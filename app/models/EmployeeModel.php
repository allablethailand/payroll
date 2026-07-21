<?php
declare(strict_types=1);
class EmployeeModel {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }

    private function allColumns(): array {
        return [
            'employee_no', 'profile_photo_path',
            'employee_type', 'employee_status', 'title', 'gender', 'name_th', 'surname_th', 'name_en', 'surname_en',
            'nickname_th', 'nickname_en', 'date_of_birth', 'nationality', 'religion', 'marital_status', 'military_status',
            'id_card_no', 'id_card_expire_date', 'tax_id_no', 'passport_no', 'passport_expire_date',
            'work_permit_no', 'date_work_permit_issue', 'date_work_permit_expire', 'visa_type', 'date_visa_expire',
            'company_email', 'office_tel', 'send_signin_email', 'personal_email', 'mobile_no', 'send_preboarding_email', 'line_id',
            'address_line_1_register', 'address_line_2_register', 'master_address_id_register',
            'use_register_address', 'address_line_1_contact', 'address_line_2_contact', 'master_address_id_contact',
            'emergency_name', 'emergency_surname', 'emergency_relationship', 'emergency_mobile',
            'department_id', 'role_id', 'position_id', 'branch_id', 'work_location_id', 'shift_id',
            'employment_date', 'employment_status', 'employment_type', 'report_to_id', 'date_contract_expire',
            'holiday_calendar_id', 'driver_license_no', 'workforce_type', 'record_time_method',
            'payment_type', 'bank_id', 'bank_account_no', 'bank_account_name', 'bank_branch',
            'salary_type', 'base_salary_amount', 'salary_effective_date', 'ot_eligible', 'tax_calculation_method', 'tax_exempt',
            'sso_enrolled', 'sso_no', 'sso_hospital_id', 'sso_start_date', 'sso_contribution_rate',
            'pvd_enrolled', 'pvd_fund_name', 'pvd_start_date', 'pvd_employee_rate', 'pvd_employer_rate',
            'insurance_plan_id', 'insurance_start_date',
            'has_spouse', 'spouse_name', 'spouse_id_card_no',
        ];
    }

    private function booleanColumns(): array {
        return ['send_signin_email', 'send_preboarding_email', 'use_register_address', 'ot_eligible', 'tax_exempt',
                'sso_enrolled', 'pvd_enrolled', 'has_spouse'];
    }

    private function intColumns(): array {
        return ['department_id', 'role_id', 'position_id', 'branch_id', 'work_location_id', 'shift_id',
                'report_to_id', 'holiday_calendar_id', 'bank_id', 'sso_hospital_id', 'insurance_plan_id',
                'master_address_id_register', 'master_address_id_contact'];
    }

    /**
     * Column => hash-column (or null if no exact-match search is needed for that field).
     * Encrypted at the application layer via EncryptionService (AES-256-GCM); never stored plaintext.
     */
    private function encryptedColumns(): array {
        return [
            'id_card_no' => 'id_card_no_hash',
            'tax_id_no' => 'tax_id_no_hash',
            'passport_no' => null,
            'bank_account_no' => 'bank_account_no_hash',
            'sso_no' => 'sso_no_hash',
            'spouse_id_card_no' => null,
        ];
    }

    private function requiredColumns(): array {
        return [
            'employee_no', 'employee_type', 'employee_status', 'title', 'gender', 'name_th', 'surname_th', 'name_en', 'surname_en',
            'date_of_birth', 'nationality', 'personal_email', 'mobile_no',
            'address_line_1_register', 'master_address_id_register',
            'address_line_1_contact', 'master_address_id_contact',
            'emergency_name', 'emergency_surname', 'emergency_relationship', 'emergency_mobile',
            'department_id', 'role_id', 'position_id', 'branch_id',
            'employment_date', 'employment_status', 'employment_type', 'workforce_type', 'record_time_method', 'payment_type',
            'salary_type', 'base_salary_amount', 'salary_effective_date', 'tax_calculation_method',
        ];
    }

    public function list(int $compId, int $start, int $length, array $filters, string $search, int $colIndex, string $orderDir, string $lang = 'th'): array {
        $nameCol = $lang === 'en' ? 'role_name_en' : 'role_name_th';
        $deptCol = $lang === 'en' ? 'department_name_en' : 'department_name_th';
        $branchCol = $lang === 'en' ? 'branch_name_en' : 'branch_name_th';

        $sortColumns = [
            1 => '`e`.`employee_no`',
            2 => '`e`.`name_th`',
            3 => "`r`.`{$nameCol}`",
            4 => "`d`.`{$deptCol}`",
            6 => "`b`.`{$branchCol}`",
            7 => '`e`.`employment_date`',
            8 => '`e`.`employee_status`',
        ];
        $sortColumn = $sortColumns[$colIndex] ?? '`e`.`id`';
        $orderDir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';

        $baseWhere = "e.comp_id = :comp_id AND e.deleted_at IS NULL";
        $params = [':comp_id' => $compId];

        if (!empty($filters['status'])) {
            $baseWhere .= " AND e.employee_status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['employment_status'])) {
            $baseWhere .= " AND e.employment_status = :employment_status";
            $params[':employment_status'] = $filters['employment_status'];
        }

        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `employees` e WHERE {$baseWhere}");
        $totalStmt->execute($params);
        $recordsTotal = (int)$totalStmt->fetchColumn();

        $whereSql = $baseWhere;
        if ($search !== '') {
            $whereSql .= " AND (e.employee_no LIKE :search1 OR e.name_th LIKE :search2 OR e.surname_th LIKE :search3 OR e.name_en LIKE :search4 OR e.surname_en LIKE :search5 OR e.personal_email LIKE :search6)";
            for ($i = 1; $i <= 6; $i++) {
                $params[":search{$i}"] = "%{$search}%";
            }
        }

        $countSql = "SELECT COUNT(*) FROM `employees` e WHERE {$whereSql}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $recordsFiltered = (int)$countStmt->fetchColumn();

        $dataSql = "SELECT e.id, e.employee_no,
                        CONCAT(e.name_th, ' ', e.surname_th) AS name,
                        e.personal_email AS email,
                        e.mobile_no AS phone,
                        COALESCE(r.{$nameCol}, '') AS role,
                        COALESCE(d.{$deptCol}, '') AS department,
                        NULL AS shift,
                        COALESCE(b.{$branchCol}, '') AS branch,
                        e.employment_date AS start_work_date,
                        e.employee_status AS status
                    FROM `employees` e
                    LEFT JOIN `structure_roles` r ON e.role_id = r.id
                    LEFT JOIN `structure_departments` d ON e.department_id = d.id
                    LEFT JOIN `structure_branches` b ON e.branch_id = b.id
                    WHERE {$whereSql}
                    ORDER BY {$sortColumn} {$orderDir}
                    LIMIT :limit OFFSET :offset";
        $dataStmt = $this->db->prepare($dataSql);
        foreach ($params as $key => $val) {
            $dataStmt->bindValue($key, $val);
        }
        $dataStmt->bindValue(':limit', $length, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $start, PDO::PARAM_INT);
        $dataStmt->execute();
        $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($data as &$row) {
            $row['status'] = $row['status'] === 'active' ? 'Active' : ucfirst((string)$row['status']);
        }

        return [
            'total' => $recordsTotal,
            'filtered' => $recordsFiltered,
            'data' => $data,
        ];
    }

    private function buildAddressDisplay(array $row, string $suffix): array {
        $th = [];
        foreach (["sub_district_th_{$suffix}", "district_th_{$suffix}", "province_th_{$suffix}", "postcode_{$suffix}"] as $key) {
            if (!empty($row[$key])) $th[] = $row[$key];
        }
        $en = [];
        foreach (["sub_district_en_{$suffix}", "district_en_{$suffix}", "province_en_{$suffix}", "postcode_{$suffix}"] as $key) {
            if (!empty($row[$key])) $en[] = $row[$key];
        }
        return [
            "address_display_th_{$suffix}" => !empty($th) ? implode(' » ', $th) : '',
            "address_display_en_{$suffix}" => !empty($en) ? implode(' » ', $en) : '',
        ];
    }

    public function get(int $compId, string $employeeNo): ?array {
        $sql = "SELECT e.*,
                    d.department_name_th, d.department_name_en,
                    r.role_name_th, r.role_name_en,
                    p.position_name_th, p.position_name_en,
                    b.branch_name_th, b.branch_name_en,
                    mb.bank_code, mb.bank_name_th, mb.bank_name_en,
                    mn.nationality_name_th, mn.nationality_name_en,
                    mrl.religion_name_th, mrl.religion_name_en,
                    CONCAT(rt.name_th, ' ', rt.surname_th) AS report_to_name_th,
                    CONCAT(rt.name_en, ' ', rt.surname_en) AS report_to_name_en,
                    mar.level_1 AS postcode_register, mar.level_2_th AS province_th_register, mar.level_3_th AS district_th_register, mar.level_4_th AS sub_district_th_register,
                    mar.level_2_en AS province_en_register, mar.level_3_en AS district_en_register, mar.level_4_en AS sub_district_en_register,
                    mac.level_1 AS postcode_contact, mac.level_2_th AS province_th_contact, mac.level_3_th AS district_th_contact, mac.level_4_th AS sub_district_th_contact,
                    mac.level_2_en AS province_en_contact, mac.level_3_en AS district_en_contact, mac.level_4_en AS sub_district_en_contact
                FROM `employees` e
                LEFT JOIN `structure_departments` d ON e.department_id = d.id
                LEFT JOIN `structure_roles` r ON e.role_id = r.id
                LEFT JOIN `structure_positions` p ON e.position_id = p.id
                LEFT JOIN `structure_branches` b ON e.branch_id = b.id
                LEFT JOIN `master_banks` mb ON e.bank_id = mb.id
                LEFT JOIN `master_nationalities` mn ON e.nationality = mn.nationality_code
                LEFT JOIN `master_religions` mrl ON e.religion = mrl.religion_code
                LEFT JOIN `employees` rt ON e.report_to_id = rt.id
                LEFT JOIN `master_addresses` mar ON e.master_address_id_register = mar.id
                LEFT JOIN `master_addresses` mac ON e.master_address_id_contact = mac.id
                WHERE e.comp_id = :comp_id AND e.employee_no = :employee_no AND e.deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':comp_id' => $compId, ':employee_no' => $employeeNo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $keyVersion = isset($row['key_version']) ? (int)$row['key_version'] : null;
        foreach (array_keys($this->encryptedColumns()) as $col) {
            $row[$col] = EncryptionService::decrypt($row[$col] ?? null, $keyVersion);
        }
        $row = array_merge($row, $this->buildAddressDisplay($row, 'register'), $this->buildAddressDisplay($row, 'contact'));
        return $row;
    }

    public function reportToOptions(int $compId, ?int $excludeId, string $search, int $page, int $limit): array {
        $offset = ($page - 1) * $limit;
        $where = "comp_id = :comp_id AND deleted_at IS NULL";
        $params = [':comp_id' => $compId];
        if ($excludeId !== null) {
            $where .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        if ($search !== '') {
            $where .= " AND (name_th LIKE :search1 OR surname_th LIKE :search2 OR name_en LIKE :search3 OR surname_en LIKE :search4 OR employee_no LIKE :search5)";
            for ($i = 1; $i <= 5; $i++) {
                $params[":search{$i}"] = "%{$search}%";
            }
        }
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `employees` WHERE {$where}");
        $totalStmt->execute($params);
        $totalCount = (int)$totalStmt->fetchColumn();

        $sql = "SELECT id,
                    CONCAT(employee_no, ' - ', name_th, ' ', surname_th) AS text_th,
                    CONCAT(employee_no, ' - ', name_en, ' ', surname_en) AS text_en
                FROM `employees` WHERE {$where} ORDER BY name_th ASC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['items' => $items, 'total_count' => $totalCount];
    }

    private function isEmployeeNoDuplicate(int $compId, string $employeeNo, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM `employees` WHERE comp_id = :comp_id AND employee_no = :employee_no AND deleted_at IS NULL";
        $params = [':comp_id' => $compId, ':employee_no' => $employeeNo];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function referenceExists(string $table, int $id, int $compId): bool {
        $stmt = $this->db->prepare("SELECT id FROM `{$table}` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        return (bool)$stmt->fetch();
    }

    private function isValidThaiId(string $id): bool {
        if (!preg_match('/^\d{13}$/', $id)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int)$id[$i] * (13 - $i);
        }
        $check = (11 - ($sum % 11)) % 10;
        return $check === (int)$id[12];
    }

    public function save(int $compId, array $data, int $userId): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        foreach ($this->requiredColumns() as $field) {
            if (!isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
                return ['status' => false, 'message' => "Missing required field: {$field}"];
            }
        }

        $employeeType = $data['employee_type'];
        if ($employeeType === 'domestic') {
            if (empty($data['id_card_no'])) {
                return ['status' => false, 'message' => 'Missing required field: id_card_no'];
            }
            if (!$this->isValidThaiId((string)$data['id_card_no'])) {
                return ['status' => false, 'message' => 'Invalid Thai ID card number.'];
            }
        } elseif ($employeeType === 'foreigner') {
            foreach (['tax_id_no', 'passport_no', 'work_permit_no', 'date_work_permit_issue', 'date_work_permit_expire'] as $field) {
                if (empty($data[$field])) {
                    return ['status' => false, 'message' => "Missing required field: {$field}"];
                }
            }
        } else {
            return ['status' => false, 'message' => 'Invalid employee_type.'];
        }

        if ($data['payment_type'] === 'bank') {
            if (empty($data['bank_id']) || empty($data['bank_account_no'])) {
                return ['status' => false, 'message' => 'Missing required field: bank_id / bank_account_no'];
            }
        }

        if (!preg_match('/^\d{9,10}$/', (string)$data['mobile_no'])) {
            return ['status' => false, 'message' => 'Invalid mobile number.'];
        }
        if (!preg_match('/^\d{9,10}$/', (string)$data['emergency_mobile'])) {
            return ['status' => false, 'message' => 'Invalid emergency contact mobile number.'];
        }
        if (!filter_var($data['personal_email'], FILTER_VALIDATE_EMAIL)) {
            return ['status' => false, 'message' => 'Invalid personal email address.'];
        }

        $fkChecks = [
            'department_id' => 'structure_departments',
            'role_id' => 'structure_roles',
            'position_id' => 'structure_positions',
            'branch_id' => 'structure_branches',
        ];
        foreach ($fkChecks as $field => $table) {
            if (!$this->referenceExists($table, (int)$data[$field], $compId)) {
                return ['status' => false, 'message' => "Invalid reference for field: {$field}"];
            }
        }
        if (!empty($data['bank_id'])) {
            $stmt = $this->db->prepare("SELECT id FROM `master_banks` WHERE id = :id AND is_active = 1");
            $stmt->execute([':id' => (int)$data['bank_id']]);
            if (!$stmt->fetch()) {
                return ['status' => false, 'message' => 'Invalid bank selected.'];
            }
        }
        if (!empty($data['report_to_id'])) {
            $stmt = $this->db->prepare("SELECT id FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmt->execute([':id' => (int)$data['report_to_id'], ':comp_id' => $compId]);
            if (!$stmt->fetch()) {
                return ['status' => false, 'message' => 'Invalid reference for field: report_to_id'];
            }
            if ($id !== null && (int)$data['report_to_id'] === $id) {
                return ['status' => false, 'message' => 'An employee cannot report to themselves.'];
            }
        }

        if ($this->isEmployeeNoDuplicate($compId, (string)$data['employee_no'], $id)) {
            return ['status' => false, 'message' => 'This employee number is already in use.'];
        }

        $booleans = $this->booleanColumns();
        $ints = $this->intColumns();
        $encrypted = $this->encryptedColumns();
        $values = [];
        $encryptedAny = false;
        foreach ($this->allColumns() as $col) {
            if (in_array($col, $booleans, true)) {
                $values[$col] = !empty($data[$col]) ? 1 : 0;
                continue;
            }
            if (in_array($col, $ints, true)) {
                $values[$col] = !empty($data[$col]) ? (int)$data[$col] : null;
                continue;
            }
            $val = $data[$col] ?? null;
            $val = ($val === '' || $val === null) ? null : $val;
            if (array_key_exists($col, $encrypted)) {
                $enc = EncryptionService::encrypt($val !== null ? (string)$val : null);
                $values[$col] = $enc['value'] ?? null;
                if ($enc !== null) {
                    $encryptedAny = true;
                }
                $hashCol = $encrypted[$col];
                if ($hashCol !== null) {
                    $values[$hashCol] = EncryptionService::hash($val !== null ? (string)$val : null);
                }
                continue;
            }
            $values[$col] = $val;
        }
        if ($encryptedAny) {
            $values['key_version'] = EncryptionService::currentKeyVersion();
        }

        try {
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
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
                $sql = "UPDATE `employees` SET " . implode(', ', $setSql) . ", updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id, 'employee_no' => $values['employee_no']];
            }

            $cols = array_keys($values);
            $colList = implode(', ', array_map(fn($c) => "`{$c}`", $cols));
            $placeholderList = implode(', ', array_map(fn($c) => ":{$c}", $cols));
            $sql = "INSERT INTO `employees` (comp_id, {$colList}, created_by) VALUES (:comp_id, {$placeholderList}, :created_by)";
            $params = [':comp_id' => $compId, ':created_by' => $userId];
            foreach ($values as $col => $val) {
                $params[":{$col}"] = $val;
            }
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return ['status' => true, 'message' => 'Created successfully.', 'id' => (int)$this->db->lastInsertId(), 'employee_no' => $values['employee_no']];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function delete(int $compId, int $id, int $userId): array {
        try {
            $stmtCheck = $this->db->prepare("SELECT id FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
            if (!$stmtCheck->fetch()) {
                return ['status' => false, 'message' => 'Record not found.'];
            }
            $stmtRef = $this->db->prepare("SELECT id FROM `employees` WHERE report_to_id = :id AND deleted_at IS NULL LIMIT 1");
            $stmtRef->execute([':id' => $id]);
            if ($stmtRef->fetch()) {
                return ['status' => false, 'message' => 'Cannot delete: this employee is set as report-to manager for other employees.'];
            }
            $stmt = $this->db->prepare("UPDATE `employees` SET employee_status = 'terminated', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
            return ['status' => true, 'message' => 'Deleted successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    private function childConfig(): array {
        return [
            'dependent' => [
                'table' => 'employee_dependents',
                'columns' => ['name', 'id_card_no', 'date_of_birth', 'relationship', 'studying'],
                'required' => ['name', 'relationship'],
                'booleans' => ['studying'],
                'encrypted' => ['id_card_no'],
            ],
            'parent' => [
                'table' => 'employee_parents',
                'columns' => ['name', 'id_card_no', 'relationship'],
                'required' => ['name', 'relationship'],
                'booleans' => [],
                'encrypted' => ['id_card_no'],
            ],
        ];
    }

    public function getChildConfig(string $type): ?array {
        return $this->childConfig()[$type] ?? null;
    }

    public function employeeBelongsToComp(int $employeeId, int $compId): bool {
        $stmt = $this->db->prepare("SELECT id FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        return (bool)$stmt->fetch();
    }

    public function listChildren(string $type, int $employeeId, int $compId): array {
        $config = $this->getChildConfig($type);
        if (!$config || !$this->employeeBelongsToComp($employeeId, $compId)) {
            return [];
        }
        $table = $config['table'];
        $stmt = $this->db->prepare("SELECT * FROM `{$table}` WHERE employee_id = :employee_id AND deleted_at IS NULL AND status != 'deleted' ORDER BY id ASC");
        $stmt->execute([':employee_id' => $employeeId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $encryptedCols = $config['encrypted'] ?? [];
        if (!empty($encryptedCols)) {
            foreach ($rows as &$row) {
                $keyVersion = isset($row['key_version']) ? (int)$row['key_version'] : null;
                foreach ($encryptedCols as $col) {
                    $row[$col] = EncryptionService::decrypt($row[$col] ?? null, $keyVersion);
                }
            }
            unset($row);
        }
        return $rows;
    }

    public function saveChild(string $type, int $employeeId, int $compId, array $data, int $userId): array {
        $config = $this->getChildConfig($type);
        if (!$config) {
            return ['status' => false, 'message' => 'Invalid entity type.'];
        }
        if (!$this->employeeBelongsToComp($employeeId, $compId)) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }
        $table = $config['table'];
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        foreach ($config['required'] as $field) {
            if (!isset($data[$field]) || $data[$field] === null || $data[$field] === '') {
                return ['status' => false, 'message' => "Missing required field: {$field}"];
            }
        }
        if (!empty($data['id_card_no']) && !preg_match('/^\d{13}$/', (string)$data['id_card_no'])) {
            return ['status' => false, 'message' => 'ID card number must be 13 digits.'];
        }

        $encryptedCols = $config['encrypted'] ?? [];
        $values = [];
        $encryptedAny = false;
        foreach ($config['columns'] as $col) {
            if (in_array($col, $config['booleans'], true)) {
                $values[$col] = !empty($data[$col]) ? 1 : 0;
                continue;
            }
            $val = $data[$col] ?? null;
            $val = ($val === '' || $val === null) ? null : $val;
            if (in_array($col, $encryptedCols, true)) {
                $enc = EncryptionService::encrypt($val !== null ? (string)$val : null);
                $values[$col] = $enc['value'] ?? null;
                if ($enc !== null) {
                    $encryptedAny = true;
                }
                continue;
            }
            $values[$col] = $val;
        }
        if ($encryptedAny) {
            $values['key_version'] = EncryptionService::currentKeyVersion();
        }

        try {
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM `{$table}` WHERE id = :id AND employee_id = :employee_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':employee_id' => $employeeId]);
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
            $sql = "INSERT INTO `{$table}` (employee_id, {$colList}, created_by) VALUES (:employee_id, {$placeholderList}, :created_by)";
            $params = [':employee_id' => $employeeId, ':created_by' => $userId];
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

    public function deleteChild(string $type, int $employeeId, int $compId, int $id, int $userId): array {
        $config = $this->getChildConfig($type);
        if (!$config) {
            return ['status' => false, 'message' => 'Invalid entity type.'];
        }
        if (!$this->employeeBelongsToComp($employeeId, $compId)) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }
        $table = $config['table'];
        try {
            $stmtCheck = $this->db->prepare("SELECT id FROM `{$table}` WHERE id = :id AND employee_id = :employee_id AND deleted_at IS NULL");
            $stmtCheck->execute([':id' => $id, ':employee_id' => $employeeId]);
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

    public function documentTypes(): array {
        return ['id_card_copy', 'house_registration_copy', 'work_permit_copy', 'employment_contract',
                'bank_book_copy', 'resume', 'education_certificate', 'other'];
    }

    public function listDocuments(int $employeeId, int $compId): array {
        if (!$this->employeeBelongsToComp($employeeId, $compId)) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT id, document_type, file_name, uploaded_at FROM `employee_documents` WHERE employee_id = :employee_id AND deleted_at IS NULL ORDER BY uploaded_at DESC");
        $stmt->execute([':employee_id' => $employeeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveDocument(int $employeeId, int $compId, string $documentType, string $fileName, string $filePath, int $userId): array {
        if (!$this->employeeBelongsToComp($employeeId, $compId)) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }
        if (!in_array($documentType, $this->documentTypes(), true)) {
            return ['status' => false, 'message' => 'Invalid document_type.'];
        }
        try {
            $stmt = $this->db->prepare("INSERT INTO `employee_documents` (employee_id, document_type, file_name, file_path, uploaded_by) VALUES (:employee_id, :document_type, :file_name, :file_path, :uploaded_by)");
            $stmt->execute([
                ':employee_id' => $employeeId,
                ':document_type' => $documentType,
                ':file_name' => $fileName,
                ':file_path' => $filePath,
                ':uploaded_by' => $userId,
            ]);
            return ['status' => true, 'message' => 'Uploaded successfully.', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function getDocument(int $id, int $compId): ?array {
        $stmt = $this->db->prepare(
            "SELECT d.* FROM `employee_documents` d
             INNER JOIN `employees` e ON d.employee_id = e.id
             WHERE d.id = :id AND e.comp_id = :comp_id AND d.deleted_at IS NULL AND e.deleted_at IS NULL"
        );
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function deleteDocument(int $id, int $employeeId, int $compId, int $userId): array {
        if (!$this->employeeBelongsToComp($employeeId, $compId)) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }
        try {
            $stmtCheck = $this->db->prepare("SELECT id FROM `employee_documents` WHERE id = :id AND employee_id = :employee_id AND deleted_at IS NULL");
            $stmtCheck->execute([':id' => $id, ':employee_id' => $employeeId]);
            if (!$stmtCheck->fetch()) {
                return ['status' => false, 'message' => 'Record not found.'];
            }
            $stmt = $this->db->prepare("UPDATE `employee_documents` SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
            return ['status' => true, 'message' => 'Deleted successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }
}
