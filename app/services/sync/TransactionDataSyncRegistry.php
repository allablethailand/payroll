<?php
declare(strict_types=1);
require_once __DIR__ . '/TransactionDataSyncerInterface.php';
require_once __DIR__ . '/AttendanceSyncer.php';
require_once __DIR__ . '/LeaveRequestSyncer.php';
require_once __DIR__ . '/OvertimeRecordSyncer.php';

/**
 * Instance-based, same reasoning as MasterDataSyncRegistry (every syncer holds a PDO connection,
 * tests need to inject their own). No cross-dependency among attendance/leave/overtime
 * themselves -- order kept as attendance -> leave -> overtime to match the requirement's listing.
 */
class TransactionDataSyncRegistry {
    /** @var TransactionDataSyncerInterface[] */
    private array $syncers = [];

    public function __construct(?PDO $pdo = null) {
        $this->register(new AttendanceSyncer($pdo));
        $this->register(new LeaveRequestSyncer($pdo));
        $this->register(new OvertimeRecordSyncer($pdo));
    }

    private function register(TransactionDataSyncerInterface $syncer): void {
        $this->syncers[$syncer->entityType()] = $syncer;
    }

    /** @return TransactionDataSyncerInterface[] */
    public function all(): array {
        return array_values($this->syncers);
    }

    public function get(string $entityType): ?TransactionDataSyncerInterface {
        return $this->syncers[$entityType] ?? null;
    }
}
