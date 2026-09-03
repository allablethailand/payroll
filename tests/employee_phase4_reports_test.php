<?php
/**
 * Verifies EmployeeModel::completenessOverviewReport() -- Phase 4 (the final phase) of the Employee
 * Reports plan, see project_employee_reports_phased_plan_2026_09_02 memory for the full 5-phase plan.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 * Run with: php tests/employee_phase4_reports_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/EmployeeModel.php';
require_once __DIR__ . '/../app/models/CompanyProfileModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    $ok = (is_float($expected) || is_float($actual)) ? abs((float)$actual - (float)$expected) < 0.05 : $actual === $expected;
    if ($ok) {
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
    $compId = 1;
    $adminUserId = 1;

    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL")->execute([':comp_id' => $compId]);

    $model = new EmployeeModel();
    $structModel = new CompanyProfileModel($pdo);
    $deptRes = $structModel->saveStructure('department', $compId, ['department_code' => 'P4_DEPT_A', 'department_name_th' => 'แผนก P4', 'department_name_en' => 'Dept P4'], $adminUserId);
    checkTrue('fixture: department created' . (empty($deptRes['status']) ? " ({$deptRes['message']})" : ''), $deptRes['status']);
    $deptId = $deptRes['id'];

    // A: uses EmployeeModel::save() (not a raw INSERT) so the full, realistic set of
    // completenessColumns() fields all get genuinely filled the same way a real save from the UI
    // would -- this should land at or near 100%.
    $fullData = [
        'employee_no' => 'P4_FULL_' . uniqid(), 'employee_type' => 'domestic', 'title' => 'mr', 'gender' => 'male',
        'name_th' => 'ครบถ้วน', 'name_en' => 'Complete', 'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        // valid mod-11 checksum, same value used elsewhere in this test suite
        'id_card_no' => '1234567890121',
        'personal_email' => uniqid() . '@test.local', 'mobile_no' => '0812345678',
        'department_id' => $deptId, 'position_id' => null, 'branch_id' => null,
        'employment_date' => '2020-01-01', 'employment_status' => 'permanent', 'employment_type' => 'full_time',
        'payment_method_id' => resolvePaymentMethodId($pdo, 'transfer'), 'bank_id' => 1, 'bank_account_no' => '1112223334',
        'salary_type' => 'monthly', 'base_salary_amount' => 30000, 'salary_effective_date' => '2020-01-01', 'tax_calculation_method' => 'average',
    ];
    // position_id/branch_id are required by requiredColumns() for a FULL save() to succeed cleanly
    // via the normal API path -- fetch real ids so this fixture doesn't fail validation and end up
    // testing nothing.
    $posRes = $structModel->saveStructure('position', $compId, ['position_code' => 'P4_POS', 'position_name_th' => 'ตำแหน่ง P4', 'position_name_en' => 'Position P4'], $adminUserId);
    $branchRes = $structModel->saveStructure('branch', $compId, ['branch_code' => 'P4_BR', 'branch_name_th' => 'สาขา P4', 'branch_name_en' => 'Branch P4'], $adminUserId);
    $fullData['position_id'] = $posRes['id'] ?? null;
    $fullData['branch_id'] = $branchRes['id'] ?? null;
    $fullSaveRes = $model->save($compId, $fullData, $adminUserId);
    checkTrue('fixture: fully-filled employee saved' . (empty($fullSaveRes['status']) ? " ({$fullSaveRes['message']})" : ''), $fullSaveRes['status']);

    // B: minimal save -- only employee_no + the handful of truly-required columns, everything else
    // (contact/identification/bank/etc.) left blank -- should land at a genuinely low percent.
    $minimalData = [
        'employee_no' => 'P4_MIN_' . uniqid(), 'employee_type' => 'domestic', 'title' => 'mr', 'gender' => 'male',
        'name_th' => 'น้อย', 'name_en' => 'Minimal', 'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        'personal_email' => uniqid() . '@test.local', 'mobile_no' => '0898888888',
        'department_id' => $deptId, 'position_id' => $fullData['position_id'], 'branch_id' => $fullData['branch_id'],
        'employment_date' => '2020-01-01', 'employment_status' => 'permanent', 'employment_type' => 'full_time',
        'payment_method_id' => resolvePaymentMethodId($pdo, 'cash'), 'salary_type' => 'monthly', 'base_salary_amount' => 30000,
        'salary_effective_date' => '2020-01-01', 'tax_calculation_method' => 'average',
    ];
    $minSaveRes = $model->save($compId, $minimalData, $adminUserId);
    checkTrue('fixture: minimally-filled employee saved' . (empty($minSaveRes['status']) ? " ({$minSaveRes['message']})" : ''), $minSaveRes['status']);

    // C: resigned -- must be EXCLUDED entirely from the overview (current snapshot only).
    $resignedData = $fullData;
    $resignedData['employee_no'] = 'P4_RESIGNED_' . uniqid();
    $resignedData['employment_status'] = 'resigned';
    $resignedSaveRes = $model->save($compId, $resignedData, $adminUserId);
    checkTrue('fixture: resigned employee saved' . (empty($resignedSaveRes['status']) ? " ({$resignedSaveRes['message']})" : ''), $resignedSaveRes['status']);

    $overview = $model->completenessOverviewReport($compId, ['department_id' => $deptId]);
    check('exactly 2 employees in the overview (A, B -- C excluded, resigned)', count($overview['items']), 2);

    $byNo = [];
    foreach ($overview['items'] as $item) { $byNo[$item['employee_no']] = $item; }
    checkTrue('the fully-filled employee (A) scores high (>= 80%)', $byNo[$fullData['employee_no']]['completeness'] >= 80);
    checkTrue('the minimally-filled employee (B) scores meaningfully lower than A', $byNo[$minimalData['employee_no']]['completeness'] < $byNo[$fullData['employee_no']]['completeness']);

    checkTrue('items are sorted ascending by completeness (least complete first)', $overview['items'][0]['completeness'] <= $overview['items'][1]['completeness']);

    $bucketCounts = [];
    foreach ($overview['buckets'] as $b) { $bucketCounts[$b['key']] = $b['count']; }
    check('bucket counts sum to 2 (matches total items)', array_sum($bucketCounts), 2);

    checkTrue('average_percent is between the two individual scores (a real average, not a copy of one)',
        $overview['average_percent'] >= min($byNo[$fullData['employee_no']]['completeness'], $byNo[$minimalData['employee_no']]['completeness'])
        && $overview['average_percent'] <= max($byNo[$fullData['employee_no']]['completeness'], $byNo[$minimalData['employee_no']]['completeness']));

    echo "=== Empty result (department with no employees at all) ===\n";
    $emptyDeptRes = $structModel->saveStructure('department', $compId, ['department_code' => 'P4_EMPTY', 'department_name_th' => 'แผนกว่าง', 'department_name_en' => 'Empty Dept'], $adminUserId);
    $emptyOverview = $model->completenessOverviewReport($compId, ['department_id' => $emptyDeptRes['id']]);
    check('0 items for a department with no employees', count($emptyOverview['items']), 0);
    check('average_percent is 0.0 (not a division-by-zero error)', $emptyOverview['average_percent'], 0.0);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
