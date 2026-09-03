<?php
/**
 * Lightweight verification script for Manual Entry Phase 1A ("reduce manual typing" audit, explicit
 * request) -- the 2 real auto-fill/scoping points found on /manual-entry: EmployeeModel::shiftInfo()
 * (Attendance's Shift field) and MasterModel::master()'s 'ot_rate' case's new $employeeId param
 * (Overtime's OT Rate field, scoped to the employee's own resolved OT Rate Set instead of listing
 * every active Set's items company-wide).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back. Uses its own freshly-created company (same makeCompany()
 * pattern as tests/employee_independent_save_test.php/audit_log_test.php) -- zero dev-DB
 * contamination risk, no isolation dance needed.
 *
 * Run with: php tests/manual_entry_autofill_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/EmployeeModel.php';
require_once __DIR__ . '/../app/models/MasterModel.php';
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

function makeCompany(PDO $pdo, string $countryCode): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function makeShift(PDO $pdo, int $compId): int {
    $stmt = $pdo->prepare("INSERT INTO shifts (comp_id, shift_code, shift_name_th, shift_name_en, start_time, end_time) VALUES (:c, :code, 'กะเช้า', 'Morning Shift', '08:00:00', '17:00:00')");
    $stmt->execute([':c' => $compId, ':code' => 'SHIFT' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function makeTeam(PDO $pdo, int $compId): int {
    $stmt = $pdo->prepare("INSERT INTO structure_teams (comp_id, team_code, team_name_th, team_name_en) VALUES (:c, :code, 'ทีมทดสอบ', 'Test Team')");
    $stmt->execute([':c' => $compId, ':code' => 'TEAM' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

try {
    $userId = 1;
    $compId = makeCompany($pdo, 'TH');
    $employeeModel = new EmployeeModel();

    echo "=== EmployeeModel::shiftInfo() (Attendance's Shift auto-fill) ===\n";
    $shiftId = makeShift($pdo, $compId);
    $empNoWithShift = 'MEA-' . uniqid();
    $withShift = $employeeModel->save($compId, [
        'employee_no' => $empNoWithShift, 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'surname_th' => 'มีกะ',
        'name_en' => 'Test', 'surname_en' => 'HasShift', 'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        'shift_id' => $shiftId,
    ], $userId);
    checkTrue('create employee with shift_id' . (empty($withShift['status']) ? " ({$withShift['message']})" : ''), $withShift['status']);
    $empIdWithShift = (int)($withShift['id'] ?? 0);

    $empNoNoShift = 'MEA-' . uniqid();
    $noShift = $employeeModel->save($compId, [
        'employee_no' => $empNoNoShift, 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'surname_th' => 'ไม่มีกะ',
        'name_en' => 'Test', 'surname_en' => 'NoShift', 'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
    ], $userId);
    checkTrue('create employee without shift_id', $noShift['status']);
    $empIdNoShift = (int)($noShift['id'] ?? 0);

    if ($empIdWithShift > 0) {
        $info = $employeeModel->shiftInfo($compId, $empIdWithShift);
        checkTrue('shiftInfo() returns a row for an employee WITH a shift', $info !== null);
        if ($info) {
            check('shiftInfo() returns the correct shift_id', (int)$info['shift_id'], $shiftId);
            check('shiftInfo() returns shift_name_en', $info['shift_name_en'], 'Morning Shift');
        }
    }
    if ($empIdNoShift > 0) {
        check('shiftInfo() returns null for an employee with NO shift', $employeeModel->shiftInfo($compId, $empIdNoShift), null);
    }
    check('shiftInfo() returns null for an unknown employee_id', $employeeModel->shiftInfo($compId, 999999999), null);

    echo "\n=== MasterModel::master() 'ot_rate' case with employee scoping (Overtime's OT Rate filter) ===\n";
    $otSetModel = new OtRateSetModel($pdo);
    $scopes = $otSetModel->otScopeOptions();
    $weekdayId = null;
    foreach ($scopes as $s) { if ($s['code'] === 'weekday') { $weekdayId = (int)$s['id']; } }
    checkTrue('weekday OT scope resolved', $weekdayId !== null);

    // The FIRST set saved for a fresh company automatically becomes the mandatory Default (see
    // OtRateSetModel::save()'s own docblock).
    $defaultSet = $otSetModel->save(['name_th' => 'ชุดมาตรฐาน', 'name_en' => 'Default Set', 'items' => [
        ['ot_scope_id' => $weekdayId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 1.5],
    ]], $compId, $userId);
    checkTrue('create Default OT Rate Set' . (empty($defaultSet['status']) ? " ({$defaultSet['message']})" : ''), $defaultSet['status']);

    $teamId = makeTeam($pdo, $compId);
    $teamSet = $otSetModel->save(['name_th' => 'ชุดทีม', 'name_en' => 'Team Set', 'items' => [
        ['ot_scope_id' => $weekdayId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 2.0],
    ], 'assignments' => [
        ['scope_type' => 'team', 'scope_id' => $teamId],
    ]], $compId, $userId);
    checkTrue('create a second (Team-assigned) OT Rate Set' . (empty($teamSet['status']) ? " ({$teamSet['message']})" : ''), $teamSet['status']);

    // Employee on that team, no explicit assigned_ot_rate_set_id override -- should resolve to the
    // TEAM set, not the company Default, per OtRateSetModel's own documented priority.
    $empNoOt = 'MEA-' . uniqid();
    $otEmp = $employeeModel->save($compId, [
        'employee_no' => $empNoOt, 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'surname_th' => 'ทีม',
        'name_en' => 'Test', 'surname_en' => 'TeamMember', 'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        'team_id' => $teamId,
    ], $userId);
    checkTrue('create the team-member employee for OT scoping test', $otEmp['status']);
    $otEmpId = (int)($otEmp['id'] ?? 0);

    $masterModel = new MasterModel();
    $unfiltered = $masterModel->master(1, 50, 'ot_rate', '', $compId, null);
    check('unfiltered ot_rate list includes items from BOTH sets', count($unfiltered['items'] ?? []), 2);

    if ($otEmpId > 0) {
        $filtered = $masterModel->master(1, 50, 'ot_rate', '', $compId, $otEmpId);
        check('employee-scoped ot_rate list includes only the TEAM set\'s own item', count($filtered['items'] ?? []), 1);
        if (!empty($filtered['items'])) {
            checkTrue('the surviving item is labeled with the Team Set\'s own name', strpos((string)$filtered['items'][0]['text_en'], 'Team Set') !== false);
        }
    }

    // An employee with no team/department/position/explicit override at all still resolves to the
    // mandatory company Default -- confirms the filter degrades correctly rather than returning
    // nothing.
    $empNoDefault = 'MEA-' . uniqid();
    $defaultEmp = $employeeModel->save($compId, [
        'employee_no' => $empNoDefault, 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'surname_th' => 'ไม่มีทีม',
        'name_en' => 'Test', 'surname_en' => 'NoTeam', 'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
    ], $userId);
    checkTrue('create the no-team employee for Default-fallback test', $defaultEmp['status']);
    $defaultEmpId = (int)($defaultEmp['id'] ?? 0);
    if ($defaultEmpId > 0) {
        $fallback = $masterModel->master(1, 50, 'ot_rate', '', $compId, $defaultEmpId);
        check('an employee with no team resolves to the company Default set (1 item)', count($fallback['items'] ?? []), 1);
        if (!empty($fallback['items'])) {
            checkTrue('the surviving item is labeled with the Default Set\'s own name', strpos((string)$fallback['items'][0]['text_en'], 'Default Set') !== false);
        }
    }

    echo "\n=== SUMMARY: {$passes} passed, {$failures} failed ===\n";
} finally {
    $pdo->rollBack();
}

exit($failures > 0 ? 1 : 0);
