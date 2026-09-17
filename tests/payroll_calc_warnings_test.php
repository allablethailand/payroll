<?php
/**
 * 2026-09-16, Employee Breakdown table "ย้ายข้อมูลออกจากเซลล์" round -- locks the 2 backend pieces that
 * round added to PayrollRunModel, both of which are easy to break silently later:
 *
 *  1. The advisory/blocking split of `calc_errors`. The list itself is not new -- it was an inline
 *     array_diff + 2 strpos checks inside recalculate() since 2026-09-02; it moved to
 *     ADVISORY_CALC_ERROR_CODES/ADVISORY_CALC_ERROR_PREFIXES so getDetails() can split the same way
 *     the writer does. These assertions exist so that move (and any later edit of the list) can
 *     never quietly turn an advisory note into something that flips calc_status to 'error' and
 *     blocks submit(), or the reverse.
 *  2. `adjustment_count` on every getDetails() row -- one number covering all 5 per-employee
 *     adjustment tables (it backs the count badge on the Calculation Breakdown row button).
 *     Verified by inserting one row in each of those tables, inside a transaction that is
 *     always rolled back.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Run with: php tests/payroll_calc_warnings_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
foreach (glob(__DIR__ . '/../app/services/*.php') as $serviceFile) {
    require_once $serviceFile;
}
foreach (glob(__DIR__ . '/../app/models/*.php') as $modelFile) {
    require_once $modelFile;
}

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

echo "=== 1. Advisory calc-error list (exact contents) ===\n";
// Spelled out here on purpose: this is the whole point of the test. A code added/removed in the
// model without a matching change here is a deliberate decision that has to be made twice.
check('ADVISORY_CALC_ERROR_CODES', PayrollRunModel::ADVISORY_CALC_ERROR_CODES, [
    'daily_salary_no_shift_pattern',
    'salary_type_hourly_not_supported',
    'hourly_salary_no_attendance_data',
    'no_attendance_data_this_period',
    'ot_not_calculated_ineligible',
    'mixed_payment_lines_mismatch',
]);
check('ADVISORY_CALC_ERROR_PREFIXES', PayrollRunModel::ADVISORY_CALC_ERROR_PREFIXES, [
    'working_days_fallback_with_attendance_deduction:',
    'transfer_payee_not_in_run:',
]);
check('6 plain advisory codes + 2 prefixed ones', count(PayrollRunModel::ADVISORY_CALC_ERROR_CODES) + count(PayrollRunModel::ADVISORY_CALC_ERROR_PREFIXES), 8);

echo "\n=== 2. isAdvisoryCalcError() ===\n";
foreach (PayrollRunModel::ADVISORY_CALC_ERROR_CODES as $code) {
    checkTrue("advisory: {$code}", PayrollRunModel::isAdvisoryCalcError($code));
}
checkTrue('advisory: working_days_fallback_with_attendance_deduction:LATE', PayrollRunModel::isAdvisoryCalcError('working_days_fallback_with_attendance_deduction:LATE'));
checkTrue('advisory: transfer_payee_not_in_run:LOAN', PayrollRunModel::isAdvisoryCalcError('transfer_payee_not_in_run:LOAN'));
// Blocking codes -- these are what genuinely make calc_status 'error' and stop submit().
foreach (['profile_incomplete', 'missing_base_salary', 'no_manual_lines', 'no_rate_configured:TH_SSO'] as $code) {
    checkFalse("blocking: {$code}", PayrollRunModel::isAdvisoryCalcError($code));
}
// A prefix is only a prefix -- the bare name without its ":value" is not a real stored code, and
// must not be matched by accident (nor a code that merely CONTAINS an advisory one).
checkFalse('blocking: transfer_payee_not_in_run (no colon/value)', PayrollRunModel::isAdvisoryCalcError('transfer_payee_not_in_run'));
checkFalse('blocking: not_mixed_payment_lines_mismatch (substring, not prefix)', PayrollRunModel::isAdvisoryCalcError('not_mixed_payment_lines_mismatch'));

echo "\n=== 3. splitCalcErrors() ===\n";
check('null -> both empty', PayrollRunModel::splitCalcErrors(null), ['warnings' => [], 'blocking' => []]);
check('empty string -> both empty', PayrollRunModel::splitCalcErrors(''), ['warnings' => [], 'blocking' => []]);
check(
    'mixed list, whitespace tolerated, order preserved',
    PayrollRunModel::splitCalcErrors('missing_base_salary, ot_not_calculated_ineligible ,transfer_payee_not_in_run:LOAN, profile_incomplete'),
    [
        'warnings' => ['ot_not_calculated_ineligible', 'transfer_payee_not_in_run:LOAN'],
        'blocking' => ['missing_base_salary', 'profile_incomplete'],
    ]
);
check(
    'advisory-only list has nothing blocking (calc_status would stay calculated)',
    PayrollRunModel::splitCalcErrors('daily_salary_no_shift_pattern, working_days_fallback_with_attendance_deduction:ABSENT')['blocking'],
    []
);
check('lists are 0-indexed (JSON array, not object, for the client)', array_keys(PayrollRunModel::splitCalcErrors('a, b')['blocking']), [0, 1]);

echo "\n=== 4. recalculate()'s blocking filter is unchanged by the move ===\n";
// Reproduces the exact expression recalculate() used before the constants existed, and asserts the
// new one agrees with it on every sample -- this is what "ไม่เปลี่ยนพฤติกรรม" means concretely.
$legacyBlocking = static function (array $errors): array {
    return array_values(array_filter(
        array_diff($errors, ['daily_salary_no_shift_pattern', 'salary_type_hourly_not_supported', 'hourly_salary_no_attendance_data', 'no_attendance_data_this_period', 'ot_not_calculated_ineligible', 'mixed_payment_lines_mismatch']),
        static fn($e) => strpos((string)$e, 'working_days_fallback_with_attendance_deduction:') !== 0
            && strpos((string)$e, 'transfer_payee_not_in_run:') !== 0
    ));
};
$samples = [
    [],
    ['missing_base_salary'],
    ['daily_salary_no_shift_pattern'],
    ['daily_salary_no_shift_pattern', 'missing_base_salary'],
    ['working_days_fallback_with_attendance_deduction:LATE', 'transfer_payee_not_in_run:LOAN'],
    ['profile_incomplete', 'no_rate_configured:TH_PVD', 'ot_not_calculated_ineligible'],
    ['mixed_payment_lines_mismatch', 'no_attendance_data_this_period', 'salary_type_hourly_not_supported', 'hourly_salary_no_attendance_data'],
];
foreach ($samples as $i => $errors) {
    $now = array_values(array_filter($errors, static fn($e) => !PayrollRunModel::isAdvisoryCalcError((string)$e)));
    check("sample {$i}: same blocking set as before", $now, $legacyBlocking($errors));
    // ...and the one thing that actually depends on it.
    check("sample {$i}: same calc_status", empty($now) ? 'calculated' : 'error', empty($legacyBlocking($errors)) ? 'calculated' : 'error');
}

echo "\n=== 5. getDetails(): calc_warnings / calc_blocking / adjustment_count ===\n";
$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();
try {
    $target = $pdo->query("SELECT d.run_id, d.employee_id, r.comp_id
        FROM `payroll_run_details` d
        JOIN `payroll_runs` r ON r.id = d.run_id
        WHERE r.deleted_at IS NULL
        ORDER BY d.run_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        echo "  SKIP  no payroll_run_details row in this dev DB to test against\n";
    } else {
        $runId = (int)$target['run_id'];
        $employeeId = (int)$target['employee_id'];
        $compId = (int)$target['comp_id'];
        $model = new PayrollRunModel();

        $rowOf = static function (array $rows, int $employeeId): ?array {
            foreach ($rows as $row) {
                if ((int)$row['employee_id'] === $employeeId) return $row;
            }
            return null;
        };

        $before = $rowOf($model->getDetails($runId, $compId), $employeeId);
        checkTrue('row found in getDetails()', $before !== null);
        $baseCount = (int)$before['adjustment_count'];
        checkTrue('adjustment_count is an int', is_int($before['adjustment_count']));
        $isList = static fn($a) => is_array($a) && ($a === [] || array_keys($a) === range(0, count($a) - 1));
        checkTrue('calc_warnings is a list', $isList($before['calc_warnings']));
        checkTrue('calc_blocking is a list', $isList($before['calc_blocking']));
        check(
            'warnings + blocking == the stored calc_errors codes',
            count($before['calc_warnings']) + count($before['calc_blocking']),
            count(array_filter(array_map('trim', explode(',', (string)$before['calc_errors'])), static fn($c) => $c !== ''))
        );

        // One row per table the Adjustments modal's 5 tabs write to.
        $pedTypeId = (int)$pdo->query("SELECT id FROM `payroll_earning_deduction_types` ORDER BY id LIMIT 1")->fetchColumn();
        $pdo->prepare("INSERT INTO `payroll_run_manual_lines` (run_id, employee_id, ped_type_id, amount, created_at)
            VALUES (?, ?, ?, 100, NOW())")->execute([$runId, $employeeId, $pedTypeId]);
        check('manual line counted', (int)$rowOf($model->getDetails($runId, $compId), $employeeId)['adjustment_count'], $baseCount + 1);

        $pdo->prepare("INSERT INTO `payroll_run_line_overrides` (run_id, employee_id, item_code, action, override_amount, created_at)
            VALUES (?, ?, '__test_item__', 'override', 50, NOW())")->execute([$runId, $employeeId]);
        check('line override counted', (int)$rowOf($model->getDetails($runId, $compId), $employeeId)['adjustment_count'], $baseCount + 2);

        $pdo->prepare("INSERT INTO `payroll_run_sync_item_overrides` (run_id, employee_id, late_mins, created_at)
            VALUES (?, ?, 15, NOW())")->execute([$runId, $employeeId]);
        check('sync item override counted', (int)$rowOf($model->getDetails($runId, $compId), $employeeId)['adjustment_count'], $baseCount + 3);

        $pdo->prepare("INSERT INTO `employee_recurring_deductions` (employee_id, ped_type_id, amount, effective_date, status, created_at)
            VALUES (?, ?, 200, '2026-01-01', 'active', NOW())")->execute([$employeeId, $pedTypeId]);
        $recurringId = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO `payroll_run_recurring_deduction_overrides` (run_id, recurring_id, payee_type, created_at)
            VALUES (?, ?, 'company', NOW())")->execute([$runId, $recurringId]);
        check('recurring deduction destination override counted (joined via recurring_id)', (int)$rowOf($model->getDetails($runId, $compId), $employeeId)['adjustment_count'], $baseCount + 4);

        $pdo->prepare("INSERT INTO `payroll_run_employee_exemptions` (run_id, employee_id, exempt_tax, exempt_sso, tax_calculate_override, sso_calculate_override, created_at)
            VALUES (?, ?, 0, 0, 'force_on', 'inherit', NOW())")->execute([$runId, $employeeId]);
        check('tax/SSO override counted', (int)$rowOf($model->getDetails($runId, $compId), $employeeId)['adjustment_count'], $baseCount + 5);

        // Run-wide, not per-employee: payroll_run_item_exclusions has no employee_id at all, so it
        // must NOT move a single employee's number (documented in getDetails()'s own SQL comment).
        $pdo->prepare("INSERT INTO `payroll_run_item_exclusions` (run_id, item_code, created_at) VALUES (?, '__test_item__', NOW())")->execute([$runId]);
        check('run-wide item exclusion does NOT count', (int)$rowOf($model->getDetails($runId, $compId), $employeeId)['adjustment_count'], $baseCount + 5);

        // An exemption row that overrides nothing (all 'inherit', nothing exempted) is not an
        // adjustment -- same condition the existing has_calc_override flag already uses.
        $pdo->prepare("UPDATE `payroll_run_employee_exemptions` SET tax_calculate_override = 'inherit' WHERE run_id = ? AND employee_id = ?")->execute([$runId, $employeeId]);
        check('all-inherit exemption row does NOT count', (int)$rowOf($model->getDetails($runId, $compId), $employeeId)['adjustment_count'], $baseCount + 4);

        // Another employee on the same run is unaffected by any of it.
        $otherId = (int)($pdo->query("SELECT employee_id FROM `payroll_run_details` WHERE run_id = {$runId} AND employee_id <> {$employeeId} LIMIT 1")->fetchColumn() ?: 0);
        if ($otherId) {
            $other = $rowOf($model->getDetails($runId, $compId), $otherId);
            check('another employee on the same run is unaffected', (int)$other['adjustment_count'], 0);
        }
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
echo "ALL TESTS PASSED\n";
