-- 2026-08-29, explicit request: "ต้องการให้มีการตั้งค่าขนาด Font ของผู้ใช้แต่ละคนได้...จะต้องคงเดิมเมื่อ
-- เข้าใช้งานครั้งต่อไปไม่ว่าจะเครื่องไหน รวมถึงภาษาล่าสุดที่ใช้งานก็ต้องเก็บเหมือนกัน" -- per-user font-size
-- preference (S/M/L) that persists SERVER-SIDE across devices, not just localStorage (the existing
-- language preference had exactly this gap too -- public/js/app.js's applyLanguage() only ever
-- wrote to localStorage, so a user switching devices/browsers always fell back to English). Both
-- preferences now live on `employees` (the row session auth already resolves via
-- $_SESSION['user']['employee_id']) rather than a new join table, since a 1:1 per-employee
-- preference set doesn't need one.
--
-- ui_language is nullable (NULL = no preference saved yet, frontend falls back to its existing
-- browser-locale/localStorage-then-English default so this migration doesn't change behavior for
-- anyone who's never opened the new Settings modal). ui_font_size defaults to 'm' (matches every
-- page's existing unscaled look, so it's a true no-op default, not a silent visual change on
-- deploy).
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-08-29_add_employee_ui_preferences.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

ALTER TABLE `employees`
    ADD COLUMN `ui_language` ENUM('th','en') NULL DEFAULT NULL COMMENT 'Per-user UI language preference, persisted server-side so it survives switching devices/browsers' AFTER `profile_photo_path`,
    ADD COLUMN `ui_font_size` ENUM('s','m','l') NOT NULL DEFAULT 'm' COMMENT 'Per-user UI font-size preference (Small/Medium/Large)' AFTER `ui_language`;
