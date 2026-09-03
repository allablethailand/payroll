<?php
/**
 * Lightweight verification script for the Cash Payment Summary feature (2026-08-31, explicit
 * request: "ถ้าพนักงานรับเงินสด ต้องไม่ดึงไปใน Report Payroll ขึ้นธนาคาร แต่แยก Report ตามแยก ว่าจ่ายเงินสด
 * เท่าไหร่ โอนผ่านธนาคารเท่าไหร่ และสามารถใส่ Status ว่าจ่ายแล้ว") -- PayrollRunCashPaymentModel (the
 * interactive per-employee status tracker) + CashPaymentSummaryReport (the export). Not PHPUnit --
 * see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a transaction
 * that is always rolled back.
 * Run with: php tests/cash_payment_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/models/PayrollRunCashPaymentModel.php';
require_once __DIR__ . '/../app/models/PayrollReportDataModel.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/services/reports/ReportRegistry.php';
require_once __DIR__ . '/../app/services/reports/LocalizedException.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/controllers/PayrollRunCashPaymentController.php';
require_once __DIR__ . '/../app/helpers/helpers.php';

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
    $compId = 1;
    $adminUserId = 1;

    // Same isolation as tests/reports_test.php/tests/payroll_run_test.php -- comp_id=1 is the real,
    // shared dev DB (see feedback_dev_db_shared_state_test_fragility). recalculate() pulls every
    // active employee whose employment window overlaps the run's period, so leftover real/other-test
    // rows would otherwise leak into this run's employee_count.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL")
        ->execute([':comp_id' => $compId]);
    $pdo->prepare("UPDATE `approval_workflows` SET status = 'inactive'
        WHERE comp_id = :comp_id AND status = 'active'
          AND id IN (SELECT workflow_id FROM `approval_workflow_document_types` WHERE document_type_code = 'PAYROLL_RUN_APPROVAL')")
        ->execute([':comp_id' => $compId]);

    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of this month')->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of this month')->format('Y-m-d');

    $cycleModel = new PayrollCycleModel();
    $cycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'CASH_TEST_CYCLE_' . uniqid(),
        'payroll_frequency' => 'monthly', 'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: cycle created', $cycleRes['status']);
    $cycleId = $cycleRes['id'];

    // 2026-09-02, follow-up: payment_type (legacy enum) dropped -- fixture now resolves a real
    // payment_method_id via master_payment_methods.code ('cash'/'transfer') instead of writing the
    // old 'cash'/'bank' string directly.
    function resolvePaymentMethodIdByCode(PDO $pdo, string $code): int {
        $stmt = $pdo->prepare("SELECT id FROM `master_payment_methods` WHERE code = :code");
        $stmt->execute([':code' => $code]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new RuntimeException("master_payment_methods code '{$code}' not found -- seed missing?");
        }
        return (int)$id;
    }

    function insertFixtureEmployee(PDO $pdo, int $compId, string $paymentMethodCode, float $baseSalary): int {
        $encTax = EncryptionService::encrypt('1' . substr((string)rand(100000000000, 999999999999), 0, 12));
        $paymentMethodId = resolvePaymentMethodIdByCode($pdo, $paymentMethodCode);
        $stmt = $pdo->prepare("INSERT INTO `employees`
            (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
             tax_id_no, key_version, personal_email, mobile_no, address_line_1_register, address_line_1_contact,
             emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
             employment_date, employment_status, employment_type, workforce_type, record_time_method,
             payment_method_id, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt, department_id)
            VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
             :tax_id_no, :key_version, :email, '0800000000', 'Test Address', 'Test Address',
             'Emergency', 'Contact', 'friend', '0899999999',
             '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
             :payment_method_id, 'monthly', :base_salary, '2020-01-01', 'average', 'active',
             0, 0, 0, NULL)");
        $stmt->execute([
            ':comp_id' => $compId, ':employee_no' => 'CASH_TEST_' . uniqid(),
            ':name_th' => 'ทดสอบ', ':surname_th' => 'เงินสด', ':name_en' => 'Test', ':surname_en' => 'Cash',
            ':tax_id_no' => $encTax['value'], ':key_version' => $encTax['key_version'],
            ':email' => uniqid() . '@test.local', ':payment_method_id' => $paymentMethodId, ':base_salary' => $baseSalary,
        ]);
        return (int)$pdo->lastInsertId();
    }

    $cashEmployeeId = insertFixtureEmployee($pdo, $compId, 'cash', 20000);
    $bankEmployeeId = insertFixtureEmployee($pdo, $compId, 'transfer', 30000);
    checkTrue('fixture: cash-paying employee created', $cashEmployeeId > 0);
    checkTrue('fixture: bank-paying employee created', $bankEmployeeId > 0);

    $runModel = new PayrollRunModel($pdo);
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'CASH_TEST_RUN_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $periodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: run created', $createRes['status']);
    $runId = $createRes['id'];
    $calcRes = $runModel->recalculate($runId, $compId, $adminUserId, true);
    checkTrue('fixture: run calculated', $calcRes['status']);
    check('fixture: employee_count is 2', $calcRes['employee_count'], 2);

    echo "=== PayrollRunCashPaymentModel: state gate ===\n";
    $cashModel = new PayrollRunCashPaymentModel($pdo);
    try {
        $cashModel->listForRun($runId, $compId);
        checkTrue('listForRun() on a DRAFT run should have thrown', false);
    } catch (LocalizedException $e) {
        checkTrue('listForRun() refuses a draft run (same ALLOWED_STATES gate as other payment reports)', true);
    }

    $submitRes = $runModel->submit($runId, $compId, $adminUserId, true);
    checkTrue('fixture: run submitted', $submitRes['status']);
    $approveRes = $runModel->approve($runId, $compId, $adminUserId, true);
    checkTrue('fixture: run approved', $approveRes['status']);

    echo "=== PayrollRunCashPaymentModel::listForRun() (ensureRowsForRun) ===\n";
    $listed = $cashModel->listForRun($runId, $compId);
    checkTrue('listForRun() succeeds once the run is approved', is_array($listed['rows']));
    check('exactly 1 cash payment row (only the cash-paying employee)', count($listed['rows']), 1);
    $cashRow = $listed['rows'][0];
    check('the tracked row is the cash-paying employee', (int)$cashRow['employee_id'], $cashEmployeeId);
    check('status defaults to unpaid', $cashRow['status'], 'unpaid');
    checkTrue('paid_at is null before marking paid', $cashRow['paid_at'] === null);
    $realNetAmount = (float)$pdo->query("SELECT net_amount FROM payroll_run_details WHERE run_id = {$runId} AND employee_id = {$cashEmployeeId}")->fetchColumn();
    check('amount snapshot equals the run\'s own real net_amount for that employee', (float)$cashRow['amount'], $realNetAmount);
    checkTrue('the snapshot amount is genuinely > 0, not just matching a zero by coincidence', $realNetAmount > 0);
    checkTrue('total_cash > 0 (the cash employee\'s own net pay)', (float)$listed['total_cash'] > 0);
    checkTrue('total_bank > 0 (the bank employee\'s own net pay)', (float)$listed['total_bank'] > 0);

    $secondCall = $cashModel->listForRun($runId, $compId);
    check('a second listForRun() call is idempotent -- still exactly 1 row (INSERT IGNORE, no duplicate)', count($secondCall['rows']), 1);
    check('the same row id is returned, not a new one', (int)$secondCall['rows'][0]['id'], (int)$cashRow['id']);

    echo "=== PayrollRunCashPaymentModel::setStatus() ===\n";
    $markPaidOk = $cashModel->setStatus((int)$cashRow['id'], $compId, 'paid', $adminUserId);
    checkTrue('setStatus(paid) succeeds', $markPaidOk);
    $afterPaid = $cashModel->listForRun($runId, $compId)['rows'][0];
    check('status is now paid', $afterPaid['status'], 'paid');
    checkTrue('paid_at is now populated', !empty($afterPaid['paid_at']));
    check('paid_by recorded', (int)$afterPaid['paid_by'], $adminUserId);

    $markUnpaidOk = $cashModel->setStatus((int)$cashRow['id'], $compId, 'unpaid', $adminUserId);
    checkTrue('setStatus(unpaid) succeeds (revert)', $markUnpaidOk);
    $afterUnpaid = $cashModel->listForRun($runId, $compId)['rows'][0];
    check('status reverted to unpaid', $afterUnpaid['status'], 'unpaid');
    checkTrue('paid_at cleared on revert', $afterUnpaid['paid_at'] === null);
    checkTrue('paid_by cleared on revert', $afterUnpaid['paid_by'] === null);

    checkFalse('setStatus() with a wrong comp_id is refused (cross-company isolation)', $cashModel->setStatus((int)$cashRow['id'], $compId + 999, 'paid', $adminUserId));
    try {
        $cashModel->setStatus((int)$cashRow['id'], $compId, 'bogus', $adminUserId);
        checkTrue('setStatus() with an invalid status string should have thrown', false);
    } catch (LocalizedException $e) {
        checkTrue('setStatus() rejects an invalid status string', true);
    }

    echo "=== CashPaymentSummaryReport (export) ===\n";
    $report = ReportRegistry::get('CASH_PAYMENT_SUMMARY');
    checkTrue('CASH_PAYMENT_SUMMARY is registered', $report !== null);
    if ($report) {
        check('reportType is payment', $report->reportType(), 'payment');
        $result = $report->generate(['comp_id' => $compId, 'run_id' => $runId], 'excel');
        checkTrue('generate() returns real Excel bytes', strlen($result['content']) > 1000);
        check('mime_type is xlsx', $result['mime_type'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        try {
            $report->generate(['comp_id' => $compId, 'run_id' => 999999999], 'excel');
            checkTrue('generate() on a nonexistent run should have thrown', false);
        } catch (LocalizedException $e) {
            check('throws run_not_found for a nonexistent run', $e->getErrorKey(), 'run_not_found');
        }
    }

    // ---------- Real bug found and fixed (explicit report: "Tab การจ่ายเงินสด...ไม่มีข้อมูล แต่ในตาราง
    // ขึ้น head และ แถวเปล่ามา ดูเหมือน error") -- PayrollRunCashPaymentController's list()/setStatus()
    // both called $this->requirePermission(...), a method that never actually existed anywhere
    // reachable (not on this controller, not on the base Controller class -- same class of bug
    // already found once this session in PayrollSyncController). Every real request fatal-errored
    // before ever reaching the model, so the AJAX success callback never fired and
    // #runCashTableBody's JS-populated <tbody> silently stayed empty under its own correctly-static
    // <thead> -- exactly the reported symptom. Fixed by adding the same standard private
    // requirePermission() every other controller already has. ----------
    echo "=== PayrollRunCashPaymentController::requirePermission() (real pre-existing bug fix) ===\n";
    $_SESSION['user'] = ['employee_id' => $adminUserId, 'role' => 'admin', 'company_id' => $compId];
    $cashController = new PayrollRunCashPaymentController();
    $refMethod = new ReflectionMethod(PayrollRunCashPaymentController::class, 'requirePermission');
    $refMethod->setAccessible(true);
    // Before this fix, this call would have been a hard PHP fatal error ("Call to undefined
    // method") -- simply completing without throwing already proves the method now exists.
    $allowed = $refMethod->invoke($cashController, 'payroll_run_cash_payment.view');
    checkTrue('requirePermission() now exists and returns true for an admin session (previously a fatal error)', $allowed);

    // ---------- The user's own reported scenario: a run with ZERO cash-paying employees ----------
    // (everyone on 'bank') must show a clean empty result, never an error/blank-looking table.
    echo "=== listForRun() with zero cash-paying employees -- clean empty result, not an error ===\n";
    $bankOnlyEmployeeId = insertFixtureEmployee($pdo, $compId, 'transfer', 25000);
    checkTrue('fixture: 2nd bank-only employee created', $bankOnlyEmployeeId > 0);
    $bankOnlyRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'CASH_TEST_BANK_ONLY_RUN_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +1 month')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +1 month')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +1 month')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('fixture: bank-only run created' . (empty($bankOnlyRunRes['status']) ? " ({$bankOnlyRunRes['message']})" : ''), $bankOnlyRunRes['status']);
    $bankOnlyRunId = $bankOnlyRunRes['id'];
    // Deliberately EXCLUDE the earlier cash-paying employee from this run's own roster -- delete()
    // it from the run via exclusion isn't available pre-recalculate, so instead soft-delete it for
    // just this section (the outer isolation step already did this for every OTHER real dev-DB
    // employee; this one is this file's own fixture, safe to touch, restored implicitly by rollback).
    $pdo->prepare("UPDATE employees SET deleted_at = NOW() WHERE id = :id")->execute([':id' => $cashEmployeeId]);
    $bankOnlyCalcRes = $runModel->recalculate($bankOnlyRunId, $compId, $adminUserId, true);
    checkTrue('fixture: bank-only run calculated', $bankOnlyCalcRes['status']);
    check('fixture: employee_count is 2 (both bank-only employees, cash one excluded)', $bankOnlyCalcRes['employee_count'], 2);
    $bankOnlySubmitRes = $runModel->submit($bankOnlyRunId, $compId, $adminUserId, true);
    checkTrue('fixture: bank-only run submitted' . (empty($bankOnlySubmitRes['status']) ? " ({$bankOnlySubmitRes['message']})" : ''), $bankOnlySubmitRes['status']);
    $bankOnlyApproveRes = $runModel->approve($bankOnlyRunId, $compId, $adminUserId, true);
    checkTrue('fixture: bank-only run approved' . (empty($bankOnlyApproveRes['status']) ? " ({$bankOnlyApproveRes['message']})" : ''), $bankOnlyApproveRes['status']);

    $bankOnlyResult = $cashModel->listForRun($bankOnlyRunId, $compId);
    check('rows is an empty array (not null, not an error)', $bankOnlyResult['rows'], []);
    check('total_cash is 0.0', $bankOnlyResult['total_cash'], 0.0);
    checkTrue('total_bank reflects both bank-only employees\' net pay', $bankOnlyResult['total_bank'] > 0);
    // json_encode() of a genuinely empty PHP array is `[]`, not `{}` -- confirms the frontend's own
    // `(data.rows || []).map(...)` sees a real (empty) array, not an object it could choke on.
    check('rows JSON-encodes as an array literal ([]), not an object ({})', json_encode($bankOnlyResult['rows']), '[]');

    // ==================================================================================================
    // 2026-09-02, real bug found and fixed (flagged during a post-feature review, not guessed): a cash
    // employee's tracked amount used the plain net_amount column instead of net_amount_due (net_amount
    // minus whatever a PRIOR payroll_run_payment_events row already recorded as disbursed this run --
    // see tests/payroll_run_payment_events_test.php for the same *_amount_due delta mechanism already
    // proven there for BankTransferFileReport). Reopening an already-partially-paid run and then
    // viewing the Cash Payments tab for the FIRST time (ensureRowsForRun()'s own INSERT IGNORE means a
    // row, once created, is never re-synced -- so the bug specifically mattered for a row not yet
    // created) would have tracked the FULL amount a second time instead of just what's actually still
    // owed. Simulates a prior payment_events row directly (same technique/shape
    // tests/payroll_run_payment_events_test.php's own fixture uses) rather than a full reopen+merge
    // dance, since this test targets PayrollRunCashPaymentModel's own read, not markPaid()/reopen()
    // themselves (already covered by that other file).
    // ==================================================================================================
    echo "=== net_amount_due fix: a cash employee's tracked amount reflects a prior payment_events delta ===\n";
    $deltaEmpId = insertFixtureEmployee($pdo, $compId, 'cash', 20000);
    $deltaCycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'CASH_DELTA_TEST_CYCLE_' . uniqid(),
        'payroll_frequency' => 'monthly', 'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    $deltaRunRes = $runModel->create($compId, [
        'cycle_id' => $deltaCycleRes['id'], 'run_name' => 'CASH_DELTA_TEST_RUN_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $periodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: delta-test run created' . (empty($deltaRunRes['status']) ? " ({$deltaRunRes['message']})" : ''), $deltaRunRes['status']);
    $deltaRunId = $deltaRunRes['id'];
    $deltaCalcRes = $runModel->recalculate($deltaRunId, $compId, $adminUserId, true);
    checkTrue('fixture: delta-test run calculated' . (empty($deltaCalcRes['status']) ? " ({$deltaCalcRes['message']})" : ''), $deltaCalcRes['status']);
    checkTrue('fixture: submitted', $runModel->submit($deltaRunId, $compId, $adminUserId, true)['status']);
    checkTrue('fixture: approved', $runModel->approve($deltaRunId, $compId, $adminUserId, true)['status']);

    $deltaDetailsBefore = (new PayrollReportDataModel())->getRunDetails($deltaRunId);
    $deltaRowBefore = current(array_filter($deltaDetailsBefore, fn($d) => (int)$d['employee_id'] === $deltaEmpId));
    $fullNetAmount = (float)$deltaRowBefore['net_amount'];
    checkTrue('fixture: employee has real positive net pay to partially pay off', $fullNetAmount > 0);

    // Simulate a PRIOR payment cycle having already disbursed part of this employee's net pay (same
    // shape a real markPaid() call would have written).
    $alreadyPaid = round($fullNetAmount * 0.4, 2);
    $remainingDue = round($fullNetAmount - $alreadyPaid, 2);
    $pdo->prepare("INSERT INTO `payroll_run_payment_events` (run_id, employee_id, gross_amount_paid, deduction_amount_paid, net_amount_paid, payment_method, payment_reference, paid_by)
        VALUES (:run_id, :employee_id, 0, 0, :net_paid, 'cash', 'PRIOR_CYCLE_TEST', :paid_by)")
        ->execute([':run_id' => $deltaRunId, ':employee_id' => $deltaEmpId, ':net_paid' => $alreadyPaid, ':paid_by' => $adminUserId]);

    $deltaDetailsAfter = (new PayrollReportDataModel())->getRunDetails($deltaRunId);
    $deltaRowAfter = current(array_filter($deltaDetailsAfter, fn($d) => (int)$d['employee_id'] === $deltaEmpId));
    check('fixture: net_amount_due now reflects only the remaining delta, not the full amount', round((float)$deltaRowAfter['net_amount_due'], 2), $remainingDue);

    // First-ever call to listForRun() for this run -- ensureRowsForRun()'s own INSERT IGNORE means
    // this is the ONE moment the row's amount gets decided, from whatever net_amount_due is right now.
    $deltaCashModel = new PayrollRunCashPaymentModel($pdo);
    $deltaResult = $deltaCashModel->listForRun($deltaRunId, $compId);
    $deltaRow = current(array_filter($deltaResult['rows'], fn($r) => (int)$r['employee_id'] === $deltaEmpId));
    checkTrue('cash-payment row was created for this employee', $deltaRow !== false);
    check('*** the core fix *** tracked cash amount is the REMAINING delta, not the full net_amount (would have double-counted the already-paid 40% before this fix)', round((float)$deltaRow['amount'], 2), $remainingDue);
    check('*** the core fix *** total_cash on the summary also reflects the delta, not the full amount', round($deltaResult['total_cash'], 2), $remainingDue);

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
