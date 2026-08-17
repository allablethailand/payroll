<?php
/**
 * Lightweight verification script for the Payroll Sync ingest API (PayrollSyncModel /
 * PAYROLL_SYNC_API.md). Not PHPUnit — see tests/statutory_engine_test.php for why. Runs against
 * the real dev DB inside a transaction that is always rolled back, so it never leaves any data
 * behind. Run with: php tests/payroll_sync_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/PayrollSyncModel.php';

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
    $compCode = 'SYNCTEST_' . uniqid();

    // ---------- Fixtures ----------
    $insComp = $pdo->prepare("INSERT INTO `companies`
        (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, origami_payroll_comp_code)
        VALUES ('Sync Test Co.', 'Sync Test Co.', 'TH', '0000000000000', 'Test Address', 'Test Signatory', :comp_code)");
    $insComp->execute([':comp_code' => $compCode]);
    $compId = (int)$pdo->lastInsertId();

    $mappedEmployeeNo = 'PAYCODE_' . uniqid();
    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'ซิงค์', 'Test', 'Sync', '1990-01-01', 'Thai',
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', 30000, '2020-01-01', 'average', 'active')");
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => $mappedEmployeeNo, ':email' => uniqid() . '@test.local']);
    $mappedEmployeeId = (int)$pdo->lastInsertId();

    function samplePayload(string $compCode, string $mappedCode, string $unmappedCode, int $processId, float $otValue): array {
        return [
            'schema_version' => 1,
            'process_id' => $processId,
            'process_no' => 'ORIGAMI-TEST-' . $processId,
            'report_id' => 7,
            'comp_id' => 999, // Origami's own internal id -- deliberately NOT what we match on
            'comp_code' => $compCode,
            'comp_name' => 'Sync Test Co. (Origami name)',
            'period_id' => 5,
            'period_name' => 'Monthly (cutoff 20th)',
            'frequency_type' => 'monthly',
            'items' => [
                [
                    'report_item_id' => 12,
                    'emp_id' => 101,
                    'emp_code' => 'E-101',
                    'payroll_code' => $mappedCode,
                    'dept_description' => 'Accounting',
                    'position_name' => 'Accountant',
                    'branch_id' => 2,
                    'branch_name' => 'Head Office',
                    'shift_working_id' => 1,
                    'shift_working_name' => 'Office Hours',
                    'pay_type' => 'transfer',
                    'pay_bank_id' => 10,
                    'pay_bank_code' => 'smbc',
                    'pay_bank_name' => 'Sumitomo Mitsui Banking Corporation',
                    'pay_bank_no' => '1234567890',
                    'deduct_sso' => true,
                    'working_days' => 22,
                    'working_mins' => 10560,
                    'absent_days' => 0,
                    'absent_mins' => 0,
                    'late_mins' => 15,
                    'early_mins' => 0,
                    'ot_mins' => 120,
                    'ot_req_hrs' => 2,
                    'ot_req_working_day_hrs' => 2,
                    'ot_req_weekend_hrs' => 0,
                    'ot_req_holiday_hrs' => 0,
                    'leave_approve_days' => 1,
                    'leave_wait_days' => 0,
                    'leave_without_pay_days' => 0,
                    'trip_allowance' => 300,
                    'item_values' => [
                        ['item_id' => 4, 'item_code' => 'OT', 'item_name' => 'Overtime', 'item_type' => 'INCOME', 'unit_type' => 'hours', 'value' => $otValue, 'remark' => null],
                        ['item_id' => 4, 'item_code' => 'OT', 'item_name' => 'Overtime', 'item_type' => 'INCOME', 'unit_type' => 'days', 'value' => 0.25, 'remark' => 'same OT, days unit'],
                        ['item_id' => 9, 'item_code' => 'CUSTOM_ATTENDANCE_BONUS', 'item_name' => 'Attendance Bonus', 'item_type' => 'INCOME', 'unit_type' => null, 'value' => 500, 'remark' => 'Not deducted for this item'],
                    ],
                ],
                [
                    'report_item_id' => 13,
                    'emp_id' => 102,
                    'emp_code' => 'E-102',
                    'payroll_code' => $unmappedCode,
                    'dept_description' => 'Sales',
                    'position_name' => 'Sales Rep',
                    'pay_type' => 'cash',
                    'deduct_sso' => false,
                    'working_days' => 22,
                    'working_mins' => 10560,
                    'item_values' => [],
                ],
            ],
            'employee_status' => [
                ['emp_id' => 101, 'emp_code' => 'E-101', 'payroll_code' => $mappedCode, 'emp_name' => 'Somchai Jaidee', 'dept_description' => 'Accounting', 'position_name' => 'Accountant', 'emp_start_date' => '2022-03-01', 'emp_resign_date' => null, 'is_new_hire' => 0, 'is_resigned_this_period' => 0, 'status_text' => 'Active'],
            ],
        ];
    }

    $model = new PayrollSyncModel($pdo);
    $processId = random_int(100000, 999999);
    $unmappedCode = 'NO_SUCH_CODE_' . uniqid();

    // ---------- Fresh ingest ----------
    echo "=== Fresh ingest ===\n";
    $payload = samplePayload($compCode, $mappedEmployeeNo, $unmappedCode, $processId, 2.0);
    $result = $model->ingest($payload);
    checkTrue('ingest succeeds' . (empty($result['status']) ? " ({$result['message']})" : ''), $result['status']);
    check('unmapped_items reported as 1', $result['unmapped_items'] ?? null, 1);
    $processRowId = $result['process_row_id'] ?? 0;

    $header = $pdo->query("SELECT * FROM payroll_sync_processes WHERE id = {$processRowId}")->fetch(PDO::FETCH_ASSOC);
    check('header comp_id resolved correctly', (int)($header['comp_id'] ?? 0), $compId);
    check('header item_count', (int)($header['item_count'] ?? -1), 2);
    check('header unmapped_item_count', (int)($header['unmapped_item_count'] ?? -1), 1);

    $items = $pdo->query("SELECT * FROM payroll_sync_items WHERE process_id = {$processRowId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check('two item rows stored', count($items), 2);
    check('mapped row resolved employee_id', (int)($items[0]['employee_id'] ?? 0), $mappedEmployeeId);
    check('mapped row mapping_status', $items[0]['mapping_status'] ?? null, 'mapped');
    check('unmapped row employee_id is NULL', $items[1]['employee_id'], null);
    check('unmapped row mapping_status', $items[1]['mapping_status'] ?? null, 'unmapped');
    check('unmapped row keeps original payroll_code', $items[1]['payroll_code'] ?? null, $unmappedCode);

    // ---------- Payment / SSO fields (added to the doc 2026-08-17) ----------
    check('transfer row pay_type stored', $items[0]['pay_type'] ?? null, 'transfer');
    check('transfer row pay_bank_code stored', $items[0]['pay_bank_code'] ?? null, 'smbc');
    check('transfer row pay_bank_name stored', $items[0]['pay_bank_name'] ?? null, 'Sumitomo Mitsui Banking Corporation');
    checkTrue('transfer row pay_bank_no is encrypted at rest (not plaintext)', ($items[0]['pay_bank_no'] ?? '') !== '1234567890' && !empty($items[0]['pay_bank_no']));
    check('transfer row deduct_sso stored as 1 (true)', (int)($items[0]['deduct_sso'] ?? -1), 1);
    check('cash row pay_type stored', $items[1]['pay_type'] ?? null, 'cash');
    check('cash row pay_bank_no is null (no bank on file)', $items[1]['pay_bank_no'], null);
    check('cash row deduct_sso stored as 0 (explicit false, not NULL)', (int)($items[1]['deduct_sso'] ?? -1), 0);

    $detail = $model->getProcessDetail($processRowId, $compId);
    check('getProcessDetail decrypts + masks pay_bank_no to last 4 digits', $detail['items'][0]['pay_bank_no_masked'] ?? null, 'xxxxxx7890');
    checkTrue('getProcessDetail never exposes raw encrypted pay_bank_no', !array_key_exists('pay_bank_no', $detail['items'][0]));
    checkTrue('getProcessDetail never exposes key_version', !array_key_exists('key_version', $detail['items'][0]));
    check('cash row has no masked bank number', $detail['items'][1]['pay_bank_no_masked'], null);

    $itemValues = json_decode($items[0]['item_values'], true);
    check('item_values round-trips all 3 entries (multi-unit OT included)', count($itemValues), 3);
    check('first item_values entry value round-trips', (float)$itemValues[0]['value'], 2.0);
    check('second item_values entry (days unit) round-trips', $itemValues[1]['unit_type'] === 'days' ? 1 : 0, 1);

    $statusRows = $pdo->query("SELECT * FROM payroll_sync_employee_status WHERE process_id = {$processRowId}")->fetchAll(PDO::FETCH_ASSOC);
    check('employee_status row stored', count($statusRows), 1);
    check('employee_status resolved employee_id', (int)($statusRows[0]['employee_id'] ?? 0), $mappedEmployeeId);

    // ---------- Idempotent resubmit (same process_id, changed value) ----------
    echo "=== Resubmit same process_id (idempotent replace) ===\n";
    $payload2 = samplePayload($compCode, $mappedEmployeeNo, $unmappedCode, $processId, 9.5);
    $result2 = $model->ingest($payload2);
    checkTrue('resubmit succeeds', $result2['status']);
    check('resubmit reuses the same process row (no duplicate)', $result2['process_row_id'] ?? null, $processRowId);

    $processRowCount = (int)$pdo->query("SELECT COUNT(*) FROM payroll_sync_processes WHERE origami_process_id = {$processId}")->fetchColumn();
    check('still exactly one process row for this process_id', $processRowCount, 1);

    $itemsAfter = $pdo->query("SELECT * FROM payroll_sync_items WHERE process_id = {$processRowId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check('still exactly two item rows after resubmit (replaced, not appended)', count($itemsAfter), 2);
    $itemValuesAfter = json_decode($itemsAfter[0]['item_values'], true);
    check('resubmit value actually updated', (float)$itemValuesAfter[0]['value'], 9.5);

    // ---------- Validation failures ----------
    echo "=== Validation failures ===\n";
    $badVersion = samplePayload($compCode, $mappedEmployeeNo, $unmappedCode, random_int(100000, 999999), 1.0);
    $badVersion['schema_version'] = 2;
    $resultBadVersion = $model->ingest($badVersion);
    check('unrecognized schema_version rejected', $resultBadVersion['status'], false);

    $badComp = samplePayload('NO_SUCH_COMP_CODE_' . uniqid(), $mappedEmployeeNo, $unmappedCode, random_int(100000, 999999), 1.0);
    $resultBadComp = $model->ingest($badComp);
    check('unmapped comp_code rejected', $resultBadComp['status'], false);

    // Scoped to this fixture's own comp_id, not a blanket table-wide count -- the dev DB can (and,
    // as of writing, does) hold real rows committed outside this test's transaction (e.g. from
    // live cron ingest testing), which a global COUNT(*) would wrongly pick up as "stray".
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM payroll_sync_processes WHERE comp_id = :comp_id");
    $stmtCount->execute([':comp_id' => $compId]);
    $processCountAfterFailures = (int)$stmtCount->fetchColumn();
    check('no stray rows written by the two rejected payloads (still just 1 process row for this fixture)', $processCountAfterFailures, 1);

    // ---------- pendingList() (Payroll Process page "Pending Pull" station) ----------
    echo "=== pendingList() ===\n";
    $pendingBefore = $model->pendingList($compId);
    check('fresh ingest appears in the pending list', count($pendingBefore), 1);
    check('pending row is the one just ingested', (int)($pendingBefore[0]['id'] ?? 0), $processRowId);

    $insCycle = $pdo->prepare("INSERT INTO payroll_cycles
        (comp_id, cycle_name, payroll_frequency, cutoff_day_of_month, payment_day_of_month, ot_cutoff_type, bank_file_format_id, status)
        VALUES (:comp_id, :cycle_name, 'monthly', 25, 5, 'same_as_attendance', 1, 'active')");
    $insCycle->execute([':comp_id' => $compId, ':cycle_name' => 'SYNC_TEST_CYCLE_' . uniqid()]);
    $syncTestCycleId = (int)$pdo->lastInsertId();

    require_once __DIR__ . '/../app/models/PayrollRunModel.php';
    $runModel = new PayrollRunModel($pdo);
    $linkRes = $runModel->create($compId, [
        'cycle_id' => $syncTestCycleId, 'run_name' => 'FROM_PENDING_' . uniqid(),
        'period_start_date' => '2027-01-01', 'period_end_date' => '2027-01-31', 'payment_date' => '2027-02-05',
        'sync_process_id' => $processRowId,
    ], 1, true);
    checkTrue('linking the pending process to a run succeeds' . (empty($linkRes['status']) ? " ({$linkRes['message']})" : ''), $linkRes['status']);

    $pendingAfter = $model->pendingList($compId);
    check('linked process no longer appears in the pending list', count($pendingAfter), 0);
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
