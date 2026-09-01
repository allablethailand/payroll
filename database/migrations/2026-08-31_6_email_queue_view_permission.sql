-- 2026-08-31, explicit request: "สร้าง Cronjob สำหรับการส่งอีเมล และเพิ่มหน้าให้ดู Log การส่งได้ มี Filter
-- และตาราง รวมถึง Summary" -- the cron script itself (cron/send_queued_emails.php) already existed
-- from Phase 7 (T040), so this batch is just the new admin log/summary page. Gated behind its own
-- permission (same convention as every other new admin page's own `<module>.view`/`.manage` key,
-- e.g. `employee_login_log.view` from 2026-08-29) rather than reusing an existing one, since
-- email_queue isn't owned by any single existing module.
INSERT INTO `permissions` (`module_code`, `action_code`, `permission_key`, `name_th`, `name_en`, `is_active`, `sort_order`)
VALUES ('email_queue', 'view', 'email_queue.view', 'ดูประวัติการส่งอีเมล', 'View Email Queue Log', 1, 200);
