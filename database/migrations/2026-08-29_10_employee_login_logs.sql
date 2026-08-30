-- 2026-08-29, explicit request: "ต้องการอีก Tab ใน Employee เพื่อดูประวัติการเข้าใช้งานระบบโดยแสดงข้อมูล
-- แบบละเอียดตามที่เก็บ ตอนนี้เก็บ ip location timezone อุปกรณ์ version อุปกรณ์ เบราเซอร์ ครบไหม ถ้ายังไม่ครบ
-- ให้เก็บเพิ่มครับ และสามารถ Filter ได้"
--
-- No login/session audit table existed anywhere in the schema before this (confirmed via
-- SHOW TABLES) -- this is a genuinely new capture, not widening an existing one. One row per
-- successful login through auth/index.php's SSO handshake (see that file's own new comment at the
-- capture point). `timezone` is filled in a SECOND step, not at insert time -- the server has no
-- way to know the browser's IANA timezone from the initial HTTP request alone (no standard header
-- carries it); a small JS snippet fires once after the post-login page loads
-- (`recordLoginTimezone()` in app.js) and PATCHes it in via `api/employee-login-log.record-timezone`.
-- `location_city`/`location_country` resolved via a best-effort IP-geolocation HTTP call made from
-- auth/index.php itself (see EmployeeLoginLogModel::create()'s own docblock for why best-effort,
-- never blocking login) -- confirmed via AskUserQuestion that automatic city/country resolution
-- (not just storing the raw IP) is what's wanted here.
-- comp_id/employee_id are plain INT (not UNSIGNED) to match companies.id/employees.id, both
-- `int(11)` (signed) in this schema -- UNSIGNED here would mismatch and fail the FK constraint
-- (errno 150), confirmed by checking both referenced columns' real types before writing this.
CREATE TABLE `employee_login_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `comp_id` INT NOT NULL,
    `employee_id` INT NOT NULL,
    `ip_address` VARCHAR(45) NULL,
    `location_city` VARCHAR(100) NULL,
    `location_country` VARCHAR(100) NULL,
    `timezone` VARCHAR(64) NULL,
    `device_type` VARCHAR(20) NULL COMMENT 'desktop / mobile / tablet / bot / unknown, parsed from User-Agent',
    `os_name` VARCHAR(50) NULL,
    `os_version` VARCHAR(30) NULL,
    `browser_name` VARCHAR(50) NULL,
    `browser_version` VARCHAR(30) NULL,
    `user_agent` VARCHAR(500) NULL COMMENT 'raw User-Agent string, kept alongside the parsed fields so a parsing miss can be re-diagnosed later without having lost the source data',
    `login_at` DATETIME NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ell_comp_employee_login` (`comp_id`, `employee_id`, `login_at`),
    CONSTRAINT `fk_ell_comp` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_ell_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions` (`module_code`, `action_code`, `permission_key`, `name_th`, `name_en`, `is_active`, `sort_order`)
VALUES ('employee_login_log', 'view', 'employee_login_log.view', 'ดูประวัติการเข้าใช้งานพนักงาน', 'View Employee Login History', 1, 190);
