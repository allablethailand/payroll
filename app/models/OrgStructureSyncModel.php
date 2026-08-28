<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/sync/OrigamiEmployeeCandidateClient.php';
require_once __DIR__ . '/../services/sync/DepartmentSyncer.php';
require_once __DIR__ . '/../services/sync/PositionSyncer.php';
require_once __DIR__ . '/SyncBatchModel.php';

/**
 * Interactive "Sync from Origami" picker for Department/Position/Team (Organizational Structure
 * tab, 2026-08-28, explicit follow-up: "ส่วนของ Department หรือข้อมูลที่ดึง Filter ได้ตอนนี้ เพิ่ม
 * ปุ่มให้ Sync ได้ด้วย") -- same review-first architecture as EmployeeSyncModel/HolidaySyncModel
 * (candidates() -> admin ticks -> apply() re-fetches fresh and applies only what's selected ->
 * log() reads sync_batches), parameterized by `$entityType` ('department'/'position'/'team')
 * instead of 3 near-identical classes.
 *
 * **Candidate SOURCE is the SAME `OrigamiEmployeeCandidateClient::fetchFilterOptions()` call the
 * Employee Sync picker's own filter dropdowns already use** -- no new Origami endpoint needed at
 * all. Its `departments`/`positions`/`teams` arrays ARE the master lists for those 3 entities
 * (confirmed against Origami's real response: `{ref_id, name_th, name_en}` for departments/
 * positions, `{ref_id, name}` for teams -- none of the 3 carry a `code` field, so every synced
 * row here falls back to a generated `ORG-{ref_id}` code, same fallback-code convention this
 * project already uses elsewhere for missing codes).
 *
 * **Department/Position reuse `DepartmentSyncer`/`PositionSyncer::applyResolved()`** (added to
 * `AbstractMasterDataSyncer` the same day) -- the exact same upsert logic the OLD bulk-sync engine
 * uses, so there is still only ONE place that knows how to write a department/position row,
 * regardless of entry point. **Team has no existing Syncer class at all** (it was never part of
 * the original 7-entity bulk engine, see `structure_teams`' own schema comment) -- `upsertTeam()`
 * below is a small, standalone equivalent, same lightweight "resolve-or-create" shape
 * `PayrollSyncModel::resolveOrCreateTeamId()` already uses for the SAME table via a different
 * entry point. Deliberately kept OUT of `AbstractMasterDataSyncer`'s inheritance chain -- that
 * class's `fetch()` contract expects `OrigamiSyncClientInterface`, which has no `fetchTeams()`
 * method (and adding one would be pure dead weight, since nothing wires Team into the old bulk
 * `MasterDataSyncOrchestrator` sweep).
 *
 * Real-data validation mirrors EmployeeSyncer/DepartmentSyncer/PositionSyncer's own same-day
 * fixes: only ONE of name_th/name_en needs to be present (mirrored into the other), since
 * Origami's real filter-options data has name_th null for virtually everything.
 */
class OrgStructureSyncModel {
    private PDO $db;

    private const VALID_ENTITY_TYPES = ['department', 'position', 'team'];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    private function origamiCompanyRefId(int $compId): ?int {
        $stmt = $this->db->prepare("SELECT ref_id FROM companies WHERE id = :id");
        $stmt->execute([':id' => $compId]);
        $refId = $stmt->fetchColumn();
        return ($refId === false || $refId === null) ? null : (int)$refId;
    }

    /** Same "if we can't connect, say so, don't show mock/fabricated data" gate every other Sync
     *  picker in this app already uses. */
    private function requireConnected(): ?array {
        if (!OrigamiEmployeeCandidateClient::isConfigured()) {
            return ['status' => false, 'not_connected' => true,
                'message' => 'Not connected to Origami yet. The connection has not been configured -- please contact your system administrator.'];
        }
        return null;
    }

    private function tableFor(string $entityType): string {
        return ['department' => 'structure_departments', 'position' => 'structure_positions', 'team' => 'structure_teams'][$entityType];
    }
    private function codeColumnFor(string $entityType): string {
        return ['department' => 'department_code', 'position' => 'position_code', 'team' => 'team_code'][$entityType];
    }
    private function nameThColumnFor(string $entityType): string {
        return ['department' => 'department_name_th', 'position' => 'position_name_th', 'team' => 'team_name_th'][$entityType];
    }
    private function nameEnColumnFor(string $entityType): string {
        return ['department' => 'department_name_en', 'position' => 'position_name_en', 'team' => 'team_name_en'][$entityType];
    }
    private function filterOptionsKeyFor(string $entityType): string {
        return ['department' => 'departments', 'position' => 'positions', 'team' => 'teams'][$entityType];
    }

    private function findLocalMatch(int $compId, string $entityType, int $refId): ?int {
        $table = $this->tableFor($entityType);
        $stmt = $this->db->prepare("SELECT id FROM `{$table}` WHERE origami_ref_id = :ref AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':ref' => $refId, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private function localRow(int $compId, string $entityType, int $localId): ?array {
        $table = $this->tableFor($entityType);
        $stmt = $this->db->prepare("SELECT * FROM `{$table}` WHERE id = :id AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':id' => $localId, ':comp' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Normalizes one filter-options row (department/position shape has name_th+name_en; team
     *  shape has a single flat `name`, mapped here into name_en so downstream code has one common
     *  shape to work with regardless of entity type). */
    private function normalizeCandidate(string $entityType, array $row): array {
        return [
            'ref_id' => (int)$row['ref_id'],
            'name_th' => $entityType === 'team' ? null : ($row['name_th'] ?? null),
            'name_en' => $entityType === 'team' ? ($row['name'] ?? null) : ($row['name_en'] ?? null),
        ];
    }

    public function candidates(int $compId, string $entityType): array {
        if (!in_array($entityType, self::VALID_ENTITY_TYPES, true)) {
            return ['status' => false, 'message' => 'Invalid entity type.'];
        }
        if ($err = $this->requireConnected()) {
            return $err;
        }
        $origamiCompanyId = $this->origamiCompanyRefId($compId);
        if ($origamiCompanyId === null) {
            return ['status' => false, 'message' => 'This company is not linked to an Origami HR company yet. Set the Origami reference ID in Company Profile first.'];
        }
        try {
            $options = (new OrigamiEmployeeCandidateClient($this->db))->fetchFilterOptions($origamiCompanyId, $compId);
        } catch (Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
        $rows = $options[$this->filterOptionsKeyFor($entityType)] ?? [];

        $new = [];
        $existing = [];
        foreach ($rows as $row) {
            $item = $this->normalizeCandidate($entityType, $row);
            $localId = $this->findLocalMatch($compId, $entityType, $item['ref_id']);
            if ($localId === null) {
                $item['match_status'] = 'new';
                $new[] = $item;
                continue;
            }
            $local = $this->localRow($compId, $entityType, $localId);
            $localNameTh = trim((string)($local[$this->nameThColumnFor($entityType)] ?? ''));
            $localNameEn = trim((string)($local[$this->nameEnColumnFor($entityType)] ?? ''));
            $candidateNameTh = trim((string)($item['name_th'] ?? ''));
            $candidateNameEn = trim((string)($item['name_en'] ?? ''));
            $hasUpdate = ($candidateNameTh !== '' && $candidateNameTh !== $localNameTh)
                || ($candidateNameEn !== '' && $candidateNameEn !== $localNameEn);
            $item['match_status'] = 'existing';
            $item['existing_code'] = $local[$this->codeColumnFor($entityType)] ?? null;
            $item['has_update'] = $hasUpdate;
            $existing[] = $item;
        }

        return ['status' => true, 'new' => $new, 'existing' => $existing];
    }

    /** 2026-08-28, same lightweight "resolve-or-create" shape PayrollSyncModel::resolveOrCreateTeamId()
     *  already uses for this same table via a different entry point -- Team has no existing Syncer
     *  class to reuse (see this class's own docblock for why). Only ONE of name_th/name_en needs
     *  to be present (mirrored into the other) -- Origami's teams list only ever carries a single
     *  flat `name`, normalized into name_en by normalizeCandidate() above. team_code always falls
     *  back to a generated `ORG-{ref_id}` -- Origami's teams list has no code field at all. */
    private function upsertTeam(int $compId, array $item, int $batchId, int $userId, ?int $existingId): void {
        $refId = (int)$item['ref_id'];
        $nameTh = trim((string)($item['name_th'] ?? ''));
        $nameEn = trim((string)($item['name_en'] ?? ''));
        if ($nameTh === '' && $nameEn === '') {
            throw new InvalidArgumentException('Missing team name.');
        }
        if ($nameTh === '') { $nameTh = $nameEn; }
        if ($nameEn === '') { $nameEn = $nameTh; }

        if ($existingId !== null) {
            $stmt = $this->db->prepare("UPDATE structure_teams SET
                    team_name_th = :th, team_name_en = :en, status = 'active',
                    sync_batch_id = :batch, updated_by = :user, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([':th' => $nameTh, ':en' => $nameEn, ':batch' => $batchId, ':user' => $userId, ':id' => $existingId]);
            return;
        }
        $code = 'ORG-' . $refId;
        $stmt = $this->db->prepare("INSERT INTO structure_teams
                (comp_id, team_code, team_name_th, team_name_en, status, origami_ref_id, data_source, sync_batch_id, created_by)
            VALUES (:comp_id, :code, :th, :en, 'active', :ref_id, 'sync', :batch, :user)");
        $stmt->execute([':comp_id' => $compId, ':code' => $code, ':th' => $nameTh, ':en' => $nameEn, ':ref_id' => $refId, ':batch' => $batchId, ':user' => $userId]);
    }

    /** Refetches fresh from Origami using the SAME filter-options call -- never trusts client-echoed
     *  candidate rows for what actually gets written, same convention as every other Sync picker. */
    public function apply(int $compId, string $entityType, array $selectedRefIds, int $userId): array {
        if (!in_array($entityType, self::VALID_ENTITY_TYPES, true)) {
            return ['status' => false, 'message' => 'Invalid entity type.'];
        }
        if ($err = $this->requireConnected()) {
            return $err;
        }
        $origamiCompanyId = $this->origamiCompanyRefId($compId);
        if ($origamiCompanyId === null) {
            return ['status' => false, 'message' => 'This company is not linked to an Origami HR company yet. Set the Origami reference ID in Company Profile first.'];
        }
        $selectedRefIds = array_values(array_unique(array_map('intval', $selectedRefIds)));
        if (empty($selectedRefIds)) {
            return ['status' => false, 'message' => 'No records selected.'];
        }

        try {
            $options = (new OrigamiEmployeeCandidateClient($this->db))->fetchFilterOptions($origamiCompanyId, $compId);
        } catch (Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
        $byRefId = [];
        foreach (($options[$this->filterOptionsKeyFor($entityType)] ?? []) as $row) {
            $item = $this->normalizeCandidate($entityType, $row);
            $byRefId[$item['ref_id']] = $item;
        }

        $batchModel = new SyncBatchModel($this->db);
        $batchId = $batchModel->start($compId, $entityType, 'sync', 'manual', $userId);
        $syncer = $entityType === 'department' ? new DepartmentSyncer($this->db)
            : ($entityType === 'position' ? new PositionSyncer($this->db) : null);

        $success = 0;
        $errors = [];
        foreach ($selectedRefIds as $refId) {
            if (!isset($byRefId[$refId])) {
                $errors[] = ['ref_id' => $refId, 'message' => 'This candidate is no longer available -- please refresh and try again.'];
                continue;
            }
            try {
                $existingId = $this->findLocalMatch($compId, $entityType, $refId);
                if ($syncer !== null) {
                    $syncer->applyResolved($compId, $byRefId[$refId], $batchId, $userId, $existingId);
                } else {
                    $this->upsertTeam($compId, $byRefId[$refId], $batchId, $userId, $existingId);
                }
                $success++;
            } catch (Throwable $e) {
                $errors[] = ['ref_id' => $refId, 'message' => $e->getMessage()];
            }
        }
        $batchModel->complete($batchId, count($selectedRefIds), $success, count($errors), $errors);

        return ['status' => true, 'batch_id' => $batchId, 'total' => count($selectedRefIds), 'success' => $success, 'error' => count($errors), 'errors' => $errors];
    }

    public function log(int $compId, string $entityType, int $limit = 50): array {
        if (!in_array($entityType, self::VALID_ENTITY_TYPES, true)) {
            return [];
        }
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->prepare("SELECT b.*, e.name_th AS triggered_by_name_th, e.surname_th AS triggered_by_surname_th,
                e.name_en AS triggered_by_name_en, e.surname_en AS triggered_by_surname_en
            FROM sync_batches b
            LEFT JOIN employees e ON e.id = b.triggered_by
            WHERE b.comp_id = :comp_id AND b.entity_type = :entity_type AND b.source = 'sync'
            ORDER BY b.started_at DESC LIMIT {$limit}");
        $stmt->execute([':comp_id' => $compId, ':entity_type' => $entityType]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['error_detail'] = $row['error_detail'] ? json_decode((string)$row['error_detail'], true) : [];
        }
        unset($row);
        return $rows;
    }
}
