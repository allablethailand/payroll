<?php
/**
 * Lightweight verification script for the Backlog Phase 9, T045 Master/Clone architecture:
 * TaxStatutoryModel::save()/delete()/toggleStatus()'s new `?int $compId` ownership scope,
 * TaxStatutoryModel::promoteToMaster(), CompanyStatutorySettingModel::list()'s new UNION (master +
 * company-owned custom items), and CompanyStatutorySettingModel::promoteOverrideToMaster(). Not
 * PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back -- uses 2 fresh companies of its own (never touches
 * comp_id=1's live data, see feedback_dev_db_shared_state_test_fragility).
 * Run with: php tests/statutory_master_clone_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/TaxStatutoryModel.php';
require_once __DIR__ . '/../app/models/CompanyStatutorySettingModel.php';

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

function makeCompany(PDO $pdo, string $countryCode = 'TH'): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

try {
    $compA = makeCompany($pdo, 'TH');
    $compB = makeCompany($pdo, 'TH');
    $taxModel = new TaxStatutoryModel();
    $csModel = new CompanyStatutorySettingModel($pdo);
    $userId = 1;

    echo "=== Custom item creation, isolated per company ===\n";
    $code = 'CUSTOM_' . strtoupper(substr(uniqid(), -6));
    $customA = $taxModel->save([
        'country_code' => 'TH', 'code' => $code, 'name_th' => 'ทดสอบ A', 'name_en' => 'Test A',
        'category' => 'other', 'calc_method' => 'flat_rate', 'calc_base' => 'basic_salary',
        'is_employee_applicable' => true, 'is_employer_applicable' => true,
    ], $userId, $compA);
    checkTrue('company A creates a custom item', $customA['status']);
    $customAId = $customA['id'] ?? 0;

    // 2 different companies choosing the SAME code independently must both succeed -- they never
    // see each other's custom items at all, so this is not a real collision.
    $customB = $taxModel->save([
        'country_code' => 'TH', 'code' => $code, 'name_th' => 'ทดสอบ B', 'name_en' => 'Test B',
        'category' => 'other', 'calc_method' => 'flat_rate', 'calc_base' => 'basic_salary',
        'is_employee_applicable' => true, 'is_employer_applicable' => true,
    ], $userId, $compB);
    checkTrue('company B can independently use the SAME code for its own custom item', $customB['status']);
    $customBId = $customB['id'] ?? 0;

    // Same company, same code again -- genuinely a collision within its own scope.
    $dupe = $taxModel->save([
        'country_code' => 'TH', 'code' => $code, 'name_th' => 'ทดสอบ A2', 'name_en' => 'Test A2',
        'category' => 'other', 'calc_method' => 'flat_rate', 'calc_base' => 'basic_salary',
        'is_employee_applicable' => true, 'is_employer_applicable' => true,
    ], $userId, $compA);
    checkFalse('company A cannot reuse the same code for a 2nd custom item of its own', $dupe['status']);

    echo "\n=== Ownership isolation (save/delete/toggleStatus) ===\n";
    $crossEdit = $taxModel->save(['id' => $customAId, 'country_code' => 'TH', 'code' => $code, 'name_th' => 'Hacked', 'name_en' => 'Hacked',
        'category' => 'other', 'calc_method' => 'flat_rate', 'calc_base' => 'basic_salary',
        'is_employee_applicable' => true, 'is_employer_applicable' => true], $userId, $compB);
    checkFalse('company B cannot edit company A\'s custom item (wrong compId scope)', $crossEdit['status']);

    $masterEdit = $taxModel->save(['id' => $customAId, 'country_code' => 'TH', 'code' => $code, 'name_th' => 'Hacked', 'name_en' => 'Hacked',
        'category' => 'other', 'calc_method' => 'flat_rate', 'calc_base' => 'basic_salary',
        'is_employee_applicable' => true, 'is_employer_applicable' => true], $userId, null);
    checkFalse('a null (master-scope) caller cannot edit a company\'s custom item either', $masterEdit['status']);

    $crossDelete = $taxModel->delete($customAId, $userId, $compB);
    checkFalse('company B cannot delete company A\'s custom item', $crossDelete['status']);

    $crossToggle = $taxModel->toggleStatus($customAId, $userId, $compB);
    checkFalse('company B cannot toggle-status company A\'s custom item', $crossToggle['status']);

    $ownToggle = $taxModel->toggleStatus($customAId, $userId, $compA);
    checkTrue('company A CAN toggle-status its own custom item', $ownToggle['status']);
    check('toggling flips it to inactive', $ownToggle['new_status'] ?? null, 'inactive');
    $taxModel->toggleStatus($customAId, $userId, $compA); // flip back to active for later assertions

    echo "\n=== CompanyStatutorySettingModel::list() UNION (master + own custom items) ===\n";
    $rowsA = $csModel->list($compA);
    $customRowA = null;
    foreach ($rowsA as $r) {
        if ((int)$r['statutory_item_id'] === $customAId) { $customRowA = $r; break; }
    }
    checkTrue('company A\'s own custom item appears in its own list()', $customRowA !== null);
    if ($customRowA !== null) {
        check('custom item\'s item_scope is "custom"', $customRowA['item_scope'], 'custom');
        check('custom item\'s effective_status is its own status directly (active)', $customRowA['effective_status'], 'active');
    }
    $foundBInA = false;
    foreach ($rowsA as $r) {
        if ((int)$r['statutory_item_id'] === $customBId) { $foundBInA = true; break; }
    }
    checkFalse('company B\'s custom item does NOT appear in company A\'s list()', $foundBInA);

    $masterCountInA = 0;
    foreach ($rowsA as $r) { if ($r['item_scope'] === 'master') $masterCountInA++; }
    checkTrue('company A\'s list() still includes real master items too (TH seeded, e.g. TH_SSO/TH_PIT)', $masterCountInA > 0);

    echo "\n=== CompanyStatutorySettingModel::save()/toggleStatus() reject a custom item id ===\n";
    $overrideOnCustom = $csModel->save($compA, ['statutory_item_id' => $customAId, 'is_active' => true, 'employee_rate_override' => '5.0'], $userId);
    checkFalse('cannot "override" a custom item via company_statutory_settings (no override concept for owned items)', $overrideOnCustom['status']);

    echo "\n=== TaxStatutoryModel::promoteToMaster() ===\n";
    $crossPromote = $taxModel->promoteToMaster($customAId, $compB, $userId);
    checkFalse('company B cannot promote company A\'s custom item', $crossPromote['status']);

    $promote = $taxModel->promoteToMaster($customAId, $compA, $userId);
    checkTrue('company A promotes its own custom item to master', $promote['status']);
    $promoted = $taxModel->get($customAId);
    checkTrue('promoted item now has comp_id = NULL', $promoted !== null && $promoted['comp_id'] === null);
    check('promoted_from_comp_id records which company originated it', $promoted['promoted_from_comp_id'] ?? null, (string)$compA);

    // Company B still has its own independent custom item with the SAME code -- promoting IT now
    // would collide with the master item A just created, and must be refused.
    $collidingPromote = $taxModel->promoteToMaster($customBId, $compB, $userId);
    checkFalse('promoting company B\'s custom item now fails: code collides with the newly-promoted master item', $collidingPromote['status']);

    echo "\n=== CompanyStatutorySettingModel::promoteOverrideToMaster() ===\n";
    // Use a real seeded master item that's genuinely company-rate-editable (TH_PVD -- TH_SSO is
    // fixed-by-law, is_company_rate_editable=0, confirmed via direct query, so it would correctly
    // refuse an override and can't be used for this half of the test).
    $stmtPvd = $pdo->prepare("SELECT id FROM statutory_items WHERE code = 'TH_PVD' AND country_code = 'TH' AND comp_id IS NULL AND deleted_at IS NULL AND is_company_rate_editable = 1 LIMIT 1");
    $stmtPvd->execute();
    $pvdId = (int)$stmtPvd->fetchColumn();
    checkTrue('TH_PVD master item (company-rate-editable) exists in the seeded dev DB (fixture precondition)', $pvdId > 0);

    if ($pvdId > 0) {
        $noOverrideYet = $csModel->promoteOverrideToMaster($compA, $pvdId, '2027-01-01', $userId);
        checkFalse('cannot promote when no override is configured yet', $noOverrideYet['status']);

        $setOverride = $csModel->save($compA, ['statutory_item_id' => $pvdId, 'is_active' => true, 'employee_rate_override' => '3.33', 'employer_rate_override' => '3.33'], $userId);
        checkTrue('company A sets its own TH_PVD override', $setOverride['status']);

        $beforeHistoryCount = (int)$pdo->query("SELECT COUNT(*) FROM statutory_item_rate_history WHERE statutory_item_id = {$pvdId} AND deleted_at IS NULL")->fetchColumn();

        $promoteOverride = $csModel->promoteOverrideToMaster($compA, $pvdId, '2027-01-01', $userId);
        checkTrue('company A promotes its own TH_PVD override to master', $promoteOverride['status']);

        $afterHistoryCount = (int)$pdo->query("SELECT COUNT(*) FROM statutory_item_rate_history WHERE statutory_item_id = {$pvdId} AND deleted_at IS NULL")->fetchColumn();
        check('exactly one new master rate_history row was added', $afterHistoryCount, $beforeHistoryCount + 1);

        $newRate = $pdo->query("SELECT employee_rate FROM statutory_item_rate_history WHERE statutory_item_id = {$pvdId} AND effective_date = '2027-01-01' AND deleted_at IS NULL")->fetchColumn();
        check('the new master rate_history row carries the promoted 3.33 value', round((float)$newRate, 2), 3.33);

        $settingAfter = $csModel->get($compA, $pvdId);
        checkTrue('company A\'s own override was cleared after promoting (now matches the new default)', $settingAfter !== null && $settingAfter['employee_rate_override'] === null);
    }

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
