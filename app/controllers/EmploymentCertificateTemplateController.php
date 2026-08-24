<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/EmploymentCertificateTemplateModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';
require_once __DIR__ . '/../services/EmploymentCertificateRenderer.php';

class EmploymentCertificateTemplateController extends Controller {
    private EmploymentCertificateTemplateModel $model;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new EmploymentCertificateTemplateModel();
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

    public function index() {
        $this->view('employment-certificate/settings');
    }

    public function fieldTypeOptions() {
        $this->json(['status' => true, 'data' => $this->model->fieldTypeOptions()]);
    }

    public function presetOptions() {
        $this->json(['status' => true, 'data' => $this->model->presetOptions()]);
    }

    /* ==================== Templates ==================== */

    public function list() {
        if (!$this->requirePermission('employment_certificate_template.manage')) return;
        $compId = getCompId();
        $language = (string)($_GET['language'] ?? '');
        if (!$compId || !in_array($language, ['th', 'en'], true)) {
            $this->json(['status' => false, 'message' => 'Missing or invalid language.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->list((int)$compId, $language)]);
    }

    public function get() {
        if (!$this->requirePermission('employment_certificate_template.manage')) return;
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->model->get((int)$compId, $id);
        if ($row) {
            $this->json(['status' => true, 'data' => $row]);
        } else {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
    }

    /** The template to open by default for a language tab (flagged default, else most recent, else
     *  null). Distinct from get() (a specific known id) -- used on initial tab load / after
     *  create-from-preset when the caller doesn't have an id to ask for yet. */
    public function getDefault() {
        if (!$this->requirePermission('employment_certificate_template.manage')) return;
        $compId = getCompId();
        $language = (string)($_GET['language'] ?? '');
        if (!$compId || !in_array($language, ['th', 'en'], true)) {
            $this->json(['status' => false, 'message' => 'Missing or invalid language.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->getDefault((int)$compId, $language)]);
    }

    public function save() {
        if (!$this->requirePermission('employment_certificate_template.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->model->save((int)$compId, $data, $this->userId()));
    }

    public function createFromPreset() {
        if (!$this->requirePermission('employment_certificate_template.manage')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$compId || !is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $language = (string)($data['language'] ?? '');
        $preset = (string)($data['preset'] ?? 'blank');
        $templateName = trim((string)($data['template_name'] ?? ''));
        if ($templateName === '') {
            $this->json(['status' => false, 'message' => 'Template name is required.']);
            return;
        }
        $this->json($this->model->createFromPreset((int)$compId, $language, $preset, $templateName, $this->userId()));
    }

    public function duplicate() {
        if (!$this->requirePermission('employment_certificate_template.manage')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->duplicate((int)$compId, $id, $this->userId()));
    }

    public function delete() {
        if (!$this->requirePermission('employment_certificate_template.manage')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->delete((int)$compId, $id, $this->userId()));
    }

    public function setDefault() {
        if (!$this->requirePermission('employment_certificate_template.manage')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->setDefault((int)$compId, $id, $this->userId()));
    }

    /* ==================== Logo + reusable image library uploads ==================== */

    /** Shared MIME/size validation for both the per-template logo upload and the image-library
     *  upload below -- only the storage subdirectory differs. */
    private function handleImageUpload(string $subdir): ?array {
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            return null;
        }
        $file = $_FILES['file'];
        $maxSize = 2 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            return null;
        }
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/svg+xml' => 'svg'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        if (!isset($allowedMimes[$detectedMime])) {
            return null;
        }
        $ext = $allowedMimes[$detectedMime];
        $compId = (int)getCompId();
        $uploadDir = __DIR__ . "/../../public/uploads/{$subdir}/{$compId}/";
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return null;
        }
        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $uploadDir . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return null;
        }
        return [
            'relative_path' => "public/uploads/{$subdir}/{$compId}/{$safeName}",
            'original_filename' => (string)($file['name'] ?? ''),
        ];
    }

    public function uploadLogo() {
        if (!$this->requirePermission('employment_certificate_template.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $result = $this->handleImageUpload('employment_cert_logos');
        if ($result === null) {
            $this->json(['status' => false, 'message' => 'File upload failed. Use JPG, PNG, or SVG, max 2MB.']);
            return;
        }
        $this->json(['status' => true, 'message' => 'Uploaded successfully.', 'logo_path' => $result['relative_path']]);
    }

    public function listImages() {
        if (!$this->requirePermission('employment_certificate_template.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->listImages((int)$compId)]);
    }

    public function uploadImage() {
        if (!$this->requirePermission('employment_certificate_template.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $result = $this->handleImageUpload('employment_cert_images');
        if ($result === null) {
            $this->json(['status' => false, 'message' => 'File upload failed. Use JPG, PNG, or SVG, max 2MB.']);
            return;
        }
        $this->json($this->model->addImage((int)$compId, $result['relative_path'], $result['original_filename'], $this->userId()));
    }

    public function deleteImage() {
        if (!$this->requirePermission('employment_certificate_template.manage')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $result = $this->model->deleteImage((int)$compId, $id);
        if ($result['status'] && !empty($result['file_path'])) {
            $abs = realpath(__DIR__ . '/../../' . ltrim($result['file_path'], '/'));
            $uploadsRoot = realpath(__DIR__ . '/../../public/uploads/employment_cert_images');
            if ($abs !== false && $uploadsRoot !== false && strpos($abs, $uploadsRoot) === 0 && is_file($abs)) {
                @unlink($abs);
            }
        }
        unset($result['file_path']);
        $this->json($result);
    }

    /* ==================== Preview ==================== */

    /** Renders the canvas's CURRENT (possibly unsaved) element set as a PDF -- streamed back
     *  directly like PayslipTemplateController::preview(), nothing persisted. Accepts an optional
     *  watermark (text + on/off, per explicit request -- "ใน Mode Preview ให้ตั้งค่าได้ว่าจะใส่ลายน้ำ
     *  หรือไม่ใส่ลายน้ำ และใส่ลายน้ำคำว่าอะไร") -- preview-only, never persisted to the template. */
    public function preview() {
        if (!$this->requirePermission('employment_certificate_template.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $language = (string)($data['language'] ?? '');
        if (!in_array($language, ['th', 'en'], true)) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'Invalid language.']);
            return;
        }
        $elements = is_array($data['elements'] ?? null) ? $data['elements'] : [];
        if (empty($elements)) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'Add at least one element before previewing.']);
            return;
        }
        $logoPath = !empty($data['logo_path']) ? (string)$data['logo_path'] : null;
        if (!EmploymentCertificateTemplateModel::isValidLogoPath($logoPath, (int)$compId)) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'Invalid logo path.']);
            return;
        }
        $pageSize = in_array(($data['page_size'] ?? 'A4'), ['A4', 'Letter', 'Legal'], true) ? $data['page_size'] : 'A4';
        $orientation = in_array(($data['orientation'] ?? 'portrait'), ['portrait', 'landscape'], true) ? $data['orientation'] : 'portrait';
        $watermarkEnabled = !empty($data['watermark_enabled']);
        $watermarkText = $watermarkEnabled ? trim((string)($data['watermark_text'] ?? '')) : null;
        try {
            $pdfContent = (new EmploymentCertificateRenderer())->renderPreview(
                (int)$compId, ['page_size' => $pageSize, 'orientation' => $orientation], $language, $elements, $logoPath, null, $watermarkText
            );
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => $e->getMessage()]);
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="employment_certificate_preview.pdf"');
        echo $pdfContent;
    }
}
