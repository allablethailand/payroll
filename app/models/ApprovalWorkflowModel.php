<?php
declare(strict_types=1);

/**
 * Approval Workflow CRUD: workflow header, its document-type mapping, and its ordered steps.
 * Request creation/progression (the "engine" that actually runs a workflow instance against a
 * real document) lives in ApprovalRequestModel — kept separate since they have very different
 * shapes (config CRUD vs. state machine).
 *
 * 2026-08-23: a step's approver list moved from a single approver_type/approver_id pair on
 * `approval_workflow_steps` to a child table `approval_workflow_step_approvers`, so one step can
 * list MULTIPLE people (a mix of specific users and/or roles) — explicit request: "การอนุมัติใน 1
 * รายการสามารถมีได้มากกว่า 1 แถว และในแต่ละแถวย่อยก็สามารถใส่ได้หลายคน". `joint_approve_mode` still
 * lives on the step itself and now governs the step's WHOLE resolved pool (every approver entry's
 * resolution unioned together), not just a single role's holders. See ApprovalRequestModel for how
 * that pool gets snapshotted into `approval_request_step_approvers` once a request reaches a step.
 */
class ApprovalWorkflowModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function documentTypeOptions(string $search, int $page, int $limit): array {
        $offset = ($page - 1) * $limit;
        $where = "WHERE is_active = 1";
        $params = [];
        if ($search !== '') {
            $where .= " AND (name_th LIKE :search1 OR name_en LIKE :search2 OR code LIKE :search3)";
            $params[':search1'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
            $params[':search3'] = "%{$search}%";
        }
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `approval_document_types` {$where}");
        $totalStmt->execute($params);
        $totalCount = (int)$totalStmt->fetchColumn();

        $sql = "SELECT code AS id, name_th AS text_th, name_en AS text_en
                FROM `approval_document_types` {$where} ORDER BY sort_order ASC LIMIT :offset, :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total_count' => $totalCount];
    }

    /** @return array<int,array> client-side list: header + step count + mapped document type labels, active/inactive only (not deleted) */
    public function list(int $compId): array {
        $sql = "SELECT w.id, w.workflow_name, w.description, w.status,
                    (SELECT COUNT(*) FROM `approval_workflow_steps` s WHERE s.workflow_id = w.id AND s.status = 'active') AS step_count,
                    (SELECT GROUP_CONCAT(dt.name_th SEPARATOR ', ')
                        FROM `approval_workflow_document_types` awdt
                        JOIN `approval_document_types` dt ON dt.code = awdt.document_type_code
                        WHERE awdt.workflow_id = w.id) AS document_type_names_th,
                    (SELECT GROUP_CONCAT(dt.name_en SEPARATOR ', ')
                        FROM `approval_workflow_document_types` awdt
                        JOIN `approval_document_types` dt ON dt.code = awdt.document_type_code
                        WHERE awdt.workflow_id = w.id) AS document_type_names_en
                FROM `approval_workflows` w
                WHERE w.comp_id = :comp_id AND w.status != 'deleted'
                ORDER BY w.id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $compId, int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `approval_workflows` WHERE id = :id AND comp_id = :comp_id AND status != 'deleted'");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $workflow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$workflow) {
            return null;
        }

        $stmtDt = $this->db->prepare("SELECT dt.code, dt.name_th, dt.name_en
            FROM `approval_workflow_document_types` awdt
            JOIN `approval_document_types` dt ON dt.code = awdt.document_type_code
            WHERE awdt.workflow_id = :id ORDER BY dt.sort_order ASC");
        $stmtDt->execute([':id' => $id]);
        $documentTypes = $stmtDt->fetchAll(PDO::FETCH_ASSOC);
        $workflow['document_type_codes'] = array_column($documentTypes, 'code');
        $workflow['document_types'] = $documentTypes;

        $stmtSteps = $this->db->prepare("SELECT * FROM `approval_workflow_steps`
            WHERE workflow_id = :id AND status = 'active' ORDER BY step_order ASC");
        $stmtSteps->execute([':id' => $id]);
        $steps = $stmtSteps->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($steps)) {
            $stepIds = array_column($steps, 'id');
            $placeholders = implode(',', array_fill(0, count($stepIds), '?'));
            $stmtApprovers = $this->db->prepare("SELECT sa.step_id, sa.id, sa.approver_type, sa.approver_id,
                    CASE WHEN sa.approver_type = 'user' THEN CONCAT(e.name_th, ' ', e.surname_th) ELSE r.role_name_th END AS approver_label_th,
                    CASE WHEN sa.approver_type = 'user' THEN CONCAT(e.name_en, ' ', e.surname_en) ELSE r.role_name_en END AS approver_label_en
                FROM `approval_workflow_step_approvers` sa
                LEFT JOIN `employees` e ON sa.approver_type = 'user' AND e.id = sa.approver_id
                LEFT JOIN `structure_roles` r ON sa.approver_type = 'role' AND r.id = sa.approver_id
                WHERE sa.step_id IN ({$placeholders}) ORDER BY sa.id ASC");
            $stmtApprovers->execute($stepIds);
            $approversByStep = [];
            foreach ($stmtApprovers->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $approversByStep[(int)$a['step_id']][] = $a;
            }
            foreach ($steps as &$s) {
                $s['approvers'] = $approversByStep[(int)$s['id']] ?? [];
            }
            unset($s);
        }
        $workflow['steps'] = $steps;

        return $workflow;
    }

    private function validApproverRef(int $compId, string $type, $id): bool {
        if (!is_numeric($id) || (int)$id <= 0) {
            return false;
        }
        if ($type === 'user') {
            $stmt = $this->db->prepare("SELECT id FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        } elseif ($type === 'role') {
            $stmt = $this->db->prepare("SELECT id FROM `structure_roles` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        } else {
            return false;
        }
        $stmt->execute([':id' => (int)$id, ':comp_id' => $compId]);
        return (bool)$stmt->fetch();
    }

    /**
     * @return array{steps?: array, error?: string} 'steps' on success (cleaned, step_order
     *   derived from array position — never trusted from the client, which also makes a
     *   duplicate step_order structurally impossible), 'error' on validation failure.
     */
    private function validateSteps(int $compId, array $rawSteps): array {
        if (empty($rawSteps)) {
            return ['error' => 'At least one approval step is required.'];
        }
        $cleaned = [];
        foreach ($rawSteps as $i => $raw) {
            $stepNo = $i + 1;
            $rawApprovers = is_array($raw['approvers'] ?? null) ? $raw['approvers'] : [];
            if (empty($rawApprovers)) {
                return ['error' => "Step {$stepNo}: at least one approver is required."];
            }
            $approvers = [];
            foreach ($rawApprovers as $j => $rawApprover) {
                $approverType = (string)($rawApprover['approver_type'] ?? '');
                if (!in_array($approverType, ['user', 'role'], true)) {
                    return ['error' => "Step {$stepNo}, approver " . ($j + 1) . ": invalid approver_type."];
                }
                if (!$this->validApproverRef($compId, $approverType, $rawApprover['approver_id'] ?? null)) {
                    return ['error' => "Step {$stepNo}, approver " . ($j + 1) . ": approver not found or does not belong to this company."];
                }
                $approvers[] = ['approver_type' => $approverType, 'approver_id' => (int)$rawApprover['approver_id']];
            }
            $jointMode = (string)($raw['joint_approve_mode'] ?? 'any');
            if (!in_array($jointMode, ['any', 'all'], true)) {
                return ['error' => "Step {$stepNo}: invalid joint_approve_mode."];
            }
            $groupType = (string)($raw['group_type'] ?? 'and');
            if (!in_array($groupType, ['and', 'or', 'finish'], true)) {
                return ['error' => "Step {$stepNo}: invalid group_type."];
            }
            $cleaned[] = [
                'step_order' => $stepNo,
                'step_name' => trim((string)($raw['step_name'] ?? '')) ?: null,
                'approvers' => $approvers,
                'joint_approve_mode' => $jointMode,
                'group_type' => $groupType,
                'requires_previous_step' => !empty($raw['requires_previous_step']) ? 1 : 0,
            ];
        }
        return ['steps' => $cleaned];
    }

    /**
     * Content-only comparison of two cleaned step sets (ids/timestamps excluded, approvers sorted
     * deterministically) -- used by save() to decide whether the stored step set actually needs
     * to change at all. Both sides must already be in the same shape as validateSteps()'s output.
     */
    private function stepsAreEquivalent(array $a, array $b): bool {
        $canon = function (array $steps): array {
            return array_map(function (array $s): array {
                $approvers = array_map(fn($a) => ['approver_type' => $a['approver_type'], 'approver_id' => (int)$a['approver_id']], $s['approvers']);
                usort($approvers, fn($x, $y) => $x['approver_type'] <=> $y['approver_type'] ?: $x['approver_id'] <=> $y['approver_id']);
                return [
                    'step_order' => $s['step_order'],
                    'step_name' => $s['step_name'],
                    'approvers' => $approvers,
                    'joint_approve_mode' => $s['joint_approve_mode'],
                    'group_type' => $s['group_type'],
                    'requires_previous_step' => (int)$s['requires_previous_step'],
                ];
            }, $steps);
        };
        return $canon($a) === $canon($b);
    }

    /** Current ACTIVE steps for a workflow, already in the same cleaned shape validateSteps()
     *  produces, for stepsAreEquivalent() to diff against a new save() payload. */
    private function currentStepsForComparison(int $workflowId): array {
        $stmt = $this->db->prepare("SELECT id, step_order, step_name, group_type, requires_previous_step, joint_approve_mode
            FROM `approval_workflow_steps` WHERE workflow_id = :id AND status = 'active' ORDER BY step_order ASC");
        $stmt->execute([':id' => $workflowId]);
        $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($steps)) {
            return [];
        }
        $stepIds = array_column($steps, 'id');
        $placeholders = implode(',', array_fill(0, count($stepIds), '?'));
        $stmtApprovers = $this->db->prepare("SELECT step_id, approver_type, approver_id
            FROM `approval_workflow_step_approvers` WHERE step_id IN ({$placeholders})");
        $stmtApprovers->execute($stepIds);
        $approversByStep = [];
        foreach ($stmtApprovers->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $approversByStep[(int)$a['step_id']][] = ['approver_type' => $a['approver_type'], 'approver_id' => (int)$a['approver_id']];
        }
        return array_map(fn($s) => [
            'step_order' => (int)$s['step_order'],
            'step_name' => $s['step_name'],
            'approvers' => $approversByStep[(int)$s['id']] ?? [],
            'joint_approve_mode' => $s['joint_approve_mode'],
            'group_type' => $s['group_type'],
            'requires_previous_step' => (int)$s['requires_previous_step'],
        ], $steps);
    }

    private function validateDocumentTypeCodes(array $codes): array {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
        if (empty($codes)) {
            return ['error' => 'At least one document type is required.'];
        }
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $stmt = $this->db->prepare("SELECT code FROM `approval_document_types` WHERE code IN ({$placeholders}) AND is_active = 1");
        $stmt->execute($codes);
        $found = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'code');
        $missing = array_diff($codes, $found);
        if (!empty($missing)) {
            return ['error' => 'Unknown document type code(s): ' . implode(', ', $missing)];
        }
        return ['codes' => $codes];
    }

    /** A document_type_code may belong to at most one ACTIVE workflow per company at a time. */
    private function findConflictingActiveWorkflow(int $compId, array $codes, ?int $excludeWorkflowId): ?array {
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $sql = "SELECT w.id, w.workflow_name, awdt.document_type_code
                FROM `approval_workflow_document_types` awdt
                JOIN `approval_workflows` w ON w.id = awdt.workflow_id
                WHERE w.comp_id = ? AND w.status = 'active' AND awdt.document_type_code IN ({$placeholders})";
        $params = array_merge([$compId], $codes);
        if ($excludeWorkflowId !== null) {
            $sql .= " AND w.id != ?";
            $params[] = $excludeWorkflowId;
        }
        $sql .= " LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function save(int $compId, array $data, int $userId): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        $workflowName = trim((string)($data['workflow_name'] ?? ''));
        if ($workflowName === '') {
            return ['status' => false, 'message' => 'Missing required field: workflow_name'];
        }
        $description = trim((string)($data['description'] ?? '')) ?: null;
        $status = (string)($data['status'] ?? 'active');
        if (!in_array($status, ['active', 'inactive'], true)) {
            return ['status' => false, 'message' => 'Invalid status.'];
        }

        $docTypeResult = $this->validateDocumentTypeCodes(is_array($data['document_type_codes'] ?? null) ? $data['document_type_codes'] : []);
        if (isset($docTypeResult['error'])) {
            return ['status' => false, 'message' => $docTypeResult['error']];
        }
        $documentTypeCodes = $docTypeResult['codes'];

        $stepsResult = $this->validateSteps($compId, is_array($data['steps'] ?? null) ? $data['steps'] : []);
        if (isset($stepsResult['error'])) {
            return ['status' => false, 'message' => $stepsResult['error']];
        }
        $steps = $stepsResult['steps'];

        if ($status === 'active') {
            $conflict = $this->findConflictingActiveWorkflow($compId, $documentTypeCodes, $id);
            if ($conflict) {
                return ['status' => false, 'message' => "Document type is already handled by another active workflow: {$conflict['workflow_name']}."];
            }
        }

        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }

            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM `approval_workflows` WHERE id = :id AND comp_id = :comp_id AND status != 'deleted'");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    if ($own) { $this->db->rollBack(); }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $stmt = $this->db->prepare("UPDATE `approval_workflows` SET workflow_name = :name, description = :description,
                    status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([':name' => $workflowName, ':description' => $description, ':status' => $status, ':updated_by' => $userId, ':id' => $id]);
                $workflowId = $id;
            } else {
                $stmt = $this->db->prepare("INSERT INTO `approval_workflows` (comp_id, workflow_name, description, status, created_by)
                    VALUES (:comp_id, :name, :description, :status, :created_by)");
                $stmt->execute([':comp_id' => $compId, ':name' => $workflowName, ':description' => $description, ':status' => $status, ':created_by' => $userId]);
                $workflowId = (int)$this->db->lastInsertId();
            }

            $delDt = $this->db->prepare("DELETE FROM `approval_workflow_document_types` WHERE workflow_id = :id");
            $delDt->execute([':id' => $workflowId]);
            $insDt = $this->db->prepare("INSERT INTO `approval_workflow_document_types` (workflow_id, document_type_code) VALUES (:workflow_id, :code)");
            foreach ($documentTypeCodes as $code) {
                $insDt->execute([':workflow_id' => $workflowId, ':code' => $code]);
            }

            // 2026-08-23: steps are now SOFT-deleted, and only when the saved config actually
            // differs from what's already stored -- an unchanged re-save leaves the existing
            // active rows (and their ids) untouched entirely. Explicit request: "ถ้ามีการ Save
            // Request ใหม่ Approval Flow ต้องเปลี่ยน Flow เดิมให้ลบไปเลยถ้าไม่ใช่ข้อมูลเดิม แต่ต้อง
            // เป็น Soft Delete". approval_workflow_step_approvers rows are left alone on a soft
            // delete (only cascade on a real DELETE) -- harmless since a soft-deleted step is
            // filtered out of every read that matters (get()/list()/snapshotting).
            $currentSteps = $id !== null ? $this->currentStepsForComparison($workflowId) : [];
            if (!$this->stepsAreEquivalent($currentSteps, $steps)) {
                $delSteps = $this->db->prepare("UPDATE `approval_workflow_steps`
                    SET status = 'deleted', deleted_by = :deleted_by, deleted_at = CURRENT_TIMESTAMP
                    WHERE workflow_id = :id AND status = 'active'");
                $delSteps->execute([':deleted_by' => $userId, ':id' => $workflowId]);
                $insStep = $this->db->prepare("INSERT INTO `approval_workflow_steps`
                    (workflow_id, step_order, step_name, group_type, requires_previous_step, joint_approve_mode)
                    VALUES (:workflow_id, :step_order, :step_name, :group_type, :requires_previous_step, :joint_approve_mode)");
                $insApprover = $this->db->prepare("INSERT INTO `approval_workflow_step_approvers` (step_id, approver_type, approver_id)
                    VALUES (:step_id, :approver_type, :approver_id)");
                foreach ($steps as $s) {
                    $insStep->execute([
                        ':workflow_id' => $workflowId,
                        ':step_order' => $s['step_order'],
                        ':step_name' => $s['step_name'],
                        ':group_type' => $s['group_type'],
                        ':requires_previous_step' => $s['requires_previous_step'],
                        ':joint_approve_mode' => $s['joint_approve_mode'],
                    ]);
                    $stepId = (int)$this->db->lastInsertId();
                    foreach ($s['approvers'] as $a) {
                        $insApprover->execute([
                            ':step_id' => $stepId,
                            ':approver_type' => $a['approver_type'],
                            ':approver_id' => $a['approver_id'],
                        ]);
                    }
                }
            }

            if ($own) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => $id !== null ? 'Updated successfully.' : 'Created successfully.', 'id' => $workflowId];
        } catch (PDOException $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function delete(int $compId, int $id, int $userId): array {
        $stmtCheck = $this->db->prepare("SELECT id FROM `approval_workflows` WHERE id = :id AND comp_id = :comp_id AND status != 'deleted'");
        $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
        if (!$stmtCheck->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $stmtPending = $this->db->prepare("SELECT COUNT(*) FROM `approval_requests` WHERE workflow_id = :id AND status = 'pending'");
        $stmtPending->execute([':id' => $id]);
        if ((int)$stmtPending->fetchColumn() > 0) {
            return ['status' => false, 'message' => 'This workflow has pending approval requests and cannot be deleted.'];
        }
        $stmt = $this->db->prepare("UPDATE `approval_workflows` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
        $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    public function toggleStatus(int $compId, int $id, int $userId, string $newStatus): array {
        if (!in_array($newStatus, ['active', 'inactive'], true)) {
            return ['status' => false, 'message' => 'Invalid status.'];
        }
        $workflow = $this->get($compId, $id);
        if (!$workflow) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($newStatus === 'active') {
            $conflict = $this->findConflictingActiveWorkflow($compId, $workflow['document_type_codes'], $id);
            if ($conflict) {
                return ['status' => false, 'message' => "Document type is already handled by another active workflow: {$conflict['workflow_name']}."];
            }
        }
        $stmt = $this->db->prepare("UPDATE `approval_workflows` SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND comp_id = :comp_id");
        $stmt->execute([':status' => $newStatus, ':updated_by' => $userId, ':id' => $id, ':comp_id' => $compId]);
        return ['status' => true, 'message' => 'Status updated successfully.'];
    }

    /** Clones a workflow's steps and document-type mapping into a new workflow, forced inactive
     * (copying an active workflow's mapping verbatim would immediately conflict with the original). */
    public function duplicate(int $compId, int $id, int $userId): array {
        $source = $this->get($compId, $id);
        if (!$source) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $data = [
            'workflow_name' => $source['workflow_name'] . ' (Copy)',
            'description' => $source['description'],
            'status' => 'inactive',
            'document_type_codes' => $source['document_type_codes'],
            'steps' => array_map(fn($s) => [
                'step_name' => $s['step_name'],
                'approvers' => array_map(fn($a) => [
                    'approver_type' => $a['approver_type'],
                    'approver_id' => $a['approver_id'],
                ], $s['approvers']),
                'joint_approve_mode' => $s['joint_approve_mode'],
                'group_type' => $s['group_type'],
                'requires_previous_step' => $s['requires_previous_step'],
            ], $source['steps']),
        ];
        return $this->save($compId, $data, $userId);
    }
}
