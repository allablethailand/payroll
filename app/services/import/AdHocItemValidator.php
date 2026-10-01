<?php
declare(strict_types=1);
require_once __DIR__ . '/EmployeeMatcher.php';

/** A rule an ad-hoc item row broke; getErrorKey() is the stable machine key, the message is "key: readable sentence". */
class AdHocItemValidationException extends InvalidArgumentException {
    private string $errorKey;

    public function __construct(string $errorKey, string $message) {
        parent::__construct("{$errorKey}: {$message}");
        $this->errorKey = $errorKey;
    }

    public function getErrorKey(): string {
        return $this->errorKey;
    }
}

/**
 * Rules for one ad-hoc earning/deduction import row. Resolves everything the importer needs from the codes in the file:
 * the employee (this company's employee code), the active catalog item, the draft payroll run for the period, and the final note.
 *
 * Run resolution: with run_code, that run within period_month/period_year (judged by period_end_date) must exist and be a draft;
 * without it, exactly one active draft run must end in that month. effective_date, when given, must fall inside that run's period and is
 * appended to the note as "(Effective: YYYY-MM-DD)". The run must be a draft because that is the only state PayrollRunModel accepts.
 */
class AdHocItemValidator {
    private PDO $db;
    private EmployeeMatcher $employees;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->employees = new EmployeeMatcher($this->db);
    }

    /** @return array{employee_id:int, employee_no:string, ped_type_id:int, item_code:string, run_id:int, amount:float, note:?string}
     *  @throws AdHocItemValidationException */
    public function validate(int $compId, array $row): array {
        $v = [];
        foreach (['employee_code', 'item_code', 'amount', 'period_month', 'period_year', 'run_code', 'effective_date', 'note'] as $key) {
            $cell = $row[$key] ?? null;
            $v[$key] = $cell === null ? '' : trim((string)$cell);
        }

        if ($v['employee_code'] === '') {
            throw new AdHocItemValidationException('employee_code_required', 'employee_code is required.');
        }
        $employee = $this->employees->find($compId, $v['employee_code'], '');
        if ($employee === null) {
            throw new AdHocItemValidationException('employee_not_found', "Employee {$v['employee_code']} does not belong to this company.");
        }

        if ($v['item_code'] === '') {
            throw new AdHocItemValidationException('item_code_required', 'item_code is required.');
        }
        $item = $this->activeItem($compId, $v['item_code']);

        $amountRaw = str_replace([',', ' '], '', $v['amount']);
        if ($amountRaw === '' || !is_numeric($amountRaw) || (float)$amountRaw <= 0) {
            throw new AdHocItemValidationException('amount_invalid', 'amount must be greater than 0.');
        }
        $amount = round((float)$amountRaw, 2);

        if (!ctype_digit($v['period_month']) || (int)$v['period_month'] < 1 || (int)$v['period_month'] > 12
            || !ctype_digit($v['period_year']) || (int)$v['period_year'] < 2000 || (int)$v['period_year'] > 2100) {
            throw new AdHocItemValidationException('period_invalid', 'period_month must be 1-12 and period_year a four-digit year.');
        }
        $month = (int)$v['period_month'];
        $year = (int)$v['period_year'];
        $run = $this->resolveRun($compId, $year, $month, $v['run_code']);

        $note = $v['note'] !== '' ? $v['note'] : null;
        if ($v['effective_date'] !== '') {
            $d = $v['effective_date'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4))) {
                throw new AdHocItemValidationException('effective_date_invalid', 'effective_date must be a valid date in YYYY-MM-DD format.');
            }
            if ($d < $run['period_start_date'] || $d > $run['period_end_date']) {
                throw new AdHocItemValidationException('effective_date_out_of_payroll_period', "effective_date {$d} is outside the payroll period {$run['period_start_date']} to {$run['period_end_date']}.");
            }
            $note = trim(($note ?? '') . " (Effective: {$d})");
        }

        return ['employee_id' => $employee['id'], 'employee_no' => $employee['employee_no'], 'ped_type_id' => (int)$item['id'], 'item_code' => $item['item_code'],
            'run_id' => (int)$run['id'], 'amount' => $amount, 'note' => $note];
    }

    /** @return array{id:int, item_code:string} */
    private function activeItem(int $compId, string $itemCode): array {
        $stmt = $this->db->prepare("SELECT id, item_code FROM `payroll_earning_deduction_types`
            WHERE comp_id = :c AND item_code = :code AND status = 'active' AND deleted_at IS NULL LIMIT 2");
        $stmt->execute([':c' => $compId, ':code' => $itemCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1) {
            throw new AdHocItemValidationException('item_not_found', count($rows) === 0
                ? "item_code {$itemCode} does not exist or is not active."
                : "item_code {$itemCode} matches more than one active item.");
        }
        return $rows[0];
    }

    /** @return array{id:int, period_start_date:string, period_end_date:string} */
    private function resolveRun(int $compId, int $year, int $month, string $runCode): array {
        $base = "SELECT id, state, period_start_date, period_end_date FROM `payroll_runs`
            WHERE comp_id = :c AND deleted_at IS NULL AND YEAR(period_end_date) = :y AND MONTH(period_end_date) = :m";
        if ($runCode !== '') {
            $stmt = $this->db->prepare($base . " AND run_code = :code LIMIT 1");
            $stmt->execute([':c' => $compId, ':y' => $year, ':m' => $month, ':code' => $runCode]);
            $run = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$run) {
                throw new AdHocItemValidationException('run_not_found', "No payroll run with run_code {$runCode} ends in {$month}/{$year}.");
            }
            if ($run['state'] !== 'draft') {
                throw new AdHocItemValidationException('invalid_run_status_for_import', "Payroll run {$runCode} is {$run['state']}; imports are only permitted on draft payroll runs.");
            }
            return $run;
        }
        $stmt = $this->db->prepare($base . " AND state = 'draft' AND status = 'active' LIMIT 2");
        $stmt->execute([':c' => $compId, ':y' => $year, ':m' => $month]);
        $runs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($runs) === 0) {
            throw new AdHocItemValidationException('no_draft_run_for_period', "No active draft payroll run found for period {$month}/{$year}");
        }
        if (count($runs) > 1) {
            throw new AdHocItemValidationException('multiple_draft_runs_for_period', "Multiple draft payroll runs found for period {$month}/{$year}. Please specify run_code");
        }
        return $runs[0];
    }
}
