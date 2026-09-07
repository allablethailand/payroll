<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/StatutoryCalculationEngine.php';
require_once __DIR__ . '/AuditLogModel.php';
class TaxStatutoryModel {
    private $db;
    private AuditLogModel $auditLog;
    // 2026-09-04, Backlog Phase 9, T047, explicit request: "generic/extensible form for future
    // deduction types... not hardcoded to only the items that exist today" -- category/calc_base
    // used to be hardcoded PHP const arrays (CATEGORIES/CALC_BASES, removed here) mirroring a DB
    // ENUM, meaning adding a new value to either needed a code deploy on BOTH sides. Replaced with
    // isValidCategory()/isValidCalcBase() DB lookups against the new master_statutory_categories/
    // master_statutory_calc_bases tables (see migration 2026-09-04_1_statutory_category_calc_base_
    // master_tables.sql's own docblock for why calc_method/rounding_mode were deliberately NOT
    // converted the same way -- both are tied to real calculation-engine code, not free-standing
    // classification data).
    private const CALC_METHODS = ['flat_rate', 'progressive_bracket', 'fixed_amount', 'formula'];
    /**
     * 2026-08-29, explicit request: "ให้มีการกำหนดเพิ่มได้ว่าปัดเศษ หรือไม่ปัด ถ้าปัดปัดแบบไหน และทศนิยม
     * ได้กี่ตำแหน่ง แล้วตอนคำนวณให้นำไปใช้ด้วย" -- 'round' = standard round-half-up (unchanged
     * behavior), 'up'/'down' = always ceiling/floor, 'none' = truncate toward zero with no
     * adjustment. Applied by StatutoryCalculationEngine::applyRounding(), not here -- this model
     * only validates and persists the config.
     */
    public const ROUNDING_MODES = ['round', 'up', 'down', 'none'];

    public function __construct() {
        $this->db = Database::getInstance()->pdo;
        $this->auditLog = new AuditLogModel($this->db);
    }

    // 2026-09-03, Backlog Phase 9, T045 -- `comp_id IS NULL` added: this is the MASTER catalog
    // browser (used by whatever eventual superadmin master-management screen T045 leaves for
    // later, see itemList()'s own controller-side comment) -- without this, a company's own custom
    // items (which didn't exist before this column was added) would leak into a query that's
    // supposed to be master-only.
    public function list(string $countryCode = ''): array {
        // editor: COALESCE(updated_by, created_by) -- 2026-08-28, explicit request to surface "last
        // edited when/by whom". updated_at is itself NOT NULL with an ON UPDATE CURRENT_TIMESTAMP
        // default, so it's always populated (equal to created_at until a genuine edit happens) --
        // updated_by stays NULL until then, which is why the WHO needs this fallback too.
        $sql = "SELECT si.*, mc.countries_name_th, mc.countries_name_en,
                    rh.id AS current_rate_id, rh.effective_date AS current_effective_date,
                    rh.employee_rate, rh.employer_rate, rh.employee_amount, rh.employer_amount,
                    editor.name_th AS last_edited_by_name_th, editor.name_en AS last_edited_by_name_en
                FROM `statutory_items` si
                LEFT JOIN `master_countries` mc ON mc.countries_code = si.country_code
                LEFT JOIN `statutory_item_rate_history` rh ON rh.id = (
                    SELECT id FROM `statutory_item_rate_history`
                    WHERE statutory_item_id = si.id AND deleted_at IS NULL
                    ORDER BY effective_date DESC, id DESC LIMIT 1
                )
                LEFT JOIN `employees` editor ON editor.id = COALESCE(si.updated_by, si.created_by)
                WHERE si.deleted_at IS NULL AND si.comp_id IS NULL";
        $params = [];
        if ($countryCode !== '') {
            $sql .= " AND si.country_code = :country_code";
            $params[':country_code'] = $countryCode;
        }
        $sql .= " ORDER BY si.country_code ASC, si.sort_order ASC, si.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `statutory_items` WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // 2026-09-03, Backlog Phase 9, T045 -- scoped by comp_id now (see migration
    // 2026-09-03_12_statutory_master_clone_comp_id.sql's own docblock on why the DB-level
    // UNIQUE KEY was dropped): a master item's code (comp_id IS NULL) must be unique among OTHER
    // master items only, and a company's own custom item's code must be unique among THAT company's
    // own custom items only -- two different companies choosing the same custom item code is fine,
    // they never see each other's items at all.
    // 2026-09-04, Backlog Phase 9, T047 -- replaces the old CATEGORIES/CALC_BASES const-array
    // checks now that both are master tables (see this class's own top-of-file docblock). A new
    // value added to either table takes effect immediately, no code deploy needed -- confirmed via
    // AskUserQuestion as the scope for T047 (calc_method/rounding_mode deliberately stay hardcoded,
    // both tied to real calculation-engine code).
    private function isValidCategory(string $category): bool {
        $stmt = $this->db->prepare("SELECT 1 FROM `master_statutory_categories` WHERE code = :code AND is_active = 1 LIMIT 1");
        $stmt->execute([':code' => $category]);
        return (bool)$stmt->fetchColumn();
    }
    private function isValidCalcBase(string $calcBase): bool {
        $stmt = $this->db->prepare("SELECT 1 FROM `master_statutory_calc_bases` WHERE code = :code AND is_active = 1 LIMIT 1");
        $stmt->execute([':code' => $calcBase]);
        return (bool)$stmt->fetchColumn();
    }

    private function isCodeDuplicate(string $countryCode, string $code, ?int $excludeId, ?int $compId): bool {
        $sql = "SELECT COUNT(*) FROM `statutory_items` WHERE country_code = :country_code AND code = :code AND deleted_at IS NULL";
        $params = [':country_code' => $countryCode, ':code' => $code];
        if ($compId === null) {
            $sql .= " AND comp_id IS NULL";
        } else {
            $sql .= " AND comp_id = :comp_id";
            $params[':comp_id'] = $compId;
        }
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * 2026-09-03, Backlog Phase 9, T045 -- `$compId` is the Master/Clone scope this call operates
     * in, NOT just an audit field: `null` (the default, matches every pre-T045 caller unchanged) =
     * operate on the system-wide MASTER catalog (comp_id IS NULL) -- gated by
     * `tax_statutory.promote_master` at the controller layer now (TaxStatutoryController::itemSave()),
     * not the old `tax_statutory.add`/`.edit` every ordinary company admin already has, precisely
     * because that combination is what let one company silently mutate every other company's data
     * before T044/T045 (see this method's own git history / the migration's docblock). Non-null =
     * operate ONLY on that one company's own custom items (comp_id = $compId) -- an UPDATE first
     * verifies the existing row's own comp_id matches, so a company can never edit another
     * company's custom item OR a master item through this path even if it guesses a valid id.
     */
    public function save(array $data, int $userId, ?int $compId = null, ?string $ip = null, ?string $userAgent = null): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        foreach (['country_code', 'code', 'name_th', 'name_en', 'category', 'calc_method', 'calc_base'] as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                return ['status' => false, 'message' => "Missing required field: {$field}"];
            }
        }

        $countryCode = strtoupper(trim((string)$data['country_code']));
        $stmtCountry = $this->db->prepare("SELECT countries_code FROM `master_countries` WHERE countries_code = :code AND is_active = 'active'");
        $stmtCountry->execute([':code' => $countryCode]);
        if (!$stmtCountry->fetch()) {
            return ['status' => false, 'message' => 'Invalid country_code.'];
        }

        $code = trim((string)$data['code']);
        if ($this->isCodeDuplicate($countryCode, $code, $id, $compId)) {
            return ['status' => false, 'message' => 'This code is already in use for the selected country.'];
        }

        $category = (string)$data['category'];
        if (!$this->isValidCategory($category)) {
            return ['status' => false, 'message' => 'Invalid category.'];
        }

        $calcMethod = (string)$data['calc_method'];
        if (!in_array($calcMethod, self::CALC_METHODS, true)) {
            return ['status' => false, 'message' => 'Invalid calc_method.'];
        }

        $calcBase = (string)$data['calc_base'];
        if (!$this->isValidCalcBase($calcBase)) {
            return ['status' => false, 'message' => 'Invalid calc_base.'];
        }

        $isEmployeeApplicable = !empty($data['is_employee_applicable']) ? 1 : 0;
        $isEmployerApplicable = !empty($data['is_employer_applicable']) ? 1 : 0;
        if (!$isEmployeeApplicable && !$isEmployerApplicable) {
            return ['status' => false, 'message' => 'At least one of employee/employer applicable must be enabled.'];
        }
        $defaultIsActive = !empty($data['default_is_active']) ? 1 : 0;
        $isCompanyRateEditable = !empty($data['is_company_rate_editable']) ? 1 : 0;
        $sortOrder = isset($data['sort_order']) && is_numeric($data['sort_order']) ? (int)$data['sort_order'] : 0;

        // 2026-09-02, Platform Hardening Phase 1.1 -- `status` is no longer sent by the Add/Edit
        // modal (the new row switch, see toggleStatus() below, is now the only way to change it).
        // Fetch and preserve the EXISTING row's status when absent from the payload, same fix
        // already applied to CompanyProfileModel::saveStructure()/PayrollCycleModel::save()/
        // BankAccountModel::save() for the identical reason.
        // 2026-09-03, Backlog Phase 9, T045 -- scoped by comp_id (same Master/Clone ownership rule
        // as isCodeDuplicate() above): a comp_id-scoped caller can only see/preserve the status of
        // ITS OWN row, never a master row or another company's row, even if it guesses a valid id.
        $existingStatus = null;
        if ($id !== null) {
            $sqlExisting = "SELECT status FROM `statutory_items` WHERE id = :id AND deleted_at IS NULL";
            $paramsExisting = [':id' => $id];
            if ($compId === null) {
                $sqlExisting .= " AND comp_id IS NULL";
            } else {
                $sqlExisting .= " AND comp_id = :comp_id";
                $paramsExisting[':comp_id'] = $compId;
            }
            $stmtExistingStatus = $this->db->prepare($sqlExisting);
            $stmtExistingStatus->execute($paramsExisting);
            $existingStatus = $stmtExistingStatus->fetchColumn();
            $existingStatus = $existingStatus === false ? null : $existingStatus;
        }
        $statusInput = $data['status'] ?? $existingStatus ?? 'active';
        $status = in_array($statusInput, ['active', 'inactive'], true) ? $statusInput : ($existingStatus ?: 'active');

        $roundingModeInput = $data['rounding_mode'] ?? 'round';
        if (!in_array($roundingModeInput, self::ROUNDING_MODES, true)) {
            return ['status' => false, 'message' => 'Invalid rounding_mode.'];
        }
        $decimalPlaces = isset($data['decimal_places']) && is_numeric($data['decimal_places']) ? (int)$data['decimal_places'] : 2;
        if ($decimalPlaces < 0 || $decimalPlaces > 4) {
            return ['status' => false, 'message' => 'decimal_places must be between 0 and 4.'];
        }

        $params = [
            ':country_code' => $countryCode,
            ':code' => $code,
            ':name_th' => trim((string)$data['name_th']),
            ':name_en' => trim((string)$data['name_en']),
            ':category' => $category,
            ':calc_method' => $calcMethod,
            ':calc_base' => $calcBase,
            ':is_employee_applicable' => $isEmployeeApplicable,
            ':is_employer_applicable' => $isEmployerApplicable,
            ':default_is_active' => $defaultIsActive,
            ':is_company_rate_editable' => $isCompanyRateEditable,
            ':sort_order' => $sortOrder,
            ':status' => $status,
            ':rounding_mode' => $roundingModeInput,
            ':decimal_places' => $decimalPlaces,
        ];

        try {
            if ($id !== null) {
                // Ownership check -- see this method's own docblock: a comp_id-scoped caller can
                // never reach a master row or another company's row through this UPDATE, even with a
                // guessed valid id, because the WHERE clause itself excludes it (0 rows found, not a
                // permission-denied response -- same "not found" framing as every other ownership
                // check in this app to avoid confirming a guessed id exists at all).
                $sqlCheck = "SELECT * FROM `statutory_items` WHERE id = :id AND deleted_at IS NULL";
                $paramsCheck = [':id' => $id];
                if ($compId === null) {
                    $sqlCheck .= " AND comp_id IS NULL";
                } else {
                    $sqlCheck .= " AND comp_id = :comp_id";
                    $paramsCheck[':comp_id'] = $compId;
                }
                $stmtCheck = $this->db->prepare($sqlCheck);
                $stmtCheck->execute($paramsCheck);
                $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $sql = "UPDATE `statutory_items` SET
                            country_code = :country_code, code = :code, name_th = :name_th, name_en = :name_en,
                            category = :category, calc_method = :calc_method, calc_base = :calc_base,
                            is_employee_applicable = :is_employee_applicable, is_employer_applicable = :is_employer_applicable,
                            default_is_active = :default_is_active, is_company_rate_editable = :is_company_rate_editable,
                            sort_order = :sort_order, status = :status, rounding_mode = :rounding_mode,
                            decimal_places = :decimal_places, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
                $params[':updated_by'] = $userId;
                $params[':id'] = $id;
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                // Platform Hardening Phase 6 (batch 4) -- only a company's OWN custom item
                // (comp_id !== null) is logged into the per-company audit_logs table; editing the
                // system-wide MASTER catalog (comp_id === null) is a different ownership story
                // entirely (affects every company on the platform at once, same as
                // CompanyStatutorySettingModel::promoteOverrideToMaster() already being out of
                // scope for this same reason) -- out of scope here too.
                if ($compId !== null) {
                    $stmtNewRow = $this->db->prepare("SELECT * FROM `statutory_items` WHERE id = :id");
                    $stmtNewRow->execute([':id' => $id]);
                    $newRow = $stmtNewRow->fetch(PDO::FETCH_ASSOC) ?: [];
                    $this->auditLog->record($compId, 'statutory_items', $id, 'update', $existing, $newRow, $userId, 'web', $ip, $userAgent);
                }
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id];
            }

            // comp_id on INSERT is exactly the Master/Clone scope this save() call was given -- NULL
            // creates a new master item, non-null creates a new custom item owned by that company.
            $params[':comp_id'] = $compId;
            $sql = "INSERT INTO `statutory_items`
                        (comp_id, country_code, code, name_th, name_en, category, calc_method, calc_base,
                         is_employee_applicable, is_employer_applicable, default_is_active, is_company_rate_editable,
                         sort_order, status, rounding_mode, decimal_places, created_by)
                    VALUES
                        (:comp_id, :country_code, :code, :name_th, :name_en, :category, :calc_method, :calc_base,
                         :is_employee_applicable, :is_employer_applicable, :default_is_active, :is_company_rate_editable,
                         :sort_order, :status, :rounding_mode, :decimal_places, :created_by)";
            $params[':created_by'] = $userId;
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return ['status' => true, 'message' => 'Created successfully.', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    // 2026-09-02, Platform Hardening Phase 1.1 -- shared status toggle switch, same shape as
    // CompanyProfileModel::toggleStructureStatus()/PayrollCycleModel::toggleStatus()/
    // BankAccountModel::toggleStatus().
    // 2026-09-03, Backlog Phase 9, T045 -- gained the SAME `?int $compId = null` Master/Clone scope
    // as save() above (null = master scope, non-null = that company's own custom items only) --
    // `statutory_items` is no longer a purely global catalog now that custom items exist.
    public function toggleStatus(int $id, int $userId, ?int $compId = null, ?string $ip = null, ?string $userAgent = null): array {
        $sql = "SELECT status FROM `statutory_items` WHERE id = :id AND deleted_at IS NULL";
        $params = [':id' => $id];
        if ($compId === null) {
            $sql .= " AND comp_id IS NULL";
        } else {
            $sql .= " AND comp_id = :comp_id";
            $params[':comp_id'] = $compId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $newStatus = $current === 'active' ? 'inactive' : 'active';
        try {
            $stmtUpdate = $this->db->prepare("UPDATE `statutory_items` SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmtUpdate->execute([':status' => $newStatus, ':updated_by' => $userId, ':id' => $id]);
            // Batch 4 -- same custom-item-only scope as save() above.
            if ($compId !== null) {
                $this->auditLog->record($compId, 'statutory_items', $id, 'update', ['status' => $current], ['status' => $newStatus], $userId, 'web', $ip, $userAgent);
            }
            return ['status' => true, 'new_status' => $newStatus, 'message' => 'Updated successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    // 2026-09-03, Backlog Phase 9, T045 -- same `?int $compId = null` Master/Clone scope as save()/
    // toggleStatus() above.
    public function delete(int $id, int $userId, ?int $compId = null, ?string $ip = null, ?string $userAgent = null): array {
        try {
            $sqlCheck = "SELECT * FROM `statutory_items` WHERE id = :id AND deleted_at IS NULL";
            $paramsCheck = [':id' => $id];
            if ($compId === null) {
                $sqlCheck .= " AND comp_id IS NULL";
            } else {
                $sqlCheck .= " AND comp_id = :comp_id";
                $paramsCheck[':comp_id'] = $compId;
            }
            $stmtCheck = $this->db->prepare($sqlCheck);
            $stmtCheck->execute($paramsCheck);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                return ['status' => false, 'message' => 'Record not found.'];
            }
            $stmt = $this->db->prepare("UPDATE `statutory_items` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
            // Batch 4 -- same custom-item-only scope as save()/toggleStatus() above.
            if ($compId !== null) {
                $this->auditLog->record($compId, 'statutory_items', $id, 'update', $existing, array_merge($existing, ['status' => 'deleted']), $userId, 'web', $ip, $userAgent);
            }
            return ['status' => true, 'message' => 'Deleted successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /**
     * 2026-09-03, Backlog Phase 9, T045 -- "Update as system default" for a company's own CUSTOM
     * item (the item itself, not a rate override -- see CompanyStatutorySettingModel::
     * promoteOverrideToMaster() for the other promote case, a rate override on an EXISTING master
     * item). Ownership-checked (must belong to $compId) then simply hands the row over to the
     * system-wide master catalog (comp_id -> NULL) -- its rate_history moves with it automatically
     * since that table is keyed by the same statutory_item_id, unaffected by this. Guarded by a
     * code-collision check against EXISTING master items in the same country (the DB no longer
     * enforces this itself, see the migration's own docblock) -- refuses rather than silently
     * creating two master items with the same code. `promoted_from_comp_id` is set once and never
     * cleared again, even if the item changes hands again later, as a permanent "which company
     * originated this" audit trail. Gated by the NEW `tax_statutory.promote_master` permission at
     * the controller layer (TaxStatutoryController::customItemPromote()), never the ordinary
     * `tax_statutory.edit` every company admin already has -- this action affects EVERY company on
     * the platform at once.
     */
    public function promoteToMaster(int $itemId, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT * FROM `statutory_items` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $itemId, ':comp_id' => $compId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            return ['status' => false, 'message' => 'Custom item not found or not owned by your company.'];
        }
        if ($this->isCodeDuplicate($item['country_code'], $item['code'], $itemId, null)) {
            return ['status' => false, 'message' => 'A master item with this code already exists for this country. Rename your custom item before promoting it.'];
        }
        try {
            $stmt = $this->db->prepare("UPDATE `statutory_items` SET comp_id = NULL, promoted_from_comp_id = COALESCE(promoted_from_comp_id, :comp_id), updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([':comp_id' => $compId, ':updated_by' => $userId, ':id' => $itemId]);
            return ['status' => true, 'message' => 'Promoted to system master successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function rateHistoryList(int $itemId): array {
        $sql = "SELECT rh.*,
                    (SELECT COUNT(*) FROM `statutory_item_brackets` WHERE statutory_item_rate_history_id = rh.id) AS bracket_count,
                    editor.name_th AS last_edited_by_name_th, editor.name_en AS last_edited_by_name_en
                FROM `statutory_item_rate_history` rh
                LEFT JOIN `employees` editor ON editor.id = COALESCE(rh.updated_by, rh.created_by)
                WHERE rh.statutory_item_id = :item_id AND rh.deleted_at IS NULL
                ORDER BY rh.effective_date DESC, rh.id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':item_id' => $itemId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function rateHistoryGet(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `statutory_item_rate_history` WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $stmtBrackets = $this->db->prepare("SELECT id, bracket_order, min_amount, max_amount, rate FROM `statutory_item_brackets` WHERE statutory_item_rate_history_id = :id ORDER BY bracket_order ASC");
        $stmtBrackets->execute([':id' => $id]);
        $row['brackets'] = $stmtBrackets->fetchAll(PDO::FETCH_ASSOC);
        return $row;
    }

    private function hasOverlap(int $itemId, string $effectiveDate, ?string $endDate, ?int $excludeId): bool {
        $sql = "SELECT effective_date, end_date FROM `statutory_item_rate_history` WHERE statutory_item_id = :item_id AND deleted_at IS NULL";
        $params = [':item_id' => $itemId];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $newStart = $effectiveDate;
        $newEnd = $endDate ?? '9999-12-31';
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $exStart = $r['effective_date'];
            $exEnd = $r['end_date'] ?? '9999-12-31';
            if ($newStart <= $exEnd && $exStart <= $newEnd) {
                return true;
            }
        }
        return false;
    }

    private function validateBrackets(array $brackets): array {
        if (empty($brackets)) {
            return ['status' => false, 'message' => 'At least one tax bracket is required.'];
        }
        $prevMax = null;
        foreach ($brackets as $i => $b) {
            if (!isset($b['min_amount']) || !is_numeric($b['min_amount']) || !isset($b['rate']) || !is_numeric($b['rate'])) {
                return ['status' => false, 'message' => 'Each bracket requires a numeric min_amount and rate.'];
            }
            $min = (float)$b['min_amount'];
            $max = ($b['max_amount'] ?? '') !== '' ? (float)$b['max_amount'] : null;
            $rate = (float)$b['rate'];
            if ($rate < 0 || $rate > 100) {
                return ['status' => false, 'message' => 'Bracket rate must be between 0-100.'];
            }
            if ($max !== null && $max <= $min) {
                return ['status' => false, 'message' => 'Bracket max_amount must be greater than min_amount.'];
            }
            if ($i > 0) {
                $expectedMin = round($prevMax + 0.01, 2);
                if (round($min, 2) !== $expectedMin) {
                    return ['status' => false, 'message' => 'Brackets must be contiguous with no gap or overlap.'];
                }
            }
            if ($max === null && $i !== count($brackets) - 1) {
                return ['status' => false, 'message' => 'Only the last bracket may have an open-ended max_amount.'];
            }
            $prevMax = $max;
        }
        return ['status' => true];
    }

    public function rateHistorySave(array $data, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        if (empty($data['statutory_item_id']) || !is_numeric($data['statutory_item_id']) || empty($data['effective_date'])) {
            return ['status' => false, 'message' => 'Missing required field: statutory_item_id or effective_date'];
        }
        $itemId = (int)$data['statutory_item_id'];
        $item = $this->get($itemId);
        if (!$item) {
            return ['status' => false, 'message' => 'Statutory item not found.'];
        }

        $effectiveDate = (string)$data['effective_date'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            return ['status' => false, 'message' => 'Invalid effective_date format.'];
        }
        $endDate = !empty($data['end_date']) ? (string)$data['end_date'] : null;
        if ($endDate !== null && $endDate <= $effectiveDate) {
            return ['status' => false, 'message' => 'end_date must be after effective_date.'];
        }

        $employeeRate = null; $employerRate = null; $employeeAmount = null; $employerAmount = null; $formulaConfig = null;
        $brackets = [];

        if ($item['calc_method'] === 'flat_rate') {
            if ($item['is_employee_applicable']) {
                if (!isset($data['employee_rate']) || !is_numeric($data['employee_rate']) || (float)$data['employee_rate'] < 0) {
                    return ['status' => false, 'message' => 'employee_rate is required and must be a non-negative number.'];
                }
                $employeeRate = (float)$data['employee_rate'];
            }
            if ($item['is_employer_applicable']) {
                if (!isset($data['employer_rate']) || !is_numeric($data['employer_rate']) || (float)$data['employer_rate'] < 0) {
                    return ['status' => false, 'message' => 'employer_rate is required and must be a non-negative number.'];
                }
                $employerRate = (float)$data['employer_rate'];
            }
        } elseif ($item['calc_method'] === 'fixed_amount') {
            if ($item['is_employee_applicable']) {
                if (!isset($data['employee_amount']) || !is_numeric($data['employee_amount']) || (float)$data['employee_amount'] < 0) {
                    return ['status' => false, 'message' => 'employee_amount is required and must be a non-negative number.'];
                }
                $employeeAmount = (float)$data['employee_amount'];
            }
            if ($item['is_employer_applicable']) {
                if (!isset($data['employer_amount']) || !is_numeric($data['employer_amount']) || (float)$data['employer_amount'] < 0) {
                    return ['status' => false, 'message' => 'employer_amount is required and must be a non-negative number.'];
                }
                $employerAmount = (float)$data['employer_amount'];
            }
        } elseif ($item['calc_method'] === 'progressive_bracket') {
            $brackets = is_array($data['brackets'] ?? null) ? $data['brackets'] : [];
            $check = $this->validateBrackets($brackets);
            if (!$check['status']) {
                return $check;
            }
        } elseif ($item['calc_method'] === 'formula') {
            if (empty($data['formula_config'])) {
                return ['status' => false, 'message' => 'formula_config is required for formula-based items.'];
            }
            $decoded = is_string($data['formula_config']) ? json_decode($data['formula_config'], true) : $data['formula_config'];
            if (!is_array($decoded)) {
                return ['status' => false, 'message' => 'formula_config must be valid JSON.'];
            }
            $formulaConfig = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }

        $minBase = ($data['min_base_amount'] ?? '') !== '' && is_numeric($data['min_base_amount']) ? (float)$data['min_base_amount'] : null;
        $maxBase = ($data['max_base_amount'] ?? '') !== '' && is_numeric($data['max_base_amount']) ? (float)$data['max_base_amount'] : null;
        $maxEmpCont = ($data['max_employee_contribution'] ?? '') !== '' && is_numeric($data['max_employee_contribution']) ? (float)$data['max_employee_contribution'] : null;
        $maxErCont = ($data['max_employer_contribution'] ?? '') !== '' && is_numeric($data['max_employer_contribution']) ? (float)$data['max_employer_contribution'] : null;
        $remark = trim((string)($data['remark'] ?? ''));

        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) {
                $this->db->beginTransaction();
            }

            if ($id === null && $endDate === null) {
                $stmtOpen = $this->db->prepare("SELECT id, effective_date FROM `statutory_item_rate_history`
                    WHERE statutory_item_id = :item_id AND deleted_at IS NULL AND end_date IS NULL AND effective_date < :effective_date
                    ORDER BY effective_date DESC LIMIT 1");
                $stmtOpen->execute([':item_id' => $itemId, ':effective_date' => $effectiveDate]);
                $openRow = $stmtOpen->fetch(PDO::FETCH_ASSOC);
                if ($openRow) {
                    $prevEnd = date('Y-m-d', strtotime($effectiveDate . ' -1 day'));
                    $stmtClose = $this->db->prepare("UPDATE `statutory_item_rate_history` SET end_date = :end_date WHERE id = :id");
                    $stmtClose->execute([':end_date' => $prevEnd, ':id' => $openRow['id']]);
                }
            }

            if ($this->hasOverlap($itemId, $effectiveDate, $endDate, $id)) {
                if ($ownTransaction) {
                    $this->db->rollBack();
                }
                return ['status' => false, 'message' => 'This effective date range overlaps with an existing rate version.'];
            }

            $params = [
                ':statutory_item_id' => $itemId,
                ':effective_date' => $effectiveDate,
                ':end_date' => $endDate,
                ':employee_rate' => $employeeRate,
                ':employer_rate' => $employerRate,
                ':employee_amount' => $employeeAmount,
                ':employer_amount' => $employerAmount,
                ':min_base_amount' => $minBase,
                ':max_base_amount' => $maxBase,
                ':max_employee_contribution' => $maxEmpCont,
                ':max_employer_contribution' => $maxErCont,
                ':formula_config' => $formulaConfig,
                ':remark' => $remark !== '' ? $remark : null,
            ];

            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT * FROM `statutory_item_rate_history` WHERE id = :id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id]);
                $existingRateRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                if (!$existingRateRow) {
                    if ($ownTransaction) {
                        $this->db->rollBack();
                    }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $sql = "UPDATE `statutory_item_rate_history` SET
                            effective_date = :effective_date, end_date = :end_date,
                            employee_rate = :employee_rate, employer_rate = :employer_rate,
                            employee_amount = :employee_amount, employer_amount = :employer_amount,
                            min_base_amount = :min_base_amount, max_base_amount = :max_base_amount,
                            max_employee_contribution = :max_employee_contribution, max_employer_contribution = :max_employer_contribution,
                            formula_config = :formula_config, remark = :remark,
                            updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
                $params[':updated_by'] = $userId;
                $params[':id'] = $id;
                // 2026-08-29, real bug found and fixed (explicit report: "Database operation failed"
                // when editing an existing rate version, e.g. the SSO contribution ceiling --
                // reproduced directly via this model, not guessed) -- $params (built once above for
                // both the UPDATE and INSERT branches) always carries `:statutory_item_id`, but only
                // the INSERT query below actually references that placeholder; the UPDATE query here
                // never did (statutory_item_id never changes on an edit). PDO's emulated-prepare mode
                // throws "SQLSTATE[HY093]: Invalid parameter number: number of bound variables does
                // not match number of tokens" whenever MORE parameters are bound than placeholders
                // exist in the query -- so every edit of an EXISTING row (the INSERT path was never
                // affected) threw a PDOException here, caught by this method's own generic catch
                // block below and surfaced only as "Database operation failed.". Confirmed via a
                // direct reproduction against this exact query+params before writing this fix.
                unset($params[':statutory_item_id']);
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                $rateHistoryId = $id;
                // Platform Hardening Phase 6 (batch 4) -- resolve the owning item's OWN comp_id
                // (already fetched into $item above) to decide whether this rate edit belongs in
                // the per-company audit_logs table at all: a MASTER item's rate history
                // (comp_id === null, e.g. editing the national SSO rate) is a system-wide change
                // affecting every company on the platform, same out-of-scope reasoning as save()/
                // toggleStatus()/delete() above -- only a company's OWN custom item's rate history
                // is logged, scoped to that company.
                if (($item['comp_id'] ?? null) !== null) {
                    $stmtNewRateRow = $this->db->prepare("SELECT * FROM `statutory_item_rate_history` WHERE id = :id");
                    $stmtNewRateRow->execute([':id' => $id]);
                    $newRateRow = $stmtNewRateRow->fetch(PDO::FETCH_ASSOC) ?: [];
                    $this->auditLog->record((int)$item['comp_id'], 'statutory_item_rate_history', $id, 'update', $existingRateRow, $newRateRow, $userId, 'web', $ip, $userAgent);
                }
            } else {
                $sql = "INSERT INTO `statutory_item_rate_history`
                            (statutory_item_id, effective_date, end_date, employee_rate, employer_rate, employee_amount, employer_amount,
                             min_base_amount, max_base_amount, max_employee_contribution, max_employer_contribution, formula_config, remark, created_by)
                        VALUES
                            (:statutory_item_id, :effective_date, :end_date, :employee_rate, :employer_rate, :employee_amount, :employer_amount,
                             :min_base_amount, :max_base_amount, :max_employee_contribution, :max_employer_contribution, :formula_config, :remark, :created_by)";
                $params[':created_by'] = $userId;
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                $rateHistoryId = (int)$this->db->lastInsertId();
            }

            if ($item['calc_method'] === 'progressive_bracket') {
                $stmtDelBrackets = $this->db->prepare("DELETE FROM `statutory_item_brackets` WHERE statutory_item_rate_history_id = :id");
                $stmtDelBrackets->execute([':id' => $rateHistoryId]);
                $stmtInsBracket = $this->db->prepare("INSERT INTO `statutory_item_brackets`
                    (statutory_item_rate_history_id, bracket_order, min_amount, max_amount, rate) VALUES (:rh_id, :order, :min, :max, :rate)");
                foreach ($brackets as $i => $b) {
                    $stmtInsBracket->execute([
                        ':rh_id' => $rateHistoryId,
                        ':order' => $i + 1,
                        ':min' => (float)$b['min_amount'],
                        ':max' => ($b['max_amount'] ?? '') !== '' ? (float)$b['max_amount'] : null,
                        ':rate' => (float)$b['rate'],
                    ]);
                }
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => $id !== null ? 'Updated successfully.' : 'Created successfully.', 'id' => $rateHistoryId];
        } catch (PDOException $e) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /**
     * 2026-08-30, calc-preview rollout (same pattern as AttendanceDeductionRuleModel::
     * previewCalculation()/OtRateSetModel::previewCalculation()): runs a DRAFT/not-yet-saved rate
     * version's fields through the SAME StatutoryCalculationEngine::computeXxx() static functions
     * real payroll calculation uses, against a sample base amount, so this can never silently
     * drift from what an actual payroll run would compute. calc_method/is_employee_applicable/
     * is_employer_applicable/rounding_mode/decimal_places come from the MASTER statutory_items
     * row (this modal edits a master rate version, not a per-company override) -- computeFlatRate()/
     * computeFixedAmount() both read employee_rate_override/employer_rate_override/employee_amount_
     * override/employer_amount_override via `??`, which are absent on this row and so simply have
     * no effect, same as a company with no override configured at all.
     */
    public function previewRateVersion(array $data, float $sampleBase = 30000.0): array {
        if (empty($data['statutory_item_id']) || !is_numeric($data['statutory_item_id'])) {
            return ['status' => false, 'message' => 'Missing required field: statutory_item_id'];
        }
        $item = $this->get((int)$data['statutory_item_id']);
        if (!$item) {
            return ['status' => false, 'message' => 'Statutory item not found.'];
        }
        if ($sampleBase < 0) {
            return ['status' => false, 'message' => 'Sample base amount must not be negative.'];
        }

        $numOrNull = function ($v) {
            return ($v ?? '') !== '' && is_numeric($v) ? (float)$v : null;
        };
        $rateRow = [
            'employee_rate' => $numOrNull($data['employee_rate'] ?? null),
            'employer_rate' => $numOrNull($data['employer_rate'] ?? null),
            'employee_amount' => $numOrNull($data['employee_amount'] ?? null),
            'employer_amount' => $numOrNull($data['employer_amount'] ?? null),
            'min_base_amount' => $numOrNull($data['min_base_amount'] ?? null),
            'max_base_amount' => $numOrNull($data['max_base_amount'] ?? null),
            'max_employee_contribution' => $numOrNull($data['max_employee_contribution'] ?? null),
            'max_employer_contribution' => $numOrNull($data['max_employer_contribution'] ?? null),
        ];

        switch ($item['calc_method']) {
            case 'flat_rate':
                if ($item['is_employee_applicable'] && $rateRow['employee_rate'] === null) {
                    return ['status' => false, 'message' => 'employee_rate is required and must be a non-negative number.'];
                }
                if ($item['is_employer_applicable'] && $rateRow['employer_rate'] === null) {
                    return ['status' => false, 'message' => 'employer_rate is required and must be a non-negative number.'];
                }
                [$empAmt, $erAmt, , $formula] = StatutoryCalculationEngine::computeFlatRate($item, $rateRow, $sampleBase);
                break;

            case 'fixed_amount':
                if ($item['is_employee_applicable'] && $rateRow['employee_amount'] === null) {
                    return ['status' => false, 'message' => 'employee_amount is required and must be a non-negative number.'];
                }
                if ($item['is_employer_applicable'] && $rateRow['employer_amount'] === null) {
                    return ['status' => false, 'message' => 'employer_amount is required and must be a non-negative number.'];
                }
                [$empAmt, $erAmt, $formula] = StatutoryCalculationEngine::computeFixedAmount($item, $rateRow);
                break;

            case 'progressive_bracket':
                $brackets = is_array($data['brackets'] ?? null) ? $data['brackets'] : [];
                $check = $this->validateBrackets($brackets);
                if (!$check['status']) {
                    return $check;
                }
                $normalized = [];
                foreach ($brackets as $b) {
                    $normalized[] = [
                        'min_amount' => (float)$b['min_amount'],
                        'max_amount' => ($b['max_amount'] ?? '') !== '' ? (float)$b['max_amount'] : null,
                        'rate' => (float)$b['rate'],
                    ];
                }
                usort($normalized, fn($a, $b) => $a['min_amount'] <=> $b['min_amount']);
                [$tax, $formula] = StatutoryCalculationEngine::computeProgressiveBracket($normalized, $sampleBase, $item);
                $empAmt = $item['is_employee_applicable'] ? $tax : 0.0;
                $erAmt = 0.0;
                break;

            case 'formula':
                if (empty($data['formula_config'])) {
                    return ['status' => false, 'message' => 'formula_config is required for formula-based items.'];
                }
                $decoded = is_string($data['formula_config']) ? json_decode($data['formula_config'], true) : $data['formula_config'];
                if (!is_array($decoded)) {
                    return ['status' => false, 'message' => 'formula_config must be valid JSON.'];
                }
                $rateRow['formula_config'] = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                [$empAmt, $erAmt, $note, $formula] = StatutoryCalculationEngine::computeFormula($item, $rateRow, $sampleBase);
                if ($note) {
                    return ['status' => false, 'message' => 'Preview could not be computed: ' . $note];
                }
                break;

            default:
                return ['status' => false, 'message' => 'Unknown calc_method.'];
        }

        return [
            'status' => true,
            'employee_amount' => $empAmt,
            'employer_amount' => $erAmt,
            'formula' => $formula,
            'sample_base_amount' => $sampleBase,
            'calc_method' => $item['calc_method'],
        ];
    }

    public function rateHistoryDelete(int $id, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        try {
            // Batch 4 -- SELECT rh.*, si.comp_id so the owning item's comp_id (needed to decide
            // whether this belongs in the per-company audit_logs table, same reasoning as
            // rateHistorySave() above) is available without a second round-trip.
            $stmtCheck = $this->db->prepare("SELECT rh.*, si.comp_id AS item_comp_id FROM `statutory_item_rate_history` rh
                JOIN `statutory_items` si ON si.id = rh.statutory_item_id
                WHERE rh.id = :id AND rh.deleted_at IS NULL");
            $stmtCheck->execute([':id' => $id]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                return ['status' => false, 'message' => 'Record not found.'];
            }
            $itemCompId = $existing['item_comp_id'];
            unset($existing['item_comp_id']);
            $stmt = $this->db->prepare("UPDATE `statutory_item_rate_history` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
            if ($itemCompId !== null) {
                $this->auditLog->record((int)$itemCompId, 'statutory_item_rate_history', $id, 'update', $existing, array_merge($existing, ['status' => 'deleted']), $userId, 'web', $ip, $userAgent);
            }
            return ['status' => true, 'message' => 'Deleted successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }
}
