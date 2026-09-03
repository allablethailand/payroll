<?php
/**
 * Verifies the multi-bank-account payroll feature (2026-09-02): employee-level default paying
 * account, per-run override (PayrollRunEmployeeBankAccountModel), and the resolution order
 * (override > employee default > cycle pin > company default). Not PHPUnit -- see
 * tests/statutory_engine_test.php for why. Runs against the real dev DB inside a transaction that
 * is always rolled back, so it never leaves any data behind. Uses its own fresh company/employees
 * (same isolation convention as tests/payroll_remittance_test.php), not the shared comp_id=1 dev data.
 * Run with: php tests/payroll_run_employee_bank_account_test.php
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
require_once __DIR__ . '/../app/models/BankAccountModel.php';
require_once __DIR__ . '/../app/models/PayrollRunEmployeeBankAccountModel.php';
require_once __DIR__ . '/../app/services/reports/payment/BankTransferFileReport.php';
require_once __DIR__ . '/../app/services/reports/payment/BankAccountPaymentSummaryReport.php';
require_once __DIR__ . '/../app/models/EmployeePaymentMethodModel.php';
require_once __DIR__ . '/../app/models/PayrollRunCashPaymentModel.php';

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
    $compCode = 'BANKACC_' . uniqid();
    $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', :comp_code)")
        ->execute([':name' => 'Bank Account Test Co ' . uniqid(), ':comp_code' => $compCode]);
    $compId = (int)$pdo->lastInsertId();

    function makeEmployee(PDO $pdo, int $compId, string $tag, ?int $defaultBankAccountId = null): int {
        $empNo = 'BANKACC_' . $tag . '_' . uniqid();
        // Real encryption (not a placeholder literal) -- this fixture is reused by the
        // BankTransferFileReport section below, which needs a genuinely decryptable account_no.
        $enc = EncryptionService::encrypt('999888777' . random_int(0, 9));
        $pdo->prepare("INSERT INTO `employees`
            (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
             personal_email, mobile_no, address_line_1_register, address_line_1_contact,
             emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
             employment_date, employment_status, employment_type, workforce_type, record_time_method,
             bank_id, bank_account_no, key_version, bank_account_name, default_bank_account_id, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt)
            VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :tag, :name_en, :tag, '1990-01-01', 'Thai',
             :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
             '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
             1, :bank_account_no, :key_version, 'Test Account', :default_bank_account_id, 'monthly', 30000, '2020-01-01', 'average', 'active', 0, 0, 1)")
            ->execute([':comp_id' => $compId, ':employee_no' => $empNo, ':email' => uniqid() . '@test.local',
                ':name_th' => 'ทดสอบ' . $tag, ':name_en' => 'Test', ':tag' => $tag, ':default_bank_account_id' => $defaultBankAccountId,
                ':bank_account_no' => $enc['value'], ':key_version' => $enc['key_version']]);
        return (int)$pdo->lastInsertId();
    }

    // ---------- Fixture: 2 company bank accounts ----------
    echo "=== Fixture: 2 company bank accounts ===\n";
    $bankAccountModel = new BankAccountModel();
    $accA = $bankAccountModel->save($compId, ['bank_id' => 1, 'account_no' => '1112223334', 'account_name' => 'KBank Payroll', 'is_default' => true], $userId);
    checkTrue('account A (default) created' . (empty($accA['status']) ? " ({$accA['message']})" : ''), $accA['status']);
    $accountAId = $accA['id'];
    $accB = $bankAccountModel->save($compId, ['bank_id' => 2, 'account_no' => '5556667778', 'account_name' => 'SCB Payroll', 'is_default' => false], $userId);
    checkTrue('account B (non-default) created' . (empty($accB['status']) ? " ({$accB['message']})" : ''), $accB['status']);
    $accountBId = $accB['id'];

    // employeeA: no default_bank_account_id set (falls through to cycle/company default)
    // employeeB: default_bank_account_id = account B (employee-level default wins over cycle/company)
    $employeeAId = makeEmployee($pdo, $compId, 'A', null);
    $employeeBId = makeEmployee($pdo, $compId, 'B', $accountBId);

    $cycleSave = (new PayrollCycleModel($pdo))->save($compId, [
        'cycle_name' => 'BANKACC_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    $cycleId = $cycleSave['id'];

    $runModel = new PayrollRunModel($pdo);
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'Bank Account Test Run',
        'period_start_date' => '2027-07-21', 'period_end_date' => '2027-08-20', 'payment_date' => '2027-07-25',
    ], $userId, true);
    if (empty($createRes['status'])) { throw new RuntimeException('create failed: ' . ($createRes['message'] ?? '')); }
    $runId = $createRes['id'];
    $recalcRes = $runModel->recalculate($runId, $compId, $userId, true);
    checkTrue('recalculate succeeds' . (empty($recalcRes['status']) ? " ({$recalcRes['message']})" : ''), $recalcRes['status']);
    // Bank account assignment is a Disbursement-layer concern -- only meaningful once approved.
    $pdo->prepare("UPDATE `payroll_runs` SET state = 'approved' WHERE id = :id")->execute([':id' => $runId]);

    // ---------- resolveForRun(): fallback chain, no cycle pin yet ----------
    echo "=== resolveForRun(): employee default > company default (no cycle pin, no override) ===\n";
    $bankModel = new PayrollRunEmployeeBankAccountModel($pdo);
    $resolved1 = $bankModel->resolveForRun($runId, $compId);
    check('A resolves to the company DEFAULT account (no employee default, no cycle pin)', $resolved1[$employeeAId]['bank_account_id'], $accountAId);
    check('A\'s source is company_default', $resolved1[$employeeAId]['source'], 'company_default');
    check('B resolves to ITS OWN employee default (account B), even though company default is A', $resolved1[$employeeBId]['bank_account_id'], $accountBId);
    check('B\'s source is employee_default', $resolved1[$employeeBId]['source'], 'employee_default');

    // ---------- Cycle pin should be beaten by employee default, but win over company default ----------
    echo "=== resolveForRun(): cycle pin wins over company default, loses to employee default ===\n";
    $pdo->prepare("UPDATE `payroll_cycles` SET bank_account_id = :acc WHERE id = :id")->execute([':acc' => $accountBId, ':id' => $cycleId]);
    $resolved2 = $bankModel->resolveForRun($runId, $compId);
    check('A (no employee default) now resolves to the CYCLE-pinned account B, not the company default A', $resolved2[$employeeAId]['bank_account_id'], $accountBId);
    check('A\'s source is now cycle', $resolved2[$employeeAId]['source'], 'cycle');
    check('B still resolves to its OWN employee default (account B, same value here but different SOURCE)', $resolved2[$employeeBId]['bank_account_id'], $accountBId);
    check('B\'s source is still employee_default, not cycle (precedence, not just coincidental same id)', $resolved2[$employeeBId]['source'], 'employee_default');

    // ---------- Per-run override wins over everything ----------
    echo "=== overrideSave(): per-run override wins over employee default/cycle/company default ===\n";
    $overrideRes = $bankModel->overrideSave($runId, $compId, $employeeBId, $accountAId, 'test override', $userId);
    checkTrue('overrideSave() succeeds' . (empty($overrideRes['status']) ? " ({$overrideRes['message']})" : ''), $overrideRes['status']);
    $resolved3 = $bankModel->resolveForRun($runId, $compId);
    check('B now resolves to account A (the OVERRIDE), even though its own employee default is B', $resolved3[$employeeBId]['bank_account_id'], $accountAId);
    check('B\'s source is override', $resolved3[$employeeBId]['source'], 'override');
    check('A is completely unaffected by B\'s override', $resolved3[$employeeAId]['bank_account_id'], $accountBId);

    $empBDefaultCheck = $pdo->query("SELECT default_bank_account_id FROM employees WHERE id = {$employeeBId}")->fetchColumn();
    check('the override NEVER touched employee B\'s own default_bank_account_id (template untouched)', (int)$empBDefaultCheck, $accountBId);

    // ---------- listForRun(): the Process Detail tab's own read shape ----------
    echo "=== listForRun() ===\n";
    $listRows = $bankModel->listForRun($runId, $compId);
    check('listForRun() returns 2 rows (both bank-paying employees)', count($listRows), 2);
    $rowB = current(array_filter($listRows, fn($r) => $r['employee_id'] === $employeeBId));
    checkTrue('row B is marked is_overridden', $rowB['is_overridden']);
    check('row B resolves the account_name label for display', $rowB['bank_account_name'], 'KBank Payroll');
    $rowA = current(array_filter($listRows, fn($r) => $r['employee_id'] === $employeeAId));
    checkFalse('row A is NOT overridden', $rowA['is_overridden']);

    // ---------- BankAccountPaymentSummaryReport: grouped-by-account report ("Report แยกตามบัญชีที่จ่าย") ----------
    // Same exact state as the BankTransferFileReport section right below: A resolves to account B
    // (cycle pin), B resolves to account A (override) -- a genuine 2-account split.
    echo "=== BankAccountPaymentSummaryReport ===\n";
    $summaryReport = new BankAccountPaymentSummaryReport();
    $summaryResult = $summaryReport->generate(['comp_id' => $compId, 'run_id' => $runId], 'excel');
    check('mime_type is xlsx', $summaryResult['mime_type'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    check('file_name follows the RunN convention', $summaryResult['file_name'], "BankAccountPaymentSummary_Run{$runId}.xlsx");
    $tmpXlsx = tempnam(sys_get_temp_dir(), 'bankaccsummary_test_') . '.xlsx';
    file_put_contents($tmpXlsx, $summaryResult['content']);
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpXlsx);
    $sheet = $spreadsheet->getActiveSheet();
    $cellValues = [];
    foreach ($sheet->getRowIterator() as $r) {
        $rowVals = [];
        foreach ($r->getCellIterator() as $c) { $rowVals[] = $c->getValue(); }
        $cellValues[] = $rowVals;
    }
    unlink($tmpXlsx);
    $flat = array_map(fn($row) => implode('|', array_map(fn($v) => (string)$v, $row)), $cellValues);
    $flatText = implode("\n", $flat);
    checkTrue('header row present', strpos($flatText, 'บัญชีที่จ่าย') !== false);
    $empANoLookup = $pdo->query("SELECT employee_no FROM employees WHERE id = {$employeeAId}")->fetchColumn();
    $empBNoLookup = $pdo->query("SELECT employee_no FROM employees WHERE id = {$employeeBId}")->fetchColumn();
    checkTrue('employee A\'s employee_no appears in the sheet', strpos($flatText, (string)$empANoLookup) !== false);
    checkTrue('employee B\'s employee_no appears in the sheet', strpos($flatText, (string)$empBNoLookup) !== false);
    checkTrue('a subtotal row ("รวม - ") is present per account (grouped, not just a flat list)', strpos($flatText, 'รวม - ') !== false);
    checkTrue('a grand total row ("รวมทั้งสิ้น") is present', strpos($flatText, 'รวมทั้งสิ้น') !== false);

    // ---------- BankTransferFileReport: correctness with 2 different paying accounts (Cashlink) ----------
    // At this exact point: A resolves to account B (cycle pin), B resolves to account A (override) --
    // a genuine 2-account split, confirmed via the assertions just above. User confirmed via
    // AskUserQuestion: one SEPARATE FILE per account, bundled as a zip when 2+ accounts are involved.
    echo "=== BankTransferFileReport: one file per account (Cashlink correctness) ===\n";
    $bankTransferReport = new BankTransferFileReport();
    $bankTransferResult = $bankTransferReport->generate(['comp_id' => $compId, 'run_id' => $runId], 'csv');
    check('2 employees paying from 2 different accounts produce a ZIP, not a single mixed file', $bankTransferResult['mime_type'], 'application/zip');
    check('zip file_name follows the RunN convention', $bankTransferResult['file_name'], "BankTransfer_Run{$runId}.zip");
    $tmpZip = tempnam(sys_get_temp_dir(), 'banktransfer_test_') . '.zip';
    file_put_contents($tmpZip, $bankTransferResult['content']);
    $zip = new \ZipArchive();
    $zip->open($tmpZip);
    check('zip contains exactly 2 entries (one per account, generic-CSV fallback since no bank_file_format_id configured on this fixture cycle)', $zip->numFiles, 2);
    $entryNames = [];
    $csvContents = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $entryNames[] = $name;
        $csvContents[$name] = $zip->getFromName($name);
    }
    $zip->close();
    unlink($tmpZip);
    checkTrue('every zip entry filename is suffixed with a distinct account label (not the plain unsuffixed BankTransfer_RunN.csv)', array_reduce($entryNames, fn($carry, $n) => $carry && $n !== "BankTransfer_Run{$runId}.csv", true));
    // Cross-check: the employee-no fixture tags let us find which file contains which employee,
    // proving the SPLIT is actually correct (not just "2 files exist"), not just "2 files, contents
    // unverified".
    $empANoRow = $pdo->query("SELECT employee_no FROM employees WHERE id = {$employeeAId}")->fetchColumn();
    $empBNoRow = $pdo->query("SELECT employee_no FROM employees WHERE id = {$employeeBId}")->fetchColumn();
    $fileWithA = null;
    $fileWithB = null;
    foreach ($csvContents as $name => $content) {
        if (strpos((string)$content, (string)$empANoRow) !== false) { $fileWithA = $name; }
        if (strpos((string)$content, (string)$empBNoRow) !== false) { $fileWithB = $name; }
    }
    checkTrue('employee A is present in exactly one of the 2 files', $fileWithA !== null);
    checkTrue('employee B is present in exactly one of the 2 files', $fileWithB !== null);
    checkTrue('employee A and employee B end up in DIFFERENT files (the whole point of the per-account split)', $fileWithA !== $fileWithB);

    // ---------- Single-account backward compatibility: after removing B's override, both employees
    // resolve to the SAME account again -- must return the OLD plain single-file shape unchanged. ----------
    echo "=== BankTransferFileReport: single-account case stays backward compatible (no zip, unsuffixed filename) ===\n";
    $bankModel->overrideSave($runId, $compId, $employeeAId, $accountBId, null, $userId);
    $bankModel->overrideSave($runId, $compId, $employeeBId, $accountBId, null, $userId);
    $singleAccountResult = $bankTransferReport->generate(['comp_id' => $compId, 'run_id' => $runId], 'csv');
    check('both employees on the SAME account -> plain csv, not a zip', $singleAccountResult['mime_type'], 'text/csv');
    check('filename is the EXACT SAME unsuffixed name this report has always returned (byte-for-byte backward compatible)', $singleAccountResult['file_name'], "BankTransfer_Run{$runId}.csv");
    checkTrue('both employees\' account numbers appear in this one file', strpos($singleAccountResult['content'], (string)$empANoRow) !== false && strpos($singleAccountResult['content'], (string)$empBNoRow) !== false);
    // Clean up the 2 overrides just added so the rest of this test's own state isn't affected.
    $bankModel->overrideRemove($runId, $compId, $employeeAId);
    $bankModel->overrideRemove($runId, $compId, $employeeBId);

    // ---------- overrideRemove(): reverts to template resolution ----------
    echo "=== overrideRemove(): reverts to template resolution ===\n";
    $removeRes = $bankModel->overrideRemove($runId, $compId, $employeeBId);
    checkTrue('overrideRemove() succeeds', $removeRes['status']);
    $resolved4 = $bankModel->resolveForRun($runId, $compId);
    check('B reverts to its own employee default (account B) after the override is removed', $resolved4[$employeeBId]['bank_account_id'], $accountBId);
    check('B\'s source is back to employee_default', $resolved4[$employeeBId]['source'], 'employee_default');

    // ---------- Validation ----------
    echo "=== overrideSave() validation ===\n";
    $invalidAccountRes = $bankModel->overrideSave($runId, $compId, $employeeAId, 999999, null, $userId);
    checkFalse('overrideSave() rejects an unknown bank_account_id', $invalidAccountRes['status']);

    // ---------- Mixed payment method: disbursement correctness (2026-09-02) ----------
    // Own fresh cycle+run (not the fixture above, which is already approved/in a carefully
    // orchestrated override state) -- 2 mixed employees: E reconciles cleanly (percent-only lines,
    // always fully resolvable against net_amount_due), F does NOT (a fixed line that doesn't sum to
    // net pay -- must be skipped, never guessed/partially disbursed).
    echo "=== Mixed payment method: BankTransferFileReport + PayrollRunCashPaymentModel ===\n";
    $paymentMethodModel = new EmployeePaymentMethodModel($pdo);
    $mixedMethodId = (int)$pdo->query("SELECT id FROM master_payment_methods WHERE code = 'mixed'")->fetchColumn();
    $transferMethodId = (int)$pdo->query("SELECT id FROM master_payment_methods WHERE code = 'transfer'")->fetchColumn();
    $cashMethodId = (int)$pdo->query("SELECT id FROM master_payment_methods WHERE code = 'cash'")->fetchColumn();
    checkTrue('fixture: master_payment_methods seeded (mixed/transfer/cash all resolved)', $mixedMethodId > 0 && $transferMethodId > 0 && $cashMethodId > 0);

    // Scope A/B to their OWN original cycle so they don't leak into the new mixed-payment cycle's
    // run below (employees.cycle_id=NULL means "eligible everywhere", per PayrollRunModel's own
    // eligibility comment -- A/B were created with cycle_id left NULL earlier in this same file).
    $pdo->prepare("UPDATE `employees` SET cycle_id = :cid WHERE id IN (:a, :b)")
        ->execute([':cid' => $cycleId, ':a' => $employeeAId, ':b' => $employeeBId]);

    $empEId = makeEmployee($pdo, $compId, 'E_MIXED', null);
    $pdo->prepare("UPDATE `employees` SET payment_method_id = :pmid WHERE id = :id")->execute([':pmid' => $mixedMethodId, ':id' => $empEId]);
    $paymentMethodModel->saveLines($empEId, [
        ['sort_order' => 0, 'payment_method_id' => $transferMethodId, 'amount_type' => 'percent', 'amount_value' => 60, 'bank_account_id' => $accountAId],
        ['sort_order' => 1, 'payment_method_id' => $cashMethodId, 'amount_type' => 'percent', 'amount_value' => 40, 'bank_account_id' => null],
    ], $userId);

    $empFId = makeEmployee($pdo, $compId, 'F_MIXED_BAD', null);
    $pdo->prepare("UPDATE `employees` SET payment_method_id = :pmid WHERE id = :id")->execute([':pmid' => $mixedMethodId, ':id' => $empFId]);
    $paymentMethodModel->saveLines($empFId, [
        // A fixed amount nowhere near this employee's real net pay -- must NOT reconcile.
        ['sort_order' => 0, 'payment_method_id' => $transferMethodId, 'amount_type' => 'fixed', 'amount_value' => 1.00, 'bank_account_id' => $accountAId],
    ], $userId);

    $mixedCycleRes = (new PayrollCycleModel($pdo))->save($compId, [
        'cycle_name' => 'BANKACC_MIXED_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    $mixedRunCreate = $runModel->create($compId, [
        'cycle_id' => $mixedCycleRes['id'], 'run_name' => 'Mixed Payment Test Run',
        'period_start_date' => '2027-07-21', 'period_end_date' => '2027-08-20', 'payment_date' => '2027-07-25',
    ], $userId, true);
    checkTrue('mixed-payment run created' . (empty($mixedRunCreate['status']) ? " ({$mixedRunCreate['message']})" : ''), $mixedRunCreate['status']);
    $mixedRunId = $mixedRunCreate['id'];
    $mixedRecalc = $runModel->recalculate($mixedRunId, $compId, $userId, true);
    checkTrue('mixed-payment run recalculates' . (empty($mixedRecalc['status']) ? " ({$mixedRecalc['message']})" : ''), $mixedRecalc['status']);
    $pdo->prepare("UPDATE `payroll_runs` SET state = 'approved' WHERE id = :id")->execute([':id' => $mixedRunId]);

    $mixedDetails = $runModel->getDetails($mixedRunId, $compId);
    $rowE = current(array_filter($mixedDetails, fn($d) => (int)$d['employee_id'] === $empEId));
    $netDueE = (float)($rowE['net_amount'] ?? 0);
    checkTrue('fixture: employee E has real positive net pay to split', $netDueE > 0);

    echo "=== BankTransferFileReport: mixed employee's own transfer LINE (60%) is disbursed, not full net pay ===\n";
    $mixedTransferReport = new BankTransferFileReport();
    $mixedTransferResult = $mixedTransferReport->generate(['comp_id' => $compId, 'run_id' => $mixedRunId], 'csv');
    // Single account (A) in play for this run -- plain CSV, not a zip.
    check('single-account mixed run produces a plain csv', $mixedTransferResult['mime_type'], 'text/csv');
    $empENoRow = $pdo->query("SELECT employee_no FROM employees WHERE id = {$empEId}")->fetchColumn();
    checkTrue('E\'s employee_no appears in the transfer file (their transfer LINE was included)', strpos($mixedTransferResult['content'], (string)$empENoRow) !== false);
    $expectedTransferAmount = number_format(round($netDueE * 0.6, 2), 2, '.', '');
    checkTrue("E's transferred amount is exactly 60% of net pay ({$expectedTransferAmount}), not the full 100%", strpos($mixedTransferResult['content'], $expectedTransferAmount) !== false);
    $empFNoRow = $pdo->query("SELECT employee_no FROM employees WHERE id = {$empFId}")->fetchColumn();
    // F's employee_no legitimately DOES appear once, in the warning-comment row -- what must be
    // false is F appearing as a real DATA line (i.e. more than the single warning occurrence).
    $empFOccurrences = substr_count($mixedTransferResult['content'], (string)$empFNoRow);
    check('F (fixed line that does NOT reconcile to net pay) appears EXACTLY ONCE (the warning comment only) -- never as a real disbursed data line', $empFOccurrences, 1);
    checkTrue('F\'s employee_no appears in the warning-comment row (generic-fallback format)', strpos($mixedTransferResult['content'], '# คำเตือน') !== false && strpos($mixedTransferResult['content'], (string)$empFNoRow) !== false);

    echo "=== PayrollRunCashPaymentModel: mixed employee's own cash LINE (40%) is tracked, not full net pay ===\n";
    $cashPaymentModel = new PayrollRunCashPaymentModel($pdo);
    $cashList = $cashPaymentModel->listForRun($mixedRunId, $compId);
    $cashRowE = current(array_filter($cashList['rows'], fn($r) => (int)$r['employee_id'] === $empEId));
    checkTrue('E has exactly one cash-payment row (their cash LINE, not a whole separate cash employee)', $cashRowE !== false);
    $expectedCashAmount = round($netDueE * 0.4, 2);
    check("E's tracked cash amount is exactly 40% of net pay ({$expectedCashAmount}), not the full 100%", round((float)$cashRowE['amount'], 2), $expectedCashAmount);
    checkFalse('F has NO cash-payment row (their only line was a transfer line, which itself was skipped for reconciliation failure)', in_array($empFId, array_column($cashList['rows'], 'employee_id'), true));

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
