<?php
declare(strict_types=1);
require_once __DIR__ . '/../../ReportGeneratorInterface.php';
require_once __DIR__ . '/../../PdfRendererTrait.php';
require_once __DIR__ . '/../../ExcelRendererTrait.php';
require_once __DIR__ . '/../../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../export/th/PndOneExporter.php';
require_once __DIR__ . '/../../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../../../models/StatutoryFormatVersionModel.php';
require_once __DIR__ . '/../../LocalizedException.php';

/**
 * ภ.ง.ด.1 monthly withholding return — one payroll run = one month's submission (unlike
 * PndOneKorSummaryReport, which aggregates a whole calendar year for ภ.ง.ด.1ก). 'txt' format
 * delegates to PndOneExporter (2026-08-29, rewritten against a structural field-order
 * description the user gave directly — see that class's own docblock, including its one
 * genuine, flagged data gap: no structured house-no./moo/building/soi/road columns exist
 * anywhere in this app, only free-text address lines). Requires the run to be at least Approved.
 *
 * 2026-08-29, explicit follow-up: "รองรับ 2 ภาษาเหมือนกัน...เช็คตรงข้อมูลบริษัทมี Filed เก็บครบหรือยัง" --
 * optional context.language ('th'/'en', default 'th', same convention every other report this
 * project added the same week uses) covers employee first/last name and prefix. Data-field
 * completeness as of this round:
 *   - National ID card no. (employees.id_card_no) -- ALREADY existed (wired up for SSO110's own
 *     insured_id the same week), no new field needed.
 *   - Prefix -- employees.title (mr/mrs/ms) mapped to the Thai form's own expected text
 *     (นาย/นาง/นางสาว, or Mr./Mrs./Ms. for the English pass) here in this class, not stored
 *     anywhere as a separate "PND1 prefix" value.
 *   - Subdistrict/district/province/postal code -- ALREADY existed via
 *     employees.master_address_id_register -> master_addresses (the same table/pattern Company
 *     Profile's own address already uses), just never joined into a payroll report's own query
 *     before this round (PayrollReportDataModel::getRunDetails() now does).
 *   - House no./moo/building/soi/road -- NO structured columns exist for any of these; the
 *     employee's one free-text address_line_1_register (+ _2 if present) is passed through as
 *     field 8 (house no.) below, fields 9-12 stay blank. A real fix needs 4 new Employee-form
 *     address fields, not a report-layer workaround -- flagged here rather than silently
 *     invented.
 */
class PndOneReport implements ReportGeneratorInterface {
    use PdfRendererTrait;
    use ExcelRendererTrait;
    use EmployeePiiTrait;

    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];

    public function code(): string {
        return 'TH_PND1';
    }

    public function reportType(): string {
        return 'statutory';
    }

    public function label(): array {
        return ['th' => 'ภ.ง.ด.1 (รายเดือน)', 'en' => 'PND.1 (Monthly)'];
    }

    public function isVerified(): bool {
        return false;
    }

    public function supportedFormats(): array {
        return ['pdf', 'excel', 'txt'];
    }

    /**
     * @param array $context { comp_id: int, run_id: int }
     */
    public function generate(array $context, string $format): array {
        $compId = (int)($context['comp_id'] ?? 0);
        if ($compId <= 0) {
            throw new LocalizedException('comp_id is required.', 'comp_id_required');
        }
        if (!isset($context['run_id']) || !is_numeric($context['run_id']) || (int)$context['run_id'] <= 0) {
            throw new LocalizedException('run_id is required and must be a positive integer.', 'run_id_required');
        }
        $runId = (int)$context['run_id'];
        $requestedLanguage = $context['language'] ?? 'th';
        $language = in_array($requestedLanguage, ['th', 'en'], true) ? $requestedLanguage : 'th';

        $dataModel = new PayrollReportDataModel();
        $run = $dataModel->getRun($runId, $compId);
        if (!$run) {
            throw new LocalizedException('Payroll run not found.', 'run_not_found');
        }
        $dataModel->assertRunStateOrThrow($run, self::ALLOWED_STATES);
        $details = $dataModel->getRunDetails($runId);

        // 2026-08-29: PND1's own expected prefix TEXT (นาย/นาง/นางสาว), distinct from SSO110's
        // own numeric-code convention for the same employees.title column -- see this class's
        // own docblock.
        $prefixMap = $language === 'en'
            ? ['mr' => 'Mr.', 'mrs' => 'Mrs.', 'ms' => 'Ms.']
            : ['mr' => 'นาย', 'mrs' => 'นาง', 'ms' => 'นางสาว'];

        $employeeList = [];
        foreach ($details as $d) {
            $pit = 0.0;
            foreach ($d['statutory_breakdown'] as $item) {
                if ($item['code'] === 'TH_PIT') {
                    $pit = (float)$item['employee_amount'];
                }
            }
            // 2026-08-29: house_no carries the ENTIRE free-text address line as a pragmatic
            // catch-all (moo/building/soi/road have no structured source at all) -- see this
            // class's own top-of-file docblock for why, and PndOneExporter's own docblock for
            // the same gap from the exporter side.
            $addressLine = trim(($d['address_line_1_register'] ?? '') . ' ' . ($d['address_line_2_register'] ?? ''));
            $employeeList[] = [
                'id_card_no' => $this->decryptEmployeeField($d, 'id_card_no') ?? '',
                'prefix' => $prefixMap[$d['title'] ?? ''] ?? '',
                'first_name' => $language === 'en' ? ($d['name_en'] ?? '') : ($d['name_th'] ?? ''),
                'last_name' => $language === 'en' ? ($d['surname_en'] ?? '') : ($d['surname_th'] ?? ''),
                'house_no' => $addressLine,
                'subdistrict' => $language === 'en' ? ($d['address_subdistrict_en'] ?? '') : ($d['address_subdistrict_th'] ?? ''),
                'district' => $language === 'en' ? ($d['address_district_en'] ?? '') : ($d['address_district_th'] ?? ''),
                'province' => $language === 'en' ? ($d['address_province_en'] ?? '') : ($d['address_province_th'] ?? ''),
                'postal_code' => $d['address_postcode'] ?? '',
                'total_income' => round((float)$d['gross_amount'], 2),
                'tax_withheld' => round($pit, 2),
            ];
        }
        if (empty($employeeList)) {
            throw new LocalizedException('This payroll run has no calculated employees yet. Recalculate it first.', 'run_no_calculated_employees');
        }

        $yearBe = (int)date('Y', strtotime($run['period_start_date'])) + 543;
        $monthNum = (int)date('n', strtotime($run['period_start_date']));

        if ($format === 'txt') {
            // 2026-08-29 -- see Sso110Report's own comment on the same call pattern.
            $versionCode = (new StatutoryFormatVersionModel())->resolveVersionCode($compId, $this->code());
            $exporter = new PndOneExporter();
            $periodContext = ['tax_year' => $yearBe, 'tax_month' => $monthNum, 'payment_date' => $run['payment_date']];
            $content = $exporter->generate(['period' => $periodContext, 'employees' => $employeeList, 'version_code' => $versionCode]);
            return ['content' => $content, 'file_name' => $exporter->fileName(['period' => $periodContext]), 'mime_type' => 'text/plain'];
        }

        if ($format === 'excel') {
            $headers = ['เลขประจำตัวประชาชน', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'เงินได้เดือนนี้', 'ภาษีหัก ณ ที่จ่าย'];
            $rows = array_map(fn($e) => [$e['id_card_no'], $e['prefix'], $e['first_name'], $e['last_name'], $e['total_income'], $e['tax_withheld']], $employeeList);
            $content = $this->renderExcelFromRows($headers, $rows, "PND1 {$yearBe}-{$monthNum}");
            return ['content' => $content, 'file_name' => sprintf('PND1_%04d%02d.xlsx', $yearBe, $monthNum), 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }

        // pdf
        $company = $dataModel->getCompany($compId);
        $companyName = htmlspecialchars($language === 'en' ? ($company['company_legal_name'] ?? ($company['local_name'] ?? '')) : ($company['local_name'] ?? ($company['company_legal_name'] ?? '')));
        $rowsHtml = '';
        $totalIncome = 0.0;
        $totalTax = 0.0;
        foreach ($employeeList as $e) {
            $totalIncome += $e['total_income'];
            $totalTax += $e['tax_withheld'];
            $rowsHtml .= '<tr><td>' . htmlspecialchars($e['id_card_no']) . '</td><td>' . htmlspecialchars($e['prefix'] . ' ' . $e['first_name'] . ' ' . $e['last_name']) . '</td><td class="amount">' . number_format($e['total_income'], 2) . '</td><td class="amount">' . number_format($e['tax_withheld'], 2) . '</td></tr>';
        }
        $html = <<<HTML
<html><head><style>
body { font-family: 'TH Sarabun New', 'DejaVu Sans', sans-serif; font-size: 14px; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
th { background: #f0f0f0; }
.amount { text-align: right; }
tfoot td { font-weight: bold; }
</style></head><body>
<h1>{$companyName}</h1>
<div>ภ.ง.ด.1 ประจำงวด {$monthNum}/{$yearBe}</div>
<table>
<thead><tr><th>เลขประจำตัวประชาชน</th><th>ชื่อ-สกุล</th><th>เงินได้เดือนนี้</th><th>ภาษีหัก ณ ที่จ่าย</th></tr></thead>
<tbody>{$rowsHtml}</tbody>
<tfoot><tr><td colspan="2">รวม</td><td class="amount">{$this->fmt($totalIncome)}</td><td class="amount">{$this->fmt($totalTax)}</td></tr></tfoot>
</table>
</body></html>
HTML;
        $content = $this->renderPdfFromHtml($html, 'A4', 'landscape');
        return ['content' => $content, 'file_name' => sprintf('PND1_%04d%02d.pdf', $yearBe, $monthNum), 'mime_type' => 'application/pdf'];
    }

    private function fmt(float $n): string {
        return number_format($n, 2);
    }
}
