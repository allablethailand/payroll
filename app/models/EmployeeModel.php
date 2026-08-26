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
            'company_email', 'office_tel', 'send_signin_email', 'personal_email', 'mobile_no', 'mobile_country_code', 'send_preboarding_email', 'line_id',
            'address_line_1_register', 'address_line_2_register', 'master_address_id_register',
            'use_register_address', 'address_line_1_contact', 'address_line_2_contact', 'master_address_id_contact',
            'emergency_name', 'emergency_surname', 'emergency_relationship', 'emergency_mobile',
            'department_id', 'team_id', 'role_id', 'position_id', 'branch_id', 'work_location_id', 'shift_id', 'cycle_id',
            'employment_date', 'employment_status', 'employment_status_effective_date', 'employment_end_date', 'employment_end_reason',
            'employment_type', 'report_to_id', 'date_contract_expire',
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
        return ['department_id', 'team_id', 'role_id', 'position_id', 'branch_id', 'work_location_id', 'shift_id', 'cycle_id',
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

    // Register/contact address and emergency contact were dropped from here 2026-08-19 (explicit
    // request: hide non-payroll fields from the form) -- both are HR-record fields never read by
    // any statutory calc/report/sync in this app, and the form no longer shows or requires them
    // (see the matching "Hidden 2026-08-19" comments in app/views/employee/detail.php). The columns
    // themselves are untouched -- existing/synced data is unaffected, this only stops blocking a
    // save over fields the form doesn't collect anymore.
    // workforce_type/record_time_method dropped the same day, same reasoning -- confirmed with user
    // after grepping PayrollRunModel/app/services/* and finding neither ever read (attendance/HR-
    // tracking fields only, not payroll-calc-relevant, same shape as report_to_id/date_contract_
    // expire/holiday_calendar_id/driver_license_no already hidden alongside them).
    private function requiredColumns(): array {
        return [
            'employee_no', 'employee_type', 'employee_status', 'title', 'gender', 'name_th', 'surname_th', 'name_en', 'surname_en',
            'date_of_birth', 'nationality', 'personal_email', 'mobile_no',
            'department_id', 'role_id', 'position_id', 'branch_id',
            'employment_date', 'employment_status', 'employment_type', 'payment_type',
            'salary_type', 'base_salary_amount', 'salary_effective_date', 'tax_calculation_method',
        ];
    }

    /* ==================== PROFILE COMPLETENESS (2026-08-19, explicit request) ==================== */

    /**
     * Every column the completeness checklist below reads -- kept separate from allColumns() so
     * EmployeeModel::list()'s SELECT can pull exactly this set (not SELECT *) for every row on the
     * page. Encrypted columns (id_card_no/tax_id_no/passport_no/bank_account_no/sso_no) are listed
     * by their ciphertext column name -- completeness only ever checks IS NULL/NOT NULL on them,
     * never decrypts, since EmployeeModel::save() stores NULL ciphertext exactly when the plaintext
     * was empty (same invariant EncryptionService keeps everywhere else in this app) -- decrypting
     * every row just to check presence would be real per-page-load overhead for zero benefit.
     */
    public function completenessColumns(): array {
        return [
            'employee_type', 'title', 'gender', 'name_th', 'surname_th', 'name_en', 'surname_en',
            'date_of_birth', 'nationality', 'id_card_no', 'tax_id_no', 'passport_no', 'work_permit_no',
            'personal_email', 'mobile_no', 'line_id',
            'department_id', 'role_id', 'position_id', 'branch_id', 'work_location_id', 'shift_id',
            'employment_date', 'payment_type', 'bank_id', 'bank_account_no',
            'salary_type', 'base_salary_amount', 'salary_effective_date', 'tax_calculation_method',
            'sso_enrolled', 'sso_no', 'has_spouse', 'spouse_name',
        ];
    }

    /**
     * True only for a genuinely-filled value -- also rejects the specific placeholder sentinels
     * createPlaceholderEmployeesForUnmapped() (PayrollSyncModel) writes for an auto-created,
     * not-yet-onboarded sync employee ('PENDING', 'Unknown', the all-zero phone placeholder, the
     * 1900-01-01 date-of-birth placeholder, the synthetic 'sync-pending-...@placeholder.local'
     * email). Without this, a placeholder employee (is_payroll_ready=0, exactly the record this
     * feature most needs to flag as incomplete) would score as fully complete on every field that
     * happens to have SOME string in it, even though none of it is real data yet.
     */
    private function isCompletenessValueFilled($value): bool {
        if ($value === null) {
            return false;
        }
        $str = trim((string)$value);
        if ($str === '' || $str === 'PENDING' || $str === 'Unknown' || $str === '0000000000' || $str === '1900-01-01') {
            return false;
        }
        if (str_starts_with($str, 'sync-pending-')) {
            return false;
        }
        return true;
    }

    /** @param bool[] $checks @return array{done:int,total:int,percent:int} */
    private function scoreChecklist(array $checks): array {
        $total = count($checks);
        $done = count(array_filter($checks));
        return ['done' => $done, 'total' => $total, 'percent' => $total > 0 ? (int)round($done / $total * 100) : 100];
    }

    /**
     * Per-tab + overall completeness, computed from whatever columns are present in $e (a row from
     * list()/get() -- both now select completenessColumns() alongside their own display columns).
     * Deliberately scoped to fields still VISIBLE on the form after the 2026-08-19 field-trim (see
     * requiredColumns()'s docblock) -- Documents and Family/Tax Allowance's dependent/parent tables
     * are excluded entirely: both are genuinely variable-length/optional data (zero dependents is a
     * legitimate complete state, not a gap), not a fixed checklist a percentage can meaningfully
     * describe. Required columns ARE included despite always being non-empty on a normally-saved
     * record -- what makes them discriminating here is isCompletenessValueFilled() rejecting the
     * placeholder sentinels a sync-created employee starts with, not the field being optional.
     * Conditional checks (identification shape by employee_type, bank details only when
     * payment_type='bank', SSO/spouse detail only when that enrollment/checkbox is on) adapt the
     * checklist per employee rather than penalizing a field that plainly doesn't apply to them.
     */
    public function calculateCompleteness(array $e): array {
        $isForeigner = ($e['employee_type'] ?? 'domestic') === 'foreigner';
        $identificationOk = $isForeigner
            ? ($this->isCompletenessValueFilled($e['tax_id_no'] ?? null)
                && $this->isCompletenessValueFilled($e['passport_no'] ?? null)
                && $this->isCompletenessValueFilled($e['work_permit_no'] ?? null))
            : $this->isCompletenessValueFilled($e['id_card_no'] ?? null);

        $tabs = [];
        $tabs['info'] = $this->scoreChecklist([
            $this->isCompletenessValueFilled($e['title'] ?? null),
            $this->isCompletenessValueFilled($e['gender'] ?? null),
            $this->isCompletenessValueFilled($e['name_th'] ?? null) && $this->isCompletenessValueFilled($e['surname_th'] ?? null),
            $this->isCompletenessValueFilled($e['name_en'] ?? null) && $this->isCompletenessValueFilled($e['surname_en'] ?? null),
            $this->isCompletenessValueFilled($e['date_of_birth'] ?? null),
            $this->isCompletenessValueFilled($e['nationality'] ?? null),
            $identificationOk,
        ]);
        $tabs['contact'] = $this->scoreChecklist([
            $this->isCompletenessValueFilled($e['personal_email'] ?? null),
            $this->isCompletenessValueFilled($e['mobile_no'] ?? null),
            $this->isCompletenessValueFilled($e['line_id'] ?? null),
        ]);
        $bankOk = ($e['payment_type'] ?? null) === 'bank'
            ? ($this->isCompletenessValueFilled($e['bank_id'] ?? null) && $this->isCompletenessValueFilled($e['bank_account_no'] ?? null))
            : true;
        $tabs['employment'] = $this->scoreChecklist([
            !empty($e['department_id']), !empty($e['role_id']), !empty($e['position_id']), !empty($e['branch_id']),
            !empty($e['work_location_id']), !empty($e['shift_id']),
            $this->isCompletenessValueFilled($e['employment_date'] ?? null),
            $bankOk,
        ]);
        $tabs['salary'] = $this->scoreChecklist([
            $this->isCompletenessValueFilled($e['salary_type'] ?? null),
            (float)($e['base_salary_amount'] ?? 0) > 0,
            $this->isCompletenessValueFilled($e['salary_effective_date'] ?? null),
            $this->isCompletenessValueFilled($e['tax_calculation_method'] ?? null),
        ]);
        $tabs['social'] = $this->scoreChecklist([
            empty($e['sso_enrolled']) || $this->isCompletenessValueFilled($e['sso_no'] ?? null),
        ]);
        $tabs['family'] = $this->scoreChecklist([
            empty($e['has_spouse']) || $this->isCompletenessValueFilled($e['spouse_name'] ?? null),
        ]);

        $totalDone = array_sum(array_column($tabs, 'done'));
        $totalCount = array_sum(array_column($tabs, 'total'));
        return [
            'percent' => $totalCount > 0 ? (int)round($totalDone / $totalCount * 100) : 100,
            'tabs' => $tabs,
        ];
    }

    /** Which tab a requiredColumns()/isPayrollReady() field lives on -- used only to summarize
     *  verifyStatus()'s missing-field list down to "which tabs need attention" for display, since
     *  listing 20+ individual field labels in a tooltip isn't actually more useful than the tab name. */
    private function requiredFieldTabs(): array {
        return [
            'employee_no' => 'employment', 'employee_status' => 'info', 'title' => 'info',
            'name_th' => 'info', 'surname_th' => 'info', 'name_en' => 'info', 'surname_en' => 'info',
            'date_of_birth' => 'info', 'nationality' => 'info', 'id_card_no' => 'info',
            'tax_id_no' => 'info', 'passport_no' => 'info', 'work_permit_no' => 'info',
            'personal_email' => 'contact', 'mobile_no' => 'contact',
            'department_id' => 'employment', 'role_id' => 'employment', 'position_id' => 'employment', 'branch_id' => 'employment',
            'employment_date' => 'employment', 'employment_status' => 'employment', 'employment_type' => 'employment',
            'payment_type' => 'employment', 'bank_id' => 'employment', 'bank_account_no' => 'employment',
            'salary_type' => 'salary', 'base_salary_amount' => 'salary', 'salary_effective_date' => 'salary', 'tax_calculation_method' => 'salary',
        ];
    }

    /**
     * "Verify Status" (2026-08-19, explicit request) -- which payroll-critical fields are still
     * missing from $values? Deliberately narrower than calculateCompleteness()'s 100% (which also
     * counts e.g. line_id, a payslip-delivery channel with nothing to do with computing pay): this
     * only checks requiredColumns() (per-country adjusted -- master_addresses/tax_calculation_method
     * are TH-only) plus the identification/bank-details conditional checks calculateCompleteness()
     * already applies for the same reasoning, so a missing LINE ID (or any other completeness-only
     * field) never blocks payroll eligibility. $values may be save()'s fully-built column=>value map
     * OR a get()/list()-shaped DB row -- either way ciphertext presence is enough for the encrypted
     * columns, no decryption needed, same as calculateCompleteness(). Empty return = fully ready.
     */
    private function missingPayrollFields(array $values, bool $isThCompany): array {
        $requiredColumns = $this->requiredColumns();
        if (!$isThCompany) {
            $requiredColumns = array_diff($requiredColumns, ['master_address_id_register', 'master_address_id_contact', 'tax_calculation_method']);
        }
        $missing = [];
        foreach ($requiredColumns as $col) {
            $v = $values[$col] ?? null;
            if ($v === null || $v === '') {
                $missing[] = $col;
            }
        }
        if ((float)($values['base_salary_amount'] ?? 0) <= 0 && !in_array('base_salary_amount', $missing, true)) {
            $missing[] = 'base_salary_amount';
        }
        if (($values['employee_type'] ?? 'domestic') === 'foreigner') {
            foreach (['tax_id_no', 'passport_no', 'work_permit_no'] as $col) {
                if (empty($values[$col])) {
                    $missing[] = $col;
                }
            }
        } elseif (empty($values['id_card_no'])) {
            $missing[] = 'id_card_no';
        }
        if (($values['payment_type'] ?? null) === 'bank') {
            if (empty($values['bank_id'])) $missing[] = 'bank_id';
            if (empty($values['bank_account_no'])) $missing[] = 'bank_account_no';
        }
        return array_values(array_unique($missing));
    }

    private function isPayrollReady(array $values, bool $isThCompany): bool {
        return empty($this->missingPayrollFields($values, $isThCompany));
    }

    /** Public wrapper for display (Employee Detail's "Verify Status" badge) -- $e is a get()-shaped
     *  row. Already consumed at save-time too via employees.is_payroll_ready, read by
     *  PayrollRunModel::recalculate() -- an ineligible employee isn't hidden from a run, it's flagged
     *  with calc_errors='profile_incomplete' (see that method's own comments). */
    public function verifyStatus(array $e, bool $isThCompany): array {
        $missing = $this->missingPayrollFields($e, $isThCompany);
        $tabs = $this->requiredFieldTabs();
        $missingTabs = array_values(array_unique(array_map(fn($f) => $tabs[$f] ?? 'info', $missing)));
        return ['ready' => empty($missing), 'missing_tabs' => $missingTabs];
    }

    public function list(int $compId, int $start, int $length, array $filters, string $search, int $colIndex, string $orderDir, string $lang = 'th'): array {
        $nameCol = $lang === 'en' ? 'role_name_en' : 'role_name_th';
        $deptCol = $lang === 'en' ? 'department_name_en' : 'department_name_th';
        $branchCol = $lang === 'en' ? 'branch_name_en' : 'branch_name_th';
        $shiftCol = $lang === 'en' ? 'shift_name_en' : 'shift_name_th';
        $teamCol = $lang === 'en' ? 'team_name_en' : 'team_name_th';
        $positionCol = $lang === 'en' ? 'position_name_en' : 'position_name_th';

        // 2026-08-26, explicit request: "เิ่ม position กับเบอร์โทรเข้าตาราง" -- Phone inserted right
        // after Name (contact info clustered with identity), Position inserted right after Role (org
        // placement clustered together) -- same "inserting a column mid-list shifts every later
        // index by one" convention Team's own addition already established (see CLAUDE.md's Team
        // section). employee_no/name/role/department/team/shift/branch/start_work_date/status/
        // completeness column indices all shift accordingly.
        $sortColumns = [
            1 => '`e`.`employee_no`',
            2 => '`e`.`name_th`',
            3 => '`e`.`mobile_no`',
            4 => "`r`.`{$nameCol}`",
            5 => "`p`.`{$positionCol}`",
            6 => "`d`.`{$deptCol}`",
            7 => "`tm`.`{$teamCol}`",
            8 => "`sh`.`{$shiftCol}`",
            9 => "`b`.`{$branchCol}`",
            10 => '`e`.`employment_date`',
            11 => '`e`.`employee_status`',
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
        if (!empty($filters['role_id'])) {
            $baseWhere .= " AND e.role_id = :role_id";
            $params[':role_id'] = (int)$filters['role_id'];
        }
        if (!empty($filters['department_id'])) {
            $baseWhere .= " AND e.department_id = :department_id";
            $params[':department_id'] = (int)$filters['department_id'];
        }
        if (!empty($filters['team_id'])) {
            $baseWhere .= " AND e.team_id = :team_id";
            $params[':team_id'] = (int)$filters['team_id'];
        }
        if (!empty($filters['shift_id'])) {
            $baseWhere .= " AND e.shift_id = :shift_id";
            $params[':shift_id'] = (int)$filters['shift_id'];
        }
        if (!empty($filters['branch_id'])) {
            $baseWhere .= " AND e.branch_id = :branch_id";
            $params[':branch_id'] = (int)$filters['branch_id'];
        }
        if (!empty($filters['created_date_from'])) {
            $baseWhere .= " AND DATE(e.created_at) >= :created_date_from";
            $params[':created_date_from'] = $filters['created_date_from'];
        }
        if (!empty($filters['created_date_to'])) {
            $baseWhere .= " AND DATE(e.created_at) <= :created_date_to";
            $params[':created_date_to'] = $filters['created_date_to'];
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

        // Completeness columns (e.*) are selected raw/undecrypted alongside the display columns --
        // calculateCompleteness() only ever checks presence on the encrypted ones (id_card_no/
        // tax_id_no/passport_no/bank_account_no/sso_no), never the plaintext, so no per-row
        // decryption cost is paid just to render this list (see completenessColumns()'s docblock).
        $completenessSelect = implode(', ', array_map(fn($c) => "e.`{$c}`", $this->completenessColumns()));
        $dataSql = "SELECT e.id, e.employee_no,
                        CONCAT(e.name_th, ' ', e.surname_th) AS name,
                        e.personal_email AS email,
                        e.mobile_no AS phone,
                        COALESCE(r.{$nameCol}, '') AS role,
                        COALESCE(p.{$positionCol}, '') AS position,
                        COALESCE(d.{$deptCol}, '') AS department,
                        COALESCE(tm.{$teamCol}, '') AS team,
                        COALESCE(sh.{$shiftCol}, '') AS shift,
                        COALESCE(b.{$branchCol}, '') AS branch,
                        e.employment_date AS start_work_date,
                        e.employee_status AS status,
                        {$completenessSelect}
                    FROM `employees` e
                    LEFT JOIN `structure_roles` r ON e.role_id = r.id
                    LEFT JOIN `structure_positions` p ON e.position_id = p.id
                    LEFT JOIN `structure_departments` d ON e.department_id = d.id
                    LEFT JOIN `structure_teams` tm ON e.team_id = tm.id
                    LEFT JOIN `shifts` sh ON e.shift_id = sh.id
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

        $completenessCols = $this->completenessColumns();
        foreach ($data as &$row) {
            $row['status'] = $row['status'] === 'active' ? 'Active' : ucfirst((string)$row['status']);
            $row['completeness'] = $this->calculateCompleteness($row)['percent'];
            // Raw checklist-only columns (some of them ciphertext) never need to reach the frontend
            // beyond the computed percent above -- drop them from the row DataTables actually renders.
            foreach ($completenessCols as $col) {
                unset($row[$col]);
            }
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
                    tm.team_name_th, tm.team_name_en, tm.client_name AS team_client_name,
                    r.role_name_th, r.role_name_en,
                    p.position_name_th, p.position_name_en,
                    b.branch_name_th, b.branch_name_en,
                    mb.bank_code, mb.bank_name_th, mb.bank_name_en,
                    mn.nationality_name_th, mn.nationality_name_en,
                    mrl.religion_name_th, mrl.religion_name_en,
                    pc.cycle_name,
                    sh.shift_name_th, sh.shift_name_en,
                    wl.location_name_th, wl.location_name_en,
                    CONCAT(rt.name_th, ' ', rt.surname_th) AS report_to_name_th,
                    CONCAT(rt.name_en, ' ', rt.surname_en) AS report_to_name_en,
                    mar.level_1 AS postcode_register, mar.level_2_th AS province_th_register, mar.level_3_th AS district_th_register, mar.level_4_th AS sub_district_th_register,
                    mar.level_2_en AS province_en_register, mar.level_3_en AS district_en_register, mar.level_4_en AS sub_district_en_register,
                    mac.level_1 AS postcode_contact, mac.level_2_th AS province_th_contact, mac.level_3_th AS district_th_contact, mac.level_4_th AS sub_district_th_contact,
                    mac.level_2_en AS province_en_contact, mac.level_3_en AS district_en_contact, mac.level_4_en AS sub_district_en_contact
                FROM `employees` e
                LEFT JOIN `structure_departments` d ON e.department_id = d.id
                LEFT JOIN `structure_teams` tm ON e.team_id = tm.id
                LEFT JOIN `structure_roles` r ON e.role_id = r.id
                LEFT JOIN `structure_positions` p ON e.position_id = p.id
                LEFT JOIN `structure_branches` b ON e.branch_id = b.id
                LEFT JOIN `master_banks` mb ON e.bank_id = mb.id
                LEFT JOIN `master_nationalities` mn ON e.nationality = mn.nationality_code
                LEFT JOIN `master_religions` mrl ON e.religion = mrl.religion_code
                LEFT JOIN `payroll_cycles` pc ON e.cycle_id = pc.id
                LEFT JOIN `shifts` sh ON e.shift_id = sh.id
                LEFT JOIN `master_work_locations` wl ON e.work_location_id = wl.id
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
        $row['completeness'] = $this->calculateCompleteness($row);
        $row['verify_status'] = $this->verifyStatus($row, $this->getCompanyCountry($compId) === 'TH');
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

    private function getCompanyCountry(int $compId): ?string {
        $stmt = $this->db->prepare("SELECT registered_country FROM `companies` WHERE id = :id");
        $stmt->execute([':id' => $compId]);
        $country = $stmt->fetchColumn();
        return $country !== false ? (string)$country : null;
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
        $isThCompany = $this->getCompanyCountry($compId) === 'TH';

        // employee_no is the ONLY field that still blocks a save outright, on every tab -- it's the
        // row's business identity (NOT NULL, no DB default, used as the URL/lookup key everywhere).
        // Every other field below used to be required on EVERY save regardless of which tab was open
        // -- per explicit request (2026-08-19, "แต่ละ Tab อยากให้บันทึกได้แบบอิสระต่อกัน") that blocked
        // saving any single tab independently: a brand-new employee couldn't even save the Info tab
        // because Employment/Salary tab fields weren't filled in yet. Those fields no longer block
        // save() -- they now only determine is_payroll_ready ("Verify Status", see isPayrollReady()
        // below), recomputed fresh on every save from whatever the form currently holds across all
        // tabs (collectEmployeeFormData() in detail.js always submits the whole form, not just the
        // tab that was clicked, so this still reflects the true overall state each time).
        if (empty($data['employee_no'])) {
            return ['status' => false, 'message' => 'Missing required field: employee_no'];
        }

        // master_addresses/tax_calculation_method are Thailand-specific (address picker only has TH
        // data; average/actual annualization only means something for TH withholding tax) -- don't
        // force a TH-only required field on SG/MY/US companies just because they share this form.
        // (The country-aware adjustment itself now lives in missingPayrollFields(), shared with
        // verifyStatus() for display -- this just fills in a sensible default so the DB's NOT NULL
        // column is satisfied outside TH.)
        if (!$isThCompany && empty($data['tax_calculation_method'])) {
            $data['tax_calculation_method'] = 'average'; // DB column is NOT NULL; unused/meaningless outside TH.
        }

        $employeeType = $data['employee_type'] ?? 'domestic';
        if ($employeeType === 'domestic') {
            // Presence is no longer save-blocking (see isPayrollReady() below) -- only checksum is
            // still enforced, and only when a value was actually provided.
            if (!empty($data['id_card_no']) && $isThCompany && !$this->isValidThaiId((string)$data['id_card_no'])) {
                return ['status' => false, 'message' => 'Invalid Thai ID card number.'];
            }
        } elseif ($employeeType !== 'foreigner') {
            return ['status' => false, 'message' => 'Invalid employee_type.'];
        }

        // TH mobile numbers are always 9-10 digits; outside TH just accept a plausible-length
        // international mobile number (e.g. Singapore is 8 digits) rather than assuming TH's format.
        // Presence is no longer save-blocking -- only format is still enforced, and only when a value
        // was actually provided, same as id_card_no's checksum check above.
        $mobilePattern = $isThCompany ? '/^\d{9,10}$/' : '/^\d{7,15}$/';
        if (!empty($data['mobile_no']) && !preg_match($mobilePattern, (string)$data['mobile_no'])) {
            return ['status' => false, 'message' => 'Invalid mobile number.'];
        }
        if (!empty($data['emergency_mobile']) && !preg_match($mobilePattern, (string)$data['emergency_mobile'])) {
            return ['status' => false, 'message' => 'Invalid emergency contact mobile number.'];
        }
        if (!empty($data['personal_email']) && !filter_var($data['personal_email'], FILTER_VALIDATE_EMAIL)) {
            return ['status' => false, 'message' => 'Invalid personal email address.'];
        }

        $fkChecks = [
            'department_id' => 'structure_departments',
            'team_id' => 'structure_teams',
            'role_id' => 'structure_roles',
            'position_id' => 'structure_positions',
            'branch_id' => 'structure_branches',
        ];
        foreach ($fkChecks as $field => $table) {
            if (!empty($data[$field]) && !$this->referenceExists($table, (int)$data[$field], $compId)) {
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
        // These DB columns are NOT NULL but have a real DEFAULT (unlike the 16 columns relaxed to
        // nullable in database/payroll.sql for independent tab saving -- there's no meaningful "not
        // yet decided" state for e.g. employee_type/gender/payment_type, they always have a sensible
        // default value). Found while adding independent-tab-save support (2026-08-19): once a save
        // could omit these, the generic branch below coerced empty -> null and the INSERT sent an
        // explicit NULL, which overrides the DB's own DEFAULT and throws a NOT NULL violation instead
        // of quietly falling back to it. Coerce to the same default here so that never happens.
        $columnDefaults = [
            'employee_type' => 'domestic', 'employee_status' => 'active', 'gender' => 'male',
            'payment_type' => 'bank', 'salary_type' => 'monthly', 'base_salary_amount' => '0.00',
            'mobile_country_code' => '+66',
        ];
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
            if ($val === null && array_key_exists($col, $columnDefaults)) {
                $val = $columnDefaults[$col];
            }
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
        // "Verify Status" (2026-08-19, explicit request): is_payroll_ready is now computed fresh on
        // every save from whatever the form currently holds across ALL tabs, instead of hardcoded to
        // 1 (which was only safe under the old all-or-nothing requiredColumns() gate above -- once
        // that gate was relaxed to support independent tab saving, hardcoding this would have wrongly
        // marked a part-filled employee "ready" the moment any single tab was saved). Not part of
        // allColumns(), so origami_ref_id/origami_sso_user_key are never touched by this generic form.
        $values['is_payroll_ready'] = $this->isPayrollReady($values, $isThCompany) ? 1 : 0;

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
