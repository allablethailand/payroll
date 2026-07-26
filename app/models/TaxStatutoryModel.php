<?php
declare(strict_types=1);
class TaxStatutoryModel {
    private $db;
    private const CATEGORIES = ['tax', 'social_insurance', 'provident_fund', 'other'];
    private const CALC_METHODS = ['flat_rate', 'progressive_bracket', 'fixed_amount', 'formula'];
    private const CALC_BASES = ['basic_salary', 'gross_salary', 'taxable_income', 'net_income', 'custom'];

    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }

    public function list(string $countryCode = ''): array {
        $sql = "SELECT si.*, mc.countries_name_th, mc.countries_name_en,
                    rh.id AS current_rate_id, rh.effective_date AS current_effective_date,
                    rh.employee_rate, rh.employer_rate, rh.employee_amount, rh.employer_amount
                FROM `statutory_items` si
                LEFT JOIN `master_countries` mc ON mc.countries_code = si.country_code
                LEFT JOIN `statutory_item_rate_history` rh ON rh.id = (
                    SELECT id FROM `statutory_item_rate_history`
                    WHERE statutory_item_id = si.id AND deleted_at IS NULL
                    ORDER BY effective_date DESC, id DESC LIMIT 1
                )
                WHERE si.deleted_at IS NULL";
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

    private function isCodeDuplicate(string $countryCode, string $code, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM `statutory_items` WHERE country_code = :country_code AND code = :code AND deleted_at IS NULL";
        $params = [':country_code' => $countryCode, ':code' => $code];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function save(array $data, int $userId): array {
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
        if ($this->isCodeDuplicate($countryCode, $code, $id)) {
            return ['status' => false, 'message' => 'This code is already in use for the selected country.'];
        }

        $category = (string)$data['category'];
        if (!in_array($category, self::CATEGORIES, true)) {
            return ['status' => false, 'message' => 'Invalid category.'];
        }

        $calcMethod = (string)$data['calc_method'];
        if (!in_array($calcMethod, self::CALC_METHODS, true)) {
            return ['status' => false, 'message' => 'Invalid calc_method.'];
        }

        $calcBase = (string)$data['calc_base'];
        if (!in_array($calcBase, self::CALC_BASES, true)) {
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

        $statusInput = $data['status'] ?? 'active';
        $status = in_array($statusInput, ['active', 'inactive'], true) ? $statusInput : 'active';

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
        ];

        try {
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM `statutory_items` WHERE id = :id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id]);
                if (!$stmtCheck->fetch()) {
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $sql = "UPDATE `statutory_items` SET
                            country_code = :country_code, code = :code, name_th = :name_th, name_en = :name_en,
                            category = :category, calc_method = :calc_method, calc_base = :calc_base,
                            is_employee_applicable = :is_employee_applicable, is_employer_applicable = :is_employer_applicable,
                            default_is_active = :default_is_active, is_company_rate_editable = :is_company_rate_editable,
                            sort_order = :sort_order, status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
                $params[':updated_by'] = $userId;
                $params[':id'] = $id;
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id];
            }

            $sql = "INSERT INTO `statutory_items`
                        (country_code, code, name_th, name_en, category, calc_method, calc_base,
                         is_employee_applicable, is_employer_applicable, default_is_active, is_company_rate_editable,
                         sort_order, status, created_by)
                    VALUES
                        (:country_code, :code, :name_th, :name_en, :category, :calc_method, :calc_base,
                         :is_employee_applicable, :is_employer_applicable, :default_is_active, :is_company_rate_editable,
                         :sort_order, :status, :created_by)";
            $params[':created_by'] = $userId;
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return ['status' => true, 'message' => 'Created successfully.', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function delete(int $id, int $userId): array {
        try {
            $stmtCheck = $this->db->prepare("SELECT id FROM `statutory_items` WHERE id = :id AND deleted_at IS NULL");
            $stmtCheck->execute([':id' => $id]);
            if (!$stmtCheck->fetch()) {
                return ['status' => false, 'message' => 'Record not found.'];
            }
            $stmt = $this->db->prepare("UPDATE `statutory_items` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
            return ['status' => true, 'message' => 'Deleted successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function rateHistoryList(int $itemId): array {
        $sql = "SELECT rh.*,
                    (SELECT COUNT(*) FROM `statutory_item_brackets` WHERE statutory_item_rate_history_id = rh.id) AS bracket_count
                FROM `statutory_item_rate_history` rh
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

    public function rateHistorySave(array $data, int $userId): array {
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
                $stmtCheck = $this->db->prepare("SELECT id FROM `statutory_item_rate_history` WHERE id = :id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id]);
                if (!$stmtCheck->fetch()) {
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
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                $rateHistoryId = $id;
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

    public function rateHistoryDelete(int $id, int $userId): array {
        try {
            $stmtCheck = $this->db->prepare("SELECT id FROM `statutory_item_rate_history` WHERE id = :id AND deleted_at IS NULL");
            $stmtCheck->execute([':id' => $id]);
            if (!$stmtCheck->fetch()) {
                return ['status' => false, 'message' => 'Record not found.'];
            }
            $stmt = $this->db->prepare("UPDATE `statutory_item_rate_history` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
            return ['status' => true, 'message' => 'Deleted successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }
}
