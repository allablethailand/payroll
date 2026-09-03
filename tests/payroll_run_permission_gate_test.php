<?php
/**
 * Verifies the 2026-09-02 RBAC gate added to the Payroll Process module (PayrollController) and
 * the Reports module (ReportsController) -- payroll_run.view/payroll_run.manage, additive on top of
 * (at the time) the older structure_roles.can_process_payroll/can_approve_payroll/can_finalize_payroll
 * flat-role-flag system.
 *
 * 2026-09-03, Platform Hardening Phase 3: those 3 legacy columns are now DROPPED entirely (folded
 * into real payroll_run.process/.approve/.finalize permission keys, with per-user override support --
 * see database/migrations/2026-09-03_3_payroll_run_permission_split.sql and
 * 2026-09-03_4_drop_legacy_payroll_role_flags.sql). This file's original section 2 ("backfill mapping
 * logic") re-ran the 2026-09-02 migration's own INSERT...SELECT against fresh fixture roles to prove
 * the MAPPING LOGIC ITSELF was correct, not just a snapshot of one historical migration run -- that's
 * no longer possible to set up at all now that its SOURCE columns don't exist, so it's been removed
 * rather than left broken. The migration's own correctness was independently verified against real
 * live data at the time it ran (3 real comp_id=1 roles backfilled exactly correctly, see
 * project memory's Phase 3 notes) -- this file's remaining sections cover everything that's still a
 * live, ongoing concern:
 *  1. The 2 legacy payroll_run.view/.manage permission rows still exist with sane names (nothing
 *     about THIS pair was retired, only the legacy-flag backfill that USED to feed it).
 *  3. PayrollController's private requireViewAccess() helper actually resolves to `true` for a
 *     correctly-granted employee (reflection-invoked GRANTED path only -- the denied path calls
 *     $this->json()+returns internally, same as every other requirePermission() helper in this app,
 *     so it can't be safely invoked from a CLI test process; the underlying
 *     PermissionModel::checkPermission() denial is verified directly instead in step 4, the exact
 *     same call requireViewAccess() delegates to).
 *  4. checkPermission() itself correctly denies payroll_run.view/.manage for an employee whose role
 *     has no explicit grant.
 *  5. The 6 new payroll_run.process/.approve/.finalize/.add/.edit/.delete permission rows exist, and
 *     a real role_permissions grant on payroll_run.process is correctly honored.
 *
 * Run with: php tests/payroll_run_permission_gate_test.php
 */
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/helpers/helpers.php';
require_once __DIR__ . '/../app/models/PermissionModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/controllers/PayrollController.php';

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
    echo "=== 1. payroll_run.view/.manage permission rows exist ===\n";
    $stmt = $pdo->prepare("SELECT id, module_code, action_code, name_th, name_en FROM permissions WHERE permission_key = :k");
    $stmt->execute([':k' => 'payroll_run.view']);
    $viewPerm = $stmt->fetch(PDO::FETCH_ASSOC);
    checkTrue('payroll_run.view exists', $viewPerm !== false);
    check('payroll_run.view module_code', $viewPerm['module_code'] ?? null, 'payroll_run');
    checkTrue('payroll_run.view has a non-empty Thai name', !empty($viewPerm['name_th']));
    checkTrue('payroll_run.view has a non-empty English name', !empty($viewPerm['name_en']));

    $stmt->execute([':k' => 'payroll_run.manage']);
    $managePerm = $stmt->fetch(PDO::FETCH_ASSOC);
    checkTrue('payroll_run.manage exists', $managePerm !== false);
    check('payroll_run.manage module_code', $managePerm['module_code'] ?? null, 'payroll_run');
    $viewPermId = (int)$viewPerm['id'];
    $managePermId = (int)$managePerm['id'];

    echo "=== 2. Fixture roles for sections 3-5 below (real grants, not the retired legacy-flag backfill) ===\n";
    $compCode = 'PRPG_' . uniqid();
    $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', :comp_code)")
        ->execute([':name' => 'Payroll Run Permission Gate Test Co ' . uniqid(), ':comp_code' => $compCode]);
    $compId = (int)$pdo->lastInsertId();

    function makeFixtureRole(PDO $pdo, int $compId, string $label): int {
        $pdo->prepare("INSERT INTO structure_roles (comp_id, role_name_th, role_name_en, status)
            VALUES (:comp_id, :name, :name, 'active')")
            ->execute([':comp_id' => $compId, ':name' => $label . '_' . uniqid()]);
        return (int)$pdo->lastInsertId();
    }
    function grantOn(PDO $pdo, int $roleId, string $permissionKey): void {
        $stmt = $pdo->prepare("SELECT id FROM permissions WHERE permission_key = :k");
        $stmt->execute([':k' => $permissionKey]);
        $permId = (int)$stmt->fetchColumn();
        $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, allow_scope, detail_level) VALUES (:r, :p, 'all', 'full')")
            ->execute([':r' => $roleId, ':p' => $permId]);
    }
    // $roleProcess: granted payroll_run.view + payroll_run.manage + payroll_run.process directly --
    // used by section 3 (requireViewAccess()) and section 5 (payroll_run.process check) below.
    $roleProcess = makeFixtureRole($pdo, $compId, 'RoleProcess');
    grantOn($pdo, $roleProcess, 'payroll_run.view');
    grantOn($pdo, $roleProcess, 'payroll_run.manage');
    grantOn($pdo, $roleProcess, 'payroll_run.process');
    // $roleNone: no grants at all -- the denial-path fixture used by sections 4-5 below.
    $roleNone = makeFixtureRole($pdo, $compId, 'RoleNone');

    echo "=== 3. PayrollController::requireViewAccess() -- GRANTED path ===\n";
    // 2026-09-03, Platform Hardening Phase 3: requireManageAccess() (the coarse payroll_run.manage
    // gate this section used to also verify) is retired -- every mutating PayrollController method
    // now checks the SPECIFIC action it performs (payroll_run.process/.add/.edit/.delete) directly
    // via requirePermission(), not one shared "manage" helper -- see that controller's own comment
    // where requireManageAccess() used to be defined. requireViewAccess() is unaffected and still
    // the real gate for every read-only method, verified below same as before. The specific-action
    // checks are verified directly against PermissionModel::checkPermission() in step 4 below
    // instead (the exact same call requirePermission() delegates to), since requirePermission()
    // itself, like requireViewAccess()'s own denial path, calls $this->json()+returns rather than
    // throwing, so it can't be safely invoked from a CLI test process on the denied path either.
    $empProcess = 0;
    $pdo->prepare("INSERT INTO employees (comp_id, employee_no, name_th, surname_th, name_en, surname_en, role_id, employment_status, employee_status, deleted_at)
        VALUES (:comp_id, :no, 'Test', 'Process', 'Test', 'Process', :role_id, 'permanent', 'active', NULL)")
        ->execute([':comp_id' => $compId, ':no' => 'PRPG-1', ':role_id' => $roleProcess]);
    $empProcess = (int)$pdo->lastInsertId();

    $payrollController = new PayrollController();
    $_SESSION['user'] = ['employee_id' => $empProcess, 'company_id' => $compId, 'role' => 'user'];
    $reqView = new ReflectionMethod($payrollController, 'requireViewAccess');
    $reqView->setAccessible(true);
    checkTrue('requireViewAccess() returns true for an employee granted payroll_run.view/.process/.manage', $reqView->invoke($payrollController));

    echo "=== 4. checkPermission() denies payroll_run.view/.manage for a no-grant, no-flag employee (the same call the deny path of step 3's helpers would make) ===\n";
    $empNone = 0;
    $pdo->prepare("INSERT INTO employees (comp_id, employee_no, name_th, surname_th, name_en, surname_en, role_id, employment_status, employee_status, deleted_at)
        VALUES (:comp_id, :no, 'Test', 'None', 'Test', 'None', :role_id, 'permanent', 'active', NULL)")
        ->execute([':comp_id' => $compId, ':no' => 'PRPG-2', ':role_id' => $roleNone]);
    $empNone = (int)$pdo->lastInsertId();
    $permissionModel = new PermissionModel($pdo);
    $checkView = $permissionModel->checkPermission($empNone, 'payroll_run.view', false, $compId);
    $checkManage = $permissionModel->checkPermission($empNone, 'payroll_run.manage', false, $compId);
    checkFalse('a no-grant, no-flag employee is denied payroll_run.view', $checkView['allowed']);
    checkFalse('a no-grant, no-flag employee is denied payroll_run.manage', $checkManage['allowed']);
    $checkViewAdmin = $permissionModel->checkPermission($empNone, 'payroll_run.view', true, $compId);
    checkTrue('admin still bypasses payroll_run.view regardless of any grant', $checkViewAdmin['allowed']);

    echo "=== 5. payroll_run.process/.approve/.finalize/.add/.edit/.delete permission rows exist (2026-09-03 split) ===\n";
    foreach (['process', 'approve', 'finalize', 'add', 'edit', 'delete'] as $action) {
        $key = "payroll_run.{$action}";
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        checkTrue("{$key} exists", $row !== false);
        check("{$key} module_code", $row['module_code'] ?? null, 'payroll_run');
    }
    checkFalse('a no-grant employee is denied payroll_run.process too', $permissionModel->checkPermission($empNone, 'payroll_run.process', false, $compId)['allowed']);
    $checkProcessGranted = $permissionModel->checkPermission($empProcess, 'payroll_run.process', false, $compId);
    checkTrue('the process-role employee (granted payroll_run.process above, section 2 follow-up) is allowed', $checkProcessGranted['allowed']);

} catch (Throwable $e) {
    $failures++;
    echo "  FAIL  Unexpected exception: {$e->getMessage()}\n{$e->getTraceAsString()}\n";
}

$pdo->rollBack();
echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
