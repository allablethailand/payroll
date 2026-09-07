<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/DocumentNumberingModel.php';

class DocumentNumberingController extends Controller {
    private DocumentNumberingModel $model;

    public function __construct() {
        $this->model = new DocumentNumberingModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    // Platform Hardening Phase 6 (batch 5) -- same shape as every other controller's own copy
    // (BankAccountController, etc.), not promoted to the base Controller class.
    private function requestFingerprint(): array {
        return [
            (string)($_SERVER['REMOTE_ADDR'] ?? '') ?: null,
            (string)($_SERVER['HTTP_USER_AGENT'] ?? '') ?: null,
        ];
    }

    public function list() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->list((int)$compId)]);
    }

    public function save() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$compId || !is_array($data) || empty($data['document_type_code'])) {
            $this->json(['status' => false, 'message' => 'Invalid request.']);
            return;
        }
        [$ip, $ua] = $this->requestFingerprint();
        $this->json($this->model->save((int)$compId, (string)$data['document_type_code'], $data, $this->userId(), $ip, $ua));
    }
}
