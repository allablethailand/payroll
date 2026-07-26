<?php
declare(strict_types=1);
require_once __DIR__ . '/TransactionDataSyncRegistry.php';
require_once __DIR__ . '/MasterDataSyncOrchestrator.php';
require_once __DIR__ . '/OrigamiSyncClient.php';
require_once __DIR__ . '/../../models/SyncBatchModel.php';

/**
 * Mirrors MasterDataSyncOrchestrator, plus a date range and a hard gate: transaction data must
 * never be synced before master data has completed at least once (per the requirement) --
 * checked via MasterDataSyncOrchestrator::hasCompletedMasterDataSync() before attempting anything.
 * This is a stricter check than master-data-internal ordering (which only warns per-row) because
 * the requirement explicitly calls out this specific boundary as a hard "ห้าม" (must not).
 */
class TransactionDataSyncOrchestrator {
    private PDO $db;
    private OrigamiSyncClientInterface $client;
    private SyncBatchModel $batchModel;
    private MasterDataSyncOrchestrator $masterOrchestrator;

    public function __construct(?PDO $pdo = null, ?OrigamiSyncClientInterface $client = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->client = $client ?? new OrigamiSyncClient();
        $this->batchModel = new SyncBatchModel($this->db);
        $this->masterOrchestrator = new MasterDataSyncOrchestrator($this->db, $this->client);
    }

    private function getOrigamiCompanyRefId(int $compId): ?int {
        $stmt = $this->db->prepare("SELECT ref_id FROM companies WHERE id = :id");
        $stmt->execute([':id' => $compId]);
        $refId = $stmt->fetchColumn();
        return ($refId === false || $refId === null) ? null : (int)$refId;
    }

    public function syncEntity(int $compId, string $entityType, ?int $triggeredBy, string $dateFrom, string $dateTo, string $triggerType = 'manual'): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) || $dateFrom > $dateTo) {
            return ['status' => false, 'entity_type' => $entityType, 'message' => 'Invalid date range.'];
        }
        $origamiCompanyId = $this->getOrigamiCompanyRefId($compId);
        if ($origamiCompanyId === null) {
            return ['status' => false, 'entity_type' => $entityType,
                'message' => 'This company is not linked to an Origami HR company yet. Set the Origami reference ID in Company Profile first.'];
        }
        if (!$this->masterOrchestrator->hasCompletedMasterDataSync($compId)) {
            return ['status' => false, 'entity_type' => $entityType,
                'message' => 'Master data (department/position/shift/holiday/leave type/OT rate/employee) must be synced at least once before transaction data can be synced.'];
        }
        $syncer = (new TransactionDataSyncRegistry($this->db))->get($entityType);
        if (!$syncer) {
            return ['status' => false, 'entity_type' => $entityType, 'message' => "Unknown entity type: {$entityType}"];
        }

        $batchId = $this->batchModel->start($compId, $entityType, 'sync', $triggerType, $triggeredBy, $dateFrom, $dateTo);
        try {
            $result = $syncer->sync($compId, $origamiCompanyId, $this->client, $batchId, $triggeredBy, $dateFrom, $dateTo);
            $this->batchModel->complete($batchId, $result['total'], $result['success'], $result['error'], $result['errors']);
            return array_merge(['status' => true, 'batch_id' => $batchId, 'entity_type' => $entityType], $result);
        } catch (Throwable $e) {
            $this->batchModel->fail($batchId, $e->getMessage());
            return ['status' => false, 'batch_id' => $batchId, 'entity_type' => $entityType, 'message' => $e->getMessage()];
        }
    }

    /** @return array<int, array> one result per entity type (attendance, leave, overtime) */
    public function syncAllTransactionData(int $compId, ?int $triggeredBy, string $dateFrom, string $dateTo, string $triggerType = 'manual'): array {
        $registry = new TransactionDataSyncRegistry($this->db);
        $results = [];
        foreach ($registry->all() as $syncer) {
            $results[] = $this->syncEntity($compId, $syncer->entityType(), $triggeredBy, $dateFrom, $dateTo, $triggerType);
        }
        return $results;
    }
}
