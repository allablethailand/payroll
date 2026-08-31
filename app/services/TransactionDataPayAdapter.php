<?php
declare(strict_types=1);

/**
 * 2026-08-30 (Phase 5, T032, explicit request: "ถ้าข้อมูล match กับพนักงาน/งวดที่ถูกต้อง ให้นำไปใช้
 * คำนวณ" -- confirmed via AskUserQuestion that this means real wiring into PayrollRunModel::
 * recalculate(), not just an import UI with no calculation effect).
 *
 * Turns `attendance_records`/`leave_requests`/`overtime_records` (fed by Sync, Import, and Manual
 * Entry alike -- see AttendanceSyncer/LeaveRequestSyncer/OvertimeRecordSyncer and
 * AttendanceRecordModel/LeaveRequestModel/OvertimeRecordModel) into per-employee rows SHAPED
 * EXACTLY like a `payroll_sync_items` row, so `PayrollRunModel::recalculate()` can feed them
 * straight into the EXISTING, already-tested `SyncPayResolver::resolve()` engine that a genuine
 * Origami Payroll Sync run already uses for OT/late/absent/unpaid-leave/leave-pending -- zero new
 * calculation logic, only a new INPUT SHAPE for the same engine. This is deliberate: before this
 * class existed, a cycle-based (non-sync) run never populated `$syncItemsByEmployee` at all, so
 * NEITHER Manual Entry NOR Import ever affected a single payroll amount, despite CLAUDE.md's own
 * "No HR user: manual entry เต็มรูปแบบ"/"External HR user: Import" business-model tiers implying they
 * should for a company that isn't on Origami HR sync.
 *
 * Only invoked for a cycle-based run (`sync_process_id === null && cycle_id !== null`) -- an
 * off-cycle/incentive run stays manually-picked-items-only, same precedent as PED assignments/
 * recurring earnings (see PayrollRunModel::recalculate()'s own `$isIncentive` branch). A genuine
 * sync-based run never calls this at all -- Origami's own `payroll_sync_items` payload always wins
 * for those, this class has no role there.
 *
 * PER-EMPLOYEE OMISSION: an employee with zero attendance/leave/OT rows in the period gets NO entry
 * in the returned array at all (not a row of zeros) -- exactly mirrors payroll_sync_items' own
 * "$syncItemsByEmployee stays empty for cycle-based runs" default, so a company that has never used
 * Manual Entry/Import for a given employee/period sees byte-identical behavior to before this class
 * existed.
 *
 * KNOWN, DELIBERATE SIMPLIFICATIONS (documented, not silently guessed -- same convention as
 * SyncPayResolver's own docblock):
 *  - `working_days`/`working_mins` are deliberately NEVER set on the synthetic row -- these 3
 *    entity types record what happened (attendance/leave/OT), not a company's SCHEDULED
 *    working-day count for the period, so inventing one here would be a guess. SyncPayResolver's
 *    own existing fallback (fixed 30-day/8-hour divisor -- see its dailyRate()/hourlyRate() docblocks)
 *    applies exactly as it already does for a genuine sync row that Origami sent without those
 *    columns.
 *  - `trip_allowance` and `item_values` are always empty -- none of the 3 import/manual entity
 *    types carries a concept of a generic paid allowance or Origami's `item_values[]` catalog; only
 *    Origami Payroll Sync has that.
 *  - A leave request whose date range only PARTIALLY overlaps the pay period (starts before
 *    `periodStart` or ends after `periodEnd`) is excluded entirely from this adapter's totals,
 *    rather than prorated -- `leave_requests.total_days` is a single stored figure for the whole
 *    request, and splitting it proportionally across two pay periods would need information (which
 *    days actually fall in which period) this schema doesn't carry. Origami's own sync payload
 *    sidesteps this by sending an already period-scoped day count directly; this adapter has no
 *    equivalent to fall back on, so a boundary-spanning leave request is simply left out rather than
 *    guessed at -- visible as "not deducted this period" rather than a silently wrong number.
 *  - `overtime_records.amount` (a precomputed money value some import sources may supply) is NEVER
 *    read by this adapter -- OT pay is always driven by `hours` through the company's own configured
 *    OT Rate Set (`ot_rate_sets`/`ot_rate_set_items`/`master_ot_scope_types`, 2026-08-30 -- see
 *    OtRateSetModel's own docblock), the exact same path a genuine Origami sync row
 *    already goes through (payroll_sync_items itself has no precomputed-money OT column either).
 *    `amount`, when present, is informational/reference data only.
 *  - Approved leave whose leave_type is PAID (`leave_types.is_paid = 1`) intentionally produces no
 *    figure on the synthetic row at all -- same "regular pay already covers it, no line needed"
 *    reasoning SyncPayResolver already applies to Origami's own INFO-typed "Leave Approved" items.
 *    Only leave_type.is_paid = 0 (approved) feeds `leave_without_pay_days`; ANY leave_type while
 *    still `status = 'pending'` feeds `leave_wait_days` regardless of is_paid (mirrors
 *    RULE_DRIVEN_ITEM_DEFS['leave_pending']'s own reasoning: not yet confirmed as paid leave, so
 *    provisionally deducted until approved, at which point it simply stops appearing here).
 */
class TransactionDataPayAdapter {
    /**
     * @param int[] $employeeIds
     * @return array<int, array<string, mixed>> employee_id => synthetic payroll_sync_items-shaped row
     */
    public static function buildSyntheticRows(PDO $db, int $compId, array $employeeIds, string $periodStart, string $periodEnd): array {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if (empty($employeeIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $rows = [];
        $touch = function (int $employeeId) use (&$rows) {
            if (!isset($rows[$employeeId])) {
                $rows[$employeeId] = [
                    'late_mins' => 0.0, 'early_mins' => 0.0, 'absent_days' => 0.0, 'absent_mins' => 0.0,
                    'leave_without_pay_days' => 0.0, 'leave_wait_days' => 0.0,
                    'trip_allowance' => 0.0, 'item_values' => [],
                    'ot_req_working_day_hrs' => 0.0, 'ot_req_weekend_hrs' => 0.0, 'ot_req_holiday_hrs' => 0.0,
                ];
            }
        };

        $stmtAtt = $db->prepare("SELECT employee_id, SUM(late_minutes) AS late_mins, SUM(early_leave_minutes) AS early_mins,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent_days
            FROM attendance_records
            WHERE comp_id = ? AND employee_id IN ({$placeholders}) AND deleted_at IS NULL AND work_date BETWEEN ? AND ?
            GROUP BY employee_id");
        $stmtAtt->execute(array_merge([$compId], $employeeIds, [$periodStart, $periodEnd]));
        foreach ($stmtAtt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $employeeId = (int)$r['employee_id'];
            $touch($employeeId);
            $rows[$employeeId]['late_mins'] += (float)$r['late_mins'];
            $rows[$employeeId]['early_mins'] += (float)$r['early_mins'];
            $rows[$employeeId]['absent_days'] += (float)$r['absent_days'];
        }

        $stmtLeaveUnpaid = $db->prepare("SELECT lr.employee_id, SUM(lr.total_days) AS days
            FROM leave_requests lr JOIN leave_types lt ON lt.id = lr.leave_type_id
            WHERE lr.comp_id = ? AND lr.employee_id IN ({$placeholders}) AND lr.deleted_at IS NULL
              AND lr.status = 'approved' AND lt.is_paid = 0
              AND lr.start_date >= ? AND lr.end_date <= ?
            GROUP BY lr.employee_id");
        $stmtLeaveUnpaid->execute(array_merge([$compId], $employeeIds, [$periodStart, $periodEnd]));
        foreach ($stmtLeaveUnpaid->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $employeeId = (int)$r['employee_id'];
            $touch($employeeId);
            $rows[$employeeId]['leave_without_pay_days'] += (float)$r['days'];
        }

        $stmtLeavePending = $db->prepare("SELECT lr.employee_id, SUM(lr.total_days) AS days
            FROM leave_requests lr
            WHERE lr.comp_id = ? AND lr.employee_id IN ({$placeholders}) AND lr.deleted_at IS NULL
              AND lr.status = 'pending'
              AND lr.start_date >= ? AND lr.end_date <= ?
            GROUP BY lr.employee_id");
        $stmtLeavePending->execute(array_merge([$compId], $employeeIds, [$periodStart, $periodEnd]));
        foreach ($stmtLeavePending->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $employeeId = (int)$r['employee_id'];
            $touch($employeeId);
            $rows[$employeeId]['leave_wait_days'] += (float)$r['days'];
        }

        $stmtOt = $db->prepare("SELECT ot.employee_id, s.code AS scope_code, SUM(ot.hours) AS hours
            FROM overtime_records ot
            JOIN ot_rate_set_items r ON r.id = ot.ot_rate_id
            JOIN master_ot_scope_types s ON s.id = r.ot_scope_id
            WHERE ot.comp_id = ? AND ot.employee_id IN ({$placeholders}) AND ot.deleted_at IS NULL
              AND ot.status = 'approved' AND ot.ot_date BETWEEN ? AND ?
            GROUP BY ot.employee_id, s.code");
        $stmtOt->execute(array_merge([$compId], $employeeIds, [$periodStart, $periodEnd]));
        $scopeColumn = ['weekday' => 'ot_req_working_day_hrs', 'weekend' => 'ot_req_weekend_hrs', 'holiday' => 'ot_req_holiday_hrs'];
        foreach ($stmtOt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $employeeId = (int)$r['employee_id'];
            $column = $scopeColumn[$r['scope_code']] ?? null;
            if ($column === null) {
                continue; // an unrecognized scope code -- defensive, never expected given the fixed 3-row master table.
            }
            $touch($employeeId);
            $rows[$employeeId][$column] += (float)$r['hours'];
        }

        return $rows;
    }
}
