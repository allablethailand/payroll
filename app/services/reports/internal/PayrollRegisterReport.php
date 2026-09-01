<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../EmployeePiiTrait.php';
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
 * consumer would complicate it for the other reports still using its simple shape unchanged):
 *   Sheet 1 "รายละเอียด" (Detail): company/run/period header block, then the same per-employee
 *     column-by-column breakdown as before, then a bold "รวม" (Total) footer row summing every
 *     numeric column.
 *   Sheet 2 "สรุป" (Summary): run-level recap independent of scrolling through Sheet 1 --
 *     company/cycle/period/state, employee count, and grand totals (gross/each earning category/
 *     each deduction category/each statutory category/net), sourced from the SAME already-computed
 *     column totals Sheet 1's own footer uses, not a second independent calculation.
 * Trigger points: the List page's own per-row Export action (any run, any state) and the Detail
 * page's existing Reports tab download button both call this exact same generate() — same
 * context shape as before ({comp_id, run_id}), no interface/registry change needed.
 */
class PayrollRegisterReport implements ReportGeneratorInterface {
    use EmployeePiiTrait;

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
        return ['excel'];
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
        $earningCols = [];
        $deductionCols = [];
        foreach ($details as $d) {
            foreach ($d['earning_breakdown'] as $line) {
                $earningCols[$line['code']] = $line['name_th'] ?? $line['code'];
            }
            foreach ($d['deduction_breakdown'] as $line) {
                $deductionCols[$line['code']] = $line['name_th'] ?? $line['code'];
            }
        }
        $statutoryCols = ['TH_SSO' => 'ประกันสังคม', 'TH_PVD' => 'กองทุนสำรองเลี้ยงชีพ', 'TH_PIT' => 'ภาษีหัก ณ ที่จ่าย'];

        $columnHeaders = ['รหัสพนักงาน', 'ชื่อ-สกุล', 'แผนก', 'เงินเดือนฐาน'];
        foreach ($earningCols as $label) { $columnHeaders[] = $label; }
        $columnHeaders[] = 'รายได้รวม';
        foreach ($deductionCols as $label) { $columnHeaders[] = $label; }
        foreach ($statutoryCols as $label) { $columnHeaders[] = $label; }
        $columnHeaders[] = 'หักรวม';
        $columnHeaders[] = 'ยอดจ่ายสุทธิ';
        $colCount = count($columnHeaders);
        $firstNumericColIdx = 3; // 0-based: รหัสพนักงาน(0)/ชื่อ-สกุล(1)/แผนก(2) -> เงินเดือนฐาน(3) is the first summable column

        $rows = [];
        // Column totals, same order as $columnHeaders from เงินเดือนฐาน onward -- built once here so
        // both Sheet 1's own footer row AND Sheet 2's summary read from this single source, never
        // recomputed twice (a real drift risk if Sheet 2 summed the raw $details a second time
        // independently).
        $colTotals = array_fill(0, $colCount - $firstNumericColIdx, 0.0);
        foreach ($details as $d) {
            $row = [
                $d['employee_no'],
                $this->employeeDisplayName($d, 'th'),
                $d['department_name_th'] ?? '',
                (float)$d['base_salary_amount'],
            ];
            $earningByCode = [];
            foreach ($d['earning_breakdown'] as $line) { $earningByCode[$line['code']] = (float)$line['amount']; }
            foreach (array_keys($earningCols) as $code) { $row[] = $earningByCode[$code] ?? 0.0; }
            $row[] = (float)$d['gross_amount'];

            $deductionByCode = [];
            foreach ($d['deduction_breakdown'] as $line) { $deductionByCode[$line['code']] = (float)$line['amount']; }
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

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // ==================== Sheet 1: Detail ====================
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr('รายละเอียด', 0, 31));
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colCount);

        $sheet->setCellValue('A1', $company['local_name'] ?? $company['company_legal_name'] ?? '');
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A2', "ทะเบียนรายได้-รายหักพนักงาน: {$run['run_name']}");
        $sheet->mergeCells("A2:{$lastColLetter}2");
        $sheet->setCellValue('A3', "งวด: {$periodLabel}  |  วันที่จ่าย: {$run['payment_date']}  |  จำนวนพนักงาน: {$employeeCount} คน");
        $sheet->mergeCells("A3:{$lastColLetter}3");

        $headerRowIdx = 5;
        foreach (array_values($columnHeaders) as $colIdx => $label) {
            $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx + 1) . $headerRowIdx;
            $this->setCellPreservingType($sheet, $coord, $label);
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
        $this->setCellPreservingType($sheet, "A{$footerRow}", 'รวม');
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
        $summarySheet->setTitle(substr('สรุป', 0, 31));
        $summarySheet->setCellValue('A1', $company['local_name'] ?? $company['company_legal_name'] ?? '');
        $summarySheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $summarySheet->setCellValue('A2', 'สรุปยอดรวมทั้งรอบ');
        $summarySheet->getStyle('A2')->getFont()->setBold(true);

        $infoRows = [
            ['งวด', $run['run_name']],
            ['รอบการจ่าย', $run['cycle_name'] ?? '-'],
            ['ช่วงเวลา', $periodLabel],
            ['วันที่จ่าย', (string)$run['payment_date']],
            ['สถานะ', (string)$run['state']],
            ['จำนวนพนักงาน', $employeeCount],
        ];
        $r = 4;
        foreach ($infoRows as [$label, $value]) {
            $this->setCellPreservingType($summarySheet, "A{$r}", $label);
            $this->setCellPreservingType($summarySheet, "B{$r}", (string)$value);
            $summarySheet->getStyle("A{$r}")->getFont()->setBold(true);
            $r++;
        }

        $r += 1;
        $this->setCellPreservingType($summarySheet, "A{$r}", 'รายการ');
        $this->setCellPreservingType($summarySheet, "B{$r}", 'จำนวนเงิน');
        $summarySheet->getStyle("A{$r}:B{$r}")->getFont()->setBold(true);
        $summarySheet->getStyle("A{$r}:B{$r}")->getFill()
            ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FFF0F0F0');
        $r++;

        $summaryLines = [['เงินเดือนฐานรวม', $colTotals[$idxBaseSalary]]];
        foreach (array_values($earningCols) as $i => $label) {
            $summaryLines[] = [$label, $colTotals[$idxBaseSalary + 1 + $i]];
        }
        $summaryLines[] = ['รายได้รวมทั้งสิ้น', $colTotals[$idxGrossTotal]];
        foreach (array_values($deductionCols) as $i => $label) {
            $summaryLines[] = [$label, $colTotals[$idxGrossTotal + 1 + $i]];
        }
        foreach (array_values($statutoryCols) as $i => $label) {
            $summaryLines[] = [$label, $colTotals[$statutoryStartIdx + $i]];
        }
        $summaryLines[] = ['หักรวมทั้งสิ้น', $colTotals[$idxDeductionTotal]];
        $summaryLines[] = ['ยอดจ่ายสุทธิรวม', $colTotals[$idxNetTotal]];

        foreach ($summaryLines as [$label, $amount]) {
            $this->setCellPreservingType($summarySheet, "A{$r}", $label);
            $summarySheet->setCellValue("B{$r}", (float)$amount);
            if ($label === 'รายได้รวมทั้งสิ้น' || $label === 'หักรวมทั้งสิ้น' || $label === 'ยอดจ่ายสุทธิรวม') {
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
