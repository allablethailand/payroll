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

    $insBank = $pdo->prepare("INSERT INTO bank_accounts (comp_id, bank_id, account_no, account_no_hash, key_version, account_name, company_code, account_type, currency_code, is_default, status, created_by)
        VALUES (:comp_id, 8, :acct, :hash, :kv, 'Test Payer Account', '712', 'current', 'THB', 1, 'active', :uid)");
    $accountNo = '1112223334';
    $enc = EncryptionService::encrypt($accountNo);
    $insBank->execute([':comp_id' => $compId, ':acct' => $enc['value'], ':hash' => EncryptionService::hash($accountNo), ':kv' => $enc['key_version'], ':uid' => $userId]);
    $defaultBankAccountId = (int)$pdo->lastInsertId();
    // bank_id=8 is BAY in master_banks -- defaultFormatId() should now resolve to the matching
    // master_bank_file_formats row (id 9) since that's the company's own default payer bank.
    check('defaultFormatId() resolves to BAY once the company has a BAY bank account on file', $model->defaultFormatId($compId), $BAY_FORMAT_ID);

    $formats = $model->listFormats($compId);
    check('listFormats() now returns exactly 1 format (only BAY, matching the 1 bank account on file)', count($formats), 1);
    $bay = null;
    foreach ($formats as $f) { if ((int)$f['id'] === $BAY_FORMAT_ID) $bay = $f; }
    checkTrue('BAY format is in the active list', $bay !== null);
    // 2026-08-29: 20 fields (12 header + 8 detail) -- was 21 (13 header) until a real bug fix the
    // same day removed a stray 1-byte blank field between total_count and total_amount in the
    // header (explicit report: "ตรง head มันมีช่องว่างก่อนข้อมูลชุดสุดท้าย แต่ไฟล์ต้นฉบับจะต่อกันเลย" -- see
    // migrations/2026-08-29_8_krungsri_header_no_gap_before_total_amount.sql's own header comment).
    check('BAY field_count reflects the real Krungsri layout (12 header + 8 detail = 20)', $bay['field_count'] ?? null, 20);
    checkFalse('BAY has_own_override is false before this company edits anything', $bay['has_own_override'] ?? true);

    /* ---------- getFormatDetail() default template ---------- */
    echo "=== getFormatDetail() (system default) ===\n";
    $detail = $model->getFormatDetail($compId, $BAY_FORMAT_ID);
    checkTrue('detail found', $detail !== null);
    check('12 header-row fields in the default template', count($detail['fields']['header']), 12);
    check('8 detail-row fields in the default template', count($detail['fields']['detail']), 8);
    checkFalse('none of the default fields are company-owned yet', $detail['fields']['detail'][0]['is_company_owned']);
    // 2026-08-29: getConfig()'s virtual default is now inferred from the fields themselves (every
    // BAY field carries a width -> fixed_width; header fields exist -> has_header_row) instead of
    // an unconditional delimited/no-header default that would have made the newly-seeded header
    // row invisible until the company separately remembered to flip 2 checkboxes -- see
    // BankFileFormatModel::getConfig()'s own docblock.
    check('config delimiter_type inferred as fixed_width (every BAY field has a width)', $detail['config']['delimiter_type'], 'fixed_width');
    checkTrue('config has_header_row inferred as true (BAY has header fields)', $detail['config']['has_header_row']);
    checkFalse('config has_trailer_row stays false (BAY has no trailer fields)', $detail['config']['has_trailer_row']);
    checkFalse('config is_verified defaults to false', $detail['config']['is_verified']);

    /* ---------- saveField() forks the default on first edit ---------- */
    echo "=== saveField() fork-on-first-edit ===\n";
    // 2026-08-29: 'width' is now required here -- getConfig()'s virtual default correctly infers
    // fixed_width for BAY (every one of its real fields carries a width, see that method's own
    // docblock), and validateFieldPayload() requires width whenever the format's own config is
    // fixed_width, same rule as before this round -- just actually enforced now that the virtual
    // default properly reflects what BAY's real layout looks like instead of defaulting away from it.
    $saveField1 = $model->saveField($compId, $BAY_FORMAT_ID, [
        'row_type' => 'detail', 'sort_order' => 9, 'field_label_th' => 'ทดสอบ', 'field_label_en' => 'Test Field',
        'source_type' => 'constant', 'constant_value' => 'X', 'width' => 5,
    ], $userId);
    checkTrue('saveField (new company field) succeeds' . (empty($saveField1['status']) ? " ({$saveField1['message']})" : ''), $saveField1['status']);
    $detailAfterFork = $model->getFormatDetail($compId, $BAY_FORMAT_ID);
    check('company now has 9 of its own detail fields (8 forked + 1 new)', count($detailAfterFork['fields']['detail']), 9);
    check('header fields (12) were forked too, untouched by this detail-only edit', count($detailAfterFork['fields']['header']), 12);
    checkTrue('every field is now company-owned (forked from default)', $detailAfterFork['fields']['detail'][0]['is_company_owned']);

    $formatsAfterFork = $model->listFormats($compId);
    $bayAfterFork = null;
    foreach ($formatsAfterFork as $f) { if ((int)$f['id'] === $BAY_FORMAT_ID) $bayAfterFork = $f; }
    checkTrue('has_own_override is now true', $bayAfterFork['has_own_override'] ?? false);

    $stillOriginalCount = $pdo->query("SELECT COUNT(*) FROM bank_file_format_fields WHERE bank_file_format_id = " . $BAY_FORMAT_ID . " AND comp_id IS NULL")->fetchColumn();
    check('the shared system-default template is untouched (still 20 rows)', (int)$stillOriginalCount, 20);

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
    check('back down to 8 company-owned detail fields after delete', count($detailAfterDelete['fields']['detail']), 8);

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

    // 2026-08-29, real bug found and fixed (explicit report: "เลขบัญชีต้องมีขีดหรือไม่ใส่ขีดช่วยปรับให้ด้วย
    // ครับ") -- stored WITH dashes (employees.bank_account_no is free-text, no format validation
    // at all) -- $empAccountNo is the digits-only value the rendered fixed-width field must
    // actually contain (used in every assertion below); $empAccountNoDashed is what's genuinely
    // stored, proving the render path strips dashes rather than just happening to already be clean.
    $empAccountNo = '9998887770';
    $empAccountNoDashed = '999-8-88777-0';
    $empEnc = EncryptionService::encrypt($empAccountNoDashed);
    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         bank_id, bank_account_no, bank_account_name, key_version,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, department_id)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'ไฟล์ธนาคาร', 'Test', 'BankFile', '1990-01-01', 'Thai',
         8, :bank_account_no, 'Test Employee Account', :key_version,
         :email, '0812345678', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0898888888',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active',
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
    // 2026-08-29, real gap fixed while implementing Krungsri's real spec: a fixed-width numeric
    // field is implied-decimal with NO literal '.' (e.g. Krungsri's own 00000008873025 = 88,730.25)
    // -- see BankTransferFileReport::applyDataType()'s own docblock. This expected value used to be
    // computed with a literal decimal point (number_format), which was the very bug that fix
    // corrected -- updated here to match.
    $expectedAmountField = str_pad((string)(int)round($netAmount * 100), 8, '0', STR_PAD_LEFT);
    $expectedLine = '9998887770' . $expectedAmountField;
    checkTrue("configured fixed-width line matches account_no(10) + amount(8, zero-padded left, NO literal decimal point, real net_amount={$netAmount})", strpos($result['content'], $expectedLine) !== false);

    /* ---------- Real bug: editing a DEFAULT field for the very first time ---------- */
    // 2026-08-29, real bug found and fixed (explicit report, screenshot: editing the seeded
    // default "รหัสอ้างอิงงวดจ่าย" field for the first time on a company that had never customized
    // this format yet failed with "Field not found." even though the field was right there in the
    // UI). Root cause: saveField() ran forkDefaultIfNeeded() (which clones the default template
    // into BRAND NEW company-owned rows with fresh auto-increment ids) BEFORE checking whether the
    // incoming `id` belonged to this company -- but that incoming `id` is the DEFAULT template
    // row's own id (the id bffCurrentDetail actually holds before this company has ever forked),
    // which no longer matches anything once the fork has happened. A FRESH company (never touched
    // BAY before) is required to reproduce this -- the earlier sections in this file already
    // forked comp_id's own copy, so this needs its own isolated company to genuinely exercise the
    // "very first edit, id still points at a default row" code path.
    echo "=== saveField() editing a DEFAULT field for the first time (real bug fix) ===\n";
    $insComp2 = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active')");
    $insComp2->execute([':name' => 'BFF Test Co2 ' . uniqid()]);
    $compId2 = (int)$pdo->lastInsertId();
    $insBank3 = $pdo->prepare("INSERT INTO bank_accounts (comp_id, bank_id, account_no, account_no_hash, key_version, account_name, account_type, currency_code, is_default, status, created_by)
        VALUES (:comp_id, 8, :acct, :hash, :kv, 'Co2 Account', 'current', 'THB', 1, 'active', :uid)");
    $acct3 = '2223334445';
    $enc3 = EncryptionService::encrypt($acct3);
    $insBank3->execute([':comp_id' => $compId2, ':acct' => $enc3['value'], ':hash' => EncryptionService::hash($acct3), ':kv' => $enc3['key_version'], ':uid' => $userId]);

    $fieldsBeforeFork = $model->fieldsForRender($compId2, $BAY_FORMAT_ID);
    checkTrue('fixture: comp2 has never forked BAY before this test (fields still comp_id IS NULL)', $fieldsBeforeFork[0]['comp_id'] === null);
    $defaultDetail = $model->getFormatDetail($compId2, $BAY_FORMAT_ID);
    $defaultRefField = null;
    foreach ($defaultDetail['fields']['header'] as $f) {
        if ($f['source_type'] === 'constant' && $f['sort_order'] == 9) { $defaultRefField = $f; }
    }
    checkTrue('found the seeded default "reference prefix" header field (sort_order=9)', $defaultRefField !== null);
    checkFalse('that field is NOT yet company-owned (comp2 never forked)', $defaultRefField['is_company_owned']);
    $defaultFieldId = (int)$defaultRefField['id'];

    // Same payload shape the real Edit modal sends: the field's CURRENT id (still the shared
    // default template's own id at this point) plus the edited constant_value.
    $editDefaultRes = $model->saveField($compId2, $BAY_FORMAT_ID, [
        'id' => $defaultFieldId, 'row_type' => 'header', 'sort_order' => 9,
        'field_label_th' => $defaultRefField['field_label_th'], 'field_label_en' => $defaultRefField['field_label_en'],
        'source_type' => 'constant', 'constant_value' => '001', 'data_type' => 'text', 'width' => 3,
        'pad_char' => ' ', 'pad_direction' => 'right',
    ], $userId);
    checkTrue('saveField() succeeds editing a default field on the very first edit' . (empty($editDefaultRes['status']) ? " ({$editDefaultRes['message']})" : ''), $editDefaultRes['status']);

    $detailAfterFirstEdit = $model->getFormatDetail($compId2, $BAY_FORMAT_ID);
    $editedField = null;
    foreach ($detailAfterFirstEdit['fields']['header'] as $f) {
        if ($f['sort_order'] == 9) { $editedField = $f; }
    }
    checkTrue('field is now company-owned (forked)', $editedField['is_company_owned']);
    check('constant_value was genuinely updated to 001, not left at XXX', $editedField['constant_value'], '001');
    // The shared system-default template itself must be completely untouched by this.
    $stillDefaultUnchanged = $pdo->prepare("SELECT constant_value FROM bank_file_format_fields WHERE id = :id");
    $stillDefaultUnchanged->execute([':id' => $defaultFieldId]);
    check('the shared default template row itself keeps its original placeholder (XXX), never mutated', $stillDefaultUnchanged->fetchColumn(), 'XXX');

    /* ---------- Full real Krungsri header+detail layout, language selection ---------- */
    // 2026-08-29, explicit request: "ปรับ Format นี้ให้เป็น Format มาตรฐานของกรุงศรี และตอน Export ให้เลือก
    // เพิ่มเติมได้ว่าเอาภาษาไทยหรือภาษาอังกฤษ ข้อมูลที่ออกมาจะตามนั้นครับ" -- resetToDefault() restores this
    // company's BAY config back to the real (unmodified) 13-header/8-detail layout the section
    // above forked+stripped down, so this exercises the ACTUAL shipped Krungsri layout end to end,
    // not the deliberately-minimized 2-field version used for the byte-exact assertion above.
    echo "=== Full Krungsri header+detail layout via BankTransferFileReport ===\n";
    $reset = $model->resetToDefault($compId, $BAY_FORMAT_ID, $userId);
    checkTrue('resetToDefault() succeeds' . (empty($reset['status']) ? " ({$reset['message']})" : ''), $reset['status']);

    $resultTh = $report->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'th'], 'csv');
    $linesTh = explode("\r\n", rtrim($resultTh['content'], "\r\n"));
    check('exactly 2 lines rendered (1 header + 1 detail, 1 employee in this fixture)', count($linesTh), 2);
    [$headerLine, $detailLineTh] = $linesTh;
    check('header line is exactly 102 bytes (Krungsri spec width)', strlen($headerLine), 102);
    check('detail line is exactly 80 bytes (Krungsri spec width)', strlen($detailLineTh), 80);
    check('header starts with record type 0000 + 2 blanks', substr($headerLine, 0, 6), '0000  ');
    check('header payment date (DDMMYY) matches the run\'s own payment_date', substr($headerLine, 6, 6), date('dmy', strtotime($periodEnd)));
    // company_account_no (header, bytes 13-22) resolves+decrypts the SAME default bank_accounts row
    // seeded at the top of this file (account_no='1112223334', is_default=1) -- this is the
    // genuinely-new source_field this round added (see BankFileFormatModel::SOURCE_FIELDS' own
    // comment: this had NO source at all before, not even a constant a company could type in).
    check("header company_account_no (bytes 13-22) resolves the company's own default bank account, right-padded to 10", substr($headerLine, 12, 10), '1112223334');
    // 2026-08-29, explicit follow-up: "ในแต่ละรอบการจ่ายอาจใช้เลขแยกกันครับ แยกบัญชีในการจ่าย" -- the
    // "รหัสบริษัท/รหัสบริการ" field (header, bytes 43-45) now resolves company_service_code from
    // bank_accounts.company_code on the SAME resolved account, no longer a hand-typed constant.
    check('header company_service_code (bytes 43-45) resolves the company\'s own bank account.company_code', substr($headerLine, 42, 3), '712');
    check('header payment type constant "A" at byte 73', substr($headerLine, 72, 1), 'A');
    check('header total record count (bytes 81-87) is 0000001 (1 employee)', substr($headerLine, 80, 7), '0000001');
    // 2026-08-29, real bug found and fixed (explicit report: "ตรง head มันมีช่องว่างก่อนข้อมูลชุดสุดท้าย แต่
    // ไฟล์ต้นฉบับจะต่อกันเลย") -- the 1-byte blank gap that used to sit between total_count and
    // total_amount was a transcription slip in the original hand-typed sample, not real; removed,
    // and total_amount widened 14->15 bytes to absorb it (bytes 88-102 now, was 89-102/14 wide).
    $expectedTotalAmountField = str_pad((string)(int)round($netAmount * 100), 15, '0', STR_PAD_LEFT);
    check('header total amount (bytes 88-102, no gap before it, implied decimal, no literal point) matches the real net_amount', substr($headerLine, 87, 15), $expectedTotalAmountField);
    check('detail starts with record type 0000 + 2 blanks', substr($detailLineTh, 0, 6), '0000  ');
    check('detail employee bank account no (bytes 7-16)', substr($detailLineTh, 6, 10), '9998887770');
    $expectedDetailAmountField = str_pad((string)(int)round($netAmount * 100), 11, '0', STR_PAD_LEFT);
    check('detail transfer amount (bytes 37-47, implied decimal, no literal point)', substr($detailLineTh, 36, 11), $expectedDetailAmountField);

    // "ตอน Export ให้เลือกเพิ่มเติมได้ว่าเอาภาษาไทยหรือภาษาอังกฤษ ข้อมูลที่ออกมาจะตามนั้นครับ" -- the employee
    // name field (bytes 17-36 of the detail line) is the one place this fixture's th/en names
    // genuinely differ ('ทดสอบ ไฟล์ธนาคาร' vs 'Test BankFile'), so a th vs en generate() call must
    // produce two DIFFERENT detail lines even though every other field (account no/amount/
    // reference) is identical between the two calls.
    $resultEn = $report->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'en'], 'csv');
    $linesEn = explode("\r\n", rtrim($resultEn['content'], "\r\n"));
    $detailLineEn = $linesEn[1];
    checkTrue('th vs en export produces a DIFFERENT detail line (the name field actually changes)', $detailLineTh !== $detailLineEn);
    // 2026-08-29: getConfig()'s virtual default now infers text_encoding=tis620 for a fixed-width
    // layout (see that method's own docblock -- a real, separate bug this exact assertion caught
    // before this fix: a Thai name was getting mid-character-truncated in a byte-width field sized
    // for TIS-620 while defaulted to utf8/3-bytes-per-char). The rendered line is genuinely
    // TIS-620-encoded bytes now (correct, matches a real bank's own expectation) -- decode back to
    // UTF-8 before comparing against a UTF-8 PHP string literal; ASCII-only en text is byte-
    // identical in both encodings so it never needed this, only the Thai comparison does.
    $nameFieldTh = trim((string)iconv('TIS-620', 'UTF-8', substr($detailLineTh, 16, 20)));
    $nameFieldEn = trim(substr($detailLineEn, 16, 20));
    check('th export name field is the Thai display name', $nameFieldTh, 'ทดสอบ ไฟล์ธนาคาร');
    check('en export name field is the English display name', $nameFieldEn, 'Test BankFile');
    // Every OTHER field (account no, amount, reference) must be byte-identical between the two
    // language calls -- only the name field is language-dependent.
    check('account_no/amount/reference segments are identical regardless of language', [substr($detailLineTh, 6, 10), substr($detailLineTh, 36, 40)], [substr($detailLineEn, 6, 10), substr($detailLineEn, 36, 40)]);

    $invalidLangResult = $report->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'fr'], 'csv');
    $invalidLangDetailLine = explode("\r\n", rtrim($invalidLangResult['content'], "\r\n"))[1];
    check('an invalid language value falls back to th (not a hard error)', trim((string)iconv('TIS-620', 'UTF-8', substr($invalidLangDetailLine, 16, 20))), 'ทดสอบ ไฟล์ธนาคาร');

    /* ---------- Real bug: UTF-8 truncation corrupting a name mid-character ---------- */
    // 2026-08-29, real bug found and fixed (explicit report: "เลือกเป็น UTF-8 แล้วแต่่ยังอ่านไม่ออก" --
    // selected UTF-8 encoding, Thai text still unreadable). Root cause: this fixture's own Thai
    // name 'ทดสอบ ไฟล์ธนาคาร' is ~46 bytes in UTF-8 (3 bytes/char) but only 20 bytes in TIS-620
    // (1 byte/char) -- the 20-byte detail name column truncates it either way, but a byte-based
    // substr() (correct for TIS-620) can slice a multi-byte UTF-8 character IN HALF, producing
    // invalid UTF-8 bytes -- exactly the "ไอ¸—ไอ¸´"-style garbage the report described. Switching
    // this company's own BAY config to text_encoding=utf8 (still fixed_width) reproduces the
    // exact code path that used to corrupt this field.
    echo "=== UTF-8 encoding: truncation must not split a multi-byte character (real bug fix) ===\n";
    // has_header_row must be passed explicitly here (true) -- saveConfig() defaults any OMITTED
    // boolean field to false, which would otherwise silently turn the header row back off (a real
    // mistake caught while writing this exact test, not a product bug -- see the debug trail this
    // assertion block replaced).
    $utf8ConfigRes = $model->saveConfig($compId, $BAY_FORMAT_ID, [
        'delimiter_type' => 'fixed_width', 'line_ending' => 'crlf', 'text_encoding' => 'utf8',
        'has_header_row' => true, 'is_verified' => 1,
    ], $userId);
    checkTrue('saveConfig() to text_encoding=utf8 succeeds' . (empty($utf8ConfigRes['status']) ? " ({$utf8ConfigRes['message']})" : ''), $utf8ConfigRes['status']);
    // 2026-08-29, real follow-up found and fixed (explicit report: "มันมีตรงชื่อที่ติดกันกับตัวเลขในลำดับต่อไป
    // ครับ") -- a fixed-width column that's genuinely NARROWER than the real content (this fixture's
    // own case: 'ทดสอบ ไฟล์ธนาคาร' needs ~46 UTF-8 bytes, the column is only 20) used to truncate
    // SILENTLY (safely, post the mb_strcut() fix above -- no more split characters -- but still a
    // genuinely shortened legal name reaching the bank file with no signal to the admin at all).
    // generate() now detects this BEFORE padByte() ever truncates anything (comparing the real
    // formatted-but-unpadded byte length against the column width) and hard-blocks the whole
    // export with a LocalizedException naming exactly which field/employee overflowed, rather than
    // shipping a file with a chopped name -- same "ห้าม generate ไฟล์เปล่าเงียบๆ" standing convention
    // as the "no valid accounts" check elsewhere in this class.
    $utf8OverflowCaught = false;
    $utf8OverflowMessage = '';
    try {
        $report->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'th'], 'csv');
    } catch (LocalizedException $e) {
        $utf8OverflowCaught = true;
        $utf8OverflowMessage = $e->getMessage();
    }
    checkTrue('generate() throws instead of silently shipping a file with a truncated name', $utf8OverflowCaught);
    $overflowEmployeeNo = (string)$pdo->query("SELECT employee_no FROM employees WHERE id = {$employeeId}")->fetchColumn();
    checkTrue('the exception names the overflowing employee', str_contains($utf8OverflowMessage, $overflowEmployeeNo));
    checkTrue('the exception names the overflowing field', str_contains($utf8OverflowMessage, 'ชื่อ-นามสกุลพนักงาน'));

    // The underlying padByte() character-boundary safety (the ORIGINAL "เลือกเป็น UTF-8 แล้วแต่่ยังอ่าน
    // ไม่ออก" bug fix) is still real, load-bearing behavior -- generate() no longer exercises it for
    // THIS fixture (it's blocked earlier now), but the method itself must still never split a
    // multi-byte character if it's ever reached (e.g. a future caller, or this exact overflow guard
    // being bypassed/removed later). Covered directly via Reflection so this guarantee doesn't
    // silently lose its only test coverage now that generate() itself no longer reaches it.
    $padByteMethod = new ReflectionMethod(BankTransferFileReport::class, 'padByte');
    $padByteMethod->setAccessible(true);
    $longUtf8Name = 'ทดสอบ ไฟล์ธนาคาร'; // same fixture name, ~46 UTF-8 bytes
    $truncated = $padByteMethod->invoke($report, $longUtf8Name, 20, ' ', 'right', true);
    checkTrue('padByte() still pads the truncated result back out to exactly the requested width', strlen($truncated) === 20);
    checkTrue('padByte() truncation is still valid UTF-8 (no character split mid-sequence)', mb_check_encoding(rtrim($truncated), 'UTF-8'));
    checkTrue('padByte() truncated text is still a genuine PREFIX of the real name (no corruption before the cut point)', str_starts_with($longUtf8Name, rtrim($truncated)));

    // Restore to the tis620 + has_header_row=true the rest of this file's own fixtures assume.
    $model->saveConfig($compId, $BAY_FORMAT_ID, [
        'delimiter_type' => 'fixed_width', 'line_ending' => 'crlf', 'text_encoding' => 'tis620',
        'has_header_row' => true, 'is_verified' => 1,
    ], $userId);

    /* ---------- Per-cycle bank account override ---------- */
    // 2026-08-29, explicit follow-up request: "ในแต่ละรอบการจ่ายอาจใช้เลขแยกกันครับ แยกบัญชีในการจ่าย" -- a
    // SECOND bank account for the SAME company, with its own account_no/company_code, pinned to
    // THIS run's cycle via bank_account_id -- the header must now resolve THIS account instead of
    // the company's is_default one.
    echo "=== Per-cycle bank_account_id overrides the company default ===\n";
    // 2026-08-29, real bug found and fixed (explicit report: "เลขบัญชีต้องมีขีดหรือไม่ใส่ขีดช่วยปรับให้
    // ด้วยครับ") -- stored WITH dashes (a valid, allowed display format per BankAccountModel::
    // save()'s own regex, and a real risk for any employee/company account entered this way): the
    // rendered fixed-width field must strip them down to plain digits, matching what a real
    // Krungsri submission expects -- see BankTransferFileReport::digitsOnlyAccountNo()'s own
    // docblock for why a dash left in place would silently truncate real trailing digits instead
    // of just looking cosmetically wrong.
    $insBank2 = $pdo->prepare("INSERT INTO bank_accounts (comp_id, bank_id, account_no, account_no_hash, key_version, account_name, company_code, account_type, currency_code, is_default, status, created_by)
        VALUES (:comp_id, 8, :acct, :hash, :kv, 'Regional Office Account', '999', 'current', 'THB', 0, 'active', :uid)");
    $secondAccountNoDashed = '555-6-66777-8';
    $secondAccountNo = '5556667778'; // the digits-only value the rendered file must actually contain
    $enc2 = EncryptionService::encrypt($secondAccountNoDashed);
    $insBank2->execute([':comp_id' => $compId, ':acct' => $enc2['value'], ':hash' => EncryptionService::hash($secondAccountNoDashed), ':kv' => $enc2['key_version'], ':uid' => $userId]);
    $secondBankAccountId = (int)$pdo->lastInsertId();

    $optionsCheck = $cycleModel->bankAccountOptions($compId, '', 1, 10);
    check('bankAccountOptions() returns both of this company\'s own accounts', count($optionsCheck['items']), 2);
    // 2026-09-02, multi-bank-account payroll -- bank_account_id is no longer settable through
    // save() directly (it's now a denormalized shortcut owned by saveBankAccounts(), see that
    // method's own docblock) -- these 3 assertions updated to call the new method instead of
    // threading bank_account_id through save()'s own $data array.
    $crossCompanyAccountRes = $cycleModel->saveBankAccounts($cycleId, $compId, [
        ['bank_account_id' => 999999999, 'is_default' => 1],
    ], $userId);
    check('saveBankAccounts() rejects a bank_account_id that does not belong to this company', $crossCompanyAccountRes['status'], false);

    $pinAccountRes = $cycleModel->saveBankAccounts($cycleId, $compId, [
        ['bank_account_id' => $secondBankAccountId, 'is_default' => 1],
    ], $userId);
    checkTrue('saveBankAccounts() accepts a real bank_account_id belonging to this company' . (empty($pinAccountRes['status']) ? " ({$pinAccountRes['message']})" : ''), $pinAccountRes['status']);
    $cycleAfterPin = $cycleModel->get($cycleId, $compId);
    check('cycle now carries the pinned bank_account_id (denormalized shortcut)', (int)($cycleAfterPin['bank_account_id'] ?? 0), $secondBankAccountId);
    check('list()/get() surface the pinned account\'s own name+company_code for the settings UI', [$cycleAfterPin['bank_account_name'] ?? null, $cycleAfterPin['bank_account_company_code'] ?? null], ['Regional Office Account', '999']);
    check('getBankAccounts() also reflects the single pinned account as the default', count($cycleAfterPin['bank_accounts'] ?? []), 1);

    $resultPinned = $report->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'th'], 'csv');
    $headerLinePinned = explode("\r\n", rtrim($resultPinned['content'], "\r\n"))[0];
    check("header company_account_no resolves the PINNED account, digits only (dashes stripped from the stored '{$secondAccountNoDashed}')", substr($headerLinePinned, 12, 10), $secondAccountNo);
    check('header company_service_code now resolves the PINNED account\'s own code (999, not the default account\'s 712)', substr($headerLinePinned, 42, 3), '999');

    $unpinRes = $cycleModel->saveBankAccounts($cycleId, $compId, [], $userId);
    checkTrue('saveBankAccounts() with an empty account list clears the pin back to null' . (empty($unpinRes['status']) ? " ({$unpinRes['message']})" : ''), $unpinRes['status']);
    $cycleAfterUnpin = $cycleModel->get($cycleId, $compId);
    check('bank_account_id is null again after unpinning', $cycleAfterUnpin['bank_account_id'], null);
    $resultUnpinned = $report->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'th'], 'csv');
    $headerLineUnpinned = explode("\r\n", rtrim($resultUnpinned['content'], "\r\n"))[0];
    check("header company_account_no falls back to the company's is_default account again after unpinning", substr($headerLineUnpinned, 12, 10), '1112223334');

    /* ---------- Real bug: reference code's MMYY must follow payment_date, not period_start_date ---------- */
    // 2026-08-29, real bug found and fixed (explicit report: "0726 ไม่ใช่ครับต้องเป็น 0826 ตามเดือนที่จ่าย")
    // -- a pay period and its actual disbursement date routinely land in different calendar
    // months (e.g. period ends July 31, paid Aug 5th); the reference code's own MMYY portion (both
    // header and detail) must track WHEN THE MONEY WAS ACTUALLY PAID, same as the header's own
    // separate "Payment Date" field right next to it -- not the period's start date. This
    // fixture's own run has payment_date == period_end_date (same month, see this file's own
    // period/payment setup above), which would NOT distinguish the bug -- temporarily moves
    // payment_date one calendar month later (still rolled back by this whole file's own enclosing
    // transaction) to genuinely exercise the fix.
    echo "=== Reference code MMYY follows payment_date, not period_start_date (real bug fix) ===\n";
    $shiftedPaymentDate = (new DateTime($periodEnd))->modify('+1 month')->format('Y-m-d');
    $pdo->prepare("UPDATE `payroll_runs` SET payment_date = :pd WHERE id = :id")->execute([':pd' => $shiftedPaymentDate, ':id' => $runId]);
    $resultShiftedPayment = $report->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'th'], 'csv');
    $linesShiftedPayment = explode("\r\n", rtrim($resultShiftedPayment['content'], "\r\n"));
    $expectedShiftedMy = date('my', strtotime($shiftedPaymentDate));
    check('header reference code MMYY (bytes 77-80) matches the SHIFTED payment_date, not period_start_date', substr($linesShiftedPayment[0], 76, 4), $expectedShiftedMy);
    check('header Payment Date field (DDMMYY, bytes 7-12) also reflects the shifted payment_date', substr($linesShiftedPayment[0], 6, 6), date('dmy', strtotime($shiftedPaymentDate)));
    check('detail reference code MMYY (last 4 bytes of the row) matches the SAME shifted payment_date', substr($linesShiftedPayment[1], -4), $expectedShiftedMy);
    // Restore payment_date so nothing downstream in this shared-fixture file is affected.
    $pdo->prepare("UPDATE `payroll_runs` SET payment_date = :pd WHERE id = :id")->execute([':pd' => $periodEnd, ':id' => $runId]);

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
