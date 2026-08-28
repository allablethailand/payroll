<?php
/**
 * Lightweight verification script for DocumentDeliveryLogModel -- the unified Payslip +
 * Employment Certificate delivery/issuance log introduced 2026-08-26 (see that class's own
 * docblock). Confirms the UNION ALL over payslip_delivery_logs + employment_certificate_requests
 * returns both document types with a consistent shape, and that document_type/status/channel_code/
 * source filters all narrow correctly.
 * Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Runs against the real dev DB inside a transaction that is always rolled back.
 *
 * Since this model has no employee_id/run_id filter of its own (by design -- see its docblock),
 * this test isolates its own fixture rows from any real, concurrent dev-DB data by filtering the
 * returned rows down to our own fixture employee_id(s) locally, rather than trusting raw counts
 * from list() directly -- same reasoning as feedback_dev_db_shared_state_test_fragility.
 *
 * Run with: php tests/document_delivery_log_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/services/PayslipDeliveryService.php';
require_once __DIR__ . '/../app/models/DocumentDeliveryLogModel.php';

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

function makeEmployee(PDO $pdo, int $compId, string $employeeNo): int {
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
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', 30000, '2020-01-01', 'average', 'active',
         1, 1, 0, NULL)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => $employeeNo,
        ':name_th' => 'ทดสอบ', ':surname_th' => $employeeNo, ':name_en' => 'Test', ':surname_en' => $employeeNo,
        ':email' => uniqid() . '@test.local',
    ]);
    return (int)$pdo->lastInsertId();
}

/** @return array<int,array> rows from $rows whose employee_id is in $employeeIds */
function onlyOurEmployees(array $rows, array $employeeIds): array {
    return array_values(array_filter($rows, fn($r) => in_array((int)$r['employee_id'], $employeeIds, true)));
}

try {
    $compId = 1;
    $adminUserId = 1;

    // Same isolation as tests/payslip_delivery_log_test.php / payroll_run_test.php.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL")
        ->execute([':comp_id' => $compId]);
    $pdo->prepare("UPDATE `approval_workflows` SET status = 'inactive'
        WHERE comp_id = :comp_id AND status = 'active'
          AND id IN (SELECT workflow_id FROM `approval_workflow_document_types` WHERE document_type_code = 'PAYROLL_RUN_APPROVAL')")
        ->execute([':comp_id' => $compId]);

    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of this month')->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of this month')->format('Y-m-d');

    $payslipEmployeeId = makeEmployee($pdo, $compId, 'DDL_PS_' . uniqid());
    $certEmployeeId = makeEmployee($pdo, $compId, 'DDL_ECR_' . uniqid());
    $ourEmployeeIds = [$payslipEmployeeId, $certEmployeeId];

    // ---------- Fixture: a payslip delivery (2 failed fallback attempts, no channels configured) ----------
    $cycleModel = new PayrollCycleModel();
    $cycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'DDL_TEST_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: cycle created', $cycleRes['status']);
    $cycleId = $cycleRes['id'];

    $runModel = new PayrollRunModel($pdo);
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'DDL_TEST_RUN_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $periodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: run created', $createRes['status']);
    $runId = $createRes['id'];
    $runModel->recalculate($runId, $compId, $adminUserId, true);
    $runModel->submit($runId, $compId, $adminUserId, true);
    checkTrue('fixture: run approved', $runModel->approve($runId, $compId, $adminUserId, true)['status']);

    (new PayslipDeliveryService($pdo))->deliver($compId, $payslipEmployeeId, $runId, ['email', 'line'], 'auto');

    // ---------- Fixture: employment certificate requests (one issued, one issue_failed) ----------
    // Direct INSERT rather than the full create()->approve() flow (already covered by
    // tests/employment_certificate_request_test.php) -- this test is only about the unified list
    // query/filters, not request creation. approval_request_id is nullable, so NULL sidesteps the
    // FK to approval_requests entirely.
    $insCert = $pdo->prepare("INSERT INTO `employment_certificate_requests`
        (comp_id, employee_id, language, requested_by, approval_request_id, status, file_path, issue_error)
        VALUES (:comp_id, :employee_id, :language, :requested_by, NULL, :status, :file_path, :issue_error)");
    $insCert->execute([
        ':comp_id' => $compId, ':employee_id' => $certEmployeeId, ':language' => 'th', ':requested_by' => $adminUserId,
        ':status' => 'issued', ':file_path' => 'public/uploads/employment_certificate_files/1/ddl_test_fixture.pdf', ':issue_error' => null,
    ]);
    $insCert->execute([
        ':comp_id' => $compId, ':employee_id' => $certEmployeeId, ':language' => 'en', ':requested_by' => $adminUserId,
        ':status' => 'issue_failed', ':file_path' => null, ':issue_error' => 'Simulated failure for test fixture.',
    ]);
    // A pending/rejected/cancelled row must NEVER show up in the log -- only issued/issue_failed
    // are terminal-enough to have actually attempted a real issuance.
    $insCert->execute([
        ':comp_id' => $compId, ':employee_id' => $certEmployeeId, ':language' => 'th', ':requested_by' => $adminUserId,
        ':status' => 'pending', ':file_path' => null, ':issue_error' => null,
    ]);

    $model = new DocumentDeliveryLogModel($pdo);

    // ---------- No filter: everything ----------
    $all = onlyOurEmployees($model->list($compId), $ourEmployeeIds);
    check('list() with no filter returns 4 rows (2 payslip attempts + issued + issue_failed, NOT the pending one)', count($all), 4);

    // ---------- document_type filter ----------
    $payslipOnly = onlyOurEmployees($model->list($compId, ['document_type' => 'payslip']), $ourEmployeeIds);
    check('document_type=payslip narrows to the 2 delivery attempts', count($payslipOnly), 2);
    checkTrue('every payslip row carries a reference_label (pay period)', $payslipOnly[0]['reference_label'] !== null);
    check('payslip rows have language = NULL', $payslipOnly[0]['language'], null);

    $certOnly = onlyOurEmployees($model->list($compId, ['document_type' => 'employment_certificate']), $ourEmployeeIds);
    check('document_type=employment_certificate narrows to the 2 terminal cert rows', count($certOnly), 2);
    checkTrue('every cert row has a language (th/en)', in_array($certOnly[0]['language'], ['th', 'en'], true));
    check('cert rows have channel_code = NULL (no delivery channel concept)', $certOnly[0]['channel_code'], null);
    check('cert rows have recipient = NULL', $certOnly[0]['recipient'], null);
    check('cert rows have source = request (no auto-issuance path exists)', $certOnly[0]['source'], 'request');

    // ---------- status filter, normalized across both document types ----------
    $successOnly = onlyOurEmployees($model->list($compId, ['status' => 'success']), $ourEmployeeIds);
    check('status=success returns only the issued cert (both payslip attempts failed, no channels configured)', count($successOnly), 1);
    check('the success row is the employment_certificate one', $successOnly[0]['document_type'], 'employment_certificate');

    $failedOnly = onlyOurEmployees($model->list($compId, ['status' => 'failed']), $ourEmployeeIds);
    check('status=failed returns the 2 payslip attempts + the issue_failed cert', count($failedOnly), 3);

    // ---------- channel_code filter (payslip-only concept, must not accidentally match cert rows) ----------
    $emailOnly = onlyOurEmployees($model->list($compId, ['channel_code' => 'email']), $ourEmployeeIds);
    check('channel_code=email narrows to exactly 1 payslip row, no cert rows leak in', count($emailOnly), 1);
    check('the email-channel row is a payslip row', $emailOnly[0]['document_type'], 'payslip');

    // ---------- employee/sent_by name joins ----------
    checkTrue('payslip rows have employee_no joined', $payslipOnly[0]['employee_no'] !== null);
    checkTrue('cert rows have employee_no joined too (shared employees JOIN)', $certOnly[0]['employee_no'] !== null);
    check('cert rows have sent_by_name_th = NULL (auto-issued by the system, not a person)', $certOnly[0]['sent_by_name_th'], null);

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
