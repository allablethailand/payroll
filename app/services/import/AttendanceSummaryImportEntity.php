<?php
declare(strict_types=1);
require_once __DIR__ . '/AttendanceSummaryValidator.php';
require_once __DIR__ . '/../../models/PayrollRunModel.php';

/**
 * Attendance summary import (entity type `attendance_summary`): each valid row becomes one payroll_run_sync_item_overrides row for the
 * employee on the draft run of that period, tagged with the import batch (import_batch_id) so the batch can be rolled back.
 *
 * Rows are written through PayrollRunModel::writeAttendanceOverride() (the same checks and history as the per-employee screen) without
 * recalculating; afterBatch() then recalculates each touched run once, on commit only. The caller (ImportController) owns the payroll_run.process gate.
 *
 * NOTE for users: recalculate() applies an override only to an employee who has some attendance, leave or overtime data in the pay
 * period (or a sync row) -- the override corrects those figures, it does not create them.
 */
class AttendanceSummaryImportEntity {
    private PDO $db;
    private AttendanceSummaryValidator $validator;
    private PayrollRunModel $runs;
    /** @var array<int, true> run ids written to in the current batch */
    private array $touchedRuns = [];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->validator = new AttendanceSummaryValidator($this->db);
        $this->runs = new PayrollRunModel($this->db);
    }

    public function entityType(): string {
        return 'attendance_summary';
    }

    public function templateColumns(): array {
        return [
            'employee_code' => 'Employee Code', 'period_month' => 'Period Month (1-12)', 'period_year' => 'Period Year (YYYY)',
            'run_code' => 'Run Code (optional, needed if the month has several draft runs)',
            'late_minutes' => 'Late Minutes', 'absent_days' => 'Absent Days', 'unpaid_leave_days' => 'Unpaid Leave Days',
            'ot_working_day_hours' => 'OT Hours - Working Day', 'ot_weekend_hours' => 'OT Hours - Weekend', 'ot_holiday_hours' => 'OT Hours - Holiday',
        ];
    }

    public function importRow(int $compId, array $row, int $batchId, ?int $triggeredBy): array {
        $v = $this->validator->validate($compId, $row);
        $result = $this->runs->writeAttendanceOverride($v['run_id'], $compId, $v['employee_id'], $v['fields'], null, (int)$triggeredBy, $batchId);
        if (empty($result['status'])) {
            throw new InvalidArgumentException((string)($result['message'] ?? 'The row could not be saved.'));
        }
        $this->touchedRuns[$v['run_id']] = true;
        return ['action' => 'inserted'];
    }

    /** Called by ImportService after the row loop. @throws RuntimeException when a recalculation fails (the whole import is then rolled back). */
    public function afterBatch(int $compId, bool $commit, ?int $triggeredBy): void {
        $runIds = array_keys($this->touchedRuns);
        $this->touchedRuns = [];
        if (!$commit) {
            return;
        }
        foreach ($runIds as $runId) {
            $result = $this->runs->recalculate($runId, $compId, (int)$triggeredBy, true);
            if (empty($result['status'])) {
                throw new RuntimeException('Recalculating payroll run ' . $runId . ' failed: ' . ($result['message'] ?? 'unknown error'));
            }
        }
    }
}
