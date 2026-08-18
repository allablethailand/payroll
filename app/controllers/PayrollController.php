<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PayrollRunModel.php';
require_once __DIR__ . '/../services/IdCodec.php';
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
        // {id} in the route is an IdCodec-encoded token (e.g. /payroll-process/AbC12-xY==), not
        // the raw payroll_runs.id -- see IdCodec's own docblock for why. A stale/forged/garbage
        // token just fails to decode and 404s, same as an out-of-range numeric id used to.
        $runId = $id ? IdCodec::decode((string)$id) : null;
        if ($runId === null) {
            http_response_code(404);
            echo '404 - Not Found';
            return;
        }
        $this->view('payroll/detail', ['runId' => $runId]);
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

    /** Any of the 3 payroll role-flags grants read access -- mutating actions already check the SPECIFIC flag they need inside PayrollRunModel. */
    private function requireViewAccess(): bool {
        if (!$this->model->canView($this->userId(), $this->isAdmin())) {
            $this->json(['status' => false, 'message' => 'You do not have permission to view payroll data.']);
            return false;
        }
        return true;
    }

    public function list() {
        if (!$this->requireViewAccess()) return;
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
        $rows = $this->model->list((int)$compId, $filters);
        // public_id is the IdCodec-encoded token used for the /payroll-process/{id} browser URL
        // (row-click navigation, the View action button) -- 'id' itself stays the raw numeric PK,
        // still used as-is for every internal AJAX call (api/payroll-run.get?id=, save/submit/
        // approve/etc. payloads), same as every other list/detail endpoint in this app. Only the
        // URL a user can see/bookmark/share gets obfuscated.
        foreach ($rows as &$row) {
            $row['public_id'] = IdCodec::encode((int)$row['id']);
        }
        unset($row);
        $this->json(['status' => true, 'data' => $rows]);
    }

    public function get() {
        if (!$this->requireViewAccess()) return;
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

    /** Server-side DataTable source for the "Join Employees" picker modal on a genuine off-cycle run. */
    public function manualEmployeeOptions() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_POST['run_id'] ?? 0);
        if (!$compId || $runId <= 0) {
            $this->json(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
            return;
        }
        $start = intval($_POST['start'] ?? 0);
        $length = intval($_POST['length'] ?? 10);
        $filters = [
            'department_id' => $_POST['department_id'] ?? '',
            'position_id' => $_POST['position_id'] ?? '',
        ];
        $search = (string)($_POST['search']['value'] ?? '');
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $res = $this->model->manualEmployeeOptions((int)$compId, $runId, $start, $length, $filters, $search, (string)$lang);
        $this->json([
            'draw' => intval($_POST['draw'] ?? 1),
            'recordsTotal' => $res['total'],
            'recordsFiltered' => $res['filtered'],
            'data' => $res['data'],
        ]);
    }

    public function joinEmployees() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeIds = (is_array($data) && isset($data['employee_ids']) && is_array($data['employee_ids'])) ? $data['employee_ids'] : [];
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->joinEmployees($id, (int)$compId, $employeeIds, $this->userId(), $this->isAdmin()));
    }

    public function removeEmployee() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        if (!$compId || $id <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->removeManualEmployee($id, (int)$compId, $employeeId, $this->userId(), $this->isAdmin()));
    }

    public function manualLinesForEmployee() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_GET['run_id'] ?? 0);
        $employeeId = intval($_GET['employee_id'] ?? 0);
        if (!$compId || $runId <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->manualLinesForEmployee((int)$compId, $runId, $employeeId)]);
    }

    public function addManualLine() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $pedTypeId = (is_array($data) && isset($data['ped_type_id'])) ? (int)$data['ped_type_id'] : 0;
        $amount = (is_array($data) && isset($data['amount']) && is_numeric($data['amount'])) ? (float)$data['amount'] : 0.0;
        if (!$compId || $id <= 0 || $employeeId <= 0 || $pedTypeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->addManualLine($id, (int)$compId, $employeeId, $pedTypeId, $amount, $this->userId(), $this->isAdmin()));
    }

    public function removeManualLine() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $lineId = (is_array($data) && isset($data['line_id'])) ? (int)$data['line_id'] : 0;
        if (!$compId || $id <= 0 || $lineId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->removeManualLine($id, (int)$compId, $lineId, $this->userId(), $this->isAdmin()));
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

    public function cancel() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $reason = (string)($data['reason'] ?? '');
        $this->json($this->model->cancel($id, (int)$compId, $this->userId(), $this->isAdmin(), $reason));
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
