<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/reports/LocalizedException.php';

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
        // c.bank_file_format_id (2026-08-29) -- lets BankTransferFileReport resolve which company-
        // configured bank file layout (BankFileFormatModel) to render this run's transfer file with.
        // c.bank_account_id (2026-08-29, explicit follow-up: "ในแต่ละรอบการจ่ายอาจใช้เลขแยกกันครับ แยก
        // บัญชีในการจ่าย") -- lets that same report resolve WHICH of the company's own bank accounts
        // (and its own Company/Service Code) this run's cycle settles from, instead of always the
        // company's single is_default account -- see
        // BankTransferFileReport::resolveCompanyBankAccount()'s own docblock.
        $sql = "SELECT r.*, c.cycle_name, c.bank_file_format_id, c.bank_account_id FROM `payroll_runs` r
                LEFT JOIN `payroll_cycles` c ON c.id = r.cycle_id
                WHERE r.id = :id AND r.comp_id = :comp_id AND r.deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $runId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Every reportable (usable-state) run for a company, newest pay period first -- backs the
     * Per-Cycle Reports matrix table (one row per completed run) added 2026-08-27.
     */
    public function getCompletedRuns(int $compId, array $allowedStates): array {
        $placeholders = implode(',', array_fill(0, count($allowedStates), '?'));
        $sql = "SELECT r.id, r.run_name, r.state, r.period_start_date, r.period_end_date, c.cycle_name
                FROM `payroll_runs` r
                LEFT JOIN `payroll_cycles` c ON c.id = r.cycle_id
                WHERE r.comp_id = ? AND r.deleted_at IS NULL AND r.state IN ({$placeholders})
                ORDER BY r.period_start_date DESC, r.id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$compId], $allowedStates));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 2026-08-30, explicit request: "filter ปีให้เลือกจากปีที่มีข้อมูลจริง" -- the Annual Reports tab's
     * year field used to be a free-typed number input (any year, including ones with zero data);
     * now backs a dropdown of only the Gregorian years that genuinely have at least one usable-state
     * run. Caller converts to Buddhist Era for display/selection (see ReportsController's own
     * availableYears(), matching the same +543 convention every annual report generator already
     * applies to context['year'] on the way back in).
     */
    public function availableReportYears(int $compId, array $allowedStates): array {
        $placeholders = implode(',', array_fill(0, count($allowedStates), '?'));
        $sql = "SELECT DISTINCT YEAR(r.period_start_date) AS yr FROM `payroll_runs` r
                WHERE r.comp_id = ? AND r.deleted_at IS NULL AND r.state IN ({$placeholders})
                ORDER BY yr DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$compId], $allowedStates));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Runs whose pay period falls (even partially) within the given calendar year, usable states only. */
    public function getRunsInYear(int $compId, int $year, array $allowedStates): array {
        $placeholders = implode(',', array_fill(0, count($allowedStates), '?'));
        // c.bank_account_id (2026-08-30, explicit follow-up: "ตรงส่วนของการตั้งค่ารอบ มีการให้เลือกบัญชีจ่าย
        // เงินแล้ว...ในส่วนของการออกรายงาน ถ้ายังไม่ดึงไปช่วยดึงไปด้วยครับ") -- PaymentVoucherReport spans
        // multiple runs per employee across a year, and different runs can settle from different
        // cycle bank_accounts (same per-cycle account pinning BankTransferFileReport's own
        // resolveCompanyBankAccount() already resolves) -- exposed here so that report can show which
        // account paid each line, not just Bank Transfer File.
        $sql = "SELECT r.*, c.cycle_name, c.bank_account_id FROM `payroll_runs` r
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
        // 2026-08-29, explicit request: PND1's real pipe-delimited e-Filing spec needs the
        // employee's registered address (subdistrict/district/province/postal code, via
        // master_addresses -- same table/join pattern Company Profile's own address already
        // uses) -- see PndOneReport's own docblock for the address-completeness gap this only
        // partially closes (house no./moo/building/soi/road have no structured columns at all,
        // just free-text address_line_1_register/address_line_2_register).
        // 2026-08-31, explicit request: real double-payment risk found and confirmed by the user --
        // reopen() has always allowed re-opening an already-paid/locked run (e.g. to merge a
        // supplemental process into it), but nothing tracked how much of the run's own current
        // total was ALREADY actually disbursed on a prior payment cycle. *_amount_due are the
        // DELTA still owed this cycle (current amount minus every payroll_run_payment_events row
        // already recorded for this run+employee) -- 0 for every employee on a run's first-ever
        // payment cycle (no prior events exist yet), so this is a no-op for the overwhelmingly
        // common single-payment-cycle case. Payment-report consumers (BankTransferFileReport/
        // PaymentVoucherReport) use these instead of the raw gross_amount/total_deduction_amount/
        // net_amount columns when computing what to actually transfer.
        $sql = "SELECT d.*, e.employee_no, e.title, e.name_th, e.surname_th, e.name_en, e.surname_en,
                    e.tax_id_no, e.sso_no, e.id_card_no, e.key_version, e.department_id, e.branch_id, e.position_id,
                    e.bank_id, e.bank_account_no, e.bank_account_name,
                    e.payment_method_id, mpm.code AS payment_method_code,
                    e.address_line_1_register, e.address_line_2_register,
                    dep.department_name_th, dep.department_name_en,
                    br.branch_name_th, br.branch_name_en,
                    pos.position_name_th, pos.position_name_en,
                    mb.bank_code, mb.bank_name_th, mb.bank_name_en,
                    ma.level_1 AS address_postcode, ma.level_2_th AS address_province_th, ma.level_2_en AS address_province_en,
                    ma.level_3_th AS address_district_th, ma.level_3_en AS address_district_en,
                    ma.level_4_th AS address_subdistrict_th, ma.level_4_en AS address_subdistrict_en,
                    (d.gross_amount - COALESCE(pp.gross_paid, 0)) AS gross_amount_due,
                    (d.total_deduction_amount - COALESCE(pp.deduction_paid, 0)) AS deduction_amount_due,
                    (d.net_amount - COALESCE(pp.net_paid, 0)) AS net_amount_due
                FROM `payroll_run_details` d
                JOIN `employees` e ON e.id = d.employee_id
                LEFT JOIN `structure_departments` dep ON dep.id = e.department_id
                LEFT JOIN `structure_branches` br ON br.id = e.branch_id
                LEFT JOIN `structure_positions` pos ON pos.id = e.position_id
                LEFT JOIN `master_banks` mb ON mb.id = e.bank_id
                LEFT JOIN `master_addresses` ma ON ma.id = e.master_address_id_register
                LEFT JOIN `master_payment_methods` mpm ON mpm.id = e.payment_method_id
                LEFT JOIN (
                    SELECT run_id, employee_id, SUM(gross_amount_paid) AS gross_paid,
                        SUM(deduction_amount_paid) AS deduction_paid, SUM(net_amount_paid) AS net_paid
                    FROM `payroll_run_payment_events` WHERE run_id = :run_id_pp GROUP BY run_id, employee_id
                ) pp ON pp.run_id = d.run_id AND pp.employee_id = d.employee_id
                WHERE d.run_id = :run_id
                ORDER BY e.employee_no ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':run_id' => $runId, ':run_id_pp' => $runId]);
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
                WHERE e.comp_id = :comp_id AND e.deleted_at IS NULL AND e.is_payroll_participant = 1
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
    /**
     * Sums gross/deduction/net across this employee's payroll_run_details rows for runs whose
     * period falls in the same calendar year as $uptoDate and starts on or before it -- used for
     * the payslip template's "ytd_summary" field. Only counts runs in an allowed (reportable) state.
     */
    public function getYtdTotals(int $compId, int $employeeId, string $uptoDate, array $allowedStates): array {
        $year = (int)substr($uptoDate, 0, 4);
        $placeholders = implode(',', array_fill(0, count($allowedStates), '?'));
        $sql = "SELECT COALESCE(SUM(d.gross_amount), 0) AS ytd_gross,
                    COALESCE(SUM(d.total_deduction_amount), 0) AS ytd_deduction,
                    COALESCE(SUM(d.net_amount), 0) AS ytd_net
                FROM `payroll_run_details` d
                JOIN `payroll_runs` r ON r.id = d.run_id
                WHERE r.comp_id = ? AND r.deleted_at IS NULL AND r.state IN ({$placeholders})
                    AND YEAR(r.period_start_date) = ? AND r.period_start_date <= ?
                    AND d.employee_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$compId], $allowedStates, [$year, $uptoDate, $employeeId]));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'ytd_gross' => (float)($row['ytd_gross'] ?? 0),
            'ytd_deduction' => (float)($row['ytd_deduction'] ?? 0),
            'ytd_net' => (float)($row['ytd_net'] ?? 0),
        ];
    }

    public function assertRunState(array $run, array $allowedStates): ?string {
        if (!in_array($run['state'], $allowedStates, true)) {
            $allowedLabel = implode(', ', $allowedStates);
            return "This report requires the payroll run to be in one of these states: {$allowedLabel}. Current state: {$run['state']}.";
        }
        return null;
    }

    /**
     * Same check as assertRunState(), but throws a LocalizedException (error_key='run_state_invalid',
     * params={states: string[], current_state: string}) instead of returning a pre-formatted English
     * string. Frontend translates each state code via the existing state_{code} lang keys. Prefer this
     * over assertRunState() in new code -- kept both since assertRunState() may still be called
     * elsewhere and changing its return contract isn't worth the blast radius for this pass.
     */
    public function assertRunStateOrThrow(array $run, array $allowedStates): void {
        if (!in_array($run['state'], $allowedStates, true)) {
            $allowedLabel = implode(', ', $allowedStates);
            throw new LocalizedException(
                "This report requires the payroll run to be in one of these states: {$allowedLabel}. Current state: {$run['state']}.",
                'run_state_invalid',
                ['states' => $allowedStates, 'current_state' => $run['state']]
            );
        }
    }

    /**
     * 2026-08-31, same-day follow-up -- ScheduledItemOccurrenceReconciliationReport's own data
     * source. Deliberately queries `payroll_sync_item_occurrences` directly (NOT through any
     * payroll_runs row) -- occurrences belong to the Origami PROCESS, independent of whether/when
     * it was ever pulled into a run, so this reconciliation view stays complete even for a process
     * still sitting in Pending Pull (or one that never gets pulled at all, e.g. a rejected one).
     * `applied_at` is the filter date (when the installment was actually applied on Origami's own
     * side), not `received_at`/period dates, since that's the figure someone reconciling actual
     * loan repayments against a period would care about. An occurrence whose employee_id never
     * resolved (see PayrollSyncModel::replaceScheduledItemOccurrences()'s own docblock) still shows
     * up here (employee columns NULL) rather than being silently excluded -- reconciliation is
     * exactly the place an unresolved row needs to be visible, not hidden.
     */
    public function scheduledItemOccurrences(int $compId, ?string $dateFrom, ?string $dateTo): array {
        $where = "WHERE p.comp_id = :comp_id";
        $params = [':comp_id' => $compId];
        if ($dateFrom !== null && $dateFrom !== '') {
            $where .= " AND o.applied_at >= :date_from";
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo !== null && $dateTo !== '') {
            $where .= " AND o.applied_at <= :date_to";
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }
        $sql = "SELECT o.item_code, o.item_ref_code, o.occurrence_code, o.installment_no, o.amount, o.applied_at,
                    o.origami_emp_id, e.employee_no, e.name_th, e.surname_th, e.name_en, e.surname_en,
                    p.process_no, r.id AS run_id, r.run_name, r.state AS run_state
                FROM `payroll_sync_item_occurrences` o
                JOIN `payroll_sync_processes` p ON p.id = o.process_id
                LEFT JOIN `employees` e ON e.id = o.employee_id
                LEFT JOIN `payroll_runs` r ON r.sync_process_id = p.id
                {$where}
                ORDER BY o.applied_at ASC, o.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
