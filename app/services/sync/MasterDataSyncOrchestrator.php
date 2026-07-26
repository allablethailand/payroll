<?php
declare(strict_types=1);
require_once __DIR__ . '/MasterDataSyncRegistry.php';
require_once __DIR__ . '/OrigamiSyncClient.php';
require_once __DIR__ . '/../../models/SyncBatchModel.php';

/**
 * Runs one or all master-data syncers, opening/closing a sync_batches row around each entity
 * type. syncAllMasterData() always iterates in MasterDataSyncRegistry's dependency order; a
 * single entity type's total failure (e.g. Origami not configured) does not stop the rest from
 * being attempted -- each gets its own independent batch result.
 *
 * Dependency ordering between master sub-types (department/position/shift before employee) is
 * enforced two ways: (1) syncAllMasterData() always calls them in the right sequence, and (2) if
 * someone manually syncs employees before syncing department/position/shift, EmployeeSyncer's
 * per-row FK resolution fails with a clear "sync it first" message recorded as a per-row error
 * (not a hard block) -- employees with no department/position/shift ref still sync fine.
 */
class MasterDataSyncOrchestrator {
    private PDO $db;
    private OrigamiSyncClientInterface $client;
    private SyncBatchModel $batchModel;

    public function __construct(?PDO $pdo = null, ?OrigamiSyncClientInterface $client = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->client = $client ?? new OrigamiSyncClient();
        $this->batchModel = new SyncBatchModel($this->db);
    }

    private function getOrigamiCompanyRefId(int $compId): ?int {
        $stmt = $this->db->prepare("SELECT ref_id FROM companies WHERE id = :id");
        $stmt->execute([':id' => $compId]);
        $refId = $stmt->fetchColumn();
        return ($refId === false || $refId === null) ? null : (int)$refId;
    }

    public function syncEntity(int $compId, string $entityType, ?int $triggeredBy, string $triggerType = 'manual'): array {
        $origamiCompanyId = $this->getOrigamiCompanyRefId($compId);
        if ($origamiCompanyId === null) {
            return ['status' => false, 'entity_type' => $entityType,
                'message' => 'This company is not linked to an Origami HR company yet. Set the Origami reference ID in Company Profile first.'];
        }
        $syncer = (new MasterDataSyncRegistry($this->db))->get($entityType);
        if (!$syncer) {
            return ['status' => false, 'entity_type' => $entityType, 'message' => "Unknown entity type: {$entityType}"];
        }

        $batchId = $this->batchModel->start($compId, $entityType, 'sync', $triggerType, $triggeredBy);
        try {
            $result = $syncer->sync($compId, $origamiCompanyId, $this->client, $batchId, $triggeredBy);
            $this->batchModel->complete($batchId, $result['total'], $result['success'], $result['error'], $result['errors']);
            return array_merge(['status' => true, 'batch_id' => $batchId, 'entity_type' => $entityType], $result);
        } catch (Throwable $e) {
            $this->batchModel->fail($batchId, $e->getMessage());
            return ['status' => false, 'batch_id' => $batchId, 'entity_type' => $entityType, 'message' => $e->getMessage()];
        }
    }

    /** @return array<int, array> one result per entity type, in dependency order */
    public function syncAllMasterData(int $compId, ?int $triggeredBy, string $triggerType = 'manual'): array {
        $registry = new MasterDataSyncRegistry($this->db);
        $results = [];
        foreach ($registry->all() as $syncer) {
            $results[] = $this->syncEntity($compId, $syncer->entityType(), $triggeredBy, $triggerType);
        }
        return $results;
    }

    /** True once every master-data entity type has at least one completed batch for this company. */
    public function hasCompletedMasterDataSync(int $compId): bool {
        $lastSyncTimes = $this->batchModel->lastSyncTimes($compId);
        $registry = new MasterDataSyncRegistry($this->db);
        foreach ($registry->all() as $syncer) {
            if (empty($lastSyncTimes[$syncer->entityType()])) {
                return false;
            }
        }
        return true;
    }
}
