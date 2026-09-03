<?php
/**
 * Lightweight verification script for the generic "Assign Employees" modal backend (2026-08-31,
 * explicit request: "เพิ่มปุ่มให้สามารถ Assign ได้...แบ่งเป็น 2 Card คือพนักงานที่อยู่ Master อื่น และพนักงานที่
 * อยู่ Master นี้...และมีอีกปุ่มสำหรับกด View"). Covers one CompanyProfileModel-owned type
 * (department) + the newly-linked Rank (employees.rank_id never existed before this batch) +
 * both SetupRulesModel-owned types (shift, work_location) -- not exhaustive across all 7 types
 * (branch/role/position/team share IDENTICAL code paths as department via structureConfig(), so
 * would only be re-testing the same generic method against different table names).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 * Run with: php tests/structure_assign_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/CompanyProfileModel.php';
require_once __DIR__ . '/../app/models/SetupRulesModel.php';

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
    $compId = 1;
    $adminUserId = (int)$pdo->query("SELECT id FROM employees WHERE comp_id = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn();

    $companyModel = new CompanyProfileModel($pdo);
    $setupModel = new SetupRulesModel($pdo);

    function insertFixtureEmployee(PDO $pdo, int $compId, string $tag): int {
        $stmt = $pdo->prepare("INSERT INTO `employees`
            (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
             personal_email, mobile_no, address_line_1_register, address_line_1_contact,
             emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
             employment_date, employment_status, employment_type, workforce_type, record_time_method,
             salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt)
            VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
             :email, '0800000000', 'Test Address', 'Test Address',
             'Emergency', 'Contact', 'friend', '0899999999',
             '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
             'monthly', 20000, '2020-01-01', 'average', 'active',
             0, 0, 0)");
        $stmt->execute([
            ':comp_id' => $compId, ':employee_no' => 'ASSIGN_TEST_' . $tag . '_' . uniqid(),
            ':name_th' => 'ทดสอบ', ':surname_th' => $tag, ':name_en' => 'Test', ':surname_en' => $tag,
            ':email' => uniqid() . '@test.local',
        ]);
        return (int)$pdo->lastInsertId();
    }

    // ---------- CompanyProfileModel: 'department' (representative of branch/role/position/team --
    // all share the exact same generic structureConfig()-driven code path) ----------
    echo "=== CompanyProfileModel: structureAssignEmployees()/EmployeesInRow()/EmployeesOutsideRow()/MoveEmployeesOut() -- 'department' ===\n";
    $deptARes = $companyModel->saveStructure('department', $compId, ['department_code' => 'ASSIGNTEST_A_' . rand(1000, 9999), 'department_name_th' => 'แผนก A', 'department_name_en' => 'Dept A'], $adminUserId);
    checkTrue('fixture: department A created', $deptARes['status']);
    $deptAId = $deptARes['id'];
    $deptBRes = $companyModel->saveStructure('department', $compId, ['department_code' => 'ASSIGNTEST_B_' . rand(1000, 9999), 'department_name_th' => 'แผนก B', 'department_name_en' => 'Dept B'], $adminUserId);
    checkTrue('fixture: department B created', $deptBRes['status']);
    $deptBId = $deptBRes['id'];

    $empUnassigned = insertFixtureEmployee($pdo, $compId, 'Unassigned');
    $empInB = insertFixtureEmployee($pdo, $compId, 'InB');
    $pdo->prepare("UPDATE employees SET department_id = :d WHERE id = :id")->execute([':d' => $deptBId, ':id' => $empInB]);

    $outsideA = $companyModel->structureEmployeesOutsideRow('department', $deptAId, $compId);
    checkTrue('structureEmployeesOutsideRow() succeeds', $outsideA['status']);
    $outsideAIds = array_map('intval', array_column($outsideA['data'], 'id'));
    checkTrue('unassigned employee appears in "outside A" (pull-in candidates)', in_array($empUnassigned, $outsideAIds, true));
    checkTrue('employee in Dept B also appears in "outside A"', in_array($empInB, $outsideAIds, true));
    $empInBRow = current(array_filter($outsideA['data'], fn($r) => (int)$r['id'] === $empInB));
    check('employee in Dept B carries its own current_row_name for display', $empInBRow['current_row_name'], 'แผนก B');

    $assignRes = $companyModel->structureAssignEmployees('department', $deptAId, [$empUnassigned, $empInB], $compId, $adminUserId);
    checkTrue('structureAssignEmployees() (pull in) succeeds', $assignRes['status']);
    $inARes = $companyModel->structureEmployeesInRow('department', $deptAId, $compId);
    checkTrue('structureEmployeesInRow() succeeds', $inARes['status']);
    $inAIds = array_map('intval', array_column($inARes['data'], 'id'));
    check('exactly 2 employees now in Dept A', count($inAIds), 2);
    checkTrue('both pulled-in employees present', in_array($empUnassigned, $inAIds, true) && in_array($empInB, $inAIds, true));

    // Pulling employees INTO A doesn't clear anyone ELSE already in A (additive, not full-replace --
    // see structureAssignEmployees()'s own docblock) -- prove with a 3rd employee assigned directly.
    $empPreExistingInA = insertFixtureEmployee($pdo, $compId, 'PreA');
    $pdo->prepare("UPDATE employees SET department_id = :d WHERE id = :id")->execute([':d' => $deptAId, ':id' => $empPreExistingInA]);
    $assignRes2 = $companyModel->structureAssignEmployees('department', $deptAId, [$empUnassigned], $compId, $adminUserId);
    checkTrue('re-pulling an already-in employee is a harmless no-op success', $assignRes2['status']);
    $inAAfter = array_map('intval', array_column($companyModel->structureEmployeesInRow('department', $deptAId, $compId)['data'], 'id'));
    checkTrue('the pre-existing employee is STILL in Dept A (additive, not cleared)', in_array($empPreExistingInA, $inAAfter, true));

    echo "=== structureMoveEmployeesOut(): with a destination, and with none (unassigned) ===\n";
    $moveRes = $companyModel->structureMoveEmployeesOut('department', [$empInB], $compId, $deptBId, $adminUserId);
    checkTrue('moving one employee to Dept B (real destination) succeeds', $moveRes['status']);
    $rowAfterMove = $pdo->query("SELECT department_id FROM employees WHERE id = {$empInB}")->fetch(PDO::FETCH_ASSOC);
    check('employee now has department_id = Dept B', (int)$rowAfterMove['department_id'], $deptBId);

    $moveOutNoDest = $companyModel->structureMoveEmployeesOut('department', [$empUnassigned], $compId, null, $adminUserId);
    checkTrue('moving out with NO destination (unassigned) succeeds', $moveOutNoDest['status']);
    $rowAfterUnassign = $pdo->query("SELECT department_id FROM employees WHERE id = {$empUnassigned}")->fetch(PDO::FETCH_ASSOC);
    checkTrue('department_id is now NULL (no สังกัด)', $rowAfterUnassign['department_id'] === null);

    $moveInvalidDest = $companyModel->structureMoveEmployeesOut('department', [$empPreExistingInA], $compId, 999999999, $adminUserId);
    checkFalse('moving to a nonexistent destination id is refused', $moveInvalidDest['status']);

    checkFalse('structureAssignEmployees() with an invalid type is refused', $companyModel->structureAssignEmployees('bogus_type', $deptAId, [$empUnassigned], $compId, $adminUserId)['status']);
    checkFalse('structureEmployeesInRow() with a cross-company row id is refused', $companyModel->structureEmployeesInRow('department', $deptAId, $compId + 999)['status']);

    // ---------- CompanyProfileModel: 'rank' -- the ONE type that never had an employees FK column
    // before this batch (structure_ranks existed, employees.rank_id did not) ----------
    echo "=== CompanyProfileModel: 'rank' (the newly-linked type, employees.rank_id is brand new) ===\n";
    $rankRes = $companyModel->saveStructure('rank', $compId, ['rank_code' => 'ASSIGNTEST_R_' . rand(1000, 9999), 'rank_name_th' => 'ระดับทดสอบ', 'rank_name_en' => 'Test Rank'], $adminUserId);
    checkTrue('fixture: rank created', $rankRes['status']);
    $rankId = $rankRes['id'];
    $empForRank = insertFixtureEmployee($pdo, $compId, 'ForRank');
    $rankAssignRes = $companyModel->structureAssignEmployees('rank', $rankId, [$empForRank], $compId, $adminUserId);
    checkTrue('structureAssignEmployees() works for rank' . (empty($rankAssignRes['status']) ? " ({$rankAssignRes['message']})" : ''), $rankAssignRes['status']);
    $rankRow = $pdo->query("SELECT rank_id FROM employees WHERE id = {$empForRank}")->fetch(PDO::FETCH_ASSOC);
    check('employees.rank_id actually persisted', (int)$rankRow['rank_id'], $rankId);
    $rankInRow = $companyModel->structureEmployeesInRow('rank', $rankId, $compId);
    check('structureEmployeesInRow() finds exactly 1 employee for this rank', count($rankInRow['data']), 1);

    // ---------- SetupRulesModel: 'shift' + 'work_location' (own class, mirrored methods) ----------
    echo "=== SetupRulesModel: scopeAssignEmployees()/EmployeesInRow()/EmployeesOutsideRow()/MoveEmployeesOut() -- 'shift' ===\n";
    $shiftARes = $setupModel->shiftSave(['shift_code' => 'ASSIGNTEST_SA_' . rand(1000, 9999), 'shift_name_th' => 'กะ A', 'start_time' => '08:00', 'end_time' => '17:00'], $compId, $adminUserId);
    checkTrue('fixture: shift A created', $shiftARes['status']);
    $shiftAId = $shiftARes['id'];
    $shiftBRes = $setupModel->shiftSave(['shift_code' => 'ASSIGNTEST_SB_' . rand(1000, 9999), 'shift_name_th' => 'กะ B', 'start_time' => '13:00', 'end_time' => '22:00'], $compId, $adminUserId);
    checkTrue('fixture: shift B created', $shiftBRes['status']);
    $shiftBId = $shiftBRes['id'];

    $empForShift = insertFixtureEmployee($pdo, $compId, 'ForShift');
    $pdo->prepare("UPDATE employees SET shift_id = :s WHERE id = :id")->execute([':s' => $shiftBId, ':id' => $empForShift]);
    $shiftOutsideA = $setupModel->scopeEmployeesOutsideRow('shift', $shiftAId, $compId);
    checkTrue('scopeEmployeesOutsideRow() (shift) succeeds', $shiftOutsideA['status']);
    checkTrue('employee on shift B appears in "outside shift A"', in_array($empForShift, array_map('intval', array_column($shiftOutsideA['data'], 'id')), true));

    $shiftAssignRes = $setupModel->scopeAssignEmployees('shift', $shiftAId, [$empForShift], $compId, $adminUserId);
    checkTrue('scopeAssignEmployees() (shift) succeeds', $shiftAssignRes['status']);
    $shiftInA = $setupModel->scopeEmployeesInRow('shift', $shiftAId, $compId);
    check('exactly 1 employee now in shift A', count($shiftInA['data']), 1);

    $shiftMoveOut = $setupModel->scopeMoveEmployeesOut('shift', [$empForShift], $compId, null, $adminUserId);
    checkTrue('scopeMoveEmployeesOut() (shift) with no destination succeeds', $shiftMoveOut['status']);
    $shiftRowAfter = $pdo->query("SELECT shift_id FROM employees WHERE id = {$empForShift}")->fetch(PDO::FETCH_ASSOC);
    checkTrue('shift_id is now NULL', $shiftRowAfter['shift_id'] === null);

    echo "=== SetupRulesModel: 'work_location' ===\n";
    $locRes = $setupModel->workLocationSave(['location_code' => 'ASSIGNTEST_L_' . rand(1000, 9999), 'location_name_th' => 'สาขาทดสอบ'], $compId, $adminUserId);
    checkTrue('fixture: work location created', $locRes['status']);
    $locId = $locRes['id'];
    $empForLoc = insertFixtureEmployee($pdo, $compId, 'ForLoc');
    $locAssignRes = $setupModel->scopeAssignEmployees('work_location', $locId, [$empForLoc], $compId, $adminUserId);
    checkTrue('scopeAssignEmployees() (work_location) succeeds' . (empty($locAssignRes['status']) ? " ({$locAssignRes['message']})" : ''), $locAssignRes['status']);
    $locRow = $pdo->query("SELECT work_location_id FROM employees WHERE id = {$empForLoc}")->fetch(PDO::FETCH_ASSOC);
    check('employees.work_location_id actually persisted', (int)$locRow['work_location_id'], $locId);

    checkFalse('scopeAssignEmployees() with an invalid type is refused', $setupModel->scopeAssignEmployees('bogus_type', $locId, [$empForLoc], $compId, $adminUserId)['status']);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
