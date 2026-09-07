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

    /** "สามารถแนบปกได้" -- same decoupled-upload pattern as CompanyProfileController::uploadLogo()/
     *  PayslipTemplateController::uploadLogo(): uploads immediately and returns the path for the
     *  client to include in save()'s own payload as `cover_image_path` (validated again there via
     *  AnnouncementModel::isValidCoverPath() before it's ever persisted). */
    public function uploadCover() {
        if (!$this->requirePermission('announcement.manage')) { return; }
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['status' => false, 'message' => 'File upload failed.']);
            return;
        }
        $file = $_FILES['file'];
        $maxSize = 3 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            $this->json(['status' => false, 'message' => 'File size exceeds 3MB limit.']);
            return;
        }
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        if (!isset($allowedMimes[$detectedMime])) {
            $this->json(['status' => false, 'message' => 'Unsupported file type. Use JPG, PNG, or WEBP.']);
            return;
        }
        $ext = $allowedMimes[$detectedMime];

        $uploadDir = __DIR__ . '/../../public/uploads/announcement_covers/' . (int)$compId . '/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            $this->json(['status' => false, 'message' => 'Failed to prepare storage directory.']);
            return;
        }
        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $uploadDir . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $this->json(['status' => false, 'message' => 'Failed to save file.']);
            return;
        }
        $relativePath = 'public/uploads/announcement_covers/' . (int)$compId . '/' . $safeName;
        $this->json(['status' => true, 'message' => 'Uploaded successfully.', 'cover_image_path' => $relativePath]);
    }

    /** "จัดรูปแบบเนื้อหาได้" -- backs the Quill editor's own Image toolbar button (public/js/setup/
     *  announcements.js's own custom image handler). Quill's DEFAULT image-button behavior embeds
     *  the picked file as a base64 `data:` URI directly in the content -- deliberately NOT used here:
     *  (1) body_th/body_en are a TEXT column (65,535-byte cap), a single embedded image alone can
     *  blow past that; (2) AnnouncementModel::sanitizeRichHtml() strips `data:`/`javascript:` URLs
     *  from every href/src as a blanket XSS defense (a `data:image/svg+xml;base64,...` can smuggle an
     *  embedded <script>), which would silently reduce a base64-embedded image to a broken <img> tag
     *  anyway. This endpoint uploads the file for real and returns a URL for the editor to insert
     *  instead -- same decoupled-upload pattern as uploadCover() above, separate storage root (this
     *  is inline BODY content, not the one distinguished cover_image_path column, so no
     *  isValidCoverPath()-style path-shape re-check is needed on save() -- sanitizeRichHtml() itself
     *  is the only gate an <img src> in body content needs to pass). */
    public function uploadContentImage() {
        if (!$this->requirePermission('announcement.manage')) { return; }
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['status' => false, 'message' => 'File upload failed.']);
            return;
        }
        $file = $_FILES['file'];
        $maxSize = 3 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            $this->json(['status' => false, 'message' => 'File size exceeds 3MB limit.']);
            return;
        }
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        if (!isset($allowedMimes[$detectedMime])) {
            $this->json(['status' => false, 'message' => 'Unsupported file type. Use JPG, PNG, GIF, or WEBP.']);
            return;
        }
        $ext = $allowedMimes[$detectedMime];

        $uploadDir = __DIR__ . '/../../public/uploads/announcement_content/' . (int)$compId . '/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            $this->json(['status' => false, 'message' => 'Failed to prepare storage directory.']);
            return;
        }
        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $uploadDir . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $this->json(['status' => false, 'message' => 'Failed to save file.']);
            return;
        }
        $relativePath = 'public/uploads/announcement_content/' . (int)$compId . '/' . $safeName;
        $this->json(['status' => true, 'message' => 'Uploaded successfully.', 'url' => BASE_URL . '/' . $relativePath]);
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
