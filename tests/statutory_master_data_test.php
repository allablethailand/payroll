<?php
/**
 * Lightweight verification script for Backlog Phase 9, T047: category/calc_base moved from
 * hardcoded PHP const arrays + DB ENUMs to master tables (master_statutory_categories/
 * master_statutory_calc_bases), plus the companion StatutoryCalculationEngine safety-net note for
 * an unrecognized calc_base. Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs
 * against the real dev DB inside a transaction that is always rolled back -- uses its own fresh
 * company (never touches comp_id=1's live data, see feedback_dev_db_shared_state_test_fragility).
 * Run with: php tests/statutory_master_data_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/TaxStatutoryModel.php';
require_once __DIR__ . '/../app/models/CompanyStatutorySettingModel.php';
require_once __DIR__ . '/../app/services/StatutoryCalculationEngine.php';

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
    $compId = makeCompany($pdo, 'TH');
    $taxModel = new TaxStatutoryModel();
    $csModel = new CompanyStatutorySettingModel($pdo);
    $userId = 1;
    $baseCode = 'MT_' . strtoupper(substr(uniqid(), -6));

    echo "=== category/calc_base validated against the new master tables ===\n";
    $validSave = $taxModel->save([
        'country_code' => 'TH', 'code' => $baseCode . '_A', 'name_th' => 'ทดสอบ', 'name_en' => 'Test',
        'category' => 'tax', 'calc_method' => 'flat_rate', 'calc_base' => 'basic_salary',
        'is_employee_applicable' => true, 'is_employer_applicable' => true,
    ], $userId, $compId);
    checkTrue('a seeded, valid category+calc_base combination saves successfully', $validSave['status']);

    $invalidCategory = $taxModel->save([
        'country_code' => 'TH', 'code' => $baseCode . '_B', 'name_th' => 'ทดสอบ', 'name_en' => 'Test',
        'category' => 'nonexistent_category_xyz', 'calc_method' => 'flat_rate', 'calc_base' => 'basic_salary',
        'is_employee_applicable' => true, 'is_employer_applicable' => true,
    ], $userId, $compId);
    checkFalse('an unrecognized category is rejected', $invalidCategory['status']);

    $invalidCalcBase = $taxModel->save([
        'country_code' => 'TH', 'code' => $baseCode . '_C', 'name_th' => 'ทดสอบ', 'name_en' => 'Test',
        'category' => 'tax', 'calc_method' => 'flat_rate', 'calc_base' => 'nonexistent_base_xyz',
        'is_employee_applicable' => true, 'is_employer_applicable' => true,
    ], $userId, $compId);
    checkFalse('an unrecognized calc_base is rejected', $invalidCalcBase['status']);

    echo "\n=== a NEW master row takes effect immediately, no code deploy (the whole point of T047) ===\n";
    $newCatCode = 'test_cat_' . substr(uniqid(), -6);
    $pdo->prepare("INSERT INTO master_statutory_categories (code, name_th, name_en, sort_order) VALUES (:c, 'ทดสอบหมวดใหม่', 'New Test Category', 999)")
        ->execute([':c' => $newCatCode]);
    $saveWithNewCategory = $taxModel->save([
        'country_code' => 'TH', 'code' => $baseCode . '_D', 'name_th' => 'ทดสอบ', 'name_en' => 'Test',
        'category' => $newCatCode, 'calc_method' => 'flat_rate', 'calc_base' => 'basic_salary',
        'is_employee_applicable' => true, 'is_employer_applicable' => true,
    ], $userId, $compId);
    checkTrue('a category row inserted at RUNTIME (no code change) is immediately accepted', $saveWithNewCategory['status']);

    echo "\n=== StatutoryCalculationEngine: unrecognized calc_base surfaces a note, not a silent 0.0 ===\n";
    $newBaseCode = 'test_base_' . substr(uniqid(), -6);
    $pdo->prepare("INSERT INTO master_statutory_calc_bases (code, name_th, name_en, sort_order) VALUES (:c, 'ฐานทดสอบใหม่', 'New Test Base', 999)")
        ->execute([':c' => $newBaseCode]);
    $itemSave = $taxModel->save([
        'country_code' => 'TH', 'code' => $baseCode . '_E', 'name_th' => 'ทดสอบฐานใหม่', 'name_en' => 'New Base Test',
        'category' => 'tax', 'calc_method' => 'flat_rate', 'calc_base' => $newBaseCode,
        'is_employee_applicable' => true, 'is_employer_applicable' => true,
        'is_company_rate_editable' => false, 'default_is_active' => true,
    ], $userId, $compId);
    checkTrue('fixture: item using the NEW (payroll-engine-unwired) calc_base saves', $itemSave['status']);
    $newItemId = $itemSave['id'] ?? 0;
    // Give it a real rate so it would otherwise successfully compute a nonzero amount.
    $rateSave = $taxModel->rateHistorySave([
        'statutory_item_id' => $newItemId, 'effective_date' => '2026-01-01',
        'employee_rate' => '5.00', 'employer_rate' => '5.00',
    ], $userId);
    checkTrue('fixture: rate history saved for the new-calc_base item', $rateSave['status']);

    $engine = new StatutoryCalculationEngine($pdo);
    $result = $engine->calculateItem($compId, $baseCode . '_E', ['basic_salary' => 30000.0], '2026-06-01');
    checkTrue('calculateItem() returns a line for the new-calc_base item', $result !== null);
    if ($result !== null) {
        check('unrecognized calc_base still computes base_amount=0.0 (unchanged behavior, no silent number change)', $result['base_amount'], 0.0);
        check('unrecognized calc_base still computes employee_amount=0.0', $result['employee_amount'], 0.0);
        check('...but now surfaces an explicit note instead of silence', $result['note'], 'unrecognized_calc_base');
    }

    // Sanity: a NORMAL item with a real, recognized calc_base is completely unaffected.
    $normalResult = $engine->calculateItem($compId, $baseCode . '_A', ['basic_salary' => 30000.0], '2026-06-01');
    checkTrue('a normal item (recognized calc_base, no rate configured yet) returns a line', $normalResult !== null);
    if ($normalResult !== null) {
        checkFalse('...and its own note is NOT unrecognized_calc_base (basic_salary is a real context key)', $normalResult['note'] === 'unrecognized_calc_base');
    }

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
