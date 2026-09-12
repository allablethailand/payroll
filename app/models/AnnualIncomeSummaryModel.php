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

    /**
     * 2026-09-12, Batch 5 item 5 step 2 -- ONE shared run-side filter fragment (currently just
     * cycle_id, "รอบเงินเดือน") appended to every query in this class that reads payroll_runs/
     * payroll_run_details (summary()'s own stmtAgg, cellDetail(), rawDeductionRows(),
     * buildMonthMeta()) -- a new run-level filter is written HERE once, never copied into each
     * query's own WHERE by hand. Returns a SQL fragment starting with "AND ..." (empty string when
     * no run-level filter is active) plus the positional params it needs, in the order they appear
     * in that fragment -- callers append the fragment wherever their own WHERE ends (before GROUP
     * BY/ORDER BY if either is present) and append the params at the matching position in their own
     * execute() array.
     * @return array{0: string, 1: array}
     */
    private function runFilterClause(array $filters): array {
        if (empty($filters['cycle_id'])) {
            return ['', []];
        }
        return [' AND r.cycle_id = ?', [(int)$filters['cycle_id']]];
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

    /**
     * 2026-09-10, real bug found and fixed (explicit report: a run whose pay period spans two
     * calendar months, e.g. 26/07-25/08 paid 31/08, was being bucketed into July's fiscal-year/
     * month column throughout this whole page instead of August's -- the actual cash outflow, and
     * the month PIT was actually withheld, happens on payment_date, not period_start_date). Every
     * date-grouping query in this class below switched from period_start_date to payment_date, same
     * fix/reasoning as PayrollReportDataModel's own identical same-day fix (see that class's own
     * docblock) -- this class is the Annual Income Summary / Annual PIT Summary / Monthly PIT Detail
     * page's ENTIRE data source, so this is the single biggest-blast-radius instance of this bug in
     * the app. period_start_date/period_end_date are still returned as plain DISPLAY columns
     * (cellDetail()) where showing the actual pay period alongside the payment month is useful --
     * only the grouping/filtering key changed.
     */

    /** Distinct fiscal-year labels that have at least one finalized run, newest first. */
    public function availableFiscalYears(int $compId, int $fiscalStartMonth): array {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT YEAR(payment_date) AS y, MONTH(payment_date) AS m
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
        [$cycleSql, $cycleParams] = $this->runFilterClause($filters);
        $placeholders = implode(',', array_fill(0, count(self::ALLOWED_STATES), '?'));
        $stmtAgg = $this->db->prepare(
            "SELECT d.employee_id, YEAR(r.payment_date) AS y, MONTH(r.payment_date) AS m,
                    SUM(d.gross_amount) AS gross, SUM(d.total_deduction_amount) AS deduction, SUM(d.net_amount) AS net
             FROM payroll_run_details d
             INNER JOIN payroll_runs r ON r.id = d.run_id
             WHERE r.comp_id = ? AND r.status = 'active' AND r.deleted_at IS NULL
               AND r.state IN ({$placeholders})
               AND r.payment_date >= ? AND r.payment_date <= ?{$cycleSql}
             GROUP BY d.employee_id, y, m"
        );
        $stmtAgg->execute(array_merge([$compId], self::ALLOWED_STATES, [$fyStart, $fyEnd], $cycleParams));
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

        $employeeRows = $this->employeeRowsForFilters($compId, $filters);

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
                'profile_photo_path' => $emp['profile_photo_path'],
                'department_name_th' => $emp['department_name_th'],
                'department_name_en' => $emp['department_name_en'],
                'team_name_th' => $emp['team_name_th'],
                'team_name_en' => $emp['team_name_en'],
                'position_name_th' => $emp['position_name_th'],
                'position_name_en' => $emp['position_name_en'],
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
            'months' => $this->buildMonthMeta($monthDefs, $compId, $fyStart, $fyEnd, $filters),
            'employees' => $employees,
            'totals' => $totals,
        ];
    }

    /**
     * 2026-08-30, explicit request: "ในแต่ละช่องถ้ามีข้อมูลให้สามารถกดดู Detail ได้ด้วยครับ" -- a month
     * cell's own line-item breakdown (base salary + every earning/deduction/statutory item that
     * made up that gross/deduction/net figure), backing a click-to-drill-down on the table. More
     * than one run can genuinely land in the same calendar month (a regular run plus an off-cycle
     * incentive run, say) -- returns one entry PER RUN found, not a single flattened total, so the
     * modal can show "which run contributed what" rather than silently merging them.
     * @param array $filters { cycle_id?: int } -- same runFilterClause() as every other query in
     *        this class; the cell click passes through whatever cycle filter the table itself is
     *        currently scoped to, so a drill-down never shows a run the table itself has filtered out.
     */
    public function cellDetail(int $compId, int $employeeId, int $year, int $month, array $filters = []): array {
        [$cycleSql, $cycleParams] = $this->runFilterClause($filters);
        $placeholders = implode(',', array_fill(0, count(self::ALLOWED_STATES), '?'));
        $stmt = $this->db->prepare(
            "SELECT d.base_salary_amount, d.earning_breakdown, d.deduction_breakdown, d.statutory_breakdown,
                    d.gross_amount, d.total_deduction_amount, d.net_amount,
                    r.id AS run_id, r.run_name, r.period_start_date, r.period_end_date, r.payment_date, r.run_purpose
             FROM payroll_run_details d
             INNER JOIN payroll_runs r ON r.id = d.run_id
             WHERE r.comp_id = ? AND d.employee_id = ? AND r.status = 'active' AND r.deleted_at IS NULL
               AND r.state IN ({$placeholders})
               AND YEAR(r.payment_date) = ? AND MONTH(r.payment_date) = ?{$cycleSql}
             ORDER BY r.payment_date ASC"
        );
        $stmt->execute(array_merge([$compId, $employeeId], self::ALLOWED_STATES, [$year, $month], $cycleParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $runs = [];
        foreach ($rows as $row) {
            $runs[] = [
                'run_id' => (int)$row['run_id'],
                'run_name' => $row['run_name'],
                'run_purpose' => $row['run_purpose'],
                'period_start_date' => $row['period_start_date'],
                'period_end_date' => $row['period_end_date'],
                'payment_date' => $row['payment_date'],
                'base_salary_amount' => (float)$row['base_salary_amount'],
                'earning_lines' => json_decode((string)$row['earning_breakdown'], true) ?? [],
                'deduction_lines' => json_decode((string)$row['deduction_breakdown'], true) ?? [],
                'statutory_lines' => json_decode((string)$row['statutory_breakdown'], true) ?? [],
                'gross_amount' => (float)$row['gross_amount'],
                'total_deduction_amount' => (float)$row['total_deduction_amount'],
                'net_amount' => (float)$row['net_amount'],
            ];
        }
        return $runs;
    }

    /**
     * Employee identity + station filters (same filter columns Employee List uses) -- shared by
     * summary(), annualPitSummary(), and monthlyPitDetail() below, extracted out of summary()'s own
     * original inline query (Phase 4, T026/T027) so all 3 tabs of the combined page use identical
     * eligibility rules. Every matching employee is listed regardless of whether they have any
     * payroll data in the requested period (an employee with none just renders as an all-zero/'-'
     * row) -- staff-only employees (is_payroll_participant=0) are excluded outright (Phase 3, T021).
     */
    private function employeeRowsForFilters(int $compId, array $filters): array {
        $where = 'e.comp_id = ? AND e.deleted_at IS NULL AND e.is_payroll_participant = 1';
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
                    e.profile_photo_path,
                    dep.department_name_th, dep.department_name_en,
                    tm.team_name_th, tm.team_name_en,
                    p.position_name_th, p.position_name_en
             FROM employees e
             LEFT JOIN structure_departments dep ON dep.id = e.department_id
             LEFT JOIN structure_teams tm ON tm.id = e.team_id
             LEFT JOIN structure_positions p ON p.id = e.position_id
             WHERE {$where}
             ORDER BY e.employee_no ASC"
        );
        $stmtEmp->execute($params);
        return $stmtEmp->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Distinct plain calendar years (not fiscal-year labels) with at least one finalized run --
     *  backs the Monthly PIT tab's (T026) own year picker, which deliberately uses a plain
     *  calendar year + month selection rather than the fiscal-year abstraction the other 2 tabs
     *  use: "which month" is naturally a calendar concept, same framing PndOneReport/
     *  PndOneKorSummaryReport already use for their own period selection. */
    public function availableCalendarYears(int $compId): array {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT YEAR(payment_date) AS y FROM payroll_runs
             WHERE comp_id = :comp_id AND state IN ('approved','paid','locked') AND status = 'active' AND deleted_at IS NULL
             ORDER BY y DESC"
        );
        $stmt->execute([':comp_id' => $compId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * 2026-09-10, Batch 2 item 6 follow-up (explicit request: "รวม annualPitSummary()/rawPitRows()
     * กับ annualSsoSummary()/rawSsoRows() เป็นฟังก์ชันเดียวรับ parameter item code") -- generic
     * per-employee-per-run-month rows within a date range, parameterized by which statutory_code
     * to extract from `statutory_breakdown` (JSON, can't be summed in portable SQL the way gross/
     * deduction/net can). Same ALLOWED_STATES gate as summary()/cellDetail() -- satisfies T029
     * ("ทุก Report ใหม่ต้องเช็คเงื่อนไขอนุมัติ/ปิดรอบ") by construction, since every caller inherits the
     * same gate rather than needing its own. Output row carries the summed amount under the neutral
     * key 'amount' -- callers that need a specific key name (e.g. monthlyPitDetail() below, via
     * rawPitRows()'s own thin-wrapper rename) remap it themselves, so this shared method's own
     * output shape never has to change to fit a second caller's naming.
     * @param array $filters { cycle_id?: int } -- runFilterClause(), same as every other query here.
     */
    private function rawDeductionRows(string $statutoryCode, int $compId, string $dateFrom, string $dateTo, array $filters = []): array {
        [$cycleSql, $cycleParams] = $this->runFilterClause($filters);
        $placeholders = implode(',', array_fill(0, count(self::ALLOWED_STATES), '?'));
        $stmt = $this->db->prepare(
            "SELECT d.employee_id, YEAR(r.payment_date) AS y, MONTH(r.payment_date) AS m,
                    d.gross_amount, d.total_deduction_amount, d.net_amount, d.statutory_breakdown
             FROM payroll_run_details d
             INNER JOIN payroll_runs r ON r.id = d.run_id
             WHERE r.comp_id = ? AND r.status = 'active' AND r.deleted_at IS NULL
               AND r.state IN ({$placeholders})
               AND r.payment_date >= ? AND r.payment_date <= ?{$cycleSql}"
        );
        $stmt->execute(array_merge([$compId], self::ALLOWED_STATES, [$dateFrom, $dateTo], $cycleParams));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $amount = 0.0;
            foreach (json_decode((string)$row['statutory_breakdown'], true) ?? [] as $item) {
                if (($item['code'] ?? null) === $statutoryCode) {
                    $amount += (float)($item['employee_amount'] ?? 0);
                }
            }
            $row['amount'] = $amount;
        }
        unset($row);
        return $rows;
    }

    /** Thin wrapper over rawDeductionRows('TH_PIT', ...) that renames the neutral 'amount' key back
     *  to 'tax_withheld' -- monthlyPitDetail() below (this method's OTHER caller, not just
     *  annualPitSummary()) reads that exact key name, so it stays untouched by this consolidation. */
    private function rawPitRows(int $compId, string $dateFrom, string $dateTo, array $filters = []): array {
        $rows = $this->rawDeductionRows('TH_PIT', $compId, $dateFrom, $dateTo, $filters);
        foreach ($rows as &$row) {
            $row['tax_withheld'] = $row['amount'];
        }
        unset($row);
        return $rows;
    }

    /**
     * 2026-09-10, Batch 2 item 6 follow-up -- generic annual per-statutory-code grid, same shape as
     * summary() (per-employee, per-month, + annual total + company-wide totals row) but tracking one
     * statutory_breakdown line's employee_amount instead of gross/deduction/net. Same fiscal-year
     * concept (fiscal_year_start_month from Company Profile, T028) as the Annual Income Summary tab
     * every one of these grids is paired with. $amountField controls the output key name on each
     * employee row and on `totals` (e.g. 'annual_tax_withheld'/'annual_sso_amount') so
     * annualPitSummary()/annualSsoSummary() below can each keep their own already-shipped shape
     * (frontend/JS reads those exact keys) without this shared method dictating one fixed name.
     * @param array $filters { department_id?, team_id?, branch_id?, role_id?, employee_status?, search? }
     * @return array{months: array, employees: array, totals: array}
     */
    private function annualDeductionSummary(string $statutoryCode, string $amountField, int $compId, int $fiscalYear, int $fiscalStartMonth, array $filters = []): array {
        [$fyStart, $fyEnd] = $this->fiscalYearBounds($fiscalYear, $fiscalStartMonth);
        $monthDefs = $this->monthsInFiscalYear($fiscalYear, $fiscalStartMonth);

        $byEmployee = [];
        foreach ($this->rawDeductionRows($statutoryCode, $compId, $fyStart, $fyEnd, $filters) as $row) {
            $empId = (int)$row['employee_id'];
            $key = $row['y'] . '-' . $row['m'];
            $byEmployee[$empId][$key] = ($byEmployee[$empId][$key] ?? 0.0) + (float)$row['amount'];
        }

        $employeeRows = $this->employeeRowsForFilters($compId, $filters);
        $employees = [];
        $totalsMonths = [];
        foreach ($monthDefs as $md) { $totalsMonths[$md['year'] . '-' . $md['month']] = 0.0; }
        $annualTotal = 0.0;
        $employeeCount = 0;
        foreach ($employeeRows as $emp) {
            $empId = (int)$emp['id'];
            $months = [];
            $annualAmount = 0.0;
            foreach ($monthDefs as $md) {
                $key = $md['year'] . '-' . $md['month'];
                $val = $byEmployee[$empId][$key] ?? 0.0;
                $months[] = $val;
                $annualAmount += $val;
                $totalsMonths[$key] += $val;
            }
            $employees[] = [
                'employee_id' => $empId,
                'employee_no' => $emp['employee_no'],
                'name_th' => trim(($emp['name_th'] ?? '') . ' ' . ($emp['surname_th'] ?? '')),
                'name_en' => trim(($emp['name_en'] ?? '') . ' ' . ($emp['surname_en'] ?? '')),
                'employee_status' => $emp['employee_status'],
                'profile_photo_path' => $emp['profile_photo_path'],
                'department_name_th' => $emp['department_name_th'],
                'department_name_en' => $emp['department_name_en'],
                'team_name_th' => $emp['team_name_th'],
                'team_name_en' => $emp['team_name_en'],
                'position_name_th' => $emp['position_name_th'],
                'position_name_en' => $emp['position_name_en'],
                'months' => $months,
                $amountField => $annualAmount,
            ];
            $annualTotal += $annualAmount;
            $employeeCount++;
        }

        return [
            'months' => $this->buildMonthMeta($monthDefs, $compId, $fyStart, $fyEnd, $filters),
            'employees' => $employees,
            'totals' => ['months' => $totalsMonths, $amountField => $annualTotal, 'employee_count' => $employeeCount],
        ];
    }

    /** Phase 4, T027 -- annual PIT-withheld grid. Thin wrapper over annualDeductionSummary() above;
     *  return shape/key names ('annual_tax_withheld') unchanged from before the consolidation. */
    public function annualPitSummary(int $compId, int $fiscalYear, int $fiscalStartMonth, array $filters = []): array {
        return $this->annualDeductionSummary('TH_PIT', 'annual_tax_withheld', $compId, $fiscalYear, $fiscalStartMonth, $filters);
    }

    /** Batch 2, item 6 -- annual SSO-contribution grid (employee_amount only, confirmed via
     *  AskUserQuestion). Thin wrapper over annualDeductionSummary() above; return shape/key names
     *  ('annual_sso_amount') unchanged from before the consolidation. */
    public function annualSsoSummary(int $compId, int $fiscalYear, int $fiscalStartMonth, array $filters = []): array {
        return $this->annualDeductionSummary('TH_SSO', 'annual_sso_amount', $compId, $fiscalYear, $fiscalStartMonth, $filters);
    }

    /**
     * Phase 4, T026 -- detailed PIT breakdown for ONE specific calendar month (not a fiscal year) --
     * income/deductions/net (same shape summary()'s own per-month cell already has) PLUS tax
     * withheld, per employee, for the selected month. Multiple runs landing in the same month
     * (a regular run plus an off-cycle incentive run) are summed per employee, not listed
     * separately -- per-run detail is still available via cellDetail() if needed (same drill-down
     * modal reused by all 3 tabs).
     * @param array $filters same shape as summary()/annualPitSummary()
     * @return array{employees: array, totals: array}
     */
    public function monthlyPitDetail(int $compId, int $year, int $month, array $filters = []): array {
        $dateFrom = sprintf('%04d-%02d-01', $year, $month);
        $dateTo = date('Y-m-t', strtotime($dateFrom));

        $byEmployee = [];
        foreach ($this->rawPitRows($compId, $dateFrom, $dateTo, $filters) as $row) {
            $empId = (int)$row['employee_id'];
            if (!isset($byEmployee[$empId])) {
                $byEmployee[$empId] = ['gross' => 0.0, 'deduction' => 0.0, 'net' => 0.0, 'tax_withheld' => 0.0];
            }
            $byEmployee[$empId]['gross'] += (float)$row['gross_amount'];
            $byEmployee[$empId]['deduction'] += (float)$row['total_deduction_amount'];
            $byEmployee[$empId]['net'] += (float)$row['net_amount'];
            $byEmployee[$empId]['tax_withheld'] += (float)$row['tax_withheld'];
        }

        $employeeRows = $this->employeeRowsForFilters($compId, $filters);
        $employees = [];
        $totals = ['gross' => 0.0, 'deduction' => 0.0, 'net' => 0.0, 'tax_withheld' => 0.0, 'employee_count' => 0];
        foreach ($employeeRows as $emp) {
            $empId = (int)$emp['id'];
            $cell = $byEmployee[$empId] ?? ['gross' => 0.0, 'deduction' => 0.0, 'net' => 0.0, 'tax_withheld' => 0.0];
            // Skip employees with genuinely nothing this month -- unlike the 12-month grids above
            // (where an all-zero row still marks "no run for them yet this year"), a flat single-
            // month list is more useful scoped to "who was actually paid this month".
            if ($cell['gross'] <= 0 && $cell['tax_withheld'] <= 0) {
                continue;
            }
            $employees[] = [
                'employee_id' => $empId,
                'employee_no' => $emp['employee_no'],
                'name_th' => trim(($emp['name_th'] ?? '') . ' ' . ($emp['surname_th'] ?? '')),
                'name_en' => trim(($emp['name_en'] ?? '') . ' ' . ($emp['surname_en'] ?? '')),
                'employee_status' => $emp['employee_status'],
                'profile_photo_path' => $emp['profile_photo_path'],
                'department_name_th' => $emp['department_name_th'],
                'department_name_en' => $emp['department_name_en'],
                'team_name_th' => $emp['team_name_th'],
                'team_name_en' => $emp['team_name_en'],
                'position_name_th' => $emp['position_name_th'],
                'position_name_en' => $emp['position_name_en'],
                'gross_amount' => $cell['gross'],
                'total_deduction_amount' => $cell['deduction'],
                'net_amount' => $cell['net'],
                'tax_withheld' => $cell['tax_withheld'],
            ];
            $totals['gross'] += $cell['gross'];
            $totals['deduction'] += $cell['deduction'];
            $totals['net'] += $cell['net'];
            $totals['tax_withheld'] += $cell['tax_withheld'];
            $totals['employee_count']++;
        }

        return ['employees' => $employees, 'totals' => $totals];
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
    private function buildMonthMeta(array $monthDefs, int $compId, string $fyStart, string $fyEnd, array $filters = []): array {
        [$cycleSql, $cycleParams] = $this->runFilterClause($filters);
        $placeholders = implode(',', array_fill(0, count(self::ALLOWED_STATES), '?'));
        $stmt = $this->db->prepare(
            "SELECT DISTINCT YEAR(payment_date) AS y, MONTH(payment_date) AS m
             FROM payroll_runs AS r
             WHERE comp_id = ? AND status = 'active' AND deleted_at IS NULL AND state IN ({$placeholders})
               AND payment_date >= ? AND payment_date <= ?{$cycleSql}"
        );
        $stmt->execute(array_merge([$compId], self::ALLOWED_STATES, [$fyStart, $fyEnd], $cycleParams));
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
