<?php
declare(strict_types=1);

/**
 * 2026-08-29, follow-up to Bank File Format: "ส่วน Format เอกสารของการนำส่งสรรพากร และ ประกันสังคม ก็อยาก
 * ให้มีการตั้งค่าเหมือนกัน แต่ดูความเหมาะสมว่าจะนำไปไว้ที่ฝั่งไหน" -- see
 * database/migrations/2026-08-29_statutory_format_versions.sql's own header comment for why this
 * is a VERSION SELECTOR (pick which known format version to file) rather than a free field editor
 * like BankFileFormatModel -- government-mandated byte-exact layouts aren't safe to let an admin
 * freely edit.
 *
 * `resolveVersionCode()` is the one method report generators (PndOneReport/Sso110Report) actually
 * call at generation time -- it's the single source of truth for "which version_code does this
 * company want," always returning a real value (falls back to the form's is_default=1 version, so
 * a company that never opens this settings tab still gets sensible behavior, not an error).
 */
class StatutoryFormatVersionModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** Every form_code this settings surface knows about, i.e. every distinct form_code seeded
     *  into master_statutory_format_versions -- adding a new form later is a DB seed, not a code
     *  change here. */
    public function listForms(): array {
        $stmt = $this->db->query("SELECT DISTINCT form_code FROM master_statutory_format_versions WHERE is_active = 1 ORDER BY form_code ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function listVersions(string $formCode): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM master_statutory_format_versions WHERE form_code = :form_code AND is_active = 1 ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute([':form_code' => $formCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['is_verified'] = (bool)$r['is_verified'];
            $r['is_default'] = (bool)$r['is_default'];
        }
        unset($r);
        return $rows;
    }

    /** Full picker payload for the settings UI: every known form_code with its version list and
     *  this company's current selection (falls back to whichever version is_default=1 when the
     *  company hasn't chosen). */
    public function settingsForCompany(int $compId): array {
        $out = [];
        foreach ($this->listForms() as $formCode) {
            $versions = $this->listVersions($formCode);
            $stmt = $this->db->prepare(
                "SELECT version_id FROM company_statutory_format_settings WHERE comp_id = :comp_id AND form_code = :form_code"
            );
            $stmt->execute([':comp_id' => $compId, ':form_code' => $formCode]);
            $selectedId = $stmt->fetchColumn();
            if ($selectedId === false) {
                foreach ($versions as $v) {
                    if ($v['is_default']) {
                        $selectedId = $v['id'];
                        break;
                    }
                }
            }
            $out[] = [
                'form_code' => $formCode,
                'versions' => $versions,
                'selected_version_id' => $selectedId !== false ? (int)$selectedId : null,
            ];
        }
        return $out;
    }

    /** The one method report generators call. Always returns a real version_code (falls back to
     *  the form's default version) -- null only when the form_code has no seeded versions at all
     *  (a form this settings surface has never been told about). */
    public function resolveVersionCode(int $compId, string $formCode): ?string {
        $stmt = $this->db->prepare(
            "SELECT v.version_code
             FROM company_statutory_format_settings s
             INNER JOIN master_statutory_format_versions v ON v.id = s.version_id
             WHERE s.comp_id = :comp_id AND s.form_code = :form_code"
        );
        $stmt->execute([':comp_id' => $compId, ':form_code' => $formCode]);
        $code = $stmt->fetchColumn();
        if ($code !== false) {
            return (string)$code;
        }
        $stmtDefault = $this->db->prepare(
            "SELECT version_code FROM master_statutory_format_versions WHERE form_code = :form_code AND is_default = 1 AND is_active = 1 LIMIT 1"
        );
        $stmtDefault->execute([':form_code' => $formCode]);
        $defaultCode = $stmtDefault->fetchColumn();
        return $defaultCode !== false ? (string)$defaultCode : null;
    }

    public function saveSelection(int $compId, string $formCode, int $versionId, ?int $userId): array {
        $stmt = $this->db->prepare(
            "SELECT id FROM master_statutory_format_versions WHERE id = :id AND form_code = :form_code AND is_active = 1"
        );
        $stmt->execute([':id' => $versionId, ':form_code' => $formCode]);
        if (!$stmt->fetch()) {
            return ['status' => false, 'message' => 'Invalid version for this form.'];
        }
        $stmtExisting = $this->db->prepare(
            "SELECT id FROM company_statutory_format_settings WHERE comp_id = :comp_id AND form_code = :form_code"
        );
        $stmtExisting->execute([':comp_id' => $compId, ':form_code' => $formCode]);
        $existingId = $stmtExisting->fetchColumn();
        if ($existingId) {
            $this->db->prepare(
                "UPDATE company_statutory_format_settings SET version_id = :version_id, updated_by = :user_id, updated_at = CURRENT_TIMESTAMP WHERE id = :id"
            )->execute([':version_id' => $versionId, ':user_id' => $userId, ':id' => $existingId]);
        } else {
            $this->db->prepare(
                "INSERT INTO company_statutory_format_settings (comp_id, form_code, version_id, created_by) VALUES (:comp_id, :form_code, :version_id, :user_id)"
            )->execute([':comp_id' => $compId, ':form_code' => $formCode, ':version_id' => $versionId, ':user_id' => $userId]);
        }
        return ['status' => true, 'message' => 'Saved successfully.'];
    }
}
