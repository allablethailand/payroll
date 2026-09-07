-- 2026-09-04, Backlog Phase 11, T069 (dark mode), Step 1 of 3 -- per-user theme preference,
-- persisted SERVER-SIDE (`employees` -- the SAME table `ui_language`/`ui_font_size` already use,
-- see 2026-08-29_add_employee_ui_preferences.sql's own docblock for why a join table isn't needed
-- for a 1:1 per-employee preference set) so it survives switching devices/browsers/logout, per the
-- task's own explicit "not just localStorage" requirement.
--
-- 3 real states, not 2: 'light' / 'dark' (explicit user choice, always wins over the browser's own
-- OS-level setting) and NULL (the true default -- never touched, OR explicitly reset to "System" --
-- deliberately NOT modeled as a 3rd enum value; NULL and "follow the OS" are the exact same
-- behavior with no meaningful difference to represent separately, so collapsing them keeps
-- header.php's own stamping logic a plain 2-way branch instead of a 3-way one for no real benefit).
-- NULL means: stamp NOTHING server-side, let the browser's own `prefers-color-scheme` media query
-- decide (see style.css's own new dark-mode token block) -- this is what makes the migration a
-- true no-op for every existing user on deploy (nobody's screen changes color until they explicitly
-- open Settings and choose Dark, UNLESS their OS is already set to dark, which is new-but-correct
-- behavuor per the task's own "respect system" requirement, not a regression).
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-04_11_employee_ui_theme.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

ALTER TABLE `employees`
    ADD COLUMN `ui_theme` ENUM('light','dark') NULL DEFAULT NULL COMMENT 'Per-user UI theme preference -- NULL = follow the browser/OS prefers-color-scheme (the "System" option in the Settings modal is just this NULL state, not its own enum value)' AFTER `ui_font_size`;
