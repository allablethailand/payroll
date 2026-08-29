<?php
declare(strict_types=1);

/**
 * 2026-08-29, explicit request: "ต้องการอีกหน้าคล้ายๆหน้าของ Employee เป็นข้อมูลสรุปรอบตามปี...คือสรุป
 * รายได้รายหักเงินได้สุทธิของพนักงานแต่ละคน สรุปเป็นเดือนๆไป และสรุปรายได้ทั้งปี" -- per-employee, month-by-
 * month net pay summary for a company-configurable fiscal year (`companies.fiscal_year_start_month`,
 * 1=January default = plain calendar year). Pure read/aggregation over `payroll_runs`/
 * `payroll_run_details` -- no new storage, same source every other report already reads from.
 *
 * "Fiscal year label" Y means the 12-month window from {fiscal_year_start_month}/Y through
 * ({fiscal_year_start_month}-1)/(Y+1) -- e.g. with fiscal_year_start_month=4 (April), label 2026
 * spans April 2026 through March 2027. With the default (1/January), label 2026 is a plain
 * calendar year, Jan-Dec 2026.
 *
 * Only runs in ALLOWED_STATES (approved/paid/locked -- same "finalized, not still-editable" gate
 * every other report in this app already uses) ever contribute to a month's totals. An employee
 * only appears in the report if they have at least one such run within the selected fiscal year --
 * this deliberately keeps the report to "people who actually got paid this year" rather than every
 * employee the company has ever had, which would clutter it with all-blank rows.
 */
class AnnualIncomeSummaryModel {
    private PDO $db;
    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    private function fiscalYearBounds(int $fiscalYear, int $fiscalStartMonth): array {
        $start = sprintf('%04d-%02d-01', $fiscalYear, $fiscalStartMonth);
        $endYear = $fiscalStartMonth === 1 ? $fiscalYear : $fiscalYear + 1;
        $endMonth = $fiscalStartMonth === 1 ? 12 : $fiscalStartMonth - 1;
        $end = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $endYear, $endMonth)));
        return [$start, $end];
    }

    /** The 12 (year, month) pairs belonging to fiscal year label $fiscalYear, in chronological order. */
    private function monthsInFiscalYear(int $fiscalYear, int $fiscalStartMonth): array {
        $months = [];
        $y = $fiscalYear;
        $m = $fiscalStartMonth;
        for ($i = 0; $i < 12; $i++) {
            $months[] = ['year' => $y, 'month' => $m];
            $m++;
            if ($m > 12) {
                $m = 1;
                $y++;
            }
        }
        return $months;
    }

    /** Distinct fiscal-year labels that have at least one finalized run, newest first. */
    public function availableFiscalYears(int $compId, int $fiscalStartMonth): array {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT YEAR(period_start_date) AS y, MONTH(period_start_date) AS m
             FROM payroll_runs
             WHERE comp_id = :comp_id AND state IN ('approved','paid','locked') AND status = 'active' AND deleted_at IS NULL"
        );
        $stmt->execute([':comp_id' => $compId]);
        $labels = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $y = (int)$row['y'];
            $m = (int)$row['m'];
            $label = $m >= $fiscalStartMonth ? $y : $y - 1;
            $labels[$label] = true;
        }
        $result = array_keys($labels);
        rsort($result);
        return $result;
    }

    /**
     * @param array $filters { department_id?, team_id?, branch_id?, role_id?, employee_status?, search? }
     * @return array{months: array, employees: array, totals: array}
     */
    public function summary(int $compId, int $fiscalYear, int $fiscalStartMonth, array $filters = []): array {
        [$fyStart, $fyEnd] = $this->fiscalYearBounds($fiscalYear, $fiscalStartMonth);
        $monthDefs = $this->monthsInFiscalYear($fiscalYear, $fiscalStartMonth);

        // ---------- per-employee, per-month aggregation ----------
        $placeholders = implode(',', array_fill(0, count(self::ALLOWED_STATES), '?'));
        $stmtAgg = $this->db->prepare(
            "SELECT d.employee_id, YEAR(r.period_start_date) AS y, MONTH(r.period_start_date) AS m,
                    SUM(d.gross_amount) AS gross, SUM(d.total_deduction_amount) AS deduction, SUM(d.net_amount) AS net
             FROM payroll_run_details d
             INNER JOIN payroll_runs r ON r.id = d.run_id
             WHERE r.comp_id = ? AND r.status = 'active' AND r.deleted_at IS NULL
               AND r.state IN ({$placeholders})
               AND r.period_start_date >= ? AND r.period_start_date <= ?
             GROUP BY d.employee_id, y, m"
        );
        $stmtAgg->execute(array_merge([$compId], self::ALLOWED_STATES, [$fyStart, $fyEnd]));
        $byEmployee = [];
        foreach ($stmtAgg->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $empId = (int)$row['employee_id'];
            $key = $row['y'] . '-' . $row['m'];
            if (!isset($byEmployee[$empId])) {
                $byEmployee[$empId] = [];
            }
            $byEmployee[$empId][$key] = [
                'gross' => (float)$row['gross'], 'deduction' => (float)$row['deduction'], 'net' => (float)$row['net'],
            ];
        }

        // ---------- employee identity + filters (same filter columns Employee List uses) ----------
        // 2026-08-29, explicit follow-up: "สามารถดึงพนักงานทั้งหมดเลยได้ไหมครับ" -- every employee
        // matching the filters is listed, not just the ones with at least one payroll run this
        // fiscal year (that used to be the whole employee-source query, driven off $byEmployee's
        // own keys) -- an employee with no data that year just renders as an all-zero row/-'s.
        $where = 'e.comp_id = ? AND e.deleted_at IS NULL';
        $params = [$compId];
        if (!empty($filters['department_id'])) { $where .= ' AND e.department_id = ?'; $params[] = (int)$filters['department_id']; }
        if (!empty($filters['team_id'])) { $where .= ' AND e.team_id = ?'; $params[] = (int)$filters['team_id']; }
        if (!empty($filters['branch_id'])) { $where .= ' AND e.branch_id = ?'; $params[] = (int)$filters['branch_id']; }
        if (!empty($filters['role_id'])) { $where .= ' AND e.role_id = ?'; $params[] = (int)$filters['role_id']; }
        if (!empty($filters['employee_status'])) { $where .= ' AND e.employee_status = ?'; $params[] = (string)$filters['employee_status']; }
        if (!empty($filters['search'])) {
            $where .= ' AND (e.employee_no LIKE ? OR e.name_th LIKE ? OR e.surname_th LIKE ? OR e.name_en LIKE ? OR e.surname_en LIKE ?)';
            $term = '%' . $filters['search'] . '%';
            array_push($params, $term, $term, $term, $term, $term);
        }
        $stmtEmp = $this->db->prepare(
            "SELECT e.id, e.employee_no, e.name_th, e.surname_th, e.name_en, e.surname_en, e.employee_status,
                    dep.department_name_th, dep.department_name_en
             FROM employees e
             LEFT JOIN structure_departments dep ON dep.id = e.department_id
             WHERE {$where}
             ORDER BY e.employee_no ASC"
        );
        $stmtEmp->execute($params);
        $employeeRows = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

        $employees = [];
        $totals = $this->emptyTotals($monthDefs);
        foreach ($employeeRows as $emp) {
            $empId = (int)$emp['id'];
            $months = [];
            $annualGross = 0.0;
            $annualDeduction = 0.0;
            $annualNet = 0.0;
            foreach ($monthDefs as $md) {
                $key = $md['year'] . '-' . $md['month'];
                $cell = $byEmployee[$empId][$key] ?? ['gross' => 0.0, 'deduction' => 0.0, 'net' => 0.0];
                $months[] = $cell;
                $annualGross += $cell['gross'];
                $annualDeduction += $cell['deduction'];
                $annualNet += $cell['net'];
                $totals['months'][$md['year'] . '-' . $md['month']]['gross'] += $cell['gross'];
                $totals['months'][$md['year'] . '-' . $md['month']]['deduction'] += $cell['deduction'];
                $totals['months'][$md['year'] . '-' . $md['month']]['net'] += $cell['net'];
            }
            $employees[] = [
                'employee_id' => $empId,
                'employee_no' => $emp['employee_no'],
                'name_th' => trim(($emp['name_th'] ?? '') . ' ' . ($emp['surname_th'] ?? '')),
                'name_en' => trim(($emp['name_en'] ?? '') . ' ' . ($emp['surname_en'] ?? '')),
                'employee_status' => $emp['employee_status'],
                'department_name_th' => $emp['department_name_th'],
                'department_name_en' => $emp['department_name_en'],
                'months' => $months,
                'annual_gross' => $annualGross,
                'annual_deduction' => $annualDeduction,
                'annual_net' => $annualNet,
            ];
            $totals['annual_gross'] += $annualGross;
            $totals['annual_deduction'] += $annualDeduction;
            $totals['annual_net'] += $annualNet;
            $totals['employee_count']++;
        }

        return [
            'months' => $this->buildMonthMeta($monthDefs, $compId, $fyStart, $fyEnd),
            'employees' => $employees,
            'totals' => $totals,
        ];
    }

    private function emptyTotals(array $monthDefs): array {
        $months = [];
        foreach ($monthDefs as $md) {
            $months[$md['year'] . '-' . $md['month']] = ['gross' => 0.0, 'deduction' => 0.0, 'net' => 0.0];
        }
        return ['months' => $months, 'annual_gross' => 0.0, 'annual_deduction' => 0.0, 'annual_net' => 0.0, 'employee_count' => 0];
    }

    /** Per-month header metadata: label + a 'state' the frontend colors by -- 'past_done' (a
     *  finalized run exists company-wide that month), 'past_missing' (past month, zero finalized
     *  runs at all -- likely a gap worth flagging), 'current', or 'future'. Company-wide (not
     *  per-employee) since "ทำ/ไม่ได้ทำ" reads as "was payroll processed that month at all", not
     *  "did this one person get paid". */
    private function buildMonthMeta(array $monthDefs, int $compId, string $fyStart, string $fyEnd): array {
        $placeholders = implode(',', array_fill(0, count(self::ALLOWED_STATES), '?'));
        $stmt = $this->db->prepare(
            "SELECT DISTINCT YEAR(period_start_date) AS y, MONTH(period_start_date) AS m
             FROM payroll_runs
             WHERE comp_id = ? AND status = 'active' AND deleted_at IS NULL AND state IN ({$placeholders})
               AND period_start_date >= ? AND period_start_date <= ?"
        );
        $stmt->execute(array_merge([$compId], self::ALLOWED_STATES, [$fyStart, $fyEnd]));
        $done = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $done[$row['y'] . '-' . $row['m']] = true;
        }

        $todayYear = (int)date('Y');
        $todayMonth = (int)date('n');
        $out = [];
        foreach ($monthDefs as $md) {
            $key = $md['year'] . '-' . $md['month'];
            $cmp = ($md['year'] <=> $todayYear) ?: ($md['month'] <=> $todayMonth);
            if ($cmp === 0) {
                $state = 'current';
            } elseif ($cmp > 0) {
                $state = 'future';
            } else {
                $state = isset($done[$key]) ? 'past_done' : 'past_missing';
            }
            $out[] = ['year' => $md['year'], 'month' => $md['month'], 'key' => $key, 'state' => $state];
        }
        return $out;
    }
}
