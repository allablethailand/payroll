<?php
require_once __DIR__ . '/../models/NotificationModel.php';
class NotificationController extends Controller {
    private $model;
    public function __construct(){ $this->model = new NotificationModel(); }
    public function index() {
        $this->view('setup/notification');
    }
}