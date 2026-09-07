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
     * (2026-09-03_12_statutory_master_clone_comp_id.sql) for the comp_id design this reads:
     *   1. MASTER items (comp_id IS NULL) for this company's country, with this company's own
     *      `company_statutory_settings` override LEFT JOINed on top -- UNCHANGED from before T045,
     *      this branch is exactly the pre-T045 query body.
     *   2. This company's own CUSTOM items (comp_id = the caller's own) -- no override layer at
     *      all (a company owns these outright, nothing to "override"), so employee_rate_override/
     *      etc. are hardcoded NULL and the item's own current rate_history IS the effective rate,
     *      read into the SAME master_employee_rate/etc. aliases branch 1 uses so every existing
     *      consumer (csRateInUseCellTs() in tax-statutory.js, StatutoryCalculationEngine) needs zero
     *      changes to handle both branches identically.
     * `item_scope` ('master'/'custom') is the one new column added to the row shape -- see this
     * method's own effective_status computation below for the one place it's actually consulted.
     */
    public function list(int $compId): array {
        $countryCode = $this->getCompanyCountry($compId);
        if ($countryCode === null) {
            return [];
        }
        // "Last edited" (2026-08-28, explicit request) applies only to a company's OWN override row
        // (css.*) -- a statutory item still on system default has never been edited BY this
        // company, so last_edited_* stays null there rather than borrowing the master item's own
        // edit time (that would misleadingly read as "this company changed something"). A custom
        // item's own last_edited_at/by is its own updated_at/updated_by directly (branch 2) -- there
        // IS no separate "company changed something" event to distinguish, the company owns the
        // whole row.
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
                    css.id AS setting_id, css.status AS setting_status,
                    css.employee_rate_override, css.employer_rate_override,
                    css.employee_amount_override, css.employer_amount_override, css.remark,
                    css.updated_at AS last_edited_at, editor.name_th AS last_edited_by_name_th, editor.name_en AS last_edited_by_name_en,
                    'master' AS item_scope
                FROM `statutory_items` si
                LEFT JOIN `master_countries` mc ON mc.countries_code = si.country_code
                LEFT JOIN `statutory_item_rate_history` rh ON rh.id = (
                    SELECT id FROM `statutory_item_rate_history`
                    WHERE statutory_item_id = si.id AND deleted_at IS NULL
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
                    NULL AS setting_id, si.status AS setting_status,
                    NULL AS employee_rate_override, NULL AS employer_rate_override,
                    NULL AS employee_amount_override, NULL AS employer_amount_override, NULL AS remark,
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
            ':comp_id1' => $compId, ':country_code1' => $countryCode,
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

    public function save(int $compId, array $data, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        if (empty($data['statutory_item_id']) || !is_numeric($data['statutory_item_id'])) {
            return ['status' => false, 'message' => 'Missing required field: statutory_item_id'];
        }
        $itemId = (int)$data['statutory_item_id'];

        $countryCode = $this->getCompanyCountry($compId);
        if ($countryCode === null) {
            return ['status' => false, 'message' => 'Company not found.'];
        }

        // 2026-09-03, Backlog Phase 9, T045 -- `AND comp_id IS NULL` added: overriding only makes
        // sense for a MASTER item (something to override a default OF). A company's own CUSTOM
        // item has no override concept at all -- it's edited directly via TaxStatutoryModel::save()
        // instead -- and without this guard, a numeric $itemId happening to belong to a DIFFERENT
        // company's custom item in the same country would have silently passed the country_code
        // check just below and let this company plant a bogus override row against data it can't
        // even see, a real cross-tenant gap that only became reachable once custom items (T045)
        // exist at all.
        $stmtItem = $this->db->prepare("SELECT * FROM `statutory_items` WHERE id = :id AND deleted_at IS NULL AND status = 'active' AND comp_id IS NULL");
        $stmtItem->execute([':id' => $itemId]);
        $item = $stmtItem->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            return ['status' => false, 'message' => 'Statutory item not found.'];
        }
        if ($item['country_code'] !== $countryCode) {
            return ['status' => false, 'message' => 'This statutory item does not belong to your company\'s country.'];
        }

        $isActive = !empty($data['is_active']);

        $employeeRateOverride = null; $employerRateOverride = null;
        $employeeAmountOverride = null; $employerAmountOverride = null;

        $hasOverrideInput = ($data['employee_rate_override'] ?? '') !== '' || ($data['employer_rate_override'] ?? '') !== ''
            || ($data['employee_amount_override'] ?? '') !== '' || ($data['employer_amount_override'] ?? '') !== '';

        if ($hasOverrideInput) {
            if (!$item['is_company_rate_editable']) {
                return ['status' => false, 'message' => 'This statutory item\'s rate cannot be adjusted per company.'];
            }
            if (!in_array($item['calc_method'], ['flat_rate', 'fixed_amount'], true)) {
                return ['status' => false, 'message' => 'Only flat_rate or fixed_amount items support per-company override.'];
            }
            if ($item['calc_method'] === 'flat_rate') {
                if (($data['employee_rate_override'] ?? '') !== '') {
                    if (!is_numeric($data['employee_rate_override']) || (float)$data['employee_rate_override'] < 0) {
                        return ['status' => false, 'message' => 'employee_rate_override must be a non-negative number.'];
                    }
                    $employeeRateOverride = (float)$data['employee_rate_override'];
                }
                if (($data['employer_rate_override'] ?? '') !== '') {
                    if (!is_numeric($data['employer_rate_override']) || (float)$data['employer_rate_override'] < 0) {
                        return ['status' => false, 'message' => 'employer_rate_override must be a non-negative number.'];
                    }
                    $employerRateOverride = (float)$data['employer_rate_override'];
                }
            } else {
                if (($data['employee_amount_override'] ?? '') !== '') {
                    if (!is_numeric($data['employee_amount_override']) || (float)$data['employee_amount_override'] < 0) {
                        return ['status' => false, 'message' => 'employee_amount_override must be a non-negative number.'];
                    }
                    $employeeAmountOverride = (float)$data['employee_amount_override'];
                }
                if (($data['employer_amount_override'] ?? '') !== '') {
                    if (!is_numeric($data['employer_amount_override']) || (float)$data['employer_amount_override'] < 0) {
                        return ['status' => false, 'message' => 'employer_amount_override must be a non-negative number.'];
                    }
                    $employerAmountOverride = (float)$data['employer_amount_override'];
                }
            }
        }

        $remark = trim((string)($data['remark'] ?? ''));
        $status = $isActive ? 'active' : 'inactive';

        try {
            $stmtExisting = $this->db->prepare("SELECT * FROM `company_statutory_settings` WHERE comp_id = :comp_id AND statutory_item_id = :item_id");
            $stmtExisting->execute([':comp_id' => $compId, ':item_id' => $itemId]);
            $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC);
            $existingId = $existing['id'] ?? false;

            $params = [
                ':employee_rate_override' => $employeeRateOverride,
                ':employer_rate_override' => $employerRateOverride,
                ':employee_amount_override' => $employeeAmountOverride,
                ':employer_amount_override' => $employerAmountOverride,
                ':remark' => $remark !== '' ? $remark : null,
                ':status' => $status,
            ];

            if ($existingId) {
                $sql = "UPDATE `company_statutory_settings` SET
                            employee_rate_override = :employee_rate_override, employer_rate_override = :employer_rate_override,
                            employee_amount_override = :employee_amount_override, employer_amount_override = :employer_amount_override,
                            remark = :remark, status = :status, deleted_at = NULL, deleted_by = NULL,
                            updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
                $params[':updated_by'] = $userId;
                $params[':id'] = $existingId;
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                $stmtNewRow = $this->db->prepare("SELECT * FROM `company_statutory_settings` WHERE id = :id");
                $stmtNewRow->execute([':id' => $existingId]);
                $newRow = $stmtNewRow->fetch(PDO::FETCH_ASSOC) ?: [];
                $this->auditLog->record($compId, 'company_statutory_settings', (int)$existingId, 'update', $existing ?: null, $newRow, $userId, 'web', $ip, $userAgent);
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => (int)$existingId];
            }

            $sql = "INSERT INTO `company_statutory_settings`
                        (comp_id, statutory_item_id, employee_rate_override, employer_rate_override,
                         employee_amount_override, employer_amount_override, remark, status, created_by)
                    VALUES
                        (:comp_id, :item_id, :employee_rate_override, :employer_rate_override,
                         :employee_amount_override, :employer_amount_override, :remark, :status, :created_by)";
            $params[':comp_id'] = $compId;
            $params[':item_id'] = $itemId;
            $params[':created_by'] = $userId;
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return ['status' => true, 'message' => 'Created successfully.', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
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
            // Same "check for ANY row incl. soft-deleted, revive by clearing deleted_at/deleted_by"
            // pattern save() above already uses -- querying only non-deleted rows here would let a
            // company that once reset() this item back to default (soft-deleting its own row) end
            // up with a second, DUPLICATE row on the next toggle, since this table's own
            // `deleted_at`-aware uniqueness can't reject that at the DB level (see this project's own
            // convention on that class of bug).
            $stmtExisting = $this->db->prepare("SELECT id, status, deleted_at FROM `company_statutory_settings` WHERE comp_id = :comp_id AND statutory_item_id = :item_id");
            $stmtExisting->execute([':comp_id' => $compId, ':item_id' => $itemId]);
            $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC);
            // A soft-deleted row's own `status` is 'deleted', not a real active/inactive value --
            // effective status for a soft-deleted (i.e. reset-to-default) row falls back to the
            // master item's own default_is_active, same as a company with no row at all.
            $currentStatus = ($existing && $existing['deleted_at'] === null)
                ? $existing['status']
                : (((int)$item['default_is_active']) === 1 ? 'active' : 'inactive');
            $newStatus = $currentStatus === 'active' ? 'inactive' : 'active';

            if ($existing && $existing['deleted_at'] === null) {
                // Live row -- just flip status, leave its own overrides/remark exactly as they are
                // (a quick toggle isn't meant to touch the configured rate, only enable/disable).
                $stmtUpdate = $this->db->prepare("UPDATE `company_statutory_settings` SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmtUpdate->execute([':status' => $newStatus, ':updated_by' => $userId, ':id' => $existing['id']]);
                $this->auditLog->record($compId, 'company_statutory_settings', (int)$existing['id'], 'update', ['status' => $currentStatus], ['status' => $newStatus], $userId, 'web', $ip, $userAgent);
            } elseif ($existing) {
                // Reviving a row that reset() previously soft-deleted -- reset() never clears the
                // override columns themselves (only marks the row deleted), so reviving it here MUST
                // also null them out, or the company's old custom rate would silently reappear even
                // though the UI showed "Using Default" (system default) right up until this toggle.
                $stmtUpdate = $this->db->prepare("UPDATE `company_statutory_settings` SET status = :status, employee_rate_override = NULL, employer_rate_override = NULL, employee_amount_override = NULL, employer_amount_override = NULL, remark = NULL, deleted_at = NULL, deleted_by = NULL, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmtUpdate->execute([':status' => $newStatus, ':updated_by' => $userId, ':id' => $existing['id']]);
                $this->auditLog->record($compId, 'company_statutory_settings', (int)$existing['id'], 'update', ['status' => $existing['status']], ['status' => $newStatus], $userId, 'web', $ip, $userAgent);
            } else {
                $stmtInsert = $this->db->prepare("INSERT INTO `company_statutory_settings` (comp_id, statutory_item_id, status, created_by) VALUES (:comp_id, :item_id, :status, :created_by)");
                $stmtInsert->execute([':comp_id' => $compId, ':item_id' => $itemId, ':status' => $newStatus, ':created_by' => $userId]);
            }
            return ['status' => true, 'new_status' => $newStatus, 'message' => 'Updated successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function reset(int $compId, int $itemId, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        try {
            $stmtCheck = $this->db->prepare("SELECT * FROM `company_statutory_settings` WHERE comp_id = :comp_id AND statutory_item_id = :item_id AND deleted_at IS NULL");
            $stmtCheck->execute([':comp_id' => $compId, ':item_id' => $itemId]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                return ['status' => false, 'message' => 'Record not found.'];
            }
            $id = $existing['id'];
            $stmt = $this->db->prepare("UPDATE `company_statutory_settings` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
            $this->auditLog->record($compId, 'company_statutory_settings', (int)$id, 'update', $existing, array_merge($existing, ['status' => 'deleted']), $userId, 'web', $ip, $userAgent);
            return ['status' => true, 'message' => 'Reset to system default.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /**
     * 2026-09-03, Backlog Phase 9, T045 -- "Update as system default" for a company's RATE OVERRIDE
     * on an EXISTING master item (the other promote case -- see TaxStatutoryModel::promoteToMaster()
     * for promoting a whole CUSTOM item instead). Takes this company's own override values and
     * writes them as a brand-new dated version onto the MASTER item's own `statutory_item_rate_
     * history` -- the exact same "close the previously-open row, insert a new one" pattern
     * TaxStatutoryModel::rateHistorySave() already uses, reproduced here rather than shared since
     * that method lives on a different model scoped to a different table-ownership story. This is
     * also the sanctioned answer to the "known gap" T044 flagged (nothing in the UI can add a new
     * master rate version anymore) -- a company tests/uses its own override first, then promotes it
     * once confirmed, rather than editing the master catalog directly. The company's own override is
     * cleared immediately after (it now exactly matches the new master default, so leaving it in
     * place would just be a redundant, confusing duplicate of the same number). Gated by the NEW
     * `tax_statutory.promote_master` permission at the controller layer
     * (TaxStatutoryController::companySettingPromote()), never the ordinary `tax_statutory.edit`
     * every company admin already has -- this action affects EVERY company on the platform at once.
     */
    public function promoteOverrideToMaster(int $compId, int $itemId, string $effectiveDate, int $userId): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            return ['status' => false, 'message' => 'Invalid effective_date format.'];
        }
        $stmtItem = $this->db->prepare("SELECT * FROM `statutory_items` WHERE id = :id AND comp_id IS NULL AND deleted_at IS NULL AND status = 'active'");
        $stmtItem->execute([':id' => $itemId]);
        $item = $stmtItem->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            return ['status' => false, 'message' => 'Master statutory item not found.'];
        }
        if (!in_array($item['calc_method'], ['flat_rate', 'fixed_amount'], true)) {
            return ['status' => false, 'message' => 'Only flat_rate or fixed_amount items support promoting an override.'];
        }
        $stmtSetting = $this->db->prepare("SELECT * FROM `company_statutory_settings` WHERE comp_id = :comp_id AND statutory_item_id = :item_id AND deleted_at IS NULL");
        $stmtSetting->execute([':comp_id' => $compId, ':item_id' => $itemId]);
        $setting = $stmtSetting->fetch(PDO::FETCH_ASSOC);
        $hasOverride = $setting && (
            $setting['employee_rate_override'] !== null || $setting['employer_rate_override'] !== null
            || $setting['employee_amount_override'] !== null || $setting['employer_amount_override'] !== null
        );
        if (!$hasOverride) {
            return ['status' => false, 'message' => 'This company has no override configured for this item to promote.'];
        }

        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            $stmtOpen = $this->db->prepare("SELECT id FROM `statutory_item_rate_history`
                WHERE statutory_item_id = :item_id AND deleted_at IS NULL AND end_date IS NULL AND effective_date < :effective_date
                ORDER BY effective_date DESC LIMIT 1");
            $stmtOpen->execute([':item_id' => $itemId, ':effective_date' => $effectiveDate]);
            $openRow = $stmtOpen->fetch(PDO::FETCH_ASSOC);
            if ($openRow) {
                $prevEnd = date('Y-m-d', strtotime($effectiveDate . ' -1 day'));
                $this->db->prepare("UPDATE `statutory_item_rate_history` SET end_date = :end_date WHERE id = :id")
                    ->execute([':end_date' => $prevEnd, ':id' => $openRow['id']]);
            }
            $this->db->prepare("INSERT INTO `statutory_item_rate_history`
                    (statutory_item_id, effective_date, employee_rate, employer_rate, employee_amount, employer_amount, remark, created_by)
                VALUES (:item_id, :effective_date, :employee_rate, :employer_rate, :employee_amount, :employer_amount, :remark, :created_by)")
                ->execute([
                    ':item_id' => $itemId,
                    ':effective_date' => $effectiveDate,
                    ':employee_rate' => $setting['employee_rate_override'],
                    ':employer_rate' => $setting['employer_rate_override'],
                    ':employee_amount' => $setting['employee_amount_override'],
                    ':employer_amount' => $setting['employer_amount_override'],
                    ':remark' => 'Promoted from comp_id=' . $compId . '\'s own override',
                    ':created_by' => $userId,
                ]);
            $this->db->prepare("UPDATE `company_statutory_settings` SET
                    employee_rate_override = NULL, employer_rate_override = NULL,
                    employee_amount_override = NULL, employer_amount_override = NULL,
                    updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id")
                ->execute([':updated_by' => $userId, ':id' => $setting['id']]);
            if ($own) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Promoted to system default successfully.'];
        } catch (PDOException $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }
}
