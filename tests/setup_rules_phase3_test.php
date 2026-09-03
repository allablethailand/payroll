<?php
/**
 * Lightweight verification script for the phase-3 Setup & Rules additions: Work Location CRUD,
 * Shift-to-employee assignment, and Leave Type CRUD (including the statutory-minimum quota
 * validation). Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real
 * dev DB inside a transaction that is always rolled back.
 * Run with: php tests/setup_rules_phase3_test.php
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
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }

function makeEmployee(PDO $pdo, int $compId, string $employeeNo): int {
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
         'monthly', 30000, '2020-01-01', 'average', 'active',
         1, 1, 0)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => $employeeNo,
        ':name_th' => 'ทดสอบ', ':surname_th' => $employeeNo, ':name_en' => 'Test', ':surname_en' => $employeeNo,
        ':email' => uniqid() . '@test.local',
    ]);
    return (int)$pdo->lastInsertId();
}

try {
    $compId = 1;
    $userId = 1;
    $model = new SetupRulesModel($pdo);

    // ---------- Work Location ----------
    $locCode = 'WL_' . uniqid();
    $loc = $model->workLocationSave([
        'location_name_th' => 'สำนักงานทดสอบ', 'location_name_en' => 'Test Office', 'location_code' => $locCode,
        'address' => '99 Test Rd', 'status' => 'active',
    ], $compId, $userId);
    checkTrue('work location create succeeds', $loc['status']);
    $locId = $loc['id'];

    $dupLoc = $model->workLocationSave([
        'location_name_th' => 'ซ้ำ', 'location_code' => $locCode,
    ], $compId, $userId);
    checkFalse('work location duplicate code rejected', $dupLoc['status']);

    $fetchedLoc = $model->workLocationGet($locId, $compId);
    check('work location get returns correct name', $fetchedLoc['location_name_th'], 'สำนักงานทดสอบ');

    // ---------- Shift with work location ----------
    $shiftCode = 'P3_SH_' . uniqid();
    $shift = $model->shiftSave([
        'shift_name_th' => 'กะทดสอบ P3', 'shift_name_en' => 'Test Shift P3', 'shift_code' => $shiftCode,
        'start_time' => '09:00', 'end_time' => '18:00', 'work_location_id' => $locId, 'status' => 'active',
    ], $compId, $userId);
    checkTrue('shift with work_location_id saves', $shift['status']);
    $shiftId = $shift['id'];

    $badLocShift = $model->shiftSave([
        'shift_name_th' => 'X', 'shift_code' => 'P3_BAD_' . uniqid(), 'start_time' => '08:00', 'end_time' => '17:00',
        'work_location_id' => 999999,
    ], $compId, $userId);
    checkFalse('shift with nonexistent work_location_id rejected', $badLocShift['status']);

    // Work location deletion should be blocked while a shift still references it.
    $blockedDelete = $model->workLocationDelete($locId, $compId, $userId);
    checkFalse('work location delete blocked while referenced by a shift', $blockedDelete['status']);

    // ---------- Shift assignment ----------
    $empA = makeEmployee($pdo, $compId, 'P3_A_' . uniqid());
    $empB = makeEmployee($pdo, $compId, 'P3_B_' . uniqid());

    $assign1 = $model->shiftAssignEmployees($shiftId, [$empA, $empB], $compId, $userId);
    checkTrue('assign 2 employees to shift succeeds', $assign1['status']);
    $assigned1 = $model->shiftAssignedEmployees($shiftId, $compId);
    check('shift now has 2 assigned employees', count($assigned1), 2);

    // Re-assign with only empA -- empB should be released (shift_id cleared), not deleted.
    $assign2 = $model->shiftAssignEmployees($shiftId, [$empA], $compId, $userId);
    checkTrue('re-assign with only empA succeeds', $assign2['status']);
    $assigned2 = $model->shiftAssignedEmployees($shiftId, $compId);
    check('shift now has 1 assigned employee', count($assigned2), 1);
    $stmtCheckB = $pdo->prepare("SELECT shift_id FROM employees WHERE id = :id");
    $stmtCheckB->execute([':id' => $empB]);
    check('empB shift_id cleared after de-assignment', $stmtCheckB->fetchColumn(), null);

    $badAssign = $model->shiftAssignEmployees($shiftId, [999999], $compId, $userId);
    checkFalse('assign nonexistent employee rejected', $badAssign['status']);

    // ---------- Leave Type ----------
    $leaveCode = 'P3_SICK_' . uniqid();
    $sickLeave = $model->leaveTypeSave([
        'name_th' => 'ลาป่วย P3', 'name_en' => 'Sick P3', 'code' => $leaveCode, 'category_id' => 1,
        'quota_type' => 'fixed', 'quota_amount' => 30, 'unit_type' => 'day', 'is_paid' => 1,
        'gender_restriction' => 'all', 'requires_document' => 1, 'is_continuous' => 1, 'allow_carry_over' => 0,
        'applicable_employment_statuses' => ['permanent', 'contract'], 'status' => 'active',
    ], $compId, $userId);
    checkTrue('sick leave type (no statutory floor) saves', $sickLeave['status']);

    $fetchedLeave = $model->leaveTypeGet((int)$sickLeave['id'], $compId);
    check('leave type applicable_employment_statuses stored as CSV', $fetchedLeave['applicable_employment_statuses'], 'permanent,contract');
    check('leave type category name resolved', $fetchedLeave['category_name_en'], 'Sick Leave');

    // Annual leave (category_id=3) has a seeded statutory minimum of 6 days for TH.
    $belowMin = $model->leaveTypeSave([
        'name_th' => 'ลาพักร้อน P3', 'name_en' => 'Annual P3', 'code' => 'P3_ANN_' . uniqid(), 'category_id' => 3,
        'quota_type' => 'fixed', 'quota_amount' => 3, 'unit_type' => 'day', 'status' => 'active',
    ], $compId, $userId);
    checkFalse('annual leave quota below statutory minimum rejected', $belowMin['status']);

    $atMin = $model->leaveTypeSave([
        'name_th' => 'ลาพักร้อน P3 ok', 'name_en' => 'Annual P3 ok', 'code' => 'P3_ANN2_' . uniqid(), 'category_id' => 3,
        'quota_type' => 'fixed', 'quota_amount' => 6, 'unit_type' => 'day', 'status' => 'active',
    ], $compId, $userId);
    checkTrue('annual leave quota exactly at statutory minimum accepted', $atMin['status']);

    // hour-denominated quota below the day-based minimum should NOT be rejected (unit mismatch, skip check).
    $hourUnit = $model->leaveTypeSave([
        'name_th' => 'ลาพักร้อนชม P3', 'name_en' => 'Annual Hourly P3', 'code' => 'P3_ANN3_' . uniqid(), 'category_id' => 3,
        'quota_type' => 'fixed', 'quota_amount' => 2, 'unit_type' => 'hour', 'status' => 'active',
    ], $compId, $userId);
    checkTrue('hour-unit quota skips statutory-day comparison', $hourUnit['status']);

    $invalidStatus = $model->leaveTypeSave([
        'name_th' => 'X', 'name_en' => 'X', 'code' => 'P3_BAD_' . uniqid(), 'category_id' => 1,
        'applicable_employment_statuses' => ['not_a_real_status'],
    ], $compId, $userId);
    checkFalse('invalid employment status value rejected', $invalidStatus['status']);

    $dupLeaveCode = $model->leaveTypeSave([
        'name_th' => 'X', 'name_en' => 'X', 'code' => $leaveCode, 'category_id' => 1,
    ], $compId, $userId);
    checkFalse('duplicate leave type code rejected', $dupLeaveCode['status']);

    $toggle = $model->leaveTypeToggleStatus((int)$sickLeave['id'], $compId, $userId);
    checkTrue('leave type toggle status succeeds', $toggle['status']);
    check('leave type toggled to inactive', $toggle['new_status'], 'inactive');

    $del = $model->leaveTypeDelete((int)$sickLeave['id'], $compId, $userId);
    checkTrue('leave type delete succeeds', $del['status']);
    check('leave type no longer retrievable after delete', $model->leaveTypeGet((int)$sickLeave['id'], $compId), null);

    // ---------- Shift weekly working-day pattern (2026-08-21) ----------
    $wdShift = $model->shiftSave([
        'shift_name_th' => 'กะวันทำงาน P3', 'shift_name_en' => 'Working Days Shift P3', 'shift_code' => 'P3_WD_' . uniqid(),
        'start_time' => '08:00', 'end_time' => '17:00', 'status' => 'active',
        'works_monday' => 1, 'works_tuesday' => 1, 'works_wednesday' => 1, 'works_thursday' => 1, 'works_friday' => 1,
        'works_saturday' => 0, 'works_sunday' => 0,
    ], $compId, $userId);
    checkTrue('shift with explicit Mon-Fri working days saves', $wdShift['status']);
    $wdShiftId = $wdShift['id'];

    $fetchedWdShift = $model->shiftGet($wdShiftId, $compId);
    check('shift round-trips works_monday=1', (int)$fetchedWdShift['works_monday'], 1);
    check('shift round-trips works_saturday=0', (int)$fetchedWdShift['works_saturday'], 0);
    check('shift round-trips works_sunday=0', (int)$fetchedWdShift['works_sunday'], 0);

    // A 6-day Saturday shift, to prove UPDATE also persists the pattern (not just INSERT).
    $sixDayShift = $model->shiftSave([
        'shift_name_th' => 'กะ 6 วัน P3', 'shift_name_en' => 'Six Day Shift P3', 'shift_code' => 'P3_WD6_' . uniqid(),
        'start_time' => '08:00', 'end_time' => '17:00', 'status' => 'active',
        'works_monday' => 1, 'works_tuesday' => 1, 'works_wednesday' => 1, 'works_thursday' => 1, 'works_friday' => 1,
        'works_saturday' => 1, 'works_sunday' => 0,
    ], $compId, $userId);
    $sixDayUpdate = $model->shiftSave([
        'id' => $sixDayShift['id'],
        'shift_name_th' => 'กะ 6 วัน P3', 'shift_name_en' => 'Six Day Shift P3', 'shift_code' => $model->shiftGet($sixDayShift['id'], $compId)['shift_code'],
        'start_time' => '08:00', 'end_time' => '17:00', 'status' => 'active',
        'works_monday' => 1, 'works_tuesday' => 1, 'works_wednesday' => 1, 'works_thursday' => 1, 'works_friday' => 1,
        'works_saturday' => 0, 'works_sunday' => 0,
    ], $compId, $userId);
    checkTrue('shift update with changed working days succeeds', $sixDayUpdate['status']);
    $afterUpdate = $model->shiftGet((int)$sixDayShift['id'], $compId);
    check('shift UPDATE persisted works_saturday flip to 0', (int)$afterUpdate['works_saturday'], 0);

    // ---------- payableDaysForEmployee() (2026-08-21) ----------
    // 2028-06-05 (Mon) .. 2028-06-11 (Sun): a clean 7-day week, 2028-06-07 is a Wednesday
    // (a scheduled work day), 2028-06-10 is a Saturday (already excluded by the shift pattern).
    $wdEmp = makeEmployee($pdo, $compId, 'P3_WD_EMP_' . uniqid());
    $assignWd = $model->shiftAssignEmployees($wdShiftId, [$wdEmp], $compId, $userId);
    checkTrue('assign employee to Mon-Fri shift for payableDays test', $assignWd['status']);

    $payableNoHoliday = $model->payableDaysForEmployee($wdEmp, $compId, '2028-06-05', '2028-06-11');
    check('payableDays: 7-day week, Mon-Fri shift, no holiday -> total_days=7', $payableNoHoliday['total_days'], 7);
    check('payableDays: 7-day week, Mon-Fri shift, no holiday -> payable_days=5', $payableNoHoliday['payable_days'], 5);
    checkTrue('payableDays: has_shift_pattern=true when shift assigned', $payableNoHoliday['has_shift_pattern']);

    // Company holiday on the Wednesday (a scheduled work day) -- must reduce payable_days by 1.
    $wedHoliday = $model->holidaySave([
        'name_th' => 'วันหยุด P3 พุธ', 'name_en' => 'P3 Wed Holiday', 'holiday_date' => '2028-06-07',
        'is_recurring' => 0, 'assignment_mode' => 'exclude', 'status' => 'active', 'assignments' => [],
    ], $compId, $userId);
    checkTrue('company-wide holiday on the Wednesday saves', $wedHoliday['status']);
    $payableWithWedHoliday = $model->payableDaysForEmployee($wdEmp, $compId, '2028-06-05', '2028-06-11');
    check('payableDays: holiday on a scheduled work day -> payable_days=4', $payableWithWedHoliday['payable_days'], 4);

    // Company holiday on the Saturday (already excluded by the shift pattern) -- must NOT
    // double-subtract; payable_days stays at 4, not 3.
    $satHoliday = $model->holidaySave([
        'name_th' => 'วันหยุด P3 เสาร์', 'name_en' => 'P3 Sat Holiday', 'holiday_date' => '2028-06-10',
        'is_recurring' => 0, 'assignment_mode' => 'exclude', 'status' => 'active', 'assignments' => [],
    ], $compId, $userId);
    checkTrue('company-wide holiday on the already-off Saturday saves', $satHoliday['status']);
    $payableWithBothHolidays = $model->payableDaysForEmployee($wdEmp, $compId, '2028-06-05', '2028-06-11');
    check('payableDays: holiday on an already-off day does NOT double-subtract -> payable_days still 4', $payableWithBothHolidays['payable_days'], 4);

    // No shift assigned at all -- falls back to "every day counts unless it's a holiday" and
    // flags has_shift_pattern=false rather than silently guessing Mon-Fri.
    $noShiftEmp = makeEmployee($pdo, $compId, 'P3_NOSHIFT_EMP_' . uniqid());
    $payableNoShift = $model->payableDaysForEmployee($noShiftEmp, $compId, '2028-06-05', '2028-06-11');
    checkFalse('payableDays: has_shift_pattern=false when no shift assigned', $payableNoShift['has_shift_pattern']);
    check('payableDays: no shift -> only the 2 company holidays excluded -> payable_days=5', $payableNoShift['payable_days'], 5);

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
