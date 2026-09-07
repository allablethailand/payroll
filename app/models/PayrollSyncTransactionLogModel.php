<?php
declare(strict_types=1);

/**
 * Backlog Phase 9->10, T051 (2026-09-04): a durable, read-only, per-employee history of
 * SyncPayResolver-resolved pay lines (Diligence/attendance-bonus, Trip Allowance/expenses, an
 * opted-in Student Loan/กยศ event, and any other sync/event-derived line) -- see the migration
 * `2026-09-04_3_payroll_sync_transaction_log.sql`'s own header comment for the full "why a new
 * table, not employee_earning_deductions/EmployeeRecurringEarningModel" reasoning.
 *
 * Deliberately NOT written on every PayrollRunModel::recalculate() (a draft run can be recalculated
 * many times before ever being approved -- logging every attempt would permanently record abandoned/
 * superseded numbers). `logForRun()` is called exactly once, from PayrollRunModel::approve() right
 * after a run's state actually flips to 'approved', reading the ALREADY-PERSISTED
 * payroll_run_details.earning_breakdown/deduction_breakdown for that run (recalculate() only ever
 * runs on a 'draft' run, so the breakdown is frozen and correct by the time approve() reads it -- no
 * need to re-run SyncPayResolver a second time). `deleteForRun()` is called from
 * PayrollRunModel::revert() (only when leaving the 'approved' state specifically -- reverting a
 * still-pending_approval submission never had rows here in the first place) and
 * PayrollRunModel::reopen() (always, since that method only ever operates on a 'paid'/'locked' run,
 * both downstream of 'approved') -- an unapproved run's numbers are no longer a settled fact, same
 * "only approved+ data is a fact" convention PayrollReportDataModel::assertRunState() already
 * established elsewhere. A later re-approval writes fresh rows again via logForRun()'s own
 * delete-then-reinsert (idempotent replace by payroll_run_id), so revert/reopen -> edit -> re-approve
 * naturally ends up correct without any special-case handling.
 *
 * `source==='sync'` is the ONE marker used to decide which breakdown lines belong in this log --
 * confirmed by reading SyncPayResolver.php directly: every line it returns (OT/OT-scope, the
 * RULE_DRIVEN_ITEM_DEFS path used for late/absent/trip_allowance-shaped events, and the generic
 * item_values/catalog-match/CUSTOM: fallback path used for Diligence/opted-in Student Loan/any other
 * Origami item_code) sets 'source' => 'sync' consistently. A company's own ordinary manually-
 * configured earning/deduction type that isn't event-linked never carries this marker (its lines come
 * from a completely different code path in PayrollRunModel::recalculate()), so it correctly never
 * lands in this log -- this is deliberately a log of SYNC-DERIVED transactions only, not a general
 * payroll-line audit trail.
 */
class PayrollSyncTransactionLogModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /**
     * Reads payroll_run_details for $runId (assumed already the run's own final, frozen breakdown --
     * caller's responsibility to only call this once the run has actually reached 'approved'),
     * filters both earning_breakdown/deduction_breakdown down to lines whose 'source' key is 'sync',
     * and replaces (delete-then-reinsert, by payroll_run_id) this run's own log rows. A run with zero
     * sync-derived lines this period (e.g. no Origami events applied) simply ends up with zero rows
     * for it -- not an error, nothing to log.
     */
    public function logForRun(int $compId, int $runId, string $periodStart, string $periodEnd): void {
        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }

            $this->db->prepare("DELETE FROM `payroll_sync_transaction_log` WHERE payroll_run_id = :run_id")
                ->execute([':run_id' => $runId]);

            $stmt = $this->db->prepare("SELECT employee_id, earning_breakdown, deduction_breakdown FROM `payroll_run_details` WHERE run_id = :run_id");
            $stmt->execute([':run_id' => $runId]);
            $details = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $ins = $this->db->prepare("INSERT INTO `payroll_sync_transaction_log`
                (comp_id, employee_id, payroll_run_id, pay_period_start, pay_period_end, item_type, item_code, item_name_th, item_name_en, raw_item_code, amount, remark, data_source)
                VALUES (:comp_id, :employee_id, :run_id, :period_start, :period_end, :item_type, :item_code, :name_th, :name_en, :raw_code, :amount, :remark, 'sync')");

            foreach ($details as $detail) {
                $employeeId = (int)$detail['employee_id'];
                $earning = json_decode((string)$detail['earning_breakdown'], true) ?? [];
                $deduction = json_decode((string)$detail['deduction_breakdown'], true) ?? [];
                foreach ([['earning', $earning], ['deduction', $deduction]] as [$itemType, $lines]) {
                    foreach ($lines as $line) {
                        if (($line['source'] ?? '') !== 'sync') {
                            continue;
                        }
                        $ins->execute([
                            ':comp_id' => $compId,
                            ':employee_id' => $employeeId,
                            ':run_id' => $runId,
                            ':period_start' => $periodStart,
                            ':period_end' => $periodEnd,
                            ':item_type' => $itemType,
                            ':item_code' => (string)($line['code'] ?? ''),
                            ':name_th' => $line['name_th'] ?? null,
                            ':name_en' => $line['name_en'] ?? null,
                            ':raw_code' => $line['sync_item_code'] ?? null,
                            ':amount' => (float)($line['amount'] ?? 0),
                            ':remark' => $line['note'] ?? null,
                        ]);
                    }
                }
            }

            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    /** Called when a run leaves 'approved' (revert() away from it, or reopen() from paid/locked) --
     *  its log rows are no longer a settled fact. Safe to call even when the run never had any rows. */
    public function deleteForRun(int $runId): void {
        $this->db->prepare("DELETE FROM `payroll_sync_transaction_log` WHERE payroll_run_id = :run_id")
            ->execute([':run_id' => $runId]);
    }

    /** Employee Detail's own read -- capped at 200 (same LIMIT convention as SyncBatchModel::list(),
     *  this is a lightweight recent-history view, not a paginated report). */
    public function listForEmployee(int $compId, int $employeeId): array {
        $stmt = $this->db->prepare("SELECT id, payroll_run_id, pay_period_start, pay_period_end, item_type, item_code,
                item_name_th, item_name_en, raw_item_code, amount, remark, created_at
            FROM `payroll_sync_transaction_log`
            WHERE comp_id = :comp_id AND employee_id = :employee_id
            ORDER BY pay_period_start DESC, id DESC
            LIMIT 200");
        $stmt->execute([':comp_id' => $compId, ':employee_id' => $employeeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
