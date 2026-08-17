<?php
declare(strict_types=1);

/**
 * ingest() receives Origami Payroll's attendance-derived cycle push (PAYROLL_SYNC_API.md).
 * Machine-to-machine only -- no session, authenticated via Authorization: Bearer
 * PAYROLL_SYNC_INGEST_API_KEY (excluded from ensure_login() in helpers.php, scoped to that one
 * route only). Kept thin on purpose: all validation/mapping/upsert logic lives in PayrollSyncModel
 * so it's unit-testable without simulating HTTP, matching this codebase's Controller/Model split
 * everywhere else.
 *
 * pendingList() is the opposite: a normal session-gated read for the logged-in Payroll Process
 * page's "Pending Pull" station, not part of the machine-to-machine contract.
 */
class PayrollSyncController extends Controller {
    public function ingest(): void {
        $header = $this->bearerToken();
        if (PAYROLL_SYNC_INGEST_API_KEY === '' || $header === null || !hash_equals(PAYROLL_SYNC_INGEST_API_KEY, $header)) {
            http_response_code(401);
            $this->json(['status' => 'error', 'message' => 'Invalid or missing bearer token.']);
        }

        $raw = file_get_contents('php://input');
        $payload = json_decode((string)$raw, true);
        if (!is_array($payload)) {
            http_response_code(400);
            $this->json(['status' => 'error', 'message' => 'Invalid JSON body.']);
        }

        $model = new PayrollSyncModel();
        $result = $model->ingest($payload);

        if (!$result['status']) {
            http_response_code(422);
            $this->json(['status' => 'error', 'message' => $result['message']]);
        }

        http_response_code(200);
        $this->json([
            'status' => 'ok',
            'external_ref' => $result['process_row_id'],
            'unmapped_items' => $result['unmapped_items'],
        ]);
    }

    public function pendingList(): void {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
        }
        $model = new PayrollSyncModel();
        $this->json(['status' => true, 'data' => $model->pendingList((int)$compId)]);
    }

    public function pendingGet(): void {
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
        }
        $model = new PayrollSyncModel();
        $detail = $model->getProcessDetail($id, (int)$compId);
        if (!$detail) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
        }
        $this->json(['status' => true, 'data' => $detail]);
    }

    private function bearerToken(): ?string {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? null) : null);
        if ($header === null || stripos($header, 'Bearer ') !== 0) {
            return null;
        }
        return trim(substr($header, 7));
    }
}
