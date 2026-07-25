<?php
declare(strict_types=1);
require_once __DIR__ . '/OrigamiSyncClientInterface.php';

/**
 * Contract for syncing one master-data entity type from Origami HR. Mirrors the
 * StatutoryExportInterface/NotificationChannelInterface pattern already used in this codebase --
 * add a new entity type = write a new class implementing this + register it in
 * MasterDataSyncRegistry::init() at the right position in the dependency order.
 */
interface MasterDataSyncerInterface {
    /** Matches sync_batches.entity_type. */
    public function entityType(): string;

    /**
     * Upserts every record the client returns (by origami_ref_id+comp_id), and deactivates any
     * previously-synced record (non-null origami_ref_id) that is no longer present in the fetch
     * response OR whose is_active flag is false. A bad individual row is recorded in `errors` and
     * skipped -- it must never abort the rest of the batch.
     *
     * @param ?int $triggeredBy employees.id of whoever clicked "Sync" (NULL for a future
     *        scheduled/auto run) -- stamped as created_by/updated_by on every upserted row.
     * @return array{total: int, success: int, error: int, errors: array<array{ref_id: mixed, message: string}>}
     * @throws RuntimeException if the fetch call itself fails (e.g. client not configured) --
     *         this is a whole-batch failure, not a per-row one, and propagates to the caller.
     */
    public function sync(int $compId, int $origamiCompanyId, OrigamiSyncClientInterface $client, int $batchId, ?int $triggeredBy): array;

    /**
     * Import entry point (Excel/CSV): upserts ONE already-parsed, header-mapped row. Matches by
     * origami_ref_id if the row carries one (optional for import, per requirement), else by this
     * entity's natural business key (e.g. department_code) so re-uploading the same file updates
     * rather than duplicates.
     *
     * @return array{action: 'inserted'|'updated'}
     * @throws InvalidArgumentException on a bad/unresolvable row -- caller records a per-row error.
     */
    public function importRow(int $compId, array $item, int $batchId, ?int $triggeredBy): array;

    /** @return array<string,string> internal field key => human-readable column label, for the downloadable import template. */
    public function templateColumns(): array;
}
