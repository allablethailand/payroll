<?php
/**
 * Lightweight verification script for the RBAC engine (PermissionModel): matrix()/saveMatrix()
 * and the checkPermission() gate used by SetupRulesController (holiday and leave-type endpoints)
 * and ApprovalWorkflowController (workflow endpoints, request act). Not PHPUnit -- see
 * tests/statutory_engine_test.php for why. Runs against the real dev DB inside a transaction
 * that is always rolled back.
 * Run with: php tests/permission_matrix_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PermissionModel.php';

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

function makeRole(PDO $pdo, int $compId, string $suffix): int {
    $stmt = $pdo->prepare("INSERT INTO structure_roles (comp_id, role_name_th, role_name_en) VALUES (:c, :th, :en)");
    $stmt->execute([':c' => $compId, ':th' => 'ทดสอบ ' . $suffix, ':en' => 'Test Role ' . $suffix]);
    return (int)$pdo->lastInsertId();
}

function makeEmployee(PDO $pdo, int $compId, string $employeeNo, ?int $roleId, ?int $deptId = null): int {
    $stmt = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         role_id, department_id, personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
         :role_id, :dept_id, :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', 30000, '2020-01-01', 'average', 'active',
         1, 1, 0)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => $employeeNo,
        ':name_th' => 'ทดสอบ', ':surname_th' => $employeeNo, ':name_en' => 'Test', ':surname_en' => $employeeNo,
        ':role_id' => $roleId, ':dept_id' => $deptId, ':email' => uniqid() . '@test.local',
    ]);
    return (int)$pdo->lastInsertId();
}

try {
    // 2026-08-30, real dev-DB-state fragility fixed (see feedback_dev_db_shared_state_test_fragility
    // in project memory) -- this test used to run against comp_id=1, the real shared dev DB company,
    // which by now has 5 real structure_roles rows of its own (matrix()'s own role query is
    // correctly comp_id-scoped, so those 5 real roles landed in this test's own role-count
    // assertions alongside the 2 fresh ones it creates below). Fixed by using a genuinely fresh,
    // isolated company instead -- zero pre-existing roles by construction, so the role-count
    // assertions are correct regardless of how much real data comp_id=1 accumulates going forward.
    // `permissions` itself (unlike structure_roles) is a GLOBAL table, not comp_id-scoped
    // (PermissionModel::listPermissions() has no WHERE comp_id at all) -- switching companies does
    // NOT fix that count on its own, so it's derived from a live query instead of a hardcoded number
    // just below.
    $insComp = $pdo->prepare("INSERT INTO `companies`
        (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, origami_payroll_comp_code)
        VALUES ('Permission Matrix Test Co.', 'Permission Matrix Test Co.', 'TH', '0000000000000', 'Test Address', 'Test Signatory', :comp_code)");
    $insComp->execute([':comp_code' => 'PMTEST_' . uniqid()]);
    $compId = (int)$pdo->lastInsertId();
    $adminUserId = 1;
    $model = new PermissionModel($pdo);

    // ---------- Fixtures ----------
    $roleManager = makeRole($pdo, $compId, 'Manager_' . uniqid());
    $roleStaff = makeRole($pdo, $compId, 'Staff_' . uniqid());
    $empManager = makeEmployee($pdo, $compId, 'PM_MGR_' . uniqid(), $roleManager);
    $empStaff = makeEmployee($pdo, $compId, 'PM_STF_' . uniqid(), $roleStaff);
    $empNoRole = makeEmployee($pdo, $compId, 'PM_NOROLE_' . uniqid(), null);

    $permissions = $model->listPermissions();
    $byKey = [];
    foreach ($permissions as $p) { $byKey[$p['permission_key']] = (int)$p['id']; }
    $expectedPermissionCount = (int)$pdo->query("SELECT COUNT(*) FROM permissions WHERE is_active = 1")->fetchColumn();
    check('listPermissions() returns every active permission row (live count, not a stale hardcoded one)', count($permissions), $expectedPermissionCount);

    // ---------- checkPermission: isAdmin bypass ----------
    $adminCheck = $model->checkPermission($empStaff, 'holiday.manage', true, $compId);
    checkTrue('isAdmin bypasses regardless of role', $adminCheck['allowed']);
    check('isAdmin bypass reports allow_scope=all', $adminCheck['allow_scope'], 'all');

    // ---------- checkPermission: no grants yet ----------
    $noneCheck = $model->checkPermission($empManager, 'holiday.manage', false, $compId);
    checkFalse('no role_permissions rows yet => denied', $noneCheck['allowed']);

    $noRoleCheck = $model->checkPermission($empNoRole, 'holiday.manage', false, $compId);
    checkFalse('employee with no role_id => denied', $noRoleCheck['allowed']);

    // ---------- matrix(): empty grants ----------
    $matrixBefore = $model->matrix($compId);
    check('matrix returns exactly the 2 fresh roles (isolated company, nothing else to pick up)', count($matrixBefore['roles']), 2);
    check('matrix returns every active permission (live count)', count($matrixBefore['permissions']), $expectedPermissionCount);
    check('matrix returns 0 grants before any save', count($matrixBefore['grants']), 0);

    // ---------- saveMatrix(): grant holiday.manage (all) to manager, approval_request.act (own_department) to manager ----------
    $saveResult = $model->saveMatrix($compId, [
        ['role_id' => $roleManager, 'permission_id' => $byKey['holiday.manage'], 'allow_scope' => 'all'],
        ['role_id' => $roleManager, 'permission_id' => $byKey['holiday.view'], 'allow_scope' => 'all'],
        ['role_id' => $roleManager, 'permission_id' => $byKey['approval_request.act'], 'allow_scope' => 'own_department'],
        ['role_id' => $roleStaff, 'permission_id' => $byKey['leave_type.view'], 'allow_scope' => 'all'],
    ], $adminUserId);
    checkTrue('saveMatrix with valid grants succeeds', $saveResult['status']);

    $matrixAfter = $model->matrix($compId);
    check('matrix now has 4 grants', count($matrixAfter['grants']), 4);

    // ---------- checkPermission: manager now has holiday.manage, not leave_type.manage ----------
    $mgrHoliday = $model->checkPermission($empManager, 'holiday.manage', false, $compId);
    checkTrue('manager granted holiday.manage is now allowed', $mgrHoliday['allowed']);
    check('manager holiday.manage allow_scope is all', $mgrHoliday['allow_scope'], 'all');

    $mgrLeave = $model->checkPermission($empManager, 'leave_type.manage', false, $compId);
    checkFalse('manager NOT granted leave_type.manage is denied', $mgrLeave['allowed']);

    $mgrAct = $model->checkPermission($empManager, 'approval_request.act', false, $compId);
    checkTrue('manager granted approval_request.act is allowed', $mgrAct['allowed']);
    check('manager approval_request.act allow_scope is own_department', $mgrAct['allow_scope'], 'own_department');

    $staffLeaveView = $model->checkPermission($empStaff, 'leave_type.view', false, $compId);
    checkTrue('staff granted leave_type.view is allowed', $staffLeaveView['allowed']);
    $staffHoliday = $model->checkPermission($empStaff, 'holiday.manage', false, $compId);
    checkFalse('staff NOT granted holiday.manage is denied', $staffHoliday['allowed']);

    // ---------- saveMatrix(): re-save replaces the whole set (staff loses leave_type.view) ----------
    $resave = $model->saveMatrix($compId, [
        ['role_id' => $roleManager, 'permission_id' => $byKey['holiday.manage'], 'allow_scope' => 'all'],
    ], $adminUserId);
    checkTrue('re-save with a smaller grant set succeeds', $resave['status']);
    $matrixResaved = $model->matrix($compId);
    check('matrix now has only 1 grant after replace', count($matrixResaved['grants']), 1);
    $staffAfterResave = $model->checkPermission($empStaff, 'leave_type.view', false, $compId);
    checkFalse('staff lost leave_type.view after replace-save', $staffAfterResave['allowed']);
    $mgrStillHoliday = $model->checkPermission($empManager, 'holiday.manage', false, $compId);
    checkTrue('manager still has holiday.manage after replace-save', $mgrStillHoliday['allowed']);

    // ---------- saveMatrix(): validation ----------
    $invalidRole = $model->saveMatrix($compId, [
        ['role_id' => 999999, 'permission_id' => $byKey['holiday.view'], 'allow_scope' => 'all'],
    ], $adminUserId);
    checkFalse('saveMatrix rejects a role_id not belonging to this company', $invalidRole['status']);

    $invalidPerm = $model->saveMatrix($compId, [
        ['role_id' => $roleManager, 'permission_id' => 999999, 'allow_scope' => 'all'],
    ], $adminUserId);
    checkFalse('saveMatrix rejects a nonexistent permission_id', $invalidPerm['status']);

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
