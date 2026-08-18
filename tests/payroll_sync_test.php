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
                    'idcard' => '1234567890123',
                    'idcard_issued' => '2018-05-01',
                    'idcard_expire' => '2028-05-01',
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

    // ---------- ID card fields (added to the doc 2026-08-18) ----------
    checkTrue('id_card_no is encrypted at rest (not plaintext)', ($items[0]['id_card_no'] ?? '') !== '1234567890123' && !empty($items[0]['id_card_no']));
    check('id_card_issue_date stored', $items[0]['id_card_issue_date'] ?? null, '2018-05-01');
    check('id_card_expire_date stored', $items[0]['id_card_expire_date'] ?? null, '2028-05-01');
    check('row with no idcard sent has null id_card_no', $items[1]['id_card_no'], null);
    check('row with no idcard sent has null id_card_issue_date', $items[1]['id_card_issue_date'], null);

    $detail = $model->getProcessDetail($processRowId, $compId);
    check('getProcessDetail decrypts + masks pay_bank_no to last 4 digits', $detail['items'][0]['pay_bank_no_masked'] ?? null, 'xxxxxx7890');
    checkTrue('getProcessDetail never exposes raw encrypted pay_bank_no', !array_key_exists('pay_bank_no', $detail['items'][0]));
    checkTrue('getProcessDetail never exposes key_version', !array_key_exists('key_version', $detail['items'][0]));
    check('cash row has no masked bank number', $detail['items'][1]['pay_bank_no_masked'], null);
    check('getProcessDetail decrypts + masks id_card_no to last 4 digits', $detail['items'][0]['id_card_no_masked'] ?? null, 'xxxxxxxxx0123');
    checkTrue('getProcessDetail never exposes raw encrypted id_card_no', !array_key_exists('id_card_no', $detail['items'][0]));
    check('getProcessDetail still exposes id_card_expire_date plainly (not PII on its own)', $detail['items'][0]['id_card_expire_date'] ?? null, '2028-05-01');
    check('row with no idcard sent has no masked id card number', $detail['items'][1]['id_card_no_masked'], null);

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

    // ---------- remapUnmappedItems() (auto-sync-on-pull support) ----------
    echo "=== remapUnmappedItems() ===\n";
    $remappedBefore = $model->remapUnmappedItems($processRowId, $compId);
    check('nothing to remap yet (matching employee does not exist)', $remappedBefore, 0);

    $insLateEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'สอง', 'Test', 'Two', '1990-01-01', 'Thai',
         :email, '0800000001', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999998',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', 30000, '2020-01-01', 'average', 'active')");
    $insLateEmp->execute([':comp_id' => $compId, ':employee_no' => $unmappedCode, ':email' => uniqid() . '@test.local']);
    $lateEmployeeId = (int)$pdo->lastInsertId();

    $remappedAfter = $model->remapUnmappedItems($processRowId, $compId);
    check('one row newly resolved once the previously-missing employee now exists', $remappedAfter, 1);

    $itemsAfterRemap = $pdo->query("SELECT * FROM payroll_sync_items WHERE process_id = {$processRowId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check('previously-unmapped row now mapped', $itemsAfterRemap[1]['mapping_status'] ?? null, 'mapped');
    check('previously-unmapped row resolved to the newly-created employee', (int)($itemsAfterRemap[1]['employee_id'] ?? 0), $lateEmployeeId);

    $headerAfterRemap = $pdo->query("SELECT unmapped_item_count FROM payroll_sync_processes WHERE id = {$processRowId}")->fetch(PDO::FETCH_ASSOC);
    check('unmapped_item_count decremented on the header row', (int)($headerAfterRemap['unmapped_item_count'] ?? -1), 0);

    $remappedIdempotent = $model->remapUnmappedItems($processRowId, $compId);
    check('nothing left to remap the second time (no double counting)', $remappedIdempotent, 0);

    // ---------- applyEmployeeMasterFields() (Pending Pull overwrites payment/SSO/ID-card on
    // employees, per explicit request -- treated as source-of-truth every pull) ----------
    echo "=== applyEmployeeMasterFields() ===\n";
    // Give the mapped employee a pre-existing tax_id_no under the current key version first, to
    // prove this method doesn't corrupt a sibling encrypted column that shares employees.key_version.
    $taxIdPlain = '1111111111111';
    $taxEnc = EncryptionService::encrypt($taxIdPlain);
    $pdo->prepare("UPDATE employees SET tax_id_no = :v, tax_id_no_hash = :h, key_version = :kv WHERE id = :id")
        ->execute([':v' => $taxEnc['value'], ':h' => EncryptionService::hash($taxIdPlain), ':kv' => $taxEnc['key_version'], ':id' => $mappedEmployeeId]);

    $updatedCount = $model->applyEmployeeMasterFields($processRowId, $compId, 1);
    check('applyEmployeeMasterFields updates both mapped employees (transfer + cash)', $updatedCount, 2);

    $mappedEmpStmt = $pdo->prepare("SELECT * FROM employees WHERE id = :id");
    $mappedEmpStmt->execute([':id' => $mappedEmployeeId]);
    $mappedEmpRow = $mappedEmpStmt->fetch(PDO::FETCH_ASSOC);
    check('transfer employee payment_type overwritten to bank', $mappedEmpRow['payment_type'] ?? null, 'bank');
    // No master_banks row for "smbc" existed before this pull -- resolveOrCreateBankId() (2026-08-19
    // "create if missing, else use the existing ID" request) auto-creates one rather than leaving
    // bank_id null.
    checkTrue('transfer employee bank_id was resolved (auto-created since "smbc" did not exist yet)', !empty($mappedEmpRow['bank_id']));
    $newBankStmt = $pdo->prepare("SELECT * FROM master_banks WHERE id = :id");
    $newBankStmt->execute([':id' => $mappedEmpRow['bank_id']]);
    $newBankRow = $newBankStmt->fetch(PDO::FETCH_ASSOC);
    check('auto-created bank bank_code matches the sync payload', $newBankRow['bank_code'] ?? null, 'smbc');
    check('auto-created bank bank_name_en matches the sync payload', $newBankRow['bank_name_en'] ?? null, 'Sumitomo Mitsui Banking Corporation');
    check('auto-created bank country_code taken from the pulling company', $newBankRow['country_code'] ?? null, 'TH');
    $decBankNo = EncryptionService::decrypt($mappedEmpRow['bank_account_no'], (int)$mappedEmpRow['key_version']);
    check('transfer employee bank_account_no decrypts to the synced value', $decBankNo, '1234567890');
    check('transfer employee sso_enrolled overwritten to 1 (deduct_sso=true)', (int)($mappedEmpRow['sso_enrolled'] ?? -1), 1);
    $decIdCard = EncryptionService::decrypt($mappedEmpRow['id_card_no'], (int)$mappedEmpRow['key_version']);
    check('transfer employee id_card_no decrypts to the synced value', $decIdCard, '1234567890123');
    check('transfer employee id_card_issue_date overwritten', $mappedEmpRow['id_card_issue_date'] ?? null, '2018-05-01');
    check('transfer employee id_card_expire_date overwritten', $mappedEmpRow['id_card_expire_date'] ?? null, '2028-05-01');
    $decTaxId = EncryptionService::decrypt($mappedEmpRow['tax_id_no'], (int)$mappedEmpRow['key_version']);
    check('sibling encrypted field (tax_id_no) still decrypts correctly after key_version was touched', $decTaxId, $taxIdPlain);

    $lateEmpStmt = $pdo->prepare("SELECT * FROM employees WHERE id = :id");
    $lateEmpStmt->execute([':id' => $lateEmployeeId]);
    $lateEmpRow = $lateEmpStmt->fetch(PDO::FETCH_ASSOC);
    check('cash employee payment_type overwritten to cash', $lateEmpRow['payment_type'] ?? null, 'cash');
    check('cash employee bank_id cleared (no bank on this cycle)', $lateEmpRow['bank_id'], null);
    check('cash employee sso_enrolled overwritten to 0 (deduct_sso=false)', (int)($lateEmpRow['sso_enrolled'] ?? -1), 0);

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

    // ---------- Auto-sync-on-pull (PayrollRunModel::create() runs a master-data sync + remap
    // before linking, so the user doesn't have to click "Sync Now" as a separate step) ----------
    echo "=== Auto-sync-on-pull ===\n";
    checkTrue('create() returns a sync_summary when pulling from a sync process', array_key_exists('sync_summary', $linkRes));
    check('sync_summary attempted all 7 master-data entity types', count($linkRes['sync_summary']['results'] ?? []), 7);
    check('sync_summary remapped_count is 0 (both rows were already resolved before pulling)', $linkRes['sync_summary']['remapped_count'] ?? null, 0);
    check('sync_summary employee_fields_updated re-applies to both mapped employees on every pull', $linkRes['sync_summary']['employee_fields_updated'] ?? null, 2);
    // This fixture's company was never linked to Origami (no companies.ref_id set), so every
    // entity type comes back as a clean per-type failure -- confirms the hook never throws or
    // blocks the pull even when Origami sync isn't configured for this company.
    checkTrue('every entity type sync result reports its own status (no exception)', array_reduce($linkRes['sync_summary']['results'] ?? [], fn($carry, $r) => $carry && array_key_exists('status', $r), true));

    $pendingAfter = $model->pendingList($compId);
    check('linked process no longer appears in the pending list', count($pendingAfter), 0);

    // ---------- Cancel/Delete return a pulled sync process back to Pending Pull (per explicit
    // request) -- pendingList() has no other way to know a run was "un-pulled" besides
    // sync_process_id going back to NULL on the run itself. ----------
    echo "=== Cancel/Delete return the sync process to Pending Pull ===\n";
    $cancelRes = $runModel->cancel((int)$linkRes['id'], $compId, 1, true, 'Test cancel reason');
    checkTrue('cancelling the run succeeds' . (empty($cancelRes['status']) ? " ({$cancelRes['message']})" : ''), $cancelRes['status']);
    $pendingAfterCancel = $model->pendingList($compId);
    check('cancelling a run pulled from sync returns its process to the pending list', count($pendingAfterCancel), 1);
    check('the process that reappears is the same one that was pulled', (int)($pendingAfterCancel[0]['id'] ?? 0), $processRowId);
    $cancelledRunRow = $pdo->query("SELECT sync_process_id FROM payroll_runs WHERE id = {$linkRes['id']}")->fetch(PDO::FETCH_ASSOC);
    check('the cancelled run itself has sync_process_id cleared', $cancelledRunRow['sync_process_id'], null);

    // Pull it again into a second (still-draft) run -- proves the UNIQUE constraint on
    // sync_process_id doesn't block re-use once the previous run released it -- then delete that
    // one to prove delete() returns it too.
    $linkRes2 = $runModel->create($compId, [
        'cycle_id' => $syncTestCycleId, 'run_name' => 'FROM_PENDING_2_' . uniqid(),
        'period_start_date' => '2027-02-01', 'period_end_date' => '2027-02-28', 'payment_date' => '2027-03-05',
        'sync_process_id' => $processRowId,
    ], 1, true);
    checkTrue('re-pulling the same (now-freed) sync process into a second run succeeds' . (empty($linkRes2['status']) ? " ({$linkRes2['message']})" : ''), $linkRes2['status']);
    check('re-pulled process no longer appears in the pending list', count($model->pendingList($compId)), 0);

    $deleteRes = $runModel->delete((int)$linkRes2['id'], $compId, 1, true);
    checkTrue('deleting the (still-draft) second run succeeds' . (empty($deleteRes['status']) ? " ({$deleteRes['message']})" : ''), $deleteRes['status']);
    $pendingAfterDelete = $model->pendingList($compId);
    check('deleting a draft run pulled from sync also returns its process to the pending list', count($pendingAfterDelete), 1);

    // ---------- createPlaceholderEmployeesForUnmapped() (2026-08-19 feature: auto-create a
    // minimal employee straight from THIS payload's own data -- payroll_code + employee_status --
    // NOT the real Origami HR API sync, see PayrollSyncModel class docblock). Placed at the very
    // end of this script (after every count-based pendingList()/comp_id-scoped assertion above)
    // since these fixtures deliberately leave extra payroll_sync_processes rows behind that would
    // otherwise inflate those earlier exact-count checks.
    echo "=== createPlaceholderEmployeesForUnmapped() ===\n";
    $placeholderProcessId = random_int(100000, 999999);
    $placeholderCodeWithStatus = 'PLACEHOLDER_TEST_' . uniqid();
    $placeholderCodeNoStatus = 'PLACEHOLDER_TEST_NOSTATUS_' . uniqid();
    $placeholderPayload = [
        'schema_version' => 1,
        'process_id' => $placeholderProcessId,
        'process_no' => 'ORIGAMI-TEST-PLACEHOLDER-' . $placeholderProcessId,
        'report_id' => 7,
        'comp_id' => 999,
        'comp_code' => $compCode,
        'comp_name' => 'Sync Test Co. (Origami name)',
        'period_id' => 5,
        'period_name' => 'Monthly (cutoff 20th)',
        'frequency_type' => 'monthly',
        'items' => [
            [
                'report_item_id' => 20, 'payroll_code' => $placeholderCodeWithStatus,
                'pay_type' => 'transfer', 'pay_bank_code' => 'smbc', 'pay_bank_name' => 'Sumitomo Mitsui Banking Corporation',
                'pay_bank_no' => '9990001112', 'deduct_sso' => true,
                'idcard' => '9998887776665', 'idcard_issued' => '2019-01-01', 'idcard_expire' => '2029-01-01',
                'item_values' => [],
            ],
            [
                'report_item_id' => 21, 'payroll_code' => $placeholderCodeNoStatus,
                'pay_type' => 'cash', 'deduct_sso' => false, 'item_values' => [],
            ],
        ],
        'employee_status' => [
            ['emp_id' => 201, 'emp_code' => 'E-201', 'payroll_code' => $placeholderCodeWithStatus, 'emp_name' => 'สมหญิง รักดี',
             'dept_description' => 'HR', 'position_name' => 'HR Officer', 'emp_start_date' => '2025-06-15', 'emp_resign_date' => null,
             'is_new_hire' => 1, 'is_resigned_this_period' => 0, 'status_text' => 'New Hire'],
        ],
    ];
    $placeholderIngest = $model->ingest($placeholderPayload);
    checkTrue('placeholder-fixture ingest succeeds' . (empty($placeholderIngest['status']) ? " ({$placeholderIngest['message']})" : ''), $placeholderIngest['status']);
    $placeholderProcessRowId = $placeholderIngest['process_row_id'] ?? 0;
    check('placeholder-fixture: both rows start unmapped', $placeholderIngest['unmapped_items'] ?? null, 2);

    $placeholderRemap = $model->remapUnmappedItems($placeholderProcessRowId, $compId);
    check('remapUnmappedItems finds nothing (neither payroll_code matches a real employee yet)', $placeholderRemap, 0);

    $createdCount = $model->createPlaceholderEmployeesForUnmapped($placeholderProcessRowId, $compId, 1);
    check('createPlaceholderEmployeesForUnmapped creates both missing employees', $createdCount, 2);

    $itemsAfterPlaceholder = $pdo->query("SELECT * FROM payroll_sync_items WHERE process_id = {$placeholderProcessRowId} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    check('both rows now mapping_status=mapped', $itemsAfterPlaceholder[0]['mapping_status'] . '/' . $itemsAfterPlaceholder[1]['mapping_status'], 'mapped/mapped');
    checkTrue('both rows now have an employee_id', !empty($itemsAfterPlaceholder[0]['employee_id']) && !empty($itemsAfterPlaceholder[1]['employee_id']));

    $headerAfterPlaceholder = $pdo->query("SELECT unmapped_item_count FROM payroll_sync_processes WHERE id = {$placeholderProcessRowId}")->fetch(PDO::FETCH_ASSOC);
    check('unmapped_item_count zeroed out on the header row', (int)($headerAfterPlaceholder['unmapped_item_count'] ?? -1), 0);

    $newEmp1Stmt = $pdo->prepare("SELECT * FROM employees WHERE id = :id");
    $newEmp1Stmt->execute([':id' => $itemsAfterPlaceholder[0]['employee_id']]);
    $newEmp1 = $newEmp1Stmt->fetch(PDO::FETCH_ASSOC);
    check('new employee employee_no set to payroll_code verbatim (not a generated code)', $newEmp1['employee_no'] ?? null, $placeholderCodeWithStatus);
    check('new employee data_source is sync (not manual, distinguishable from SSO placeholders)', $newEmp1['data_source'] ?? null, 'sync');
    check('new employee is_payroll_ready=0 (flagged profile_incomplete in recalculate() until HR completes the profile)', (int)($newEmp1['is_payroll_ready'] ?? -1), 0);
    check('new employee name_th split from emp_name (first word)', $newEmp1['name_th'] ?? null, 'สมหญิง');
    check('new employee surname_th split from emp_name (remainder)', $newEmp1['surname_th'] ?? null, 'รักดี');
    check('new employee employment_date taken from employee_status.emp_start_date', $newEmp1['employment_date'] ?? null, '2025-06-15');

    $newEmp2Stmt = $pdo->prepare("SELECT * FROM employees WHERE id = :id");
    $newEmp2Stmt->execute([':id' => $itemsAfterPlaceholder[1]['employee_id']]);
    $newEmp2 = $newEmp2Stmt->fetch(PDO::FETCH_ASSOC);
    check('new employee with no employee_status row falls back to payroll_code as name_th', $newEmp2['name_th'] ?? null, $placeholderCodeNoStatus);
    check('new employee with no employee_status row falls back to payroll_code as surname_th too', $newEmp2['surname_th'] ?? null, $placeholderCodeNoStatus);
    check('new employee with no emp_start_date falls back to today as employment_date', $newEmp2['employment_date'] ?? null, date('Y-m-d'));

    $idempotentCreatedCount = $model->createPlaceholderEmployeesForUnmapped($placeholderProcessRowId, $compId, 1);
    check('nothing left to create the second time (idempotent, no duplicate employees)', $idempotentCreatedCount, 0);

    // Mirrors the real pull order in PayrollRunModel::create(): remap -> createPlaceholder ->
    // applyEmployeeMasterFields, so a brand-new placeholder also gets its payment/SSO/ID-card
    // fields populated in this SAME pull, not left blank until some future pull.
    $placeholderApplyCount = $model->applyEmployeeMasterFields($placeholderProcessRowId, $compId, 1);
    check('applyEmployeeMasterFields also reaches the two just-created placeholder employees', $placeholderApplyCount, 2);
    $newEmp1Stmt->execute([':id' => $itemsAfterPlaceholder[0]['employee_id']]);
    $newEmp1AfterApply = $newEmp1Stmt->fetch(PDO::FETCH_ASSOC);
    check('newly-created placeholder employee payment_type populated from sync data', $newEmp1AfterApply['payment_type'] ?? null, 'bank');
    $decNewBankNo = EncryptionService::decrypt($newEmp1AfterApply['bank_account_no'], (int)$newEmp1AfterApply['key_version']);
    check('newly-created placeholder employee bank_account_no decrypts to the synced value', $decNewBankNo, '9990001112');
    check('newly-created placeholder employee sso_enrolled populated from sync data', (int)($newEmp1AfterApply['sso_enrolled'] ?? -1), 1);

    // ---------- Full wiring through PayrollRunModel::create() (the real Pending Pull path) ----------
    echo "=== createPlaceholderEmployeesForUnmapped() wired into PayrollRunModel::create() ===\n";
    $wireProcessId = random_int(100000, 999999);
    $wireCode = 'PLACEHOLDER_WIRE_TEST_' . uniqid();
    $wirePayload = [
        'schema_version' => 1, 'process_id' => $wireProcessId, 'process_no' => 'ORIGAMI-TEST-WIRE-' . $wireProcessId,
        'report_id' => 7, 'comp_id' => 999, 'comp_code' => $compCode, 'comp_name' => 'Sync Test Co. (Origami name)',
        'period_id' => 5, 'period_name' => 'Monthly (cutoff 20th)', 'frequency_type' => 'monthly',
        'items' => [
            ['report_item_id' => 30, 'payroll_code' => $wireCode, 'pay_type' => 'cash', 'deduct_sso' => false, 'item_values' => []],
        ],
        'employee_status' => [],
    ];
    $wireIngest = $model->ingest($wirePayload);
    checkTrue('wire-fixture ingest succeeds' . (empty($wireIngest['status']) ? " ({$wireIngest['message']})" : ''), $wireIngest['status']);
    $wireProcessRowId = $wireIngest['process_row_id'] ?? 0;

    $insWireCycle = $pdo->prepare("INSERT INTO payroll_cycles
        (comp_id, cycle_name, payroll_frequency, cutoff_day_of_month, payment_day_of_month, ot_cutoff_type, bank_file_format_id, status)
        VALUES (:comp_id, :cycle_name, 'monthly', 25, 5, 'same_as_attendance', 1, 'active')");
    $insWireCycle->execute([':comp_id' => $compId, ':cycle_name' => 'SYNC_TEST_WIRE_CYCLE_' . uniqid()]);
    $wireCycleId = (int)$pdo->lastInsertId();

    $wireRunModel = new PayrollRunModel($pdo);
    $wireLinkRes = $wireRunModel->create($compId, [
        'cycle_id' => $wireCycleId, 'run_name' => 'FROM_PENDING_PLACEHOLDER_' . uniqid(),
        'period_start_date' => '2027-04-01', 'period_end_date' => '2027-04-30', 'payment_date' => '2027-05-05',
        'sync_process_id' => $wireProcessRowId,
    ], 1, true);
    checkTrue('pulling a process with a genuinely-unmatched payroll_code still succeeds' . (empty($wireLinkRes['status']) ? " ({$wireLinkRes['message']})" : ''), $wireLinkRes['status']);
    check('sync_summary reports 1 placeholder employee created during the pull', $wireLinkRes['sync_summary']['placeholders_created'] ?? null, 1);
    $wireItem = $pdo->query("SELECT * FROM payroll_sync_items WHERE process_id = {$wireProcessRowId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('the pulled row is now mapped', $wireItem['mapping_status'] ?? null, 'mapped');
    $wireEmpStmt = $pdo->prepare("SELECT * FROM employees WHERE id = :id");
    $wireEmpStmt->execute([':id' => $wireItem['employee_id']]);
    $wireEmp = $wireEmpStmt->fetch(PDO::FETCH_ASSOC);
    check('the placeholder employee created via the real create() path has the right employee_no', $wireEmp['employee_no'] ?? null, $wireCode);
    check('the placeholder employee created via the real create() path is data_source=sync', $wireEmp['data_source'] ?? null, 'sync');

    // ---------- 2026-08-18 rev 2 fields: dept_id/posi_id/title/gender/date_birth/nickname/
    // nationality/religion/marital_status/military_service/emp_pic/email/emp_tel/spouse/children.
    // Covers: ingest stores everything completely, applyEmployeeMasterFields() resolve-or-creates
    // Department/Position (mirroring the bank upgrade above), maps the safe/unambiguous personal
    // profile fields, and deliberately leaves military_service/pass_pro/emp_pic/spouse/children
    // unmapped per the class docblock. ----------
    echo "=== 2026-08-18 rev 2 fields (dept/position/personal profile mapping) ===\n";
    $profileTestEmployeeNo = 'PROFILE_TEST_' . uniqid();
    $insProfileEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status)
        VALUES (:comp_id, :employee_no, 'ms', 'female', 'เดิม', 'เดิม', 'Old', 'Old', '1990-01-01', 'Thai',
         :email, '0800000002', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999997',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', 30000, '2020-01-01', 'average', 'active')");
    $profileTestOriginalEmail = uniqid() . '@test.local';
    $insProfileEmp->execute([':comp_id' => $compId, ':employee_no' => $profileTestEmployeeNo, ':email' => $profileTestOriginalEmail]);
    $profileTestEmployeeId = (int)$pdo->lastInsertId();

    $profileProcessId = random_int(100000, 999999);
    $profileDeptId = random_int(500000, 599999);
    $profilePosiId = random_int(600000, 699999);
    $profilePayload = [
        'schema_version' => 1, 'process_id' => $profileProcessId, 'process_no' => 'ORIGAMI-TEST-PROFILE-' . $profileProcessId,
        'report_id' => 7, 'comp_id' => 999, 'comp_code' => $compCode, 'comp_name' => 'Sync Test Co. (Origami name)',
        'period_id' => 5, 'period_name' => 'Monthly (cutoff 20th)', 'frequency_type' => 'monthly',
        'items' => [[
            'report_item_id' => 40, 'payroll_code' => $profileTestEmployeeNo,
            'dept_id' => $profileDeptId, 'dept_description' => 'Engineering Test Dept',
            'posi_id' => $profilePosiId, 'position_name' => 'Senior Engineer Test',
            'pay_type' => 'transfer', 'pay_bank_code' => 'smbc', 'pay_bank_name' => 'Sumitomo Mitsui Banking Corporation',
            'pay_bank_no' => '5551112223', 'deduct_sso' => true,
            'title' => 'Mr.', 'gender' => 'M', 'date_birth' => '1985-07-04', 'nickname' => 'Nok',
            'nationality' => 'Thai', 'religion' => 'Buddhist', 'marital_status' => 'married',
            'military_service' => 'exempted', 'pass_pro' => true, 'pass_pro_date' => '2020-01-01',
            'emp_pic' => 'uploads/employee/2/employee/999.jpg',
            'email' => 'worktest@example.com', 'emp_tel' => '0899990000',
            'spouse' => [
                'spouse_title' => 1, 'spouse_name' => 'Malee', 'spouse_lastname' => 'Jaidee',
                'spouse_idcard' => '1112223334445', 'spouse_tax' => '9998887776665',
                'father_idcard' => null, 'mother_idcard' => null,
            ],
            'children' => [
                ['child_id' => 1, 'child_type' => 1, 'child_name' => 'Nong', 'child_lastname' => 'Test', 'child_idcard' => '4445556667778', 'child_birthday' => '2015-05-05', 'child_tax_allowance' => 1],
            ],
            'item_values' => [],
        ]],
        'employee_status' => [],
    ];
    $profileIngest = $model->ingest($profilePayload);
    checkTrue('profile-fixture ingest succeeds' . (empty($profileIngest['status']) ? " ({$profileIngest['message']})" : ''), $profileIngest['status']);
    $profileProcessRowId = $profileIngest['process_row_id'] ?? 0;
    check('profile-fixture row resolves mapped immediately (employee already existed at ingest time)', $profileIngest['unmapped_items'] ?? null, 0);

    $profileItemRaw = $pdo->query("SELECT * FROM payroll_sync_items WHERE process_id = {$profileProcessRowId} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('dept_id stored as-received', (int)($profileItemRaw['dept_id'] ?? 0), $profileDeptId);
    check('posi_id stored as-received', (int)($profileItemRaw['posi_id'] ?? 0), $profilePosiId);
    check('title stored raw (not normalized at ingest)', $profileItemRaw['title'] ?? null, 'Mr.');
    check('pass_pro stored as tri-state 1 (true)', (int)($profileItemRaw['pass_pro'] ?? -1), 1);
    checkTrue('spouse_data stored encrypted (not plaintext)', !empty($profileItemRaw['spouse_data']) && strpos((string)$profileItemRaw['spouse_data'], 'spouse_idcard') === false);
    checkTrue('children_data stored encrypted (not plaintext)', !empty($profileItemRaw['children_data']) && strpos((string)$profileItemRaw['children_data'], 'child_idcard') === false);

    $profileApplyCount = $model->applyEmployeeMasterFields($profileProcessRowId, $compId, 1);
    check('applyEmployeeMasterFields reaches the profile-test employee', $profileApplyCount, 1);

    $profileEmpStmt = $pdo->prepare("SELECT * FROM employees WHERE id = :id");
    $profileEmpStmt->execute([':id' => $profileTestEmployeeId]);
    $profileEmp = $profileEmpStmt->fetch(PDO::FETCH_ASSOC);

    checkTrue('department_id was resolved to a real row', !empty($profileEmp['department_id']));
    $newDeptStmt = $pdo->prepare("SELECT * FROM structure_departments WHERE id = :id");
    $newDeptStmt->execute([':id' => $profileEmp['department_id']]);
    $newDept = $newDeptStmt->fetch(PDO::FETCH_ASSOC);
    check('auto-created department name matches dept_description', $newDept['department_name_th'] ?? null, 'Engineering Test Dept');
    check('auto-created department origami_ref_id matches dept_id', (int)($newDept['origami_ref_id'] ?? 0), $profileDeptId);
    check('auto-created department data_source is sync', $newDept['data_source'] ?? null, 'sync');

    checkTrue('position_id was resolved to a real row', !empty($profileEmp['position_id']));
    $newPosiStmt = $pdo->prepare("SELECT * FROM structure_positions WHERE id = :id");
    $newPosiStmt->execute([':id' => $profileEmp['position_id']]);
    $newPosi = $newPosiStmt->fetch(PDO::FETCH_ASSOC);
    check('auto-created position name matches position_name', $newPosi['position_name_th'] ?? null, 'Senior Engineer Test');
    check('auto-created position origami_ref_id matches posi_id', (int)($newPosi['origami_ref_id'] ?? 0), $profilePosiId);

    // Bank was already auto-created earlier in this script for the same bank_code -- proves
    // resolveOrCreateBankId() resolves to the EXISTING row on a second encounter, not a duplicate.
    check('bank_id resolves to the SAME bank row already created earlier (name/code match, no duplicate)', (int)($profileEmp['bank_id'] ?? 0), (int)$mappedEmpRow['bank_id']);

    check('title normalized and applied', $profileEmp['title'] ?? null, 'mr');
    check('gender normalized and applied', $profileEmp['gender'] ?? null, 'male');
    check('marital_status normalized and applied', $profileEmp['marital_status'] ?? null, 'married');
    check('date_of_birth applied directly', $profileEmp['date_of_birth'] ?? null, '1985-07-04');
    check('nickname_th applied from single-source nickname', $profileEmp['nickname_th'] ?? null, 'Nok');
    check('nickname_en applied from single-source nickname', $profileEmp['nickname_en'] ?? null, 'Nok');
    check('nationality applied directly', $profileEmp['nationality'] ?? null, 'Thai');
    check('religion applied directly', $profileEmp['religion'] ?? null, 'Buddhist');
    check('company_email applied from email (not personal_email)', $profileEmp['company_email'] ?? null, 'worktest@example.com');
    check('personal_email left untouched (not overwritten by email)', $profileEmp['personal_email'] ?? null, $profileTestOriginalEmail);
    check('office_tel applied from emp_tel (not mobile_no)', $profileEmp['office_tel'] ?? null, '0899990000');
    check('mobile_no left untouched (not overwritten by emp_tel)', $profileEmp['mobile_no'] ?? null, '0800000002');
    check('military_status intentionally left unmapped despite a plausible-looking raw value', $profileEmp['military_status'], null);
    check('profile_photo_path intentionally left unmapped (emp_pic is a foreign filesystem path)', $profileEmp['profile_photo_path'], null);
    check('employment_status untouched by pass_pro (no auto business-rule transition)', $profileEmp['employment_status'] ?? null, 'permanent');
    checkTrue('has_spouse/spouse_name/spouse_id_card_no untouched (spouse mapping deliberately deferred)', empty($profileEmp['has_spouse']));

    // Idempotency: re-applying against the SAME dept_id/posi_id must resolve back to the same rows,
    // not create duplicates.
    $profileApplyCount2 = $model->applyEmployeeMasterFields($profileProcessRowId, $compId, 1);
    check('re-applying resolves the same employee again', $profileApplyCount2, 1);
    $deptCountStmt = $pdo->prepare("SELECT COUNT(*) FROM structure_departments WHERE origami_ref_id = :ref AND comp_id = :comp");
    $deptCountStmt->execute([':ref' => $profileDeptId, ':comp' => $compId]);
    check('no duplicate department created on a second pull', (int)$deptCountStmt->fetchColumn(), 1);
    $posiCountStmt = $pdo->prepare("SELECT COUNT(*) FROM structure_positions WHERE origami_ref_id = :ref AND comp_id = :comp");
    $posiCountStmt->execute([':ref' => $profilePosiId, ':comp' => $compId]);
    check('no duplicate position created on a second pull', (int)$posiCountStmt->fetchColumn(), 1);

    // ---------- getProcessDetail() masks the nested PII inside spouse/children ----------
    $profileDetail = $model->getProcessDetail($profileProcessRowId, $compId);
    $detailItem = $profileDetail['items'][0] ?? [];
    checkTrue('getProcessDetail never exposes raw encrypted spouse_data', !array_key_exists('spouse_data', $detailItem));
    checkTrue('getProcessDetail never exposes raw encrypted children_data', !array_key_exists('children_data', $detailItem));
    check('getProcessDetail decrypts spouse name (not PII, shown plainly)', $detailItem['spouse']['spouse_name'] ?? null, 'Malee');
    check('getProcessDetail masks spouse_idcard to the last 4 digits', $detailItem['spouse']['spouse_idcard'] ?? null, 'xxxxxxxxx4445');
    check('getProcessDetail masks spouse_tax to the last 4 digits', $detailItem['spouse']['spouse_tax'] ?? null, 'xxxxxxxxx6665');
    check('getProcessDetail decrypts child name (not PII, shown plainly)', $detailItem['children'][0]['child_name'] ?? null, 'Nong');
    check('getProcessDetail masks child_idcard to the last 4 digits', $detailItem['children'][0]['child_idcard'] ?? null, 'xxxxxxxxx7778');
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
