<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/AttendanceRecordModel.php';
require_once __DIR__ . '/../models/LeaveRequestModel.php';
require_once __DIR__ . '/../models/OvertimeRecordModel.php';
require_once __DIR__ . '/../models/SyncBatchModel.php';
require_once __DIR__ . '/../models/ImportTemplateDownloadLogModel.php';
require_once __DIR__ . '/../models/ImportActivityLogModel.php';
require_once __DIR__ . '/../services/import/ImportFileParser.php';
require_once __DIR__ . '/../services/import/ImportService.php';

/**
 * Manual entry (Step 6) for attendance/leave/overtime -- the "No HR" user type keying data in
 * directly. department/position/shift/holiday/leave_type/ot_rate/employees already have their
 * own controllers elsewhere (Company Setup / Setup & Rules / Employee), unaffected by this file.
 * No permission gate here, matching the same precedent as PayslipTemplateController/
 * PayslipRequestController (RBAC in this project is scoped to Holiday/Leave Type/Approval
 * Workflow only, per CLAUDE.md).
 *
 * 2026-08-30 (Phase 5, T030-T035, explicit request: "ออกแบบ Import Template...Download Template
 * function...Import + validation + match เข้ารอบคำนวณเงินเดือน...หน้ารายการ: แสดงผลเป็นรอบของการ
 * Import...กดดูรายการข้างในแต่ละรอบ import...แก้ไขข้อมูลรายการที่ import เข้ามาได้") -- the Import UI is
 * added as a 4th tab on this SAME "Manual Time Entry" page rather than a separate page, since it
 * writes into the exact same 3 tables (attendance_records/leave_requests/overtime_records) this
 * controller already manages, and T035 ("แก้ไขข้อมูลรายการที่ import เข้ามาได้") is satisfied by
 * REUSING the edit modals/endpoints already above (attendanceSave()/leaveSave()/overtimeSave()
 * neither know nor care whether the row being edited was created by manual entry, sync, or
 * import) rather than building a second, parallel edit surface. The import ENGINE itself
 * (ImportFileParser/ImportService, plus the conflict-prevention fixes on the Syncer/Model classes
 * they call through) was already built/fixed earlier this same session -- this controller is
 * purely a thin wrapper exposing it: templateDownload() -> ImportService::generateTemplate(),
 * preview()/commit() -> ImportService::preview()/commit() (mapRows() runs first so the client
 * never has to know internal field keys, only the file's own header text), batchList()/
 * batchDetail() -> SyncBatchModel::list()/the 3 models' own list(batch_id filter).
 */
class ManualEntryController extends Controller {
    private AttendanceRecordModel $attendanceModel;
    private LeaveRequestModel $leaveModel;
    private OvertimeRecordModel $overtimeModel;
    private SyncBatchModel $batchModel;
    private ImportFileParser $importParser;
    private ImportService $importService;
    private ImportTemplateDownloadLogModel $downloadLogModel;
    private ImportActivityLogModel $activityLogModel;

    private const IMPORTABLE_TRANSACTION_TYPES = ['attendance', 'leave', 'overtime'];

    public function __construct() {
        $this->attendanceModel = new AttendanceRecordModel();
        $this->leaveModel = new LeaveRequestModel();
        $this->overtimeModel = new OvertimeRecordModel();
        $this->batchModel = new SyncBatchModel();
        $this->importParser = new ImportFileParser();
        $this->importService = new ImportService();
        $this->downloadLogModel = new ImportTemplateDownloadLogModel();
        $this->activityLogModel = new ImportActivityLogModel();
    }

    /** @return array{0:?string,1:?string} [ip_address, user_agent] -- the real request's own, never user-suppliable free text. Same capture pattern ReportsController already uses for report_export_logs. */
    private function requestFingerprint(): array {
        return [
            (string)($_SERVER['REMOTE_ADDR'] ?? '') ?: null,
            (string)($_SERVER['HTTP_USER_AGENT'] ?? '') ?: null,
        ];
    }

    private function modelForEntityType(string $entityType) {
        switch ($entityType) {
            case 'attendance': return $this->attendanceModel;
            case 'leave': return $this->leaveModel;
            case 'overtime': return $this->overtimeModel;
            default: return null;
        }
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function filtersFromQuery(): array {
        return [
            'employee_id' => $_GET['employee_id'] ?? null,
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
        ];
    }

    private function jsonBody(): ?array {
        $data = json_decode(file_get_contents('php://input'), true);
        return is_array($data) ? $data : null;
    }

    public function index() {
        $this->view('manual-entry/index');
    }

    /* ---------- Attendance ---------- */

    public function attendanceList() {
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->attendanceModel->list((int)$compId, $this->filtersFromQuery())]);
    }

    public function attendanceGet() {
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $row = $this->attendanceModel->get($id, (int)$compId);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $row]);
    }

    public function attendanceSave() {
        $compId = getCompId();
        $data = $this->jsonBody();
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->attendanceModel->save($data, (int)$compId, $this->userId()));
    }

    public function attendanceDelete() {
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->attendanceModel->delete($id, (int)$compId, $this->userId()));
    }

    /* ---------- Leave ---------- */

    public function leaveList() {
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->leaveModel->list((int)$compId, $this->filtersFromQuery())]);
    }

    public function leaveGet() {
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $row = $this->leaveModel->get($id, (int)$compId);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $row]);
    }

    public function leaveSave() {
        $compId = getCompId();
        $data = $this->jsonBody();
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->leaveModel->save($data, (int)$compId, $this->userId()));
    }

    public function leaveDelete() {
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->leaveModel->delete($id, (int)$compId, $this->userId()));
    }

    /* ---------- Overtime ---------- */

    public function overtimeList() {
        $compId = getCompId();
        $this->json(['status' => true, 'data' => $this->overtimeModel->list((int)$compId, $this->filtersFromQuery())]);
    }

    public function overtimeGet() {
        $compId = getCompId();
        $id = (int)($_GET['id'] ?? 0);
        $row = $this->overtimeModel->get($id, (int)$compId);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        $this->json(['status' => true, 'data' => $row]);
    }

    public function overtimeSave() {
        $compId = getCompId();
        $data = $this->jsonBody();
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $this->json($this->overtimeModel->save($data, (int)$compId, $this->userId()));
    }

    public function overtimeDelete() {
        $compId = getCompId();
        $id = (int)($_POST['id'] ?? 0);
        $this->json($this->overtimeModel->delete($id, (int)$compId, $this->userId()));
    }

    /* ---------- Import (T030/T031/T032/T033/T034) ---------- */

    /** T031: GET, streams a downloadable .xlsx template for the requested entity type's columns (T030 -- one template per entity type, not a combined multi-sheet file, since attendance/leave/overtime have genuinely unrelated column sets and a company importing one rarely needs the others at the same time). 2026-08-30: logs every real download (who/when/device/IP/browser -- see ImportTemplateDownloadLogModel's own docblock) for audit, same as report_export_logs already does for report downloads -- logged only for a genuinely successful stream (i.e. after the entity-type check passes), never for a rejected request. */
    public function importTemplate() {
        $compId = (int)getCompId();
        $entityType = (string)($_GET['entity_type'] ?? '');
        if (!in_array($entityType, self::IMPORTABLE_TRANSACTION_TYPES, true)) {
            http_response_code(400);
            echo 'Unknown entity type.';
            return;
        }
        $file = $this->importService->generateTemplate($entityType);
        if ($compId) {
            [$ip, $ua] = $this->requestFingerprint();
            $this->downloadLogModel->log($compId, $entityType, $file['file_name'], $this->userId(), $ip, $ua, 'manual_entry_import_tab');
        }
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: attachment; filename="' . $file['file_name'] . '"');
        header('Content-Length: ' . strlen($file['content']));
        echo $file['content'];
        exit;
    }

    /**
     * T032 (validation half): parses the uploaded file, auto-maps its headers against
     * templateColumns(), and runs a DRY-RUN (ImportService::preview() -- always rolls back, nothing
     * is persisted) so the admin sees exactly what would happen -- including which rows would
     * insert vs. update, any cross-source conflict warnings (see AbstractTransactionDataSyncer::
     * importRow()'s own docblock), and any per-row validation errors (e.g. an overlapping leave
     * date range, an unresolvable employee_no) -- BEFORE anything is committed. Returns
     * `mapped_rows` so the client can echo them straight back to commit() without re-uploading or
     * re-parsing the file a second time.
     */
    public function importPreview() {
        $compId = (int)getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $entityType = (string)($_POST['entity_type'] ?? '');
        if (!in_array($entityType, self::IMPORTABLE_TRANSACTION_TYPES, true)) {
            $this->json(['status' => false, 'message' => 'Unknown entity type.']);
            return;
        }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['status' => false, 'message' => 'No file uploaded.']);
            return;
        }
        $maxSize = 5 * 1024 * 1024;
        if ($_FILES['file']['size'] > $maxSize) {
            $this->json(['status' => false, 'message' => 'File too large. Maximum size is 5MB.']);
            return;
        }
        $explicitMapping = [];
        if (!empty($_POST['mapping'])) {
            $decoded = json_decode((string)$_POST['mapping'], true);
            if (is_array($decoded)) {
                $explicitMapping = $decoded;
            }
        }
        try {
            $columns = $this->importService->templateColumns($entityType);
            $parsedRows = $this->importParser->parse($_FILES['file']['tmp_name'], (string)$_FILES['file']['name']);
            $mapResult = $this->importService->mapRows($parsedRows, $columns, $explicitMapping);
            [$ip, $ua] = $this->requestFingerprint();
            $previewResult = $this->importService->preview($compId, $entityType, $mapResult['rows'], $this->userId(), $ip, $ua);
            $this->json([
                'status' => true,
                'columns' => $columns,
                'unmapped_headers' => $mapResult['unmapped_headers'],
                'mapped_rows' => $mapResult['rows'],
                'preview' => $previewResult,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->json(['status' => false, 'message' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->json(['status' => false, 'message' => 'Import preview failed: ' . $e->getMessage()]);
        }
    }

    /** T032 (commit half): re-runs the SAME mapped-rows array the preview step already validated -- through the real, persisting ImportService::commit(), creating a real sync_batches row (source='import') that T033/T034 list below. */
    public function importCommit() {
        $compId = (int)getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $entityType = (string)($_POST['entity_type'] ?? '');
        if (!in_array($entityType, self::IMPORTABLE_TRANSACTION_TYPES, true)) {
            $this->json(['status' => false, 'message' => 'Unknown entity type.']);
            return;
        }
        $mappedRows = json_decode((string)($_POST['mapped_rows'] ?? '[]'), true);
        if (!is_array($mappedRows) || empty($mappedRows)) {
            $this->json(['status' => false, 'message' => 'No rows to import.']);
            return;
        }
        [$ip, $ua] = $this->requestFingerprint();
        $result = $this->importService->commit($compId, $entityType, $mappedRows, $this->userId(), $ip, $ua);
        $this->json($result);
    }

    /** 2026-08-30: unified Download Template + Import history (ImportActivityLogModel's own UNION ALL) -- backs the new "History" tab. */
    public function importActivityLog() {
        $compId = (int)getCompId();
        $filters = [];
        if (!empty($_GET['event_type']) && in_array($_GET['event_type'], ['download', 'import'], true)) { $filters['event_type'] = $_GET['event_type']; }
        if (!empty($_GET['entity_type']) && in_array($_GET['entity_type'], self::IMPORTABLE_TRANSACTION_TYPES, true)) { $filters['entity_type'] = $_GET['entity_type']; }
        if (!empty($_GET['date_from'])) { $filters['date_from'] = $_GET['date_from']; }
        if (!empty($_GET['date_to'])) { $filters['date_to'] = $_GET['date_to']; }
        $this->json(['status' => true, 'data' => $this->activityLogModel->list($compId, $filters)]);
    }

    /** T033: lists import ROUNDS (sync_batches rows, source='import') only, no Download events -- the narrower building block importActivityLog() above is built from. No longer called by this page's own UI since the 2026-08-30 unified "History" tab superseded it (same precedent as ApprovalWorkflowModel's own old generic endpoints -- kept live, not deleted, for any narrower future caller that only wants import rounds). */
    public function importBatchList() {
        $compId = (int)getCompId();
        $filters = ['source' => 'import'];
        if (!empty($_GET['entity_type']) && in_array($_GET['entity_type'], self::IMPORTABLE_TRANSACTION_TYPES, true)) {
            $filters['entity_type'] = $_GET['entity_type'];
        }
        $this->json(['status' => true, 'data' => $this->batchModel->list($compId, $filters)]);
    }

    /** T034: drills into ONE import batch's actual records, via the same 3 models' own list(), now filterable by batch_id. */
    public function importBatchDetail() {
        $compId = (int)getCompId();
        $entityType = (string)($_GET['entity_type'] ?? '');
        $batchId = (int)($_GET['batch_id'] ?? 0);
        $model = $this->modelForEntityType($entityType);
        if ($model === null || $batchId <= 0) {
            $this->json(['status' => false, 'message' => 'Unknown entity type or batch.']);
            return;
        }
        $this->json(['status' => true, 'data' => $model->list($compId, ['batch_id' => $batchId])]);
    }
}
