<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/PayrollRunModel.php';
require_once __DIR__ . '/../services/IdCodec.php';
require_once __DIR__ . '/../models/PermissionModel.php';
class PayrollController extends Controller {
    private $model;
    private PermissionModel $permissionModel;
    public function __construct(){
        $this->model = new PayrollRunModel();
        $this->permissionModel = new PermissionModel();
    }

    /**
     * 2026-08-31, explicit request: "สิทธิ์ในการมองเห็นเงินเดือน...จะเห็นเป็น XXXX แต่ยังสามารถคำนวณเงินเดือน
     * ...ได้ตามสิทธิ์" -- masking ONLY happens here, at the response-shaping layer, never inside
     * PayrollRunModel (recalculate()/getDetails()/etc. never call through this check at all, see
     * PermissionModel's own top-of-file docblock -- calculation stays correct regardless of who is
     * looking at the result). `own_only` scope has no single meaningful "subject" for a whole
     * payroll run's aggregate totals (a run isn't "owned" by one employee the way an Employee
     * Detail profile is) -- resolveSalaryVisibility() already degrades own_only to masked when no
     * $subjectEmployeeId is given, which is exactly the right behavior here: 'own_only' effectively
     * means "no access to run-level figures" for this module, only 'all' (or admin) sees them.
     */
    private function maskRunMonetaryFields(array $row, int $compId): array {
        $visibility = $this->permissionModel->resolveSalaryVisibility($this->userId(), 'payroll_process', $this->isAdmin(), $compId);
        if ($visibility['full']) {
            return $row;
        }
        foreach (['total_gross_amount', 'total_deduction_amount', 'total_net_amount'] as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = PermissionModel::MASK_VALUE;
            }
        }
        return $row;
    }

    /**
     * Same decision, applied to the Detail page's own per-employee `details` rows (getDetails()'s
     * own return shape) -- 'full' shows everything unchanged; 'masked' (no grant at all) replaces
     * every monetary field AND every itemized breakdown line's amount; 'summary_only'
     * (detail_level='summary') shows the row-level totals but still masks the itemized breakdown
     * lines -- the same "summary vs full" distinction PermissionModel's own docblock describes.
     */
    private function maskRunDetailRows(array $details, int $compId): array {
        $visibility = $this->permissionModel->resolveSalaryVisibility($this->userId(), 'payroll_process', $this->isAdmin(), $compId);
        if ($visibility['full']) {
            return $details;
        }
        $totalsFields = ['base_salary_amount', 'gross_amount', 'taxable_gross_amount', 'total_deduction_amount', 'net_amount', 'employer_cost_amount'];
        $breakdownFields = ['earning_breakdown', 'deduction_breakdown', 'statutory_breakdown'];
        foreach ($details as &$d) {
            if ($visibility['masked']) {
                foreach ($totalsFields as $field) {
                    if (array_key_exists($field, $d)) {
                        $d[$field] = PermissionModel::MASK_VALUE;
                    }
                }
            }
            // Itemized lines are masked whenever the caller doesn't have FULL detail -- both the
            // fully-masked case above AND the summary_only case (row totals stay visible, but the
            // line-by-line breakdown does not).
            if ($visibility['masked'] || $visibility['summary_only']) {
                foreach ($breakdownFields as $field) {
                    if (!empty($d[$field]) && is_array($d[$field])) {
                        foreach ($d[$field] as &$line) {
                            if (!is_array($line)) {
                                continue;
                            }
                            // earning_breakdown/deduction_breakdown lines use 'amount';
                            // statutory_breakdown lines use 'employee_amount'/'employer_amount'
                            // (StatutoryCalculationEngine's own shape) -- mask whichever are present.
                            foreach (['amount', 'employee_amount', 'employer_amount'] as $amountKey) {
                                if (array_key_exists($amountKey, $line)) {
                                    $line[$amountKey] = PermissionModel::MASK_VALUE;
                                }
                            }
                        }
                        unset($line);
                    }
                }
            }
        }
        unset($d);
        return $details;
    }

    public function index() {
        $this->view('payroll/index');
    }

    public function approvalQueue() {
        $this->view('payroll/approval');
    }

    public function detail($id = null) {
        // {id} in the route is an IdCodec-encoded token (e.g. /payroll-process/AbC12-xY==), not
        // the raw payroll_runs.id -- see IdCodec's own docblock for why. A stale/forged/garbage
        // token just fails to decode and 404s, same as an out-of-range numeric id used to.
        $runId = $id ? IdCodec::decode((string)$id) : null;
        if ($runId === null) {
            http_response_code(404);
            echo '404 - Not Found';
            return;
        }
        // 2026-08-31, same-day follow-up (item 9c): log every open of this page, not every AJAX
        // data-refresh the page itself later triggers -- see PayrollRunModel::logViewDetail()'s own
        // docblock. compId may legitimately be 0 (no active company selected yet) -- logViewDetail()
        // itself no-ops cleanly when the run/company don't match, same as any bad/stale id.
        $compId = (int)(getCompId() ?? 0);
        if ($compId > 0) {
            $this->model->logViewDetail($runId, $compId, $this->userId());
        }
        $this->view('payroll/detail', ['runId' => $runId]);
    }

    public function options() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => ['items' => [], 'total_count' => 0]]);
            return;
        }
        $page = intval($_POST['page'] ?? 1);
        $limit = intval($_POST['limit'] ?? 10);
        $search = (string)($_POST['searchTerm'] ?? '');
        $statesParam = (string)($_POST['states'] ?? '');
        $allowedStates = $statesParam !== '' ? explode(',', $statesParam) : null;
        // 2026-09-01: data-exclude-id, same generic convention every other select2-remote field in
        // this app already uses -- first consumer here is the Detail page's own "Target Round"
        // merge-target picker, excluding the run being edited from its own dropdown.
        $excludeId = !empty($_POST['exclude_id']) ? (int)$_POST['exclude_id'] : null;
        $data = $this->model->options((int)$compId, $search, $page, $limit, $allowedStates, $excludeId);
        $this->json(['status' => true, 'data' => $data]);
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    // 2026-09-02, explicit request (following up on a Permission Matrix design review that surfaced
    // this module had ZERO checks against the `permissions`/`role_permissions` RBAC tables at all --
    // only the older, separate structure_roles.can_process_payroll/can_approve_payroll/
    // can_finalize_payroll flat-role-flag system, see PayrollRunModel::userCan()) -- adds
    // payroll_run.view/payroll_run.manage as an ADDITIVE gate on top of that legacy system, not a
    // replacement. Deliberately leaves submit/approve/reject/markPaid/lock/reopen/cancel/
    // requestInfo/bulk* UNTOUCHED -- those already have their own well-tested, much more granular
    // authorization (the Approval Workflow engine + can_approve_payroll/can_finalize_payroll +
    // department-scoping, see CLAUDE.md's own "Approval Workflow" section for the real bugs already
    // found/fixed there); layering a coarse RBAC gate on top risked conflicting with logic that's
    // already correct. See database/migrations/2026-09-02_3_payroll_run_permission_gate.sql's own
    // docblock -- that migration ALSO backfills role_permissions for every role that already has the
    // corresponding legacy flag set, so no existing non-admin user loses access the moment this ships.
    private function requirePermission(string $permissionKey): bool {
        $compId = (int)getCompId();
        $check = $this->permissionModel->checkPermission($this->userId(), $permissionKey, $this->isAdmin(), $compId);
        if (!$check['allowed']) {
            $this->json(['status' => false, 'message' => 'You do not have permission to perform this action.']);
            return false;
        }
        return true;
    }

    /** Any of the 3 payroll permission keys (payroll_run.process/.approve/.finalize) grants read
     *  access -- mutating actions already check the SPECIFIC key they need inside PayrollRunModel.
     *  2026-09-02: ALSO requires payroll_run.view (RBAC-layer, additive -- see this method's own
     *  top-of-file comment above). */
    private function requireViewAccess(): bool {
        if (!$this->model->canView($this->userId(), $this->isAdmin())) {
            $this->json(['status' => false, 'message' => 'You do not have permission to view payroll data.']);
            return false;
        }
        return $this->requirePermission('payroll_run.view');
    }

    // 2026-09-03, Platform Hardening Phase 3: requireManageAccess() (the coarse payroll_run.manage
    // gate that used to sit in front of every mutating method below) is retired -- every call site
    // now checks the SPECIFIC action it actually performs (payroll_run.process/.add/.edit/.delete)
    // directly via requirePermission(), matching the full CRUD/verb granularity payroll_run's
    // permission rows now have (see database/migrations/2026-09-03_3_payroll_run_permission_split.sql).
    // approve()/reject()/requestInfo()/revert()/bulkApprove()/bulkReject()/bulkRequestInfo()/cancel()/
    // markPaid()/lock()/reopen() are DELIBERATELY left with no controller-level gate here, same as
    // before this change -- their real authorization may come from the Approval Workflow engine's own
    // per-run eligibility snapshot (see PayrollRunModel::canApproveThisRun()'s own docblock), which is
    // completely independent of any company-wide permission grant; a blanket controller-level
    // payroll_run.approve check here would wrongly refuse a legitimate engine-eligible approver who
    // doesn't happen to also hold that coarse grant. Each of those methods' own model-level check
    // (already correctly engine-aware) remains the sole authority, exactly as the original
    // 2026-09-02 migration's own docblock reasoned for the same set of methods.

    public function list() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => true, 'data' => []]);
            return;
        }
        $filters = [
            'state' => (string)($_GET['state'] ?? ''),
            'date_from' => (string)($_GET['date_from'] ?? ''),
            'date_to' => (string)($_GET['date_to'] ?? ''),
        ];
        // approval_queue=1 (2026-08-24): sent only by the Approval Queue page (approval.js) -- the
        // Process List page (index.js) hits this same endpoint without it and must keep seeing
        // every run regardless of who can approve it. See PayrollRunModel::list()'s own docblock.
        $approvalQueueOnly = (string)($_GET['approval_queue'] ?? '') === '1';
        $rows = $this->model->list((int)$compId, $filters, $this->userId(), $this->isAdmin(), $approvalQueueOnly);
        // public_id is the IdCodec-encoded token used for the /payroll-process/{id} browser URL
        // (row-click navigation, the View action button) -- 'id' itself stays the raw numeric PK,
        // still used as-is for every internal AJAX call (api/payroll-run.get?id=, save/submit/
        // approve/etc. payloads), same as every other list/detail endpoint in this app. Only the
        // URL a user can see/bookmark/share gets obfuscated.
        // can_finalize_payroll (2026-08-27, gates the mini-timeline's own Mark as Paid/Lock quick
        // actions, see index.js's miniTimelineQuickActionHtml()) -- unlike can_approve_payroll,
        // this isn't department-scoped per row, so it's the same value for every row in the
        // response; computed once outside the loop rather than once per row.
        $canFinalize = $this->model->canFinalizePayroll($this->userId(), $this->isAdmin());
        foreach ($rows as &$row) {
            $row['public_id'] = IdCodec::encode((int)$row['id']);
            $row['can_finalize_payroll'] = $canFinalize;
            $row = $this->maskRunMonetaryFields($row, (int)$compId);
        }
        unset($row);
        $this->json(['status' => true, 'data' => $rows]);
    }

    public function get() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $row = $this->model->get($id, (int)$compId);
        if (!$row) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        // NOTE: can_approve_payroll/can_process_payroll/can_finalize_payroll are computed on the
        // UNMASKED $row (they only ever read state/approval_request_id fields, never money) --
        // masking is applied last, right before the response goes out, so it can never accidentally
        // feed a masked value into an approval/permission decision.
        $row['details'] = $this->model->getDetails($id, (int)$compId);
        $row['audit_log'] = $this->model->getAuditLog($id, (int)$compId);
        $row['approval_flow'] = $this->model->approvalFlow($id, (int)$compId);
        $row['can_approve_payroll'] = $this->model->canApprovePayroll($this->userId(), $this->isAdmin(), $row);
        $row['can_process_payroll'] = $this->model->canProcessPayroll($this->userId(), $this->isAdmin());
        $row['can_finalize_payroll'] = $this->model->canFinalizePayroll($this->userId(), $this->isAdmin());
        // 2026-08-29: backs the Print Reports dropdown's own tax/SSO-report hiding -- see
        // PayrollRunModel::calcApplicabilitySummary()'s own docblock.
        $calcApplicability = $this->model->calcApplicabilitySummary($id, (int)$compId);
        $row['any_tax_applicable'] = $calcApplicability['any_tax'];
        $row['any_sso_applicable'] = $calcApplicability['any_sso'];
        // 2026-08-31, explicit request: "สิทธิ์ในการมองเห็นเงินเดือน...จะเห็นเป็น XXXX แต่ยังสามารถคำนวณ
        // เงินเดือน...ได้ตามสิทธิ์" -- see maskRunMonetaryFields()/maskRunDetailRows()'s own docblocks.
        $row['details'] = $this->maskRunDetailRows($row['details'], (int)$compId);
        $row = $this->maskRunMonetaryFields($row, (int)$compId);
        $this->json(['status' => true, 'data' => $row]);
    }

    /** Feeds the Approval Timeline modal on the Approval Queue page -- that page's own list
     *  endpoint stays lean (one row per run, no audit log/approver breakdown) since most rows'
     *  timeline never gets opened; this is fetched on demand only when the modal opens. */
    public function approvalTimeline() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $run = $this->model->get($id, (int)$compId);
        if (!$run) {
            $this->json(['status' => false, 'message' => 'Record not found.']);
            return;
        }
        // Full run row (not just id/run_name/state) -- the Approval Flow modal's Created/Paid
        // stages need created_at/created_by_name_*/paid_at/approved_at/etc. too.
        // audit_log removed -- this modal's History section was cut, no other consumer of this endpoint.
        $run['approval_flow'] = $this->model->approvalFlow($id, (int)$compId);
        $run['can_approve_payroll'] = $this->model->canApprovePayroll($this->userId(), $this->isAdmin(), $run);
        $this->json(['status' => true, 'data' => $run]);
    }

    public function save() {
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $id = !empty($data['id']) ? (int)$data['id'] : null;
        // 2026-09-03, Platform Hardening Phase 3: the permission check needs to know add-vs-edit
        // BEFORE calling the model, same branch the model itself uses (id present = update).
        if (!$this->requirePermission($id ? 'payroll_run.edit' : 'payroll_run.add')) return;
        $result = $id
            ? $this->model->update($id, (int)$compId, $data, $this->userId(), $this->isAdmin())
            : $this->model->create((int)$compId, $data, $this->userId(), $this->isAdmin());
        $this->json($result);
    }

    /** 2026-09-09, round-creation flow audit Bug 2 fix -- read-only preview for the "future round"
     *  merge-target mode's own auto-matching (cycle+payment-month), so the Create/Edit forms can show
     *  the admin exactly which existing run(s) a save would resolve against BEFORE they click Save,
     *  instead of it happening silently server-side inside resolveMergeTargetSpec(). See
     *  PayrollRunModel::previewFutureCycleMergeTarget()'s own docblock. Gated the same as every other
     *  read-only lookup on this controller (requireViewAccess()), not payroll_run.add/.edit -- viewing
     *  which runs already exist doesn't need create/edit rights, only the eventual Save does. */
    public function previewMergeTarget() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $cycleId = isset($_GET['cycle_id']) ? (int)$_GET['cycle_id'] : 0;
        $periodStart = isset($_GET['period_start_date']) ? (string)$_GET['period_start_date'] : '';
        $excludeId = !empty($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : null;
        if (!$compId || $cycleId <= 0 || $periodStart === '') {
            $this->json(['status' => false, 'message' => 'Missing cycle_id/period_start_date.']);
            return;
        }
        $this->json($this->model->previewFutureCycleMergeTarget((int)$compId, $cycleId, $periodStart, $excludeId));
    }

    /** 2026-08-31, PAYROLL_SYNC_API.md `attribution` revision -- Pending Pull's "Merge into Target"
     *  action for a supplemental process attributed tax_treatment='merge'. See
     *  PayrollRunModel::mergeSupplementalIntoRun()'s own docblock for the full mechanism. */
    public function mergeSupplemental() {
        if (!$this->requirePermission('payroll_run.process')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $syncProcessId = (is_array($data) && isset($data['sync_process_id'])) ? (int)$data['sync_process_id'] : 0;
        if (!$compId || $syncProcessId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing sync_process_id.']);
            return;
        }
        // 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย") -- explicit opt-in to auto-revert a
        // pending_approval/approved/rejected/need_info (not yet paid) target back to draft before
        // merging. Defaults false, same safe refusal as before this flag existed.
        $allowRevert = !empty($data['allow_revert_non_draft_target']);
        // Separate, higher-risk opt-in: reopen a paid/locked target (money may have already
        // moved) via PayrollRunModel::reopen() -- see mergeSupplementalIntoRun()'s own docblock.
        $allowReopen = !empty($data['allow_reopen_paid_target']);
        $this->json($this->model->mergeSupplementalIntoRun($syncProcessId, (int)$compId, $this->userId(), $this->isAdmin(), $allowRevert, $allowReopen));
    }

    /** 2026-09-01, explicit request: "ให้มี radio เลือกว่า เปิดรอบใหม่ หรืออ้างอิงถึงรอบ" -- the plain
     *  "Add" flow's own equivalent of mergeSupplemental() above, for a run the admin built up
     *  manually (never Origami-sourced). See PayrollRunModel::mergeIntoExistingRun()'s own docblock. */
    public function mergeIntoExistingRun() {
        if (!$this->requirePermission('payroll_run.process')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $sourceRunId = (is_array($data) && isset($data['source_run_id'])) ? (int)$data['source_run_id'] : 0;
        $targetRunId = (is_array($data) && isset($data['target_run_id'])) ? (int)$data['target_run_id'] : 0;
        if (!$compId || $sourceRunId <= 0 || $targetRunId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing source_run_id/target_run_id.']);
            return;
        }
        $allowRevert = !empty($data['allow_revert_non_draft_target']);
        $allowReopen = !empty($data['allow_reopen_paid_target']);
        $this->json($this->model->mergeIntoExistingRun($sourceRunId, $targetRunId, (int)$compId, $this->userId(), $this->isAdmin(), $allowRevert, $allowReopen));
    }

    public function delete() {
        if (!$this->requirePermission('payroll_run.delete')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->delete($id, (int)$compId, $this->userId(), $this->isAdmin()));
    }

    public function recalculate() {
        if (!$this->requirePermission('payroll_run.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->recalculate($id, (int)$compId, $this->userId(), $this->isAdmin()));
    }

    /** Server-side DataTable source for the "Join Employees" picker modal on a genuine off-cycle run. */
    public function manualEmployeeOptions() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_POST['run_id'] ?? 0);
        if (!$compId || $runId <= 0) {
            $this->json(['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
            return;
        }
        $start = intval($_POST['start'] ?? 0);
        $length = intval($_POST['length'] ?? 10);
        $filters = [
            'department_id' => $_POST['department_id'] ?? '',
            'team_id' => $_POST['team_id'] ?? '',
            'position_id' => $_POST['position_id'] ?? '',
            'emp_cycle_id' => $_POST['emp_cycle_id'] ?? '',
        ];
        $search = (string)($_POST['search']['value'] ?? '');
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $columnFilters = is_array($_POST['column_filters'] ?? null) ? $_POST['column_filters'] : [];
        $res = $this->model->manualEmployeeOptions((int)$compId, $runId, $start, $length, $filters, $search, (string)$lang, $columnFilters);
        $this->json([
            'draw' => intval($_POST['draw'] ?? 1),
            'recordsTotal' => $res['total'],
            'recordsFiltered' => $res['filtered'],
            'data' => $res['data'],
        ]);
    }
    /** 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter rollout. */
    public function manualEmployeeColumnValues() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_POST['run_id'] ?? 0);
        if (!$compId || $runId <= 0) {
            $this->json(['status' => false, 'values' => []]);
            return;
        }
        $filters = [
            'department_id' => $_POST['department_id'] ?? '',
            'team_id' => $_POST['team_id'] ?? '',
            'position_id' => $_POST['position_id'] ?? '',
            'emp_cycle_id' => $_POST['emp_cycle_id'] ?? '',
        ];
        $column = (string)($_POST['column'] ?? '');
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $columnFilters = is_array($_POST['column_filters'] ?? null) ? $_POST['column_filters'] : [];
        $values = $this->model->manualEmployeeColumnValues((int)$compId, $runId, $filters, $column, (string)$lang, $columnFilters);
        $this->json(['status' => true, 'values' => $values]);
    }

    /** 2026-08-24, explicit request ("จัดรูปแบบให้การดึงพนักงานเข้ามาในการคำนวณดำเนินการได้ง่ายที่สุด") --
     *  "Select all N matching" for the Join Employees picker: every id matching the current filter/
     *  search, not just the current DataTable page. Separate endpoint from manualEmployeeOptions()
     *  (that one stays paginated for the table itself) so the picker's live DataTable ajax traffic
     *  is untouched -- this is only called once, when the user clicks "Select All". */
    public function manualEmployeeAllIds() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_POST['run_id'] ?? 0);
        if (!$compId || $runId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid run.']);
            return;
        }
        $filters = [
            'department_id' => $_POST['department_id'] ?? '',
            'team_id' => $_POST['team_id'] ?? '',
            'position_id' => $_POST['position_id'] ?? '',
            'emp_cycle_id' => $_POST['emp_cycle_id'] ?? '',
        ];
        $search = (string)($_POST['search'] ?? '');
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $columnFilters = is_array($_POST['column_filters'] ?? null) ? $_POST['column_filters'] : [];
        $ids = $this->model->manualEmployeeAllIds((int)$compId, $runId, $filters, $search, (string)$lang, $columnFilters);
        $this->json(['status' => true, 'employee_ids' => $ids]);
    }

    public function joinEmployees() {
        if (!$this->requirePermission('payroll_run.add')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeIds = (is_array($data) && isset($data['employee_ids']) && is_array($data['employee_ids'])) ? $data['employee_ids'] : [];
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->joinEmployees($id, (int)$compId, $employeeIds, $this->userId(), $this->isAdmin()));
    }

    public function removeEmployee() {
        if (!$this->requirePermission('payroll_run.delete')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        if (!$compId || $id <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->removeManualEmployee($id, (int)$compId, $employeeId, $this->userId(), $this->isAdmin()));
    }

    public function manualLinesForEmployee() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_GET['run_id'] ?? 0);
        $employeeId = intval($_GET['employee_id'] ?? 0);
        if (!$compId || $runId <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->manualLinesForEmployee((int)$compId, $runId, $employeeId)]);
    }

    public function addManualLine() {
        if (!$this->requirePermission('payroll_run.add')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        // ped_type_id is optional now -- omitted (or 0) means a custom, not-in-the-catalog item
        // instead (custom_item_name + custom_item_type), see PayrollRunModel::addManualLine()'s
        // docblock. At least one of the two forms must be present, checked below.
        $pedTypeIdRaw = (is_array($data) && isset($data['ped_type_id'])) ? (int)$data['ped_type_id'] : 0;
        $pedTypeId = $pedTypeIdRaw > 0 ? $pedTypeIdRaw : null;
        $amount = (is_array($data) && isset($data['amount']) && is_numeric($data['amount'])) ? (float)$data['amount'] : 0.0;
        $note = (is_array($data) && isset($data['note'])) ? (string)$data['note'] : null;
        $customItemName = (is_array($data) && isset($data['custom_item_name'])) ? (string)$data['custom_item_name'] : null;
        $customItemType = (is_array($data) && isset($data['custom_item_type'])) ? (string)$data['custom_item_type'] : null;
        $payeeEmployeeIdRaw = (is_array($data) && isset($data['payee_employee_id'])) ? (int)$data['payee_employee_id'] : 0;
        $payeeEmployeeId = $payeeEmployeeIdRaw > 0 ? $payeeEmployeeIdRaw : null;
        // 2026-08-31, same-day follow-up ("รายการหัก...ในหน้าทำรอบ...ให้เพิ่มเติมตรงที่หักไปที่ไหน") --
        // see PayrollRunModel::addManualLine()'s own docblock for validation/defaulting.
        $payeeType = (is_array($data) && !empty($data['payee_type'])) ? (string)$data['payee_type'] : null;
        $includeInCashSummary = (is_array($data) && array_key_exists('include_in_cash_summary', $data)) ? (bool)$data['include_in_cash_summary'] : null;
        // 2026-09-02, Deduction Destination & Third-Party Remittance -- only meaningful when
        // payee_type='other_person'; PayrollRunModel::addManualLine()/PaymentDestinationModel
        // itself validate the shape, this layer just passes it through untouched.
        $destinationData = (is_array($data) && isset($data['destination']) && is_array($data['destination'])) ? $data['destination'] : null;
        // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7.
        $isOther = (is_array($data) && !empty($data['is_other'])) ? true : null;
        if (!$compId || $id <= 0 || $employeeId <= 0 || ($pedTypeId === null && ($customItemName === null || trim($customItemName) === ''))) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->addManualLine($id, (int)$compId, $employeeId, $pedTypeId, $amount, $this->userId(), $this->isAdmin(), $note, $customItemName, $customItemType, $payeeEmployeeId, $payeeType, $includeInCashSummary, $destinationData, $isOther));
    }

    public function removeManualLine() {
        if (!$this->requirePermission('payroll_run.delete')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $lineId = (is_array($data) && isset($data['line_id'])) ? (int)$data['line_id'] : 0;
        if (!$compId || $id <= 0 || $lineId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->removeManualLine($id, (int)$compId, $lineId, $this->userId(), $this->isAdmin()));
    }

    /* ==================== SYNC DEDUCTION LINE OVERRIDES (2026-08-21) ==================== */

    public function syncLinesForEmployee() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_GET['run_id'] ?? 0);
        $employeeId = intval($_GET['employee_id'] ?? 0);
        if (!$compId || $runId <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        // 2026-08-29: bundles this employee's per-run tax/SSO calculation override alongside the
        // line-override list (same "always pull fresh, one fetch per modal open" convention as the
        // Raw Sync Data modal used to have for this same data) -- the "Tax & SSO" tab of the Manage
        // Items modal (universal, not sync-only, per explicit request) reads this `exemption` key.
        // `run_settings` (item_options + excluded_item_codes, from the SAME source Run Settings'
        // own panel uses) additionally backs this tab's own item-exclusion checklist (2026-08-29
        // follow-up: "อยากให้มี List รายการและติ๊กเข้าออกได้เหมือนตอนที่ Set ทั้ง Template").
        $this->json([
            'status' => true,
            'data' => $this->model->syncDeductionLinesForEmployee((int)$compId, $runId, $employeeId),
            'exemption' => $this->model->getEmployeeExemption($runId, (int)$compId, $employeeId),
            'run_settings' => $this->model->runSettingsGet($runId, (int)$compId)['data'] ?? null,
        ]);
    }

    public function lineOverrideSave() {
        if (!$this->requirePermission('payroll_run.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $itemCode = (is_array($data) && isset($data['item_code'])) ? (string)$data['item_code'] : '';
        $action = (is_array($data) && isset($data['action'])) ? (string)$data['action'] : '';
        $overrideAmount = (is_array($data) && isset($data['override_amount']) && is_numeric($data['override_amount'])) ? (float)$data['override_amount'] : null;
        $note = (is_array($data) && isset($data['note'])) ? (string)$data['note'] : null;
        if (!$compId || $id <= 0 || $employeeId <= 0 || $itemCode === '') {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->lineOverrideSave($id, (int)$compId, $employeeId, $itemCode, $action, $overrideAmount, $note, $this->userId(), $this->isAdmin()));
    }

    public function lineOverrideRemove() {
        if (!$this->requirePermission('payroll_run.delete')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $itemCode = (is_array($data) && isset($data['item_code'])) ? (string)$data['item_code'] : '';
        if (!$compId || $id <= 0 || $employeeId <= 0 || $itemCode === '') {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->lineOverrideRemove($id, (int)$compId, $employeeId, $itemCode, $this->userId(), $this->isAdmin()));
    }

    // 2026-08-31, same-day follow-up ("ทำทั้ง 3 ข้อเลย" -- item 9a): same shape as
    // lineOverrideSave()/lineOverrideRemove() above, one level up (statutory item code, not a
    // general item_code) -- see PayrollRunModel::statutoryLineOverrideSave()'s own docblock.
    public function statutoryLineOverrideSave() {
        if (!$this->requirePermission('payroll_run.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $itemCode = (is_array($data) && isset($data['item_code'])) ? (string)$data['item_code'] : '';
        $action = (is_array($data) && isset($data['action'])) ? (string)$data['action'] : '';
        $overrideAmount = (is_array($data) && isset($data['override_amount']) && is_numeric($data['override_amount'])) ? (float)$data['override_amount'] : null;
        $note = (is_array($data) && isset($data['note'])) ? (string)$data['note'] : null;
        if (!$compId || $id <= 0 || $employeeId <= 0 || $itemCode === '') {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->statutoryLineOverrideSave($id, (int)$compId, $employeeId, $itemCode, $action, $overrideAmount, $note, $this->userId(), $this->isAdmin()));
    }

    public function statutoryLineOverrideRemove() {
        if (!$this->requirePermission('payroll_run.delete')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $itemCode = (is_array($data) && isset($data['item_code'])) ? (string)$data['item_code'] : '';
        if (!$compId || $id <= 0 || $employeeId <= 0 || $itemCode === '') {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->statutoryLineOverrideRemove($id, (int)$compId, $employeeId, $itemCode, $this->userId(), $this->isAdmin()));
    }

    /* ==================== RECURRING DEDUCTION DESTINATION OVERRIDES (2026-09-02, Deduction
       Destination & Third-Party Remittance, Phase 6) -- process-level override of a recurring
       deduction's payee, without touching the Employee Detail template row. ==================== */

    public function recurringDeductionDestinationsForEmployee() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = isset($_GET['run_id']) ? (int)$_GET['run_id'] : 0;
        $employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
        if (!$compId || $runId <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'data' => []]);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->recurringDeductionDestinationsForEmployee($runId, (int)$compId, $employeeId)]);
    }

    public function recurringDeductionDestinationOverrideSave() {
        if (!$this->requirePermission('payroll_run.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $recurringId = (is_array($data) && isset($data['recurring_id'])) ? (int)$data['recurring_id'] : 0;
        if (!$compId || $id <= 0 || $recurringId <= 0 || !is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->recurringDeductionDestinationOverrideSave($id, (int)$compId, $recurringId, $data, $this->userId(), $this->isAdmin()));
    }

    public function recurringDeductionDestinationOverrideRemove() {
        if (!$this->requirePermission('payroll_run.delete')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $recurringId = (is_array($data) && isset($data['recurring_id'])) ? (int)$data['recurring_id'] : 0;
        if (!$compId || $id <= 0 || $recurringId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->recurringDeductionDestinationOverrideRemove($id, (int)$compId, $recurringId, $this->userId(), $this->isAdmin()));
    }

    /* ==================== RAW ATTENDANCE DATA OVERRIDES (2026-08-21) ==================== */

    public function attendanceDataForEmployee() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_GET['run_id'] ?? 0);
        $employeeId = intval($_GET['employee_id'] ?? 0);
        if (!$compId || $runId <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->attendanceDataForEmployee((int)$compId, $runId, $employeeId)]);
    }

    public function attendanceOverrideSave() {
        if (!$this->requirePermission('payroll_run.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $fields = (is_array($data) && isset($data['fields']) && is_array($data['fields'])) ? $data['fields'] : [];
        $note = (is_array($data) && isset($data['note'])) ? (string)$data['note'] : null;
        if (!$compId || $id <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->attendanceOverrideSave($id, (int)$compId, $employeeId, $fields, $note, $this->userId(), $this->isAdmin()));
    }

    public function attendanceOverrideRemove() {
        if (!$this->requirePermission('payroll_run.delete')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        if (!$compId || $id <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->attendanceOverrideRemove($id, (int)$compId, $employeeId, $this->userId(), $this->isAdmin()));
    }

    /* ==================== RAW SYNC DATA VIEWER (2026-08-21) ==================== */

    public function rawSyncDataForEmployee() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_GET['run_id'] ?? 0);
        $employeeId = intval($_GET['employee_id'] ?? 0);
        if (!$compId || $runId <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $data = $this->model->rawSyncDataForEmployee((int)$compId, $runId, $employeeId);
        if ($data === null) {
            $this->json(['status' => false, 'message' => 'No raw sync data found for this employee on this run.']);
            return;
        }
        $this->json(['status' => true, 'data' => $data]);
    }

    /** 2026-08-29: `tax_calculate_override`/`sso_calculate_override` are tri-state strings
     *  ('inherit'/'yes'/'no') now, widened from the original force-off-only exempt_tax/exempt_sso
     *  booleans -- see PayrollRunModel::saveEmployeeExemption()'s own docblock. */
    public function saveEmployeeExemption() {
        if (!$this->requirePermission('payroll_run.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $taxCalculateOverride = (is_array($data) && isset($data['tax_calculate_override'])) ? (string)$data['tax_calculate_override'] : 'inherit';
        $ssoCalculateOverride = (is_array($data) && isset($data['sso_calculate_override'])) ? (string)$data['sso_calculate_override'] : 'inherit';
        $note = (is_array($data) && isset($data['note'])) ? (string)$data['note'] : null;
        if (!$compId || $id <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->saveEmployeeExemption($id, (int)$compId, $employeeId, $taxCalculateOverride, $ssoCalculateOverride, $note, $this->userId(), $this->isAdmin()));
    }

    /* ==================== Run Settings panel (2026-08-29) ==================== */

    public function runSettingsGet() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $runId = intval($_GET['id'] ?? 0);
        if (!$compId || $runId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->runSettingsGet($runId, (int)$compId));
    }

    public function runSettingsSave() {
        if (!$this->requirePermission('payroll_run.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $taxCalculateDefault = (is_array($data) && isset($data['tax_calculate_default'])) ? (string)$data['tax_calculate_default'] : 'use_employee_setting';
        $ssoCalculateDefault = (is_array($data) && isset($data['sso_calculate_default'])) ? (string)$data['sso_calculate_default'] : 'use_employee_setting';
        $excludedItemCodes = (is_array($data) && isset($data['excluded_item_codes']) && is_array($data['excluded_item_codes'])) ? $data['excluded_item_codes'] : [];
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->runSettingsSave($id, (int)$compId, $taxCalculateDefault, $ssoCalculateDefault, $excludedItemCodes, $this->userId(), $this->isAdmin()));
    }

    /**
     * 2026-08-31, explicit request: per-run "auto-recalculate immediately after edits" checkbox --
     * see PayrollRunModel::setAutoRecalculate()'s own docblock for the full design.
     */
    public function autoRecalculateSave() {
        if (!$this->requirePermission('payroll_run.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $value = is_array($data) && !empty($data['value']);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->setAutoRecalculate($id, (int)$compId, $value, $this->userId(), $this->isAdmin()));
    }

    /* ==================== Employee Verify / Lock / Comments (2026-08-29) ==================== */

    public function employeeVerifySave() {
        if (!$this->requirePermission('payroll_run.process')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $verified = is_array($data) && !empty($data['verified']);
        if (!$compId || $id <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->setEmployeeVerified($id, (int)$compId, $employeeId, $verified, $this->userId(), $this->isAdmin()));
    }

    public function employeeVerifyBulk() {
        if (!$this->requirePermission('payroll_run.process')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeIds = (is_array($data) && is_array($data['employee_ids'] ?? null)) ? array_map('intval', $data['employee_ids']) : [];
        $verified = is_array($data) && !empty($data['verified']);
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->bulkSetEmployeeVerified($id, (int)$compId, $employeeIds, $verified, $this->userId(), $this->isAdmin()));
    }

    /**
     * 2026-08-31, explicit request: "สามารถ Verify ทั้ง Process ได้เลย...ให้ Verify ได้ทั้ง Process ทั้ง
     * Detail และหน้า List" -- verifies every employee currently in the run at once. Reachable from
     * either page: the Detail page's own bulk bar area, or a per-row action on the List page (which
     * has no employee roster loaded client-side at all, hence a run_id-only endpoint rather than
     * requiring the caller to already know every employee_id).
     */
    public function employeeVerifyAll() {
        if (!$this->requirePermission('payroll_run.process')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->verifyAllEmployeesForRun($id, (int)$compId, $this->userId(), $this->isAdmin()));
    }

    public function employeeCommentAdd() {
        if (!$this->requirePermission('payroll_run.add')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $employeeId = (is_array($data) && isset($data['employee_id'])) ? (int)$data['employee_id'] : 0;
        $tag = (is_array($data) && !empty($data['tag'])) ? (string)$data['tag'] : null;
        $comment = (is_array($data) && isset($data['comment'])) ? (string)$data['comment'] : '';
        if (!$compId || $id <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->employeeCommentAdd($id, (int)$compId, $employeeId, $tag, $comment, $this->userId(), $this->isAdmin()));
    }

    public function errorEmployees() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->errorEmployeesForRun($id, (int)$compId)]);
    }

    // 2026-08-30 (Phase 8, T041) -- reconciliation list for a sync-based run: employees who would
    // normally be expected in payroll but Origami didn't send this time and nobody manually joined
    // them either. See PayrollRunModel::syncMissingEmployees()'s own docblock.
    public function syncMissingEmployees() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->syncMissingEmployees($id, (int)$compId)]);
    }

    public function employeeCommentList() {
        if (!$this->requireViewAccess()) return;
        $compId = getCompId();
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
        $employeeId = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
        if (!$compId || $id <= 0 || $employeeId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json(['status' => true, 'data' => $this->model->employeeComments($id, (int)$compId, $employeeId)]);
    }

    public function employeeCommentUpdate() {
        if (!$this->requirePermission('payroll_run.edit')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $commentId = (is_array($data) && isset($data['comment_id'])) ? (int)$data['comment_id'] : 0;
        $tag = (is_array($data) && !empty($data['tag'])) ? (string)$data['tag'] : null;
        $comment = (is_array($data) && isset($data['comment'])) ? (string)$data['comment'] : '';
        if (!$compId || $id <= 0 || $commentId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->employeeCommentUpdate($id, (int)$compId, $commentId, $tag, $comment, $this->userId(), $this->isAdmin()));
    }

    public function employeeCommentDelete() {
        if (!$this->requirePermission('payroll_run.delete')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $commentId = (is_array($data) && isset($data['comment_id'])) ? (int)$data['comment_id'] : 0;
        if (!$compId || $id <= 0 || $commentId <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->employeeCommentDelete($id, (int)$compId, $commentId, $this->userId(), $this->isAdmin()));
    }

    public function submit() {
        if (!$this->requirePermission('payroll_run.process')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $result = $this->model->submit($id, (int)$compId, $this->userId(), $this->isAdmin());
        $this->pushOrigamiRunStatus($result, $id, (int)$compId, 'run_submitted');
        $this->json($result);
    }

    public function revert() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $note = !empty($data['note']) ? (string)$data['note'] : null;
        // 2026-08-24: optional explicit target status (pending_approval/rejected/need_info) for
        // reverting a DECIDED run -- see PayrollRunModel::revert()'s own docblock. Omitted (or a
        // run still at pending_approval, which has only one possible target anyway) falls back to
        // the model's own default.
        $toState = !empty($data['to_state']) ? (string)$data['to_state'] : null;
        $result = $this->model->revert($id, (int)$compId, $this->userId(), $this->isAdmin(), $note, $toState);
        $this->pushOrigamiRunStatus($result, $id, (int)$compId, 'run_reverted');
        $this->json($result);
    }

    public function approve() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $note = !empty($data['note']) ? (string)$data['note'] : null;
        $result = $this->model->approve($id, (int)$compId, $this->userId(), $this->isAdmin(), $note);
        $this->pushOrigamiRunStatus($result, $id, (int)$compId, 'run_approved');
        $this->json($result);
    }

    public function reject() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $reason = (string)($data['reason'] ?? '');
        $result = $this->model->reject($id, (int)$compId, $this->userId(), $this->isAdmin(), $reason);
        $this->pushOrigamiRunStatus($result, $id, (int)$compId, 'run_rejected');
        $this->json($result);
    }

    public function bulkApprove() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $ids = (is_array($data) && isset($data['ids']) && is_array($data['ids'])) ? $data['ids'] : [];
        if (!$compId || empty($ids)) {
            $this->json(['status' => false, 'message' => 'No payroll runs selected.']);
            return;
        }
        $note = !empty($data['note']) ? (string)$data['note'] : null;
        $result = $this->model->bulkApprove($ids, (int)$compId, $this->userId(), $this->isAdmin(), $note);
        $this->pushOrigamiRunStatusForIds($result['results'] ?? [], (int)$compId, 'run_approved');
        $this->json($result);
    }

    public function bulkReject() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $ids = (is_array($data) && isset($data['ids']) && is_array($data['ids'])) ? $data['ids'] : [];
        if (!$compId || empty($ids)) {
            $this->json(['status' => false, 'message' => 'No payroll runs selected.']);
            return;
        }
        $reason = (string)($data['reason'] ?? '');
        $result = $this->model->bulkReject($ids, (int)$compId, $this->userId(), $this->isAdmin(), $reason);
        $this->pushOrigamiRunStatusForIds($result['results'] ?? [], (int)$compId, 'run_rejected');
        $this->json($result);
    }

    public function cancel() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $reason = (string)($data['reason'] ?? '');
        $result = $this->model->cancel($id, (int)$compId, $this->userId(), $this->isAdmin(), $reason);
        $this->pushOrigamiRunStatus($result, $id, (int)$compId, 'run_cancelled');
        $this->json($result);
    }

    public function reviseAfterReject() {
        if (!$this->requirePermission('payroll_run.process')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $result = $this->model->reviseAfterReject($id, (int)$compId, $this->userId(), $this->isAdmin());
        $this->pushOrigamiRunStatus($result, $id, (int)$compId, 'run_revised');
        $this->json($result);
    }

    public function requestInfo() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $reason = (string)($data['reason'] ?? '');
        $result = $this->model->requestInfo($id, (int)$compId, $this->userId(), $this->isAdmin(), $reason);
        $this->pushOrigamiRunStatus($result, $id, (int)$compId, 'run_need_info');
        $this->json($result);
    }

    public function bulkRequestInfo() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $ids = (is_array($data) && isset($data['ids']) && is_array($data['ids'])) ? $data['ids'] : [];
        if (!$compId || empty($ids)) {
            $this->json(['status' => false, 'message' => 'No payroll runs selected.']);
            return;
        }
        $reason = (string)($data['reason'] ?? '');
        $result = $this->model->bulkRequestInfo($ids, (int)$compId, $this->userId(), $this->isAdmin(), $reason);
        $this->pushOrigamiRunStatusForIds($result['results'] ?? [], (int)$compId, 'run_need_info');
        $this->json($result);
    }

    public function reviseAfterNeedInfo() {
        if (!$this->requirePermission('payroll_run.process')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $result = $this->model->reviseAfterNeedInfo($id, (int)$compId, $this->userId(), $this->isAdmin());
        $this->pushOrigamiRunStatus($result, $id, (int)$compId, 'run_revised');
        $this->json($result);
    }

    public function markPaid() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $result = $this->model->markPaid($id, (int)$compId, $this->userId(), $this->isAdmin(), $data);
        $this->pushOrigamiRunStatus($result, $id, (int)$compId, 'run_paid');
        $this->json($result);
    }

    /** 2026-08-31, explicit request: push a payroll run's status to Origami on every state change
     *  (submit/approve/reject/markPaid) -- see OrigamiPayrollStatusClient's own docblock for the
     *  full contract/reasoning. Best-effort: only fires when the state change itself actually
     *  succeeded, and any push failure is logged (origami_status_push_logs) but never surfaced to
     *  the caller -- a down/unreachable Origami must never look like the payroll action itself failed. */
    private function pushOrigamiRunStatus(array $result, int $runId, int $compId, string $eventType): void {
        if (empty($result['status'])) {
            return;
        }
        try {
            $run = $this->model->get($runId, $compId);
            if ($run) {
                require_once __DIR__ . '/../services/OrigamiPayrollStatusClient.php';
                (new OrigamiPayrollStatusClient())->pushRunStatus($run, $eventType);
            }
        } catch (Throwable $e) {
            // Never let a push-logging problem affect the HTTP response for the real action.
        }
    }

    /** Same as pushOrigamiRunStatus() above, for a bulk*() action's own per-id $result['results']
     *  map -- only the ids whose OWN sub-result succeeded get pushed (a bulk action can partially
     *  fail, e.g. one run in the batch not actually being at the right state for that action). */
    private function pushOrigamiRunStatusForIds(array $resultsById, int $compId, string $eventType): void {
        foreach ($resultsById as $runId => $subResult) {
            if (!empty($subResult['status'])) {
                $this->pushOrigamiRunStatus($subResult, (int)$runId, $compId, $eventType);
            }
        }
    }

    public function lock() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->lock($id, (int)$compId, $this->userId(), $this->isAdmin()));
    }

    /** 2026-08-29, explicit request: "รายการที่ติ๊กว่าทำจ่ายแล้ว หรือปิดรอบไปแล้ว สามารถเปิดให้กลับมาแก้ไขได้
     *  และส่งอนุมัติใหม่ได้ครับ" -- see PayrollRunModel::reopen()'s own docblock. */
    public function reopen() {
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        $note = (is_array($data) && isset($data['note'])) ? (string)$data['note'] : null;
        if (!$compId || $id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $this->json($this->model->reopen($id, (int)$compId, $this->userId(), $this->isAdmin(), $note));
    }
}
