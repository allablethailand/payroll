<?php
/**
 * 2026-09-16: `api/payroll-run.sync-lines-for-employee` (the Adjustments modal's "ปรับตัวเลข" rows)
 * serves the SAME itemized payroll figures the Detail page's breakdown does, but used to return
 * every one of them in the clear regardless of who was asking -- a role with no salary_amount
 * visibility could read the whole calculation one employee at a time through this endpoint while
 * the page it sits on showed XXXX.
 *
 * What this locks:
 *  1. the endpoint resolves the reader's salary visibility at all, and masks through the SHARED
 *     primitive (maskMonetaryKeys()) rather than a fourth private copy of the same loop;
 *  2. the gate matches payroll-run.get's own breakdown-line gate exactly -- anything short of FULL
 *     (so `masked` AND `summary_only`) sees no figure;
 *  3. the rule: every monetary key becomes 'XXXX', a null stays null (masking it would invent an
 *     override that was never made), occurrences[] amounts go too, and nothing else is touched;
 *  4. the key list still matches the response the model actually produces -- the way this silently
 *     breaks is a renamed/added money column that the mask then never sees.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Run with: php tests/sync_lines_masking_test.php
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
    "route api/payroll-run.sync-lines-for-employee -> PayrollController@syncLinesForEmployee",
    strpos($routes, "\$router->get('api/payroll-run.sync-lines-for-employee', 'PayrollController@syncLinesForEmployee');") !== false
);
$controllerSrc = file_get_contents(__DIR__ . '/../app/controllers/PayrollController.php');
$methodStart = strpos($controllerSrc, 'public function syncLinesForEmployee()');
checkTrue('PayrollController::syncLinesForEmployee() exists', $methodStart !== false);
$methodBody = substr($controllerSrc, $methodStart, strpos($controllerSrc, 'public function lineOverrideSave()') - $methodStart);
checkTrue('it resolves the reader salary visibility', strpos($methodBody, 'resolveSalaryVisibility') !== false);
checkTrue('it asks payroll_process, the same module payroll-run.get asks', strpos($methodBody, "'payroll_process'") !== false);
checkTrue('it masks through maskAdjustLines()', strpos($methodBody, 'maskAdjustLines') !== false);
// One primitive, several callers -- a private copy of the loop is how two endpoints drift apart.
$adjustMaskerStart = strpos($controllerSrc, 'private function maskAdjustLines');
checkTrue('maskAdjustLines() masks through the shared maskMonetaryKeys()',
    $adjustMaskerStart !== false && strpos(substr($controllerSrc, $adjustMaskerStart), 'maskMonetaryKeys') !== false);
$adjustMaskerBody = substr($controllerSrc, $adjustMaskerStart, strpos($controllerSrc, 'public function syncLinesForEmployee()') - $adjustMaskerStart);
check('maskAdjustLines() writes no MASK_VALUE of its own -- it delegates the rule, it does not restate it',
    strpos($adjustMaskerBody, 'MASK_VALUE'), false);

echo "\n=== 2. the gate is the same one payroll-run.get uses for breakdown lines ===\n";
// payroll-run.get masks breakdown lines on `masked || summary_only`, i.e. on NOT full. These rows
// ARE breakdown lines, so the gate has to be the same -- not the stricter totals-only `masked`.
checkTrue("it gates on full, not on masked (summary_only must not see line figures either)",
    strpos($methodBody, "\$visibility['full'] ? \$lines :") !== false);
checkTrue('maskRunDetailRows() still gates its breakdown lines the same way (the rule being matched)',
    strpos($controllerSrc, "if (\$visibility['masked'] || \$visibility['summary_only']) {") !== false);

echo "\n=== 3. the masking rule ===\n";
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/controllers/PayrollController.php';
$masker = new ReflectionMethod('PayrollController', 'maskAdjustLines');
$masker->setAccessible(true);
$controller = (new ReflectionClass('PayrollController'))->newInstanceWithoutConstructor();
$masked = $masker->invoke($controller, [
    [
        'code' => 'OT', 'name_th' => 'ล่วงเวลา', 'name_en' => 'Overtime',
        'current_amount' => 5000.0, 'override_amount' => 4500.0, 'override_action' => 'override',
        'override_note' => 'ปรับตามใบรับรอง', 'line_type' => 'earning_deduction', 'item_type' => 'earning', 'note' => null,
        'occurrences' => [
            ['occurrence_code' => 'INST1', 'installment_no' => 1, 'amount' => 2500.0, 'applied_at' => '2026-09-01'],
            ['occurrence_code' => 'INST2', 'installment_no' => 2, 'amount' => 2500.0, 'applied_at' => '2026-09-15'],
        ],
    ],
    [
        'code' => 'TH_SSO', 'name_th' => 'ประกันสังคม', 'name_en' => 'Social Security',
        'current_amount' => 0.0, 'override_amount' => null, 'override_action' => null, 'override_note' => null,
        'line_type' => 'statutory', 'item_type' => 'statutory', 'note' => 'employee_not_enrolled',
    ],
]);
check('current_amount is masked', $masked[0]['current_amount'], PermissionModel::MASK_VALUE);
check('override_amount is masked', $masked[0]['override_amount'], PermissionModel::MASK_VALUE);
check('every occurrence amount is masked too (installments add up to the very figure being hidden)',
    [$masked[0]['occurrences'][0]['amount'], $masked[0]['occurrences'][1]['amount']],
    [PermissionModel::MASK_VALUE, PermissionModel::MASK_VALUE]);
check('a zero amount is still masked (0.00 is a real figure, not "no value")', $masked[1]['current_amount'], PermissionModel::MASK_VALUE);
check('a null override_amount stays null -- masking it would invent an override nobody made', $masked[1]['override_amount'], null);
// Everything that is NOT a figure has to survive, or the table becomes unreadable rather than
// merely figure-free -- and the engine's reason note is precisely what tells a user why a line is 0.
check('item code survives', $masked[0]['code'], 'OT');
check('name survives', $masked[0]['name_th'], 'ล่วงเวลา');
check('override_action survives', $masked[0]['override_action'], 'override');
check("the user's own note survives", $masked[0]['override_note'], 'ปรับตามใบรับรอง');
check("the engine's reason note survives", $masked[1]['note'], 'employee_not_enrolled');
check('line_type/item_type survive (they drive the grouping, not the figures)',
    [$masked[1]['line_type'], $masked[1]['item_type']], ['statutory', 'statutory']);
check('non-monetary occurrence fields survive', $masked[0]['occurrences'][0]['installment_no'], 1);
check('a row with no occurrences is left alone', array_key_exists('occurrences', $masked[1]), false);

echo "\n=== 4. the key list still matches what the model actually returns ===\n";
$pdo = Database::getInstance()->pdo;
$model = new PayrollRunModel();
$target = $pdo->query("SELECT d.run_id, d.employee_id, r.comp_id
    FROM `payroll_run_details` d
    JOIN `payroll_runs` r ON r.id = d.run_id
    WHERE r.deleted_at IS NULL ORDER BY d.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$target) {
    echo "  SKIP  this dev DB has no calculated payroll_run_details row to read a real shape from\n";
} else {
    $realLines = $model->syncDeductionLinesForEmployee((int)$target['comp_id'], (int)$target['run_id'], (int)$target['employee_id']);
    checkTrue('the model returns at least one row for a real run (base salary is always present)', count($realLines) > 0);
    $moneyKeys = (new ReflectionClassConstant('PayrollController', 'ADJUST_LINE_MONEY_KEYS'))->getValue();
    foreach ($moneyKeys as $key) {
        checkTrue("the masked key '{$key}' actually exists on a real row", array_key_exists($key, $realLines[0]));
    }
    // The inverse guard: any OTHER key whose name reads like money is one the mask is not covering.
    $uncovered = array_values(array_filter(array_keys($realLines[0]), static function (string $key) use ($moneyKeys): bool {
        return !in_array($key, $moneyKeys, true) && preg_match('/(amount|_value|salary|total)/i', $key) === 1;
    }));
    check('no other money-shaped key is left unmasked on a real row', $uncovered, []);
    $realMasked = $masker->invoke($controller, $realLines);
    checkTrue('masking a real response leaves no number behind in the masked keys',
        array_reduce($realMasked, static function (bool $carry, array $row) use ($moneyKeys): bool {
            foreach ($moneyKeys as $key) {
                if (($row[$key] ?? null) !== null && !is_string($row[$key])) { return false; }
            }
            return $carry;
        }, true));
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
