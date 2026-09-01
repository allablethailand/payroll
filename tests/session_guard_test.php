<?php
/**
 * Lightweight verification for ensure_login()'s new Phase 7 (T037/T038) logic in
 * app/helpers/helpers.php. Not PHPUnit -- see tests/statutory_engine_test.php for why.
 *
 * ensure_login() calls exit() on every "reject" path, which would kill a normal in-process test
 * script -- same reasoning that made auth/switch.php's own verification (see CLAUDE.md's
 * Authentication section) use a REAL, separate PHP CLI subprocess per scenario instead. Each
 * scenario below shells out to its own `php -r "..."` invocation (via shell_exec()) that sets up a
 * real PHP session (session_id() to a known value, session_start()) with realistic $_SESSION state,
 * calls the real ensure_login(), and echoes exactly one line so this outer script can parse it --
 * exercising the actual function, not a re-implementation/guess of what it does.
 *
 * Uses a real fixture employee + real employee_login_logs rows against the dev DB (own transaction,
 * rolled back at the end) -- the subprocesses connect to the SAME dev DB directly (not through this
 * script's own transaction, since each is a separate PHP process/connection) so the fixture rows
 * are committed for real for the duration of this test, then cleaned up explicitly in `finally`
 * (a real DELETE, not a rollback, since the subprocesses' own writes were never part of this
 * script's transaction to begin with).
 *
 * Run with: php tests/session_guard_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
// 2026-08-31: only for reading the SESSION_IDLE_TIMEOUT_SECONDS constant below (to keep the
// stale-duration fixtures always safely past whatever the current threshold is) -- ensure_login()
// itself is never called in THIS (outer) process, only in the subprocesses runEnsureLogin() shells
// out to, so requiring this here has no session/exit side effects.
require_once __DIR__ . '/../app/helpers/helpers.php';

$pdo = Database::getInstance()->pdo;

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

$root = str_replace('\\', '/', dirname(__DIR__));
$php = PHP_BINARY;

/**
 * Runs ensure_login() in a fresh subprocess with the given $_SESSION contents pre-seeded into a
 * real session (session_id() pinned so this test can inspect/reuse it), plus REQUEST_URI/
 * X-Requested-With so it takes the AJAX/API JSON branch (never the redirect-to-/auth branch, which
 * can't be inspected this way). Returns the decoded JSON body ensure_login() itself echoed, or null
 * if it printed nothing at all (i.e. it let the request through without rejecting it).
 */
function runEnsureLogin(string $root, string $php, string $sessionId, array $sessionData, string $requestUri = '/api/some-protected-endpoint'): ?array {
    $sessionDataPhp = var_export($sessionData, true);
    $script = <<<PHP
        error_reporting(E_ALL & ~E_DEPRECATED);
        session_id('{$sessionId}');
        \$_SERVER['REQUEST_URI'] = '{$requestUri}';
        \$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        session_start();
        \$_SESSION = {$sessionDataPhp};
        require '{$root}/vendor/autoload.php';
        \$dotenv = Dotenv\\Dotenv::createImmutable('{$root}');
        \$dotenv->load();
        require '{$root}/config.php';
        require '{$root}/app/core/Database.php';
        require '{$root}/app/helpers/helpers.php';
        ensure_login();
        echo "___NOT_REJECTED___";
        PHP;
    $tmpFile = tempnam(sys_get_temp_dir(), 'ensure_login_test_');
    file_put_contents($tmpFile, "<?php\n{$script}\n");
    $output = shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($tmpFile) . ' 2>&1');
    @unlink($tmpFile);
    if ($output === null) {
        return null;
    }
    if (strpos($output, '___NOT_REJECTED___') !== false) {
        return ['__not_rejected__' => true];
    }
    // Strip any stderr noise merged in via the 2>&1 above (this environment's own ever-present
    // "Module openssl is already loaded" PHP warning on every CLI invocation, unrelated to
    // anything under test) -- the real JSON body always starts at the first '{'.
    $jsonStart = strpos($output, '{');
    $jsonPart = $jsonStart !== false ? substr($output, $jsonStart) : $output;
    $decoded = json_decode(trim($jsonPart), true);
    return is_array($decoded) ? $decoded : ['__raw__' => $output];
}

try {
    $compId = 1;
    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'เซสชัน', 'Test', 'SessionGuard', '1990-01-01', 'Thai',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', 30000, '2020-01-01', 'average', 'active')");
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => 'SESSGUARD_TEST_' . uniqid()]);
    $employeeId = (int)$pdo->lastInsertId();

    require_once __DIR__ . '/../app/models/EmployeeLoginLogModel.php';
    $loginLogModel = new EmployeeLoginLogModel();
    $loginLogId = $loginLogModel->create($compId, $employeeId, '127.0.0.1', 'Mozilla/5.0 Test');

    echo "=== ensure_login(): a fresh, currently-active, recently-active session passes through untouched ===\n";
    $res1 = runEnsureLogin($root, $php, 'sgtestvalid' . str_replace('.', '', uniqid()), [
        'user' => ['employee_id' => $employeeId, 'company_id' => $compId, 'role' => 'user'],
        'login_log_id' => $loginLogId,
        'last_activity' => time(),
    ]);
    checkTrue('a valid, recently-active session is NOT rejected', isset($res1['__not_rejected__']));

    echo "=== T037: a session whose login_log_id has been superseded (is_active=0) is rejected with reason=superseded ===\n";
    $loginLogModel->endSession($loginLogId, $compId, $employeeId, 'new_login'); // simulate: a newer login elsewhere kicked this one
    $res2 = runEnsureLogin($root, $php, 'sgtestsuperseded' . str_replace('.', '', uniqid()), [
        'user' => ['employee_id' => $employeeId, 'company_id' => $compId, 'role' => 'user'],
        'login_log_id' => $loginLogId,
        'last_activity' => time(),
    ]);
    check('rejected with reason=superseded', $res2['reason'] ?? null, 'superseded');
    check('status is false', $res2['status'] ?? null, false);

    echo "=== T038: a session idle for longer than SESSION_IDLE_TIMEOUT_SECONDS is rejected with reason=timeout, and the login log row is marked ended ===\n";
    $loginLogId2 = $loginLogModel->create($compId, $employeeId, '127.0.0.1', 'Mozilla/5.0 Test');
    $res3 = runEnsureLogin($root, $php, 'sgtesttimeout' . str_replace('.', '', uniqid()), [
        'user' => ['employee_id' => $employeeId, 'company_id' => $compId, 'role' => 'user'],
        'login_log_id' => $loginLogId2,
        // 2026-08-31: timeout widened 30min -> 1h -- stale-duration fixtures below bumped to stay
        // well past the CURRENT threshold (SESSION_IDLE_TIMEOUT_SECONDS itself, not a hardcoded
        // number, so this file never has to be re-tuned again if the constant changes once more).
        'last_activity' => time() - SESSION_IDLE_TIMEOUT_SECONDS - 600,
    ]);
    check('rejected with reason=timeout', $res3['reason'] ?? null, 'timeout');
    checkTrue('endSession() was actually called server-side -- the login log row is now inactive', !$loginLogModel->isActive($loginLogId2, $employeeId));
    $timeoutRow = $pdo->query("SELECT ended_reason FROM employee_login_logs WHERE id = {$loginLogId2}")->fetch(PDO::FETCH_ASSOC);
    check('the row is tagged ended_reason=timeout by the server-side check itself', $timeoutRow['ended_reason'], 'timeout');

    echo "=== a heartbeat-route request does NOT bump last_activity (would defeat the idle timeout) ===\n";
    $loginLogId3 = $loginLogModel->create($compId, $employeeId, '127.0.0.1', 'Mozilla/5.0 Test');
    // A request stale past the threshold on the heartbeat route: since bumping is skipped there, a
    // heartbeat itself must NOT be able to indefinitely refresh an idle session -- it should be
    // rejected with reason=timeout exactly like any other route would be at that same staleness.
    $res4 = runEnsureLogin($root, $php, 'sgtestheartbeat' . str_replace('.', '', uniqid()), [
        'user' => ['employee_id' => $employeeId, 'company_id' => $compId, 'role' => 'user'],
        'login_log_id' => $loginLogId3,
        'last_activity' => time() - SESSION_IDLE_TIMEOUT_SECONDS - 600,
    ], '/api/session.heartbeat');
    check('a heartbeat call on an already-stale session is ALSO rejected as timeout (heartbeats check, they do not refresh)', $res4['reason'] ?? null, 'timeout');

    echo "=== a session with no login_log_id at all (pre-existing/older session) still enforces the idle timeout, just skips the is_active check ===\n";
    $res5 = runEnsureLogin($root, $php, 'sgtestnologinlog' . str_replace('.', '', uniqid()), [
        'user' => ['employee_id' => $employeeId, 'company_id' => $compId, 'role' => 'user'],
        'last_activity' => time() - SESSION_IDLE_TIMEOUT_SECONDS - 600,
    ]);
    check('still rejected with reason=timeout even with no login_log_id to check', $res5['reason'] ?? null, 'timeout');

    $res6 = runEnsureLogin($root, $php, 'sgtestnologinlogok' . str_replace('.', '', uniqid()), [
        'user' => ['employee_id' => $employeeId, 'company_id' => $compId, 'role' => 'user'],
        'last_activity' => time(),
    ]);
    checkTrue('...but passes through fine when recently active, with no login_log_id to check either', isset($res6['__not_rejected__']));

} finally {
    // Real DELETEs, not a rollback -- the subprocess PHP invocations above used their own separate
    // DB connections, entirely outside this script's own (nonexistent, deliberately -- see this
    // file's own top comment) transaction.
    if (isset($employeeId)) {
        $pdo->prepare("DELETE FROM employee_login_logs WHERE employee_id = :id")->execute([':id' => $employeeId]);
        $pdo->prepare("DELETE FROM employees WHERE id = :id")->execute([':id' => $employeeId]);
    }
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
