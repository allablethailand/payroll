<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PayslipDeliveryLogModel.php';
require_once __DIR__ . '/../services/PayslipDeliveryService.php';

class PayslipDeliveryLogController extends Controller {
    private PayslipDeliveryLogModel $model;

    public function __construct() {
        $this->model = new PayslipDeliveryLogModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    public function list() {
        $compId = getCompId();
        $filters = [
            'status' => (string)($_GET['status'] ?? ''),
            'channel_code' => (string)($_GET['channel_code'] ?? ''),
            'source' => (string)($_GET['source'] ?? ''),
        ];
        $this->json(['status' => true, 'data' => $this->model->list((int)$compId, $filters)]);
    }

    public function resend() {
        $compId = getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $logId = is_array($data) ? (int)($data['id'] ?? 0) : 0;
        if ($logId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $result = (new PayslipDeliveryService())->resend((int)$compId, $logId, $this->userId());
        $this->json(['status' => $result['success'], 'message' => $result['message']]);
    }
}
