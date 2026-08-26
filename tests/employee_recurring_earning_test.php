<?php
/**
 * Verifies EmployeeRecurringEarningModel -- 2026-08-26, explicit request: "ส่วนของเงินเดือนในหน้าจัดการ
 * ข้อมูลพนักงาน จะมีรายรับที่ได้ทุกเดือนเช่นพวกค่าตำแหน่ง ค่ารถ ค่าน้ำมัน และอื่นๆ ให้เพิ่มส่วนนี้เข้าไปด้วย และ
 * ระงับการจ่ายได้ รวมถึงการตั้งค่าส่วนนี้เพิ่มเติมให้นำไปคำนวณในรอบการจ่ายด้วย". Covers save() validation
 * (catalog restricted to item_type=earning + calculation_method=fixed_amount, both-or-neither
 * suspend dates, duplicate-active-assignment rejection), list()/get() shape including the
 * is_suspended_now convenience flag, delete(), and activeForPeriod()'s date-range-overlap logic.
 * The actual payroll-calculation integration (PayrollRunModel::recalculate()'s own "Recurring
 * earnings" section) is covered separately in tests/payroll_run_test.php, same split
 * tests/employee_earning_deduction_test.php already established for its own feature.
 * Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Runs against the real dev DB inside a transaction that is always rolled back.
 * Run with: php tests/employee_recurring_earning_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/EmployeeRecurringEarningModel.php';
require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';

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

    $typeModel = new PayrollEarningDeductionTypeModel($pdo);
    $model = new EmployeeRecurringEarningModel($pdo);

    $employeeId = makeEmployee($pdo, $compId, 'ERE_EMP_' . uniqid());

    // ---------- Fixtures: catalog types ----------
    $fixedEarningRes = $typeModel->save($compId, [
        'item_code' => 'ERETEST1', 'item_name_th' => 'ค่าตำแหน่งทดสอบ', 'item_name_en' => 'Test Position Allowance',
        'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 2000, 'tax_treatment' => 'taxable',
    ], $adminUserId);
    checkTrue('fixture: fixed_amount earning type created', $fixedEarningRes['status']);
    $fixedEarningTypeId = $fixedEarningRes['id'];

    $percentEarningRes = $typeModel->save($compId, [
        'item_code' => 'ERETEST2', 'item_name_th' => 'ทดสอบเปอร์เซ็นต์', 'item_name_en' => 'Test Percent Earning',
        'item_type' => 'earning', 'calculation_method' => 'percent_of_base_salary', 'percent_rate' => 5, 'tax_treatment' => 'taxable',
    ], $adminUserId);
    checkTrue('fixture: percent_of_base_salary earning type created', $percentEarningRes['status']);
    $percentEarningTypeId = $percentEarningRes['id'];

    $deductionRes = $typeModel->save($compId, [
        'item_code' => 'ERETEST3', 'item_name_th' => 'ทดสอบรายหัก', 'item_name_en' => 'Test Deduction',
        'item_type' => 'deduction', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 500, 'tax_deduction_impact' => 'after_tax',
    ], $adminUserId);
    checkTrue('fixture: fixed_amount deduction type created', $deductionRes['status']);
    $deductionTypeId = $deductionRes['id'];

    // ---------- save() validation ----------
    $r = $model->save(999999, $compId, ['ped_type_id' => $fixedEarningTypeId, 'amount' => 1000, 'effective_date' => '2026-01-01'], $adminUserId);
    checkFalse('unknown employee_id rejected', $r['status']);

    $r = $model->save($employeeId, $compId, ['ped_type_id' => 999999, 'amount' => 1000, 'effective_date' => '2026-01-01'], $adminUserId);
    checkFalse('unknown ped_type_id rejected', $r['status']);

    $r = $model->save($employeeId, $compId, ['ped_type_id' => $deductionTypeId, 'amount' => 1000, 'effective_date' => '2026-01-01'], $adminUserId);
    checkFalse('a deduction-type catalog item rejected (earning only)', $r['status']);

    $r = $model->save($employeeId, $compId, ['ped_type_id' => $percentEarningTypeId, 'amount' => 1000, 'effective_date' => '2026-01-01'], $adminUserId);
    checkFalse('a percent_of_base_salary catalog item rejected (fixed_amount only)', $r['status']);

    $r = $model->save($employeeId, $compId, ['ped_type_id' => $fixedEarningTypeId, 'amount' => 0, 'effective_date' => '2026-01-01'], $adminUserId);
    checkFalse('zero amount rejected', $r['status']);

    $r = $model->save($employeeId, $compId, ['ped_type_id' => $fixedEarningTypeId, 'amount' => 1000, 'effective_date' => 'not-a-date'], $adminUserId);
    checkFalse('invalid effective_date rejected', $r['status']);

    $r = $model->save($employeeId, $compId, ['ped_type_id' => $fixedEarningTypeId, 'amount' => 1000, 'effective_date' => '2026-01-01', 'suspended_from' => '2026-02-01'], $adminUserId);
    checkFalse('suspended_from without suspended_to rejected (both-or-neither)', $r['status']);

    $r = $model->save($employeeId, $compId, ['ped_type_id' => $fixedEarningTypeId, 'amount' => 1000, 'effective_date' => '2026-01-01', 'suspended_to' => '2026-02-01'], $adminUserId);
    checkFalse('suspended_to without suspended_from rejected (both-or-neither)', $r['status']);

    $r = $model->save($employeeId, $compId, ['ped_type_id' => $fixedEarningTypeId, 'amount' => 1000, 'effective_date' => '2026-01-01', 'suspended_from' => '2026-03-01', 'suspended_to' => '2026-02-01'], $adminUserId);
    checkFalse('suspend end date before start date rejected', $r['status']);

    // ---------- save() success ----------
    $created = $model->save($employeeId, $compId, ['ped_type_id' => $fixedEarningTypeId, 'amount' => 1500.5, 'effective_date' => '2026-01-01', 'notes' => 'test note'], $adminUserId);
    checkTrue('valid assignment created', $created['status']);
    $assignmentId = $created['id'];

    $dup = $model->save($employeeId, $compId, ['ped_type_id' => $fixedEarningTypeId, 'amount' => 999, 'effective_date' => '2026-01-01'], $adminUserId);
    checkFalse('duplicate active assignment of the same type for the same employee rejected', $dup['status']);

    $row = $model->get($assignmentId, $compId);
    checkTrue('get() returns the created row', $row !== null);
    check('amount rounds to 2 decimals', (float)$row['amount'], 1500.5);
    check('item_code joined from the catalog', $row['item_code'], 'ERETEST1');
    check('notes saved', $row['notes'], 'test note');

    // ---------- update (editing its own row is not a self-conflict) ----------
    $updated = $model->save($employeeId, $compId, [
        'id' => $assignmentId, 'ped_type_id' => $fixedEarningTypeId, 'amount' => 1800, 'effective_date' => '2026-01-01',
        'suspended_from' => '2026-06-01', 'suspended_to' => '2026-06-30',
    ], $adminUserId);
    checkTrue('editing its own row succeeds (not treated as a duplicate)', $updated['status']);

    // ---------- list()/is_suspended_now ----------
    $list = $model->list($employeeId, $compId);
    check('list() returns 1 active row', count($list), 1);
    check('list() row has the updated amount', (float)$list[0]['amount'], 1800.0);
    // is_suspended_now compares against TODAY -- the fixture's suspend window (June 2026) is not
    // "now" relative to whenever this test actually runs in real time, so this just needs to be
    // internally consistent, not a specific hardcoded expectation.
    $today = date('Y-m-d');
    $expectedSuspendedNow = ('2026-06-01' <= $today && $today <= '2026-06-30');
    check('is_suspended_now matches today vs the suspend window', $list[0]['is_suspended_now'], $expectedSuspendedNow);

    // ---------- delete() ----------
    $delRes = $model->delete($assignmentId, $compId, $employeeId, $adminUserId);
    checkTrue('delete() succeeds', $delRes['status']);
    check('list() is empty after delete', count($model->list($employeeId, $compId)), 0);

    $reCreate = $model->save($employeeId, $compId, ['ped_type_id' => $fixedEarningTypeId, 'amount' => 1000, 'effective_date' => '2026-01-01'], $adminUserId);
    checkTrue('same allowance type can be re-assigned after the old one was deleted', $reCreate['status']);
    $secondAssignmentId = $reCreate['id'];

    // ---------- activeForPeriod() ----------
    $noSuspend = $model->activeForPeriod($employeeId, '2026-02-01', '2026-02-28');
    check('no suspend window: included for a period on/after effective_date', count($noSuspend), 1);
    check('activeForPeriod() carries the amount through', (float)$noSuspend[0]['amount'], 1000.0);

    $beforeEffective = $model->activeForPeriod($employeeId, '2025-12-01', '2025-12-31');
    check('excluded for a period entirely before effective_date', count($beforeEffective), 0);

    $model->save($employeeId, $compId, [
        'id' => $secondAssignmentId, 'ped_type_id' => $fixedEarningTypeId, 'amount' => 1000, 'effective_date' => '2026-01-01',
        'suspended_from' => '2026-03-10', 'suspended_to' => '2026-03-20',
    ], $adminUserId);

    $fullyOutside = $model->activeForPeriod($employeeId, '2026-04-01', '2026-04-30');
    check('a period entirely outside the suspend window: included', count($fullyOutside), 1);

    $fullyInside = $model->activeForPeriod($employeeId, '2026-03-01', '2026-03-31');
    check('a period that fully contains the suspend window: excluded', count($fullyInside), 0);

    $partialOverlapStart = $model->activeForPeriod($employeeId, '2026-03-15', '2026-03-25');
    check('a period that partially overlaps the START of the suspend window: excluded', count($partialOverlapStart), 0);

    $partialOverlapEnd = $model->activeForPeriod($employeeId, '2026-03-01', '2026-03-15');
    check('a period that partially overlaps the END of the suspend window: excluded', count($partialOverlapEnd), 0);

    $exactBoundaryBefore = $model->activeForPeriod($employeeId, '2026-03-01', '2026-03-09');
    check('a period ending exactly the day before the suspend window starts: included', count($exactBoundaryBefore), 1);

    $exactBoundaryAfter = $model->activeForPeriod($employeeId, '2026-03-21', '2026-03-31');
    check('a period starting exactly the day after the suspend window ends: included', count($exactBoundaryAfter), 1);

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
