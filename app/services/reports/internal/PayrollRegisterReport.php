<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../ExcelRendererTrait.php';
require_once __DIR__ . '/../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../models/PayrollReportDataModel.php';

/**
 * Payroll Register (ทะเบียนรายได้-รายหักพนักงาน) — one row per employee for a run, with a
 * column per distinct earning/deduction line item that appears anywhere in that run (since
 * each company's PED setup differs, the column set is data-driven, not fixed). Internal
 * report — unlike the statutory/payment reports, this is allowed on any run state (including
 * Draft) since HR commonly wants to preview the register before submitting for approval.
 */
class PayrollRegisterReport implements ReportGeneratorInterface {
    use ExcelRendererTrait;
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
            throw new InvalidArgumentException('comp_id is required.');
        }
        if (!isset($context['run_id']) || !is_numeric($context['run_id']) || (int)$context['run_id'] <= 0) {
            throw new InvalidArgumentException('run_id is required and must be a positive integer.');
        }
        $runId = (int)$context['run_id'];

        $dataModel = new PayrollReportDataModel();
        $run = $dataModel->getRun($runId, $compId);
        if (!$run) {
            throw new RuntimeException('Payroll run not found.');
        }
        $details = $dataModel->getRunDetails($runId);
        if (empty($details)) {
            throw new RuntimeException('This payroll run has no calculated employees yet. Recalculate it first.');
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

        $headers = ['รหัสพนักงาน', 'ชื่อ-สกุล', 'แผนก', 'เงินเดือนฐาน'];
        foreach ($earningCols as $label) { $headers[] = $label; }
        $headers[] = 'รายได้รวม';
        foreach ($deductionCols as $label) { $headers[] = $label; }
        foreach ($statutoryCols as $label) { $headers[] = $label; }
        $headers[] = 'หักรวม';
        $headers[] = 'ยอดจ่ายสุทธิ';

        $rows = [];
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
        }

        $content = $this->renderExcelFromRows($headers, $rows, 'Payroll Register ' . $run['id']);
        return [
            'content' => $content,
            'file_name' => "PayrollRegister_Run{$runId}.xlsx",
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }
}
