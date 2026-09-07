<?php
/**
 * Backlog Phase 10, T056 -- "Probation setting gains Clone + Assign, using T055's template."
 * Verifies both layers: PayrollPolicyModel's new probation_policy_sets CRUD (model-level, mirrors
 * OtRateSetModel's own is_default/duplicate/delete conventions), AND end-to-end through a REAL
 * PayrollRunModel::recalculate() call that 2 employees in the SAME company, one department-scoped
 * to a non-default Set and one unscoped (falls to Default), get DIFFERENT probation_base_salary_ratio
 * treatment in the actual persisted `payroll_run_details.base_salary_amount` -- not just verified via
 * PayrollPolicyModel::probationSettings() in isolation. Also proves the backward-compatibility case:
 * a company that never created ANY probation_policy_sets row computes byte-identical results to how
 * probation worked before T056 (no ratio applied, same as the old "nothing configured" default).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why this project uses plain check()/
 * checkTrue() scripts instead. Runs against the real dev DB inside a transaction that is always
 * rolled back. Uses fresh throwaway companies, never comp_id=1 (see
 * feedback_dev_db_shared_state_test_fragility memory).
 * Run with: php tests/probation_policy_set_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/PayrollPolicyModel.php';
require_once __DIR__ . '/../app/models/EntityAssignmentModel.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    $ok = (is_float($expected) || is_float($actual)) ? abs((float)$actual - (float)$expected) < 0.005 : $actual === $expected;
    if ($ok) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }

function makeCompany(PDO $pdo, string $countryCode = 'TH'): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory', 'active', :comp_code)");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid(), ':comp_code' => 'PPS_' . uniqid()]);
    return (int)$pdo->lastInsertId();
}
function makeDepartment(PDO $pdo, int $compId): int {
    $stmt = $pdo->prepare("INSERT INTO structure_departments (comp_id, department_code, department_name_th, department_name_en, status) VALUES (:comp_id, :code, 'ทดสอบ', 'Test', 'active')");
    $stmt->execute([':comp_id' => $compId, ':code' => 'DEPT_' . uniqid()]);
    return (int)$pdo->lastInsertId();
}
function makeCycle(PayrollCycleModel $cycleModel, int $compId, int $userId): int {
    $res = $cycleModel->save($compId, [
        'cycle_name' => 'PPS_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    if (empty($res['status'])) {
        throw new RuntimeException('Cannot continue without a cycle: ' . $res['message']);
    }
    return (int)$res['id'];
}
function makeEmployee(PDO $pdo, int $compId, int $cycleId, float $baseSalary, string $employmentStatus, ?int $departmentId = null): int {
    $stmt = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, employee_type, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, cycle_id, department_id)
        VALUES (:comp_id, :employee_no, 'domestic', 'mr', 'male', 'ทดสอบ', 'สาย', 'Test', 'Line', '1990-01-01', 'Thai',
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2018-01-01', :employment_status, 'full_time', 'office', 'manual',
         'monthly', :base_salary, '2018-01-01', 'average', 'active', 0, 0, 0, :cycle_id, :department_id)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => 'PPS_' . uniqid(), ':email' => uniqid() . '@test.local',
        ':base_salary' => $baseSalary, ':employment_status' => $employmentStatus,
        ':cycle_id' => $cycleId, ':department_id' => $departmentId,
    ]);
    return (int)$pdo->lastInsertId();
}
function runAndGetDetails(PayrollRunModel $runModel, int $compId, int $cycleId, int $userId, int $monthOffset): array {
    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of this month')->modify("+{$monthOffset} months")->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of this month')->modify("+{$monthOffset} months")->format('Y-m-d');
    $res = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'PPS_RUN_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $periodEnd,
    ], $userId, true);
    if (empty($res['status'])) {
        throw new RuntimeException('Cannot continue without a created run: ' . $res['message']);
    }
    $recalc = $runModel->recalculate((int)$res['id'], $compId, $userId, true);
    if (empty($recalc['status'])) {
        throw new RuntimeException('recalculate() failed: ' . ($recalc['message'] ?? ''));
    }
    return $runModel->getDetails((int)$res['id'], $compId);
}
function detailFor(array $details, int $employeeId): ?array {
    $row = current(array_filter($details, fn($d) => (int)$d['employee_id'] === $employeeId));
    return $row === false ? null : $row;
}

try {
    $userId = 1;
    $policyModel = new PayrollPolicyModel($pdo);
    $assignmentModel = new EntityAssignmentModel($pdo);
    $cycleModel = new PayrollCycleModel($pdo);
    $runModel = new PayrollRunModel($pdo);

    echo "=== Model-level: is_default enforcement (mirrors OtRateSetModel's own convention) ===\n";
    $compA = makeCompany($pdo, 'TH');
    $deptA1 = makeDepartment($pdo, $compA);
    $deptA2 = makeDepartment($pdo, $compA);
    $cycleA = makeCycle($cycleModel, $compA, $userId);

    $firstSet = $policyModel->probationSetSave($compA, [
        'set_name_th' => 'ค่าเริ่มต้น', 'set_name_en' => 'Default', 'probation_base_salary_ratio' => 90.00,
    ], $userId);
    checkTrue('first Set ever created succeeds', $firstSet['status']);
    $defaultSetId = (int)$firstSet['id'];
    $defaultRow = $policyModel->probationSetGet($compA, $defaultSetId);
    checkTrue('first Set is force-defaulted regardless of what was submitted (nothing else to fall back to)', $defaultRow['is_default']);

    $scopedSet = $policyModel->probationSetSave($compA, [
        'set_name_th' => 'แผนก A1', 'set_name_en' => 'Dept A1', 'probation_base_salary_ratio' => 80.00,
        'assignments' => [['scope_type' => 'department', 'scope_id' => $deptA1]],
    ], $userId);
    checkTrue('second Set (not forced default, has an actual "any other set" to fall back to) succeeds', $scopedSet['status']);
    $scopedSetId = (int)$scopedSet['id'];
    $scopedRow = $policyModel->probationSetGet($compA, $scopedSetId);
    checkFalse('second Set is NOT auto-defaulted', $scopedRow['is_default']);
    check('second Set has exactly 1 assignment', count($scopedRow['assignments']), 1);
    check('second Set\'s assignment resolves the real department label', $scopedRow['assignments'][0]['label'] !== null, true);

    echo "\n=== probationSetDelete()/toggleStatus() refuse on the Default Set ===\n";
    $delDefault = $policyModel->probationSetDelete($compA, $defaultSetId, $userId);
    checkFalse('cannot delete the Default Set', $delDefault['status']);
    $toggleDefault = $policyModel->probationSetToggleStatus($compA, $defaultSetId, $userId);
    checkFalse('cannot deactivate the Default Set', $toggleDefault['status']);
    $delScoped = $policyModel->probationSetDelete($compA, $scopedSetId, $userId);
    checkTrue('CAN delete a non-default Set', $delScoped['status']);
    check('deleted Set\'s assignment rows are cleaned up too (deleteAllForEntity called)', count($assignmentModel->getAssignments($compA, 'probation_policy_set', $scopedSetId)), 0);

    // Recreate for the remaining tests (deleted above just to prove the cleanup call worked).
    $scopedSet = $policyModel->probationSetSave($compA, [
        'set_name_th' => 'แผนก A1', 'set_name_en' => 'Dept A1', 'probation_base_salary_ratio' => 80.00,
        'assignments' => [['scope_type' => 'department', 'scope_id' => $deptA1]],
    ], $userId);
    $scopedSetId = (int)$scopedSet['id'];

    echo "\n=== probationSetDuplicate(): the literal 'Clone' -- starts non-default, unscoped ===\n";
    $dup = $policyModel->probationSetDuplicate($compA, $scopedSetId, $userId);
    checkTrue('duplicate() succeeds', $dup['status']);
    $dupRow = $policyModel->probationSetGet($compA, (int)$dup['id']);
    checkFalse('duplicate starts non-default (even though the source could theoretically be re-checked)', $dupRow['is_default']);
    check('duplicate carries the source\'s field values over (ratio)', $dupRow['probation_base_salary_ratio'], 80.00);
    check('duplicate does NOT carry the source\'s assignments forward (starts unscoped)', count($dupRow['assignments']), 0);
    $policyModel->probationSetDelete($compA, (int)$dup['id'], $userId);

    echo "\n=== probationSetSetDefault(): explicit 'make this the default' clears the old one + own assignments ===\n";
    $thirdSet = $policyModel->probationSetSave($compA, [
        'set_name_th' => 'แผนก A2', 'set_name_en' => 'Dept A2', 'probation_base_salary_ratio' => 70.00,
        'assignments' => [['scope_type' => 'department', 'scope_id' => $deptA2]],
    ], $userId);
    $thirdSetId = (int)$thirdSet['id'];
    $setDefaultRes = $policyModel->probationSetSetDefault($compA, $thirdSetId, $userId);
    checkTrue('setDefault() succeeds', $setDefaultRes['status']);
    checkTrue('the newly-default Set is now is_default', $policyModel->probationSetGet($compA, $thirdSetId)['is_default']);
    checkFalse('the OLD default Set lost its flag', $policyModel->probationSetGet($compA, $defaultSetId)['is_default']);
    check('the newly-default Set\'s own assignments were cleared (Default carries none)', count($assignmentModel->getAssignments($compA, 'probation_policy_set', $thirdSetId)), 0);
    // Put default back for the rest of the test.
    $policyModel->probationSetSetDefault($compA, $defaultSetId, $userId);

    echo "\n=== probationSettings(compId, employeeId): resolution + overlap tie-break ===\n";
    $empInDept = makeEmployee($pdo, $compA, $cycleA, 50000, 'probation', $deptA1);
    $empUnrelated = makeEmployee($pdo, $compA, $cycleA, 50000, 'probation', null);
    $resolvedForDeptEmp = $policyModel->probationSettings($compA, $empInDept);
    check('employee IN the assigned department resolves the SCOPED Set\'s ratio (80), not Default (90)', $resolvedForDeptEmp['base_salary_ratio'], 80.00);
    $resolvedForUnrelated = $policyModel->probationSettings($compA, $empUnrelated);
    check('employee with no matching assignment falls back to the Default Set\'s ratio (90)', $resolvedForUnrelated['base_salary_ratio'], 90.00);

    // Overlap: a SECOND non-default Set ALSO claims deptA1 -- probationSetSave() deliberately allows
    // this (no findConflictingAssignment() the way OtRateSetModel has); resolution tie-breaks by id
    // ASC among active non-default Sets, i.e. the OLDER Set ($scopedSetId, created first) wins.
    $overlapSet = $policyModel->probationSetSave($compA, [
        'set_name_th' => 'ทับซ้อน', 'set_name_en' => 'Overlap', 'probation_base_salary_ratio' => 55.00,
        'assignments' => [['scope_type' => 'department', 'scope_id' => $deptA1]],
    ], $userId);
    checkTrue('a SECOND Set claiming the SAME department is allowed (no conflict rejection)', $overlapSet['status']);
    $resolvedAfterOverlap = $policyModel->probationSettings($compA, $empInDept);
    check('on overlap, the OLDER (lower id) matching Set still wins -- deterministic, not the newest', $resolvedAfterOverlap['base_salary_ratio'], 80.00);
    $policyModel->probationSetDelete($compA, (int)$overlapSet['id'], $userId);

    echo "\n=== probationSettings(compId) with NO employeeId (legacy call shape, unchanged) ===\n";
    $legacyShape = $policyModel->probationSettings($compA);
    check('omitting employeeId resolves the company\'s Default Set (90), same as before T056', $legacyShape['base_salary_ratio'], 90.00);

    echo "\n=== REAL end-to-end through PayrollRunModel::recalculate(): 2 employees, 2 different ratios ===\n";
    $detailsA = runAndGetDetails($runModel, $compA, $cycleA, $userId, 1);
    $rowDeptEmp = detailFor($detailsA, $empInDept);
    $rowUnrelated = detailFor($detailsA, $empUnrelated);
    checkTrue('dept-scoped employee appears in the real run', $rowDeptEmp !== null);
    checkTrue('unrelated (falls to Default) employee appears in the real run', $rowUnrelated !== null);
    if ($rowDeptEmp !== null) {
        check('REAL RUN: dept-scoped employee base_salary_amount = 50000 * 80% = 40000.00', (float)$rowDeptEmp['base_salary_amount'], 40000.00);
    }
    if ($rowUnrelated !== null) {
        check('REAL RUN: unrelated employee (falls to Default) base_salary_amount = 50000 * 90% = 45000.00', (float)$rowUnrelated['base_salary_amount'], 45000.00);
    }

    echo "\n=== Backward compatibility: a company with ZERO probation_policy_sets rows computes unchanged ===\n";
    $compB = makeCompany($pdo, 'TH');
    $cycleB = makeCycle($cycleModel, $compB, $userId);
    $empB = makeEmployee($pdo, $compB, $cycleB, 50000, 'probation', null);
    checkTrue('sanity: compB genuinely has zero probation_policy_sets rows', empty($policyModel->probationSetList($compB)));
    $detailsB = runAndGetDetails($runModel, $compB, $cycleB, $userId, 2);
    $rowB = detailFor($detailsB, $empB);
    checkTrue('probation employee appears in the run even with zero Sets configured', $rowB !== null);
    if ($rowB !== null) {
        check('REAL RUN: no Sets at all -> no ratio applied, base_salary_amount = full 50000.00 (unchanged pre-T056 behavior)', (float)$rowB['base_salary_amount'], 50000.00);
    }

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
