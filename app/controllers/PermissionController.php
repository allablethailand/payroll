<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PermissionModel.php';

class PermissionController extends Controller {
    private PermissionModel $model;

    public function __construct() {
        $this->model = new PermissionModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    // 2026-08-31, explicit request: "สิทธิ์การใช้งาน...อยากให้แยกออกมาเป็นอีก Menu ไปเลย" -- was pill
    // p6 inside Company Profile's Organizational Structure tab, now its own standalone page. The
    // API actions below (matrix()/save()) are unchanged -- only this new page/route is added.
    public function index() {
        $this->view('setup/permissions');
    }

    public function matrix() {
        $compId = getCompId();
        if (!$this->isAdmin()) {
            $check = $this->model->checkPermission($this->userId(), 'rbac.manage', $this->isAdmin(), (int)$compId);
            if (!$check['allowed']) {
                $this->json(['status' => false, 'message' => 'You do not have permission to view roles & permissions.']);
                return;
            }
        }
        $this->json(['status' => true, 'data' => $this->model->matrix((int)$compId)]);
    }

    public function save() {
        $compId = getCompId();
        if (!$this->isAdmin()) {
            $check = $this->model->checkPermission($this->userId(), 'rbac.manage', $this->isAdmin(), (int)$compId);
            if (!$check['allowed']) {
                $this->json(['status' => false, 'message' => 'You do not have permission to manage roles & permissions.']);
                return;
            }
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data) || !isset($data['grants']) || !is_array($data['grants'])) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->model->saveMatrix((int)$compId, $data['grants'], $this->userId()));
    }
}
