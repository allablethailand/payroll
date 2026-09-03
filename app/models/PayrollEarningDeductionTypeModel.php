<?php
declare(strict_types=1);
require_once __DIR__ . '/AuditLogModel.php';
class PayrollEarningDeductionTypeModel {
    private $db;
    private AuditLogModel $auditLog;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
        $this->auditLog = new AuditLogModel($this->db);
    }

    /** Frontend column KEY -> real SQL column for the Excel-style column filter (2026-08-27
     *  rollout) -- shared across both Earning and Deduction tabs (same underlying table, just
     *  scoped by item_type), each requesting only the keys relevant to it. `item_name` is
     *  `$lang`-resolved the same way EmployeeModel::listColumnExprMap() does. Excludes the
     *  composite item-name+tags cell (no single real column), the boolean calc_sso/calc_pf icons,
     *  and the actions column -- same exclusion policy as every other table in this rollout. */
    private function columnFilterExprMap(string $lang): array {
        return [
            'item_code' => 'item_code',
            'item_name' => $lang === 'en' ? 'item_name_en' : 'item_name_th',
            'calculation_method' => 'calculation_method',
            'tax_treatment' => 'tax_treatment',
            'tax_deduction_impact' => 'tax_deduction_impact',
            'status' => 'status',
        ];
    }

    private function applyColumnFilters(string $whereSql, array &$params, array $columnFilters, array $exprMap, ?string $excludeColumn = null): string {
        $paramIdx = 0;
        foreach ($columnFilters as $col => $values) {
            if ($col === $excludeColumn || !isset($exprMap[$col]) || !is_array($values) || empty($values)) {
                continue;
            }
            $values = array_values(array_filter($values, fn($v) => $v !== null && $v !== ''));
            if (empty($values)) {
                continue;
            }
            $placeholders = [];
            foreach ($values as $v) {
                $paramIdx++;
                $ph = ":cf{$paramIdx}";
                $placeholders[] = $ph;
                $params[$ph] = (string)$v;
            }
            $whereSql .= " AND `{$exprMap[$col]}` IN (" . implode(', ', $placeholders) . ")";
        }
        return $whereSql;
    }

    public function list(int $compId, int $start, int $length, string $itemType, string $search, int $colIndex, string $orderDir, string $lang = 'th', array $columnFilters = []): array {
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
        // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter rollout.
        $whereSql = $this->applyColumnFilters($whereSql, $params, $columnFilters, $this->columnFilterExprMap($lang));

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

    /** Distinct values for ONE column of the Earning/Deduction Type list, scoped to the SAME
     *  item_type as the tab it's opened from (a distinct value only valid for Earning rows has no
     *  business appearing in Deduction's own filter dropdown), respecting every OTHER active
     *  Excel-style column filter but not this column's own selection -- see
     *  EmployeeModel::listColumnValues()'s own docblock for why. */
    public function columnDistinctValues(int $compId, string $itemType, string $column, string $lang, array $columnFilters, ?string $excludeColumn): array {
        $exprMap = $this->columnFilterExprMap($lang);
        if (!isset($exprMap[$column])) {
            return [];
        }
        $expr = $exprMap[$column];
        $where = "comp_id = :comp_id AND deleted_at IS NULL AND item_type = :item_type";
        $params = [':comp_id' => $compId, ':item_type' => $itemType];
        $where = $this->applyColumnFilters($where, $params, $columnFilters, $exprMap, $excludeColumn);
        $sql = "SELECT DISTINCT `{$expr}` AS value FROM `payroll_earning_deduction_types`
                WHERE {$where} AND `{$expr}` IS NOT NULL AND `{$expr}` != ''
                ORDER BY value ASC LIMIT 500";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'value');
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

    /** @return string[] item_code values of this company's active deduction types tagged with the given statutory_report_code */
    public function itemCodesByStatutoryReportCode(int $compId, string $statutoryReportCode): array {
        $stmt = $this->db->prepare("SELECT item_code FROM `payroll_earning_deduction_types`
            WHERE comp_id = :comp_id AND item_type = 'deduction' AND statutory_report_code = :code
            AND status = 'active' AND deleted_at IS NULL");
        $stmt->execute([':comp_id' => $compId, ':code' => $statutoryReportCode]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'item_code');
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

    public function save(int $compId, array $data, int $userId, ?string $ip = null, ?string $userAgent = null): array {
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

        // Tags this deduction type as feeding a known statutory report (e.g. TH_SLF = กยศ.),
        // so a report generator can find "whichever deduction type the company set up for this"
        // without guessing by item_code/name. Earning-only concept; whitelist kept small and
        // explicit rather than a lookup table since there is currently exactly one such report.
        $statutoryReportCode = null;
        if (!empty($data['statutory_report_code'])) {
            if ($itemType !== 'deduction') {
                return ['status' => false, 'message' => 'statutory_report_code only applies to deduction items.'];
            }
            $statutoryReportCode = trim((string)$data['statutory_report_code']);
            if (!in_array($statutoryReportCode, ['TH_SLF'], true)) {
                return ['status' => false, 'message' => 'Invalid statutory_report_code.'];
            }
        }

        $calcSso = !empty($data['calc_sso']) ? 1 : 0;
        $calcPf = !empty($data['calc_pf']) ? 1 : 0;
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
            ':statutory_report_code' => $statutoryReportCode,
        ];

        try {
            if ($id !== null) {
                // Platform Hardening Phase 6 pilot: SELECT * (not just id/is_sync_only/status) so the
                // full row is available to AuditLogModel::record() as the "old" side of the diff below.
                $stmtCheck = $this->db->prepare("SELECT * FROM `payroll_earning_deduction_types` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                if (!$existing) {
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                if ((int)$existing['is_sync_only'] === 1) {
                    return ['status' => false, 'message' => 'This item is managed by system sync and cannot be edited manually.'];
                }
                // 2026-08-30 (Phase 2, T014, explicit request: "ย้าย 'สถานะ' ออกจาก modal ไปไว้ที่แถวใน
                // ตาราง") -- the Add/Edit modal no longer has a Status field at all (status changes
                // ONLY through the new dedicated toggleStatus() below now) -- an UPDATE must therefore
                // preserve whatever status the row already had rather than defaulting to 'active' the
                // way `$data['status'] ?? 'active'` used to (that default is still correct, and still
                // used, for a brand-new row further down -- see the INSERT branch).
                $statusInput = $data['status'] ?? $existing['status'];
                $params[':status'] = in_array($statusInput, ['active', 'inactive'], true) ? $statusInput : $existing['status'];
                $sql = "UPDATE `payroll_earning_deduction_types` SET
                            item_code = :item_code, item_name_th = :item_name_th, item_name_en = :item_name_en,
                            item_type = :item_type, calculation_method = :calculation_method,
                            fixed_amount = :fixed_amount, percent_rate = :percent_rate,
                            tax_treatment = :tax_treatment, tax_deduction_impact = :tax_deduction_impact,
                            calc_sso = :calc_sso, calc_pf = :calc_pf, country_code = :country_code,
                            source_event_code = :source_event_code, statutory_report_code = :statutory_report_code,
                            status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
                $params[':updated_by'] = $userId;
                $params[':id'] = $id;
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                $stmtNewRow = $this->db->prepare("SELECT * FROM `payroll_earning_deduction_types` WHERE id = :id");
                $stmtNewRow->execute([':id' => $id]);
                $newRow = $stmtNewRow->fetch(PDO::FETCH_ASSOC) ?: [];
                $this->auditLog->record($compId, 'payroll_earning_deduction_types', $id, 'update', $existing, $newRow, $userId, 'web', $ip, $userAgent);
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id];
            }

            $sql = "INSERT INTO `payroll_earning_deduction_types`
                        (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method,
                         fixed_amount, percent_rate, tax_treatment, tax_deduction_impact, calc_sso, calc_pf,
                         country_code, source_event_code, statutory_report_code, is_sync_only, status, created_by)
                    VALUES
                        (:comp_id, :item_code, :item_name_th, :item_name_en, :item_type, :calculation_method,
                         :fixed_amount, :percent_rate, :tax_treatment, :tax_deduction_impact, :calc_sso, :calc_pf,
                         :country_code, :source_event_code, :statutory_report_code, 0, :status, :created_by)";
            $statusInput = $data['status'] ?? 'active';
            $params[':status'] = in_array($statusInput, ['active', 'inactive'], true) ? $statusInput : 'active';
            $params[':comp_id'] = $compId;
            $params[':created_by'] = $userId;
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return ['status' => true, 'message' => 'Created successfully.', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /**
     * A starter set of commonly-used/necessary earning & deduction items (per explicit request,
     * 2026-08-19: "give the system defaults, widely-used + necessary ones, admin can add more or
     * delete them -- but all soft delete"). Deletable like any other row here -- nothing about a
     * seeded row is special/protected (is_sync_only stays 0) -- these are just a starting point,
     * not a fixed system requirement. Idempotent: skips any item_code the company already has
     * (active OR soft-deleted -- item_code stays reserved per the deleted_at-composite-unique
     * caveat this project always double-checks at the application layer, same as everywhere else),
     * so calling this again (e.g. re-clicking "Load Default Items") never creates duplicates or
     * resurrects something an admin deliberately deleted.
     * @return array{inserted:int,skipped:int}
     */
    public function seedDefaults(int $compId, ?int $userId): array {
        $defaults = [
            ['item_code' => 'OT', 'item_name_th' => 'ค่าล่วงเวลา', 'item_name_en' => 'Overtime Pay', 'item_type' => 'earning', 'calculation_method' => 'manual_entry', 'tax_treatment' => 'taxable', 'calc_sso' => 1, 'calc_pf' => 1, 'source_event_code' => 'ot_hours'],
            ['item_code' => 'TRIP_ALLOW', 'item_name_th' => 'ค่าเที่ยว', 'item_name_en' => 'Trip Allowance', 'item_type' => 'earning', 'calculation_method' => 'manual_entry', 'tax_treatment' => 'taxable', 'source_event_code' => 'trip_allowance'],
            ['item_code' => 'POSITION_ALLOW', 'item_name_th' => 'ค่าตำแหน่ง', 'item_name_en' => 'Position Allowance', 'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 0, 'tax_treatment' => 'taxable', 'calc_sso' => 1, 'calc_pf' => 1],
            // 2026-08-30 (Phase 2, T011): promoted from a plain fixed_amount=0 manual item to a
            // proper source_event_code link, same treatment as OT/Trip Allowance above -- the
            // amount was NEVER actually read from fixed_amount here (that column only matters for a
            // manual per-employee assignment, which this item was never meant to have -- see
            // PayrollRunModel.php's own 2026-08-29 comment on this same item, "จะเชื่อมมาจาก Origami
            // แทน"), so fixed_amount=0 was silently inert either way -- this just makes the wiring
            // explicit/discoverable via the "Linked Attendance Event" dropdown instead of relying on
            // SyncPayResolver's generic item_code fallback matching. See
            // database/migrations/2026-08-30_11b_diligence_source_event_backfill.sql for the
            // existing-row backfill this required.
            ['item_code' => 'DILIGENCE', 'item_name_th' => 'เบี้ยขยัน', 'item_name_en' => 'Diligence Allowance', 'item_type' => 'earning', 'calculation_method' => 'manual_entry', 'tax_treatment' => 'taxable', 'calc_sso' => 1, 'calc_pf' => 1, 'source_event_code' => 'diligence'],
            ['item_code' => 'MEAL_ALLOW', 'item_name_th' => 'ค่าอาหาร', 'item_name_en' => 'Meal Allowance', 'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 0, 'tax_treatment' => 'non_taxable'],
            ['item_code' => 'PHONE_ALLOW', 'item_name_th' => 'ค่าโทรศัพท์', 'item_name_en' => 'Phone Allowance', 'item_type' => 'earning', 'calculation_method' => 'fixed_amount', 'fixed_amount' => 0, 'tax_treatment' => 'taxable'],
            ['item_code' => 'BONUS', 'item_name_th' => 'โบนัส', 'item_name_en' => 'Bonus', 'item_type' => 'earning', 'calculation_method' => 'manual_entry', 'tax_treatment' => 'taxable'],
            ['item_code' => 'COMMISSION', 'item_name_th' => 'ค่าคอมมิชชั่น', 'item_name_en' => 'Commission', 'item_type' => 'earning', 'calculation_method' => 'manual_entry', 'tax_treatment' => 'taxable', 'calc_sso' => 1, 'calc_pf' => 1],
            ['item_code' => 'LATE_DEDUCT', 'item_name_th' => 'หักมาสาย', 'item_name_en' => 'Late Deduction', 'item_type' => 'deduction', 'calculation_method' => 'manual_entry', 'tax_deduction_impact' => 'before_tax', 'source_event_code' => 'late'],
            ['item_code' => 'ABSENT_DEDUCT', 'item_name_th' => 'หักขาดงาน', 'item_name_en' => 'Absence Deduction', 'item_type' => 'deduction', 'calculation_method' => 'manual_entry', 'tax_deduction_impact' => 'before_tax', 'source_event_code' => 'absent'],
            ['item_code' => 'LEAVE_NO_PAY_DEDUCT', 'item_name_th' => 'หักลาไม่รับเงินเดือน', 'item_name_en' => 'Unpaid Leave Deduction', 'item_type' => 'deduction', 'calculation_method' => 'manual_entry', 'tax_deduction_impact' => 'before_tax', 'source_event_code' => 'unpaid_leave'],
            ['item_code' => 'LEAVE_PENDING_DEDUCT', 'item_name_th' => 'หักลารออนุมัติ', 'item_name_en' => 'Pending Leave Deduction', 'item_type' => 'deduction', 'calculation_method' => 'manual_entry', 'tax_deduction_impact' => 'before_tax', 'source_event_code' => 'leave_pending'],
            ['item_code' => 'LOAN_REPAY', 'item_name_th' => 'หักเงินกู้ยืมพนักงาน', 'item_name_en' => 'Loan Repayment', 'item_type' => 'deduction', 'calculation_method' => 'manual_entry', 'tax_deduction_impact' => 'after_tax'],
            ['item_code' => 'STUDENT_LOAN', 'item_name_th' => 'หักเงินกู้ยืม กยศ.', 'item_name_en' => 'Student Loan (SLF)', 'item_type' => 'deduction', 'calculation_method' => 'manual_entry', 'tax_deduction_impact' => 'before_tax', 'statutory_report_code' => 'TH_SLF'],
            ['item_code' => 'UNIFORM_DEDUCT', 'item_name_th' => 'หักค่าเครื่องแบบ', 'item_name_en' => 'Uniform Deduction', 'item_type' => 'deduction', 'calculation_method' => 'manual_entry', 'tax_deduction_impact' => 'after_tax'],
        ];

        $existingStmt = $this->db->prepare("SELECT item_code FROM `payroll_earning_deduction_types` WHERE comp_id = :comp_id");
        $existingStmt->execute([':comp_id' => $compId]);
        $existingCodes = array_map('strtoupper', array_column($existingStmt->fetchAll(PDO::FETCH_ASSOC), 'item_code'));

        $inserted = 0;
        $skipped = 0;
        foreach ($defaults as $item) {
            if (in_array(strtoupper($item['item_code']), $existingCodes, true)) {
                $skipped++;
                continue;
            }
            $res = $this->save($compId, $item, (int)$userId);
            if (!empty($res['status'])) {
                $inserted++;
            } else {
                $skipped++;
            }
        }
        return ['inserted' => $inserted, 'skipped' => $skipped];
    }

    public function delete(int $compId, int $id, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        try {
            $stmtCheck = $this->db->prepare("SELECT * FROM `payroll_earning_deduction_types` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
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
            $this->auditLog->record($compId, 'payroll_earning_deduction_types', $id, 'update', $existing,
                array_merge($existing, ['status' => 'deleted']), $userId, 'web', $ip, $userAgent);
            return ['status' => true, 'message' => 'Deleted successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /**
     * 2026-08-30 (Phase 2, T014, explicit request: "ย้าย 'สถานะ' ออกจาก modal ไปไว้ที่แถวในตาราง") --
     * plain active/inactive flip, same shape as SetupRulesModel::holidayToggleStatus(). Deliberately
     * has NO `is_sync_only` guard (unlike delete() above) -- deactivating a sync-detected/event-linked
     * item is a legitimate, expected admin action (see SyncPayResolver's own deactivation handling,
     * e.g. the Trip Allowance/Diligence "admin deactivates -> excluded from calculation entirely"
     * tests), only CREATE/EDIT/DELETE are blocked for a system-managed row.
     */
    public function toggleStatus(int $compId, int $id, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        $stmt = $this->db->prepare("SELECT status FROM `payroll_earning_deduction_types` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $newStatus = $current === 'active' ? 'inactive' : 'active';
        $this->db->prepare("UPDATE `payroll_earning_deduction_types` SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':status' => $newStatus, ':updated_by' => $userId, ':id' => $id]);
        $this->auditLog->record($compId, 'payroll_earning_deduction_types', $id, 'update',
            ['status' => $current], ['status' => $newStatus], $userId, 'web', $ip, $userAgent);
        return ['status' => true, 'message' => 'Updated successfully.', 'new_status' => $newStatus];
    }
}
