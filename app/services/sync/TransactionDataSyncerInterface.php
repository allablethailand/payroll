<?php
declare(strict_types=1);
require_once __DIR__ . '/OrigamiSyncClientInterface.php';

/**
 * Contract for syncing one transaction-data entity type (attendance/leave/overtime) from Origami
 * HR for a date range. Same shape as MasterDataSyncerInterface plus the date scope, and the same
 * per-row-error/whole-batch-exception split.
 */
interface TransactionDataSyncerInterface {
    /** Matches sync_batches.entity_type. */
    public function entityType(): string;

    /**
     * @return array{total: int, success: int, error: int, errors: array<array{ref_id: mixed, message: string}>}
     * @throws RuntimeException if the fetch call itself fails.
     */
    public function sync(int $compId, int $origamiCompanyId, OrigamiSyncClientInterface $client, int $batchId, ?int $triggeredBy, string $dateFrom, string $dateTo): array;

    /**
     * Import entry point (Excel/CSV): upserts ONE already-parsed, header-mapped row. Resolves the
     * employee by `employee_no` (not origami_ref_id -- a human filling a spreadsheet knows this
     * system's employee numbers, not Origami's internal ids) and matches the target row itself by
     * origami_ref_id if the row carries one, else by this entity's natural composite key (e.g.
     * employee+work_date for attendance).
     *
     * @return array{action: 'inserted'|'updated'}
     * @throws InvalidArgumentException on a bad/unresolvable row -- caller records a per-row error.
     */
    public function importRow(int $compId, array $item, int $batchId, ?int $triggeredBy): array;

    /** @return array<string,string> internal field key => human-readable column label, for the downloadable import template. */
    public function templateColumns(): array;
}
