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
    // 2026-08-29, explicit request: "ประกันสังคม อยากให้เห็นสูตรคำนวณด้วยครับ" -- structured 'formula'
    // trace attached by computeFlatRate(), for the Detail page's popover.
    check('flat_rate result carries a formula field', isset($result['formula']), true);
    check('formula raw_base is the uncapped 20000', $result['formula']['raw_base'] ?? null, 20000.0);
    check('formula effective_base reflects the max_base clamp (15000)', $result['formula']['effective_base'] ?? null, 15000.0);
    check('formula employee_rate is 5', $result['formula']['employee_rate'] ?? null, 5.0);
    check('formula employee_raw_amount (before the contribution cap) = 15000*5% = 750', $result['formula']['employee_raw_amount'] ?? null, 750.0);
    check('formula employee_capped is false here (raw amount already equals the cap, not truncated by it)', $result['formula']['employee_capped'], false);

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

    // 2026-08-30, calc-preview rollout: TaxStatutoryModel::previewRateVersion() runs a DRAFT rate
    // version's fields through the SAME StatutoryCalculationEngine::computeXxx() static functions
    // used above, against a sample base amount -- expected numbers below are lifted directly from
    // Scenario 1/5/6's own real calculateItem()/calculateLine() results (same config, same base),
    // so a pass here proves the preview can never silently drift from real payroll calculation.
    echo "=== previewRateVersion(): calc-preview endpoint for Tax & Statutory settings ===\n";

    $beforeSsoRateCount = (int)$pdo->query("SELECT COUNT(*) FROM `statutory_item_rate_history` WHERE statutory_item_id = {$ssoId}")->fetchColumn();
    $preview = $taxModel->previewRateVersion([
        'statutory_item_id' => $ssoId,
        'employee_rate' => 5, 'employer_rate' => 5,
        'max_base_amount' => 15000, 'max_employee_contribution' => 750, 'max_employer_contribution' => 750,
    ], 20000.0);
    check('flat_rate preview status', $preview['status'], true);
    check('flat_rate preview employee_amount matches Scenario 1 real calc (capped at 750)', $preview['employee_amount'], 750.0);
    check('flat_rate preview employer_amount', $preview['employer_amount'], 750.0);
    check('flat_rate preview formula.effective_base reflects the max_base clamp', $preview['formula']['effective_base'] ?? null, 15000.0);
    check('flat_rate preview formula.employee_capped is false (raw already equals the cap)', $preview['formula']['employee_capped'], false);

    $preview = $taxModel->previewRateVersion(['statutory_item_id' => $ssoId, 'employer_rate' => 5], 20000.0);
    check('flat_rate preview rejects a missing employee_rate', $preview['status'], false);

    check('previewRateVersion() never writes a rate history row (TH_SSO row count unchanged)',
        (int)$pdo->query("SELECT COUNT(*) FROM `statutory_item_rate_history` WHERE statutory_item_id = {$ssoId}")->fetchColumn(), $beforeSsoRateCount);

    // progressive_bracket (TH_PIT) -- reuse the real, already-configured brackets so the expected
    // result matches Scenario 5's own published-table value (17,500 THB @ taxable_income=400,000).
    $pitBracketsStmt = $pdo->prepare("SELECT min_amount, max_amount, rate FROM `statutory_item_brackets`
        WHERE statutory_item_rate_history_id = (
            SELECT id FROM `statutory_item_rate_history` WHERE statutory_item_id = :item_id AND deleted_at IS NULL
            ORDER BY effective_date DESC LIMIT 1
        ) ORDER BY bracket_order ASC");
    $pitBracketsStmt->execute([':item_id' => $pitId]);
    $pitBrackets = $pitBracketsStmt->fetchAll(PDO::FETCH_ASSOC);
    check('fixture sanity: TH_PIT has real bracket rows configured', count($pitBrackets) > 0, true);

    $beforeBracketCount = (int)$pdo->query("SELECT COUNT(*) FROM `statutory_item_brackets` WHERE statutory_item_rate_history_id IN (SELECT id FROM `statutory_item_rate_history` WHERE statutory_item_id = {$pitId})")->fetchColumn();
    $preview = $taxModel->previewRateVersion(['statutory_item_id' => $pitId, 'brackets' => $pitBrackets], 400000.0);
    check('progressive_bracket preview status', $preview['status'], true);
    check('progressive_bracket preview matches Scenario 5 real calc (17,500)', $preview['employee_amount'], 17500.0);
    check('progressive_bracket preview employer_amount is 0 (PIT has no employer share)', $preview['employer_amount'], 0.0);
    check('progressive_bracket preview formula carries a steps array', is_array($preview['formula']['steps'] ?? null), true);

    $preview = $taxModel->previewRateVersion(['statutory_item_id' => $pitId, 'brackets' => []], 400000.0);
    check('progressive_bracket preview rejects an empty bracket list', $preview['status'], false);

    check('previewRateVersion() never writes a bracket row (TH_PIT bracket count unchanged)',
        (int)$pdo->query("SELECT COUNT(*) FROM `statutory_item_brackets` WHERE statutory_item_rate_history_id IN (SELECT id FROM `statutory_item_rate_history` WHERE statutory_item_id = {$pitId})")->fetchColumn(), $beforeBracketCount);

    // fixed_amount -- no real TH fixed_amount item exists in seed data, so a small standalone
    // fixture item is inserted directly (same approach Scenario 6 uses for the formula item).
    $insertFixed = $pdo->prepare("INSERT INTO `statutory_items`
        (country_code, code, name_th, name_en, category, calc_method, calc_base, is_employee_applicable, is_employer_applicable, sort_order, status, rounding_mode, decimal_places, created_by)
        VALUES ('TH', 'TEST_PREVIEW_FIXED', 'Test Fixed', 'Test Fixed', 'other', 'fixed_amount', 'basic_salary', 1, 1, 99, 'active', 'round', 2, 1)");
    $insertFixed->execute();
    $fixedItemId = (int)$pdo->lastInsertId();
    $preview = $taxModel->previewRateVersion(['statutory_item_id' => $fixedItemId, 'employee_amount' => 100, 'employer_amount' => 50], 30000.0);
    check('fixed_amount preview status', $preview['status'], true);
    check('fixed_amount preview employee_amount', $preview['employee_amount'], 100.0);
    check('fixed_amount preview employer_amount', $preview['employer_amount'], 50.0);
    check('fixed_amount preview formula.type', $preview['formula']['type'] ?? null, 'fixed_amount');

    $preview = $taxModel->previewRateVersion(['statutory_item_id' => $fixedItemId, 'employer_amount' => 50], 30000.0);
    check('fixed_amount preview rejects a missing employee_amount', $preview['status'], false);

    check('previewRateVersion() never writes a rate history row for the fixed_amount fixture item',
        (int)$pdo->query("SELECT COUNT(*) FROM `statutory_item_rate_history` WHERE statutory_item_id = {$fixedItemId}")->fetchColumn(), 0);

    // formula -- reuse the exact US_FICA_MEDICARE config/base already verified in Scenario 6
    // (employee 4075 = 250000*1.45% + (250000-200000)*0.9%, employer 3625 = 250000*1.45% only)
    $preview = $taxModel->previewRateVersion([
        'statutory_item_id' => $medicareId,
        'formula_config' => json_encode(['employee' => ['base_rate' => 1.45, 'extra_rate' => 0.9, 'extra_threshold' => 200000], 'employer' => ['base_rate' => 1.45]]),
    ], 250000.0);
    check('formula preview status', $preview['status'], true);
    check('formula preview employee_amount matches Scenario 6 real calc', $preview['employee_amount'], 4075.0);
    check('formula preview employer_amount matches Scenario 6 real calc', $preview['employer_amount'], 3625.0);

    $preview = $taxModel->previewRateVersion(['statutory_item_id' => $medicareId, 'formula_config' => 'not json'], 250000.0);
    check('formula preview rejects invalid JSON', $preview['status'], false);

    $preview = $taxModel->previewRateVersion(['statutory_item_id' => 999999], 30000.0);
    check('preview rejects an unknown statutory_item_id', $preview['status'], false);

    $preview = $taxModel->previewRateVersion(['statutory_item_id' => $ssoId, 'employee_rate' => 5, 'employer_rate' => 5], -100.0);
    check('preview rejects a negative sample_base_amount', $preview['status'], false);

    // 2026-08-29, explicit request: "ให้มีการกำหนดเพิ่มได้ว่าปัดเศษ หรือไม่ปัด ถ้าปัดปัดแบบไหน และทศนิยม
    // ได้กี่ตำแหน่ง แล้วตอนคำนวณให้นำไปใช้ด้วย" -- per-item rounding_mode/decimal_places on
    // statutory_items, applied by StatutoryCalculationEngine::applyRounding(). TH_SSO's own 5% rate
    // and 1650-15000 min/max base clamp stay unchanged throughout; only rounding_mode/decimal_places
    // are varied per scenario via TaxStatutoryModel::save() against the real master item row.
    echo "=== Scenario 9: per-item rounding_mode/decimal_places config, applied at calc time ===\n";
    $ssoItem = $taxModel->get($ssoId);
    check('fixture sanity: TH_SSO default rounding_mode is round/2dp (pre-existing rows, byte-identical to old hardcoded behavior)', [$ssoItem['rounding_mode'], (int)$ssoItem['decimal_places']], ['round', 2]);

    // base=1822 * 5% = 91.1 exactly -- 'up' must ceil past the nearest integer, 'down' must floor short of it.
    $saveRes = $taxModel->save(array_merge($ssoItem, ['id' => $ssoId, 'rounding_mode' => 'up', 'decimal_places' => 0]), 1);
    check('save rounding_mode=up, decimal_places=0', $saveRes['status'], true);
    $result = $engine->calculateItem($compId, 'TH_SSO', ['basic_salary' => 1822, 'sso_eligible_earnings' => 1822], '2026-07-01');
    check('rounding_mode=up: 91.1 ceils to 92', $result['employee_amount'], 92.0);

    $saveRes = $taxModel->save(array_merge($ssoItem, ['id' => $ssoId, 'rounding_mode' => 'down', 'decimal_places' => 0]), 1);
    check('save rounding_mode=down, decimal_places=0', $saveRes['status'], true);
    $result = $engine->calculateItem($compId, 'TH_SSO', ['basic_salary' => 1822, 'sso_eligible_earnings' => 1822], '2026-07-01');
    check('rounding_mode=down: 91.1 floors to 91', $result['employee_amount'], 91.0);

    // base=1837.2 * 5% = 91.86 -- the dropped 2nd-decimal digit (6) is >=5, so 'round' rounds UP to
    // 91.9 while 'none' (truncate, no adjustment) must still land on 91.8 -- the one pair of modes
    // that genuinely differ for a positive value (up/down never differ from round/none by more than
    // the rounding boundary itself).
    $saveRes = $taxModel->save(array_merge($ssoItem, ['id' => $ssoId, 'rounding_mode' => 'round', 'decimal_places' => 1]), 1);
    check('save rounding_mode=round, decimal_places=1', $saveRes['status'], true);
    $result = $engine->calculateItem($compId, 'TH_SSO', ['basic_salary' => 1837.2, 'sso_eligible_earnings' => 1837.2], '2026-07-01');
    check('rounding_mode=round, 1dp: 91.86 rounds to 91.9', $result['employee_amount'], 91.9);

    $saveRes = $taxModel->save(array_merge($ssoItem, ['id' => $ssoId, 'rounding_mode' => 'none', 'decimal_places' => 1]), 1);
    check('save rounding_mode=none, decimal_places=1', $saveRes['status'], true);
    $result = $engine->calculateItem($compId, 'TH_SSO', ['basic_salary' => 1837.2, 'sso_eligible_earnings' => 1837.2], '2026-07-01');
    check('rounding_mode=none, 1dp: 91.86 truncates to 91.8 (no round-up)', $result['employee_amount'], 91.8);

    $saveRes = $taxModel->save(array_merge($ssoItem, ['id' => $ssoId, 'rounding_mode' => 'zzz_invalid', 'decimal_places' => 2]), 1);
    check('save rejects an invalid rounding_mode', $saveRes['status'], false);

    $saveRes = $taxModel->save(array_merge($ssoItem, ['id' => $ssoId, 'rounding_mode' => 'round', 'decimal_places' => 9]), 1);
    check('save rejects decimal_places out of the 0-4 range', $saveRes['status'], false);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
