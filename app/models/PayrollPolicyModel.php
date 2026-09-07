<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/AuditLogModel.php';
require_once __DIR__ . '/EntityAssignmentModel.php';

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
    private EntityAssignmentModel $assignmentModel;

    /** entity_type this feature registers itself under in T055's shared entity_assignments table --
     *  see EntityAssignmentModel's own docblock ("Open note for whoever builds T054/T056 next"). */
    private const ENTITY_TYPE = 'probation_policy_set';

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->auditLog = new AuditLogModel($this->db);
        $this->assignmentModel = new EntityAssignmentModel($this->db);
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
     * 2026-09-04, Backlog Phase 10, T056 ("Probation setting gains Clone + Assign, using T055's
     * template"): probation policy is no longer a single company-wide singleton -- it's now
     * `probation_policy_sets`, multiple named/cloneable/assignable Sets with exactly one mandatory
     * Default (same architecture as `OtRateSetModel`/`ot_rate_sets`, the closest existing precedent
     * in this codebase -- see that model's own docblock). Assignment scoping uses T055's generic
     * `EntityAssignmentModel` (`entity_type = 'probation_policy_set'`) rather than a bespoke 4th
     * per-feature assignment table -- this is the literal reason T055 was built ahead of any real
     * consumer, see that model's own "Open note for whoever builds T054/T056 next".
     *
     * `company_payroll_policies.probation_*` (the OLD singleton columns) are left in the schema,
     * UNUSED going forward -- see database/migrations/2026-09-04_5_probation_policy_sets.sql's own
     * header for the full "why not drop them" reasoning. `save()` above still technically
     * reads/writes them (untouched, since that one method also handles several UNRELATED settings on
     * the same row -- reopen_window_days, pay_basis, intern_ fields, supplemental_flat_tax_rate_percent --
     * touching it risks those) but nothing reads them for real calculation any more; the Payroll
     * Policies settings form's own probation_* fields were removed from the UI (see
     * payroll-configuration.js) so nothing submits those keys any more either.
     *
     * `probationSettings(compId, employeeId = null)`: the `$employeeId` param is NEW and OPTIONAL,
     * backward-compatible with every pre-T056 call shape.
     *   - $employeeId given: resolves which Set applies to THIS employee -- iterates ACTIVE,
     *     non-default Sets (ordered by `id ASC`, i.e. oldest-created-set-wins on a genuine overlap --
     *     see probationSetSave()'s own docblock for why overlapping assignments across 2 Sets are
     *     allowed rather than rejected), calling EntityAssignmentModel::resolveForEmployee() for
     *     each; the first match wins. No match -> falls back to the `is_default=1` Set. Neither
     *     exists at all (a company that never migrated/configured anything, e.g. every fresh company
     *     going forward) -> returns the EXACT SAME all-nulls/all-defaults shape this method already
     *     returned pre-T056, so a company with zero probation config sees zero behavior change.
     *   - $employeeId omitted: same as before T056 existed -- resolves the company's Default Set (or
     *     the legacy company_payroll_policies row's own values, for a company whose data was never
     *     backfilled into a Set because it had nothing configured) -- kept for any caller that still
     *     wants "the company's general probation policy" without resolving a specific employee.
     */
    public function probationSettings(int $compId, ?int $employeeId = null): array {
        $defaultShape = [
            'defer_pvd' => false, 'defer_recurring_earning' => false, 'base_salary_ratio' => null,
            'period_days' => null, 'leave_days_limit' => null, 'allow_leave' => true,
            'ot_eligible_default' => null, 'defer_sso' => false, 'tax_exempt_default' => null,
        ];

        $setRow = null;
        if ($employeeId !== null) {
            $stmtNonDefault = $this->db->prepare(
                "SELECT * FROM `probation_policy_sets` WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL AND is_default = 0 ORDER BY id ASC"
            );
            $stmtNonDefault->execute([':comp_id' => $compId]);
            foreach ($stmtNonDefault->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
                $candidateId = (int)$candidate['id'];
                // EntityAssignmentModel::resolveForEmployee() treats ZERO assignment rows as
                // "unscoped, matches everyone" -- correct for its other consumers (a Payslip/ECT
                // Template with no assignment rows genuinely IS the unscoped general default), but
                // WRONG here: only the is_default=1 Set is allowed to be this feature's catch-all. A
                // non-default Set that has (however it happened -- e.g. briefly made default via
                // probationSetSetDefault(), which force-clears assignments, then demoted again by a
                // DIFFERENT Set being made default) ended up with zero assignment rows must NOT
                // silently start matching every employee -- skip it entirely rather than letting
                // resolveForEmployee()'s own zero-rows semantics leak through. Real bug caught by
                // this file's own test (an orphaned zero-assignment non-default Set was matching
                // employees it had no business matching) before this reached PayrollRunModel.
                if (empty($this->assignmentModel->getAssignments($compId, self::ENTITY_TYPE, $candidateId))) {
                    continue;
                }
                if ($this->assignmentModel->resolveForEmployee($compId, self::ENTITY_TYPE, $candidateId, $employeeId)) {
                    $setRow = $candidate;
                    break;
                }
            }
        }
        if ($setRow === null) {
            $stmtDefault = $this->db->prepare(
                "SELECT * FROM `probation_policy_sets` WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL AND is_default = 1 LIMIT 1"
            );
            $stmtDefault->execute([':comp_id' => $compId]);
            $setRow = $stmtDefault->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($setRow === null) {
            // No Sets at all for this company (never configured/migrated) -- same all-defaults shape
            // this method has always returned for "nothing configured", zero behavior change.
            return $defaultShape;
        }
        return [
            'defer_pvd' => (bool)$setRow['probation_defer_pvd'],
            'defer_recurring_earning' => (bool)$setRow['probation_defer_recurring_earning'],
            'base_salary_ratio' => $setRow['probation_base_salary_ratio'] !== null ? (float)$setRow['probation_base_salary_ratio'] : null,
            'period_days' => $setRow['probation_period_days'] !== null ? (int)$setRow['probation_period_days'] : null,
            'leave_days_limit' => $setRow['probation_leave_days_limit'] !== null ? (int)$setRow['probation_leave_days_limit'] : null,
            'allow_leave' => (bool)$setRow['allow_leave_during_probation'],
            'ot_eligible_default' => $setRow['probation_ot_eligible_default'] !== null ? (bool)$setRow['probation_ot_eligible_default'] : null,
            'defer_sso' => (bool)$setRow['probation_defer_sso'],
            'tax_exempt_default' => $setRow['probation_tax_exempt_default'] !== null ? (bool)$setRow['probation_tax_exempt_default'] : null,
        ];
    }

    /** Every Probation Set for a company, Default first (mirrors OtRateSetModel::list()'s own
     *  ordering), each with its resolved assignment list (empty for the Default Set, always). */
    public function probationSetList(int $compId): array {
        $stmt = $this->db->prepare("SELECT id FROM `probation_policy_sets` WHERE comp_id = :comp_id AND deleted_at IS NULL ORDER BY is_default DESC, set_name_th ASC");
        $stmt->execute([':comp_id' => $compId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return array_values(array_filter(array_map(fn($id) => $this->probationSetGet($compId, $id), $ids)));
    }

    public function probationSetGet(int $compId, int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `probation_policy_sets` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $set = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$set) {
            return null;
        }
        $set['is_default'] = (bool)$set['is_default'];
        $set['probation_defer_pvd'] = (bool)$set['probation_defer_pvd'];
        $set['probation_defer_recurring_earning'] = (bool)$set['probation_defer_recurring_earning'];
        $set['probation_base_salary_ratio'] = $set['probation_base_salary_ratio'] !== null ? (float)$set['probation_base_salary_ratio'] : null;
        $set['probation_period_days'] = $set['probation_period_days'] !== null ? (int)$set['probation_period_days'] : null;
        $set['probation_leave_days_limit'] = $set['probation_leave_days_limit'] !== null ? (int)$set['probation_leave_days_limit'] : null;
        $set['allow_leave_during_probation'] = (bool)$set['allow_leave_during_probation'];
        $set['probation_ot_eligible_default'] = $set['probation_ot_eligible_default'] !== null ? (bool)$set['probation_ot_eligible_default'] : null;
        $set['probation_defer_sso'] = (bool)$set['probation_defer_sso'];
        $set['probation_tax_exempt_default'] = $set['probation_tax_exempt_default'] !== null ? (bool)$set['probation_tax_exempt_default'] : null;
        $set['assignments'] = $set['is_default'] ? [] : $this->assignmentModel->getAssignments($compId, self::ENTITY_TYPE, $id);
        return $set;
    }

    /**
     * Create/update a Probation Set. Same `is_default` enforcement as OtRateSetModel::save(): the
     * very first Set a company ever creates is force-defaulted regardless of what was submitted
     * (nothing else to fall back to); flipping is_default=true on any save clears every other Set's
     * flag first; the Default Set's own assignments are force-cleared (it's the catch-all, it never
     * needs explicit scoping).
     *
     * Deliberately DOES NOT reject an assignment scope already claimed by another active Set (unlike
     * OtRateSetModel::findConflictingAssignment()) -- T055's own EntityAssignmentModel has no
     * built-in conflict concept, and this task doesn't ask for strict non-overlap the way OT Rate's
     * own explicit request did ("ป้องกันการบันทึกซ้ำ"). A genuine overlap (2 Sets both listing the
     * same department) is allowed and resolves deterministically at read time via
     * probationSettings()'s own "active non-default Sets ordered by id ASC, first match wins" rule --
     * documented there, tested here.
     * @param array $assignments list of {scope_type, scope_id} -- ignored/force-cleared when is_default=true
     */
    public function probationSetSave(int $compId, array $data, int $userId): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;
        $nameTh = trim((string)($data['set_name_th'] ?? ''));
        $nameEn = trim((string)($data['set_name_en'] ?? ''));
        if ($nameTh === '') {
            return ['status' => false, 'message' => 'Missing required field: set_name_th.'];
        }
        if ($nameEn === '') {
            $nameEn = $nameTh;
        }

        $stmtAnyOther = $this->db->prepare("SELECT COUNT(*) FROM `probation_policy_sets` WHERE comp_id = :comp_id AND deleted_at IS NULL" . ($id !== null ? " AND id != :id" : ""));
        $anyOtherParams = [':comp_id' => $compId];
        if ($id !== null) {
            $anyOtherParams[':id'] = $id;
        }
        $stmtAnyOther->execute($anyOtherParams);
        $hasAnyOtherSet = (int)$stmtAnyOther->fetchColumn() > 0;
        $isDefault = !$hasAnyOtherSet || !empty($data['is_default']);

        $periodDays = null;
        if (isset($data['probation_period_days']) && $data['probation_period_days'] !== '' && $data['probation_period_days'] !== null) {
            if (!is_numeric($data['probation_period_days']) || (int)$data['probation_period_days'] < 0) {
                return ['status' => false, 'message' => 'Probation period days must be a non-negative number, or left blank.'];
            }
            $periodDays = (int)$data['probation_period_days'];
        }
        $baseSalaryRatio = null;
        if (isset($data['probation_base_salary_ratio']) && $data['probation_base_salary_ratio'] !== '' && $data['probation_base_salary_ratio'] !== null) {
            if (!is_numeric($data['probation_base_salary_ratio']) || (float)$data['probation_base_salary_ratio'] <= 0 || (float)$data['probation_base_salary_ratio'] > 100) {
                return ['status' => false, 'message' => 'Base salary ratio must be a percentage between 0 (exclusive) and 100, or left blank for no reduction.'];
            }
            $baseSalaryRatio = (float)$data['probation_base_salary_ratio'];
        }
        $leaveDaysLimit = null;
        if (isset($data['probation_leave_days_limit']) && $data['probation_leave_days_limit'] !== '' && $data['probation_leave_days_limit'] !== null) {
            if (!is_numeric($data['probation_leave_days_limit']) || (int)$data['probation_leave_days_limit'] < 0) {
                return ['status' => false, 'message' => 'Leave days limit must be a non-negative number, or left blank.'];
            }
            $leaveDaysLimit = (int)$data['probation_leave_days_limit'];
        }
        $allowLeave = array_key_exists('allow_leave_during_probation', $data) ? (!empty($data['allow_leave_during_probation']) ? 1 : 0) : 1;
        $otEligibleDefault = null;
        if (isset($data['probation_ot_eligible_default']) && $data['probation_ot_eligible_default'] !== '' && $data['probation_ot_eligible_default'] !== null) {
            $otEligibleDefault = !empty($data['probation_ot_eligible_default']) ? 1 : 0;
        }
        $taxExemptDefault = null;
        if (isset($data['probation_tax_exempt_default']) && $data['probation_tax_exempt_default'] !== '' && $data['probation_tax_exempt_default'] !== null) {
            $taxExemptDefault = !empty($data['probation_tax_exempt_default']) ? 1 : 0;
        }
        $deferPvd = !empty($data['probation_defer_pvd']) ? 1 : 0;
        $deferRecurringEarning = !empty($data['probation_defer_recurring_earning']) ? 1 : 0;
        $deferSso = !empty($data['probation_defer_sso']) ? 1 : 0;

        $assignments = [];
        if (!$isDefault) {
            $rawAssignments = is_array($data['assignments'] ?? null) ? $data['assignments'] : [];
            foreach ($rawAssignments as $a) {
                $assignments[] = ['scope_type' => (string)($a['scope_type'] ?? ''), 'scope_id' => (int)($a['scope_id'] ?? 0)];
            }
        }

        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM `probation_policy_sets` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    if ($own) { $this->db->rollBack(); }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                if ($isDefault) {
                    $this->db->prepare("UPDATE `probation_policy_sets` SET is_default = 0 WHERE comp_id = :comp_id AND id != :id")->execute([':comp_id' => $compId, ':id' => $id]);
                }
                $this->db->prepare(
                    "UPDATE `probation_policy_sets` SET set_name_th = :th, set_name_en = :en, is_default = :is_default,
                        probation_period_days = :period_days, probation_defer_pvd = :defer_pvd, probation_defer_recurring_earning = :defer_recurring,
                        probation_base_salary_ratio = :base_ratio, probation_leave_days_limit = :leave_limit, allow_leave_during_probation = :allow_leave,
                        probation_ot_eligible_default = :ot_eligible, probation_defer_sso = :defer_sso, probation_tax_exempt_default = :tax_exempt,
                        updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id"
                )->execute([
                    ':th' => $nameTh, ':en' => $nameEn, ':is_default' => $isDefault ? 1 : 0,
                    ':period_days' => $periodDays, ':defer_pvd' => $deferPvd, ':defer_recurring' => $deferRecurringEarning,
                    ':base_ratio' => $baseSalaryRatio, ':leave_limit' => $leaveDaysLimit, ':allow_leave' => $allowLeave,
                    ':ot_eligible' => $otEligibleDefault, ':defer_sso' => $deferSso, ':tax_exempt' => $taxExemptDefault,
                    ':updated_by' => $userId, ':id' => $id,
                ]);
                $setId = $id;
            } else {
                if ($isDefault) {
                    $this->db->prepare("UPDATE `probation_policy_sets` SET is_default = 0 WHERE comp_id = :comp_id")->execute([':comp_id' => $compId]);
                }
                $this->db->prepare(
                    "INSERT INTO `probation_policy_sets`
                        (comp_id, set_name_th, set_name_en, is_default, probation_period_days, probation_defer_pvd, probation_defer_recurring_earning,
                         probation_base_salary_ratio, probation_leave_days_limit, allow_leave_during_probation, probation_ot_eligible_default,
                         probation_defer_sso, probation_tax_exempt_default, created_by)
                     VALUES (:comp_id, :th, :en, :is_default, :period_days, :defer_pvd, :defer_recurring,
                         :base_ratio, :leave_limit, :allow_leave, :ot_eligible, :defer_sso, :tax_exempt, :created_by)"
                )->execute([
                    ':comp_id' => $compId, ':th' => $nameTh, ':en' => $nameEn, ':is_default' => $isDefault ? 1 : 0,
                    ':period_days' => $periodDays, ':defer_pvd' => $deferPvd, ':defer_recurring' => $deferRecurringEarning,
                    ':base_ratio' => $baseSalaryRatio, ':leave_limit' => $leaveDaysLimit, ':allow_leave' => $allowLeave,
                    ':ot_eligible' => $otEligibleDefault, ':defer_sso' => $deferSso, ':tax_exempt' => $taxExemptDefault,
                    ':created_by' => $userId,
                ]);
                $setId = (int)$this->db->lastInsertId();
            }

            if ($isDefault) {
                $this->assignmentModel->deleteAllForEntity($compId, self::ENTITY_TYPE, $setId);
            } else {
                $assignRes = $this->assignmentModel->saveAssignments($compId, self::ENTITY_TYPE, $setId, $assignments, $userId);
                if (!$assignRes['status']) {
                    if ($own) { $this->db->rollBack(); }
                    return $assignRes;
                }
            }

            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
        return ['status' => true, 'message' => 'Saved successfully.', 'id' => $setId];
    }

    /** The Default Set can never be deleted -- same mandatory-invariant reasoning as
     *  OtRateSetModel::delete(); an admin must set a DIFFERENT Set as default first. */
    public function probationSetDelete(int $compId, int $id, int $userId): array {
        $stmt = $this->db->prepare("SELECT is_default FROM `probation_policy_sets` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ((int)$row['is_default'] === 1) {
            return ['status' => false, 'message' => 'The Default Probation Set cannot be deleted -- set a different Set as Default first.'];
        }
        $this->db->prepare("UPDATE `probation_policy_sets` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id")
            ->execute([':deleted_by' => $userId, ':id' => $id]);
        $this->assignmentModel->deleteAllForEntity($compId, self::ENTITY_TYPE, $id);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    public function probationSetToggleStatus(int $compId, int $id, int $userId): array {
        $stmt = $this->db->prepare("SELECT status, is_default FROM `probation_policy_sets` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ((int)$row['is_default'] === 1 && $row['status'] === 'active') {
            return ['status' => false, 'message' => 'The Default Probation Set cannot be deactivated -- set a different Set as Default first.'];
        }
        $newStatus = $row['status'] === 'active' ? 'inactive' : 'active';
        $this->db->prepare("UPDATE `probation_policy_sets` SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':status' => $newStatus, ':updated_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Updated successfully.', 'new_status' => $newStatus];
    }

    /** Explicit "make this one the Default" action -- clears every other Set's flag first, and clears
     *  the newly-default Set's own assignments (same "Default carries no assignments" invariant
     *  probationSetSave() itself enforces). */
    public function probationSetSetDefault(int $compId, int $id, int $userId): array {
        $stmt = $this->db->prepare("SELECT id, status FROM `probation_policy_sets` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($row['status'] !== 'active') {
            return ['status' => false, 'message' => 'Only an active Probation Set can be made the default.'];
        }
        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            $this->db->prepare("UPDATE `probation_policy_sets` SET is_default = 0 WHERE comp_id = :comp_id")->execute([':comp_id' => $compId]);
            $this->db->prepare("UPDATE `probation_policy_sets` SET is_default = 1, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':updated_by' => $userId, ':id' => $id]);
            $this->assignmentModel->deleteAllForEntity($compId, self::ENTITY_TYPE, $id);
            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
        return ['status' => true, 'message' => 'Default set updated.'];
    }

    /** The literal "Clone" action -- duplicates every probation_* field, forces the copy to
     *  is_default=false regardless of the source (same "a duplicate starts unscoped/non-default"
     *  precedent already established for Payslip/Employment Certificate Template's own duplicate()),
     *  and deliberately does NOT carry the source's assignments forward -- 2 active Sets
     *  simultaneously claiming the same scope would be a confusing default, same reasoning those
     *  templates' own duplicate() docblocks already give. */
    public function probationSetDuplicate(int $compId, int $id, int $userId): array {
        $source = $this->probationSetGet($compId, $id);
        if ($source === null) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        return $this->probationSetSave($compId, [
            'set_name_th' => trim($source['set_name_th']) . ' (Copy)',
            'set_name_en' => trim($source['set_name_en']) . ' (Copy)',
            'is_default' => false,
            'probation_period_days' => $source['probation_period_days'],
            'probation_defer_pvd' => $source['probation_defer_pvd'],
            'probation_defer_recurring_earning' => $source['probation_defer_recurring_earning'],
            'probation_base_salary_ratio' => $source['probation_base_salary_ratio'],
            'probation_leave_days_limit' => $source['probation_leave_days_limit'],
            'allow_leave_during_probation' => $source['allow_leave_during_probation'],
            'probation_ot_eligible_default' => $source['probation_ot_eligible_default'],
            'probation_defer_sso' => $source['probation_defer_sso'],
            'probation_tax_exempt_default' => $source['probation_tax_exempt_default'],
            'assignments' => [],
        ], $userId);
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
