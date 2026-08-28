<?php
declare(strict_types=1);
class CompanyStatutorySettingModel {
    private $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    private function getCompanyCountry(int $compId): ?string {
        $stmt = $this->db->prepare("SELECT registered_country FROM `companies` WHERE id = :id");
        $stmt->execute([':id' => $compId]);
        $country = $stmt->fetchColumn();
        return $country !== false ? (string)$country : null;
    }

    public function list(int $compId): array {
        $countryCode = $this->getCompanyCountry($compId);
        if ($countryCode === null) {
            return [];
        }
        // "Last edited" (2026-08-28, explicit request) applies only to a company's OWN override row
        // (css.*) -- a statutory item still on system default has never been edited BY this
        // company, so last_edited_* stays null there rather than borrowing the master item's own
        // edit time (that would misleadingly read as "this company changed something").
        $sql = "SELECT si.id AS statutory_item_id, si.code, si.name_th, si.name_en, si.category, si.calc_method, si.calc_base,
                    si.is_employee_applicable, si.is_employer_applicable, si.default_is_active, si.is_company_rate_editable,
                    rh.employee_rate AS master_employee_rate, rh.employer_rate AS master_employer_rate,
                    rh.employee_amount AS master_employee_amount, rh.employer_amount AS master_employer_amount,
                    css.id AS setting_id, css.status AS setting_status,
                    css.employee_rate_override, css.employer_rate_override,
                    css.employee_amount_override, css.employer_amount_override, css.remark,
                    css.updated_at AS last_edited_at, editor.name_th AS last_edited_by_name_th, editor.name_en AS last_edited_by_name_en
                FROM `statutory_items` si
                LEFT JOIN `statutory_item_rate_history` rh ON rh.id = (
                    SELECT id FROM `statutory_item_rate_history`
                    WHERE statutory_item_id = si.id AND deleted_at IS NULL
                    ORDER BY effective_date DESC, id DESC LIMIT 1
                )
                LEFT JOIN `company_statutory_settings` css ON css.statutory_item_id = si.id AND css.comp_id = :comp_id AND css.deleted_at IS NULL
                LEFT JOIN `employees` editor ON editor.id = COALESCE(css.updated_by, css.created_by)
                WHERE si.deleted_at IS NULL AND si.status = 'active' AND si.country_code = :country_code
                ORDER BY si.sort_order ASC, si.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':comp_id' => $compId, ':country_code' => $countryCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['effective_status'] = $row['setting_id'] !== null ? $row['setting_status'] : (((int)$row['default_is_active']) === 1 ? 'active' : 'inactive');
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

    public function save(int $compId, array $data, int $userId): array {
        if (empty($data['statutory_item_id']) || !is_numeric($data['statutory_item_id'])) {
            return ['status' => false, 'message' => 'Missing required field: statutory_item_id'];
        }
        $itemId = (int)$data['statutory_item_id'];

        $countryCode = $this->getCompanyCountry($compId);
        if ($countryCode === null) {
            return ['status' => false, 'message' => 'Company not found.'];
        }

        $stmtItem = $this->db->prepare("SELECT * FROM `statutory_items` WHERE id = :id AND deleted_at IS NULL AND status = 'active'");
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
            $stmtExisting = $this->db->prepare("SELECT id FROM `company_statutory_settings` WHERE comp_id = :comp_id AND statutory_item_id = :item_id");
            $stmtExisting->execute([':comp_id' => $compId, ':item_id' => $itemId]);
            $existingId = $stmtExisting->fetchColumn();

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

    public function reset(int $compId, int $itemId, int $userId): array {
        try {
            $stmtCheck = $this->db->prepare("SELECT id FROM `company_statutory_settings` WHERE comp_id = :comp_id AND statutory_item_id = :item_id AND deleted_at IS NULL");
            $stmtCheck->execute([':comp_id' => $compId, ':item_id' => $itemId]);
            $id = $stmtCheck->fetchColumn();
            if (!$id) {
                return ['status' => false, 'message' => 'Record not found.'];
            }
            $stmt = $this->db->prepare("UPDATE `company_statutory_settings` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
            return ['status' => true, 'message' => 'Reset to system default.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }
}
