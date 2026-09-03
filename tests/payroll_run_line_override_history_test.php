<?php
/**
 * Lightweight verification script for item 10's payroll_run_line_override_history mechanism
 * (PayrollRunModel::recordLineOverrideHistory()/runAuditList()/lineOverrideAuditDiff()). Not
 * PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back, so it never leaves any data behind.
 * Run with: php tests/payroll_run_line_override_history_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';

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
function checkTrue(string $label, bool $actual): void {
    check($label, $actual, true);
}

try {
    $userId = 1;
    $compCode = 'LOH_' . uniqid();
    $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', :comp_code)")
        ->execute([':name' => 'Line Override History Test Co ' . uniqid(), ':comp_code' => $compCode]);
    $compId = (int)$pdo->lastInsertId();

    $empNo = 'LOH_EMP_' . uniqid();
    $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'LOH', 'Test', 'LOH', '1990-01-01', 'Thai',
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active', 1, 0, 0)")
        ->execute([':comp_id' => $compId, ':employee_no' => $empNo, ':email' => uniqid() . '@test.local']);
    $employeeId = (int)$pdo->lastInsertId();

    $cycleSave = (new PayrollCycleModel($pdo))->save($compId, [
        'cycle_name' => 'LOH_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    $cycleId = $cycleSave['id'];

    $runModel = new PayrollRunModel($pdo);
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'Line Override History Test Run',
        'period_start_date' => '2027-07-21', 'period_end_date' => '2027-08-20', 'payment_date' => '2027-07-25',
    ], $userId, true);
    if (empty($createRes['status'])) { throw new RuntimeException('create failed: ' . ($createRes['message'] ?? '')); }
    $runId = $createRes['id'];
    $runModel->recalculate($runId, $compId, $userId, true);

    $historyForRun = function () use ($pdo, $runId): array {
        $stmt = $pdo->prepare("SELECT * FROM payroll_run_line_override_history WHERE run_id = :run_id ORDER BY id ASC");
        $stmt->execute([':run_id' => $runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    // ---------- earning/deduction line override chain ----------
    echo "=== earning/deduction: override -> override again -> remove ===\n";
    // Uses the reserved base-salary code, which always exists on every calculated run.
    $ovRes1 = $runModel->lineOverrideSave($runId, $compId, $employeeId, PayrollRunModel::BASE_SALARY_OVERRIDE_CODE, 'override_amount', 25000.00, 'first edit', $userId, true);
    checkTrue('1st override succeeds' . (empty($ovRes1['status']) ? " ({$ovRes1['message']})" : ''), $ovRes1['status']);
    $h1 = $historyForRun();
    check('exactly 1 history row after 1st override', count($h1), 1);
    check('row 1 line_type is earning_deduction', $h1[0]['line_type'], 'earning_deduction');
    check('row 1 item_code is the base salary code', $h1[0]['item_code'], PayrollRunModel::BASE_SALARY_OVERRIDE_CODE);
    check('row 1 action is override', $h1[0]['action'], 'override');
    check('row 1 new_value is 25000.00', round((float)$h1[0]['new_value'], 2), 25000.00);
    check('row 1 old_value is the original computed base salary (30000.00)', round((float)$h1[0]['old_value'], 2), 30000.00);

    $ovRes2 = $runModel->lineOverrideSave($runId, $compId, $employeeId, PayrollRunModel::BASE_SALARY_OVERRIDE_CODE, 'override_amount', 27000.00, 'second edit', $userId, true);
    checkTrue('2nd override (same line, different value) succeeds', $ovRes2['status']);
    $h2 = $historyForRun();
    check('exactly 2 history rows after 2nd override', count($h2), 2);
    check('row 2 old_value chains from row 1\'s new_value (25000.00)', round((float)$h2[1]['old_value'], 2), 25000.00);
    check('row 2 new_value is 27000.00', round((float)$h2[1]['new_value'], 2), 27000.00);

    $rmRes = $runModel->lineOverrideRemove($runId, $compId, $employeeId, PayrollRunModel::BASE_SALARY_OVERRIDE_CODE, $userId, true);
    checkTrue('remove (revert to computed default) succeeds', $rmRes['status']);
    $h3 = $historyForRun();
    check('exactly 3 history rows after remove', count($h3), 3);
    check('row 3 action is restore', $h3[2]['action'], 'restore');
    check('row 3 old_value is the override that was just removed (27000.00)', round((float)$h3[2]['old_value'], 2), 27000.00);
    check('row 3 new_value is back to the original computed base salary (30000.00)', round((float)$h3[2]['new_value'], 2), 30000.00);

    // ---------- statutory override: bare item_code, line_type=statutory ----------
    echo "=== statutory (TH_SSO): line_type + bare item_code (not the wrapped sentinel) ===\n";
    $statRes = $runModel->statutoryLineOverrideSave($runId, $compId, $employeeId, 'TH_SSO', 'override_amount', 111.11, null, $userId, true);
    checkTrue('statutory override succeeds' . (empty($statRes['status']) ? " ({$statRes['message']})" : ''), $statRes['status']);
    $h4 = $historyForRun();
    $statRow = end($h4);
    check('statutory history row line_type is statutory', $statRow['line_type'], 'statutory');
    check('statutory history row item_code is the BARE code (TH_SSO), not the wrapped sentinel', $statRow['item_code'], 'TH_SSO');
    check('statutory history row new_value is 111.11', round((float)$statRow['new_value'], 2), 111.11);

    $statRmRes = $runModel->statutoryLineOverrideRemove($runId, $compId, $employeeId, 'TH_SSO', $userId, true);
    checkTrue('statutory remove succeeds', $statRmRes['status']);
    $h5 = $historyForRun();
    $statRmRow = end($h5);
    check('statutory remove history row line_type is still statutory', $statRmRow['line_type'], 'statutory');
    check('statutory remove history row item_code is still bare TH_SSO', $statRmRow['item_code'], 'TH_SSO');
    check('statutory remove history row action is restore', $statRmRow['action'], 'restore');

    // ---------- attendance overrides: per-field, skip-unchanged ----------
    echo "=== attendance: per-field history + skip-unchanged ===\n";
    $countBeforeAtt = count($historyForRun());
    $attRes1 = $runModel->attendanceOverrideSave($runId, $compId, $employeeId, ['late_mins' => 45], 'late correction', $userId, true);
    checkTrue('attendance override (1 field) succeeds' . (empty($attRes1['status']) ? " ({$attRes1['message']})" : ''), $attRes1['status']);
    $hAtt1 = $historyForRun();
    check('exactly 1 new history row for the 1 field that changed', count($hAtt1) - $countBeforeAtt, 1);
    $attRow1 = end($hAtt1);
    check('attendance history row line_type is attendance', $attRow1['line_type'], 'attendance');
    check('attendance history row item_code is the field name', $attRow1['item_code'], 'late_mins');
    check('attendance history row new_value is 45.00', round((float)$attRow1['new_value'], 2), 45.00);
    check('attendance history row old_value is null (no prior override existed for this field)', $attRow1['old_value'], null);

    // Same value again -- must NOT create a new history row (skip-unchanged).
    $attRes2 = $runModel->attendanceOverrideSave($runId, $compId, $employeeId, ['late_mins' => 45], null, $userId, true);
    checkTrue('re-saving the SAME value succeeds', $attRes2['status']);
    $hAtt2 = $historyForRun();
    check('NO new history row when the value did not actually change', count($hAtt2), count($hAtt1));

    // Different value -- must create exactly 1 more row, chained.
    $attRes3 = $runModel->attendanceOverrideSave($runId, $compId, $employeeId, ['late_mins' => 60], null, $userId, true);
    checkTrue('changing the value succeeds', $attRes3['status']);
    $hAtt3 = $historyForRun();
    check('exactly 1 more history row when the value genuinely changed', count($hAtt3) - count($hAtt2), 1);
    $attRow3 = end($hAtt3);
    check('chained old_value is the previous override (45.00)', round((float)$attRow3['old_value'], 2), 45.00);
    check('new new_value is 60.00', round((float)$attRow3['new_value'], 2), 60.00);

    $attRmRes = $runModel->attendanceOverrideRemove($runId, $compId, $employeeId, $userId, true);
    checkTrue('attendance remove succeeds', $attRmRes['status']);
    $hAtt4 = $historyForRun();
    check('exactly 1 more (restore) row after attendance remove', count($hAtt4) - count($hAtt3), 1);
    $attRmRow = end($hAtt4);
    check('attendance remove history row action is restore', $attRmRow['action'], 'restore');
    check('attendance remove history row old_value is the override that was removed (60.00)', round((float)$attRmRow['old_value'], 2), 60.00);

    // ---------- runAuditList() / lineOverrideAuditDiff() ----------
    echo "=== runAuditList() / lineOverrideAuditDiff() ===\n";
    $auditList = $runModel->runAuditList($compId);
    $ourRunRow = current(array_filter($auditList, fn($r) => (int)$r['id'] === $runId));
    checkTrue('runAuditList() includes this run', $ourRunRow !== false);
    checkTrue('edit_count is > 0 for this edited run', (int)$ourRunRow['edit_count'] > 0);
    check('origin is "cycle" (this fixture used a real payroll cycle, no sync_process_id)', $ourRunRow['origin'], 'cycle');
    checkTrue('history_available is true (real edits exist)', (bool)$ourRunRow['history_available']);

    // 2026-08-31, same-day follow-up, explicit request: "อยากให้เพิ่ม Filter ด้วยครับ" -- date_from/
    // date_to on runAuditList() (period_start_date/period_end_date, same overlap-range convention
    // every other List page's own Date filter already uses).
    echo "=== runAuditList() date_from/date_to filter ===\n";
    $filteredIn = $runModel->runAuditList($compId, '2027-07-01', '2027-07-31');
    checkTrue('a date range overlapping this run\'s own period (2027-07-21 to 2027-08-20) includes it', current(array_filter($filteredIn, fn($r) => (int)$r['id'] === $runId)) !== false);
    $filteredOut = $runModel->runAuditList($compId, '2099-01-01', '2099-12-31');
    checkTrue('a date range with no overlap excludes it', current(array_filter($filteredOut, fn($r) => (int)$r['id'] === $runId)) === false);

    $diff = $runModel->lineOverrideAuditDiff($runId, $compId);
    checkTrue('history_available is true for this run\'s diff', $diff['history_available']);
    checkTrue('diff has at least 3 distinct (employee,line_type,item_code) groups (base salary, TH_SSO, late_mins)', count($diff['lines']) >= 3);
    $baseSalaryGroup = current(array_filter($diff['lines'], fn($l) => $l['item_code'] === PayrollRunModel::BASE_SALARY_OVERRIDE_CODE));
    checkTrue('base salary group found in the diff', $baseSalaryGroup !== false);
    check('base salary group original_value is the FIRST recorded old_value (30000.00)', round((float)$baseSalaryGroup['original_value'], 2), 30000.00);
    check('base salary group has exactly 3 edits (override, override, restore)', count($baseSalaryGroup['edits']), 3);
    check('base salary group current_value reflects the reverted live figure (30000.00)', round((float)$baseSalaryGroup['current_value'], 2), 30000.00);

    // ---------- a run with ZERO edits: Original == Current, no misleading history_available=false ----------
    echo "=== a run with zero edits: history_available true (feature-era run), lines=[] ===\n";
    $cleanCreateRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'Line Override History Test Run (Clean, No Edits)',
        'period_start_date' => '2027-08-21', 'period_end_date' => '2027-09-20', 'payment_date' => '2027-08-25',
    ], $userId, true);
    checkTrue('fixture: clean run created', $cleanCreateRes['status']);
    $cleanRunId = $cleanCreateRes['id'];
    $runModel->recalculate($cleanRunId, $compId, $userId, true);
    $cleanDiff = $runModel->lineOverrideAuditDiff($cleanRunId, $compId);
    checkTrue('history_available is TRUE for a feature-era run even with zero edits (genuinely never edited, not "feature unavailable")', $cleanDiff['history_available']);
    check('lines is empty for a run with zero edits', count($cleanDiff['lines']), 0);

    // ---------- a run predating the feature: history_available=false ----------
    echo "=== a run predating LINE_OVERRIDE_HISTORY_FEATURE_START_DATE: history_available false ===\n";
    $pdo->prepare("UPDATE payroll_runs SET period_start_date = '2025-01-01' WHERE id = :id")->execute([':id' => $cleanRunId]);
    $preFeatureDiff = $runModel->lineOverrideAuditDiff($cleanRunId, $compId);
    checkTrue('history_available is FALSE for a pre-feature-date run with zero edit rows', !$preFeatureDiff['history_available']);
    check('lines is still empty (no fabricated data)', count($preFeatureDiff['lines']), 0);

} finally {
    $pdo->rollBack();
    echo "rolled back.\n";
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
