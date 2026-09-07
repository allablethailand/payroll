-- 2026-09-07, explicit request: "ทางลัด วางอยู่ล่างเกินไป ใช้งานไม่สะดวกครับ...ปรับเป็นให้อยู่บน header
-- ไปเลย...โดยให้ผู้ใช้เลือกได้ว่าจะโชว์ หรือไม่โชว์เมนูไหน เลือกได้ทั้งเมนู และ sub menu" -- Quick Links moves
-- from the Dashboard's own bottom-of-sidebar card into the top navbar (see layout/header.php's new
-- .nav-quicklinks), and becomes per-user configurable (any top-level OR submenu item in the sidebar).
--
-- Same established pattern as `ui_language`/`ui_font_size`/`ui_theme` on this SAME table (see
-- 2026-08-29_add_employee_ui_preferences.sql / 2026-09-04_11_employee_ui_theme.sql's own docblocks)
-- rather than a new join table -- this is still a single, small, 1:1-per-employee preference value.
--
-- NULL = never customized -- header.php falls back to a sensible built-in default selection (see
-- that file's own $defaultQuickLinkKeys). An explicit '[]' (user removed every quick link on
-- purpose) is a real, different state from NULL and must be respected as "show none" -- see
-- UserPreferenceModel::getQuickLinks()'s own docblock for how the two are told apart.
--
-- Stores a JSON array of catalog KEYS (e.g. ["employees.list","payroll_process",...], see
-- $quickLinkCatalog in layout/header.php for the full key list), in the user's own chosen display
-- order -- not URLs/labels, so a later rename of a route/label never orphans a saved selection.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-07_1_employee_ui_quick_links.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

ALTER TABLE `employees`
    ADD COLUMN `ui_quick_links` TEXT NULL DEFAULT NULL COMMENT 'Per-user header Quick Links selection -- JSON array of catalog keys from layout/header.php, in display order. NULL = never customized (falls back to the built-in default set); "[]" = user intentionally selected none.' AFTER `ui_theme`;
