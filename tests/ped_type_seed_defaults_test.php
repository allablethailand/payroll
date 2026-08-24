<?php
/**
 * Lightweight verification script for PayrollEarningDeductionTypeModel::seedDefaults()
 * (2026-08-19 feature: system-provided default earning/deduction items, per explicit request --
 * "give the system defaults, widely-used + necessary ones, admin can add more or delete them --
 * but all soft delete"). Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against
 * the real dev DB inside a transaction that is always rolled back, so it never leaves any data
 * behind. Run with: php tests/ped_type_seed_defaults_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';

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
function checkTrue(string $label, bool $actual): void {
    check($label, $actual, true);
}

try {
    $insComp = $pdo->prepare("INSERT INTO `companies`
        (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES ('PED Seed Test Co.', 'PED Seed Test Co.', 'TH', '0000000000000', 'Test Address', 'Test Signatory')");
    $insComp->execute();
    $compId = (int)$pdo->lastInsertId();

    $model = new PayrollEarningDeductionTypeModel();

    echo "=== seedDefaults() on a fresh company ===\n";
    $firstRes = $model->seedDefaults($compId, 1);
    check('all 14 defaults inserted on a fresh company', $firstRes['inserted'], 14);
    check('nothing skipped on a fresh company', $firstRes['skipped'], 0);

    $rows = $pdo->query("SELECT item_code, item_type, calculation_method, status, is_sync_only FROM payroll_earning_deduction_types WHERE comp_id = {$compId} ORDER BY item_code")->fetchAll(PDO::FETCH_ASSOC);
    check('14 rows actually exist for this company', count($rows), 14);
    checkTrue('every seeded row is active', array_reduce($rows, fn($carry, $r) => $carry && $r['status'] === 'active', true));
    checkTrue('every seeded row is is_sync_only=0 (not protected, deletable like any other row)', array_reduce($rows, fn($carry, $r) => $carry && (int)$r['is_sync_only'] === 0, true));

    $studentLoanRow = $pdo->query("SELECT statutory_report_code FROM payroll_earning_deduction_types WHERE comp_id = {$compId} AND item_code = 'STUDENT_LOAN'")->fetch(PDO::FETCH_ASSOC);
    check('STUDENT_LOAN is tagged with the TH_SLF statutory_report_code', $studentLoanRow['statutory_report_code'] ?? null, 'TH_SLF');

    $otRow = $pdo->query("SELECT source_event_code, calc_sso, calc_pf FROM payroll_earning_deduction_types WHERE comp_id = {$compId} AND item_code = 'OT'")->fetch(PDO::FETCH_ASSOC);
    check('OT is linked to the ot_hours source event', $otRow['source_event_code'] ?? null, 'ot_hours');
    check('OT counts toward SSO', (int)($otRow['calc_sso'] ?? -1), 1);

    echo "=== seedDefaults() is idempotent (safe to click \"Load Default Items\" again) ===\n";
    $secondRes = $model->seedDefaults($compId, 1);
    check('nothing new inserted the second time', $secondRes['inserted'], 0);
    check('all 14 reported as already existing', $secondRes['skipped'], 14);
    $rowsAfterSecond = (int)$pdo->query("SELECT COUNT(*) FROM payroll_earning_deduction_types WHERE comp_id = {$compId}")->fetchColumn();
    check('still exactly 14 rows (no duplicates created)', $rowsAfterSecond, 14);

    echo "=== Seeded items are soft-deletable like any other item, and stay deleted on re-seed ===\n";
    $otId = (int)$pdo->query("SELECT id FROM payroll_earning_deduction_types WHERE comp_id = {$compId} AND item_code = 'OT'")->fetchColumn();
    $deleteRes = $model->delete($compId, $otId, 1);
    checkTrue('deleting a seeded item succeeds' . (empty($deleteRes['status']) ? " ({$deleteRes['message']})" : ''), $deleteRes['status']);
    $otAfterDelete = $pdo->query("SELECT status, deleted_at FROM payroll_earning_deduction_types WHERE id = {$otId}")->fetch(PDO::FETCH_ASSOC);
    check('deleted seeded item is soft-deleted (status=deleted), not hard-removed', $otAfterDelete['status'] ?? null, 'deleted');
    checkTrue('deleted seeded item has deleted_at stamped', !empty($otAfterDelete['deleted_at']));

    $thirdRes = $model->seedDefaults($compId, 1);
    check('re-seeding after a manual delete does not resurrect the deleted item (still counted as existing/skipped)', $thirdRes['skipped'], 14);
    check('re-seeding after a manual delete inserts nothing', $thirdRes['inserted'], 0);
    $otStillDeleted = $pdo->query("SELECT status FROM payroll_earning_deduction_types WHERE id = {$otId}")->fetch(PDO::FETCH_ASSOC);
    check('the deliberately-deleted item is still deleted after re-seeding (admin\'s deletion is respected)', $otStillDeleted['status'] ?? null, 'deleted');
    $activeCount = (int)$pdo->query("SELECT COUNT(*) FROM payroll_earning_deduction_types WHERE comp_id = {$compId} AND status != 'deleted'")->fetchColumn();
    check('13 items remain active after deleting 1 of the 14', $activeCount, 13);
} catch (Throwable $e) {
    $failures++;
    echo "  FAIL  uncaught exception: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
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
