<?php
declare(strict_types=1);
require_once __DIR__ . '/ImportTargetResolver.php';

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
    private ImportTargetResolver $targets;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->targets = new ImportTargetResolver($this->db);
    }

    /** @return array{employee_id:int, employee_no:string, ped_type_id:int, item_code:string, run_id:int, amount:float, note:?string}
     *  @throws ImportRowValidationException */
    public function validate(int $compId, array $row): array {
        $v = [];
        foreach (['employee_code', 'item_code', 'amount', 'period_month', 'period_year', 'run_code', 'effective_date', 'note'] as $key) {
            $cell = $row[$key] ?? null;
            $v[$key] = $cell === null ? '' : trim((string)$cell);
        }

        $employee = $this->targets->employee($compId, $v['employee_code']);

        if ($v['item_code'] === '') {
            throw new ImportRowValidationException('item_code_required', 'item_code is required.');
        }
        $item = $this->activeItem($compId, $v['item_code']);

        $amountRaw = str_replace([',', ' '], '', $v['amount']);
        if ($amountRaw === '' || !is_numeric($amountRaw) || (float)$amountRaw <= 0) {
            throw new ImportRowValidationException('amount_invalid', 'amount must be greater than 0.');
        }
        $amount = round((float)$amountRaw, 2);

        $run = $this->targets->draftRun($compId, $v['period_month'], $v['period_year'], $v['run_code']);

        $note = $v['note'] !== '' ? $v['note'] : null;
        if ($v['effective_date'] !== '') {
            $d = $v['effective_date'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4))) {
                throw new ImportRowValidationException('effective_date_invalid', 'effective_date must be a valid date in YYYY-MM-DD format.');
            }
            if ($d < $run['period_start_date'] || $d > $run['period_end_date']) {
                throw new ImportRowValidationException('effective_date_out_of_payroll_period', "effective_date {$d} is outside the payroll period {$run['period_start_date']} to {$run['period_end_date']}.");
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
            throw new ImportRowValidationException('item_not_found', count($rows) === 0
                ? "item_code {$itemCode} does not exist or is not active."
                : "item_code {$itemCode} matches more than one active item.");
        }
        return $rows[0];
    }
}
