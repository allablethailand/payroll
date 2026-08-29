<?php
declare(strict_types=1);

/**
 * 2026-08-29, explicit request: "ต้องการให้มีการตั้งค่าขนาด Font ของผู้ใช้แต่ละคนได้...จะต้องคงเดิมเมื่อ
 * เข้าใช้งานครั้งต่อไปไม่ว่าจะเครื่องไหน รวมถึงภาษาล่าสุดที่ใช้งานก็ต้องเก็บเหมือนกัน" -- per-user font-size
 * (S/M/L) + language preference, persisted SERVER-SIDE on `employees.ui_font_size`/`ui_language`
 * (see database/migrations/2026-08-29_add_employee_ui_preferences.sql) so it survives switching
 * devices/browsers, unlike the language switcher's pre-existing localStorage-only persistence.
 *
 * Deliberately no permission gate beyond "logged in" -- this is always "set MY OWN preference",
 * never another employee's, so there is no separate manage/view distinction to enforce; the acting
 * employee id always comes from the session, never from client input.
 */
class UserPreferenceModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** @return array{ui_language:?string, ui_font_size:string} */
    public function get(int $employeeId, int $compId): array {
        $stmt = $this->db->prepare("SELECT ui_language, ui_font_size FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ui_language' => null, 'ui_font_size' => 'm'];
        }
        return ['ui_language' => $row['ui_language'], 'ui_font_size' => $row['ui_font_size']];
    }

    /** @param ?string $language 'th'/'en'/null (null = clear the saved preference, fall back to
     *   the browser default again) @param string $fontSize 's'/'m'/'l' */
    public function save(int $employeeId, int $compId, ?string $language, string $fontSize): array {
        if ($language !== null && !in_array($language, ['th', 'en'], true)) {
            return ['status' => false, 'message' => 'Invalid language.'];
        }
        if (!in_array($fontSize, ['s', 'm', 'l'], true)) {
            return ['status' => false, 'message' => 'Invalid font size.'];
        }
        // Existence checked separately, not via UPDATE's own rowCount() -- MySQL only counts ROWS
        // ACTUALLY CHANGED for an UPDATE (not rows matched), so re-saving the exact same values a
        // user already has would make rowCount() come back 0 even though the row genuinely exists,
        // incorrectly reporting "Record not found" for a completely valid no-op save.
        $stmtExists = $this->db->prepare("SELECT id FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtExists->execute([':id' => $employeeId, ':comp_id' => $compId]);
        if ($stmtExists->fetchColumn() === false) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $stmt = $this->db->prepare("UPDATE `employees` SET ui_language = :language, ui_font_size = :font_size WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':language' => $language, ':font_size' => $fontSize, ':id' => $employeeId, ':comp_id' => $compId]);
        return ['status' => true, 'message' => 'Saved successfully.'];
    }
}
