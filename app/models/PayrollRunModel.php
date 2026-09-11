<?php
declare(strict_types=1);
require_once __DIR__ . '/EmployeeModel.php';
require_once __DIR__ . '/../services/StatutoryCalculationEngine.php';
require_once __DIR__ . '/../services/ThPitCalculator.php';
require_once __DIR__ . '/../services/SyncPayResolver.php';
require_once __DIR__ . '/../services/TransactionDataPayAdapter.php';
require_once __DIR__ . '/../services/PayslipDeliveryService.php';
require_once __DIR__ . '/../services/sync/MasterDataSyncOrchestrator.php';
require_once __DIR__ . '/PayrollSyncModel.php';
require_once __DIR__ . '/SetupRulesModel.php';
require_once __DIR__ . '/ApprovalRequestModel.php';
require_once __DIR__ . '/PermissionModel.php';
require_once __DIR__ . '/EmployeeRecurringEarningModel.php';
require_once __DIR__ . '/EmployeeRecurringDeductionModel.php';
require_once __DIR__ . '/AttendanceDeductionRuleModel.php';
require_once __DIR__ . '/OtRateSetModel.php';
require_once __DIR__ . '/PayrollPolicyModel.php';
require_once __DIR__ . '/NonResidentTaxSettingModel.php';
require_once __DIR__ . '/NotificationModel.php';
require_once __DIR__ . '/AttendanceRecordModel.php';
require_once __DIR__ . '/DocumentNumberingModel.php';
require_once __DIR__ . '/EmployeePaymentMethodModel.php';
require_once __DIR__ . '/PvdEmployerRateLadderModel.php';

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
    private StatutoryCalculationEngine $engine;
    private ThPitCalculator $thPitCalculator;
    private SyncPayResolver $syncPayResolver;
    private SetupRulesModel $setupRulesModel;
    private EmployeeRecurringEarningModel $recurringEarningModel;
    private EmployeeRecurringDeductionModel $recurringDeductionModel;
    private AttendanceDeductionRuleModel $attendanceDeductionRuleModel;
    private OtRateSetModel $otRateSetModel;
    private PayrollPolicyModel $policyModel;
    private NonResidentTaxSettingModel $nonResidentTaxSettingModel;
    private AttendanceRecordModel $attendanceRecordModel;
    private EmployeePaymentMethodModel $paymentMethodModel;
    private PvdEmployerRateLadderModel $pvdLadderModel;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->engine = new StatutoryCalculationEngine($this->db);
        $this->thPitCalculator = new ThPitCalculator($this->db, $this->engine);
        $this->syncPayResolver = new SyncPayResolver($this->db);
        $this->setupRulesModel = new SetupRulesModel($this->db);
        $this->recurringEarningModel = new EmployeeRecurringEarningModel($this->db);
        $this->recurringDeductionModel = new EmployeeRecurringDeductionModel($this->db);
        $this->attendanceDeductionRuleModel = new AttendanceDeductionRuleModel($this->db);
        $this->otRateSetModel = new OtRateSetModel($this->db);
        $this->policyModel = new PayrollPolicyModel($this->db);
        $this->nonResidentTaxSettingModel = new NonResidentTaxSettingModel($this->db);
        $this->attendanceRecordModel = new AttendanceRecordModel($this->db);
        $this->paymentMethodModel = new EmployeePaymentMethodModel($this->db);
        $this->pvdLadderModel = new PvdEmployerRateLadderModel($this->db);
    }

    /* ==================== READ ==================== */

    /**
     * cancelled_from_state (2026-08-22, explicit request: "หน้า Process List อยากให้เพิ่มอีก Column
     * เป็น Timeline ย่อๆ") -- the List page's new compact per-row timeline needs to know which
     * spine state a cancelled run was cancelled FROM to branch the widget correctly, the same way
     * detail.js's full-size timeline already does via cancelledFromState(run.audit_log) -- but
     * list() rows don't carry the full audit log (only get() does, and fetching it per row here
     * would be needlessly heavy for a list). This one small read-only subquery is purely additive:
     * no existing column, write path, or other caller of list() is affected.
     */
    /**
     * $actingEmployeeId/$isAdmin are optional (default: skip the per-row flag entirely, existing
     * callers unaffected) -- when passed, each row gets a `can_approve_payroll` boolean so the
     * Approval Queue page can hide the Approve/Reject/Request Info buttons on a row the viewer
     * isn't actually eligible to decide (2026-08-23, follow-up to the department-scoping fix in
     * canApproveThisRun()/approvalFlow() -- those buttons used to be safe to show unconditionally
     * on every pending_approval row since ANY can_approve_payroll holder could act on ANY run;
     * now that eligibility is per-row (submitter's department), showing them regardless would be
     * misleading -- clickable-looking buttons that the backend then correctly refuses). Computed
     * inline against the already-JOINed submitter.department_id instead of calling
     * canApproveThisRun() per row -- that method does its own DB round-trip per call, which would
     * be an N+1 query here; list() already has the one column it needs.
     *
     * $approvalQueueOnly (2026-08-24, explicit bug report: a user with no eligible approval step
     * on a run -- or eligible only on a step a joint peer had already decided, or one still locked
     * -- was still SEEING that row in the Approval Queue, can_approve_payroll flag aside; the
     * button being hidden wasn't enough, the row itself shouldn't be there) -- when true (the
     * Approval Queue page only, NOT the general Process List, which must keep showing every run
     * regardless of who can approve it), drops any `pending_approval` row whose computed
     * can_approve_payroll came back false. Only `pending_approval` rows are filtered -- approved/
     * rejected/need_info rows stay visible to anyone with page access same as before, both because
     * they're shown for status-tracking/history (not "things to act on"), and because their
     * can_approve_payroll flag intentionally means something different at that point (gates Undo
     * Decision via the coarser "was ever eligible" check, not "actionable now" -- see
     * canApproveThisRun()'s own docblock). Requires $actingEmployeeId (silently a no-op without one
     * -- there's nothing to filter against).
     */
    public function list(int $compId, array $filters = [], ?int $actingEmployeeId = null, bool $isAdmin = false, bool $approvalQueueOnly = false): array {
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
                    submitter.name_th AS submitted_by_name_th, submitter.name_en AS submitted_by_name_en,
                    submitter.department_id AS submitted_by_department_id,
                    -- 2026-08-29, explicit request: 'ช่วยเพิ่ม Column ว่า Update ข้อมูลล่าสุดเมื่อไหร่ และใครเป็น
                    -- คน Update' -- updated_at/updated_by themselves are already genuine, wired-through
                    -- columns on this table (every mutating method already sets both -- recalculate(),
                    -- submit/approve/reject/cancel/markPaid/lock, run-settings save, etc.), just never
                    -- resolved to a display name/exposed as their own List columns until now.
                    updater.name_th AS updated_by_name_th, updater.name_en AS updated_by_name_en,
                    -- Batch 3A item 4: the List page's own Updated By cell shows a clickable
                    -- employee avatar now (app.js's apvAvatarHtml(..., {employeeId})), so it needs
                    -- this employee's photo path too, not just their name.
                    updater.profile_photo_path AS updated_by_profile_photo_path,
                    (SELECT from_state FROM `payroll_run_audit_logs` WHERE run_id = r.id AND action = 'cancel' ORDER BY id DESC LIMIT 1) AS cancelled_from_state,
                    -- 2026-08-29 ('ต้องดึงไปแสดงผลในหน้า List ด้วยว่า Verify ไปแล้วกี่คน') -- Lock retired
                    -- 2026-08-31 (see EMPLOYEE VERIFY / LOCK / COMMENTS section below), Verify itself
                    -- now carries what Lock used to; locked_employee_count dropped, nothing reads it.
                    (SELECT COUNT(*) FROM `payroll_run_employee_verifications` WHERE run_id = r.id AND is_verified = 1) AS verified_employee_count,
                    -- 2026-08-29, explicit request: 'ถ้าข้อมูลไม่สมบูรณ์ให้มีบอกด้วย ว่าไม่สมบูรณ์กี่คน'
                    -- (indicate how many employees have incomplete data) -- calc_status='error' on
                    -- payroll_run_details is the existing per-line marker recalculate() already sets
                    -- when a line couldn't be fully computed (e.g. no rate configured); this just
                    -- surfaces the count on the List page instead of only inside Run Detail.
                    (SELECT COUNT(*) FROM `payroll_run_details` WHERE run_id = r.id AND calc_status = 'error') AS error_employee_count,
                    -- 2026-09-01, explicit request: 'หน้า List page ควรมี indicator บอกด้วยว่ารอบนี้ตั้งค่าไว้ให้
                    -- ไปรวมกับรอบไหน' -- r.* above already carries the raw merge_target_run_id, but never a
                    -- human-readable name; same LEFT JOIN get() already has, just also surfaced here so the
                    -- List page's own badge (mergeTargetIconPr() in index.js) doesn't need a 2nd round trip
                    -- per row. run_code (2026-09-02, 'และถ้ามีการอ้างอิงถึงรอบก็ให้แสดงด้วยครับ') added the same
                    -- way, once payroll_runs itself gained a run_code column -- shown alongside the name so
                    -- the List page's own Code column can display which round's CODE a reference points at,
                    -- not just its free-text name.
                    mt.run_name AS merge_target_run_name, mt.run_code AS merge_target_run_code,
                    -- 2026-09-06, same reasoning as get()'s own version just above.
                    mtc.cycle_name AS merge_target_cycle_name, mtc.status AS merge_target_cycle_status
                FROM `payroll_runs` r
                LEFT JOIN `payroll_cycles` c ON c.id = r.cycle_id
                LEFT JOIN `employees` creator ON creator.id = r.created_by
                LEFT JOIN `employees` submitter ON submitter.id = r.submitted_by
                LEFT JOIN `employees` updater ON updater.id = r.updated_by
                LEFT JOIN `payroll_runs` mt ON mt.id = r.merge_target_run_id AND mt.deleted_at IS NULL
                LEFT JOIN `payroll_cycles` mtc ON mtc.id = r.merge_target_cycle_id
                {$where}
                ORDER BY r.period_start_date DESC, r.id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2026-08-23, simplified to delegate straight to canApproveThisRun() per row (same single
        // source of truth approve()/reject()/revert() themselves use) instead of a separate
        // parallel computation here -- that method now already branches on approval_request_id
        // (Approval Workflow engine) vs. the flat department-scoped fallback on its own. One extra
        // DB round-trip per row (department lookup, or an engine step lookup) is a bounded cost
        // for an approval queue's typical row count, and worth it to guarantee this list can never
        // drift out of sync with what approve()/reject() will actually allow.
        if ($actingEmployeeId !== null) {
            foreach ($rows as &$row) {
                $row['can_approve_payroll'] = $this->canApproveThisRun($actingEmployeeId, $isAdmin, $row);
            }
            unset($row);

            if ($approvalQueueOnly) {
                $rows = array_values(array_filter($rows, function (array $row): bool {
                    return $row['state'] !== 'pending_approval' || $row['can_approve_payroll'];
                }));
            }
        }

        return $rows;
    }

    /** Select2-ajax-shaped list of runs, for report generation pickers etc. -- $excludeId (2026-09-01,
     * added for the Detail page's own "Target Round" merge-target picker) leaves out one specific
     * run id, same generic `data-exclude-id` convention this app's other select2-remote fields
     * already use (e.g. #manualLinePayeeEmployee), so a run never gets offered as its own merge
     * target in the first place. */
    public function options(int $compId, string $search, int $page, int $limit, ?array $allowedStates = null, ?int $excludeId = null): array {
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
        if ($excludeId !== null) {
            $where .= ' AND id != :exclude_id';
            $params[':exclude_id'] = $excludeId;
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
        // sp.run_kind/process_subject/process_start/process_end/process_paid (2026-08-29, see
        // PAYROLL_SYNC_API.md) -- exposed here so the Detail page can (a) show which Origami cycle
        // this run was pulled from and what it actually said, and (b) know whether this specific
        // sync-linked run is 'supplemental' (eligible to edit run_purpose/compute_statutory/
        // include_base_salary/include_standing_items, same as a genuine off-cycle run) or 'regular'
        // (always full payroll, not editable) -- see update()'s own use of sync_run_kind below.
        $sql = "SELECT r.*, c.cycle_name, c.payroll_frequency,
                    sp.run_kind AS sync_run_kind, sp.process_subject AS sync_process_subject,
                    sp.process_start AS sync_process_start, sp.process_end AS sync_process_end,
                    sp.process_paid AS sync_process_paid,
                    creator.name_th AS created_by_name_th, creator.name_en AS created_by_name_en,
                    creator.profile_photo_path AS created_by_profile_photo_path,
                    submitter.name_th AS submitted_by_name_th, submitter.name_en AS submitted_by_name_en,
                    -- 2026-09-10, Batch 3A item 3: the Approval Timeline modal's own Paid/Locked
                    -- stations need who+photo, same as creator/approvers already have -- paid_by/
                    -- locked_by were never JOINed before (the old merged Paid/Locked stage box only
                    -- ever showed a date, never a name).
                    payer.name_th AS paid_by_name_th, payer.name_en AS paid_by_name_en,
                    payer.profile_photo_path AS paid_by_profile_photo_path,
                    locker.name_th AS locked_by_name_th, locker.name_en AS locked_by_name_en,
                    locker.profile_photo_path AS locked_by_profile_photo_path,
                    mt.run_name AS merge_target_run_name, mt.state AS merge_target_run_state, mt.run_code AS merge_target_run_code,
                    -- 2026-09-06: name for the waiting-on-a-future-cycle-period banner (see
                    -- resolveMergeTargetSpec()'s own docblock) -- merge_target_run_id/merge_target_cycle_id
                    -- are mutually exclusive, so at most one of mt.*/mtc.* is ever non-null on a given row.
                    -- mtc.status (2026-09-06, real gap found and fixed): create() requires a target
                    -- cycle to be status='active' to create a NEW run against it at all, so a waiting
                    -- spec whose cycle has since been deactivated/deleted is a genuine dead end --
                    -- exposed here so the UI can warn distinctly instead of showing waiting forever
                    -- for a round that will never come (same category as the Origami-attribution
                    -- target_rejected status, see PayrollSyncModel::attributionTargetStatus()).
                    mtc.cycle_name AS merge_target_cycle_name, mtc.status AS merge_target_cycle_status,
                    -- 2026-09-11, Batch 3C item 5, explicit instruction: the Detail page's Third-
                    -- Party Remittance tab hides itself entirely when a run has no remittance rows
                    -- (see updateRunDetailTabVisibility() in detail.js) -- unlike cash/bank-transfer
                    -- payment counts (already derivable from `r.details`' own payment_method_code
                    -- per employee, no new field needed there), remittances live in their own table
                    -- with nothing reachable from getDetails() at all, so this needs a real count.
                    (SELECT COUNT(*) FROM `payroll_remittances` pr WHERE pr.run_id = r.id) AS remittance_count
                FROM `payroll_runs` r
                LEFT JOIN `payroll_cycles` c ON c.id = r.cycle_id
                LEFT JOIN `payroll_sync_processes` sp ON sp.id = r.sync_process_id
                LEFT JOIN `employees` creator ON creator.id = r.created_by
                LEFT JOIN `employees` submitter ON submitter.id = r.submitted_by
                LEFT JOIN `employees` payer ON payer.id = r.paid_by
                LEFT JOIN `employees` locker ON locker.id = r.locked_by
                LEFT JOIN `payroll_runs` mt ON mt.id = r.merge_target_run_id AND mt.deleted_at IS NULL
                LEFT JOIN `payroll_cycles` mtc ON mtc.id = r.merge_target_cycle_id
                WHERE r.id = :id AND r.comp_id = :comp_id AND r.deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getDetails(int $runId, int $compId): array {
        $run = $this->get($runId, $compId);
        if (!$run) {
            return [];
        }
        // 2026-08-29: is_verified + who/when, LEFT JOINed since most employees have no row in
        // payroll_run_employee_verifications at all (see that table's own docblock -- a row only
        // exists while the flag is true) -- COALESCE to 0/false for everyone else. is_locked/
        // locked_at/locked_by dropped 2026-08-31 -- see this method's own EMPLOYEE VERIFY / LOCK /
        // COMMENTS section header comment for why Lock was retired entirely.
        $sql = "SELECT d.*, e.employee_no, e.name_th, e.surname_th, e.name_en, e.surname_en, e.department_id,
                    -- Batch 3A item 4: the Detail page's own employee table shows an avatar next to
                    -- the name now (clickable, app.js's apvAvatarHtml(..., {employeeId})).
                    e.profile_photo_path,
                    -- 2026-08-31, explicit request: checkbox filter (before the employee table) +
                    -- 2 summary cards + a dedicated tab all keyed on payment method -- previously
                    -- only PayrollReportDataModel::getRunDetails() (reports/cash-payment tab) read
                    -- this column; the Process Detail page's own #tb_run_detail never had it.
                    -- 2026-09-02, follow-up: the legacy payment_type enum (bank/cash only) is gone --
                    -- resolved via payment_method_id/master_payment_methods instead, same join
                    -- PayrollReportDataModel::getRunDetails() already uses, exposed here as
                    -- payment_method_code (transfer/cash/check/mixed).
                    COALESCE(pmt.code, 'transfer') AS payment_method_code,
                    -- 2026-09-11, Batch 3C item 7: employee table's new Department column -- the
                    -- employee's CURRENT department (there is no separate department snapshot on
                    -- payroll_run_details itself, same live-employee-record source e.department_id
                    -- above already reads from).
                    dept.department_name_th, dept.department_name_en,
                    COALESCE(v.is_verified, 0) AS is_verified, v.verified_at,
                    vu.name_th AS verified_by_name_th, vu.name_en AS verified_by_name_en,
                    -- 2026-08-29: comment count shown as a notification badge on the Comment button
                    (SELECT COUNT(*) FROM `payroll_run_employee_comments` c WHERE c.run_id = d.run_id AND c.employee_id = d.employee_id) AS comment_count,
                    -- 2026-08-29, explicit follow-up request (own earlier suggestion, accepted): a
                    -- small icon per customization surface this page has (line/base-salary
                    -- override-or-exclude, and tax/SSO override), rendered as small badges on the
                    -- row so an admin can tell at a glance without opening each employee's own modal.
                    (SELECT COUNT(*) FROM `payroll_run_line_overrides` lo WHERE lo.run_id = d.run_id AND lo.employee_id = d.employee_id) AS line_override_count,
                    -- 2026-09-10, Batch 3A item 5: the Adjusted-N badge counts overrides AND ad-hoc
                    -- added items together (both count as an adjustment made to this employee's
                    -- calculation), so this needs its own count alongside line_override_count.
                    (SELECT COUNT(*) FROM `payroll_run_manual_lines` pml WHERE pml.run_id = d.run_id AND pml.employee_id = d.employee_id) AS manual_line_count,
                    (SELECT 1 FROM `payroll_run_employee_exemptions` ex WHERE ex.run_id = d.run_id AND ex.employee_id = d.employee_id
                        AND (ex.tax_calculate_override != 'inherit' OR ex.sso_calculate_override != 'inherit') LIMIT 1) AS has_calc_override,
                    -- 2026-08-29, explicit follow-up request: base salary excluded should show as a
                    -- red 'not calculated' label instead of 0 in the employee table -- effective
                    -- exclusion state for base salary specifically, same per-employee-override-wins-
                    -- over-run-default resolution recalculate() itself uses (see that method's own
                    -- docblock), computed fresh here rather than persisted -- always in sync since a
                    -- Run Settings or per-employee save always recalculates immediately anyway.
                    (SELECT lo2.action FROM `payroll_run_line_overrides` lo2 WHERE lo2.run_id = d.run_id AND lo2.employee_id = d.employee_id AND lo2.item_code = :base_salary_code LIMIT 1) AS base_salary_override_action,
                    EXISTS(SELECT 1 FROM `payroll_run_item_exclusions` rie WHERE rie.run_id = d.run_id AND rie.item_code = :base_salary_code2) AS run_excludes_base_salary
                FROM `payroll_run_details` d
                JOIN `employees` e ON e.id = d.employee_id
                LEFT JOIN `master_payment_methods` pmt ON pmt.id = e.payment_method_id
                LEFT JOIN `structure_departments` dept ON dept.id = e.department_id
                LEFT JOIN `payroll_run_employee_verifications` v ON v.run_id = d.run_id AND v.employee_id = d.employee_id
                LEFT JOIN `employees` vu ON vu.id = v.verified_by
                WHERE d.run_id = :run_id
                ORDER BY e.employee_no ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':run_id' => $runId, ':base_salary_code' => self::BASE_SALARY_OVERRIDE_CODE, ':base_salary_code2' => self::BASE_SALARY_OVERRIDE_CODE]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // 2026-09-06, explicit request: display Origami's opt-in TOTAL_DAYS item_values entry
        // (item_type='INFO', calendar-based day count, announced same day) per employee on this
        // page. Origami only sends it for a sync-based run whose admin ticked it on in Report
        // Items -- there's no equivalent for a cycle-based/off-cycle run at all, so `total_days`
        // stays null (not 0 -- SyncPayResolver::extractInfoItemValue()'s own docblock: null means
        // "no data," never treat as zero) whenever there's nothing to look up. One extra query
        // (not a JOIN in the main SELECT above) since `payroll_sync_items` is keyed by this run's
        // OWN sync_process_id + employee_id, a different join shape than every other column in
        // this method's main query.
        $totalDaysByEmployee = [];
        if ($run['sync_process_id'] !== null && !empty($rows)) {
            $stmtSync = $this->db->prepare("SELECT employee_id, item_values FROM `payroll_sync_items`
                WHERE process_id = :process_id AND employee_id IS NOT NULL");
            $stmtSync->execute([':process_id' => $run['sync_process_id']]);
            foreach ($stmtSync->fetchAll(PDO::FETCH_ASSOC) as $syncRow) {
                $itemValues = json_decode((string)$syncRow['item_values'], true);
                $totalDays = SyncPayResolver::extractInfoItemValue(['item_values' => is_array($itemValues) ? $itemValues : []], 'TOTAL_DAYS');
                if ($totalDays !== null) {
                    $totalDaysByEmployee[(int)$syncRow['employee_id']] = $totalDays;
                }
            }
        }
        foreach ($rows as &$row) {
            $row['earning_breakdown'] = json_decode((string)$row['earning_breakdown'], true) ?? [];
            $row['deduction_breakdown'] = json_decode((string)$row['deduction_breakdown'], true) ?? [];
            $row['statutory_breakdown'] = json_decode((string)$row['statutory_breakdown'], true) ?? [];
            $row['is_verified'] = (bool)$row['is_verified'];
            $row['line_override_count'] = (int)$row['line_override_count'];
            $row['manual_line_count'] = (int)$row['manual_line_count'];
            $row['has_calc_override'] = !empty($row['has_calc_override']);
            // 2026-09-10, real gap found and fixed (confirmed business rule): this used to check
            // only the per-employee override + Run Settings item-exclusion -- it had NO awareness
            // at all of an incentive run's own include_base_salary=0 toggle, which zeroes
            // effectiveBase through a COMPLETELY SEPARATE code path in recalculate() (see that
            // method's own `$effectiveBase = $includeBaseSalary ? $baseSalary : 0.0` branch for
            // $isIncentive, well before the override/exclusion resolution runs). An incentive run
            // with include_base_salary unchecked and no override/Run-Settings-exclusion configured
            // on top genuinely has base_salary_amount=0 for the same "intentionally not included"
            // reason as the other 2 mechanisms, but this flag stayed false for it, so the table
            // showed a plain grey "0.00" instead of the red "Not Calculated" label. See
            // isBaseSalaryExcluded()'s own docblock for the full 3-way resolution this now shares
            // with PayrollReportDataModel::getRunDetails() (used by PayrollRegisterReport's Excel/
            // PDF export of this exact same table).
            $row['base_salary_excluded'] = self::isBaseSalaryExcluded(
                $row['base_salary_override_action'],
                !empty($row['run_excludes_base_salary']),
                (string)($run['run_purpose'] ?? 'payroll'),
                !empty($run['include_base_salary'])
            );
            unset($row['base_salary_override_action'], $row['run_excludes_base_salary']);
            $row['total_days'] = $totalDaysByEmployee[(int)$row['employee_id']] ?? null;
        }
        return $rows;
    }

    /**
     * 2026-08-30 (Phase 8, T041, real gap found and fixed): a sync-based run's employee membership
     * is (by design, see the eligibility query in recalculate()'s own comment) ONLY whoever Origami
     * actually sent this time (plus anyone manually joined on top) -- an employee who would
     * otherwise be expected in payroll (active, is_payroll_participant, employment date range
     * overlapping the period, same cycle_id-or-unassigned rule as the T041 cross-cycle-leakage fix
     * above) but simply wasn't in this sync payload is silently absent from the run with no visible
     * sign anything is missing. This is a reconciliation check, purely informational (never blocks
     * anything) -- returns who's missing so an admin at cutoff can tell "Origami hasn't sent
     * everyone yet" apart from "this really is everyone this period" before submitting for approval.
     * Returns [] for any run that isn't sync-based (nothing to reconcile against for a cycle-based
     * or off-cycle run, whose membership rules are different).
     * @return array<int,array{id:int,employee_no:string,name_th:string,surname_th:string,name_en:string,surname_en:string}>
     */
    public function syncMissingEmployees(int $runId, int $compId): array {
        $run = $this->get($runId, $compId);
        if (!$run || $run['sync_process_id'] === null) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT e.id, e.employee_no, e.name_th, e.surname_th, e.name_en, e.surname_en
            FROM `employees` e
            WHERE e.comp_id = :comp_id AND e.deleted_at IS NULL AND e.is_payroll_participant = 1
            AND e.employment_date <= :period_end
            AND (e.employment_end_date IS NULL OR e.employment_end_date >= :period_start)
            AND (e.cycle_id IS NULL OR e.cycle_id = :cycle_id)
            AND NOT EXISTS (SELECT 1 FROM `payroll_sync_items` psi WHERE psi.process_id = :process_id AND psi.employee_id = e.id AND psi.mapping_status = 'mapped')
            AND NOT EXISTS (SELECT 1 FROM `payroll_run_manual_employees` pme WHERE pme.run_id = :run_id AND pme.employee_id = e.id)
            AND NOT EXISTS (SELECT 1 FROM `payroll_run_excluded_employees` pex WHERE pex.run_id = :run_id2 AND pex.employee_id = e.id)
            ORDER BY e.employee_no ASC");
        $stmt->execute([
            ':comp_id' => $compId, ':period_end' => $run['period_end_date'], ':period_start' => $run['period_start_date'],
            ':cycle_id' => $run['cycle_id'], ':process_id' => $run['sync_process_id'],
            ':run_id' => $runId, ':run_id2' => $runId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 2026-08-29, explicit request: "ตรงที่ปริ้น Slip ของพนักงาน ปรับให้ขึ้นเป็นรายชื่อพนักงานมาเลย และ emp
     * code ด้วย แผนกตำแหน่งทีม" -- a lean roster (employee_no/name/department/position/team only) for
     * the Reports page's Pay Slip picker, deliberately NOT reusing getDetails() (that method carries
     * a lot of payroll-calculation-specific data -- earning/deduction/statutory breakdowns, verify/
     * lock state, override counts -- none of which a "pick an employee to download their slip"
     * picker needs). Scoped to employees who actually have a payroll_run_details row for this run
     * (i.e. were genuinely calculated/included), same as getDetails()'s own employee set.
     */
    public function employeeRosterForReports(int $runId, int $compId): array {
        if (!$this->get($runId, $compId)) {
            return [];
        }
        $sql = "SELECT e.id AS employee_id, e.employee_no, e.name_th, e.surname_th, e.name_en, e.surname_en,
                    d.department_name_th, d.department_name_en,
                    p.position_name_th, p.position_name_en,
                    tm.team_name_th, tm.team_name_en
                FROM `payroll_run_details` rd
                JOIN `employees` e ON e.id = rd.employee_id
                LEFT JOIN `structure_departments` d ON e.department_id = d.id
                LEFT JOIN `structure_positions` p ON e.position_id = p.id
                LEFT JOIN `structure_teams` tm ON e.team_id = tm.id
                WHERE rd.run_id = :run_id
                ORDER BY e.employee_no ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':run_id' => $runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 2026-08-29, explicit request: "ถ้าข้อมูลไม่สมบูรณ์ให้มีบอกด้วย ว่าไม่สมบูรณ์กี่คนและมีปุ่ม i ให้คลิก
     * ดูรายละเอียดในหน้ารายการได้เลย" -- backs the List page's "i" info button. Deliberately a
     * separate, narrow, on-demand lookup (not part of list()'s own per-row query) so viewing the
     * whole List doesn't have to fetch every erroring employee for every run up front -- only the
     * one run the admin actually clicked "i" on.
     */
    public function errorEmployeesForRun(int $runId, int $compId): array {
        if (!$this->get($runId, $compId)) {
            return [];
        }
        $sql = "SELECT e.employee_no, e.name_th, e.surname_th, e.name_en, e.surname_en, d.calc_errors
                FROM `payroll_run_details` d
                JOIN `employees` e ON e.id = d.employee_id
                WHERE d.run_id = :run_id AND d.calc_status = 'error'
                ORDER BY e.employee_no ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':run_id' => $runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 2026-08-29, explicit follow-up request: "ถ้ารอบนั้นไม่ส่งภาษี ไม่ส่งประกันสังคมครบทุกคน ในการออก
     * Report ของรอบนั้นก็จะมีแค่ไฟล์นำส่งธนาคาร" -- whether AT LEAST ONE employee in this run actually
     * has tax/SSO calculated, once the SAME per-employee-override-wins-over-run-default resolution
     * recalculate() itself uses (see that method's own docblock) is applied. Confirmed via
     * AskUserQuestion: only the tax/SSO-specific statutory reports (TH_PND1/TH_SSO110 shortcut
     * dropdown on Process Detail) hide when the corresponding flag here is false -- Payslip/Payment
     * Voucher/Payroll Register/Bank Transfer File are untouched, none of them depend on tax/SSO at
     * all. Expressed as ONE aggregate SQL query (not a per-employee PHP loop) so it's cheap enough
     * to call on every Process Detail page load.
     */
    public function calcApplicabilitySummary(int $runId, int $compId): array {
        if (!$this->get($runId, $compId)) {
            return ['any_tax' => true, 'any_sso' => true];
        }
        $sql = "SELECT
                MAX(CASE
                    WHEN COALESCE(ex.tax_calculate_override, 'inherit') = 'yes' THEN 1
                    WHEN COALESCE(ex.tax_calculate_override, 'inherit') = 'no' THEN 0
                    WHEN cs.tax_calculate_default = 'yes' THEN 1
                    WHEN cs.tax_calculate_default = 'no' THEN 0
                    WHEN e.tax_exempt = 0 THEN 1
                    ELSE 0
                END) AS any_tax,
                MAX(CASE
                    WHEN COALESCE(ex.sso_calculate_override, 'inherit') = 'yes' THEN 1
                    WHEN COALESCE(ex.sso_calculate_override, 'inherit') = 'no' THEN 0
                    WHEN cs.sso_calculate_default = 'yes' THEN 1
                    WHEN cs.sso_calculate_default = 'no' THEN 0
                    WHEN e.sso_enrolled = 1 THEN 1
                    ELSE 0
                END) AS any_sso
            FROM `payroll_run_details` d
            JOIN `employees` e ON e.id = d.employee_id
            LEFT JOIN `payroll_run_employee_exemptions` ex ON ex.run_id = d.run_id AND ex.employee_id = d.employee_id
            LEFT JOIN `payroll_run_calc_settings` cs ON cs.run_id = d.run_id
            WHERE d.run_id = :run_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':run_id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['any_tax'] === null) {
            // No employees calculated yet -- fail open (show every report) rather than hide
            // everything before there's anything to base the decision on.
            return ['any_tax' => true, 'any_sso' => true];
        }
        return ['any_tax' => (bool)$row['any_tax'], 'any_sso' => (bool)$row['any_sso']];
    }

    /**
     * 2026-08-31, same-day follow-up (item 9c): excludes action='view_detail' rows on purpose.
     * Those rows exist (see logViewDetail()) so item 10's audit/analysis report and any future
     * forensic need has a real record of every page open -- but this method is what feeds the
     * Detail page's own human-facing "Audit Log" timeline tab, and a new row every single time
     * ANYONE opens/refreshes the page would flood that timeline with noise nobody asked to see
     * there, drowning out the real state-transition/edit history it exists to show. Use
     * getViewLog() (below) to read the raw view rows for reporting purposes instead.
     */
    public function getAuditLog(int $runId, int $compId): array {
        if (!$this->get($runId, $compId)) {
            return [];
        }
        // 2026-09-11, Batch 3C item 2, explicit instruction: the Action History tab's own actor line
        // now renders avatar+name (apvPersonLineHtml(), same as the Approval Timeline modal) instead
        // of plain text, clickable through to the employee quick-view modal -- needs the photo path
        // alongside the name fields this query already joined.
        $sql = "SELECT a.*, e.name_th AS performed_by_name_th, e.name_en AS performed_by_name_en,
                    e.profile_photo_path AS performed_by_profile_photo_path
                FROM `payroll_run_audit_logs` a
                LEFT JOIN `employees` e ON e.id = a.performed_by
                WHERE a.run_id = :run_id AND a.action != 'view_detail' ORDER BY a.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':run_id' => $runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Raw view_detail rows for a run -- see getAuditLog()'s own docblock for why those are kept
     *  out of that method. Used by the item-10 audit/analysis report, not the Detail page itself. */
    public function getViewLog(int $runId, int $compId): array {
        if (!$this->get($runId, $compId)) {
            return [];
        }
        $sql = "SELECT a.*, e.name_th AS performed_by_name_th, e.name_en AS performed_by_name_en
                FROM `payroll_run_audit_logs` a
                LEFT JOIN `employees` e ON e.id = a.performed_by
                WHERE a.run_id = :run_id AND a.action = 'view_detail' ORDER BY a.id ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':run_id' => $runId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 2026-08-31, same-day follow-up (item 10, "Design ให้หน่อยครับ No Idea"): the date the
     * override-history table went live -- payroll_run_line_override_history only ever records edits
     * made from this date forward (see that table's own migration comment for why: the tables it
     * replaced only ever stored the CURRENT value per line, never a structured before/after chain).
     * A run created before this date can genuinely have zero history rows even if it WAS manually
     * edited -- the audit report must say "no edit history available (feature started {this date})"
     * for such a run, never a misleading "never edited" claim it can't actually back up.
     */
    public const LINE_OVERRIDE_HISTORY_FEATURE_START_DATE = '2026-08-31';

    /**
     * List view for the new "Payroll Run Audit" report (Reports menu) -- every run for this
     * company with its origin, state, key actors/dates, and how many manual edits (of any kind --
     * earning/deduction/statutory line overrides or attendance overrides) it has on record, so an
     * admin can spot which runs to actually drill into via lineOverrideAuditDiff() below.
     */
    /**
     * 2026-08-31, same-day follow-up, explicit request: "อยากให้เพิ่ม Filter ด้วยครับ" --
     * date_from/date_to filter on period_start_date/period_end_date, same convention every other
     * List page's own Date From/To filter box already uses (e.g. PayrollRunModel::list() itself).
     * `state` is deliberately NOT filtered here server-side -- the page's own pipeline station bar
     * filters by state entirely client-side (same "load once, filter via DataTables ext.search"
     * pattern the Payroll Process List page's own station cards already use), since this list is
     * small (one company's own runs) and the station bar needs live per-state counts anyway, which
     * a server-side-only filter would require a second query for.
     */
    public function runAuditList(int $compId, ?string $dateFrom = null, ?string $dateTo = null): array {
        $where = "WHERE r.comp_id = :comp_id AND r.deleted_at IS NULL";
        $params = [':comp_id' => $compId];
        if ($dateFrom !== null && $dateFrom !== '') {
            $where .= " AND r.period_end_date >= :date_from";
            $params[':date_from'] = $dateFrom;
        }
        if ($dateTo !== null && $dateTo !== '') {
            $where .= " AND r.period_start_date <= :date_to";
            $params[':date_to'] = $dateTo;
        }
        $sql = "SELECT r.id, r.run_name, r.state, r.period_start_date, r.period_end_date, r.payment_date,
                    r.run_purpose, r.total_gross_amount, r.total_deduction_amount, r.total_net_amount,
                    c.cycle_name,
                    sp.run_kind AS sync_run_kind, sp.process_subject AS sync_process_subject,
                    submitter.name_th AS submitted_by_name_th, submitter.name_en AS submitted_by_name_en,
                    r.submitted_at, r.approved_at, r.paid_at,
                    (SELECT COUNT(*) FROM `payroll_run_line_override_history` WHERE run_id = r.id) AS edit_count
                FROM `payroll_runs` r
                LEFT JOIN `payroll_cycles` c ON c.id = r.cycle_id
                LEFT JOIN `payroll_sync_processes` sp ON sp.id = r.sync_process_id
                LEFT JOIN `employees` submitter ON submitter.id = r.submitted_by
                {$where}
                ORDER BY r.period_start_date DESC, r.id DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map(function (array $row): array {
            // origin: 'sync' (pulled from Origami) / 'cycle' (regular cycle-based run, never
            // sync-pulled) / 'manual' (off-cycle/incentive run, no cycle at all) -- same 3-way split
            // already established by this app's other origin-facing surfaces (e.g. Employee/
            // attendance data_source).
            $row['origin'] = $row['sync_run_kind'] !== null ? 'sync' : ($row['cycle_name'] !== null ? 'cycle' : 'manual');
            $row['edit_count'] = (int)$row['edit_count'];
            $row['history_available'] = $row['period_start_date'] >= self::LINE_OVERRIDE_HISTORY_FEATURE_START_DATE
                || $row['edit_count'] > 0; // a run with real recorded edits obviously has history, regardless of its own period date
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Drill-down diff view for one run: per (employee, line), an ORIGINAL -> Edit 1 -> Edit 2 ->
     * ... -> CURRENT chain, sourced from payroll_run_line_override_history. "Original" is the
     * oldest recorded old_value for that line (or, if a line was never overridden, simply equals
     * "Current" -- both null-safe, both read the same live figure, see below). "Current" is always
     * read LIVE from the run's own current calculated state (via currentLineAmount()/
     * attendanceDataForEmployee()), never assumed from the last history row's own new_value --
     * a later recalculate() can still shift a downstream figure (e.g. a statutory line recomputing
     * after an attendance override changes taxable income) independent of any further manual edit,
     * and Current must always reflect truth, not staleness.
     *
     * Returns ['history_available' => bool, 'lines' => [...]] -- lines is empty (not an error) for
     * a run with zero recorded edits; the caller is responsible for showing the
     * LINE_OVERRIDE_HISTORY_FEATURE_START_DATE caveat when history_available is false.
     *
     * 2026-09-10, Batch 3A item 5: gained an optional $employeeId filter (generalized, not
     * duplicated) so employeeAdjustments() below can reuse this exact same diff-chain logic scoped
     * to one employee for the Detail page's per-employee "adjusted items" modal, instead of the
     * Payroll Run Audit report's whole-run list this method originally served alone.
     */
    public function lineOverrideAuditDiff(int $runId, int $compId, ?int $employeeId = null): array {
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['history_available' => false, 'lines' => []];
        }
        $historyAvailable = (string)$run['period_start_date'] >= self::LINE_OVERRIDE_HISTORY_FEATURE_START_DATE;

        $where = "WHERE h.run_id = :run_id";
        $params = [':run_id' => $runId];
        if ($employeeId !== null) {
            $where .= " AND h.employee_id = :employee_id";
            $params[':employee_id'] = $employeeId;
        }
        $stmt = $this->db->prepare("SELECT h.*, e.employee_no, e.name_th AS employee_name_th, e.name_en AS employee_name_en,
                u.name_th AS changed_by_name_th, u.name_en AS changed_by_name_en
            FROM `payroll_run_line_override_history` h
            JOIN `employees` e ON e.id = h.employee_id
            LEFT JOIN `employees` u ON u.id = h.changed_by
            {$where}
            ORDER BY h.employee_id ASC, h.line_type ASC, h.item_code ASC, h.id ASC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $historyAvailable = true; // real recorded edits exist regardless of the run's own period date
        }

        $groups = [];
        foreach ($rows as $r) {
            $key = $r['employee_id'] . '|' . $r['line_type'] . '|' . $r['item_code'];
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'employee_id' => (int)$r['employee_id'],
                    'employee_no' => $r['employee_no'],
                    'employee_name_th' => $r['employee_name_th'], 'employee_name_en' => $r['employee_name_en'],
                    'line_type' => $r['line_type'], 'item_code' => $r['item_code'],
                    'original_value' => $r['old_value'] !== null ? (float)$r['old_value'] : null,
                    'edits' => [],
                ];
            }
            $groups[$key]['edits'][] = [
                'action' => $r['action'],
                'old_value' => $r['old_value'] !== null ? (float)$r['old_value'] : null,
                'new_value' => $r['new_value'] !== null ? (float)$r['new_value'] : null,
                'note' => $r['note'],
                'changed_by' => (int)$r['changed_by'],
                'changed_by_name_th' => $r['changed_by_name_th'], 'changed_by_name_en' => $r['changed_by_name_en'],
                'changed_at' => $r['changed_at'],
            ];
        }

        foreach ($groups as &$g) {
            if ($g['line_type'] === 'attendance') {
                $live = $this->attendanceDataForEmployee($compId, $runId, $g['employee_id']);
                $g['current_value'] = $live['override'][$g['item_code']] ?? $live['synced'][$g['item_code']] ?? null;
            } elseif ($g['line_type'] === 'statutory') {
                $g['current_value'] = $this->currentLineAmount($runId, $g['employee_id'], $this->statutoryOverrideCode($g['item_code']));
            } else {
                $g['current_value'] = $this->currentLineAmount($runId, $g['employee_id'], $g['item_code']);
            }
        }
        unset($g);

        return ['history_available' => $historyAvailable, 'lines' => array_values($groups)];
    }

    /* ==================== EMPLOYEE VERIFY / COMMENTS (2026-08-29, Lock retired 2026-08-31) ====================
       Explicit request (2026-08-29): "อยากให้มีปุ่ม Verify ของแต่ละคน และสามารถ Lock Unlock ได้ โดยถ้า Lock
       แล้วข้อมูลจะไม่คำนวณใหม่...สามารถมี checkbox เลือกได้ทีละหลายคน...รวมถึงเพิ่มให้สามารถใส่ Comment ได้ของแต่ละ
       คน...เป็น Timeline...ใส่ tag ได้ว่า กำลังดำเนินการ ดำเนินการเสร็จแล้ว มีข้อผิดพลาด". Verify and Lock
       originally lived as 2 INDEPENDENT flags -- 2026-08-31, explicit follow-up: "ให้ตัดปุ่ม Lock ออกไป
       เลยครับ ให้เหลือแค่ Verify ถ้า Verify แล้ว จะไม่คำนวณอีกต่อไป" -- Lock is retired entirely (both the
       column and the concept); Verify itself now carries what Lock used to: recalculate() preserves
       a verified employee's payroll_run_details row byte-for-byte instead of recomputing it (see
       recalculate()'s own prefetch/branch), AND every other per-employee mutation entry point on
       this page refuses to edit a verified employee at all -- see isEmployeeVerifiedForRun()'s
       callers below (renamed from isEmployeeLockedForRun()). `payroll_run_employee_verifications`
       had its own is_locked/locked_by/locked_at columns dropped in the same migration that shipped
       this change (2026-08-31_20_drop_employee_lock.sql) -- a row now only exists while is_verified
       is true (deleted outright when unverified, same "no all-zero row" convention
       payroll_run_employee_exemptions already uses). Comments (`payroll_run_employee_comments`) are
       unaffected -- a separate, append-only per-employee timeline, NOT gated by run state. ==================== */

    /** Shared guard used by every per-employee mutation entry point on a draft run (manual lines,
     *  line overrides, attendance overrides, per-run exemptions) -- a verified employee's numbers
     *  must stay frozen exactly as they are, so nothing that would trigger a recompute is allowed to
     *  touch them at all. Renamed from isEmployeeLockedForRun() 2026-08-31 -- Verify now carries
     *  this behavior, Lock no longer exists. */
    private function isEmployeeVerifiedForRun(int $runId, int $employeeId): bool {
        $stmt = $this->db->prepare("SELECT is_verified FROM `payroll_run_employee_verifications` WHERE run_id = :run_id AND employee_id = :employee_id");
        $stmt->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        return (bool)$stmt->fetchColumn();
    }

    public function setEmployeeVerified(int $runId, int $compId, int $employeeId, bool $verified, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run\'s employees can be verified.'];
        }
        $stmtEmp = $this->db->prepare("SELECT employee_no FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $employeeNo = $stmtEmp->fetchColumn();
        if ($employeeNo === false) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }

        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            if (!$verified) {
                $this->db->prepare("DELETE FROM `payroll_run_employee_verifications` WHERE run_id = :run_id AND employee_id = :employee_id")
                    ->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
            } else {
                // Re-verifying an already-verified employee still refreshes verified_at/verified_by
                // to the current user/time -- treated as "re-confirming", not a no-op, matching how
                // the user would read clicking the button again anyway.
                $stmt = $this->db->prepare("INSERT INTO `payroll_run_employee_verifications`
                        (run_id, employee_id, is_verified, verified_by, verified_at)
                    VALUES (:run_id, :employee_id, 1, :verified_by, :verified_at)
                    ON DUPLICATE KEY UPDATE is_verified = 1, verified_by = VALUES(verified_by), verified_at = VALUES(verified_at)");
                $stmt->execute([
                    ':run_id' => $runId, ':employee_id' => $employeeId,
                    ':verified_by' => $userId, ':verified_at' => date('Y-m-d H:i:s'),
                ]);
            }

            $actionWord = $verified ? 'verified' : 'unverified';
            $this->logAudit($runId, 'draft', 'draft', "employee_{$actionWord}", $userId, "Employee {$employeeNo}: {$actionWord}.");
            if ($own) { $this->db->commit(); }
            return ['status' => true, 'message' => 'Saved successfully.'];
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /** @param array<int> $employeeIds */
    public function bulkSetEmployeeVerified(int $runId, int $compId, array $employeeIds, bool $verified, int $userId, bool $isAdmin): array {
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));
        if (empty($employeeIds)) {
            return ['status' => false, 'message' => 'No employees selected.'];
        }
        $own = !$this->db->inTransaction();
        $succeeded = 0;
        $failed = [];
        try {
            if ($own) { $this->db->beginTransaction(); }
            foreach ($employeeIds as $employeeId) {
                $res = $this->setEmployeeVerified($runId, $compId, $employeeId, $verified, $userId, $isAdmin);
                if (!empty($res['status'])) {
                    $succeeded++;
                } else {
                    $failed[] = ['employee_id' => $employeeId, 'message' => $res['message'] ?? 'Failed.'];
                }
            }
            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
        return [
            'status' => $succeeded > 0,
            'message' => "{$succeeded} employee(s) updated" . (empty($failed) ? '.' : ('; ' . count($failed) . ' failed.')),
            'succeeded' => $succeeded, 'failed' => $failed,
        ];
    }

    /**
     * 2026-08-31, explicit request: "สามารถ Verify ทั้ง Process ได้เลย เพราะตอนนี้มีแค่รายพนักงาน กับรายการที่
     * เลือก ให้ Verify ได้ทั้ง Process ทั้ง Detail และหน้า List" -- verifies every employee currently in
     * this run's own payroll_run_details in one call, reusing bulkSetEmployeeVerified() (same
     * per-employee validation/audit-log/skip-on-failure behavior, just with the full roster instead
     * of a hand-picked subset) -- callable from the List page (a run_id alone, no Detail page
     * required) as well as the Detail page's own bulk bar.
     */
    public function verifyAllEmployeesForRun(int $runId, int $compId, int $userId, bool $isAdmin): array {
        $stmt = $this->db->prepare("SELECT employee_id FROM `payroll_run_details` WHERE run_id = :run_id");
        $stmt->execute([':run_id' => $runId]);
        $employeeIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (empty($employeeIds)) {
            return ['status' => false, 'message' => 'This payroll run has no calculated employees yet.'];
        }
        return $this->bulkSetEmployeeVerified($runId, $compId, $employeeIds, true, $userId, $isAdmin);
    }

    /**
     * 2026-08-29, explicit follow-up request: "ถ้าการดำเนินเสร็จแล้ว Comment ดูได้เท่านั้น ไม่สามารถเพิ่ม
     * แก้ไข ลบได้" -- deliberately NOT the same cutoff as "View Mode" elsewhere on this page
     * (currentRun.state !== 'draft', which also covers pending_approval/approved/rejected/
     * need_info) -- comments are a running reminder log meant to stay usable WHILE a run is still
     * actively moving through approval/back-and-forth ("ไว้เตือนตัวเอง"), so they only actually lock
     * once the run has genuinely finished: paid (money has moved), locked (sealed), or cancelled
     * (nothing more will ever happen to it). rejected/need_info are explicitly excluded -- those are
     * still "in progress" states the run can be resubmitted from.
     */
    private const COMMENT_LOCKED_STATES = ['paid', 'locked', 'cancelled'];
    /** Reserved item_code for lineOverrideSave()'s own base-salary special case -- see that method's own docblock. */
    public const BASE_SALARY_OVERRIDE_CODE = '__base_salary__';

    /**
     * 2026-09-10, real gap found and fixed (confirmed business rule): the single source of truth
     * for "is base salary effectively excluded from this employee's calculation for a reason,
     * not genuinely zero" -- shared between getDetails() (below, backs the on-screen Employee
     * Breakdown table's red "Not Calculated" label) and PayrollReportDataModel::getRunDetails()
     * (backs PayrollRegisterReport's Excel/PDF export of that exact same table), so the two can
     * never drift apart on which rows count as excluded.
     *
     * 3 independent reasons, mirroring recalculate()'s own real resolution order exactly (see that
     * method's own `$effectiveBase` assignment for $isIncentive vs. the per-employee-override/
     * Run-Settings-exclusion block further down):
     *   1. A per-employee override (`payroll_run_line_overrides`, item_code=BASE_SALARY_OVERRIDE_CODE)
     *      set to 'exclude' -- always wins over everything else, even for a normal run.
     *   2. No override at all ('override_amount' also counts as "no override" here -- a real
     *      value IS in effect, that's the opposite of excluded) AND this run's own Run Settings
     *      item-exclusion list (`payroll_run_item_exclusions`) contains the base-salary sentinel.
     *   3. No override, no Run Settings exclusion, AND this is an incentive run
     *      (`run_purpose='incentive'`) that never opted into `include_base_salary` -- functionally
     *      identical to reasons 1/2 (base salary genuinely wasn't brought into this employee's
     *      calculation, on purpose) even though it comes from recalculate()'s own separate
     *      $isIncentive/$includeBaseSalary branch, not either of the other 2 tables at all. A
     *      normal (non-incentive) run always has run_purpose='payroll', so this 3rd clause is a
     *      guaranteed no-op for it regardless of whatever include_base_salary happens to hold.
     */
    public static function isBaseSalaryExcluded(?string $overrideAction, bool $runExcludesBaseSalary, string $runPurpose, bool $includeBaseSalary): bool {
        if ($overrideAction === 'exclude') {
            return true;
        }
        if ($overrideAction !== null) {
            return false; // 'override_amount' -- a real overridden value is in effect, not an exclusion
        }
        if ($runExcludesBaseSalary) {
            return true;
        }
        return $runPurpose === 'incentive' && !$includeBaseSalary;
    }

    /**
     * 2026-08-31: same "reserved sentinel, never collides with a company's own free-text catalog
     * item_code" precedent as BASE_SALARY_OVERRIDE_CODE above -- wraps a statutory item code
     * (TH_SSO/TH_PVD/TH_PIT/etc.) before it's used as the item_code key in
     * `payroll_run_line_overrides` (a table shared with earning/deduction/base-salary overrides).
     * Used by both statutoryLineOverrideSave()/statutoryLineOverrideRemove() (writing) and
     * recalculate()'s own statutory block (reading) -- always call this, never hand-build the
     * wrapped string, so the two sides can never drift out of sync.
     */
    public function statutoryOverrideCode(string $statutoryItemCode): string {
        return '__statutory_' . strtoupper(trim($statutoryItemCode)) . '__';
    }

    /** Verified/locked counts for the run list page ("ต้องดึงไปแสดงผลในหน้า List ด้วยว่า Verify ไปแล้ว
     *  กี่คน Lock ข้อมูลแล้วกี่คน") -- see list()'s own new subqueries below for the actual per-run count. */
    public function employeeCommentAdd(int $runId, int $compId, int $employeeId, ?string $tag, string $comment, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to comment on this payroll run.'];
        }
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if (in_array($run['state'], self::COMMENT_LOCKED_STATES, true)) {
            return ['status' => false, 'message' => 'This payroll run has finished processing -- comments are view-only.'];
        }
        $comment = trim($comment);
        if ($comment === '') {
            return ['status' => false, 'message' => 'Comment text is required.'];
        }
        if ($tag !== null && !in_array($tag, ['in_progress', 'completed', 'error'], true)) {
            return ['status' => false, 'message' => 'Invalid tag.'];
        }
        $stmtEmp = $this->db->prepare("SELECT employee_no FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
        if ($stmtEmp->fetchColumn() === false) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }
        $this->db->prepare("INSERT INTO `payroll_run_employee_comments` (run_id, employee_id, tag, comment, created_by)
                VALUES (:run_id, :employee_id, :tag, :comment, :created_by)")
            ->execute([':run_id' => $runId, ':employee_id' => $employeeId, ':tag' => $tag, ':comment' => $comment, ':created_by' => $userId]);
        return ['status' => true, 'message' => 'Saved successfully.', 'id' => (int)$this->db->lastInsertId()];
    }

    /** 2026-08-29, explicit follow-up: "สามารถแก้ไข Comment และลบ Comment ได้ด้วย". Not restricted to
     *  the original author -- same "anyone with can_process_payroll can act" convention as every
     *  other mutation on this page (no per-row ownership concept exists elsewhere in this class
     *  either). updated_by/updated_at let the UI show a small "(edited)" marker only when genuinely
     *  applicable -- a never-edited comment keeps both null. */
    public function employeeCommentUpdate(int $runId, int $compId, int $commentId, ?string $tag, string $comment, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit comments on this payroll run.'];
        }
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if (in_array($run['state'], self::COMMENT_LOCKED_STATES, true)) {
            return ['status' => false, 'message' => 'This payroll run has finished processing -- comments are view-only.'];
        }
        $comment = trim($comment);
        if ($comment === '') {
            return ['status' => false, 'message' => 'Comment text is required.'];
        }
        if ($tag !== null && !in_array($tag, ['in_progress', 'completed', 'error'], true)) {
            return ['status' => false, 'message' => 'Invalid tag.'];
        }
        $stmt = $this->db->prepare("UPDATE `payroll_run_employee_comments`
            SET tag = :tag, comment = :comment, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND run_id = :run_id");
        $stmt->execute([':tag' => $tag, ':comment' => $comment, ':updated_by' => $userId, ':id' => $commentId, ':run_id' => $runId]);
        if ($stmt->rowCount() === 0) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        return ['status' => true, 'message' => 'Saved successfully.'];
    }

    /** Hard delete -- see this table's own migration docblock for why (a lightweight reminder note,
     *  not compliance/audit data). */
    public function employeeCommentDelete(int $runId, int $compId, int $commentId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to delete comments on this payroll run.'];
        }
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if (in_array($run['state'], self::COMMENT_LOCKED_STATES, true)) {
            return ['status' => false, 'message' => 'This payroll run has finished processing -- comments are view-only.'];
        }
        $stmt = $this->db->prepare("DELETE FROM `payroll_run_employee_comments` WHERE id = :id AND run_id = :run_id");
        $stmt->execute([':id' => $commentId, ':run_id' => $runId]);
        if ($stmt->rowCount() === 0) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    /** Oldest-first (a chronological timeline read top-to-bottom), unlike the run-level audit log
     *  which reads newest-last too -- kept consistent with that same convention. */
    public function employeeComments(int $runId, int $compId, int $employeeId): array {
        if (!$this->get($runId, $compId)) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT c.*, e.name_th AS created_by_name_th, e.name_en AS created_by_name_en
            FROM `payroll_run_employee_comments` c
            LEFT JOIN `employees` e ON e.id = c.created_by
            WHERE c.run_id = :run_id AND c.employee_id = :employee_id
            ORDER BY c.id ASC");
        $stmt->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The department_id of the employee who submitted this run (or created it, if never actually
     * submitted -- a draft has no submitter yet). Null if that employee has no department set.
     * Shared by approvalFlow()/canApproveThisRun() below -- both need to resolve the SAME
     * department to stay consistent (the eligible-approver list a user sees must match who the
     * backend will actually let act, see canApproveThisRun()'s own docblock for why).
     */
    private function submitterDepartmentId(array $run): ?int {
        $submittedBy = $run['submitted_by'] !== null ? (int)$run['submitted_by'] : null;
        if ($submittedBy === null) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT department_id FROM `employees` WHERE id = :id");
        $stmt->execute([':id' => $submittedBy]);
        $deptId = $stmt->fetchColumn();
        return ($deptId !== false && $deptId !== null) ? (int)$deptId : null;
    }

    /**
     * Who must approve this run, and where each of them stands (approved / pending / not
     * applicable) -- there's no multi-step approver chain for a payroll run (unlike the generic
     * ApprovalRequestModel engine): ANY employee whose role currently holds can_approve_payroll
     * AND sits in the SAME DEPARTMENT AS WHOEVER SUBMITTED THE RUN can act, first one in wins
     * (see approve()/reject()/requestInfo() above, all gated through canApproveThisRun() below).
     * "Who must approve" is therefore resolved live (current role holders + current department
     * assignment), same reasoning as ApprovalRequestModel's own role-type step resolution --
     * someone who approved in the past but has since moved off an approver role (or department)
     * won't show up in the eligible list any more, only via the run's own approved_by/rejected_by
     * columns (still correct there regardless of their current role/department).
     *
     * 2026-08-23, explicit request ("Approval ตอนนี้ Set ไว้แค่คนเดียว แต่ดึงมาหลายคน") -- was a
     * flat company-wide `can_approve_payroll` query (every Department Manager at every
     * department), which surprised a user who'd only meant to grant one specific manager approval
     * rights but happened to share that role with 5 other managers across other departments.
     * Scoped down to the submitter's own department on request.
     *
     * 2026-08-23, real bug fix (explicit report: "ตอนนี้ Set ไว้ที่ Specific User ในหน้า Approve มี
     * รายการ แต่พอกดดู timeline No employee in the submitter's department currently holds approval
     * permission" -- traced to the actual dev-DB row: the run's submitter (employee 28, an
     * SSO-provisioned placeholder account) has department_id = NULL, so scoping by "the
     * submitter's department" had nothing to scope against and came back empty even though 6 real
     * Department Managers existed and were perfectly willing to approve). department_id IS NULL is
     * a real, not-rare case (synced/system/placeholder accounts, or a company that simply hasn't
     * filled in every employee's department yet) -- department scoping is meant to NARROW the
     * eligible pool, never to leave a run with zero possible approvers just because one piece of
     * HR data is missing. When the submitter's department can't be resolved, this now falls back
     * to the pre-scoping company-wide list (every can_approve_payroll holder) instead of an empty
     * one -- canApproveThisRun()/list() below fall back the same way, so the Timeline's displayed
     * roster always matches who the backend will actually let act.
     */
    public function approvalFlow(int $runId, int $compId): array {
        $run = $this->get($runId, $compId);
        if (!$run) {
            return [];
        }

        // 2026-08-23, wired to the Approval Workflow engine when this run went through it
        // (approval_request_id set at submit() time) -- "who must approve" is resolved from the
        // ACTUAL configured approval_workflow_steps chain's current step (specific user OR role,
        // live-resolved the same way ApprovalRequestModel::act() itself does), not the flat
        // department-scoped fallback below. Explicit bug report: a real workflow was configured
        // (a single approver_type='user' step) but this method was still building the breakdown
        // from structure_roles.can_approve_payroll, a completely different, unrelated table.
        if ($run['approval_request_id'] !== null) {
            $approvalRequestModel = new ApprovalRequestModel($this->db);
            $pool = $approvalRequestModel->currentStepApprovers($compId, (int)$run['approval_request_id']);
            $approvers = array_map(function (array $p) use ($run): array {
                if ($run['state'] === 'pending_approval') {
                    $status = $p['acted'] ? 'approved' : 'pending';
                } elseif ($run['state'] === 'approved') {
                    $status = $p['acted'] ? 'approved' : 'not_applicable';
                } elseif ($run['state'] === 'rejected') {
                    $status = ((int)$p['id'] === (int)$run['rejected_by']) ? 'rejected' : 'not_applicable';
                } elseif ($run['state'] === 'need_info') {
                    $status = ((int)$p['id'] === (int)$run['need_info_by']) ? 'need_info' : 'not_applicable';
                } else {
                    $status = 'not_applicable';
                }
                $actedAt = null;
                $note = null;
                if ($status === 'approved') {
                    $actedAt = $run['approved_at'];
                } elseif ($status === 'rejected') {
                    $actedAt = $run['rejected_at'];
                    $note = $run['reject_reason'];
                } elseif ($status === 'need_info') {
                    $actedAt = $run['need_info_at'];
                    $note = $run['need_info_reason'];
                }
                return [
                    'id' => $p['id'], 'employee_no' => $p['employee_no'], 'name_th' => $p['name_th'], 'name_en' => $p['name_en'],
                    'profile_photo_path' => $p['profile_photo_path'] ?? null,
                    'status' => $status, 'acted_at' => $actedAt, 'note' => $note,
                ];
            }, $pool);
            // 2026-08-30, explicit follow-up ("ยังไม่ได้ปรับ UI...ให้แสดงหลาย step ที่ actionable
            // พร้อมกันแบบจุดๆ") -- 'steps' is the NEW per-step breakdown (ApprovalRequestModel::
            // stepBreakdown(), additive, see its own docblock) feeding the frontend's step-dot
            // timeline; 'approvers' above is UNCHANGED (still the flattened "who can act right now"
            // list every existing caller/UI already renders) so nothing that reads only 'approvers'
            // needs to change. Only present for a run actually routed through the workflow engine --
            // the flat department-scoped fallback below has no multi-step concept at all, 'steps'
            // stays absent there, same as before this change.
            return ['run_state' => $run['state'], 'approvers' => $approvers, 'steps' => $approvalRequestModel->stepBreakdown($compId, (int)$run['approval_request_id'])];
        }

        // 2026-09-03, Platform Hardening Phase 3: was a raw `sr.can_approve_payroll = 1` JOIN
        // against structure_roles (that column is dropped) -- now resolves the eligible pool via
        // PermissionModel::employeesWithPermission() (role grant, correctly minus any per-user deny
        // override / plus any per-user grant override) first, then applies this method's own
        // department scoping on top of that id set. Same output shape/ordering as before.
        $departmentId = $this->submitterDepartmentId($run);
        $permissionModel = new PermissionModel($this->db);
        $eligibleIds = $permissionModel->employeesWithPermission($compId, 'payroll_run.approve');
        if (empty($eligibleIds)) {
            $approvers = [];
        } else {
            $placeholders = implode(',', array_fill(0, count($eligibleIds), '?'));
            $sql = "SELECT e.id, e.employee_no, e.name_th, e.name_en, e.profile_photo_path
                    FROM `employees` e
                    WHERE e.comp_id = ? AND e.deleted_at IS NULL AND e.id IN ({$placeholders})";
            $params = array_merge([$compId], $eligibleIds);
            if ($departmentId !== null) {
                $sql .= " AND e.department_id = ?";
                $params[] = $departmentId;
            }
            $sql .= " ORDER BY e.name_th ASC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $approvers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $actedId = null;
        $actedStatus = null;
        $actedAt = null;
        $actedNote = null;
        if ($run['state'] === 'approved') {
            $actedId = (int)$run['approved_by'];
            $actedStatus = 'approved';
            $actedAt = $run['approved_at'];
        } elseif ($run['state'] === 'rejected') {
            $actedId = (int)$run['rejected_by'];
            $actedStatus = 'rejected';
            $actedAt = $run['rejected_at'];
            $actedNote = $run['reject_reason'];
        } elseif ($run['state'] === 'need_info') {
            $actedId = (int)$run['need_info_by'];
            $actedStatus = 'need_info';
            $actedAt = $run['need_info_at'];
            $actedNote = $run['need_info_reason'];
        }
        $isPending = $run['state'] === 'pending_approval';

        foreach ($approvers as &$a) {
            if ($actedId !== null && (int)$a['id'] === $actedId) {
                $a['status'] = $actedStatus;
                $a['acted_at'] = $actedAt;
                $a['note'] = $actedNote;
            } elseif ($isPending) {
                $a['status'] = 'pending';
                $a['acted_at'] = null;
                $a['note'] = null;
            } else {
                $a['status'] = 'not_applicable';
                $a['acted_at'] = null;
                $a['note'] = null;
            }
        }
        unset($a);

        return ['run_state' => $run['state'], 'approvers' => $approvers];
    }

    /* ==================== PERMISSIONS ==================== */

    /** True if the employee holds ANY of the 3 payroll permission keys (or is admin) -- gates read
     *  access to run detail/salary data.
     *  2026-09-03, Platform Hardening Phase 3: was an OR of 3 raw structure_roles.can_* column
     *  reads (can_process_payroll/can_approve_payroll/can_finalize_payroll) -- those columns are
     *  retired, replaced 1:1 by the payroll_run.process/.approve/.finalize permission keys (see
     *  userCan()'s own docblock for how the swap preserves admin-bypass and per-user-override
     *  behavior identically to before). */
    public function canView(int $actingEmployeeId, bool $isAdmin): bool {
        return $this->userCan($actingEmployeeId, 'payroll_run.process', $isAdmin)
            || $this->userCan($actingEmployeeId, 'payroll_run.approve', $isAdmin)
            || $this->userCan($actingEmployeeId, 'payroll_run.finalize', $isAdmin);
    }

    /**
     * Whether $actingEmployeeId can approve/reject/request-info/undo-decision on THIS SPECIFIC
     * run -- holding a can_approve_payroll role is necessary but no longer sufficient on its own
     * (2026-08-23, explicit request: scope approval to the submitter's own department, see
     * approvalFlow()'s own docblock for the full reasoning). Admin still bypasses unconditionally,
     * same as every other permission check in this class. A run with no resolvable submitter
     * department (still draft, or the submitter has no department set) has no valid approver at
     * all -- correct: there's no "department" to scope against yet.
     */
    /** A run routed through the Approval Workflow engine (approval_request_id set) defers
     *  ENTIRELY to that engine's own live step resolution -- the flat department-scoped check
     *  below only applies to a run that never had a workflow configured for it at submit() time.
     *
     *  2026-08-24, split by run state (explicit bug report: a user not listed as an eligible
     *  approver on ANY currently-open step -- or eligible only on a step a joint peer had ALREADY
     *  decided, or one still locked behind an earlier step -- was still seeing the Approve/Reject/
     *  Request Info buttons on the Approval Queue AND the Process Detail page, and the row itself
     *  never disappeared from their Approval Queue once a peer decided it). While pending_approval,
     *  this must answer "can I decide RIGHT NOW" -- canActOnRequestNow() (eligible, that specific
     *  row still 'pending', and unlocked), the exact same gate act() itself enforces, so a shown
     *  button always actually works and a decided-by-someone-else row stops being "mine to act on".
     *  Once the run has already been decided (approved/rejected/need_info), there is no more
     *  'pending' row left to be "actionable now" on for ANYONE -- this instead gates the Undo
     *  Decision button, which -- like revert() itself -- intentionally uses the coarser "was ever
     *  eligible on this request" canActOnRequest(), unchanged from before. */
    private function canApproveThisRun(int $actingEmployeeId, bool $isAdmin, array $run): bool {
        // 2026-08-24, second fix the same day (explicit repro from the user: logged in as a
        // DIFFERENT employee_id than the one configured -- employee 28, session role 'admin' --
        // still got can_approve_payroll=true and a working Approve button, even though the active
        // PAYROLL_RUN_APPROVAL workflow names only employee 190 as the approver). The 2026-08-24
        // fix above (canActOnRequestNow()) tightened WHICH row counts as "actionable", but this
        // `if ($isAdmin) return true;` sat BEFORE that check and short-circuited the whole thing
        // for any admin-role session regardless -- admin bypass is a deliberate, documented
        // convention everywhere else in this app (RBAC, canView, canProcessPayroll, etc.), but it
        // defeats the entire purpose of a configured Approval Workflow if it also overrides WHO is
        // allowed to approve/reject THIS specific run. Now scoped: admin bypasses only the flat
        // company-wide fallback below (no active workflow configured for PAYROLL_RUN_APPROVAL --
        // there's no specific flow to violate in that case, same as before). Once a run is routed
        // through the engine (approval_request_id set), admin is just another employee_id that
        // must actually be a configured/eligible approver like anyone else -- see approve()/
        // reject()/requestInfo()/revert()'s own docblocks for the matching fix on the action side
        // (admin used to skip calling the engine's act()/canActOnRequest() entirely, which ALSO
        // meant the linked approval_requests row was silently left stuck 'pending' forever while
        // payroll_runs.state said 'approved' -- a real data inconsistency, not just a visibility
        // bug).
        if (($run['approval_request_id'] ?? null) !== null) {
            $approvalRequestModel = new ApprovalRequestModel($this->db);
            if ($run['state'] === 'pending_approval') {
                return $approvalRequestModel->canActOnRequestNow((int)$run['comp_id'], (int)$run['approval_request_id'], $actingEmployeeId);
            }
            return $approvalRequestModel->canActOnRequest((int)$run['comp_id'], (int)$run['approval_request_id'], $actingEmployeeId);
        }
        if ($isAdmin) {
            return true;
        }
        // 2026-09-03, Platform Hardening Phase 3: can_approve_payroll -> payroll_run.approve. This
        // is the ONLY line in this whole method the permission-key swap touches -- the
        // approval_request_id-first branch above (and everything about admin bypass ordering
        // relative to it) is completely unchanged, since the engine already handles that case
        // before this line is ever reached. A per-user override on payroll_run.approve therefore
        // has the exact same "irrelevant once a workflow is linked" property the legacy boolean
        // already had -- this line is only reachable when approval_request_id is null.
        if (!$this->userCan($actingEmployeeId, 'payroll_run.approve', $isAdmin)) {
            return false;
        }
        $submitterDept = $this->submitterDepartmentId($run);
        // 2026-08-23, real bug fix -- see approvalFlow()'s own docblock for the full incident
        // (submitter had no department set, so the run had zero possible approvers under a hard
        // department match). Same fallback here: no resolvable submitter department means "don't
        // scope," not "nobody can approve" -- any can_approve_payroll holder qualifies.
        if ($submitterDept === null) {
            return true;
        }
        $stmt = $this->db->prepare("SELECT department_id FROM `employees` WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $actingEmployeeId]);
        $actorDept = $stmt->fetchColumn();
        return $actorDept !== false && $actorDept !== null && (int)$actorDept === $submitterDept;
    }

    /** Public wrapper around canApproveThisRun() -- lets the frontend (Detail page's Timeline, the
     *  Approval Queue's Timeline modal) know whether to show the Approve/Reject/Request Info/
     *  Revert buttons for THIS run at all. */
    public function canApprovePayroll(int $actingEmployeeId, bool $isAdmin, array $run): bool {
        return $this->canApproveThisRun($actingEmployeeId, $isAdmin, $run);
    }

    /** Same, for can_process_payroll -- gates the Detail page's "Pull Back to Edit" button
     *  (reviseAfterReject()/reviseAfterNeedInfo()), which is the submitter's action, not the
     *  approver's. */
    public function canProcessPayroll(int $actingEmployeeId, bool $isAdmin): bool {
        return $this->userCan($actingEmployeeId, 'payroll_run.process', $isAdmin);
    }

    /** Same, for payroll_run.finalize -- gates the Detail page's "Mark as Paid"/"Lock" buttons
     *  (markPaid()/lock() below), same simple permission check (no department-scoping like
     *  payroll_run.approve needs -- finalizing isn't tied to who submitted the run). */
    public function canFinalizePayroll(int $actingEmployeeId, bool $isAdmin): bool {
        return $this->userCan($actingEmployeeId, 'payroll_run.finalize', $isAdmin);
    }

    /**
     * 2026-09-03, Platform Hardening Phase 3: was a raw `structure_roles.<can_process_payroll|
     * can_approve_payroll|can_finalize_payroll>` column read, whitelisted against those 3 literal
     * column names first. Now a straight PermissionModel::checkPermission() call against the 1:1
     * replacement permission keys (payroll_run.process/.approve/.finalize) -- same admin-bypass
     * behavior (checked first, unconditional), PLUS the new per-user override support for free
     * (checkPermission() itself checks employee_permission_overrides before falling through to the
     * role_permissions grant this method used to read directly).
     *
     * $permissionKey is no longer whitelisted against a literal array here -- every call site in
     * this class passes a compile-time string literal, and checkPermission() itself safely returns
     * "denied" for any key that doesn't exist in `permissions` (a JOIN that matches zero rows), so
     * there's no injection surface and no behavior gap versus the old whitelist.
     *
     * comp_id is resolved from the acting employee's own row rather than widening this method's
     * signature (and therefore canView()'s/canProcessPayroll()'s/canFinalizePayroll()'s public
     * signatures, which every controller call site would then need updating too) -- one extra query,
     * but zero ripple to any caller.
     */
    private function userCan(int $actingEmployeeId, string $permissionKey, bool $isAdmin): bool {
        if ($isAdmin) {
            return true;
        }
        $stmt = $this->db->prepare("SELECT comp_id FROM `employees` WHERE id = :employee_id AND deleted_at IS NULL");
        $stmt->execute([':employee_id' => $actingEmployeeId]);
        $compId = $stmt->fetchColumn();
        if ($compId === false) {
            return false;
        }
        $permissionModel = new PermissionModel($this->db);
        return $permissionModel->checkPermission($actingEmployeeId, $permissionKey, $isAdmin, (int)$compId)['allowed'];
    }

    /* ==================== AUDIT ==================== */

    /**
     * REMOTE_ADDR is the TCP peer address the webserver actually saw -- trustworthy for an audit
     * trail (unlike X-Forwarded-For, which a client can freely spoof and which this dev/single-box
     * environment has no trusted reverse proxy in front of to strip/validate). Good enough to
     * answer "approved from IP X"; not used for any access-control decision.
     */
    private function clientIp(): ?string {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return $ip !== null && $ip !== '' ? substr((string)$ip, 0, 45) : null;
    }

    private function clientUserAgent(): ?string {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return $ua !== null && $ua !== '' ? substr((string)$ua, 0, 255) : null;
    }

    /**
     * 2026-08-31, same-day follow-up (item 9c, explicit request: "Log ทุกครั้งที่เปิดหน้า Process Detail")
     * -- reuses payroll_run_audit_logs/logAudit() rather than a new table, with a new 'view_detail'
     * action value (varchar(50) column, no schema change needed). from_state == to_state on purpose
     * (a view never transitions anything) -- this is what distinguishes a view-log row from every
     * other row in this table, which always represents a real transition/edit. Called once per
     * PAGE NAVIGATION (PayrollController::detail(), the route that renders the Detail page itself),
     * deliberately NOT from get() (the AJAX data-fetch endpoint that same page also calls after
     * every single edit/save via loadRunDetail()) -- logging there would flood this table with one
     * row per edit on top of that edit's own real audit-log row, which is not what "log every time
     * the page opens" asked for. Silently no-ops if the run/company don't match (bad/stale id) --
     * a failed view attempt is not something worth recording here.
     */
    public function logViewDetail(int $runId, int $compId, int $userId): void {
        $run = $this->get($runId, $compId);
        if (!$run) {
            return;
        }
        $this->logAudit($runId, (string)$run['state'], (string)$run['state'], 'view_detail', $userId);
    }

    private function logAudit(int $runId, ?string $fromState, string $toState, string $action, int $userId, ?string $note = null): void {
        $stmt = $this->db->prepare("INSERT INTO `payroll_run_audit_logs` (run_id, from_state, to_state, action, note, performed_by, ip_address, user_agent)
            VALUES (:run_id, :from_state, :to_state, :action, :note, :performed_by, :ip_address, :user_agent)");
        $stmt->execute([
            ':run_id' => $runId,
            ':from_state' => $fromState,
            ':to_state' => $toState,
            ':action' => $action,
            ':note' => $note,
            ':performed_by' => $userId,
            ':ip_address' => $this->clientIp(),
            ':user_agent' => $this->clientUserAgent(),
        ]);
    }

    /* ==================== CREATE / EDIT (draft only) ==================== */

    // Off-cycle runs (cycleId === null) are deliberately exempt from this check -- there's no
    // cycle group to collide within, and an ad-hoc/out-of-cycle payment legitimately CAN share a
    // date range with a normal cycle's run (e.g. a one-off bonus run covering the same period).
    /**
     * 2026-08-28, real bug found and fixed (explicit report: "A payroll run already exists for
     * this cycle and period. ทั้งๆอีกรอบยกเลิกไปแล้ว" -- a cancelled run still blocked a new one for
     * the same cycle+period). Root cause: cancel() sets state='cancelled' but deliberately never
     * touches deleted_at (it's a state transition, not a soft-delete -- the row must stay visible
     * in run history/logs). This check only ever excluded deleted_at IS NULL, so a cancelled run
     * still counted as "occupying" its period. A cancelled run represents money that never moved
     * (see cancel()'s own docblock -- it even frees the run's sync_process_id back to Pending Pull
     * for exactly this reason), so it must not block a fresh run for the same period.
     */
    private function isDuplicatePeriod(int $compId, ?int $cycleId, string $start, string $end, ?int $excludeId): bool {
        if ($cycleId === null) {
            return false;
        }
        $sql = "SELECT COUNT(*) FROM `payroll_runs`
                WHERE comp_id = :comp_id AND cycle_id = :cycle_id AND period_start_date = :start AND period_end_date = :end
                AND deleted_at IS NULL AND state != 'cancelled'";
        $params = [':comp_id' => $compId, ':cycle_id' => $cycleId, ':start' => $start, ':end' => $end];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * 2026-09-06, explicit request: "ปรับ Process ที่มีการสร้างรอบเองในฝั่ง Payroll ให้เป็นไปในแนวทางเดียวกัน"
     * -- extends the "อ้างอิงถึงรอบ" (reference a round) merge-target mechanism to accept a round that
     * doesn't exist yet, the SAME real-world gap just closed on the Origami-attribution side
     * (see PayrollSyncModel::attributionTargetStatus()'s own docblock for that thread). Reuses the
     * EXACT SAME `isDuplicatePeriod()` uniqueness key (cycle_id+period_start_date+period_end_date) a
     * real payroll run is already keyed on -- the one and only kind of "future round" this app can
     * identify in advance, since a recurring Payroll Cycle is the one and only concept with a
     * predictable next occurrence (an arbitrary future off-cycle/manual run has no schedule at all,
     * so there is no key to wait on for one of those -- confirmed via AskUserQuestion).
     * @return array{id:int}|null the matching active (non-cancelled, non-deleted) run, or null
     */
    /**
     * 2026-09-07, real behavior change (explicit request: "รอบอนาคต...น่าจะเปลี่ยนเป็นรอบเดือนของวันที่จ่าย
     * เพราะเราจะดึงข้อมูลของรอบเดือนที่จ่าย เช่น จ่าย 15 กย ก็จะนำไปรวมกับที่จ่าย 30 กย") -- was
     * `findActiveRunForCyclePeriod()`, matching on an EXACT `period_start_date`/`period_end_date`
     * pair (the same cycle's one specific occurrence). Confirmed with the user this undersold their
     * real need: a company whose SAME recurring Payroll Cycle produces more than one run inside a
     * single calendar month (e.g. a semi-monthly cycle paying on the 15th AND the 30th) wants a
     * future merge target to resolve against WHICHEVER of that cycle's own occurrences lands in the
     * target month, not one exact, hand-predicted period. Still scoped to a single named `cycle_id`
     * (never any-cycle-in-the-company) -- the "confirmed via AskUserQuestion: the only kind of future
     * round this app can name in advance is a recurring Payroll Cycle's own next occurrence" design
     * principle from 2026-09-06 is unchanged, only WHICH occurrence of that one cycle counts as a
     * match got looser. `$targetMonthAnchor` is the stored `merge_target_period_start_date` (the
     * only date this feature has ever collected from the admin) -- its own YEAR/MONTH stands in for
     * "the target payment month" since no separate target-payment-date field exists or is needed;
     * matched against the CANDIDATE run's actual `payment_date` (not ITS OWN period dates), since
     * payment date -- not period -- is what genuinely determines which calendar month a run belongs
     * to for this purpose (a period can straddle a month boundary; its payment date does not).
     * `ORDER BY period_start_date ASC LIMIT 1` picks the earliest-period occurrence deterministically
     * on the rare chance more than one already exists in that month (e.g. both the 15th and 30th runs
     * already created before this target was even set up) -- an edge case, not the common path.
     */
    private function findActiveRunForCyclePaymentMonth(int $compId, int $cycleId, string $targetMonthAnchor, ?int $excludeId): ?array {
        $sql = "SELECT id FROM `payroll_runs`
                WHERE comp_id = :comp_id AND cycle_id = :cycle_id
                  AND YEAR(payment_date) = YEAR(:anchor) AND MONTH(payment_date) = MONTH(:anchor)
                AND deleted_at IS NULL AND state != 'cancelled'";
        $params = [':comp_id' => $compId, ':cycle_id' => $cycleId, ':anchor' => $targetMonthAnchor];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $sql .= " ORDER BY period_start_date ASC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * 2026-09-09, round-creation flow audit Bug 2 fix (explicit report: findActiveRunForCyclePaymentMonth()
     * silently picks the earliest-period candidate with zero visible indication of WHICH run got
     * chosen, whenever 2+ runs already match the same cycle+payment-month). Same WHERE as that method,
     * minus the LIMIT 1 and plus enough columns for the preview UI to actually show the admin what
     * they're choosing between -- used ONLY by previewFutureCycleMergeTarget() below, never by
     * resolveMergeTargetSpec() itself (that method's own single-pick fallback is UNCHANGED, still the
     * safety net for a caller that saves without ever calling the new preview endpoint at all -- e.g.
     * a stale client, or the disambiguation happening to still land on a single match by the time of
     * save). Returned in the SAME `ORDER BY period_start_date ASC` order so index 0 is always "what the
     * old silent behavior would have picked" if the UI needs that reference point.
     */
    private function findAllActiveRunsForCyclePaymentMonth(int $compId, int $cycleId, string $targetMonthAnchor, ?int $excludeId): array {
        $sql = "SELECT id, run_name, run_code, state, payment_date, period_start_date, period_end_date FROM `payroll_runs`
                WHERE comp_id = :comp_id AND cycle_id = :cycle_id
                  AND YEAR(payment_date) = YEAR(:anchor) AND MONTH(payment_date) = MONTH(:anchor)
                AND deleted_at IS NULL AND state != 'cancelled'";
        $params = [':comp_id' => $compId, ':cycle_id' => $cycleId, ':anchor' => $targetMonthAnchor];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $sql .= " ORDER BY period_start_date ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 2026-09-09, round-creation flow audit Bug 2 fix -- read-only preview for the "future round"
     * merge-target mode, called by the Create/Edit forms right before Save (and live as the admin picks
     * the target cycle/period) so a 2+-candidate match is disambiguated EXPLICITLY by the admin instead
     * of silently defaulting to whichever run has the earliest period_start_date. Same
     * cycle-must-be-active-and-belong-to-this-company validation resolveMergeTargetSpec() already does,
     * duplicated here rather than shared because that method's version returns immediately on failure as
     * part of a larger multi-field resolution and isn't a clean extraction point on its own.
     * @return array{status:bool, message?:string, matches?:array}
     */
    public function previewFutureCycleMergeTarget(int $compId, int $cycleId, string $periodStart, ?int $excludeRunId): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $periodStart)) {
            return ['status' => false, 'message' => 'Invalid date format, expected YYYY-MM-DD.'];
        }
        $stmtCycle = $this->db->prepare("SELECT id FROM `payroll_cycles` WHERE id = :id AND comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL");
        $stmtCycle->execute([':id' => $cycleId, ':comp_id' => $compId]);
        if (!$stmtCycle->fetch()) {
            return ['status' => false, 'message' => 'Invalid or inactive target payroll cycle.'];
        }
        $matches = $this->findAllActiveRunsForCyclePaymentMonth($compId, $cycleId, $periodStart, $excludeRunId);
        return ['status' => true, 'matches' => $matches];
    }

    /**
     * Shared by create()/update(): validates a genuine off-cycle run's merge-target spec, which is
     * now EITHER `merge_target_run_id` (an existing round, any state except cancelled -- unchanged
     * behavior) OR `merge_target_cycle_id`+period (a round that doesn't exist yet for that recurring
     * Payroll Cycle's next occurrence) -- never both at once. Whenever the future-cycle spec
     * ALREADY has a real matching run at save time (e.g. the admin picked a period that, unknown to
     * them, someone else already created a run for moments ago), it's resolved immediately into a
     * plain `merge_target_run_id` instead of being stored as a still-waiting spec -- from that point
     * on the row behaves 100% identically to the "reference an existing round" case that already
     * existed and is already fully tested (Detail page's own eligibility check/banner/button,
     * List page's own icon, mergeIntoExistingRun() itself) -- zero new merge-execution code path,
     * only a new way to name a target that doesn't exist YET.
     *
     * Each of `merge_target_run_id`/`merge_target_cycle_id` is resolved INDEPENDENTLY with the exact
     * same "key absent from $data => keep whatever this run already had, key present (even if
     * empty/null) => this call sets/clears it" rule create()/update() already established for
     * `merge_target_run_id` alone, BEFORE this feature existed -- preserves 100% backward
     * compatibility for a caller that only ever touches `merge_target_run_id` (as every existing
     * caller still does; the new UI is the only caller that will ever send `merge_target_cycle_id`).
     * Because of that independence, a caller that switches modes (e.g. was in "future cycle" mode,
     * now wants "existing round" instead) MUST send BOTH keys in the same request (the new one with
     * a real value, the old one explicitly null) -- sending only the new key while leaving the old
     * one untouched is refused below as an ambiguous "both set" conflict, forcing an explicit choice
     * rather than silently guessing which one wins.
     * @return array{status:bool, message?:string, merge_target_run_id:?int, merge_target_cycle_id:?int, merge_target_period_start_date:?string, merge_target_period_end_date:?string}
     */
    private function resolveMergeTargetSpec(int $compId, ?int $cycleId, ?int $syncProcessId, array $data, ?int $currentMergeTargetRunId, ?int $currentMergeTargetCycleId, ?string $currentMergeTargetPeriodStart, ?string $currentMergeTargetPeriodEnd, ?int $excludeRunId): array {
        $mergeTargetRunId = $currentMergeTargetRunId;
        if (array_key_exists('merge_target_run_id', $data)) {
            $newMergeTargetRunId = !empty($data['merge_target_run_id']) ? (int)$data['merge_target_run_id'] : null;
            if ($newMergeTargetRunId !== null) {
                if ($cycleId !== null || $syncProcessId !== null) {
                    return ['status' => false, 'message' => 'A merge target only applies to a genuine off-schedule run.'];
                }
                if ($excludeRunId !== null && $newMergeTargetRunId === $excludeRunId) {
                    return ['status' => false, 'message' => 'A run cannot be merged into itself.'];
                }
                $stmtMergeTarget = $this->db->prepare("SELECT id FROM `payroll_runs` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL AND state != 'cancelled'");
                $stmtMergeTarget->execute([':id' => $newMergeTargetRunId, ':comp_id' => $compId]);
                if (!$stmtMergeTarget->fetch()) {
                    return ['status' => false, 'message' => 'Invalid merge target run.'];
                }
            }
            $mergeTargetRunId = $newMergeTargetRunId;
        }

        $mergeTargetCycleId = $currentMergeTargetCycleId;
        $mergeTargetPeriodStart = $currentMergeTargetPeriodStart;
        $mergeTargetPeriodEnd = $currentMergeTargetPeriodEnd;
        if (array_key_exists('merge_target_cycle_id', $data)) {
            $newCycleId = !empty($data['merge_target_cycle_id']) ? (int)$data['merge_target_cycle_id'] : null;
            if ($newCycleId === null) {
                $mergeTargetCycleId = null;
                $mergeTargetPeriodStart = null;
                $mergeTargetPeriodEnd = null;
            } else {
                if ($cycleId !== null || $syncProcessId !== null) {
                    return ['status' => false, 'message' => 'A merge target only applies to a genuine off-schedule run.'];
                }
                $mtStart = !empty($data['merge_target_period_start_date']) ? (string)$data['merge_target_period_start_date'] : null;
                $mtEnd = !empty($data['merge_target_period_end_date']) ? (string)$data['merge_target_period_end_date'] : null;
                if ($mtStart === null || $mtEnd === null) {
                    return ['status' => false, 'message' => 'merge_target_period_start_date/merge_target_period_end_date are required with merge_target_cycle_id.'];
                }
                foreach ([$mtStart, $mtEnd] as $d) {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                        return ['status' => false, 'message' => 'Invalid date format, expected YYYY-MM-DD.'];
                    }
                }
                if ($mtEnd < $mtStart) {
                    return ['status' => false, 'message' => 'merge_target_period_end_date must not be before merge_target_period_start_date.'];
                }
                $stmtMtCycle = $this->db->prepare("SELECT id FROM `payroll_cycles` WHERE id = :id AND comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL");
                $stmtMtCycle->execute([':id' => $newCycleId, ':comp_id' => $compId]);
                if (!$stmtMtCycle->fetch()) {
                    return ['status' => false, 'message' => 'Invalid or inactive target payroll cycle.'];
                }
                // Immediate resolution -- see findActiveRunForCyclePaymentMonth()'s own docblock: if
                // a real run of this cycle already exists whose payment_date falls in the same
                // month as $mtStart, this is no longer a "future" target at all (2026-09-07: was an
                // exact cycle+period match, now a same-cycle+same-payment-month match).
                $existingTargetRun = $this->findActiveRunForCyclePaymentMonth($compId, $newCycleId, $mtStart, $excludeRunId);
                if ($existingTargetRun !== null) {
                    $mergeTargetRunId = (int)$existingTargetRun['id'];
                    $mergeTargetCycleId = null;
                    $mergeTargetPeriodStart = null;
                    $mergeTargetPeriodEnd = null;
                } else {
                    $mergeTargetCycleId = $newCycleId;
                    $mergeTargetPeriodStart = $mtStart;
                    $mergeTargetPeriodEnd = $mtEnd;
                }
            }
        }

        if ($mergeTargetRunId !== null && $mergeTargetCycleId !== null) {
            return ['status' => false, 'message' => 'A run is already set to reference an existing round or a future cycle period -- clear the other one explicitly before setting a new one.'];
        }

        return [
            'status' => true,
            'merge_target_run_id' => $mergeTargetRunId,
            'merge_target_cycle_id' => $mergeTargetCycleId,
            'merge_target_period_start_date' => $mergeTargetPeriodStart,
            'merge_target_period_end_date' => $mergeTargetPeriodEnd,
        ];
    }

    public function create(int $compId, array $data, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
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
        //
        // 2026-08-29, explicit request referencing PAYROLL_SYNC_API.md's own run_kind field
        // ("regular"/"supplemental", added there 2026-08-28): a supplemental sync process (a
        // standalone/ad-hoc Origami cycle -- e.g. OT-only or Trip-only, hand-built roster, not
        // tied to a period auto-match) is NOT "real payroll by definition" the way a regular
        // sync-pulled cycle is, so it must NOT be forced into requiring a payroll cycle or into
        // always including base salary/standing items -- confirmed via AskUserQuestion. A regular
        // sync process keeps today's existing behavior unchanged (cycle_id required, always full
        // payroll) -- this is purely additive for the supplemental case.
        $syncProcessId = null;
        $syncIsSupplemental = false;
        $syncOrigamiProcessId = null;
        if (!empty($data['sync_process_id'])) {
            $syncProcessId = (int)$data['sync_process_id'];
            $stmtSync = $this->db->prepare("SELECT p.id, p.run_kind, p.origami_process_id FROM `payroll_sync_processes` p
                LEFT JOIN `payroll_runs` r ON r.sync_process_id = p.id
                WHERE p.id = :id AND p.comp_id = :comp_id AND r.id IS NULL");
            $stmtSync->execute([':id' => $syncProcessId, ':comp_id' => $compId]);
            $syncRow = $stmtSync->fetch(PDO::FETCH_ASSOC);
            if (!$syncRow) {
                return ['status' => false, 'message' => 'Invalid or already-pulled sync process.'];
            }
            $syncIsSupplemental = ($syncRow['run_kind'] ?? 'regular') === 'supplemental';
            $syncOrigamiProcessId = $syncRow['origami_process_id'] !== null ? (int)$syncRow['origami_process_id'] : null;
            if ($cycleId === null && !$syncIsSupplemental) {
                return ['status' => false, 'message' => 'A payroll cycle is required when pulling from a regular sync process.'];
            }
        }

        // 2026-09-01, explicit request: "ตอนดึงมาทำรอบหรือเพิ่มรอบใหม่ ให้มี radio เลือกว่า เปิดรอบใหม่ หรือ
        // อ้างอิงถึงรอบ" -- confirmed via AskUserQuestion: reachable ONLY from the plain "Add" flow
        // (Pending-Pull/Origami stays exactly as-is). Just a tag at creation time -- the actual fold-
        // in happens later, once the admin has built this run up normally (Join Employees/Manage
        // Items) and explicitly triggers it via PayrollRunModel::mergeIntoExistingRun() from the
        // Detail page's own "Merge into Target" action (same 2-step "build it up, then merge"
        // rhythm the Pending-Pull table's own Merge-into-Target button already establishes). Only
        // meaningful for a genuinely off-cycle/manual run -- cycle_id/sync_process_id being set
        // already implies automatic-by-date/by-payload membership, not a hand-built extra payment.
        //
        // 2026-09-06, explicit request: "ปรับ Process ที่มีการสร้างรอบเองในฝั่ง Payroll ให้เป็นไปในแนวทาง
        // เดียวกัน" (with the Origami-attribution "waiting for a round that doesn't exist yet" fix
        // just shipped) -- see resolveMergeTargetSpec()'s own docblock for the full mechanism now
        // shared between create()/update().
        $mergeTargetSpec = $this->resolveMergeTargetSpec($compId, $cycleId, $syncProcessId, $data, null, null, null, null, null);
        if (empty($mergeTargetSpec['status'])) {
            return $mergeTargetSpec;
        }
        $mergeTargetRunId = $mergeTargetSpec['merge_target_run_id'];
        $mergeTargetCycleId = $mergeTargetSpec['merge_target_cycle_id'];
        $mergeTargetPeriodStart = $mergeTargetSpec['merge_target_period_start_date'];
        $mergeTargetPeriodEnd = $mergeTargetSpec['merge_target_period_end_date'];

        // "Incentive/Other Payment" run purpose (per explicit request, 2026-08-19): a special
        // payment (e.g. a one-off incentive) that BY DEFAULT does not involve base salary or the
        // employee's standing earning/deduction setup -- only whatever specific earning/deduction
        // items the admin picks per employee (see joinEmployees()/addManualLine() below). Makes
        // sense for a genuine off-cycle run (same gate as the manual employee roster) OR a
        // supplemental sync pull (see the block above this one, 2026-08-29) -- a genuinely regular
        // cycle-based or Pending-Pull run is real payroll by definition, so 'incentive' is rejected
        // there rather than silently ignored.
        // compute_statutory/include_base_salary/include_standing_items are all the admin's own
        // per-run opt-in choice (compute_statutory confirmed explicit 2026-08-19; the other two
        // 2026-08-27, explicit request: "การทำงานจ่ายนอกรอบ สามารถเลือกได้ว่าจะนำเงินเดือนหรือค่า
        // เงินได้เงินหักที่มีการตั้งค่าไว้มาคำนวณ", confirmed via AskUserQuestion -- two SEPARATE
        // toggles, base salary paid FULL/not prorated, standing items = PED assignments + Recurring
        // Earnings) -- but a normal 'payroll' run must ALWAYS include all three; that's not
        // something the UI is allowed to turn off, so all three are forced to their "on" value here
        // regardless of what the request sent, not just hidden in the UI. See recalculate()'s own
        // docblock (search $includeBaseSalary/$includeStandingItems) for what each actually changes.
        $runPurpose = (string)($data['run_purpose'] ?? 'payroll') === 'incentive' ? 'incentive' : 'payroll';
        $isGenuineOffCycle = $cycleId === null && $syncProcessId === null;
        if ($runPurpose === 'incentive' && !$isGenuineOffCycle && !$syncIsSupplemental) {
            return ['status' => false, 'message' => 'Incentive/Other Payment is only available for an off-cycle run or a supplemental sync pull.'];
        }
        $computeStatutory = $runPurpose === 'incentive' ? (!empty($data['compute_statutory']) ? 1 : 0) : 1;
        $includeBaseSalary = $runPurpose === 'incentive' ? (!empty($data['include_base_salary']) ? 1 : 0) : 1;
        $includeStandingItems = $runPurpose === 'incentive' ? (!empty($data['include_standing_items']) ? 1 : 0) : 1;
        // 2026-08-30 (Phase 8, T041, real gap found and fixed): a 3rd opt-in toggle, same pattern as
        // the two above -- pulls sync-derived attendance EARNING lines only (OT/trip allowance/any
        // other item_values-derived earning), never the deduction side (late/absent/unpaid leave/
        // leave pending don't belong in a supplemental payout run). See recalculate()'s own
        // $includeAttendancePay branch for exactly what this changes. A normal 'payroll' run has no
        // meaning for this (it already pulls sync-derived lines unconditionally), so it stays 0 there
        // same as include_base_salary/include_standing_items are forced to their normal-run value.
        $includeAttendancePay = $runPurpose === 'incentive' ? (!empty($data['include_attendance_pay']) ? 1 : 0) : 0;
        // 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย") -- same admin-opt-in shape as the 3
        // toggles just above; only meaningful for the same incentive/supplemental pull, never a
        // normal 'payroll' run. See PayrollPolicyModel::flatTaxRatePercent()'s own docblock and
        // recalculate()'s own use of this flag for what it actually changes.
        $useFlatTaxRate = $runPurpose === 'incentive' ? (!empty($data['use_flat_tax_rate']) ? 1 : 0) : 0;

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
            // item_master (PAYROLL_SYNC_API.md's 2026-08-30 revision) -- auto-creates a catalog row
            // for any income/deduction item this company doesn't already have one for, so a new
            // custom item is ready to review/configure the moment this run exists. See
            // PayrollSyncModel::autoCreateMissingPedTypes()'s own docblock.
            $pedTypesCreated = $syncModel->autoCreateMissingPedTypes($syncProcessId, $compId, $userId);
            $syncSummary = ['results' => $syncResults, 'remapped_count' => $remappedCount,
                'placeholders_created' => $placeholdersCreated, 'employee_fields_updated' => $employeeFieldsUpdated,
                'ped_types_created' => $pedTypesCreated];
        }

        $runName = trim((string)$data['run_name']);
        $notes = !empty($data['notes']) ? trim((string)$data['notes']) : null;
        // 2026-09-01, explicit request: settable right at creation, not just from the Detail page --
        // an admin who already knows this run needs frequent hands-on edits (a typical incentive/
        // off-cycle run) can turn it on here instead of a separate trip after. See
        // PayrollRunModel::setAutoRecalculate()'s own docblock for what this flag actually does.
        $autoRecalculate = !empty($data['auto_recalculate']) ? 1 : 0;
        // 2026-09-02, explicit request: "ในตารางให้แสดง Code ของรอบด้วยครับ" -- first real consumer of
        // the Document Numbering settings' PAYROLL_RUN row (see DocumentNumberingModel::
        // generateNext()'s own docblock -- that model's settings existed since 2026-08-23 but were
        // never actually wired to stamp a code onto anything). Best-effort/non-blocking by design --
        // generateNext() itself never throws (catches internally, returns null on any failure), and
        // a null run_code here is a completely normal, harmless outcome (e.g. right after a fresh
        // company provision before this company's own settings row has ever been touched -- though
        // ensureSeeded() inside generateNext() makes that self-healing on the very next call too).
        $runCode = (new DocumentNumberingModel($this->db))->generateNext($compId, 'PAYROLL_RUN');

        $stmt = $this->db->prepare("INSERT INTO `payroll_runs`
            (comp_id, run_code, cycle_id, sync_process_id, merge_target_run_id, merge_target_cycle_id, merge_target_period_start_date, merge_target_period_end_date, run_purpose, compute_statutory, include_base_salary, include_standing_items, include_attendance_pay, use_flat_tax_rate, run_name, period_start_date, period_end_date, payment_date, state, notes, auto_recalculate, created_by)
            VALUES (:comp_id, :run_code, :cycle_id, :sync_process_id, :merge_target_run_id, :merge_target_cycle_id, :merge_target_period_start_date, :merge_target_period_end_date, :run_purpose, :compute_statutory, :include_base_salary, :include_standing_items, :include_attendance_pay, :use_flat_tax_rate, :run_name, :start, :end, :pay_date, 'draft', :notes, :auto_recalculate, :created_by)");
        $stmt->execute([
            ':comp_id' => $compId, ':run_code' => $runCode, ':cycle_id' => $cycleId, ':sync_process_id' => $syncProcessId,
            ':merge_target_run_id' => $mergeTargetRunId,
            ':merge_target_cycle_id' => $mergeTargetCycleId,
            ':merge_target_period_start_date' => $mergeTargetPeriodStart,
            ':merge_target_period_end_date' => $mergeTargetPeriodEnd,
            ':run_purpose' => $runPurpose, ':compute_statutory' => $computeStatutory,
            ':include_base_salary' => $includeBaseSalary, ':include_standing_items' => $includeStandingItems,
            ':include_attendance_pay' => $includeAttendancePay, ':use_flat_tax_rate' => $useFlatTaxRate,
            ':run_name' => $runName,
            ':start' => $start, ':end' => $end, ':pay_date' => $payDate,
            ':notes' => $notes, ':auto_recalculate' => $autoRecalculate, ':created_by' => $userId,
        ]);
        $runId = (int)$this->db->lastInsertId();
        $this->logAudit($runId, null, 'draft', 'create', $userId);
        $result = ['status' => true, 'message' => 'Created successfully.', 'id' => $runId];
        if ($syncSummary !== null) {
            $result['sync_summary'] = $syncSummary;
        }
        // A Pending-Pull run's membership is fixed by the sync payload itself (see recalculate()'s
        // sync_process_id branch) -- there's no "pick who's in it" step for the admin to do first,
        // unlike a normal cycle-based run where Recalculate is a deliberate review checkpoint. Left
        // uncalculated here, the run would sit at employee_count=0 on the list until someone opened
        // it and clicked Recalculate manually, which reads as "the pull didn't bring employees in"
        // rather than "an extra step is needed". Calling it right away makes the count accurate the
        // moment the run appears in the list. Best-effort: if this fails for some reason the run
        // still exists as a normal draft, just still at 0 until a manual Recalculate.
        if ($syncProcessId !== null) {
            $calcResult = $this->recalculate($runId, $compId, $userId, $isAdmin);
            if (!empty($calcResult['status'])) {
                $result['employee_count'] = $calcResult['employee_count'];
                $result['has_validation_errors'] = $calcResult['has_validation_errors'];
            }
        }

        // 2026-09-06: unified "waiting merge target just became available" detection -- covers BOTH
        // the pre-existing Origami-attribution case (a supplemental process attributed
        // tax_treatment='merge' to THIS SAME Origami process, by origami_process_id -- only possible
        // when THIS run itself was pulled from a regular sync process) AND the new manual/future-
        // cycle case just added (any OTHER off-cycle run whose merge_target_cycle_id+period exactly
        // matches THIS run's own cycle_id+period -- true regardless of whether THIS run came from a
        // normal "Add", a Pull-to-Run, or a Bulk Pull, since all 3 paths go through this one create()
        // method and all 3 can equally be "the round someone else was waiting for"). Confirmed via
        // AskUserQuestion: never auto-merged silently either way -- only ever surfaced here for the
        // admin to confirm, merging changes the target's own gross pay/tax. Each item carries its own
        // `type` so the caller knows which merge endpoint applies (`mergeSupplementalIntoRun()` for
        // 'sync', `mergeIntoExistingRun()` for 'manual').
        $pendingMergesReady = [];
        // Deliberately excludes a supplemental pull itself (!$syncIsSupplemental) -- a supplemental
        // process is never a valid merge TARGET, only a source.
        if ($syncProcessId !== null && !$syncIsSupplemental && $syncOrigamiProcessId !== null) {
            $stmtPendingMerge = $this->db->prepare("SELECT id, process_no, process_subject FROM `payroll_sync_processes`
                WHERE comp_id = :comp_id AND run_kind = 'supplemental' AND status = 'pending'
                  AND merged_into_run_id IS NULL AND attribution_tax_treatment = 'merge'
                  AND attribution_target_origami_process_id = :target_origami_id");
            $stmtPendingMerge->execute([':comp_id' => $compId, ':target_origami_id' => $syncOrigamiProcessId]);
            foreach ($stmtPendingMerge->fetchAll(PDO::FETCH_ASSOC) as $sp) {
                $pendingMergesReady[] = ['id' => (int)$sp['id'], 'label' => $sp['process_subject'] ?: $sp['process_no'], 'type' => 'sync'];
            }
        }
        if ($cycleId !== null) {
            // 2026-09-07: was an exact `merge_target_period_start_date = :start AND
            // merge_target_period_end_date = :end` match against THIS new run's own period -- now
            // matches by PAYMENT MONTH instead (this run's own $payDate against the waiting target's
            // stored merge_target_period_start_date, whose year/month stands in for "target month" --
            // see findActiveRunForCyclePaymentMonth()'s own docblock for the full reasoning, same
            // change applied here for the auto-detect path since a run can arrive via Add/Pull/Bulk
            // Pull in any order relative to when the future-target was set up).
            $stmtFutureMerge = $this->db->prepare("SELECT id, run_name FROM `payroll_runs`
                WHERE comp_id = :comp_id AND state = 'draft' AND deleted_at IS NULL
                  AND merge_target_run_id IS NULL AND merge_target_cycle_id = :cycle_id
                  AND YEAR(merge_target_period_start_date) = YEAR(:pay_date) AND MONTH(merge_target_period_start_date) = MONTH(:pay_date)");
            $stmtFutureMerge->execute([':comp_id' => $compId, ':cycle_id' => $cycleId, ':pay_date' => $payDate]);
            foreach ($stmtFutureMerge->fetchAll(PDO::FETCH_ASSOC) as $fm) {
                // Resolve immediately into the plain, already-fully-tested merge_target_run_id case
                // -- see resolveMergeTargetSpec()'s own docblock for why this is the right moment,
                // and why it means zero new merge-execution code path from here on.
                $this->db->prepare("UPDATE `payroll_runs` SET merge_target_run_id = :target_id,
                        merge_target_cycle_id = NULL, merge_target_period_start_date = NULL, merge_target_period_end_date = NULL,
                        updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id")->execute([':target_id' => $runId, ':updated_by' => $userId, ':id' => $fm['id']]);
                $pendingMergesReady[] = ['id' => (int)$fm['id'], 'label' => $fm['run_name'], 'type' => 'manual', 'target_run_id' => $runId];
            }
        }
        if (!empty($pendingMergesReady)) {
            $result['pending_merges_ready'] = $pendingMergesReady;
        }
        return $result;
    }

    public function update(int $id, int $compId, array $data, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
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
        $notes = array_key_exists('notes', $data) ? (trim((string)$data['notes']) ?: null) : $run['notes'];

        // 2026-09-01, explicit request: "ในการดึงข้อมูลมาทำรอบที่ส่งมาจาก Origami รวมถึงการสร้างเอง ให้
        // สามารถเลือกอ้างอิงรอบได้เหมือนตอน Origami และในหน้า Detail ก็สามารถแก้ไขเพิ่มได้ Form เหมือนหน้า
        // สร้างเลยครับ" -- create() has always let cycle_id be picked, but update() never allowed
        // changing it afterward at all. Confirmed via AskUserQuestion: cycle_id is NOT a soft
        // reference label -- recalculate()'s own cycle-based eligibility query keys off it (which
        // employees are automatically pulled into this run), so changing it is a REAL, calculation-
        // affecting edit, not cosmetic.
        //
        // 2026-09-01, same-day follow-up (explicit push-back: "ก็น่าจะเพิ่มให้สามารถตั้งค่าได้เหมือนกัน
        // ...เหตุผลอะไรบ้างในหน้า Edit ที่ไม่สามารถแก้ไขได้ ควรเปิดให้แก้ไขได้") -- re-examined
        // recalculate()'s own 3 eligibility branches (see its own big comment starting "Pulled from
        // Pending Pull") and found the FIRST version's blanket employee_count===0 gate was broader
        // than the real risk actually requires:
        //   - A sync-linked run's eligibility branch (checked FIRST, before cycle_id is even
        //     considered) never reads cycle_id at all -- changing it there can't affect who gets
        //     pulled in, full stop. Free to change regardless of employee_count.
        //   - Switching between two DIFFERENT real cycles on an already cycle-linked run stays
        //     within the exact same eligibility branch (same query shape, just a different
        //     :cycle_id parameter) -- no different in kind from editing period_start/period_end,
        //     which this same method has ALWAYS allowed with zero gating despite having the exact
        //     same "changes who's eligible on the next Recalculate" effect. Free to change
        //     regardless of employee_count, for consistency with that existing precedent.
        //   - The ONE genuinely risky case: a non-sync run flipping cycle_id between null
        //     (off-cycle) and a real id (cycle-linked). recalculate()'s off-cycle branch reads
        //     ONLY payroll_run_manual_employees; its cycle-based branch reads ONLY the date-range/
        //     employees.cycle_id match and never joins payroll_run_manual_employees at all -- so
        //     flipping that toggle on a run that already has anyone in it (auto-included OR
        //     manually joined) can leave STALE data behind if nobody remembers to click
        //     Recalculate afterward: submit()'s own safety net (assertCalculationClean() + an
        //     employee_count>0 check) never actually detects "the employee list no longer matches
        //     this run's current cycle_id" -- every existing row would still read calc_status=
        //     'calculated' from before the toggle. Rather than hard-blocking this transition (the
        //     first version here did, before this same-day follow-up), this save now forces one
        //     real recalculate() itself immediately after, in the SAME transaction, whenever this
        //     specific toggle happens on a run that already has employees -- same "auto-recalculate"
        //     precedent PayrollRunModel::setAutoRecalculate() already established elsewhere on this
        //     page, applied unconditionally here (not gated on that per-run opt-in flag) because
        //     THIS transition can never be allowed to save without also becoming internally
        //     consistent -- there is no safe "edit now, remember to recalculate later" path for it
        //     the way there is for every other field this method touches.
        $forceRecalculateAfterSave = false;
        $cycleId = $run['cycle_id'] !== null ? (int)$run['cycle_id'] : null;
        if (array_key_exists('cycle_id', $data)) {
            $newCycleId = !empty($data['cycle_id']) ? (int)$data['cycle_id'] : null;
            if ($newCycleId !== $cycleId) {
                $isNonSyncOffCycleToggle = $run['sync_process_id'] === null && ($newCycleId === null) !== ($cycleId === null);
                if ($isNonSyncOffCycleToggle && (int)($run['employee_count'] ?? 0) > 0) {
                    $forceRecalculateAfterSave = true;
                }
                if ($newCycleId !== null) {
                    $stmtCycle = $this->db->prepare("SELECT id FROM `payroll_cycles` WHERE id = :id AND comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL");
                    $stmtCycle->execute([':id' => $newCycleId, ':comp_id' => $compId]);
                    if (!$stmtCycle->fetch()) {
                        return ['status' => false, 'message' => 'Invalid or inactive payroll cycle.'];
                    }
                } elseif ($run['sync_process_id'] !== null && ($run['sync_run_kind'] ?? 'regular') !== 'supplemental') {
                    // Same rule create() already enforces for a fresh pull: a regular (non-
                    // supplemental) sync-linked run is inherently cycle-based data, so it can never
                    // be cleared down to no cycle at all -- only a genuinely off-cycle or
                    // supplemental-sync run can go cycle-less.
                    return ['status' => false, 'message' => 'A payroll cycle is required for a run pulled from a regular sync process.'];
                }
                $cycleId = $newCycleId;
            }
        }
        if ($this->isDuplicatePeriod($compId, $cycleId, $start, $end, $id)) {
            return ['status' => false, 'message' => 'A payroll run already exists for this cycle and period.'];
        }

        // 2026-09-01, same-day follow-up, explicit request: "เพิ่มในหน้า Detail ให้ด้วยครับ" -- the
        // merge_target_run_id create() itself has always accepted was set-once, no way to change or
        // clear it afterward from the UI at all. Editable here now the same way cycle_id just
        // became: uses the (possibly just-changed) $cycleId above, same "only a genuine off-schedule
        // run" rule create() enforces. Clearing it (empty/null value) is always allowed regardless of
        // $cycleId -- "cancel the merge plan, keep this run standalone" needs no gate at all, same
        // as switching cycle_id back to null itself needs no special permission beyond what the
        // cycle_id block above already grants.
        //
        // 2026-09-06: shares resolveMergeTargetSpec() with create() now -- same "future cycle period"
        // capability editable here too, see that method's own docblock.
        $mergeTargetSpec = $this->resolveMergeTargetSpec(
            $compId, $cycleId, $run['sync_process_id'] !== null ? (int)$run['sync_process_id'] : null, $data,
            $run['merge_target_run_id'] !== null ? (int)$run['merge_target_run_id'] : null,
            $run['merge_target_cycle_id'] !== null ? (int)$run['merge_target_cycle_id'] : null,
            $run['merge_target_period_start_date'] ?: null, $run['merge_target_period_end_date'] ?: null,
            $id
        );
        if (empty($mergeTargetSpec['status'])) {
            return $mergeTargetSpec;
        }
        $mergeTargetRunId = $mergeTargetSpec['merge_target_run_id'];
        $mergeTargetCycleId = $mergeTargetSpec['merge_target_cycle_id'];
        $mergeTargetPeriodStart = $mergeTargetSpec['merge_target_period_start_date'];
        $mergeTargetPeriodEnd = $mergeTargetSpec['merge_target_period_end_date'];

        // Run type (compute full payroll vs. an off-cycle/supplemental Incentive/Other Payment
        // pull) is editable on a draft run, same forcing rules as create() -- meaningful for a
        // genuine off-cycle run (no cycle_id/sync_process_id) OR a sync-linked run pulled from a
        // 'supplemental' Origami process (see get()'s own sync_run_kind, and create()'s matching
        // 2026-08-29 relaxation -- PAYROLL_SYNC_API.md's run_kind field). A regular cycle-based/
        // Pending-Pull run keeps whatever create() already forced (always run_purpose='payroll'
        // with all three flags on) regardless of what the request sends, since real payroll can't
        // opt out of base salary/statutory/standing items. 2026-08-28, explicit request: "ในหน้า
        // Process Detail สามารถแก้ไขได้ด้วยว่าคำนวณเงินเดือนหรือรายรับรายหักอื่นไหม หรือเป็นการดึงมาทำจ่าย
        // แยก". Uses the (possibly just-changed) $cycleId above, not $run['cycle_id'], so switching
        // INTO a cycle via this same save forces the normal-payroll invariant immediately (same as a
        // fresh create() would), rather than leaving a stale run_purpose='incentive' on a now-
        // cycle-linked run.
        $runPurpose = $run['run_purpose'];
        $computeStatutory = (int)$run['compute_statutory'];
        $includeBaseSalary = (int)$run['include_base_salary'];
        $includeStandingItems = (int)$run['include_standing_items'];
        $includeAttendancePay = (int)$run['include_attendance_pay'];
        $useFlatTaxRate = (int)($run['use_flat_tax_rate'] ?? 0);
        $isOffCycle = $cycleId === null && $run['sync_process_id'] === null;
        $isSupplementalSync = $run['sync_process_id'] !== null && ($run['sync_run_kind'] ?? 'regular') === 'supplemental';
        if (($isOffCycle || $isSupplementalSync) && array_key_exists('run_purpose', $data)) {
            $runPurpose = (string)($data['run_purpose'] ?? 'payroll') === 'incentive' ? 'incentive' : 'payroll';
            if ($runPurpose === 'incentive') {
                // 2026-09-09, real bug found and fixed (round-creation flow audit, Bug 1, explicit
                // report of a supplemental run's flat-tax-rate opt-in getting silently wiped by any
                // unrelated edit-save): each of these 5 flags is now only touched when the CLIENT
                // PAYLOAD actually included its own key -- absence means "leave this run's current
                // stored value alone", never "silently reset to 0". This is exactly the hole
                // use_flat_tax_rate fell into: the Edit modal had no matching field/payload key for it
                // at all (Create's own #run_use_flat_tax_rate was never ported over), so the OLD
                // `!empty($data[...]) ? 1 : 0` pattern here -- which correctly zeroes a box that WAS
                // actually unchecked-and-sent -- was instead unconditionally treating "key never
                // existed" the same as "explicitly false", wiping a real, previously-saved flag on
                // every single edit-save regardless of what the admin actually changed. Guarding
                // every flag in this group the same way (not just the one that broke) protects
                // against the identical regression recurring for any of the other 4 if a future edit
                // to this modal ever drops one of their payload keys the same way.
                $computeStatutory = array_key_exists('compute_statutory', $data) ? (!empty($data['compute_statutory']) ? 1 : 0) : $computeStatutory;
                $includeBaseSalary = array_key_exists('include_base_salary', $data) ? (!empty($data['include_base_salary']) ? 1 : 0) : $includeBaseSalary;
                $includeStandingItems = array_key_exists('include_standing_items', $data) ? (!empty($data['include_standing_items']) ? 1 : 0) : $includeStandingItems;
                $includeAttendancePay = array_key_exists('include_attendance_pay', $data) ? (!empty($data['include_attendance_pay']) ? 1 : 0) : $includeAttendancePay;
                $useFlatTaxRate = array_key_exists('use_flat_tax_rate', $data) ? (!empty($data['use_flat_tax_rate']) ? 1 : 0) : $useFlatTaxRate;
            } else {
                $computeStatutory = 1;
                $includeBaseSalary = 1;
                $includeStandingItems = 1;
                $includeAttendancePay = 0;
                $useFlatTaxRate = 0;
            }
        } elseif (!$isOffCycle && !$isSupplementalSync) {
            // Just became (or already was) a regular cycle-linked/Pending-Pull run -- same
            // always-on invariant create() enforces for that case, regardless of whatever
            // run_purpose/flags this row happened to carry from before the cycle was assigned.
            $runPurpose = 'payroll';
            $computeStatutory = 1;
            $includeBaseSalary = 1;
            $includeStandingItems = 1;
            $includeAttendancePay = 0;
            $useFlatTaxRate = 0;
        }

        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET run_name = :run_name, cycle_id = :cycle_id,
            merge_target_run_id = :merge_target_run_id,
            merge_target_cycle_id = :merge_target_cycle_id,
            merge_target_period_start_date = :merge_target_period_start_date,
            merge_target_period_end_date = :merge_target_period_end_date,
            period_start_date = :start, period_end_date = :end, payment_date = :pay_date, notes = :notes,
            run_purpose = :run_purpose, compute_statutory = :compute_statutory,
            include_base_salary = :include_base_salary, include_standing_items = :include_standing_items,
            include_attendance_pay = :include_attendance_pay, use_flat_tax_rate = :use_flat_tax_rate,
            updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id");
        $stmt->execute([
            ':run_name' => $runName, ':cycle_id' => $cycleId, ':merge_target_run_id' => $mergeTargetRunId,
            ':merge_target_cycle_id' => $mergeTargetCycleId,
            ':merge_target_period_start_date' => $mergeTargetPeriodStart,
            ':merge_target_period_end_date' => $mergeTargetPeriodEnd,
            ':start' => $start, ':end' => $end, ':pay_date' => $payDate,
            ':notes' => $notes, ':run_purpose' => $runPurpose, ':compute_statutory' => $computeStatutory,
            ':include_base_salary' => $includeBaseSalary, ':include_standing_items' => $includeStandingItems,
            ':include_attendance_pay' => $includeAttendancePay, ':use_flat_tax_rate' => $useFlatTaxRate,
            ':updated_by' => $userId, ':id' => $id,
        ]);
        $this->logAudit($id, 'draft', 'draft', 'update', $userId);
        if ($forceRecalculateAfterSave) {
            // Same "own transaction if not already inside one" pattern this whole file uses
            // everywhere (runSettingsSave() already returns recalculate()'s own result the same
            // way) -- runs inside the SAME transaction as the cycle_id UPDATE above, so
            // recalculate()'s own fresh $this->get($id, $compId) sees the just-saved new cycle_id,
            // not a stale pre-update read.
            $recalcResult = $this->recalculate($id, $compId, $userId, $isAdmin);
            if (empty($recalcResult['status'])) {
                // Extremely unlikely (recalculate() only fails on permission/state, both already
                // checked above in this same method) -- surfaced as-is rather than silently
                // swallowed, since a failure here means the save itself is NOT actually consistent.
                return $recalcResult;
            }
            return ['status' => true, 'message' => 'Updated and recalculated successfully.', 'employee_count' => $recalcResult['employee_count'] ?? null];
        }
        return ['status' => true, 'message' => 'Updated successfully.'];
    }

    // 2026-08-28, explicit request: "Process ที่ Cancel ให้สามารถลบข้อมูลออกไปได้" -- a cancelled run
    // used to be a dead end (state='cancelled' forever, no way to remove it from the list). Now
    // deletable the same way a draft is -- state itself is untouched by delete() (still whatever it
    // was, 'draft' or 'cancelled'), this only ever flips the SEPARATE soft-delete `status` column
    // (active/deleted), same as before. Deliberately still NOT extended to any other state
    // (pending_approval/approved/rejected/need_info/paid/locked) -- those either have money in
    // flight or already moved, same reasoning cancel() itself already applies when deciding which
    // states are even cancellable in the first place; a run must be cancelled (or never left draft)
    // before it can be deleted, there is no way to jump straight from e.g. approved to deleted.
    public function delete(int $id, int $compId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to delete this payroll run.'];
        }
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if (!in_array($run['state'], ['draft', 'cancelled'], true)) {
            return ['status' => false, 'message' => 'Only a draft or cancelled payroll run can be deleted.'];
        }
        $fromState = $run['state'];
        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) { $this->db->beginTransaction(); }
            $this->db->prepare("DELETE FROM `payroll_run_details` WHERE run_id = :id")->execute([':id' => $id]);
            // sync_process_id = NULL unlinks this run from whatever payroll_sync_processes row it
            // was pulled from (per explicit request) -- pendingList()'s own query is just "no
            // payroll_runs row currently references this sync process", so clearing the FK here is
            // enough to make it reappear on the Pending Pull station, ready to be pulled again. Also
            // required to free up the UNIQUE constraint on sync_process_id for a future re-pull. A
            // no-op (NULL -> NULL) for a run that was never pulled from a sync process (or already
            // NULL'd out by cancel() itself, for a cancelled run reaching this point).
            $stmt = $this->db->prepare("UPDATE `payroll_runs` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by, sync_process_id = NULL WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
            $this->logAudit($id, $fromState, $fromState, 'delete', $userId);
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
     *    assignments) and recurring earnings feed earnings/deductions beyond base pay.
     *  - taxable_income context for the statutory engine is estimated as gross * 12
     *    (annualized), not the employee's actual tax_calculation_method (average/actual).
     *  - Pro-rate accounts for both mid-period joiners (employment_date) and mid-period
     *    leavers (employment_end_date).
     * All of the above are flagged here and in the class docblock, not hidden.
     */
    /**
     * 2026-08-31, explicit request: "Form ที่เป็นรายการหัก...ถ้าค่าธรรมเนียมให้ใส่ได้เป็น % คิดจากอะไร...ต้อง
     * นำไปรวมคำนวณได้ถูกต้อง" -- $rec is one row from EmployeeRecurringDeductionModel::activeForPeriod()
     * (carries `amount`/`fee_percent`/`fee_base`), $baseSalary is THIS employee's own decrypted
     * current base_salary_amount (already resolved earlier in the same per-employee iteration this
     * is called from). fee_base only ever validates to 'base_salary' for this table (see that
     * model's own migration comment -- no principal/loan-amount concept exists here), so this is
     * intentionally a single-branch helper, not a mirror of the loan side's 2-option fee_base.
     */
    private function recurringDeductionAmountWithFee(array $rec, float $baseSalary): float {
        $amount = (float)$rec['amount'];
        if (!empty($rec['fee_percent']) && ($rec['fee_base'] ?? null) === 'base_salary') {
            $amount += round($baseSalary * ((float)$rec['fee_percent'] / 100), 2);
        }
        return round($amount, 2);
    }

    /**
     * 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 6 -- $rec is one row from
     * EmployeeRecurringDeductionModel::activeForPeriod() (carries the TEMPLATE's own
     * payee_type/payee_employee_id/destination_id). $overridesByRecurringId is this run's own
     * prefetched payroll_run_recurring_deduction_overrides, keyed by recurring_id (see recalculate()'s
     * own prefetch a few hundred lines up). An override, when present, wins outright -- never merged
     * field-by-field with the template (e.g. an override that only sets payee_type='company' still
     * fully replaces payee_employee_id/destination_id with null, exactly as if the admin had picked
     * "Company" fresh in the destination picker for this run).
     */
    private function resolveRecurringDeductionPayee(array $rec, array $overridesByRecurringId): array {
        $override = $overridesByRecurringId[(int)$rec['recurring_id']] ?? null;
        if ($override !== null) {
            return [
                'payee_type' => $override['payee_type'],
                'payee_employee_id' => $override['payee_employee_id'] !== null ? (int)$override['payee_employee_id'] : null,
                'destination_id' => $override['destination_id'] !== null ? (int)$override['destination_id'] : null,
                // 2026-09-10, Batch 3B item 3: same "override wins outright, never merged field-by-
                // field" rule this method's own docblock already states for payee_employee_id/
                // destination_id -- bank_account_id follows it too.
                'bank_account_id' => $override['bank_account_id'] !== null ? (int)$override['bank_account_id'] : null,
            ];
        }
        return [
            'payee_type' => $rec['payee_type'] ?? null,
            'payee_employee_id' => ($rec['payee_employee_id'] ?? null) !== null ? (int)$rec['payee_employee_id'] : null,
            'destination_id' => ($rec['destination_id'] ?? null) !== null ? (int)$rec['destination_id'] : null,
            'bank_account_id' => ($rec['bank_account_id'] ?? null) !== null ? (int)$rec['bank_account_id'] : null,
        ];
    }

    public function recalculate(int $id, int $compId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
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
        // 2026-08-29, explicit request: "กรณีคนเข้า และคนออก การคิดเงินเดือน ต้องจับหาร 30 ตามกฏหมาย...ตอนนี้
        // หารจำนวนวันจริงของเดือนครับ" -- a monthly-rate employee's mid-period join/leave proration
        // used $totalPeriodDays (the REAL number of days in that specific period -- 28/29/30/31)
        // as its denominator; Thai labor law instead uses a FIXED divisor (30, the same value
        // regardless of the actual month length) so the daily-equivalent rate doesn't silently
        // shift between a 28-day February and a 31-day January. Now company-configurable via
        // companies.prorate_divisor_days (Company Profile, default 30 -- see that column's own
        // migration comment) -- used ONLY as the denominator below; $totalPeriodDays itself is
        // still used unchanged as the sanity cap on how many days an employee could possibly have
        // been present within the real period (a separate concern from which number to divide by).
        $stmtProrateDivisor = $this->db->prepare("SELECT prorate_divisor_days FROM `companies` WHERE id = :id");
        $stmtProrateDivisor->execute([':id' => $compId]);
        $prorateDivisorDays = (int)($stmtProrateDivisor->fetchColumn() ?: 30);

        // 2026-08-30 (Phase 8, T041): "no attendance/OT/leave data at all this period" is only a
        // meaningful WARNING for a company actually linked to Origami Payroll sync (a company on
        // that tier reasonably expects Origami to have sent SOMETHING every period) -- for a
        // manual-entry-only or import-batch company (CLAUDE.md's "No HR user"/"External HR user"
        // tiers) a genuinely empty period is completely normal and would make this warning pure
        // noise on every single run. Fetched once per run, same "cheap company-wide singleton"
        // precedent as $prorateDivisorDays above -- gates the advisory calc_errors note pushed per
        // employee further down, see that block's own comment for the full reasoning.
        $stmtOrigamiLinked = $this->db->prepare("SELECT origami_payroll_comp_code FROM `companies` WHERE id = :id");
        $stmtOrigamiLinked->execute([':id' => $compId]);
        $isOrigamiPayrollLinked = (string)($stmtOrigamiLinked->fetchColumn() ?: '') !== '';
        // Same "genuine cycle-based run, not a sync-based one" test buildManualEmployeeWhere()'s own
        // $isPureCycleRun already uses -- a sync-based run's eligibility query already guarantees
        // every included employee has a $syncItemsByEmployee row (see that query's own
        // `psi.employee_id IS NOT NULL OR pme.employee_id IS NOT NULL` condition), and an off-cycle
        // run never populates $syncItemsByEmployee for anyone at all -- so this warning is only ever
        // meaningful (and only ever false-positive-free) on THIS one run type.
        $isPureCycleRunForDataWarning = $run['cycle_id'] !== null && $run['sync_process_id'] === null;

        // 2026-08-30, explicit request: probation pay conditions (Payroll Configuration > Payroll
        // Policies tab) -- fetched ONCE per run (company-wide singleton), not per employee. Gated
        // per employee below by `employees.employment_status === 'probation'` -- see
        // PayrollPolicyModel::probationSettings()'s own docblock for why that's the real gate, not
        // a day-count.
        $probationSettings = $this->policyModel->probationSettings($compId);
        // 2026-08-31, explicit request: internship pay conditions ("เด็กฝึกงานบางคนที่ให้เงินเดือน แต่อยากให้
        // ตั้งเงื่อนไขได้แบบ Probation") -- own separate field set, same "fetched once per run" shape as
        // $probationSettings above. Gated by employees.employment_type === 'internship'.
        // PRECEDENCE when an employee is somehow BOTH employment_type='internship' AND
        // employment_status='probation' at once: internship settings take priority and probation's
        // own settings are skipped entirely for that employee -- confirmed via AskUserQuestion, never
        // stacking/multiplying both ratios together (a more specific worker classification wins over
        // the more general one, same spirit as this project's own "employee > position > department >
        // shift" Holiday-priority precedent elsewhere).
        $internSettings = $this->policyModel->internSettings($compId);
        // 2026-08-30, explicit request: "จ่ายตามวันที่มาทำงาน หักวันหยุด หักวันลาไหม หรือจ่ายเต็มเดือน" --
        // same "fetched once per run, not per employee" precedent as $probationSettings above. See
        // SetupRulesModel::scheduledPayableDaysForEmployee()'s own docblock for the calculation this
        // feeds ('full_month', the default, is a no-op -- read at the base-salary block further down).
        // Same-day follow-up: gated by employees.employment_status === 'probation' too (same real
        // gate $probationSettings' own fields use) -- see the base-salary block's own comment.
        $payBasisSettings = $this->policyModel->payBasisSettings($compId);
        // 2026-08-31, explicit request: "เงื่อนไขการจ่ายเงินเด็กฝึกงาน...จ่ายเต็มเดือน หรือจ่ายแค่วันที่มาทำจริง
        // หักลา หักวันหยุดไหม เหมือน Probation" -- direct mirror of $payBasisSettings above, own
        // separate field set. Gated by employees.employment_type === 'internship' at the base-salary
        // block below, taking PRECEDENCE over $payBasisSettings' own probation gate when an employee
        // is somehow both (same precedence already established for the ratio/defer flags above).
        $internPayBasisSettings = $this->policyModel->internPayBasisSettings($compId);

        // 2026-08-31, explicit request ("ทำทั้ง 3 ข้อเลย", item 3 of the Origami `attribution`
        // plan's own deferred list): a company-configured flat withholding % used ONLY when this
        // run explicitly opted in via payroll_runs.use_flat_tax_rate (forced 0 for every normal
        // 'payroll' run, see create()/update()'s own comments on that column) -- substitutes for
        // the normal average/actual ThPitCalculator call in the TH_PIT block below. Fetched once
        // per run, same "company-wide singleton, not per employee" precedent as every other policy
        // setting above. A null company rate (never configured) means the flag is a no-op and the
        // normal calculation is used instead -- never silently invents a rate or withholds 0%.
        $useFlatTaxRate = !empty($run['use_flat_tax_rate']);
        $flatTaxRatePercent = $useFlatTaxRate ? $this->policyModel->flatTaxRatePercent($compId) : null;

        // 2026-09-02, explicit request following an AskUserQuestion exchange: a company-configured
        // flat withholding % for employees individually flagged tax_non_resident (employees table)
        // -- same "company-configurable, never a hardcoded rate this app asserts" precedent as
        // $flatTaxRatePercent immediately above (see NonResidentTaxSettingModel's own docblock for
        // why -- normal Thai PIT progressive withholding on Thailand-source salary is actually the
        // SAME regardless of residency, this exists only because the user's own accountant may
        // apply a different rate this app has no basis to assume). Null when the company never
        // configured/enabled it -- the flag then behaves exactly as if it didn't exist.
        $nonResidentFlatRatePercent = $this->nonResidentTaxSettingModel->activeFlatRatePercent($compId);

        // 2026-08-21, real bug fix: needed to correctly annualize/de-annualize TH_PIT withholding
        // (see ThPitCalculator) -- $run['payroll_frequency'] already comes from get()'s own LEFT
        // JOIN to payroll_cycles, so this is free; defaults to monthly (the existing implicit
        // assumption everywhere else in this file) when cycle_id is null (sync/off-cycle runs).
        $periodsPerYearByFrequency = ['monthly' => 12, 'semi_monthly' => 24, 'bi_weekly' => 26, 'weekly' => 52];
        $periodsPerYear = $periodsPerYearByFrequency[$run['payroll_frequency'] ?? 'monthly'] ?? 12;

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
        // 2026-08-30 (Phase 3, T021, explicit request: "ไม่จ่ายเงินเดือน...ดึงไปทำรายการไม่ได้") -- ALL
        // 3 branches below now also require e.is_payroll_participant = 1 (or the bare
        // is_payroll_participant on the cycle branch, which doesn't alias the table). A staff-only
        // employee is excluded even if they were previously manually joined/re-included before being
        // marked unpaid -- the same buildManualEmployeeWhere() filter also stops the "Join Employees"
        // picker from offering them in the first place, but this covers the case where the exclusion
        // needs to win over already-existing membership too (e.g. marked unpaid AFTER being joined).
        if ($run['sync_process_id'] !== null) {
            // 2026-08-21, explicit request ("เพิ่มพนักงานเข้ามาในรอบได้แบบ Manual...ถ้าเป็นการ Sync")
            // -- membership is no longer sync-payload-only: an employee manually joined via
            // payroll_run_manual_employees (same table/mechanism a genuine off-cycle run already
            // used) is now ALSO included on a sync-based run, on top of whoever Origami actually
            // sent. data_source ('sync' vs 'manual') is computed per row here and threaded straight
            // through to payroll_run_details.data_source below (that column already existed with
            // this exact enum, just never wired to reality before now) -- 'sync' wins if somehow
            // both sides match, since that employee's real attendance data IS what's driving their
            // calculation regardless of also being manually rostered.
            $stmtEmp = $this->db->prepare("SELECT DISTINCT e.id, e.employee_no, e.base_salary_amount, e.key_version, e.employment_date, e.employment_end_date,
                    e.sso_enrolled, e.pvd_enrolled, e.tax_exempt, e.is_payroll_ready, e.ot_eligible, e.ot_rate_source, e.assigned_ot_rate_set_id,
                    e.has_spouse, e.tax_calculation_method, e.tax_non_resident, e.salary_type, e.department_id, e.team_id, e.position_id, e.employment_status,
                    e.employment_type, e.intern_base_salary_ratio_override, e.probation_base_salary_ratio_override, e.probation_defer_pvd_override, e.probation_defer_sso_override, e.probation_defer_recurring_earning_override, e.intern_defer_pvd_override, e.intern_defer_sso_override, e.intern_defer_recurring_earning_override, e.sso_contribution_rate, e.sso_employer_contribution_rate, e.pvd_start_date, e.pvd_employee_rate, e.pvd_employer_rate, e.payment_method_id,
                    CASE WHEN psi.employee_id IS NOT NULL THEN 'sync' ELSE 'manual' END AS data_source
                FROM `employees` e
                LEFT JOIN `payroll_sync_items` psi ON psi.process_id = :process_id AND psi.employee_id = e.id AND psi.mapping_status = 'mapped'
                LEFT JOIN `payroll_run_manual_employees` pme ON pme.run_id = :run_id AND pme.employee_id = e.id
                WHERE e.comp_id = :comp_id AND e.deleted_at IS NULL AND e.is_payroll_participant = 1
                AND (psi.employee_id IS NOT NULL OR pme.employee_id IS NOT NULL)
                AND NOT EXISTS (SELECT 1 FROM `payroll_run_excluded_employees` pex WHERE pex.run_id = :run_id_exclude AND pex.employee_id = e.id)");
            $stmtEmp->execute([':comp_id' => $compId, ':process_id' => $run['sync_process_id'], ':run_id' => $id, ':run_id_exclude' => $id]);
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
            //
            // 2026-08-30 (Phase 8, T041, real bug found and fixed -- reproduced live, not guessed:
            // created 2 payroll_cycles on the same company, an employee assigned only to Cycle A via
            // employees.cycle_id, then ran payroll under Cycle B for the same period -- the employee
            // was pulled into and paid by Cycle B's run too, since this query never filtered by
            // employees.cycle_id at all despite that column existing since 2026-08-19 specifically to
            // assign an employee to one standing cycle [[project_employee_salary_cycle_field]]. Any
            // company running more than one concurrent cycle was at real risk of cross-cycle
            // double-inclusion/double-payment). Fixed with `e.cycle_id IS NULL OR e.cycle_id =
            // :cycle_id`, NOT a strict equality-only filter -- employees.cycle_id is nullable/optional
            // (a company that only ever runs a single cycle never has to assign it at all), so a
            // strict filter would have silently dropped every never-assigned employee out of their
            // only cycle's run. NULL = "not scoped to any particular cycle, eligible everywhere" (same
            // "unassigned = general, explicitly assigned = scoped" convention this codebase already
            // uses for Holiday/Payslip Template assignment); an employee with cycle_id explicitly set
            // is eligible ONLY for that cycle's own runs, closing the leakage.
            $stmtEmp = $this->db->prepare("SELECT id, employee_no, base_salary_amount, key_version, employment_date, employment_end_date,
                    sso_enrolled, pvd_enrolled, tax_exempt, is_payroll_ready, ot_eligible, ot_rate_source, assigned_ot_rate_set_id,
                    has_spouse, tax_calculation_method, tax_non_resident, salary_type, department_id, team_id, position_id, employment_status,
                    employment_type, intern_base_salary_ratio_override, probation_base_salary_ratio_override, probation_defer_pvd_override, probation_defer_sso_override, probation_defer_recurring_earning_override, intern_defer_pvd_override, intern_defer_sso_override, intern_defer_recurring_earning_override, sso_contribution_rate, sso_employer_contribution_rate, pvd_start_date, pvd_employee_rate, pvd_employer_rate, payment_method_id, 'manual' AS data_source
                FROM `employees` e
                WHERE comp_id = :comp_id AND deleted_at IS NULL AND is_payroll_participant = 1
                AND employment_date <= :period_end
                AND (employment_end_date IS NULL OR employment_end_date >= :period_start)
                AND (e.cycle_id IS NULL OR e.cycle_id = :cycle_id)
                AND NOT EXISTS (SELECT 1 FROM `payroll_run_excluded_employees` pex WHERE pex.run_id = :run_id_exclude AND pex.employee_id = e.id)");
            $stmtEmp->execute([':comp_id' => $compId, ':period_end' => $periodEnd, ':period_start' => $periodStart, ':cycle_id' => $run['cycle_id'], ':run_id_exclude' => $id]);
        } else {
            $stmtEmp = $this->db->prepare("SELECT e.id, e.employee_no, e.base_salary_amount, e.key_version, e.employment_date, e.employment_end_date,
                    e.sso_enrolled, e.pvd_enrolled, e.tax_exempt, e.is_payroll_ready, e.ot_eligible, e.ot_rate_source, e.assigned_ot_rate_set_id,
                    e.has_spouse, e.tax_calculation_method, e.tax_non_resident, e.salary_type, e.department_id, e.team_id, e.position_id, e.employment_status,
                    e.employment_type, e.intern_base_salary_ratio_override, e.probation_base_salary_ratio_override, e.probation_defer_pvd_override, e.probation_defer_sso_override, e.probation_defer_recurring_earning_override, e.intern_defer_pvd_override, e.intern_defer_sso_override, e.intern_defer_recurring_earning_override, e.sso_contribution_rate, e.sso_employer_contribution_rate, e.pvd_start_date, e.pvd_employee_rate, e.pvd_employer_rate, e.payment_method_id, 'manual' AS data_source
                FROM `payroll_run_manual_employees` pme
                JOIN `employees` e ON e.id = pme.employee_id AND e.comp_id = :comp_id AND e.deleted_at IS NULL AND e.is_payroll_participant = 1
                WHERE pme.run_id = :run_id");
            $stmtEmp->execute([':comp_id' => $compId, ':run_id' => $id]);
        }
        $employees = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);
        // 2026-08-26, explicit request: "ตัวข้อมูลเงินเดือน...ไม่ต้องการให้เห็นตัวเลขตรงๆในฐานข้อมูล" --
        // employees.base_salary_amount is now AES-256-GCM ciphertext (EmployeeModel::encryptedColumns()),
        // decrypted here once for the whole batch -- same on-demand-decrypt-at-point-of-use convention
        // this project already uses for tax_id_no/bank_account_no/sso_no elsewhere (e.g.
        // EmployeePiiTrait::decryptEmployeeField()), not centralized inside a shared model method.
        foreach ($employees as &$emp) {
            $emp['base_salary_amount'] = EmployeeModel::decryptSalaryValue($emp['base_salary_amount'] ?? null, isset($emp['key_version']) ? (int)$emp['key_version'] : null);
        }
        unset($emp);

        // 2026-09-02, explicit request: "การตั้งค่าเงินรวมกันถ้าเกินจำนวนเงินเดือนมีการดักส่วนนี้ไว้ไหม" --
        // small master table, fetched once for the whole batch (same "fetched once here rather than
        // per-employee inside the loop below" convention as $syncItemsByEmployee right below) rather
        // than a per-employee EmployeePaymentMethodModel::findMethod() query -- used by the mixed-
        // payment reconciliation check further down, once each row's own net pay is known.
        $paymentMethodCodesById = [];
        $stmtPaymentMethods = $this->db->query("SELECT id, code FROM `master_payment_methods`");
        foreach ($stmtPaymentMethods->fetchAll(PDO::FETCH_ASSOC) as $pm) {
            $paymentMethodCodesById[(int)$pm['id']] = $pm['code'];
        }

        // Sync-derived earning/deduction lines (OT/trip allowance/late/absent/item_values), keyed
        // by employee_id -- fetched once here rather than per-employee inside the loop below. Not
        // JOINed into the eligibility SELECT above (no unique constraint on
        // payroll_sync_items(process_id, employee_id) at the DB level -- see SyncPayResolver's
        // docblock -- so a JOIN there would risk duplicating eligibility rows under DISTINCT
        // semantics; a separate keyed fetch avoids that entirely). Only populated for a sync-based
        // run -- $syncItemsByEmployee stays empty for cycle-based/off-cycle runs, so
        // SyncPayResolver is never invoked for them below.
        $syncItemsByEmployee = [];
        if ($run['sync_process_id'] !== null) {
            $stmtSyncItems = $this->db->prepare("SELECT * FROM `payroll_sync_items`
                WHERE process_id = :process_id AND mapping_status = 'mapped' AND employee_id IS NOT NULL
                ORDER BY id ASC");
            $stmtSyncItems->execute([':process_id' => $run['sync_process_id']]);
            foreach ($stmtSyncItems->fetchAll(PDO::FETCH_ASSOC) as $psi) {
                $psi['item_values'] = $psi['item_values'] !== null ? json_decode((string)$psi['item_values'], true) : [];
                $syncItemsByEmployee[(int)$psi['employee_id']] = $psi; // last row wins if duplicates exist
            }
        } elseif ($run['cycle_id'] !== null) {
            // 2026-08-30 (Phase 5, T032, explicit request: "ถ้าข้อมูล match กับพนักงาน/งวดที่ถูกต้อง
            // ให้นำไปใช้คำนวณ", confirmed via AskUserQuestion) -- a normal cycle-based (non-sync) run
            // now ALSO feeds SyncPayResolver, using attendance_records/leave_requests/
            // overtime_records (Sync/Import/Manual Entry all write into these same 3 tables) instead
            // of payroll_sync_items -- see TransactionDataPayAdapter's own docblock for the full
            // reasoning and its documented simplifications. Scoped to cycle-based runs only, same as
            // PED assignments/recurring earnings just below -- an off-cycle/incentive run stays
            // manually-picked-items-only, untouched by this branch.
            $syncItemsByEmployee = TransactionDataPayAdapter::buildSyntheticRows(
                $this->db, $compId, array_column($employees, 'id'), $periodStart, $periodEnd
            );
        }

        // Per-run, per-employee, per-item overrides on a SYNC-COMPUTED deduction line (2026-08-21,
        // explicit request: "ปรับค่า สาย ขาดงาน ลาไม่รับเงิน หรือยกเว้นไม่ให้หัก") -- prefetched once
        // (not one query per employee) and applied below wherever a sync-derived deduction line is
        // produced. See PayrollRunModel::lineOverrideSave()'s own docblock for the full design; empty
        // for a non-sync run (no sync-derived lines exist there for an override to ever match).
        $overridesByEmployeeAndCode = [];
        $stmtOverrides = $this->db->prepare("SELECT employee_id, item_code, action, override_amount, note
            FROM `payroll_run_line_overrides` WHERE run_id = :run_id");
        $stmtOverrides->execute([':run_id' => $id]);
        foreach ($stmtOverrides->fetchAll(PDO::FETCH_ASSOC) as $ov) {
            $overridesByEmployeeAndCode[(int)$ov['employee_id']][$ov['item_code']] = $ov;
        }

        // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 6 -- per-run override of
        // a recurring deduction's PAYEE (not its amount -- that's still the generic
        // payroll_run_line_overrides mechanism just above), keyed by recurring_id so it can never be
        // confused with the amount override even when both target the same item_code. Prefetched
        // once per run, applied below wherever a recurring_deduction line is built: an override here
        // wins over the template's own payee_type/payee_employee_id/destination_id
        // (employee_recurring_deductions), which stays completely untouched by this.
        $recurringDeductionDestOverridesByRecurringId = [];
        $stmtRddOv = $this->db->prepare("SELECT recurring_id, payee_type, payee_employee_id, destination_id, bank_account_id
            FROM `payroll_run_recurring_deduction_overrides` WHERE run_id = :run_id");
        $stmtRddOv->execute([':run_id' => $id]);
        foreach ($stmtRddOv->fetchAll(PDO::FETCH_ASSOC) as $ov) {
            $recurringDeductionDestOverridesByRecurringId[(int)$ov['recurring_id']] = $ov;
        }

        // Per-run, per-employee correction of the RAW attendance numbers Origami sent (2026-08-21,
        // explicit request: "ต้องการแก้ตัวเลขดิบที่ Sync มา ไม่ใช่แค่ยอดเงิน") -- prefetched once,
        // passed as SyncPayResolver::resolve()'s 4th param below. Column names match
        // payroll_sync_items verbatim (see that table's own docblock in database/payroll.sql), so
        // each row is passed through with zero remapping.
        $attendanceOverridesByEmployee = [];
        $stmtAttOverrides = $this->db->prepare("SELECT * FROM `payroll_run_sync_item_overrides` WHERE run_id = :run_id");
        $stmtAttOverrides->execute([':run_id' => $id]);
        foreach ($stmtAttOverrides->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $attendanceOverridesByEmployee[(int)$row['employee_id']] = $row;
        }

        // 2026-08-30, explicit request ("OT Rate...Assign รายบุคคลได้ด้วย"): every employee's OT rate
        // override rows for this company, prefetched ONCE (not per employee/per scope) -- same
        // "prefetch outside the per-employee loop" convention every other lookup on this page
        // already follows. Keyed employee_id => scope_code => rate array (the exact shape
        // SyncPayResolver::resolve()'s own $otOverridesByScope param expects), so the per-employee
        // loop below just does `$otOverridesByEmployee[$employeeId] ?? []` -- filtering by
        // ot_rate_source='custom' happens per employee below (not here), since a company can freely
        // mix employees on 'default' and 'custom' -- fetching every row regardless of that flag is
        // simpler than joining employees here just to filter, and any leftover override rows for a
        // 'default' employee are simply never looked up. See EmployeeOtRateModel's own docblock.
        $otOverridesByEmployee = [];
        $stmtOtOverrides = $this->db->prepare("SELECT o.employee_id, s.code AS scope_code, o.multiplier_rate, o.calculation_base, o.calculation_method, o.flat_amount_rate
            FROM `employee_ot_rate_overrides` o
            JOIN `master_ot_scope_types` s ON s.id = o.ot_scope_id
            WHERE o.comp_id = :comp_id");
        $stmtOtOverrides->execute([':comp_id' => $compId]);
        foreach ($stmtOtOverrides->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $otOverridesByEmployee[(int)$row['employee_id']][$row['scope_code']] = [
                'multiplier_rate' => (float)$row['multiplier_rate'], 'calculation_base' => (string)$row['calculation_base'],
                'calculation_method' => (string)$row['calculation_method'],
                'flat_amount_rate' => $row['flat_amount_rate'] !== null ? (float)$row['flat_amount_rate'] : 0.0,
            ];
        }

        // 2026-08-30, real gap found and fixed (previous version read the flat `ot_rates` table
        // directly, keyed only by scope, with no way to disambiguate multiple rows for the same
        // scope beyond an arbitrary `ORDER BY id ASC LIMIT 1` -- the exact ambiguity the user's own
        // report named: "ถ้าบันทึกข้อมูลซ้ำ แต่คนละ Rate จะแก้ไขยังไง"). Batched ONCE per run (same
        // "prefetch outside the per-employee loop" convention as every other lookup here) via
        // OtRateSetModel::resolveRatesForEmployees() -- explicit assigned_ot_rate_set_id wins, else
        // employee>team>position>department assignment match, else the company's mandatory Default
        // set. Keyed employee_id => scope_code => rate array, same shape $otOverridesByEmployee
        // above already uses -- $otOverridesByEmployee (the CUSTOM per-employee override) always
        // wins over this when both exist for the same scope, see resolve()'s own docblock.
        $otRateSetRatesByEmployee = $this->otRateSetModel->resolveRatesForEmployees($employees, $compId);

        // Per-run, per-employee tax/SSO exemption (2026-08-21, explicit request: "จัดการได้ว่า
        // คนนี้ไม่ต้องคำนวณภาษี ไม่นำส่งประกันสังคมในรอบนี้") -- prefetched once, merged into
        // $employeeFlags below (Pass 2, right before StatutoryCalculationEngine::calculate()) on
        // top of the employee's own permanent sso_enrolled/tax_exempt columns. See
        // saveEmployeeExemption()'s own docblock for the full design.
        $exemptionsByEmployee = [];
        $stmtExemptions = $this->db->prepare("SELECT employee_id, exempt_tax, exempt_sso, tax_calculate_override, sso_calculate_override FROM `payroll_run_employee_exemptions` WHERE run_id = :run_id");
        $stmtExemptions->execute([':run_id' => $id]);
        foreach ($stmtExemptions->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $exemptionsByEmployee[(int)$row['employee_id']] = $row;
        }

        // 2026-08-29, explicit request: "เพิ่มให้สามารถเลือกเอาเงินเดือนออกจากการคำนวณได้ หรือค่าอื่นๆที่ไม่
        // นำมาคำนวณ ทั้ง template เลย และกำหนดได้สำหรับพนักงานรายบุคคล ติ๊กเอาหรือไม่เอา...และต้องกำหนดได้ด้วยว่า
        // คำนวณภาษี ไม่คำนวณภาษี ส่งประกันสังคมไหม กำหนดแบบทั้งหมด และรายบุคคลได้" -- run-level defaults
        // ("Run Settings" panel, Process Detail page) applied to every employee below. Applies to
        // every run type (regular sync-driven + off-cycle/manual alike) -- confirmed via
        // AskUserQuestion, not scoped to off-cycle only. A run that never touched either feature
        // gets empty results here and behaves byte-for-byte as before this feature existed.
        //
        // The PER-EMPLOYEE override side of item exclusion deliberately reuses the EXISTING
        // payroll_run_line_overrides mechanism (its own 'exclude' action, prefetched into
        // $overridesByEmployeeAndCode a few lines above -- lineOverrideSave()'s own docblock)
        // instead of a second, parallel per-employee table -- that mechanism already covers
        // "exclude this one item for this one employee" for every earning/deduction line AND (as of
        // this same request) base salary too, via the Manage Items modal's existing "Adjust
        // Amounts" tab, which already has its own tested UI/audit trail. See
        // $applyLineOverrides/$baseSalaryOverride below for exactly how the run-level default here
        // and that per-employee override layer together. Known, accepted simplification: a
        // per-employee override can only ADD an exclusion on top of an included-by-default item
        // (or, for base salary/an item with a real amount, override its VALUE back to something
        // nonzero) -- there is no explicit "force this one item back IN for this one employee" action
        // distinct from supplying an override amount, since the common real-world case this
        // feature was requested for is opting specific people OUT of a run-wide default, not back in.
        $stmtCalcSettings = $this->db->prepare("SELECT tax_calculate_default, sso_calculate_default FROM `payroll_run_calc_settings` WHERE run_id = :run_id");
        $stmtCalcSettings->execute([':run_id' => $id]);
        $calcSettingsRow = $stmtCalcSettings->fetch(PDO::FETCH_ASSOC);
        $runCalcSettings = $calcSettingsRow ?: ['tax_calculate_default' => 'use_employee_setting', 'sso_calculate_default' => 'use_employee_setting'];

        $runItemExclusionCodesUpper = [];
        $stmtItemExcl = $this->db->prepare("SELECT item_code FROM `payroll_run_item_exclusions` WHERE run_id = :run_id");
        $stmtItemExcl->execute([':run_id' => $id]);
        foreach ($stmtItemExcl->fetchAll(PDO::FETCH_COLUMN) as $code) {
            $runItemExclusionCodesUpper[] = strtoupper((string)$code);
        }

        // 2026-08-29, real bug found and fixed (explicit report: "หักประกันสังคมจะไม่ใช่คำนวณจากฐาน
        // อย่างเดียวต้องมาจากที่เราตั้งค่าในรายได้ ว่ารายการไหนหักประกันสังคม ต้องเอามาคำนวณทั้งหมด") --
        // payroll_earning_deduction_types.calc_sso/calc_pf ("Include in SSO contribution base"/
        // "Include in Provident Fund base", set in Payroll Configuration) were being saved but never
        // actually READ anywhere in the calculation engine -- TH_SSO/TH_PVD's own calc_base was
        // hardcoded to 'basic_salary' (see migrations/2026-08-29_sso_pf_eligible_earnings_base.sql),
        // so every allowance/earning item an admin had explicitly flagged as SSO/PF-eligible (e.g.
        // Position Allowance, Commission -- both seeded with calc_sso=calc_pf=1 by
        // PayrollEarningDeductionTypeModel::seedDefaults()) was silently excluded from the actual
        // contribution base regardless of that setting. Prefetched once per run (company-wide, not
        // per-employee) into two item_code sets, checked against each employee's own $earningLines
        // in Pass 2 below (right before the statutory engine call) to build the real eligible-
        // earnings sum. Base salary itself always counts toward both (it's not one of these flagged
        // "earning items" -- it's the foundation both bases start from), matching Thai SSO/PF law's
        // own "total wages" concept rather than base-salary-only.
        $calcSsoItemCodes = [];
        $calcPfItemCodes = [];
        $stmtCalcFlags = $this->db->prepare("SELECT item_code, calc_sso, calc_pf FROM `payroll_earning_deduction_types`
            WHERE comp_id = :comp_id AND item_type = 'earning' AND status = 'active' AND deleted_at IS NULL AND (calc_sso = 1 OR calc_pf = 1)");
        $stmtCalcFlags->execute([':comp_id' => $compId]);
        foreach ($stmtCalcFlags->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = strtoupper((string)$row['item_code']);
            if ($row['calc_sso']) { $calcSsoItemCodes[$code] = true; }
            if ($row['calc_pf']) { $calcPfItemCodes[$code] = true; }
        }

        // 2026-08-30, real bug found and fixed (see database/migrations/2026-08-30_7_taxable_gross_amount.sql's
        // own header for the full root-cause writeup) -- payroll_earning_deduction_types.
        // tax_treatment ('non_taxable' earning items) and tax_deduction_impact ('after_tax'
        // deduction items) were both REQUIRED data entry on the catalog but never actually read
        // anywhere in this method (PIT withholding was computed as if EVERY earning was taxable and
        // NO PED deduction ever reduced taxable income, regardless of what was configured).
        // Prefetched once per run (company-wide), same pattern as $calcSsoItemCodes/$calcPfItemCodes
        // just above -- only the two "opt out of the always-taxable-income default" values are
        // collected (non_taxable earnings / after_tax deductions). A line with NO matching catalog
        // code at all (e.g. a CUSTOM: item or the TRANSFER_IN credit line) always keeps the exact
        // pre-fix behavior (counted as taxable income, never reduces it) -- but a REAL catalog item
        // already explicitly marked non_taxable or before_tax will see a genuine, CORRECT change in
        // computed tax starting from this fix (that mismatch between "what the admin configured" and
        // "what actually happened" is precisely the bug being fixed, not a regression to guard
        // against).
        $nonTaxableEarningCodes = [];
        $afterTaxDeductionCodes = [];
        $stmtTaxFlags = $this->db->prepare("SELECT item_code, item_type, tax_treatment, tax_deduction_impact FROM `payroll_earning_deduction_types`
            WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL
                AND ((item_type = 'earning' AND tax_treatment = 'non_taxable') OR (item_type = 'deduction' AND tax_deduction_impact = 'after_tax'))");
        $stmtTaxFlags->execute([':comp_id' => $compId]);
        foreach ($stmtTaxFlags->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = strtoupper((string)$row['item_code']);
            if ($row['item_type'] === 'earning') {
                $nonTaxableEarningCodes[$code] = true;
            } else {
                $afterTaxDeductionCodes[$code] = true;
            }
        }

        // "Incentive/Other Payment" runs (see create()'s docblock for the full reasoning) skip
        // base salary/proration, standing PED assignments, and attendance bonus entirely by
        // default -- only the manually-picked payroll_run_manual_lines for each employee count.
        // Statutory is only computed when the admin opted into it for this specific run
        // (compute_statutory); otherwise every line here is exactly what was picked, nothing
        // withheld automatically. A normal 'payroll' run's create() always forces
        // compute_statutory=1, so this ternary never actually skips statutory for real payroll.
        //
        // 2026-08-27, explicit request ("การทำงานจ่ายนอกรอบ สามารถเลือกได้ว่าจะนำเงินเดือนหรือ
        // ค่าเงินได้เงินหักที่มีการตั้งค่าไว้มาคำนวณ") -- two more per-run opt-in toggles, same
        // "admin's explicit choice, forced true for a normal payroll run" pattern as
        // compute_statutory right above:
        //   - include_base_salary: pulls in the employee's FULL base_salary_amount (no proration
        //     -- explicit decision, since an off-cycle run's period dates are optional/often
        //     meaningless for prorating against) as part of gross, same as a normal run always does.
        //   - include_standing_items: pulls in standing PED assignments (employee_earning_deductions)
        //     AND Recurring Earnings (EmployeeRecurringEarningModel) -- the two "configured on the
        //     Employee Detail Salary tab" sources -- same $pedRestrictSql-gated query a normal run
        //     always runs, and the exact same two-panel Earning/Deduction type-selection UI
        //     (payroll_run_ped_type_settings, see that table's own docblock) an admin already uses to
        //     narrow a normal run's items, now also usable on an incentive run once this is on.
        //   - include_attendance_pay (2026-08-30, Phase 8 T041, real gap found and fixed): pulls
        //     sync-derived attendance EARNING lines only (OT/trip allowance/any other item_values-
        //     derived earning) -- the deduction side (late/absent/unpaid leave/leave pending) never
        //     applies here, doesn't belong in a supplemental payout run. An off-cycle incentive run
        //     with neither cycle_id nor sync_process_id has no attendance_records/overtime_records
        //     period to read from automatically, so this branch calls
        //     TransactionDataPayAdapter::buildSyntheticRows() directly, scoped to just this run's own
        //     manually-joined roster and its own period -- see the $isIncentive block further down
        //     for exactly where this is consumed.
        $isIncentive = ($run['run_purpose'] ?? 'payroll') === 'incentive';
        $computeStatutory = $isIncentive ? !empty($run['compute_statutory']) : true;
        $includeBaseSalary = $isIncentive ? !empty($run['include_base_salary']) : true;
        $includeStandingItems = $isIncentive ? !empty($run['include_standing_items']) : true;
        $includeAttendancePay = $isIncentive && !empty($run['include_attendance_pay']);
        if ($includeAttendancePay && empty($syncItemsByEmployee)) {
            // Genuine off-cycle run (no cycle_id/sync_process_id already populated it above) -- pull
            // directly, scoped to just this run's own manually-joined roster (payroll_run_manual_employees)
            // rather than the whole company, since an off-cycle run has no automatic membership.
            $stmtIncentiveRoster = $this->db->prepare("SELECT employee_id FROM `payroll_run_manual_employees` WHERE run_id = :run_id");
            $stmtIncentiveRoster->execute([':run_id' => $id]);
            $incentiveRosterIds = array_map('intval', $stmtIncentiveRoster->fetchAll(PDO::FETCH_COLUMN));
            if (!empty($incentiveRosterIds)) {
                $syncItemsByEmployee = TransactionDataPayAdapter::buildSyntheticRows(
                    $this->db, $compId, $incentiveRosterIds, $periodStart, $periodEnd
                );
            }
        }

        // 2026-08-29, explicit follow-up request: "ตอนนี้ 2 รายการเงินได้/เงินหักที่ใช้ในรอบนี้ จะไม่ซ้ำซ้อน
        // กับการตั้งค่าของรอบใช่ไหมครับ" -- confirmed genuine overlap (both this OLD per-run standing-
        // PED-type allowlist AND the NEW Run Settings item-exclusion denylist could each control
        // "does this earning/deduction TYPE apply to this run", for standing-assignment items
        // specifically) and consolidated into ONE mechanism per explicit choice (AskUserQuestion):
        // Run Settings' item exclusion (payroll_run_item_exclusions/payroll_run_line_overrides,
        // applied as a post-filter on $earningLines/$deductionLines further down) now covers this
        // case too -- it's a strict superset of what this old allowlist ever did (this old one only
        // ever restricted STANDING PED assignments specifically -- never Recurring Earnings, sync-
        // derived lines, or manual lines of the same item type, which could still slip through
        // uncontrolled; Run Settings' exclusion applies uniformly to a given item_code regardless of
        // source). $earningRestrictIds/$deductionRestrictIds are now PERMANENTLY empty (this old
        // table -- payroll_run_ped_type_settings -- is no longer read at all), which makes
        // $buildTypeCondition() below naturally fall through to its own "no rows = unrestricted"
        // branch for both sides, same as a run that had never touched the old setting -- i.e. this
        // whole per-run TYPE-restriction concept is now permanently a no-op, its only remaining job
        // being $isIncentive's own include_standing_items gate right below (whether the PED-
        // assignment query runs AT ALL), which this change does not touch. The old 2-panel UI
        // (#pedTypeSettingsSection, PayrollRunModel::savePedTypeSettings()) is removed to match --
        // see this session's own UI changes.
        $earningRestrictIds = [];
        $deductionRestrictIds = [];
        $pedRestrictParams = [];
        // COALESCE(pt.item_type, eed.custom_item_type) (2026-08-19, explicit request): a custom item
        // (ped_type_id NULL, so pt is an unmatched LEFT JOIN row here -- see the query below) has no
        // pt.item_type to compare against on its own. When this item_type is unrestricted (empty
        // $restrictIds) a custom item of that type flows through same as any catalog one, matching
        // the "no rows saved = unrestricted, everything of that type included" rule this table
        // already documents. When restricted to specific catalog ids, a custom item is excluded --
        // it was never one of the ids the admin picked, and there's no "always include custom items"
        // concept here.
        $buildTypeCondition = function (string $itemType, array $restrictIds) use (&$pedRestrictParams) {
            if (empty($restrictIds)) {
                return "COALESCE(pt.item_type, eed.custom_item_type) = '{$itemType}'";
            }
            $placeholders = [];
            foreach ($restrictIds as $i => $ptid) {
                $key = ":restrict_{$itemType}_{$i}";
                $placeholders[] = $key;
                $pedRestrictParams[$key] = $ptid;
            }
            return "(COALESCE(pt.item_type, eed.custom_item_type) = '{$itemType}' AND pt.id IN (" . implode(',', $placeholders) . "))";
        };
        // is_sync_only types (e.g. trip allowance) always pass through regardless of either side's
        // restriction -- see the table's docblock; they're never offered as a selectable option.
        $pedRestrictSql = ' AND (pt.is_sync_only = 1 OR '
            . $buildTypeCondition('earning', $earningRestrictIds) . ' OR '
            . $buildTypeCondition('deduction', $deductionRestrictIds) . ')';

        // 2026-08-29, explicit request ("ถ้า Lock แล้วข้อมูลจะไม่คำนวณใหม่"), retargeted to is_verified
        // 2026-08-31 when Lock was retired -- fetched BEFORE the DELETE below wipes the table, so a
        // verified employee's existing row can be re-inserted verbatim instead of recomputed. Keyed
        // by employee_id and consumed inside the main per-employee loop further down (checked FIRST,
        // before any of that employee's business logic runs, so verifying genuinely means "don't
        // touch this employee's numbers at all" -- not just "compute the same thing again and happen
        // to land on the same answer").
        $verifiedPreservedRows = [];
        $stmtVerifiedIds = $this->db->prepare("SELECT employee_id FROM `payroll_run_employee_verifications` WHERE run_id = :id AND is_verified = 1");
        $stmtVerifiedIds->execute([':id' => $id]);
        $verifiedEmployeeIds = array_map('intval', $stmtVerifiedIds->fetchAll(PDO::FETCH_COLUMN));
        if (!empty($verifiedEmployeeIds)) {
            $stmtPreserved = $this->db->prepare("SELECT * FROM `payroll_run_details` WHERE run_id = :id");
            $stmtPreserved->execute([':id' => $id]);
            foreach ($stmtPreserved->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if (in_array((int)$row['employee_id'], $verifiedEmployeeIds, true)) {
                    $verifiedPreservedRows[(int)$row['employee_id']] = $row;
                }
            }
        }

        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) { $this->db->beginTransaction(); }
            $this->db->prepare("DELETE FROM `payroll_run_details` WHERE run_id = :id")->execute([':id' => $id]);

            $insStmt = $this->db->prepare("INSERT INTO `payroll_run_details`
                (run_id, employee_id, base_salary_amount, prorate_days, prorate_total_days,
                 earning_breakdown, deduction_breakdown, statutory_breakdown,
                 gross_amount, taxable_gross_amount, total_deduction_amount, net_amount, employer_cost_amount, calc_status, calc_errors, data_source)
                VALUES (:run_id, :employee_id, :base_salary_amount, :prorate_days, :prorate_total_days,
                 :earning_breakdown, :deduction_breakdown, :statutory_breakdown,
                 :gross_amount, :taxable_gross_amount, :total_deduction_amount, :net_amount, :employer_cost_amount, :calc_status, :calc_errors, :data_source)");

            $totalGross = 0.0;
            $totalDeduction = 0.0;
            $totalNet = 0.0;
            $anyError = false;

            // Pass 1: assemble each employee's earning/deduction lines (unchanged logic from
            // before the 2026-08-21 transfer-deduction feature) but stash instead of computing
            // totals/inserting immediately -- transfer credits (below) need every employee's
            // deduction lines already assembled before any employee's gross/net can be finalized,
            // regardless of which order employees appear in $employees.
            $perEmployeeData = [];
            foreach ($employees as $emp) {
                $employeeId = (int)$emp['id'];

                // 2026-08-29 (retargeted to is_verified 2026-08-31): a verified employee's earning/
                // deduction lines are stashed AS-IS from the preserved row instead of being
                // reassembled from scratch -- still stashed into $perEmployeeData (not skipped
                // outright) so that if this employee has a transfer-deduction line paying ANOTHER
                // (unverified) employee, that other employee's own transfer-credit earning line
                // further down still resolves correctly against this employee's frozen amount. Pass
                // 2 below has its own, separate bypass that skips recomputing THIS employee's own
                // totals/statutory/insert entirely.
                if (isset($verifiedPreservedRows[$employeeId])) {
                    $preserved = $verifiedPreservedRows[$employeeId];
                    $perEmployeeData[$employeeId] = [
                        'emp' => $emp,
                        'effectiveBase' => (float)$preserved['base_salary_amount'],
                        'prorateDays' => $preserved['prorate_days'] !== null ? (int)$preserved['prorate_days'] : null,
                        'prorateTotalDays' => $preserved['prorate_total_days'] !== null ? (int)$preserved['prorate_total_days'] : null,
                        'earningLines' => json_decode((string)$preserved['earning_breakdown'], true) ?? [],
                        'deductionLines' => json_decode((string)$preserved['deduction_breakdown'], true) ?? [],
                        'errors' => $preserved['calc_errors'] ? explode(', ', (string)$preserved['calc_errors']) : [],
                    ];
                    continue;
                }

                $baseSalary = (float)$emp['base_salary_amount'];
                $employmentDate = $emp['employment_date'];
                $employmentEndDate = $emp['employment_end_date'];
                $salaryType = $emp['salary_type'] ?? 'monthly';

                $employeeFlags = [
                    'sso_enrolled' => (bool)$emp['sso_enrolled'],
                    'pvd_enrolled' => (bool)$emp['pvd_enrolled'],
                    'tax_exempt' => (bool)$emp['tax_exempt'],
                ];

                // Moved before the prorate/base-pay block below (used to sit after it) so the
                // 'daily'/'hourly' salary_type branches there can push their own flags in too.
                $errors = [];
                if (empty($emp['is_payroll_ready'])) {
                    // Placeholder profile (SSO auto-provision or sync auto-create, see above) --
                    // flagged distinctly from missing_base_salary since a placeholder can have
                    // other missing required fields even if a salary happens to be filled in, and
                    // vice versa.
                    $errors[] = 'profile_incomplete';
                }
                if ((!$isIncentive || $includeBaseSalary) && $baseSalary <= 0) {
                    $errors[] = 'missing_base_salary';
                }

                // 2026-08-21, explicit request ("พนักงานที่อยู่ในช่วงทดลองงาน จะไม่จ่ายในวันหยุด
                // จ่ายแค่วันทำงาน") -- employees.salary_type existed as a required Employee-form
                // field but was never read here before now; everyone was paid via the monthly-style
                // calendar-day proration below regardless of what was selected. 'daily' now means
                // base_salary_amount is a PER-DAY rate, paid for actual payable days only (weekly
                // off-days from the employee's Shift + resolved company holidays, both excluded via
                // SetupRulesModel::payableDaysForEmployee() -- reuses resolveHolidaysForEmployee()
                // so a holiday landing on an already-excluded weekly off-day is never double-
                // subtracted). 'hourly' has no reliable actual-hours-worked source for a cycle-based
                // run computed in advance, so it's explicitly out of scope: flagged visibly rather
                // than silently mistreated, falling back to the exact monthly-style formula so the
                // run still isn't blocked. 'monthly'/unset keeps today's exact formula unchanged --
                // zero behavior change for the default/common case.
                $prorateDays = null;
                $prorateTotalDays = null;
                $effectiveBase = 0.0;
                // 2026-08-27, explicit request/confirmed via AskUserQuestion ("เต็มจำนวน ไม่ Prorate")
                // -- an incentive run that opts into include_base_salary uses the FULL
                // base_salary_amount as-is, no proration against period_start/end_date at all
                // (unlike the normal-run branch below): an off-cycle run's period dates are often
                // just payment_date itself (see create()'s own "defaults to payment_date, a
                // single-day period" fallback), which would otherwise prorate a real month's salary
                // down to a single day's worth -- clearly not the intent of "bring in the base
                // salary".
                if ($isIncentive) {
                    if ($includeBaseSalary) {
                        $effectiveBase = $baseSalary;
                    }
                } else {
                    $effectiveStart = $employmentDate > $periodStart ? $employmentDate : $periodStart;
                    $effectiveEnd = ($employmentEndDate !== null && $employmentEndDate < $periodEnd) ? $employmentEndDate : $periodEnd;

                    // 2026-08-31, explicit request/investigation: "ถ้าเป็นพนักงานรายวัน การระบุเงินเดือน และ
                    // การคำนวณจะเป็นแบบไหนครับ รายสัปดาห์ด้วย และรายปักษ์...ต้องครอบคลุมทั้งหมด" -- widened from
                    // 'daily'-only to also cover 'weekly'/'semi_monthly'/'bi_weekly' (employees.
                    // salary_type, confirmed via AskUserQuestion to reuse payroll_cycles.
                    // payroll_frequency's own vocabulary/i18n labels for consistency -- these
                    // employees are expected to be assigned, via employees.cycle_id, to a Payroll
                    // Cycle of the SAME frequency, whose own period-boundary math
                    // (PayrollCycleModel::suggestNextPeriod()) already correctly produces a ~7/~15/14-
                    // day run period -- this branch doesn't need to know or care which cycle produced
                    // $periodStart/$periodEnd, only how to prorate base_salary_amount across
                    // whatever period this run actually covers).
                    //
                    // Reuses the EXACT SAME payableDaysForEmployee()-based proration 'daily' already
                    // had (shift pattern + company holidays, excludes weekly off-days) -- the only new
                    // piece is converting base_salary_amount into an EFFECTIVE DAILY RATE first, via a
                    // FIXED divisor per type (same "fixed legal divisor, not the period's own actual
                    // length" convention the monthly branch below already uses for its own 30-day
                    // divisor -- a semi-monthly period's real length varies 13-18 days depending on
                    // the company's chosen cutoff day and the month, so a fixed 15 keeps this
                    // predictable/consistent rather than silently shifting rate depending on which
                    // half of which month a run happens to fall in). 'daily' itself keeps its
                    // original divisor of 1 (base_salary_amount already IS a per-day rate) --
                    // zero behavior change for any employee already on 'daily'.
                    $salaryTypeDayDivisors = ['daily' => 1, 'weekly' => 7, 'semi_monthly' => 15, 'bi_weekly' => 14];
                    if (isset($salaryTypeDayDivisors[$salaryType])) {
                        $effectiveDailyRate = $baseSalary / $salaryTypeDayDivisors[$salaryType];
                        $payable = $this->setupRulesModel->payableDaysForEmployee($employeeId, $compId, $effectiveStart, $effectiveEnd);
                        // Repurposed, not renamed -- same "X/Y days" breakdown display fields the
                        // monthly path below still uses, now showing payable/total days instead of
                        // a calendar prorate ratio.
                        $prorateDays = $payable['payable_days'];
                        $prorateTotalDays = $payable['total_days'];
                        $effectiveBase = round($effectiveDailyRate * $payable['payable_days'], 2);
                        if (!$payable['has_shift_pattern']) {
                            // No shift assigned -- still pays for every non-holiday day in range as
                            // the safe default (see docblock), just flagged visibly instead of
                            // silently guessing a Mon-Fri pattern for someone with no data.
                            $errors[] = 'daily_salary_no_shift_pattern';
                        }
                    } elseif ($salaryType === 'hourly') {
                        // 2026-08-31, real gap fixed (was previously flagged
                        // salary_type_hourly_not_supported and silently fell through to the monthly-
                        // prorate formula below -- a wrong number, not just an unsupported one, since
                        // base_salary_amount for an hourly employee is a per-HOUR rate, not a monthly
                        // salary). Sums REAL worked minutes from attendance_records (Sync/Import/
                        // Manual Entry alike -- see AttendanceRecordModel::totalWorkedMinutesForEmployee()'s
                        // own docblock for why this is a genuine "what happened" data source, not a
                        // guess) across this employee's own effective range, converts to hours, and
                        // multiplies by the hourly rate -- same spirit as 'daily' paying for actual
                        // payable days, just hours instead of days and real clock data instead of a
                        // shift pattern (there is no safe DEFAULT hours-worked assumption the way
                        // "every non-holiday weekday" was defensible for daily, so a genuine data gap
                        // here pays 0 with a visible flag rather than guessing).
                        $workedMinutes = $this->attendanceRecordModel->totalWorkedMinutesForEmployee($employeeId, $compId, $effectiveStart, $effectiveEnd);
                        $prorateDays = $workedMinutes['days_with_data'];
                        $prorateTotalDays = (int)((strtotime($effectiveEnd) - strtotime($effectiveStart)) / 86400) + 1;
                        $effectiveBase = round($baseSalary * ($workedMinutes['total_minutes'] / 60.0), 2);
                        if ($workedMinutes['days_with_data'] === 0) {
                            // No attendance data reached us for this employee's own effective range at
                            // all (distinct from "attendance rows exist but summed to 0 minutes", e.g.
                            // an employee on leave the whole period, which is a real, correctly-$0 result).
                            $errors[] = 'hourly_salary_no_attendance_data';
                        }
                    } else {
                        // 2026-08-30, explicit request: Payroll Policy "pay_basis" -- 'schedule_based'
                        // replaces the flat-then-mid-period-prorate formula below entirely with
                        // SetupRulesModel::scheduledPayableDaysForEmployee()'s own ratio, uniformly
                        // across BOTH the full-period and mid-period-join/leave cases (simpler and more
                        // correct than layering two different proration mechanisms on top of each
                        // other). 'full_month' (the default) is BYTE-IDENTICAL to this method's
                        // behavior before this feature existed -- confirmed via the full regression
                        // suite. Deliberately does NOT read actual attendance/sync data (schedule +
                        // Holiday config + approved unpaid leave only) -- see that method's own
                        // docblock for why (avoids double-deducting against Attendance Deduction
                        // Rule's own sync-derived absence formulas for a company that has both
                        // configured).
                        // 2026-08-30, same-day follow-up, explicit correction: "พื้นฐานการจ่ายเงินเดือน
                        // ที่ตั้งจะนำไปคำนวณแค่พนักงานที่ทดลองงานใช่ไหม ถ้าไม่ใช่ช่วยปรับให้เป็นเงื่อนไขของการ
                        // ทดลองงานเท่านั้น" -- it wasn't (applied to every monthly employee company-wide)
                        // before this line was added; now gated by employees.employment_status ===
                        // 'probation' too, same real HR-maintained-status gate every other Payroll
                        // Policy "probation" field already uses (base_salary_ratio/defer_pvd/
                        // defer_recurring_earning, all further down/up in this same method) -- despite
                        // living on the same generic-looking company_payroll_policies.pay_basis
                        // column, this setting is now, in practice, a probation-only condition.
                        // 2026-08-30, same-day follow-up: reconciles Origami's own PROBATION_WORKING_DAYS
                        // (PAYROLL_SYNC_API.md's 2026-08-29 addition -- working_days minus absent_days,
                        // sent only when Origami's OWN company policy is 'actual_days') against the
                        // 'schedule_based' branch above -- confirmed via AskUserQuestion: a separate,
                        // explicit pay_basis value rather than teaching 'schedule_based' to silently
                        // prefer sync data when present (keeps that branch's own documented,
                        // never-reads-sync-data behavior byte-identical for every company already using
                        // it). Only takes effect on a sync-based run where THIS employee has a mapped
                        // sync row carrying the item this cycle -- $syncItemsByEmployee is already
                        // empty for every non-sync run (see its own fetch comment above), so a bare
                        // isset() check here correctly also means "sync-based run." Falls back to
                        // paying the FULL base salary (not a guessed prorate) whenever the data isn't
                        // there -- Origami's own policy might be 'full_month' this cycle, or this
                        // employee might be outside ITS OWN probation window, or this could genuinely
                        // be a non-sync run -- none of those are a "day count" this app has any basis
                        // to invent, same "don't guess" posture as missing_ot_rate_*/no_rate_configured
                        // elsewhere in this codebase; surfaced as a visible calc_errors flag instead.
                        // 2026-08-31: intern's own pay_basis takes PRECEDENCE over probation's when an
                        // employee is somehow both employment_type='internship' AND
                        // employment_status='probation' -- $effectivePayBasis/$payBasisGateMatches
                        // pick which settings (if any) actually apply to THIS employee, then the same
                        // sync_actual_days/schedule_based branches below read from whichever won,
                        // instead of duplicating both branches per employment classification.
                        if (($emp['employment_type'] ?? null) === 'internship') {
                            $effectivePayBasis = $internPayBasisSettings;
                            $payBasisGateMatches = true;
                            $syncWorkingDaysInfoKey = 'INTERN_WORKING_DAYS';
                        } elseif (($emp['employment_status'] ?? null) === 'probation') {
                            $effectivePayBasis = $payBasisSettings;
                            $payBasisGateMatches = true;
                            $syncWorkingDaysInfoKey = 'PROBATION_WORKING_DAYS';
                        } else {
                            $effectivePayBasis = null;
                            $payBasisGateMatches = false;
                            $syncWorkingDaysInfoKey = null;
                        }
                        if ($payBasisGateMatches && $effectivePayBasis['pay_basis'] === 'sync_actual_days' && $salaryType !== 'hourly') {
                            // 2026-08-31: INTERN_WORKING_DAYS is NOT a field Origami's sync payload
                            // sends today (PROBATION_WORKING_DAYS is, see PAYROLL_SYNC_API.md's
                            // 2026-08-29 addition) -- this branch is built and ready, same "don't guess
                            // a field that doesn't exist" posture as every other DRAFT/unverified piece
                            // in this app, but will always fall through to the else (full base salary +
                            // sync_actual_days_no_data flag) for an intern until Origami's own side
                            // adds this field. Documented, not hidden.
                            $syncRow = $syncItemsByEmployee[$employeeId] ?? null;
                            $totalWorkingDays = $syncRow !== null && isset($syncRow['working_days']) ? (float)$syncRow['working_days'] : null;
                            $actualWorkingDays = $syncRow !== null ? SyncPayResolver::extractInfoItemValue($syncRow, $syncWorkingDaysInfoKey) : null;
                            if ($actualWorkingDays !== null && $totalWorkingDays !== null && $totalWorkingDays > 0) {
                                $actualWorkingDays = min($actualWorkingDays, $totalWorkingDays);
                                $prorateDays = $actualWorkingDays;
                                $prorateTotalDays = $totalWorkingDays;
                                $effectiveBase = round($baseSalary * $actualWorkingDays / $totalWorkingDays, 2);
                            } else {
                                $effectiveBase = $baseSalary;
                                $errors[] = 'sync_actual_days_no_data';
                            }
                        } elseif ($payBasisGateMatches && $effectivePayBasis['pay_basis'] === 'schedule_based' && $salaryType !== 'hourly') {
                            $scheduled = $this->setupRulesModel->scheduledPayableDaysForEmployee(
                                $employeeId, $compId, $effectiveStart, $effectiveEnd,
                                $effectivePayBasis['deduct_holidays'], $effectivePayBasis['deduct_leave']
                            );
                            $prorateDays = $scheduled['payable_days'];
                            $prorateTotalDays = $scheduled['total_scheduled_days'];
                            $effectiveBase = $prorateTotalDays > 0 ? round($baseSalary * $scheduled['payable_days'] / $prorateTotalDays, 2) : 0.0;
                        } else {
                            $effectiveBase = $baseSalary;
                            if ($effectiveStart > $periodStart || $effectiveEnd < $periodEnd) {
                                // 2026-08-29: denominator is the company's configured legal divisor
                                // (default 30), NOT $totalPeriodDays -- see this method's own top-of-
                                // function comment. $prorateDays (days actually present) is still
                                // capped against the REAL period length as a sanity bound only, not
                                // against the divisor -- intentionally uncapped against the divisor
                                // itself, matching the well-known characteristic of the fixed-30
                                // convention (e.g. joining on day 2 of a 31-day January yields
                                // 30/30 = 100% of base salary, same as Thai practice).
                                $prorateTotalDays = $prorateDivisorDays;
                                $prorateDays = (int)((strtotime($effectiveEnd) - strtotime($effectiveStart)) / 86400) + 1;
                                $prorateDays = max(0, min($prorateDays, $totalPeriodDays));
                                $effectiveBase = $prorateDays > 0 ? round($baseSalary * $prorateDays / $prorateTotalDays, 2) : 0.0;
                            }
                        }
                    }
                }

                // 2026-08-30, explicit request: probation base-salary ratio -- applied AFTER every
                // other proration above (daily/monthly/incentive), same "on top of whatever the
                // normal formula already produced" precedent as the daily-salary/monthly-prorate
                // branches being mutually exclusive alternatives, not stacked -- this ratio is a
                // further multiplier on top of whichever of THOSE already ran, not a replacement for
                // any of them. Gated by the real, HR-maintained employment_status, not a day-count
                // (see PayrollPolicyModel::probationSettings()'s own docblock).
                // 2026-08-31, explicit request: internship pay conditions, same "further multiplier"
                // shape -- $isIntern takes PRECEDENCE over the probation gate below when an employee
                // is somehow both (see $internSettings' own comment above for why). The employee's
                // own intern_base_salary_ratio_override (Salary tab, "Set ได้จากตรงนั้น...แก้ไขได้เป็น
                // รายบุคคล") wins over the company-wide intern_base_salary_ratio default when set.
                $isIntern = ($emp['employment_type'] ?? null) === 'internship';
                $isProbation = ($emp['employment_status'] ?? null) === 'probation';
                // 2026-09-04, Backlog Phase 10, T056: probation policy is no longer a single
                // company-wide singleton -- $probationSettings (fetched once above, before this
                // loop) is now only the FALLBACK shape for a non-probation employee; a real
                // probation employee resolves their OWN Probation Set here (department/position/
                // team/employee-assignable, exactly one mandatory company Default -- see
                // PayrollPolicyModel::probationSettings()'s own docblock). Only queried when
                // $isProbation is actually true, same "don't pay for what you don't need" posture
                // the rest of this method already follows for other per-employee lookups.
                $employeeProbationSettingsPass1 = $isProbation
                    ? $this->policyModel->probationSettings($compId, $employeeId)
                    : $probationSettings;
                if ($isIntern) {
                    $internRatio = $emp['intern_base_salary_ratio_override'] ?? $internSettings['base_salary_ratio'];
                    if ($internRatio !== null) {
                        $effectiveBase = round($effectiveBase * ((float)$internRatio / 100), 2);
                    }
                } elseif ($isProbation) {
                    // 2026-09-02, explicit request: Probation gained the same per-employee ratio
                    // override Internship already had -- direct mirror of the intern branch above,
                    // employee's own probation_base_salary_ratio_override wins over the company-wide
                    // probation_base_salary_ratio default when set.
                    $probationRatio = $emp['probation_base_salary_ratio_override'] ?? $employeeProbationSettingsPass1['base_salary_ratio'];
                    if ($probationRatio !== null) {
                        $effectiveBase = round($effectiveBase * ((float)$probationRatio / 100), 2);
                    }
                }
                // 2026-08-30, explicit request: defer Recurring Allowances until probation passes --
                // read below by BOTH the incentive-run branch's own conditional include and the
                // normal-run branch's unconditional include (two separate `activeForPeriod()` call
                // sites further down), same gate either way. 2026-08-31: intern equivalent, same
                // precedence-over-probation rule as the ratio just above.
                // 2026-09-02, follow-up to close a review-flagged gap: per-employee override (NULL =
                // use the company default, same "one nullable column doubles as its own toggle"
                // convention as the ratio override) -- (bool) cast handles the raw '0'/'1'/int-from-
                // PDO value uniformly regardless of driver-specific type.
                $probationDeferRecurringEffective = $emp['probation_defer_recurring_earning_override'] !== null
                    ? (bool)$emp['probation_defer_recurring_earning_override'] : $employeeProbationSettingsPass1['defer_recurring_earning'];
                $internDeferRecurringEffective = $emp['intern_defer_recurring_earning_override'] !== null
                    ? (bool)$emp['intern_defer_recurring_earning_override'] : $internSettings['defer_recurring_earning'];
                $deferRecurringEarningForThisEmployee = $isIntern
                    ? $internDeferRecurringEffective
                    : ($probationDeferRecurringEffective && $isProbation);

                $earningLines = [];
                $deductionLines = [];

                if ($isIncentive) {
                    // 2026-08-27, explicit request/AskUserQuestion-confirmed scope ("เงินได้/เงินหัก
                    // ปกติ (PED) + เบี้ยเลี้ยงประจำ") -- once include_standing_items is on, pull in
                    // the SAME two sources the normal-run branch below always does (standing PED
                    // assignments + Recurring Earnings), gated by the SAME $pedRestrictSql/
                    // payroll_run_ped_type_settings two-panel selection an admin already uses on a
                    // normal run. Sync-derived EARNING lines (OT/trip allowance/item_values) are now
                    // ALSO available here, but only opt-in via the separate include_attendance_pay
                    // toggle below (2026-08-30, Phase 8 T041) -- attendance bonus stays out entirely
                    // (see [[SyncPayResolver's own DILIGENCE comment]], nothing left in this codebase
                    // that can produce a source==='attendance_bonus' line at all any more). This is a
                    // duplicated copy of those two specific queries from the `else` branch below, not
                    // a shared helper -- matches
                    // this method's own existing precedent of duplicating the payroll_run_manual_lines
                    // query per-branch instead (see the "Ad-hoc per-employee adjustments" comment
                    // further down, same SQL as this branch's own manual-lines query below).
                    if ($includeStandingItems) {
                        $stmtPed = $this->db->prepare("SELECT eed.id AS assignment_id, i.id AS installment_id, i.amount, i.principal_amount, i.interest_amount,
                                eed.ped_type_id, eed.custom_item_name, eed.custom_item_type, eed.is_other, eed.payee_employee_id, eed.payee_type, eed.destination_id, eed.bank_account_id,
                                pt.item_code, pt.item_name_th, pt.item_name_en, pt.item_type
                            FROM `employee_earning_deductions` eed
                            LEFT JOIN `payroll_earning_deduction_types` pt ON pt.id = eed.ped_type_id
                            JOIN `employee_earning_deduction_installments` i ON i.assignment_id = eed.id AND i.status = 'pending'
                            WHERE eed.employee_id = :employee_id AND eed.status = 'active' AND eed.deleted_at IS NULL
                            AND eed.effective_date <= :period_end{$pedRestrictSql}
                            ORDER BY eed.id ASC, i.installment_no ASC");
                        $stmtPed->execute(array_merge([':employee_id' => $employeeId, ':period_end' => $periodEnd], $pedRestrictParams));
                        $pedSeen = [];
                        foreach ($stmtPed->fetchAll(PDO::FETCH_ASSOC) as $ped) {
                            $assignmentId = (int)$ped['assignment_id'];
                            if (isset($pedSeen[$assignmentId])) {
                                continue; // only the first (earliest) pending installment per assignment
                            }
                            $pedSeen[$assignmentId] = true;
                            $resolved = $this->resolveManualLineRow($ped);
                            $line = [
                                'source' => 'ped',
                                'assignment_id' => $assignmentId,
                                'installment_id' => (int)$ped['installment_id'],
                                'code' => $resolved['code'],
                                'name_th' => $resolved['name_th'],
                                'name_en' => $resolved['name_en'],
                                'amount' => (float)$ped['amount'],
                                // 2026-09-11, Batch 3B item 4: read straight off the ALREADY-PERSISTED
                                // installment row (EmployeeEarningDeductionModel::save() computed
                                // and stored this once, at save time) -- never recomputed here, so a
                                // slip/report showing this breakdown can never drift from what the
                                // Employee Detail modal itself already showed when the assignment
                                // was created/edited. NULL for an installment saved before this
                                // column existed (legacy data) -- never guessed.
                                'principal_amount' => $ped['principal_amount'] !== null ? (float)$ped['principal_amount'] : null,
                                'interest_amount' => $ped['interest_amount'] !== null ? (float)$ped['interest_amount'] : null,
                                'is_custom' => $resolved['is_custom'],
                                'is_other' => $resolved['is_other'],
                                'payee_employee_id' => $ped['payee_employee_id'] !== null ? (int)$ped['payee_employee_id'] : null,
                                // 2026-08-31, same-day follow-up: same reasoning as the manual_line
                                // entry below -- carried through so the outer breakdown table can show
                                // the company/not_disbursed tag, not just 'employee' transfer.
                                'payee_type' => $ped['payee_type'],
                                'destination_id' => $ped['destination_id'] !== null ? (int)$ped['destination_id'] : null,
                                // 2026-09-10, Batch 3B item 3: level-2 for payee_type='company'.
                                'bank_account_id' => $ped['bank_account_id'] !== null ? (int)$ped['bank_account_id'] : null,
                            ];
                            if ($resolved['item_type'] === 'earning') {
                                $earningLines[] = $line;
                            } else {
                                $deductionLines[] = $line;
                            }
                        }

                        foreach ($deferRecurringEarningForThisEmployee ? [] : $this->recurringEarningModel->activeForPeriod($employeeId, $periodStart, $periodEnd, $compId) as $rec) {
                            $earningLines[] = [
                                'source' => 'recurring_earning',
                                'recurring_id' => (int)$rec['recurring_id'],
                                'code' => $rec['item_code'],
                                'name_th' => $rec['item_name_th'],
                                'name_en' => $rec['item_name_en'],
                                'amount' => (float)$rec['amount'],
                                'is_custom' => false,
                            ];
                        }
                        // 2026-08-31, explicit request: "หน้า Employee Detail เพิ่มรายหักประจำด้วยครับ" --
                        // mirrors Recurring Earnings exactly, deducting instead of adding. Deliberately
                        // NOT gated by $deferRecurringEarningForThisEmployee (that Payroll Policy field
                        // is specifically an EARNING-allowance concept, e.g. defer a car allowance
                        // during probation -- a recurring FEE deduction has no equivalent "defer during
                        // probation" precedent asked for here, so it's unconditionally included).
                        foreach ($this->recurringDeductionModel->activeForPeriod($employeeId, $periodStart, $periodEnd, $compId) as $rec) {
                            $recPayee = $this->resolveRecurringDeductionPayee($rec, $recurringDeductionDestOverridesByRecurringId);
                            $deductionLines[] = [
                                'source' => 'recurring_deduction',
                                'recurring_id' => (int)$rec['recurring_id'],
                                'code' => $rec['item_code'],
                                'name_th' => $rec['item_name_th'],
                                'name_en' => $rec['item_name_en'],
                                'amount' => $this->recurringDeductionAmountWithFee($rec, $baseSalary),
                                'is_custom' => false,
                                'payee_type' => $recPayee['payee_type'],
                                'payee_employee_id' => $recPayee['payee_employee_id'],
                                'destination_id' => $recPayee['destination_id'],
                                'bank_account_id' => $recPayee['bank_account_id'],
                            ];
                        }
                    }

                    // Manually-picked items (see joinEmployees()/addManualLine() docblocks) --
                    // additive on top of the standing items above when include_standing_items is on,
                    // or the ONLY source when it's off (today's original/default incentive-run
                    // behavior, unchanged).
                    $stmtLines = $this->db->prepare("SELECT pml.ped_type_id, pml.amount, pml.note, pml.custom_item_name, pml.custom_item_type, pml.is_other, pml.payee_employee_id,
                            pml.payee_type, pml.destination_id, pml.bank_account_id,
                            pt.item_code, pt.item_name_th, pt.item_name_en, pt.item_type
                        FROM `payroll_run_manual_lines` pml
                        LEFT JOIN `payroll_earning_deduction_types` pt ON pt.id = pml.ped_type_id
                        WHERE pml.run_id = :run_id AND pml.employee_id = :employee_id");
                    $stmtLines->execute([':run_id' => $id, ':employee_id' => $employeeId]);
                    $manualLines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($manualLines as $line) {
                        $resolved = $this->resolveManualLineRow($line);
                        $entry = [
                            'source' => 'manual_line',
                            'code' => $resolved['code'],
                            'name_th' => $resolved['name_th'],
                            'name_en' => $resolved['name_en'],
                            'amount' => (float)$line['amount'],
                            'note' => $line['note'],
                            'is_custom' => $resolved['is_custom'],
                            'is_other' => $resolved['is_other'],
                            'payee_employee_id' => $line['payee_employee_id'] !== null ? (int)$line['payee_employee_id'] : null,
                            // 2026-08-31, same-day follow-up: carried through so the outer breakdown
                            // table (public/js/payroll/detail.js's breakdownLineRowsRd()) can show
                            // the SAME company/not_disbursed tag the manage-items modal shows, not
                            // just the 'employee' transfer case.
                            'payee_type' => $line['payee_type'],
                            'destination_id' => $line['destination_id'] !== null ? (int)$line['destination_id'] : null,
                            'bank_account_id' => $line['bank_account_id'] !== null ? (int)$line['bank_account_id'] : null,
                        ];
                        if ($resolved['item_type'] === 'earning') {
                            $earningLines[] = $entry;
                        } else {
                            $deductionLines[] = $entry;
                        }
                    }

                    // 2026-08-30 (Phase 8, T041, real gap found and fixed): sync-derived attendance
                    // EARNING lines (OT/trip allowance/item_values), additive on top of everything
                    // above -- $syncItemsByEmployee was either populated at the top of this method (a
                    // supplemental sync-linked or cycle-tagged incentive run) or by the
                    // TransactionDataPayAdapter fallback right above the $isIncentive gate for a
                    // genuine ad-hoc off-cycle run. Deliberately keeps ONLY $syncResult['earning'] --
                    // the deduction side (late/absent/unpaid leave/leave pending) is discarded
                    // entirely, never applied here; a supplemental OT/trip payout run is not the place
                    // to also dock someone's pay for lateness.
                    if ($includeAttendancePay && isset($syncItemsByEmployee[$employeeId])) {
                        $employeeDepartmentIdForSync = isset($emp['department_id']) && $emp['department_id'] !== null ? (int)$emp['department_id'] : null;
                        $employeeTeamIdForSync = isset($emp['team_id']) && $emp['team_id'] !== null ? (int)$emp['team_id'] : null;
                        $syncResultIncentive = $this->syncPayResolver->resolve($compId, $syncItemsByEmployee[$employeeId], $baseSalary,
                            $attendanceOverridesByEmployee[$employeeId] ?? [], [], $employeeDepartmentIdForSync, $employeeTeamIdForSync,
                            (bool)($emp['ot_eligible'] ?? true), $otOverridesByEmployee[$employeeId] ?? [], $otRateSetRatesByEmployee[$employeeId]['rates'] ?? []);
                        foreach ($syncResultIncentive['earning'] as $line) {
                            $earningLines[] = $line;
                        }
                        foreach ($syncResultIncentive['errors'] as $syncError) {
                            $errors[] = $syncError;
                        }
                    }

                    // Genuinely nothing at all for this employee -- no manual lines picked, no
                    // standing items pulled in (either because include_standing_items is off, or on
                    // but nothing matched), no attendance pay pulled in either, and no base salary
                    // either. Almost certainly an oversight, same spirit as missing_base_salary for a
                    // normal payroll row. Widened from the original "just check $manualLines" version
                    // (2026-08-27) so turning on include_base_salary/include_standing_items/
                    // include_attendance_pay alone no longer falsely flags an employee who has real
                    // pay lines from those sources but never got a manual line.
                    if (empty($manualLines) && empty($earningLines) && empty($deductionLines) && $effectiveBase <= 0) {
                        $errors[] = 'no_manual_lines';
                    }
                } else {
                    // PED assignments: pick the earliest pending installment per assignment. LEFT JOIN
                    // (2026-08-19, explicit request: employee_earning_deductions can now hold a custom
                    // item, ped_type_id NULL -- an INNER JOIN here would have silently dropped every
                    // custom-item assignment out of the actual payroll calculation, even though it
                    // saved fine and showed up on the Employee Detail Salary tab). Resolved the same
                    // way as payroll_run_manual_lines' own custom items, via resolveManualLineRow().
                    $stmtPed = $this->db->prepare("SELECT eed.id AS assignment_id, i.id AS installment_id, i.amount, i.principal_amount, i.interest_amount,
                            eed.ped_type_id, eed.custom_item_name, eed.custom_item_type, eed.is_other, eed.payee_employee_id, eed.payee_type, eed.destination_id, eed.bank_account_id,
                            pt.item_code, pt.item_name_th, pt.item_name_en, pt.item_type
                        FROM `employee_earning_deductions` eed
                        LEFT JOIN `payroll_earning_deduction_types` pt ON pt.id = eed.ped_type_id
                        JOIN `employee_earning_deduction_installments` i ON i.assignment_id = eed.id AND i.status = 'pending'
                        WHERE eed.employee_id = :employee_id AND eed.status = 'active' AND eed.deleted_at IS NULL
                        AND eed.effective_date <= :period_end{$pedRestrictSql}
                        ORDER BY eed.id ASC, i.installment_no ASC");
                    $stmtPed->execute(array_merge([':employee_id' => $employeeId, ':period_end' => $periodEnd], $pedRestrictParams));
                    $pedSeen = [];
                    foreach ($stmtPed->fetchAll(PDO::FETCH_ASSOC) as $ped) {
                        $assignmentId = (int)$ped['assignment_id'];
                        if (isset($pedSeen[$assignmentId])) {
                            continue; // only the first (earliest) pending installment per assignment
                        }
                        $pedSeen[$assignmentId] = true;
                        $resolved = $this->resolveManualLineRow($ped);
                        $line = [
                            'source' => 'ped',
                            'assignment_id' => $assignmentId,
                            'installment_id' => (int)$ped['installment_id'],
                            'code' => $resolved['code'],
                            'name_th' => $resolved['name_th'],
                            'name_en' => $resolved['name_en'],
                            'amount' => (float)$ped['amount'],
                            // 2026-09-11, Batch 3B item 4: same "read the persisted value, never
                            // recompute" rule as the incentive-run branch above.
                            'principal_amount' => $ped['principal_amount'] !== null ? (float)$ped['principal_amount'] : null,
                            'interest_amount' => $ped['interest_amount'] !== null ? (float)$ped['interest_amount'] : null,
                            'is_custom' => $resolved['is_custom'],
                            'is_other' => $resolved['is_other'],
                            'payee_employee_id' => $ped['payee_employee_id'] !== null ? (int)$ped['payee_employee_id'] : null,
                            'payee_type' => $ped['payee_type'],
                            'destination_id' => $ped['destination_id'] !== null ? (int)$ped['destination_id'] : null,
                            'bank_account_id' => $ped['bank_account_id'] !== null ? (int)$ped['bank_account_id'] : null,
                        ];
                        if ($resolved['item_type'] === 'earning') {
                            $earningLines[] = $line;
                        } else {
                            $deductionLines[] = $line;
                        }
                    }

                    // Recurring earnings (position/car/fuel allowance, etc.) -- 2026-08-26, explicit
                    // request: "รายรับที่ได้ทุกเดือน...ให้เพิ่มส่วนนี้เข้าไปด้วย และระงับการจ่ายได้ รวมถึงการ
                    // ตั้งค่าส่วนนี้เพิ่มเติมให้นำไปคำนวณในรอบการจ่ายด้วย". Same placement as the PED
                    // assignments block above (skipped entirely for an incentive/off-cycle run, which
                    // is manually-picked items only) -- see EmployeeRecurringEarningModel's own
                    // docblock for why this is a separate table/query, not a mode of PED assignments.
                    foreach ($deferRecurringEarningForThisEmployee ? [] : $this->recurringEarningModel->activeForPeriod($employeeId, $periodStart, $periodEnd, $compId) as $rec) {
                        $earningLines[] = [
                            'source' => 'recurring_earning',
                            'recurring_id' => (int)$rec['recurring_id'],
                            'code' => $rec['item_code'],
                            'name_th' => $rec['item_name_th'],
                            'name_en' => $rec['item_name_en'],
                            'amount' => (float)$rec['amount'],
                            'is_custom' => false,
                        ];
                    }
                    // 2026-08-31, explicit request: "หน้า Employee Detail เพิ่มรายหักประจำด้วยครับ" -- see
                    // this same block's own comment further up in this method for the full reasoning
                    // (mirrors Recurring Earnings, unconditionally included -- not probation-deferred).
                    foreach ($this->recurringDeductionModel->activeForPeriod($employeeId, $periodStart, $periodEnd, $compId) as $rec) {
                        $recPayee = $this->resolveRecurringDeductionPayee($rec, $recurringDeductionDestOverridesByRecurringId);
                        $deductionLines[] = [
                            'source' => 'recurring_deduction',
                            'recurring_id' => (int)$rec['recurring_id'],
                            'code' => $rec['item_code'],
                            'name_th' => $rec['item_name_th'],
                            'name_en' => $rec['item_name_en'],
                            'amount' => $this->recurringDeductionAmountWithFee($rec, $baseSalary),
                            'is_custom' => false,
                            'payee_type' => $recPayee['payee_type'],
                            'payee_employee_id' => $recPayee['payee_employee_id'],
                            'destination_id' => $recPayee['destination_id'],
                            'bank_account_id' => $recPayee['bank_account_id'],
                        ];
                    }

                    // 2026-08-29, explicit request: "ตัดเบี้ยขยันและการบันทึกเบี้ยขยันออกจากการตั้งค่า และไม่
                    // นำไปคำนวณในเงินเดือน แต่ใน Income ยังคงมีไว้ เพราะจะเชื่อมมาจาก Origami แทน" -- the
                    // Attendance Bonus/Ledger feature (settings UI, controller endpoints,
                    // AttendanceBonusSchemeModel/AttendanceBonusLedgerModel, and the
                    // attendance_bonus_schemes/attendance_bonus_ledger tables themselves) is removed
                    // entirely as of the same-day follow-up (explicit: "ถ้ามีลบเพิ่มไฟล์ .sql ให้ด้วยครับ" --
                    // see database/migrations/2026-08-29_drop_attendance_bonus_tables.sql) -- there is
                    // nothing left anywhere in this codebase that can produce a `source==='attendance_bonus'`
                    // earning line. The DILIGENCE catalog item stays available in the Income tab as a
                    // pure sync target instead -- Origami will push it as a regular item_values line
                    // during a normal sync-based run, resolved by SyncPayResolver's existing generic
                    // item_code matching (the same path already handles any catalog item with no
                    // source_event_code mapping), no special-casing needed here.

                    // Ad-hoc per-employee adjustments (payroll_run_manual_lines) -- additive on top
                    // of the standing PED assignments/attendance bonus above, added via the "Items"
                    // button on the calculation table (2026-08-19, explicit request: this used to be
                    // incentive-run-only, now available on any draft run so an admin can add a
                    // one-off earning/deduction for a single employee without it affecting anyone
                    // else or needing a whole separate off-cycle run). Contrast with the $isIncentive
                    // branch above, where manual lines are the ONLY source instead of an addition.
                    $stmtAdj = $this->db->prepare("SELECT pml.ped_type_id, pml.amount, pml.note, pml.custom_item_name, pml.custom_item_type, pml.is_other, pml.payee_employee_id,
                            pml.payee_type, pml.destination_id, pml.bank_account_id,
                            pt.item_code, pt.item_name_th, pt.item_name_en, pt.item_type
                        FROM `payroll_run_manual_lines` pml
                        LEFT JOIN `payroll_earning_deduction_types` pt ON pt.id = pml.ped_type_id
                        WHERE pml.run_id = :run_id AND pml.employee_id = :employee_id");
                    $stmtAdj->execute([':run_id' => $id, ':employee_id' => $employeeId]);
                    foreach ($stmtAdj->fetchAll(PDO::FETCH_ASSOC) as $adj) {
                        $resolvedAdj = $this->resolveManualLineRow($adj);
                        $line = [
                            'source' => 'manual_line',
                            'code' => $resolvedAdj['code'],
                            'name_th' => $resolvedAdj['name_th'],
                            'name_en' => $resolvedAdj['name_en'],
                            'amount' => (float)$adj['amount'],
                            'note' => $adj['note'],
                            'is_custom' => $resolvedAdj['is_custom'],
                            'is_other' => $resolvedAdj['is_other'],
                            'payee_employee_id' => $adj['payee_employee_id'] !== null ? (int)$adj['payee_employee_id'] : null,
                            'payee_type' => $adj['payee_type'],
                            'destination_id' => $adj['destination_id'] !== null ? (int)$adj['destination_id'] : null,
                            'bank_account_id' => $adj['bank_account_id'] !== null ? (int)$adj['bank_account_id'] : null,
                        ];
                        if ($resolvedAdj['item_type'] === 'earning') {
                            $earningLines[] = $line;
                        } else {
                            $deductionLines[] = $line;
                        }
                    }

                    // Sync-derived lines (OT/trip allowance/late/absent/item_values) -- see
                    // SyncPayResolver's own docblock for the full design. Additive on top of
                    // everything above, same as attendance bonus/manual lines; only populated when
                    // this employee actually has a row in $syncItemsByEmployee (i.e. this is a
                    // sync-based run and they were present in the pulled process).
                    if (isset($syncItemsByEmployee[$employeeId])) {
                        // 2026-08-30, explicit request: per-department/team/individual exemption from
                        // attendance-driven deductions -- see AttendanceDeductionRuleModel::
                        // exemptEventCodesForEmployee()'s own docblock. Same $departmentId/$teamId
                        // also threaded into resolve() below (multi-scope rollout, same day) so the
                        // correct SAVED rule variant (team > department > company-wide default) is
                        // used for the actual amount, not just the exempt/not-exempt gate.
                        $employeeDepartmentId = isset($emp['department_id']) && $emp['department_id'] !== null ? (int)$emp['department_id'] : null;
                        $employeeTeamId = isset($emp['team_id']) && $emp['team_id'] !== null ? (int)$emp['team_id'] : null;
                        $exemptEventCodes = $this->attendanceDeductionRuleModel->exemptEventCodesForEmployee(
                            $compId, $employeeId, $employeeDepartmentId, $employeeTeamId
                        );
                        $syncResult = $this->syncPayResolver->resolve($compId, $syncItemsByEmployee[$employeeId], $baseSalary,
                            $attendanceOverridesByEmployee[$employeeId] ?? [], $exemptEventCodes, $employeeDepartmentId, $employeeTeamId,
                            (bool)($emp['ot_eligible'] ?? true), $otOverridesByEmployee[$employeeId] ?? [], $otRateSetRatesByEmployee[$employeeId]['rates'] ?? []);
                        foreach ($syncResult['earning'] as $line) {
                            $earningLines[] = $line;
                        }
                        foreach ($syncResult['deduction'] as $line) {
                            $deductionLines[] = $line;
                        }
                        foreach ($syncResult['errors'] as $syncError) {
                            $errors[] = $syncError;
                        }
                    } elseif ($isPureCycleRunForDataWarning && $isOrigamiPayrollLinked) {
                        // 2026-08-30 (Phase 8, T041, real gap found and fixed): before this, an
                        // employee with literally zero attendance_records/leave_requests/
                        // overtime_records rows for the whole period was calculated completely
                        // silently -- base salary only, no visible sign anything might be missing.
                        // An admin had no way to tell "genuinely nothing to report this period" apart
                        // from "Origami hasn't finished sending this employee's data yet at cutoff."
                        // Advisory only (same precedent as daily_salary_no_shift_pattern/
                        // salary_type_hourly_not_supported below -- excluded from $blockingErrors,
                        // never flips calc_status to 'error', never blocks submit()) since a
                        // genuinely empty period IS sometimes correct (e.g. a brand-new hire with no
                        // attendance history yet) -- this is a prompt to verify, not a hard failure.
                        $errors[] = 'no_attendance_data_this_period';
                    }
                }

                // 2026-08-29, generalized from a sync-deduction-only mechanism (explicit request:
                // "ในหน้าทำจ่าย น่าจะเปิดให้แก้ไขตัวเลขได้ ในกรณีที่ระบบคำนวณไม่ตรงนะครับ ทุกค่าเลย") -- was
                // previously applied ONLY to $syncResult['deduction'] lines, inside the
                // sync-items-exist branch above (see the removed inline comment this replaces).
                // Now a single pass over EVERY earning + deduction line this employee ended up
                // with, regardless of source (manual_line/standing PED/sync-derived) or whether
                // this is even a sync-based run at all -- $overridesByEmployeeAndCode itself is
                // unchanged (still keyed by run_id+employee_id+item_code from
                // payroll_run_line_overrides), lineOverrideSave() is what actually dropped its own
                // sync_process_id-only restriction (see that method's own docblock). Deliberately
                // still scoped to earning/deduction LINE items only -- statutory (SSO/PVD/tax)
                // amounts are computed from legally-defined formulas and are NOT covered by this
                // mechanism; an admin who genuinely disagrees with a statutory figure should use
                // the existing per-employee tax/SSO exemption/rate-history tools instead, not a
                // free-text override of a government-formula result.
                // 2026-08-29: a line is dropped when EITHER this specific employee has an explicit
                // 'exclude' override for it, OR (with no per-employee override at all for this
                // item) the run-level default ("Run Settings" panel, $runItemExclusionCodesUpper)
                // excludes it for everyone -- see this method's own prefetch docblock above for the
                // full "reuse the existing per-employee mechanism" reasoning.
                $applyLineOverrides = function (array $lines) use ($overridesByEmployeeAndCode, $employeeId, $runItemExclusionCodesUpper): array {
                    $out = [];
                    foreach ($lines as $line) {
                        $override = $overridesByEmployeeAndCode[$employeeId][$line['code']] ?? null;
                        if ($override !== null && $override['action'] === 'exclude') {
                            continue;
                        }
                        if ($override === null && in_array(strtoupper((string)($line['code'] ?? '')), $runItemExclusionCodesUpper, true)) {
                            continue;
                        }
                        if ($override !== null && $override['action'] === 'override_amount') {
                            $line['amount'] = (float)$override['override_amount'];
                            $overrideNote = $override['note'] ? " (override: {$override['note']})" : ' (manually overridden)';
                            $line['note'] = trim(($line['note'] ?? '') . $overrideNote);
                        }
                        $out[] = $line;
                    }
                    return $out;
                };
                $earningLines = $applyLineOverrides($earningLines);
                $deductionLines = $applyLineOverrides($deductionLines);

                // Base salary itself, overridable via the SAME mechanism under the reserved
                // item_code `__base_salary__` (not a real catalog item -- validated as a reserved
                // constant in lineOverrideSave(), never collides with a real payroll_earning_
                // deduction_types.item_code since those are admin-defined and this one is fixed).
                // 2026-08-29: 'exclude' now DOES have a meaning here (explicit request: "เพิ่มให้
                // สามารถเลือกเอาเงินเดือนออกจากการคำนวณได้") -- zeroes effectiveBase, same as dropping a
                // real line would for an earning/deduction item. A per-employee override (either
                // action) always wins over the run-level default below; with no override at all,
                // the run-level default zeroes it for everyone.
                $baseSalaryOverride = $overridesByEmployeeAndCode[$employeeId][self::BASE_SALARY_OVERRIDE_CODE] ?? null;
                if ($baseSalaryOverride !== null && $baseSalaryOverride['action'] === 'exclude') {
                    $effectiveBase = 0.0;
                } elseif ($baseSalaryOverride !== null && $baseSalaryOverride['action'] === 'override_amount') {
                    $effectiveBase = (float)$baseSalaryOverride['override_amount'];
                } elseif ($baseSalaryOverride === null && in_array(strtoupper(self::BASE_SALARY_OVERRIDE_CODE), $runItemExclusionCodesUpper, true)) {
                    $effectiveBase = 0.0;
                }

                $perEmployeeData[$employeeId] = [
                    'emp' => $emp,
                    'effectiveBase' => $effectiveBase,
                    'prorateDays' => $prorateDays,
                    'prorateTotalDays' => $prorateTotalDays,
                    'earningLines' => $earningLines,
                    'deductionLines' => $deductionLines,
                    'errors' => $errors,
                ];
            }

            // Employee-to-employee transfer deductions (2026-08-21, explicit request: "หักเพื่อไปจ่าย
            // ให้ใคร โดยเลือกพนักงานได้ว่าจะหักของคนนี้ไปให้คนนี้") -- a deduction line with
            // payee_employee_id set (standing employee_earning_deductions assignment or a one-off
            // payroll_run_manual_lines row, see their own SELECTs above) becomes a real TAXABLE
            // earning line for the payee, credited in THIS SAME run. A separate pass between line-
            // assembly and totals/insert (not inline in Pass 1) because crediting payee B requires
            // knowing employee A's already-computed deduction amount, regardless of which order A/B
            // appear in $employees.
            $transferCreditsByPayee = [];
            foreach ($perEmployeeData as $fromEmployeeId => &$fromData) {
                foreach ($fromData['deductionLines'] as &$dLine) {
                    $payeeId = $dLine['payee_employee_id'] ?? null;
                    if ($payeeId === null) {
                        continue;
                    }
                    $payeeId = (int)$payeeId;
                    if (!isset($perEmployeeData[$payeeId])) {
                        // Payee isn't part of this run -- the deduction still happens (employee A
                        // still loses the money), but the transfer itself is surfaced as a Remark
                        // rather than silently dropped, same "visible, not silent" convention as
                        // missing_ot_rate_*/no_rate_configured above.
                        //
                        // 2026-09-02, Deduction Destination & Third-Party Remittance (confirmed via
                        // AskUserQuestion): this used to BLOCK the run (calc_status='error'), no
                        // recovery path beyond re-adding the payee to the run. Downgraded to
                        // advisory (see this method's own $blockingErrors filter below) -- the
                        // payee-not-in-run case is now a real, supported outcome: at Approved,
                        // PaymentDestinationModel's own remittance-grouping step (see
                        // PayrollRemittanceModel::generateForRun()) detects the exact same
                        // condition independently (a payee_type='employee' line whose
                        // payee_employee_id has no payroll_run_details row in this run) and routes
                        // it as an `employee_fallback` remittance -- a real external transfer paid
                        // to that employee's own bank details, surfaced for confirmation before the
                        // approval finalizes. Nothing else about this pass changes: the credit is
                        // still skipped here (there's no in-run payee to credit).
                        $fromData['errors'][] = "transfer_payee_not_in_run:{$dLine['code']}";
                        continue;
                    }
                    $fromEmployeeNo = $fromData['emp']['employee_no'] ?? ('#' . $fromEmployeeId);
                    $transferCreditsByPayee[$payeeId][] = [
                        'source' => 'transfer_in',
                        'code' => 'TRANSFER_IN',
                        'name_th' => "รับโอนจาก {$fromEmployeeNo}",
                        'name_en' => "Transfer from {$fromEmployeeNo}",
                        'amount' => (float)$dLine['amount'],
                        'is_custom' => true,
                    ];
                    $dLine['payee_employee_no'] = $perEmployeeData[$payeeId]['emp']['employee_no'] ?? ('#' . $payeeId);
                }
                unset($dLine);
            }
            unset($fromData);

            // Pass 2: totals/statutory/insert (unchanged logic from before the transfer-deduction
            // feature), now reading Pass 1's stashed lines merged with any transfer credit queued
            // for this employee above.
            foreach ($employees as $emp) {
                $employeeId = (int)$emp['id'];

                // 2026-08-29 (retargeted to is_verified 2026-08-31): a verified employee's row is
                // re-inserted byte-for-byte from what was preserved before the DELETE above -- no
                // statutory recompute, no transfer-credit merge, nothing. This is the actual "don't
                // touch this employee's numbers at all" guarantee ("ถ้า Verify แล้ว จะไม่คำนวณอีกต่อไป");
                // Pass 1's own bypass above only exists so an OTHER (unverified) employee receiving a
                // transfer credit FROM this one still resolves correctly.
                if (isset($verifiedPreservedRows[$employeeId])) {
                    $preserved = $verifiedPreservedRows[$employeeId];
                    $insStmt->execute([
                        ':run_id' => $id, ':employee_id' => $employeeId,
                        ':base_salary_amount' => $preserved['base_salary_amount'],
                        ':prorate_days' => $preserved['prorate_days'],
                        ':prorate_total_days' => $preserved['prorate_total_days'],
                        ':earning_breakdown' => $preserved['earning_breakdown'],
                        ':deduction_breakdown' => $preserved['deduction_breakdown'],
                        ':statutory_breakdown' => $preserved['statutory_breakdown'],
                        ':gross_amount' => $preserved['gross_amount'],
                        ':taxable_gross_amount' => $preserved['taxable_gross_amount'] ?? $preserved['gross_amount'],
                        ':total_deduction_amount' => $preserved['total_deduction_amount'],
                        ':net_amount' => $preserved['net_amount'],
                        ':employer_cost_amount' => $preserved['employer_cost_amount'],
                        ':calc_status' => $preserved['calc_status'],
                        ':calc_errors' => $preserved['calc_errors'],
                        ':data_source' => $preserved['data_source'],
                    ]);
                    $totalGross += (float)$preserved['gross_amount'];
                    $totalDeduction += (float)$preserved['total_deduction_amount'];
                    $totalNet += (float)$preserved['net_amount'];
                    if ($preserved['calc_status'] === 'error') {
                        $anyError = true;
                    }
                    continue;
                }

                $pdata = $perEmployeeData[$employeeId];
                $effectiveBase = $pdata['effectiveBase'];
                $prorateDays = $pdata['prorateDays'];
                $prorateTotalDays = $pdata['prorateTotalDays'];
                $earningLines = array_merge($pdata['earningLines'], $transferCreditsByPayee[$employeeId] ?? []);
                $deductionLines = $pdata['deductionLines'];
                $errors = $pdata['errors'];
                $employeeFlags = [
                    'sso_enrolled' => (bool)$emp['sso_enrolled'],
                    'pvd_enrolled' => (bool)$emp['pvd_enrolled'],
                    'tax_exempt' => (bool)$emp['tax_exempt'],
                ];
                // 2026-08-29: run-level tax/SSO calculation default ("Run Settings" panel), applied
                // before any per-employee setting below -- most specific setting always wins:
                // per-employee override > run-level default > employee's own permanent flag (the
                // original, unchanged behavior when neither of the above is configured).
                if ($runCalcSettings['tax_calculate_default'] === 'yes') {
                    $employeeFlags['tax_exempt'] = false;
                } elseif ($runCalcSettings['tax_calculate_default'] === 'no') {
                    $employeeFlags['tax_exempt'] = true;
                }
                if ($runCalcSettings['sso_calculate_default'] === 'yes') {
                    $employeeFlags['sso_enrolled'] = true;
                } elseif ($runCalcSettings['sso_calculate_default'] === 'no') {
                    $employeeFlags['sso_enrolled'] = false;
                }
                // Per-run, per-employee override -- bidirectional tri-state (see
                // saveEmployeeExemption()'s own docblock for the 2026-08-29 widening from the
                // original force-off-only exempt_tax/exempt_sso booleans).
                $runExemption = $exemptionsByEmployee[$employeeId] ?? null;
                if ($runExemption !== null) {
                    $taxOverride = $runExemption['tax_calculate_override'] ?? 'inherit';
                    if ($taxOverride === 'yes') {
                        $employeeFlags['tax_exempt'] = false;
                    } elseif ($taxOverride === 'no') {
                        $employeeFlags['tax_exempt'] = true;
                    }
                    $ssoOverride = $runExemption['sso_calculate_override'] ?? 'inherit';
                    if ($ssoOverride === 'yes') {
                        $employeeFlags['sso_enrolled'] = true;
                    } elseif ($ssoOverride === 'no') {
                        $employeeFlags['sso_enrolled'] = false;
                    }
                }

                // 2026-08-30, explicit request: defer PVD contribution until probation passes --
                // applied AFTER every other tax/SSO override above so it can't be silently re-enabled
                // by a run-level default or per-employee exemption meant for tax/SSO (this is a
                // separate, PVD-specific gate; StatutoryCalculationEngine::calculate()'s own
                // $employeeFlags['pvd_enrolled'] is read the same way whether it came from the
                // employee's own permanent setting or this override).
                // 2026-08-31: intern equivalent, same "internship takes precedence over probation"
                // rule as the base-salary ratio/Recurring Allowances gates in Pass 1 above -- this is
                // a SEPARATE loop iteration (Pass 2, see this method's own "Pass 1"/"Pass 2" comments),
                // so $isIntern/$isProbation from Pass 1 are out of scope here and recomputed fresh
                // from the same $emp row instead of being threaded through.
                $isInternPass2 = ($emp['employment_type'] ?? null) === 'internship';
                $isProbationPass2 = ($emp['employment_status'] ?? null) === 'probation';
                // 2026-09-04, Backlog Phase 10, T056: same per-employee Probation Set resolution as
                // Pass 1's own $employeeProbationSettingsPass1 above -- recomputed fresh here since
                // this is a separate loop iteration (Pass 2), same reasoning as
                // $isInternPass2/$isProbationPass2 themselves not being threaded through from Pass 1.
                $employeeProbationSettingsPass2 = $isProbationPass2
                    ? $this->policyModel->probationSettings($compId, $employeeId)
                    : $probationSettings;
                // 2026-09-02, follow-up to close a review-flagged gap: per-employee override, same
                // "NULL = use company default" convention as the ratio/defer_recurring_earning
                // overrides in Pass 1 above -- recomputed fresh here since this is a separate loop
                // iteration (Pass 2), same reasoning as $isInternPass2/$isProbationPass2 themselves.
                $probationDeferPvdEffective = $emp['probation_defer_pvd_override'] !== null
                    ? (bool)$emp['probation_defer_pvd_override'] : $employeeProbationSettingsPass2['defer_pvd'];
                $internDeferPvdEffective = $emp['intern_defer_pvd_override'] !== null
                    ? (bool)$emp['intern_defer_pvd_override'] : $internSettings['defer_pvd'];
                if ($isInternPass2 ? $internDeferPvdEffective : ($probationDeferPvdEffective && $isProbationPass2)) {
                    $employeeFlags['pvd_enrolled'] = false;
                }
                // 2026-09-02, follow-up to close a review-flagged gap: "เงื่อนไขการหักภาษี/ประกันสังคมที่
                // แตกต่างจากพนักงานปกติ (ถ้ามี)" was never actually built -- SSO gets the EXACT SAME
                // "defer contribution until probation/internship passes" mechanism PVD already has
                // immediately above, just for sso_enrolled instead of pvd_enrolled.
                $probationDeferSsoEffective = $emp['probation_defer_sso_override'] !== null
                    ? (bool)$emp['probation_defer_sso_override'] : $employeeProbationSettingsPass2['defer_sso'];
                $internDeferSsoEffective = $emp['intern_defer_sso_override'] !== null
                    ? (bool)$emp['intern_defer_sso_override'] : $internSettings['defer_sso'];
                if ($isInternPass2 ? $internDeferSsoEffective : ($probationDeferSsoEffective && $isProbationPass2)) {
                    $employeeFlags['sso_enrolled'] = false;
                }

                // 2026-09-02: per-employee SSO rate override (Origami candidates.php's
                // sso_employee_rate_percent/sso_company_rate_percent -- see EmployeeSyncer's own
                // docblock) -- wired straight through to StatutoryCalculationEngine::calculate(),
                // which gives it precedence over company_statutory_settings' own override. NULL on
                // either column (the common case -- most employees pay the standard rate) means "no
                // override" and is simply omitted here rather than passed as an explicit null, same
                // "absent key = untouched" contract the engine's own docblock documents.
                $employeeRateOverrides = [];
                $ssoRateOverride = [];
                if ($emp['sso_contribution_rate'] !== null) {
                    $ssoRateOverride['employee_rate_override'] = (float)$emp['sso_contribution_rate'];
                }
                if ($emp['sso_employer_contribution_rate'] !== null) {
                    $ssoRateOverride['employer_rate_override'] = (float)$emp['sso_employer_contribution_rate'];
                }
                if (!empty($ssoRateOverride)) {
                    $employeeRateOverrides['TH_SSO'] = $ssoRateOverride;
                }

                // 2026-09-10, Batch 3A item 7a: TH_PVD per-employee rate override + tenure-based
                // employer-rate ladder, wired into the SAME $employeeRateOverrides channel TH_SSO
                // already uses above (precedence: employee override > ladder > company/master
                // rate). Employee-side has no ladder (not requested) -- just employees.
                // pvd_employee_rate directly, same shape as TH_SSO's own employee_rate_override.
                $pvdRateOverride = [];
                $pvdEmployerRateSource = 'default';
                $pvdLadderTierUsed = null;
                if ($emp['pvd_employee_rate'] !== null) {
                    $pvdRateOverride['employee_rate_override'] = (float)$emp['pvd_employee_rate'];
                }
                if ($emp['pvd_employer_rate'] !== null) {
                    $pvdRateOverride['employer_rate_override'] = (float)$emp['pvd_employer_rate'];
                    $pvdEmployerRateSource = 'employee_override';
                } else {
                    // Tenure counted as of THIS run's own period_end_date, not "today" (explicit
                    // request) -- a run being calculated for a past period must reflect the tenure
                    // AT THAT TIME, not whenever the calculation happens to actually run.
                    $pvdJoinDate = $emp['pvd_start_date'] ?? $emp['employment_date'];
                    if ($pvdJoinDate !== null) {
                        $serviceYears = PvdEmployerRateLadderModel::serviceYears((string)$pvdJoinDate, $periodEnd);
                        $tier = $this->pvdLadderModel->resolveTier($compId, $serviceYears);
                        if ($tier !== null) {
                            $pvdRateOverride['employer_rate_override'] = $tier['rate_percent'];
                            $pvdEmployerRateSource = 'ladder';
                            $pvdLadderTierUsed = $tier;
                        }
                    }
                }
                if (!empty($pvdRateOverride)) {
                    $employeeRateOverrides['TH_PVD'] = $pvdRateOverride;
                }

                $earningTotal = array_sum(array_column($earningLines, 'amount'));
                $pedDeductionTotal = array_sum(array_column($deductionLines, 'amount'));
                $grossAmount = round($effectiveBase + $earningTotal, 2);

                // 2026-08-29: SSO/PF-eligible earnings base -- base salary always counts, plus any
                // earning line whose catalog item_code is flagged calc_sso/calc_pf (see the
                // $calcSsoItemCodes/$calcPfItemCodes prefetch above for the full reasoning). A
                // transfer-credit line (source='transfer_in') is deliberately excluded from BOTH --
                // it's money credited from a DIFFERENT employee's deduction, not wages paid to this
                // employee for their own work, so it was never a candidate for this employee's own
                // SSO/PF base regardless of what the paying employee's own item is flagged.
                //
                // 2026-08-30: $taxableGrossAmount is a SEPARATE figure from $grossAmount above --
                // $grossAmount/$netAmount stay exactly as they always were (full gross including
                // non_taxable earnings, for net-pay/reporting) -- only $taxableGrossAmount feeds
                // PIT withholding further down. base salary + transfer-in credits always count as
                // taxable (transfer_in is real income to THIS employee even though it's excluded
                // from their own SSO/PF base above for a different reason); an earning line whose
                // catalog item_code is flagged tax_treatment='non_taxable' is excluded.
                $ssoEligibleBase = $effectiveBase;
                $pfEligibleBase = $effectiveBase;
                $taxableGrossAmount = $effectiveBase;
                foreach ($earningLines as $eLine) {
                    $lineCode = strtoupper((string)($eLine['code'] ?? ''));
                    if (!($lineCode !== '' && isset($nonTaxableEarningCodes[$lineCode]))) {
                        $taxableGrossAmount += (float)$eLine['amount'];
                    }
                    if (($eLine['source'] ?? null) === 'transfer_in') {
                        continue;
                    }
                    if ($lineCode === '') {
                        continue;
                    }
                    if (isset($calcSsoItemCodes[$lineCode])) {
                        $ssoEligibleBase += (float)$eLine['amount'];
                    }
                    if (isset($calcPfItemCodes[$lineCode])) {
                        $pfEligibleBase += (float)$eLine['amount'];
                    }
                }
                $taxableGrossAmount = max(0.0, round($taxableGrossAmount, 2));

                // Before-tax PED deduction total for THIS period -- folded into ThPitCalculator's
                // own $allowances the same way SSO/PVD already are (see that class's own 2026-08-30
                // docblock note), NOT subtracted from $taxableGrossAmount itself.
                $beforeTaxDeductionAmount = 0.0;
                foreach ($deductionLines as $dLine) {
                    $lineCode = strtoupper((string)($dLine['code'] ?? ''));
                    if ($lineCode !== '' && !isset($afterTaxDeductionCodes[$lineCode])) {
                        $beforeTaxDeductionAmount += (float)$dLine['amount'];
                    }
                }
                $beforeTaxDeductionAmount = round($beforeTaxDeductionAmount, 2);

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
                        // taxable_income here is only a rough placeholder for the TH_PIT line --
                        // it gets recomputed properly by ThPitCalculator below (2026-08-21, real
                        // bug fix). Left as-is for every OTHER statutory item, which doesn't read
                        // this key at all. Uses $taxableGrossAmount (2026-08-30, tax_treatment fix),
                        // not the full $grossAmount, for consistency with the real ThPitCalculator
                        // call below -- doesn't actually matter in practice since this placeholder
                        // is always overwritten before use, but keeping them consistent avoids a
                        // confusing intermediate value if that ever changes.
                        'taxable_income' => round($taxableGrossAmount * 12, 2),
                        'net_income' => $grossAmount,
                        // 2026-08-29: TH_SSO/TH_PVD's own calc_base now points here instead of
                        // 'basic_salary' (see migrations/2026-08-29_sso_pf_eligible_earnings_base.sql)
                        // -- any OTHER country's future analogous item (SG CPF, MY SOCSO/EPF, etc.)
                        // can opt into the same "total eligible wages, not just base" concept simply
                        // by pointing its own calc_base at one of these two keys, no engine change needed.
                        'sso_eligible_earnings' => round($ssoEligibleBase, 2),
                        'pf_eligible_earnings' => round($pfEligibleBase, 2),
                    ];
                    // 2026-09-04, Backlog Phase 10, T060: $employeeId/$periodStart threaded
                    // through so a flat_rate item's monthly base/contribution ceiling (TH_SSO/
                    // TH_PVD's real 15,000 THB / 750 THB monthly caps) accumulates correctly
                    // across MULTIPLE settled runs within the same calendar month for a
                    // non-monthly payroll_frequency -- see StatutoryCalculationEngine::calculate()'s
                    // own docblock. A no-op for the existing monthly case (never a prior settled
                    // run this same calendar month by construction).
                    // Real, PRE-EXISTING, unrelated bug found and fixed in the same edit: this call
                    // was passing a literal `[]` for $employeeRateOverrides even though
                    // $employeeRateOverrides (the per-employee TH_SSO rate override, computed just
                    // above from employees.sso_contribution_rate/sso_employer_contribution_rate)
                    // was ALREADY computed a few lines up -- it was simply never threaded into this
                    // call, so the per-employee SSO rate override feature (2026-09-02) has been
                    // silently dead since it shipped: the value synced/stored correctly onto the
                    // employees row, but never actually affected a real payroll run's own
                    // calculation. Fixed by passing the real variable instead of a literal [].
                    $statutoryResult = $this->engine->calculate($compId, $salaryContext, $paymentDate, $employeeFlags, $employeeRateOverrides, $employeeId, $periodStart);

                    // 2026-09-10, Batch 3A item 7a: stamp the resolved TH_PVD employer-rate
                    // source/tier onto its own breakdown item, per explicit request ("เก็บ tier/
                    // อัตราที่ใช้จริงไว้ใน breakdown...จะได้ตรวจย้อนหลังได้") -- an audit trail of WHY
                    // this period's employer contribution used the rate it did, inspectable later
                    // without re-deriving it from whatever the ladder/employee override state
                    // happens to be at the time someone looks.
                    foreach ($statutoryResult['items'] as $pvdIdx => $pvdItem) {
                        if ($pvdItem['code'] !== 'TH_PVD') {
                            continue;
                        }
                        $statutoryResult['items'][$pvdIdx]['employer_rate_source'] = $pvdEmployerRateSource;
                        if ($pvdLadderTierUsed !== null) {
                            $statutoryResult['items'][$pvdIdx]['employer_rate_tier_min_years'] = $pvdLadderTierUsed['min_service_years'];
                            $statutoryResult['items'][$pvdIdx]['employer_rate_tier_max_years'] = $pvdLadderTierUsed['max_service_years'];
                        }
                        break;
                    }

                    // 2026-08-21, real bug fix (explicit report: a 25,000/month employee was
                    // withheld ~7,500 in a single period). The engine's own TH_PIT line above is a
                    // placeholder -- see ThPitCalculator's class docblock for the full root-cause
                    // writeup (no deductions/allowances subtracted, and the annual tax figure was
                    // never divided back down to one period). Recomputed properly here using the
                    // real SSO/PVD amounts the engine just calculated, then the placeholder line is
                    // replaced in place so payslip/report code reading statutory_breakdown needs no
                    // changes at all. Skipped when the employee is tax_exempt (the engine's own
                    // TAX_EXEMPT_ITEMS check already zeroed the placeholder correctly) or when this
                    // company has no TH_PIT item configured at all (non-TH companies).
                    foreach ($statutoryResult['items'] as $pitIdx => $sItem) {
                        if ($sItem['code'] !== 'TH_PIT' || !empty($employeeFlags['tax_exempt'])) {
                            continue;
                        }
                        $ssoAmount = 0.0;
                        $pvdAmount = 0.0;
                        foreach ($statutoryResult['items'] as $siblingItem) {
                            if ($siblingItem['code'] === 'TH_SSO') $ssoAmount = (float)$siblingItem['employee_amount'];
                            if ($siblingItem['code'] === 'TH_PVD') $pvdAmount = (float)$siblingItem['employee_amount'];
                        }
                        // 2026-08-31, explicit request (Origami `attribution` plan's item 3): a
                        // supplemental run that opted into use_flat_tax_rate AND has a company rate
                        // configured withholds a flat % of this period's own taxable gross instead
                        // of the normal average/actual ThPitCalculator computation below -- this is
                        // deliberately a SEPARATE, standalone lump-sum withholding, not folded into
                        // the employee's annual/cumulative tax curve at all (same "separate" spirit
                        // Origami's own attribution_tax_treatment='separate' asks for). Falls through
                        // to the normal calculation when the flag is off OR no rate is configured
                        // (see $flatTaxRatePercent's own comment above) -- never invents a rate.
                        if ($useFlatTaxRate && $flatTaxRatePercent !== null) {
                            $flatAmount = round($taxableGrossAmount * $flatTaxRatePercent / 100, 2);
                            $statutoryResult['items'][$pitIdx]['employee_amount'] = $flatAmount;
                            $statutoryResult['items'][$pitIdx]['base_amount'] = $taxableGrossAmount;
                            $statutoryResult['items'][$pitIdx]['note'] = "th_pit_flat_rate_{$flatTaxRatePercent}";
                            break;
                        }
                        // 2026-09-02, explicit request: an employee individually flagged
                        // tax_non_resident (employees table) withholds this company's configured
                        // non-resident flat % instead of the normal calculation below -- same
                        // "separate, standalone, never folded into the annual/cumulative curve"
                        // spirit as the run-level flat-tax-rate branch immediately above, but
                        // PER-EMPLOYEE rather than per-run (checked second, since a run-level opt-in
                        // is an explicit admin decision for THIS run and takes precedence over a
                        // standing per-employee flag). No-op when the company never configured/
                        // enabled it (see NonResidentTaxSettingModel's own docblock) -- never invents
                        // a rate.
                        if (!empty($emp['tax_non_resident']) && $nonResidentFlatRatePercent !== null) {
                            $flatAmount = round($taxableGrossAmount * $nonResidentFlatRatePercent / 100, 2);
                            $statutoryResult['items'][$pitIdx]['employee_amount'] = $flatAmount;
                            $statutoryResult['items'][$pitIdx]['base_amount'] = $taxableGrossAmount;
                            $statutoryResult['items'][$pitIdx]['note'] = "th_pit_nonresident_flat_rate_{$nonResidentFlatRatePercent}";
                            break;
                        }
                        // 2026-08-30: $taxableGrossAmount/$beforeTaxDeductionAmount (tax_treatment/
                        // tax_deduction_impact fix), not the raw $grossAmount -- see this method's
                        // own comment at their computation above, and ThPitCalculator's own docblock.
                        $pit = $this->thPitCalculator->calculate(
                            $compId, $employeeId, $taxableGrossAmount, $ssoAmount, $pvdAmount, $beforeTaxDeductionAmount, $periodsPerYear,
                            (string)($emp['tax_calculation_method'] ?? 'average'), (bool)$emp['has_spouse'],
                            $periodStart, $paymentDate
                        );
                        // base_amount/note also overwritten, not just employee_amount -- otherwise
                        // the stored breakdown would keep showing the placeholder gross*12 figure
                        // next to a since-corrected amount, which is confusing/inconsistent for
                        // anyone inspecting it later (e.g. on a payslip's statutory line detail).
                        $statutoryResult['items'][$pitIdx]['employee_amount'] = $pit['employee_amount'];
                        $statutoryResult['items'][$pitIdx]['base_amount'] = $pit['annual_taxable_income'];
                        $statutoryResult['items'][$pitIdx]['note'] = $pit['method'] === 'actual'
                            ? "th_pit_cumulative_annual_tax_{$pit['annual_tax']}"
                            : "th_pit_average_annual_tax_{$pit['annual_tax']}";
                        break;
                    }

                    // 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย" -- item 9a of the 9-part
                    // batch's own Phase 3, "ในหน้า Process Detail ตัวเลขทุกตัวต้องสามารถแก้ไขได้...ไม่ว่าจะ
                    // อยู่ที่ modal ไหน...และมี checkbox ให้ติ๊กออกไม่นำมาคำนวณได้ทุกตัวเลขเหมือนกัน"): the
                    // SAME payroll_run_line_overrides table/lineOverrideSave() mechanism that already
                    // covers every earning/deduction line + base salary now ALSO covers every
                    // statutory line (TH_SSO/TH_PVD/TH_PIT/any future country's own item) -- reuses
                    // statutoryOverrideCode()'s reserved-sentinel wrapping (same "__base_salary__"
                    // precedent BASE_SALARY_OVERRIDE_CODE already established, see that const's own
                    // comment) so a statutory item_code can never collide with a company's own
                    // free-text earning/deduction catalog item_code in this shared table. Only ever
                    // touches employee_amount (the figure an admin would actually be correcting) --
                    // employer_amount (a company-cost figure, not something this feature was asked to
                    // let anyone override) is left exactly as the engine computed it. Distinct from
                    // the existing yes/no saveEmployeeExemption() toggle (see this method's own
                    // adjacent comment above) -- that answers "does this item apply at all", this
                    // answers "what amount, or excluded, for THIS run specifically" -- both can be
                    // active on the same item at once (exemption zeroes it first via the engine's own
                    // TAX_EXEMPT_ITEMS/enrollment-flag check; if NOT exempt, a line override applied
                    // here still wins over whatever the engine computed).
                    foreach ($statutoryResult['items'] as $sIdx => $sItemForOverride) {
                        $statutoryOverride = $overridesByEmployeeAndCode[$employeeId][$this->statutoryOverrideCode($sItemForOverride['code'])] ?? null;
                        if ($statutoryOverride === null) {
                            continue;
                        }
                        if ($statutoryOverride['action'] === 'exclude') {
                            $statutoryResult['items'][$sIdx]['employee_amount'] = 0.0;
                            $statutoryResult['items'][$sIdx]['note'] = 'manually_excluded';
                        } else {
                            $statutoryResult['items'][$sIdx]['employee_amount'] = (float)$statutoryOverride['override_amount'];
                            $statutoryResult['items'][$sIdx]['note'] = 'manually_overridden';
                        }
                    }

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

                // 2026-09-02, explicit request: "การตั้งค่าเงินรวมกันถ้าเกินจำนวนเงินเดือนมีการดักส่วนนี้ไว้ไหม"
                // -- found a real, partial gap while investigating: EmployeePaymentMethodModel::
                // validateMixedLines() only checks a PERCENT-ONLY line set sums to 100 (fully
                // checkable at Employee-save time); a set containing any FIXED-amount line skips that
                // check entirely there (net pay isn't known yet). Previously the ONLY place that ever
                // reconciled a fixed-line set against real net pay was BankTransferFileReport::
                // generate() -- and even that only checked the TRANSFER-line subset, silently
                // skipping that employee's transfer (never partially/wrongly disbursing) and flagging
                // it inside the exported file's own comment row; CashPaymentSummaryReport's cash-line
                // subset was never checked against anything at all. Confirmed via AskUserQuestion:
                // check the FULL line set (cash+transfer+check together) HERE instead, the moment
                // this row's own net pay is actually known, so a mismatch is a visible Remark on the
                // run itself (calc_errors, see payroll/detail.js's own calcErrorsRemarkRd()) instead
                // of only surfacing later as a silently-skipped file row nobody looked at yet.
                // Advisory only (added to $blockingErrors' own exclusion list below), same "flag it,
                // don't block the whole run over it" treatment as daily_salary_no_shift_pattern below
                // -- a percent-only set is already guaranteed to reconcile (validateMixedLines() above
                // blocks that at save time), so this only ever actually fires for a fixed-amount set
                // whose sum turned out wrong, which the admin genuinely needs to go fix on Employee
                // Detail's own Payment tab, not something this run itself can safely auto-correct.
                // Checked against THIS row's own $netAmount, not the merge-into-round-adjusted
                // net_amount_due PayrollReportDataModel/BankTransferFileReport compute at report time
                // (that needs a further per-employee payment-events query this loop doesn't otherwise
                // need) -- a deliberate simplification: correct for the common non-merged case, and
                // still a useful early warning for the merged case even where the exact "still owed"
                // figure can differ slightly (report-generation time remains the authoritative check
                // for an actual export).
                if (($paymentMethodCodesById[(int)($emp['payment_method_id'] ?? 0)] ?? null) === 'mixed') {
                    $mixedLines = $this->paymentMethodModel->getLines($employeeId);
                    if (!empty($mixedLines)) {
                        $mixedLinesTotal = 0.0;
                        foreach ($mixedLines as $mixedLine) {
                            $mixedLinesTotal += $mixedLine['amount_type'] === 'percent'
                                ? round($netAmount * (float)$mixedLine['amount_value'] / 100, 2)
                                : (float)$mixedLine['amount_value'];
                        }
                        if (abs($mixedLinesTotal - $netAmount) > 0.01) {
                            $errors[] = 'mixed_payment_lines_mismatch';
                        }
                    }
                }
                // daily_salary_no_shift_pattern/hourly_salary_no_attendance_data are advisory only
                // (same spirit as no_rate_ever_configured above) -- surfaced as a visible Remark via
                // calc_errors so the admin can act on them, but the run still pays out using its
                // documented safe-default fallback (a $0 base for the hourly case specifically -- see
                // that branch's own comment on why there's no safe non-zero default to guess), so
                // they must NOT flip calc_status to 'error' and block submit() the way
                // missing_base_salary/no_rate_configured genuinely should. salary_type_hourly_not_supported
                // itself is retired (2026-08-31, real hourly formula now exists) but harmless to leave
                // in this list in case an already-approved/locked older run still carries it in its
                // preserved calc_errors.
                //
                // 2026-09-02, real gap found while adding SyncPayResolver's own working_days_fallback_
                // with_attendance_deduction warning: it's a PREFIXED code (":eventCode" suffix, same
                // shape as no_rate_configured:/transfer_payee_not_in_run:), so it can never exact-match
                // a plain string in this whitelist -- needed a prefix-aware filter, not just array_diff.
                // Downgraded to advisory HERE (not blocking) after Origami confirmed their own sync
                // payload can never actually trigger it (working_days/working_mins share the same
                // umbrella selection flag as Late/Absent, so one can never be 0 while the other has a
                // real deduction quantity) -- but TransactionDataPayAdapter's own Manual Entry/Import
                // path DELIBERATELY never sets working_days/working_mins at all (see its own docblock:
                // "inventing one here would be a guess"), so this warning would otherwise fire on
                // EVERY Manual/Import-driven cycle run with any Late/Absent/Unpaid-Leave deduction --
                // making it blocking would have broken that entire, already-accepted-as-a-known-
                // limitation code path. Kept as a visible Remark (still useful: tells an admin exactly
                // which event's amount used the fallback divisor) without ever blocking submit().
                $blockingErrors = array_filter(
                    array_diff($errors, ['daily_salary_no_shift_pattern', 'salary_type_hourly_not_supported', 'hourly_salary_no_attendance_data', 'no_attendance_data_this_period', 'ot_not_calculated_ineligible', 'mixed_payment_lines_mismatch']),
                    static fn($e) => strpos((string)$e, 'working_days_fallback_with_attendance_deduction:') !== 0
                        // 2026-09-02, Deduction Destination & Third-Party Remittance -- see this
                        // error's own push site (the transfer-credit pass above) for why this is
                        // now advisory, not blocking.
                        && strpos((string)$e, 'transfer_payee_not_in_run:') !== 0
                );
                $calcStatus = empty($blockingErrors) ? 'calculated' : 'error';
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
                    ':taxable_gross_amount' => $taxableGrossAmount,
                    ':total_deduction_amount' => $totalDeductionAmount,
                    ':net_amount' => $netAmount,
                    ':employer_cost_amount' => round($statutoryEmployerTotal, 2),
                    ':calc_status' => $calcStatus,
                    ':calc_errors' => empty($errors) ? null : implode(', ', $errors),
                    ':data_source' => $emp['data_source'] ?? 'manual',
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

    /* ==================== MANUAL EMPLOYEE ROSTER (off-cycle and sync-based runs) ==================== */

    /**
     * Guard shared by joinEmployees()/removeManualEmployee(): both just need a draft run that
     * exists. Used to unconditionally block a cycle-based run until 2026-08-21 -- see the comment
     * inline below for why that changed.
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
        // 2026-08-21, explicit request ("พนักงานทุกคน สามารถลบข้อมูลออกจากรอบได้ ต่อให้ Sync มาจาก
        // Origami เองก็ตาม"): a cycle-based run's automatic membership can now be overridden per
        // employee via payroll_run_excluded_employees (see recalculate()'s cycle-branch eligibility
        // query), so it's no longer blocked entirely here. joinEmployees() applies its OWN
        // finer-grained restriction for a cycle-only run (re-include an excluded employee only,
        // never add an arbitrary new one) since that distinction only makes sense once
        // employee_ids are known.
        return [$run, null];
    }

    /**
     * Adds one or more employees to a run, then recalculates so the calculation table reflects the
     * change immediately (payroll_run_details is always a full rebuild from current membership,
     * same as any other recalculate() trigger). Two distinct behaviors depending on run type
     * (2026-08-21, explicit request -- this is also the undo path for removeManualEmployee()'s new
     * universal-remove behavior, reusing this existing picker/endpoint instead of new UI):
     *  - Off-cycle / sync-based run: unchanged from before -- INSERT IGNORE into
     *    payroll_run_manual_employees.
     *  - Genuine CYCLE-only run: "joining" here can only mean "re-include an employee this run
     *    previously excluded" -- membership is otherwise fully automatic by employment date range,
     *    so there's no sense in which an arbitrary employee can be "added". $employeeIds is
     *    narrowed to whichever of them currently have a payroll_run_excluded_employees row for this
     *    run; anything outside that set is dropped (not inserted into payroll_run_manual_employees
     *    -- manualEmployeeOptions() is expected to only ever offer excluded employees for a
     *    cycle-only run in the first place), and if NOTHING in the request qualifies, the call fails.
     * Either way, any exclusion row for the ids actually being joined is cleared, so a previously
     * -removed synced/cycle-automatic employee comes back correctly on the recalculate() below.
     * @param int[] $employeeIds
     */
    public function joinEmployees(int $id, int $compId, array $employeeIds, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
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

        $isPureCycleRun = $run['cycle_id'] !== null && $run['sync_process_id'] === null;
        if ($isPureCycleRun) {
            $excludedPlaceholders = implode(',', array_fill(0, count($validIds), '?'));
            $stmtExcluded = $this->db->prepare("SELECT employee_id FROM `payroll_run_excluded_employees` WHERE run_id = ? AND employee_id IN ({$excludedPlaceholders})");
            $stmtExcluded->execute(array_merge([$id], $validIds));
            $excludedIds = array_map('intval', $stmtExcluded->fetchAll(PDO::FETCH_COLUMN));
            $validIds = array_values(array_intersect($validIds, $excludedIds));
            if (empty($validIds)) {
                return ['status' => false, 'message' => "A cycle-based run's membership is automatic by employment date -- only an employee previously removed from this run can be re-included here."];
            }
        }

        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) { $this->db->beginTransaction(); }
            if (!$isPureCycleRun) {
                $ins = $this->db->prepare("INSERT IGNORE INTO `payroll_run_manual_employees` (run_id, employee_id, joined_by) VALUES (:run_id, :employee_id, :joined_by)");
                foreach ($validIds as $employeeId) {
                    $ins->execute([':run_id' => $id, ':employee_id' => $employeeId, ':joined_by' => $userId]);
                }
            }
            $validPlaceholders = implode(',', array_fill(0, count($validIds), '?'));
            $this->db->prepare("DELETE FROM `payroll_run_excluded_employees` WHERE run_id = ? AND employee_id IN ({$validPlaceholders})")
                ->execute(array_merge([$id], $validIds));
            if ($ownTransaction) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }

        $recalcRes = $this->recalculate($id, $compId, $userId, $isAdmin);
        $recalcRes['joined_count'] = count($validIds);
        return $recalcRes;
    }

    /**
     * Removes one employee from a run, regardless of how they got there (2026-08-21, explicit
     * request: "พนักงานทุกคน สามารถลบข้อมูลออกจากรอบได้ ต่อให้ Sync มาจาก Origami เองก็ตาม" -- ALL
     * employees removable, even a genuinely-synced row). Previously this only deleted a
     * payroll_run_manual_employees row, which was a no-op for a synced/cycle-automatic employee --
     * they simply came right back on the very next recalculate() regardless, since neither the
     * sync-branch nor cycle-branch eligibility query in recalculate() ever consulted that table.
     * Now does BOTH unconditionally: delete any manual-roster row (harmless no-op if none -- covers
     * the off-cycle/manually-joined case exactly as before) AND record the exclusion in
     * payroll_run_excluded_employees (harmless if already excluded, via INSERT IGNORE -- covers the
     * synced/cycle-automatic case, which recalculate()'s eligibility queries now both check via
     * NOT EXISTS). Undo: see joinEmployees()'s cycle-only-run branch / manualEmployeeOptions().
     */
    public function removeManualEmployee(int $id, int $compId, int $employeeId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        [$run, $err] = $this->assertManualRosterEditable($id, $compId);
        if ($err !== null) {
            return ['status' => false, 'message' => $err];
        }
        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) { $this->db->beginTransaction(); }
            $this->db->prepare("DELETE FROM `payroll_run_manual_employees` WHERE run_id = :run_id AND employee_id = :employee_id")
                ->execute([':run_id' => $id, ':employee_id' => $employeeId]);
            $this->db->prepare("INSERT IGNORE INTO `payroll_run_excluded_employees` (run_id, employee_id, excluded_by) VALUES (:run_id, :employee_id, :excluded_by)")
                ->execute([':run_id' => $id, ':employee_id' => $employeeId, ':excluded_by' => $userId]);
            if ($ownTransaction) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
        return $this->recalculate($id, $compId, $userId, $isAdmin);
    }

    /**
     * Server-side DataTables source for the "Join Employees" picker modal -- every employee in
     * this company NOT already on the run's manual roster, optionally filtered by department_id/
     * position_id/emp_cycle_id (the employee's own standing payroll cycle, employees.cycle_id --
     * 2026-08-22, explicit request) and a free-text search. Deliberately a standalone query rather
     * than reusing EmployeeModel::list() -- "exclude whoever's already joined to run X" is
     * specific to this one picker, not a general employee-list concern.
     */
    /** Shared WHERE-builder for manualEmployeeOptions()/manualEmployeeAllIds() -- both need the
     *  exact same "who's eligible to be joined onto this run" logic (pure-cycle-run re-include-only
     *  branch, sync-run already-mapped exclusion, plus every filter), just with different
     *  pagination/output shapes on top. @return array{0:string,1:array} [$whereSql, $params] */
    private const MANUAL_EMPLOYEE_JOINS = "FROM `employees` e
                LEFT JOIN `structure_departments` d ON e.department_id = d.id
                LEFT JOIN `structure_teams` tm ON e.team_id = tm.id
                LEFT JOIN `structure_positions` p ON e.position_id = p.id
                LEFT JOIN `payroll_cycles` c ON e.cycle_id = c.id";

    /** Frontend column KEY -> real SQL expression for the Join Employees picker's Excel-style
     *  column filter (2026-08-27 rollout) -- mirrors EmployeeModel::listColumnExprMap()'s own
     *  $lang-resolution for name/department/team/position. No actions/checkbox column here (this
     *  picker has neither) -- every listed column is filterable. */
    private function manualEmployeeFilterExprMap(string $lang): array {
        $deptCol = $lang === 'en' ? 'department_name_en' : 'department_name_th';
        $posiCol = $lang === 'en' ? 'position_name_en' : 'position_name_th';
        $teamCol = $lang === 'en' ? 'team_name_en' : 'team_name_th';
        return [
            'employee_no' => 'e.employee_no',
            'name' => $lang === 'en' ? "CONCAT(e.name_en, ' ', e.surname_en)" : "CONCAT(e.name_th, ' ', e.surname_th)",
            'department' => "d.{$deptCol}",
            'team' => "tm.{$teamCol}",
            'position' => "p.{$posiCol}",
            'cycle_name' => 'c.cycle_name',
        ];
    }

    private function applyManualEmployeeColumnFilters(string $whereSql, array &$params, array $columnFilters, array $exprMap, ?string $excludeColumn = null): string {
        $paramIdx = 0;
        foreach ($columnFilters as $col => $values) {
            if ($col === $excludeColumn || !isset($exprMap[$col]) || !is_array($values) || empty($values)) {
                continue;
            }
            $values = array_values(array_filter($values, fn($v) => $v !== null && $v !== ''));
            if (empty($values)) {
                continue;
            }
            $placeholders = [];
            foreach ($values as $v) {
                $paramIdx++;
                $ph = ":cf{$paramIdx}";
                $placeholders[] = $ph;
                $params[$ph] = (string)$v;
            }
            $whereSql .= " AND {$exprMap[$col]} IN (" . implode(', ', $placeholders) . ")";
        }
        return $whereSql;
    }

    private function buildManualEmployeeWhere(int $compId, int $runId, array $filters): array {
        $run = $this->get($runId, $compId);
        $isPureCycleRun = $run && $run['cycle_id'] !== null && $run['sync_process_id'] === null;

        if ($isPureCycleRun) {
            // 2026-08-21, explicit request ("พนักงานทุกคน สามารถลบข้อมูลออกจากรอบได้..."): a
            // cycle-based run's membership is fully automatic by employment date -- the only thing
            // this picker can ever offer here is "re-include a previously-removed employee" (see
            // joinEmployees()'s cycle-only-run branch), never an arbitrary new add.
            $where = "e.comp_id = :comp_id AND e.deleted_at IS NULL
                AND EXISTS (SELECT 1 FROM `payroll_run_excluded_employees` pex WHERE pex.run_id = :run_id AND pex.employee_id = e.id)";
            $params = [':comp_id' => $compId, ':run_id' => $runId];
        } else {
            $where = "e.comp_id = :comp_id AND e.deleted_at IS NULL
                AND NOT EXISTS (SELECT 1 FROM `payroll_run_manual_employees` pme WHERE pme.run_id = :run_id AND pme.employee_id = e.id)";
            $params = [':comp_id' => $compId, ':run_id' => $runId];

            // 2026-08-21: a sync-based run can now also have manually-added employees on top of
            // whoever Origami synced -- don't offer someone who's already in the run through sync,
            // they'd just show up twice in spirit (data_source resolves to 'sync' regardless, but
            // there's no reason to let the picker suggest a redundant manual join in the first
            // place) -- UNLESS this run has excluded them, in which case surfacing them back into
            // the picker is exactly how they get re-included (the OR clause below).
            if ($run && $run['sync_process_id'] !== null) {
                $where .= " AND (NOT EXISTS (SELECT 1 FROM `payroll_sync_items` psi WHERE psi.process_id = :sync_process_id AND psi.employee_id = e.id AND psi.mapping_status = 'mapped')
                    OR EXISTS (SELECT 1 FROM `payroll_run_excluded_employees` pex2 WHERE pex2.run_id = :run_id2 AND pex2.employee_id = e.id))";
                $params[':sync_process_id'] = $run['sync_process_id'];
                $params[':run_id2'] = $runId;
            }
        }
        // 2026-08-30 (Phase 3, T021, explicit request: "ไม่จ่ายเงินเดือน...ดึงไปทำรายการไม่ได้") -- a
        // staff-only employee can never be offered by this picker, on ANY run type/branch above
        // (including the pure-cycle-run "re-include a previously-excluded employee" case -- being
        // marked unpaid overrides even an earlier manual re-inclusion decision).
        $where .= " AND e.is_payroll_participant = 1";
        if (!empty($filters['department_id'])) {
            $where .= " AND e.department_id = :department_id";
            $params[':department_id'] = (int)$filters['department_id'];
        }
        // 2026-08-24, explicit request ("ในการดึงพนักงานเข้ามาเพื่อคำนวณเงินเดือน ให้มี Filter ส่วนที่
        // เพิ่มเมื่อสักครู่ด้วยครับ") -- same Team filter just added to Employee List, here too.
        if (!empty($filters['team_id'])) {
            $where .= " AND e.team_id = :team_id";
            $params[':team_id'] = (int)$filters['team_id'];
        }
        if (!empty($filters['position_id'])) {
            $where .= " AND e.position_id = :position_id";
            $params[':position_id'] = (int)$filters['position_id'];
        }
        // 2026-08-22, explicit request ("ตรง Join Employee อยากให้เพิ่ม Filter รอบเงินเดือนได้ด้วย")
        // -- filters by the employee's own standing payroll cycle (employees.cycle_id), not this
        // run's cycle_id -- useful on an off-cycle/incentive run to narrow the picker down to
        // employees who normally belong to one particular cycle, same idea as filtering by
        // department/position.
        if (!empty($filters['emp_cycle_id'])) {
            $where .= " AND e.cycle_id = :emp_cycle_id";
            $params[':emp_cycle_id'] = (int)$filters['emp_cycle_id'];
        }
        return [$where, $params];
    }

    public function manualEmployeeOptions(int $compId, int $runId, int $start, int $length, array $filters, string $search, string $lang = 'th', array $columnFilters = []): array {
        $deptCol = $lang === 'en' ? 'department_name_en' : 'department_name_th';
        $posiCol = $lang === 'en' ? 'position_name_en' : 'position_name_th';
        $teamCol = $lang === 'en' ? 'team_name_en' : 'team_name_th';
        $filterExprMap = $this->manualEmployeeFilterExprMap($lang);

        [$baseWhere, $params] = $this->buildManualEmployeeWhere($compId, $runId, $filters);

        // Both COUNT queries need the same JOINs as the main data query below -- column_filters
        // (2026-08-27) can filter on a JOINed display-name column (e.g. d.department_name_th), not
        // just e.*'s own FK id columns like the pre-existing department_id/team_id/etc. filters, so
        // a bare `FROM employees e` here would 42S22 the moment any column_filters entry is active
        // (same real bug already found and fixed once for EmployeeModel::list() during this rollout).
        $totalStmt = $this->db->prepare("SELECT COUNT(*) " . self::MANUAL_EMPLOYEE_JOINS . " WHERE {$baseWhere}");
        $totalStmt->execute($params);
        $recordsTotal = (int)$totalStmt->fetchColumn();

        $whereSql = $baseWhere;
        if ($search !== '') {
            $whereSql .= " AND (e.employee_no LIKE :search1 OR e.name_th LIKE :search2 OR e.surname_th LIKE :search3 OR e.name_en LIKE :search4 OR e.surname_en LIKE :search5)";
            for ($i = 1; $i <= 5; $i++) {
                $params[":search{$i}"] = "%{$search}%";
            }
        }
        // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter rollout.
        $whereSql = $this->applyManualEmployeeColumnFilters($whereSql, $params, $columnFilters, $filterExprMap);

        $countStmt = $this->db->prepare("SELECT COUNT(*) " . self::MANUAL_EMPLOYEE_JOINS . " WHERE {$whereSql}");
        $countStmt->execute($params);
        $recordsFiltered = (int)$countStmt->fetchColumn();

        $dataSql = "SELECT e.id, e.employee_no,
                    CONCAT(e.name_th, ' ', e.surname_th) AS name_th, CONCAT(e.name_en, ' ', e.surname_en) AS name_en,
                    COALESCE(d.{$deptCol}, '') AS department, COALESCE(tm.{$teamCol}, '') AS team, COALESCE(p.{$posiCol}, '') AS position,
                    COALESCE(c.cycle_name, '') AS cycle_name,
                    e.employment_date
                " . self::MANUAL_EMPLOYEE_JOINS . "
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

    /** 2026-08-24, explicit request ("จัดรูปแบบให้การดึงพนักงานเข้ามาในการคำนวณดำเนินการได้ง่ายที่สุด") --
     *  "Select All" in the Join Employees picker previously only ever meant the current DataTable
     *  page (serverSide:true, so most matches were invisible to a page-scoped select-all). This
     *  returns every employee id matching the current filter/search with NO pagination, so the
     *  frontend can offer a real "select all N matching" action. Same WHERE as
     *  manualEmployeeOptions() (including its own search clause, and now its own column_filters too
     *  -- 2026-08-27 -- so "select all matching" also honors whatever Excel-style filters are
     *  currently checked, not just the pre-existing department/team/position/cycle dropdowns)
     *  -- just id-only, unpaginated. */
    public function manualEmployeeAllIds(int $compId, int $runId, array $filters, string $search, string $lang = 'th', array $columnFilters = []): array {
        [$whereSql, $params] = $this->buildManualEmployeeWhere($compId, $runId, $filters);
        if ($search !== '') {
            $whereSql .= " AND (e.employee_no LIKE :search1 OR e.name_th LIKE :search2 OR e.surname_th LIKE :search3 OR e.name_en LIKE :search4 OR e.surname_en LIKE :search5)";
            for ($i = 1; $i <= 5; $i++) {
                $params[":search{$i}"] = "%{$search}%";
            }
        }
        $whereSql = $this->applyManualEmployeeColumnFilters($whereSql, $params, $columnFilters, $this->manualEmployeeFilterExprMap($lang));
        $stmt = $this->db->prepare("SELECT e.id " . self::MANUAL_EMPLOYEE_JOINS . " WHERE {$whereSql} ORDER BY e.employee_no ASC");
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** Distinct values for ONE column of the Join Employees picker, respecting every OTHER active
     *  Excel-style column filter but not this column's own selection -- see
     *  EmployeeModel::listColumnValues()'s own docblock for why. Also respects this run's own
     *  membership WHERE (buildManualEmployeeWhere()) -- the dropdown only ever offers values that
     *  could actually appear in the picker's own rows, same as every other table in this rollout. */
    public function manualEmployeeColumnValues(int $compId, int $runId, array $filters, string $column, string $lang, array $columnFilters): array {
        $exprMap = $this->manualEmployeeFilterExprMap($lang);
        if (!isset($exprMap[$column])) {
            return [];
        }
        $expr = $exprMap[$column];
        [$whereSql, $params] = $this->buildManualEmployeeWhere($compId, $runId, $filters);
        $whereSql = $this->applyManualEmployeeColumnFilters($whereSql, $params, $columnFilters, $exprMap, $column);
        $sql = "SELECT DISTINCT {$expr} AS value " . self::MANUAL_EMPLOYEE_JOINS . "
                WHERE {$whereSql} AND {$expr} IS NOT NULL AND {$expr} != ''
                ORDER BY value ASC LIMIT 500";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'value');
    }

    /**
     * For an 'incentive' run, manual lines are the ONLY source of earnings/deductions, so they're
     * always addable regardless of membership (joinEmployees() already put the employee in
     * payroll_run_details via its own recalculate() call). For any other 'payroll' run
     * (cycle-based, Pending-Pull, or off-cycle), manual lines are an ADD-ON adjustment on top of
     * the normal calculation (2026-08-19, explicit request) -- so the employee must already be a
     * calculated member of this run (i.e. it's been Recalculated at least once and they're
     * eligible), otherwise the line would sit orphaned with no visible effect until membership
     * happens to include them, which would look like a silent no-op rather than a clear error.
     */
    private function assertManualLinesEditable(int $id, int $compId, int $employeeId): array {
        $run = $this->get($id, $compId);
        if (!$run) {
            return [null, 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return [null, 'Only a draft payroll run can have its earning/deduction items adjusted.'];
        }
        // 2026-08-31: a verified employee's numbers must stay frozen -- see isEmployeeVerifiedForRun()'s
        // own docblock for why this must block every per-employee mutation entry point, not just
        // recalculate() itself.
        if ($this->isEmployeeVerifiedForRun($id, $employeeId)) {
            return [null, 'This employee is verified for this run and cannot be edited. Unverify first.'];
        }
        if (($run['run_purpose'] ?? 'payroll') !== 'incentive') {
            $stmtMember = $this->db->prepare("SELECT 1 FROM `payroll_run_details` WHERE run_id = :run_id AND employee_id = :employee_id");
            $stmtMember->execute([':run_id' => $id, ':employee_id' => $employeeId]);
            if (!$stmtMember->fetch()) {
                return [null, 'This employee is not part of the calculated run yet -- Recalculate first.'];
            }
        }
        return [$run, null];
    }

    /**
     * Resolves one payroll_run_manual_lines row (already LEFT JOINed against
     * payroll_earning_deduction_types) into display fields, whichever of the two sources it came
     * from. $row['ped_type_id'] === null is what tells a custom row apart from a catalog one (the
     * LEFT JOIN then has no matching pt.* columns either way, but ped_type_id itself is the
     * authoritative signal, not "is pt.item_code null" -- belt-and-suspenders in case a future
     * catalog item somehow has a null item_code). The synthetic 'CUSTOM:' code prefix is never
     * shown to a user (every consumer prefers name_th/name_en, which are always set for a custom
     * row) -- it exists only so PayrollRegisterReport's per-code column dictionary groups repeated
     * custom labels together (e.g. two different employees both getting a "ค่าปรับ" custom
     * deduction land in the same report column) without ever colliding with a real catalog
     * item_code (those never contain a colon).
     */
    private function resolveManualLineRow(array $row): array {
        $isCustom = $row['ped_type_id'] === null;
        if ($isCustom) {
            // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7 -- an "Other"
            // item (is_other=1) gets a FIXED sentinel code shared by every employee/record instead
            // of the per-name 'CUSTOM:{name}' every other custom item gets, so PayrollRegisterReport
            // (which groups columns by `code`, see that class's own docblock) and the taxable-income
            // summation in recalculate() both treat every "Other Income"/"Other Deduction" entry as
            // ONE aggregate bucket regardless of what free-text label each admin typed. name_th/
            // name_en stay the admin's own custom_item_name UNCHANGED -- only `code` (the
            // aggregation/report-grouping key) differs; the per-line breakdown UI still shows the
            // specific label ("ค่าปรับผิดสัญญาจ้าง") exactly as before this feature.
            $isOther = !empty($row['is_other']);
            return [
                'code' => $isOther ? ($row['custom_item_type'] === 'deduction' ? 'OTHER_DEDUCTION' : 'OTHER_INCOME') : 'CUSTOM:' . $row['custom_item_name'],
                'name_th' => $row['custom_item_name'],
                'name_en' => $row['custom_item_name'],
                'item_type' => $row['custom_item_type'],
                'is_custom' => true,
                'is_other' => $isOther,
            ];
        }
        return [
            'code' => $row['item_code'],
            'name_th' => $row['item_name_th'],
            'name_en' => $row['item_name_en'],
            'item_type' => $row['item_type'],
            'is_custom' => false,
            'is_other' => false,
        ];
    }

    /**
     * Adds one earning/deduction line for one employee on this run, then recalculates immediately
     * (same as joinEmployees()). For an 'incentive' run this is the only source of pay per explicit
     * request (2026-08-19: "pick item + enter the amount separately per person" -- not one flat
     * amount applied to everyone); for any other run it's an additive one-off adjustment on top of
     * the normal calculation.
     *
     * Two mutually-exclusive ways to specify the item (2026-08-19, explicit request: "ระบุ item ได้
     * เอง ว่าจะจ่ายเพิ่มหรือหักจากอะไร" -- let the admin type their own item too):
     *   - $pedTypeId set: a catalog payroll_earning_deduction_types item (existing behavior).
     *   - $pedTypeId null: a free-text $customItemName + explicit $customItemType('earning'/
     *     'deduction') -- for a genuine one-off that isn't worth creating a standing catalog entry
     *     for. Whichever $customItemName/$customItemType are passed are IGNORED when $pedTypeId is
     *     set (not an error -- the catalog item wins, matching how a frontend toggle between the
     *     two modes would only ever send one side populated anyway).
     */
    public function addManualLine(int $id, int $compId, int $employeeId, ?int $pedTypeId, float $amount, int $userId, bool $isAdmin, ?string $note = null, ?string $customItemName = null, ?string $customItemType = null, ?int $payeeEmployeeId = null, ?string $payeeType = null, ?bool $includeInCashSummary = null, ?array $destinationData = null, ?bool $isOther = null, ?int $bankAccountId = null): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        [$run, $err] = $this->assertManualLinesEditable($id, $compId, $employeeId);
        if ($err !== null) {
            return ['status' => false, 'message' => $err];
        }
        if ($amount <= 0) {
            return ['status' => false, 'message' => 'Amount must be greater than 0.'];
        }
        $note = $note !== null ? trim($note) : '';
        $note = $note !== '' ? $note : null;
        $stmtEmp = $this->db->prepare("SELECT employee_no FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $employeeNo = $stmtEmp->fetchColumn();
        if ($employeeNo === false) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }

        $itemLabel = null;
        $resolvedItemType = null;
        // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7 -- same "Other
        // Income"/"Other Deduction" tag as EmployeeEarningDeductionModel::save()'s own $isOther
        // (see that method's own docblock) -- only meaningful in the custom-item branch below.
        $isOtherFlag = false;
        if ($pedTypeId !== null) {
            // is_sync_only items are meant to be written only by whatever automated flow owns
            // them -- not something an admin hand-picks into an ad-hoc line.
            $stmtPed = $this->db->prepare("SELECT item_code, item_type FROM `payroll_earning_deduction_types`
                WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL AND status = 'active' AND is_sync_only = 0");
            $stmtPed->execute([':id' => $pedTypeId, ':comp_id' => $compId]);
            $pedType = $stmtPed->fetch(PDO::FETCH_ASSOC);
            if (!$pedType) {
                return ['status' => false, 'message' => 'Invalid earning/deduction item.'];
            }
            $itemLabel = $pedType['item_code'];
            $resolvedItemType = $pedType['item_type'];
            $customItemName = null;
            $customItemType = null;
        } else {
            $customItemName = $customItemName !== null ? trim($customItemName) : '';
            $customItemName = $customItemName !== '' ? $customItemName : null;
            if ($customItemName === null) {
                return ['status' => false, 'message' => 'Item name is required.'];
            }
            if (!in_array($customItemType, ['earning', 'deduction'], true)) {
                return ['status' => false, 'message' => 'Invalid item type.'];
            }
            $itemLabel = $customItemName;
            $resolvedItemType = $customItemType;
            $isOtherFlag = (bool)$isOther;
        }

        // Transfer-to-payee (2026-08-21, explicit request: "หักเพื่อไปจ่ายให้ใคร") -- only meaningful
        // on a deduction; silently ignored (not an error) for an earning, same as interest_type
        // being forced to 'none' for earnings elsewhere in this codebase.
        //
        // 2026-08-31, same-day follow-up: widened to the SAME payee_type concept
        // EmployeeEarningDeductionModel::save() already has ('employee'/'company'/'not_disbursed')
        // -- this table never had it at all before this migration (database/migrations/
        // 2026-08-31_15_eed_payee_type_not_disbursed.sql). Backward-compat: a caller sending only
        // $payeeEmployeeId with no $payeeType (every pre-existing call site) is treated as
        // 'employee', same "implicit employee" convention that model already established.
        if ($resolvedItemType !== 'deduction') {
            $payeeEmployeeId = null;
            $payeeType = null;
        } elseif ($payeeType === null && $payeeEmployeeId !== null) {
            $payeeType = 'employee';
        }
        // 2026-09-02, Deduction Destination & Third-Party Remittance -- 'other_person' added to the
        // same payee_type set this table already shares with employee_earning_deductions. See that
        // model's own save() for the identical destination_id resolution pattern.
        if ($payeeType !== null && !in_array($payeeType, ['employee', 'company', 'not_disbursed', 'other_person'], true)) {
            return ['status' => false, 'message' => 'Invalid payee_type.'];
        }
        if ($payeeType !== 'employee') {
            $payeeEmployeeId = null;
        }
        if ($payeeEmployeeId !== null) {
            if ($payeeEmployeeId === $employeeId) {
                return ['status' => false, 'message' => 'An employee cannot be their own transfer payee.'];
            }
            $stmtPayee = $this->db->prepare("SELECT id FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmtPayee->execute([':id' => $payeeEmployeeId, ':comp_id' => $compId]);
            if (!$stmtPayee->fetch()) {
                return ['status' => false, 'message' => 'Invalid payee employee.'];
            }
        }
        $destinationId = null;
        if ($payeeType === 'other_person') {
            require_once __DIR__ . '/PaymentDestinationModel.php';
            $destResult = (new PaymentDestinationModel($this->db))->resolveOrCreate($compId, $destinationData ?? [], $userId);
            if (!$destResult['status']) {
                return ['status' => false, 'message' => $destResult['message'] ?? 'Invalid destination.'];
            }
            $destinationId = $destResult['destination_id'];
        }
        // 2026-09-10, Batch 3B item 3: level-2 for payee_type='company' -- same mandatory-going-
        // forward rule as EmployeeEarningDeductionModel::save()/EmployeeRecurringDeductionModel::save().
        if ($payeeType !== 'company') {
            $bankAccountId = null;
        } elseif ($bankAccountId === null) {
            return ['status' => false, 'message' => 'bank_account_id is required when payee_type is company.'];
        } else {
            $stmtBank = $this->db->prepare("SELECT id FROM `bank_accounts` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL AND status = 'active'");
            $stmtBank->execute([':id' => $bankAccountId, ':comp_id' => $compId]);
            if (!$stmtBank->fetch()) {
                return ['status' => false, 'message' => 'Invalid bank_account_id.'];
            }
        }
        // Same "forced 0 for not_disbursed, otherwise honor the caller (default included)" rule as
        // EmployeeEarningDeductionModel::save()'s own include_in_cash_summary comment.
        $includeInCashSummaryVal = $payeeType === 'not_disbursed' ? 0 : ($includeInCashSummary === false ? 0 : 1);

        $this->db->prepare("INSERT INTO `payroll_run_manual_lines`
                (run_id, employee_id, ped_type_id, custom_item_name, custom_item_type, is_other, amount, note, payee_employee_id, payee_type, destination_id, bank_account_id, include_in_cash_summary, created_by)
            VALUES (:run_id, :employee_id, :ped_type_id, :custom_item_name, :custom_item_type, :is_other, :amount, :note, :payee_employee_id, :payee_type, :destination_id, :bank_account_id, :include_in_cash_summary, :created_by)")
            ->execute([
                ':run_id' => $id, ':employee_id' => $employeeId, ':ped_type_id' => $pedTypeId,
                ':custom_item_name' => $customItemName, ':custom_item_type' => $customItemType, ':is_other' => $isOtherFlag ? 1 : 0,
                ':amount' => $amount, ':note' => $note, ':payee_employee_id' => $payeeEmployeeId,
                ':payee_type' => $payeeType, ':destination_id' => $destinationId, ':bank_account_id' => $bankAccountId, ':include_in_cash_summary' => $includeInCashSummaryVal, ':created_by' => $userId,
            ]);

        // 2026-08-21, explicit request ("ต้องเก็บ Log ว่าใครแก้ไขข้อมูลอะไรไปเมื่อไหร่") -- addManualLine()/
        // removeManualLine() were the only mutating PayrollRunModel methods with no audit trail at
        // all (every other one already calls logAudit()). No dedicated employee_id column on
        // payroll_run_audit_logs, so the affected employee/item/amount go into the existing
        // free-text `note`, same as recalculate()'s own "N employee(s) calculated" note.
        $this->logAudit($id, 'draft', 'draft', 'add_manual_line', $userId,
            "Employee {$employeeNo}: added \"{$itemLabel}\" amount " . number_format($amount, 2) . ($note ? " (note: {$note})" : ''));

        return $this->recalculate($id, $compId, $userId, $isAdmin);
    }

    /**
     * 2026-09-01: shared by mergeSupplementalIntoRun() (Origami-attribution-driven target) and
     * mergeIntoExistingRun() (manually-picked target, explicit request: "ตอนดึงมาทำรอบหรือเพิ่มรอบใหม่
     * ให้มี radio เลือกว่า เปิดรอบใหม่ หรืออ้างอิงถึงรอบ") -- extracted verbatim from
     * mergeSupplementalIntoRun()'s own pre-existing logic so this state machine (is the target
     * usable right now, does it need reverting/reopening first) can never drift between the two
     * entry points. Given a target run id, returns either a usable draft $targetRun (reverting/
     * reopening it first if needed and confirmed) or a structured refusal
     * (needs_reopen_confirmation / needs_revert_confirmation, same contract callers already handle).
     * $mergeContextLabel is purely for the audit-note text on an auto-revert/reopen (e.g. "Reopened
     * to merge {$mergeContextLabel}").
     * @return array{status:bool, message?:string, needs_reopen_confirmation?:bool, needs_revert_confirmation?:bool, target_state?:string, target_run?:array}
     */
    private function resolveMergeTargetRun(int $targetRunId, int $compId, int $userId, bool $isAdmin, bool $allowRevertNonDraftTarget, bool $allowReopenPaidTarget, string $mergeContextLabel): array {
        $targetRun = $this->get($targetRunId, $compId);
        if (!$targetRun) {
            return ['status' => false, 'message' => 'Target run not found.'];
        }
        if ($targetRun['state'] !== 'draft') {
            if ($targetRun['state'] === 'cancelled') {
                return ['status' => false, 'message' => "The target run is already 'cancelled' and cannot be reopened for a merge."];
            }
            // 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย" -- item 2 of this feature's own
            // deferred list, "เปิดรอบเดิมกลับมาคำนวณใหม่" -- reopen the ORIGINAL run back to
            // recalculation, the higher-risk of the two options offered, confirmed via
            // AskUserQuestion): a `paid`/`locked` target is reopened via the SAME reopen() a real
            // admin action on the Detail page already uses (2026-08-29 feature) -- correctly
            // reverses the installment-consumption side effect markPaid() made, respects the
            // company's own reopen_window_days cap, and requires can_finalize_payroll (checked by
            // reopen() itself). NEVER covered by allowRevertNonDraftTarget -- that flag only ever
            // reaches revert()'s own state machine, which has no paid/locked source state at all.
            if (in_array($targetRun['state'], ['paid', 'locked'], true)) {
                if (!$allowReopenPaidTarget) {
                    return ['status' => false, 'needs_reopen_confirmation' => true, 'target_state' => $targetRun['state'],
                        'message' => "The target run is already '{$targetRun['state']}' -- money may have already moved. Merging will REOPEN it (same as the Detail page's own \"Reopen\" action: clears paid/locked/approval status, un-consumes any deduction installments it consumed) back to draft, requiring a fresh recalculate+submit+approve+pay cycle -- retry with allowReopenPaidTarget=true to confirm this."];
                }
                $reopenNote = "Reopened to merge {$mergeContextLabel}";
                $reopenRes = $this->reopen($targetRunId, $compId, $userId, $isAdmin, $reopenNote);
                if (empty($reopenRes['status'])) {
                    return ['status' => false, 'message' => "Could not reopen the target run: " . ($reopenRes['message'] ?? 'unknown error')];
                }
                $targetRun = $this->get($targetRunId, $compId);
                if (!$targetRun || $targetRun['state'] !== 'draft') {
                    return ['status' => false, 'message' => 'Target run did not end up at draft after reopening.'];
                }
            } elseif (!$allowRevertNonDraftTarget) {
                // needs_revert_confirmation=true (not a string-match on the message) lets the
                // caller/UI reliably detect "would need the opt-in flag" without depending on
                // this exact message text ever staying stable or untranslated.
                return ['status' => false, 'needs_revert_confirmation' => true, 'target_state' => $targetRun['state'],
                    'message' => "The target run is already '{$targetRun['state']}'. Merging will REVERT its existing approval decision back to draft -- retry with allowRevertNonDraftTarget=true to confirm this."];
            }
            $revertNote = "Auto-reverted to draft to merge {$mergeContextLabel}";
            $currentState = $targetRun['state'];
            $hops = 0;
            while ($currentState !== 'draft' && $hops < 3) {
                $hops++;
                $revertToState = $currentState === 'pending_approval' ? null : 'pending_approval';
                $revertRes = $this->revert($targetRunId, $compId, $userId, $isAdmin, $revertNote, $revertToState);
                if (empty($revertRes['status'])) {
                    return ['status' => false, 'message' => "Could not auto-revert the target run to draft: " . ($revertRes['message'] ?? 'unknown error')];
                }
                $reloaded = $this->get($targetRunId, $compId);
                if (!$reloaded) {
                    return ['status' => false, 'message' => 'Target run vanished mid-revert.'];
                }
                $currentState = $reloaded['state'];
            }
            if ($currentState !== 'draft') {
                return ['status' => false, 'message' => 'Could not fully revert the target run to draft.'];
            }
            $targetRun = $this->get($targetRunId, $compId);
            // Deliberately NOT wrapped in the same transaction as the merge itself -- each
            // revert()/reopen() call already commits its own -- so if the merge steps further down
            // somehow fail after this point, the target run is left sitting at 'draft' (a
            // perfectly valid, recoverable state -- an admin can just retry the merge, or resubmit
            // as-is if they decide not to), never left half-reverted or corrupted.
        }
        return ['status' => true, 'target_run' => $targetRun];
    }

    /**
     * 2026-08-31, PAYROLL_SYNC_API.md `attribution` revision -- folds a supplemental sync
     * process's own resolved earning/deduction amounts into an EXISTING regular run's own gross
     * pay, for `attribution_tax_treatment='merge'`. See docs/origami-payroll-status-api-guide.md's
     * sibling document (the inbound contract, not the outbound one that file covers) and this
     * feature's own plan for the full design reasoning; summarized:
     *
     * 1. The supplemental process is pulled through the EXACT SAME code path a plain standalone
     *    OT-only/Trip-only supplemental pull already uses (create()+recalculate(), same
     *    run_purpose='incentive'/compute_statutory=0/include_base_salary=0/
     *    include_standing_items=0/include_attendance_pay=1 shape that pull already tests) --
     *    reuses the FULL, already-correct SyncPayResolver/OT-rate-engine resolution with zero
     *    duplicated logic, rather than re-implementing item resolution here.
     * 2. That throwaway run's own resolved earning_breakdown/deduction_breakdown per employee is
     *    copied onto the TARGET run as `payroll_run_manual_lines` rows (insertMergedManualLine()
     *    below -- an is_sync_only-tolerant sibling of the public addManualLine(), since these are
     *    Origami catalog items, not something an admin hand-picked).
     * 3. The throwaway run is then SOFT-deleted the same way the public delete() action deletes
     *    any draft run (status='deleted', never a hard DELETE -- this project's own convention:
     *    "ไม่ hard delete ข้อมูล payroll/master data") -- its payroll_run_details rows are
     *    deliberately LEFT IN PLACE (not cleared) as a queryable record of exactly what amounts
     *    were computed and merged, in case anyone needs to verify the numbers later.
     * 4. `payroll_sync_processes.merged_into_run_id` is set to the TARGET run's id (not the
     *    now-deleted throwaway run) -- this, together with pendingList()'s own WHERE, is what
     *    makes the process disappear from Pending Pull, independent of the throwaway run's own
     *    sync_process_id having been freed back to NULL by the soft-delete step.
     * 5. The target run is recalculated once so its own totals/tax reflect the new manual lines.
     *
     * By default only ever attempts the merge when the target run is still `draft` -- a target
     * that's pending_approval/approved/rejected/need_info/paid/locked is refused with a clear
     * reason instead of silently reverting someone else's already-decided or already-paid run.
     * Refusing here does not touch anything -- the caller can still pull this supplemental process
     * as its own standalone run instead, same as `separate` always could.
     *
     * 2026-08-31, same-day follow-up, explicit request ("ทำทั้ง 3 ข้อเลย" -- covering all 3
     * deliberately-deferred cases from this feature's own plan): `$allowRevertNonDraftTarget`
     * (default false, so every EXISTING caller/test keeps today's safe refusal unchanged) opts
     * into auto-reverting a target that's `pending_approval`/`approved`/`rejected`/`need_info`
     * (decided or in-flight, but NOT YET PAID) back to `draft` first, via the SAME public revert()
     * this run's own Approve page uses -- `approved`/`rejected`/`need_info` need TWO hops
     * (revert()'s own REVERT_TARGET_STATES never allows a direct decided->draft jump; only
     * pending_approval->draft is a single hop), so this loops revert() until state='draft'. This
     * is a REAL, visible undo of whatever decision was already made on the target run -- gated by
     * the exact same approver permission a manual revert would require (revert() enforces this
     * itself, not re-checked here), and the target ends up back at `draft`, requiring a fresh
     * submit()+approve() cycle same as any other draft run. A `paid`/`locked` target is NEVER
     * covered by this flag -- see `$allowReopenPaidTarget` immediately below instead.
     *
     * `$allowReopenPaidTarget` (default false, same "existing caller/test unaffected" shape as
     * `$allowRevertNonDraftTarget` above) covers the higher-risk `paid`/`locked` case specifically
     * -- confirmed via AskUserQuestion: "เปิดรอบเดิมกลับมาคำนวณใหม่" (reopen the ORIGINAL run back to
     * recalculation), not a separate correction-run mechanism. Reuses the SAME public reopen()
     * method the Detail page's own "Reopen" admin action already calls (2026-08-29 feature) --
     * gated by can_finalize_payroll (a stricter permission than this method's own
     * can_process_payroll gate, enforced by reopen() itself), respects the company's own
     * reopen_window_days cap, and correctly reverses markPaid()'s installment-consumption side
     * effect. `cancelled` is NEVER reachable by either flag -- reopen() itself only ever accepts
     * `paid`/`locked` as a source state, and there is no real-world "undo a cancellation to merge
     * into it" scenario this was asked to support.
     *
     * Known, deliberate simplification: an employee present in the supplemental process's own
     * roster but NOT already part of the target run's own `payroll_run_details` (e.g. excluded, or
     * not eligible for that cycle) is skipped and reported in the result's `skipped_employee_ids`,
     * NOT auto-joined into the target run's membership -- joinEmployees() itself refuses to add an
     * arbitrary employee into a pure cycle-based run's automatic-by-employment-date roster (only a
     * previously-EXCLUDED employee can be re-included there), so silently forcing membership here
     * would bypass that run's own eligibility rules. Surfaced as data for the admin to handle
     * manually, never silently dropped.
     */
    public function mergeSupplementalIntoRun(int $supplementalProcessRowId, int $compId, int $userId, bool $isAdmin, bool $allowRevertNonDraftTarget = false, bool $allowReopenPaidTarget = false): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to process payroll.'];
        }
        $stmt = $this->db->prepare("SELECT * FROM `payroll_sync_processes` WHERE id = :id AND comp_id = :comp_id");
        $stmt->execute([':id' => $supplementalProcessRowId, ':comp_id' => $compId]);
        $process = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$process) {
            return ['status' => false, 'message' => 'Sync process not found.'];
        }
        if ($process['run_kind'] !== 'supplemental') {
            return ['status' => false, 'message' => 'Only a supplemental sync process can be merged into another run.'];
        }
        if ($process['attribution_tax_treatment'] !== 'merge') {
            return ['status' => false, 'message' => 'This process is not attributed for merge -- pull it as its own standalone run instead.'];
        }
        if ($process['status'] !== 'pending') {
            return ['status' => false, 'message' => 'This process has already been rejected.'];
        }
        if ($process['merged_into_run_id'] !== null) {
            return ['status' => false, 'message' => 'This process has already been merged into a run.'];
        }
        $stmtLinked = $this->db->prepare("SELECT id FROM `payroll_runs` WHERE sync_process_id = :id");
        $stmtLinked->execute([':id' => $supplementalProcessRowId]);
        if ($stmtLinked->fetch()) {
            return ['status' => false, 'message' => 'This process has already been pulled into its own run.'];
        }
        if ($process['attribution_target_origami_process_id'] === null) {
            return ['status' => false, 'message' => 'No merge target is set on this process.'];
        }

        $stmtTarget = $this->db->prepare("SELECT r.* FROM `payroll_sync_processes` tp
            JOIN `payroll_runs` r ON r.sync_process_id = tp.id
            WHERE tp.origami_process_id = :target_origami_id AND tp.comp_id = :comp_id AND r.deleted_at IS NULL");
        $stmtTarget->execute([':target_origami_id' => $process['attribution_target_origami_process_id'], ':comp_id' => $compId]);
        $targetRun = $stmtTarget->fetch(PDO::FETCH_ASSOC);
        if (!$targetRun) {
            return ['status' => false, 'message' => 'The target regular cycle has not been pulled into a run on our side yet. Pull it first, or pull this supplemental batch as its own standalone run instead.'];
        }
        $targetRunId = (int)$targetRun['id'];
        // 2026-09-01, explicit request: "อ้างอิงถึงรอบ...แต่ต้องรองรับค่าการอ้างอิงที่ส่งมาจาก Origami ไม่ให้
        // ซ้ำซ้อน" -- extracted into resolveMergeTargetRun() (shared with the new, purely manual
        // mergeIntoExistingRun() below) so the exact same "is this target usable, does it need
        // revert/reopen first" state machine is never duplicated/able to drift between the two
        // entry points -- this call site's own behavior is byte-identical to before the extraction.
        $resolvedTarget = $this->resolveMergeTargetRun($targetRunId, $compId, $userId, $isAdmin, $allowRevertNonDraftTarget, $allowReopenPaidTarget, "supplemental process {$process['process_no']}");
        if (empty($resolvedTarget['status'])) {
            return $resolvedTarget;
        }
        $targetRun = $resolvedTarget['target_run'];

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $createRes = $this->create($compId, [
                'sync_process_id' => $supplementalProcessRowId,
                'run_purpose' => 'incentive', 'compute_statutory' => 0, 'include_base_salary' => 0,
                'include_standing_items' => 0, 'include_attendance_pay' => 1,
                'run_name' => 'Merge extraction: ' . $process['process_no'],
                'payment_date' => $targetRun['payment_date'],
            ], $userId, $isAdmin);
        } catch (Throwable $e) {
            if ($own) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Merge failed: ' . $e->getMessage()];
        }
        if (empty($createRes['status'])) {
            if ($own) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Could not resolve supplemental amounts: ' . ($createRes['message'] ?? 'unknown error')];
        }
        $throwawayRunId = (int)$createRes['id'];

        // 2026-09-01: the actual "copy resolved lines onto the target, soft-delete the source,
        // recalculate the target" mechanics (steps 2-5 of this method's own docblock) are now
        // shared with mergeIntoExistingRun() below via performRunMerge() -- byte-identical
        // behavior to before this extraction, verified by tests/payroll_sync_attribution_test.php
        // (unchanged) still passing.
        $mergeRes = $this->performRunMerge($throwawayRunId, $targetRunId, $compId, $userId, $isAdmin, "supplemental process {$process['process_no']}", $supplementalProcessRowId, 'merge_supplemental');
        if (empty($mergeRes['status'])) {
            if ($own) { $this->db->rollBack(); }
            return $mergeRes;
        }
        if ($own) {
            $this->db->commit();
        }
        return $mergeRes;
    }

    /**
     * 2026-09-01, explicit request: "ในการดึงข้อมูลมาทำรอบที่ส่งมาจาก Origami รวมถึงการสร้างเอง ให้สามารถ
     * เลือกอ้างอิงรอบได้เหมือนตอน Origami" -- confirmed via AskUserQuestion: reachable ONLY from the
     * plain "Add" flow (Process List's own standalone create button), never the Pending-Pull/
     * Origami flow (that stays exactly as-is, unchanged, still driven solely by Origami's own
     * attribution -- see mergeSupplementalIntoRun() above, genuinely independent of this method).
     * $sourceRunId is an ordinary off-cycle run the admin already built up normally (Join
     * Employees + Manage Items, same as any other "Add" flow run) -- this folds its resolved
     * earning/deduction breakdown into an EXISTING target run and soft-deletes the source, the
     * exact same mechanics mergeSupplementalIntoRun() already uses (performRunMerge(), shared, not
     * duplicated), just without any Origami/sync-process involvement at either end. Confirmed via
     * AskUserQuestion: $targetRunId may be ANY of this company's own runs regardless of state
     * (draft used directly; pending_approval/approved/rejected/need_info auto-reverted; paid/
     * locked auto-reopened -- both needing the same explicit confirmation flags
     * mergeSupplementalIntoRun() already requires, resolveMergeTargetRun() shared between both).
     */
    public function mergeIntoExistingRun(int $sourceRunId, int $targetRunId, int $compId, int $userId, bool $isAdmin, bool $allowRevertNonDraftTarget = false, bool $allowReopenPaidTarget = false): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to process payroll.'];
        }
        if ($sourceRunId === $targetRunId) {
            return ['status' => false, 'message' => 'A run cannot be merged into itself.'];
        }
        $sourceRun = $this->get($sourceRunId, $compId);
        if (!$sourceRun) {
            return ['status' => false, 'message' => 'Source run not found.'];
        }
        if ($sourceRun['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft run can be merged into another run.'];
        }
        // Genuinely off-cycle/manual only (no cycle_id, no sync_process_id) -- a cycle-linked or
        // sync-pulled run's own membership is automatic-by-date/by-payload, not something this
        // "fold a hand-built extra payment into an existing run" mechanism is meant to consume.
        // Matches the exact same $isOffCycle test used elsewhere in this class (update()/create()).
        if ($sourceRun['cycle_id'] !== null || $sourceRun['sync_process_id'] !== null) {
            return ['status' => false, 'message' => 'Only a genuine off-schedule run (not tied to a Payroll Schedule or an Origami sync process) can be merged into another run this way.'];
        }

        $resolvedTarget = $this->resolveMergeTargetRun($targetRunId, $compId, $userId, $isAdmin, $allowRevertNonDraftTarget, $allowReopenPaidTarget, "run \"{$sourceRun['run_name']}\"");
        if (empty($resolvedTarget['status'])) {
            return $resolvedTarget;
        }

        return $this->performRunMerge($sourceRunId, $targetRunId, $compId, $userId, $isAdmin, "run \"{$sourceRun['run_name']}\"", null, 'merge_run');
    }

    /**
     * 2026-09-01: shared merge mechanics extracted out of mergeSupplementalIntoRun() (steps 2-5 of
     * that method's own docblock) so mergeIntoExistingRun() above reuses the EXACT same "copy
     * resolved lines onto the target, soft-delete the source, recalculate the target" logic rather
     * than a parallel/duplicated copy that could drift. Recalculates $sourceRunId itself first
     * (idempotent/cheap either way) so this works whether the caller already has fresh numbers
     * (mergeSupplementalIntoRun()'s own freshly-created throwaway run) or not (an existing,
     * possibly-stale draft run mergeIntoExistingRun() was handed). $auditNote/$auditAction
     * customize the log entry per caller; $sourceSyncProcessIdToMark is non-null ONLY for the
     * Origami-driven caller (marks that sync process as consumed) -- null here is what makes this
     * safe to call from a purely manual merge with nothing sync-related to update.
     * @return array{status:bool, message?:string, target_run_id?:int, merged_line_count?:int, skipped_employee_ids?:array, recalculate_status?:bool}
     */
    private function performRunMerge(int $sourceRunId, int $targetRunId, int $compId, int $userId, bool $isAdmin, string $auditNote, ?int $sourceSyncProcessIdToMark, string $auditAction): array {
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $calcRes = $this->recalculate($sourceRunId, $compId, $userId, $isAdmin);
            if (empty($calcRes['status'])) {
                if ($own) { $this->db->rollBack(); }
                return ['status' => false, 'message' => 'Could not resolve source amounts: ' . ($calcRes['message'] ?? 'unknown error')];
            }

            $details = $this->getDetails($sourceRunId, $compId);
            $mergedLineCount = 0;
            $skippedEmployeeIds = [];
            $stmtMember = $this->db->prepare("SELECT 1 FROM `payroll_run_details` WHERE run_id = :run_id AND employee_id = :employee_id");
            foreach ($details as $d) {
                $employeeId = (int)$d['employee_id'];
                $stmtMember->execute([':run_id' => $targetRunId, ':employee_id' => $employeeId]);
                if (!$stmtMember->fetch()) {
                    $skippedEmployeeIds[] = $employeeId;
                    continue;
                }
                foreach (($d['earning_breakdown'] ?? []) as $line) {
                    if ((float)($line['amount'] ?? 0) != 0.0) {
                        $this->insertMergedManualLine($targetRunId, $employeeId, $line, 'earning', $compId, $userId);
                        $mergedLineCount++;
                    }
                }
                foreach (($d['deduction_breakdown'] ?? []) as $line) {
                    if ((float)($line['amount'] ?? 0) != 0.0) {
                        $this->insertMergedManualLine($targetRunId, $employeeId, $line, 'deduction', $compId, $userId);
                        $mergedLineCount++;
                    }
                }
            }

            // Soft-delete the source run -- same UPDATE shape the public delete() action uses,
            // never a hard DELETE (this project's own payroll-data convention). payroll_run_details
            // rows are deliberately left as-is (not cleared), a queryable trace of the exact
            // amounts that were merged.
            $this->db->prepare("UPDATE `payroll_runs` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by, sync_process_id = NULL,
                    notes = CONCAT(COALESCE(notes, ''), ' [Merge-extraction run, superseded -- amounts merged into run #', :target_run_id_note, ']')
                WHERE id = :id")
                ->execute([':deleted_by' => $userId, ':target_run_id_note' => $targetRunId, ':id' => $sourceRunId]);

            if ($sourceSyncProcessIdToMark !== null) {
                $this->db->prepare("UPDATE `payroll_sync_processes` SET merged_into_run_id = :run_id WHERE id = :id")
                    ->execute([':run_id' => $targetRunId, ':id' => $sourceSyncProcessIdToMark]);
            }

            $this->logAudit($targetRunId, 'draft', 'draft', $auditAction, $userId,
                "Merged {$auditNote} ({$mergedLineCount} line(s), " . count($skippedEmployeeIds) . ' employee(s) skipped -- not part of this run)');

            if ($own) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Merge failed: ' . $e->getMessage()];
        }

        $finalRecalc = $this->recalculate($targetRunId, $compId, $userId, $isAdmin);
        return [
            'status' => true, 'message' => 'Merged.', 'target_run_id' => $targetRunId,
            'merged_line_count' => $mergedLineCount, 'skipped_employee_ids' => $skippedEmployeeIds,
            'recalculate_status' => $finalRecalc['status'] ?? false,
        ];
    }

    /** Internal sibling of addManualLine() for performRunMerge() above -- same INSERT
     *  shape, but (a) tolerates an `is_sync_only=1` catalog item (this is a system-driven merge of
     *  Origami-sourced items, never an admin hand-picking one), (b) skips the public method's own
     *  permission/state/membership checks (already verified once by the caller for the whole
     *  batch, not per line), and (c) does NOT call recalculate() itself -- mergeSupplementalIntoRun()
     *  recalculates the target run exactly once after every line for this merge has been inserted. */
    private function insertMergedManualLine(int $runId, int $employeeId, array $line, string $itemType, int $compId, int $userId): void {
        $pedTypeId = null;
        $customItemName = null;
        $customItemType = null;
        if (empty($line['is_custom']) && !empty($line['code'])) {
            $stmtPed = $this->db->prepare("SELECT id FROM `payroll_earning_deduction_types` WHERE item_code = :code AND comp_id = :comp_id AND deleted_at IS NULL LIMIT 1");
            $stmtPed->execute([':code' => $line['code'], ':comp_id' => $compId]);
            $foundId = $stmtPed->fetchColumn();
            if ($foundId !== false) {
                $pedTypeId = (int)$foundId;
            }
        }
        if ($pedTypeId === null) {
            $customItemName = (string)($line['name_th'] ?? $line['code'] ?? 'Merged item');
            $customItemType = $itemType;
        }
        $this->db->prepare("INSERT INTO `payroll_run_manual_lines`
                (run_id, employee_id, ped_type_id, custom_item_name, custom_item_type, amount, note, created_by)
            VALUES (:run_id, :employee_id, :ped_type_id, :custom_item_name, :custom_item_type, :amount, :note, :created_by)")
            ->execute([
                ':run_id' => $runId, ':employee_id' => $employeeId, ':ped_type_id' => $pedTypeId,
                ':custom_item_name' => $customItemName, ':custom_item_type' => $customItemType,
                ':amount' => abs((float)($line['amount'] ?? 0)), ':note' => 'Merged from supplemental sync process',
                ':created_by' => $userId,
            ]);
    }

    /** Removes one manually-added line, then recalculates. No employee-membership check needed here
     *  (unlike addManualLine()) -- the line already exists, so its employee was already validated
     *  when it was added; removing it is always safe once the run itself is still draft. */
    public function removeManualLine(int $id, int $compId, int $lineId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $err = $run['state'] !== 'draft' ? 'Only a draft payroll run can have its earning/deduction items adjusted.' : null;
        if ($err !== null) {
            return ['status' => false, 'message' => $err];
        }

        // Fetched BEFORE the delete (2026-08-21, explicit request: audit log needs to say what was
        // removed, which is no longer readable once the row is gone) -- same reasoning as
        // addManualLine()'s new logAudit() call just above this method.
        $stmtLine = $this->db->prepare("SELECT pml.employee_id, pml.amount, pml.custom_item_name, pt.item_code, e.employee_no
            FROM `payroll_run_manual_lines` pml
            LEFT JOIN `payroll_earning_deduction_types` pt ON pt.id = pml.ped_type_id
            JOIN `employees` e ON e.id = pml.employee_id
            WHERE pml.id = :line_id AND pml.run_id = :run_id");
        $stmtLine->execute([':line_id' => $lineId, ':run_id' => $id]);
        $lineInfo = $stmtLine->fetch(PDO::FETCH_ASSOC);
        if (!$lineInfo) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        // 2026-08-29: same lock guard as assertManualLinesEditable() -- see that method's own comment.
        if ($this->isEmployeeVerifiedForRun($id, (int)$lineInfo['employee_id'])) {
            return ['status' => false, 'message' => 'This employee is verified for this run and cannot be edited. Unverify first.'];
        }

        $this->db->prepare("DELETE FROM `payroll_run_manual_lines` WHERE id = :line_id AND run_id = :run_id")
            ->execute([':line_id' => $lineId, ':run_id' => $id]);

        $itemLabel = $lineInfo['item_code'] ?? $lineInfo['custom_item_name'];
        $this->logAudit($id, 'draft', 'draft', 'remove_manual_line', $userId,
            "Employee {$lineInfo['employee_no']}: removed \"{$itemLabel}\" amount " . number_format((float)$lineInfo['amount'], 2));

        return $this->recalculate($id, $compId, $userId, $isAdmin);
    }

    /**
     * Every manual line for one employee on this run (item code/name + amount + note + line id), for
     * the "Manage Items" UI.
     * 2026-09-10, Batch 3A item 5: added created_by/created_at (+ creator name) -- additive, existing
     * callers (Manage Items modal) already ignore unknown keys -- so employeeAdjustments() below can
     * show who added a manual line and when, alongside the line_override "who/when" it already has.
     */
    public function manualLinesForEmployee(int $compId, int $runId, int $employeeId): array {
        $stmt = $this->db->prepare("SELECT pml.id, pml.ped_type_id, pml.amount, pml.note, pml.custom_item_name, pml.custom_item_type, pml.is_other, pml.payee_employee_id,
                pml.payee_type, pml.destination_id, pml.bank_account_id, pml.include_in_cash_summary, pml.created_by, pml.created_at,
                pt.item_code, pt.item_name_th, pt.item_name_en, pt.item_type, payee.employee_no AS payee_employee_no,
                pd.account_name AS destination_account_name,
                ba.account_name AS bank_account_name,
                creator.name_th AS created_by_name_th, creator.name_en AS created_by_name_en
            FROM `payroll_run_manual_lines` pml
            LEFT JOIN `payroll_earning_deduction_types` pt ON pt.id = pml.ped_type_id
            LEFT JOIN `employees` payee ON payee.id = pml.payee_employee_id
            LEFT JOIN `payment_destinations` pd ON pd.id = pml.destination_id
            LEFT JOIN `bank_accounts` ba ON ba.id = pml.bank_account_id
            LEFT JOIN `employees` creator ON creator.id = pml.created_by
            JOIN `payroll_runs` r ON r.id = pml.run_id AND r.comp_id = :comp_id
            WHERE pml.run_id = :run_id AND pml.employee_id = :employee_id
            ORDER BY pml.id ASC");
        $stmt->execute([':comp_id' => $compId, ':run_id' => $runId, ':employee_id' => $employeeId]);
        return array_map(function (array $row): array {
            $resolved = $this->resolveManualLineRow($row);
            return [
                'id' => (int)$row['id'],
                'amount' => (float)$row['amount'],
                'note' => $row['note'],
                'item_code' => $resolved['code'],
                'item_name_th' => $resolved['name_th'],
                'item_name_en' => $resolved['name_en'],
                'item_type' => $resolved['item_type'],
                'is_custom' => $resolved['is_custom'],
                'is_other' => $resolved['is_other'],
                'payee_employee_id' => $row['payee_employee_id'] !== null ? (int)$row['payee_employee_id'] : null,
                'payee_employee_no' => $row['payee_employee_no'],
                'payee_type' => $row['payee_type'],
                'destination_id' => $row['destination_id'] !== null ? (int)$row['destination_id'] : null,
                'destination_account_name' => $row['destination_account_name'],
                'bank_account_id' => $row['bank_account_id'] !== null ? (int)$row['bank_account_id'] : null,
                'bank_account_name' => $row['bank_account_name'],
                'include_in_cash_summary' => (int)$row['include_in_cash_summary'],
                'created_by' => $row['created_by'] !== null ? (int)$row['created_by'] : null,
                'created_by_name_th' => $row['created_by_name_th'],
                'created_by_name_en' => $row['created_by_name_en'],
                'created_at' => $row['created_at'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * 2026-09-10, Batch 3A item 5: combined "what was adjusted for this employee on this run" view,
     * replacing the old fa-sliders icon (which only ever hinted "something changed," with no detail)
     * behind a new "ปรับแล้ว N" badge + view-only modal on the Detail page's employee table. No new
     * table -- built entirely from data this app already has:
     *   - `payroll_run_line_overrides` (current truth for "what's overridden right now," same table
     *     getDetails()'s own line_override_count already counts) drives the override list itself;
     *   - lineOverrideAuditDiff($runId, $compId, $employeeId) (generalized above with the employee
     *     filter, not duplicated) enriches each override with its real original/edit-chain/current
     *     values + who/when, whenever a history row exists (edits made 2026-08-31 forward, see
     *     LINE_OVERRIDE_HISTORY_FEATURE_START_DATE);
     *   - an override with NO history row (edited before that date and never touched since) still
     *     shows up -- 'history_available' => false on that one item -- using the override row's own
     *     created_by/updated_by/created_at/updated_at as a "who/when" fallback, so N always matches
     *     how many items the modal actually lists;
     *   - `payroll_run_manual_lines` (via manualLinesForEmployee(), extended above with
     *     created_by/created_at) supplies the ad-hoc added items -- these have no "old value" at all
     *     (nothing existed before them), so old_value is always null for this group.
     */
    public function employeeAdjustments(int $runId, int $compId, int $employeeId): array {
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['overrides' => [], 'manual_lines' => [], 'history_feature_start_date' => self::LINE_OVERRIDE_HISTORY_FEATURE_START_DATE];
        }

        $stmtOv = $this->db->prepare("SELECT lo.item_code, lo.action, lo.override_amount, lo.note,
                lo.created_by, lo.created_at, lo.updated_by, lo.updated_at,
                creator.name_th AS created_by_name_th, creator.name_en AS created_by_name_en,
                updater.name_th AS updated_by_name_th, updater.name_en AS updated_by_name_en
            FROM `payroll_run_line_overrides` lo
            LEFT JOIN `employees` creator ON creator.id = lo.created_by
            LEFT JOIN `employees` updater ON updater.id = lo.updated_by
            WHERE lo.run_id = :run_id AND lo.employee_id = :employee_id
            ORDER BY lo.id ASC");
        $stmtOv->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        $overrideRows = $stmtOv->fetchAll(PDO::FETCH_ASSOC);

        $diff = $this->lineOverrideAuditDiff($runId, $compId, $employeeId);
        $diffByKey = [];
        foreach ($diff['lines'] as $line) {
            $diffByKey[$line['line_type'] . '|' . $line['item_code']] = $line;
        }

        $overrides = [];
        foreach ($overrideRows as $row) {
            $rawCode = $row['item_code'];
            $statutoryCode = $this->unwrapStatutoryOverrideCode($rawCode);
            $lineType = $statutoryCode !== null ? 'statutory' : 'earning_deduction';
            $displayCode = $statutoryCode ?? $rawCode;
            $diffLine = $diffByKey[$lineType . '|' . $displayCode] ?? null;
            $fallbackCurrent = $row['action'] === 'exclude' ? 0.0 : ($row['override_amount'] !== null ? (float)$row['override_amount'] : null);
            $hasUpdate = $row['updated_by'] !== null;

            $overrides[] = [
                'item_code' => $displayCode,
                'line_type' => $lineType,
                'action' => $row['action'],
                'note' => $row['note'],
                'original_value' => $diffLine['original_value'] ?? null,
                'current_value' => $diffLine['current_value'] ?? $fallbackCurrent,
                'edits' => $diffLine['edits'] ?? [],
                'history_available' => $diffLine !== null,
                'fallback_changed_by' => $hasUpdate ? (int)$row['updated_by'] : ($row['created_by'] !== null ? (int)$row['created_by'] : null),
                'fallback_changed_by_name_th' => $hasUpdate ? $row['updated_by_name_th'] : $row['created_by_name_th'],
                'fallback_changed_by_name_en' => $hasUpdate ? $row['updated_by_name_en'] : $row['created_by_name_en'],
                'fallback_changed_at' => $hasUpdate ? $row['updated_at'] : $row['created_at'],
            ];
        }

        return [
            'overrides' => $overrides,
            'manual_lines' => $this->manualLinesForEmployee($compId, $runId, $employeeId),
            'history_feature_start_date' => self::LINE_OVERRIDE_HISTORY_FEATURE_START_DATE,
        ];
    }

    /**
     * Looks up the CURRENT effective amount for one item_code on one employee's already-calculated
     * row, for lineOverrideSave()'s own "changed from X to Y" audit note (2026-08-29, explicit
     * request: "ในหน้า Log ให้กดดูรายละเอียดส่วนที่ทำรายการไป ว่าใครทำอะไรกับข้อมูล แก้ไขอะไรส่วนไหน ปรับจาก
     * อะไรเป็นอะไร"). Reads the LAST recalculate()'s own persisted earning_breakdown/
     * deduction_breakdown JSON (or base_salary_amount for the reserved base-salary code) rather
     * than re-deriving anything -- this is purely for a human-readable "before" number in the
     * audit trail, not a value anything downstream depends on being exact to the last decimal.
     * Returns null when there's genuinely nothing to compare against yet (e.g. the very first
     * override on a line that only exists because of ANOTHER override, or before this run has ever
     * been calculated at all) -- the caller falls back to omitting the "from" half of the note
     * rather than showing a misleading 0.00.
     */
    /**
     * Unwraps a statutoryOverrideCode()-wrapped item_code back to its real TH_SSO/TH_PVD/etc. code,
     * or returns null when $itemCode isn't statutory-wrapped at all -- single source of truth for
     * the unwrap side, matching statutoryOverrideCode()'s own wrap side 1:1 (used by
     * currentLineAmount() below and employeeAdjustments()'s own override-row unwrapping).
     */
    private function unwrapStatutoryOverrideCode(string $itemCode): ?string {
        if (str_starts_with($itemCode, '__statutory_') && str_ends_with($itemCode, '__')) {
            return substr($itemCode, strlen('__statutory_'), -2);
        }
        return null;
    }

    private function currentLineAmount(int $runId, int $employeeId, string $itemCode): ?float {
        $stmt = $this->db->prepare("SELECT base_salary_amount, earning_breakdown, deduction_breakdown, statutory_breakdown
            FROM `payroll_run_details` WHERE run_id = :run_id AND employee_id = :employee_id");
        $stmt->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if ($itemCode === self::BASE_SALARY_OVERRIDE_CODE) {
            return $row['base_salary_amount'] !== null ? (float)$row['base_salary_amount'] : null;
        }
        // 2026-08-31: statutoryOverrideCode()-wrapped codes (TH_SSO/TH_PVD/TH_PIT/etc.) read from
        // statutory_breakdown's own employee_amount instead -- see that method's own docblock.
        $rawCode = $this->unwrapStatutoryOverrideCode($itemCode);
        if ($rawCode !== null) {
            $lines = $row['statutory_breakdown'] !== null ? json_decode((string)$row['statutory_breakdown'], true) : [];
            foreach ((is_array($lines) ? $lines : []) as $line) {
                if (($line['code'] ?? null) === $rawCode) {
                    return isset($line['employee_amount']) ? (float)$line['employee_amount'] : null;
                }
            }
            return null;
        }
        foreach (['earning_breakdown', 'deduction_breakdown'] as $col) {
            $lines = $row[$col] !== null ? json_decode((string)$row[$col], true) : [];
            foreach ((is_array($lines) ? $lines : []) as $line) {
                if (($line['code'] ?? null) === $itemCode) {
                    return isset($line['amount']) ? (float)$line['amount'] : null;
                }
            }
        }
        return null;
    }

    /**
     * Per-run, per-employee, per-item override/exemption for ANY earning/deduction line this
     * employee's calculation produced -- base salary too, via the reserved
     * self::BASE_SALARY_OVERRIDE_CODE item_code (2026-08-29, generalized from a sync-deduction-only
     * mechanism, explicit request: "ในหน้าทำจ่าย น่าจะเปิดให้แก้ไขตัวเลขได้ ในกรณีที่ระบบคำนวณไม่ตรงนะครับ
     * ทุกค่าเลย แต่ต้องเก็บ Log ไว้เสมอ" -- originally 2026-08-21's own "ปรับค่า สาย ขาดงาน ลาไม่รับเงิน หรือ
     * ยกเว้นไม่ให้หัก" was scoped to a sync-COMPUTED deduction line on a sync-based run only; the
     * `sync_process_id === null` guard that enforced that scope is gone, and recalculate() now
     * applies this same override table to every earning + deduction line regardless of source --
     * see that method's own "generalized from a sync-deduction-only mechanism" comment). Upserted
     * by (run_id, employee_id, item_code), same shape as AttendanceDeductionRuleModel::ruleSave()'s
     * select-then-update-or-insert. Deliberately scoped to THIS run only (confirmed choice, not a
     * standing per-employee setting) -- applying the same rule to every future run would need a
     * different, employee-level mechanism, not this one.
     */
    /**
     * 2026-08-31, same-day follow-up (item 10): one append-only row per actual edit into
     * payroll_run_line_override_history, feeding the new "Payroll Run Audit" report's Original ->
     * Edit 1 -> Edit 2 -> ... -> Current diff chain (see that table's own migration comment for why
     * this can only capture edits made from today forward). $itemCode here is always the
     * human-readable code (bare TH_SSO/TH_PVD/etc. for statutory, the real earning/deduction
     * item_code, or one of ATTENDANCE_OVERRIDE_FIELDS for attendance) -- NEVER the
     * statutoryOverrideCode()-wrapped sentinel, which exists only to keep the OVERRIDE table's own
     * key space collision-free and has no reason to leak into a human-facing report.
     */
    private function recordLineOverrideHistory(int $runId, int $employeeId, string $lineType, string $itemCode, string $action, ?float $oldValue, ?float $newValue, int $userId, ?string $note = null): void {
        $this->db->prepare("INSERT INTO `payroll_run_line_override_history`
                (run_id, employee_id, line_type, item_code, action, old_value, new_value, note, changed_by)
            VALUES (:run_id, :employee_id, :line_type, :item_code, :action, :old_value, :new_value, :note, :changed_by)")
            ->execute([
                ':run_id' => $runId, ':employee_id' => $employeeId, ':line_type' => $lineType,
                ':item_code' => $itemCode, ':action' => $action,
                ':old_value' => $oldValue, ':new_value' => $newValue,
                ':note' => $note, ':changed_by' => $userId,
            ]);
    }

    public function lineOverrideSave(int $runId, int $compId, int $employeeId, string $itemCode, string $action, ?float $overrideAmount, ?string $note, int $userId, bool $isAdmin, string $historyLineType = 'earning_deduction', ?string $historyItemCode = null): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run can have its earning/deduction items adjusted.'];
        }
        if ($this->isEmployeeVerifiedForRun($runId, $employeeId)) {
            return ['status' => false, 'message' => 'This employee is verified for this run and cannot be edited. Unverify first.'];
        }
        if (!in_array($action, ['override_amount', 'exclude'], true)) {
            return ['status' => false, 'message' => 'Invalid action.'];
        }
        if ($action === 'override_amount' && ($overrideAmount === null || $overrideAmount < 0)) {
            return ['status' => false, 'message' => 'override_amount must be zero or greater.'];
        }
        if ($action === 'exclude') {
            $overrideAmount = null;
        }
        $itemCode = trim($itemCode);
        if ($itemCode === '') {
            return ['status' => false, 'message' => 'Invalid item_code.'];
        }
        $note = $note !== null ? trim($note) : '';
        $note = $note !== '' ? $note : null;

        $stmtEmp = $this->db->prepare("SELECT employee_no FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $employeeNo = $stmtEmp->fetchColumn();
        if ($employeeNo === false) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }

        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }

            $stmtExisting = $this->db->prepare("SELECT id FROM `payroll_run_line_overrides` WHERE run_id = :run_id AND employee_id = :employee_id AND item_code = :item_code");
            $stmtExisting->execute([':run_id' => $runId, ':employee_id' => $employeeId, ':item_code' => $itemCode]);
            $existingId = $stmtExisting->fetchColumn();

            if ($existingId) {
                $this->db->prepare("UPDATE `payroll_run_line_overrides` SET action = :action, override_amount = :override_amount,
                        note = :note, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id")
                    ->execute([
                        ':action' => $action, ':override_amount' => $overrideAmount, ':note' => $note,
                        ':updated_by' => $userId, ':id' => $existingId,
                    ]);
            } else {
                $this->db->prepare("INSERT INTO `payroll_run_line_overrides` (run_id, employee_id, item_code, action, override_amount, note, created_by)
                    VALUES (:run_id, :employee_id, :item_code, :action, :override_amount, :note, :created_by)")
                    ->execute([
                        ':run_id' => $runId, ':employee_id' => $employeeId, ':item_code' => $itemCode,
                        ':action' => $action, ':override_amount' => $overrideAmount, ':note' => $note, ':created_by' => $userId,
                    ]);
            }

            // "ปรับจากอะไรเป็นอะไร" (changed from what to what) -- looked up BEFORE this override was
            // written to the DB above would have been more "pure", but the override row itself
            // doesn't feed back into currentLineAmount() (that reads payroll_run_details, a
            // separate table only recalculate() writes), so reading it after is equally correct
            // and avoids holding the value in a variable across the whole transaction.
            $beforeAmount = $this->currentLineAmount($runId, $employeeId, $itemCode);
            $beforeDesc = $beforeAmount !== null ? number_format($beforeAmount, 2) : 'n/a';
            $actionDesc = $action === 'exclude' ? 'excluded' : ('override amount ' . number_format($overrideAmount, 2));
            $this->logAudit($runId, 'draft', 'draft', 'line_override_save', $userId,
                "Employee {$employeeNo}: {$itemCode} changed from {$beforeDesc} -> {$actionDesc}" . ($note ? " (note: {$note})" : ''));
            $this->recordLineOverrideHistory($runId, $employeeId, $historyLineType, $historyItemCode ?? $itemCode,
                $action === 'exclude' ? 'exclude' : 'override', $beforeAmount, $action === 'exclude' ? 0.0 : $overrideAmount, $userId, $note);

            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }

        return $this->recalculate($runId, $compId, $userId, $isAdmin);
    }

    /** Removes a line override (reverts that item back to its computed default), then recalculates. */
    public function lineOverrideRemove(int $runId, int $compId, int $employeeId, string $itemCode, int $userId, bool $isAdmin, string $historyLineType = 'earning_deduction', ?string $historyItemCode = null): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run can have its earning/deduction items adjusted.'];
        }

        $stmtEmp = $this->db->prepare("SELECT employee_no FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $employeeNo = $stmtEmp->fetchColumn();

        // 2026-08-31, same-day follow-up (item 10): captured BEFORE the delete so the history row's
        // old_value reflects what the override actually was, not "gone" -- exclude has no persisted
        // amount of its own (see lineOverrideSave()'s own "exclude -> null override_amount" rule),
        // so its old_value is represented as 0.00, same convention lineOverrideSave() itself uses.
        $stmtOv = $this->db->prepare("SELECT action, override_amount FROM `payroll_run_line_overrides` WHERE run_id = :run_id AND employee_id = :employee_id AND item_code = :item_code");
        $stmtOv->execute([':run_id' => $runId, ':employee_id' => $employeeId, ':item_code' => $itemCode]);
        $existingOv = $stmtOv->fetch(PDO::FETCH_ASSOC);
        $oldValue = $existingOv ? ($existingOv['action'] === 'exclude' ? 0.0 : ((float)$existingOv['override_amount'])) : null;

        $this->db->prepare("DELETE FROM `payroll_run_line_overrides` WHERE run_id = :run_id AND employee_id = :employee_id AND item_code = :item_code")
            ->execute([':run_id' => $runId, ':employee_id' => $employeeId, ':item_code' => $itemCode]);

        $this->logAudit($runId, 'draft', 'draft', 'line_override_remove', $userId,
            "Employee " . ($employeeNo !== false ? $employeeNo : $employeeId) . ": {$itemCode} reverted to computed default");

        $result = $this->recalculate($runId, $compId, $userId, $isAdmin);
        // Read back AFTER recalculate() so new_value reflects the actually-reverted computed figure,
        // not a guess -- same "read after write" reasoning lineOverrideSave()'s own docblock uses.
        $newValue = $this->currentLineAmount($runId, $employeeId, $itemCode);
        $this->recordLineOverrideHistory($runId, $employeeId, $historyLineType, $historyItemCode ?? $itemCode, 'restore', $oldValue, $newValue, $userId);

        return $result;
    }

    /**
     * 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย" -- item 9a): thin wrappers around
     * lineOverrideSave()/lineOverrideRemove() that wrap/unwrap a statutory item code (TH_SSO/
     * TH_PVD/TH_PIT/etc.) through statutoryOverrideCode() -- reuses 100% of the existing
     * validation/DB/audit logic those methods already have (same table, same recalculate()
     * trigger), only the item_code differs. See recalculate()'s own statutory block for where
     * this is actually applied during calculation.
     */
    public function statutoryLineOverrideSave(int $runId, int $compId, int $employeeId, string $statutoryItemCode, string $action, ?float $overrideAmount, ?string $note, int $userId, bool $isAdmin): array {
        return $this->lineOverrideSave($runId, $compId, $employeeId, $this->statutoryOverrideCode($statutoryItemCode), $action, $overrideAmount, $note, $userId, $isAdmin, 'statutory', $statutoryItemCode);
    }

    public function statutoryLineOverrideRemove(int $runId, int $compId, int $employeeId, string $statutoryItemCode, int $userId, bool $isAdmin): array {
        return $this->lineOverrideRemove($runId, $compId, $employeeId, $this->statutoryOverrideCode($statutoryItemCode), $userId, $isAdmin, 'statutory', $statutoryItemCode);
    }

    /**
     * 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 6 -- read-only, for the
     * "Recurring Deduction Destination" section of the Manage Items modal: one row per recurring
     * deduction active for this employee in this run's own pay period
     * (EmployeeRecurringDeductionModel::activeForPeriod()), each carrying both the TEMPLATE's own
     * default payee (from employee_recurring_deductions, unaffected by anything below) and this
     * run's own override (if one exists) so the UI can show "currently routed to X (overridden from
     * the template's own Y)" without a second round trip.
     */
    public function recurringDeductionDestinationsForEmployee(int $runId, int $compId, int $employeeId): array {
        $run = $this->get($runId, $compId);
        if (!$run) {
            return [];
        }
        $recRows = $this->recurringDeductionModel->activeForPeriod($employeeId, $run['period_start_date'], $run['period_end_date'], $compId);
        if (empty($recRows)) {
            return [];
        }
        $recurringIds = array_map(static fn($r) => (int)$r['recurring_id'], $recRows);
        $placeholders = implode(',', array_fill(0, count($recurringIds), '?'));
        $stmtOv = $this->db->prepare("SELECT * FROM `payroll_run_recurring_deduction_overrides` WHERE run_id = ? AND recurring_id IN ({$placeholders})");
        $stmtOv->execute(array_merge([$runId], $recurringIds));
        $overridesByRecurringId = [];
        foreach ($stmtOv->fetchAll(PDO::FETCH_ASSOC) as $ov) {
            $overridesByRecurringId[(int)$ov['recurring_id']] = $ov;
        }

        $destIds = [];
        $payeeEmpIds = [];
        $bankAccountIds = [];
        foreach ($recRows as $r) {
            if (!empty($r['destination_id'])) { $destIds[] = (int)$r['destination_id']; }
            if (!empty($r['payee_employee_id'])) { $payeeEmpIds[] = (int)$r['payee_employee_id']; }
            if (!empty($r['bank_account_id'])) { $bankAccountIds[] = (int)$r['bank_account_id']; }
        }
        foreach ($overridesByRecurringId as $ov) {
            if (!empty($ov['destination_id'])) { $destIds[] = (int)$ov['destination_id']; }
            if (!empty($ov['payee_employee_id'])) { $payeeEmpIds[] = (int)$ov['payee_employee_id']; }
            if (!empty($ov['bank_account_id'])) { $bankAccountIds[] = (int)$ov['bank_account_id']; }
        }
        $destLabels = [];
        if (!empty($destIds)) {
            $destIds = array_values(array_unique($destIds));
            $ph = implode(',', array_fill(0, count($destIds), '?'));
            $stmtDest = $this->db->prepare("SELECT pd.id, pd.account_name, mb.bank_name_th, mb.bank_name_en
                FROM `payment_destinations` pd LEFT JOIN `master_banks` mb ON mb.id = pd.bank_id WHERE pd.id IN ({$ph})");
            $stmtDest->execute($destIds);
            foreach ($stmtDest->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $destLabels[(int)$d['id']] = trim(($d['account_name'] ?? '') . ($d['bank_name_th'] ? ' - ' . $d['bank_name_th'] : ''));
            }
        }
        // 2026-09-10, Batch 3B item 3: same label-lookup pattern as $destLabels above, for the new
        // 'company' level-2 (WHICH of the company's own bank_accounts).
        $bankAccountLabels = [];
        if (!empty($bankAccountIds)) {
            $bankAccountIds = array_values(array_unique($bankAccountIds));
            $ph3 = implode(',', array_fill(0, count($bankAccountIds), '?'));
            $stmtBa = $this->db->prepare("SELECT id, account_name FROM `bank_accounts` WHERE id IN ({$ph3})");
            $stmtBa->execute($bankAccountIds);
            foreach ($stmtBa->fetchAll(PDO::FETCH_ASSOC) as $ba) {
                $bankAccountLabels[(int)$ba['id']] = $ba['account_name'];
            }
        }
        $payeeLabels = [];
        if (!empty($payeeEmpIds)) {
            $payeeEmpIds = array_values(array_unique($payeeEmpIds));
            $ph2 = implode(',', array_fill(0, count($payeeEmpIds), '?'));
            $stmtEmp = $this->db->prepare("SELECT id, employee_no, name_th, surname_th FROM `employees` WHERE id IN ({$ph2})");
            $stmtEmp->execute($payeeEmpIds);
            foreach ($stmtEmp->fetchAll(PDO::FETCH_ASSOC) as $e) {
                $payeeLabels[(int)$e['id']] = trim(($e['name_th'] ?? '') . ' ' . ($e['surname_th'] ?? '')) . ' (' . $e['employee_no'] . ')';
            }
        }

        $result = [];
        foreach ($recRows as $r) {
            $recurringId = (int)$r['recurring_id'];
            $override = $overridesByRecurringId[$recurringId] ?? null;
            $templateDestId = $r['destination_id'] !== null ? (int)$r['destination_id'] : null;
            $templatePayeeEmpId = $r['payee_employee_id'] !== null ? (int)$r['payee_employee_id'] : null;
            $templateBankAccountId = !empty($r['bank_account_id']) ? (int)$r['bank_account_id'] : null;
            $result[] = [
                'recurring_id' => $recurringId,
                'item_code' => $r['item_code'], 'item_name_th' => $r['item_name_th'], 'item_name_en' => $r['item_name_en'],
                'template_payee_type' => $r['payee_type'],
                'template_payee_employee_id' => $templatePayeeEmpId,
                'template_payee_label' => $templatePayeeEmpId !== null ? ($payeeLabels[$templatePayeeEmpId] ?? null) : null,
                'template_destination_id' => $templateDestId,
                'template_destination_label' => $templateDestId !== null ? ($destLabels[$templateDestId] ?? null) : null,
                'template_bank_account_id' => $templateBankAccountId,
                'template_bank_account_label' => $templateBankAccountId !== null ? ($bankAccountLabels[$templateBankAccountId] ?? null) : null,
                'override' => $override ? [
                    'payee_type' => $override['payee_type'],
                    'payee_employee_id' => $override['payee_employee_id'] !== null ? (int)$override['payee_employee_id'] : null,
                    'payee_label' => $override['payee_employee_id'] !== null ? ($payeeLabels[(int)$override['payee_employee_id']] ?? null) : null,
                    'destination_id' => $override['destination_id'] !== null ? (int)$override['destination_id'] : null,
                    'destination_label' => $override['destination_id'] !== null ? ($destLabels[(int)$override['destination_id']] ?? null) : null,
                    'bank_account_id' => !empty($override['bank_account_id']) ? (int)$override['bank_account_id'] : null,
                    'bank_account_label' => !empty($override['bank_account_id']) ? ($bankAccountLabels[(int)$override['bank_account_id']] ?? null) : null,
                    'note' => $override['note'],
                ] : null,
            ];
        }
        return $result;
    }

    /**
     * Saves (creates or updates) this run's own override of one recurring deduction's payee --
     * mirrors lineOverrideSave()'s own gate/permission/state pattern, but the destination-resolve
     * step reuses PaymentDestinationModel exactly like EmployeeEarningDeductionModel::save()/
     * EmployeeRecurringDeductionModel::save() already do for 'other_person'. The TEMPLATE row
     * (employee_recurring_deductions) is never written to by this method.
     */
    public function recurringDeductionDestinationOverrideSave(int $runId, int $compId, int $recurringId, array $data, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run can have its earning/deduction items adjusted.'];
        }
        $stmtRec = $this->db->prepare("SELECT erd.employee_id, e.employee_no FROM `employee_recurring_deductions` erd
            JOIN `employees` e ON e.id = erd.employee_id
            WHERE erd.id = :id AND e.comp_id = :comp_id AND erd.deleted_at IS NULL");
        $stmtRec->execute([':id' => $recurringId, ':comp_id' => $compId]);
        $rec = $stmtRec->fetch(PDO::FETCH_ASSOC);
        if (!$rec) {
            return ['status' => false, 'message' => 'Recurring deduction not found.'];
        }
        $employeeId = (int)$rec['employee_id'];
        if ($this->isEmployeeVerifiedForRun($runId, $employeeId)) {
            return ['status' => false, 'message' => 'This employee is verified for this run and cannot be edited. Unverify first.'];
        }

        $payeeType = (string)($data['payee_type'] ?? '');
        if (!in_array($payeeType, ['employee', 'company', 'not_disbursed', 'other_person'], true)) {
            return ['status' => false, 'message' => 'Invalid payee_type.'];
        }
        $payeeEmployeeId = null;
        $destinationId = null;
        $bankAccountId = null;
        if ($payeeType === 'employee') {
            if (empty($data['payee_employee_id'])) {
                return ['status' => false, 'message' => 'payee_employee_id is required when payee_type is employee.'];
            }
            $payeeEmployeeId = (int)$data['payee_employee_id'];
            if ($payeeEmployeeId === $employeeId) {
                return ['status' => false, 'message' => 'An employee cannot be their own transfer payee.'];
            }
            $stmtPayee = $this->db->prepare("SELECT id FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmtPayee->execute([':id' => $payeeEmployeeId, ':comp_id' => $compId]);
            if (!$stmtPayee->fetch()) {
                return ['status' => false, 'message' => 'Invalid payee employee.'];
            }
        } elseif ($payeeType === 'company') {
            // 2026-09-10, Batch 3B item 3: same level-2 as EmployeeEarningDeductionModel::save()/
            // EmployeeRecurringDeductionModel::save() -- mandatory when choosing 'company' here too,
            // since this override is itself a fresh choice being made right now (not legacy data).
            if (empty($data['bank_account_id'])) {
                return ['status' => false, 'message' => 'bank_account_id is required when payee_type is company.'];
            }
            $bankAccountId = (int)$data['bank_account_id'];
            $stmtBank = $this->db->prepare("SELECT id FROM `bank_accounts` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL AND status = 'active'");
            $stmtBank->execute([':id' => $bankAccountId, ':comp_id' => $compId]);
            if (!$stmtBank->fetch()) {
                return ['status' => false, 'message' => 'Invalid bank_account_id.'];
            }
        } elseif ($payeeType === 'other_person') {
            require_once __DIR__ . '/PaymentDestinationModel.php';
            $destResult = (new PaymentDestinationModel($this->db))->resolveOrCreate($compId, $data, $userId);
            if (!$destResult['status']) {
                return ['status' => false, 'message' => $destResult['message'] ?? 'Invalid destination.'];
            }
            $destinationId = $destResult['destination_id'];
        }
        $note = !empty($data['note']) ? trim((string)$data['note']) : null;

        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            $stmtExisting = $this->db->prepare("SELECT id FROM `payroll_run_recurring_deduction_overrides` WHERE run_id = :run_id AND recurring_id = :recurring_id");
            $stmtExisting->execute([':run_id' => $runId, ':recurring_id' => $recurringId]);
            $existingId = $stmtExisting->fetchColumn();
            if ($existingId) {
                $this->db->prepare("UPDATE `payroll_run_recurring_deduction_overrides` SET
                        payee_type = :payee_type, payee_employee_id = :payee_employee_id, destination_id = :destination_id, bank_account_id = :bank_account_id, note = :note,
                        updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id")
                    ->execute([
                        ':payee_type' => $payeeType, ':payee_employee_id' => $payeeEmployeeId, ':destination_id' => $destinationId, ':bank_account_id' => $bankAccountId, ':note' => $note,
                        ':updated_by' => $userId, ':id' => $existingId,
                    ]);
            } else {
                $this->db->prepare("INSERT INTO `payroll_run_recurring_deduction_overrides`
                        (run_id, recurring_id, payee_type, payee_employee_id, destination_id, bank_account_id, note, created_by)
                    VALUES (:run_id, :recurring_id, :payee_type, :payee_employee_id, :destination_id, :bank_account_id, :note, :created_by)")
                    ->execute([
                        ':run_id' => $runId, ':recurring_id' => $recurringId, ':payee_type' => $payeeType,
                        ':payee_employee_id' => $payeeEmployeeId, ':destination_id' => $destinationId, ':bank_account_id' => $bankAccountId, ':note' => $note, ':created_by' => $userId,
                    ]);
            }
            $this->logAudit($runId, 'draft', 'draft', 'recurring_deduction_destination_override_save', $userId,
                "Employee {$rec['employee_no']}: recurring deduction #{$recurringId} destination overridden to {$payeeType} for this run only");
            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }

        return $this->recalculate($runId, $compId, $userId, $isAdmin);
    }

    /** Removes this run's own override, reverting that recurring deduction back to its template's
     *  own default payee for this run only -- the template itself was never touched either way. */
    public function recurringDeductionDestinationOverrideRemove(int $runId, int $compId, int $recurringId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run can have its earning/deduction items adjusted.'];
        }
        $stmtRec = $this->db->prepare("SELECT erd.employee_id, e.employee_no FROM `employee_recurring_deductions` erd
            JOIN `employees` e ON e.id = erd.employee_id
            WHERE erd.id = :id AND e.comp_id = :comp_id AND erd.deleted_at IS NULL");
        $stmtRec->execute([':id' => $recurringId, ':comp_id' => $compId]);
        $rec = $stmtRec->fetch(PDO::FETCH_ASSOC);
        if (!$rec) {
            return ['status' => false, 'message' => 'Recurring deduction not found.'];
        }

        $this->db->prepare("DELETE FROM `payroll_run_recurring_deduction_overrides` WHERE run_id = :run_id AND recurring_id = :recurring_id")
            ->execute([':run_id' => $runId, ':recurring_id' => $recurringId]);
        $this->logAudit($runId, 'draft', 'draft', 'recurring_deduction_destination_override_remove', $userId,
            "Employee {$rec['employee_no']}: recurring deduction #{$recurringId} destination reverted to the template's own default for this run");

        return $this->recalculate($runId, $compId, $userId, $isAdmin);
    }

    /**
     * Read-only, for the "Adjust Amounts" section of the Manage Items modal. 2026-08-29,
     * generalized from a sync-computed-deduction-lines-only listing (explicit request: "ในหน้าทำจ่าย
     * น่าจะเปิดให้แก้ไขตัวเลขได้ ในกรณีที่ระบบคำนวณไม่ตรงนะครับ ทุกค่าเลย") into every earning + deduction
     * line this employee's LAST recalculate() actually produced, for ANY run (not just a sync-based
     * one), plus a synthetic base-salary row (self::BASE_SALARY_OVERRIDE_CODE) at the top.
     *
     * `current_amount` reads straight from the persisted payroll_run_details breakdown -- i.e. it
     * is the value AFTER any already-active override, not a bypass-override "what the system would
     * compute fresh" figure the way the old sync-only version's `computed_amount` was. That's a
     * deliberate simplification: re-deriving every line from scratch (manual lines + standing PED +
     * recurring earnings + sync-derived, all without overrides) would mean duplicating a large slice
     * of recalculate()'s own per-employee assembly logic outside it, a real duplication-drift risk
     * for a fairly minor convenience. The "what did this used to be before I changed it" question
     * this trades away is still fully answered by the audit trail instead -- every lineOverrideSave()
     * call logs a "changed from X to Y" note (see that method's own docblock), viewable via the
     * Action History tab's own View Detail button.
     */
    public function syncDeductionLinesForEmployee(int $compId, int $runId, int $employeeId): array {
        $run = $this->get($runId, $compId);
        if (!$run) {
            return [];
        }
        $stmtDetail = $this->db->prepare("SELECT base_salary_amount, earning_breakdown, deduction_breakdown, statutory_breakdown
            FROM `payroll_run_details` WHERE run_id = :run_id AND employee_id = :employee_id");
        $stmtDetail->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        $detail = $stmtDetail->fetch(PDO::FETCH_ASSOC);
        if (!$detail) {
            return [];
        }

        $stmtOv = $this->db->prepare("SELECT item_code, action, override_amount, note FROM `payroll_run_line_overrides` WHERE run_id = :run_id AND employee_id = :employee_id");
        $stmtOv->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        $overrides = [];
        foreach ($stmtOv->fetchAll(PDO::FETCH_ASSOC) as $ov) {
            $overrides[$ov['item_code']] = $ov;
        }
        $attachOverride = function (array $row) use ($overrides): array {
            $ov = $overrides[$row['code']] ?? null;
            $row['override_action'] = $ov['action'] ?? null;
            $row['override_amount'] = $ov['override_amount'] !== null ? (float)$ov['override_amount'] : null;
            $row['override_note'] = $ov['note'] ?? null;
            return $row;
        };
        // 2026-08-31, same-day follow-up (item 9a): same attachOverride() shape, but keyed by the
        // WRAPPED statutoryOverrideCode() (this row's own bare 'code' stays the real TH_SSO/TH_PVD/
        // TH_PIT/etc. for display -- only the override lookup key differs).
        $attachStatutoryOverride = function (array $row) use ($overrides): array {
            $ov = $overrides[$this->statutoryOverrideCode($row['code'])] ?? null;
            $row['override_action'] = $ov['action'] ?? null;
            $row['override_amount'] = $ov['override_amount'] !== null ? (float)$ov['override_amount'] : null;
            $row['override_note'] = $ov['note'] ?? null;
            return $row;
        };

        $seenCodes = [self::BASE_SALARY_OVERRIDE_CODE => true];
        $rows = [$attachOverride([
            'code' => self::BASE_SALARY_OVERRIDE_CODE,
            'name_th' => 'เงินเดือนพื้นฐาน', 'name_en' => 'Base Salary',
            'current_amount' => $detail['base_salary_amount'] !== null ? (float)$detail['base_salary_amount'] : 0.0,
            // 2026-08-31, same-day follow-up (item 9a): lets the frontend route Save/Reset to the
            // right endpoint (api/payroll-run.line-override.* vs .statutory-line-override.*)
            // without having to pattern-match item codes client-side.
            'line_type' => 'earning_deduction',
        ])];
        foreach (['earning_breakdown', 'deduction_breakdown'] as $col) {
            $lines = $detail[$col] !== null ? json_decode((string)$detail[$col], true) : [];
            foreach ((is_array($lines) ? $lines : []) as $line) {
                if (empty($line['code'])) {
                    continue; // a line with no code has nothing lineOverrideSave() could ever target
                }
                $seenCodes[$line['code']] = true;
                $rows[] = $attachOverride([
                    'code' => $line['code'],
                    'name_th' => $line['name_th'] ?? $line['code'],
                    'name_en' => $line['name_en'] ?? $line['code'],
                    'current_amount' => isset($line['amount']) ? (float)$line['amount'] : 0.0,
                    'line_type' => 'earning_deduction',
                    // 2026-08-31: SyncPayResolver's own raw Origami item_code, when this line came
                    // through the generic item_values loop -- see that method's own comment on why
                    // this can genuinely differ from 'code' above (the CUSTOM: fallback especially).
                    // Absent for base salary/manual/attendance-derived (OT/trip/etc) lines, which
                    // never set it -- the occurrence-enrichment step below falls back to 'code' then.
                    'sync_item_code' => $line['sync_item_code'] ?? null,
                ]);
            }
        }
        // 2026-08-31, same-day follow-up (item 9a): statutory rows, same shape as earning/deduction
        // above but sourced from statutory_breakdown/employee_amount. Unlike an earning/deduction
        // 'exclude' (which drops the line from the breakdown entirely, see the fallback loop below),
        // an excluded STATUTORY line stays IN statutory_breakdown at employee_amount=0 (see
        // recalculate()'s own statutory-override block) -- so this loop alone always finds it, no
        // separate "dropped line" fallback needed for statutory codes.
        $lines = $detail['statutory_breakdown'] !== null ? json_decode((string)$detail['statutory_breakdown'], true) : [];
        foreach ((is_array($lines) ? $lines : []) as $line) {
            if (empty($line['code'])) {
                continue;
            }
            $seenCodes[$this->statutoryOverrideCode($line['code'])] = true;
            $rows[] = $attachStatutoryOverride([
                'code' => $line['code'],
                'name_th' => $line['name_th'] ?? $line['code'],
                'name_en' => $line['name_en'] ?? $line['code'],
                'current_amount' => isset($line['employee_amount']) ? (float)$line['employee_amount'] : 0.0,
                'line_type' => 'statutory',
            ]);
        }
        // An 'exclude' override drops its line out of the persisted breakdown entirely (that's the
        // whole point of excluding it), so the loops above never see it -- but the UI still needs to
        // list it (with its override_action/note intact) so a "Reset" action remains reachable to
        // un-exclude it. current_amount is 0 for these (there's no persisted "what it would be"
        // figure to show once excluded, same simplification this method's own docblock already
        // documents for the override_amount case). Statutory codes are deliberately EXCLUDED from
        // this fallback (seenCodes above is keyed by the WRAPPED code for them, always populated by
        // the statutory loop just above regardless of exclude state) -- letting a wrapped
        // '__statutory_..__' code fall through here would create a garbled duplicate row.
        foreach ($overrides as $code => $ov) {
            if (isset($seenCodes[$code]) || $ov['action'] !== 'exclude' || (str_starts_with($code, '__statutory_') && str_ends_with($code, '__'))) {
                continue;
            }
            $rows[] = $attachOverride(['code' => $code, 'name_th' => $code, 'name_en' => $code, 'current_amount' => 0.0, 'line_type' => 'earning_deduction']);
        }

        // 2026-08-31, same-day follow-up (Origami's `scheduled_item_occurrences[]` proposal, per-
        // installment breakdown of an Employee Item, e.g. "LOAN installment 2 of 12") -- attaches an
        // `occurrences` sub-array to a line so the Adjust Amounts modal can show the breakdown
        // alongside the summed total it already displays. Only meaningful for a run pulled from an
        // Origami sync process (occurrences belong to that PROCESS, not to this run itself, see
        // PayrollSyncModel::occurrencesForItem()'s own docblock) and only for real earning/deduction
        // catalog lines -- base salary and statutory items have no "Employee Item schedule" concept
        // for Origami to have sent a breakdown of in the first place. Matches on `sync_item_code`
        // (SyncPayResolver's own preserved RAW Origami item_code) when present, falling back to
        // `code` otherwise -- real bug found and fixed while building this: matching on `code` alone
        // silently missed every line that went through SyncPayResolver's CUSTOM: fallback (no
        // company catalog row for that item_code), since 'code' there is 'CUSTOM:{item_name}', not
        // the raw item_code Origami's own occurrence rows are actually keyed by.
        if ($run['sync_process_id'] !== null) {
            $syncModel = new PayrollSyncModel($this->db);
            foreach ($rows as &$row) {
                $syncItemCode = $row['sync_item_code'] ?? null;
                unset($row['sync_item_code']); // internal matching key only, not part of the public row shape
                if ($row['line_type'] !== 'earning_deduction' || $row['code'] === self::BASE_SALARY_OVERRIDE_CODE) {
                    continue;
                }
                $occurrences = $syncModel->occurrencesForItem((int)$run['sync_process_id'], $employeeId, $syncItemCode ?? $row['code']);
                if ($occurrences) {
                    $row['occurrences'] = $occurrences;
                }
            }
            unset($row);
        } else {
            foreach ($rows as &$row) {
                unset($row['sync_item_code']);
            }
            unset($row);
        }

        return $rows;
    }

    /** The 7 payroll_sync_items columns this override mechanism can correct -- see
     *  payroll_run_sync_item_overrides' own docblock in database/payroll.sql for why the names are
     *  identical to their source columns and why absent_days (not absent_mins) is the one editable
     *  absence field. */
    private const ATTENDANCE_OVERRIDE_FIELDS = [
        'ot_req_working_day_hrs', 'ot_req_weekend_hrs', 'ot_req_holiday_hrs',
        'trip_allowance', 'late_mins', 'absent_days', 'leave_without_pay_days',
    ];

    /**
     * Corrects the RAW attendance numbers Origami sent for one employee on this run (2026-08-21,
     * explicit request: "ต้องการแก้ตัวเลขดิบที่ Sync มา ไม่ใช่แค่ยอดเงิน") -- distinct from
     * lineOverrideSave() above, which overrides the resulting deduction AMOUNT instead. Full-replace
     * semantics (same spirit as AttendanceDeductionRuleModel::ruleSave()'s bracket replace): every
     * field in ATTENDANCE_OVERRIDE_FIELDS not present (or null) in $fields is stored as NULL, so the
     * form's submitted state always becomes the complete override row, not a partial patch on top
     * of whatever was there before. Applied by recalculate() the next time it runs (see that
     * method's prefetch of payroll_run_sync_item_overrides + the 4th arg on
     * SyncPayResolver::resolve()) -- this method itself just persists the correction and triggers
     * recalculate(), same "mutate then recalculate immediately" pattern as addManualLine().
     */
    public function attendanceOverrideSave(int $runId, int $compId, int $employeeId, array $fields, ?string $note, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run can have its earning/deduction items adjusted.'];
        }
        // 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย" -- item 9b): the old "only a run pulled
        // from synced attendance data" refusal here was already stricter than what recalculate()
        // itself actually needs -- $syncItemsByEmployee (the thing that determines whether
        // SyncPayResolver::resolve() -- and therefore this override table -- ever applies at all,
        // see that method's own `isset($syncItemsByEmployee[$employeeId])` gate) is populated
        // EITHER by a real Origami sync payload OR by TransactionDataPayAdapter's own Manual/Import
        // fallback (Phase 5, 2026-08-30) -- meaning the calculation engine ALREADY treats
        // sync/manual/import attendance data uniformly, only this save-time check was still
        // sync-only. Removed entirely: every run type can now correct its own attendance figures
        // the same way. (An employee with genuinely zero underlying attendance data of ANY source
        // still has nothing for this override to apply to -- same "no line to override" reality a
        // sync-based employee absent from the pulled payload already has today, not a new gap.)
        if ($this->isEmployeeVerifiedForRun($runId, $employeeId)) {
            return ['status' => false, 'message' => 'This employee is verified for this run and cannot be edited. Unverify first.'];
        }

        $stmtEmp = $this->db->prepare("SELECT employee_no FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $employeeNo = $stmtEmp->fetchColumn();
        if ($employeeNo === false) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }

        $values = [];
        foreach (self::ATTENDANCE_OVERRIDE_FIELDS as $field) {
            if (!array_key_exists($field, $fields) || $fields[$field] === null || $fields[$field] === '') {
                $values[$field] = null;
                continue;
            }
            if (!is_numeric($fields[$field]) || (float)$fields[$field] < 0) {
                return ['status' => false, 'message' => "Invalid value for {$field}."];
            }
            $values[$field] = round((float)$fields[$field], 2);
        }

        $note = $note !== null ? trim($note) : '';
        $note = $note !== '' ? $note : null;

        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }

            // 2026-08-31, same-day follow-up (item 10): selected in full (not just id) so the
            // per-field old_value used below reflects whatever this run's override actually was
            // BEFORE this save, not just whether a row existed.
            $stmtExisting = $this->db->prepare("SELECT * FROM `payroll_run_sync_item_overrides` WHERE run_id = :run_id AND employee_id = :employee_id");
            $stmtExisting->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
            $existingRow = $stmtExisting->fetch(PDO::FETCH_ASSOC);
            $existingId = $existingRow['id'] ?? false;

            // $values above is keyed by plain field name (kept the validation loop readable) --
            // rebuild with the leading colon every prepared-statement placeholder needs.
            // Field-only bindings (no :run_id/:employee_id) -- the UPDATE branch's SQL doesn't
            // reference either (it targets by :id instead), and PDO's native prepares reject extra
            // bound parameters that don't appear in the query text (real bug found running this
            // method's own test -- the UPDATE path failed outright with both left in).
            $fieldBound = [':note' => $note];
            foreach ($values as $field => $value) {
                $fieldBound[":{$field}"] = $value;
            }

            if ($existingId) {
                $setSql = implode(', ', array_map(fn($f) => "{$f} = :{$f}", self::ATTENDANCE_OVERRIDE_FIELDS));
                $this->db->prepare("UPDATE `payroll_run_sync_item_overrides` SET {$setSql}, note = :note, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                    ->execute(array_merge($fieldBound, [':updated_by' => $userId, ':id' => $existingId]));
            } else {
                $cols = implode(', ', self::ATTENDANCE_OVERRIDE_FIELDS);
                $placeholders = implode(', ', array_map(fn($f) => ":{$f}", self::ATTENDANCE_OVERRIDE_FIELDS));
                $this->db->prepare("INSERT INTO `payroll_run_sync_item_overrides` (run_id, employee_id, {$cols}, note, created_by)
                    VALUES (:run_id, :employee_id, {$placeholders}, :note, :created_by)")
                    ->execute(array_merge($fieldBound, [':run_id' => $runId, ':employee_id' => $employeeId, ':created_by' => $userId]));
            }

            $summary = implode(', ', array_filter(array_map(
                fn($f, $v) => $v !== null ? "{$f}={$v}" : null,
                array_keys($values), array_values($values)
            )));
            $this->logAudit($runId, 'draft', 'draft', 'attendance_override_save', $userId,
                "Employee {$employeeNo}: " . ($summary !== '' ? $summary : 'all fields reset to synced values') . ($note ? " (note: {$note})" : ''));

            // 2026-08-31, same-day follow-up (item 10): one history row per field that ACTUALLY
            // changed (this method upserts all 7 fields on every call, so most calls leave most
            // fields untouched -- an unconditional history row per field would flood the audit
            // report with no-op noise). $values[$field]===null means "this call leaves the field
            // following synced/import data" -- same reading as recorded 'restore', consistent with
            // attendanceOverrideRemove()'s own action value below.
            foreach (self::ATTENDANCE_OVERRIDE_FIELDS as $field) {
                $oldFieldValue = $existingRow && $existingRow[$field] !== null ? (float)$existingRow[$field] : null;
                $newFieldValue = $values[$field];
                if ($oldFieldValue === $newFieldValue || ($oldFieldValue !== null && $newFieldValue !== null && abs($oldFieldValue - $newFieldValue) < 0.005)) {
                    continue; // unchanged, nothing to record
                }
                $this->recordLineOverrideHistory($runId, $employeeId, 'attendance', $field,
                    $newFieldValue !== null ? 'override' : 'restore', $oldFieldValue, $newFieldValue, $userId, $note);
            }

            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }

        return $this->recalculate($runId, $compId, $userId, $isAdmin);
    }

    /** Reverts every field back to whatever Origami actually sent, then recalculates. */
    public function attendanceOverrideRemove(int $runId, int $compId, int $employeeId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run can have its earning/deduction items adjusted.'];
        }

        $stmtEmp = $this->db->prepare("SELECT employee_no FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $employeeNo = $stmtEmp->fetchColumn();

        // 2026-08-31, same-day follow-up (item 10): captured BEFORE the delete, same "old_value
        // must reflect what the override actually was" reasoning as lineOverrideRemove()'s own.
        $stmtExisting = $this->db->prepare("SELECT * FROM `payroll_run_sync_item_overrides` WHERE run_id = :run_id AND employee_id = :employee_id");
        $stmtExisting->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        $existingRow = $stmtExisting->fetch(PDO::FETCH_ASSOC);

        $this->db->prepare("DELETE FROM `payroll_run_sync_item_overrides` WHERE run_id = :run_id AND employee_id = :employee_id")
            ->execute([':run_id' => $runId, ':employee_id' => $employeeId]);

        $this->logAudit($runId, 'draft', 'draft', 'attendance_override_remove', $userId,
            "Employee " . ($employeeNo !== false ? $employeeNo : $employeeId) . ": all fields reset to synced values");

        // One 'restore' row per field that genuinely HAD an override (skip fields that were never
        // touched) -- new_value is the real synced/import figure it reverted to, not a guess.
        $syncedAfter = $this->attendanceDataForEmployee($compId, $runId, $employeeId)['synced'] ?? [];
        foreach (self::ATTENDANCE_OVERRIDE_FIELDS as $field) {
            if (!$existingRow || $existingRow[$field] === null) {
                continue;
            }
            $this->recordLineOverrideHistory($runId, $employeeId, 'attendance', $field, 'restore',
                (float)$existingRow[$field], isset($syncedAfter[$field]) ? (float)$syncedAfter[$field] : null, $userId);
        }

        return $this->recalculate($runId, $compId, $userId, $isAdmin);
    }

    /**
     * Read-only, for the UI: the raw synced values side by side with whatever override is currently
     * active (or all-null if none), for the same 7 fields ATTENDANCE_OVERRIDE_FIELDS covers.
     */
    public function attendanceDataForEmployee(int $compId, int $runId, int $employeeId): array {
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['synced' => [], 'override' => []];
        }
        $castNumeric = fn(array $row) => array_map(fn($v) => $v !== null ? (float)$v : null, $row);
        // 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย" -- item 9b): the OLD blanket "non-sync run
        // -> return nothing at all" refusal here was a SEPARATE gate from
        // attendanceOverrideSave()'s own (already removed) -- a real bug found while testing this
        // change: the save succeeded but this read-back method still silently hid it for a non-sync
        // run. `synced` (Origami's own raw payroll_sync_items row) genuinely has nothing to show for
        // a non-sync run -- that part of the gate is correct and stays. `override` has no such
        // dependency (payroll_run_sync_item_overrides is keyed by run_id/employee_id only, not tied
        // to sync_process_id at all) -- reads unconditionally now, matching what
        // attendanceOverrideSave() can now actually write for any run.
        $synced = array_fill_keys(self::ATTENDANCE_OVERRIDE_FIELDS, null);
        if ($run['sync_process_id'] !== null) {
            $stmtSync = $this->db->prepare("SELECT " . implode(', ', self::ATTENDANCE_OVERRIDE_FIELDS) . " FROM `payroll_sync_items`
                WHERE process_id = :process_id AND employee_id = :employee_id AND mapping_status = 'mapped'
                ORDER BY id DESC LIMIT 1");
            $stmtSync->execute([':process_id' => $run['sync_process_id'], ':employee_id' => $employeeId]);
            $synced = $stmtSync->fetch(PDO::FETCH_ASSOC) ?: $synced;
        }

        $stmtOv = $this->db->prepare("SELECT " . implode(', ', self::ATTENDANCE_OVERRIDE_FIELDS) . " FROM `payroll_run_sync_item_overrides`
            WHERE run_id = :run_id AND employee_id = :employee_id");
        $stmtOv->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        $override = $stmtOv->fetch(PDO::FETCH_ASSOC) ?: array_fill_keys(self::ATTENDANCE_OVERRIDE_FIELDS, null);

        return ['synced' => $castNumeric($synced), 'override' => $castNumeric($override)];
    }

    /** payroll_sync_items columns shown by the "Raw Sync Data" viewer -- deliberately NOT
     *  `SELECT *`: that row also carries AES-256-GCM-encrypted PII (pay_bank_no, id_card_no,
     *  spouse_data, children_data) and assorted Origami-internal identity fields (gender,
     *  date_birth, nationality, religion, dept_id/posi_id, origami_*_id) that have nothing to do
     *  with "Recheck ข้อมูลย้อนหลัง" (rechecking the PAYROLL/ATTENDANCE figures that drove this
     *  employee's calculation) -- showing raw ciphertext would be useless, and showing decrypted
     *  PII in a generic debug-style viewer would be a real, unnecessary privacy exposure unrelated
     *  to what this feature is actually for. Scoped to exactly the fields that feed
     *  SyncPayResolver/attendanceDataForEmployee()'s correction form, plus enough identifying
     *  context (payroll_code/dept/position/branch/shift/pay type+bank name) to place the record. */
    private const RAW_SYNC_DATA_FIELDS = [
        'payroll_code', 'emp_code', 'mapping_status',
        'dept_description', 'position_name', 'branch_name', 'shift_working_name',
        'pay_type', 'pay_bank_code', 'pay_bank_name',
        'working_days', 'working_mins', 'absent_days', 'absent_mins', 'late_mins', 'early_mins',
        'ot_mins', 'ot_req_hrs', 'ot_req_working_day_hrs', 'ot_req_weekend_hrs', 'ot_req_holiday_hrs',
        'leave_approve_days', 'leave_wait_days', 'leave_without_pay_days', 'trip_allowance',
        'pass_pro', 'pass_pro_date', 'item_values',
    ];

    /**
     * Read-only, for the "Raw Sync Data" viewer (2026-08-21, explicit request: "ดูข้อมูลดิบได้ ว่า
     * ข้อมูลดิบที่ส่งมาเป็นยังไง เพื่อทำการ Recheck ข้อมูลย้อนหลังได้") -- the raw payroll/attendance
     * fields Origami actually sent (see RAW_SYNC_DATA_FIELDS for what's included and why), for
     * after-the-fact audit/verification -- distinct from attendanceDataForEmployee(), which exposes
     * only the 7 fields that can be corrected. Returns null for a non-sync run or when this employee
     * has no mapped sync row (e.g. a manually-added employee on a sync-based run -- see
     * recalculate()'s sync-branch eligibility query, which now allows that).
     */
    public function rawSyncDataForEmployee(int $compId, int $runId, int $employeeId): ?array {
        $run = $this->get($runId, $compId);
        if (!$run || $run['sync_process_id'] === null) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT " . implode(', ', self::RAW_SYNC_DATA_FIELDS) . " FROM `payroll_sync_items`
            WHERE process_id = :process_id AND employee_id = :employee_id AND mapping_status = 'mapped'
            ORDER BY id DESC LIMIT 1");
        $stmt->execute([':process_id' => $run['sync_process_id'], ':employee_id' => $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['item_values'] = $row['item_values'] !== null ? json_decode((string)$row['item_values'], true) : [];
        // Current run-scoped tax/SSO exemption, if any (2026-08-21, explicit request) -- bundled
        // in here rather than a separate GET endpoint since this is fetched fresh every time the
        // Raw Sync Data modal opens anyway, same "always pull fresh" convention as everything else.
        $row['exemption'] = $this->getEmployeeExemption($runId, $compId, $employeeId);
        // 2026-08-29, explicit request: "ให้แสดงในข้อมูลด้วยว่า จำนวนวันในรอบนั้นกี่วัน วันทำงานกี่วัน
        // วันหยุดนักขัตฤกษ์กี่วัน วันหยุดประจำสัปดาห์กี่วัน" -- computed from THIS app's own company
        // holiday/shift configuration (not from Origami), shown alongside the raw `working_days`
        // Origami itself reported so an admin can see both side by side. See
        // SetupRulesModel::workingDaysBreakdown()'s own docblock.
        //
        // 2026-08-29, real bug found and fixed (explicit report: "ส่วนนี้ยังไม่ถูก เพราะจำได้ว่าข้อมูลที่
        // ส่งมาจาก Origami ถูกครับ เพราะเข้างานรอบนั้น ออกจากงานรอบนั้นจะคำนวณวันจริงมาให้แล้ว" -- Origami's
        // own working_days already accounts for a mid-period joiner/leaver's REAL employment window,
        // but this breakdown was passing the run's raw period_start_date/period_end_date straight
        // through unclamped -- always showing the FULL period's day count (e.g. 31/26/0/5) even for
        // an employee who only worked part of it, so the two numbers could never agree for anyone
        // who joined or left mid-period. Clamped to the same employment_date/employment_end_date
        // intersection recalculate() already uses for its own prorate window ($effectiveStart/
        // $effectiveEnd there) -- an employee present for the WHOLE period sees zero change (the
        // intersection is just the period itself), only a mid-period joiner/leaver's breakdown now
        // shrinks to match their actual window, same as Origami's own number already did.
        $stmtEmpDates = $this->db->prepare("SELECT employment_date, employment_end_date FROM employees WHERE id = :id AND comp_id = :comp_id");
        $stmtEmpDates->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $empDates = $stmtEmpDates->fetch(PDO::FETCH_ASSOC);
        $periodStart = (string)$run['period_start_date'];
        $periodEnd = (string)$run['period_end_date'];
        $effectiveStart = ($empDates && $empDates['employment_date'] !== null && $empDates['employment_date'] > $periodStart) ? $empDates['employment_date'] : $periodStart;
        $effectiveEnd = ($empDates && $empDates['employment_end_date'] !== null && $empDates['employment_end_date'] < $periodEnd) ? $empDates['employment_end_date'] : $periodEnd;
        $row['working_days_breakdown'] = $this->setupRulesModel->workingDaysBreakdown($employeeId, $compId, $effectiveStart, $effectiveEnd);
        return $row;
    }

    private const CALC_OVERRIDE_VALUES = ['inherit', 'yes', 'no'];

    /**
     * Current per-run tax/SSO calculation override for one employee, or "inherit" defaults if none
     * saved yet. 2026-08-29: widened from a force-off-only boolean pair (exempt_tax/exempt_sso) to
     * a bidirectional tri-state pair -- see this table's own migration header comment
     * (2026-08-29_13_payroll_run_calc_exclusions.sql) for the backfill. Both the old derived
     * boolean keys AND the new tri-state keys are returned so nothing else reading the old shape
     * breaks.
     */
    public function getEmployeeExemption(int $runId, int $compId, int $employeeId): array {
        $stmt = $this->db->prepare("SELECT tax_calculate_override, sso_calculate_override, note FROM `payroll_run_employee_exemptions`
            WHERE run_id = :run_id AND employee_id = :employee_id");
        $stmt->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $taxOverride = $row['tax_calculate_override'] ?? 'inherit';
        $ssoOverride = $row['sso_calculate_override'] ?? 'inherit';
        return [
            'tax_calculate_override' => $taxOverride,
            'sso_calculate_override' => $ssoOverride,
            'exempt_tax' => $taxOverride === 'no',
            'exempt_sso' => $ssoOverride === 'no',
            'note' => $row['note'] ?? null,
        ];
    }

    /**
     * Sets (or clears, when both are 'inherit') an employee's per-run tax/SSO calculation override
     * (2026-08-21, explicit request: "จัดการได้ว่า คนนี้ไม่ต้องคำนวณภาษี ไม่นำส่งประกันสังคมในรอบนี้" --
     * widened 2026-08-29, explicit request: "ต้องกำหนดได้ด้วยว่าคำนวณภาษี ไม่คำนวณภาษี ส่งประกันสังคมไหม
     * กำหนดแบบทั้งหมด และรายบุคคลได้", from force-off-only to a bidirectional
     * inherit/yes/no tri-state, so an employee can now also be forced back ON even when the run's
     * own "Run Settings" default says off for everyone). Does not touch the employee's own
     * permanent tax_exempt/sso_enrolled columns. Applied by recalculate()'s Pass 2 the next time it
     * runs (see that method's prefetch of payroll_run_employee_exemptions/payroll_run_calc_settings),
     * same "mutate then recalculate immediately" pattern as every other per-run override save in
     * this class. When both are 'inherit', the row is deleted outright rather than kept as an
     * all-inherit row -- keeps exemptionsByEmployee's prefetch in recalculate() empty for a run
     * nobody has touched, and getEmployeeExemption() already returns the same "inherit" shape for
     * "no row" as for "a row of inherits" so callers can't tell the difference anyway.
     */
    public function saveEmployeeExemption(int $runId, int $compId, int $employeeId, string $taxCalculateOverride, string $ssoCalculateOverride, ?string $note, int $userId, bool $isAdmin): array {
        if (!in_array($taxCalculateOverride, self::CALC_OVERRIDE_VALUES, true) || !in_array($ssoCalculateOverride, self::CALC_OVERRIDE_VALUES, true)) {
            return ['status' => false, 'message' => 'Invalid tax/SSO calculation setting.'];
        }
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run can have its employee exemptions adjusted.'];
        }
        if ($this->isEmployeeVerifiedForRun($runId, $employeeId)) {
            return ['status' => false, 'message' => 'This employee is verified for this run and cannot be edited. Unverify first.'];
        }
        $stmtEmp = $this->db->prepare("SELECT employee_no FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $employeeNo = $stmtEmp->fetchColumn();
        if ($employeeNo === false) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }

        $note = $note !== null ? trim($note) : '';
        $note = $note !== '' ? $note : null;

        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            if ($taxCalculateOverride === 'inherit' && $ssoCalculateOverride === 'inherit') {
                $this->db->prepare("DELETE FROM `payroll_run_employee_exemptions` WHERE run_id = :run_id AND employee_id = :employee_id")
                    ->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
                $this->logAudit($runId, 'draft', 'draft', 'employee_exemption_remove', $userId, "Employee {$employeeNo}: exemptions cleared.");
            } else {
                $this->db->prepare("INSERT INTO `payroll_run_employee_exemptions` (run_id, employee_id, exempt_tax, exempt_sso, tax_calculate_override, sso_calculate_override, note, created_by)
                    VALUES (:run_id, :employee_id, :exempt_tax, :exempt_sso, :tax_override, :sso_override, :note, :created_by)
                    ON DUPLICATE KEY UPDATE exempt_tax = VALUES(exempt_tax), exempt_sso = VALUES(exempt_sso),
                        tax_calculate_override = VALUES(tax_calculate_override), sso_calculate_override = VALUES(sso_calculate_override),
                        note = VALUES(note), updated_by = VALUES(created_by), updated_at = CURRENT_TIMESTAMP")
                    ->execute([
                        ':run_id' => $runId, ':employee_id' => $employeeId,
                        // Legacy boolean columns kept in sync for anything historical still reading
                        // them -- 'no' (force off) is the only value the old column pair could ever
                        // represent, so 'yes'/'inherit' both correctly map to 0 (not exempt).
                        ':exempt_tax' => $taxCalculateOverride === 'no' ? 1 : 0, ':exempt_sso' => $ssoCalculateOverride === 'no' ? 1 : 0,
                        ':tax_override' => $taxCalculateOverride, ':sso_override' => $ssoCalculateOverride,
                        ':note' => $note, ':created_by' => $userId,
                    ]);
                $summaryParts = [];
                if ($taxCalculateOverride !== 'inherit') { $summaryParts[] = "tax calculate = {$taxCalculateOverride}"; }
                if ($ssoCalculateOverride !== 'inherit') { $summaryParts[] = "SSO calculate = {$ssoCalculateOverride}"; }
                $summary = implode(', ', $summaryParts);
                $this->logAudit($runId, 'draft', 'draft', 'employee_exemption_save', $userId,
                    "Employee {$employeeNo}: {$summary} for this run." . ($note ? " (note: {$note})" : ''));
            }
            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }

        return $this->recalculate($runId, $compId, $userId, $isAdmin);
    }

    private const CALC_DEFAULT_VALUES = ['use_employee_setting', 'yes', 'no'];

    /**
     * "Run Settings" panel (Process Detail page, 2026-08-29 explicit request -- see recalculate()'s
     * own prefetch docblock for the full feature). Whole-run defaults: which items (base salary +
     * any earning/deduction catalog item) are excluded from calculation for EVERY employee in this
     * run, and whether tax/SSO calculation defaults to on/off/"use each employee's own setting".
     * `item_options` is the full active catalog for this company (+ the reserved base-salary
     * pseudo-item first) for the panel's checklist -- fetched here rather than a separate endpoint
     * since the panel always needs both together.
     */
    public function runSettingsGet(int $runId, int $compId): array {
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $stmt = $this->db->prepare("SELECT tax_calculate_default, sso_calculate_default FROM `payroll_run_calc_settings` WHERE run_id = :run_id");
        $stmt->execute([':run_id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $excludedStmt = $this->db->prepare("SELECT item_code FROM `payroll_run_item_exclusions` WHERE run_id = :run_id");
        $excludedStmt->execute([':run_id' => $runId]);

        $itemOptions = [['item_code' => self::BASE_SALARY_OVERRIDE_CODE, 'item_name_th' => 'เงินเดือนพื้นฐาน', 'item_name_en' => 'Base Salary', 'item_type' => 'base_salary']];
        $stmtCatalog = $this->db->prepare("SELECT item_code, item_name_th, item_name_en, item_type FROM `payroll_earning_deduction_types`
            WHERE comp_id = :comp_id AND status = 'active' ORDER BY item_type, item_name_th");
        $stmtCatalog->execute([':comp_id' => $compId]);
        foreach ($stmtCatalog->fetchAll(PDO::FETCH_ASSOC) as $catalogRow) {
            $itemOptions[] = $catalogRow;
        }

        return [
            'status' => true,
            'data' => [
                'tax_calculate_default' => $row['tax_calculate_default'] ?? 'use_employee_setting',
                'sso_calculate_default' => $row['sso_calculate_default'] ?? 'use_employee_setting',
                'excluded_item_codes' => $excludedStmt->fetchAll(PDO::FETCH_COLUMN),
                'item_options' => $itemOptions,
            ],
        ];
    }

    /**
     * Saves the "Run Settings" panel's whole-run defaults, then recalculates (same "mutate then
     * recalculate immediately" pattern as every other per-run override save in this class).
     * `$excludedItemCodes` is a full-replace list (delete+reinsert, same convention as
     * approval_workflow_steps/holiday_assignments in this project) -- the panel always submits its
     * complete current checklist state, not a diff.
     */
    public function runSettingsSave(int $runId, int $compId, string $taxCalculateDefault, string $ssoCalculateDefault, array $excludedItemCodes, int $userId, bool $isAdmin): array {
        if (!in_array($taxCalculateDefault, self::CALC_DEFAULT_VALUES, true) || !in_array($ssoCalculateDefault, self::CALC_DEFAULT_VALUES, true)) {
            return ['status' => false, 'message' => 'Invalid tax/SSO calculation setting.'];
        }
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run can have its Run Settings adjusted.'];
        }

        $excludedItemCodes = array_values(array_unique(array_filter(array_map(function ($code) {
            return trim((string)$code);
        }, $excludedItemCodes), fn($code) => $code !== '')));

        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            $this->db->prepare("INSERT INTO `payroll_run_calc_settings` (run_id, tax_calculate_default, sso_calculate_default, updated_by)
                VALUES (:run_id, :tax_default, :sso_default, :updated_by)
                ON DUPLICATE KEY UPDATE tax_calculate_default = VALUES(tax_calculate_default),
                    sso_calculate_default = VALUES(sso_calculate_default), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP")
                ->execute([
                    ':run_id' => $runId, ':tax_default' => $taxCalculateDefault, ':sso_default' => $ssoCalculateDefault, ':updated_by' => $userId,
                ]);

            $this->db->prepare("DELETE FROM `payroll_run_item_exclusions` WHERE run_id = :run_id")->execute([':run_id' => $runId]);
            if (!empty($excludedItemCodes)) {
                $stmtIns = $this->db->prepare("INSERT INTO `payroll_run_item_exclusions` (run_id, item_code, created_by) VALUES (:run_id, :item_code, :created_by)");
                foreach ($excludedItemCodes as $code) {
                    $stmtIns->execute([':run_id' => $runId, ':item_code' => $code, ':created_by' => $userId]);
                }
            }

            $summary = "tax default = {$taxCalculateDefault}, SSO default = {$ssoCalculateDefault}, excluded items = "
                . (empty($excludedItemCodes) ? 'none' : implode(', ', $excludedItemCodes));
            $this->logAudit($runId, 'draft', 'draft', 'run_settings_save', $userId, $summary);

            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }

        return $this->recalculate($runId, $compId, $userId, $isAdmin);
    }

    /**
     * 2026-08-31, explicit request: "เพิ่ม Function ให้มี checkbox ติ๊กว่าคำนวณอัตโนมัติหลังจากที่แก้ไขข้อมูล
     * ทันที...บันทึกลง DB ผูกกับ Run นั้นๆ" -- persisted per-run (confirmed via AskUserQuestion: shared
     * across whoever opens this run, not a personal browser preference). Every mutation entry point
     * this page itself owns (addManualLine/removeManualLine/lineOverrideSave/lineOverrideRemove/
     * attendanceOverrideSave/attendanceOverrideRemove/saveEmployeeExemption/joinEmployees/
     * removeManualEmployee/runSettingsSave) ALREADY calls recalculate() internally as its own last
     * step -- so this flag adds nothing there, numbers from THIS page's own actions are always fresh
     * regardless of the setting. What this flag actually controls is the one gap those methods can
     * never close: an edit made somewhere ELSE (Employee Detail's salary/PED tab, Setup & Rules,
     * Payroll Configuration, HR sync, ...) that this specific draft run has no way to detect on its
     * own. detail.js's own page-load path checks this flag and, when true, fires ONE recalculate()
     * automatically before rendering so a returning admin always sees fresh numbers without an extra
     * click; when false, the page renders as-is and shows a reminder banner instead (see
     * renderRecalcReminder() in detail.js).
     */
    public function setAutoRecalculate(int $runId, int $compId, bool $value, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run can have this setting changed.'];
        }
        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET auto_recalculate = :value WHERE id = :id AND comp_id = :comp_id");
        $stmt->execute([':value' => $value ? 1 : 0, ':id' => $runId, ':comp_id' => $compId]);
        return ['status' => true, 'message' => 'Saved successfully.'];
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

    /**
     * 2026-08-23, wired to the generic Approval Workflow engine (explicit bug report: a workflow
     * WAS configured for PAYROLL_RUN_APPROVAL via the Approval Workflow tab -- one
     * approver_type='user' step -- but this method never looked at it, only the flat
     * structure_roles.can_approve_payroll check). If an active workflow exists for this document
     * type, a real ApprovalRequestModel request is created and linked via approval_request_id --
     * approve()/reject() below then route through that engine instead of the flat check.
     * ALWAYS creates a fresh request here (never reopens an old one from a prior reject/need_info
     * cycle) -- submit() only ever runs from 'draft', which this run only reaches via a genuinely
     * new submission (first time, or after reviseAfterReject()/reviseAfterNeedInfo()), so a clean
     * request starting at step 1 is exactly right; the previous cycle's request (if any) simply
     * stays in the table as history, no longer linked as current. No active workflow configured
     * at all -- approval_request_id stays NULL, falling back to the flat role check unchanged.
     */
    public function submit(int $id, int $compId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
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

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare("UPDATE `payroll_runs` SET state = 'pending_approval', submitted_at = CURRENT_TIMESTAMP,
                submitted_by = :submitted_by, updated_by = :submitted_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([':submitted_by' => $userId, ':id' => $id]);

            $approvalRequestModel = new ApprovalRequestModel($this->db);
            if ($approvalRequestModel->hasActiveWorkflow($compId, 'PAYROLL_RUN_APPROVAL')) {
                $createRes = $approvalRequestModel->create($compId, 'PAYROLL_RUN_APPROVAL', $id, $run['run_name'], $userId);
                if ($createRes['status']) {
                    $this->db->prepare("UPDATE `payroll_runs` SET approval_request_id = :approval_request_id WHERE id = :id")
                        ->execute([':approval_request_id' => $createRes['id'], ':id' => $id]);
                }
                // Best-effort: hasActiveWorkflow() already confirmed one exists, so create() failing
                // here would only be a genuine race (deactivated between the two calls) -- don't
                // block the submission over it, just fall back to the flat role check for this run.
            }

            $this->logAudit($id, 'draft', 'pending_approval', 'submit', $userId);
            if ($own) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Submitted for approval.'];
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /** Every state a DECIDED run (approved/rejected/need_info) may be reverted directly INTO --
     *  deliberately excludes 'approved' itself (re-approving must go through the real approve()
     *  flow/engine, not this override) and excludes paid/locked/cancelled/draft (not reachable from
     *  a decided state at all). */
    private const REVERT_TARGET_STATES = ['pending_approval', 'rejected', 'need_info'];

    /**
     * 2026-08-24, explicit follow-up request ("ในหน้า Approve...สามารถถอยอนุมัติได้ โดยถ้า Process
     * นั้นอนุมัติ สามารถถอยมารออนุมัติ ไม่อนุมัติ ขอข้อมูลเพิ่มเติมได้ คือ Status ที่ถอยหรือเปลี่ยน ต้องไม่ใช่
     * Status เดิม") -- supersedes the 2026-08-23 design this method originally shipped with, which
     * deliberately picked ONE fixed target per source state ("never an arbitrary pick", per that
     * day's own docblock) specifically to avoid this. The user has now explicitly asked for the
     * opposite: from any DECIDED state (approved/rejected/need_info), the approver picks which of
     * the other 2 decided-adjacent states to land on (self::REVERT_TARGET_STATES) -- e.g. an
     * approved run can go back to pending_approval, OR straight to rejected, OR straight to
     * need_info, the approver's choice -- as long as it's not the run's CURRENT status (a no-op
     * "revert to the same status" is rejected outright). `pending_approval` itself still has
     * exactly ONE target (`draft`) -- there is nothing to choose between, it is the submitter
     * pulling their own still-undecided run back for edits, not an approver overriding a decision.
     *
     * The state-defining columns for whichever state is being EXITED are cleared (approved_at/by,
     * rejected_at/by/reason, or need_info_at/by/reason); if the NEW target is itself 'rejected' or
     * 'need_info' (not just 'pending_approval'), that state's own columns are SET too (rejected_by/
     * reason or need_info_by/reason, using $note as the reason, same columns reject()/requestInfo()
     * themselves populate) -- so a run landed on 'rejected' via this override looks exactly like
     * one rejected the normal way to every other part of the app that reads those columns.
     *
     * Nothing here touches payroll_run_audit_logs -- every past approve/reject/request-info/revert
     * action stays in that table forever (never deleted/overwritten), which is exactly the "keep a
     * Log of how many times it was approved and what happened each time" the original request
     * asked for -- see getAuditLog()/approvalFlow() and the Timeline modal that renders both.
     */
    public function revert(int $id, int $compId, int $userId, bool $isAdmin, ?string $note = null, ?string $toState = null): array {
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $fromState = $run['state'];
        if ($fromState === 'pending_approval') {
            $toState = 'draft';
        } elseif (in_array($fromState, ['approved', 'rejected', 'need_info'], true)) {
            // Backward compatible: a caller that doesn't specify a target (the old single-target
            // callers/tests) still gets the original "undo back to pending_approval" behavior.
            $toState = $toState ?? 'pending_approval';
            if (!in_array($toState, self::REVERT_TARGET_STATES, true)) {
                return ['status' => false, 'message' => 'Invalid target status.'];
            }
            if ($toState === $fromState) {
                return ['status' => false, 'message' => 'The new status must be different from the current status.'];
            }
        } else {
            return ['status' => false, 'message' => 'This payroll run is not in a state that can be reverted.'];
        }
        // 2026-08-23, explicit request ("ในกรณีที่ส่ง Approve แล้วยังไม่มีใคร Approve สามารถดึง Process
        // กลับได้") -- pulling back a still-pending_approval submission (nobody has decided on it
        // yet) is allowed for EITHER the submitter (can_process_payroll, pulling back their own
        // submission) OR a valid approver. Undoing an ALREADY-decided state stays approver-only.
        // 2026-08-23, wired to the Approval Workflow engine when this run went through it
        // (approval_request_id set) -- eligibility resolves against that request's own step
        // (canActOnRequest(), same live role-membership resolution act() itself uses) instead of
        // the flat department-scoped fallback (canApproveThisRun()).
        // 2026-08-24, same admin-bypass fix as approve()/reject()/requestInfo() -- undoing an
        // ALREADY-decided outcome (approved/rejected/need_info) is approver-only, and once an
        // active workflow governs the run, admin does not get a free pass around it (must actually
        // be a configured/eligible approver, same as everyone else). Pulling back a still-
        // pending_approval submission to draft keeps its OTHER allowed path -- the submitter's own
        // can_process_payroll -- untouched either way: userCan() bypasses for admin there on
        // purpose, same as every process-side (not approval-side) permission in this class, per the
        // user's own instruction that payroll PROCESSING is a separate concern from approval.
        $approvalRequestModel = $run['approval_request_id'] !== null ? new ApprovalRequestModel($this->db) : null;
        if ($approvalRequestModel !== null) {
            $allowed = $approvalRequestModel->canActOnRequest($compId, (int)$run['approval_request_id'], $userId);
            if ($fromState === 'pending_approval') {
                $allowed = $allowed || $this->userCan($userId, 'payroll_run.process', $isAdmin);
            }
        } elseif ($isAdmin) {
            $allowed = true;
        } else {
            $allowed = $fromState === 'pending_approval'
                ? ($this->canApproveThisRun($userId, $isAdmin, $run) || $this->userCan($userId, 'payroll_run.process', $isAdmin))
                : $this->canApproveThisRun($userId, $isAdmin, $run);
        }
        if (!$allowed) {
            return ['status' => false, 'message' => 'You do not have permission to revert this payroll run.'];
        }
        $clearSql = '';
        if ($fromState === 'approved') {
            $clearSql = ", approved_at = NULL, approved_by = NULL";
        } elseif ($fromState === 'rejected') {
            $clearSql = ", rejected_at = NULL, rejected_by = NULL, reject_reason = NULL";
        } elseif ($fromState === 'need_info') {
            $clearSql = ", need_info_at = NULL, need_info_by = NULL, need_info_reason = NULL";
        } elseif ($fromState === 'pending_approval') {
            // 2026-08-27, explicit bug report ("ในหน้า Process List ถ้ายังไม่ส่งไป Approve ปุ่ม Timeline
            // ยังไม่ควรขึ้นมาให้กดดูได้ครับ") -- pulling a still-pending_approval submission back to
            // draft (nobody has decided on it yet) left `submitted_at` populated, so a run that's
            // genuinely draft again (editable, nothing pending) still satisfied
            // workflowTimelineButtonHtml()'s/the Detail page's own `!row.submitted_at` gate and kept
            // showing the Timeline button/mini-timeline "submitted" dot as done. Clearing it here
            // matches the same "revert clears the column(s) that state's own transition set" pattern
            // already used for approved_at/rejected_at/need_info_at above -- submit() sets a fresh
            // submitted_at the next time this run is actually resubmitted.
            $clearSql = ", submitted_at = NULL";
        }
        // If the NEW target is itself a decided-ish state (not just pending_approval/draft), set
        // its own columns too -- same shape reject()/requestInfo() themselves write, so this looks
        // identical to a normal decision to everything else that reads these columns.
        $setSql = '';
        $params = [':state' => $toState, ':updated_by' => $userId, ':id' => $id];
        if ($toState === 'rejected') {
            $setSql = ", rejected_at = CURRENT_TIMESTAMP, rejected_by = :acted_by, reject_reason = :reason";
            $params[':acted_by'] = $userId;
            $params[':reason'] = ($note !== null && trim($note) !== '') ? $note : 'Reverted to rejected.';
        } elseif ($toState === 'need_info') {
            $setSql = ", need_info_at = CURRENT_TIMESTAMP, need_info_by = :acted_by, need_info_reason = :reason";
            $params[':acted_by'] = $userId;
            $params[':reason'] = ($note !== null && trim($note) !== '') ? $note : 'Reverted to need_info.';
        }
        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET state = :state, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP{$clearSql}{$setSql} WHERE id = :id");
        $stmt->execute($params);
        // 2026-09-04, Backlog Phase 9->10, T051 -- reverting AWAY from 'approved' means this run's
        // sync-transaction-log rows (written once, at approve() time) are no longer a settled fact --
        // delete them so history never shows stale "approved" data for a run that's back in flux. A
        // re-approval later writes fresh rows again (PayrollSyncTransactionLogModel::logForRun()'s own
        // delete-then-reinsert). No-op (nothing to delete) for every other fromState, since rows only
        // ever exist for a run that reached 'approved' at least once.
        if ($fromState === 'approved') {
            require_once __DIR__ . '/PayrollSyncTransactionLogModel.php';
            try {
                (new PayrollSyncTransactionLogModel($this->db))->deleteForRun($id);
            } catch (Throwable $e) {
                // Best-effort, same posture as approve()'s own write -- a cleanup failure must never
                // block the revert itself (state already committed via the UPDATE above).
            }
        }
        // Re-opens the SAME linked request at step 1 so the engine is ready for a fresh decision
        // through the same configured chain -- only meaningful when the target is actually
        // 'pending_approval' (a genuinely fresh decision is expected next). A direct override to
        // 'rejected'/'need_info' deliberately does NOT touch the linked approval_requests row at
        // all (same as requestInfo() itself never touching it) -- it stays whatever it last was;
        // known, accepted limitation: the generic Document Approval Monitor page's own status for
        // this request may look stale (e.g. still 'approved') until the run is acted on again
        // through the normal flow. Revisit only if that page's own users actually hit this.
        if ($approvalRequestModel !== null && $fromState !== 'pending_approval' && $toState === 'pending_approval') {
            $approvalRequestModel->reopen($compId, (int)$run['approval_request_id']);
        }
        $this->logAudit($id, $fromState, $toState, 'revert', $userId, $note);
        return ['status' => true, 'message' => 'Reverted.'];
    }

    /**
     * 2026-08-23, wired to the Approval Workflow engine when this run went through it
     * (approval_request_id set at submit() time) -- routes the decision through
     * ApprovalRequestModel::act() instead of the flat canApproveThisRun() check, so a configured
     * approval_workflow_steps chain (specific user OR role, single- or multi-step, any/all joint
     * approve) is actually what decides who may act, matching what the Approval Workflow tab
     * actually configured (explicit bug report: it wasn't being consulted at all before this).
     * A non-terminal act() result (multi-step chain still advancing, or a joint 'all' step not yet
     * fully satisfied) leaves the run itself at pending_approval -- only a final 'approved' from
     * the engine flips payroll_runs.state, same as reaching the end of the flat single-step check
     * always did. No workflow was ever configured for this run (approval_request_id is NULL) --
     * falls straight back to the original flat department-scoped check, unchanged.
     *
     * 2026-08-24, `if (!$isAdmin)` used to wrap this ENTIRE block, so an admin session skipped
     * calling the engine's act() outright -- can_approve_payroll's own bypass (see
     * canApproveThisRun()'s docblock) was only the visible half of the same bug; this was the
     * actual server-side hole it was hiding (a shown-because-of-the-bug button really did work).
     * It also meant an admin "approving" an engine-routed run never touched
     * approval_request_step_approvers/approval_request_logs at all -- the linked approval_requests
     * row stayed 'pending' forever while payroll_runs.state said 'approved', a real orphaned-state
     * bug on top of the access-control one. Now: an active workflow ALWAYS routes through act(),
     * admin included -- admin only keeps its unconditional bypass on the flat fallback below (no
     * workflow configured at all, nothing to violate).
     */
    public function approve(int $id, int $compId, int $userId, bool $isAdmin, ?string $note = null): array {
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

        if ($run['approval_request_id'] !== null) {
            $approvalRequestModel = new ApprovalRequestModel($this->db);
            $actRes = $approvalRequestModel->act($compId, (int)$run['approval_request_id'], $userId, 'approve', $note);
            if (!$actRes['status']) {
                return $actRes;
            }
            if (($actRes['request_status'] ?? null) !== 'approved') {
                // Recorded in approval_request_logs already (act() itself does that); the run
                // stays pending_approval, still waiting on the rest of the chain/step.
                $this->logAudit($id, 'pending_approval', 'pending_approval', 'approve_step', $userId, $note);
                return ['status' => true, 'message' => $actRes['message']];
            }
            // Final step reached and satisfied -- fall through to the same state flip below.
        } elseif (!$isAdmin && !$this->canApproveThisRun($userId, $isAdmin, $run)) {
            return ['status' => false, 'message' => 'You do not have permission to approve this payroll run.'];
        }

        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET state = 'approved', approved_at = CURRENT_TIMESTAMP,
            approved_by = :approved_by, updated_by = :approved_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':approved_by' => $userId, ':id' => $id]);
        $this->logAudit($id, 'pending_approval', 'approved', 'approve', $userId, $note);
        // 2026-09-02, Deduction Destination & Third-Party Remittance -- generates the run's
        // remittance batches (company/other_person/employee_fallback groups) right after the state
        // flip, confirmed trigger point via AskUserQuestion. Best-effort/non-blocking: a failure
        // here must never undo an already-successful approval (same "wrapped in an outer try/catch"
        // posture as PayslipDeliveryService::autoSendForRun() elsewhere in this app) -- surfaced as
        // a warning appended to the success message instead, so an admin still sees it happened but
        // the approval itself is never rolled back over a remittance-grouping issue.
        require_once __DIR__ . '/PayrollRemittanceModel.php';
        $remittanceWarning = '';
        try {
            $remittanceRes = (new PayrollRemittanceModel($this->db))->generateForRun($id, $compId, $userId);
            if (!$remittanceRes['status']) {
                $remittanceWarning = ' (Remittance grouping warning: ' . ($remittanceRes['message'] ?? 'unknown error') . ')';
            } elseif (!empty($remittanceRes['unspecified_company_count'])) {
                // 2026-09-10, Batch 3B item 3: explicit instruction -- never hide this bucket
                // silently. Appended to the same success-message warning slot approve() already
                // uses for the remittance-grouping warning above.
                $remittanceWarning = ' (ปลายทางยังไม่ระบุ ' . $remittanceRes['unspecified_company_count'] . " รายการ -- {$remittanceRes['unspecified_company_count']} deduction line(s) with payee_type='company' have no specific bank account -- see the Remittance tab.)";
            }
        } catch (Throwable $e) {
            $remittanceWarning = ' (Remittance grouping warning: ' . $e->getMessage() . ')';
        }
        // 2026-09-04, Backlog Phase 9->10, T051 -- one-time snapshot of this run's own sync-derived
        // pay lines (Diligence/Trip Allowance/opted-in Student Loan/etc.) into a durable per-employee
        // history log, read from the ALREADY-PERSISTED payroll_run_details breakdown (frozen since
        // recalculate() only runs on a 'draft' run). Best-effort/non-blocking, same posture as the
        // remittance-grouping call just above -- a logging failure must never undo an already-
        // successful approval. See PayrollSyncTransactionLogModel's own docblock for the full design
        // (why this write happens here specifically, and why revert()/reopen() delete it again).
        require_once __DIR__ . '/PayrollSyncTransactionLogModel.php';
        try {
            (new PayrollSyncTransactionLogModel($this->db))->logForRun($compId, $id, (string)$run['period_start_date'], (string)$run['period_end_date']);
        } catch (Throwable $e) {
            $remittanceWarning .= ' (Sync transaction log warning: ' . $e->getMessage() . ')';
        }
        // 2026-08-29, explicit request: "อนุมัติแล้วนะ ทำงานต่อเลยไหม" -- notifies whoever can actually
        // act on this next (Mark as Paid), not the approver themselves -- see NotificationModel's
        // own top-of-file docblock for the full recipient-resolution rationale per notification type.
        (new NotificationModel())->createForPermissionHolders(
            $compId, 'payroll_run.finalize', 'approved_continue',
            "งวด \"{$run['run_name']}\" ได้รับการอนุมัติแล้ว", "\"{$run['run_name']}\" has been approved",
            "ดำเนินการจ่ายต่อได้เลยครับ", "Ready to continue -- Mark as Paid when you're ready",
            "/payroll-process/{$id}", 'payroll_run', $id, null, 'fa-circle-check'
        );
        return ['status' => true, 'message' => 'Approved.' . $remittanceWarning];
    }

    /**
     * Same Approval Workflow engine wiring as approve() above.
     *
     * 2026-08-23: reject() in that engine is NO LONGER unconditionally terminal, now that steps
     * carry a `group_type` (AND/OR/Finish, ported from origami's getApprovalResult) -- a reject on
     * an OR-group step doesn't necessarily finalize the whole request by itself if other OR-group
     * steps can still save it (only an AND-group or Finish-group reject is guaranteed terminal).
     * Mirrors approve()'s own non-terminal handling exactly: only flips payroll_runs.state once the
     * underlying request has actually reached 'rejected'.
     *
     * 2026-08-24, same admin-bypass fix as approve() (see its own docblock) -- an active workflow
     * routes through act() unconditionally now, admin included; admin only keeps its bypass on the
     * flat fallback (no workflow configured).
     */
    public function reject(int $id, int $compId, int $userId, bool $isAdmin, string $reason): array {
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
        if ($run['approval_request_id'] !== null) {
            $approvalRequestModel = new ApprovalRequestModel($this->db);
            $actRes = $approvalRequestModel->act($compId, (int)$run['approval_request_id'], $userId, 'reject', $reason);
            if (!$actRes['status']) {
                return $actRes;
            }
            if (($actRes['request_status'] ?? null) !== 'rejected') {
                // Recorded in approval_request_logs already (act() itself does that); the run
                // stays pending_approval, still waiting on the rest of the chain/step.
                $this->logAudit($id, 'pending_approval', 'pending_approval', 'reject_step', $userId, $reason);
                return ['status' => true, 'message' => $actRes['message']];
            }
            // The reject was terminal (AND-group or Finish-group step, or the last remaining
            // OR-group step) -- fall through to the same state flip below.
        } elseif (!$isAdmin && !$this->canApproveThisRun($userId, $isAdmin, $run)) {
            return ['status' => false, 'message' => 'You do not have permission to reject this payroll run.'];
        }
        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET state = 'rejected', rejected_at = CURRENT_TIMESTAMP,
            rejected_by = :rejected_by, reject_reason = :reason, updated_by = :rejected_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':rejected_by' => $userId, ':reason' => trim($reason), ':id' => $id]);
        $this->logAudit($id, 'pending_approval', 'rejected', 'reject', $userId, $reason);
        return ['status' => true, 'message' => 'Rejected.'];
    }

    /**
     * Approves multiple payroll runs in one call (2026-08-22, explicit request: "การอนุมุติให้มี
     * checkbox เลือกอนุมุติได้หลายรายการพร้อมกัน") -- a thin loop over the single-run approve()
     * above, zero changes to that method or the state machine it enforces. Each id gets its own
     * full permission/state/calculation-clean check exactly as if approved one at a time; a bad id
     * in the batch (wrong state, dirty calculation, no permission) just fails on its own without
     * blocking the others, same partial-success shape the reference design uses. `status` is true
     * as long as at least one succeeded, so the caller can still show a useful message when the
     * batch is a mix of successes and failures -- `results` (keyed by id) carries the detail for a
     * per-row failure reason if the UI wants to show it.
     * @param int[] $ids
     */
    public function bulkApprove(array $ids, int $compId, int $userId, bool $isAdmin, ?string $note = null): array {
        $results = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $results[$id] = $this->approve($id, $compId, $userId, $isAdmin, $note);
        }
        $succeeded = count(array_filter($results, fn($r) => $r['status']));
        return ['status' => $succeeded > 0, 'succeeded' => $succeeded, 'total' => count($results), 'results' => $results];
    }

    /** Same shape/reasoning as bulkApprove() above, looping the single-run reject() (still requires
     *  a non-empty $reason, same validation reject() already does per-id). */
    public function bulkReject(array $ids, int $compId, int $userId, bool $isAdmin, string $reason): array {
        $results = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $results[$id] = $this->reject($id, $compId, $userId, $isAdmin, $reason);
        }
        $succeeded = count(array_filter($results, fn($r) => $r['status']));
        return ['status' => $succeeded > 0, 'succeeded' => $succeeded, 'total' => count($results), 'results' => $results];
    }

    /**
     * Sends a pending_approval run back with "need more information" (2026-08-22, explicit
     * request: "Status ในหน้า Approve มี Waiting Approve Not Approve Need Information" -- confirmed
     * with the user this is a REAL third state, not a label). Byte-for-byte mirror of reject()
     * above -- same permission, same non-empty-reason requirement, same "only from
     * pending_approval" guard -- just lands on 'need_info' instead of 'rejected', a separate
     * branch off pending_approval alongside it (rejected = something is wrong; need_info = more
     * info needed before a decision can be made).
     */
    /** The Approval Workflow engine's own act() vocabulary is approve/reject/cancel only -- no
     *  "need more info" concept -- so this stays a PayrollRunModel-only state, but gates on
     *  canActOnRequestNow() (the same "eligible, still-pending, unlocked" gate act() itself
     *  enforces for approve/reject) when this run went through that engine, instead of the flat
     *  department-scoped fallback.
     *  2026-08-24, tightened from canActOnRequest() to canActOnRequestNow() -- this action only
     *  ever fires from pending_approval (checked below), so it belongs with approve()/reject() in
     *  the same button group and must follow the same "can I decide RIGHT NOW" rule, not the
     *  coarser "was ever eligible" one meant for undoing an already-decided outcome. See
     *  canApproveThisRun()'s own docblock for the full reasoning.
     *  2026-08-24, second fix the same day: admin no longer bypasses this check when an active
     *  workflow governs the run (same reasoning as approve()/reject()) -- only kept as a bypass on
     *  the flat fallback below (no workflow configured), via canApproveThisRun() itself. */
    public function requestInfo(int $id, int $compId, int $userId, bool $isAdmin, string $reason): array {
        if (trim($reason) === '') {
            return ['status' => false, 'message' => 'A reason is required.'];
        }
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $allowed = $run['approval_request_id'] !== null
            ? (new ApprovalRequestModel($this->db))->canActOnRequestNow($compId, (int)$run['approval_request_id'], $userId)
            : $this->canApproveThisRun($userId, $isAdmin, $run);
        if (!$allowed) {
            return ['status' => false, 'message' => 'You do not have permission to request information on this payroll run.'];
        }
        if ($run['state'] !== 'pending_approval') {
            return ['status' => false, 'message' => 'Only a payroll run pending approval can have information requested.'];
        }
        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET state = 'need_info', need_info_at = CURRENT_TIMESTAMP,
            need_info_by = :need_info_by, need_info_reason = :reason, updated_by = :need_info_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':need_info_by' => $userId, ':reason' => trim($reason), ':id' => $id]);
        $this->logAudit($id, 'pending_approval', 'need_info', 'request_info', $userId, $reason);
        return ['status' => true, 'message' => 'Information requested.'];
    }

    /** Same shape/reasoning as bulkReject() above, looping requestInfo(). */
    public function bulkRequestInfo(array $ids, int $compId, int $userId, bool $isAdmin, string $reason): array {
        $results = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $results[$id] = $this->requestInfo($id, $compId, $userId, $isAdmin, $reason);
        }
        $succeeded = count(array_filter($results, fn($r) => $r['status']));
        return ['status' => $succeeded > 0, 'succeeded' => $succeeded, 'total' => count($results), 'results' => $results];
    }

    /** Mirror of reviseAfterReject() below -- reopens a need_info run as draft for revision, same
     *  can_process_payroll permission (the run owner, not the approver). No UI wired to this yet,
     *  same pre-existing gap reviseAfterReject() itself already has -- kept for API-surface parity. */
    public function reviseAfterNeedInfo(int $id, int $compId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to revise this payroll run.'];
        }
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'need_info') {
            return ['status' => false, 'message' => 'Only a payroll run marked as needing information can be revised.'];
        }
        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET state = 'draft', updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':updated_by' => $userId, ':id' => $id]);
        $this->logAudit($id, 'need_info', 'draft', 'reviseAfterNeedInfo', $userId);
        return ['status' => true, 'message' => 'Reopened as draft for revision.'];
    }

    public function cancel(int $id, int $compId, int $userId, bool $isAdmin, string $reason): array {
        if (!$this->userCan($userId, 'payroll_run.approve', $isAdmin)) {
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
        // rejected/need_info (2026-08-22: need_info added, same reasoning -- money hasn't moved
        // there either). Once paid/locked, cancelling the *record* would be misleading (the
        // payment already happened); that needs a real reversal process, not a state flip, so
        // it's deliberately not allowed here.
        if (!in_array($run['state'], ['draft', 'pending_approval', 'approved', 'rejected', 'need_info'], true)) {
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
        if (!$this->userCan($userId, 'payroll_run.process', $isAdmin)) {
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
        if (!$this->userCan($userId, 'payroll_run.finalize', $isAdmin)) {
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
            foreach ($stmtDetails->fetchAll(PDO::FETCH_ASSOC) as $detail) {
                foreach (array_merge(json_decode((string)$detail['earning_breakdown'], true) ?? [], json_decode((string)$detail['deduction_breakdown'], true) ?? []) as $line) {
                    if (($line['source'] ?? '') === 'ped' && !empty($line['installment_id'])) {
                        $installmentIds[] = (int)$line['installment_id'];
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
            // 2026-08-31, explicit request: real double-payment risk found and confirmed by the
            // user -- reopen() has always allowed re-opening an already-paid/locked run (e.g. to
            // merge a supplemental process into it), but nothing recorded how much of this run's
            // total was ALREADY disbursed on a prior payment cycle. This is what closes that gap:
            // one payroll_run_payment_events row per employee, per markPaid() call, recording the
            // DELTA (current amount minus every prior event already recorded for this run+
            // employee) -- NOT the run's own current total. A run's first-ever markPaid() has no
            // prior events, so every delta here equals the plain current amount -- byte-identical
            // to this method's own pre-existing behavior for the overwhelmingly common single-
            // payment-cycle case, this is purely additive. See
            // PayrollReportDataModel::getRunDetails()'s own matching *_amount_due columns, which
            // BankTransferFileReport/PaymentVoucherReport now read instead of the raw amounts.
            $stmtPriorPaid = $this->db->prepare("SELECT employee_id, SUM(gross_amount_paid) AS gross_paid,
                    SUM(deduction_amount_paid) AS deduction_paid, SUM(net_amount_paid) AS net_paid
                FROM `payroll_run_payment_events` WHERE run_id = :run_id GROUP BY employee_id");
            $stmtPriorPaid->execute([':run_id' => $id]);
            $priorPaidByEmployee = [];
            foreach ($stmtPriorPaid->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $priorPaidByEmployee[(int)$row['employee_id']] = $row;
            }
            $stmtCurrentDetails = $this->db->prepare("SELECT employee_id, gross_amount, total_deduction_amount, net_amount FROM `payroll_run_details` WHERE run_id = :run_id");
            $stmtCurrentDetails->execute([':run_id' => $id]);
            $insEvent = $this->db->prepare("INSERT INTO `payroll_run_payment_events`
                (run_id, employee_id, gross_amount_paid, deduction_amount_paid, net_amount_paid, payment_method, payment_reference, paid_by)
                VALUES (:run_id, :employee_id, :gross, :deduction, :net, :payment_method, :payment_reference, :paid_by)");
            foreach ($stmtCurrentDetails->fetchAll(PDO::FETCH_ASSOC) as $detail) {
                $employeeId = (int)$detail['employee_id'];
                $prior = $priorPaidByEmployee[$employeeId] ?? ['gross_paid' => 0, 'deduction_paid' => 0, 'net_paid' => 0];
                $insEvent->execute([
                    ':run_id' => $id, ':employee_id' => $employeeId,
                    ':gross' => round((float)$detail['gross_amount'] - (float)$prior['gross_paid'], 2),
                    ':deduction' => round((float)$detail['total_deduction_amount'] - (float)$prior['deduction_paid'], 2),
                    ':net' => round((float)$detail['net_amount'] - (float)$prior['net_paid'], 2),
                    ':payment_method' => $paymentMethod, ':payment_reference' => $paymentReference, ':paid_by' => $userId,
                ]);
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
        if (!$this->userCan($userId, 'payroll_run.finalize', $isAdmin)) {
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
        // 2026-08-29, explicit request: "ปิดรอบไปแล้วอย่าลืมปริ้นเอกสาร" -- see NotificationModel's own
        // top-of-file docblock for why this goes to can_process_payroll holders (the people who'd
        // actually go pull the statutory/bank/payslip reports next).
        (new NotificationModel())->createForPermissionHolders(
            $compId, 'payroll_run.process', 'lock_reminder_print',
            "งวด \"{$run['run_name']}\" ปิดรอบแล้ว", "\"{$run['run_name']}\" is now locked",
            "อย่าลืมปริ้นเอกสาร/รายงานที่จำเป็นสำหรับงวดนี้นะครับ", "Don't forget to print the reports/documents needed for this period",
            "/payroll-process/{$id}", 'payroll_run', $id, null, 'fa-print'
        );
        return ['status' => true, 'message' => 'Locked.'];
    }

    /**
     * 2026-08-29, explicit request: "รายการที่ติ๊กว่าทำจ่ายแล้ว หรือปิดรอบไปแล้ว สามารถเปิดให้กลับมาแก้ไขได้
     * และส่งอนุมัติใหม่ได้ครับ" -- takes a paid/locked run all the way back to draft so its numbers can
     * be corrected (via lineOverrideSave()/manual lines/recalculate()) and resubmitted through
     * submit() -- which already creates a brand-new ApprovalRequestModel row on every call, so
     * "resubmit" needed no separate mechanism once state is back at draft. Gated by
     * can_finalize_payroll (confirmed via AskUserQuestion: same permission that can Mark-as-Paid/
     * Lock in the first place -- reopening something that sensitive deserves at least that level of
     * trust, not the lighter can_process_payroll a normal draft edit only needs), NOT extended onto
     * revert()'s own state machine (pending_approval/approved/rejected/need_info) -- this is a
     * fundamentally different permission tier and a one-way "undo the finalization", not an
     * approval-flow decision.
     *
     * Approval/submission fields (approved_at/by, submitted_at/by, approval_request_id) are cleared
     * along with paid/locked -- the OLD approval_request row and its own approval_request_logs are
     * NEVER touched or deleted (same "nothing is ever hard-deleted" convention as every other
     * history table in this app), only the now-stale FK on payroll_runs itself is detached, exactly
     * like a fresh submit() would create its own new link anyway.
     *
     * Real correctness risk found and handled here, not guessed: markPaid() doesn't just flip
     * payroll_runs.state -- it also marks any employee_earning_deduction_installments this run
     * consumed as `status='processed'` (and advances/completes their parent
     * employee_earning_deductions.current_installment). Reopening without reversing that would
     * leave those installments permanently "spent" even though the payroll that spent them just got
     * undone -- a recalculate() on the reopened draft would then silently DROP that deduction line
     * (its installment is no longer 'pending', so recalculate()'s own installment query would never
     * find it again), understating what the employee actually owes. Reversed here as the exact
     * mirror of markPaid()'s own consumption logic, scoped tightly to installments THIS run
     * consumed (`payroll_run_id = :id`) so a different run's own consumption is never touched.
     *
     * Deliberately does NOT auto-recalculate() at the end -- state transition and figure
     * recalculation stay separate, explicit user actions everywhere else in this class (Recalculate
     * is its own button), and an admin reopening a run to review it first shouldn't have the numbers
     * change out from under them before they've looked.
     */
    public function reopen(int $id, int $compId, int $userId, bool $isAdmin, ?string $note = null): array {
        if (!$this->userCan($userId, 'payroll_run.finalize', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to reopen this payroll run.'];
        }
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $fromState = $run['state'];
        if (!in_array($fromState, ['paid', 'locked'], true)) {
            return ['status' => false, 'message' => 'Only a paid or locked payroll run can be reopened.'];
        }

        // 2026-08-30, explicit request: "หลังจากปิดรอบต้องกี่วันถึงจะสามารถดึงกลับมาได้" -- an optional,
        // company-configurable cap (Payroll Configuration > Payroll Policies tab) on how many days
        // after the run's own CLOSING timestamp it can still be reopened. "Closing" is locked_at
        // when the run reached 'locked' (the state this app's own UI actually calls "ปิดรอบ"), else
        // paid_at for a run reopened straight from 'paid' (never locked at all) -- whichever
        // timestamp corresponds to the fromState being left. NULL/unset policy = unlimited, the
        // exact behavior this method had before this feature existed, so a company that never
        // visits the new tab sees zero change.
        require_once __DIR__ . '/PayrollPolicyModel.php';
        $policy = (new PayrollPolicyModel($this->db))->get($compId);
        $reopenWindowDays = $policy['reopen_window_days'];
        if ($reopenWindowDays !== null) {
            $closedAt = $fromState === 'locked' ? ($run['locked_at'] ?? null) : ($run['paid_at'] ?? null);
            if ($closedAt !== null) {
                $daysSinceClosed = (int)floor((time() - strtotime($closedAt)) / 86400);
                if ($daysSinceClosed > $reopenWindowDays) {
                    return ['status' => false, 'message' => "This payroll run was closed {$daysSinceClosed} day(s) ago, past this company's {$reopenWindowDays}-day reopen window."];
                }
            }
        }

        $note = $note !== null ? trim($note) : '';
        $note = $note !== '' ? $note : null;

        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }

            $stmtInst = $this->db->prepare("SELECT id, assignment_id FROM `employee_earning_deduction_installments` WHERE payroll_run_id = :run_id AND status = 'processed'");
            $stmtInst->execute([':run_id' => $id]);
            $consumedInstallments = $stmtInst->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($consumedInstallments)) {
                $instIds = array_column($consumedInstallments, 'id');
                $placeholders = implode(',', array_fill(0, count($instIds), '?'));
                $this->db->prepare("UPDATE `employee_earning_deduction_installments`
                    SET status = 'pending', payroll_run_id = NULL, processed_at = NULL WHERE id IN ({$placeholders})")
                    ->execute($instIds);

                $assignmentIds = array_unique(array_column($consumedInstallments, 'assignment_id'));
                foreach ($assignmentIds as $assignmentId) {
                    // A completed assignment is no longer "done" once one of its installments is
                    // un-consumed -- reverts to active regardless of whether IT was the one that
                    // tipped current_installment to completion (GREATEST(0, ...) guards against
                    // ever going negative if this ran twice for some reason).
                    $this->db->prepare("UPDATE `employee_earning_deductions`
                        SET current_installment = GREATEST(0, current_installment - 1), status = 'active'
                        WHERE id = :id")->execute([':id' => $assignmentId]);
                }
            }

            $stmt = $this->db->prepare("UPDATE `payroll_runs` SET state = 'draft',
                paid_at = NULL, paid_by = NULL, payment_method = NULL, payment_reference = NULL,
                locked_at = NULL, locked_by = NULL,
                approved_at = NULL, approved_by = NULL,
                submitted_at = NULL, submitted_by = NULL,
                approval_request_id = NULL,
                updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([':updated_by' => $userId, ':id' => $id]);

            // 2026-09-04, Backlog Phase 9->10, T051 -- reopen() only ever operates on 'paid'/'locked'
            // (both downstream of 'approved', checked above), and always clears approved_at/
            // approved_by along with everything else, so this run's sync-transaction-log rows
            // (written once, at approve() time) always need clearing here too -- same "no longer a
            // settled fact once un-approved" reasoning as revert()'s own cleanup. Inside this
            // method's own transaction (unlike revert()/approve(), which have none) since reopen()
            // already treats its other side-effect reversals (installment un-consumption) as
            // real, non-best-effort steps.
            require_once __DIR__ . '/PayrollSyncTransactionLogModel.php';
            (new PayrollSyncTransactionLogModel($this->db))->deleteForRun($id);

            $this->logAudit($id, $fromState, 'draft', 'reopen', $userId, $note);

            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }

        return ['status' => true, 'message' => 'Reopened for editing.'];
    }
}
