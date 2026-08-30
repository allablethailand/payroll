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
// signature_drawing (2026-08-30 field batch) writes a REAL file to disk via file_put_contents() --
// a rolled-back DB transaction cannot undo that, same precedent already documented in
// tests/payroll_sync_test.php -- tracked here and cleaned up in the `finally` block below.
$filesWrittenDuringTest = [];
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
    // comp_id=1 is the real, live dev DB company -- it has genuinely been linked to Origami HR for
    // real (companies.ref_id set) since the Employee Sync picker's own live-testing sessions (see
    // feedback_dev_db_shared_state_test_fragility in project memory), so this test's own "starts
    // unlinked" assumption no longer holds by default. Temporarily cleared here, inside this test's
    // own rolled-back transaction only -- never committed, the real link is restored the instant
    // this script exits.
    $pdo->prepare("UPDATE companies SET ref_id = NULL WHERE id = :id")->execute([':id' => $compId]);
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
            'personal_email' => 'mds1@test.local', 'mobile_no' => '0811111111', 'tel_code' => '+95', 'is_active' => true,
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

    $empRow = $pdo->prepare("SELECT department_id, position_id, shift_id, employee_status, data_source, mobile_country_code FROM employees WHERE origami_ref_id = 11001 AND comp_id = :c");
    $empRow->execute([':c' => $compId]);
    $emp1 = $empRow->fetch(PDO::FETCH_ASSOC);
    checkTrue('employee 1 created', $emp1 !== false);
    check('department_id resolved to the internal id', (int)$emp1['department_id'], $engId);
    check('data_source is sync', $emp1['data_source'], 'sync');
    // 2026-08-30, real gap found and fixed while auditing candidates.php: `tel_code` was never read
    // at all despite `employees.mobile_country_code` existing for exactly this purpose.
    check('mobile_country_code taken from the sync payload tel_code (INSERT branch)', $emp1['mobile_country_code'] ?? null, '+95');

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

    // 2026-08-30, explicit request: "วันที่เริ่มประกันสังคมถ้าเป็นค่าว่างให้ Default เป็นวันที่เริ่มงานลงไปเลย"
    // -- same rule as PayrollSyncModel::applyOneEmployeeMasterFields()'s own version (see
    // tests/payroll_sync_test.php), applied here to EmployeeSyncer::upsertItem()'s UPDATE branch
    // (the write path behind the Employee page's "Sync"/"Re-Sync" button). Reuses the same
    // MDS_EMP_3 row already linked above -- sso_enrolled is turned on manually (this candidate API
    // carries no SSO field of its own at all) with sso_start_date left blank, then a second sync
    // call (candidate3b, a later employment_date) should fill it in.
    $pdo->prepare("UPDATE employees SET sso_enrolled = 1, sso_start_date = NULL WHERE id = :id")->execute([':id' => (int)$manualBefore['id']]);
    $candidate3b = $candidate3;
    $candidate3b['employment_date'] = '2024-03-15'; // a later date, distinct from candidate3's 2024-03-01, so the assertion can't pass by coincidence.
    $employeeSyncer->applyOne($compId, $candidate3b, $linkBatchId, $adminUserId);
    $ssoStmt = $pdo->prepare("SELECT sso_start_date, mobile_country_code FROM employees WHERE id = :id");
    $ssoStmt->execute([':id' => (int)$manualBefore['id']]);
    $afterCandidate3b = $ssoStmt->fetch(PDO::FETCH_ASSOC);
    check('sso_start_date defaulted to this sync call\'s employment_date since it was blank and the employee is sso_enrolled', $afterCandidate3b['sso_start_date'] ?? null, '2024-03-15');
    check('mobile_country_code defaults to +66 on the UPDATE branch too when tel_code is absent from the payload', $afterCandidate3b['mobile_country_code'] ?? null, '+66');

    // Never clobbers a value already on file.
    $pdo->prepare("UPDATE employees SET sso_start_date = '2019-06-15' WHERE id = :id")->execute([':id' => (int)$manualBefore['id']]);
    $employeeSyncer->applyOne($compId, $candidate3b, $linkBatchId, $adminUserId);
    $ssoStmt->execute([':id' => (int)$manualBefore['id']]);
    check('a manually-set sso_start_date survives a re-sync unchanged', $ssoStmt->fetchColumn(), '2019-06-15');

    // 2026-08-30, candidates.php's new field batch (branch/payroll_code/emp_tel/title/nickname/
    // nationality/religion/marital_status/idcard/deduct_sso/spouse/children/signature_drawing) --
    // see EmployeeSyncer::upsertItem()'s own 2026-08-30 docblock. photo_url is deliberately NOT
    // exercised here (a real HTTP download has no place in a repeatable, network-independent test)
    // -- downloadPhoto()'s own guard clauses (non-http(s) scheme rejected) are covered directly.
    echo "=== EmployeeSyncer: 2026-08-30 new field batch (branch/personal-profile/idcard/SSO/spouse/children/signature) ===\n";
    $candidate4 = [
        'ref_id' => 11004, 'employee_no' => 'MDS_EMP_4', 'name_th' => 'ทดสอบ', 'surname_th' => 'พนักงานสี่',
        'name_en' => 'Test', 'surname_en' => 'EmployeeFour', 'date_of_birth' => '1998-01-01', 'gender' => 'female',
        'department_ref_id' => null, 'position_ref_id' => null, 'shift_ref_id' => null,
        'branch_ref_id' => 12001, 'branch_name' => 'Head Office (Test)',
        'employment_date' => '2024-04-01', 'employment_status' => 'permanent',
        'personal_email' => 'mds4@test.local', 'mobile_no' => '0844444444', 'is_active' => true,
        'payroll_code' => 'PAYCODE-4', 'emp_tel' => '02-111-2222',
        'title' => 'ms', 'nickname' => 'Four', 'nationality' => 'Thai', 'religion' => 'Buddha', 'marital_status' => 'single',
        'idcard' => '1234567890124', 'idcard_issued' => '2018-01-01', 'idcard_expire' => '2028-01-01',
        'deduct_sso' => true,
        'spouse' => [
            'spouse_name' => 'Malee', 'spouse_lastname' => 'Jaidee', 'spouse_idcard' => '1112223334446',
            'father_name' => 'Somsak', 'father_lastname' => 'Testfour', 'father_idcard' => null,
            'mother_name' => '', 'mother_lastname' => '', 'mother_idcard' => null,
        ],
        'children' => [
            ['child_id' => 1, 'child_type' => 1, 'child_name' => 'Nong', 'child_lastname' => 'Testfour', 'child_idcard' => '9998887776664', 'child_birthday' => '2020-05-05', 'child_tax_allowance' => 1],
        ],
        'signature_drawing' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
    ];
    $batch4 = (new SyncBatchModel($pdo))->start($compId, 'employee', 'sync', 'manual', $adminUserId);
    $employeeSyncer->applyOne($compId, $candidate4, $batch4, $adminUserId);
    $emp4Stmt = $pdo->prepare("SELECT * FROM employees WHERE origami_ref_id = 11004 AND comp_id = :c");
    $emp4Stmt->execute([':c' => $compId]);
    $emp4 = $emp4Stmt->fetch(PDO::FETCH_ASSOC);
    checkTrue('employee 4 created (INSERT branch)', $emp4 !== false);

    $branch4 = $pdo->prepare("SELECT * FROM structure_branches WHERE origami_ref_id = 12001 AND comp_id = :c");
    $branch4->execute([':c' => $compId]);
    $branchRow4 = $branch4->fetch(PDO::FETCH_ASSOC);
    checkTrue('branch auto-created (structure_branches had no origami_ref_id support before this round)', $branchRow4 !== false);
    check('branch_name written to BOTH branch_name_th and branch_name_en (no separate TH/EN source on the wire)', [$branchRow4['branch_name_th'] ?? null, $branchRow4['branch_name_en'] ?? null], ['Head Office (Test)', 'Head Office (Test)']);
    check('employee 4 branch_id resolved to the auto-created branch', (int)($emp4['branch_id'] ?? 0), (int)$branchRow4['id']);
    check('branch data_source is sync', $branchRow4['data_source'] ?? null, 'sync');

    check('origami_payroll_code stored (reference-only, NOT the matching key)', $emp4['origami_payroll_code'] ?? null, 'PAYCODE-4');
    check('office_tel taken from emp_tel (distinct from mobile_no)', $emp4['office_tel'] ?? null, '02-111-2222');
    check('title normalized to ms', $emp4['title'] ?? null, 'ms');
    check('nickname written to both nickname_th and nickname_en', [$emp4['nickname_th'] ?? null, $emp4['nickname_en'] ?? null], ['Four', 'Four']);
    check('nationality resolved to the real master_nationalities CODE (TH), not the raw word "Thai" (real bug found+fixed this round)', $emp4['nationality'] ?? null, 'TH');
    check('religion stored as-sent (direct passthrough, same documented-risk precedent as the other Origami integration)', $emp4['religion'] ?? null, 'Buddha');
    check('marital_status normalized to single', $emp4['marital_status'] ?? null, 'single');
    check('id_card_issue_date stored', $emp4['id_card_issue_date'] ?? null, '2018-01-01');
    check('id_card_expire_date stored', $emp4['id_card_expire_date'] ?? null, '2028-01-01');
    check('sso_enrolled set from deduct_sso=true', (int)($emp4['sso_enrolled'] ?? -1), 1);
    check('sso_start_date defaulted to employment_date on INSERT since sso_enrolled and blank', $emp4['sso_start_date'] ?? null, '2024-04-01');
    $decIdCard4 = EncryptionService::decrypt($emp4['id_card_no'], (int)$emp4['key_version']);
    check('id_card_no decrypts to the synced value', $decIdCard4, '1234567890124');
    $decSso4 = EncryptionService::decrypt($emp4['sso_no'], (int)$emp4['key_version']);
    check('sso_no defaults to the same value as id_card_no (Thai law equivalence, same precedent as the other integration)', $decSso4, '1234567890124');
    check('has_spouse=1 (spouse_name present)', (int)($emp4['has_spouse'] ?? -1), 1);
    check('spouse_name is the combined name+lastname', $emp4['spouse_name'] ?? null, 'Malee Jaidee');
    $decSpouseIdCard4 = EncryptionService::decrypt($emp4['spouse_id_card_no'], (int)$emp4['key_version']);
    check('spouse_id_card_no decrypts to the synced value', $decSpouseIdCard4, '1112223334446');
    checkTrue('signature_path was written to a real file', !empty($emp4['signature_path']) && is_file(__DIR__ . '/../' . $emp4['signature_path']));
    if (!empty($emp4['signature_path'])) { $filesWrittenDuringTest[] = __DIR__ . '/../' . $emp4['signature_path']; }

    $parents4 = $pdo->prepare("SELECT * FROM employee_parents WHERE employee_id = :id");
    $parents4->execute([':id' => $emp4['id']]);
    $parentRows4 = $parents4->fetchAll(PDO::FETCH_ASSOC);
    check('exactly 1 parent row created (father only -- mother name was blank, correctly skipped)', count($parentRows4), 1);
    check('father name/relationship correct', [$parentRows4[0]['name'] ?? null, $parentRows4[0]['relationship'] ?? null], ['Somsak Testfour', 'father']);

    $children4 = $pdo->prepare("SELECT * FROM employee_dependents WHERE employee_id = :id");
    $children4->execute([':id' => $emp4['id']]);
    $childRows4 = $children4->fetchAll(PDO::FETCH_ASSOC);
    check('exactly 1 child row created', count($childRows4), 1);
    check('child name/dob/relationship correct', [$childRows4[0]['name'] ?? null, $childRows4[0]['date_of_birth'] ?? null, $childRows4[0]['relationship'] ?? null], ['Nong Testfour', '2020-05-05', 'child_legitimate']);
    $decChildIdCard4 = EncryptionService::decrypt($childRows4[0]['id_card_no'], (int)$childRows4[0]['key_version']);
    check('child id_card_no decrypts to the synced value', $decChildIdCard4, '9998887776664');

    echo "--- UPDATE branch: sparse re-sync (title/nickname/nationality/religion/marital_status/idcard all blank this time) must NOT erase what's already on file ---\n";
    $candidate4Sparse = $candidate4;
    unset($candidate4Sparse['title'], $candidate4Sparse['nickname'], $candidate4Sparse['nationality'], $candidate4Sparse['religion'], $candidate4Sparse['marital_status'], $candidate4Sparse['idcard'], $candidate4Sparse['idcard_issued'], $candidate4Sparse['idcard_expire']);
    $candidate4Sparse['emp_tel'] = ''; // also blank -- office_tel must survive too.
    $employeeSyncer->applyOne($compId, $candidate4Sparse, $batch4, $adminUserId);
    $emp4Stmt->execute([':c' => $compId]);
    $emp4AfterSparse = $emp4Stmt->fetch(PDO::FETCH_ASSOC);
    check('title survives a sparse re-sync unchanged', $emp4AfterSparse['title'] ?? null, 'ms');
    check('nickname survives a sparse re-sync unchanged', $emp4AfterSparse['nickname_th'] ?? null, 'Four');
    check('nationality survives a sparse re-sync unchanged', $emp4AfterSparse['nationality'] ?? null, 'TH');
    check('religion survives a sparse re-sync unchanged', $emp4AfterSparse['religion'] ?? null, 'Buddha');
    check('marital_status survives a sparse re-sync unchanged', $emp4AfterSparse['marital_status'] ?? null, 'single');
    check('office_tel survives a sparse re-sync unchanged (blank emp_tel this time)', $emp4AfterSparse['office_tel'] ?? null, '02-111-2222');
    $decIdCard4After = EncryptionService::decrypt($emp4AfterSparse['id_card_no'], (int)$emp4AfterSparse['key_version']);
    check('id_card_no survives a sparse re-sync unchanged', $decIdCard4After, '1234567890124');

    echo "--- Spouse/children whole-set replace: removing spouse/emptying children on a re-sync clears them (not a diff-and-patch) ---\n";
    $candidate4NoFamily = $candidate4;
    $candidate4NoFamily['spouse'] = null;
    $candidate4NoFamily['children'] = [];
    $employeeSyncer->applyOne($compId, $candidate4NoFamily, $batch4, $adminUserId);
    $emp4Stmt->execute([':c' => $compId]);
    $emp4AfterNoFamily = $emp4Stmt->fetch(PDO::FETCH_ASSOC);
    check('has_spouse cleared to 0', (int)($emp4AfterNoFamily['has_spouse'] ?? -1), 0);
    checkTrue('spouse_name cleared to NULL', $emp4AfterNoFamily['spouse_name'] === null);
    $parents4After = $pdo->prepare("SELECT COUNT(*) FROM employee_parents WHERE employee_id = :id");
    $parents4After->execute([':id' => $emp4['id']]);
    check('parent rows removed (whole-set replace, not diff-and-patch)', (int)$parents4After->fetchColumn(), 0);
    $children4After = $pdo->prepare("SELECT COUNT(*) FROM employee_dependents WHERE employee_id = :id");
    $children4After->execute([':id' => $emp4['id']]);
    check('child rows removed (whole-set replace, not diff-and-patch)', (int)$children4After->fetchColumn(), 0);

    echo "--- downloadPhoto() guard clauses (no real network call -- non-http(s)/blank input rejected) ---\n";
    $photoReflection = new ReflectionMethod(EmployeeSyncer::class, 'downloadPhoto');
    $photoReflection->setAccessible(true);
    checkTrue('downloadPhoto() rejects a non-http(s) scheme (e.g. file://)', $photoReflection->invoke($employeeSyncer, 'file:///etc/passwd') === null);
    checkTrue('downloadPhoto() rejects a blank URL', $photoReflection->invoke($employeeSyncer, '') === null);
    checkTrue('downloadPhoto() rejects null', $photoReflection->invoke($employeeSyncer, null) === null);

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
    foreach ($filesWrittenDuringTest as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
