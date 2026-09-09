<?php
declare(strict_types=1);
require_once __DIR__ . '/AuditLogModel.php';
require_once __DIR__ . '/StatutoryRateHistoryTrait.php';

/**
 * 2026-09-08, Clone+Version redesign -- explicit request: "มี Master สำหรับแต่ละประเทศ ตอนเปิดใช้งาน
 * บริษัท ก็ดึง Master Clone มาเพื่อให้บริษัทปรับแต่งเอง แต่เพิ่มได้หลาย Version และถ้าอยากจะดึง Master ก็
 * สามารถดึงได้ทุกเมื่อที่ต้องการกลับมาใช้ แต่ต้องมีบอกว่า ปรับแต่งหรือ Default". Replaces the old flat
 * `company_statutory_settings.employee_rate_override` (single value, no history, no per-company
 * dated versions) with a REAL per-company dated version list, sharing the exact same
 * `statutory_item_rate_history` table Master items' own rate history already lives on -- see
 * database/migrations/2026-09-08_1_statutory_company_rate_versions.sql for the schema this reads/
 * writes (`comp_id` scopes a row to one company's own clone/customization of a Master item;
 * `source` = 'master_clone' (byte-identical to what Master had when cloned/pulled -- the
 * "Default" badge) or 'company_custom' (the company edited it -- the "Customized" badge)).
 *
 * Deliberately scoped to MASTER items only (`statutory_items.comp_id IS NULL`) -- a company's own
 * CUSTOM item (no Master counterpart) is edited directly via `TaxStatutoryModel::rateHistorySave()`
 * instead, exactly as before this redesign; this model never touches a custom item's rate history.
 *
 * Enable/disable is NOT this model's job -- that stays `CompanyStatutorySettingModel::
 * toggleStatus()`'s exclusive responsibility, unrelated to which version's rate is in effect.
 */
class CompanyStatutoryRateVersionModel {
    use StatutoryRateHistoryTrait;
    private $db;
    private AuditLogModel $auditLog;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->auditLog = new AuditLogModel($this->db);
    }

    /**
     * Confirms $itemId is a real, active MASTER item belonging to $compId's own country and
     * returns its `statutory_items` row -- every public method below needs this same ownership/
     * scope check before touching that item's comp_id-scoped rate history, so it's centralized
     * here rather than repeated per method.
     */
    private function getOwnedMasterItem(int $compId, int $itemId): ?array {
        $stmt = $this->db->prepare("SELECT c.registered_country, si.* FROM `companies` c
            JOIN `statutory_items` si ON si.country_code = c.registered_country AND si.comp_id IS NULL
                AND si.deleted_at IS NULL AND si.status = 'active'
            WHERE c.id = :comp_id AND si.id = :item_id");
        $stmt->execute([':comp_id' => $compId, ':item_id' => $itemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function list(int $compId, int $itemId): array {
        if (!$this->getOwnedMasterItem($compId, $itemId)) {
            return [];
        }
        $sql = "SELECT rh.*,
                    (SELECT COUNT(*) FROM `statutory_item_brackets` WHERE statutory_item_rate_history_id = rh.id) AS bracket_count,
                    editor.name_th AS last_edited_by_name_th, editor.name_en AS last_edited_by_name_en
                FROM `statutory_item_rate_history` rh
                LEFT JOIN `employees` editor ON editor.id = COALESCE(rh.updated_by, rh.created_by)
                WHERE rh.statutory_item_id = :item_id AND rh.comp_id = :comp_id AND rh.deleted_at IS NULL
                ORDER BY rh.effective_date DESC, rh.id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':item_id' => $itemId, ':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $compId, int $versionId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `statutory_item_rate_history` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $versionId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $stmtBrackets = $this->db->prepare("SELECT id, bracket_order, min_amount, max_amount, rate FROM `statutory_item_brackets` WHERE statutory_item_rate_history_id = :id ORDER BY bracket_order ASC");
        $stmtBrackets->execute([':id' => $versionId]);
        $row['brackets'] = $stmtBrackets->fetchAll(PDO::FETCH_ASSOC);
        return $row;
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

    /**
     * Adds/edits ONE of this company's own dated versions of a Master item -- direct mirror of
     * `TaxStatutoryModel::rateHistorySave()`'s own validation/overlap/close-open-row/insert-or-
     * update shape, just comp_id-scoped throughout via the shared trait. Every row this writes
     * (new or edited) is stamped `source = 'company_custom'` -- once a company has touched a
     * version by hand it is no longer "exactly what Master had", regardless of whether the
     * values happen to numerically match right now.
     */
    public function save(int $compId, array $data, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        if (empty($data['statutory_item_id']) || !is_numeric($data['statutory_item_id']) || empty($data['effective_date'])) {
            return ['status' => false, 'message' => 'Missing required field: statutory_item_id or effective_date'];
        }
        $itemId = (int)$data['statutory_item_id'];
        $item = $this->getOwnedMasterItem($compId, $itemId);
        if (!$item) {
            return ['status' => false, 'message' => 'Statutory item not found.'];
        }
        if (!$item['is_company_rate_editable']) {
            return ['status' => false, 'message' => 'This statutory item\'s rate cannot be adjusted per company.'];
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

            if ($id !== null) {
                // Ownership check -- must be THIS company's own version, not some other
                // company's row that happens to share the same statutory_item_id.
                $stmtCheck = $this->db->prepare("SELECT * FROM `statutory_item_rate_history` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                $existingRateRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                if (!$existingRateRow) {
                    if ($ownTransaction) {
                        $this->db->rollBack();
                    }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
            } else {
                $existingRateRow = null;
                if ($endDate === null) {
                    $this->closeOpenRateVersion($itemId, $compId, $effectiveDate);
                }
            }

            if ($this->hasOverlapForItem($itemId, $compId, $effectiveDate, $endDate, $id)) {
                if ($ownTransaction) {
                    $this->db->rollBack();
                }
                return ['status' => false, 'message' => 'This effective date range overlaps with an existing version of your own.'];
            }

            $params = [
                ':statutory_item_id' => $itemId,
                ':comp_id' => $compId,
                ':source' => 'company_custom',
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
                $sql = "UPDATE `statutory_item_rate_history` SET
                            source = :source, effective_date = :effective_date, end_date = :end_date,
                            employee_rate = :employee_rate, employer_rate = :employer_rate,
                            employee_amount = :employee_amount, employer_amount = :employer_amount,
                            min_base_amount = :min_base_amount, max_base_amount = :max_base_amount,
                            max_employee_contribution = :max_employee_contribution, max_employer_contribution = :max_employer_contribution,
                            formula_config = :formula_config, remark = :remark,
                            updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
                unset($params[':statutory_item_id'], $params[':comp_id']);
                $params[':updated_by'] = $userId;
                $params[':id'] = $id;
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                $rateHistoryId = $id;
                $stmtNewRow = $this->db->prepare("SELECT * FROM `statutory_item_rate_history` WHERE id = :id");
                $stmtNewRow->execute([':id' => $id]);
                $newRow = $stmtNewRow->fetch(PDO::FETCH_ASSOC) ?: [];
                $this->auditLog->record($compId, 'statutory_item_rate_history', $id, 'update', $existingRateRow, $newRow, $userId, 'web', $ip, $userAgent);
            } else {
                $sql = "INSERT INTO `statutory_item_rate_history`
                            (statutory_item_id, comp_id, source, effective_date, end_date, employee_rate, employer_rate, employee_amount, employer_amount,
                             min_base_amount, max_base_amount, max_employee_contribution, max_employer_contribution, formula_config, remark, created_by)
                        VALUES
                            (:statutory_item_id, :comp_id, :source, :effective_date, :end_date, :employee_rate, :employer_rate, :employee_amount, :employer_amount,
                             :min_base_amount, :max_base_amount, :max_employee_contribution, :max_employer_contribution, :formula_config, :remark, :created_by)";
                $params[':created_by'] = $userId;
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                // Create branch is not audited -- same convention every other "create" in this app's
                // audit trail already follows (see e.g. TaxStatutoryModel::rateHistorySave()'s own
                // INSERT branch, CompanyStatutorySettingModel's old create-branch, ApprovalWorkflow's
                // own save()) -- only an UPDATE to an existing row gets a diff logged.
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

    public function delete(int $compId, int $versionId, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        try {
            $stmtCheck = $this->db->prepare("SELECT * FROM `statutory_item_rate_history` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmtCheck->execute([':id' => $versionId, ':comp_id' => $compId]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                return ['status' => false, 'message' => 'Record not found.'];
            }
            $stmt = $this->db->prepare("UPDATE `statutory_item_rate_history` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $versionId]);
            $this->auditLog->record($compId, 'statutory_item_rate_history', $versionId, 'update', $existing, array_merge($existing, ['status' => 'deleted']), $userId, 'web', $ip, $userAgent);
            return ['status' => true, 'message' => 'Deleted successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /**
     * "ดึง Master กลับมาใช้ได้ทุกเมื่อ" -- reads Master's own currently-effective version and adds
     * it as a brand-new version of this company's own, tagged `source = 'master_clone'` (the
     * "Default" badge). Refuses if Master has no version in effect right now (nothing to pull).
     */
    public function pullFromMaster(int $compId, int $itemId, string $effectiveDate, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            return ['status' => false, 'message' => 'Invalid effective_date format.'];
        }
        $item = $this->getOwnedMasterItem($compId, $itemId);
        if (!$item) {
            return ['status' => false, 'message' => 'Statutory item not found.'];
        }

        $stmtMaster = $this->db->prepare("SELECT * FROM `statutory_item_rate_history`
            WHERE statutory_item_id = :item_id AND comp_id IS NULL AND deleted_at IS NULL
            AND effective_date <= :today AND (end_date IS NULL OR end_date >= :today)
            ORDER BY effective_date DESC LIMIT 1");
        $stmtMaster->execute([':item_id' => $itemId, ':today' => date('Y-m-d')]);
        $masterRow = $stmtMaster->fetch(PDO::FETCH_ASSOC);
        if (!$masterRow) {
            return ['status' => false, 'message' => 'This item has no current Master rate to pull.'];
        }

        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) {
                $this->db->beginTransaction();
            }
            // Close whichever of this company's own OPEN-ended versions precedes the new pull FIRST
            // (same order save()'s own id===null+endDate===null branch already uses) -- otherwise a
            // routine "pull the latest Master rate in again" would spuriously overlap against the
            // company's own still-open PREVIOUS pull/version instead of correctly superseding it.
            $this->closeOpenRateVersion($itemId, $compId, $effectiveDate);
            if ($this->hasOverlapForItem($itemId, $compId, $effectiveDate, null, null)) {
                if ($ownTransaction) {
                    $this->db->rollBack();
                }
                return ['status' => false, 'message' => 'This effective date range overlaps with an existing version of your own.'];
            }
            $stmt = $this->db->prepare("INSERT INTO `statutory_item_rate_history`
                    (statutory_item_id, comp_id, source, effective_date, end_date, employee_rate, employer_rate, employee_amount, employer_amount,
                     min_base_amount, max_base_amount, max_employee_contribution, max_employer_contribution, formula_config, remark, created_by)
                VALUES
                    (:item_id, :comp_id, 'master_clone', :effective_date, NULL, :employee_rate, :employer_rate, :employee_amount, :employer_amount,
                     :min_base_amount, :max_base_amount, :max_employee_contribution, :max_employer_contribution, :formula_config, :remark, :created_by)");
            $stmt->execute([
                ':item_id' => $itemId,
                ':comp_id' => $compId,
                ':effective_date' => $effectiveDate,
                ':employee_rate' => $masterRow['employee_rate'],
                ':employer_rate' => $masterRow['employer_rate'],
                ':employee_amount' => $masterRow['employee_amount'],
                ':employer_amount' => $masterRow['employer_amount'],
                ':min_base_amount' => $masterRow['min_base_amount'],
                ':max_base_amount' => $masterRow['max_base_amount'],
                ':max_employee_contribution' => $masterRow['max_employee_contribution'],
                ':max_employer_contribution' => $masterRow['max_employer_contribution'],
                ':formula_config' => $masterRow['formula_config'],
                ':remark' => 'Pulled from Master on ' . date('Y-m-d'),
                ':created_by' => $userId,
            ]);
            $newVersionId = (int)$this->db->lastInsertId();
            if ($item['calc_method'] === 'progressive_bracket') {
                $stmtBrackets = $this->db->prepare("SELECT bracket_order, min_amount, max_amount, rate FROM `statutory_item_brackets` WHERE statutory_item_rate_history_id = :id ORDER BY bracket_order ASC");
                $stmtBrackets->execute([':id' => $masterRow['id']]);
                $stmtInsBracket = $this->db->prepare("INSERT INTO `statutory_item_brackets`
                    (statutory_item_rate_history_id, bracket_order, min_amount, max_amount, rate) VALUES (:rh_id, :order, :min, :max, :rate)");
                foreach ($stmtBrackets->fetchAll(PDO::FETCH_ASSOC) as $b) {
                    $stmtInsBracket->execute([
                        ':rh_id' => $newVersionId, ':order' => $b['bracket_order'],
                        ':min' => $b['min_amount'], ':max' => $b['max_amount'], ':rate' => $b['rate'],
                    ]);
                }
            }
            // Create branch is not audited -- same convention save()'s own INSERT branch follows
            // (see that method's own comment).
            if ($ownTransaction) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Pulled from Master successfully.', 'id' => $newVersionId];
        } catch (PDOException $e) {
            if ($ownTransaction) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /**
     * Replaces the old `CompanyStatutorySettingModel::promoteOverrideToMaster()` -- takes ONE of
     * this company's own versions and writes it as a brand-new dated version onto the MASTER
     * item's own rate history (`comp_id IS NULL`). Unlike the old flow, the company's own version
     * is left completely untouched afterward -- under the versioned model there's nothing
     * contradictory about the company still holding its own explicit version even once Master
     * matches it (the Default/Customized badge on each version already tells the real story).
     * Gated by `tax_statutory.promote_master` at the controller layer, never the ordinary
     * `tax_statutory.edit` every company admin already has -- this affects every company at once.
     */
    public function promoteToMaster(int $compId, int $versionId, string $effectiveDate, int $userId): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveDate)) {
            return ['status' => false, 'message' => 'Invalid effective_date format.'];
        }
        $stmtVersion = $this->db->prepare("SELECT * FROM `statutory_item_rate_history` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtVersion->execute([':id' => $versionId, ':comp_id' => $compId]);
        $version = $stmtVersion->fetch(PDO::FETCH_ASSOC);
        if (!$version) {
            return ['status' => false, 'message' => 'Version not found or not owned by your company.'];
        }
        $item = $this->getOwnedMasterItem($compId, (int)$version['statutory_item_id']);
        if (!$item) {
            return ['status' => false, 'message' => 'Master statutory item not found.'];
        }
        $itemId = (int)$item['id'];

        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            $this->closeOpenRateVersion($itemId, null, $effectiveDate);
            $stmt = $this->db->prepare("INSERT INTO `statutory_item_rate_history`
                    (statutory_item_id, comp_id, source, effective_date, end_date, employee_rate, employer_rate, employee_amount, employer_amount,
                     min_base_amount, max_base_amount, max_employee_contribution, max_employer_contribution, formula_config, remark, created_by)
                VALUES
                    (:item_id, NULL, NULL, :effective_date, NULL, :employee_rate, :employer_rate, :employee_amount, :employer_amount,
                     :min_base_amount, :max_base_amount, :max_employee_contribution, :max_employer_contribution, :formula_config, :remark, :created_by)");
            $stmt->execute([
                ':item_id' => $itemId,
                ':effective_date' => $effectiveDate,
                ':employee_rate' => $version['employee_rate'],
                ':employer_rate' => $version['employer_rate'],
                ':employee_amount' => $version['employee_amount'],
                ':employer_amount' => $version['employer_amount'],
                ':min_base_amount' => $version['min_base_amount'],
                ':max_base_amount' => $version['max_base_amount'],
                ':max_employee_contribution' => $version['max_employee_contribution'],
                ':max_employer_contribution' => $version['max_employer_contribution'],
                ':formula_config' => $version['formula_config'],
                ':remark' => 'Promoted from comp_id=' . $compId . '\'s own version #' . $versionId,
                ':created_by' => $userId,
            ]);
            $newMasterRowId = (int)$this->db->lastInsertId();
            if ($item['calc_method'] === 'progressive_bracket') {
                $stmtBrackets = $this->db->prepare("SELECT bracket_order, min_amount, max_amount, rate FROM `statutory_item_brackets` WHERE statutory_item_rate_history_id = :id ORDER BY bracket_order ASC");
                $stmtBrackets->execute([':id' => $versionId]);
                $stmtInsBracket = $this->db->prepare("INSERT INTO `statutory_item_brackets`
                    (statutory_item_rate_history_id, bracket_order, min_amount, max_amount, rate) VALUES (:rh_id, :order, :min, :max, :rate)");
                foreach ($stmtBrackets->fetchAll(PDO::FETCH_ASSOC) as $b) {
                    $stmtInsBracket->execute([
                        ':rh_id' => $newMasterRowId, ':order' => $b['bracket_order'],
                        ':min' => $b['min_amount'], ':max' => $b['max_amount'], ':rate' => $b['rate'],
                    ]);
                }
            }
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

    /**
     * Clone-at-activation -- called once from `CompanyProfileModel::save()` on the draft->active
     * transition (same call site as `PayrollEarningDeductionTypeModel::seedDefaults()`). Loops
     * every active Master item in the company's own country and, for each one that has a
     * currently-effective version, inserts a `source='master_clone'` copy scoped to this company
     * -- same SQL shape as the migration's own one-time backfill (database/migrations/2026-09-08_1_
     * statutory_company_rate_versions.sql step 3), kept in ONE place here so the migration's own
     * backfill and this ongoing per-activation hook can never drift apart. Silently skips a Master
     * item that has no currently-effective version at all (nothing to clone) and silently skips
     * (via `INSERT ... WHERE NOT EXISTS`) any item this company somehow already has a comp_id-
     * scoped row for -- safe to call more than once for the same company without creating
     * duplicates.
     */
    public function cloneMasterForCompany(int $compId, string $countryCode, ?int $userId = null): void {
        $sql = "INSERT INTO `statutory_item_rate_history`
                (statutory_item_id, comp_id, source, effective_date, end_date,
                 employee_rate, employer_rate, employee_amount, employer_amount,
                 min_base_amount, max_base_amount, max_employee_contribution, max_employer_contribution,
                 formula_config, remark, created_by)
            SELECT
                si.id, :comp_id, 'master_clone', CURDATE(), NULL,
                mrh.employee_rate, mrh.employer_rate, mrh.employee_amount, mrh.employer_amount,
                mrh.min_base_amount, mrh.max_base_amount, mrh.max_employee_contribution, mrh.max_employer_contribution,
                mrh.formula_config, 'Cloned from master at company activation', :created_by
            FROM `statutory_items` si
            JOIN `statutory_item_rate_history` mrh ON mrh.statutory_item_id = si.id AND mrh.comp_id IS NULL AND mrh.deleted_at IS NULL
                AND mrh.effective_date <= CURDATE() AND (mrh.end_date IS NULL OR mrh.end_date >= CURDATE())
            WHERE si.country_code = :country_code AND si.comp_id IS NULL AND si.deleted_at IS NULL AND si.status = 'active'
                AND NOT EXISTS (
                    SELECT 1 FROM `statutory_item_rate_history` existing
                    WHERE existing.statutory_item_id = si.id AND existing.comp_id = :comp_id2 AND existing.deleted_at IS NULL
                )";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':comp_id' => $compId, ':created_by' => $userId, ':country_code' => $countryCode, ':comp_id2' => $compId]);

        // Progressive-bracket items also need their bracket rows cloned (statutory_item_brackets
        // hangs off the SOURCE Master rate_history_id, not the new company-scoped one -- has to
        // be copied per newly-inserted row, one INSERT per source can't carry these along).
        $stmtNewClones = $this->db->prepare("SELECT crh.id AS new_id, mrh.id AS source_id
            FROM `statutory_item_rate_history` crh
            JOIN `statutory_items` si ON si.id = crh.statutory_item_id
            JOIN `statutory_item_rate_history` mrh ON mrh.statutory_item_id = si.id AND mrh.comp_id IS NULL AND mrh.deleted_at IS NULL
                AND mrh.effective_date <= CURDATE() AND (mrh.end_date IS NULL OR mrh.end_date >= CURDATE())
            WHERE crh.comp_id = :comp_id AND si.calc_method = 'progressive_bracket' AND crh.source = 'master_clone'
                AND crh.remark = 'Cloned from master at company activation'");
        $stmtNewClones->execute([':comp_id' => $compId]);
        $pairs = $stmtNewClones->fetchAll(PDO::FETCH_ASSOC);
        if ($pairs) {
            $stmtInsBracket = $this->db->prepare("INSERT INTO `statutory_item_brackets`
                (statutory_item_rate_history_id, bracket_order, min_amount, max_amount, rate)
                SELECT :new_id, bracket_order, min_amount, max_amount, rate FROM `statutory_item_brackets`
                WHERE statutory_item_rate_history_id = :source_id");
            foreach ($pairs as $pair) {
                $stmtInsBracket->execute([':new_id' => $pair['new_id'], ':source_id' => $pair['source_id']]);
            }
        }
    }
}
