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
 * 'txt' format to Sso110Exporter. 'pdf'/'excel' are a human-readable summary of this system's own
 * data, not a form reproduction. Requires the run to be Approved/Paid/Locked, same as other
 * statutory reports.
 *
 * 2026-09-05, Phase 12 T071 — Sso110Exporter rewritten against a confirmed reference spec (byte-
 * exact sample + PHP reference implementation the user supplied, see that class's own docblock);
 * the pre-2026-09-05 fixed-width layout (with its one never-resolved sample discrepancy) is gone.
 * The new format has NO header/batch-total row and needs NO employer SSO account/branch/agency
 * code at all — $companyContext below is now built ONLY for the excel/pdf company-name label, no
 * longer fed into the exporter itself. `$prefixMap` corrected from a 2-digit numeric code (the
 * pre-2026-09-05 assumption) to Thai/English TEXT (นาย/นาง/นางสาว), matching what the new sample's
 * own bytes actually decode to.
 *
 * 2026-08-29, explicit follow-up: "รองรับ 2 ภาษาเหมือนกัน และเช็คตรงข้อมูลบริษัทมี Filed เก็บครบหรือยัง" --
 * 'txt' format takes an optional context.language ('th'/'en', default 'th', same convention
 * BankTransferFileReport's own generate() established the same day) covering employee first/last
 * name/prefix (and, for excel/pdf only, the employer name label).
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

    // 2026-09-05, Phase 12 T071: was an unconditional `false` -- the underlying `txt` layout
    // (what this flag is actually meant to gate, per StatutoryFormatVersionModel's own Document
    // Format tab) is now confirmed against a real reference spec, see Sso110Exporter's own
    // docblock. 'excel'/'pdf' were never claimed to be an exact form reproduction either way (see
    // this class's own top docblock), so this flag flipping doesn't change anything about them.
    public function isVerified(): bool {
        return true;
    }

    public function supportedFormats(): array {
        return ['txt', 'excel', 'pdf'];
    }

    /**
     * @param array $context { comp_id: int, run_id: int } OR { comp_id: int, year: int, month: int }
     *        (2026-09-04, Backlog Phase 10, T060 Step D) -- see PndOneReport::generate()'s own
     *        docblock for the full run_id-vs-year+month reasoning (identical shape, this class
     *        mirrors it exactly). Both paths share ONE accumulation loop below, keyed by
     *        employee_id, summing `wage`/`contribution` across every run in scope -- for run_id
     *        (exactly 1 run) this is byte-identical to the pre-T060 direct build. Each run's own
     *        TH_SSO `base_amount`/`employee_amount` already correctly reflects ONLY that run's own
     *        share of the monthly ceiling (T060 Step A's cumulative-cap fix), so summing across a
     *        month's runs already produces the correct true monthly wage/contribution -- no extra
     *        capping logic needed here.
     */
    public function generate(array $context, string $format): array {
        $compId = (int)($context['comp_id'] ?? 0);
        if ($compId <= 0) {
            throw new LocalizedException('comp_id is required.', 'comp_id_required');
        }
        $requestedLanguage = $context['language'] ?? 'th';
        $language = in_array($requestedLanguage, ['th', 'en'], true) ? $requestedLanguage : 'th';
        $dataModel = new PayrollReportDataModel();

        $hasRunId = isset($context['run_id']) && is_numeric($context['run_id']) && (int)$context['run_id'] > 0;
        $hasYearMonth = isset($context['year']) && is_numeric($context['year']) && isset($context['month']) && is_numeric($context['month']);

        if ($hasRunId) {
            $runId = (int)$context['run_id'];
            $run = $dataModel->getRun($runId, $compId);
            if (!$run) {
                throw new LocalizedException('Payroll run not found.', 'run_not_found');
            }
            $dataModel->assertRunStateOrThrow($run, self::ALLOWED_STATES);
            $runsForDetails = [$run];
            $periodYear = (int)date('Y', strtotime($run['period_start_date']));
            $periodMonth = (int)date('n', strtotime($run['period_start_date']));
        } elseif ($hasYearMonth) {
            // 2026-09-04, real bug caught during my own review before shipping: the Reports page's
            // shared Annual-tab year picker (the SAME picker TH_SSO609/TH_PND1K_SUMMARY already use)
            // always sends context['year'] as Buddhist Era -- confirmed by reading Sso609Report's
            // own docblock/generate(), which is fed by this exact same picker. This class's OWN
            // internal $periodContext['year'] (used by the exporter/PDF/Excel labels below) has
            // always been Gregorian AD (derived from the run's own period_start_date, never BE) --
            // so the incoming BE value must be converted to AD here, same -543 convention every
            // other 'annual' report generator in this codebase already applies.
            $yearBe = (int)$context['year'];
            $periodYear = $yearBe - 543;
            $periodMonth = (int)$context['month'];
            if ($periodMonth < 1 || $periodMonth > 12) {
                throw new LocalizedException('month must be between 1 and 12.', 'month_out_of_range');
            }
            $runsForDetails = $dataModel->getRunsInMonth($compId, $periodYear, $periodMonth, self::ALLOWED_STATES);
            if (empty($runsForDetails)) {
                $allowedLabel = implode('/', self::ALLOWED_STATES);
                throw new LocalizedException("No payroll runs in state {$allowedLabel} were found for {$periodMonth}/{$periodYear}.", 'no_runs_in_state_for_month', ['states' => self::ALLOWED_STATES, 'year' => $periodYear, 'month' => $periodMonth]);
            }
        } else {
            throw new LocalizedException('Either run_id or year+month is required.', 'run_id_or_year_month_required');
        }

        // 2026-09-05, Phase 12 T071: SSO 1-10's own prefix field is Thai/English TEXT (นาย/นาง/
        // นางสาว), not a numeric code -- corrects the pre-2026-09-05 version's own 2-digit-code
        // assumption, which the new reference sample directly contradicts (see Sso110Exporter's
        // own docblock). Same map PndOneReport's own $prefixMap uses.
        $prefixMap = [
            'th' => ['mr' => 'นาย', 'mrs' => 'นาง', 'ms' => 'นางสาว'],
            'en' => ['mr' => 'Mr.', 'mrs' => 'Mrs.', 'ms' => 'Ms.'],
        ];

        $employeesAcc = []; // keyed by employee_id, accumulated across every run in $runsForDetails
        $lastPaymentDate = null;
        foreach ($runsForDetails as $r) {
            $lastPaymentDate = $r['payment_date'] ?? $lastPaymentDate;
            foreach ($dataModel->getRunDetails((int)$r['id']) as $d) {
                $empId = (int)$d['employee_id'];
                $ssoAmount = 0.0;
                // 2026-08-29, real correctness gap found and fixed while wiring this up: this
                // used to report base_salary_amount as "wage" regardless of the SSO min/max base
                // clamp (min_base 1,650 / max_base 15,000, see
                // StatutoryCalculationEngine::computeFlatRate()) -- an employee earning above the
                // ceiling would have their FULL uncapped salary reported here even though only the
                // CAPPED amount was actually used to compute their contribution.
                // statutory_breakdown's own TH_SSO line already carries the real, effective
                // (clamped, and since T060 Step A -- monthly-ceiling-aware) base as base_amount --
                // use that instead so the wage figure reported to SSO always matches what the
                // contribution was actually calculated from.
                $ssoWageBase = (float)$d['base_salary_amount'];
                foreach ($d['statutory_breakdown'] as $item) {
                    if ($item['code'] === 'TH_SSO') {
                        $ssoAmount = (float)$item['employee_amount'];
                        if (isset($item['base_amount'])) {
                            $ssoWageBase = (float)$item['base_amount'];
                        }
                    }
                }
                if (!isset($employeesAcc[$empId])) {
                    $employeesAcc[$empId] = [
                        'insured_id' => $this->decryptEmployeeField($d, 'id_card_no') ?? '',
                        'prefix_th' => $prefixMap['th'][$d['title'] ?? ''] ?? '',
                        'prefix_en' => $prefixMap['en'][$d['title'] ?? ''] ?? '',
                        'first_name_th' => $d['name_th'] ?? '',
                        'last_name_th' => $d['surname_th'] ?? '',
                        'first_name_en' => $d['name_en'] ?? '',
                        'last_name_en' => $d['surname_en'] ?? '',
                        'wage' => 0.0,
                        'contribution' => 0.0,
                    ];
                }
                $employeesAcc[$empId]['wage'] += $ssoWageBase;
                $employeesAcc[$empId]['contribution'] += $ssoAmount;
            }
        }
        // Excluded AFTER accumulation, not per-run -- an employee whose SSO ceiling was already
        // exhausted in a LATER run this month (T060 Step A, correctly $ssoAmount=0 for that one
        // run) must still appear here if ANY run this month contributed a real amount; only a
        // TRULY not-enrolled-all-month employee (every run's own contribution is 0) is excluded.
        $employees = array_values(array_filter($employeesAcc, static fn(array $e): bool => $e['contribution'] > 0));
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
        // 2026-09-04, T060 Step D: for the month-aggregated path (multiple runs), there is no
        // single canonical payment_date -- uses the LATEST run's own payment_date in that month as
        // the representative value, same value the run_id path already used when there was only 1
        // run (see PndOneReport::generate()'s own identical comment on this same tradeoff).
        $periodContext = [
            'year' => $periodYear,
            'month' => $periodMonth,
            'payment_date' => $lastPaymentDate,
        ];

        if ($format === 'txt') {
            // 2026-09-05, Phase 12 T071: the new reference format has NO header/batch-total row at
            // all -- just one line per employee -- so $companyContext (employer SSO id/branch/
            // agency code) is no longer part of what Sso110Exporter needs; still built above for
            // excel/pdf's own company-name label below.
            $versionCode = (new StatutoryFormatVersionModel())->resolveVersionCode($compId, $this->code());
            $exporter = new Sso110Exporter();
            $exportRows = array_map(fn($e) => [
                'insured_id' => $e['insured_id'],
                'prefix' => $language === 'en' ? $e['prefix_en'] : $e['prefix_th'],
                'first_name' => $language === 'en' ? $e['first_name_en'] : $e['first_name_th'],
                'last_name' => $language === 'en' ? $e['last_name_en'] : $e['last_name_th'],
                'wage' => $e['wage'],
                'contribution' => $e['contribution'],
            ], $employees);
            $content = $exporter->generate(['period' => $periodContext, 'employees' => $exportRows, 'version_code' => $versionCode]);
            return ['content' => $content, 'file_name' => $exporter->fileName(['period' => $periodContext]), 'mime_type' => 'text/plain'];
        }

        if ($format === 'excel') {
            $headers = ['เลขประกันสังคม', 'คำนำหน้า', 'ชื่อ', 'นามสกุล', 'ค่าจ้าง', 'เงินสมทบ'];
            $rows = array_map(fn($e) => [$e['insured_id'], $language === 'en' ? $e['prefix_en'] : $e['prefix_th'], $language === 'en' ? $e['first_name_en'] : $e['first_name_th'], $language === 'en' ? $e['last_name_en'] : $e['last_name_th'], $e['wage'], $e['contribution']], $employees);
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
            $displayPrefix = $language === 'en' ? $e['prefix_en'] : $e['prefix_th'];
            $displayFirst = $language === 'en' ? $e['first_name_en'] : $e['first_name_th'];
            $displayLast = $language === 'en' ? $e['last_name_en'] : $e['last_name_th'];
            $rowsHtml .= '<tr><td>' . htmlspecialchars($e['insured_id']) . '</td><td>' . htmlspecialchars($displayPrefix . ' ' . $displayFirst . ' ' . $displayLast) . '</td><td class="amount">' . number_format($e['wage'], 2) . '</td><td class="amount">' . number_format($e['contribution'], 2) . '</td></tr>';
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
