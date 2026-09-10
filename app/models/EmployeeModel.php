<?php
declare(strict_types=1);
require_once __DIR__ . '/EmployeeOtRateModel.php';
require_once __DIR__ . '/PayrollPolicyModel.php';
require_once __DIR__ . '/EmployeePaymentMethodModel.php';
require_once __DIR__ . '/EmployeeForeignWorkerDetailModel.php';
require_once __DIR__ . '/AuditLogModel.php';
class EmployeeModel {
    private $db;
    private AuditLogModel $auditLog;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
        $this->auditLog = new AuditLogModel($this->db);
    }

    /** Same traversal-proofing pattern as CompanyProfileModel::isValidLogoPath()/
     *  isValidSignaturePath() -- one folder per company, not per employee (the random 32-hex filename
     *  is already unguessable, a per-employee subfolder wasn't needed for isolation). */
    public static function isValidSignaturePath(?string $path, int $compId): bool {
        if ($path === null || $path === '') {
            return true;
        }
        $pattern = '#^public/uploads/employee_signatures/' . $compId . '/[a-f0-9]{32}\.(jpg|png|svg)$#';
        return (bool)preg_match($pattern, $path);
    }

    /** Same pattern as isValidSignaturePath() above, own folder (employee_photos, not signatures). */
    public static function isValidPhotoPath(?string $path, int $compId): bool {
        if ($path === null || $path === '') {
            return true;
        }
        $pattern = '#^public/uploads/employee_photos/' . $compId . '/[a-f0-9]{32}\.(jpg|png|svg)$#';
        return (bool)preg_match($pattern, $path);
    }

    /**
     * Decrypts employees.base_salary_amount, tolerating a row that was never actually encrypted (a
     * raw INSERT bypassing save() -- e.g. every test fixture in tests/*.php that inserts directly
     * into `employees` for speed, or PayrollSyncModel/EmployeeSyncer's own placeholder-employee
     * INSERTs, though those two never set this column at all so they hit the null branch, not this
     * fallback). EncryptionService::decrypt() already returns null (not an exception) for malformed
     * ciphertext BY DESIGN -- this just adds one more fallback on top of that specifically for this
     * column: if it wasn't valid ciphertext but IS a plain numeric string, treat it as an
     * already-plaintext legacy/test value instead of silently collapsing to 0. This does NOT weaken
     * the actual encryption guarantee -- every real write path (EmployeeModel::save(), the only place
     * application code ever writes this column) always encrypts, so this fallback only ever fires for
     * data that was never encrypted in the first place.
     */
    public static function decryptSalaryValue(?string $raw, ?int $keyVersion): string {
        if ($raw === null || $raw === '') {
            return '0.00';
        }
        $decrypted = EncryptionService::decrypt($raw, $keyVersion);
        if ($decrypted !== null) {
            return $decrypted;
        }
        return is_numeric($raw) ? $raw : '0.00';
    }

    private function allColumns(): array {
        return [
            'employee_no', 'profile_photo_path',
            // 2026-09-03, Platform Hardening Phase 5A/5B: file_size persisted for every upload site
            // app-wide; thumbnail_path additionally set for profile photos (jpg/png only -- GD
            // cannot rasterize SVG, so an SVG-uploaded photo leaves this NULL) -- see
            // ThumbnailGenerator's own docblock.
            'profile_photo_file_size', 'profile_photo_thumbnail_path',
            // 2026-08-26, explicit request: "ในการจัดการพนักงาน เพิ่มการเก็บลายเซ็นต์ของพนักงานแต่ละคนได้"
            // -- uploaded via a separate endpoint (EmployeeController::uploadSignature(), same
            // upload-then-hidden-field convention as Company Profile's own signature_path), plain
            // passthrough column here like profile_photo_path above.
            'signature_path', 'signature_file_size',
            // 2026-08-30 (Phase 3, T020, explicit request: field "จ่าย/ไม่จ่ายเงินเดือน", default = จ่าย)
            // -- boolean, see booleanColumns() below. 0 excludes this employee from payroll entirely
            // (missingPayrollFields()/calculateCompleteness() below, PayrollRunModel eligibility for
            // T021) and hides payroll-specific fields/tabs on the Detail form (detail.js).
            'is_payroll_participant',
            'employee_type', 'employee_status', 'title', 'gender', 'name_th', 'surname_th', 'name_en', 'surname_en',
            'nickname_th', 'nickname_en', 'date_of_birth', 'nationality', 'religion', 'marital_status', 'military_status',
            'id_card_no', 'id_card_expire_date', 'tax_id_no', 'passport_no', 'passport_expire_date',
            // 2026-09-02, extends the earlier Origami candidates.php field batch (passport_no/
            // work_permit_no/visa_type already existed) -- field shapes confirmed directly from
            // Origami's own candidates.php source (passport{}/visa{}/work_permit{} blocks), not
            // guessed. Not encrypted -- same tier as work_permit_no (a plain ID-ish field, not
            // id_card_no/tax_id_no/passport_no's own encrypted tier).
            'passport_issued_place', 'passport_issue_date',
            'work_permit_no', 'date_work_permit_issue', 'date_work_permit_expire', 'work_permit_issued_place',
            'visa_type', 'visa_no', 'visa_issued_place', 'visa_issue_date', 'date_visa_expire',
            // 2026-09-02, explicit request following an AskUserQuestion exchange: a plain
            // per-employee flag, only affects PIT withholding when the company enables + configures
            // a flat rate in Tax & Statutory settings (NonResidentTaxSettingModel) -- see that
            // model's own docblock. Boolean, see booleanColumns() below.
            'tax_non_resident',
            'company_email', 'office_tel', 'send_signin_email', 'personal_email', 'mobile_no', 'mobile_country_code', 'send_preboarding_email', 'line_id',
            'address_line_1_register', 'address_line_2_register', 'master_address_id_register',
            'use_register_address', 'address_line_1_contact', 'address_line_2_contact', 'master_address_id_contact',
            // 2026-08-26, explicit request: "ส่วนของที่อยู่ให้เพิ่มสามารถปักหมุด Location บน Map ได้" --
            // ONE pin for the contact address specifically (where the employee can actually be
            // reached, unlike the register address which is often a permanent household record) --
            // OpenStreetMap/Leaflet, plain decimal columns, nullable (a pin is optional, not required).
            'address_latitude', 'address_longitude',
            'emergency_name', 'emergency_surname', 'emergency_relationship', 'emergency_mobile',
            'department_id', 'team_id', 'role_id', 'position_id', 'branch_id', 'work_location_id', 'shift_id', 'cycle_id',
            'employment_date', 'employment_status', 'employment_status_effective_date', 'employment_end_date', 'employment_end_reason',
            // 2026-08-31, explicit request: internship pay conditions -- per-employee override of
            // company_payroll_policies.intern_base_salary_ratio (Salary tab, only meaningful/shown
            // while employment_type=internship). NULL = no override, use the company default.
            // 2026-09-02, explicit request: Probation never had the per-employee ratio override
            // Internship already has -- same "NULL = use company default" convention.
            'employment_type', 'employment_type_id', 'intern_base_salary_ratio_override', 'probation_base_salary_ratio_override',
            // 2026-09-02, follow-up to close a review-flagged gap: "ตั้งค่าแยกเฉพาะบุคคลนี้" must cover
            // EVERY field the company policy has ("ครบทุกช่อง ไม่ตัดทอน"), not just the ratio -- see
            // each column's own migration comment for the NULL="use company default" convention.
            'probation_defer_pvd_override', 'probation_defer_sso_override', 'probation_defer_recurring_earning_override',
            'probation_leave_days_limit_override', 'probation_allow_leave_override', 'probation_period_days_override',
            'intern_defer_pvd_override', 'intern_defer_sso_override', 'intern_defer_recurring_earning_override',
            'intern_leave_days_limit_override', 'intern_allow_leave_override', 'intern_period_days_override',
            'report_to_id', 'date_contract_expire',
            'holiday_calendar_id', 'driver_license_no', 'workforce_type', 'record_time_method',
            // 2026-09-02, explicit request: payment method type (transfer/cash/check/mixed) --
            // payment_method_id (master_payment_methods lookup) is the source of truth. The old
            // employees.payment_type enum('bank','cash') mirror this was kept-in-sync with (as a
            // migration bridge) has since been DROPPED entirely (see
            // database/migrations/2026-09-02_19_drop_legacy_payment_type.sql) -- every read site
            // that used to fall back to it now reads payment_method_code (joined from
            // master_payment_methods) instead.
            'payment_method_id', 'bank_id', 'bank_account_no', 'bank_account_name', 'bank_branch',
            // 2026-09-02, explicit request: "ในหน้าพนักงาน ก็ต้องมี Tab setup ส่วนนี้เพิ่มเติมว่ารับเงิน
            // ผ่านบัญชีไหน" -- which of the COMPANY's own settlement accounts (bank_accounts) normally
            // pays this employee, NOT bank_id above (the employee's own personal receiving account).
            // Optional -- see PayrollRunEmployeeBankAccountModel::resolveForRun()'s own docblock for
            // the full precedence chain this feeds into.
            'default_bank_account_id',
            'salary_type', 'base_salary_amount', 'salary_effective_date', 'ot_eligible', 'ot_rate_source', 'tax_calculation_method', 'tax_exempt',
            'sso_enrolled', 'sso_no', 'sso_hospital_id', 'sso_start_date', 'sso_contribution_rate', 'sso_employer_contribution_rate',
            'pvd_enrolled', 'pvd_fund_name', 'pvd_start_date', 'pvd_employee_rate', 'pvd_employer_rate',
            'insurance_plan_id', 'insurance_start_date',
            'has_spouse', 'spouse_name', 'spouse_id_card_no',
        ];
    }

    private function booleanColumns(): array {
        return ['send_signin_email', 'send_preboarding_email', 'use_register_address', 'ot_eligible', 'tax_exempt',
                'sso_enrolled', 'pvd_enrolled', 'has_spouse', 'is_payroll_participant', 'tax_non_resident'];
    }

    private function intColumns(): array {
        return ['department_id', 'team_id', 'role_id', 'position_id', 'branch_id', 'work_location_id', 'shift_id', 'cycle_id',
                'report_to_id', 'holiday_calendar_id', 'bank_id', 'default_bank_account_id', 'payment_method_id', 'sso_hospital_id', 'insurance_plan_id',
                'master_address_id_register', 'master_address_id_contact', 'employment_type_id',
                'profile_photo_file_size', 'signature_file_size'];
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
            // 2026-08-26, explicit request: "ตัวข้อมูลเงินเดือนตอนนี้ เก็บเป็นตัวเลขตรงๆ ไม่ต้องการให้เห็น
            // ตัวเลขตรงๆในฐานข้อมูลครับ" -- confirmed via AskUserQuestion to start with the single most
            // sensitive per-employee value first (base salary), NOT every monetary column system-wide:
            // base_salary_amount is read once per employee into PHP for payroll calculation and is
            // NEVER used in SQL-side SUM/WHERE/ORDER BY anywhere in this codebase (verified by
            // grepping every query referencing it before making this change), so encrypting it here
            // carries none of the "every report/aggregation query needs rewriting" risk that the same
            // treatment would carry for payroll_run_details/payroll amounts -- see this project's own
            // CLAUDE.md for why THAT is deliberately a separate, not-yet-started phase. No hash column
            // -- there's no legitimate reason to look an employee up BY their exact salary.
            'base_salary_amount' => null,
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
    // 2026-08-28, explicit request: "ตรง Role อาจจะไม่ต้อง Require Field เพราะระบบนี้เข้ามาใช้งานได้แค่
    // บางส่วน" -- role_id removed from this list (was between department_id and position_id) since
    // it governs Payroll's own system PERMISSIONS (PermissionModel), not payroll eligibility -- same
    // "optional, excluded from required/completeness entirely" precedent this file already
    // established for team_id (see detail.php's own Team section comment). Still nullable in schema
    // either way (`role_id int(11) DEFAULT NULL`), so no migration was needed.
    private function requiredColumns(): array {
        // 2026-08-30 (T023, explicit request: "นามสกุลไม่เป็น required field") -- surname_th/surname_en
        // dropped from this list. First name (name_th/name_en) stays required; a missing surname no
        // longer blocks is_payroll_ready/"Verify Status" (see missingPayrollFields()/verifyStatus()
        // below, both driven off this list) or shows up in a Recheck-tab-style missing-field flag.
        return [
            'employee_no', 'employee_type', 'employee_status', 'title', 'gender', 'name_th', 'name_en',
            'date_of_birth', 'nationality', 'personal_email', 'mobile_no',
            'department_id', 'position_id', 'branch_id',
            'employment_date', 'employment_status', 'employment_type', 'payment_method_id',
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
        // 2026-08-30 (T023): surname_th/surname_en dropped -- calculateCompleteness() no longer
        // reads them (surname is not required, see requiredColumns()'s own comment), so selecting
        // them here would just be dead weight on every list() page load.
        return [
            // 2026-08-30 (T020): needed by calculateCompleteness() to auto-pass payroll-specific
            // checklist items for a staff-only employee.
            'is_payroll_participant',
            // 2026-08-30, real bug found and fixed (caught while building recheckList() for T018,
            // but this ALSO affects list()'s own long-standing Salary-tab completeness % below --
            // base_salary_amount has been AES-256-GCM ciphertext since 2026-08-26 (see
            // encryptedColumns()), but calculateCompleteness()'s own `(float)(...) > 0` threshold
            // check needs the real plaintext number, not just presence -- a non-numeric ciphertext
            // string casts to 0.0, so this check has silently read as "not filled" on every row ever
            // since salary encryption shipped. key_version is needed to decrypt it (see list()'s own
            // decryption step, right before calculateCompleteness() is called).
            'key_version',
            'employee_type', 'title', 'gender', 'name_th', 'name_en',
            'date_of_birth', 'nationality', 'id_card_no', 'tax_id_no', 'passport_no', 'work_permit_no',
            'personal_email', 'mobile_no', 'line_id',
            'department_id', 'position_id', 'branch_id', 'work_location_id', 'shift_id',
            'employment_date', 'payment_method_id', 'bank_id', 'bank_account_no',
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
     * Conditional checks (identification shape by employee_type, bank details only when the
     * resolved payment method is transfer/mixed, SSO/spouse detail only when that enrollment/
     * checkbox is on) adapt the checklist per employee rather than penalizing a field that plainly
     * doesn't apply to them.
     */
    /**
     * 2026-09-02, follow-up cleanup: employees.payment_type (the legacy enum mirror) has been
     * dropped entirely (see database/migrations/2026-09-02_19_drop_legacy_payment_type.sql) --
     * calculateCompleteness()'s own bankOk check below (and a couple of other per-row readers
     * elsewhere in this file) need to know which master_payment_methods CODE a raw
     * payment_method_id resolves to, without either hardcoding the seeded ids (fragile) or joining
     * master_payment_methods into every list()/recheckList() SELECT (real per-row query cost for a
     * tiny, effectively-static lookup table). Fetched once per request and cached on the instance --
     * this table only ever has a handful of rows (see its own migration's seed).
     */
    private ?array $paymentMethodCodesById = null;
    private function paymentMethodCode(?int $methodId): ?string {
        if ($methodId === null) {
            return null;
        }
        if ($this->paymentMethodCodesById === null) {
            $this->paymentMethodCodesById = [];
            $stmt = $this->db->query("SELECT id, code FROM `master_payment_methods`");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $this->paymentMethodCodesById[(int)$row['id']] = (string)$row['code'];
            }
        }
        return $this->paymentMethodCodesById[$methodId] ?? null;
    }

    public function calculateCompleteness(array $e): array {
        // 2026-08-30 (T020) -- payroll-specific checklist items (bank details within Employment,
        // and the whole Salary/Social Security/Family-Tax Allowance tabs) auto-pass for a staff-only
        // employee, same reasoning as missingPayrollFields()'s own early-return just above this
        // method: none of that data applies to them, so it shouldn't read as "incomplete" on a
        // completeness bar the Recheck tab (T018)/Employee Detail page both show. Info/Contact and
        // Employment's own org-placement checks (department/position/branch/work_location/shift/
        // employment_date) are UNCHANGED -- those still matter for staff-only records too.
        $isPayrollParticipant = (int)($e['is_payroll_participant'] ?? 1) === 1;
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
            // 2026-08-30 (T023): surname is no longer required -- checks first name alone now, so an
            // employee with a genuinely blank surname (not a required field's own placeholder-empty
            // state) doesn't get unfairly dinged on this checklist item.
            $this->isCompletenessValueFilled($e['name_th'] ?? null),
            $this->isCompletenessValueFilled($e['name_en'] ?? null),
            $this->isCompletenessValueFilled($e['date_of_birth'] ?? null),
            $this->isCompletenessValueFilled($e['nationality'] ?? null),
            $identificationOk,
        ]);
        $tabs['contact'] = $this->scoreChecklist([
            $this->isCompletenessValueFilled($e['personal_email'] ?? null),
            $this->isCompletenessValueFilled($e['mobile_no'] ?? null),
            $this->isCompletenessValueFilled($e['line_id'] ?? null),
        ]);
        // 2026-09-02: bank details are required whenever the resolved method is 'transfer' or
        // 'mixed' (a mixed set might route through transfer -- see detail.js's own
        // applyPaymentMethodVisibility(), which shows the same #sectionBankPayment fields for
        // both), not just an exact 'bank' string match against the now-dropped legacy column.
        $resolvedPaymentMethodCode = $this->paymentMethodCode(isset($e['payment_method_id']) ? (int)$e['payment_method_id'] : null);
        $bankOk = !$isPayrollParticipant || !in_array($resolvedPaymentMethodCode, ['transfer', 'mixed'], true)
            ? true
            : ($this->isCompletenessValueFilled($e['bank_id'] ?? null) && $this->isCompletenessValueFilled($e['bank_account_no'] ?? null));
        $tabs['employment'] = $this->scoreChecklist([
            !empty($e['department_id']), !empty($e['position_id']), !empty($e['branch_id']),
            !empty($e['work_location_id']), !empty($e['shift_id']),
            $this->isCompletenessValueFilled($e['employment_date'] ?? null),
            $bankOk,
        ]);
        $tabs['salary'] = $this->scoreChecklist([
            !$isPayrollParticipant || $this->isCompletenessValueFilled($e['salary_type'] ?? null),
            !$isPayrollParticipant || (float)($e['base_salary_amount'] ?? 0) > 0,
            !$isPayrollParticipant || $this->isCompletenessValueFilled($e['salary_effective_date'] ?? null),
            !$isPayrollParticipant || $this->isCompletenessValueFilled($e['tax_calculation_method'] ?? null),
        ]);
        $tabs['social'] = $this->scoreChecklist([
            !$isPayrollParticipant || empty($e['sso_enrolled']) || $this->isCompletenessValueFilled($e['sso_no'] ?? null),
        ]);
        $tabs['family'] = $this->scoreChecklist([
            !$isPayrollParticipant || empty($e['has_spouse']) || $this->isCompletenessValueFilled($e['spouse_name'] ?? null),
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
            'name_th' => 'info', 'name_en' => 'info',
            'date_of_birth' => 'info', 'nationality' => 'info', 'id_card_no' => 'info',
            'tax_id_no' => 'info', 'passport_no' => 'info', 'work_permit_no' => 'info',
            'personal_email' => 'contact', 'mobile_no' => 'contact',
            'department_id' => 'employment', 'position_id' => 'employment', 'branch_id' => 'employment',
            'employment_date' => 'employment', 'employment_status' => 'employment', 'employment_type' => 'employment',
            'payment_method_id' => 'employment', 'bank_id' => 'employment', 'bank_account_no' => 'employment',
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
        // 2026-08-30 (T020, explicit request: field "จ่าย/ไม่จ่ายเงินเดือน") -- a staff-only (not paid
        // through payroll) employee is never "missing" anything payroll-related, because none of it
        // applies to them at all; "ready for payroll" is a moot question, not a failing one. This is
        // also what keeps a staff-only employee's is_payroll_ready from flipping to 0 the moment
        // is_payroll_participant is turned off, which would otherwise misleadingly flag them as an
        // incomplete profile on the Recheck tab (T018)/Employee Detail's own Verify Status badge.
        if (empty($values['is_payroll_participant']) && array_key_exists('is_payroll_participant', $values)) {
            return [];
        }
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
        // 2026-09-02: resolved via payment_method_id (employees.payment_type, the old enum mirror,
        // has been dropped -- see this file's own paymentMethodCode() docblock).
        $missingFieldsPaymentMethodCode = $this->paymentMethodCode(isset($values['payment_method_id']) ? (int)$values['payment_method_id'] : null);
        if (in_array($missingFieldsPaymentMethodCode, ['transfer', 'mixed'], true)) {
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

    private const LIST_JOINS = "FROM `employees` e
                    LEFT JOIN `structure_roles` r ON e.role_id = r.id
                    LEFT JOIN `structure_positions` p ON e.position_id = p.id
                    LEFT JOIN `structure_departments` d ON e.department_id = d.id
                    LEFT JOIN `structure_teams` tm ON e.team_id = tm.id
                    LEFT JOIN `shifts` sh ON e.shift_id = sh.id
                    LEFT JOIN `structure_branches` b ON e.branch_id = b.id";

    /** Batch 3A item 4 -- lightweight lookup for the app.js quick-view modal opened by clicking an
     *  employee avatar (Process List's Created/Updated By, Process Detail's employee table, the
     *  Approval Timeline modal). Deliberately NOT the full get() (that method decrypts/returns many
     *  fields no quick-view popup needs, keyed by employee_no not id besides). */
    public function quickView(int $compId, int $employeeId): ?array {
        $sql = "SELECT e.id, e.employee_no, e.name_th, e.surname_th, e.name_en, e.surname_en,
                    e.profile_photo_path, e.employee_status,
                    d.department_name_th, d.department_name_en,
                    p.position_name_th, p.position_name_en,
                    b.branch_name_th, b.branch_name_en
                " . self::LIST_JOINS . "
                WHERE e.id = :id AND e.comp_id = :comp_id AND e.deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Maps this list's DataTables column keys to their real SQL expression -- shared by list()'s
     *  own SELECT and listColumnValues()'s DISTINCT lookup, so the two can never quietly drift out
     *  of sync (e.g. the Excel-style filter offering a value list() itself would never actually
     *  match, or vice versa). Language-dependent columns (role/position/department/team/shift/
     *  branch, each backed by a *_th/*_en pair) resolve to whichever the caller's own $lang picked. */
    private function listColumnExprMap(string $lang): array {
        $nameCol = $lang === 'en' ? 'role_name_en' : 'role_name_th';
        $deptCol = $lang === 'en' ? 'department_name_en' : 'department_name_th';
        $branchCol = $lang === 'en' ? 'branch_name_en' : 'branch_name_th';
        $shiftCol = $lang === 'en' ? 'shift_name_en' : 'shift_name_th';
        $teamCol = $lang === 'en' ? 'team_name_en' : 'team_name_th';
        $positionCol = $lang === 'en' ? 'position_name_en' : 'position_name_th';
        // 2026-08-29, real bug found and fixed (explicit report: "แสดง ชื่อ และข้อมูลอื่นๆตามภาษาที่เลือก
        // Auto เปลี่ยนโดยไม่ต้อง Reload หน้า" -- Name specifically never actually followed $lang at
        // all, unlike every other bilingual column here (role/position/department/team/shift/
        // branch all correctly branch on $lang via *Col above) -- this one was hardcoded to
        // name_th/surname_th regardless. Switching the language dropdown to EN never changed the
        // Name column, only the column HEADERS (those are separate i18n text via data-i18n,
        // unrelated to this SQL expression). Fixed to branch on $lang the same way as everything
        // else in this map, with a fallback to the Thai name when name_en/surname_en are BOTH
        // blank (NULLIF against a literal single space catches "both concatenated to empty" --
        // CONCAT('', ' ', '') = ' ', not NULL) -- same "graceful EN fallback" precedent this app's
        // own client-side name-display helpers already use (e.g. dashEmployeeDisplayName()).
        $nameExpr = $lang === 'en'
            ? "COALESCE(NULLIF(TRIM(CONCAT(e.name_en, ' ', e.surname_en)), ''), CONCAT(e.name_th, ' ', e.surname_th))"
            : "CONCAT(e.name_th, ' ', e.surname_th)";
        return [
            'employee_no' => 'e.employee_no',
            'name' => $nameExpr,
            'phone' => 'e.mobile_no',
            'email' => 'e.personal_email',
            'role' => "r.{$nameCol}",
            'position' => "p.{$positionCol}",
            'department' => "d.{$deptCol}",
            'team' => "tm.{$teamCol}",
            'shift' => "sh.{$shiftCol}",
            'branch' => "b.{$branchCol}",
            'start_work_date' => 'e.employment_date',
            'status' => 'e.employee_status',
            // 2026-08-31, explicit request: badge column for the List table's own "จ่ายเงินเดือน"
            // filter -- raw here (0/1), same as 'status' above; both listColumnValues() and
            // buildListWhere()'s column_filters loop special-case this key into human-readable
            // "Yes"/"No" text for the Excel-style filter dropdown, same precedent as 'status'
            // there (also not localized at this layer -- matches that existing precedent, not a
            // new gap this change introduces).
            'payroll_participant' => 'e.is_payroll_participant',
        ];
    }

    /**
     * Builds the shared WHERE clause + bound params for both list() and listColumnValues() --
     * 2026-08-27, explicit request: "ในตารางทุกตาราง...เพิ่มให้สามารถ Filter ได้...เหมือนกับ Excel" (the
     * Excel-style per-column header filter, proof-of-concept on this table first). `filters` carries
     * the ORIGINAL station filters (status/employment_status/role_id/department_id/team_id/
     * shift_id/branch_id/created_date_from/created_date_to) exactly as before, PLUS a new
     * `column_filters` entry: `[columnKey => [selected values...]]`, one IN(...) clause appended per
     * non-empty entry (AND'd together, same as Excel's own "each column's filter narrows further"
     * semantics). `$excludeColumnFilter`, when set, skips that ONE column's own entry in
     * `column_filters` -- this is what makes opening column X's own filter dropdown show every value
     * X could possibly hold (not just the ones its own currently-checked selection already narrowed
     * to), while still respecting every OTHER active filter -- genuine Excel behavior, not a
     * simplification.
     */
    private function buildListWhere(int $compId, array $filters, string $search, string $lang, ?string $excludeColumnFilter = null): array {
        $exprMap = $this->listColumnExprMap($lang);
        $where = "e.comp_id = :comp_id AND e.deleted_at IS NULL";
        $params = [':comp_id' => $compId];

        if (!empty($filters['status'])) {
            $where .= " AND e.employee_status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['employment_status'])) {
            $where .= " AND e.employment_status = :employment_status";
            $params[':employment_status'] = $filters['employment_status'];
        }
        if (!empty($filters['role_id'])) {
            $where .= " AND e.role_id = :role_id";
            $params[':role_id'] = (int)$filters['role_id'];
        }
        if (!empty($filters['department_id'])) {
            $where .= " AND e.department_id = :department_id";
            $params[':department_id'] = (int)$filters['department_id'];
        }
        if (!empty($filters['team_id'])) {
            $where .= " AND e.team_id = :team_id";
            $params[':team_id'] = (int)$filters['team_id'];
        }
        if (!empty($filters['shift_id'])) {
            $where .= " AND e.shift_id = :shift_id";
            $params[':shift_id'] = (int)$filters['shift_id'];
        }
        if (!empty($filters['branch_id'])) {
            $where .= " AND e.branch_id = :branch_id";
            $params[':branch_id'] = (int)$filters['branch_id'];
        }
        // 2026-08-30 (Phase 3, T022) -- !empty() would silently never apply this filter for value
        // '0' (No Salary), unlike every other filter above (all FK ids/non-empty-string enums,
        // where '0' is never a real value) -- checked explicitly instead.
        if (isset($filters['is_payroll_participant']) && $filters['is_payroll_participant'] !== '') {
            $where .= " AND e.is_payroll_participant = :is_payroll_participant";
            $params[':is_payroll_participant'] = (int)$filters['is_payroll_participant'];
        }
        if (!empty($filters['created_date_from'])) {
            $where .= " AND DATE(e.created_at) >= :created_date_from";
            $params[':created_date_from'] = $filters['created_date_from'];
        }
        if (!empty($filters['created_date_to'])) {
            $where .= " AND DATE(e.created_at) <= :created_date_to";
            $params[':created_date_to'] = $filters['created_date_to'];
        }
        if ($search !== '') {
            $where .= " AND (e.employee_no LIKE :search1 OR e.name_th LIKE :search2 OR e.surname_th LIKE :search3 OR e.name_en LIKE :search4 OR e.surname_en LIKE :search5 OR e.personal_email LIKE :search6)";
            for ($i = 1; $i <= 6; $i++) {
                $params[":search{$i}"] = "%{$search}%";
            }
        }

        $colFilters = $filters['column_filters'] ?? [];
        $paramIdx = 0;
        foreach ($colFilters as $col => $values) {
            if ($col === $excludeColumnFilter || !isset($exprMap[$col]) || !is_array($values) || empty($values)) {
                continue;
            }
            $values = array_values(array_filter($values, fn($v) => $v !== null && $v !== ''));
            if (empty($values)) {
                continue;
            }
            $expr = $col === 'status'
                ? "IF(e.employee_status = 'active', 'Active', CONCAT(UCASE(LEFT(e.employee_status,1)), SUBSTRING(e.employee_status,2)))"
                : ($col === 'payroll_participant' ? "IF(e.is_payroll_participant = 1, 'Yes', 'No')" : $exprMap[$col]);
            $placeholders = [];
            foreach ($values as $v) {
                $paramIdx++;
                $ph = ":cf{$paramIdx}";
                $placeholders[] = $ph;
                $params[$ph] = (string)$v;
            }
            $where .= " AND {$expr} IN (" . implode(', ', $placeholders) . ")";
        }

        return [$where, $params];
    }

    /**
     * Per-station employee counts for the Employee tab's own station-card pipeline (Phase 3, T024,
     * explicit request: "แสดงจำนวนพนักงานต่อ station ด้วย") -- respects every
     * OTHER active filter (department/team/shift/branch/role/date range/is_payroll_participant/
     * search) exactly like list() itself, EXCLUDING status/employment_status (those ARE the station
     * selector -- a count scoped to itself would be meaningless). #tb_employee is serverSide:true,
     * so client-side row-counting (the technique Payroll Process's own updateStationCounts() uses)
     * only ever sees the current page's rows, not the true total -- this is a real aggregate query.
     * NOTE (confirmed against the real markup, not assumed): Active/Probation/Resign all read
     * employee_status (which has both an 'active' and a separate 'probation' value); Permanent
     * alone reads employment_status instead -- a pre-existing quirk, not something this method
     * invented or needs to normalize.
     */
    public function stationCounts(int $compId, array $filters, string $search, string $lang = 'th'): array {
        $filters['status'] = '';
        $filters['employment_status'] = '';
        [$whereSql, $params] = $this->buildListWhere($compId, $filters, $search, $lang);
        $sql = "SELECT
                    SUM(CASE WHEN e.employee_status = 'active' THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN e.employee_status = 'probation' THEN 1 ELSE 0 END) AS probation,
                    SUM(CASE WHEN e.employment_status = 'permanent' THEN 1 ELSE 0 END) AS permanent,
                    SUM(CASE WHEN e.employee_status = 'resigned' THEN 1 ELSE 0 END) AS resigned
                " . self::LIST_JOINS . " WHERE {$whereSql}";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'active' => (int)($row['active'] ?? 0),
            'probation' => (int)($row['probation'] ?? 0),
            'permanent' => (int)($row['permanent'] ?? 0),
            'resigned' => (int)($row['resigned'] ?? 0),
        ];
    }

    public function list(int $compId, int $start, int $length, array $filters, string $search, int $colIndex, string $orderDir, string $lang = 'th'): array {
        $exprMap = $this->listColumnExprMap($lang);
        // 2026-08-26, explicit request: "เิ่ม position กับเบอร์โทรเข้าตาราง" -- Phone inserted right
        // after Name (contact info clustered with identity), Position inserted right after Role (org
        // placement clustered together) -- same "inserting a column mid-list shifts every later
        // index by one" convention Team's own addition already established (see CLAUDE.md's Team
        // section). employee_no/name/role/department/team/shift/branch/start_work_date/status/
        // completeness column indices all shift accordingly.
        // 2026-08-27, shifted by +1 again: "ปุ่มที่ expand ตารางเพื่อดูข้อมูลของ column ที่ซ่อน ควรแยกมาเป็น
        // column แรก" -- a new dedicated Responsive expand-control column was inserted at index 0 on
        // the frontend (list.js), pushing every column below down by one again.
        // 2026-08-28, shifted by +1 again from index 4 onward: a new "Source" column (data_source
        // badge, orderable:false) was inserted right after Employee No. -- a non-orderable column
        // still occupies a real slot in DataTables' own column-index numbering (order[0][column]
        // counts ALL columns, not just sortable ones), so every sortable index below it still had
        // to shift even though this new column itself never appears in this map.
        // 2026-08-29, shifted AGAIN: a checkbox column (orderable:false, bulk sync selection) was
        // inserted right after the expand-control column (index 0 -> pushes everything below down
        // by 1), and an Email column (orderable:false, e.personal_email -- already selected, just
        // not previously rendered) was inserted right after Phone (pushes role-onward down by 1
        // MORE, since it lands strictly before them in column order).
        // 2026-08-31, explicit request: a new sortable "จ่ายเงินเดือน" badge column was inserted
        // right after Status (index 16) and before the existing orderable:false Completeness column
        // -- since it's the LAST sortable entry in the run and everything after it in this map was
        // already non-sortable/absent, no OTHER index in this map needed to shift.
        $sortColumns = [
            3 => '`e`.`employee_no`',
            5 => '`e`.`name_th`',
            6 => '`e`.`mobile_no`',
            8 => "`r`.`" . $this->langNameCol($lang, 'role_name') . "`",
            9 => "`p`.`" . $this->langNameCol($lang, 'position_name') . "`",
            10 => "`d`.`" . $this->langNameCol($lang, 'department_name') . "`",
            11 => "`tm`.`" . $this->langNameCol($lang, 'team_name') . "`",
            12 => "`sh`.`" . $this->langNameCol($lang, 'shift_name') . "`",
            13 => "`b`.`" . $this->langNameCol($lang, 'branch_name') . "`",
            14 => '`e`.`employment_date`',
            15 => '`e`.`employee_status`',
            16 => '`e`.`is_payroll_participant`',
        ];
        $sortColumn = $sortColumns[$colIndex] ?? '`e`.`id`';
        $orderDir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';

        // Both COUNT queries now need the same JOINs as the main data query -- column_filters
        // (2026-08-27) can filter on a JOINed display-name column (e.g. d.department_name_th), not
        // just e.*'s own FK id columns like the pre-existing station filters, so a bare
        // `FROM employees e` here would 42S22 the moment any column_filters entry is active.
        [$baseWhere, $baseParams] = $this->buildListWhere($compId, $filters, '', $lang);
        $totalStmt = $this->db->prepare("SELECT COUNT(*) " . self::LIST_JOINS . " WHERE {$baseWhere}");
        $totalStmt->execute($baseParams);
        $recordsTotal = (int)$totalStmt->fetchColumn();

        [$whereSql, $params] = $this->buildListWhere($compId, $filters, $search, $lang);

        $countSql = "SELECT COUNT(*) " . self::LIST_JOINS . " WHERE {$whereSql}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $recordsFiltered = (int)$countStmt->fetchColumn();

        // Completeness columns (e.*) are selected raw/undecrypted alongside the display columns --
        // calculateCompleteness() only ever checks presence on the encrypted ones (id_card_no/
        // tax_id_no/passport_no/bank_account_no/sso_no), never the plaintext, so no per-row
        // decryption cost is paid just to render this list (see completenessColumns()'s docblock).
        $completenessSelect = implode(', ', array_map(fn($c) => "e.`{$c}`", $this->completenessColumns()));
        // 2026-08-31, explicit request: new "จ่ายเงินเดือน" badge column, aliased below to a
        // DIFFERENT key than the bare `is_payroll_participant` completeness column already selected
        // via $completenessSelect -- that one gets stripped from every row before it reaches the
        // frontend (see the unset() loop right after this query, which drops every
        // completenessColumns() key on purpose, is_payroll_participant included), so reusing the
        // same key here would just get silently deleted again the same way.
        $dataSql = "SELECT e.id, e.employee_no, e.data_source, e.origami_ref_id, e.profile_photo_path,
                        {$exprMap['name']} AS name,
                        e.personal_email AS email,
                        {$exprMap['phone']} AS phone,
                        COALESCE({$exprMap['role']}, '') AS role,
                        COALESCE({$exprMap['position']}, '') AS position,
                        COALESCE({$exprMap['department']}, '') AS department,
                        COALESCE({$exprMap['team']}, '') AS team,
                        COALESCE({$exprMap['shift']}, '') AS shift,
                        COALESCE({$exprMap['branch']}, '') AS branch,
                        {$exprMap['start_work_date']} AS start_work_date,
                        {$exprMap['status']} AS status,
                        e.is_payroll_participant AS payroll_participant_flag,
                        {$completenessSelect}
                    " . self::LIST_JOINS . "
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
            // 2026-08-30, real bug fix (see completenessColumns()'s own comment on key_version) --
            // decrypt base_salary_amount to plaintext just for this ONE threshold check, same
            // on-demand-decrypt-at-point-of-use convention as PayrollRunModel::recalculate()'s own
            // identical decryption step.
            $completenessRow = $row;
            $completenessRow['base_salary_amount'] = self::decryptSalaryValue($row['base_salary_amount'] ?? null, isset($row['key_version']) ? (int)$row['key_version'] : null);
            $row['completeness'] = $this->calculateCompleteness($completenessRow)['percent'];
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

    private function langNameCol(string $lang, string $prefix): string {
        return $lang === 'en' ? "{$prefix}_en" : "{$prefix}_th";
    }

    /* ==================== RECHECK TAB (Phase 3, T018, explicit request: "แสดงเป็น column-by-
     * column ว่าข้อมูลจำเป็นสำหรับทำเงินเดือนครบหรือไม่") ==================== */

    /**
     * Every field this employee needs for payroll, each as its own boolean (true = filled) --
     * field-level detail underneath missingPayrollFields()'s flat "missing" list, keyed by column
     * name for direct per-field column rendering on the Recheck tab. Same checks, just exploded to
     * field granularity instead of a single ready/not-ready verdict. $e must carry every column
     * requiredColumns() -- adjusted per $isThCompany -- plus id_card_no/tax_id_no/passport_no/
     * work_permit_no/bank_id/bank_account_no (recheckList() below selects exactly this set).
     * @return array<string,bool>
     */
    public function fieldReadiness(array $e, bool $isThCompany): array {
        $missing = $this->missingPayrollFields($e, $isThCompany);
        $fields = $this->requiredColumns();
        if (!$isThCompany) {
            $fields = array_diff($fields, ['master_address_id_register', 'master_address_id_contact', 'tax_calculation_method']);
        }
        // Conditional fields missingPayrollFields() also evaluates but that aren't in
        // requiredColumns() itself (identification shape varies by employee_type, bank details only
        // apply when the resolved payment method is transfer/mixed) -- surfaced as their own
        // columns too, same reasoning calculateCompleteness() already applies for these exact 2
        // conditions.
        $extra = (($e['employee_type'] ?? 'domestic') === 'foreigner')
            ? ['tax_id_no', 'passport_no', 'work_permit_no']
            : ['id_card_no'];
        if (in_array($this->paymentMethodCode(isset($e['payment_method_id']) ? (int)$e['payment_method_id'] : null), ['transfer', 'mixed'], true)) {
            $extra = array_merge($extra, ['bank_id', 'bank_account_no']);
        }
        $result = [];
        foreach (array_values(array_unique(array_merge($fields, $extra))) as $f) {
            $result[$f] = !in_array($f, $missing, true);
        }
        return $result;
    }

    /** Every column fieldReadiness() might read across BOTH the domestic and foreigner paths, plus
     *  bank details -- the fixed SELECT list recheckList() below always pulls, regardless of any one
     *  row's own employee_type/payment_method_id (which branch fieldReadiness() actually uses at
     *  render time). Deliberately a superset, not conditional per-row -- one query, same shape every time. */
    private function recheckColumns(): array {
        return array_values(array_unique(array_merge($this->requiredColumns(), [
            'id_card_no', 'tax_id_no', 'passport_no', 'work_permit_no', 'bank_id', 'bank_account_no',
        ])));
    }

    /**
     * Server-side DataTables source for the Recheck tab -- one row per employee, each carrying a
     * `field_readiness` map (fieldReadiness() above) + an overall `is_ready` flag, for a column-by-
     * column payroll-data-completeness grid. Reuses the SAME station filters/search as list() (via
     * buildListWhere()) so an admin can narrow this down by department/team/etc. exactly like the
     * main Employee tab. A staff-only employee (is_payroll_participant=0, see T020/T021) is excluded
     * entirely -- nothing here is relevant to them, same reasoning as their exclusion from every
     * payroll report (AnnualIncomeSummaryModel, PayrollReportDataModel::getResignedEmployeesInMonth()).
     *
     * 2026-08-31, explicit request: add a "Remove from Payroll"/"Add Back" action pair to this tab --
     * $participantMode ('participant' default, or 'excluded') lets the SAME query/columns/readiness
     * logic serve a second, opt-in view of the employees this tab normally hides (is_payroll_
     * participant=0) so an admin can find and re-include them, instead of only being able to turn the
     * flag off from Employee Detail's own Salary tab with no way back from here. See
     * setPayrollParticipant() below for the actual toggle.
     */
    public function recheckList(int $compId, int $start, int $length, array $filters, string $search, string $lang = 'th', string $participantMode = 'participant'): array {
        $isThCompany = $this->getCompanyCountry($compId) === 'TH';
        $exprMap = $this->listColumnExprMap($lang);
        // Always forces staff-only (is_payroll_participant=0) employees out, on top of whatever
        // station filters the caller passed in -- same "total = station filters, no search yet"
        // vs. "filtered = station filters + search" split list() itself uses. $participantMode='excluded'
        // flips this to show ONLY the staff-only employees instead (the "Not in Payroll" view).
        $filters['is_payroll_participant'] = $participantMode === 'excluded' ? '0' : '1';
        [$baseWhere, $baseParams] = $this->buildListWhere($compId, $filters, '', $lang);
        $totalStmt = $this->db->prepare("SELECT COUNT(*) " . self::LIST_JOINS . " WHERE {$baseWhere}");
        $totalStmt->execute($baseParams);
        $recordsTotal = (int)$totalStmt->fetchColumn();

        [$whereSql, $params] = $this->buildListWhere($compId, $filters, $search, $lang);

        $countStmt = $this->db->prepare("SELECT COUNT(*) " . self::LIST_JOINS . " WHERE {$whereSql}");
        $countStmt->execute($params);
        $recordsFiltered = (int)$countStmt->fetchColumn();

        $recheckCols = $this->recheckColumns();
        // key_version/ot_eligible/ot_rate_source selected separately (not part of recheckColumns()/
        // requiredColumns() -- none of these are "required field" readiness concepts) -- key_version
        // needed to decrypt base_salary_amount below (same real bug/fix as list()'s own
        // completenessCols); ot_eligible/ot_rate_source needed to build ot_summary below (explicit
        // request: "เพิ่ม Column OT เพิ่มว่าคิดหรือไม่คิด ถ้าคิดคิด Rate ของ OT แต่ละประเภท").
        // team_id/assigned_ot_rate_set_id (2026-08-30, OT Rate Set replacement) needed by
        // EmployeeOtRateModel::summaryForEmployees() below -- department_id/position_id are already
        // in $recheckCols via requiredColumns(), but team_id is deliberately NOT a required field
        // (Team is optional company-wide, see CLAUDE.md's own Team section) so it's never in that
        // list and must be added here explicitly, same as key_version/ot_eligible/ot_rate_source are.
        // 2026-08-31, explicit request: "ตรงหน้าตรวจสอบเหมือนยังขาด ประกันสังคม ทั้งตารางและหน้า Form" --
        // sso_enrolled/sso_no (ciphertext) added the same way ot_eligible/ot_rate_source were --
        // stripped raw below, exposed only as a computed `sso_status` summary (see below) since SSO
        // enrollment is legitimately sometimes false for valid reasons (not every employee must be
        // enrolled), so this is an informational status column, not a pass/fail requiredColumns()
        // entry -- sso_no's ciphertext is only ever checked for IS NULL/NOT NULL here, never decrypted
        // (the actual editable plaintext value the modal shows/saves comes from the SAME
        // api/employee.get call the Recheck modal already makes for every other field).
        $selectCols = implode(', ', array_map(fn($c) => "e.`{$c}`", $recheckCols)) . ', e.key_version, e.ot_eligible, e.ot_rate_source, e.team_id, e.assigned_ot_rate_set_id, e.sso_enrolled, e.sso_no';
        $dataSql = "SELECT e.id, {$exprMap['employee_no']} AS employee_no, {$exprMap['name']} AS name, {$selectCols}
                    " . self::LIST_JOINS . "
                    WHERE {$whereSql}
                    ORDER BY e.employee_no ASC
                    LIMIT :limit OFFSET :offset";
        $dataStmt = $this->db->prepare($dataSql);
        foreach ($params as $key => $val) {
            $dataStmt->bindValue($key, $val);
        }
        $dataStmt->bindValue(':limit', $length, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $start, PDO::PARAM_INT);
        $dataStmt->execute();
        $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        // 2026-08-30, explicit request: "ในข้อมูลบัญชีธนาคาร ให้บอกประเภทการจ่ายเงิน เป็นเงินสุด หรือบัญชี ถ้า
        // บัญชี มีเลขบัญชีหรือยัง" -- the Bank Details column needs the resolved payment method CODE
        // (cash/transfer/check/mixed), not just its readiness boolean, to render that distinction --
        // computed below via the same cached paymentMethodCode() lookup save()/get() already use.
        $otSummaryByEmployee = (new EmployeeOtRateModel($this->db))->summaryForEmployees($data, $compId);

        // 'employee_no'/'payment_method_id' are deliberately excluded from the strip list below --
        // both are also recheckColumns()/requiredColumns() entries (needed by fieldReadiness()'s
        // presence check), but the frontend needs their raw values too (employee_no for row identity/
        // display, already aliased in via $exprMap above under the exact same key; payment_method_id
        // so the Recheck edit modal can pre-select it, and so payment_method_code -- computed below --
        // can drive the Bank Details column's cash-vs-transfer display). key_version/ot_eligible/
        // ot_rate_source are added to the strip list (never needed raw by the frontend -- ot_summary
        // below already carries everything the UI needs from them).
        $rawColsToStrip = array_merge(array_diff($recheckCols, ['employee_no', 'payment_method_id']), ['key_version', 'ot_eligible', 'ot_rate_source', 'team_id', 'assigned_ot_rate_set_id', 'sso_enrolled', 'sso_no']);
        foreach ($data as &$row) {
            $row['base_salary_amount'] = self::decryptSalaryValue($row['base_salary_amount'] ?? null, isset($row['key_version']) ? (int)$row['key_version'] : null);
            $row['payment_method_code'] = $this->paymentMethodCode(isset($row['payment_method_id']) ? (int)$row['payment_method_id'] : null);
            $row['field_readiness'] = $this->fieldReadiness($row, $isThCompany);
            $row['is_ready'] = !in_array(false, $row['field_readiness'], true);
            $row['ot_summary'] = $otSummaryByEmployee[(int)$row['id']] ?? null;
            if (!(bool)($row['sso_enrolled'] ?? false)) {
                $row['sso_status'] = 'not_enrolled';
            } elseif ($row['sso_no'] === null || $row['sso_no'] === '') {
                $row['sso_status'] = 'enrolled_missing_no';
            } else {
                $row['sso_status'] = 'enrolled_complete';
            }
            foreach ($rawColsToStrip as $col) {
                unset($row[$col]);
            }
        }

        return ['total' => $recordsTotal, 'filtered' => $recordsFiltered, 'data' => $data];
    }

    /**
     * 2026-08-31, explicit request: "เพิ่มปุ่มให้นำออกจากการจ่ายเงินเดือน และมีปุ่มเพิ่ม Employee ที่ไม่ทำ
     * จ่ายเงินเดือนกลับเข้ามาทำเงินเดือน" -- a deliberately minimal, single-purpose UPDATE rather than
     * routing through the generic save() pipeline: save() computes is_payroll_ready/completeness and
     * a dozen other derived fields from a full form payload, none of which are relevant to this one
     * explicit toggle action, and going through it would risk save()'s own "absent key keeps existing
     * value" guard (see save()'s own 2026-08-30 comment) silently no-op'ing if this caller ever forgot
     * to also resend every other column. This method only ever touches this one column.
     */
    public function setPayrollParticipant(int $employeeId, int $compId, bool $participant): bool {
        $stmt = $this->db->prepare("UPDATE `employees` SET is_payroll_participant = :val WHERE id = :id AND comp_id = :comp_id");
        return $stmt->execute([':val' => $participant ? 1 : 0, ':id' => $employeeId, ':comp_id' => $compId]);
    }

    /* ==================== STANDING ITEMS SUMMARY TAB (explicit request: "สรุปรวมรายได้รายหักที่
     * หักหรือได้ประจำ รวมถึงฐานเงินและ และรายได้ รายหักที่ได้รับเป็นรอบ ให้แสดงตัวเลขในรอบที่รอจ่าย รอหัก และ
     * บอกด้วยว่า งวดที่เท่าไหร่จากทั้งหมดกี่งวด") ==================== */

    /**
     * Batched per-employee summary of base salary + Recurring Earnings (EmployeeRecurringEarningModel,
     * indefinite/no installment schedule) + the NEXT PENDING installment of every active PED
     * assignment (employee_earning_deductions -- its own total_installments/current_installment
     * columns map directly onto "งวดที่เท่าไหร่จากทั้งหมดกี่งวด", no cycle-date math needed). "รอจ่าย/
     * รอหัก" (pending to be paid/deducted) means each item's own status='active'(recurring)/
     * 'pending'(installment) state -- this is a snapshot of what's CURRENTLY configured to be paid
     * next, not a specific calendar period resolved against any one payroll cycle.
     *
     * Batched via WHERE employee_id IN (...) for BOTH recurring earnings and PED assignments+
     * installments (2 queries total, not N) -- same "prefetch once, not per row" convention
     * EmployeeOtRateModel::summaryForEmployees() already established for this exact page.
     * base_salary_amount is AES-256-GCM ciphertext (can't SUM in SQL), so it's decrypted per row here
     * same as every other consumer of that column.
     * @param int[] $employeeIds
     * @return array<int,array> keyed by employee_id
     */
    private function standingSummaryForEmployees(array $employeeIds, int $compId): array {
        if (empty($employeeIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));

        $stmtBase = $this->db->prepare("SELECT id, base_salary_amount, key_version FROM `employees` WHERE id IN ({$placeholders})");
        $stmtBase->execute($employeeIds);
        $result = [];
        foreach ($stmtBase->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int)$row['id']] = [
                'base_salary_amount' => self::decryptSalaryValue($row['base_salary_amount'] ?? null, isset($row['key_version']) ? (int)$row['key_version'] : null),
                'recurring' => [], 'recurring_total' => 0.0,
                'recurring_deduction' => [], 'recurring_deduction_total' => 0.0,
                'ped_earning' => [], 'ped_earning_total' => 0.0,
                'ped_deduction' => [], 'ped_deduction_total' => 0.0,
            ];
        }

        $today = date('Y-m-d');
        $stmtRecurring = $this->db->prepare("SELECT ere.employee_id, ere.amount, ere.suspended_from, ere.suspended_to,
                pt.item_name_th, pt.item_name_en
            FROM `employee_recurring_earnings` ere
            JOIN `payroll_earning_deduction_types` pt ON pt.id = ere.ped_type_id
            WHERE ere.employee_id IN ({$placeholders}) AND ere.status = 'active' AND ere.deleted_at IS NULL");
        $stmtRecurring->execute($employeeIds);
        foreach ($stmtRecurring->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $employeeId = (int)$row['employee_id'];
            if (!isset($result[$employeeId])) continue;
            $isSuspended = $row['suspended_from'] !== null && $row['suspended_to'] !== null
                && $row['suspended_from'] <= $today && $row['suspended_to'] >= $today;
            if ($isSuspended) continue; // suspended = not pending to be paid this round, excluded entirely.
            $amount = (float)$row['amount'];
            $result[$employeeId]['recurring'][] = ['name_th' => $row['item_name_th'], 'name_en' => $row['item_name_en'], 'amount' => $amount];
            $result[$employeeId]['recurring_total'] += $amount;
        }

        // 2026-08-31, explicit request: "หน้า Employee Detail เพิ่มรายหักประจำด้วยครับ และนำไปเพิ่มใน ตรงสรุป
        // รายได้ประจำ ด้วย" -- direct mirror of the Recurring Earnings query immediately above,
        // against employee_recurring_deductions instead (see EmployeeRecurringDeductionModel).
        $stmtRecurringDed = $this->db->prepare("SELECT erd.employee_id, erd.amount, erd.suspended_from, erd.suspended_to,
                pt.item_name_th, pt.item_name_en
            FROM `employee_recurring_deductions` erd
            JOIN `payroll_earning_deduction_types` pt ON pt.id = erd.ped_type_id
            WHERE erd.employee_id IN ({$placeholders}) AND erd.status = 'active' AND erd.deleted_at IS NULL");
        $stmtRecurringDed->execute($employeeIds);
        foreach ($stmtRecurringDed->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $employeeId = (int)$row['employee_id'];
            if (!isset($result[$employeeId])) continue;
            $isSuspended = $row['suspended_from'] !== null && $row['suspended_to'] !== null
                && $row['suspended_from'] <= $today && $row['suspended_to'] >= $today;
            if ($isSuspended) continue;
            $amount = (float)$row['amount'];
            $result[$employeeId]['recurring_deduction'][] = ['name_th' => $row['item_name_th'], 'name_en' => $row['item_name_en'], 'amount' => $amount];
            $result[$employeeId]['recurring_deduction_total'] += $amount;
        }

        // Every active assignment's NEXT pending installment (lowest installment_no with status=
        // 'pending') -- a correlated subquery, not a JOIN+GROUP BY, since we need exactly ONE row
        // per assignment (the earliest pending one), not every pending installment summed together.
        $stmtPed = $this->db->prepare("SELECT eed.employee_id, eed.total_installments, eed.current_installment,
                COALESCE(pt.item_type, eed.custom_item_type) AS item_type,
                COALESCE(pt.item_name_th, eed.custom_item_name) AS item_name_th,
                COALESCE(pt.item_name_en, eed.custom_item_name) AS item_name_en,
                i.installment_no, i.amount
            FROM `employee_earning_deductions` eed
            LEFT JOIN `payroll_earning_deduction_types` pt ON pt.id = eed.ped_type_id
            JOIN `employee_earning_deduction_installments` i ON i.assignment_id = eed.id AND i.status = 'pending'
                AND i.installment_no = (SELECT MIN(i2.installment_no) FROM `employee_earning_deduction_installments` i2 WHERE i2.assignment_id = eed.id AND i2.status = 'pending')
            WHERE eed.employee_id IN ({$placeholders}) AND eed.status = 'active' AND eed.deleted_at IS NULL");
        $stmtPed->execute($employeeIds);
        foreach ($stmtPed->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $employeeId = (int)$row['employee_id'];
            if (!isset($result[$employeeId])) continue;
            $amount = (float)$row['amount'];
            $item = [
                'name_th' => $row['item_name_th'], 'name_en' => $row['item_name_en'], 'amount' => $amount,
                'installment_no' => (int)$row['installment_no'], 'total_installments' => (int)$row['total_installments'],
            ];
            if ($row['item_type'] === 'earning') {
                $result[$employeeId]['ped_earning'][] = $item;
                $result[$employeeId]['ped_earning_total'] += $amount;
            } else {
                $result[$employeeId]['ped_deduction'][] = $item;
                $result[$employeeId]['ped_deduction_total'] += $amount;
            }
        }

        foreach ($result as &$r) {
            $r['total_earning'] = round($r['base_salary_amount'] + $r['recurring_total'] + $r['ped_earning_total'], 2);
            $r['total_deduction'] = round($r['recurring_deduction_total'] + $r['ped_deduction_total'], 2);
            $r['net_total'] = round($r['total_earning'] - $r['total_deduction'], 2);
        }
        unset($r);
        return $result;
    }

    /**
     * Server-side DataTables source for the Summary tab -- same station filters/search as
     * recheckList(), plus company-wide TOTALS across the whole filtered set (not just the current
     * page -- same "footer reflects every filtered row, not just what's currently rendered"
     * precedent AnnualIncomeSummaryModel's own report totals already established, needed here since
     * this table is serverSide:true so DataTables' own client-side footerCallback would only ever see
     * the current page).
     */
    public function standingSummaryList(int $compId, int $start, int $length, array $filters, string $search, string $lang = 'th'): array {
        $exprMap = $this->listColumnExprMap($lang);
        $filters['is_payroll_participant'] = '1';
        [$baseWhere, $baseParams] = $this->buildListWhere($compId, $filters, '', $lang);
        $totalStmt = $this->db->prepare("SELECT COUNT(*) " . self::LIST_JOINS . " WHERE {$baseWhere}");
        $totalStmt->execute($baseParams);
        $recordsTotal = (int)$totalStmt->fetchColumn();

        [$whereSql, $params] = $this->buildListWhere($compId, $filters, $search, $lang);
        $countStmt = $this->db->prepare("SELECT COUNT(*) " . self::LIST_JOINS . " WHERE {$whereSql}");
        $countStmt->execute($params);
        $recordsFiltered = (int)$countStmt->fetchColumn();

        $allIdsStmt = $this->db->prepare("SELECT e.id " . self::LIST_JOINS . " WHERE {$whereSql}");
        foreach ($params as $key => $val) {
            $allIdsStmt->bindValue($key, $val);
        }
        $allIdsStmt->execute();
        $allFilteredIds = array_map('intval', $allIdsStmt->fetchAll(PDO::FETCH_COLUMN));

        $summaryByEmployee = $this->standingSummaryForEmployees($allFilteredIds, $compId);

        $totals = ['base_salary_amount' => 0.0, 'recurring_total' => 0.0, 'recurring_deduction_total' => 0.0, 'ped_earning_total' => 0.0, 'ped_deduction_total' => 0.0, 'total_earning' => 0.0, 'total_deduction' => 0.0, 'net_total' => 0.0];
        foreach ($summaryByEmployee as $s) {
            foreach ($totals as $key => &$val) {
                $val += $s[$key] ?? 0;
            }
            unset($val);
        }
        foreach ($totals as &$val) {
            $val = round($val, 2);
        }
        unset($val);

        $dataSql = "SELECT e.id, {$exprMap['employee_no']} AS employee_no, {$exprMap['name']} AS name
                    " . self::LIST_JOINS . "
                    WHERE {$whereSql}
                    ORDER BY e.employee_no ASC
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
            $row['summary'] = $summaryByEmployee[(int)$row['id']] ?? null;
        }
        unset($row);

        return ['total' => $recordsTotal, 'filtered' => $recordsFiltered, 'data' => $data, 'totals' => $totals];
    }

    /**
     * Headcount Movement report (2026-09-02, explicit request: "Report คนเข้าคนออกประจำเดือน ประจำปี"),
     * Phase 1 of the Employee Reports plan. Scoped to ONE calendar year at a time (the year picker is
     * the primary filter -- a monthly BREAKDOWN of that year is what the `by_month` series below is
     * for, so there's no separate "month mode"). `hired` = `employment_date` falls in the year;
     * `exited` = `employment_end_date` falls in the year AND `employment_status` is resigned/
     * terminated (an employment_end_date can exist without a status change yet in edge cases -- only
     * counting the 2 real "left the company" statuses avoids over-counting). Unlike
     * standingSummaryList() above, this deliberately does NOT filter to `is_payroll_participant=1`
     * -- headcount movement is an HR metric about every real employee, not a payroll-specific one.
     *
     * `turnover_rate` uses the standard (exits ÷ average headcount) formula -- average headcount
     * approximated as (headcount at the START of the year + headcount at the END of the year) / 2,
     * each a point-in-time snapshot (`employment_date <= X AND (employment_end_date IS NULL OR
     * employment_end_date >= X)`), the simplest defensible average without needing a full daily
     * headcount time series. 0 when the average headcount itself is 0 (a brand-new company with no
     * headcount yet that year) -- never divides by zero.
     *
     * @param array $filters {department_id?: int, branch_id?: int}
     * @return array{summary: array, by_month: array<int,array{month:int,hires:int,exits:int}>, events: array}
     */
    public function headcountMovementReport(int $compId, int $year, array $filters = []): array {
        $yearStart = sprintf('%04d-01-01', $year);
        $yearEnd = sprintf('%04d-12-31', $year);

        $extraWhere = '';
        $extraParams = [];
        if (!empty($filters['department_id'])) {
            $extraWhere .= ' AND department_id = :department_id';
            $extraParams[':department_id'] = (int)$filters['department_id'];
        }
        if (!empty($filters['branch_id'])) {
            $extraWhere .= ' AND branch_id = :branch_id';
            $extraParams[':branch_id'] = (int)$filters['branch_id'];
        }

        // ---------- Monthly hires/exits breakdown ----------
        $hiresStmt = $this->db->prepare("SELECT MONTH(employment_date) AS m, COUNT(*) AS c
            FROM `employees`
            WHERE comp_id = :comp_id AND deleted_at IS NULL
              AND employment_date BETWEEN :year_start AND :year_end
              {$extraWhere}
            GROUP BY MONTH(employment_date)");
        $hiresStmt->execute(array_merge([':comp_id' => $compId, ':year_start' => $yearStart, ':year_end' => $yearEnd], $extraParams));
        $hiresByMonth = array_fill(1, 12, 0);
        foreach ($hiresStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hiresByMonth[(int)$row['m']] = (int)$row['c'];
        }

        $exitsStmt = $this->db->prepare("SELECT MONTH(employment_end_date) AS m, COUNT(*) AS c
            FROM `employees`
            WHERE comp_id = :comp_id AND deleted_at IS NULL
              AND employment_end_date BETWEEN :year_start AND :year_end
              AND employment_status IN ('resigned', 'terminated')
              {$extraWhere}
            GROUP BY MONTH(employment_end_date)");
        $exitsStmt->execute(array_merge([':comp_id' => $compId, ':year_start' => $yearStart, ':year_end' => $yearEnd], $extraParams));
        $exitsByMonth = array_fill(1, 12, 0);
        foreach ($exitsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $exitsByMonth[(int)$row['m']] = (int)$row['c'];
        }

        $byMonth = [];
        for ($m = 1; $m <= 12; $m++) {
            $byMonth[] = ['month' => $m, 'hires' => $hiresByMonth[$m], 'exits' => $exitsByMonth[$m]];
        }
        $totalHires = array_sum($hiresByMonth);
        $totalExits = array_sum($exitsByMonth);

        // ---------- Average headcount + turnover rate ----------
        // Real bug caught by this method's own test before shipping: "still active as of date X"
        // must use the SAME authoritative signal the exits count above uses
        // (`employment_status IN ('resigned','terminated')`), not a raw `employment_end_date`
        // presence/date check -- an employee can have `employment_end_date` SET (e.g. a
        // future-planned date entered ahead of time) while their `employment_status` hasn't
        // actually changed yet, and that person is still genuinely employed. The first draft here
        // only checked the date, disagreeing with the exits definition for exactly that case and
        // producing a headcount snapshot inconsistent with its own hires/exits numbers.
        $headcountAsOf = function (string $asOfDate) use ($compId, $extraWhere, $extraParams): int {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `employees`
                WHERE comp_id = :comp_id AND deleted_at IS NULL
                  AND employment_date <= :as_of
                  AND (
                    employment_status NOT IN ('resigned', 'terminated')
                    OR employment_end_date >= :as_of2
                  )
                  {$extraWhere}");
            $stmt->execute(array_merge([':comp_id' => $compId, ':as_of' => $asOfDate, ':as_of2' => $asOfDate], $extraParams));
            return (int)$stmt->fetchColumn();
        };
        $headcountStart = $headcountAsOf($yearStart);
        $headcountEnd = $headcountAsOf($yearEnd);
        $avgHeadcount = ($headcountStart + $headcountEnd) / 2;
        $turnoverRate = $avgHeadcount > 0 ? round(($totalExits / $avgHeadcount) * 100, 2) : 0.0;

        // ---------- Individual movement events (hires + exits, for the detail table) ----------
        // A UNION ALL of 2 near-identical SELECT halves -- real bug caught before shipping: this
        // driver does NOT have emulated prepares on in this environment (confirmed empirically by
        // this exact query throwing "Invalid parameter number: number of bound variables does not
        // match number of tokens" the first time a filter was applied -- PDO::ATTR_EMULATE_PREPARES
        // isn't overridden anywhere in app/core/Database.php, but the underlying driver still treats
        // each occurrence of a named placeholder as its OWN token when natively preparing, unlike
        // the commonly-assumed "PDO substitutes one bound value into every occurrence" behavior).
        // Every placeholder that appears in BOTH halves of the UNION is therefore given a distinct
        // '1'/'2' suffix per half, bound separately, rather than reused.
        $nameExpr = "CONCAT(e.name_th, ' ', e.surname_th)";
        // Built directly from $filters (not by transforming the $extraWhere string above) --
        // chaining str_replace('department_id' -> 'e.department_id') after suffixing the
        // placeholder to ':department_id1' would ALSO rewrite that placeholder's own text (it
        // contains "department_id" as a substring), corrupting it into ':e.department_id1'. Simpler
        // and safer to just rebuild both qualified/suffixed WHERE fragments from scratch here.
        $extraWhereQualified1 = '';
        $extraWhereQualified2 = '';
        if (!empty($filters['department_id'])) {
            $extraWhereQualified1 .= ' AND e.department_id = :department_id1';
            $extraWhereQualified2 .= ' AND e.department_id = :department_id2';
        }
        if (!empty($filters['branch_id'])) {
            $extraWhereQualified1 .= ' AND e.branch_id = :branch_id1';
            $extraWhereQualified2 .= ' AND e.branch_id = :branch_id2';
        }
        $eventsStmt = $this->db->prepare("
            SELECT e.employee_no, {$nameExpr} AS name, d.department_name_th, d.department_name_en,
                   b.branch_name_th, b.branch_name_en, 'hire' AS movement_type, e.employment_date AS event_date
            FROM `employees` e
            LEFT JOIN `structure_departments` d ON e.department_id = d.id
            LEFT JOIN `structure_branches` b ON e.branch_id = b.id
            WHERE e.comp_id = :comp_id1 AND e.deleted_at IS NULL
              AND e.employment_date BETWEEN :year_start1 AND :year_end1
              {$extraWhereQualified1}
            UNION ALL
            SELECT e.employee_no, {$nameExpr} AS name, d.department_name_th, d.department_name_en,
                   b.branch_name_th, b.branch_name_en, 'exit' AS movement_type, e.employment_end_date AS event_date
            FROM `employees` e
            LEFT JOIN `structure_departments` d ON e.department_id = d.id
            LEFT JOIN `structure_branches` b ON e.branch_id = b.id
            WHERE e.comp_id = :comp_id2 AND e.deleted_at IS NULL
              AND e.employment_end_date BETWEEN :year_start2 AND :year_end2
              AND e.employment_status IN ('resigned', 'terminated')
              {$extraWhereQualified2}
            ORDER BY event_date ASC");
        $eventsParams = [
            ':comp_id1' => $compId, ':year_start1' => $yearStart, ':year_end1' => $yearEnd,
            ':comp_id2' => $compId, ':year_start2' => $yearStart, ':year_end2' => $yearEnd,
        ];
        foreach ($extraParams as $key => $val) {
            $eventsParams[$key . '1'] = $val;
            $eventsParams[$key . '2'] = $val;
        }
        $eventsStmt->execute($eventsParams);
        $events = $eventsStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'summary' => [
                'total_hires' => $totalHires,
                'total_exits' => $totalExits,
                'net_change' => $totalHires - $totalExits,
                'turnover_rate' => $turnoverRate,
                'headcount_start' => $headcountStart,
                'headcount_end' => $headcountEnd,
            ],
            'by_month' => $byMonth,
            'events' => $events,
        ];
    }

    /**
     * Contract/Work Permit/Visa/Passport Expiry report (2026-09-02, Phase 2 of the Employee Reports
     * plan) -- the single highest-value item in that phase: `date_contract_expire`/
     * `date_work_permit_expire`/`date_visa_expire`/`passport_expire_date` have sat fully populated
     * (2026-09-02 sync field batch) and completely unsurfaced as a report until now. Returns ONE ROW
     * PER EXPIRING DATE (not per employee) -- an employee with both a contract and a work permit
     * expiring shows as 2 rows -- so the list can be sorted purely by urgency (soonest/most-overdue
     * first) across every category at once, matching how an HR admin actually triages this.
     * Deliberately has NO lower bound on how far in the past an expiry can be -- an already-expired
     * item stays listed (negative `days_remaining`) until the underlying date is actually updated,
     * since that's a real, still-unresolved compliance gap, not something to silently drop off a list.
     *
     * @param array $filters {department_id?: int, branch_id?: int}
     * @return array{items: array, counts: array{contract:int, work_permit:int, visa:int, passport:int}}
     */
    public function expiryReport(int $compId, int $withinDays, array $filters = []): array {
        $cutoff = date('Y-m-d', strtotime("+{$withinDays} days"));
        $today = date('Y-m-d');

        $extraWhere = '';
        $extraParams = [];
        if (!empty($filters['department_id'])) {
            $extraWhere .= ' AND e.department_id = :department_id';
            $extraParams[':department_id'] = (int)$filters['department_id'];
        }
        if (!empty($filters['branch_id'])) {
            $extraWhere .= ' AND e.branch_id = :branch_id';
            $extraParams[':branch_id'] = (int)$filters['branch_id'];
        }

        $nameExpr = "CONCAT(e.name_th, ' ', e.surname_th)";
        $dateColumns = [
            'contract' => 'date_contract_expire',
            'work_permit' => 'date_work_permit_expire',
            'visa' => 'date_visa_expire',
            'passport' => 'passport_expire_date',
        ];
        // Each category is its OWN prepared statement (not one big UNION ALL) -- simpler and avoids
        // the duplicate-named-placeholder pitfall headcountMovementReport() above already documents
        // hitting (this driver does not dedupe repeated named placeholders across a single
        // statement) without needing manually-suffixed placeholder names for 4 categories at once.
        $items = [];
        $counts = ['contract' => 0, 'work_permit' => 0, 'visa' => 0, 'passport' => 0];
        foreach ($dateColumns as $type => $col) {
            $stmt = $this->db->prepare("
                SELECT e.employee_no, {$nameExpr} AS name, d.department_name_th, d.department_name_en,
                       b.branch_name_th, b.branch_name_en, e.{$col} AS expiry_date
                FROM `employees` e
                LEFT JOIN `structure_departments` d ON e.department_id = d.id
                LEFT JOIN `structure_branches` b ON e.branch_id = b.id
                WHERE e.comp_id = :comp_id AND e.deleted_at IS NULL
                  AND e.{$col} IS NOT NULL AND e.{$col} <= :cutoff
                  {$extraWhere}
                ORDER BY e.{$col} ASC");
            $stmt->execute(array_merge([':comp_id' => $compId, ':cutoff' => $cutoff], $extraParams));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $counts[$type] = count($rows);
            foreach ($rows as $row) {
                $row['expiry_type'] = $type;
                $row['days_remaining'] = (int)((strtotime($row['expiry_date']) - strtotime($today)) / 86400);
                $items[] = $row;
            }
        }
        usort($items, fn($a, $b) => strcmp($a['expiry_date'], $b['expiry_date']));

        return ['items' => $items, 'counts' => $counts];
    }

    /**
     * Probation Status report (2026-09-02, Phase 2 of the Employee Reports plan). Ordered by
     * `employment_date` ascending (longest-waiting first). **No "days until due" column** --
     * confirmed via AskUserQuestion: no probation-period-LENGTH setting exists anywhere in this
     * schema (per-company or per-employee), so a due date can't be computed without guessing a
     * number; ships "days on probation so far" only. Revisit if a future request adds a real
     * probation-length setting to build the due-date column against.
     *
     * @param array $filters {department_id?: int, branch_id?: int}
     */
    public function probationReport(int $compId, array $filters = []): array {
        $extraWhere = '';
        $extraParams = [];
        if (!empty($filters['department_id'])) {
            $extraWhere .= ' AND e.department_id = :department_id';
            $extraParams[':department_id'] = (int)$filters['department_id'];
        }
        if (!empty($filters['branch_id'])) {
            $extraWhere .= ' AND e.branch_id = :branch_id';
            $extraParams[':branch_id'] = (int)$filters['branch_id'];
        }
        $nameExpr = "CONCAT(e.name_th, ' ', e.surname_th)";
        $stmt = $this->db->prepare("
            SELECT e.employee_no, {$nameExpr} AS name, d.department_name_th, d.department_name_en,
                   b.branch_name_th, b.branch_name_en, e.employment_date
            FROM `employees` e
            LEFT JOIN `structure_departments` d ON e.department_id = d.id
            LEFT JOIN `structure_branches` b ON e.branch_id = b.id
            WHERE e.comp_id = :comp_id AND e.deleted_at IS NULL
              AND e.employment_status = 'probation'
              {$extraWhere}
            ORDER BY e.employment_date ASC");
        $stmt->execute(array_merge([':comp_id' => $compId], $extraParams));
        $today = date('Y-m-d');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['days_on_probation'] = (int)((strtotime($today) - strtotime($row['employment_date'])) / 86400);
        }
        unset($row);
        return ['items' => $rows, 'count' => count($rows)];
    }

    /**
     * SSO/PVD Enrollment report (2026-09-02, Phase 2 of the Employee Reports plan) -- "which people
     * are/aren't enrolled," distinct from the existing statutory SSO 1-10 FORM exports
     * (`app/services/reports/statutory/th/Sso110Report.php` etc, which are government filing
     * documents, not a plain roster). Only counts employees whose `employment_status` isn't
     * resigned/terminated -- enrollment status of someone who's already left is not an actionable
     * "should this person be enrolled" question the way it is for a current employee.
     *
     * @param array $filters {department_id?: int, branch_id?: int}
     * @return array{items: array, counts: array{sso_enrolled:int, sso_not_enrolled:int, pvd_enrolled:int, pvd_not_enrolled:int}}
     */
    public function statutoryEnrollmentReport(int $compId, array $filters = []): array {
        $extraWhere = '';
        $extraParams = [];
        if (!empty($filters['department_id'])) {
            $extraWhere .= ' AND e.department_id = :department_id';
            $extraParams[':department_id'] = (int)$filters['department_id'];
        }
        if (!empty($filters['branch_id'])) {
            $extraWhere .= ' AND e.branch_id = :branch_id';
            $extraParams[':branch_id'] = (int)$filters['branch_id'];
        }
        $nameExpr = "CONCAT(e.name_th, ' ', e.surname_th)";
        $stmt = $this->db->prepare("
            SELECT e.employee_no, {$nameExpr} AS name, d.department_name_th, d.department_name_en,
                   b.branch_name_th, b.branch_name_en, e.sso_enrolled, e.pvd_enrolled
            FROM `employees` e
            LEFT JOIN `structure_departments` d ON e.department_id = d.id
            LEFT JOIN `structure_branches` b ON e.branch_id = b.id
            WHERE e.comp_id = :comp_id AND e.deleted_at IS NULL
              AND e.employment_status NOT IN ('resigned', 'terminated')
              {$extraWhere}
            ORDER BY e.employee_no ASC");
        $stmt->execute(array_merge([':comp_id' => $compId], $extraParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $counts = ['sso_enrolled' => 0, 'sso_not_enrolled' => 0, 'pvd_enrolled' => 0, 'pvd_not_enrolled' => 0];
        foreach ($rows as $row) {
            $counts[(int)$row['sso_enrolled'] === 1 ? 'sso_enrolled' : 'sso_not_enrolled']++;
            $counts[(int)$row['pvd_enrolled'] === 1 ? 'pvd_enrolled' : 'pvd_not_enrolled']++;
        }
        return ['items' => $rows, 'counts' => $counts];
    }

    /**
     * Headcount Structure report (2026-09-02, Phase 3 of the Employee Reports plan) -- a current
     * snapshot (not scoped to any date range) grouped by ONE dimension at a time, picked by the
     * caller. Scoped to currently-employed staff only (`employment_status NOT IN
     * ('resigned','terminated')`) -- a resigned employee's old department doesn't belong in a
     * "how is headcount currently structured" view. `employment_type` groups by the FIXED enum
     * column (full_time/part_time/daily/internship), not the newer optional
     * `structure_employment_types` company-defined classification (`employment_type_id`) -- the
     * enum is always populated for every employee, the newer table is opt-in and may have zero rows
     * for a company that's never synced one in.
     *
     * @param string $groupBy one of 'department'|'position'|'branch'|'employment_type'
     * @return array{groups: array<int,array{label:string,count:int}>, total: int}
     */
    public function headcountStructureReport(int $compId, string $groupBy): array {
        $groupConfig = [
            'department' => ["LEFT JOIN `structure_departments` g ON e.department_id = g.id", 'g.department_name_th', 'g.department_name_en'],
            'position' => ["LEFT JOIN `structure_positions` g ON e.position_id = g.id", 'g.position_name_th', 'g.position_name_en'],
            'branch' => ["LEFT JOIN `structure_branches` g ON e.branch_id = g.id", 'g.branch_name_th', 'g.branch_name_en'],
        ];
        if (isset($groupConfig[$groupBy])) {
            [$join, $labelThCol, $labelEnCol] = $groupConfig[$groupBy];
            $stmt = $this->db->prepare("
                SELECT COALESCE({$labelThCol}, '-') AS label_th, COALESCE({$labelEnCol}, '-') AS label_en, COUNT(*) AS c
                FROM `employees` e
                {$join}
                WHERE e.comp_id = :comp_id AND e.deleted_at IS NULL AND e.employment_status NOT IN ('resigned', 'terminated')
                GROUP BY label_th, label_en
                ORDER BY c DESC");
        } elseif ($groupBy === 'employment_type') {
            $stmt = $this->db->prepare("
                SELECT e.employment_type AS label_th, e.employment_type AS label_en, COUNT(*) AS c
                FROM `employees` e
                WHERE e.comp_id = :comp_id AND e.deleted_at IS NULL AND e.employment_status NOT IN ('resigned', 'terminated')
                GROUP BY e.employment_type
                ORDER BY c DESC");
        } else {
            throw new InvalidArgumentException('Invalid groupBy.');
        }
        $stmt->execute([':comp_id' => $compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $groups = array_map(fn($r) => ['label_th' => $r['label_th'], 'label_en' => $r['label_en'], 'count' => (int)$r['c']], $rows);
        $total = array_sum(array_column($groups, 'count'));
        return ['groups' => $groups, 'total' => $total];
    }

    /**
     * Tenure (อายุงาน) report (2026-09-02, Phase 3) -- buckets currently-employed staff by
     * years-of-service, computed from `employment_date`. Same "currently employed only" scope as
     * headcountStructureReport() above. Bucket boundaries: <1yr, 1-3yr, 3-5yr, 5-10yr, 10yr+ --
     * Thai labor-law severance-pay brackets land near these same boundaries (not an exact legal
     * citation, just the natural grouping this app's own HR audience would recognize).
     *
     * @param array $filters {department_id?: int, branch_id?: int}
     * @return array{buckets: array<int,array{label:string,count:int}>, items: array, average_years: float, longest_years: float}
     */
    public function tenureReport(int $compId, array $filters = []): array {
        $extraWhere = '';
        $extraParams = [];
        if (!empty($filters['department_id'])) {
            $extraWhere .= ' AND e.department_id = :department_id';
            $extraParams[':department_id'] = (int)$filters['department_id'];
        }
        if (!empty($filters['branch_id'])) {
            $extraWhere .= ' AND e.branch_id = :branch_id';
            $extraParams[':branch_id'] = (int)$filters['branch_id'];
        }
        $nameExpr = "CONCAT(e.name_th, ' ', e.surname_th)";
        $stmt = $this->db->prepare("
            SELECT e.employee_no, {$nameExpr} AS name, d.department_name_th, d.department_name_en,
                   b.branch_name_th, b.branch_name_en, e.employment_date
            FROM `employees` e
            LEFT JOIN `structure_departments` d ON e.department_id = d.id
            LEFT JOIN `structure_branches` b ON e.branch_id = b.id
            WHERE e.comp_id = :comp_id AND e.deleted_at IS NULL
              AND e.employment_status NOT IN ('resigned', 'terminated')
              {$extraWhere}
            ORDER BY e.employment_date ASC");
        $stmt->execute(array_merge([':comp_id' => $compId], $extraParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $bucketDefs = [
            '<1' => ['label' => 'tenure_bucket_under_1', 'min' => 0, 'max' => 1],
            '1-3' => ['label' => 'tenure_bucket_1_3', 'min' => 1, 'max' => 3],
            '3-5' => ['label' => 'tenure_bucket_3_5', 'min' => 3, 'max' => 5],
            '5-10' => ['label' => 'tenure_bucket_5_10', 'min' => 5, 'max' => 10],
            '10+' => ['label' => 'tenure_bucket_10_plus', 'min' => 10, 'max' => null],
        ];
        $bucketCounts = array_fill_keys(array_keys($bucketDefs), 0);
        $today = new DateTime('today');
        $totalYears = 0.0;
        $longestYears = 0.0;
        foreach ($rows as &$row) {
            $start = new DateTime($row['employment_date']);
            $years = $today->diff($start)->days / 365.25;
            $row['tenure_years'] = round($years, 2);
            $totalYears += $years;
            $longestYears = max($longestYears, $years);
            foreach ($bucketDefs as $key => $def) {
                if ($years >= $def['min'] && ($def['max'] === null || $years < $def['max'])) {
                    $bucketCounts[$key]++;
                    break;
                }
            }
        }
        unset($row);

        $buckets = [];
        foreach ($bucketDefs as $key => $def) {
            $buckets[] = ['key' => $key, 'label_key' => $def['label'], 'count' => $bucketCounts[$key]];
        }

        return [
            'buckets' => $buckets,
            'items' => $rows,
            'average_years' => count($rows) > 0 ? round($totalYears / count($rows), 2) : 0.0,
            'longest_years' => round($longestYears, 2),
        ];
    }

    /**
     * Birthday & Work Anniversary report (2026-09-02, Phase 3) -- lists currently-employed staff
     * whose `date_of_birth` OR `employment_date` MONTH matches the selected month (any year --
     * "born in March" / "hired in March", regardless of which year). An employee whose hire month
     * happens to also be their birth month appears in BOTH lists independently (not deduplicated --
     * they're 2 genuinely separate facts an HR admin would want to see both of).
     *
     * @param array $filters {department_id?: int, branch_id?: int}
     * @return array{birthdays: array, anniversaries: array}
     */
    public function birthdayAnniversaryReport(int $compId, int $month, array $filters = []): array {
        $extraWhere = '';
        $extraParams = [];
        if (!empty($filters['department_id'])) {
            $extraWhere .= ' AND e.department_id = :department_id';
            $extraParams[':department_id'] = (int)$filters['department_id'];
        }
        if (!empty($filters['branch_id'])) {
            $extraWhere .= ' AND e.branch_id = :branch_id';
            $extraParams[':branch_id'] = (int)$filters['branch_id'];
        }
        $nameExpr = "CONCAT(e.name_th, ' ', e.surname_th)";
        $baseSelect = "SELECT e.employee_no, {$nameExpr} AS name, d.department_name_th, d.department_name_en,
                   b.branch_name_th, b.branch_name_en";
        $baseFrom = "FROM `employees` e
            LEFT JOIN `structure_departments` d ON e.department_id = d.id
            LEFT JOIN `structure_branches` b ON e.branch_id = b.id
            WHERE e.comp_id = :comp_id AND e.deleted_at IS NULL
              AND e.employment_status NOT IN ('resigned', 'terminated')
              {$extraWhere}";

        $birthdayStmt = $this->db->prepare("{$baseSelect}, e.date_of_birth AS event_date {$baseFrom}
              AND MONTH(e.date_of_birth) = :month
            ORDER BY DAY(e.date_of_birth) ASC");
        $birthdayStmt->execute(array_merge([':comp_id' => $compId, ':month' => $month], $extraParams));

        $anniversaryStmt = $this->db->prepare("{$baseSelect}, e.employment_date AS event_date {$baseFrom}
              AND MONTH(e.employment_date) = :month
            ORDER BY DAY(e.employment_date) ASC");
        $anniversaryStmt->execute(array_merge([':comp_id' => $compId, ':month' => $month], $extraParams));

        $today = new DateTime('today');
        $addYearsOn = function (array $rows) use ($today): array {
            foreach ($rows as &$row) {
                $row['years'] = $today->diff(new DateTime($row['event_date']))->y;
            }
            unset($row);
            return $rows;
        };

        return [
            'birthdays' => $addYearsOn($birthdayStmt->fetchAll(PDO::FETCH_ASSOC)),
            'anniversaries' => $addYearsOn($anniversaryStmt->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /**
     * Company-wide Data Completeness overview (2026-09-02, Phase 4 -- the last phase -- of the
     * Employee Reports plan) -- aggregates the SAME per-employee `calculateCompleteness()`/
     * `completenessColumns()` machinery every other consumer of this already uses (`list()`,
     * `get()`) into a company-wide distribution, zero new calculation logic. Scoped to
     * currently-employed staff only, same "current snapshot" convention as
     * headcountStructureReport()/tenureReport() above -- a resigned employee's own data gaps aren't
     * something anyone is going to act on anymore. Buckets: <50% / 50-80% / 80%+.
     *
     * @param array $filters {department_id?: int, branch_id?: int}
     * @return array{buckets: array<int,array{key:string,label_key:string,count:int}>, items: array, average_percent: float}
     */
    public function completenessOverviewReport(int $compId, array $filters = []): array {
        $extraWhere = '';
        $extraParams = [];
        if (!empty($filters['department_id'])) {
            $extraWhere .= ' AND e.department_id = :department_id';
            $extraParams[':department_id'] = (int)$filters['department_id'];
        }
        if (!empty($filters['branch_id'])) {
            $extraWhere .= ' AND e.branch_id = :branch_id';
            $extraParams[':branch_id'] = (int)$filters['branch_id'];
        }
        $nameExpr = "CONCAT(e.name_th, ' ', e.surname_th)";
        $completenessSelect = implode(', ', array_map(fn($c) => "e.`{$c}`", $this->completenessColumns()));
        $stmt = $this->db->prepare("
            SELECT e.employee_no, {$nameExpr} AS name, d.department_name_th, d.department_name_en,
                   b.branch_name_th, b.branch_name_en, {$completenessSelect}
            FROM `employees` e
            LEFT JOIN `structure_departments` d ON e.department_id = d.id
            LEFT JOIN `structure_branches` b ON e.branch_id = b.id
            WHERE e.comp_id = :comp_id AND e.deleted_at IS NULL
              AND e.employment_status NOT IN ('resigned', 'terminated')
              {$extraWhere}");
        $stmt->execute(array_merge([':comp_id' => $compId], $extraParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $bucketDefs = [
            'under_50' => ['label' => 'completeness_bucket_under_50', 'min' => 0, 'max' => 50],
            '50_80' => ['label' => 'completeness_bucket_50_80', 'min' => 50, 'max' => 80],
            '80_plus' => ['label' => 'completeness_bucket_80_plus', 'min' => 80, 'max' => 101],
        ];
        $bucketCounts = array_fill_keys(array_keys($bucketDefs), 0);
        $items = [];
        $totalPercent = 0;
        foreach ($rows as $row) {
            $row['base_salary_amount'] = self::decryptSalaryValue($row['base_salary_amount'] ?? null, isset($row['key_version']) ? (int)$row['key_version'] : null);
            $percent = $this->calculateCompleteness($row)['percent'];
            $totalPercent += $percent;
            foreach ($bucketDefs as $key => $def) {
                if ($percent >= $def['min'] && $percent < $def['max']) {
                    $bucketCounts[$key]++;
                    break;
                }
            }
            $items[] = [
                'employee_no' => $row['employee_no'], 'name' => $row['name'],
                'department_name_th' => $row['department_name_th'], 'department_name_en' => $row['department_name_en'],
                'branch_name_th' => $row['branch_name_th'], 'branch_name_en' => $row['branch_name_en'],
                'completeness' => $percent,
            ];
        }
        usort($items, fn($a, $b) => $a['completeness'] <=> $b['completeness']);

        $buckets = [];
        foreach ($bucketDefs as $key => $def) {
            $buckets[] = ['key' => $key, 'label_key' => $def['label'], 'count' => $bucketCounts[$key]];
        }

        return [
            'buckets' => $buckets,
            'items' => $items,
            'average_percent' => count($rows) > 0 ? round($totalPercent / count($rows), 1) : 0.0,
        ];
    }

    /**
     * Distinct values for ONE column of the Employee List, respecting every OTHER currently-active
     * filter (station filters + every OTHER column's own Excel-style checkbox selection) but NOT
     * this column's own selection -- see buildListWhere()'s own docblock for why. Powers the
     * Excel-style per-column header filter's checkbox list (`api/employee.list-column-values`).
     * Capped at 500 distinct values -- same pragmatic cap a real spreadsheet's own filter dropdown
     * would eventually need too; a column that legitimately has more distinct values than that
     * (none currently do on this table) would need pagination/search-within-the-dropdown to stay
     * usable, not attempted here since nothing on this table needs it yet.
     */
    public function listColumnValues(int $compId, string $column, array $filters, string $search, string $lang = 'th'): array {
        $exprMap = $this->listColumnExprMap($lang);
        if (!isset($exprMap[$column])) {
            return [];
        }
        $expr = $column === 'status'
            ? "IF(e.employee_status = 'active', 'Active', CONCAT(UCASE(LEFT(e.employee_status,1)), SUBSTRING(e.employee_status,2)))"
            : ($column === 'payroll_participant' ? "IF(e.is_payroll_participant = 1, 'Yes', 'No')" : $exprMap[$column]);
        [$where, $params] = $this->buildListWhere($compId, $filters, $search, $lang, $column);
        $sql = "SELECT DISTINCT {$expr} AS value " . self::LIST_JOINS . "
                WHERE {$where} AND {$expr} IS NOT NULL AND {$expr} != ''
                ORDER BY value ASC LIMIT 500";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'value');
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
                    et.employment_type_name_th, et.employment_type_name_en,
                    r.role_name_th, r.role_name_en,
                    p.position_name_th, p.position_name_en,
                    b.branch_name_th, b.branch_name_en,
                    mb.bank_code, mb.bank_name_th, mb.bank_name_en,
                    dba.account_name AS default_bank_account_name, dba.company_code AS default_bank_account_company_code,
                    dmb.bank_name_th AS default_bank_account_bank_name_th, dmb.bank_name_en AS default_bank_account_bank_name_en,
                    mpm.code AS payment_method_code, mpm.name_th AS payment_method_name_th, mpm.name_en AS payment_method_name_en,
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
                LEFT JOIN `structure_employment_types` et ON e.employment_type_id = et.id
                LEFT JOIN `structure_roles` r ON e.role_id = r.id
                LEFT JOIN `structure_positions` p ON e.position_id = p.id
                LEFT JOIN `structure_branches` b ON e.branch_id = b.id
                LEFT JOIN `master_banks` mb ON e.bank_id = mb.id
                LEFT JOIN `bank_accounts` dba ON e.default_bank_account_id = dba.id
                LEFT JOIN `master_banks` dmb ON dba.bank_id = dmb.id
                LEFT JOIN `master_payment_methods` mpm ON e.payment_method_id = mpm.id
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
            $row[$col] = $col === 'base_salary_amount'
                ? self::decryptSalaryValue($row[$col] ?? null, $keyVersion)
                : EncryptionService::decrypt($row[$col] ?? null, $keyVersion);
        }
        $row = array_merge($row, $this->buildAddressDisplay($row, 'register'), $this->buildAddressDisplay($row, 'contact'));
        // 2026-09-02, extends the Origami candidates.php field batch -- foreign_worker_info lives in
        // its own table (EmployeeForeignWorkerDetailModel), merged in here so the Employee Detail
        // form's single get() call already has everything it needs (same "one call populates the
        // whole form" convention this method already follows for every other related-table field).
        $foreignWorkerDetail = (new EmployeeForeignWorkerDetailModel($this->db))->get((int)$row['id']);
        if ($foreignWorkerDetail) {
            unset($foreignWorkerDetail['employee_id'], $foreignWorkerDetail['updated_by'], $foreignWorkerDetail['updated_at']);
            $row = array_merge($row, $foreignWorkerDetail);
        }
        $row['completeness'] = $this->calculateCompleteness($row);
        $row['verify_status'] = $this->verifyStatus($row, $this->getCompanyCountry($compId) === 'TH');
        return $row;
    }

    // 2026-09-08, explicit request: "ส่วนที่ดึงรายชื่อมาทำเงินเดือน และออก Report จะต้องไม่ดึงคนที่ไม่ได้
    // รับเงินเดือนมาด้วย" -- this endpoint is a general-purpose employee picker reused for several
    // unrelated purposes (Report-To manager selection, Payslip/Employment Certificate Template
    // "Assign To" pickers) where offering a non-payroll-participant is correct (a manager or a
    // document's audience need not themselves be paid through this app), so the filter is opt-in via
    // $payrollParticipantsOnly rather than baked into $where unconditionally -- the one caller that
    // DOES need it is the Payment Voucher report's own employee picker (Reports > Annual Reports),
    // wired via #reportsPreviewEmployeeSelect's `data-payroll-participants-only="1"` (see input.js).
    public function reportToOptions(int $compId, ?int $excludeId, string $search, int $page, int $limit, bool $payrollParticipantsOnly = false): array {
        $offset = ($page - 1) * $limit;
        $where = "comp_id = :comp_id AND deleted_at IS NULL";
        $params = [':comp_id' => $compId];
        if ($payrollParticipantsOnly) {
            $where .= " AND is_payroll_participant = 1";
        }
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

    public function save(int $compId, array $data, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;
        $isThCompany = $this->getCompanyCountry($compId) === 'TH';

        // 2026-08-30 (T020): looked up up-front (not just inside the UPDATE branch further down) so
        // it's available before is_payroll_ready is computed below too -- same "fetch the row's own
        // current value first, fall back to THAT instead of a hardcoded default when the payload
        // omits the key" pattern already established for status in
        // PayrollEarningDeductionTypeModel::save() (see this project's own CLAUDE.md). Only matters
        // for an UPDATE from a caller that doesn't send this field at all; every real save from the
        // Employee Detail form always sends it (hidden input, collectEmployeeFormData()'s generic loop).
        $existingIsPayrollParticipant = null;
        if ($id !== null) {
            $stmtExistingParticipant = $this->db->prepare("SELECT is_payroll_participant FROM `employees` WHERE id = :id AND comp_id = :comp_id");
            $stmtExistingParticipant->execute([':id' => $id, ':comp_id' => $compId]);
            $existingVal = $stmtExistingParticipant->fetchColumn();
            if ($existingVal !== false) {
                $existingIsPayrollParticipant = (int)$existingVal;
            }
        }

        // 2026-09-02, explicit request: Probation/Internship pay policy's new OT-eligible-default
        // setting -- confirmed via AskUserQuestion "default only, checkbox still wins": applied
        // exactly ONCE, at CREATE time only, when a brand-new employee is created directly with
        // employment_status=probation or employment_type=internship. Deliberately does NOT also
        // fire when an EXISTING employee later transitions into that status/type (an earlier
        // version of this logic tried to detect that case too, via "submitted value equals the
        // row's existing value" -- caught as a REAL bug by this feature's own test before shipping:
        // that heuristic can't actually distinguish "admin didn't touch this checkbox" from "admin's
        // pre-existing, deliberate true value coincidentally wasn't changed in this save," so an
        // existing employee with ot_eligible ALREADY explicitly set to true, reclassified into
        // probation without the admin ever looking at the OT checkbox, would have had that
        // deliberate setting silently overwritten to the policy default -- exactly the kind of
        // surprise "checkbox still wins" was meant to prevent. This codebase has no existing "was
        // this field manually touched" tracking to do better than that heuristic, so CREATE-TIME-ONLY
        // is the safe, unsurprising rule -- an existing employee's OT eligibility is never touched
        // by this feature, full stop, regardless of what status/type change accompanies the save).
        if ($id === null) {
            $newEmploymentStatus = (string)($data['employment_status'] ?? '');
            $newEmploymentType = (string)($data['employment_type'] ?? '');
            $isNewIntern = $newEmploymentType === 'internship';
            $isNewProbation = $newEmploymentStatus === 'probation';
            // Intern takes precedence over probation when both are somehow true, same precedence
            // PayrollRunModel::recalculate() already established for the ratio/defer_pvd gates.
            if ($isNewIntern || $isNewProbation) {
                $policyModel = new PayrollPolicyModel($this->db);
                $settings = $isNewIntern ? $policyModel->internSettings($compId) : $policyModel->probationSettings($compId);
                if (empty($data['ot_eligible']) && $settings['ot_eligible_default'] !== null) {
                    $data['ot_eligible'] = $settings['ot_eligible_default'] ? 1 : 0;
                }
                // 2026-09-02, follow-up to close a review-flagged gap: "เงื่อนไขการหักภาษี...ที่แตกต่างจาก
                // พนักงานปกติ" -- same CREATE-TIME-ONLY soft default as ot_eligible_default immediately
                // above, applied to the ALREADY-existing employees.tax_exempt checkbox (not a new tax
                // formula -- see this feature's own migration comment for why).
                if (empty($data['tax_exempt']) && $settings['tax_exempt_default'] !== null) {
                    $data['tax_exempt'] = $settings['tax_exempt_default'] ? 1 : 0;
                }
            }
        }

        // 2026-08-30, real bug found and fixed BEFORE shipping (not guessed -- caught while reasoning
        // through EmployeeOtRateModel's own OT rate feature): `ot_rate_source` is a plain enum column
        // (NOT boolean/int), so it falls into the generic `$val = $data[$col] ?? null;` branch further
        // down -- an absent key would have written a literal NULL into a NOT NULL column on EVERY
        // save. The real Employee Detail page's own #ot_rate_source select deliberately has NO `name`
        // attribute (its own dedicated api/employee.ot-rate.save endpoint owns writing it, see that
        // section's own comment) -- meaning EVERY save from that page's normal tabs (collect the WHOLE
        // form, submit regardless of which tab's button was clicked) would have hit exactly this,
        // breaking every employee save the moment ot_rate_source was added to allColumns() below.
        // Same "fetch existing value first, fall back to THAT instead of a hardcoded default when the
        // payload omits the key" pattern as is_payroll_participant just above -- only the Recheck
        // modal's own #rc_ot_rate_source (which DOES carry a name, saving through this generic path)
        // and the dedicated OT endpoint ever need to actually change it.
        if (!array_key_exists('ot_rate_source', $data)) {
            $existingOtRateSource = 'default';
            if ($id !== null) {
                $stmtExistingOtSource = $this->db->prepare("SELECT ot_rate_source FROM `employees` WHERE id = :id AND comp_id = :comp_id");
                $stmtExistingOtSource->execute([':id' => $id, ':comp_id' => $compId]);
                $existingOtVal = $stmtExistingOtSource->fetchColumn();
                if ($existingOtVal !== false && $existingOtVal !== null && $existingOtVal !== '') {
                    $existingOtRateSource = (string)$existingOtVal;
                }
            }
            $data['ot_rate_source'] = $existingOtRateSource;
        }

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
        if (!empty($data['signature_path']) && !self::isValidSignaturePath((string)$data['signature_path'], $compId)) {
            return ['status' => false, 'message' => 'Invalid signature path.'];
        }
        if (!empty($data['profile_photo_path']) && !self::isValidPhotoPath((string)$data['profile_photo_path'], $compId)) {
            return ['status' => false, 'message' => 'Invalid photo path.'];
        }
        // 2026-08-31, explicit request: internship pay conditions, per-employee ratio override --
        // same 0(exclusive)-100 validation shape as PayrollPolicyModel::save()'s own
        // intern_base_salary_ratio/probation_base_salary_ratio. Blank/absent is fine (null = no
        // override, use the company default) -- only a NON-EMPTY, out-of-range value is rejected.
        if (isset($data['intern_base_salary_ratio_override']) && $data['intern_base_salary_ratio_override'] !== '' && $data['intern_base_salary_ratio_override'] !== null) {
            if (!is_numeric($data['intern_base_salary_ratio_override']) || (float)$data['intern_base_salary_ratio_override'] <= 0 || (float)$data['intern_base_salary_ratio_override'] > 100) {
                return ['status' => false, 'message' => 'Intern base salary ratio override must be a percentage between 0 (exclusive) and 100, or left blank for no override.'];
            }
        }
        // 2026-09-02, real gap found and fixed while extending this section: probation_base_salary_
        // ratio_override was added to allColumns() but never got this SAME range check intern's own
        // override already has -- fixed here, same shape.
        if (isset($data['probation_base_salary_ratio_override']) && $data['probation_base_salary_ratio_override'] !== '' && $data['probation_base_salary_ratio_override'] !== null) {
            if (!is_numeric($data['probation_base_salary_ratio_override']) || (float)$data['probation_base_salary_ratio_override'] <= 0 || (float)$data['probation_base_salary_ratio_override'] > 100) {
                return ['status' => false, 'message' => 'Probation base salary ratio override must be a percentage between 0 (exclusive) and 100, or left blank for no override.'];
            }
        }
        // 2026-09-02, follow-up to close a review-flagged gap: "ตั้งค่าแยกเฉพาะบุคคลนี้" must cover
        // EVERY company-policy field, not just the ratio -- the day-count overrides get the same
        // non-negative check their own company-level counterparts (PayrollPolicyModel::save()) use;
        // the boolean-ish overrides (defer_pvd/defer_sso/defer_recurring_earning/allow_leave) need
        // no range check, any truthy/falsy value coerces safely through the generic column loop
        // below, same as every other tinyint column in this model.
        foreach (['probation_leave_days_limit_override', 'probation_period_days_override', 'intern_leave_days_limit_override', 'intern_period_days_override'] as $dayField) {
            if (isset($data[$dayField]) && $data[$dayField] !== '' && $data[$dayField] !== null) {
                if (!is_numeric($data[$dayField]) || (int)$data[$dayField] < 0) {
                    return ['status' => false, 'message' => "{$dayField} must be a non-negative number, or left blank for no override."];
                }
            }
        }

        $fkChecks = [
            'department_id' => 'structure_departments',
            'team_id' => 'structure_teams',
            'role_id' => 'structure_roles',
            'position_id' => 'structure_positions',
            'branch_id' => 'structure_branches',
            'employment_type_id' => 'structure_employment_types',
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
        // 2026-09-02, explicit request: payment method type (transfer/cash/check/mixed) --
        // payment_method_id (master_payment_methods lookup) is the sole source of truth. The old
        // payment_type enum mirror has been dropped entirely (see
        // database/migrations/2026-09-02_19_drop_legacy_payment_type.sql) -- every read site downstream
        // resolves via paymentMethodCode()/EmployeePaymentMethodModel instead.
        $paymentMethodModel = new EmployeePaymentMethodModel($this->db);
        // Only touched when the Employment tab (which owns payment_method_id) was actually part of
        // THIS save -- same "independent tab save" precedent this whole method already follows
        // elsewhere (is_payroll_participant/ot_rate_source above) -- a save from a different tab
        // must never silently clear an already-configured mixed-payment line set.
        $paymentMethodFieldProvided = array_key_exists('payment_method_id', $data);
        $resolvedPaymentMethodCode = null;
        if (!empty($data['payment_method_id'])) {
            $method = $paymentMethodModel->findMethod((int)$data['payment_method_id']);
            if (!$method) {
                return ['status' => false, 'message' => 'Invalid payment method selected.'];
            }
            $resolvedPaymentMethodCode = $method['code'];
        }
        // 2026-09-02, extends the Origami candidates.php field batch -- foreign_worker_info lives in
        // its own table (EmployeeForeignWorkerDetailModel, see that class's own docblock), persisted
        // AFTER the employee row's own INSERT/UPDATE succeeds below (needs the employee's own id for
        // a brand-new record). Only touched when at least one of its own fields was actually part of
        // THIS save -- same "independent tab save never silently clears another tab's data"
        // precedent as payment_method_id above.
        $foreignWorkerDetailModel = new EmployeeForeignWorkerDetailModel($this->db);
        $foreignWorkerDetailFields = ['recruitment_agency', 'arrival_date', 'due_date', 'arrival_card_no',
            'arrival_by_vehicle', 'address', 'soi', 'province', 'district', 'sub_district', 'tel_code', 'tel'];
        $foreignWorkerDetailProvided = !empty(array_intersect($foreignWorkerDetailFields, array_keys($data)));
        // 2026-09-02, explicit request: mixed payment lines -- validated here (before the employee
        // row itself is written) so a bad line set never partially saves; the normalized lines are
        // persisted AFTER the employee row's own INSERT/UPDATE succeeds below (needs the employee's
        // own id for a brand-new record). A non-mixed method clears any previously-saved lines.
        $mixedPaymentLines = [];
        if ($resolvedPaymentMethodCode === 'mixed') {
            $mixedValidation = $paymentMethodModel->validateMixedLines($compId, $data['payment_method_lines'] ?? []);
            if (!$mixedValidation['status']) {
                return $mixedValidation;
            }
            $mixedPaymentLines = $mixedValidation['lines'];
        }
        // 2026-09-02, explicit request: "ตัวเลือกบัญชีในส่วนนี้ต้องสอดคล้องกับประเภทการจ่ายเงินที่เลือกใน Tab
        // การจ้างงาน...เลือกต่อได้ว่าจะใช้บัญชีไหนของรอบนั้น" -- default_bank_account_id must belong to the
        // employee's own cycle_id's account set (EmployeePaymentMethodModel::isBankAccountValidForCycle(),
        // same fallback-to-company-default rule scopedBankAccountOptions() itself offers) whenever a
        // cycle is actually set; falls back to the old plain "belongs to this company" check when the
        // employee has no cycle_id yet (can't scope-validate against nothing).
        if (!empty($data['default_bank_account_id'])) {
            $cycleIdForScope = !empty($data['cycle_id']) ? (int)$data['cycle_id'] : null;
            $validForCycle = $cycleIdForScope !== null
                ? $paymentMethodModel->isBankAccountValidForCycle($compId, $cycleIdForScope, (int)$data['default_bank_account_id'])
                : false;
            if (!$validForCycle) {
                $stmt = $this->db->prepare("SELECT id FROM `bank_accounts` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL AND status = 'active'");
                $stmt->execute([':id' => (int)$data['default_bank_account_id'], ':comp_id' => $compId]);
                if (!$stmt->fetch()) {
                    return ['status' => false, 'message' => 'Invalid default bank account selected.'];
                }
                if ($cycleIdForScope !== null) {
                    return ['status' => false, 'message' => 'The selected bank account is not available on this employee\'s payroll cycle.'];
                }
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
        // yet decided" state for e.g. employee_type/gender, they always have a sensible
        // default value). Found while adding independent-tab-save support (2026-08-19): once a save
        // could omit these, the generic branch below coerced empty -> null and the INSERT sent an
        // explicit NULL, which overrides the DB's own DEFAULT and throws a NOT NULL violation instead
        // of quietly falling back to it. Coerce to the same default here so that never happens.
        $columnDefaults = [
            'employee_type' => 'domestic', 'employee_status' => 'active', 'gender' => 'male',
            'salary_type' => 'monthly', 'base_salary_amount' => '0.00',
            'mobile_country_code' => '+66',
        ];
        $values = [];
        $encryptedAny = false;
        // 2026-08-26, explicit request: "ตัวข้อมูลเงินเดือนตอนนี้...ไม่ต้องการให้เห็นตัวเลขตรงๆในฐานข้อมูล"
        // -- base_salary_amount joined encryptedColumns() below (same AES-256-GCM mechanism as
        // id_card_no/tax_id_no/bank_account_no/sso_no), so by the end of this loop
        // $values['base_salary_amount'] holds CIPHERTEXT, not the plaintext number. missingPayrollFields()
        // needs the real numeric value (`(float)($values['base_salary_amount']) <= 0`, a threshold
        // check, unlike the plain presence checks empty()'d elsewhere in that method which still work
        // fine on ciphertext) -- captured here, BEFORE encryption, and substituted back in just for
        // that one readiness check below.
        $plainBaseSalaryForReadyCheck = null;
        // 2026-08-31, explicit request ("สิทธิ์ในการมองเห็นเงินเดือน...จะเห็นเป็น XXXX"): a user with
        // masked salary visibility never sees the real base_salary_amount on their own Salary tab
        // (EmployeeController::get() replaces it with PermissionModel::MASK_VALUE = 'XXXX' before
        // it ever reaches the browser) -- but collectEmployeeFormData() in detail.js always resends
        // the WHOLE form on every save, regardless of which tab was actually edited. Without this
        // guard, saving ANY tab while masked would literally encrypt the string "XXXX" over the
        // employee's real salary, permanently destroying it. Detected here (not trusted from the
        // frontend alone, in case of a direct/forged API call) and treated as "leave the existing
        // encrypted value untouched" -- same "fetch existing, fall back" pattern
        // is_payroll_participant/ot_rate_source above already use for their own omitted-key case.
        // Known, accepted limitation: key_version is one column per ROW (shared by every encrypted
        // field), so if this save also genuinely re-encrypts another field (e.g. id_card_no) the
        // row's key_version advances while this preserved ciphertext stays at whatever version it
        // was already at -- a real mismatch only if this app ever actually rotates keys, which it
        // has never done in practice (currentKeyVersion() has only ever returned one constant).
        $preserveExistingBaseSalary = false;
        $existingEncryptedBaseSalary = null;
        if ($id !== null && ($data['base_salary_amount'] ?? null) === 'XXXX') {
            $stmtExistingSalary = $this->db->prepare("SELECT base_salary_amount, key_version FROM `employees` WHERE id = :id AND comp_id = :comp_id");
            $stmtExistingSalary->execute([':id' => $id, ':comp_id' => $compId]);
            $existingSalaryRow = $stmtExistingSalary->fetch(PDO::FETCH_ASSOC);
            if ($existingSalaryRow !== false) {
                $preserveExistingBaseSalary = true;
                $existingEncryptedBaseSalary = $existingSalaryRow['base_salary_amount'];
                // Decrypted server-side ONLY for the is_payroll_ready threshold check further down
                // -- never included in save()'s own return value (status/message only, no employee
                // data echoed back), so this never re-exposes the real number to a masked caller.
                $plainBaseSalaryForReadyCheck = self::decryptSalaryValue($existingEncryptedBaseSalary, isset($existingSalaryRow['key_version']) ? (int)$existingSalaryRow['key_version'] : null);
            }
        }
        foreach ($this->allColumns() as $col) {
            if ($col === 'base_salary_amount' && $preserveExistingBaseSalary) {
                $values[$col] = $existingEncryptedBaseSalary;
                continue;
            }
            if (in_array($col, $booleans, true)) {
                // 2026-08-30 (T020): every OTHER boolean here safely defaults to 0/false when the
                // payload just doesn't mention it (not-enrolled/not-eligible/no-spouse are all
                // reasonable "unless explicitly set" defaults) -- is_payroll_participant is the one
                // exception, since a caller that doesn't yet know about this new field (e.g. an
                // older sync/import code path) must not silently exclude the employee from payroll,
                // NOR silently flip an already-unpaid employee back to paid. Only an EXPLICIT falsy
                // value in the payload turns it off; an absent key keeps whatever this employee
                // already had (existing row on UPDATE, via $existingIsPayrollParticipant fetched
                // above) or the DB column's own DEFAULT 1 (paid, on a brand-new INSERT).
                if ($col === 'is_payroll_participant' && !array_key_exists($col, $data)) {
                    $values[$col] = $existingIsPayrollParticipant ?? 1;
                } else {
                    $values[$col] = !empty($data[$col]) ? 1 : 0;
                }
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
            if ($col === 'base_salary_amount') {
                $plainBaseSalaryForReadyCheck = $val;
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
        // base_salary_amount substituted back to its plaintext value for this ONE check -- see this
        // loop's own comment on why $values['base_salary_amount'] itself is ciphertext by this point.
        $readyCheckValues = $values;
        $readyCheckValues['base_salary_amount'] = $plainBaseSalaryForReadyCheck;
        $values['is_payroll_ready'] = $this->isPayrollReady($readyCheckValues, $isThCompany) ? 1 : 0;

        try {
            if ($id !== null) {
                // Platform Hardening Phase 6 pilot: full old row fetched up front (not just the id
                // the original check needed) so AuditLogModel::record() can diff it against the row's
                // own state after the UPDATE below -- see that class's own docblock for the diff
                // contract, and AUDIT_EXCLUDE_FIELDS below for why the encrypted-at-rest columns are
                // excluded from that diff.
                $stmtCheck = $this->db->prepare("SELECT * FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                $oldRowForAudit = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                if (!$oldRowForAudit) {
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
                if ($paymentMethodFieldProvided) {
                    $paymentMethodModel->saveLines($id, $mixedPaymentLines, $userId);
                }
                if ($foreignWorkerDetailProvided) {
                    $foreignWorkerDetailModel->save($id, $data, $userId);
                }
                $stmtNewRow = $this->db->prepare("SELECT * FROM `employees` WHERE id = :id");
                $stmtNewRow->execute([':id' => $id]);
                $newRowForAudit = $stmtNewRow->fetch(PDO::FETCH_ASSOC) ?: [];
                // Encrypted-at-rest columns (see encryptedColumns() above) produce different
                // ciphertext on every save even when the plaintext is unchanged -- diffing raw
                // ciphertext into the audit log would be both noisy (a false "changed" row on every
                // single save) and pointless (the diff itself isn't human-readable). Excluded here,
                // not from AuditLogModel's own shared denylist, since this is specific to this one
                // model's own schema.
                $auditExcludeFields = array_merge(
                    array_keys($this->encryptedColumns()),
                    array_filter(array_values($this->encryptedColumns())),
                    ['key_version']
                );
                $this->auditLog->record($compId, 'employees', $id, 'update', $oldRowForAudit, $newRowForAudit,
                    $userId, 'web', $ip, $userAgent, $auditExcludeFields);
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
            $newId = (int)$this->db->lastInsertId();
            if ($paymentMethodFieldProvided) {
                $paymentMethodModel->saveLines($newId, $mixedPaymentLines, $userId);
            }
            if ($foreignWorkerDetailProvided) {
                $foreignWorkerDetailModel->save($newId, $data, $userId);
            }
            return ['status' => true, 'message' => 'Created successfully.', 'id' => $newId, 'employee_no' => $values['employee_no']];
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

    /** 2026-09-03, Manual Entry Phase 1A: single-purpose lookup backing ManualEntryController::
     *  employeeContext()'s Attendance auto-fill -- returns null when the employee has no shift_id
     *  set at all (a normal, common state, not an error) so the caller can just clear the field. */
    public function shiftInfo(int $compId, int $employeeId): ?array {
        $stmt = $this->db->prepare(
            "SELECT e.shift_id, s.shift_name_th, s.shift_name_en
             FROM `employees` e LEFT JOIN `shifts` s ON s.id = e.shift_id
             WHERE e.id = :id AND e.comp_id = :comp_id AND e.deleted_at IS NULL"
        );
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['shift_id'] === null) {
            return null;
        }
        return $row;
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

    /** 2026-09-03: `passport_copy`/`visa_copy` added alongside EmployeeSyncer's own new
     *  document-scan sync (see that class's own `documentScansFromItem()`/`syncDocumentScans()`
     *  docblocks) -- `work_permit_copy` already existed and is reused as-is for the synced work
     *  permit scan rather than adding a redundant 4th "scan" variant of the same document. Keep
     *  EmployeeSyncer's own `DOCUMENT_TYPE_*` mapping in sync if either list changes. */
    public function documentTypes(): array {
        return ['id_card_copy', 'house_registration_copy', 'work_permit_copy', 'employment_contract',
                'bank_book_copy', 'resume', 'education_certificate', 'passport_copy', 'visa_copy', 'other'];
    }

    public function listDocuments(int $employeeId, int $compId): array {
        if (!$this->employeeBelongsToComp($employeeId, $compId)) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT id, document_type, source, file_name, file_size, thumbnail_path, uploaded_at FROM `employee_documents` WHERE employee_id = :employee_id AND deleted_at IS NULL ORDER BY uploaded_at DESC");
        $stmt->execute([':employee_id' => $employeeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveDocument(int $employeeId, int $compId, string $documentType, string $fileName, string $filePath, int $userId, ?int $fileSize = null, ?string $thumbnailPath = null): array {
        if (!$this->employeeBelongsToComp($employeeId, $compId)) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }
        if (!in_array($documentType, $this->documentTypes(), true)) {
            return ['status' => false, 'message' => 'Invalid document_type.'];
        }
        try {
            $stmt = $this->db->prepare("INSERT INTO `employee_documents` (employee_id, document_type, file_name, file_path, file_size, thumbnail_path, uploaded_by) VALUES (:employee_id, :document_type, :file_name, :file_path, :file_size, :thumbnail_path, :uploaded_by)");
            $stmt->execute([
                ':employee_id' => $employeeId,
                ':document_type' => $documentType,
                ':file_name' => $fileName,
                ':file_path' => $filePath,
                ':file_size' => $fileSize,
                ':thumbnail_path' => $thumbnailPath,
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
