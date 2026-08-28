<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/DocumentDeliveryLogModel.php';

/**
 * Unified Payslip + Employment Certificate delivery/issuance log listing -- 2026-08-26, see
 * DocumentDeliveryLogModel's own docblock. Read-only: Resend (payslip) still lives on
 * PayslipDeliveryLogController's own endpoint, Download (employment certificate) still lives on
 * EmploymentCertificateRequestController's own endpoint -- this controller only serves the
 * combined list the Delivery Log tab now renders.
 */
class DocumentDeliveryLogController extends Controller {
    private DocumentDeliveryLogModel $model;

    public function __construct() {
        $this->model = new DocumentDeliveryLogModel();
    }

    public function list() {
        $compId = getCompId();
        $filters = [
            'document_type' => (string)($_GET['document_type'] ?? ''),
            'status' => (string)($_GET['status'] ?? ''),
            'channel_code' => (string)($_GET['channel_code'] ?? ''),
            'source' => (string)($_GET['source'] ?? ''),
        ];
        $this->json(['status' => true, 'data' => $this->model->list((int)$compId, $filters)]);
    }
}
