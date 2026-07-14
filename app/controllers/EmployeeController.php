<?php
require_once __DIR__ . '/../models/EmployeeModel.php';
class EmployeeController extends Controller {
    private $model;
    public function __construct(){ $this->model = new EmployeeModel(); }
    public function index() {
        $this->view('employee/list');
    }
    public function create() {
        $this->view('employee/detail', ['employee' => null]);
    }
    public function detail($data = null) {
        $employee_no = $data;
        if (!$employee_no) {
            http_response_code(404);
            echo '404 - Not Found';
            return;
        }
        $this->view('employee/detail', ['employee_no' => $employee_no]);
    }
    public function list(){
        $start = intval($_POST['start'] ?? 0);
        $length= intval($_POST['length'] ?? 10);
        $filters = [
            'role'=> $_POST['role'] ?? '',
            'privileges'=> $_POST['privileges'] ?? '',
            'status'=> $_POST['status'] ?? '',
        ];
        $search = $_POST['search']['value'] ?? '';
        $orderDir    = 'asc';
        if (!empty($_POST['order'][0])) {
            $colIndex   = intval($_POST['order'][0]['column']);
            $orderDir   = $_POST['order'][0]['dir'] === 'desc' ? 'desc' : 'asc';
        }
        $res = $this->model->list(
            $start,
            $length,
            $filters,
            $search,
            $colIndex,
            $orderDir
        );
        $this->json([
            "draw" => intval($_POST['draw'] ?? 1),
            "recordsTotal" => $res['total'],
            "recordsFiltered" => $res['total'],
            "data" => $res['data']
        ]);
    }
}