<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/MasterModel.php';
class MasterController extends Controller {
    private $model;
    public function __construct(){ $this->model = new MasterModel(); }
    public function getMaster() {
        $page = intval($_POST['page'] ?? 0);
        $limit = intval($_POST['limit'] ?? 10);
        $searchTerm = (string)($_POST['searchTerm'] ?? '');
        $type = (string)($_POST['type'] ?? '');
        $compId = getCompId();
        // 2026-09-03, Manual Entry Phase 1A: optional, only meaningful for type='ot_rate' -- see
        // MasterModel::master()'s own docblock on that case.
        $employeeId = isset($_POST['employee_id']) && $_POST['employee_id'] !== '' ? (int)$_POST['employee_id'] : null;
        $this->json(['status'=>true , 'data' => $this->model->master($page, $limit, $type, $searchTerm, $compId ? (int)$compId : null, $employeeId)]);
    }
}
