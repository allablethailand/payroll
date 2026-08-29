<?php
/**
 * Lightweight verification script for StatutoryCalculationEngine.
 * Not PHPUnit — the project has no test framework installed yet. Runs against
 * the real dev DB inside a transaction that is always rolled back, so it never
 * leaves any data behind. Run with: php tests/statutory_engine_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/TaxStatutoryModel.php';
require_once __DIR__ . '/../app/models/CompanyStatutorySettingModel.php';
require_once __DIR__ . '/../app/services/StatutoryCalculationEngine.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    $ok = (is_float($expected) || is_float($actual)) ? abs((float)$actual - (float)$expected) < 0.005 : $actual === $expected;
    if ($ok) {
        $passes++;
        echo "  PASS  {$label} => " . var_export($actual, true) . "\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}

try {
    $compId = 1; // Allable Co.,Ltd. (TH)
    $taxModel = new TaxStatutoryModel();
    $csModel = new CompanyStatutorySettingModel($pdo);
    $engine = new StatutoryCalculationEngine($pdo);

    // Lookup real item ids by code (do not hardcode ids, in case seed order changes)
    $itemIdByCode = [];
    foreach ($taxModel->list('TH') as $row) {
        $itemIdByCode[$row['code']] = (int)$row['id'];
    }
    $ssoId = $itemIdByCode['TH_SSO'];
    $pitId = $itemIdByCode['TH_PIT'];
    $pvdId = $itemIdByCode['TH_PVD'];

    // 2026-08-29: TH_SSO/TH_PVD's own calc_base now points at sso_eligible_earnings/
    // pf_eligible_earnings instead of basic_salary (see migrations/
    // 2026-08-29_sso_pf_eligible_earnings_base.sql + PayrollRunModel::recalculate()'s own new
    // $calcSsoItemCodes/$calcPfItemCodes aggregation) -- every salaryContext below that exercises
    // TH_SSO/TH_PVD now sets the matching new key too. These are direct engine-level unit tests
    // with no earning-line concept at all, so "eligible earnings" is simply set equal to
    // basic_salary in every scenario here (no additional calc_sso/calc_pf-flagged allowance to add
    // on top) -- that aggregation itself is covered separately, at the PayrollRunModel level, in
    // tests/payroll_run_test.php's own "SSO/PF base now includes calc_sso/calc_pf-flagged earning
    // items" section.
    echo "=== Scenario 1: TH_SSO flat_rate, salary above max_base (capped) ===\n";
    $result = $engine->calculateItem($compId, 'TH_SSO', ['basic_salary' => 20000, 'sso_eligible_earnings' => 20000], '2026-07-01');
    check('employee_amount (capped at 15000 base * 5%, then capped at 750)', $result['employee_amount'], 750.0);
    check('employer_amount', $result['employer_amount'], 750.0);
    check('base_amount clamped to max_base', $result['base_amount'], 15000.0);

    echo "=== Scenario 2: TH_SSO flat_rate, salary below min_base (floored) ===\n";
    $result = $engine->calculateItem($compId, 'TH_SSO', ['basic_salary' => 1000, 'sso_eligible_earnings' => 1000], '2026-07-01');
    check('employee_amount (floored to 1650 base * 5%)', $result['employee_amount'], 82.5);
    check('employer_amount', $result['employer_amount'], 82.5);

    echo "=== Scenario 3: TH_PVD with company rate override ===\n";
    $saveRes = $csModel->save($compId, [
        'statutory_item_id' => $pvdId,
        'is_active' => true,
        'employee_rate_override' => 5,
        'employer_rate_override' => 4,
    ], 1);
    check('override save status', $saveRes['status'], true);
    $result = $engine->calculateItem($compId, 'TH_PVD', ['basic_salary' => 30000, 'pf_eligible_earnings' => 30000], '2026-07-01');
    check('employee_amount uses override 5% not master 3%', $result['employee_amount'], 1500.0);
    check('employer_amount uses override 4% not master 3%', $result['employer_amount'], 1200.0);
    check('rate_source flagged as company_override', $result['rate_source'], 'company_override');

    echo "=== Scenario 4: TH_PVD disabled by company ===\n";
    $csModel->save($compId, ['statutory_item_id' => $pvdId, 'is_active' => false], 1);
    $result = $engine->calculateItem($compId, 'TH_PVD', ['basic_salary' => 30000, 'pf_eligible_earnings' => 30000], '2026-07-01');
    check('employee_amount is 0 when disabled', $result['employee_amount'], 0.0);
    check('note is disabled', $result['note'], 'disabled');

    echo "=== Scenario 5: TH_PIT progressive bracket, taxable_income=400000 ===\n";
    $result = $engine->calculateItem($compId, 'TH_PIT', ['taxable_income' => 400000], '2026-07-01');
    check('PIT tax on 400,000 THB (published table value)', $result['employee_amount'], 17500.0);
    check('PIT has no employer share', $result['employer_amount'], 0.0);

    echo "=== Scenario 6: formula calc_method (US_FICA_MEDICARE, threshold extra rate) ===\n";
    $medicareId = null;
    foreach ($taxModel->list('US') as $row) {
        if ($row['code'] === 'US_FICA_MEDICARE') $medicareId = (int)$row['id'];
    }
    // Inserted directly (not via TaxStatutoryModel::rateHistorySave) because that method manages its
    // own transaction internally, which would conflict with the outer transaction wrapping this script.
    $insertRate = $pdo->prepare("INSERT INTO `statutory_item_rate_history`
        (statutory_item_id, effective_date, formula_config, created_by) VALUES (:item_id, '2026-01-01', :config, 1)");
    $insertRate->execute([
        ':item_id' => $medicareId,
        ':config' => json_encode([
            'employee' => ['base_rate' => 1.45, 'extra_rate' => 0.9, 'extra_threshold' => 200000],
            'employer' => ['base_rate' => 1.45],
        ]),
    ]);
    check('formula rate insert row count', $insertRate->rowCount(), 1);
    // US company doesn't exist in seed data; call the engine's line calc directly via a synthetic item row
    // to keep this scenario isolated from needing a US company fixture.
    $medicareItem = $taxModel->get($medicareId);
    $reflection = new ReflectionClass($engine);
    $method = $reflection->getMethod('calculateLine');
    $method->setAccessible(true);
    $syntheticRow = array_merge($medicareItem, [
        'statutory_item_id' => $medicareId,
        'effective_status' => 'active',
        'employee_rate_override' => null, 'employer_rate_override' => null,
        'employee_amount_override' => null, 'employer_amount_override' => null,
    ]);
    $line = $method->invoke($engine, $syntheticRow, ['gross_salary' => 250000], '2026-07-01');
    check('medicare employee amount (base + extra threshold portion)', $line['employee_amount'], 4075.0);
    check('medicare employer amount (base only, no extra)', $line['employer_amount'], 3625.0);

    echo "=== Scenario 7: no rate configured for the calculation date ===\n";
    $result = $engine->calculateItem($compId, 'TH_SSO', ['basic_salary' => 20000, 'sso_eligible_earnings' => 20000], '2020-01-01');
    check('employee_amount is 0 with no applicable rate history', $result['employee_amount'], 0.0);
    check('note is no_rate_configured', $result['note'], 'no_rate_configured');

    echo "=== Scenario 8: full calculate() across all active TH items ===\n";
    $csModel->save($compId, ['statutory_item_id' => $pvdId, 'is_active' => true, 'employee_rate_override' => '', 'employer_rate_override' => ''], 1);
    $full = $engine->calculate($compId, ['basic_salary' => 30000, 'taxable_income' => 400000, 'sso_eligible_earnings' => 30000, 'pf_eligible_earnings' => 30000], '2026-07-01');
    check('calculate() returns 3 line items for TH', count($full['items']), 3);
    check('total_employee_deduction sums all active items', $full['total_employee_deduction'], 750.0 + 900.0 + 17500.0);
    // SSO: 30000 clamped to max_base 15000 * 5% = 750 (also under the 750 cap), PVD: 30000*3% master=900, PIT=17500

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
