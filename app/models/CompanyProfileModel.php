<?php
declare(strict_types=1);
class CompanyProfileModel {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }

    /** logo_path must exactly match what uploadLogo() produces for THIS company -- same
     *  traversal-proofing pattern as PayslipTemplateModel::isValidLogoPath()/
     *  EmploymentCertificateTemplateModel::isValidLogoPath(). */
    public static function isValidLogoPath(?string $path, int $compId): bool {
        if ($path === null || $path === '') {
            return true;
        }
        $pattern = '#^public/uploads/company_logos/' . $compId . '/[a-f0-9]{32}\.(jpg|png|svg)$#';
        return (bool)preg_match($pattern, $path);
    }

    /** 2026-08-26, explicit request: "เพิ่มให้แนบลายเซ็นต์ Authorized Signatory Name หรือสามารถเซ็นต์สด
     *  ผ่านหน้าจอได้" -- same traversal-proofing pattern as isValidLogoPath() above, one company-wide
     *  signature image (uploaded file OR a live-drawn signature exported to PNG client-side -- both
     *  go through the exact same upload endpoint/path convention, only the file content differs). */
    public static function isValidSignaturePath(?string $path, int $compId): bool {
        if ($path === null || $path === '') {
            return true;
        }
        $pattern = '#^public/uploads/company_signatures/' . $compId . '/[a-f0-9]{32}\.(jpg|png|svg)$#';
        return (bool)preg_match($pattern, $path);
    }

    public function get() {
        $companyId = $_SESSION['user']['company_id'] ?? null;
        if (!$companyId) {
            return null;
        }
        $sql = "SELECT c.*, 
                       mc.countries_name_th,
                       mc.countries_name_en,
                       m.level_1 AS postcode,
                       m.level_2_th AS province_th,
                       m.level_3_th AS district_th,
                       m.level_4_th AS sub_district_th,
                       m.level_2_en AS province_en,
                       m.level_3_en AS district_en,
                       m.level_4_en AS sub_district_en
                FROM companies c
                LEFT JOIN master_countries mc ON c.registered_country = mc.countries_code
                LEFT JOIN master_addresses m ON c.master_address_id = m.id
                WHERE c.id = :company_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':company_id' => $companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($company) {
            $addrTh = [];
            if (!empty($company['sub_district_th'])) $addrTh[] = $company['sub_district_th'];
            if (!empty($company['district_th']))     $addrTh[] = $company['district_th'];
            if (!empty($company['province_th']))     $addrTh[] = $company['province_th'];
            if (!empty($company['postcode']))        $addrTh[] = $company['postcode'];
            $company['address_display_th'] = !empty($addrTh) ? implode(' » ', $addrTh) : '';
            $addrEn = [];
            if (!empty($company['sub_district_en'])) $addrEn[] = $company['sub_district_en'];
            if (!empty($company['district_en']))     $addrEn[] = $company['district_en'];
            if (!empty($company['province_en']))     $addrEn[] = $company['province_en'];
            if (!empty($company['postcode']))        $addrEn[] = $company['postcode'];
            $company['address_display_en'] = !empty($addrEn) ? implode(' » ', $addrEn) : '';
            $company['country_data'] = [
                'id' => $company['registered_country'],
                'text_th' => $company['countries_name_th'] ?? $company['registered_country'],
                'text_en' => $company['countries_name_en'] ?? $company['registered_country']
            ];
            if (!empty($company['statutory_data'])) {
                $company['statutory_data'] = json_decode($company['statutory_data'], true);
            } else {
                $company['statutory_data'] = [];
            }
            return $company;
        }
        return null;
    }
    public function save($data) {
        $companyId = $_SESSION['user']['company_id'] ?? null;
        if (!$companyId) {
            return false;
        }
        $stmtCheck = $this->db->prepare("SELECT id, setup_status FROM companies WHERE id = :company_id");
        $stmtCheck->execute([':company_id' => $companyId]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        $wasDraft = $existing && ($existing['setup_status'] ?? null) === 'draft';
        $statutoryJson = null;
        if (isset($data['statutory_data']) && is_array($data['statutory_data'])) {
            $statutoryJson = json_encode($data['statutory_data'], JSON_UNESCAPED_UNICODE);
        }
        if ($existing) {
            // A company auto-provisioned via Origami SSO (auth/index.php) starts as
            // setup_status='draft' with placeholder registered_country='XX'/'PENDING' fields --
            // only advance it to 'active' once a real, supported country and non-placeholder
            // required fields are actually saved. A draft re-saved with placeholders still
            // pending stays draft. Existing companies are already setup_status='active' by
            // default, so this only ever matters for auto-provisioned rows.
            $isRealCountry = false;
            if (!empty($data['registered_country']) && $data['registered_country'] !== 'XX') {
                $chkCountry = $this->db->prepare("SELECT 1 FROM master_countries WHERE countries_code = :code LIMIT 1");
                $chkCountry->execute([':code' => $data['registered_country']]);
                $isRealCountry = (bool)$chkCountry->fetchColumn();
            }
            $placeholder = ['PENDING', ''];
            $isComplete = $isRealCountry
                && !in_array((string)($data['global_tax_id'] ?? ''), $placeholder, true)
                && !in_array((string)($data['authorized_signatory_name'] ?? ''), $placeholder, true)
                && !in_array((string)($data['address_line_1'] ?? ''), $placeholder, true);

            $sql = "UPDATE companies SET
                        company_legal_name = :company_legal_name,
                        local_name = :local_name,
                        fiscal_year_start_month = :fiscal_year_start_month,
                        prorate_divisor_days = :prorate_divisor_days,
                        registered_country = :registered_country,
                        global_tax_id = :global_tax_id,
                        address_line_1 = :address_line_1,
                        address_line_2 = :address_line_2,
                        master_address_id = :master_address_id,
                        statutory_data = :statutory_data,
                        authorized_signatory_name = :authorized_signatory_name,
                        logo_path = :logo_path,
                        signature_path = :signature_path,
                        setup_status = :setup_status,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $ok = $stmt->execute([
                ':id' => $companyId,
                ':company_legal_name' => $data['company_legal_name'] ?? null,
                ':local_name' => $data['local_name'] ?? null,
                ':fiscal_year_start_month' => (isset($data['fiscal_year_start_month']) && (int)$data['fiscal_year_start_month'] >= 1 && (int)$data['fiscal_year_start_month'] <= 12) ? (int)$data['fiscal_year_start_month'] : 1,
                // 2026-08-29, explicit request: "การคิดเงินเดือน ต้องจับหาร 30 ตามกฏหมาย...ช่วยเพิ่มให้ตั้งค่า
                // ตัวเลขนี้ได้หน่อย" -- fixed divisor for mid-period join/leave proration (see
                // PayrollRunModel::recalculate()'s own use of this same value). Bounded 1-31 (a
                // real month never has more than 31 days) -- default/fallback 30 matches the legal
                // convention this request names outright, not an arbitrary placeholder.
                ':prorate_divisor_days' => (isset($data['prorate_divisor_days']) && (int)$data['prorate_divisor_days'] >= 1 && (int)$data['prorate_divisor_days'] <= 31) ? (int)$data['prorate_divisor_days'] : 30,
                ':registered_country' => $data['registered_country'] ?? null,
                ':global_tax_id' => $data['global_tax_id'] ?? null,
                ':address_line_1' => $data['address_line_1'] ?? null,
                ':address_line_2' => !empty($data['address_line_2']) ? $data['address_line_2'] : null,
                ':master_address_id' => !empty($data['master_address_id']) ? (int)$data['master_address_id'] : null,
                ':statutory_data' => $statutoryJson,
                ':authorized_signatory_name' => $data['authorized_signatory_name'] ?? null,
                // Uploaded via a separate endpoint (CompanyProfileController::uploadLogo(), same
                // pattern as PayslipTemplateController's own logo upload) -- the client keeps
                // whatever path it already had in a hidden field across saves, same as Payslip
                // Template's modal does, so an unrelated profile save never accidentally clears it.
                ':logo_path' => !empty($data['logo_path']) ? $data['logo_path'] : null,
                ':signature_path' => !empty($data['signature_path']) ? $data['signature_path'] : null,
                ':setup_status' => $isComplete ? 'active' : 'draft',
            ]);
            // Auto-seed the default earning/deduction items the moment a company actually
            // transitions draft -> active (2026-08-21, explicit request: "กรณีเป็นการเปิดใช้งาน
            // บริษัทใหม่ ให้ขึ้น Default ของระบบไว้ให้เลย") -- only on the real transition, not every
            // subsequent save of an already-active company. seedDefaults() is idempotent (skips any
            // item_code already present, active or soft-deleted) so it's safe even if this ever
            // fires more than once for the same company. The manual "Load Default Items" button in
            // Payroll Configuration still works independently of this -- unchanged.
            if ($ok && $isComplete && $wasDraft) {
                $userId = $_SESSION['user']['employee_id'] ?? null;
                (new PayrollEarningDeductionTypeModel())->seedDefaults((int)$companyId, $userId !== null ? (int)$userId : null);
            }
            return $ok;
        }
    }
    /**
     * Appends `AND col IN (...)` clauses for the Excel-style column filter (2026-08-27 rollout) --
     * shared by paginateData() and columnDistinctValues() so the two can never drift out of sync.
     * `$columnFilters` is `[realColumnName => [selected values...]]` (already translated from the
     * frontend's own column KEYS to real SQL column names by the controller, see
     * CompanyProfileController::structureFilterMap()) -- validated again here against
     * `$allowedColumns` regardless, never trusting the caller alone with something that ends up
     * inside a backtick-quoted identifier.
     */
    private function applyColumnFilters(string $whereSql, array &$params, array $columnFilters, array $allowedColumns, ?string $excludeColumn = null): string {
        $paramIdx = 0;
        foreach ($columnFilters as $col => $values) {
            if ($col === $excludeColumn || !in_array($col, $allowedColumns, true) || !is_array($values) || empty($values)) {
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
            $whereSql .= " AND `{$col}` IN (" . implode(', ', $placeholders) . ")";
        }
        return $whereSql;
    }

    public function paginateData($tableName, $compId, $searchColumns, $sortColumns, $start, $length, $search, $colIndex, $orderDir, array $columnFilters = [], array $allowedColumns = []) {
        $sortColumn = $sortColumns[$colIndex] ?? $sortColumns[0];
        $orderDir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';
        $baseWhere = "comp_id = :comp_id AND deleted_at IS NULL AND status != 'deleted'";
        $totalQuery = "SELECT COUNT(*) FROM `{$tableName}` WHERE {$baseWhere}";
        $stmtTotal = $this->db->prepare($totalQuery);
        $stmtTotal->execute([':comp_id' => $compId]);
        $recordsTotal = (int)$stmtTotal->fetchColumn();
        $whereSql = $baseWhere;
        $params = [':comp_id' => $compId];
        if (!empty($search)) {
            $searchTerms = [];
            foreach ($searchColumns as $index => $col) {
                $paramName = ":search_" . $index;
                $searchTerms[] = "`{$col}` LIKE {$paramName}";
                $params[$paramName] = "%{$search}%";
            }
            $whereSql .= " AND (" . implode(" OR ", $searchTerms) . ")";
        }
        // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter rollout
        // (same "counts toward recordsFiltered, not recordsTotal" placement as the search box above).
        $whereSql = $this->applyColumnFilters($whereSql, $params, $columnFilters, $allowedColumns);
        $countQuery = "SELECT COUNT(*) FROM `{$tableName}` WHERE {$whereSql}";
        $stmtCount = $this->db->prepare($countQuery);
        $stmtCount->execute($params);
        $recordsFiltered = (int)$stmtCount->fetchColumn();
        $dataQuery = "SELECT * FROM `{$tableName}` 
                      WHERE {$whereSql} 
                      ORDER BY `{$sortColumn}` {$orderDir} 
                      LIMIT :limit OFFSET :offset";
        $stmtData = $this->db->prepare($dataQuery);
        foreach ($params as $key => $val) {
            $stmtData->bindValue($key, $val);
        }
        $stmtData->bindValue(':limit', (int)$length, PDO::PARAM_INT);
        $stmtData->bindValue(':offset', (int)$start, PDO::PARAM_INT);
        $stmtData->execute();
        $data = $stmtData->fetchAll(PDO::FETCH_ASSOC);
        return [
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data
        ];
    }

    /**
     * Distinct values for ONE column of a structure table, respecting every OTHER currently-active
     * Excel-style column filter but NOT this column's own selection -- same "opening a column's own
     * dropdown shows every value it could hold" rule as EmployeeModel::listColumnValues(), see that
     * method's own docblock. Deliberately does NOT also respect the table's free-text search box
     * (unlike EmployeeModel's own version) -- these 6 structure tables have no other pre-existing
     * filter UI beyond DataTables' own search box, and threading that through here too would add
     * real complexity for a genuinely minor edge case (the checkbox list not ALSO narrowing when
     * something is typed in the search box); the actual table rows still correctly narrow by both
     * together via paginateData()'s own search+columnFilters combination.
     */
    public function columnDistinctValues(string $tableName, int $compId, string $column, array $allowedColumns, array $columnFilters, ?string $excludeColumn): array {
        if (!in_array($column, $allowedColumns, true)) {
            return [];
        }
        $whereSql = "comp_id = :comp_id AND deleted_at IS NULL AND status != 'deleted'";
        $params = [':comp_id' => $compId];
        $whereSql = $this->applyColumnFilters($whereSql, $params, $columnFilters, $allowedColumns, $excludeColumn);
        $sql = "SELECT DISTINCT `{$column}` AS value FROM `{$tableName}`
                WHERE {$whereSql} AND `{$column}` IS NOT NULL AND `{$column}` != ''
                ORDER BY value ASC LIMIT 500";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'value');
    }

    private function structureConfig(): array {
        return [
            'branch' => [
                'table' => 'structure_branches',
                'columns' => ['branch_code', 'branch_name_th', 'branch_name_en', 'tax_branch_id', 'sso_branch_code', 'is_default', 'lock_stamp', 'location', 'status'],
                'required' => ['branch_code', 'branch_name_th', 'branch_name_en'],
                'unique_columns' => ['branch_code'],
                'booleans' => ['is_default', 'lock_stamp'],
            ],
            'role' => [
                'table' => 'structure_roles',
                // can_process_payroll/can_approve_payroll/can_finalize_payroll added 2026-08-28
                // (explicit request, following a real bug report: a role could be granted every
                // Permission Matrix checkbox and still be unable to touch Payroll Run at all,
                // since these 3 columns are a separate, older mechanism PayrollRunModel::userCan()
                // checks directly against structure_roles -- unrelated to permissions/
                // role_permissions. There was no UI anywhere to set them before this; they
                // defaulted to 0 for every role and could only be flipped via raw SQL.
                'columns' => ['role_name_th', 'role_name_en', 'salary_access', 'can_process_payroll', 'can_approve_payroll', 'can_finalize_payroll', 'status'],
                'required' => ['role_name_th', 'role_name_en'],
                'unique_columns' => ['role_name_th', 'role_name_en'],
                'booleans' => ['salary_access', 'can_process_payroll', 'can_approve_payroll', 'can_finalize_payroll'],
            ],
            'department' => [
                'table' => 'structure_departments',
                'columns' => ['department_code', 'department_name_th', 'department_name_en', 'cost_center', 'status'],
                'required' => ['department_code', 'department_name_th', 'department_name_en'],
                'unique_columns' => ['department_code'],
                'booleans' => [],
            ],
            'position' => [
                'table' => 'structure_positions',
                'columns' => ['position_code', 'position_name_th', 'position_name_en', 'position_allowance', 'status'],
                'required' => ['position_code', 'position_name_th', 'position_name_en'],
                'unique_columns' => ['position_code'],
                'booleans' => [],
            ],
            'rank' => [
                'table' => 'structure_ranks',
                'columns' => ['rank_code', 'rank_name_th', 'rank_name_en', 'salary_min', 'salary_max', 'ot_eligible', 'status'],
                'required' => ['rank_code', 'rank_name_th', 'rank_name_en'],
                'unique_columns' => ['rank_code'],
                'booleans' => ['ot_eligible'],
            ],
            // 2026-08-24, explicit request: "ในหน้าตั้งค่าพนักงาน ให้เพิ่ม Team เข้าไปได้ด้วย...ทีมให้เป็น
            // การเพิ่มการตั้งค่าเช่นเดียวกับ Department" -- outsourcing company's own project/client
            // team grouping (see structure_teams' own migration comment for the full context).
            // client_name is intentionally NOT in `required` -- a team can exist before its client
            // assignment is finalized, same "not every column that CAN be filled has to be" stance
            // department's own cost_center already takes.
            'team' => [
                'table' => 'structure_teams',
                'columns' => ['team_code', 'team_name_th', 'team_name_en', 'client_name', 'status'],
                'required' => ['team_code', 'team_name_th', 'team_name_en'],
                'unique_columns' => ['team_code'],
                'booleans' => [],
            ],
        ];
    }

    public function getStructureConfig(string $type): ?array {
        return $this->structureConfig()[$type] ?? null;
    }

    private function isStructureValueDuplicate(string $table, int $compId, string $column, string $value, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE comp_id = :comp_id AND `{$column}` = :value AND deleted_at IS NULL";
        $params = [':comp_id' => $compId, ':value' => $value];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function saveStructure(string $type, int $compId, array $data, int $userId): array {
        $config = $this->getStructureConfig($type);
        if (!$config) {
            return ['status' => false, 'message' => 'Invalid entity type.'];
        }
        $table = $config['table'];
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        foreach ($config['required'] as $field) {
            if (empty($data[$field])) {
                return ['status' => false, 'message' => "Missing required field: {$field}"];
            }
        }

        foreach ($config['unique_columns'] as $col) {
            if (!empty($data[$col]) && $this->isStructureValueDuplicate($table, $compId, $col, (string)$data[$col], $id)) {
                return ['status' => false, 'message' => "Duplicate value for field: {$col}"];
            }
        }

        $values = [];
        foreach ($config['columns'] as $col) {
            if ($col === 'status') {
                $statusInput = $data['status'] ?? 'active';
                $values[$col] = in_array($statusInput, ['active', 'inactive'], true) ? $statusInput : 'active';
                continue;
            }
            if (in_array($col, $config['booleans'], true)) {
                $values[$col] = !empty($data[$col]) ? 1 : 0;
                continue;
            }
            $val = $data[$col] ?? null;
            $values[$col] = ($val === '' || $val === null) ? null : $val;
        }

        try {
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM `{$table}` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $setSql = [];
                $params = [':id' => $id, ':updated_by' => $userId];
                foreach ($values as $col => $val) {
                    $setSql[] = "`{$col}` = :{$col}";
                    $params[":{$col}"] = $val;
                }
                $sql = "UPDATE `{$table}` SET " . implode(', ', $setSql) . ", updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id];
            }

            $cols = array_keys($values);
            $colList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
            $placeholderList = implode(', ', array_map(fn($c) => ":{$c}", $cols));
            $sql = "INSERT INTO `{$table}` (comp_id, {$colList}, created_by) VALUES (:comp_id, {$placeholderList}, :created_by)";
            $params = [':comp_id' => $compId, ':created_by' => $userId];
            foreach ($values as $col => $val) {
                $params[":{$col}"] = $val;
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return ['status' => true, 'message' => 'Created successfully.', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function deleteStructure(string $type, int $compId, int $id, int $userId): array {
        $config = $this->getStructureConfig($type);
        if (!$config) {
            return ['status' => false, 'message' => 'Invalid entity type.'];
        }
        $table = $config['table'];
        try {
            $stmtCheck = $this->db->prepare("SELECT id FROM `{$table}` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
            if (!$stmtCheck->fetch()) {
                return ['status' => false, 'message' => 'Record not found.'];
            }
            $stmt = $this->db->prepare("UPDATE `{$table}` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
            return ['status' => true, 'message' => 'Deleted successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }
}