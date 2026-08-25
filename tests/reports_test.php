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
    $bankAccountNo = '1112223334';
    $encTax = EncryptionService::encrypt($taxId);
    $encSso = EncryptionService::encrypt($ssoNo);
    $encBank = EncryptionService::encrypt($bankAccountNo);
    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         tax_id_no, sso_no, bank_id, bank_account_no, bank_account_name, key_version,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, department_id)
        VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en, '1990-01-01', 'Thai',
         :tax_id_no, :sso_no, 1, :bank_account_no, :bank_account_name, :key_version,
         :email, '0800000000', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', :base_salary, '2020-01-01', 'average', 'active',
         1, 1, 0, NULL)");
    $insEmp->execute([
        ':comp_id' => $compId, ':employee_no' => 'RPT_TEST_' . uniqid(),
        ':name_th' => 'ทดสอบ', ':surname_th' => 'รายงาน', ':name_en' => 'Test', ':surname_en' => 'Report',
        ':sso_no' => $encSso['value'], ':bank_account_no' => $encBank['value'], ':bank_account_name' => 'ทดสอบ รายงาน',
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
    $insResigned = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         sso_no, key_version,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_end_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, department_id)
        VALUES (:comp_id, :employee_no, 'ms', 'female', 'ทดสอบ', 'ลาออก', 'Test', 'Resigned', '1990-01-01', 'Thai',
         :sso_no, :key_version,
         :email, '0811111111', 'Test Address', 'Test Address',
         'Emergency', 'Contact', 'friend', '0899999999',
         '2019-01-01', :employment_end_date, 'resigned', 'full_time', 'office', 'manual',
         'bank', 'monthly', 28000, '2019-01-01', 'average', 'active',
         1, 0, 0, NULL)");
    $insResigned->execute([
        ':comp_id' => $compId, ':employee_no' => 'RPT_RESIGNED_' . uniqid(),
        ':sso_no' => $encResignedSso['value'], ':key_version' => $encResignedSso['key_version'],
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
    check('payment type has 3 reports', count(ReportRegistry::byType('payment')), 3);
    check('internal type has 1 report', count(ReportRegistry::byType('internal')), 1);
    check('unknown code returns null', ReportRegistry::get('NOPE'), null);

    // ---------- Payroll Register (internal, Excel, any state) ----------
    echo "=== PayrollRegisterReport (internal, Excel) ===\n";
    $registerReport = ReportRegistry::get('PAYROLL_REGISTER');
    $registerResult = $registerReport->generate(['comp_id' => $compId, 'run_id' => $runId], 'excel');
    checkTrue('excel content is non-empty', strlen($registerResult['content']) > 0);
    check('mime type is xlsx', $registerResult['mime_type'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $tmpXlsx = sys_get_temp_dir() . '/reports_test_' . uniqid() . '.xlsx';
    file_put_contents($tmpXlsx, $registerResult['content']);
    $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
    $sheet = $reader->load($tmpXlsx)->getActiveSheet();
    check('header row A1 is employee code column', $sheet->getCell('A1')->getValue(), 'รหัสพนักงาน');
    check('data row A2 has the test employee number', strpos((string)$sheet->getCell('A2')->getValue(), 'RPT_TEST_') === 0, true);
    unlink($tmpXlsx);

    // Draft run is allowed for the internal report (no state gate).
    $draftRegisterResult = $registerReport->generate(['comp_id' => $compId, 'run_id' => $draftRunId], 'excel');
    checkTrue('internal report allowed on a draft run', strlen($draftRegisterResult['content']) > 0);

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
    check('no default payslip template exists yet for this fixture company', $payslipTemplateModel->getDefaultForCompany($compId), null);

    $psBase = ['pos_x_pct' => 8, 'width_pct' => 40, 'height_pct' => 5, 'font_size' => 12, 'font_family' => 'th_sarabun_new',
        'font_color' => '#000000', 'text_align' => 'left', 'font_weight' => 'normal', 'font_style' => 'normal', 'text_decoration' => 'none'];
    $templateSave = $payslipTemplateModel->save($compId, [
        'template_name' => 'Test Slip Template', 'language_mode' => 'both', 'is_default' => 1, 'status' => 'active',
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
    checkTrue('getDefaultForCompany now finds the new default template', $payslipTemplateModel->getDefaultForCompany($compId) !== null);

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
    check('no default template again after deactivation', $payslipTemplateModel->getDefaultForCompany($compId), null);

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
    check('txt has 1 employee line', count($lines), 1);
    $fields = explode('|', $lines[0]);
    check('txt tax_id field matches the decrypted value', $fields[0], $taxId);

    $pnd1kExcel = $pnd1kReport->generate(['comp_id' => $compId, 'year' => $periodYearBe], 'excel');
    checkTrue('excel content is non-empty', strlen($pnd1kExcel['content']) > 0);

    $pnd1kPdf = $pnd1kReport->generate(['comp_id' => $compId, 'year' => $periodYearBe], 'pdf');
    checkTrue('PDF content starts with %PDF header', str_starts_with($pnd1kPdf['content'], '%PDF'));

    echo "=== PndOneKorSummaryReport validation ===\n";
    $nonNumericYear = false;
    try {
        $pnd1kReport->generate(['comp_id' => $compId, 'year' => 'abc'], 'excel');
    } catch (InvalidArgumentException $e) {
        $nonNumericYear = true;
    }
    checkTrue('non-numeric year rejected', $nonNumericYear);

    $outOfRangeYear = false;
    try {
        $pnd1kReport->generate(['comp_id' => $compId, 'year' => 9999], 'excel');
    } catch (InvalidArgumentException $e) {
        $outOfRangeYear = true;
    }
    checkTrue('unreasonably far future year rejected', $outOfRangeYear);

    $noDataForYear = false;
    try {
        $pnd1kReport->generate(['comp_id' => $compId, 'year' => 2555], 'excel'); // B.E. 2555 = A.D. 2012, no runs exist there
    } catch (RuntimeException $e) {
        $noDataForYear = true;
    }
    checkTrue('year with no approved runs rejected with a clear error', $noDataForYear);

    // ---------- PND1 (monthly) ----------
    echo "=== PndOneReport (statutory, monthly) ===\n";
    $pnd1Report = ReportRegistry::get('TH_PND1');
    $pnd1Txt = $pnd1Report->generate(['comp_id' => $compId, 'run_id' => $runId], 'txt');
    $pnd1Lines = explode("\r\n", rtrim($pnd1Txt['content'], "\r\n"));
    check('monthly txt has 1 employee line', count($pnd1Lines), 1);
    check('monthly txt tax_id matches decrypted value', explode('|', $pnd1Lines[0])[0], $taxId);
    $pnd1DraftBlocked = false;
    try {
        $pnd1Report->generate(['comp_id' => $compId, 'run_id' => $draftRunId], 'txt');
    } catch (RuntimeException $e) {
        $pnd1DraftBlocked = true;
    }
    checkTrue('PND1 blocked for a draft run', $pnd1DraftBlocked);

    // ---------- SSO 1-10 ----------
    echo "=== Sso110Report (statutory) ===\n";
    $sso110Report = ReportRegistry::get('TH_SSO110');
    $sso110Txt = $sso110Report->generate(['comp_id' => $compId, 'run_id' => $runId], 'txt');
    $sso110Rows = explode("\r\n", rtrim($sso110Txt['content'], "\r\n"));
    check('SSO110 has 1 header + 1 detail row', count($sso110Rows), 2);
    check('SSO110 header row is 135 bytes', strlen($sso110Rows[0]), 135);
    check('SSO110 detail row is 135 bytes', strlen($sso110Rows[1]), 135);
    check('SSO110 detail insured_id matches decrypted sso_no', substr($sso110Rows[1], 1, 13), $ssoNo);

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

    // ---------- Bank Transfer File ----------
    echo "=== BankTransferFileReport (payment) ===\n";
    $bankReport = ReportRegistry::get('BANK_TRANSFER_FILE');
    $bankCsv = $bankReport->generate(['comp_id' => $compId, 'run_id' => $runId], 'csv');
    check('mime type is csv', $bankCsv['mime_type'], 'text/csv');
    checkTrue('CSV contains the decrypted bank account number', strpos($bankCsv['content'], $bankAccountNo) !== false);
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
    $filteredByFormat = $logModel->list($compId, ['format' => 'pdf']);
    checkTrue('filter by format returns only pdf logs', count($filteredByFormat) === 1 && $filteredByFormat[0]['format'] === 'pdf');

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
exit($failures === 0 ? 0 : 1);
