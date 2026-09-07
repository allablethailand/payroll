<?php
/**
 * Lightweight verification script for Backlog Phase 10, T055: EntityAssignmentModel, the generic,
 * reusable "assign to Department/Position/Team/Employee" component -- see the model's own docblock
 * and database/migrations/2026-09-04_4_entity_assignments.sql's own header comment for the full
 * architecture/scope-boundary reasoning (no HTTP endpoint yet -- T054/T056 are the first real
 * consumers, not built in this task).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back -- uses its own fresh companies (never touches comp_id=1's
 * live data, see feedback_dev_db_shared_state_test_fragility).
 * Run with: php tests/entity_assignment_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/EntityAssignmentModel.php';

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

function makeDepartment(PDO $pdo, int $compId, string $nameTh): int {
    $stmt = $pdo->prepare("INSERT INTO structure_departments (comp_id, department_code, department_name_th, department_name_en, status) VALUES (:c, :code, :name, :name, 'active')");
    $stmt->execute([':c' => $compId, ':code' => 'DEPT_' . uniqid(), ':name' => $nameTh]);
    return (int)$pdo->lastInsertId();
}
function makePosition(PDO $pdo, int $compId, string $nameTh): int {
    $stmt = $pdo->prepare("INSERT INTO structure_positions (comp_id, position_code, position_name_th, position_name_en, status) VALUES (:c, :code, :name, :name, 'active')");
    $stmt->execute([':c' => $compId, ':code' => 'POS_' . uniqid(), ':name' => $nameTh]);
    return (int)$pdo->lastInsertId();
}
function makeTeam(PDO $pdo, int $compId, string $nameTh): int {
    $stmt = $pdo->prepare("INSERT INTO structure_teams (comp_id, team_code, team_name_th, team_name_en, status) VALUES (:c, :code, :name, :name, 'active')");
    $stmt->execute([':c' => $compId, ':code' => 'TEAM_' . uniqid(), ':name' => $nameTh]);
    return (int)$pdo->lastInsertId();
}
function makeEmployee(PDO $pdo, int $compId, ?int $deptId = null, ?int $posId = null, ?int $teamId = null): int {
    $stmt = $pdo->prepare("INSERT INTO employees (comp_id, employee_no, name_th, surname_th, department_id, position_id, team_id)
        VALUES (:c, :no, 'ทดสอบ', 'พนักงาน', :dept, :pos, :team)");
    $stmt->execute([':c' => $compId, ':no' => 'EMP_' . uniqid(), ':dept' => $deptId, ':pos' => $posId, ':team' => $teamId]);
    return (int)$pdo->lastInsertId();
}

try {
    $compA = makeCompany($pdo);
    $compB = makeCompany($pdo);
    $userId = 1;
    $model = new EntityAssignmentModel($pdo);
    $entityType = 'test_entity';

    $deptA = makeDepartment($pdo, $compA, 'แผนก A');
    $deptA2 = makeDepartment($pdo, $compA, 'แผนก A2');
    $posA = makePosition($pdo, $compA, 'ตำแหน่ง A');
    $teamA = makeTeam($pdo, $compA, 'ทีม A');
    $deptB = makeDepartment($pdo, $compB, 'แผนก B');

    $empInDeptA = makeEmployee($pdo, $compA, $deptA, null, null);
    $empOutsideDeptA = makeEmployee($pdo, $compA, $deptA2, null, null);
    $empInPosA = makeEmployee($pdo, $compA, null, $posA, null);
    $empInTeamA = makeEmployee($pdo, $compA, null, null, $teamA);
    $empDirect = makeEmployee($pdo, $compA, null, null, null);
    $empUnrelated = makeEmployee($pdo, $compA, null, null, null);
    $empBothDeptAndTeam = makeEmployee($pdo, $compA, $deptA, null, $teamA);

    echo "=== assignableOptions() ===\n";
    $opts = $model->assignableOptions($compA);
    checkTrue('4 keys present', isset($opts['departments'], $opts['positions'], $opts['teams'], $opts['employees']));
    check('2 departments visible for compA', count($opts['departments']), 2);
    check('1 position visible for compA', count($opts['positions']), 1);
    check('1 team visible for compA', count($opts['teams']), 1);
    check('7 employees visible for compA', count($opts['employees']), 7);

    echo "=== saveAssignments(): validation ===\n";
    $invalidType = $model->saveAssignments($compA, $entityType, 1, [['scope_type' => 'branch', 'scope_id' => $deptA]], $userId);
    checkFalse('invalid scope_type rejected', $invalidType['status']);

    $crossCompany = $model->saveAssignments($compA, $entityType, 1, [['scope_type' => 'department', 'scope_id' => $deptB]], $userId);
    checkFalse('cross-company scope_id rejected (compB department used from compA)', $crossCompany['status']);

    echo "=== zero rows = unscoped (applies to everyone) ===\n";
    checkTrue('unscoped: empInDeptA matches (nothing assigned yet)', $model->resolveForEmployee($compA, $entityType, 100, $empInDeptA));
    checkTrue('unscoped: empUnrelated matches too', $model->resolveForEmployee($compA, $entityType, 100, $empUnrelated));
    check('unscoped: getAssignments() returns empty array', $model->getAssignments($compA, $entityType, 100), []);

    echo "=== saveAssignments(): whole-set replace semantics ===\n";
    $entityId = 200;
    $save1 = $model->saveAssignments($compA, $entityType, $entityId, [
        ['scope_type' => 'department', 'scope_id' => $deptA],
    ], $userId);
    checkTrue('first save (1 department) succeeds', $save1['status']);
    check('1 assignment row after first save', count($model->getAssignments($compA, $entityType, $entityId)), 1);

    $save2 = $model->saveAssignments($compA, $entityType, $entityId, [
        ['scope_type' => 'team', 'scope_id' => $teamA],
        ['scope_type' => 'employee', 'scope_id' => $empDirect],
    ], $userId);
    checkTrue('second save (team + employee) succeeds', $save2['status']);
    $afterSecond = $model->getAssignments($compA, $entityType, $entityId);
    check('exactly 2 rows after second save (old department row replaced, not additive)', count($afterSecond), 2);
    $scopeTypesAfter = array_column($afterSecond, 'scope_type');
    checkFalse('old department assignment is gone', in_array('department', $scopeTypesAfter, true));
    checkTrue('new team assignment present', in_array('team', $scopeTypesAfter, true));
    checkTrue('new employee assignment present', in_array('employee', $scopeTypesAfter, true));

    echo "=== getAssignments(): resolved labels ===\n";
    $labels = array_column($afterSecond, 'label', 'scope_type');
    check('team label resolved', $labels['team'], 'ทีม A');

    echo "=== resolveForEmployee(): department-scoped ===\n";
    $entityDept = 300;
    $model->saveAssignments($compA, $entityType, $entityDept, [['scope_type' => 'department', 'scope_id' => $deptA]], $userId);
    checkTrue('employee IN the assigned department matches', $model->resolveForEmployee($compA, $entityType, $entityDept, $empInDeptA));
    checkFalse('employee OUTSIDE the assigned department does not match', $model->resolveForEmployee($compA, $entityType, $entityDept, $empOutsideDeptA));

    echo "=== resolveForEmployee(): position-scoped ===\n";
    $entityPos = 301;
    $model->saveAssignments($compA, $entityType, $entityPos, [['scope_type' => 'position', 'scope_id' => $posA]], $userId);
    checkTrue('employee with the assigned position matches', $model->resolveForEmployee($compA, $entityType, $entityPos, $empInPosA));
    checkFalse('employee without that position does not match', $model->resolveForEmployee($compA, $entityType, $entityPos, $empUnrelated));

    echo "=== resolveForEmployee(): team-scoped ===\n";
    $entityTeam = 302;
    $model->saveAssignments($compA, $entityType, $entityTeam, [['scope_type' => 'team', 'scope_id' => $teamA]], $userId);
    checkTrue('employee IN the assigned team matches', $model->resolveForEmployee($compA, $entityType, $entityTeam, $empInTeamA));
    checkFalse('employee not in that team does not match', $model->resolveForEmployee($compA, $entityType, $entityTeam, $empUnrelated));

    echo "=== resolveForEmployee(): employee-direct ===\n";
    $entityDirect = 303;
    $model->saveAssignments($compA, $entityType, $entityDirect, [['scope_type' => 'employee', 'scope_id' => $empDirect]], $userId);
    checkTrue('the directly-assigned employee matches', $model->resolveForEmployee($compA, $entityType, $entityDirect, $empDirect));
    checkFalse('a different employee does not match', $model->resolveForEmployee($compA, $entityType, $entityDirect, $empUnrelated));

    echo "=== resolveForEmployee(): multi-scope UNION match (department AND team, either satisfies) ===\n";
    $entityMulti = 304;
    $model->saveAssignments($compA, $entityType, $entityMulti, [
        ['scope_type' => 'department', 'scope_id' => $deptA],
        ['scope_type' => 'team', 'scope_id' => $teamA],
    ], $userId);
    checkTrue('employee matching ONLY department (not team) still matches (union, not intersection)', $model->resolveForEmployee($compA, $entityType, $entityMulti, $empInDeptA));
    checkTrue('employee matching ONLY team (not department) still matches', $model->resolveForEmployee($compA, $entityType, $entityMulti, $empInTeamA));
    checkTrue('employee matching BOTH department and team matches', $model->resolveForEmployee($compA, $entityType, $entityMulti, $empBothDeptAndTeam));
    checkFalse('employee matching NEITHER does not match', $model->resolveForEmployee($compA, $entityType, $entityMulti, $empUnrelated));

    echo "=== deleteAllForEntity() ===\n";
    check('entityMulti has 2 rows before delete', count($model->getAssignments($compA, $entityType, $entityMulti)), 2);
    $model->deleteAllForEntity($compA, $entityType, $entityMulti);
    check('entityMulti has 0 rows after deleteAllForEntity()', count($model->getAssignments($compA, $entityType, $entityMulti)), 0);
    checkTrue('deleted entity is now unscoped (applies to everyone again)', $model->resolveForEmployee($compA, $entityType, $entityMulti, $empUnrelated));

    echo "=== entity_type isolation: same entity_id, different entity_type, independent rows ===\n";
    $model->saveAssignments($compA, 'other_entity_type', $entityDept, [['scope_type' => 'employee', 'scope_id' => $empUnrelated]], $userId);
    check('original entity_type (department, id 300) untouched by a different entity_type using the same id', count($model->getAssignments($compA, $entityType, $entityDept)), 1);
    check('the other entity_type has its own independent row', count($model->getAssignments($compA, 'other_entity_type', $entityDept)), 1);

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
