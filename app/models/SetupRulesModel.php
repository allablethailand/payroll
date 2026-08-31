<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/SyncPayResolver.php';

/**
 * Shift + Holiday + Work Location + Leave Type + OT Rate backend for the Setup & Rules page.
 *
 * Holiday scope priority (employee > position > department > shift) is enforced in
 * resolveHolidaysForEmployee(), used when two active holidays would otherwise both apply to the
 * same employee on the same date with different outcomes. There is no attendance/payroll consumer
 * wired to this yet in this codebase -- it's built and tested (tests/holiday_test.php) as a ready
 * engine, same precedent as ApprovalRequestModel before it was wired to a real flow.
 *
 * Leave Type quota validation: leaveTypeSave() looks up master_statutory_leave_minimums by the
 * company's country + selected category and, when found, blocks a quota_amount below that minimum
 * -- but only when unit_type='day' (the minimums table is day-denominated; hour/half-day quotas
 * aren't comparable without a conversion the codebase doesn't have, so the check is skipped rather
 * than silently misapplied).
 */
class SetupRulesModel {
    private PDO $db;

    private const SCOPE_PRIORITY = ['employee' => 4, 'position' => 3, 'department' => 2, 'shift' => 1];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /* ==================== SHIFT ==================== */

    public function shiftList(int $compId): array {
        $stmt = $this->db->prepare("SELECT s.*,
                l.location_name_th, l.location_name_en,
                (SELECT COUNT(*) FROM employees e WHERE e.shift_id = s.id AND e.deleted_at IS NULL) AS assigned_count
            FROM shifts s
            LEFT JOIN master_work_locations l ON l.id = s.work_location_id
            WHERE s.comp_id = :comp_id AND s.deleted_at IS NULL ORDER BY s.shift_name_th ASC");
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function shiftGet(int $id, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT s.*, l.location_name_th, l.location_name_en
            FROM shifts s
            LEFT JOIN master_work_locations l ON l.id = s.work_location_id
            WHERE s.id = :id AND s.comp_id = :comp_id AND s.deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function shiftAssignedEmployees(int $shiftId, int $compId): array {
        $stmt = $this->db->prepare("SELECT id,
                CONCAT(employee_no, ' - ', name_th, ' ', surname_th) AS text_th,
                CONCAT(employee_no, ' - ', name_en, ' ', surname_en) AS text_en
            FROM employees WHERE shift_id = :shift_id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':shift_id' => $shiftId, ':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function shiftAssignEmployees(int $shiftId, array $employeeIds, int $compId, int $userId): array {
        $stmtCheck = $this->db->prepare("SELECT id FROM shifts WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtCheck->execute([':id' => $shiftId, ':comp_id' => $compId]);
        if (!$stmtCheck->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        foreach ($employeeIds as $eid) {
            $stmtE = $this->db->prepare("SELECT id FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmtE->execute([':id' => $eid, ':comp_id' => $compId]);
            if (!$stmtE->fetch()) {
                return ['status' => false, 'message' => 'One or more selected employees were not found.'];
            }
        }
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare("UPDATE employees SET shift_id = NULL, updated_by = :updated_by WHERE shift_id = :shift_id AND comp_id = :comp_id")
                ->execute([':updated_by' => $userId, ':shift_id' => $shiftId, ':comp_id' => $compId]);
            if (!empty($employeeIds)) {
                $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
                $params = array_merge([$shiftId, $userId], $employeeIds, [$compId]);
                $this->db->prepare("UPDATE employees SET shift_id = ?, updated_by = ? WHERE id IN ({$placeholders}) AND comp_id = ?")
                    ->execute($params);
            }
            if ($ownTransaction) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Assigned successfully.'];
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    private function isShiftCodeDuplicate(string $code, int $compId, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM shifts WHERE comp_id = :comp_id AND shift_code = :code AND deleted_at IS NULL";
        $params = [':comp_id' => $compId, ':code' => $code];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function shiftSave(array $data, int $compId, int $userId): array {
        $nameTh = trim((string)($data['shift_name_th'] ?? ''));
        $nameEn = trim((string)($data['shift_name_en'] ?? ''));
        $code = trim((string)($data['shift_code'] ?? ''));
        $start = (string)($data['start_time'] ?? '');
        $end = (string)($data['end_time'] ?? '');
        $breakMinutes = max(0, (int)($data['break_minutes'] ?? 0));
        $description = trim((string)($data['description'] ?? ''));
        $status = in_array($data['status'] ?? '', ['active', 'inactive'], true) ? $data['status'] : 'active';
        $workLocationId = (!empty($data['work_location_id']) && is_numeric($data['work_location_id'])) ? (int)$data['work_location_id'] : null;
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;
        $worksMonday = !empty($data['works_monday']) ? 1 : 0;
        $worksTuesday = !empty($data['works_tuesday']) ? 1 : 0;
        $worksWednesday = !empty($data['works_wednesday']) ? 1 : 0;
        $worksThursday = !empty($data['works_thursday']) ? 1 : 0;
        $worksFriday = !empty($data['works_friday']) ? 1 : 0;
        $worksSaturday = !empty($data['works_saturday']) ? 1 : 0;
        $worksSunday = !empty($data['works_sunday']) ? 1 : 0;

        if ($nameTh === '' || $code === '' || $start === '' || $end === '') {
            return ['status' => false, 'message' => 'Missing required field.'];
        }
        if ($nameEn === '') {
            $nameEn = $nameTh;
        }
        if ($this->isShiftCodeDuplicate($code, $compId, $id)) {
            return ['status' => false, 'message' => 'Duplicate shift code.'];
        }
        if ($workLocationId !== null && !$this->validateWorkLocationRef($workLocationId, $compId)) {
            return ['status' => false, 'message' => 'Selected work location not found.'];
        }

        try {
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM shifts WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $stmt = $this->db->prepare("UPDATE shifts SET shift_code = :code, shift_name_th = :th, shift_name_en = :en,
                    start_time = :start, end_time = :end, break_minutes = :brk, work_location_id = :wl, description = :desc, status = :status,
                    works_monday = :mon, works_tuesday = :tue, works_wednesday = :wed, works_thursday = :thu, works_friday = :fri,
                    works_saturday = :sat, works_sunday = :sun,
                    updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([
                    ':code' => $code, ':th' => $nameTh, ':en' => $nameEn, ':start' => $start, ':end' => $end,
                    ':brk' => $breakMinutes, ':wl' => $workLocationId, ':desc' => $description !== '' ? $description : null, ':status' => $status,
                    ':mon' => $worksMonday, ':tue' => $worksTuesday, ':wed' => $worksWednesday, ':thu' => $worksThursday,
                    ':fri' => $worksFriday, ':sat' => $worksSaturday, ':sun' => $worksSunday,
                    ':updated_by' => $userId, ':id' => $id
                ]);
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id];
            }
            $stmt = $this->db->prepare("INSERT INTO shifts (comp_id, shift_code, shift_name_th, shift_name_en, start_time,
                end_time, break_minutes, work_location_id, description, status,
                works_monday, works_tuesday, works_wednesday, works_thursday, works_friday, works_saturday, works_sunday, created_by)
                VALUES (:comp_id, :code, :th, :en, :start, :end, :brk, :wl, :desc, :status,
                :mon, :tue, :wed, :thu, :fri, :sat, :sun, :created_by)");
            $stmt->execute([
                ':comp_id' => $compId, ':code' => $code, ':th' => $nameTh, ':en' => $nameEn, ':start' => $start,
                ':end' => $end, ':brk' => $breakMinutes, ':wl' => $workLocationId, ':desc' => $description !== '' ? $description : null,
                ':status' => $status,
                ':mon' => $worksMonday, ':tue' => $worksTuesday, ':wed' => $worksWednesday, ':thu' => $worksThursday,
                ':fri' => $worksFriday, ':sat' => $worksSaturday, ':sun' => $worksSunday,
                ':created_by' => $userId
            ]);
            return ['status' => true, 'message' => 'Created successfully.', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function payableDaysForEmployee(int $employeeId, int $compId, string $dateFrom, string $dateTo): array {
        $stmtE = $this->db->prepare("SELECT shift_id FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtE->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $emp = $stmtE->fetch(PDO::FETCH_ASSOC);
        $shiftId = $emp['shift_id'] ?? null;

        $workDays = null;
        if ($shiftId !== null) {
            $stmtS = $this->db->prepare("SELECT works_monday, works_tuesday, works_wednesday, works_thursday, works_friday, works_saturday, works_sunday
                FROM shifts WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmtS->execute([':id' => $shiftId, ':comp_id' => $compId]);
            $shift = $stmtS->fetch(PDO::FETCH_ASSOC);
            if ($shift) {
                $workDays = [
                    1 => (bool)$shift['works_monday'], 2 => (bool)$shift['works_tuesday'], 3 => (bool)$shift['works_wednesday'],
                    4 => (bool)$shift['works_thursday'], 5 => (bool)$shift['works_friday'], 6 => (bool)$shift['works_saturday'],
                    7 => (bool)$shift['works_sunday'],
                ];
            }
        }

        $holidays = $this->resolveHolidaysForEmployee($employeeId, $compId, $dateFrom, $dateTo);
        $holidayDates = array_flip(array_column($holidays, 'date'));

        $from = new DateTime($dateFrom);
        $to = new DateTime($dateTo);
        $totalDays = 0;
        $payableDays = 0;
        $cursor = clone $from;
        while ($cursor <= $to) {
            $totalDays++;
            $dateStr = $cursor->format('Y-m-d');
            $dow = (int)$cursor->format('N');
            $isScheduledWorkDay = $workDays === null ? true : ($workDays[$dow] ?? true);
            $isHoliday = isset($holidayDates[$dateStr]);
            if ($isScheduledWorkDay && !$isHoliday) {
                $payableDays++;
            }
            $cursor->modify('+1 day');
        }

        return ['total_days' => $totalDays, 'payable_days' => $payableDays, 'has_shift_pattern' => $workDays !== null];
    }

    /**
     * 2026-08-29, explicit request: "การคิดจำนวนวันทำงาน ตอนนี้มีส่งมาจาก Origami ว่าทำงานทั้งหมดกี่วัน
     * ให้แสดงในข้อมูลด้วยว่า จำนวนวันในรอบนั้นกี่วัน วันทำงานกี่วัน วันหยุดนักขัตฤกษ์กี่วัน วันหยุดประจำสัปดาห์กี่วัน"
     * -- a richer companion to payableDaysForEmployee() above (that method stays untouched, still
     * feeds real payroll proration -- this one is purely informational, for the "Raw Sync Data"
     * viewer to show alongside Origami's own reported working_days number). Every calendar day in
     * range is categorized into EXACTLY ONE bucket (never double-counted): a holiday day counts as
     * `holiday_days` regardless of whether it also happens to fall on a scheduled work day or an
     * already-off weekly day (same "holiday wins" priority payableDaysForEmployee() already uses);
     * a non-holiday day that isn't a scheduled work day counts as `weekly_off_days`; everything else
     * counts as `working_days`. total_days always equals the sum of the other three.
     * @return array{total_days:int,working_days:int,holiday_days:int,weekly_off_days:int,has_shift_pattern:bool}
     */
    public function workingDaysBreakdown(int $employeeId, int $compId, string $dateFrom, string $dateTo): array {
        $stmtE = $this->db->prepare("SELECT shift_id FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtE->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $emp = $stmtE->fetch(PDO::FETCH_ASSOC);
        $shiftId = $emp['shift_id'] ?? null;

        $workDays = null;
        if ($shiftId !== null) {
            $stmtS = $this->db->prepare("SELECT works_monday, works_tuesday, works_wednesday, works_thursday, works_friday, works_saturday, works_sunday
                FROM shifts WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmtS->execute([':id' => $shiftId, ':comp_id' => $compId]);
            $shift = $stmtS->fetch(PDO::FETCH_ASSOC);
            if ($shift) {
                $workDays = [
                    1 => (bool)$shift['works_monday'], 2 => (bool)$shift['works_tuesday'], 3 => (bool)$shift['works_wednesday'],
                    4 => (bool)$shift['works_thursday'], 5 => (bool)$shift['works_friday'], 6 => (bool)$shift['works_saturday'],
                    7 => (bool)$shift['works_sunday'],
                ];
            }
        }

        $holidays = $this->resolveHolidaysForEmployee($employeeId, $compId, $dateFrom, $dateTo);
        $holidayDates = array_flip(array_column($holidays, 'date'));

        $from = new DateTime($dateFrom);
        $to = new DateTime($dateTo);
        $totalDays = 0;
        $workingDaysCount = 0;
        $holidayDaysCount = 0;
        $weeklyOffDaysCount = 0;
        $cursor = clone $from;
        while ($cursor <= $to) {
            $totalDays++;
            $dateStr = $cursor->format('Y-m-d');
            $dow = (int)$cursor->format('N');
            $isScheduledWorkDay = $workDays === null ? true : ($workDays[$dow] ?? true);
            $isHoliday = isset($holidayDates[$dateStr]);
            if ($isHoliday) {
                $holidayDaysCount++;
            } elseif (!$isScheduledWorkDay) {
                $weeklyOffDaysCount++;
            } else {
                $workingDaysCount++;
            }
            $cursor->modify('+1 day');
        }

        return [
            'total_days' => $totalDays, 'working_days' => $workingDaysCount,
            'holiday_days' => $holidayDaysCount, 'weekly_off_days' => $weeklyOffDaysCount,
            'has_shift_pattern' => $workDays !== null,
        ];
    }

    /**
     * 2026-08-30, Payroll Policy "pay_basis='schedule_based'" rollout (explicit request: "จ่ายตามวันที่
     * มาทำงาน หักวันหยุด หักวันลาไหม") -- computes how many of a MONTHLY-rate employee's own SCHEDULED
     * work days (per their Shift's weekly-off pattern, same resolution as payableDaysForEmployee()/
     * workingDaysBreakdown() above) within a date range are payable, optionally excluding
     * holidays/unpaid-leave days. Confirmed via AskUserQuestion: intentionally modeled on
     * salary_type='daily' proration (schedule+config only) rather than on Attendance Deduction
     * Rule's own sync-derived absence formulas, specifically to AVOID double-deducting the same
     * absence twice for a company that has both features configured -- this method never reads
     * actual attendance/sync data at all.
     *
     * Deliberately narrower than workingDaysBreakdown() above: that method's own `holiday_days`
     * bucket also counts a holiday that lands on an ALREADY weekly-off day (holiday-wins priority) --
     * reusing it directly here would wrongly inflate "total scheduled days" with holidays the
     * employee was never scheduled to work anyway. This method counts a holiday only when it falls
     * on what would otherwise have been a scheduled work day.
     *
     * $deductHolidays/$deductLeave both false (the "toggle exists but nothing opted in" case) always
     * yields payable_days === total_scheduled_days, i.e. 100% of base salary for a normal period --
     * same safe-default spirit as every other Payroll Policy column (PayrollPolicyModel's own
     * docblock). Unpaid leave = an APPROVED `leave_requests` row whose `leave_types.is_paid = 0` --
     * deliberately excludes PAID leave types (annual/paid sick leave etc.) from ever reducing pay,
     * since docking pay for using entitled PAID leave would be a real, harmful bug, not a feature.
     * @return array{total_scheduled_days:int,holiday_on_scheduled_days:int,payable_days:float}
     */
    public function scheduledPayableDaysForEmployee(int $employeeId, int $compId, string $dateFrom, string $dateTo, bool $deductHolidays, bool $deductLeave): array {
        $stmtE = $this->db->prepare("SELECT shift_id FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtE->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $emp = $stmtE->fetch(PDO::FETCH_ASSOC);
        $shiftId = $emp['shift_id'] ?? null;

        $workDays = null;
        if ($shiftId !== null) {
            $stmtS = $this->db->prepare("SELECT works_monday, works_tuesday, works_wednesday, works_thursday, works_friday, works_saturday, works_sunday
                FROM shifts WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmtS->execute([':id' => $shiftId, ':comp_id' => $compId]);
            $shift = $stmtS->fetch(PDO::FETCH_ASSOC);
            if ($shift) {
                $workDays = [
                    1 => (bool)$shift['works_monday'], 2 => (bool)$shift['works_tuesday'], 3 => (bool)$shift['works_wednesday'],
                    4 => (bool)$shift['works_thursday'], 5 => (bool)$shift['works_friday'], 6 => (bool)$shift['works_saturday'],
                    7 => (bool)$shift['works_sunday'],
                ];
            }
        }

        $holidays = $this->resolveHolidaysForEmployee($employeeId, $compId, $dateFrom, $dateTo);
        $holidayDates = array_flip(array_column($holidays, 'date'));

        $from = new DateTime($dateFrom);
        $to = new DateTime($dateTo);
        $totalScheduledDays = 0;
        $holidayOnScheduledDays = 0;
        $cursor = clone $from;
        while ($cursor <= $to) {
            $dateStr = $cursor->format('Y-m-d');
            $dow = (int)$cursor->format('N');
            $isScheduledWorkDay = $workDays === null ? true : ($workDays[$dow] ?? true);
            if ($isScheduledWorkDay) {
                $totalScheduledDays++;
                if (isset($holidayDates[$dateStr])) {
                    $holidayOnScheduledDays++;
                }
            }
            $cursor->modify('+1 day');
        }

        $payableDays = (float)$totalScheduledDays;
        if ($deductHolidays) {
            $payableDays -= $holidayOnScheduledDays;
        }
        if ($deductLeave) {
            $payableDays -= $this->approvedUnpaidLeaveDays($employeeId, $compId, $dateFrom, $dateTo);
        }

        return [
            'total_scheduled_days' => $totalScheduledDays,
            'holiday_on_scheduled_days' => $holidayOnScheduledDays,
            'payable_days' => max(0.0, $payableDays),
        ];
    }

    private function approvedUnpaidLeaveDays(int $employeeId, int $compId, string $dateFrom, string $dateTo): float {
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(lr.total_days), 0) AS total
            FROM `leave_requests` lr
            JOIN `leave_types` lt ON lt.id = lr.leave_type_id
            WHERE lr.employee_id = :employee_id AND lr.comp_id = :comp_id AND lr.status = 'approved' AND lr.deleted_at IS NULL
            AND lt.is_paid = 0 AND lr.start_date <= :date_to AND lr.end_date >= :date_from");
        $stmt->execute([':employee_id' => $employeeId, ':comp_id' => $compId, ':date_to' => $dateTo, ':date_from' => $dateFrom]);
        return (float)$stmt->fetchColumn();
    }

    public function shiftToggleStatus(int $id, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT status FROM shifts WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $newStatus = $current === 'active' ? 'inactive' : 'active';
        $this->db->prepare("UPDATE shifts SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':status' => $newStatus, ':updated_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Updated successfully.', 'new_status' => $newStatus];
    }

    public function shiftDelete(int $id, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT id FROM shifts WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        if (!$stmt->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $stmtRef = $this->db->prepare("SELECT COUNT(*) FROM holiday_assignments ha
            JOIN holidays h ON h.id = ha.holiday_id
            WHERE ha.scope_type = 'shift' AND ha.scope_id = :id AND h.deleted_at IS NULL");
        $stmtRef->execute([':id' => $id]);
        if ((int)$stmtRef->fetchColumn() > 0) {
            return ['status' => false, 'message' => 'This shift is used by one or more holidays and cannot be deleted.'];
        }
        $this->db->prepare("UPDATE shifts SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id")
            ->execute([':deleted_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    /* ==================== HOLIDAY ==================== */

    public function holidayList(int $compId): array {
        $stmt = $this->db->prepare("SELECT h.*,
                (SELECT COUNT(*) FROM holiday_assignments ha WHERE ha.holiday_id = h.id) AS assignment_count
            FROM holidays h
            WHERE h.comp_id = :comp_id AND h.deleted_at IS NULL
            ORDER BY h.holiday_date ASC");
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function holidayGet(int $id, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM holidays WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $holiday = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$holiday) {
            return null;
        }
        $stmtA = $this->db->prepare("SELECT scope_type, scope_id FROM holiday_assignments WHERE holiday_id = :id");
        $stmtA->execute([':id' => $id]);
        $assignments = [];
        foreach ($stmtA->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $label = $this->scopeLabel($row['scope_type'], (int)$row['scope_id']);
            $assignments[] = [
                'scope_type' => $row['scope_type'],
                'scope_id' => (int)$row['scope_id'],
                'text_th' => $label['text_th'] ?? '',
                'text_en' => $label['text_en'] ?? '',
            ];
        }
        $holiday['assignments'] = $assignments;
        return $holiday;
    }

    private function scopeLabel(string $scopeType, int $scopeId): array {
        switch ($scopeType) {
            case 'shift':
                $sql = "SELECT CONCAT(shift_code, ' - ', shift_name_th) AS text_th, CONCAT(shift_code, ' - ', shift_name_en) AS text_en FROM shifts WHERE id = :id";
                break;
            case 'department':
                $sql = "SELECT CONCAT(department_code, ' - ', department_name_th) AS text_th, CONCAT(department_code, ' - ', department_name_en) AS text_en FROM structure_departments WHERE id = :id";
                break;
            case 'position':
                $sql = "SELECT CONCAT(position_code, ' - ', position_name_th) AS text_th, CONCAT(position_code, ' - ', position_name_en) AS text_en FROM structure_positions WHERE id = :id";
                break;
            case 'employee':
                $sql = "SELECT CONCAT(employee_no, ' - ', name_th, ' ', surname_th) AS text_th, CONCAT(employee_no, ' - ', name_en, ' ', surname_en) AS text_en FROM employees WHERE id = :id";
                break;
            default:
                return [];
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $scopeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: [];
    }

    private function validateWorkLocationRef(int $locationId, int $compId): bool {
        $stmt = $this->db->prepare("SELECT id FROM master_work_locations WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $locationId, ':comp_id' => $compId]);
        return (bool)$stmt->fetchColumn();
    }

    private function validateScopeRef(string $scopeType, int $scopeId, int $compId): bool {
        switch ($scopeType) {
            case 'shift':
                $sql = "SELECT id FROM shifts WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL";
                break;
            case 'department':
                $sql = "SELECT id FROM structure_departments WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL";
                break;
            case 'position':
                $sql = "SELECT id FROM structure_positions WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL";
                break;
            case 'employee':
                $sql = "SELECT id FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL";
                break;
            default:
                return false;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $scopeId, ':comp_id' => $compId]);
        return (bool)$stmt->fetchColumn();
    }

    public function holidaySave(array $data, int $compId, int $userId): array {
        $nameTh = trim((string)($data['name_th'] ?? ''));
        $nameEn = trim((string)($data['name_en'] ?? ''));
        $holidayDate = (string)($data['holiday_date'] ?? '');
        $isRecurring = !empty($data['is_recurring']) ? 1 : 0;
        $mode = in_array($data['assignment_mode'] ?? '', ['include', 'exclude'], true) ? $data['assignment_mode'] : 'include';
        $remark = trim((string)($data['remark'] ?? ''));
        $status = in_array($data['status'] ?? '', ['active', 'inactive'], true) ? $data['status'] : 'active';
        $assignmentsInput = is_array($data['assignments'] ?? null) ? $data['assignments'] : [];
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        if ($nameTh === '' || $nameEn === '') {
            return ['status' => false, 'message' => 'Missing required field: name'];
        }
        $dt = DateTime::createFromFormat('Y-m-d', $holidayDate);
        if (!$dt || $dt->format('Y-m-d') !== $holidayDate) {
            return ['status' => false, 'message' => 'Missing or invalid holiday date.'];
        }

        $validScopeTypes = ['shift', 'department', 'position', 'employee'];
        $seen = [];
        $cleanAssignments = [];
        foreach ($assignmentsInput as $a) {
            $scopeType = (string)($a['scope_type'] ?? '');
            $scopeId = (int)($a['scope_id'] ?? 0);
            if (!in_array($scopeType, $validScopeTypes, true) || $scopeId <= 0) {
                return ['status' => false, 'message' => 'Invalid scope selection.'];
            }
            if (!$this->validateScopeRef($scopeType, $scopeId, $compId)) {
                return ['status' => false, 'message' => "Selected {$scopeType} not found."];
            }
            $key = $scopeType . ':' . $scopeId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $cleanAssignments[] = ['scope_type' => $scopeType, 'scope_id' => $scopeId];
        }
        if ($mode === 'include' && count($cleanAssignments) === 0) {
            return ['status' => false, 'message' => 'Include mode requires at least one scope selection.'];
        }

        $stmtC = $this->db->prepare("SELECT registered_country FROM companies WHERE id = :id");
        $stmtC->execute([':id' => $compId]);
        $countryCode = (string)$stmtC->fetchColumn();
        if ($countryCode === '') {
            return ['status' => false, 'message' => 'Company country is not configured.'];
        }

        // Scope-blind date-collision check: two active holidays landing on the same effective date
        // for this company are flagged regardless of whether their scopes would actually overlap --
        // full scope-aware overlap is handled at read time by resolveHolidaysForEmployee() instead.
        $month = (int)substr($holidayDate, 5, 2);
        $day = (int)substr($holidayDate, 8, 2);
        $sqlOthers = "SELECT id, holiday_date, is_recurring FROM holidays WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL";
        $paramsOthers = [':comp_id' => $compId];
        if ($id !== null) {
            $sqlOthers .= " AND id != :id";
            $paramsOthers[':id'] = $id;
        }
        $stmtOthers = $this->db->prepare($sqlOthers);
        $stmtOthers->execute($paramsOthers);
        foreach ($stmtOthers->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $oMonth = (int)substr($o['holiday_date'], 5, 2);
            $oDay = (int)substr($o['holiday_date'], 8, 2);
            $sameRecurringDay = $isRecurring && (int)$o['is_recurring'] === 1 && $oMonth === $month && $oDay === $day;
            $sameExactDate = (!$isRecurring && (int)$o['is_recurring'] === 0) && $o['holiday_date'] === $holidayDate;
            if ($sameRecurringDay || $sameExactDate) {
                return ['status' => false, 'message' => 'Another holiday is already defined on this date.'];
            }
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM holidays WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    if ($ownTransaction) {
                        $this->db->rollBack();
                    }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $stmt = $this->db->prepare("UPDATE holidays SET name_th = :name_th, name_en = :name_en, country_code = :country_code,
                    holiday_date = :holiday_date, is_recurring = :is_recurring, assignment_mode = :mode, remark = :remark,
                    status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([
                    ':name_th' => $nameTh, ':name_en' => $nameEn, ':country_code' => $countryCode, ':holiday_date' => $holidayDate,
                    ':is_recurring' => $isRecurring, ':mode' => $mode, ':remark' => $remark !== '' ? $remark : null,
                    ':status' => $status, ':updated_by' => $userId, ':id' => $id
                ]);
                $this->db->prepare("DELETE FROM holiday_assignments WHERE holiday_id = :id")->execute([':id' => $id]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO holidays (comp_id, country_code, name_th, name_en, holiday_date,
                    is_recurring, assignment_mode, remark, status, created_by)
                    VALUES (:comp_id, :country_code, :name_th, :name_en, :holiday_date, :is_recurring, :mode, :remark, :status, :created_by)");
                $stmt->execute([
                    ':comp_id' => $compId, ':country_code' => $countryCode, ':name_th' => $nameTh, ':name_en' => $nameEn,
                    ':holiday_date' => $holidayDate, ':is_recurring' => $isRecurring, ':mode' => $mode,
                    ':remark' => $remark !== '' ? $remark : null, ':status' => $status, ':created_by' => $userId
                ]);
                $id = (int)$this->db->lastInsertId();
            }
            if (!empty($cleanAssignments)) {
                $insA = $this->db->prepare("INSERT INTO holiday_assignments (holiday_id, scope_type, scope_id) VALUES (:holiday_id, :scope_type, :scope_id)");
                foreach ($cleanAssignments as $a) {
                    $insA->execute([':holiday_id' => $id, ':scope_type' => $a['scope_type'], ':scope_id' => $a['scope_id']]);
                }
            }
            if ($ownTransaction) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Saved successfully.', 'id' => $id];
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function holidayDelete(int $id, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT id FROM holidays WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        if (!$stmt->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $this->db->prepare("UPDATE holidays SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id")
            ->execute([':deleted_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    public function holidayToggleStatus(int $id, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT status FROM holidays WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $newStatus = $current === 'active' ? 'inactive' : 'active';
        $this->db->prepare("UPDATE holidays SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':status' => $newStatus, ':updated_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Updated successfully.', 'new_status' => $newStatus];
    }

    /**
     * Resolves, for one employee across a date range, which holiday (if any) applies on each date.
     * When more than one active holiday would apply to the same date, the one whose matching scope
     * has the highest priority (employee > position > department > shift) wins; an exclude-mode
     * holiday that applies because nothing matched is treated as the lowest priority (company-wide).
     */
    public function resolveHolidaysForEmployee(int $employeeId, int $compId, string $dateFrom, string $dateTo): array {
        $stmtE = $this->db->prepare("SELECT department_id, position_id, shift_id FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtE->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $emp = $stmtE->fetch(PDO::FETCH_ASSOC);
        if (!$emp) {
            return [];
        }

        $stmtH = $this->db->prepare("SELECT h.id, h.name_th, h.name_en, h.holiday_date, h.is_recurring, h.assignment_mode,
                ha.scope_type, ha.scope_id
            FROM holidays h
            LEFT JOIN holiday_assignments ha ON ha.holiday_id = h.id
            WHERE h.comp_id = :comp_id AND h.status = 'active' AND h.deleted_at IS NULL");
        $stmtH->execute([':comp_id' => $compId]);

        $byHoliday = [];
        foreach ($stmtH->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $hid = (int)$row['id'];
            if (!isset($byHoliday[$hid])) {
                $byHoliday[$hid] = [
                    'holiday' => [
                        'id' => $hid, 'name_th' => $row['name_th'], 'name_en' => $row['name_en'],
                        'holiday_date' => $row['holiday_date'], 'is_recurring' => (int)$row['is_recurring'],
                        'assignment_mode' => $row['assignment_mode'],
                    ],
                    'assignments' => [],
                ];
            }
            if ($row['scope_type'] !== null) {
                $byHoliday[$hid]['assignments'][] = ['scope_type' => $row['scope_type'], 'scope_id' => (int)$row['scope_id']];
            }
        }

        $from = new DateTime($dateFrom);
        $to = new DateTime($dateTo);
        $results = [];

        foreach ($byHoliday as $entry) {
            $h = $entry['holiday'];
            $matchedPriority = 0;
            $matchedType = null;
            foreach ($entry['assignments'] as $a) {
                $matches = false;
                if ($a['scope_type'] === 'employee' && $a['scope_id'] === $employeeId) {
                    $matches = true;
                } elseif ($a['scope_type'] === 'position' && $emp['position_id'] !== null && $a['scope_id'] === (int)$emp['position_id']) {
                    $matches = true;
                } elseif ($a['scope_type'] === 'department' && $emp['department_id'] !== null && $a['scope_id'] === (int)$emp['department_id']) {
                    $matches = true;
                } elseif ($a['scope_type'] === 'shift' && $emp['shift_id'] !== null && $a['scope_id'] === (int)$emp['shift_id']) {
                    $matches = true;
                }
                if ($matches && self::SCOPE_PRIORITY[$a['scope_type']] > $matchedPriority) {
                    $matchedPriority = self::SCOPE_PRIORITY[$a['scope_type']];
                    $matchedType = $a['scope_type'];
                }
            }
            $isMatched = $matchedType !== null;
            $applies = ($h['assignment_mode'] === 'include') ? $isMatched : !$isMatched;
            if (!$applies) {
                continue;
            }
            $effectivePriority = $isMatched ? $matchedPriority : 0;

            foreach ($this->occurrencesInRange($h, $from, $to) as $d) {
                $existing = $results[$d] ?? null;
                if ($existing === null
                    || $effectivePriority > $existing['priority']
                    || ($effectivePriority === $existing['priority'] && $h['id'] > $existing['holiday_id'])) {
                    $results[$d] = [
                        'date' => $d,
                        'holiday_id' => $h['id'],
                        'name_th' => $h['name_th'],
                        'name_en' => $h['name_en'],
                        'priority' => $effectivePriority,
                        'matched_scope' => $matchedType,
                    ];
                }
            }
        }
        ksort($results);
        return array_values($results);
    }

    private function occurrencesInRange(array $holiday, DateTime $from, DateTime $to): array {
        $out = [];
        $fromStr = $from->format('Y-m-d');
        $toStr = $to->format('Y-m-d');
        if (!$holiday['is_recurring']) {
            $d = $holiday['holiday_date'];
            if ($d >= $fromStr && $d <= $toStr) {
                $out[] = $d;
            }
            return $out;
        }
        $month = (int)substr($holiday['holiday_date'], 5, 2);
        $day = (int)substr($holiday['holiday_date'], 8, 2);
        for ($y = (int)$from->format('Y'); $y <= (int)$to->format('Y'); $y++) {
            if ($month === 2 && $day === 29 && !checkdate(2, 29, $y)) {
                // Known limitation: a recurring Feb 29 holiday is skipped in non-leap years rather than
                // shifted to Feb 28/Mar 1 -- acceptable for now, not silently "fixed" with a guess.
                continue;
            }
            $candidate = sprintf('%04d-%02d-%02d', $y, $month, $day);
            if ($candidate >= $fromStr && $candidate <= $toStr) {
                $out[] = $candidate;
            }
        }
        return $out;
    }

    /* ==================== WORK LOCATION ==================== */

    public function workLocationOptions(int $compId, string $search = ''): array {
        $sql = "SELECT id, CONCAT(location_code, ' - ', location_name_th) AS text_th,
                CONCAT(location_code, ' - ', location_name_en) AS text_en
            FROM master_work_locations WHERE comp_id = :comp_id AND deleted_at IS NULL AND status = 'active'";
        $params = [':comp_id' => $compId];
        if ($search !== '') {
            $sql .= " AND (location_name_th LIKE :search1 OR location_name_en LIKE :search2 OR location_code LIKE :search3)";
            $params[':search1'] = $params[':search2'] = $params[':search3'] = "%{$search}%";
        }
        $sql .= " ORDER BY location_name_th ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function workLocationList(int $compId): array {
        $stmt = $this->db->prepare("SELECT * FROM master_work_locations WHERE comp_id = :comp_id AND deleted_at IS NULL ORDER BY location_name_th ASC");
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function workLocationGet(int $id, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM master_work_locations WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function isWorkLocationCodeDuplicate(string $code, int $compId, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM master_work_locations WHERE comp_id = :comp_id AND location_code = :code AND deleted_at IS NULL";
        $params = [':comp_id' => $compId, ':code' => $code];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function workLocationSave(array $data, int $compId, int $userId): array {
        $nameTh = trim((string)($data['location_name_th'] ?? ''));
        $nameEn = trim((string)($data['location_name_en'] ?? ''));
        $code = trim((string)($data['location_code'] ?? ''));
        $address = trim((string)($data['address'] ?? ''));
        $status = in_array($data['status'] ?? '', ['active', 'inactive'], true) ? $data['status'] : 'active';
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        if ($nameTh === '' || $code === '') {
            return ['status' => false, 'message' => 'Missing required field.'];
        }
        if ($nameEn === '') {
            $nameEn = $nameTh;
        }
        if ($this->isWorkLocationCodeDuplicate($code, $compId, $id)) {
            return ['status' => false, 'message' => 'Duplicate location code.'];
        }

        try {
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM master_work_locations WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $stmt = $this->db->prepare("UPDATE master_work_locations SET location_code = :code, location_name_th = :th,
                    location_name_en = :en, address = :address, status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id");
                $stmt->execute([
                    ':code' => $code, ':th' => $nameTh, ':en' => $nameEn, ':address' => $address !== '' ? $address : null,
                    ':status' => $status, ':updated_by' => $userId, ':id' => $id
                ]);
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id];
            }
            $stmt = $this->db->prepare("INSERT INTO master_work_locations (comp_id, location_code, location_name_th, location_name_en,
                address, status, created_by) VALUES (:comp_id, :code, :th, :en, :address, :status, :created_by)");
            $stmt->execute([
                ':comp_id' => $compId, ':code' => $code, ':th' => $nameTh, ':en' => $nameEn,
                ':address' => $address !== '' ? $address : null, ':status' => $status, ':created_by' => $userId
            ]);
            return ['status' => true, 'message' => 'Created successfully.', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function workLocationDelete(int $id, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT id FROM master_work_locations WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        if (!$stmt->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $stmtRef = $this->db->prepare("SELECT COUNT(*) FROM shifts WHERE work_location_id = :id AND deleted_at IS NULL");
        $stmtRef->execute([':id' => $id]);
        if ((int)$stmtRef->fetchColumn() > 0) {
            return ['status' => false, 'message' => 'This location is used by one or more shifts and cannot be deleted.'];
        }
        $this->db->prepare("UPDATE master_work_locations SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id")
            ->execute([':deleted_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    public function workLocationToggleStatus(int $id, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT status FROM master_work_locations WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $newStatus = $current === 'active' ? 'inactive' : 'active';
        $this->db->prepare("UPDATE master_work_locations SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':status' => $newStatus, ':updated_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Updated successfully.', 'new_status' => $newStatus];
    }

    /* ==================== LEAVE TYPE ==================== */

    public function leaveCategoryOptions(string $search = ''): array {
        $sql = "SELECT id, name_th AS text_th, name_en AS text_en FROM master_leave_categories WHERE is_active = 1";
        $params = [];
        if ($search !== '') {
            $sql .= " AND (name_th LIKE :search1 OR name_en LIKE :search2)";
            $params[':search1'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
        }
        $sql .= " ORDER BY sort_order ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function leaveTypeList(int $compId): array {
        $stmt = $this->db->prepare("SELECT lt.*, c.name_th AS category_name_th, c.name_en AS category_name_en
            FROM leave_types lt
            JOIN master_leave_categories c ON c.id = lt.category_id
            WHERE lt.comp_id = :comp_id AND lt.deleted_at IS NULL
            ORDER BY lt.name_th ASC");
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function leaveTypeGet(int $id, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT lt.*, c.name_th AS category_name_th, c.name_en AS category_name_en
            FROM leave_types lt
            JOIN master_leave_categories c ON c.id = lt.category_id
            WHERE lt.id = :id AND lt.comp_id = :comp_id AND lt.deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function isLeaveTypeCodeDuplicate(string $code, int $compId, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM leave_types WHERE comp_id = :comp_id AND code = :code AND deleted_at IS NULL";
        $params = [':comp_id' => $compId, ':code' => $code];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    private const VALID_EMPLOYMENT_STATUSES = ['probation', 'permanent', 'contract', 'resigned', 'terminated'];

    public function leaveTypeSave(array $data, int $compId, int $userId): array {
        $nameTh = trim((string)($data['name_th'] ?? ''));
        $nameEn = trim((string)($data['name_en'] ?? ''));
        $code = trim((string)($data['code'] ?? ''));
        $categoryId = (int)($data['category_id'] ?? 0);
        $quotaType = in_array($data['quota_type'] ?? '', ['fixed', 'prorate'], true) ? $data['quota_type'] : 'fixed';
        $quotaAmount = (float)($data['quota_amount'] ?? 0);
        $unitType = in_array($data['unit_type'] ?? '', ['day', 'hour', 'half_day'], true) ? $data['unit_type'] : 'day';
        $requiresDocument = !empty($data['requires_document']) ? 1 : 0;
        $isContinuous = !empty($data['is_continuous']) ? 1 : 0;
        $isPaid = array_key_exists('is_paid', $data) ? (!empty($data['is_paid']) ? 1 : 0) : 1;
        $allowCarryOver = !empty($data['allow_carry_over']) ? 1 : 0;
        $genderRestriction = in_array($data['gender_restriction'] ?? '', ['all', 'male', 'female'], true) ? $data['gender_restriction'] : 'all';
        $minServiceDays = ($data['min_service_days'] ?? '') !== '' ? max(0, (int)$data['min_service_days']) : null;
        $advanceNoticeDays = ($data['advance_notice_days'] ?? '') !== '' ? max(0, (int)$data['advance_notice_days']) : null;
        $maxConsecutiveDays = ($data['max_consecutive_days'] ?? '') !== '' ? max(0, (float)$data['max_consecutive_days']) : null;
        $applicableStatusesInput = is_array($data['applicable_employment_statuses'] ?? null) ? $data['applicable_employment_statuses'] : [];
        $status = in_array($data['status'] ?? '', ['active', 'inactive'], true) ? $data['status'] : 'active';
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        if ($nameTh === '' || $nameEn === '' || $code === '' || $categoryId <= 0) {
            return ['status' => false, 'message' => 'Missing required field.'];
        }
        $stmtCat = $this->db->prepare("SELECT id FROM master_leave_categories WHERE id = :id AND is_active = 1");
        $stmtCat->execute([':id' => $categoryId]);
        if (!$stmtCat->fetch()) {
            return ['status' => false, 'message' => 'Selected category not found.'];
        }
        if ($this->isLeaveTypeCodeDuplicate($code, $compId, $id)) {
            return ['status' => false, 'message' => 'Duplicate leave type code.'];
        }
        $applicableStatuses = array_values(array_intersect(array_map('strval', $applicableStatusesInput), self::VALID_EMPLOYMENT_STATUSES));
        foreach ($applicableStatusesInput as $s) {
            if (!in_array((string)$s, self::VALID_EMPLOYMENT_STATUSES, true)) {
                return ['status' => false, 'message' => 'Invalid employment status selection.'];
            }
        }
        $applicableStatusesCsv = !empty($applicableStatuses) ? implode(',', $applicableStatuses) : null;

        $stmtC = $this->db->prepare("SELECT registered_country FROM companies WHERE id = :id");
        $stmtC->execute([':id' => $compId]);
        $countryCode = (string)$stmtC->fetchColumn();

        $statutoryMinimum = null;
        if ($countryCode !== '') {
            $stmtMin = $this->db->prepare("SELECT min_days FROM master_statutory_leave_minimums WHERE country_code = :cc AND category_id = :cat");
            $stmtMin->execute([':cc' => $countryCode, ':cat' => $categoryId]);
            $minVal = $stmtMin->fetchColumn();
            if ($minVal !== false) {
                $statutoryMinimum = (float)$minVal;
                if ($unitType === 'day' && $quotaAmount < $statutoryMinimum) {
                    return ['status' => false, 'message' => "Quota is below the statutory minimum for this category ({$statutoryMinimum} days)."];
                }
            }
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $params = [
                ':category_id' => $categoryId, ':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn,
                ':quota_type' => $quotaType, ':quota_amount' => $quotaAmount, ':unit_type' => $unitType,
                ':requires_document' => $requiresDocument, ':is_continuous' => $isContinuous, ':is_paid' => $isPaid,
                ':allow_carry_over' => $allowCarryOver, ':statutory_minimum_days' => $statutoryMinimum,
                ':gender_restriction' => $genderRestriction, ':min_service_days' => $minServiceDays,
                ':advance_notice_days' => $advanceNoticeDays, ':max_consecutive_days' => $maxConsecutiveDays,
                ':applicable_employment_statuses' => $applicableStatusesCsv, ':status' => $status,
            ];
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM leave_types WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    if ($ownTransaction) {
                        $this->db->rollBack();
                    }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $params[':id'] = $id;
                $params[':updated_by'] = $userId;
                $stmt = $this->db->prepare("UPDATE leave_types SET category_id = :category_id, code = :code, name_th = :name_th,
                    name_en = :name_en, quota_type = :quota_type, quota_amount = :quota_amount, unit_type = :unit_type,
                    requires_document = :requires_document, is_continuous = :is_continuous, is_paid = :is_paid,
                    allow_carry_over = :allow_carry_over, statutory_minimum_days = :statutory_minimum_days,
                    gender_restriction = :gender_restriction, min_service_days = :min_service_days,
                    advance_notice_days = :advance_notice_days, max_consecutive_days = :max_consecutive_days,
                    applicable_employment_statuses = :applicable_employment_statuses, status = :status,
                    updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute($params);
                if ($ownTransaction) {
                    $this->db->commit();
                }
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id];
            }
            $params[':comp_id'] = $compId;
            $params[':created_by'] = $userId;
            $stmt = $this->db->prepare("INSERT INTO leave_types (comp_id, category_id, code, name_th, name_en, quota_type,
                quota_amount, unit_type, requires_document, is_continuous, is_paid, allow_carry_over, statutory_minimum_days,
                gender_restriction, min_service_days, advance_notice_days, max_consecutive_days, applicable_employment_statuses,
                status, created_by)
                VALUES (:comp_id, :category_id, :code, :name_th, :name_en, :quota_type, :quota_amount, :unit_type,
                :requires_document, :is_continuous, :is_paid, :allow_carry_over, :statutory_minimum_days, :gender_restriction,
                :min_service_days, :advance_notice_days, :max_consecutive_days, :applicable_employment_statuses, :status, :created_by)");
            $stmt->execute($params);
            $newId = (int)$this->db->lastInsertId();
            if ($ownTransaction) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Created successfully.', 'id' => $newId];
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function leaveTypeDelete(int $id, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT id FROM leave_types WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        if (!$stmt->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $this->db->prepare("UPDATE leave_types SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id")
            ->execute([':deleted_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    public function leaveTypeToggleStatus(int $id, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT status FROM leave_types WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $newStatus = $current === 'active' ? 'inactive' : 'active';
        $this->db->prepare("UPDATE leave_types SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':status' => $newStatus, ':updated_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Updated successfully.', 'new_status' => $newStatus];
    }

    /**
     * "Apply Default" button (2026-08-28, explicit request: "seed ผ่าน apply default button ครับ",
     * following up on the earlier request that every data-management tab ship with starter data
     * an admin can edit/delete/extend afterward) -- one starter row per master_leave_categories
     * entry (category_id 1-10), general Thai-HR-SaaS-standard values, NOT verified against every
     * country's actual labor law (same DRAFT/unverified caveat this project already carries on
     * master_statutory_leave_minimums itself -- only annual(6)/maternity(98) are pinned exactly to
     * that table's own TH floor, the rest are common-practice defaults an admin is expected to
     * review). Deliberately reuses leaveTypeSave() row-by-row rather than a bare bulk INSERT, so a
     * seeded row goes through the EXACT same validation (active category, duplicate-code check,
     * statutory-minimum floor) a manually-created one would -- no parallel/divergent insert path
     * to drift out of sync with leaveTypeSave() over time.
     *
     * Idempotent by design: a default whose CODE already exists for this company (either because
     * it was already seeded once, or an admin independently created their own leave type using the
     * same code) is silently skipped, not overwritten -- re-clicking "Apply Default" after editing
     * some seeded rows only fills in whatever's still missing, it never resets edits back to the
     * default values. This is also why the button stays usable indefinitely, not just once on an
     * empty table.
     */
    private const LEAVE_TYPE_DEFAULTS = [
        ['category_id' => 1, 'code' => 'SICK', 'name_th' => 'ลาป่วย', 'name_en' => 'Sick Leave',
            'quota_amount' => 30, 'is_continuous' => 1, 'is_paid' => 1],
        ['category_id' => 2, 'code' => 'PERSONAL', 'name_th' => 'ลากิจ', 'name_en' => 'Personal Leave',
            'quota_amount' => 3, 'is_paid' => 1],
        ['category_id' => 3, 'code' => 'ANNUAL', 'name_th' => 'ลาพักร้อน', 'name_en' => 'Annual Leave',
            'quota_amount' => 6, 'is_paid' => 1, 'allow_carry_over' => 1, 'min_service_days' => 365],
        ['category_id' => 4, 'code' => 'MATERNITY', 'name_th' => 'ลาคลอดบุตร', 'name_en' => 'Maternity Leave',
            'quota_amount' => 98, 'requires_document' => 1, 'is_continuous' => 1, 'is_paid' => 1, 'gender_restriction' => 'female'],
        ['category_id' => 5, 'code' => 'ORDINATION', 'name_th' => 'ลาบวช', 'name_en' => 'Ordination Leave',
            'quota_amount' => 15, 'is_continuous' => 1, 'is_paid' => 0, 'gender_restriction' => 'male', 'min_service_days' => 365],
        ['category_id' => 6, 'code' => 'MILITARY', 'name_th' => 'ลาราชการทหาร', 'name_en' => 'Military Leave',
            'quota_amount' => 60, 'requires_document' => 1, 'is_continuous' => 1, 'is_paid' => 1, 'gender_restriction' => 'male'],
        ['category_id' => 7, 'code' => 'LWOP', 'name_th' => 'ลาโดยไม่รับค่าจ้าง', 'name_en' => 'Leave Without Pay',
            'quota_amount' => 0, 'is_paid' => 0, 'advance_notice_days' => 7],
        ['category_id' => 8, 'code' => 'FAMILY', 'name_th' => 'ลาเพื่อดูแลครอบครัว/บุตร', 'name_en' => 'Family/Parental Leave',
            'quota_amount' => 15, 'is_paid' => 0],
        ['category_id' => 9, 'code' => 'EMERGENCY', 'name_th' => 'ลาฉุกเฉิน', 'name_en' => 'Emergency Leave',
            'quota_amount' => 3, 'is_paid' => 1, 'advance_notice_days' => 0],
        ['category_id' => 10, 'code' => 'OTHER', 'name_th' => 'ลาอื่นๆ', 'name_en' => 'Other Leave',
            'quota_amount' => 0, 'is_paid' => 0],
    ];

    public function leaveTypeApplyDefaults(int $compId, int $userId): array {
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        $created = 0;
        $skipped = 0;
        try {
            foreach (self::LEAVE_TYPE_DEFAULTS as $defaults) {
                $result = $this->leaveTypeSave($defaults, $compId, $userId);
                if ($result['status']) {
                    $created++;
                } else {
                    $skipped++;
                }
            }
            if ($ownTransaction) {
                $this->db->commit();
            }
            return ['status' => true, 'created' => $created, 'skipped' => $skipped];
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /* ==================== OT RATE ====================
     * 2026-08-30: the flat one-row-per-scope CRUD that used to live here (otRateList/otRateGet/
     * otRateSave/otRateDelete/otRateToggleStatus/otRatePreview, reading/writing the now-retired
     * `ot_rates` table) has been fully replaced by OtRateSetModel -- see that class's own docblock.
     * `SetupRulesController`'s otRate* endpoints now delegate there instead of here. Only the master
     * scope-type lookup stays in this model (shared with every other OT-scope consumer in the app).
     */

    public function otScopeOptions(): array {
        $stmt = $this->db->query("SELECT id, name_th AS text_th, name_en AS text_en FROM master_ot_scope_types WHERE is_active = 1 ORDER BY sort_order ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
