<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PayrollRemittanceModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

/**
 * 2026-09-02, Deduction Destination & Third-Party Remittance -- the "Third-Party Remittance" tab
 * on Process Detail. See PayrollRemittanceModel's own docblock for the grouping/status-workflow
 * design; this controller is a thin pass-through, same shape as PayrollRunCashPaymentController
 * (this feature's own closest sibling: a per-run status-tracking report tab).
 */
class PayrollRemittanceController extends Controller {
    private PayrollRemittanceModel $model;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new PayrollRemittanceModel();
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

    /** Viewing reuses payroll_run.view (the same gate the rest of Process Detail already requires)
     *  -- this tab has no separate view concern from the page it lives on. */
    public function list() {
        $compId = getCompId();
        $runId = (int)($_GET['run_id'] ?? 0);
        if (!$compId || $runId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing run_id.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->listForRun($runId, (int)$compId)]);
    }

    public function items() {
        $compId = getCompId();
        $remittanceId = (int)($_GET['id'] ?? 0);
        if (!$compId || $remittanceId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->itemsForRemittance($remittanceId, (int)$compId)]);
    }

    /** multipart/form-data upload (evidence file) + id. Same jpg/png/pdf allowlist a bank-transfer
     *  slip photo or a PDF confirmation would realistically be, 5MB cap (a scanned slip/screenshot,
     *  same order of magnitude as every other document upload in this app). */
    public function markTransferred() {
        if (!$this->requirePermission('payroll_remittance.edit')) return;
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $evidencePath = null;
        $evidenceFileSize = null;
        if (!empty($_FILES['evidence']) && $_FILES['evidence']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['evidence'];
            $maxSize = 5 * 1024 * 1024;
            if ($file['size'] > $maxSize) {
                $this->json(['status' => false, 'message' => 'File too large (max 5MB).']);
                return;
            }
            $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            if (!isset($allowedMimes[$mime])) {
                $this->json(['status' => false, 'message' => 'Only JPG, PNG, or PDF files are allowed.']);
                return;
            }
            $dir = __DIR__ . '/../../public/uploads/remittance_evidence/' . $compId . '/';
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                $this->json(['status' => false, 'message' => 'Could not save the uploaded file.']);
                return;
            }
            $fileName = bin2hex(random_bytes(16)) . '.' . $allowedMimes[$mime];
            if (!move_uploaded_file($file['tmp_name'], $dir . $fileName)) {
                $this->json(['status' => false, 'message' => 'Could not save the uploaded file.']);
                return;
            }
            $evidencePath = 'public/uploads/remittance_evidence/' . $compId . '/' . $fileName;
            $evidenceFileSize = (int)$file['size'];
        }
        $this->json($this->model->markTransferred($id, (int)$compId, $evidencePath, $this->userId(), $evidenceFileSize));
    }

    public function confirmSuccess() {
        if (!$this->requirePermission('payroll_remittance.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->confirmSuccess($id, (int)$compId, $this->userId()));
    }

    public function markFailed() {
        if (!$this->requirePermission('payroll_remittance.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        $note = (string)($data['note'] ?? '');
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->markFailed($id, (int)$compId, $note, $this->userId()));
    }

    public function retry() {
        if (!$this->requirePermission('payroll_remittance.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->retryToPending($id, (int)$compId, $this->userId()));
    }
}
