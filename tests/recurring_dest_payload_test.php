<?php
/**
 * `PayrollRunModel::recurringDeductionDestinationsForEmployee()` -- the fields the Recurring
 * Deduction Destination card and its Override editor read back (2026-09-17, tiny-L2).
 *
 * The card composed all 3 destination labels itself, and all 3 disagreed with the picker the editor
 * offers for the same value: "name (EM001)" vs the picker's "EM001 - name", the company account's
 * bare `account_name` vs "bank . masked (account name)", and "name - bankTh" vs "name (bank)". The
 * same row therefore read one way in the list, another in the dropdown. This locks that every label
 * now comes from that picker's OWN options builder -- byte-identical, asserted against what the
 * endpoint itself would return -- and that the account fields the editor needs (bank/branch/masked
 * number/has_bank_account/is_saved) ride along MASKED, through the same shared primitives.
 *
 * Writes to the DB are wrapped in a transaction and rolled back, per CLAUDE.md -- nothing survives
 * this file, including on failure (the rollback is in a finally).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Run with: php tests/recurring_dest_payload_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
foreach (glob(__DIR__ . '/../app/services/*.php') as $serviceFile) {
    require_once $serviceFile;
}
foreach (glob(__DIR__ . '/../app/models/*.php') as $modelFile) {
    require_once $modelFile;
}

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

echo "=== 1. the 3 private label compositions are gone, the 3 shared builders are in ===\n";
$modelSrc = file_get_contents(__DIR__ . '/../app/models/PayrollRunModel.php');
$start = strpos($modelSrc, 'public function recurringDeductionDestinationsForEmployee(');
checkTrue('recurringDeductionDestinationsForEmployee() exists', $start !== false);
// 2026-09-18, tiny-L4: bounded by the descriptor builder that follows this method, not by the
// next PUBLIC one -- the helpers between them (payeeLookupForLines()/enrichLinePayee()) call
// the same builder for the slip's own lines, and counting THEIR calls as this method's is how
// the "one method, not two blocks" assertion below started reading 3.
$body = substr($modelSrc, $start, strpos($modelSrc, 'private function payeeDestinationDescriptor(') - $start);

foreach ([
    'the payee employee label' => "(new EmployeeModel(\$this->db))->optionRowsByIds(",
    'the external destination' => "(new PaymentDestinationModel(\$this->db))->optionRowsByIds(",
    'the company bank account' => "(new PayrollCycleModel(\$this->db))->bankAccountOptionRowsByIds(",
] as $what => $call) {
    checkTrue("{$what} comes from the picker's own builder", strpos($body, $call) !== false);
}
// The exact 3 compositions this round removed. Any of them coming back means the card is spelling a
// row its own way again, which is the whole bug.
checkTrue("no re-composed employee label (name + ' (' + employee_no + ')')",
    strpos($body, "' (' . \$e['employee_no']") === false);
checkTrue("no re-composed destination label (account_name . ' - ' . bank_name_th)",
    strpos($body, "' - ' . \$d['bank_name_th']") === false);
checkTrue('no bare-account_name company label lookup left',
    strpos($body, "SELECT id, account_name FROM `bank_accounts`") === false);
checkTrue('template and override are built by ONE method, not two blocks',
    substr_count($body, '$this->payeeDestinationDescriptor(') === 2);
$descStart = strpos($modelSrc, 'private function payeeDestinationDescriptor(');
checkTrue('payeeDestinationDescriptor() exists', $descStart !== false);
$descBody = substr($modelSrc, $descStart, 3000);
check('the descriptor never returns a raw account number under any key',
    preg_match("/'(destination|bank)_account_no'\s*=>/", $descBody), 0);
checkTrue('it never decrypts anything itself (the builders it reads already masked)',
    strpos($descBody, 'EncryptionService::') === false);

echo "\n=== 2. the shared builders agree with their own endpoints, byte for byte ===\n";
$db = Database::getInstance()->pdo;
$compId = 1;
$destModelCheck = new PaymentDestinationModel($db);
foreach ($destModelCheck->listSaved($compId, '', 5) as $r) {
    $fromEndpoint = PaymentDestinationModel::optionItem($r);
    $byId = $destModelCheck->optionRowsByIds($compId, [(int)$r['id']])[(int)$r['id']] ?? null;
    checkTrue("destination #{$r['id']}: fetched by id, same label as the endpoint's own option",
        $byId !== null && $byId['text_th'] === $fromEndpoint['text_th'] && $byId['text_en'] === $fromEndpoint['text_en']);
    checkTrue("destination #{$r['id']}: same account fields too",
        $byId !== null && $byId['account_no_masked'] === $fromEndpoint['account_no_masked']
            && $byId['bank_branch'] === $fromEndpoint['bank_branch']);
    check("destination #{$r['id']}: the endpoint's own item has no is_saved key (its shape is unchanged)",
        array_key_exists('is_saved', $fromEndpoint), false);
}
$cycleModel = new PayrollCycleModel($db);
foreach ($cycleModel->bankAccountOptions($compId, '', 1, 5)['items'] as $it) {
    $byId = $cycleModel->bankAccountOptionRowsByIds($compId, [(int)$it['id']])[(int)$it['id']] ?? null;
    check("bank account #{$it['id']}: fetched by id, identical to the picker's own option", $byId, $it);
}

echo "\n=== 3. against the real tables (transaction, rolled back) ===\n";
// Any live run does: this read path never looks at `state` (the card's own gating is elsewhere).
// A DRAFT run is skipped on purpose -- this dev DB holds real work, and a draft is the one someone
// may be editing by hand right now; this file has no business appearing in it, rolled back or not.
$runRow = $db->query("SELECT id, period_start_date, period_end_date FROM `payroll_runs`
    WHERE comp_id = {$compId} AND deleted_at IS NULL AND state <> 'draft' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$employeeId = (int)$db->query("SELECT id FROM `employees` WHERE comp_id = {$compId} AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
$payeeEmployeeId = (int)$db->query("SELECT id FROM `employees` WHERE comp_id = {$compId} AND deleted_at IS NULL AND id != {$employeeId} ORDER BY id LIMIT 1")->fetchColumn();
$bankAccountId = (int)$db->query("SELECT id FROM `bank_accounts` WHERE comp_id = {$compId} AND deleted_at IS NULL AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
$bankId = (int)$db->query("SELECT id FROM `master_banks` WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();

if (!$runRow || $employeeId <= 0 || $payeeEmployeeId <= 0 || $bankAccountId <= 0 || $bankId <= 0) {
    echo "  SKIP  no draft run / employee / bank account / bank in this database to build against\n";
} else {
    $runId = (int)$runRow['id'];
    $effective = $runRow['period_start_date'];
    $db->beginTransaction();
    try {
        $ACCOUNT_NO = '9876543210';
        // The catalog this dev DB has holds no fixed_amount deduction type, which is the only kind a
        // recurring deduction may point at -- so the fixture brings its own (rolled back with the rest).
        $db->prepare("INSERT INTO `payroll_earning_deduction_types`
                (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method, status, created_by)
            VALUES (:c, :code, :th, :en, 'deduction', 'fixed_amount', 'active', :u)")
            ->execute([':c' => $compId, ':code' => 'TESTRD1', ':th' => 'TEST หักประจำ (rolled back)', ':en' => 'TEST recurring (rolled back)', ':u' => $employeeId]);
        $pedTypeA = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO `payroll_earning_deduction_types`
                (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method, status, created_by)
            VALUES (:c, :code, :th, :en, 'deduction', 'fixed_amount', 'active', :u)")
            ->execute([':c' => $compId, ':code' => 'TESTRD2', ':th' => 'TEST หักประจำ 2 (rolled back)', ':en' => 'TEST recurring 2 (rolled back)', ':u' => $employeeId]);
        $pedTypeB = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO `payroll_earning_deduction_types`
                (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method, status, created_by)
            VALUES (:c, :code, :th, :en, 'deduction', 'fixed_amount', 'active', :u)")
            ->execute([':c' => $compId, ':code' => 'TESTRD3', ':th' => 'TEST หักประจำ 3 (rolled back)', ':en' => 'TEST recurring 3 (rolled back)', ':u' => $employeeId]);
        $pedTypeC = (int)$db->lastInsertId();

        $recModel = new EmployeeRecurringDeductionModel($db);
        // is_saved = 0 on purpose: the destination the picker's own endpoint can never offer back.
        $savedEmployee = $recModel->save($employeeId, $compId, [
            'ped_type_id' => $pedTypeA, 'amount' => 100, 'effective_date' => $effective,
            'payee_type' => 'employee', 'payee_employee_id' => $payeeEmployeeId,
        ], $employeeId);
        checkTrue('fixture: employee-payee recurring deduction created', !empty($savedEmployee['status']));
        $savedCompany = $recModel->save($employeeId, $compId, [
            'ped_type_id' => $pedTypeB, 'amount' => 200, 'effective_date' => $effective,
            'payee_type' => 'company', 'bank_account_id' => $bankAccountId,
        ], $employeeId);
        checkTrue('fixture: company-account recurring deduction created', !empty($savedCompany['status']));
        $savedExternal = $recModel->save($employeeId, $compId, [
            'ped_type_id' => $pedTypeC, 'amount' => 300, 'effective_date' => $effective,
            'payee_type' => 'other_person', 'account_name' => 'TEST ปลายทาง (rolled back)',
            'account_no' => $ACCOUNT_NO, 'bank_id' => $bankId, 'bank_branch' => 'TEST branch', 'is_saved' => false,
        ], $employeeId);
        checkTrue('fixture: external-destination recurring deduction created', !empty($savedExternal['status']));

        $rows = (new PayrollRunModel($db))->recurringDeductionDestinationsForEmployee($runId, $compId, $employeeId);
        $byCode = [];
        foreach ($rows as $r) { $byCode[$r['item_code']] = $r; }
        checkTrue('all 3 fixture rows come back', isset($byCode['TESTRD1'], $byCode['TESTRD2'], $byCode['TESTRD3']));

        // ---- the payee employee ----
        $empRow = $byCode['TESTRD1'] ?? null;
        if ($empRow !== null) {
            $opt = (new EmployeeModel($db))->optionRowsByIds($compId, [$payeeEmployeeId])[$payeeEmployeeId] ?? null;
            checkTrue('the payee option row is available', $opt !== null);
            check('payee_employee_label_th is the picker own label, not a re-composed one',
                $empRow['template']['payee_employee_label_th'], $opt['text_th'] ?? null);
            check('payee_employee_label_en likewise', $empRow['template']['payee_employee_label_en'], $opt['text_en'] ?? null);
            check('payee_employee_account_no_masked matches the picker own value',
                $empRow['template']['payee_employee_account_no_masked'], $opt['account_no_masked'] ?? null);
            check('payee_employee_has_bank_account matches',
                $empRow['template']['payee_employee_has_bank_account'], (bool)($opt['has_bank_account'] ?? false));
            $rawEmpNo = (string)$db->query("SELECT bank_account_no FROM `employees` WHERE id = {$payeeEmployeeId}")->fetchColumn();
            check('the payee stored (encrypted) bank_account_no never appears in the payload',
                $rawEmpNo !== '' && in_array($rawEmpNo, array_map('strval', array_values($empRow['template'])), true), false);
        }

        // ---- the company bank account ----
        $bankRow = $byCode['TESTRD2'] ?? null;
        if ($bankRow !== null) {
            $pickerItem = null;
            foreach ($cycleModel->bankAccountOptions($compId, '', 1, 200)['items'] as $it) {
                if ((int)$it['id'] === $bankAccountId) { $pickerItem = $it; }
            }
            checkTrue('the bank account is offered by its own picker endpoint too', $pickerItem !== null);
            check('bank_account_label_th is byte-identical to the option the picker would show',
                $bankRow['template']['bank_account_label_th'], $pickerItem['text_th'] ?? null);
            check('bank_account_label_en likewise', $bankRow['template']['bank_account_label_en'], $pickerItem['text_en'] ?? null);
            checkTrue('the label is no longer the bare account_name it used to be',
                $bankRow['template']['bank_account_label_th'] !== $bankRow['template']['bank_account_name']);
            check('bank_account_no_masked matches the picker own masked value',
                $bankRow['template']['bank_account_no_masked'], $pickerItem['account_no_masked'] ?? null);
            check('bank_account_branch matches', $bankRow['template']['bank_account_branch'], $pickerItem['bank_branch'] ?? null);
            $rawBankNo = (string)$db->query("SELECT account_no FROM `bank_accounts` WHERE id = {$bankAccountId}")->fetchColumn();
            check('the stored (encrypted) account_no never appears in the payload',
                in_array($rawBankNo, array_map('strval', array_values($bankRow['template'])), true), false);
        }

        // ---- the external destination (is_saved = 0) ----
        $destRow = $byCode['TESTRD3'] ?? null;
        if ($destRow !== null) {
            $destId = (int)$destRow['template']['destination_id'];
            checkTrue('the destination id came back', $destId > 0);
            $expectedLabel = PaymentDestinationModel::optionLabel(
                'TEST ปลายทาง (rolled back)',
                $db->query("SELECT bank_name_th FROM `master_banks` WHERE id = {$bankId}")->fetchColumn() ?: null,
                $db->query("SELECT bank_name_en FROM `master_banks` WHERE id = {$bankId}")->fetchColumn() ?: null
            );
            check('destination_label_th is the endpoint own composition', $destRow['template']['destination_label_th'], $expectedLabel);
            check('destination_label_en is the SAME string (the endpoint serves one label for both)',
                $destRow['template']['destination_label_en'], $expectedLabel);
            check('destination_is_saved reports the ad-hoc row as 0 (the editor needs to know)',
                $destRow['template']['destination_is_saved'], 0);
            check('destination_bank_branch', $destRow['template']['destination_bank_branch'], 'TEST branch');
            $masked = (string)$destRow['template']['destination_account_no_masked'];
            checkTrue('destination_account_no_masked is present', $masked !== '');
            check('the full account number is NOT in the payload', $masked === $ACCOUNT_NO, false);
            check('the masked value matches the shared primitive exactly', $masked, EncryptionService::maskAccountNo($ACCOUNT_NO));
            checkTrue('only the last 4 digits survive',
                substr($masked, -4) === substr($ACCOUNT_NO, -4) && strpos($masked, substr($ACCOUNT_NO, 0, 5)) === false);
            $rawDestNo = (string)$db->query("SELECT account_no FROM `payment_destinations` WHERE id = {$destId}")->fetchColumn();
            check('no ciphertext leaks into the payload',
                in_array($rawDestNo, array_map('strval', array_values($destRow['template'])), true), false);
            // The saved-only picker genuinely cannot offer this row back -- which is why it is pinned.
            $offered = false;
            foreach ((new PaymentDestinationModel($db))->listSaved($compId, '', 200) as $s) {
                if ((int)$s['id'] === $destId) { $offered = true; }
            }
            check('the picker endpoint does NOT return this ad-hoc destination', $offered, false);
        }

        // ---- template vs override: one shape, built once ----
        $recurringId = (int)$byCode['TESTRD1']['recurring_id'];
        check('a row with no override says so', $byCode['TESTRD1']['override'], null);
        $db->prepare("INSERT INTO `payroll_run_recurring_deduction_overrides`
                (run_id, recurring_id, payee_type, bank_account_id, note, created_by)
            VALUES (:r, :rec, 'company', :b, 'TEST note', :u)")
            ->execute([':r' => $runId, ':rec' => $recurringId, ':b' => $bankAccountId, ':u' => $employeeId]);
        $rows2 = (new PayrollRunModel($db))->recurringDeductionDestinationsForEmployee($runId, $compId, $employeeId);
        $overridden = null;
        foreach ($rows2 as $r) { if ($r['item_code'] === 'TESTRD1') { $overridden = $r; } }
        checkTrue('the overridden row comes back', $overridden !== null);
        if ($overridden !== null) {
            checkTrue('the override is there', is_array($overridden['override']));
            check('override and template carry exactly the same fields (plus the note)',
                array_values(array_diff(array_keys($overridden['override']), array_keys($overridden['template']))), ['note']);
            check('the override names the company account it points at, with the picker own label',
                $overridden['override']['bank_account_label_th'],
                $cycleModel->bankAccountOptionRowsByIds($compId, [$bankAccountId])[$bankAccountId]['text_th'] ?? null);
            check('the template underneath is untouched by the override',
                $overridden['template']['payee_type'], 'employee');
            check('override payee_type', $overridden['override']['payee_type'], 'company');
            check('the override carries no payee employee', $overridden['override']['payee_employee_id'], null);
            check('...and invents no label for one', $overridden['override']['payee_employee_label_th'], null);
        }

        // ---- a template with no payee at all must not grow invented values ----
        $db->prepare("INSERT INTO `payroll_earning_deduction_types`
                (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method, status, created_by)
            VALUES (:c, 'TESTRD4', 'TEST หักประจำ 4 (rolled back)', 'TEST recurring 4 (rolled back)', 'deduction', 'fixed_amount', 'active', :u)")
            ->execute([':c' => $compId, ':u' => $employeeId]);
        $pedTypeD = (int)$db->lastInsertId();
        $recModel->save($employeeId, $compId, [
            'ped_type_id' => $pedTypeD, 'amount' => 400, 'effective_date' => $effective,
        ], $employeeId);
        $rows3 = (new PayrollRunModel($db))->recurringDeductionDestinationsForEmployee($runId, $compId, $employeeId);
        $plain = null;
        foreach ($rows3 as $r) { if ($r['item_code'] === 'TESTRD4') { $plain = $r; } }
        checkTrue('the payee-less row comes back too', $plain !== null);
        if ($plain !== null) {
            check('payee_type is null', $plain['template']['payee_type'], null);
            check('payee_employee_label_th is null (no payee to label)', $plain['template']['payee_employee_label_th'], null);
            check('payee_employee_has_bank_account is null, not a misleading false', $plain['template']['payee_employee_has_bank_account'], null);
            check('destination_account_no_masked is null, not a masked empty string', $plain['template']['destination_account_no_masked'], null);
            check('destination_is_saved is null', $plain['template']['destination_is_saved'], null);
            check('bank_account_label_th is null (no account to label)', $plain['template']['bank_account_label_th'], null);
        }
    } finally {
        $db->rollBack();
    }
    $leftTypes = (int)$db->query("SELECT COUNT(*) FROM `payroll_earning_deduction_types` WHERE item_code LIKE 'TESTRD%'")->fetchColumn();
    $leftRec = (int)$db->query("SELECT COUNT(*) FROM `employee_recurring_deductions` erd
        JOIN `payroll_earning_deduction_types` pt ON pt.id = erd.ped_type_id WHERE pt.item_code LIKE 'TESTRD%'")->fetchColumn();
    $leftDest = (int)$db->query("SELECT COUNT(*) FROM `payment_destinations` WHERE account_name = 'TEST ปลายทาง (rolled back)'")->fetchColumn();
    check('rollback left no deduction type behind', $leftTypes, 0);
    check('rollback left no recurring deduction behind', $leftRec, 0);
    check('rollback left no destination behind', $leftDest, 0);
}

echo "\nPassed: {$passes}, Failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
