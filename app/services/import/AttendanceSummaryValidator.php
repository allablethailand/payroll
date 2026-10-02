<?php
declare(strict_types=1);
require_once __DIR__ . '/ImportTargetResolver.php';

/**
 * Rules for one attendance-summary import row (entity attendance_summary): per-employee, per-payroll-run correction figures that land in
 * payroll_run_sync_item_overrides. The employee and the draft run are resolved by ImportTargetResolver (same hybrid run resolution as the
 * ad-hoc item import). Every figure is optional but at least one is required, and each must be a number of 0 or more. An employee who already
 * has an override row on the run is refused, so the import only ever creates rows and a batch can be rolled back by deleting them.
 */
class AttendanceSummaryValidator {
    /** file column => payroll_run_sync_item_overrides column, and the largest value the column's DECIMAL can hold */
    public const FIELD_MAP = [
        'late_minutes' => ['late_mins', 999999.99],
        'absent_days' => ['absent_days', 9999.99],
        'unpaid_leave_days' => ['leave_without_pay_days', 9999.99],
        'ot_working_day_hours' => ['ot_req_working_day_hrs', 999999.99],
        'ot_weekend_hours' => ['ot_req_weekend_hrs', 999999.99],
        'ot_holiday_hours' => ['ot_req_holiday_hrs', 999999.99],
    ];

    private PDO $db;
    private ImportTargetResolver $targets;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->targets = new ImportTargetResolver($this->db);
    }

    /** @return array{employee_id:int, employee_no:string, run_id:int, fields: array<string, float>}  fields keyed by override column, only the figures given
     *  @throws ImportRowValidationException */
    public function validate(int $compId, array $row): array {
        $v = [];
        foreach (array_merge(['employee_code', 'period_month', 'period_year', 'run_code'], array_keys(self::FIELD_MAP)) as $key) {
            $cell = $row[$key] ?? null;
            $v[$key] = $cell === null ? '' : trim((string)$cell);
        }

        $employee = $this->targets->employee($compId, $v['employee_code']);

        $fields = [];
        foreach (self::FIELD_MAP as $column => [$overrideColumn, $max]) {
            if ($v[$column] === '') {
                continue;
            }
            $raw = str_replace([',', ' '], '', $v[$column]);
            if (!is_numeric($raw) || (float)$raw < 0 || (float)$raw > $max) {
                throw new ImportRowValidationException('value_invalid', "{$column} must be a number between 0 and {$max}.");
            }
            $fields[$overrideColumn] = round((float)$raw, 2);
        }
        if (!$fields) {
            throw new ImportRowValidationException('no_values', 'At least one of ' . implode(', ', array_keys(self::FIELD_MAP)) . ' is required.');
        }

        $run = $this->targets->draftRun($compId, $v['period_month'], $v['period_year'], $v['run_code']);

        // Without this the override would sit on a run the employee is not part of and never affect any pay (a normal run only lists calculated members).
        if ($run['run_purpose'] !== 'incentive') {
            $stmt = $this->db->prepare("SELECT 1 FROM `payroll_run_details` WHERE run_id = :r AND employee_id = :e");
            $stmt->execute([':r' => $run['id'], ':e' => $employee['id']]);
            if (!$stmt->fetch()) {
                throw new ImportRowValidationException('employee_not_in_run', "Employee {$v['employee_code']} is not part of the calculated run yet -- recalculate it first.");
            }
        }

        $stmt = $this->db->prepare("SELECT 1 FROM `payroll_run_sync_item_overrides` WHERE run_id = :r AND employee_id = :e");
        $stmt->execute([':r' => $run['id'], ':e' => $employee['id']]);
        if ($stmt->fetch()) {
            throw new ImportRowValidationException('existing_override_record', "พนักงาน {$v['employee_code']} มีข้อมูล override อยู่แล้วในรอบการจ่ายนี้");
        }

        return ['employee_id' => $employee['id'], 'employee_no' => $employee['employee_no'], 'run_id' => (int)$run['id'], 'fields' => $fields];
    }
}
