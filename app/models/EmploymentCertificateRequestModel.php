<?php
declare(strict_types=1);
require_once __DIR__ . '/ApprovalRequestModel.php';
require_once __DIR__ . '/EmploymentCertificateTemplateModel.php';
require_once __DIR__ . '/../services/EmploymentCertificateRenderer.php';

/**
 * Employment Certificate request/issuance flow (2026-08-26, explicit request: "ในส่วนของ Request
 * เพิ่ม Tab สำหรับการ Request ใบรับรองขึ้นมาด้วยคู่กับ Pay slip และการอนุมัติให้เป็นรูปแบบเดียวกับ Approve
 * Process ครับมี timeline ให้กดดู"). Direct port of PayslipRequestModel's own architecture (see that
 * class's docblock) -- HR submits on behalf of the employee for now (no employee self-service portal
 * exists in this codebase yet), Approve/Reject/Cancel is NOT duplicated here: it reuses the existing
 * generic Approval Monitor's `/api/approval-request.act` untouched -- this model only creates the
 * paired row and reacts to the outcome via ApprovalWorkflowController::requestAct()'s sync hook (see
 * syncFromApprovalStatus()).
 *
 * The one real structural difference from Payslip's own version: confirmed via AskUserQuestion that
 * approval should auto-generate the actual PDF (not just track request/approval status) -- so
 * syncFromApprovalStatus() calls EmploymentCertificateRenderer::renderForIssuance() and persists the
 * PDF to disk on 'approved', the same two-phase pattern Payslip's own 'approved' -> 'sent'/
 * 'send_failed' already established via its Delivery Engine (here: 'approved' -> 'issued'/
 * 'issue_failed', since there is no delivery-channel concept for a certificate, just a downloadable
 * file). Generated ONCE at approval time and never re-rendered afterward -- a later change to the
 * employee's data or the template must not retroactively alter an already-issued document, the same
 * "immutable once issued" property any real legal/HR document needs.
 *
 * `EMPLOYMENT_CERTIFICATE_APPROVAL` was seeded into `approval_document_types` back in Employment
 * Certificate Template's own v4 (config-only, no consumer yet, see CLAUDE.md) -- this is the first
 * real consumer of it.
 */
class EmploymentCertificateRequestModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function list(int $compId): array {
        $stmt = $this->db->prepare("SELECT ecr.*,
                CONCAT(e.name_th, ' ', e.surname_th) AS employee_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS employee_name_en,
                e.employee_no,
                CONCAT(rb.name_th, ' ', rb.surname_th) AS requested_by_name_th, CONCAT(rb.name_en, ' ', rb.surname_en) AS requested_by_name_en,
                ar.status AS approval_status, ar.current_step_order,
                s.step_name AS current_step_name
            FROM employment_certificate_requests ecr
            JOIN employees e ON e.id = ecr.employee_id
            LEFT JOIN employees rb ON rb.id = ecr.requested_by
            LEFT JOIN approval_requests ar ON ar.id = ecr.approval_request_id
            LEFT JOIN approval_workflow_steps s ON s.workflow_id = ar.workflow_id AND s.step_order = ar.current_step_order
            WHERE ecr.comp_id = :comp_id
            ORDER BY ecr.created_at DESC");
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $compId, int $id): ?array {
        $stmt = $this->db->prepare("SELECT ecr.*,
                CONCAT(e.name_th, ' ', e.surname_th) AS employee_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS employee_name_en,
                e.employee_no
            FROM employment_certificate_requests ecr
            JOIN employees e ON e.id = ecr.employee_id
            WHERE ecr.id = :id AND ecr.comp_id = :comp_id");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function hasPendingRequest(int $employeeId, string $language): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM employment_certificate_requests WHERE employee_id = :employee_id AND language = :language AND status = 'pending'");
        $stmt->execute([':employee_id' => $employeeId, ':language' => $language]);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function create(int $compId, int $employeeId, string $language, int $requestedBy): array {
        if ($employeeId <= 0) {
            return ['status' => false, 'message' => 'employee_id is required.'];
        }
        if (!in_array($language, ['th', 'en'], true)) {
            return ['status' => false, 'message' => 'Invalid language.'];
        }

        $stmtEmp = $this->db->prepare("SELECT id, name_th, surname_th FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $employee = $stmtEmp->fetch(PDO::FETCH_ASSOC);
        if (!$employee) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }
        if ($this->hasPendingRequest($employeeId, $language)) {
            return ['status' => false, 'message' => 'A pending request already exists for this employee and language.'];
        }

        // Confirmed upfront that a template actually exists for this employee+language, so a
        // request can never be approved into a dead end at issuance time (see class docblock).
        $template = (new EmploymentCertificateTemplateModel())->resolveTemplateForEmployee($compId, $employeeId, $language);
        if ($template === null || empty($template['elements'])) {
            $languageLabel = $language === 'en' ? 'English' : 'Thai';
            return ['status' => false, 'message' => "No employment certificate template has been set up for {$languageLabel} yet. Set one up under Payslip & Documents > Settings first."];
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare("INSERT INTO employment_certificate_requests (comp_id, employee_id, language, requested_by, status)
                VALUES (:comp_id, :employee_id, :language, :requested_by, 'pending')");
            $stmt->execute([':comp_id' => $compId, ':employee_id' => $employeeId, ':language' => $language, ':requested_by' => $requestedBy]);
            $requestId = (int)$this->db->lastInsertId();

            $label = "Employment Certificate - {$employee['name_th']} {$employee['surname_th']} ({$language})";
            $approvalModel = new ApprovalRequestModel($this->db);
            $approvalResult = $approvalModel->create($compId, 'EMPLOYMENT_CERTIFICATE_APPROVAL', $requestId, $label, $requestedBy);
            if (!$approvalResult['status']) {
                if ($ownTransaction) {
                    $this->db->rollBack();
                }
                return $approvalResult;
            }

            $this->db->prepare("UPDATE employment_certificate_requests SET approval_request_id = :approval_request_id WHERE id = :id")
                ->execute([':approval_request_id' => $approvalResult['id'], ':id' => $requestId]);

            if ($ownTransaction) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Employment certificate request submitted for approval.', 'id' => $requestId];
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /**
     * Called from ApprovalWorkflowController::requestAct() right after the generic engine acts on
     * a request -- kept OUT of ApprovalRequestModel itself so the generic engine stays document-
     * type-agnostic, exactly like PayslipRequestModel::syncFromApprovalStatus(). No-op for anything
     * other than a terminal outcome.
     */
    public function syncFromApprovalStatus(int $approvalRequestId, string $requestStatus): void {
        if (!in_array($requestStatus, ['approved', 'rejected', 'cancelled'], true)) {
            return;
        }
        $stmt = $this->db->prepare("SELECT id FROM employment_certificate_requests WHERE approval_request_id = :approval_request_id");
        $stmt->execute([':approval_request_id' => $approvalRequestId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return;
        }
        $this->db->prepare("UPDATE employment_certificate_requests SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':status' => $requestStatus, ':id' => (int)$id]);

        if ($requestStatus === 'approved') {
            // Best-effort: a rendering failure (dompdf error, template deleted between request and
            // approval, etc.) must not undo the approval itself -- issuePdf() itself already leaves
            // the row at 'issue_failed' with a reason on any handled failure; this outer catch is
            // only a safety net against an unexpected exception escaping issuePdf() entirely, same
            // precedent as PayslipRequestModel's own deliverForRequest() safety net.
            try {
                $this->issuePdf((int)$id);
            } catch (Throwable $e) {
                $this->db->prepare("UPDATE employment_certificate_requests SET status = 'issue_failed', issue_error = :err WHERE id = :id")
                    ->execute([':err' => substr($e->getMessage(), 0, 255), ':id' => (int)$id]);
            }
        }
    }

    /** Renders the real, final PDF for an approved request and persists it to disk -- generated
     *  ONCE here, never again (see class docblock on why re-rendering later would be wrong). */
    private function issuePdf(int $requestId): void {
        $stmt = $this->db->prepare("SELECT * FROM employment_certificate_requests WHERE id = :id");
        $stmt->execute([':id' => $requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            return;
        }
        $compId = (int)$request['comp_id'];
        $employeeId = (int)$request['employee_id'];
        $language = (string)$request['language'];

        $template = (new EmploymentCertificateTemplateModel())->resolveTemplateForEmployee($compId, $employeeId, $language);
        if ($template === null || empty($template['elements'])) {
            $this->db->prepare("UPDATE employment_certificate_requests SET status = 'issue_failed', issue_error = :err WHERE id = :id")
                ->execute([':err' => 'No template is configured for this employee/language anymore.', ':id' => $requestId]);
            return;
        }

        $pdfContent = (new EmploymentCertificateRenderer($this->db))->renderForIssuance($compId, $template, $language, $template['elements'], $employeeId);

        $uploadDir = __DIR__ . "/../../public/uploads/employment_certificate_files/{$compId}/";
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            $this->db->prepare("UPDATE employment_certificate_requests SET status = 'issue_failed', issue_error = :err WHERE id = :id")
                ->execute([':err' => 'Could not create the storage directory for the issued file.', ':id' => $requestId]);
            return;
        }
        $fileName = bin2hex(random_bytes(16)) . '.pdf';
        $relativePath = "public/uploads/employment_certificate_files/{$compId}/{$fileName}";
        file_put_contents(__DIR__ . '/../../' . $relativePath, $pdfContent);

        $this->db->prepare("UPDATE employment_certificate_requests SET status = 'issued', file_path = :file_path, issue_error = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':file_path' => $relativePath, ':id' => $requestId]);
    }
}
