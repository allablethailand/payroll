<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/reports/payment/PaySlipReport.php';
require_once __DIR__ . '/../services/reports/LocalizedException.php';

class PayslipController extends Controller {
    public function requests() {
        $this->view('payslip/requests');
    }
    public function settings() {
        $this->view('payslip/settings');
    }

    /**
     * 2026-09-04, Backlog Phase 11, T062 -- self-service "view MY OWN payslip" download, added
     * specifically so LineChannel's push-message text can include a real, clickable link an
     * employee can open from the LINE app itself (LINE's Messaging API has no generic
     * file-attachment message type, unlike email/Telegram -- see LineChannel's own docblock).
     * Deliberately NOT the admin api/report.generate path (ReportsController::generate(), gated
     * by salary_amount.view_reports -- an ADMIN/HR-only permission an ordinary employee viewing
     * their OWN payslip would almost never hold). This endpoint needs no separate permission
     * check at all: `employee_id` is read ONLY from the logged-in session
     * ($_SESSION['user']['employee_id'], never a client-suppliable value), and
     * PaySlipReport::generate() itself already enforces both (a) the run belongs to THIS
     * company (getRun($runId, $compId), throws run_not_found otherwise) and (b) this exact
     * employee is really a participant of that run (getRunDetailForEmployee(), throws
     * employee_not_in_run otherwise) -- so the caller can structurally never retrieve anyone
     * else's payslip or another company's run this way, the same trust boundary a "you can
     * always see your own record" self-service page already relies on elsewhere in this app.
     * No token/signed-URL scheme -- reuses the app's own normal Origami-SSO session
     * (ensure_login(), enforced globally already) exactly like every other page, so opening this
     * link outside an active session just redirects through the normal login flow first, same as
     * any other in-app link would.
     */
    public function myDownload() {
        $compId = (int)getCompId();
        $employeeId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $runId = (int)($_GET['run_id'] ?? 0);
        if ($compId <= 0 || $employeeId <= 0 || $runId <= 0) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'Missing or invalid parameters.']);
            return;
        }
        try {
            $result = (new PaySlipReport())->generate(
                ['comp_id' => $compId, 'run_id' => $runId, 'employee_id' => $employeeId],
                'pdf'
            );
        } catch (LocalizedException|RuntimeException|InvalidArgumentException $e) {
            // Never distinguishes "wrong run" from "not your payslip" from "not ready yet" in the
            // response -- a flat 404 either way, same "don't confirm/deny existence to someone
            // with no business knowing" posture this app already applies elsewhere (e.g.
            // ApprovalWorkflowController's own not-found framing for a cross-company id).
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'message' => 'Payslip not available.']);
            return;
        }
        header('Content-Type: ' . $result['mime_type']);
        header('Content-Disposition: inline; filename="' . $result['file_name'] . '"');
        header('Content-Length: ' . strlen($result['content']));
        echo $result['content'];
        exit;
    }
}
