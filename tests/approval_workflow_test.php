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
        'steps' => [['approver_type' => 'user', 'approver_id' => 9999999]],
    ], $adminUserId);
    checkTrue('rejects a step with a non-existent approver_id', $badApprover['status'] === false);

    $badDocType = $wfModel->save($compId, [
        'workflow_name' => 'Bad Doc Type', 'document_type_codes' => ['NOT_A_REAL_CODE'],
        'steps' => [['approver_type' => 'user', 'approver_id' => $employeeA]],
    ], $adminUserId);
    checkTrue('rejects an unknown document_type_code', $badDocType['status'] === false);

    // ---------- Create a real 2-step workflow ----------
    $wf1Res = $wfModel->save($compId, [
        'workflow_name' => 'AWF Test Workflow ' . uniqid(),
        'description' => 'End-to-end test workflow',
        'status' => 'active',
        'document_type_codes' => ['PAYROLL_RUN_APPROVAL'],
        'steps' => [
            ['step_name' => 'Manager Review', 'approver_type' => 'user', 'approver_id' => $employeeA, 'joint_approve_mode' => 'any'],
            ['step_name' => 'Finance Sign-off', 'approver_type' => 'role', 'approver_id' => $testRoleId, 'joint_approve_mode' => 'all', 'timeout_hours' => 48],
        ],
    ], $adminUserId);
    checkTrue('fixture: 2-step workflow created', $wf1Res['status']);
    $wf1Id = $wf1Res['id'];

    // A second workflow mapped to the SAME (still-active) document type must be rejected.
    $conflictRes = $wfModel->save($compId, [
        'workflow_name' => 'Conflicting Workflow', 'status' => 'active',
        'document_type_codes' => ['PAYROLL_RUN_APPROVAL'],
        'steps' => [['approver_type' => 'user', 'approver_id' => $employeeA]],
    ], $adminUserId);
    checkTrue('rejects a second ACTIVE workflow mapped to the same document type', $conflictRes['status'] === false);

    // ---------- get() round-trip ----------
    echo "=== ApprovalWorkflowModel::get() ===\n";
    $wf1 = $wfModel->get($compId, $wf1Id);
    check('document_type_codes round-trips', $wf1['document_type_codes'], ['PAYROLL_RUN_APPROVAL']);
    check('2 steps round-trip in order', count($wf1['steps']), 2);
    check('step 1 approver_type', $wf1['steps'][0]['approver_type'], 'user');
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
        'steps' => [['approver_type' => 'user', 'approver_id' => $employeeA]],
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

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
