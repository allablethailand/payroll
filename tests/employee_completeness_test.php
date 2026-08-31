<?php
/**
 * Lightweight verification script for EmployeeModel::calculateCompleteness() (2026-08-19, explicit
 * request: "% ความสมบูรณ์" on the Employee list + detail pages). Pure function over a plain array --
 * no DB writes, so unlike the other tests/*.php scripts here this doesn't need a transaction/
 * rollback, it just needs a DB connection to construct EmployeeModel itself.
 * Run with: php tests/employee_completeness_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/EmployeeModel.php';

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

$model = new EmployeeModel();

function fullyFilledDomestic(): array {
    return [
        'employee_type' => 'domestic', 'title' => 'mr', 'gender' => 'male',
        'name_th' => 'ทดสอบ', 'surname_th' => 'นามสกุล', 'name_en' => 'Test', 'surname_en' => 'Surname',
        'date_of_birth' => '1990-01-01', 'nationality' => 'TH', 'id_card_no' => '1234567890121',
        'personal_email' => 'test@example.com', 'mobile_no' => '0812345678', 'line_id' => 'test_line',
        'department_id' => 1, 'role_id' => 1, 'position_id' => 1, 'branch_id' => 1,
        'work_location_id' => 1, 'shift_id' => 1, 'employment_date' => '2024-01-01',
        'payment_type' => 'bank', 'bank_id' => 1, 'bank_account_no' => '1112223334',
        'salary_type' => 'monthly', 'base_salary_amount' => 30000, 'salary_effective_date' => '2024-01-01',
        'tax_calculation_method' => 'average',
        'sso_enrolled' => 1, 'sso_no' => '1234567890123',
        'has_spouse' => 1, 'spouse_name' => 'คู่สมรส',
    ];
}

echo "=== Fully-filled domestic employee ===\n";
$full = $model->calculateCompleteness(fullyFilledDomestic());
check('overall percent is 100', $full['percent'], 100);
foreach (['info', 'contact', 'employment', 'salary', 'social', 'family'] as $tab) {
    check("{$tab} tab is 100%", $full['tabs'][$tab]['percent'], 100);
}

echo "=== Sync placeholder employee (createPlaceholderEmployeesForUnmapped()'s exact sentinels) ===\n";
$placeholder = [
    'employee_type' => 'domestic', 'title' => 'mr', 'gender' => 'male',
    'name_th' => 'EMP001', 'surname_th' => 'EMP001', 'name_en' => 'EMP001', 'surname_en' => 'EMP001',
    'date_of_birth' => '1900-01-01', 'nationality' => 'Unknown', 'id_card_no' => null,
    'personal_email' => 'sync-pending-emp001-5@placeholder.local', 'mobile_no' => '0000000000', 'line_id' => null,
    'department_id' => null, 'role_id' => null, 'position_id' => null, 'branch_id' => null,
    'work_location_id' => null, 'shift_id' => null, 'employment_date' => '2026-08-19',
    'payment_type' => 'cash', 'bank_id' => null, 'bank_account_no' => null,
    'salary_type' => 'monthly', 'base_salary_amount' => 0, 'salary_effective_date' => '2026-08-19',
    'tax_calculation_method' => 'average',
    'sso_enrolled' => 0, 'sso_no' => null,
    'has_spouse' => 0, 'spouse_name' => null,
];
$ph = $model->calculateCompleteness($placeholder);
checkTrue('placeholder scores well below 100% overall', $ph['percent'] < 100);
check('placeholder Info tab: title/gender/name_th+surname_th/name_en+surname_en filled (real strings, even if generic) but date_of_birth/nationality/id_card_no sentinels are not', $ph['tabs']['info']['done'], 4);
check('placeholder Contact tab is 0% (placeholder email + all-zero phone + no line_id)', $ph['tabs']['contact']['percent'], 0);
check('placeholder Employment tab: only employment_date + the auto-true cash-payment check count (dept/role/position/branch/work_location/shift all null)', $ph['tabs']['employment']['done'], 2);
check('placeholder Salary tab: base_salary_amount=0 does not count as filled', $ph['tabs']['salary']['done'], 3);
check('placeholder Social tab is 100% (not enrolled -- nothing required)', $ph['tabs']['social']['percent'], 100);
check('placeholder Family tab is 100% (no spouse -- nothing required)', $ph['tabs']['family']['percent'], 100);

echo "=== Conditional checklist adapts per employee ===\n";
$foreignerMissingDocs = array_merge(fullyFilledDomestic(), ['employee_type' => 'foreigner', 'id_card_no' => null]);
$foreignerRes = $model->calculateCompleteness($foreignerMissingDocs);
checkTrue('foreigner without tax_id_no/passport_no/work_permit_no fails identification despite id_card_no being irrelevant now', $foreignerRes['tabs']['info']['percent'] < 100);

$foreignerComplete = array_merge($foreignerMissingDocs, ['tax_id_no' => 'TAX123', 'passport_no' => 'P123', 'work_permit_no' => 'WP123']);
$foreignerCompleteRes = $model->calculateCompleteness($foreignerComplete);
check('foreigner with all 3 foreigner-path documents scores Info 100%', $foreignerCompleteRes['tabs']['info']['percent'], 100);

$cashNoBank = array_merge(fullyFilledDomestic(), ['payment_type' => 'cash', 'bank_id' => null, 'bank_account_no' => null]);
$cashRes = $model->calculateCompleteness($cashNoBank);
check('cash payment_type with no bank details still scores Employment 100% (bank check skipped, not penalized)', $cashRes['tabs']['employment']['percent'], 100);

$ssoEnrolledNoNumber = array_merge(fullyFilledDomestic(), ['sso_enrolled' => 1, 'sso_no' => null]);
$ssoRes = $model->calculateCompleteness($ssoEnrolledNoNumber);
check('sso_enrolled=1 with no sso_no scores Social 0%', $ssoRes['tabs']['social']['percent'], 0);

$spouseNoName = array_merge(fullyFilledDomestic(), ['has_spouse' => 1, 'spouse_name' => null]);
$spouseRes = $model->calculateCompleteness($spouseNoName);
check('has_spouse=1 with no spouse_name scores Family 0%', $spouseRes['tabs']['family']['percent'], 0);

echo "=== 2026-08-30 (Phase 3, T020, field \"จ่าย/ไม่จ่ายเงินเดือน\"): staff-only (is_payroll_participant=0) employee auto-passes every payroll-specific checklist item ===\n";
$staffOnly = array_merge(fullyFilledDomestic(), [
    'is_payroll_participant' => 0,
    // Every payroll-specific field genuinely blank -- would score 0% on Salary/Social/Family and
    // fail the bank-details check on Employment if is_payroll_participant weren't honored.
    'payment_type' => 'bank', 'bank_id' => null, 'bank_account_no' => null,
    'salary_type' => null, 'base_salary_amount' => 0, 'salary_effective_date' => null, 'tax_calculation_method' => null,
    'sso_enrolled' => 1, 'sso_no' => null,
    'has_spouse' => 1, 'spouse_name' => null,
]);
$staffOnlyRes = $model->calculateCompleteness($staffOnly);
check('staff-only employee scores 100% overall despite every payroll field being blank', $staffOnlyRes['percent'], 100);
check('Employment tab is 100% (bank-details check auto-passes)', $staffOnlyRes['tabs']['employment']['percent'], 100);
check('Salary tab is 100% (auto-passes, not 0%)', $staffOnlyRes['tabs']['salary']['percent'], 100);
check('Social tab is 100% (auto-passes despite sso_enrolled=1 with no sso_no)', $staffOnlyRes['tabs']['social']['percent'], 100);
check('Family tab is 100% (auto-passes despite has_spouse=1 with no spouse_name)', $staffOnlyRes['tabs']['family']['percent'], 100);
check('Info tab is still scored normally (name_th/surname/etc. all genuinely filled here)', $staffOnlyRes['tabs']['info']['percent'], 100);

$staffOnlyIncompleteInfo = array_merge($staffOnly, ['name_th' => null]);
$staffOnlyIncompleteRes = $model->calculateCompleteness($staffOnlyIncompleteInfo);
checkTrue('a staff-only employee with a genuinely missing NON-payroll field (name_th) still scores below 100% -- is_payroll_participant only exempts payroll-specific checks, not everything', $staffOnlyIncompleteRes['percent'] < 100);

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
