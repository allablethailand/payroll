<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/Database.php';

/**
 * 2026-09-02, explicit request following an AskUserQuestion exchange -- flagged (not guessed) that
 * Thai PIT withholding on Thailand-source SALARY income (มาตรา 50 ทวิ) uses the SAME progressive
 * bracket table regardless of resident/non-resident status; the user confirmed there MAY be a real
 * different rate their accountant applies, but doesn't know the exact figure right now, so this is
 * a plain COMPANY-CONFIGURABLE flat-rate override -- never a hardcoded "correct" rate this app
 * asserts -- same established convention as `PayrollPolicyModel`'s own
 * `supplemental_flat_tax_rate_percent` (see that migration's own header for the precedent this
 * mirrors). Singleton row per company, same shape/pattern as `StatutoryFormatVersionModel`.
 *
 * `enabled=0` (the default, and what every company that never visits this tab stays at) means
 * `PayrollRunModel::recalculate()` never takes the flat-rate branch at all -- zero behavior change.
 * When enabled AND `flat_rate_percent` is actually set, the flat rate applies ONLY to employees
 * individually flagged `employees.tax_non_resident = 1` -- every other employee keeps using the
 * normal average/actual progressive calculation regardless of this setting.
 */
class NonResidentTaxSettingModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function get(int $compId): array {
        $stmt = $this->db->prepare("SELECT * FROM `company_nonresident_tax_settings` WHERE comp_id = :comp_id");
        $stmt->execute([':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'comp_id' => (int)$row['comp_id'],
                'enabled' => (bool)$row['enabled'],
                'flat_rate_percent' => $row['flat_rate_percent'] !== null ? (float)$row['flat_rate_percent'] : null,
                'reference_note' => $row['reference_note'],
                'updated_at' => $row['updated_at'],
            ];
        }
        return ['comp_id' => $compId, 'enabled' => false, 'flat_rate_percent' => null, 'reference_note' => null, 'updated_at' => null];
    }

    /** Only what `PayrollRunModel::recalculate()` actually needs at calc time -- avoids that
     *  method having to know this model's full row shape. Returns null when not enabled (the
     *  common case), so the caller's own `if ($settings !== null && ...)` reads as "this company
     *  opted in" without re-checking the `enabled` flag itself. */
    public function activeFlatRatePercent(int $compId): ?float {
        $row = $this->get($compId);
        return ($row['enabled'] && $row['flat_rate_percent'] !== null) ? $row['flat_rate_percent'] : null;
    }

    public function save(int $compId, array $data, int $userId): array {
        $enabled = !empty($data['enabled']);
        $flatRatePercent = null;
        if (isset($data['flat_rate_percent']) && $data['flat_rate_percent'] !== '' && $data['flat_rate_percent'] !== null) {
            if (!is_numeric($data['flat_rate_percent']) || (float)$data['flat_rate_percent'] < 0 || (float)$data['flat_rate_percent'] > 100) {
                return ['status' => false, 'message' => 'flat_rate_percent must be a number between 0 and 100.'];
            }
            $flatRatePercent = round((float)$data['flat_rate_percent'], 2);
        }
        if ($enabled && $flatRatePercent === null) {
            return ['status' => false, 'message' => 'A flat rate is required when this setting is enabled.'];
        }
        $referenceNote = isset($data['reference_note']) ? trim((string)$data['reference_note']) : null;
        if ($referenceNote === '') {
            $referenceNote = null;
        }
        if ($referenceNote !== null && mb_strlen($referenceNote) > 500) {
            return ['status' => false, 'message' => 'reference_note must be 500 characters or fewer.'];
        }

        $stmt = $this->db->prepare("INSERT INTO `company_nonresident_tax_settings` (comp_id, enabled, flat_rate_percent, reference_note, updated_by)
            VALUES (:comp_id, :enabled, :flat_rate_percent, :reference_note, :updated_by)
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), flat_rate_percent = VALUES(flat_rate_percent),
                reference_note = VALUES(reference_note), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([
            ':comp_id' => $compId, ':enabled' => $enabled ? 1 : 0, ':flat_rate_percent' => $flatRatePercent,
            ':reference_note' => $referenceNote, ':updated_by' => $userId,
        ]);
        return ['status' => true];
    }
}
