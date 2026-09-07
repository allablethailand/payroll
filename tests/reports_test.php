<?php
/**
 * Lightweight verification script for the Reports module backend (registry + 3 representative
 * generators: statutory/payment/internal). Not PHPUnit — see tests/statutory_engine_test.php
 * for why. Runs against the real dev DB inside a transaction that is always rolled back.
 * Run with: php tests/reports_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/models/ReportExportLogModel.php';
require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';
require_once __DIR__ . '/../app/models/EmployeeEarningDeductionModel.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/services/reports/ReportRegistry.php';
require_once __DIR__ . '/../app/models/PayslipTemplateModel.php';
require_once __DIR__ . '/../app/models/CompanyStatutorySettingModel.php';
require_once __DIR__ . '/../app/core/Controller.php';
require_once __DIR__ . '/../app/controllers/ReportsController.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    $ok = (is_float($expected) || is_float($actual)) ? abs((float)$actual - (float)$expected) < 0.005 : $actual === $expected;
    if ($ok) {
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
    $compId = 1;
    $adminUserId = 1;

    // Same isolation as tests/payroll_run_test.php: recalculate() now pulls incomplete-profile
    // employees into the calculation table instead of excluding them (see
    // PayrollRunModel::recalculate(), 2026-08-19), so leftover placeholder employees anyone has
    // ever created against this real, shared dev-DB company (id 1) -- e.g. via interactive manual
    // testing of the Pending Pull screen -- now legitimately show up in every run this test
    // creates and block submit()/approve() through no fault of this test's own fixture. Broadened
    // from is_payroll_ready=0-only to every employee at comp_id=1 (2026-08-19, found while adding
    // independent-tab-save support to EmployeeModel::save(): a real leftover row, is_payroll_ready=1
    // from back when that column was hardcoded true on every successful save, still matched every
    // run's period and inflated employee_count/corrupted the single-employee txt-export assertions
    // below) since this test creates its own complete fixture set from scratch regardless. Soft-
    // delete them for this run only, entirely inside this script's own transaction (rolled back at
    // the very end), so nothing here is a real/permanent change.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL")
        ->execute([':comp_id' => $compId]);

    // Same isolation, same reason (2026-08-24): this real dev-DB company has a real, live
    // PAYROLL_RUN_APPROVAL Approval Workflow configured (named approver, not a test fixture) --
    // PayrollRunModel::approve()/reject()/requestInfo()/revert() now route through that REAL
    // engine whenever one is active, admin included (see canApproveThisRun()'s own docblock,
    // 2026-08-24 fix -- admin no longer bypasses a configured workflow). This test isn't testing
    // the Approval Workflow engine itself (see tests/approval_workflow_test.php for that) -- it
    // just needs runs to reach 'approved' quickly via the flat admin-bypass fallback, so
    // temporarily deactivate whatever's live, entirely inside this script's own rolled-back
    // transaction (restored the instant it rolls back, same as the employees soft-delete above).
    $pdo->prepare("UPDATE `approval_workflows` SET status = 'inactive'
        WHERE comp_id = :comp_id AND status = 'active'
          AND id IN (SELECT workflow_id FROM `approval_workflow_document_types` WHERE document_type_code = 'PAYROLL_RUN_APPROVAL')")
        ->execute([':comp_id' => $compId]);

    // ---------- Fixtures ----------
    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of this month')->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of this month')->format('Y-m-d');
    $periodYearAd = (int)date('Y', strtotime($periodStart));
    $periodYearBe = $periodYearAd + 543;

    $cycleModel = new PayrollCycleModel();
    $cycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'REPORT_TEST_CYCLE_' . uniqid(),
        'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25,
        'payment_day_of_month' => 5,
        'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1,
        'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: cycle created', $cycleRes['status']);
    $cycleId = $cycleRes['id'];

    $taxId = '1234567890123';
    $ssoNo = '9876543210987';
    // 2026-08-29, explicit follow-up: Sso110Report now reports the employee's national ID card
    // number (id_card_no) as SSO's own "insured person ID", not sso_no -- see that class's own
    // docblock (matches the real sample the user supplied, which explicitly labels this field
    // "เลขประจำตัวประชาชน 13 หลัก", the national ID, not a separate SSO-specific number).
    $idCardNo = '1012990570210';
    $bankAccountNo = '1112223334';
    $encTax = EncryptionService::encrypt($taxId);
    $encSso = EncryptionService::encrypt($ssoNo);
    $encIdCard = EncryptionService::encrypt($idCardNo);
    $encBank = EncryptionService::encrypt($bankAccountNo);
    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         tax_id_no, sso_no, id_card_no, bank_id, bank_account_no, bank_account_name, key_version,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, department_id)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
         :tax_id_no, :sso_no, :id_card_no, 1, :bank_account_no, :bank_account_name, :key_version,
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', :base_salary, '2020-01-01', 'average', 'active',
         1, 1, 0, NULL)");
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'RPT_TEST_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'รายงาน', ':name_en' => 'Test', ':surname_en' => 'Report',
        ':sso_no' => $encSso['value'], ':id_card_no' => $encIdCard['value'], ':bank_account_no' => $encBank['value'], ':bank_account_name' => 'ทดสอบ รายงาน',
        ':tax_id_no' => $encTax['value'], ':key_version' => $encTax['key_version'],
        ':email' => uniqid() . '@test.local', ':base_salary' => 30000,
    ]);
    $employeeId = (int)$pdo->lastInsertId();

    // A second employee who resigned LAST month (deliberately outside the main test run's
    // period, so they don't get pulled into that run's payroll — see the eligibility-by-date
    // logic in PayrollRunModel::recalculate). Only used for the สปส.6-09 report, which reads
    // straight from employees.employment_end_date and isn't tied to any specific run.
    $resignedSsoNo = '1112223334445';
    $encResignedSso = EncryptionService::encrypt($resignedSsoNo);
    // 2026-09-05, Phase 12 T071: id_card_no added -- Sso609Exporter's new 'txt' format needs it
    // (the spec's own field 2 is explicitly "เลขประจำตัวประชาชน", the national ID, not sso_no).
    $resignedIdCardNo = '2100300400556';
    $encResignedIdCard = EncryptionService::encrypt($resignedIdCardNo);
    $insResigned = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         sso_no, id_card_no, key_version,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_end_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, department_id)
        VALUES (:comp_id, :employee_no, 'ms', 'female', 'ทดสอบ', 'ลาออก', 'Test', 'Resigned', '1990-01-01', 'Thai',
         :sso_no, :id_card_no, :key_version,
         :email, '0811111111', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2019-01-01', :employment_end_date, 'resigned', 'full_time', 'office', 'manual',
         'monthly', 28000, '2019-01-01', 'average', 'active',
         1, 0, 0, NULL)");
    $insResigned->execute([
        ':comp_id' => $compId, ':employee_no' => 'RPT_RESIGNED_' . uniqid(),
        ':sso_no' => $encResignedSso['value'], ':id_card_no' => $encResignedIdCard['value'], ':key_version' => $encResignedSso['key_version'],
        ':email' => uniqid() . '@test.local',
        ':employment_end_date' => (clone $today)->modify('first day of last month')->modify('+5 days')->format('Y-m-d'),
    ]);
    $resignedEmployeeId = (int)$pdo->lastInsertId();

    // A กยศ. (Student Loan Fund) deduction type + an active installment assignment for the main
    // test employee — feeds StudentLoanReport via the statutory_report_code = 'TH_SLF' tag on
    // payroll_earning_deduction_types. Must be created before the run is calculated so its first
    // pending installment gets picked up by PayrollRunModel::recalculate().
    $pedTypeModel = new PayrollEarningDeductionTypeModel();
    $slfTypeRes = $pedTypeModel->save($compId, [
        'item_code' => 'SLF_' . substr(uniqid(), -8),
        'item_name_th' => 'หักเงินกยศ.ทดสอบ',
        'item_name_en' => 'Test SLF Deduction',
        'item_type' => 'deduction',
        'calculation_method' => 'manual_entry',
        'tax_deduction_impact' => 'after_tax',
        'statutory_report_code' => 'TH_SLF',
        'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: SLF deduction type created', $slfTypeRes['status']);
    $slfTypeId = $slfTypeRes['id'];

    $slfContractNo = 'SLF-CONTRACT-' . substr(uniqid(), -6);
    $eedModel = new EmployeeEarningDeductionModel();
    $slfAssignRes = $eedModel->save($employeeId, $compId, [
        'ped_type_id' => $slfTypeId,
        'total_installments' => 12,
        'amount_mode' => 'even_split',
        'total_amount' => 12000,
        'effective_date' => (clone $today)->modify('first day of this month')->format('Y-m-d'),
        'external_reference_no' => $slfContractNo,
    ], $adminUserId);
    checkTrue('fixture: SLF assignment created', $slfAssignRes['status']);

    // ---------- Build an approved run with real calculated data ----------
    $runModel = new PayrollRunModel($pdo);
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'REPORT_TEST_RUN_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $periodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: run created', $createRes['status']);
    $runId = $createRes['id'];

    $calcRes = $runModel->recalculate($runId, $compId, $adminUserId, true);
    checkTrue('fixture: run calculated', $calcRes['status']);
    check('fixture: employee_count is 1', $calcRes['employee_count'], 1);

    $submitRes = $runModel->submit($runId, $compId, $adminUserId, true);
    checkTrue('fixture: run submitted', $submitRes['status']);
    $approveRes = $runModel->approve($runId, $compId, $adminUserId, true);
    checkTrue('fixture: run approved', $approveRes['status']);

    // A second run, left in draft, to test the Approved/Paid/Locked state gate.
    $draftRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'REPORT_TEST_DRAFT_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of next month')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of next month')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of next month')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('fixture: draft run created', $draftRunRes['status']);
    $draftRunId = $draftRunRes['id'];
    $draftCalcRes = $runModel->recalculate($draftRunId, $compId, $adminUserId, true);
    checkTrue('fixture: draft run calculated (stays in draft state)', $draftCalcRes['status']);

    // ---------- Registry ----------
    echo "=== Registry ===\n";
    $all = ReportRegistry::all();
    checkTrue('registry has at least 3 reports', count($all) >= 3);
    check('statutory type has 6 reports', count(ReportRegistry::byType('statutory')), 6);
    // 2026-08-31: +1 for CASH_PAYMENT_SUMMARY (see tests/cash_payment_test.php for its own dedicated coverage).
    // 2026-09-02: +1 for THIRD_PARTY_REMITTANCE_SUMMARY (see tests/payroll_remittance_test.php for its own dedicated coverage).
    // 2026-09-02: +1 for BANK_ACCOUNT_PAYMENT_SUMMARY (see tests/payroll_run_employee_bank_account_test.php for its own dedicated coverage).
    check('payment type has 6 reports', count(ReportRegistry::byType('payment')), 6);
    // 2026-08-31: 3 now -- PayrollRunListSummaryReport (PAYROLL_RUN_LIST_SUMMARY) and
    // ScheduledItemOccurrenceReconciliationReport (SCHEDULED_ITEM_OCCURRENCE_RECONCILIATION, own
    // dedicated coverage in tests/payroll_sync_item_occurrences_test.php) both added alongside the
    // original PayrollRegisterReport.
    check('internal type has 3 reports', count(ReportRegistry::byType('internal')), 3);
    check('unknown code returns null', ReportRegistry::get('NOPE'), null);

    // ---------- Payroll Register (internal, Excel, any state) ----------
    echo "=== PayrollRegisterReport (internal, Excel) ===\n";
    $registerReport = ReportRegistry::get('PAYROLL_REGISTER');
    $registerResult = $registerReport->generate(['comp_id' => $compId, 'run_id' => $runId], 'excel');
    checkTrue('excel content is non-empty', strlen($registerResult['content']) > 0);
    check('mime type is xlsx', $registerResult['mime_type'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    // 2026-08-31, same-day follow-up (explicit request: head/footer/2nd Summary sheet) --
    // PayrollRegisterReport rebuilt into a real 2-sheet workbook (custom PhpSpreadsheet code, not
    // the shared ExcelRendererTrait's flat single-sheet shape); these assertions moved from
    // A1/A2 (the old flat layout) to the new header-block/column-header-row/footer-row positions.
    $tmpXlsx = sys_get_temp_dir() . '/reports_test_' . uniqid() . '.xlsx';
    file_put_contents($tmpXlsx, $registerResult['content']);
    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $workbook = $reader->load($tmpXlsx);
    check('2 sheets: Detail + Summary', $workbook->getSheetCount(), 2);
    check('sheet 1 is named รายละเอียด', $workbook->getSheet(0)->getTitle(), 'รายละเอียด');
    check('sheet 2 is named สรุป', $workbook->getSheet(1)->getTitle(), 'สรุป');

    $sheet = $workbook->getActiveSheet();
    checkTrue('A1 is the header block (company name), not a column header', $sheet->getCell('A1')->getValue() !== 'รหัสพนักงาน');
    check('column header row (row 5) A5 is the employee code column', $sheet->getCell('A5')->getValue(), 'รหัสพนักงาน');
    check('data row 6 has the test employee number', strpos((string)$sheet->getCell('A6')->getValue(), 'RPT_TEST_') === 0, true);

    // Footer totals row: exactly 1 employee row in this fixture, so the footer's own "รวม" total
    // for เงินเดือนฐาน (column D, the first numeric column) must equal that single row's own value --
    // proves the footer is a REAL sum, not a placeholder/copy.
    $footerRowIdx = 7; // header at row 5, 1 data row at row 6, footer at row 7
    check('footer row label is รวม', $sheet->getCell("A{$footerRowIdx}")->getValue(), 'รวม');
    $baseSalaryDataCell = (float)$sheet->getCell('D6')->getValue();
    $baseSalaryFooterCell = (float)$sheet->getCell("D{$footerRowIdx}")->getValue();
    checkTrue('footer base-salary total equals the single fixture row\'s own value (real sum, not a placeholder)', abs($baseSalaryDataCell - $baseSalaryFooterCell) < 0.01 && $baseSalaryDataCell > 0);

    // Summary sheet: run-level recap, sourced from the SAME totals as the footer above.
    $summarySheet = $workbook->getSheet(1);
    checkTrue('summary sheet has a real info block (run name in B4)', (string)$summarySheet->getCell('B4')->getValue() !== '');
    checkTrue('summary sheet employee count row matches the fixture (1)', (string)$summarySheet->getCell('B9')->getValue() === '1');
    // Grand total on the summary sheet must match the SAME base-salary total the Detail sheet's own
    // footer computed -- proves both sheets read from one shared totals array, not two independent
    // (and possibly drifting) calculations.
    $summaryValues = [];
    for ($row = 12; $row <= 30; $row++) {
        $label = (string)$summarySheet->getCell("A{$row}")->getValue();
        if ($label !== '') { $summaryValues[$label] = (float)$summarySheet->getCell("B{$row}")->getValue(); }
    }
    checkTrue('summary sheet lists "เงินเดือนฐานรวม"', array_key_exists('เงินเดือนฐานรวม', $summaryValues));
    checkTrue('its value matches the Detail sheet\'s own footer total for the same figure', abs(($summaryValues['เงินเดือนฐานรวม'] ?? -1) - $baseSalaryFooterCell) < 0.01);
    checkTrue('summary sheet lists "ยอดจ่ายสุทธิรวม" (grand total net)', array_key_exists('ยอดจ่ายสุทธิรวม', $summaryValues));
    unlink($tmpXlsx);

    // Draft run is allowed for the internal report (no state gate).
    $draftRegisterResult = $registerReport->generate(['comp_id' => $compId, 'run_id' => $draftRunId], 'excel');
    checkTrue('internal report allowed on a draft run', strlen($draftRegisterResult['content']) > 0);

    // ---------- Payroll Register: PDF format + bilingual (2026-09-02, explicit request: "เพิ่มให้
    // Export เป็น PDF ได้ด้วย และรองรับ 2 ภาษาเหมือน Report ส่วนอื่น") ----------
    echo "=== PayrollRegisterReport (internal, PDF, bilingual) ===\n";
    checkTrue('pdf added to supportedFormats()', in_array('pdf', $registerReport->supportedFormats(), true));
    $registerPdfTh = $registerReport->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'th'], 'pdf');
    checkTrue('th pdf content is non-empty', strlen($registerPdfTh['content']) > 0);
    check('pdf mime type', $registerPdfTh['mime_type'], 'application/pdf');
    check('pdf content starts with the real PDF signature (%PDF), not an error page', substr($registerPdfTh['content'], 0, 4), '%PDF');
    check('pdf file_name uses .pdf extension', $registerPdfTh['file_name'], "PayrollRegister_Run{$runId}.pdf");

    $registerPdfEn = $registerReport->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'en'], 'pdf');
    checkTrue('en pdf content is also non-empty', strlen($registerPdfEn['content']) > 0);
    checkTrue('th and en PDFs are genuinely different byte content (real bilingual output, not the same file twice)', $registerPdfTh['content'] !== $registerPdfEn['content']);

    // Absent language defaults to 'th' -- every pre-existing caller (List/Detail page buttons before
    // this round) never passes one, so this must keep behaving exactly like the th-explicit case.
    $registerPdfDefault = $registerReport->generate(['comp_id' => $compId, 'run_id' => $runId], 'pdf');
    checkTrue('absent language defaults to th (same length as explicit th -- deterministic dompdf output for identical input)', strlen($registerPdfDefault['content']) === strlen($registerPdfTh['content']));

    // An invalid/unrecognized language value must fall back to th (never crash, never silently
    // produce garbled/empty output) -- same defensive default() the ternary comment in generate()
    // itself documents guarding against.
    $registerPdfBadLang = $registerReport->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'fr'], 'pdf');
    checkTrue('unrecognized language falls back to th, does not throw', strlen($registerPdfBadLang['content']) === strlen($registerPdfTh['content']));

    // Excel output is ALSO bilingual now (same $context['language'] the PDF branch reads) -- en
    // headers should differ from the th ones already asserted above (รหัสพนักงาน at A5).
    $registerExcelEn = $registerReport->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'en'], 'excel');
    $tmpXlsxEn = sys_get_temp_dir() . '/reports_test_en_' . uniqid() . '.xlsx';
    file_put_contents($tmpXlsxEn, $registerExcelEn['content']);
    $readerEn = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $workbookEn = $readerEn->load($tmpXlsxEn);
    check('en excel sheet 1 is named Detail', $workbookEn->getSheet(0)->getTitle(), 'Detail');
    check('en excel sheet 2 is named Summary', $workbookEn->getSheet(1)->getTitle(), 'Summary');
    check('en excel column header row A5 is the English employee-no label', $workbookEn->getActiveSheet()->getCell('A5')->getValue(), 'Employee No.');
    unlink($tmpXlsxEn);
    // The default (no language passed) excel path must stay byte-identical in SHAPE to before this
    // round -- re-assert the exact same th labels the pre-existing test above already locked in,
    // proving the bilingual refactor didn't change the default output at all.
    check('default (th) excel still has รหัสพนักงาน at A5 (unchanged from before this round)', $sheet->getCell('A5')->getValue(), 'รหัสพนักงาน');

    echo "=== PayrollRegisterReport validation ===\n";
    $invalidRunId = false;
    try {
        $registerReport->generate(['comp_id' => $compId, 'run_id' => 'not-a-number'], 'excel');
    } catch (LocalizedException $e) {
        $invalidRunId = ($e->getErrorKey() === 'run_id_required');
    }
    checkTrue('non-numeric run_id rejected', $invalidRunId);

    $neverCalculatedRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'REPORT_TEST_EMPTY_' . uniqid(),
        'period_start_date' => (clone $today)->modify('first day of +2 month')->format('Y-m-d'),
        'period_end_date' => (clone $today)->modify('last day of +2 month')->format('Y-m-d'),
        'payment_date' => (clone $today)->modify('last day of +2 month')->format('Y-m-d'),
    ], $adminUserId, true);
    checkTrue('fixture: never-calculated run created', $neverCalculatedRunRes['status']);
    $noDataError = false;
    try {
        $registerReport->generate(['comp_id' => $compId, 'run_id' => $neverCalculatedRunRes['id']], 'excel');
    } catch (RuntimeException $e) {
        $noDataError = true;
    }
    checkTrue('run with no calculated employees rejected with a clear error', $noDataError);

    // ---------- Pay Slip (payment, PDF, requires Approved+) ----------
    echo "=== PaySlipReport (payment, PDF) ===\n";
    $paySlipReport = ReportRegistry::get('PAY_SLIP');
    $slipResult = $paySlipReport->generate(['comp_id' => $compId, 'run_id' => $runId, 'employee_id' => $employeeId], 'pdf');
    checkTrue('PDF content starts with %PDF header', str_starts_with($slipResult['content'], '%PDF'));
    check('mime type is pdf', $slipResult['mime_type'], 'application/pdf');

    // ---------- Backlog Phase 11, T061: DocumentNumberingModel wired to PAYSLIP ----------
    echo "=== PaySlipReport: DocumentNumberingModel wiring (T061) ===\n";
    $stmtPsNo = $pdo->prepare("SELECT payslip_number FROM payroll_run_details WHERE run_id = :run_id AND employee_id = :employee_id");
    $stmtPsNo->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
    $psNumberAfterFirstGenerate = $stmtPsNo->fetchColumn();
    checkTrue('a payslip_number was stamped after the first generate() call', !empty($psNumberAfterFirstGenerate));
    // Re-generate (simulates re-downloading the same real payslip) -- must reuse the SAME number,
    // never assign a new one on every render.
    $slipResultSecond = $paySlipReport->generate(['comp_id' => $compId, 'run_id' => $runId, 'employee_id' => $employeeId], 'pdf');
    $stmtPsNo->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
    check('re-generating the SAME payslip reuses the identical payslip_number (idempotent, not re-assigned)', $stmtPsNo->fetchColumn(), $psNumberAfterFirstGenerate);

    // ---------- Backlog Phase 12, T073: generate-once-persist-reuse (Option C) for the PDF itself ----------
    echo "=== PaySlipReport: PDF cache (T073, Option C) ===\n";
    $stmtPdfPath = $pdo->prepare("SELECT payslip_pdf_path FROM payroll_run_details WHERE run_id = :run_id AND employee_id = :employee_id");
    $stmtPdfPath->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
    $pdfPathAfterFirstGenerate = $stmtPdfPath->fetchColumn();
    checkTrue('a payslip_pdf_path was persisted after the first generate() call', !empty($pdfPathAfterFirstGenerate));
    checkTrue('the persisted file genuinely exists on disk', is_file(__DIR__ . '/../' . $pdfPathAfterFirstGenerate));
    check('re-generating the SAME payslip returns byte-identical content (served from cache, not re-rendered)', $slipResultSecond['content'], $slipResult['content']);
    $stmtPdfPath->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
    check('re-generating the SAME payslip did not create/point to a different cached file', $stmtPdfPath->fetchColumn(), $pdfPathAfterFirstGenerate);

    $blockedByState = false;
    try {
        $paySlipReport->generate(['comp_id' => $compId, 'run_id' => $draftRunId, 'employee_id' => $employeeId], 'pdf');
    } catch (RuntimeException $e) {
        $blockedByState = true;
    }
    checkTrue('pay slip blocked for a draft (not yet approved) run', $blockedByState);

    // ---------- Pay Slip: template-driven rendering (falls back to fixed layout above when no default template exists) ----------
    echo "=== PaySlipReport: template-driven rendering ===\n";
    $payslipTemplateModel = new PayslipTemplateModel($pdo);
    // Isolation step -- comp_id=1 is the real dev DB and may have a genuine admin-created default
    // template at any time (see tests/payslip_template_test.php's own comment on this same issue).
    $pdo->prepare("UPDATE `payslip_templates` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP WHERE comp_id = :comp_id AND deleted_at IS NULL")
        ->execute([':comp_id' => $compId]);
    check('no default payslip template exists yet for this fixture company', $payslipTemplateModel->getDefault($compId, 'th'), null);

    $psBase = ['pos_x_pct' => 8, 'width_pct' => 40, 'height_pct' => 5, 'font_size' => 12, 'font_family' => 'th_sarabun_new',
        'font_color' => '#000000', 'text_align' => 'left', 'font_weight' => 'normal', 'font_style' => 'normal', 'text_decoration' => 'none'];
    $templateSave = $payslipTemplateModel->save($compId, [
        'language' => 'th', 'template_name' => 'Test Slip Template', 'is_default' => 1, 'status' => 'active',
        'header_text_th' => 'ทดสอบหัวกระดาษ', 'footer_text_en' => 'Test footer',
        'elements' => [
            $psBase + ['element_type' => 'text', 'content' => '{{company_name}}', 'pos_y_pct' => 2],
            $psBase + ['element_type' => 'text', 'content' => '{{employee_no}}', 'pos_y_pct' => 10],
            $psBase + ['element_type' => 'text', 'content' => '{{employee_name}}', 'pos_y_pct' => 16],
            $psBase + ['element_type' => 'text', 'content' => '{{department}}', 'pos_y_pct' => 22],
            $psBase + ['element_type' => 'text', 'content' => '{{position}}', 'pos_y_pct' => 28],
            $psBase + ['element_type' => 'text', 'content' => '{{basic_salary}}', 'pos_y_pct' => 34],
            $psBase + ['element_type' => 'text', 'content' => '{{earning_lines_all}}', 'pos_y_pct' => 40, 'width_pct' => 84, 'height_pct' => 12],
            $psBase + ['element_type' => 'text', 'content' => '{{deduction_lines_all}}', 'pos_y_pct' => 53, 'width_pct' => 84, 'height_pct' => 12],
            $psBase + ['element_type' => 'text', 'content' => '{{statutory_lines_all}}', 'pos_y_pct' => 66, 'width_pct' => 84, 'height_pct' => 12],
            $psBase + ['element_type' => 'text', 'content' => '{{gross_amount}}', 'pos_y_pct' => 80],
            $psBase + ['element_type' => 'text', 'content' => '{{total_deduction_amount}}', 'pos_y_pct' => 85],
            $psBase + ['element_type' => 'text', 'content' => '{{net_amount}}', 'pos_y_pct' => 90],
            $psBase + ['element_type' => 'text', 'content' => '{{ytd_summary}}', 'pos_y_pct' => 95, 'width_pct' => 84],
            $psBase + ['element_type' => 'text', 'content' => '{{bank_account_masked}}', 'pos_x_pct' => 52, 'pos_y_pct' => 80],
            $psBase + ['element_type' => 'image', 'field_key' => 'company_logo', 'content' => null, 'pos_x_pct' => 70, 'pos_y_pct' => 2, 'width_pct' => 20, 'height_pct' => 8],
        ],
    ], $adminUserId);
    checkTrue('payslip template with a broad field mix saves', $templateSave['status']);
    // 2026-08-26: publish_status defaults to 'draft' on every INSERT -- getDefault() now also
    // requires publish_status='public' (see PayslipTemplateModel::save()'s own comment), so this
    // fixture must be explicitly published before PaySlipReport::generate() can find it.
    checkTrue('template published', $payslipTemplateModel->setPublishStatus($compId, (int)$templateSave['template_id'], 'public', $adminUserId)['status']);
    checkTrue('getDefault() now finds the new default template', $payslipTemplateModel->getDefault($compId, 'th') !== null);

    // 2026-09-05, Phase 12 T073: this (run, employee) pair was already cached by the earlier T061/
    // T073 sections above (BEFORE this template even existed) -- without clearing it, generate()
    // would correctly (per the new caching design) keep serving that OLDER fixed-layout fallback
    // PDF forever, making every assertion below about the TEMPLATE-driven render meaningless. See
    // the identical clear-before-re-testing-a-changed-scenario pattern a few sections below (the
    // company-logo-fallback check) for the same reasoning.
    $stmtPdfPath = $pdo->prepare("SELECT payslip_pdf_path FROM payroll_run_details WHERE run_id = :run_id AND employee_id = :employee_id");
    $stmtPdfPath->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
    $pathBeforeTemplate = $stmtPdfPath->fetchColumn();
    if (!empty($pathBeforeTemplate)) {
        @unlink(__DIR__ . '/../' . $pathBeforeTemplate);
    }
    $pdo->prepare("UPDATE `payroll_run_details` SET payslip_pdf_path = NULL WHERE run_id = :run_id AND employee_id = :employee_id")
        ->execute([':run_id' => $runId, ':employee_id' => $employeeId]);

    $templatedSlip = $paySlipReport->generate(['comp_id' => $compId, 'run_id' => $runId, 'employee_id' => $employeeId], 'pdf');
    checkTrue('templated PDF content starts with %PDF header', str_starts_with($templatedSlip['content'], '%PDF'));
    checkTrue('templated PDF has non-trivial content length', strlen($templatedSlip['content']) > 1000);
    checkTrue('templated PDF file_name follows the same naming convention', str_starts_with($templatedSlip['file_name'], 'PaySlip_') && str_ends_with($templatedSlip['file_name'], "_{$runId}.pdf"));

    // company_logo field with no logo_path set must not error -- it should just be skipped.
    checkTrue('generate() does not throw when company_logo field has no uploaded logo', is_string($templatedSlip['content']));

    // ---------- PaySlipReport: falls back to the Company Profile logo when the template has none ----------
    echo "=== PaySlipReport: falls back to the Company Profile logo when the template has none ===\n";
    $tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $companyLogoDir = __DIR__ . '/../public/uploads/company_logos/' . $compId;
    @mkdir($companyLogoDir, 0777, true);
    $companyLogoRel = 'public/uploads/company_logos/' . $compId . '/' . bin2hex(random_bytes(16)) . '.png';
    file_put_contents(__DIR__ . '/../' . $companyLogoRel, $tinyPng);
    try {
        $pdo->prepare('UPDATE `companies` SET logo_path = :p WHERE id = :id')->execute([':p' => $companyLogoRel, ':id' => $compId]);
        // 2026-09-05, Phase 12 T073: this exact (run, employee) pair was already cached by earlier
        // sections in this file -- generate() would otherwise correctly (per the new "generate
        // once, never re-render" design) serve that OLDER, logo-less cached PDF here instead of a
        // fresh render, which would make this specific before/after comparison meaningless. Clears
        // the cache column (+ its now-orphaned file) to force one genuinely fresh render, same as
        // the very first time anyone ever asks for this payslip -- this is testing the render path
        // itself, not the cache.
        $stmtPdfPath->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        $oldCachedPath = $stmtPdfPath->fetchColumn();
        if (!empty($oldCachedPath)) {
            @unlink(__DIR__ . '/../' . $oldCachedPath);
        }
        $pdo->prepare("UPDATE `payroll_run_details` SET payslip_pdf_path = NULL WHERE run_id = :run_id AND employee_id = :employee_id")
            ->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        $slipWithCompanyLogoFallback = $paySlipReport->generate(['comp_id' => $compId, 'run_id' => $runId, 'employee_id' => $employeeId], 'pdf');
        checkTrue('PDF still valid when falling back to the company logo', str_starts_with($slipWithCompanyLogoFallback['content'], '%PDF'));
        checkTrue('PDF is larger than the no-logo-at-all version (company logo actually embedded)', strlen($slipWithCompanyLogoFallback['content']) > strlen($templatedSlip['content']));
    } finally {
        $pdo->prepare('UPDATE `companies` SET logo_path = NULL WHERE id = :id')->execute([':id' => $compId]);
        @unlink(__DIR__ . '/../' . $companyLogoRel);
    }

    $toggleOff = $payslipTemplateModel->toggleStatus($compId, (int)$templateSave['template_id'], $adminUserId);
    checkTrue('deactivating the template succeeds', $toggleOff['status']);
    check('deactivating clears is_default', $toggleOff['new_status'], 'inactive');
    check('no default template again after deactivation', $payslipTemplateModel->getDefault($compId, 'th'), null);

    $fallbackAgainSlip = $paySlipReport->generate(['comp_id' => $compId, 'run_id' => $runId, 'employee_id' => $employeeId], 'pdf');
    checkTrue('generate() falls back cleanly after the template is deactivated', str_starts_with($fallbackAgainSlip['content'], '%PDF'));

    $invalidEmployeeId = false;
    try {
        $paySlipReport->generate(['comp_id' => $compId, 'run_id' => $runId, 'employee_id' => 'nope'], 'pdf');
    } catch (LocalizedException $e) {
        $invalidEmployeeId = ($e->getErrorKey() === 'employee_id_required');
    }
    checkTrue('non-numeric employee_id rejected', $invalidEmployeeId);

    // ---------- PND1K Summary (statutory, PDF/Excel/txt, requires Approved+) ----------
    echo "=== PndOneKorSummaryReport (statutory) ===\n";
    $pnd1kReport = ReportRegistry::get('TH_PND1K_SUMMARY');
    $pnd1kTxt = $pnd1kReport->generate(['comp_id' => $compId, 'year' => $periodYearBe], 'txt');
    $lines = explode("\r\n", rtrim($pnd1kTxt['content'], "\r\n"));
    // 2026-08-30, real dev-DB-state fragility fixed (feedback_dev_db_shared_state_test_fragility) --
    // comp_id=1 is the real shared dev DB company, which by now has other real employees with
    // approved runs in this same calendar year, so an exact "1 line total" count is no longer
    // meaningful here (it was 12, not 1, the moment this ran against real accumulated data). Finds
    // THIS fixture employee's own line by its known id_card_no instead of assuming array
    // position/count -- still a real assertion (the fixture's own line must exist and be correctly
    // formatted), just no longer dependent on how much other real data comp_id=1 has accumulated.
    // 2026-09-05, Phase 12 T071: field index moved from 0 to 1 (11-field layout now, id_card_no
    // -- not tax_id_no -- see PndOneKorSummaryReport's own docblock for that real fix).
    $fixtureLine = null;
    foreach ($lines as $line) {
        $f = explode('|', $line);
        if (($f[1] ?? null) === $idCardNo) {
            $fixtureLine = $f;
            break;
        }
    }
    checkTrue('txt includes a line for the fixture employee (comp_id=1 has other real employees with approved runs this year -- searched by id_card_no, not assumed to be the only/first line)', $fixtureLine !== null);
    check('txt id_card_no field matches the decrypted value', $fixtureLine[1] ?? null, $idCardNo);
    check('txt prefix field is Thai text นาย, not the raw title code (real bug fixed alongside the rewrite)', iconv('TIS-620', 'UTF-8', $fixtureLine[2] ?? ''), 'นาย');

    $pnd1kExcel = $pnd1kReport->generate(['comp_id' => $compId, 'year' => $periodYearBe], 'excel');
    checkTrue('excel content is non-empty', strlen($pnd1kExcel['content']) > 0);

    $pnd1kPdf = $pnd1kReport->generate(['comp_id' => $compId, 'year' => $periodYearBe], 'pdf');
    checkTrue('PDF content starts with %PDF header', str_starts_with($pnd1kPdf['content'], '%PDF'));

    echo "=== PndOneKorSummaryReport validation ===\n";
    // 2026-08-30, real bug found and fixed: this generate() was the last holdout in the whole
    // Reports module still throwing a plain InvalidArgumentException/RuntimeException, bypassing
    // the LocalizedException i18n mechanism every sibling report already used -- now migrated,
    // asserting the real error_key too (not just "an exception happened"), same convention as
    // PayrollRegisterReport's own validation tests above.
    $nonNumericYear = false;
    try {
        $pnd1kReport->generate(['comp_id' => $compId, 'year' => 'abc'], 'excel');
    } catch (LocalizedException $e) {
        $nonNumericYear = ($e->getErrorKey() === 'year_required');
    }
    checkTrue('non-numeric year rejected', $nonNumericYear);

    $outOfRangeYear = false;
    try {
        $pnd1kReport->generate(['comp_id' => $compId, 'year' => 9999], 'excel');
    } catch (LocalizedException $e) {
        $outOfRangeYear = ($e->getErrorKey() === 'year_out_of_range');
    }
    checkTrue('unreasonably far future year rejected', $outOfRangeYear);

    $noDataForYear = false;
    try {
        $pnd1kReport->generate(['comp_id' => $compId, 'year' => 2555], 'excel'); // B.E. 2555 = A.D. 2012, no runs exist there
    } catch (LocalizedException $e) {
        $noDataForYear = ($e->getErrorKey() === 'no_runs_in_state_for_year');
    }
    checkTrue('year with no approved runs rejected with a clear error', $noDataForYear);

    // ---------- PND1 (monthly) ----------
    // 2026-09-05, Phase 12 T071: PndOneExporter/PndOneReport rewritten against a confirmed
    // reference spec (11-field pipe layout, no address fields at all) -- see those classes' own
    // docblocks. The pre-2026-09-05 20-field layout (form-type-code constant, structured address
    // sub-fields) is gone entirely.
    echo "=== PndOneReport (statutory, monthly) ===\n";
    $pnd1Report = ReportRegistry::get('TH_PND1');
    $pnd1Txt = $pnd1Report->generate(['comp_id' => $compId, 'run_id' => $runId], 'txt');
    $pnd1Lines = explode("\r\n", rtrim($pnd1Txt['content'], "\r\n"));
    check('monthly txt has 1 employee line', count($pnd1Lines), 1);
    $pnd1Fields = explode('|', $pnd1Lines[0]);
    check('11 total pipe-delimited fields per row', count($pnd1Fields), 11);
    check('field 1: sequence number starts at 1', $pnd1Fields[0], '1');
    check('field 2: id_card_no matches decrypted value', $pnd1Fields[1], $idCardNo);
    check('field 3: prefix is the Thai text นาย (mr), not a numeric code', iconv('TIS-620', 'UTF-8', $pnd1Fields[2]), 'นาย');
    check('field 8: literal 0.00 rate placeholder', $pnd1Fields[7], '0.00');

    // "รองรับ 2 ภาษาเหมือนกัน" -- employee/company name + prefix follow the requested language.
    $pnd1TxtEn = $pnd1Report->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'en'], 'txt');
    $pnd1FieldsEn = explode('|', explode("\r\n", rtrim($pnd1TxtEn['content'], "\r\n"))[0]);
    check('en language: prefix is the English text Mr.', $pnd1FieldsEn[2], 'Mr.');
    checkTrue('en language produces a different row than th (name/prefix change)', $pnd1Fields !== $pnd1FieldsEn);
    check('en language: id_card_no field identical regardless of language', $pnd1FieldsEn[1], $pnd1Fields[1]);

    $pnd1DraftBlocked = false;
    try {
        $pnd1Report->generate(['comp_id' => $compId, 'run_id' => $draftRunId], 'txt');
    } catch (RuntimeException $e) {
        $pnd1DraftBlocked = true;
    }
    checkTrue('PND1 blocked for a draft run', $pnd1DraftBlocked);

    // 2026-08-29, explicit bug report: "PDF ใน Report ไม่รองรับภาษาไทย" -- real root cause was every
    // report's own generated HTML hardcoding font-family:'DejaVu Sans' (zero Thai glyphs) directly
    // in its <style> block, which overrides PdfRendererTrait's own defaultFont option entirely --
    // fixing the trait alone was not enough. Locks in the fix at the actual PDF-byte level (the
    // embedded font must be a real TH Sarabun New subset, not Times/Helvetica/DejaVu) so this
    // can't silently regress the next time a report's own HTML is touched.
    $pnd1Pdf = $pnd1Report->generate(['comp_id' => $compId, 'run_id' => $runId], 'pdf');
    checkTrue('PND1 PDF starts with %PDF header', str_starts_with($pnd1Pdf['content'], '%PDF'));
    preg_match_all('/\/BaseFont\s*\/([A-Za-z0-9+,-]+)/', $pnd1Pdf['content'], $pnd1FontMatches);
    $pnd1EmbedsThaiFont = false;
    foreach (array_unique($pnd1FontMatches[1]) as $bf) {
        if (str_contains($bf, 'THSarabun')) { $pnd1EmbedsThaiFont = true; }
    }
    checkTrue('PND1 PDF embeds a real TH Sarabun font (not a Thai-blind fallback like DejaVu/Times)', $pnd1EmbedsThaiFont);

    // ---------- SSO 1-10 ----------
    // 2026-09-05, Phase 12 T071: Sso110Exporter/Sso110Report rewritten against a confirmed
    // reference spec (7-field pipe layout, no header/batch-total row at all) -- see those
    // classes' own docblocks. The pre-2026-09-05 fixed-width (135/108-byte) layout is gone.
    echo "=== Sso110Report (statutory) ===\n";
    $sso110Report = ReportRegistry::get('TH_SSO110');
    $sso110Txt = $sso110Report->generate(['comp_id' => $compId, 'run_id' => $runId], 'txt');
    $sso110Rows = explode("\r\n", rtrim($sso110Txt['content'], "\r\n"));
    check('SSO110 has exactly 1 detail row (no header row anymore)', count($sso110Rows), 1);
    $sso110Fields = explode('|', $sso110Rows[0]);
    check('7 total pipe-delimited fields per row', count($sso110Fields), 7);
    check('SSO110 seq field is zero-padded to 5 digits', $sso110Fields[0], '00001');
    check('SSO110 insured_id matches decrypted id_card_no', $sso110Fields[1], $idCardNo);
    check('SSO110 prefix is Thai text นาย, not a numeric code', iconv('TIS-620', 'UTF-8', $sso110Fields[2]), 'นาย');

    // 2026-08-29, explicit request: "รองรับ 2 ภาษาเหมือนกัน" -- employee name follows the requested
    // language; the fixture's own name_en/surname_en ('Test'/'Report') differ from name_th/
    // surname_th ('ทดสอบ'/'รายงาน'), so this genuinely exercises the language switch.
    $sso110TxtEn = $sso110Report->generate(['comp_id' => $compId, 'run_id' => $runId, 'language' => 'en'], 'txt');
    $sso110FieldsEn = explode('|', explode("\r\n", rtrim($sso110TxtEn['content'], "\r\n"))[0]);
    checkTrue('SSO110 en language produces a different row (employee name changes)', $sso110Fields !== $sso110FieldsEn);
    check('SSO110 en id_card_no/wage/contribution fields identical regardless of language', [$sso110FieldsEn[1], $sso110FieldsEn[5], $sso110FieldsEn[6]], [$sso110Fields[1], $sso110Fields[5], $sso110Fields[6]]);

    // ---------- SSO 6-09 ----------
    echo "=== Sso609Report (statutory) ===\n";
    $sso609Report = ReportRegistry::get('TH_SSO609');
    $lastMonthDate = (clone $today)->modify('first day of last month');
    $resignedMonth = (int)$lastMonthDate->format('n');
    $resignedYearBe = (int)$lastMonthDate->format('Y') + 543;
    $sso609Excel = $sso609Report->generate(['comp_id' => $compId, 'year' => $resignedYearBe, 'month' => $resignedMonth], 'excel');
    checkTrue('SSO609 excel content is non-empty', strlen($sso609Excel['content']) > 0);
    $tmpXlsx2 = sys_get_temp_dir() . '/reports_test_' . uniqid() . '.xlsx';
    file_put_contents($tmpXlsx2, $sso609Excel['content']);
    $sheet2 = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($tmpXlsx2)->getActiveSheet();
    check('SSO609 has exactly the 1 resigned employee row', $sheet2->getCell('A2')->getValue(), $resignedSsoNo);
    unlink($tmpXlsx2);
    $sso609NoData = false;
    try {
        $sso609Report->generate(['comp_id' => $compId, 'year' => $periodYearBe, 'month' => (int)date('n', strtotime($periodStart))], 'excel');
    } catch (RuntimeException $e) {
        $sso609NoData = true;
    }
    checkTrue('SSO609 rejects a month with no resignations', $sso609NoData);

    // 2026-09-05, Phase 12 T071: SSO 6-09 gained a real 'txt' format this round (previously
    // PDF/Excel only, "no field-layout basis exists" per this class's own pre-existing docblock).
    // See Sso609Exporter's own docblock for the field layout.
    $sso609Txt = $sso609Report->generate(['comp_id' => $compId, 'year' => $resignedYearBe, 'month' => $resignedMonth], 'txt');
    $sso609Rows = explode("\r\n", rtrim($sso609Txt['content'], "\r\n"));
    check('SSO609 txt has exactly the 1 resigned employee row', count($sso609Rows), 1);
    $sso609Fields = explode('|', $sso609Rows[0]);
    check('5 total pipe-delimited fields per row', count($sso609Fields), 5);
    check('SSO609 txt citizen_id matches decrypted id_card_no (not sso_no)', $sso609Fields[1], $resignedIdCardNo);
    check('SSO609 txt full_name field decodes to prefix+first+last', iconv('TIS-620', 'UTF-8', $sso609Fields[2]), 'นางสาว ทดสอบ ลาออก');
    check('SSO609 txt reason defaults to 01 (resign) when employment_end_reason is unset', $sso609Fields[4], '01');

    // ---------- Kor.20Kor (PVD annual) ----------
    echo "=== Kor20KorReport (statutory, annual PVD) ===\n";
    $kor20Report = ReportRegistry::get('TH_KOR20KOR');
    $kor20Excel = $kor20Report->generate(['comp_id' => $compId, 'year' => $periodYearBe], 'excel');
    checkTrue('Kor20Kor excel content is non-empty', strlen($kor20Excel['content']) > 0);
    $kor20Pdf = $kor20Report->generate(['comp_id' => $compId, 'year' => $periodYearBe], 'pdf');
    checkTrue('Kor20Kor PDF starts with %PDF header', str_starts_with($kor20Pdf['content'], '%PDF'));

    // ---------- Student Loan Fund (กยศ.) ----------
    echo "=== StudentLoanReport (statutory) ===\n";
    $slfReport = ReportRegistry::get('TH_SLF');
    $slfExcel = $slfReport->generate(['comp_id' => $compId, 'run_id' => $runId], 'excel');
    checkTrue('SLF excel content is non-empty', strlen($slfExcel['content']) > 0);
    $tmpXlsx3 = sys_get_temp_dir() . '/reports_test_' . uniqid() . '.xlsx';
    file_put_contents($tmpXlsx3, $slfExcel['content']);
    $sheet3 = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($tmpXlsx3)->getActiveSheet();
    check('SLF detail row has the test employee number', strpos((string)$sheet3->getCell('A2')->getValue(), 'RPT_TEST_') === 0, true);
    check('SLF detail row has the loan contract reference number', $sheet3->getCell('E2')->getValue(), $slfContractNo);
    check('SLF detail row has the first installment amount (12000/12)', (float)$sheet3->getCell('F2')->getValue(), 1000.0);
    unlink($tmpXlsx3);

    $slfPdf = $slfReport->generate(['comp_id' => $compId, 'run_id' => $runId], 'pdf');
    checkTrue('SLF PDF starts with %PDF header', str_starts_with($slfPdf['content'], '%PDF'));

    $slfDraftBlocked = false;
    try {
        $slfReport->generate(['comp_id' => $compId, 'run_id' => $draftRunId], 'excel');
    } catch (RuntimeException $e) {
        $slfDraftBlocked = true;
    }
    checkTrue('SLF blocked for a draft run', $slfDraftBlocked);

    // 2026-09-05, Phase 12 T071: Student Loan Fund gained a real 'txt' format this round
    // (previously PDF/Excel only, "no field-layout basis exists at all" per this class's own
    // pre-existing docblock). See StudentLoanExporter's own docblock for the field layout.
    $slfTxt = $slfReport->generate(['comp_id' => $compId, 'run_id' => $runId], 'txt');
    $slfRows = explode("\r\n", rtrim($slfTxt['content'], "\r\n"));
    check('SLF txt has the 1 test employee row', count($slfRows), 1);
    $slfFields = explode('|', $slfRows[0]);
    check('4 total pipe-delimited fields per row', count($slfFields), 4);
    check('SLF txt citizen_id matches decrypted id_card_no (not tax_id_no, per the spec\'s own field label)', $slfFields[1], $idCardNo);
    check('SLF txt full_name field decodes to prefix+first+last', iconv('TIS-620', 'UTF-8', $slfFields[2]), 'นาย ทดสอบ รายงาน');
    check('SLF txt amount matches the first installment (12000/12)', $slfFields[3], '1000.00');

    // ---------- Bank Transfer File ----------
    echo "=== BankTransferFileReport (payment) ===\n";
    $bankReport = ReportRegistry::get('BANK_TRANSFER_FILE');
    $bankCsv = $bankReport->generate(['comp_id' => $compId, 'run_id' => $runId], 'csv');
    check('mime type is csv', $bankCsv['mime_type'], 'text/csv');
    checkTrue('CSV contains the decrypted bank account number', strpos($bankCsv['content'], $bankAccountNo) !== false);
    // 2026-08-29, explicit bug report: "excel csv...ไม่รองรับภาษาไทย" -- a plain UTF-8 CSV opened
    // directly in Excel (the realistic way this file gets viewed) very often gets misdetected as
    // the system's legacy Thai codepage without a BOM, turning every Thai header/value into
    // mojibake. A leading UTF-8 BOM fixes that; this fixture's own Thai header row proves it's
    // still valid content right after the BOM, not corrupted by it.
    checkTrue('CSV starts with a UTF-8 BOM (fixes Excel Thai-encoding mojibake on open)', str_starts_with($bankCsv['content'], "\xEF\xBB\xBF"));
    // 2026-09-04, Backlog Phase 11, T061: BANK_TRANSFER is now wired to DocumentNumberingModel --
    // a real file-reference comment line is unshifted ABOVE the header the moment a code is
    // assigned (which now happens on every real generate() call, see the wiring assertions right
    // below), so "the header is always the very first line after the BOM" is no longer literally
    // true -- was: strpos(..., BOM.'เลขที่บัญชี') === 0. Now: the header row appears somewhere near
    // the top (right after the file-code comment line), not necessarily at byte 0 post-BOM.
    checkTrue('Thai header row still present near the top of the file (after the BOM + file-code comment line)', strpos($bankCsv['content'], 'เลขที่บัญชี,ชื่อบัญชี') !== false);

    // ---------- Backlog Phase 11, T061: DocumentNumberingModel wired to BANK_TRANSFER ----------
    echo "=== BankTransferFileReport: DocumentNumberingModel wiring (T061) ===\n";
    checkTrue('the generated CSV leads with a "# เลขที่ไฟล์:" file-reference comment line', str_starts_with($bankCsv['content'], "\xEF\xBB\xBF" . '# เลขที่ไฟล์: '));
    $stmtBtCode = $pdo->prepare("SELECT bank_transfer_file_code FROM payroll_runs WHERE id = :id");
    $stmtBtCode->execute([':id' => $runId]);
    $btCodeAfterFirstGenerate = $stmtBtCode->fetchColumn();
    checkTrue('a bank_transfer_file_code was stamped after the first generate() call', !empty($btCodeAfterFirstGenerate));
    checkTrue('the file-code comment line embeds that exact persisted code', strpos($bankCsv['content'], $btCodeAfterFirstGenerate) !== false);
    // Re-generate (simulates re-downloading the same real transfer file) -- must reuse the SAME
    // code, never assign a new one on every render.
    $bankCsvAgain = $bankReport->generate(['comp_id' => $compId, 'run_id' => $runId], 'csv');
    $stmtBtCode->execute([':id' => $runId]);
    check('re-generating the SAME transfer file reuses the identical bank_transfer_file_code (idempotent, not re-assigned)', $stmtBtCode->fetchColumn(), $btCodeAfterFirstGenerate);
    checkTrue('the re-generated CSV embeds that same code too', strpos($bankCsvAgain['content'], $btCodeAfterFirstGenerate) !== false);
    $bankDraftBlocked = false;
    try {
        $bankReport->generate(['comp_id' => $compId, 'run_id' => $draftRunId], 'csv');
    } catch (RuntimeException $e) {
        $bankDraftBlocked = true;
    }
    checkTrue('bank transfer file blocked for a draft run', $bankDraftBlocked);

    // ---------- Payment Voucher (annual) ----------
    echo "=== PaymentVoucherReport (payment, annual) ===\n";
    $voucherReport = ReportRegistry::get('PAYMENT_VOUCHER');
    $voucherPdf = $voucherReport->generate(['comp_id' => $compId, 'year' => $periodYearBe, 'employee_id' => $employeeId], 'pdf');
    checkTrue('voucher PDF starts with %PDF header', str_starts_with($voucherPdf['content'], '%PDF'));
    $voucherNoRecords = false;
    try {
        $voucherReport->generate(['comp_id' => $compId, 'year' => $periodYearBe, 'employee_id' => $resignedEmployeeId], 'pdf');
    } catch (RuntimeException $e) {
        $voucherNoRecords = true;
    }
    checkTrue('voucher rejects an employee with no payment records that year', $voucherNoRecords);

    // ---------- Export log ----------
    echo "=== ReportExportLogModel ===\n";
    $logModel = new ReportExportLogModel($pdo);
    $logId = $logModel->log($compId, 'internal', 'PAYROLL_REGISTER', 'test.xlsx', 'excel', null, null, $runId, $adminUserId);
    checkTrue('log insert returns an id', $logId > 0);
    $logs = $logModel->list($compId, ['report_type' => 'internal']);
    checkTrue('list returns the logged entry', count(array_filter($logs, fn($l) => (int)$l['id'] === $logId)) === 1);

    $logs2 = $logModel->log($compId, 'statutory', 'TH_PND1K_SUMMARY', 'pnd1k.pdf', 'pdf', $periodYearBe, null, null, $adminUserId);
    checkTrue('second log entry (statutory, different period_year) inserted', $logs2 > 0);
    $filteredByRun = $logModel->list($compId, ['payroll_run_id' => $runId]);
    checkTrue('filter by payroll_run_id returns only that run\'s log', count($filteredByRun) === 1 && (int)$filteredByRun[0]['payroll_run_id'] === $runId);
    $filteredByYear = $logModel->list($compId, ['period_year' => $periodYearBe]);
    checkTrue('filter by period_year returns only the PND1K log', count($filteredByYear) === 1 && (int)$filteredByYear[0]['period_year'] === $periodYearBe);
    // 2026-08-29: this comp_id's dev DB now has a REAL pdf report_export_logs row from actual
    // manual testing of the Print Reports buttons added to the Payroll Process pages today (a
    // genuine SSO110 pdf generated outside this test's own transaction) -- asserting an exact
    // total count of 1 is fragile to that (see feedback_dev_db_shared_state_test_fragility).
    // Scoped down to this fixture's own log id instead, same isolation pattern already used
    // elsewhere in this project for a comp_id with real concurrent data.
    $filteredByFormat = $logModel->list($compId, ['format' => 'pdf']);
    $matchesFixture = array_filter($filteredByFormat, fn($l) => (int)$l['id'] === $logs2);
    checkTrue('filter by format returns this fixture\'s own pdf log entry', count($matchesFixture) === 1);
    checkTrue('every row filter by format returns is genuinely format=pdf', count(array_filter($filteredByFormat, fn($l) => $l['format'] !== 'pdf')) === 0);

    echo "=== ReportExportLogModel: download history (2026-08-29, explicit request) ===\n";
    // Real Chrome-on-Windows and Firefox-on-Windows UA strings -- same fixture style
    // EmployeeLoginLogModel's own parseUserAgent() tests already use.
    $chromeUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Safari/537.36';
    $firefoxUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:120.0) Gecko/20100101 Firefox/120.0';
    $ssoLogId = $logModel->log($compId, 'statutory', 'TH_SSO110', 'sso110_th.pdf', 'pdf', null, null, $runId, $adminUserId, '203.0.113.10', $chromeUa, 'th', 'process_detail');
    checkTrue('log() with IP/UA/language/source succeeds', $ssoLogId > 0);
    $ssoLogRow = current(array_filter($logModel->list($compId, ['report_code' => 'TH_SSO110', 'payroll_run_id' => $runId]), fn($l) => (int)$l['id'] === $ssoLogId));
    checkTrue('list(report_code filter) finds the new row', $ssoLogRow !== false);
    check('ip_address stored verbatim', $ssoLogRow['ip_address'], '203.0.113.10');
    check('device_type parsed from the UA (desktop, Windows Chrome)', $ssoLogRow['device_type'], 'desktop');
    check('browser_name parsed from the UA (Chrome)', $ssoLogRow['browser_name'], 'Chrome');
    check('language stored as given', $ssoLogRow['language'], 'th');
    check('source stored as given', $ssoLogRow['source'], 'process_detail');
    checkTrue('the raw user_agent is kept alongside the parsed fields', str_contains((string)$ssoLogRow['user_agent'], 'Chrome/119.0.0.0'));

    $ssoLogId2 = $logModel->log($compId, 'statutory', 'TH_SSO110', 'sso110_en.pdf', 'pdf', null, null, $runId, $adminUserId, '198.51.100.20', $firefoxUa, 'en', 'process_detail');
    checkTrue('a second download of the SAME report_code+run logs a separate row' , $ssoLogId2 > 0 && $ssoLogId2 !== $ssoLogId);
    // Looked up by its own id (not list()'s own generated_at DESC ordering, which is NOT a stable
    // tiebreak between two rows inserted within the same second in this fast test) -- verifies the
    // second row parsed Firefox correctly and independently of the first row's own Chrome UA.
    $ssoLogRow2 = current(array_filter($logModel->list($compId, ['report_code' => 'TH_SSO110', 'payroll_run_id' => $runId]), fn($l) => (int)$l['id'] === $ssoLogId2));
    check('the second row parses Firefox correctly, independent of the first', $ssoLogRow2['browser_name'], 'Firefox');
    check('the second row keeps its own distinct IP too', $ssoLogRow2['ip_address'], '198.51.100.20');

    echo "=== ReportExportLogModel::summaryForRun() ===\n";
    $summary = $logModel->summaryForRun($compId, $runId);
    checkTrue('summaryForRun() includes TH_SSO110 (2 downloads logged above)', isset($summary['TH_SSO110']));
    check('TH_SSO110 download_count is 2', $summary['TH_SSO110']['download_count'], 2);
    checkTrue('TH_SSO110 last_downloaded_at is the more recent of the two (the English one)', $summary['TH_SSO110']['last_downloaded_at'] >= $ssoLogRow['generated_at']);
    checkTrue('a report_code never downloaded for this run is simply absent from the summary map', !isset($summary['BANK_TRANSFER_FILE']));

    echo "=== PayrollRunModel::calcApplicabilitySummary() (2026-08-29, backs the Reports tab's own tax/SSO hiding) ===\n";
    $applicability = $runModel->calcApplicabilitySummary($runId, $compId);
    checkTrue('calcApplicabilitySummary() returns an any_tax/any_sso shape', array_key_exists('any_tax', $applicability) && array_key_exists('any_sso', $applicability));

    // ---------- 2026-08-31, explicit request: "ในหน้า List และ Detail ของการทำรอบ อยากให้มีการ Export
    // Excel ได้ไม่ว่าจะสถานะไหน" -- new PAYROLL_RUN_LIST_SUMMARY (internal, list-page export). ----------
    echo "=== PayrollRunListSummaryReport (new, List-page Export Excel) ===\n";
    $listSummaryReport = ReportRegistry::get('PAYROLL_RUN_LIST_SUMMARY');
    checkTrue('PAYROLL_RUN_LIST_SUMMARY is registered', $listSummaryReport !== null);
    if ($listSummaryReport) {
        check('reportType() is internal (no state gate, matches "ไม่ว่าจะสถานะไหน")', $listSummaryReport->reportType(), 'internal');
        $listSummaryResult = $listSummaryReport->generate(['comp_id' => $compId], 'excel');
        checkTrue('generate() with no filter produces real xlsx bytes (ZIP magic)', substr($listSummaryResult['content'], 0, 2) === 'PK');
        check('mime_type is xlsx', $listSummaryResult['mime_type'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $listSummaryFiltered = $listSummaryReport->generate(['comp_id' => $compId, 'date_from' => $periodStart, 'date_to' => $periodEnd], 'excel');
        checkTrue('generate() scoped to this fixture run\'s own period also succeeds', substr($listSummaryFiltered['content'], 0, 2) === 'PK');

        $listSummaryEmptyThrew = false;
        try {
            $listSummaryReport->generate(['comp_id' => $compId, 'state' => 'a_state_that_will_never_match_any_real_run'], 'excel');
        } catch (LocalizedException $e) {
            $listSummaryEmptyThrew = true;
            check('empty-result error_key is run_list_empty', $e->getErrorKey(), 'run_list_empty');
        }
        checkTrue('generate() throws (not a silent empty file) when the filter matches zero runs', $listSummaryEmptyThrew);

        $listSummaryNoCompThrew = false;
        try {
            $listSummaryReport->generate(['comp_id' => 0], 'excel');
        } catch (LocalizedException $e) {
            $listSummaryNoCompThrew = true;
        }
        checkTrue('generate() rejects comp_id=0', $listSummaryNoCompThrew);
    }

    echo "=== ReportsController::companySsoActive() (2026-08-31, company-wide SSO report gate) ===\n";
    $reportsController = new ReportsController();
    $ssoActiveRef = new ReflectionMethod($reportsController, 'companySsoActive');
    $ssoActiveRef->setAccessible(true);
    checkTrue('companySsoActive() is true for comp_id=1 (real TH company, SSO on by default)', $ssoActiveRef->invoke($reportsController, $compId));

    $ssoItemId = (int)$pdo->query("SELECT id FROM statutory_items WHERE code='TH_SSO'")->fetchColumn();
    $existingSsoSetting = $pdo->prepare("SELECT id FROM company_statutory_settings WHERE comp_id=:comp_id AND statutory_item_id=:item_id AND deleted_at IS NULL");
    $existingSsoSetting->execute([':comp_id' => $compId, ':item_id' => $ssoItemId]);
    $existingSsoRow = $existingSsoSetting->fetch(PDO::FETCH_ASSOC);
    if ($existingSsoRow) {
        $pdo->prepare("UPDATE company_statutory_settings SET status='inactive' WHERE id=:id")->execute([':id' => $existingSsoRow['id']]);
    } else {
        $pdo->prepare("INSERT INTO company_statutory_settings (comp_id, statutory_item_id, status, created_by) VALUES (:comp_id, :item_id, 'inactive', :uid)")
            ->execute([':comp_id' => $compId, ':item_id' => $ssoItemId, ':uid' => $adminUserId]);
    }
    $reportsControllerAfterDeactivate = new ReportsController();
    $ssoActiveRefAfter = new ReflectionMethod($reportsControllerAfterDeactivate, 'companySsoActive');
    $ssoActiveRefAfter->setAccessible(true);
    checkTrue('companySsoActive() flips to false once the company deactivates TH_SSO', !$ssoActiveRefAfter->invoke($reportsControllerAfterDeactivate, $compId));

    // NOTE: ReportsController::list() itself is deliberately NOT called directly here -- like
    // every controller action in this app, it ends in $this->json()/exit(), which would kill this
    // whole test script (and skip the finally{} rollback below) rather than just returning. Same
    // filtering logic replicated inline instead, keyed off the SAME companySsoActive() this
    // reflection already confirmed just flipped to false.
    $listCodesAfterDeactivate = [];
    foreach (ReportRegistry::all() as $r) {
        if (!$ssoActiveRefAfter->invoke($reportsControllerAfterDeactivate, $compId) && in_array($r->code(), ['TH_SSO110', 'TH_SSO609'], true)) continue;
        $listCodesAfterDeactivate[] = $r->code();
    }
    checkTrue('list()\'s own filtering logic (the Annual Reports tab\'s source) drops TH_SSO609 once SSO is company-wide inactive', !in_array('TH_SSO609', $listCodesAfterDeactivate, true));
    checkTrue('drops TH_SSO110 too', !in_array('TH_SSO110', $listCodesAfterDeactivate, true));
    checkTrue('still includes an unrelated report (TH_PND1)', in_array('TH_PND1', $listCodesAfterDeactivate, true));

    // Restore (still inside this test's own rolled-back transaction, but keeps the rest of this
    // file's own later assertions -- if any get added after this point in the future -- from
    // silently running against a company that now looks SSO-inactive).
    $pdo->prepare("UPDATE company_statutory_settings SET status='active' WHERE statutory_item_id=:item_id AND comp_id=:comp_id")->execute([':item_id' => $ssoItemId, ':comp_id' => $compId]);
    $reportsControllerRestored = new ReportsController();
    $ssoActiveRefRestored = new ReflectionMethod($reportsControllerRestored, 'companySsoActive');
    $ssoActiveRefRestored->setAccessible(true);
    checkTrue('companySsoActive() flips back to true after reactivating', $ssoActiveRefRestored->invoke($reportsControllerRestored, $compId));

    // ---------- 2026-08-31, explicit request: "สิทธิ์ในการมองเห็นเงินเดือน...จะเห็นเป็น XXXX แต่ยังสามารถ
    // คำนวณเงินเดือน...ได้ตามสิทธิ์" -- salary_amount.view_reports gates the WHOLE download (a
    // generated file can't be selectively redacted after the fact, unlike a live JSON response --
    // see ReportsController::generate()'s own docblock). Full permission-matrix coverage of
    // resolveSalaryVisibility() itself (own_only/summary/admin-bypass/no-grant) already lives in
    // tests/permission_matrix_test.php and tests/salary_amount_visibility_test.php -- this just
    // confirms the 'reports' module key resolves through the SAME generic mechanism. ----------
    echo "=== resolveSalaryVisibility('reports') -- the gate generate() now enforces ===\n";
    checkTrue('salary_amount.view_reports permission row exists', $pdo->query("SELECT COUNT(*) FROM permissions WHERE permission_key='salary_amount.view_reports'")->fetchColumn() > 0);
    $permModel = new PermissionModel($pdo);
    $adminReportsVisibility = $permModel->resolveSalaryVisibility($adminUserId, 'reports', true, $compId);
    checkTrue('admin always has full reports visibility', $adminReportsVisibility['full']);
    $noGrantEmpNo = 'RPT_NOGRANT_' . uniqid();
    $pdo->prepare("INSERT INTO structure_roles (comp_id, role_name_th, role_name_en, status, created_by) VALUES (:comp_id, 'ไม่มีสิทธิ์รายงาน', 'NoReportGrant', 'active', :uid)")
        ->execute([':comp_id' => $compId, ':uid' => $adminUserId]);
    $noReportGrantRoleId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, role_id, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt)
        VALUES (:comp_id, :employee_no, :role_id, 'mr', 'male', 'ทดสอบ', 'RPT', 'Test', 'RPT', '1990-01-01', 'Thai',
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 25000, '2020-01-01', 'average', 'active', 0, 0, 0)")
        ->execute([':comp_id' => $compId, ':employee_no' => $noGrantEmpNo, ':role_id' => $noReportGrantRoleId, ':email' => uniqid() . '@test.local']);
    $noReportGrantEmpId = (int)$pdo->lastInsertId();
    $noGrantReportsVisibility = $permModel->resolveSalaryVisibility($noReportGrantEmpId, 'reports', false, $compId);
    checkTrue('a role with no salary_amount.view_reports grant is masked (generate() would refuse the whole download)', $noGrantReportsVisibility['masked']);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
