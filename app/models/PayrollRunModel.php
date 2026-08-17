<?php
declare(strict_types=1);
require_once __DIR__ . '/AttendanceBonusLedgerModel.php';
require_once __DIR__ . '/../services/StatutoryCalculationEngine.php';
require_once __DIR__ . '/../services/PayslipDeliveryService.php';

/**
 * Payroll Run state machine + calculation.
 *
 * State machine (enforced here, not just in the frontend):
 *   draft --submit--> pending_approval --approve--> approved --markPaid--> paid --lock--> locked
 *   pending_approval --revert--> draft
 *   pending_approval --reject--> rejected --reviseAfterReject--> draft
 *
 * Every transition: checks role permission (structure_roles.can_*_payroll via the acting
 * employee's role_id, unless $isAdmin bypass), re-validates business rules, and writes a
 * payroll_run_audit_logs row. Read methods (list/get/getDetails/getAuditLog) never mutate
 * state and never need a permission check.
 */
class PayrollRunModel {
    private PDO $db;
    private AttendanceBonusLedgerModel $ledgerModel;
    private StatutoryCalculationEngine $engine;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->ledgerModel = new AttendanceBonusLedgerModel();
        $this->engine = new StatutoryCalculationEngine($this->db);
    }

    /* ==================== READ ==================== */

    public function list(int $compId, array $filters = []): array {
        $where = "WHERE r.comp_id = :comp_id AND r.deleted_at IS NULL";
        $params = [':comp_id' => $compId];
        if (!empty($filters['state'])) {
            $where .= " AND r.state = :state";
            $params[':state'] = $filters['state'];
        }
        if (!empty($filters['date_from'])) {
            $where .= " AND r.period_end_date >= :date_from";
            $params[':date_from'] = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND r.period_start_date <= :date_to";
            $params[':date_to'] = $filters['date_to'];
        }
        $sql = "SELECT r.*, c.cycle_name,
                    creator.name_th AS created_by_name_th, creator.name_en AS created_by_name_en,
                    submitter.name_th AS submitted_by_name_th, submitter.name_en AS submitted_by_name_en
                FROM `payroll_runs` r
                LEFT JOIN `payroll_cycles` c ON c.id = r.cycle_id
                LEFT JOIN `employees` creator ON creator.id = r.created_by
                LEFT JOIN `employees` submitter ON submitter.id = r.submitted_by
                {$where}
                ORDER BY r.period_start_date DESC, r.id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Select2-ajax-shaped list of runs, for report generation pickers etc. */
    public function options(int $compId, string $search, int $page, int $limit, ?array $allowedStates = null): array {
        $offset = ($page - 1) * $limit;
        $where = "WHERE comp_id = :comp_id AND deleted_at IS NULL";
        $params = [':comp_id' => $compId];
        if ($search !== '') {
            $where .= " AND run_name LIKE :search";
            $params[':search'] = "%{$search}%";
        }
        if (!empty($allowedStates)) {
            $stateKeys = [];
            foreach (array_values($allowedStates) as $i => $state) {
                $key = ":state{$i}";
                $stateKeys[] = $key;
                $params[$key] = $state;
            }
            $where .= ' AND state IN (' . implode(',', $stateKeys) . ')';
        }

        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `payroll_runs` {$where}");
        $totalStmt->execute($params);
        $totalCount = (int)$totalStmt->fetchColumn();

        $sql = "SELECT id,
                    CONCAT(run_name, ' (', period_start_date, ' - ', period_end_date, ')') AS text_th,
                    CONCAT(run_name, ' (', period_start_date, ' - ', period_end_date, ')') AS text_en
                FROM `payroll_runs` {$where} ORDER BY period_start_date DESC, id DESC LIMIT :offset, :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total_count' => $totalCount];
    }

    public function get(int $id, int $compId): ?array {
        $sql = "SELECT r.*, c.cycle_name, c.payroll_frequency,
                    creator.name_th AS created_by_name_th, creator.name_en AS created_by_name_en,
                    submitter.name_th AS submitted_by_name_th, submitter.name_en AS submitted_by_name_en
                FROM `payroll_runs` r
                LEFT JOIN `payroll_cycles` c ON c.id = r.cycle_id
                LEFT JOIN `employees` creator ON creator.id = r.created_by
                LEFT JOIN `employees` submitter ON submitter.id = r.submitted_by
                WHERE r.id = :id AND r.comp_id = :comp_id AND r.deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getDetails(int $runId, int $compId): array {
        if (!$this->get($runId, $compId)) {
            return [];
        }
        $sql = "SELECT d.*, e.employee_no, e.name_th, e.surname_th, e.name_en, e.surname_en, e.department_id
                FROM `payroll_run_details` d
                JOIN `employees` e ON e.id = d.employee_id
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

    public function getAuditLog(int $runId, int $compId): array {
        if (!$this->get($runId, $compId)) {
            return [];
        }
        $sql = "SELECT a.*, e.name_th AS performed_by_name_th, e.name_en AS performed_by_name_en
                FROM `payroll_run_audit_logs` a
                LEFT JOIN `employees` e ON e.id = a.performed_by
                WHERE a.run_id = :run_id ORDER BY a.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':run_id' => $runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /* ==================== PERMISSIONS ==================== */

    /** True if the employee holds ANY of the 3 payroll role-flags (or is admin) -- gates read access to run detail/salary data. */
    public function canView(int $actingEmployeeId, bool $isAdmin): bool {
        return $this->userCan($actingEmployeeId, 'can_process_payroll', $isAdmin)
            || $this->userCan($actingEmployeeId, 'can_approve_payroll', $isAdmin)
            || $this->userCan($actingEmployeeId, 'can_finalize_payroll', $isAdmin);
    }

    private function userCan(int $actingEmployeeId, string $permissionColumn, bool $isAdmin): bool {
        if ($isAdmin) {
            return true;
        }
        if (!in_array($permissionColumn, ['can_process_payroll', 'can_approve_payroll', 'can_finalize_payroll'], true)) {
            return false;
        }
        $sql = "SELECT sr.`{$permissionColumn}` FROM `employees` e
                JOIN `structure_roles` sr ON sr.id = e.role_id AND sr.deleted_at IS NULL
                WHERE e.id = :employee_id AND e.deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':employee_id' => $actingEmployeeId]);
        $val = $stmt->fetchColumn();
        return $val !== false && (int)$val === 1;
    }

    /* ==================== AUDIT ==================== */

    private function logAudit(int $runId, ?string $fromState, string $toState, string $action, int $userId, ?string $note = null): void {
        $stmt = $this->db->prepare("INSERT INTO `payroll_run_audit_logs` (run_id, from_state, to_state, action, note, performed_by)
            VALUES (:run_id, :from_state, :to_state, :action, :note, :performed_by)");
        $stmt->execute([
            ':run_id' => $runId,
            ':from_state' => $fromState,
            ':to_state' => $toState,
            ':action' => $action,
            ':note' => $note,
            ':performed_by' => $userId,
        ]);
    }

    /* ==================== CREATE / EDIT (draft only) ==================== */

    private function isDuplicatePeriod(int $compId, int $cycleId, string $start, string $end, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM `payroll_runs`
                WHERE comp_id = :comp_id AND cycle_id = :cycle_id AND period_start_date = :start AND period_end_date = :end
                AND deleted_at IS NULL";
        $params = [':comp_id' => $compId, ':cycle_id' => $cycleId, ':start' => $start, ':end' => $end];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function create(int $compId, array $data, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to create a payroll run.'];
        }
        // A company auto-provisioned via Origami SSO (auth/index.php) starts as
        // setup_status='draft' with placeholder registered_country/global_tax_id/etc -- block
        // real payroll runs until Company Profile is saved with real values (CompanyProfileModel
        // ::save() flips this to 'active'). Nothing else in the app is gated this way; editing
        // Company Profile, adding employees, etc. all stay usable in draft mode since that's the
        // only way to get out of it.
        $stmtComp = $this->db->prepare("SELECT setup_status FROM `companies` WHERE id = :id");
        $stmtComp->execute([':id' => $compId]);
        if (($stmtComp->fetchColumn() ?: 'active') === 'draft') {
            return ['status' => false, 'message' => 'บริษัทนี้ยังตั้งค่าไม่ครบ (สร้างจาก Origami SSO อัตโนมัติ) กรุณาไปที่ Company Profile เพื่อกรอกข้อมูลให้ครบก่อนสร้างรอบจ่ายเงินเดือน'];
        }
        foreach (['cycle_id', 'run_name', 'period_start_date', 'period_end_date', 'payment_date'] as $field) {
            if (empty($data[$field])) {
                return ['status' => false, 'message' => "Missing required field: {$field}"];
            }
        }
        $cycleId = (int)$data['cycle_id'];
        $stmtCycle = $this->db->prepare("SELECT id FROM `payroll_cycles` WHERE id = :id AND comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL");
        $stmtCycle->execute([':id' => $cycleId, ':comp_id' => $compId]);
        if (!$stmtCycle->fetch()) {
            return ['status' => false, 'message' => 'Invalid or inactive payroll cycle.'];
        }

        [$start, $end, $payDate] = [(string)$data['period_start_date'], (string)$data['period_end_date'], (string)$data['payment_date']];
        foreach ([$start, $end, $payDate] as $d) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return ['status' => false, 'message' => 'Invalid date format, expected YYYY-MM-DD.'];
            }
        }
        if ($end < $start) {
            return ['status' => false, 'message' => 'period_end_date must not be before period_start_date.'];
        }
        if ($this->isDuplicatePeriod($compId, $cycleId, $start, $end, null)) {
            return ['status' => false, 'message' => 'A payroll run already exists for this cycle and period.'];
        }

        // Payroll Process page "Pending Pull" station: creating a run from an unconsumed
        // payroll_sync_processes row (as opposed to a standalone run, the default/normal path)
        // links it via sync_process_id so it drops out of that station afterward. Validated here
        // (not just left to the DB's UNIQUE constraint) for a clear error message instead of a
        // raw constraint-violation surfacing to the user.
        $syncProcessId = null;
        if (!empty($data['sync_process_id'])) {
            $syncProcessId = (int)$data['sync_process_id'];
            $stmtSync = $this->db->prepare("SELECT p.id FROM `payroll_sync_processes` p
                LEFT JOIN `payroll_runs` r ON r.sync_process_id = p.id
                WHERE p.id = :id AND p.comp_id = :comp_id AND r.id IS NULL");
            $stmtSync->execute([':id' => $syncProcessId, ':comp_id' => $compId]);
            if (!$stmtSync->fetch()) {
                return ['status' => false, 'message' => 'Invalid or already-pulled sync process.'];
            }
        }

        $runName = trim((string)$data['run_name']);
        $notes = !empty($data['notes']) ? trim((string)$data['notes']) : null;

        $stmt = $this->db->prepare("INSERT INTO `payroll_runs`
            (comp_id, cycle_id, sync_process_id, run_name, period_start_date, period_end_date, payment_date, state, notes, created_by)
            VALUES (:comp_id, :cycle_id, :sync_process_id, :run_name, :start, :end, :pay_date, 'draft', :notes, :created_by)");
        $stmt->execute([
            ':comp_id' => $compId, ':cycle_id' => $cycleId, ':sync_process_id' => $syncProcessId, ':run_name' => $runName,
            ':start' => $start, ':end' => $end, ':pay_date' => $payDate,
            ':notes' => $notes, ':created_by' => $userId,
        ]);
        $runId = (int)$this->db->lastInsertId();
        $this->logAudit($runId, null, 'draft', 'create', $userId);
        return ['status' => true, 'message' => 'Created successfully.', 'id' => $runId];
    }

    public function update(int $id, int $compId, array $data, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run can be edited.'];
        }
        $runName = !empty($data['run_name']) ? trim((string)$data['run_name']) : $run['run_name'];
        $start = !empty($data['period_start_date']) ? (string)$data['period_start_date'] : $run['period_start_date'];
        $end = !empty($data['period_end_date']) ? (string)$data['period_end_date'] : $run['period_end_date'];
        $payDate = !empty($data['payment_date']) ? (string)$data['payment_date'] : $run['payment_date'];
        if ($end < $start) {
            return ['status' => false, 'message' => 'period_end_date must not be before period_start_date.'];
        }
        if ($this->isDuplicatePeriod($compId, (int)$run['cycle_id'], $start, $end, $id)) {
            return ['status' => false, 'message' => 'A payroll run already exists for this cycle and period.'];
        }
        $notes = array_key_exists('notes', $data) ? (trim((string)$data['notes']) ?: null) : $run['notes'];

        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET run_name = :run_name, period_start_date = :start,
            period_end_date = :end, payment_date = :pay_date, notes = :notes, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id");
        $stmt->execute([
            ':run_name' => $runName, ':start' => $start, ':end' => $end, ':pay_date' => $payDate,
            ':notes' => $notes, ':updated_by' => $userId, ':id' => $id,
        ]);
        $this->logAudit($id, 'draft', 'draft', 'update', $userId);
        return ['status' => true, 'message' => 'Updated successfully.'];
    }

    public function delete(int $id, int $compId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to delete this payroll run.'];
        }
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run can be deleted.'];
        }
        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) { $this->db->beginTransaction(); }
            $this->db->prepare("DELETE FROM `payroll_run_details` WHERE run_id = :id")->execute([':id' => $id]);
            $stmt = $this->db->prepare("UPDATE `payroll_runs` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
            $this->logAudit($id, 'draft', 'draft', 'delete', $userId);
            if ($ownTransaction) { $this->db->commit(); }
            return ['status' => true, 'message' => 'Deleted successfully.'];
        } catch (PDOException $e) {
            if ($ownTransaction) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /* ==================== CALCULATION (draft only) ==================== */

    /**
     * Recomputes every eligible employee's breakdown for this run and overwrites
     * payroll_run_details wholesale. Pure read of employees/PED/ledger/statutory config —
     * does NOT mark installments processed or lock ledger entries (that only happens for
     * real at markPaid(), so an abandoned draft calculation never leaves side effects
     * on other modules' data).
     *
     * KNOWN SIMPLIFICATIONS (no Time & Leave module exists yet):
     *  - No real attendance/OT sync data source; only employee_earning_deductions (PED
     *    assignments) and attendance_bonus_ledger feed earnings/deductions beyond base pay.
     *  - taxable_income context for the statutory engine is estimated as gross * 12
     *    (annualized), not the employee's actual tax_calculation_method (average/actual).
     *  - Pro-rate accounts for both mid-period joiners (employment_date) and mid-period
     *    leavers (employment_end_date).
     * All of the above are flagged here and in the class docblock, not hidden.
     */
    public function recalculate(int $id, int $compId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to calculate this payroll run.'];
        }
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run can be recalculated.'];
        }

        $periodStart = $run['period_start_date'];
        $periodEnd = $run['period_end_date'];
        $paymentDate = $run['payment_date'];
        $periodYear = (int)date('Y', strtotime($periodStart));
        $periodMonth = (int)date('n', strtotime($periodStart));
        $totalPeriodDays = (int)((strtotime($periodEnd) - strtotime($periodStart)) / 86400) + 1;

        // Eligibility is based purely on the employment date range overlapping this pay period,
        // not on the current employee_status label — a "resigned" employee's status is usually
        // updated as soon as they leave, but their FINAL (partial) period still needs to be paid,
        // so status alone would wrongly exclude them from their own last run.
        // is_payroll_ready=0 additionally excludes employees auto-provisioned via Origami SSO
        // (auth/index.php) whose salary/tax/employment fields are still placeholders -- until a
        // real EmployeeModel::save() completes their profile, they must never enter a real
        // payroll calculation.
        $stmtEmp = $this->db->prepare("SELECT id, base_salary_amount, employment_date, employment_end_date,
                sso_enrolled, pvd_enrolled, tax_exempt
            FROM `employees`
            WHERE comp_id = :comp_id AND deleted_at IS NULL AND is_payroll_ready = 1
            AND employment_date <= :period_end
            AND (employment_end_date IS NULL OR employment_end_date >= :period_start)");
        $stmtEmp->execute([':comp_id' => $compId, ':period_end' => $periodEnd, ':period_start' => $periodStart]);
        $employees = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) { $this->db->beginTransaction(); }
            $this->db->prepare("DELETE FROM `payroll_run_details` WHERE run_id = :id")->execute([':id' => $id]);

            $insStmt = $this->db->prepare("INSERT INTO `payroll_run_details`
                (run_id, employee_id, base_salary_amount, prorate_days, prorate_total_days,
                 earning_breakdown, deduction_breakdown, statutory_breakdown,
                 gross_amount, total_deduction_amount, net_amount, employer_cost_amount, calc_status, calc_errors, data_source)
                VALUES (:run_id, :employee_id, :base_salary_amount, :prorate_days, :prorate_total_days,
                 :earning_breakdown, :deduction_breakdown, :statutory_breakdown,
                 :gross_amount, :total_deduction_amount, :net_amount, :employer_cost_amount, :calc_status, :calc_errors, 'manual')");

            $totalGross = 0.0;
            $totalDeduction = 0.0;
            $totalNet = 0.0;
            $anyError = false;

            foreach ($employees as $emp) {
                $employeeId = (int)$emp['id'];
                $baseSalary = (float)$emp['base_salary_amount'];
                $employmentDate = $emp['employment_date'];
                $employmentEndDate = $emp['employment_end_date'];

                $effectiveStart = $employmentDate > $periodStart ? $employmentDate : $periodStart;
                $effectiveEnd = ($employmentEndDate !== null && $employmentEndDate < $periodEnd) ? $employmentEndDate : $periodEnd;

                $prorateDays = null;
                $prorateTotalDays = null;
                $effectiveBase = $baseSalary;
                if ($effectiveStart > $periodStart || $effectiveEnd < $periodEnd) {
                    $prorateTotalDays = $totalPeriodDays;
                    $prorateDays = (int)((strtotime($effectiveEnd) - strtotime($effectiveStart)) / 86400) + 1;
                    $prorateDays = max(0, min($prorateDays, $totalPeriodDays));
                    $effectiveBase = $prorateDays > 0 ? round($baseSalary * $prorateDays / $prorateTotalDays, 2) : 0.0;
                }

                $employeeFlags = [
                    'sso_enrolled' => (bool)$emp['sso_enrolled'],
                    'pvd_enrolled' => (bool)$emp['pvd_enrolled'],
                    'tax_exempt' => (bool)$emp['tax_exempt'],
                ];

                $errors = [];
                if ($baseSalary <= 0) {
                    $errors[] = 'missing_base_salary';
                }

                $earningLines = [];
                $deductionLines = [];

                // PED assignments: pick the earliest pending installment per assignment.
                $stmtPed = $this->db->prepare("SELECT eed.id AS assignment_id, i.id AS installment_id, i.amount,
                        pt.item_code, pt.item_name_th, pt.item_name_en, pt.item_type
                    FROM `employee_earning_deductions` eed
                    JOIN `payroll_earning_deduction_types` pt ON pt.id = eed.ped_type_id
                    JOIN `employee_earning_deduction_installments` i ON i.assignment_id = eed.id AND i.status = 'pending'
                    WHERE eed.employee_id = :employee_id AND eed.status = 'active' AND eed.deleted_at IS NULL
                    AND eed.effective_date <= :period_end
                    ORDER BY eed.id ASC, i.installment_no ASC");
                $stmtPed->execute([':employee_id' => $employeeId, ':period_end' => $periodEnd]);
                $pedSeen = [];
                foreach ($stmtPed->fetchAll(PDO::FETCH_ASSOC) as $ped) {
                    $assignmentId = (int)$ped['assignment_id'];
                    if (isset($pedSeen[$assignmentId])) {
                        continue; // only the first (earliest) pending installment per assignment
                    }
                    $pedSeen[$assignmentId] = true;
                    $line = [
                        'source' => 'ped',
                        'assignment_id' => $assignmentId,
                        'installment_id' => (int)$ped['installment_id'],
                        'code' => $ped['item_code'],
                        'name_th' => $ped['item_name_th'],
                        'name_en' => $ped['item_name_en'],
                        'amount' => (float)$ped['amount'],
                    ];
                    if ($ped['item_type'] === 'earning') {
                        $earningLines[] = $line;
                    } else {
                        $deductionLines[] = $line;
                    }
                }

                // Attendance bonus (only passed/locked entries for this period).
                $stmtBonus = $this->db->prepare("SELECT l.id AS ledger_id, l.amount, s.scheme_name
                    FROM `attendance_bonus_ledger` l
                    JOIN `attendance_bonus_schemes` s ON s.id = l.scheme_id
                    WHERE l.employee_id = :employee_id AND l.period_year = :year AND l.period_month = :month
                    AND l.status = 'passed'");
                $stmtBonus->execute([':employee_id' => $employeeId, ':year' => $periodYear, ':month' => $periodMonth]);
                foreach ($stmtBonus->fetchAll(PDO::FETCH_ASSOC) as $bonus) {
                    if ((float)$bonus['amount'] <= 0) {
                        continue;
                    }
                    $earningLines[] = [
                        'source' => 'attendance_bonus',
                        'ledger_id' => (int)$bonus['ledger_id'],
                        'code' => 'ATTENDANCE_BONUS',
                        'name_th' => $bonus['scheme_name'],
                        'name_en' => $bonus['scheme_name'],
                        'amount' => (float)$bonus['amount'],
                    ];
                }

                $earningTotal = array_sum(array_column($earningLines, 'amount'));
                $pedDeductionTotal = array_sum(array_column($deductionLines, 'amount'));
                $grossAmount = round($effectiveBase + $earningTotal, 2);

                // Statutory engine — see class docblock for the taxable_income simplification.
                $salaryContext = [
                    'basic_salary' => $effectiveBase,
                    'gross_salary' => $grossAmount,
                    'taxable_income' => round($grossAmount * 12, 2),
                    'net_income' => $grossAmount,
                ];
                $statutoryResult = $this->engine->calculate($compId, $salaryContext, $paymentDate, $employeeFlags);
                $statutoryEmployeeTotal = 0.0;
                $statutoryEmployerTotal = 0.0;
                foreach ($statutoryResult['items'] as $sItem) {
                    $statutoryEmployeeTotal += $sItem['employee_amount'];
                    $statutoryEmployerTotal += $sItem['employer_amount'];
                    // 'no_rate_configured' = a real gap in an otherwise-maintained rate timeline -- block the run.
                    // 'no_rate_ever_configured' = this item has zero rate history rows anywhere (not rolled out on
                    // this deployment yet, e.g. SG/MY/US items with no CPF/SOCSO/EPF rates entered) -- don't block
                    // payroll for every non-TH company over data nobody has entered yet; the line just computes to
                    // 0 with the note preserved in statutory_breakdown so it's still visible on the payslip/report.
                    if ($sItem['note'] === 'no_rate_configured') {
                        $errors[] = "no_rate_configured:{$sItem['code']}";
                    }
                }

                $totalDeductionAmount = round($pedDeductionTotal + $statutoryEmployeeTotal, 2);
                $netAmount = round($grossAmount - $totalDeductionAmount, 2);
                $calcStatus = empty($errors) ? 'calculated' : 'error';
                if ($calcStatus === 'error') {
                    $anyError = true;
                }

                $insStmt->execute([
                    ':run_id' => $id,
                    ':employee_id' => $employeeId,
                    ':base_salary_amount' => $effectiveBase,
                    ':prorate_days' => $prorateDays,
                    ':prorate_total_days' => $prorateTotalDays,
                    ':earning_breakdown' => json_encode($earningLines, JSON_UNESCAPED_UNICODE),
                    ':deduction_breakdown' => json_encode($deductionLines, JSON_UNESCAPED_UNICODE),
                    ':statutory_breakdown' => json_encode($statutoryResult['items'], JSON_UNESCAPED_UNICODE),
                    ':gross_amount' => $grossAmount,
                    ':total_deduction_amount' => $totalDeductionAmount,
                    ':net_amount' => $netAmount,
                    ':employer_cost_amount' => round($statutoryEmployerTotal, 2),
                    ':calc_status' => $calcStatus,
                    ':calc_errors' => empty($errors) ? null : implode(', ', $errors),
                ]);

                $totalGross += $grossAmount;
                $totalDeduction += $totalDeductionAmount;
                $totalNet += $netAmount;
            }

            $stmtRun = $this->db->prepare("UPDATE `payroll_runs` SET employee_count = :count,
                total_gross_amount = :gross, total_deduction_amount = :deduction, total_net_amount = :net,
                has_validation_errors = :has_errors, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmtRun->execute([
                ':count' => count($employees),
                ':gross' => round($totalGross, 2),
                ':deduction' => round($totalDeduction, 2),
                ':net' => round($totalNet, 2),
                ':has_errors' => $anyError ? 1 : 0,
                ':updated_by' => $userId,
                ':id' => $id,
            ]);
            $this->logAudit($id, 'draft', 'draft', 'recalculate', $userId, count($employees) . ' employee(s) calculated' . ($anyError ? ' (with errors)' : ''));
            if ($ownTransaction) { $this->db->commit(); }
            return ['status' => true, 'message' => 'Calculated successfully.', 'employee_count' => count($employees), 'has_validation_errors' => $anyError];
        } catch (PDOException $e) {
            if ($ownTransaction) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /* ==================== STATE TRANSITIONS ==================== */

    private function assertCalculationClean(int $id): ?string {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `payroll_run_details` WHERE run_id = :id AND calc_status != 'calculated'");
        $stmt->execute([':id' => $id]);
        $badCount = (int)$stmt->fetchColumn();
        if ($badCount > 0) {
            return "{$badCount} employee(s) have unresolved calculation errors. Recalculate and fix them before continuing.";
        }
        return null;
    }

    public function submit(int $id, int $compId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to submit this payroll run for approval.'];
        }
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run can be submitted.'];
        }
        if ((int)$run['employee_count'] <= 0) {
            return ['status' => false, 'message' => 'Cannot submit a payroll run with no employees. Recalculate first.'];
        }
        $err = $this->assertCalculationClean($id);
        if ($err !== null) {
            return ['status' => false, 'message' => $err];
        }
        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET state = 'pending_approval', submitted_at = CURRENT_TIMESTAMP,
            submitted_by = :submitted_by, updated_by = :submitted_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':submitted_by' => $userId, ':id' => $id]);
        $this->logAudit($id, 'draft', 'pending_approval', 'submit', $userId);
        return ['status' => true, 'message' => 'Submitted for approval.'];
    }

    public function revert(int $id, int $compId, int $userId, bool $isAdmin, ?string $note = null): array {
        if (!$this->userCan($userId, 'can_approve_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to send this payroll run back for revision.'];
        }
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'pending_approval') {
            return ['status' => false, 'message' => 'Only a payroll run pending approval can be reverted.'];
        }
        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET state = 'draft', updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':updated_by' => $userId, ':id' => $id]);
        $this->logAudit($id, 'pending_approval', 'draft', 'revert', $userId, $note);
        return ['status' => true, 'message' => 'Sent back for revision.'];
    }

    public function approve(int $id, int $compId, int $userId, bool $isAdmin, ?string $note = null): array {
        if (!$this->userCan($userId, 'can_approve_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to approve this payroll run.'];
        }
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'pending_approval') {
            return ['status' => false, 'message' => 'Only a payroll run pending approval can be approved.'];
        }
        $err = $this->assertCalculationClean($id);
        if ($err !== null) {
            return ['status' => false, 'message' => $err];
        }
        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET state = 'approved', approved_at = CURRENT_TIMESTAMP,
            approved_by = :approved_by, updated_by = :approved_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':approved_by' => $userId, ':id' => $id]);
        $this->logAudit($id, 'pending_approval', 'approved', 'approve', $userId, $note);
        return ['status' => true, 'message' => 'Approved.'];
    }

    public function reject(int $id, int $compId, int $userId, bool $isAdmin, string $reason): array {
        if (!$this->userCan($userId, 'can_approve_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to reject this payroll run.'];
        }
        if (trim($reason) === '') {
            return ['status' => false, 'message' => 'A reject reason is required.'];
        }
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'pending_approval') {
            return ['status' => false, 'message' => 'Only a payroll run pending approval can be rejected.'];
        }
        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET state = 'rejected', rejected_at = CURRENT_TIMESTAMP,
            rejected_by = :rejected_by, reject_reason = :reason, updated_by = :rejected_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':rejected_by' => $userId, ':reason' => trim($reason), ':id' => $id]);
        $this->logAudit($id, 'pending_approval', 'rejected', 'reject', $userId, $reason);
        return ['status' => true, 'message' => 'Rejected.'];
    }

    public function cancel(int $id, int $compId, int $userId, bool $isAdmin, string $reason): array {
        if (!$this->userCan($userId, 'can_approve_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to cancel this payroll run.'];
        }
        if (trim($reason) === '') {
            return ['status' => false, 'message' => 'A cancel reason is required.'];
        }
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        // Cancellable any time before money has actually moved -- draft/pending_approval/approved/
        // rejected. Once paid/locked, cancelling the *record* would be misleading (the payment
        // already happened); that needs a real reversal process, not a state flip, so it's
        // deliberately not allowed here.
        if (!in_array($run['state'], ['draft', 'pending_approval', 'approved', 'rejected'], true)) {
            return ['status' => false, 'message' => 'Only a payroll run that has not been paid yet can be cancelled.'];
        }
        $fromState = $run['state'];
        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET state = 'cancelled', cancelled_at = CURRENT_TIMESTAMP,
            cancelled_by = :cancelled_by, cancel_reason = :reason, updated_by = :cancelled_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':cancelled_by' => $userId, ':reason' => trim($reason), ':id' => $id]);
        $this->logAudit($id, $fromState, 'cancelled', 'cancel', $userId, $reason);
        return ['status' => true, 'message' => 'Cancelled.'];
    }

    public function reviseAfterReject(int $id, int $compId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to revise this payroll run.'];
        }
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'rejected') {
            return ['status' => false, 'message' => 'Only a rejected payroll run can be revised.'];
        }
        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET state = 'draft', updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':updated_by' => $userId, ':id' => $id]);
        $this->logAudit($id, 'rejected', 'draft', 'reviseAfterReject', $userId);
        return ['status' => true, 'message' => 'Reopened as draft for revision.'];
    }

    public function markPaid(int $id, int $compId, int $userId, bool $isAdmin, array $data): array {
        if (!$this->userCan($userId, 'can_finalize_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to mark this payroll run as paid.'];
        }
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'approved') {
            return ['status' => false, 'message' => 'Only an approved payroll run can be marked as paid.'];
        }
        $paymentMethod = $data['payment_method'] ?? 'bank_transfer';
        if (!in_array($paymentMethod, ['bank_transfer', 'cash', 'cheque'], true)) {
            return ['status' => false, 'message' => 'Invalid payment_method.'];
        }
        $paymentReference = !empty($data['payment_reference']) ? trim((string)$data['payment_reference']) : null;
        $actualPaymentDate = !empty($data['payment_date']) ? (string)$data['payment_date'] : $run['payment_date'];

        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) { $this->db->beginTransaction(); }

            $stmtDetails = $this->db->prepare("SELECT earning_breakdown, deduction_breakdown FROM `payroll_run_details` WHERE run_id = :id");
            $stmtDetails->execute([':id' => $id]);
            $installmentIds = [];
            $ledgerIds = [];
            foreach ($stmtDetails->fetchAll(PDO::FETCH_ASSOC) as $detail) {
                foreach (array_merge(json_decode((string)$detail['earning_breakdown'], true) ?? [], json_decode((string)$detail['deduction_breakdown'], true) ?? []) as $line) {
                    if (($line['source'] ?? '') === 'ped' && !empty($line['installment_id'])) {
                        $installmentIds[] = (int)$line['installment_id'];
                    }
                    if (($line['source'] ?? '') === 'attendance_bonus' && !empty($line['ledger_id'])) {
                        $ledgerIds[] = (int)$line['ledger_id'];
                    }
                }
            }

            if (!empty($installmentIds)) {
                $placeholders = implode(',', array_fill(0, count($installmentIds), '?'));
                $stmtInst = $this->db->prepare("UPDATE `employee_earning_deduction_installments`
                    SET status = 'processed', payroll_run_id = ?, processed_at = CURRENT_TIMESTAMP
                    WHERE id IN ({$placeholders}) AND status = 'pending'");
                $stmtInst->execute(array_merge([$id], $installmentIds));

                // Advance current_installment / complete the assignment where this was its last one.
                $stmtAssignments = $this->db->prepare("SELECT DISTINCT assignment_id FROM `employee_earning_deduction_installments` WHERE id IN ({$placeholders})");
                $stmtAssignments->execute($installmentIds);
                foreach ($stmtAssignments->fetchAll(PDO::FETCH_COLUMN) as $assignmentId) {
                    $this->db->prepare("UPDATE `employee_earning_deductions` SET current_installment = current_installment + 1 WHERE id = :id")->execute([':id' => $assignmentId]);
                    $stmtCheck = $this->db->prepare("SELECT current_installment, total_installments FROM `employee_earning_deductions` WHERE id = :id");
                    $stmtCheck->execute([':id' => $assignmentId]);
                    $a = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                    if ($a && (int)$a['current_installment'] >= (int)$a['total_installments']) {
                        $this->db->prepare("UPDATE `employee_earning_deductions` SET status = 'completed' WHERE id = :id")->execute([':id' => $assignmentId]);
                    }
                }
            }
            foreach (array_unique($ledgerIds) as $ledgerId) {
                $this->ledgerModel->lock($ledgerId, $compId, $userId);
            }

            $stmt = $this->db->prepare("UPDATE `payroll_runs` SET state = 'paid', paid_at = CURRENT_TIMESTAMP, paid_by = :paid_by,
                payment_method = :payment_method, payment_reference = :payment_reference, payment_date = :payment_date,
                updated_by = :paid_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([
                ':paid_by' => $userId, ':payment_method' => $paymentMethod, ':payment_reference' => $paymentReference,
                ':payment_date' => $actualPaymentDate, ':id' => $id,
            ]);
            $this->logAudit($id, 'approved', 'paid', 'markPaid', $userId, $paymentReference);
            if ($ownTransaction) { $this->db->commit(); }

            // Best-effort, outside the transaction: a slow/failing SMTP call must never roll back
            // the state change itself (that already committed) or block the API response longer
            // than necessary. Failures are logged per-employee in payslip_delivery_logs by the
            // service itself; nothing further to do with the summary here yet (no admin-facing
            // "last auto-send result" surface exists -- see payslip_delivery_logs for detail).
            try {
                (new PayslipDeliveryService($this->db))->autoSendForRun($compId, $id);
            } catch (Throwable $e) {
                // Swallow -- payroll state is already committed; auto-send is a side effect, not
                // a precondition of "marked as paid" succeeding.
            }

            return ['status' => true, 'message' => 'Marked as paid.'];
        } catch (PDOException $e) {
            if ($ownTransaction) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function lock(int $id, int $compId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'can_finalize_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to lock this payroll run.'];
        }
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'paid') {
            return ['status' => false, 'message' => 'Only a paid payroll run can be locked.'];
        }
        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET state = 'locked', locked_at = CURRENT_TIMESTAMP,
            locked_by = :locked_by, updated_by = :locked_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':locked_by' => $userId, ':id' => $id]);
        $this->logAudit($id, 'paid', 'locked', 'lock', $userId);
        return ['status' => true, 'message' => 'Locked.'];
    }
}
