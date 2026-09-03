<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/EncryptionService.php';

/**
 * "Saved third-party bank account" catalog for deduction routing (Deduction Destination &
 * Third-Party Remittance feature, 2026-09-02). Deliberately scoped to ONLY the `other_person` case
 * of the ALREADY-EXISTING `payee_type` enum on `employee_earning_deductions`/
 * `payroll_run_manual_lines` (employee/company/not_disbursed keep using the existing
 * payee_employee_id column, completely unchanged) -- confirmed via AskUserQuestion before building
 * this, specifically to avoid a parallel, disconnected system next to the payee_type mechanism
 * that already works and is already tested (830 assertions in payroll_run_test.php touch it).
 *
 * `account_no` is AES-256-GCM encrypted (same convention as `employees.bank_account_no`/
 * `bank_accounts.account_no`) with a companion `account_no_hash` for exact-match lookup -- there is
 * no legitimate reason to search FOR an account number here beyond that, same reasoning
 * EncryptionService's own docblock gives for every other encrypted column in this app.
 *
 * `is_saved` distinguishes a REUSABLE destination (shows up in the picker dropdown for future
 * deductions) from a ONE-OFF ad-hoc one (still gets a real row here -- every deduction/manual line
 * needs a destination_id to point at -- just never offered again in the picker). Both are company-
 * scoped (any employee's deduction routed to the same third party reuses the same saved row), not
 * per-employee -- a third-party payee (e.g. a loan company, a court-ordered garnishee) is typically
 * shared across multiple employees' deductions, not owned by one employee's record.
 */
class PaymentDestinationModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** Select2-ajax-shaped list of SAVED destinations only (the reuse picker never offers a one-off
     *  ad-hoc row back, by design -- it was never meant to be found again). */
    public function listSaved(int $compId, string $search = '', int $limit = 20): array {
        $where = "WHERE comp_id = :comp_id AND deleted_at IS NULL AND status = 'active' AND is_saved = 1";
        $params = [':comp_id' => $compId];
        if ($search !== '') {
            $where .= " AND account_name LIKE :search";
            $params[':search'] = '%' . $search . '%';
        }
        $stmt = $this->db->prepare("SELECT pd.id, pd.account_name, mb.bank_name_th, mb.bank_name_en, pd.bank_branch
            FROM `payment_destinations` pd
            LEFT JOIN `master_banks` mb ON mb.id = pd.bank_id
            {$where} ORDER BY pd.account_name ASC LIMIT " . (int)$limit);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Full detail, account_no decrypted -- only ever called for display within this company's own
     *  scope (never cross-company), same guard every other encrypted-field get() in this app uses. */
    public function get(int $compId, int $id): ?array {
        $stmt = $this->db->prepare("SELECT pd.*, mb.bank_code, mb.bank_name_th, mb.bank_name_en
            FROM `payment_destinations` pd
            LEFT JOIN `master_banks` mb ON mb.id = pd.bank_id
            WHERE pd.id = :id AND pd.comp_id = :comp_id AND pd.deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['account_no'] = EncryptionService::decrypt($row['account_no'] ?? null, $row['key_version'] !== null ? (int)$row['key_version'] : null);
        return $row;
    }

    /**
     * Creates a new destination row (this catalog never updates an existing row in place from the
     * deduction-save flow -- editing a saved destination is a separate, explicit action via
     * update(), see below; picking an EXISTING saved one just reuses its id directly and never
     * calls this at all).
     * @param array{account_name:string, account_no:string, bank_id:int, bank_branch:?string, is_saved:bool} $data
     * @return array{status:bool, id?:int, message?:string}
     */
    public function create(int $compId, array $data, int $userId): array {
        $accountName = trim((string)($data['account_name'] ?? ''));
        $accountNo = trim((string)($data['account_no'] ?? ''));
        $bankId = !empty($data['bank_id']) ? (int)$data['bank_id'] : null;
        if ($accountName === '' || $accountNo === '' || $bankId === null) {
            return ['status' => false, 'message' => 'Account name, account number, and bank are required.'];
        }
        $stmtBank = $this->db->prepare("SELECT id FROM `master_banks` WHERE id = :id AND is_active = 1");
        $stmtBank->execute([':id' => $bankId]);
        if (!$stmtBank->fetch()) {
            return ['status' => false, 'message' => 'Invalid bank.'];
        }
        $bankBranch = !empty($data['bank_branch']) ? trim((string)$data['bank_branch']) : null;
        $isSaved = !empty($data['is_saved']) ? 1 : 0;

        $enc = EncryptionService::encrypt($accountNo);
        $stmt = $this->db->prepare("INSERT INTO `payment_destinations`
                (comp_id, account_name, account_no, account_no_hash, bank_id, bank_branch, is_saved, key_version, status, created_by)
            VALUES (:comp_id, :account_name, :account_no, :account_no_hash, :bank_id, :bank_branch, :is_saved, :key_version, 'active', :created_by)");
        $stmt->execute([
            ':comp_id' => $compId, ':account_name' => $accountName,
            ':account_no' => $enc['value'] ?? null, ':account_no_hash' => EncryptionService::hash($accountNo),
            ':bank_id' => $bankId, ':bank_branch' => $bankBranch, ':is_saved' => $isSaved,
            ':key_version' => $enc !== null ? EncryptionService::currentKeyVersion() : null, ':created_by' => $userId,
        ]);
        return ['status' => true, 'id' => (int)$this->db->lastInsertId()];
    }

    public function update(int $compId, int $id, array $data, int $userId): array {
        $existing = $this->get($compId, $id);
        if ($existing === null) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $accountName = trim((string)($data['account_name'] ?? ''));
        $accountNo = trim((string)($data['account_no'] ?? ''));
        $bankId = !empty($data['bank_id']) ? (int)$data['bank_id'] : null;
        if ($accountName === '' || $accountNo === '' || $bankId === null) {
            return ['status' => false, 'message' => 'Account name, account number, and bank are required.'];
        }
        $stmtBank = $this->db->prepare("SELECT id FROM `master_banks` WHERE id = :id AND is_active = 1");
        $stmtBank->execute([':id' => $bankId]);
        if (!$stmtBank->fetch()) {
            return ['status' => false, 'message' => 'Invalid bank.'];
        }
        $bankBranch = !empty($data['bank_branch']) ? trim((string)$data['bank_branch']) : null;
        $enc = EncryptionService::encrypt($accountNo);
        $stmt = $this->db->prepare("UPDATE `payment_destinations` SET
                account_name = :account_name, account_no = :account_no, account_no_hash = :account_no_hash,
                bank_id = :bank_id, bank_branch = :bank_branch, key_version = :key_version,
                updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id AND comp_id = :comp_id");
        $stmt->execute([
            ':account_name' => $accountName, ':account_no' => $enc['value'] ?? null, ':account_no_hash' => EncryptionService::hash($accountNo),
            ':bank_id' => $bankId, ':bank_branch' => $bankBranch,
            ':key_version' => $enc !== null ? EncryptionService::currentKeyVersion() : null,
            ':updated_by' => $userId, ':id' => $id, ':comp_id' => $compId,
        ]);
        return ['status' => true];
    }

    /** Soft delete -- blocked while any active (not soft-deleted) deduction/manual line still
     *  references it, same "block delete while referenced" convention used elsewhere in this app
     *  (e.g. Employment Certificate Template's own image library). */
    public function delete(int $compId, int $id, int $userId): array {
        $existing = $this->get($compId, $id);
        if ($existing === null) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        // 2026-09-02, Phase 6: employee_recurring_deductions (template default) and
        // payroll_run_recurring_deduction_overrides (per-run override) also point at this table now
        // -- both counted here too, same "block delete while still referenced anywhere" rule.
        $stmtRef = $this->db->prepare("SELECT
                (SELECT COUNT(*) FROM employee_earning_deductions WHERE destination_id = :id1 AND deleted_at IS NULL) +
                (SELECT COUNT(*) FROM payroll_run_manual_lines WHERE destination_id = :id2) +
                (SELECT COUNT(*) FROM employee_recurring_deductions WHERE destination_id = :id3 AND deleted_at IS NULL) +
                (SELECT COUNT(*) FROM payroll_run_recurring_deduction_overrides WHERE destination_id = :id4) AS ref_count");
        $stmtRef->execute([':id1' => $id, ':id2' => $id, ':id3' => $id, ':id4' => $id]);
        if ((int)$stmtRef->fetchColumn() > 0) {
            return ['status' => false, 'message' => 'This destination is still used by one or more deduction items and cannot be deleted.'];
        }
        $this->db->prepare("UPDATE `payment_destinations` SET status = 'deleted', deleted_by = :user, deleted_at = CURRENT_TIMESTAMP WHERE id = :id AND comp_id = :comp_id")
            ->execute([':user' => $userId, ':id' => $id, ':comp_id' => $compId]);
        return ['status' => true];
    }

    /**
     * Called from EmployeeEarningDeductionModel::save()/PayrollRunModel::addManualLine()'s own
     * payee_type='other_person' branch -- either reuses an existing saved destination_id (just
     * validates it belongs to this company) or creates a brand-new one (saved or one-off,
     * depending on the caller's own "save for reuse" checkbox state).
     * @return array{status:bool, destination_id?:int, message?:string}
     */
    public function resolveOrCreate(int $compId, array $data, int $userId): array {
        if (!empty($data['destination_id'])) {
            $existing = $this->get($compId, (int)$data['destination_id']);
            if ($existing === null) {
                return ['status' => false, 'message' => 'Invalid destination.'];
            }
            return ['status' => true, 'destination_id' => (int)$existing['id']];
        }
        $created = $this->create($compId, $data, $userId);
        if (!$created['status']) {
            return $created;
        }
        return ['status' => true, 'destination_id' => $created['id']];
    }
}
