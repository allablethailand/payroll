<?php
require_once __DIR__ . '/../models/SubmissionModel.php';
class SubmissionController extends Controller {
    private $model;
    public function __construct(){ $this->model = new SubmissionModel(); }
    public function index() {
        $this->view('submission/index');
    }
}