<?php
/**
 * Lightweight verification script for the Origami HR transaction-data sync engine
 * (TransactionDataSyncRegistry/TransactionDataSyncOrchestrator/AttendanceSyncer/
 * LeaveRequestSyncer/OvertimeRecordSyncer). Not PHPUnit -- see tests/statutory_engine_test.php
 * for why.
 *
 * Uses a FakeOrigamiSyncClient (same idea as master_data_sync_test.php's, redefined here so this
 * file can run standalone) instead of the real OrigamiSyncClient stub.
 *
 * Runs against the real dev DB inside a transaction that is always rolled back.
 * Run with: php tests/transaction_data_sync_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/sync/OrigamiSyncClientInterface.php';
require_once __DIR__ . '/../app/services/sync/MasterDataSyncOrchestrator.php';
require_once __DIR__ . '/../app/services/sync/TransactionDataSyncOrchestrator.php';
require_once __DIR__ . '/../app/models/SyncBatchModel.php';

class FakeOrigamiSyncClient implements OrigamiSyncClientInterface {
    public array $departments = [];
    public array $positions = [];
    public array $shifts = [];
    public array $holidays = [];
    public array $leaveTypes = [];
    public array $otRates = [];
    public array $employees = [];
    public array $attendance = [];
    public array $leaveRequests = [];
    public array $overtimeRecords = [];

    public function fetchDepartments(int $origamiCompanyId): array { return $this->departments; }
    public function fetchPositions(int $origamiCompanyId): array { return $this->positions; }
    public function fetchShifts(int $origamiCompanyId): array { return $this->shifts; }
    public function fetchHolidays(int $origamiCompanyId): array { return $this->holidays; }
    public function fetchLeaveTypes(int $origamiCompanyId): array { return $this->leaveTypes; }
    public function fetchOtRates(int $origamiCompanyId): array { return $this->otRates; }
    public function fetchEmployees(int $origamiCompanyId): array { return $this->employees; }
    public function fetchAttendance(int $origamiCompanyId, string $dateFrom, string $dateTo): array { return $this->attendance; }
    public function fetchLeaveRequests(int $origamiCompanyId, string $dateFrom, string $dateTo): array { return $this->leaveRequests; }
    public function fetchOvertimeRecords(int $origamiCompanyId, string $dateFrom, string $dateTo): array { return $this->overtimeRecords; }
}

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
    $adminUserId = 1;
    $origamiCompanyId = 90002;
    $fake = new FakeOrigamiSyncClient();
    $pdo->prepare("UPDATE companies SET ref_id = :ref WHERE id = :id")->execute([':ref' => $origamiCompanyId, ':id' => $compId]);

    $masterOrch = new MasterDataSyncOrchestrator($pdo, $fake);
    $txOrch = new TransactionDataSyncOrchestrator($pdo, $fake);

    // ---------- Gate: transaction sync blocked before master data has ever completed ----------
    echo "=== Dependency gate ===\n";
    $blocked = $txOrch->syncEntity($compId, 'attendance', $adminUserId, '2026-01-01', '2026-01-31');
    checkFalse('attendance sync blocked before master data has synced', $blocked['status']);
    checkTrue('error mentions master data', strpos($blocked['message'], 'Master data') !== false);

    // ---------- Invalid date range ----------
    $badRange = $txOrch->syncEntity($compId, 'attendance', $adminUserId, '2026-01-31', '2026-01-01');
    checkFalse('dateFrom > dateTo rejected', $badRange['status']);

    // ---------- Sync master data so employees/shifts/leave_types/ot_rates exist ----------
    echo "=== Fixture: master data ===\n";
    $fake->shifts = [['ref_id' => 7101, 'code' => 'TXN_SHIFT', 'name_th' => 'กะทดสอบ', 'name_en' => 'Test Shift', 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'is_active' => true]];
    $fake->leaveTypes = [['ref_id' => 9101, 'category_code' => 'personal', 'code' => 'TXN_LEAVE', 'name_th' => 'ลาทดสอบ', 'name_en' => 'Test Leave', 'quota_amount' => 5, 'is_active' => true]];
    $fake->otRates = [['ref_id' => 10101, 'name_th' => 'OT ทดสอบ', 'name_en' => 'Test OT', 'scope_code' => 'weekday', 'multiplier_rate' => 1.5, 'is_active' => true]];
    $fake->employees = [[
        'ref_id' => 11101, 'employee_no' => 'TXN_EMP_1', 'name_th' => 'ทดสอบ', 'surname_th' => 'ธุรกรรม',
        'name_en' => 'Test', 'surname_en' => 'Transaction', 'date_of_birth' => '1995-01-01', 'gender' => 'male',
        'department_ref_id' => null, 'position_ref_id' => null, 'shift_ref_id' => 7101,
        'employment_date' => '2024-01-01', 'employment_status' => 'permanent',
        'personal_email' => 'txn1@test.local', 'mobile_no' => '0833333333', 'is_active' => true,
    ]];
    foreach (['shift', 'leave_type', 'ot_rate', 'employee'] as $type) {
        $r = $masterOrch->syncEntity($compId, $type, $adminUserId);
        checkTrue("fixture: {$type} master sync succeeds", $r['status']);
    }
    checkTrue('hasCompletedMasterDataSync() still false (department/position/holiday never run)', !$masterOrch->hasCompletedMasterDataSync($compId));

    // Run the remaining 3 (even with empty fetches) so the gate opens.
    foreach (['department', 'position', 'holiday'] as $type) {
        $masterOrch->syncEntity($compId, $type, $adminUserId);
    }
    checkTrue('hasCompletedMasterDataSync() now true', $masterOrch->hasCompletedMasterDataSync($compId));

    $empIdRow = $pdo->prepare("SELECT id FROM employees WHERE origami_ref_id = 11101 AND comp_id = :c");
    $empIdRow->execute([':c' => $compId]);
    $employeeId = (int)$empIdRow->fetchColumn();
    checkTrue('fixture employee resolved to a real internal id', $employeeId > 0);

    // ---------- AttendanceSyncer ----------
    echo "=== AttendanceSyncer ===\n";
    $fake->attendance = [
        ['ref_id' => 20001, 'employee_ref_id' => 11101, 'work_date' => '2026-01-05', 'shift_ref_id' => 7101, 'clock_in' => '2026-01-05 08:00:00', 'clock_out' => '2026-01-05 17:00:00', 'status' => 'present'],
        ['ref_id' => 20002, 'employee_ref_id' => 999999, 'work_date' => '2026-01-06', 'status' => 'present'], // unresolvable employee
    ];
    $ra = $txOrch->syncEntity($compId, 'attendance', $adminUserId, '2026-01-01', '2026-01-31');
    checkTrue('attendance batch completes', $ra['status']);
    check('1 success, 1 error', [$ra['success'], $ra['error']], [1, 1]);
    checkTrue('error mentions syncing employees first', strpos($ra['errors'][0]['message'], 'sync employees first') !== false);

    $attRow = $pdo->prepare("SELECT employee_id, shift_id, actual_work_minutes, data_source, sync_batch_id FROM attendance_records WHERE origami_ref_id = 20001 AND comp_id = :c");
    $attRow->execute([':c' => $compId]);
    $att = $attRow->fetch(PDO::FETCH_ASSOC);
    checkTrue('attendance record created', $att !== false);
    check('employee_id resolved via origami_ref_id (not the raw ref)', (int)$att['employee_id'], $employeeId);
    check('actual_work_minutes computed from clock in/out', (int)$att['actual_work_minutes'], 540);
    check('data_source is sync', $att['data_source'], 'sync');

    // Soft-delete by absence WITHIN the synced date range.
    $fake->attendance = [['ref_id' => 30001, 'employee_ref_id' => 11101, 'work_date' => '2026-01-10', 'status' => 'present']];
    $txOrch->syncEntity($compId, 'attendance', $adminUserId, '2026-01-01', '2026-01-31');
    $deletedAt = $pdo->query("SELECT deleted_at FROM attendance_records WHERE origami_ref_id = 20001")->fetchColumn();
    checkTrue('the Jan 5 record was soft-deleted (absent from a re-sync covering Jan)', $deletedAt !== null);

    // A record OUTSIDE the re-synced range must not be touched even if a totally different month is re-synced.
    $fake->attendance = [['ref_id' => 30001, 'employee_ref_id' => 11101, 'work_date' => '2026-01-10', 'status' => 'present']]; // still present, keep it alive
    $marchFetch = []; // empty -- but scoped to March, should not affect January rows at all
    $fake->attendance = $marchFetch;
    $txOrch->syncEntity($compId, 'attendance', $adminUserId, '2026-03-01', '2026-03-31');
    $janStillAlive = $pdo->query("SELECT deleted_at FROM attendance_records WHERE origami_ref_id = 30001")->fetchColumn();
    check('January record untouched by a March-scoped sync (even with an empty fetch)', $janStillAlive, null);

    // ---------- LeaveRequestSyncer ----------
    echo "=== LeaveRequestSyncer ===\n";
    $fake->leaveRequests = [
        ['ref_id' => 40001, 'employee_ref_id' => 11101, 'leave_type_ref_id' => 9101, 'start_date' => '2026-01-15', 'end_date' => '2026-01-15', 'total_days' => 1],
        ['ref_id' => 40002, 'employee_ref_id' => 11101, 'leave_type_ref_id' => 999999, 'start_date' => '2026-01-20', 'end_date' => '2026-01-20', 'total_days' => 1],
    ];
    $rl = $txOrch->syncEntity($compId, 'leave', $adminUserId, '2026-01-01', '2026-01-31');
    checkTrue('leave batch completes', $rl['status']);
    check('1 success, 1 error', [$rl['success'], $rl['error']], [1, 1]);

    $leaveRow = $pdo->prepare("SELECT employee_id, leave_type_id, status FROM leave_requests WHERE origami_ref_id = 40001 AND comp_id = :c");
    $leaveRow->execute([':c' => $compId]);
    $leave = $leaveRow->fetch(PDO::FETCH_ASSOC);
    checkTrue('leave request created', $leave !== false);
    check('status defaults to approved', $leave['status'], 'approved');

    // ---------- OvertimeRecordSyncer ----------
    echo "=== OvertimeRecordSyncer ===\n";
    $fake->overtimeRecords = [['ref_id' => 50001, 'employee_ref_id' => 11101, 'ot_date' => '2026-01-08', 'ot_rate_ref_id' => 10101, 'hours' => 2.5, 'amount' => 375]];
    $ro = $txOrch->syncEntity($compId, 'overtime', $adminUserId, '2026-01-01', '2026-01-31');
    checkTrue('overtime batch completes', $ro['status']);
    check('1 success', $ro['success'], 1);
    $otRow = $pdo->prepare("SELECT hours, amount FROM overtime_records WHERE origami_ref_id = 50001 AND comp_id = :c");
    $otRow->execute([':c' => $compId]);
    $ot = $otRow->fetch(PDO::FETCH_ASSOC);
    check('hours persisted', (float)$ot['hours'], 2.5);
    check('amount persisted', (float)$ot['amount'], 375.0);

    // ---------- syncAllTransactionData() ----------
    echo "=== syncAllTransactionData() ===\n";
    $all = $txOrch->syncAllTransactionData($compId, $adminUserId, '2026-01-01', '2026-01-31');
    check('3 entity types attempted', count($all), 3);
    checkTrue('all 3 succeeded', array_reduce($all, fn($c, $r) => $c && $r['status'], true));

    // ---------- SyncBatchModel scope columns ----------
    $batchModel = new SyncBatchModel($pdo);
    $attendanceBatches = $batchModel->list($compId, ['entity_type' => 'attendance']);
    checkTrue('attendance batch recorded scope dates', !empty($attendanceBatches[0]['scope_date_from']));

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
