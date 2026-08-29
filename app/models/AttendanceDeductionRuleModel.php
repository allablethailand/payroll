<?php
declare(strict_types=1);

/**
 * Company-configurable "how is this deduction calculated" for the 3 attendance-driven deduction
 * events sourced from Origami sync data (2026-08-20, explicit request -- originally built Late-only
 * as SetupRulesModel::lateDeductionRule*() under Time & Leave, then generalized/relocated to Payroll
 * Configuration the same day, before any real company had configured it, to cover Absent and Unpaid
 * Leave too: "รองรับการ Set เงื่อนของ สาย ขาดงาน ลาไม่รับเงินด้วย...ดึงไปใช้ในการทำ Process เงินเดือนด้วย").
 *
 * Unlike OT Rate (a list of many records per company), each company has AT MOST one row per event
 * (`attendance_deduction_rules` UNIQUE(comp_id, event_code)) -- upserted, no delete UX (switching
 * method is just saving again). `attendance_deduction_rule_brackets` is only populated when
 * method_code='tiered_bracket' for that event, delete+reinsert whole set on every save (same pattern
 * as `holiday_assignments`/`approval_workflow_steps`).
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
 */
class AttendanceDeductionRuleModel {
    private PDO $db;

    // 2026-08-29, explicit request ("ลาไม่รับเงิน และลารออนุมัติ ถึงจะเอามาคำนวณเป็นเงินหัก") -- leave still
    // awaiting approval is provisionally deducted like unpaid leave until it's actually approved (at
    // which point it stops appearing in payroll_sync_items as pending), same reasoning/company-
    // configurable rule mechanism as late/absent/unpaid_leave. See SyncPayResolver's own
    // RULE_DRIVEN_ITEM_DEFS['leave_pending'].
    private const EVENT_CODES = ['late', 'absent', 'unpaid_leave', 'leave_pending'];
    private const RATE_UNITS = ['minute', 'hour', 'day'];
    /** Sensible starting point per event when no rule has been saved yet -- late naturally reads as
     *  "per minute", absent/unpaid_leave/leave_pending as "per day"; freely changeable once a rule is saved. */
    private const DEFAULT_RATE_UNIT = ['late' => 'minute', 'absent' => 'day', 'unpaid_leave' => 'day', 'leave_pending' => 'day'];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
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

    /** @return array<string,array> keyed by event_code ('late'/'absent'/'unpaid_leave'), each with a 'brackets' sub-array. */
    public function ruleGetAll(int $compId): array {
        $stmt = $this->db->prepare("SELECT r.*, m.name_th AS method_name_th, m.name_en AS method_name_en
            FROM attendance_deduction_rules r
            JOIN master_attendance_deduction_methods m ON m.code = r.method_code
            WHERE r.comp_id = :comp_id");
        $stmt->execute([':comp_id' => $compId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows[$row['event_code']] = $row;
        }

        $result = [];
        foreach (self::EVENT_CODES as $eventCode) {
            $rule = $rows[$eventCode] ?? ['id' => null, 'comp_id' => $compId, 'event_code' => $eventCode,
                'method_code' => 'percent_of_rate', 'rate_unit' => self::DEFAULT_RATE_UNIT[$eventCode],
                'rate_per_unit' => null, 'multiplier_rate' => '1.00'];
            $rule['brackets'] = [];
            if ($rule['method_code'] === 'tiered_bracket' && !empty($rule['id'])) {
                $stmtB = $this->db->prepare("SELECT min_units, max_units, deduction_amount
                    FROM attendance_deduction_rule_brackets WHERE rule_id = :rule_id ORDER BY min_units ASC");
                $stmtB->execute([':rule_id' => $rule['id']]);
                $rule['brackets'] = $stmtB->fetchAll(PDO::FETCH_ASSOC);
            }
            $result[$eventCode] = $rule;
        }
        return $result;
    }

    public function ruleSave(array $data, int $compId, int $userId): array {
        $eventCode = (string)($data['event_code'] ?? '');
        if (!in_array($eventCode, self::EVENT_CODES, true)) {
            return ['status' => false, 'message' => 'Invalid event_code.'];
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
        } else { // tiered_bracket
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

        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }

            $stmtExisting = $this->db->prepare("SELECT id FROM attendance_deduction_rules WHERE comp_id = :comp_id AND event_code = :event_code");
            $stmtExisting->execute([':comp_id' => $compId, ':event_code' => $eventCode]);
            $existingId = $stmtExisting->fetchColumn();

            if ($existingId) {
                $ruleId = (int)$existingId;
                $stmt = $this->db->prepare("UPDATE attendance_deduction_rules SET method_code = :method_code,
                    rate_unit = :rate_unit, rate_per_unit = :rate_per_unit, multiplier_rate = :multiplier_rate,
                    updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([
                    ':method_code' => $methodCode, ':rate_unit' => $rateUnit, ':rate_per_unit' => $ratePerUnit, ':multiplier_rate' => $multiplierRate,
                    ':updated_by' => $userId, ':id' => $ruleId,
                ]);
                $this->db->prepare("DELETE FROM attendance_deduction_rule_brackets WHERE rule_id = :rule_id")->execute([':rule_id' => $ruleId]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO attendance_deduction_rules (comp_id, event_code, method_code, rate_unit, rate_per_unit, multiplier_rate, created_by)
                    VALUES (:comp_id, :event_code, :method_code, :rate_unit, :rate_per_unit, :multiplier_rate, :created_by)");
                $stmt->execute([
                    ':comp_id' => $compId, ':event_code' => $eventCode, ':method_code' => $methodCode, ':rate_unit' => $rateUnit, ':rate_per_unit' => $ratePerUnit,
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

            if ($own) { $this->db->commit(); }
            return ['status' => true, 'message' => 'Saved successfully.', 'id' => $ruleId];
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }
}
