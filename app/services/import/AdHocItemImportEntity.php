<?php
declare(strict_types=1);
require_once __DIR__ . '/AdHocItemValidator.php';
require_once __DIR__ . '/../../models/PayrollRunModel.php';

/**
 * Ad-hoc earning/deduction import (entity type `adhoc_item`): each valid row becomes one payroll_run_manual_lines row on the draft run
 * for its period, tagged with the import batch (import_batch_id) so the whole batch can be rolled back.
 *
 * PayrollRunModel::insertManualLine() applies the same gates as adding a line by hand (draft run, employee in the run, not verified).
 * Lines are inserted without recalculating; afterBatch() then recalculates each touched run ONCE, on commit only -- a dry run is rolled
 * back, so there is nothing to recalculate. The caller (ImportController) owns the payroll_run.process permission gate.
 */
class AdHocItemImportEntity {
    private PDO $db;
    private AdHocItemValidator $validator;
    private PayrollRunModel $runs;
    /** @var array<int, true> run ids written to in the current batch */
    private array $touchedRuns = [];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->validator = new AdHocItemValidator($this->db);
        $this->runs = new PayrollRunModel($this->db);
    }

    public function entityType(): string {
        return 'adhoc_item';
    }

    public function templateColumns(): array {
        return [
            'employee_code' => 'Employee Code', 'item_code' => 'Item Code', 'amount' => 'Amount',
            'period_month' => 'Period Month (1-12)', 'period_year' => 'Period Year (YYYY)',
            'run_code' => 'Run Code (optional, needed if the month has several draft runs)',
            'effective_date' => 'Effective Date (YYYY-MM-DD, optional)', 'note' => 'Note (optional)',
        ];
    }

    public function importRow(int $compId, array $row, int $batchId, ?int $triggeredBy): array {
        $v = $this->validator->validate($compId, $row);
        $result = $this->runs->insertManualLine($v['run_id'], $compId, $v['employee_id'], $v['ped_type_id'], $v['amount'], (int)$triggeredBy, $v['note'],
            null, null, null, null, null, null, null, null, $batchId);
        if (empty($result['status'])) {
            throw new InvalidArgumentException((string)($result['message'] ?? 'The line could not be added.'));
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
