-- 2026-08-29, explicit request: "ช่วยสร้างระบบแจ้งเตือนและวิเคราะห์ว่าควรมีการแจ้งเตือนอะไรบ้าง...บน header
-- มี icon noti อยู่...โดยเป็นของใครของมัน คนนึงเห็นแล้วอีกคนไม่เห็นตัวเลขก็จะไม่หาย ต้องไปกดเปิดดูก่อนถึงหาย
-- และสามารถคลิกจาก item นั้นแล้วไปหน้านั้นได้เลย"
--
-- One row PER RECIPIENT (not a shared notification + a separate read-state pivot table) -- this is
-- the simplest design that already satisfies "ของใครของมัน" (each person's own copy) for free: if
-- 3 employees can act on the same event, 3 separate rows get inserted, one per employee_id, each
-- with its own independent is_read/read_at. Person A opening/reading their own row can never affect
-- person B's row for the "same" event.
--
-- `dedup_key` is how repeat-check-generated notifications (the aging-draft reminder specifically --
-- see NotificationModel::checkStaleDrafts()'s own docblock, this project has no cron/scheduled-job
-- infrastructure, already documented elsewhere, e.g. PayslipDeliveryService's own docblock -- so
-- "1/2/3 days stale" is computed lazily whenever notifications are actually fetched, not on a
-- timer) avoid spamming the same milestone twice: e.g. 'stale_draft:{run_id}:1d' for employee X is
-- unique, so a second lazy-check run within the same day-bucket is a silent no-op, not a duplicate
-- row. NULL is exempt from the unique constraint (MySQL treats every NULL as distinct), which is
-- what event-triggered notifications that never need de-duplication (approved/locked/document
-- request) leave it as.
CREATE TABLE `notifications` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `comp_id` INT NOT NULL,
    `employee_id` INT NOT NULL COMMENT 'recipient',
    `type` VARCHAR(50) NOT NULL COMMENT 'sync_new_data / stale_draft / approved_continue / lock_reminder_print / document_request_pending / ...',
    `icon` VARCHAR(50) NULL COMMENT 'Font Awesome icon suffix, e.g. fa-arrows-rotate',
    `title_th` VARCHAR(255) NOT NULL,
    `title_en` VARCHAR(255) NOT NULL,
    `message_th` VARCHAR(500) NULL,
    `message_en` VARCHAR(500) NULL,
    `link_url` VARCHAR(255) NULL COMMENT 'relative URL (no BASE_URL prefix) the notification navigates to when clicked',
    `related_type` VARCHAR(50) NULL COMMENT 'e.g. payroll_run, payslip_request -- for future reference, not required for display',
    `related_id` INT NULL,
    `dedup_key` VARCHAR(150) NULL COMMENT 'unique per (employee, event instance) for lazily re-checked notification types -- see this table''s own migration header comment',
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `read_at` DATETIME NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_notifications_dedup_key` (`dedup_key`),
    KEY `idx_notifications_employee_unread` (`comp_id`, `employee_id`, `is_read`, `created_at`),
    CONSTRAINT `fk_notifications_comp` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_notifications_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
