<?php
/**
 * Lightweight verification script for the Approval Workflow engine (ApprovalWorkflowModel +
 * ApprovalRequestModel). Not PHPUnit — see tests/statutory_engine_test.php for why.
 * Runs against the real dev DB inside a transaction that is always rolled back.
 * Run with: php tests/approval_workflow_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/ApprovalWorkflowModel.php';
require_once __DIR__ . '/../app/models/ApprovalRequestModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    $ok = (is_float($expected) || is_float($actual)) ? abs((float)$actual - (float)$expected) < 0.005 : $actual === $expected;
    if ($ok) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue(string $label, bool $actual): void {
    check($label, $actual, true);
}

function makeEmployee(PDO $pdo, int $compId, string $employeeNo, ?int $roleId): int {
    $stmt = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         role_id, personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, department_id)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
         :role_id, :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', 30000, '2020-01-01', 'average', 'active',
         1, 1, 0, NULL)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => $employeeNo,
        ':name_th' => 'ทดสอบ', ':surname_th' => $employeeNo, ':name_en' => 'Test', ':surname_en' => $employeeNo,
        ':role_id' => $roleId, ':email' => uniqid() . '@test.local',
    ]);
    return (int)$pdo->lastInsertId();
}

try {
    $compId = 1;
    $adminUserId = 1;

    // Real dev-DB config, not a test fixture: this shared DB may already have a real ACTIVE
    // workflow mapped to PAYROLL_RUN_APPROVAL and/or SLIP_REQUEST_APPROVAL (the exact kind of row
    // documented in feedback_dev_db_shared_state_test_fragility -- see also
    // tests/payroll_run_test.php's own copy of this same neutralization). Every save() below that
    // maps to either document type would otherwise conflict with it. Deactivated for the duration
    // of this transaction only, rolled back at the end.
    $pdo->prepare("UPDATE `approval_workflows` w
        JOIN `approval_workflow_document_types` awdt ON awdt.workflow_id = w.id
        SET w.status = 'inactive'
        WHERE w.comp_id = :comp_id AND awdt.document_type_code IN ('PAYROLL_RUN_APPROVAL', 'SLIP_REQUEST_APPROVAL') AND w.status = 'active'")
        ->execute([':comp_id' => $compId]);

    // ---------- Fixtures ----------
    $stmtRole = $pdo->prepare("INSERT INTO `structure_roles` (comp_id, role_name_th, role_name_en, can_approve_payroll) VALUES (:comp_id, :th, :en, 1)");
    $stmtRole->execute([':comp_id' => $compId, ':th' => 'ทดสอบผู้อนุมัติ', ':en' => 'Test Approver Role ' . uniqid()]);
    $testRoleId = (int)$pdo->lastInsertId();

    $employeeA = makeEmployee($pdo, $compId, 'AWF_A_' . uniqid(), null);     // step 1 approver (specific user)
    $employeeB = makeEmployee($pdo, $compId, 'AWF_B_' . uniqid(), $testRoleId); // step 2 approver pool (role, joint=all)
    $employeeC = makeEmployee($pdo, $compId, 'AWF_C_' . uniqid(), $testRoleId); // step 2 approver pool (role, joint=all)
    $employeeD = makeEmployee($pdo, $compId, 'AWF_D_' . uniqid(), null);     // requester, not an approver anywhere

    $wfModel = new ApprovalWorkflowModel($pdo);
    $reqModel = new ApprovalRequestModel($pdo);

    // ---------- ApprovalWorkflowModel::save() validation ----------
    echo "=== ApprovalWorkflowModel::save() validation ===\n";
    $noSteps = $wfModel->save($compId, [
        'workflow_name' => 'No Steps', 'document_type_codes' => ['PAYROLL_RUN_APPROVAL'], 'steps' => [],
    ], $adminUserId);
    checkTrue('rejects a workflow with zero steps', $noSteps['status'] === false);

    $badApprover = $wfModel->save($compId, [
        'workflow_name' => 'Bad Approver', 'document_type_codes' => ['PAYROLL_RUN_APPROVAL'],
        'steps' => [['approvers' => [['approver_type' => 'user', 'approver_id' => 9999999]]]],
    ], $adminUserId);
    checkTrue('rejects a step with a non-existent approver_id', $badApprover['status'] === false);

    $noApprovers = $wfModel->save($compId, [
        'workflow_name' => 'No Approvers', 'document_type_codes' => ['PAYROLL_RUN_APPROVAL'],
        'steps' => [['approvers' => []]],
    ], $adminUserId);
    checkTrue('rejects a step with an empty approvers list', $noApprovers['status'] === false);

    $badDocType = $wfModel->save($compId, [
        'workflow_name' => 'Bad Doc Type', 'document_type_codes' => ['NOT_A_REAL_CODE'],
        'steps' => [['approvers' => [['approver_type' => 'user', 'approver_id' => $employeeA]]]],
    ], $adminUserId);
    checkTrue('rejects an unknown document_type_code', $badDocType['status'] === false);

    // ---------- Create a real 2-step workflow ----------
    $wf1Res = $wfModel->save($compId, [
        'workflow_name' => 'AWF Test Workflow ' . uniqid(),
        'description' => 'End-to-end test workflow',
        'status' => 'active',
        'document_type_codes' => ['PAYROLL_RUN_APPROVAL'],
        'steps' => [
            ['step_name' => 'Manager Review', 'approvers' => [['approver_type' => 'user', 'approver_id' => $employeeA]], 'joint_approve_mode' => 'any'],
            ['step_name' => 'Finance Sign-off', 'approvers' => [['approver_type' => 'role', 'approver_id' => $testRoleId]], 'joint_approve_mode' => 'all', 'timeout_hours' => 48],
        ],
    ], $adminUserId);
    checkTrue('fixture: 2-step workflow created', $wf1Res['status']);
    $wf1Id = $wf1Res['id'];

    // A second workflow mapped to the SAME (still-active) document type must be rejected.
    $conflictRes = $wfModel->save($compId, [
        'workflow_name' => 'Conflicting Workflow', 'status' => 'active',
        'document_type_codes' => ['PAYROLL_RUN_APPROVAL'],
        'steps' => [['approvers' => [['approver_type' => 'user', 'approver_id' => $employeeA]]]],
    ], $adminUserId);
    checkTrue('rejects a second ACTIVE workflow mapped to the same document type', $conflictRes['status'] === false);

    // ---------- get() round-trip ----------
    echo "=== ApprovalWorkflowModel::get() ===\n";
    $wf1 = $wfModel->get($compId, $wf1Id);
    check('document_type_codes round-trips', $wf1['document_type_codes'], ['PAYROLL_RUN_APPROVAL']);
    check('2 steps round-trip in order', count($wf1['steps']), 2);
    check('step 1 has exactly one approver entry', count($wf1['steps'][0]['approvers']), 1);
    check('step 1 approver_type', $wf1['steps'][0]['approvers'][0]['approver_type'], 'user');
    check('step 2 joint_approve_mode', $wf1['steps'][1]['joint_approve_mode'], 'all');

    // ---------- list() ----------
    echo "=== ApprovalWorkflowModel::list() ===\n";
    $list = $wfModel->list($compId);
    $found = array_filter($list, fn($w) => (int)$w['id'] === $wf1Id);
    checkTrue('list() includes the new workflow', count($found) === 1);
    $listedRow = array_values($found)[0];
    check('list() reports step_count = 2', (int)$listedRow['step_count'], 2);

    // ---------- ApprovalRequestModel::create() ----------
    echo "=== ApprovalRequestModel::create() ===\n";
    $createReqRes = $reqModel->create($compId, 'PAYROLL_RUN_APPROVAL', 555, 'Test Run - July 2026', $employeeD);
    checkTrue('request created against the active workflow', $createReqRes['status']);
    $requestId = $createReqRes['id'];

    $request = $reqModel->get($compId, $requestId);
    check('new request starts at step 1', (int)$request['current_step_order'], 1);
    check('new request status is pending', $request['status'], 'pending');

    $noWorkflowRes = $reqModel->create($compId, 'SLIP_REQUEST_APPROVAL', 1, null, $employeeD);
    checkTrue('rejects creating a request for a document type with no active workflow', $noWorkflowRes['status'] === false);

    // ---------- Act: unauthorized ----------
    echo "=== ApprovalRequestModel::act() — authorization ===\n";
    $unauthorized = $reqModel->act($compId, $requestId, $employeeD, 'approve');
    checkTrue('requester D cannot approve step 1 (not the assigned approver)', $unauthorized['status'] === false);

    // ---------- Act: step 1 (specific user) ----------
    echo "=== ApprovalRequestModel::act() — step progression ===\n";
    $step1Approve = $reqModel->act($compId, $requestId, $employeeA, 'approve');
    checkTrue('employee A approves step 1', $step1Approve['status']);
    check('request advances to step 2', (int)$reqModel->get($compId, $requestId)['current_step_order'], 2);
    check('request still pending after step 1', $reqModel->get($compId, $requestId)['status'], 'pending');

    // ---------- Act: step 2 (role, joint=all) — partial then complete ----------
    $step2ApproveB = $reqModel->act($compId, $requestId, $employeeB, 'approve');
    checkTrue('employee B (role holder 1/2) approves step 2', $step2ApproveB['status']);
    check('request stays at step 2 — B alone is not enough (joint=all)', (int)$reqModel->get($compId, $requestId)['current_step_order'], 2);
    check('request still pending after partial joint approval', $reqModel->get($compId, $requestId)['status'], 'pending');

    $step2ApproveC = $reqModel->act($compId, $requestId, $employeeC, 'approve');
    checkTrue('employee C (role holder 2/2) approves step 2', $step2ApproveC['status']);
    check('request fully approved once both role holders acted', $reqModel->get($compId, $requestId)['status'], 'approved');
    checkTrue('completed_at is set', $reqModel->get($compId, $requestId)['completed_at'] !== null);

    $alreadyDone = $reqModel->act($compId, $requestId, $employeeA, 'approve');
    checkTrue('acting on an already-approved request is rejected', $alreadyDone['status'] === false);

    // ---------- logs() ----------
    $logs = $reqModel->logs($compId, $requestId);
    check('3 log entries recorded (A approve, B approve, C approve)', count($logs), 3);

    // ---------- Reject flow ----------
    echo "=== Reject flow ===\n";
    $reqRejectRes = $reqModel->create($compId, 'PAYROLL_RUN_APPROVAL', 556, 'Test Run - reject case', $employeeD);
    $rejectId = $reqRejectRes['id'];
    $rejectAct = $reqModel->act($compId, $rejectId, $employeeA, 'reject', 'Numbers look wrong');
    checkTrue('employee A rejects at step 1', $rejectAct['status']);
    check('request status is rejected', $reqModel->get($compId, $rejectId)['status'], 'rejected');

    // ---------- Cancel flow ----------
    echo "=== Cancel flow ===\n";
    $reqCancelRes = $reqModel->create($compId, 'PAYROLL_RUN_APPROVAL', 557, 'Test Run - cancel case', $employeeD);
    $cancelId = $reqCancelRes['id'];
    $cancelByOther = $reqModel->act($compId, $cancelId, $employeeA, 'cancel');
    checkTrue('a non-requester cannot cancel', $cancelByOther['status'] === false);
    $cancelByRequester = $reqModel->act($compId, $cancelId, $employeeD, 'cancel');
    checkTrue('the requester can cancel their own request', $cancelByRequester['status']);
    check('request status is cancelled', $reqModel->get($compId, $cancelId)['status'], 'cancelled');

    // ---------- delete() blocked by pending requests ----------
    echo "=== ApprovalWorkflowModel::delete() ===\n";
    $wf2Res = $wfModel->save($compId, [
        'workflow_name' => 'AWF Slip Workflow ' . uniqid(), 'status' => 'active',
        'document_type_codes' => ['SLIP_REQUEST_APPROVAL'],
        'steps' => [['approvers' => [['approver_type' => 'user', 'approver_id' => $employeeA]]]],
    ], $adminUserId);
    $wf2Id = $wf2Res['id'];
    $reqModel->create($compId, 'SLIP_REQUEST_APPROVAL', 1, null, $employeeD);
    $deleteBlocked = $wfModel->delete($compId, $wf2Id, $adminUserId);
    checkTrue('delete blocked while a pending request references this workflow', $deleteBlocked['status'] === false);

    // ---------- duplicate() + toggleStatus() ----------
    echo "=== ApprovalWorkflowModel::duplicate() / toggleStatus() ===\n";
    $dupRes = $wfModel->duplicate($compId, $wf1Id, $adminUserId);
    checkTrue('duplicate created', $dupRes['status']);
    $dupId = $dupRes['id'];
    $dup = $wfModel->get($compId, $dupId);
    check('duplicate forced to inactive', $dup['status'], 'inactive');
    check('duplicate copied both steps', count($dup['steps']), 2);
    check('duplicate name suffixed', str_ends_with($dup['workflow_name'], '(Copy)'), true);

    $activateConflict = $wfModel->toggleStatus($compId, $dupId, $adminUserId, 'active');
    checkTrue('activating the duplicate conflicts with the still-active original', $activateConflict['status'] === false);

    $deactivateOriginal = $wfModel->toggleStatus($compId, $wf1Id, $adminUserId, 'inactive');
    checkTrue('deactivate the original workflow', $deactivateOriginal['status']);
    $activateAfter = $wfModel->toggleStatus($compId, $dupId, $adminUserId, 'active');
    checkTrue('activating the duplicate now succeeds (no more conflict)', $activateAfter['status']);

    // ---------- Multi-approver-per-step + persisted eligibility snapshot (2026-08-23) ----------
    // Explicit request: "ในแต่ละแถวย่อยก็สามารถใส่ได้หลายคน" (one step can list multiple people --
    // here a mix of a specific user AND a role) and "สร้างตารางเก็บรายการ Approve ของแต่ละ Process
    // แล้วก็ดึงจากตารางนั้นว่าใครมีสิทธิ์ Approve บ้าง...ไม่ใช่ไปดึงข้อมูลใหม่ทุกรอบ" (persist the
    // eligible list once into a table, read from THAT afterwards -- verified here directly against
    // approval_request_step_approvers, not just through the model's own read methods).
    echo "=== Multi-approver-per-step + persisted eligibility snapshot ===\n";
    // SLIP_REQUEST_APPROVAL is still claimed by the earlier wf2 fixture (left active on purpose,
    // to exercise delete()'s pending-request block) -- free it up for this section.
    $wfModel->toggleStatus($compId, $wf2Id, $adminUserId, 'inactive');
    $wf3Res = $wfModel->save($compId, [
        'workflow_name' => 'AWF Multi-Approver Workflow ' . uniqid(), 'status' => 'active',
        'document_type_codes' => ['SLIP_REQUEST_APPROVAL'],
        'steps' => [[
            'step_name' => 'Either Manager or Finance Team',
            // A specific user (employeeA) PLUS a role (testRoleId, held by B and C) in the SAME
            // step -- resolved pool must be the union {A, B, C}. joint_approve_mode='any' -> the
            // first of the three to act decides the whole step (one shared snapshot row expected).
            'approvers' => [
                ['approver_type' => 'user', 'approver_id' => $employeeA],
                ['approver_type' => 'role', 'approver_id' => $testRoleId],
            ],
            'joint_approve_mode' => 'any',
        ]],
    ], $adminUserId);
    checkTrue('fixture: multi-approver step workflow created', $wf3Res['status']);
    $wf3 = $wfModel->get($compId, $wf3Res['id']);
    check('step has both approver entries persisted', count($wf3['steps'][0]['approvers']), 2);

    $anyModeRequestId = $reqModel->create($compId, 'SLIP_REQUEST_APPROVAL', 900, 'Multi-approver any-mode case', $employeeD)['id'];

    $snapshotAny = $pdo->prepare("SELECT * FROM `approval_request_step_approvers` WHERE request_id = :id");
    $snapshotAny->execute([':id' => $anyModeRequestId]);
    $anyRows = $snapshotAny->fetchAll(PDO::FETCH_ASSOC);
    check('joint=any with a 2-entry step (user + role of 2) snapshots as ONE shared row', count($anyRows), 1);
    $anyEligible = array_map('intval', explode(',', $anyRows[0]['eligible_employee_ids']));
    sort($anyEligible);
    $expectedPool = [$employeeA, $employeeB, $employeeC];
    sort($expectedPool);
    check('shared row eligibility is the resolved union {A, B, C}', $anyEligible, $expectedPool);

    checkTrue('employee C (only via the role entry, never listed directly) can act', $reqModel->canActOnRequest($compId, $anyModeRequestId, $employeeC));
    checkTrue('an outsider (employee D, the requester) cannot act', !$reqModel->canActOnRequest($compId, $anyModeRequestId, $employeeD));

    $anyPool = $reqModel->currentStepApprovers($compId, $anyModeRequestId);
    check('currentStepApprovers() expands the shared row into 3 people', count($anyPool), 3);

    $cActs = $reqModel->act($compId, $anyModeRequestId, $employeeC, 'approve');
    checkTrue('employee C approves (any mode -- first to act decides it)', $cActs['status']);
    check('request fully approved after just one of the three acted', $reqModel->get($compId, $anyModeRequestId)['status'], 'approved');

    // Same step definition, but joint_approve_mode='all' this time -- storage must switch to one
    // row PER eligible person (3 individually-tracked rows for {A, B, C}), exactly as specified.
    $wf4Res = $wfModel->save($compId, [
        'workflow_name' => 'AWF Multi-Approver All-Mode Workflow ' . uniqid(), 'status' => 'active',
        'document_type_codes' => ['PAYROLL_RUN_APPROVAL'],
        'steps' => [[
            'approvers' => [
                ['approver_type' => 'user', 'approver_id' => $employeeA],
                ['approver_type' => 'role', 'approver_id' => $testRoleId],
            ],
            'joint_approve_mode' => 'all',
        ]],
    ], $adminUserId);
    // (PAYROLL_RUN_APPROVAL is already claimed by the still-active duplicate from the section
    // above -- deactivate it first.)
    checkTrue('rejects while the duplicate workflow still holds PAYROLL_RUN_APPROVAL active', $wf4Res['status'] === false);
    $wfModel->toggleStatus($compId, $dupId, $adminUserId, 'inactive');
    $wf4Res = $wfModel->save($compId, [
        'workflow_name' => 'AWF Multi-Approver All-Mode Workflow ' . uniqid(), 'status' => 'active',
        'document_type_codes' => ['PAYROLL_RUN_APPROVAL'],
        'steps' => [[
            'approvers' => [
                ['approver_type' => 'user', 'approver_id' => $employeeA],
                ['approver_type' => 'role', 'approver_id' => $testRoleId],
            ],
            'joint_approve_mode' => 'all',
        ]],
    ], $adminUserId);
    checkTrue('fixture: all-mode multi-approver workflow created', $wf4Res['status']);

    $allModeRequestId = $reqModel->create($compId, 'PAYROLL_RUN_APPROVAL', 901, 'Multi-approver all-mode case', $employeeD)['id'];
    $snapshotAll = $pdo->prepare("SELECT * FROM `approval_request_step_approvers` WHERE request_id = :id");
    $snapshotAll->execute([':id' => $allModeRequestId]);
    $allRows = $snapshotAll->fetchAll(PDO::FETCH_ASSOC);
    check('joint=all with a resolved pool of 3 snapshots as 3 individual rows', count($allRows), 3);
    check('each all-mode row eligibility is a single id (not a CSV group)', array_filter(array_map(fn($r) => str_contains($r['eligible_employee_ids'], ','), $allRows)), []);

    $reqModel->act($compId, $allModeRequestId, $employeeA, 'approve');
    check('after 1 of 3 approves (all mode), request is still pending', $reqModel->get($compId, $allModeRequestId)['status'], 'pending');
    $reqModel->act($compId, $allModeRequestId, $employeeB, 'approve');
    check('after 2 of 3 approves (all mode), request is still pending', $reqModel->get($compId, $allModeRequestId)['status'], 'pending');
    $reqModel->act($compId, $allModeRequestId, $employeeC, 'approve');
    check('after all 3 approve (all mode), request is approved', $reqModel->get($compId, $allModeRequestId)['status'], 'approved');

    // ---------- Per-step sequencing (requires_previous_step) + AND/OR/Finish verdict (2026-08-23)
    // ---------- Explicit request: "อนุมัติตาม Step หมายถึง...จะยังไม่เปิดปุ่มให้ แต่จะเห็นเป็นจุดๆ
    // ว่าตัวเองอยู่ตำแหน่งไหน...สามารถตั้งค่าได้แบบอิสระ เช่น 1 ก่อนค่อย 2 แต่ 3 อนุมัติได้เลยไม่ต้องรอ
    // Step" and "AND หมายถึงทุกแถวของ AND ต้องอนุมัติ...ถ้ามี OR แค่ 1 แถว ผล OR ไม่มีผล ถ้ามี OR
    // มากกว่า 1 แถว เอาผลอนุมัติแค่อย่างน้อย 1 แถว ถ้าเป็น Finish คือไม่สนใจผลของคนอื่นเลย".
    echo "=== Per-step sequencing (requires_previous_step) ===\n";
    $employeeE = makeEmployee($pdo, $compId, 'AWF_E_' . uniqid(), null); // step 3, NOT gated
    $wfModel->toggleStatus($compId, $wf3Res['id'], $adminUserId, 'inactive');
    $wfGateRes = $wfModel->save($compId, [
        'workflow_name' => 'AWF Gating Workflow ' . uniqid(), 'status' => 'active',
        'document_type_codes' => ['SLIP_REQUEST_APPROVAL'],
        'steps' => [
            ['step_name' => 'Step 1', 'approvers' => [['approver_type' => 'user', 'approver_id' => $employeeA]], 'requires_previous_step' => true],
            ['step_name' => 'Step 2', 'approvers' => [['approver_type' => 'user', 'approver_id' => $employeeB]], 'requires_previous_step' => true],
            ['step_name' => 'Step 3 (not sequenced)', 'approvers' => [['approver_type' => 'user', 'approver_id' => $employeeE]], 'requires_previous_step' => false],
        ],
    ], $adminUserId);
    checkTrue('fixture: 3-step gating workflow created', $wfGateRes['status']);
    $gateRequestId = $reqModel->create($compId, 'SLIP_REQUEST_APPROVAL', 902, 'Gating case', $employeeD)['id'];

    $lockedAttempt = $reqModel->act($compId, $gateRequestId, $employeeB, 'approve');
    checkTrue('step 2 (sequenced) is locked -- B cannot act while step 1 is still pending', $lockedAttempt['status'] === false);
    $unsequencedAttempt = $reqModel->act($compId, $gateRequestId, $employeeE, 'approve');
    checkTrue('step 3 (NOT sequenced) is always actionable -- E can approve immediately, ignoring the queue', $unsequencedAttempt['status']);
    check('request still pending after only the un-sequenced step 3 is done', $reqModel->get($compId, $gateRequestId)['status'], 'pending');

    $step1Res = $reqModel->act($compId, $gateRequestId, $employeeA, 'approve');
    checkTrue('employee A approves step 1', $step1Res['status']);
    $step2NowRes = $reqModel->act($compId, $gateRequestId, $employeeB, 'approve');
    checkTrue('step 2 unlocks once step 1 is fully approved -- B can now act', $step2NowRes['status']);
    check('all 3 steps approved (2 sequenced + 1 free) => request approved', $reqModel->get($compId, $gateRequestId)['status'], 'approved');

    echo "=== AND / OR (count=1, no effect) verdict ===\n";
    $employeeF = makeEmployee($pdo, $compId, 'AWF_F_' . uniqid(), null); // AND step
    $employeeG = makeEmployee($pdo, $compId, 'AWF_G_' . uniqid(), null); // lone OR step
    $wfModel->toggleStatus($compId, $wfGateRes['id'], $adminUserId, 'inactive');
    $wfAndOrRes = $wfModel->save($compId, [
        'workflow_name' => 'AWF AND+lone-OR Workflow ' . uniqid(), 'status' => 'active',
        'document_type_codes' => ['SLIP_REQUEST_APPROVAL'],
        'steps' => [
            ['step_name' => 'AND step', 'approvers' => [['approver_type' => 'user', 'approver_id' => $employeeF]], 'group_type' => 'and'],
            ['step_name' => 'Lone OR step', 'approvers' => [['approver_type' => 'user', 'approver_id' => $employeeG]], 'group_type' => 'or'],
        ],
    ], $adminUserId);
    checkTrue('fixture: AND + lone-OR workflow created', $wfAndOrRes['status']);
    $andOrRequestId = $reqModel->create($compId, 'SLIP_REQUEST_APPROVAL', 903, 'AND + lone OR case', $employeeD)['id'];

    $gRejects = $reqModel->act($compId, $andOrRequestId, $employeeG, 'reject', 'irrelevant');
    checkTrue('G rejects the lone OR step', $gRejects['status']);
    check('a lone OR step (count=1) has NO EFFECT -- rejecting it does not reject the request', $reqModel->get($compId, $andOrRequestId)['status'], 'pending');
    $fApproves = $reqModel->act($compId, $andOrRequestId, $employeeF, 'approve');
    checkTrue('F approves the AND step', $fApproves['status']);
    check('request is approved once the AND step passes, despite the earlier lone-OR rejection', $reqModel->get($compId, $andOrRequestId)['status'], 'approved');

    echo "=== OR with 2+ rows: any one approval passes, all rejected fails ===\n";
    $employeeH = makeEmployee($pdo, $compId, 'AWF_H_' . uniqid(), null);
    $employeeI = makeEmployee($pdo, $compId, 'AWF_I_' . uniqid(), null);
    $wfModel->toggleStatus($compId, $wfAndOrRes['id'], $adminUserId, 'inactive');
    $wfOrMultiRes = $wfModel->save($compId, [
        'workflow_name' => 'AWF OR-multi Workflow ' . uniqid(), 'status' => 'active',
        'document_type_codes' => ['SLIP_REQUEST_APPROVAL'],
        'steps' => [
            ['step_name' => 'OR A', 'approvers' => [['approver_type' => 'user', 'approver_id' => $employeeH]], 'group_type' => 'or'],
            ['step_name' => 'OR B', 'approvers' => [['approver_type' => 'user', 'approver_id' => $employeeI]], 'group_type' => 'or'],
        ],
    ], $adminUserId);
    checkTrue('fixture: OR-multi workflow created', $wfOrMultiRes['status']);
    $orPassRequestId = $reqModel->create($compId, 'SLIP_REQUEST_APPROVAL', 904, 'OR any-one-passes case', $employeeD)['id'];
    $reqModel->act($compId, $orPassRequestId, $employeeH, 'reject', 'no');
    check('one OR row rejected, the other still pending -- request stays pending', $reqModel->get($compId, $orPassRequestId)['status'], 'pending');
    $reqModel->act($compId, $orPassRequestId, $employeeI, 'approve');
    check('the OTHER OR row approving is enough to pass the whole request', $reqModel->get($compId, $orPassRequestId)['status'], 'approved');

    $orFailRequestId = $reqModel->create($compId, 'SLIP_REQUEST_APPROVAL', 905, 'OR all-rejected case', $employeeD)['id'];
    $reqModel->act($compId, $orFailRequestId, $employeeH, 'reject', 'no');
    $reqModel->act($compId, $orFailRequestId, $employeeI, 'reject', 'no');
    check('every OR row rejected -- the request is rejected', $reqModel->get($compId, $orFailRequestId)['status'], 'rejected');

    echo "=== Finish: decides the whole request immediately, ignoring every other step ===\n";
    $employeeJ = makeEmployee($pdo, $compId, 'AWF_J_' . uniqid(), null); // AND step, left undecided
    $employeeK = makeEmployee($pdo, $compId, 'AWF_K_' . uniqid(), null); // Finish step
    $wfModel->toggleStatus($compId, $wfOrMultiRes['id'], $adminUserId, 'inactive');
    $wfFinishRes = $wfModel->save($compId, [
        'workflow_name' => 'AWF Finish Workflow ' . uniqid(), 'status' => 'active',
        'document_type_codes' => ['SLIP_REQUEST_APPROVAL'],
        'steps' => [
            ['step_name' => 'AND step (never decided)', 'approvers' => [['approver_type' => 'user', 'approver_id' => $employeeJ]], 'group_type' => 'and'],
            ['step_name' => 'Finish step', 'approvers' => [['approver_type' => 'user', 'approver_id' => $employeeK]], 'group_type' => 'finish'],
        ],
    ], $adminUserId);
    checkTrue('fixture: Finish workflow created', $wfFinishRes['status']);
    $finishRequestId = $reqModel->create($compId, 'SLIP_REQUEST_APPROVAL', 906, 'Finish short-circuit case', $employeeD)['id'];
    $kRejects = $reqModel->act($compId, $finishRequestId, $employeeK, 'reject', 'done');
    checkTrue('K rejects the Finish step', $kRejects['status']);
    check('Finish step decides the WHOLE request immediately -- rejected, even though the AND step never acted', $reqModel->get($compId, $finishRequestId)['status'], 'rejected');
    $jTooLate = $reqModel->act($compId, $finishRequestId, $employeeJ, 'approve');
    checkTrue('the AND step can no longer act at all once Finish has already decided the request', $jTooLate['status'] === false);

    // ---------- Soft-delete-on-change for approval_workflow_steps (2026-08-23) ---------- Explicit
    // request: "ถ้ามีการ Save Request ใหม่ Approval Flow ต้องเปลี่ยน Flow เดิมให้ลบไปเลยถ้าไม่ใช่
    // ข้อมูลเดิม แต่ต้องเป็น Soft Delete".
    echo "=== save() soft-deletes the old step set only when it actually changed ===\n";
    $wfModel->toggleStatus($compId, $wfFinishRes['id'], $adminUserId, 'inactive');
    $wfSoftDelRes = $wfModel->save($compId, [
        'workflow_name' => 'AWF Soft-Delete Workflow ' . uniqid(), 'status' => 'active',
        'document_type_codes' => ['SLIP_REQUEST_APPROVAL'],
        'steps' => [['step_name' => 'Only step', 'approvers' => [['approver_type' => 'user', 'approver_id' => $employeeA]], 'joint_approve_mode' => 'any']],
    ], $adminUserId);
    checkTrue('fixture: soft-delete-test workflow created', $wfSoftDelRes['status']);
    $wfSoftDelId = $wfSoftDelRes['id'];
    $originalStepId = (int)$pdo->query("SELECT id FROM `approval_workflow_steps` WHERE workflow_id = {$wfSoftDelId} AND status = 'active'")->fetchColumn();
    $wfSoftDelName = $wfModel->get($compId, $wfSoftDelId)['workflow_name'];

    // Re-saving with the EXACT same payload must leave the existing active step row untouched.
    $unchangedSave = $wfModel->save($compId, [
        'id' => $wfSoftDelId, 'workflow_name' => $wfSoftDelName,
        'status' => 'active', 'document_type_codes' => ['SLIP_REQUEST_APPROVAL'],
        'steps' => [['step_name' => 'Only step', 'approvers' => [['approver_type' => 'user', 'approver_id' => $employeeA]], 'joint_approve_mode' => 'any']],
    ], $adminUserId);
    checkTrue('unchanged re-save succeeds', $unchangedSave['status']);
    $sameStepId = (int)$pdo->query("SELECT id FROM `approval_workflow_steps` WHERE workflow_id = {$wfSoftDelId} AND status = 'active'")->fetchColumn();
    check('an unchanged re-save keeps the SAME step id (no churn)', $sameStepId, $originalStepId);
    $deletedCountAfterNoop = (int)$pdo->query("SELECT COUNT(*) FROM `approval_workflow_steps` WHERE workflow_id = {$wfSoftDelId} AND status = 'deleted'")->fetchColumn();
    check('no rows were soft-deleted by the unchanged re-save', $deletedCountAfterNoop, 0);

    // Now actually change the joint_approve_mode -- the old row must be SOFT-deleted (not hard
    // deleted) and a fresh active row inserted.
    $changedSave = $wfModel->save($compId, [
        'id' => $wfSoftDelId, 'workflow_name' => $wfSoftDelName,
        'status' => 'active', 'document_type_codes' => ['SLIP_REQUEST_APPROVAL'],
        'steps' => [['step_name' => 'Only step', 'approvers' => [['approver_type' => 'user', 'approver_id' => $employeeA]], 'joint_approve_mode' => 'all']],
    ], $adminUserId);
    checkTrue('changed re-save succeeds', $changedSave['status']);
    $newStepId = (int)$pdo->query("SELECT id FROM `approval_workflow_steps` WHERE workflow_id = {$wfSoftDelId} AND status = 'active'")->fetchColumn();
    checkTrue('a genuinely changed re-save gets a NEW step id', $newStepId !== $originalStepId);
    $oldStepStatus = $pdo->query("SELECT status, deleted_at FROM `approval_workflow_steps` WHERE id = {$originalStepId}")->fetch(PDO::FETCH_ASSOC);
    check('the old step row still EXISTS (soft delete, not hard delete)', $oldStepStatus !== false, true);
    check('the old step row is marked deleted', $oldStepStatus['status'], 'deleted');
    checkTrue('the old step row has a deleted_at timestamp', $oldStepStatus['deleted_at'] !== null);
    check('get() no longer surfaces the soft-deleted step', count($wfModel->get($compId, $wfSoftDelId)['steps']), 1);

    echo "=== 2026-08-24: per-row Settings UI methods (getByDocumentType/stepSave/stepDelete/" .
        "stepsSort) -- backs the new 2-tab, no-modal, row-by-row Approval Workflow settings page" .
        " (explicit request: \"1 Tab ต่อ 1 Flow ไม่ต้องเปิด Modal เข้าไปจัดการ แต่เป็นการเปิดแก้ไข แถว" .
        " by แถว มีปุ่ม Save แยกตามแถว และมีปุ่มในการบันทึก Sort\"). Uses a FRESH throwaway company" .
        " (not comp_id=1) so getByDocumentType() genuinely starts from null -- comp_id=1's own" .
        " PAYROLL_RUN_APPROVAL/SLIP_REQUEST_APPROVAL have real, already-active workflows from" .
        " earlier sections in this very file (getByDocumentType() falls back to the most recently" .
        " updated INACTIVE one when none is active, by design -- see its own docblock -- so merely" .
        " deactivating one of those would NOT produce a null starting state here). ===\n";
    $insWfCo = $pdo->prepare("INSERT INTO `companies`
        (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES ('AWF Row-Level Test Co.', 'AWF Row-Level Test Co.', 'TH', '0000000000001', 'Test Address', 'Test Signatory')");
    $insWfCo->execute();
    $wfTestCompId = (int)$pdo->lastInsertId();
    $wfTestEmpX = makeEmployee($pdo, $wfTestCompId, 'AWF_ROW_X_' . uniqid(), null);
    $wfTestEmpY = makeEmployee($pdo, $wfTestCompId, 'AWF_ROW_Y_' . uniqid(), null);
    $stmtWfTestRole = $pdo->prepare("INSERT INTO `structure_roles` (comp_id, role_name_th, role_name_en, can_approve_payroll) VALUES (:comp_id, :th, :en, 1)");
    $stmtWfTestRole->execute([':comp_id' => $wfTestCompId, ':th' => 'ทดสอบ Row', ':en' => 'Test Row Role ' . uniqid()]);
    $wfTestRoleId = (int)$pdo->lastInsertId();

    check('getByDocumentType() returns null on a brand-new company (nothing configured at all)', $wfModel->getByDocumentType($wfTestCompId, 'PAYROLL_RUN_APPROVAL'), null);

    echo "--- stepSave() auto-creates the flow the first time a step is saved ---\n";
    $noApproversRes = $wfModel->stepSave($wfTestCompId, [
        'document_type_code' => 'PAYROLL_RUN_APPROVAL', 'approvers' => [], 'joint_approve_mode' => 'any', 'group_type' => 'and',
    ], $adminUserId);
    check('stepSave() rejects an empty approver list', $noApproversRes['status'], false);
    check('the rejected attempt created nothing', $wfModel->getByDocumentType($wfTestCompId, 'PAYROLL_RUN_APPROVAL'), null);

    $step1Res = $wfModel->stepSave($wfTestCompId, [
        'document_type_code' => 'PAYROLL_RUN_APPROVAL', 'step_name' => 'First step',
        'approvers' => [['approver_type' => 'user', 'approver_id' => $wfTestEmpX]],
        'joint_approve_mode' => 'any', 'group_type' => 'and', 'requires_previous_step' => false,
    ], $adminUserId);
    checkTrue('stepSave() (no step_id) creates the flow + first step' . (empty($step1Res['status']) ? " ({$step1Res['message']})" : ''), $step1Res['status']);
    $flowAfterStep1 = $wfModel->getByDocumentType($wfTestCompId, 'PAYROLL_RUN_APPROVAL');
    checkTrue('the flow now exists', $flowAfterStep1 !== null);
    check('flow status defaults to active', $flowAfterStep1['status'], 'active');
    checkTrue('workflow_name was auto-derived, not left blank', trim((string)$flowAfterStep1['workflow_name']) !== '');
    check('exactly 1 step so far', count($flowAfterStep1['steps']), 1);
    check('step 1 step_order is 1', (int)$flowAfterStep1['steps'][0]['step_order'], 1);
    $step1Id = (int)$flowAfterStep1['steps'][0]['id'];

    echo "--- a second stepSave() (still no step_id) appends at the end of the SAME flow ---\n";
    $step2Res = $wfModel->stepSave($wfTestCompId, [
        'document_type_code' => 'PAYROLL_RUN_APPROVAL', 'step_name' => 'Second step',
        'approvers' => [['approver_type' => 'role', 'approver_id' => $wfTestRoleId]],
        'joint_approve_mode' => 'all', 'group_type' => 'and', 'requires_previous_step' => true,
    ], $adminUserId);
    checkTrue('stepSave() appends a second step' . (empty($step2Res['status']) ? " ({$step2Res['message']})" : ''), $step2Res['status']);
    check('workflow_id is the SAME as step 1 (same flow, not a new one)', (int)$step2Res['workflow_id'], (int)$flowAfterStep1['id']);
    $flowAfterStep2 = $wfModel->getByDocumentType($wfTestCompId, 'PAYROLL_RUN_APPROVAL');
    check('now 2 steps', count($flowAfterStep2['steps']), 2);
    check('step 2 step_order is 2', (int)$flowAfterStep2['steps'][1]['step_order'], 2);
    $step2Id = (int)$flowAfterStep2['steps'][1]['id'];
    check('step 1 id is unchanged by adding step 2', (int)$flowAfterStep2['steps'][0]['id'], $step1Id);

    echo "--- stepSave() WITH step_id updates that ONE step in place -- step_order/other step untouched ---\n";
    $step1UpdateRes = $wfModel->stepSave($wfTestCompId, [
        'document_type_code' => 'PAYROLL_RUN_APPROVAL', 'step_id' => $step1Id, 'step_name' => 'First step (renamed)',
        'approvers' => [['approver_type' => 'user', 'approver_id' => $wfTestEmpY]],
        'joint_approve_mode' => 'any', 'group_type' => 'or', 'requires_previous_step' => false,
    ], $adminUserId);
    checkTrue('stepSave() (with step_id) updates in place' . (empty($step1UpdateRes['status']) ? " ({$step1UpdateRes['message']})" : ''), $step1UpdateRes['status']);
    check('the step id is unchanged (in-place update, not delete+reinsert)', $step1UpdateRes['step_id'], $step1Id);
    $flowAfterUpdate = $wfModel->getByDocumentType($wfTestCompId, 'PAYROLL_RUN_APPROVAL');
    check('still exactly 2 steps (no phantom row from the update)', count($flowAfterUpdate['steps']), 2);
    check('step 1 name updated', $flowAfterUpdate['steps'][0]['step_name'], 'First step (renamed)');
    check('step 1 group_type updated', $flowAfterUpdate['steps'][0]['group_type'], 'or');
    check('step 1 approver replaced (wfTestEmpY, not wfTestEmpX anymore)', (int)$flowAfterUpdate['steps'][0]['approvers'][0]['approver_id'], $wfTestEmpY);
    check('step 1 step_order is STILL 1 (untouched by an in-place update)', (int)$flowAfterUpdate['steps'][0]['step_order'], 1);
    check("step 2 is untouched by step 1's update", $flowAfterUpdate['steps'][1]['step_name'], 'Second step');

    echo "--- stepSave() validation rejects an unknown approver before touching the DB ---\n";
    $badApproverRes = $wfModel->stepSave($wfTestCompId, [
        'document_type_code' => 'PAYROLL_RUN_APPROVAL',
        'approvers' => [['approver_type' => 'user', 'approver_id' => 999999999]],
        'joint_approve_mode' => 'any', 'group_type' => 'and',
    ], $adminUserId);
    check('stepSave() rejects an approver that does not exist', $badApproverRes['status'], false);
    check('still exactly 2 steps (the rejected attempt created nothing)', count($wfModel->getByDocumentType($wfTestCompId, 'PAYROLL_RUN_APPROVAL')['steps']), 2);
    $crossCompanyApproverRes = $wfModel->stepSave($wfTestCompId, [
        'document_type_code' => 'PAYROLL_RUN_APPROVAL',
        'approvers' => [['approver_type' => 'user', 'approver_id' => $employeeA]], // belongs to comp_id=1, not $wfTestCompId
        'joint_approve_mode' => 'any', 'group_type' => 'and',
    ], $adminUserId);
    check('stepSave() rejects an approver that belongs to a DIFFERENT company', $crossCompanyApproverRes['status'], false);

    echo "--- stepDelete() removes exactly one step and renumbers the rest to a contiguous sequence ---\n";
    $deleteStep1Res = $wfModel->stepDelete($wfTestCompId, $step1Id, $adminUserId);
    checkTrue('stepDelete() succeeds' . (empty($deleteStep1Res['status']) ? " ({$deleteStep1Res['message']})" : ''), $deleteStep1Res['status']);
    $flowAfterDelete = $wfModel->getByDocumentType($wfTestCompId, 'PAYROLL_RUN_APPROVAL');
    check('exactly 1 step remains', count($flowAfterDelete['steps']), 1);
    check('the remaining step is the former step 2', (int)$flowAfterDelete['steps'][0]['id'], $step2Id);
    check('renumbered to step_order 1', (int)$flowAfterDelete['steps'][0]['step_order'], 1);
    $deleteAgainRes = $wfModel->stepDelete($wfTestCompId, $step1Id, $adminUserId);
    check('deleting an already-deleted step id is refused, not a silent no-op', $deleteAgainRes['status'], false);

    echo "--- stepsSort() sets step_order = array position, and rejects a set that does not match ---\n";
    $step3Res = $wfModel->stepSave($wfTestCompId, [
        'document_type_code' => 'PAYROLL_RUN_APPROVAL', 'step_name' => 'Third step',
        'approvers' => [['approver_type' => 'user', 'approver_id' => $wfTestEmpX]],
        'joint_approve_mode' => 'any', 'group_type' => 'and',
    ], $adminUserId);
    checkTrue('setup: a 3rd step for the sort test', $step3Res['status']);
    $step3Id = (int)$step3Res['step_id'];
    $mismatchSortRes = $wfModel->stepsSort($wfTestCompId, 'PAYROLL_RUN_APPROVAL', [$step3Id]);
    check('stepsSort() rejects a set that does not exactly match the current flow', $mismatchSortRes['status'], false);
    $sortRes = $wfModel->stepsSort($wfTestCompId, 'PAYROLL_RUN_APPROVAL', [$step3Id, $step2Id]);
    checkTrue('stepsSort() succeeds with the exact current id set' . (empty($sortRes['status']) ? " ({$sortRes['message']})" : ''), $sortRes['status']);
    $flowAfterSort = $wfModel->getByDocumentType($wfTestCompId, 'PAYROLL_RUN_APPROVAL');
    check('step 3 is now first (step_order 1)', (int)$flowAfterSort['steps'][0]['id'], $step3Id);
    check('step 2 is now second (step_order 2)', (int)$flowAfterSort['steps'][1]['id'], $step2Id);

    echo "--- getByDocumentType() prefers the ACTIVE workflow, falls back to the most recently" .
        " updated INACTIVE one if none is active (so toggling a flow off never loses its steps) ---\n";
    $wfModel->toggleStatus($wfTestCompId, (int)$flowAfterSort['id'], $adminUserId, 'inactive');
    $flowInactive = $wfModel->getByDocumentType($wfTestCompId, 'PAYROLL_RUN_APPROVAL');
    checkTrue('still returns the workflow (its steps are not lost by deactivating)', $flowInactive !== null);
    check('status reflects inactive', $flowInactive['status'], 'inactive');
    check('still has both steps', count($flowInactive['steps']), 2);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
