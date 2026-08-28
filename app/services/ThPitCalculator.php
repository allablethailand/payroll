<?php
declare(strict_types=1);
require_once __DIR__ . '/StatutoryCalculationEngine.php';

/**
 * Computes a single payroll period's TH personal income tax (PIT, มาตรา 50 ทวิ) withholding
 * amount, already reduced from the annual figure down to one period's worth -- ready to use
 * directly as employee_amount on the TH_PIT line.
 *
 * 2026-08-21, real bug found & fixed (explicit report: 25,000/month employee withheld ~7,500 in
 * one period). Root cause was in PayrollRunModel::recalculate(), NOT in
 * StatutoryCalculationEngine -- the engine's own docblock states it is deliberately
 * country-agnostic ("no per-country if/else"), and its computeProgressiveBracket() bracket
 * walking was already correct. The bug was what got fed into it (gross*12 with zero deductions
 * subtracted, so brackets were applied to gross income instead of net taxable income) and what
 * happened to its output (the ANNUAL tax figure was used directly as a single period's
 * withholding, never divided back down). This class owns the TH-specific deduction/allowance math
 * and the average-vs-actual method branching; it calls back into StatutoryCalculationEngine only
 * for the bracket lookup itself (reused, not reimplemented), keeping the engine itself untouched
 * and its own test suite (tests/statutory_engine_test.php) unaffected.
 *
 * KNOWN SIMPLIFICATIONS (confirmed with the user before implementing, or well-established enough
 * not to need confirming -- flagged here rather than silently guessed, same convention as
 * master_statutory_leave_minimums leaving SG/MY unseeded rather than guess at real numbers):
 *  - Child allowance is a flat 30,000/dependent. Real TH law distinguishes a 2nd-and-later child
 *    born 2018+ (60,000) from every other child (30,000) -- explicitly NOT implemented; user
 *    approved the flat simplification for this pass.
 *  - Parents' allowance (30,000/parent if the parent is 60+ and has no material income) is NOT
 *    implemented at all -- nothing in this schema captures "parent has no income" precisely
 *    enough to compute it correctly, and guessing would be worse than omitting it.
 *  - SSO/PVD employee contributions ARE deducted from taxable income, but annualized as
 *    `period_amount * periodsPerYear` for BOTH methods (not full YTD-cumulative precision for
 *    this one piece) -- SSO has a fixed statutory cap so this is very close to exact regardless,
 *    and PVD is exact whenever the rate/base doesn't change mid-year (the common case).
 */
class ThPitCalculator {
    private PDO $db;
    private StatutoryCalculationEngine $engine;

    private const EXPENSE_DEDUCTION_RATE = 0.5;
    private const EXPENSE_DEDUCTION_CAP = 100000.0;
    private const PERSONAL_ALLOWANCE = 60000.0;
    private const SPOUSE_ALLOWANCE = 60000.0;
    private const CHILD_ALLOWANCE = 30000.0;
    private const CHILD_RELATIONSHIPS = ['child_legitimate', 'child_adopted'];
    private const YTD_STATES = ['approved', 'paid', 'locked'];

    public function __construct(?PDO $pdo = null, ?StatutoryCalculationEngine $engine = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->engine = $engine ?? new StatutoryCalculationEngine($this->db);
    }

    /**
     * @param string $method 'average' or 'actual' (employees.tax_calculation_method)
     * @return array{employee_amount:float, annual_taxable_income:float, annual_tax:float, method:string, periods_elapsed:?int}
     */
    public function calculate(
        int $compId,
        int $employeeId,
        float $periodGrossAmount,
        float $periodSsoEmployeeAmount,
        float $periodPvdEmployeeAmount,
        int $periodsPerYear,
        string $method,
        bool $hasSpouse,
        string $periodStartDate,
        string $calcDate
    ): array {
        $periodsPerYear = max(1, $periodsPerYear);
        $childCount = $this->dependentChildCount($employeeId);
        $annualSso = $periodSsoEmployeeAmount * $periodsPerYear;
        $annualPvd = $periodPvdEmployeeAmount * $periodsPerYear;
        $allowances = $this->personalAllowances($hasSpouse, $childCount) + $annualSso + $annualPvd;

        if ($method === 'actual') {
            return $this->calculateActual($compId, $employeeId, $periodGrossAmount, $periodsPerYear, $allowances, $periodStartDate, $calcDate);
        }
        return $this->calculateAverage($compId, $periodGrossAmount, $periodsPerYear, $allowances, $calcDate);
    }

    private function personalAllowances(bool $hasSpouse, int $childCount): float {
        return self::PERSONAL_ALLOWANCE
            + ($hasSpouse ? self::SPOUSE_ALLOWANCE : 0.0)
            + ($childCount * self::CHILD_ALLOWANCE);
    }

    private function netTaxableIncome(float $annualGrossEstimate, float $allowances): float {
        $expenseDeduction = min($annualGrossEstimate * self::EXPENSE_DEDUCTION_RATE, self::EXPENSE_DEDUCTION_CAP);
        return max(0.0, $annualGrossEstimate - $expenseDeduction - $allowances);
    }

    private function annualTax(int $compId, float $netTaxableIncome, string $calcDate): float {
        $line = $this->engine->calculateItem($compId, 'TH_PIT', ['taxable_income' => $netTaxableIncome], $calcDate);
        return $line ? (float)$line['employee_amount'] : 0.0;
    }

    /** No history needed: assumes this period's gross repeats every period this year. */
    private function calculateAverage(int $compId, float $periodGrossAmount, int $periodsPerYear, float $allowances, string $calcDate): array {
        $annualEstimate = round($periodGrossAmount * $periodsPerYear, 2);
        $netTaxable = round($this->netTaxableIncome($annualEstimate, $allowances), 2);
        $annualTax = $this->annualTax($compId, $netTaxable, $calcDate);
        return [
            'employee_amount' => round($annualTax / $periodsPerYear, 2),
            'annual_taxable_income' => $netTaxable,
            'annual_tax' => $annualTax,
            'method' => 'average',
            'periods_elapsed' => null,
        ];
    }

    /**
     * Cumulative year-to-date true-up (การคำนวณสะสมตามจริงทุกเดือน), standard TH RD method:
     * projects the annual estimate as "actual income earned so far this year (including this
     * period) + remaining periods projected AT THIS PERIOD'S RATE" -- deliberately NOT
     * "average-income-so-far x total periods" (that formula was tried first and rejected: it
     * converges to a raise far too slowly, since low earlier periods keep dragging the average
     * down for the rest of the year instead of the projection responding to the new rate right
     * away). Projecting the remainder at the latest rate is what makes a raise or bonus correctly
     * increase withholding starting the period it happens, with the true-up (below) spreading any
     * remaining catch-up across whatever periods are left instead of a single lump correction.
     */
    private function calculateActual(int $compId, int $employeeId, float $periodGrossAmount, int $periodsPerYear, float $allowances, string $periodStartDate, string $calcDate): array {
        $ytdGrossPrior = $this->ytdGrossPriorToThisPeriod($compId, $employeeId, $periodStartDate);
        $periodsElapsed = $this->periodsElapsedThisYear($compId, $employeeId, $periodStartDate) + 1;
        $ytdGrossIncludingThis = $ytdGrossPrior + $periodGrossAmount;
        $remainingPeriods = max(0, $periodsPerYear - $periodsElapsed);

        $annualEstimate = round($ytdGrossIncludingThis + ($remainingPeriods * $periodGrossAmount), 2);
        $netTaxable = round($this->netTaxableIncome($annualEstimate, $allowances), 2);
        $annualTax = $this->annualTax($compId, $netTaxable, $calcDate);

        $cumulativeTaxDue = round(($annualTax / $periodsPerYear) * $periodsElapsed, 2);
        $ytdPitWithheldPrior = $this->ytdPitWithheldPriorToThisPeriod($compId, $employeeId, $periodStartDate);

        return [
            'employee_amount' => max(0.0, round($cumulativeTaxDue - $ytdPitWithheldPrior, 2)),
            'annual_taxable_income' => $netTaxable,
            'annual_tax' => $annualTax,
            'method' => 'actual',
            'periods_elapsed' => $periodsElapsed,
        ];
    }

    private function dependentChildCount(int $employeeId): int {
        $placeholders = implode(',', array_fill(0, count(self::CHILD_RELATIONSHIPS), '?'));
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `employee_dependents`
            WHERE employee_id = ? AND status = 'active' AND deleted_at IS NULL
                AND relationship IN ({$placeholders})");
        $stmt->execute(array_merge([$employeeId], self::CHILD_RELATIONSHIPS));
        return (int)$stmt->fetchColumn();
    }

    /** Same query shape as PayrollReportDataModel::getYtdTotals() -- states filtered to
     *  approved/paid/locked, which naturally excludes the current still-draft run being
     *  calculated, no separate self-exclusion needed. */
    private function ytdGrossPriorToThisPeriod(int $compId, int $employeeId, string $periodStartDate): float {
        $year = (int)substr($periodStartDate, 0, 4);
        $placeholders = implode(',', array_fill(0, count(self::YTD_STATES), '?'));
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(d.gross_amount), 0) FROM `payroll_run_details` d
            JOIN `payroll_runs` r ON r.id = d.run_id
            WHERE r.comp_id = ? AND r.deleted_at IS NULL AND r.state IN ({$placeholders})
                AND YEAR(r.period_start_date) = ? AND r.period_start_date < ? AND d.employee_id = ?");
        $stmt->execute(array_merge([$compId], self::YTD_STATES, [$year, $periodStartDate, $employeeId]));
        return (float)$stmt->fetchColumn();
    }

    private function periodsElapsedThisYear(int $compId, int $employeeId, string $periodStartDate): int {
        $year = (int)substr($periodStartDate, 0, 4);
        $placeholders = implode(',', array_fill(0, count(self::YTD_STATES), '?'));
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `payroll_run_details` d
            JOIN `payroll_runs` r ON r.id = d.run_id
            WHERE r.comp_id = ? AND r.deleted_at IS NULL AND r.state IN ({$placeholders})
                AND YEAR(r.period_start_date) = ? AND r.period_start_date < ? AND d.employee_id = ?");
        $stmt->execute(array_merge([$compId], self::YTD_STATES, [$year, $periodStartDate, $employeeId]));
        return (int)$stmt->fetchColumn();
    }

    /** Sums the TH_PIT line's employee_amount out of statutory_breakdown (JSON, decoded PHP-side
     *  -- same pattern this codebase already uses elsewhere rather than fragile in-SQL JSON
     *  functions) across prior periods this year, up to (not including) this one. */
    private function ytdPitWithheldPriorToThisPeriod(int $compId, int $employeeId, string $periodStartDate): float {
        $year = (int)substr($periodStartDate, 0, 4);
        $placeholders = implode(',', array_fill(0, count(self::YTD_STATES), '?'));
        $stmt = $this->db->prepare("SELECT d.statutory_breakdown FROM `payroll_run_details` d
            JOIN `payroll_runs` r ON r.id = d.run_id
            WHERE r.comp_id = ? AND r.deleted_at IS NULL AND r.state IN ({$placeholders})
                AND YEAR(r.period_start_date) = ? AND r.period_start_date < ? AND d.employee_id = ?");
        $stmt->execute(array_merge([$compId], self::YTD_STATES, [$year, $periodStartDate, $employeeId]));
        $total = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $items = json_decode((string)$json, true);
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (($item['code'] ?? null) === 'TH_PIT') {
                    $total += (float)($item['employee_amount'] ?? 0);
                }
            }
        }
        return $total;
    }
}
