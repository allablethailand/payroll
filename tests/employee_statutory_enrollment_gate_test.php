<?php
/**
 * Batch 4 item 3 -- verifies the SSO/PVD tab's 2-axis gate (employee-level sso_enrolled/pvd_enrolled
 * AND company-level company_statutory_settings effective_status) that EmployeeModel::save() now
 * enforces server-side, mirroring detail.js's own clear-on-hide. Also covers
 * CompanyStatutorySettingModel::effectiveStatusForItemCode() (the generalized resolution both
 * EmployeeModel and StatutoryCalculationEngine now share) and
 * EmployeeModel::statutoryEnrollmentGateInfo() (what the view/JS read).
 * Key behaviors under test:
 *   - Ineligible dependent fields are discarded SILENTLY (no validation error).
 *   - Existing data is NEVER deleted just because the gate closed later -- an UPDATE preserves the
 *     row's current value for those columns (explicit instruction, point 4), including sso_no's
 *     ciphertext (never re-encrypted).
 *   - A brand-new employee (CREATE) has nothing to preserve -- ineligible fields simply stay null.
 *   - sso_leave_reason_code carries one more AND condition (employment_status resigned/terminated)
 *     on top of TH_SSO's own gate.
 *   - A company whose country has no such item at all (effectiveStatusForItemCode() returns null)
 *     is treated as ineligible too, distinct from an item that exists but is switched off.
 * Run with: php tests/employee_statutory_enrollment_gate_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/EmployeeModel.php';
require_once __DIR__ . '/../app/models/CompanyStatutorySettingModel.php';

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
function checkNull(string $label, $actual): void { check($label, $actual, null); }

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

    $stmt = $pdo->prepare("INSERT INTO structure_positions (comp_id, position_code, position_name_th, position_name_en) VALUES (:c, :code, 'ตำแหน่งทดสอบ', 'Test Position')");
    $stmt->execute([':c' => $compId, ':code' => 'POS' . uniqid()]);
    $ids['position_id'] = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO structure_branches (comp_id, branch_code, branch_name_th, branch_name_en) VALUES (:c, :code, 'สาขาทดสอบ', 'Test Branch')");
    $stmt->execute([':c' => $compId, ':code' => 'BR' . uniqid()]);
    $ids['branch_id'] = (int)$pdo->lastInsertId();

    return $ids;
}

function statutoryItemId(PDO $pdo, string $code): int {
    $stmt = $pdo->prepare("SELECT id FROM `statutory_items` WHERE code = :code AND comp_id IS NULL");
    $stmt->execute([':code' => $code]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        throw new RuntimeException("statutory_items code '{$code}' not found -- seed missing?");
    }
    return (int)$id;
}

function basePayload(string $empNo, array $structure, int $paymentMethodId): array {
    return [
        'employee_no' => $empNo, 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'surname_th' => 'นามสกุล',
        'name_en' => 'Test', 'surname_en' => 'Surname', 'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        'id_card_no' => '1234567890121',
        'department_id' => $structure['department_id'], 'position_id' => $structure['position_id'], 'branch_id' => $structure['branch_id'],
        'employment_date' => '2024-01-01', 'employment_status' => 'permanent', 'employment_type' => 'full_time',
        'workforce_type' => 'office', 'record_time_method' => 'manual', 'payment_method_id' => $paymentMethodId,
        'personal_email' => 'gate' . uniqid() . '@example.com', 'mobile_no' => '812345678',
        'salary_type' => 'monthly', 'base_salary_amount' => 25000, 'salary_effective_date' => '2024-01-01',
        'tax_calculation_method' => 'average',
    ];
}

try {
    $userId = 1;
    $model = new EmployeeModel();
    $settingModel = new CompanyStatutorySettingModel($pdo);
    $compId = makeCompany($pdo, 'TH');
    $structure = makeStructure($pdo, $compId);
    $paymentMethodId = resolvePaymentMethodId($pdo, 'cash');
    $ssoItemId = statutoryItemId($pdo, 'TH_SSO');
    $pvdItemId = statutoryItemId($pdo, 'TH_PVD');

    // ==================== CompanyStatutorySettingModel::effectiveStatusForItemCode() ====================
    check('Fresh TH company: TH_SSO defaults to active (default_is_active, no override row yet)',
        $settingModel->effectiveStatusForItemCode($compId, 'TH_SSO'), 'active');
    check('Fresh TH company: TH_PVD defaults to active', $settingModel->effectiveStatusForItemCode($compId, 'TH_PVD'), 'active');

    $usCompId = makeCompany($pdo, 'US');
    checkNull('A US company has no TH_SSO item at all -- null, not "inactive"',
        $settingModel->effectiveStatusForItemCode($usCompId, 'TH_SSO'));
    checkNull('Same for TH_PVD on a US company', $settingModel->effectiveStatusForItemCode($usCompId, 'TH_PVD'));

    // ==================== EmployeeModel::statutoryEnrollmentGateInfo() ====================
    $gateInfo = $model->statutoryEnrollmentGateInfo($compId);
    check('Gate info maps TH_SSO to sso_enrolled', $gateInfo['TH_SSO']['enrollment_flag'] ?? null, 'sso_enrolled');
    check('Gate info maps TH_PVD to pvd_enrolled', $gateInfo['TH_PVD']['enrollment_flag'] ?? null, 'pvd_enrolled');
    check('Gate info company_status for a fresh TH company is active', $gateInfo['TH_SSO']['company_status'] ?? null, 'active');
    checkTrue('Gate info dependent_fields for TH_SSO includes sso_hospital_id',
        in_array('sso_hospital_id', $gateInfo['TH_SSO']['dependent_fields'] ?? [], true));
    checkTrue('Gate info dependent_fields for TH_SSO includes sso_leave_reason_code',
        in_array('sso_leave_reason_code', $gateInfo['TH_SSO']['dependent_fields'] ?? [], true));

    // ==================== CREATE: eligible (enrolled + company active) -> saved for real ====================
    $empNo1 = 'GATE-' . uniqid();
    $payload1 = array_merge(basePayload($empNo1, $structure, $paymentMethodId), [
        'sso_enrolled' => true, 'sso_no' => '1234567890123', 'sso_start_date' => '2024-01-01',
        'sso_hospital_id' => 'Test Hospital ' . uniqid(),
    ]);
    $r1 = $model->save($compId, $payload1, $userId);
    checkTrue('Eligible SSO create succeeds' . (empty($r1['status']) ? " ({$r1['message']})" : ''), $r1['status']);
    $row1 = $r1['status'] ? $model->get($compId, $empNo1) : null;
    if ($row1) {
        check('Eligible: sso_no is actually persisted (decrypted back)', $row1['sso_no'], '1234567890123');
        checkTrue('Eligible: sso_hospital_id resolved to a real int id', ($row1['sso_hospital_id'] ?? null) !== null);
    }

    // ==================== CREATE: employee NOT enrolled -> dependent fields silently discarded ====================
    $empNo2 = 'GATE-' . uniqid();
    $payload2 = array_merge(basePayload($empNo2, $structure, $paymentMethodId), [
        'sso_enrolled' => false, 'sso_no' => '9999999999999', 'sso_start_date' => '2024-01-01',
        'sso_hospital_id' => 'Should Not Save Hospital',
    ]);
    $r2 = $model->save($compId, $payload2, $userId);
    checkTrue('Not-enrolled create still succeeds (no validation error)' . (empty($r2['status']) ? " ({$r2['message']})" : ''), $r2['status']);
    $row2 = $r2['status'] ? $model->get($compId, $empNo2) : null;
    if ($row2) {
        checkNull('Not-enrolled create: sso_no was discarded, not saved', $row2['sso_no']);
        checkNull('Not-enrolled create: sso_hospital_id was discarded, not saved (no stray lookup row created)', $row2['sso_hospital_id']);
    }
    // The stray hospital tag must never have been created in company_hospitals at all (pre-loop
    // guard skips resolveOrCreate() entirely for an ineligible field).
    $strayCheck = $pdo->prepare("SELECT COUNT(*) FROM `company_hospitals` WHERE comp_id = :c AND name = 'Should Not Save Hospital'");
    $strayCheck->execute([':c' => $compId]);
    check('No stray company_hospitals row was created for the discarded value', (int)$strayCheck->fetchColumn(), 0);

    // ==================== UPDATE: gate closes later -> existing data PRESERVED, not deleted ====================
    if ($r1['status']) {
        $updatePayload = array_merge($payload1, [
            'id' => $r1['id'],
            'sso_enrolled' => false, // employee opts out this save
            'sso_no' => '0000000000000', // a stale/still-populated hidden field, as if the client never cleared it
        ]);
        $r3 = $model->save($compId, $updatePayload, $userId);
        checkTrue('Update with sso_enrolled flipped to false still succeeds', $r3['status']);
        $row3 = $model->get($compId, $empNo1);
        check('Existing sso_no is PRESERVED (not deleted, not overwritten with the new submitted value)', $row3['sso_no'], '1234567890123');
        checkTrue('sso_hospital_id is also preserved', ($row3['sso_hospital_id'] ?? null) !== null);

        // Re-enroll: eligible again -> a genuinely new value now DOES save.
        $reEnrollPayload = array_merge($payload1, [
            'id' => $r1['id'], 'sso_enrolled' => true, 'sso_no' => '1111111111111',
        ]);
        $r4 = $model->save($compId, $reEnrollPayload, $userId);
        checkTrue('Re-enrolling and saving a new value succeeds', $r4['status']);
        $row4 = $model->get($compId, $empNo1);
        check('Once re-eligible, a genuinely new sso_no value DOES persist', $row4['sso_no'], '1111111111111');
    }

    // ==================== Company-level gate: TH_SSO turned OFF for the whole company ====================
    $settingModel->toggleStatus($compId, $ssoItemId, $userId); // active -> inactive
    check('Company TH_SSO effective_status is now inactive', $settingModel->effectiveStatusForItemCode($compId, 'TH_SSO'), 'inactive');

    if ($r1['status']) {
        $companyOffPayload = array_merge($payload1, [
            'id' => $r1['id'], 'sso_enrolled' => true, // employee still says yes...
            'sso_no' => '2222222222222', // ...but the COMPANY has switched the item off
        ]);
        $r5 = $model->save($compId, $companyOffPayload, $userId);
        checkTrue('Save succeeds even though the company has TH_SSO switched off', $r5['status']);
        $row5 = $model->get($compId, $empNo1);
        check('Company-disabled: the NEW value was discarded', $row5['sso_no'], '1111111111111');
        checkFalse('Company-disabled: the old value was NOT wiped to null either', $row5['sso_no'] === null);
    }

    // New employee created while company-disabled: nothing to preserve -> stays null, no error.
    $empNo3 = 'GATE-' . uniqid();
    $payload3 = array_merge(basePayload($empNo3, $structure, $paymentMethodId), [
        'sso_enrolled' => true, 'sso_no' => '3333333333333',
    ]);
    $r6 = $model->save($compId, $payload3, $userId);
    checkTrue('Create succeeds while company TH_SSO is disabled', $r6['status']);
    $row6 = $r6['status'] ? $model->get($compId, $empNo3) : null;
    if ($row6) {
        checkNull('Brand-new employee, company-disabled: sso_no stays null (nothing to preserve)', $row6['sso_no']);
    }
    $settingModel->toggleStatus($compId, $ssoItemId, $userId); // restore to active for the rest of this file

    // ==================== PVD: same 2-axis gate, different fields ====================
    $empNo4 = 'GATE-' . uniqid();
    $payload4 = array_merge(basePayload($empNo4, $structure, $paymentMethodId), [
        'pvd_enrolled' => true, 'pvd_fund_name' => 'ABC Provident Fund', 'pvd_employee_rate' => 3.0, 'pvd_employer_rate' => 3.0,
    ]);
    $r7 = $model->save($compId, $payload4, $userId);
    checkTrue('Eligible PVD create succeeds', $r7['status']);
    $row7 = $r7['status'] ? $model->get($compId, $empNo4) : null;
    if ($row7) {
        check('Eligible: pvd_fund_name persisted', $row7['pvd_fund_name'], 'ABC Provident Fund');
    }
    if ($r7['status']) {
        $pvdOffPayload = array_merge($payload4, ['id' => $r7['id'], 'pvd_enrolled' => false, 'pvd_fund_name' => 'Should Not Overwrite']);
        $r8 = $model->save($compId, $pvdOffPayload, $userId);
        checkTrue('PVD opt-out save succeeds', $r8['status']);
        $row8 = $model->get($compId, $empNo4);
        check('PVD opt-out: existing fund name preserved, new value discarded', $row8['pvd_fund_name'], 'ABC Provident Fund');
    }
    // Company-level PVD off.
    $settingModel->toggleStatus($compId, $pvdItemId, $userId);
    $empNo5 = 'GATE-' . uniqid();
    $payload5 = array_merge(basePayload($empNo5, $structure, $paymentMethodId), [
        'pvd_enrolled' => true, 'pvd_fund_name' => 'Company Disabled Fund',
    ]);
    $r9 = $model->save($compId, $payload5, $userId);
    checkTrue('Create succeeds while company TH_PVD is disabled', $r9['status']);
    $row9 = $r9['status'] ? $model->get($compId, $empNo5) : null;
    if ($row9) {
        checkNull('Company-disabled PVD: pvd_fund_name discarded, not saved', $row9['pvd_fund_name']);
    }
    $settingModel->toggleStatus($compId, $pvdItemId, $userId); // restore

    // ==================== sso_leave_reason_code: extra AND condition (employment_status) ====================
    $empNo6 = 'GATE-' . uniqid();
    $payload6 = array_merge(basePayload($empNo6, $structure, $paymentMethodId), [
        'sso_enrolled' => true, 'employment_status' => 'resigned', 'sso_leave_reason_code' => 3,
    ]);
    $r10 = $model->save($compId, $payload6, $userId);
    checkTrue('Resigned + enrolled + company-active create succeeds', $r10['status']);
    $row10 = $r10['status'] ? $model->get($compId, $empNo6) : null;
    if ($row10) {
        check('sso_leave_reason_code saved when all 3 conditions hold', (int)($row10['sso_leave_reason_code'] ?? 0), 3);

        // Not resigned anymore -> the NEW attempted value (5) is discarded, but the row is an
        // UPDATE (point 4: never delete existing data) so the OLD value (3) is preserved, not nulled.
        $notResignedPayload = array_merge($payload6, ['id' => $r10['id'], 'employment_status' => 'permanent', 'sso_leave_reason_code' => 5]);
        $r11 = $model->save($compId, $notResignedPayload, $userId);
        checkTrue('Switching back to permanent still saves', $r11['status']);
        $row11 = $model->get($compId, $empNo6);
        check('sso_leave_reason_code: new value (5) discarded, OLD value (3) preserved once employment_status is no longer resigned/terminated', (int)($row11['sso_leave_reason_code'] ?? 0), 3);
    }

    // Resigned but NOT enrolled -> also discarded (SSO axis fails even though status condition holds).
    $empNo7 = 'GATE-' . uniqid();
    $payload7 = array_merge(basePayload($empNo7, $structure, $paymentMethodId), [
        'sso_enrolled' => false, 'employment_status' => 'terminated', 'sso_leave_reason_code' => 2,
    ]);
    $r12 = $model->save($compId, $payload7, $userId);
    checkTrue('Terminated + NOT enrolled create still succeeds', $r12['status']);
    $row12 = $r12['status'] ? $model->get($compId, $empNo7) : null;
    if ($row12) {
        checkNull('sso_leave_reason_code discarded when SSO enrollment axis fails, even though resigned/terminated', $row12['sso_leave_reason_code']);
    }

    echo "\n--------------------------------------------------\n";
    echo "Passed: {$passes}, Failed: {$failures}\n";
    if ($failures > 0) {
        echo "SOME TESTS FAILED\n";
    } else {
        echo "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
    }
} finally {
    $pdo->rollBack();
}

exit($failures > 0 ? 1 : 0);
