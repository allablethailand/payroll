<?php
declare(strict_types=1);
require_once __DIR__ . '/AbstractMasterDataSyncer.php';

/**
 * Does NOT re-run the statutory-minimum-quota check that SetupRulesModel::leaveTypeSave() applies
 * to manually-entered leave types -- synced/imported leave type config reflects data that already
 * exists (and is presumably already legally compliant) in the source system. Revisit if that
 * turns out to be a wrong assumption once real Origami data or a real customer import is seen.
 */
class LeaveTypeSyncer extends AbstractMasterDataSyncer {
    public function entityType(): string {
        return 'leave_type';
    }

    protected function tableName(): string {
        return 'leave_types';
    }

    protected function fetch(int $origamiCompanyId, OrigamiSyncClientInterface $client): array {
        return $client->fetchLeaveTypes($origamiCompanyId);
    }

    protected function findByNaturalKey(int $compId, array $item): ?int {
        $code = trim((string)($item['code'] ?? ''));
        if ($code === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM leave_types WHERE code = :code AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':code' => $code, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function templateColumns(): array {
        return [
            'ref_id' => 'Origami Ref ID (optional)', 'category_code' => 'Category Code (sick/personal/annual/maternity/ordination/military/leave_without_pay/family_parental/emergency/other)',
            'code' => 'Leave Type Code', 'name_th' => 'Name (Thai)', 'name_en' => 'Name (English)',
            'quota_type' => 'Quota Type (fixed/prorate)', 'quota_amount' => 'Quota Amount', 'unit_type' => 'Unit (day/hour/half_day)', 'is_paid' => 'Paid (1/0)',
        ];
    }

    private function resolveCategoryId(string $categoryCode): int {
        $stmt = $this->db->prepare("SELECT id FROM master_leave_categories WHERE code = :code AND is_active = 1");
        $stmt->execute([':code' => $categoryCode]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException("Unknown leave category code: {$categoryCode}");
        }
        return (int)$id;
    }

    protected function upsertItem(int $compId, array $item, int $batchId, ?int $triggeredBy, ?int $existingId, string $dataSource): void {
        $code = trim((string)($item['code'] ?? ''));
        $nameTh = trim((string)($item['name_th'] ?? ''));
        $nameEn = trim((string)($item['name_en'] ?? ''));
        $categoryCode = trim((string)($item['category_code'] ?? ''));
        if ($code === '' || $nameTh === '' || $nameEn === '' || $categoryCode === '') {
            throw new InvalidArgumentException('Missing code/name_th/name_en/category_code.');
        }
        $categoryId = $this->resolveCategoryId($categoryCode);
        $quotaType = in_array($item['quota_type'] ?? '', ['fixed', 'prorate'], true) ? $item['quota_type'] : 'fixed';
        $quotaAmount = isset($item['quota_amount']) && is_numeric($item['quota_amount']) ? (float)$item['quota_amount'] : 0.0;
        $unitType = in_array($item['unit_type'] ?? '', ['day', 'hour', 'half_day'], true) ? $item['unit_type'] : 'day';
        $isPaid = array_key_exists('is_paid', $item) ? (!empty($item['is_paid']) ? 1 : 0) : 1;
        $refId = isset($item['ref_id']) && is_numeric($item['ref_id']) ? (int)$item['ref_id'] : null;
        if ($existingId !== null) {
            $stmt = $this->db->prepare("UPDATE leave_types SET
                    category_id = :category_id, code = :code, name_th = :name_th, name_en = :name_en,
                    quota_type = :quota_type, quota_amount = :quota_amount, unit_type = :unit_type, is_paid = :is_paid,
                    status = 'active', sync_batch_id = :batch_id, updated_by = :user, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([
                ':category_id' => $categoryId, ':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn,
                ':quota_type' => $quotaType, ':quota_amount' => $quotaAmount, ':unit_type' => $unitType, ':is_paid' => $isPaid,
                ':batch_id' => $batchId, ':user' => $triggeredBy, ':id' => $existingId,
            ]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO leave_types
                    (comp_id, category_id, code, name_th, name_en, quota_type, quota_amount, unit_type, is_paid, status, origami_ref_id, data_source, sync_batch_id, created_by)
                VALUES (:comp_id, :category_id, :code, :name_th, :name_en, :quota_type, :quota_amount, :unit_type, :is_paid, 'active', :ref_id, :data_source, :batch_id, :user)");
            $stmt->execute([
                ':comp_id' => $compId, ':category_id' => $categoryId, ':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn,
                ':quota_type' => $quotaType, ':quota_amount' => $quotaAmount, ':unit_type' => $unitType, ':is_paid' => $isPaid,
                ':ref_id' => $refId, ':data_source' => $dataSource, ':batch_id' => $batchId, ':user' => $triggeredBy,
            ]);
        }
    }
}
