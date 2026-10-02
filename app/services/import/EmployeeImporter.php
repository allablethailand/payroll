<?php
declare(strict_types=1);
require_once __DIR__ . '/../EncryptionService.php';
require_once __DIR__ . '/../sync/EmployeeSyncer.php';
require_once __DIR__ . '/EmployeeMatcher.php';

/**
 * Employee file import (entity type `employee_import`). Matches an existing employee by employee code
 * (employees.employee_no), then by National ID (id_card_no_hash); no match creates one.
 *
 * Update rules: a blank cell never overwrites a stored value, and id, comp_id, employee_no, employee_status,
 * created_at and created_by are never written. Distinct from the Origami sync importer registered as `employee`,
 * which keeps its own overwrite rules (tests/import_test.php covers that one).
 */
class EmployeeImporter {
    private PDO $db;
    private EmployeeSyncer $orgLookup;
    private EmployeeMatcher $matcher;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->orgLookup = new EmployeeSyncer($this->db);
        $this->matcher = new EmployeeMatcher($this->db);
    }

    public function entityType(): string {
        return 'employee_import';
    }

    public function templateColumns(): array {
        return [
            'employee_no' => 'Employee Code', 'id_card_no' => 'National ID', 'tax_id_no' => 'Tax ID',
            'name_th' => 'Name (Thai)', 'surname_th' => 'Surname (Thai)', 'name_en' => 'Name (English)', 'surname_en' => 'Surname (English)',
            'date_of_birth' => 'Date of Birth (YYYY-MM-DD)', 'gender' => 'Gender (male/female/other)',
            'personal_email' => 'Personal Email', 'mobile_no' => 'Mobile No.',
            'department_code' => 'Department Code', 'position_code' => 'Position Code', 'shift_code' => 'Shift Code',
            'employment_date' => 'Employment Date (YYYY-MM-DD)',
            'employment_status' => 'Employment Status (probation/permanent/contract/resigned/terminated)',
        ];
    }

    private const PLAIN_COLUMNS = ['name_th', 'surname_th', 'name_en', 'surname_en', 'date_of_birth', 'gender', 'personal_email', 'mobile_no', 'employment_date', 'employment_status'];
    private const ENCRYPTED_COLUMNS = ['id_card_no' => 'id_card_no_hash', 'tax_id_no' => 'tax_id_no_hash'];
    private const ORG_LOOKUPS = [
        'department_code' => ['structure_departments', 'department_code', 'department_id'],
        'position_code' => ['structure_positions', 'position_code', 'position_id'],
        'shift_code' => ['shifts', 'shift_code', 'shift_id'],
    ];

    public function importRow(int $compId, array $row, int $batchId, ?int $triggeredBy): array {
        $v = [];
        foreach (array_keys($this->templateColumns()) as $key) {
            $cell = $row[$key] ?? null;
            $v[$key] = $cell === null ? '' : trim((string)$cell);
        }
        $this->validateFormats($v);

        $matchId = $this->matcher->find($compId, $v['employee_no'], $v['id_card_no'])['id'] ?? null;

        $links = [];
        foreach (self::ORG_LOOKUPS as $field => [$table, $codeCol, $idCol]) {
            if ($v[$field] !== '') {
                $links[$idCol] = $this->orgLookup->resolveRefByCode($table, $codeCol, $compId, $v[$field]);
            }
        }

        return $matchId === null
            ? $this->insert($compId, $v, $links, $batchId, $triggeredBy)
            : $this->update($matchId, $v, $links, $batchId, $triggeredBy);
    }

    private function validateFormats(array $v): void {
        foreach (['date_of_birth', 'employment_date'] as $f) {
            if ($v[$f] !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v[$f]) || !checkdate((int)substr($v[$f], 5, 2), (int)substr($v[$f], 8, 2), (int)substr($v[$f], 0, 4)))) {
                throw new InvalidArgumentException("{$f} must be a valid date in YYYY-MM-DD format.");
            }
        }
        if ($v['gender'] !== '' && !in_array($v['gender'], ['male', 'female', 'other'], true)) {
            throw new InvalidArgumentException('gender must be male, female or other.');
        }
        if ($v['employment_status'] !== '' && !in_array($v['employment_status'], ['probation', 'permanent', 'contract', 'resigned', 'terminated'], true)) {
            throw new InvalidArgumentException('employment_status must be probation, permanent, contract, resigned or terminated.');
        }
        if ($v['personal_email'] !== '' && !filter_var($v['personal_email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('personal_email is not a valid email address.');
        }
    }

    /** Non-blank provided values as column => value, encrypted columns already encrypted plus their hash column. */
    private function writableValues(array $v, array $links): array {
        $out = [];
        foreach (self::PLAIN_COLUMNS as $c) {
            if ($v[$c] !== '') {
                $out[$c] = $v[$c];
            }
        }
        foreach (self::ENCRYPTED_COLUMNS as $c => $hashCol) {
            if ($v[$c] !== '') {
                $out[$c] = EncryptionService::encrypt($v[$c])['value'];
                $out[$hashCol] = EncryptionService::hash($v[$c]);
            }
        }
        return $out + $links;
    }

    private function insert(int $compId, array $v, array $links, int $batchId, ?int $userId): array {
        if ($v['employee_no'] === '') {
            throw new InvalidArgumentException('Employee Code is required to create a new employee.');
        }
        $hasTh = $v['name_th'] !== '' && $v['surname_th'] !== '';
        $hasEn = $v['name_en'] !== '' && $v['surname_en'] !== '';
        if (!$hasTh && !$hasEn) {
            throw new InvalidArgumentException('A new employee needs a name and surname (Thai or English).');
        }
        foreach (['employment_date', 'employment_status'] as $f) {
            if ($v[$f] === '') {
                throw new InvalidArgumentException("{$f} is required to create a new employee.");
            }
        }
        $cols = ['comp_id' => $compId, 'employee_no' => $v['employee_no'], 'data_source' => 'import', 'sync_batch_id' => $batchId, 'created_by' => $userId]
            + $this->writableValues($v, $links);
        if (isset($cols['id_card_no']) || isset($cols['tax_id_no'])) {
            $cols['key_version'] = EncryptionService::currentKeyVersion();
        }
        $names = array_keys($cols);
        $stmt = $this->db->prepare('INSERT INTO employees (' . implode(', ', $names) . ') VALUES (:' . implode(', :', $names) . ')');
        $stmt->execute($cols);
        return ['action' => 'inserted'];
    }

    private function update(int $id, array $v, array $links, int $batchId, ?int $userId): array {
        $cols = $this->writableValues($v, $links);
        if (isset($cols['id_card_no']) || isset($cols['tax_id_no'])) {
            // key_version is one column per row for every encrypted field, so a row on another key version cannot be partly rewritten.
            $stmt = $this->db->prepare("SELECT key_version FROM employees WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $existing = $stmt->fetchColumn();
            if ($existing !== null && $existing !== false && (int)$existing !== EncryptionService::currentKeyVersion()) {
                throw new InvalidArgumentException('This employee\'s encrypted data uses an older key version -- re-encrypt it before importing ID numbers.');
            }
            $cols['key_version'] = EncryptionService::currentKeyVersion();
        }
        $cols['sync_batch_id'] = $batchId;
        $cols['updated_by'] = $userId;
        $sets = [];
        foreach (array_keys($cols) as $c) {
            $sets[] = "{$c} = :{$c}";
        }
        $stmt = $this->db->prepare('UPDATE employees SET ' . implode(', ', $sets) . ', updated_at = CURRENT_TIMESTAMP WHERE id = :__id');
        $stmt->execute($cols + ['__id' => $id]);
        return ['action' => 'updated'];
    }
}
