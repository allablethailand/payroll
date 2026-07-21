<?php
declare(strict_types=1);

/**
 * Shared read-only helpers used by report generators across all three report types
 * (statutory/payment/internal). Keeps the "which payroll_run rows are usable for reporting"
 * logic in one place instead of duplicated per report.
 */
class PayrollReportDataModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function getCompany(int $compId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `companies` WHERE id = :id");
        $stmt->execute([':id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getRun(int $runId, int $compId): ?array {
        $sql = "SELECT r.*, c.cycle_name FROM `payroll_runs` r
                LEFT JOIN `payroll_cycles` c ON c.id = r.cycle_id
                WHERE r.id = :id AND r.comp_id = :comp_id AND r.deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $runId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Runs whose pay period falls (even partially) within the given calendar year, usable states only. */
    public function getRunsInYear(int $compId, int $year, array $allowedStates): array {
        $placeholders = implode(',', array_fill(0, count($allowedStates), '?'));
        $sql = "SELECT r.*, c.cycle_name FROM `payroll_runs` r
                LEFT JOIN `payroll_cycles` c ON c.id = r.cycle_id
                WHERE r.comp_id = ? AND r.deleted_at IS NULL AND r.state IN ({$placeholders})
                AND YEAR(r.period_start_date) = ?
                ORDER BY r.period_start_date ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$compId], $allowedStates, [$year]));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array> decoded payroll_run_details rows keyed by nothing in particular, joined with employee info. */
    public function getRunDetails(int $runId): array {
        $sql = "SELECT d.*, e.employee_no, e.title, e.name_th, e.surname_th, e.name_en, e.surname_en,
                    e.tax_id_no, e.sso_no, e.key_version, e.department_id, e.branch_id,
                    e.bank_id, e.bank_account_no, e.bank_account_name, e.payment_type,
                    dep.department_name_th, dep.department_name_en,
                    br.branch_name_th, br.branch_name_en,
                    mb.bank_code, mb.bank_name_th, mb.bank_name_en
                FROM `payroll_run_details` d
                JOIN `employees` e ON e.id = d.employee_id
                LEFT JOIN `structure_departments` dep ON dep.id = e.department_id
                LEFT JOIN `structure_branches` br ON br.id = e.branch_id
                LEFT JOIN `master_banks` mb ON mb.id = e.bank_id
                WHERE d.run_id = :run_id
                ORDER BY e.employee_no ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':run_id' => $runId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['earning_breakdown'] = json_decode((string)$row['earning_breakdown'], true) ?? [];
            $row['deduction_breakdown'] = json_decode((string)$row['deduction_breakdown'], true) ?? [];
            $row['statutory_breakdown'] = json_decode((string)$row['statutory_breakdown'], true) ?? [];
        }
        return $rows;
    }

    /**
     * Employees whose employment_end_date falls within the given month — for สปส.6-09
     * (SSO termination notice). Not tied to any payroll_run; this is a point-in-time
     * employee-record query, since the resignation date itself is the authoritative signal
     * (see CLAUDE.md note on PayrollRunModel eligibility for the same reasoning).
     */
    public function getResignedEmployeesInMonth(int $compId, int $year, int $month): array {
        $sql = "SELECT e.*, dep.department_name_th, dep.department_name_en
                FROM `employees` e
                LEFT JOIN `structure_departments` dep ON dep.id = e.department_id
                WHERE e.comp_id = :comp_id AND e.deleted_at IS NULL
                AND e.employment_end_date IS NOT NULL
                AND YEAR(e.employment_end_date) = :year AND MONTH(e.employment_end_date) = :month
                ORDER BY e.employment_end_date ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':comp_id' => $compId, ':year' => $year, ':month' => $month]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param int[] $assignmentIds employee_earning_deductions.id values (from a deduction_breakdown
     *   line's 'assignment_id'), used to look up the external reference number (e.g. a กยศ. loan
     *   contract number) that doesn't get carried into deduction_breakdown itself.
     * @return array<int,?string> keyed by assignment_id
     */
    public function getEarningDeductionReferenceNos(array $assignmentIds): array {
        if (empty($assignmentIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($assignmentIds), '?'));
        $stmt = $this->db->prepare("SELECT id, external_reference_no FROM `employee_earning_deductions` WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($assignmentIds));
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int)$row['id']] = $row['external_reference_no'];
        }
        return $result;
    }

    public function getRunDetailForEmployee(int $runId, int $employeeId): ?array {
        foreach ($this->getRunDetails($runId) as $row) {
            if ((int)$row['employee_id'] === $employeeId) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Returns an error message if $run's state isn't in $allowedStates, else null.
     * Official/statutory reports must only be generated from runs that are at least
     * Approved (per the original spec: "เช็คว่าข้อมูล payroll run สถานะ Approved/Paid แล้ว
     * ก่อนออกรายงานยื่นราชการ") — a Draft run's numbers can still change and must not be
     * mistaken for something submittable.
     */
    public function assertRunState(array $run, array $allowedStates): ?string {
        if (!in_array($run['state'], $allowedStates, true)) {
            $allowedLabel = implode(', ', $allowedStates);
            return "This report requires the payroll run to be in one of these states: {$allowedLabel}. Current state: {$run['state']}.";
        }
        return null;
    }
}
