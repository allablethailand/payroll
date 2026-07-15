<?php
require_once __DIR__ . '/../models/SetupRulesModel.php';
class SetupRulesController extends Controller {
    private $model;
    public function __construct(){ $this->model = new SetupRulesModel(); }
    public function index() {
        $this->view('setup-rules/index');
    }
}