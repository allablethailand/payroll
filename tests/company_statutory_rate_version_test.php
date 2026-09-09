<?php
/**
 * Lightweight verification script for the Clone+Version redesign (2026-09-08, explicit request:
 * "อยากให้ทำแบบ Clone+Multi-Version จริงตามที่อธิบายครับ") -- covers the pieces NOT already exercised
 * end-to-end by tests/statutory_engine_test.php (Scenario 3/8, a company's own version resolving in
 * calculateItem()/calculate()), tests/statutory_master_clone_test.php (ownership isolation +
 * promoteToMaster() both directions), and tests/statutory_master_clone_payroll_run_test.php (Part 4,
 * a real PayrollRunModel run using a company's own version, then a THIRD company defaulting to the
 * promoted rate). Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real
 * dev DB inside a transaction that is always rolled back -- uses its own fresh companies of its own,
 * never touches comp_id=1's live data (see feedback_dev_db_shared_state_test_fragility).
 * Run with: php tests/company_statutory_rate_version_test.php
 *
 * Covers specifically:
 *   1. CompanyProfileModel::save()'s draft->active transition clones every active Master item in
 *      the company's own country into a comp_id-scoped 'master_clone' version (the "ตอนเปิดใช้งาน
 *      บริษัท ก็ดึง Master Clone มาเพื่อให้บริษัทปรับแต่งเอง" part of the request), INCLUDING bracket rows
 *      for a progressive_bracket item (TH_PIT) -- the one-time SQL migration backfill (database/
 *      migrations/2026-09-08_1_statutory_company_rate_versions.sql) did NOT copy brackets (a real
 *      bug found and fixed separately via a corrective migration, see 2026-09-08_2_statutory_
 *      company_rate_version_brackets_backfill.sql's own docblock) -- this proves the ONGOING
 *      per-activation hook (CompanyStatutoryRateVersionModel::cloneMasterForCompany(), what every
 *      NEWLY activated company actually goes through) gets this right from the start.
 *   2. Idempotency -- calling cloneMasterForCompany() a second time for the same company creates no
 *      duplicate rows (save() itself already calls this via CompanyProfileModel, but a defensive
 *      re-run must still be a safe no-op).
 *   3. Overlap rejection is scoped PER COMPANY -- two different companies can have overlapping date
 *      ranges for "the same" Master item without conflicting with EACH OTHER, but a conflict within
 *      the SAME company's own versions is still correctly rejected.
 *   4. pullFromMaster() -- "ถ้าอยากจะดึง Master ก็สามารถดึงได้ทุกเมื่อที่ต้องการกลับมาใช้" -- pulls Master's
 *      current values in as a new company-scoped version tagged source='master_clone', and refuses
 *      cleanly when Master has no currently-effective version to pull at all.
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/AuditLogModel.php';
require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';
require_once __DIR__ . '/../app/models/CompanyProfileModel.php';
require_once __DIR__ . '/../app/models/CompanyStatutoryRateVersionModel.php';
require_once __DIR__ . '/../app/models/TaxStatutoryModel.php';

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

// Draft, placeholder-country row -- mirrors exactly what auth/index.php auto-provisions for a
// brand-new Origami SSO login (see CompanyProfileModel::save()'s own docblock), so the
// draft->active transition below is genuine, not simulated.
function makeDraftCompany(PDO $pdo): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status)
        VALUES (:name, :name, 'XX', 'PENDING', 'PENDING', 'PENDING', 'draft')");
    $stmt->execute([':name' => 'Test Co ' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

try {
    $taxModel = new TaxStatutoryModel();
    $rateVersionModel = new CompanyStatutoryRateVersionModel($pdo);
    $userId = 1;

    echo "=== Part 1: clone-at-activation via CompanyProfileModel::save()'s draft->active transition ===\n";
    $compA = makeDraftCompany($pdo);
    $masterCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM statutory_item_rate_history WHERE comp_id = {$compA}")->fetchColumn();
    check('fresh draft company has zero comp_id-scoped versions before activation', $masterCountBefore, 0);

    $_SESSION['user']['company_id'] = $compA;
    $_SESSION['user']['employee_id'] = $userId;
    $profileModel = new CompanyProfileModel();
    $ok = $profileModel->save([
        'company_legal_name' => 'Test Co Activated', 'local_name' => 'Test Co Activated Local',
        'registered_country' => 'TH', 'global_tax_id' => 'TAX' . uniqid(),
        'address_line_1' => 'Real Address', 'authorized_signatory_name' => 'Real Signatory',
    ]);
    checkTrue('CompanyProfileModel::save() succeeds on the draft->active transition', $ok);
    $rowAfter = $profileModel->get();
    check('company is genuinely active now (fixture precondition)', $rowAfter['setup_status'] ?? null, 'active');

    $expectedMasterCount = (int)$pdo->query("SELECT COUNT(*) FROM statutory_items WHERE country_code='TH' AND comp_id IS NULL AND deleted_at IS NULL AND status='active'
        AND EXISTS (SELECT 1 FROM statutory_item_rate_history WHERE statutory_item_id = statutory_items.id AND comp_id IS NULL AND deleted_at IS NULL
            AND effective_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE()))")->fetchColumn();
    checkTrue('fixture sanity: TH has at least one active Master item with a currently-effective rate', $expectedMasterCount > 0);
    $clonedCount = (int)$pdo->query("SELECT COUNT(*) FROM statutory_item_rate_history WHERE comp_id = {$compA} AND source = 'master_clone' AND deleted_at IS NULL")->fetchColumn();
    check('activation cloned exactly one master_clone version per active, currently-effective TH master item', $clonedCount, $expectedMasterCount);

    $pitId = (int)$pdo->query("SELECT id FROM statutory_items WHERE code='TH_PIT' AND comp_id IS NULL AND deleted_at IS NULL")->fetchColumn();
    checkTrue('fixture: TH_PIT master item id resolved', $pitId > 0);
    $clonedPitRowId = (int)$pdo->query("SELECT id FROM statutory_item_rate_history WHERE comp_id = {$compA} AND statutory_item_id = {$pitId} AND deleted_at IS NULL")->fetchColumn();
    checkTrue('compA has its own cloned TH_PIT (progressive_bracket) version', $clonedPitRowId > 0);
    $masterPitBracketCount = (int)$pdo->query("SELECT COUNT(*) FROM statutory_item_brackets WHERE statutory_item_rate_history_id = (
        SELECT id FROM statutory_item_rate_history WHERE statutory_item_id = {$pitId} AND comp_id IS NULL AND deleted_at IS NULL
        AND effective_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE()) ORDER BY effective_date DESC LIMIT 1)")->fetchColumn();
    $clonedPitBracketCount = (int)$pdo->query("SELECT COUNT(*) FROM statutory_item_brackets WHERE statutory_item_rate_history_id = {$clonedPitRowId}")->fetchColumn();
    checkTrue('fixture sanity: Master TH_PIT has real bracket rows to copy from', $masterPitBracketCount > 0);
    check('compA\'s own cloned TH_PIT version carries the SAME bracket count as Master (brackets genuinely copied, not just the header row)', $clonedPitBracketCount, $masterPitBracketCount);

    echo "\n=== Part 2: idempotency -- re-running cloneMasterForCompany() for the same company is a safe no-op ===\n";
    $rateVersionModel->cloneMasterForCompany($compA, 'TH', $userId);
    $clonedCountAfterRerun = (int)$pdo->query("SELECT COUNT(*) FROM statutory_item_rate_history WHERE comp_id = {$compA} AND deleted_at IS NULL")->fetchColumn();
    check('re-running the clone hook creates no duplicate rows', $clonedCountAfterRerun, $clonedCount);

    echo "\n=== Part 3: overlap rejection is scoped PER COMPANY ===\n";
    $compB = makeDraftCompany($pdo);
    $pdo->prepare("UPDATE companies SET setup_status='active', registered_country='TH' WHERE id = :id")->execute([':id' => $compB]);
    $pvdId = (int)$pdo->query("SELECT id FROM statutory_items WHERE code='TH_PVD' AND comp_id IS NULL AND deleted_at IS NULL")->fetchColumn();
    checkTrue('fixture: TH_PVD master item id resolved', $pvdId > 0);

    // compA already has an OPEN-ended master_clone version for TH_PVD from Part 1's activation
    // (effective_date = today, end_date = NULL) -- a NEW version for compA covering an overlapping
    // range must be rejected...
    $overlapSameCompany = $rateVersionModel->save($compA, [
        'statutory_item_id' => $pvdId, 'effective_date' => '2020-01-01', 'employee_rate' => 9, 'employer_rate' => 9,
    ], $userId);
    checkFalse('a NEW open-ended version for compA overlapping its OWN existing clone is rejected', $overlapSameCompany['status']);

    // ...but the EXACT SAME date range for compB (which has no versions of its own for TH_PVD yet)
    // must succeed -- overlap is scoped per comp_id, not global to the item.
    $noOverlapOtherCompany = $rateVersionModel->save($compB, [
        'statutory_item_id' => $pvdId, 'effective_date' => '2020-01-01', 'employee_rate' => 9, 'employer_rate' => 9,
    ], $userId);
    checkTrue('the SAME date range succeeds for a DIFFERENT company (overlap check is per-company, not global)', $noOverlapOtherCompany['status']);

    echo "\n=== Part 4: pullFromMaster() ===\n";
    $todayPlus1 = (new DateTime('+1 day'))->format('Y-m-d');
    $pull = $rateVersionModel->pullFromMaster($compB, $pvdId, $todayPlus1, $userId);
    checkTrue('pullFromMaster() succeeds' . (empty($pull['message']) ? '' : " ({$pull['message']})"), $pull['status']);
    $pulledRow = $rateVersionModel->get($compB, (int)($pull['id'] ?? 0));
    checkTrue('pulled version is readable back via get()', $pulledRow !== null);
    if ($pulledRow !== null) {
        check('pulled version is tagged source=master_clone (the "Default" badge)', $pulledRow['source'], 'master_clone');
        $masterPvdRate = $pdo->query("SELECT employee_rate FROM statutory_item_rate_history WHERE statutory_item_id = {$pvdId} AND comp_id IS NULL AND deleted_at IS NULL
            AND effective_date <= CURDATE() AND (end_date IS NULL OR end_date >= CURDATE()) ORDER BY effective_date DESC LIMIT 1")->fetchColumn();
        check('pulled version\'s own rate matches Master\'s current rate exactly', round((float)$pulledRow['employee_rate'], 4), round((float)$masterPvdRate, 4));
    }

    // A synthetic Master item with NO rate history at all -- pullFromMaster() must refuse cleanly,
    // not silently pull nothing / crash.
    $noRateCode = 'TESTPULL' . substr(uniqid(), -6);
    $pdo->prepare("INSERT INTO `statutory_items`
        (country_code, code, name_th, name_en, category, calc_method, calc_base, is_employee_applicable, is_employer_applicable, default_is_active, is_company_rate_editable, status)
        VALUES ('TH', :code, 'ทดสอบ', 'Test No Rate', 'other', 'flat_rate', 'basic_salary', 1, 1, 1, 1, 'active')")
        ->execute([':code' => $noRateCode]);
    $noRateItemId = (int)$pdo->lastInsertId();
    $pullNoMaster = $rateVersionModel->pullFromMaster($compB, $noRateItemId, $todayPlus1, $userId);
    checkFalse('pullFromMaster() refuses cleanly when Master has no current version to pull', $pullNoMaster['status']);

    echo "\n=== Part 5: promoteToMaster() -- quick direct check (full both-direction coverage lives in statutory_master_clone_test.php) ===\n";
    $versionToPromote = $rateVersionModel->save($compB, [
        'statutory_item_id' => $noRateItemId, 'effective_date' => '2026-01-01', 'employee_rate' => 6.6, 'employer_rate' => 6.6,
    ], $userId);
    checkTrue('fixture: compB adds its own version for the no-rate-yet item', $versionToPromote['status']);
    $promote = $rateVersionModel->promoteToMaster($compB, (int)$versionToPromote['id'], '2027-06-01', $userId);
    checkTrue('promoteToMaster() succeeds for a brand-new master item with no prior rate history at all', $promote['status']);
    $newMasterRate = $pdo->query("SELECT employee_rate FROM statutory_item_rate_history WHERE statutory_item_id = {$noRateItemId} AND comp_id IS NULL AND effective_date = '2027-06-01' AND deleted_at IS NULL")->fetchColumn();
    check('the new Master row carries the promoted 6.6 rate', round((float)$newMasterRate, 2), 6.6);

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
