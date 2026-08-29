<?php
/**
 * Lightweight verification script for the Origami HR master-data sync engine
 * (MasterDataSyncRegistry/MasterDataSyncOrchestrator/*Syncer/SyncBatchModel). Not PHPUnit -- see
 * tests/statutory_engine_test.php for why.
 *
 * Uses a FakeOrigamiSyncClient (defined below) instead of the real OrigamiSyncClient, which is a
 * pure stub with no real API to call -- this test exercises the upsert/dependency-order/
 * deactivation engine, not any real Origami integration (there isn't one yet).
 *
 * Runs against the real dev DB inside a transaction that is always rolled back.
 * Run with: php tests/master_data_sync_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/sync/OrigamiSyncClientInterface.php';
require_once __DIR__ . '/../app/services/sync/MasterDataSyncOrchestrator.php';
require_once __DIR__ . '/../app/models/SyncBatchModel.php';

class FakeOrigamiSyncClient implements OrigamiSyncClientInterface {
    public array $departments = [];
    public array $positions = [];
    public array $shifts = [];
    public array $holidays = [];
    public array $leaveTypes = [];
    public array $otRates = [];
    public array $employees = [];
    /** @var string[] entity types (matching fetch method suffix) that should throw when called */
    public array $throwFor = [];

    private function maybeThrow(string $key): void {
        if (in_array($key, $this->throwFor, true)) {
            throw new RuntimeException("Fake failure for {$key}");
        }
    }

    public function fetchDepartments(int $origamiCompanyId): array { $this->maybeThrow('departments'); return $this->departments; }
    public function fetchPositions(int $origamiCompanyId): array { $this->maybeThrow('positions'); return $this->positions; }
    public function fetchShifts(int $origamiCompanyId): array { $this->maybeThrow('shifts'); return $this->shifts; }
    public function fetchHolidays(int $origamiCompanyId): array { $this->maybeThrow('holidays'); return $this->holidays; }
    public function fetchLeaveTypes(int $origamiCompanyId): array { $this->maybeThrow('leaveTypes'); return $this->leaveTypes; }
    public function fetchOtRates(int $origamiCompanyId): array { $this->maybeThrow('otRates'); return $this->otRates; }
    public function fetchEmployees(int $origamiCompanyId): array { $this->maybeThrow('employees'); return $this->employees; }
    public function fetchAttendance(int $origamiCompanyId, string $dateFrom, string $dateTo): array { return []; }
    public function fetchLeaveRequests(int $origamiCompanyId, string $dateFrom, string $dateTo): array { return []; }
    public function fetchOvertimeRecords(int $origamiCompanyId, string $dateFrom, string $dateTo): array { return []; }
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
    $origamiCompanyId = 90001;
    $fake = new FakeOrigamiSyncClient();

    // ---------- Not linked to Origami yet ----------
    echo "=== Not linked ===\n";
    $orch = new MasterDataSyncOrchestrator($pdo, $fake);
    $notLinked = $orch->syncEntity($compId, 'department', $adminUserId);
    checkFalse('syncEntity fails when companies.ref_id is NULL', $notLinked['status']);

    $pdo->prepare("UPDATE companies SET ref_id = :ref WHERE id = :id")->execute([':ref' => $origamiCompanyId, ':id' => $compId]);

    // ---------- Unknown entity type ----------
    $unknown = $orch->syncEntity($compId, 'not_a_real_type', $adminUserId);
    checkFalse('unknown entity type fails cleanly', $unknown['status']);

    // ---------- Department: insert, update, deactivate-by-flag, deactivate-by-absence ----------
    echo "=== DepartmentSyncer ===\n";
    $fake->departments = [
        ['ref_id' => 5001, 'code' => 'MDS_ENG', 'name_th' => 'วิศวกรรม', 'name_en' => 'Engineering', 'is_active' => true],
        ['ref_id' => 5002, 'code' => 'MDS_SALE', 'name_th' => 'ขาย', 'name_en' => 'Sales', 'is_active' => true],
    ];
    $r1 = $orch->syncEntity($compId, 'department', $adminUserId);
    checkTrue('department sync succeeds', $r1['status']);
    check('2 total, 2 success, 0 error', [$r1['total'], $r1['success'], $r1['error']], [2, 2, 0]);

    $deptRow = $pdo->prepare("SELECT id, department_name_en, status, data_source, sync_batch_id FROM structure_departments WHERE origami_ref_id = 5001 AND comp_id = :c");
    $deptRow->execute([':c' => $compId]);
    $eng = $deptRow->fetch(PDO::FETCH_ASSOC);
    checkTrue('Engineering department created', $eng !== false);
    check('data_source is sync', $eng['data_source'], 'sync');
    check('sync_batch_id matches the batch', (int)$eng['sync_batch_id'], $r1['batch_id']);
    $engId = (int)$eng['id'];

    // Update: same ref_id, new name.
    $fake->departments[0]['name_en'] = 'Engineering & R&D';
    $r2 = $orch->syncEntity($compId, 'department', $adminUserId);
    $updated = $pdo->query("SELECT id, department_name_en FROM structure_departments WHERE id = {$engId}")->fetch(PDO::FETCH_ASSOC);
    check('update reuses the same row (same id)', (int)$updated['id'], $engId);
    check('name updated', $updated['department_name_en'], 'Engineering & R&D');

    // Deactivate by explicit is_active=false.
    $fake->departments[1]['is_active'] = false;
    $orch->syncEntity($compId, 'department', $adminUserId);
    $salesStatus = $pdo->query("SELECT status FROM structure_departments WHERE origami_ref_id = 5002 AND comp_id = {$compId}")->fetchColumn();
    check('Sales deactivated via is_active=false', $salesStatus, 'inactive');

    // Deactivate by absence: Engineering no longer appears in the feed at all.
    $fake->departments = [];
    $emptyFetchResult = $orch->syncEntity($compId, 'department', $adminUserId);
    $engStatusAfterEmpty = $pdo->query("SELECT status FROM structure_departments WHERE id = {$engId}")->fetchColumn();
    check('an EMPTY fetch does not deactivate previously-synced rows (safety net)', $engStatusAfterEmpty, 'active');

    $fake->departments = [
        ['ref_id' => 5003, 'code' => 'MDS_HR', 'name_th' => 'บุคคล', 'name_en' => 'HR', 'is_active' => true],
    ];
    $orch->syncEntity($compId, 'department', $adminUserId);
    $engStatusAfterMissing = $pdo->query("SELECT status FROM structure_departments WHERE id = {$engId}")->fetchColumn();
    check('Engineering deactivated once absent from a NON-empty fetch', $engStatusAfterMissing, 'inactive');

    // Restore Engineering as active for the employee-linking tests below.
    $fake->departments = [
        ['ref_id' => 5001, 'code' => 'MDS_ENG', 'name_th' => 'วิศวกรรม', 'name_en' => 'Engineering', 'is_active' => true],
        ['ref_id' => 5003, 'code' => 'MDS_HR', 'name_th' => 'บุคคล', 'name_en' => 'HR', 'is_active' => true],
    ];
    $orch->syncEntity($compId, 'department', $adminUserId);

    // ---------- Position ----------
    echo "=== PositionSyncer ===\n";
    $fake->positions = [['ref_id' => 6001, 'code' => 'MDS_DEV', 'name_th' => 'นักพัฒนา', 'name_en' => 'Developer', 'is_active' => true]];
    $rp = $orch->syncEntity($compId, 'position', $adminUserId);
    checkTrue('position sync succeeds', $rp['status']);
    check('1 success', $rp['success'], 1);

    // ---------- Shift ----------
    echo "=== ShiftSyncer ===\n";
    $fake->shifts = [['ref_id' => 7001, 'code' => 'MDS_DAY', 'name_th' => 'กะเช้า', 'name_en' => 'Day Shift', 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60, 'is_active' => true]];
    $rs = $orch->syncEntity($compId, 'shift', $adminUserId);
    checkTrue('shift sync succeeds', $rs['status']);
    check('1 success', $rs['success'], 1);

    // ---------- Holiday ----------
    echo "=== HolidaySyncer ===\n";
    $fake->holidays = [['ref_id' => 8001, 'name_th' => 'วันทดสอบ', 'name_en' => 'Test Day', 'holiday_date' => '2026-12-05', 'is_recurring' => false, 'is_active' => true]];
    $rh = $orch->syncEntity($compId, 'holiday', $adminUserId);
    checkTrue('holiday sync succeeds', $rh['status']);
    $holidayRow = $pdo->query("SELECT country_code, assignment_mode FROM holidays WHERE origami_ref_id = 8001 AND comp_id = {$compId}")->fetch(PDO::FETCH_ASSOC);
    checkTrue('country_code auto-derived (not empty)', !empty($holidayRow['country_code']));
    check('assignment_mode defaults to exclude (company-wide)', $holidayRow['assignment_mode'], 'exclude');

    // ---------- Leave Type ----------
    echo "=== LeaveTypeSyncer ===\n";
    $fake->leaveTypes = [
        ['ref_id' => 9001, 'category_code' => 'sick', 'code' => 'MDS_SICK', 'name_th' => 'ลาป่วยทดสอบ', 'name_en' => 'Test Sick', 'quota_amount' => 30, 'is_active' => true],
        ['ref_id' => 9002, 'category_code' => 'not_a_real_category', 'code' => 'MDS_BAD', 'name_th' => 'bad', 'name_en' => 'bad', 'is_active' => true],
    ];
    $rl = $orch->syncEntity($compId, 'leave_type', $adminUserId);
    checkTrue('leave_type batch completes despite one bad row', $rl['status']);
    check('1 success, 1 error', [$rl['success'], $rl['error']], [1, 1]);
    check('error message names the bad category', strpos($rl['errors'][0]['message'], 'not_a_real_category') !== false, true);

    // ---------- OT Rate ----------
    echo "=== OtRateSyncer ===\n";
    $fake->otRates = [['ref_id' => 10001, 'name_th' => 'OT ทดสอบ', 'name_en' => 'Test OT', 'scope_code' => 'weekday', 'multiplier_rate' => 1.5, 'is_active' => true]];
    $ro = $orch->syncEntity($compId, 'ot_rate', $adminUserId);
    checkTrue('ot_rate sync succeeds', $ro['status']);
    check('1 success', $ro['success'], 1);

    // ---------- Employee: FK resolution + dependency-order enforcement ----------
    echo "=== EmployeeSyncer ===\n";
    $fake->employees = [
        [
            'ref_id' => 11001, 'employee_no' => 'MDS_EMP_1', 'name_th' => 'ทดสอบ', 'surname_th' => 'พนักงานหนึ่ง',
            'name_en' => 'Test', 'surname_en' => 'EmployeeOne', 'date_of_birth' => '1995-01-01', 'gender' => 'male',
            'department_ref_id' => 5001, 'position_ref_id' => 6001, 'shift_ref_id' => 7001,
            'employment_date' => '2024-01-01', 'employment_status' => 'permanent',
            'personal_email' => 'mds1@test.local', 'mobile_no' => '0811111111', 'is_active' => true,
        ],
        [
            // References a department ref_id that was NEVER synced -- must fail this ROW only.
            'ref_id' => 11002, 'employee_no' => 'MDS_EMP_2', 'name_th' => 'ทดสอบ', 'surname_th' => 'พนักงานสอง',
            'name_en' => 'Test', 'surname_en' => 'EmployeeTwo', 'date_of_birth' => '1996-01-01', 'gender' => 'female',
            'department_ref_id' => 999999, 'position_ref_id' => null, 'shift_ref_id' => null,
            'employment_date' => '2024-02-01', 'employment_status' => 'permanent',
            'personal_email' => 'mds2@test.local', 'mobile_no' => '0822222222', 'is_active' => true,
        ],
    ];
    $re = $orch->syncEntity($compId, 'employee', $adminUserId);
    checkTrue('employee batch completes despite one bad row', $re['status']);
    check('1 success, 1 error', [$re['success'], $re['error']], [1, 1]);
    checkTrue('error mentions syncing the department first', strpos($re['errors'][0]['message'], 'sync it first') !== false);

    $empRow = $pdo->prepare("SELECT department_id, position_id, shift_id, employee_status, data_source FROM employees WHERE origami_ref_id = 11001 AND comp_id = :c");
    $empRow->execute([':c' => $compId]);
    $emp1 = $empRow->fetch(PDO::FETCH_ASSOC);
    checkTrue('employee 1 created', $emp1 !== false);
    check('department_id resolved to the internal id', (int)$emp1['department_id'], $engId);
    check('data_source is sync', $emp1['data_source'], 'sync');

    // 2026-08-28, real bug found and fixed: EmployeeSyncer::upsertItem()'s UPDATE branch never wrote
    // origami_ref_id -- so a manually-created employee matched via the employee_no fallback (not the
    // ref_id path) never actually got "linked" to Origami. Confirms the fix directly: clone a manual
    // row from employee 1's own just-created row (comp_id/name/dob/etc copied, origami_ref_id left
    // NULL, data_source forced to 'manual'), then sync a fresh candidate sharing that SAME
    // employee_no but a NEW ref_id -- the manual row must be UPDATED in place (not duplicated) and
    // must come out of it with origami_ref_id set to the new candidate's ref_id.
    // Clone employee 1's own just-created row wholesale (every NOT NULL column already has a valid
    // value since EmployeeSyncer's INSERT branch put it there) rather than hand-listing employees'
    // many NOT NULL columns (title/nationality/address/emergency-contact/employment_type/etc) here.
    $sourceStmt = $pdo->prepare("SELECT * FROM employees WHERE origami_ref_id = 11001 AND comp_id = :c");
    $sourceStmt->execute([':c' => $compId]);
    $cloneRow = $sourceStmt->fetch(PDO::FETCH_ASSOC);
    unset($cloneRow['id'], $cloneRow['created_at'], $cloneRow['updated_at']);
    $cloneRow['employee_no'] = 'MDS_EMP_3';
    $cloneRow['origami_ref_id'] = null;
    $cloneRow['data_source'] = 'manual';
    $cloneRow['personal_email'] = 'mds3@test.local';
    $cloneRow['mobile_no'] = '0833333333';
    $cloneRow['sync_batch_id'] = null;
    $cols = array_keys($cloneRow);
    $insertSql = "INSERT INTO employees (`" . implode('`,`', $cols) . "`) VALUES (:" . implode(',:', $cols) . ")";
    $pdo->prepare($insertSql)->execute($cloneRow);
    $manualStmt = $pdo->prepare("SELECT id, origami_ref_id FROM employees WHERE employee_no = 'MDS_EMP_3' AND comp_id = :c");
    $manualStmt->execute([':c' => $compId]);
    $manualBefore = $manualStmt->fetch(PDO::FETCH_ASSOC);
    checkTrue('manual employee fixture created with no origami_ref_id', $manualBefore !== false && $manualBefore['origami_ref_id'] === null);

    // NOTE: exercised via EmployeeSyncer::applyOne() directly, NOT $orch->syncEntity() -- the bulk
    // sync() path (what syncEntity() actually calls) only ever matches by findByRefId(), with no
    // employee_no fallback at all (a separate, pre-existing gap, out of scope here). applyOne() is
    // the one actually used by the production code this fix targets: EmployeeSyncModel::resyncOne()
    // (Employee Detail's "Re-Sync"/"Sync from Origami" button, and the same List-page per-row
    // action), and EmployeeSyncModel::apply() (the List page's bulk picker).
    $linkBatchId = (new SyncBatchModel($pdo))->start($compId, 'employee', 'sync', 'manual', $adminUserId);
    $candidate3 = [
        'ref_id' => 11003, 'employee_no' => 'MDS_EMP_3', 'name_th' => 'ทดสอบ', 'surname_th' => 'พนักงานสาม',
        'name_en' => 'Test', 'surname_en' => 'EmployeeThree', 'date_of_birth' => '1997-01-01', 'gender' => 'male',
        'department_ref_id' => null, 'position_ref_id' => null, 'shift_ref_id' => null,
        'employment_date' => '2024-03-01', 'employment_status' => 'permanent',
        'personal_email' => 'mds3@test.local', 'mobile_no' => '0833333333', 'is_active' => true,
    ];
    $employeeSyncer = new EmployeeSyncer($pdo);
    $linkResult = $employeeSyncer->applyOne($compId, $candidate3, $linkBatchId, $adminUserId);
    check('applyOne() reports "updated" (matched the manual row, not a fresh insert)', $linkResult['action'], 'updated');
    $manualStmt->execute([':c' => $compId]);
    $manualRows = $manualStmt->fetchAll(PDO::FETCH_ASSOC);
    check('exactly one row for MDS_EMP_3 after linking (matched+updated, not duplicated)', count($manualRows), 1);
    check('manually-created employee is now linked via employee_no fallback', (int)$manualRows[0]['origami_ref_id'], 11003);
    check('same local id preserved across the link (updated in place, not re-inserted)', (int)$manualRows[0]['id'], (int)$manualBefore['id']);

    // Deactivation: employee 1 removed from a non-empty feed -> employee_status becomes resigned.
    $fake->employees = [$fake->employees[1]]; // keep only employee 2 (which still errors on department, so total=1, success=0)
    // Give employee 2 a resolvable department this time so the feed is non-empty AND has a success.
    $fake->employees[0]['department_ref_id'] = null;
    $orch->syncEntity($compId, 'employee', $adminUserId);
    $emp1After = $pdo->prepare("SELECT employee_status FROM employees WHERE origami_ref_id = 11001 AND comp_id = :c");
    $emp1After->execute([':c' => $compId]);
    check('employee 1 marked resigned once absent from a non-empty feed', $emp1After->fetchColumn(), 'resigned');

    // ---------- Whole-batch failure does not stop the rest of syncAllMasterData() ----------
    echo "=== syncAllMasterData() ===\n";
    $fake->throwFor = ['shifts'];
    $all = $orch->syncAllMasterData($compId, $adminUserId);
    check('7 entity types attempted', count($all), 7);
    $shiftResult = array_values(array_filter($all, fn($r) => $r['entity_type'] === 'shift'))[0];
    checkFalse('shift batch failed (client threw)', $shiftResult['status']);
    $employeeResult = array_values(array_filter($all, fn($r) => $r['entity_type'] === 'employee'))[0];
    checkTrue('employee sync still ran after shift failed', $employeeResult['status'] || isset($employeeResult['batch_id']));
    $fake->throwFor = [];

    // ---------- SyncBatchModel ----------
    echo "=== SyncBatchModel ===\n";
    $batchModel = new SyncBatchModel($pdo);
    $lastTimes = $batchModel->lastSyncTimes($compId);
    checkTrue('lastSyncTimes has an entry for department', !empty($lastTimes['department']));
    // shift has an EARLIER completed batch (before throwFor was set) plus a LATER failed one --
    // lastSyncTimes only tracks completed batches, so it still shows the earlier success; the
    // failed attempt itself is only visible via list().
    $failedShiftBatches = $batchModel->list($compId, ['entity_type' => 'shift', 'status' => 'failed']);
    checkTrue('the failed shift batch is recorded separately via list()', count($failedShiftBatches) > 0);

    $batchList = $batchModel->list($compId, ['entity_type' => 'department']);
    checkTrue('list() filters by entity_type', count($batchList) > 0);
    checkTrue('every listed row is department', array_reduce($batchList, fn($carry, $r) => $carry && $r['entity_type'] === 'department', true));

    checkTrue('hasCompletedMasterDataSync() is true once every type has completed at least once', $orch->hasCompletedMasterDataSync($compId));

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
