<?php
/**
 * Lightweight verification script for PAYROLL_SYNC_API.md's 2026-08-31 `attribution` revision --
 * PayrollRunModel::mergeSupplementalIntoRun() (Phase C of that feature's own plan). Not PHPUnit --
 * see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a transaction
 * that is always rolled back. Uses a fresh throwaway company, not comp_id=1. See
 * tests/payroll_sync_run_kind_test.php for the ingest-layer (Phase A/B) coverage of `attribution` --
 * this file only covers the merge mechanism itself.
 * Run with: php tests/payroll_sync_attribution_test.php
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
require_once __DIR__ . '/../app/models/PayrollPolicyModel.php';

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

try {
    $userId = 1;
    $compCode = 'ATTR_' . uniqid();
    $insComp = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', :comp_code)");
    $insComp->execute([':name' => 'Attribution Test Co ' . uniqid(), ':comp_code' => $compCode]);
    $compId = (int)$pdo->lastInsertId();

    $empNo = 'ATTR_EMP_' . uniqid();
    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'ATTR', 'Test', 'ATTR', '1990-01-01', 'Thai',
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active', 0, 0, 0)");
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => $empNo, ':email' => uniqid() . '@test.local']);
    $employeeId = (int)$pdo->lastInsertId();

    $cycleModel = new PayrollCycleModel($pdo);
    $cycleSave = $cycleModel->save($compId, [
        'cycle_name' => 'ATTR_TEST_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    checkTrue('fixture: cycle created', $cycleSave['status']);
    $cycleId = $cycleSave['id'];

    $syncModel = new PayrollSyncModel($pdo);
    $runModel = new PayrollRunModel($pdo);

    function attrBasePayloadItem(string $empNo, float $tripAllowance = 0): array {
        return [
            'report_item_id' => 1, 'emp_id' => 1, 'emp_code' => 'E-1', 'payroll_code' => $empNo,
            'dept_description' => null, 'position_name' => null, 'branch_id' => null, 'branch_name' => null,
            'shift_working_id' => null, 'shift_working_name' => null, 'pay_type' => 'cash',
            'pay_bank_id' => null, 'pay_bank_code' => null, 'pay_bank_name' => null, 'pay_bank_no' => null,
            'deduct_sso' => null, 'idcard' => null, 'idcard_issued' => null, 'idcard_expire' => null,
            'pass_pro' => null, 'pass_pro_date' => null, 'working_days' => 22, 'working_mins' => 10560,
            'absent_days' => 0, 'absent_mins' => 0, 'late_mins' => 0, 'early_mins' => 0, 'ot_mins' => 0,
            'ot_req_hrs' => 0, 'ot_req_working_day_hrs' => 0, 'ot_req_weekend_hrs' => 0, 'ot_req_holiday_hrs' => 0,
            'leave_approve_days' => 0, 'leave_wait_days' => 0, 'leave_without_pay_days' => 0, 'trip_allowance' => $tripAllowance,
            'title' => null, 'gender' => null, 'date_birth' => null, 'nickname' => null, 'nationality' => null,
            'religion' => null, 'marital_status' => null, 'military_service' => null, 'emp_pic' => null,
            'signature_drawing' => null, 'email' => null, 'emp_tel' => null, 'dept_id' => null, 'posi_id' => null,
            'support_team_id' => null, 'support_team_text' => null, 'spouse' => null, 'children' => [],
            'item_values' => [],
        ];
    }

    // ---------- Fixture: a real REGULAR run, still DRAFT, pulled from a regular sync process ----------
    $targetOrigamiProcessId = random_int(200000, 299999);
    $regularPayload = [
        'schema_version' => 1, 'process_id' => $targetOrigamiProcessId, 'process_no' => 'ORIGAMI-2026-ATTR-TARGET',
        'process_subject' => 'Attribution Target Cycle', 'process_description' => null,
        'process_start' => '2026-07-21', 'process_end' => '2026-08-20', 'process_paid' => '2026-07-25',
        'report_id' => 1, 'comp_id' => 1, 'comp_code' => $compCode, 'comp_name' => 'Test Co',
        'period_id' => 1, 'period_name' => 'Monthly', 'frequency_type' => 'monthly', 'run_kind' => 'regular',
        'items' => [attrBasePayloadItem($empNo)], 'employee_status' => [],
    ];
    $regularResult = $syncModel->ingest($regularPayload);
    checkTrue('fixture: regular target payload ingests', $regularResult['status']);
    $regularProcessRowId = $regularResult['process_row_id'];

    $targetCreate = $runModel->create($compId, [
        'sync_process_id' => $regularProcessRowId, 'cycle_id' => $cycleId, 'run_name' => 'Attribution Target Run',
        'period_start_date' => '2026-07-21', 'period_end_date' => '2026-08-20', 'payment_date' => '2026-07-25',
    ], $userId, true);
    checkTrue('fixture: target run created' . (empty($targetCreate['status']) ? " ({$targetCreate['message']})" : ''), $targetCreate['status']);
    $targetRunId = $targetCreate['id'];
    $targetCalc = $runModel->recalculate($targetRunId, $compId, $userId, true);
    checkTrue('fixture: target run calculated', $targetCalc['status']);
    $targetBefore = $runModel->getDetails($targetRunId, $compId);
    $targetRowBefore = current(array_filter($targetBefore, fn($d) => (int)$d['employee_id'] === $employeeId));
    $grossBefore = (float)$targetRowBefore['gross_amount'];
    checkTrue('fixture: target run has a real base-salary gross before any merge', $grossBefore > 0);

    // ---------- A supplemental process attributed to merge into the target above ----------
    $supplementalProcessId = random_int(300000, 399999);
    $mergePayload = [
        'schema_version' => 1, 'process_id' => $supplementalProcessId, 'process_no' => 'ORIGAMI-2026-ATTR-SUPP',
        'process_subject' => 'Trip Allowance Payout', 'process_description' => null,
        'process_start' => null, 'process_end' => null, 'process_paid' => null,
        'report_id' => 1, 'comp_id' => 1, 'comp_code' => $compCode, 'comp_name' => 'Test Co',
        'period_id' => 1, 'period_name' => 'Monthly', 'frequency_type' => 'monthly', 'run_kind' => 'supplemental',
        'attribution' => ['target_process_id' => $targetOrigamiProcessId, 'target_process_no' => 'ORIGAMI-2026-ATTR-TARGET', 'tax_treatment' => 'merge'],
        'items' => [attrBasePayloadItem($empNo, 750.0)], 'employee_status' => [],
    ];
    $mergeResult = $syncModel->ingest($mergePayload);
    checkTrue('fixture: merge-attributed supplemental payload ingests', $mergeResult['status']);
    $supplementalProcessRowId = $mergeResult['process_row_id'];

    echo "=== mergeSupplementalIntoRun(): the real merge ===\n";
    $mergeAction = $runModel->mergeSupplementalIntoRun($supplementalProcessRowId, $compId, $userId, true);
    checkTrue('merge succeeds' . (empty($mergeAction['status']) ? " ({$mergeAction['message']})" : ''), $mergeAction['status']);
    check('merge reports the correct target_run_id', $mergeAction['target_run_id'] ?? null, $targetRunId);
    checkTrue('at least 1 line was merged (the trip allowance)', ($mergeAction['merged_line_count'] ?? 0) >= 1);
    check('no employees skipped (the fixture employee IS in the target run)', $mergeAction['skipped_employee_ids'] ?? ['x'], []);

    $targetAfter = $runModel->getDetails($targetRunId, $compId);
    $targetRowAfter = current(array_filter($targetAfter, fn($d) => (int)$d['employee_id'] === $employeeId));
    check('target run gross_amount increased by exactly the trip allowance (750)', (float)$targetRowAfter['gross_amount'], $grossBefore + 750.0);
    // The source line inside the throwaway extraction run itself already carried a Thai custom
    // label (ค่าเที่ยว = trip allowance) rather than a catalog item_code match -- confirmed by
    // direct inspection, not guessed -- so the merged line on the target run correctly preserves
    // that same label (source='manual_line', is_custom=1) rather than resolving to a catalog id.
    $tripLine = current(array_filter($targetRowAfter['earning_breakdown'], fn($l) => (float)($l['amount'] ?? 0) === 750.0 && ($l['source'] ?? '') === 'manual_line'));
    checkTrue('the merged 750-amount manual_line earning is present in the target run\'s own breakdown', $tripLine !== false);

    $processRowAfter = $pdo->query("SELECT merged_into_run_id FROM payroll_sync_processes WHERE id = {$supplementalProcessRowId}")->fetch(PDO::FETCH_ASSOC);
    check('payroll_sync_processes.merged_into_run_id now points at the target run', (int)$processRowAfter['merged_into_run_id'], $targetRunId);

    $pendingAfterMerge = $syncModel->pendingList($compId);
    $stillPending = current(array_filter($pendingAfterMerge, fn($p) => (int)$p['id'] === $supplementalProcessRowId));
    checkFalse('the merged supplemental process no longer appears in Pending Pull', $stillPending !== false);

    $throwawayRuns = $pdo->query("SELECT id, status FROM payroll_runs WHERE run_name LIKE 'Merge extraction:%' AND comp_id = {$compId}")->fetchAll(PDO::FETCH_ASSOC);
    checkTrue('exactly one throwaway extraction run was created', count($throwawayRuns) === 1);
    check('the throwaway run is soft-deleted (never a hard DELETE, per this project\'s own convention)', $throwawayRuns[0]['status'] ?? null, 'deleted');

    checkFalse('a second merge attempt on the SAME already-merged process is refused', $runModel->mergeSupplementalIntoRun($supplementalProcessRowId, $compId, $userId, true)['status']);

    echo "=== mergeSupplementalIntoRun(): refusal paths ===\n";
    // Target not yet pulled into a run at all.
    $noTargetOrigamiId = random_int(400000, 499999);
    $noTargetSuppId = random_int(500000, 599999);
    $noTargetPayload = $mergePayload;
    $noTargetPayload['process_id'] = $noTargetSuppId;
    $noTargetPayload['process_no'] = 'ORIGAMI-2026-ATTR-NOTARGET';
    $noTargetPayload['attribution']['target_process_id'] = $noTargetOrigamiId; // never ingested as its own process at all
    $noTargetResult = $syncModel->ingest($noTargetPayload);
    checkTrue('fixture: no-target supplemental ingests', $noTargetResult['status']);
    $noTargetMerge = $runModel->mergeSupplementalIntoRun($noTargetResult['process_row_id'], $compId, $userId, true);
    checkFalse('merge refused when the target cycle was never even pulled into a run', $noTargetMerge['status']);

    // Target exists but is NOT draft (pending_approval).
    $target2OrigamiId = random_int(600000, 699999);
    $target2Payload = $regularPayload;
    $target2Payload['process_id'] = $target2OrigamiId;
    $target2Payload['process_no'] = 'ORIGAMI-2026-ATTR-TARGET2';
    $target2Result = $syncModel->ingest($target2Payload);
    checkTrue('fixture: second regular target payload ingests', $target2Result['status']);
    $target2Create = $runModel->create($compId, [
        'sync_process_id' => $target2Result['process_row_id'], 'cycle_id' => $cycleId, 'run_name' => 'Attribution Target Run 2',
        'period_start_date' => '2026-08-21', 'period_end_date' => '2026-09-20', 'payment_date' => '2026-08-25',
    ], $userId, true);
    checkTrue('fixture: second target run created', $target2Create['status']);
    $runModel->recalculate($target2Create['id'], $compId, $userId, true);
    $submitRes = $runModel->submit($target2Create['id'], $compId, $userId, true);
    checkTrue('fixture: second target run submitted (now pending_approval, not draft)', $submitRes['status']);

    $notDraftSuppPayload = $mergePayload;
    $notDraftSuppPayload['process_id'] = random_int(700000, 799999);
    $notDraftSuppPayload['process_no'] = 'ORIGAMI-2026-ATTR-SUPP2';
    $notDraftSuppPayload['attribution']['target_process_id'] = $target2OrigamiId;
    $notDraftSuppResult = $syncModel->ingest($notDraftSuppPayload);
    checkTrue('fixture: not-draft-target supplemental ingests', $notDraftSuppResult['status']);
    $notDraftMerge = $runModel->mergeSupplementalIntoRun($notDraftSuppResult['process_row_id'], $compId, $userId, true);
    checkFalse('merge refused when the target run is no longer draft (pending_approval)', $notDraftMerge['status']);
    $target2AfterRefusal = $runModel->get($target2Create['id'], $compId);
    check('the not-draft target run itself is completely untouched by the refused merge attempt', $target2AfterRefusal['state'], 'pending_approval');

    // ---------- 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย"): allowRevertNonDraftTarget=true
    // auto-reverts a pending_approval/approved/rejected/need_info target back to draft, then merges. ----------
    echo "=== mergeSupplementalIntoRun(allowRevertNonDraftTarget=true): pending_approval target ===\n";
    $target2Before = $runModel->getDetails($target2Create['id'], $compId);
    $target2RowBefore = current(array_filter($target2Before, fn($d) => (int)$d['employee_id'] === $employeeId));
    $target2GrossBefore = (float)$target2RowBefore['gross_amount'];
    $revertMerge = $runModel->mergeSupplementalIntoRun($notDraftSuppResult['process_row_id'], $compId, $userId, true, true);
    checkTrue('merge succeeds with allowRevertNonDraftTarget=true on a pending_approval target' . (empty($revertMerge['status']) ? " ({$revertMerge['message']})" : ''), $revertMerge['status']);
    $target2AfterMerge = $runModel->get($target2Create['id'], $compId);
    check('the target run ends up back at draft (requires a fresh submit+approve)', $target2AfterMerge['state'], 'draft');
    $target2DetailsAfter = $runModel->getDetails($target2Create['id'], $compId);
    $target2RowAfter = current(array_filter($target2DetailsAfter, fn($d) => (int)$d['employee_id'] === $employeeId));
    check('the target run\'s own gross increased by the merged trip allowance (750)', (float)$target2RowAfter['gross_amount'], $target2GrossBefore + 750.0);

    echo "=== mergeSupplementalIntoRun(allowRevertNonDraftTarget=true): approved target (2-hop revert) ===\n";
    $target3OrigamiId = random_int(900000, 999999);
    $target3Payload = $regularPayload;
    $target3Payload['process_id'] = $target3OrigamiId;
    $target3Payload['process_no'] = 'ORIGAMI-2026-ATTR-TARGET3';
    $target3Result = $syncModel->ingest($target3Payload);
    checkTrue('fixture: third regular target payload ingests', $target3Result['status']);
    $target3Create = $runModel->create($compId, [
        'sync_process_id' => $target3Result['process_row_id'], 'cycle_id' => $cycleId, 'run_name' => 'Attribution Target Run 3',
        'period_start_date' => '2026-09-21', 'period_end_date' => '2026-10-20', 'payment_date' => '2026-09-25',
    ], $userId, true);
    checkTrue('fixture: third target run created', $target3Create['status']);
    $runModel->recalculate($target3Create['id'], $compId, $userId, true);
    checkTrue('fixture: third target run submitted', $runModel->submit($target3Create['id'], $compId, $userId, true)['status']);
    checkTrue('fixture: third target run approved (now 2 hops from draft)', $runModel->approve($target3Create['id'], $compId, $userId, true)['status']);
    $target3StateCheck = $runModel->get($target3Create['id'], $compId);
    check('fixture sanity: third target run really is approved before the merge attempt', $target3StateCheck['state'], 'approved');

    $approvedSuppPayload = $mergePayload;
    $approvedSuppPayload['process_id'] = random_int(1000000, 1099999);
    $approvedSuppPayload['process_no'] = 'ORIGAMI-2026-ATTR-SUPP3';
    $approvedSuppPayload['attribution']['target_process_id'] = $target3OrigamiId;
    $approvedSuppResult = $syncModel->ingest($approvedSuppPayload);
    checkTrue('fixture: approved-target supplemental ingests', $approvedSuppResult['status']);

    checkFalse('WITHOUT the opt-in flag, merge is still refused on an approved target (safe default unchanged)', $runModel->mergeSupplementalIntoRun($approvedSuppResult['process_row_id'], $compId, $userId, true)['status']);

    $approvedMerge = $runModel->mergeSupplementalIntoRun($approvedSuppResult['process_row_id'], $compId, $userId, true, true);
    checkTrue('merge succeeds with the opt-in flag on an APPROVED target (2-hop revert: approved->pending_approval->draft)' . (empty($approvedMerge['status']) ? " ({$approvedMerge['message']})" : ''), $approvedMerge['status']);
    $target3AfterMerge = $runModel->get($target3Create['id'], $compId);
    check('the approved target run ends up back at draft', $target3AfterMerge['state'], 'draft');
    checkTrue('the approved target run\'s own approved_at was cleared by the revert chain', $target3AfterMerge['approved_at'] === null);

    // paid/locked is NEVER covered by this flag, even with the opt-in.
    echo "=== mergeSupplementalIntoRun(allowRevertNonDraftTarget=true): paid/locked target is STILL always refused ===\n";
    $target4OrigamiId = random_int(1100000, 1199999);
    $target4Payload = $regularPayload;
    $target4Payload['process_id'] = $target4OrigamiId;
    $target4Payload['process_no'] = 'ORIGAMI-2026-ATTR-TARGET4';
    $target4Result = $syncModel->ingest($target4Payload);
    checkTrue('fixture: fourth regular target payload ingests', $target4Result['status']);
    $target4Create = $runModel->create($compId, [
        'sync_process_id' => $target4Result['process_row_id'], 'cycle_id' => $cycleId, 'run_name' => 'Attribution Target Run 4',
        'period_start_date' => '2026-10-21', 'period_end_date' => '2026-11-20', 'payment_date' => '2026-10-25',
    ], $userId, true);
    checkTrue('fixture: fourth target run created', $target4Create['status']);
    $runModel->recalculate($target4Create['id'], $compId, $userId, true);
    $runModel->submit($target4Create['id'], $compId, $userId, true);
    $runModel->approve($target4Create['id'], $compId, $userId, true);
    $markPaidRes = $runModel->markPaid($target4Create['id'], $compId, $userId, true, ['payment_method' => 'bank_transfer']);
    checkTrue('fixture: fourth target run marked paid' . (empty($markPaidRes['status']) ? " ({$markPaidRes['message']})" : ''), $markPaidRes['status']);
    $target4StateCheck = $runModel->get($target4Create['id'], $compId);
    check('fixture sanity: fourth target run really is paid', $target4StateCheck['state'], 'paid');

    $paidSuppPayload = $mergePayload;
    $paidSuppPayload['process_id'] = random_int(1200000, 1299999);
    $paidSuppPayload['process_no'] = 'ORIGAMI-2026-ATTR-SUPP4';
    $paidSuppPayload['attribution']['target_process_id'] = $target4OrigamiId;
    $paidSuppResult = $syncModel->ingest($paidSuppPayload);
    checkTrue('fixture: paid-target supplemental ingests', $paidSuppResult['status']);
    $paidMergeRefused = $runModel->mergeSupplementalIntoRun($paidSuppResult['process_row_id'], $compId, $userId, true, true);
    checkFalse('merge STILL refused on a paid target even WITH the revert opt-in flag (that flag only ever covers pending_approval/approved/rejected/need_info)', $paidMergeRefused['status']);
    checkTrue('refusal correctly flags needs_reopen_confirmation (a DIFFERENT flag is needed for paid/locked)', !empty($paidMergeRefused['needs_reopen_confirmation']));
    $target4AfterRefusal = $runModel->get($target4Create['id'], $compId);
    check('the paid target run is completely untouched', $target4AfterRefusal['state'], 'paid');

    // ---------- 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย"): item 2, allowReopenPaidTarget=true
    // reopens a paid/locked target via the SAME PayrollRunModel::reopen() the Detail page's own
    // "Reopen" admin action already uses (2026-08-29 feature), then merges normally. ----------
    echo "=== mergeSupplementalIntoRun(allowReopenPaidTarget=true): paid target is reopened, then merged ===\n";
    $target5OrigamiId = random_int(1300000, 1399999);
    $target5Payload = $regularPayload;
    $target5Payload['process_id'] = $target5OrigamiId;
    $target5Payload['process_no'] = 'ORIGAMI-2026-ATTR-TARGET5';
    $target5Result = $syncModel->ingest($target5Payload);
    checkTrue('fixture: fifth regular target payload ingests', $target5Result['status']);
    $target5Create = $runModel->create($compId, [
        'sync_process_id' => $target5Result['process_row_id'], 'cycle_id' => $cycleId, 'run_name' => 'Attribution Target Run 5',
        'period_start_date' => '2027-02-21', 'period_end_date' => '2027-03-20', 'payment_date' => '2027-02-25',
    ], $userId, true);
    checkTrue('fixture: fifth target run created', $target5Create['status']);
    $runModel->recalculate($target5Create['id'], $compId, $userId, true);
    $runModel->submit($target5Create['id'], $compId, $userId, true);
    $runModel->approve($target5Create['id'], $compId, $userId, true);
    $markPaidRes5 = $runModel->markPaid($target5Create['id'], $compId, $userId, true, ['payment_method' => 'bank_transfer']);
    checkTrue('fixture: fifth target run marked paid' . (empty($markPaidRes5['status']) ? " ({$markPaidRes5['message']})" : ''), $markPaidRes5['status']);
    $target5Before = $runModel->getDetails($target5Create['id'], $compId);
    $target5RowBefore = current(array_filter($target5Before, fn($d) => (int)$d['employee_id'] === $employeeId));
    $target5GrossBefore = (float)$target5RowBefore['gross_amount'];

    $reopenSuppPayload = $mergePayload;
    $reopenSuppPayload['process_id'] = random_int(1400000, 1499999);
    $reopenSuppPayload['process_no'] = 'ORIGAMI-2026-ATTR-SUPP5';
    $reopenSuppPayload['attribution']['target_process_id'] = $target5OrigamiId;
    $reopenSuppResult = $syncModel->ingest($reopenSuppPayload);
    checkTrue('fixture: paid-target (item 2) supplemental ingests', $reopenSuppResult['status']);

    checkFalse('WITHOUT the reopen opt-in flag, merge is still refused on a paid target (safe default unchanged)', $runModel->mergeSupplementalIntoRun($reopenSuppResult['process_row_id'], $compId, $userId, true)['status']);

    $reopenMerge = $runModel->mergeSupplementalIntoRun($reopenSuppResult['process_row_id'], $compId, $userId, true, false, true);
    checkTrue('merge succeeds with allowReopenPaidTarget=true on a PAID target (reopen -> draft -> merge)' . (empty($reopenMerge['status']) ? " ({$reopenMerge['message']})" : ''), $reopenMerge['status']);
    check('merge reports the correct target_run_id', $reopenMerge['target_run_id'] ?? null, $target5Create['id']);
    $target5AfterMerge = $runModel->get($target5Create['id'], $compId);
    check('the reopened+merged target run ends up back at draft (requires a fresh calculate+submit+approve+pay)', $target5AfterMerge['state'], 'draft');
    checkTrue('paid_at was cleared by the reopen', $target5AfterMerge['paid_at'] === null);
    checkTrue('approved_at was cleared by the reopen', $target5AfterMerge['approved_at'] === null);
    $target5DetailsAfter = $runModel->getDetails($target5Create['id'], $compId);
    $target5RowAfter = current(array_filter($target5DetailsAfter, fn($d) => (int)$d['employee_id'] === $employeeId));
    check('the target run\'s own gross increased by the merged trip allowance (750) even after being reopened', (float)$target5RowAfter['gross_amount'], $target5GrossBefore + 750.0);

    // tax_treatment='separate' can never be merged.
    $separateSuppPayload = $mergePayload;
    $separateSuppPayload['process_id'] = random_int(800000, 899999);
    $separateSuppPayload['process_no'] = 'ORIGAMI-2026-ATTR-SEPARATE';
    $separateSuppPayload['attribution']['tax_treatment'] = 'separate';
    $separateSuppResult = $syncModel->ingest($separateSuppPayload);
    checkTrue('fixture: separate-attributed supplemental ingests', $separateSuppResult['status']);
    checkFalse('merge refused on a process attributed tax_treatment=separate', $runModel->mergeSupplementalIntoRun($separateSuppResult['process_row_id'], $compId, $userId, true)['status']);

    // A REGULAR process is never mergeable at all.
    checkFalse('merge refused on a run_kind=regular process', $runModel->mergeSupplementalIntoRun($regularProcessRowId, $compId, $userId, true)['status']);

    // ---------- 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย"): item 3, a company-configured
    // flat withholding rate for a standalone supplemental run that opts in via
    // payroll_runs.use_flat_tax_rate. Not a merge -- a plain standalone off-cycle/supplemental
    // pull, same as tax_treatment='separate' already produces today, just with a different tax
    // computation substituted in for TH_PIT. ----------
    echo "=== use_flat_tax_rate: falls back to normal PIT when no company rate is configured ===\n";
    $policyModel = new PayrollPolicyModel($pdo);
    $noRateConfig = $policyModel->get($compId);
    check('fixture sanity: this fresh company has no flat tax rate configured yet', $noRateConfig['supplemental_flat_tax_rate_percent'], null);

    $flatRunNoRate = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'compute_statutory' => 1, 'include_base_salary' => 1,
        'include_standing_items' => 0, 'include_attendance_pay' => 0, 'use_flat_tax_rate' => 1,
        'run_name' => 'Flat Tax Test (no rate configured)',
        'period_start_date' => '2026-11-21', 'period_end_date' => '2026-12-20', 'payment_date' => '2026-11-25',
    ], $userId, true);
    checkTrue('fixture: standalone flat-tax-opt-in run created' . (empty($flatRunNoRate['status']) ? " ({$flatRunNoRate['message']})" : ''), $flatRunNoRate['status']);
    checkTrue('fixture: employee joined into the standalone run', $runModel->joinEmployees($flatRunNoRate['id'], $compId, [$employeeId], $userId, true)['status']);
    checkTrue('fixture: standalone run recalculated', $runModel->recalculate($flatRunNoRate['id'], $compId, $userId, true)['status']);
    $noRateDetails = $runModel->getDetails($flatRunNoRate['id'], $compId);
    $noRateRow = current(array_filter($noRateDetails, fn($d) => (int)$d['employee_id'] === $employeeId));
    $noRatePit = current(array_filter($noRateRow['statutory_breakdown'], fn($l) => ($l['code'] ?? '') === 'TH_PIT'));
    checkTrue('TH_PIT line exists on the standalone run', $noRatePit !== false);
    checkFalse('use_flat_tax_rate=1 with NO company rate configured falls back to the normal average/actual PIT calculation (note is not a flat-rate note)', str_starts_with((string)($noRatePit['note'] ?? ''), 'th_pit_flat_rate_'));

    echo "=== use_flat_tax_rate: applies the configured flat % once a company rate is set ===\n";
    // $userId (1) is a synthetic admin id, not a real employees.id row -- company_payroll_policies
    // has a real FK on updated_by -> employees(id) (unlike payroll_runs.created_by/approved_by,
    // which deliberately have none, see this project's own established FK convention) -- use the
    // fixture's own real employee row instead, same fix already applied elsewhere this session for
    // payroll_run_cash_payments.paid_by.
    $policySaveRes = $policyModel->save($compId, ['supplemental_flat_tax_rate_percent' => 15], $employeeId);
    checkTrue('fixture: company flat tax rate saved (15%)' . (empty($policySaveRes['status']) ? " ({$policySaveRes['message']})" : ''), $policySaveRes['status']);
    check('flatTaxRatePercent() reads back the saved rate', $policyModel->flatTaxRatePercent($compId), 15.0);

    $flatRunWithRate = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'compute_statutory' => 1, 'include_base_salary' => 1,
        'include_standing_items' => 0, 'include_attendance_pay' => 0, 'use_flat_tax_rate' => 1,
        'run_name' => 'Flat Tax Test (15% configured)',
        'period_start_date' => '2026-12-21', 'period_end_date' => '2027-01-20', 'payment_date' => '2026-12-25',
    ], $userId, true);
    checkTrue('fixture: second standalone flat-tax-opt-in run created', $flatRunWithRate['status']);
    checkTrue('fixture: employee joined into the second standalone run', $runModel->joinEmployees($flatRunWithRate['id'], $compId, [$employeeId], $userId, true)['status']);
    checkTrue('fixture: second standalone run recalculated', $runModel->recalculate($flatRunWithRate['id'], $compId, $userId, true)['status']);
    $withRateDetails = $runModel->getDetails($flatRunWithRate['id'], $compId);
    $withRateRow = current(array_filter($withRateDetails, fn($d) => (int)$d['employee_id'] === $employeeId));
    $withRatePit = current(array_filter($withRateRow['statutory_breakdown'], fn($l) => ($l['code'] ?? '') === 'TH_PIT'));
    checkTrue('TH_PIT line exists on the second standalone run', $withRatePit !== false);
    check('TH_PIT employee_amount equals 15% of the run\'s own taxable gross (base salary, no other earnings)', round((float)$withRatePit['employee_amount'], 2), round(30000.0 * 15 / 100, 2));
    check('note marks this as the flat-rate computation, not the normal average/actual one', $withRatePit['note'] ?? null, 'th_pit_flat_rate_15');

    echo "=== use_flat_tax_rate: forced to 0 for a normal 'payroll'-purpose run, even if sent as 1 ===\n";
    $normalRunAttempt = $runModel->create($compId, [
        'run_name' => 'Normal Run (use_flat_tax_rate ignored)', 'use_flat_tax_rate' => 1,
        'period_start_date' => '2027-01-21', 'period_end_date' => '2027-02-20', 'payment_date' => '2027-01-25',
    ], $userId, true);
    checkTrue('fixture: normal run created', $normalRunAttempt['status']);
    $normalRunRow = $runModel->get($normalRunAttempt['id'], $compId);
    check('use_flat_tax_rate stayed 0 on a normal run despite the request sending 1 (same forced-off pattern as compute_statutory/include_base_salary etc.)', (int)$normalRunRow['use_flat_tax_rate'], 0);

    // 2026-09-01, explicit request: "ตอนดึงมาทำรอบหรือเพิ่มรอบใหม่ ให้มี radio เลือกว่า เปิดรอบใหม่ หรืออ้างอิงถึง
    // รอบ" -- PayrollRunModel::mergeIntoExistingRun(), the plain "Add" flow's own equivalent of
    // mergeSupplementalIntoRun() above, sharing the SAME resolveMergeTargetRun()/performRunMerge()
    // mechanics (already exercised extensively above via the Origami-driven caller) -- this section
    // only needs to confirm the NEW entry point itself wires correctly, not re-prove every target-
    // state edge case that shared code already covers.
    echo "=== mergeIntoExistingRun(): manual merge, no Origami/sync involvement at all ===\n";
    $targetRunRes = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'compute_statutory' => false,
        'run_name' => 'MIER_TARGET_' . uniqid(),
        'period_start_date' => '2027-03-01', 'period_end_date' => '2027-03-31', 'payment_date' => '2027-03-31',
    ], $userId, true);
    checkTrue('fixture: target run created' . (empty($targetRunRes['status']) ? " ({$targetRunRes['message']})" : ''), $targetRunRes['status']);
    $targetRunId = $targetRunRes['id'];
    checkTrue('fixture: employee joined into the target run', $runModel->joinEmployees($targetRunId, $compId, [$employeeId], $userId, true)['status']);
    checkTrue('fixture: target run recalculated', $runModel->recalculate($targetRunId, $compId, $userId, true)['status']);
    $targetBefore = current(array_filter($runModel->getDetails($targetRunId, $compId), fn($d) => (int)$d['employee_id'] === $employeeId));
    $targetNetBefore = (float)$targetBefore['net_amount'];

    $sourceRunRes = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'compute_statutory' => false,
        'run_name' => 'MIER_SOURCE_' . uniqid(),
        'period_start_date' => '2027-04-01', 'period_end_date' => '2027-04-30', 'payment_date' => '2027-04-30',
    ], $userId, true);
    checkTrue('fixture: source run created' . (empty($sourceRunRes['status']) ? " ({$sourceRunRes['message']})" : ''), $sourceRunRes['status']);
    $sourceRunId = $sourceRunRes['id'];
    checkTrue('fixture: employee joined into the source run', $runModel->joinEmployees($sourceRunId, $compId, [$employeeId], $userId, true)['status']);
    $manualLineAmount = 800.00;
    $addLineRes = $runModel->addManualLine($sourceRunId, $compId, $employeeId, null, $manualLineAmount, $userId, true, null, 'MIER Test Allowance', 'earning');
    checkTrue('fixture: manual custom line added to the source run' . (empty($addLineRes['status']) ? " ({$addLineRes['message']})" : ''), $addLineRes['status']);

    check('self-merge (source === target) is rejected', $runModel->mergeIntoExistingRun($sourceRunId, $sourceRunId, $compId, $userId, true)['status'], false);
    $cycleBasedSourceRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'MIER_CYCLEBASED_SRC_' . uniqid(),
        'period_start_date' => '2027-05-01', 'period_end_date' => '2027-05-31', 'payment_date' => '2027-05-31',
    ], $userId, true);
    checkTrue('fixture: cycle-based run created (invalid as a merge source)', $cycleBasedSourceRes['status']);
    check('a cycle-linked run cannot be used as a merge source', $runModel->mergeIntoExistingRun($cycleBasedSourceRes['id'], $targetRunId, $compId, $userId, true)['status'], false);

    $mergeRes = $runModel->mergeIntoExistingRun($sourceRunId, $targetRunId, $compId, $userId, true);
    checkTrue('mergeIntoExistingRun() succeeds' . (empty($mergeRes['status']) ? " ({$mergeRes['message']})" : ''), $mergeRes['status']);
    check('merge reports the correct target_run_id', $mergeRes['target_run_id'] ?? null, $targetRunId);
    checkTrue('at least 1 line merged', ($mergeRes['merged_line_count'] ?? 0) >= 1);

    $targetAfter = current(array_filter($runModel->getDetails($targetRunId, $compId), fn($d) => (int)$d['employee_id'] === $employeeId));
    check('target run\'s gross increased by the merged custom allowance (800)', round((float)$targetAfter['net_amount'] - $targetNetBefore, 2), $manualLineAmount);
    $sourceRunAfter = $pdo->query("SELECT status FROM payroll_runs WHERE id = {$sourceRunId}")->fetch(PDO::FETCH_ASSOC);
    check('source run was soft-deleted (status=deleted, never a hard DELETE)', $sourceRunAfter['status'], 'deleted');
    $sourceDetailsAfter = $pdo->query("SELECT COUNT(*) FROM payroll_run_details WHERE run_id = {$sourceRunId}")->fetchColumn();
    checkTrue('source run\'s own payroll_run_details rows are left in place (queryable trace, not cleared)', (int)$sourceDetailsAfter > 0);

    echo "=== mergeIntoExistingRun(): target-state escalation reuses the SAME resolveMergeTargetRun() logic ===\n";
    $target2Res = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'compute_statutory' => false,
        'run_name' => 'MIER_TARGET2_' . uniqid(),
        'period_start_date' => '2027-06-01', 'period_end_date' => '2027-06-30', 'payment_date' => '2027-06-30',
    ], $userId, true);
    $target2Id = $target2Res['id'];
    $runModel->joinEmployees($target2Id, $compId, [$employeeId], $userId, true);
    // An incentive run with zero manual lines is a real calc_status='error' ("no_manual_lines"),
    // not just an empty-but-valid run -- needs at least one line of its own before it can be
    // submitted, same as target/source above.
    $runModel->addManualLine($target2Id, $compId, $employeeId, null, 500.00, $userId, true, null, 'MIER Target2 Baseline', 'earning');
    $runModel->recalculate($target2Id, $compId, $userId, true);
    $target2SubmitRes = $runModel->submit($target2Id, $compId, $userId, true);
    checkTrue('fixture: target 2 submitted' . (empty($target2SubmitRes['status']) ? " ({$target2SubmitRes['message']})" : ''), $target2SubmitRes['status']);

    $source2Res = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'compute_statutory' => false,
        'run_name' => 'MIER_SOURCE2_' . uniqid(),
        'period_start_date' => '2027-07-01', 'period_end_date' => '2027-07-31', 'payment_date' => '2027-07-31',
    ], $userId, true);
    $source2Id = $source2Res['id'];
    $runModel->joinEmployees($source2Id, $compId, [$employeeId], $userId, true);

    $blockedRes = $runModel->mergeIntoExistingRun($source2Id, $target2Id, $compId, $userId, true);
    check('merge into a non-draft target is refused without the opt-in flag', $blockedRes['status'], false);
    checkTrue('refusal correctly flags needs_revert_confirmation', !empty($blockedRes['needs_revert_confirmation']));
    $confirmedRes = $runModel->mergeIntoExistingRun($source2Id, $target2Id, $compId, $userId, true, true, false);
    checkTrue('merge succeeds with allowRevertNonDraftTarget=true (auto-reverts target to draft first)' . (empty($confirmedRes['status']) ? " ({$confirmedRes['message']})" : ''), $confirmedRes['status']);
    $target2AfterMerge = $runModel->get($target2Id, $compId);
    check('target 2 ended up back at draft (auto-reverted before the merge)', $target2AfterMerge['state'], 'draft');

    // 2026-09-01, same-day follow-up, explicit request: "เพิ่มในหน้า Detail ให้ด้วยครับ" -- merge_target_run_id
    // is no longer set-once-at-creation-only; PayrollRunModel::update() now accepts/validates/persists
    // changes to it too, following the exact same "genuine off-schedule run only" rule create() already
    // enforces.
    echo "=== update(): merge_target_run_id editable/clearable from the Detail page's Edit form ===\n";
    $umtSourceRes = $runModel->create($compId, [
        'run_name' => 'UMT_SOURCE_' . uniqid(),
        'period_start_date' => '2027-08-01', 'period_end_date' => '2027-08-31', 'payment_date' => '2027-08-31',
    ], $userId, true);
    checkTrue('fixture: UMT source run created' . (empty($umtSourceRes['status']) ? " ({$umtSourceRes['message']})" : ''), $umtSourceRes['status']);
    $umtSourceId = $umtSourceRes['id'];

    $umtTargetRunName = 'UMT_TARGET_' . uniqid();
    $umtTargetRes = $runModel->create($compId, [
        'run_name' => $umtTargetRunName,
        'period_start_date' => '2027-09-01', 'period_end_date' => '2027-09-30', 'payment_date' => '2027-09-30',
    ], $userId, true);
    checkTrue('fixture: UMT target run created' . (empty($umtTargetRes['status']) ? " ({$umtTargetRes['message']})" : ''), $umtTargetRes['status']);
    $umtTargetId = $umtTargetRes['id'];

    $setRes = $runModel->update($umtSourceId, $compId, ['merge_target_run_id' => $umtTargetId], $userId, true);
    checkTrue('update() sets merge_target_run_id on a genuine off-cycle run' . (empty($setRes['status']) ? " ({$setRes['message']})" : ''), $setRes['status']);
    $umtSourceAfterSet = $runModel->get($umtSourceId, $compId);
    check('merge_target_run_id persisted', (int)$umtSourceAfterSet['merge_target_run_id'], $umtTargetId);
    check('merge_target_run_name resolves via get()\'s own LEFT JOIN', $umtSourceAfterSet['merge_target_run_name'], $umtTargetRunName);
    // 2026-09-02, explicit request: "และถ้ามีการอ้างอิงถึงรอบก็ให้แสดงด้วยครับ" -- the target's own run_code
    // (not just its name) also resolves via the same LEFT JOIN, for the List page's Code column.
    $umtTargetRunCode = $runModel->get($umtTargetId, $compId)['run_code'];
    checkTrue('fixture: target run has a real run_code of its own', $umtTargetRunCode !== null);
    check('merge_target_run_code resolves via get()\'s own LEFT JOIN', $umtSourceAfterSet['merge_target_run_code'], $umtTargetRunCode);

    $selfRefRes = $runModel->update($umtSourceId, $compId, ['merge_target_run_id' => $umtSourceId], $userId, true);
    check('a run cannot be set to merge into itself', $selfRefRes['status'], false);

    $invalidRes = $runModel->update($umtSourceId, $compId, ['merge_target_run_id' => 999999999], $userId, true);
    check('a nonexistent target run id is rejected', $invalidRes['status'], false);

    $clearRes = $runModel->update($umtSourceId, $compId, ['merge_target_run_id' => null], $userId, true);
    checkTrue('merge_target_run_id can be cleared back to null' . (empty($clearRes['status']) ? " ({$clearRes['message']})" : ''), $clearRes['status']);
    $umtSourceAfterClear = $runModel->get($umtSourceId, $compId);
    check('merge_target_run_id is null after clearing', $umtSourceAfterClear['merge_target_run_id'], null);

    $umtCycleLinkedRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'UMT_CYCLELINKED_' . uniqid(),
        'period_start_date' => '2027-10-01', 'period_end_date' => '2027-10-31', 'payment_date' => '2027-10-31',
    ], $userId, true);
    checkTrue('fixture: cycle-linked run created', $umtCycleLinkedRes['status']);
    $cycleLinkedMergeRes = $runModel->update($umtCycleLinkedRes['id'], $compId, ['merge_target_run_id' => $umtTargetId], $userId, true);
    check('a cycle-linked run is refused a merge_target_run_id (same OR-condition update() shares with create())', $cycleLinkedMergeRes['status'], false);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
