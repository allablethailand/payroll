<?php
/**
 * `PayrollRunModel::earningDeductionDestinationsForEmployee()` -- the READ-ONLY rows the Recurring
 * Deduction Destination tab now shows underneath its editable cards (2026-09-18, tiny-L3).
 *
 * Reported for real: an employee whose Breakdown listed 4 deductions saw an EMPTY tab, because every
 * one of those deductions lives in `employee_earning_deductions` (per-installment assignments, whose
 * destination belongs to the assignment and is edited on Employee Detail) while the tab only ever
 * read `employee_recurring_deductions`.
 *
 * What this locks:
 *  1. the row-selection clause is still character-for-character recalculate()'s own -- the one thing
 *     that could silently drift, since the two are deliberately NOT shared (generalising would mean
 *     editing recalculate(), which that round was explicitly not allowed to touch);
 *  2. the 4 selection cases behave: active+pending+destination is in, paused / no-pending-installment
 *     / no-destination-at-all are out;
 *  3. every destination label is byte-identical to the one that value's own picker builds (the same
 *     rule tiny-L2 established for the editable cards -- nothing is composed in this read path);
 *  4. the recurring payload the tab already had is unchanged -- fields added only, never altered.
 *
 * Writes to the DB are wrapped in a transaction and rolled back, per CLAUDE.md -- nothing survives
 * this file, including on failure (the rollback is in a finally).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Run with: php tests/eed_dest_payload_test.php
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
function squash(string $sql): string { return trim(preg_replace('/\s+/', ' ', $sql)); }

echo "=== 1. the selection clause is recalculate()'s own, character for character ===\n";
$modelSrc = file_get_contents(__DIR__ . '/../app/models/PayrollRunModel.php');

$refl = new ReflectionClass('PayrollRunModel');
$constants = $refl->getConstants();
checkTrue('EED_ASSIGNMENT_WHERE_SQL exists', array_key_exists('EED_ASSIGNMENT_WHERE_SQL', $constants));
$whereConst = squash((string)($constants['EED_ASSIGNMENT_WHERE_SQL'] ?? ''));

// recalculate()'s own PED-assignment SELECT -- the non-incentive branch (the LAST of the two copies
// in that method; the first is the incentive branch's own, gated by include_standing_items).
$recalcStart = strpos($modelSrc, 'public function recalculate(');
checkTrue('recalculate() exists', $recalcStart !== false);
$fromPositions = [];
$offset = $recalcStart;
while (($pos = strpos($modelSrc, 'FROM `employee_earning_deductions` eed', $offset)) !== false) {
    $fromPositions[] = $pos;
    $offset = $pos + 1;
    if (count($fromPositions) >= 2) { break; }
}
check('recalculate() still holds exactly the 2 PED-assignment SELECTs this test knows about',
    count($fromPositions), 2);
$recalcWhere = '';
if (count($fromPositions) === 2) {
    $whereStart = strpos($modelSrc, 'WHERE eed.employee_id = :employee_id', $fromPositions[1]);
    $whereEnd = strpos($modelSrc, 'ORDER BY eed.id ASC', $whereStart);
    $recalcWhere = squash(substr($modelSrc, $whereStart + strlen('WHERE '), $whereEnd - $whereStart - strlen('WHERE ')));
}
// $pedRestrictSql is interpolated at runtime; both of its id lists are hard-coded empty in
// recalculate(), so $buildTypeCondition() always returns its "no rows = unrestricted" branch -- the
// exact OR-group the constant spells out. Assert that premise too, so re-populating either list
// (which would make recalculate() select FEWER rows than this read path reports) fails here.
checkTrue('recalculate() still hard-codes an EMPTY earning restrict list',
    strpos($modelSrc, '$earningRestrictIds = [];') !== false);
checkTrue('recalculate() still hard-codes an EMPTY deduction restrict list',
    strpos($modelSrc, '$deductionRestrictIds = [];') !== false);
$restrictTail = " AND (pt.is_sync_only = 1 OR COALESCE(pt.item_type, eed.custom_item_type) = 'earning'"
    . " OR COALESCE(pt.item_type, eed.custom_item_type) = 'deduction')";
check('the unrestricted $pedRestrictSql is still built the way the constant assumes',
    strpos($modelSrc, "\$pedRestrictSql = ' AND (pt.is_sync_only = 1 OR '") !== false
        && strpos($modelSrc, "return \"COALESCE(pt.item_type, eed.custom_item_type) = '{\$itemType}'\";") !== false,
    true);
check('the constant IS recalculate()\'s clause with that OR-group resolved',
    $whereConst, squash(str_replace('{$pedRestrictSql}', $restrictTail, $recalcWhere)));

$newStart = strpos($modelSrc, 'public function earningDeductionDestinationsForEmployee(');
checkTrue('earningDeductionDestinationsForEmployee() exists', $newStart !== false);
$newBody = substr($modelSrc, $newStart, strpos($modelSrc, 'public function recurringDeductionDestinationsForEmployee(') - $newStart);
checkTrue('it reads the clause from the constant, not a second copy of the text',
    strpos($newBody, 'self::EED_ASSIGNMENT_WHERE_SQL') !== false);
checkTrue('it joins only PENDING installments, like recalculate()',
    strpos($newBody, "i.status = 'pending'") !== false);
checkTrue('it keeps only the earliest pending installment per assignment',
    strpos($newBody, 'only the first (earliest) pending installment per assignment') !== false);
checkTrue('it composes no label of its own -- the 3 picker builders do it',
    strpos($newBody, '$this->payeeDestinationDescriptor(') !== false
        && strpos($newBody, '(new PaymentDestinationModel($this->db))->optionRowsByIds(') !== false
        && strpos($newBody, '(new PayrollCycleModel($this->db))->bankAccountOptionRowsByIds(') !== false
        && strpos($newBody, '(new EmployeeModel($this->db))->optionRowsByIds(') !== false);
checkTrue('it writes nothing', strpos($newBody, 'INSERT') === false && strpos($newBody, 'UPDATE') === false
    && strpos($newBody, 'DELETE') === false && strpos($newBody, 'beginTransaction') === false);

echo "\n=== 2. against the real tables (transaction, rolled back) ===\n";
$db = Database::getInstance()->pdo;
$compId = 1;
// Same rule as tests/recurring_dest_payload_test.php: never build inside a DRAFT run, which is the
// one someone may be editing by hand in this shared dev DB right now.
$runRow = $db->query("SELECT id, period_start_date, period_end_date FROM `payroll_runs`
    WHERE comp_id = {$compId} AND deleted_at IS NULL AND state <> 'draft' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$employeeId = (int)$db->query("SELECT id FROM `employees` WHERE comp_id = {$compId} AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
$payeeEmployeeId = (int)$db->query("SELECT id FROM `employees` WHERE comp_id = {$compId} AND deleted_at IS NULL AND id != {$employeeId} ORDER BY id LIMIT 1")->fetchColumn();
$bankAccountId = (int)$db->query("SELECT id FROM `bank_accounts` WHERE comp_id = {$compId} AND deleted_at IS NULL AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
$bankId = (int)$db->query("SELECT id FROM `master_banks` WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();

if (!$runRow || $employeeId <= 0 || $payeeEmployeeId <= 0 || $bankAccountId <= 0 || $bankId <= 0) {
    echo "  SKIP  no run / employee / bank account / bank in this database to build against\n";
} else {
    $runId = (int)$runRow['id'];
    $effective = $runRow['period_start_date'];
    $runModel = new PayrollRunModel($db);
    $eedModel = new EmployeeEarningDeductionModel($db);
    $db->beginTransaction();
    try {
        $ACCOUNT_NO = '9876543210';
        $typeIds = [];
        foreach (['TESTEED1', 'TESTEED2', 'TESTEED3', 'TESTEED4', 'TESTEED5'] as $code) {
            $db->prepare("INSERT INTO `payroll_earning_deduction_types`
                    (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method, status, created_by)
                VALUES (:c, :code, :th, :en, 'deduction', 'manual_entry', 'active', :u)")
                ->execute([':c' => $compId, ':code' => $code, ':th' => "TEST {$code} (rolled back)",
                    ':en' => "TEST {$code} en (rolled back)", ':u' => $employeeId]);
            $typeIds[$code] = (int)$db->lastInsertId();
        }

        // (a) INCLUDED -- active, a pending installment, routed to a company bank account, 2 of 3.
        $savedCompany = $eedModel->save($employeeId, $compId, [
            'ped_type_id' => $typeIds['TESTEED1'], 'total_installments' => 3, 'amount_mode' => 'even_split',
            'total_amount' => 300, 'effective_date' => $effective,
            'payee_type' => 'company', 'bank_account_id' => $bankAccountId,
        ], $employeeId);
        checkTrue('fixture: company-account assignment created', !empty($savedCompany['status']));
        $companyAssignmentId = (int)($savedCompany['id'] ?? 0);
        // Pay the first installment off, so the row this run reports is genuinely installment 2 of 3.
        $db->prepare("UPDATE `employee_earning_deduction_installments` SET status = 'processed'
            WHERE assignment_id = :a AND installment_no = 1")->execute([':a' => $companyAssignmentId]);

        // (b) INCLUDED -- routed to another employee.
        $savedEmployee = $eedModel->save($employeeId, $compId, [
            'ped_type_id' => $typeIds['TESTEED2'], 'total_installments' => 1, 'amount_mode' => 'even_split',
            'total_amount' => 150, 'effective_date' => $effective,
            'payee_type' => 'employee', 'payee_employee_id' => $payeeEmployeeId,
        ], $employeeId);
        checkTrue('fixture: employee-payee assignment created', !empty($savedEmployee['status']));

        // (c) INCLUDED -- routed to an ad-hoc external destination (is_saved = 0, the one the saved-only
        // picker can never offer back).
        $savedExternal = $eedModel->save($employeeId, $compId, [
            'ped_type_id' => $typeIds['TESTEED3'], 'total_installments' => 1, 'amount_mode' => 'even_split',
            'total_amount' => 250, 'effective_date' => $effective,
            'payee_type' => 'other_person', 'account_name' => 'TEST ปลายทาง EED (rolled back)',
            'account_no' => $ACCOUNT_NO, 'bank_id' => $bankId, 'bank_branch' => 'TEST branch', 'is_saved' => false,
        ], $employeeId);
        checkTrue('fixture: external-destination assignment created', !empty($savedExternal['status']));

        // (d) EXCLUDED -- a real destination, but the assignment is not active.
        $savedPaused = $eedModel->save($employeeId, $compId, [
            'ped_type_id' => $typeIds['TESTEED4'], 'total_installments' => 1, 'amount_mode' => 'even_split',
            'total_amount' => 400, 'effective_date' => $effective,
            'payee_type' => 'company', 'bank_account_id' => $bankAccountId,
        ], $employeeId);
        $db->prepare("UPDATE `employee_earning_deductions` SET status = 'paused' WHERE id = :id")
            ->execute([':id' => (int)($savedPaused['id'] ?? 0)]);

        // (e) EXCLUDED -- active with a destination, but every installment is already paid.
        $savedDone = $eedModel->save($employeeId, $compId, [
            'ped_type_id' => $typeIds['TESTEED5'], 'total_installments' => 1, 'amount_mode' => 'even_split',
            'total_amount' => 500, 'effective_date' => $effective,
            'payee_type' => 'company', 'bank_account_id' => $bankAccountId,
        ], $employeeId);
        $db->prepare("UPDATE `employee_earning_deduction_installments` SET status = 'processed' WHERE assignment_id = :a")
            ->execute([':a' => (int)($savedDone['id'] ?? 0)]);

        // (f) EXCLUDED -- active with a pending installment, but nothing was ever routed anywhere.
        $savedNoDest = $eedModel->save($employeeId, $compId, [
            'ped_type_id' => $typeIds['TESTEED1'], 'total_installments' => 1, 'amount_mode' => 'even_split',
            'total_amount' => 600, 'effective_date' => $effective,
        ], $employeeId);
        checkTrue('fixture: payee-less assignment created', !empty($savedNoDest['status']));
        $noDestAssignmentId = (int)($savedNoDest['id'] ?? 0);
        check('fixture: it really has no payee_type at all',
            $db->query("SELECT payee_type FROM `employee_earning_deductions` WHERE id = {$noDestAssignmentId}")->fetchColumn(),
            null);

        $rows = $runModel->earningDeductionDestinationsForEmployee($runId, $compId, $employeeId);
        $mine = [];
        foreach ($rows as $r) {
            if (strpos((string)$r['item_code'], 'TESTEED') === 0) { $mine[$r['assignment_id']] = $r; }
        }
        $byCode = [];
        foreach ($mine as $r) { $byCode[$r['item_code']] = $r; }

        echo "\n  -- which rows come back --\n";
        check('the 3 routed, active, still-owing assignments come back', count($mine), 3);
        checkTrue('the company-account one is in', isset($byCode['TESTEED1']));
        checkTrue('the employee-payee one is in', isset($byCode['TESTEED2']));
        checkTrue('the external-destination one is in', isset($byCode['TESTEED3']));
        check('a PAUSED assignment is left out (recalculate would not pay it either)', isset($byCode['TESTEED4']), false);
        check('an assignment with no pending installment left is left out', isset($byCode['TESTEED5']), false);
        check('an assignment with no destination at all is left out', isset($mine[$noDestAssignmentId]), false);

        echo "\n  -- what each row says --\n";
        $companyRow = $byCode['TESTEED1'] ?? null;
        if ($companyRow !== null) {
            check('readonly is stated outright', $companyRow['readonly'], true);
            check('it points at the employee whose setting owns it',
                $companyRow['employee_detail_url'], BASE_URL . '/employees/' . $employeeId);
            check('the catalog item\'s calculation_method rides along', $companyRow['calculation_method'], 'manual_entry');
            check('a multi-installment assignment says so', $companyRow['is_installment_plan'], true);
            check('...and reports the EARLIEST STILL-PENDING installment, not the first ever',
                $companyRow['installment_no'], 2);
            check('total_installments', $companyRow['total_installments'], 3);
            check('the amount is this installment\'s, not the whole plan\'s', $companyRow['amount'], 100.0);
            $pickerItem = null;
            foreach ((new PayrollCycleModel($db))->bankAccountOptions($compId, '', 1, 200)['items'] as $it) {
                if ((int)$it['id'] === $bankAccountId) { $pickerItem = $it; }
            }
            check('bank_account_label_th is byte-identical to the picker\'s own option',
                $companyRow['destination']['bank_account_label_th'], $pickerItem['text_th'] ?? null);
            check('bank_account_label_en likewise',
                $companyRow['destination']['bank_account_label_en'], $pickerItem['text_en'] ?? null);
            check('the masked account number matches the picker\'s own',
                $companyRow['destination']['bank_account_no_masked'], $pickerItem['account_no_masked'] ?? null);
            $rawBankNo = (string)$db->query("SELECT account_no FROM `bank_accounts` WHERE id = {$bankAccountId}")->fetchColumn();
            check('no stored (encrypted) account number reaches the payload',
                in_array($rawBankNo, array_map('strval', array_values($companyRow['destination'])), true), false);
        }
        $employeeRow = $byCode['TESTEED2'] ?? null;
        if ($employeeRow !== null) {
            $opt = (new EmployeeModel($db))->optionRowsByIds($compId, [$payeeEmployeeId])[$payeeEmployeeId] ?? null;
            check('payee_employee_label_th is the picker\'s own label',
                $employeeRow['destination']['payee_employee_label_th'], $opt['text_th'] ?? null);
            check('payee_employee_label_en likewise',
                $employeeRow['destination']['payee_employee_label_en'], $opt['text_en'] ?? null);
            check('has_bank_account rides along for the summary line',
                $employeeRow['destination']['payee_employee_has_bank_account'], (bool)($opt['has_bank_account'] ?? false));
            check('a single-installment assignment is not called a plan', $employeeRow['is_installment_plan'], false);
        }
        $externalRow = $byCode['TESTEED3'] ?? null;
        if ($externalRow !== null) {
            $destId = (int)$externalRow['destination']['destination_id'];
            // 2026-09-18, tiny-L5: one label per language now -- the bank's own English name where
            // `master_banks` has one. The destination's own name has no English twin and never changes.
            $bankTh = $db->query("SELECT bank_name_th FROM `master_banks` WHERE id = {$bankId}")->fetchColumn() ?: null;
            $bankEn = $db->query("SELECT bank_name_en FROM `master_banks` WHERE id = {$bankId}")->fetchColumn() ?: null;
            $expectedLabel = PaymentDestinationModel::optionLabel('TEST ปลายทาง EED (rolled back)', $bankTh ?? $bankEn);
            $expectedLabelEn = PaymentDestinationModel::optionLabel('TEST ปลายทาง EED (rolled back)', $bankEn ?? $bankTh);
            check('destination_label_th is the endpoint\'s own composition',
                $externalRow['destination']['destination_label_th'], $expectedLabel);
            check('destination_label_en takes the bank name in English, from that same endpoint',
                $externalRow['destination']['destination_label_en'], $expectedLabelEn);
            checkTrue('...which really is a different string here (this bank has an English name on file)',
                $bankEn === null || $expectedLabelEn !== $expectedLabel);
            check('the ad-hoc destination reports is_saved = 0',
                $externalRow['destination']['destination_is_saved'], 0);
            check('the account number is masked by the shared primitive, never sent whole',
                $externalRow['destination']['destination_account_no_masked'], EncryptionService::maskAccountNo($ACCOUNT_NO));
            $rawDestNo = (string)$db->query("SELECT account_no FROM `payment_destinations` WHERE id = {$destId}")->fetchColumn();
            check('no ciphertext leaks into the payload',
                in_array($rawDestNo, array_map('strval', array_values($externalRow['destination'])), true), false);
        }
        checkTrue('every row carries the SAME descriptor shape the editable cards read',
            $companyRow !== null && $employeeRow !== null
                && array_keys($companyRow['destination']) === array_keys($employeeRow['destination']));

        echo "\n  -- the recurring payload underneath is untouched --\n";
        $recRows = $runModel->recurringDeductionDestinationsForEmployee($runId, $compId, $employeeId);
        checkTrue('it still returns a plain list (not wrapped in a new envelope)',
            is_array($recRows) && ($recRows === [] || array_keys($recRows) === range(0, count($recRows) - 1)));
        if (!empty($recRows)) {
            check('a recurring row still carries exactly its own 6 fields',
                array_keys($recRows[0]),
                ['recurring_id', 'item_code', 'item_name_th', 'item_name_en', 'template', 'override']);
        } else {
            echo "  NOTE  this employee has no recurring deduction in this period -- shape checked by tests/recurring_dest_payload_test.php\n";
        }
        check('a run that does not exist yields no read-only rows either',
            $runModel->earningDeductionDestinationsForEmployee(0, $compId, $employeeId), []);
    } finally {
        $db->rollBack();
    }
    $leftTypes = (int)$db->query("SELECT COUNT(*) FROM `payroll_earning_deduction_types` WHERE item_code LIKE 'TESTEED%'")->fetchColumn();
    $leftEed = (int)$db->query("SELECT COUNT(*) FROM `employee_earning_deductions` eed
        JOIN `payroll_earning_deduction_types` pt ON pt.id = eed.ped_type_id WHERE pt.item_code LIKE 'TESTEED%'")->fetchColumn();
    $leftDest = (int)$db->query("SELECT COUNT(*) FROM `payment_destinations` WHERE account_name = 'TEST ปลายทาง EED (rolled back)'")->fetchColumn();
    check('rollback left no deduction type behind', $leftTypes, 0);
    check('rollback left no assignment behind', $leftEed, 0);
    check('rollback left no destination behind', $leftDest, 0);
}

echo "\nPassed: {$passes}, Failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
