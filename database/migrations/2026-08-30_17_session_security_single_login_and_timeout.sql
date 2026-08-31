-- 2026-08-30, Phase 7 (T037/T038): "1 User 1 Login" + 30-minute inactivity timeout.
--
-- `is_active` marks whether a login row still represents a currently-valid session -- a NEW login
-- for the SAME employee deactivates every OTHER still-active row for that employee
-- (EmployeeLoginLogModel::create()), giving every OTHER open session (elsewhere) something to
-- discover on its own next request (ensure_login() checks this every request -- see that
-- function's own updated docblock in app/helpers/helpers.php). `ended_reason` records WHY a
-- session ended (switch_app already existed as an implicit concept via `logout_at`; new_login and
-- timeout are new) so the existing Login History tab can show a real reason instead of just a
-- blank/present logout_at.
ALTER TABLE `employee_login_logs`
    ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `login_at`,
    ADD COLUMN `ended_reason` ENUM('switch_app','new_login','timeout') NULL AFTER `logout_at`,
    ADD KEY `idx_ell_active` (`employee_id`, `is_active`);
