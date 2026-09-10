<?php
declare(strict_types=1);

/**
 * Approval Request engine: creates a running instance of whichever ACTIVE workflow is mapped
 * to a document type, and progresses it step by step as approvers act.
 *
 * 2026-08-23, wired to a real document flow (explicit bug report: "ใส่คน Approve ไว้แค่คนเดียวแต่
 * ดึงอะไรมาก้ไม่รู้...ในตาราง approval_workflow_steps คุณรู้ใช่ไหมว่ามันมีการตั้งค่าส่วนนี้" -- the
 * user had configured a real workflow via the Approval Workflow tab (a single approver_type='user'
 * step), but PayrollRunModel::submit()/approve()/reject() were still only consulting the
 * completely separate flat structure_roles.can_approve_payroll check, ignoring this table
 * entirely -- exactly the gap this class's own docblock used to flag as deferred). See
 * PayrollRunModel::submit()/approve()/reject()/requestInfo()/revert()/approvalFlow() for the
 * integration -- when an active workflow is configured for PAYROLL_RUN_APPROVAL, submit() creates
 * a real request here and payroll_runs.approval_request_id links to it; approve()/reject() route
 * through act() below; requestInfo()/revert() (actions this engine's own approve/reject/cancel
 * vocabulary doesn't cover) gate on canActOnRequest()/wasEligibleActor() instead. A company that
 * has never configured a PAYROLL_RUN_APPROVAL workflow gets approval_request_id = NULL and falls
 * straight back to the pre-existing flat-role check, unchanged.
 *
 * 2026-08-23, second change the same day: eligibility used to be LIVE-resolved on every read
 * (role membership queried fresh each time, by design, so a person changing roles mid-flight would
 * immediately affect who could act). Explicit follow-up request: persist the eligible list into a
 * table once, when a step becomes current, and read from THAT everywhere after -- "ให้ปรับเป็นการ
 * สร้างตารางเก็บรายการ Approve ของแต่ละ Process แล้วก็ดึงจากตารางนั้นว่าใครมีสิทธิ์ Approve บ้าง...
 * ไม่ใช่ไปดึงข้อมูลใหม่ทุกรอบ". `approval_request_step_approvers` is that table: one row per
 * eligible person when a step's joint_approve_mode='all' (each individually tracked), one shared
 * row covering the whole pool when ='any' (whichever eligible person acts first decides it) --
 * exactly the storage split the user described. snapshotStepApprovers() populates it the moment a
 * step becomes the request's current step (request creation for step 1, act()/approve advancing
 * to the next step); every eligibility read (canActOnRequest(), currentStepApprovers(), act()
 * itself) now queries this table only, never `structure_roles`/`employees.role_id` directly.
 *
 * Still also used standalone for SLIP_REQUEST_APPROVAL (PayslipRequestModel) exactly as before --
 * this snapshot format is the standard for every approval flow in the system, not payroll-specific.
 *
 * 2026-08-23, third change the same day: replaced the strict single-current-step pointer model
 * with a per-step gating + AND/OR/Finish verdict model, ported from origami's
 * `m_approval_master`/`getApprovalResult` (explicit request, semantics spelled out verbatim by the
 * user, "type"s not relevant to payroll dropped -- no levels/projects/customers/assets, no 'I'
 * need-info status since that's handled outside this engine already):
 *   - EVERY active step's eligible pool is snapshotted at request creation (not lazily one at a
 *     time) -- multiple steps can be simultaneously actionable now.
 *   - `requires_previous_step` (per step): freely toggle whether a step must wait its turn. Steps
 *     with this on form an ordered queue (by step_order) -- queued step N only becomes actionable
 *     once every earlier QUEUED step (order < N, also requires_previous_step=1) is fully approved;
 *     see isStepUnlocked(). Steps with this off are always immediately actionable.
 *   - `group_type` (and/or/finish, per step): how a step's own result (once every eligible person
 *     on it has decided, per its joint_approve_mode) feeds the OVERALL request verdict, computed
 *     across every step after each action -- see recomputeVerdict(): 'and' = every AND-group step
 *     must be approved, any one rejected fails the whole request immediately; 'or' = with 2+
 *     OR-group steps, one approval is enough to satisfy that side (all rejected fails it) --  with
 *     0-1 OR-group steps the OR side has no effect on the outcome either way (a lone OR step
 *     doesn't auto-pass, it simply doesn't count); 'finish' = the moment any Finish-group step is
 *     decided, that decision alone becomes the whole request's final result immediately, ignoring
 *     every other step's state.
 * `act()`'s reject is therefore NO LONGER unconditionally terminal (an OR-group reject might not
 * finalize the request) -- see PayrollRunModel::reject()'s own updated docblock for the knock-on
 * fix that required.
 *
 * See tests/approval_workflow_test.php for the end-to-end engine exercise (create workflow ->
 * create request -> act through every step, gating and AND/OR/Finish combinations) and
 * tests/payroll_run_test.php for the PayrollRunModel integration itself.
 */
class ApprovalRequestModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /**
     * Starts a new approval request for a document, using whichever workflow is currently
     * ACTIVE for this document type in this company.
     */
    public function create(int $compId, string $documentTypeCode, int $referenceId, ?string $referenceLabel, int $requestedBy): array {
        if ($referenceId <= 0) {
            return ['status' => false, 'message' => 'reference_id is required.'];
        }
        $stmtType = $this->db->prepare("SELECT code FROM `approval_document_types` WHERE code = :code AND is_active = 1");
        $stmtType->execute([':code' => $documentTypeCode]);
        if (!$stmtType->fetch()) {
            return ['status' => false, 'message' => 'Unknown or inactive document_type_code.'];
        }

        $stmtWf = $this->db->prepare("SELECT w.id FROM `approval_workflows` w
            JOIN `approval_workflow_document_types` awdt ON awdt.workflow_id = w.id
            WHERE w.comp_id = :comp_id AND w.status = 'active' AND awdt.document_type_code = :code LIMIT 1");
        $stmtWf->execute([':comp_id' => $compId, ':code' => $documentTypeCode]);
        $workflowId = $stmtWf->fetchColumn();
        if ($workflowId === false) {
            return ['status' => false, 'message' => 'No active workflow is configured for this document type yet.'];
        }

        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            $stmt = $this->db->prepare("INSERT INTO `approval_requests`
                (comp_id, workflow_id, document_type_code, reference_id, reference_label, current_step_order, status, requested_by)
                VALUES (:comp_id, :workflow_id, :document_type_code, :reference_id, :reference_label, 1, 'pending', :requested_by)");
            $stmt->execute([
                ':comp_id' => $compId,
                ':workflow_id' => (int)$workflowId,
                ':document_type_code' => $documentTypeCode,
                ':reference_id' => $referenceId,
                ':reference_label' => $referenceLabel,
                ':requested_by' => $requestedBy,
            ]);
            $requestId = (int)$this->db->lastInsertId();
            foreach ($this->activeStepsForWorkflow((int)$workflowId) as $step) {
                $this->snapshotStepApprovers($compId, $requestId, $step);
            }
            $this->refreshCurrentStepOrderPointer($requestId);
            if ($own) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Approval request created.', 'id' => $requestId];
        } catch (PDOException $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /** True if this document type has an active workflow configured for this company -- lets a
     *  caller (PayrollRunModel::submit()) decide whether to route through this engine at all, or
     *  stay on its own simpler fallback check. */
    public function hasActiveWorkflow(int $compId, string $documentTypeCode): bool {
        $stmt = $this->db->prepare("SELECT 1 FROM `approval_workflows` w
            JOIN `approval_workflow_document_types` awdt ON awdt.workflow_id = w.id
            WHERE w.comp_id = :comp_id AND w.status = 'active' AND awdt.document_type_code = :code LIMIT 1");
        $stmt->execute([':comp_id' => $compId, ':code' => $documentTypeCode]);
        return (bool)$stmt->fetchColumn();
    }

    public function get(int $compId, int $requestId): ?array {
        $stmt = $this->db->prepare("SELECT r.*, w.workflow_name, dt.name_th AS document_type_name_th, dt.name_en AS document_type_name_en,
                CONCAT(e.name_th, ' ', e.surname_th) AS requested_by_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS requested_by_name_en
            FROM `approval_requests` r
            JOIN `approval_workflows` w ON w.id = r.workflow_id
            JOIN `approval_document_types` dt ON dt.code = r.document_type_code
            LEFT JOIN `employees` e ON e.id = r.requested_by
            WHERE r.id = :id AND r.comp_id = :comp_id");
        $stmt->execute([':id' => $requestId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return array<int,array> every ACTIVE (not soft-deleted) step of a workflow, ordered */
    private function activeStepsForWorkflow(int $workflowId): array {
        $stmt = $this->db->prepare("SELECT * FROM `approval_workflow_steps`
            WHERE workflow_id = :workflow_id AND status = 'active' ORDER BY step_order ASC");
        $stmt->execute([':workflow_id' => $workflowId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array{approver_type:string,approver_id:int}> every approver entry configured on a step */
    private function stepApprovers(int $stepId): array {
        $stmt = $this->db->prepare("SELECT approver_type, approver_id FROM `approval_workflow_step_approvers` WHERE step_id = :step_id");
        $stmt->execute([':step_id' => $stepId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<int,array{approver_type:string,approver_id:int}> $approvers
     *  @return int[] employee ids eligible to act on a step -- union of every approver entry's
     *  resolution (a role entry expands to its current holders), deduped. Only ever called from
     *  snapshotStepApprovers() -- this is the one place role membership gets live-resolved; every
     *  other read in this class goes through the persisted snapshot instead (see class docblock). */
    private function resolveApproverPool(int $compId, array $approvers): array {
        $ids = [];
        foreach ($approvers as $a) {
            if ($a['approver_type'] === 'user') {
                $ids[] = (int)$a['approver_id'];
                continue;
            }
            $stmt = $this->db->prepare("SELECT id FROM `employees` WHERE role_id = :role_id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmt->execute([':role_id' => (int)$a['approver_id'], ':comp_id' => $compId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
                $ids[] = (int)$id;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Resolves a step's eligible pool and persists it into `approval_request_step_approvers` --
     * the one and only time this class live-resolves role membership for a given request+step.
     * Idempotent (a snapshot already present for this request+step is left untouched). Called for
     * EVERY active step at request creation (and again on reopen()) since, unlike the old
     * single-current-step model, several steps can be simultaneously actionable from the start.
     * Storage granularity follows joint_approve_mode exactly as specified: 'all' -> one row per
     * eligible person (individually tracked), 'any' -> one shared row covering the whole pool
     * (first to act decides it). group_type/requires_previous_step are frozen in at the same time
     * (see class docblock) so a later workflow edit can't retroactively change the rules a request
     * already in flight is being judged by.
     */
    private function snapshotStepApprovers(int $compId, int $requestId, array $step): void {
        $stepOrder = (int)$step['step_order'];
        $stmtExists = $this->db->prepare("SELECT 1 FROM `approval_request_step_approvers`
            WHERE request_id = :request_id AND step_order = :step_order LIMIT 1");
        $stmtExists->execute([':request_id' => $requestId, ':step_order' => $stepOrder]);
        if ($stmtExists->fetch()) {
            return;
        }
        $pool = $this->resolveApproverPool($compId, $this->stepApprovers((int)$step['id']));
        if (empty($pool)) {
            return;
        }
        $jointMode = (string)$step['joint_approve_mode'];
        $stmt = $this->db->prepare("INSERT INTO `approval_request_step_approvers`
            (request_id, step_order, step_name_snapshot, group_type, requires_previous_step, joint_approve_mode, eligible_employee_ids, status)
            VALUES (:request_id, :step_order, :step_name, :group_type, :requires_previous_step, :joint_approve_mode, :eligible, 'pending')");
        $base = [
            ':request_id' => $requestId, ':step_order' => $stepOrder, ':step_name' => $step['step_name'],
            ':group_type' => $step['group_type'], ':requires_previous_step' => (int)$step['requires_previous_step'],
            ':joint_approve_mode' => $jointMode,
        ];
        if ($jointMode === 'all') {
            foreach ($pool as $empId) {
                $stmt->execute($base + [':eligible' => (string)$empId]);
            }
        } else {
            $stmt->execute($base + [':eligible' => implode(',', $pool)]);
        }
    }

    /**
     * True if a step is currently actionable. A step with requires_previous_step=0 always is. One
     * with =1 is gated behind every EARLIER step_order in the same request that is also
     * requires_previous_step=1 (steps that don't require sequencing don't count as "earlier" for
     * this purpose, and don't themselves block anything) -- each such earlier step must be FULLY
     * approved (every one of its own rows, per its joint_approve_mode) before this one opens up.
     * The button stays visible but disabled until then -- explicit request: "จะยังไม่เปิดปุ่มให้ แต่จะ
     * เห็นเป็นจุดๆ ว่าตัวเองอยู่ตำแหน่งไหน และตำแหน่งก่อนหน้านั้นอนุมัติหรือยัง".
     */
    private function isStepUnlocked(int $requestId, int $stepOrder): bool {
        $stmt = $this->db->prepare("SELECT requires_previous_step FROM `approval_request_step_approvers`
            WHERE request_id = :request_id AND step_order = :step_order LIMIT 1");
        $stmt->execute([':request_id' => $requestId, ':step_order' => $stepOrder]);
        $requiresPrev = $stmt->fetchColumn();
        if ($requiresPrev === false || !(bool)$requiresPrev) {
            return true;
        }
        $stmt2 = $this->db->prepare("SELECT COUNT(*) AS total, SUM(status = 'approved') AS approved_count
            FROM `approval_request_step_approvers`
            WHERE request_id = :request_id AND requires_previous_step = 1 AND step_order < :step_order
            GROUP BY step_order");
        $stmt2->execute([':request_id' => $requestId, ':step_order' => $stepOrder]);
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((int)$row['approved_count'] < (int)$row['total']) {
                return false;
            }
        }
        return true;
    }

    /** The FIRST (lowest step_order) still-'pending' snapshot row that $userId is eligible for AND
     *  that is currently unlocked, across ALL of a request's steps -- or null. Membership check is
     *  FIND_IN_SET against the persisted eligible_employee_ids CSV, never a live role query (see
     *  class docblock). If a user happens to be eligible on more than one simultaneously-unlocked
     *  step, this deterministically picks the earliest one; act() has no way for a caller to name
     *  a specific step to act on, so ambiguity resolves to "process in order when possible". */
    private function actionableRowFor(int $requestId, int $userId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `approval_request_step_approvers`
            WHERE request_id = :request_id AND status = 'pending' AND FIND_IN_SET(:user_id, eligible_employee_ids)
            ORDER BY step_order ASC");
        $stmt->execute([':request_id' => $requestId, ':user_id' => $userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($this->isStepUnlocked($requestId, (int)$row['step_order'])) {
                return $row;
            }
        }
        return null;
    }

    /**
     * True if $userId is eligible to act on ANY step of this request, per the persisted snapshot
     * -- resolved regardless of the request's status, whether the step is currently gated/locked,
     * or whether this particular row has already been decided by someone else, unlike act() which
     * separately refuses any action once status != 'pending' AND enforces gating via
     * actionableRowFor(). Exposed for callers that need a coarser gate outside this engine's own
     * approve/reject/cancel vocabulary: PayrollRunModel::requestInfo() (while still pending) and
     * PayrollRunModel::revert() (to undo an ALREADY-decided outcome).
     */
    public function canActOnRequest(int $compId, int $requestId, int $userId): bool {
        $request = $this->get($compId, $requestId);
        if (!$request) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT 1 FROM `approval_request_step_approvers`
            WHERE request_id = :request_id AND FIND_IN_SET(:user_id, eligible_employee_ids) LIMIT 1");
        $stmt->execute([':request_id' => $requestId, ':user_id' => $userId]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * True if $userId has a step they can decide RIGHT NOW on this request -- eligible AND that
     * step's snapshot row is still 'pending' (not already decided by a joint peer on an 'any'-mode
     * pool, and not already this same user's own past decision on an 'all'-mode row) AND unlocked
     * (isStepUnlocked()) -- exactly actionableRowFor()'s own definition of "can act", the same one
     * act() itself enforces before letting approve/reject through.
     *
     * 2026-08-24, added because canActOnRequest() above was being used to gate the Approve/Reject/
     * Request Info BUTTONS (PayrollRunModel::canApproveThisRun()) and the Approval Queue list's
     * per-row visibility -- but that method answers a different, coarser question ("was this user
     * EVER eligible on ANY row of this request", ignoring status/locking) meant for revert()/
     * requestInfo()'s "undo an already-decided outcome" use case. Using it for the buttons/list
     * meant: (1) a user eligible on a step whose 'any'-mode pool a joint peer had ALREADY decided
     * still saw an Approve button and a row in their queue (explicit bug report: this must
     * disappear once someone else in the same pool has acted, unless the user is ALSO eligible on a
     * different still-open step of the same request); (2) a user eligible only on a still-LOCKED
     * later step (requires_previous_step gating not yet satisfied) saw the same, even though the
     * button would just be refused by act() if clicked. canActOnRequest() itself is UNCHANGED and
     * still used exactly where it was before (revert(), requestInfo()'s permission check before
     * this fix, and the Undo Decision button on an already-decided run) -- undoing a decision is
     * correctly allowed for anyone who was ever part of the flow, not just whoever currently has an
     * open pending step. See PayrollRunModel::canApproveThisRun()'s own docblock for how the two
     * checks are now split by run state.
     */
    public function canActOnRequestNow(int $compId, int $requestId, int $userId): bool {
        $request = $this->get($compId, $requestId);
        if (!$request) {
            return false;
        }
        return $this->actionableRowFor($requestId, $userId) !== null;
    }

    /** Every employee who could act RIGHT NOW -- eligible on a step that is both still pending AND
     *  currently unlocked (see isStepUnlocked()) -- from the persisted snapshot, never a live
     *  query, each flagged with whether THEY personally have already approved. Feeds
     *  PayrollRunModel::approvalFlow()'s "who needs to approve" breakdown when a run is routed
     *  through this engine, the same shape its own flat-role fallback already returns. A person
     *  eligible across more than one simultaneously-unlocked step is deduped to one entry (acted =
     *  true if true on any of them). For a joint_approve_mode='all' step, each person has their
     *  own row so acted = (that row's status != 'pending'). For 'any', the whole pool shares one
     *  row, so only the specific person recorded in acted_by shows acted = true. */
    public function currentStepApprovers(int $compId, int $requestId): array {
        $request = $this->get($compId, $requestId);
        if (!$request) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT * FROM `approval_request_step_approvers`
            WHERE request_id = :request_id ORDER BY step_order ASC, id ASC");
        $stmt->execute([':request_id' => $requestId]);
        $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($allRows)) {
            return [];
        }

        $unlockedStepOrders = [];
        foreach (array_unique(array_column($allRows, 'step_order')) as $so) {
            if ($this->isStepUnlocked($requestId, (int)$so)) {
                $unlockedStepOrders[(int)$so] = true;
            }
        }
        $rows = array_values(array_filter($allRows, fn($r) => isset($unlockedStepOrders[(int)$r['step_order']])));
        if (empty($rows)) {
            return [];
        }

        $allEmpIds = [];
        foreach ($rows as $r) {
            foreach (explode(',', (string)$r['eligible_employee_ids']) as $idStr) {
                if ($idStr !== '') {
                    $allEmpIds[] = (int)$idStr;
                }
            }
        }
        $allEmpIds = array_values(array_unique($allEmpIds));
        if (empty($allEmpIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($allEmpIds), '?'));
        $stmtEmp = $this->db->prepare("SELECT id, employee_no, name_th, name_en, profile_photo_path FROM `employees`
            WHERE id IN ({$placeholders}) ORDER BY name_th ASC");
        $stmtEmp->execute($allEmpIds);
        $empById = [];
        foreach ($stmtEmp->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $empById[(int)$e['id']] = $e;
        }

        $byEmp = [];
        foreach ($rows as $r) {
            $actedBy = $r['acted_by'] !== null ? (int)$r['acted_by'] : null;
            foreach (explode(',', (string)$r['eligible_employee_ids']) as $idStr) {
                if ($idStr === '' || !isset($empById[(int)$idStr])) {
                    continue;
                }
                $empId = (int)$idStr;
                $acted = ($r['joint_approve_mode'] === 'all') ? ($r['status'] !== 'pending') : ($actedBy === $empId);
                if (!isset($byEmp[$empId])) {
                    $byEmp[$empId] = $empById[$empId];
                    $byEmp[$empId]['acted'] = $acted;
                } elseif ($acted) {
                    $byEmp[$empId]['acted'] = true;
                }
            }
        }
        return array_values($byEmp);
    }

    /**
     * 2026-08-30, explicit follow-up ("งาน UI ที่ยังไม่เสร็จ...4. ยังไม่ได้ปรับ UI ฝั่ง Monitor/Approve
     * modal ให้แสดงหลาย step ที่ actionable พร้อมกันแบบ 'จุดๆ ว่าตัวเองอยู่ตำแหน่งไหน และตำแหน่งก่อนหน้านั้น
     * อนุมัติหรือยัง'") -- the per-step counterpart to currentStepApprovers() above, which
     * deliberately FLATTENS/dedupes across every simultaneously-unlocked step (that method answers
     * "who can act right now", one list). This one answers "what does the WHOLE chain look like,
     * step by step" for a timeline/stepper UI -- one entry PER STEP (in step_order), locked or not,
     * decided or not, with that step's own approver breakdown nested inside it. A step's overall
     * status uses the EXACT SAME rule recomputeVerdict()'s own stepResult() closure already applies
     * (approved_count >= total => approved; rejected_count > 0 => rejected; else pending) so the
     * timeline can never show a step as "approved" that the verdict engine wouldn't also treat as
     * approved.
     *
     * Per-step approver list: joint_approve_mode='all' always lists every eligible person with
     * their OWN real per-row status (each person's decision matters individually). For ='any'
     * (one shared pool, first-to-act decides it), listing every pool member as still "pending"
     * once the step has ALREADY been decided would misrepresent a closed step as still open with
     * 4 people waiting -- so once decided, only the person who actually acted (acted_by) is shown;
     * while still pending, the whole pool is shown (any of them could still act).
     *
     * @return array<int,array{step_order:int,step_name:?string,group_type:string,
     *   requires_previous_step:bool,joint_approve_mode:string,unlocked:bool,status:string,
     *   approvers:array<int,array{id:int,employee_no:string,name_th:string,name_en:string,status:string,acted_at:?string,note:?string}>}>
     */
    public function stepBreakdown(int $compId, int $requestId): array {
        $request = $this->get($compId, $requestId);
        if (!$request) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT * FROM `approval_request_step_approvers`
            WHERE request_id = :request_id ORDER BY step_order ASC, id ASC");
        $stmt->execute([':request_id' => $requestId]);
        $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($allRows)) {
            return [];
        }

        $empIds = [];
        foreach ($allRows as $r) {
            foreach (explode(',', (string)$r['eligible_employee_ids']) as $idStr) {
                if ($idStr !== '') {
                    $empIds[] = (int)$idStr;
                }
            }
        }
        $empIds = array_values(array_unique($empIds));
        $empById = [];
        if (!empty($empIds)) {
            $placeholders = implode(',', array_fill(0, count($empIds), '?'));
            $stmtEmp = $this->db->prepare("SELECT id, employee_no, name_th, name_en, profile_photo_path FROM `employees` WHERE id IN ({$placeholders})");
            $stmtEmp->execute($empIds);
            foreach ($stmtEmp->fetchAll(PDO::FETCH_ASSOC) as $e) {
                $empById[(int)$e['id']] = $e;
            }
        }

        $stepResult = function (int $total, int $approvedCount, int $rejectedCount): string {
            if ($approvedCount >= $total) {
                return 'approved';
            }
            if ($rejectedCount > 0) {
                return 'rejected';
            }
            return 'pending';
        };

        $rowsByStep = [];
        foreach ($allRows as $r) {
            $rowsByStep[(int)$r['step_order']][] = $r;
        }

        $steps = [];
        foreach ($rowsByStep as $stepOrder => $rows) {
            $total = count($rows);
            $approvedCount = count(array_filter($rows, fn($r) => $r['status'] === 'approved'));
            $rejectedCount = count(array_filter($rows, fn($r) => $r['status'] === 'rejected'));
            $status = $stepResult($total, $approvedCount, $rejectedCount);
            $jointMode = (string)$rows[0]['joint_approve_mode'];

            $approvers = [];
            if ($jointMode === 'all') {
                foreach ($rows as $r) {
                    foreach (explode(',', (string)$r['eligible_employee_ids']) as $idStr) {
                        if ($idStr === '' || !isset($empById[(int)$idStr])) {
                            continue;
                        }
                        $approvers[] = array_merge($empById[(int)$idStr], [
                            'status' => $r['status'], 'acted_at' => $r['acted_at'], 'note' => $r['note'],
                        ]);
                    }
                }
            } else {
                $row = $rows[0]; // 'any' mode is always exactly one shared row per step.
                if ($status === 'pending') {
                    foreach (explode(',', (string)$row['eligible_employee_ids']) as $idStr) {
                        if ($idStr === '' || !isset($empById[(int)$idStr])) {
                            continue;
                        }
                        $approvers[] = array_merge($empById[(int)$idStr], ['status' => 'pending', 'acted_at' => null, 'note' => null]);
                    }
                } elseif ($row['acted_by'] !== null && isset($empById[(int)$row['acted_by']])) {
                    $approvers[] = array_merge($empById[(int)$row['acted_by']], [
                        'status' => $row['status'], 'acted_at' => $row['acted_at'], 'note' => $row['note'],
                    ]);
                }
            }

            $steps[] = [
                'step_order' => (int)$stepOrder,
                'step_name' => $rows[0]['step_name_snapshot'],
                'group_type' => $rows[0]['group_type'],
                'requires_previous_step' => (bool)$rows[0]['requires_previous_step'],
                'joint_approve_mode' => $jointMode,
                'unlocked' => $this->isStepUnlocked($requestId, (int)$stepOrder),
                'status' => $status,
                'approvers' => $approvers,
            ];
        }
        // Already in step_order-ascending order -- $rowsByStep's own keys were populated by
        // iterating $allRows, which the SQL above already returned ORDER BY step_order ASC.
        return $steps;
    }

    /** Resets a completed request back to 'pending' at its FIRST step -- used by
     *  PayrollRunModel::revert() when undoing an already-decided run that went through this
     *  engine, so it can be re-approved cleanly through the same chain. Clears the old snapshot
     *  and takes a fresh one, since circumstances (role holders, department, etc.) may have
     *  changed since the request was first decided -- a stale snapshot from a prior cycle must
     *  not silently linger. Never touches approval_request_logs -- the full step-by-step history
     *  stays intact regardless. */
    public function reopen(int $compId, int $requestId): bool {
        $request = $this->get($compId, $requestId);
        if (!$request) {
            return false;
        }
        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            $stmt = $this->db->prepare("UPDATE `approval_requests`
                SET status = 'pending', current_step_order = 1, completed_at = NULL, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([':id' => $requestId]);
            $del = $this->db->prepare("DELETE FROM `approval_request_step_approvers` WHERE request_id = :id");
            $del->execute([':id' => $requestId]);
            foreach ($this->activeStepsForWorkflow((int)$request['workflow_id']) as $step) {
                $this->snapshotStepApprovers($compId, $requestId, $step);
            }
            $this->refreshCurrentStepOrderPointer($requestId);
            if ($own) {
                $this->db->commit();
            }
            return true;
        } catch (PDOException $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return false;
        }
    }

    /**
     * @param string $action approve|reject|cancel
     */
    public function act(int $compId, int $requestId, int $userId, string $action, ?string $note = null): array {
        if (!in_array($action, ['approve', 'reject', 'cancel'], true)) {
            return ['status' => false, 'message' => 'Invalid action.'];
        }
        $request = $this->get($compId, $requestId);
        if (!$request) {
            return ['status' => false, 'message' => 'Request not found.'];
        }
        if ($request['status'] !== 'pending') {
            return ['status' => false, 'message' => "This request is already {$request['status']}."];
        }

        if ($action === 'cancel') {
            if ((int)$request['requested_by'] !== $userId) {
                return ['status' => false, 'message' => 'Only the requester can cancel this request.'];
            }
            return $this->finalize($requestId, (int)$request['current_step_order'], 'cancel', $userId, $note, 'cancelled', null);
        }

        $row = $this->actionableRowFor($requestId, $userId);
        if (!$row) {
            return ['status' => false, 'message' => 'You are not authorized to act on this step.'];
        }
        $stepOrder = (int)$row['step_order'];
        $stepNameSnapshot = $row['step_name_snapshot'];

        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            $rowStatus = $action === 'approve' ? 'approved' : 'rejected';
            $this->markSnapshotRow((int)$row['id'], $rowStatus, $userId, $note);
            $this->logAction($requestId, $stepOrder, $stepNameSnapshot, $action, $userId, $note);

            $verdict = $this->recomputeVerdict($requestId);
            if ($own) {
                $this->db->commit();
            }
            if ($verdict !== null) {
                return ['status' => true, 'message' => ucfirst($verdict) . '.', 'request_status' => $verdict];
            }
            return [
                'status' => true,
                'message' => $action === 'approve' ? 'Your approval was recorded.' : 'Your rejection was recorded.',
                'request_status' => 'pending',
            ];
        } catch (PDOException $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    private function markSnapshotRow(int $rowId, string $status, int $userId, ?string $note): void {
        $stmt = $this->db->prepare("UPDATE `approval_request_step_approvers`
            SET status = :status, acted_by = :acted_by, note = :note, acted_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':status' => $status, ':acted_by' => $userId, ':note' => $note, ':id' => $rowId]);
    }

    /**
     * Recomputes the OVERALL request verdict from every step's own result (ported from origami's
     * getApprovalResult -- see class docblock for the exact AND/OR/Finish semantics). Called after
     * every approve/reject. Returns 'approved'/'rejected' and finalizes the request the moment a
     * verdict is reached, or null if still pending (and refreshes the display-only
     * current_step_order pointer in that case).
     */
    private function recomputeVerdict(int $requestId): ?string {
        $stmt = $this->db->prepare("SELECT step_order, group_type,
                COUNT(*) AS total, SUM(status = 'approved') AS approved_count, SUM(status = 'rejected') AS rejected_count
            FROM `approval_request_step_approvers`
            WHERE request_id = :request_id GROUP BY step_order, group_type");
        $stmt->execute([':request_id' => $requestId]);
        $steps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($steps)) {
            return null;
        }
        $stepResult = function (array $s): string {
            if ((int)$s['approved_count'] >= (int)$s['total']) {
                return 'approved';
            }
            if ((int)$s['rejected_count'] > 0) {
                return 'rejected';
            }
            return 'pending';
        };

        // Finish: the moment ANY finish-group step is decided, that decision alone is the whole
        // request's result, ignoring every other step ("ไม่สนใจผลของคนอื่นเลย ถ้ากดแล้วก็รวบผลไปเลย").
        foreach ($steps as $s) {
            if ($s['group_type'] === 'finish') {
                $r = $stepResult($s);
                if ($r !== 'pending') {
                    $this->applyFinalStatus($requestId, $r);
                    return $r;
                }
            }
        }

        // AND: every AND-group step must be approved; any one rejected fails the request
        // immediately ("ทุกแถวของ AND ต้องอนุมัติจึงจะอนุมัติ").
        $andResult = 'approved';
        foreach ($steps as $s) {
            if ($s['group_type'] !== 'and') {
                continue;
            }
            $r = $stepResult($s);
            if ($r === 'rejected') {
                $andResult = 'rejected';
                break;
            }
            if ($r === 'pending') {
                $andResult = 'pending';
            }
        }

        // OR: with 0 or 1 OR-group step, OR has no effect on the outcome either way ("ถ้ามี OR แค่
        // 1 แถว ผล OR ไม่มีผล"). With 2+, one approval satisfies it; all rejected fails it
        // ("ถ้ามี OR มากกว่า 1 แถว เอาผลอนุมัติแค่อย่างน้อย 1 แถว").
        $orSteps = array_values(array_filter($steps, fn($s) => $s['group_type'] === 'or'));
        $orResult = 'approved';
        if (count($orSteps) >= 2) {
            $anyApproved = false;
            $allRejected = true;
            foreach ($orSteps as $s) {
                $r = $stepResult($s);
                if ($r === 'approved') {
                    $anyApproved = true;
                }
                if ($r !== 'rejected') {
                    $allRejected = false;
                }
            }
            $orResult = $anyApproved ? 'approved' : ($allRejected ? 'rejected' : 'pending');
        }

        $final = ($andResult === 'rejected' || $orResult === 'rejected') ? 'rejected'
            : (($andResult === 'approved' && $orResult === 'approved') ? 'approved' : 'pending');

        if ($final === 'approved' || $final === 'rejected') {
            $this->applyFinalStatus($requestId, $final);
            return $final;
        }
        $this->refreshCurrentStepOrderPointer($requestId);
        return null;
    }

    private function applyFinalStatus(int $requestId, string $status): void {
        $stmt = $this->db->prepare("UPDATE `approval_requests`
            SET status = :status, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $requestId]);
    }

    /** current_step_order is display-only under the gating model (see approval_requests' own
     *  column comment) -- set to the lowest step_order still pending, purely so
     *  list()/PayslipRequestModel's "current step" label has something reasonable to show. */
    private function refreshCurrentStepOrderPointer(int $requestId): void {
        $stmt = $this->db->prepare("SELECT MIN(step_order) FROM `approval_request_step_approvers`
            WHERE request_id = :id AND status = 'pending'");
        $stmt->execute([':id' => $requestId]);
        $min = $stmt->fetchColumn();
        if ($min !== false && $min !== null) {
            $this->db->prepare("UPDATE `approval_requests` SET current_step_order = :step WHERE id = :id")
                ->execute([':step' => (int)$min, ':id' => $requestId]);
        }
    }

    private function finalize(int $requestId, int $stepOrder, string $action, int $userId, ?string $note, string $newStatus, ?string $stepNameSnapshot): array {
        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            $this->logAction($requestId, $stepOrder, $stepNameSnapshot, $action, $userId, $note);
            $stmt = $this->db->prepare("UPDATE `approval_requests` SET status = :status, completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([':status' => $newStatus, ':id' => $requestId]);
            if ($own) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => ucfirst($newStatus) . '.', 'request_status' => $newStatus];
        } catch (PDOException $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    private function logAction(int $requestId, int $stepOrder, ?string $stepName, string $action, int $userId, ?string $note): void {
        $stmt = $this->db->prepare("INSERT INTO `approval_request_logs` (request_id, step_order, step_name_snapshot, action, acted_by, note)
            VALUES (:request_id, :step_order, :step_name, :action, :acted_by, :note)");
        $stmt->execute([
            ':request_id' => $requestId,
            ':step_order' => $stepOrder,
            ':step_name' => $stepName,
            ':action' => $action,
            ':acted_by' => $userId,
            ':note' => $note,
        ]);
    }

    public function logs(int $compId, int $requestId): array {
        $request = $this->get($compId, $requestId);
        if (!$request) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT l.*, CONCAT(e.name_th, ' ', e.surname_th) AS acted_by_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS acted_by_name_en
            FROM `approval_request_logs` l
            LEFT JOIN `employees` e ON e.id = l.acted_by
            WHERE l.request_id = :request_id ORDER BY l.acted_at ASC");
        $stmt->execute([':request_id' => $requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array{status?: string, document_type_code?: string} $filters */
    public function list(int $compId, array $filters = []): array {
        $where = "WHERE r.comp_id = :comp_id";
        $params = [':comp_id' => $compId];
        if (!empty($filters['status'])) {
            $where .= " AND r.status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['document_type_code'])) {
            $where .= " AND r.document_type_code = :document_type_code";
            $params[':document_type_code'] = $filters['document_type_code'];
        }
        $sql = "SELECT r.*, w.workflow_name, dt.name_th AS document_type_name_th, dt.name_en AS document_type_name_en,
                    s.step_name AS current_step_name,
                    CONCAT(e.name_th, ' ', e.surname_th) AS requested_by_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS requested_by_name_en
                FROM `approval_requests` r
                JOIN `approval_workflows` w ON w.id = r.workflow_id
                JOIN `approval_document_types` dt ON dt.code = r.document_type_code
                LEFT JOIN `approval_workflow_steps` s ON s.workflow_id = r.workflow_id AND s.step_order = r.current_step_order
                LEFT JOIN `employees` e ON e.id = r.requested_by
                {$where}
                ORDER BY r.requested_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
