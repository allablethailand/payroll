<?php
declare(strict_types=1);
require_once __DIR__ . '/ImportFileParser.php';
require_once __DIR__ . '/EmployeeImporter.php';
require_once __DIR__ . '/YtdImporter.php';
require_once __DIR__ . '/AdHocItemImportEntity.php';
require_once __DIR__ . '/AttendanceSummaryImportEntity.php';
require_once __DIR__ . '/../sync/MasterDataSyncRegistry.php';
require_once __DIR__ . '/../sync/TransactionDataSyncRegistry.php';
require_once __DIR__ . '/../../models/SyncBatchModel.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;

/**
 * Import orchestrator (Excel/CSV) for all 7 entity types -- reuses the SAME
 * MasterDataSyncerInterface/TransactionDataSyncerInterface::importRow() implementations the sync
 * engine uses (see those interfaces' docblocks), so upsert/validation logic is written once and
 * shared between "pushed from Origami" and "uploaded as a file." Only the data SOURCE differs.
 *
 * preview() and commit() run the exact same per-row loop through a real DB transaction (so a real
 * sync_batches row backs every attempt, satisfying the FK on data rows' sync_batch_id) --
 * preview() always rolls back at the end regardless of outcome, commit() commits only if the
 * caller asked to (still rolls back on an unexpected exception).
 */
class ImportService {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** @return MasterDataSyncerInterface|TransactionDataSyncerInterface|null */
    private function getImporter(string $entityType) {
        if ($entityType === 'employee_import') {
            return new EmployeeImporter($this->db);
        }
        if ($entityType === 'ytd_opening') {
            return new YtdImporter($this->db);
        }
        if ($entityType === 'adhoc_item') {
            return new AdHocItemImportEntity($this->db);
        }
        if ($entityType === 'attendance_summary') {
            return new AttendanceSummaryImportEntity($this->db);
        }
        $master = (new MasterDataSyncRegistry($this->db))->get($entityType);
        if ($master) {
            return $master;
        }
        return (new TransactionDataSyncRegistry($this->db))->get($entityType);
    }

    /** @return array<string,string> internal field key => human-readable label */
    public function templateColumns(string $entityType): array {
        $importer = $this->getImporter($entityType);
        if (!$importer) {
            throw new InvalidArgumentException("Unknown entity type: {$entityType}");
        }
        return $importer->templateColumns();
    }

    /** @return array{content: string, mime_type: string, file_name: string} */
    public function generateTemplate(string $entityType): array {
        $columns = $this->templateColumns($entityType);
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $col = 1;
        foreach ($columns as $label) {
            $sheet->setCellValueExplicit([$col, 1], $label, DataType::TYPE_STRING);
            $col++;
        }
        $writer = new XlsxWriter($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = (string)ob_get_clean();
        return [
            'content' => $content,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'file_name' => "import_template_{$entityType}.xlsx",
        ];
    }

    /**
     * Maps parsed file rows (keyed by the file's own header text) to importer-shaped rows (keyed
     * by internal field key). Auto-matches a file header against templateColumns()'s labels
     * (case-insensitive, trimmed); anything left unmatched can be resolved via $explicitMapping
     * (file header => internal field key) -- this is the "field mapping if headers don't match
     * the template" requirement's backend half; a UI would collect $explicitMapping from the user
     * for whatever headers don't auto-match.
     *
     * @return array{rows: array<int, array<string, mixed>>, unmapped_headers: string[]}
     */
    public function mapRows(array $parsedRows, array $templateColumns, array $explicitMapping = []): array {
        $labelToKey = [];
        foreach ($templateColumns as $key => $label) {
            $labelToKey[mb_strtolower(trim($label))] = $key;
        }
        $unmapped = [];
        $mappedRows = [];
        foreach ($parsedRows as $row) {
            $mappedRow = [];
            foreach ($row as $header => $value) {
                $headerTrimmed = trim((string)$header);
                $key = $explicitMapping[$headerTrimmed] ?? ($labelToKey[mb_strtolower($headerTrimmed)] ?? null);
                if ($key !== null) {
                    $mappedRow[$key] = is_string($value) ? trim($value) : $value;
                } elseif (!in_array($headerTrimmed, $unmapped, true)) {
                    $unmapped[] = $headerTrimmed;
                }
            }
            $mappedRows[] = $mappedRow;
        }
        return ['rows' => $mappedRows, 'unmapped_headers' => $unmapped];
    }

    /**
     * @return array{status: bool, batch_id: ?int, total: int, success: int, error: int, conflict: int,
     *         errors: array<array{row:int, message:string}>,
     *         row_results: array<array{row:int, status:string, action?:string, message?:string, source_conflict?:bool, previous_source?:string}>}
     */
    private function runImport(int $compId, string $entityType, array $mappedRows, ?int $triggeredBy, bool $commit, ?string $ipAddress = null, ?string $userAgent = null, ?array $originalFile = null): array {
        $importer = $this->getImporter($entityType);
        if (!$importer) {
            return ['status' => false, 'batch_id' => null, 'total' => 0, 'success' => 0, 'error' => 0, 'conflict' => 0, 'errors' => [], 'row_results' => [],
                'message' => "Unknown entity type: {$entityType}"];
        }
        if (empty($mappedRows)) {
            return ['status' => false, 'batch_id' => null, 'total' => 0, 'success' => 0, 'error' => 0, 'conflict' => 0, 'errors' => [], 'row_results' => [],
                'message' => 'The file has no data rows.'];
        }

        // preview() must ALWAYS discard its writes even if this connection is already inside
        // someone else's transaction (e.g. a test harness) -- a SAVEPOINT guarantees that without
        // touching the ambient transaction, unlike the usual own-transaction pattern elsewhere in
        // this codebase (which is fine for commit(), since that's meant to participate in
        // whatever transaction its caller is already running).
        $nested = $this->db->inTransaction();
        if ($nested) {
            $this->db->exec('SAVEPOINT import_run');
        } else {
            $this->db->beginTransaction();
        }
        try {
            $batchModel = new SyncBatchModel($this->db);
            $batchId = $batchModel->start($compId, $entityType, 'import', 'manual', $triggeredBy, null, null, $ipAddress, $userAgent, $commit ? $originalFile : null);

            $success = 0;
            $errors = [];
            $rowResults = [];
            foreach ($mappedRows as $i => $row) {
                $rowNumber = $i + 2; // file row number: +1 for 0-index, +1 for the consumed header row
                try {
                    $result = $importer->importRow($compId, $row, $batchId, $triggeredBy);
                    $success++;
                    $rowResult = ['row' => $rowNumber, 'status' => 'ok', 'action' => $result['action']];
                    // 2026-08-30, conflict-prevention (explicit decision): surface, don't silently
                    // allow, an import row that overwrites a record another source (sync/manual)
                    // last touched -- see AbstractTransactionDataSyncer::importRow()'s own docblock.
                    if (!empty($result['source_conflict'])) {
                        $rowResult['source_conflict'] = true;
                        $rowResult['previous_source'] = $result['previous_source'];
                    }
                    $rowResults[] = $rowResult;
                } catch (Throwable $e) {
                    $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage()];
                    $rowResults[] = ['row' => $rowNumber, 'status' => 'error', 'message' => $e->getMessage()];
                }
            }

            // Optional hook: an importer that writes without recalculating (adhoc_item) finishes the batch here, inside the same transaction.
            if (method_exists($importer, 'afterBatch')) {
                $importer->afterBatch($compId, $commit, $triggeredBy);
            }

            $batchModel->complete($batchId, count($mappedRows), $success, count($errors), $errors);

            if ($nested) {
                if (!$commit) {
                    $this->db->exec('ROLLBACK TO SAVEPOINT import_run');
                }
                // commit=true while nested: leave it to the outer transaction owner, same as the
                // rest of this codebase's own-transaction convention.
            } elseif ($commit) {
                $this->db->commit();
            } else {
                $this->db->rollBack();
            }
            $conflictCount = count(array_filter($rowResults, fn($r) => !empty($r['source_conflict'])));
            return ['status' => true, 'batch_id' => $commit ? $batchId : null, 'total' => count($mappedRows),
                'success' => $success, 'error' => count($errors), 'conflict' => $conflictCount, 'errors' => $errors, 'row_results' => $rowResults];
        } catch (Throwable $e) {
            if ($nested) {
                $this->db->exec('ROLLBACK TO SAVEPOINT import_run');
            } elseif ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['status' => false, 'batch_id' => null, 'total' => count($mappedRows), 'success' => 0, 'error' => count($mappedRows), 'conflict' => 0,
                'errors' => [], 'row_results' => [], 'message' => 'Import failed: ' . $e->getMessage()];
        }
    }

    /** Dry run -- validates and would-be-upserts every row inside a transaction that ALWAYS rolls back. Nothing is persisted, including the batch row itself. */
    public function preview(int $compId, string $entityType, array $mappedRows, ?int $triggeredBy, ?string $ipAddress = null, ?string $userAgent = null): array {
        return $this->runImport($compId, $entityType, $mappedRows, $triggeredBy, false, $ipAddress, $userAgent);
    }

    /** Same validation/upsert pass as preview(), but commits. $ipAddress/$userAgent are the real request's own -- captured on the persisted sync_batches row for audit (2026-08-30, see SyncBatchModel::start()'s own docblock).
     *  @param ?array{path:string,name:string,size:int} $originalFile Platform Hardening Phase 5C -- the already-stored original upload (see ManualEntryController::importPreview()/importCommit()), threaded through to SyncBatchModel::start(). */
    public function commit(int $compId, string $entityType, array $mappedRows, ?int $triggeredBy, ?string $ipAddress = null, ?string $userAgent = null, ?array $originalFile = null): array {
        return $this->runImport($compId, $entityType, $mappedRows, $triggeredBy, true, $ipAddress, $userAgent, $originalFile);
    }

    /** Copies the just-uploaded import file to a durable location (storage/uploads/import_originals/
     *  {comp_id}/{hex}.{ext}, random-hex-name convention every other upload site in this app already
     *  uses) so it survives past this one request -- PHP's own upload temp file is auto-cleaned the
     *  moment the request ends. Returns null (never fatal) on any filesystem failure -- retaining the
     *  original is a nice-to-have for audit, not a requirement for the import itself to work.
     *  @return ?array{token:string,name:string} */
    public function storeOriginal(int $compId, array $file): ?array {
        $ext = strtolower((string)pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            $ext = 'dat';
        }
        $dir = __DIR__ . '/../../../storage/uploads/import_originals/' . $compId . '/';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            return null;
        }
        $token = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!copy($file['tmp_name'], $dir . $token)) {
            return null;
        }
        return ['token' => $token, 'name' => basename((string)($file['name'] ?? $token))];
    }

    /** Resolves a stored_file_token (from storeOriginal() above) back to an absolute path,
     *  validated via realpath containment against the import_originals root -- never trusts the
     *  token as a literal filesystem path. */
    public function resolveOriginalPath(int $compId, string $token): ?string {
        $root = realpath(__DIR__ . '/../../../storage/uploads/import_originals/' . $compId);
        if ($root === false) {
            return null;
        }
        $candidate = realpath($root . '/' . $token);
        if ($candidate === false || strpos($candidate, $root) !== 0 || !is_file($candidate)) {
            return null;
        }
        return $candidate;
    }
}
