<?php
declare(strict_types=1);
require_once __DIR__ . '/AuditLogModel.php';
class CompanyStatutorySettingModel {
    private $db;
    private AuditLogModel $auditLog;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->auditLog = new AuditLogModel($this->db);
    }

    private function getCompanyCountry(int $compId): ?string {
        $stmt = $this->db->prepare("SELECT registered_country FROM `companies` WHERE id = :id");
        $stmt->execute([':id' => $compId]);
        $country = $stmt->fetchColumn();
        return $country !== false ? (string)$country : null;
    }

    /**
     * 2026-09-03, Backlog Phase 9, T045 -- UNION of 2 branches, see the migration's own docblock
     * (2026-09-03_12_statutory_master_clone_comp_id.sql) for the comp_id design this reads.
     * 2026-09-08, Clone+Version redesign (database/migrations/2026-09-08_1_statutory_company_
     * rate_versions.sql) -- the flat `company_statutory_settings.employee_rate_override` etc.
     * fields are gone from this row shape entirely, replaced by `effective_employee_rate`/etc.:
     * this company's own CURRENT version of the item (comp_id-scoped `statutory_item_rate_
     * history` row, same "latest effective_date wins" convention `master_employee_rate` already
     * used) when the company has cloned/customized one, falling back to Master's own current row
     * otherwise -- this is a DISPLAY convenience only (StatutoryCalculationEngine resolves the
     * real date-ranged rate itself, independently, for actual calculation -- see that class's own
     * resolveEffectiveRate()). `effective_rate_source` ('master_clone'/'company_custom'/NULL)
     * drives the "Default"/"Customized" badge in the UI -- NULL for a custom item (no separate
     * clone/customize concept, the whole item is the company's own) or a master item the company
     * has never been cloned for at all (pure Master fallback, no row of its own yet).
     *   1. MASTER items (comp_id IS NULL) for this company's country.
     *   2. This company's own CUSTOM items (comp_id = the caller's own) -- `master_employee_rate`
     *      etc. and `effective_employee_rate` etc. are the SAME value here (the item's own current
     *      rate_history IS both "master" and "effective" for something the company owns outright)
     *      so every existing consumer keeps working whether it's looking at a master or custom row.
     * `item_scope` ('master'/'custom') is the one new column added to the row shape -- see this
     * method's own effective_status computation below for the one place it's actually consulted.
     */
    public function list(int $compId): array {
        $countryCode = $this->getCompanyCountry($compId);
        if ($countryCode === null) {
            return [];
        }
        // sort_order added to BOTH branches' own SELECT list (real bug caught before shipping, not
        // guessed): a plain single-table query can ORDER BY an underlying table column that isn't in
        // its own SELECT list, but a UNION's own ORDER BY can only reference the COMBINED result
        // set's output columns -- `si.sort_order` (bare, no output alias) threw "Unknown column
        // 'sort_order'" the moment this ran for real, reproduced live via the payroll calculation
        // path (StatutoryCalculationEngine -> this method) that every recalculate() call goes
        // through.
        $sql = "SELECT si.id AS statutory_item_id, si.code, si.name_th, si.name_en, si.category, si.calc_method, si.calc_base,
                    si.is_employee_applicable, si.is_employer_applicable, si.default_is_active, si.is_company_rate_editable,
                    si.rounding_mode, si.decimal_places, si.country_code, si.sort_order,
                    mc.countries_name_th, mc.countries_name_en,
                    rh.employee_rate AS master_employee_rate, rh.employer_rate AS master_employer_rate,
                    rh.employee_amount AS master_employee_amount, rh.employer_amount AS master_employer_amount,
                    COALESCE(crh.employee_rate, rh.employee_rate) AS effective_employee_rate,
                    COALESCE(crh.employer_rate, rh.employer_rate) AS effective_employer_rate,
                    COALESCE(crh.employee_amount, rh.employee_amount) AS effective_employee_amount,
                    COALESCE(crh.employer_amount, rh.employer_amount) AS effective_employer_amount,
                    crh.source AS effective_rate_source,
                    css.id AS setting_id, css.status AS setting_status,
                    css.updated_at AS last_edited_at, editor.name_th AS last_edited_by_name_th, editor.name_en AS last_edited_by_name_en,
                    'master' AS item_scope
                FROM `statutory_items` si
                LEFT JOIN `master_countries` mc ON mc.countries_code = si.country_code
                LEFT JOIN `statutory_item_rate_history` rh ON rh.id = (
                    SELECT id FROM `statutory_item_rate_history`
                    WHERE statutory_item_id = si.id AND comp_id IS NULL AND deleted_at IS NULL
                    ORDER BY effective_date DESC, id DESC LIMIT 1
                )
                LEFT JOIN `statutory_item_rate_history` crh ON crh.id = (
                    SELECT id FROM `statutory_item_rate_history`
                    WHERE statutory_item_id = si.id AND comp_id = :comp_id3 AND deleted_at IS NULL
                    ORDER BY effective_date DESC, id DESC LIMIT 1
                )
                LEFT JOIN `company_statutory_settings` css ON css.statutory_item_id = si.id AND css.comp_id = :comp_id1 AND css.deleted_at IS NULL
                LEFT JOIN `employees` editor ON editor.id = COALESCE(css.updated_by, css.created_by)
                WHERE si.deleted_at IS NULL AND si.status = 'active' AND si.country_code = :country_code1 AND si.comp_id IS NULL

                UNION ALL

                SELECT si.id AS statutory_item_id, si.code, si.name_th, si.name_en, si.category, si.calc_method, si.calc_base,
                    si.is_employee_applicable, si.is_employer_applicable, si.default_is_active, si.is_company_rate_editable,
                    si.rounding_mode, si.decimal_places, si.country_code, si.sort_order,
                    mc2.countries_name_th, mc2.countries_name_en,
                    rh2.employee_rate AS master_employee_rate, rh2.employer_rate AS master_employer_rate,
                    rh2.employee_amount AS master_employee_amount, rh2.employer_amount AS master_employer_amount,
                    rh2.employee_rate AS effective_employee_rate, rh2.employer_rate AS effective_employer_rate,
                    rh2.employee_amount AS effective_employee_amount, rh2.employer_amount AS effective_employer_amount,
                    NULL AS effective_rate_source,
                    NULL AS setting_id, si.status AS setting_status,
                    si.updated_at AS last_edited_at, editor2.name_th AS last_edited_by_name_th, editor2.name_en AS last_edited_by_name_en,
                    'custom' AS item_scope
                FROM `statutory_items` si
                LEFT JOIN `master_countries` mc2 ON mc2.countries_code = si.country_code
                LEFT JOIN `statutory_item_rate_history` rh2 ON rh2.id = (
                    SELECT id FROM `statutory_item_rate_history`
                    WHERE statutory_item_id = si.id AND deleted_at IS NULL
                    ORDER BY effective_date DESC, id DESC LIMIT 1
                )
                LEFT JOIN `employees` editor2 ON editor2.id = COALESCE(si.updated_by, si.created_by)
                WHERE si.deleted_at IS NULL AND si.status != 'deleted' AND si.country_code = :country_code2 AND si.comp_id = :comp_id2

                ORDER BY item_scope DESC, sort_order ASC, statutory_item_id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':comp_id1' => $compId, ':comp_id3' => $compId, ':country_code1' => $countryCode,
            ':country_code2' => $countryCode, ':comp_id2' => $compId,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            // A custom item's own `status` column (aliased into setting_status by branch 2 above) IS
            // the effective status directly -- there is no separate "no row yet, fall back to
            // default_is_active" state for an item the company owns outright, unlike a master item.
            $row['effective_status'] = $row['item_scope'] === 'custom'
                ? $row['setting_status']
                : ($row['setting_id'] !== null ? $row['setting_status'] : (((int)$row['default_is_active']) === 1 ? 'active' : 'inactive'));
        }
        return $rows;
    }

    public function get(int $compId, int $itemId): ?array {
        $rows = $this->list($compId);
        foreach ($rows as $row) {
            if ((int)$row['statutory_item_id'] === $itemId) {
                return $row;
            }
        }
        return null;
    }

    // 2026-09-02, Platform Hardening Phase 1.1 -- shared status toggle switch. Genuinely more than a
    // plain UPDATE ... SET status like every other converted table's own toggleStatus(): the value
    // shown/toggled in the UI is `effective_status` (see list()'s own computed fallback), a company
    // may have NO `company_statutory_settings` row yet at all (still on the master item's own
    // default_is_active) -- flipping "off" from that state means INSERTing a new row, not UPDATEing
    // one that doesn't exist. Preserves whatever override/remark values an EXISTING row already has
    // (only `status` changes) -- a brand-new row created by this toggle has no overrides at all
    // (same as `reset()`'s own "back to system default rate, just explicitly enabled/disabled" idea).
    public function toggleStatus(int $compId, int $itemId, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        $countryCode = $this->getCompanyCountry($compId);
        if ($countryCode === null) {
            return ['status' => false, 'message' => 'Company not found.'];
        }
        // 2026-09-03, Backlog Phase 9, T045 -- a CUSTOM item owned by this company toggles its OWN
        // `statutory_items.status` directly (ownership-checked via `comp_id = :comp_id` in the WHERE
        // itself) -- there is no company_statutory_settings row to flip for something the company
        // owns outright, unlike a master item's own branch just below.
        $stmtCustom = $this->db->prepare("SELECT status FROM `statutory_items` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL AND status != 'deleted' AND country_code = :country_code");
        $stmtCustom->execute([':id' => $itemId, ':comp_id' => $compId, ':country_code' => $countryCode]);
        $customStatus = $stmtCustom->fetchColumn();
        if ($customStatus !== false) {
            $newStatus = $customStatus === 'active' ? 'inactive' : 'active';
            try {
                $stmt = $this->db->prepare("UPDATE `statutory_items` SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([':status' => $newStatus, ':updated_by' => $userId, ':id' => $itemId]);
                $this->auditLog->record($compId, 'statutory_items', $itemId, 'update', ['status' => $customStatus], ['status' => $newStatus], $userId, 'web', $ip, $userAgent);
                return ['status' => true, 'new_status' => $newStatus, 'message' => 'Updated successfully.'];
            } catch (PDOException $e) {
                return ['status' => false, 'message' => 'Database operation failed.'];
            }
        }
        $stmtItem = $this->db->prepare("SELECT default_is_active FROM `statutory_items` WHERE id = :id AND deleted_at IS NULL AND status = 'active' AND country_code = :country_code AND comp_id IS NULL");
        $stmtItem->execute([':id' => $itemId, ':country_code' => $countryCode]);
        $item = $stmtItem->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            return ['status' => false, 'message' => 'Statutory item not found.'];
        }
        try {
            // 2026-09-08, Clone+Version redesign -- the "revive a row reset() previously soft-
            // deleted" branch this used to have is gone: nothing in the app soft-deletes a
            // `company_statutory_settings` row anymore (reset()/promoteOverrideToMaster() -- the
            // only 2 methods that ever touched this table's rate-override columns -- are both
            // retired; rate versioning lives entirely in `statutory_item_rate_history` now, see
            // CompanyStatutoryRateVersionModel). This table's own rows exist for exactly one job,
            // enable/disable, and are only ever live or absent.
            $stmtExisting = $this->db->prepare("SELECT id, status FROM `company_statutory_settings` WHERE comp_id = :comp_id AND statutory_item_id = :item_id AND deleted_at IS NULL");
            $stmtExisting->execute([':comp_id' => $compId, ':item_id' => $itemId]);
            $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC);
            $currentStatus = $existing ? $existing['status'] : (((int)$item['default_is_active']) === 1 ? 'active' : 'inactive');
            $newStatus = $currentStatus === 'active' ? 'inactive' : 'active';

            if ($existing) {
                $stmtUpdate = $this->db->prepare("UPDATE `company_statutory_settings` SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmtUpdate->execute([':status' => $newStatus, ':updated_by' => $userId, ':id' => $existing['id']]);
                $this->auditLog->record($compId, 'company_statutory_settings', (int)$existing['id'], 'update', ['status' => $currentStatus], ['status' => $newStatus], $userId, 'web', $ip, $userAgent);
            } else {
                $stmtInsert = $this->db->prepare("INSERT INTO `company_statutory_settings` (comp_id, statutory_item_id, status, created_by) VALUES (:comp_id, :item_id, :status, :created_by)");
                $stmtInsert->execute([':comp_id' => $compId, ':item_id' => $itemId, ':status' => $newStatus, ':created_by' => $userId]);
            }
            return ['status' => true, 'new_status' => $newStatus, 'message' => 'Updated successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }
}
