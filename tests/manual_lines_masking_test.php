<?php
/**
 * 2026-09-16: `api/payroll-run.manual-lines` (the Manage Items modal's list of ad-hoc added
 * earning/deduction lines) is the SECOND endpoint that served real payroll figures unmasked --
 * the exact same rows `api/payroll-run.employee-adjustments` has been masking since 2026-09-10,
 * reached through a different URL. A role with no salary_amount visibility could read them all.
 *
 * What this locks:
 *  1. the endpoint resolves the reader's salary visibility and masks before responding;
 *  2. it masks through the SAME maskManualLines() its sibling employee-adjustments uses -- one rule,
 *     two callers, so the two can never disagree about who may see a manual line's amount;
 *  3. the rule: `amount` becomes 'XXXX' and nothing else is touched (item code, payee, destination,
 *     note, who added it and when all stay readable -- none of them is a salary figure);
 *  4. the key list still matches the row manualLinesForEmployee() actually returns.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Run with: php tests/manual_lines_masking_test.php
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

echo "=== 1. the endpoint is wired and masks ===\n";
$routes = file_get_contents(__DIR__ . '/../index.php');
checkTrue(
    "route api/payroll-run.manual-lines -> PayrollController@manualLinesForEmployee",
    strpos($routes, "\$router->get('api/payroll-run.manual-lines', 'PayrollController@manualLinesForEmployee');") !== false
);
$controllerSrc = file_get_contents(__DIR__ . '/../app/controllers/PayrollController.php');
$methodStart = strpos($controllerSrc, 'public function manualLinesForEmployee()');
checkTrue('PayrollController::manualLinesForEmployee() exists', $methodStart !== false);
$methodBody = substr($controllerSrc, $methodStart, strpos($controllerSrc, 'public function addManualLine()') - $methodStart);
checkTrue('it resolves the reader salary visibility', strpos($methodBody, 'resolveSalaryVisibility') !== false);
checkTrue('it asks payroll_process, the same module payroll-run.get asks', strpos($methodBody, "'payroll_process'") !== false);
checkTrue('it masks through maskManualLines()', strpos($methodBody, 'maskManualLines') !== false);
checkTrue('it gates on full (summary_only must not see line figures either)',
    strpos($methodBody, "\$visibility['full'] ? \$lines :") !== false);

echo "\n=== 2. one rule, both callers ===\n";
// The bug this file exists for was two endpoints serving one set of rows under two different
// masking rules. A second copy of the loop is how that comes back.
checkTrue('employee-adjustments masks its manual_lines through the same helper',
    strpos($controllerSrc, "\$data['manual_lines'] = \$this->maskManualLines(\$data['manual_lines']);") !== false);
$sharedMaskerStart = strpos($controllerSrc, 'private function maskManualLines');
checkTrue('maskManualLines() masks through the shared maskMonetaryKeys()',
    $sharedMaskerStart !== false && strpos(substr($controllerSrc, $sharedMaskerStart, 400), 'maskMonetaryKeys') !== false);

echo "\n=== 3. the masking rule ===\n";
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/controllers/PayrollController.php';
$masker = new ReflectionMethod('PayrollController', 'maskManualLines');
$masker->setAccessible(true);
$controller = (new ReflectionClass('PayrollController'))->newInstanceWithoutConstructor();
$masked = $masker->invoke($controller, [
    [
        'id' => 41, 'amount' => 800.0, 'note' => 'คืนเงินยืม', 'item_code' => 'LOAN_REPAY',
        'item_name_th' => 'ชำระคืนเงินกู้', 'item_name_en' => 'Loan Repayment', 'item_type' => 'deduction',
        'is_custom' => false, 'is_other' => false, 'payee_employee_id' => 12, 'payee_employee_no' => 'EMP012',
        'payee_type' => 'employee', 'destination_id' => null, 'destination_account_name' => null,
        'bank_account_id' => null, 'bank_account_name' => null, 'include_in_cash_summary' => 0,
        'created_by' => 28, 'created_by_name_th' => 'ผู้ดูแล', 'created_by_name_en' => 'Admin',
        'created_at' => '2026-09-16 09:56:08',
    ],
    ['id' => 42, 'amount' => 0.0, 'note' => null, 'item_code' => 'CUSTOM:Bonus', 'item_type' => 'earning'],
]);
check('amount is masked', $masked[0]['amount'], PermissionModel::MASK_VALUE);
check('a zero amount is masked too (0.00 is a real figure, not "no value")', $masked[1]['amount'], PermissionModel::MASK_VALUE);
// Everything that is NOT a figure has to survive -- the modal still has to say WHAT the line is,
// where the money goes and who added it, or it stops being usable rather than merely figure-free.
check('the line id survives (it is what Remove targets)', $masked[0]['id'], 41);
check('item code survives', $masked[0]['item_code'], 'LOAN_REPAY');
check('item name survives', $masked[0]['item_name_th'], 'ชำระคืนเงินกู้');
check('item_type survives', $masked[0]['item_type'], 'deduction');
check('the note survives', $masked[0]['note'], 'คืนเงินยืม');
check('the payee survives', [$masked[0]['payee_type'], $masked[0]['payee_employee_no']], ['employee', 'EMP012']);
check('who added it and when survive', [$masked[0]['created_by_name_th'], $masked[0]['created_at']], ['ผู้ดูแล', '2026-09-16 09:56:08']);
check('a null field is left null, not turned into a figure', $masked[1]['note'], null);
check('the row count is unchanged -- masking hides figures, it never drops lines', count($masked), 2);

echo "\n=== 4. the key list still matches what the model actually returns ===\n";
$pdo = Database::getInstance()->pdo;
$model = new PayrollRunModel();
$target = $pdo->query("SELECT pml.run_id, pml.employee_id, r.comp_id
    FROM `payroll_run_manual_lines` pml
    JOIN `payroll_runs` r ON r.id = pml.run_id
    WHERE r.deleted_at IS NULL ORDER BY pml.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$target) {
    echo "  SKIP  this dev DB has no payroll_run_manual_lines row to read a real shape from\n";
} else {
    $realLines = $model->manualLinesForEmployee((int)$target['comp_id'], (int)$target['run_id'], (int)$target['employee_id']);
    checkTrue('the model returns the real manual line', count($realLines) > 0);
    $moneyKeys = (new ReflectionClassConstant('PayrollController', 'MANUAL_LINE_MONEY_KEYS'))->getValue();
    foreach ($moneyKeys as $key) {
        checkTrue("the masked key '{$key}' actually exists on a real row", array_key_exists($key, $realLines[0]));
    }
    // The inverse guard: any OTHER key whose name reads like money is one the mask is not covering.
    $uncovered = array_values(array_filter(array_keys($realLines[0]), static function (string $key) use ($moneyKeys): bool {
        return !in_array($key, $moneyKeys, true) && preg_match('/(amount|_value|salary|total)/i', $key) === 1;
    }));
    check('no other money-shaped key is left unmasked on a real row', $uncovered, []);
    $realMasked = $masker->invoke($controller, $realLines);
    check('masking a real response replaces the amount with the mask string',
        $realMasked[0]['amount'], PermissionModel::MASK_VALUE);
    check('and leaves the rest of the real row byte-identical',
        array_diff_key($realMasked[0], ['amount' => null]), array_diff_key($realLines[0], ['amount' => null]));
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
