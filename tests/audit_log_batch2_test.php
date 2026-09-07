<?php
/**
 * Lightweight verification script for Platform Hardening Phase 6, batch 2: extends the pilot's
 * AuditLogModel wiring (see tests/audit_log_test.php) to 4 more financial/approval-critical models
 * -- BankAccountModel, PayrollCycleModel, CompanyStatutorySettingModel, ApprovalWorkflowModel --
 * chosen per explicit user scoping ("ส่วนที่สำคัญต่อการเงีน/การอนุมัติ"), same update/delete/toggle-only
 * convention as the pilot (no CREATE logging, per explicit user confirmation "เหมือน pilot เดิม").
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back. Every fixture uses its OWN freshly-created company (same
 * makeCompany() pattern as tests/audit_log_test.php) -- zero dev-DB contamination risk.
 *
 * Run with: php tests/audit_log_batch2_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/AuditLogModel.php';
require_once __DIR__ . '/../app/models/BankAccountModel.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/CompanyStatutorySettingModel.php';
require_once __DIR__ . '/../app/models/ApprovalWorkflowModel.php';

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

function makeCompany(PDO $pdo, string $countryCode): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function makeEmployee(PDO $pdo, int $compId, string $employeeNo): int {
    $stmt = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active',
         1, 1, 0)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => $employeeNo,
        ':name_th' => 'ทดสอบ', ':surname_th' => $employeeNo, ':name_en' => 'Test', ':surname_en' => $employeeNo,
        ':email' => uniqid() . '@test.local',
    ]);
    return (int)$pdo->lastInsertId();
}

/** @return array rows from audit_logs for one table+record, oldest-first. */
function auditRowsFor(PDO $pdo, int $compId, string $tableName, int $recordId): array {
    $stmt = $pdo->prepare("SELECT * FROM audit_logs WHERE comp_id = :comp_id AND table_name = :table_name AND record_id = :record_id ORDER BY id ASC");
    $stmt->execute([':comp_id' => $compId, ':table_name' => $tableName, ':record_id' => $recordId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $userId = (int)$pdo->query("SELECT id FROM employees ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($userId <= 0) {
        throw new RuntimeException('No employee row exists in this dev DB to use as a valid created_by/updated_by FK value.');
    }
    $compId = makeCompany($pdo, 'TH');

    // ================= Integration: BankAccountModel::save()/toggleStatus()/delete() =================
    echo "=== Integration: BankAccountModel ===\n";
    $bankId = (int)$pdo->query("SELECT id FROM master_banks WHERE is_active = 1 LIMIT 1")->fetchColumn();
    if ($bankId <= 0) {
        throw new RuntimeException('No active master_banks row exists to use as a fixture bank_id.');
    }
    $bankAccountModel = new BankAccountModel();
    $baCreate = $bankAccountModel->save($compId, [
        'bank_id' => $bankId, 'account_no' => '1112223334', 'account_name' => 'Original Name',
    ], $userId);
    checkTrue('create bank account' . (empty($baCreate['status']) ? " ({$baCreate['message']})" : ''), $baCreate['status']);
    $baId = (int)($baCreate['id'] ?? 0);
    if ($baId > 0) {
        check('BankAccount create branch is not audited (only update/delete/toggle are wired)', count(auditRowsFor($pdo, $compId, 'bank_accounts', $baId)), 0);

        $baUpdate = $bankAccountModel->save($compId, [
            'id' => $baId, 'bank_id' => $bankId, 'account_no' => '1112223334', 'account_name' => 'Renamed',
        ], $userId, '10.1.0.1', 'AuditBatch2/1.0');
        checkTrue('update bank account', $baUpdate['status']);
        $baFields = array_column(auditRowsFor($pdo, $compId, 'bank_accounts', $baId), 'field_name');
        checkTrue('BankAccount update: account_name change logged', in_array('account_name', $baFields, true));
        check('BankAccount update: encrypted account_no NOT logged', in_array('account_no', $baFields, true), false);
        check('BankAccount update: account_no_hash NOT logged', in_array('account_no_hash', $baFields, true), false);

        $baToggle = $bankAccountModel->toggleStatus($compId, $baId, $userId, '10.1.0.2');
        checkTrue('toggle bank account status', $baToggle['status']);
        $toggleRow = current(array_filter(auditRowsFor($pdo, $compId, 'bank_accounts', $baId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === $baToggle['new_status']));
        checkTrue('BankAccount toggleStatus: status change logged', $toggleRow !== false);

        $baDelete = $bankAccountModel->delete($compId, $baId, $userId, '10.1.0.3');
        checkTrue('delete bank account', $baDelete['status']);
        $deleteRows = array_filter(auditRowsFor($pdo, $compId, 'bank_accounts', $baId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === 'deleted');
        checkTrue('BankAccount delete: logged as a status->deleted update row', count($deleteRows) > 0);
    }

    // ================= Integration: PayrollCycleModel::save()/toggleStatus()/delete() =================
    echo "\n=== Integration: PayrollCycleModel ===\n";
    $bankFormatId = (int)$pdo->query("SELECT id FROM master_bank_file_formats WHERE is_active = 1 LIMIT 1")->fetchColumn();
    if ($bankFormatId <= 0) {
        throw new RuntimeException('No active master_bank_file_formats row exists to use as a fixture bank_file_format_id.');
    }
    $cycleModel = new PayrollCycleModel($pdo);
    $cycleCreate = $cycleModel->save($compId, [
        'cycle_name' => 'Original Cycle ' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_use_last_day' => 1, 'payment_use_last_day' => 1, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => $bankFormatId,
    ], $userId);
    checkTrue('create payroll cycle' . (empty($cycleCreate['status']) ? " ({$cycleCreate['message']})" : ''), $cycleCreate['status']);
    $cycleId = (int)($cycleCreate['id'] ?? 0);
    if ($cycleId > 0) {
        check('PayrollCycle create branch is not audited', count(auditRowsFor($pdo, $compId, 'payroll_cycles', $cycleId)), 0);

        $cycleUpdate = $cycleModel->save($compId, [
            'id' => $cycleId, 'cycle_name' => 'Renamed Cycle', 'payroll_frequency' => 'monthly',
            'cutoff_use_last_day' => 1, 'payment_use_last_day' => 1, 'ot_cutoff_type' => 'same_as_attendance',
            'bank_file_format_id' => $bankFormatId,
        ], $userId, '10.1.0.4');
        checkTrue('update payroll cycle' . (empty($cycleUpdate['status']) ? " ({$cycleUpdate['message']})" : ''), $cycleUpdate['status']);
        $cycleFields = array_column(auditRowsFor($pdo, $compId, 'payroll_cycles', $cycleId), 'field_name');
        checkTrue('PayrollCycle update: cycle_name change logged', in_array('cycle_name', $cycleFields, true));

        $cycleToggle = $cycleModel->toggleStatus($compId, $cycleId, $userId, '10.1.0.5');
        checkTrue('toggle payroll cycle status', $cycleToggle['status']);
        $cycleToggleRow = current(array_filter(auditRowsFor($pdo, $compId, 'payroll_cycles', $cycleId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === $cycleToggle['new_status']));
        checkTrue('PayrollCycle toggleStatus: status change logged', $cycleToggleRow !== false);

        $cycleDelete = $cycleModel->delete($compId, $cycleId, $userId, '10.1.0.6');
        checkTrue('delete payroll cycle', $cycleDelete['status']);
        $cycleDeleteRows = array_filter(auditRowsFor($pdo, $compId, 'payroll_cycles', $cycleId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === 'deleted');
        checkTrue('PayrollCycle delete: logged as a status->deleted update row', count($cycleDeleteRows) > 0);
    }

    // ================= Integration: CompanyStatutorySettingModel::save()/toggleStatus()/reset() =================
    echo "\n=== Integration: CompanyStatutorySettingModel ===\n";
    $itemCode = 'AUDTEST' . substr(uniqid(), -6);
    $pdo->prepare("INSERT INTO `statutory_items`
        (country_code, code, name_th, name_en, category, calc_method, calc_base, is_employee_applicable, is_employer_applicable, default_is_active, is_company_rate_editable, status)
        VALUES ('TH', :code, 'ทดสอบ', 'Test Item', 'social_insurance', 'flat_rate', 'gross_salary', 1, 1, 1, 1, 'active')")
        ->execute([':code' => $itemCode]);
    $statItemId = (int)$pdo->lastInsertId();
    $companySettingModel = new CompanyStatutorySettingModel($pdo);
    $csSave1 = $companySettingModel->save($compId, [
        'statutory_item_id' => $statItemId, 'is_active' => 1, 'employee_rate_override' => 5,
    ], $userId);
    checkTrue('create company statutory setting' . (empty($csSave1['status']) ? " ({$csSave1['message']})" : ''), $csSave1['status']);
    $csId = (int)($csSave1['id'] ?? 0);
    if ($csId > 0) {
        check('CompanyStatutorySetting create branch is not audited', count(auditRowsFor($pdo, $compId, 'company_statutory_settings', $csId)), 0);

        $csSave2 = $companySettingModel->save($compId, [
            'statutory_item_id' => $statItemId, 'is_active' => 1, 'employee_rate_override' => 7.5,
        ], $userId, '10.1.0.7');
        checkTrue('update company statutory setting' . (empty($csSave2['status']) ? " ({$csSave2['message']})" : ''), $csSave2['status']);
        $csFields = array_column(auditRowsFor($pdo, $compId, 'company_statutory_settings', $csId), 'field_name');
        checkTrue('CompanyStatutorySetting update: employee_rate_override change logged', in_array('employee_rate_override', $csFields, true));

        $csToggle = $companySettingModel->toggleStatus($compId, $statItemId, $userId, '10.1.0.8');
        checkTrue('toggle company statutory setting status', $csToggle['status']);
        $csToggleRow = current(array_filter(auditRowsFor($pdo, $compId, 'company_statutory_settings', $csId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === $csToggle['new_status']));
        checkTrue('CompanyStatutorySetting toggleStatus: status change logged', $csToggleRow !== false);

        $csReset = $companySettingModel->reset($compId, $statItemId, $userId, '10.1.0.9');
        checkTrue('reset company statutory setting to default', $csReset['status']);
        $csResetRows = array_filter(auditRowsFor($pdo, $compId, 'company_statutory_settings', $csId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === 'deleted');
        checkTrue('CompanyStatutorySetting reset: logged as a status->deleted update row', count($csResetRows) > 0);
    }

    // ================= Integration: ApprovalWorkflowModel =================
    echo "\n=== Integration: ApprovalWorkflowModel ===\n";
    $approverId = makeEmployee($pdo, $compId, 'AUDBATCH-APR-' . uniqid());
    $workflowModel = new ApprovalWorkflowModel($pdo);
    $wfCreate = $workflowModel->save($compId, [
        'workflow_name' => 'Original Flow ' . uniqid(), 'status' => 'active',
        'document_type_codes' => ['PAYROLL_RUN_APPROVAL'],
        'steps' => [[
            'step_name' => 'Step 1', 'approvers' => [['approver_type' => 'user', 'approver_id' => $approverId]],
            'joint_approve_mode' => 'any', 'group_type' => 'and', 'requires_previous_step' => false,
        ]],
    ], $userId);
    checkTrue('create approval workflow' . (empty($wfCreate['status']) ? " ({$wfCreate['message']})" : ''), $wfCreate['status']);
    $wfId = (int)($wfCreate['id'] ?? 0);
    if ($wfId > 0) {
        check('ApprovalWorkflow create branch is not audited', count(auditRowsFor($pdo, $compId, 'approval_workflows', $wfId)), 0);

        $wfUpdate = $workflowModel->save($compId, [
            'id' => $wfId, 'workflow_name' => 'Renamed Flow', 'status' => 'active',
            'document_type_codes' => ['PAYROLL_RUN_APPROVAL'],
            'steps' => [[
                'step_name' => 'Step 1', 'approvers' => [['approver_type' => 'user', 'approver_id' => $approverId]],
                'joint_approve_mode' => 'any', 'group_type' => 'and', 'requires_previous_step' => false,
            ]],
        ], $userId, '10.1.0.10');
        checkTrue('update approval workflow (same steps, header changed)' . (empty($wfUpdate['status']) ? " ({$wfUpdate['message']})" : ''), $wfUpdate['status']);
        $wfFields = array_column(auditRowsFor($pdo, $compId, 'approval_workflows', $wfId), 'field_name');
        checkTrue('ApprovalWorkflow update: workflow_name change logged', in_array('workflow_name', $wfFields, true));

        $wfToggle = $workflowModel->toggleStatus($compId, $wfId, $userId, 'inactive', '10.1.0.11');
        checkTrue('toggle approval workflow status', $wfToggle['status']);
        $wfToggleRow = current(array_filter(auditRowsFor($pdo, $compId, 'approval_workflows', $wfId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === 'inactive'));
        checkTrue('ApprovalWorkflow toggleStatus: status change logged', $wfToggleRow !== false);

        // ---------- stepSave() update-in-place + stepsSort() + stepDelete() ----------
        $flow = $workflowModel->getByDocumentType($compId, 'PAYROLL_RUN_APPROVAL');
        checkTrue('getByDocumentType found the fixture flow', $flow !== null);
        if ($flow !== null) {
            $existingStepId = (int)$flow['steps'][0]['id'];
            $stepUpdate = $workflowModel->stepSave($compId, [
                'document_type_code' => 'PAYROLL_RUN_APPROVAL', 'step_id' => $existingStepId,
                'step_name' => 'Step 1 Renamed', 'approvers' => [['approver_type' => 'user', 'approver_id' => $approverId]],
                'joint_approve_mode' => 'any', 'group_type' => 'and', 'requires_previous_step' => false,
            ], $userId, '10.1.0.12');
            checkTrue('stepSave update-in-place' . (empty($stepUpdate['status']) ? " ({$stepUpdate['message']})" : ''), $stepUpdate['status']);
            $stepFields = array_column(auditRowsFor($pdo, $compId, 'approval_workflow_steps', $existingStepId), 'field_name');
            checkTrue('stepSave update: step_name change logged', in_array('step_name', $stepFields, true));

            // add a second step via stepSave() (create branch -- not audited), then reorder both.
            $stepCreate = $workflowModel->stepSave($compId, [
                'document_type_code' => 'PAYROLL_RUN_APPROVAL',
                'step_name' => 'Step 2', 'approvers' => [['approver_type' => 'user', 'approver_id' => $approverId]],
                'joint_approve_mode' => 'any', 'group_type' => 'and', 'requires_previous_step' => false,
            ], $userId);
            checkTrue('stepSave create (2nd step)' . (empty($stepCreate['status']) ? " ({$stepCreate['message']})" : ''), $stepCreate['status']);
            $newStepId = (int)($stepCreate['step_id'] ?? 0);
            check('stepSave create branch is not audited', count(auditRowsFor($pdo, $compId, 'approval_workflow_steps', $newStepId)), 0);

            // $existingStepId is at position 1, $newStepId at position 2 (just created after it) --
            // swapping them means BOTH actually move, so both should be logged.
            $sortResult = $workflowModel->stepsSort($compId, 'PAYROLL_RUN_APPROVAL', [$newStepId, $existingStepId], $userId, '10.1.0.13');
            checkTrue('stepsSort reorder' . (empty($sortResult['status']) ? " ({$sortResult['message']})" : ''), $sortResult['status']);
            $sortRowsExisting = array_filter(auditRowsFor($pdo, $compId, 'approval_workflow_steps', $existingStepId), fn($r) => $r['field_name'] === 'step_order');
            checkTrue('stepsSort: step_order change logged for existingStepId (1 -> 2)', count($sortRowsExisting) > 0);
            $sortRowsNew = array_filter(auditRowsFor($pdo, $compId, 'approval_workflow_steps', $newStepId), fn($r) => $r['field_name'] === 'step_order');
            checkTrue('stepsSort: step_order change logged for newStepId (2 -> 1)', count($sortRowsNew) > 0);
            $sortRowsExistingCountAfterFirst = count($sortRowsExisting);

            // Re-sorting with the SAME (already current) order is a genuine no-op -- confirm it logs
            // nothing new for either step.
            $noopSort = $workflowModel->stepsSort($compId, 'PAYROLL_RUN_APPROVAL', [$newStepId, $existingStepId], $userId, '10.1.0.13b');
            checkTrue('stepsSort no-op re-sort (same order)', $noopSort['status']);
            $sortRowsExistingAfterNoop = array_filter(auditRowsFor($pdo, $compId, 'approval_workflow_steps', $existingStepId), fn($r) => $r['field_name'] === 'step_order');
            check('stepsSort: no-op re-sort adds no new step_order row for existingStepId', count($sortRowsExistingAfterNoop), $sortRowsExistingCountAfterFirst);

            $stepDeleteResult = $workflowModel->stepDelete($compId, $newStepId, $userId, '10.1.0.14');
            checkTrue('stepDelete', $stepDeleteResult['status']);
            $stepDeleteRows = array_filter(auditRowsFor($pdo, $compId, 'approval_workflow_steps', $newStepId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === 'deleted');
            checkTrue('stepDelete: logged as a status->deleted update row', count($stepDeleteRows) > 0);
        }

        $wfDelete = $workflowModel->delete($compId, $wfId, $userId, '10.1.0.15');
        checkTrue('delete approval workflow', $wfDelete['status']);
        $wfDeleteRows = array_filter(auditRowsFor($pdo, $compId, 'approval_workflows', $wfId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === 'deleted');
        checkTrue('ApprovalWorkflow delete: logged as a status->deleted update row', count($wfDeleteRows) > 0);
    }

    echo "\n=== SUMMARY: {$passes} passed, {$failures} failed ===\n";
} finally {
    $pdo->rollBack();
}

exit($failures > 0 ? 1 : 0);
