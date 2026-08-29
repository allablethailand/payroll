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
    // 2026-08-29: 21 fields (13 header + 8 detail), replacing the earlier honestly-guessed 4-field
    // detail-only draft with the real Krungsri layout the user supplied directly (see
    // database/migrations/2026-08-29_4_krungsri_bank_transfer_real_layout.sql's own header comment
    // for exactly how every width was derived, not guessed).
    check('BAY field_count reflects the real Krungsri layout (13 header + 8 detail = 21)', $bay['field_count'] ?? null, 21);
    checkFalse('BAY has_own_override is false before this company edits anything', $bay['has_own_override'] ?? true);

    /* ---------- getFormatDetail() default template ---------- */
    echo "=== getFormatDetail() (system default) ===\n";
    $detail = $model->getFormatDetail($compId, $BAY_FORMAT_ID);
    checkTrue('detail found', $detail !== null);
    check('13 header-row fields in the default template', count($detail['fields']['header']), 13);
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
    check('header fields (13) were forked too, untouched by this detail-only edit', count($detailAfterFork['fields']['header']), 13);
    checkTrue('every field is now company-owned (forked from default)', $detailAfterFork['fields']['detail'][0]['is_company_owned']);

    $formatsAfterFork = $model->listFormats($compId);
    $bayAfterFork = null;
    foreach ($formatsAfterFork as $f) { if ((int)$f['id'] === $BAY_FORMAT_ID) $bayAfterFork = $f; }
    checkTrue('has_own_override is now true', $bayAfterFork['has_own_override'] ?? false);

    $stillOriginalCount = $pdo->query("SELECT COUNT(*) FROM bank_file_format_fields WHERE bank_file_format_id = " . $BAY_FORMAT_ID . " AND comp_id IS NULL")->fetchColumn();
    check('the shared system-default template is untouched (still 21 rows)', (int)$stillOriginalCount, 21);

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
    // 2026-08-29, real gap fixed while implementing Krungsri's real spec: a fixed-width numeric
    // field is implied-decimal with NO literal '.' (e.g. Krungsri's own 00000008873025 = 88,730.25)
    // -- see BankTransferFileReport::applyDataType()'s own docblock. This expected value used to be
    // computed with a literal decimal point (number_format), which was the very bug that fix
    // corrected -- updated here to match.
    $expectedAmountField = str_pad((string)(int)round($netAmount * 100), 8, '0', STR_PAD_LEFT);
    $expectedLine = '9998887770' . $expectedAmountField;
    checkTrue("configured fixed-width line matches account_no(10) + amount(8, zero-padded left, NO literal decimal point, real net_amount={$netAmount})", strpos($result['content'], $expectedLine) !== false);

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
    $expectedTotalAmountField = str_pad((string)(int)round($netAmount * 100), 14, '0', STR_PAD_LEFT);
    check('header total amount (bytes 89-102, implied decimal, no literal point) matches the real net_amount', substr($headerLine, 88, 14), $expectedTotalAmountField);
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

    /* ---------- Per-cycle bank account override ---------- */
    // 2026-08-29, explicit follow-up request: "ในแต่ละรอบการจ่ายอาจใช้เลขแยกกันครับ แยกบัญชีในการจ่าย" -- a
    // SECOND bank account for the SAME company, with its own account_no/company_code, pinned to
    // THIS run's cycle via bank_account_id -- the header must now resolve THIS account instead of
    // the company's is_default one.
    echo "=== Per-cycle bank_account_id overrides the company default ===\n";
    $insBank2 = $pdo->prepare("INSERT INTO bank_accounts (comp_id, bank_id, account_no, account_no_hash, key_version, account_name, company_code, account_type, currency_code, is_default, status, created_by)
        VALUES (:comp_id, 8, :acct, :hash, :kv, 'Regional Office Account', '999', 'current', 'THB', 0, 'active', :uid)");
    $secondAccountNo = '5556667778';
    $enc2 = EncryptionService::encrypt($secondAccountNo);
    $insBank2->execute([':comp_id' => $compId, ':acct' => $enc2['value'], ':hash' => EncryptionService::hash($secondAccountNo), ':kv' => $enc2['key_version'], ':uid' => $userId]);
    $secondBankAccountId = (int)$pdo->lastInsertId();

    $optionsCheck = $cycleModel->bankAccountOptions($compId, '', 1, 10);
    check('bankAccountOptions() returns both of this company\'s own accounts', count($optionsCheck['items']), 2);
    $crossCompanyAccountRes = $cycleModel->save($compId, [
        'id' => $cycleId, 'cycle_name' => 'BFF_TEST_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => $BAY_FORMAT_ID, 'bank_account_id' => 999999999, 'status' => 'active',
    ], $userId);
    check('save() rejects a bank_account_id that does not belong to this company', $crossCompanyAccountRes['status'], false);

    $pinAccountRes = $cycleModel->save($compId, [
        'id' => $cycleId, 'cycle_name' => 'BFF_TEST_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => $BAY_FORMAT_ID, 'bank_account_id' => $secondBankAccountId, 'status' => 'active',
    ], $userId);
    checkTrue('save() accepts a real bank_account_id belonging to this company' . (empty($pinAccountRes['status']) ? " ({$pinAccountRes['message']})" : ''), $pinAccountRes['status']);
    $cycleAfterPin = $cycleModel->get($cycleId, $compId);
    check('cycle now carries the pinned bank_account_id', (int)($cycleAfterPin['bank_account_id'] ?? 0), $secondBankAccountId);
    check('list()/get() surface the pinned account\'s own name+company_code for the settings UI', [$cycleAfterPin['bank_account_name'] ?? null, $cycleAfterPin['bank_account_company_code'] ?? null], ['Regional Office Account', '999']);

    $resultPinned = $report->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'th'], 'csv');
    $headerLinePinned = explode("\r\n", rtrim($resultPinned['content'], "\r\n"))[0];
    check("header company_account_no now resolves the PINNED account (not the company's is_default one)", substr($headerLinePinned, 12, 10), $secondAccountNo);
    check('header company_service_code now resolves the PINNED account\'s own code (999, not the default account\'s 712)', substr($headerLinePinned, 42, 3), '999');

    $unpinRes = $cycleModel->save($compId, [
        'id' => $cycleId, 'cycle_name' => 'BFF_TEST_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => $BAY_FORMAT_ID, 'status' => 'active',
    ], $userId);
    checkTrue('save() with no bank_account_id at all clears the pin back to null' . (empty($unpinRes['status']) ? " ({$unpinRes['message']})" : ''), $unpinRes['status']);
    $cycleAfterUnpin = $cycleModel->get($cycleId, $compId);
    check('bank_account_id is null again after unpinning', $cycleAfterUnpin['bank_account_id'], null);
    $resultUnpinned = $report->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'th'], 'csv');
    $headerLineUnpinned = explode("\r\n", rtrim($resultUnpinned['content'], "\r\n"))[0];
    check("header company_account_no falls back to the company's is_default account again after unpinning", substr($headerLineUnpinned, 12, 10), '1112223334');

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
