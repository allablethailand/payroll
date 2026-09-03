<?php
declare(strict_types=1);
require_once __DIR__ . '/PayrollReportDataModel.php';
require_once __DIR__ . '/EmployeePaymentMethodModel.php';
require_once __DIR__ . '/../services/reports/LocalizedException.php';

/**
 * 2026-08-31, explicit request: "ถ้าพนักงานรับเงินสด ต้องไม่ดึงไปใน Report Payroll ขึ้นธนาคาร แต่แยก
 * Report ตามแยก ว่าจ่ายเงินสดเท่าไหร่ โอนผ่านธนาคารเท่าไหร่ และสามารถใส่ Status ว่าจ่ายแล้ว" -- see
 * `database/migrations/2026-08-31_8_payroll_run_cash_payments.sql`'s own header comment for why this
 * needed a new table rather than reusing `payroll_runs.markPaid()` (that's whole-run, not
 * per-employee). Same ALLOWED_STATES gate every other payment-type report already uses
 * (PaymentVoucherReport/BankTransferFileReport) -- a run still in draft can have its numbers/
 * payment_type change at any time, so cash-payment tracking only starts once the run is
 * approved/paid/locked.
 */
class PayrollRunCashPaymentModel {
    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];
    private PDO $db;
    private PayrollReportDataModel $dataModel;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->dataModel = new PayrollReportDataModel($this->db);
    }

    /**
     * Lazily creates one payroll_run_cash_payments row per cash-paying employee in this run, taking
     * an amount SNAPSHOT from net_amount_due (net_amount minus whatever's already been recorded as
     * disbursed on a prior payment cycle for this run+employee, see PayrollReportDataModel::
     * getRunDetails()'s own docblock) at this moment (see this class's own top docblock for why a
     * snapshot, not a live join, is safe here). Idempotent -- INSERT IGNORE
     * against the (run_id, employee_id) unique key, so calling this repeatedly (every time the page
     * is opened) never duplicates or resets an already-tracked row's status.
     */
    private function ensureRowsForRun(int $runId, int $compId): array {
        $run = $this->dataModel->getRun($runId, $compId);
        if (!$run) {
            throw new LocalizedException('Payroll run not found.', 'run_not_found');
        }
        $this->dataModel->assertRunStateOrThrow($run, self::ALLOWED_STATES);

        $details = $this->dataModel->getRunDetails($runId);
        $cashAmountsByEmployee = [];
        // 2026-09-02, real bug found and fixed (flagged during a post-feature review, not guessed):
        // a plain 'cash' employee's tracked amount now uses net_amount_due (the DELTA still owed
        // this payment cycle after subtracting whatever payroll_run_payment_events already recorded
        // as disbursed on a prior cycle -- 0 for the overwhelmingly common single-payment-cycle case,
        // where this is byte-identical to the old net_amount) instead of the raw net_amount column --
        // was previously a real inconsistency with BankTransferFileReport's own delta-aware handling:
        // reopening an already-partially-paid run and tracking the cash portion again would have
        // recorded the FULL amount a second time instead of just what's actually still owed. A
        // 'mixed' employee with one or more cash lines gets those lines SUMMED into ONE row -- this
        // table has a UNIQUE (run_id, employee_id) key, it was never designed to hold multiple cash
        // rows per employee, same reasoning BankTransferFileReport's own mixed-line handling
        // documents for why percent lines resolve against net_amount_due there instead (a mixed
        // employee's cash portion is genuinely just "however much of their pay is cash", one figure).
        $paymentMethodModel = new EmployeePaymentMethodModel();
        foreach ($details as $d) {
            $resolvedCode = $d['payment_method_code'] ?? 'transfer';
            $employeeId = (int)$d['employee_id'];
            if ($resolvedCode === 'cash') {
                $cashAmountsByEmployee[$employeeId] = (float)($d['net_amount_due'] ?? $d['net_amount'] ?? 0);
            } elseif ($resolvedCode === 'mixed') {
                $lines = $paymentMethodModel->getLines($employeeId);
                $cashLines = array_filter($lines, fn($l) => ($l['payment_method_code'] ?? '') === 'cash');
                if (empty($cashLines)) {
                    continue;
                }
                $netAmountDue = (float)($d['net_amount_due'] ?? $d['net_amount'] ?? 0);
                $sum = 0.0;
                foreach ($cashLines as $l) {
                    $sum += $l['amount_type'] === 'percent'
                        ? round($netAmountDue * (float)$l['amount_value'] / 100, 2)
                        : (float)$l['amount_value'];
                }
                $cashAmountsByEmployee[$employeeId] = $sum;
            }
        }
        if (!empty($cashAmountsByEmployee)) {
            $own = !$this->db->inTransaction();
            if ($own) {
                $this->db->beginTransaction();
            }
            try {
                $insStmt = $this->db->prepare("INSERT IGNORE INTO `payroll_run_cash_payments` (run_id, employee_id, amount) VALUES (:run_id, :employee_id, :amount)");
                foreach ($cashAmountsByEmployee as $employeeId => $amount) {
                    $insStmt->execute([
                        ':run_id' => $runId,
                        ':employee_id' => $employeeId,
                        ':amount' => $amount,
                    ]);
                }
                if ($own) {
                    $this->db->commit();
                }
            } catch (Throwable $e) {
                if ($own) {
                    $this->db->rollBack();
                }
                throw $e;
            }
        }
        return $run;
    }

    /** @return array{run: array, rows: array<int,array>, total_cash: float, total_bank: float} */
    public function listForRun(int $runId, int $compId): array {
        $run = $this->ensureRowsForRun($runId, $compId);
        $sql = "SELECT p.*, e.employee_no, e.name_th, e.surname_th, e.name_en, e.surname_en,
                    CONCAT(u.name_th, ' ', u.surname_th) AS paid_by_name_th, CONCAT(u.name_en, ' ', u.surname_en) AS paid_by_name_en
                FROM `payroll_run_cash_payments` p
                JOIN `employees` e ON e.id = p.employee_id
                LEFT JOIN `employees` u ON u.id = p.paid_by
                WHERE p.run_id = :run_id
                ORDER BY e.employee_no ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':run_id' => $runId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Bank total is a plain cross-reference against this same run's own details -- NOT a
        // duplicate of BankTransferFileReport's own transfer-file generation, just the number for
        // this page's own "cash vs bank" summary. Uses net_amount_due (see ensureRowsForRun()'s own
        // comment on the same fix, applied here too) so this total stays correct after a reopened,
        // partially-paid run instead of double-counting an already-disbursed prior cycle.
        $details = $this->dataModel->getRunDetails($runId);
        $totalBank = 0.0;
        // 2026-09-02, mixed payment method -- a 'mixed' employee's own transfer-line total is added
        // in too (same per-line resolution as ensureRowsForRun()'s own cash-side sum above), so this
        // summary card doesn't undercount the moment any employee starts using mixed payment.
        $paymentMethodModel = new EmployeePaymentMethodModel();
        foreach ($details as $d) {
            $resolvedCode = $d['payment_method_code'] ?? 'transfer';
            if ($resolvedCode === 'transfer') {
                $totalBank += (float)($d['net_amount_due'] ?? $d['net_amount'] ?? 0);
            } elseif ($resolvedCode === 'mixed') {
                $lines = $paymentMethodModel->getLines((int)$d['employee_id']);
                $transferLines = array_filter($lines, fn($l) => ($l['payment_method_code'] ?? '') === 'transfer');
                $netAmountDue = (float)($d['net_amount_due'] ?? $d['net_amount'] ?? 0);
                foreach ($transferLines as $l) {
                    $totalBank += $l['amount_type'] === 'percent'
                        ? round($netAmountDue * (float)$l['amount_value'] / 100, 2)
                        : (float)$l['amount_value'];
                }
            }
        }
        $totalCash = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));

        return ['run' => $run, 'rows' => $rows, 'total_cash' => $totalCash, 'total_bank' => $totalBank];
    }

    public function setStatus(int $id, int $compId, string $status, int $userId): bool {
        if (!in_array($status, ['paid', 'unpaid'], true)) {
            throw new LocalizedException('Invalid status.', 'invalid_status');
        }
        $stmt = $this->db->prepare("SELECT p.id FROM `payroll_run_cash_payments` p
            JOIN `payroll_runs` r ON r.id = p.run_id
            WHERE p.id = :id AND r.comp_id = :comp_id");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        if (!$stmt->fetch()) {
            return false;
        }
        if ($status === 'paid') {
            $upd = $this->db->prepare("UPDATE `payroll_run_cash_payments` SET status = 'paid', paid_at = CURRENT_TIMESTAMP, paid_by = :paid_by WHERE id = :id");
            $upd->execute([':paid_by' => $userId, ':id' => $id]);
        } else {
            $upd = $this->db->prepare("UPDATE `payroll_run_cash_payments` SET status = 'unpaid', paid_at = NULL, paid_by = NULL WHERE id = :id");
            $upd->execute([':id' => $id]);
        }
        return true;
    }
}
