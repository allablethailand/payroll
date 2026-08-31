<?php
declare(strict_types=1);
require_once __DIR__ . '/AbstractMasterDataSyncer.php';

/**
 * 2026-08-30 (OT Rate Set replacement): rewritten to sync into `ot_rate_set_items` (child rows of
 * `ot_rate_sets`) instead of the retired flat `ot_rates` table -- see OtRateSetModel's own docblock
 * for the full replacement, and OvertimeRecordModel's docblock for the sibling fix on the
 * transaction side. The generic AbstractMasterDataSyncer ref_id/comp_id-directly-on-tableName()
 * machinery assumes a single flat, self-contained comp_id-scoped table (true for the old `ot_rates`,
 * NOT true for `ot_rate_set_items`, whose comp_id lives one level up on its parent `ot_rate_sets`
 * row) -- sync()/importRow() are fully overridden here instead of reusing the base class's generic
 * upsert loop, which would silently look for `comp_id`/`origami_ref_id` columns that don't exist on
 * this table.
 *
 * Every Origami-synced OT rate lands as an item inside ONE dedicated, auto-created company Set named
 * "Synced from Origami" (kept separate from any admin-created Set so a sync run never silently
 * overwrites a manually-configured Set's rates). Matched by `origami_ref_id` (stored per-item, same
 * as every other synced entity) when present, else by SCOPE CODE within that same Set -- since
 * `ot_rate_set_items`' own real uniqueness constraint is (set_id, ot_scope_id), "the weekday rate"
 * genuinely IS this table's natural key now, not an arbitrary free-text name the way `ot_rates.
 * ot_name_en` used to be (a deliberate improvement, not a downgrade -- it closes the exact ambiguity
 * ["ถ้าบันทึกข้อมูลซ้ำ แต่คนละ Rate จะแก้ไขยังไง"] the whole OT Rate Set redesign set out to fix).
 *
 * This auto-created Set is NEVER made the company's `is_default` Set automatically -- an admin must
 * explicitly promote it (or build their own) via the OT Rate Set settings UI. Origami sync alone must
 * never silently change which Set actually governs real payroll calculation for an OT-eligible
 * employee relying on the mandatory Default.
 */
class OtRateSyncer extends AbstractMasterDataSyncer {
    private const SYNCED_SET_NAME_EN = 'Synced from Origami';
    private const SYNCED_SET_NAME_TH = 'ซิงค์จาก Origami';

    public function entityType(): string {
        return 'ot_rate';
    }

    protected function tableName(): string {
        // Not read by this class's own overridden methods below (comp_id/origami_ref_id resolution
        // needs a join through ot_rate_sets that a bare table name can't express) -- kept only to
        // satisfy the abstract contract.
        return 'ot_rate_set_items';
    }

    protected function fetch(int $origamiCompanyId, OrigamiSyncClientInterface $client): array {
        return $client->fetchOtRates($origamiCompanyId);
    }

    public function templateColumns(): array {
        return [
            'ref_id' => 'Origami Ref ID (optional)', 'name_th' => 'Name (Thai, informational only)', 'name_en' => 'Name (English, informational only)',
            'scope_code' => 'Scope Code (weekday/weekend/holiday)', 'multiplier_rate' => 'Multiplier Rate', 'calculation_base' => 'Calculation Base (hourly/daily)',
        ];
    }

    /** Finds (or, if $createIfMissing, creates) this company's dedicated "Synced from Origami" Set. */
    private function resolveSyncedSetId(int $compId, ?int $triggeredBy, bool $createIfMissing = true): ?int {
        $stmt = $this->db->prepare("SELECT id FROM ot_rate_sets WHERE comp_id = :comp_id AND name_en = :name AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':comp_id' => $compId, ':name' => self::SYNCED_SET_NAME_EN]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
        if (!$createIfMissing) {
            return null;
        }
        // 2026-08-31, real correctness bug found and fixed (explicit emphasis: "การตั้งค่าทุกอย่างต้อง
        // คำนวณออกมาได้ถูกต้อง") -- this used to always insert as non-default (`is_default=0`) on the
        // reasoning that an unreviewed synced Set shouldn't silently become the company's mandatory
        // Default without an admin choosing that. That reasoning missed a worse failure mode: a
        // company that ONLY ever uses Origami sync for OT rates (never touches the manual settings UI
        // at all) would then have ZERO active Sets with is_default=1 -- meaning
        // OtRateSetModel::resolveRatesForEmployees() returns set_id=null for EVERY OT-eligible
        // employee with no explicit assignment, i.e. OT pay silently computes to nothing company-wide.
        // Fixed to mirror OtRateSetModel::save()'s own exact "the FIRST Set a company ever creates is
        // always forced default" invariant -- force is_default=1 ONLY when this company has genuinely
        // no other Set at all yet (active, inactive, OR soft-deleted -- same check save() itself
        // does). Once ANY Set already exists (real or synced), this never again silently reassigns
        // Default -- an admin can always explicitly call setDefault() on a different Set afterward.
        $stmtAnyOther = $this->db->prepare("SELECT COUNT(*) FROM ot_rate_sets WHERE comp_id = :comp_id");
        $stmtAnyOther->execute([':comp_id' => $compId]);
        $isDefault = ((int)$stmtAnyOther->fetchColumn() === 0) ? 1 : 0;
        $this->db->prepare("INSERT INTO ot_rate_sets (comp_id, name_th, name_en, is_default, created_by) VALUES (:comp_id, :name_th, :name_en, :is_default, :created_by)")
            ->execute([':comp_id' => $compId, ':name_th' => self::SYNCED_SET_NAME_TH, ':name_en' => self::SYNCED_SET_NAME_EN, ':is_default' => $isDefault, ':created_by' => $triggeredBy]);
        return (int)$this->db->lastInsertId();
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

    /** By origami_ref_id first (present on a live sync payload), else by scope_code within the Synced Set. */
    protected function findByNaturalKey(int $compId, array $item): ?int {
        $refId = $item['ref_id'] ?? null;
        if ($refId !== null && $refId !== '' && is_numeric($refId)) {
            $byRef = $this->findByRefIdJoined($compId, (int)$refId);
            if ($byRef !== null) {
                return $byRef;
            }
        }
        $scopeCode = trim((string)($item['scope_code'] ?? ''));
        if ($scopeCode === '') {
            return null;
        }
        $setId = $this->resolveSyncedSetId($compId, null, false);
        if ($setId === null) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT i.id FROM ot_rate_set_items i
            JOIN master_ot_scope_types t ON t.id = i.ot_scope_id
            WHERE i.set_id = :set_id AND t.code = :code");
        $stmt->execute([':set_id' => $setId, ':code' => $scopeCode]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private function findByRefIdJoined(int $compId, int $refId): ?int {
        $stmt = $this->db->prepare("SELECT i.id FROM ot_rate_set_items i
            JOIN ot_rate_sets s ON s.id = i.set_id
            WHERE i.origami_ref_id = :ref AND s.comp_id = :comp AND s.deleted_at IS NULL");
        $stmt->execute([':ref' => $refId, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    protected function upsertItem(int $compId, array $item, int $batchId, ?int $triggeredBy, ?int $existingId, string $dataSource): void {
        $scopeCode = trim((string)($item['scope_code'] ?? ''));
        if ($scopeCode === '') {
            throw new InvalidArgumentException('Missing scope_code.');
        }
        $scopeId = $this->resolveScopeId($scopeCode);
        $multiplierRate = isset($item['multiplier_rate']) && is_numeric($item['multiplier_rate']) ? (float)$item['multiplier_rate'] : 1.5;
        $calcBase = in_array($item['calculation_base'] ?? '', ['hourly', 'daily'], true) ? $item['calculation_base'] : 'hourly';
        $refId = isset($item['ref_id']) && is_numeric($item['ref_id']) ? (int)$item['ref_id'] : null;
        if ($existingId !== null) {
            $this->db->prepare("UPDATE ot_rate_set_items SET calculation_method = 'multiplier', multiplier_rate = :multiplier_rate, calculation_base = :calc_base, flat_amount_rate = NULL, origami_ref_id = COALESCE(:ref_id, origami_ref_id) WHERE id = :id")
                ->execute([':multiplier_rate' => $multiplierRate, ':calc_base' => $calcBase, ':ref_id' => $refId, ':id' => $existingId]);
        } else {
            $setId = $this->resolveSyncedSetId($compId, $triggeredBy, true);
            $this->db->prepare("INSERT INTO ot_rate_set_items (set_id, ot_scope_id, calculation_method, multiplier_rate, calculation_base, origami_ref_id) VALUES (:set_id, :scope_id, 'multiplier', :multiplier_rate, :calc_base, :ref_id)")
                ->execute([':set_id' => $setId, ':scope_id' => $scopeId, ':multiplier_rate' => $multiplierRate, ':calc_base' => $calcBase, ':ref_id' => $refId]);
        }
    }

    /**
     * Fully overridden (not the base class's generic ref_id-on-tableName() loop) -- see this class's
     * own docblock for why. No "deactivate missing" pass: unlike the old flat table (where a whole
     * ROW being absent meant "Origami removed this rate"), the Synced Set only ever has 3 possible
     * scope slots total; a scope simply absent from THIS fetch is left untouched rather than deleted,
     * since "Origami didn't mention weekend this time" is a much weaker signal than an empty fetch
     * would be for a normal master-data table, and silently losing an employee's real OT calculation
     * over an incomplete fetch is a bigger risk here than elsewhere (deactivateMissing()'s own doc
     * comment on the base class describes the same class of caution for the general case).
     */
    public function sync(int $compId, int $origamiCompanyId, OrigamiSyncClientInterface $client, int $batchId, ?int $triggeredBy): array {
        $items = $this->fetch($origamiCompanyId, $client);
        $success = 0;
        $errors = [];
        foreach ($items as $item) {
            try {
                $scopeCode = trim((string)($item['scope_code'] ?? ''));
                if ($scopeCode === '') {
                    throw new InvalidArgumentException('Missing scope_code.');
                }
                if (array_key_exists('is_active', $item) && !$item['is_active']) {
                    $setId = $this->resolveSyncedSetId($compId, null, false);
                    if ($setId !== null) {
                        $this->db->prepare("DELETE i FROM ot_rate_set_items i JOIN master_ot_scope_types t ON t.id = i.ot_scope_id WHERE i.set_id = :set_id AND t.code = :code")
                            ->execute([':set_id' => $setId, ':code' => $scopeCode]);
                    }
                } else {
                    $existingId = $this->findByNaturalKey($compId, $item);
                    $this->upsertItem($compId, $item, $batchId, $triggeredBy, $existingId, 'sync');
                }
                $success++;
            } catch (Throwable $e) {
                $errors[] = ['ref_id' => $item['ref_id'] ?? null, 'message' => $e->getMessage()];
            }
        }
        return ['total' => count($items), 'success' => $success, 'error' => count($errors), 'errors' => $errors];
    }

    public function importRow(int $compId, array $item, int $batchId, ?int $triggeredBy): array {
        $existingId = $this->findByNaturalKey($compId, $item);
        $this->upsertItem($compId, $item, $batchId, $triggeredBy, $existingId, 'import');
        return ['action' => $existingId !== null ? 'updated' : 'inserted'];
    }
}
