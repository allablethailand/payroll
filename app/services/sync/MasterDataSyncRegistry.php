<?php
declare(strict_types=1);
require_once __DIR__ . '/MasterDataSyncerInterface.php';
require_once __DIR__ . '/DepartmentSyncer.php';
require_once __DIR__ . '/PositionSyncer.php';
require_once __DIR__ . '/ShiftSyncer.php';
require_once __DIR__ . '/BranchSyncer.php';
require_once __DIR__ . '/TeamSyncer.php';
require_once __DIR__ . '/HolidaySyncer.php';
require_once __DIR__ . '/LeaveTypeSyncer.php';
require_once __DIR__ . '/OtRateSyncer.php';
require_once __DIR__ . '/EmployeeSyncer.php';

/**
 * Fixed dependency order per the sync requirement: department -> position -> shift -> branch ->
 * team -> holiday -> leave_type -> ot_rate -> employee (employees resolves department/position/
 * shift refs, so those three must be synced first; branch/team/holiday/leave_type/ot_rate have no
 * cross-dependency on each other or on employees, so their relative order among themselves doesn't
 * matter functionally -- kept alongside the other org-structure types for readability).
 *
 * 2026-09-02: branch/team added (real Origami endpoints confirmed live -- see BranchSyncer/
 * TeamSyncer's own docblocks). Note EmployeeSyncer::sync()'s own $links resolution (the strict,
 * bulk-sync path -- NOT applyOne()'s auto-create path) still does not resolve branch_id/team_id at
 * all -- a PRE-EXISTING gap in that method, unrelated to and not fixed by this addition, which is
 * scoped to populating the branch/team MASTER CATALOG only.
 *
 * Instance-based, not a static singleton like StatutoryExportRegistry/ReportRegistry/
 * NotificationChannelRegistry -- those wrap stateless classes, but every syncer here holds a PDO
 * connection, and tests need to inject their own (transaction-scoped) connection. Cheap enough to
 * rebuild per orchestration run.
 */
class MasterDataSyncRegistry {
    /** @var MasterDataSyncerInterface[] */
    private array $syncers = [];

    public function __construct(?PDO $pdo = null) {
        $this->register(new DepartmentSyncer($pdo));
        $this->register(new PositionSyncer($pdo));
        $this->register(new ShiftSyncer($pdo));
        $this->register(new BranchSyncer($pdo));
        $this->register(new TeamSyncer($pdo));
        $this->register(new HolidaySyncer($pdo));
        $this->register(new LeaveTypeSyncer($pdo));
        $this->register(new OtRateSyncer($pdo));
        $this->register(new EmployeeSyncer($pdo));
    }

    private function register(MasterDataSyncerInterface $syncer): void {
        $this->syncers[$syncer->entityType()] = $syncer;
    }

    /** @return MasterDataSyncerInterface[] in dependency order */
    public function all(): array {
        return array_values($this->syncers);
    }

    public function get(string $entityType): ?MasterDataSyncerInterface {
        return $this->syncers[$entityType] ?? null;
    }
}
