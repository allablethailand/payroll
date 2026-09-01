<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PayrollRunCashPaymentModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';

/**
 * 2026-08-31, explicit request: per-run cash-vs-bank breakdown + per-employee "paid" status for
 * cash-paying employees -- see PayrollRunCashPaymentModel's own docblock.
 */
class PayrollRunCashPaymentController extends Controller {
    private PayrollRunCashPaymentModel $model;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->model = new PayrollRunCashPaymentModel();
        $this->permissionModel = new PermissionModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    /**
     * 2026-08-31, same-day follow-up, real pre-existing bug found and fixed (explicit report: the
     * Cash Payments tab on Process Detail showed its table head but the body stayed empty even for
     * a run with real cash-paying employees, "looks like an error"). Root cause: list()/setStatus()
     * already called `$this->requirePermission(...)`, but this method never actually existed --
     * not on this controller, not on the base Controller class (confirmed directly, same class of
     * bug already found once this session in PayrollSyncController) -- so every call fatal-errored
     * ("Call to undefined method") before ever reaching the model. The AJAX request's success
     * callback never fired (the response wasn't the expected JSON), leaving #runCashTableBody's
     * static <thead> visibly correct while its JS-populated <tbody> silently stayed empty -- exactly
     * the "head shows, body looks broken" symptom reported. Verified directly via
     * ReflectionMethod::invoke() against a real run before writing this fix, not guessed. Same body
     * every other controller's own private requirePermission() uses.
     */
    private function requirePermission(string $permissionKey): bool {
        $compId = (int)getCompId();
        $check = $this->permissionModel->checkPermission($this->userId(), $permissionKey, $this->isAdmin(), $compId);
        if (!$check['allowed']) {
            $this->json(['status' => false, 'message' => 'You do not have permission to perform this action.']);
            return false;
        }
        return true;
    }

    public function list() {
        if (!$this->requirePermission('payroll_run_cash_payment.manage')) return;
        $compId = getCompId();
        $runId = (int)($_GET['run_id'] ?? 0);
        if (!$compId || $runId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing run_id.']);
            return;
        }
        try {
            $this->json(['status' => true, 'data' => $this->model->listForRun($runId, (int)$compId)]);
        } catch (LocalizedException $e) {
            $this->json(['status' => false, 'message' => $e->getMessage(), 'error_key' => $e->getErrorKey(), 'error_params' => $e->getParams()]);
        }
    }

    public function setStatus() {
        if (!$this->requirePermission('payroll_run_cash_payment.manage')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (int)($data['id'] ?? 0);
        $status = (string)($data['status'] ?? '');
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        try {
            $ok = $this->model->setStatus($id, (int)$compId, $status, $this->userId());
            $this->json(['status' => $ok, 'message' => $ok ? null : 'Record not found.']);
        } catch (LocalizedException $e) {
            $this->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }
}
