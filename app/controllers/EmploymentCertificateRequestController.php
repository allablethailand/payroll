<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/EmploymentCertificateRequestModel.php';

/**
 * Employment Certificate request creation + listing (2026-08-26, explicit request paired with the
 * existing Payslip Requests tab). Direct port of PayslipRequestController's own shape -- Approve/
 * Reject/Cancel deliberately live on the existing generic Approval Monitor's
 * `/api/approval-request.act`, not duplicated here (see EmploymentCertificateRequestModel's own
 * docblock). No permission gate here, matching PayslipRequestController's own precedent.
 */
class EmploymentCertificateRequestController extends Controller {
    private EmploymentCertificateRequestModel $model;

    public function __construct() {
        $this->model = new EmploymentCertificateRequestModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    public function list() {
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->model->list((int)$compId)]);
    }

    public function get() {
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $row = $this->model->get((int)$compId, $id);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $row]);
    }

    public function create() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $employeeId = (int)($data['employee_id'] ?? 0);
        $language = (string)($data['language'] ?? 'th');
        $this->json($this->model->create((int)$compId, $employeeId, $language, $this->userId()));
    }

    /** Streams the issued PDF back for download -- only once status='issued' and file_path is set. */
    public function download() {
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $row = $this->model->get((int)$compId, $id);
        if (!$row || empty($row['file_path']) || $row['status'] !== 'issued') {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'This document has not been issued yet.']);
            return;
        }
        $uploadsRoot = realpath(__DIR__ . '/../../public/uploads/employment_certificate_files');
        $abs = realpath(__DIR__ . '/../../' . ltrim((string)$row['file_path'], '/'));
        if ($abs === false || $uploadsRoot === false || strpos($abs, $uploadsRoot) !== 0 || !is_file($abs)) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'The issued file could not be found.']);
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="EmploymentCertificate_' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$row['employee_no']) . '.pdf"');
        readfile($abs);
    }
}
