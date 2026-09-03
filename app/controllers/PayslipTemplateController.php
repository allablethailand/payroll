<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PayslipTemplateModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';
require_once __DIR__ . '/../services/PayslipTemplateRenderer.php';
require_once __DIR__ . '/../services/ThumbnailGenerator.php';

/**
 * Payslip Template canvas designer backend -- rebuilt to match Employment Certificate Template's own
 * controller shape almost exactly (2026-08-25, explicit request: "ปรับให้การตั้งค่า Slip เงินเดือน
 * Template เป็นเหมือนกับใบรับรอง"; same-day follow-up: "การทำ 2 ภาษาอยากให้เป็นเหมือนหน้าของเอกสาร และ
 * รูปแบบการทำเหมือนกัน" -- the language mechanism now ALSO mirrors Employment Certificate Template's
 * pair_key/TH-EN architecture, see PayslipTemplateModel's own docblock). editPage()/pairedList()/
 * generateOtherLanguage() below are direct ports of EmploymentCertificateTemplateController's own
 * methods of the same name.
 *
 * Permission gate added fresh here (`payslip_template.manage`) -- the OLD PayslipTemplateController
 * had none at all; Employment Certificate Template's own controller already gates every action this
 * way, so this brings Payslip Template in line with that established pattern rather than leaving it
 * as the one designer with no permission check.
 */
class PayslipTemplateController extends Controller {
    private PayslipTemplateModel $model;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new PayslipTemplateModel();
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

    /** Standalone editor page (2026-08-25 follow-up, "รูปแบบการทำเหมือนกัน" -- direct port of
     *  EmploymentCertificateTemplateController::editPage()). `{key}` is a template's pair_key.
     *  Rendered through the normal Controller::view() layout, same as every other page. */
    public function editPage(string $key) {
        $compId = (int)getCompId();
        $pair = $compId ? $this->model->getPairByKey($compId, $key) : null;
        $this->view('payslip-template/edit', ['pair' => $pair]);
    }

    public function fieldTypeOptions() {
        $this->json(['status' => true, 'data' => $this->model->fieldTypeOptions()]);
    }

    /** Full department/team/employee lists for the "Assign To" tab's checkbox lists (2026-08-25,
     *  explicit request: checkboxes instead of a search dropdown -- see PayslipTemplateModel::
     *  assignableOptions()'s own comment). */
    public function assignableOptions() {
        if (!$this->requirePermission('payslip_template.view')) return;
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
        if (!$this->requirePermission('payslip_template.view')) return;
        $compId = getCompId();
        $language = (string)($_GET['language'] ?? '');
        if (!$compId || !in_array($language, ['th', 'en'], true)) {
            $this->json(['status' => false, 'message' => 'Missing or invalid language.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->list((int)$compId, $language)]);
    }

    /** 2026-08-25 follow-up, unified TH/EN list ("รูปแบบการทำเหมือนกัน") -- backs the single
     *  DataTable that replaced the old language_mode column, direct port of
     *  EmploymentCertificateTemplateController::pairedList(). */
    public function pairedList() {
        if (!$this->requirePermission('payslip_template.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->listPaired((int)$compId)]);
    }

    /** "Generate other language, Auto" -- direct port of
     *  EmploymentCertificateTemplateController::generateOtherLanguage(). */
    public function generateOtherLanguage() {
        if (!$this->requirePermission('payslip_template.add')) return;
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
        if (!$this->requirePermission('payslip_template.view')) return;
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
        // PayslipTemplateModel::save() uses (id present = update).
        $isEdit = !empty($data['id']) && is_numeric($data['id']);
        if (!$this->requirePermission($isEdit ? 'payslip_template.edit' : 'payslip_template.add')) return;
        $this->json($this->model->save((int)$compId, $data, $this->userId()));
    }

    public function createFromPreset() {
        if (!$this->requirePermission('payslip_template.add')) return;
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
        // Creating the missing language of an existing pair "ทำเอง" (manually, via the New Template
        // gallery) instead of "Generate Auto" -- if the frontend passes the counterpart's pair_key,
        // this new row links to it instead of starting a new pair.
        $pairKey = !empty($data['pair_key']) ? (string)$data['pair_key'] : null;
        $this->json($this->model->createFromPreset((int)$compId, $language, $preset, $templateName, $this->userId(), $pairKey));
    }

    public function duplicate() {
        if (!$this->requirePermission('payslip_template.add')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->duplicate((int)$compId, $id, $this->userId()));
    }

    /** 2026-08-25 follow-up: the unified list's Duplicate button duplicates a whole PAIR (both
     *  languages, if both exist) as one action -- direct port of
     *  EmploymentCertificateTemplateController::duplicatePair(). */
    public function duplicatePair() {
        if (!$this->requirePermission('payslip_template.add')) return;
        $compId = getCompId();
        $pairKey = trim((string)($_POST['pair_key'] ?? ''));
        if (!$compId || $pairKey === '') {
            $this->json(['status' => false, 'message' => 'Missing pair_key.']);
            return;
        }
        $this->json($this->model->duplicatePair((int)$compId, $pairKey, $this->userId()));
    }

    public function delete() {
        if (!$this->requirePermission('payslip_template.delete')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->delete((int)$compId, $id, $this->userId()));
    }

    public function toggleStatus() {
        if (!$this->requirePermission('payslip_template.edit')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->toggleStatus((int)$compId, $id, $this->userId()));
    }

    public function setDefault() {
        if (!$this->requirePermission('payslip_template.edit')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->setDefault((int)$compId, $id, $this->userId()));
    }

    /** 2026-08-26, explicit request: "ในหน้า List สามารถเปิด Draft หรือ Public ได้จากหน้านั้นเลย" --
     *  callable both from the List page's own toggle and from the editor's own Publish switch. */
    public function publishToggle() {
        if (!$this->requirePermission('payslip_template.edit')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['publish_status'] ?? '');
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->setPublishStatus((int)$compId, $id, $status, $this->userId()));
    }

    /** Plain-JSON wrapper around presetPreviewElements() for "Change Layout" -- re-applies a
     *  different preset's elements to the template currently open in the editor, client-side.
     *  Mirrors EmploymentCertificateTemplateController::presetElements(). */
    public function presetElements() {
        if (!$this->requirePermission('payslip_template.view')) return;
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

    /* ==================== Logo + reusable image library uploads ==================== */

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
        if (!$this->requirePermission('payslip_template.edit')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $result = $this->handleImageUpload('payslip_logos');
        if ($result === null) {
            $this->json(['status' => false, 'message' => 'File upload failed. Use JPG, PNG, or SVG, max 2MB.']);
            return;
        }
        $this->json(['status' => true, 'message' => 'Uploaded successfully.', 'logo_path' => $result['relative_path']]);
    }

    public function listImages() {
        if (!$this->requirePermission('payslip_template.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->listImages((int)$compId)]);
    }

    public function uploadImage() {
        if (!$this->requirePermission('payslip_template.add')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $result = $this->handleImageUpload('payslip_images');
        if ($result === null) {
            $this->json(['status' => false, 'message' => 'File upload failed. Use JPG, PNG, or SVG, max 2MB.']);
            return;
        }
        $this->json($this->model->addImage((int)$compId, $result['relative_path'], $result['original_filename'], $this->userId(), $result['file_size'], $result['thumbnail_path']));
    }

    public function deleteImage() {
        if (!$this->requirePermission('payslip_template.delete')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $result = $this->model->deleteImage((int)$compId, $id);
        if ($result['status'] && !empty($result['file_path'])) {
            $abs = realpath(__DIR__ . '/../../' . ltrim($result['file_path'], '/'));
            $uploadsRoot = realpath(__DIR__ . '/../../public/uploads/payslip_images');
            if ($abs !== false && $uploadsRoot !== false && strpos($abs, $uploadsRoot) === 0 && is_file($abs)) {
                @unlink($abs);
            }
        }
        unset($result['file_path']);
        $this->json($result);
    }

    /* ==================== Preview ==================== */

    /** Renders the editor's CURRENT (possibly unsaved) element set as a PDF -- streamed back
     *  directly, nothing persisted. Mirrors EmploymentCertificateTemplateController::preview(). */
    public function preview() {
        if (!$this->requirePermission('payslip_template.view')) return;
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
        $elements = is_array($data['elements'] ?? null) ? $data['elements'] : [];
        if (empty($elements)) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'Add at least one element before previewing.']);
            return;
        }
        $logoPath = !empty($data['logo_path']) ? (string)$data['logo_path'] : null;
        if (!PayslipTemplateModel::isValidLogoPath($logoPath, (int)$compId)) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'Invalid logo path.']);
            return;
        }
        $pageSize = in_array(($data['page_size'] ?? 'A4'), ['A4', 'Letter', 'Legal'], true) ? $data['page_size'] : 'A4';
        $orientation = in_array(($data['orientation'] ?? 'portrait'), ['portrait', 'landscape'], true) ? $data['orientation'] : 'portrait';
        $language = (string)($data['language'] ?? '');
        if (!in_array($language, ['th', 'en'], true)) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'Invalid language.']);
            return;
        }
        $watermarkEnabled = !empty($data['watermark_enabled']);
        $watermarkText = $watermarkEnabled ? trim((string)($data['watermark_text'] ?? '')) : null;
        try {
            $pdfContent = (new PayslipTemplateRenderer())->renderPreview(
                (int)$compId,
                ['page_size' => $pageSize, 'orientation' => $orientation, 'language' => $language],
                $elements, $logoPath, $watermarkText
            );
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => $e->getMessage()]);
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="payslip_template_preview.pdf"');
        echo $pdfContent;
    }

    /** New Template modal's per-preset Preview button -- renders one of presetOptions()'s layouts
     *  against real company data + mock employee/run data. Mirrors
     *  EmploymentCertificateTemplateController::presetPreview(). */
    public function presetPreview() {
        if (!$this->requirePermission('payslip_template.view')) return;
        $compId = getCompId();
        if (!$compId) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $language = is_array($data) ? (string)($data['language'] ?? '') : '';
        if (!in_array($language, ['th', 'en'], true)) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'Invalid language.']);
            return;
        }
        $preset = is_array($data) ? (string)($data['preset'] ?? '') : '';
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
            $pdfContent = (new PayslipTemplateRenderer())->renderPreview(
                (int)$compId, ['page_size' => 'A4', 'orientation' => 'portrait', 'language' => $language], $elements, null, null
            );
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => $e->getMessage()]);
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="payslip_template_preset_preview.pdf"');
        echo $pdfContent;
    }
}
