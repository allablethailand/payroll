<?php
declare(strict_types=1);
require_once __DIR__ . '/AbstractMasterDataSyncer.php';

/**
 * 2026-09-02, real Origami endpoint (`GET /api/hr/master/teams`) confirmed live. Team was
 * DELIBERATELY excluded from every earlier round of this sync engine (see
 * OrigamiEmployeeCandidateClient's own docblock: "Team is a purely local ... concept with no
 * Origami-side equivalent") -- that was accurate at the time (no endpoint existed), not anymore.
 * structure_teams already had origami_ref_id/data_source/sync_batch_id since 2026-08-24 (the Team
 * feature's own initial build), so this is purely additive -- no schema change needed.
 *
 * SCOPE NOTE: this syncer populates the structure_teams MASTER CATALOG only (so a team exists to
 * pick from). It does NOT touch `employees.team_id` -- EmployeeSyncer/OrigamiEmployeeCandidateClient
 * still treat Team assignment as a manual, per-employee decision via Employee Detail, unchanged.
 * Origami's own candidates.php DOES send team_ref_id/team_name per employee, so wiring an automatic
 * employees.team_id resolution is technically possible now that a real team master exists -- but
 * that's a separate scope decision (whether Team assignment should ever be automatic) not asked for
 * in this round, so it's deliberately left alone.
 */
class TeamSyncer extends AbstractMasterDataSyncer {
    public function entityType(): string {
        return 'team';
    }

    protected function tableName(): string {
        return 'structure_teams';
    }

    protected function fetch(int $origamiCompanyId, OrigamiSyncClientInterface $client): array {
        return $client->fetchTeams($origamiCompanyId);
    }

    /**
     * No natural code column exists on Origami's own m_support_team (confirmed against its real
     * `master/teams.php` response -- `{ref_id, name, is_active}` only, no code) -- team_code is
     * NOT NULL on this side though (same "generated code, never blank" convention as every other
     * auto-created entity in this app), so a synced team's own code is always the derived
     * `ORG-{ref_id}` form, never a real Origami code. Natural-key matching therefore falls back to
     * an exact team_name_th match (same simplification Team's own `client_name` etc. never needed
     * disambiguating), acceptable since origami_ref_id is the primary match path once synced once.
     */
    protected function findByNaturalKey(int $compId, array $item): ?int {
        $name = trim((string)($item['name_th'] ?? '')) ?: trim((string)($item['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM structure_teams WHERE team_name_th = :name AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':name' => $name, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function templateColumns(): array {
        return ['ref_id' => 'Origami Ref ID (optional)', 'name_th' => 'Name (Thai)', 'name_en' => 'Name (English)'];
    }

    protected function upsertItem(int $compId, array $item, int $batchId, ?int $triggeredBy, ?int $existingId, string $dataSource): void {
        $refId = isset($item['ref_id']) && is_numeric($item['ref_id']) ? (int)$item['ref_id'] : null;
        $name = trim((string)($item['name_th'] ?? '')) ?: trim((string)($item['name'] ?? '')) ?: trim((string)($item['name_en'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Missing name.');
        }
        $code = trim((string)($item['code'] ?? '')) ?: ($refId !== null ? "ORG-{$refId}" : ('ORG-' . substr(md5($name), 0, 8)));
        if ($existingId !== null) {
            $stmt = $this->db->prepare("UPDATE structure_teams SET
                    team_name_th = :name, team_name_en = :name,
                    status = 'active', sync_batch_id = :batch_id, updated_by = :user, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([':name' => $name, ':batch_id' => $batchId, ':user' => $triggeredBy, ':id' => $existingId]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO structure_teams
                    (comp_id, team_code, team_name_th, team_name_en, status, origami_ref_id, data_source, sync_batch_id, created_by)
                VALUES (:comp_id, :code, :name, :name, 'active', :ref_id, :data_source, :batch_id, :user)");
            $stmt->execute([':comp_id' => $compId, ':code' => $code, ':name' => $name, ':ref_id' => $refId, ':data_source' => $dataSource, ':batch_id' => $batchId, ':user' => $triggeredBy]);
        }
    }
}
