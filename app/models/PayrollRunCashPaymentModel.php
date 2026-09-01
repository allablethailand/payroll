<?php
declare(strict_types=1);
require_once __DIR__ . '/PayrollReportDataModel.php';
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
     * an amount SNAPSHOT from payroll_run_details.net_amount at this moment (see this class's own
     * top docblock for why a snapshot, not a live join, is safe here). Idempotent -- INSERT IGNORE
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
        $cashRows = array_filter($details, fn($d) => ($d['payment_type'] ?? 'bank') === 'cash');
        if (!empty($cashRows)) {
            $own = !$this->db->inTransaction();
            if ($own) {
                $this->db->beginTransaction();
            }
            try {
                $insStmt = $this->db->prepare("INSERT IGNORE INTO `payroll_run_cash_payments` (run_id, employee_id, amount) VALUES (:run_id, :employee_id, :amount)");
                foreach ($cashRows as $row) {
                    $insStmt->execute([
                        ':run_id' => $runId,
                        ':employee_id' => (int)$row['employee_id'],
                        ':amount' => (float)$row['net_amount'],
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

        // Bank total is a plain cross-reference against this same run's own details (net_amount for
        // every payment_type='bank' employee) -- NOT a duplicate of BankTransferFileReport's own
        // transfer-file generation, just the number for this page's own "cash vs bank" summary.
        $details = $this->dataModel->getRunDetails($runId);
        $totalBank = 0.0;
        foreach ($details as $d) {
            if (($d['payment_type'] ?? 'bank') === 'bank') {
                $totalBank += (float)$d['net_amount'];
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
