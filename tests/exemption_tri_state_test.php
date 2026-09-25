<?php
/**
 * 2026-09-18, tiny-E: proves the ONE surface for "does this person get tax/SSO on this run" is
 * payroll_run_employee_exemptions' tri-state (saveEmployeeExemption), and that an exclusion row on
 * TH_PIT/TH_SSO -- which only ever zeroed the employee half and left the employer contribution
 * computing in full -- is now refused outright. See
 * docs/decisions/2026-09-18-tiny-e-exemption-guard.md.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back. Builds its own employees/run; touches no existing run.
 * Run with: php tests/exemption_tri_state_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();
$failures = 0; $passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    if ($actual === $expected) { $passes++; echo "  PASS  {$label}\n"; return; }
    $failures++; echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
}
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }

try {
    $compId = 1; $adminUserId = 1; $today = new DateTime();
    // Same dev-DB isolation reasoning as tests/tax_treatment_pit_fix_test.php's own comment:
    // leftover placeholder employees at comp_id=1 would otherwise join any run this script creates.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :c AND deleted_at IS NULL")->execute([':c' => $compId]);

    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_end_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, has_spouse)
        VALUES (:c, :no, 'mr', 'male', 'ทดสอบ', 'ยกเว้น', 'Test', 'Exempt', '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address', 'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', NULL, 'permanent', 'full_time', 'office', 'manual',
         'monthly', 0, '2020-01-01', 'average', 'active', :sso, 0, 0, 0)");
    $makeEmp = function (int $sso) use ($insEmp, $pdo, $compId): int {
        $insEmp->execute([':c' => $compId, ':no' => 'TEST_TRISTATE_' . uniqid(), ':email' => uniqid() . '@test.local', ':sso' => $sso]);
        return (int)$pdo->lastInsertId();
    };
    $empIn = $makeEmp(1);   // sso_enrolled = 1
    $empOut = $makeEmp(0);  // sso_enrolled = 0
    $runModel = new PayrollRunModel($pdo);
    $r = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'compute_statutory' => 1, 'run_name' => 'TRISTATE_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +9 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +9 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +9 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('fixture: incentive run created' . (empty($r['status']) ? " ({$r['message']})" : ''), (bool)$r['status']);
    $runId = (int)$r['id'];
    $runModel->joinEmployees($runId, $compId, [$empIn, $empOut], $adminUserId, true);
    $bonusId = (int)$pdo->query("SELECT id FROM payroll_earning_deduction_types WHERE comp_id = {$compId} AND item_code = 'BONUS' AND deleted_at IS NULL")->fetchColumn();
    checkTrue('fixture: BONUS catalog item exists', $bonusId > 0);
    $runModel->addManualLine($runId, $compId, $empIn, $bonusId, 40000, $adminUserId, true);
    $runModel->addManualLine($runId, $compId, $empOut, $bonusId, 40000, $adminUserId, true);

    $exemptionRow = function (int $employeeId) use ($pdo, $runId): ?array {
        $s = $pdo->prepare("SELECT tax_calculate_override, sso_calculate_override, exempt_tax, exempt_sso FROM `payroll_run_employee_exemptions` WHERE run_id = :r AND employee_id = :e");
        $s->execute([':r' => $runId, ':e' => $employeeId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        // The 2 legacy tinyint columns come back as strings from this driver -- the point of
        // reading them is that saveEmployeeExemption() keeps them in step with the tri-state.
        if ($row === false) { return null; }
        $row['exempt_tax'] = (int)$row['exempt_tax'];
        $row['exempt_sso'] = (int)$row['exempt_sso'];
        return $row;
    };
    /** The engine keeps a not-enrolled item as a zero row carrying note='employee_not_enrolled'
     *  rather than dropping it (StatutoryCalculationEngine), so "no SSO for this person" is read as
     *  BOTH sides being 0 -- which is precisely what an exclusion row never did: it zeroed the
     *  employee half and left the employer contribution computing in full. */
    $ssoOff = function (?array $item): bool {
        return $item !== null && (float)$item['employee_amount'] === 0.0 && (float)$item['employer_amount'] === 0.0
            && ($item['note'] ?? '') === 'employee_not_enrolled';
    };
    /** One statutory item of one employee, straight off the persisted breakdown. */
    $statutory = function (int $employeeId, string $code) use ($runModel, $runId, $compId): ?array {
        foreach ($runModel->getDetails($runId, $compId) as $d) {
            if ((int)$d['employee_id'] !== $employeeId) { continue; }
            foreach (($d['statutory_breakdown'] ?? []) as $item) {
                if ($item['code'] === $code) { return $item; }
            }
        }
        return null;
    };
    $countOverrides = function () use ($pdo, $runId): array {
        $c = function (string $table) use ($pdo, $runId): int {
            $s = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE run_id = :r AND item_code LIKE '\_\_statutory\_%'");
            $s->execute([':r' => $runId]);
            return (int)$s->fetchColumn();
        };
        return [$c('payroll_run_line_overrides'), $c('payroll_run_line_override_history')];
    };

    echo "=== (a) saveEmployeeExemption(): the tri-state write surface ===\n";
    $s1 = $runModel->saveEmployeeExemption($runId, $compId, $empIn, 'no', 'yes', null, $adminUserId, true);
    checkTrue('mixed tax=no / sso=yes saves' . (empty($s1['status']) ? " ({$s1['message']})" : ''), (bool)$s1['status']);
    check('...the row holds exactly that', $exemptionRow($empIn), ['tax_calculate_override' => 'no', 'sso_calculate_override' => 'yes', 'exempt_tax' => 1, 'exempt_sso' => 0]);
    checkTrue('tax=inherit / sso=no saves', (bool)$runModel->saveEmployeeExemption($runId, $compId, $empIn, 'inherit', 'no', null, $adminUserId, true)['status']);
    check('...and overwrites the previous pair rather than adding a second row',
        $exemptionRow($empIn), ['tax_calculate_override' => 'inherit', 'sso_calculate_override' => 'no', 'exempt_tax' => 0, 'exempt_sso' => 1]);
    checkTrue('inherit + inherit saves', (bool)$runModel->saveEmployeeExemption($runId, $compId, $empIn, 'inherit', 'inherit', null, $adminUserId, true)['status']);
    check('...and deletes the row outright (no all-zero row left behind)', $exemptionRow($empIn), null);
    check('a value outside the enum is refused', $runModel->saveEmployeeExemption($runId, $compId, $empIn, 'maybe', 'inherit', null, $adminUserId, true)['status'], false);
    check('...and wrote nothing', $exemptionRow($empIn), null);

    echo "=== (b) statutoryLineOverrideSave(): exclude is refused on the 2 tri-state codes ===\n";
    [$ovBefore, $histBefore] = $countOverrides();
    foreach (['TH_PIT', 'TH_SSO'] as $code) {
        $res = $runModel->statutoryLineOverrideSave($runId, $compId, $empIn, $code, 'exclude', null, null, $adminUserId, true);
        check("exclude on {$code} is refused", $res['status'], false);
        checkTrue("...and says where participation is set instead", str_contains((string)$res['message'], 'tax/SSO setting'));
        check("lower-case {$code} is refused too (the guard normalizes)", $runModel->statutoryLineOverrideSave($runId, $compId, $empIn, strtolower($code), 'exclude', null, null, $adminUserId, true)['status'], false);
    }
    check('no override row was written by any of the 4 refusals', $countOverrides()[0], $ovBefore);
    check('...and no history row either', $countOverrides()[1], $histBefore);
    $ovAmt = $runModel->statutoryLineOverrideSave($runId, $compId, $empIn, 'TH_SSO', 'override_amount', 123.0, null, $adminUserId, true);
    checkTrue('override_amount on TH_SSO still works -- only exclude is gone' . (empty($ovAmt['status']) ? " ({$ovAmt['message']})" : ''), (bool)$ovAmt['status']);
    check('...and the overridden figure is what recalculate() persists', (float)($statutory($empIn, 'TH_SSO')['employee_amount'] ?? -1), 123.0);
    checkTrue('removing it works (the escape hatch for any leftover row stays)', (bool)$runModel->statutoryLineOverrideRemove($runId, $compId, $empIn, 'TH_SSO', $adminUserId, true)['status']);
    $pvd = $runModel->statutoryLineOverrideSave($runId, $compId, $empIn, 'TH_PVD', 'exclude', null, null, $adminUserId, true);
    checkTrue('exclude on another statutory code (TH_PVD) is UNCHANGED' . (empty($pvd['status']) ? " ({$pvd['message']})" : ''), (bool)$pvd['status']);
    $runModel->statutoryLineOverrideRemove($runId, $compId, $empIn, 'TH_PVD', $adminUserId, true);

    echo "=== (c) recalculate() semantics: the tri-state moves BOTH sides of the SSO line ===\n";
    $ssoIn = $statutory($empIn, 'TH_SSO');
    checkTrue('baseline: an sso_enrolled=1 employee has an SSO line with both sides', $ssoIn !== null && (float)$ssoIn['employee_amount'] > 0 && (float)$ssoIn['employer_amount'] > 0);
    checkTrue('baseline: an sso_enrolled=0 employee gets no SSO on either side', $ssoOff($statutory($empOut, 'TH_SSO')));
    $runModel->saveEmployeeExemption($runId, $compId, $empIn, 'inherit', 'no', null, $adminUserId, true);
    checkTrue("sso_calculate_override='no' zeroes BOTH sides -- employer included, unlike the old exclusion row", $ssoOff($statutory($empIn, 'TH_SSO')));
    $runModel->saveEmployeeExemption($runId, $compId, $empOut, 'inherit', 'yes', null, $adminUserId, true);
    $ssoForced = $statutory($empOut, 'TH_SSO');
    checkTrue("sso_calculate_override='yes' enrols an opted-out employee, both sides", $ssoForced !== null && (float)$ssoForced['employee_amount'] > 0 && (float)$ssoForced['employer_amount'] > 0);
    $runModel->saveEmployeeExemption($runId, $compId, $empOut, 'inherit', 'inherit', null, $adminUserId, true);
    checkTrue("back to 'inherit' falls through to the employee's own sso_enrolled=0 again", $ssoOff($statutory($empOut, 'TH_SSO')));
    $runModel->saveEmployeeExemption($runId, $compId, $empIn, 'inherit', 'inherit', null, $adminUserId, true);
    $ssoBack = $statutory($empIn, 'TH_SSO');
    checkTrue("...and to sso_enrolled=1 for the other one", $ssoBack !== null && (float)$ssoBack['employee_amount'] > 0 && (float)$ssoBack['employer_amount'] > 0);

    echo "=== (d) both write surfaces refuse a verified employee and a non-draft run ===\n";
    checkTrue('fixture: employee verified', (bool)$runModel->setEmployeeVerified($runId, $compId, $empIn, true, $adminUserId, true)['status']);
    check('saveEmployeeExemption refuses a verified employee', $runModel->saveEmployeeExemption($runId, $compId, $empIn, 'no', 'no', null, $adminUserId, true)['status'], false);
    check('...and wrote nothing', $exemptionRow($empIn), null);
    check('statutoryLineOverrideSave refuses a verified employee too', $runModel->statutoryLineOverrideSave($runId, $compId, $empIn, 'TH_PVD', 'exclude', null, null, $adminUserId, true)['status'], false);
    $runModel->setEmployeeVerified($runId, $compId, $empIn, false, $adminUserId, true);
    $pdo->prepare("UPDATE `payroll_runs` SET state = 'locked' WHERE id = :r")->execute([':r' => $runId]);
    check('saveEmployeeExemption refuses a non-draft run', $runModel->saveEmployeeExemption($runId, $compId, $empIn, 'no', 'no', null, $adminUserId, true)['status'], false);
    check('statutoryLineOverrideSave refuses a non-draft run', $runModel->statutoryLineOverrideSave($runId, $compId, $empIn, 'TH_PVD', 'exclude', null, null, $adminUserId, true)['status'], false);

    echo "\n--------------------------------------------------\n";
    echo "Passed: {$passes}, Failed: {$failures}\n";
    echo $failures > 0 ? "SOME TESTS FAILED\n" : "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
} finally {
    $pdo->rollBack();
}

exit($failures > 0 ? 1 : 0);
