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
        // 2026-09-05 -- the client now sends 'system' as its own literal value (not '') when the
        // employee explicitly picks System, see app.js's own persistUserPreferences(); '' is still
        // accepted here defensively and still maps to null (never configured / clear the saved
        // preference), it just isn't what the Settings modal's System button sends anymore.
        $theme = array_key_exists('ui_theme', $input) && $input['ui_theme'] !== ''
            ? (string)$input['ui_theme'] : null;
        $result = $this->model->save($this->userId(), (int)$compId, $language, $fontSize, $theme);
        // 2026-09-04, T069 Step 1 -- header.php reads ui_theme straight out of the session on
        // every page render (no per-request DB query -- see auth/index.php's own login-time
        // hydration). Without this, a saved theme change would only take effect server-side on
        // the NEXT login, not the rest of THIS session's remaining page loads.
        if (!empty($result['status'])) {
            $_SESSION['user']['ui_theme'] = $theme;
        }
        $this->json($result);
    }
}
