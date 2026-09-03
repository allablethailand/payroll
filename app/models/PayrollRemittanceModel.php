<?php
declare(strict_types=1);

/**
 * Deduction Destination & Third-Party Remittance (2026-09-02) -- groups a run's already-calculated
 * deduction lines by destination into a trackable "who do we actually need to transfer real money
 * to, and have we done it yet" batch. Deliberately built as a SEPARATE layer from calculation:
 * this class only ever READS `payroll_run_details.deduction_breakdown` (the already-computed,
 * already-approved numbers) -- it never recomputes or influences a single deduction amount. Net
 * pay/tax/statutory calculation is completely unaware this class exists.
 *
 * `payee_type='employee'` where the payee IS part of this same run is deliberately EXCLUDED here
 * entirely -- that case is already paid via PayrollRunModel::recalculate()'s own TRANSFER_IN
 * mechanism (a real taxable earning line credited to the payee in the SAME run, no external
 * transfer needed). Only 3 cases ever produce a remittance row:
 *  - payee_type='company' -- retained by the company itself. One combined row per run, marked
 *    'success' immediately (no real external transfer happens, but the amount is still recorded
 *    for audit -- "money left this deduction line but never left the company").
 *  - payee_type='other_person' -- grouped by destination_id (payment_destinations), a real
 *    external bank transfer that needs tracking.
 *  - payee_type='employee' where the payee is NOT part of this run -- the "fallback" case
 *    (2026-09-02, confirmed via AskUserQuestion): grouped by payee_employee_id instead of a saved
 *    destination, paid to that employee's own bank details as a real external transfer (same as
 *    other_person in every way except where the bank details come from).
 *
 * `payee_type='not_disbursed'` never produces a remittance at all -- by definition no money
 * actually needs to go anywhere. Confirmed via the feature's own spec: an unspecified/None
 * destination (payee_type null) is likewise never tracked here.
 */
class PayrollRemittanceModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /**
     * Called from PayrollRunModel::approve() right after a run transitions to 'approved' (confirmed
     * trigger point via AskUserQuestion 2026-09-02 -- gives Finance lead time to arrange transfers
     * before the run is marked Paid). Idempotent for the common "revert then re-approve" case: if
     * every existing remittance for this run is still 'pending' (nothing has actually been acted on
     * yet), they're deleted and regenerated fresh; if ANY remittance has moved past 'pending'
     * (transferred/success/failed -- real progress that must never be silently discarded), this
     * refuses outright rather than guessing which rows are still valid.
     * @return array{status:bool, message?:string, remittance_count?:int, fallback_cases?:array}
     */
    public function generateForRun(int $runId, int $compId, int $userId): array {
        $stmtRun = $this->db->prepare("SELECT id FROM `payroll_runs` WHERE id = :id AND comp_id = :comp_id");
        $stmtRun->execute([':id' => $runId, ':comp_id' => $compId]);
        if (!$stmtRun->fetch()) {
            return ['status' => false, 'message' => 'Run not found.'];
        }

        // 2026-09-02, real bug caught by this class's own test: destination_type='company' is
        // ALWAYS auto-created as status='success' immediately (never a human action), so it must
        // NOT count as "real progress" for this guard -- only other_person/employee_fallback rows
        // that a human has actually moved past 'pending' (transferred/success/failed) represent
        // real progress that must never be silently discarded.
        $stmtExisting = $this->db->prepare("SELECT COUNT(*) FROM `payroll_remittances` WHERE run_id = :run_id AND status != 'pending' AND destination_type != 'company'");
        $stmtExisting->execute([':run_id' => $runId]);
        if ((int)$stmtExisting->fetchColumn() > 0) {
            return ['status' => false, 'message' => 'One or more remittances for this run have already been acted on (transferred/confirmed/failed) -- cannot regenerate.'];
        }

        $stmtDetails = $this->db->prepare("SELECT employee_id, deduction_breakdown FROM `payroll_run_details` WHERE run_id = :run_id");
        $stmtDetails->execute([':run_id' => $runId]);
        $detailRows = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);
        $employeeIdsInRun = array_map(static fn($r) => (int)$r['employee_id'], $detailRows);

        // groupKey => ['destination_type', 'destination_id', 'fallback_employee_id', 'amount', 'items' => [[employee_id, item_code, amount]]]
        $groups = [];
        foreach ($detailRows as $row) {
            $employeeId = (int)$row['employee_id'];
            $lines = json_decode((string)($row['deduction_breakdown'] ?? '[]'), true) ?: [];
            foreach ($lines as $line) {
                $payeeType = $line['payee_type'] ?? null;
                if ($payeeType === null || $payeeType === 'not_disbursed') {
                    continue;
                }
                $amount = (float)($line['amount'] ?? 0);
                if ($amount <= 0) {
                    continue;
                }
                $itemCode = (string)($line['code'] ?? 'UNKNOWN');
                if ($payeeType === 'company') {
                    $key = 'company';
                    $groups[$key] ??= ['destination_type' => 'company', 'destination_id' => null, 'fallback_employee_id' => null, 'amount' => 0.0, 'items' => []];
                } elseif ($payeeType === 'other_person') {
                    $destinationId = $line['destination_id'] ?? null;
                    if ($destinationId === null) {
                        continue; // defensive -- other_person always sets destination_id at save time, but never guess if it's somehow missing.
                    }
                    $key = 'other_person:' . $destinationId;
                    $groups[$key] ??= ['destination_type' => 'other_person', 'destination_id' => (int)$destinationId, 'fallback_employee_id' => null, 'amount' => 0.0, 'items' => []];
                } elseif ($payeeType === 'employee') {
                    $payeeEmployeeId = $line['payee_employee_id'] ?? null;
                    if ($payeeEmployeeId === null || in_array((int)$payeeEmployeeId, $employeeIdsInRun, true)) {
                        continue; // payee IS in this run -- already paid via TRANSFER_IN, not a remittance.
                    }
                    $key = 'employee_fallback:' . $payeeEmployeeId;
                    $groups[$key] ??= ['destination_type' => 'employee_fallback', 'destination_id' => null, 'fallback_employee_id' => (int)$payeeEmployeeId, 'amount' => 0.0, 'items' => []];
                } else {
                    continue;
                }
                $groups[$key]['amount'] += $amount;
                $groups[$key]['items'][] = ['employee_id' => $employeeId, 'item_code' => $itemCode, 'amount' => $amount];
            }
        }

        if (empty($groups)) {
            return ['status' => true, 'remittance_count' => 0, 'fallback_cases' => []];
        }

        $own = !$this->db->inTransaction();
        $fallbackCases = [];
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            // Clear any previously-generated remittances for this run first (the idempotent-
            // regenerate path) -- 'pending' rows plus the always-auto-success 'company' row (which
            // is never real human progress, see the guard above); anything else past 'pending' was
            // already confirmed absent by the guard above, so this DELETE can never touch real
            // progress.
            $this->db->prepare("DELETE FROM `payroll_remittances` WHERE run_id = :run_id AND (status = 'pending' OR destination_type = 'company')")->execute([':run_id' => $runId]);

            $count = 0;
            foreach ($groups as $group) {
                $status = $group['destination_type'] === 'company' ? 'success' : 'pending';
                $stmtIns = $this->db->prepare("INSERT INTO `payroll_remittances`
                        (run_id, destination_type, destination_id, fallback_employee_id, total_amount, status, created_by)
                    VALUES (:run_id, :destination_type, :destination_id, :fallback_employee_id, :total_amount, :status, :created_by)");
                $stmtIns->execute([
                    ':run_id' => $runId, ':destination_type' => $group['destination_type'],
                    ':destination_id' => $group['destination_id'], ':fallback_employee_id' => $group['fallback_employee_id'],
                    ':total_amount' => round($group['amount'], 2), ':status' => $status, ':created_by' => $userId,
                ]);
                $remittanceId = (int)$this->db->lastInsertId();
                $stmtItem = $this->db->prepare("INSERT INTO `payroll_remittance_items` (remittance_id, employee_id, item_code, amount) VALUES (:remittance_id, :employee_id, :item_code, :amount)");
                foreach ($group['items'] as $item) {
                    $stmtItem->execute([':remittance_id' => $remittanceId, ':employee_id' => $item['employee_id'], ':item_code' => $item['item_code'], ':amount' => $item['amount']]);
                }
                $count++;
                if ($group['destination_type'] === 'employee_fallback') {
                    $fallbackCases[] = ['remittance_id' => $remittanceId, 'fallback_employee_id' => $group['fallback_employee_id'], 'total_amount' => round($group['amount'], 2)];
                }
            }
            if ($own) {
                $this->db->commit();
            }
            return ['status' => true, 'remittance_count' => $count, 'fallback_cases' => $fallbackCases];
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    /** Preview version of generateForRun() -- same grouping logic, NO writes -- used by the
     *  Approval confirmation step to show "these fallback cases will be created" before the admin
     *  actually confirms approve(). Deliberately a thin wrapper that reuses generateForRun() itself
     *  would be unsafe (it writes) -- instead this duplicates only the read/group half. Kept small
     *  on purpose: if this drifts out of sync with generateForRun()'s own grouping rules, the
     *  worst case is a preview that doesn't exactly match what gets created, not a data-integrity
     *  problem (generateForRun() is still the single source of truth for what actually gets
     *  written).
     */
    public function previewFallbackCases(int $runId, int $compId): array {
        $stmtRun = $this->db->prepare("SELECT id FROM `payroll_runs` WHERE id = :id AND comp_id = :comp_id");
        $stmtRun->execute([':id' => $runId, ':comp_id' => $compId]);
        if (!$stmtRun->fetch()) {
            return [];
        }
        $stmtDetails = $this->db->prepare("SELECT employee_id, deduction_breakdown FROM `payroll_run_details` WHERE run_id = :run_id");
        $stmtDetails->execute([':run_id' => $runId]);
        $detailRows = $stmtDetails->fetchAll(PDO::FETCH_ASSOC);
        $employeeIdsInRun = array_map(static fn($r) => (int)$r['employee_id'], $detailRows);
        $fallbackTotals = [];
        foreach ($detailRows as $row) {
            $lines = json_decode((string)($row['deduction_breakdown'] ?? '[]'), true) ?: [];
            foreach ($lines as $line) {
                if (($line['payee_type'] ?? null) !== 'employee') {
                    continue;
                }
                $payeeEmployeeId = $line['payee_employee_id'] ?? null;
                $amount = (float)($line['amount'] ?? 0);
                if ($payeeEmployeeId === null || $amount <= 0 || in_array((int)$payeeEmployeeId, $employeeIdsInRun, true)) {
                    continue;
                }
                $fallbackTotals[(int)$payeeEmployeeId] = ($fallbackTotals[(int)$payeeEmployeeId] ?? 0) + $amount;
            }
        }
        $result = [];
        foreach ($fallbackTotals as $employeeId => $amount) {
            $stmtEmp = $this->db->prepare("SELECT employee_no, name_th, surname_th, name_en, surname_en FROM `employees` WHERE id = :id");
            $stmtEmp->execute([':id' => $employeeId]);
            $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC) ?: [];
            $result[] = ['employee_id' => $employeeId, 'employee_no' => $emp['employee_no'] ?? null,
                'name_th' => trim(($emp['name_th'] ?? '') . ' ' . ($emp['surname_th'] ?? '')),
                'name_en' => trim(($emp['name_en'] ?? '') . ' ' . ($emp['surname_en'] ?? '')),
                'total_amount' => round($amount, 2)];
        }
        return $result;
    }

    public function listForRun(int $runId, int $compId): array {
        $stmt = $this->db->prepare("SELECT r.*, pd.account_name AS destination_account_name, mb.bank_name_th, mb.bank_name_en,
                fe.employee_no AS fallback_employee_no, fe.name_th AS fallback_name_th, fe.surname_th AS fallback_surname_th,
                fe.name_en AS fallback_name_en, fe.surname_en AS fallback_surname_en,
                (SELECT COUNT(DISTINCT employee_id) FROM payroll_remittance_items WHERE remittance_id = r.id) AS employee_count
            FROM `payroll_remittances` r
            LEFT JOIN `payment_destinations` pd ON pd.id = r.destination_id
            LEFT JOIN `master_banks` mb ON mb.id = pd.bank_id
            LEFT JOIN `employees` fe ON fe.id = r.fallback_employee_id
            JOIN `payroll_runs` pr ON pr.id = r.run_id AND pr.comp_id = :comp_id
            WHERE r.run_id = :run_id
            ORDER BY r.id ASC");
        $stmt->execute([':run_id' => $runId, ':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function itemsForRemittance(int $remittanceId, int $compId): array {
        $stmt = $this->db->prepare("SELECT ri.*, e.employee_no, e.name_th, e.surname_th, e.name_en, e.surname_en
            FROM `payroll_remittance_items` ri
            JOIN `payroll_remittances` r ON r.id = ri.remittance_id
            JOIN `payroll_runs` pr ON pr.id = r.run_id AND pr.comp_id = :comp_id
            JOIN `employees` e ON e.id = ri.employee_id
            WHERE ri.remittance_id = :remittance_id
            ORDER BY ri.id ASC");
        $stmt->execute([':remittance_id' => $remittanceId, ':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function findOwned(int $remittanceId, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT r.* FROM `payroll_remittances` r
            JOIN `payroll_runs` pr ON pr.id = r.run_id AND pr.comp_id = :comp_id
            WHERE r.id = :id");
        $stmt->execute([':id' => $remittanceId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Upload evidence + record the transfer -- 'pending' -> 'transferred' only. */
    public function markTransferred(int $remittanceId, int $compId, ?string $evidenceFilePath, int $userId, ?int $evidenceFileSize = null): array {
        $row = $this->findOwned($remittanceId, $compId);
        if ($row === null) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($row['status'] !== 'pending') {
            return ['status' => false, 'message' => 'Only a pending remittance can be marked as transferred.'];
        }
        $this->db->prepare("UPDATE `payroll_remittances` SET status = 'transferred', evidence_file_path = :evidence, evidence_file_size = :evidence_size,
                transferred_at = CURRENT_TIMESTAMP, transferred_by = :user, updated_by = :user, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id")
            ->execute([':evidence' => $evidenceFilePath, ':evidence_size' => $evidenceFileSize, ':user' => $userId, ':id' => $remittanceId]);
        return ['status' => true];
    }

    /** 'transferred' -> 'success'. */
    public function confirmSuccess(int $remittanceId, int $compId, int $userId): array {
        $row = $this->findOwned($remittanceId, $compId);
        if ($row === null) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($row['status'] !== 'transferred') {
            return ['status' => false, 'message' => 'Only a transferred remittance can be confirmed as successful.'];
        }
        $this->db->prepare("UPDATE `payroll_remittances` SET status = 'success', updated_by = :user, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':user' => $userId, ':id' => $remittanceId]);
        return ['status' => true];
    }

    /** 'transferred' -> 'failed', with a required reason. */
    public function markFailed(int $remittanceId, int $compId, string $note, int $userId): array {
        $note = trim($note);
        if ($note === '') {
            return ['status' => false, 'message' => 'A reason is required when marking a remittance as failed.'];
        }
        $row = $this->findOwned($remittanceId, $compId);
        if ($row === null) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($row['status'] !== 'transferred') {
            return ['status' => false, 'message' => 'Only a transferred remittance can be marked as failed.'];
        }
        $this->db->prepare("UPDATE `payroll_remittances` SET status = 'failed', note = :note, updated_by = :user, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':note' => $note, ':user' => $userId, ':id' => $remittanceId]);
        return ['status' => true];
    }

    /** 'failed' -> 'pending' again, for a retry -- clears the previous evidence/transfer timestamp
     *  so the next "Mark as Transferred" attempt records fresh evidence, not stale data from the
     *  failed attempt. */
    public function retryToPending(int $remittanceId, int $compId, int $userId): array {
        $row = $this->findOwned($remittanceId, $compId);
        if ($row === null) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($row['status'] !== 'failed') {
            return ['status' => false, 'message' => 'Only a failed remittance can be retried.'];
        }
        $this->db->prepare("UPDATE `payroll_remittances` SET status = 'pending', evidence_file_path = NULL,
                transferred_at = NULL, transferred_by = NULL, updated_by = :user, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id")
            ->execute([':user' => $userId, ':id' => $remittanceId]);
        return ['status' => true];
    }
}
