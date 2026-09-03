<?php
/**
 * Lightweight verification script for EmployeeModel::save()'s country-conditional validation
 * (Phase A / audit finding: Thai-ID checksum, TH-only address master-table FK, and TH-only
 * tax_calculation_method were being forced on non-TH companies, making the Employee module
 * unusable for SG/MY/US companies). Runs against the real dev DB inside a transaction that is
 * always rolled back.
 * Run with: php tests/employee_country_validation_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/EmployeeModel.php';

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

// 2026-09-02, follow-up: payment_type (legacy enum) dropped -- EmployeeModel::save() payloads now
// use payment_method_id, resolved here via master_payment_methods.code.
function resolvePaymentMethodId(PDO $pdo, string $code): int {
    $stmt = $pdo->prepare("SELECT id FROM `master_payment_methods` WHERE code = :code");
    $stmt->execute([':code' => $code]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        throw new RuntimeException("master_payment_methods code '{$code}' not found -- seed missing?");
    }
    return (int)$id;
}

function makeCompany(PDO $pdo, string $countryCode): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function makeStructure(PDO $pdo, int $compId): array {
    $ids = [];
    $stmt = $pdo->prepare("INSERT INTO structure_departments (comp_id, department_code, department_name_th, department_name_en) VALUES (:c, :code, 'แผนกทดสอบ', 'Test Dept')");
    $stmt->execute([':c' => $compId, ':code' => 'DEPT' . uniqid()]);
    $ids['department_id'] = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO structure_roles (comp_id, role_name_th, role_name_en) VALUES (:c, 'ตำแหน่งทดสอบ', 'Test Role')");
    $stmt->execute([':c' => $compId]);
    $ids['role_id'] = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO structure_positions (comp_id, position_code, position_name_th, position_name_en) VALUES (:c, :code, 'ตำแหน่งทดสอบ', 'Test Position')");
    $stmt->execute([':c' => $compId, ':code' => 'POS' . uniqid()]);
    $ids['position_id'] = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO structure_branches (comp_id, branch_code, branch_name_th, branch_name_en) VALUES (:c, :code, 'สาขาทดสอบ', 'Test Branch')");
    $stmt->execute([':c' => $compId, ':code' => 'BR' . uniqid()]);
    $ids['branch_id'] = (int)$pdo->lastInsertId();

    return $ids;
}

function baseEmployeePayload(array $structureIds, string $employeeNo, PDO $pdo): array {
    return array_merge($structureIds, [
        'employee_no' => $employeeNo,
        'employee_type' => 'domestic',
        'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'surname_th' => 'นามสกุล',
        'name_en' => 'Test', 'surname_en' => 'Surname',
        'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        'personal_email' => 'test' . uniqid() . '@example.com',
        'address_line_1_register' => '123 Test Rd', 'address_line_1_contact' => '123 Test Rd',
        'emergency_name' => 'Emergency', 'emergency_surname' => 'Contact', 'emergency_relationship' => 'parent',
        'employment_date' => '2024-01-01', 'employment_status' => 'permanent', 'employment_type' => 'full_time',
        'workforce_type' => 'employee', 'record_time_method' => 'manual', 'payment_method_id' => resolvePaymentMethodId($pdo, 'cash'),
        'salary_type' => 'monthly', 'base_salary_amount' => 30000, 'salary_effective_date' => '2024-01-01',
    ]);
}

try {
    $userId = 1;
    $model = new EmployeeModel();

    // ---------- SG company: non-TH-specific requirements must NOT block save ----------
    $sgCompId = makeCompany($pdo, 'SG');
    $sgStructure = makeStructure($pdo, $sgCompId);
    $sgPayload = baseEmployeePayload($sgStructure, 'SG-EMP-' . uniqid(), $pdo);
    $sgPayload['id_card_no'] = 'S1234567A'; // real Singapore NRIC shape -- would fail a Thai mod-11 checksum
    $sgPayload['mobile_no'] = '91234567'; // 8 digits -- would fail the old TH-only 9-10 digit regex
    $sgPayload['emergency_mobile'] = '98765432';
    // Deliberately NOT setting master_address_id_register/_contact or tax_calculation_method.

    $sgResult = $model->save($sgCompId, $sgPayload, $userId);
    checkTrue('SG employee saves without master_address_id (TH-only address picker)', $sgResult['status']);
    checkTrue('SG employee saves with 8-digit mobile number', $sgResult['status'] || false);
    if (!$sgResult['status']) {
        echo "    (SG save error: {$sgResult['message']})\n";
    }

    $sgRow = null;
    if ($sgResult['status']) {
        $stmt = $pdo->prepare("SELECT tax_calculation_method FROM employees WHERE id = :id");
        $stmt->execute([':id' => $sgResult['id']]);
        $sgRow = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    check('tax_calculation_method defaulted to average for SG company', $sgRow['tax_calculation_method'] ?? null, 'average');

    // A non-Thai-shaped id_card_no must still be accepted (no checksum enforced outside TH).
    $sgPayload2 = baseEmployeePayload($sgStructure, 'SG-EMP-' . uniqid(), $pdo);
    $sgPayload2['id_card_no'] = 'S7654321B';
    $sgPayload2['mobile_no'] = '91234567';
    $sgPayload2['emergency_mobile'] = '98765432';
    $sgResult2 = $model->save($sgCompId, $sgPayload2, $userId);
    checkTrue('SG employee with non-Thai-shaped id_card_no is accepted', $sgResult2['status']);

    // ---------- TH company: existing required-field behavior must be UNCHANGED ----------
    $thCompId = makeCompany($pdo, 'TH');
    $thStructure = makeStructure($pdo, $thCompId);
    $thPayload = baseEmployeePayload($thStructure, 'TH-EMP-' . uniqid(), $pdo);
    $thPayload['id_card_no'] = '1234567890123'; // fails Thai mod-11 checksum on purpose
    $thPayload['mobile_no'] = '812345678';
    $thPayload['emergency_mobile'] = '812345679';
    $thPayload['tax_calculation_method'] = 'average';
    // master_address_id_register/_contact deliberately left unset here -- no longer required at all
    // (2026-08-19: register/contact address dropped from EmployeeModel::requiredColumns(), see its
    // own docblock), covered by the "hidden fields don't block a save" case below instead.

    $thResultBadChecksum = $model->save($thCompId, $thPayload, $userId);
    checkFalse('TH employee with invalid Thai ID checksum still rejected', $thResultBadChecksum['status']);
    check('TH rejection message is the checksum message, not a missing-field message', $thResultBadChecksum['message'], 'Invalid Thai ID card number.');

    $thPayload2 = baseEmployeePayload($thStructure, 'TH-EMP-' . uniqid(), $pdo);
    $thPayload2['mobile_no'] = '1234567'; // 7 digits -- valid under the relaxed non-TH pattern but must still fail for TH
    $thPayload2['emergency_mobile'] = '812345679';
    $thPayload2['tax_calculation_method'] = 'average';
    $thResultBadPhone = $model->save($thCompId, $thPayload2, $userId);
    checkFalse('TH employee still enforces 9-10 digit mobile number (7 digits rejected)', $thResultBadPhone['status']);

    // ---------- Hidden-fields-don't-block-a-save (2026-08-19, explicit request: trim the Employee
    // form to Payroll-relevant fields only) -- register/contact address and emergency contact are no
    // longer collected by the form at all, so a real save must succeed with all six left blank. ----------
    $thPayload3 = baseEmployeePayload($thStructure, 'TH-EMP-' . uniqid(), $pdo);
    $thPayload3['id_card_no'] = '1234567890121'; // valid mod-11 checksum
    $thPayload3['mobile_no'] = '812345678';
    $thPayload3['tax_calculation_method'] = 'average';
    foreach (['address_line_1_register', 'address_line_1_contact', 'emergency_name', 'emergency_surname', 'emergency_relationship', 'emergency_mobile'] as $droppedField) {
        unset($thPayload3[$droppedField]);
    }
    $thResultNoHiddenFields = $model->save($thCompId, $thPayload3, $userId);
    checkTrue('TH employee saves with address/emergency-contact fields entirely absent' . (empty($thResultNoHiddenFields['status']) ? " ({$thResultNoHiddenFields['message']})" : ''), $thResultNoHiddenFields['status']);
    if ($thResultNoHiddenFields['status']) {
        $hiddenFieldsRow = $pdo->query("SELECT address_line_1_register, emergency_mobile FROM employees WHERE id = {$thResultNoHiddenFields['id']}")->fetch(PDO::FETCH_ASSOC);
        check('address_line_1_register stored as NULL, not rejected', $hiddenFieldsRow['address_line_1_register'], null);
        check('emergency_mobile stored as NULL, not rejected', $hiddenFieldsRow['emergency_mobile'], null);
    }

} finally {
    $pdo->rollBack();
}

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
