<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/ApprovalWorkflowModel.php';
require_once __DIR__ . '/../models/ApprovalRequestModel.php';

class ApprovalWorkflowController extends Controller {
    private $model;
    private $requestModel;

    public function __construct() {
        $this->model = new ApprovalWorkflowModel();
        $this->requestModel = new ApprovalRequestModel();
    }

    private function actingUserId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
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
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->list((int)$compId)]);
    }

    public function workflowGet() {
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
        $this->json($this->model->save((int)$compId, $data, $this->actingUserId()));
    }

    public function workflowDelete() {
        $compId = getCompId();
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->delete((int)$compId, $id, $this->actingUserId()));
    }

    public function workflowDuplicate() {
        $compId = getCompId();
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->duplicate((int)$compId, $id, $this->actingUserId()));
    }

    public function workflowToggleStatus() {
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
        $compId = getCompId();
        $requestId = isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0;
        if (!$compId || $requestId <= 0) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->requestModel->logs((int)$compId, $requestId)]);
    }
}
