<?php
declare(strict_types=1);
require_once __DIR__ . '/../EncryptionService.php';

/** Finds an existing employee for an import row: employee code first, then National ID (hash). Shared by every employee-keyed importer. */
class EmployeeMatcher {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /**
     * @return ?array{id:int, employee_no:string} null when neither identifier matches anyone
     * @throws InvalidArgumentException when the identifiers point at two different employees, or the National ID matches several
     */
    public function find(int $compId, string $employeeNo, string $nationalId): ?array {
        $byCode = null;
        if ($employeeNo !== '') {
            $stmt = $this->db->prepare("SELECT id, employee_no FROM employees WHERE comp_id = :c AND employee_no = :no AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([':c' => $compId, ':no' => $employeeNo]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $byCode = $row ? ['id' => (int)$row['id'], 'employee_no' => (string)$row['employee_no']] : null;
        }
        $byId = null;
        if ($nationalId !== '') {
            $stmt = $this->db->prepare("SELECT id, employee_no FROM employees WHERE comp_id = :c AND id_card_no_hash = :h AND deleted_at IS NULL LIMIT 2");
            $stmt->execute([':c' => $compId, ':h' => EncryptionService::hash($nationalId)]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 1) {
                throw new InvalidArgumentException('National ID matches more than one employee.');
            }
            $byId = $rows ? ['id' => (int)$rows[0]['id'], 'employee_no' => (string)$rows[0]['employee_no']] : null;
        }
        if ($byCode !== null && $byId !== null && $byCode['id'] !== $byId['id']) {
            throw new InvalidArgumentException("National ID belongs to another employee (code {$byId['employee_no']}).");
        }
        return $byCode ?? $byId;
    }
}
