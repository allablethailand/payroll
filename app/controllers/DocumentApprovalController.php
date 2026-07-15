<?php
require_once __DIR__ . '/../models/DocumentApprovalModel.php';
class DocumentApprovalController extends Controller {
    private $model;
    public function __construct(){ $this->model = new DocumentApprovalModel(); }
    public function index() {
        $this->view('setup/document-approval');
    }
}