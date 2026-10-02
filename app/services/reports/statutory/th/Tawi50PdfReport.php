<?php
declare(strict_types=1);
require_once __DIR__ . '/../../ReportGeneratorInterface.php';
require_once __DIR__ . '/../../PdfRendererTrait.php';
require_once __DIR__ . '/../../EmployeePiiTrait.php';
require_once __DIR__ . '/../../LocalizedException.php';
require_once __DIR__ . '/../../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../../../models/AnnualIncomeSummaryModel.php';

/**
 * หนังสือรับรองการหักภาษี ณ ที่จ่าย ตามมาตรา 50 ทวิ (50 ทวิ) — one PDF per employee per tax year.
 *
 * Figures come from AnnualIncomeSummaryModel::getEmployeeAnnualSummary(): runs paid in the calendar year PLUS any
 * opening YTD balance imported for a mid-year go-live (final_* keys). Section 1 (มาตรา 40(1)) shows final_gross /
 * final_tax, the fund lines show final_pvd / final_sso, and a footnote names the opening-balance date when one is included.
 *
 * DRAFT (isVerified() = false): this is our own layout of the form's content, NOT compared against the Revenue
 * Department's official form -- do not file or hand it out as the official certificate until that comparison is done.
 * Left blank on purpose: the book/number box (no WHT_CERT numbering is wired), the "ลำดับที่ในแบบ" box, and the
 * signature and company stamp. Only the "(1) หัก ณ ที่จ่าย" payer condition is ticked, since payroll withholds at source.
 */
class Tawi50PdfReport implements ReportGeneratorInterface {
    use PdfRendererTrait;
    use EmployeePiiTrait;

    public function code(): string {
        return 'TH_WHT50';
    }

    public function reportType(): string {
        return 'statutory';
    }

    public function label(): array {
        return ['th' => 'หนังสือรับรองการหักภาษี ณ ที่จ่าย (50 ทวิ) — ฉบับร่าง', 'en' => 'Withholding Tax Certificate (50 Tawi) — Draft'];
    }

    public function isVerified(): bool {
        return false;
    }

    public function supportedFormats(): array {
        return ['pdf'];
    }

    /**
     * @param array $context { comp_id: int, year: int (พ.ศ.), employee_id: int }
     */
    public function generate(array $context, string $format): array {
        if ($format !== 'pdf') {
            throw new LocalizedException('Unsupported format.', 'unsupported_format');
        }
        $doc = $this->prepare($context);
        return ['content' => $this->renderPdfFromHtml($doc['html'], 'A4', 'portrait'), 'file_name' => $doc['file_name'], 'mime_type' => 'application/pdf'];
    }

    /** Validates the context and builds the certificate HTML (kept apart from rendering so the content can be checked without parsing a PDF). @return array{html:string, file_name:string} */
    private function prepare(array $context): array {
        $compId = (int)($context['comp_id'] ?? 0);
        if ($compId <= 0) {
            throw new LocalizedException('comp_id is required.', 'comp_id_required');
        }
        if (!isset($context['year']) || !is_numeric($context['year'])) {
            throw new LocalizedException('year (พ.ศ.) is required and must be numeric.', 'year_required');
        }
        if (!isset($context['employee_id']) || !is_numeric($context['employee_id']) || (int)$context['employee_id'] <= 0) {
            throw new LocalizedException('employee_id is required and must be a positive integer.', 'employee_id_required');
        }
        $yearBe = (int)$context['year'];
        $currentYearBe = (int)date('Y') + 543;
        if ($yearBe < 2500 || $yearBe > $currentYearBe + 1) {
            throw new LocalizedException("year must be a valid Buddhist Era year (2500–{$currentYearBe}).", 'year_out_of_range', ['min' => 2500, 'max' => $currentYearBe]);
        }
        $employeeId = (int)$context['employee_id'];

        $summary = (new AnnualIncomeSummaryModel())->getEmployeeAnnualSummary($employeeId, $yearBe - 543);
        // The model resolves the company from the employee, so a mismatch means someone else's employee.
        if (!$summary || (int)$summary['comp_id'] !== $compId) {
            throw new LocalizedException('Employee not found.', 'employee_not_found');
        }
        if ($summary['sys_run_count'] === 0 && !$summary['has_ytd_included']) {
            throw new LocalizedException("This employee has no payment records in an approved payroll run for B.E. {$yearBe}.", 'no_payment_records_for_year', ['year' => $yearBe]);
        }

        $dataModel = new PayrollReportDataModel();
        $company = $dataModel->getCompany($compId) ?? [];
        $person = $dataModel->getEmployeesPii([$employeeId])[$employeeId] ?? [];

        return [
            'html' => $this->buildHtml($yearBe, $summary, $company, $person),
            'file_name' => 'WHT50_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$summary['employee_no']) . "_{$yearBe}.pdf",
        ];
    }

    private function buildHtml(int $yearBe, array $s, array $company, array $person): string {
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $prefix = ['mr' => 'นาย', 'mrs' => 'นาง', 'ms' => 'นางสาว'][$person['title'] ?? ''] ?? '';
        $employeeName = trim($prefix . ' ' . ($s['name_th'] ?? '') . ' ' . ($s['surname_th'] ?? ''));
        $employeeAddress = trim(($person['address_line_1_register'] ?? '') . ' ' . ($person['address_line_2_register'] ?? ''));
        if ($employeeAddress === '') {
            $employeeAddress = trim(($person['address_line_1_contact'] ?? '') . ' ' . ($person['address_line_2_contact'] ?? ''));
        }
        $companyName = $company['local_name'] ?? $company['company_legal_name'] ?? '';
        $companyAddress = trim(($company['address_line_1'] ?? '') . ' ' . ($company['address_line_2'] ?? ''));
        $nationalId = $this->decryptEmployeeField($person, 'id_card_no') ?? '';
        $signatory = $company['authorized_signatory_name'] ?? '';
        $issueDate = date('d/m/') . ((int)date('Y') + 543);

        $money = fn(float $n) => number_format($n, 2);
        $footnote = '';
        if ($s['has_ytd_included']) {
            $footnote = '<p class="note">* รวมยอดสะสมก่อนเข้าระบบ (YTD) ณ วันที่ ' . $h($this->thaiDate((string)$s['ytd_as_of_date'])) . '</p>';
        }
        $mark = $s['has_ytd_included'] ? ' *' : '';

        return <<<HTML
<html>
<head><style>
body { font-family: 'TH Sarabun New', 'DejaVu Sans', sans-serif; font-size: 14px; }
h1 { font-size: 18px; text-align: center; margin: 0 0 2px 0; }
.sub { text-align: center; margin-bottom: 8px; }
table { width: 100%; border-collapse: collapse; }
td, th { border: 1px solid #555; padding: 4px 6px; vertical-align: top; }
th { background: #eee; }
.box td { border: 1px solid #555; }
.num { text-align: right; }
.cb { display: inline-block; width: 11px; height: 11px; border: 1px solid #000; text-align: center; line-height: 11px; font-size: 11px; }
.sign { margin-top: 24px; text-align: center; }
.note { margin-top: 10px; }
.draft { text-align: center; font-size: 12px; margin-bottom: 6px; }
</style></head>
<body>
<div class="draft">ฉบับร่าง — ยังไม่ได้ตรวจเทียบแบบฟอร์มของกรมสรรพากร ห้ามใช้เป็นหนังสือรับรองจริง</div>
<h1>หนังสือรับรองการหักภาษี ณ ที่จ่าย</h1>
<div class="sub">ตามมาตรา 50 ทวิ แห่งประมวลรัษฎากร — ปีภาษี {$yearBe}</div>
<table class="box">
<tr><td colspan="2">เล่มที่ ........ เลขที่ ........ &nbsp;&nbsp; ลำดับที่ในแบบ ภ.ง.ด.1ก ........</td></tr>
<tr><td colspan="2"><b>ผู้มีหน้าที่หักภาษี ณ ที่จ่าย</b><br>ชื่อ: {$h($companyName)}<br>เลขประจำตัวผู้เสียภาษีอากร: {$h($company['global_tax_id'] ?? '')}<br>ที่อยู่: {$h($companyAddress)}</td></tr>
<tr><td colspan="2"><b>ผู้ถูกหักภาษี ณ ที่จ่าย</b><br>ชื่อ: {$h($employeeName)}<br>เลขประจำตัวประชาชน: {$h($nationalId)}<br>ที่อยู่: {$h($employeeAddress)}</td></tr>
</table>
<br>
<table>
<thead><tr><th>ประเภทเงินได้พึงประเมินที่จ่าย</th><th>ปีภาษีที่จ่าย</th><th>จำนวนเงินที่จ่าย</th><th>ภาษีที่หักและนำส่งไว้</th></tr></thead>
<tbody>
<tr><td>1. เงินเดือน ค่าจ้าง เบี้ยเลี้ยง โบนัส ฯลฯ ตามมาตรา 40 (1){$mark}</td><td>{$yearBe}</td><td class="num">{$money((float)$s['final_gross'])}</td><td class="num">{$money((float)$s['final_tax'])}</td></tr>
<tr><td colspan="2"><b>รวมเงินที่จ่ายและภาษีที่หักนำส่ง</b></td><td class="num">{$money((float)$s['final_gross'])}</td><td class="num">{$money((float)$s['final_tax'])}</td></tr>
<tr><td colspan="4">รวมเงินภาษีที่หักนำส่ง (ตัวอักษร): {$h($this->thaiBahtText((float)$s['final_tax']))}</td></tr>
</tbody>
</table>
<br>
<table>
<tr><td>เงินที่จ่ายเข้ากองทุนสำรองเลี้ยงชีพ</td><td class="num">{$money((float)$s['final_pvd'])}</td><td>บาท</td></tr>
<tr><td>เงินสมทบที่จ่ายเข้ากองทุนประกันสังคม</td><td class="num">{$money((float)$s['final_sso'])}</td><td>บาท</td></tr>
</table>
<p>ผู้จ่ายเงิน &nbsp; <span class="cb">X</span> (1) หัก ณ ที่จ่าย &nbsp; <span class="cb">&nbsp;</span> (2) ออกให้ตลอดไป &nbsp; <span class="cb">&nbsp;</span> (3) ออกให้ครั้งเดียว &nbsp; <span class="cb">&nbsp;</span> (4) อื่น ๆ</p>
{$footnote}
<div class="sign">
ขอรับรองว่าข้อความและตัวเลขข้างต้นถูกต้องตรงกับความจริงทุกประการ<br><br>
ลงชื่อ ................................................ ผู้จ่ายเงิน<br>
({$h($signatory)})<br>
วันที่ออกหนังสือรับรอง {$h($issueDate)}
</div>
</body>
</html>
HTML;
    }

    /** yyyy-mm-dd -> dd/mm/yyyy in the Buddhist Era. */
    private function thaiDate(string $isoDate): string {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $isoDate, $m)) {
            return $isoDate;
        }
        return $m[3] . '/' . $m[2] . '/' . ((int)$m[1] + 543);
    }

    /** 2500.50 -> สองพันห้าร้อยบาทห้าสิบสตางค์ ; 0 -> ศูนย์บาทถ้วน */
    private function thaiBahtText(float $amount): string {
        $totalSatang = (int)round($amount * 100);
        $baht = intdiv($totalSatang, 100);
        $satang = $totalSatang % 100;
        $text = ($baht === 0 ? 'ศูนย์' : $this->thaiNumber($baht)) . 'บาท';
        return $text . ($satang === 0 ? 'ถ้วน' : $this->thaiNumber($satang) . 'สตางค์');
    }

    private function thaiNumber(int $n, bool $afterMillion = false): string {
        if ($n >= 1000000) {
            $rest = $n % 1000000;
            return $this->thaiNumber(intdiv($n, 1000000)) . 'ล้าน' . ($rest > 0 ? $this->thaiNumber($rest, true) : '');
        }
        $digits = ['ศูนย์', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
        $places = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน'];
        $out = '';
        $str = (string)$n;
        $len = strlen($str);
        foreach (str_split($str) as $i => $ch) {
            $d = (int)$ch;
            $place = $len - $i - 1;
            if ($d === 0) {
                continue;
            }
            if ($place === 1) {
                $out .= $d === 1 ? 'สิบ' : ($d === 2 ? 'ยี่สิบ' : $digits[$d] . 'สิบ');
            } elseif ($place === 0) {
                $out .= ($d === 1 && ($len > 1 || $afterMillion)) ? 'เอ็ด' : $digits[$d];
            } else {
                $out .= $digits[$d] . $places[$place];
            }
        }
        return $out;
    }
}
