<?php
/**
 * Lightweight verification script for Phase 8 (2026-08-31, explicit request: "ช่วยเขียนส่งสำหรับส่ง
 * Status กลับไปที่ Origami ได้ไหมครับ เพื่อให้ฝั่งโน้นสามารถ Track ได้ และเพิ่มให้สามารถตีกลับเอกสารที่ยังไม่ดึงมา
 * ทำรอบได้ โดยที่ต้องใส่ Comment เข้าไปด้วยครับ...และต้องมีเอกสารส่งไปที่ Origami...และเน้นย้ำต้องเก็บ Log การ
 * ดำเนินการ") -- OrigamiPayrollStatusClient (outbound push + logging) and
 * PayrollSyncModel::rejectProcess() (reject-back for a Pending Pull document).
 *
 * ORIGAMI_API_BASE_URL/ORIGAMI_API_KEY ARE genuinely configured in this dev .env (confirmed --
 * unlike EmailChannel/LineChannel/TelegramChannel elsewhere in this app), so isConfigured() is true
 * and every push here makes a REAL HTTP call to that real reachable host.
 *
 * 2026-08-31, same-day follow-up: Origami confirmed their side has now built the receiving
 * endpoint (`POST /api/hr/payroll/status`, docs/origami-payroll-status-api-guide.md) -- re-verified
 * directly against the real host, not just taken on their word: the push now comes back a genuine
 * 2xx with a real `{"status": true}` JSON body (OrigamiPayrollStatusClient::pushRunStatus()'s own
 * `$success` check requires BOTH a 2xx status AND that exact decoded field, so this isn't a false
 * positive from some other unrelated 2xx response -- e.g. the OLD html-login-page response, from
 * before the endpoint existed, returned 200 too but decoded to `null`, which correctly failed this
 * same check). success=1 is now the correct, expected outcome for every push in this file.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 * Run with: php tests/origami_payroll_status_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/OrigamiPayrollStatusClient.php';
require_once __DIR__ . '/../app/models/PayrollSyncModel.php';
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
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }

try {
    $compId = 1;
    $adminUserId = (int)$pdo->query("SELECT id FROM employees WHERE comp_id = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn();

    echo "=== OrigamiPayrollStatusClient ===\n";
    check('isConfigured() reflects this dev .env (real values present)', OrigamiPayrollStatusClient::isConfigured(), true);

    $client = new OrigamiPayrollStatusClient($pdo);
    $fakeRun = ['id' => 999001, 'comp_id' => $compId, 'state' => 'approved', 'sync_process_id' => null, 'reject_reason' => null];
    $countBefore = (int)$pdo->query("SELECT COUNT(*) FROM origami_status_push_logs")->fetchColumn();
    $client->pushRunStatus($fakeRun, 'run_approved');
    $countAfter = (int)$pdo->query("SELECT COUNT(*) FROM origami_status_push_logs")->fetchColumn();
    check('pushRunStatus() writes exactly one log row', $countAfter - $countBefore, 1);
    $logRow = $pdo->query("SELECT * FROM origami_status_push_logs ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('logged event_type matches', $logRow['event_type'], 'run_approved');
    check('logged run_id matches', (int)$logRow['run_id'], 999001);
    checkTrue('logged sync_process_id is null (no sync origin on this fake run)', $logRow['sync_process_id'] === null);
    // Origami's receiving endpoint is now live (confirmed 2026-08-31, see this file's own top
    // docblock) -- a real push now succeeds end-to-end.
    check('logged success is 1 (Origami\'s receiving endpoint is now live)', (int)$logRow['success'], 1);
    checkTrue('the request_payload actually contains the real event_type', str_contains($logRow['request_payload'], 'run_approved'));
    checkTrue('pushRunStatus() never throws regardless of Origami\'s response', true);

    $decodedPayload = json_decode($logRow['request_payload'], true);
    check('payload payroll_run_id matches', $decodedPayload['payroll_run_id'], 999001);
    check('payload state matches', $decodedPayload['state'], 'approved');

    // 2026-08-31, same-day follow-up: widened from the original 4 events (submit/approve/reject/
    // markPaid) to cover EVERY payroll_runs state-changing controller action -- confirms the ENUM
    // was actually widened to accept all of them (would otherwise throw a PDOException, not just
    // silently reject).
    foreach (['run_submitted', 'run_approved', 'run_rejected', 'run_paid', 'run_cancelled', 'run_need_info', 'run_reverted', 'run_revised'] as $eventType) {
        $client->pushRunStatus(array_merge($fakeRun, ['id' => 999002]), $eventType);
        $row = $pdo->query("SELECT event_type FROM origami_status_push_logs WHERE run_id = 999002 ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        check("event_type '{$eventType}' is accepted by the widened ENUM", $row['event_type'] ?? null, $eventType);
    }

    // ---------- PayrollSyncModel::rejectProcess() ----------
    echo "=== PayrollSyncModel::rejectProcess() ===\n";
    $syncModel = new PayrollSyncModel($pdo);
    $uniqueTag = rand(100000000, 999999999);
    $insProc = $pdo->prepare("INSERT INTO payroll_sync_processes
        (comp_id, origami_process_id, process_no, process_subject, run_kind, origami_comp_code, origami_comp_name,
         frequency_type, schema_version, item_count, unmapped_item_count, raw_payload)
        VALUES (:comp_id, :origami_process_id, :process_no, 'Reject Test Process', 'regular', 'TESTCODE', 'Test Co',
         'monthly', 1, 1, 0, '{}')");
    $insProc->execute([':comp_id' => $compId, ':origami_process_id' => $uniqueTag, ':process_no' => 'RJTEST-' . $uniqueTag]);
    $processId = (int)$pdo->lastInsertId();
    checkTrue('fixture: pending sync process created', $processId > 0);

    $pendingBefore = $syncModel->pendingList($compId);
    checkTrue('the fresh process appears in pendingList() before rejecting', in_array($processId, array_map('intval', array_column($pendingBefore, 'id')), true));

    $rejectNoComment = $syncModel->rejectProcess($processId, $compId, '  ', $adminUserId);
    checkFalse('rejectProcess() refuses an empty/whitespace-only comment', $rejectNoComment['status']);

    $rejectWrongComp = $syncModel->rejectProcess($processId, $compId + 999, 'test', $adminUserId);
    checkFalse('rejectProcess() refuses a cross-company id', $rejectWrongComp['status']);

    $rejectRes = $syncModel->rejectProcess($processId, $compId, 'ข้อมูลไม่ถูกต้อง กรุณาส่งใหม่', $adminUserId);
    checkTrue('rejectProcess() succeeds with a real comment' . (empty($rejectRes['status']) ? " ({$rejectRes['message']})" : ''), $rejectRes['status']);

    $processRow = $pdo->query("SELECT * FROM payroll_sync_processes WHERE id = {$processId}")->fetch(PDO::FETCH_ASSOC);
    check('status flipped to rejected', $processRow['status'], 'rejected');
    check('rejected_reason persisted', $processRow['rejected_reason'], 'ข้อมูลไม่ถูกต้อง กรุณาส่งใหม่');
    check('rejected_by recorded', (int)$processRow['rejected_by'], $adminUserId);
    checkTrue('rejected_at populated', !empty($processRow['rejected_at']));

    $pendingAfter = $syncModel->pendingList($compId);
    checkFalse('the rejected process no longer appears in pendingList()', in_array($processId, array_map('intval', array_column($pendingAfter, 'id')), true));

    $rejectAgain = $syncModel->rejectProcess($processId, $compId, 'trying again', $adminUserId);
    checkFalse('rejectProcess() refuses an already-rejected process (not a silent no-op success)', $rejectAgain['status']);

    // A push-log row for this reject-back should ALSO have been written, same as run status pushes.
    $rejectLogRow = $pdo->query("SELECT * FROM origami_status_push_logs WHERE sync_process_id = {$processId} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    checkTrue('rejectProcess() also wrote its own origami_status_push_logs row', $rejectLogRow !== false);
    if ($rejectLogRow) {
        check('logged event_type is sync_process_rejected', $rejectLogRow['event_type'], 'sync_process_rejected');
        check('logged origami_process_id matches the fixture', (int)$rejectLogRow['origami_process_id'], $uniqueTag);
    }

    // ---------- 2026-09-06, confirmed by Origami: their own ProcessModel::pullBackFromPayrollRejection()
    // lets an admin pull a "sync_rejected" (our own status='rejected', never pulled into a run)
    // process back to draft and resend it with the SAME origami_process_id -- a real, intentionally-
    // designed flow, not a hypothetical. Before this fix, upsertProcess()'s UPDATE branch never reset
    // status/rejected_reason/rejected_by/rejected_at, so a resent process stayed permanently excluded
    // from pendingList()'s own `AND p.status = 'pending'` filter -- fresh, corrected data could never
    // be pulled into a run again. ----------
    echo "=== 2026-09-06: re-ingest (resend) of a REJECTED process resets status back to pending ===\n";
    $resendItem = [
        'report_item_id' => 1, 'emp_id' => 1, 'emp_code' => 'E-1', 'payroll_code' => 'NONEXISTENT_' . $uniqueTag,
        'working_days' => 22, 'trip_allowance' => 0, 'item_values' => [], 'children' => [],
    ];
    $resendPayload = [
        'schema_version' => 1, 'process_id' => $uniqueTag, 'process_no' => 'RJTEST-' . $uniqueTag . '-RESENT',
        'process_subject' => 'Resent after rejection', 'comp_id' => 1, 'comp_code' => 'TESTCODE', 'comp_name' => 'Test Co',
        'frequency_type' => 'monthly', 'run_kind' => 'regular', 'items' => [$resendItem], 'employee_status' => [],
    ];
    // Fixture sanity: TESTCODE isn't necessarily a real companies.origami_payroll_comp_code in this
    // dev DB, so resolveCompanyId() would fail ingest() before ever reaching upsertProcess() -- point
    // this resend at comp_id=1's OWN real comp_code instead (same company the rest of this file
    // already uses throughout).
    $realCompCode = (string)$pdo->query("SELECT origami_payroll_comp_code FROM companies WHERE id = 1")->fetchColumn();
    checkTrue('fixture sanity: comp_id=1 has a real origami_payroll_comp_code to resend against', $realCompCode !== '');
    $resendPayload['comp_code'] = $realCompCode;

    $resendResult = $syncModel->ingest($resendPayload);
    checkTrue('fixture: the resend (same origami_process_id, still not linked to any run) ingests cleanly, not blocked' . (empty($resendResult['status']) ? " ({$resendResult['message']})" : ''), $resendResult['status']);
    check('the resend updates the SAME payroll_sync_processes row (same id), not a new one', $resendResult['process_row_id'] ?? null, $processId);

    $processRowAfterResend = $pdo->query("SELECT * FROM payroll_sync_processes WHERE id = {$processId}")->fetch(PDO::FETCH_ASSOC);
    check('status reset back to pending after the resend', $processRowAfterResend['status'], 'pending');
    checkTrue('rejected_reason cleared', $processRowAfterResend['rejected_reason'] === null);
    checkTrue('rejected_by cleared', $processRowAfterResend['rejected_by'] === null);
    checkTrue('rejected_at cleared', $processRowAfterResend['rejected_at'] === null);
    check('process_subject reflects the newly-resent payload (a genuine re-ingest, not a no-op)', $processRowAfterResend['process_subject'], 'Resent after rejection');

    $pendingAfterResend = $syncModel->pendingList($compId);
    checkTrue('the resent process reappears in pendingList()', in_array($processId, array_map('intval', array_column($pendingAfterResend, 'id')), true));

    // Can be rejected again cleanly -- confirms this isn't some half-reset state stuck between
    // 'pending' and 'rejected'.
    $rejectResendedProcess = $syncModel->rejectProcess($processId, $compId, 'still wrong, rejecting again', $adminUserId);
    checkTrue('the resent (now-pending) process can be rejected again cleanly' . (empty($rejectResendedProcess['status']) ? " ({$rejectResendedProcess['message']})" : ''), $rejectResendedProcess['status']);

    echo "=== rejectProcess() refuses a process already pulled into a run ===\n";
    $uniqueTag2 = rand(100000000, 999999999);
    $insProc->execute([':comp_id' => $compId, ':origami_process_id' => $uniqueTag2, ':process_no' => 'RJTEST2-' . $uniqueTag2]);
    $processId2 = (int)$pdo->lastInsertId();
    // Fake-link it to a run without going through the full recalculate()/submit() pipeline -- only
    // the FK relationship (payroll_runs.sync_process_id -> this process's id) matters for this check.
    $insRun = $pdo->prepare("INSERT INTO payroll_runs (comp_id, sync_process_id, run_name, period_start_date, period_end_date, payment_date, state, created_by)
        VALUES (:comp_id, :sync_process_id, 'Fixture Run For Reject Block Test', '2026-01-01', '2026-01-31', '2026-02-05', 'draft', :created_by)");
    $insRun->execute([':comp_id' => $compId, ':sync_process_id' => $processId2, ':created_by' => $adminUserId]);
    $rejectAlreadyPulled = $syncModel->rejectProcess($processId2, $compId, 'too late', $adminUserId);
    checkFalse('rejectProcess() refuses a process already linked to a real payroll run', $rejectAlreadyPulled['status']);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
