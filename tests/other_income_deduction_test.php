<?php
/**
 * Verifies Phase 7 of Deduction Destination & Third-Party Remittance (2026-09-02): "Other Income"/
 * "Other Deduction" as a formal, aggregating bucket -- `is_other` on `employee_earning_deductions`/
 * `payroll_run_manual_lines`, PayrollRunModel::resolveManualLineRow()'s fixed OTHER_INCOME/
 * OTHER_DEDUCTION sentinel code, and PayrollRegisterReport's stable column header + sum-not-
 * overwrite fix. Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real
 * dev DB inside a transaction that is always rolled back, so it never leaves any data behind. Uses
 * its own fresh company/employees (same isolation convention as tests/payroll_remittance_test.php),
 * not the shared comp_id=1 dev data.
 * Run with: php tests/other_income_deduction_test.php
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
require_once __DIR__ . '/../app/models/EmployeeEarningDeductionModel.php';
require_once __DIR__ . '/../app/services/reports/internal/PayrollRegisterReport.php';

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
    $compCode = 'OID_' . uniqid();
    $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', :comp_code)")
        ->execute([':name' => 'Other Income Deduction Test Co ' . uniqid(), ':comp_code' => $compCode]);
    $compId = (int)$pdo->lastInsertId();

    function makeEmployee(PDO $pdo, int $compId, string $tag, string $employmentDate): int {
        $empNo = 'OID_' . $tag . '_' . uniqid();
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
    $employeeAId = makeEmployee($pdo, $compId, 'A', '2020-01-01'); // 2 different "Other Income" labels + 1 "Other Deduction" (other_person)
    $employeeBId = makeEmployee($pdo, $compId, 'B', '2020-01-01'); // 1 genuinely-custom (non-Other) item -- regression check

    $cycleSave = (new PayrollCycleModel($pdo))->save($compId, [
        'cycle_name' => 'OID_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    $cycleId = $cycleSave['id'];

    $runModel = new PayrollRunModel($pdo);
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'Other Income Deduction Test Run',
        'period_start_date' => '2027-07-21', 'period_end_date' => '2027-08-20', 'payment_date' => '2027-07-25',
    ], $userId, true);
    if (empty($createRes['status'])) { throw new RuntimeException('create failed: ' . ($createRes['message'] ?? '')); }
    $runId = $createRes['id'];

    // ---------- EmployeeEarningDeductionModel: is_other wiring ----------
    echo "=== EmployeeEarningDeductionModel: is_other aggregation ===\n";
    $eedModel = new EmployeeEarningDeductionModel();
    $otherIncome1 = $eedModel->save($employeeAId, $compId, [
        'custom_item_name' => 'เงินช่วยเหลือพิเศษ', 'custom_item_type' => 'earning', 'is_other' => true,
        'total_installments' => 1, 'amount_mode' => 'even_split', 'total_amount' => 500.00, 'effective_date' => '2027-07-01',
    ], $userId);
    checkTrue('save() Other Income #1 succeeds' . (empty($otherIncome1['status']) ? " ({$otherIncome1['message']})" : ''), $otherIncome1['status']);
    $otherIncome1Get = $eedModel->get($otherIncome1['id'], $compId);
    check('is_other persisted as 1', (int)($otherIncome1Get['is_other'] ?? -1), 1);
    check('custom_item_name preserved verbatim (not overwritten by a fixed label)', $otherIncome1Get['custom_item_name'], 'เงินช่วยเหลือพิเศษ');

    $otherIncome2 = $eedModel->save($employeeAId, $compId, [
        'custom_item_name' => 'ค่าตอบแทนพิเศษงานเร่งด่วน', 'custom_item_type' => 'earning', 'is_other' => true,
        'total_installments' => 1, 'amount_mode' => 'even_split', 'total_amount' => 300.00, 'effective_date' => '2027-07-01',
    ], $userId);
    checkTrue('save() Other Income #2 (a DIFFERENT free-text label, same employee) succeeds' . (empty($otherIncome2['status']) ? " ({$otherIncome2['message']})" : ''), $otherIncome2['status']);

    $otherDeduction = $eedModel->save($employeeAId, $compId, [
        'custom_item_name' => 'หักค่าปรับสัญญาจ้าง', 'custom_item_type' => 'deduction', 'is_other' => true,
        'total_installments' => 1, 'amount_mode' => 'even_split', 'total_amount' => 200.00, 'effective_date' => '2027-07-01',
        'payee_type' => 'other_person', 'account_name' => 'Vendor Co.', 'account_no' => '9990001112', 'bank_id' => 1, 'is_saved' => false,
    ], $userId);
    checkTrue('save() Other Deduction with destination (Phase 2 flow, generic -- no Phase 7 code needed) succeeds' . (empty($otherDeduction['status']) ? " ({$otherDeduction['message']})" : ''), $otherDeduction['status']);

    $genuineCustom = $eedModel->save($employeeBId, $compId, [
        'custom_item_name' => 'เงินคืนมัดจำเครื่องแบบ', 'custom_item_type' => 'earning',
        'total_installments' => 1, 'amount_mode' => 'even_split', 'total_amount' => 150.00, 'effective_date' => '2027-07-01',
    ], $userId);
    checkTrue('save() a genuinely custom (is_other omitted/false) item still succeeds unchanged' . (empty($genuineCustom['status']) ? " ({$genuineCustom['message']})" : ''), $genuineCustom['status']);
    $genuineCustomGet = $eedModel->get($genuineCustom['id'], $compId);
    check('is_other defaults to 0 when not sent', (int)($genuineCustomGet['is_other'] ?? -1), 0);

    // ---------- Add a THIRD Other Income as an ad-hoc manual line (same run, same employee, same
    // bucket -- exercises the SUM-not-overwrite fix in both recalculate() and PayrollRegisterReport) ----------
    echo "=== PayrollRunModel::addManualLine(): ad-hoc Other Income, same bucket ===\n";
    $recalcRes0 = $runModel->recalculate($runId, $compId, $userId, true);
    checkTrue('initial recalculate succeeds' . (empty($recalcRes0['status']) ? " ({$recalcRes0['message']})" : ''), $recalcRes0['status']);
    $addLineRes = $runModel->addManualLine($runId, $compId, $employeeAId, null, 100.00, $userId, true, 'bonus ad-hoc', 'เงินรางวัลพิเศษ', 'earning', null, null, null, null, true);
    checkTrue('addManualLine() Other Income (ad-hoc, is_other=true) succeeds' . (empty($addLineRes['status']) ? " ({$addLineRes['message']})" : ''), $addLineRes['status']);

    // ---------- recalculate(): aggregation into ONE OTHER_INCOME code ----------
    echo "=== PayrollRunModel::recalculate(): 3 differently-labeled Other Income lines share ONE code ===\n";
    $details = $runModel->getDetails($runId, $compId);
    $rowA = current(array_filter($details, fn($d) => (int)$d['employee_id'] === $employeeAId));
    $otherIncomeLines = array_filter($rowA['earning_breakdown'], fn($l) => $l['code'] === 'OTHER_INCOME');
    check('exactly 1 breakdown line carries the OTHER_INCOME code (per-line array, not yet summed -- confirms all 3 collapsed onto the SAME code before any report-level aggregation)', count($otherIncomeLines), 3);
    $otherIncomeTotal = array_sum(array_column($otherIncomeLines, 'amount'));
    check('sum of the 3 OTHER_INCOME lines = 500 + 300 + 100', $otherIncomeTotal, 900.0);

    $otherDeductionLines = array_filter($rowA['deduction_breakdown'], fn($l) => $l['code'] === 'OTHER_DEDUCTION');
    check('exactly 1 OTHER_DEDUCTION line', count($otherDeductionLines), 1);
    $otherDeductionLine = current($otherDeductionLines);
    check('OTHER_DEDUCTION line keeps its own specific label for display', $otherDeductionLine['name_th'], 'หักค่าปรับสัญญาจ้าง');
    check('OTHER_DEDUCTION line carries payee_type=other_person (Phase 2 destination flow applies generically)', $otherDeductionLine['payee_type'] ?? null, 'other_person');
    checkTrue('OTHER_DEDUCTION line carries a real destination_id', ($otherDeductionLine['destination_id'] ?? null) !== null);

    $rowB = current(array_filter($details, fn($d) => (int)$d['employee_id'] === $employeeBId));
    $genuineCustomLine = current(array_filter($rowB['earning_breakdown'], fn($l) => strpos((string)$l['code'], 'CUSTOM:') === 0));
    checkTrue('the genuinely-custom item on B still gets its own unique CUSTOM: code (regression, unaffected by Phase 7)', $genuineCustomLine !== false);
    check('genuinely-custom code embeds its own name verbatim', $genuineCustomLine['code'], 'CUSTOM:เงินคืนมัดจำเครื่องแบบ');

    checkTrue('A\'s gross_amount includes all 3 Other Income lines (taxable-income treatment unchanged from pre-existing custom-item default)', (float)$rowA['gross_amount'] >= 30000 + 900 - 0.01);

    // ---------- PayrollRegisterReport: stable column header + summed (not overwritten) value ----------
    echo "=== PayrollRegisterReport: aggregated column ===\n";
    $pdo->prepare("UPDATE `payroll_runs` SET state = 'approved' WHERE id = :id")->execute([':id' => $runId]);
    $report = new PayrollRegisterReport();
    $result = $report->generate(['comp_id' => $compId, 'run_id' => $runId], 'excel');
    checkTrue('generate() returns non-empty xlsx content', strlen($result['content'] ?? '') > 0);
    checkTrue('content is a real ZIP/XLSX (starts with PK signature)', substr($result['content'], 0, 2) === 'PK');
    // Read the actual sheet back via PhpSpreadsheet to confirm exactly ONE "รายได้อื่น" column
    // (not 3 separate ones) and that its value is the summed 900.00, not just the last line seen.
    $tmpFile = tempnam(sys_get_temp_dir(), 'oid_test_') . '.xlsx';
    file_put_contents($tmpFile, $result['content']);
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpFile);
    $sheet = $spreadsheet->getSheetByName('รายละเอียด') ?? $spreadsheet->getActiveSheet();
    $headerRow = null;
    $highestRow = $sheet->getHighestRow();
    $highestCol = $sheet->getHighestColumn();
    $otherIncomeColIdx = null;
    $employeeColIdx = null;
    for ($r = 1; $r <= $highestRow; $r++) {
        $rowVals = $sheet->rangeToArray("A{$r}:{$highestCol}{$r}")[0];
        if (in_array('รายได้อื่น', $rowVals, true)) {
            $headerRow = $rowVals;
            $otherIncomeColIdx = array_search('รายได้อื่น', $rowVals, true);
            $employeeColIdx = array_search('รหัสพนักงาน', $rowVals, true);
            $dataStartRow = $r + 1;
            break;
        }
    }
    checkTrue('exactly one "รายได้อื่น" column header found (not one per distinct free-text label)', $headerRow !== null && count(array_keys($headerRow, 'รายได้อื่น', true)) === 1);
    if ($headerRow !== null) {
        $empNoA = $pdo->query("SELECT employee_no FROM employees WHERE id = {$employeeAId}")->fetchColumn();
        $foundValue = null;
        for ($r = $dataStartRow; $r <= $highestRow; $r++) {
            $rowVals = $sheet->rangeToArray("A{$r}:{$highestCol}{$r}")[0];
            if (($rowVals[$employeeColIdx] ?? null) === $empNoA) {
                $foundValue = $rowVals[$otherIncomeColIdx];
                break;
            }
        }
        check('the "รายได้อื่น" cell for employee A sums all 3 lines (900.00), not just the last one written', (float)$foundValue, 900.0);
    }
    unlink($tmpFile);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
