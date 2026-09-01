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
 * 2026-09-02: generateNext() is the first real CONSUMER of prefix_format/digit_count/
 * current_number/reset_cycle, wired to PAYROLL_RUN via PayrollRunModel::create() (see that
 * method's own use of it, and generateNext()'s own docblock for the row-locking/reset-cycle
 * mechanics). PAYSLIP/WHT_CERT/BANK_TRANSFER still have no consumer yet -- each is its own,
 * separate wiring task per report/export type, same as before.
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

    /**
     * 2026-09-02, explicit request: "ในตารางให้แสดง Code ของรอบด้วยครับ" -- the first real consumer of
     * prefix_format/digit_count/current_number/reset_cycle, which this model's own docblock has
     * flagged as unwired since 2026-08-23 ("a separate, much larger task"). First caller:
     * PayrollRunModel::create() for document_type_code='PAYROLL_RUN'.
     *
     * Row-locked (SELECT ... FOR UPDATE inside its own transaction) so two documents of the same
     * type created back-to-back never race onto the same number -- silently duplicate-issuing the
     * same code (e.g. 2 payroll runs both "PR-2026-001") would be a real, confusing bug even though
     * this app's actual concurrency profile is low (small admin team, not a public-facing queue).
     * Follows this project's own inTransaction()-check convention (see CLAUDE.md) so a caller that
     * already owns a transaction (PayrollRunModel::create() itself doesn't wrap one, but tests that
     * call it from inside their own file-level transaction do) never gets a nested
     * beginTransaction() error.
     *
     * reset_cycle honored via the new last_reset_key column: 'yearly' stores/compares 'YYYY',
     * 'monthly' stores/compares 'YYYY-MM', 'never' never resets (current_number just climbs
     * forever). Returns null (never throws) on any failure -- generating a code must NEVER block
     * the document itself from being created; the caller treats a null return as "no code this
     * time" and moves on.
     */
    public function generateNext(int $compId, string $documentTypeCode): ?string {
        if (!array_key_exists($documentTypeCode, self::DEFAULTS)) {
            return null;
        }
        $this->ensureSeeded($compId);
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare("SELECT * FROM `document_numbering_settings`
                WHERE comp_id = :comp_id AND document_type_code = :code FOR UPDATE");
            $stmt->execute([':comp_id' => $compId, ':code' => $documentTypeCode]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                if ($own) { $this->db->rollBack(); }
                return null;
            }

            $resetCycle = (string)$row['reset_cycle'];
            $currentKey = $resetCycle === 'monthly' ? date('Y-m') : ($resetCycle === 'yearly' ? date('Y') : null);
            $isNewPeriod = $currentKey !== null && $currentKey !== ($row['last_reset_key'] ?? null);
            $nextNumber = $isNewPeriod ? 1 : ((int)$row['current_number'] + 1);

            $digitCount = (int)$row['digit_count'];
            $code = $this->formatPrefix((string)$row['prefix_format']) . str_pad((string)$nextNumber, $digitCount, '0', STR_PAD_LEFT);

            $params = [':current' => $nextNumber, ':comp_id' => $compId, ':code' => $documentTypeCode];
            if ($currentKey !== null) {
                $params[':key'] = $currentKey;
                $this->db->prepare("UPDATE `document_numbering_settings`
                    SET current_number = :current, last_reset_key = :key, updated_at = CURRENT_TIMESTAMP
                    WHERE comp_id = :comp_id AND document_type_code = :code")->execute($params);
            } else {
                $this->db->prepare("UPDATE `document_numbering_settings`
                    SET current_number = :current, updated_at = CURRENT_TIMESTAMP
                    WHERE comp_id = :comp_id AND document_type_code = :code")->execute($params);
            }

            if ($own) {
                $this->db->commit();
            }
            return $code;
        } catch (Throwable $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return null;
        }
    }

    /** {YYYY}/{MM}/{DD}/{YYYYMMDD} are the only placeholders any DEFAULTS prefix_format actually
     *  uses today -- bracketed tokens don't overlap/collide with each other so a plain str_replace
     *  per token is safe (no ordering trick needed the way e.g. {YYYYMMDD} vs {YYYY} substring
     *  containment might otherwise require). */
    private function formatPrefix(string $prefix): string {
        $now = new DateTime('today');
        return str_replace(
            ['{YYYYMMDD}', '{YYYY}', '{MM}', '{DD}'],
            [$now->format('Ymd'), $now->format('Y'), $now->format('m'), $now->format('d')],
            $prefix
        );
    }
}
