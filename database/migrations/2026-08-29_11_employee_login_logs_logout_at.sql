-- 2026-08-29, explicit follow-up request: "และการเก็บประวัติเก็บตอน Switch App มาที่ Payroll และ Logout
-- คือตอน Switch ออกจาก Payroll ไปที่อื่น แต่ถ้าไม่มีก็แสดงว่าไม่ Logout"
--
-- The "entered Payroll" half of this is already correctly covered by employee_login_logs' own
-- capture point (auth/index.php's SSO handshake) -- EVERY way of arriving at Payroll, including
-- Origami's own "Switch App" mechanism, goes through that exact same one-way handshake endpoint
-- (see this app's Authentication docs: there is no separate code path for "switched in" vs "fresh
-- login", they are the same request). Nothing new needed for that half.
--
-- The "left Payroll" half is genuinely new: auth/switch.php (added 2026-08-26 to fix a real
-- session-not-destroyed bug when switching away to another Origami app) destroys the Payroll
-- session but never recorded WHEN that happened. `logout_at` is set there, on the SAME row
-- $_SESSION['login_log_id'] already points at (stashed at login time) -- NULL is the normal,
-- expected state for a row whose session ended some other way (browser closed, tab closed, session
-- simply expired) -- there is no way to detect "the user just closed the tab" server-side, so a
-- NULL logout_at is NOT an error condition, it correctly means "no explicit Switch-App-away logout
-- was ever recorded for this login."
ALTER TABLE `employee_login_logs`
    ADD COLUMN `logout_at` DATETIME NULL AFTER `login_at`;
