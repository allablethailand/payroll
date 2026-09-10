<?php
/**
 * Verifies PaymentDestinationModel + PayrollRemittanceModel (Deduction Destination & Third-Party
 * Remittance, 2026-09-02). Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs
 * against the real dev DB inside a transaction that is always rolled back, so it never leaves any
 * data behind. Uses its own fresh company/employees (same isolation convention as
 * tests/payroll_run_line_override_history_test.php), not the shared comp_id=1 dev data.
 * Run with: php tests/payroll_remittance_test.php
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
require_once __DIR__ . '/../app/models/PaymentDestinationModel.php';
require_once __DIR__ . '/../app/models/PayrollRemittanceModel.php';
require_once __DIR__ . '/../app/models/BankAccountModel.php';

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
    $compCode = 'REM_' . uniqid();
    $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', :comp_code)")
        ->execute([':name' => 'Remittance Test Co ' . uniqid(), ':comp_code' => $compCode]);
    $compId = (int)$pdo->lastInsertId();

    function makeEmployee(PDO $pdo, int $compId, string $tag, string $employmentDate): int {
        $empNo = 'REM_' . $tag . '_' . uniqid();
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
    $employeeAId = makeEmployee($pdo, $compId, 'A', '2020-01-01'); // in run -- has multiple destination-routed deductions
    $employeeBId = makeEmployee($pdo, $compId, 'B', '2020-01-01'); // in run -- receives A's employee-to-employee transfer (TRANSFER_IN, no remittance)
    $employeeDId = makeEmployee($pdo, $compId, 'D', '2099-01-01'); // NOT in this run (future employment_date, date-range eligibility excludes them) -- the fallback target

    $cycleSave = (new PayrollCycleModel($pdo))->save($compId, [
        'cycle_name' => 'REM_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    $cycleId = $cycleSave['id'];

    $runModel = new PayrollRunModel($pdo);
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'Remittance Test Run',
        'period_start_date' => '2027-07-21', 'period_end_date' => '2027-08-20', 'payment_date' => '2027-07-25',
    ], $userId, true);
    if (empty($createRes['status'])) { throw new RuntimeException('create failed: ' . ($createRes['message'] ?? '')); }
    $runId = $createRes['id'];

    // ---------- PaymentDestinationModel ----------
    echo "=== PaymentDestinationModel: create/get/list/delete ===\n";
    $destModel = new PaymentDestinationModel($pdo);
    $createDest = $destModel->create($compId, ['account_name' => 'ACME Loan Co.', 'account_no' => '1234567890', 'bank_id' => 1, 'bank_branch' => 'Silom', 'is_saved' => true], $userId);
    checkTrue('create() succeeds' . (empty($createDest['status']) ? " ({$createDest['message']})" : ''), $createDest['status']);
    $destinationId = $createDest['id'];
    $destGet = $destModel->get($compId, $destinationId);
    checkTrue('get() returns the row', $destGet !== null);
    check('account_no decrypts back to the original value', $destGet['account_no'] ?? null, '1234567890');
    check('is_saved persisted', (int)($destGet['is_saved'] ?? -1), 1);
    $savedList = $destModel->listSaved($compId);
    checkTrue('listSaved() includes the new saved destination', in_array((string)$destinationId, array_column($savedList, 'id')));

    $createOneOff = $destModel->create($compId, ['account_name' => 'One-off Payee', 'account_no' => '9999999999', 'bank_id' => 1, 'is_saved' => false], $userId);
    $oneOffId = $createOneOff['id'];
    $savedListAfterOneOff = $destModel->listSaved($compId);
    checkFalse('listSaved() does NOT include a one-off (is_saved=0) destination', in_array((string)$oneOffId, array_column($savedListAfterOneOff, 'id')));

    checkFalse('create() rejects missing bank_id', $destModel->create($compId, ['account_name' => 'X', 'account_no' => '1', 'bank_id' => null], $userId)['status']);
    checkFalse('create() rejects an inactive/invalid bank_id', $destModel->create($compId, ['account_name' => 'X', 'account_no' => '1', 'bank_id' => 999999], $userId)['status']);

    // ---------- Batch 3B item 3: level-2 for payee_type='company' -- WHICH of the company's own
    // bank_accounts. 2 real accounts so generateForRun() below can prove per-account grouping, not
    // just "some account was recorded". ----------
    echo "=== Fixture: 2 company bank accounts (Batch 3B item 3) ===\n";
    $bankAccountModel = new BankAccountModel($pdo);
    $bankAccA = $bankAccountModel->save($compId, ['bank_id' => 1, 'account_no' => '1112223334', 'account_name' => 'Payroll Ops Account', 'is_default' => true], $userId);
    checkTrue('bank account A created' . (empty($bankAccA['status']) ? " ({$bankAccA['message']})" : ''), $bankAccA['status']);
    $bankAccountAId = $bankAccA['id'];
    $bankAccB = $bankAccountModel->save($compId, ['bank_id' => 2, 'account_no' => '5556667778', 'account_name' => 'Welfare Fund Account', 'is_default' => false], $userId);
    checkTrue('bank account B created' . (empty($bankAccB['status']) ? " ({$bankAccB['message']})" : ''), $bankAccB['status']);
    $bankAccountBId = $bankAccB['id'];

    // ---------- EmployeeEarningDeductionModel: payee_type='other_person' wiring ----------
    echo "=== EmployeeEarningDeductionModel: other_person destination wiring ===\n";
    $eedModel = new EmployeeEarningDeductionModel();
    $eedOtherPerson = $eedModel->save($employeeAId, $compId, [
        'custom_item_name' => 'Loan to ACME', 'custom_item_type' => 'deduction',
        'total_installments' => 1, 'amount_mode' => 'even_split', 'total_amount' => 1000.00, 'effective_date' => '2027-07-01',
        'payee_type' => 'other_person', 'destination_id' => $destinationId,
    ], $userId);
    checkTrue('save() with payee_type=other_person + existing destination_id succeeds' . (empty($eedOtherPerson['status']) ? " ({$eedOtherPerson['message']})" : ''), $eedOtherPerson['status']);
    $eedOtherPersonGet = $eedModel->get($eedOtherPerson['id'], $compId);
    check('destination_id persisted', (int)($eedOtherPersonGet['destination_id'] ?? -1), $destinationId);
    check('destination_account_name resolved for display', $eedOtherPersonGet['destination_account_name'] ?? null, 'ACME Loan Co.');

    $eedNewDest = $eedModel->save($employeeAId, $compId, [
        'custom_item_name' => 'Court Order Garnishment', 'custom_item_type' => 'deduction',
        'total_installments' => 1, 'amount_mode' => 'even_split', 'total_amount' => 500.00, 'effective_date' => '2027-07-01',
        'payee_type' => 'other_person', 'account_name' => 'Court Registry', 'account_no' => '5551234567', 'bank_id' => 1, 'is_saved' => true,
    ], $userId);
    checkTrue('save() with payee_type=other_person + brand-new destination details succeeds' . (empty($eedNewDest['status']) ? " ({$eedNewDest['message']})" : ''), $eedNewDest['status']);
    $eedNewDestGet = $eedModel->get($eedNewDest['id'], $compId);
    checkTrue('a NEW destination_id was created (not reusing the ACME one)', ($eedNewDestGet['destination_id'] ?? null) !== $destinationId);

    // 2026-09-10, Batch 3B item 3: bank_account_id is mandatory now for a NEW/edited payee_type=
    // 'company' save -- both rejection paths first, then the real success case with a real account.
    $eedCompanyNoAccount = $eedModel->save($employeeAId, $compId, [
        'custom_item_name' => 'Uniform Deposit (missing account)', 'custom_item_type' => 'deduction',
        'total_installments' => 1, 'amount_mode' => 'even_split', 'total_amount' => 300.00, 'effective_date' => '2027-07-01',
        'payee_type' => 'company',
    ], $userId);
    checkFalse('save() with payee_type=company rejects a MISSING bank_account_id', $eedCompanyNoAccount['status']);
    $eedCompanyBadAccount = $eedModel->save($employeeAId, $compId, [
        'custom_item_name' => 'Uniform Deposit (bad account)', 'custom_item_type' => 'deduction',
        'total_installments' => 1, 'amount_mode' => 'even_split', 'total_amount' => 300.00, 'effective_date' => '2027-07-01',
        'payee_type' => 'company', 'bank_account_id' => 999999,
    ], $userId);
    checkFalse('save() with payee_type=company rejects an INVALID bank_account_id', $eedCompanyBadAccount['status']);

    $eedCompany = $eedModel->save($employeeAId, $compId, [
        'custom_item_name' => 'Uniform Deposit', 'custom_item_type' => 'deduction',
        'total_installments' => 1, 'amount_mode' => 'even_split', 'total_amount' => 300.00, 'effective_date' => '2027-07-01',
        'payee_type' => 'company', 'bank_account_id' => $bankAccountAId,
    ], $userId);
    checkTrue('save() with payee_type=company + valid bank_account_id succeeds' . (empty($eedCompany['status']) ? " ({$eedCompany['message']})" : ''), $eedCompany['status']);
    $eedCompanyGet = $eedModel->get($eedCompany['id'], $compId);
    check('bank_account_id persisted', (int)($eedCompanyGet['bank_account_id'] ?? -1), $bankAccountAId);
    check('bank_account_name resolved for display', $eedCompanyGet['bank_account_name'] ?? null, 'Payroll Ops Account');

    // A second company-routed deduction, to a DIFFERENT bank account -- proves generateForRun()
    // below actually groups PER account, not just "some account was recorded".
    $eedCompany2 = $eedModel->save($employeeAId, $compId, [
        'custom_item_name' => 'Welfare Fund Contribution', 'custom_item_type' => 'deduction',
        'total_installments' => 1, 'amount_mode' => 'even_split', 'total_amount' => 150.00, 'effective_date' => '2027-07-01',
        'payee_type' => 'company', 'bank_account_id' => $bankAccountBId,
    ], $userId);
    checkTrue('save() with payee_type=company to the SECOND bank account succeeds' . (empty($eedCompany2['status']) ? " ({$eedCompany2['message']})" : ''), $eedCompany2['status']);

    // Simulates real pre-existing legacy data (a payee_type='company' row saved before this
    // migration ever existed, bank_account_id genuinely NULL) -- direct SQL on purpose, since
    // save() now correctly refuses to create a NEW row this way; this is what generateForRun()'s
    // own "unspecified" bucket exists to handle gracefully instead of crashing/hiding it.
    $pdo->prepare("INSERT INTO `employee_earning_deductions`
            (employee_id, ped_type_id, custom_item_name, custom_item_type, total_installments, current_installment, amount_mode, total_amount, effective_date, status, payee_type, bank_account_id, created_by)
        VALUES (:employee_id, NULL, 'Legacy Company Deduction', 'deduction', 1, 0, 'even_split', 75.00, :effective_date, 'active', 'company', NULL, :created_by)")
        ->execute([':employee_id' => $employeeAId, ':effective_date' => '2027-07-01', ':created_by' => $userId]);
    $legacyAssignmentId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `employee_earning_deduction_installments` (assignment_id, installment_no, amount, status)
        VALUES (:assignment_id, 1, 75.00, 'pending')")->execute([':assignment_id' => $legacyAssignmentId]);
    checkTrue('fixture: legacy unspecified-company-account deduction row created directly (bypassing save() on purpose)', $legacyAssignmentId > 0);

    // Employee-to-employee: B is IN the run (should end up as TRANSFER_IN, no remittance at all).
    $eedToB = $eedModel->save($employeeAId, $compId, [
        'custom_item_name' => 'Split Bonus to B', 'custom_item_type' => 'deduction',
        'total_installments' => 1, 'amount_mode' => 'even_split', 'total_amount' => 400.00, 'effective_date' => '2027-07-01',
        'payee_type' => 'employee', 'payee_employee_id' => $employeeBId,
    ], $userId);
    checkTrue('save() with payee_type=employee (payee IS in this run) succeeds' . (empty($eedToB['status']) ? " ({$eedToB['message']})" : ''), $eedToB['status']);

    // Employee-to-employee: D is NOT in the run (future employment_date) -- the fallback case.
    $eedToD = $eedModel->save($employeeAId, $compId, [
        'custom_item_name' => 'Split Bonus to D', 'custom_item_type' => 'deduction',
        'total_installments' => 1, 'amount_mode' => 'even_split', 'total_amount' => 600.00, 'effective_date' => '2027-07-01',
        'payee_type' => 'employee', 'payee_employee_id' => $employeeDId,
    ], $userId);
    checkTrue('save() with payee_type=employee (payee NOT in this run) still succeeds -- validated only against comp membership, not run membership' . (empty($eedToD['status']) ? " ({$eedToD['message']})" : ''), $eedToD['status']);

    $recalcRes = $runModel->recalculate($runId, $compId, $userId, true);
    checkTrue('recalculate succeeds' . (empty($recalcRes['status']) ? " ({$recalcRes['message']})" : ''), $recalcRes['status']);
    check('run has exactly 2 employees (A and B -- D is excluded by date-range eligibility)', $recalcRes['employee_count'] ?? null, 2);

    $detailsAfterCalc = $runModel->getDetails($runId, $compId);
    $rowA = current(array_filter($detailsAfterCalc, fn($d) => (int)$d['employee_id'] === $employeeAId));
    checkTrue('A calc_status is calculated', $rowA['calc_status'] === 'calculated');
    checkTrue('A carries the transfer_payee_not_in_run advisory for the D transfer', strpos((string)($rowA['calc_errors'] ?? ''), 'transfer_payee_not_in_run') !== false);

    // ---------- PayrollRemittanceModel::generateForRun() ----------
    echo "=== PayrollRemittanceModel::generateForRun() ===\n";
    $remittanceModel = new PayrollRemittanceModel($pdo);
    $genRes = $remittanceModel->generateForRun($runId, $compId, $userId);
    checkTrue('generateForRun() succeeds' . (empty($genRes['status']) ? " ({$genRes['message']})" : ''), $genRes['status']);
    // 2026-09-10, Batch 3B item 3: 6 now -- company/bank-A, company/bank-B, company/unspecified
    // (was 1 blanket 'company' row before this batch), ACME, Court Registry, employee_fallback to D.
    check('6 remittances created (3 company groups now split per bank account/unspecified, ACME, Court Registry, employee_fallback to D) -- employee-to-B transfer excluded (paid via TRANSFER_IN)', $genRes['remittance_count'] ?? null, 6);
    check('exactly 1 fallback case reported (D)', count($genRes['fallback_cases'] ?? []), 1);
    check('fallback case amount matches the deduction (600.00)', $genRes['fallback_cases'][0]['total_amount'] ?? null, 600.0);
    check('fallback case identifies employee D', $genRes['fallback_cases'][0]['fallback_employee_id'] ?? null, $employeeDId);
    // 2026-09-10, Batch 3B item 3: the explicit "never hidden" requirement -- the legacy unspecified
    // row must surface here, not just silently exist in the DB.
    check('unspecified_company_count reports exactly the 1 legacy row', $genRes['unspecified_company_count'] ?? null, 1);

    $list = $remittanceModel->listForRun($runId, $compId);
    check('listForRun() returns 6 rows', count($list), 6);

    $companyRowA = current(array_filter($list, fn($r) => $r['destination_type'] === 'company' && (int)($r['bank_account_id'] ?? 0) === $bankAccountAId));
    checkTrue('company remittance (bank account A) exists', $companyRowA !== false);
    check('company remittance (bank A) amount = 300.00', (float)$companyRowA['total_amount'], 300.0);
    check('company remittance (bank A) is auto-marked success (no real external transfer needed)', $companyRowA['status'], 'success');
    check('company remittance (bank A) resolves bank_account_name for display', $companyRowA['bank_account_name'] ?? null, 'Payroll Ops Account');
    checkFalse('company remittance (bank A) is NOT flagged unspecified', (bool)$companyRowA['is_unspecified_company_account']);

    $companyRowB = current(array_filter($list, fn($r) => $r['destination_type'] === 'company' && (int)($r['bank_account_id'] ?? 0) === $bankAccountBId));
    checkTrue('company remittance (bank account B) exists -- proves per-account grouping, not one blanket row', $companyRowB !== false);
    check('company remittance (bank B) amount = 150.00', (float)$companyRowB['total_amount'], 150.0);
    check('company remittance (bank B) resolves bank_account_name for display', $companyRowB['bank_account_name'] ?? null, 'Welfare Fund Account');

    $companyRowUnspecified = current(array_filter($list, fn($r) => $r['destination_type'] === 'company' && $r['bank_account_id'] === null));
    checkTrue('company remittance (unspecified) exists -- the legacy row, never silently dropped', $companyRowUnspecified !== false);
    check('company remittance (unspecified) amount = 75.00', (float)$companyRowUnspecified['total_amount'], 75.0);
    checkTrue('company remittance (unspecified) IS flagged unspecified', (bool)$companyRowUnspecified['is_unspecified_company_account']);

    $acmeRow = current(array_filter($list, fn($r) => $r['destination_type'] === 'other_person' && (int)$r['destination_id'] === $destinationId));
    checkTrue('ACME (saved destination) remittance exists', $acmeRow !== false);
    check('ACME remittance amount = 1000.00', (float)$acmeRow['total_amount'], 1000.0);
    check('ACME remittance starts pending', $acmeRow['status'], 'pending');
    check('ACME remittance resolves the destination account name for display', $acmeRow['destination_account_name'] ?? null, 'ACME Loan Co.');

    $courtRow = current(array_filter($list, fn($r) => $r['destination_type'] === 'other_person' && (int)$r['destination_id'] !== $destinationId));
    checkTrue('Court Registry (one-off destination created via EED save) remittance exists', $courtRow !== false);
    check('Court Registry remittance amount = 500.00', (float)$courtRow['total_amount'], 500.0);

    $fallbackRow = current(array_filter($list, fn($r) => $r['destination_type'] === 'employee_fallback'));
    checkTrue('employee_fallback remittance exists', $fallbackRow !== false);
    check('fallback remittance amount = 600.00', (float)$fallbackRow['total_amount'], 600.0);
    check('fallback remittance identifies employee D by employee_no', $fallbackRow['fallback_employee_no'] ?? null, current(array_filter([$pdo->query("SELECT employee_no FROM employees WHERE id={$employeeDId}")->fetchColumn()])));

    $companyItems = $remittanceModel->itemsForRemittance((int)$companyRowA['id'], $compId);
    check('company remittance (bank A) breakdown has exactly 1 item', count($companyItems), 1);
    check('company remittance (bank A) item is from employee A', (int)$companyItems[0]['employee_id'], $employeeAId);
    check('company remittance (bank A) item_code is the custom item code', $companyItems[0]['item_code'], 'CUSTOM:Uniform Deposit');

    echo "--- Employee-to-employee transfer with payee IN the run creates NO remittance at all (TRANSFER_IN handles it) ---\n";
    $rowB = current(array_filter($detailsAfterCalc, fn($d) => (int)$d['employee_id'] === $employeeBId));
    $transferInLine = current(array_filter($rowB['earning_breakdown'], fn($l) => $l['code'] === 'TRANSFER_IN'));
    checkTrue('B received a real TRANSFER_IN earning line (unchanged, pre-existing mechanism)', $transferInLine !== false);
    check('TRANSFER_IN amount matches the A->B deduction exactly', (float)($transferInLine['amount'] ?? null), 400.0);
    checkFalse('no remittance exists for the A->B transfer (destination_type would be employee_fallback if it did)', in_array(400.0, array_column($list, 'total_amount')));

    echo "=== generateForRun(): idempotent regeneration (revert-then-reapprove scenario) ===\n";
    $genRes2 = $remittanceModel->generateForRun($runId, $compId, $userId);
    checkTrue('re-calling generateForRun() while everything is still pending succeeds (regenerates cleanly)', $genRes2['status']);
    $list2 = $remittanceModel->listForRun($runId, $compId);
    check('still exactly 6 remittances after regeneration (old pending ones replaced, not duplicated)', count($list2), 6);

    // ---------- Status transitions ----------
    // Re-fetch by destination_id/destination_type, NOT by the stale row ids captured before
    // regeneration above -- generateForRun() deletes+recreates pending rows on every call, so
    // $acmeRow['id']/$courtRow['id'] from before the regeneration no longer exist.
    echo "=== Status transitions: pending -> transferred -> success ===\n";
    $acmeRow2 = current(array_filter($remittanceModel->listForRun($runId, $compId), fn($r) => $r['destination_type'] === 'other_person' && (int)$r['destination_id'] === $destinationId));
    $acmeId = (int)$acmeRow2['id'];
    checkFalse('confirmSuccess() rejects a still-pending remittance', $remittanceModel->confirmSuccess($acmeId, $compId, $userId)['status']);
    $markTransferredRes = $remittanceModel->markTransferred($acmeId, $compId, 'public/uploads/remittance_evidence/1/fake.pdf', $userId);
    checkTrue('markTransferred() succeeds from pending' . (empty($markTransferredRes['status']) ? " ({$markTransferredRes['message']})" : ''), $markTransferredRes['status']);
    $afterTransferred = current(array_filter($remittanceModel->listForRun($runId, $compId), fn($r) => (int)$r['id'] === $acmeId));
    check('status is now transferred', $afterTransferred['status'], 'transferred');
    checkTrue('transferred_at recorded', !empty($afterTransferred['transferred_at']));
    check('transferred_by recorded', (int)$afterTransferred['transferred_by'], $userId);
    checkFalse('markTransferred() rejects a non-pending remittance (already transferred)', $remittanceModel->markTransferred($acmeId, $compId, 'x', $userId)['status']);

    $confirmRes = $remittanceModel->confirmSuccess($acmeId, $compId, $userId);
    checkTrue('confirmSuccess() succeeds from transferred', $confirmRes['status']);
    $afterSuccess = current(array_filter($remittanceModel->listForRun($runId, $compId), fn($r) => (int)$r['id'] === $acmeId));
    check('status is now success', $afterSuccess['status'], 'success');

    echo "=== Status transitions: transferred -> failed -> pending (retry) ===\n";
    $courtRow2 = current(array_filter($remittanceModel->listForRun($runId, $compId), fn($r) => $r['destination_type'] === 'other_person' && (int)$r['destination_id'] !== $destinationId));
    $courtId = (int)$courtRow2['id'];
    $remittanceModel->markTransferred($courtId, $compId, 'public/uploads/remittance_evidence/1/fake2.pdf', $userId);
    checkFalse('markFailed() rejects an empty reason', $remittanceModel->markFailed($courtId, $compId, '', $userId)['status']);
    $failRes = $remittanceModel->markFailed($courtId, $compId, 'Wrong account number', $userId);
    checkTrue('markFailed() succeeds with a reason' . (empty($failRes['status']) ? " ({$failRes['message']})" : ''), $failRes['status']);
    $afterFailed = current(array_filter($remittanceModel->listForRun($runId, $compId), fn($r) => (int)$r['id'] === $courtId));
    check('status is now failed', $afterFailed['status'], 'failed');
    check('note carries the failure reason', $afterFailed['note'], 'Wrong account number');

    $retryRes = $remittanceModel->retryToPending($courtId, $compId, $userId);
    checkTrue('retryToPending() succeeds from failed', $retryRes['status']);
    $afterRetry = current(array_filter($remittanceModel->listForRun($runId, $compId), fn($r) => (int)$r['id'] === $courtId));
    check('status is back to pending', $afterRetry['status'], 'pending');
    checkTrue('evidence_file_path cleared on retry', empty($afterRetry['evidence_file_path']));
    checkTrue('transferred_at cleared on retry', empty($afterRetry['transferred_at']));

    echo "=== generateForRun() refuses to regenerate once real progress exists ===\n";
    $genRes3 = $remittanceModel->generateForRun($runId, $compId, $userId);
    checkFalse('generateForRun() now refuses (the "success" ACME row is real progress that must not be silently discarded)', $genRes3['status']);

    echo "=== PaymentDestinationModel::delete() blocked while referenced ===\n";
    $deleteBlockedRes = $destModel->delete($compId, $destinationId, $userId);
    checkFalse('delete() refuses while an active EED row still references this destination', $deleteBlockedRes['status']);

    // ---------- ThirdPartyRemittanceSummaryReport (Phase 5's own Export Excel button) ----------
    // generateForRun() itself never checks payroll_runs.state (it's called from approve() right
    // after the state transition already happened) -- this test built its fixture by calling
    // generateForRun() directly (bypassing submit()/approve() entirely, on purpose, to isolate the
    // grouping logic from the approval-flow test surface, see the section above). The report
    // generator DOES gate on state (ALLOWED_STATES, same as every other payment report) so this
    // section flips the run to 'approved' directly via SQL -- a test-only shortcut, safe because
    // everything is inside this file's own rolled-back transaction.
    echo "=== ThirdPartyRemittanceSummaryReport ===\n";
    require_once __DIR__ . '/../app/services/reports/payment/ThirdPartyRemittanceSummaryReport.php';
    $pdo->prepare("UPDATE `payroll_runs` SET state = 'approved' WHERE id = :id")->execute([':id' => $runId]);
    $remittanceReport = new ThirdPartyRemittanceSummaryReport();
    $reportResult = $remittanceReport->generate(['comp_id' => $compId, 'run_id' => $runId], 'excel');
    checkTrue('generate() returns non-empty xlsx content', strlen($reportResult['content'] ?? '') > 0);
    checkTrue('content is a real ZIP/XLSX (starts with PK signature)', substr($reportResult['content'], 0, 2) === 'PK');
    check('file_name follows the RunN convention', $reportResult['file_name'] ?? null, "ThirdPartyRemittance_Run{$runId}.xlsx");

    // 2026-09-10, Batch 3B item 3: real cell-content verification (XLSX is a compressed zip of XML,
    // so a raw byte substring search wouldn't reliably find anything -- same reasoning already
    // documented for PDF text elsewhere in this project) that the report actually shows WHICH bank
    // account for a 'company' row, and an explicit "not specified" label for the unspecified one,
    // never a blank/generic "บริษัท" cell for either.
    $tmpXlsxRem = tempnam(sys_get_temp_dir(), 'thirdparty_remittance_test_') . '.xlsx';
    file_put_contents($tmpXlsxRem, $reportResult['content']);
    $spreadsheetRem = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmpXlsxRem);
    $sheetRem = $spreadsheetRem->getActiveSheet();
    $flatRem = [];
    foreach ($sheetRem->getRowIterator() as $r) {
        $rowVals = [];
        foreach ($r->getCellIterator() as $c) { $rowVals[] = $c->getValue(); }
        $flatRem[] = implode('|', array_map(fn($v) => (string)$v, $rowVals));
    }
    unlink($tmpXlsxRem);
    $flatTextRem = implode("\n", $flatRem);
    checkTrue('report shows bank account A\'s own name for that company row', strpos($flatTextRem, 'Payroll Ops Account') !== false);
    checkTrue('report shows bank account B\'s own name for that company row', strpos($flatTextRem, 'Welfare Fund Account') !== false);
    checkTrue('report shows an explicit "not specified" label for the unspecified company row, not a blank/generic cell', strpos($flatTextRem, 'ไม่ระบุบัญชี') !== false);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
