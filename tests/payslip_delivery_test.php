<?php
/**
 * Lightweight verification script for the Payslip Distribution delivery engine
 * (NotificationChannelRegistry + PayslipDeliveryService). Not PHPUnit -- see
 * tests/statutory_engine_test.php for why.
 *
 * This dev environment has no real SMTP/LINE/Telegram credentials configured (MAIL_HOST,
 * LINE_CHANNEL_ACCESS_TOKEN, TELEGRAM_BOT_TOKEN are all empty in .env), so this test can only
 * verify the ORCHESTRATION (fallback chain order, logging, scope filtering, status transitions)
 * -- not an actual successful send on any channel. That's expected, not a gap in this script.
 *
 * Runs against the real dev DB inside a transaction that is always rolled back.
 * Run with: php tests/payslip_delivery_test.php
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
require_once __DIR__ . '/../app/models/PayslipDistributionSettingModel.php';
require_once __DIR__ . '/../app/services/notifications/NotificationChannelRegistry.php';
require_once __DIR__ . '/../app/services/PayslipDeliveryService.php';

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

function makeEmployee(PDO $pdo, int $compId, string $employeeNo, ?int $departmentId = null, ?string $employmentStatus = 'permanent'): int {
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
         '2020-01-01', :employment_status, 'full_time', 'office', 'manual',
         'bank', 'monthly', 30000, '2020-01-01', 'average', 'active',
         1, 1, 0, :department_id)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => $employeeNo,
        ':name_th' => 'ทดสอบ', ':surname_th' => $employeeNo, ':name_en' => 'Test', ':surname_en' => $employeeNo,
        ':email' => uniqid() . '@test.local', ':employment_status' => $employmentStatus, ':department_id' => $departmentId,
    ]);
    return (int)$pdo->lastInsertId();
}

try {
    $compId = 1;
    $adminUserId = 1;
    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of this month')->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of this month')->format('Y-m-d');

    echo "=== NotificationChannelRegistry ===\n";
    $email = NotificationChannelRegistry::get('email');
    $line = NotificationChannelRegistry::get('line');
    $telegram = NotificationChannelRegistry::get('telegram');
    checkTrue('email channel registered', $email !== null);
    checkTrue('line channel registered', $line !== null);
    checkTrue('telegram channel registered', $telegram !== null);
    check('unknown channel returns null', NotificationChannelRegistry::get('fax'), null);
    // This dev environment has no real credentials configured -- these must be false, not throw.
    checkFalse('email is not configured (no MAIL_HOST in this dev .env)', $email->isConfigured());
    checkFalse('line is not configured (stub, no token)', $line->isConfigured());
    checkFalse('telegram is not configured (stub, no token)', $telegram->isConfigured());

    // ---------- Fixtures ----------
    $deptStmt = $pdo->prepare("INSERT INTO structure_departments (id, comp_id, department_code, department_name_th, department_name_en, status)
        VALUES (90101, :comp_id, 'PDV_TEST', 'แผนกทดสอบ', 'Test Dept', 'active')");
    $deptStmt->execute([':comp_id' => $compId]);

    $employeeInScope = makeEmployee($pdo, $compId, 'PDV_IN_' . uniqid(), 90101);
    $employeeOutOfScope = makeEmployee($pdo, $compId, 'PDV_OUT_' . uniqid(), null);

    $cycleModel = new PayrollCycleModel();
    $cycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'PDV_TEST_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: cycle created', $cycleRes['status']);
    $cycleId = $cycleRes['id'];

    $runModel = new PayrollRunModel($pdo);
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'PDV_TEST_RUN_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $periodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: run created', $createRes['status']);
    $runId = $createRes['id'];
    check('fixture: both employees in the run', $runModel->recalculate($runId, $compId, $adminUserId, true)['employee_count'], 2);
    checkTrue('fixture: run submitted', $runModel->submit($runId, $compId, $adminUserId, true)['status']);
    checkTrue('fixture: run approved', $runModel->approve($runId, $compId, $adminUserId, true)['status']);

    $service = new PayslipDeliveryService($pdo);

    // ---------- deliver(): basic failure modes ----------
    echo "=== PayslipDeliveryService::deliver() ===\n";
    $noChannel = $service->deliver($compId, $employeeInScope, $runId, [], 'auto');
    checkFalse('empty channel list fails', $noChannel['success']);

    $unknownEmp = $service->deliver($compId, 999999, $runId, ['email'], 'auto');
    checkFalse('unknown employee fails', $unknownEmp['success']);

    $maxIdBefore = (int)($pdo->query("SELECT MAX(id) FROM payslip_delivery_logs")->fetchColumn() ?: 0);
    $result = $service->deliver($compId, $employeeInScope, $runId, ['email', 'line'], 'auto');
    checkFalse('unconfigured email+line fallback chain still fails overall', $result['success']);
    $newRowCount = (int)$pdo->query("SELECT COUNT(*) FROM payslip_delivery_logs WHERE id > {$maxIdBefore}")->fetchColumn();
    check('both fallback attempts were logged', $newRowCount, 2);

    // employeeInScope already has a 'none'-channel log row from the empty-channel-list check
    // above -- scope to rows created by THIS call (id > the snapshot taken right before it) so
    // we're reading the email/line fallback chain specifically, not the whole history.
    $logs = $pdo->prepare("SELECT * FROM payslip_delivery_logs WHERE employee_id = :id AND id > :min_id ORDER BY id ASC");
    $logs->execute([':id' => $employeeInScope, ':min_id' => $maxIdBefore]);
    $logRows = $logs->fetchAll(PDO::FETCH_ASSOC);
    check('attempt 1 channel is email (primary)', $logRows[0]['channel_code'], 'email');
    check('attempt 1 status is failed', $logRows[0]['status'], 'failed');
    check('attempt 1 error mentions not configured', strpos($logRows[0]['error_message'], 'not configured') !== false, true);
    check('attempt 2 channel is line (fallback)', $logRows[1]['channel_code'], 'line');
    check('attempt order increments', (int)$logRows[1]['attempt_order'], 2);

    // ---------- autoSendForRun(): mode gate + scope filtering ----------
    echo "=== PayslipDeliveryService::autoSendForRun() ===\n";
    $settingsModel = new PayslipDistributionSettingModel($pdo);

    $notEnabled = $service->autoSendForRun($compId, $runId);
    check('auto-send skipped when no settings row exists yet (defaults to request_only)', $notEnabled['sent'] + $notEnabled['failed'], 0);

    $settingsModel->save(['distribution_mode' => 'request_only', 'channels' => ['email'], 'is_active' => 1], $compId, $adminUserId);
    $stillNotEnabled = $service->autoSendForRun($compId, $runId);
    check('auto-send skipped in request_only mode', $stillNotEnabled['sent'] + $stillNotEnabled['failed'], 0);

    $scopedSaveRes = $settingsModel->save([
        'distribution_mode' => 'auto', 'channels' => ['email'], 'scope_department_ids' => '90101', 'is_active' => 1,
    ], $compId, $adminUserId);
    checkTrue('fixture: scoped auto-send settings saved', $scopedSaveRes['status']);
    $countBeforeAuto = (int)$pdo->query("SELECT COUNT(*) FROM payslip_delivery_logs WHERE source = 'auto'")->fetchColumn();
    $autoResult = $service->autoSendForRun($compId, $runId);
    checkTrue('autoSendForRun() returns status=true even when sends fail (it ran, they just failed)', $autoResult['status']);
    check('only the in-scope employee was attempted (department scope)', $autoResult['sent'] + $autoResult['failed'], 1);
    check('failed count is 1 (email unconfigured)', $autoResult['failed'], 1);

    $autoLogs = $pdo->prepare("SELECT employee_id FROM payslip_delivery_logs WHERE run_id = :run_id AND source = 'auto'");
    $autoLogs->execute([':run_id' => $runId]);
    $autoEmployeeIds = array_map('intval', array_column($autoLogs->fetchAll(PDO::FETCH_ASSOC), 'employee_id'));
    checkTrue('in-scope employee was logged', in_array($employeeInScope, $autoEmployeeIds, true));
    checkFalse('out-of-scope employee was NOT logged', in_array($employeeOutOfScope, $autoEmployeeIds, true));

    // ---------- deliverForRequest() + syncFromApprovalStatus() integration ----------
    echo "=== deliverForRequest() via the approval sync hook ===\n";
    $wfModel = new ApprovalWorkflowModel($pdo);
    $approverEmployee = makeEmployee($pdo, $compId, 'PDV_APPROVER_' . uniqid());
    $wfRes = $wfModel->save($compId, [
        'workflow_name' => 'PDV Test Workflow ' . uniqid(),
        'document_type_codes' => ['SLIP_REQUEST_APPROVAL'], 'status' => 'active',
        'steps' => [['approver_type' => 'user', 'approver_id' => $approverEmployee]],
    ], $adminUserId);
    checkTrue('fixture: SLIP_REQUEST_APPROVAL workflow created', $wfRes['status']);

    $requestModel = new PayslipRequestModel($pdo);
    $approvalModel = new ApprovalRequestModel($pdo);
    $created = $requestModel->create($compId, $employeeOutOfScope, $runId, $adminUserId);
    checkTrue('fixture: payslip request created', $created['status']);
    $requestRow = $requestModel->get($compId, $created['id']);

    $actRes = $approvalModel->act($compId, (int)$requestRow['approval_request_id'], $approverEmployee, 'approve');
    check('approval is terminal (single step)', $actRes['request_status'], 'approved');
    $requestModel->syncFromApprovalStatus((int)$requestRow['approval_request_id'], $actRes['request_status'], 'email');

    $afterDelivery = $requestModel->get($compId, $created['id']);
    check('status ends at send_failed (email still unconfigured in this dev env)', $afterDelivery['status'], 'send_failed');
    check('selected_channel was recorded', $afterDelivery['selected_channel'], 'email');

    $requestDeliveryLog = $pdo->prepare("SELECT * FROM payslip_delivery_logs WHERE payslip_request_id = :id");
    $requestDeliveryLog->execute([':id' => $created['id']]);
    $reqLogRows = $requestDeliveryLog->fetchAll(PDO::FETCH_ASSOC);
    checkTrue('deliverForRequest logged at least one attempt', count($reqLogRows) >= 1);
    check('logged source is request', $reqLogRows[0]['source'], 'request');
    check('logged channel is the selected one (email, tried first)', $reqLogRows[0]['channel_code'], 'email');

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
