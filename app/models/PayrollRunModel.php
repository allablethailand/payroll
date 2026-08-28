<?php
declare(strict_types=1);
require_once __DIR__ . '/AttendanceBonusLedgerModel.php';
require_once __DIR__ . '/EmployeeModel.php';
require_once __DIR__ . '/../services/StatutoryCalculationEngine.php';
require_once __DIR__ . '/../services/ThPitCalculator.php';
require_once __DIR__ . '/../services/SyncPayResolver.php';
require_once __DIR__ . '/../services/PayslipDeliveryService.php';
require_once __DIR__ . '/../services/sync/MasterDataSyncOrchestrator.php';
require_once __DIR__ . '/PayrollSyncModel.php';
require_once __DIR__ . '/SetupRulesModel.php';
require_once __DIR__ . '/ApprovalRequestModel.php';
require_once __DIR__ . '/EmployeeRecurringEarningModel.php';

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
    private ThPitCalculator $thPitCalculator;
    private SyncPayResolver $syncPayResolver;
    private SetupRulesModel $setupRulesModel;
    private EmployeeRecurringEarningModel $recurringEarningModel;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->ledgerModel = new AttendanceBonusLedgerModel();
        $this->engine = new StatutoryCalculationEngine($this->db);
        $this->thPitCalculator = new ThPitCalculator($this->db, $this->engine);
        $this->syncPayResolver = new SyncPayResolver($this->db);
        $this->setupRulesModel = new SetupRulesModel($this->db);
        $this->recurringEarningModel = new EmployeeRecurringEarningModel($this->db);
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
                    (SELECT from_state FROM `payroll_run_audit_logs` WHERE run_id = r.id AND action = 'cancel' ORDER BY id DESC LIMIT 1) AS cancelled_from_state
                FROM `payroll_runs` r
                LEFT JOIN `payroll_cycles` c ON c.id = r.cycle_id
                LEFT JOIN `employees` creator ON creator.id = r.created_by
                LEFT JOIN `employees` submitter ON submitter.id = r.submitted_by
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
                    'status' => $status, 'acted_at' => $actedAt, 'note' => $note,
                ];
            }, $pool);
            return ['run_state' => $run['state'], 'approvers' => $approvers];
        }

        $departmentId = $this->submitterDepartmentId($run);
        $sql = "SELECT e.id, e.employee_no, e.name_th, e.name_en
                FROM `employees` e
                JOIN `structure_roles` sr ON sr.id = e.role_id AND sr.deleted_at IS NULL
                WHERE e.comp_id = :comp_id AND e.deleted_at IS NULL AND sr.can_approve_payroll = 1";
        $params = [':comp_id' => $compId];
        if ($departmentId !== null) {
            $sql .= " AND e.department_id = :department_id";
            $params[':department_id'] = $departmentId;
        }
        $sql .= " ORDER BY e.name_th ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $approvers = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

    /** True if the employee holds ANY of the 3 payroll role-flags (or is admin) -- gates read access to run detail/salary data. */
    public function canView(int $actingEmployeeId, bool $isAdmin): bool {
        return $this->userCan($actingEmployeeId, 'can_process_payroll', $isAdmin)
            || $this->userCan($actingEmployeeId, 'can_approve_payroll', $isAdmin)
            || $this->userCan($actingEmployeeId, 'can_finalize_payroll', $isAdmin);
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
        if (!$this->userCan($actingEmployeeId, 'can_approve_payroll', $isAdmin)) {
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
        return $this->userCan($actingEmployeeId, 'can_process_payroll', $isAdmin);
    }

    /** Same, for can_finalize_payroll -- gates the Detail page's "Mark as Paid"/"Lock" buttons
     *  (markPaid()/lock() below), same simple role-flag check (no department-scoping like
     *  can_approve_payroll needs -- finalizing isn't tied to who submitted the run). */
    public function canFinalizePayroll(int $actingEmployeeId, bool $isAdmin): bool {
        return $this->userCan($actingEmployeeId, 'can_finalize_payroll', $isAdmin);
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
        // payment (e.g. a one-off incentive) that BY DEFAULT does not involve base salary or the
        // employee's standing earning/deduction setup -- only whatever specific earning/deduction
        // items the admin picks per employee (see joinEmployees()/addManualLine() below). Only
        // makes sense for a genuine off-cycle run (same gate as the manual employee roster) -- a
        // cycle-based or Pending-Pull run is real payroll by definition, so 'incentive' is
        // rejected there rather than silently ignored.
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
        if ($runPurpose === 'incentive' && ($cycleId !== null || $syncProcessId !== null)) {
            return ['status' => false, 'message' => 'Incentive/Other Payment is only available for an off-cycle run with no payroll cycle selected.'];
        }
        $computeStatutory = $runPurpose === 'incentive' ? (!empty($data['compute_statutory']) ? 1 : 0) : 1;
        $includeBaseSalary = $runPurpose === 'incentive' ? (!empty($data['include_base_salary']) ? 1 : 0) : 1;
        $includeStandingItems = $runPurpose === 'incentive' ? (!empty($data['include_standing_items']) ? 1 : 0) : 1;

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
            (comp_id, cycle_id, sync_process_id, run_purpose, compute_statutory, include_base_salary, include_standing_items, run_name, period_start_date, period_end_date, payment_date, state, notes, created_by)
            VALUES (:comp_id, :cycle_id, :sync_process_id, :run_purpose, :compute_statutory, :include_base_salary, :include_standing_items, :run_name, :start, :end, :pay_date, 'draft', :notes, :created_by)");
        $stmt->execute([
            ':comp_id' => $compId, ':cycle_id' => $cycleId, ':sync_process_id' => $syncProcessId,
            ':run_purpose' => $runPurpose, ':compute_statutory' => $computeStatutory,
            ':include_base_salary' => $includeBaseSalary, ':include_standing_items' => $includeStandingItems,
            ':run_name' => $runName,
            ':start' => $start, ':end' => $end, ':pay_date' => $payDate,
            ':notes' => $notes, ':created_by' => $userId,
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

        // Run type (compute full payroll vs. an off-cycle Incentive/Other Payment pull) is editable
        // on a draft run, same forcing rules as create() -- only meaningful for a genuine off-cycle
        // run (no cycle_id/sync_process_id, both immutable after creation). A cycle-based/Pending-
        // Pull run keeps whatever create() already forced (always run_purpose='payroll' with all
        // three flags on) regardless of what the request sends, since real payroll can't opt out of
        // base salary/statutory/standing items. 2026-08-28, explicit request: "ในหน้า Process Detail
        // สามารถแก้ไขได้ด้วยว่าคำนวณเงินเดือนหรือรายรับรายหักอื่นไหม หรือเป็นการดึงมาทำจ่ายแยก".
        $runPurpose = $run['run_purpose'];
        $computeStatutory = (int)$run['compute_statutory'];
        $includeBaseSalary = (int)$run['include_base_salary'];
        $includeStandingItems = (int)$run['include_standing_items'];
        $isOffCycle = $run['cycle_id'] === null && $run['sync_process_id'] === null;
        if ($isOffCycle && array_key_exists('run_purpose', $data)) {
            $runPurpose = (string)($data['run_purpose'] ?? 'payroll') === 'incentive' ? 'incentive' : 'payroll';
            $computeStatutory = $runPurpose === 'incentive' ? (!empty($data['compute_statutory']) ? 1 : 0) : 1;
            $includeBaseSalary = $runPurpose === 'incentive' ? (!empty($data['include_base_salary']) ? 1 : 0) : 1;
            $includeStandingItems = $runPurpose === 'incentive' ? (!empty($data['include_standing_items']) ? 1 : 0) : 1;
        }

        $stmt = $this->db->prepare("UPDATE `payroll_runs` SET run_name = :run_name, period_start_date = :start,
            period_end_date = :end, payment_date = :pay_date, notes = :notes,
            run_purpose = :run_purpose, compute_statutory = :compute_statutory,
            include_base_salary = :include_base_salary, include_standing_items = :include_standing_items,
            updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id");
        $stmt->execute([
            ':run_name' => $runName, ':start' => $start, ':end' => $end, ':pay_date' => $payDate,
            ':notes' => $notes, ':run_purpose' => $runPurpose, ':compute_statutory' => $computeStatutory,
            ':include_base_salary' => $includeBaseSalary, ':include_standing_items' => $includeStandingItems,
            ':updated_by' => $userId, ':id' => $id,
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
                    e.sso_enrolled, e.pvd_enrolled, e.tax_exempt, e.is_payroll_ready,
                    e.has_spouse, e.tax_calculation_method, e.salary_type,
                    CASE WHEN psi.employee_id IS NOT NULL THEN 'sync' ELSE 'manual' END AS data_source
                FROM `employees` e
                LEFT JOIN `payroll_sync_items` psi ON psi.process_id = :process_id AND psi.employee_id = e.id AND psi.mapping_status = 'mapped'
                LEFT JOIN `payroll_run_manual_employees` pme ON pme.run_id = :run_id AND pme.employee_id = e.id
                WHERE e.comp_id = :comp_id AND e.deleted_at IS NULL
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
            $stmtEmp = $this->db->prepare("SELECT id, employee_no, base_salary_amount, key_version, employment_date, employment_end_date,
                    sso_enrolled, pvd_enrolled, tax_exempt, is_payroll_ready,
                    has_spouse, tax_calculation_method, salary_type, 'manual' AS data_source
                FROM `employees` e
                WHERE comp_id = :comp_id AND deleted_at IS NULL
                AND employment_date <= :period_end
                AND (employment_end_date IS NULL OR employment_end_date >= :period_start)
                AND NOT EXISTS (SELECT 1 FROM `payroll_run_excluded_employees` pex WHERE pex.run_id = :run_id_exclude AND pex.employee_id = e.id)");
            $stmtEmp->execute([':comp_id' => $compId, ':period_end' => $periodEnd, ':period_start' => $periodStart, ':run_id_exclude' => $id]);
        } else {
            $stmtEmp = $this->db->prepare("SELECT e.id, e.employee_no, e.base_salary_amount, e.key_version, e.employment_date, e.employment_end_date,
                    e.sso_enrolled, e.pvd_enrolled, e.tax_exempt, e.is_payroll_ready,
                    e.has_spouse, e.tax_calculation_method, e.salary_type, 'manual' AS data_source
                FROM `payroll_run_manual_employees` pme
                JOIN `employees` e ON e.id = pme.employee_id AND e.comp_id = :comp_id AND e.deleted_at IS NULL
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

        // Per-run, per-employee tax/SSO exemption (2026-08-21, explicit request: "จัดการได้ว่า
        // คนนี้ไม่ต้องคำนวณภาษี ไม่นำส่งประกันสังคมในรอบนี้") -- prefetched once, merged into
        // $employeeFlags below (Pass 2, right before StatutoryCalculationEngine::calculate()) on
        // top of the employee's own permanent sso_enrolled/tax_exempt columns. See
        // saveEmployeeExemption()'s own docblock for the full design.
        $exemptionsByEmployee = [];
        $stmtExemptions = $this->db->prepare("SELECT employee_id, exempt_tax, exempt_sso FROM `payroll_run_employee_exemptions` WHERE run_id = :run_id");
        $stmtExemptions->execute([':run_id' => $id]);
        foreach ($stmtExemptions->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $exemptionsByEmployee[(int)$row['employee_id']] = $row;
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
        //     Deliberately does NOT include attendance bonus or sync-derived lines -- both are tied
        //     to a real pay period/cycle or a pulled sync process, neither of which an off-cycle
        //     incentive run has.
        $isIncentive = ($run['run_purpose'] ?? 'payroll') === 'incentive';
        $computeStatutory = $isIncentive ? !empty($run['compute_statutory']) : true;
        $includeBaseSalary = $isIncentive ? !empty($run['include_base_salary']) : true;
        $includeStandingItems = $isIncentive ? !empty($run['include_standing_items']) : true;

        // Per-run item restriction (see payroll_run_ped_type_settings' own docblock in
        // database/payroll.sql for the full reasoning): no rows saved for a given item_type =
        // unrestricted for that type (every active standing PED assignment of that type is
        // included, exactly as before this setting existed) -- earning and deduction are
        // restricted/unrestricted completely independently of each other, matching the two-panel
        // (Earning left / Deduction right) selection UI where each side saves separately.
        // 2026-08-27: also meaningful for an incentive run once include_standing_items is on --
        // same table, same "no rows = unrestricted" default either way; only whether the
        // PED-assignment query below runs AT ALL differs by run type (see $includeStandingItems
        // above and its own gate further down).
        $earningRestrictIds = [];
        $deductionRestrictIds = [];
        if (!$isIncentive || $includeStandingItems) {
            $stmtRestrict = $this->db->prepare("SELECT s.ped_type_id, pt.item_type FROM `payroll_run_ped_type_settings` s
                JOIN `payroll_earning_deduction_types` pt ON pt.id = s.ped_type_id WHERE s.run_id = :run_id");
            $stmtRestrict->execute([':run_id' => $id]);
            foreach ($stmtRestrict->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['item_type'] === 'earning') {
                    $earningRestrictIds[] = (int)$row['ped_type_id'];
                } else {
                    $deductionRestrictIds[] = (int)$row['ped_type_id'];
                }
            }
        }
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
                 :gross_amount, :total_deduction_amount, :net_amount, :employer_cost_amount, :calc_status, :calc_errors, :data_source)");

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

                    if ($salaryType === 'daily') {
                        $payable = $this->setupRulesModel->payableDaysForEmployee($employeeId, $compId, $effectiveStart, $effectiveEnd);
                        // Repurposed, not renamed -- same "X/Y days" breakdown display fields the
                        // monthly path below still uses, now showing payable/total days instead of
                        // a calendar prorate ratio.
                        $prorateDays = $payable['payable_days'];
                        $prorateTotalDays = $payable['total_days'];
                        $effectiveBase = round($baseSalary * $payable['payable_days'], 2);
                        if (!$payable['has_shift_pattern']) {
                            // No shift assigned -- still pays for every non-holiday day in range as
                            // the safe default (see docblock), just flagged visibly instead of
                            // silently guessing a Mon-Fri pattern for someone with no data.
                            $errors[] = 'daily_salary_no_shift_pattern';
                        }
                    } else {
                        if ($salaryType === 'hourly') {
                            $errors[] = 'salary_type_hourly_not_supported';
                        }
                        $effectiveBase = $baseSalary;
                        if ($effectiveStart > $periodStart || $effectiveEnd < $periodEnd) {
                            $prorateTotalDays = $totalPeriodDays;
                            $prorateDays = (int)((strtotime($effectiveEnd) - strtotime($effectiveStart)) / 86400) + 1;
                            $prorateDays = max(0, min($prorateDays, $totalPeriodDays));
                            $effectiveBase = $prorateDays > 0 ? round($baseSalary * $prorateDays / $prorateTotalDays, 2) : 0.0;
                        }
                    }
                }

                $earningLines = [];
                $deductionLines = [];

                if ($isIncentive) {
                    // 2026-08-27, explicit request/AskUserQuestion-confirmed scope ("เงินได้/เงินหัก
                    // ปกติ (PED) + เบี้ยเลี้ยงประจำ") -- once include_standing_items is on, pull in
                    // the SAME two sources the normal-run branch below always does (standing PED
                    // assignments + Recurring Earnings), gated by the SAME $pedRestrictSql/
                    // payroll_run_ped_type_settings two-panel selection an admin already uses on a
                    // normal run. Deliberately does NOT also pull attendance bonus or sync-derived
                    // lines here (see $includeStandingItems' own docblock above for why -- neither
                    // has an off-cycle-run equivalent). This is a duplicated copy of those two
                    // specific queries from the `else` branch below, not a shared helper -- matches
                    // this method's own existing precedent of duplicating the payroll_run_manual_lines
                    // query per-branch instead (see the "Ad-hoc per-employee adjustments" comment
                    // further down, same SQL as this branch's own manual-lines query below).
                    if ($includeStandingItems) {
                        $stmtPed = $this->db->prepare("SELECT eed.id AS assignment_id, i.id AS installment_id, i.amount,
                                eed.ped_type_id, eed.custom_item_name, eed.custom_item_type, eed.payee_employee_id,
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
                                'is_custom' => $resolved['is_custom'],
                                'payee_employee_id' => $ped['payee_employee_id'] !== null ? (int)$ped['payee_employee_id'] : null,
                            ];
                            if ($resolved['item_type'] === 'earning') {
                                $earningLines[] = $line;
                            } else {
                                $deductionLines[] = $line;
                            }
                        }

                        foreach ($this->recurringEarningModel->activeForPeriod($employeeId, $periodStart, $periodEnd) as $rec) {
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
                    }

                    // Manually-picked items (see joinEmployees()/addManualLine() docblocks) --
                    // additive on top of the standing items above when include_standing_items is on,
                    // or the ONLY source when it's off (today's original/default incentive-run
                    // behavior, unchanged). No attendance bonus, no sync-derived lines, ever.
                    $stmtLines = $this->db->prepare("SELECT pml.ped_type_id, pml.amount, pml.note, pml.custom_item_name, pml.custom_item_type, pml.payee_employee_id,
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
                            'payee_employee_id' => $line['payee_employee_id'] !== null ? (int)$line['payee_employee_id'] : null,
                        ];
                        if ($resolved['item_type'] === 'earning') {
                            $earningLines[] = $entry;
                        } else {
                            $deductionLines[] = $entry;
                        }
                    }
                    // Genuinely nothing at all for this employee -- no manual lines picked, no
                    // standing items pulled in (either because include_standing_items is off, or on
                    // but nothing matched), and no base salary either. Almost certainly an oversight,
                    // same spirit as missing_base_salary for a normal payroll row. Widened from the
                    // original "just check $manualLines" version (2026-08-27) so turning on
                    // include_base_salary/include_standing_items alone no longer falsely flags an
                    // employee who has real pay lines from those sources but never got a manual line.
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
                    $stmtPed = $this->db->prepare("SELECT eed.id AS assignment_id, i.id AS installment_id, i.amount,
                            eed.ped_type_id, eed.custom_item_name, eed.custom_item_type, eed.payee_employee_id,
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
                            'is_custom' => $resolved['is_custom'],
                            'payee_employee_id' => $ped['payee_employee_id'] !== null ? (int)$ped['payee_employee_id'] : null,
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
                    foreach ($this->recurringEarningModel->activeForPeriod($employeeId, $periodStart, $periodEnd) as $rec) {
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

                    // Ad-hoc per-employee adjustments (payroll_run_manual_lines) -- additive on top
                    // of the standing PED assignments/attendance bonus above, added via the "Items"
                    // button on the calculation table (2026-08-19, explicit request: this used to be
                    // incentive-run-only, now available on any draft run so an admin can add a
                    // one-off earning/deduction for a single employee without it affecting anyone
                    // else or needing a whole separate off-cycle run). Contrast with the $isIncentive
                    // branch above, where manual lines are the ONLY source instead of an addition.
                    $stmtAdj = $this->db->prepare("SELECT pml.ped_type_id, pml.amount, pml.note, pml.custom_item_name, pml.custom_item_type, pml.payee_employee_id,
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
                            'payee_employee_id' => $adj['payee_employee_id'] !== null ? (int)$adj['payee_employee_id'] : null,
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
                        $syncResult = $this->syncPayResolver->resolve($compId, $syncItemsByEmployee[$employeeId], $baseSalary,
                            $attendanceOverridesByEmployee[$employeeId] ?? []);
                        foreach ($syncResult['earning'] as $line) {
                            $earningLines[] = $line;
                        }
                        // Per-run override/exemption (2026-08-21, explicit request) -- prefetched
                        // above into $overridesByEmployeeAndCode. No matching override = pushed
                        // through unchanged, byte-identical to before this feature existed.
                        foreach ($syncResult['deduction'] as $line) {
                            $override = $overridesByEmployeeAndCode[$employeeId][$line['code']] ?? null;
                            if ($override !== null && $override['action'] === 'exclude') {
                                continue;
                            }
                            if ($override !== null && $override['action'] === 'override_amount') {
                                $line['amount'] = (float)$override['override_amount'];
                                $overrideNote = $override['note'] ? " (override: {$override['note']})" : ' (manually overridden)';
                                $line['note'] = trim(($line['note'] ?? '') . $overrideNote);
                            }
                            $deductionLines[] = $line;
                        }
                        foreach ($syncResult['errors'] as $syncError) {
                            $errors[] = $syncError;
                        }
                    }
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
                        // still loses the money), but the transfer itself is surfaced as an error
                        // rather than silently dropped, same "visible, not silent" convention as
                        // missing_ot_rate_*/no_rate_configured above.
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
                // Per-run exemption layered on top of the employee's own permanent flags -- forces
                // OFF only, never forces a company-wide opted-out employee back ON for this run.
                $runExemption = $exemptionsByEmployee[$employeeId] ?? null;
                if ($runExemption !== null) {
                    if (!empty($runExemption['exempt_sso'])) {
                        $employeeFlags['sso_enrolled'] = false;
                    }
                    if (!empty($runExemption['exempt_tax'])) {
                        $employeeFlags['tax_exempt'] = true;
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
                        // taxable_income here is only a rough placeholder for the TH_PIT line --
                        // it gets recomputed properly by ThPitCalculator below (2026-08-21, real
                        // bug fix). Left as-is for every OTHER statutory item, which doesn't read
                        // this key at all (TH_SSO/TH_PVD use basic_salary, per statutory_items.calc_base).
                        'taxable_income' => round($grossAmount * 12, 2),
                        'net_income' => $grossAmount,
                    ];
                    $statutoryResult = $this->engine->calculate($compId, $salaryContext, $paymentDate, $employeeFlags);

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
                        $pit = $this->thPitCalculator->calculate(
                            $compId, $employeeId, $grossAmount, $ssoAmount, $pvdAmount, $periodsPerYear,
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
                // daily_salary_no_shift_pattern/salary_type_hourly_not_supported are advisory only
                // (same spirit as no_rate_ever_configured above) -- surfaced as a visible Remark via
                // calc_errors so the admin can act on them, but the run still pays out using its
                // documented safe-default fallback, so they must NOT flip calc_status to 'error' and
                // block submit() the way missing_base_salary/no_rate_configured genuinely should.
                $blockingErrors = array_diff($errors, ['daily_salary_no_shift_pattern', 'salary_type_hourly_not_supported']);
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

    /* ==================== PER-RUN EARNING/DEDUCTION ITEM SELECTION ==================== */

    /** Every active, non-is_sync_only PED type of one item_type for a company -- the full catalog
     *  a selection checklist picks from (is_sync_only excluded: those are never user-selectable,
     *  see payroll_run_ped_type_settings' own docblock in database/payroll.sql). */
    private function activePedTypesByItemType(int $compId, string $itemType): array {
        $stmt = $this->db->prepare("SELECT id, item_code, item_name_th, item_name_en
            FROM `payroll_earning_deduction_types`
            WHERE comp_id = :comp_id AND item_type = :item_type AND status = 'active' AND deleted_at IS NULL AND is_sync_only = 0
            ORDER BY item_code ASC");
        $stmt->execute([':comp_id' => $compId, ':item_type' => $itemType]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Selection state for BOTH item types at once, shaped for the two-panel (Earning left /
     * Deduction right) UI. Each type is independent: no rows saved for that type = unrestricted
     * (every active item of that type included, same as before this table existed) -- displayed as
     * every item pre-selected, since that's what's actually in effect. Any rows saved for that type
     * = restricted to exactly those.
     */
    public function getPedTypeSettings(int $id, int $compId): array {
        $run = $this->get($id, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $result = ['status' => true];
        foreach (['earning', 'deduction'] as $itemType) {
            $allItems = $this->activePedTypesByItemType($compId, $itemType);
            $stmt = $this->db->prepare("SELECT s.ped_type_id FROM `payroll_run_ped_type_settings` s
                JOIN `payroll_earning_deduction_types` pt ON pt.id = s.ped_type_id AND pt.item_type = :item_type
                WHERE s.run_id = :run_id");
            $stmt->execute([':run_id' => $id, ':item_type' => $itemType]);
            $restrictedIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $isRestricted = !empty($restrictedIds);
            $result[$itemType] = [
                'all_items' => $allItems,
                'selected_ids' => $isRestricted ? $restrictedIds : array_map(fn($row) => (int)$row['id'], $allItems),
                'is_restricted' => $isRestricted,
            ];
        }
        return $result;
    }

    /**
     * Partial replace-all, scoped to ONE item_type at a time (earning panel's Save never touches
     * the deduction selection and vice versa) -- deletes only this run's rows whose ped_type is of
     * $itemType, then reinserts $pedTypeIds. Saving every currently-active item of that type is
     * equivalent to "include all" in effect, but is stored as an explicit list (ticking every box
     * IS the reset-to-default action from the checklist UI's point of view; there's no separate
     * hidden "unrestricted" toggle to expose). Draft only.
     *
     * 2026-08-27: now also usable on an 'incentive' run, but ONLY once that run opted into
     * include_standing_items (see recalculate()'s own docblock) -- without that flag on, this
     * table is never read at all for that run (payroll_run_manual_lines is its only item source),
     * so saving a restriction here would silently do nothing; still blocked in that case with a
     * clearer message pointing at the actual toggle, rather than the old blanket "doesn't apply to
     * Incentive" rejection this used to always give. Recalculates immediately after saving
     * (2026-08-19, explicit request: the calculation table should reflect the new selection right
     * away, not require a separate manual Recalculate click).
     */
    public function savePedTypeSettings(int $id, int $compId, string $itemType, array $pedTypeIds, int $userId, bool $isAdmin): array {
        if (!in_array($itemType, ['earning', 'deduction'], true)) {
            return ['status' => false, 'message' => 'Invalid item type.'];
        }
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
        if (($run['run_purpose'] ?? 'payroll') === 'incentive' && empty($run['include_standing_items'])) {
            return ['status' => false, 'message' => 'Turn on "Include configured earning/deduction items" for this run before selecting which items to include.'];
        }
        $pedTypeIds = array_values(array_unique(array_map('intval', $pedTypeIds)));
        if (!empty($pedTypeIds)) {
            $placeholders = [];
            $params = [':comp_id' => $compId, ':item_type' => $itemType];
            foreach ($pedTypeIds as $i => $ptid) {
                $key = ":ptid{$i}";
                $placeholders[] = $key;
                $params[$key] = $ptid;
            }
            $stmtCheck = $this->db->prepare("SELECT id FROM `payroll_earning_deduction_types`
                WHERE comp_id = :comp_id AND item_type = :item_type AND status = 'active' AND deleted_at IS NULL AND id IN (" . implode(',', $placeholders) . ")");
            $stmtCheck->execute($params);
            $validIds = array_map('intval', $stmtCheck->fetchAll(PDO::FETCH_COLUMN));
            if (count($validIds) !== count($pedTypeIds)) {
                return ['status' => false, 'message' => 'One or more selected items are invalid.'];
            }
        }

        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) { $this->db->beginTransaction(); }
            $this->db->prepare("DELETE s FROM `payroll_run_ped_type_settings` s
                JOIN `payroll_earning_deduction_types` pt ON pt.id = s.ped_type_id
                WHERE s.run_id = :run_id AND pt.item_type = :item_type")
                ->execute([':run_id' => $id, ':item_type' => $itemType]);
            if (!empty($pedTypeIds)) {
                $insStmt = $this->db->prepare("INSERT INTO `payroll_run_ped_type_settings` (run_id, ped_type_id, created_by) VALUES (:run_id, :ped_type_id, :created_by)");
                foreach ($pedTypeIds as $ptid) {
                    $insStmt->execute([':run_id' => $id, ':ped_type_id' => $ptid, ':created_by' => $userId]);
                }
            }
            $this->logAudit($id, 'draft', 'draft', 'update', $userId,
                ucfirst($itemType) . ' item selection updated (' . count($pedTypeIds) . ' item(s)).');
            if ($ownTransaction) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($ownTransaction) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
        // Best-effort: the selection itself is already saved regardless of whether this succeeds.
        $calcResult = $this->recalculate($id, $compId, $userId, $isAdmin);
        return [
            'status' => true, 'message' => 'Saved successfully.',
            'employee_count' => $calcResult['employee_count'] ?? null,
            'has_validation_errors' => $calcResult['has_validation_errors'] ?? null,
        ];
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
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
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
            return [
                'code' => 'CUSTOM:' . $row['custom_item_name'],
                'name_th' => $row['custom_item_name'],
                'name_en' => $row['custom_item_name'],
                'item_type' => $row['custom_item_type'],
                'is_custom' => true,
            ];
        }
        return [
            'code' => $row['item_code'],
            'name_th' => $row['item_name_th'],
            'name_en' => $row['item_name_en'],
            'item_type' => $row['item_type'],
            'is_custom' => false,
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
    public function addManualLine(int $id, int $compId, int $employeeId, ?int $pedTypeId, float $amount, int $userId, bool $isAdmin, ?string $note = null, ?string $customItemName = null, ?string $customItemType = null, ?int $payeeEmployeeId = null): array {
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
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
        }

        // Transfer-to-payee (2026-08-21, explicit request: "หักเพื่อไปจ่ายให้ใคร") -- only meaningful
        // on a deduction; silently ignored (not an error) for an earning, same as interest_type
        // being forced to 'none' for earnings elsewhere in this codebase.
        if ($resolvedItemType !== 'deduction') {
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

        $this->db->prepare("INSERT INTO `payroll_run_manual_lines`
                (run_id, employee_id, ped_type_id, custom_item_name, custom_item_type, amount, note, payee_employee_id, created_by)
            VALUES (:run_id, :employee_id, :ped_type_id, :custom_item_name, :custom_item_type, :amount, :note, :payee_employee_id, :created_by)")
            ->execute([
                ':run_id' => $id, ':employee_id' => $employeeId, ':ped_type_id' => $pedTypeId,
                ':custom_item_name' => $customItemName, ':custom_item_type' => $customItemType,
                ':amount' => $amount, ':note' => $note, ':payee_employee_id' => $payeeEmployeeId, ':created_by' => $userId,
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

    /** Removes one manually-added line, then recalculates. No employee-membership check needed here
     *  (unlike addManualLine()) -- the line already exists, so its employee was already validated
     *  when it was added; removing it is always safe once the run itself is still draft. */
    public function removeManualLine(int $id, int $compId, int $lineId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
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
        $stmtLine = $this->db->prepare("SELECT pml.amount, pml.custom_item_name, pt.item_code, e.employee_no
            FROM `payroll_run_manual_lines` pml
            LEFT JOIN `payroll_earning_deduction_types` pt ON pt.id = pml.ped_type_id
            JOIN `employees` e ON e.id = pml.employee_id
            WHERE pml.id = :line_id AND pml.run_id = :run_id");
        $stmtLine->execute([':line_id' => $lineId, ':run_id' => $id]);
        $lineInfo = $stmtLine->fetch(PDO::FETCH_ASSOC);
        if (!$lineInfo) {
            return ['status' => false, 'message' => 'Record not found.'];
        }

        $this->db->prepare("DELETE FROM `payroll_run_manual_lines` WHERE id = :line_id AND run_id = :run_id")
            ->execute([':line_id' => $lineId, ':run_id' => $id]);

        $itemLabel = $lineInfo['item_code'] ?? $lineInfo['custom_item_name'];
        $this->logAudit($id, 'draft', 'draft', 'remove_manual_line', $userId,
            "Employee {$lineInfo['employee_no']}: removed \"{$itemLabel}\" amount " . number_format((float)$lineInfo['amount'], 2));

        return $this->recalculate($id, $compId, $userId, $isAdmin);
    }

    /** Every manual line for one employee on this run (item code/name + amount + note + line id), for the "Manage Items" UI. */
    public function manualLinesForEmployee(int $compId, int $runId, int $employeeId): array {
        $stmt = $this->db->prepare("SELECT pml.id, pml.ped_type_id, pml.amount, pml.note, pml.custom_item_name, pml.custom_item_type, pml.payee_employee_id,
                pt.item_code, pt.item_name_th, pt.item_name_en, pt.item_type, payee.employee_no AS payee_employee_no
            FROM `payroll_run_manual_lines` pml
            LEFT JOIN `payroll_earning_deduction_types` pt ON pt.id = pml.ped_type_id
            LEFT JOIN `employees` payee ON payee.id = pml.payee_employee_id
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
                'payee_employee_id' => $row['payee_employee_id'] !== null ? (int)$row['payee_employee_id'] : null,
                'payee_employee_no' => $row['payee_employee_no'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Per-run, per-employee, per-item override/exemption for a SYNC-COMPUTED deduction line
     * (2026-08-21, explicit request: "ปรับค่า สาย ขาดงาน ลาไม่รับเงิน หรือยกเว้นไม่ให้หัก") -- upserted
     * by (run_id, employee_id, item_code), same shape as AttendanceDeductionRuleModel::ruleSave()'s
     * select-then-update-or-insert. Deliberately scoped to THIS run only (confirmed choice, not a
     * standing per-employee setting) -- applying the same rule to every future run would need a
     * different, employee-level mechanism, not this one. Only valid on a sync-based draft run --
     * there is no sync-computed line to override otherwise.
     */
    public function lineOverrideSave(int $runId, int $compId, int $employeeId, string $itemCode, string $action, ?float $overrideAmount, ?string $note, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run can have its earning/deduction items adjusted.'];
        }
        if ($run['sync_process_id'] === null) {
            return ['status' => false, 'message' => 'This adjustment only applies to a run pulled from synced attendance data.'];
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

            $actionDesc = $action === 'exclude' ? 'excluded' : ('override amount ' . number_format($overrideAmount, 2));
            $this->logAudit($runId, 'draft', 'draft', 'line_override_save', $userId,
                "Employee {$employeeNo}: {$itemCode} -> {$actionDesc}" . ($note ? " (note: {$note})" : ''));

            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }

        return $this->recalculate($runId, $compId, $userId, $isAdmin);
    }

    /** Removes a line override (reverts that item back to its computed default), then recalculates. */
    public function lineOverrideRemove(int $runId, int $compId, int $employeeId, string $itemCode, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
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

        $this->db->prepare("DELETE FROM `payroll_run_line_overrides` WHERE run_id = :run_id AND employee_id = :employee_id AND item_code = :item_code")
            ->execute([':run_id' => $runId, ':employee_id' => $employeeId, ':item_code' => $itemCode]);

        $this->logAudit($runId, 'draft', 'draft', 'line_override_remove', $userId,
            "Employee " . ($employeeNo !== false ? $employeeNo : $employeeId) . ": {$itemCode} reverted to computed default");

        return $this->recalculate($runId, $compId, $userId, $isAdmin);
    }

    /**
     * Read-only, for the "Sync Deduction Adjustments" section of the Manage Items modal: re-runs
     * SyncPayResolver for this employee (same inputs recalculate() itself would use) to get the RAW
     * computed deduction lines pre-override, then attaches whichever override (if any) currently
     * applies to each. Returns [] for a non-sync run -- there is nothing to adjust otherwise.
     */
    public function syncDeductionLinesForEmployee(int $compId, int $runId, int $employeeId): array {
        $run = $this->get($runId, $compId);
        if (!$run || $run['sync_process_id'] === null) {
            return [];
        }
        $stmtSync = $this->db->prepare("SELECT * FROM `payroll_sync_items`
            WHERE process_id = :process_id AND employee_id = :employee_id AND mapping_status = 'mapped'
            ORDER BY id DESC LIMIT 1");
        $stmtSync->execute([':process_id' => $run['sync_process_id'], ':employee_id' => $employeeId]);
        $syncRow = $stmtSync->fetch(PDO::FETCH_ASSOC);
        if (!$syncRow) {
            return [];
        }
        $syncRow['item_values'] = $syncRow['item_values'] !== null ? json_decode((string)$syncRow['item_values'], true) : [];

        // 2026-08-26: base_salary_amount is now AES-256-GCM ciphertext -- see recalculate()'s own
        // decrypt comment for the reasoning/convention this follows.
        $stmtBase = $this->db->prepare("SELECT base_salary_amount, key_version FROM `employees` WHERE id = :id AND comp_id = :comp_id");
        $stmtBase->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $baseRow = $stmtBase->fetch(PDO::FETCH_ASSOC);
        $baseSalary = (float)EmployeeModel::decryptSalaryValue($baseRow['base_salary_amount'] ?? null, isset($baseRow['key_version']) ? (int)$baseRow['key_version'] : null);

        // Reflects any raw-attendance-number correction too (2026-08-21), so the "Computed" figure
        // shown alongside the $-amount override always matches what recalculate() would actually
        // produce right now, not a stale pre-correction figure.
        $stmtAttOv = $this->db->prepare("SELECT * FROM `payroll_run_sync_item_overrides` WHERE run_id = :run_id AND employee_id = :employee_id");
        $stmtAttOv->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        $attendanceOverride = $stmtAttOv->fetch(PDO::FETCH_ASSOC) ?: [];

        $result = $this->syncPayResolver->resolve($compId, $syncRow, $baseSalary, $attendanceOverride);

        $stmtOv = $this->db->prepare("SELECT item_code, action, override_amount, note FROM `payroll_run_line_overrides` WHERE run_id = :run_id AND employee_id = :employee_id");
        $stmtOv->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        $overrides = [];
        foreach ($stmtOv->fetchAll(PDO::FETCH_ASSOC) as $ov) {
            $overrides[$ov['item_code']] = $ov;
        }

        return array_map(function (array $line) use ($overrides): array {
            $ov = $overrides[$line['code']] ?? null;
            return [
                'code' => $line['code'],
                'name_th' => $line['name_th'],
                'name_en' => $line['name_en'],
                'computed_amount' => (float)$line['amount'],
                'override_action' => $ov['action'] ?? null,
                'override_amount' => $ov['override_amount'] !== null ? (float)$ov['override_amount'] : null,
                'override_note' => $ov['note'] ?? null,
            ];
        }, $result['deduction']);
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
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run can have its earning/deduction items adjusted.'];
        }
        if ($run['sync_process_id'] === null) {
            return ['status' => false, 'message' => 'This adjustment only applies to a run pulled from synced attendance data.'];
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

            $stmtExisting = $this->db->prepare("SELECT id FROM `payroll_run_sync_item_overrides` WHERE run_id = :run_id AND employee_id = :employee_id");
            $stmtExisting->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
            $existingId = $stmtExisting->fetchColumn();

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

            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }

        return $this->recalculate($runId, $compId, $userId, $isAdmin);
    }

    /** Reverts every field back to whatever Origami actually sent, then recalculates. */
    public function attendanceOverrideRemove(int $runId, int $compId, int $employeeId, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
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

        $this->db->prepare("DELETE FROM `payroll_run_sync_item_overrides` WHERE run_id = :run_id AND employee_id = :employee_id")
            ->execute([':run_id' => $runId, ':employee_id' => $employeeId]);

        $this->logAudit($runId, 'draft', 'draft', 'attendance_override_remove', $userId,
            "Employee " . ($employeeNo !== false ? $employeeNo : $employeeId) . ": all fields reset to synced values");

        return $this->recalculate($runId, $compId, $userId, $isAdmin);
    }

    /**
     * Read-only, for the UI: the raw synced values side by side with whatever override is currently
     * active (or all-null if none), for the same 7 fields ATTENDANCE_OVERRIDE_FIELDS covers.
     */
    public function attendanceDataForEmployee(int $compId, int $runId, int $employeeId): array {
        $run = $this->get($runId, $compId);
        if (!$run || $run['sync_process_id'] === null) {
            return ['synced' => [], 'override' => []];
        }
        $stmtSync = $this->db->prepare("SELECT " . implode(', ', self::ATTENDANCE_OVERRIDE_FIELDS) . " FROM `payroll_sync_items`
            WHERE process_id = :process_id AND employee_id = :employee_id AND mapping_status = 'mapped'
            ORDER BY id DESC LIMIT 1");
        $stmtSync->execute([':process_id' => $run['sync_process_id'], ':employee_id' => $employeeId]);
        $synced = $stmtSync->fetch(PDO::FETCH_ASSOC) ?: array_fill_keys(self::ATTENDANCE_OVERRIDE_FIELDS, null);

        $stmtOv = $this->db->prepare("SELECT " . implode(', ', self::ATTENDANCE_OVERRIDE_FIELDS) . " FROM `payroll_run_sync_item_overrides`
            WHERE run_id = :run_id AND employee_id = :employee_id");
        $stmtOv->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        $override = $stmtOv->fetch(PDO::FETCH_ASSOC) ?: array_fill_keys(self::ATTENDANCE_OVERRIDE_FIELDS, null);

        $castNumeric = fn(array $row) => array_map(fn($v) => $v !== null ? (float)$v : null, $row);
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
        return $row;
    }

    /**
     * Current per-run tax/SSO exemption for one employee, or zeroed defaults if none saved yet.
     */
    public function getEmployeeExemption(int $runId, int $compId, int $employeeId): array {
        $stmt = $this->db->prepare("SELECT exempt_tax, exempt_sso, note FROM `payroll_run_employee_exemptions`
            WHERE run_id = :run_id AND employee_id = :employee_id");
        $stmt->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['exempt_tax' => false, 'exempt_sso' => false, 'note' => null];
        }
        return ['exempt_tax' => (bool)$row['exempt_tax'], 'exempt_sso' => (bool)$row['exempt_sso'], 'note' => $row['note']];
    }

    /**
     * Marks (or clears, when both flags are false) an employee as exempt from tax calculation
     * and/or SSO submission for THIS RUN ONLY (2026-08-21, explicit request: "จัดการได้ว่า คนนี้ไม่
     * ต้องคำนวณภาษี ไม่นำส่งประกันสังคมในรอบนี้") -- does not touch the employee's own permanent
     * tax_exempt/sso_enrolled columns. Applied by recalculate()'s Pass 2 the next time it runs (see
     * that method's prefetch of payroll_run_employee_exemptions), same "mutate then recalculate
     * immediately" pattern as every other per-run override save in this class. When both flags are
     * false, the row is deleted outright rather than kept as an all-zero row -- keeps
     * exemptionsByEmployee's prefetch in recalculate() empty for a run nobody has touched, and
     * getEmployeeExemption() already returns the same zeroed shape for "no row" as for "a row of
     * zeros" so callers can't tell the difference anyway.
     */
    public function saveEmployeeExemption(int $runId, int $compId, int $employeeId, bool $exemptTax, bool $exemptSso, ?string $note, int $userId, bool $isAdmin): array {
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
            return ['status' => false, 'message' => 'You do not have permission to edit this payroll run.'];
        }
        $run = $this->get($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($run['state'] !== 'draft') {
            return ['status' => false, 'message' => 'Only a draft payroll run can have its employee exemptions adjusted.'];
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
            if (!$exemptTax && !$exemptSso) {
                $this->db->prepare("DELETE FROM `payroll_run_employee_exemptions` WHERE run_id = :run_id AND employee_id = :employee_id")
                    ->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
                $this->logAudit($runId, 'draft', 'draft', 'employee_exemption_remove', $userId, "Employee {$employeeNo}: exemptions cleared.");
            } else {
                $this->db->prepare("INSERT INTO `payroll_run_employee_exemptions` (run_id, employee_id, exempt_tax, exempt_sso, note, created_by)
                    VALUES (:run_id, :employee_id, :exempt_tax, :exempt_sso, :note, :created_by)
                    ON DUPLICATE KEY UPDATE exempt_tax = VALUES(exempt_tax), exempt_sso = VALUES(exempt_sso), note = VALUES(note),
                        updated_by = VALUES(created_by), updated_at = CURRENT_TIMESTAMP")
                    ->execute([
                        ':run_id' => $runId, ':employee_id' => $employeeId,
                        ':exempt_tax' => $exemptTax ? 1 : 0, ':exempt_sso' => $exemptSso ? 1 : 0,
                        ':note' => $note, ':created_by' => $userId,
                    ]);
                $summary = trim(($exemptTax ? 'tax exempt' : '') . ($exemptTax && $exemptSso ? ', ' : '') . ($exemptSso ? 'SSO exempt' : ''), ', ');
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
                $allowed = $allowed || $this->userCan($userId, 'can_process_payroll', $isAdmin);
            }
        } elseif ($isAdmin) {
            $allowed = true;
        } else {
            $allowed = $fromState === 'pending_approval'
                ? ($this->canApproveThisRun($userId, $isAdmin, $run) || $this->userCan($userId, 'can_process_payroll', $isAdmin))
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
        return ['status' => true, 'message' => 'Approved.'];
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
        if (!$this->userCan($userId, 'can_process_payroll', $isAdmin)) {
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
