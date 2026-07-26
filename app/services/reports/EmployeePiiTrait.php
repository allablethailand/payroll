<?php
declare(strict_types=1);
require_once __DIR__ . '/../EncryptionService.php';

/** Shared helper for decrypting employee PII fields inside a payroll_run_details row. */
trait EmployeePiiTrait {
    protected function decryptEmployeeField(array $row, string $field): ?string {
        return EncryptionService::decrypt($row[$field] ?? null, isset($row['key_version']) ? (int)$row['key_version'] : null);
    }

    protected function employeeDisplayName(array $row, string $lang = 'th'): string {
        if ($lang === 'th') {
            return trim(($row['name_th'] ?? '') . ' ' . ($row['surname_th'] ?? ''));
        }
        return trim(($row['name_en'] ?? '') . ' ' . ($row['surname_en'] ?? ''));
    }
}
