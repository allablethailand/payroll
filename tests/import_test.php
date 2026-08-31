<?php
/**
 * Lightweight verification script for the Excel/CSV import engine (ImportFileParser,
 * ImportService, and importRow() on the sync engine's Syncer classes). Not PHPUnit -- see
 * tests/statutory_engine_test.php for why.
 *
 * Runs against the real dev DB inside a transaction that is always rolled back. Note:
 * ImportService::preview() uses a SAVEPOINT internally so it stays a true no-op even when
 * (as here) it's already running inside this test's own outer transaction.
 *
 * Run with: php tests/import_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/import/ImportFileParser.php';
require_once __DIR__ . '/../app/services/import/ImportService.php';
require_once __DIR__ . '/../app/models/SyncBatchModel.php';
require_once __DIR__ . '/../app/models/AttendanceRecordModel.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

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

$tmpFiles = [];
function writeTmpCsv(string $content): string {
    global $tmpFiles;
    $path = sys_get_temp_dir() . '/import_test_' . uniqid('', true) . '.csv';
    file_put_contents($path, $content);
    $tmpFiles[] = $path;
    return $path;
}
function writeTmpXlsx(array $rows): string {
    global $tmpFiles;
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    foreach ($rows as $r => $row) {
        foreach ($row as $c => $value) {
            $sheet->setCellValue([$c + 1, $r + 1], $value);
        }
    }
    $path = sys_get_temp_dir() . '/import_test_' . uniqid('', true) . '.xlsx';
    (new XlsxWriter($spreadsheet))->save($path);
    $tmpFiles[] = $path;
    return $path;
}

try {
    $compId = 1;
    $adminUserId = 1;
    $parser = new ImportFileParser();
    $importService = new ImportService($pdo);

    // ---------- ImportFileParser: CSV ----------
    echo "=== ImportFileParser ===\n";
    $csvPath = writeTmpCsv("Department Code,Name (Thai),Name (English)\nIMP_DEPT_A,แผนกเอ,Dept A\nIMP_DEPT_B,แผนกบี,Dept B\n\n");
    $csvRows = $parser->parse($csvPath, 'departments.csv');
    check('CSV: 2 data rows parsed (blank trailing row skipped)', count($csvRows), 2);
    check('CSV: header text used as row keys', $csvRows[0]['Department Code'], 'IMP_DEPT_A');
    check('CSV: Thai text round-trips', $csvRows[0]['Name (Thai)'], 'แผนกเอ');

    $xlsxPath = writeTmpXlsx([
        ['Department Code', 'Name (Thai)', 'Name (English)'],
        ['IMP_DEPT_X', 'แผนกเอ็กซ์', 'Dept X'],
    ]);
    $xlsxRows = $parser->parse($xlsxPath, 'departments.xlsx');
    check('XLSX: 1 data row parsed', count($xlsxRows), 1);
    check('XLSX: header text used as row keys', $xlsxRows[0]['Department Code'], 'IMP_DEPT_X');

    $badPath = writeTmpCsv("a,b\n1,2\n");
    try {
        $parser->parse($badPath, 'file.txt');
        checkTrue('unsupported extension throws', false);
    } catch (InvalidArgumentException $e) {
        checkTrue('unsupported extension throws', true);
    }

    // ---------- ImportService::templateColumns() / generateTemplate() ----------
    echo "=== templateColumns() / generateTemplate() ===\n";
    foreach (['department', 'position', 'shift', 'holiday', 'leave_type', 'ot_rate', 'employee', 'attendance', 'leave', 'overtime'] as $type) {
        $cols = $importService->templateColumns($type);
        checkTrue("templateColumns('{$type}') is non-empty", count($cols) > 0);
    }
    $template = $importService->generateTemplate('department');
    check('generated template mime type', $template['mime_type'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    checkTrue('generated template has content', strlen($template['content']) > 0);
    $templateTmpPath = sys_get_temp_dir() . '/import_template_' . uniqid('', true) . '.xlsx';
    file_put_contents($templateTmpPath, $template['content']);
    $tmpFiles[] = $templateTmpPath;
    $templateSheet = (new XlsxReader())->load($templateTmpPath)->getActiveSheet();
    check('template header row matches templateColumns() labels', $templateSheet->getCell('B1')->getValue(), $importService->templateColumns('department')['code']);

    // ---------- mapRows() ----------
    echo "=== mapRows() ===\n";
    $deptColumns = $importService->templateColumns('department');
    $parsedForMapping = [['Department Code' => 'MAP_A', 'Name (Thai)' => 'เอ', 'Name (English)' => 'A', 'Unknown Column' => 'x']];
    $mapped = $importService->mapRows($parsedForMapping, $deptColumns);
    check('code column auto-mapped', $mapped['rows'][0]['code'], 'MAP_A');
    check('name_en column auto-mapped', $mapped['rows'][0]['name_en'], 'A');
    check('unrecognized header reported as unmapped', $mapped['unmapped_headers'], ['Unknown Column']);

    $mappedWithOverride = $importService->mapRows($parsedForMapping, $deptColumns, ['Unknown Column' => 'ref_id']);
    check('explicit mapping resolves a previously-unmapped header', $mappedWithOverride['rows'][0]['ref_id'], 'x');
    check('no unmapped headers left once explicitly mapped', $mappedWithOverride['unmapped_headers'], []);

    // ---------- Department: preview vs commit + natural-key re-import ----------
    echo "=== Department import: preview/commit/re-import ===\n";
    $deptRows = [['code' => 'IMP_ENG', 'name_th' => 'วิศวกรรมนำเข้า', 'name_en' => 'Imported Engineering']];
    $countBefore = (int)$pdo->query("SELECT COUNT(*) FROM structure_departments WHERE department_code = 'IMP_ENG'")->fetchColumn();
    $previewRes = $importService->preview($compId, 'department', $deptRows, $adminUserId);
    checkTrue('preview succeeds', $previewRes['status']);
    check('preview: 1 success', $previewRes['success'], 1);
    check('preview: action is inserted', $previewRes['row_results'][0]['action'], 'inserted');
    check('preview: batch_id is null (not persisted)', $previewRes['batch_id'], null);
    $countAfterPreview = (int)$pdo->query("SELECT COUNT(*) FROM structure_departments WHERE department_code = 'IMP_ENG'")->fetchColumn();
    check('preview does NOT persist the row', $countAfterPreview, $countBefore);

    $commitRes = $importService->commit($compId, 'department', $deptRows, $adminUserId);
    checkTrue('commit succeeds', $commitRes['status']);
    checkTrue('commit: batch_id is set', $commitRes['batch_id'] !== null);
    $deptRow = $pdo->prepare("SELECT id, data_source, origami_ref_id, sync_batch_id FROM structure_departments WHERE department_code = 'IMP_ENG' AND comp_id = :c");
    $deptRow->execute([':c' => $compId]);
    $dept = $deptRow->fetch(PDO::FETCH_ASSOC);
    checkTrue('department row persisted after commit', $dept !== false);
    check('data_source is import', $dept['data_source'], 'import');
    check('origami_ref_id stays NULL (not required for import)', $dept['origami_ref_id'], null);
    $deptId = (int)$dept['id'];

    // Re-import the SAME code -- must update, not duplicate.
    $deptRows[0]['name_en'] = 'Imported Engineering & R&D';
    $commitRes2 = $importService->commit($compId, 'department', $deptRows, $adminUserId);
    check('re-import: action is updated', $commitRes2['row_results'][0]['action'], 'updated');
    $countAfterReimport = (int)$pdo->query("SELECT COUNT(*) FROM structure_departments WHERE department_code = 'IMP_ENG'")->fetchColumn();
    check('re-import did not create a duplicate row', $countAfterReimport, 1);
    $updatedDept = $pdo->query("SELECT id, department_name_en FROM structure_departments WHERE id = {$deptId}")->fetch(PDO::FETCH_ASSOC);
    check('re-import updated the SAME row', (int)$updatedDept['id'], $deptId);
    check('re-import updated the name', $updatedDept['department_name_en'], 'Imported Engineering & R&D');

    // ---------- Employee import resolves department by CODE, not ref_id ----------
    echo "=== Employee import (resolves department_code) ===\n";
    $empRows = [[
        'employee_no' => 'IMP_EMP_1', 'name_th' => 'ทดสอบ', 'surname_th' => 'นำเข้า', 'name_en' => 'Test', 'surname_en' => 'Import',
        'date_of_birth' => '1994-05-05', 'gender' => 'female', 'department_code' => 'IMP_ENG',
        'employment_date' => '2024-06-01', 'employment_status' => 'permanent',
        'personal_email' => 'impemp1@test.local', 'mobile_no' => '0844444444',
    ]];
    $empImportRes = $importService->commit($compId, 'employee', $empRows, $adminUserId);
    checkTrue('employee import succeeds', $empImportRes['status']);
    check('1 success', $empImportRes['success'], 1);
    $empRow = $pdo->prepare("SELECT id, department_id, data_source FROM employees WHERE employee_no = 'IMP_EMP_1' AND comp_id = :c");
    $empRow->execute([':c' => $compId]);
    $emp = $empRow->fetch(PDO::FETCH_ASSOC);
    checkTrue('employee created', $emp !== false);
    check('department_id resolved from department_code (not left null)', (int)$emp['department_id'], $deptId);
    $employeeId = (int)$emp['id'];

    // Unknown department code must fail the row cleanly, not the whole batch.
    $empRows2 = [
        ['employee_no' => 'IMP_EMP_2', 'name_th' => 'ก', 'surname_th' => 'ข', 'name_en' => 'A', 'surname_en' => 'B', 'date_of_birth' => '1990-01-01', 'gender' => 'male', 'department_code' => 'NOPE', 'employment_date' => '2024-01-01', 'employment_status' => 'permanent', 'personal_email' => 'x@test.local', 'mobile_no' => '0800000001'],
        ['employee_no' => 'IMP_EMP_3', 'name_th' => 'ค', 'surname_th' => 'ง', 'name_en' => 'C', 'surname_en' => 'D', 'date_of_birth' => '1990-01-01', 'gender' => 'male', 'employment_date' => '2024-01-01', 'employment_status' => 'permanent', 'personal_email' => 'y@test.local', 'mobile_no' => '0800000002'],
    ];
    $empImportRes2 = $importService->commit($compId, 'employee', $empRows2, $adminUserId);
    check('1 success (no department), 1 error (unknown department code)', [$empImportRes2['success'], $empImportRes2['error']], [1, 1]);

    // ---------- Transaction import: attendance resolves employee by employee_no ----------
    echo "=== Attendance import (resolves employee_no) ===\n";
    $attRows = [['employee_no' => 'IMP_EMP_1', 'work_date' => '2026-02-10', 'status' => 'present', 'clock_in' => '2026-02-10 08:00:00', 'clock_out' => '2026-02-10 17:00:00']];
    $attImportRes = $importService->commit($compId, 'attendance', $attRows, $adminUserId);
    checkTrue('attendance import succeeds', $attImportRes['status']);
    check('1 success', $attImportRes['success'], 1);
    $attRow = $pdo->prepare("SELECT employee_id, actual_work_minutes, data_source FROM attendance_records WHERE employee_id = :emp AND work_date = '2026-02-10'");
    $attRow->execute([':emp' => $employeeId]);
    $att = $attRow->fetch(PDO::FETCH_ASSOC);
    checkTrue('attendance record created', $att !== false);
    check('employee_id resolved via employee_no', (int)$att['employee_id'], $employeeId);
    check('actual_work_minutes computed', (int)$att['actual_work_minutes'], 540);
    check('data_source is import', $att['data_source'], 'import');

    // Unknown employee_no fails cleanly.
    $attRows2 = [['employee_no' => 'NOT_A_REAL_EMPLOYEE', 'work_date' => '2026-02-11', 'status' => 'present']];
    $attImportRes2 = $importService->commit($compId, 'attendance', $attRows2, $adminUserId);
    check('unknown employee_no rejected as a row error', $attImportRes2['error'], 1);

    // ---------- 2026-08-30 (Phase 5, conflict-prevention): cross-source overwrite is reported ----------
    echo "=== Cross-source conflict reporting (import overwriting a sync-sourced row) ===\n";
    // Simulate the Feb 10 attendance row (created via import above) having actually come from a
    // real Origami sync on a previous pull, then re-import the SAME employee_no+work_date.
    $pdo->prepare("UPDATE attendance_records SET data_source = 'sync' WHERE employee_id = :emp AND work_date = '2026-02-10'")
        ->execute([':emp' => $employeeId]);
    $attReimportRes = $importService->commit($compId, 'attendance', $attRows, $adminUserId);
    checkTrue('re-import over a sync-sourced row still succeeds (not blocked)', $attReimportRes['status']);
    check('re-import: action is updated', $attReimportRes['row_results'][0]['action'], 'updated');
    checkTrue('re-import: source_conflict flagged', !empty($attReimportRes['row_results'][0]['source_conflict']));
    check('re-import: previous_source reported as sync', $attReimportRes['row_results'][0]['previous_source'], 'sync');
    check('re-import: conflict count is 1', $attReimportRes['conflict'], 1);
    $attAfterReimport = $pdo->prepare("SELECT data_source FROM attendance_records WHERE employee_id = :emp AND work_date = '2026-02-10'");
    $attAfterReimport->execute([':emp' => $employeeId]);
    check('data_source now reflects the LATEST write (import), not left stale as sync', $attAfterReimport->fetchColumn(), 'import');

    // A normal import with no prior different-source row must NOT be flagged.
    $attRowsFresh = [['employee_no' => 'IMP_EMP_1', 'work_date' => '2026-02-12', 'status' => 'present']];
    $attFreshRes = $importService->commit($compId, 'attendance', $attRowsFresh, $adminUserId);
    checkTrue('fresh import (no prior row) is NOT flagged as a conflict', empty($attFreshRes['row_results'][0]['source_conflict']));
    check('fresh import: conflict count is 0', $attFreshRes['conflict'], 0);

    // ---------- 2026-08-30 (Phase 5, conflict-prevention): leave date-range OVERLAP is rejected ----------
    echo "=== Leave import: overlap-aware conflict rejection ===\n";
    $categoryId = (int)$pdo->query("SELECT id FROM master_leave_categories LIMIT 1")->fetchColumn();
    checkTrue('fixture: a leave category exists to attach the test leave type to', $categoryId > 0);
    $pdo->prepare("INSERT INTO leave_types (comp_id, category_id, code, name_th, name_en, quota_amount, status, data_source)
            VALUES (:comp_id, :category_id, 'IMP_LEAVE_TYPE', 'ลาทดสอบนำเข้า', 'Import Test Leave', 5, 'active', 'manual')")
        ->execute([':comp_id' => $compId, ':category_id' => $categoryId]);
    $leaveTypeIdForOverlap = (int)$pdo->lastInsertId();

    $leaveRowsFirst = [['employee_no' => 'IMP_EMP_1', 'leave_type_code' => 'IMP_LEAVE_TYPE', 'start_date' => '2026-03-01', 'end_date' => '2026-03-03', 'total_days' => 3, 'status' => 'approved']];
    $leaveFirstRes = $importService->commit($compId, 'leave', $leaveRowsFirst, $adminUserId);
    checkTrue('first leave import (no conflict) succeeds', $leaveFirstRes['status']);
    check('first leave import: 1 success', $leaveFirstRes['success'], 1);

    // Overlapping (not identical) date range, same employee + same leave type -- must be rejected.
    $leaveRowsOverlap = [['employee_no' => 'IMP_EMP_1', 'leave_type_code' => 'IMP_LEAVE_TYPE', 'start_date' => '2026-03-02', 'end_date' => '2026-03-05', 'total_days' => 4, 'status' => 'approved']];
    $leaveOverlapRes = $importService->commit($compId, 'leave', $leaveRowsOverlap, $adminUserId);
    check('overlapping leave import: 0 success, 1 error', [$leaveOverlapRes['success'], $leaveOverlapRes['error']], [0, 1]);
    checkTrue('overlap error message names the conflicting range', strpos($leaveOverlapRes['errors'][0]['message'], '2026-03-01') !== false);
    $leaveCountRealAfterOverlap = (int)$pdo->query("SELECT COUNT(*) FROM leave_requests WHERE employee_id = {$employeeId} AND leave_type_id = {$leaveTypeIdForOverlap} AND deleted_at IS NULL")->fetchColumn();
    check('overlapping row was NOT inserted', $leaveCountRealAfterOverlap, 1);

    // A DIFFERENT (non-overlapping) date range for the same employee+leave type is still allowed.
    $leaveRowsSeparate = [['employee_no' => 'IMP_EMP_1', 'leave_type_code' => 'IMP_LEAVE_TYPE', 'start_date' => '2026-04-01', 'end_date' => '2026-04-01', 'total_days' => 1, 'status' => 'approved']];
    $leaveSeparateRes = $importService->commit($compId, 'leave', $leaveRowsSeparate, $adminUserId);
    checkTrue('non-overlapping second leave request for the same employee+type is allowed', $leaveSeparateRes['status'] && $leaveSeparateRes['success'] === 1);

    // Re-importing the EXACT same range again is a legitimate UPDATE, not a self-conflict.
    $leaveRowsSame = $leaveRowsFirst;
    $leaveRowsSame[0]['total_days'] = 2.5; // a real correction
    $leaveSameRes = $importService->commit($compId, 'leave', $leaveRowsSame, $adminUserId);
    checkTrue('re-importing the exact same date range updates cleanly (not a self-conflict)', $leaveSameRes['status'] && $leaveSameRes['success'] === 1);
    check('re-import: action is updated, not a rejected overlap', $leaveSameRes['row_results'][0]['action'], 'updated');

    // ---------- 2026-08-30 (Phase 5, T034): AttendanceRecordModel::list()'s new batch_id filter ----------
    echo "=== list() batch_id filter (drill into one import batch's rows) ===\n";
    $attBatchRow = $pdo->prepare("SELECT sync_batch_id FROM attendance_records WHERE employee_id = :emp AND work_date = '2026-02-10'");
    $attBatchRow->execute([':emp' => $employeeId]);
    $importBatchId = (int)$attBatchRow->fetchColumn();
    checkTrue('fixture: the Feb 10 attendance row has a real sync_batch_id', $importBatchId > 0);
    $attModel = new AttendanceRecordModel($pdo);
    $batchRows = $attModel->list($compId, ['batch_id' => $importBatchId]);
    checkTrue('list(batch_id=...) returns exactly the rows from that one batch', count($batchRows) === 1 && (int)$batchRows[0]['employee_id'] === $employeeId);
    $unrelatedBatchRows = $attModel->list($compId, ['batch_id' => 999999999]);
    check('an unknown batch_id returns an empty list, not everything', $unrelatedBatchRows, []);

    // ---------- SyncBatchModel: source filtering ----------
    echo "=== SyncBatchModel source filtering ===\n";
    $importBatches = (new SyncBatchModel($pdo))->list($compId, ['entity_type' => 'department', 'source' => 'import']);
    checkTrue('import batches are listed separately by source', count($importBatches) > 0);
    checkTrue('every listed row has source=import', array_reduce($importBatches, fn($c, $r) => $c && $r['source'] === 'import', true));
    $lastImportTimes = (new SyncBatchModel($pdo))->lastSyncTimes($compId, 'import');
    checkTrue('lastSyncTimes(import) has an entry for department', !empty($lastImportTimes['department']));
    $lastSyncTimesDefault = (new SyncBatchModel($pdo))->lastSyncTimes($compId); // defaults to source='sync'
    checkTrue('lastSyncTimes() default (sync) has NO department entry (only import ran)', empty($lastSyncTimesDefault['department']));

} catch (Throwable $e) {
    $failures++;
    echo "  FAIL  Uncaught exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    $pdo->rollBack();
    foreach ($tmpFiles as $f) {
        @unlink($f);
    }
}

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
