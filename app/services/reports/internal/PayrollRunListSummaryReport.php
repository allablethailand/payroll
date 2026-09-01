<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../ExcelRendererTrait.php';
require_once __DIR__ . '/../LocalizedException.php';
require_once __DIR__ . '/../../../core/Database.php';

/**
 * 2026-08-31, explicit request: "ในหน้า List และ Detail ของการทำรอบ อยากให้มีการ Export Excel ได้ไม่ว่าจะ
 * สถานะไหน รวมถึงที่ส่งมาจาก Origami ด้วยครับ เป็นตารางเพื่อดึงแต่ละค่า รวมถึงยอดสรุปเพื่อนำออกมา Recheck ข้างนอก"
 * -- the List-page half of that request (the Detail-page half reuses the existing PAYROLL_REGISTER
 * report, see ReportsController::RUN_REPORT_SHORTCUTS's own comment). One row per run currently
 * matching the Process List page's own filters (state/date_from/date_to -- same shape
 * PayrollRunModel::list() already accepts, so this report's own context mirrors that page exactly),
 * plus a totals footer row for external recheck.
 *
 * `internal` reportType(), same as PayrollRegisterReport -- no assertRunStateOrThrow() call
 * anywhere in this class, so every state (including draft) is included, matching "ไม่ว่าจะสถานะไหน"
 * literally. `total_gross_amount`/`total_deduction_amount`/`total_net_amount` are already
 * denormalized columns directly on `payroll_runs` (recalculate() keeps them current) -- no SUM
 * subquery over payroll_run_details needed for the totals themselves.
 */
class PayrollRunListSummaryReport implements ReportGeneratorInterface {
    use ExcelRendererTrait;

    public function code(): string {
        return 'PAYROLL_RUN_LIST_SUMMARY';
    }

    public function reportType(): string {
        return 'internal';
    }

    public function label(): array {
        return ['th' => 'สรุปรายการทำรอบเงินเดือนทั้งหมด', 'en' => 'Payroll Run List Summary'];
    }

    public function isVerified(): bool {
        return true; // this system's own layout, no external form spec claimed
    }

    public function supportedFormats(): array {
        return ['excel'];
    }

    /**
     * @param array $context { comp_id: int, state?: string, date_from?: string, date_to?: string }
     */
    public function generate(array $context, string $format): array {
        $compId = (int)($context['comp_id'] ?? 0);
        if ($compId <= 0) {
            throw new LocalizedException('comp_id is required.', 'comp_id_required');
        }
        $pdo = Database::getInstance()->pdo;

        $where = "WHERE r.comp_id = :comp_id AND r.deleted_at IS NULL";
        $params = [':comp_id' => $compId];
        if (!empty($context['state'])) {
            $where .= " AND r.state = :state";
            $params[':state'] = (string)$context['state'];
        }
        if (!empty($context['date_from'])) {
            $where .= " AND r.period_end_date >= :date_from";
            $params[':date_from'] = (string)$context['date_from'];
        }
        if (!empty($context['date_to'])) {
            $where .= " AND r.period_start_date <= :date_to";
            $params[':date_to'] = (string)$context['date_to'];
        }

        $sql = "SELECT r.id, r.run_name, r.state, r.run_purpose,
                    r.period_start_date, r.period_end_date, r.payment_date,
                    r.employee_count, r.total_gross_amount, r.total_deduction_amount, r.total_net_amount,
                    r.has_validation_errors,
                    c.cycle_name,
                    sp.run_kind AS sync_run_kind, sp.process_subject AS sync_process_subject, sp.process_no AS sync_process_no,
                    r.submitted_at, submitter.name_th AS submitted_by_name_th,
                    r.approved_at, approver.name_th AS approved_by_name_th,
                    r.paid_at, payer.name_th AS paid_by_name_th
                FROM `payroll_runs` r
                LEFT JOIN `payroll_cycles` c ON c.id = r.cycle_id
                LEFT JOIN `payroll_sync_processes` sp ON sp.id = r.sync_process_id
                LEFT JOIN `employees` submitter ON submitter.id = r.submitted_by
                LEFT JOIN `employees` approver ON approver.id = r.approved_by
                LEFT JOIN `employees` payer ON payer.id = r.paid_by
                {$where}
                ORDER BY r.period_start_date DESC, r.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($runs)) {
            throw new LocalizedException('No payroll runs match the current filter.', 'run_list_empty');
        }

        $headers = [
            'Run Name', 'Origin', 'State', 'Period Start', 'Period End', 'Payment Date',
            'Employee Count', 'Gross Amount', 'Deduction Amount', 'Net Amount', 'Has Errors',
            'Submitted At', 'Submitted By', 'Approved At', 'Approved By', 'Paid At', 'Paid By',
        ];
        $rows = [];
        $totalGross = 0.0;
        $totalDeduction = 0.0;
        $totalNet = 0.0;
        foreach ($runs as $r) {
            // 2026-08-31: "รวมถึงที่ส่งมาจาก Origami ด้วย" -- origin column distinguishes manual
            // (off-cycle, no cycle/sync at all), cycle (a normal recurring payroll cycle, no sync),
            // and sync (pulled from Origami, further tagged regular/supplemental via sync_run_kind).
            if (!empty($r['sync_run_kind'])) {
                $origin = 'Sync (' . $r['sync_run_kind'] . ($r['sync_process_no'] ? ', ' . $r['sync_process_no'] : '') . ')';
            } elseif (!empty($r['cycle_name'])) {
                $origin = 'Cycle (' . $r['cycle_name'] . ')';
            } else {
                $origin = 'Manual/Off-cycle' . ($r['run_purpose'] === 'incentive' ? ' (Incentive)' : '');
            }
            $gross = (float)$r['total_gross_amount'];
            $deduction = (float)$r['total_deduction_amount'];
            $net = (float)$r['total_net_amount'];
            $totalGross += $gross;
            $totalDeduction += $deduction;
            $totalNet += $net;
            $rows[] = [
                $r['run_name'],
                $origin,
                $r['state'],
                $r['period_start_date'],
                $r['period_end_date'],
                $r['payment_date'],
                (int)$r['employee_count'],
                $gross,
                $deduction,
                $net,
                (int)$r['has_validation_errors'] === 1 ? 'Yes' : 'No',
                $r['submitted_at'],
                $r['submitted_by_name_th'],
                $r['approved_at'],
                $r['approved_by_name_th'],
                $r['paid_at'],
                $r['paid_by_name_th'],
            ];
        }
        $rows[] = array_fill(0, count($headers), '');
        $totalsRow = array_fill(0, count($headers), '');
        $totalsRow[0] = 'TOTAL (' . count($runs) . ' runs)';
        $totalsRow[7] = $totalGross;
        $totalsRow[8] = $totalDeduction;
        $totalsRow[9] = $totalNet;
        $rows[] = $totalsRow;

        $content = $this->renderExcelFromRows($headers, $rows, 'Payroll Run List Summary');
        return [
            'content' => $content,
            'file_name' => 'PayrollRunListSummary_Comp' . $compId . '_' . date('Ymd_His') . '.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
    }
}
