<?php
declare(strict_types=1);
require_once __DIR__ . '/notifications/NotificationChannelRegistry.php';
require_once __DIR__ . '/reports/payment/PaySlipReport.php';
require_once __DIR__ . '/../models/PayslipDistributionSettingModel.php';

/**
 * Payslip Distribution delivery engine. Two entry points:
 *   - autoSendForRun(): Mode A, called from PayrollRunModel::markPaid() right after the run
 *     transitions to 'paid'.
 *   - deliverForRequest(): Mode B, called from PayslipRequestModel::syncFromApprovalStatus()
 *     right after a payslip_requests row becomes 'approved'.
 * Both funnel through deliver(), which tries a channel priority list in order (the fallback
 * chain both modes require) and logs every attempt to payslip_delivery_logs.
 *
 * send_delay_hours (payslip_distribution_settings) is config-only and NOT enforced here -- this
 * project has no scheduled-job/cron infrastructure (same limitation already documented for
 * Approval Workflow's timeout_hours/escalation). autoSendForRun() always sends immediately
 * regardless of the configured delay; wiring real delayed sending needs that infrastructure
 * built first.
 */
class PayslipDeliveryService {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    private function resolveRecipient(array $employee, string $channelCode): ?string {
        switch ($channelCode) {
            case 'email':
                return !empty($employee['personal_email']) ? $employee['personal_email']
                    : (!empty($employee['company_email']) ? $employee['company_email'] : null);
            case 'line':
                return !empty($employee['line_id']) ? $employee['line_id'] : null;
            case 'telegram':
                return !empty($employee['telegram_chat_id']) ? $employee['telegram_chat_id'] : null;
            default:
                return null;
        }
    }

    private function logAttempt(
        int $compId, int $employeeId, int $runId, string $source, ?int $payslipRequestId,
        string $channelCode, int $attemptOrder, ?string $recipient, string $status, ?string $errorMessage, ?int $sentBy
    ): void {
        $stmt = $this->db->prepare("INSERT INTO payslip_delivery_logs
            (comp_id, employee_id, run_id, source, payslip_request_id, channel_code, attempt_order, recipient, status, error_message, sent_by)
            VALUES (:comp_id, :employee_id, :run_id, :source, :payslip_request_id, :channel_code, :attempt_order, :recipient, :status, :error_message, :sent_by)");
        $stmt->execute([
            ':comp_id' => $compId, ':employee_id' => $employeeId, ':run_id' => $runId, ':source' => $source,
            ':payslip_request_id' => $payslipRequestId, ':channel_code' => $channelCode, ':attempt_order' => $attemptOrder,
            ':recipient' => $recipient, ':status' => $status, ':error_message' => $errorMessage, ':sent_by' => $sentBy,
        ]);
    }

    /**
     * @param string[] $channelPriority channel codes in attempt order (first = primary, rest = fallback)
     * @return array{success: bool, message: string, channel?: string}
     */
    public function deliver(int $compId, int $employeeId, int $runId, array $channelPriority, string $source, ?int $payslipRequestId = null, ?int $sentBy = null): array {
        $stmtEmp = $this->db->prepare("SELECT * FROM employees WHERE id = :id AND comp_id = :comp_id");
        $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $employee = $stmtEmp->fetch(PDO::FETCH_ASSOC);
        if (!$employee) {
            return ['success' => false, 'message' => 'Employee not found.'];
        }
        if (empty($channelPriority)) {
            $this->logAttempt($compId, $employeeId, $runId, $source, $payslipRequestId, 'none', 1, null, 'failed', 'No delivery channel configured.', $sentBy);
            return ['success' => false, 'message' => 'No delivery channel configured.'];
        }

        try {
            $slip = (new PaySlipReport())->generate(['comp_id' => $compId, 'run_id' => $runId, 'employee_id' => $employeeId], 'pdf');
        } catch (Throwable $e) {
            $this->logAttempt($compId, $employeeId, $runId, $source, $payslipRequestId, 'none', 1, null, 'failed', 'Could not generate payslip PDF: ' . $e->getMessage(), $sentBy);
            return ['success' => false, 'message' => 'Could not generate payslip PDF: ' . $e->getMessage()];
        }

        $tmpPath = sys_get_temp_dir() . '/payslip_' . $runId . '_' . $employeeId . '_' . uniqid('', true) . '.pdf';
        file_put_contents($tmpPath, $slip['content']);

        // 2026-09-04, Backlog Phase 11, T062 -- built ONCE, shared across every channel in the
        // fallback chain (not special-cased per channel_code in the loop below). Necessary because
        // LINE's own Messaging API has no file-attachment message type at all (see LineChannel's
        // own docblock) -- its ONLY way to actually deliver the document is a clickable link back
        // to this app's own self-service download (PayslipController::myDownload()), so the shared
        // message text has to carry that link. Including it for email/Telegram too is harmless (a
        // convenience alongside the real attachment those channels already carry), so one shared
        // string is simpler than diverging per channel for no real benefit.
        $downloadLink = rtrim(BASE_URL, '/') . '/api/payslip.my-download?run_id=' . $runId;
        $message = "Please find your payslip attached. You can also view/download it here: {$downloadLink}";

        try {
            $attempt = 0;
            $lastMessage = 'All configured channels failed.';
            foreach ($channelPriority as $channelCode) {
                $attempt++;
                $channel = NotificationChannelRegistry::get($channelCode);
                if (!$channel) {
                    $this->logAttempt($compId, $employeeId, $runId, $source, $payslipRequestId, $channelCode, $attempt, null, 'failed', 'Unknown channel.', $sentBy);
                    $lastMessage = "Unknown channel: {$channelCode}";
                    continue;
                }
                if (!$channel->isConfigured()) {
                    $this->logAttempt($compId, $employeeId, $runId, $source, $payslipRequestId, $channelCode, $attempt, null, 'failed', 'Channel not configured.', $sentBy);
                    $lastMessage = ucfirst($channelCode) . ' is not configured.';
                    continue;
                }
                $recipient = $this->resolveRecipient($employee, $channelCode);
                if (!$recipient) {
                    $this->logAttempt($compId, $employeeId, $runId, $source, $payslipRequestId, $channelCode, $attempt, null, 'failed', 'Employee has no destination address for this channel.', $sentBy);
                    $lastMessage = "Employee has no destination address for {$channelCode}.";
                    continue;
                }
                try {
                    $result = $channel->send($recipient, 'Payslip', $message, $tmpPath, $slip['file_name']);
                } catch (Throwable $e) {
                    $result = ['success' => false, 'message' => $e->getMessage()];
                }
                $this->logAttempt(
                    $compId, $employeeId, $runId, $source, $payslipRequestId, $channelCode, $attempt, $recipient,
                    $result['success'] ? 'success' : 'failed', $result['success'] ? null : $result['message'], $sentBy
                );
                if ($result['success']) {
                    return ['success' => true, 'message' => "Sent via {$channelCode}.", 'channel' => $channelCode];
                }
                $lastMessage = $result['message'];
            }
            return ['success' => false, 'message' => $lastMessage];
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * Mode B: called after a payslip_requests row becomes 'approved'. Updates its status to
     * sent/send_failed. $sentBy is null for the automatic post-approval trigger, or the acting
     * admin's employee id when called from resend().
     */
    public function deliverForRequest(int $payslipRequestId, ?int $sentBy = null): array {
        $stmt = $this->db->prepare("SELECT * FROM payslip_requests WHERE id = :id");
        $stmt->execute([':id' => $payslipRequestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            return ['success' => false, 'message' => 'Payslip request not found.'];
        }
        $compId = (int)$request['comp_id'];

        $settings = (new PayslipDistributionSettingModel($this->db))->get($compId);
        $companyChannels = array_column($settings['channels'], 'code');

        $stmtEmp = $this->db->prepare("SELECT default_payslip_channel FROM employees WHERE id = :id");
        $stmtEmp->execute([':id' => $request['employee_id']]);
        $employeeDefault = $stmtEmp->fetchColumn();

        $priority = [];
        if (!empty($request['selected_channel'])) {
            $priority[] = $request['selected_channel'];
        }
        if (!empty($employeeDefault)) {
            $priority[] = $employeeDefault;
        }
        $priority = array_values(array_unique(array_merge($priority, $companyChannels)));

        $result = $this->deliver($compId, (int)$request['employee_id'], (int)$request['run_id'], $priority, 'request', $payslipRequestId, $sentBy);

        $this->db->prepare("UPDATE payslip_requests SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':status' => $result['success'] ? 'sent' : 'send_failed', ':id' => $payslipRequestId]);

        return $result;
    }

    /**
     * Mode A: called once a run reaches 'paid'. No-op (not an error) if the company hasn't
     * enabled auto-send. Scope filters (department/employment status) only restrict WHO gets
     * auto-sent -- everyone else can still get their slip via Mode B (payslip_requests).
     */
    public function autoSendForRun(int $compId, int $runId): array {
        $settings = (new PayslipDistributionSettingModel($this->db))->get($compId);
        if (!$settings['is_active'] || !in_array($settings['distribution_mode'], ['auto', 'both'], true)) {
            return ['status' => true, 'message' => 'Auto-send is not enabled for this company.', 'sent' => 0, 'failed' => 0];
        }
        $channelPriority = array_column($settings['channels'], 'code');
        if (empty($channelPriority)) {
            return ['status' => false, 'message' => 'Auto-send is enabled but no delivery channel is configured.', 'sent' => 0, 'failed' => 0];
        }

        $deptIds = !empty($settings['scope_department_ids']) ? array_map('intval', explode(',', (string)$settings['scope_department_ids'])) : [];
        $statuses = !empty($settings['scope_employment_statuses']) ? explode(',', (string)$settings['scope_employment_statuses']) : [];

        $sql = "SELECT d.employee_id FROM payroll_run_details d JOIN employees e ON e.id = d.employee_id WHERE d.run_id = ?";
        $params = [$runId];
        if (!empty($deptIds)) {
            $sql .= " AND e.department_id IN (" . implode(',', array_fill(0, count($deptIds), '?')) . ")";
            $params = array_merge($params, $deptIds);
        }
        if (!empty($statuses)) {
            $sql .= " AND e.employment_status IN (" . implode(',', array_fill(0, count($statuses), '?')) . ")";
            $params = array_merge($params, $statuses);
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $employeeIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'employee_id'));

        $sent = 0;
        $failed = 0;
        foreach ($employeeIds as $employeeId) {
            $result = $this->deliver($compId, $employeeId, $runId, $channelPriority, 'auto', null, null);
            if ($result['success']) {
                $sent++;
            } else {
                $failed++;
            }
        }
        return ['status' => true, 'message' => "Auto-send: {$sent} sent, {$failed} failed.", 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * Retries delivery for one payslip_delivery_logs entry (typically a failed one), from the
     * Payslip Delivery Log tab's Resend button. Re-runs the FULL fallback chain, not just the
     * one channel that failed on this particular log row -- a channel that failed 10 minutes ago
     * might succeed now (e.g. SMTP config was just fixed), and the other channels in the chain
     * deserve a fresh attempt too.
     */
    public function resend(int $compId, int $logId, int $userId): array {
        $stmt = $this->db->prepare("SELECT * FROM payslip_delivery_logs WHERE id = :id AND comp_id = :comp_id");
        $stmt->execute([':id' => $logId, ':comp_id' => $compId]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$log) {
            return ['success' => false, 'message' => 'Log entry not found.'];
        }

        if ($log['source'] === 'request' && !empty($log['payslip_request_id'])) {
            return $this->deliverForRequest((int)$log['payslip_request_id'], $userId);
        }

        $settings = (new PayslipDistributionSettingModel($this->db))->get($compId);
        $channelPriority = array_column($settings['channels'], 'code');
        if (empty($channelPriority)) {
            // Company has no channels configured anymore -- fall back to retrying just the
            // channel this log entry originally attempted, rather than refusing outright.
            $channelPriority = [$log['channel_code']];
        }
        return $this->deliver($compId, (int)$log['employee_id'], (int)$log['run_id'], $channelPriority, (string)$log['source'], null, $userId);
    }
}
