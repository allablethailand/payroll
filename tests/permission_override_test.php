<?php
/**
 * Lightweight verification script for Platform Hardening Phase 3's per-user permission override
 * mechanism (PermissionModel::checkPermission()'s new override check, employeesWithPermission()).
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back. Uses a fresh, isolated company (not comp_id=1) -- same
 * "dev DB has real accumulated data" fragility precaution tests/permission_matrix_test.php already
 * documents (see feedback_dev_db_shared_state_test_fragility in project memory).
 * Run with: php tests/permission_override_test.php
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

function makeEmployee(PDO $pdo, int $compId, string $employeeNo, ?int $roleId): int {
    $stmt = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         role_id, personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
         :role_id, :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active',
         1, 1, 0)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => $employeeNo,
        ':name_th' => 'ทดสอบ', ':surname_th' => $employeeNo, ':name_en' => 'Test', ':surname_en' => $employeeNo,
        ':role_id' => $roleId, ':email' => uniqid() . '@test.local',
    ]);
    return (int)$pdo->lastInsertId();
}

function grantRole(PDO $pdo, int $roleId, int $permissionId): void {
    $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, allow_scope, detail_level) VALUES (:r, :p, 'all', 'full')")
        ->execute([':r' => $roleId, ':p' => $permissionId]);
}

function setOverride(PDO $pdo, int $compId, int $employeeId, int $permissionId, string $effect): void {
    $pdo->prepare("INSERT INTO employee_permission_overrides (comp_id, employee_id, permission_id, effect) VALUES (:c, :e, :p, :eff)
        ON DUPLICATE KEY UPDATE effect = VALUES(effect)")
        ->execute([':c' => $compId, ':e' => $employeeId, ':p' => $permissionId, ':eff' => $effect]);
}

try {
    $insComp = $pdo->prepare("INSERT INTO `companies`
        (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, origami_payroll_comp_code)
        VALUES ('Permission Override Test Co.', 'Permission Override Test Co.', 'TH', '0000000000000', 'Test Address', 'Test Signatory', :comp_code)");
    $insComp->execute([':comp_code' => 'POTEST_' . uniqid()]);
    $compId = (int)$pdo->lastInsertId();

    $model = new PermissionModel($pdo);

    // Use 2 real permission_keys already in the table: one that a role WILL grant, one it won't.
    $holidayViewId = (int)$pdo->query("SELECT id FROM permissions WHERE permission_key = 'holiday.view'")->fetchColumn();
    $holidayAddId = (int)$pdo->query("SELECT id FROM permissions WHERE permission_key = 'holiday.add'")->fetchColumn();
    check('holiday.view permission row exists (Stage 1 migration ran)', $holidayViewId > 0, true);
    check('holiday.add permission row exists (Stage 1 migration ran)', $holidayAddId > 0, true);

    $grantedRole = makeRole($pdo, $compId, 'Granted');
    grantRole($pdo, $grantedRole, $holidayViewId); // this role has holiday.view, NOT holiday.add

    echo "\n=== checkPermission(): no override present -> falls through to role, unchanged ===\n";
    $empA = makeEmployee($pdo, $compId, 'POA001', $grantedRole);
    checkTrue('role-granted permission (holiday.view), no override -> allowed', $model->checkPermission($empA, 'holiday.view', false, $compId)['allowed']);
    checkFalse('role-NOT-granted permission (holiday.add), no override -> denied', $model->checkPermission($empA, 'holiday.add', false, $compId)['allowed']);

    echo "\n=== checkPermission(): deny override wins over a role grant ===\n";
    setOverride($pdo, $compId, $empA, $holidayViewId, 'deny');
    checkFalse('deny override on a role-granted permission -> denied', $model->checkPermission($empA, 'holiday.view', false, $compId)['allowed']);
    checkTrue('deny override does NOT affect a different, unrelated permission', $model->checkPermission($empA, 'holiday.add', false, $compId)['allowed'] === false); // still denied, unrelated to the override

    echo "\n=== checkPermission(): grant override wins over a role NON-grant ===\n";
    $empB = makeEmployee($pdo, $compId, 'POB001', $grantedRole);
    setOverride($pdo, $compId, $empB, $holidayAddId, 'grant');
    checkTrue('grant override on a role-NOT-granted permission -> allowed', $model->checkPermission($empB, 'holiday.add', false, $compId)['allowed']);
    checkTrue('the same employee still gets their normal role-granted permission too', $model->checkPermission($empB, 'holiday.view', false, $compId)['allowed']);
    $grantResult = $model->checkPermission($empB, 'holiday.add', false, $compId);
    check('grant override defaults allow_scope to all when not set', $grantResult['allow_scope'], 'all');
    check('grant override defaults detail_level to full when not set', $grantResult['detail_level'], 'full');

    echo "\n=== checkPermission(): admin bypasses everything, even with a deny override present ===\n";
    checkTrue('admin bypasses despite empA having a deny override on holiday.view', $model->checkPermission($empA, 'holiday.view', true, $compId)['allowed']);

    echo "\n=== checkPermission(): explicit allow_scope/detail_level on a grant override are honored ===\n";
    $empC = makeEmployee($pdo, $compId, 'POC001', null); // no role at all
    $pdo->prepare("INSERT INTO employee_permission_overrides (comp_id, employee_id, permission_id, effect, allow_scope, detail_level) VALUES (:c, :e, :p, 'grant', 'own_department', 'summary')")
        ->execute([':c' => $compId, ':e' => $empC, ':p' => $holidayViewId]);
    $scopedResult = $model->checkPermission($empC, 'holiday.view', false, $compId);
    checkTrue('grant override allowed even with no role at all', $scopedResult['allowed']);
    check('grant override honors its own explicit allow_scope', $scopedResult['allow_scope'], 'own_department');
    check('grant override honors its own explicit detail_level', $scopedResult['detail_level'], 'summary');

    echo "\n=== employeesWithPermission(): role-granted set, minus deny overrides, plus grant overrides ===\n";
    // Fresh set for clean isolation from the individual checkPermission() cases above.
    $roleX = makeRole($pdo, $compId, 'FanoutX');
    $keyId = (int)$pdo->query("SELECT id FROM permissions WHERE permission_key = 'leave_type.view'")->fetchColumn();
    grantRole($pdo, $roleX, $keyId);
    $roleY = makeRole($pdo, $compId, 'FanoutY'); // no grant at all

    $inRoleNoOverride = makeEmployee($pdo, $compId, 'POX001', $roleX);       // role-granted, no override -> IN
    $inRoleDenied = makeEmployee($pdo, $compId, 'POX002', $roleX);          // role-granted, denied -> OUT
    $noRoleGranted = makeEmployee($pdo, $compId, 'POX003', $roleY);          // not role-granted, override grants -> IN
    $noRoleNoOverride = makeEmployee($pdo, $compId, 'POX004', $roleY);       // neither -> OUT

    setOverride($pdo, $compId, $inRoleDenied, $keyId, 'deny');
    setOverride($pdo, $compId, $noRoleGranted, $keyId, 'grant');

    $holders = $model->employeesWithPermission($compId, 'leave_type.view');
    checkTrue('role-granted, no override -> included', in_array($inRoleNoOverride, $holders, true));
    checkFalse('role-granted, denied by override -> excluded', in_array($inRoleDenied, $holders, true));
    checkTrue('not role-granted, granted by override -> included', in_array($noRoleGranted, $holders, true));
    checkFalse('neither role nor override -> excluded', in_array($noRoleNoOverride, $holders, true));

    echo "\n=== 2026-09-03, Phase 3 Stage 5: employeeOverrides()/saveEmployeeOverrides() (Employee Detail's own 'Permission Overrides' tab data source) ===\n";
    $roleD = makeRole($pdo, $compId, 'Stage5Role');
    grantRole($pdo, $roleD, $holidayViewId); // role grants holiday.view, nothing else
    $empD = makeEmployee($pdo, $compId, 'POD001', $roleD);

    $rowsBefore = $model->employeeOverrides($compId, $empD);
    check('employeeOverrides() returns every active permission row (live count)', count($rowsBefore), count($model->listPermissions()));
    $rowHolidayView = current(array_filter($rowsBefore, fn($r) => $r['permission_id'] === $holidayViewId));
    $rowHolidayAdd = current(array_filter($rowsBefore, fn($r) => $r['permission_id'] === $holidayAddId));
    checkTrue('holiday.view row: role_granted=true (role grants it)', $rowHolidayView['role_granted']);
    check('holiday.view row: override_effect is null (no override yet, Inherit)', $rowHolidayView['override_effect'], null);
    checkFalse('holiday.add row: role_granted=false (role does not grant it)', $rowHolidayAdd['role_granted']);
    check('holiday.add row: override_effect is null (Inherit)', $rowHolidayAdd['override_effect'], null);

    // Deny the role-granted one, grant the not-role-granted one -- exactly the override UI's own use case.
    $saveResult = $model->saveEmployeeOverrides($compId, $empD, [
        ['permission_id' => $holidayViewId, 'effect' => 'deny'],
        ['permission_id' => $holidayAddId, 'effect' => 'grant', 'allow_scope' => 'own_department'],
    ], 1);
    checkTrue('saveEmployeeOverrides() with valid overrides succeeds', $saveResult['status']);

    $rowsAfter = $model->employeeOverrides($compId, $empD);
    $rowHolidayViewAfter = current(array_filter($rowsAfter, fn($r) => $r['permission_id'] === $holidayViewId));
    $rowHolidayAddAfter = current(array_filter($rowsAfter, fn($r) => $r['permission_id'] === $holidayAddId));
    check('holiday.view row now shows override_effect=deny', $rowHolidayViewAfter['override_effect'], 'deny');
    check('holiday.add row now shows override_effect=grant', $rowHolidayAddAfter['override_effect'], 'grant');
    check('holiday.add row honors the explicit allow_scope submitted', $rowHolidayAddAfter['allow_scope'], 'own_department');
    checkFalse('checkPermission() itself now reflects the deny override (role would have granted it)', $model->checkPermission($empD, 'holiday.view', false, $compId)['allowed']);
    checkTrue('checkPermission() itself now reflects the grant override (role would have denied it)', $model->checkPermission($empD, 'holiday.add', false, $compId)['allowed']);

    // Re-save with holiday.view back to Inherit (effect omitted) and holiday.add unchanged -- whole-
    // set replace semantics: holiday.view's row must be gone (delete-then-reinsert), not left as 'deny'.
    $resave = $model->saveEmployeeOverrides($compId, $empD, [
        ['permission_id' => $holidayAddId, 'effect' => 'grant'],
    ], 1);
    checkTrue('re-save (whole-set replace) succeeds', $resave['status']);
    $rowsResaved = $model->employeeOverrides($compId, $empD);
    $rowHolidayViewResaved = current(array_filter($rowsResaved, fn($r) => $r['permission_id'] === $holidayViewId));
    check('holiday.view override cleared back to Inherit (null) after re-save omitted it', $rowHolidayViewResaved['override_effect'], null);
    checkTrue('checkPermission() for holiday.view falls back to the role grant again (Inherit)', $model->checkPermission($empD, 'holiday.view', false, $compId)['allowed']);

    $invalidEmployee = $model->saveEmployeeOverrides($compId, 999999999, [], 1);
    checkFalse('saveEmployeeOverrides() rejects a nonexistent employee_id', $invalidEmployee['status']);

    $invalidPermission = $model->saveEmployeeOverrides($compId, $empD, [
        ['permission_id' => 999999999, 'effect' => 'grant'],
    ], 1);
    checkFalse('saveEmployeeOverrides() rejects a nonexistent permission_id', $invalidPermission['status']);

    $invalidEffect = $model->saveEmployeeOverrides($compId, $empD, [
        ['permission_id' => $holidayViewId, 'effect' => 'not_a_real_effect'],
    ], 1);
    checkFalse('saveEmployeeOverrides() rejects an invalid effect value', $invalidEffect['status']);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
