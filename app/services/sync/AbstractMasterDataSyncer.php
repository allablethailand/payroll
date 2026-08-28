<?php
declare(strict_types=1);
require_once __DIR__ . '/MasterDataSyncerInterface.php';

/**
 * Shared upsert-by-origami_ref_id (sync) / upsert-by-ref_id-or-natural-key (import) +
 * deactivate-on-removal template for the 6 master data tables that share a plain `status`
 * enum('active','inactive','deleted') column. EmployeeSyncer does NOT extend this -- `employees`
 * has no generic `status` column (uses `employee_status` instead) and needs FK resolution to
 * department/position/shift, different enough to not force into this shape.
 *
 * `upsertItem()` takes an already-resolved $existingId and $dataSource so the same method backs
 * both sync() (always resolves by origami_ref_id) and importRow() (resolves by origami_ref_id if
 * given, else by a natural key -- e.g. department_code -- so re-importing the same file updates
 * instead of duplicating). `data_source` is written on INSERT only and never overwritten on
 * UPDATE -- it records where a row originated, not who last touched it; `sync_batch_id` DOES
 * update on every touch (that one tracks "last batch to touch this row" for audit purposes).
 */
abstract class AbstractMasterDataSyncer implements MasterDataSyncerInterface {
    protected PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    abstract protected function tableName(): string;

    /** @return array<array<string,mixed>> */
    abstract protected function fetch(int $origamiCompanyId, OrigamiSyncClientInterface $client): array;

    /** Upsert one active item into an already-resolved row (or a new one if $existingId is null). Throw (InvalidArgumentException for bad data) to record a per-row error. */
    abstract protected function upsertItem(int $compId, array $item, int $batchId, ?int $triggeredBy, ?int $existingId, string $dataSource): void;

    /** Resolves an existing row by this entity's natural business key (e.g. department_code) -- used only when import data carries no origami_ref_id. */
    abstract protected function findByNaturalKey(int $compId, array $item): ?int;

    /** @return array<string,string> internal field key => human-readable column label, for the downloadable import template. */
    abstract public function templateColumns(): array;

    public function sync(int $compId, int $origamiCompanyId, OrigamiSyncClientInterface $client, int $batchId, ?int $triggeredBy): array {
        $items = $this->fetch($origamiCompanyId, $client);
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
                if (array_key_exists('is_active', $item) && !$item['is_active']) {
                    $this->deactivateByRefId($compId, $refIdInt);
                } else {
                    $existingId = $this->findByRefId($compId, $refIdInt);
                    $this->upsertItem($compId, $item, $batchId, $triggeredBy, $existingId, 'sync');
                }
                $success++;
            } catch (Throwable $e) {
                $errors[] = ['ref_id' => $refId, 'message' => $e->getMessage()];
            }
        }
        $this->deactivateMissing($compId, $seenRefIds);
        return ['total' => count($items), 'success' => $success, 'error' => count($errors), 'errors' => $errors];
    }

    /**
     * Import entry point: matches by origami_ref_id if the row carries one (non-empty), else by
     * natural key -- re-importing the same file updates existing rows instead of duplicating them.
     * origami_ref_id is never modified by import once a row exists (only set at insert time).
     */
    public function importRow(int $compId, array $item, int $batchId, ?int $triggeredBy): array {
        $refId = $item['ref_id'] ?? null;
        if ($refId !== null && $refId !== '' && is_numeric($refId)) {
            $existingId = $this->findByRefId($compId, (int)$refId);
        } else {
            $existingId = $this->findByNaturalKey($compId, $item);
        }
        $this->upsertItem($compId, $item, $batchId, $triggeredBy, $existingId, 'import');
        return ['action' => $existingId !== null ? 'updated' : 'inserted'];
    }

    /**
     * 2026-08-28, added for the interactive "Sync from Origami" picker pattern (Employee/Holiday/
     * now Department+Position+Team) -- same shape as HolidaySyncer's own applyResolved(): the
     * picker's own Model does its OWN local-match query (findByRefId() here is protected, not
     * reachable from outside this class hierarchy) and passes the already-resolved $existingId in
     * directly, skipping this class's own findByRefId()/findByNaturalKey() resolution entirely.
     * Lives on the ABSTRACT base (not duplicated into DepartmentSyncer/PositionSyncer individually)
     * since it needs nothing beyond upsertItem(), which every concrete subclass already implements.
     */
    public function applyResolved(int $compId, array $item, int $batchId, ?int $triggeredBy, ?int $existingId): array {
        $this->upsertItem($compId, $item, $batchId, $triggeredBy, $existingId, 'sync');
        return ['action' => $existingId !== null ? 'updated' : 'inserted'];
    }

    protected function findByRefId(int $compId, int $refId): ?int {
        $stmt = $this->db->prepare("SELECT id FROM `{$this->tableName()}` WHERE origami_ref_id = :ref AND comp_id = :comp");
        $stmt->execute([':ref' => $refId, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    protected function deactivateByRefId(int $compId, int $refId): void {
        $this->db->prepare("UPDATE `{$this->tableName()}` SET status = 'inactive' WHERE origami_ref_id = :ref AND comp_id = :comp AND status != 'deleted'")
            ->execute([':ref' => $refId, ':comp' => $compId]);
    }

    /**
     * Deactivates any row that WAS synced (non-null origami_ref_id) but is absent from this
     * fetch -- interpreted as "removed on the Origami side." Deliberately a no-op when the fetch
     * returned zero rows: an empty/flaky API response must never look like "everything was
     * deleted" and wipe out every previously-synced row for this company. Import never calls this
     * -- a one-off file upload has no concept of "everything currently in the file" the way a
     * live API fetch does.
     */
    protected function deactivateMissing(int $compId, array $seenRefIds): void {
        if (empty($seenRefIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($seenRefIds), '?'));
        $sql = "UPDATE `{$this->tableName()}` SET status = 'inactive'
            WHERE comp_id = ? AND origami_ref_id IS NOT NULL AND status != 'deleted' AND origami_ref_id NOT IN ({$placeholders})";
        $this->db->prepare($sql)->execute(array_merge([$compId], $seenRefIds));
    }
}
