<?php
/**
 * Verifies the 2026-09-02 probation/internship period-expiry reminder (item 5 of a 5-item
 * follow-up list, explicit request: "ข้อ 5 ปิดไปได้เลยครับ ให้เข้าไปปรับเอง แต่ให้มี notification
 * ขึ้นเตือนเฉยๆครับ ทั้งในหน้า Dashboard ถ้าไม่มีไม่ต้องแสดงเลย และใน notification ครับ" -- NO
 * auto-transition of employment_status/employment_type, purely a reminder shown in the notification
 * bell + Dashboard).
 *
 * Covers `NotificationModel::probationInternExpiringEmployees()` (the single query both the
 * Dashboard card and the lazy notification trigger share) and `checkProbationInternExpiring()`
 * (the fan-out + dedup mechanism).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back. Isolates from comp_id=1's real data the same way
 * tests/probation_ratio_override_test.php already does (soft-delete existing employees within this
 * transaction) -- see feedback_dev_db_shared_state_test_fragility.
 * Run with: php tests/probation_intern_expiring_notification_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/PayrollPolicyModel.php';
require_once __DIR__ . '/../app/models/NotificationModel.php';

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

try {
    $compId = 1;
    $adminUserId = (int)$pdo->query("SELECT id FROM employees WHERE comp_id = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1")->fetchColumn();

    // Same isolation precedent as tests/probation_ratio_override_test.php's own top-of-file comment.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL AND id != :keep")
        ->execute([':comp_id' => $compId, ':keep' => $adminUserId]);
    // Clear out any notifications this comp's real employees already accumulated, so counts below
    // are exact, not "at least".
    $pdo->prepare("DELETE FROM `notifications` WHERE comp_id = :comp_id")->execute([':comp_id' => $compId]);

    $policyModel = new PayrollPolicyModel($pdo);
    $notifModel = new NotificationModel();

    // 2026-09-03, Platform Hardening Phase 3: structure_roles.can_process_payroll is dropped --
    // a role with a real payroll_run.process grant so checkProbationInternExpiring()'s fan-out
    // (createForPermissionHolders(), now resolved via PermissionModel::employeesWithPermission())
    // has a real recipient to land on.
    $roleStmt = $pdo->prepare("INSERT INTO `structure_roles` (comp_id, role_name_th, role_name_en)
        VALUES (:comp_id, 'ผู้ดูแลเงินเดือน', 'Payroll Admin')");
    $roleStmt->execute([':comp_id' => $compId]);
    $payrollRoleId = (int)$pdo->lastInsertId();
    $processPermIdPie = (int)$pdo->query("SELECT id FROM permissions WHERE permission_key = 'payroll_run.process'")->fetchColumn();
    $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, allow_scope, detail_level) VALUES (:r, :p, 'all', 'full')")
        ->execute([':r' => $payrollRoleId, ':p' => $processPermIdPie]);
    $pdo->prepare("UPDATE `employees` SET role_id = :role_id WHERE id = :id")->execute([':role_id' => $payrollRoleId, ':id' => $adminUserId]);

    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, probation_period_days_override, intern_period_days_override)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1998-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         :employment_date, :employment_status_enum, :employment_type_enum, 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active',
         0, 0, 0, :probation_override, :intern_override)");

    $today = new DateTime('today');
    $mkDate = fn(int $daysAgo) => (clone $today)->modify("-{$daysAgo} days")->format('Y-m-d');

    // Company policy: probation 90 days, intern 180 days.
    $policyModel->save($compId, [
        'probation_defer_pvd' => false, 'probation_defer_recurring_earning' => false,
        'probation_period_days' => 90,
        'intern_defer_pvd' => false, 'intern_defer_recurring_earning' => false,
        'intern_period_days' => 180,
    ], $adminUserId);

    // A: probation, 85 days in (5 days remaining, within the 7-day "expiring soon" window).
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => 'TEST_PIE_A_' . uniqid(), ':name_th' => 'ทดสอบ', ':surname_th' => 'A',
        ':name_en' => 'Test', ':surname_en' => 'A', ':email' => uniqid() . '@test.local',
        ':employment_date' => $mkDate(85), ':employment_status_enum' => 'probation', ':employment_type_enum' => 'full_time',
        ':probation_override' => null, ':intern_override' => null]);
    $empAId = (int)$pdo->lastInsertId();

    // B: probation, 100 days in (10 days PAST the 90-day company default -- expired).
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => 'TEST_PIE_B_' . uniqid(), ':name_th' => 'ทดสอบ', ':surname_th' => 'B',
        ':name_en' => 'Test', ':surname_en' => 'B', ':email' => uniqid() . '@test.local',
        ':employment_date' => $mkDate(100), ':employment_status_enum' => 'probation', ':employment_type_enum' => 'full_time',
        ':probation_override' => null, ':intern_override' => null]);
    $empBId = (int)$pdo->lastInsertId();

    // C: probation, only 10 days in (80 days remaining -- well outside the 7-day window, must NOT appear).
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => 'TEST_PIE_C_' . uniqid(), ':name_th' => 'ทดสอบ', ':surname_th' => 'C',
        ':name_en' => 'Test', ':surname_en' => 'C', ':email' => uniqid() . '@test.local',
        ':employment_date' => $mkDate(10), ':employment_status_enum' => 'probation', ':employment_type_enum' => 'full_time',
        ':probation_override' => null, ':intern_override' => null]);
    $empCId = (int)$pdo->lastInsertId();

    // D: probation, own override of 10 days, 15 days in -- expired per ITS OWN override even though
    // the company default (90) would say "not even close".
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => 'TEST_PIE_D_' . uniqid(), ':name_th' => 'ทดสอบ', ':surname_th' => 'D',
        ':name_en' => 'Test', ':surname_en' => 'D', ':email' => uniqid() . '@test.local',
        ':employment_date' => $mkDate(15), ':employment_status_enum' => 'probation', ':employment_type_enum' => 'full_time',
        ':probation_override' => 10, ':intern_override' => null]);
    $empDId = (int)$pdo->lastInsertId();

    // E: BOTH probation AND internship simultaneously, 85 days in -- probation's company default
    // (90 days) would say "5 days left"; intern's OWN override (87 days) says "2 days left". Both
    // land in the expiring_soon window, but with DIFFERENT numbers and a different `kind` -- proves
    // precedence actually used intern's reading (2 days, kind='internship'), not probation's (5
    // days, kind='probation'), rather than just happening to both produce SOME result.
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => 'TEST_PIE_E_' . uniqid(), ':name_th' => 'ทดสอบ', ':surname_th' => 'E',
        ':name_en' => 'Test', ':surname_en' => 'E', ':email' => uniqid() . '@test.local',
        ':employment_date' => $mkDate(85), ':employment_status_enum' => 'probation', ':employment_type_enum' => 'internship',
        ':probation_override' => null, ':intern_override' => 87]);
    $empEId = (int)$pdo->lastInsertId();

    // F: was probation, now resigned -- must be excluded entirely (employment_status filter).
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => 'TEST_PIE_F_' . uniqid(), ':name_th' => 'ทดสอบ', ':surname_th' => 'F',
        ':name_en' => 'Test', ':surname_en' => 'F', ':email' => uniqid() . '@test.local',
        ':employment_date' => $mkDate(100), ':employment_status_enum' => 'resigned', ':employment_type_enum' => 'full_time',
        ':probation_override' => null, ':intern_override' => null]);
    $empFId = (int)$pdo->lastInsertId();

    echo "=== probationInternExpiringEmployees(): correct set, correct milestones ===\n";
    $rows = $notifModel->probationInternExpiringEmployees($compId);
    $byId = [];
    foreach ($rows as $r) { $byId[$r['employee_id']] = $r; }

    checkTrue('A (5 days left) is included', isset($byId[$empAId]));
    check('A milestone is expiring_soon', $byId[$empAId]['milestone'] ?? null, 'expiring_soon');
    check('A days_remaining is exactly 5', $byId[$empAId]['days_remaining'] ?? null, 5);
    check('A kind is probation', $byId[$empAId]['kind'] ?? null, 'probation');

    checkTrue('B (10 days past) is included', isset($byId[$empBId]));
    check('B milestone is expired', $byId[$empBId]['milestone'] ?? null, 'expired');
    check('B days_remaining is exactly -10 (10 days overdue)', $byId[$empBId]['days_remaining'] ?? null, -10);

    checkFalse('C (80 days remaining, outside the 7-day window) is NOT included', isset($byId[$empCId]));

    checkTrue('D (own 10-day override, 15 days in) is included via ITS OWN override', isset($byId[$empDId]));
    check('D milestone is expired (per its own 10-day override, not the company 90-day default)', $byId[$empDId]['milestone'] ?? null, 'expired');
    check('D days_remaining is exactly -5', $byId[$empDId]['days_remaining'] ?? null, -5);

    checkTrue('E (probation+internship both true) is included', isset($byId[$empEId]));
    check('E milestone is expiring_soon', $byId[$empEId]['milestone'] ?? null, 'expiring_soon');
    check('E kind is internship (precedence: intern wins over probation, never stacked)', $byId[$empEId]['kind'] ?? null, 'internship');
    check('E days_remaining is exactly 2 (per its OWN intern override of 87 days), NOT 5 (which the probation reading would give)', $byId[$empEId]['days_remaining'] ?? null, 2);

    checkFalse('F (resigned) is excluded entirely regardless of how overdue employment_date would be', isset($byId[$empFId]));

    echo "=== nothing configured at all -- company policy AND employee both leave period_days null ===\n";
    $noPolicyCompStmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES ('PIE No-Policy Co', 'PIE No-Policy Co', 'TH', :tax, 'Test Address', 'Test Signatory')");
    $noPolicyCompStmt->execute([':tax' => 'TAX' . uniqid()]);
    $noPolicyCompId = (int)$pdo->lastInsertId();
    $insEmp->execute([':comp_id' => $noPolicyCompId, ':employee_no' => 'TEST_PIE_G_' . uniqid(), ':name_th' => 'ทดสอบ', ':surname_th' => 'G',
        ':name_en' => 'Test', ':surname_en' => 'G', ':email' => uniqid() . '@test.local',
        ':employment_date' => $mkDate(500), ':employment_status_enum' => 'probation', ':employment_type_enum' => 'full_time',
        ':probation_override' => null, ':intern_override' => null]);
    $noPolicyRows = $notifModel->probationInternExpiringEmployees($noPolicyCompId);
    check('a company that never configured probation_period_days at all gets an empty result (not a crash, not treated as "already expired")', count($noPolicyRows), 0);

    echo "=== checkProbationInternExpiring(): fans out to can_process_payroll holders, dedup-safe ===\n";
    $notifModel->checkProbationInternExpiring($compId);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `notifications` WHERE comp_id = :comp_id AND type = 'probation_intern_expiring' AND employee_id = :employee_id");
    $countStmt->execute([':comp_id' => $compId, ':employee_id' => $adminUserId]);
    check('exactly 4 notifications created for the can_process_payroll holder (A/B/D/E, C and F excluded)', (int)$countStmt->fetchColumn(), 4);

    $dedupStmt = $pdo->prepare("SELECT dedup_key, title_th, title_en FROM `notifications` WHERE comp_id = :comp_id AND type = 'probation_intern_expiring' AND related_id = :related_id AND employee_id = :employee_id");
    $dedupStmt->execute([':comp_id' => $compId, ':related_id' => $empBId, ':employee_id' => $adminUserId]);
    $bNotif = $dedupStmt->fetch(PDO::FETCH_ASSOC);
    // createForPermissionHolders() appends ":{$recipientId}" on top of the prefix passed to it (each
    // recipient needs their own dedup row) -- so the full key is prefix:subjectEmployeeId:milestone,
    // THEN :recipientId on top.
    checkTrue('B\'s notification exists with the expected dedup_key shape', $bNotif !== false && $bNotif['dedup_key'] === "probation_intern_expiring:{$empBId}:expired:{$adminUserId}");
    checkTrue('B\'s title mentions "ended" wording (expired milestone), not "ending soon"', str_contains((string)$bNotif['title_en'], 'ended'));

    // Calling it again must NOT duplicate (dedup_key is a real unique constraint + create()'s own
    // pre-check) -- same idempotency guarantee checkStaleDrafts() already relies on.
    $notifModel->checkProbationInternExpiring($compId);
    $countStmt->execute([':comp_id' => $compId, ':employee_id' => $adminUserId]);
    check('calling checkProbationInternExpiring() again does NOT duplicate -- still exactly 4', (int)$countStmt->fetchColumn(), 4);

    echo "=== listForEmployee()/unreadCount() trigger the same lazy check on read (fresh comp, zero prior calls) ===\n";
    $freshCompStmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES ('PIE Fresh Co', 'PIE Fresh Co', 'TH', :tax, 'Test Address', 'Test Signatory')");
    $freshCompStmt->execute([':tax' => 'TAX' . uniqid()]);
    $freshCompId = (int)$pdo->lastInsertId();
    $freshRoleStmt = $pdo->prepare("INSERT INTO `structure_roles` (comp_id, role_name_th, role_name_en) VALUES (:comp_id, 'Role', 'Role')");
    $freshRoleStmt->execute([':comp_id' => $freshCompId]);
    $freshRoleId = (int)$pdo->lastInsertId();
    $processPermIdFresh = (int)$pdo->query("SELECT id FROM permissions WHERE permission_key = 'payroll_run.process'")->fetchColumn();
    $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, allow_scope, detail_level) VALUES (:r, :p, 'all', 'full')")
        ->execute([':r' => $freshRoleId, ':p' => $processPermIdFresh]);
    $policyModel->save($freshCompId, ['probation_defer_pvd' => false, 'probation_defer_recurring_earning' => false, 'probation_period_days' => 90], $adminUserId);
    $insEmp->execute([':comp_id' => $freshCompId, ':employee_no' => 'TEST_PIE_H_' . uniqid(), ':name_th' => 'ทดสอบ', ':surname_th' => 'H',
        ':name_en' => 'Test', ':surname_en' => 'H', ':email' => uniqid() . '@test.local',
        ':employment_date' => $mkDate(100), ':employment_status_enum' => 'probation', ':employment_type_enum' => 'full_time',
        ':probation_override' => null, ':intern_override' => null]);
    $freshHId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE `employees` SET role_id = :role_id WHERE id = :id")->execute([':role_id' => $freshRoleId, ':id' => $freshHId]);
    // H is the ONLY employee in this fresh company, and is themselves the can_process_payroll holder
    // -- fanning out to themselves is a real, deliberately-unavoidable edge case here (a 1-person
    // company), not a bug -- just confirms the read-side trigger fires without ever having been
    // called explicitly.
    $unread = $notifModel->unreadCount($freshCompId, $freshHId);
    checkTrue('unreadCount() lazily triggered checkProbationInternExpiring() and now shows >= 1 unread', $unread >= 1);

    echo "\n" . ($failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)" : "SOME TESTS FAILED") . "\n";
    echo "{$passes} passed, {$failures} failed.\n";
} finally {
    $pdo->rollBack();
}
exit($failures === 0 ? 0 : 1);
