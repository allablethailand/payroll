<?php
declare(strict_types=1);

/**
 * 2026-08-29, explicit request: "ต้องการให้มีการตั้งค่าขนาด Font ของผู้ใช้แต่ละคนได้...จะต้องคงเดิมเมื่อ
 * เข้าใช้งานครั้งต่อไปไม่ว่าจะเครื่องไหน รวมถึงภาษาล่าสุดที่ใช้งานก็ต้องเก็บเหมือนกัน" -- per-user font-size
 * (S/M/L) + language preference, persisted SERVER-SIDE on `employees.ui_font_size`/`ui_language`
 * (see database/migrations/2026-08-29_add_employee_ui_preferences.sql) so it survives switching
 * devices/browsers, unlike the language switcher's pre-existing localStorage-only persistence.
 *
 * 2026-09-04, Backlog Phase 11, T069 (dark mode), Step 1 of 3 -- `ui_theme` added to this SAME
 * model/table rather than a new one, following the identical established pattern (see
 * database/migrations/2026-09-04_11_employee_ui_theme.sql's own docblock for the original
 * NULL="follow system" design decision -- since REVISED, see next paragraph). `get()`/`save()`
 * widened, NOT split into a separate theme-only method
 * pair -- every existing call site already sends language+fontSize together on every save (see the
 * "always send all values together" discipline documented in app.js's own persistUserPreferences()),
 * so widening the same 2 methods keeps that one discipline in one place instead of introducing a
 * second, easy-to-forget partial-update path that could silently reset theme back to NULL on a
 * plain font-size-only save (or vice versa).
 *
 * 2026-09-05, real bug found and fixed -- NULL was overloaded to mean both "never touched
 * Settings" and "explicitly chose System", so a never-configured employee whose OS/browser
 * happened to be dark saw the app go dark before they had ever opened Settings. `ui_theme` widened
 * to `ENUM('light','dark','system')` (see database/migrations/2026-09-05_1_ui_theme_add_system_value.sql)
 * so "explicitly follow the OS" is its own real value -- NULL now means ONLY "never configured"
 * and defaults to Light (see header.php's own stamping logic, the actual place this default is
 * enforced; this model just needs to accept and persist 'system' as a distinct valid value now).
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

    /** @return array{ui_language:?string, ui_font_size:string, ui_theme:?string} */
    public function get(int $employeeId, int $compId): array {
        $stmt = $this->db->prepare("SELECT ui_language, ui_font_size, ui_theme FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['ui_language' => null, 'ui_font_size' => 'm', 'ui_theme' => null];
        }
        return ['ui_language' => $row['ui_language'], 'ui_font_size' => $row['ui_font_size'], 'ui_theme' => $row['ui_theme']];
    }

    /** @param ?string $language 'th'/'en'/null (null = clear the saved preference, fall back to
     *   the browser default again) @param string $fontSize 's'/'m'/'l'
     *   @param ?string $theme 'light'/'dark'/'system'/null -- null (never configured) defaults to
     *   Light (see header.php); 'system' is an EXPLICIT choice to follow prefers-color-scheme,
     *   distinct from null since 2026-09-05 (see this file's own top-of-class docblock) */
    public function save(int $employeeId, int $compId, ?string $language, string $fontSize, ?string $theme = null): array {
        if ($language !== null && !in_array($language, ['th', 'en'], true)) {
            return ['status' => false, 'message' => 'Invalid language.'];
        }
        if (!in_array($fontSize, ['s', 'm', 'l'], true)) {
            return ['status' => false, 'message' => 'Invalid font size.'];
        }
        if ($theme !== null && !in_array($theme, ['light', 'dark', 'system'], true)) {
            return ['status' => false, 'message' => 'Invalid theme.'];
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
        $stmt = $this->db->prepare("UPDATE `employees` SET ui_language = :language, ui_font_size = :font_size, ui_theme = :theme WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':language' => $language, ':font_size' => $fontSize, ':theme' => $theme, ':id' => $employeeId, ':comp_id' => $compId]);
        return ['status' => true, 'message' => 'Saved successfully.'];
    }
}
