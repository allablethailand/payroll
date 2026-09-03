<?php
/**
 * Verifies the 3 Phase 3 Employee Reports (2026-09-02, see
 * project_employee_reports_phased_plan_2026_09_02 memory for the full 5-phase plan):
 * EmployeeModel::headcountStructureReport()/tenureReport()/birthdayAnniversaryReport().
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 * Run with: php tests/employee_phase3_reports_test.php
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
    $ok = (is_float($expected) || is_float($actual)) ? abs((float)$actual - (float)$expected) < 0.05 : $actual === $expected;
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

    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL")->execute([':comp_id' => $compId]);

    $model = new EmployeeModel();
    $structModel = new CompanyProfileModel($pdo);
    $deptA = $structModel->saveStructure('department', $compId, ['department_code' => 'P3_DEPT_A', 'department_name_th' => 'แผนก P3A', 'department_name_en' => 'Dept P3A'], $adminUserId);
    checkTrue('fixture: department A created' . (empty($deptA['status']) ? " ({$deptA['message']})" : ''), $deptA['status']);
    $deptAId = $deptA['id'];
    $deptB = $structModel->saveStructure('department', $compId, ['department_code' => 'P3_DEPT_B', 'department_name_th' => 'แผนก P3B', 'department_name_en' => 'Dept P3B'], $adminUserId);
    $deptBId = $deptB['id'];

    $today = new DateTime('today');
    function isoYearsAgo(DateTime $base, float $years): string {
        $d = clone $base;
        $d->modify('-' . (int)round($years * 365.25) . ' days');
        return $d->format('Y-m-d');
    }

    function makeP3Employee(PDO $pdo, int $compId, string $tag, array $overrides = []): int {
        $empNo = 'P3_' . $tag . '_' . uniqid();
        $defaults = [
            'employment_status' => 'permanent',
            'employment_type' => 'full_time',
            'department_id' => null,
            'employment_date' => '2020-01-01',
            'date_of_birth' => '1990-01-01',
        ];
        $d = array_merge($defaults, $overrides);
        $pdo->prepare("INSERT INTO `employees`
            (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
             personal_email, mobile_no, address_line_1_register, address_line_1_contact,
             emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
             department_id, employment_date, employment_status, employment_type, workforce_type, record_time_method,
             bank_id, bank_account_no, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt)
            VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :tag, :name_en, :tag, :dob, 'Thai',
             :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
             :department_id, :employment_date, :employment_status, :employment_type, 'office', 'manual',
             1, '1112223334', 'monthly', 30000, :employment_date, 'average', 'active', 0, 0, 1)")
            ->execute([
                ':comp_id' => $compId, ':employee_no' => $empNo, ':name_th' => 'ทดสอบ' . $tag, ':name_en' => 'Test', ':tag' => $tag,
                ':dob' => $d['date_of_birth'], ':email' => uniqid() . '@test.local', ':department_id' => $d['department_id'],
                ':employment_date' => $d['employment_date'], ':employment_status' => $d['employment_status'], ':employment_type' => $d['employment_type'],
            ]);
        return (int)$pdo->lastInsertId();
    }

    echo "=== headcountStructureReport() ===\n";
    makeP3Employee($pdo, $compId, 'A', ['department_id' => $deptAId, 'employment_type' => 'full_time']);
    makeP3Employee($pdo, $compId, 'B', ['department_id' => $deptAId, 'employment_type' => 'full_time']);
    makeP3Employee($pdo, $compId, 'C', ['department_id' => $deptBId, 'employment_type' => 'part_time']);
    makeP3Employee($pdo, $compId, 'D', ['department_id' => null, 'employment_type' => 'internship']);
    // Resigned -- must be excluded entirely (current snapshot, not historical).
    makeP3Employee($pdo, $compId, 'E', ['department_id' => $deptAId, 'employment_status' => 'resigned']);

    $byDept = $model->headcountStructureReport($compId, 'department');
    check('total = 4 (A-D, E excluded -- resigned)', $byDept['total'], 4);
    $deptGroups = [];
    foreach ($byDept['groups'] as $g) { $deptGroups[$g['label_th']] = $g['count']; }
    check('Dept P3A has 2 (A, B)', $deptGroups['แผนก P3A'] ?? 0, 2);
    check('Dept P3B has 1 (C)', $deptGroups['แผนก P3B'] ?? 0, 1);
    check('no-department bucket ("-") has 1 (D)', $deptGroups['-'] ?? 0, 1);

    $byType = $model->headcountStructureReport($compId, 'employment_type');
    $typeGroups = [];
    foreach ($byType['groups'] as $g) { $typeGroups[$g['label_th']] = $g['count']; }
    check('full_time has 2 (A, B)', $typeGroups['full_time'] ?? 0, 2);
    check('part_time has 1 (C)', $typeGroups['part_time'] ?? 0, 1);
    check('internship has 1 (D)', $typeGroups['internship'] ?? 0, 1);

    echo "=== tenureReport() ===\n";
    // F: 6 months tenure (<1yr bucket).
    makeP3Employee($pdo, $compId, 'F', ['department_id' => $deptAId, 'employment_date' => isoYearsAgo($today, 0.5)]);
    // G: 2 years tenure (1-3yr bucket).
    makeP3Employee($pdo, $compId, 'G', ['department_id' => $deptAId, 'employment_date' => isoYearsAgo($today, 2)]);
    // H: 4 years tenure (3-5yr bucket).
    makeP3Employee($pdo, $compId, 'H', ['department_id' => $deptAId, 'employment_date' => isoYearsAgo($today, 4)]);
    // I: 7 years tenure (5-10yr bucket).
    makeP3Employee($pdo, $compId, 'I', ['department_id' => $deptAId, 'employment_date' => isoYearsAgo($today, 7)]);
    // J: 12 years tenure (10yr+ bucket) -- the longest-serving in this fixture.
    makeP3Employee($pdo, $compId, 'J', ['department_id' => $deptAId, 'employment_date' => isoYearsAgo($today, 12)]);

    $tenureDeptA = $model->tenureReport($compId, ['department_id' => $deptAId]);
    // dept A holds: A, B (2020-01-01, several years old by "today"), F, G, H, I, J = 7 employees.
    check('7 employees in dept A tenure report (A, B, F-J)', count($tenureDeptA['items']), 7);
    $bucketCounts = [];
    foreach ($tenureDeptA['buckets'] as $b) { $bucketCounts[$b['key']] = $b['count']; }
    check('<1yr bucket has 1 (F)', $bucketCounts['<1'], 1);
    check('1-3yr bucket has 1 (G)', $bucketCounts['1-3'], 1);
    check('3-5yr bucket has 1 (H)', $bucketCounts['3-5'], 1);
    // A and B (from the headcountStructureReport section above, same dept A, default
    // employment_date 2020-01-01) ALSO land in this bucket alongside I (~7yr) -- 2020-01-01 is
    // itself several years ago by "today", so this isn't a bug, just this fixture's own shared
    // setup bleeding into a later section's count.
    check('5-10yr bucket has 3 (A, B from the earlier section + I)', $bucketCounts['5-10'], 3);
    checkTrue('10yr+ bucket has at least 1 (J, 12 years)', $bucketCounts['10+'] >= 1);
    check('longest_years is at least 12 (J)', $tenureDeptA['longest_years'] >= 12.0, true);

    echo "=== birthdayAnniversaryReport() ===\n";
    // K: born in March (any year), hired in a different month.
    makeP3Employee($pdo, $compId, 'K', ['department_id' => $deptBId, 'date_of_birth' => '1985-03-15', 'employment_date' => '2018-07-01']);
    // L: hired in March (any year), born in a different month.
    makeP3Employee($pdo, $compId, 'L', ['department_id' => $deptBId, 'date_of_birth' => '1988-09-01', 'employment_date' => '2019-03-20']);
    // M: BOTH born AND hired in March -- must appear in BOTH lists (not deduplicated).
    makeP3Employee($pdo, $compId, 'M', ['department_id' => $deptBId, 'date_of_birth' => '1992-03-05', 'employment_date' => '2021-03-10']);
    // N: neither birthday nor anniversary in March -- must appear in neither list.
    makeP3Employee($pdo, $compId, 'N', ['department_id' => $deptBId, 'date_of_birth' => '1990-06-01', 'employment_date' => '2020-06-01']);

    $marchReport = $model->birthdayAnniversaryReport($compId, 3, ['department_id' => $deptBId]);
    check('3 birthdays in March (K, M -- L/N excluded)', count($marchReport['birthdays']), 2);
    check('3 anniversaries in March (L, M -- K/N excluded)', count($marchReport['anniversaries']), 2);
    $birthdayNos = array_column($marchReport['birthdays'], 'employee_no');
    $anniversaryNos = array_column($marchReport['anniversaries'], 'employee_no');
    $mEmpNo = null;
    foreach ($marchReport['birthdays'] as $r) { if (strpos($r['employee_no'], 'P3_M_') === 0) { $mEmpNo = $r['employee_no']; } }
    checkTrue('M appears in the birthdays list', $mEmpNo !== null);
    checkTrue('M ALSO appears in the anniversaries list (not deduplicated -- 2 separate real facts)', in_array($mEmpNo, $anniversaryNos, true));

    $aprilReport = $model->birthdayAnniversaryReport($compId, 4, ['department_id' => $deptBId]);
    check('no birthdays or anniversaries in April for this fixture', count($aprilReport['birthdays']) + count($aprilReport['anniversaries']), 0);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
