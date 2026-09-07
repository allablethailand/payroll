<?php
/**
 * Lightweight verification script for Backlog Phase 10, T059 ("RBAC -- suspend a user's system
 * access from settings"). Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against
 * the real dev DB inside a transaction that is always rolled back. Uses a fresh, isolated company
 * (never comp_id=1), see feedback_dev_db_shared_state_test_fragility in project memory.
 * Run with: php tests/employee_access_suspension_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PermissionModel.php';
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

function makeEmployee(PDO $pdo, int $compId, string $employeeNo): int {
    $stmt = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active',
         1, 1, 0)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => $employeeNo,
        ':name_th' => 'ทดสอบ', ':surname_th' => $employeeNo, ':name_en' => 'Test', ':surname_en' => $employeeNo,
        ':email' => uniqid() . '@test.local',
    ]);
    return (int)$pdo->lastInsertId();
}

try {
    $insComp = $pdo->prepare("INSERT INTO `companies`
        (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, origami_payroll_comp_code)
        VALUES ('Access Suspension Test Co.', 'Access Suspension Test Co.', 'TH', '0000000000000', 'Test Address', 'Test Signatory', :comp_code)");
    $insComp->execute([':comp_code' => 'ASTEST_' . uniqid()]);
    $compId = (int)$pdo->lastInsertId();

    $model = new PermissionModel($pdo);
    $loginLogModel = new EmployeeLoginLogModel();

    $admin = makeEmployee($pdo, $compId, 'ADM1');
    $targetA = makeEmployee($pdo, $compId, 'TGTA');
    $targetB = makeEmployee($pdo, $compId, 'TGTB');

    $anyPermRow = $pdo->query("SELECT id, permission_key FROM permissions WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $independentPermRow = $pdo->query("SELECT id, permission_key FROM permissions WHERE is_active = 1 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('fixture: at least one active permission exists', $anyPermRow !== false, true);
    $permissionCount = (int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE is_active = 1")->fetchColumn();

    echo "=== suspendEmployee(): guards ===\n";
    $selfSuspend = $model->suspendEmployee($compId, $admin, $admin, 'test');
    checkFalse('self-suspension is refused', $selfSuspend['status']);

    echo "\n=== A pre-existing, INDEPENDENT override on targetA (before any suspension) ===\n";
    $pdo->prepare("INSERT INTO employee_permission_overrides (comp_id, employee_id, permission_id, effect, allow_scope, detail_level) VALUES (:c, :e, :p, 'grant', 'own_department', 'summary')")
        ->execute([':c' => $compId, ':e' => $targetA, ':p' => $independentPermRow['id']]);

    echo "\n=== suspendEmployee(): success path ===\n";
    $suspendRes = $model->suspendEmployee($compId, $targetA, $admin, 'Policy violation');
    checkTrue('suspend succeeds', $suspendRes['status']);

    $alreadySuspended = $model->suspendEmployee($compId, $targetA, $admin, 'again');
    checkFalse('suspending an already-suspended employee is refused', $alreadySuspended['status']);

    $status = $model->suspensionStatus($compId, $targetA);
    checkTrue('suspensionStatus() reports suspended', $status !== null);
    check('suspensionStatus() reason matches', $status['reason'] ?? null, 'Policy violation');
    check('suspensionStatus() suspended_by matches the acting employee', $status['suspended_by'] ?? null, $admin);

    $stmtDenyCount = $pdo->prepare("SELECT COUNT(*) FROM employee_permission_overrides WHERE employee_id = :e AND comp_id = :c AND effect = 'deny'");
    $stmtDenyCount->execute([':e' => $targetA, ':c' => $compId]);
    check('every active permission now has a deny override for targetA', (int)$stmtDenyCount->fetchColumn(), $permissionCount);

    echo "\n=== checkPermission(): suspended employee denied for EVERYTHING, including a permission never explicitly overridden before ===\n";
    $checkResult = $model->checkPermission($targetA, $anyPermRow['permission_key'], false, $compId);
    checkFalse('checkPermission() denies a suspended employee', $checkResult['allowed']);
    // 2026-09-04, same-day follow-up: flipped after direct confirmation with the user -- a live
    // admin session must NEVER be blocked by a suspension marker sitting on that employee_id (the
    // hard "admin can never be locked out" guarantee), since suspendEmployee() itself has no way to
    // refuse an admin TARGET in the first place (no persistent admin flag exists anywhere in this
    // schema -- see the migration's own header comment). $isAdmin=true must therefore win.
    $checkResultAsAdmin = $model->checkPermission($targetA, $anyPermRow['permission_key'], true, $compId);
    checkTrue('a live admin session is NEVER blocked by a suspension marker (hard lockout guarantee)', $checkResultAsAdmin['allowed']);

    echo "\n=== targetA's active session was ended by suspend() ===\n";
    $loginLogId = $loginLogModel->create($compId, $targetA, '127.0.0.1', 'TestAgent/1.0');
    // Suspend again is refused (already suspended) -- unsuspend first, re-create the active-session
    // scenario cleanly, then suspend once more to observe the session-kill behavior in isolation.
    $model->unsuspendEmployee($compId, $targetA, $admin);
    $loginLogId2 = $loginLogModel->create($compId, $targetA, '127.0.0.1', 'TestAgent/1.0');
    checkTrue('fixture: targetA has an active session before re-suspension', $loginLogModel->isActive($loginLogId2, $targetA));
    $model->suspendEmployee($compId, $targetA, $admin, 'Second suspension');
    checkFalse('targetA\'s active session is ended immediately by suspendEmployee()', $loginLogModel->isActive($loginLogId2, $targetA));

    echo "\n=== unsuspendEmployee(): restores exactly the prior override state ===\n";
    $unsuspendMissingTarget = $model->unsuspendEmployee($compId, $targetB, $admin);
    checkFalse('unsuspending a never-suspended employee is refused', $unsuspendMissingTarget['status']);

    $unsuspendRes = $model->unsuspendEmployee($compId, $targetA, $admin);
    checkTrue('unsuspend succeeds', $unsuspendRes['status']);

    $statusAfter = $model->suspensionStatus($compId, $targetA);
    check('suspensionStatus() is null after unsuspend', $statusAfter, null);

    $checkAfterUnsuspend = $model->checkPermission($targetA, $anyPermRow['permission_key'], false, $compId);
    checkFalse('a permission never independently granted is correctly denied again after unsuspend (role-based, not by suspend residue)', $checkAfterUnsuspend['allowed']);

    $stmtSnapCount = $pdo->prepare("SELECT COUNT(*) FROM employee_suspension_permission_snapshots WHERE employee_id = :e AND comp_id = :c");
    $stmtSnapCount->execute([':e' => $targetA, ':c' => $compId]);
    check('snapshot rows are cleaned up after unsuspend', (int)$stmtSnapCount->fetchColumn(), 0);

    $independentOverrideAfter = $pdo->prepare("SELECT effect, allow_scope, detail_level FROM employee_permission_overrides WHERE employee_id = :e AND comp_id = :c AND permission_id = :p");
    $independentOverrideAfter->execute([':e' => $targetA, ':c' => $compId, ':p' => $independentPermRow['id']]);
    $independentRow = $independentOverrideAfter->fetch(PDO::FETCH_ASSOC);
    checkTrue('the PRE-EXISTING independent override on targetA still exists after unsuspend', $independentRow !== false);
    if ($independentRow !== false) {
        check('the independent override\'s effect is unchanged (grant, not left as deny)', $independentRow['effect'], 'grant');
        check('the independent override\'s allow_scope is unchanged', $independentRow['allow_scope'], 'own_department');
        check('the independent override\'s detail_level is unchanged', $independentRow['detail_level'], 'summary');
    }

    $stmtDenyCount2 = $pdo->prepare("SELECT COUNT(*) FROM employee_permission_overrides WHERE employee_id = :e AND comp_id = :c AND effect = 'deny'");
    $stmtDenyCount2->execute([':e' => $targetA, ':c' => $compId]);
    check('every suspend-created deny row is gone after unsuspend (only the 1 independent grant remains)', (int)$stmtDenyCount2->fetchColumn(), 0);

    $checkIndependentAfter = $model->checkPermission($targetA, $independentPermRow['permission_key'], false, $compId);
    checkTrue('the independently-granted permission is still granted after the whole suspend/unsuspend cycle', $checkIndependentAfter['allowed']);

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
