<?php
/**
 * Lightweight verification script for Backlog Phase 10, T058: "Dashboard shows currently-online
 * users." Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB
 * inside a transaction that is always rolled back -- uses its own fresh companies (never touches
 * comp_id=1's live data, see feedback_dev_db_shared_state_test_fragility).
 *
 * Covers EmployeeLoginLogModel::touchLastSeen()/listOnlineForCompany() directly -- the two methods
 * where T058's real logic lives. ensure_login()'s own throttling (which calls touchLastSeen() on a
 * real HTTP request lifecycle) has no PHP-CLI test coverage, same standing limitation as every other
 * request-lifecycle-only code path in this app.
 *
 * Run with: php tests/employee_login_log_online_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/EmployeeLoginLogModel.php';

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

function makeCompany(PDO $pdo, string $countryCode = 'TH'): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function makeEmployee(PDO $pdo, int $compId, string $nameTh): int {
    $stmt = $pdo->prepare("INSERT INTO employees (comp_id, employee_no, name_th, surname_th, employment_date, employment_status, employment_type, workforce_type, record_time_method, salary_type, base_salary_amount, tax_calculation_method, employee_status)
        VALUES (:comp_id, :no, :name_th, 'Tester', CURDATE(), 'permanent', 'full_time', 'hr', 'manual', 'monthly', 30000, 'progressive', 'active')");
    $stmt->execute([':comp_id' => $compId, ':no' => 'EMP' . uniqid(), ':name_th' => $nameTh]);
    return (int)$pdo->lastInsertId();
}

/** Raw insert into employee_login_logs with explicit control over is_active/last_seen_at, bypassing
 *  EmployeeLoginLogModel::create()'s own real geolocation/UA-parsing side effects (irrelevant here). */
function makeLoginLog(PDO $pdo, int $compId, int $employeeId, bool $isActive, ?string $lastSeenAgo): int {
    $lastSeenExpr = $lastSeenAgo === null ? 'NULL' : "NOW() - INTERVAL {$lastSeenAgo}";
    $stmt = $pdo->prepare("INSERT INTO employee_login_logs (comp_id, employee_id, login_at, last_seen_at, is_active)
        VALUES (:comp_id, :employee_id, NOW(), {$lastSeenExpr}, :is_active)");
    $stmt->execute([':comp_id' => $compId, ':employee_id' => $employeeId, ':is_active' => $isActive ? 1 : 0]);
    return (int)$pdo->lastInsertId();
}

try {
    $model = new EmployeeLoginLogModel();
    $compA = makeCompany($pdo, 'TH');
    $compB = makeCompany($pdo, 'TH');

    $empRecent = makeEmployee($pdo, $compA, 'Recent');
    $empStale = makeEmployee($pdo, $compA, 'Stale');
    $empInactive = makeEmployee($pdo, $compA, 'Inactive');
    $empNeverSeen = makeEmployee($pdo, $compA, 'NeverSeen');
    $empOtherCompany = makeEmployee($pdo, $compB, 'OtherCompany');

    echo "=== fixtures ===\n";
    $logRecent = makeLoginLog($pdo, $compA, $empRecent, true, '1 MINUTE');
    $logStale = makeLoginLog($pdo, $compA, $empStale, true, '10 MINUTE'); // active but outside the default 5-minute window
    $logInactive = makeLoginLog($pdo, $compA, $empInactive, false, '1 MINUTE'); // fresh last_seen_at but session already ended/superseded
    makeLoginLog($pdo, $compA, $empNeverSeen, true, null); // is_active=1 but last_seen_at IS NULL (never touched yet)
    makeLoginLog($pdo, $compB, $empOtherCompany, true, '1 MINUTE');
    checkTrue('fixtures created without error', true);

    echo "\n=== touchLastSeen(): only updates a still-active row ===\n";
    // logInactive starts with last_seen_at ~1 minute ago (see fixture above) -- confirm touchLastSeen()
    // does NOT bump it further (the WHERE is_active=1 guard should reject this row entirely).
    $beforeStmt = $pdo->prepare("SELECT last_seen_at FROM employee_login_logs WHERE id = :id");
    $beforeStmt->execute([':id' => $logInactive]);
    $before = $beforeStmt->fetchColumn();
    sleep(1); // ensure a real, measurable CURRENT_TIMESTAMP delta if the guard were broken
    $model->touchLastSeen($logInactive);
    $afterStmt = $pdo->prepare("SELECT last_seen_at FROM employee_login_logs WHERE id = :id");
    $afterStmt->execute([':id' => $logInactive]);
    $after = $afterStmt->fetchColumn();
    check('touchLastSeen() on an INACTIVE row leaves last_seen_at unchanged', $after, $before);

    $model->touchLastSeen($logRecent);
    $afterActiveStmt = $pdo->prepare("SELECT last_seen_at FROM employee_login_logs WHERE id = :id");
    $afterActiveStmt->execute([':id' => $logRecent]);
    $afterActive = $afterActiveStmt->fetchColumn();
    checkFalse('touchLastSeen() on an ACTIVE row DOES update last_seen_at (moved forward)', $afterActive === $before);

    echo "\n=== listOnlineForCompany(): correct inclusion/exclusion ===\n";
    $online = $model->listOnlineForCompany($compA, 5);
    $onlineIds = array_map('intval', array_column($online, 'employee_id'));
    checkTrue('recently-active employee IS included', in_array($empRecent, $onlineIds, true));
    checkFalse('an ACTIVE session outside the time window is EXCLUDED (stale presence, not "online")', in_array($empStale, $onlineIds, true));
    checkFalse('an INACTIVE session (superseded/ended) is EXCLUDED even with a fresh last_seen_at', in_array($empInactive, $onlineIds, true));
    checkFalse('an active session that has NEVER been touched (last_seen_at IS NULL) is EXCLUDED', in_array($empNeverSeen, $onlineIds, true));
    checkFalse('a DIFFERENT company\'s online employee is EXCLUDED (cross-company isolation)', in_array($empOtherCompany, $onlineIds, true));

    $onlineWiderWindow = $model->listOnlineForCompany($compA, 15);
    $widerIds = array_map('intval', array_column($onlineWiderWindow, 'employee_id'));
    checkTrue('widening the window includes the previously-stale-but-still-active session', in_array($empStale, $widerIds, true));

    echo "\n=== listOnlineForCompany(): row shape ===\n";
    $recentRow = null;
    foreach ($online as $row) {
        if ((int)$row['employee_id'] === $empRecent) { $recentRow = $row; break; }
    }
    checkTrue('the recent row was actually found', $recentRow !== null);
    if ($recentRow !== null) {
        check('row carries name_th', $recentRow['name_th'], 'Recent');
        checkTrue('row carries a non-null last_seen_at', $recentRow['last_seen_at'] !== null);
    }

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
