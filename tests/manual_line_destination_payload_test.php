<?php
/**
 * `PayrollRunModel::manualLinesForEmployee()` -- the destination fields the edit form reads back
 * (2026-09-17, tiny-M).
 *
 * The form could not describe the destination a manual line points at: the read payload carried
 * only `destination_account_name`, so the summary box under the picker stayed empty in edit mode,
 * and a line pointing at an ad-hoc (is_saved = 0) destination had nothing to identify it with at
 * all. This locks the 5 fields that were added, and -- the part that matters most -- that the
 * account NUMBER leaves this method masked, through the same 2 shared primitives
 * PaymentDestinationModel::listSaved() (the picker's own endpoint) already uses.
 *
 * Writes to the DB are wrapped in a transaction and rolled back, per CLAUDE.md -- nothing survives
 * this file, including on failure (the rollback is in a finally).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Run with: php tests/manual_line_destination_payload_test.php
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

echo "=== 1. the query and the mapper carry the destination fields ===\n";
$modelSrc = file_get_contents(__DIR__ . '/../app/models/PayrollRunModel.php');
$start = strpos($modelSrc, 'public function manualLinesForEmployee(');
checkTrue('manualLinesForEmployee() exists', $start !== false);
$body = substr($modelSrc, $start, strpos($modelSrc, 'public function employeeAdjustments(') - $start);

checkTrue('SELECT joins master_banks for the destination bank name',
    strpos($body, 'LEFT JOIN `master_banks` dbank ON dbank.id = pd.bank_id') !== false);
foreach ([
    'destination_bank_name_th', 'destination_bank_name_en', 'destination_bank_branch',
    'destination_account_no_masked', 'destination_is_saved',
] as $key) {
    checkTrue("mapper returns '{$key}'", strpos($body, "'{$key}' =>") !== false);
}
// The masked value is produced by the same pair the picker's own endpoint uses. A private
// substr()/str_repeat() here would be a second masking rule that can drift from that one.
checkTrue('the number is masked through EncryptionService::maskAccountNo(EncryptionService::decrypt(...))',
    strpos($body, 'EncryptionService::maskAccountNo(EncryptionService::decrypt(') !== false);
check('the mapper never returns the raw account number under any key',
    preg_match("/'destination_account_no'\s*=>/", $body), 0);
checkTrue('listSaved() -- the endpoint this mirrors -- still masks the same way',
    strpos(file_get_contents(__DIR__ . '/../app/models/PaymentDestinationModel.php'),
        'EncryptionService::maskAccountNo(') !== false);

echo "\n=== 2. against the real table (transaction, rolled back) ===\n";
$db = Database::getInstance()->pdo;
$compId = 1;
$runId = (int)$db->query("SELECT id FROM `payroll_runs` WHERE comp_id = {$compId} ORDER BY id DESC LIMIT 1")->fetchColumn();
$employeeId = (int)$db->query("SELECT id FROM `employees` WHERE comp_id = {$compId} AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
$bankId = (int)$db->query("SELECT id FROM `master_banks` WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();

if ($runId <= 0 || $employeeId <= 0 || $bankId <= 0) {
    echo "  SKIP  no run/employee/bank in this database to build a row against\n";
} else {
    $db->beginTransaction();
    try {
        $ACCOUNT_NO = '1234567890';
        $destModel = new PaymentDestinationModel($db);
        // is_saved = 0 on purpose: the case the picker's own endpoint can never return.
        $created = $destModel->create($compId, [
            'account_name' => 'TEST destination (rolled back)',
            'account_no' => $ACCOUNT_NO,
            'bank_id' => $bankId,
            'bank_branch' => 'TEST branch',
            'is_saved' => false,
        ], $employeeId);
        checkTrue('fixture destination created', !empty($created['status']));
        $destId = (int)$created['id'];

        $db->prepare("INSERT INTO `payroll_run_manual_lines`
                (run_id, employee_id, ped_type_id, custom_item_name, custom_item_type, amount, payee_type, destination_id, created_by)
            VALUES (:r, :e, NULL, 'TEST line (rolled back)', 'deduction', 100.00, 'other_person', :d, :u)")
            ->execute([':r' => $runId, ':e' => $employeeId, ':d' => $destId, ':u' => $employeeId]);

        $rows = (new PayrollRunModel($db))->manualLinesForEmployee($compId, $runId, $employeeId);
        $row = null;
        foreach ($rows as $r) {
            if ((int)($r['destination_id'] ?? 0) === $destId) { $row = $r; }
        }
        checkTrue('the line comes back from manualLinesForEmployee()', $row !== null);
        if ($row !== null) {
            check('destination_id', $row['destination_id'], $destId);
            check('destination_account_name', $row['destination_account_name'], 'TEST destination (rolled back)');
            check('destination_bank_branch', $row['destination_bank_branch'], 'TEST branch');
            check('destination_is_saved reports the ad-hoc row as 0 (the form needs to know)', $row['destination_is_saved'], 0);
            checkTrue('destination_bank_name_th is filled from master_banks',
                is_string($row['destination_bank_name_th']) && $row['destination_bank_name_th'] !== '');
            checkTrue('destination_bank_name_en is filled from master_banks',
                is_string($row['destination_bank_name_en']) && $row['destination_bank_name_en'] !== '');

            $masked = (string)$row['destination_account_no_masked'];
            checkTrue('destination_account_no_masked is present', $masked !== '');
            check('the full account number is NOT in the payload', $masked === $ACCOUNT_NO, false);
            check('the masked value matches the shared primitive exactly',
                $masked, EncryptionService::maskAccountNo($ACCOUNT_NO));
            checkTrue('only the last 4 digits survive',
                substr($masked, -4) === substr($ACCOUNT_NO, -4) && strpos($masked, substr($ACCOUNT_NO, 0, 5)) === false);
            check('no key/ciphertext leaks into the payload',
                array_key_exists('destination_key_version', $row) || array_key_exists('destination_account_no', $row), false);
        }

        // ---- round 3: the other 2 destinations, same treatment ----
        $bankAccountId = (int)$db->query("SELECT id FROM `bank_accounts` WHERE comp_id = {$compId} AND deleted_at IS NULL AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
        $payeeEmployeeId = (int)$db->query("SELECT id FROM `employees` WHERE comp_id = {$compId} AND deleted_at IS NULL AND id != {$employeeId} ORDER BY id LIMIT 1")->fetchColumn();
        if ($bankAccountId > 0) {
            $db->prepare("INSERT INTO `payroll_run_manual_lines`
                    (run_id, employee_id, ped_type_id, custom_item_name, custom_item_type, amount, payee_type, bank_account_id, created_by)
                VALUES (:r, :e, NULL, 'TEST company line (rolled back)', 'deduction', 70.00, 'company', :b, :u)")
                ->execute([':r' => $runId, ':e' => $employeeId, ':b' => $bankAccountId, ':u' => $employeeId]);
        }
        if ($payeeEmployeeId > 0) {
            $db->prepare("INSERT INTO `payroll_run_manual_lines`
                    (run_id, employee_id, ped_type_id, custom_item_name, custom_item_type, amount, payee_type, payee_employee_id, created_by)
                VALUES (:r, :e, NULL, 'TEST employee line (rolled back)', 'deduction', 80.00, 'employee', :p, :u)")
                ->execute([':r' => $runId, ':e' => $employeeId, ':p' => $payeeEmployeeId, ':u' => $employeeId]);
        }
        $rows3 = (new PayrollRunModel($db))->manualLinesForEmployee($compId, $runId, $employeeId);
        $byName = [];
        foreach ($rows3 as $r) { $byName[$r['item_name_th']] = $r; }

        if ($bankAccountId > 0) {
            $bankRow = $byName['TEST company line (rolled back)'] ?? null;
            checkTrue('company line comes back', $bankRow !== null);
            if ($bankRow !== null) {
                // The label must be the picker's own, not a second composition of the same parts.
                $fromPicker = (new PayrollCycleModel($db))->bankAccountOptions($compId, '', 1, 200);
                $pickerLabel = null;
                foreach ($fromPicker['items'] as $it) {
                    if ((int)$it['id'] === $bankAccountId) { $pickerLabel = $it; }
                }
                checkTrue('the bank account is offered by its own picker endpoint too', $pickerLabel !== null);
                check('bank_account_label_th is byte-identical to the option the picker would show',
                    $bankRow['bank_account_label_th'], $pickerLabel['text_th'] ?? null);
                check('bank_account_label_en likewise', $bankRow['bank_account_label_en'], $pickerLabel['text_en'] ?? null);
                check('bank_account_no_masked matches the picker own masked value',
                    $bankRow['bank_account_no_masked'], $pickerLabel['account_no_masked'] ?? null);
                check('bank_account_bank_name_th matches', $bankRow['bank_account_bank_name_th'], $pickerLabel['bank_name_th'] ?? null);
                check('bank_account_branch matches', $bankRow['bank_account_branch'], $pickerLabel['bank_branch'] ?? null);
                $rawBankNo = (string)$db->query("SELECT account_no FROM `bank_accounts` WHERE id = {$bankAccountId}")->fetchColumn();
                check('the stored (encrypted) account_no never appears in the payload',
                    in_array($rawBankNo, array_map('strval', array_values($bankRow)), true), false);
            }
        }
        if ($payeeEmployeeId > 0) {
            $empRow = $byName['TEST employee line (rolled back)'] ?? null;
            checkTrue('employee line comes back', $empRow !== null);
            if ($empRow !== null) {
                $opt = (new EmployeeModel($db))->optionRowsByIds($compId, [$payeeEmployeeId])[$payeeEmployeeId] ?? null;
                checkTrue('the payee option row is available', $opt !== null);
                check('payee_employee_label_th is the picker own label ("EM - name"), not employee_no alone',
                    $empRow['payee_employee_label_th'], $opt['text_th'] ?? null);
                check('payee_employee_label_en likewise', $empRow['payee_employee_label_en'], $opt['text_en'] ?? null);
                checkTrue('the label is not just the employee_no',
                    $empRow['payee_employee_label_th'] !== $empRow['payee_employee_no']);
                check('payee_employee_account_no_masked matches the picker own value',
                    $empRow['payee_employee_account_no_masked'], $opt['account_no_masked'] ?? null);
                check('payee_employee_has_bank_account matches', $empRow['payee_employee_has_bank_account'], (bool)($opt['has_bank_account'] ?? false));
                $rawEmpNo = (string)$db->query("SELECT bank_account_no FROM `employees` WHERE id = {$payeeEmployeeId}")->fetchColumn();
                check('the payee stored (encrypted) bank_account_no never appears in the payload',
                    $rawEmpNo !== '' && in_array($rawEmpNo, array_map('strval', array_values($empRow)), true), false);
            }
        }

        // ---- round 3b: the picker still FINDS a row by its code, even though no option prints one ----
        if ($payeeEmployeeId > 0) {
            $empModel = new EmployeeModel($db);
            $who = $db->query("SELECT employee_no, name_th, surname_th, name_en FROM `employees` WHERE id = {$payeeEmployeeId}")->fetch(PDO::FETCH_ASSOC);
            $foundBy = static function (string $term) use ($empModel, $compId, $payeeEmployeeId): bool {
                $res = $empModel->reportToOptions($compId, null, $term, 1, 50);
                foreach ($res['items'] as $it) {
                    if ((int)$it['id'] === $payeeEmployeeId) { return true; }
                }
                return false;
            };
            checkTrue('searching the employee CODE still returns the row (the strip is client-side only)',
                $foundBy((string)$who['employee_no']));
            if (trim((string)$who['name_th']) !== '') {
                checkTrue('searching the Thai name still returns the row', $foundBy(trim((string)$who['name_th'])));
            }
            if (trim((string)$who['name_en']) !== '') {
                checkTrue('searching the English name still returns the row', $foundBy(trim((string)$who['name_en'])));
            }
            // The endpoint is deliberately UNCHANGED: it still composes "CODE - Name"; only the
            // browser takes the code off for display. If this ever stops holding, the client strip
            // would be taking apart a label that no longer has that shape.
            $opt = $empModel->optionRowsByIds($compId, [$payeeEmployeeId])[$payeeEmployeeId] ?? null;
            checkTrue('the endpoint label still starts with the code + " - " (what the client strips)',
                $opt !== null && strpos((string)$opt['text_th'], $who['employee_no'] . ' - ') === 0);
        }

        // A line with no destination at all must not grow invented values.
        $db->prepare("INSERT INTO `payroll_run_manual_lines`
                (run_id, employee_id, ped_type_id, custom_item_name, custom_item_type, amount, created_by)
            VALUES (:r, :e, NULL, 'TEST plain line (rolled back)', 'earning', 50.00, :u)")
            ->execute([':r' => $runId, ':e' => $employeeId, ':u' => $employeeId]);
        $rows2 = (new PayrollRunModel($db))->manualLinesForEmployee($compId, $runId, $employeeId);
        $plain = null;
        foreach ($rows2 as $r) {
            if (($r['item_name_th'] ?? '') === 'TEST plain line (rolled back)') { $plain = $r; }
        }
        checkTrue('the destination-less line comes back too', $plain !== null);
        if ($plain !== null) {
            check('destination_id is null', $plain['destination_id'], null);
            check('destination_account_no_masked is null, not a masked empty string', $plain['destination_account_no_masked'], null);
            check('destination_is_saved is null', $plain['destination_is_saved'], null);
            check('bank_account_no_masked is null too', $plain['bank_account_no_masked'], null);
            check('bank_account_label_th is null (no account to label)', $plain['bank_account_label_th'], null);
            check('payee_employee_label_th is null (no payee to label)', $plain['payee_employee_label_th'], null);
            check('payee_employee_has_bank_account is null, not a misleading false', $plain['payee_employee_has_bank_account'], null);
        }
    } finally {
        $db->rollBack();
    }
    $left = (int)$db->query("SELECT COUNT(*) FROM `payroll_run_manual_lines` WHERE custom_item_name LIKE 'TEST %(rolled back)'")->fetchColumn();
    $leftDest = (int)$db->query("SELECT COUNT(*) FROM `payment_destinations` WHERE account_name = 'TEST destination (rolled back)'")->fetchColumn();
    check('rollback left no manual line behind', $left, 0);
    check('rollback left no destination behind', $leftDest, 0);
}

echo "\nPassed: {$passes}, Failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
