<?php
/**
 * Lightweight verification script for Manual Entry / Employee Salary tab review Phase 1B (explicit
 * request: "เมื่อเลือก PED Type ในฟอร์มเงินกู้/ผ่อนชำระ ให้ auto-fill ดอกเบี้ย/เงื่อนไข default จาก catalog") --
 * the 5 new default_interest_type/default_interest_rate/default_fee_percent/default_fee_base
 * columns on `payroll_earning_deduction_types` (PayrollEarningDeductionTypeModel::save()'s own
 * deduction-only validation/persistence) and their exposure through
 * EmployeeEarningDeductionModel::activeOptions() (the same catalog picker #eed_ped_type_id's own
 * select2:select handler in detail.js reads to suggest starting values -- that handler's own toggle-
 * button-state logic is pure client-side JS with no PHP coverage, verified instead by reading the
 * code, same standing limitation as every other canvas/interaction feature in this project).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back. Uses its own freshly-created company (same makeCompany()
 * pattern as tests/manual_entry_autofill_test.php) -- zero dev-DB contamination risk.
 *
 * Run with: php tests/ped_type_default_interest_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';
require_once __DIR__ . '/../app/models/EmployeeEarningDeductionModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    if ($actual === $expected) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }

function makeCompany(PDO $pdo, string $countryCode): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

try {
    $userId = 1;
    $compId = makeCompany($pdo, 'TH');
    $pedModel = new PayrollEarningDeductionTypeModel();
    $eedModel = new EmployeeEarningDeductionModel();

    echo "=== PayrollEarningDeductionTypeModel::save() -- default_interest_* is deduction-only ===\n";
    $earningCode = 'DI' . substr(uniqid(), -6);
    $earningResult = $pedModel->save($compId, [
        'item_code' => $earningCode, 'item_name_th' => 'ทดสอบ', 'item_name_en' => 'Test Earning',
        'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 100, 'tax_treatment' => 'taxable',
        // Submitted anyway, to confirm the deduction-only gate silently ignores it for an earning row.
        'default_interest_type' => 'fixed', 'default_interest_rate' => 5,
    ], $userId);
    checkTrue('create earning item' . (empty($earningResult['status']) ? " ({$earningResult['message']})" : ''), $earningResult['status']);
    if (!empty($earningResult['id'])) {
        $row = $pdo->query("SELECT default_interest_type, default_interest_rate FROM payroll_earning_deduction_types WHERE id = " . (int)$earningResult['id'])->fetch(PDO::FETCH_ASSOC);
        check('earning item: default_interest_type stays NULL despite being submitted', $row['default_interest_type'], null);
        check('earning item: default_interest_rate stays NULL despite being submitted', $row['default_interest_rate'], null);
    }

    echo "\n=== PayrollEarningDeductionTypeModel::save() -- deduction item, 'fixed' interest default ===\n";
    $fixedCode = 'DI' . substr(uniqid(), -6);
    $fixedResult = $pedModel->save($compId, [
        'item_code' => $fixedCode, 'item_name_th' => 'ทดสอบ', 'item_name_en' => 'Test Loan Fixed',
        'item_type' => 'deduction', 'calculation_method' => 'manual_entry', 'tax_deduction_impact' => 'after_tax',
        'default_interest_type' => 'fixed', 'default_interest_rate' => 3.5,
    ], $userId);
    checkTrue('create deduction item with fixed interest default' . (empty($fixedResult['status']) ? " ({$fixedResult['message']})" : ''), $fixedResult['status']);
    $fixedId = (int)($fixedResult['id'] ?? 0);
    if ($fixedId > 0) {
        $row = $pdo->query("SELECT default_interest_type, default_interest_rate, default_fee_percent, default_fee_base FROM payroll_earning_deduction_types WHERE id = {$fixedId}")->fetch(PDO::FETCH_ASSOC);
        check('default_interest_type persisted', $row['default_interest_type'], 'fixed');
        check('default_interest_rate persisted', (float)$row['default_interest_rate'], 3.5);
        check('default_fee_percent stays NULL for a fixed-interest item', $row['default_fee_percent'], null);
        check('default_fee_base stays NULL for a fixed-interest item', $row['default_fee_base'], null);
    }

    echo "\n=== PayrollEarningDeductionTypeModel::save() -- deduction item, 'fee' default ===\n";
    $feeCode = 'DI' . substr(uniqid(), -6);
    $feeResult = $pedModel->save($compId, [
        'item_code' => $feeCode, 'item_name_th' => 'ทดสอบ', 'item_name_en' => 'Test Loan Fee',
        'item_type' => 'deduction', 'calculation_method' => 'manual_entry', 'tax_deduction_impact' => 'after_tax',
        'default_interest_type' => 'fee', 'default_fee_percent' => 2, 'default_fee_base' => 'principal_amount',
        // Submitted alongside 'fee' -- should be ignored since interest_rate only applies to fixed/reducing_balance.
        'default_interest_rate' => 99,
    ], $userId);
    checkTrue('create deduction item with fee default' . (empty($feeResult['status']) ? " ({$feeResult['message']})" : ''), $feeResult['status']);
    $feeId = (int)($feeResult['id'] ?? 0);
    if ($feeId > 0) {
        $row = $pdo->query("SELECT default_interest_type, default_interest_rate, default_fee_percent, default_fee_base FROM payroll_earning_deduction_types WHERE id = {$feeId}")->fetch(PDO::FETCH_ASSOC);
        check('default_interest_type persisted as fee', $row['default_interest_type'], 'fee');
        check('default_interest_rate stays NULL for a fee item (even though submitted)', $row['default_interest_rate'], null);
        check('default_fee_percent persisted', (float)$row['default_fee_percent'], 2.0);
        check('default_fee_base persisted', $row['default_fee_base'], 'principal_amount');
    }

    echo "\n=== PayrollEarningDeductionTypeModel::save() -- validation ===\n";
    $badType = $pedModel->save($compId, [
        'item_code' => 'DI' . substr(uniqid(), -6), 'item_name_th' => 'x', 'item_name_en' => 'x',
        'item_type' => 'deduction', 'calculation_method' => 'manual_entry', 'tax_deduction_impact' => 'after_tax',
        'default_interest_type' => 'bogus',
    ], $userId);
    checkFalse('unknown default_interest_type rejected', $badType['status']);

    $badFeeBase = $pedModel->save($compId, [
        'item_code' => 'DI' . substr(uniqid(), -6), 'item_name_th' => 'x', 'item_name_en' => 'x',
        'item_type' => 'deduction', 'calculation_method' => 'manual_entry', 'tax_deduction_impact' => 'after_tax',
        'default_interest_type' => 'fee', 'default_fee_base' => 'bogus',
    ], $userId);
    checkFalse('unknown default_fee_base rejected', $badFeeBase['status']);

    echo "\n=== EmployeeEarningDeductionModel::activeOptions() exposes the new columns ===\n";
    $options = $eedModel->activeOptions($compId, '', 1, 50, 'deduction');
    $itemsById = [];
    foreach (($options['items'] ?? []) as $it) { $itemsById[(int)$it['id']] = $it; }
    checkTrue('the fee-default item is present in activeOptions()', isset($itemsById[$feeId]));
    if (isset($itemsById[$feeId])) {
        check('activeOptions() exposes default_interest_type', $itemsById[$feeId]['default_interest_type'], 'fee');
        check('activeOptions() exposes default_fee_percent', (float)$itemsById[$feeId]['default_fee_percent'], 2.0);
        check('activeOptions() exposes default_fee_base', $itemsById[$feeId]['default_fee_base'], 'principal_amount');
    }

    echo "\n=== SUMMARY: {$passes} passed, {$failures} failed ===\n";
} finally {
    $pdo->rollBack();
}

exit($failures > 0 ? 1 : 0);
