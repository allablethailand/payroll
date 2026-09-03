<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/ApprovalWorkflowModel.php';
require_once __DIR__ . '/../models/ApprovalRequestModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';
require_once __DIR__ . '/../models/PayslipRequestModel.php';
require_once __DIR__ . '/../models/EmploymentCertificateRequestModel.php';

class ApprovalWorkflowController extends Controller {
    private $model;
    private $requestModel;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new ApprovalWorkflowModel();
        $this->requestModel = new ApprovalRequestModel();
        $this->permissionModel = new PermissionModel();
    }

    private function actingUserId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    /**
     * Coarse RBAC gate (approval_workflow.view/manage, approval_request.act). requestCreate() is
     * deliberately left ungated -- it's meant to be called by other modules' flows on behalf of
     * whoever is submitting something for approval, not a direct user action through this UI, and
     * nothing calls it from a real flow yet (see CLAUDE.md).
     */
    private function requirePermission(string $permissionKey): bool {
        $compId = (int)getCompId();
        $check = $this->permissionModel->checkPermission($this->actingUserId(), $permissionKey, $this->isAdmin(), $compId);
        if (!$check['allowed']) {
            $this->json(['status' => false, 'message' => 'You do not have permission to perform this action.']);
            return false;
        }
        return true;
    }

    private function jsonBody(): ?array {
        $data = json_decode(file_get_contents('php://input'), true);
        return is_array($data) ? $data : null;
    }

    public function documentTypeOptions() {
        $page = intval($_POST['page'] ?? 1);
        $limit = intval($_POST['limit'] ?? 10);
        $search = (string)($_POST['searchTerm'] ?? '');
        $data = $this->model->documentTypeOptions($search, $page, $limit);
        $this->json(['status' => true, 'data' => $data]);
    }

    public function workflowList() {
        if (!$this->requirePermission('approval_workflow.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->list((int)$compId)]);
    }

    public function workflowGet() {
        if (!$this->requirePermission('approval_workflow.view')) return;
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->model->get((int)$compId, $id);
        if ($row) {
            $this->json(['status' => true, 'data' => $row]);
        } else {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
    }

    public function workflowSave() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = $this->jsonBody();
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: permission needs to know add-vs-edit
        // BEFORE calling the model, same branch ApprovalWorkflowModel::save() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'approval_workflow.edit' : 'approval_workflow.add')) return;
        $this->json($this->model->save((int)$compId, $data, $this->actingUserId()));
    }

    public function workflowDelete() {
        if (!$this->requirePermission('approval_workflow.delete')) return;
        $compId = getCompId();
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->delete((int)$compId, $id, $this->actingUserId()));
    }

    public function workflowDuplicate() {
        if (!$this->requirePermission('approval_workflow.add')) return;
        $compId = getCompId();
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->duplicate((int)$compId, $id, $this->actingUserId()));
    }

    public function workflowToggleStatus() {
        if (!$this->requirePermission('approval_workflow.edit')) return;
        $compId = getCompId();
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $status = (string)($_POST['status'] ?? '');
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->toggleStatus((int)$compId, $id, $this->actingUserId(), $status));
    }

    /** The simplified Settings UI's per-tab flow load (2026-08-24 -- see ApprovalWorkflowModel's
     *  own docblock on getByDocumentType()/stepSave()/stepDelete()/stepsSort() for the full
     *  redesign context). `data: null` (not an error) is the normal "never configured yet" case --
     *  the tab renders an empty step list ready for the first "+ Step". */
    public function flowGet() {
        if (!$this->requirePermission('approval_workflow.view')) return;
        $compId = getCompId();
        $documentTypeCode = (string)($_GET['document_type_code'] ?? '');
        if (!$compId || $documentTypeCode === '') {
            $this->json(['status' => false, 'message' => 'Missing document_type_code.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->getByDocumentType((int)$compId, $documentTypeCode)]);
    }

    public function stepSave() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = $this->jsonBody();
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: same add-vs-edit branch
        // ApprovalWorkflowModel::stepSave() uses (step_id present = update).
        $isEdit = !empty($data['step_id']);
        if (!$this->requirePermission($isEdit ? 'approval_workflow.edit' : 'approval_workflow.add')) return;
        $this->json($this->model->stepSave((int)$compId, $data, $this->actingUserId()));
    }

    public function stepDelete() {
        if (!$this->requirePermission('approval_workflow.delete')) return;
        $compId = getCompId();
        $id = isset($_POST['step_id']) ? (int)$_POST['step_id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing step_id.']);
            return;
        }
        $this->json($this->model->stepDelete((int)$compId, $id, $this->actingUserId()));
    }

    public function stepsSort() {
        if (!$this->requirePermission('approval_workflow.edit')) return;
        $compId = getCompId();
        $data = $this->jsonBody();
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $documentTypeCode = (string)($data['document_type_code'] ?? '');
        $stepIds = is_array($data['step_ids'] ?? null) ? $data['step_ids'] : [];
        if (!$compId || $documentTypeCode === '') {
            $this->json(['status' => false, 'message' => 'Missing document_type_code.']);
            return;
        }
        $this->json($this->model->stepsSort((int)$compId, $documentTypeCode, $stepIds));
    }

    public function requestCreate() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = $this->jsonBody();
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $documentTypeCode = (string)($data['document_type_code'] ?? '');
        $referenceId = isset($data['reference_id']) ? (int)$data['reference_id'] : 0;
        $referenceLabel = isset($data['reference_label']) ? (string)$data['reference_label'] : null;
        $this->json($this->requestModel->create((int)$compId, $documentTypeCode, $referenceId, $referenceLabel, $this->actingUserId()));
    }

    /**
     * After the generic engine acts, a thin per-document-type sync hook runs for any document
     * type that needs one -- keeps ApprovalRequestModel itself document-type-agnostic (per its
     * own docblock) while still letting a terminal outcome (approved/rejected/cancelled) update
     * the document's own table. `EMPLOYMENT_CERTIFICATE_APPROVAL` added 2026-08-26 -- see
     * EmploymentCertificateRequestModel's own docblock for the first real consumer of that
     * document type (seeded config-only back in Employment Certificate Template's own v4).
     */
    private function syncDocumentAfterAct(int $compId, int $requestId, string $requestStatus, array $data): void {
        $request = $this->requestModel->get($compId, $requestId);
        if (!$request) {
            return;
        }
        switch ($request['document_type_code']) {
            case 'SLIP_REQUEST_APPROVAL':
                $selectedChannel = isset($data['selected_channel']) ? (string)$data['selected_channel'] : null;
                (new PayslipRequestModel())->syncFromApprovalStatus((int)$request['reference_id'], $requestStatus, $selectedChannel);
                break;
            case 'EMPLOYMENT_CERTIFICATE_APPROVAL':
                (new EmploymentCertificateRequestModel())->syncFromApprovalStatus((int)$request['reference_id'], $requestStatus);
                break;
        }
    }

    public function requestAct() {
        if (!$this->requirePermission('approval_request.act')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = $this->jsonBody();
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $requestId = isset($data['request_id']) ? (int)$data['request_id'] : 0;
        $action = (string)($data['action'] ?? '');
        $note = isset($data['note']) ? (string)$data['note'] : null;
        $result = $this->requestModel->act((int)$compId, $requestId, $this->actingUserId(), $action, $note);
        if ($result['status'] && !empty($result['request_status'])) {
            $this->syncDocumentAfterAct((int)$compId, $requestId, (string)$result['request_status'], $data);
        }
        $this->json($result);
    }

    public function requestGet() {
        if (!$this->requirePermission('approval_workflow.view')) return;
        $compId = getCompId();
        $requestId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $requestId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->requestModel->get((int)$compId, $requestId);
        if ($row) {
            // 2026-08-30, real access-control gap found and fixed (same class this project's own
            // PayrollRunModel::canApproveThisRun() fix already addressed for the Payroll-specific
            // Approval Queue -- see project memory -- but never ported to this GENERIC engine's own
            // consumers): every caller of this endpoint (the Monitor page's own modal in
            // approval-workflow.js, AND the shared #requestDetailModal in approval-request-detail.js
            // used by Payslip Requests + Employment Certificate Requests) only ever gated its
            // Approve/Reject/Cancel buttons on `status === 'pending'`, never on whether the CURRENT
            // user is actually an eligible, currently-unlocked approver -- `act()` itself already
            // refuses correctly (`actionableRowFor()`, no admin bypass anywhere in this engine, see
            // ApprovalRequestModel::act()'s own code), so this was a UI-only exposure (a wrong click
            // just got a clear error back), not a data-integrity hole -- but still a real gap: anyone
            // with `approval_workflow.view` could see an Approve button on a request they have no
            // eligibility for at all. `can_act_now` is the SAME canActOnRequestNow() check the
            // Payroll-specific fix already uses, now surfaced generically so every consumer of this
            // endpoint can gate its own buttons correctly without duplicating the eligibility logic.
            $row['can_act_now'] = $this->requestModel->canActOnRequestNow((int)$compId, $requestId, $this->actingUserId());
            // Cancel is a SEPARATE permission from Approve/Reject (`act()` itself checks
            // `requested_by === $userId`, unrelated to approver-eligibility) -- computed here rather
            // than left for the frontend to guess from a client-side "who am I" global, so it's
            // driven by the exact same source of truth `act()` will enforce.
            $row['is_requester'] = (int)($row['requested_by'] ?? 0) === $this->actingUserId();
            $this->json(['status' => true, 'data' => $row]);
        } else {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
    }

    public function requestList() {
        if (!$this->requirePermission('approval_workflow.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $filters = [
            'status' => (string)($_GET['status'] ?? ''),
            'document_type_code' => (string)($_GET['document_type_code'] ?? ''),
        ];
        $this->json(['status' => true, 'data' => $this->requestModel->list((int)$compId, $filters)]);
    }

    public function requestLogs() {
        if (!$this->requirePermission('approval_workflow.view')) return;
        $compId = getCompId();
        $requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
        if (!$compId || $requestId <= 0) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->requestModel->logs((int)$compId, $requestId)]);
    }
}
