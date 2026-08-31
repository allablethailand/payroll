<?php
/**
 * Lightweight verification script for the manual-entry models (AttendanceRecordModel/
 * LeaveRequestModel/OvertimeRecordModel) -- Step 6 of the Origami HR data ingestion feature. Not
 * PHPUnit -- see tests/statutory_engine_test.php for why.
 * Runs against the real dev DB inside a transaction that is always rolled back.
 * Run with: php tests/manual_entry_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/AttendanceRecordModel.php';
require_once __DIR__ . '/../app/models/LeaveRequestModel.php';
require_once __DIR__ . '/../app/models/OvertimeRecordModel.php';
require_once __DIR__ . '/../app/models/OtRateSetModel.php';

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
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, department_id)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', 30000, '2020-01-01', 'average', 'active',
         1, 1, 0, NULL)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => $employeeNo,
        ':name_th' => 'ทดสอบ', ':surname_th' => $employeeNo, ':name_en' => 'Test', ':surname_en' => $employeeNo,
        ':email' => uniqid() . '@test.local',
    ]);
    return (int)$pdo->lastInsertId();
}

try {
    $compId = 1;
    $adminUserId = 1;
    $employeeId = makeEmployee($pdo, $compId, 'MAN_EMP_' . uniqid());
    $otherCompEmployeeId = null; // used for cross-company rejection checks below (comp_id=2)

    // ---------- AttendanceRecordModel ----------
    echo "=== AttendanceRecordModel ===\n";
    $attModel = new AttendanceRecordModel($pdo);

    $r = $attModel->save(['employee_id' => 999999, 'work_date' => '2026-04-01'], $compId, $adminUserId);
    checkFalse('unknown employee rejected', $r['status']);

    $r = $attModel->save(['employee_id' => $employeeId, 'work_date' => 'not-a-date'], $compId, $adminUserId);
    checkFalse('invalid work_date rejected', $r['status']);

    $r = $attModel->save(['employee_id' => $employeeId, 'work_date' => '2026-04-01', 'clock_in' => '2026-04-01 17:00:00', 'clock_out' => '2026-04-01 08:00:00'], $compId, $adminUserId);
    checkFalse('clock_out before clock_in rejected', $r['status']);

    $created = $attModel->save(['employee_id' => $employeeId, 'work_date' => '2026-04-01', 'clock_in' => '2026-04-01 08:00:00', 'clock_out' => '2026-04-01 17:00:00'], $compId, $adminUserId);
    checkTrue('valid attendance record created', $created['status']);
    $attRow = $attModel->get((int)$created['id'], $compId);
    check('actual_work_minutes computed', (int)$attRow['actual_work_minutes'], 540);
    check('data_source is manual', $attRow['data_source'], 'manual');
    checkTrue('sync_batch_id is NULL (not part of a batch)', $attRow['sync_batch_id'] === null);

    $dup = $attModel->save(['employee_id' => $employeeId, 'work_date' => '2026-04-01'], $compId, $adminUserId);
    checkFalse('duplicate employee+work_date rejected', $dup['status']);

    $updateSameRecord = $attModel->save(['id' => $created['id'], 'employee_id' => $employeeId, 'work_date' => '2026-04-01', 'status' => 'present'], $compId, $adminUserId);
    checkTrue('updating the SAME record (excluding itself from the dup check) succeeds', $updateSameRecord['status']);

    // 2026-08-30 (Phase 5, conflict-prevention): data_source resets to 'manual' on every edit, even
    // if it had previously come from Sync/Import -- proves the badge stays honest.
    $pdo->prepare("UPDATE attendance_records SET data_source = 'sync' WHERE id = :id")->execute([':id' => $created['id']]);
    $attModel->save(['id' => $created['id'], 'employee_id' => $employeeId, 'work_date' => '2026-04-01', 'status' => 'present'], $compId, $adminUserId);
    check('editing a sync-sourced record via Manual Entry flips data_source back to manual', $attModel->get((int)$created['id'], $compId)['data_source'], 'manual');

    $listed = $attModel->list($compId, ['employee_id' => $employeeId]);
    check('list() returns the record', count($listed), 1);

    $delRes = $attModel->delete((int)$created['id'], $compId, $adminUserId);
    checkTrue('delete succeeds', $delRes['status']);
    check('deleted record no longer retrievable', $attModel->get((int)$created['id'], $compId), null);

    // ---------- LeaveRequestModel ----------
    echo "=== LeaveRequestModel ===\n";
    $leaveTypeRow = $pdo->query("SELECT id FROM leave_types WHERE comp_id = {$compId} AND deleted_at IS NULL LIMIT 1")->fetchColumn();
    if ($leaveTypeRow === false) {
        // Fixture: minimal leave type if none exists for comp 1 in this dev DB.
        $catId = (int)$pdo->query("SELECT id FROM master_leave_categories WHERE code='personal'")->fetchColumn();
        $pdo->prepare("INSERT INTO leave_types (comp_id, category_id, code, name_th, name_en, status)
            VALUES (:comp_id, :cat, :code, 'ลาทดสอบ', 'Test Leave', 'active')")
            ->execute([':comp_id' => $compId, ':cat' => $catId, ':code' => 'MAN_LT_' . uniqid()]);
        $leaveTypeRow = (int)$pdo->lastInsertId();
    }
    $leaveTypeId = (int)$leaveTypeRow;
    $leaveModel = new LeaveRequestModel($pdo);

    $r = $leaveModel->save(['employee_id' => $employeeId, 'leave_type_id' => 999999, 'start_date' => '2026-04-05', 'end_date' => '2026-04-05', 'total_days' => 1], $compId, $adminUserId);
    checkFalse('unknown leave_type rejected', $r['status']);

    $r = $leaveModel->save(['employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'start_date' => '2026-04-10', 'end_date' => '2026-04-05', 'total_days' => 1], $compId, $adminUserId);
    checkFalse('end_date before start_date rejected', $r['status']);

    $r = $leaveModel->save(['employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'start_date' => '2026-04-05', 'end_date' => '2026-04-05', 'total_days' => 0], $compId, $adminUserId);
    checkFalse('total_days=0 rejected', $r['status']);

    $leaveCreated = $leaveModel->save(['employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'start_date' => '2026-04-05', 'end_date' => '2026-04-05', 'total_days' => 1], $compId, $adminUserId);
    checkTrue('valid leave request created', $leaveCreated['status']);
    $leaveRow = $leaveModel->get((int)$leaveCreated['id'], $compId);
    check('status defaults to approved', $leaveRow['status'], 'approved');
    check('data_source is manual', $leaveRow['data_source'], 'manual');

    $leaveDup = $leaveModel->save(['employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'start_date' => '2026-04-05', 'end_date' => '2026-04-05', 'total_days' => 1], $compId, $adminUserId);
    checkFalse('exact duplicate leave request rejected', $leaveDup['status']);

    // 2026-08-30 (Phase 5, conflict-prevention): an OVERLAPPING (not identical) range for the same
    // employee+leave_type is now also rejected, not just an exact duplicate.
    $leaveOverlap = $leaveModel->save(['employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'start_date' => '2026-04-04', 'end_date' => '2026-04-06', 'total_days' => 3], $compId, $adminUserId);
    checkFalse('overlapping (non-identical) date range for the same employee+leave_type rejected', $leaveOverlap['status']);
    checkTrue('overlap rejection message names the conflicting range', strpos((string)$leaveOverlap['message'], '2026-04-05') !== false);

    // A separate, non-overlapping range for the same employee+leave_type is still allowed.
    $leaveSeparate = $leaveModel->save(['employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'start_date' => '2026-04-20', 'end_date' => '2026-04-20', 'total_days' => 1], $compId, $adminUserId);
    checkTrue('non-overlapping second leave request for the same employee+type is allowed', $leaveSeparate['status']);
    $leaveModel->delete((int)$leaveSeparate['id'], $compId, $adminUserId);

    // data_source resets to 'manual' on every edit -- same fix as AttendanceRecordModel above.
    $pdo->prepare("UPDATE leave_requests SET data_source = 'import' WHERE id = :id")->execute([':id' => $leaveCreated['id']]);
    $leaveModel->save(['id' => $leaveCreated['id'], 'employee_id' => $employeeId, 'leave_type_id' => $leaveTypeId, 'start_date' => '2026-04-05', 'end_date' => '2026-04-05', 'total_days' => 1], $compId, $adminUserId);
    check('editing an import-sourced leave request flips data_source back to manual', $leaveModel->get((int)$leaveCreated['id'], $compId)['data_source'], 'manual');

    $delRes = $leaveModel->delete((int)$leaveCreated['id'], $compId, $adminUserId);
    checkTrue('leave delete succeeds', $delRes['status']);

    // ---------- OvertimeRecordModel ----------
    echo "=== OvertimeRecordModel ===\n";
    // 2026-08-30: ot_rate_id now targets ot_rate_set_items, not the retired flat `ot_rates` table --
    // see OvertimeRecordModel's own docblock (TransactionDataPayAdapter only ever reads the referenced
    // row's own ot_scope_id, never its rate value).
    $otRateSetModel = new OtRateSetModel($pdo);
    $scopeId = (int)$pdo->query("SELECT id FROM master_ot_scope_types WHERE code='weekday'")->fetchColumn();
    $otRateRow = $pdo->prepare("SELECT i.id FROM ot_rate_set_items i JOIN ot_rate_sets s ON s.id = i.set_id
        WHERE s.comp_id = :comp_id AND s.deleted_at IS NULL AND i.ot_scope_id = :scope LIMIT 1");
    $otRateRow->execute([':comp_id' => $compId, ':scope' => $scopeId]);
    $otRateId = $otRateRow->fetchColumn();
    if ($otRateId === false) {
        $setSave = $otRateSetModel->save([
            'name_th' => 'ชุด OT ทดสอบ', 'name_en' => 'Test OT Set', 'is_default' => true,
            'items' => [['ot_scope_id' => $scopeId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 1.5, 'calculation_base' => 'hourly']],
        ], $compId, $adminUserId);
        checkTrue('fixture: OT Rate Set created' . (empty($setSave['status']) ? " ({$setSave['message']})" : ''), $setSave['status']);
        $otRateRow->execute([':comp_id' => $compId, ':scope' => $scopeId]);
        $otRateId = $otRateRow->fetchColumn();
    }
    $otRateId = (int)$otRateId;
    $otModel = new OvertimeRecordModel($pdo);

    $r = $otModel->save(['employee_id' => $employeeId, 'ot_rate_id' => 999999, 'ot_date' => '2026-04-05', 'hours' => 2], $compId, $adminUserId);
    checkFalse('unknown ot_rate rejected', $r['status']);

    $r = $otModel->save(['employee_id' => $employeeId, 'ot_rate_id' => $otRateId, 'ot_date' => '2026-04-05', 'hours' => 0], $compId, $adminUserId);
    checkFalse('hours=0 rejected', $r['status']);

    $otCreated = $otModel->save(['employee_id' => $employeeId, 'ot_rate_id' => $otRateId, 'ot_date' => '2026-04-05', 'hours' => 2.5, 'amount' => 375], $compId, $adminUserId);
    checkTrue('valid overtime record created', $otCreated['status']);
    $otRow = $otModel->get((int)$otCreated['id'], $compId);
    check('hours persisted', (float)$otRow['hours'], 2.5);
    check('status defaults to approved', $otRow['status'], 'approved');

    $otDup = $otModel->save(['employee_id' => $employeeId, 'ot_rate_id' => $otRateId, 'ot_date' => '2026-04-05', 'hours' => 1], $compId, $adminUserId);
    checkFalse('duplicate employee+rate+date rejected', $otDup['status']);

    // data_source resets to 'manual' on every edit -- same fix as the other 2 models above.
    $pdo->prepare("UPDATE overtime_records SET data_source = 'sync' WHERE id = :id")->execute([':id' => $otCreated['id']]);
    $otModel->save(['id' => $otCreated['id'], 'employee_id' => $employeeId, 'ot_rate_id' => $otRateId, 'ot_date' => '2026-04-05', 'hours' => 2.5], $compId, $adminUserId);
    check('editing a sync-sourced overtime record flips data_source back to manual', $otModel->get((int)$otCreated['id'], $compId)['data_source'], 'manual');

    $delRes = $otModel->delete((int)$otCreated['id'], $compId, $adminUserId);
    checkTrue('overtime delete succeeds', $delRes['status']);

} catch (Throwable $e) {
    $failures++;
    echo "  FAIL  Uncaught exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
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
