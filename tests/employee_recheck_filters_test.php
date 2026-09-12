<?php
/**
 * Batch 4 item 4 -- verifies the 7 new EmployeeModel::buildListWhere() filters (position_id,
 * nationality, payment_method_id, status, employment_status, tax_calculation_method, is_ready)
 * wired into the Employee Recheck tab (EmployeeController::recheckList()/EmployeeModel::
 * recheckList()), reusing the SAME method every other station filter (role_id/department_id/etc.)
 * already goes through -- no new WHERE-building mechanism.
 * `is_ready` is the important one: it filters on the PERSISTED `employees.is_payroll_ready` column
 * (written by EmployeeModel::save() from isPayrollReady()/missingPayrollFields(), the exact same
 * call recheckList()'s own live `field_readiness`/`is_ready` per-row values are built from) rather
 * than a second readiness definition, and does it as a SQL WHERE (not a post-query PHP filter) so
 * DataTables' own recordsTotal/recordsFiltered stay correct. This file checks all 3 of those
 * properties: same definition (no drift from the row's own live `is_ready`), SQL-level filtering
 * (counts match, not just the returned page), and correct '0'-is-a-real-value handling.
 * Run with: php tests/employee_recheck_filters_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/EmployeeModel.php';

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

function resolvePaymentMethodId(PDO $pdo, string $code): int {
    $stmt = $pdo->prepare("SELECT id FROM `master_payment_methods` WHERE code = :code");
    $stmt->execute([':code' => $code]);
    return (int)$stmt->fetchColumn();
}

function makeCompany(PDO $pdo): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, 'TH', :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'Recheck Filter Test Co ' . uniqid(), ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function makePosition(PDO $pdo, int $compId, string $nameEn): int {
    $stmt = $pdo->prepare("INSERT INTO structure_positions (comp_id, position_code, position_name_th, position_name_en) VALUES (:c, :code, :name, :name)");
    $stmt->execute([':c' => $compId, ':code' => 'POS' . uniqid(), ':name' => $nameEn]);
    return (int)$pdo->lastInsertId();
}

function makeDeptBranch(PDO $pdo, int $compId): array {
    $stmt = $pdo->prepare("INSERT INTO structure_departments (comp_id, department_code, department_name_th, department_name_en) VALUES (:c, :code, 'แผนกทดสอบ', 'Test Dept')");
    $stmt->execute([':c' => $compId, ':code' => 'DEPT' . uniqid()]);
    $deptId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO structure_branches (comp_id, branch_code, branch_name_th, branch_name_en) VALUES (:c, :code, 'สาขาทดสอบ', 'Test Branch')");
    $stmt->execute([':c' => $compId, ':code' => 'BR' . uniqid()]);
    $branchId = (int)$pdo->lastInsertId();

    return [$deptId, $branchId];
}

try {
    $userId = 1;
    $model = new EmployeeModel();
    $compId = makeCompany($pdo);
    [$deptId, $branchId] = makeDeptBranch($pdo, $compId);
    $posA = makePosition($pdo, $compId, 'Filter Test Position A');
    $posB = makePosition($pdo, $compId, 'Filter Test Position B');
    $cashId = resolvePaymentMethodId($pdo, 'cash');
    $transferId = resolvePaymentMethodId($pdo, 'transfer');

    // Employee 1: fully complete (is_payroll_ready=1), position A, nationality Thai, cash, active/permanent, average.
    $empNo1 = 'RCF-' . uniqid();
    $r1 = $model->save($compId, [
        'employee_no' => $empNo1, 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'surname_th' => 'หนึ่ง',
        'name_en' => 'Test', 'surname_en' => 'One', 'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        'id_card_no' => '1234567890121',
        'department_id' => $deptId, 'position_id' => $posA, 'branch_id' => $branchId,
        'employment_date' => '2024-01-01', 'employment_status' => 'permanent', 'employment_type' => 'full_time',
        'workforce_type' => 'office', 'record_time_method' => 'manual', 'payment_method_id' => $cashId,
        'personal_email' => 'rcf1' . uniqid() . '@example.com', 'mobile_no' => '812345678',
        'salary_type' => 'monthly', 'base_salary_amount' => 25000, 'salary_effective_date' => '2024-01-01',
        'tax_calculation_method' => 'average',
    ], $userId);
    checkTrue('fixture: employee 1 (ready, position A) created' . (empty($r1['status']) ? " ({$r1['message']})" : ''), $r1['status']);

    // Employee 2: deliberately INCOMPLETE (missing personal_email/mobile_no -> is_payroll_ready=0),
    // position B, nationality American, transfer (but no bank details -> also not ready for that
    // reason), resigned/terminated, actual.
    $empNo2 = 'RCF-' . uniqid();
    $r2 = $model->save($compId, [
        'employee_no' => $empNo2, 'employee_type' => 'domestic', 'employee_status' => 'resigned',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'surname_th' => 'สอง',
        'name_en' => 'Test', 'surname_en' => 'Two', 'date_of_birth' => '1990-01-01', 'nationality' => 'American',
        'id_card_no' => '1234567890121',
        'department_id' => $deptId, 'position_id' => $posB, 'branch_id' => $branchId,
        'employment_date' => '2024-01-01', 'employment_status' => 'terminated', 'employment_type' => 'full_time',
        'workforce_type' => 'office', 'record_time_method' => 'manual', 'payment_method_id' => $transferId,
        'salary_type' => 'monthly', 'base_salary_amount' => 25000, 'salary_effective_date' => '2024-01-01',
        'tax_calculation_method' => 'actual',
    ], $userId);
    checkTrue('fixture: employee 2 (not ready, position B) created' . (empty($r2['status']) ? " ({$r2['message']})" : ''), $r2['status']);

    $row1 = $model->get($compId, $empNo1);
    $row2 = $model->get($compId, $empNo2);
    check('sanity: employee 1 is genuinely persisted as ready (is_payroll_ready)', (int)$row1['is_payroll_ready'], 1);
    check('sanity: employee 2 is genuinely persisted as NOT ready (is_payroll_ready)', (int)$row2['is_payroll_ready'], 0);

    $onlyThese = fn($items) => array_values(array_filter($items, fn($i) => in_array($i['employee_no'], [$empNo1, $empNo2], true)));

    // ==================== position_id ====================
    $res = $model->recheckList($compId, 0, 50, ['position_id' => $posA], '', 'en');
    $filtered = $onlyThese($res['data']);
    check('position_id=A: exactly employee 1 among our fixtures', count($filtered), 1);
    check('position_id=A: it is employee 1', $filtered[0]['employee_no'] ?? null, $empNo1);

    // ==================== nationality ====================
    $res = $model->recheckList($compId, 0, 50, ['nationality' => 'American'], '', 'en');
    $filtered = $onlyThese($res['data']);
    check('nationality=American: exactly employee 2 among our fixtures', count($filtered), 1);
    check('nationality=American: it is employee 2', $filtered[0]['employee_no'] ?? null, $empNo2);

    // ==================== payment_method_id ====================
    $res = $model->recheckList($compId, 0, 50, ['payment_method_id' => $cashId], '', 'en');
    $filtered = $onlyThese($res['data']);
    check('payment_method_id=cash: exactly employee 1 among our fixtures', count($filtered), 1);

    // ==================== status (employee_status) ====================
    $res = $model->recheckList($compId, 0, 50, ['status' => 'resigned'], '', 'en');
    $filtered = $onlyThese($res['data']);
    check('status=resigned: exactly employee 2 among our fixtures', count($filtered), 1);
    check('status=resigned: it is employee 2', $filtered[0]['employee_no'] ?? null, $empNo2);

    // ==================== employment_status ====================
    $res = $model->recheckList($compId, 0, 50, ['employment_status' => 'permanent'], '', 'en');
    $filtered = $onlyThese($res['data']);
    check('employment_status=permanent: exactly employee 1 among our fixtures', count($filtered), 1);

    // ==================== tax_calculation_method ====================
    $res = $model->recheckList($compId, 0, 50, ['tax_calculation_method' => 'actual'], '', 'en');
    $filtered = $onlyThese($res['data']);
    check('tax_calculation_method=actual: exactly employee 2 among our fixtures', count($filtered), 1);

    // ==================== is_ready -- the important one (SQL-level, no drift, '0' is real) ====================
    $resReady = $model->recheckList($compId, 0, 50, ['is_ready' => '1'], '', 'en');
    $filteredReady = $onlyThese($resReady['data']);
    check('is_ready=1: exactly employee 1 among our fixtures', count($filteredReady), 1);
    check('is_ready=1: it is employee 1', $filteredReady[0]['employee_no'] ?? null, $empNo1);
    checkTrue("is_ready=1: the row's OWN live is_ready field agrees (no drift from the persisted column)", (bool)($filteredReady[0]['is_ready'] ?? false));

    $resNotReady = $model->recheckList($compId, 0, 50, ['is_ready' => '0'], '', 'en');
    $filteredNotReady = $onlyThese($resNotReady['data']);
    check("is_ready=0 (a real value, not 'no filter'): exactly employee 2 among our fixtures", count($filteredNotReady), 1);
    check('is_ready=0: it is employee 2', $filteredNotReady[0]['employee_no'] ?? null, $empNo2);
    check("is_ready=0: the row's OWN live is_ready field agrees (no drift)", $filteredNotReady[0]['is_ready'] ?? null, false);

    // No filter at all -> both still present (nothing accidentally narrowed by default).
    $resAll = $model->recheckList($compId, 0, 50, [], '', 'en');
    check('No is_ready filter: both fixtures still present', count($onlyThese($resAll['data'])), 2);

    // recordsFiltered/recordsTotal reflect the SQL-level narrowing (server-side DataTable paging
    // correctness) -- with only our 2 fixtures in this fresh throwaway company, filtering to
    // is_ready=1 must show recordsFiltered=1, not 2 (which a post-query PHP filter would have kept
    // reporting, since PHP filtering never touches the COUNT queries at all).
    check('is_ready=1: recordsFiltered reflects the SQL-level narrowing (not just the returned page)', $resReady['filtered'], 1);
    check('is_ready=1: recordsTotal ALSO reflects it (is_ready is not treated as a search term)', $resReady['total'], 1);
    check('is_ready=0: recordsFiltered reflects the SQL-level narrowing', $resNotReady['filtered'], 1);

    echo "\n--------------------------------------------------\n";
    echo "Passed: {$passes}, Failed: {$failures}\n";
    if ($failures > 0) {
        echo "SOME TESTS FAILED\n";
    } else {
        echo "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
    }
} finally {
    $pdo->rollBack();
}

exit($failures > 0 ? 1 : 0);
