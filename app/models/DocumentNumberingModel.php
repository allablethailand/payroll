<?php
declare(strict_types=1);

/**
 * Document Numbering settings (Document & Approval > "Document Numbering" tab). Was a static
 * HTML mockup with no schema/model behind it at all (2026-08-23, explicit request: "ยังไม่สามารถ
 * ตั้งค่าได้จริง"). document_type_code is a fixed, code-tied set (PAYSLIP/PAYROLL_RUN/WHT_CERT/
 * BANK_TRANSFER) rather than a master table -- each one corresponds to an actual generator
 * elsewhere in the app (PaySlipReport, the WHT export, BankTransferFileReport), not a
 * freely-extensible dropdown a company could add entries to on its own, matching the same
 * reasoning ot_rates.calculation_method already documents for staying a plain enum instead of a
 * master table.
 *
 * Nothing in this codebase actually CONSUMES prefix_format/digit_count/current_number yet to
 * stamp a real running number onto a generated payslip/run/certificate/bank file -- that wiring
 * is a separate, much larger task per report/export type. This model only makes the SETTINGS
 * themselves real (persisted, validated, editable), which is what was actually broken.
 */
class DocumentNumberingModel {
    private PDO $db;

    private const DEFAULTS = [
        'PAYSLIP' => ['prefix_format' => 'PS-{YYYY}{MM}-', 'digit_count' => 4, 'reset_cycle' => 'monthly'],
        'PAYROLL_RUN' => ['prefix_format' => 'PR-{YYYY}-', 'digit_count' => 3, 'reset_cycle' => 'yearly'],
        'WHT_CERT' => ['prefix_format' => 'WHT-{YYYY}-', 'digit_count' => 4, 'reset_cycle' => 'yearly'],
        'BANK_TRANSFER' => ['prefix_format' => 'BT-{YYYYMMDD}-', 'digit_count' => 3, 'reset_cycle' => 'never'],
    ];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** Every fixed document type, seeding any this company has never touched with sensible
     *  defaults first (no separate per-company seed migration needed). */
    public function list(int $compId): array {
        $this->ensureSeeded($compId);
        $stmt = $this->db->prepare("SELECT * FROM `document_numbering_settings`
            WHERE comp_id = :comp_id
            ORDER BY FIELD(document_type_code, 'PAYSLIP', 'PAYROLL_RUN', 'WHT_CERT', 'BANK_TRANSFER')");
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function ensureSeeded(int $compId): void {
        $stmt = $this->db->prepare("SELECT document_type_code FROM `document_numbering_settings` WHERE comp_id = :comp_id");
        $stmt->execute([':comp_id' => $compId]);
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff(array_keys(self::DEFAULTS), $existing);
        if (empty($missing)) {
            return;
        }
        $ins = $this->db->prepare("INSERT INTO `document_numbering_settings`
            (comp_id, document_type_code, prefix_format, digit_count, reset_cycle)
            VALUES (:comp_id, :code, :prefix, :digits, :reset)");
        foreach ($missing as $code) {
            $d = self::DEFAULTS[$code];
            $ins->execute([
                ':comp_id' => $compId, ':code' => $code,
                ':prefix' => $d['prefix_format'], ':digits' => $d['digit_count'], ':reset' => $d['reset_cycle'],
            ]);
        }
    }

    public function save(int $compId, string $documentTypeCode, array $data, int $userId): array {
        if (!array_key_exists($documentTypeCode, self::DEFAULTS)) {
            return ['status' => false, 'message' => 'Invalid document type.'];
        }
        $prefix = trim((string)($data['prefix_format'] ?? ''));
        if ($prefix === '') {
            return ['status' => false, 'message' => 'Prefix format is required.'];
        }
        if (mb_strlen($prefix) > 50) {
            return ['status' => false, 'message' => 'Prefix format must be 50 characters or fewer.'];
        }
        $digitCount = (int)($data['digit_count'] ?? 0);
        if ($digitCount < 1 || $digitCount > 10) {
            return ['status' => false, 'message' => 'Digit count must be between 1 and 10.'];
        }
        $currentNumber = (int)($data['current_number'] ?? 0);
        if ($currentNumber < 0) {
            return ['status' => false, 'message' => 'Current number cannot be negative.'];
        }
        $maxForDigits = (10 ** $digitCount) - 1;
        if ($currentNumber > $maxForDigits) {
            return ['status' => false, 'message' => "Current number cannot exceed {$maxForDigits} with {$digitCount} digits."];
        }
        $resetCycle = (string)($data['reset_cycle'] ?? 'never');
        if (!in_array($resetCycle, ['never', 'yearly', 'monthly'], true)) {
            return ['status' => false, 'message' => 'Invalid reset cycle.'];
        }
        $this->ensureSeeded($compId);
        $stmt = $this->db->prepare("UPDATE `document_numbering_settings`
            SET prefix_format = :prefix, digit_count = :digits, current_number = :current,
                reset_cycle = :reset, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
            WHERE comp_id = :comp_id AND document_type_code = :code");
        $stmt->execute([
            ':prefix' => $prefix, ':digits' => $digitCount, ':current' => $currentNumber,
            ':reset' => $resetCycle, ':updated_by' => $userId, ':comp_id' => $compId, ':code' => $documentTypeCode,
        ]);
        return ['status' => true, 'message' => 'Saved successfully.'];
    }
}
