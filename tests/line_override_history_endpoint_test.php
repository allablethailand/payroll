<?php
/**
 * 2026-09-16, Adjustments modal > "ปรับตัวเลข" tab's own History column: a read-only endpoint
 * (`api/payroll-run.line-override-history`) over PayrollRunModel::lineOverrideAuditDiff()'s
 * per-employee filter.
 *
 * What this locks:
 *  1. the route exists and points at the controller method (the column silently loses its data
 *     otherwise -- the table still renders, just with no badge anywhere);
 *  2. that method masks, through the SAME helper its sibling endpoint uses -- this response carries
 *     itemized payroll figures (every edit's before/after), so a role without full salary visibility
 *     must never see a real number in it;
 *  3. the masking rule itself: every value field becomes 'XXXX', null stays null, and nothing else
 *     in the row is touched (who/when/action must stay readable -- they are not salary data);
 *  4. the employee filter actually filters (it is what keeps one employee's modal from showing
 *     another employee's edits).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Run with: php tests/line_override_history_endpoint_test.php
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

echo "=== 1. the endpoint is wired ===\n";
$routes = file_get_contents(__DIR__ . '/../index.php');
checkTrue(
    "route api/payroll-run.line-override-history -> PayrollController@lineOverrideHistory",
    strpos($routes, "\$router->get('api/payroll-run.line-override-history', 'PayrollController@lineOverrideHistory');") !== false
);
$controllerSrc = file_get_contents(__DIR__ . '/../app/controllers/PayrollController.php');
checkTrue('PayrollController::lineOverrideHistory() exists', strpos($controllerSrc, 'public function lineOverrideHistory()') !== false);

echo "\n=== 2. it masks, via the shared helper (not its own copy) ===\n";
$methodStart = strpos($controllerSrc, 'public function lineOverrideHistory()');
$methodBody = substr($controllerSrc, $methodStart, strpos($controllerSrc, 'public function employeeAdjustments()') - $methodStart);
checkTrue('it resolves the reader\'s salary visibility', strpos($methodBody, 'resolveSalaryVisibility') !== false);
checkTrue('it masks through maskOverrideDiffLine()', strpos($methodBody, 'maskOverrideDiffLine') !== false);
checkTrue('it reports the history feature start date so the UI can footnote it', strpos($methodBody, 'LINE_OVERRIDE_HISTORY_FEATURE_START_DATE') !== false);
// Both endpoints must keep sharing one masker -- a second copy is how the two drift apart.
checkTrue('employeeAdjustments() masks through the same helper', strpos($controllerSrc, '$ov = $this->maskOverrideDiffLine($ov);') !== false);

echo "\n=== 3. the masking rule ===\n";
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/controllers/PayrollController.php';
$masker = new ReflectionMethod('PayrollController', 'maskOverrideDiffLine');
$masker->setAccessible(true);
$controller = (new ReflectionClass('PayrollController'))->newInstanceWithoutConstructor();
$line = $masker->invoke($controller, [
    'line_type' => 'earning_deduction',
    'item_code' => 'BONUS',
    'original_value' => 2000.0,
    'current_value' => 1500.0,
    'edits' => [
        ['action' => 'override', 'old_value' => 2000.0, 'new_value' => 1500.0, 'note' => 'first', 'changed_by' => 28,
         'changed_by_name_th' => 'ผู้ดูแล', 'changed_by_name_en' => 'Admin', 'changed_at' => '2026-09-16 09:56:08'],
        ['action' => 'restore', 'old_value' => 1500.0, 'new_value' => null, 'note' => null, 'changed_by' => 28,
         'changed_by_name_th' => 'ผู้ดูแล', 'changed_by_name_en' => 'Admin', 'changed_at' => '2026-09-16 10:00:00'],
    ],
]);
check('original_value is masked', $line['original_value'], PermissionModel::MASK_VALUE);
check('current_value is masked', $line['current_value'], PermissionModel::MASK_VALUE);
check('edit 1 old_value is masked', $line['edits'][0]['old_value'], PermissionModel::MASK_VALUE);
check('edit 1 new_value is masked', $line['edits'][0]['new_value'], PermissionModel::MASK_VALUE);
check('edit 2 old_value is masked', $line['edits'][1]['old_value'], PermissionModel::MASK_VALUE);
// A null value has nothing to hide -- masking it would invent a figure that never existed.
check('a null value stays null, it is not masked into a figure', $line['edits'][1]['new_value'], null);
// Everything that is NOT a salary figure has to survive, or the dropdown loses who/when/what.
check('action survives', $line['edits'][0]['action'], 'override');
check('changed_at survives', $line['edits'][0]['changed_at'], '2026-09-16 09:56:08');
check('changed_by name survives', $line['edits'][0]['changed_by_name_th'], 'ผู้ดูแล');
check('note survives', $line['edits'][0]['note'], 'first');
check('item_code survives', $line['item_code'], 'BONUS');
$noValues = $masker->invoke($controller, ['original_value' => null, 'current_value' => null, 'edits' => []]);
check('a line with no values at all masks to nothing', [$noValues['original_value'], $noValues['current_value']], [null, null]);

echo "\n=== 4. the employee filter ===\n";
$pdo = Database::getInstance()->pdo;
$model = new PayrollRunModel();
$pdo->beginTransaction();
try {
    $target = $pdo->query("SELECT h.run_id, h.employee_id, r.comp_id
        FROM `payroll_run_line_override_history` h
        JOIN `payroll_runs` r ON r.id = h.run_id
        WHERE r.deleted_at IS NULL ORDER BY h.id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        echo "  SKIP  this dev DB has no recorded override history to filter\n";
    } else {
        $runId = (int)$target['run_id'];
        $compId = (int)$target['comp_id'];
        $employeeId = (int)$target['employee_id'];
        // A second employee's edit on the same run, so "filtered" means something.
        $otherId = (int)($pdo->query("SELECT id FROM `employees` WHERE comp_id = {$compId} AND id <> {$employeeId} LIMIT 1")->fetchColumn() ?: 0);
        if ($otherId) {
            $pdo->prepare("INSERT INTO `payroll_run_line_override_history`
                    (run_id, employee_id, line_type, item_code, action, old_value, new_value, changed_by)
                VALUES (?, ?, 'earning_deduction', '__filter_probe__', 'override', 10, 20, ?)")
                ->execute([$runId, $otherId, $employeeId]);
        }
        $whole = $model->lineOverrideAuditDiff($runId, $compId);
        $scoped = $model->lineOverrideAuditDiff($runId, $compId, $employeeId);
        checkTrue('the whole-run view sees both employees', count($whole['lines']) > count($scoped['lines']));
        $employeeIds = array_values(array_unique(array_map(static fn($l) => $l['employee_id'], $scoped['lines'])));
        check('the scoped view returns exactly one employee', $employeeIds, [$employeeId]);
        $first = $scoped['lines'][0] ?? [];
        foreach (['line_type', 'item_code', 'original_value', 'current_value', 'edits'] as $field) {
            checkTrue("a scoped line carries {$field} (the dropdown reads it)", array_key_exists($field, $first));
        }
        $edit = $first['edits'][0] ?? [];
        foreach (['action', 'new_value', 'changed_at', 'changed_by_name_th', 'changed_by_name_en'] as $field) {
            checkTrue("an edit carries {$field}", array_key_exists($field, $edit));
        }
    }
} finally {
    $pdo->rollBack();
}

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
