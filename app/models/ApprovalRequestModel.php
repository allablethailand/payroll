<?php
declare(strict_types=1);

/**
 * Approval Request engine: creates a running instance of whichever ACTIVE workflow is mapped
 * to a document type, and progresses it step by step as approvers act.
 *
 * Not wired to any real document flow yet (e.g. PayrollRunModel's own submit/approve/reject
 * stays on its existing single-step permission check) — this is a complete, independently
 * testable subsystem that a future integration round can call into. See tests/approval_workflow_test.php
 * for an end-to-end exercise (create workflow -> create request -> act through every step).
 */
class ApprovalRequestModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /**
     * Starts a new approval request for a document, using whichever workflow is currently
     * ACTIVE for this document type in this company.
     */
    public function create(int $compId, string $documentTypeCode, int $referenceId, ?string $referenceLabel, int $requestedBy): array {
        if ($referenceId <= 0) {
            return ['status' => false, 'message' => 'reference_id is required.'];
        }
        $stmtType = $this->db->prepare("SELECT code FROM `approval_document_types` WHERE code = :code AND is_active = 1");
        $stmtType->execute([':code' => $documentTypeCode]);
        if (!$stmtType->fetch()) {
            return ['status' => false, 'message' => 'Unknown or inactive document_type_code.'];
        }

        $stmtWf = $this->db->prepare("SELECT w.id FROM `approval_workflows` w
            JOIN `approval_workflow_document_types` awdt ON awdt.workflow_id = w.id
            WHERE w.comp_id = :comp_id AND w.status = 'active' AND awdt.document_type_code = :code LIMIT 1");
        $stmtWf->execute([':comp_id' => $compId, ':code' => $documentTypeCode]);
        $workflowId = $stmtWf->fetchColumn();
        if ($workflowId === false) {
            return ['status' => false, 'message' => 'No active workflow is configured for this document type yet.'];
        }

        $stmt = $this->db->prepare("INSERT INTO `approval_requests`
            (comp_id, workflow_id, document_type_code, reference_id, reference_label, current_step_order, status, requested_by)
            VALUES (:comp_id, :workflow_id, :document_type_code, :reference_id, :reference_label, 1, 'pending', :requested_by)");
        $stmt->execute([
            ':comp_id' => $compId,
            ':workflow_id' => (int)$workflowId,
            ':document_type_code' => $documentTypeCode,
            ':reference_id' => $referenceId,
            ':reference_label' => $referenceLabel,
            ':requested_by' => $requestedBy,
        ]);
        return ['status' => true, 'message' => 'Approval request created.', 'id' => (int)$this->db->lastInsertId()];
    }

    public function get(int $compId, int $requestId): ?array {
        $stmt = $this->db->prepare("SELECT r.*, w.workflow_name, dt.name_th AS document_type_name_th, dt.name_en AS document_type_name_en,
                CONCAT(e.name_th, ' ', e.surname_th) AS requested_by_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS requested_by_name_en
            FROM `approval_requests` r
            JOIN `approval_workflows` w ON w.id = r.workflow_id
            JOIN `approval_document_types` dt ON dt.code = r.document_type_code
            LEFT JOIN `employees` e ON e.id = r.requested_by
            WHERE r.id = :id AND r.comp_id = :comp_id");
        $stmt->execute([':id' => $requestId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function currentStep(int $workflowId, int $stepOrder): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `approval_workflow_steps` WHERE workflow_id = :workflow_id AND step_order = :step_order");
        $stmt->execute([':workflow_id' => $workflowId, ':step_order' => $stepOrder]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return int[] employee ids eligible to act on a step */
    private function resolveApproverPool(int $compId, string $approverType, int $approverId): array {
        if ($approverType === 'user') {
            return [$approverId];
        }
        $stmt = $this->db->prepare("SELECT id FROM `employees` WHERE role_id = :role_id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':role_id' => $approverId, ':comp_id' => $compId]);
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    }

    private function userCanActOnStep(int $compId, int $userId, array $step): bool {
        $pool = $this->resolveApproverPool($compId, (string)$step['approver_type'], (int)$step['approver_id']);
        return in_array($userId, $pool, true);
    }

    /**
     * @param string $action approve|reject|cancel
     */
    public function act(int $compId, int $requestId, int $userId, string $action, ?string $note = null): array {
        if (!in_array($action, ['approve', 'reject', 'cancel'], true)) {
            return ['status' => false, 'message' => 'Invalid action.'];
        }
        $request = $this->get($compId, $requestId);
        if (!$request) {
            return ['status' => false, 'message' => 'Request not found.'];
        }
        if ($request['status'] !== 'pending') {
            return ['status' => false, 'message' => "This request is already {$request['status']}."];
        }

        if ($action === 'cancel') {
            if ((int)$request['requested_by'] !== $userId) {
                return ['status' => false, 'message' => 'Only the requester can cancel this request.'];
            }
            return $this->finalize($requestId, (int)$request['current_step_order'], 'cancel', $userId, $note, 'cancelled', null);
        }

        $step = $this->currentStep((int)$request['workflow_id'], (int)$request['current_step_order']);
        if (!$step) {
            return ['status' => false, 'message' => 'This workflow no longer has a step at the current position — contact an administrator.'];
        }
        if (!$this->userCanActOnStep($compId, $userId, $step)) {
            return ['status' => false, 'message' => 'You are not authorized to act on this step.'];
        }

        if ($action === 'reject') {
            return $this->finalize($requestId, (int)$step['step_order'], 'reject', $userId, $note, 'rejected', $step['step_name']);
        }

        // approve
        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            $this->logAction($requestId, (int)$step['step_order'], $step['step_name'], 'approve', $userId, $note);

            $satisfied = true;
            if ($step['approver_type'] === 'role' && $step['joint_approve_mode'] === 'all') {
                $pool = $this->resolveApproverPool($compId, 'role', (int)$step['approver_id']);
                $stmtActed = $this->db->prepare("SELECT COUNT(DISTINCT acted_by) FROM `approval_request_logs`
                    WHERE request_id = :request_id AND step_order = :step_order AND action = 'approve'");
                $stmtActed->execute([':request_id' => $requestId, ':step_order' => $step['step_order']]);
                $actedCount = (int)$stmtActed->fetchColumn();
                $satisfied = !empty($pool) && $actedCount >= count($pool);
            }

            if (!$satisfied) {
                if ($own) {
                    $this->db->commit();
                }
                return ['status' => true, 'message' => 'Your approval was recorded. Waiting on the rest of this step.', 'request_status' => 'pending'];
            }

            $nextStep = $this->currentStep((int)$request['workflow_id'], (int)$step['step_order'] + 1);
            if ($nextStep) {
                $stmt = $this->db->prepare("UPDATE `approval_requests` SET current_step_order = :next, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([':next' => $nextStep['step_order'], ':id' => $requestId]);
                if ($own) {
                    $this->db->commit();
                }
                return ['status' => true, 'message' => 'Approved. Advanced to the next step.', 'request_status' => 'pending'];
            }

            $stmt = $this->db->prepare("UPDATE `approval_requests` SET status = 'approved', completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([':id' => $requestId]);
            if ($own) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Approved. This was the final step — the request is now fully approved.', 'request_status' => 'approved'];
        } catch (PDOException $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    private function finalize(int $requestId, int $stepOrder, string $action, int $userId, ?string $note, string $newStatus, ?string $stepNameSnapshot): array {
        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            $this->logAction($requestId, $stepOrder, $stepNameSnapshot, $action, $userId, $note);
            $stmt = $this->db->prepare("UPDATE `approval_requests` SET status = :status, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([':status' => $newStatus, ':id' => $requestId]);
            if ($own) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => ucfirst($newStatus) . '.', 'request_status' => $newStatus];
        } catch (PDOException $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    private function logAction(int $requestId, int $stepOrder, ?string $stepName, string $action, int $userId, ?string $note): void {
        $stmt = $this->db->prepare("INSERT INTO `approval_request_logs` (request_id, step_order, step_name_snapshot, action, acted_by, note)
            VALUES (:request_id, :step_order, :step_name, :action, :acted_by, :note)");
        $stmt->execute([
            ':request_id' => $requestId,
            ':step_order' => $stepOrder,
            ':step_name' => $stepName,
            ':action' => $action,
            ':acted_by' => $userId,
            ':note' => $note,
        ]);
    }

    public function logs(int $compId, int $requestId): array {
        $request = $this->get($compId, $requestId);
        if (!$request) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT l.*, CONCAT(e.name_th, ' ', e.surname_th) AS acted_by_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS acted_by_name_en
            FROM `approval_request_logs` l
            LEFT JOIN `employees` e ON e.id = l.acted_by
            WHERE l.request_id = :request_id ORDER BY l.acted_at ASC");
        $stmt->execute([':request_id' => $requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array{status?: string, document_type_code?: string} $filters */
    public function list(int $compId, array $filters = []): array {
        $where = "WHERE r.comp_id = :comp_id";
        $params = [':comp_id' => $compId];
        if (!empty($filters['status'])) {
            $where .= " AND r.status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['document_type_code'])) {
            $where .= " AND r.document_type_code = :document_type_code";
            $params[':document_type_code'] = $filters['document_type_code'];
        }
        $sql = "SELECT r.*, w.workflow_name, dt.name_th AS document_type_name_th, dt.name_en AS document_type_name_en,
                    s.step_name AS current_step_name, s.approver_type AS current_step_approver_type,
                    CONCAT(e.name_th, ' ', e.surname_th) AS requested_by_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS requested_by_name_en
                FROM `approval_requests` r
                JOIN `approval_workflows` w ON w.id = r.workflow_id
                JOIN `approval_document_types` dt ON dt.code = r.document_type_code
                LEFT JOIN `approval_workflow_steps` s ON s.workflow_id = r.workflow_id AND s.step_order = r.current_step_order
                LEFT JOIN `employees` e ON e.id = r.requested_by
                {$where}
                ORDER BY r.requested_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
