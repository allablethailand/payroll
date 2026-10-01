<?php
/**
 * Verification for the ad-hoc earning/deduction import (AdHocItemValidator / AdHocItemImportEntity, entity type adhoc_item) and the
 * batch rollback (PayrollRunModel::rollbackManualLineImportBatch()). Runs inside a transaction that is always rolled back,
 * on a throwaway company. Requires migration 2026-10-01_5_import_batch_rollback_support.
 *
 * Run with: php tests/adhoc_item_import_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/services/import/ImportService.php';
require_once __DIR__ . '/../app/models/SyncBatchModel.php';

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
function errorKey(callable $fn): ?string {
    try { $fn(); } catch (AdHocItemValidationException $e) { return $e->getErrorKey(); }
    return null;
}

try {
    $userId = 1;
    $insComp = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status)
        VALUES (:n, :n, 'TH', '1234567890123', 'Test Address', 'Tester', 'active')");
    $insComp->execute([':n' => 'ADH Test Co ' . uniqid()]);
    $compId = (int)$pdo->lastInsertId();
    $insComp->execute([':n' => 'ADH Other Co ' . uniqid()]);
    $otherComp = (int)$pdo->lastInsertId();

    $cycleSave = (new PayrollCycleModel($pdo))->save($compId, [
        'cycle_name' => 'ADH_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly', 'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    $cycleId = $cycleSave['id'];

    $pdo->prepare("INSERT INTO structure_departments (comp_id, department_code, department_name_th, department_name_en, status, created_by) VALUES (:c, :code, 'ทดสอบ', 'Test Dept', 'active', :u)")
        ->execute([':c' => $compId, ':code' => 'ADH_' . substr(uniqid(), -6), ':u' => $userId]);
    $deptId = (int)$pdo->lastInsertId();

    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, department_id)
        VALUES (:comp_id, :no, 'mr', 'male', 'ทดสอบ', 'ADH', 'Test', 'ADH', '1990-01-01', 'Thai',
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active', 0, 0, 0, :dept)");
    $newEmp = function (int $comp, string $no) use ($insEmp, $pdo, $deptId): int {
        $insEmp->execute([':comp_id' => $comp, ':no' => $no, ':email' => uniqid() . '@test.local', ':dept' => $deptId]);
        return (int)$pdo->lastInsertId();
    };
    $emp1 = $newEmp($compId, 'ADH-1');
    $emp2 = $newEmp($compId, 'ADH-2');
    $empOther = $newEmp($otherComp, 'ADH-X');

    $insType = $pdo->prepare("INSERT INTO payroll_earning_deduction_types (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method, tax_treatment, status, created_by)
        VALUES (:c, :code, 'ทดสอบ', 'Test item', 'earning', 'manual_entry', 'taxable', :st, :u)");
    $insType->execute([':c' => $compId, ':code' => 'ADH_BONUS', ':st' => 'active', ':u' => $userId]);
    $insType->execute([':c' => $compId, ':code' => 'ADH_OFF', ':st' => 'inactive', ':u' => $userId]);
    $insType->execute([':c' => $otherComp, ':code' => 'ADH_THEIRS', ':st' => 'active', ':u' => $userId]);

    $runModel = new PayrollRunModel($pdo);
    $mkRun = function (string $start, string $end) use ($runModel, $compId, $cycleId, $userId): int {
        $create = $runModel->create($compId, ['cycle_id' => $cycleId, 'run_name' => 'ADH_RUN_' . uniqid(), 'period_start_date' => $start, 'period_end_date' => $end, 'payment_date' => $end], $userId, true);
        if (empty($create['status'])) throw new RuntimeException('run create failed: ' . ($create['message'] ?? ''));
        $recalc = $runModel->recalculate($create['id'], $compId, $userId, true);
        if (empty($recalc['status'])) throw new RuntimeException('recalculate failed: ' . ($recalc['message'] ?? ''));
        return $create['id'];
    };
    $runMar = $mkRun('2026-03-01', '2026-03-31');
    $gross = fn(int $run, int $emp) => (float)$pdo->query("SELECT gross_amount FROM payroll_run_details WHERE run_id = {$run} AND employee_id = {$emp}")->fetchColumn();
    $lineCount = fn(?int $batch = null) => (int)$pdo->query("SELECT COUNT(*) FROM payroll_run_manual_lines WHERE run_id IN (SELECT id FROM payroll_runs WHERE comp_id = {$compId})"
        . ($batch !== null ? " AND import_batch_id = {$batch}" : ''))->fetchColumn();
    $baseGross = $gross($runMar, $emp1);
    check('fixture: the employee is in the March run', $baseGross > 0, true);

    $validator = new AdHocItemValidator($pdo);
    $row = ['employee_code' => 'ADH-1', 'item_code' => 'ADH_BONUS', 'amount' => '1,500.50', 'period_month' => '3', 'period_year' => '2026'];

    echo "=== template / entity ===\n";
    $svc = new ImportService($pdo);
    check('template exposes 8 columns incl. optional run_code', array_keys($svc->templateColumns('adhoc_item')),
        ['employee_code', 'item_code', 'amount', 'period_month', 'period_year', 'run_code', 'effective_date', 'note']);

    echo "=== validator ===\n";
    $ok = $validator->validate($compId, $row);
    check('valid row resolves employee, item, the only draft run and amount', [$ok['employee_id'], $ok['run_id'], $ok['amount'], $ok['item_code'], $ok['note']], [$emp1, $runMar, 1500.5, 'ADH_BONUS', null]);
    check('employee_code required', errorKey(fn() => $validator->validate($compId, ['employee_code' => ''] + $row)), 'employee_code_required');
    check('unknown employee code', errorKey(fn() => $validator->validate($compId, ['employee_code' => 'NOPE'] + $row)), 'employee_not_found');
    check('another company\'s employee is refused', errorKey(fn() => $validator->validate($compId, ['employee_code' => 'ADH-X'] + $row)), 'employee_not_found');
    check('unknown item code', errorKey(fn() => $validator->validate($compId, ['item_code' => 'NO_SUCH'] + $row)), 'item_not_found');
    check('inactive item code', errorKey(fn() => $validator->validate($compId, ['item_code' => 'ADH_OFF'] + $row)), 'item_not_found');
    check('another company\'s item is refused', errorKey(fn() => $validator->validate($compId, ['item_code' => 'ADH_THEIRS'] + $row)), 'item_not_found');
    foreach (['0', '-5', 'abc', ''] as $bad) {
        check("amount '{$bad}' rejected", errorKey(fn() => $validator->validate($compId, ['amount' => $bad] + $row)), 'amount_invalid');
    }
    check('month out of range', errorKey(fn() => $validator->validate($compId, ['period_month' => '13'] + $row)), 'period_invalid');
    check('year not numeric', errorKey(fn() => $validator->validate($compId, ['period_year' => '26'] + $row)), 'period_invalid');
    check('no draft run in the period', errorKey(fn() => $validator->validate($compId, ['period_month' => '7'] + $row)), 'no_draft_run_for_period');

    $insRun = $pdo->prepare("INSERT INTO payroll_runs (comp_id, cycle_id, run_code, run_name, period_start_date, period_end_date, payment_date, state, status) VALUES (:c, :cy, :code, :n, :ps, :pe, :pe, :st, 'active')");
    $insRun->execute([':c' => $compId, ':cy' => $cycleId, ':code' => 'ADH-PR-A', ':n' => 'ADH multi A', ':ps' => '2026-05-01', ':pe' => '2026-05-31', ':st' => 'draft']);
    $runMayA = (int)$pdo->lastInsertId();
    $insRun->execute([':c' => $compId, ':cy' => $cycleId, ':code' => 'ADH-PR-B', ':n' => 'ADH multi B', ':ps' => '2026-05-01', ':pe' => '2026-05-31', ':st' => 'draft']);
    $insRun->execute([':c' => $compId, ':cy' => $cycleId, ':code' => 'ADH-PR-C', ':n' => 'ADH approved', ':ps' => '2026-06-01', ':pe' => '2026-06-30', ':st' => 'approved']);
    $mayRow = ['period_month' => '5'] + $row;
    check('several draft runs and no run_code', errorKey(fn() => $validator->validate($compId, $mayRow)), 'multiple_draft_runs_for_period');
    check('run_code picks one of them', $validator->validate($compId, ['run_code' => 'ADH-PR-A'] + $mayRow)['run_id'], $runMayA);
    check('run_code that does not end in that month', errorKey(fn() => $validator->validate($compId, ['run_code' => 'ADH-PR-A', 'period_month' => '4'] + $row)), 'run_not_found');
    check('unknown run_code', errorKey(fn() => $validator->validate($compId, ['run_code' => 'ZZZ'] + $mayRow)), 'run_not_found');
    check('a run that is not a draft is refused by run_code', errorKey(fn() => $validator->validate($compId, ['run_code' => 'ADH-PR-C', 'period_month' => '6'] + $row)), 'invalid_run_status_for_import');
    check('and is not picked up without run_code (no draft that month)', errorKey(fn() => $validator->validate($compId, ['period_month' => '6'] + $row)), 'no_draft_run_for_period');

    check('effective_date outside the run period', errorKey(fn() => $validator->validate($compId, ['effective_date' => '2026-04-01'] + $row)), 'effective_date_out_of_payroll_period');
    check('effective_date with a bad format', errorKey(fn() => $validator->validate($compId, ['effective_date' => '15/03/2026'] + $row)), 'effective_date_invalid');
    check('effective_date inside the period is appended to the note', $validator->validate($compId, ['effective_date' => '2026-03-15', 'note' => 'Spot bonus'] + $row)['note'], 'Spot bonus (Effective: 2026-03-15)');
    check('effective_date with no note', $validator->validate($compId, ['effective_date' => '2026-03-31'] + $row)['note'], '(Effective: 2026-03-31)');
    check('note alone is kept as is', $validator->validate($compId, ['note' => 'Just a note'] + $row)['note'], 'Just a note');

    echo "=== dry run ===\n";
    $before = $lineCount();
    $prev = $svc->preview($compId, 'adhoc_item', [$row, ['employee_code' => 'NOPE'] + $row], $userId);
    check('preview reports 1 ok / 1 error and the error names the rule', [$prev['success'], $prev['error'], str_starts_with($prev['errors'][0]['message'], 'employee_not_found:')], [1, 1, true]);
    check('preview writes nothing and recalculates nothing', [$lineCount(), $gross($runMar, $emp1)], [$before, $baseGross]);

    echo "=== commit ===\n";
    $rows = [$row, ['employee_code' => 'ADH-2', 'amount' => '200'] + $row, ['employee_code' => 'ADH-1', 'note' => 'second line', 'amount' => '100'] + $row, ['item_code' => 'BAD'] + $row];
    $res = $svc->commit($compId, 'adhoc_item', $rows, $userId);
    $batch = (int)$res['batch_id'];
    check('commit: 3 lines written, the bad row reported', [$res['success'], $res['error']], [3, 1]);
    check('lines are tagged with the sync batch', $lineCount($batch), 3);
    check('the run was recalculated: gross rose by the imported amounts', [round($gross($runMar, $emp1) - $baseGross, 2), $gross($runMar, $emp2) > 0], [1600.5, true]);
    $audit = $pdo->query("SELECT COUNT(*) FROM payroll_run_audit_logs WHERE run_id = {$runMar} AND action = 'add_manual_line'")->fetchColumn();
    check('each line left its audit note', (int)$audit, 3);

    echo "=== a row the run itself refuses ===\n";
    $res2 = $svc->preview($compId, 'adhoc_item', [['employee_code' => 'ADH-1', 'period_month' => '5', 'run_code' => 'ADH-PR-A'] + $row], $userId);
    check('employee not in that run: row error from PayrollRunModel, not a crash', [$res2['success'], $res2['error']], [0, 1]);

    echo "=== rollback ===\n";
    $pdo->prepare("UPDATE payroll_runs SET state = 'pending_approval' WHERE id = ?")->execute([$runMar]);
    $refused = $runModel->rollbackManualLineImportBatch($batch, $compId, $userId, true);
    check('refused when the run is no longer a draft, nothing touched', [$refused['status'], $lineCount($batch)], [false, 3]);
    $pdo->prepare("UPDATE payroll_runs SET state = 'draft' WHERE id = ?")->execute([$runMar]);
    $other = $runModel->rollbackManualLineImportBatch($batch, $otherComp, $userId, true);
    check('another company cannot roll it back', [$other['status'], $lineCount($batch)], [false, 3]);
    $done = $runModel->rollbackManualLineImportBatch($batch, $compId, $userId, true);
    check('rollback removes the batch lines and recalculates the run once', [$done['status'], $done['lines_removed'], $done['runs_recalculated'], $lineCount($batch)], [true, 3, 1, 0]);
    check('gross is back to what it was', $gross($runMar, $emp1), $baseGross);
    $again = $runModel->rollbackManualLineImportBatch($batch, $compId, $userId, true);
    check('rolling back twice says nothing is left', $again['status'], false);

    echo "=== hand-made lines are not touched ===\n";
    $manual = $runModel->addManualLine($runMar, $compId, $emp1, (int)$pdo->query("SELECT id FROM payroll_earning_deduction_types WHERE comp_id = {$compId} AND item_code = 'ADH_BONUS'")->fetchColumn(), 50.0, $userId, true);
    $res3 = $svc->commit($compId, 'adhoc_item', [$row], $userId);
    $runModel->rollbackManualLineImportBatch((int)$res3['batch_id'], $compId, $userId, true);
    check('a line added by hand (no batch) survives another batch\'s rollback', [(int)$pdo->query("SELECT COUNT(*) FROM payroll_run_manual_lines WHERE run_id = {$runMar} AND import_batch_id IS NULL")->fetchColumn(), !empty($manual['status'])], [1, true]);

    echo "\n--------------------------------------------------\n";
    echo "Passed: {$passes}, Failed: {$failures}\n";
    echo $failures > 0 ? "SOME TESTS FAILED\n" : "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
} catch (Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
exit($failures > 0 ? 1 : 0);
