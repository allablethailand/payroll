<?php
declare(strict_types=1);
require_once __DIR__ . '/AbstractMasterDataSyncer.php';

/**
 * 2026-09-02, real Origami endpoint (`GET /api/hr/master/branches`) confirmed live -- structure_branches
 * already had origami_ref_id/data_source/sync_batch_id since 2026-08-30 (branch auto-create during
 * employee sync, see EmployeeSyncer::resolveOrCreateBranch()'s own docblock), this is the FIRST bulk
 * master-data syncer for branches specifically. Mirrors DepartmentSyncer/PositionSyncer exactly.
 */
class BranchSyncer extends AbstractMasterDataSyncer {
    public function entityType(): string {
        return 'branch';
    }

    protected function tableName(): string {
        return 'structure_branches';
    }

    protected function fetch(int $origamiCompanyId, OrigamiSyncClientInterface $client): array {
        return $client->fetchBranches($origamiCompanyId);
    }

    /** Natural key: branch_code (case-sensitive exact match, same `code` value import/sync both use). */
    protected function findByNaturalKey(int $compId, array $item): ?int {
        $code = trim((string)($item['code'] ?? ''));
        if ($code === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM structure_branches WHERE branch_code = :code AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':code' => $code, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function templateColumns(): array {
        return ['ref_id' => 'Origami Ref ID (optional)', 'code' => 'Branch Code', 'name_th' => 'Name (Thai)', 'name_en' => 'Name (English)'];
    }

    /**
     * Origami's own `master/branches.php` sends only ONE `name` (no separate th/en pair, same "no
     * bilingual source" situation EmployeeSyncer::resolveOrCreateBranch() already documents for the
     * per-employee auto-create path) -- mirrored into both branch_name_th/branch_name_en, same
     * convention. `is_default` is a real field Origami sends (which branch is the company's primary
     * one) -- carried straight through.
     */
    protected function upsertItem(int $compId, array $item, int $batchId, ?int $triggeredBy, ?int $existingId, string $dataSource): void {
        $refId = isset($item['ref_id']) && is_numeric($item['ref_id']) ? (int)$item['ref_id'] : null;
        $code = trim((string)($item['code'] ?? ''));
        if ($code === '') {
            if ($refId === null) {
                throw new InvalidArgumentException('Missing code and ref_id -- cannot identify this candidate at all.');
            }
            $code = 'ORG-' . $refId;
        }
        $name = trim((string)($item['name_th'] ?? '')) ?: trim((string)($item['name'] ?? '')) ?: trim((string)($item['name_en'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Missing name.');
        }
        $isDefault = !empty($item['is_default']) ? 1 : 0;
        if ($existingId !== null) {
            $stmt = $this->db->prepare("UPDATE structure_branches SET
                    branch_code = :code, branch_name_th = :name, branch_name_en = :name, is_default = :is_default,
                    status = 'active', sync_batch_id = :batch_id, updated_by = :user, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([':code' => $code, ':name' => $name, ':is_default' => $isDefault, ':batch_id' => $batchId, ':user' => $triggeredBy, ':id' => $existingId]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO structure_branches
                    (comp_id, branch_code, branch_name_th, branch_name_en, is_default, status, origami_ref_id, data_source, sync_batch_id, created_by)
                VALUES (:comp_id, :code, :name, :name, :is_default, 'active', :ref_id, :data_source, :batch_id, :user)");
            $stmt->execute([':comp_id' => $compId, ':code' => $code, ':name' => $name, ':is_default' => $isDefault, ':ref_id' => $refId, ':data_source' => $dataSource, ':batch_id' => $batchId, ':user' => $triggeredBy]);
        }
    }
}
