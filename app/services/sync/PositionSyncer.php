<?php
declare(strict_types=1);
require_once __DIR__ . '/AbstractMasterDataSyncer.php';

class PositionSyncer extends AbstractMasterDataSyncer {
    public function entityType(): string {
        return 'position';
    }

    protected function tableName(): string {
        return 'structure_positions';
    }

    protected function fetch(int $origamiCompanyId, OrigamiSyncClientInterface $client): array {
        return $client->fetchPositions($origamiCompanyId);
    }

    protected function findByNaturalKey(int $compId, array $item): ?int {
        $code = trim((string)($item['code'] ?? ''));
        if ($code === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM structure_positions WHERE position_code = :code AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':code' => $code, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function templateColumns(): array {
        return ['ref_id' => 'Origami Ref ID (optional)', 'code' => 'Position Code', 'name_th' => 'Name (Thai)', 'name_en' => 'Name (English)'];
    }

    protected function upsertItem(int $compId, array $item, int $batchId, ?int $triggeredBy, ?int $existingId, string $dataSource): void {
        $code = trim((string)($item['code'] ?? ''));
        $nameTh = trim((string)($item['name_th'] ?? ''));
        $nameEn = trim((string)($item['name_en'] ?? ''));
        if ($code === '' || $nameTh === '' || $nameEn === '') {
            throw new InvalidArgumentException('Missing code/name_th/name_en.');
        }
        $refId = isset($item['ref_id']) && is_numeric($item['ref_id']) ? (int)$item['ref_id'] : null;
        if ($existingId !== null) {
            $stmt = $this->db->prepare("UPDATE structure_positions SET
                    position_code = :code, position_name_th = :name_th, position_name_en = :name_en,
                    status = 'active', sync_batch_id = :batch_id, updated_by = :user, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn, ':batch_id' => $batchId, ':user' => $triggeredBy, ':id' => $existingId]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO structure_positions
                    (comp_id, position_code, position_name_th, position_name_en, status, origami_ref_id, data_source, sync_batch_id, created_by)
                VALUES (:comp_id, :code, :name_th, :name_en, 'active', :ref_id, :data_source, :batch_id, :user)");
            $stmt->execute([':comp_id' => $compId, ':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn, ':ref_id' => $refId, ':data_source' => $dataSource, ':batch_id' => $batchId, ':user' => $triggeredBy]);
        }
    }
}
