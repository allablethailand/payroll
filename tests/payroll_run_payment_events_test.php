<?php
/**
 * 2026-08-31, explicit request: real double-payment risk found and confirmed by the user --
 * PayrollRunModel::reopen() has always allowed re-opening an already-paid/locked run, but
 * markPaid()/the payment reports never tracked how much of a run's own total was ALREADY actually
 * disbursed on a prior payment cycle -- a second markPaid() cycle risked re-transferring the SAME
 * base salary a second time on top of whatever new amount triggered the reopen (e.g. merging a
 * supplemental process into an already-paid run). Fixed by recording one payroll_run_payment_events
 * row per employee per markPaid() call (the DELTA actually disbursed that cycle, not the run's own
 * running total) and having BankTransferFileReport read the resulting *_amount_due (delta still
 * owed) instead of the raw net_amount column. 2026-09-25, round B: for a paid/locked run
 * specifically, the report now reads net_amount_paid_via_transfer (what was actually recorded as
 * disbursed) instead of *_amount_due (always 0 once fully paid) -- see
 * docs/decisions/2026-09-25-bank-transfer-export-paid-runs.md; this file's own last assertion
 * block was updated to match.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 * Run with: php tests/payroll_run_payment_events_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/models/PayrollReportDataModel.php';
require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/services/reports/payment/BankTransferFileReport.php';

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

    // Same isolation as tests/reports_test.php/tests/payroll_run_test.php -- comp_id=1 is the real,
    // shared dev DB.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL")
        ->execute([':comp_id' => $compId]);
    $pdo->prepare("UPDATE `approval_workflows` SET status = 'inactive'
        WHERE comp_id = :comp_id AND status = 'active'
          AND id IN (SELECT workflow_id FROM `approval_workflow_document_types` WHERE document_type_code = 'PAYROLL_RUN_APPROVAL')")
        ->execute([':comp_id' => $compId]);

    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of this month')->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of this month')->format('Y-m-d');
    $paymentDate = $periodEnd;

    $cycleModel = new PayrollCycleModel();
    $cycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'PAYEVT_TEST_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: cycle created', $cycleRes['status']);
    $cycleId = $cycleRes['id'];

    $bankAccountNo = '1112223334';
    $encBank = EncryptionService::encrypt($bankAccountNo);
    // Employee A: bank-paid, will receive a merged top-up between the 1st and 2nd payment cycles.
    $insEmpA = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         bank_id, bank_account_no, bank_account_name, key_version,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'จ่ายซ้ำเอ', 'Test', 'PayEventA', '1990-01-01', 'Thai',
         1, :bank_account_no, 'ทดสอบ จ่ายซ้ำเอ', :key_version,
         :email, '0800000001', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active')");
    $insEmpA->execute([
        ':comp_id' => $compId, ':employee_no' => 'PAYEVT_A_' . uniqid(),
        ':bank_account_no' => $encBank['value'], ':key_version' => $encBank['key_version'],
        ':email' => uniqid() . '@test.local',
    ]);
    $employeeAId = (int)$pdo->lastInsertId();
    // Employee B: bank-paid, untouched by the merge -- must show due=0 on the 2nd cycle (skipped
    // from the transfer file entirely, not re-included for the same amount already sent).
    $insEmpB = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         bank_id, bank_account_no, bank_account_name, key_version,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'จ่ายซ้ำบี', 'Test', 'PayEventB', '1990-01-01', 'Thai',
         1, :bank_account_no, 'ทดสอบ จ่ายซ้ำบี', :key_version,
         :email, '0800000002', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 25000, '2020-01-01', 'average', 'active')");
    $insEmpB->execute([
        ':comp_id' => $compId, ':employee_no' => 'PAYEVT_B_' . uniqid(),
        ':bank_account_no' => $encBank['value'], ':key_version' => $encBank['key_version'],
        ':email' => uniqid() . '@test.local',
    ]);
    $employeeBId = (int)$pdo->lastInsertId();

    $pedTypeModel = new PayrollEarningDeductionTypeModel();
    // non_taxable (not calc_sso/calc_pf) deliberately -- this test is about the payment_events
    // delta ledger itself, not tax/SSO accuracy, so a clean +exact-amount net_amount change (no
    // interaction with the tax engine) keeps the assertions below simple and robust.
    $topUpTypeRes = $pedTypeModel->save($compId, [
        'item_code' => 'PEVTU_' . substr(uniqid(), -8),
        'item_name_th' => 'ค่าเที่ยวทดสอบ', 'item_name_en' => 'Test Trip Allowance',
        'item_type' => 'earning', 'calculation_method' => 'manual_entry', 'tax_treatment' => 'non_taxable',
        'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: top-up earning type created', $topUpTypeRes['status']);
    $topUpTypeId = $topUpTypeRes['id'];

    $runModel = new PayrollRunModel();
    $dataModel = new PayrollReportDataModel();
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'PAYEVT_RUN_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $paymentDate,
    ], $adminUserId, true);
    checkTrue('fixture: run created' . (empty($createRes['status']) ? " ({$createRes['message']})" : ''), $createRes['status']);
    $runId = $createRes['id'];
    $recalcRes = $runModel->recalculate($runId, $compId, $adminUserId, true);
    checkTrue('fixture: run calculated' . (empty($recalcRes['status']) ? " ({$recalcRes['message']})" : ''), $recalcRes['status']);
    check('fixture: both employees pulled into the run', (int)$recalcRes['employee_count'], 2);

    $detailsBeforeFirstPay = $dataModel->getRunDetails($runId);
    $detailA0 = current(array_filter($detailsBeforeFirstPay, fn($d) => (int)$d['employee_id'] === $employeeAId));
    check('before any payment event: net_amount_due equals the plain net_amount (no prior events yet)', (float)$detailA0['net_amount_due'], (float)$detailA0['net_amount']);

    echo "=== First payment cycle: delta equals the full amount (no prior events) ===\n";
    checkTrue('fixture: submitted', $runModel->submit($runId, $compId, $adminUserId, true)['status']);
    checkTrue('fixture: approved', $runModel->approve($runId, $compId, $adminUserId, true)['status']);
    $firstPayRes = $runModel->markPaid($runId, $compId, $adminUserId, true, ['payment_method' => 'bank_transfer', 'payment_reference' => 'FIRST_PAY_TEST']);
    checkTrue('markPaid() (1st cycle) succeeds' . (empty($firstPayRes['status']) ? " ({$firstPayRes['message']})" : ''), $firstPayRes['status']);

    $eventsAfterFirstPay = $pdo->query("SELECT employee_id, net_amount_paid FROM payroll_run_payment_events WHERE run_id = {$runId} ORDER BY employee_id")->fetchAll(PDO::FETCH_ASSOC);
    check('exactly 2 payment_events rows after the 1st markPaid() (one per employee)', count($eventsAfterFirstPay), 2);
    $eventA1 = current(array_filter($eventsAfterFirstPay, fn($e) => (int)$e['employee_id'] === $employeeAId));
    $netAmountA0 = (float)$detailA0['net_amount'];
    check('1st cycle event for employee A equals their full net_amount (no prior events)', (float)$eventA1['net_amount_paid'], $netAmountA0);

    echo "=== Reopen + merge (simulating a supplemental top-up) + 2nd payment cycle ===\n";
    $reopenRes = $runModel->reopen($runId, $compId, $adminUserId, true, 'Reopened to merge a test top-up');
    checkTrue('reopen() succeeds' . (empty($reopenRes['status']) ? " ({$reopenRes['message']})" : ''), $reopenRes['status']);
    $topUpAmount = 1500.00;
    $addLineRes = $runModel->addManualLine($runId, $compId, $employeeAId, $topUpTypeId, $topUpAmount, $adminUserId, true, 'Test merge top-up');
    checkTrue('addManualLine() (the simulated merge) succeeds' . (empty($addLineRes['status']) ? " ({$addLineRes['message']})" : ''), $addLineRes['status']);

    $detailsAfterMerge = $dataModel->getRunDetails($runId);
    $detailAMerged = current(array_filter($detailsAfterMerge, fn($d) => (int)$d['employee_id'] === $employeeAId));
    $detailBMerged = current(array_filter($detailsAfterMerge, fn($d) => (int)$d['employee_id'] === $employeeBId));
    check('employee A net_amount increased by exactly the top-up (before-tax earning, no other side effects at this base salary)', (float)$detailAMerged['net_amount'], round($netAmountA0 + $topUpAmount, 2));
    check('*** the core fix *** net_amount_due for employee A is ONLY the new top-up delta, not the full new total', (float)$detailAMerged['net_amount_due'], $topUpAmount);
    check('*** the core fix *** net_amount_due for employee B (untouched by the merge) is 0 -- nothing new to pay them', (float)$detailBMerged['net_amount_due'], 0.0);

    checkTrue('re-submitted after merge', $runModel->submit($runId, $compId, $adminUserId, true)['status']);
    checkTrue('re-approved after merge', $runModel->approve($runId, $compId, $adminUserId, true)['status']);

    // 2026-08-31: generated HERE (state='approved', BEFORE the 2nd markPaid()) -- this is the
    // realistic timing (preview/execute the transfer, THEN mark paid based on that same file).
    // Generating it AFTER markPaid() would correctly show 0 due for everyone (markPaid() itself
    // just recorded that exact delta as paid) -- that is not a bug, it is the ledger doing its job.
    echo "=== BankTransferFileReport reflects only the delta, and skips the unaffected employee ===\n";
    $report = new BankTransferFileReport();
    $genRes = $report->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'th'], 'csv');
    $csvContent = $genRes['content'];
    $bankAccountNoPlain = $bankAccountNo;
    // Generic fallback CSV format: one line per included employee, amount as the 5th field.
    $lines = array_values(array_filter(explode("\r\n", $csvContent), fn($l) => strpos($l, $bankAccountNoPlain) === 0));
    checkTrue('bank transfer CSV has exactly 1 detail line (only employee A has a real delta to transfer this cycle)', count($lines) === 1);
    if (!empty($lines)) {
        $fields = str_getcsv($lines[0]);
        check('the one included line is the top-up delta amount, not the full run total', (float)$fields[4], $topUpAmount);
    }

    $secondPayRes = $runModel->markPaid($runId, $compId, $adminUserId, true, ['payment_method' => 'bank_transfer', 'payment_reference' => 'SECOND_PAY_TEST']);
    checkTrue('markPaid() (2nd cycle) succeeds' . (empty($secondPayRes['status']) ? " ({$secondPayRes['message']})" : ''), $secondPayRes['status']);

    $eventsAfterSecondPay = $pdo->query("SELECT employee_id, net_amount_paid FROM payroll_run_payment_events WHERE run_id = {$runId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check('now 4 payment_events rows total (2 employees x 2 payment cycles, append-only ledger)', count($eventsAfterSecondPay), 4);
    $employeeAEventsSecondCycle = array_values(array_filter($eventsAfterSecondPay, fn($e) => (int)$e['employee_id'] === $employeeAId));
    check('employee A has exactly 2 event rows (one per cycle)', count($employeeAEventsSecondCycle), 2);
    check('employee A 2nd-cycle event amount is exactly the top-up delta (NOT the full new total -- this is the double-payment fix)', (float)$employeeAEventsSecondCycle[1]['net_amount_paid'], $topUpAmount);
    $employeeBEventsSecondCycle = array_values(array_filter($eventsAfterSecondPay, fn($e) => (int)$e['employee_id'] === $employeeBId));
    check('employee B 2nd-cycle event amount is exactly 0 (untouched, correctly recorded as no new disbursement)', (float)$employeeBEventsSecondCycle[1]['net_amount_paid'], 0.0);
    $sumOfBothEventsForA = array_sum(array_column($employeeAEventsSecondCycle, 'net_amount_paid'));
    check('sum of BOTH of employee A\'s event rows equals their final total net_amount (deltas reconcile, nothing lost or double-counted)', $sumOfBothEventsForA, (float)$detailAMerged['net_amount']);

    // 2026-09-25, round B (see docs/decisions/2026-09-25-bank-transfer-export-paid-runs.md):
    // markPaid() has already moved this run's state to 'paid' by this point -- this used to throw
    // 'bank_transfer_no_valid_accounts' here (net_amount_due is 0 for everyone once fully paid,
    // and the old code read net_amount_due even for a paid/locked run), which was the exact
    // production bug that round confirmed and fixed: a paid/locked run's file now reads what was
    // actually recorded as disbursed via bank_transfer (net_amount_paid_via_transfer, capped at
    // net_amount) instead. Both employees were paid via 'bank_transfer' on both cycles here, so
    // both now show their own full, final net_amount -- no longer "nothing left to pay", but "here
    // is everything this run actually transferred", which is what a paid run's own file should mean.
    $reportAfterSettled = $report->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'th'], 'csv');
    checkTrue('no exception after the 2nd markPaid() -- a paid run\'s file reflects what was actually transferred', $reportAfterSettled !== null);
    $linesAfterSettled = array_values(array_filter(explode("\r\n", $reportAfterSettled['content']), fn($l) => strpos($l, $bankAccountNoPlain) === 0));
    check('paid-run file has 2 detail lines (both employees\' full transferred totals, not "nothing due")', count($linesAfterSettled), 2);
    $totalAfterSettled = array_sum(array_map(fn($l) => (float)str_getcsv($l)[4], $linesAfterSettled));
    check('sum of both lines equals A\'s post-merge total + B\'s original total', $totalAfterSettled, round((float)$detailAMerged['net_amount'] + (float)$detailBMerged['net_amount'], 2));
    // 2026-09-25, same-round follow-up: the "already recorded as paid, verify before re-uploading"
    // notice now fires for EVERY paid/locked run regardless of inclusion (not just when someone was
    // excluded) -- but the exclusion breakdown itself must still be absent here (everyone who was
    // ever paid via transfer is genuinely accounted for, nobody skipped).
    $warningKeys = array_column($reportAfterSettled['warnings'] ?? [], 'key');
    checkTrue('"already paid" notice fires (state=paid)', in_array('bank_transfer_already_paid_notice', $warningKeys, true));
    checkTrue('no employees_excluded warning (nobody skipped)', !in_array('bank_transfer_employees_excluded', $warningKeys, true));

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
