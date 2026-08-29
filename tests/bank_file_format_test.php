<?php
/**
 * Lightweight verification script for BankFileFormatModel (2026-08-29) -- the company-configurable
 * bank bulk-transfer file layout engine (default/company-override field layers, audit log) plus
 * BankTransferFileReport's rewritten configured-render path. Not PHPUnit -- see
 * tests/statutory_engine_test.php for why. Runs against the real dev DB inside a transaction that
 * is always rolled back. Uses a fresh throwaway company (not comp_id=1) so a real company's own
 * BAY customization, if any exists by the time this runs, can never affect these assertions -- see
 * feedback_dev_db_shared_state_test_fragility.
 * Run with: php tests/bank_file_format_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/BankFileFormatModel.php';
require_once __DIR__ . '/../app/models/PayrollReportDataModel.php';
require_once __DIR__ . '/../app/services/reports/payment/BankTransferFileReport.php';

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
    $userId = 1;
    $BAY_FORMAT_ID = 9; // master_bank_file_formats.id for BAY, seeded with a 4-field DRAFT default

    $insComp = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active')");
    $insComp->execute([':name' => 'BFF Test Co ' . uniqid()]);
    $compId = (int)$pdo->lastInsertId();

    $model = new BankFileFormatModel($pdo);

    /* ---------- listFormats() / defaultFormatId() ---------- */
    echo "=== listFormats() / defaultFormatId() ===\n";
    // 2026-08-29, explicit request: "ตรงที่ธนาคาร ให้เหลือแค่ธนาคารที่บริษัทนั้นเพิ่มแล้วเท่านั้น" -- before
    // this company has ANY bank_accounts row, the picker list must be empty (not all 16 active
    // formats), and only grows once a real bank account matching that format's bank_id exists.
    $formatsBeforeBank = $model->listFormats($compId);
    check('listFormats() is empty before this company has any bank account on file', count($formatsBeforeBank), 0);
    check('defaultFormatId() is null with no bank account on file yet', $model->defaultFormatId($compId), null);

    $insBank = $pdo->prepare("INSERT INTO bank_accounts (comp_id, bank_id, account_no, account_no_hash, key_version, account_name, account_type, currency_code, is_default, status, created_by)
        VALUES (:comp_id, 8, :acct, :hash, :kv, 'Test Payer Account', 'current', 'THB', 1, 'active', :uid)");
    $accountNo = '1112223334';
    $enc = EncryptionService::encrypt($accountNo);
    $insBank->execute([':comp_id' => $compId, ':acct' => $enc['value'], ':hash' => EncryptionService::hash($accountNo), ':kv' => $enc['key_version'], ':uid' => $userId]);
    // bank_id=8 is BAY in master_banks -- defaultFormatId() should now resolve to the matching
    // master_bank_file_formats row (id 9) since that's the company's own default payer bank.
    check('defaultFormatId() resolves to BAY once the company has a BAY bank account on file', $model->defaultFormatId($compId), $BAY_FORMAT_ID);

    $formats = $model->listFormats($compId);
    check('listFormats() now returns exactly 1 format (only BAY, matching the 1 bank account on file)', count($formats), 1);
    $bay = null;
    foreach ($formats as $f) { if ((int)$f['id'] === $BAY_FORMAT_ID) $bay = $f; }
    checkTrue('BAY format is in the active list', $bay !== null);
    check('BAY field_count reflects the seeded system default (4)', $bay['field_count'] ?? null, 4);
    checkFalse('BAY has_own_override is false before this company edits anything', $bay['has_own_override'] ?? true);

    /* ---------- getFormatDetail() default template ---------- */
    echo "=== getFormatDetail() (system default) ===\n";
    $detail = $model->getFormatDetail($compId, $BAY_FORMAT_ID);
    checkTrue('detail found', $detail !== null);
    check('4 detail-row fields in the default template', count($detail['fields']['detail']), 4);
    checkFalse('none of the default fields are company-owned yet', $detail['fields']['detail'][0]['is_company_owned']);
    check('config delimiter_type defaults to delimited (virtual, unsaved)', $detail['config']['delimiter_type'], 'delimited');
    checkFalse('config is_verified defaults to false', $detail['config']['is_verified']);

    /* ---------- saveField() forks the default on first edit ---------- */
    echo "=== saveField() fork-on-first-edit ===\n";
    $saveField1 = $model->saveField($compId, $BAY_FORMAT_ID, [
        'row_type' => 'detail', 'sort_order' => 5, 'field_label_th' => 'ทดสอบ', 'field_label_en' => 'Test Field',
        'source_type' => 'constant', 'constant_value' => 'X',
    ], $userId);
    checkTrue('saveField (new company field) succeeds' . (empty($saveField1['status']) ? " ({$saveField1['message']})" : ''), $saveField1['status']);
    $detailAfterFork = $model->getFormatDetail($compId, $BAY_FORMAT_ID);
    check('company now has 5 of its own detail fields (4 forked + 1 new)', count($detailAfterFork['fields']['detail']), 5);
    checkTrue('every field is now company-owned (forked from default)', $detailAfterFork['fields']['detail'][0]['is_company_owned']);

    $formatsAfterFork = $model->listFormats($compId);
    $bayAfterFork = null;
    foreach ($formatsAfterFork as $f) { if ((int)$f['id'] === $BAY_FORMAT_ID) $bayAfterFork = $f; }
    checkTrue('has_own_override is now true', $bayAfterFork['has_own_override'] ?? false);

    $stillFour = $pdo->query("SELECT COUNT(*) FROM bank_file_format_fields WHERE bank_file_format_id = " . $BAY_FORMAT_ID . " AND comp_id IS NULL")->fetchColumn();
    check('the shared system-default template is untouched (still 4 rows)', (int)$stillFour, 4);

    $badField = $model->saveField($compId, $BAY_FORMAT_ID, [
        'row_type' => 'detail', 'source_type' => 'employee_field', 'source_field' => 'not_a_real_field',
        'field_label_th' => 'x', 'field_label_en' => 'x',
    ], $userId);
    checkFalse('saveField rejects an unknown source_field', $badField['status']);

    /* ---------- deleteField() only ever touches company-owned rows ---------- */
    echo "=== deleteField() ===\n";
    $newFieldId = (int)$saveField1['id'];
    $del = $model->deleteField($compId, $BAY_FORMAT_ID, $newFieldId, $userId);
    checkTrue('deleteField succeeds for a company-owned row', $del['status']);
    $detailAfterDelete = $model->getFormatDetail($compId, $BAY_FORMAT_ID);
    check('back down to 4 company-owned fields after delete', count($detailAfterDelete['fields']['detail']), 4);

    $delMissing = $model->deleteField($compId, $BAY_FORMAT_ID, 999999, $userId);
    checkFalse('deleteField rejects a nonexistent id', $delMissing['status']);

    /* ---------- saveConfig() + is_verified ---------- */
    echo "=== saveConfig() ===\n";
    $saveConfig = $model->saveConfig($compId, $BAY_FORMAT_ID, [
        'delimiter_type' => 'fixed_width', 'line_ending' => 'crlf', 'text_encoding' => 'utf8', 'is_verified' => 1,
    ], $userId);
    checkTrue('saveConfig succeeds', $saveConfig['status']);
    $configAfterSave = $model->getConfig($compId, $BAY_FORMAT_ID);
    check('delimiter_type persisted as fixed_width', $configAfterSave['delimiter_type'], 'fixed_width');
    checkTrue('is_verified persisted as true', $configAfterSave['is_verified']);

    /* ---------- editLogs() ---------- */
    echo "=== editLogs() ===\n";
    $logs = $model->editLogs($compId, $BAY_FORMAT_ID);
    $actions = array_column($logs, 'action');
    checkTrue('log contains a field_saved entry', in_array('field_saved', $actions, true));
    checkTrue('log contains a field_deleted entry', in_array('field_deleted', $actions, true));
    checkTrue('log contains a config_saved entry', in_array('config_saved', $actions, true));

    /* ---------- BankTransferFileReport: configured fixed-width render ---------- */
    echo "=== BankTransferFileReport (configured, fixed-width) ===\n";
    // Adjust the seeded (now company-owned) widths down to something small+predictable for a
    // byte-exact assertion, and drop it to 2 fields (account_no, amount) so the expected line is
    // easy to hand-verify.
    $ownFields = $pdo->query("SELECT id, source_field FROM bank_file_format_fields WHERE bank_file_format_id = " . $BAY_FORMAT_ID . " AND comp_id = {$compId}")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ownFields as $of) {
        if ($of['source_field'] === 'bank_account_no') {
            $model->saveField($compId, $BAY_FORMAT_ID, ['id' => $of['id'], 'row_type' => 'detail', 'sort_order' => 1, 'field_label_th' => 'a', 'field_label_en' => 'a', 'source_type' => 'employee_field', 'source_field' => 'bank_account_no', 'data_type' => 'text', 'width' => 10, 'pad_char' => ' ', 'pad_direction' => 'right'], $userId);
        } elseif ($of['source_field'] === 'net_amount') {
            $model->saveField($compId, $BAY_FORMAT_ID, ['id' => $of['id'], 'row_type' => 'detail', 'sort_order' => 2, 'field_label_th' => 'b', 'field_label_en' => 'b', 'source_type' => 'employee_field', 'source_field' => 'net_amount', 'data_type' => 'number', 'decimal_places' => 2, 'width' => 8, 'pad_char' => '0', 'pad_direction' => 'left'], $userId);
        } else {
            $model->deleteField($compId, $BAY_FORMAT_ID, (int)$of['id'], $userId);
        }
    }

    // Fixture: a minimal approved run with exactly one bank-paid employee (same fixture shape
    // tests/reports_test.php's own BankTransferFileReport fixture already uses, copied verbatim).
    require_once __DIR__ . '/../app/models/PayrollRunModel.php';
    require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
    $cycleModel = new PayrollCycleModel($pdo);
    $cycleSave = $cycleModel->save($compId, [
        'cycle_name' => 'BFF_TEST_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => $BAY_FORMAT_ID, 'status' => 'active',
    ], $userId);
    checkTrue('test cycle create succeeds' . (empty($cycleSave['status']) ? " ({$cycleSave['message']})" : ''), $cycleSave['status']);
    $cycleId = $cycleSave['id'];

    $empAccountNo = '9998887770';
    $empEnc = EncryptionService::encrypt($empAccountNo);
    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         bank_id, bank_account_no, bank_account_name, key_version,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, department_id)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'ไฟล์ธนาคาร', 'Test', 'BankFile', '1990-01-01', 'Thai',
         8, :bank_account_no, 'Test Employee Account', :key_version,
         :email, '0812345678', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0898888888',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', 30000, '2020-01-01', 'average', 'active',
         1, 0, 0, NULL)");
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'BFF_TEST_' . uniqid(),
        ':bank_account_no' => $empEnc['value'], ':key_version' => $empEnc['key_version'],
        ':email' => uniqid() . '@test.local',
    ]);
    $employeeId = (int)$pdo->lastInsertId();

    $today = new DateTime('now');
    $periodStart = (clone $today)->modify('first day of this month')->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of this month')->format('Y-m-d');
    $runModel = new PayrollRunModel($pdo);
    $runCreate = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'BFF_TEST_RUN_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $periodEnd,
    ], $userId, true);
    checkTrue('test run create succeeds' . (empty($runCreate['status']) ? " ({$runCreate['message']})" : ''), $runCreate['status']);
    $runId = $runCreate['id'];
    $recalc = $runModel->recalculate($runId, $compId, $userId, true);
    checkTrue('recalculate succeeds' . (empty($recalc['status']) ? " ({$recalc['message']})" : ''), $recalc['status']);
    $submit = $runModel->submit($runId, $compId, $userId, true);
    checkTrue('run submitted' . (empty($submit['status']) ? " ({$submit['message']})" : ''), $submit['status']);
    $approve = $runModel->approve($runId, $compId, $userId, true);
    checkTrue('run approved for report generation' . (empty($approve['status']) ? " ({$approve['message']})" : ''), $approve['status']);

    // Real net_amount depends on statutory deductions actually calculated for this run (SSO/tax),
    // not a hand-assumed "no deductions" figure -- read it back from what recalculate() actually
    // produced instead of guessing, so this assertion reflects real calculation output.
    $netAmount = (float)$pdo->query("SELECT net_amount FROM payroll_run_details WHERE run_id = {$runId} AND employee_id = {$employeeId}")->fetchColumn();
    checkTrue('fixture net_amount is a sane positive number for the byte-width assertion below', $netAmount > 0 && $netAmount < 999999.99);

    $report = new BankTransferFileReport();
    $result = $report->generate(['comp_id' => $compId, 'run_id' => $runId], 'csv');
    check('configured render mime_type is text/plain (fixed_width)', $result['mime_type'], 'text/plain');
    checkTrue('configured render file_name uses .txt extension', str_ends_with($result['file_name'], '.txt'));
    $expectedAmountField = str_pad(number_format($netAmount, 2, '.', ''), 8, '0', STR_PAD_LEFT);
    $expectedLine = '9998887770' . $expectedAmountField;
    checkTrue("configured fixed-width line matches account_no(10) + amount(8, zero-padded left, real net_amount={$netAmount})", strpos($result['content'], $expectedLine) !== false);

    echo "\n--------------------------------------------------\n";
    echo "Passed: {$passes}, Failed: {$failures}\n";
    if ($failures > 0) {
        echo "SOME TESTS FAILED\n";
    } else {
        echo "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
    }
} catch (Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
exit($failures > 0 ? 1 : 0);
