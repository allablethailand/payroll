<?php
/**
 * Verifies EmployeeEarningDeductionModel's custom-item support (2026-08-19, explicit request: "ในส่วน
 * ของ Item ให้สามารถใส่เองได้ โดยบอกว่าเป็นรายได้หรือรายหัก") -- either a catalog ped_type_id OR a
 * free-text custom_item_name+custom_item_type, list()/get() resolving both shapes correctly via
 * LEFT JOIN + COALESCE. The actual payroll-calculation integration (PayrollRunModel::recalculate()'s
 * matching LEFT JOIN fix) is covered separately in tests/payroll_run_test.php.
 * Run with: php tests/employee_earning_deduction_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/EmployeeEarningDeductionModel.php';
require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';

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

try {
    $userId = 1;
    $compId = 1;
    $eedModel = new EmployeeEarningDeductionModel();
    $pedTypeModel = new PayrollEarningDeductionTypeModel();

    $stmt = $pdo->prepare("SELECT id FROM employees WHERE comp_id = :c AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':c' => $compId]);
    $employeeId = (int)$stmt->fetchColumn();
    if ($employeeId <= 0) {
        echo "No employee available for comp_id=1 -- cannot run this test.\n";
        exit(1);
    }

    $pedRes = $pedTypeModel->save($compId, [
        'item_code' => 'TESTEED' . rand(1000, 9999),
        'item_name_th' => 'ค่าทดสอบ', 'item_name_en' => 'Test Item',
        'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 500,
        'tax_treatment' => 'taxable', 'status' => 'active',
    ], $userId);
    checkTrue('fixture: catalog PED type created', $pedRes['status']);
    $pedTypeId = $pedRes['id'];

    // ---------- Neither ped_type_id nor custom fields given -> rejected ----------
    $rNeither = $eedModel->save($employeeId, $compId, [
        'total_installments' => 1, 'amount_mode' => 'even_split', 'total_amount' => 100,
        'effective_date' => '2026-01-01',
    ], $userId);
    checkFalse('save() rejects when neither ped_type_id nor custom_item_name/type given', $rNeither['status']);

    // ---------- Catalog item (existing behavior, unaffected) ----------
    $rCatalog = $eedModel->save($employeeId, $compId, [
        'ped_type_id' => $pedTypeId, 'total_installments' => 1, 'amount_mode' => 'even_split',
        'total_amount' => 500, 'effective_date' => '2026-01-01',
    ], $userId);
    checkTrue('save() succeeds for a catalog item' . (empty($rCatalog['status']) ? " ({$rCatalog['message']})" : ''), $rCatalog['status']);
    $catalogId = $rCatalog['id'] ?? null;

    // ---------- Custom item, invalid type -> rejected ----------
    $rBadType = $eedModel->save($employeeId, $compId, [
        'custom_item_name' => 'ทดสอบ', 'custom_item_type' => 'bonus',
        'total_installments' => 1, 'amount_mode' => 'even_split', 'total_amount' => 100,
        'effective_date' => '2026-01-01',
    ], $userId);
    checkFalse('save() rejects an invalid custom_item_type', $rBadType['status']);

    // ---------- Custom item, valid ----------
    $rCustom = $eedModel->save($employeeId, $compId, [
        'custom_item_name' => 'คืนเงินประกันชุดยูนิฟอร์ม', 'custom_item_type' => 'earning',
        'total_installments' => 1, 'amount_mode' => 'even_split', 'total_amount' => 350,
        'effective_date' => '2026-01-01',
    ], $userId);
    checkTrue('save() succeeds for a custom item' . (empty($rCustom['status']) ? " ({$rCustom['message']})" : ''), $rCustom['status']);
    $customId = $rCustom['id'] ?? null;

    // ---------- list() resolves both shapes correctly, LEFT JOIN doesn't drop the custom row ----------
    if ($catalogId && $customId) {
        $list = $eedModel->list($employeeId, $compId);
        $listedIds = array_map('intval', array_column($list, 'id'));
        checkTrue('list() includes the catalog item', in_array((int)$catalogId, $listedIds, true));
        checkTrue('list() includes the custom item (not dropped by the LEFT JOIN)', in_array((int)$customId, $listedIds, true));

        $customRow = null;
        foreach ($list as $row) {
            if ((int)$row['id'] === $customId) $customRow = $row;
        }
        checkTrue('custom row found in list()', $customRow !== null);
        if ($customRow) {
            check('custom row item_name_th resolves via COALESCE to custom_item_name', $customRow['item_name_th'], 'คืนเงินประกันชุดยูนิฟอร์ม');
            check('custom row item_type resolves via COALESCE to custom_item_type', $customRow['item_type'], 'earning');
            check('custom row item_code is null (no catalog code to show)', $customRow['item_code'], null);
        }

        // ---------- list() item_type filter also matches on custom_item_type ----------
        $earningOnly = $eedModel->list($employeeId, $compId, 'earning');
        $earningIds = array_map('intval', array_column($earningOnly, 'id'));
        checkTrue('item_type=earning filter includes the custom earning item', in_array((int)$customId, $earningIds, true));
        $deductionOnly = $eedModel->list($employeeId, $compId, 'deduction');
        $deductionIds = array_map('intval', array_column($deductionOnly, 'id'));
        checkFalse('item_type=deduction filter excludes the custom earning item', in_array((int)$customId, $deductionIds, true));

        // ---------- get() resolves the custom row the same way ----------
        $got = $eedModel->get($customId, $compId);
        checkTrue('get() finds the custom row', $got !== null);
        if ($got) {
            check('get() item_name_th resolves via COALESCE', $got['item_name_th'], 'คืนเงินประกันชุดยูนิฟอร์ม');
            check('get() item_type resolves via COALESCE', $got['item_type'], 'earning');
        }
    }

    // ---------- activeOptions() item_type filter (select2 catalog dropdown, Add Earning/Add
    // Deduction pre-filtering) ----------
    $optionsEarning = $eedModel->activeOptions($compId, '', 1, 50, 'earning');
    $foundEarning = false;
    foreach ($optionsEarning['items'] as $item) {
        if ((int)$item['id'] === $pedTypeId) $foundEarning = true;
    }
    checkTrue('activeOptions(item_type=earning) includes the earning-type catalog item', $foundEarning);
    $optionsDeduction = $eedModel->activeOptions($compId, '', 1, 50, 'deduction');
    $foundInDeduction = false;
    foreach ($optionsDeduction['items'] as $item) {
        if ((int)$item['id'] === $pedTypeId) $foundInDeduction = true;
    }
    checkFalse('activeOptions(item_type=deduction) excludes the earning-type catalog item', $foundInDeduction);

} finally {
    $pdo->rollBack();
}

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
