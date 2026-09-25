<?php
/**
 * 2026-09-19, H-backend: payroll_run_line_override_history now carries 3 kinds of edit, not 1.
 * What this locks:
 *
 *  1. every writer records -- line overrides (the only one that ever did), the hand-added manual
 *     lines, and the per-run tri-state tax/SSO answer. The last 2 left no structured before/after
 *     anywhere at all until today, only a free-text payroll_run_audit_logs note;
 *  2. the 2 suppression rules are one rule, applied to all of them: an edit that changes nothing
 *     (same figure, or same word) writes no row, and neither does undoing something that was never
 *     there. Both used to exist inline in the attendance writer and nowhere else;
 *  3. the new per-line endpoint's own shape: newest first, and the fields a caller is promised;
 *  4. THE OLD ENDPOINT IS UNMOVED. lineOverrideAuditDiff() and runAuditList()'s edit_count still
 *     see overrides only -- the Adjustments table's History column and the Payroll Run Audit report
 *     are built on those, and this round does not touch a single line of UI.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back, and builds its own employees/run: nothing here reads or
 * writes run 752, EM009, or any other real row.
 * Run with: php tests/line_override_history_sources_test.php
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
    // Same dev-DB isolation reasoning as tests/exemption_tri_state_test.php's own comment: leftover
    // placeholder employees at comp_id=1 would otherwise join any run this script creates.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :c AND deleted_at IS NULL")->execute([':c' => $compId]);

    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_end_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, has_spouse)
        VALUES (:c, :no, 'mr', 'male', 'ทดสอบ', 'ประวัติ', 'Test', 'History', '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address', 'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', NULL, 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active', 1, 0, 0, 0)");
    $insEmp->execute([':c' => $compId, ':no' => 'TEST_HSRC_' . uniqid(), ':email' => uniqid() . '@test.local']);
    $empId = (int)$pdo->lastInsertId();

    $runModel = new PayrollRunModel($pdo);
    $r = $runModel->create($compId, [
        'run_purpose' => 'incentive', 'compute_statutory' => 1, 'run_name' => 'HSRC_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +9 months')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +9 months')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +9 months')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('fixture: run created' . (empty($r['status']) ? " ({$r['message']})" : ''), (bool)$r['status']);
    $runId = (int)$r['id'];
    $runModel->joinEmployees($runId, $compId, [$empId], $adminUserId, true);
    $bonusId = (int)$pdo->query("SELECT id FROM payroll_earning_deduction_types WHERE comp_id = {$compId} AND item_code = 'BONUS' AND deleted_at IS NULL")->fetchColumn();
    checkTrue('fixture: BONUS catalog item exists', $bonusId > 0);

    /** Every history row this run has recorded, newest first -- read straight off the table so a
     *  test failure points at what was written, not at what an endpoint chose to show. */
    $rows = function (?string $sourceType = null) use ($pdo, $runId): array {
        $sql = "SELECT id, line_type, source_type, source_id, item_code, action, old_value, old_value_text, new_value, new_value_text, note
            FROM `payroll_run_line_override_history` WHERE run_id = :r";
        if ($sourceType !== null) { $sql .= " AND source_type = " . $pdo->quote($sourceType); }
        $s = $pdo->prepare($sql . " ORDER BY id DESC");
        $s->execute([':r' => $runId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    };
    $count = fn(?string $t = null): int => count($rows($t));
    $latest = fn(?string $t = null): ?array => $rows($t)[0] ?? null;

    echo "=== 1. manual lines: add / edit / delete ===\n";
    $before = $count();
    $add = $runModel->addManualLine($runId, $compId, $empId, $bonusId, 40000, $adminUserId, true, 'first note');
    checkTrue('add succeeds' . (empty($add['status']) ? " ({$add['message']})" : ''), (bool)$add['status']);
    check('...and wrote exactly 1 history row', $count() - $before, 1);
    $row = $latest();
    check('...source_type is manual_line', $row['source_type'], 'manual_line');
    check('...action is add', $row['action'], 'add');
    check('...item_code is the catalog code the slip addresses the row by', $row['item_code'], 'BONUS');
    check('...line_type stays earning_deduction (no new enum value needed)', $row['line_type'], 'earning_deduction');
    check('...old_value is null, there was no line before', $row['old_value'], null);
    check('...new_value is the amount', round((float)$row['new_value'], 2), 40000.00);
    check('...the line note rides along', $row['note'], 'first note');
    $lineId = (int)$row['source_id'];
    checkTrue('...source_id is the manual line PK', $lineId > 0);
    $realLineId = (int)$pdo->query("SELECT id FROM payroll_run_manual_lines WHERE run_id = {$runId} AND employee_id = {$empId} ORDER BY id DESC LIMIT 1")->fetchColumn();
    check('...and it is the row that was really inserted', $lineId, $realLineId);

    $before = $count();
    checkTrue('edit to a different amount succeeds',
        (bool)$runModel->updateManualLine($runId, $compId, $lineId, $empId, $bonusId, 45000, $adminUserId, true, 'second note')['status']);
    check('...and wrote 1 row', $count() - $before, 1);
    $row = $latest();
    check('...action is edit', $row['action'], 'edit');
    check('...old_value is the figure the UPDATE overwrote', round((float)$row['old_value'], 2), 40000.00);
    check('...new_value is the new figure', round((float)$row['new_value'], 2), 45000.00);
    check('...source_id still addresses the same line', (int)$row['source_id'], $lineId);

    $before = $count();
    checkTrue('edit that leaves the amount alone still succeeds',
        (bool)$runModel->updateManualLine($runId, $compId, $lineId, $empId, $bonusId, 45000, $adminUserId, true, 'third note')['status']);
    check('...but writes NOTHING (nothing measurable changed -- see BACKLOG for the gap)', $count() - $before, 0);

    $before = $count();
    checkTrue('delete succeeds', (bool)$runModel->removeManualLine($runId, $compId, $lineId, $adminUserId, true)['status']);
    check('...and wrote 1 row', $count() - $before, 1);
    $row = $latest();
    check('...action is delete', $row['action'], 'delete');
    check('...old_value is what was removed', round((float)$row['old_value'], 2), 45000.00);
    check('...new_value is null, the line is gone', $row['new_value'], null);
    checkTrue('...the history outlives the hard-deleted line (no FK cascade)',
        (int)$pdo->query("SELECT COUNT(*) FROM payroll_run_manual_lines WHERE id = {$lineId}")->fetchColumn() === 0
        && (int)$row['source_id'] === $lineId);

    echo "\n=== 2. a custom 'Other' manual line keeps the aggregate code, not the typed label ===\n";
    $before = $count();
    checkTrue('custom Other deduction added',
        (bool)$runModel->addManualLine($runId, $compId, $empId, null, 500, $adminUserId, true, null, 'ค่าปรับผิดสัญญา', 'deduction', null, null, null, null, true)['status']);
    check('...1 row', $count() - $before, 1);
    check('...item_code is the shared OTHER_DEDUCTION bucket', $latest()['item_code'], 'OTHER_DEDUCTION');
    $otherLineId = (int)$latest()['source_id'];
    checkTrue('plain custom (not Other) added',
        (bool)$runModel->addManualLine($runId, $compId, $empId, null, 700, $adminUserId, true, null, 'ค่าปรับอื่น', 'deduction', null, null, null, null, false)['status']);
    check('...item_code is the CUSTOM: form', $latest()['item_code'], 'CUSTOM:ค่าปรับอื่น');
    $plainCustomLineId = (int)$latest()['source_id'];
    $runModel->removeManualLine($runId, $compId, $otherLineId, $adminUserId, true);
    $runModel->removeManualLine($runId, $compId, $plainCustomLineId, $adminUserId, true);

    echo "\n=== 3. the tri-state exemption answer ===\n";
    $before = $count('exemption');
    checkTrue('tax=no / sso=inherit saves',
        (bool)$runModel->saveEmployeeExemption($runId, $compId, $empId, 'no', 'inherit', 'why', $adminUserId, true)['status']);
    check('...1 row: only the half that moved', $count('exemption') - $before, 1);
    $row = $latest('exemption');
    check('...item_code is the bare statutory code', $row['item_code'], 'TH_PIT');
    checkTrue('...never the statutoryOverrideCode() sentinel', strpos((string)$row['item_code'], '__statutory') === false);
    check('...line_type is statutory', $row['line_type'], 'statutory');
    check('...action is exemption_change', $row['action'], 'exemption_change');
    check('...the answer before it', $row['old_value_text'], 'inherit');
    check('...and after', $row['new_value_text'], 'no');
    check('...the decimals stay empty -- this row is a word, not a figure', [$row['old_value'], $row['new_value']], [null, null]);
    check('...the note rides along', $row['note'], 'why');

    $before = $count('exemption');
    checkTrue('saving the identical pair again succeeds',
        (bool)$runModel->saveEmployeeExemption($runId, $compId, $empId, 'no', 'inherit', null, $adminUserId, true)['status']);
    check('...and writes nothing at all', $count('exemption') - $before, 0);

    $before = $count('exemption');
    checkTrue('moving the OTHER half saves',
        (bool)$runModel->saveEmployeeExemption($runId, $compId, $empId, 'no', 'yes', null, $adminUserId, true)['status']);
    check('...1 row, for that half only', $count('exemption') - $before, 1);
    check('...and it is the SSO one', $latest('exemption')['item_code'], 'TH_SSO');

    $before = $count('exemption');
    checkTrue('clearing both back to inherit saves',
        (bool)$runModel->saveEmployeeExemption($runId, $compId, $empId, 'inherit', 'inherit', null, $adminUserId, true)['status']);
    check('...2 rows, both halves really moved', $count('exemption') - $before, 2);
    check('...the row deletion is recorded as a change TO inherit, not as an absence',
        array_column(array_slice($rows('exemption'), 0, 2), 'new_value_text'), ['inherit', 'inherit']);

    echo "\n=== 4. line overrides: unchanged behaviour, plus the 2 suppression rules ===\n";
    $before = $count('override');
    checkTrue('an override on base salary saves',
        (bool)$runModel->lineOverrideSave($runId, $compId, $empId, PayrollRunModel::BASE_SALARY_OVERRIDE_CODE, 'override_amount', 25000, null, $adminUserId, true)['status']);
    check('...1 row', $count('override') - $before, 1);
    check('...source_type defaults to override', $latest('override')['source_type'], 'override');
    check('...source_id is null -- an override has no row of its own to point at', $latest('override')['source_id'], null);

    $before = $count('override');
    checkTrue('saving the SAME amount again succeeds',
        (bool)$runModel->lineOverrideSave($runId, $compId, $empId, PayrollRunModel::BASE_SALARY_OVERRIDE_CODE, 'override_amount', 25000, null, $adminUserId, true)['status']);
    check('...and writes nothing', $count('override') - $before, 0);

    $before = $count('override');
    checkTrue('removing a real override succeeds',
        (bool)$runModel->lineOverrideRemove($runId, $compId, $empId, PayrollRunModel::BASE_SALARY_OVERRIDE_CODE, $adminUserId, true)['status']);
    check('...1 restore row', $count('override') - $before, 1);
    check('...action is restore', $latest('override')['action'], 'restore');

    $before = $count('override');
    checkTrue('removing an override that is not there still answers ok',
        (bool)$runModel->lineOverrideRemove($runId, $compId, $empId, PayrollRunModel::BASE_SALARY_OVERRIDE_CODE, $adminUserId, true)['status']);
    check('...but writes no history -- there was nothing to undo', $count('override') - $before, 0);

    echo "\n=== 5. the new per-line endpoint ===\n";
    $routes = file_get_contents(__DIR__ . '/../index.php');
    checkTrue("route api/payroll-run.line-history -> PayrollController@lineHistory",
        strpos($routes, "\$router->get('api/payroll-run.line-history', 'PayrollController@lineHistory');") !== false);
    $controllerSrc = file_get_contents(__DIR__ . '/../app/controllers/PayrollController.php');
    checkTrue('PayrollController::lineHistory() exists', strpos($controllerSrc, 'public function lineHistory()') !== false);
    $methodBody = substr($controllerSrc, (int)strpos($controllerSrc, 'public function lineHistory()'));
    $methodBody = substr($methodBody, 0, (int)strpos($methodBody, 'public function index()'));
    checkTrue('it is gated by the same read access as its sibling', strpos($methodBody, 'requireViewAccess') !== false);
    checkTrue('it resolves the reader\'s salary visibility', strpos($methodBody, 'resolveSalaryVisibility') !== false);
    // The rule is shared, not re-typed: a second copy of the masking loop is how two endpoints drift.
    checkTrue('it masks through the shared maskMonetaryKeys()', strpos($methodBody, 'maskMonetaryKeys') !== false);

    $all = $runModel->lineHistoryRows($runId, $compId, $empId);
    check('it returns every recorded edit for this employee', count($all), $count());
    $ids = array_column($all, 'id');
    $sorted = $ids; rsort($sorted);
    check('...newest first', $ids, $sorted);
    check('...every promised field is on the row', array_keys($all[0]),
        ['id', 'action', 'source_type', 'source_id', 'line_type', 'item_code', 'old_value', 'new_value',
         'old_text', 'new_text', 'note', 'changed_by', 'changed_by_name_th', 'changed_by_name_en', 'changed_at']);

    $byManual = $runModel->lineHistoryRows($runId, $compId, $empId, null, null, 'manual_line');
    checkTrue('filtering by source_type returns only those', $byManual !== []
        && array_values(array_unique(array_column($byManual, 'source_type'))) === ['manual_line']);
    $byRow = $runModel->lineHistoryRows($runId, $compId, $empId, null, null, 'manual_line', $lineId);
    check('...and adding source_id narrows to one line\'s 3 entries (add, edit, delete)', count($byRow), 3);
    check('...in that order, newest first', array_column($byRow, 'action'), ['delete', 'edit', 'add']);
    $byLine = $runModel->lineHistoryRows($runId, $compId, $empId, 'statutory', 'TH_SSO');
    checkTrue('filtering by (line_type, item_code) works for the tri-state rows', $byLine !== []
        && array_values(array_unique(array_column($byLine, 'item_code'))) === ['TH_SSO']);
    check('a run from another company is not readable through this', $runModel->lineHistoryRows($runId, $compId + 999, $empId), []);

    echo "\n=== 6. the OLD endpoint is unmoved ===\n";
    $diff = $runModel->lineOverrideAuditDiff($runId, $compId, $empId);
    $seenSources = [];
    foreach ($diff['lines'] as $l) { $seenSources[] = $l['line_type'] . '|' . $l['item_code']; }
    checkTrue('it still shows the base-salary override chain', in_array('earning_deduction|' . PayrollRunModel::BASE_SALARY_OVERRIDE_CODE, $seenSources, true));
    checkTrue('...and shows NO manual line', !in_array('earning_deduction|BONUS', $seenSources, true));
    checkTrue('...and NO tri-state row', !in_array('statutory|TH_PIT', $seenSources, true) && !in_array('statutory|TH_SSO', $seenSources, true));
    check('its edits carry exactly the keys the Adjustments column reads', array_keys($diff['lines'][0]['edits'][0]),
        ['action', 'old_value', 'new_value', 'note', 'changed_by', 'changed_by_name_th', 'changed_by_name_en', 'changed_at']);
    check('...oldest first, as it always was', array_column($diff['lines'][0]['edits'], 'action'), ['override', 'restore']);

    $auditRow = null;
    foreach ($runModel->runAuditList($compId) as $ar) { if ((int)$ar['id'] === $runId) { $auditRow = $ar; break; } }
    checkTrue('the run is on the audit report', $auditRow !== null);
    check('...and its edit count is the OVERRIDE count, not the whole table', $auditRow['edit_count'], $count('override'));
    checkTrue('...which is genuinely fewer than everything recorded', $count('override') < $count());
} finally {
    $pdo->rollBack();
}

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
