-- 2026-09-04, Backlog Phase 10, T058: "Dashboard shows currently-online users."
--
-- `employee_login_logs.is_active` (Phase 7, T037/T038) already tracks whether a session has been
-- superseded by a newer login or explicitly ended (timeout/switch-app) -- but it is a LAZY signal,
-- not a real-time one: a session idle past SESSION_IDLE_TIMEOUT_SECONDS still reads is_active=1
-- until that specific browser tab makes its own next request (ensure_login()'s T038 check only
-- fires then). That is the right behavior for a SECURITY idle-timeout, but a poor proxy for "is
-- this person genuinely online right now" on a Dashboard widget.
--
-- `last_seen_at` is a SEPARATE, parallel presence signal, deliberately not reusing/conflating with
-- `$_SESSION['last_activity']` (T038's own idle-timeout clock, which intentionally excludes
-- api/session.heartbeat pings so a forgotten-but-open tab can't defeat the idle timeout). Unlike
-- last_activity, last_seen_at DOES advance on a heartbeat ping -- a user with the tab genuinely open
-- and polling is meaningfully "online" for presence purposes, even between real clicks. See
-- EmployeeLoginLogModel::touchLastSeen()/listOnlineForCompany() and helpers.php's ensure_login()
-- for where this is written/read.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-04_6_employee_login_log_last_seen.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

ALTER TABLE `employee_login_logs`
    ADD COLUMN `last_seen_at` TIMESTAMP NULL DEFAULT NULL AFTER `login_at`;

ALTER TABLE `employee_login_logs`
    ADD KEY `idx_ell_online_lookup` (`comp_id`, `is_active`, `last_seen_at`);
