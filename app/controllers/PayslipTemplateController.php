<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PayslipTemplateModel.php';
require_once __DIR__ . '/../services/reports/payment/PaySlipReport.php';
require_once __DIR__ . '/../models/PermissionModel.php';

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

    public function fieldTypeOptions() {
        $this->json(['status' => true, 'data' => ['items' => $this->model->fieldTypeOptions(), 'total_count' => 0]]);
    }

    public function list() {
        if (!$this->requirePermission('payslip_template.manage')) return;
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->model->list((int)$compId)]);
    }

    public function get() {
        if (!$this->requirePermission('payslip_template.manage')) return;
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $row = $this->model->get($id, (int)$compId);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $row]);
    }

    public function save() {
        if (!$this->requirePermission('payslip_template.manage')) return;
        $compId = getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->model->save($data, (int)$compId, $this->userId()));
    }

    public function delete() {
        if (!$this->requirePermission('payslip_template.manage')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->model->delete($id, (int)$compId, $this->userId()));
    }

    public function toggleStatus() {
        if (!$this->requirePermission('payslip_template.manage')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->model->toggleStatus($id, (int)$compId, $this->userId()));
    }

    /**
     * Renders the modal's current (unsaved) field selection against mock data and streams back
     * a PDF directly -- no persistence, nothing written to payslip_templates.
     */
    public function preview() {
        if (!$this->requirePermission('payslip_template.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        try {
            $pdfContent = (new PaySlipReport())->generatePreview($data, (int)$compId);
        } catch (InvalidArgumentException $e) {
            http_response_code(422);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => $e->getMessage()]);
            return;
        } catch (LocalizedException $e) {
            $validationKeys = ['invalid_field_selection', 'select_at_least_one_field'];
            http_response_code(in_array($e->getErrorKey(), $validationKeys, true) ? 422 : 500);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => $e->getMessage(), 'error_key' => $e->getErrorKey()]);
            return;
        } catch (RuntimeException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => $e->getMessage()]);
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="payslip_preview.pdf"');
        echo $pdfContent;
    }

    /** Uploads a logo image, returns its web-relative path for the client to include in save(). */
    public function uploadLogo() {
        if (!$this->requirePermission('payslip_template.manage')) return;
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
        $maxSize = 2 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            $this->json(['status' => false, 'message' => 'File size exceeds 2MB limit.']);
            return;
        }
        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/svg+xml' => 'svg',
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        if (!isset($allowedMimes[$detectedMime])) {
            $this->json(['status' => false, 'message' => 'Unsupported file type. Use JPG, PNG, or SVG.']);
            return;
        }
        $ext = $allowedMimes[$detectedMime];

        $uploadDir = __DIR__ . '/../../public/uploads/payslip_logos/' . (int)$compId . '/';
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
        $relativePath = 'public/uploads/payslip_logos/' . (int)$compId . '/' . $safeName;
        $this->json(['status' => true, 'message' => 'Uploaded successfully.', 'logo_path' => $relativePath]);
    }
}
