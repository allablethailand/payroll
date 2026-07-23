<?php
declare(strict_types=1);
require_once __DIR__ . '/ApprovalRequestModel.php';
require_once __DIR__ . '/../services/PayslipDeliveryService.php';

/**
 * Mode B (employee-initiated payslip request), per Payslip Distribution's design. HR submits on
 * behalf of the employee for now (no employee self-service portal exists in this codebase yet --
 * see PayslipDistributionController survey). Approve/Reject/Cancel is NOT duplicated here: it
 * reuses the existing generic Approval Monitor tab and `/api/approval-request.act` untouched --
 * this model only creates the paired row and reacts to the outcome via
 * ApprovalWorkflowController::requestAct()'s sync hook (see syncFromApprovalStatus()).
 *
 * Actually sending the payslip (LINE/Email/Telegram) is out of scope here -- that's the Delivery
 * Engine step. Once approved, `status` becomes 'approved' and sits there until the delivery
 * engine picks it up and moves it to 'sent'/'send_failed'.
 */
class PayslipRequestModel {
    private PDO $db;
    private const ALLOWED_RUN_STATES = ['approved', 'paid', 'locked'];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function list(int $compId): array {
        $stmt = $this->db->prepare("SELECT pr.*,
                CONCAT(e.name_th, ' ', e.surname_th) AS employee_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS employee_name_en,
                e.employee_no,
                r.run_name, r.period_start_date, r.period_end_date,
                ar.status AS approval_status, ar.current_step_order,
                s.step_name AS current_step_name
            FROM payslip_requests pr
            JOIN employees e ON e.id = pr.employee_id
            JOIN payroll_runs r ON r.id = pr.run_id
            LEFT JOIN approval_requests ar ON ar.id = pr.approval_request_id
            LEFT JOIN approval_workflow_steps s ON s.workflow_id = ar.workflow_id AND s.step_order = ar.current_step_order
            WHERE pr.comp_id = :comp_id
            ORDER BY pr.created_at DESC");
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $compId, int $id): ?array {
        $stmt = $this->db->prepare("SELECT pr.*,
                CONCAT(e.name_th, ' ', e.surname_th) AS employee_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS employee_name_en,
                e.employee_no,
                r.run_name, r.period_start_date, r.period_end_date
            FROM payslip_requests pr
            JOIN employees e ON e.id = pr.employee_id
            JOIN payroll_runs r ON r.id = pr.run_id
            WHERE pr.id = :id AND pr.comp_id = :comp_id");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Employees eligible to be requested for, scoped to a run (for the "create request" picker). */
    public function eligibleEmployeesForRun(int $compId, int $runId): array {
        $stmt = $this->db->prepare("SELECT e.id, e.employee_no,
                CONCAT(e.employee_no, ' - ', e.name_th, ' ', e.surname_th) AS text_th,
                CONCAT(e.employee_no, ' - ', e.name_en, ' ', e.surname_en) AS text_en
            FROM payroll_run_details d
            JOIN employees e ON e.id = d.employee_id
            JOIN payroll_runs r ON r.id = d.run_id
            WHERE d.run_id = :run_id AND r.comp_id = :comp_id
            ORDER BY e.employee_no ASC");
        $stmt->execute([':run_id' => $runId, ':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Runs a payslip could plausibly be requested for (state gate applied here, not left to the client). */
    public function eligibleRuns(int $compId): array {
        $placeholders = implode(',', array_fill(0, count(self::ALLOWED_RUN_STATES), '?'));
        $stmt = $this->db->prepare("SELECT id, run_name AS text_th, run_name AS text_en, period_start_date, period_end_date
            FROM payroll_runs WHERE comp_id = ? AND state IN ({$placeholders}) ORDER BY period_end_date DESC");
        $stmt->execute([$compId, ...self::ALLOWED_RUN_STATES]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function employeeInRun(int $employeeId, int $runId): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM payroll_run_details WHERE run_id = :run_id AND employee_id = :employee_id");
        $stmt->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function hasPendingRequest(int $employeeId, int $runId): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM payslip_requests WHERE employee_id = :employee_id AND run_id = :run_id AND status = 'pending'");
        $stmt->execute([':employee_id' => $employeeId, ':run_id' => $runId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function create(int $compId, int $employeeId, int $runId, int $requestedBy): array {
        if ($employeeId <= 0 || $runId <= 0) {
            return ['status' => false, 'message' => 'employee_id and run_id are required.'];
        }

        $stmtEmp = $this->db->prepare("SELECT id, name_th, surname_th FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $employee = $stmtEmp->fetch(PDO::FETCH_ASSOC);
        if (!$employee) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }

        $stmtRun = $this->db->prepare("SELECT id, run_name, state FROM payroll_runs WHERE id = :id AND comp_id = :comp_id");
        $stmtRun->execute([':id' => $runId, ':comp_id' => $compId]);
        $run = $stmtRun->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            return ['status' => false, 'message' => 'Payroll run not found.'];
        }
        if (!in_array($run['state'], self::ALLOWED_RUN_STATES, true)) {
            return ['status' => false, 'message' => 'This payroll run is not yet in a state that can be requested (must be approved, paid, or locked).'];
        }
        if (!$this->employeeInRun($employeeId, $runId)) {
            return ['status' => false, 'message' => 'This employee is not part of the selected payroll run.'];
        }
        if ($this->hasPendingRequest($employeeId, $runId)) {
            return ['status' => false, 'message' => 'A pending request already exists for this employee and pay period.'];
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare("INSERT INTO payslip_requests (comp_id, employee_id, run_id, requested_by, status)
                VALUES (:comp_id, :employee_id, :run_id, :requested_by, 'pending')");
            $stmt->execute([':comp_id' => $compId, ':employee_id' => $employeeId, ':run_id' => $runId, ':requested_by' => $requestedBy]);
            $requestId = (int)$this->db->lastInsertId();

            $label = "Payslip - {$employee['name_th']} {$employee['surname_th']} - {$run['run_name']}";
            $approvalModel = new ApprovalRequestModel($this->db);
            $approvalResult = $approvalModel->create($compId, 'SLIP_REQUEST_APPROVAL', $requestId, $label, $requestedBy);
            if (!$approvalResult['status']) {
                if ($ownTransaction) {
                    $this->db->rollBack();
                }
                return $approvalResult;
            }

            $this->db->prepare("UPDATE payslip_requests SET approval_request_id = :approval_request_id WHERE id = :id")
                ->execute([':approval_request_id' => $approvalResult['id'], ':id' => $requestId]);

            if ($ownTransaction) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Payslip request submitted for approval.', 'id' => $requestId];
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /**
     * Called from ApprovalWorkflowController::requestAct() right after the generic engine acts
     * on a request -- kept OUT of ApprovalRequestModel itself so the generic engine stays
     * document-type-agnostic. No-op for anything other than a terminal outcome (approve mid-step
     * on a multi-step workflow returns request_status='pending', which isn't synced).
     */
    public function syncFromApprovalStatus(int $approvalRequestId, string $requestStatus, ?string $selectedChannel = null): void {
        if (!in_array($requestStatus, ['approved', 'rejected', 'cancelled'], true)) {
            return;
        }
        $stmt = $this->db->prepare("SELECT id FROM payslip_requests WHERE approval_request_id = :approval_request_id");
        $stmt->execute([':approval_request_id' => $approvalRequestId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return;
        }
        $sql = "UPDATE payslip_requests SET status = :status, updated_at = CURRENT_TIMESTAMP";
        $params = [':status' => $requestStatus, ':id' => (int)$id];
        if ($requestStatus === 'approved' && $selectedChannel !== null && $selectedChannel !== '') {
            $sql .= ", selected_channel = :selected_channel";
            $params[':selected_channel'] = $selectedChannel;
        }
        $sql .= " WHERE id = :id";
        $this->db->prepare($sql)->execute($params);

        if ($requestStatus === 'approved') {
            // Best-effort: a delivery failure (bad SMTP config, unconfigured channel, etc.) must
            // not undo the approval itself. deliverForRequest() already logs every attempt and
            // sets status to sent/send_failed; this is just a safety net against an unexpected
            // exception escaping the whole approve action.
            try {
                (new PayslipDeliveryService($this->db))->deliverForRequest((int)$id);
            } catch (Throwable $e) {
                // Swallow -- an exception here means deliverForRequest() itself blew up before
                // reaching its own status update, so the request is left sitting at 'approved'
                // rather than falsely marked 'sent'. A resend action (not built yet) is how this
                // gets retried.
            }
        }
    }
}
