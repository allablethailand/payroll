<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PayslipDistributionSettingModel.php';

class PayslipDistributionController extends Controller {
    private PayslipDistributionSettingModel $model;

    public function __construct() {
        $this->model = new PayslipDistributionSettingModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    public function channelOptions() {
        $this->json(['status' => true, 'data' => ['items' => $this->model->channelOptions(), 'total_count' => 0]]);
    }

    public function settingsGet() {
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->model->get((int)$compId)]);
    }

    public function settingsSave() {
        $compId = getCompId();
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->model->save($data, (int)$compId, $this->userId()));
    }
}
