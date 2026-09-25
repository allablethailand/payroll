<?php
/**
 * 2026-09-19, tiny-F: proves PayeeDescriptorTrait is ONE descriptor, not two spellings of one --
 * what EmployeeEarningDeductionModel::list() / EmployeeRecurringDeductionModel::list() now attach
 * per row is byte-identical to what PayrollRunModel produces for the SAME row, the 3 option-row
 * lookups happen once per list() rather than once per row, and a row routed nowhere still carries
 * the full key set. See docs/decisions/2026-09-19-tiny-f-payee-descriptor-trait.md.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back; EM009 (id 159) is READ ONLY, everything written is seeded
 * here. Run with: php tests/payee_descriptor_trait_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/models/EmployeeEarningDeductionModel.php';
require_once __DIR__ . '/../app/models/EmployeeRecurringDeductionModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();
$failures = 0; $passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    if ($actual === $expected) { $passes++; echo "  PASS  {$label}\n"; return; }
    $failures++; echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
}
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }

/** Statements this connection has run so far -- the same SHOW costs the same on both sides, so
 *  two deltas measured this way are comparable even though each includes the SHOW itself. */
function questionCount(PDO $pdo): int {
    return (int)$pdo->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_ASSOC)['Value'];
}

try {
    $compId = 1;
    $runModel = new PayrollRunModel($pdo);

    echo "\n-- (a) EED list() of EM009 says exactly what PayrollRunModel says --\n";
    $eedModel = new EmployeeEarningDeductionModel();
    $rows = $eedModel->list(159, $compId);
    check('EM009 has its 4 assignment rows', count($rows), 4);
    $byType = [];
    foreach ($rows as $r) { $byType[$r['payee_type'] ?? '(null)'] = $r; }
    ksort($byType);
    check('all 4 kinds of routing are represented', implode(',', array_keys($byType)),
        '(null),company,employee,other_person');

    $lookup = $runModel->payeeLookupForLines($compId, ['eed' => $rows]);
    foreach ($rows as $r) {
        $type = $r['payee_type'] ?? '(null)';
        checkTrue("payee_type={$type}: the trait's descriptor rides on the row", is_array($r['payee'] ?? null));
        if (empty($r['payee_type'])) { continue; }
        $fromRun = $runModel->enrichLinePayee($r, $lookup)['payee'];
        check("payee_type={$type}: byte-identical to PayrollRunModel's own descriptor",
            json_encode($r['payee'], JSON_UNESCAPED_UNICODE), json_encode($fromRun, JSON_UNESCAPED_UNICODE));
    }
    $company = $byType['company'];
    checkTrue('a company row carries the composed account label it is read by',
        is_string($company['payee']['bank_account_label_th']) && $company['payee']['bank_account_label_th'] !== '');
    $person = $byType['employee'];
    checkTrue('an employee payee carries its own composed account label or an honest null',
        array_key_exists('payee_employee_account_label_th', $person['payee']));
    check('a resolvable row is not reported missing', $byType['other_person']['payee']['missing'], false);
    checkTrue('every column list() returned before is still there',
        isset($company['item_name_th'], $company['total_installments'], $company['effective_date']));

    echo "\n-- (c) a row routed nowhere still carries the whole key set --\n";
    $nullRow = $byType['(null)'];
    check('same keys as a routed row, none dropped',
        array_keys($nullRow['payee']), array_keys($company['payee']));
    $nonNull = [];
    foreach ($nullRow['payee'] as $k => $v) { if ($v !== null && $v !== false) { $nonNull[] = $k; } }
    check('and every one of them is null (missing:false is the only statement it makes)', $nonNull, []);

    echo "\n-- seed: one employee of our own, for the write-side checks --\n";
    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_end_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, has_spouse)
        VALUES (:c, :no, 'mr', 'male', 'ทดสอบ', 'ปลายทาง', 'Test', 'Payee', '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address', 'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', NULL, 'permanent', 'full_time', 'office', 'manual',
         'monthly', 20000, '2020-01-01', 'average', 'active', 1, 0, 0, 0)");
    $insEmp->execute([':c' => $compId, ':no' => 'TEST_PAYEE_' . uniqid(), ':email' => uniqid() . '@test.local']);
    $empId = (int)$pdo->lastInsertId();
    $pedTypeId = (int)$pdo->query("SELECT id FROM `payroll_earning_deduction_types`
        WHERE comp_id = {$compId} AND item_type = 'deduction' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    $payeeEmpId = (int)$pdo->query("SELECT id FROM `employees` WHERE comp_id = {$compId} AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    $bankAccountId = (int)$pdo->query("SELECT id FROM `bank_accounts` WHERE comp_id = {$compId} AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();

    echo "\n-- (b) recurring deductions read the same descriptor from the same trait --\n";
    $insErd = $pdo->prepare("INSERT INTO `employee_recurring_deductions`
        (employee_id, ped_type_id, amount, effective_date, payee_type, payee_employee_id, bank_account_id, status)
        VALUES (:e, :pt, 500, '2020-01-01', :type, :payee, :bank, 'active')");
    $insErd->execute([':e' => $empId, ':pt' => $pedTypeId, ':type' => 'employee', ':payee' => $payeeEmpId, ':bank' => null]);
    $insErd->execute([':e' => $empId, ':pt' => $pedTypeId, ':type' => 'company', ':payee' => null, ':bank' => $bankAccountId]);
    $insErd->execute([':e' => $empId, ':pt' => $pedTypeId, ':type' => null, ':payee' => null, ':bank' => null]);

    $erdModel = new EmployeeRecurringDeductionModel($pdo);
    $erdRows = $erdModel->list($empId, $compId);
    check('all 3 seeded rows come back', count($erdRows), 3);
    $erdLookup = $runModel->payeeLookupForLines($compId, ['erd' => $erdRows]);
    foreach ($erdRows as $r) {
        $type = $r['payee_type'] ?? '(null)';
        checkTrue("recurring payee_type={$type}: descriptor present", is_array($r['payee'] ?? null));
        if (empty($r['payee_type'])) {
            check("recurring payee_type={$type}: full key set anyway",
                array_keys($r['payee']), array_keys($erdRows[0]['payee']));
            continue;
        }
        check("recurring payee_type={$type}: byte-identical to PayrollRunModel's own descriptor",
            json_encode($r['payee'], JSON_UNESCAPED_UNICODE),
            json_encode($runModel->enrichLinePayee($r, $erdLookup)['payee'], JSON_UNESCAPED_UNICODE));
    }
    checkTrue('is_suspended_now, the field this list() already computed, survived',
        array_key_exists('is_suspended_now', $erdRows[0]));

    echo "\n-- (d) the lookups are per list(), not per row --\n";
    $insEed = $pdo->prepare("INSERT INTO `employee_earning_deductions`
        (employee_id, ped_type_id, total_installments, total_amount, effective_date, payee_type, payee_employee_id, bank_account_id, status)
        VALUES (:e, :pt, 1, 1000, '2020-01-01', :type, :payee, :bank, 'active')");
    // BOTH kinds of id on both sides: what is being measured is rows, not which lookups a row set
    // happens to need (an id kind that appears in neither set costs neither side a query).
    $insEed->execute([':e' => $empId, ':pt' => $pedTypeId, ':type' => 'employee', ':payee' => $payeeEmpId, ':bank' => null]);
    $insEed->execute([':e' => $empId, ':pt' => $pedTypeId, ':type' => 'company', ':payee' => null, ':bank' => $bankAccountId]);
    $before = questionCount($pdo);
    $small = $eedModel->list($empId, $compId);
    $costOfTwo = questionCount($pdo) - $before;
    for ($i = 0; $i < 10; $i++) {
        $insEed->execute([':e' => $empId, ':pt' => $pedTypeId, ':type' => 'company', ':payee' => null, ':bank' => $bankAccountId]);
    }
    $before = questionCount($pdo);
    $big = $eedModel->list($empId, $compId);
    $costOfTwelve = questionCount($pdo) - $before;
    check('2 rows in', count($small), 2);
    check('12 rows in', count($big), 12);
    check('...and the same number of statements either way', $costOfTwelve, $costOfTwo);
    checkTrue('the 12-row list still resolved its accounts',
        is_string($big[0]['payee']['bank_account_label_th'] ?? $big[0]['payee']['payee_employee_label_th']));

} finally {
    $pdo->rollBack();
}

echo "\nPassed: {$passes}, Failed: {$failures}\n";
exit($failures === 0 ? 0 : 1);
