<?php
/**
 * Lightweight verification script for Platform Hardening Phase 6 (pilot): AuditLogModel's
 * field-level diff/insert logic directly, plus integration coverage through each of the 5 pilot
 * models' own write methods (EmployeeModel::save(), CompanyProfileModel::save(),
 * PayrollEarningDeductionTypeModel::save()/delete()/toggleStatus(), PayrollPolicyModel::save(),
 * AttendanceDeductionRuleModel::ruleSave()/ruleDelete()).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back. Every fixture uses its OWN freshly-created company (same
 * makeCompany()/makeStructure() pattern as tests/employee_independent_save_test.php) rather than the
 * real comp_id=1 -- zero dev-DB contamination risk, no isolation dance needed.
 *
 * Run with: php tests/audit_log_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/AuditLogModel.php';
require_once __DIR__ . '/../app/models/EmployeeModel.php';
require_once __DIR__ . '/../app/models/CompanyProfileModel.php';
require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';
require_once __DIR__ . '/../app/models/PayrollPolicyModel.php';
require_once __DIR__ . '/../app/models/AttendanceDeductionRuleModel.php';

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

function makeCompany(PDO $pdo, string $countryCode): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

/** Standard Thai national ID mod-11 checksum -- returns a real, valid 13-digit ID for any 12-digit base. */
function thaiIdWithChecksum(string $base12): string {
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int)$base12[$i] * (13 - $i);
    }
    $checkDigit = (11 - ($sum % 11)) % 10;
    return $base12 . (string)$checkDigit;
}

function makeDepartment(PDO $pdo, int $compId): int {
    $stmt = $pdo->prepare("INSERT INTO structure_departments (comp_id, department_code, department_name_th, department_name_en) VALUES (:c, :code, 'แผนกทดสอบ', 'Test Dept')");
    $stmt->execute([':c' => $compId, ':code' => 'DEPT' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

/** @return array rows from audit_logs for one table+record, oldest-first (reversed from the model's own DESC list()). */
function auditRowsFor(PDO $pdo, int $compId, string $tableName, int $recordId): array {
    $stmt = $pdo->prepare("SELECT * FROM audit_logs WHERE comp_id = :comp_id AND table_name = :table_name AND record_id = :record_id ORDER BY id ASC");
    $stmt->execute([':comp_id' => $compId, ':table_name' => $tableName, ':record_id' => $recordId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    // Some pilot models' write tables (company_payroll_policies, attendance_deduction_rules) have a
    // real FK from updated_by/created_by -> employees.id -- id=1 is not guaranteed to exist in every
    // dev DB (confirmed it doesn't in this one), so a real existing employee id is looked up
    // dynamically rather than hardcoded, same "don't assume specific dev-DB row ids" caution as
    // feedback_dev_db_shared_state_test_fragility documents.
    $userId = (int)$pdo->query("SELECT id FROM employees ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($userId <= 0) {
        throw new RuntimeException('No employee row exists in this dev DB to use as a valid created_by/updated_by FK value.');
    }
    $compId = makeCompany($pdo, 'TH');
    $auditLog = new AuditLogModel($pdo);

    // ================= AuditLogModel::record() direct unit tests =================
    echo "=== AuditLogModel::record() -- create/update/delete/diff ===\n";

    // ---------- create ----------
    $auditLog->record($compId, 'unit_test_table', 9001, 'create', null, ['name' => 'A', 'amount' => 100], $userId, 'web', '127.0.0.1', 'TestAgent/1.0');
    $createRows = auditRowsFor($pdo, $compId, 'unit_test_table', 9001);
    check('create: exactly 1 row', count($createRows), 1);
    if (count($createRows) === 1) {
        check('create: field_name is NULL', $createRows[0]['field_name'], null);
        check('create: old_value is NULL', $createRows[0]['old_value'], null);
        checkTrue('create: new_value contains the encoded row', strpos((string)$createRows[0]['new_value'], '"name":"A"') !== false);
        check('create: ip_address captured', $createRows[0]['ip_address'], '127.0.0.1');
        check('create: source captured', $createRows[0]['source'], 'web');
    }

    // ---------- update: only genuinely-changed fields produce a row ----------
    $auditLog->record($compId, 'unit_test_table', 9002, 'update',
        ['name' => 'Old Name', 'amount' => 100, 'unchanged' => 'same', 'updated_at' => '2026-01-01 00:00:00'],
        ['name' => 'New Name', 'amount' => 100, 'unchanged' => 'same', 'updated_at' => '2026-09-03 00:00:00'],
        $userId, 'web');
    $updateRows = auditRowsFor($pdo, $compId, 'unit_test_table', 9002);
    check('update: exactly 1 row (only "name" changed, "amount"/"unchanged" identical, "updated_at" denylisted)', count($updateRows), 1);
    if (count($updateRows) === 1) {
        check('update: field_name is the changed column', $updateRows[0]['field_name'], 'name');
        check('update: old_value is the old value', $updateRows[0]['old_value'], 'Old Name');
        check('update: new_value is the new value', $updateRows[0]['new_value'], 'New Name');
    }

    // ---------- update: multiple changed fields produce one row each ----------
    $auditLog->record($compId, 'unit_test_table', 9003, 'update',
        ['a' => '1', 'b' => '2', 'c' => '3'], ['a' => '10', 'b' => '2', 'c' => '30'], $userId, 'web');
    check('update: 2 rows for 2 changed fields (a, c), not the unchanged one (b)', count(auditRowsFor($pdo, $compId, 'unit_test_table', 9003)), 2);

    // ---------- update: no-op writes zero rows ----------
    $auditLog->record($compId, 'unit_test_table', 9004, 'update', ['x' => 'same'], ['x' => 'same'], $userId, 'web');
    check('update: identical old/new writes 0 rows', count(auditRowsFor($pdo, $compId, 'unit_test_table', 9004)), 0);

    // ---------- update: NULL -> '' IS treated as a real change (not silently swallowed by the
    // (string)-cast comparison) -- the extra "($oldValue === null) === ($newValue === null)" guard
    // in recordUpdate() exists specifically so a genuine null-to-empty-string transition isn't
    // mistaken for a no-op just because both stringify to ''. ----------
    $auditLog->record($compId, 'unit_test_table', 9005, 'update', ['v' => null], ['v' => ''], $userId, 'web');
    $nullRows = auditRowsFor($pdo, $compId, 'unit_test_table', 9005);
    check('update: NULL -> "" is logged as a real change', count($nullRows), 1);
    if (count($nullRows) === 1) {
        check('update: NULL -> "" old_value is NULL', $nullRows[0]['old_value'], null);
        check('update: NULL -> "" new_value is empty string', $nullRows[0]['new_value'], '');
    }

    // ---------- update: custom $excludeFields param (per-caller, on top of the shared denylist) ----------
    $auditLog->record($compId, 'unit_test_table', 9006, 'update',
        ['secret' => 'cipher1', 'plain' => 'A'], ['secret' => 'cipher2', 'plain' => 'B'],
        $userId, 'web', null, null, ['secret']);
    $excludeRows = auditRowsFor($pdo, $compId, 'unit_test_table', 9006);
    check('excludeFields: only "plain" logged, "secret" excluded despite changing', count($excludeRows), 1);
    if (count($excludeRows) === 1) {
        check('excludeFields: the surviving row is the non-excluded field', $excludeRows[0]['field_name'], 'plain');
    }

    // ---------- delete ----------
    $auditLog->record($compId, 'unit_test_table', 9007, 'delete', ['name' => 'Gone'], null, $userId, 'web');
    $deleteRows = auditRowsFor($pdo, $compId, 'unit_test_table', 9007);
    check('delete: exactly 1 row', count($deleteRows), 1);
    if (count($deleteRows) === 1) {
        check('delete: field_name is NULL', $deleteRows[0]['field_name'], null);
        check('delete: new_value is NULL', $deleteRows[0]['new_value'], null);
        checkTrue('delete: old_value contains the encoded row', strpos((string)$deleteRows[0]['old_value'], '"name":"Gone"') !== false);
    }

    // ---------- an invalid action is silently ignored, never throws ----------
    $auditLog->record($compId, 'unit_test_table', 9008, 'bogus_action', ['a' => 1], ['a' => 2], $userId);
    check('invalid action: writes 0 rows and does not throw', count(auditRowsFor($pdo, $compId, 'unit_test_table', 9008)), 0);

    // ---------- list() filters ----------
    echo "\n=== AuditLogModel::list() filters ===\n";
    $listAll = $auditLog->list($compId, []);
    // 9001(1) + 9002(1) + 9003(2) + 9004(0) + 9005(1) + 9006(1) + 9007(1) + 9008(0) = 7 rows so far
    // (the integration sections below add more, but haven't run yet at this point in the script).
    check('list(): recordsTotal covers everything written above', $listAll['recordsTotal'], 7);
    $listByTable = $auditLog->list($compId, ['table_name' => 'unit_test_table', 'record_id' => 9003]);
    check('list(): table_name+record_id filter narrows to exactly the 2 rows from that record', $listByTable['recordsTotal'], 2);

    // ================= Integration: EmployeeModel::save() =================
    echo "\n=== Integration: EmployeeModel::save() ===\n";
    $employeeModel = new EmployeeModel();
    $empNo = 'AUDIT-EMP-' . uniqid();
    $createResult = $employeeModel->save($compId, [
        'employee_no' => $empNo, 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'surname_th' => 'เดิม',
        'name_en' => 'Test', 'surname_en' => 'Old', 'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        'id_card_no' => thaiIdWithChecksum('123456789012'), 'mobile_no' => '0800000001',
    ], $userId);
    checkTrue('create employee for fixture' . (empty($createResult['status']) ? " ({$createResult['message']})" : ''), $createResult['status']);
    $employeeId = (int)($createResult['id'] ?? 0);
    if ($employeeId > 0) {
        check('create: EmployeeModel::save() does NOT log an audit row for the CREATE branch (only update is wired)', count(auditRowsFor($pdo, $compId, 'employees', $employeeId)), 0);
        [$ip, $ua] = ['10.0.0.5', 'AuditTestAgent/1.0'];
        $updateResult = $employeeModel->save($compId, [
            'id' => $employeeId, 'employee_no' => $empNo, 'employee_type' => 'domestic', 'employee_status' => 'active',
            'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'surname_th' => 'ใหม่',
            'name_en' => 'Test', 'surname_en' => 'New', 'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
            'id_card_no' => thaiIdWithChecksum('987654321098'), 'mobile_no' => '0800000002',
        ], $userId, $ip, $ua);
        checkTrue('update employee' . (empty($updateResult['status']) ? " ({$updateResult['message']})" : ''), $updateResult['status']);
        $empAuditRows = auditRowsFor($pdo, $compId, 'employees', $employeeId);
        $empFields = array_column($empAuditRows, 'field_name');
        checkTrue('update: surname_th change is logged', in_array('surname_th', $empFields, true));
        checkTrue('update: mobile_no change is logged', in_array('mobile_no', $empFields, true));
        checkFalse('update: encrypted id_card_no change is NOT logged (excludeFields)', in_array('id_card_no', $empFields, true));
        checkFalse('update: id_card_no_hash is NOT logged either', in_array('id_card_no_hash', $empFields, true));
        $surnameRow = current(array_filter($empAuditRows, fn($r) => $r['field_name'] === 'surname_th'));
        if ($surnameRow) {
            check('update: surname_th old_value correct', $surnameRow['old_value'], 'เดิม');
            check('update: surname_th new_value correct', $surnameRow['new_value'], 'ใหม่');
            check('update: ip_address captured on the real controller-fingerprint param', $surnameRow['ip_address'], '10.0.0.5');
        }
    }

    // ================= Integration: CompanyProfileModel::save() =================
    echo "\n=== Integration: CompanyProfileModel::save() ===\n";
    $_SESSION['user']['company_id'] = $compId;
    $_SESSION['user']['employee_id'] = $userId;
    $companyProfileModel = new CompanyProfileModel();
    $cpResult1 = $companyProfileModel->save([
        'registered_country' => 'TH', 'company_legal_name' => 'Test Co', 'global_tax_id' => 'TAX123',
        'address_line_1' => 'Addr 1', 'authorized_signatory_name' => 'Sig', 'local_name' => 'Local A',
    ]);
    checkTrue('first CompanyProfileModel::save() succeeds', (bool)$cpResult1);
    $cpResult2 = $companyProfileModel->save([
        'registered_country' => 'TH', 'company_legal_name' => 'Test Co', 'global_tax_id' => 'TAX123',
        'address_line_1' => 'Addr 1', 'authorized_signatory_name' => 'Sig', 'local_name' => 'Local B',
    ], '10.0.0.6', 'AuditTestAgent/1.0');
    checkTrue('second CompanyProfileModel::save() succeeds', (bool)$cpResult2);
    // 2 save() calls above produce 2 diff rows for local_name (makeCompany()'s own original name ->
    // "Local A" from the first call, then "Local A" -> "Local B" from the second) -- take the SECOND
    // one specifically, since that's the one this assertion actually cares about.
    $companyAuditRows = auditRowsFor($pdo, $compId, 'companies', $compId);
    $localNameRows = array_values(array_filter($companyAuditRows, fn($r) => $r['field_name'] === 'local_name'));
    check('companies: exactly 2 local_name diff rows (one per save() call)', count($localNameRows), 2);
    if (count($localNameRows) === 2) {
        check('companies: local_name old_value correct (2nd diff)', $localNameRows[1]['old_value'], 'Local A');
        check('companies: local_name new_value correct (2nd diff)', $localNameRows[1]['new_value'], 'Local B');
    }

    // ================= Integration: PayrollEarningDeductionTypeModel =================
    echo "\n=== Integration: PayrollEarningDeductionTypeModel::save()/delete()/toggleStatus() ===\n";
    $pedModel = new PayrollEarningDeductionTypeModel();
    $pedCode = 'AUD' . substr(uniqid(), -6);
    $pedCreate = $pedModel->save($compId, [
        'item_code' => $pedCode, 'item_name_th' => 'ทดสอบ', 'item_name_en' => 'Test Item',
        'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 100, 'tax_treatment' => 'taxable',
    ], $userId);
    checkTrue('create PED type' . (empty($pedCreate['status']) ? " ({$pedCreate['message']})" : ''), $pedCreate['status']);
    $pedId = (int)($pedCreate['id'] ?? 0);
    if ($pedId > 0) {
        check('PED create branch is not audited (only update/delete/toggleStatus are wired)', count(auditRowsFor($pdo, $compId, 'payroll_earning_deduction_types', $pedId)), 0);
        $pedUpdate = $pedModel->save($compId, [
            'id' => $pedId, 'item_code' => $pedCode, 'item_name_th' => 'ทดสอบ', 'item_name_en' => 'Test Item Renamed',
            'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 200, 'tax_treatment' => 'taxable',
        ], $userId, '10.0.0.7');
        checkTrue('update PED type', $pedUpdate['status']);
        $pedRows = auditRowsFor($pdo, $compId, 'payroll_earning_deduction_types', $pedId);
        $pedFields = array_column($pedRows, 'field_name');
        checkTrue('PED update: item_name_en change logged', in_array('item_name_en', $pedFields, true));
        checkTrue('PED update: fixed_amount change logged', in_array('fixed_amount', $pedFields, true));

        $pedToggle = $pedModel->toggleStatus($compId, $pedId, $userId);
        checkTrue('toggle PED status', $pedToggle['status']);
        $toggleRow = current(array_filter(auditRowsFor($pdo, $compId, 'payroll_earning_deduction_types', $pedId), fn($r) => $r['field_name'] === 'status'));
        checkTrue('PED toggleStatus: status change is logged', $toggleRow !== false);
        if ($toggleRow) {
            check('PED toggleStatus: old status', $toggleRow['old_value'], 'active');
            check('PED toggleStatus: new status', $toggleRow['new_value'], $pedToggle['new_status']);
        }

        $pedDelete = $pedModel->delete($compId, $pedId, $userId);
        checkTrue('delete PED type', $pedDelete['status']);
        $deleteStatusRows = array_filter(auditRowsFor($pdo, $compId, 'payroll_earning_deduction_types', $pedId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === 'deleted');
        checkTrue('PED delete: logged as a status->deleted update row', count($deleteStatusRows) > 0);
    }

    // ================= Integration: PayrollPolicyModel::save() =================
    echo "\n=== Integration: PayrollPolicyModel::save() ===\n";
    $policyModel = new PayrollPolicyModel($pdo);
    $policyModel->save($compId, ['reopen_window_days' => 5], $userId);
    $policyModel->save($compId, ['reopen_window_days' => 10], $userId, '10.0.0.8');
    $policyRow = $pdo->query("SELECT id FROM company_payroll_policies WHERE comp_id = {$compId}")->fetchColumn();
    if ($policyRow) {
        $policyAuditRows = auditRowsFor($pdo, $compId, 'company_payroll_policies', (int)$policyRow);
        $reopenRow = current(array_filter($policyAuditRows, fn($r) => $r['field_name'] === 'reopen_window_days'));
        checkTrue('PayrollPolicy: reopen_window_days change logged on the second save (update, not create)', $reopenRow !== false);
        if ($reopenRow) {
            check('PayrollPolicy: old value', $reopenRow['old_value'], '5');
            check('PayrollPolicy: new value', $reopenRow['new_value'], '10');
        }
    } else {
        check('PayrollPolicy: fixture row exists', 'missing', 'present');
    }

    // ================= Integration: AttendanceDeductionRuleModel =================
    echo "\n=== Integration: AttendanceDeductionRuleModel::ruleSave()/ruleDelete() ===\n";
    $deptId = makeDepartment($pdo, $compId);
    $ruleModel = new AttendanceDeductionRuleModel($pdo);
    $ruleCreate = $ruleModel->ruleSave([
        'event_code' => 'late', 'scope_type' => 'department', 'scope_id' => $deptId,
        'method_code' => 'flat_amount', 'rate_unit' => 'minute', 'rate_per_unit' => 5,
    ], $compId, $userId);
    checkTrue('create scoped attendance deduction rule' . (empty($ruleCreate['status']) ? " ({$ruleCreate['message']})" : ''), $ruleCreate['status']);
    $ruleId = (int)($ruleCreate['id'] ?? 0);
    if ($ruleId > 0) {
        check('rule create branch is not audited (only update/delete are wired)', count(auditRowsFor($pdo, $compId, 'attendance_deduction_rules', $ruleId)), 0);
        $ruleUpdate = $ruleModel->ruleSave([
            'id' => $ruleId, 'event_code' => 'late', 'scope_type' => 'department', 'scope_id' => $deptId,
            'method_code' => 'flat_amount', 'rate_unit' => 'minute', 'rate_per_unit' => 15,
        ], $compId, $userId, '10.0.0.9');
        checkTrue('update attendance deduction rule', $ruleUpdate['status']);
        $ruleFields = array_column(auditRowsFor($pdo, $compId, 'attendance_deduction_rules', $ruleId), 'field_name');
        checkTrue('rule update: rate_per_unit change logged', in_array('rate_per_unit', $ruleFields, true));

        $ruleDelete = $ruleModel->ruleDelete($ruleId, $compId, $userId, '10.0.0.10');
        checkTrue('delete attendance deduction rule', $ruleDelete['status']);
        $ruleDeleteRows = array_filter(auditRowsFor($pdo, $compId, 'attendance_deduction_rules', $ruleId), fn($r) => $r['action'] === 'delete');
        checkTrue('rule delete: logged as a real delete action row', count($ruleDeleteRows) > 0);
    }

    echo "\n=== SUMMARY: {$passes} passed, {$failures} failed ===\n";
} finally {
    $pdo->rollBack();
}

exit($failures > 0 ? 1 : 0);
