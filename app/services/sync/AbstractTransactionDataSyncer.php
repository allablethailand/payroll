<?php
declare(strict_types=1);
require_once __DIR__ . '/TransactionDataSyncerInterface.php';

/**
 * Shared upsert-by-origami_ref_id (sync) / upsert-by-ref_id-or-natural-key (import) + scoped
 * soft-delete template for attendance/leave/overtime. All 3 tables resolve "which employee" via
 * employees.origami_ref_id for sync, or employees.employee_no for import -- never an internal id
 * passed straight through, per the "never link transaction data by internal id directly"
 * requirement either way.
 *
 * 2026-08-30 (Phase 5, conflict-prevention decision -- explicit confirmation: "แก้ทั้ง 3 จุดใน Phase
 * นี้"): data_source now updates on EVERY write, not just INSERT (was previously written once and
 * left stale forever after -- an attendance row Origami synced, then later hand-corrected via
 * Manual Entry, kept showing a "sync" badge even though a human overwrote it last). Concrete
 * columns are set in each subclass's own upsertItem() UPDATE branch (this base class has no SQL of
 * its own to touch); importRow() below additionally reports when an import is about to overwrite a
 * row whose CURRENT data_source differs from 'import' (e.g. a sync-derived row), via
 * `source_conflict`/`previous_source` in its return array -- surfaced as a non-blocking warning by
 * ImportService's row_results (see that class's own docblock), not a hard rejection: a legitimate
 * correction from a different source is still allowed through, just made visible instead of silent.
 */
abstract class AbstractTransactionDataSyncer implements TransactionDataSyncerInterface {
    protected PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    abstract protected function tableName(): string;

    /** Column used to scope the "missing from this fetch" soft-delete diff to the requested date range (sync only). */
    abstract protected function dateColumn(): string;

    /** @return array<array<string,mixed>> */
    abstract protected function fetch(int $origamiCompanyId, OrigamiSyncClientInterface $client, string $dateFrom, string $dateTo): array;

    /** Resolves an existing row by this entity's natural composite key (e.g. employee_id+work_date) -- used only when import data carries no origami_ref_id. */
    abstract protected function findByNaturalKey(int $compId, int $employeeId, array $item): ?int;

    /** @return array<string,string> internal field key => human-readable column label, for the downloadable import template. */
    abstract public function templateColumns(): array;

    /** Upsert one item for an already-resolved employee into an already-resolved row (or a new one if $existingId is null). Throw (InvalidArgumentException for bad data) to record a per-row error. */
    abstract protected function upsertItem(int $compId, array $item, int $employeeId, int $batchId, ?int $triggeredBy, ?int $existingId, string $dataSource): void;

    protected function resolveEmployeeId(int $compId, $employeeRefId): int {
        if ($employeeRefId === null || $employeeRefId === '' || !is_numeric($employeeRefId)) {
            throw new InvalidArgumentException('Missing or invalid employee_ref_id.');
        }
        $stmt = $this->db->prepare("SELECT id FROM employees WHERE origami_ref_id = :ref AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':ref' => (int)$employeeRefId, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException("Employee (ref_id={$employeeRefId}) has not been synced yet -- sync employees first.");
        }
        return (int)$id;
    }

    protected function resolveEmployeeIdByNo(int $compId, $employeeNo): int {
        $employeeNo = trim((string)$employeeNo);
        if ($employeeNo === '') {
            throw new InvalidArgumentException('Missing employee_no.');
        }
        $stmt = $this->db->prepare("SELECT id FROM employees WHERE employee_no = :no AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':no' => $employeeNo, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException("Employee (employee_no={$employeeNo}) was not found -- create or import it first.");
        }
        return (int)$id;
    }

    protected function resolveRequiredRef(string $table, int $compId, $refId): int {
        $id = $this->resolveOptionalRef($table, $compId, $refId);
        if ($id === null) {
            throw new InvalidArgumentException("Missing ref for {$table}.");
        }
        return $id;
    }

    protected function resolveOptionalRef(string $table, int $compId, $refId): ?int {
        if ($refId === null || $refId === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM `{$table}` WHERE origami_ref_id = :ref AND comp_id = :comp");
        $stmt->execute([':ref' => (int)$refId, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException("Referenced {$table} (ref_id={$refId}) has not been synced yet -- sync it first.");
        }
        return (int)$id;
    }

    protected function resolveRequiredRefByCode(string $table, string $codeColumn, int $compId, $code): int {
        $id = $this->resolveOptionalRefByCode($table, $codeColumn, $compId, $code);
        if ($id === null) {
            throw new InvalidArgumentException("Missing code for {$table}.");
        }
        return $id;
    }

    protected function resolveOptionalRefByCode(string $table, string $codeColumn, int $compId, $code): ?int {
        if ($code === null || trim((string)$code) === '') {
            return null;
        }
        $code = trim((string)$code);
        $stmt = $this->db->prepare("SELECT id FROM `{$table}` WHERE `{$codeColumn}` = :code AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':code' => $code, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException("Referenced {$table} (code={$code}) was not found -- create or import it first.");
        }
        return (int)$id;
    }

    protected function findByRefId(int $compId, int $refId): ?int {
        $stmt = $this->db->prepare("SELECT id FROM `{$this->tableName()}` WHERE origami_ref_id = :ref AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':ref' => $refId, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** Current data_source of an existing row, or null if it no longer exists -- used by importRow() to detect a cross-source overwrite before it happens. */
    private function currentDataSource(int $id): ?string {
        $stmt = $this->db->prepare("SELECT data_source FROM `{$this->tableName()}` WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    }

    /**
     * Soft-deletes previously-synced rows (non-null origami_ref_id) whose date column falls
     * WITHIN [dateFrom, dateTo] and are absent from this fetch -- scoped to the requested window,
     * not all-time, so re-syncing January never touches February's data. No-op on an empty fetch
     * (same safety net as the master-data syncers). Import never calls this -- a one-off file
     * upload has no concept of "everything currently in the file" the way a live API fetch does.
     */
    protected function softDeleteMissing(int $compId, string $dateFrom, string $dateTo, array $seenRefIds): void {
        if (empty($seenRefIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($seenRefIds), '?'));
        $sql = "UPDATE `{$this->tableName()}` SET deleted_at = CURRENT_TIMESTAMP
            WHERE comp_id = ? AND origami_ref_id IS NOT NULL AND deleted_at IS NULL
              AND `{$this->dateColumn()}` BETWEEN ? AND ?
              AND origami_ref_id NOT IN ({$placeholders})";
        $this->db->prepare($sql)->execute(array_merge([$compId, $dateFrom, $dateTo], $seenRefIds));
    }

    public function sync(int $compId, int $origamiCompanyId, OrigamiSyncClientInterface $client, int $batchId, ?int $triggeredBy, string $dateFrom, string $dateTo): array {
        $items = $this->fetch($origamiCompanyId, $client, $dateFrom, $dateTo);
        $success = 0;
        $errors = [];
        $seenRefIds = [];
        foreach ($items as $item) {
            $refId = $item['ref_id'] ?? null;
            try {
                if ($refId === null || !is_numeric($refId)) {
                    throw new InvalidArgumentException('Missing or invalid ref_id.');
                }
                $refIdInt = (int)$refId;
                $seenRefIds[] = $refIdInt;
                $employeeId = $this->resolveEmployeeId($compId, $item['employee_ref_id'] ?? null);
                $existingId = $this->findByRefId($compId, $refIdInt);
                $this->upsertItem($compId, $item, $employeeId, $batchId, $triggeredBy, $existingId, 'sync');
                $success++;
            } catch (Throwable $e) {
                $errors[] = ['ref_id' => $refId, 'message' => $e->getMessage()];
            }
        }
        $this->softDeleteMissing($compId, $dateFrom, $dateTo, $seenRefIds);
        return ['total' => count($items), 'success' => $success, 'error' => count($errors), 'errors' => $errors];
    }

    public function importRow(int $compId, array $item, int $batchId, ?int $triggeredBy): array {
        $employeeId = $this->resolveEmployeeIdByNo($compId, $item['employee_no'] ?? null);
        $refId = $item['origami_ref_id'] ?? ($item['ref_id'] ?? null);
        if ($refId !== null && $refId !== '' && is_numeric($refId)) {
            $existingId = $this->findByRefId($compId, (int)$refId);
        } else {
            $existingId = $this->findByNaturalKey($compId, $employeeId, $item);
        }
        // 2026-08-30, conflict-prevention: capture the row's CURRENT source before upsertItem()
        // overwrites it -- if it was 'sync' or 'manual', this import is silently taking over a
        // record that came from somewhere else. Reported, never blocked (see class docblock).
        $previousSource = $existingId !== null ? $this->currentDataSource($existingId) : null;
        $itemWithRef = $item;
        $itemWithRef['ref_id'] = $refId;
        $this->upsertItem($compId, $itemWithRef, $employeeId, $batchId, $triggeredBy, $existingId, 'import');
        $result = ['action' => $existingId !== null ? 'updated' : 'inserted'];
        if ($previousSource !== null && $previousSource !== 'import') {
            $result['source_conflict'] = true;
            $result['previous_source'] = $previousSource;
        }
        return $result;
    }
}
