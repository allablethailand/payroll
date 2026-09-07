<?php
/**
 * Verifies the 2026-09-06 Dashboard redesign's backend additions: DashboardModel::employeeStats()'s
 * new historical ('YYYY-MM-01') mode, and DashboardModel::calendarEvents() (holidays + payroll
 * cutoff/payment dates + probation/internship end dates for one calendar month, confirmed via
 * AskUserQuestion). Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the
 * real dev DB inside a transaction that is always rolled back, using a fresh throwaway company.
 * Run with: php tests/dashboard_historical_and_calendar_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/DashboardModel.php';
require_once __DIR__ . '/../app/models/NotificationModel.php';
require_once __DIR__ . '/../app/models/PayrollPolicyModel.php';
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
    $insComp = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active')");
    $insComp->execute([':name' => 'Dashboard Historical Test Co ' . uniqid()]);
    $compId = (int)$pdo->lastInsertId();

    $dashModel = new DashboardModel();

    function insEmp(PDO $pdo, int $compId, string $empNo, string $employmentDate, ?string $employmentEndDate, string $status): int {
        $stmt = $pdo->prepare("INSERT INTO employees
            (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
             personal_email, mobile_no, address_line_1_register, address_line_1_contact,
             emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
             employment_date, employment_end_date, employment_status, employment_type, workforce_type, record_time_method,
             salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt)
            VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'DASH', 'Test', 'DASH', '1990-01-01', 'Thai',
             :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
             :employment_date, :employment_end_date, :employment_status, 'full_time', 'office', 'manual',
             'monthly', 30000, :employment_date, 'average', :employee_status, 0, 0, 0)");
        $stmt->execute([
            ':comp_id' => $compId, ':employee_no' => $empNo, ':email' => uniqid() . '@test.local',
            ':employment_date' => $employmentDate, ':employment_end_date' => $employmentEndDate,
            ':employment_status' => $status, ':employee_status' => $status === 'resigned' ? 'resigned' : 'active',
        ]);
        return (int)$pdo->lastInsertId();
    }

    echo "=== DashboardModel::employeeStats(): historical month mode ===\n";
    // A: hired well before, still employed throughout -> counts as active in every month tested.
    $empA = insEmp($pdo, $compId, 'DASH_A_' . uniqid(), '2025-01-01', null, 'permanent');
    // B: hired mid-March 2026, no end date -> active from March onward, NOT active in January/February.
    $empB = insEmp($pdo, $compId, 'DASH_B_' . uniqid(), '2026-03-15', null, 'permanent');
    // C: hired 2025, resigned effective 2026-02-10 -> active through January, NOT active by March.
    $empC = insEmp($pdo, $compId, 'DASH_C_' . uniqid(), '2025-06-01', '2026-02-10', 'resigned');

    $statsJan = $dashModel->employeeStats($compId, '2026-01-01');
    check('January 2026: A and C both active (C resigns Feb 10, still employed through January), B not yet hired -> 2', $statsJan['active_count'], 2);
    check('January 2026: no one hired that month -> 0 new hires', $statsJan['new_this_month'], 0);

    $statsMar = $dashModel->employeeStats($compId, '2026-03-01');
    check('March 2026: A still active, B just hired, C already resigned in Feb -> 2', $statsMar['active_count'], 2);
    check('March 2026: B was hired this month -> 1 new hire', $statsMar['new_this_month'], 1);

    $statsFeb = $dashModel->employeeStats($compId, '2026-02-01');
    check('February 2026: A active, C active through Feb 10 (employment_end_date within the month still counts as active during it), B not yet hired -> 2', $statsFeb['active_count'], 2);

    echo "=== DashboardModel::calendarEvents(): combines holidays + payroll dates + probation/intern ends, scoped to ONE month ===\n";
    $insHoliday = $pdo->prepare("INSERT INTO holidays (comp_id, country_code, name_th, name_en, holiday_date, status, created_by)
        VALUES (:comp_id, 'TH', 'วันทดสอบ', 'Test Holiday', :holiday_date, 'active', :created_by)");
    $insHoliday->execute([':comp_id' => $compId, ':holiday_date' => '2026-04-06', ':created_by' => $userId]);
    // A second holiday OUTSIDE the target month -- must NOT leak into April's event list.
    $insHoliday->execute([':comp_id' => $compId, ':holiday_date' => '2026-05-01', ':created_by' => $userId]);

    $cycleModel = new PayrollCycleModel($pdo);
    $cycleSave = $cycleModel->save($compId, [
        'cycle_name' => 'DASH_CAL_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    checkTrue('fixture: cycle created', $cycleSave['status']);

    $runModel = new PayrollRunModel();
    $runRes = $runModel->create($compId, [
        'cycle_id' => $cycleSave['id'], 'run_name' => 'DASH_CAL_RUN_' . uniqid(),
        // period_end_date (cutoff) lands in March, payment_date lands in April -- confirms each
        // date is attributed to ITS OWN month independently, not both to whichever month the run
        // "belongs to" as a whole.
        'period_start_date' => '2026-03-01', 'period_end_date' => '2026-03-25', 'payment_date' => '2026-04-05',
    ], $userId, true);
    checkTrue('fixture: payroll run created' . (empty($runRes['status']) ? " ({$runRes['message']})" : ''), $runRes['status']);

    // A probation employee whose computed expiry date lands in April.
    // $userId=1 isn't a real employee row in this fresh throwaway company -- FK columns like
    // company_payroll_policies.updated_by need a real employees.id, so $empA (already created
    // above) doubles as the "acting employee" from here on, same as every other real-employee-FK
    // call in this file.
    $policyModel = new PayrollPolicyModel($pdo);
    $policyModel->save($compId, ['probation_period_days' => 90, 'intern_period_days' => 180], $empA);
    // 2026-04-20 minus 90 days = 2026-01-20 -- hire date chosen so the expiry lands in April.
    $empProbation = insEmp($pdo, $compId, 'DASH_PROB_' . uniqid(), '2026-01-20', null, 'probation');

    $aprilEvents = $dashModel->calendarEvents($compId, 2026, 4);
    $marchEvents = $dashModel->calendarEvents($compId, 2026, 3);

    $aprilTypes = array_column($aprilEvents, 'type');
    checkTrue('April: the payment_date event (payroll_payment) is present', in_array('payroll_payment', $aprilTypes, true));
    checkFalse('April: the March cutoff (payroll_cutoff) does NOT leak into April', in_array('payroll_cutoff', $aprilTypes, true));
    checkTrue('April: the April 6 holiday is present', in_array('holiday', $aprilTypes, true));
    checkTrue('April: the probation-end event is present', in_array('probation_end', $aprilTypes, true));
    checkFalse('April: the May 1 holiday does NOT leak into April', in_array('2026-05-01', array_column($aprilEvents, 'date'), true));

    $marchTypes = array_column($marchEvents, 'type');
    checkTrue('March: the period_end_date event (payroll_cutoff) is present', in_array('payroll_cutoff', $marchTypes, true));
    checkFalse('March: the April payment_date does NOT leak into March', in_array('payroll_payment', $marchTypes, true));
    checkFalse('March: no holiday leaks into March (both fixture holidays are April/May)', in_array('holiday', $marchTypes, true));
    checkFalse('March: the probation-end event (April) does NOT leak into March', in_array('probation_end', $marchTypes, true));

    $holidayEvent = current(array_filter($aprilEvents, fn($e) => $e['type'] === 'holiday'));
    checkTrue('the holiday event carries its own Thai/English names', $holidayEvent !== false && $holidayEvent['label_th'] === 'วันทดสอบ' && $holidayEvent['label_en'] === 'Test Holiday');

    $probEvent = current(array_filter($aprilEvents, fn($e) => $e['type'] === 'probation_end'));
    checkTrue('the probation-end event carries the correct employee_id', $probEvent !== false && (int)$probEvent['employee_id'] === $empProbation);

    // Events sorted chronologically within the month.
    $aprilDates = array_column($aprilEvents, 'date');
    $sortedDates = $aprilDates;
    sort($sortedDates);
    check('events are returned in chronological order within the month', $aprilDates, $sortedDates);

    echo "=== DashboardModel::departmentHeadcount(): live vs. historical, shares the same window logic as employeeStats() ===\n";
    $deptStmt = $pdo->prepare("INSERT INTO structure_departments (comp_id, department_code, department_name_th, department_name_en, status, created_by)
        VALUES (:comp_id, :code, 'ฝ่ายทดสอบ', 'Test Dept', 'active', :created_by)");
    $deptStmt->execute([':comp_id' => $compId, ':code' => 'DASHDEPT_' . uniqid(), ':created_by' => $empA]);
    $deptId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE employees SET department_id = :dept_id WHERE id IN (:a, :b)")
        ->execute([':dept_id' => $deptId, ':a' => $empA, ':b' => $empB]);

    $headcountMarch = $dashModel->departmentHeadcount($compId, '2026-03-01');
    $deptRowMarch = current(array_filter($headcountMarch, fn($r) => $r['department_name_en'] === 'Test Dept'));
    check('March 2026: both A and B are active and in the test department -> count 2', $deptRowMarch !== false ? $deptRowMarch['count'] : null, 2);

    $headcountJan = $dashModel->departmentHeadcount($compId, '2026-01-01');
    $deptRowJan = current(array_filter($headcountJan, fn($r) => $r['department_name_en'] === 'Test Dept'));
    check('January 2026: only A is active+in-dept yet (B not hired until March) -> count 1', $deptRowJan !== false ? $deptRowJan['count'] : null, 1);

    $unspecifiedJan = current(array_filter($headcountJan, fn($r) => $r['department_name_en'] === 'Unspecified'));
    checkTrue('employees with no department_id (C, the probation fixture) fall into "Unspecified", not dropped', $unspecifiedJan !== false && $unspecifiedJan['count'] >= 1);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
