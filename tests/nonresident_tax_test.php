<?php
/**
 * Verifies the 2026-09-02 Non-Resident Foreign Tax feature (explicit request following an
 * AskUserQuestion exchange -- flagged, not guessed, that Thai PIT withholding on Thailand-source
 * salary uses the SAME progressive table regardless of resident/non-resident status; the user
 * confirmed their own accountant MAY apply a different rate, but doesn't know the exact figure, so
 * this is a plain company-configurable flat-rate override, never a hardcoded "correct" rate --
 * see NonResidentTaxSettingModel's own docblock).
 *
 * Covers `NonResidentTaxSettingModel` (validation + round-trip) and its wiring into
 * `PayrollRunModel::recalculate()` (an employee flagged `tax_non_resident=1` gets the flat rate
 * applied ONLY when the company setting is enabled with a rate configured; every other employee,
 * and a flagged employee when the company setting is off, keeps using the normal progressive
 * ThPitCalculator computation unchanged).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back. Uses a fresh throwaway company, not comp_id=1.
 * Run with: php tests/nonresident_tax_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/NonResidentTaxSettingModel.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';

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
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }

try {
    $userId = 1;
    $compCode = 'NRTAX_' . uniqid();
    $insComp = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', :comp_code)");
    $insComp->execute([':name' => 'NonResident Tax Test Co ' . uniqid(), ':comp_code' => $compCode]);
    $compId = (int)$pdo->lastInsertId();

    $settingModel = new NonResidentTaxSettingModel($pdo);

    echo "=== NonResidentTaxSettingModel: validation ===\n";
    check('get() on a never-configured company returns enabled=false, flat_rate_percent=null', $settingModel->get($compId), [
        'comp_id' => $compId, 'enabled' => false, 'flat_rate_percent' => null, 'reference_note' => null, 'updated_at' => null,
    ]);
    check('activeFlatRatePercent() is null when never configured', $settingModel->activeFlatRatePercent($compId), null);

    $rejectOutOfRange = $settingModel->save($compId, ['enabled' => true, 'flat_rate_percent' => 150], $userId);
    checkFalse('save() rejects flat_rate_percent > 100', $rejectOutOfRange['status']);
    $rejectNegative = $settingModel->save($compId, ['enabled' => true, 'flat_rate_percent' => -5], $userId);
    checkFalse('save() rejects a negative flat_rate_percent', $rejectNegative['status']);
    $rejectEnabledNoRate = $settingModel->save($compId, ['enabled' => true, 'flat_rate_percent' => null], $userId);
    checkFalse('save() rejects enabled=true with no flat_rate_percent at all', $rejectEnabledNoRate['status']);
    $rejectLongNote = $settingModel->save($compId, ['enabled' => false, 'reference_note' => str_repeat('x', 501)], $userId);
    checkFalse('save() rejects a reference_note over 500 chars', $rejectLongNote['status']);

    echo "=== NonResidentTaxSettingModel: round-trip ===\n";
    $saveOk = $settingModel->save($compId, ['enabled' => true, 'flat_rate_percent' => 15, 'reference_note' => 'RD ruling no. 1234/2569'], $userId);
    checkTrue('save() accepts a valid enabled+rate+note' . (empty($saveOk['message']) ? '' : " ({$saveOk['message']})"), $saveOk['status']);
    $reloaded = $settingModel->get($compId);
    checkTrue('reloaded enabled is true', $reloaded['enabled']);
    check('reloaded flat_rate_percent round-trips exactly', $reloaded['flat_rate_percent'], 15.0);
    check('reloaded reference_note round-trips exactly', $reloaded['reference_note'], 'RD ruling no. 1234/2569');
    check('activeFlatRatePercent() now returns the configured rate', $settingModel->activeFlatRatePercent($compId), 15.0);

    // Disable again -- activeFlatRatePercent() must go back to null even though a rate is still stored.
    $settingModel->save($compId, ['enabled' => false, 'flat_rate_percent' => 15], $userId);
    check('activeFlatRatePercent() is null once disabled, even with a rate still stored', $settingModel->activeFlatRatePercent($compId), null);
    checkTrue('the stored rate itself survives being disabled (not wiped)', $settingModel->get($compId)['flat_rate_percent'] === 15.0);

    echo "=== PayrollRunModel::recalculate() wiring ===\n";
    $cycleModel = new PayrollCycleModel($pdo);
    $cycleSave = $cycleModel->save($compId, [
        'cycle_name' => 'NRTAX_TEST_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    checkTrue('fixture: cycle created' . (empty($cycleSave['message']) ? '' : " ({$cycleSave['message']})"), $cycleSave['status']);
    $cycleId = $cycleSave['id'];

    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, employee_type, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, cycle_id, tax_non_resident)
        VALUES (:comp_id, :employee_no, :employee_type, 'mr', 'male', 'ทดสอบ', :surname, 'Test', :surname, '1990-01-01', :nationality,
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 50000, '2020-01-01', 'average', 'active', 0, 0, 0, :cycle_id, :tax_non_resident)");

    // A: domestic employee, NOT flagged -- must always use the normal calculation regardless of the
    // company's non-resident setting.
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => 'NRTAX_A_' . uniqid(), ':employee_type' => 'domestic',
        ':surname' => 'A', ':nationality' => 'Thai', ':email' => uniqid() . '@test.local', ':cycle_id' => $cycleId, ':tax_non_resident' => 0]);
    $empAId = (int)$pdo->lastInsertId();

    // B: foreign employee, flagged tax_non_resident=1.
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => 'NRTAX_B_' . uniqid(), ':employee_type' => 'foreigner',
        ':surname' => 'B', ':nationality' => 'American', ':email' => uniqid() . '@test.local', ':cycle_id' => $cycleId, ':tax_non_resident' => 1]);
    $empBId = (int)$pdo->lastInsertId();

    $runModel = new PayrollRunModel($pdo);
    $today = new DateTime();

    // Each call uses a DIFFERENT period (offset by $monthOffset months) -- PayrollRunModel::create()
    // refuses a 2nd run against the same cycle+period, and this test needs two independent runs
    // (setting off, then setting on) against the SAME cycle/employees to compare cleanly.
    $createRun = function (int $monthOffset) use ($runModel, $cycleId, $compId, $today, $userId) {
        $periodStart = (clone $today)->modify('first day of this month')->modify("+{$monthOffset} months")->format('Y-m-d');
        $periodEnd = (clone $today)->modify('last day of this month')->modify("+{$monthOffset} months")->format('Y-m-d');
        $res = $runModel->create($compId, [
            'cycle_id' => $cycleId, 'run_name' => 'NRTAX_RUN_' . uniqid(),
            'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $periodEnd,
        ], $userId, true);
        if (empty($res['status'])) {
            throw new RuntimeException('Cannot continue without a created run: ' . $res['message']);
        }
        $runModel->recalculate($res['id'], $compId, $userId, true);
        return $runModel->getDetails($res['id'], $compId);
    };

    $pitOf = function (array $details, int $employeeId): ?array {
        $row = current(array_filter($details, fn($d) => (int)$d['employee_id'] === $employeeId));
        if ($row === false) return null;
        $breakdown = is_string($row['statutory_breakdown']) ? json_decode($row['statutory_breakdown'], true) : $row['statutory_breakdown'];
        foreach ((array)$breakdown as $item) {
            if (($item['code'] ?? null) === 'TH_PIT') return $item;
        }
        return null;
    };

    echo "--- company setting OFF (never enabled) -- both employees use the normal progressive calc ---\n";
    $settingModel->save($compId, ['enabled' => false, 'flat_rate_percent' => null], $userId);
    $detailsOff = $createRun(0);
    $pitAOff = $pitOf($detailsOff, $empAId);
    $pitBOff = $pitOf($detailsOff, $empBId);
    checkTrue('A (not flagged) has a TH_PIT line', $pitAOff !== null);
    checkTrue('B (flagged, but company setting OFF) has a TH_PIT line', $pitBOff !== null);
    checkFalse('B is NOT using the flat-rate note while the company setting is off', str_starts_with((string)($pitBOff['note'] ?? ''), 'th_pit_nonresident_flat_rate_'));

    echo "--- company setting ON, 20% flat rate -- ONLY the flagged employee (B) switches ---\n";
    $settingModel->save($compId, ['enabled' => true, 'flat_rate_percent' => 20], $userId);
    $detailsOn = $createRun(1);
    $pitAOn = $pitOf($detailsOn, $empAId);
    $pitBOn = $pitOf($detailsOn, $empBId);
    checkFalse('A (not flagged) is UNAFFECTED -- still the normal progressive calc, even with the company setting on', str_starts_with((string)($pitAOn['note'] ?? ''), 'th_pit_nonresident_flat_rate_'));
    check('B\'s note marks this as the non-resident flat-rate computation at 20%', $pitBOn['note'] ?? null, 'th_pit_nonresident_flat_rate_20');
    check('B\'s TH_PIT employee_amount is exactly 20% of taxable gross (50000 * 0.20 = 10000)', (float)($pitBOn['employee_amount'] ?? -1), 10000.0);
    check('B\'s base_amount is the taxable gross itself (not an annualized/allowance-reduced figure, same as the run-level flat-rate branch)', (float)($pitBOn['base_amount'] ?? -1), 50000.0);

    echo "\n" . ($failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)" : "SOME TESTS FAILED") . "\n";
    echo "{$passes} passed, {$failures} failed.\n";
} finally {
    $pdo->rollBack();
}
exit($failures === 0 ? 0 : 1);
