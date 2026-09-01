<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/Database.php';

/**
 * 2026-08-31, explicit request: "ช่วยเขียนส่งสำหรับส่ง Status กลับไปที่ Origami ได้ไหมครับ เพื่อให้ฝั่งโน้น
 * สามารถ Track ได้...และต้องมีเอกสารส่งไปที่ Origami เพื่อให้ฝั่งนั้นเขียนรับค่า Status และการตีกลับครับ...และ
 * เน้นย้ำต้องเก็บ Log การดำเนินการ" -- confirmed via AskUserQuestion: (a) direct synchronous outbound
 * call (NOT a queue -- unlike Phase 7's email_queue, the user explicitly wants Origami to see the
 * result quickly, accepting that a slow/down Origami briefly slows this app's own request instead of
 * a cron-delayed retry), (b) no formal spec document existed at first (no Origami dev team ready
 * to consume one yet) -- written up the same day once asked to have something ready to hand off:
 * `docs/origami-payroll-status-api-guide.md` (same DRAFT-banner style as
 * docs/origami-employee-sync-api-guide.md) is now the authoritative copy of this contract; this
 * class's own docblock below is kept in sync with it but the .md file is what to actually send to
 * Origami's team. Update BOTH if the contract ever changes once Origami confirms/adjusts anything.
 *
 * **CONFIRMED, LIVE contract (2026-08-31: Origami built and deployed the receiving endpoint --
 * verified via a real push actually succeeding end-to-end, not just taken on their word, see
 * tests/origami_payroll_status_test.php's own docblock), one endpoint, two event families:**
 *   POST {ORIGAMI_API_BASE_URL}/api/hr/payroll/status
 *   Auth: `Authorization: Bearer {ORIGAMI_API_KEY}` (same header this app's OTHER outbound Origami
 *   client, OrigamiEmployeeCandidateClient, already uses -- reusing the SAME credential pair, not a
 *   new one).
 *   Body (JSON):
 *     {
 *       "event_type": "run_submitted" | "run_approved" | "run_rejected" | "run_paid" | "run_cancelled" | "run_need_info" | "run_reverted" | "run_revised" | "sync_process_rejected",
 *       "company_ref_id": string,            // origami_comp_code (payroll_sync_processes) when known, else null
 *       "origami_process_id": int|null,      // the natural key Origami itself gave this cycle (payroll_runs.sync_process_id -> payroll_sync_processes.origami_process_id), null for a run with no sync origin (manual/off-cycle)
 *       "payroll_run_id": int|null,          // THIS app's own run id, for reference/debugging on Origami's side
 *       "state": string|null,                // payroll_runs.state after the change (run_* events only)
 *       "reason": string|null,               // reject_reason / rejected_reason, when applicable
 *       "occurred_at": string                 // ISO 8601
 *     }
 *   Expected response: {"status": true} on success; any non-2xx or {"status": false, "message": "..."}
 *   is logged as a failure (see logAttempt() below) but NEVER thrown back to the caller -- every
 *   public method here is best-effort by design (a failed/unreachable Origami must never undo or
 *   block a real payroll_runs state change or a reject-back action that already committed locally).
 *
 * Every attempt (success or failure) is logged to `origami_status_push_logs` -- this IS the
 * "เก็บ Log การดำเนินการ" requirement, independent of whether Origami could actually be reached.
 */
class OrigamiPayrollStatusClient {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public static function isConfigured(): bool {
        return ORIGAMI_API_BASE_URL !== '' && ORIGAMI_API_KEY !== '';
    }

    /** Called from PayrollController's own submit()/approve()/reject()/markPaid()/bulkApprove()/
     *  bulkReject()/cancel()/requestInfo()/bulkRequestInfo()/revert()/reviseAfterReject()/
     *  reviseAfterNeedInfo() actions -- every payroll_runs state-changing entry point on that
     *  controller, per the batch's own "ทุกครั้งที่ Payroll Run เปลี่ยนสถานะ" (every time the payroll
     *  run changes state) requirement -- right after the model call itself already succeeded, never
     *  from inside PayrollRunModel directly, so tests that call the model layer straight (the
     *  overwhelming majority of this app's own test suite) never trigger a real/attempted outbound
     *  HTTP call. $run is whatever PayrollRunModel::get() returned -- that method's own SELECT
     *  doesn't carry origami_process_id/origami_comp_code (deliberately not touched, see this
     *  method's own resolveSyncOrigin() call below), only `sync_process_id`, which is enough to
     *  resolve them here. */
    public function pushRunStatus(array $run, string $eventType): void {
        $origin = $this->resolveSyncOrigin(isset($run['sync_process_id']) ? (int)$run['sync_process_id'] : null);
        $payload = [
            'event_type' => $eventType,
            'company_ref_id' => $origin['origami_comp_code'],
            'origami_process_id' => $origin['origami_process_id'],
            'payroll_run_id' => (int)($run['id'] ?? 0),
            'state' => $run['state'] ?? null,
            'reason' => $run['reject_reason'] ?? null,
            'occurred_at' => date('c'),
        ];
        $this->send($eventType, (int)($run['comp_id'] ?? 0), (int)($run['id'] ?? 0), null, $origin['origami_process_id'], $payload);
    }

    /** @return array{origami_process_id:?int, origami_comp_code:?string} */
    private function resolveSyncOrigin(?int $syncProcessId): array {
        if ($syncProcessId === null || $syncProcessId <= 0) {
            return ['origami_process_id' => null, 'origami_comp_code' => null];
        }
        $stmt = $this->db->prepare("SELECT origami_process_id, origami_comp_code FROM `payroll_sync_processes` WHERE id = :id");
        $stmt->execute([':id' => $syncProcessId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'origami_process_id' => $row ? (int)$row['origami_process_id'] : null,
            'origami_comp_code' => $row['origami_comp_code'] ?? null,
        ];
    }

    /** Called from PayrollSyncModel::rejectProcess() right after the local reject-back already committed. */
    public function pushSyncProcessRejected(array $process, string $comment): void {
        $payload = [
            'event_type' => 'sync_process_rejected',
            'company_ref_id' => $process['origami_comp_code'] ?? null,
            'origami_process_id' => (int)($process['origami_process_id'] ?? 0),
            'payroll_run_id' => null,
            'state' => null,
            'reason' => $comment,
            'occurred_at' => date('c'),
        ];
        $this->send('sync_process_rejected', (int)($process['comp_id'] ?? 0), null, (int)($process['id'] ?? 0), (int)($process['origami_process_id'] ?? 0), $payload);
    }

    private function send(string $eventType, int $compId, ?int $runId, ?int $syncProcessId, ?int $origamiProcessId, array $payload): void {
        if (!self::isConfigured()) {
            $this->logAttempt($eventType, $compId, $runId, $syncProcessId, $origamiProcessId, $payload, false, null, 'Not configured -- ORIGAMI_API_BASE_URL/ORIGAMI_API_KEY are not set.');
            return;
        }
        $url = ORIGAMI_API_BASE_URL . '/api/hr/payroll/status';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . ORIGAMI_API_KEY,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrno !== 0) {
            $this->logAttempt($eventType, $compId, $runId, $syncProcessId, $origamiProcessId, $payload, false, null, "Could not reach Origami: {$curlError}");
            return;
        }
        $decoded = json_decode((string)$body, true);
        $success = ($httpCode >= 200 && $httpCode < 300) && is_array($decoded) && ($decoded['status'] ?? null) === true;
        $message = is_array($decoded) && is_string($decoded['message'] ?? null) ? $decoded['message'] : (string)$body;
        $this->logAttempt($eventType, $compId, $runId, $syncProcessId, $origamiProcessId, $payload, $success, $httpCode, $success ? null : substr($message, 0, 2000));
    }

    private function logAttempt(string $eventType, int $compId, ?int $runId, ?int $syncProcessId, ?int $origamiProcessId, array $payload, bool $success, ?int $httpStatus, ?string $responseMessage): void {
        try {
            $stmt = $this->db->prepare("INSERT INTO `origami_status_push_logs`
                (comp_id, event_type, run_id, sync_process_id, origami_process_id, request_payload, success, http_status, response_message)
                VALUES (:comp_id, :event_type, :run_id, :sync_process_id, :origami_process_id, :request_payload, :success, :http_status, :response_message)");
            $stmt->execute([
                ':comp_id' => $compId ?: null,
                ':event_type' => $eventType,
                ':run_id' => $runId,
                ':sync_process_id' => $syncProcessId,
                ':origami_process_id' => $origamiProcessId ?: null,
                ':request_payload' => json_encode($payload),
                ':success' => $success ? 1 : 0,
                ':http_status' => $httpStatus,
                ':response_message' => $responseMessage,
            ]);
        } catch (Throwable $e) {
            // Logging itself must never throw back into the caller's own state-change flow --
            // same "best-effort, never blocks" posture as the push attempt itself.
        }
    }
}
