<?php
/**
 * Verifies the 3 Phase 2 Employee Reports (2026-09-02, see
 * project_employee_reports_phased_plan_2026_09_02 memory for the full 5-phase plan):
 * EmployeeModel::expiryReport()/probationReport()/statutoryEnrollmentReport().
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 * Run with: php tests/employee_phase2_reports_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/EmployeeModel.php';
require_once __DIR__ . '/../app/models/CompanyProfileModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    $ok = (is_float($expected) || is_float($actual)) ? abs((float)$actual - (float)$expected) < 0.005 : $actual === $expected;
    if ($ok) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }

try {
    $compId = 1;
    $adminUserId = 1;

    // Same shared-dev-DB isolation precaution as employee_headcount_movement_test.php -- these
    // reports count/aggregate ACROSS the whole `employees` table for comp_id=1.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL")->execute([':comp_id' => $compId]);

    $model = new EmployeeModel();
    $structModel = new CompanyProfileModel($pdo);
    $deptRes = $structModel->saveStructure('department', $compId, ['department_code' => 'P2_DEPT_A', 'department_name_th' => 'แผนก P2', 'department_name_en' => 'Dept P2'], $adminUserId);
    checkTrue('fixture: department created' . (empty($deptRes['status']) ? " ({$deptRes['message']})" : ''), $deptRes['status']);
    $deptId = $deptRes['id'];
    $deptRes2 = $structModel->saveStructure('department', $compId, ['department_code' => 'P2_DEPT_B', 'department_name_th' => 'แผนก P2B', 'department_name_en' => 'Dept P2B'], $adminUserId);
    $deptId2 = $deptRes2['id'];

    $today = new DateTime('today');
    function isoPlus(DateTime $base, int $days): string {
        $d = clone $base;
        $d->modify(($days >= 0 ? '+' : '') . $days . ' days');
        return $d->format('Y-m-d');
    }

    function makeP2Employee(PDO $pdo, int $compId, string $tag, array $overrides = []): int {
        $empNo = 'P2_' . $tag . '_' . uniqid();
        $defaults = [
            'employment_status' => 'permanent',
            'department_id' => null,
            'date_contract_expire' => null,
            'date_work_permit_expire' => null,
            'date_visa_expire' => null,
            'passport_expire_date' => null,
            'sso_enrolled' => 0,
            'pvd_enrolled' => 0,
            'employment_date' => '2020-01-01',
        ];
        $d = array_merge($defaults, $overrides);
        $pdo->prepare("INSERT INTO `employees`
            (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
             personal_email, mobile_no, address_line_1_register, address_line_1_contact,
             emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
             department_id, employment_date, employment_status, employment_type, workforce_type, record_time_method,
             bank_id, bank_account_no, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt,
             date_contract_expire, date_work_permit_expire, date_visa_expire, passport_expire_date)
            VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :tag, :name_en, :tag, '1990-01-01', 'Thai',
             :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
             :department_id, :employment_date, :employment_status, 'full_time', 'office', 'manual',
             1, '1112223334', 'monthly', 30000, :employment_date, 'average', 'active', :sso_enrolled, :pvd_enrolled, 1,
             :date_contract_expire, :date_work_permit_expire, :date_visa_expire, :passport_expire_date)")
            ->execute([
                ':comp_id' => $compId, ':employee_no' => $empNo, ':name_th' => 'ทดสอบ' . $tag, ':name_en' => 'Test', ':tag' => $tag,
                ':email' => uniqid() . '@test.local', ':department_id' => $d['department_id'],
                ':employment_date' => $d['employment_date'], ':employment_status' => $d['employment_status'],
                ':sso_enrolled' => $d['sso_enrolled'], ':pvd_enrolled' => $d['pvd_enrolled'],
                ':date_contract_expire' => $d['date_contract_expire'], ':date_work_permit_expire' => $d['date_work_permit_expire'],
                ':date_visa_expire' => $d['date_visa_expire'], ':passport_expire_date' => $d['passport_expire_date'],
            ]);
        return (int)$pdo->lastInsertId();
    }

    echo "=== expiryReport() ===\n";
    // A: contract expires in 10 days (within 30-day window, urgent).
    makeP2Employee($pdo, $compId, 'A', ['department_id' => $deptId, 'date_contract_expire' => isoPlus($today, 10)]);
    // B: work permit ALREADY expired 5 days ago -- must still show (negative days_remaining), and be
    // sorted BEFORE A (more urgent/overdue).
    makeP2Employee($pdo, $compId, 'B', ['department_id' => $deptId, 'date_work_permit_expire' => isoPlus($today, -5)]);
    // C: visa expires in 200 days -- outside a 30-day window, must NOT appear when filtering to 30.
    makeP2Employee($pdo, $compId, 'C', ['department_id' => $deptId, 'date_visa_expire' => isoPlus($today, 200)]);
    // D: passport expires in 25 days, but in a DIFFERENT department -- tests the department filter.
    makeP2Employee($pdo, $compId, 'D', ['department_id' => $deptId2, 'passport_expire_date' => isoPlus($today, 25)]);
    // E: contract AND work permit both expiring within window -- must produce 2 separate rows.
    makeP2Employee($pdo, $compId, 'E', ['department_id' => $deptId, 'date_contract_expire' => isoPlus($today, 15), 'date_work_permit_expire' => isoPlus($today, 20)]);

    $expiry30 = $model->expiryReport($compId, 30);
    check('30-day window: 5 items total (A, B, D, E-contract, E-workpermit -- C excluded, 200 days out)', count($expiry30['items']), 5);
    check('counts.contract = 2 (A, E)', $expiry30['counts']['contract'], 2);
    check('counts.work_permit = 2 (B, E)', $expiry30['counts']['work_permit'], 2);
    check('counts.visa = 0 (C is outside the window)', $expiry30['counts']['visa'], 0);
    check('counts.passport = 1 (D)', $expiry30['counts']['passport'], 1);
    checkTrue('B (already expired) sorts FIRST (most overdue/urgent)', $expiry30['items'][0]['expiry_type'] === 'work_permit' && $expiry30['items'][0]['days_remaining'] < 0);
    check('B\'s days_remaining is negative (already expired 5 days ago)', $expiry30['items'][0]['days_remaining'], -5);

    $expiryDeptFiltered = $model->expiryReport($compId, 30, ['department_id' => $deptId2]);
    check('department filter: only D (dept P2B) appears, 1 item', count($expiryDeptFiltered['items']), 1);
    check('filtered item is the passport type', $expiryDeptFiltered['items'][0]['expiry_type'], 'passport');

    $expiry200 = $model->expiryReport($compId, 200);
    check('200-day window: C (visa) now included, total 6 items', count($expiry200['items']), 6);

    echo "=== probationReport() ===\n";
    // F: on probation, started 40 days ago (longer-waiting).
    makeP2Employee($pdo, $compId, 'F', ['employment_status' => 'probation', 'employment_date' => isoPlus($today, -40), 'department_id' => $deptId]);
    // G: on probation, started 10 days ago (shorter-waiting) -- must sort AFTER F.
    makeP2Employee($pdo, $compId, 'G', ['employment_status' => 'probation', 'employment_date' => isoPlus($today, -10), 'department_id' => $deptId]);
    // H: permanent, NOT on probation -- must not appear at all.
    makeP2Employee($pdo, $compId, 'H', ['employment_status' => 'permanent', 'department_id' => $deptId]);

    $probation = $model->probationReport($compId);
    check('2 employees on probation (F, G) -- A-E/H excluded', $probation['count'], 2);
    check('F (longest-waiting) sorts first', $probation['items'][0]['days_on_probation'], 40);
    check('G sorts second', $probation['items'][1]['days_on_probation'], 10);

    $probationDept = $model->probationReport($compId, ['department_id' => $deptId2]);
    check('department filter with no probation employees in that dept: 0 results', $probationDept['count'], 0);

    echo "=== statutoryEnrollmentReport() ===\n";
    // I: enrolled in both SSO and PVD.
    makeP2Employee($pdo, $compId, 'I', ['sso_enrolled' => 1, 'pvd_enrolled' => 1, 'department_id' => $deptId]);
    // J: enrolled in SSO only.
    makeP2Employee($pdo, $compId, 'J', ['sso_enrolled' => 1, 'pvd_enrolled' => 0, 'department_id' => $deptId]);
    // K: enrolled in neither.
    makeP2Employee($pdo, $compId, 'K', ['sso_enrolled' => 0, 'pvd_enrolled' => 0, 'department_id' => $deptId]);
    // L: resigned, enrolled in both -- must be EXCLUDED entirely (not an actionable current question).
    makeP2Employee($pdo, $compId, 'L', ['sso_enrolled' => 1, 'pvd_enrolled' => 1, 'employment_status' => 'resigned', 'department_id' => $deptId]);

    // Full company-wide count at this point: A-K are all permanent/probation (11 employees) minus
    // L (resigned, excluded) -- only I/J/K carry real sso/pvd values (the rest default 0/0).
    $enrollment = $model->statutoryEnrollmentReport($compId);
    // Filter this test's OWN fixture rows only, by employee_no prefix, since the report itself is
    // company-wide (not scoped to a fetched id list like other reports here).
    $ownRows = array_values(array_filter($enrollment['items'], fn($r) => strpos($r['employee_no'], 'P2_') === 0));
    check('exactly 11 of THIS test\'s own employees appear (A through K, none resigned; L is excluded separately)', count($ownRows), 11);
    $ownBySuffix = [];
    foreach ($ownRows as $r) {
        // employee_no shape: P2_<TAG>_<uniqid> -- extract TAG.
        preg_match('/^P2_([A-Z]+)_/', $r['employee_no'], $mm);
        $ownBySuffix[$mm[1] ?? '?'] = $r;
    }
    checkTrue('I is sso_enrolled AND pvd_enrolled', (int)$ownBySuffix['I']['sso_enrolled'] === 1 && (int)$ownBySuffix['I']['pvd_enrolled'] === 1);
    checkTrue('J is sso_enrolled only', (int)$ownBySuffix['J']['sso_enrolled'] === 1 && (int)$ownBySuffix['J']['pvd_enrolled'] === 0);
    checkTrue('K is enrolled in neither', (int)$ownBySuffix['K']['sso_enrolled'] === 0 && (int)$ownBySuffix['K']['pvd_enrolled'] === 0);
    checkTrue('L (resigned) never made it into $ownRows at all', !isset($ownBySuffix['L']));

    // Department-scoped count is a cleaner way to verify counts without dev-DB pollution risk.
    $enrollmentDept = $model->statutoryEnrollmentReport($compId, ['department_id' => $deptId]);
    // dept $deptId holds: A,B,C,E,F,G,H,I,J,K (10 non-resigned) -- L excluded (resigned), D is in $deptId2.
    check('department-scoped roster: 10 employees (L excluded, D in a different dept)', count($enrollmentDept['items']), 10);
    check('sso_enrolled count = 2 (I, J)', $enrollmentDept['counts']['sso_enrolled'], 2);
    check('sso_not_enrolled count = 8 (everyone else in this dept)', $enrollmentDept['counts']['sso_not_enrolled'], 8);
    check('pvd_enrolled count = 1 (I only)', $enrollmentDept['counts']['pvd_enrolled'], 1);
    check('pvd_not_enrolled count = 9', $enrollmentDept['counts']['pvd_not_enrolled'], 9);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
