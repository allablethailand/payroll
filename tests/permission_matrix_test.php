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
    $compId = 1;
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
    check('8 permissions seeded', count($permissions), 8);

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
    check('matrix returns 2 roles', count($matrixBefore['roles']), 2);
    check('matrix returns 8 permissions', count($matrixBefore['permissions']), 8);
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
