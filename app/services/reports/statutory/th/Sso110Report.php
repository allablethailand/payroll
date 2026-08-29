<?php
declare(strict_types=1);
require_once __DIR__ . '/../../ReportGeneratorInterface.php';
require_once __DIR__ . '/../../ExcelRendererTrait.php';
require_once __DIR__ . '/../../PdfRendererTrait.php';
require_once __DIR__ . '/../../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../export/th/Sso110Exporter.php';
require_once __DIR__ . '/../../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../../../models/StatutoryFormatVersionModel.php';
require_once __DIR__ . '/../../LocalizedException.php';

/**
 * สปส.1-10 monthly contribution report — one payroll run = one month's submission. Delegates
 * 'txt' format to Sso110Exporter (2026-08-29, rewritten against a real sample the user supplied
 * directly — see that class's own docblock for the full field-layout derivation and its one
 * unresolved sample discrepancy). 'pdf'/'excel' are a human-readable summary of this system's
 * own data, not a form reproduction. Requires the run to be Approved/Paid/Locked, same as other
 * statutory reports.
 *
 * 2026-08-29, explicit follow-up: "รองรับ 2 ภาษาเหมือนกัน และเช็คตรงข้อมูลบริษัทมี Filed เก็บครบหรือยัง" --
 * 'txt' format now takes an optional context.language ('th'/'en', default 'th', same convention
 * BankTransferFileReport's own generate() established the same day) covering employer name and
 * employee first/last name. Company-field completeness check (as of this same round):
 *   - Employer SSO account no. (companies.statutory_data.th_sso_id) -- ALREADY existed (a
 *     required field on the Company Profile form's own country-specific section), just never
 *     actually consumed by this report before now.
 *   - SSO agency/branch code -- resolved from structure_branches.sso_branch_code (ALREADY
 *     existed, Organizational Structure's own Branch tab) for the company's own is_default=1
 *     branch. Genuinely new consumer, not a new field.
 *   - Branch sequence number (the sample's own "0001") -- resolved from that SAME default
 *     branch's branch_code if it looks like a short numeric code, else falls back to a "0001"
 *     constant (a single-branch company's own SSO filing is virtually always sequence 1) -- no
 *     dedicated field exists for this specific 4-digit sequence concept, and the sample alone
 *     doesn't distinguish "always 0001" from "this company's own real branch sequence", so this
 *     is a best-effort default, not a confirmed mapping.
 */
class Sso110Report implements ReportGeneratorInterface {
    use ExcelRendererTrait;
    use PdfRendererTrait;
    use EmployeePiiTrait;

    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];

    public function code(): string {
        return 'TH_SSO110';
    }

    public function reportType(): string {
        return 'statutory';
    }

    public function label(): array {
        return ['th' => 'สปส.1-10 (นำส่งเงินสมทบ)', 'en' => 'SSO 1-10 (Monthly Contribution)'];
    }

    public function isVerified(): bool {
        return false;
    }

    public function supportedFormats(): array {
        return ['txt', 'excel', 'pdf'];
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

        // 2026-08-29: SSO's own prefix-code convention (widely-used Thai government-form
        // standard, matching the sample's own confirmed "03 = นาย") -- see Sso110Exporter's own
        // docblock for which of these 3 the sample itself actually confirms.
        $prefixCodeMap = ['mr' => '03', 'mrs' => '04', 'ms' => '05'];

        $employees = [];
        foreach ($details as $d) {
            $ssoAmount = 0.0;
            // 2026-08-29, real correctness gap found and fixed while wiring this up: this used to
            // report base_salary_amount as "wage" regardless of the SSO min/max base clamp
            // (min_base 1,650 / max_base 15,000, see StatutoryCalculationEngine::computeFlatRate())
            // -- an employee earning above the ceiling would have their FULL uncapped salary
            // reported here even though only the CAPPED amount was actually used to compute their
            // contribution. statutory_breakdown's own TH_SSO line already carries the real,
            // effective (clamped) base as base_amount -- use that instead so the wage figure
            // reported to SSO always matches what the contribution was actually calculated from.
            $ssoWageBase = (float)$d['base_salary_amount'];
            foreach ($d['statutory_breakdown'] as $item) {
                if ($item['code'] === 'TH_SSO') {
                    $ssoAmount = (float)$item['employee_amount'];
                    if (isset($item['base_amount'])) {
                        $ssoWageBase = (float)$item['base_amount'];
                    }
                }
            }
            if ($ssoAmount <= 0) {
                continue; // not SSO-enrolled this period, excluded from the submission
            }
            $employees[] = [
                'insured_id' => $this->decryptEmployeeField($d, 'id_card_no') ?? '',
                'prefix_code' => $prefixCodeMap[$d['title'] ?? ''] ?? '',
                'first_name_th' => $d['name_th'] ?? '',
                'last_name_th' => $d['surname_th'] ?? '',
                'first_name_en' => $d['name_en'] ?? '',
                'last_name_en' => $d['surname_en'] ?? '',
                'wage' => $ssoWageBase,
                'contribution' => $ssoAmount,
            ];
        }
        if (empty($employees)) {
            throw new LocalizedException('No SSO-enrolled employees with a contribution were found in this payroll run.', 'sso_no_enrolled_employees');
        }

        $company = $dataModel->getCompany($compId);
        $statutoryData = json_decode((string)($company['statutory_data'] ?? '{}'), true) ?: [];
        // 2026-08-29: company's own default branch (structure_branches.is_default=1) -- see this
        // class's own top-of-file docblock for exactly what's resolved from it and why.
        $defaultBranch = null;
        $stmtBranch = Database::getInstance()->pdo->prepare(
            "SELECT branch_code, sso_branch_code FROM `structure_branches`
             WHERE comp_id = :comp_id AND deleted_at IS NULL AND status = 'active' AND is_default = 1
             ORDER BY id ASC LIMIT 1"
        );
        $stmtBranch->execute([':comp_id' => $compId]);
        $defaultBranch = $stmtBranch->fetch(PDO::FETCH_ASSOC) ?: null;
        $branchCode = (string)($defaultBranch['branch_code'] ?? '');
        $branchSeq = (ctype_digit($branchCode) && strlen($branchCode) <= 4) ? $branchCode : '0001';

        $companyContext = [
            'employer_account' => $statutoryData['th_sso_id'] ?? '',
            'branch_seq' => $branchSeq,
            'sso_agency_code' => $defaultBranch['sso_branch_code'] ?? '',
            'name_th' => $company['local_name'] ?? $company['company_legal_name'] ?? '',
            'name_en' => $company['company_legal_name'] ?? $company['local_name'] ?? '',
        ];
        $periodContext = [
            'year' => (int)date('Y', strtotime($run['period_start_date'])),
            'month' => (int)date('n', strtotime($run['period_start_date'])),
            'payment_date' => $run['payment_date'],
        ];

        if ($format === 'txt') {
            // 2026-08-29 -- resolves this company's chosen document format version (Tax &
            // Statutory settings' "Document Format" tab), falling back to the form's default
            // version when the company never set one. See StatutoryFormatVersionModel's own
            // docblock; Sso110Exporter validates this against what it actually implements.
            $versionCode = (new StatutoryFormatVersionModel())->resolveVersionCode($compId, $this->code());
            $exporter = new Sso110Exporter();
            $content = $exporter->generate(['company' => $companyContext, 'period' => $periodContext, 'employees' => $employees, 'version_code' => $versionCode, 'language' => $language]);
            return ['content' => $content, 'file_name' => $exporter->fileName(['period' => $periodContext]), 'mime_type' => 'text/plain'];
        }

        if ($format === 'excel') {
            $headers = ['เลขประกันสังคม', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'ค่าจ้าง', 'เงินสมทบ'];
            $rows = array_map(fn($e) => [$e['insured_id'], $e['prefix_code'], $language === 'en' ? $e['first_name_en'] : $e['first_name_th'], $language === 'en' ? $e['last_name_en'] : $e['last_name_th'], $e['wage'], $e['contribution']], $employees);
            $content = $this->renderExcelFromRows($headers, $rows, "SSO110 {$periodContext['year']}-{$periodContext['month']}");
            return ['content' => $content, 'file_name' => "SSO110_Summary_{$periodContext['year']}{$periodContext['month']}.xlsx", 'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }

        // pdf
        $rowsHtml = '';
        $totalWage = 0.0;
        $totalContribution = 0.0;
        foreach ($employees as $e) {
            $totalWage += $e['wage'];
            $totalContribution += $e['contribution'];
            $displayFirst = $language === 'en' ? $e['first_name_en'] : $e['first_name_th'];
            $displayLast = $language === 'en' ? $e['last_name_en'] : $e['last_name_th'];
            $rowsHtml .= '<tr><td>' . htmlspecialchars($e['insured_id']) . '</td><td>' . htmlspecialchars($e['prefix_code'] . ' ' . $displayFirst . ' ' . $displayLast) . '</td><td class="amount">' . number_format($e['wage'], 2) . '</td><td class="amount">' . number_format($e['contribution'], 2) . '</td></tr>';
        }
        $companyName = htmlspecialchars($language === 'en' ? $companyContext['name_en'] : $companyContext['name_th']);
        $periodLabel = "{$periodContext['month']}/{$periodContext['year']}";
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
<div>สปส.1-10 สรุปเงินสมทบ งวด {$periodLabel}</div>
<table>
<thead><tr><th>เลขประกันสังคม</th><th>ชื่อ-สกุล</th><th>ค่าจ้าง</th><th>เงินสมทบ</th></tr></thead>
<tbody>{$rowsHtml}</tbody>
<tfoot><tr><td colspan="2">รวม</td><td class="amount">{$this->fmt($totalWage)}</td><td class="amount">{$this->fmt($totalContribution)}</td></tr></tfoot>
</table>
</body></html>
HTML;
        $content = $this->renderPdfFromHtml($html, 'A4', 'landscape');
        return ['content' => $content, 'file_name' => "SSO110_Summary_{$periodContext['year']}{$periodContext['month']}.pdf", 'mime_type' => 'application/pdf'];
    }

    private function fmt(float $n): string {
        return number_format($n, 2);
    }
}
