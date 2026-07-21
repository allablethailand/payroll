<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/BankAccountModel.php';
class BankAccountController extends Controller {
    private $model;
    public function __construct() {
        $this->model = new BankAccountModel();
    }
    public function list() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
            return;
        }
        $request = $_REQUEST;
        $start = isset($request['start']) ? (int)$request['start'] : 0;
        $length = isset($request['length']) ? (int)$request['length'] : 10;
        $search = isset($request['search']['value']) ? (string)$request['search']['value'] : '';
        $colIndex = isset($request['order'][0]['column']) ? (int)$request['order'][0]['column'] : 0;
        $orderDir = isset($request['order'][0]['dir']) ? (string)$request['order'][0]['dir'] : 'asc';
        $result = $this->model->list((int)$compId, $start, $length, $search, $colIndex, $orderDir);
        foreach ($result['data'] as &$row) {
            $row['is_default'] = isset($row['is_default']) ? (bool)$row['is_default'] : false;
        }
        $this->json($result);
    }
    public function save() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->model->save((int)$compId, $data, $userId);
        $this->json($result);
    }
    public function delete() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->model->delete((int)$compId, $id, $userId);
        $this->json($result);
    }
}
