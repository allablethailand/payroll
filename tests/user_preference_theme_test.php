<?php
/**
 * 2026-09-05, real bug fix -- `employees.ui_theme` NULL used to be overloaded to mean both "never
 * configured" AND "explicitly chose System" (both fell through to the browser's own
 * prefers-color-scheme), so a never-configured employee whose OS/browser happened to be dark saw
 * the app go dark before ever touching Settings. Explicit request: default must be Light for
 * everyone until the employee changes it themselves. `ui_theme` widened to
 * ENUM('light','dark','system') -- see database/migrations/2026-09-05_1_ui_theme_add_system_value.sql
 * -- so "explicitly follow the OS" is its own real value, distinct from NULL.
 *
 * This covers UserPreferenceModel's own validation/persistence only. The actual DEFAULT-is-Light
 * behavior is enforced in app/views/layout/header.php's server-side stamping (a 3-way branch on
 * $_SESSION['user']['ui_theme']: 'dark' -> stamp dark, 'system' -> stamp nothing (follow OS),
 * anything else including null/'light' -> stamp light) and mirrored client-side in public/js/app.js
 * -- neither is exercised by a PHP CLI test since both are pure view/JS logic with no model call,
 * same standing limitation as every other canvas/UI-only feature in this project (see CLAUDE.md).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 * Run with: php tests/user_preference_theme_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/UserPreferenceModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

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
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }

try {
    $model = new UserPreferenceModel($pdo);
    $emp = $pdo->query("SELECT id, comp_id FROM employees WHERE deleted_at IS NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        throw new RuntimeException('No employee row available to test against.');
    }
    $employeeId = (int)$emp['id'];
    $compId = (int)$emp['comp_id'];

    // 'light'/'dark'/'system' all persist as their own literal enum values now.
    foreach (['light', 'dark', 'system'] as $theme) {
        $res = $model->save($employeeId, $compId, null, 'm', $theme);
        checkTrue("save() accepts theme='{$theme}'", (bool)$res['status']);
        $got = $model->get($employeeId, $compId);
        check("get() reflects theme='{$theme}' back exactly", $got['ui_theme'], $theme);
    }

    // null clears back to "never configured" (NOT 'system' -- the whole point of this fix).
    $res = $model->save($employeeId, $compId, null, 'm', null);
    checkTrue('save() accepts theme=null', (bool)$res['status']);
    $got = $model->get($employeeId, $compId);
    check('get() reflects null (never configured), not "system"', $got['ui_theme'], null);

    // Invalid values are still rejected, and the previously-saved value is left untouched.
    $model->save($employeeId, $compId, null, 'm', 'dark');
    $res = $model->save($employeeId, $compId, null, 'm', 'auto');
    checkFalse("save() rejects an invalid theme ('auto')", (bool)$res['status']);
    $got = $model->get($employeeId, $compId);
    check('rejected save left the prior value ("dark") untouched', $got['ui_theme'], 'dark');

    // Font size/language are unaffected by any of this -- still validated independently.
    $res = $model->save($employeeId, $compId, 'xx', 'm', 'light');
    checkFalse('save() still rejects an invalid language independently of theme', (bool)$res['status']);
    $res = $model->save($employeeId, $compId, null, 'xl', 'light');
    checkFalse('save() still rejects an invalid font size independently of theme', (bool)$res['status']);

} catch (Throwable $e) {
    $failures++;
    echo "  FAIL  Uncaught exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
