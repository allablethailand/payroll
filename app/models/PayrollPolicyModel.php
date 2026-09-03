<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/AuditLogModel.php';

/**
 * Company-wide "Payroll Policies" settings (Payroll Configuration's own new tab, 2026-08-30,
 * explicit request: "ต้องการเปิดกลับมาแก้ไข อยากให้มีการตั้งค่าได้ว่า หลังจากปิดรอบต้องกี่วันถึงจะสามารถ
 * ดึงกลับมาได้ เพิ่มอีก Tab เป็น Tab ตั้งค่า...เดี๋ยวมีอีกหลายหัวข้อครับ"). One row per company
 * (singleton, auto-created on first save() -- no separate "create" step), holding heterogeneous
 * settings added incrementally as new topics land in this same tab -- reopen_window_days was the
 * first, probation pay conditions (2026-08-30, same day) the second batch. See
 * database/migrations/2026-08-30_2_payroll_policies_table.sql and
 * database/migrations/2026-08-30_4_probation_pay_policy.sql's own header comments.
 *
 * Probation gating uses `employees.employment_status = 'probation'` (the real, HR-maintained
 * current status), NOT a day-count computed from probation_period_days -- see that column's own
 * migration comment for why. probation_period_days is reference/display only.
 */
class PayrollPolicyModel {
    private PDO $db;
    private AuditLogModel $auditLog;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->auditLog = new AuditLogModel($this->db);
    }

    /** Always returns a row (defaults, never persisted, when the company hasn't saved anything yet). */
    public function get(int $compId): array {
        $stmt = $this->db->prepare("SELECT * FROM `company_payroll_policies` WHERE comp_id = :comp_id");
        $stmt->execute([':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['reopen_window_days'] = $row['reopen_window_days'] !== null ? (int)$row['reopen_window_days'] : null;
            $row['probation_period_days'] = $row['probation_period_days'] !== null ? (int)$row['probation_period_days'] : null;
            $row['probation_defer_pvd'] = (bool)$row['probation_defer_pvd'];
            $row['probation_defer_recurring_earning'] = (bool)$row['probation_defer_recurring_earning'];
            $row['probation_base_salary_ratio'] = $row['probation_base_salary_ratio'] !== null ? (float)$row['probation_base_salary_ratio'] : null;
            $row['pay_basis'] = $row['pay_basis'] ?? 'full_month';
            $row['pay_basis_deduct_holidays'] = (bool)($row['pay_basis_deduct_holidays'] ?? false);
            $row['pay_basis_deduct_leave'] = (bool)($row['pay_basis_deduct_leave'] ?? false);
            // 2026-08-31, explicit request: internship pay conditions -- own separate field set,
            // same shape as probation_* immediately above, gated by employment_type='internship'
            // instead of employment_status='probation' (see PayrollRunModel's own precedence
            // comment for what happens when an employee is somehow both).
            $row['intern_defer_pvd'] = (bool)($row['intern_defer_pvd'] ?? false);
            $row['intern_defer_recurring_earning'] = (bool)($row['intern_defer_recurring_earning'] ?? false);
            $row['intern_base_salary_ratio'] = $row['intern_base_salary_ratio'] !== null ? (float)$row['intern_base_salary_ratio'] : null;
            // 2026-08-31, explicit request: "เงื่อนไขการจ่ายเงินเด็กฝึกงาน...จ่ายเต็มเดือน หรือจ่ายแค่วันที่มา
            // ทำจริง หักลา หักวันหยุดไหม เหมือน Probation" -- own separate mirror of pay_basis/
            // pay_basis_deduct_holidays/pay_basis_deduct_leave immediately above, same shape.
            $row['intern_pay_basis'] = $row['intern_pay_basis'] ?? 'full_month';
            $row['intern_pay_basis_deduct_holidays'] = (bool)($row['intern_pay_basis_deduct_holidays'] ?? false);
            $row['intern_pay_basis_deduct_leave'] = (bool)($row['intern_pay_basis_deduct_leave'] ?? false);
            // 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย"): a company-configured flat
            // withholding rate for a supplemental sync run pulled with attribution_tax_treatment=
            // 'separate' -- Origami's own PAYROLL_SYNC_API.md is explicit it has no tax-rate
            // concept of its own, so this is entirely this app's own configurable policy, never a
            // hardcoded "correct" rate -- see flatTaxRateSettings() below.
            $row['supplemental_flat_tax_rate_percent'] = $row['supplemental_flat_tax_rate_percent'] !== null ? (float)$row['supplemental_flat_tax_rate_percent'] : null;
            // 2026-09-02, explicit request: extend Probation/Internship pay policy with leave/OT
            // rights during the period + an internship duration reference. Same "reference/display
            // only" contract as probation_period_days for the two *_period_days fields (see that
            // column's own migration comment) -- neither auto-transitions employment_status/type,
            // this app has no cron/scheduled-job infrastructure. allow_leave_during_* default true
            // (1) so a company that never visits this section sees zero behavior change.
            $row['intern_period_days'] = $row['intern_period_days'] !== null ? (int)$row['intern_period_days'] : null;
            $row['probation_leave_days_limit'] = $row['probation_leave_days_limit'] !== null ? (int)$row['probation_leave_days_limit'] : null;
            $row['allow_leave_during_probation'] = (bool)($row['allow_leave_during_probation'] ?? true);
            $row['probation_ot_eligible_default'] = $row['probation_ot_eligible_default'] !== null ? (bool)$row['probation_ot_eligible_default'] : null;
            $row['intern_leave_days_limit'] = $row['intern_leave_days_limit'] !== null ? (int)$row['intern_leave_days_limit'] : null;
            $row['allow_leave_during_intern'] = (bool)($row['allow_leave_during_intern'] ?? true);
            $row['intern_ot_eligible_default'] = $row['intern_ot_eligible_default'] !== null ? (bool)$row['intern_ot_eligible_default'] : null;
            // 2026-09-02, follow-up to close a review-flagged gap: "เงื่อนไขการหักภาษี/ประกันสังคมที่
            // แตกต่างจากพนักงานปกติ (ถ้ามี)" was never actually built -- probation_defer_pvd/
            // intern_defer_pvd (pre-existing) only covered PVD. defer_sso is the exact same
            // mechanism, just for SSO (see PayrollRunModel::recalculate()'s own defer_pvd comment
            // for the shared StatutoryCalculationEngine::$employeeFlags gate this feeds).
            // tax_exempt_default is a soft, CREATE-TIME-ONLY default for the ALREADY-existing
            // employees.tax_exempt checkbox (same contract as *_ot_eligible_default) -- not a new
            // tax formula, this app has no basis to invent a probation-specific PIT rule Thai law
            // itself doesn't define.
            $row['probation_defer_sso'] = (bool)($row['probation_defer_sso'] ?? false);
            $row['probation_tax_exempt_default'] = $row['probation_tax_exempt_default'] !== null ? (bool)$row['probation_tax_exempt_default'] : null;
            $row['intern_defer_sso'] = (bool)($row['intern_defer_sso'] ?? false);
            $row['intern_tax_exempt_default'] = $row['intern_tax_exempt_default'] !== null ? (bool)$row['intern_tax_exempt_default'] : null;
            return $row;
        }
        return [
            'comp_id' => $compId, 'reopen_window_days' => null,
            'probation_period_days' => null, 'probation_defer_pvd' => false,
            'probation_defer_recurring_earning' => false, 'probation_base_salary_ratio' => null,
            'pay_basis' => 'full_month', 'pay_basis_deduct_holidays' => false, 'pay_basis_deduct_leave' => false,
            'intern_defer_pvd' => false, 'intern_defer_recurring_earning' => false, 'intern_base_salary_ratio' => null,
            'intern_pay_basis' => 'full_month', 'intern_pay_basis_deduct_holidays' => false, 'intern_pay_basis_deduct_leave' => false,
            'supplemental_flat_tax_rate_percent' => null,
            'intern_period_days' => null, 'probation_leave_days_limit' => null, 'allow_leave_during_probation' => true,
            'probation_ot_eligible_default' => null, 'intern_leave_days_limit' => null, 'allow_leave_during_intern' => true,
            'intern_ot_eligible_default' => null,
            'probation_defer_sso' => false, 'probation_tax_exempt_default' => null,
            'intern_defer_sso' => false, 'intern_tax_exempt_default' => null,
        ];
    }

    /** Precomputed-flags-param convention, same as probationSettings()/payBasisSettings() above --
     *  PayrollRunModel::recalculate() calls this ONCE per run. Returns null (never a default
     *  fallback number) when the company hasn't configured a rate -- a run with
     *  use_flat_tax_rate=1 but no rate configured falls back to the normal PIT calculation rather
     *  than silently withholding 0% or guessing a rate, see recalculate()'s own use of this. */
    public function flatTaxRatePercent(int $compId): ?float {
        return $this->get($compId)['supplemental_flat_tax_rate_percent'];
    }

    /**
     * Same "engine takes a precomputed flags param" convention as StatutoryCalculationEngine's own
     * $employeeFlags / SyncPayResolver's own $exemptEventCodes -- PayrollRunModel::recalculate()
     * calls this ONCE per run (not per employee, this is a company-wide singleton) and reads the
     * result directly rather than re-querying company_payroll_policies inside a per-employee loop.
     */
    public function probationSettings(int $compId): array {
        $row = $this->get($compId);
        return [
            'defer_pvd' => $row['probation_defer_pvd'],
            'defer_recurring_earning' => $row['probation_defer_recurring_earning'],
            'base_salary_ratio' => $row['probation_base_salary_ratio'], // null = 100%, no reduction
            'period_days' => $row['probation_period_days'], // reference/display only, never a gate
            'leave_days_limit' => $row['probation_leave_days_limit'],
            'allow_leave' => $row['allow_leave_during_probation'],
            'ot_eligible_default' => $row['probation_ot_eligible_default'], // null = no default configured
            'defer_sso' => $row['probation_defer_sso'],
            'tax_exempt_default' => $row['probation_tax_exempt_default'], // null = no default configured
        ];
    }

    /** Same "engine takes a precomputed flags param, called once per run not per employee" convention
     *  as probationSettings() immediately above -- direct mirror, own separate field set (see this
     *  class's own get() docblock comment / the migration's own header for why these are kept
     *  independent of probation_* rather than reused). */
    public function internSettings(int $compId): array {
        $row = $this->get($compId);
        return [
            'defer_pvd' => $row['intern_defer_pvd'],
            'defer_recurring_earning' => $row['intern_defer_recurring_earning'],
            'base_salary_ratio' => $row['intern_base_salary_ratio'], // null = 100%, no reduction
            'period_days' => $row['intern_period_days'], // reference/display only, never a gate
            'leave_days_limit' => $row['intern_leave_days_limit'],
            'allow_leave' => $row['allow_leave_during_intern'],
            'ot_eligible_default' => $row['intern_ot_eligible_default'], // null = no default configured
            'defer_sso' => $row['intern_defer_sso'],
            'tax_exempt_default' => $row['intern_tax_exempt_default'], // null = no default configured
        ];
    }

    /** Same "engine takes a precomputed flags param, called once per run not per employee" convention
     *  as probationSettings() above. See scheduledPayableDaysForEmployee()'s own docblock (SetupRulesModel)
     *  for the full pay_basis='schedule_based' calculation this feeds into.
     *  2026-08-30, same-day explicit correction ("พื้นฐานการจ่ายเงินเดือน ที่ตั้งจะนำไปคำนวณแค่พนักงานที่
     *  ทดลองงานใช่ไหม ถ้าไม่ใช่ช่วยปรับให้เป็นเงื่อนไขของการทดลองงานเท่านั้น"): despite living on the same
     *  generic-looking company_payroll_policies.pay_basis column (not a probation_*-prefixed one),
     *  this is now a PROBATION-ONLY condition -- PayrollRunModel::recalculate() only takes the
     *  schedule_based branch when employees.employment_status === 'probation', same real gate
     *  probationSettings()'s own fields already use. It was NOT probation-gated when first shipped
     *  earlier the same day (applied to every monthly employee company-wide) -- this is a genuine
     *  scope-narrowing correction, not a pre-existing behavior. */
    public function payBasisSettings(int $compId): array {
        $row = $this->get($compId);
        return [
            'pay_basis' => $row['pay_basis'],
            'deduct_holidays' => $row['pay_basis_deduct_holidays'],
            'deduct_leave' => $row['pay_basis_deduct_leave'],
        ];
    }

    /** Direct mirror of payBasisSettings() above, own separate field set -- gated by
     *  employees.employment_type === 'internship' instead of employment_status === 'probation' (see
     *  PayrollRunModel::recalculate()'s own precedence comment for what happens when an employee is
     *  somehow both). */
    public function internPayBasisSettings(int $compId): array {
        $row = $this->get($compId);
        return [
            'pay_basis' => $row['intern_pay_basis'],
            'deduct_holidays' => $row['intern_pay_basis_deduct_holidays'],
            'deduct_leave' => $row['intern_pay_basis_deduct_leave'],
        ];
    }

    public function save(int $compId, array $data, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        // Platform Hardening Phase 6 pilot: singleton-per-company upsert -- fetched up front so
        // AuditLogModel::record() can diff it against the row's own state after the upsert below
        // (action='create' the very first time a company saves this tab, 'update' every time after).
        $stmtOld = $this->db->prepare("SELECT * FROM `company_payroll_policies` WHERE comp_id = :comp_id");
        $stmtOld->execute([':comp_id' => $compId]);
        $oldRowForAudit = $stmtOld->fetch(PDO::FETCH_ASSOC) ?: null;
        $reopenWindowDays = null;
        if (isset($data['reopen_window_days']) && $data['reopen_window_days'] !== '' && $data['reopen_window_days'] !== null) {
            if (!is_numeric($data['reopen_window_days']) || (int)$data['reopen_window_days'] < 0) {
                return ['status' => false, 'message' => 'Reopen window days must be a non-negative number, or left blank for unlimited.'];
            }
            $reopenWindowDays = (int)$data['reopen_window_days'];
        }

        $probationPeriodDays = null;
        if (isset($data['probation_period_days']) && $data['probation_period_days'] !== '' && $data['probation_period_days'] !== null) {
            if (!is_numeric($data['probation_period_days']) || (int)$data['probation_period_days'] < 0) {
                return ['status' => false, 'message' => 'Probation period days must be a non-negative number, or left blank.'];
            }
            $probationPeriodDays = (int)$data['probation_period_days'];
        }

        $probationBaseSalaryRatio = null;
        if (isset($data['probation_base_salary_ratio']) && $data['probation_base_salary_ratio'] !== '' && $data['probation_base_salary_ratio'] !== null) {
            if (!is_numeric($data['probation_base_salary_ratio']) || (float)$data['probation_base_salary_ratio'] <= 0 || (float)$data['probation_base_salary_ratio'] > 100) {
                return ['status' => false, 'message' => 'Probation base salary ratio must be a percentage between 0 (exclusive) and 100, or left blank for no reduction.'];
            }
            $probationBaseSalaryRatio = (float)$data['probation_base_salary_ratio'];
        }

        $probationDeferPvd = !empty($data['probation_defer_pvd']) ? 1 : 0;
        $probationDeferRecurringEarning = !empty($data['probation_defer_recurring_earning']) ? 1 : 0;

        // 2026-08-31, explicit request: internship pay conditions -- own separate field set, same
        // validation shape as probation_base_salary_ratio immediately above.
        $internBaseSalaryRatio = null;
        if (isset($data['intern_base_salary_ratio']) && $data['intern_base_salary_ratio'] !== '' && $data['intern_base_salary_ratio'] !== null) {
            if (!is_numeric($data['intern_base_salary_ratio']) || (float)$data['intern_base_salary_ratio'] <= 0 || (float)$data['intern_base_salary_ratio'] > 100) {
                return ['status' => false, 'message' => 'Intern base salary ratio must be a percentage between 0 (exclusive) and 100, or left blank for no reduction.'];
            }
            $internBaseSalaryRatio = (float)$data['intern_base_salary_ratio'];
        }
        $internDeferPvd = !empty($data['intern_defer_pvd']) ? 1 : 0;
        $internDeferRecurringEarning = !empty($data['intern_defer_recurring_earning']) ? 1 : 0;

        $payBasis = in_array($data['pay_basis'] ?? '', ['full_month', 'schedule_based', 'sync_actual_days'], true) ? $data['pay_basis'] : 'full_month';
        $payBasisDeductHolidays = !empty($data['pay_basis_deduct_holidays']) ? 1 : 0;
        $payBasisDeductLeave = !empty($data['pay_basis_deduct_leave']) ? 1 : 0;

        // 2026-08-31, explicit request: intern pay_basis -- direct mirror of pay_basis/
        // pay_basis_deduct_holidays/pay_basis_deduct_leave immediately above, own separate columns.
        $internPayBasis = in_array($data['intern_pay_basis'] ?? '', ['full_month', 'schedule_based', 'sync_actual_days'], true) ? $data['intern_pay_basis'] : 'full_month';
        $internPayBasisDeductHolidays = !empty($data['intern_pay_basis_deduct_holidays']) ? 1 : 0;
        $internPayBasisDeductLeave = !empty($data['intern_pay_basis_deduct_leave']) ? 1 : 0;

        // 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย"): flat withholding rate for a
        // supplemental run's own 'separate' tax treatment -- see flatTaxRatePercent()'s own docblock.
        $supplementalFlatTaxRatePercent = null;
        if (isset($data['supplemental_flat_tax_rate_percent']) && $data['supplemental_flat_tax_rate_percent'] !== '' && $data['supplemental_flat_tax_rate_percent'] !== null) {
            if (!is_numeric($data['supplemental_flat_tax_rate_percent']) || (float)$data['supplemental_flat_tax_rate_percent'] < 0 || (float)$data['supplemental_flat_tax_rate_percent'] > 100) {
                return ['status' => false, 'message' => 'Supplemental flat tax rate must be a percentage between 0 and 100, or left blank.'];
            }
            $supplementalFlatTaxRatePercent = (float)$data['supplemental_flat_tax_rate_percent'];
        }

        // 2026-09-02, explicit request: extend Probation/Internship pay policy with leave/OT rights
        // during the period + an internship duration reference -- same validation shape as the
        // existing *_period_days/*_base_salary_ratio fields above.
        $internPeriodDays = null;
        if (isset($data['intern_period_days']) && $data['intern_period_days'] !== '' && $data['intern_period_days'] !== null) {
            if (!is_numeric($data['intern_period_days']) || (int)$data['intern_period_days'] < 0) {
                return ['status' => false, 'message' => 'Intern period days must be a non-negative number, or left blank.'];
            }
            $internPeriodDays = (int)$data['intern_period_days'];
        }
        $probationLeaveDaysLimit = null;
        if (isset($data['probation_leave_days_limit']) && $data['probation_leave_days_limit'] !== '' && $data['probation_leave_days_limit'] !== null) {
            if (!is_numeric($data['probation_leave_days_limit']) || (int)$data['probation_leave_days_limit'] < 0) {
                return ['status' => false, 'message' => 'Probation leave days limit must be a non-negative number, or left blank.'];
            }
            $probationLeaveDaysLimit = (int)$data['probation_leave_days_limit'];
        }
        $internLeaveDaysLimit = null;
        if (isset($data['intern_leave_days_limit']) && $data['intern_leave_days_limit'] !== '' && $data['intern_leave_days_limit'] !== null) {
            if (!is_numeric($data['intern_leave_days_limit']) || (int)$data['intern_leave_days_limit'] < 0) {
                return ['status' => false, 'message' => 'Intern leave days limit must be a non-negative number, or left blank.'];
            }
            $internLeaveDaysLimit = (int)$data['intern_leave_days_limit'];
        }
        // allow_leave_during_* defaults TRUE (unlike the other checkboxes above, which default
        // false/off) -- these arrive from a real <input type=checkbox> checked-by-default in the UI,
        // so "key absent" (unchecked, browsers omit unchecked checkboxes from form submission) must
        // still resolve to true here or the very first save from that form would silently flip it
        // off for every company. array_key_exists distinguishes "field present, value 0" (explicit
        // uncheck) from "field genuinely never sent" (a non-browser caller) the same way this
        // project's own ot_rate_source precedent (EmployeeModel::save()) already established.
        $allowLeaveDuringProbation = array_key_exists('allow_leave_during_probation', $data) ? (!empty($data['allow_leave_during_probation']) ? 1 : 0) : 1;
        $allowLeaveDuringIntern = array_key_exists('allow_leave_during_intern', $data) ? (!empty($data['allow_leave_during_intern']) ? 1 : 0) : 1;
        $probationOtEligibleDefault = null;
        if (isset($data['probation_ot_eligible_default']) && $data['probation_ot_eligible_default'] !== '' && $data['probation_ot_eligible_default'] !== null) {
            $probationOtEligibleDefault = !empty($data['probation_ot_eligible_default']) ? 1 : 0;
        }
        $internOtEligibleDefault = null;
        if (isset($data['intern_ot_eligible_default']) && $data['intern_ot_eligible_default'] !== '' && $data['intern_ot_eligible_default'] !== null) {
            $internOtEligibleDefault = !empty($data['intern_ot_eligible_default']) ? 1 : 0;
        }

        // 2026-09-02, follow-up to close a review-flagged gap: SSO deferral (same mechanism as
        // defer_pvd above) + a soft tax-exempt default (same tri-state contract as
        // *_ot_eligible_default above).
        $probationDeferSso = !empty($data['probation_defer_sso']) ? 1 : 0;
        $internDeferSso = !empty($data['intern_defer_sso']) ? 1 : 0;
        $probationTaxExemptDefault = null;
        if (isset($data['probation_tax_exempt_default']) && $data['probation_tax_exempt_default'] !== '' && $data['probation_tax_exempt_default'] !== null) {
            $probationTaxExemptDefault = !empty($data['probation_tax_exempt_default']) ? 1 : 0;
        }
        $internTaxExemptDefault = null;
        if (isset($data['intern_tax_exempt_default']) && $data['intern_tax_exempt_default'] !== '' && $data['intern_tax_exempt_default'] !== null) {
            $internTaxExemptDefault = !empty($data['intern_tax_exempt_default']) ? 1 : 0;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO `company_payroll_policies` (comp_id, reopen_window_days, probation_period_days, probation_defer_pvd, probation_defer_recurring_earning, probation_base_salary_ratio, intern_defer_pvd, intern_defer_recurring_earning, intern_base_salary_ratio, pay_basis, pay_basis_deduct_holidays, pay_basis_deduct_leave, intern_pay_basis, intern_pay_basis_deduct_holidays, intern_pay_basis_deduct_leave, supplemental_flat_tax_rate_percent, intern_period_days, probation_leave_days_limit, allow_leave_during_probation, probation_ot_eligible_default, intern_leave_days_limit, allow_leave_during_intern, intern_ot_eligible_default, probation_defer_sso, probation_tax_exempt_default, intern_defer_sso, intern_tax_exempt_default, updated_by)
             VALUES (:comp_id, :reopen_window_days, :probation_period_days, :probation_defer_pvd, :probation_defer_recurring_earning, :probation_base_salary_ratio, :intern_defer_pvd, :intern_defer_recurring_earning, :intern_base_salary_ratio, :pay_basis, :pay_basis_deduct_holidays, :pay_basis_deduct_leave, :intern_pay_basis, :intern_pay_basis_deduct_holidays, :intern_pay_basis_deduct_leave, :supplemental_flat_tax_rate_percent, :intern_period_days, :probation_leave_days_limit, :allow_leave_during_probation, :probation_ot_eligible_default, :intern_leave_days_limit, :allow_leave_during_intern, :intern_ot_eligible_default, :probation_defer_sso, :probation_tax_exempt_default, :intern_defer_sso, :intern_tax_exempt_default, :updated_by)
             ON DUPLICATE KEY UPDATE reopen_window_days = VALUES(reopen_window_days), probation_period_days = VALUES(probation_period_days),
                probation_defer_pvd = VALUES(probation_defer_pvd), probation_defer_recurring_earning = VALUES(probation_defer_recurring_earning),
                probation_base_salary_ratio = VALUES(probation_base_salary_ratio),
                intern_defer_pvd = VALUES(intern_defer_pvd), intern_defer_recurring_earning = VALUES(intern_defer_recurring_earning),
                intern_base_salary_ratio = VALUES(intern_base_salary_ratio),
                pay_basis = VALUES(pay_basis), pay_basis_deduct_holidays = VALUES(pay_basis_deduct_holidays), pay_basis_deduct_leave = VALUES(pay_basis_deduct_leave),
                intern_pay_basis = VALUES(intern_pay_basis), intern_pay_basis_deduct_holidays = VALUES(intern_pay_basis_deduct_holidays), intern_pay_basis_deduct_leave = VALUES(intern_pay_basis_deduct_leave),
                supplemental_flat_tax_rate_percent = VALUES(supplemental_flat_tax_rate_percent),
                intern_period_days = VALUES(intern_period_days), probation_leave_days_limit = VALUES(probation_leave_days_limit),
                allow_leave_during_probation = VALUES(allow_leave_during_probation), probation_ot_eligible_default = VALUES(probation_ot_eligible_default),
                intern_leave_days_limit = VALUES(intern_leave_days_limit), allow_leave_during_intern = VALUES(allow_leave_during_intern),
                intern_ot_eligible_default = VALUES(intern_ot_eligible_default),
                probation_defer_sso = VALUES(probation_defer_sso), probation_tax_exempt_default = VALUES(probation_tax_exempt_default),
                intern_defer_sso = VALUES(intern_defer_sso), intern_tax_exempt_default = VALUES(intern_tax_exempt_default),
                updated_by = VALUES(updated_by)"
        );
        $stmt->execute([
            ':comp_id' => $compId,
            ':reopen_window_days' => $reopenWindowDays,
            ':probation_period_days' => $probationPeriodDays,
            ':probation_defer_pvd' => $probationDeferPvd,
            ':probation_defer_recurring_earning' => $probationDeferRecurringEarning,
            ':pay_basis' => $payBasis,
            ':pay_basis_deduct_holidays' => $payBasisDeductHolidays,
            ':pay_basis_deduct_leave' => $payBasisDeductLeave,
            ':probation_base_salary_ratio' => $probationBaseSalaryRatio,
            ':intern_defer_pvd' => $internDeferPvd,
            ':intern_defer_recurring_earning' => $internDeferRecurringEarning,
            ':intern_base_salary_ratio' => $internBaseSalaryRatio,
            ':intern_pay_basis' => $internPayBasis,
            ':intern_pay_basis_deduct_holidays' => $internPayBasisDeductHolidays,
            ':intern_pay_basis_deduct_leave' => $internPayBasisDeductLeave,
            ':supplemental_flat_tax_rate_percent' => $supplementalFlatTaxRatePercent,
            ':intern_period_days' => $internPeriodDays,
            ':probation_leave_days_limit' => $probationLeaveDaysLimit,
            ':allow_leave_during_probation' => $allowLeaveDuringProbation,
            ':probation_ot_eligible_default' => $probationOtEligibleDefault,
            ':intern_leave_days_limit' => $internLeaveDaysLimit,
            ':allow_leave_during_intern' => $allowLeaveDuringIntern,
            ':intern_ot_eligible_default' => $internOtEligibleDefault,
            ':probation_defer_sso' => $probationDeferSso,
            ':probation_tax_exempt_default' => $probationTaxExemptDefault,
            ':intern_defer_sso' => $internDeferSso,
            ':intern_tax_exempt_default' => $internTaxExemptDefault,
            ':updated_by' => $userId,
        ]);

        $stmtNew = $this->db->prepare("SELECT * FROM `company_payroll_policies` WHERE comp_id = :comp_id");
        $stmtNew->execute([':comp_id' => $compId]);
        $newRowForAudit = $stmtNew->fetch(PDO::FETCH_ASSOC) ?: [];
        $recordId = isset($newRowForAudit['id']) ? (int)$newRowForAudit['id'] : $compId;
        if ($oldRowForAudit === null) {
            $this->auditLog->record($compId, 'company_payroll_policies', $recordId, 'create', null, $newRowForAudit, $userId, 'web', $ip, $userAgent);
        } else {
            $this->auditLog->record($compId, 'company_payroll_policies', $recordId, 'update', $oldRowForAudit, $newRowForAudit, $userId, 'web', $ip, $userAgent);
        }

        return ['status' => true, 'message' => 'Saved.'];
    }
}
