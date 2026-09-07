<?php
declare(strict_types=1);
require_once __DIR__ . '/../../ReportGeneratorInterface.php';
require_once __DIR__ . '/../../ExcelRendererTrait.php';
require_once __DIR__ . '/../../PdfRendererTrait.php';
require_once __DIR__ . '/../../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../export/th/Sso609Exporter.php';
require_once __DIR__ . '/../../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../LocalizedException.php';

/**
 * สปส.6-09 (แจ้งการสิ้นสุดความเป็นผู้ประกันตน / SSO termination notice).
 *
 * Lists employees whose `employees.employment_end_date` falls in the requested month — not
 * derived from any payroll_run, so there is no Approved/Paid/Locked state gate here (this is
 * an HR/employee-master fact, not a calculated payroll figure).
 *
 * 2026-09-05, Phase 12 T071 — 'txt' format added via the new Sso609Exporter (see that class's own
 * docblock, including its one genuinely unconfirmed piece: the termination-REASON code mapping,
 * which is a best-effort heuristic over a free-text column with no real seeded data to confirm
 * against). Before this, this report had no field-layout basis for a txt export at all — the
 * user's reference materials are the first. `id_card_no` (decrypted here, distinct from the
 * pre-existing `sso_no` field the PDF/Excel formats below already use) and a prefix-included full
 * name are built specifically for the txt path, matching the spec's own field labels exactly
 * ("เลขประจำตัวประชาชน", one combined "คำนำหน้า-ชื่อ-สกุล" field) — the pre-existing PDF/Excel fields
 * are untouched.
 */
class Sso609Report implements ReportGeneratorInterface {
    use ExcelRendererTrait;
    use PdfRendererTrait;
    use EmployeePiiTrait;

    public function code(): string {
        return 'TH_SSO609';
    }

    public function reportType(): string {
        return 'statutory';
    }

    public function label(): array {
        return ['th' => 'สปส.6-09 (แจ้งสิ้นสุดผู้ประกันตน)', 'en' => 'SSO 6-09 (Termination Notice)'];
    }

    // 2026-09-05, Phase 12 T071: was an unconditional `false` -- the underlying `txt` layout is
    // now confirmed against a real reference spec, see Sso609Exporter's own docblock (which also
    // flags the one still-unconfirmed piece, the reason-code heuristic).
    public function isVerified(): bool {
        return true;
    }

    public function supportedFormats(): array {
        return ['excel', 'pdf', 'txt'];
    }

    /**
     * @param array $context { comp_id: int, year: int (พ.ศ.), month: int (1-12) }
     */
    public function generate(array $context, string $format): array {
        $compId = (int)($context['comp_id'] ?? 0);
        if ($compId <= 0) {
            throw new LocalizedException('comp_id is required.', 'comp_id_required');
        }
        if (!isset($context['year']) || !is_numeric($context['year'])) {
            throw new LocalizedException('year (พ.ศ.) is required and must be numeric.', 'year_required');
        }
        if (!isset($context['month']) || !is_numeric($context['month']) || (int)$context['month'] < 1 || (int)$context['month'] > 12) {
            throw new LocalizedException('month is required and must be between 1-12.', 'month_required');
        }
        $yearBe = (int)$context['year'];
        $month = (int)$context['month'];
        $yearAd = $yearBe - 543;

        $dataModel = new PayrollReportDataModel();
        $employees = $dataModel->getResignedEmployeesInMonth($compId, $yearAd, $month);
        if (empty($employees)) {
            throw new LocalizedException("No employees with an employment end date in {$month}/{$yearBe} were found.", 'no_resignations_in_month', ['month' => $month, 'year' => $yearBe]);
        }

        $rows = [];
        $exportRows = [];
        $prefixTh = ['mr' => 'นาย', 'mrs' => 'นาง', 'ms' => 'นางสาว'];
        foreach ($employees as $e) {
            $rows[] = [
                $this->decryptEmployeeField($e, 'sso_no') ?? '',
                $e['title'] ?? '',
                $e['name_th'] ?? '',
                $e['surname_th'] ?? '',
                $e['department_name_th'] ?? '',
                $e['employment_end_date'],
            ];
            // 2026-09-05, Phase 12 T071 -- built for the new txt path only, see this class's own
            // top-of-file docblock.
            $exportRows[] = [
                'citizen_id' => $this->decryptEmployeeField($e, 'id_card_no') ?? '',
                'full_name' => trim(($prefixTh[$e['title'] ?? ''] ?? '') . ' ' . ($e['name_th'] ?? '') . ' ' . ($e['surname_th'] ?? '')),
                'leave_date' => $e['employment_end_date'],
                'reason' => $e['employment_end_reason'] ?? null,
            ];
        }

        if ($format === 'txt') {
            $exporter = new Sso609Exporter();
            $periodContext = ['year' => $yearBe, 'month' => $month];
            $content = $exporter->generate(['employees' => $exportRows]);
            return ['content' => $content, 'file_name' => $exporter->fileName(['period' => $periodContext]), 'mime_type' => 'text/plain'];
        }

        if ($format === 'excel') {
            $headers = ['เลขประกันสังคม', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'แผนก', 'วันที่สิ้นสุดการเป็นผู้ประกันตน'];
            $content = $this->renderExcelFromRows($headers, $rows, "SSO609 {$month}-{$yearBe}");
            return ['content' => $content, 'file_name' => sprintf('SSO609_%04d%02d.xlsx', $yearBe, $month), 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }

        // pdf
        $dataModelCompany = $dataModel->getCompany($compId);
        $companyName = htmlspecialchars($dataModelCompany['local_name'] ?? $dataModelCompany['company_legal_name'] ?? '');
        $rowsHtml = '';
        foreach ($rows as $r) {
            $rowsHtml .= '<tr><td>' . htmlspecialchars((string)$r[0]) . '</td><td>' . htmlspecialchars($r[1] . ' ' . $r[2] . ' ' . $r[3]) . '</td><td>' . htmlspecialchars((string)$r[4]) . '</td><td>' . htmlspecialchars((string)$r[5]) . '</td></tr>';
        }
        $html = <<<HTML
<html><head><style>
body { font-family: 'TH Sarabun New', 'DejaVu Sans', sans-serif; font-size: 14px; }
table { width: 100%; border-collapse: collapse; margin-top: 10px; }
th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
th { background: #f0f0f0; }
</style></head><body>
<h1>{$companyName}</h1>
<div>สปส.6-09 รายชื่อผู้ประกันตนที่สิ้นสุดสภาพ งวด {$month}/{$yearBe}</div>
<table>
<thead><tr><th>เลขประกันสังคม</th><th>ชื่อ-สกุล</th><th>แผนก</th><th>วันที่สิ้นสุด</th></tr></thead>
<tbody>{$rowsHtml}</tbody>
</table>
</body></html>
HTML;
        $content = $this->renderPdfFromHtml($html, 'A4', 'portrait');
        return ['content' => $content, 'file_name' => sprintf('SSO609_%04d%02d.pdf', $yearBe, $month), 'mime_type' => 'application/pdf'];
    }
}
