<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/TermsAndConditionsModel.php';

/**
 * 2026-09-05, Backlog Phase 13 -- login-gate Terms & Conditions modal + Profile menu's own
 * "Terms and Conditions" (view again) / acceptance-history surface. No permission gate beyond
 * "logged in" -- same convention as UserPreferenceController (always MY OWN acceptance, never
 * another employee's, so no separate view/manage distinction is meaningful here).
 */
class TermsAndConditionsController extends Controller {
    private TermsAndConditionsModel $model;

    public function __construct() {
        $this->model = new TermsAndConditionsModel();
    }

    private function employeeId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    /** Active version's content + whether the CURRENT employee has already accepted it -- the
     *  header.php forced-modal check and the Profile "view again" menu both call this same
     *  endpoint (the modal only actually force-opens client-side when accepted=false). */
    public function get() {
        $active = $this->model->getActive();
        if ($active === null) {
            $this->json(['status' => true, 'data' => null]);
            return;
        }
        $active['accepted'] = $this->model->hasAcceptedActive($this->employeeId());
        $this->json(['status' => true, 'data' => $active]);
    }

    public function accept() {
        $employeeId = $this->employeeId();
        if ($employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Not logged in.']);
            return;
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $result = $this->model->acceptActive($employeeId, is_string($ip) ? $ip : null);
        $this->json($result);
    }

    /** Profile > Terms and Conditions' own "when did I accept which version" list. */
    public function history() {
        $this->json(['status' => true, 'data' => $this->model->acceptanceHistory($this->employeeId())]);
    }

    /** 2026-09-07 -- one row of that history table, clicked into: the FULL text of a specific past
     *  version this employee actually accepted (see getVersionForEmployee()'s own docblock for the
     *  authorization scoping). */
    public function version() {
        $termsId = (int)($_GET['id'] ?? 0);
        if ($termsId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->model->getVersionForEmployee($termsId, $this->employeeId());
        if ($row === null) {
            $this->json(['status' => false, 'message' => 'Version not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $row]);
    }
}
