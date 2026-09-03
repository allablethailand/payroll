<?php
/**
 * Verifies Phase 6 of Deduction Destination & Third-Party Remittance (2026-09-02): destination
 * routing on Employee Detail's recurring deductions (EmployeeRecurringDeductionModel), the
 * per-run-only override (PayrollRunModel::recurringDeductionDestinationOverrideSave()/Remove()/
 * recurringDeductionDestinationsForEmployee()), and that the whole thing feeds correctly into the
 * already-tested TRANSFER_IN mechanism and PayrollRemittanceModel::generateForRun() grouping
 * without any changes needed to either of those. Not PHPUnit -- see tests/statutory_engine_test.php
 * for why. Runs against the real dev DB inside a transaction that is always rolled back, so it
 * never leaves any data behind. Uses its own fresh company/employees (same isolation convention as
 * tests/payroll_remittance_test.php), not the shared comp_id=1 dev data.
 * Run with: php tests/employee_recurring_deduction_destination_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/models/EmployeeRecurringDeductionModel.php';
require_once __DIR__ . '/../app/models/PaymentDestinationModel.php';
require_once __DIR__ . '/../app/models/PayrollRemittanceModel.php';

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
    $compCode = 'RDD_' . uniqid();
    $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', :comp_code)")
        ->execute([':name' => 'Recurring Deduction Destination Test Co ' . uniqid(), ':comp_code' => $compCode]);
    $compId = (int)$pdo->lastInsertId();

    function makeEmployee(PDO $pdo, int $compId, string $tag, string $employmentDate): int {
        $empNo = 'RDD_' . $tag . '_' . uniqid();
        $pdo->prepare("INSERT INTO `employees`
            (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
             personal_email, mobile_no, address_line_1_register, address_line_1_contact,
             emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
             employment_date, employment_status, employment_type, workforce_type, record_time_method,
             bank_id, bank_account_no, bank_account_name, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt)
            VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :tag, :name_en, :tag, '1990-01-01', 'Thai',
             :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
             :employment_date, 'permanent', 'full_time', 'office', 'manual',
             1, 'ENC-ACC-NO', 'Test Account', 'monthly', 30000, '2020-01-01', 'average', 'active', 0, 0, 1)")
            ->execute([':comp_id' => $compId, ':employee_no' => $empNo, ':email' => uniqid() . '@test.local',
                ':name_th' => 'ทดสอบ' . $tag, ':name_en' => 'Test', ':tag' => $tag, ':employment_date' => $employmentDate]);
        return (int)$pdo->lastInsertId();
    }
    $employeeAId = makeEmployee($pdo, $compId, 'A', '2020-01-01'); // in run -- has 3 recurring deductions
    $employeeBId = makeEmployee($pdo, $compId, 'B', '2020-01-01'); // in run -- receives A's employee-payee recurring deduction (TRANSFER_IN)

    // A fixed_amount deduction-type catalog item, as EmployeeRecurringDeductionModel::save() requires.
    $pdo->prepare("INSERT INTO `payroll_earning_deduction_types`
            (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method, is_sync_only, status)
        VALUES (:comp_id, :code, 'ค่าธรรมเนียมทดสอบ', 'Test Fee', 'deduction', 'fixed_amount', 0, 'active')")
        ->execute([':comp_id' => $compId, ':code' => 'RDD_FEE_' . uniqid()]);
    $pedTypeId1 = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `payroll_earning_deduction_types`
            (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method, is_sync_only, status)
        VALUES (:comp_id, :code, 'ค่าธรรมเนียมทดสอบ 2', 'Test Fee 2', 'deduction', 'fixed_amount', 0, 'active')")
        ->execute([':comp_id' => $compId, ':code' => 'RDD_FEE2_' . uniqid()]);
    $pedTypeId2 = (int)$pdo->lastInsertId();

    $cycleSave = (new PayrollCycleModel($pdo))->save($compId, [
        'cycle_name' => 'RDD_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    $cycleId = $cycleSave['id'];

    $runModel = new PayrollRunModel($pdo);
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'Recurring Deduction Destination Test Run',
        'period_start_date' => '2027-07-21', 'period_end_date' => '2027-08-20', 'payment_date' => '2027-07-25',
    ], $userId, true);
    if (empty($createRes['status'])) { throw new RuntimeException('create failed: ' . ($createRes['message'] ?? '')); }
    $runId = $createRes['id'];

    // ---------- EmployeeRecurringDeductionModel: template-level destination wiring ----------
    echo "=== EmployeeRecurringDeductionModel: template payee_type/destination_id ===\n";
    $recModel = new EmployeeRecurringDeductionModel($pdo);
    $recOtherPerson = $recModel->save($employeeAId, $compId, [
        'ped_type_id' => $pedTypeId1, 'amount' => 200.00, 'effective_date' => '2027-07-01',
        'payee_type' => 'other_person', 'account_name' => 'Locker Vendor Co.', 'account_no' => '1112223334', 'bank_id' => 1, 'is_saved' => true,
    ], $userId);
    checkTrue('save() with payee_type=other_person + new destination succeeds' . (empty($recOtherPerson['status']) ? " ({$recOtherPerson['message']})" : ''), $recOtherPerson['status']);
    $recurringOtherPersonId = $recOtherPerson['id'];
    $recGet = $recModel->get($recurringOtherPersonId, $compId);
    check('destination_id persisted on the template row', $recGet['destination_id'] !== null, true);
    check('destination_account_name resolves for display', $recGet['destination_account_name'] ?? null, 'Locker Vendor Co.');
    $templateDestinationId = (int)$recGet['destination_id'];

    $recToB = $recModel->save($employeeAId, $compId, [
        'ped_type_id' => $pedTypeId2, 'amount' => 150.00, 'effective_date' => '2027-07-01',
        'payee_type' => 'employee', 'payee_employee_id' => $employeeBId,
    ], $userId);
    checkTrue('save() with payee_type=employee (payee IS in this run) succeeds' . (empty($recToB['status']) ? " ({$recToB['message']})" : ''), $recToB['status']);
    $recurringToBId = $recToB['id'];

    checkFalse('save() rejects payee_type=employee with no payee_employee_id',
        $recModel->save($employeeAId, $compId, ['ped_type_id' => $pedTypeId1, 'amount' => 100, 'effective_date' => '2027-07-01', 'payee_type' => 'employee'], $userId)['status']);

    // ---------- recalculate(): template defaults flow through into the persisted breakdown ----------
    echo "=== PayrollRunModel::recalculate(): template payee/destination on recurring_deduction lines ===\n";
    $recalcRes = $runModel->recalculate($runId, $compId, $userId, true);
    checkTrue('recalculate succeeds' . (empty($recalcRes['status']) ? " ({$recalcRes['message']})" : ''), $recalcRes['status']);
    $detailsAfterCalc = $runModel->getDetails($runId, $compId);
    $rowA = current(array_filter($detailsAfterCalc, fn($d) => (int)$d['employee_id'] === $employeeAId));
    $lineOtherPerson = current(array_filter($rowA['deduction_breakdown'], fn($l) => ($l['recurring_id'] ?? null) === $recurringOtherPersonId));
    checkTrue('other_person recurring line carries destination_id from the template', $lineOtherPerson !== false);
    check('other_person line payee_type', $lineOtherPerson['payee_type'] ?? null, 'other_person');
    check('other_person line destination_id matches the template', (int)($lineOtherPerson['destination_id'] ?? -1), $templateDestinationId);

    $rowB = current(array_filter($detailsAfterCalc, fn($d) => (int)$d['employee_id'] === $employeeBId));
    $transferInLine = current(array_filter($rowB['earning_breakdown'], fn($l) => $l['code'] === 'TRANSFER_IN'));
    checkTrue('B received a real TRANSFER_IN earning line from A\'s employee-payee recurring deduction (pre-existing mechanism, unchanged)', $transferInLine !== false);
    check('TRANSFER_IN amount matches the recurring deduction amount exactly', (float)($transferInLine['amount'] ?? null), 150.0);

    // ---------- Run-level override: this process only, template untouched ----------
    echo "=== PayrollRunModel::recurringDeductionDestinationOverrideSave(): process-level only ===\n";
    $listBeforeOverride = $runModel->recurringDeductionDestinationsForEmployee($runId, $compId, $employeeAId);
    $rowBeforeOverride = current(array_filter($listBeforeOverride, fn($r) => $r['recurring_id'] === $recurringOtherPersonId));
    checkTrue('recurringDeductionDestinationsForEmployee() lists the row before any override', $rowBeforeOverride !== false);
    check('no override present yet', $rowBeforeOverride['override'], null);
    check('template_payee_type reflects the template default', $rowBeforeOverride['template_payee_type'] ?? null, 'other_person');

    $overrideRes = $runModel->recurringDeductionDestinationOverrideSave($runId, $compId, $recurringOtherPersonId, ['payee_type' => 'company'], $userId, true);
    checkTrue('override save() to company succeeds' . (empty($overrideRes['status']) ? " ({$overrideRes['message']})" : ''), $overrideRes['status']);

    $detailsAfterOverride = $runModel->getDetails($runId, $compId);
    $rowAAfterOverride = current(array_filter($detailsAfterOverride, fn($d) => (int)$d['employee_id'] === $employeeAId));
    $lineAfterOverride = current(array_filter($rowAAfterOverride['deduction_breakdown'], fn($l) => ($l['recurring_id'] ?? null) === $recurringOtherPersonId));
    check('effective payee_type is now company (the OVERRIDE), after only ONE Save call', $lineAfterOverride['payee_type'] ?? null, 'company');
    check('effective destination_id cleared (company has no destination row)', $lineAfterOverride['destination_id'], null);

    $templateStillOtherPerson = $recModel->get($recurringOtherPersonId, $compId);
    check('the TEMPLATE row itself is completely untouched by the run-level override', $templateStillOtherPerson['payee_type'], 'other_person');
    check('the TEMPLATE\'s own destination_id is untouched too', (int)$templateStillOtherPerson['destination_id'], $templateDestinationId);

    $listAfterOverride = $runModel->recurringDeductionDestinationsForEmployee($runId, $compId, $employeeAId);
    $rowAfterOverride = current(array_filter($listAfterOverride, fn($r) => $r['recurring_id'] === $recurringOtherPersonId));
    checkTrue('recurringDeductionDestinationsForEmployee() now reports an active override', $rowAfterOverride['override'] !== null);
    check('override payee_type reported correctly', $rowAfterOverride['override']['payee_type'] ?? null, 'company');
    check('template_payee_type in the same response STILL shows the template\'s own default (other_person), for comparison', $rowAfterOverride['template_payee_type'] ?? null, 'other_person');

    echo "=== PayrollRunModel::recurringDeductionDestinationOverrideRemove(): reverts to template ===\n";
    $removeRes = $runModel->recurringDeductionDestinationOverrideRemove($runId, $compId, $recurringOtherPersonId, $userId, true);
    checkTrue('override remove() succeeds' . (empty($removeRes['status']) ? " ({$removeRes['message']})" : ''), $removeRes['status']);
    $detailsAfterRemove = $runModel->getDetails($runId, $compId);
    $rowAAfterRemove = current(array_filter($detailsAfterRemove, fn($d) => (int)$d['employee_id'] === $employeeAId));
    $lineAfterRemove = current(array_filter($rowAAfterRemove['deduction_breakdown'], fn($l) => ($l['recurring_id'] ?? null) === $recurringOtherPersonId));
    check('effective payee_type reverted to the template\'s own other_person', $lineAfterRemove['payee_type'] ?? null, 'other_person');
    check('effective destination_id reverted to the template\'s own destination', (int)($lineAfterRemove['destination_id'] ?? -1), $templateDestinationId);

    // ---------- Remittance grouping picks up recurring-deduction-routed lines with zero changes ----------
    echo "=== PayrollRemittanceModel::generateForRun(): recurring deduction lines feed the same grouping ===\n";
    $pdo->prepare("UPDATE `payroll_runs` SET state = 'approved' WHERE id = :id")->execute([':id' => $runId]);
    $remittanceModel = new PayrollRemittanceModel($pdo);
    $genRes = $remittanceModel->generateForRun($runId, $compId, $userId);
    checkTrue('generateForRun() succeeds against a run whose only deduction lines came from recurring deductions' . (empty($genRes['status']) ? " ({$genRes['message']})" : ''), $genRes['status']);
    $remList = $remittanceModel->listForRun($runId, $compId);
    check('exactly 1 remittance (the other_person recurring deduction; the employee-payee one was paid via TRANSFER_IN, no remittance)', count($remList), 1);
    $onlyRow = $remList[0];
    check('remittance destination_type is other_person', $onlyRow['destination_type'], 'other_person');
    check('remittance amount matches the recurring deduction amount', (float)$onlyRow['total_amount'], 200.0);
    check('remittance resolves the destination account name', $onlyRow['destination_account_name'] ?? null, 'Locker Vendor Co.');

    // ---------- PaymentDestinationModel::delete() also blocked by a recurring-deduction reference ----------
    echo "=== PaymentDestinationModel::delete() blocked while a recurring deduction template references it ===\n";
    $destModel = new PaymentDestinationModel($pdo);
    $deleteBlockedRes = $destModel->delete($compId, $templateDestinationId, $userId);
    checkFalse('delete() refuses while employee_recurring_deductions still references this destination', $deleteBlockedRes['status']);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
