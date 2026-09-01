<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../ExcelRendererTrait.php';
require_once __DIR__ . '/../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../LocalizedException.php';

/**
 * 2026-08-31, same-day follow-up -- Origami's `scheduled_item_occurrences[]` proposal (a
 * per-installment breakdown of an Employee Item, e.g. "LOAN installment 2 of 12"). One row per
 * occurrence received from Origami, across every process for this company, filterable by the
 * `applied_at` date range -- lets finance/HR reconcile "which installments Origami says were
 * actually applied" against this app's own records, independent of whether that process was ever
 * pulled into a payroll run at all. Internal report -- own layout, no external form spec claimed
 * (isVerified() = true is correct here for that reason, not because it's been checked against
 * anything external).
 *
 * Deliberately company-wide/period-wide, not scoped to one run (unlike PayrollRegisterReport) --
 * see PayrollReportDataModel::scheduledItemOccurrences()'s own docblock for why: occurrences
 * belong to the Origami PROCESS, not to whichever run (if any) it ended up pulled into.
 */
class ScheduledItemOccurrenceReconciliationReport implements ReportGeneratorInterface {
    use ExcelRendererTrait;

    public function code(): string {
        return 'SCHEDULED_ITEM_OCCURRENCE_RECONCILIATION';
    }

    public function reportType(): string {
        return 'internal';
    }

    public function label(): array {
        return ['th' => 'กระทบยอดรายการหักตามงวด (Origami)', 'en' => 'Scheduled Item Occurrence Reconciliation'];
    }

    public function isVerified(): bool {
        return true; // this system's own layout, no external form spec claimed
    }

    public function supportedFormats(): array {
        return ['excel'];
    }

    /**
     * @param array $context { comp_id: int, date_from?: string (Y-m-d), date_to?: string (Y-m-d) }
     */
    public function generate(array $context, string $format): array {
        $compId = (int)($context['comp_id'] ?? 0);
        if ($compId <= 0) {
            throw new LocalizedException('comp_id is required.', 'comp_id_required');
        }
        $dateFrom = !empty($context['date_from']) ? (string)$context['date_from'] : null;
        $dateTo = !empty($context['date_to']) ? (string)$context['date_to'] : null;

        $dataModel = new PayrollReportDataModel();
        $rows = $dataModel->scheduledItemOccurrences($compId, $dateFrom, $dateTo);
        if (empty($rows)) {
            throw new LocalizedException('No occurrence data found for this period.', 'no_occurrence_data');
        }

        $headers = ['Employee No', 'Employee Name', 'Item Code', 'Item Ref Code', 'Occurrence Code',
            'Installment No', 'Amount', 'Applied At', 'Process No', 'Linked Run', 'Run State', 'Origami Emp ID'];
        $excelRows = [];
        foreach ($rows as $r) {
            $employeeName = trim(($r['name_th'] ?? '') . ' ' . ($r['surname_th'] ?? ''));
            $excelRows[] = [
                $r['employee_no'] ?? '', $employeeName !== '' ? $employeeName : '(unresolved)',
                $r['item_code'], $r['item_ref_code'] ?? '', $r['occurrence_code'] ?? '',
                $r['installment_no'] !== null ? (int)$r['installment_no'] : '',
                (float)$r['amount'], $r['applied_at'] ?? '',
                $r['process_no'] ?? '', $r['run_name'] ?? '(not yet pulled into a run)', $r['run_state'] ?? '',
                $r['origami_emp_id'] !== null ? (int)$r['origami_emp_id'] : '',
            ];
        }

        $content = $this->renderExcelFromRows($headers, $excelRows, 'Occurrence Reconciliation');
        return [
            'content' => $content,
            'file_name' => "ScheduledItemOccurrenceReconciliation_{$compId}.xlsx",
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }
}
