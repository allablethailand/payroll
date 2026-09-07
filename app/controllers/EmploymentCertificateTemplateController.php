<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/EmploymentCertificateTemplateModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';
require_once __DIR__ . '/../services/EmploymentCertificateRenderer.php';
require_once __DIR__ . '/../services/ThumbnailGenerator.php';

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

    // Platform Hardening Phase 6 (batch 5) -- same shape as every other controller's own copy.
    private function requestFingerprint(): array {
        return [
            (string)($_SERVER['REMOTE_ADDR'] ?? '') ?: null,
            (string)($_SERVER['HTTP_USER_AGENT'] ?? '') ?: null,
        ];
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

    /** Standalone editor page (2026-08-25, explicit request: "หน้าแก้ไขให้เปลี่ยนเป็นการเปิด Tab ใหม่...
     *  โดยส่ง key ไปต่อ /key"; explicit same-day follow-up: "ไม่ต้องแสดงใน modal ครับ ให้เป็น page ปกติได้
     *  เลย มี head ปกติเหมือนหน้า Detail ของพนักงาน" -- a real page (not the earlier "bare", no-nav
     *  version), rendered through the normal layout like every other page in this app). `{key}` is a
     *  template's pair_key. Permission is enforced the same way index() already relies on -- every
     *  API call the page's JS makes (get/save/etc.) is independently gated, this page-render itself
     *  isn't (matches index()'s own existing pattern). */
    public function editPage(string $key) {
        $compId = (int)getCompId();
        $pair = $compId ? $this->model->getPairByKey($compId, $key) : null;
        $this->view('employment-certificate/edit', ['pair' => $pair]);
    }

    public function fieldTypeOptions() {
        $this->json(['status' => true, 'data' => $this->model->fieldTypeOptions()]);
    }

    /** Full department/team/employee lists for the "Assign To" tab's checkbox lists -- mirrors
     *  PayslipTemplateController::assignableOptions() exactly. */
    public function assignableOptions() {
        if (!$this->requirePermission('employment_certificate_template.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => ['departments' => [], 'teams' => [], 'employees' => []]]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->assignableOptions((int)$compId)]);
    }

    public function presetOptions() {
        $this->json(['status' => true, 'data' => $this->model->presetOptions()]);
    }

    /* ==================== Templates ==================== */

    public function list() {
        if (!$this->requirePermission('employment_certificate_template.view')) return;
        $compId = getCompId();
        $language = (string)($_GET['language'] ?? '');
        if (!$compId || !in_array($language, ['th', 'en'], true)) {
            $this->json(['status' => false, 'message' => 'Missing or invalid language.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->list((int)$compId, $language)]);
    }

    /** 2026-08-25, explicit request: unified TH/EN list ("ให้มี th กับ eng ในการจัดการเลย ไม่ต้องแยกเป็น
     *  Tab เหมือนเดิม") -- backs the new single DataTable that replaced the language-pill-tab list. */
    public function pairedList() {
        if (!$this->requirePermission('employment_certificate_template.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->listPaired((int)$compId)]);
    }

    /** "Generate other language, Auto" (2026-08-25, explicit request). */
    public function generateOtherLanguage() {
        if (!$this->requirePermission('employment_certificate_template.add')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->generateOtherLanguage((int)$compId, $id, $this->userId()));
    }

    public function get() {
        if (!$this->requirePermission('employment_certificate_template.view')) return;
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
        if (!$this->requirePermission('employment_certificate_template.view')) return;
        $compId = getCompId();
        $language = (string)($_GET['language'] ?? '');
        if (!$compId || !in_array($language, ['th', 'en'], true)) {
            $this->json(['status' => false, 'message' => 'Missing or invalid language.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->getDefault((int)$compId, $language)]);
    }

    public function save() {
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
        // 2026-09-03, Platform Hardening Phase 3 Stage 3: same add-vs-edit branch
        // EmploymentCertificateTemplateModel::save() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'employment_certificate_template.edit' : 'employment_certificate_template.add')) return;
        [$ip, $ua] = $this->requestFingerprint();
        $this->json($this->model->save((int)$compId, $data, $this->userId(), $ip, $ua));
    }

    public function createFromPreset() {
        if (!$this->requirePermission('employment_certificate_template.add')) return;
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
        // 2026-08-25, explicit request: creating the missing language of an existing pair "ทำเอง"
        // (manually, via the New Template gallery) instead of "Generate Auto" -- if the frontend
        // passes the counterpart's pair_key, this new row links to it instead of starting a new pair.
        $pairKey = !empty($data['pair_key']) ? (string)$data['pair_key'] : null;
        $this->json($this->model->createFromPreset((int)$compId, $language, $preset, $templateName, $this->userId(), $pairKey));
    }

    public function duplicate() {
        if (!$this->requirePermission('employment_certificate_template.add')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->duplicate((int)$compId, $id, $this->userId()));
    }

    public function delete() {
        if (!$this->requirePermission('employment_certificate_template.delete')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        [$ip, $ua] = $this->requestFingerprint();
        $this->json($this->model->delete((int)$compId, $id, $this->userId(), $ip, $ua));
    }

    /** 2026-08-25, unified-list redesign: the list's Duplicate button now duplicates a whole PAIR
     *  (both languages, if both exist) as one action instead of one language at a time. */
    public function duplicatePair() {
        if (!$this->requirePermission('employment_certificate_template.add')) return;
        $compId = getCompId();
        $pairKey = trim((string)($_POST['pair_key'] ?? ''));
        if (!$compId || $pairKey === '') {
            $this->json(['status' => false, 'message' => 'Missing pair_key.']);
            return;
        }
        $this->json($this->model->duplicatePair((int)$compId, $pairKey, $this->userId()));
    }

    /** 2026-08-25, explicit request: "เพิ่มให้สามารถเลือกเปลี่ยน Template ได้" -- lets the designer
     *  re-apply a different preset's layout to the template CURRENTLY open in the editor, replacing
     *  its elements client-side (nothing is persisted here; the admin still has to hit Save). Plain
     *  JSON wrapper around the same presetPreviewElements() the PDF-preview endpoint already uses --
     *  no new model logic needed. */
    public function presetElements() {
        if (!$this->requirePermission('employment_certificate_template.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $language = is_array($data) ? (string)($data['language'] ?? '') : '';
        $preset = is_array($data) ? (string)($data['preset'] ?? '') : '';
        if (!in_array($language, ['th', 'en'], true)) {
            $this->json(['status' => false, 'message' => 'Invalid language.']);
            return;
        }
        try {
            $elements = $this->model->presetPreviewElements($preset, $language);
        } catch (InvalidArgumentException $e) {
            $this->json(['status' => false, 'message' => $e->getMessage()]);
            return;
        }
        $this->json(['status' => true, 'data' => $elements]);
    }

    public function setDefault() {
        if (!$this->requirePermission('employment_certificate_template.edit')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        [$ip, $ua] = $this->requestFingerprint();
        $this->json($this->model->setDefault((int)$compId, $id, $this->userId(), $ip, $ua));
    }

    /** 2026-08-26, explicit request: "ในหน้า List สามารถเปิด Draft หรือ Public ได้จากหน้านั้นเลย" --
     *  direct port of PayslipTemplateController::publishToggle(). */
    public function publishToggle() {
        if (!$this->requirePermission('employment_certificate_template.edit')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['publish_status'] ?? '');
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        [$ip, $ua] = $this->requestFingerprint();
        $this->json($this->model->setPublishStatus((int)$compId, $id, $status, $this->userId(), $ip, $ua));
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
        // Platform Hardening Phase 5B: thumbnail for the reusable Image Library grid (jpg/png only,
        // SVG skipped -- see ThumbnailGenerator's own docblock). A generation failure never fails the
        // upload -- thumbnail_path simply stays null (renderImageLibrary() falls back to file_path).
        $thumbnailRelativePath = null;
        if (ThumbnailGenerator::isSupportedMime($detectedMime)) {
            $thumbName = 'thumb_' . $safeName;
            if (ThumbnailGenerator::generate($destPath, $uploadDir . $thumbName)) {
                $thumbnailRelativePath = "public/uploads/{$subdir}/{$compId}/{$thumbName}";
            }
        }
        return [
            'relative_path' => "public/uploads/{$subdir}/{$compId}/{$safeName}",
            'original_filename' => (string)($file['name'] ?? ''),
            'file_size' => (int)$file['size'],
            'thumbnail_path' => $thumbnailRelativePath,
        ];
    }

    public function uploadLogo() {
        if (!$this->requirePermission('employment_certificate_template.edit')) return;
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
        if (!$this->requirePermission('employment_certificate_template.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->listImages((int)$compId)]);
    }

    public function uploadImage() {
        if (!$this->requirePermission('employment_certificate_template.add')) return;
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
        $this->json($this->model->addImage((int)$compId, $result['relative_path'], $result['original_filename'], $this->userId(), $result['file_size'], $result['thumbnail_path']));
    }

    public function deleteImage() {
        if (!$this->requirePermission('employment_certificate_template.delete')) return;
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
        if (!$this->requirePermission('employment_certificate_template.view')) return;
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

    /** "New Template" modal's per-preset Preview button (2026-08-24, List+Modal restructure) --
     *  renders one of presetOptions()'s layouts against real company data + a real/mock employee,
     *  same PDF-blob response pattern as preview() above, but sourced from a preset code instead of
     *  a client-submitted canvas payload -- nothing is created/persisted here either. */
    public function presetPreview() {
        if (!$this->requirePermission('employment_certificate_template.view')) return;
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
        $preset = (string)($data['preset'] ?? '');
        try {
            $elements = $this->model->presetPreviewElements($preset, $language);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => $e->getMessage()]);
            return;
        }
        if (empty($elements)) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'The "blank" preset has nothing to preview.']);
            return;
        }
        try {
            $pdfContent = (new EmploymentCertificateRenderer())->renderPreview(
                (int)$compId, ['page_size' => 'A4', 'orientation' => 'portrait'], $language, $elements, null, null, null
            );
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => $e->getMessage()]);
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="employment_certificate_preset_preview.pdf"');
        echo $pdfContent;
    }
}
