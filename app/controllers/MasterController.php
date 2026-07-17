<?php
require_once __DIR__ . '/../models/MasterModel.php';
class MasterController extends Controller {
    private $model;
    public function __construct(){ $this->model = new MasterModel(); }
    public function getMaster() {
        $page = intval($_POST['page'] ?? 0);
        $limit = intval($_POST['limit'] ?? 10);
        $searchTerm = $_POST['searchTerm'] ?? '';
        $type = $_POST['type'] ?? '';
        $this->json(['status'=>true , 'data' => $this->model->master($page, $limit, $type, $searchTerm)]);
    }
}