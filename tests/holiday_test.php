<?php
/**
 * Lightweight verification script for SetupRulesModel's Shift + Holiday backend, focused on the
 * holiday scope priority resolver (employee > position > department > shift). Not PHPUnit --
 * see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a transaction
 * that is always rolled back.
 * Run with: php tests/holiday_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
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
function checkTrue(string $label, bool $actual): void {
    check($label, $actual, true);
}
function checkFalse(string $label, bool $actual): void {
    check($label, $actual, false);
}

function makeEmployee(PDO $pdo, int $compId, string $employeeNo, ?int $deptId, ?int $posId, ?int $shiftId): int {
    $stmt = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         department_id, position_id, shift_id, personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
         :dept_id, :pos_id, :shift_id, :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active',
         1, 1, 0)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => $employeeNo,
        ':name_th' => 'ทดสอบ', ':surname_th' => $employeeNo, ':name_en' => 'Test', ':surname_en' => $employeeNo,
        ':dept_id' => $deptId, ':pos_id' => $posId, ':shift_id' => $shiftId,
        ':email' => uniqid() . '@test.local',
    ]);
    return (int)$pdo->lastInsertId();
}

try {
    $compId = 1;
    $userId = 1;
    $model = new SetupRulesModel($pdo);

    // ---------- Fixtures ----------
    $pdo->prepare("INSERT INTO structure_departments (comp_id, department_code, department_name_th, department_name_en) VALUES (:c, :code, 'แผนกทดสอบ', 'Test Dept')")
        ->execute([':c' => $compId, ':code' => 'HTD_' . uniqid()]);
    $deptId = (int)$pdo->lastInsertId();

    $pdo->prepare("INSERT INTO structure_positions (comp_id, position_code, position_name_th, position_name_en) VALUES (:c, :code, 'ตำแหน่งทดสอบ', 'Test Position')")
        ->execute([':c' => $compId, ':code' => 'HTP_' . uniqid()]);
    $posId = (int)$pdo->lastInsertId();

    $shiftCode = 'HT_' . uniqid();
    $shiftResult = $model->shiftSave([
        'shift_name_th' => 'กะทดสอบ', 'shift_name_en' => 'Test Shift', 'shift_code' => $shiftCode,
        'start_time' => '08:00', 'end_time' => '17:00', 'status' => 'active',
    ], $compId, $userId);
    checkTrue('shift create succeeds', $shiftResult['status']);
    $shiftId = $shiftResult['id'];

    // Employee A: in the test department (no position, no shift) -- should match at department level only.
    $empA = makeEmployee($pdo, $compId, 'HT_A_' . uniqid(), $deptId, null, null);
    // Employee B: same department AND the specific one who gets an employee-level override.
    $empB = makeEmployee($pdo, $compId, 'HT_B_' . uniqid(), $deptId, null, null);
    // Employee C: only on the test shift, unrelated department/position.
    $empC = makeEmployee($pdo, $compId, 'HT_C_' . uniqid(), null, null, $shiftId);
    // Employee D: nothing matches any scope at all.
    $empD = makeEmployee($pdo, $compId, 'HT_D_' . uniqid(), null, null, null);

    // ---------- Shift validation ----------
    $dupCode = $model->shiftSave([
        'shift_name_th' => 'ซ้ำ', 'shift_name_en' => 'Dup', 'shift_code' => $shiftCode,
        'start_time' => '08:00', 'end_time' => '17:00',
    ], $compId, $userId);
    checkFalse('shift duplicate code rejected', $dupCode['status']);

    $missingField = $model->shiftSave(['shift_name_th' => 'X'], $compId, $userId);
    checkFalse('shift missing required field rejected', $missingField['status']);

    // ---------- Holiday: include + department scope, one-time ----------
    $h1 = $model->holidaySave([
        'name_th' => 'ทดสอบแผนก', 'name_en' => 'Dept Holiday', 'holiday_date' => '2027-03-10',
        'is_recurring' => 0, 'assignment_mode' => 'include', 'status' => 'active',
        'assignments' => [['scope_type' => 'department', 'scope_id' => $deptId]],
    ], $compId, $userId);
    checkTrue('holiday1 (department include) saves', $h1['status']);

    // ---------- Holiday: include + employee scope override for empB on the SAME date ----------
    $h2 = $model->holidaySave([
        'name_th' => 'ทดสอบเฉพาะบุคคล', 'name_en' => 'Employee Override', 'holiday_date' => '2027-03-11',
        'is_recurring' => 0, 'assignment_mode' => 'include', 'status' => 'active',
        'assignments' => [['scope_type' => 'employee', 'scope_id' => $empB]],
    ], $compId, $userId);
    checkTrue('holiday2 (employee include, different date) saves', $h2['status']);

    // ---------- Holiday: exclude + shift scope, recurring ----------
    $h3 = $model->holidaySave([
        'name_th' => 'ทดสอบยกเว้นกะ', 'name_en' => 'Shift Exclude Recurring', 'holiday_date' => '2027-12-05',
        'is_recurring' => 1, 'assignment_mode' => 'exclude', 'status' => 'active',
        'assignments' => [['scope_type' => 'shift', 'scope_id' => $shiftId]],
    ], $compId, $userId);
    checkTrue('holiday3 (shift exclude, recurring) saves', $h3['status']);

    // ---------- Validation: invalid scope ref ----------
    $badScope = $model->holidaySave([
        'name_th' => 'X', 'name_en' => 'X', 'holiday_date' => '2027-05-05',
        'is_recurring' => 0, 'assignment_mode' => 'include', 'status' => 'active',
        'assignments' => [['scope_type' => 'employee', 'scope_id' => 999999]],
    ], $compId, $userId);
    checkFalse('holiday with nonexistent employee scope rejected', $badScope['status']);

    // ---------- Validation: include mode with zero scopes ----------
    $emptyInclude = $model->holidaySave([
        'name_th' => 'X', 'name_en' => 'X', 'holiday_date' => '2027-05-06',
        'is_recurring' => 0, 'assignment_mode' => 'include', 'status' => 'active', 'assignments' => [],
    ], $compId, $userId);
    checkFalse('holiday include mode with no scope rejected', $emptyInclude['status']);

    // ---------- Validation: date collision (same one-time date as holiday1) ----------
    $dupDate = $model->holidaySave([
        'name_th' => 'X', 'name_en' => 'X', 'holiday_date' => '2027-03-10',
        'is_recurring' => 0, 'assignment_mode' => 'exclude', 'status' => 'active', 'assignments' => [],
    ], $compId, $userId);
    checkFalse('holiday date collision rejected', $dupDate['status']);

    // ---------- Priority resolution ----------
    // Employee A: only department-level match on 2027-03-10 -> holiday1 applies.
    $resA = $model->resolveHolidaysForEmployee($empA, $compId, '2027-03-01', '2027-03-31');
    $datesA = array_column($resA, 'date');
    checkTrue('empA gets dept holiday on 2027-03-10', in_array('2027-03-10', $datesA, true));
    $entryA = current(array_filter($resA, fn($r) => $r['date'] === '2027-03-10'));
    check('empA matched scope is department', $entryA['matched_scope'], 'department');
    checkFalse('empA does NOT get employee-override holiday (different date+not targeted)', in_array('2027-03-11', $datesA, true));

    // Employee B: department-level match on 03-10 (from holiday1, same as empA) AND a dedicated
    // employee-level holiday on 03-11 -- both should resolve since they're on different dates.
    $resB = $model->resolveHolidaysForEmployee($empB, $compId, '2027-03-01', '2027-03-31');
    $datesB = array_column($resB, 'date');
    checkTrue('empB gets dept holiday on 2027-03-10', in_array('2027-03-10', $datesB, true));
    checkTrue('empB gets employee-override holiday on 2027-03-11', in_array('2027-03-11', $datesB, true));
    $entryB11 = current(array_filter($resB, fn($r) => $r['date'] === '2027-03-11'));
    check('empB 03-11 matched scope is employee', $entryB11['matched_scope'], 'employee');

    // Employee C: on the excluded shift -> the recurring exclude-shift holiday must NOT apply to them
    // (everyone else gets it, they don't) on any of its recurring dates within range.
    $resC = $model->resolveHolidaysForEmployee($empC, $compId, '2027-01-01', '2028-12-31');
    $datesC = array_column($resC, 'date');
    checkFalse('empC (on excluded shift) does NOT get the shift-exclude holiday in 2027', in_array('2027-12-05', $datesC, true));
    checkFalse('empC (on excluded shift) does NOT get the shift-exclude holiday in 2028', in_array('2028-12-05', $datesC, true));

    // Employee D: matches nothing specific -> gets the exclude-mode holiday (recurring, both years)
    // since "exclude nobody-specific" means it applies company-wide.
    $resD = $model->resolveHolidaysForEmployee($empD, $compId, '2027-01-01', '2028-12-31');
    $datesD = array_column($resD, 'date');
    checkTrue('empD (matches nothing) gets shift-exclude holiday in 2027', in_array('2027-12-05', $datesD, true));
    checkTrue('empD (matches nothing) gets shift-exclude holiday in 2028 (recurring)', in_array('2028-12-05', $datesD, true));
    checkFalse('empD does not get department-scoped holiday1 (not in that dept)', in_array('2027-03-10', $datesD, true));

    // ---------- Toggle + delete ----------
    $toggle = $model->holidayToggleStatus((int)$h1['id'], $compId, $userId);
    checkTrue('holiday1 toggle status succeeds', $toggle['status']);
    check('holiday1 toggled to inactive', $toggle['new_status'], 'inactive');

    $del = $model->holidayDelete((int)$h2['id'], $compId, $userId);
    checkTrue('holiday2 delete succeeds', $del['status']);
    $afterDelete = $model->holidayGet((int)$h2['id'], $compId);
    check('holiday2 no longer retrievable after delete', $afterDelete, null);

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
