<?php
/**
 * Verifies EmployeeModel::save() no longer blocks a save just because fields on OTHER tabs are
 * still empty (2026-08-19, explicit request: "แต่ละ Tab อยากให้บันทึกได้แบบอิสระต่อกัน"), and that
 * is_payroll_ready / verifyStatus() ("Verify Status") are computed dynamically from whatever the
 * row currently holds instead of being hardcoded true on every successful save.
 * Run with: php tests/employee_independent_save_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
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
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }

function makeCompany(PDO $pdo, string $countryCode): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function makeStructure(PDO $pdo, int $compId): array {
    $ids = [];
    $stmt = $pdo->prepare("INSERT INTO structure_departments (comp_id, department_code, department_name_th, department_name_en) VALUES (:c, :code, 'แผนกทดสอบ', 'Test Dept')");
    $stmt->execute([':c' => $compId, ':code' => 'DEPT' . uniqid()]);
    $ids['department_id'] = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO structure_roles (comp_id, role_name_th, role_name_en) VALUES (:c, 'ตำแหน่งทดสอบ', 'Test Role')");
    $stmt->execute([':c' => $compId]);
    $ids['role_id'] = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO structure_positions (comp_id, position_code, position_name_th, position_name_en) VALUES (:c, :code, 'ตำแหน่งทดสอบ', 'Test Position')");
    $stmt->execute([':c' => $compId, ':code' => 'POS' . uniqid()]);
    $ids['position_id'] = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO structure_branches (comp_id, branch_code, branch_name_th, branch_name_en) VALUES (:c, :code, 'สาขาทดสอบ', 'Test Branch')");
    $stmt->execute([':c' => $compId, ':code' => 'BR' . uniqid()]);
    $ids['branch_id'] = (int)$pdo->lastInsertId();

    return $ids;
}

try {
    $userId = 1;
    $model = new EmployeeModel();
    $compId = makeCompany($pdo, 'TH');
    $structure = makeStructure($pdo, $compId);

    // ---------- Missing employee_no is still the one hard-blocking case ----------
    $noEmpNo = ['title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ'];
    $r0 = $model->save($compId, $noEmpNo, $userId);
    checkFalse('Save without employee_no is still rejected', $r0['status']);
    check('Rejection message names employee_no', $r0['message'], 'Missing required field: employee_no');

    // ---------- Info-tab-only create: no Employment/Salary/Contact fields at all ----------
    $empNo = 'IND-EMP-' . uniqid();
    $infoOnly = [
        'employee_no' => $empNo, 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'surname_th' => 'นามสกุล',
        'name_en' => 'Test', 'surname_en' => 'Surname', 'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        'id_card_no' => '1234567890121', // valid mod-11 checksum -- Info tab's own identification field filled in
    ];
    $r1 = $model->save($compId, $infoOnly, $userId);
    checkTrue('Info-tab-only payload creates a new employee' . (empty($r1['status']) ? " ({$r1['message']})" : ''), $r1['status']);

    $row1 = $r1['status'] ? $model->get($compId, $empNo) : null;
    if ($row1) {
        check('is_payroll_ready is 0 right after an Info-only create', (int)$row1['is_payroll_ready'], 0);
        checkFalse('verify_status.ready is false', $row1['verify_status']['ready']);
        checkTrue('verify_status.missing_tabs includes contact', in_array('contact', $row1['verify_status']['missing_tabs'], true));
        checkTrue('verify_status.missing_tabs includes employment', in_array('employment', $row1['verify_status']['missing_tabs'], true));
        checkTrue('verify_status.missing_tabs includes salary', in_array('salary', $row1['verify_status']['missing_tabs'], true));
        checkFalse('Info tab itself is NOT in missing_tabs (its own fields are all filled)', in_array('info', $row1['verify_status']['missing_tabs'], true));
    }

    // ---------- Employment-tab-only save on the SAME record (simulating the next tab's Save click,
    // which per detail.js always resubmits the whole form -- Info's already-saved fields come along
    // unchanged, only Employment's fields are newly filled in here) ----------
    if ($r1['status']) {
        $employmentPayload = array_merge($infoOnly, $structure, [
            'id' => $r1['id'],
            'employment_date' => '2024-01-01', 'employment_status' => 'permanent', 'employment_type' => 'full_time',
            'workforce_type' => 'office', 'record_time_method' => 'manual', 'payment_type' => 'cash',
        ]);
        $r2 = $model->save($compId, $employmentPayload, $userId);
        checkTrue('Employment-tab save on existing record succeeds without Contact/Salary filled in' . (empty($r2['status']) ? " ({$r2['message']})" : ''), $r2['status']);

        $row2 = $r2['status'] ? $model->get($compId, $empNo) : null;
        if ($row2) {
            check('is_payroll_ready still 0 (Contact/Salary still missing)', (int)$row2['is_payroll_ready'], 0);
            checkFalse('employment no longer in missing_tabs', in_array('employment', $row2['verify_status']['missing_tabs'], true));
            checkTrue('salary still in missing_tabs', in_array('salary', $row2['verify_status']['missing_tabs'], true));
        }

        // ---------- Fill in Contact + Salary too -> now fully ready ----------
        if ($row2) {
            $fullPayload = array_merge($employmentPayload, [
                'personal_email' => 'indep' . uniqid() . '@example.com', 'mobile_no' => '812345678',
                'id_card_no' => '1234567890121', // valid mod-11 checksum
                'salary_type' => 'monthly', 'base_salary_amount' => 25000, 'salary_effective_date' => '2024-01-01',
                'tax_calculation_method' => 'average',
            ]);
            $r3 = $model->save($compId, $fullPayload, $userId);
            checkTrue('Final save with every tab filled succeeds' . (empty($r3['status']) ? " ({$r3['message']})" : ''), $r3['status']);
            $row3 = $r3['status'] ? $model->get($compId, $empNo) : null;
            if ($row3) {
                check('is_payroll_ready flips to 1 once every required field is present', (int)$row3['is_payroll_ready'], 1);
                checkTrue('verify_status.ready is now true', $row3['verify_status']['ready']);
                check('verify_status.missing_tabs is now empty', $row3['verify_status']['missing_tabs'], []);
            }
        }
    }

    // ---------- FK fields left empty must not be rejected as "Invalid reference" ----------
    $noFk = [
        'employee_no' => 'IND-EMP-NOFK-' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ก', 'surname_th' => 'ข', 'name_en' => 'A', 'surname_en' => 'B',
        'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        // department_id/role_id/position_id/branch_id deliberately omitted
    ];
    $rFk = $model->save($compId, $noFk, $userId);
    checkTrue('Save with department_id/role_id/position_id/branch_id all omitted still succeeds' . (empty($rFk['status']) ? " ({$rFk['message']})" : ''), $rFk['status']);

    // ---------- bank_id/bank_account_no missing while payment_type=bank must not block the save
    // itself, only is_payroll_ready ----------
    $bankIncomplete = [
        'employee_no' => 'IND-EMP-BANK-' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ก', 'surname_th' => 'ข', 'name_en' => 'A', 'surname_en' => 'B',
        'date_of_birth' => '1990-01-01', 'nationality' => 'Thai', 'payment_type' => 'bank',
    ];
    $rBank = $model->save($compId, $bankIncomplete, $userId);
    checkTrue('Save with payment_type=bank but no bank_id/bank_account_no still succeeds' . (empty($rBank['status']) ? " ({$rBank['message']})" : ''), $rBank['status']);
    if ($rBank['status']) {
        $bankRow = $model->get($compId, $bankIncomplete['employee_no']);
        checkTrue('...but employment stays in missing_tabs because of the incomplete bank details', in_array('employment', $bankRow['verify_status']['missing_tabs'], true));
    }

    // ---------- Resignation/termination fields round-trip (2026-08-21, explicit request) ----------
    $resignedPayload = [
        'employee_no' => 'IND-EMP-RESIGN-' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'resigned',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ก', 'surname_th' => 'ข', 'name_en' => 'A', 'surname_en' => 'B',
        'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        'employment_status' => 'resigned',
        'employment_status_effective_date' => '2026-08-15',
        'employment_end_date' => '2026-08-31',
        'employment_end_reason' => 'ลาออกเพื่อไปศึกษาต่อ',
    ];
    $rResigned = $model->save($compId, $resignedPayload, $userId);
    checkTrue('Save with resignation fields succeeds' . (empty($rResigned['status']) ? " ({$rResigned['message']})" : ''), $rResigned['status']);
    if ($rResigned['status']) {
        $resignedRow = $model->get($compId, $resignedPayload['employee_no']);
        check('employment_status_effective_date round-trips', $resignedRow['employment_status_effective_date'], '2026-08-15');
        check('employment_end_date round-trips (already existed, now actually settable)', $resignedRow['employment_end_date'], '2026-08-31');
        check('employment_end_reason round-trips', $resignedRow['employment_end_reason'], 'ลาออกเพื่อไปศึกษาต่อ');
    }

} finally {
    $pdo->rollBack();
}

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
