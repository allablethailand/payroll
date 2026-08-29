<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/UserPreferenceModel.php';

/** Per-user Settings modal (profile icon -> Settings), see UserPreferenceModel's own docblock. */
class UserPreferenceController extends Controller {
    private UserPreferenceModel $model;

    public function __construct() {
        $this->model = new UserPreferenceModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    public function get() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'No company context.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->get($this->userId(), (int)$compId)]);
    }

    public function save() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'No company context.']);
            return;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $language = array_key_exists('ui_language', $input) && $input['ui_language'] !== ''
            ? (string)$input['ui_language'] : null;
        $fontSize = (string)($input['ui_font_size'] ?? 'm');
        $this->json($this->model->save($this->userId(), (int)$compId, $language, $fontSize));
    }
}
