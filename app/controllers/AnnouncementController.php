<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/AnnouncementModel.php';
require_once __DIR__ . '/../models/EntityAssignmentModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

/** Backlog Phase 10, T057. CMS actions gated by `announcement.manage`; employee-facing actions
 *  (pending-list/acknowledge/my-list) need only be logged in -- acknowledge() itself validates the
 *  acting employee is a real recipient (see AnnouncementModel::acknowledge()'s own docblock). */
class AnnouncementController extends Controller {
    private AnnouncementModel $model;
    private EntityAssignmentModel $assignmentModel;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new AnnouncementModel();
        $this->assignmentModel = new EntityAssignmentModel();
        $this->permissionModel = new PermissionModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    private function requirePermission(string $permissionKey): bool {
        $compId = (int)getCompId();
        $check = $this->permissionModel->checkPermission($this->userId(), $permissionKey, $this->isAdmin(), $compId);
        if (!$check['allowed']) {
            $this->json(['status' => false, 'message' => 'You do not have permission to perform this action.']);
            return false;
        }
        return true;
    }

    private function readJsonBody(): ?array {
        $data = json_decode(file_get_contents('php://input'), true);
        return is_array($data) ? $data : null;
    }

    public function settingsPage() {
        if (!$this->requirePermission('announcement.manage')) { return; }
        $this->view('setup/announcements', []);
    }

    public function myListPage() {
        $this->view('announcements/my-list', []);
    }

    public function list() {
        if (!$this->requirePermission('announcement.manage')) { return; }
        $this->json(['status' => true, 'data' => $this->model->list((int)getCompId())]);
    }

    public function get() {
        if (!$this->requirePermission('announcement.manage')) { return; }
        $id = (int)($_GET['id'] ?? 0);
        $row = $this->model->get((int)getCompId(), $id);
        if ($row === null) {
            $this->json(['status' => false, 'message' => 'Announcement not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $row]);
    }

    public function assignableOptions() {
        if (!$this->requirePermission('announcement.manage')) { return; }
        $this->json(['status' => true, 'data' => $this->assignmentModel->assignableOptions((int)getCompId())]);
    }

    public function save() {
        $data = $this->readJsonBody();
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        if (!$this->requirePermission('announcement.manage')) { return; }
        $result = $this->model->save((int)getCompId(), $data, $this->userId());
        $this->json($result);
    }

    public function delete() {
        $data = $this->readJsonBody();
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        if (!$this->requirePermission('announcement.manage')) { return; }
        $result = $this->model->delete((int)getCompId(), (int)($data['id'] ?? 0), $this->userId());
        $this->json($result);
    }

    public function publish() {
        $data = $this->readJsonBody();
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        if (!$this->requirePermission('announcement.manage')) { return; }
        $result = $this->model->publish((int)getCompId(), (int)($data['id'] ?? 0), $this->userId());
        $this->json($result);
    }

    public function setFeatured() {
        $data = $this->readJsonBody();
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        if (!$this->requirePermission('announcement.manage')) { return; }
        $result = $this->model->setDashboardFeatured((int)getCompId(), (int)($data['id'] ?? 0), $this->userId());
        $this->json($result);
    }

    /** Employee-facing: no announcement.manage gate, every logged-in employee sees their OWN pending queue. */
    public function pendingList() {
        $this->json(['status' => true, 'data' => $this->model->pendingForEmployee((int)getCompId(), $this->userId())]);
    }

    public function myList() {
        $this->json(['status' => true, 'data' => $this->model->listForEmployee((int)getCompId(), $this->userId())]);
    }

    /** Employee-facing acknowledge -- acknowledge() itself refuses (not-found framing) if the
     *  acting employee is not a real recipient, so no extra permission gate is needed here. */
    public function acknowledge() {
        $data = $this->readJsonBody();
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $via = (string)($data['via'] ?? 'modal');
        $result = $this->model->acknowledge((int)getCompId(), (int)($data['id'] ?? 0), $this->userId(), $via);
        $this->json($result);
    }
}
