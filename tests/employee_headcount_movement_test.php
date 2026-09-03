<?php
/**
 * Verifies EmployeeModel::headcountMovementReport() -- Phase 1 of the Employee Reports plan,
 * explicit request: "Report คนเข้าคนออกประจำเดือน ประจำปี" (see
 * project_employee_reports_phased_plan_2026_09_02 memory for the full 5-phase plan this belongs to).
 *
 * Covers: hires/exits counted by employment_date/employment_end_date falling within the selected
 * year, monthly breakdown correctness, department/branch filtering, turnover-rate calculation
 * (average of start/end-of-year headcount), the events list (hire+exit rows, correctly typed and
 * dated), and that a resigned/terminated status is required for an exit to count (an
 * employment_end_date alone, with no such status, must NOT count as an exit).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 * Run with: php tests/employee_headcount_movement_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/EmployeeModel.php';
require_once __DIR__ . '/../app/models/CompanyProfileModel.php';

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

try {
    $compId = 1;
    $adminUserId = 1;

    // Same shared-dev-DB isolation precaution every other test that counts/aggregates ACROSS the
    // whole `employees` table for comp_id=1 uses -- see feedback_dev_db_shared_state_test_fragility.
    // Unlike standingSummaryList()'s own test (scoped to a fetched ID list), this report counts
    // hires/exits company-wide, so real pre-existing dev-DB employees would pollute every count.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL")->execute([':comp_id' => $compId]);

    $model = new EmployeeModel();
    $structModel = new CompanyProfileModel($pdo);

    $deptARes = $structModel->saveStructure('department', $compId, ['department_code' => 'HCM_DEPT_A', 'department_name_th' => 'แผนก A', 'department_name_en' => 'Dept A'], $adminUserId);
    checkTrue('fixture: department A created' . (empty($deptARes['status']) ? " ({$deptARes['message']})" : ''), $deptARes['status']);
    $deptAId = $deptARes['id'];
    $deptBRes = $structModel->saveStructure('department', $compId, ['department_code' => 'HCM_DEPT_B', 'department_name_th' => 'แผนก B', 'department_name_en' => 'Dept B'], $adminUserId);
    checkTrue('fixture: department B created' . (empty($deptBRes['status']) ? " ({$deptBRes['message']})" : ''), $deptBRes['status']);
    $deptBId = $deptBRes['id'];

    function makeHcmEmployee(PDO $pdo, int $compId, string $tag, string $employmentDate, ?string $employmentEndDate, string $employmentStatus, int $departmentId): int {
        $empNo = 'HCM_' . $tag . '_' . uniqid();
        $pdo->prepare("INSERT INTO `employees`
            (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
             personal_email, mobile_no, address_line_1_register, address_line_1_contact,
             emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
             department_id, employment_date, employment_end_date, employment_status, employment_type, workforce_type, record_time_method,
             bank_id, bank_account_no, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt)
            VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :tag, :name_en, :tag, '1990-01-01', 'Thai',
             :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
             :department_id, :employment_date, :employment_end_date, :employment_status, 'full_time', 'office', 'manual',
             1, '1112223334', 'monthly', 30000, :employment_date, 'average', 'active', 0, 0, 1)")
            ->execute([
                ':comp_id' => $compId, ':employee_no' => $empNo, ':name_th' => 'ทดสอบ' . $tag, ':name_en' => 'Test', ':tag' => $tag,
                ':email' => uniqid() . '@test.local', ':department_id' => $departmentId,
                ':employment_date' => $employmentDate, ':employment_end_date' => $employmentEndDate, ':employment_status' => $employmentStatus,
            ]);
        return (int)$pdo->lastInsertId();
    }

    echo "=== Fixture: 2027 headcount movement (5 employees) ===\n";
    // A: hired March 2027, still active (dept A) -- counts as a hire, not an exit.
    makeHcmEmployee($pdo, $compId, 'A', '2027-03-15', null, 'permanent', $deptAId);
    // B: hired before 2027 (2020), resigned June 2027 (dept A) -- counts as an exit only, not a hire.
    makeHcmEmployee($pdo, $compId, 'B', '2020-01-01', '2027-06-30', 'resigned', $deptAId);
    // C: hired AND terminated within 2027 (Jan hire, Aug exit, dept B) -- counts as BOTH a hire and an exit.
    makeHcmEmployee($pdo, $compId, 'C', '2027-01-10', '2027-08-20', 'terminated', $deptBId);
    // D: has an employment_end_date in 2027 but status is still 'permanent' (e.g. a future-dated
    // planned end that hasn't actually taken effect) -- must NOT count as an exit, per the model's
    // own "status must be resigned/terminated" guard.
    makeHcmEmployee($pdo, $compId, 'D', '2019-05-01', '2027-12-01', 'permanent', $deptBId);
    // E: hired in 2026 (outside the 2027 window entirely) -- must not appear in 2027 hires or exits.
    makeHcmEmployee($pdo, $compId, 'E', '2026-11-01', null, 'permanent', $deptAId);

    $report = $model->headcountMovementReport($compId, 2027);
    check('total_hires = 2 (A in March, C in January -- D/E/B excluded)', $report['summary']['total_hires'], 2);
    check('total_exits = 2 (B in June, C in August -- D excluded, no status match)', $report['summary']['total_exits'], 2);
    check('net_change = 0 (2 hires - 2 exits)', $report['summary']['net_change'], 0);

    echo "=== Monthly breakdown ===\n";
    $byMonth = [];
    foreach ($report['by_month'] as $row) { $byMonth[$row['month']] = $row; }
    check('12 months present in by_month (not just months with data)', count($report['by_month']), 12);
    check('January: 1 hire (C), 0 exits', [$byMonth[1]['hires'], $byMonth[1]['exits']], [1, 0]);
    check('March: 1 hire (A), 0 exits', [$byMonth[3]['hires'], $byMonth[3]['exits']], [1, 0]);
    check('June: 0 hires, 1 exit (B)', [$byMonth[6]['hires'], $byMonth[6]['exits']], [0, 1]);
    check('August: 0 hires, 1 exit (C)', [$byMonth[8]['hires'], $byMonth[8]['exits']], [0, 1]);
    check('December: 0 hires, 0 exits (D\'s end_date here does not count, wrong status)', [$byMonth[12]['hires'], $byMonth[12]['exits']], [0, 0]);
    check('February: 0 hires, 0 exits (no events at all that month)', [$byMonth[2]['hires'], $byMonth[2]['exits']], [0, 0]);

    echo "=== Department filter ===\n";
    $reportDeptA = $model->headcountMovementReport($compId, 2027, ['department_id' => $deptAId]);
    check('dept A only: 1 hire (A), 1 exit (B) -- C/D/E excluded (C is dept B)', [$reportDeptA['summary']['total_hires'], $reportDeptA['summary']['total_exits']], [1, 1]);
    $reportDeptB = $model->headcountMovementReport($compId, 2027, ['department_id' => $deptBId]);
    check('dept B only: 1 hire (C), 1 exit (C) -- A/B/D/E excluded', [$reportDeptB['summary']['total_hires'], $reportDeptB['summary']['total_exits']], [1, 1]);

    echo "=== Turnover rate (average of start/end-of-year headcount) ===\n";
    // Headcount at 2027-01-01: B (hired 2020, not yet resigned as of Jan 1) + D (hired 2019) + E
    // (hired 2026) = 3 active. Headcount at 2027-12-31: A (hired Mar) + D (still active, end_date
    // doesn't count since status never changed) + E = 3 active (B and C both exited by then).
    // avg = (3+3)/2 = 3. turnover_rate = 2 exits / 3 avg * 100 = 66.67%.
    check('headcount_start = 3 (B, D, E all already employed on 2027-01-01)', $report['summary']['headcount_start'], 3);
    check('headcount_end = 3 (A, D, E active on 2027-12-31; B and C both exited)', $report['summary']['headcount_end'], 3);
    check('turnover_rate = 66.67% (2 exits / avg headcount 3)', $report['summary']['turnover_rate'], 66.67);

    echo "=== Events list ===\n";
    $events = $report['events'];
    check('4 total events (A hire, B exit, C hire, C exit -- D and E produce none)', count($events), 4);
    $eventTypes = array_count_values(array_column($events, 'movement_type'));
    check('2 hire events, 2 exit events', [$eventTypes['hire'] ?? 0, $eventTypes['exit'] ?? 0], [2, 2]);
    checkTrue('events are ordered by event_date ascending', $events[0]['event_date'] <= $events[count($events) - 1]['event_date']);

    echo "=== Empty year (no fixture data at all) ===\n";
    $emptyReport = $model->headcountMovementReport($compId, 1999);
    check('total_hires = 0 for a year with no data', $emptyReport['summary']['total_hires'], 0);
    check('turnover_rate = 0 (not a division-by-zero error) when avg headcount is 0', $emptyReport['summary']['turnover_rate'], 0.0);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
