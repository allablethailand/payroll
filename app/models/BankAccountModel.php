<?php
declare(strict_types=1);
class BankAccountModel {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }

    /** Frontend column KEY -> real SQL expression for the Excel-style column filter (2026-08-27
     *  rollout). `account_no` (ciphertext, decrypted only per-row after the query above) and
     *  `is_default` (boolean icon, not a meaningful checkbox label) are deliberately NOT included --
     *  same exclusion policy every other table in this rollout applies to its own non-filterable
     *  columns; `bank_name` is `$lang`-resolved the same way EmployeeModel::listColumnExprMap() does. */
    private function columnFilterExprMap(string $lang): array {
        return [
            'bank_name' => $lang === 'en' ? 'mb.bank_name_en' : 'mb.bank_name_th',
            'account_name' => 'ba.account_name',
            'branch_name' => 'ba.branch_name',
            'account_type' => 'ba.account_type',
            'status' => 'ba.status',
        ];
    }

    private function applyColumnFilters(string $whereSql, array &$params, array $columnFilters, array $exprMap, ?string $excludeColumn = null): string {
        $paramIdx = 0;
        foreach ($columnFilters as $col => $values) {
            if ($col === $excludeColumn || !isset($exprMap[$col]) || !is_array($values) || empty($values)) {
                continue;
            }
            $values = array_values(array_filter($values, fn($v) => $v !== null && $v !== ''));
            if (empty($values)) {
                continue;
            }
            $placeholders = [];
            foreach ($values as $v) {
                $paramIdx++;
                $ph = ":cf{$paramIdx}";
                $placeholders[] = $ph;
                $params[$ph] = (string)$v;
            }
            $whereSql .= " AND {$exprMap[$col]} IN (" . implode(', ', $placeholders) . ")";
        }
        return $whereSql;
    }

    public function list(int $compId, int $start, int $length, string $search, int $colIndex, string $orderDir, string $lang = 'th', array $columnFilters = []): array {
        $sortColumns = [
            0 => '`ba`.`id`',
            1 => '`mb`.`bank_name_th`',
            2 => '`ba`.`id`', // account_no is encrypted (ciphertext) — sorting by it is meaningless, fall back to id
            3 => '`ba`.`account_name`',
            4 => '`ba`.`account_type`',
            5 => '`ba`.`is_default`',
            6 => '`ba`.`status`',
        ];
        $sortColumn = $sortColumns[$colIndex] ?? $sortColumns[0];
        $orderDir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';

        $baseWhere = "ba.comp_id = :comp_id AND ba.deleted_at IS NULL AND ba.status != 'deleted'";
        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `bank_accounts` ba WHERE {$baseWhere}");
        $totalStmt->execute([':comp_id' => $compId]);
        $recordsTotal = (int)$totalStmt->fetchColumn();

        $whereSql = $baseWhere;
        $params = [':comp_id' => $compId];
        if ($search !== '') {
            // account_no is encrypted so LIKE can't match it; an exact-match via the HMAC hash
            // still lets users find an account by typing the full account number.
            $whereSql .= " AND (ba.account_no_hash = :searchHash OR ba.account_name LIKE :search2 OR mb.bank_name_th LIKE :search3 OR mb.bank_name_en LIKE :search4)";
            $params[':searchHash'] = EncryptionService::hash($search) ?? '';
            $params[':search2'] = "%{$search}%";
            $params[':search3'] = "%{$search}%";
            $params[':search4'] = "%{$search}%";
        }
        // 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter rollout.
        $whereSql = $this->applyColumnFilters($whereSql, $params, $columnFilters, $this->columnFilterExprMap($lang));

        $countSql = "SELECT COUNT(*) FROM `bank_accounts` ba LEFT JOIN `master_banks` mb ON ba.bank_id = mb.id WHERE {$whereSql}";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $recordsFiltered = (int)$countStmt->fetchColumn();

        $dataSql = "SELECT ba.*, mb.bank_code, mb.bank_name_th, mb.bank_name_en
                     FROM `bank_accounts` ba
                     LEFT JOIN `master_banks` mb ON ba.bank_id = mb.id
                     WHERE {$whereSql}
                     ORDER BY {$sortColumn} {$orderDir}
                     LIMIT :limit OFFSET :offset";
        $dataStmt = $this->db->prepare($dataSql);
        foreach ($params as $key => $val) {
            $dataStmt->bindValue($key, $val);
        }
        $dataStmt->bindValue(':limit', $length, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $start, PDO::PARAM_INT);
        $dataStmt->execute();
        $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($data as &$row) {
            $keyVersion = isset($row['key_version']) ? (int)$row['key_version'] : null;
            $row['account_no'] = EncryptionService::decrypt($row['account_no'] ?? null, $keyVersion);
        }
        unset($row);

        return [
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $data,
        ];
    }

    /** Distinct values for ONE column of the bank account list, respecting every OTHER active
     *  Excel-style column filter but not this column's own selection -- see
     *  EmployeeModel::listColumnValues()'s own docblock for why. */
    public function columnDistinctValues(int $compId, string $column, string $lang, array $columnFilters, ?string $excludeColumn): array {
        $exprMap = $this->columnFilterExprMap($lang);
        if (!isset($exprMap[$column])) {
            return [];
        }
        $expr = $exprMap[$column];
        $where = "ba.comp_id = :comp_id AND ba.deleted_at IS NULL AND ba.status != 'deleted'";
        $params = [':comp_id' => $compId];
        $where = $this->applyColumnFilters($where, $params, $columnFilters, $exprMap, $excludeColumn);
        $sql = "SELECT DISTINCT {$expr} AS value FROM `bank_accounts` ba LEFT JOIN `master_banks` mb ON ba.bank_id = mb.id
                WHERE {$where} AND {$expr} IS NOT NULL AND {$expr} != ''
                ORDER BY value ASC LIMIT 500";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'value');
    }

    private function isAccountNoDuplicate(int $compId, string $accountNoHash, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM `bank_accounts` WHERE comp_id = :comp_id AND account_no_hash = :account_no_hash AND deleted_at IS NULL";
        $params = [':comp_id' => $compId, ':account_no_hash' => $accountNoHash];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function isValidActiveBank(int $bankId): bool {
        $stmt = $this->db->prepare("SELECT id FROM `master_banks` WHERE id = :id AND is_active = 1");
        $stmt->execute([':id' => $bankId]);
        return (bool)$stmt->fetch();
    }

    public function save(int $compId, array $data, int $userId): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        foreach (['bank_id', 'account_no', 'account_name'] as $field) {
            if (empty($data[$field])) {
                return ['status' => false, 'message' => "Missing required field: {$field}"];
            }
        }

        $bankId = (int)$data['bank_id'];
        if (!$this->isValidActiveBank($bankId)) {
            return ['status' => false, 'message' => 'Invalid bank selected.'];
        }

        $accountNo = trim((string)$data['account_no']);
        if (!preg_match('/^[0-9\-]{4,30}$/', $accountNo)) {
            return ['status' => false, 'message' => 'Account number must contain only digits and dashes (4-30 characters).'];
        }
        $accountNoHash = EncryptionService::hash($accountNo);
        if ($this->isAccountNoDuplicate($compId, $accountNoHash, $id)) {
            return ['status' => false, 'message' => 'This account number is already registered for this company.'];
        }
        $accountNoEnc = EncryptionService::encrypt($accountNo);
        $keyVersion = $accountNoEnc['key_version'] ?? EncryptionService::currentKeyVersion();

        $accountTypeInput = $data['account_type'] ?? 'savings';
        $accountType = in_array($accountTypeInput, ['savings', 'current'], true) ? $accountTypeInput : 'savings';
        $currencyCode = !empty($data['currency_code']) ? strtoupper(substr((string)$data['currency_code'], 0, 3)) : 'THB';
        $statusInput = $data['status'] ?? 'active';
        $status = in_array($statusInput, ['active', 'inactive'], true) ? $statusInput : 'active';
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        $branchName = !empty($data['branch_name']) ? trim((string)$data['branch_name']) : null;
        $accountName = trim((string)$data['account_name']);

        try {
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM `bank_accounts` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $sql = "UPDATE `bank_accounts` SET
                            bank_id = :bank_id,
                            account_no = :account_no,
                            account_no_hash = :account_no_hash,
                            key_version = :key_version,
                            account_name = :account_name,
                            branch_name = :branch_name,
                            account_type = :account_type,
                            currency_code = :currency_code,
                            is_default = :is_default,
                            status = :status,
                            updated_by = :updated_by,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    ':bank_id' => $bankId,
                    ':account_no' => $accountNoEnc['value'] ?? null,
                    ':account_no_hash' => $accountNoHash,
                    ':key_version' => $keyVersion,
                    ':account_name' => $accountName,
                    ':branch_name' => $branchName,
                    ':account_type' => $accountType,
                    ':currency_code' => $currencyCode,
                    ':is_default' => $isDefault,
                    ':status' => $status,
                    ':updated_by' => $userId,
                    ':id' => $id,
                ]);
                return ['status' => true, 'message' => 'Updated successfully.', 'id' => $id];
            }

            $sql = "INSERT INTO `bank_accounts`
                        (comp_id, bank_id, account_no, account_no_hash, key_version, account_name, branch_name, account_type, currency_code, is_default, status, created_by)
                    VALUES
                        (:comp_id, :bank_id, :account_no, :account_no_hash, :key_version, :account_name, :branch_name, :account_type, :currency_code, :is_default, :status, :created_by)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':comp_id' => $compId,
                ':bank_id' => $bankId,
                ':account_no' => $accountNoEnc['value'] ?? null,
                ':account_no_hash' => $accountNoHash,
                ':key_version' => $keyVersion,
                ':account_name' => $accountName,
                ':branch_name' => $branchName,
                ':account_type' => $accountType,
                ':currency_code' => $currencyCode,
                ':is_default' => $isDefault,
                ':status' => $status,
                ':created_by' => $userId,
            ]);
            return ['status' => true, 'message' => 'Created successfully.', 'id' => (int)$this->db->lastInsertId()];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function delete(int $compId, int $id, int $userId): array {
        try {
            $stmtCheck = $this->db->prepare("SELECT id FROM `bank_accounts` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
            if (!$stmtCheck->fetch()) {
                return ['status' => false, 'message' => 'Record not found.'];
            }
            $stmt = $this->db->prepare("UPDATE `bank_accounts` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id");
            $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
            return ['status' => true, 'message' => 'Deleted successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }
}
