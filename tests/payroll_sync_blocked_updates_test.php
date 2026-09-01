<?php
/**
 * Lightweight verification script for the "blocked sync update" guard (explicit report from the
 * Origami dev team about their new "pull back and re-edit" admin action surfacing a real gap on
 * our own receiving side) -- PayrollSyncModel::ingest()'s new guard,
 * blockedUpdatesList()/applyBlockedUpdate()/dismissBlockedUpdate(). Not PHPUnit -- see
 * tests/statutory_engine_test.php for why. Runs against the real dev DB inside a transaction that
 * is always rolled back. Uses a fresh throwaway company, not comp_id=1.
 * Run with: php tests/payroll_sync_blocked_updates_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/PayrollSyncModel.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/controllers/PayrollSyncController.php';
require_once __DIR__ . '/../app/helpers/helpers.php';

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
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }

try {
    $userId = 1;
    $compCode = 'BSU_' . uniqid();
    $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', :comp_code)")
        ->execute([':name' => 'Blocked Sync Update Test Co ' . uniqid(), ':comp_code' => $compCode]);
    $compId = (int)$pdo->lastInsertId();

    // A can_process_payroll role, so createForPermissionHolders() ('sync_update_blocked' below) has
    // a real recipient to fan out to -- otherwise that best-effort notification legitimately has
    // nobody to notify and the assertion on it would be meaningless, not because the feature is
    // broken.
    $pdo->prepare("INSERT INTO structure_roles (comp_id, role_name_th, role_name_en, can_process_payroll, status)
        VALUES (:comp_id, 'BSU Payroll Admin', 'BSU Payroll Admin', 1, 'active')")
        ->execute([':comp_id' => $compId]);
    $roleId = (int)$pdo->lastInsertId();

    $empNo = 'BSU_EMP_' . uniqid();
    $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status, role_id)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'BSU', 'Test', 'BSU', '1990-01-01', 'Thai',
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', 30000, '2020-01-01', 'average', 'active', :role_id)")
        ->execute([':comp_id' => $compId, ':employee_no' => $empNo, ':email' => uniqid() . '@test.local', ':role_id' => $roleId]);
    $employeeId = (int)$pdo->lastInsertId();

    function bsuPayload(string $compCode, string $empCode, int $processId, float $otValue): array {
        return [
            'schema_version' => 1,
            'process_id' => $processId,
            'process_no' => 'ORIGAMI-BSU-' . $processId,
            'comp_code' => $compCode,
            'comp_name' => 'BSU Test Co. (Origami name)',
            'frequency_type' => 'monthly',
            'items' => [[
                'report_item_id' => 1, 'emp_id' => 101, 'emp_code' => 'E-101', 'emp_name' => 'Test Employee',
                'payroll_code' => $empCode, 'pay_type' => 'transfer', 'deduct_sso' => true,
                'working_days' => 22, 'working_mins' => 10560, 'late_mins' => 15,
                'item_values' => [
                    ['item_id' => 4, 'item_code' => 'OT', 'item_name' => 'Overtime', 'item_type' => 'INCOME', 'unit_type' => 'hours', 'value' => $otValue, 'remark' => null],
                ],
            ]],
            'employee_status' => [],
        ];
    }

    $model = new PayrollSyncModel($pdo);
    $processId = random_int(100000, 999999);

    // ---------- Fresh ingest -- normal upsert, guard does not trigger for a never-pulled process ----------
    echo "=== Fresh ingest (not yet pulled into any run) ===\n";
    $payload1 = bsuPayload($compCode, $empNo, $processId, 2.0);
    $res1 = $model->ingest($payload1);
    checkTrue('fresh ingest succeeds' . (empty($res1['status']) ? " ({$res1['message']})" : ''), $res1['status']);
    checkFalse('fresh ingest is NOT flagged as blocked', $res1['blocked'] ?? false);
    $processRowId = $res1['process_row_id'];

    $itemsBefore = $pdo->query("SELECT ot_req_working_day_hrs, item_values FROM payroll_sync_items WHERE process_id = {$processRowId}")->fetch(PDO::FETCH_ASSOC);
    checkTrue('fixture: sync item row exists after fresh ingest', $itemsBefore !== false);

    // Re-ingest the SAME process_id BEFORE it's linked to any run -- must behave exactly as before
    // (normal idempotent overwrite, not blocked) -- proves the new guard is scoped correctly.
    echo "=== Re-ingest before being pulled into a run -- normal overwrite, not blocked ===\n";
    $res1b = $model->ingest(bsuPayload($compCode, $empNo, $processId, 3.5));
    checkTrue('re-ingest before any pull succeeds' . (empty($res1b['status']) ? " ({$res1b['message']})" : ''), $res1b['status']);
    checkFalse('re-ingest before any pull is NOT blocked', $res1b['blocked'] ?? false);
    $processRowCount = (int)$pdo->query("SELECT COUNT(*) FROM payroll_sync_processes WHERE origami_process_id = {$processId}")->fetchColumn();
    check('still exactly 1 process row (idempotent upsert, not a duplicate)', $processRowCount, 1);

    // ---------- Pull into a run, then re-push -- guard must now block ----------
    echo "=== Pull into a draft run, then re-push the same process_id -- BLOCKED ===\n";
    $cycleSave = (new PayrollCycleModel($pdo))->save($compId, [
        'cycle_name' => 'BSU_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    $cycleId = $cycleSave['id'];
    $runModel = new PayrollRunModel($pdo);
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'BSU Test Run',
        'period_start_date' => '2027-07-21', 'period_end_date' => '2027-08-20', 'payment_date' => '2027-07-25',
        'sync_process_id' => $processRowId,
    ], $userId, true);
    checkTrue('pulling the process into a draft run succeeds' . (empty($createRes['status']) ? " ({$createRes['message']})" : ''), $createRes['status']);
    $runId = $createRes['id'];

    $blockedNotifCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type = 'sync_update_blocked'")->fetchColumn();

    $res2 = $model->ingest(bsuPayload($compCode, $empNo, $processId, 999.0));
    checkTrue('re-push after being pulled into a run still returns status:true (never a retryable error)' . (empty($res2['status']) ? " ({$res2['message']})" : ''), $res2['status']);
    checkTrue('re-push after being pulled is now flagged blocked:true', $res2['blocked'] ?? false);
    check('blocked response reports the correct linked_run_id', $res2['linked_run_id'] ?? null, $runId);
    $blockedUpdateId = $res2['blocked_update_id'];

    $itemsAfterBlockedPush = $pdo->query("SELECT ot_req_working_day_hrs, item_values FROM payroll_sync_items WHERE process_id = {$processRowId}")->fetch(PDO::FETCH_ASSOC);
    checkTrue('payroll_sync_items row still present after a blocked push (not wiped)', $itemsAfterBlockedPush !== false);
    // Direct proof: the OT value in item_values must still be the ORIGINAL one (3.5, from the last
    // successful pre-pull ingest), NOT the blocked push's 999.0 -- i.e. source data was NOT
    // silently overwritten.
    $itemValuesDecoded = json_decode((string)$itemsAfterBlockedPush['item_values'], true);
    $otAfterBlock = current(array_filter($itemValuesDecoded, fn($v) => $v['item_code'] === 'OT'))['value'] ?? null;
    check('the OT figure is still 3.5 (pre-block value), NOT 999.0 (the blocked push value)', (float)$otAfterBlock, 3.5);

    $blockedRow = $pdo->query("SELECT * FROM payroll_sync_blocked_updates WHERE id = {$blockedUpdateId}")->fetch(PDO::FETCH_ASSOC);
    checkTrue('a payroll_sync_blocked_updates row was created', $blockedRow !== false);
    check('blocked row status is pending', $blockedRow['status'], 'pending');
    check('blocked row process_row_id matches', (int)$blockedRow['process_row_id'], $processRowId);
    check('blocked row linked_run_id matches', (int)$blockedRow['linked_run_id'], $runId);
    checkTrue('blocked row preserved the attempted payload (not discarded)', strpos((string)$blockedRow['attempted_payload'], '999') !== false);

    $blockedNotifCountAfter = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type = 'sync_update_blocked'")->fetchColumn();
    check('a sync_update_blocked notification was created', $blockedNotifCountAfter - $blockedNotifCountBefore, 1);

    // ---------- blockedUpdatesList() ----------
    echo "=== blockedUpdatesList() ===\n";
    $list = $model->blockedUpdatesList($compId);
    checkTrue('blockedUpdatesList() includes this pending update', current(array_filter($list, fn($r) => (int)$r['id'] === $blockedUpdateId)) !== false);

    // ---------- A SECOND re-push while the first is still pending -- own new row, not overwritten ----------
    echo "=== A 2nd re-push while the 1st blocked update is still pending -- own separate row ===\n";
    $res3 = $model->ingest(bsuPayload($compCode, $empNo, $processId, 1234.0));
    checkTrue('2nd blocked push also succeeds (status:true)', $res3['status']);
    checkTrue('2nd push is also blocked', $res3['blocked'] ?? false);
    checkTrue('2nd push got its OWN blocked_update_id, not reusing the 1st', $res3['blocked_update_id'] !== $blockedUpdateId);
    $pendingCountNow = (int)$pdo->query("SELECT COUNT(*) FROM payroll_sync_blocked_updates WHERE process_row_id = {$processRowId} AND status = 'pending'")->fetchColumn();
    check('exactly 2 pending blocked rows now queued for this process', $pendingCountNow, 2);
    $secondBlockedUpdateId = $res3['blocked_update_id'];

    // ---------- dismissBlockedUpdate() on the FIRST one ----------
    echo "=== dismissBlockedUpdate() ===\n";
    $dismissRes = $model->dismissBlockedUpdate($blockedUpdateId, $compId, $userId);
    checkTrue('dismiss succeeds' . (empty($dismissRes['status']) ? " ({$dismissRes['message']})" : ''), $dismissRes['status']);
    $dismissedRow = $pdo->query("SELECT status, resolved_by FROM payroll_sync_blocked_updates WHERE id = {$blockedUpdateId}")->fetch(PDO::FETCH_ASSOC);
    check('dismissed row status is dismissed', $dismissedRow['status'], 'dismissed');
    check('dismissed row records who resolved it', (int)$dismissedRow['resolved_by'], $userId);
    $itemsAfterDismiss = $pdo->query("SELECT item_values FROM payroll_sync_items WHERE process_id = {$processRowId}")->fetch(PDO::FETCH_ASSOC);
    check('dismiss does NOT touch payroll_sync_items at all', $itemsAfterDismiss['item_values'], $itemsAfterBlockedPush['item_values']);

    $dismissAgainRes = $model->dismissBlockedUpdate($blockedUpdateId, $compId, $userId);
    checkFalse('dismissing an already-resolved row a second time is refused, not a silent no-op', $dismissAgainRes['status']);

    // ---------- applyBlockedUpdate() on the SECOND one ----------
    echo "=== applyBlockedUpdate() ===\n";
    $applyRes = $model->applyBlockedUpdate($secondBlockedUpdateId, $compId, $userId);
    checkTrue('apply succeeds' . (empty($applyRes['status']) ? " ({$applyRes['message']})" : ''), $applyRes['status']);
    $appliedRow = $pdo->query("SELECT status, resolved_by FROM payroll_sync_blocked_updates WHERE id = {$secondBlockedUpdateId}")->fetch(PDO::FETCH_ASSOC);
    check('applied row status is applied', $appliedRow['status'], 'applied');
    check('applied row records who resolved it', (int)$appliedRow['resolved_by'], $userId);

    $itemsAfterApply = $pdo->query("SELECT item_values FROM payroll_sync_items WHERE process_id = {$processRowId}")->fetch(PDO::FETCH_ASSOC);
    $itemValuesAfterApply = json_decode((string)$itemsAfterApply['item_values'], true);
    $otAfterApply = current(array_filter($itemValuesAfterApply, fn($v) => $v['item_code'] === 'OT'))['value'] ?? null;
    check('applying the 2nd blocked update now DOES overwrite payroll_sync_items (OT is now 1234.0, the applied payload\'s own value)', (float)$otAfterApply, 1234.0);

    $applyAgainRes = $model->applyBlockedUpdate($secondBlockedUpdateId, $compId, $userId);
    checkFalse('applying an already-resolved row a second time is refused, not a silent no-op', $applyAgainRes['status']);

    $listAfterResolve = $model->blockedUpdatesList($compId);
    check('blockedUpdatesList() is now empty (both entries resolved)', count($listAfterResolve), 0);

    // ---------- Cancelling the run releases the process -- re-push after that is NOT blocked ----------
    echo "=== Cancel the linked run -- process is released, next re-push is normal (not blocked) ===\n";
    $cancelRes = $runModel->cancel($runId, $compId, $userId, true, 'Test cancel reason for BSU');
    checkTrue('cancelling the run succeeds' . (empty($cancelRes['status']) ? " ({$cancelRes['message']})" : ''), $cancelRes['status']);
    $res4 = $model->ingest(bsuPayload($compCode, $empNo, $processId, 55.0));
    checkTrue('re-push after the linked run was cancelled succeeds' . (empty($res4['status']) ? " ({$res4['message']})" : ''), $res4['status']);
    checkFalse('re-push after cancellation is NOT blocked (process was released back to Pending Pull)', $res4['blocked'] ?? false);
    $itemsAfterCancel = $pdo->query("SELECT item_values FROM payroll_sync_items WHERE process_id = {$processRowId}")->fetch(PDO::FETCH_ASSOC);
    $itemValuesAfterCancel = json_decode((string)$itemsAfterCancel['item_values'], true);
    $otAfterCancel = current(array_filter($itemValuesAfterCancel, fn($v) => $v['item_code'] === 'OT'))['value'] ?? null;
    check('normal overwrite applies once released (OT is now 55.0)', (float)$otAfterCancel, 55.0);

    // ---------- Real pre-existing bug fix: PayrollSyncController::requirePermission() now exists ----------
    echo "=== PayrollSyncController::requirePermission() (real pre-existing bug fix) ===\n";
    $_SESSION['user'] = ['employee_id' => $userId, 'role' => 'admin', 'company_id' => $compId];
    $controller = new PayrollSyncController();
    $refMethod = new ReflectionMethod(PayrollSyncController::class, 'requirePermission');
    $refMethod->setAccessible(true);
    // Before this fix, this call would have been a hard PHP fatal error ("Call to undefined
    // method") -- simply completing without throwing already proves the method now exists.
    // isAdmin=true (session role='admin') bypasses the underlying permission check entirely, so
    // this specifically proves the METHOD EXISTS AND RUNS, not any particular permission grant.
    $allowed = $refMethod->invoke($controller, 'payroll_sync.reject');
    checkTrue('requirePermission() now exists and returns true for an admin session (previously a fatal error)', $allowed);

} finally {
    $pdo->rollBack();
    echo "rolled back.\n";
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
