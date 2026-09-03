<?php
/**
 * Lightweight verification script for EmploymentCertificateRequestModel (HR-proxied employment
 * certificate request + its paired approval_requests row, and the sync hook that
 * ApprovalWorkflowController::requestAct() calls after the generic engine acts -- exercised here
 * directly against the model, same as tests/payslip_request_test.php exercises its own model).
 * Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Runs against the real dev DB inside a transaction that is always rolled back.
 *
 * One thing a DB transaction rollback can NOT undo: issuePdf() writes the actual PDF file to disk
 * (public/uploads/employment_certificate_files/{comp_id}/{hash}.pdf) outside the transaction --
 * every file path this test causes to be written is collected in $issuedFilePaths and unlinked in
 * the finally block so this script never leaves real files behind on disk either.
 *
 * Run with: php tests/employment_certificate_request_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/ApprovalWorkflowModel.php';
require_once __DIR__ . '/../app/models/ApprovalRequestModel.php';
require_once __DIR__ . '/../app/models/EmploymentCertificateTemplateModel.php';
require_once __DIR__ . '/../app/models/EmploymentCertificateRequestModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    if ($actual === $expected) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }

function makeEmployee(PDO $pdo, int $compId, string $employeeNo): int {
    $stmt = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, department_id)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active',
         1, 1, 0, NULL)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => $employeeNo,
        ':name_th' => 'ทดสอบ', ':surname_th' => $employeeNo, ':name_en' => 'Test', ':surname_en' => $employeeNo,
        ':email' => uniqid() . '@test.local',
    ]);
    return (int)$pdo->lastInsertId();
}

$issuedFilePaths = [];

try {
    $compId = 1;
    $adminUserId = 1;

    // Isolation, same pattern/reasoning as tests/employment_certificate_template_test.php's own
    // (comp_id=1 is the real, live dev DB -- see feedback_dev_db_shared_state_test_fragility
    // memory): temporarily soft-delete any active templates for BOTH languages and deactivate any
    // active EMPLOYMENT_CERTIFICATE_APPROVAL workflow, all inside this script's own transaction
    // that is always rolled back, so the real rows are restored the instant this exits either way.
    $pdo->prepare("UPDATE `employment_certificate_templates` SET status = 'deleted' WHERE comp_id = :comp_id AND status = 'active'")
        ->execute([':comp_id' => $compId]);
    $pdo->prepare("UPDATE `approval_workflows` SET status = 'inactive'
        WHERE comp_id = :comp_id AND status = 'active'
          AND id IN (SELECT workflow_id FROM `approval_workflow_document_types` WHERE document_type_code = 'EMPLOYMENT_CERTIFICATE_APPROVAL')")
        ->execute([':comp_id' => $compId]);

    $templateModel = new EmploymentCertificateTemplateModel($pdo);
    $wfModel = new ApprovalWorkflowModel($pdo);
    $approvalModel = new ApprovalRequestModel($pdo);
    $model = new EmploymentCertificateRequestModel($pdo);

    $employee = makeEmployee($pdo, $compId, 'ECR_EMP_' . uniqid());
    $approverEmployee = makeEmployee($pdo, $compId, 'ECR_APPROVER_' . uniqid());

    // ---------- create() validation, before any template/workflow exists ----------
    $r = $model->create($compId, 999999, 'th', $adminUserId);
    checkFalse('unknown employee_id rejected', $r['status']);

    $r = $model->create($compId, $employee, 'fr', $adminUserId);
    checkFalse('invalid language rejected', $r['status']);

    $r = $model->create($compId, $employee, 'th', $adminUserId);
    checkFalse('create() fails when no template is configured for this language yet', $r['status']);

    // ---------- fixture: a real template for both languages ----------
    $thTemplate = $templateModel->createFromPreset($compId, 'th', 'classic', 'ECR Test TH ' . uniqid(), $adminUserId);
    checkTrue('fixture: Thai template created from preset', $thTemplate['status']);
    $enTemplate = $templateModel->createFromPreset($compId, 'en', 'classic', 'ECR Test EN ' . uniqid(), $adminUserId);
    checkTrue('fixture: English template created from preset', $enTemplate['status']);
    // 2026-08-26: publish_status defaults to 'draft' on every INSERT -- resolveTemplateForEmployee()
    // now also requires publish_status='public' (see EmploymentCertificateTemplateModel::save()'s
    // own comment), so both fixture templates must be explicitly published before create() can
    // confirm a real template resolves for this employee+language.
    checkTrue('fixture: Thai template published', $templateModel->setPublishStatus($compId, (int)$thTemplate['template_id'], 'public', $adminUserId)['status']);
    checkTrue('fixture: English template published', $templateModel->setPublishStatus($compId, (int)$enTemplate['template_id'], 'public', $adminUserId)['status']);

    // ---------- create() still fails: template exists now, but no workflow mapped yet ----------
    $noWorkflowRes = $model->create($compId, $employee, 'th', $adminUserId);
    checkFalse('create() fails with no active EMPLOYMENT_CERTIFICATE_APPROVAL workflow mapped', $noWorkflowRes['status']);
    $pdo->prepare("DELETE FROM employment_certificate_requests WHERE employee_id = :e")->execute([':e' => $employee]);

    // ---------- fixture: workflow ----------
    $wfRes = $wfModel->save($compId, [
        'workflow_name' => 'ECR Test Workflow ' . uniqid(),
        'document_type_codes' => ['EMPLOYMENT_CERTIFICATE_APPROVAL'],
        'status' => 'active',
        'steps' => [['approvers' => [['approver_type' => 'user', 'approver_id' => $approverEmployee]]]],
    ], $adminUserId);
    checkTrue('fixture: EMPLOYMENT_CERTIFICATE_APPROVAL workflow created', $wfRes['status']);

    // ---------- create() success ----------
    $created = $model->create($compId, $employee, 'th', $adminUserId);
    checkTrue('create() succeeds once a template and a workflow both exist', $created['status']);
    $requestId = $created['id'];

    $dup = $model->create($compId, $employee, 'th', $adminUserId);
    checkFalse('duplicate pending request for same employee+language rejected', $dup['status']);

    $notDup = $model->create($compId, $employee, 'en', $adminUserId);
    checkTrue('same employee, different language is NOT treated as a duplicate', $notDup['status']);
    $enRequestId = $notDup['id'];

    $row = $model->get($compId, $requestId);
    checkTrue('get() returns the created row', $row !== null);
    check('status starts pending', $row['status'], 'pending');
    checkTrue('approval_request_id was filled in (not left null)', $row['approval_request_id'] !== null);

    $approvalRow = $approvalModel->get($compId, (int)$row['approval_request_id']);
    check('linked approval_requests.document_type_code', $approvalRow['document_type_code'], 'EMPLOYMENT_CERTIFICATE_APPROVAL');
    check('linked approval_requests.reference_id points back to employment_certificate_requests.id', (int)$approvalRow['reference_id'], $requestId);

    // ---------- Approve flow + sync hook -> real PDF issuance ----------
    $actRes = $approvalModel->act($compId, (int)$row['approval_request_id'], $approverEmployee, 'approve');
    checkTrue('approver can approve the single-step workflow', $actRes['status']);
    check('single-step approval is terminal', $actRes['request_status'], 'approved');

    $model->syncFromApprovalStatus((int)$row['approval_request_id'], $actRes['request_status']);
    $afterApprove = $model->get($compId, $requestId);
    check('employment_certificate_requests.status ends at issued (PDF generated automatically on approval)', $afterApprove['status'], 'issued');
    checkTrue('file_path was recorded', !empty($afterApprove['file_path']));
    check('issue_error is cleared', $afterApprove['issue_error'], null);

    $absolutePath = __DIR__ . '/../' . $afterApprove['file_path'];
    $issuedFilePaths[] = $absolutePath;
    checkTrue('the issued PDF file actually exists on disk', is_file($absolutePath));
    $pdfBytes = (string)file_get_contents($absolutePath);
    checkTrue('the issued file is a real PDF (starts with %PDF)', str_starts_with($pdfBytes, '%PDF'));

    // ---------- Reject flow ----------
    $rowEn = $model->get($compId, $enRequestId);
    $rejectRes = $approvalModel->act($compId, (int)$rowEn['approval_request_id'], $approverEmployee, 'reject', 'not eligible');
    checkTrue('reject action succeeds', $rejectRes['status']);
    $model->syncFromApprovalStatus((int)$rowEn['approval_request_id'], $rejectRes['request_status']);
    $afterReject = $model->get($compId, $enRequestId);
    check('employment_certificate_requests.status synced to rejected', $afterReject['status'], 'rejected');
    check('no file was generated for a rejected request', $afterReject['file_path'], null);

    // ---------- Cancel flow (only the requester can cancel) ----------
    // 'th' already has a terminal (issued) request above, so a fresh 'th' request is not a duplicate.
    $created3 = $model->create($compId, $employee, 'th', $adminUserId);
    checkTrue('third request created', $created3['status']);
    $row3 = $model->get($compId, $created3['id']);
    $cancelRes = $approvalModel->act($compId, (int)$row3['approval_request_id'], $adminUserId, 'cancel');
    checkTrue('requester can cancel', $cancelRes['status']);
    $model->syncFromApprovalStatus((int)$row3['approval_request_id'], $cancelRes['request_status']);
    check('employment_certificate_requests.status synced to cancelled', $model->get($compId, $created3['id'])['status'], 'cancelled');

    // ---------- list() ----------
    $list = $model->list($compId);
    $ourIds = [$requestId, $enRequestId, $created3['id']];
    $listedIds = array_map('intval', array_column($list, 'id'));
    foreach ($ourIds as $id) {
        checkTrue("list() includes request id {$id}", in_array((int)$id, $listedIds, true));
    }

} catch (Throwable $e) {
    $failures++;
    echo "  FAIL  Uncaught exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    $pdo->rollBack();
    foreach ($issuedFilePaths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
