<?php
/**
 * Lightweight verification script for companies.currency_code (Manual Entry / Platform UX review
 * Phase 5, Option A): CompanyProfileModel::save() persisting a whitelisted currency, defaulting an
 * invalid/missing one to 'THB', and get() returning it back untouched. Not PHPUnit -- see
 * tests/statutory_engine_test.php for why. Runs against the real dev DB inside a transaction that is
 * always rolled back -- uses its own fresh company (CompanyProfileModel::save() reads
 * $_SESSION['user']['company_id'] directly, so a real row must exist to UPDATE; never touches
 * comp_id=1's live data, see feedback_dev_db_shared_state_test_fragility).
 * Run with: php tests/company_currency_setting_test.php
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

function makeCompany(PDO $pdo, string $countryCode): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory', 'active')");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function baseSaveData(): array {
    return [
        'company_legal_name' => 'Test Co Updated',
        'local_name' => 'Test Co Updated Local',
        'registered_country' => 'SG',
        'global_tax_id' => 'TAX' . uniqid(),
        'address_line_1' => 'Test Address',
        'authorized_signatory_name' => 'Test Signatory',
    ];
}

try {
    $compId = makeCompany($pdo, 'TH');
    // Migration backfill happens once at deploy time, not per-INSERT -- a freshly created row
    // relies purely on the column's own DEFAULT 'THB', confirmed here before save() is ever called.
    $freshRow = $pdo->query("SELECT currency_code FROM companies WHERE id = {$compId}")->fetch(PDO::FETCH_ASSOC);
    check('fresh company row defaults to THB before any save()', $freshRow['currency_code'], 'THB');

    $_SESSION['user']['company_id'] = $compId;
    $model = new CompanyProfileModel();

    // Explicit valid currency persists, case-insensitively.
    $ok1 = $model->save(array_merge(baseSaveData(), ['currency_code' => 'sgd']));
    check('save() with lowercase "sgd" succeeds', $ok1, true);
    $row1 = $model->get();
    check('save() persists SGD (uppercased)', $row1['currency_code'], 'SGD');

    // Switching back to another valid code works (not a one-way/write-once field).
    $model->save(array_merge(baseSaveData(), ['currency_code' => 'USD']));
    $row2 = $model->get();
    check('save() can switch to a different valid currency (USD)', $row2['currency_code'], 'USD');

    // An invalid/unsupported code is rejected server-side, not trusted from the client -- degrades
    // to the documented 'THB' fallback rather than persisting garbage or throwing.
    $model->save(array_merge(baseSaveData(), ['currency_code' => 'XYZ']));
    $row3 = $model->get();
    check('save() with an unsupported code ("XYZ") falls back to THB, not persisted verbatim', $row3['currency_code'], 'THB');

    // A save that omits currency_code entirely (e.g. a caller that hasn't been updated) also falls
    // back to THB rather than a null/empty value or a PDO error.
    $dataNoCurrency = baseSaveData();
    unset($dataNoCurrency['currency_code']);
    $model->save($dataNoCurrency);
    $row4 = $model->get();
    check('save() with currency_code entirely absent falls back to THB', $row4['currency_code'], 'THB');

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
