<?php
/**
 * Verifies `EmployeeForeignWorkerDetailModel` (2026-09-02, extends the Origami candidates.php field
 * batch -- visa details + foreign_worker_info now have a schema home, see
 * database/migrations/2026-09-02_21_visa_foreign_worker_info.sql's own header) directly, plus
 * EmployeeModel::save()/get()'s own wiring into it (independent-tab-save semantics: only touched
 * when at least one of its own fields is part of a given save, same "don't silently clear another
 * tab's data" precedent as payment_method_id/mixed lines).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 * Run with: php tests/employee_foreign_worker_detail_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/EmployeeForeignWorkerDetailModel.php';
require_once __DIR__ . '/../app/models/EmployeeModel.php';

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

// 2026-09-02, follow-up: payment_type (legacy enum) dropped -- EmployeeModel::save() payloads now
// use payment_method_id, resolved here via master_payment_methods.code.
function resolvePaymentMethodId(PDO $pdo, string $code): int {
    $stmt = $pdo->prepare("SELECT id FROM `master_payment_methods` WHERE code = :code");
    $stmt->execute([':code' => $code]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        throw new RuntimeException("master_payment_methods code '{$code}' not found -- seed missing?");
    }
    return (int)$id;
}

try {
    $userId = 1;
    $compCode = 'FWD_' . uniqid();
    $insComp = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', :comp_code)");
    $insComp->execute([':name' => 'Foreign Worker Detail Test Co ' . uniqid(), ':comp_code' => $compCode]);
    $compId = (int)$pdo->lastInsertId();

    echo "=== EmployeeForeignWorkerDetailModel: direct CRUD ===\n";
    $model = new EmployeeForeignWorkerDetailModel($pdo);
    $insEmpStmt = $pdo->prepare("INSERT INTO employees (comp_id, employee_no, employee_type, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality, employment_date, employment_status, employment_type, employee_status)
        VALUES (:comp_id, :employee_no, 'foreigner', 'mr', 'male', 'ทดสอบ', 'FWD', 'Test', 'FWD', '1990-01-01', 'Burmese', '2024-01-01', 'permanent', 'full_time', 'active')");
    $insEmpStmt->execute([':comp_id' => $compId, ':employee_no' => 'FWD_EMP_' . uniqid()]);
    $employeeId = (int)$pdo->lastInsertId();

    check('get() on a never-saved employee returns null', $model->get($employeeId), null);

    $model->save($employeeId, ['recruitment_agency' => 'Agency A', 'arrival_date' => '2024-01-01', 'tel' => '0812345678'], $userId);
    $afterFirstSave = $model->get($employeeId);
    checkTrue('row created after first save', $afterFirstSave !== null);
    check('recruitment_agency stored', $afterFirstSave['recruitment_agency'] ?? null, 'Agency A');
    check('arrival_date stored', $afterFirstSave['arrival_date'] ?? null, '2024-01-01');
    check('tel stored', $afterFirstSave['tel'] ?? null, '0812345678');
    check('a field never sent (due_date) stays null', $afterFirstSave['due_date'], null);

    echo "--- partial update: only sending ONE field must not blank the others already on file ---\n";
    $model->save($employeeId, ['due_date' => '2025-01-01'], $userId);
    $afterPartial = $model->get($employeeId);
    check('due_date, the only field sent this time, is now set', $afterPartial['due_date'] ?? null, '2025-01-01');
    check('recruitment_agency from the FIRST save survives untouched', $afterPartial['recruitment_agency'] ?? null, 'Agency A');
    check('tel from the FIRST save survives untouched', $afterPartial['tel'] ?? null, '0812345678');

    echo "--- a field sent as an explicit empty string DOES clear it (distinguishes 'not sent' from 'sent blank') ---\n";
    $model->save($employeeId, ['recruitment_agency' => ''], $userId);
    $afterClear = $model->get($employeeId);
    check('recruitment_agency explicitly cleared', $afterClear['recruitment_agency'], null);
    check('due_date (not part of THIS save) still survives untouched', $afterClear['due_date'] ?? null, '2025-01-01');

    echo "--- save() with none of its own fields present is a safe no-op ---\n";
    $model->save($employeeId, ['unrelated_key' => 'ignored'], $userId);
    $afterNoop = $model->get($employeeId);
    check('nothing changed', $afterNoop, $afterClear);

    echo "=== EmployeeModel::save()/get(): independent-tab-save wiring ===\n";
    $employeeModel = new EmployeeModel($pdo);
    $structDeptStmt = $pdo->prepare("INSERT INTO structure_departments (comp_id, department_code, department_name_th, department_name_en) VALUES (:c, :code, 'แผนกทดสอบ', 'Test Dept')");
    $structDeptStmt->execute([':c' => $compId, ':code' => 'FWD_DEPT_' . uniqid()]);
    $deptId = (int)$pdo->lastInsertId();
    $structPosStmt = $pdo->prepare("INSERT INTO structure_positions (comp_id, position_code, position_name_th, position_name_en) VALUES (:c, :code, 'ตำแหน่งทดสอบ', 'Test Position')");
    $structPosStmt->execute([':c' => $compId, ':code' => 'FWD_POS_' . uniqid()]);
    $posId = (int)$pdo->lastInsertId();
    $structBranchStmt = $pdo->prepare("INSERT INTO structure_branches (comp_id, branch_code, branch_name_th, branch_name_en) VALUES (:c, :code, 'สาขาทดสอบ', 'Test Branch')");
    $structBranchStmt->execute([':c' => $compId, ':code' => 'FWD_BR_' . uniqid()]);
    $branchId = (int)$pdo->lastInsertId();

    $basePayload = [
        'employee_no' => 'FWD_EMP2_' . uniqid(), 'employee_type' => 'foreigner', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'surname_th' => 'สอง', 'name_en' => 'Test', 'surname_en' => 'Two',
        'date_of_birth' => '1990-01-01', 'nationality' => 'Burmese',
        'tax_id_no' => '1234567890124', 'passport_no' => 'Y7654321', 'passport_expire_date' => '2031-01-01',
        'passport_issued_place' => 'Yangon', 'passport_issue_date' => '2021-01-01',
        'work_permit_no' => 'WP-1111', 'date_work_permit_issue' => '2024-01-01', 'date_work_permit_expire' => '2025-01-01',
        'work_permit_issued_place' => 'Bangkok',
        'visa_type' => 'Non-Immigrant Visa', 'visa_no' => 'V-999', 'visa_issued_place' => 'Bangkok',
        'visa_issue_date' => '2024-02-01', 'date_visa_expire' => '2025-02-01',
        'department_id' => $deptId, 'position_id' => $posId, 'branch_id' => $branchId,
        'employment_date' => '2024-01-01', 'employment_status' => 'permanent', 'employment_type' => 'full_time',
        'workforce_type' => 'employee', 'record_time_method' => 'manual',
        'payment_method_id' => resolvePaymentMethodId($pdo, 'transfer'), 'bank_id' => 1, 'bank_account_no' => '1112223334',
        'salary_type' => 'monthly', 'base_salary_amount' => 30000, 'salary_effective_date' => '2024-01-01',
        'recruitment_agency' => 'Agency B', 'arrival_date' => '2024-01-05', 'arrival_card_no' => 'AC-222',
        'address' => 'Home St 2', 'province' => 'Mandalay', 'tel_code' => '+95', 'tel' => '999888777',
    ];
    $createRes = $employeeModel->save($compId, $basePayload, $userId);
    checkTrue('employee created with visa/foreign-worker fields in the SAME save' . (empty($createRes['message']) ? '' : " ({$createRes['message']})"), $createRes['status']);
    $created = $employeeModel->get($compId, $basePayload['employee_no']);
    checkTrue('get() returns the created employee', $created !== null);
    check('passport_issued_place round-trips via EmployeeModel', $created['passport_issued_place'] ?? null, 'Yangon');
    check('passport_issue_date round-trips', $created['passport_issue_date'] ?? null, '2021-01-01');
    check('work_permit_issued_place round-trips', $created['work_permit_issued_place'] ?? null, 'Bangkok');
    check('visa_no round-trips', $created['visa_no'] ?? null, 'V-999');
    check('visa_issued_place round-trips', $created['visa_issued_place'] ?? null, 'Bangkok');
    check('visa_issue_date round-trips', $created['visa_issue_date'] ?? null, '2024-02-01');
    check('get() merges in recruitment_agency from employee_foreign_worker_details', $created['recruitment_agency'] ?? null, 'Agency B');
    check('get() merges in arrival_card_no', $created['arrival_card_no'] ?? null, 'AC-222');
    check('get() merges in province', $created['province'] ?? null, 'Mandalay');
    check('get() merges in tel', $created['tel'] ?? null, '999888777');

    echo "--- saving a DIFFERENT tab (no foreign-worker-info fields sent at all) must not clear it ---\n";
    $otherTabSave = $employeeModel->save($compId, array_merge($basePayload, ['id' => $created['id'], 'name_en' => 'Renamed']), $userId);
    // Note: the generic save() ALWAYS resends the full payload from a real form (per this app's own
    // "always resubmit everything" convention) -- this assertion instead simulates the model-level
    // contract directly: array_intersect-based gating means omitting these keys from $data leaves
    // the related table untouched, which is what actually protects a real independent-tab save.
    $minimalPayload = $basePayload;
    unset($minimalPayload['recruitment_agency'], $minimalPayload['arrival_date'], $minimalPayload['arrival_card_no'], $minimalPayload['address'], $minimalPayload['province'], $minimalPayload['tel_code'], $minimalPayload['tel']);
    $minimalPayload['id'] = $created['id'];
    $minimalPayload['name_en'] = 'RenamedAgain';
    $minimalSave = $employeeModel->save($compId, $minimalPayload, $userId);
    checkTrue('save without any foreign-worker-info keys succeeds' . (empty($minimalSave['message']) ? '' : " ({$minimalSave['message']})"), $minimalSave['status']);
    $afterMinimal = $employeeModel->get($compId, $basePayload['employee_no']);
    check('name_en DID change (this save\'s own real field)', $afterMinimal['name_en'] ?? null, 'RenamedAgain');
    check('recruitment_agency survives untouched (not part of THIS save)', $afterMinimal['recruitment_agency'] ?? null, 'Agency B');
    check('province survives untouched', $afterMinimal['province'] ?? null, 'Mandalay');

    echo "\n" . ($failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)" : "SOME TESTS FAILED") . "\n";
    echo "{$passes} passed, {$failures} failed.\n";
} finally {
    $pdo->rollBack();
}
exit($failures === 0 ? 0 : 1);
