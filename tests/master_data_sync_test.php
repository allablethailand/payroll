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
require_once __DIR__ . '/../app/models/EmployeeModel.php';
// 2026-09-02, added for the "EmployeeSyncer: 2026-09-02 new field batch" section's own
// StatutoryCalculationEngine per-employee SSO rate override assertions below.
require_once __DIR__ . '/../app/models/TaxStatutoryModel.php';
require_once __DIR__ . '/../app/models/CompanyStatutorySettingModel.php';
require_once __DIR__ . '/../app/services/StatutoryCalculationEngine.php';

class FakeOrigamiSyncClient implements OrigamiSyncClientInterface {
    public array $departments = [];
    public array $positions = [];
    public array $shifts = [];
    public array $branches = [];
    public array $teams = [];
    public array $holidays = [];
    public array $leaveTypes = [];
    public array $otRates = [];
    public array $employees = [];
    public ?array $company = null;
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
    public function fetchBranches(int $origamiCompanyId): array { $this->maybeThrow('branches'); return $this->branches; }
    public function fetchTeams(int $origamiCompanyId): array { $this->maybeThrow('teams'); return $this->teams; }
    public function fetchCompany(int $origamiCompanyId): ?array { $this->maybeThrow('company'); return $this->company; }
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

    // ---------- Branch (2026-09-02, real Origami endpoint confirmed live) ----------
    echo "=== BranchSyncer ===\n";
    $fake->branches = [
        ['ref_id' => 7501, 'code' => 'MDS_HQ', 'name' => 'Head Office (Test)', 'is_default' => true, 'is_active' => true],
    ];
    $rbr = $orch->syncEntity($compId, 'branch', $adminUserId);
    checkTrue('branch sync succeeds', $rbr['status']);
    check('1 success', $rbr['success'], 1);
    $branchRow = $pdo->query("SELECT branch_name_th, branch_name_en, is_default, data_source FROM structure_branches WHERE origami_ref_id = 7501 AND comp_id = {$compId}")->fetch(PDO::FETCH_ASSOC);
    checkTrue('branch created', $branchRow !== false);
    check('single name mirrored into both branch_name_th/en (no bilingual source)', [$branchRow['branch_name_th'] ?? null, $branchRow['branch_name_en'] ?? null], ['Head Office (Test)', 'Head Office (Test)']);
    check('is_default carried through', (int)($branchRow['is_default'] ?? -1), 1);
    check('data_source is sync', $branchRow['data_source'] ?? null, 'sync');

    // ---------- Team (2026-09-02, real Origami endpoint confirmed live -- previously deliberately excluded, see TeamSyncer's own docblock) ----------
    echo "=== TeamSyncer ===\n";
    $fake->teams = [
        ['ref_id' => 7601, 'name' => 'Client Alpha Team (Test)', 'is_active' => true],
    ];
    $rtm = $orch->syncEntity($compId, 'team', $adminUserId);
    checkTrue('team sync succeeds', $rtm['status']);
    check('1 success', $rtm['success'], 1);
    $teamRow = $pdo->query("SELECT team_code, team_name_th, team_name_en, data_source FROM structure_teams WHERE origami_ref_id = 7601 AND comp_id = {$compId}")->fetch(PDO::FETCH_ASSOC);
    checkTrue('team created', $teamRow !== false);
    checkTrue('team_code auto-generated (Origami has no code field at all for teams)', !empty($teamRow['team_code']));
    check('single name mirrored into both team_name_th/en', [$teamRow['team_name_th'] ?? null, $teamRow['team_name_en'] ?? null], ['Client Alpha Team (Test)', 'Client Alpha Team (Test)']);
    check('data_source is sync', $teamRow['data_source'] ?? null, 'sync');
    // Re-sync with a NEW name, same ref_id -- must update the SAME row, not duplicate.
    $fake->teams[0]['name'] = 'Client Alpha Team RENAMED (Test)';
    $orch->syncEntity($compId, 'team', $adminUserId);
    $teamCountAfterRename = (int)$pdo->query("SELECT COUNT(*) FROM structure_teams WHERE origami_ref_id = 7601 AND comp_id = {$compId}")->fetchColumn();
    check('re-sync updates the same row (still exactly 1), not a duplicate', $teamCountAfterRename, 1);
    $teamNameAfterRename = $pdo->query("SELECT team_name_en FROM structure_teams WHERE origami_ref_id = 7601 AND comp_id = {$compId}")->fetchColumn();
    check('name updated on re-sync', $teamNameAfterRename, 'Client Alpha Team RENAMED (Test)');

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
    // 2026-09-02, real bug found (via a follow-up reply from the Origami team) and fixed: religion
    // used to be a raw passthrough here (unlike nationality right above, which was already
    // correctly resolved) -- now resolved via resolveReligionCode(), same alias map as
    // PayrollSyncModel's own fix on the OTHER Origami integration. 'Buddha' is Origami's REAL wire
    // value (per PAYROLL_SYNC_API.md), resolved via the alias map (not a direct name match) to this
    // app's own 'BUD' code.
    check('religion resolved via the alias map from Origami\'s real wire value ("Buddha") to this app\'s own religion_code ("BUD"), not stored raw', $emp4['religion'] ?? null, 'BUD');
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
    check('religion survives a sparse re-sync unchanged (still resolved code "BUD", not re-derived or blanked)', $emp4AfterSparse['religion'] ?? null, 'BUD');
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

    echo "--- 2026-08-30, real production bug: title/marital_status/nationality can arrive as a raw INT (numeric code), not a string -- must not TypeError under strict_types ---\n";
    // Reported live: "EmployeeSyncer::normalizeMaritalStatus(): Argument #1 ($raw) must be of type
    // ?string, int given" -- Origami's own field notes for this legacy data already say title/
    // gender/marital_status are "sometimes human-readable text, sometimes an internal numeric code"
    // (see PayrollSyncModel's identical docblock on the SAME field family, a different Origami
    // integration hitting the same source data), but the numeric case arrives as a genuine JSON int,
    // not a numeric string -- the ?string param type rejected it outright under strict_types before
    // the "don't guess, leave unmapped" logic in the function body ever got a chance to run.
    $candidate5 = $candidate4;
    $candidate5['ref_id'] = 11005;
    $candidate5['employee_no'] = 'MDS_EMP_5';
    $candidate5['branch_ref_id'] = null;
    $candidate5['title'] = 2;
    $candidate5['marital_status'] = 1;
    $candidate5['nationality'] = 99;
    $candidate5['spouse'] = null;
    $candidate5['children'] = [];
    $employeeSyncer->applyOne($compId, $candidate5, $batch4, $adminUserId);
    $emp5Stmt = $pdo->prepare("SELECT * FROM employees WHERE origami_ref_id = 11005 AND comp_id = :c");
    $emp5Stmt->execute([':c' => $compId]);
    $emp5 = $emp5Stmt->fetch(PDO::FETCH_ASSOC);
    checkTrue('employee 5 created despite int-typed title/marital_status/nationality (no TypeError thrown)', $emp5 !== false);
    // title/nationality are NOT NULL columns -- on INSERT (unlike UPDATE), an unresolved value falls
    // back to a safe default ('mr'/'TH') rather than staying unmapped, same as an unresolved string
    // value already did before this fix (see upsertItem()'s own docblock) -- only marital_status is
    // nullable, so IT is the one that stays genuinely unmapped for a code with no known meaning.
    check('unrecognized numeric title code falls back to the NOT NULL default (mr), not guessed at', $emp5['title'] ?? null, 'mr');
    checkTrue('unrecognized numeric marital_status code left unmapped (null), not guessed', ($emp5['marital_status'] ?? null) === null);
    check('unrecognized numeric nationality code falls back to the NOT NULL default (TH), not guessed at', $emp5['nationality'] ?? null, 'TH');

    echo "--- downloadPhoto() guard clauses (no real network call -- non-http(s)/blank input rejected) ---\n";
    $photoReflection = new ReflectionMethod(EmployeeSyncer::class, 'downloadPhoto');
    $photoReflection->setAccessible(true);
    checkTrue('downloadPhoto() rejects a non-http(s) scheme (e.g. file://)', $photoReflection->invoke($employeeSyncer, 'file:///etc/passwd') === null);
    checkTrue('downloadPhoto() rejects a blank URL', $photoReflection->invoke($employeeSyncer, '') === null);
    checkTrue('downloadPhoto() rejects null', $photoReflection->invoke($employeeSyncer, null) === null);

    echo "=== EmployeeSyncer: 2026-09-02 new field batch (employment_type/SSO rate override/address/foreign worker/emergency contact) ===\n";
    $candidate6 = [
        'ref_id' => 11006, 'employee_no' => 'MDS_EMP_6', 'name_th' => 'ทดสอบ', 'surname_th' => 'พนักงานหก',
        'name_en' => 'Test', 'surname_en' => 'EmployeeSix', 'date_of_birth' => '1995-06-15', 'gender' => 'male',
        'department_ref_id' => null, 'position_ref_id' => null, 'shift_ref_id' => null, 'branch_ref_id' => null,
        'employment_date' => '2024-05-01', 'employment_status' => 'permanent',
        'personal_email' => 'mds6@test.local', 'mobile_no' => '0866666666', 'is_active' => true,
        'employment_type_ref_id' => 22001, 'employment_type_code' => 'CONTRACT', 'employment_type_name' => 'Contract Employee (Test)',
        'sso_employee_rate_percent' => '3.00', 'sso_company_rate_percent' => '2.50',
        'current_address' => [
            'no' => '99/9', 'moo' => '5', 'building' => null, 'soi' => 'Sukhumvit 21', 'road' => 'Sukhumvit',
            'sub_district' => 'Khlong Toei Nuea', 'district' => 'Watthana', 'province' => 'Bangkok', 'postcode' => '10110',
        ],
        'house_registration_same_as_current' => false,
        'house_registration_address' => [
            'no' => '10', 'moo' => null, 'building' => null, 'soi' => null, 'road' => 'Ratchadamnoen',
            'sub_district' => 'Phra Nakhon', 'district' => 'Phra Nakhon', 'province' => 'Bangkok', 'postcode' => '10200',
        ],
        'is_foreign_worker' => true,
        'passport' => ['no' => 'X1234567', 'issued_place' => 'Yangon', 'issue_date' => '2020-01-01', 'expire_date' => '2030-01-01'],
        'work_permit' => ['no' => 'WP-9988', 'issued_place' => 'Bangkok', 'issue_date' => '2024-05-01', 'expire_date' => '2025-05-01'],
        // 2026-09-02, same-day follow-up: visa/foreign_worker_info now have a schema home too --
        // field shapes confirmed directly from Origami's own candidates.php source.
        'visa' => ['type_code' => '3', 'type_name' => 'Non-Immigrant Visa', 'no' => 'V-555', 'issued_place' => 'Bangkok', 'issue_date' => '2024-04-01', 'expire_date' => '2025-04-01'],
        'foreign_worker_info' => [
            'recruitment_agency' => 'ABC Recruitment', 'arrival_date' => '2024-04-15', 'due_date' => '2025-04-15',
            'arrival_card_no' => 'AC-777', 'arrival_by_vehicle' => 'Flight TG123', 'address' => '123 Home St',
            'soi' => 'Home Soi', 'province' => 'Yangon', 'district' => 'Home District', 'sub_district' => 'Home Sub',
            'tel_code' => '+95', 'tel' => '912345678',
        ],
        'emergency_contact' => ['firstname' => 'Somying', 'lastname' => 'Testsix', 'relationship_name' => 'Sister', 'tel' => '0899999999'],
    ];
    $batch6 = (new SyncBatchModel($pdo))->start($compId, 'employee', 'sync', 'manual', $adminUserId);
    $employeeSyncer->applyOne($compId, $candidate6, $batch6, $adminUserId);
    $emp6Stmt = $pdo->prepare("SELECT * FROM employees WHERE origami_ref_id = 11006 AND comp_id = :c");
    $emp6Stmt->execute([':c' => $compId]);
    $emp6 = $emp6Stmt->fetch(PDO::FETCH_ASSOC);
    checkTrue('employee 6 created (INSERT branch)', $emp6 !== false);

    $empType6 = $pdo->prepare("SELECT * FROM structure_employment_types WHERE origami_ref_id = 22001 AND comp_id = :c");
    $empType6->execute([':c' => $compId]);
    $empTypeRow6 = $empType6->fetch(PDO::FETCH_ASSOC);
    checkTrue('employment type auto-created (structure_employment_types)', $empTypeRow6 !== false);
    check('employment_type_code stored', $empTypeRow6['employment_type_code'] ?? null, 'CONTRACT');
    check('employment_type_name written to both _th and _en (no separate TH/EN source on the wire)', [$empTypeRow6['employment_type_name_th'] ?? null, $empTypeRow6['employment_type_name_en'] ?? null], ['Contract Employee (Test)', 'Contract Employee (Test)']);
    check('employee 6 employment_type_id resolved to the auto-created row', (int)($emp6['employment_type_id'] ?? 0), (int)$empTypeRow6['id']);
    check('employees.employment_type (fixed enum) unaffected by the new employment_type_id link -- still the pre-existing hardcoded INSERT default', $emp6['employment_type'] ?? null, 'full_time');

    check('sso_contribution_rate (employee-side override) stored as sent', $emp6['sso_contribution_rate'] ?? null, '3.00');
    check('sso_employer_contribution_rate (employer-side override, new column) stored as sent', $emp6['sso_employer_contribution_rate'] ?? null, '2.50');

    check('address_line_1_contact concatenated from current_address block', $emp6['address_line_1_contact'] ?? null, '99/9 หมู่ 5 ซอยSukhumvit 21 ถนนSukhumvit');
    check('address_line_2_contact concatenated (sub-district/district/province/postcode)', $emp6['address_line_2_contact'] ?? null, 'Khlong Toei Nuea Watthana Bangkok 10110');
    check('address_line_1_register concatenated from house_registration_address block (same_as_current=false)', $emp6['address_line_1_register'] ?? null, '10 ถนนRatchadamnoen');
    check('address_line_2_register concatenated', $emp6['address_line_2_register'] ?? null, 'Phra Nakhon Phra Nakhon Bangkok 10200');
    check('use_register_address is 0 (house_registration_same_as_current was false)', (int)($emp6['use_register_address'] ?? -1), 0);
    checkTrue('master_address_id_contact left null (no reliable text-match resolution attempted, by design)', ($emp6['master_address_id_contact'] ?? null) === null);
    checkTrue('master_address_id_register left null (same reason)', ($emp6['master_address_id_register'] ?? null) === null);

    check('employee_type set to foreigner from is_foreign_worker=true', $emp6['employee_type'] ?? null, 'foreigner');
    check('passport_no stored', $emp6['passport_no'] ?? null, 'X1234567');
    check('passport_expire_date stored', $emp6['passport_expire_date'] ?? null, '2030-01-01');
    check('work_permit_no stored', $emp6['work_permit_no'] ?? null, 'WP-9988');
    check('date_work_permit_issue stored', $emp6['date_work_permit_issue'] ?? null, '2024-05-01');
    check('date_work_permit_expire stored', $emp6['date_work_permit_expire'] ?? null, '2025-05-01');

    echo "--- 2026-09-02, same-day follow-up: visa details + foreign_worker_info ---\n";
    check('passport_issued_place stored', $emp6['passport_issued_place'] ?? null, 'Yangon');
    check('passport_issue_date stored', $emp6['passport_issue_date'] ?? null, '2020-01-01');
    check('work_permit_issued_place stored', $emp6['work_permit_issued_place'] ?? null, 'Bangkok');
    check('visa_type stores the RESOLVED type_name, not the raw type_code', $emp6['visa_type'] ?? null, 'Non-Immigrant Visa');
    check('visa_no stored', $emp6['visa_no'] ?? null, 'V-555');
    check('visa_issued_place stored', $emp6['visa_issued_place'] ?? null, 'Bangkok');
    check('visa_issue_date stored', $emp6['visa_issue_date'] ?? null, '2024-04-01');
    check('date_visa_expire stored', $emp6['date_visa_expire'] ?? null, '2025-04-01');

    $fwd6Stmt = $pdo->prepare("SELECT * FROM employee_foreign_worker_details WHERE employee_id = :id");
    $fwd6Stmt->execute([':id' => $emp6['id']]);
    $fwd6 = $fwd6Stmt->fetch(PDO::FETCH_ASSOC);
    checkTrue('employee_foreign_worker_details row created', $fwd6 !== false);
    check('recruitment_agency stored', $fwd6['recruitment_agency'] ?? null, 'ABC Recruitment');
    check('arrival_date stored', $fwd6['arrival_date'] ?? null, '2024-04-15');
    check('due_date stored', $fwd6['due_date'] ?? null, '2025-04-15');
    check('arrival_card_no stored', $fwd6['arrival_card_no'] ?? null, 'AC-777');
    check('arrival_by_vehicle stored', $fwd6['arrival_by_vehicle'] ?? null, 'Flight TG123');
    check('address stored', $fwd6['address'] ?? null, '123 Home St');
    check('soi stored', $fwd6['soi'] ?? null, 'Home Soi');
    check('province stored', $fwd6['province'] ?? null, 'Yangon');
    check('district stored', $fwd6['district'] ?? null, 'Home District');
    check('sub_district stored', $fwd6['sub_district'] ?? null, 'Home Sub');
    check('tel_code stored', $fwd6['tel_code'] ?? null, '+95');
    check('tel stored', $fwd6['tel'] ?? null, '912345678');

    check('emergency_name stored', $emp6['emergency_name'] ?? null, 'Somying');
    check('emergency_surname stored', $emp6['emergency_surname'] ?? null, 'Testsix');
    check('emergency_relationship stored (English relationship_name preferred)', $emp6['emergency_relationship'] ?? null, 'Sister');
    check('emergency_mobile stored', $emp6['emergency_mobile'] ?? null, '0899999999');

    echo "--- house_registration_same_as_current=true: register address mirrors current address, not the (possibly stale) house_regis_* block ---\n";
    $candidate6b = $candidate6;
    $candidate6b['ref_id'] = 11007;
    $candidate6b['employee_no'] = 'MDS_EMP_7';
    $candidate6b['house_registration_same_as_current'] = true;
    // Deliberately stale/different house_registration_address -- must be IGNORED when same_as_current=true.
    $candidate6b['house_registration_address'] = ['no' => 'STALE', 'sub_district' => 'ShouldNotAppear'];
    $employeeSyncer->applyOne($compId, $candidate6b, $batch6, $adminUserId);
    $emp7Stmt = $pdo->prepare("SELECT * FROM employees WHERE origami_ref_id = 11007 AND comp_id = :c");
    $emp7Stmt->execute([':c' => $compId]);
    $emp7 = $emp7Stmt->fetch(PDO::FETCH_ASSOC);
    check('address_line_1_register mirrors current address (same_as_current=true), not the stale house_regis_* block', $emp7['address_line_1_register'] ?? null, '99/9 หมู่ 5 ซอยSukhumvit 21 ถนนSukhumvit');
    check('use_register_address is 1', (int)($emp7['use_register_address'] ?? -1), 1);

    echo "--- UPDATE branch: sparse re-sync (emergency_contact/current_address/house_registration_address/passport/work_permit/visa/foreign_worker_info all absent this time) must NOT erase what's already on file ---\n";
    $candidate6Sparse = $candidate6;
    unset($candidate6Sparse['emergency_contact'], $candidate6Sparse['current_address'], $candidate6Sparse['house_registration_address'], $candidate6Sparse['passport'], $candidate6Sparse['work_permit'], $candidate6Sparse['visa'], $candidate6Sparse['foreign_worker_info']);
    $candidate6Sparse['house_registration_same_as_current'] = false; // present, but current/house_reg blocks are absent -> both addressLinesFromBlock() calls return null.
    $employeeSyncer->applyOne($compId, $candidate6Sparse, $batch6, $adminUserId);
    $emp6Stmt->execute([':c' => $compId]);
    $emp6AfterSparse = $emp6Stmt->fetch(PDO::FETCH_ASSOC);
    check('emergency_name survives a sparse re-sync unchanged', $emp6AfterSparse['emergency_name'] ?? null, 'Somying');
    check('address_line_1_contact survives a sparse re-sync unchanged', $emp6AfterSparse['address_line_1_contact'] ?? null, '99/9 หมู่ 5 ซอยSukhumvit 21 ถนนSukhumvit');
    check('address_line_1_register survives a sparse re-sync unchanged', $emp6AfterSparse['address_line_1_register'] ?? null, '10 ถนนRatchadamnoen');
    check('passport_no survives a sparse re-sync unchanged (foreignWorkerFieldsFromItem() null-guarded per sub-field)', $emp6AfterSparse['passport_no'] ?? null, 'X1234567');
    check('work_permit_no survives a sparse re-sync unchanged', $emp6AfterSparse['work_permit_no'] ?? null, 'WP-9988');
    check('visa_no survives a sparse re-sync unchanged', $emp6AfterSparse['visa_no'] ?? null, 'V-555');
    $fwd6AfterSparseStmt = $pdo->prepare("SELECT * FROM employee_foreign_worker_details WHERE employee_id = :id");
    $fwd6AfterSparseStmt->execute([':id' => $emp6AfterSparse['id']]);
    $fwd6AfterSparse = $fwd6AfterSparseStmt->fetch(PDO::FETCH_ASSOC);
    check('employee_foreign_worker_details row survives a sparse re-sync unchanged (foreignWorkerInfoFromItem() returns null when the whole block is absent, so save() is never even called)', $fwd6AfterSparse['recruitment_agency'] ?? null, 'ABC Recruitment');

    echo "=== EmployeeSyncer: 2026-09-03, document scan URLs (documentScansFromItem/downloadDocumentScan/syncDocumentScans) ===\n";
    // documentScansFromItem() is pure data-shaping (no network) -- exercised directly via reflection.
    $scansReflection = new ReflectionMethod(EmployeeSyncer::class, 'documentScansFromItem');
    $scansReflection->setAccessible(true);
    $scansFull = $scansReflection->invoke($employeeSyncer, [
        'passport' => ['document_url' => 'https://origami.test/files/passport_11006.pdf', 'document_name' => 'passport.pdf'],
        'visa' => ['document_url' => 'https://origami.test/files/visa_11006.pdf', 'document_name' => 'visa.pdf'],
        'work_permit' => ['document_url' => 'https://origami.test/files/wp_11006.pdf', 'document_name' => 'work_permit.pdf'],
    ]);
    check('documentScansFromItem() returns all 3 scans when all 3 blocks carry a document_url', count($scansFull), 3);
    $scanByType = [];
    foreach ($scansFull as $s) { $scanByType[$s['document_type']] = $s; }
    check('passport block maps to document_type=passport_copy with the real URL/name', [$scanByType['passport_copy']['url'] ?? null, $scanByType['passport_copy']['name'] ?? null], ['https://origami.test/files/passport_11006.pdf', 'passport.pdf']);
    check('visa block maps to document_type=visa_copy', $scanByType['visa_copy']['url'] ?? null, 'https://origami.test/files/visa_11006.pdf');
    check('work_permit block REUSES the existing work_permit_copy type (no redundant 4th "scan" variant)', $scanByType['work_permit_copy']['url'] ?? null, 'https://origami.test/files/wp_11006.pdf');

    $scansPartial = $scansReflection->invoke($employeeSyncer, [
        'passport' => ['document_url' => '', 'document_name' => 'passport.pdf'], // blank url -- skipped
        'visa' => ['document_url' => 'not-a-real-url'], // non-http(s) scheme -- skipped
        // work_permit block entirely absent -- skipped
    ]);
    check('documentScansFromItem() skips blank/non-http(s)/absent blocks entirely, not guessed at', count($scansPartial), 0);

    $scansNoName = $scansReflection->invoke($employeeSyncer, ['passport' => ['document_url' => 'https://origami.test/files/x.pdf']]);
    check('documentScansFromItem() falls back to a synthetic filename when Origami sends no document_name', $scansNoName[0]['name'] ?? null, 'passport_copy.pdf');

    echo "--- downloadDocumentScan() guard clauses (no real network call -- non-http(s)/blank input rejected, same convention as downloadPhoto()'s own test above) ---\n";
    $docDlReflection = new ReflectionMethod(EmployeeSyncer::class, 'downloadDocumentScan');
    $docDlReflection->setAccessible(true);
    checkTrue('downloadDocumentScan() rejects a non-http(s) scheme', $docDlReflection->invoke($employeeSyncer, 'file:///etc/passwd') === null);
    checkTrue('downloadDocumentScan() rejects a blank URL', $docDlReflection->invoke($employeeSyncer, '') === null);
    checkTrue('downloadDocumentScan() rejects null', $docDlReflection->invoke($employeeSyncer, null) === null);

    echo "--- syncDocumentScans(): idempotency + failure-safety, exercised without any real network call ---\n";
    $syncScansReflection = new ReflectionMethod(EmployeeSyncer::class, 'syncDocumentScans');
    $syncScansReflection->setAccessible(true);
    $docCountStmt = $pdo->prepare("SELECT COUNT(*) FROM employee_documents WHERE employee_id = :id AND deleted_at IS NULL");

    $syncScansReflection->invoke($employeeSyncer, $emp6['id'], [], $adminUserId);
    $docCountStmt->execute([':id' => $emp6['id']]);
    check('syncDocumentScans() with an empty scan list is a pure no-op', (int)$docCountStmt->fetchColumn(), 0);

    // Seed a fake already-synced row directly (bypassing the real download path on purpose, same
    // "insert the row this method would have produced, then test the method's own branching logic
    // against it" approach used for CompanyStatutorySettingModel's own toggleStatus() test elsewhere
    // in this project).
    $pdo->prepare("INSERT INTO employee_documents (employee_id, document_type, source, file_name, file_path, source_url, uploaded_by) VALUES (:eid, 'passport_copy', 'sync', 'old_passport.pdf', 'storage/uploads/employees/999999/fake.pdf', 'https://origami.test/files/passport_11006.pdf', :uid)")
        ->execute([':eid' => $emp6['id'], ':uid' => $adminUserId]);

    $syncScansReflection->invoke($employeeSyncer, $emp6['id'], [['document_type' => 'passport_copy', 'url' => 'https://origami.test/files/passport_11006.pdf', 'name' => 'passport.pdf']], $adminUserId);
    $docCountStmt->execute([':id' => $emp6['id']]);
    check('syncDocumentScans() with an UNCHANGED source_url makes no new row (no network call attempted at all)', (int)$docCountStmt->fetchColumn(), 1);

    // URL genuinely differs this time, but the new URL is deliberately unfetchable (non-http) so
    // downloadDocumentScan() fails fast with zero network I/O -- the pre-existing row must survive
    // untouched (a failed re-fetch must never destroy what's already on file).
    $syncScansReflection->invoke($employeeSyncer, $emp6['id'], [['document_type' => 'passport_copy', 'url' => 'not-a-real-url', 'name' => 'passport.pdf']], $adminUserId);
    $survivingDoc = $pdo->prepare("SELECT source_url FROM employee_documents WHERE employee_id = :id AND document_type = 'passport_copy' AND deleted_at IS NULL");
    $survivingDoc->execute([':id' => $emp6['id']]);
    check('syncDocumentScans() leaves the existing row untouched when the new URL fails to download (never destroys data on a failed re-fetch)', $survivingDoc->fetchColumn(), 'https://origami.test/files/passport_11006.pdf');

    // A source='manual' row for the SAME document_type must never be touched by this method at all
    // (the soft-delete WHERE clause is scoped to source='sync' specifically) -- verified by seeding
    // one and confirming it survives every syncDocumentScans() call above untouched.
    $pdo->prepare("INSERT INTO employee_documents (employee_id, document_type, source, file_name, file_path, uploaded_by) VALUES (:eid, 'passport_copy', 'manual', 'my_own_scan.pdf', 'storage/uploads/employees/999999/manual.pdf', :uid)")
        ->execute([':eid' => $emp6['id'], ':uid' => $adminUserId]);
    $syncScansReflection->invoke($employeeSyncer, $emp6['id'], [['document_type' => 'passport_copy', 'url' => 'still-not-a-real-url', 'name' => 'passport.pdf']], $adminUserId);
    $manualDocStmt = $pdo->prepare("SELECT id FROM employee_documents WHERE employee_id = :id AND document_type = 'passport_copy' AND source = 'manual' AND deleted_at IS NULL");
    $manualDocStmt->execute([':id' => $emp6['id']]);
    checkTrue('a source=manual row for the same document_type is never touched by syncDocumentScans() (WHERE clause scoped to source=sync only)', $manualDocStmt->fetch() !== false);

    echo "--- EmployeeModel: documentTypes()/listDocuments() reflect the 2026-09-03 schema change ---\n";
    $employeeModelForDocs = new EmployeeModel();
    checkTrue('EmployeeModel::documentTypes() includes the 2 new synced-scan types', in_array('passport_copy', $employeeModelForDocs->documentTypes(), true) && in_array('visa_copy', $employeeModelForDocs->documentTypes(), true));
    check('EmployeeModel::documentTypes() still reuses work_permit_copy (no redundant 3rd variant added there)', count(array_keys($employeeModelForDocs->documentTypes(), 'work_permit_copy', true)), 1);
    $listedDocs6 = $employeeModelForDocs->listDocuments((int)$emp6['id'], $compId);
    $listedManual = current(array_filter($listedDocs6, fn($d) => $d['source'] === 'manual'));
    checkTrue('listDocuments() now surfaces the source column, correctly manual for the manually-inserted row', $listedManual !== false);
    // A brand-new manual upload through the EXISTING saveDocument() path (untouched by this round)
    // must still default to source='manual'/source_url=NULL via the column's own DB default --
    // confirms backward compatibility for the pre-existing upload flow, not just the new sync path.
    $manualSaveResult = $employeeModelForDocs->saveDocument((int)$emp6['id'], $compId, 'resume', 'my_resume.pdf', 'storage/uploads/employees/999999/resume.pdf', $adminUserId);
    checkTrue('saveDocument() (the pre-existing manual-upload path) still succeeds unchanged', $manualSaveResult['status'] ?? false);
    $freshManualRow = $pdo->prepare("SELECT source, source_url FROM employee_documents WHERE id = :id");
    $freshManualRow->execute([':id' => $manualSaveResult['id']]);
    $freshManual = $freshManualRow->fetch(PDO::FETCH_ASSOC);
    check('a fresh manual upload defaults source to manual via the column default (saveDocument() itself was not changed)', $freshManual['source'] ?? null, 'manual');
    checkTrue('a fresh manual upload has source_url = NULL', array_key_exists('source_url', $freshManual) && $freshManual['source_url'] === null);

    echo "--- StatutoryCalculationEngine: per-employee SSO rate override wins over company override, wins over master rate ---\n";
    $engine6 = new StatutoryCalculationEngine($pdo);
    $csModel6 = new CompanyStatutorySettingModel($pdo);
    $taxModel6 = new TaxStatutoryModel();
    $ssoItemId6 = null;
    foreach ($taxModel6->list('TH') as $row) {
        if ($row['code'] === 'TH_SSO') { $ssoItemId6 = (int)$row['id']; break; }
    }
    checkTrue('TH_SSO item found for company override lookup', $ssoItemId6 !== null);
    $masterResult6 = $engine6->calculateItem($compId, 'TH_SSO', ['basic_salary' => 10000, 'sso_eligible_earnings' => 10000], '2026-07-01');
    checkTrue('master/company rate_source when no per-employee override is passed', in_array($masterResult6['rate_source'] ?? null, ['master', 'company_override'], true));
    $overrideResult6 = $engine6->calculateItem($compId, 'TH_SSO', ['basic_salary' => 10000, 'sso_eligible_earnings' => 10000], '2026-07-01', [], [
        'TH_SSO' => ['employee_rate_override' => 3.0, 'employer_rate_override' => 2.5],
    ]);
    check('employee_amount uses the per-employee 3% override (300.00), not the master/company rate', $overrideResult6['employee_amount'], 300.0);
    check('employer_amount uses the per-employee 2.5% override (250.00)', $overrideResult6['employer_amount'], 250.0);
    check('rate_source reports employee_override', $overrideResult6['rate_source'] ?? null, 'employee_override');
    // Only the employee side overridden -- employer side must still fall back to the master/company rate untouched.
    $partialOverrideResult6 = $engine6->calculateItem($compId, 'TH_SSO', ['basic_salary' => 10000, 'sso_eligible_earnings' => 10000], '2026-07-01', [], [
        'TH_SSO' => ['employee_rate_override' => 3.0],
    ]);
    check('employee_amount uses the 3% override', $partialOverrideResult6['employee_amount'], 300.0);
    check('employer_amount falls back to the master/company rate (untouched), NOT zeroed just because the employee side was overridden', $partialOverrideResult6['employer_amount'], $masterResult6['employer_amount']);

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
    check('9 entity types attempted (branch/team added 2026-09-02)', count($all), 9);
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
