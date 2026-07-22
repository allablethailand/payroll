<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/ApprovalWorkflowModel.php';
require_once __DIR__ . '/../models/ApprovalRequestModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

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
        if (!$this->requirePermission('approval_workflow.manage')) return;
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
        $this->json($this->model->save((int)$compId, $data, $this->actingUserId()));
    }

    public function workflowDelete() {
        if (!$this->requirePermission('approval_workflow.manage')) return;
        $compId = getCompId();
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->delete((int)$compId, $id, $this->actingUserId()));
    }

    public function workflowDuplicate() {
        if (!$this->requirePermission('approval_workflow.manage')) return;
        $compId = getCompId();
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->duplicate((int)$compId, $id, $this->actingUserId()));
    }

    public function workflowToggleStatus() {
        if (!$this->requirePermission('approval_workflow.manage')) return;
        $compId = getCompId();
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $status = (string)($_POST['status'] ?? '');
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->toggleStatus((int)$compId, $id, $this->actingUserId(), $status));
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
        $this->json($this->requestModel->act((int)$compId, $requestId, $this->actingUserId(), $action, $note));
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
