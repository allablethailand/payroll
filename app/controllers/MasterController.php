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
        $this->json(['status'=>true , 'data' => $this->model->master($page, $limit, $type, $searchTerm, $compId ? (int)$compId : null)]);
    }
}
