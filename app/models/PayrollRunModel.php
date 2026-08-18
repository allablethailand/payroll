<?php
declare(strict_types=1);
require_once __DIR__ . '/AttendanceBonusLedgerModel.php';
require_once __DIR__ . '/../services/StatutoryCalculationEngine.php';
require_once __DIR__ . '/../services/PayslipDeliveryService.php';
require_once __DIR__ . '/../services/sync/MasterDataSyncOrchestrator.php';
require_once __DIR__ . '/PayrollSyncModel.php';

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

    // Off-cycle runs (cycleId === null) are deliberately exempt from this check -- there's no
    // cycle group to collide within, and an ad-hoc/out-of-cycle payment legitimately CAN share a
    // date range with a normal cycle's run (e.g. a one-off bonus run covering the same period).
    private function isDuplicatePeriod(int $compId, ?int $cycleId, string $start, string $end, ?int $excludeId): bool {
        if ($cycleId === null) {
            return false;
        }
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
        foreach (['run_name', 'payment_date'] as $field) {
            if (empty($data[$field])) {
                return ['status' => false, 'message' => "Missing required field: {$field}"];
            }
        }
        // cycle_id is optional -- an off-cycle/ad-hoc run (e.g. a one-off out-of-cycle payment, a
        // special bonus payout) isn't tied to any payroll_cycles config at all. A run pulled from
        // an Origami sync process is a different story: that data is inherently cycle-based, so
        // cycle_id is still required whenever sync_process_id is present (checked once both are
        // resolved, below).
        $cycleId = !empty($data['cycle_id']) ? (int)$data['cycle_id'] : null;
        if ($cycleId !== null) {
            $stmtCycle = $this->db->prepare("SELECT id FROM `payroll_cycles` WHERE id = :id AND comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL");
            $stmtCycle->execute([':id' => $cycleId, ':comp_id' => $compId]);
            if (!$stmtCycle->fetch()) {
                return ['status' => false, 'message' => 'Invalid or inactive payroll cycle.'];
            }
        }

        $payDate = (string)$data['payment_date'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $payDate)) {
            return ['status' => false, 'message' => 'Invalid date format, expected YYYY-MM-DD.'];
        }
        // Period start/end are only strictly required for a cycle-based run -- an off-cycle run
        // (cycle_id === null) doesn't always have a meaningful attendance period to speak of (e.g.
        // a special bonus payout), so per explicit request those two fields are optional there,
        // while payment_date always stays required regardless. When left blank on an off-cycle
        // run, both default to payment_date itself (a single-day "period") rather than being
        // stored as genuinely NULL -- recalculate()'s eligibility/pro-rate math and the date-range
        // filters on the process list both assume a real period range, and there's no product need
        // yet to teach every one of those a "no period at all" case just for this.
        $start = !empty($data['period_start_date']) ? (string)$data['period_start_date'] : null;
        $end = !empty($data['period_end_date']) ? (string)$data['period_end_date'] : null;
        if ($cycleId !== null && ($start === null || $end === null)) {
            return ['status' => false, 'message' => 'Missing required field: period_start_date/period_end_date'];
        }
        $start = $start ?? $payDate;
        $end = $end ?? $payDate;
        foreach ([$start, $end] as $d) {
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
            if ($cycleId === null) {
                return ['status' => false, 'message' => 'A payroll cycle is required when pulling from a sync process.'];
            }
        }

        // "Incentive/Other Payment" run purpose (per explicit request, 2026-08-19): a special
        // payment (e.g. a one-off incentive) that deliberately does NOT involve base salary --
        // only whatever specific earning/deduction items the admin picks per employee (see
        // joinEmployees()/addManualLine() below). Only makes sense for a genuine off-cycle run
        // (same gate as the manual employee roster) -- a cycle-based or Pending-Pull run is real
        // payroll by definition, so 'incentive' is rejected there rather than silently ignored.
        // compute_statutory is the admin's per-run choice (also confirmed explicit, 2026-08-19)
        // of whether this incentive should still go through SSO/PVD/tax -- but a normal 'payroll'
        // run must ALWAYS compute statutory; that's not something the UI is allowed to turn off,
        // so it's forced to true here regardless of what the request sent, not just hidden in the UI.
        $runPurpose = (string)($data['run_purpose'] ?? 'payroll') === 'incentive' ? 'incentive' : 'payroll';
        if ($runPurpose === 'incentive' && ($cycleId !== null || $syncProcessId !== null)) {
            return ['status' => false, 'message' => 'Incentive/Other Payment is only available for an off-cycle run with no payroll cycle selected.'];
        }
        $computeStatutory = $runPurpose === 'incentive' ? (!empty($data['compute_statutory']) ? 1 : 0) : 1;

        // Auto-sync Origami HR master data (department/position/shift/employee) right before
        // pulling this process into a run, so the user doesn't have to run "Sync Now" as a
        // separate manual step first -- per explicit request, to avoid doing the same job twice.
        // syncAllMasterData() never throws: a company not linked to Origami, or the sync client
        // not configured yet, comes back as a per-type failure result, not an exception -- so this
        // never blocks the pull itself, it's a best-effort freshen-up. Re-resolving unmapped rows
        // afterward is what actually benefits from any employee/department that just got synced.
        $syncSummary = null;
        if ($syncProcessId !== null) {
            $syncResults = (new MasterDataSyncOrchestrator($this->db))->syncAllMasterData($compId, $userId, 'manual');
            $syncModel = new PayrollSyncModel($this->db);
            $remappedCount = $syncModel->remapUnmappedItems($syncProcessId, $compId);
            // Whatever's still unmapped after a real Origami HR sync above gets a minimal
            // placeholder `employees` row created straight from this payload's own data (payroll_code
            // + payroll_sync_employee_status) -- per explicit clarification, this is NOT the same as
            // the Origami HR API sync above; see PayrollSyncModel's class docblock. This has to run
            // BEFORE applyEmployeeMasterFields() so newly-created employees also get their payment/
            // SSO/ID-card fields populated in this same pull, not just on some future pull.
            $placeholdersCreated = $syncModel->createPlaceholderEmployeesForUnmapped($syncProcessId, $compId, $userId);
            // Overwrites payment/SSO/ID-card fields on `employees` from this process's mapped rows
            // every pull -- per explicit request, treated as source-of-truth from Origami's own
            // already-approved payroll process, not "payroll-owned, default-once" like the rest of
            // employees' payment config. See PayrollSyncModel's class docblock for the full reasoning.
            $employeeFieldsUpdated = $syncModel->applyEmployeeMasterFields($syncProcessId, $compId, $userId);
            $syncSummary = ['results' => $syncResults, 'remapped_count' => $remappedCount,
                'placeholders_created' => $placeholdersCreated, 'employee_fields_updated' => $employeeFieldsUpdated];
        }

        $runName = trim((string)$data['run_name']);
        $notes = !empty($data['notes']) ? trim((string)$data['notes']) : null;

        $stmt = $this->db->prepare("INSERT INTO `payroll_runs`
            (comp_id, cycle_id, sync_process_id, run_purpose, compute_statutory, run_name, period_start_date, period_end_date, payment_date, state, notes, created_by)
            VALUES (:comp_id, :cycle_id, :sync_process_id, :run_purpose, :compute_statutory, :run_name, :start, :end, :pay_date, 'draft', :notes, :created_by)");
        $stmt->execute([
            ':comp_id' => $compId, ':cycle_id' => $cycleId, ':sync_process_id' => $syncProcessId,
            ':run_purpose' => $runPurpose, ':compute_statutory' => $computeStatutory, ':run_name' => $runName,
            ':start' => $start, ':end' => $end, ':pay_date' => $payDate,
            ':notes' => $notes, ':created_by' => $userId,
        ]);
        $runId = (int)$this->db->lastInsertId();
        $this->logAudit($runId, null, 'draft', 'create', $userId);
        $result = ['status' => true, 'message' => 'Created successfully.', 'id' => $runId];
        if ($syncSummary !== null) {
            $result['sync_summary'] = $syncSummary;
        }
        return $result;
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
            // sync_process_id = NULL unlinks this run from whatever payroll_sync_processes row it
            // was pulled from (per explicit request) -- pendingList()'s own query is just "no
            // payroll_runs row currently references this sync process", so clearing the FK here is
            // enough to make it reappear on the Pending Pull station, ready to be pulled again. Also
            // required to free up the UNIQUE constraint on sync_process_id for a future re-pull. A
            // no-op (NULL -> NULL) for a run that was never pulled from a sync process.
            $stmt = $this->db->prepare("UPDATE `payroll_runs` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by, sync_process_id = NULL WHERE id = :id");
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

        // Eligibility now branches by how this run's employee list is meant to be sourced (per
        // explicit request, 2026-08-19):
        //   - Pulled from Pending Pull (sync_process_id set): ONLY employees actually present in
        //     that sync payload (payroll_sync_items resolved to an employee) -- not the broader
        //     date-range membership below, which could silently include someone who merely happens
        //     to match dates but was never part of what Origami actually sent this time.
        //   - A normal cycle-based run (cycle_id set, no sync): employment date range overlapping
        //     this pay period, same as always -- see the paragraph below for the "status alone
        //     would wrongly exclude a leaver" and is_payroll_ready reasoning, both still apply here.
        //   - A genuine off-cycle run (cycle_id AND sync_process_id both NULL -- e.g. a one-off
        //     bonus payout): NO automatic membership at all. Only employees explicitly "Joined" via
        //     joinEmployees() (payroll_run_manual_employees) are included -- an ad-hoc special
        //     payment should never silently default to "everyone currently employed."
        if ($run['sync_process_id'] !== null) {
            $stmtEmp = $this->db->prepare("SELECT DISTINCT e.id, e.base_salary_amount, e.employment_date, e.employment_end_date,
                    e.sso_enrolled, e.pvd_enrolled, e.tax_exempt, e.is_payroll_ready
                FROM `payroll_sync_items` psi
                JOIN `employees` e ON e.id = psi.employee_id AND e.comp_id = :comp_id AND e.deleted_at IS NULL
                WHERE psi.process_id = :process_id AND psi.mapping_status = 'mapped'");
            $stmtEmp->execute([':comp_id' => $compId, ':process_id' => $run['sync_process_id']]);
        } elseif ($run['cycle_id'] !== null) {
            // Eligibility is based purely on the employment date range overlapping this pay period,
            // not on the current employee_status label — a "resigned" employee's status is usually
            // updated as soon as they leave, but their FINAL (partial) period still needs to be paid,
            // so status alone would wrongly exclude them from their own last run.
            // is_payroll_ready=0 (auto-provisioned via Origami SSO in auth/index.php, or auto-created
            // from a Payroll Sync payload in PayrollSyncModel::createPlaceholderEmployeesForUnmapped())
            // used to exclude these employees from the calculation table entirely. Per explicit request
            // (2026-08-19), that hid them from the run silently -- an admin had no way to see that
            // someone was missing and why. Now they're pulled into the table like anyone else, and
            // flagged below with calc_errors='profile_incomplete' (plus whatever else is actually
            // missing, e.g. missing_base_salary) so it's visible as a Remark instead of an absence.
            // assertCalculationClean() already blocks submit() while any row's calc_status isn't
            // 'calculated', so this can't reach approval half-finished -- completing the employee's
            // profile via the normal Employee edit form (which flips is_payroll_ready back to 1) and
            // recalculating is what clears it.
            $stmtEmp = $this->db->prepare("SELECT id, base_salary_amount, employment_date, employment_end_date,
                    sso_enrolled, pvd_enrolled, tax_exempt, is_payroll_ready
                FROM `employees`
                WHERE comp_id = :comp_id AND deleted_at IS NULL
                AND employment_date <= :period_end
                AND (employment_end_date IS NULL OR employment_end_date >= :period_start)");
            $stmtEmp->execute([':comp_id' => $compId, ':period_end' => $periodEnd, ':period_start' => $periodStart]);
        } else {
            $stmtEmp = $this->db->prepare("SELECT e.id, e.base_salary_amount, e.employment_date, e.employment_end_date,
                    e.sso_enrolled, e.pvd_enrolled, e.tax_exempt, e.is_payroll_ready
                FROM `payroll_run_manual_employees` pme
                JOIN `employees` e ON e.id = pme.employee_id AND e.comp_id = :comp_id AND e.deleted_at IS NULL
                WHERE pme.run_id = :run_id");
            $stmtEmp->execute([':comp_id' => $compId, ':run_id' => $id]);
        }
        $employees = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

        // "Incentive/Other Payment" runs (see create()'s docblock for the full reasoning) skip
        // base salary/proration, standing PED assignments, and attendance bonus entirely -- only
        // the manually-picked payroll_run_manual_lines for each employee count. Statutory is only
        // computed when the admin opted into it for this specific run (compute_statutory);
        // otherwise every line here is exactly what was picked, nothing withheld automatically.
        // A normal 'payroll' run's create() always forces compute_statutory=1, so this ternary
        // never actually skips statutory for real payroll.
        $isIncentive = ($run['run_purpose'] ?? 'payroll') === 'incentive';
        $computeStatutory = $isIncentive ? !empty($run['compute_statutory']) : true;

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

                $prorateDays = null;
                $prorateTotalDays = null;
                $effectiveBase = 0.0;
                if (!$isIncentive) {
                    $effectiveStart = $employmentDate > $periodStart ? $employmentDate : $periodStart;
                    $effectiveEnd = ($employmentEndDate !== null && $employmentEndDate < $periodEnd) ? $employmentEndDate : $periodEnd;
                    $effectiveBase = $baseSalary;
                    if ($effectiveStart > $periodStart || $effectiveEnd < $periodEnd) {
                        $prorateTotalDays = $totalPeriodDays;
                        $prorateDays = (int)((strtotime($effectiveEnd) - strtotime($effectiveStart)) / 86400) + 1;
                        $prorateDays = max(0, min($prorateDays, $totalPeriodDays));
                        $effectiveBase = $prorateDays > 0 ? round($baseSalary * $prorateDays / $prorateTotalDays, 2) : 0.0;
                    }
                }

                $employeeFlags = [
                    'sso_enrolled' => (bool)$emp['sso_enrolled'],
                    'pvd_enrolled' => (bool)$emp['pvd_enrolled'],
                    'tax_exempt' => (bool)$emp['tax_exempt'],
                ];

                $errors = [];
                if (empty($emp['is_payroll_ready'])) {
                    // Placeholder profile (SSO auto-provision or sync auto-create, see above) --
                    // flagged distinctly from missing_base_salary since a placeholder can have
                    // other missing required fields even if a salary happens to be filled in, and
                    // vice versa.
                    $errors[] = 'profile_incomplete';
                }
                if (!$isIncentive && $baseSalary <= 0) {
                    $errors[] = 'missing_base_salary';
                }

                $earningLines = [];
                $deductionLines = [];

                if ($isIncentive) {
                    // Manually-picked items only (see joinEmployees()/addManualLine() docblocks) --
                    // no standing PED assignments, no attendance bonus, nothing automatic.
                    $stmtLines = $this->db->prepare("SELECT pml.amount, pt.item_code, pt.item_name_th, pt.item_name_en, pt.item_type
                        FROM `payroll_run_manual_lines` pml
                        JOIN `payroll_earning_deduction_types` pt ON pt.id = pml.ped_type_id
                        WHERE pml.run_id = :run_id AND pml.employee_id = :employee_id");
                    $stmtLines->execute([':run_id' => $id, ':employee_id' => $employeeId]);
                    $manualLines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($manualLines as $line) {
                        $entry = [
                            'source' => 'manual_line',
                            'code' => $line['item_code'],
                            'name_th' => $line['item_name_th'],
                            'name_en' => $line['item_name_en'],
                            'amount' => (float)$line['amount'],
                        ];
                        if ($line['item_type'] === 'earning') {
                            $earningLines[] = $entry;
                        } else {
                            $deductionLines[] = $entry;
                        }
                    }
                    if (empty($manualLines)) {
                        // Joined but nothing picked yet -- almost certainly an oversight, same
                        // spirit as missing_base_salary for a normal payroll row.
                        $errors[] = 'no_manual_lines';
                    }
                } else {
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
                }

                $earningTotal = array_sum(array_column($earningLines, 'amount'));
                $pedDeductionTotal = array_sum(array_column($deductionLines, 'amount'));
                $grossAmount = round($effectiveBase + $earningTotal, 2);

                // Statutory engine — see class docblock for the taxable_income simplification.
                // Skipped entirely for an incentive run that opted out (compute_statutory=0): every
                // line is then exactly what was manually picked, nothing withheld automatically.
                $statutoryResult = ['items' => []];
                $statutoryEmployeeTotal = 0.0;
                $statutoryEmployerTotal = 0.0;
                if ($computeStatutory) {
                    $salaryContext = [
                        'basic_salary' => $effectiveBase,
                        'gross_salary' => $grossAmount,
                        'taxable_income' => round($grossAmount * 12, 2),
                        'net_income' => $grossAmount,
                    ];
                    $statutoryResult = $this->engine->calculate($compId, $salaryContext, $paymentDate, $employeeFlags);
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

    /* ==================== MANUAL EMPLOYEE ROSTER (off-cycle runs only) ==================== */

    /**
     * Guard shared by joinEmployees()/removeManualEmployee(): manual roster editing only makes
     * sense for a genuine off-cycle run (no cycle, no sync process) that's still draft -- a
     * cycle-based or Pending-Pull run's membership is derived automatically by recalculate()
     * itself (see its docblock), so there's nothing here for a human to curate.
     * @return array{0:?array,1:?string} [$run, $errorMessage] -- exactly one is non-null
     */
    private function assertManualRosterEditable(int $id, int $compId): array {
        $run = $this->get($id, $compId);
        if (!$run) {
            return [null, 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return [null, 'Only a draft payroll run can have its employee roster edited.'];
        }
        if ($run['cycle_id'] !== null || $run['sync_process_id'] !== null) {
            return [null, 'Manually adding/removing employees is only available for an off-cycle run with no payroll cycle selected.'];
        }
        return [$run, null];
    }

    /**
     * Adds one or more employees to a genuine off-cycle run's manual roster, then recalculates so
     * the calculation table reflects the change immediately (payroll_run_details is always a full
     * rebuild from current membership, same as any other recalculate() trigger -- there's no
     * separate "roster" vs "calculated" state to keep in sync by hand).
     * @param int[] $employeeIds
     */
    public function joinEmployees(int $id, int $compId, array $employeeIds, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        [$run, $err] = $this->assertManualRosterEditable($id, $compId);
        if ($err !== null) {
            return ['status' => false, 'message' => $err];
        }
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if (empty($employeeIds)) {
            return ['status' => false, 'message' => 'No employees selected.'];
        }

        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
        $stmtValid = $this->db->prepare("SELECT id FROM `employees` WHERE comp_id = ? AND deleted_at IS NULL AND id IN ({$placeholders})");
        $stmtValid->execute(array_merge([$compId], $employeeIds));
        $validIds = array_map('intval', $stmtValid->fetchAll(PDO::FETCH_COLUMN));
        if (empty($validIds)) {
            return ['status' => false, 'message' => 'None of the selected employees belong to this company.'];
        }

        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) { $this->db->beginTransaction(); }
            $ins = $this->db->prepare("INSERT IGNORE INTO `payroll_run_manual_employees` (run_id, employee_id, joined_by) VALUES (:run_id, :employee_id, :joined_by)");
            foreach ($validIds as $employeeId) {
                $ins->execute([':run_id' => $id, ':employee_id' => $employeeId, ':joined_by' => $userId]);
            }
            if ($ownTransaction) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }

        $recalcRes = $this->recalculate($id, $compId, $userId, $isAdmin);
        $recalcRes['joined_count'] = count($validIds);
        return $recalcRes;
    }

    /** Removes one employee from a genuine off-cycle run's manual roster, then recalculates. */
    public function removeManualEmployee(int $id, int $compId, int $employeeId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        [$run, $err] = $this->assertManualRosterEditable($id, $compId);
        if ($err !== null) {
            return ['status' => false, 'message' => $err];
        }
        $this->db->prepare("DELETE FROM `payroll_run_manual_employees` WHERE run_id = :run_id AND employee_id = :employee_id")
            ->execute([':run_id' => $id, ':employee_id' => $employeeId]);
        return $this->recalculate($id, $compId, $userId, $isAdmin);
    }

    /**
     * Server-side DataTables source for the "Join Employees" picker modal -- every employee in
     * this company NOT already on the run's manual roster, optionally filtered by department_id/
     * position_id and a free-text search. Deliberately a standalone query rather than reusing
     * EmployeeModel::list() -- "exclude whoever's already joined to run X" is specific to this one
     * picker, not a general employee-list concern.
     */
    public function manualEmployeeOptions(int $compId, int $runId, int $start, int $length, array $filters, string $search, string $lang = 'th'): array {
        $deptCol = $lang === 'en' ? 'department_name_en' : 'department_name_th';
        $posiCol = $lang === 'en' ? 'position_name_en' : 'position_name_th';

        $baseWhere = "e.comp_id = :comp_id AND e.deleted_at IS NULL
            AND NOT EXISTS (SELECT 1 FROM `payroll_run_manual_employees` pme WHERE pme.run_id = :run_id AND pme.employee_id = e.id)";
        $params = [':comp_id' => $compId, ':run_id' => $runId];
        if (!empty($filters['department_id'])) {
            $baseWhere .= " AND e.department_id = :department_id";
            $params[':department_id'] = (int)$filters['department_id'];
        }
        if (!empty($filters['position_id'])) {
            $baseWhere .= " AND e.position_id = :position_id";
            $params[':position_id'] = (int)$filters['position_id'];
        }

        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `employees` e WHERE {$baseWhere}");
        $totalStmt->execute($params);
        $recordsTotal = (int)$totalStmt->fetchColumn();

        $whereSql = $baseWhere;
        if ($search !== '') {
            $whereSql .= " AND (e.employee_no LIKE :search1 OR e.name_th LIKE :search2 OR e.surname_th LIKE :search3 OR e.name_en LIKE :search4 OR e.surname_en LIKE :search5)";
            for ($i = 1; $i <= 5; $i++) {
                $params[":search{$i}"] = "%{$search}%";
            }
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `employees` e WHERE {$whereSql}");
        $countStmt->execute($params);
        $recordsFiltered = (int)$countStmt->fetchColumn();

        $dataSql = "SELECT e.id, e.employee_no,
                    CONCAT(e.name_th, ' ', e.surname_th) AS name_th, CONCAT(e.name_en, ' ', e.surname_en) AS name_en,
                    COALESCE(d.{$deptCol}, '') AS department, COALESCE(p.{$posiCol}, '') AS position,
                    e.employment_date
                FROM `employees` e
                LEFT JOIN `structure_departments` d ON e.department_id = d.id
                LEFT JOIN `structure_positions` p ON e.position_id = p.id
                WHERE {$whereSql}
                ORDER BY e.employee_no ASC
                LIMIT :start, :length";
        $stmt = $this->db->prepare($dataSql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':start', $start, PDO::PARAM_INT);
        $stmt->bindValue(':length', $length, PDO::PARAM_INT);
        $stmt->execute();

        return ['total' => $recordsTotal, 'filtered' => $recordsFiltered, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    /**
     * Guard shared by addManualLine()/removeManualLine(): only makes sense for a run that's both
     * a genuine off-cycle run (see assertManualRosterEditable()) AND specifically run_purpose=
     * 'incentive' -- a normal 'payroll' off-cycle run (e.g. a final settlement run) still goes
     * through the standard base-salary/PED/statutory pipeline in recalculate(), it has no concept
     * of a manually-picked line to add.
     * @return array{0:?array,1:?string}
     */
    private function assertManualLinesEditable(int $id, int $compId): array {
        [$run, $err] = $this->assertManualRosterEditable($id, $compId);
        if ($err !== null) {
            return [null, $err];
        }
        if (($run['run_purpose'] ?? 'payroll') !== 'incentive') {
            return [null, 'Manually adding earning/deduction items is only available for an Incentive/Other Payment run.'];
        }
        return [$run, null];
    }

    /**
     * Adds one earning/deduction line (item + amount) for one employee on an 'incentive' run, per
     * explicit request (2026-08-19): "pick item + enter the amount separately per person" -- not
     * one flat amount applied to everyone. Recalculates immediately after, same as joinEmployees().
     */
    public function addManualLine(int $id, int $compId, int $employeeId, int $pedTypeId, float $amount, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        [$run, $err] = $this->assertManualLinesEditable($id, $compId);
        if ($err !== null) {
            return ['status' => false, 'message' => $err];
        }
        if ($amount <= 0) {
            return ['status' => false, 'message' => 'Amount must be greater than 0.'];
        }
        $stmtEmp = $this->db->prepare("SELECT id FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
        if (!$stmtEmp->fetch()) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }
        // is_sync_only items are meant to be written only by whatever automated flow owns them --
        // not something an admin hand-picks into an ad-hoc incentive line.
        $stmtPed = $this->db->prepare("SELECT id FROM `payroll_earning_deduction_types`
            WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL AND status = 'active' AND is_sync_only = 0");
        $stmtPed->execute([':id' => $pedTypeId, ':comp_id' => $compId]);
        if (!$stmtPed->fetch()) {
            return ['status' => false, 'message' => 'Invalid earning/deduction item.'];
        }

        $this->db->prepare("INSERT INTO `payroll_run_manual_lines` (run_id, employee_id, ped_type_id, amount, created_by)
            VALUES (:run_id, :employee_id, :ped_type_id, :amount, :created_by)")
            ->execute([':run_id' => $id, ':employee_id' => $employeeId, ':ped_type_id' => $pedTypeId, ':amount' => $amount, ':created_by' => $userId]);

        return $this->recalculate($id, $compId, $userId, $isAdmin);
    }

    /** Removes one manually-added line, then recalculates. */
    public function removeManualLine(int $id, int $compId, int $lineId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        [$run, $err] = $this->assertManualLinesEditable($id, $compId);
        if ($err !== null) {
            return ['status' => false, 'message' => $err];
        }
        $this->db->prepare("DELETE FROM `payroll_run_manual_lines` WHERE id = :line_id AND run_id = :run_id")
            ->execute([':line_id' => $lineId, ':run_id' => $id]);
        return $this->recalculate($id, $compId, $userId, $isAdmin);
    }

    /** Every manual line for one employee on this run (item code/name + amount + line id), for the "Manage Items" UI. */
    public function manualLinesForEmployee(int $compId, int $runId, int $employeeId): array {
        $stmt = $this->db->prepare("SELECT pml.id, pml.amount, pt.item_code, pt.item_name_th, pt.item_name_en, pt.item_type
            FROM `payroll_run_manual_lines` pml
            JOIN `payroll_earning_deduction_types` pt ON pt.id = pml.ped_type_id
            JOIN `payroll_runs` r ON r.id = pml.run_id AND r.comp_id = :comp_id
            WHERE pml.run_id = :run_id AND pml.employee_id = :employee_id
            ORDER BY pml.id ASC");
        $stmt->execute([':comp_id' => $compId, ':run_id' => $runId, ':employee_id' => $employeeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
        // sync_process_id = NULL -- same reasoning as delete() above: returns this run's source
        // payroll_sync_processes row (if any) to the Pending Pull station instead of leaving it
        // permanently consumed by a cancelled run. No-op for a standalone (non-sync) run.
        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET state = 'cancelled', cancelled_at = CURRENT_TIMESTAMP,
            cancelled_by = :cancelled_by, cancel_reason = :reason, updated_by = :cancelled_by, updated_at = CURRENT_TIMESTAMP,
            sync_process_id = NULL WHERE id = :id");
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
