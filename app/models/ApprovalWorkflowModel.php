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
require_once __DIR__ . '/AuditLogModel.php';
class ApprovalWorkflowModel {
    private PDO $db;
    private AuditLogModel $auditLog;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->auditLog = new AuditLogModel($this->db);
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

    public function save(int $compId, array $data, int $userId, ?string $ip = null, ?string $userAgent = null): array {
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
                $stmtCheck = $this->db->prepare("SELECT * FROM `approval_workflows` WHERE id = :id AND comp_id = :comp_id AND status != 'deleted'");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                $existingWorkflow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                if (!$existingWorkflow) {
                    if ($own) { $this->db->rollBack(); }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $stmt = $this->db->prepare("UPDATE `approval_workflows` SET workflow_name = :name, description = :description,
                    status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([':name' => $workflowName, ':description' => $description, ':status' => $status, ':updated_by' => $userId, ':id' => $id]);
                $workflowId = $id;
                $stmtNewRow = $this->db->prepare("SELECT * FROM `approval_workflows` WHERE id = :id");
                $stmtNewRow->execute([':id' => $workflowId]);
                $newWorkflowRow = $stmtNewRow->fetch(PDO::FETCH_ASSOC) ?: [];
                $this->auditLog->record($compId, 'approval_workflows', $workflowId, 'update', $existingWorkflow, $newWorkflowRow, $userId, 'web', $ip, $userAgent);
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

    public function delete(int $compId, int $id, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        $stmtCheck = $this->db->prepare("SELECT * FROM `approval_workflows` WHERE id = :id AND comp_id = :comp_id AND status != 'deleted'");
        $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $stmtPending = $this->db->prepare("SELECT COUNT(*) FROM `approval_requests` WHERE workflow_id = :id AND status = 'pending'");
        $stmtPending->execute([':id' => $id]);
        if ((int)$stmtPending->fetchColumn() > 0) {
            return ['status' => false, 'message' => 'This workflow has pending approval requests and cannot be deleted.'];
        }
        $stmt = $this->db->prepare("UPDATE `approval_workflows` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
        $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
        $this->auditLog->record($compId, 'approval_workflows', $id, 'update', $existing, array_merge($existing, ['status' => 'deleted']), $userId, 'web', $ip, $userAgent);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    public function toggleStatus(int $compId, int $id, int $userId, string $newStatus, ?string $ip = null, ?string $userAgent = null): array {
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
        $this->auditLog->record($compId, 'approval_workflows', $id, 'update', ['status' => $workflow['status']], ['status' => $newStatus], $userId, 'web', $ip, $userAgent);
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

    /**
     * 2026-08-24, Settings page redesign (explicit request: "ให้แบ่งเป็น Tab อนุมัติงวดเงินเดือน และ
     * อนุมัติ Pay Slip ไปเลยให้ตั้งค่า และในแต่ละ Tab ก็ให้จัดการได้เลย 1 Tab ต่อ 1 Flow ไม่ต้องเปิด Modal
     * เข้าไปจัดการ แต่เป็นการเปิดแก้ไข แถว by แถว มีปุ่ม Save แยกตามแถว และมีปุ่มในการบันทึก Sort...ให้
     * เหมือน [origami's own approval settings page]") -- the old page was a generic DataTable of
     * ANY number of workflows, each spanning ANY number of document types, edited through one big
     * modal form saved as a single atomic unit. The new page fixes 2 tabs, one per document type
     * that actually has a UI to configure it (PAYROLL_RUN_APPROVAL, SLIP_REQUEST_APPROVAL) -- each
     * tab IS that document type's one flow, no workflow list/picker, no document-type multi-select,
     * no workflow_name field (auto-derived below), no modal -- steps render as table-like rows
     * in-page, edited/saved/deleted ONE ROW AT A TIME (getByDocumentType/stepSave/stepDelete below),
     * with a separate explicit "Save Order" action for drag-reorder (stepsSort below) rather than
     * autosaving on every drop like the reference app does. save()/get()/list()/duplicate()/
     * delete()/toggleStatus() above are UNCHANGED and still the general-purpose API surface (a
     * document type this simplified page doesn't cover could still be configured through them
     * directly, or a future admin UI could reintroduce the generic list) -- these new methods are
     * additive, not a replacement.
     *
     * Known simplification: if a workflow ends up mapped to MULTIPLE document types (only possible
     * via the general save()/duplicate() API this page no longer exposes, or pre-existing data from
     * before this redesign), editing it from one tab affects every document type it's mapped to.
     * Not a concern for any real data at the time of this change (confirmed via direct DB check),
     * but a future document type reusing this same simplified-tab pattern should keep its own
     * dedicated workflow, one document type per workflow, to avoid this cross-tab surprise.
     */

    /** The single flow governing one document type, for a tab in the simplified Settings UI --
     *  prefers an ACTIVE workflow mapped to this code; if none is active, falls back to the most
     *  recently updated INACTIVE one instead of showing nothing, so toggling a flow off never loses
     *  its step config. Returns null only if this company has never configured one for this
     *  document type at all (deleted ones don't count either). */
    public function getByDocumentType(int $compId, string $documentTypeCode): ?array {
        $stmt = $this->db->prepare("SELECT w.id FROM `approval_workflows` w
            JOIN `approval_workflow_document_types` awdt ON awdt.workflow_id = w.id
            WHERE w.comp_id = :comp_id AND w.status != 'deleted' AND awdt.document_type_code = :code
            ORDER BY (w.status = 'active') DESC, w.updated_at DESC, w.id DESC LIMIT 1");
        $stmt->execute([':comp_id' => $compId, ':code' => $documentTypeCode]);
        $id = $stmt->fetchColumn();
        return $id !== false ? $this->get($compId, (int)$id) : null;
    }

    /** Auto-creates a bare (zero-step) workflow header the FIRST time a step is saved for a
     *  document type this company has never configured before -- the simplified UI has no separate
     *  "create workflow" action, adding the first step implicitly creates its flow. Name is derived
     *  from the document type itself since the UI no longer has a workflow_name field. Only ever
     *  called from stepSave() when getByDocumentType() already confirmed no non-deleted workflow
     *  exists for this code, so there is nothing to conflict with. */
    private function createBareWorkflow(int $compId, string $documentTypeCode, int $userId): int {
        $stmtDt = $this->db->prepare("SELECT name_th FROM `approval_document_types` WHERE code = :code");
        $stmtDt->execute([':code' => $documentTypeCode]);
        $name = (string)($stmtDt->fetchColumn() ?: $documentTypeCode);
        $stmt = $this->db->prepare("INSERT INTO `approval_workflows` (comp_id, workflow_name, status, created_by)
            VALUES (:comp_id, :name, 'active', :created_by)");
        $stmt->execute([':comp_id' => $compId, ':name' => $name, ':created_by' => $userId]);
        $workflowId = (int)$this->db->lastInsertId();
        $this->db->prepare("INSERT INTO `approval_workflow_document_types` (workflow_id, document_type_code) VALUES (:workflow_id, :code)")
            ->execute([':workflow_id' => $workflowId, ':code' => $documentTypeCode]);
        return $workflowId;
    }

    /**
     * Per-row create/update for the simplified Settings UI. Unlike save() above -- which replaces
     * the WHOLE step set atomically with soft-delete-if-changed semantics -- this touches exactly
     * ONE step (update in place if `step_id` is given, insert at the end of the flow otherwise).
     * @param array{document_type_code:string, step_id?:int, step_name?:string,
     *   approvers:array<int,array{approver_type:string,approver_id:int}>, joint_approve_mode:string,
     *   group_type:string, requires_previous_step?:bool} $data
     * @return array{status:bool, message:string, workflow_id?:int, step_id?:int}
     */
    public function stepSave(int $compId, array $data, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        $documentTypeCode = (string)($data['document_type_code'] ?? '');
        if ($documentTypeCode === '') {
            return ['status' => false, 'message' => 'Missing document_type_code.'];
        }
        $docCheck = $this->validateDocumentTypeCodes([$documentTypeCode]);
        if (isset($docCheck['error'])) {
            return ['status' => false, 'message' => $docCheck['error']];
        }

        $rawApprovers = is_array($data['approvers'] ?? null) ? $data['approvers'] : [];
        if (empty($rawApprovers)) {
            return ['status' => false, 'message' => 'At least one approver is required.'];
        }
        $approvers = [];
        foreach ($rawApprovers as $j => $rawApprover) {
            $approverType = (string)($rawApprover['approver_type'] ?? '');
            if (!in_array($approverType, ['user', 'role'], true)) {
                return ['status' => false, 'message' => 'Invalid approver type.'];
            }
            if (!$this->validApproverRef($compId, $approverType, $rawApprover['approver_id'] ?? null)) {
                return ['status' => false, 'message' => 'Approver ' . ($j + 1) . ' not found or does not belong to this company.'];
            }
            $approvers[] = ['approver_type' => $approverType, 'approver_id' => (int)$rawApprover['approver_id']];
        }
        $jointMode = (string)($data['joint_approve_mode'] ?? 'any');
        if (!in_array($jointMode, ['any', 'all'], true)) {
            return ['status' => false, 'message' => 'Invalid joint_approve_mode.'];
        }
        $groupType = (string)($data['group_type'] ?? 'and');
        if (!in_array($groupType, ['and', 'or', 'finish'], true)) {
            return ['status' => false, 'message' => 'Invalid group_type.'];
        }
        $stepName = trim((string)($data['step_name'] ?? '')) ?: null;
        $requiresPrev = !empty($data['requires_previous_step']) ? 1 : 0;
        $stepId = (!empty($data['step_id']) && is_numeric($data['step_id'])) ? (int)$data['step_id'] : null;

        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            $workflow = $this->getByDocumentType($compId, $documentTypeCode);

            if ($stepId !== null) {
                if (!$workflow) {
                    if ($own) { $this->db->rollBack(); }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $stmtCheck = $this->db->prepare("SELECT * FROM `approval_workflow_steps` WHERE id = :id AND workflow_id = :wf AND status = 'active'");
                $stmtCheck->execute([':id' => $stepId, ':wf' => $workflow['id']]);
                $existingStep = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                if (!$existingStep) {
                    if ($own) { $this->db->rollBack(); }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $workflowId = (int)$workflow['id'];
                $this->db->prepare("UPDATE `approval_workflow_steps` SET step_name = :step_name, group_type = :group_type,
                        requires_previous_step = :requires_previous_step, joint_approve_mode = :joint_approve_mode,
                        updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                    ->execute([
                        ':step_name' => $stepName, ':group_type' => $groupType, ':requires_previous_step' => $requiresPrev,
                        ':joint_approve_mode' => $jointMode, ':id' => $stepId,
                    ]);
                $this->db->prepare("DELETE FROM `approval_workflow_step_approvers` WHERE step_id = :id")->execute([':id' => $stepId]);
            } else {
                $workflowId = $workflow ? (int)$workflow['id'] : $this->createBareWorkflow($compId, $documentTypeCode, $userId);
                $stmtMax = $this->db->prepare("SELECT COALESCE(MAX(step_order), 0) FROM `approval_workflow_steps` WHERE workflow_id = :wf AND status = 'active'");
                $stmtMax->execute([':wf' => $workflowId]);
                $nextOrder = (int)$stmtMax->fetchColumn() + 1;
                $this->db->prepare("INSERT INTO `approval_workflow_steps`
                        (workflow_id, step_order, step_name, group_type, requires_previous_step, joint_approve_mode)
                        VALUES (:workflow_id, :step_order, :step_name, :group_type, :requires_previous_step, :joint_approve_mode)")
                    ->execute([
                        ':workflow_id' => $workflowId, ':step_order' => $nextOrder, ':step_name' => $stepName,
                        ':group_type' => $groupType, ':requires_previous_step' => $requiresPrev, ':joint_approve_mode' => $jointMode,
                    ]);
                $stepId = (int)$this->db->lastInsertId();
            }

            $insApprover = $this->db->prepare("INSERT INTO `approval_workflow_step_approvers` (step_id, approver_type, approver_id)
                VALUES (:step_id, :approver_type, :approver_id)");
            foreach ($approvers as $a) {
                $insApprover->execute([':step_id' => $stepId, ':approver_type' => $a['approver_type'], ':approver_id' => $a['approver_id']]);
            }

            if (isset($existingStep) && $existingStep !== null) {
                $stmtNewStep = $this->db->prepare("SELECT * FROM `approval_workflow_steps` WHERE id = :id");
                $stmtNewStep->execute([':id' => $stepId]);
                $newStep = $stmtNewStep->fetch(PDO::FETCH_ASSOC) ?: [];
                $this->auditLog->record($compId, 'approval_workflow_steps', $stepId, 'update', $existingStep, $newStep, $userId, 'web', $ip, $userAgent);
            }

            if ($own) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Saved successfully.', 'workflow_id' => $workflowId, 'step_id' => $stepId];
        } catch (PDOException $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /** Soft-deletes exactly ONE step (the row-level "Delete" button), then renumbers the workflow's
     *  remaining active steps to a contiguous 1..N sequence in their existing order -- same
     *  end-state a full save() would produce, keeps `requires_previous_step` gating comparisons
     *  (`step_order < :step_order` in ApprovalRequestModel) sane after removing one from the middle. */
    public function stepDelete(int $compId, int $stepId, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        $stmt = $this->db->prepare("SELECT s.* FROM `approval_workflow_steps` s
            JOIN `approval_workflows` w ON w.id = s.workflow_id
            WHERE s.id = :id AND w.comp_id = :comp_id AND s.status = 'active'");
        $stmt->execute([':id' => $stepId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            $this->db->prepare("UPDATE `approval_workflow_steps` SET status = 'deleted', deleted_by = :deleted_by, deleted_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':deleted_by' => $userId, ':id' => $stepId]);
            $this->auditLog->record($compId, 'approval_workflow_steps', $stepId, 'update', $row, array_merge($row, ['status' => 'deleted']), $userId, 'web', $ip, $userAgent);
            $stmtRemaining = $this->db->prepare("SELECT id FROM `approval_workflow_steps` WHERE workflow_id = :wf AND status = 'active' ORDER BY step_order ASC");
            $stmtRemaining->execute([':wf' => $row['workflow_id']]);
            $upd = $this->db->prepare("UPDATE `approval_workflow_steps` SET step_order = :order WHERE id = :id");
            foreach ($stmtRemaining->fetchAll(PDO::FETCH_COLUMN) as $i => $remainingId) {
                $upd->execute([':order' => $i + 1, ':id' => $remainingId]);
            }
            if ($own) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Deleted successfully.'];
        } catch (PDOException $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /** The explicit "Save Order" button after a drag-reorder (2026-08-24 -- deliberately NOT
     *  autosaved on every drop, per explicit request, unlike the reference app it otherwise
     *  mirrors). Sets step_order = array position (1-indexed) for every id in `$stepIds`. The
     *  given id set must EXACTLY match this document type's current active step ids (no partial
     *  reorder, no smuggling in a step from a different workflow/company). */
    public function stepsSort(int $compId, string $documentTypeCode, array $stepIds, int $userId = 0, ?string $ip = null, ?string $userAgent = null): array {
        $workflow = $this->getByDocumentType($compId, $documentTypeCode);
        if (!$workflow) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $stmt = $this->db->prepare("SELECT id, step_order FROM `approval_workflow_steps` WHERE workflow_id = :wf AND status = 'active'");
        $stmt->execute([':wf' => $workflow['id']]);
        $currentRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $currentOrderById = [];
        foreach ($currentRows as $r) {
            $currentOrderById[(int)$r['id']] = (int)$r['step_order'];
        }
        $currentIds = array_map('intval', array_keys($currentOrderById));
        $stepIds = array_map('intval', $stepIds);
        $sortedCurrent = $currentIds;
        sort($sortedCurrent);
        $sortedInput = $stepIds;
        sort($sortedInput);
        if ($sortedCurrent !== $sortedInput) {
            return ['status' => false, 'message' => 'Step list does not match the current flow.'];
        }
        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            $upd = $this->db->prepare("UPDATE `approval_workflow_steps` SET step_order = :order WHERE id = :id AND workflow_id = :wf");
            foreach ($stepIds as $i => $id) {
                $newOrder = $i + 1;
                $upd->execute([':order' => $newOrder, ':id' => $id, ':wf' => $workflow['id']]);
                $oldOrder = $currentOrderById[$id] ?? null;
                if ($oldOrder !== null && $oldOrder !== $newOrder) {
                    $this->auditLog->record($compId, 'approval_workflow_steps', $id, 'update', ['step_order' => $oldOrder], ['step_order' => $newOrder], $userId ?: null, 'web', $ip, $userAgent);
                }
            }
            if ($own) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Order saved.'];
        } catch (PDOException $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }
}
