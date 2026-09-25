<?php
/**
 * Verifies the round-B fix to BankTransferFileReport (2026-09-25, see
 * docs/decisions/2026-09-25-bank-transfer-export-paid-runs.md): a paid/locked run's transfer file
 * used to always throw 'bank_transfer_no_valid_accounts' because net_amount_due (still-owed) is 0
 * for everyone once markPaid() has recorded the full amount -- this checks R1 (paid/locked uses the
 * amount actually recorded as paid via bank_transfer, capped at net_amount), R2 (an approved run's
 * output is completely unaffected), and R3 (never throws for "nobody in the file", always returns a
 * real file + a warning breakdown instead). Not PHPUnit -- see tests/statutory_engine_test.php for
 * why. Runs against the real dev DB inside a transaction that is always rolled back, using its own
 * fresh throwaway company (same isolation convention as tests/payroll_run_employee_bank_account_test.php),
 * never touches run 1014/752 or employee EM009.
 * Run with: php tests/bank_transfer_export_paid_locked_test.php
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
require_once __DIR__ . '/../app/models/EmployeePaymentMethodModel.php';
require_once __DIR__ . '/../app/models/PayrollReportDataModel.php';
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
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }
/** Finds one {key,params} entry by key inside $result['warnings'] (the list generate() attaches --
 *  see BankTransferFileReport::generate()'s own docblock on why it's a list, not a single
 *  warning_key/params pair, since round B's follow-up round). Returns null if absent. */
function findWarning(array $result, string $key): ?array {
    foreach ($result['warnings'] ?? [] as $w) {
        if (($w['key'] ?? null) === $key) return $w;
    }
    return null;
}

try {
    $userId = 1;
    $compCode = 'BTPL_' . uniqid();
    $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', :comp_code)")
        ->execute([':name' => 'Bank Transfer Paid/Locked Test Co ' . uniqid(), ':comp_code' => $compCode]);
    $compId = (int)$pdo->lastInsertId();

    $transferMethodId = (int)$pdo->query("SELECT id FROM master_payment_methods WHERE code = 'transfer'")->fetchColumn();
    $cashMethodId = (int)$pdo->query("SELECT id FROM master_payment_methods WHERE code = 'cash'")->fetchColumn();
    $mixedMethodId = (int)$pdo->query("SELECT id FROM master_payment_methods WHERE code = 'mixed'")->fetchColumn();
    checkTrue('fixture: master_payment_methods resolved', $transferMethodId > 0 && $cashMethodId > 0 && $mixedMethodId > 0);

    /** Minimal employee: sso_enrolled=0/pvd_enrolled=0/tax_exempt=1 keeps net_amount == base_salary
     *  exactly (no statutory noise), same convention tests/payroll_run_employee_bank_account_test.php
     *  already uses. $hasBankAccount=false leaves bank_id/bank_account_no NULL (the exact gap round
     *  A found no fixture ever covered -- a 'transfer' employee missing their own account details). */
    function makeEmployee(PDO $pdo, int $compId, string $tag, int $paymentMethodId, bool $hasBankAccount): int {
        $empNo = 'BTPL_' . $tag . '_' . uniqid();
        $bankId = $hasBankAccount ? 1 : null;
        $accountNo = null;
        $keyVersion = null;
        if ($hasBankAccount) {
            $enc = EncryptionService::encrypt('99988877' . random_int(0, 9) . random_int(0, 9));
            $accountNo = $enc['value'];
            $keyVersion = $enc['key_version'];
        }
        $pdo->prepare("INSERT INTO `employees`
            (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
             personal_email, mobile_no, address_line_1_register, address_line_1_contact,
             emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
             employment_date, employment_status, employment_type, workforce_type, record_time_method,
             payment_method_id, bank_id, bank_account_no, key_version, bank_account_name,
             salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt)
            VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :tag, :name_en, :tag, '1990-01-01', 'Thai',
             :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
             '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
             :payment_method_id, :bank_id, :bank_account_no, :key_version, 'Test Account',
             'monthly', 30000, '2020-01-01', 'average', 'active', 0, 0, 1)")
            ->execute([
                ':comp_id' => $compId, ':employee_no' => $empNo, ':email' => uniqid() . '@test.local',
                ':name_th' => 'ทดสอบ' . $tag, ':name_en' => 'Test', ':tag' => $tag,
                ':payment_method_id' => $paymentMethodId, ':bank_id' => $bankId,
                ':bank_account_no' => $accountNo, ':key_version' => $keyVersion,
            ]);
        return (int)$pdo->lastInsertId();
    }

    $cycleModel = new PayrollCycleModel($pdo);
    /** Cycle with NO bank_file_format_id override needed (format id=1 has zero seeded fields --
     *  confirmed via SELECT -- so BankTransferFileReport always falls back to the generic CSV
     *  render for it, same as omitting the config entirely). */
    function makeCycle(PayrollCycleModel $cycleModel, int $compId, int $userId, string $tag, int $bankFileFormatId = 1): int {
        $cycleRes = $cycleModel->save($compId, [
            'cycle_name' => 'BTPL_CYCLE_' . $tag . '_' . uniqid(), 'payroll_frequency' => 'monthly',
            'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
            'bank_file_format_id' => $bankFileFormatId, 'status' => 'active',
        ], $userId);
        checkTrue("fixture: cycle created ({$tag})" . (empty($cycleRes['status']) ? " ({$cycleRes['message']})" : ''), $cycleRes['status']);
        return (int)$cycleRes['id'];
    }

    /** Run created + recalculated ONCE (every employee must already be cycle-scoped before this is
     *  called), then force-set to 'approved' via raw SQL (same shortcut
     *  tests/payroll_run_employee_bank_account_test.php already uses -- submit()/approve()'s own
     *  approval-workflow plumbing is irrelevant to what this file is testing). */
    function makeApprovedRunForCycle(PDO $pdo, PayrollRunModel $runModel, int $compId, int $userId, int $cycleId, string $tag): int {
        $runCreate = $runModel->create($compId, [
            'cycle_id' => $cycleId, 'run_name' => 'BTPL Run ' . $tag,
            'period_start_date' => '2027-09-21', 'period_end_date' => '2027-10-20', 'payment_date' => '2027-09-25',
        ], $userId, true);
        checkTrue("fixture: run created ({$tag})" . (empty($runCreate['status']) ? " ({$runCreate['message']})" : ''), $runCreate['status']);
        $runId = $runCreate['id'];
        $recalc = $runModel->recalculate($runId, $compId, $userId, true);
        checkTrue("fixture: run recalculates ({$tag})" . (empty($recalc['status']) ? " ({$recalc['message']})" : ''), $recalc['status']);
        $pdo->prepare("UPDATE `payroll_runs` SET state = 'approved' WHERE id = :id")->execute([':id' => $runId]);
        return $runId;
    }

    $runModel = new PayrollRunModel($pdo);
    $bankAccountModel = new BankAccountModel();
    $companyAccount = $bankAccountModel->save($compId, ['bank_id' => 1, 'account_no' => '1112223334', 'account_name' => 'Payroll Account', 'is_default' => true], $userId);
    checkTrue('fixture: company bank account created' . (empty($companyAccount['status']) ? " ({$companyAccount['message']})" : ''), $companyAccount['status']);
    $companyAccountId = $companyAccount['id'];

    /* ==================== Scenario 1: approved run, unaffected (R2) + closes the round-A gap
     * (a 'transfer' employee missing their own bank_account_no was never covered by any existing
     * fixture) -- also the first real coverage of R3's partial-skip warning at 'approved' state. */
    echo "=== Scenario 1: approved run -- unaffected by the paid/locked override, missing-account skip surfaced ===\n";
    $s1CycleId = makeCycle($cycleModel, $compId, $userId, 'S1');
    $s1EmpOk = makeEmployee($pdo, $compId, 'S1_OK', $transferMethodId, true);
    $s1EmpNoAccount = makeEmployee($pdo, $compId, 'S1_NOACC', $transferMethodId, false);
    $pdo->prepare("UPDATE `employees` SET cycle_id = :cid WHERE id IN (:a, :b)")
        ->execute([':cid' => $s1CycleId, ':a' => $s1EmpOk, ':b' => $s1EmpNoAccount]);
    $s1RunId = makeApprovedRunForCycle($pdo, $runModel, $compId, $userId, $s1CycleId, 'S1');
    $s1NetOk = (float)$pdo->query("SELECT net_amount FROM payroll_run_details WHERE run_id = {$s1RunId} AND employee_id = {$s1EmpOk}")->fetchColumn();
    checkTrue('S1: fixture net_amount is positive', $s1NetOk > 0);

    $s1Report = new BankTransferFileReport();
    $s1Result = $s1Report->generate(['comp_id' => $compId, 'run_id' => $s1RunId], 'csv');
    check('S1: mime_type is generic CSV (no bank_file_format configured)', $s1Result['mime_type'], 'text/csv');
    checkTrue('S1: included employee amount is the full due amount (approved, untouched)', strpos($s1Result['content'], number_format($s1NetOk, 2, '.', '')) !== false);
    checkTrue('S1: no "already paid" notice (approved, not paid/locked)', findWarning($s1Result, 'bank_transfer_already_paid_notice') === null);
    $s1Excluded = findWarning($s1Result, 'bank_transfer_employees_excluded');
    checkTrue('S1: employees_excluded warning present (1 employee skipped for missing account)', $s1Excluded !== null);
    check('S1: warning no_account == 1', $s1Excluded['params']['no_account'] ?? null, 1);
    check('S1: warning no_amount == 0', $s1Excluded['params']['no_amount'] ?? null, 0);
    check('S1: warning included == 1', $s1Excluded['params']['included'] ?? null, 1);

    /* ==================== Scenario 2: paid, then locked -- plain transfer employee's full net
     * amount is included (R1), same run carried through both states. */
    echo "=== Scenario 2: paid then locked -- plain transfer employee included at full net_amount ===\n";
    $s2CycleId = makeCycle($cycleModel, $compId, $userId, 'S2');
    $s2Emp = makeEmployee($pdo, $compId, 'S2', $transferMethodId, true);
    $pdo->prepare("UPDATE `employees` SET cycle_id = :cid WHERE id = :e")->execute([':cid' => $s2CycleId, ':e' => $s2Emp]);
    $s2RunId = makeApprovedRunForCycle($pdo, $runModel, $compId, $userId, $s2CycleId, 'S2');
    $s2Net = (float)$pdo->query("SELECT net_amount FROM payroll_run_details WHERE run_id = {$s2RunId} AND employee_id = {$s2Emp}")->fetchColumn();
    checkTrue('S2: fixture net_amount is positive', $s2Net > 0);

    $s2MarkPaid = $runModel->markPaid($s2RunId, $compId, $userId, true, ['payment_method' => 'bank_transfer']);
    checkTrue('S2: markPaid succeeds' . (empty($s2MarkPaid['status']) ? " ({$s2MarkPaid['message']})" : ''), $s2MarkPaid['status']);

    $s2Report = new BankTransferFileReport();
    $s2ResultPaid = $s2Report->generate(['comp_id' => $compId, 'run_id' => $s2RunId], 'csv');
    checkTrue('S2 (paid): no throw, file generated', $s2ResultPaid !== null);
    checkTrue('S2 (paid): full net_amount appears in the file (was 0/throw before this fix)', strpos($s2ResultPaid['content'], number_format($s2Net, 2, '.', '')) !== false);
    checkTrue('S2 (paid): no employees_excluded warning (nobody skipped)', findWarning($s2ResultPaid, 'bank_transfer_employees_excluded') === null);
    $s2NoticePaid = findWarning($s2ResultPaid, 'bank_transfer_already_paid_notice');
    checkTrue('S2 (paid): "already paid" notice fires even with 100% inclusion (requirement added after initial round B)', $s2NoticePaid !== null);
    check('S2 (paid): notice params.included == 1', $s2NoticePaid['params']['included'] ?? null, 1);

    $s2Lock = $runModel->lock($s2RunId, $compId, $userId, true);
    checkTrue('S2: lock succeeds' . (empty($s2Lock['status']) ? " ({$s2Lock['message']})" : ''), $s2Lock['status']);
    $s2ResultLocked = $s2Report->generate(['comp_id' => $compId, 'run_id' => $s2RunId], 'csv');
    checkTrue('S2 (locked): same full net_amount still appears', strpos($s2ResultLocked['content'], number_format($s2Net, 2, '.', '')) !== false);
    checkTrue('S2 (locked): "already paid" notice still fires', findWarning($s2ResultLocked, 'bank_transfer_already_paid_notice') !== null);

    /* ==================== Scenario 3: partial skip in a paid+locked run -- plain transfer
     * (included), missing-account transfer (skip no_account), mixed percent-only (included at its
     * own transfer-line %, not full net pay), mixed with a non-reconciling fixed line (skip
     * reconciliation). Covers R1 (mixed in paid/locked), R3 (per-reason breakdown, "ตกบางคน"). */
    echo "=== Scenario 3: paid+locked run, 4 employees, all 3 skip reasons + a reconciling mixed line ===\n";
    $s3CycleId = makeCycle($cycleModel, $compId, $userId, 'S3');
    $s3EmpOk = makeEmployee($pdo, $compId, 'S3_OK', $transferMethodId, true);
    $s3EmpNoAccount = makeEmployee($pdo, $compId, 'S3_NOACC', $transferMethodId, false);
    $s3EmpMixedOk = makeEmployee($pdo, $compId, 'S3_MIXOK', $mixedMethodId, true);
    $s3EmpMixedBad = makeEmployee($pdo, $compId, 'S3_MIXBAD', $mixedMethodId, true);
    $pdo->prepare("UPDATE `employees` SET cycle_id = :cid WHERE id IN (:a, :b, :c, :d)")
        ->execute([':cid' => $s3CycleId, ':a' => $s3EmpOk, ':b' => $s3EmpNoAccount, ':c' => $s3EmpMixedOk, ':d' => $s3EmpMixedBad]);

    $paymentMethodModel = new EmployeePaymentMethodModel($pdo);
    $paymentMethodModel->saveLines($s3EmpMixedOk, [
        ['sort_order' => 0, 'payment_method_id' => $transferMethodId, 'amount_type' => 'percent', 'amount_value' => 60, 'bank_account_id' => $companyAccountId],
        ['sort_order' => 1, 'payment_method_id' => $cashMethodId, 'amount_type' => 'percent', 'amount_value' => 40, 'bank_account_id' => null],
    ], $userId);
    $paymentMethodModel->saveLines($s3EmpMixedBad, [
        // A fixed amount nowhere near this employee's real net pay -- must NOT reconcile.
        ['sort_order' => 0, 'payment_method_id' => $transferMethodId, 'amount_type' => 'fixed', 'amount_value' => 1.00, 'bank_account_id' => $companyAccountId],
    ], $userId);

    $s3RunId = makeApprovedRunForCycle($pdo, $runModel, $compId, $userId, $s3CycleId, 'S3');
    $s3NetMixedOk = (float)$pdo->query("SELECT net_amount FROM payroll_run_details WHERE run_id = {$s3RunId} AND employee_id = {$s3EmpMixedOk}")->fetchColumn();
    checkTrue('S3: mixed-ok fixture net_amount is positive', $s3NetMixedOk > 0);

    $s3MarkPaid = $runModel->markPaid($s3RunId, $compId, $userId, true, ['payment_method' => 'bank_transfer']);
    checkTrue('S3: markPaid succeeds' . (empty($s3MarkPaid['status']) ? " ({$s3MarkPaid['message']})" : ''), $s3MarkPaid['status']);
    $s3Lock = $runModel->lock($s3RunId, $compId, $userId, true);
    checkTrue('S3: lock succeeds' . (empty($s3Lock['status']) ? " ({$s3Lock['message']})" : ''), $s3Lock['status']);

    $s3Report = new BankTransferFileReport();
    $s3Result = $s3Report->generate(['comp_id' => $compId, 'run_id' => $s3RunId], 'csv');
    checkTrue('S3: "already paid" notice present (locked)', findWarning($s3Result, 'bank_transfer_already_paid_notice') !== null);
    $s3Excluded = findWarning($s3Result, 'bank_transfer_employees_excluded');
    checkTrue('S3: employees_excluded warning present too (both fire together)', $s3Excluded !== null);
    check('S3: warning included == 2 (plain-ok + mixed-ok transfer line)', $s3Excluded['params']['included'] ?? null, 2);
    check('S3: warning no_account == 1 (S3_NOACC)', $s3Excluded['params']['no_account'] ?? null, 1);
    check('S3: warning reconciliation == 1 (S3_MIXBAD)', $s3Excluded['params']['reconciliation'] ?? null, 1);
    check('S3: warning no_amount == 0', $s3Excluded['params']['no_amount'] ?? null, 0);
    $s3ExpectedMixedTransfer = round($s3NetMixedOk * 0.6, 2);
    checkTrue("S3: mixed employee's transfer line is 60% of net pay ({$s3ExpectedMixedTransfer}), not the full amount", strpos($s3Result['content'], number_format($s3ExpectedMixedTransfer, 2, '.', '')) !== false);

    /* ==================== Scenario 4: reopen + repay (multiple payment_events for the same
     * run+employee) -- summed, and never exceeding net_amount even if the ledger somehow holds
     * more than that (defensive cap, R1's own "ห้ามเกิน net_amount"). */
    echo "=== Scenario 4: reopen + repay -- events summed, capped at net_amount ===\n";
    $s4CycleId = makeCycle($cycleModel, $compId, $userId, 'S4');
    $s4Emp = makeEmployee($pdo, $compId, 'S4', $transferMethodId, true);
    $pdo->prepare("UPDATE `employees` SET cycle_id = :cid WHERE id = :e")->execute([':cid' => $s4CycleId, ':e' => $s4Emp]);
    $s4RunId = makeApprovedRunForCycle($pdo, $runModel, $compId, $userId, $s4CycleId, 'S4');
    $s4NetOriginal = (float)$pdo->query("SELECT net_amount FROM payroll_run_details WHERE run_id = {$s4RunId} AND employee_id = {$s4Emp}")->fetchColumn();

    $s4MarkPaid1 = $runModel->markPaid($s4RunId, $compId, $userId, true, ['payment_method' => 'bank_transfer']);
    checkTrue('S4: first markPaid succeeds' . (empty($s4MarkPaid1['status']) ? " ({$s4MarkPaid1['message']})" : ''), $s4MarkPaid1['status']);
    $s4Lock1 = $runModel->lock($s4RunId, $compId, $userId, true);
    checkTrue('S4: first lock succeeds' . (empty($s4Lock1['status']) ? " ({$s4Lock1['message']})" : ''), $s4Lock1['status']);
    $s4Reopen = $runModel->reopen($s4RunId, $compId, $userId, true, 'test reopen');
    checkTrue('S4: reopen succeeds' . (empty($s4Reopen['status']) ? " ({$s4Reopen['message']})" : ''), $s4Reopen['status']);

    // Simulate a supplemental merge adding new pay for this employee (out of scope to exercise the
    // real merge feature here -- this test is about the export path, not payroll calculation).
    $s4Bump = 500.00;
    $s4NetNew = round($s4NetOriginal + $s4Bump, 2);
    $pdo->prepare("UPDATE `payroll_run_details` SET gross_amount = gross_amount + :bump, net_amount = net_amount + :bump WHERE run_id = :run_id AND employee_id = :e")
        ->execute([':bump' => $s4Bump, ':run_id' => $s4RunId, ':e' => $s4Emp]);
    $pdo->prepare("UPDATE `payroll_runs` SET state = 'approved' WHERE id = :id")->execute([':id' => $s4RunId]);
    $s4MarkPaid2 = $runModel->markPaid($s4RunId, $compId, $userId, true, ['payment_method' => 'bank_transfer']);
    checkTrue('S4: second markPaid succeeds' . (empty($s4MarkPaid2['status']) ? " ({$s4MarkPaid2['message']})" : ''), $s4MarkPaid2['status']);
    $s4Lock2 = $runModel->lock($s4RunId, $compId, $userId, true);
    checkTrue('S4: second lock succeeds' . (empty($s4Lock2['status']) ? " ({$s4Lock2['message']})" : ''), $s4Lock2['status']);

    $s4EventCount = (int)$pdo->query("SELECT COUNT(*) FROM payroll_run_payment_events WHERE run_id = {$s4RunId} AND employee_id = {$s4Emp}")->fetchColumn();
    check('S4: 2 payment events recorded for this run+employee', $s4EventCount, 2);

    $s4Report = new BankTransferFileReport();
    $s4Result = $s4Report->generate(['comp_id' => $compId, 'run_id' => $s4RunId], 'csv');
    checkTrue("S4: amount reflects BOTH events summed ({$s4NetNew})", strpos($s4Result['content'], number_format($s4NetNew, 2, '.', '')) !== false);
    checkTrue('S4: "already paid" notice fires (locked, 100% inclusion, no exclusion warning)', findWarning($s4Result, 'bank_transfer_already_paid_notice') !== null);
    checkTrue('S4: no employees_excluded warning (nobody skipped)', findWarning($s4Result, 'bank_transfer_employees_excluded') === null);

    // Defensive cap: a data anomaly (more recorded than the run ever owed) must never inflate what
    // a consumer sees -- inserted directly (not reachable via markPaid() in normal operation).
    $pdo->prepare("INSERT INTO `payroll_run_payment_events` (run_id, employee_id, gross_amount_paid, deduction_amount_paid, net_amount_paid, payment_method, paid_by)
        VALUES (:run_id, :e, 0, 0, 99999.00, 'bank_transfer', :uid)")
        ->execute([':run_id' => $s4RunId, ':e' => $s4Emp, ':uid' => $userId]);
    $s4ResultAnomaly = $s4Report->generate(['comp_id' => $compId, 'run_id' => $s4RunId], 'csv');
    checkFalse('S4 (anomaly): the inflated raw sum (99999+) does NOT appear', strpos($s4ResultAnomaly['content'], '99999') !== false);
    checkTrue("S4 (anomaly): amount is capped at net_amount ({$s4NetNew}), not the inflated sum", strpos($s4ResultAnomaly['content'], number_format($s4NetNew, 2, '.', '')) !== false);

    /* ==================== Scenario 5: locked run paid entirely via cash -- nobody transferred,
     * still no throw, empty-but-valid file + warning (R3's own "ไม่มีคนเข้าเลย" case, the exact
     * production/dev repro shape: employee classified 'transfer' but the batch was recorded as
     * disbursed via cash). */
    echo "=== Scenario 5: locked run, whole batch marked paid via cash -- empty file + warning, no throw ===\n";
    $s5CycleId = makeCycle($cycleModel, $compId, $userId, 'S5');
    $s5Emp = makeEmployee($pdo, $compId, 'S5', $transferMethodId, true);
    $pdo->prepare("UPDATE `employees` SET cycle_id = :cid WHERE id = :e")->execute([':cid' => $s5CycleId, ':e' => $s5Emp]);
    $s5RunId = makeApprovedRunForCycle($pdo, $runModel, $compId, $userId, $s5CycleId, 'S5');
    $s5MarkPaid = $runModel->markPaid($s5RunId, $compId, $userId, true, ['payment_method' => 'cash']);
    checkTrue('S5: markPaid (cash) succeeds' . (empty($s5MarkPaid['status']) ? " ({$s5MarkPaid['message']})" : ''), $s5MarkPaid['status']);
    $s5Lock = $runModel->lock($s5RunId, $compId, $userId, true);
    checkTrue('S5: lock succeeds' . (empty($s5Lock['status']) ? " ({$s5Lock['message']})" : ''), $s5Lock['status']);

    $s5Report = new BankTransferFileReport();
    $s5Exception = null;
    try {
        $s5Result = $s5Report->generate(['comp_id' => $compId, 'run_id' => $s5RunId], 'csv');
    } catch (Throwable $e) {
        $s5Exception = $e;
    }
    checkTrue('S5: no exception thrown (used to throw bank_transfer_no_valid_accounts)', $s5Exception === null);
    if ($s5Exception === null) {
        check('S5: mime_type still a valid CSV', $s5Result['mime_type'], 'text/csv');
        // No detail row for the skipped employee, and no in-file "# คำเตือน" comment row either --
        // no_amount skips are surfaced ONLY via the new warning_key/warning_params channel, unlike
        // the pre-existing missing-account comment row (see renderGenericFallback()'s own docblock
        // on why that one stays byte-identical). A leading "# เลขที่ไฟล์: ..." document-numbering
        // comment row is unrelated to this and may or may not be present.
        checkFalse('S5: no in-file "# คำเตือน" comment row (that channel stays untouched)', strpos($s5Result['content'], '# คำเตือน') !== false);
        checkTrue('S5: header row is present', strpos($s5Result['content'], 'เลขที่บัญชี,ชื่อบัญชี,ธนาคาร,รหัสธนาคาร,จำนวนเงิน,หมายเหตุ') !== false);
        checkTrue('S5: "already paid" notice fires too (locked, alongside the exclusion warning)', findWarning($s5Result, 'bank_transfer_already_paid_notice') !== null);
        $s5Excluded = findWarning($s5Result, 'bank_transfer_employees_excluded');
        checkTrue('S5: employees_excluded warning present', $s5Excluded !== null);
        check('S5: warning included == 0', $s5Excluded['params']['included'] ?? null, 0);
        check('S5: warning no_amount == 1', $s5Excluded['params']['no_amount'] ?? null, 1);
        check('S5: warning total_skipped == 1', $s5Excluded['params']['total_skipped'] ?? null, 1);
        checkTrue('S5: NOT the dedicated no_bank_employees message (this employee WAS a bankish candidate, just paid via cash)', findWarning($s5Result, 'bank_transfer_no_bank_employees') === null);
    }

    /* ==================== Scenario 6: configured (fixed-width) format, same "nobody qualifies"
     * shape -- R3's own "ทั้ง configured format และ generic CSV" requirement. */
    echo "=== Scenario 6: configured bank file format, missing-account employee only -- empty file + warning ===\n";
    $BAY_FORMAT_ID = 9;
    $s6CycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'BTPL_CYCLE_S6_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => $BAY_FORMAT_ID, 'status' => 'active',
    ], $userId);
    checkTrue('S6: cycle created' . (empty($s6CycleRes['status']) ? " ({$s6CycleRes['message']})" : ''), $s6CycleRes['status']);
    $s6Emp = makeEmployee($pdo, $compId, 'S6', $transferMethodId, false);
    $pdo->prepare("UPDATE `employees` SET cycle_id = :cid WHERE id = :e")->execute([':cid' => $s6CycleRes['id'], ':e' => $s6Emp]);
    $s6RunCreate = $runModel->create($compId, [
        'cycle_id' => $s6CycleRes['id'], 'run_name' => 'BTPL Run S6',
        'period_start_date' => '2027-09-21', 'period_end_date' => '2027-10-20', 'payment_date' => '2027-09-25',
    ], $userId, true);
    checkTrue('S6: run created' . (empty($s6RunCreate['status']) ? " ({$s6RunCreate['message']})" : ''), $s6RunCreate['status']);
    $s6RunId = $s6RunCreate['id'];
    $s6Recalc = $runModel->recalculate($s6RunId, $compId, $userId, true);
    checkTrue('S6: recalculate succeeds' . (empty($s6Recalc['status']) ? " ({$s6Recalc['message']})" : ''), $s6Recalc['status']);
    $pdo->prepare("UPDATE `payroll_runs` SET state = 'approved' WHERE id = :id")->execute([':id' => $s6RunId]);

    $s6Report = new BankTransferFileReport();
    $s6Exception = null;
    try {
        $s6Result = $s6Report->generate(['comp_id' => $compId, 'run_id' => $s6RunId], 'csv');
    } catch (Throwable $e) {
        $s6Exception = $e;
    }
    checkTrue('S6: no exception thrown for a configured format either', $s6Exception === null);
    if ($s6Exception === null) {
        checkTrue('S6: configured render still returns a real file (fixed-width/text)', $s6Result['mime_type'] === 'text/plain' || $s6Result['mime_type'] === 'text/csv');
        checkTrue('S6: no "already paid" notice (approved, not paid/locked)', findWarning($s6Result, 'bank_transfer_already_paid_notice') === null);
        $s6Excluded = findWarning($s6Result, 'bank_transfer_employees_excluded');
        checkTrue('S6: employees_excluded warning present', $s6Excluded !== null);
        check('S6: warning no_account == 1', $s6Excluded['params']['no_account'] ?? null, 1);
        check('S6: warning included == 0', $s6Excluded['params']['included'] ?? null, 0);
    }

    /* ==================== Scenario 7: locked run, EVERY employee classified cash/check from the
     * grouping stage itself (nobody ever set up for bank transfer at all) -- must get its OWN
     * dedicated message, not the generic breakdown (which would read as 0/0/0/0 -- meaningless).
     * The "already paid" notice (R1) still fires alongside it (both apply: this run IS locked). */
    echo "=== Scenario 7: locked run, nobody bank-classified at all -- dedicated message, not a 0/0/0/0 breakdown ===\n";
    $s7CycleId = makeCycle($cycleModel, $compId, $userId, 'S7');
    $s7EmpCashA = makeEmployee($pdo, $compId, 'S7_CASHA', $cashMethodId, false);
    $s7EmpCashB = makeEmployee($pdo, $compId, 'S7_CASHB', $cashMethodId, false);
    $pdo->prepare("UPDATE `employees` SET cycle_id = :cid WHERE id IN (:a, :b)")
        ->execute([':cid' => $s7CycleId, ':a' => $s7EmpCashA, ':b' => $s7EmpCashB]);
    $s7RunId = makeApprovedRunForCycle($pdo, $runModel, $compId, $userId, $s7CycleId, 'S7');
    $s7MarkPaid = $runModel->markPaid($s7RunId, $compId, $userId, true, ['payment_method' => 'cash']);
    checkTrue('S7: markPaid (cash) succeeds' . (empty($s7MarkPaid['status']) ? " ({$s7MarkPaid['message']})" : ''), $s7MarkPaid['status']);
    $s7Lock = $runModel->lock($s7RunId, $compId, $userId, true);
    checkTrue('S7: lock succeeds' . (empty($s7Lock['status']) ? " ({$s7Lock['message']})" : ''), $s7Lock['status']);

    $s7Report = new BankTransferFileReport();
    $s7Exception = null;
    try {
        $s7Result = $s7Report->generate(['comp_id' => $compId, 'run_id' => $s7RunId], 'csv');
    } catch (Throwable $e) {
        $s7Exception = $e;
    }
    checkTrue('S7: no exception thrown', $s7Exception === null);
    if ($s7Exception === null) {
        $s7NoBank = findWarning($s7Result, 'bank_transfer_no_bank_employees');
        checkTrue('S7: dedicated no_bank_employees warning present', $s7NoBank !== null);
        check('S7: warning total == 2 (both employees are cash-classified)', $s7NoBank['params']['total'] ?? null, 2);
        checkTrue('S7: NOT the generic employees_excluded breakdown (would be a meaningless 0/0/0/0)', findWarning($s7Result, 'bank_transfer_employees_excluded') === null);
        checkTrue('S7: "already paid" notice ALSO fires (locked) -- both messages together', findWarning($s7Result, 'bank_transfer_already_paid_notice') !== null);
    }

    /* ==================== Scenario 8: same "nobody bank-classified" shape, but at 'approved' state
     * -- confirms the dedicated message is state-independent (no "already paid" notice here, since
     * this run was never paid). */
    echo "=== Scenario 8: approved run, nobody bank-classified -- dedicated message, no 'already paid' notice ===\n";
    $s8CycleId = makeCycle($cycleModel, $compId, $userId, 'S8');
    $s8EmpCash = makeEmployee($pdo, $compId, 'S8_CASH', $cashMethodId, false);
    $pdo->prepare("UPDATE `employees` SET cycle_id = :cid WHERE id = :e")->execute([':cid' => $s8CycleId, ':e' => $s8EmpCash]);
    $s8RunId = makeApprovedRunForCycle($pdo, $runModel, $compId, $userId, $s8CycleId, 'S8');

    $s8Report = new BankTransferFileReport();
    $s8Exception = null;
    try {
        $s8Result = $s8Report->generate(['comp_id' => $compId, 'run_id' => $s8RunId], 'csv');
    } catch (Throwable $e) {
        $s8Exception = $e;
    }
    checkTrue('S8: no exception thrown', $s8Exception === null);
    if ($s8Exception === null) {
        $s8NoBank = findWarning($s8Result, 'bank_transfer_no_bank_employees');
        checkTrue('S8: dedicated no_bank_employees warning present', $s8NoBank !== null);
        check('S8: warning total == 1', $s8NoBank['params']['total'] ?? null, 1);
        checkTrue('S8: no "already paid" notice (approved, never paid)', findWarning($s8Result, 'bank_transfer_already_paid_notice') === null);
    }

    /* ==================== Cleanup ==================== */
    echo "\n=== Summary ===\n";
    echo "Passed: {$passes}, Failed: {$failures}\n";
} catch (Throwable $e) {
    echo "FATAL: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    $pdo->rollBack();
}

exit($failures > 0 ? 1 : 0);
