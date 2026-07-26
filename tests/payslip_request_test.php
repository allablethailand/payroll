<?php
/**
 * Lightweight verification script for PayslipRequestModel (Mode B: HR-proxied payslip request +
 * its paired approval_requests row) and the sync hook that ApprovalWorkflowController::requestAct()
 * calls after the generic engine acts (exercised here directly against the model, same as
 * tests/approval_workflow_test.php exercises ApprovalRequestModel::act() directly).
 * Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Runs against the real dev DB inside a transaction that is always rolled back.
 * Run with: php tests/payslip_request_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/models/ApprovalWorkflowModel.php';
require_once __DIR__ . '/../app/models/ApprovalRequestModel.php';
require_once __DIR__ . '/../app/models/PayslipRequestModel.php';

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

function makeEmployee(PDO $pdo, int $compId, string $employeeNo, string $employmentDate = '2020-01-01'): int {
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
         :employment_date, 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', 30000, :employment_date2, 'average', 'active',
         1, 1, 0, NULL)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => $employeeNo,
        ':name_th' => 'ทดสอบ', ':surname_th' => $employeeNo, ':name_en' => 'Test', ':surname_en' => $employeeNo,
        ':email' => uniqid() . '@test.local',
        ':employment_date' => $employmentDate, ':employment_date2' => $employmentDate,
    ]);
    return (int)$pdo->lastInsertId();
}

try {
    $compId = 1;
    $adminUserId = 1;
    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of this month')->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of this month')->format('Y-m-d');

    // ---------- Fixtures ----------
    $employeeInRun = makeEmployee($pdo, $compId, 'PR_IN_' . uniqid());
    // Employment starts next month -- outside the main run's period, so PayrollRunModel::recalculate's
    // date-range eligibility excludes them from it (see reports_test.php for the same technique).
    $employeeNotInRun = makeEmployee($pdo, $compId, 'PR_OUT_' . uniqid(), (clone $today)->modify('first day of next month')->format('Y-m-d'));
    $approverEmployee = makeEmployee($pdo, $compId, 'PR_APPROVER_' . uniqid());

    $cycleModel = new PayrollCycleModel();
    $cycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'PR_TEST_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: cycle created', $cycleRes['status']);
    $cycleId = $cycleRes['id'];

    $runModel = new PayrollRunModel($pdo);
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'PR_TEST_RUN_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $periodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: run created', $createRes['status']);
    $runId = $createRes['id'];
    $calcRes = $runModel->recalculate($runId, $compId, $adminUserId, true);
    checkTrue('fixture: run calculated', $calcRes['status']);
    $submitRes = $runModel->submit($runId, $compId, $adminUserId, true);
    checkTrue('fixture: run submitted', $submitRes['status']);
    $approveRunRes = $runModel->approve($runId, $compId, $adminUserId, true);
    checkTrue('fixture: run approved', $approveRunRes['status']);

    $draftRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'PR_TEST_DRAFT_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of next month')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of next month')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of next month')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('fixture: draft run created', $draftRunRes['status']);
    $draftRunId = $draftRunRes['id'];

    // No workflow mapped yet -- create() must fail cleanly. In production this is a fresh
    // connection (own-transaction=true) so the failed INSERT genuinely rolls back; here the whole
    // test file already owns one big transaction, so create() correctly defers the rollback
    // decision to us (its caller) -- we clean up the same way the real rollback would have.
    $noWorkflowRes = (new PayslipRequestModel($pdo))->create($compId, $employeeInRun, $runId, $adminUserId);
    checkFalse('create() fails with no active SLIP_REQUEST_APPROVAL workflow mapped', $noWorkflowRes['status']);
    $pdo->prepare("DELETE FROM payslip_requests WHERE employee_id = :e AND run_id = :r")
        ->execute([':e' => $employeeInRun, ':r' => $runId]);

    $wfModel = new ApprovalWorkflowModel($pdo);
    $wfRes = $wfModel->save($compId, [
        'workflow_name' => 'PR Test Workflow ' . uniqid(),
        'document_type_codes' => ['SLIP_REQUEST_APPROVAL'],
        'status' => 'active',
        'steps' => [['approver_type' => 'user', 'approver_id' => $approverEmployee]],
    ], $adminUserId);
    checkTrue('fixture: SLIP_REQUEST_APPROVAL workflow created', $wfRes['status']);

    $model = new PayslipRequestModel($pdo);
    $approvalModel = new ApprovalRequestModel($pdo);

    // ---------- create() validation ----------
    $r = $model->create($compId, 999999, $runId, $adminUserId);
    checkFalse('unknown employee_id rejected', $r['status']);

    $r = $model->create($compId, $employeeInRun, 999999, $adminUserId);
    checkFalse('unknown run_id rejected', $r['status']);

    $r = $model->create($compId, $employeeInRun, $draftRunId, $adminUserId);
    checkFalse('draft run rejected (not approved/paid/locked)', $r['status']);

    $r = $model->create($compId, $employeeNotInRun, $runId, $adminUserId);
    checkFalse('employee not part of the run rejected', $r['status']);

    // ---------- create() success ----------
    $created = $model->create($compId, $employeeInRun, $runId, $adminUserId);
    checkTrue('create() succeeds once a workflow is mapped', $created['status']);
    $requestId = $created['id'];

    $dup = $model->create($compId, $employeeInRun, $runId, $adminUserId);
    checkFalse('duplicate pending request for same employee+run rejected', $dup['status']);

    $row = $model->get($compId, $requestId);
    checkTrue('get() returns the created row', $row !== null);
    check('status starts pending', $row['status'], 'pending');
    checkTrue('approval_request_id was filled in (not left null)', $row['approval_request_id'] !== null);

    $approvalRow = $approvalModel->get($compId, (int)$row['approval_request_id']);
    check('linked approval_requests.document_type_code', $approvalRow['document_type_code'], 'SLIP_REQUEST_APPROVAL');
    check('linked approval_requests.reference_id points back to payslip_requests.id', (int)$approvalRow['reference_id'], $requestId);

    // ---------- Approve flow + sync hook ----------
    $actRes = $approvalModel->act($compId, (int)$row['approval_request_id'], $approverEmployee, 'approve');
    checkTrue('approver can approve the single-step workflow', $actRes['status']);
    check('single-step approval is terminal', $actRes['request_status'], 'approved');

    // syncFromApprovalStatus() also triggers PayslipDeliveryService::deliverForRequest() as soon
    // as status hits 'approved' (see PayslipDeliveryService::deliverForRequest() -- it does the
    // final status update itself). This dev environment has no LINE/Email credentials configured
    // (see tests/payslip_delivery_test.php), so the deterministic end state here is
    // 'send_failed', not 'approved' -- 'approved' is only a transient intermediate value now.
    $model->syncFromApprovalStatus((int)$row['approval_request_id'], $actRes['request_status'], 'line');
    $afterApprove = $model->get($compId, $requestId);
    check('payslip_requests.status ends at send_failed (delivery attempted, no channel configured in this dev env)', $afterApprove['status'], 'send_failed');
    check('selected_channel synced from the act() payload', $afterApprove['selected_channel'], 'line');

    // ---------- Reject flow ----------
    $created2 = $model->create($compId, $employeeInRun, $runId, $adminUserId);
    checkTrue('second request created (first is no longer pending)', $created2['status']);
    $row2 = $model->get($compId, $created2['id']);
    $rejectRes = $approvalModel->act($compId, (int)$row2['approval_request_id'], $approverEmployee, 'reject', 'not eligible');
    checkTrue('reject action succeeds', $rejectRes['status']);
    $model->syncFromApprovalStatus((int)$row2['approval_request_id'], $rejectRes['request_status']);
    check('payslip_requests.status synced to rejected', $model->get($compId, $created2['id'])['status'], 'rejected');

    // ---------- Cancel flow (only the requester can cancel) ----------
    $created3 = $model->create($compId, $employeeInRun, $runId, $adminUserId);
    checkTrue('third request created', $created3['status']);
    $row3 = $model->get($compId, $created3['id']);
    $cancelRes = $approvalModel->act($compId, (int)$row3['approval_request_id'], $adminUserId, 'cancel');
    checkTrue('requester can cancel', $cancelRes['status']);
    $model->syncFromApprovalStatus((int)$row3['approval_request_id'], $cancelRes['request_status']);
    check('payslip_requests.status synced to cancelled', $model->get($compId, $created3['id'])['status'], 'cancelled');

    // ---------- list()/eligibleRuns()/eligibleEmployeesForRun() ----------
    $list = $model->list($compId);
    check('list() returns all 3 requests', count($list), 3);

    $runs = $model->eligibleRuns($compId);
    $runIds = array_column($runs, 'id');
    checkTrue('eligibleRuns() includes the approved run', in_array($runId, array_map('intval', $runIds), true));
    checkFalse('eligibleRuns() excludes the draft run', in_array($draftRunId, array_map('intval', $runIds), true));

    $eligibleEmployees = $model->eligibleEmployeesForRun($compId, $runId);
    $empIds = array_map('intval', array_column($eligibleEmployees, 'id'));
    checkTrue('eligibleEmployeesForRun() includes the employee who is in the run', in_array($employeeInRun, $empIds, true));
    checkFalse('eligibleEmployeesForRun() excludes the employee who is not in the run', in_array($employeeNotInRun, $empIds, true));

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
