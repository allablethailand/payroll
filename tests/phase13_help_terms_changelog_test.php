<?php
/**
 * Lightweight verification script for Backlog Phase 13 (Onboarding/Help/Version system) --
 * TermsAndConditionsModel, SetupGuideModel, ChangelogModel, HelpDrawerContentModel.
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside
 * a transaction that is always rolled back. Uses a fresh throwaway company/employee for the
 * comp_id-scoped/employee-scoped checks (SetupGuideModel, TermsAndConditionsModel's own acceptance
 * tracking) -- ChangelogModel/HelpDrawerContentModel are platform-wide reference data, read
 * directly against whatever the real migrations already seeded (read-only, never mutated here).
 * Run with: php tests/phase13_help_terms_changelog_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/TermsAndConditionsModel.php';
require_once __DIR__ . '/../app/models/SetupGuideModel.php';
require_once __DIR__ . '/../app/models/ChangelogModel.php';
require_once __DIR__ . '/../app/models/HelpDrawerContentModel.php';

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
    $insComp = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active')");
    $insComp->execute([':name' => 'Phase13 Test Co ' . uniqid()]);
    $compId = (int)$pdo->lastInsertId();

    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, department_id)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'เฟส13', 'Test', 'Phase13', '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active',
         1, 1, 0, NULL)");
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => 'P13_TEST_' . uniqid(), ':email' => uniqid() . '@test.local']);
    $employeeId = (int)$pdo->lastInsertId();

    echo "=== TermsAndConditionsModel ===\n";
    $termsModel = new TermsAndConditionsModel($pdo);
    $active = $termsModel->getActive();
    checkTrue('a real active version exists (seeded by the migration)', $active !== null);
    checkFalse('a brand-new employee has NOT accepted the active version yet', $termsModel->hasAcceptedActive($employeeId));

    $acceptRes = $termsModel->acceptActive($employeeId, '127.0.0.1');
    checkTrue('acceptActive() succeeds' . (empty($acceptRes['status']) ? " ({$acceptRes['message']})" : ''), $acceptRes['status']);
    checkTrue('hasAcceptedActive() is now true', $termsModel->hasAcceptedActive($employeeId));

    // Idempotent -- accepting the SAME version again must not error or duplicate.
    $acceptAgainRes = $termsModel->acceptActive($employeeId, '127.0.0.1');
    checkTrue('accepting the same version again is a harmless no-op, not an error', $acceptAgainRes['status']);
    $historyAfterDuplicate = $termsModel->acceptanceHistory($employeeId);
    check('exactly 1 acceptance row exists (duplicate accept did not insert a 2nd row)', count($historyAfterDuplicate), 1);

    $history = $termsModel->acceptanceHistory($employeeId);
    check('acceptanceHistory() returns 1 row', count($history), 1);
    check('history row\'s own version_label matches the active version', $history[0]['version_label'], $active['version_label']);

    // A NEW version published -> the employee's OLD acceptance no longer counts (real "re-prompt
    // everyone on a new version" behavior, not just a shape check).
    $pdo->prepare("UPDATE `terms_and_conditions` SET is_active = 0")->execute();
    $pdo->prepare("INSERT INTO `terms_and_conditions` (version_label, content_th, content_en, is_active) VALUES ('99.0-test', 'th', 'en', 1)")->execute();
    checkFalse('after a NEW version is published, the employee\'s OLD acceptance no longer satisfies hasAcceptedActive()', $termsModel->hasAcceptedActive($employeeId));
    $newActive = $termsModel->getActive();
    check('getActive() now returns the NEW version', $newActive['version_label'], '99.0-test');

    echo "=== SetupGuideModel ===\n";
    $setupGuideModel = new SetupGuideModel($pdo);
    $checklistBefore = $setupGuideModel->checklist($compId);
    check('checklist() returns 7 items', count($checklistBefore['items']), 7);
    checkTrue('the 1 employee just created makes the "employees" item done', array_values(array_filter($checklistBefore['items'], fn($i) => $i['key'] === 'employees'))[0]['done']);
    checkFalse('bank_account item is NOT done yet (no bank_accounts row for this fresh company)', array_values(array_filter($checklistBefore['items'], fn($i) => $i['key'] === 'bank_account'))[0]['done']);
    checkTrue('done_count is less than total_count for a freshly-created company', $checklistBefore['done_count'] < $checklistBefore['total_count']);
    check('percent is a real computed ratio, not hardcoded', $checklistBefore['percent'], (int)round($checklistBefore['done_count'] / $checklistBefore['total_count'] * 100));

    $pdo->prepare("INSERT INTO bank_accounts (comp_id, bank_id, account_no, account_no_hash, key_version, account_name, account_type, currency_code, is_default, status, created_by)
        VALUES (:comp_id, 1, 'enc', 'hash', 1, 'Test Account', 'current', 'THB', 1, 'active', 1)")->execute([':comp_id' => $compId]);
    $checklistAfter = $setupGuideModel->checklist($compId);
    checkTrue('adding a real active bank account flips bank_account to done', array_values(array_filter($checklistAfter['items'], fn($i) => $i['key'] === 'bank_account'))[0]['done']);
    checkTrue('done_count increased after adding the bank account', $checklistAfter['done_count'] > $checklistBefore['done_count']);

    echo "=== ChangelogModel ===\n";
    $changelogModel = new ChangelogModel($pdo);
    $changelog = $changelogModel->list();
    checkTrue('at least the 4 seeded changelog entries exist', count($changelog) >= 4);
    checkTrue('every row has both th and en title/body populated', array_reduce($changelog, fn($carry, $row) => $carry && $row['title_th'] !== '' && $row['title_en'] !== '' && $row['body_th'] !== '' && $row['body_en'] !== '', true));
    $releaseDates = array_column($changelog, 'release_date');
    $sortedDesc = $releaseDates;
    rsort($sortedDesc);
    check('list() is ordered by release_date descending (newest first)', $releaseDates, $sortedDesc);

    echo "=== HelpDrawerContentModel ===\n";
    $drawerModel = new HelpDrawerContentModel($pdo);
    $dashboardHelp = $drawerModel->getByPageKey('dashboard');
    checkTrue('the seeded "dashboard" page_key has real content', $dashboardHelp !== null);
    checkTrue('dashboard help has both th and en title', !empty($dashboardHelp['title_th']) && !empty($dashboardHelp['title_en']));
    check('an unwritten page_key returns null, not an error', $drawerModel->getByPageKey('some_page_nobody_wrote_help_for_yet'), null);

    echo "\n--------------------------------------------------\n";
    echo "Passed: {$passes}, Failed: {$failures}\n";
    if ($failures > 0) {
        echo "SOME TESTS FAILED\n";
    } else {
        echo "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
    }
} catch (Throwable $e) {
    $failures++;
    echo "FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
exit($failures > 0 ? 1 : 0);
