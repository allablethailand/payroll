<?php
/**
 * 2026-09-18, tiny-L4 -- "where does this money go?" is answered by ONE builder now.
 *
 * PayrollRunModel::enrichLinePayee() puts the descriptor payeeDestinationDescriptor() already built
 * for the recurring-destination card onto every persisted earning/deduction line as well, so the
 * read-only slip, the editable slip and that card stop each deriving their own answer from the 4 raw
 * payee columns. What this file pins:
 *   1. the descriptor itself, for all 4 payee kinds + "no payee at all" + "the record is gone"
 *   2. that no lookup happens per line (the rule that makes this affordable on a whole-run page)
 *   3. that both read endpoints really carry it, against the real dev DB
 *   4. (2026-09-18, tiny-L5) the SECOND thing the same sub-line says -- "งวด n/m" -- which rides
 *      the same one-lookup-per-request rule, and the one label that deliberately differs from the
 *      composer's default (a payee employee's account, with the owner's name taken back off)
 * Read-only throughout: nothing here writes, so there is no transaction to roll back.
 *
 * Run with: php tests/payee_descriptor_enrich_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
foreach (glob(__DIR__ . '/../app/services/*.php') as $f) { require_once $f; }
foreach (glob(__DIR__ . '/../app/models/*.php') as $f) { require_once $f; }

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

$db = Database::getInstance()->pdo;
$model = new PayrollRunModel();
$compId = 1;

echo "=== 1. the descriptor, one payee kind at a time (arrays only -- no fixture rows) ===\n";

// The lookup shape payeeLookupForLines() returns, stood up by hand so every branch below can be
// exercised without a real employee/destination/bank row existing for it (the recurring branch
// included -- it reads the same 4 columns off its own template row).
$LOOKUP = [
    'payees' => [499 => [
        'id' => 499, 'text_th' => 'CEO - กฤษดา สาธุกิจชัย', 'text_en' => 'CEO - Kridsada Satukijchai',
        'account_name' => 'Kridsada Satukijchai', 'bank_name_th' => 'ธนาคารกสิกรไทย', 'bank_name_en' => 'Kasikornbank',
        'bank_branch' => 'สีลม', 'account_no_masked' => 'XXXXXX4321', 'has_bank_account' => 1,
    ]],
    'destinations' => [268 => [
        'id' => 268, 'text_th' => 'กรมบังคับคดี (ธนาคารกรุงไทย)', 'text_en' => 'กรมบังคับคดี (ธนาคารกรุงไทย)',
        'account_name' => 'กรมบังคับคดี', 'bank_name_th' => 'ธนาคารกรุงไทย', 'bank_name_en' => 'Krungthai Bank',
        'bank_branch' => 'สวนหลวง', 'account_no_masked' => 'XXXXXX7890', 'is_saved' => 1,
    ]],
    'banks' => [4 => [
        'id' => 4, 'text_th' => 'ธนาคารไทยพาณิชย์ • XXXXXX5566 (Trandar)', 'text_en' => 'Siam Commercial Bank • XXXXXX5566 (Trandar)',
        'account_name' => 'Trandar', 'bank_name_th' => 'ธนาคารไทยพาณิชย์', 'bank_name_en' => 'Siam Commercial Bank',
        'bank_branch' => 'สำนักงานใหญ่', 'account_no_masked' => 'XXXXXX5566',
    ]],
];

$noPayee = $model->enrichLinePayee(['code' => 'STUDENT_LOAN', 'amount' => 4000.0, 'payee_type' => null], $LOOKUP);
check('a line routed nowhere gets payee = null, not an empty descriptor', $noPayee['payee'], null);
check('...and the rest of the line is handed back untouched', $noPayee['code'], 'STUDENT_LOAN');
check('...a line with no payee_type KEY at all is the same answer, not a warning',
    $model->enrichLinePayee(['code' => 'X'], $LOOKUP)['payee'], null);

$employee = $model->enrichLinePayee(
    ['code' => 'EARLY_LEAVE_DEDUCT', 'payee_type' => 'employee', 'payee_employee_id' => 499, 'payee_employee_no' => 'CEO'],
    $LOOKUP
)['payee'];
check('employee: the label is the picker own option, never re-composed', $employee['payee_employee_label_th'], 'CEO - กฤษดา สาธุกิจชัย');
check('employee: and its English twin', $employee['payee_employee_label_en'], 'CEO - Kridsada Satukijchai');
check('employee: the payout account arrives as ONE label (tiny-L4 addition), built by the shared composer',
    $employee['payee_employee_account_label_th'], 'ธนาคารกสิกรไทย • XXXXXX4321');
check('employee: the English label swaps only the bank name', $employee['payee_employee_account_label_en'], 'Kasikornbank • XXXXXX4321');
// 2026-09-18, tiny-L5: this label always follows "จ่ายให้ {the same person}" in one sentence, so the
// composer's trailing "(owner)" said the name twice. A COMPANY account keeps it: nothing in front of
// that one says whose account it is.
checkTrue('employee: the account label no longer repeats the person it is already following',
    strpos((string)$employee['payee_employee_account_label_th'], 'Kridsada Satukijchai') === false
        && strpos((string)$employee['payee_employee_account_label_en'], 'Kridsada Satukijchai') === false);
check('company: ...and the company account still names its owner, which nothing else on that line does',
    PayrollCycleModel::bankAccountOptionLabel('ธนาคารไทยพาณิชย์', 'XXXXXX5566', 'Trandar'),
    'ธนาคารไทยพาณิชย์ • XXXXXX5566 (Trandar)');
check('employee: resolved cleanly, so nothing is missing', $employee['missing'], false);
check('employee: employee_no rides along for the reader own last-resort fallback', $employee['payee_employee_no'], 'CEO');

$company = $model->enrichLinePayee(['code' => 'UNIFORM_DEDUCT', 'payee_type' => 'company', 'bank_account_id' => 4], $LOOKUP)['payee'];
check('company: names WHICH account, with the picker own label', $company['bank_account_label_th'], 'ธนาคารไทยพาณิชย์ • XXXXXX5566 (Trandar)');
check('company: nothing missing', $company['missing'], false);
$companyNoAccount = $model->enrichLinePayee(['code' => 'X', 'payee_type' => 'company'], $LOOKUP)['payee'];
check('company with no account chosen is NOT "missing" -- it is unspecified, a different problem', $companyNoAccount['missing'], false);
check('...and carries no account id to describe', $companyNoAccount['bank_account_id'], null);

$external = $model->enrichLinePayee(['code' => 'LOAN_REPAY', 'payee_type' => 'other_person', 'destination_id' => 268], $LOOKUP)['payee'];
check('other_person: the destination label is the endpoint own', $external['destination_label_th'], 'กรมบังคับคดี (ธนาคารกรุงไทย)');
check('other_person: the saved/ad-hoc flag survives', $external['destination_is_saved'], 1);
// 2026-09-18, tiny-L5: `master_banks` carries a real English name for every row, so the English
// label uses it; `payment_destinations.account_name` has no English twin and stays as stored.
check('other_person: the English label takes the bank name in English...',
    PaymentDestinationModel::optionItem([
        'id' => 268, 'account_name' => 'กรมบังคับคดี',
        'bank_name_th' => 'ธนาคารกรุงไทย', 'bank_name_en' => 'Krungthai Bank',
    ])['text_en'], 'กรมบังคับคดี (Krungthai Bank)');
check('other_person: ...the Thai one keeps the Thai bank name...',
    PaymentDestinationModel::optionItem([
        'id' => 268, 'account_name' => 'กรมบังคับคดี',
        'bank_name_th' => 'ธนาคารกรุงไทย', 'bank_name_en' => 'Krungthai Bank',
    ])['text_th'], 'กรมบังคับคดี (ธนาคารกรุงไทย)');
check('other_person: ...and a bank with no English name on file stays Thai rather than losing its name',
    PaymentDestinationModel::optionItem([
        'id' => 268, 'account_name' => 'กรมบังคับคดี',
        'bank_name_th' => 'ธนาคารกรุงไทย', 'bank_name_en' => null,
    ])['text_en'], 'กรมบังคับคดี (ธนาคารกรุงไทย)');
check('other_person: ...an EMPTY English name is absent too, not "a bank with no name"',
    PaymentDestinationModel::optionItem([
        'id' => 268, 'account_name' => 'กรมบังคับคดี',
        'bank_name_th' => 'ธนาคารกรุงไทย', 'bank_name_en' => '',
    ])['text_en'], 'กรมบังคับคดี (ธนาคารกรุงไทย)');
check('other_person: ...and a destination with no bank at all is just its own name',
    PaymentDestinationModel::optionItem([
        'id' => 268, 'account_name' => 'กรมบังคับคดี', 'bank_name_th' => null, 'bank_name_en' => null,
    ])['text_th'], 'กรมบังคับคดี');
check('other_person: nothing missing', $external['missing'], false);

$notDisbursed = $model->enrichLinePayee(['code' => 'X', 'payee_type' => 'not_disbursed'], $LOOKUP)['payee'];
check('not_disbursed: a real descriptor, with its type and no account at all', $notDisbursed['payee_type'], 'not_disbursed');
check('not_disbursed: nothing to be missing', $notDisbursed['missing'], false);

echo "\n=== 2. an id pointing at something that is gone ===\n";
foreach ([
    'employee' => ['payee_type' => 'employee', 'payee_employee_id' => 9999999],
    'other_person' => ['payee_type' => 'other_person', 'destination_id' => 9999999],
    'company' => ['payee_type' => 'company', 'bank_account_id' => 9999999],
] as $kind => $line) {
    $p = $model->enrichLinePayee($line + ['code' => 'X'], $LOOKUP)['payee'];
    checkTrue("{$kind}: a soft-deleted record is flagged missing, never thrown", $p['missing']);
    check("{$kind}: ...and the type it still IS survives, so the row stays readable", $p['payee_type'], $kind);
}
check('an empty lookup resolves nothing, and says so rather than pretending',
    $model->enrichLinePayee(['code' => 'X', 'payee_type' => 'employee', 'payee_employee_id' => 499])['payee']['missing'], true);

echo "\n=== 3. no lookup per line (the rule that makes a whole-run page affordable) ===\n";
$modelSrc = file_get_contents(__DIR__ . '/../app/models/PayrollRunModel.php');
$slice = static function (string $src, string $signature): string {
    $start = strpos($src, $signature);
    if ($start === false) { return ''; }
    $depth = 0;
    $len = strlen($src);
    for ($j = strpos($src, '{', $start); $j < $len; $j++) {
        if ($src[$j] === '{') { $depth++; }
        elseif ($src[$j] === '}') { $depth--; if ($depth === 0) { return substr($src, $start, $j - $start + 1); } }
    }
    return '';
};
$enrichSrc = $slice($modelSrc, 'public function enrichLinePayee(');
checkTrue('enrichLinePayee() exists', $enrichSrc !== '');
checkTrue('it issues no query of its own -- every row it needs was already fetched',
    strpos($enrichSrc, '->prepare(') === false && strpos($enrichSrc, '->query(') === false
        && strpos($enrichSrc, 'optionRowsByIds') === false);
checkTrue('it writes nothing',
    strpos($enrichSrc, 'INSERT') === false && strpos($enrichSrc, 'UPDATE') === false && strpos($enrichSrc, 'DELETE') === false);
$lookupSrc = $slice($modelSrc, 'public function payeeLookupForLines(');
checkTrue('payeeLookupForLines() exists', $lookupSrc !== '');
foreach ([
    'EmployeeModel' => 'optionRowsByIds',
    'PaymentDestinationModel' => 'optionRowsByIds',
    'PayrollCycleModel' => 'bankAccountOptionRowsByIds',
] as $class => $method) {
    check("it resolves {$class} exactly once, through that picker own builder",
        substr_count($lookupSrc, "(new {$class}(\$this->db))->{$method}("), 1);
}
check('no ids at all short-circuits before any model is even constructed',
    $model->payeeLookupForLines($compId, [[['code' => 'A'], ['code' => 'B']]]),
    ['payees' => [], 'destinations' => [], 'banks' => [], 'installments' => []]);
foreach (['getDetails(', 'syncDeductionLinesForEmployee(', 'manualLinesForEmployee('] as $endpoint) {
    check("{$endpoint} builds its lookup once for the whole response",
        substr_count($slice($modelSrc, 'public function ' . $endpoint), '$this->payeeLookupForLines('), 1);
}

echo "\n=== 3b. งวด n/m: the other half of the same sub-line (tiny-L5) ===\n";
// Stood up by hand exactly like $LOOKUP above, so every branch is reachable without a real
// assignment existing for it. installmentRowsByIds() itself is exercised against the real DB in
// section 5 below, through the endpoints that call it.
$INST_LOOKUP = ['installments' => [
    2437 => ['installment_no' => 2, 'total_installments' => 12],
    2439 => ['installment_no' => 1, 'total_installments' => 1],
]];
check('a line in an instalment plan says which one, out of how many',
    $model->enrichLineInstallment(['code' => 'LOAN_REPAY', 'installment_id' => 2437], $INST_LOOKUP)['installment'],
    ['n' => 2, 'total' => 12]);
check('a one-off assignment is not a plan -- "งวด 1/1" is noise, not information',
    $model->enrichLineInstallment(['code' => 'UNIFORM_DEDUCT', 'installment_id' => 2439], $INST_LOOKUP)['installment'], null);
check('a line with no instalment id at all (manual line, base salary, statutory) says nothing',
    $model->enrichLineInstallment(['code' => 'BASE'], $INST_LOOKUP)['installment'], null);
check('...and the key is still PRESENT, so no reader has to special-case a missing one',
    array_key_exists('installment', $model->enrichLineInstallment(['code' => 'BASE'], $INST_LOOKUP)), true);
check('an id whose row is gone (deleted assignment) resolves to null, never to a half-filled tag',
    $model->enrichLineInstallment(['code' => 'X', 'installment_id' => 9999999], $INST_LOOKUP)['installment'], null);
check('an empty lookup is the same answer, not a warning',
    $model->enrichLineInstallment(['code' => 'X', 'installment_id' => 2437])['installment'], null);
check('...and the rest of the line is handed back untouched',
    $model->enrichLineInstallment(['code' => 'X', 'amount' => 12.5], $INST_LOOKUP)['amount'], 12.5);

$instSrc = $slice($modelSrc, 'public function enrichLineInstallment(');
checkTrue('enrichLineInstallment() exists', $instSrc !== '');
checkTrue('it issues no query of its own either -- same rule as its twin',
    strpos($instSrc, '->prepare(') === false && strpos($instSrc, '->query(') === false);
checkTrue('it writes nothing',
    strpos($instSrc, 'INSERT') === false && strpos($instSrc, 'UPDATE') === false && strpos($instSrc, 'DELETE') === false);
check('the lookup resolves instalments exactly once too, in the same batch',
    substr_count($lookupSrc, '$this->installmentRowsByIds('), 1);
$instLookupSrc = $slice($modelSrc, 'private function installmentRowsByIds(');
checkTrue('installmentRowsByIds() reads a SET of ids in one statement, never one per line',
    substr_count($instLookupSrc, '->prepare(') === 1 && strpos($instLookupSrc, 'IN ({$in})') !== false);
checkTrue('...scoped to the company, through the assignment own employee',
    strpos($instLookupSrc, 'e.comp_id = ?') !== false);
checkTrue('...and it will not describe an assignment that has been deleted',
    strpos($instLookupSrc, 'eed.deleted_at IS NULL') !== false);

echo "\n=== 4. the descriptor still leaks no plaintext account number ===\n";
$rawKeys = [];
foreach ([$employee, $company, $external] as $p) {
    foreach (array_keys($p) as $key) {
        if (preg_match('/account_no$/', (string)$key) === 1) { $rawKeys[] = $key; }
    }
}
check('no key ending in account_no reached the descriptor (masked ones only)', $rawKeys, []);
checkTrue('every account number in it really is a masked one',
    str_contains((string)$employee['payee_employee_account_no_masked'], 'X'));

echo "\n=== 5. both read endpoints really carry it (real dev DB, read-only) ===\n";
$target = $db->query("SELECT d.run_id, d.employee_id, r.comp_id
    FROM `payroll_run_details` d
    JOIN `payroll_runs` r ON r.id = d.run_id AND r.deleted_at IS NULL
    WHERE d.deduction_breakdown LIKE '%payee_type%' ORDER BY d.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$target) {
    echo "  SKIP  this dev DB has no calculated line carrying a payee to read a real shape from\n";
} else {
    $runId = (int)$target['run_id'];
    $employeeId = (int)$target['employee_id'];
    $realCompId = (int)$target['comp_id'];

    $details = $model->getDetails($runId, $realCompId);
    $seenLines = 0;
    $missingKey = 0;
    $statutoryCarriedPayee = 0;
    $anyResolved = false;
    foreach ($details as $d) {
        foreach (['earning_breakdown', 'deduction_breakdown'] as $field) {
            foreach ($d[$field] as $line) {
                $seenLines++;
                if (!array_key_exists('payee', $line) || !array_key_exists('installment', $line)) { $missingKey++; }
                if (($line['payee'] ?? null) !== null) { $anyResolved = true; }
            }
        }
        foreach ($d['statutory_breakdown'] as $line) {
            if (array_key_exists('payee', $line)) { $statutoryCarriedPayee++; }
        }
    }
    checkTrue('(a) the slip endpoint returned lines to check at all', $seenLines > 0);
    check('(a) every earning/deduction line carries `payee` AND `installment` (null is an answer, a missing key is not)', $missingKey, 0);
    check('(a) statutory lines do NOT -- a statutory item has no payee concept', $statutoryCarriedPayee, 0);
    checkTrue('(a) at least one of them resolved to a real descriptor, not all nulls', $anyResolved);

    $lines = $model->syncDeductionLinesForEmployee($realCompId, $runId, $employeeId);
    checkTrue('(b) the editable-table endpoint returned rows', count($lines) > 0);
    $requiredKeys = ['source', 'recurring_id', 'assignment_id', 'installment_id', 'manual_line_id',
        'payee_type', 'payee_employee_id', 'payee_employee_no', 'destination_id', 'bank_account_id', 'payee',
        'installment'];
    $absent = [];
    foreach ($lines as $row) {
        foreach ($requiredKeys as $key) {
            if (!array_key_exists($key, $row)) { $absent[$key] = true; }
        }
    }
    check('(b) every row carries all 12 read-only provenance/payee/instalment keys', array_keys($absent), []);
    // Read against the real DB: whatever the plan lengths in this dev database are, a row that says
    // it is an instalment must agree with the assignment it came from.
    $instAgree = true;
    foreach ($lines as $r) {
        if ($r['installment'] === null) { continue; }
        $real = $db->prepare("SELECT i.installment_no, eed.total_installments
            FROM `employee_earning_deduction_installments` i
            JOIN `employee_earning_deductions` eed ON eed.id = i.assignment_id
            WHERE i.id = :id");
        $real->execute([':id' => $r['installment_id']]);
        $row = $real->fetch(PDO::FETCH_ASSOC);
        $instAgree = $instAgree && $row !== false
            && (int)$row['installment_no'] === $r['installment']['n']
            && (int)$row['total_installments'] === $r['installment']['total']
            && (int)$row['total_installments'] > 1;
    }
    checkTrue('(b) every row that claims an instalment matches its own assignment in the DB', $instAgree);
    check('(b) the internal sync-matching key is still stripped, as it always was',
        array_key_exists('sync_item_code', $lines[0]), false);
    $typesAgree = true;
    foreach ($lines as $r) {
        $typesAgree = $typesAgree && ($r['payee_type'] === null
            ? $r['payee'] === null
            : (is_array($r['payee']) && $r['payee']['payee_type'] === $r['payee_type']));
    }
    checkTrue('(b) a row whose payee_type is set resolves to a descriptor of the same type', $typesAgree);

    $manualMissing = 0;
    foreach ($model->manualLinesForEmployee($realCompId, $runId, $employeeId) as $row) {
        if (!array_key_exists('payee', $row) || !array_key_exists('installment', $row)) { $manualMissing++; }
        // A hand-added line has no instalment plan behind it -- there is no assignment to be one of.
        if ($row['installment'] !== null) { $manualMissing++; }
    }
    check('(c) the hand-added lines endpoint carries both keys, and never claims an instalment', $manualMissing, 0);
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
