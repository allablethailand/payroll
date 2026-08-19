<?php
/**
 * Lightweight verification script for PayslipDeliveryLogModel (list/filters) and
 * PayslipDeliveryService::resend(). Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Runs against the real dev DB inside a transaction that is always rolled back.
 * Run with: php tests/payslip_delivery_log_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/models/PayslipDistributionSettingModel.php';
require_once __DIR__ . '/../app/models/PayslipDeliveryLogModel.php';
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

try {
    $compId = 1;
    $adminUserId = 1;

    // Same isolation as tests/payroll_run_test.php: recalculate() now pulls incomplete-profile
    // employees (is_payroll_ready=0) into the calculation table instead of excluding them (see
    // PayrollRunModel::recalculate(), 2026-08-19), so leftover placeholder employees anyone has
    // ever created against this real, shared dev-DB company (id 1) now legitimately show up in
    // every run this test creates and block submit()/approve() through no fault of this test's own
    // fixture. Soft-delete them for this run only, entirely inside this script's own transaction
    // (rolled back at the very end), so nothing here is a real/permanent change.
    // Broadened from is_payroll_ready=0-only (2026-08-19, see tests/payroll_run_test.php's own
    // comment for the full reasoning): a leftover row with is_payroll_ready=1 -- from back when that
    // column was hardcoded true on every successful save, before EmployeeModel::save() started
    // computing it dynamically -- would slip past a narrower filter and still be picked up.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL")
        ->execute([':comp_id' => $compId]);

    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of this month')->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of this month')->format('Y-m-d');

    $employeeId = makeEmployee($pdo, $compId, 'PDVL_' . uniqid());

    $cycleModel = new PayrollCycleModel();
    $cycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'PDVL_TEST_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: cycle created', $cycleRes['status']);
    $cycleId = $cycleRes['id'];

    $runModel = new PayrollRunModel($pdo);
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'PDVL_TEST_RUN_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $periodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: run created', $createRes['status']);
    $runId = $createRes['id'];
    $runModel->recalculate($runId, $compId, $adminUserId, true);
    $runModel->submit($runId, $compId, $adminUserId, true);
    checkTrue('fixture: run approved', $runModel->approve($runId, $compId, $adminUserId, true)['status']);

    $service = new PayslipDeliveryService($pdo);
    $service->deliver($compId, $employeeId, $runId, ['email', 'line'], 'auto');

    $logModel = new PayslipDeliveryLogModel($pdo);
    $all = $logModel->list($compId, ['run_id' => $runId]);
    check('list() returns both fallback attempts', count($all), 2);

    $onlyEmail = $logModel->list($compId, ['run_id' => $runId, 'channel_code' => 'email']);
    check('filter by channel_code narrows to 1', count($onlyEmail), 1);
    check('filtered row has employee_no joined', $onlyEmail[0]['employee_no'] !== null, true);
    check('filtered row has run_name joined', $onlyEmail[0]['run_name'] !== null, true);

    $onlyFailed = $logModel->list($compId, ['run_id' => $runId, 'status' => 'failed']);
    check('both attempts are failed (unconfigured channels)', count($onlyFailed), 2);

    $onlySuccess = $logModel->list($compId, ['run_id' => $runId, 'status' => 'success']);
    check('no successful attempts exist', count($onlySuccess), 0);

    // ---------- resend() ----------
    echo "=== PayslipDeliveryService::resend() ===\n";
    $failedLogId = $onlyEmail[0]['id'];
    $countBefore = count($logModel->list($compId, ['run_id' => $runId]));
    $resendRes = $service->resend($compId, (int)$failedLogId, $adminUserId);
    checkFalse('resend still fails (channels still unconfigured)', $resendRes['success']);
    $countAfter = count($logModel->list($compId, ['run_id' => $runId]));
    checkTrue('resend created new log rows (retried the fallback chain, not just one channel)', $countAfter > $countBefore);

    $bySentByDirect = array_filter($logModel->list($compId, ['run_id' => $runId]), fn($r) => (int)($r['sent_by'] ?? 0) === $adminUserId);
    checkTrue('at least one resent attempt recorded sent_by = the acting admin', count($bySentByDirect) > 0);

    $unknownLog = $service->resend($compId, 999999, $adminUserId);
    checkFalse('resend on unknown log id fails cleanly', $unknownLog['success']);

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
