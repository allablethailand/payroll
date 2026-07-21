<?php
declare(strict_types=1);
class PayrollEarningDeductionTypeModel {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }

    public function list(int $compId, int $start, int $length, string $itemType, string $search, int $colIndex, string $orderDir): array {
        $sortColumns = [
            0 => '`item_code`',
            1 => '`item_name_th`',
            2 => '`calculation_method`',
            3 => '`status`',
        ];
        $sortColumn = $sortColumns[$colIndex] ?? $sortColumns[0];
        $orderDir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';

        $baseWhere = "comp_id = :comp_id AND deleted_at IS NULL AND item_type = :item_type";
        $params = [':comp_id' => $compId, ':item_type' => $itemType];

        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `payroll_earning_deduction_types` WHERE {$baseWhere}");
        $totalStmt->execute($params);
        $recordsTotal = (int)$totalStmt->fetchColumn();

        $whereSql = $baseWhere;
        if ($search !== '') {
            $whereSql .= " AND (item_code LIKE :search1 OR item_name_th LIKE :search2 OR item_name_en LIKE :search3)";
            $params[':search1'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
            $params[':search3'] = "%{$search}%";
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `payroll_earning_deduction_types` WHERE {$whereSql}");
        $countStmt->execute($params);
        $recordsFiltered = (int)$countStmt->fetchColumn();

        $dataSql = "SELECT t.*, se.name_th AS source_event_name_th, se.name_en AS source_event_name_en
                     FROM `payroll_earning_deduction_types` t
                     LEFT JOIN `master_payroll_source_events` se ON t.source_event_code = se.code
                     WHERE {$whereSql} ORDER BY {$sortColumn} {$orderDir} LIMIT :limit OFFSET :offset";
        $dataStmt = $this->db->prepare($dataSql);
        foreach ($params as $key => $val) {
            $dataStmt->bindValue($key, $val);
        }
        $dataStmt->bindValue(':limit', $length, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $start, PDO::PARAM_INT);
        $dataStmt->execute();

        return [
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $dataStmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function get(int $compId, int $id): ?array {
        $stmt = $this->db->prepare("SELECT t.*, se.name_th AS source_event_name_th, se.name_en AS source_event_name_en
                                     FROM `payroll_earning_deduction_types` t
                                     LEFT JOIN `master_payroll_source_events` se ON t.source_event_code = se.code
                                     WHERE t.id = :id AND t.comp_id = :comp_id AND t.deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function sourceEventOptions(string $itemType, string $search, int $page, int $limit): array {
        $offset = ($page - 1) * $limit;
        $where = "WHERE is_active = 1 AND (applies_to = 'both'" . ($itemType !== '' ? " OR applies_to = :item_type" : "") . ")";
        $params = [];
        if ($itemType !== '') {
            $params[':item_type'] = $itemType;
        }
        if ($search !== '') {
            $where .= " AND (name_th LIKE :search1 OR name_en LIKE :search2)";
            $params[':search1'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
        }
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `master_payroll_source_events` {$where}");
        $totalStmt->execute($params);
        $totalCount = (int)$totalStmt->fetchColumn();

        $sql = "SELECT code AS id, name_th AS text_th, name_en AS text_en FROM `master_payroll_source_events` {$where} ORDER BY sort_order ASC LIMIT :offset, :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total_count' => $totalCount];
    }

    private function isItemCodeDuplicate(int $compId, string $itemCode, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM `payroll_earning_deduction_types` WHERE comp_id = :comp_id AND item_code = :item_code AND deleted_at IS NULL";
        $params = [':comp_id' => $compId, ':item_code' => $itemCode];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function save(int $compId, array $data, int $userId): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        foreach (['item_code', 'item_name_th', 'item_name_en', 'item_type', 'calculation_method'] as $field) {
            if (empty($data[$field])) {
                return ['status' => false, 'message' => "Missing required field: {$field}"];
            }
        }

        $itemCode = trim((string)$data['item_code']);
        if (!preg_match('/^[A-Za-z0-9_-]{2,20}$/', $itemCode)) {
            return ['status' => false, 'message' => 'Item code must be 2-20 characters (letters, numbers, - or _ only).'];
        }
        if ($this->isItemCodeDuplicate($compId, $itemCode, $id)) {
            return ['status' => false, 'message' => 'This item code is already in use.'];
        }

        $itemType = (string)$data['item_type'];
        if (!in_array($itemType, ['earning', 'deduction'], true)) {
            return ['status' => false, 'message' => 'Invalid item_type.'];
        }

        $calcMethod = (string)$data['calculation_method'];
        if (!in_array($calcMethod, ['fixed_amount', 'percent_of_base_salary', 'manual_entry'], true)) {
            return ['status' => false, 'message' => 'Invalid calculation_method.'];
        }

        $fixedAmount = null;
        $percentRate = null;
        if ($calcMethod === 'fixed_amount') {
            if (!isset($data['fixed_amount']) || $data['fixed_amount'] === '' || !is_numeric($data['fixed_amount'])) {
                return ['status' => false, 'message' => 'Missing required field: fixed_amount'];
            }
            $fixedAmount = (float)$data['fixed_amount'];
        } elseif ($calcMethod === 'percent_of_base_salary') {
            if (!isset($data['percent_rate']) || $data['percent_rate'] === '' || !is_numeric($data['percent_rate'])) {
                return ['status' => false, 'message' => 'Missing required field: percent_rate'];
            }
            $percentRate = (float)$data['percent_rate'];
        }

        $taxTreatment = null;
        $taxDeductionImpact = null;
        if ($itemType === 'earning') {
            $taxTreatmentInput = $data['tax_treatment'] ?? '';
            if (!in_array($taxTreatmentInput, ['taxable', 'non_taxable'], true)) {
                return ['status' => false, 'message' => 'Missing required field: tax_treatment'];
            }
            $taxTreatment = $taxTreatmentInput;
        } else {
            $taxImpactInput = $data['tax_deduction_impact'] ?? '';
            if (!in_array($taxImpactInput, ['before_tax', 'after_tax'], true)) {
                return ['status' => false, 'message' => 'Missing required field: tax_deduction_impact'];
            }
            $taxDeductionImpact = $taxImpactInput;
        }

        $countryCode = null;
        if (!empty($data['country_code'])) {
            $countryCode = strtoupper(trim((string)$data['country_code']));
            if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
                return ['status' => false, 'message' => 'Invalid country_code.'];
            }
        }

        $sourceEventCode = null;
        if (!empty($data['source_event_code'])) {
            $sourceEventCode = trim((string)$data['source_event_code']);
            $stmtEvent = $this->db->prepare("SELECT applies_to FROM `master_payroll_source_events` WHERE code = :code AND is_active = 1");
            $stmtEvent->execute([':code' => $sourceEventCode]);
            $eventRow = $stmtEvent->fetch(PDO::FETCH_ASSOC);
            if (!$eventRow) {
                return ['status' => false, 'message' => 'Invalid source_event_code.'];
            }
            if ($eventRow['applies_to'] !== 'both' && $eventRow['applies_to'] !== $itemType) {
                return ['status' => false, 'message' => 'This linked event does not apply to the selected item type.'];
            }
        }

        $calcSso = !empty($data['calc_sso']) ? 1 : 0;
        $calcPf = !empty($data['calc_pf']) ? 1 : 0;
        $statusInput = $data['status'] ?? 'active';
        $status = in_array($statusInput, ['active', 'inactive'], true) ? $statusInput : 'active';
        $itemNameTh = trim((string)$data['item_name_th']);
        $itemNameEn = trim((string)$data['item_name_en']);

        $params = [
            ':item_code' => $itemCode,
            ':item_name_th' => $itemNameTh,
            ':item_name_en' => $itemNameEn,
            ':item_type' => $itemType,
            ':calculation_method' => $calcMethod,
            ':fixed_amount' => $fixedAmount,
            ':percent_rate' => $percentRate,
            ':tax_treatment' => $taxTreatment,
            ':tax_deduction_impact' => $taxDeductionImpact,
            ':calc_sso' => $calcSso,
            ':calc_pf' => $calcPf,
            ':country_code' => $countryCode,
            ':source_event_code' => $sourceEventCode,
            ':status' => $status,
        ];

        try {
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id, is_sync_only FROM `payroll_earning_deduction_types` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                if ((int)$existing['is_sync_only'] === 1) {
                    return ['status' => false, 'message' => 'This item is managed by system sync and cannot be edited manually.'];
                }
                $sql = "UPDATE `payroll_earning_deduction_types` SET
                            item_code = :item_code, item_name_th = :item_name_th, item_name_en = :item_name_en,
                            item_type = :item_type, calculation_method = :calculation_method,
                            fixed_amount = :fixed_amount, percent_rate = :percent_rate,
                            tax_treatment = :tax_treatment, tax_deduction_impact = :tax_deduction_impact,
                            calc_sso = :calc_sso, calc_pf = :calc_pf, country_code = :country_code,
                            source_event_code = :source_event_code,
                            status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
                $params[':updated_by'] = $userId;
                $params[':id'] = $id;
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id];
            }

            $sql = "INSERT INTO `payroll_earning_deduction_types`
                        (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method,
                         fixed_amount, percent_rate, tax_treatment, tax_deduction_impact, calc_sso, calc_pf,
                         country_code, source_event_code, is_sync_only, status, created_by)
                    VALUES
                        (:comp_id, :item_code, :item_name_th, :item_name_en, :item_type, :calculation_method,
                         :fixed_amount, :percent_rate, :tax_treatment, :tax_deduction_impact, :calc_sso, :calc_pf,
                         :country_code, :source_event_code, 0, :status, :created_by)";
            $params[':comp_id'] = $compId;
            $params[':created_by'] = $userId;
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return ['status' => true, 'message' => 'Created successfully.', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function delete(int $compId, int $id, int $userId): array {
        try {
            $stmtCheck = $this->db->prepare("SELECT id, is_sync_only FROM `payroll_earning_deduction_types` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
            $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                return ['status' => false, 'message' => 'Record not found.'];
            }
            if ((int)$existing['is_sync_only'] === 1) {
                return ['status' => false, 'message' => 'This item is managed by system sync and cannot be deleted manually.'];
            }
            $stmt = $this->db->prepare("UPDATE `payroll_earning_deduction_types` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
            return ['status' => true, 'message' => 'Deleted successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }
}
