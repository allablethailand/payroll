<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/SyncPayResolver.php';
require_once __DIR__ . '/AuditLogModel.php';

/**
 * Company-configurable "how is this deduction calculated" for the 5 attendance-driven deduction
 * events sourced from Origami sync/import/manual data (2026-08-20, explicit request -- originally built Late-only
 * as SetupRulesModel::lateDeductionRule*() under Time & Leave, then generalized/relocated to Payroll
 * Configuration the same day, before any real company had configured it, to cover Absent and Unpaid
 * Leave too: "รองรับการ Set เงื่อนของ สาย ขาดงาน ลาไม่รับเงินด้วย...ดึงไปใช้ในการทำ Process เงินเดือนด้วย").
 *
 * `min_units`/`max_units`/`rate_per_unit` are deliberately unit-agnostic column names -- the row's
 * own `rate_unit` column (2026-08-21, explicit request: "การตั้งค่าเงื่อนไขการหักสาย ให้มี นาทีละ กี่บาท
 * ชั่วโมงละกี่บาท") picks minute/hour/day, freely choosable per rule regardless of event_code (not
 * fixed per event like before) -- only meaningful for flat_amount/tiered_bracket, read by
 * `app/services/SyncPayResolver.php::computeAttendanceDeductionAmount()`; percent_of_rate always
 * computes against actual minutes internally regardless of this column (see that class's own
 * docblock for why -- a day-based rate hides real shift-length variation within a period). No row
 * for a given (company, event) = default behavior = method_code='percent_of_rate' @ multiplier
 * 1.00, matching what SyncPayResolver did before this feature existed for that event.
 *
 * 2026-08-30, explicit request: "ในกรณีที่มีการคำนวณประเภทเดียวกันแต่หลายทีม ให้เพิ่มปุ่ม Clone ขึ้นมาและใส่
 * รายละเอียดเพิ่มเข้าไปในส่วนของรายการด้วยมาใช้กับอะไร" (confirmed via AskUserQuestion: full schema
 * redesign, not a UI-only add) -- was AT MOST one row per (comp_id, event_code); now MULTIPLE rows
 * are allowed per event, each optionally scoped to one team OR one department via `scope_type`/
 * `scope_id` (both NULL = the company-wide DEFAULT row, exactly the old single-row behavior).
 * Resolution priority for a given employee, most-specific-wins: **team > department > company-wide
 * default** -- same precedent as Holiday's own employee>position>department>shift resolver
 * (SetupRulesModel::resolveHolidaysForEmployee()). `label` is a free-text "used for what" note
 * (e.g. "Warehouse team - late policy") shown in the settings list so multiple variants for the same
 * event are distinguishable at a glance -- purely descriptive, never read by the calc engine.
 *
 * The default (unscoped) row is still virtual-if-absent (ruleGetAll() synthesizes it the same way
 * as before) and can never be deleted, only edited -- there must always be a fallback for any
 * employee not covered by a more specific scoped row. Scoped variants ARE deletable (ruleDelete()).
 *
 * IMPORTANT: this resolution priority is implemented TWICE, independently, by design -- once here
 * (exemptEventCodesForEmployee(), the company-wide/exemption-list gate) and once in
 * SyncPayResolver::attendanceDeductionRuleFor() (the actual per-employee amount calculation) --
 * matching this codebase's own pre-existing precedent (both classes already independently queried
 * `attendance_deduction_rules` before this change too). Keep both in sync if this priority rule ever
 * changes.
 */
class AttendanceDeductionRuleModel {
    private PDO $db;
    private const SCOPE_TYPES = ['team', 'department'];

    // 2026-08-29, explicit request ("ลาไม่รับเงิน และลารออนุมัติ ถึงจะเอามาคำนวณเป็นเงินหัก") -- leave still
    // awaiting approval is provisionally deducted like unpaid leave until it's actually approved (at
    // which point it stops appearing in payroll_sync_items as pending), same reasoning/company-
    // configurable rule mechanism as late/absent/unpaid_leave. See SyncPayResolver's own
    // RULE_DRIVEN_ITEM_DEFS['leave_pending'].
    // 2026-08-30, Phase 8 (T043, explicit request: "เพิ่ม 'กลับก่อนเวลา' ตั้งค่าได้แบบเดียวกับ 'มาสาย'") --
    // the CALCULATION side (SyncPayResolver::RULE_DRIVEN_ITEM_DEFS['early_leave'], structured
    // `early_mins` column) has computed this event since an earlier fix the same day (see that
    // class's own docblock) -- attendanceDeductionRuleFor() there already queries generically by
    // event_code, no hardcoded event list of its own, so it was ALREADY able to pick up a configured
    // early_leave rule the moment one existed. The gap was entirely here + the settings UI: nothing
    // let an admin actually create one, so early_leave silently ALWAYS used the bare
    // percent_of_rate @ 1.00 default. Added exactly like the other 4 -- same table, same
    // ruleSave()/ruleGetAll()/ruleDelete()/previewCalculation(), zero new methods needed.
    private const EVENT_CODES = ['late', 'early_leave', 'absent', 'unpaid_leave', 'leave_pending'];
    private const RATE_UNITS = ['minute', 'hour', 'day'];
    /** Sensible starting point per event when no rule has been saved yet -- late/early_leave naturally
     *  read as "per minute" (both are structured *_mins columns), absent/unpaid_leave/leave_pending as
     *  "per day"; freely changeable once a rule is saved. */
    private const DEFAULT_RATE_UNIT = ['late' => 'minute', 'early_leave' => 'minute', 'absent' => 'day', 'unpaid_leave' => 'day', 'leave_pending' => 'day'];

    private AuditLogModel $auditLog;
    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->auditLog = new AuditLogModel($this->db);
    }

    /**
     * `code AS id` (2026-08-20, real live-testing bug: initially copied master_ot_scope_types'
     * `SELECT id, ...` pattern, but that's only correct when the FK column is genuinely numeric
     * (ot_rates.ot_scope_id is). attendance_deduction_rules.method_code stores the STRING code
     * ('percent_of_rate' etc), so the Select2 ajax value must be that code, not the master table's
     * numeric row id -- otherwise picking a real dropdown option submits e.g. "1" as method_code,
     * which fails validation, AND the multiplier/flat/bracket section toggle
     * (applyAttendanceDeductionMethodFields(), string-compared against 'flat_amount' etc.) silently
     * hides ALL sections since nothing matches a numeric id. Same fix already established in
     * PayrollEarningDeductionTypeModel::sourceEventOptions() (`code AS id`) for the identical
     * shape of problem.
     */
    public function methodOptions(): array {
        $stmt = $this->db->query("SELECT code AS id, name_th AS text_th, name_en AS text_en FROM master_attendance_deduction_methods WHERE is_active = 1 ORDER BY sort_order ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string,array> keyed by event_code ('late'/'early_leave'/'absent'/'unpaid_leave'/'leave_pending'),
     *   each value a LIST of rule-variant rows for that event -- the company-wide default row always
     *   first (real if saved, else a synthesized virtual one, same shape either way), followed by any
     *   team/department-scoped variants (real rows only, ordered by scope_type then label). Each row
     *   has 'brackets'/'exemptions' sub-arrays and, for scoped rows, a resolved 'scope_label' (the
     *   team/department's own display name, for the settings list -- see scopeLabel()).
     */
    public function ruleGetAll(int $compId): array {
        $stmt = $this->db->prepare("SELECT r.*, m.name_th AS method_name_th, m.name_en AS method_name_en
            FROM attendance_deduction_rules r
            JOIN master_attendance_deduction_methods m ON m.code = r.method_code
            WHERE r.comp_id = :comp_id
            ORDER BY r.event_code ASC, (r.scope_type IS NULL) DESC, r.scope_type ASC, r.label ASC");
        $stmt->execute([':comp_id' => $compId]);
        $rowsByEvent = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rowsByEvent[$row['event_code']][] = $row;
        }

        $result = [];
        foreach (self::EVENT_CODES as $eventCode) {
            $rows = $rowsByEvent[$eventCode] ?? [];
            $hasDefault = false;
            $variants = [];
            foreach ($rows as $row) {
                $row['is_active'] = (bool)$row['is_active'];
                $row['brackets'] = [];
                $row['exemptions'] = $this->exemptionsForRule((int)$row['id'], $compId);
                if ($row['method_code'] === 'tiered_bracket') {
                    $stmtB = $this->db->prepare("SELECT min_units, max_units, deduction_amount
                        FROM attendance_deduction_rule_brackets WHERE rule_id = :rule_id ORDER BY min_units ASC");
                    $stmtB->execute([':rule_id' => $row['id']]);
                    $row['brackets'] = $stmtB->fetchAll(PDO::FETCH_ASSOC);
                }
                if ($row['scope_type'] === null) {
                    $hasDefault = true;
                    $row['scope_label'] = null;
                } else {
                    $row['scope_label'] = $this->scopeLabel($compId, (string)$row['scope_type'], (int)$row['scope_id']);
                }
                $variants[] = $row;
            }
            if (!$hasDefault) {
                array_unshift($variants, [
                    'id' => null, 'comp_id' => $compId, 'event_code' => $eventCode,
                    'scope_type' => null, 'scope_id' => null, 'scope_label' => null, 'label' => null,
                    'is_active' => true, 'method_code' => 'percent_of_rate', 'rate_unit' => self::DEFAULT_RATE_UNIT[$eventCode],
                    'rate_per_unit' => null, 'multiplier_rate' => '1.00', 'brackets' => [], 'exemptions' => [],
                ]);
            }
            $result[$eventCode] = $variants;
        }
        return $result;
    }

    /**
     * Resolved (with display labels, not just raw scope_id) exemption rows for one rule -- used both
     * by ruleGetAll() (to pre-check the right boxes in the "Assign" UI) and by the settings page's
     * own read of "who is currently exempt" list.
     */
    private function exemptionsForRule(int $ruleId, int $compId): array {
        $stmt = $this->db->prepare("SELECT id, scope_type, scope_id FROM attendance_deduction_rule_exemptions WHERE rule_id = :rule_id");
        $stmt->execute([':rule_id' => $ruleId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['label'] = $this->scopeLabel($compId, $row['scope_type'], (int)$row['scope_id']);
        }
        return $rows;
    }

    private function scopeLabel(int $compId, string $scopeType, int $scopeId): ?string {
        $table = ['department' => 'structure_departments', 'team' => 'structure_teams', 'employee' => 'employees'][$scopeType] ?? null;
        if ($table === null) {
            return null;
        }
        if ($scopeType === 'employee') {
            $stmt = $this->db->prepare("SELECT CONCAT(employee_no, ' - ', name_th, ' ', surname_th) AS label FROM employees WHERE id = :id AND comp_id = :comp_id");
        } else {
            $nameCol = $scopeType === 'department' ? 'department_name_th' : 'team_name_th';
            $stmt = $this->db->prepare("SELECT {$nameCol} AS label FROM {$table} WHERE id = :id AND comp_id = :comp_id");
        }
        $stmt->execute([':id' => $scopeId, ':comp_id' => $compId]);
        $label = $stmt->fetchColumn();
        return $label !== false ? (string)$label : null;
    }

    /**
     * Full option lists (departments/teams/employees, every active row, no pagination) for the
     * "Assign" checkbox UI -- same shape/precedent as Payslip/Employment Certificate Template's own
     * assignableOptions() (see CLAUDE.md's "Assign To became its own tab with checkboxes" section).
     */
    public function assignableOptions(int $compId): array {
        $departments = $this->db->prepare("SELECT id, department_name_th AS label FROM structure_departments WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL ORDER BY department_name_th ASC");
        $departments->execute([':comp_id' => $compId]);
        $teams = $this->db->prepare("SELECT id, team_name_th AS label FROM structure_teams WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL ORDER BY team_name_th ASC");
        $teams->execute([':comp_id' => $compId]);
        $employees = $this->db->prepare("SELECT id, CONCAT(employee_no, ' - ', name_th, ' ', surname_th) AS label FROM employees WHERE comp_id = :comp_id AND deleted_at IS NULL ORDER BY employee_no ASC");
        $employees->execute([':comp_id' => $compId]);
        return [
            'departments' => $departments->fetchAll(PDO::FETCH_ASSOC),
            'teams' => $teams->fetchAll(PDO::FETCH_ASSOC),
            'employees' => $employees->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * Picks the single most-specific matching row out of every rule row saved for one event_code --
     * team > department > company-wide default (scope_type NULL). $rows must all share the same
     * event_code. Returns null only if $rows is empty (event never configured at all -- callers treat
     * that as "default behavior", not "exempt"). Shared priority logic -- see this class's own
     * docblock for why SyncPayResolver::attendanceDeductionRuleFor() re-implements this independently
     * rather than calling this method directly.
     */
    private function resolveVariantRow(array $rows, ?int $departmentId, ?int $teamId): ?array {
        $teamRow = null; $deptRow = null; $defaultRow = null;
        foreach ($rows as $row) {
            if ($row['scope_type'] === 'team' && $teamId !== null && (int)$row['scope_id'] === $teamId) {
                $teamRow = $row;
            } elseif ($row['scope_type'] === 'department' && $departmentId !== null && (int)$row['scope_id'] === $departmentId) {
                $deptRow = $row;
            } elseif ($row['scope_type'] === null) {
                $defaultRow = $row;
            }
        }
        return $teamRow ?? $deptRow ?? $defaultRow;
    }

    /**
     * The set of event_codes (subset of self::EVENT_CODES) that this specific employee is exempt
     * from. For each event, resolves the ONE rule variant that actually applies to this employee
     * (team > department > company-wide default -- resolveVariantRow()) -- an event whose RESOLVED
     * variant has is_active=0 exempts this employee (a scoped variant being off only exempts the
     * team/department it's scoped to; the company-wide default being off only exempts whoever falls
     * through to it), else exempt only if this employee/their department/their team is on THAT
     * resolved variant's own exemption list. Called once per employee per recalculate()
     * (PayrollRunModel), passed into SyncPayResolver::resolve() as a plain list so that class needs
     * no DB access of its own for this -- same "engine takes a precomputed flags param, doesn't look
     * anything up itself" convention already established for StatutoryCalculationEngine::calculate()'s
     * own $employeeFlags param.
     * @return string[] e.g. ['late', 'unpaid_leave']
     */
    public function exemptEventCodesForEmployee(int $compId, int $employeeId, ?int $departmentId, ?int $teamId): array {
        $stmt = $this->db->prepare("SELECT id, event_code, is_active, scope_type, scope_id FROM attendance_deduction_rules WHERE comp_id = :comp_id");
        $stmt->execute([':comp_id' => $compId]);
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rules)) {
            return [];
        }

        $ruleIds = array_column($rules, 'id');
        $placeholders = implode(',', array_fill(0, count($ruleIds), '?'));
        $stmtEx = $this->db->prepare("SELECT rule_id, scope_type, scope_id FROM attendance_deduction_rule_exemptions WHERE rule_id IN ({$placeholders})");
        $stmtEx->execute($ruleIds);
        $exemptionsByRule = [];
        foreach ($stmtEx->fetchAll(PDO::FETCH_ASSOC) as $ex) {
            $exemptionsByRule[(int)$ex['rule_id']][] = $ex;
        }

        $rulesByEvent = [];
        foreach ($rules as $rule) {
            $rulesByEvent[$rule['event_code']][] = $rule;
        }

        $exempt = [];
        foreach ($rulesByEvent as $eventCode => $rows) {
            $resolved = $this->resolveVariantRow($rows, $departmentId, $teamId);
            if ($resolved === null) {
                continue; // never configured at all -- default behavior, not exempt.
            }
            if ((int)$resolved['is_active'] === 0) {
                $exempt[] = $eventCode;
                continue;
            }
            foreach ($exemptionsByRule[(int)$resolved['id']] ?? [] as $ex) {
                $matches = ($ex['scope_type'] === 'employee' && (int)$ex['scope_id'] === $employeeId)
                    || ($ex['scope_type'] === 'department' && $departmentId !== null && (int)$ex['scope_id'] === $departmentId)
                    || ($ex['scope_type'] === 'team' && $teamId !== null && (int)$ex['scope_id'] === $teamId);
                if ($matches) {
                    $exempt[] = $eventCode;
                    break;
                }
            }
        }
        return array_values(array_unique($exempt));
    }

    public function ruleSave(array $data, int $compId, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;
        $eventCode = (string)($data['event_code'] ?? '');
        if (!in_array($eventCode, self::EVENT_CODES, true)) {
            return ['status' => false, 'message' => 'Invalid event_code.'];
        }
        $isActive = !array_key_exists('is_active', $data) || !empty($data['is_active']) ? 1 : 0;

        // 2026-08-30, multi-scope rollout -- scope_type/scope_id together identify WHICH variant of
        // this event's rule is being saved (both null = the company-wide default). label is a plain
        // "used for what" note, only meaningful on a scoped variant (kept nullable for the default
        // row too, no real use for it there, but not worth a special-case reject).
        $scopeType = $data['scope_type'] ?? null;
        $scopeId = null;
        if ($scopeType !== null && $scopeType !== '') {
            if (!in_array($scopeType, self::SCOPE_TYPES, true)) {
                return ['status' => false, 'message' => 'Invalid scope_type.'];
            }
            if (empty($data['scope_id'])) {
                return ['status' => false, 'message' => 'A team or department must be selected for a scoped rule.'];
            }
            $scopeId = (int)$data['scope_id'];
            if (!$this->validScopeRef($compId, $scopeType, $scopeId)) {
                return ['status' => false, 'message' => 'The selected team/department does not belong to this company.'];
            }
        } else {
            $scopeType = null;
        }
        $label = trim((string)($data['label'] ?? ''));
        $label = $label !== '' ? mb_substr($label, 0, 150) : null;

        // Duplicate-variant check -- app-layer, same "unique key can't express this" reasoning as
        // this table's own migration comment (scope_id is polymorphic + both scope columns are NULL
        // for every default row, so a plain composite unique key can't enforce "at most one" here).
        $stmtDup = $this->db->prepare("SELECT id, scope_type, scope_id FROM attendance_deduction_rules WHERE comp_id = :comp_id AND event_code = :event_code" . ($id !== null ? " AND id != :id" : ""));
        $dupParams = [':comp_id' => $compId, ':event_code' => $eventCode];
        if ($id !== null) {
            $dupParams[':id'] = $id;
        }
        $stmtDup->execute($dupParams);
        foreach ($stmtDup->fetchAll(PDO::FETCH_ASSOC) as $other) {
            $otherIsDefault = $other['scope_type'] === null;
            $sameVariant = $scopeType === null
                ? $otherIsDefault
                : (!$otherIsDefault && $other['scope_type'] === $scopeType && (int)$other['scope_id'] === $scopeId);
            if ($sameVariant) {
                // 2026-09-02, explicit request: "ถ้าประเภทเดียว Assign ซ้ำ ต้องมี Alert เตือน และถ้าผู้ใช้
                // ต้องการ Save ทับเพื่อ Update ข้อมูลใหม่ต้องทำได้" -- conflict_id lets the frontend offer
                // "update the existing rule instead" (re-submit the SAME payload with `id` set to
                // this) rather than just a dead-end refusal. Safe to hand back: the caller already
                // knows this row exists (that's WHY it conflicted) and it belongs to this same
                // comp_id/event_code/scope, so re-submitting with this id can only ever update that
                // exact row, never something unrelated.
                return ['status' => false, 'message' => $scopeType === null
                    ? 'A company-wide default rule already exists for this event.'
                    : 'A rule for this exact team/department is already configured for this event.',
                    'conflict_id' => (int)$other['id']];
            }
        }

        $exemptions = is_array($data['exemptions'] ?? null) ? $data['exemptions'] : [];
        foreach ($exemptions as $ex) {
            if (!is_array($ex) || !in_array($ex['scope_type'] ?? '', ['department', 'team', 'employee'], true) || empty($ex['scope_id'])) {
                return ['status' => false, 'message' => 'Invalid exemption row.'];
            }
            if (!$this->validScopeRef($compId, (string)$ex['scope_type'], (int)$ex['scope_id'])) {
                return ['status' => false, 'message' => 'One of the exempted departments/teams/employees does not belong to this company.'];
            }
        }

        $methodCode = (string)($data['method_code'] ?? '');
        $stmtMethod = $this->db->prepare("SELECT code FROM master_attendance_deduction_methods WHERE code = :code AND is_active = 1");
        $stmtMethod->execute([':code' => $methodCode]);
        if (!$stmtMethod->fetch()) {
            return ['status' => false, 'message' => 'Invalid deduction method.'];
        }

        $rateUnit = in_array($data['rate_unit'] ?? '', self::RATE_UNITS, true) ? $data['rate_unit'] : self::DEFAULT_RATE_UNIT[$eventCode];
        $ratePerUnit = null;
        $multiplierRate = null;
        $brackets = [];
        if ($methodCode === 'flat_amount') {
            $ratePerUnit = (float)($data['rate_per_unit'] ?? 0);
            if ($ratePerUnit <= 0) {
                return ['status' => false, 'message' => 'rate_per_unit must be greater than 0.'];
            }
        } elseif ($methodCode === 'percent_of_rate') {
            $multiplierRate = (float)($data['multiplier_rate'] ?? 0);
            if ($multiplierRate <= 0) {
                $multiplierRate = 1.00;
            }
        } elseif ($methodCode === 'tiered_bracket') {
            $brackets = is_array($data['brackets'] ?? null) ? $data['brackets'] : [];
            if (empty($brackets)) {
                return ['status' => false, 'message' => 'At least one bracket is required.'];
            }
            foreach ($brackets as $b) {
                if (!isset($b['min_units'], $b['deduction_amount']) || (float)$b['min_units'] < 0 || (float)$b['deduction_amount'] < 0) {
                    return ['status' => false, 'message' => 'Invalid bracket row.'];
                }
            }
            usort($brackets, fn($a, $b) => ((float)$a['min_units']) <=> ((float)$b['min_units']));
        }
        // 2026-08-30 (T015, "เพิ่มตัวเลือก 'ไม่หัก'") -- 'no_deduction' needs no extra config at all
        // (ratePerUnit/multiplierRate/brackets all correctly stay at their null/empty defaults set
        // above). This used to be an unconditional trailing `else` that assumed anything not
        // flat_amount/percent_of_rate MUST be tiered_bracket -- a real bug this new method_code
        // would have hit immediately (silently requiring a bracket row for a method that has no
        // brackets at all) had this stayed a catch-all instead of an explicit elseif chain.

        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }

            $existingId = null;
            $oldRowForAudit = null;
            if ($id !== null) {
                // Platform Hardening Phase 6 pilot: SELECT * so the full row is available to
                // AuditLogModel::record() as the "old" side of the diff below -- only this table's
                // own scalar columns are diffed, not the child brackets/exemptions rows.
                $stmtExisting = $this->db->prepare("SELECT * FROM attendance_deduction_rules WHERE id = :id AND comp_id = :comp_id");
                $stmtExisting->execute([':id' => $id, ':comp_id' => $compId]);
                $oldRowForAudit = $stmtExisting->fetch(PDO::FETCH_ASSOC);
                $existingId = $oldRowForAudit['id'] ?? null;
                if (!$existingId) {
                    if ($own) { $this->db->rollBack(); }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
            }

            if ($existingId) {
                $ruleId = (int)$existingId;
                $stmt = $this->db->prepare("UPDATE attendance_deduction_rules SET is_active = :is_active, method_code = :method_code,
                    rate_unit = :rate_unit, rate_per_unit = :rate_per_unit, multiplier_rate = :multiplier_rate,
                    scope_type = :scope_type, scope_id = :scope_id, label = :label,
                    updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([
                    ':is_active' => $isActive, ':method_code' => $methodCode, ':rate_unit' => $rateUnit, ':rate_per_unit' => $ratePerUnit, ':multiplier_rate' => $multiplierRate,
                    ':scope_type' => $scopeType, ':scope_id' => $scopeId, ':label' => $label,
                    ':updated_by' => $userId, ':id' => $ruleId,
                ]);
                $this->db->prepare("DELETE FROM attendance_deduction_rule_brackets WHERE rule_id = :rule_id")->execute([':rule_id' => $ruleId]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO attendance_deduction_rules (comp_id, event_code, scope_type, scope_id, label, is_active, method_code, rate_unit, rate_per_unit, multiplier_rate, created_by)
                    VALUES (:comp_id, :event_code, :scope_type, :scope_id, :label, :is_active, :method_code, :rate_unit, :rate_per_unit, :multiplier_rate, :created_by)");
                $stmt->execute([
                    ':comp_id' => $compId, ':event_code' => $eventCode, ':scope_type' => $scopeType, ':scope_id' => $scopeId, ':label' => $label,
                    ':is_active' => $isActive, ':method_code' => $methodCode, ':rate_unit' => $rateUnit, ':rate_per_unit' => $ratePerUnit,
                    ':multiplier_rate' => $multiplierRate, ':created_by' => $userId,
                ]);
                $ruleId = (int)$this->db->lastInsertId();
            }

            if ($methodCode === 'tiered_bracket') {
                $stmtIns = $this->db->prepare("INSERT INTO attendance_deduction_rule_brackets (rule_id, min_units, max_units, deduction_amount, sort_order)
                    VALUES (:rule_id, :min_units, :max_units, :deduction_amount, :sort_order)");
                foreach ($brackets as $i => $b) {
                    $stmtIns->execute([
                        ':rule_id' => $ruleId, ':min_units' => (int)$b['min_units'],
                        ':max_units' => (isset($b['max_units']) && $b['max_units'] !== '' && $b['max_units'] !== null) ? (int)$b['max_units'] : null,
                        ':deduction_amount' => (float)$b['deduction_amount'], ':sort_order' => $i,
                    ]);
                }
            }

            // Exemption list: delete+reinsert whole set every save, same pattern as
            // holiday_assignments/approval_workflow_steps -- this table has no in-flight state of
            // its own that would need a diff-and-preserve-ids approach.
            $this->db->prepare("DELETE FROM attendance_deduction_rule_exemptions WHERE rule_id = :rule_id")->execute([':rule_id' => $ruleId]);
            if (!empty($exemptions)) {
                $stmtExIns = $this->db->prepare("INSERT INTO attendance_deduction_rule_exemptions (rule_id, scope_type, scope_id, created_by) VALUES (:rule_id, :scope_type, :scope_id, :created_by)");
                foreach ($exemptions as $ex) {
                    $stmtExIns->execute([
                        ':rule_id' => $ruleId, ':scope_type' => $ex['scope_type'], ':scope_id' => (int)$ex['scope_id'], ':created_by' => $userId,
                    ]);
                }
            }

            if ($oldRowForAudit !== null) {
                $stmtNewRow = $this->db->prepare("SELECT * FROM attendance_deduction_rules WHERE id = :id");
                $stmtNewRow->execute([':id' => $ruleId]);
                $newRow = $stmtNewRow->fetch(PDO::FETCH_ASSOC) ?: [];
                $this->auditLog->record($compId, 'attendance_deduction_rules', $ruleId, 'update', $oldRowForAudit, $newRow, $userId, 'web', $ip, $userAgent);
            }
            if ($own) { $this->db->commit(); }
            return ['status' => true, 'message' => 'Saved successfully.', 'id' => $ruleId];
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /**
     * 2026-08-30, multi-scope rollout -- only a SCOPED variant (team/department override) can be
     * deleted. The company-wide default row is never deletable through this: it's the fallback every
     * employee not covered by a more specific scoped row resolves to, so there must always be one
     * (ruleGetAll() synthesizes a virtual one when absent -- deleting a real one would just make the
     * next ruleGetAll() call show that same virtual default again, which is confusing UX, so it's
     * blocked outright instead). Hard delete (not soft) -- same precedent as
     * ApprovalWorkflowModel::stepDelete() for a config row with no audit/compliance meaning of its
     * own; attendance_deduction_rule_brackets/_exemptions both CASCADE on rule_id.
     */
    public function ruleDelete(int $id, int $compId, ?int $userId = null, ?string $ip = null, ?string $userAgent = null): array {
        $stmt = $this->db->prepare("SELECT * FROM attendance_deduction_rules WHERE id = :id AND comp_id = :comp_id");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($row['scope_type'] === null) {
            return ['status' => false, 'message' => 'The company-wide default rule cannot be deleted, only edited.'];
        }
        $this->db->prepare("DELETE FROM attendance_deduction_rules WHERE id = :id AND comp_id = :comp_id")->execute([':id' => $id, ':comp_id' => $compId]);
        $this->auditLog->record($compId, 'attendance_deduction_rules', $id, 'delete', $row, null, $userId, 'web', $ip, $userAgent);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    /**
     * 2026-08-30, explicit request: "อยากให้เพิ่มปุ่มแสดงตัวอย่างการคำนวณจากการตั้งค่าที่เลือก ในหน้า Form
     * ตอนเลือกวิธีการหักและกรอกข้อมูล" -- computes a worked example against the CURRENT, not-yet-saved
     * form values (method_code/rate_unit/rate_per_unit/multiplier_rate/brackets), so an admin sees
     * the real result before clicking Save. Delegates the actual formula to
     * SyncPayResolver::computeAttendanceDeductionFromConfig() -- the exact same pure function real
     * payroll runs use -- so this preview can never drift out of sync with the real calculation.
     * $sampleBaseSalary/$sampleMinutes let the admin try their own numbers; both default to a
     * round, easy-to-follow scenario (30,000 THB monthly, 30 minutes late) when omitted.
     * @return array{status:bool,message?:string,amount?:float,formula?:array,hourly_rate?:float}
     */
    public function previewCalculation(array $data, float $sampleBaseSalary = 30000.0, float $sampleMinutes = 30.0): array {
        $methodCode = (string)($data['method_code'] ?? '');
        $stmtMethod = $this->db->prepare("SELECT code FROM master_attendance_deduction_methods WHERE code = :code AND is_active = 1");
        $stmtMethod->execute([':code' => $methodCode]);
        if (!$stmtMethod->fetch()) {
            return ['status' => false, 'message' => 'Invalid deduction method.'];
        }
        if ($sampleBaseSalary <= 0 || $sampleMinutes < 0) {
            return ['status' => false, 'message' => 'Sample base salary must be positive and sample minutes must not be negative.'];
        }

        $rateUnit = in_array($data['rate_unit'] ?? '', self::RATE_UNITS, true) ? $data['rate_unit'] : 'minute';
        $rule = [
            'method_code' => $methodCode, 'rate_unit' => $rateUnit,
            'rate_per_unit' => isset($data['rate_per_unit']) ? (float)$data['rate_per_unit'] : null,
            'multiplier_rate' => isset($data['multiplier_rate']) ? (float)$data['multiplier_rate'] : null,
        ];
        $brackets = [];
        if ($methodCode === 'tiered_bracket') {
            foreach ((is_array($data['brackets'] ?? null) ? $data['brackets'] : []) as $b) {
                if (!isset($b['min_units'], $b['deduction_amount'])) {
                    continue;
                }
                $brackets[] = [
                    'min_units' => (float)$b['min_units'],
                    'max_units' => (isset($b['max_units']) && $b['max_units'] !== '' && $b['max_units'] !== null) ? (float)$b['max_units'] : null,
                    'deduction_amount' => (float)$b['deduction_amount'],
                ];
            }
            usort($brackets, fn($a, $b) => $a['min_units'] <=> $b['min_units']);
        }

        // Same hourlyRate fallback formula SyncPayResolver::hourlyRate() itself falls back to when
        // there's no real shift-derived working_mins to key off of (baseSalary / 30 days / 8 hours)
        // -- the exact scenario a sample/preview always is.
        $hourlyRate = $sampleBaseSalary / 30.0 / 8.0;

        $result = SyncPayResolver::computeAttendanceDeductionFromConfig($rule, $brackets, $sampleMinutes, $hourlyRate);
        return [
            'status' => true,
            'amount' => $result['amount'],
            'formula' => $result['formula'] ?? null,
            'hourly_rate' => round($hourlyRate, 4),
            'sample_base_salary' => $sampleBaseSalary,
            'sample_minutes' => $sampleMinutes,
        ];
    }

    /** Same application-layer polymorphic-scope validation as Holiday/Payslip Template's own assignment tables. */
    private function validScopeRef(int $compId, string $scopeType, int $scopeId): bool {
        $table = ['department' => 'structure_departments', 'team' => 'structure_teams', 'employee' => 'employees'][$scopeType] ?? null;
        if ($table === null) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT id FROM {$table} WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $scopeId, ':comp_id' => $compId]);
        return (bool)$stmt->fetchColumn();
    }
}
