<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/Database.php';

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

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
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
            return $row;
        }
        return [
            'comp_id' => $compId, 'reopen_window_days' => null,
            'probation_period_days' => null, 'probation_defer_pvd' => false,
            'probation_defer_recurring_earning' => false, 'probation_base_salary_ratio' => null,
            'pay_basis' => 'full_month', 'pay_basis_deduct_holidays' => false, 'pay_basis_deduct_leave' => false,
            'intern_defer_pvd' => false, 'intern_defer_recurring_earning' => false, 'intern_base_salary_ratio' => null,
        ];
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

    public function save(int $compId, array $data, int $userId): array {
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

        $stmt = $this->db->prepare(
            "INSERT INTO `company_payroll_policies` (comp_id, reopen_window_days, probation_period_days, probation_defer_pvd, probation_defer_recurring_earning, probation_base_salary_ratio, intern_defer_pvd, intern_defer_recurring_earning, intern_base_salary_ratio, pay_basis, pay_basis_deduct_holidays, pay_basis_deduct_leave, updated_by)
             VALUES (:comp_id, :reopen_window_days, :probation_period_days, :probation_defer_pvd, :probation_defer_recurring_earning, :probation_base_salary_ratio, :intern_defer_pvd, :intern_defer_recurring_earning, :intern_base_salary_ratio, :pay_basis, :pay_basis_deduct_holidays, :pay_basis_deduct_leave, :updated_by)
             ON DUPLICATE KEY UPDATE reopen_window_days = VALUES(reopen_window_days), probation_period_days = VALUES(probation_period_days),
                probation_defer_pvd = VALUES(probation_defer_pvd), probation_defer_recurring_earning = VALUES(probation_defer_recurring_earning),
                probation_base_salary_ratio = VALUES(probation_base_salary_ratio),
                intern_defer_pvd = VALUES(intern_defer_pvd), intern_defer_recurring_earning = VALUES(intern_defer_recurring_earning),
                intern_base_salary_ratio = VALUES(intern_base_salary_ratio),
                pay_basis = VALUES(pay_basis), pay_basis_deduct_holidays = VALUES(pay_basis_deduct_holidays), pay_basis_deduct_leave = VALUES(pay_basis_deduct_leave),
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
            ':updated_by' => $userId,
        ]);

        return ['status' => true, 'message' => 'Saved.'];
    }
}
