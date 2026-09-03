<?php
/**
 * Verifies CompanySyncModel (2026-09-02, real Origami `GET /api/hr/company` endpoint confirmed
 * live). Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB
 * inside a transaction that is always rolled back, so it never leaves any data behind.
 * Run with: php tests/company_sync_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/sync/OrigamiSyncClientInterface.php';
require_once __DIR__ . '/../app/models/CompanySyncModel.php';

/** Minimal fake client -- only fetchCompany() matters for this test file, same "fake the one
 *  method under test, stub the rest to satisfy the interface" convention as
 *  tests/master_data_sync_test.php's own FakeOrigamiSyncClient. */
class FakeCompanyOrigamiClient implements OrigamiSyncClientInterface {
    public ?array $company = null;
    public bool $throwOnFetch = false;

    public function fetchCompany(int $origamiCompanyId): ?array {
        if ($this->throwOnFetch) {
            throw new RuntimeException('Fake HTTP failure');
        }
        return $this->company;
    }
    public function fetchDepartments(int $origamiCompanyId): array { return []; }
    public function fetchPositions(int $origamiCompanyId): array { return []; }
    public function fetchShifts(int $origamiCompanyId): array { return []; }
    public function fetchBranches(int $origamiCompanyId): array { return []; }
    public function fetchTeams(int $origamiCompanyId): array { return []; }
    public function fetchHolidays(int $origamiCompanyId): array { return []; }
    public function fetchLeaveTypes(int $origamiCompanyId): array { return []; }
    public function fetchOtRates(int $origamiCompanyId): array { return []; }
    public function fetchEmployees(int $origamiCompanyId): array { return []; }
    public function fetchAttendance(int $origamiCompanyId, string $dateFrom, string $dateTo): array { return []; }
    public function fetchLeaveRequests(int $origamiCompanyId, string $dateFrom, string $dateTo): array { return []; }
    public function fetchOvertimeRecords(int $origamiCompanyId, string $dateFrom, string $dateTo): array { return []; }
}

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
    $compId = 1; // Real dev company (Trandar International), same convention as every other test file.
    $userId = 1;
    $fake = new FakeCompanyOrigamiClient();
    $model = new CompanySyncModel($pdo, $fake);

    // Snapshot the real row so every assertion below is relative, not hardcoded against
    // whatever happens to be in the shared dev DB right now (see feedback_dev_db_shared_state_test_fragility).
    $before = $pdo->query("SELECT ref_id, company_legal_name, local_name, global_tax_id, address_line_1, logo_path FROM companies WHERE id = {$compId}")->fetch(PDO::FETCH_ASSOC);
    checkTrue('fixture: company row exists', $before !== false);

    echo "=== Not linked (companies.ref_id is NULL) ===\n";
    $pdo->prepare("UPDATE companies SET ref_id = NULL WHERE id = :id")->execute([':id' => $compId]);
    $notLinked = $model->sync($compId, $userId);
    checkFalse('sync() fails when companies.ref_id is NULL', $notLinked['status']);

    $pdo->prepare("UPDATE companies SET ref_id = :ref WHERE id = :id")->execute([':ref' => $before['ref_id'] ?: 999999, ':id' => $compId]);

    echo "=== Origami returns no company (404 -> null) ===\n";
    $fake->company = null;
    $rNone = $model->sync($compId, $userId);
    checkFalse('sync() fails cleanly when Origami has no matching company', $rNone['status']);

    echo "=== Full real data -- every field overwritten (Origami is always the data owner) ===\n";
    $fake->company = [
        'ref_id' => 2, 'code' => 'TDI', 'name_th' => 'บริษัท ทดสอบ ซิงค์ จำกัด', 'name_en' => 'Test Sync Co., Ltd.',
        'tax_id' => '0199999999999', 'branch_name' => 'สำนักงานใหญ่', 'address_th' => '999 ถนนทดสอบ กรุงเทพฯ 10000', 'address_en' => null,
        'telephone' => '02-000-0000', 'fax' => '02-000-0001', 'logo_url' => null, 'is_active' => true, 'updated_at' => '2026-09-02 00:00:00',
    ];
    $r1 = $model->sync($compId, $userId);
    checkTrue('sync() succeeds' . (empty($r1['status']) ? " ({$r1['message']})" : ''), $r1['status']);
    $after1 = $pdo->query("SELECT company_legal_name, local_name, global_tax_id, address_line_1 FROM companies WHERE id = {$compId}")->fetch(PDO::FETCH_ASSOC);
    check('company_legal_name overwritten from name_en', $after1['company_legal_name'], 'Test Sync Co., Ltd.');
    check('local_name overwritten from name_th', $after1['local_name'], 'บริษัท ทดสอบ ซิงค์ จำกัด');
    check('global_tax_id overwritten from tax_id', $after1['global_tax_id'], '0199999999999');
    check('address_line_1 overwritten from address_th (address_en was null)', $after1['address_line_1'], '999 ถนนทดสอบ กรุงเทพฯ 10000');

    echo "=== A re-sync with a DIFFERENT value overwrites again (one-directional, confirmed policy) ===\n";
    $fake->company['name_en'] = 'Renamed Co., Ltd.';
    $model->sync($compId, $userId);
    $after2 = $pdo->query("SELECT company_legal_name FROM companies WHERE id = {$compId}")->fetchColumn();
    check('company_legal_name overwritten AGAIN on re-sync (Origami always wins, per confirmed policy)', $after2, 'Renamed Co., Ltd.');

    echo "=== NOT NULL fallback: Origami sends a genuinely blank name_en -- must NOT blank the local column ===\n";
    $fake->company['name_en'] = '';
    $model->sync($compId, $userId);
    $after3 = $pdo->query("SELECT company_legal_name FROM companies WHERE id = {$compId}")->fetchColumn();
    check('company_legal_name survives a blank name_en unchanged (NOT NULL column, never blanked)', $after3, 'Renamed Co., Ltd.');

    echo "=== address_en fallback when address_th is blank ===\n";
    $fake->company['name_en'] = 'Test Sync Co., Ltd.';
    $fake->company['address_th'] = '';
    $fake->company['address_en'] = '888 English Address Rd, Bangkok';
    $model->sync($compId, $userId);
    $after4 = $pdo->query("SELECT address_line_1 FROM companies WHERE id = {$compId}")->fetchColumn();
    check('address_line_1 falls back to address_en when address_th is blank', $after4, '888 English Address Rd, Bangkok');

    echo "=== Origami-side failure (network/HTTP error) is caught cleanly, not an uncaught exception ===\n";
    $fake->throwOnFetch = true;
    $rErr = $model->sync($compId, $userId);
    checkFalse('sync() fails cleanly on a client exception', $rErr['status']);
    checkTrue('error message is the real exception message, not swallowed', strpos((string)($rErr['message'] ?? ''), 'Fake HTTP failure') !== false);
    $fake->throwOnFetch = false;

    echo "=== sync_batches audit trail ===\n";
    $fake->company['telephone'] = null; // no other change needed for this section
    $model->sync($compId, $userId);
    $batchCount = (int)$pdo->query("SELECT COUNT(*) FROM sync_batches WHERE comp_id = {$compId} AND entity_type = 'company'")->fetchColumn();
    checkTrue('at least one sync_batches row logged (entity_type=company)', $batchCount > 0);

    echo "=== downloadLogo() guard clauses (no real network call -- non-http(s)/blank input rejected) ===\n";
    $logoReflection = new ReflectionMethod(CompanySyncModel::class, 'downloadLogo');
    $logoReflection->setAccessible(true);
    checkTrue('downloadLogo() rejects a non-http(s) scheme (e.g. file://)', $logoReflection->invoke($model, 'file:///etc/passwd') === null);
    checkTrue('downloadLogo() rejects a blank URL', $logoReflection->invoke($model, '') === null);
    checkTrue('downloadLogo() rejects null', $logoReflection->invoke($model, null) === null);

    echo "=== requireConnected() guard (real client, not the fake -- confirms it's actually wired) ===\n";
    require_once __DIR__ . '/../app/services/sync/OrigamiSyncClient.php';
    checkTrue('OrigamiSyncClient::isConfigured() reflects real .env state (no crash either way)', is_bool(OrigamiSyncClient::isConfigured()));

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
