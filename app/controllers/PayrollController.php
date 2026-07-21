<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PayrollRunModel.php';
class PayrollController extends Controller {
    private $model;
    public function __construct(){ $this->model = new PayrollRunModel(); }

    public function index() {
        $this->view('payroll/index');
    }

    public function approvalQueue() {
        $this->view('payroll/approval');
    }

    public function detail($id = null) {
        if (!$id || !is_numeric($id)) {
            http_response_code(404);
            echo '404 - Not Found';
            return;
        }
        $this->view('payroll/detail', ['runId' => (int)$id]);
    }

    public function options() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => ['items' => [], 'total_count' => 0]]);
            return;
        }
        $page = intval($_POST['page'] ?? 1);
        $limit = intval($_POST['limit'] ?? 10);
        $search = (string)($_POST['searchTerm'] ?? '');
        $statesParam = (string)($_POST['states'] ?? '');
        $allowedStates = $statesParam !== '' ? explode(',', $statesParam) : null;
        $data = $this->model->options((int)$compId, $search, $page, $limit, $allowedStates);
        $this->json(['status' => true, 'data' => $data]);
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    public function list() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $filters = [
            'state' => (string)($_GET['state'] ?? ''),
            'date_from' => (string)($_GET['date_from'] ?? ''),
            'date_to' => (string)($_GET['date_to'] ?? ''),
        ];
        $this->json(['status' => true, 'data' => $this->model->list((int)$compId, $filters)]);
    }

    public function get() {
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->model->get($id, (int)$compId);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $row['details'] = $this->model->getDetails($id, (int)$compId);
        $row['audit_log'] = $this->model->getAuditLog($id, (int)$compId);
        $this->json(['status' => true, 'data' => $row]);
    }

    public function save() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $result = $id
            ? $this->model->update($id, (int)$compId, $data, $this->userId(), $this->isAdmin())
            : $this->model->create((int)$compId, $data, $this->userId(), $this->isAdmin());
        $this->json($result);
    }

    public function delete() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->delete($id, (int)$compId, $this->userId(), $this->isAdmin()));
    }

    public function recalculate() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->recalculate($id, (int)$compId, $this->userId(), $this->isAdmin()));
    }

    public function submit() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->submit($id, (int)$compId, $this->userId(), $this->isAdmin()));
    }

    public function revert() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $note = !empty($data['note']) ? (string)$data['note'] : null;
        $this->json($this->model->revert($id, (int)$compId, $this->userId(), $this->isAdmin(), $note));
    }

    public function approve() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $note = !empty($data['note']) ? (string)$data['note'] : null;
        $this->json($this->model->approve($id, (int)$compId, $this->userId(), $this->isAdmin(), $note));
    }

    public function reject() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $reason = (string)($data['reason'] ?? '');
        $this->json($this->model->reject($id, (int)$compId, $this->userId(), $this->isAdmin(), $reason));
    }

    public function reviseAfterReject() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->reviseAfterReject($id, (int)$compId, $this->userId(), $this->isAdmin()));
    }

    public function markPaid() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->markPaid($id, (int)$compId, $this->userId(), $this->isAdmin(), $data));
    }

    public function lock() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->lock($id, (int)$compId, $this->userId(), $this->isAdmin()));
    }
}
