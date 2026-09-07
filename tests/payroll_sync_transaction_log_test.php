<?php
/**
 * Backlog Phase 9->10, T051 (2026-09-04): PayrollSyncTransactionLogModel, wired into
 * PayrollRunModel::approve()/revert()/reopen(). Not PHPUnit -- see tests/statutory_engine_test.php
 * for why. Runs against the real dev DB inside a transaction that is always rolled back -- uses its
 * own fresh company (never touches comp_id=1's live data, see
 * feedback_dev_db_shared_state_test_fragility). Mirrors the fresh-company "real
 * PayrollRunModel::recalculate() through a real run" pattern established by
 * tests/statutory_master_clone_payroll_run_test.php (T048), and the sync-process/payroll_sync_items
 * fixture shape used by tests/payroll_run_test.php's own T017 section (comp_id=1, not reused here
 * directly since a fresh company needs its own).
 * Run with: php tests/payroll_sync_transaction_log_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';
require_once __DIR__ . '/../app/models/PayrollSyncTransactionLogModel.php';
require_once __DIR__ . '/../app/models/PayrollReportDataModel.php';

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

function makeCompany(PDO $pdo, string $countryCode = 'TH'): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

try {
    $adminUserId = 1;
    $compId = makeCompany($pdo, 'TH');

    // ---- Fixture: 1 employee, 1 monthly cycle, 1 sync process pulled into 1 draft run ----
    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, employment_date, employment_status, employment_type,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, ot_eligible, is_payroll_participant)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'T051', 'Test', 'T051', '1990-01-01', 'Thai',
         :email, '0810000001', '2020-01-01', 'permanent', 'full_time',
         'monthly', 30000, '2020-01-01', 'average', 'active',
         0, 0, 0, 1, 1)");
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => 'T051_' . uniqid(), ':email' => uniqid() . '@test.local']);
    $employeeId = (int)$pdo->lastInsertId();

    $cycleModel = new PayrollCycleModel();
    $cycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'T051_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: cycle created' . (empty($cycleRes['status']) ? " ({$cycleRes['message']})" : ''), $cycleRes['status']);
    $cycleId = $cycleRes['id'];

    $insSyncProc = $pdo->prepare("INSERT INTO payroll_sync_processes
        (comp_id, origami_process_id, process_no, origami_comp_code, origami_comp_name, frequency_type, schema_version, raw_payload)
        VALUES (:comp_id, :origami_process_id, :process_no, 'T051CODE', 'T051 Co.', 'monthly', 1, '{}')");
    $insSyncProc->execute([':comp_id' => $compId, ':origami_process_id' => random_int(1000000, 9999999), ':process_no' => 'T051SYNC_' . uniqid()]);
    $syncProcessId = (int)$pdo->lastInsertId();

    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of this month')->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of this month')->format('Y-m-d');

    $runModel = new PayrollRunModel($pdo);
    $runRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'T051_RUN_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $periodEnd,
        'sync_process_id' => $syncProcessId,
    ], $adminUserId, true);
    checkTrue('fixture: sync-based run created' . (empty($runRes['status']) ? " ({$runRes['message']})" : ''), $runRes['status']);
    $runId = $runRes['id'];

    $pdo->prepare("INSERT INTO payroll_sync_items (process_id, employee_id, payroll_code, mapping_status, item_values)
        VALUES (:process_id, :employee_id, 'T051_MAPPED', 'mapped', :item_values)")
        ->execute([
            ':process_id' => $syncProcessId, ':employee_id' => $employeeId,
            ':item_values' => json_encode([
                // DILIGENCE resolves via SyncPayResolver's RULE_DRIVEN_ITEM_DEFS ("structured item
                // def") path, not the generic catalog-match/CUSTOM: path -- that path synthesizes its
                // own descriptive note (sync_{event}_{value}{unit}) rather than passing Origami's raw
                // remark through (confirmed by reading SyncPayResolver.php directly; only the generic
                // item_values/catalog-match/CUSTOM: fallback path preserves $first['remark']). The
                // 2nd item below (no catalog match, falls to the CUSTOM: fallback) is what actually
                // proves real-remark passthrough into the log.
                ['item_id' => 901, 'item_code' => 'DILIGENCE', 'item_name' => 'Diligence Allowance', 'item_type' => 'INCOME', 'unit_type' => null, 'value' => 750.0, 'remark' => null],
                ['item_id' => 902, 'item_code' => 'T051_CUSTOM_ITEM', 'item_name' => 'T051 Custom Bonus', 'item_type' => 'INCOME', 'unit_type' => null, 'value' => 321.0, 'remark' => 'T051 real remark passthrough'],
            ], JSON_UNESCAPED_UNICODE),
        ]);

    // Diligence catalog row, opt-in via source_event_code (T013's own mechanism).
    $pedTypeModel = new PayrollEarningDeductionTypeModel($pdo);
    $diligenceSave = $pedTypeModel->save($compId, [
        'item_code' => 'T051_DILIGENCE', 'item_name_th' => 'เบี้ยขยัน T051', 'item_name_en' => 'T051 Diligence',
        'item_type' => 'earning', 'calculation_method' => 'manual_entry', 'source_event_code' => 'diligence',
        'tax_treatment' => 'taxable', 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: Diligence catalog row (opt-in) created' . (empty($diligenceSave['status']) ? " ({$diligenceSave['message']})" : ''), $diligenceSave['status']);

    $syncLogModel = new PayrollSyncTransactionLogModel($pdo);
    $reportDataModel = new PayrollReportDataModel($pdo);

    echo "=== recalculate() resolves the sync-derived Diligence line (draft run, log not written yet) ===\n";
    $recalc = $runModel->recalculate($runId, $compId, $adminUserId, true);
    checkTrue('recalculate() succeeds' . (empty($recalc['status']) ? " ({$recalc['message']})" : ''), $recalc['status']);
    $preApproveLog = $syncLogModel->listForEmployee($compId, $employeeId);
    check('log has ZERO rows while the run is still draft (only written at approve() time)', count($preApproveLog), 0);

    echo "=== submit() + approve() -- log is written from the ALREADY-PERSISTED breakdown ===\n";
    $submitRes = $runModel->submit($runId, $compId, $adminUserId, true);
    checkTrue('submit() succeeds' . (empty($submitRes['status']) ? " ({$submitRes['message']})" : ''), $submitRes['status']);
    $approveRes = $runModel->approve($runId, $compId, $adminUserId, true);
    checkTrue('approve() succeeds' . (empty($approveRes['status']) ? " ({$approveRes['message']})" : ''), $approveRes['status']);

    $logRows = $syncLogModel->listForEmployee($compId, $employeeId);
    check('exactly 2 sync-derived lines logged (Diligence + the custom item) -- no other sync/event-linked items on this run', count($logRows), 2);
    $diligenceLog = current(array_filter($logRows, fn($r) => $r['item_code'] === 'T051_DILIGENCE')) ?: [];
    checkTrue('the Diligence line was logged', !empty($diligenceLog));
    check('logged item_type = earning', $diligenceLog['item_type'] ?? null, 'earning');
    check('logged amount = face value 750.00', (float)($diligenceLog['amount'] ?? 0), 750.0);
    check('logged pay_period_start matches the run\'s own period', (string)($diligenceLog['pay_period_start'] ?? ''), $periodStart);

    $customLog = current(array_filter($logRows, fn($r) => strpos((string)$r['item_code'], 'CUSTOM:') === 0)) ?: [];
    checkTrue('the CUSTOM: fallback line (no catalog match) was also logged', !empty($customLog));
    check('CUSTOM: line amount = face value 321.00', (float)($customLog['amount'] ?? 0), 321.0);
    check('CUSTOM: line remark carries Origami\'s own real per-line remark (the generic/catalog-match path, unlike Diligence\'s structured-def path)', $customLog['remark'] ?? null, 'T051 real remark passthrough');
    check('CUSTOM: line raw_item_code preserves Origami\'s own raw item_code', $customLog['raw_item_code'] ?? null, 'T051_CUSTOM_ITEM');

    echo "=== revert() away from approved deletes the log rows (no longer a settled fact) ===\n";
    $revertRes = $runModel->revert($runId, $compId, $adminUserId, true, 'T051 revert test');
    checkTrue('revert() succeeds' . (empty($revertRes['status']) ? " ({$revertRes['message']})" : ''), $revertRes['status']);
    $afterRevertLog = $syncLogModel->listForEmployee($compId, $employeeId);
    check('log rows are gone after revert() away from approved', count($afterRevertLog), 0);

    echo "=== re-approving after revert writes fresh rows again (naturally correct, no special-casing needed) ===\n";
    $reapproveRes = $runModel->approve($runId, $compId, $adminUserId, true);
    checkTrue('re-approve() succeeds' . (empty($reapproveRes['status']) ? " ({$reapproveRes['message']})" : ''), $reapproveRes['status']);
    $afterReapproveLog = $syncLogModel->listForEmployee($compId, $employeeId);
    check('log has 2 rows again after re-approval', count($afterReapproveLog), 2);

    echo "=== markPaid() -> reopen() also deletes the log rows (paid/locked are downstream of approved) ===\n";
    $markPaidRes = $runModel->markPaid($runId, $compId, $adminUserId, true, ['payment_method' => 'bank_transfer']);
    checkTrue('markPaid() succeeds' . (empty($markPaidRes['status']) ? " ({$markPaidRes['message']})" : ''), $markPaidRes['status']);
    $afterPaidLog = $syncLogModel->listForEmployee($compId, $employeeId);
    check('log still has its 2 rows after markPaid() (breakdown/approval unchanged by paying)', count($afterPaidLog), 2);

    $reopenRes = $runModel->reopen($runId, $compId, $adminUserId, true, 'T051 reopen test');
    checkTrue('reopen() succeeds' . (empty($reopenRes['status']) ? " ({$reopenRes['message']})" : ''), $reopenRes['status']);
    $afterReopenLog = $syncLogModel->listForEmployee($compId, $employeeId);
    check('log rows are gone after reopen() back to draft', count($afterReopenLog), 0);

    echo "=== scheduledItemOccurrences() employee_id filter (additive, company-wide call site unaffected) ===\n";
    $insOccProc = $pdo->prepare("INSERT INTO payroll_sync_processes
        (comp_id, origami_process_id, process_no, origami_comp_code, origami_comp_name, frequency_type, schema_version, raw_payload)
        VALUES (:comp_id, :origami_process_id, :process_no, 'T051OCC', 'T051 Occ Co.', 'monthly', 1, '{}')");
    $insOccProc->execute([':comp_id' => $compId, ':origami_process_id' => random_int(1000000, 9999999), ':process_no' => 'T051OCCPROC_' . uniqid()]);
    $occProcessId = (int)$pdo->lastInsertId();

    // A second employee whose occurrence rows must NOT leak into employee #1's filtered result.
    $insEmp2 = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, employment_date, employment_status, employment_type,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, ot_eligible, is_payroll_participant)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ2', 'T051', 'Test2', 'T051', '1990-01-01', 'Thai',
         :email, '0810000002', '2020-01-01', 'permanent', 'full_time',
         'monthly', 30000, '2020-01-01', 'average', 'active',
         0, 0, 0, 1, 1)");
    $insEmp2->execute([':comp_id' => $compId, ':employee_no' => 'T051B_' . uniqid(), ':email' => uniqid() . '@test.local']);
    $employeeId2 = (int)$pdo->lastInsertId();

    $insOcc = $pdo->prepare("INSERT INTO payroll_sync_item_occurrences
        (process_id, employee_id, item_code, item_ref_code, occurrence_code, installment_no, amount, applied_at)
        VALUES (:process_id, :employee_id, :item_code, :item_ref_code, :occurrence_code, :installment_no, :amount, :applied_at)");
    $insOcc->execute([':process_id' => $occProcessId, ':employee_id' => $employeeId, ':item_code' => 'STUDENT_LOAN', ':item_ref_code' => 'REF-001', ':occurrence_code' => 'OCC-001', ':installment_no' => 1, ':amount' => 600.0, ':applied_at' => date('Y-m-d H:i:s')]);
    $insOcc->execute([':process_id' => $occProcessId, ':employee_id' => $employeeId2, ':item_code' => 'STUDENT_LOAN', ':item_ref_code' => 'REF-002', ':occurrence_code' => 'OCC-002', ':installment_no' => 1, ':amount' => 400.0, ':applied_at' => date('Y-m-d H:i:s')]);

    $unfiltered = $reportDataModel->scheduledItemOccurrences($compId, null, null);
    checkTrue('company-wide call (no employee_id, existing reconciliation-report call shape) returns both rows', count($unfiltered) >= 2);

    $filteredForEmp1 = $reportDataModel->scheduledItemOccurrences($compId, null, null, $employeeId);
    check('employee_id filter returns exactly 1 row for employee #1', count($filteredForEmp1), 1);
    check('the returned row really belongs to employee #1', $filteredForEmp1[0]['item_ref_code'] ?? null, 'REF-001');

    $filteredForEmp2 = $reportDataModel->scheduledItemOccurrences($compId, null, null, $employeeId2);
    check('employee_id filter returns exactly 1 row for employee #2 (no cross-employee leakage)', count($filteredForEmp2), 1);
    check('the returned row really belongs to employee #2', $filteredForEmp2[0]['item_ref_code'] ?? null, 'REF-002');

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
