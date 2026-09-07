<?php
declare(strict_types=1);

class DashboardModel {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }

    public function currentEmployee(int $employeeId, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT e.id, e.name_th, e.surname_th, e.name_en, e.surname_en,
                p.position_name_th, p.position_name_en
            FROM `employees` e
            LEFT JOIN `structure_positions` p ON p.id = e.position_id
            WHERE e.id = :id AND e.comp_id = :comp_id AND e.deleted_at IS NULL");
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Active headcount + hires within the given calendar month (defaults to the current month when
     * $monthStart is omitted). 2026-09-06, explicit request: "อยากให้ทุกอย่างที่เป็นไปได้...ปรับตาม" the
     * Dashboard's own month/year picker, including headcount -- for the CURRENT month this still
     * matches `employee_status='active'` in practice, but there is no status-HISTORY table in this
     * app (`employment_status` is a live/current flag, not a point-in-time record), so a PAST
     * month's headcount is approximated the same way `PayrollRunModel::recalculate()`'s own
     * cycle-based eligibility window already does elsewhere in this app: "employed as of that
     * month" = `employment_date` on/before the month's last day AND (`employment_end_date` is
     * still unset OR falls on/after the month's first day). This is a deliberate, documented
     * approximation (an employee whose employment_status flips mid-month for a reason OTHER than
     * employment_date/employment_end_date changing -- e.g. probation->permanent -- isn't reflected
     * retroactively), not a bug -- there's no historical record to reconstruct precisely without a
     * new audit table this task doesn't ask for.
     * `$monthStart` is null for the DEFAULT (no month picked yet) Dashboard view -- runs the
     * EXACT SAME query as before this feature existed (`employee_status='active'` live count),
     * byte-identical behavior, so the page's own default first-impression view never regresses.
     * Only passing a real `'YYYY-MM-01'` (the Dashboard's own month/year picker, explicitly used)
     * switches to the historical approximation above.
     * @param string|null $monthStart 'YYYY-MM-01', or null for the live/current-state default
     */
    public function employeeStats(int $compId, ?string $monthStart = null): array {
        if ($monthStart === null) {
            $stmt = $this->db->prepare("SELECT
                    SUM(CASE WHEN employee_status = 'active' THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN employment_date >= :month_start THEN 1 ELSE 0 END) AS new_this_month
                FROM `employees`
                WHERE comp_id = :comp_id AND deleted_at IS NULL");
            $stmt->execute([':comp_id' => $compId, ':month_start' => date('Y-m-01')]);
            $row = $stmt->fetch();
            return [
                'active_count' => (int)($row['active_count'] ?? 0),
                'new_this_month' => (int)($row['new_this_month'] ?? 0),
            ];
        }
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $stmt = $this->db->prepare("SELECT
                SUM(CASE WHEN employment_date <= :month_end AND (employment_end_date IS NULL OR employment_end_date >= :month_start) THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN employment_date BETWEEN :month_start AND :month_end THEN 1 ELSE 0 END) AS new_this_month
            FROM `employees`
            WHERE comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':comp_id' => $compId, ':month_start' => $monthStart, ':month_end' => $monthEnd]);
        $row = $stmt->fetch();
        return [
            'active_count' => (int)($row['active_count'] ?? 0),
            'new_this_month' => (int)($row['new_this_month'] ?? 0),
        ];
    }

    /**
     * 2026-09-06, Dashboard redesign, explicit request: "กราฟที่สามารถเพิ่มได้...แต่ไม่ดูยัดเยียดเกินไป" --
     * one additional, restrained chart: headcount by department. Shares the EXACT SAME live-vs-
     * historical duality as employeeStats() (null = today's live `employee_status='active'`,
     * a real month = the same employment_date/employment_end_date window approximation) so the two
     * headcount figures (the stat card and this breakdown) can never disagree about which employees
     * count for a given month selection. `department_id IS NULL` employees group under one
     * "Unspecified" bucket rather than being silently dropped from the chart.
     * @return array<int,array{department_name_th:string,department_name_en:string,count:int}> descending by count
     */
    public function departmentHeadcount(int $compId, ?string $monthStart = null): array {
        $params = [':comp_id' => $compId];
        if ($monthStart === null) {
            $activeClause = "e.employee_status = 'active'";
        } else {
            $monthEnd = date('Y-m-t', strtotime($monthStart));
            $activeClause = "e.employment_date <= :month_end AND (e.employment_end_date IS NULL OR e.employment_end_date >= :month_start)";
            $params[':month_start'] = $monthStart;
            $params[':month_end'] = $monthEnd;
        }
        $stmt = $this->db->prepare("SELECT COALESCE(d.department_name_th, 'ไม่ระบุแผนก') AS department_name_th,
                COALESCE(d.department_name_en, 'Unspecified') AS department_name_en, COUNT(*) AS count
            FROM `employees` e
            LEFT JOIN `structure_departments` d ON d.id = e.department_id AND d.deleted_at IS NULL
            WHERE e.comp_id = :comp_id AND e.deleted_at IS NULL AND ({$activeClause})
            GROUP BY d.id
            ORDER BY count DESC");
        $stmt->execute($params);
        return array_map(fn($row) => [
            'department_name_th' => (string)$row['department_name_th'],
            'department_name_en' => (string)$row['department_name_en'],
            'count' => (int)$row['count'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * 2026-09-06, Dashboard redesign: Calendar widget events for one calendar month, combining the
     * 3 sources confirmed via AskUserQuestion (payroll cutoff/payment dates + holidays +
     * probation/internship end dates). Deliberately company-wide/unscoped for holidays (not
     * resolved per-employee via SetupRulesModel::resolveHolidaysForEmployee()'s own scope-priority
     * engine) -- this is a general "what's happening this month" overview widget, not a personal
     * attendance tool, so a plain company-wide holiday list is the right level of detail (same
     * simplification precedent as this app's other "overview, not per-employee" dashboard widgets).
     * @return array<int,array{date:string,type:string,label:string}>
     */
    public function calendarEvents(int $compId, int $year, int $month): array {
        $monthStart = sprintf('%04d-%02d-01', $year, $month);
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $events = [];

        $stmtHolidays = $this->db->prepare("SELECT holiday_date, name_th, name_en FROM holidays
            WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL
              AND holiday_date BETWEEN :month_start AND :month_end");
        $stmtHolidays->execute([':comp_id' => $compId, ':month_start' => $monthStart, ':month_end' => $monthEnd]);
        foreach ($stmtHolidays->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $events[] = ['date' => $h['holiday_date'], 'type' => 'holiday', 'label_th' => $h['name_th'], 'label_en' => $h['name_en']];
        }

        // Payroll cutoff (period_end_date) + payment (payment_date) dates -- from REAL payroll_runs
        // rows, not a hypothetical/computed next-occurrence guess, so the calendar only ever shows
        // dates that genuinely correspond to a run that exists (cancelled runs excluded, same
        // "never real disbursement" convention every other report/summary in this app already uses).
        $stmtRuns = $this->db->prepare("SELECT id, run_name, run_code, state, period_end_date, payment_date FROM payroll_runs
            WHERE comp_id = :comp_id AND deleted_at IS NULL AND state != 'cancelled'
              AND (period_end_date BETWEEN :month_start AND :month_end OR payment_date BETWEEN :month_start AND :month_end)");
        $stmtRuns->execute([':comp_id' => $compId, ':month_start' => $monthStart, ':month_end' => $monthEnd]);
        foreach ($stmtRuns->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $label = $r['run_code'] ?: $r['run_name'];
            if ($r['period_end_date'] >= $monthStart && $r['period_end_date'] <= $monthEnd) {
                $events[] = ['date' => $r['period_end_date'], 'type' => 'payroll_cutoff', 'label_th' => $label, 'label_en' => $label, 'run_id' => (int)$r['id']];
            }
            if ($r['payment_date'] >= $monthStart && $r['payment_date'] <= $monthEnd) {
                $events[] = ['date' => $r['payment_date'], 'type' => 'payroll_payment', 'label_th' => $label, 'label_en' => $label, 'run_id' => (int)$r['id']];
            }
        }

        require_once __DIR__ . '/NotificationModel.php';
        foreach ((new NotificationModel())->probationInternEndingInMonth($compId, $year, $month) as $e) {
            $name = trim($e['name_th'] ?: $e['name_en']);
            $nameEn = trim($e['name_en'] ?: $e['name_th']);
            $kindLabel = $e['kind'] === 'internship' ? ['th' => 'สิ้นสุดฝึกงาน', 'en' => 'Internship ends'] : ['th' => 'สิ้นสุดทดลองงาน', 'en' => 'Probation ends'];
            $events[] = [
                'date' => $e['expiry_date'], 'type' => $e['kind'] === 'internship' ? 'internship_end' : 'probation_end',
                'label_th' => "{$kindLabel['th']}: {$name}", 'label_en' => "{$kindLabel['en']}: {$nameEn}",
                'employee_id' => $e['employee_id'],
            ];
        }

        usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));
        return $events;
    }
}
