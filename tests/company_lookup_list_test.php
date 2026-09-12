<?php
/**
 * Batch 3A item 7b: generic "company lookup list" (CompanyLookupListModel::resolveOrCreate(),
 * used by SSO Hospital + PVD Investment Plan) + the new PVD fields (fund manager/member no./
 * end date+reason) + the fixed สปส.6-09 SSO leaving-reason code, all wired through
 * EmployeeModel::save()/get(). Also covers MasterModel::master()'s new 'hospital'/'pvd_plan'
 * select2-ajax cases (comp_id scoping).
 *
 * Not PHPUnit. Runs against the real dev DB inside a transaction that is always rolled back. Uses
 * fresh throwaway companies, never comp_id=1.
 * Run with: php tests/company_lookup_list_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/CompanyLookupListModel.php';
require_once __DIR__ . '/../app/models/MasterModel.php';
require_once __DIR__ . '/../app/models/EmployeeModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    $ok = $actual === $expected;
    if ($ok) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }

function makeCompany(PDO $pdo): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', :tax, 'Test Address', 'Test Signatory', 'active', :comp_code)");
    $stmt->execute([':name' => 'Lookup List Test Co ' . uniqid(), ':tax' => 'TAX' . uniqid(), ':comp_code' => 'LKP_' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function makeBareEmployee(PDO $pdo, int $compId): array {
    $employeeNo = 'LKP_' . uniqid();
    $stmt = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, employee_type, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'domestic', 'mr', 'male', 'ทดสอบ', 'ลิสต์', 'Test', 'Lookup', '1990-01-01', 'Thai',
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2010-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2010-01-01', 'average', 'active', 1, 1, 0)");
    $stmt->execute([':comp_id' => $compId, ':employee_no' => $employeeNo, ':email' => uniqid() . '@test.local']);
    return ['id' => (int)$pdo->lastInsertId(), 'employee_no' => $employeeNo];
}

try {
    $userId = 1;
    $lookupModel = new CompanyLookupListModel($pdo);
    $masterModel = new MasterModel();
    $employeeModel = new EmployeeModel();

    echo "=== Part 1: CompanyLookupListModel::resolveOrCreate() ===\n";
    $compL = makeCompany($pdo);

    $id1 = $lookupModel->resolveOrCreate('company_hospitals', $compL, 'โรงพยาบาลกรุงเทพ', $userId);
    checkTrue('first call with a new name creates a real row', $id1 !== null && $id1 > 0);

    $id2 = $lookupModel->resolveOrCreate('company_hospitals', $compL, '  โรงพยาบาลกรุงเทพ  ', $userId);
    check('same name with extra whitespace resolves to the SAME row (trimmed dedup)', $id2, $id1);

    $id3 = $lookupModel->resolveOrCreate('company_hospitals', $compL, 'โรงพยาบาลกรุงเทพ', $userId);
    check('exact same name again still resolves to the same row (no duplicate created)', $id3, $id1);

    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM company_hospitals WHERE comp_id = :c");
    $stmtCount->execute([':c' => $compL]);
    check('exactly 1 row exists for this company despite 3 resolveOrCreate() calls', (int)$stmtCount->fetchColumn(), 1);

    $id4 = $lookupModel->resolveOrCreate('company_hospitals', $compL, (string)$id1, $userId);
    check('passing the EXISTING numeric id (as a string, matching what a real <select> submits) resolves to itself, no new row', $id4, $id1);

    $idEmpty = $lookupModel->resolveOrCreate('company_hospitals', $compL, '   ', $userId);
    checkTrue('whitespace-only value resolves to null (no row created)', $idEmpty === null);

    $idOther = $lookupModel->resolveOrCreate('company_pvd_plans', $compL, 'แผนตราสารหนี้', $userId);
    // NOTE: comparing $idOther against $id1 numerically would be meaningless -- company_hospitals
    // and company_pvd_plans are separate tables with their own independent auto-increment counters,
    // so both legitimately landing on the same raw id value (e.g. both "1") proves nothing wrong.
    checkTrue('same helper works against the OTHER allowed table (company_pvd_plans) too', $idOther !== null && $idOther > 0);

    echo "\n=== Part 2: MasterModel::master() 'hospital'/'pvd_plan' cases (select2 ajax) ===\n";
    $resultHospital = $masterModel->master(1, 10, 'hospital', '', $compL);
    checkTrue('hospital list returns the row just created', count(array_filter($resultHospital['items'], fn($i) => (int)$i['id'] === $id1)) === 1);
    foreach ($resultHospital['items'] as $item) {
        if ((int)$item['id'] === $id1) {
            check('text_th equals the plain name (no _th/_en split)', $item['text_th'], 'โรงพยาบาลกรุงเทพ');
            check('text_en equals the SAME plain name', $item['text_en'], 'โรงพยาบาลกรุงเทพ');
        }
    }

    $compOther = makeCompany($pdo);
    $resultOtherComp = $masterModel->master(1, 10, 'hospital', '', $compOther);
    checkTrue('a DIFFERENT company sees ZERO hospitals from compL (comp_id scoping)', count($resultOtherComp['items']) === 0);

    $resultPvdPlan = $masterModel->master(1, 10, 'pvd_plan', '', $compL);
    checkTrue('pvd_plan list returns the row just created', count(array_filter($resultPvdPlan['items'], fn($i) => (int)$i['id'] === $idOther)) === 1);

    echo "\n=== Part 3: full EmployeeModel::save()/get() round trip ===\n";
    $emp = makeBareEmployee($pdo, $compL);

    $saveRes = $employeeModel->save($compL, [
        'id' => $emp['id'], 'employee_no' => $emp['employee_no'],
        // 2026-09-12, Batch 4 item 3: EmployeeModel::save() now gates every one of these dependent
        // fields on sso_enrolled/pvd_enrolled (AND the company's own effective_status) -- see
        // tests/employee_statutory_enrollment_gate_test.php for that gate's own dedicated coverage.
        // Explicitly enrolled here so THIS test keeps exercising resolveOrCreate()'s own dedup
        // mechanics (what it actually tests), not the gate's discard path.
        'sso_enrolled' => true, 'pvd_enrolled' => true, 'employment_status' => 'resigned',
        'sso_hospital_id' => 'โรงพยาบาลศิริราช',
        'pvd_plan_id' => 'แผนผสมความเสี่ยงต่ำ',
        'sso_leave_reason_code' => 3,
        'pvd_fund_manager' => 'บลจ.ทดสอบ จำกัด',
        'pvd_member_no' => 'MB-00123',
        'pvd_end_date' => '2026-06-30',
        'pvd_end_reason' => 'ลาออกจากกองทุนโดยสมัครใจ',
    ], $userId);
    checkTrue('save() with brand-new hospital/plan names succeeds' . (empty($saveRes['status']) ? " ({$saveRes['message']})" : ''), $saveRes['status'] === true);

    $row = $employeeModel->get($compL, $emp['employee_no']);
    checkTrue('get() returns the row', $row !== null);
    if ($row !== null) {
        checkTrue('sso_hospital_id resolved to a real int', is_numeric($row['sso_hospital_id']) && (int)$row['sso_hospital_id'] > 0);
        check('sso_hospital_name joined back correctly', $row['sso_hospital_name'], 'โรงพยาบาลศิริราช');
        checkTrue('pvd_plan_id resolved to a real int', is_numeric($row['pvd_plan_id']) && (int)$row['pvd_plan_id'] > 0);
        check('pvd_plan_name joined back correctly', $row['pvd_plan_name'], 'แผนผสมความเสี่ยงต่ำ');
        check('sso_leave_reason_code persisted', (int)$row['sso_leave_reason_code'], 3);
        check('pvd_fund_manager persisted', $row['pvd_fund_manager'], 'บลจ.ทดสอบ จำกัด');
        check('pvd_member_no persisted', $row['pvd_member_no'], 'MB-00123');
        check('pvd_end_date persisted', $row['pvd_end_date'], '2026-06-30');
        check('pvd_end_reason persisted', $row['pvd_end_reason'], 'ลาออกจากกองทุนโดยสมัครใจ');

        $savedHospitalId = (int)$row['sso_hospital_id'];

        echo "\n-- re-saving with the SAME hospital name (different casing/spacing) must NOT create a duplicate --\n";
        $saveRes2 = $employeeModel->save($compL, [
            'id' => $emp['id'], 'employee_no' => $emp['employee_no'],
            'sso_enrolled' => true,
            'sso_hospital_id' => '  โรงพยาบาลศิริราช  ',
        ], $userId);
        checkTrue('re-save succeeds', $saveRes2['status'] === true);
        $row2 = $employeeModel->get($compL, $emp['employee_no']);
        check('sso_hospital_id is UNCHANGED (same row reused, not a new duplicate)', (int)$row2['sso_hospital_id'], $savedHospitalId);
        $stmtCount2 = $pdo->prepare("SELECT COUNT(*) FROM company_hospitals WHERE comp_id = :c AND LOWER(name) = LOWER('โรงพยาบาลศิริราช')");
        $stmtCount2->execute([':c' => $compL]);
        check('exactly 1 company_hospitals row for this name (no duplicate)', (int)$stmtCount2->fetchColumn(), 1);
    }

    echo "\n=== Part 4: EmployeeController::statutoryRateDefaults()-equivalent logic (CompanyStatutorySettingModel) ===\n";
    require_once __DIR__ . '/../app/models/CompanyStatutorySettingModel.php';
    $settingModel = new CompanyStatutorySettingModel($pdo);
    $rows = $settingModel->list($compL);
    $ssoRow = null;
    $pvdRow = null;
    foreach ($rows as $r) {
        if (($r['code'] ?? null) === 'TH_SSO') $ssoRow = $r;
        if (($r['code'] ?? null) === 'TH_PVD') $pvdRow = $r;
    }
    checkTrue('TH_SSO row resolves for a fresh company (master fallback)', $ssoRow !== null);
    checkTrue('TH_PVD row resolves for a fresh company (master fallback)', $pvdRow !== null);
    if ($ssoRow !== null) {
        checkTrue('TH_SSO effective_employee_rate is a real number (master default, 5%)', $ssoRow['effective_employee_rate'] !== null);
    }

    echo "\n" . ($failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)" : "SOME TESTS FAILED") . "\n";
    echo "{$passes} passed, {$failures} failed.\n";
} finally {
    $pdo->rollBack();
}
exit($failures === 0 ? 0 : 1);
