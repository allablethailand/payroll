<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/ImportStagingModel.php';
require_once __DIR__ . '/../models/ImportAuditLogModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';
require_once __DIR__ . '/../models/EmployeeLoginLogModel.php';
require_once __DIR__ . '/../models/ImportTemplateDownloadLogModel.php';
require_once __DIR__ . '/../models/PayrollRunModel.php';
require_once __DIR__ . '/../services/import/ImportService.php';
require_once __DIR__ . '/../services/import/ImportFileParser.php';

/**
 * Import Framework endpoints: upload -> map -> validate (repeatable) -> edit rows -> commit | discard.
 * Rows live in import_staging_*; validation reuses ImportService::preview() (always rolled back) and commit
 * rebuilds its rows from the DB, so nothing the browser sends can reach a live table without passing preview rules.
 * Every action is written to import_audit_logs.
 */
class ImportController extends Controller {
    /** employee_import has its own importer (EmployeeImporter) and extra permission gate. */
    private const STAGING_ENTITY_TYPES = ['attendance', 'leave', 'overtime', 'employee_import', 'ytd_opening', 'adhoc_item'];
    // ytd_opening changes the tax withheld on every later period, so it needs the permission that gates running payroll.
    private const ENTITY_EXTRA_PERMISSION = ['employee_import' => 'employee.edit', 'ytd_opening' => 'payroll_run.process', 'adhoc_item' => 'payroll_run.process'];
    private const MAX_FILE_BYTES = 5 * 1024 * 1024;

    private ImportStagingModel $staging;
    private ImportAuditLogModel $audit;
    private ImportService $importService;
    private ImportFileParser $parser;
    private PermissionModel $permissionModel;

    public function __construct() {
        $this->staging = new ImportStagingModel();
        $this->audit = new ImportAuditLogModel();
        $this->importService = new ImportService();
        $this->parser = new ImportFileParser();
        $this->permissionModel = new PermissionModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function requirePermission(string $permissionKey): bool {
        $isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
        $check = $this->permissionModel->checkPermission($this->userId(), $permissionKey, $isAdmin, (int)getCompId());
        if (!$check['allowed']) {
            $this->json(['status' => false, 'message' => 'You do not have permission to perform this action.']);
            return false;
        }
        return true;
    }

    /** import.run plus whatever the entity itself needs (creating/updating employees also needs employee.edit). */
    private function requireImportAccess(string $entityType): bool {
        if (!$this->requirePermission('import.run')) { return false; }
        $extra = self::ENTITY_EXTRA_PERMISSION[$entityType] ?? null;
        return $extra === null || $this->requirePermission($extra);
    }

    private function fail(string $message): void {
        $this->json(['status' => false, 'message' => $message]);
    }

    private function fingerprint(): array {
        return [(string)($_SERVER['REMOTE_ADDR'] ?? '') ?: null, (string)($_SERVER['HTTP_USER_AGENT'] ?? '') ?: null];
    }

    private function log(int $compId, string $entityType, string $action, array $payload = [], bool $success = true, ?int $batchId = null): void {
        [$ip, $ua] = $this->fingerprint();
        $this->audit->log($compId, $this->userId(), $entityType, $action, $payload, $success, $batchId, strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') ?: null, $ip, $ua);
    }

    private function jsonBody(): array {
        $data = json_decode(file_get_contents('php://input'), true);
        return is_array($data) ? $data : [];
    }

    /** Loads the batch for this company and checks it is in one of $allowedStatuses; answers the request itself when not. */
    private function loadBatch(int $batchId, array $allowedStatuses): ?array {
        $batch = $batchId > 0 ? $this->staging->find((int)getCompId(), $batchId) : null;
        if (!$batch) { $this->fail('Import batch not found.'); return null; }
        if (!in_array($batch['status'], $allowedStatuses, true)) {
            $this->fail('This import is already ' . $batch['status'] . '.');
            return null;
        }
        return $batch;
    }

    private function summary(array $b): array {
        return ['id' => (int)$b['id'], 'entity_type' => $b['entity_type'], 'file_name' => $b['file_name'], 'status' => $b['status'],
            'total' => (int)$b['total_count'], 'valid' => (int)$b['valid_count'], 'errors' => (int)$b['error_count'], 'warnings' => (int)$b['warning_count']];
    }

    public function index() {
        $this->view('import/index', ['entityTypes' => self::STAGING_ENTITY_TYPES]);
    }

    /** GET: import_audit_logs for this company, payload flattened for the Activity Log table. */
    public function activityLog() {
        if (!$this->requirePermission('import.view_log')) return;
        $entries = $this->audit->list((int)getCompId(), [], 500);
        // A batch counts as rolled back once a successful 'rollback' audit row names it.
        $rolledBack = [];
        foreach ($entries as $e) {
            if ($e['action'] === 'rollback' && $e['outcome'] === 'success' && $e['batch_id'] !== null) {
                $rolledBack[(int)$e['batch_id']] = true;
            }
        }
        $rows = array_map(function (array $r) use ($rolledBack): array {
            $payload = $r['payload_json'] ? (array)json_decode($r['payload_json'], true) : [];
            $ua = $r['user_agent'] ? EmployeeLoginLogModel::parseUserAgent($r['user_agent']) : [];
            return [
                'id' => (int)$r['id'], 'performed_at' => $r['performed_at'], 'action' => $r['action'], 'outcome' => $r['outcome'], 'entity_type' => $r['entity_type'],
                'performed_by_name_th' => $r['performed_by_name_th'], 'performed_by_name_en' => $r['performed_by_name_en'],
                'sync_batch_id' => $payload['sync_batch_id'] ?? null, 'rolled_back' => !empty($payload['sync_batch_id']) && isset($rolledBack[(int)$payload['sync_batch_id']]),
                'file_name' => $payload['file_name'] ?? null, 'total' => $payload['total'] ?? null, 'success' => $payload['success'] ?? null, 'failed' => $payload['failed'] ?? null,
                'ip_address' => $r['ip_address'], 'browser' => trim(($ua['browser_name'] ?? '') . ' ' . ($ua['browser_version'] ?? '')) ?: null, 'os' => $ua['os_name'] ?? null,
            ];
        }, $entries);
        $this->json(['status' => true, 'data' => $rows]);
    }

    /** GET: entity_type. Streams the .xlsx template for any staging entity and logs the download. */
    public function template() {
        $compId = (int)getCompId();
        $entityType = (string)($_GET['entity_type'] ?? '');
        if (!$compId || !in_array($entityType, self::STAGING_ENTITY_TYPES, true)) {
            http_response_code(400);
            echo 'Unknown entity type.';
            return;
        }
        if (!$this->requireImportAccess($entityType)) return;
        $file = $this->importService->generateTemplate($entityType);
        [$ip, $ua] = $this->fingerprint();
        (new ImportTemplateDownloadLogModel())->log($compId, $entityType, $file['file_name'], $this->userId(), $ip, $ua, 'import_page');
        $this->log($compId, $entityType, 'download_template', ['file_name' => $file['file_name']]);
        header('Content-Type: ' . $file['mime_type']);
        header('Content-Disposition: attachment; filename="' . $file['file_name'] . '"');
        header('Content-Length: ' . strlen($file['content']));
        echo $file['content'];
        exit;
    }

    /** POST multipart: file + entity_type. Stores the file, stages its rows, and suggests a header mapping. */
    public function upload() {
        $compId = (int)getCompId();
        $entityType = (string)($_POST['entity_type'] ?? '');
        if (!$compId || !in_array($entityType, self::STAGING_ENTITY_TYPES, true)) { $this->fail('Unknown entity type.'); return; }
        if (!$this->requireImportAccess($entityType)) return;
        $file = $_FILES['file'] ?? null;
        $fileName = (string)($file['name'] ?? '');
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) { $this->fail('No file uploaded.'); return; }
        if ($file['size'] > self::MAX_FILE_BYTES) { $this->fail('File too large. Maximum size is 5MB.'); return; }
        try {
            $parsed = $this->parser->parse($file['tmp_name'], $fileName);
            if (!$parsed) { throw new InvalidArgumentException('The file has no data rows.'); }
            $stored = $this->importService->storeOriginal($compId, $file);
            $batchId = $this->staging->create($compId, $entityType, $fileName, $stored ? 'storage/uploads/import_originals/' . $compId . '/' . $stored['token'] : null,
                (int)$file['size'], $parsed, $this->userId());
        } catch (InvalidArgumentException $e) {
            $this->log($compId, $entityType, 'upload', ['file_name' => $fileName, 'error' => $e->getMessage()], false);
            $this->fail($e->getMessage());
            return;
        } catch (Throwable $e) {
            $this->log($compId, $entityType, 'upload', ['file_name' => $fileName, 'error' => $e->getMessage()], false);
            $this->fail('Upload failed. Please try again.');
            return;
        }
        $columns = $this->importService->templateColumns($entityType);
        $headers = $this->staging->fileHeaders($batchId);
        $suggested = [];
        $labelToKey = [];
        foreach ($columns as $key => $label) { $labelToKey[mb_strtolower(trim($label))] = $key; }
        foreach ($headers as $h) {
            if (isset($labelToKey[mb_strtolower($h)])) { $suggested[$h] = $labelToKey[mb_strtolower($h)]; }
        }
        $samples = [];
        foreach ($headers as $h) {
            foreach (array_slice($parsed, 0, 20) as $row) {
                $v = trim((string)($row[$h] ?? ''));
                if ($v !== '') { $samples[$h] = mb_substr($v, 0, 60); break; }
            }
        }
        $this->log($compId, $entityType, 'upload', ['file_name' => $fileName, 'total' => count($parsed)], true, $batchId);
        $this->json(['status' => true, 'batch_id' => $batchId, 'total' => count($parsed), 'file_headers' => $headers, 'samples' => $samples, 'columns' => $columns, 'suggested_mapping' => $suggested]);
    }

    /** POST json: batch_id, mapping {file header: field key}. Re-mapping resets validation. */
    public function map() {
        if (!$this->requirePermission('import.run')) return;
        $body = $this->jsonBody();
        $batch = $this->loadBatch((int)($body['batch_id'] ?? 0), ['uploaded', 'mapped', 'validated']);
        if (!$batch) return;
        $compId = (int)getCompId();
        $columns = $this->importService->templateColumns($batch['entity_type']);
        $mapping = [];
        foreach ((array)($body['mapping'] ?? []) as $header => $key) {
            if ($key === null || $key === '') { continue; }
            if (!is_string($key) || !isset($columns[$key])) { $this->fail('Unknown field: ' . (is_string($key) ? $key : '?')); return; }
            $mapping[(string)$header] = $key;
        }
        if (!$mapping) { $this->fail('Map at least one column.'); return; }
        if (count($mapping) !== count(array_unique($mapping))) { $this->fail('Each field can be mapped from only one column.'); return; }
        $this->staging->applyMapping((int)$batch['id'], $mapping, fn($raw, $m) => $this->importService->mapRows($raw, $columns, $m));
        $this->log($compId, $batch['entity_type'], 'map', ['file_name' => $batch['file_name'], 'mapped_fields' => array_values($mapping)], true, (int)$batch['id']);
        $this->json(['status' => true, 'batch' => $this->summary($this->staging->find($compId, (int)$batch['id']))]);
    }

    /** POST json: batch_id, optional row_nos[] (re-validate only those). Dry run via ImportService::preview(); nothing is persisted outside staging. */
    public function validate() {
        if (!$this->requirePermission('import.run')) return;
        $body = $this->jsonBody();
        $batch = $this->loadBatch((int)($body['batch_id'] ?? 0), ['mapped', 'validated']);
        if (!$batch) return;
        if ($batch['mapping_json'] === null) { $this->fail('Map the columns first.'); return; }
        $compId = (int)getCompId();
        $rowNos = is_array($body['row_nos'] ?? null) ? array_map('intval', $body['row_nos']) : null;
        $rows = $this->staging->mappedRows((int)$batch['id'], $rowNos);
        if (!$rows) { $this->fail('No rows to validate.'); return; }
        [$ip, $ua] = $this->fingerprint();
        $result = $this->importService->preview($compId, $batch['entity_type'], array_column($rows, 'data'), $this->userId(), $ip, $ua);
        if (!$result['status']) {
            $this->log($compId, $batch['entity_type'], 'validate', ['file_name' => $batch['file_name'], 'error' => $result['message'] ?? ''], false, (int)$batch['id']);
            $this->fail($result['message'] ?? 'Validation failed.');
            return;
        }
        $perRow = [];
        foreach ($result['row_results'] as $r) {
            $rowNo = $rows[$r['row'] - 2]['row_no'];
            if ($r['status'] === 'error') {
                $perRow[$rowNo] = ['status' => 'error', 'messages' => [(string)($r['message'] ?? 'Invalid row.')]];
            } elseif (!empty($r['source_conflict'])) {
                $perRow[$rowNo] = ['status' => 'warning', 'messages' => ['Overwrites a record last written by ' . ($r['previous_source'] ?? 'another source') . '.']];
            } else {
                $perRow[$rowNo] = ['status' => 'valid', 'messages' => []];
            }
        }
        $this->staging->saveValidation((int)$batch['id'], $perRow);
        $fresh = $this->staging->find($compId, (int)$batch['id']);
        $this->log($compId, $batch['entity_type'], 'validate', ['file_name' => $batch['file_name'], 'rows_checked' => count($rows),
            'total' => (int)$fresh['total_count'], 'success' => (int)$fresh['valid_count'] + (int)$fresh['warning_count'], 'failed' => (int)$fresh['error_count']], true, (int)$batch['id']);
        $this->json(['status' => true, 'batch' => $this->summary($fresh)]);
    }

    /** POST json: batch_id, edits [{row_no, data{field key: value}}]. Edited rows return to 'pending' until re-validated. */
    public function editRows() {
        if (!$this->requirePermission('import.run')) return;
        $body = $this->jsonBody();
        $batch = $this->loadBatch((int)($body['batch_id'] ?? 0), ['mapped', 'validated']);
        if (!$batch) return;
        $compId = (int)getCompId();
        $edits = is_array($body['edits'] ?? null) ? $body['edits'] : [];
        $keys = array_keys($this->importService->templateColumns($batch['entity_type']));
        $count = $this->staging->editRows((int)$batch['id'], $edits, $keys);
        $this->log($compId, $batch['entity_type'], 'edit_row', ['file_name' => $batch['file_name'], 'rows_edited' => $count], true, (int)$batch['id']);
        $this->json(['status' => true, 'edited' => $count, 'batch' => $this->summary($this->staging->find($compId, (int)$batch['id']))]);
    }

    /** GET: batch_id, optional status (error/warning/valid/pending), offset, limit -- errors first. */
    public function rows() {
        if (!$this->requirePermission('import.run')) return;
        $batch = $this->loadBatch((int)($_GET['batch_id'] ?? 0), ['uploaded', 'mapped', 'validated', 'committing']);
        if (!$batch) return;
        $status = isset($_GET['status']) && $_GET['status'] !== '' ? (string)$_GET['status'] : null;
        $page = $this->staging->rows((int)$batch['id'], $status, (int)($_GET['offset'] ?? 0), (int)($_GET['limit'] ?? 100));
        $this->json(['status' => true, 'batch' => $this->summary($batch), 'columns' => $this->importService->templateColumns($batch['entity_type']),
            'mapping' => $batch['mapping_json'] ? json_decode($batch['mapping_json'], true) : null] + $page);
    }

    /** POST json: batch_id. Only a fully validated batch with zero errors and no un-revalidated edits can commit; rows come from the DB, never the request. */
    public function commit() {
        if (!$this->requirePermission('import.run')) return;
        $body = $this->jsonBody();
        $batch = $this->loadBatch((int)($body['batch_id'] ?? 0), ['validated']);
        if (!$batch || !$this->requireImportAccess($batch['entity_type'])) return;
        $compId = (int)getCompId();
        $id = (int)$batch['id'];
        if (!$this->staging->claimForCommit($id)) {
            $this->fail('Fix every error and re-validate before importing.');
            return;
        }
        $rows = array_column($this->staging->mappedRows($id), 'data');
        [$ip, $ua] = $this->fingerprint();
        $original = $batch['file_path'] ? ['path' => $batch['file_path'], 'name' => $batch['file_name'], 'size' => (int)$batch['file_size']] : null;
        try {
            $result = $this->importService->commit($compId, $batch['entity_type'], $rows, $this->userId(), $ip, $ua, $original);
        } catch (Throwable $e) {
            $this->staging->releaseClaim($id);
            $this->log($compId, $batch['entity_type'], 'commit', ['file_name' => $batch['file_name'], 'error' => $e->getMessage()], false, $id);
            $this->fail('Import failed. Nothing was imported.');
            return;
        }
        if (empty($result['status'])) {
            $this->staging->releaseClaim($id);
            $this->log($compId, $batch['entity_type'], 'commit', ['file_name' => $batch['file_name'], 'error' => $result['message'] ?? ''], false, $id);
            $this->fail($result['message'] ?? 'Import failed. Nothing was imported.');
            return;
        }
        $this->staging->markCommitted($id, isset($result['batch_id']) ? (int)$result['batch_id'] : null);
        $this->log($compId, $batch['entity_type'], 'commit', ['file_name' => $batch['file_name'], 'mapped_fields' => array_values((array)json_decode((string)$batch['mapping_json'], true)),
            'total' => $result['total'], 'success' => $result['success'], 'failed' => $result['error'], 'sync_batch_id' => $result['batch_id'] ?? null], true, $id);
        $this->json(['status' => true, 'total' => $result['total'], 'success' => $result['success'], 'error' => $result['error'], 'sync_batch_id' => $result['batch_id'] ?? null]);
    }

    /** POST json: sync_batch_id. Undoes a committed ad-hoc item import: removes its manual lines from the (still draft) runs and recalculates them. */
    public function rollback() {
        $body = $this->jsonBody();
        $syncBatchId = (int)($body['sync_batch_id'] ?? 0);
        $compId = (int)getCompId();
        if (!$this->requireImportAccess('adhoc_item')) return;
        $stmt = (Database::getInstance()->pdo)->prepare("SELECT id FROM `sync_batches` WHERE id = :id AND comp_id = :c AND source = 'import' AND entity_type = 'adhoc_item'");
        $stmt->execute([':id' => $syncBatchId, ':c' => $compId]);
        if ($syncBatchId <= 0 || !$stmt->fetch()) { $this->fail('Import batch not found.'); return; }
        $isAdmin = ($_SESSION['user']['role'] ?? '') === 'admin';
        $result = (new PayrollRunModel())->rollbackManualLineImportBatch($syncBatchId, $compId, $this->userId(), $isAdmin);
        $this->log($compId, 'adhoc_item', 'rollback', [
            'sync_batch_id' => $syncBatchId, 'lines_removed' => $result['lines_removed'] ?? 0, 'runs_recalculated' => $result['runs_recalculated'] ?? 0, 'error' => $result['message'] ?? null,
        ], !empty($result['status']), $syncBatchId);
        $this->json($result);
    }

    /** POST json: batch_id. Deletes the staged rows (personal data); the batch row stays as history. */
    public function discard() {
        if (!$this->requirePermission('import.run')) return;
        $body = $this->jsonBody();
        $batch = $this->loadBatch((int)($body['batch_id'] ?? 0), ['uploaded', 'mapped', 'validated']);
        if (!$batch) return;
        if (!$this->staging->discard((int)$batch['id'])) { $this->fail('This import can no longer be discarded.'); return; }
        $this->log((int)getCompId(), $batch['entity_type'], 'discard', ['file_name' => $batch['file_name'], 'total' => (int)$batch['total_count']], true, (int)$batch['id']);
        $this->json(['status' => true]);
    }
}
