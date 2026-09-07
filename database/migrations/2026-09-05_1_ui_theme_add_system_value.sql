-- 2026-09-05, real bug found and fixed: `employees.ui_theme` NULL was overloaded to mean BOTH
-- "never touched Settings" AND "explicitly chose System" (see 2026-09-04_11_employee_ui_theme.sql's
-- own docblock) -- both resolved to "follow the browser/OS prefers-color-scheme". That meant any
-- employee whose OS happened to already be set to dark saw the app switch to dark mode by itself
-- the very first time they opened it, before ever touching Settings -- reported live as "Origami
-- switch มาที่ payroll ไม่ได้" turning out to be an unrelated MySQL privilege-table crash, but while
-- investigating, explicit follow-up request: "อยากให้ Default เป็น Mode ปกติก่อน แล้วผู้ใช้เปลี่ยนเองทีหลัง"
-- (default should be Light for everyone; only an employee's OWN explicit choice should change it).
--
-- Fix: give "explicitly follow the OS" its own real value ('system') instead of overloading NULL.
-- NULL now means ONLY "never configured" and defaults to Light (see header.php's own updated
-- stamping logic) -- 'system' is what the Settings modal's System button now actually persists.
-- Existing NULL rows (every employee who has never opened Settings, or who WOULD have picked
-- System before this fix existed) correctly become "Light by default" going forward, which is
-- exactly the requested behavior -- there is no way to retroactively tell those two prior cases
-- apart, and the request is for both of them to land on Light now anyway.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-05_1_ui_theme_add_system_value.sql

ALTER TABLE `employees`
    MODIFY COLUMN `ui_theme` ENUM('light','dark','system') NULL DEFAULT NULL COMMENT 'Per-user UI theme preference -- NULL = never configured (defaults to Light); ''system'' = explicitly chosen to follow the browser/OS prefers-color-scheme; ''light''/''dark'' = explicit override, always wins' AFTER `ui_font_size`;
