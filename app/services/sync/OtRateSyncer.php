<?php
declare(strict_types=1);
require_once __DIR__ . '/AbstractMasterDataSyncer.php';

class OtRateSyncer extends AbstractMasterDataSyncer {
    public function entityType(): string {
        return 'ot_rate';
    }

    protected function tableName(): string {
        return 'ot_rates';
    }

    protected function fetch(int $origamiCompanyId, OrigamiSyncClientInterface $client): array {
        return $client->fetchOtRates($origamiCompanyId);
    }

    /** ot_rates has no code column -- ot_name_en (exact match) is used as the natural key for import. */
    protected function findByNaturalKey(int $compId, array $item): ?int {
        $nameEn = trim((string)($item['name_en'] ?? ''));
        if ($nameEn === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM ot_rates WHERE ot_name_en = :name_en AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':name_en' => $nameEn, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function templateColumns(): array {
        return [
            'ref_id' => 'Origami Ref ID (optional)', 'name_th' => 'Name (Thai)', 'name_en' => 'Name (English)',
            'scope_code' => 'Scope Code (weekday/weekend/holiday)', 'multiplier_rate' => 'Multiplier Rate', 'calculation_base' => 'Calculation Base (hourly/daily)',
        ];
    }

    private function resolveScopeId(string $scopeCode): int {
        $stmt = $this->db->prepare("SELECT id FROM master_ot_scope_types WHERE code = :code AND is_active = 1");
        $stmt->execute([':code' => $scopeCode]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException("Unknown OT scope code: {$scopeCode}");
        }
        return (int)$id;
    }

    protected function upsertItem(int $compId, array $item, int $batchId, ?int $triggeredBy, ?int $existingId, string $dataSource): void {
        $nameTh = trim((string)($item['name_th'] ?? ''));
        $nameEn = trim((string)($item['name_en'] ?? ''));
        $scopeCode = trim((string)($item['scope_code'] ?? ''));
        if ($nameTh === '' || $nameEn === '' || $scopeCode === '') {
            throw new InvalidArgumentException('Missing name_th/name_en/scope_code.');
        }
        $scopeId = $this->resolveScopeId($scopeCode);
        $multiplierRate = isset($item['multiplier_rate']) && is_numeric($item['multiplier_rate']) ? (float)$item['multiplier_rate'] : 1.5;
        $calcBase = in_array($item['calculation_base'] ?? '', ['hourly', 'daily'], true) ? $item['calculation_base'] : 'hourly';
        $refId = isset($item['ref_id']) && is_numeric($item['ref_id']) ? (int)$item['ref_id'] : null;
        if ($existingId !== null) {
            $stmt = $this->db->prepare("UPDATE ot_rates SET
                    ot_name_th = :name_th, ot_name_en = :name_en, ot_scope_id = :scope_id, multiplier_rate = :multiplier_rate, calculation_base = :calc_base,
                    status = 'active', sync_batch_id = :batch_id, updated_by = :user, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([
                ':name_th' => $nameTh, ':name_en' => $nameEn, ':scope_id' => $scopeId, ':multiplier_rate' => $multiplierRate,
                ':calc_base' => $calcBase, ':batch_id' => $batchId, ':user' => $triggeredBy, ':id' => $existingId,
            ]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO ot_rates
                    (comp_id, ot_name_th, ot_name_en, ot_scope_id, multiplier_rate, calculation_base, status, origami_ref_id, data_source, sync_batch_id, created_by)
                VALUES (:comp_id, :name_th, :name_en, :scope_id, :multiplier_rate, :calc_base, 'active', :ref_id, :data_source, :batch_id, :user)");
            $stmt->execute([
                ':comp_id' => $compId, ':name_th' => $nameTh, ':name_en' => $nameEn, ':scope_id' => $scopeId, ':multiplier_rate' => $multiplierRate,
                ':calc_base' => $calcBase, ':ref_id' => $refId, ':data_source' => $dataSource, ':batch_id' => $batchId, ':user' => $triggeredBy,
            ]);
        }
    }
}
