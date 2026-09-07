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

    /**
     * 2026-09-07, explicit request: "ทางลัด...ปรับเป็นให้อยู่บน header ไปเลย...โดยให้ผู้ใช้เลือกได้ว่าจะโชว์
     * หรือไม่โชว์เมนูไหน" -- per-user header Quick Links selection, same `employees` table as
     * ui_language/ui_font_size/ui_theme above (see 2026-09-07_1_employee_ui_quick_links.sql).
     *
     * Returns `null` (not `[]`) when the column itself is NULL -- the one meaningful distinction a
     * plain array can't carry on its own: `null` means "this employee has never opened Customize
     * Quick Links at all" (caller should fall back to the built-in default set), while `[]` means
     * "opened it and explicitly unchecked everything" (caller must respect that and show none).
     * layout/header.php's own `$quickLinkSelectedKeys = $savedQuickLinkKeys ?? $defaultQuickLinkKeys;`
     * is the one place this distinction actually matters.
     *
     * @return ?string[] catalog keys, in the user's own saved display order
     */
    public function getQuickLinks(int $employeeId, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT ui_quick_links FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $raw = $stmt->fetchColumn();
        if ($raw === false || $raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        return array_values(array_filter($decoded, 'is_string'));
    }

    /** @param string[] $keys catalog keys (see layout/header.php's own $quickLinkCatalog), in the
     *   order the user wants them to appear -- validated against $validKeys (the CURRENTLY VISIBLE
     *   catalog, permission-filtered) so a stale/tampered key can never get persisted; duplicates
     *   are silently collapsed to their first occurrence rather than rejected outright, since a
     *   double-click on a checkbox is a plausible client-side accident, not a meaningful error. */
    public function saveQuickLinks(int $employeeId, int $compId, array $keys, array $validKeys): array {
        $validSet = array_flip($validKeys);
        $clean = [];
        foreach ($keys as $key) {
            if (is_string($key) && isset($validSet[$key]) && !in_array($key, $clean, true)) {
                $clean[] = $key;
            }
        }
        $stmtExists = $this->db->prepare("SELECT id FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtExists->execute([':id' => $employeeId, ':comp_id' => $compId]);
        if ($stmtExists->fetchColumn() === false) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $stmt = $this->db->prepare("UPDATE `employees` SET ui_quick_links = :links WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':links' => json_encode($clean, JSON_UNESCAPED_UNICODE), ':id' => $employeeId, ':comp_id' => $compId]);
        return ['status' => true, 'message' => 'Saved successfully.', 'data' => $clean];
    }

    /**
     * The ONE canonical list of every sidebar link (top-level AND submenu) eligible to be a Quick
     * Link -- called from BOTH layout/header.php (to render the bar + feed the Customize modal's
     * checkbox list) and UserPreferenceController::quickLinksSave() (to validate incoming keys)
     * so there is exactly one place this list is defined; header.php's sidebar markup itself is
     * NOT generated from this array (that would be a much larger, riskier refactor of a 700-line
     * file for no real benefit here) -- this is a deliberately hand-maintained MIRROR of it. If a
     * future sidebar item is added/removed/re-gated, mirror the same change here too.
     *
     * Each entry's `group` is the EXACT i18n key its own parent sidebar group already renders as
     * its label (`employees`/`payroll_menu`/`reports`/`payslip_menu`/`time_and_leave`/`settings`/
     * `help_menu`) -- reused as-is by the Customize modal's own group headers, `null` for a
     * top-level item with no submenu of its own (only Dashboard, as of the 2026-09-07 sidebar
     * consolidation -- Payroll Process/Approval, Audit Log, and Announcements all moved under a
     * parent group that same round, see header.php's own sidebar comments on each move).
     * Static (no DB access of its own) -- every permission check is delegated to PermissionModel,
     * the same single source of truth header.php's own sidebar `$canView*Menu` flags already use.
     *
     * @return array<int, array{key:string, url:string, icon:string, label:string, group:?string}>
     */
    public static function quickLinkCatalog(int $compId, int $employeeId, bool $isAdmin): array {
        $perm = new PermissionModel();
        $can = function (string $permissionKey) use ($perm, $employeeId, $isAdmin, $compId): bool {
            return $perm->checkPermission($employeeId, $permissionKey, $isAdmin, $compId)['allowed'];
        };
        $catalog = [
            ['key' => 'dashboard', 'url' => '/dashboard', 'icon' => 'DASHBOARD.SVG', 'label' => 'dashboard', 'group' => null],
            ['key' => 'employees.list', 'url' => '/employees', 'icon' => 'EMPLOYEE.SVG', 'label' => 'employee_list_menu', 'group' => 'employees'],
            ['key' => 'employees.login_history', 'url' => '/employees/login-history', 'icon' => 'EMPLOYEE.SVG', 'label' => 'login_history', 'group' => 'employees'],
            ['key' => 'employees.reports', 'url' => '/employees/reports', 'icon' => 'REPORT.SVG', 'label' => 'employee_reports', 'group' => 'employees'],
            ['key' => 'payroll_process', 'url' => '/payroll-process', 'icon' => 'PAYROLL.SVG', 'label' => 'payroll_process', 'group' => 'payroll_menu'],
            ['key' => 'payroll_approval', 'url' => '/payroll-approval', 'icon' => 'APPROVAL.SVG', 'label' => 'payroll_approval', 'group' => 'payroll_menu'],
            ['key' => 'payslip.requests', 'url' => '/payslip-documents/requests', 'icon' => 'APPROVAL.SVG', 'label' => 'requests', 'group' => 'payslip_menu'],
            ['key' => 'payslip.settings', 'url' => '/payslip-documents/settings', 'icon' => 'SETTINGS.SVG', 'label' => 'settings', 'group' => 'payslip_menu'],
            ['key' => 'time_leave.setup_rules', 'url' => '/setup-rules', 'icon' => 'Shift.SVG', 'label' => 'setup_and_rules', 'group' => 'time_and_leave'],
            ['key' => 'time_leave.manual_entry', 'url' => '/manual-entry', 'icon' => 'TIME.SVG', 'label' => 'manual_time_entry', 'group' => 'time_and_leave'],
            ['key' => 'settings.company_profile', 'url' => '/setup/company-profile', 'icon' => 'COMPANY.SVG', 'label' => 'company_profile', 'group' => 'settings'],
            ['key' => 'settings.data_sync', 'url' => '/setup/data-sync', 'icon' => 'ORIGAMI_APP.SVG', 'label' => 'data_sync_menu', 'group' => 'settings'],
            ['key' => 'settings.payroll_configuration', 'url' => '/setup/payroll-configuration', 'icon' => 'ORIGAMI_APP.SVG', 'label' => 'payroll_configuration', 'group' => 'settings'],
            ['key' => 'settings.tax_statutory', 'url' => '/setup/tax-statutory', 'icon' => 'TAX.SVG', 'label' => 'tax_and_statutory', 'group' => 'settings'],
            ['key' => 'help.setup_guide', 'url' => '/help/setup-guide', 'icon' => 'REPORT.SVG', 'label' => 'setup_guide_menu', 'group' => 'help_menu'],
            ['key' => 'help.version', 'url' => '/help/version', 'icon' => 'REPORT.SVG', 'label' => 'version_menu', 'group' => 'help_menu'],
        ];
        if ($can('payroll_run.view')) {
            $catalog[] = ['key' => 'reports.generate', 'url' => '/reports', 'icon' => 'REPORT.SVG', 'label' => 'generate_reports', 'group' => 'reports'];
        }
        if ($can('annual_income_summary.view')) {
            $catalog[] = ['key' => 'reports.annual_summary', 'url' => '/reports/annual-summary', 'icon' => 'REPORT.SVG', 'label' => 'annual_income_summary', 'group' => 'reports'];
        }
        if ($can('payroll_run.view')) {
            $catalog[] = ['key' => 'reports.run_audit', 'url' => '/reports/run-audit', 'icon' => 'REPORT.SVG', 'label' => 'payroll_run_audit_menu', 'group' => 'reports'];
        }
        if ($can('approval_workflow.view')) {
            $catalog[] = ['key' => 'settings.document_approval', 'url' => '/setup/document-approval', 'icon' => 'APPROVAL.SVG', 'label' => 'document_and_approval', 'group' => 'settings'];
        }
        if ($can('rbac.view')) {
            $catalog[] = ['key' => 'settings.permissions', 'url' => '/setup/permissions', 'icon' => 'APPROVAL.SVG', 'label' => 'permissions_menu', 'group' => 'settings'];
        }
        // 2026-09-07 -- both folded into an existing sidebar submenu (Reports / Settings
        // respectively) during that day's menu consolidation; `group` updated to match so the
        // Customize modal lists them under the correct heading (see header.php's own sidebar
        // comments on each move for the reasoning).
        if ($can('audit_log.view')) {
            $catalog[] = ['key' => 'audit_log', 'url' => '/audit-log', 'icon' => 'REPORT.SVG', 'label' => 'audit_log_menu', 'group' => 'reports'];
        }
        if ($can('announcement.manage')) {
            $catalog[] = ['key' => 'announcements', 'url' => '/setup/announcements', 'icon' => 'APPROVAL.SVG', 'label' => 'announcement_menu', 'group' => 'settings'];
        }
        return $catalog;
    }

    /** The built-in selection shown until an employee opens Customize Quick Links for the first
     *  time -- mirrors what the OLD Dashboard "Quick Links" card used to show (see dashboard.php's
     *  own git history), just relocated, so nobody's screen changes on deploy day. Filtered against
     *  the CURRENTLY VISIBLE catalog by the caller (header.php), same as any saved selection. */
    public static function defaultQuickLinkKeys(): array {
        return [
            'employees.list',
            'payroll_process',
            'payroll_approval',
            'reports.generate',
            'payslip.requests',
            'time_leave.setup_rules',
            'settings.company_profile',
        ];
    }
}
