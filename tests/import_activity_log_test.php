<?php
/**
 * Lightweight verification script for the Manual Time Entry Import audit-log trio (2026-08-30,
 * explicit request: "เก็บประวัติการ Download ข้อมูลออกจากระบบ และการ Import ข้อมูลเข้าระบบ...เก็บตาม Format
 * Log ที่ควรเก็บเพื่อให้สามารถ Audit ต่อได้"): SyncBatchModel::start()'s new ip/user-agent capture,
 * ImportTemplateDownloadLogModel (a new dedicated table for the Download Template action), and
 * ImportActivityLogModel (the UNION ALL read-layer combining both into one unified history).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 *
 * Run with: php tests/import_activity_log_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/SyncBatchModel.php';
require_once __DIR__ . '/../app/models/ImportTemplateDownloadLogModel.php';
require_once __DIR__ . '/../app/models/ImportActivityLogModel.php';

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
function findRow(array $rows, string $eventType, int $id): ?array {
    foreach ($rows as $r) {
        if ($r['event_type'] === $eventType && (int)$r['id'] === $id) { return $r; }
    }
    return null;
}

try {
    $compId = 1;
    $adminUserId = 1;
    $chromeUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';
    $firefoxUa = 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0';

    // employees.id=1 (the conventional "admin" id many other tests use for created_by/triggered_by,
    // a soft reference with no FK) does not actually EXIST as a real employee row in this dev DB --
    // fine for every other assertion here (no FK to violate), but the performed_by/downloaded_by
    // name-resolution JOIN assertions below need a REAL employee row to prove the JOIN itself works,
    // so a minimal throwaway fixture is created here instead of assuming id=1 resolves.
    $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'บันทึก', 'Test', 'Logger', '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address', 'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual', 'monthly', 30000, '2020-01-01', 'average', 'active')")
        ->execute([':comp_id' => $compId, ':employee_no' => 'IAL_LOGGER_' . uniqid(), ':email' => uniqid() . '@test.local']);
    $realEmployeeId = (int)$pdo->lastInsertId();

    // ---------- SyncBatchModel::start(): ip/user-agent capture (2026-08-30) ----------
    echo "=== SyncBatchModel::start() -- new audit fields ===\n";
    $batchModel = new SyncBatchModel($pdo);
    $batchId = $batchModel->start($compId, 'attendance', 'import', 'manual', $realEmployeeId, null, null, '203.0.113.10', $chromeUa);
    $batchRow = $pdo->prepare("SELECT ip_address, device_type, browser_name, browser_version, user_agent FROM sync_batches WHERE id = :id");
    $batchRow->execute([':id' => $batchId]);
    $b = $batchRow->fetch(PDO::FETCH_ASSOC);
    check('ip_address persisted', $b['ip_address'], '203.0.113.10');
    check('browser_name parsed from user_agent', $b['browser_name'], 'Chrome');
    checkTrue('device_type parsed (non-null)', $b['device_type'] !== null);
    checkTrue('raw user_agent kept alongside the parsed fields', str_contains((string)$b['user_agent'], 'Chrome/119.0.0.0'));

    // Old-style call (no ip/ua at all -- every existing sync-engine call site) must still work exactly as before.
    $legacyBatchId = $batchModel->start($compId, 'attendance', 'sync', 'auto', null);
    $legacyRow = $pdo->prepare("SELECT ip_address, user_agent FROM sync_batches WHERE id = :id");
    $legacyRow->execute([':id' => $legacyBatchId]);
    $legacy = $legacyRow->fetch(PDO::FETCH_ASSOC);
    check('a legacy call with no ip/ua leaves both columns NULL, no error', [$legacy['ip_address'], $legacy['user_agent']], [null, null]);
    $batchModel->complete($batchId, 5, 4, 1, [['row' => 3, 'message' => 'bad row']]);

    // ---------- ImportTemplateDownloadLogModel ----------
    echo "=== ImportTemplateDownloadLogModel ===\n";
    $downloadLogModel = new ImportTemplateDownloadLogModel($pdo);
    $dlId1 = $downloadLogModel->log($compId, 'leave', 'import_template_leave.xlsx', $realEmployeeId, '203.0.113.20', $firefoxUa, 'manual_entry_import_tab');
    $dlId2 = $downloadLogModel->log($compId, 'attendance', 'import_template_attendance.xlsx', $realEmployeeId, '203.0.113.30', $chromeUa, 'manual_entry_import_tab');
    checkTrue('2 distinct download log rows created', $dlId1 > 0 && $dlId2 > 0 && $dlId1 !== $dlId2);

    $downloads = $downloadLogModel->list($compId);
    $dlRow1 = null;
    foreach ($downloads as $d) { if ((int)$d['id'] === $dlId1) { $dlRow1 = $d; } }
    checkTrue('list() returns the leave-template download', $dlRow1 !== null);
    check('browser_name parsed correctly (Firefox)', $dlRow1['browser_name'], 'Firefox');
    check('file_name persisted', $dlRow1['file_name'], 'import_template_leave.xlsx');
    checkTrue('downloaded_by_name_th resolved via the employees JOIN', !empty($dlRow1['downloaded_by_name_th']));

    $downloadsFilteredByType = $downloadLogModel->list($compId, ['entity_type' => 'attendance']);
    checkTrue('entity_type filter narrows correctly', count(array_filter($downloadsFilteredByType, fn($r) => (int)$r['id'] === $dlId2)) === 1);
    checkTrue('entity_type filter excludes the leave download', count(array_filter($downloadsFilteredByType, fn($r) => (int)$r['id'] === $dlId1)) === 0);

    // ---------- ImportActivityLogModel: unified Download + Import history ----------
    echo "=== ImportActivityLogModel (UNION ALL) ===\n";
    $activityModel = new ImportActivityLogModel($pdo);
    $all = $activityModel->list($compId);
    $downloadRow = findRow($all, 'download', $dlId1);
    $importRow = findRow($all, 'import', $batchId);
    checkTrue('unified list() includes the download event', $downloadRow !== null);
    checkTrue('unified list() includes the import event', $importRow !== null);
    check('download row status is completed', $downloadRow['status'], 'completed');
    check('import row status reflects the real batch status', $importRow['status'], 'completed');
    check('import row carries its own success/error counts', [(int)$importRow['success_count'], (int)$importRow['error_count']], [4, 1]);
    check('download row has no success/error counts (NULL, not 0 -- a download has no such concept)', [$downloadRow['success_count'], $downloadRow['error_count']], [null, null]);
    check('import row has no file_name (NULL -- only a download has one)', $importRow['file_name'], null);
    check('download row carries the real file_name', $downloadRow['file_name'], 'import_template_leave.xlsx');
    checkTrue('import row carries the SAME device/IP audit fields a download row does', $importRow['ip_address'] === '203.0.113.10' && $importRow['browser_name'] === 'Chrome');
    checkTrue('performed_by_name_th resolved for the import row too', !empty($importRow['performed_by_name_th']));

    $filteredDownloadOnly = $activityModel->list($compId, ['event_type' => 'download']);
    checkTrue('event_type=download filter excludes import rows', count(array_filter($filteredDownloadOnly, fn($r) => $r['event_type'] === 'import')) === 0);
    checkTrue('event_type=download filter keeps download rows', count(array_filter($filteredDownloadOnly, fn($r) => (int)$r['id'] === $dlId1)) === 1);

    $filteredImportOnly = $activityModel->list($compId, ['event_type' => 'import']);
    checkTrue('event_type=import filter excludes download rows', count(array_filter($filteredImportOnly, fn($r) => $r['event_type'] === 'download')) === 0);

    $filteredByEntity = $activityModel->list($compId, ['entity_type' => 'leave']);
    checkTrue('entity_type filter applies across BOTH event types at once', count(array_filter($filteredByEntity, fn($r) => (int)$r['id'] === $dlId1 && $r['event_type'] === 'download')) === 1);
    checkTrue('entity_type filter excludes the attendance download', count(array_filter($filteredByEntity, fn($r) => (int)$r['id'] === $dlId2)) === 0);

    // Date-range filter (both event types honor performed_at bounds -- downloaded_at/started_at respectively).
    $farFuture = (new DateTime('+2 years'))->format('Y-m-d');
    $noneInFuture = $activityModel->list($compId, ['date_from' => $farFuture]);
    checkTrue('a date_from far in the future excludes everything just created', count(array_filter($noneInFuture, fn($r) => (int)$r['id'] === $dlId1 || ((int)$r['id'] === $batchId && $r['event_type'] === 'import'))) === 0);

} catch (Throwable $e) {
    $failures++;
    echo "  FAIL  Uncaught exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
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
