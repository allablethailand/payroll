<?php
/**
 * Lightweight verification script for the PAYROLL_SYNC_API.md 2026-08-28/29 revisions
 * (process_subject/process_description/process_start/process_end/process_paid, and the
 * run_kind="regular"/"supplemental" flag) -- see PayrollSyncModel::upsertProcess()'s own docblock
 * for the field-parsing side, and PayrollRunModel::create()/update()'s own 2026-08-29 comments for
 * how a 'supplemental' sync pull (e.g. an OT-only or Trip-only standalone cycle) is now allowed to
 * skip the "cycle required, always full payroll" rule a 'regular' sync pull still enforces
 * unchanged. Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev
 * DB inside a transaction that is always rolled back. Uses a fresh throwaway company, not comp_id=1.
 * Run with: php tests/payroll_sync_run_kind_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/PayrollSyncModel.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';

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
    $userId = 1;
    $compCode = 'SYNCRK_' . uniqid();
    $insComp = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', :comp_code)");
    $insComp->execute([':name' => 'Sync RunKind Test Co ' . uniqid(), ':comp_code' => $compCode]);
    $compId = (int)$pdo->lastInsertId();

    $empNo = 'SRK_' . uniqid();
    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'SRK', 'Test', 'SRK', '1990-01-01', 'Thai',
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'cash', 'monthly', 30000, '2020-01-01', 'average', 'active', 1, 0, 0)");
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => $empNo, ':email' => uniqid() . '@test.local']);
    $employeeId = (int)$pdo->lastInsertId();

    $cycleModel = new PayrollCycleModel($pdo);
    $cycleSave = $cycleModel->save($compId, [
        'cycle_name' => 'SRK_TEST_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    checkTrue('test cycle create succeeds' . (empty($cycleSave['status']) ? " ({$cycleSave['message']})" : ''), $cycleSave['status']);
    $cycleId = $cycleSave['id'];

    $syncModel = new PayrollSyncModel($pdo);

    function basePayloadItem(string $empNo): array {
        return [
            'report_item_id' => 1, 'emp_id' => 1, 'emp_code' => 'E-1', 'payroll_code' => $empNo,
            'dept_description' => null, 'position_name' => null, 'branch_id' => null, 'branch_name' => null,
            'shift_working_id' => null, 'shift_working_name' => null, 'pay_type' => 'cash',
            'pay_bank_id' => null, 'pay_bank_code' => null, 'pay_bank_name' => null, 'pay_bank_no' => null,
            'deduct_sso' => null, 'idcard' => null, 'idcard_issued' => null, 'idcard_expire' => null,
            'pass_pro' => null, 'pass_pro_date' => null, 'working_days' => 22, 'working_mins' => 10560,
            'absent_days' => 0, 'absent_mins' => 0, 'late_mins' => 0, 'early_mins' => 0, 'ot_mins' => 120,
            'ot_req_hrs' => 2, 'ot_req_working_day_hrs' => 2, 'ot_req_weekend_hrs' => 0, 'ot_req_holiday_hrs' => 0,
            'leave_approve_days' => 0, 'leave_wait_days' => 0, 'leave_without_pay_days' => 0, 'trip_allowance' => 0,
            'title' => null, 'gender' => null, 'date_birth' => null, 'nickname' => null, 'nationality' => null,
            'religion' => null, 'marital_status' => null, 'military_service' => null, 'emp_pic' => null,
            'signature_drawing' => null, 'email' => null, 'emp_tel' => null, 'dept_id' => null, 'posi_id' => null,
            'support_team_id' => null, 'support_team_text' => null, 'spouse' => null, 'children' => [],
            'item_values' => [],
        ];
    }

    echo "=== Ingest: regular sync process with real process_subject/dates ===\n";
    $regularPayload = [
        'schema_version' => 1, 'process_id' => random_int(100000, 999999), 'process_no' => 'REG-TEST-1',
        'process_subject' => 'July 2026 Payroll', 'process_description' => 'Regular monthly run',
        'process_start' => '2026-07-21', 'process_end' => '2026-08-20', 'process_paid' => '2026-07-25',
        'report_id' => 1, 'comp_id' => 1, 'comp_code' => $compCode, 'comp_name' => 'Test Co',
        'period_id' => 1, 'period_name' => 'Monthly', 'frequency_type' => 'monthly', 'run_kind' => 'regular',
        'items' => [basePayloadItem($empNo)], 'employee_status' => [],
    ];
    $regularResult = $syncModel->ingest($regularPayload);
    checkTrue('regular payload ingest succeeds' . (empty($regularResult['status']) ? " ({$regularResult['message']})" : ''), $regularResult['status']);
    $regularProcessRowId = $regularResult['process_row_id'];

    $row = $pdo->query("SELECT process_subject, process_description, process_start, process_end, process_paid, run_kind FROM payroll_sync_processes WHERE id = {$regularProcessRowId}")->fetch(PDO::FETCH_ASSOC);
    check('process_subject stored correctly', $row['process_subject'], 'July 2026 Payroll');
    check('process_description stored correctly', $row['process_description'], 'Regular monthly run');
    check('process_start stored correctly', $row['process_start'], '2026-07-21');
    check('process_end stored correctly', $row['process_end'], '2026-08-20');
    check('process_paid stored correctly', $row['process_paid'], '2026-07-25');
    check('run_kind stored as regular', $row['run_kind'], 'regular');

    echo "=== Ingest: supplemental sync process (no period dates, OT-only style) ===\n";
    $supplementalPayload = [
        'schema_version' => 1, 'process_id' => random_int(100000, 999999), 'process_no' => 'SUPP-TEST-1',
        'process_subject' => 'July OT Payout', 'process_description' => null,
        'process_start' => null, 'process_end' => null, 'process_paid' => null,
        'report_id' => 1, 'comp_id' => 1, 'comp_code' => $compCode, 'comp_name' => 'Test Co',
        'period_id' => 1, 'period_name' => 'Monthly', 'frequency_type' => 'monthly', 'run_kind' => 'supplemental',
        'items' => [basePayloadItem($empNo)], 'employee_status' => [],
    ];
    $supplementalResult = $syncModel->ingest($supplementalPayload);
    checkTrue('supplemental payload ingest succeeds' . (empty($supplementalResult['status']) ? " ({$supplementalResult['message']})" : ''), $supplementalResult['status']);
    $supplementalProcessRowId = $supplementalResult['process_row_id'];
    $row2 = $pdo->query("SELECT process_start, run_kind, attribution_tax_treatment FROM payroll_sync_processes WHERE id = {$supplementalProcessRowId}")->fetch(PDO::FETCH_ASSOC);
    check('process_start is null when Origami sends none', $row2['process_start'], null);
    check('run_kind stored as supplemental', $row2['run_kind'], 'supplemental');
    check('attribution absent -> tax_treatment stays null (fully stand-alone, today\'s existing behavior)', $row2['attribution_tax_treatment'], null);

    // ---------- 2026-08-31, PAYROLL_SYNC_API.md revision: `attribution` -- confirmed & built on
    // Origami's side. Only meaningful when run_kind='supplemental'. ----------
    echo "=== Ingest: supplemental with attribution.tax_treatment='merge' ===\n";
    $mergePayload = $supplementalPayload;
    $mergePayload['process_id'] = random_int(100000, 999999);
    $mergePayload['process_no'] = 'SUPP-MERGE-1';
    $mergeTargetOrigamiId = random_int(100000, 999999);
    $mergePayload['attribution'] = ['target_process_id' => $mergeTargetOrigamiId, 'target_process_no' => 'ORIGAMI-2026-00024', 'tax_treatment' => 'merge'];
    $mergeResult = $syncModel->ingest($mergePayload);
    checkTrue('supplemental+merge payload ingests' . (empty($mergeResult['status']) ? " ({$mergeResult['message']})" : ''), $mergeResult['status']);
    $mergeRow = $pdo->query("SELECT attribution_target_origami_process_id, attribution_target_process_no, attribution_tax_treatment FROM payroll_sync_processes WHERE id = {$mergeResult['process_row_id']}")->fetch(PDO::FETCH_ASSOC);
    check('attribution_target_origami_process_id stored', (int)$mergeRow['attribution_target_origami_process_id'], $mergeTargetOrigamiId);
    check('attribution_target_process_no stored', $mergeRow['attribution_target_process_no'], 'ORIGAMI-2026-00024');
    check('attribution_tax_treatment stored as merge', $mergeRow['attribution_tax_treatment'], 'merge');

    echo "=== Ingest: supplemental with attribution.tax_treatment='separate' ===\n";
    $separatePayload = $supplementalPayload;
    $separatePayload['process_id'] = random_int(100000, 999999);
    $separatePayload['process_no'] = 'SUPP-SEPARATE-1';
    $separateTargetOrigamiId = random_int(100000, 999999);
    $separatePayload['attribution'] = ['target_process_id' => $separateTargetOrigamiId, 'target_process_no' => 'ORIGAMI-2026-00030', 'tax_treatment' => 'separate'];
    $separateResult = $syncModel->ingest($separatePayload);
    checkTrue('supplemental+separate payload ingests' . (empty($separateResult['status']) ? " ({$separateResult['message']})" : ''), $separateResult['status']);
    $separateRow = $pdo->query("SELECT attribution_tax_treatment FROM payroll_sync_processes WHERE id = {$separateResult['process_row_id']}")->fetch(PDO::FETCH_ASSOC);
    check('attribution_tax_treatment stored as separate', $separateRow['attribution_tax_treatment'], 'separate');

    echo "=== Ingest: attribution ignored on a REGULAR process even if Origami sends one anyway ===\n";
    $regularWithAttribution = $regularPayload;
    $regularWithAttribution['process_id'] = random_int(100000, 999999);
    $regularWithAttribution['process_no'] = 'REG-WITH-ATTR-1';
    $regularWithAttribution['attribution'] = ['target_process_id' => 999, 'target_process_no' => 'SHOULD-BE-IGNORED', 'tax_treatment' => 'merge'];
    $regularAttrResult = $syncModel->ingest($regularWithAttribution);
    checkTrue('regular+attribution payload still ingests', $regularAttrResult['status']);
    $regularAttrRow = $pdo->query("SELECT attribution_target_origami_process_id, attribution_tax_treatment FROM payroll_sync_processes WHERE id = {$regularAttrResult['process_row_id']}")->fetch(PDO::FETCH_ASSOC);
    checkTrue('attribution_target_origami_process_id is null (run_kind=regular, attribution never applies)', $regularAttrRow['attribution_target_origami_process_id'] === null);
    checkTrue('attribution_tax_treatment is null (run_kind=regular, attribution never applies)', $regularAttrRow['attribution_tax_treatment'] === null);

    echo "=== Ingest: malformed attribution (no target_process_id) degrades to no-attribution ===\n";
    $malformedPayload = $supplementalPayload;
    $malformedPayload['process_id'] = random_int(100000, 999999);
    $malformedPayload['process_no'] = 'SUPP-MALFORMED-ATTR-1';
    $malformedPayload['attribution'] = ['tax_treatment' => 'merge']; // no target_process_id at all
    $malformedResult = $syncModel->ingest($malformedPayload);
    checkTrue('supplemental with a target-less attribution object still ingests (never throws)', $malformedResult['status']);
    $malformedRow = $pdo->query("SELECT attribution_tax_treatment FROM payroll_sync_processes WHERE id = {$malformedResult['process_row_id']}")->fetch(PDO::FETCH_ASSOC);
    checkTrue('degrades to no-attribution (same as attribution:null) rather than half-populating', $malformedRow['attribution_tax_treatment'] === null);

    echo "=== Ingest: attribution.tax_treatment absent defaults to 'separate' when a target IS given ===\n";
    $noTreatmentPayload = $supplementalPayload;
    $noTreatmentPayload['process_id'] = random_int(100000, 999999);
    $noTreatmentPayload['process_no'] = 'SUPP-NO-TREATMENT-1';
    $noTreatmentPayload['attribution'] = ['target_process_id' => random_int(100000, 999999), 'target_process_no' => 'ORIGAMI-2026-00040'];
    $noTreatmentResult = $syncModel->ingest($noTreatmentPayload);
    checkTrue('supplemental with a target but no explicit tax_treatment still ingests', $noTreatmentResult['status']);
    $noTreatmentRow = $pdo->query("SELECT attribution_tax_treatment FROM payroll_sync_processes WHERE id = {$noTreatmentResult['process_row_id']}")->fetch(PDO::FETCH_ASSOC);
    check('defaults to separate per Origami\'s own doc ("defaults to separate whenever no target is chosen" -- same safe default applied here for a missing treatment string)', $noTreatmentRow['attribution_tax_treatment'], 'separate');

    echo "=== Ingest: missing/unrecognized run_kind defaults safely to regular ===\n";
    $noRunKindPayload = $regularPayload;
    $noRunKindPayload['process_id'] = random_int(100000, 999999);
    $noRunKindPayload['process_no'] = 'NORUNKIND-TEST-1';
    unset($noRunKindPayload['run_kind']);
    $noKindResult = $syncModel->ingest($noRunKindPayload);
    checkTrue('payload with no run_kind at all still ingests successfully', $noKindResult['status']);
    $noKindRow = $pdo->query("SELECT run_kind FROM payroll_sync_processes WHERE id = {$noKindResult['process_row_id']}")->fetch(PDO::FETCH_ASSOC);
    check('missing run_kind defaults to regular (backward compatible)', $noKindRow['run_kind'], 'regular');

    echo "=== pendingList() surfaces the new fields ===\n";
    $pending = $syncModel->pendingList($compId);
    check('8 distinct pending processes visible (regular + supplemental + the 5 attribution fixtures + the no-run-kind fixture, none pulled into a run yet)', count($pending), 8);
    $pendingRegular = null;
    $pendingSupplemental = null;
    foreach ($pending as $p) {
        if ((int)$p['id'] === $regularProcessRowId) $pendingRegular = $p;
        if ((int)$p['id'] === $supplementalProcessRowId) $pendingSupplemental = $p;
    }
    checkTrue('regular process found in pendingList()', $pendingRegular !== null);
    check('pendingList() exposes process_subject', $pendingRegular['process_subject'], 'July 2026 Payroll');
    check('pendingList() exposes process_start', $pendingRegular['process_start'], '2026-07-21');
    check('pendingList() exposes run_kind', $pendingRegular['run_kind'], 'regular');
    checkTrue('supplemental process found in pendingList()', $pendingSupplemental !== null);
    check('pendingList() exposes run_kind=supplemental', $pendingSupplemental['run_kind'], 'supplemental');
    checkTrue('pendingList() plain no-attribution supplemental has null attribution_tax_treatment', $pendingSupplemental['attribution_tax_treatment'] === null);

    $pendingMerge = null;
    foreach ($pending as $p) { if ((int)$p['id'] === (int)$mergeResult['process_row_id']) $pendingMerge = $p; }
    checkTrue('the merge-attributed process found in pendingList()', $pendingMerge !== null);
    check('pendingList() exposes attribution_tax_treatment=merge', $pendingMerge['attribution_tax_treatment'], 'merge');
    check('pendingList() exposes attribution_target_process_no', $pendingMerge['attribution_target_process_no'], 'ORIGAMI-2026-00024');

    echo "=== PayrollRunModel::create() -- regular sync pull keeps existing strict rules ===\n";
    $runModel = new PayrollRunModel($pdo);
    $regularNoCycle = $runModel->create($compId, [
        'sync_process_id' => $regularProcessRowId, 'run_name' => 'Regular no cycle', 'payment_date' => '2026-07-25',
    ], $userId, true);
    checkFalse('regular sync pull WITHOUT cycle_id is rejected', $regularNoCycle['status']);

    $regularIncentive = $runModel->create($compId, [
        'sync_process_id' => $regularProcessRowId, 'cycle_id' => $cycleId, 'run_purpose' => 'incentive',
        'run_name' => 'Regular incentive attempt', 'period_start_date' => '2026-07-21', 'period_end_date' => '2026-08-20',
        'payment_date' => '2026-07-25',
    ], $userId, true);
    checkFalse('regular sync pull with run_purpose=incentive is rejected', $regularIncentive['status']);

    $regularOk = $runModel->create($compId, [
        'sync_process_id' => $regularProcessRowId, 'cycle_id' => $cycleId, 'run_name' => 'Regular pull OK',
        'period_start_date' => '2026-07-21', 'period_end_date' => '2026-08-20', 'payment_date' => '2026-07-25',
    ], $userId, true);
    checkTrue('regular sync pull WITH cycle_id succeeds' . (empty($regularOk['status']) ? " ({$regularOk['message']})" : ''), $regularOk['status']);
    $regularRunId = $regularOk['id'];
    $regularRun = $runModel->get($regularRunId, $compId);
    check('regular pull forced run_purpose=payroll', $regularRun['run_purpose'], 'payroll');
    check('regular pull forced include_base_salary=1', (int)$regularRun['include_base_salary'], 1);
    check('get() exposes sync_run_kind=regular for this run', $regularRun['sync_run_kind'], 'regular');
    check('get() exposes sync_process_subject', $regularRun['sync_process_subject'], 'July 2026 Payroll');

    echo "=== PayrollRunModel::create() -- supplemental sync pull relaxed rules ===\n";
    $supplementalOk = $runModel->create($compId, [
        'sync_process_id' => $supplementalProcessRowId, 'run_purpose' => 'incentive',
        'compute_statutory' => 0, 'include_base_salary' => 0, 'include_standing_items' => 0,
        'run_name' => 'Supplemental OT payout', 'payment_date' => '2026-07-28',
    ], $userId, true);
    checkTrue('supplemental sync pull WITHOUT cycle_id succeeds' . (empty($supplementalOk['status']) ? " ({$supplementalOk['message']})" : ''), $supplementalOk['status']);
    $supplementalRunId = $supplementalOk['id'];
    $supplementalRun = $runModel->get($supplementalRunId, $compId);
    check('supplemental pull respects run_purpose=incentive', $supplementalRun['run_purpose'], 'incentive');
    check('supplemental pull respects include_base_salary=0 (not forced on)', (int)$supplementalRun['include_base_salary'], 0);
    check('supplemental pull respects include_standing_items=0', (int)$supplementalRun['include_standing_items'], 0);
    check('supplemental pull cycle_id is null', $supplementalRun['cycle_id'], null);
    check('get() exposes sync_run_kind=supplemental for this run', $supplementalRun['sync_run_kind'], 'supplemental');

    echo "=== PayrollRunModel::update() -- editability follows sync_run_kind ===\n";
    $updateRegular = $runModel->update($regularRunId, $compId, ['run_purpose' => 'incentive', 'include_base_salary' => 0], $userId, true);
    checkTrue('update() on a regular-sync-linked draft run still succeeds (other fields save)', $updateRegular['status']);
    $regularRunAfter = $runModel->get($regularRunId, $compId);
    check('regular-sync-linked run_purpose stays forced to payroll (edit attempt ignored)', $regularRunAfter['run_purpose'], 'payroll');
    check('regular-sync-linked include_base_salary stays forced to 1', (int)$regularRunAfter['include_base_salary'], 1);

    $updateSupplemental = $runModel->update($supplementalRunId, $compId, ['run_purpose' => 'incentive', 'include_base_salary' => 1, 'compute_statutory' => 1, 'include_standing_items' => 1], $userId, true);
    checkTrue('update() on a supplemental-sync-linked draft run succeeds' . (empty($updateSupplemental['status']) ? " ({$updateSupplemental['message']})" : ''), $updateSupplemental['status']);
    $supplementalRunAfter = $runModel->get($supplementalRunId, $compId);
    check('supplemental-sync-linked include_base_salary is now editable to 1', (int)$supplementalRunAfter['include_base_salary'], 1);
    check('supplemental-sync-linked compute_statutory is now editable to 1', (int)$supplementalRunAfter['compute_statutory'], 1);

    echo "\n--------------------------------------------------\n";
    echo "Passed: {$passes}, Failed: {$failures}\n";
    if ($failures > 0) {
        echo "SOME TESTS FAILED\n";
    } else {
        echo "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
    }
} catch (Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
exit($failures > 0 ? 1 : 0);
