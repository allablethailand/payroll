<?php
/**
 * Lightweight verification script for Platform Hardening Phase 6, batch 3: extends AuditLogModel
 * wiring to 3 more employee-compensation models -- EmployeeRecurringEarningModel,
 * EmployeeRecurringDeductionModel, EmployeeEarningDeductionModel -- per explicit user scoping
 * ("Employee compensation models") after PayrollRunModel itself was deliberately skipped (it already
 * has its own dedicated payroll_run_audit_logs trail covering every state transition, see that
 * model's own docblock -- adding the generic audit_logs table there would be redundant).
 * Same update/delete/toggle-only convention as batches 1/2 (no CREATE logging).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back. Every fixture uses its OWN freshly-created company (same
 * makeCompany() pattern as tests/audit_log_test.php/audit_log_batch2_test.php) -- zero dev-DB
 * contamination risk.
 *
 * Run with: php tests/audit_log_batch3_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/AuditLogModel.php';
require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';
require_once __DIR__ . '/../app/models/EmployeeRecurringEarningModel.php';
require_once __DIR__ . '/../app/models/EmployeeRecurringDeductionModel.php';
require_once __DIR__ . '/../app/models/EmployeeEarningDeductionModel.php';

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

/** @return array rows from audit_logs for one table+record, oldest-first. */
function auditRowsFor(PDO $pdo, int $compId, string $tableName, int $recordId): array {
    $stmt = $pdo->prepare("SELECT * FROM audit_logs WHERE comp_id = :comp_id AND table_name = :table_name AND record_id = :record_id ORDER BY id ASC");
    $stmt->execute([':comp_id' => $compId, ':table_name' => $tableName, ':record_id' => $recordId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $userId = (int)$pdo->query("SELECT id FROM employees ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($userId <= 0) {
        throw new RuntimeException('No employee row exists in this dev DB to use as a valid created_by/updated_by FK value.');
    }
    $compId = makeCompany($pdo, 'TH');
    $employeeId = makeEmployee($pdo, $compId, 'AUDBATCH3-EMP-' . uniqid());

    $pedTypeModel = new PayrollEarningDeductionTypeModel();

    // ================= Integration: EmployeeRecurringEarningModel::save()/delete() =================
    echo "=== Integration: EmployeeRecurringEarningModel ===\n";
    $earnTypeRes = $pedTypeModel->save($compId, [
        'item_code' => 'AUDRE' . substr(uniqid(), -6), 'item_name_th' => 'ค่าตำแหน่ง', 'item_name_en' => 'Position Allowance',
        'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 1000, 'tax_treatment' => 'taxable', 'status' => 'active',
    ], $userId);
    checkTrue('fixture: earning PED type created' . (empty($earnTypeRes['status']) ? " ({$earnTypeRes['message']})" : ''), $earnTypeRes['status']);
    $earnTypeId = (int)($earnTypeRes['id'] ?? 0);

    $recurringEarningModel = new EmployeeRecurringEarningModel($pdo);
    $reCreate = $recurringEarningModel->save($employeeId, $compId, [
        'ped_type_id' => $earnTypeId, 'amount' => 1000, 'effective_date' => '2026-01-01',
    ], $userId);
    checkTrue('create recurring earning' . (empty($reCreate['status']) ? " ({$reCreate['message']})" : ''), $reCreate['status']);
    $reId = (int)($reCreate['id'] ?? 0);
    if ($reId > 0) {
        check('RecurringEarning create branch is not audited', count(auditRowsFor($pdo, $compId, 'employee_recurring_earnings', $reId)), 0);

        $reUpdate = $recurringEarningModel->save($employeeId, $compId, [
            'id' => $reId, 'ped_type_id' => $earnTypeId, 'amount' => 1500, 'effective_date' => '2026-01-01',
        ], $userId, '10.2.0.1');
        checkTrue('update recurring earning' . (empty($reUpdate['status']) ? " ({$reUpdate['message']})" : ''), $reUpdate['status']);
        $reFields = array_column(auditRowsFor($pdo, $compId, 'employee_recurring_earnings', $reId), 'field_name');
        checkTrue('RecurringEarning update: amount change logged', in_array('amount', $reFields, true));

        $reDelete = $recurringEarningModel->delete($reId, $compId, $employeeId, $userId, '10.2.0.2');
        checkTrue('delete recurring earning', $reDelete['status']);
        $reDeleteRows = array_filter(auditRowsFor($pdo, $compId, 'employee_recurring_earnings', $reId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === 'deleted');
        checkTrue('RecurringEarning delete: logged as a status->deleted update row', count($reDeleteRows) > 0);
    }

    // ================= Integration: EmployeeRecurringDeductionModel::save()/delete() =================
    echo "\n=== Integration: EmployeeRecurringDeductionModel ===\n";
    $dedTypeRes = $pedTypeModel->save($compId, [
        'item_code' => 'AUDRD' . substr(uniqid(), -6), 'item_name_th' => 'ค่าเครื่องแบบ', 'item_name_en' => 'Uniform Fee',
        'item_type' => 'deduction', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 200,
        'tax_treatment' => 'non_taxable', 'tax_deduction_impact' => 'before_tax', 'status' => 'active',
    ], $userId);
    checkTrue('fixture: deduction PED type created' . (empty($dedTypeRes['status']) ? " ({$dedTypeRes['message']})" : ''), $dedTypeRes['status']);
    $dedTypeId = (int)($dedTypeRes['id'] ?? 0);

    $recurringDeductionModel = new EmployeeRecurringDeductionModel($pdo);
    $rdCreate = $recurringDeductionModel->save($employeeId, $compId, [
        'ped_type_id' => $dedTypeId, 'amount' => 200, 'effective_date' => '2026-01-01',
    ], $userId);
    checkTrue('create recurring deduction' . (empty($rdCreate['status']) ? " ({$rdCreate['message']})" : ''), $rdCreate['status']);
    $rdId = (int)($rdCreate['id'] ?? 0);
    if ($rdId > 0) {
        check('RecurringDeduction create branch is not audited', count(auditRowsFor($pdo, $compId, 'employee_recurring_deductions', $rdId)), 0);

        $rdUpdate = $recurringDeductionModel->save($employeeId, $compId, [
            'id' => $rdId, 'ped_type_id' => $dedTypeId, 'amount' => 250, 'effective_date' => '2026-01-01',
        ], $userId, '10.2.0.3');
        checkTrue('update recurring deduction' . (empty($rdUpdate['status']) ? " ({$rdUpdate['message']})" : ''), $rdUpdate['status']);
        $rdFields = array_column(auditRowsFor($pdo, $compId, 'employee_recurring_deductions', $rdId), 'field_name');
        checkTrue('RecurringDeduction update: amount change logged', in_array('amount', $rdFields, true));

        $rdDelete = $recurringDeductionModel->delete($rdId, $compId, $employeeId, $userId, '10.2.0.4');
        checkTrue('delete recurring deduction', $rdDelete['status']);
        $rdDeleteRows = array_filter(auditRowsFor($pdo, $compId, 'employee_recurring_deductions', $rdId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === 'deleted');
        checkTrue('RecurringDeduction delete: logged as a status->deleted update row', count($rdDeleteRows) > 0);
    }

    // ================= Integration: EmployeeEarningDeductionModel::save()/updateStatus()/delete() =================
    echo "\n=== Integration: EmployeeEarningDeductionModel ===\n";
    $eedModel = new EmployeeEarningDeductionModel();
    $eedCreate = $eedModel->save($employeeId, $compId, [
        'custom_item_name' => 'เงินกู้ทดสอบ', 'custom_item_type' => 'deduction',
        'total_installments' => 3, 'amount_mode' => 'even_split', 'total_amount' => 3000, 'effective_date' => '2026-01-01',
    ], $userId);
    checkTrue('create earning-deduction assignment' . (empty($eedCreate['status']) ? " ({$eedCreate['message']})" : ''), $eedCreate['status']);
    $eedId = (int)($eedCreate['id'] ?? 0);
    if ($eedId > 0) {
        check('EarningDeduction create branch is not audited', count(auditRowsFor($pdo, $compId, 'employee_earning_deductions', $eedId)), 0);

        $eedUpdate = $eedModel->save($employeeId, $compId, [
            'id' => $eedId, 'custom_item_name' => 'เงินกู้ทดสอบ (แก้ไข)', 'custom_item_type' => 'deduction',
            'total_installments' => 3, 'amount_mode' => 'even_split', 'total_amount' => 3000, 'effective_date' => '2026-01-01',
        ], $userId, '10.2.0.5');
        checkTrue('update earning-deduction assignment' . (empty($eedUpdate['status']) ? " ({$eedUpdate['message']})" : ''), $eedUpdate['status']);
        $eedFields = array_column(auditRowsFor($pdo, $compId, 'employee_earning_deductions', $eedId), 'field_name');
        checkTrue('EarningDeduction update: custom_item_name change logged', in_array('custom_item_name', $eedFields, true));

        $eedToggle = $eedModel->updateStatus($eedId, $compId, $employeeId, 'paused', $userId, '10.2.0.6');
        checkTrue('pause earning-deduction assignment' . (empty($eedToggle['status']) ? " ({$eedToggle['message']})" : ''), $eedToggle['status']);
        $eedToggleRow = current(array_filter(auditRowsFor($pdo, $compId, 'employee_earning_deductions', $eedId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === 'paused'));
        checkTrue('EarningDeduction updateStatus: status change logged', $eedToggleRow !== false);

        $eedDelete = $eedModel->delete($eedId, $compId, $employeeId, $userId, '10.2.0.7');
        checkTrue('delete earning-deduction assignment', $eedDelete['status']);
        $eedDeleteRows = array_filter(auditRowsFor($pdo, $compId, 'employee_earning_deductions', $eedId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === 'deleted');
        checkTrue('EarningDeduction delete: logged as a status->deleted update row', count($eedDeleteRows) > 0);
    }

    echo "\n=== SUMMARY: {$passes} passed, {$failures} failed ===\n";
} finally {
    $pdo->rollBack();
}

exit($failures > 0 ? 1 : 0);
