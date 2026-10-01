<?php
declare(strict_types=1);
require_once __DIR__ . '/ImportRowValidationException.php';
require_once __DIR__ . '/EmployeeMatcher.php';

/**
 * Resolves the two things every payroll-run import row points at -- the employee and the draft payroll run -- so each entity's
 * validator applies the same rules (ad-hoc items and attendance summaries share them).
 *
 * Run resolution: with run_code, that run ending in period_month/period_year (judged by period_end_date) must exist and be a draft;
 * without it, exactly one active draft run must end in that month. Draft is the only state PayrollRunModel lets anyone edit.
 */
class ImportTargetResolver {
    private PDO $db;
    private EmployeeMatcher $employees;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->employees = new EmployeeMatcher($this->db);
    }

    /** @return array{id:int, employee_no:string}
     *  @throws ImportRowValidationException */
    public function employee(int $compId, string $employeeCode): array {
        if ($employeeCode === '') {
            throw new ImportRowValidationException('employee_code_required', 'employee_code is required.');
        }
        $employee = $this->employees->find($compId, $employeeCode, '');
        if ($employee === null) {
            throw new ImportRowValidationException('employee_not_found', "Employee {$employeeCode} does not belong to this company.");
        }
        return $employee;
    }

    /** @return array{id:int, state:string, run_purpose:string, period_start_date:string, period_end_date:string}
     *  @throws ImportRowValidationException */
    public function draftRun(int $compId, string $periodMonth, string $periodYear, string $runCode): array {
        if (!ctype_digit($periodMonth) || (int)$periodMonth < 1 || (int)$periodMonth > 12
            || !ctype_digit($periodYear) || (int)$periodYear < 2000 || (int)$periodYear > 2100) {
            throw new ImportRowValidationException('period_invalid', 'period_month must be 1-12 and period_year a four-digit year.');
        }
        $month = (int)$periodMonth;
        $year = (int)$periodYear;
        $base = "SELECT id, state, run_purpose, period_start_date, period_end_date FROM `payroll_runs`
            WHERE comp_id = :c AND deleted_at IS NULL AND YEAR(period_end_date) = :y AND MONTH(period_end_date) = :m";
        if ($runCode !== '') {
            $stmt = $this->db->prepare($base . " AND run_code = :code LIMIT 1");
            $stmt->execute([':c' => $compId, ':y' => $year, ':m' => $month, ':code' => $runCode]);
            $run = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$run) {
                throw new ImportRowValidationException('run_not_found', "No payroll run with run_code {$runCode} ends in {$month}/{$year}.");
            }
            if ($run['state'] !== 'draft') {
                throw new ImportRowValidationException('invalid_run_status_for_import', "Payroll run {$runCode} is {$run['state']}; imports are only permitted on draft payroll runs.");
            }
            return $run;
        }
        $stmt = $this->db->prepare($base . " AND state = 'draft' AND status = 'active' LIMIT 2");
        $stmt->execute([':c' => $compId, ':y' => $year, ':m' => $month]);
        $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($runs) === 0) {
            throw new ImportRowValidationException('no_draft_run_for_period', "No active draft payroll run found for period {$month}/{$year}");
        }
        if (count($runs) > 1) {
            throw new ImportRowValidationException('multiple_draft_runs_for_period', "Multiple draft payroll runs found for period {$month}/{$year}. Please specify run_code");
        }
        return $runs[0];
    }
}
