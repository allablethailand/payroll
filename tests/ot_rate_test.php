<?php
/**
 * Lightweight verification script for OtRateSetModel (2026-08-30 -- OT Rate Set replacement of the
 * old flat one-row-per-scope `ot_rates` table/SetupRulesModel::otRate* CRUD).
 *
 * Explicit request: "ในการจัดการ OT ตอนนี้สร้างได้เรื่อยๆ ถ้าตอนที่นำไปคำนวณ ถ้าบันทึกข้อมูลซ้ำ แต่คนละ Rate
 * จะแก้ไขยังไง...ตอนกดบวกรายการ ให้ขึ้นมาเลยเป็นชุดของ OT Type แล้วมี form ในแต่ละ Type ให้ระบุ...เท่ากับว่า 1
 * ชุดข้อมูลมีทุก Type ให้จัดการ แต่สามารถจัดการแยกกันได้แต่ละ type ในแถวเดียวกัน และให้เพิ่มการ Assign ให้ด้วย ว่ามี
 * ผลกับแผนก ทีม ตำแหน่ง หรือพนักงานคนไหน และป้องกันการบันทึกซ้ำ...บังคับไปเลยว่าต้องมี Default". Resolution
 * priority (confirmed via AskUserQuestion): employee > team > position > department > mandatory
 * company Default.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 * Run with: php tests/ot_rate_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/OtRateSetModel.php';

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
    $compId = 1;
    $userId = 1;
    $model = new OtRateSetModel($pdo);

    // Same shared-dev-DB isolation precautions tests/payroll_run_test.php already documents.
    $pdo->prepare("UPDATE `ot_rate_sets` SET deleted_at = NOW(), status = 'deleted' WHERE comp_id = :comp_id AND deleted_at IS NULL")->execute([':comp_id' => $compId]);
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL")->execute([':comp_id' => $compId]);

    $scopes = $model->otScopeOptions();
    check('3 OT scope types seeded', count($scopes), 3);
    $weekdayId = $weekendId = $holidayId = null;
    foreach ($scopes as $s) {
        if ($s['code'] === 'weekday') { $weekdayId = (int)$s['id']; }
        if ($s['code'] === 'weekend') { $weekendId = (int)$s['id']; }
        if ($s['code'] === 'holiday') { $holidayId = (int)$s['id']; }
    }
    checkTrue('all 3 scope codes resolved', $weekdayId !== null && $weekendId !== null && $holidayId !== null);

    echo "=== save(): validation ===\n";
    $missingName = $model->save(['items' => []], $compId, $userId);
    checkFalse('missing name_th rejected', $missingName['status']);

    $badScope = $model->save(['name_th' => 'X', 'items' => [['ot_scope_id' => 999999, 'calculation_method' => 'multiplier', 'multiplier_rate' => 1.5]]], $compId, $userId);
    checkFalse('unknown ot_scope_id in items rejected', $badScope['status']);

    $zeroMultiplier = $model->save(['name_th' => 'X', 'items' => [['ot_scope_id' => $weekdayId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 0]]], $compId, $userId);
    checkFalse('zero multiplier_rate rejected', $zeroMultiplier['status']);

    $zeroFlat = $model->save(['name_th' => 'X', 'items' => [['ot_scope_id' => $weekdayId, 'calculation_method' => 'flat_amount', 'flat_amount_rate' => 0]]], $compId, $userId);
    checkFalse('zero flat_amount_rate rejected', $zeroFlat['status']);

    echo "=== save(): first set is force-defaulted (\"บังคับไปเลยว่าต้องมี Default\") ===\n";
    $set1 = $model->save([
        'name_th' => 'ชุดมาตรฐาน', 'name_en' => 'Standard Set', 'is_default' => false, // explicitly false -- must still be forced true
        'items' => [
            ['ot_scope_id' => $weekdayId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 1.5, 'calculation_base' => 'hourly'],
            ['ot_scope_id' => $weekendId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 2.0, 'calculation_base' => 'hourly'],
            ['ot_scope_id' => $holidayId, 'calculation_method' => 'flat_amount', 'flat_amount_rate' => 500.0, 'calculation_base' => 'daily'],
        ],
    ], $compId, $userId);
    checkTrue('first set create succeeds' . (empty($set1['status']) ? " ({$set1['message']})" : ''), $set1['status']);
    $set1Id = (int)$set1['id'];
    $set1Row = $model->get($set1Id, $compId);
    checkTrue('first set forced to is_default=true even though submitted false', $set1Row['is_default']);
    check('first set has all 3 items', count($set1Row['items']), 3);

    echo "=== save(): a SECOND set is NOT auto-defaulted ===\n";
    $set2 = $model->save([
        'name_th' => 'ชุดที่สอง', 'name_en' => 'Second Set', 'is_default' => false,
        'items' => [['ot_scope_id' => $weekdayId, 'calculation_method' => 'flat_amount', 'flat_amount_rate' => 100.0, 'calculation_base' => 'hourly']],
    ], $compId, $userId);
    checkTrue('second set create succeeds' . (empty($set2['status']) ? " ({$set2['message']})" : ''), $set2['status']);
    $set2Id = (int)$set2['id'];
    checkFalse('second set is NOT default (first set already claimed it)', $model->get($set2Id, $compId)['is_default']);

    echo "=== delete()/toggleStatus(): the current Default set cannot be deleted/deactivated ===\n";
    checkFalse('delete() blocked on the default set', $model->delete($set1Id, $compId, $userId)['status']);
    checkFalse('toggleStatus() blocked on the default set', $model->toggleStatus($set1Id, $compId, $userId)['status']);
    checkTrue('toggleStatus() allowed on a NON-default set', $model->toggleStatus($set2Id, $compId, $userId)['status']);
    check('second set toggled to inactive', $model->get($set2Id, $compId)['status'], 'inactive');
    checkTrue('toggling back to active succeeds', $model->toggleStatus($set2Id, $compId, $userId)['status']);

    echo "=== setDefault(): explicit default switch, clears the new default's own assignments ===\n";
    // fixture scopes for assignment tests below.
    $deptCode = 'OTR_DEPT_' . uniqid();
    $pdo->prepare("INSERT INTO structure_departments (comp_id, department_code, department_name_th, department_name_en, status, created_by) VALUES (:c, :code, 'แผนกทดสอบ', 'Test Dept', 'active', :u)")
        ->execute([':c' => $compId, ':code' => $deptCode, ':u' => $userId]);
    $deptId = (int)$pdo->lastInsertId();
    $teamCode = 'OTR_TEAM_' . uniqid();
    $pdo->prepare("INSERT INTO structure_teams (comp_id, team_code, team_name_th, team_name_en, status, created_by) VALUES (:c, :code, 'ทีมทดสอบ', 'Test Team', 'active', :u)")
        ->execute([':c' => $compId, ':code' => $teamCode, ':u' => $userId]);
    $teamId = (int)$pdo->lastInsertId();
    $posCode = 'OTR_POS_' . uniqid();
    $pdo->prepare("INSERT INTO structure_positions (comp_id, position_code, position_name_th, position_name_en, status, created_by) VALUES (:c, :code, 'ตำแหน่งทดสอบ', 'Test Position', 'active', :u)")
        ->execute([':c' => $compId, ':code' => $posCode, ':u' => $userId]);
    $posId = (int)$pdo->lastInsertId();

    $set3 = $model->save([
        'name_th' => 'ชุดแผนก', 'name_en' => 'Department Set', 'is_default' => false,
        'items' => [['ot_scope_id' => $weekdayId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 3.0, 'calculation_base' => 'hourly']],
        'assignments' => [['scope_type' => 'department', 'scope_id' => $deptId]],
    ], $compId, $userId);
    checkTrue('third set (with a department assignment) create succeeds' . (empty($set3['status']) ? " ({$set3['message']})" : ''), $set3['status']);
    $set3Id = (int)$set3['id'];
    check('third set has 1 assignment row', count($model->get($set3Id, $compId)['assignments']), 1);

    $setDefaultRes = $model->setDefault($set3Id, $compId, $userId);
    checkTrue('setDefault() on set 3 succeeds' . (empty($setDefaultRes['status']) ? " ({$setDefaultRes['message']})" : ''), $setDefaultRes['status']);
    checkTrue('set 3 is now the default', $model->get($set3Id, $compId)['is_default']);
    checkFalse('set 1 is no longer the default', $model->get($set1Id, $compId)['is_default']);
    check('set 3 own assignments were cleared by becoming default (Default is always the catch-all)', count($model->get($set3Id, $compId)['assignments']), 0);
    // Restore set 1 as default for the rest of this file's assertions.
    checkTrue('restoring set 1 as default succeeds', $model->setDefault($set1Id, $compId, $userId)['status']);
    // becoming-default cleared set 3's own department assignment above (by design) -- re-establish it
    // (deptId is currently unclaimed by anything, so this must succeed) before testing duplicate
    // rejection against it below.
    $set3Reassign = $model->save([
        'id' => $set3Id, 'name_th' => 'ชุดแผนก', 'name_en' => 'Department Set', 'is_default' => false,
        'items' => [['ot_scope_id' => $weekdayId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 3.0, 'calculation_base' => 'hourly']],
        'assignments' => [['scope_type' => 'department', 'scope_id' => $deptId]],
    ], $compId, $userId);
    checkTrue('re-establishing set 3\'s department assignment after the setDefault dance succeeds' . (empty($set3Reassign['status']) ? " ({$set3Reassign['message']})" : ''), $set3Reassign['status']);

    echo "=== save(): duplicate-assignment rejection (\"ป้องกันการบันทึกซ้ำ\") ===\n";
    $set4 = $model->save([
        'name_th' => 'ชุดที่สี่', 'name_en' => 'Fourth Set', 'is_default' => false,
        'items' => [['ot_scope_id' => $weekdayId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 1.75, 'calculation_base' => 'hourly']],
        'assignments' => [['scope_type' => 'department', 'scope_id' => $deptId]], // already claimed by set 3
    ], $compId, $userId);
    checkFalse('a department already claimed by another active set is rejected', $set4['status']);
    checkTrue('rejection message names the conflicting set', strpos((string)$set4['message'], 'ชุดแผนก') !== false);

    $set4b = $model->save([
        'name_th' => 'ชุดที่สี่', 'name_en' => 'Fourth Set', 'is_default' => false,
        'items' => [['ot_scope_id' => $weekdayId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 1.75, 'calculation_base' => 'hourly']],
        'assignments' => [['scope_type' => 'team', 'scope_id' => $teamId], ['scope_type' => 'position', 'scope_id' => $posId]],
    ], $compId, $userId);
    checkTrue('a genuinely free team+position assignment succeeds' . (empty($set4b['status']) ? " ({$set4b['message']})" : ''), $set4b['status']);
    $set4Id = (int)$set4b['id'];

    // Re-saving set 3 with its OWN existing department assignment must not self-conflict.
    $set3Resave = $model->save([
        'id' => $set3Id, 'name_th' => 'ชุดแผนก', 'name_en' => 'Department Set', 'is_default' => false,
        'items' => [['ot_scope_id' => $weekdayId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 3.0, 'calculation_base' => 'hourly']],
        'assignments' => [['scope_type' => 'department', 'scope_id' => $deptId]],
    ], $compId, $userId);
    checkTrue('re-saving a set with its own existing assignment does not self-conflict' . (empty($set3Resave['status']) ? " ({$set3Resave['message']})" : ''), $set3Resave['status']);

    echo "=== resolveRatesForEmployees(): priority employee > team > position > department > mandatory Default ===\n";
    $mkEmp = function (?int $deptId, ?int $teamId, ?int $posId, ?int $assignedSetId) use ($pdo, $compId) {
        $stmt = $pdo->prepare("INSERT INTO `employees`
            (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
             personal_email, mobile_no, address_line_1_register, address_line_1_contact,
             emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
             employment_date, employment_status, employment_type, workforce_type, record_time_method,
             salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt, department_id, team_id, position_id, assigned_ot_rate_set_id)
            VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'OTR', 'Test', 'OTR', '1990-01-01', 'Thai',
             :email, '0800000000', 'Test Address', 'Test Address', 'Emergency', 'Contact', 'friend', '0899999999',
             '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
             'monthly', 30000, '2020-01-01', 'average', 'active', 1, 1, 0, :dept, :team, :pos, :set)");
        $stmt->execute([
            ':comp_id' => $compId, ':employee_no' => 'OTR_EMP_' . uniqid(), ':email' => uniqid() . '@test.local',
            ':dept' => $deptId, ':team' => $teamId, ':pos' => $posId, ':set' => $assignedSetId,
        ]);
        return (int)$pdo->lastInsertId();
    };

    $empNoScope = $mkEmp(null, null, null, null); // falls all the way through to the mandatory Default (set 1)
    $empDeptOnly = $mkEmp($deptId, null, null, null); // matches set 3 via department
    $empPosOverDept = $mkEmp($deptId, null, $posId, null); // position (set 4) wins over department (set 3)
    $empTeamOverAll = $mkEmp($deptId, $teamId, $posId, null); // team (set 4, same set as position here) wins over both
    $empExplicitOverride = $mkEmp($deptId, $teamId, $posId, $set2Id); // explicit assigned_ot_rate_set_id wins outright over every assignment match

    $rows = [
        ['id' => $empNoScope, 'department_id' => null, 'team_id' => null, 'position_id' => null, 'assigned_ot_rate_set_id' => null],
        ['id' => $empDeptOnly, 'department_id' => $deptId, 'team_id' => null, 'position_id' => null, 'assigned_ot_rate_set_id' => null],
        ['id' => $empPosOverDept, 'department_id' => $deptId, 'team_id' => null, 'position_id' => $posId, 'assigned_ot_rate_set_id' => null],
        ['id' => $empTeamOverAll, 'department_id' => $deptId, 'team_id' => $teamId, 'position_id' => $posId, 'assigned_ot_rate_set_id' => null],
        ['id' => $empExplicitOverride, 'department_id' => $deptId, 'team_id' => $teamId, 'position_id' => $posId, 'assigned_ot_rate_set_id' => $set2Id],
    ];
    $resolved = $model->resolveRatesForEmployees($rows, $compId);

    check('no-scope employee resolves to the mandatory Default set (set 1)', $resolved[$empNoScope]['set_id'], $set1Id);
    check('department-only employee resolves to set 3 (department assignment)', $resolved[$empDeptOnly]['set_id'], $set3Id);
    check('position beats department: resolves to set 4', $resolved[$empPosOverDept]['set_id'], $set4Id);
    check('team beats position AND department: resolves to set 4 (same set here, but via the team match)', $resolved[$empTeamOverAll]['set_id'], $set4Id);
    check('explicit assigned_ot_rate_set_id wins outright over every assignment match', $resolved[$empExplicitOverride]['set_id'], $set2Id);
    check('resolved rates for the department-only employee carry the real weekday multiplier (3.0)', $resolved[$empDeptOnly]['rates']['weekday']['multiplier_rate'] ?? null, 3.0);

    $single = $model->resolveSetForEmployee($compId, $empDeptOnly, $deptId, null, null);
    check('resolveSetForEmployee() (single-employee convenience wrapper) agrees with the batched resolver', (int)($single['id'] ?? 0), $set3Id);

    echo "=== previewCalculation(): draft (unsaved) item config, same formula real payroll uses ===\n";
    $multiplierPreview = $model->previewCalculation(['calculation_method' => 'multiplier', 'calculation_base' => 'hourly', 'multiplier_rate' => 1.5], 13500.0, 1.5);
    checkTrue('multiplier/hourly preview succeeds' . (empty($multiplierPreview['status']) ? " ({$multiplierPreview['message']})" : ''), $multiplierPreview['status']);
    check('multiplier/hourly: 13500/30/8=56.25/hr * 1.5 * 1.5h = 126.56', $multiplierPreview['amount'], 126.56);

    $flatDailyPreview = $model->previewCalculation(['calculation_method' => 'flat_amount', 'calculation_base' => 'daily', 'flat_amount_rate' => 500], 30000.0, 4.0);
    checkTrue('flat_amount/daily preview succeeds', $flatDailyPreview['status']);
    check('flat_amount/daily: 500.00 * (4h/8h=0.5) = 250.00', $flatDailyPreview['amount'], 250.0);

    check('previewCalculation() rejects flat_amount with no flat_amount_rate', $model->previewCalculation(['calculation_method' => 'flat_amount', 'calculation_base' => 'hourly'])['status'], false);
    check('previewCalculation() rejects a non-positive sample base salary', $model->previewCalculation(['calculation_method' => 'multiplier', 'multiplier_rate' => 1.5], 0.0, 2.0)['status'], false);

    $rowCountBeforePreview = (int)$pdo->query("SELECT COUNT(*) FROM ot_rate_set_items WHERE set_id IN (SELECT id FROM ot_rate_sets WHERE comp_id = {$compId})")->fetchColumn();
    $model->previewCalculation(['calculation_method' => 'multiplier', 'multiplier_rate' => 2.0]);
    check('previewCalculation() never actually writes a row', (int)$pdo->query("SELECT COUNT(*) FROM ot_rate_set_items WHERE set_id IN (SELECT id FROM ot_rate_sets WHERE comp_id = {$compId})")->fetchColumn(), $rowCountBeforePreview);

} catch (Throwable $e) {
    $failures++;
    echo "  FAIL  Uncaught exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
