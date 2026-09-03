<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../EmployeePiiTrait.php';
require_once __DIR__ . '/../PdfRendererTrait.php';
require_once __DIR__ . '/../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../LocalizedException.php';

/**
 * Payroll Register (ทะเบียนรายได้-รายหักพนักงาน) — one row per employee for a run, with a
 * column per distinct earning/deduction line item that appears anywhere in that run (since
 * each company's PED setup differs, the column set is data-driven, not fixed). Internal
 * report — unlike the statutory/payment reports, this is allowed on any run state (including
 * Draft) since HR commonly wants to preview the register before submitting for approval.
 *
 * 2026-08-31, same-day follow-up, explicit request: "Excel ให้ออกมาสรุปเป็น Column By Column
 * พนักงาน รวมถึงรายได้ รายหัก และยอดสุทธิ ให้มี head มี footer มีสรุป และมี Summary ทั้งรอบแยกเป็นอีก
 * sheet สามารถ Export ได้จากหน้า List เอง และหน้า Detail ก็สามารถ Export ได้ครับ" -- rebuilt from a
 * single flat `renderExcelFromRows()` table into a real 2-sheet workbook (custom PhpSpreadsheet
 * code, NOT the shared ExcelRendererTrait -- that trait's plain "headers+rows" shape doesn't fit
 * this report's own header block/footer totals row/2nd sheet, and widening it for this one
 * consumer would complicate it for the other reports still using its simple shape unchanged).
 *
 * 2026-09-02, explicit request: "เพิ่มให้ Export เป็น PDF ได้ด้วย และรองรับ 2 ภาษาเหมือน Report ส่วนอื่น
 * ...เนื่องจากผู้ใช้ในบางที่ อาจต้องมีการ Print ออกมาเพื่อ Check ความถูกต้อง" -- added a PDF branch
 * (landscape A4, `use PdfRendererTrait` for TH Sarabun New Thai glyph support, same trait every
 * statutory/payment PDF report already relies on) reusing the exact SAME $rows/$columnHeaders/
 * $colTotals this class's own Excel branch already builds -- one shared data-assembly pass feeds
 * both output formats, so they can never drift apart on WHAT numbers are shown, only how they're
 * laid out. Every previously-hardcoded Thai string is now resolved through `L()` against
 * `$context['language']` ('th'/'en', defaults 'th' when absent -- same default this report's own
 * Excel output has always effectively had, so every EXISTING caller that never passed a language
 * keeps getting byte-identical Thai output). Reuses the preview-then-choose-language-download
 * modal already built for other reports (`#reportPreviewModal` in payroll/detail.php) -- no new UI
 * pattern needed, just registering 'pdf' in supportedFormats() below.
 */
class PayrollRegisterReport implements ReportGeneratorInterface {
    use EmployeePiiTrait;
    use PdfRendererTrait;

    /** th/en pairs for every fixed (non-data-driven) label this report renders, in EITHER format. */
    private const LABELS = [
        'title' => ['th' => 'ทะเบียนรายได้-รายหักพนักงาน', 'en' => 'Payroll Register'],
        'employee_no' => ['th' => 'รหัสพนักงาน', 'en' => 'Employee No.'],
        'full_name' => ['th' => 'ชื่อ-สกุล', 'en' => 'Full Name'],
        'department' => ['th' => 'แผนก', 'en' => 'Department'],
        'base_salary' => ['th' => 'เงินเดือนฐาน', 'en' => 'Base Salary'],
        'gross_total' => ['th' => 'รายได้รวม', 'en' => 'Total Income'],
        'social_security' => ['th' => 'ประกันสังคม', 'en' => 'Social Security'],
        'provident_fund' => ['th' => 'กองทุนสำรองเลี้ยงชีพ', 'en' => 'Provident Fund'],
        'withholding_tax' => ['th' => 'ภาษีหัก ณ ที่จ่าย', 'en' => 'Withholding Tax'],
        'deduction_total' => ['th' => 'หักรวม', 'en' => 'Total Deductions'],
        'net_total' => ['th' => 'ยอดจ่ายสุทธิ', 'en' => 'Net Pay'],
        'total_row' => ['th' => 'รวม', 'en' => 'Total'],
        'period' => ['th' => 'งวด', 'en' => 'Period'],
        'pay_cycle' => ['th' => 'รอบการจ่าย', 'en' => 'Pay Cycle'],
        'date_range' => ['th' => 'ช่วงเวลา', 'en' => 'Date Range'],
        'payment_date' => ['th' => 'วันที่จ่าย', 'en' => 'Payment Date'],
        'state' => ['th' => 'สถานะ', 'en' => 'Status'],
        'employee_count' => ['th' => 'จำนวนพนักงาน', 'en' => 'Employee Count'],
        'unit_person' => ['th' => 'คน', 'en' => ''],
        'summary_title' => ['th' => 'สรุปยอดรวมทั้งรอบ', 'en' => 'Run Summary'],
        'item' => ['th' => 'รายการ', 'en' => 'Item'],
        'amount' => ['th' => 'จำนวนเงิน', 'en' => 'Amount'],
        'total_base_salary' => ['th' => 'เงินเดือนฐานรวม', 'en' => 'Total Base Salary'],
        'total_income' => ['th' => 'รายได้รวมทั้งสิ้น', 'en' => 'Grand Total Income'],
        'total_deduction' => ['th' => 'หักรวมทั้งสิ้น', 'en' => 'Grand Total Deductions'],
        'total_net' => ['th' => 'ยอดจ่ายสุทธิรวม', 'en' => 'Grand Total Net Pay'],
        'sheet_detail' => ['th' => 'รายละเอียด', 'en' => 'Detail'],
        'sheet_summary' => ['th' => 'สรุป', 'en' => 'Summary'],
        'other_income' => ['th' => 'รายได้อื่น', 'en' => 'Other Income'],
        'other_deduction' => ['th' => 'รายการหักอื่น', 'en' => 'Other Deduction'],
    ];

    private function L(string $key, string $lang): string {
        return self::LABELS[$key][$lang] ?? self::LABELS[$key]['th'] ?? $key;
    }

    public function code(): string {
        return 'PAYROLL_REGISTER';
    }

    public function reportType(): string {
        return 'internal';
    }

    public function label(): array {
        return ['th' => 'ทะเบียนรายได้-รายหักพนักงาน', 'en' => 'Payroll Register'];
    }

    public function isVerified(): bool {
        return true; // this system's own layout, no external form spec claimed
    }

    public function supportedFormats(): array {
        return ['excel', 'pdf'];
    }

    /**
     * @param array $context { comp_id: int, run_id: int, language?: 'th'|'en' }
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
        // 2026-09-02: captured into its own variable BEFORE the ternary re-reads it -- a real bug
        // already caught once elsewhere in this codebase (PaySlipReport::generate(), see that
        // class's own docblock) from writing `in_array(($context['language'] ?? 'th'), [...]) ?
        // $context['language'] : 'th'` -- the true-branch re-reads the RAW array key directly
        // instead of the already-defaulted value, throwing an undefined-array-key warning whenever
        // the key is genuinely absent (every pre-existing caller, since none pass it yet).
        $requestedLanguage = $context['language'] ?? 'th';
        $language = in_array($requestedLanguage, ['th', 'en'], true) ? $requestedLanguage : 'th';

        $dataModel = new PayrollReportDataModel();
        $run = $dataModel->getRun($runId, $compId);
        if (!$run) {
            throw new LocalizedException('Payroll run not found.', 'run_not_found');
        }
        $company = $dataModel->getCompany($compId);
        $details = $dataModel->getRunDetails($runId);
        if (empty($details)) {
            throw new LocalizedException('This payroll run has no calculated employees yet. Recalculate it first.', 'run_no_calculated_employees');
        }

        // Collect the union of distinct earning/deduction line labels across the whole run.
        // 2026-09-02, Deduction Destination & Third-Party Remittance, Phase 7 -- OTHER_INCOME/
        // OTHER_DEDUCTION are a FIXED sentinel code shared by every "Other" item regardless of
        // employee (see PayrollRunModel::resolveManualLineRow()'s own docblock), so this column
        // header must be a stable constant too -- not derived from a line's own name (which would
        // otherwise be whichever employee's own free-text label the loop below happens to reach
        // first, showing a random person's private memo as the column header for everyone else's
        // "Other" entries as well).
        $reservedColLabels = ['OTHER_INCOME' => $this->L('other_income', $language), 'OTHER_DEDUCTION' => $this->L('other_deduction', $language)];
        $lineLabel = function (array $line) use ($language, $reservedColLabels): string {
            if (isset($reservedColLabels[$line['code']])) {
                return $reservedColLabels[$line['code']];
            }
            $key = 'name_' . $language;
            return $line[$key] ?: ($line['name_th'] ?? $line['code']);
        };
        $earningCols = [];
        $deductionCols = [];
        foreach ($details as $d) {
            foreach ($d['earning_breakdown'] as $line) {
                $earningCols[$line['code']] = $lineLabel($line);
            }
            foreach ($d['deduction_breakdown'] as $line) {
                $deductionCols[$line['code']] = $lineLabel($line);
            }
        }
        $statutoryCols = [
            'TH_SSO' => $this->L('social_security', $language),
            'TH_PVD' => $this->L('provident_fund', $language),
            'TH_PIT' => $this->L('withholding_tax', $language),
        ];

        $columnHeaders = [$this->L('employee_no', $language), $this->L('full_name', $language), $this->L('department', $language), $this->L('base_salary', $language)];
        foreach ($earningCols as $colLabel) { $columnHeaders[] = $colLabel; }
        $columnHeaders[] = $this->L('gross_total', $language);
        foreach ($deductionCols as $colLabel) { $columnHeaders[] = $colLabel; }
        foreach ($statutoryCols as $colLabel) { $columnHeaders[] = $colLabel; }
        $columnHeaders[] = $this->L('deduction_total', $language);
        $columnHeaders[] = $this->L('net_total', $language);
        $colCount = count($columnHeaders);
        $firstNumericColIdx = 3; // 0-based: employee_no(0)/full_name(1)/department(2) -> base_salary(3) is the first summable column

        $rows = [];
        // Column totals, same order as $columnHeaders from base_salary onward -- built once here so
        // both output formats' own footer/summary read from this single source, never recomputed
        // twice (a real drift risk if a second format summed the raw $details a second time
        // independently).
        $colTotals = array_fill(0, $colCount - $firstNumericColIdx, 0.0);
        foreach ($details as $d) {
            $row = [
                $d['employee_no'],
                $this->employeeDisplayName($d, $language),
                ($language === 'en' ? ($d['department_name_en'] ?? null) : ($d['department_name_th'] ?? null)) ?? ($d['department_name_th'] ?? ''),
                (float)$d['base_salary_amount'],
            ];
            // 2026-09-02, Phase 7: SUM (not overwrite) same-code lines -- harmless for the
            // pre-existing case (two lines that happen to share an identical CUSTOM:{name} code
            // were always meant to be the exact same item split across installments/sources
            // anyway), but load-bearing now that OTHER_INCOME/OTHER_DEDUCTION genuinely CAN repeat
            // for one employee in one run (e.g. a standing "Other Income" assignment plus an ad-hoc
            // "Other Income" manual line) -- an overwrite here would silently drop one of them from
            // this report despite gross_amount/net_amount elsewhere already including both.
            $earningByCode = [];
            foreach ($d['earning_breakdown'] as $line) { $earningByCode[$line['code']] = ($earningByCode[$line['code']] ?? 0.0) + (float)$line['amount']; }
            foreach (array_keys($earningCols) as $code) { $row[] = $earningByCode[$code] ?? 0.0; }
            $row[] = (float)$d['gross_amount'];

            $deductionByCode = [];
            foreach ($d['deduction_breakdown'] as $line) { $deductionByCode[$line['code']] = ($deductionByCode[$line['code']] ?? 0.0) + (float)$line['amount']; }
            foreach (array_keys($deductionCols) as $code) { $row[] = $deductionByCode[$code] ?? 0.0; }

            $statutoryByCode = [];
            foreach ($d['statutory_breakdown'] as $item) { $statutoryByCode[$item['code']] = (float)$item['employee_amount']; }
            foreach (array_keys($statutoryCols) as $code) { $row[] = $statutoryByCode[$code] ?? 0.0; }

            $row[] = (float)$d['total_deduction_amount'];
            $row[] = (float)$d['net_amount'];
            $rows[] = $row;

            foreach (array_slice($row, $firstNumericColIdx) as $i => $v) {
                $colTotals[$i] += (float)$v;
            }
        }

        $employeeCount = count($details);
        $periodLabel = "{$run['period_start_date']} - {$run['period_end_date']}";
        // Re-derive indices within $colTotals (0-based, starts at base salary) explicitly rather than
        // by fragile arithmetic reuse, so a future column added to either group can't silently shift
        // these without a visible failure.
        $idx = 0;
        $idxBaseSalary = $idx++;
        $idx += count($earningCols);
        $idxGrossTotal = $idx++;
        $idx += count($deductionCols);
        $statutoryStartIdx = $idx;
        $idx += count($statutoryCols);
        $idxDeductionTotal = $idx++;
        $idxNetTotal = $idx++;

        $companyName = $company['local_name'] ?? $company['company_legal_name'] ?? '';

        if ($format === 'pdf') {
            return $this->generatePdf($language, $companyName, $run, $periodLabel, $employeeCount, $columnHeaders, $rows, $colTotals, $firstNumericColIdx, $runId);
        }

        return $this->generateExcel(
            $language, $companyName, $run, $periodLabel, $employeeCount, $columnHeaders, $rows, $colTotals, $firstNumericColIdx,
            $earningCols, $deductionCols, $statutoryCols, $idxBaseSalary, $idxGrossTotal, $statutoryStartIdx, $idxDeductionTotal, $idxNetTotal, $runId
        );
    }

    /** Landscape A4 -- this report can easily have 15-20+ dynamic columns (one per distinct
     *  earning/deduction item across the whole run), portrait would force an unreadably tiny font
     *  or truncate columns off the page. Reuses the SAME $rows/$columnHeaders/$colTotals the Excel
     *  branch already built -- one shared data pass, two renderings. */
    private function generatePdf(string $language, string $companyName, array $run, string $periodLabel, int $employeeCount, array $columnHeaders, array $rows, array $colTotals, int $firstNumericColIdx, int $runId): array {
        $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $fmtNum = fn($v) => number_format((float)$v, 2);

        $html = '<html><head><meta charset="UTF-8"><style>
            body { font-family: "TH Sarabun New", sans-serif; font-size: 11pt; }
            h1 { font-size: 16pt; margin: 0 0 2px; }
            .meta { font-size: 10pt; color: #333; margin-bottom: 8px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #999; padding: 3px 5px; font-size: 8.5pt; }
            th { background: #f0f0f0; font-weight: bold; text-align: center; }
            td.num { text-align: right; }
            tr.total-row td { font-weight: bold; border-top: 2px solid #333; }
        </style></head><body>';
        $html .= '<h1>' . $esc($companyName) . '</h1>';
        $html .= '<div class="meta">' . $esc($this->L('title', $language) . ': ' . $run['run_name']) . '<br>'
            . $esc($this->L('period', $language) . ': ' . $periodLabel . '  |  ' . $this->L('payment_date', $language) . ': ' . $run['payment_date'] . '  |  ' . $this->L('employee_count', $language) . ': ' . $employeeCount . ' ' . $this->L('unit_person', $language))
            . '</div>';
        $html .= '<table><thead><tr>';
        foreach ($columnHeaders as $h) { $html .= '<th>' . $esc($h) . '</th>'; }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $i => $v) {
                $isNum = $i >= $firstNumericColIdx;
                $html .= $isNum ? '<td class="num">' . $esc($fmtNum($v)) . '</td>' : '<td>' . $esc($v) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '<tr class="total-row"><td colspan="' . $firstNumericColIdx . '">' . $esc($this->L('total_row', $language)) . '</td>';
        foreach ($colTotals as $total) { $html .= '<td class="num">' . $esc($fmtNum($total)) . '</td>'; }
        $html .= '</tr></tbody></table></body></html>';

        $content = $this->renderPdfFromHtml($html, 'A4', 'landscape');
        return [
            'content' => $content,
            'file_name' => "PayrollRegister_Run{$runId}.pdf",
            'mime_type' => 'application/pdf',
        ];
    }

    private function generateExcel(
        string $language, string $companyName, array $run, string $periodLabel, int $employeeCount, array $columnHeaders, array $rows, array $colTotals, int $firstNumericColIdx,
        array $earningCols, array $deductionCols, array $statutoryCols, int $idxBaseSalary, int $idxGrossTotal, int $statutoryStartIdx, int $idxDeductionTotal, int $idxNetTotal, int $runId
    ): array {
        $colCount = count($columnHeaders);
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // ==================== Sheet 1: Detail ====================
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($this->L('sheet_detail', $language), 0, 31));
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);

        $sheet->setCellValue('A1', $companyName);
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A2', $this->L('title', $language) . ": {$run['run_name']}");
        $sheet->mergeCells("A2:{$lastColLetter}2");
        $sheet->setCellValue('A3', $this->L('period', $language) . ": {$periodLabel}  |  " . $this->L('payment_date', $language) . ": {$run['payment_date']}  |  " . $this->L('employee_count', $language) . ": {$employeeCount} " . $this->L('unit_person', $language));
        $sheet->mergeCells("A3:{$lastColLetter}3");

        $headerRowIdx = 5;
        foreach (array_values($columnHeaders) as $colIdx => $colLabel) {
            $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1) . $headerRowIdx;
            $this->setCellPreservingType($sheet, $coord, $colLabel);
        }
        $sheet->getStyle("A{$headerRowIdx}:{$lastColLetter}{$headerRowIdx}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRowIdx}:{$lastColLetter}{$headerRowIdx}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FFF0F0F0');

        $dataStartRow = $headerRowIdx + 1;
        foreach ($rows as $rowIdx => $row) {
            foreach (array_values($row) as $colIdx => $value) {
                $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1) . ($dataStartRow + $rowIdx);
                $this->setCellPreservingType($sheet, $coord, $value);
            }
        }

        $footerRow = $dataStartRow + count($rows);
        $this->setCellPreservingType($sheet, "A{$footerRow}", $this->L('total_row', $language));
        $sheet->mergeCells("A{$footerRow}:C{$footerRow}");
        foreach ($colTotals as $i => $total) {
            $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($firstNumericColIdx + $i + 1) . $footerRow;
            $sheet->setCellValue($coord, $total);
        }
        $sheet->getStyle("A{$footerRow}:{$lastColLetter}{$footerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$footerRow}:{$lastColLetter}{$footerRow}")->getBorders()->getTop()
            ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        foreach (range(1, $colCount) as $colIdx) {
            $sheet->getColumnDimensionByColumn($colIdx)->setAutoSize(true);
        }

        // ==================== Sheet 2: Summary ====================
        $summarySheet = $spreadsheet->createSheet();
        $summarySheet->setTitle(substr($this->L('sheet_summary', $language), 0, 31));
        $summarySheet->setCellValue('A1', $companyName);
        $summarySheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $summarySheet->setCellValue('A2', $this->L('summary_title', $language));
        $summarySheet->getStyle('A2')->getFont()->setBold(true);

        $infoRows = [
            [$this->L('period', $language), $run['run_name']],
            [$this->L('pay_cycle', $language), $run['cycle_name'] ?? '-'],
            [$this->L('date_range', $language), $periodLabel],
            [$this->L('payment_date', $language), (string)$run['payment_date']],
            [$this->L('state', $language), (string)$run['state']],
            [$this->L('employee_count', $language), $employeeCount],
        ];
        $r = 4;
        foreach ($infoRows as [$rowLabel, $value]) {
            $this->setCellPreservingType($summarySheet, "A{$r}", $rowLabel);
            $this->setCellPreservingType($summarySheet, "B{$r}", (string)$value);
            $summarySheet->getStyle("A{$r}")->getFont()->setBold(true);
            $r++;
        }

        $r += 1;
        $this->setCellPreservingType($summarySheet, "A{$r}", $this->L('item', $language));
        $this->setCellPreservingType($summarySheet, "B{$r}", $this->L('amount', $language));
        $summarySheet->getStyle("A{$r}:B{$r}")->getFont()->setBold(true);
        $summarySheet->getStyle("A{$r}:B{$r}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FFF0F0F0');
        $r++;

        $totalIncomeLabel = $this->L('total_income', $language);
        $totalDeductionLabel = $this->L('total_deduction', $language);
        $totalNetLabel = $this->L('total_net', $language);
        $summaryLines = [[$this->L('total_base_salary', $language), $colTotals[$idxBaseSalary]]];
        foreach (array_values($earningCols) as $i => $colLabel) {
            $summaryLines[] = [$colLabel, $colTotals[$idxBaseSalary + 1 + $i]];
        }
        $summaryLines[] = [$totalIncomeLabel, $colTotals[$idxGrossTotal]];
        foreach (array_values($deductionCols) as $i => $colLabel) {
            $summaryLines[] = [$colLabel, $colTotals[$idxGrossTotal + 1 + $i]];
        }
        foreach (array_values($statutoryCols) as $i => $colLabel) {
            $summaryLines[] = [$colLabel, $colTotals[$statutoryStartIdx + $i]];
        }
        $summaryLines[] = [$totalDeductionLabel, $colTotals[$idxDeductionTotal]];
        $summaryLines[] = [$totalNetLabel, $colTotals[$idxNetTotal]];

        foreach ($summaryLines as [$rowLabel, $rowAmount]) {
            $this->setCellPreservingType($summarySheet, "A{$r}", $rowLabel);
            $summarySheet->setCellValue("B{$r}", (float)$rowAmount);
            if ($rowLabel === $totalIncomeLabel || $rowLabel === $totalDeductionLabel || $rowLabel === $totalNetLabel) {
                $summarySheet->getStyle("A{$r}:B{$r}")->getFont()->setBold(true);
            }
            $r++;
        }
        $summarySheet->getColumnDimension('A')->setAutoSize(true);
        $summarySheet->getColumnDimension('B')->setAutoSize(true);

        $spreadsheet->setActiveSheetIndex(0);
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = (string)ob_get_clean();

        return [
            'content' => $content,
            'file_name' => "PayrollRegister_Run{$runId}.xlsx",
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }

    /** Same string/numeric type-preservation as ExcelRendererTrait::setCellPreservingType() -- kept
     *  as its own private copy here (not `use`-ing that trait) since this class needs multi-sheet
     *  control the trait's own single-call renderExcelFromRows() doesn't expose. */
    private function setCellPreservingType(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, string $coord, mixed $value): void {
        if (is_string($value)) {
            $sheet->setCellValueExplicit($coord, $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        } else {
            $sheet->setCellValue($coord, $value);
        }
    }
}
