<?php
declare(strict_types=1);

/**
 * 2026-09-02, explicit request: employee-level payment method type (transfer/cash/check/mixed,
 * `master_payment_methods` lookup table -- see that table's own migration header for why a lookup
 * table over a plain enum). This model owns:
 *  - `employee_payment_method_lines` CRUD (only populated while an employee's own
 *    `employees.payment_method_id` resolves to the 'mixed' code) -- delete+reinsert on every save,
 *    same "replace the whole child set" convention this project already uses elsewhere (e.g.
 *    approval_workflow_steps, holiday_assignments) since a mixed payment split is a small,
 *    all-or-nothing configuration, not something with in-flight state tied to individual line ids.
 *  - Mixed-line validation. Per this project's own planning discussion: the true "sums to exactly
 *    net pay" rule for FIXED-amount lines can only be checked once net pay is known (payroll-run
 *    time, see PayrollRunModel's own mixed-payment branch) -- this model only validates what's
 *    knowable at Employee-save time: a set of PURELY percent-type lines must sum to exactly 100.
 *    Any set containing a `fixed` line skips the sum check entirely here (deferred to run time,
 *    surfaced there as a non-blocking advisory `calc_errors` flag, same convention as
 *    `hourly_salary_no_attendance_data`/`sync_actual_days_no_data` elsewhere in that model).
 *  - `scopedBankAccountOptions()`: cycle-scoped bank account picker, used by BOTH the Employment
 *    tab's `default_bank_account_id` field and any mixed line's own transfer bank-account picker --
 *    falls back to the company's own `is_default=1` account when the cycle has zero accounts
 *    configured, mirroring PayrollRunEmployeeBankAccountModel::resolveForRun()'s own final fallback
 *    step so what an admin is OFFERED here always matches what a real payroll run would actually
 *    resolve to.
 */
class EmployeePaymentMethodModel {
    private PDO $db;

    // 2026-09-02, follow-up cleanup: the legacy employees.payment_type enum mirror (and this class's
    // own LEGACY_PAYMENT_TYPE_MAP that kept it in sync) has been dropped entirely -- see
    // database/migrations/2026-09-02_19_drop_legacy_payment_type.sql. payment_method_id
    // (master_payment_methods) is the sole source of truth everywhere now.

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** @return array{id:int, code:string}|null */
    public function findMethod(int $methodId): ?array {
        $stmt = $this->db->prepare("SELECT id, code FROM `master_payment_methods` WHERE id = :id AND is_active = 1");
        $stmt->execute([':id' => $methodId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function methodOptions(string $search, int $page, int $limit, ?string $excludeCode = null): array {
        $offset = ($page - 1) * $limit;
        $where = "WHERE is_active = 1";
        $params = [];
        if ($excludeCode !== null && $excludeCode !== '') {
            $where .= " AND code != :exclude_code";
            $params[':exclude_code'] = $excludeCode;
        }
        if ($search !== '') {
            $where .= " AND (name_th LIKE :search1 OR name_en LIKE :search2)";
            $params[':search1'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
        }
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `master_payment_methods` {$where}");
        $totalStmt->execute($params);
        $totalCount = (int)$totalStmt->fetchColumn();

        $sql = "SELECT id, code, name_th AS text_th, name_en AS text_en FROM `master_payment_methods` {$where} ORDER BY sort_order ASC, id ASC LIMIT :offset, :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total_count' => $totalCount];
    }

    /**
     * Validates a raw array of mixed-line submissions (from the Employment tab's repeatable-row
     * form). Returns ['status'=>true, 'lines'=>array] (normalized, ready for saveLines()) or
     * ['status'=>false, 'message'=>string].
     */
    public function validateMixedLines(int $compId, array $rawLines): array {
        if (empty($rawLines)) {
            return ['status' => false, 'message' => 'At least one payment line is required for a mixed payment method.'];
        }
        $normalized = [];
        $percentSum = 0.0;
        $hasFixed = false;
        foreach ($rawLines as $i => $line) {
            $methodId = !empty($line['payment_method_id']) ? (int)$line['payment_method_id'] : 0;
            $method = $methodId > 0 ? $this->findMethod($methodId) : null;
            if (!$method) {
                return ['status' => false, 'message' => "Line " . ($i + 1) . ": invalid payment method."];
            }
            if ($method['code'] === 'mixed') {
                return ['status' => false, 'message' => "Line " . ($i + 1) . ": a mixed line cannot itself be 'mixed'."];
            }
            $amountType = ($line['amount_type'] ?? '') === 'percent' ? 'percent' : (($line['amount_type'] ?? '') === 'fixed' ? 'fixed' : null);
            if ($amountType === null) {
                return ['status' => false, 'message' => "Line " . ($i + 1) . ": amount_type must be 'fixed' or 'percent'."];
            }
            $amountValue = isset($line['amount_value']) && is_numeric($line['amount_value']) ? (float)$line['amount_value'] : null;
            if ($amountValue === null || $amountValue <= 0) {
                return ['status' => false, 'message' => "Line " . ($i + 1) . ": amount_value must be a positive number."];
            }
            if ($amountType === 'percent' && $amountValue > 100) {
                return ['status' => false, 'message' => "Line " . ($i + 1) . ": percent amount cannot exceed 100."];
            }
            $bankAccountId = null;
            if ($method['code'] === 'transfer') {
                $bankAccountId = !empty($line['bank_account_id']) ? (int)$line['bank_account_id'] : 0;
                if ($bankAccountId <= 0) {
                    return ['status' => false, 'message' => "Line " . ($i + 1) . ": a bank account is required for a transfer line."];
                }
                $stmt = $this->db->prepare("SELECT id FROM `bank_accounts` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL AND status = 'active'");
                $stmt->execute([':id' => $bankAccountId, ':comp_id' => $compId]);
                if (!$stmt->fetch()) {
                    return ['status' => false, 'message' => "Line " . ($i + 1) . ": invalid bank account."];
                }
            }
            if ($amountType === 'percent') {
                $percentSum += $amountValue;
            } else {
                $hasFixed = true;
            }
            $normalized[] = [
                'sort_order' => $i,
                'payment_method_id' => $methodId,
                'amount_type' => $amountType,
                'amount_value' => $amountValue,
                'bank_account_id' => $bankAccountId,
            ];
        }
        // Percent-only configs are fully checkable now (no unknowns) -- a set containing any fixed
        // line defers its sum-equals-net-pay check to payroll-run time (see this class's own
        // docblock), since net pay isn't known here.
        if (!$hasFixed && abs($percentSum - 100.0) > 0.01) {
            return ['status' => false, 'message' => "Percent lines must sum to exactly 100 (currently {$percentSum})."];
        }
        return ['status' => true, 'lines' => $normalized];
    }

    /** Replaces the WHOLE line set for one employee -- see this class's own docblock on why
     *  delete+reinsert rather than diff-and-patch. Caller (EmployeeModel::save()) is expected to
     *  have already validated via validateMixedLines(); this trusts $lines' shape as-is. */
    public function saveLines(int $employeeId, array $lines, int $userId): void {
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $del = $this->db->prepare("DELETE FROM `employee_payment_method_lines` WHERE employee_id = :employee_id");
            $del->execute([':employee_id' => $employeeId]);
            if (!empty($lines)) {
                $ins = $this->db->prepare(
                    "INSERT INTO `employee_payment_method_lines`
                        (employee_id, sort_order, payment_method_id, amount_type, amount_value, bank_account_id, created_by)
                     VALUES (:employee_id, :sort_order, :payment_method_id, :amount_type, :amount_value, :bank_account_id, :created_by)"
                );
                foreach ($lines as $line) {
                    $ins->execute([
                        ':employee_id' => $employeeId,
                        ':sort_order' => $line['sort_order'],
                        ':payment_method_id' => $line['payment_method_id'],
                        ':amount_type' => $line['amount_type'],
                        ':amount_value' => $line['amount_value'],
                        ':bank_account_id' => $line['bank_account_id'],
                        ':created_by' => $userId,
                    ]);
                }
            }
            if ($own) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($own) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function getLines(int $employeeId): array {
        $stmt = $this->db->prepare(
            "SELECT l.*, mpm.code AS payment_method_code, mpm.name_th AS payment_method_name_th, mpm.name_en AS payment_method_name_en,
                    ba.account_name AS bank_account_name
             FROM `employee_payment_method_lines` l
             LEFT JOIN `master_payment_methods` mpm ON mpm.id = l.payment_method_id
             LEFT JOIN `bank_accounts` ba ON ba.id = l.bank_account_id
             WHERE l.employee_id = :employee_id ORDER BY l.sort_order ASC, l.id ASC"
        );
        $stmt->execute([':employee_id' => $employeeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Bank accounts available for ONE payroll cycle -- falls back to the company's own
     * is_default=1 account when the cycle has none configured, mirroring
     * PayrollRunEmployeeBankAccountModel::resolveForRun()'s own final fallback step so this
     * picker's OPTIONS always match what a real run would actually resolve an unset choice to.
     */
    public function scopedBankAccountOptions(int $compId, int $cycleId, string $search, int $page, int $limit): array {
        $offset = ($page - 1) * $limit;
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `payroll_cycle_bank_accounts` WHERE cycle_id = :cycle_id");
        $countStmt->execute([':cycle_id' => $cycleId]);
        $hasCycleAccounts = (int)$countStmt->fetchColumn() > 0;

        $where = $hasCycleAccounts
            ? "WHERE pcba.cycle_id = :cycle_id"
            : "WHERE ba.comp_id = :comp_id AND ba.is_default = 1";
        $params = $hasCycleAccounts ? [':cycle_id' => $cycleId] : [':comp_id' => $compId];
        if ($search !== '') {
            $where .= " AND (ba.account_name LIKE :search1 OR mb.bank_name_th LIKE :search2 OR mb.bank_name_en LIKE :search3)";
            $params[':search1'] = "%{$search}%";
            $params[':search2'] = "%{$search}%";
            $params[':search3'] = "%{$search}%";
        }
        $joinPcba = $hasCycleAccounts ? "JOIN `payroll_cycle_bank_accounts` pcba ON pcba.bank_account_id = ba.id" : "";
        $baseSql = "FROM `bank_accounts` ba {$joinPcba} LEFT JOIN `master_banks` mb ON mb.id = ba.bank_id {$where} AND ba.deleted_at IS NULL AND ba.status = 'active'";

        $totalStmt = $this->db->prepare("SELECT COUNT(*) {$baseSql}");
        $totalStmt->execute($params);
        $totalCount = (int)$totalStmt->fetchColumn();

        $orderBy = $hasCycleAccounts ? "pcba.is_default DESC, ba.id ASC" : "ba.id ASC";
        $sql = "SELECT ba.id,
                    CONCAT(mb.bank_name_th, ' - ', ba.account_name) AS text_th,
                    CONCAT(mb.bank_name_en, ' - ', ba.account_name) AS text_en
                {$baseSql} ORDER BY {$orderBy} LIMIT :offset, :limit";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total_count' => $totalCount, 'scoped_to_cycle' => $hasCycleAccounts];
    }

    /** True when $bankAccountId is a valid option for $cycleId per scopedBankAccountOptions()'s own
     *  resolution rule -- used by EmployeeModel::save() to reject an out-of-scope selection. */
    public function isBankAccountValidForCycle(int $compId, int $cycleId, int $bankAccountId): bool {
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `payroll_cycle_bank_accounts` WHERE cycle_id = :cycle_id");
        $countStmt->execute([':cycle_id' => $cycleId]);
        if ((int)$countStmt->fetchColumn() > 0) {
            $stmt = $this->db->prepare("SELECT id FROM `payroll_cycle_bank_accounts` WHERE cycle_id = :cycle_id AND bank_account_id = :bank_account_id");
            $stmt->execute([':cycle_id' => $cycleId, ':bank_account_id' => $bankAccountId]);
            return (bool)$stmt->fetch();
        }
        $stmt = $this->db->prepare("SELECT id FROM `bank_accounts` WHERE id = :id AND comp_id = :comp_id AND is_default = 1 AND deleted_at IS NULL");
        $stmt->execute([':id' => $bankAccountId, ':comp_id' => $compId]);
        return (bool)$stmt->fetch();
    }
}
