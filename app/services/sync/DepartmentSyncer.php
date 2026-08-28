<?php
declare(strict_types=1);
require_once __DIR__ . '/AbstractMasterDataSyncer.php';

class DepartmentSyncer extends AbstractMasterDataSyncer {
    public function entityType(): string {
        return 'department';
    }

    protected function tableName(): string {
        return 'structure_departments';
    }

    protected function fetch(int $origamiCompanyId, OrigamiSyncClientInterface $client): array {
        return $client->fetchDepartments($origamiCompanyId);
    }

    /** Natural key: department_code (case-sensitive exact match, same value import/sync both use as `code`). */
    protected function findByNaturalKey(int $compId, array $item): ?int {
        $code = trim((string)($item['code'] ?? ''));
        if ($code === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM structure_departments WHERE department_code = :code AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':code' => $code, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function templateColumns(): array {
        return ['ref_id' => 'Origami Ref ID (optional)', 'code' => 'Department Code', 'name_th' => 'Name (Thai)', 'name_en' => 'Name (English)'];
    }

    /**
     * 2026-08-28, real-data fix mirroring EmployeeSyncer::upsertItem()'s own same-day fix -- checked
     * against Origami's ACTUAL filter-options response (not theoretical): name_th is null for
     * essentially every real department (Origami only ever populates the English name field for
     * this company), so the original all-required validation would reject every real candidate the
     * same way the employee one did. Relaxed the same way: code falls back to a generated
     * `ORG-{ref_id}` (same fallback already established for department auto-creation during
     * employee sync, see EmployeeSyncer::resolveOrCreateDepartment()), and only ONE of name_th/
     * name_en needs to be present -- mirrored into the other, same th||en fallback convention used
     * everywhere else in this app for display.
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
        $nameTh = trim((string)($item['name_th'] ?? ''));
        $nameEn = trim((string)($item['name_en'] ?? ''));
        if ($nameTh === '' && $nameEn === '') {
            throw new InvalidArgumentException('Missing name_th and name_en.');
        }
        if ($nameTh === '') { $nameTh = $nameEn; }
        if ($nameEn === '') { $nameEn = $nameTh; }
        if ($existingId !== null) {
            $stmt = $this->db->prepare("UPDATE structure_departments SET
                    department_code = :code, department_name_th = :name_th, department_name_en = :name_en,
                    status = 'active', sync_batch_id = :batch_id, updated_by = :user, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn, ':batch_id' => $batchId, ':user' => $triggeredBy, ':id' => $existingId]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO structure_departments
                    (comp_id, department_code, department_name_th, department_name_en, status, origami_ref_id, data_source, sync_batch_id, created_by)
                VALUES (:comp_id, :code, :name_th, :name_en, 'active', :ref_id, :data_source, :batch_id, :user)");
            $stmt->execute([':comp_id' => $compId, ':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn, ':ref_id' => $refId, ':data_source' => $dataSource, ':batch_id' => $batchId, ':user' => $triggeredBy]);
        }
    }
}
