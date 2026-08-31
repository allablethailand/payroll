-- 2026-08-30, Phase 7 (T040): "ทุกครั้งที่ส่งเมล ให้บันทึกคิวไว้ใน Database ก่อน แล้วมี cronjob แยกมา run
-- ส่งจริง (ไม่ส่งแบบ sync ทันที)" -- EmailChannel::send() (app/services/notifications/EmailChannel.php,
-- the ONLY email-sending code path anywhere in this app -- confirmed via grep before writing this,
-- no other PHPMailer call site exists) used to call PHPMailer::send() synchronously, inline in the
-- HTTP request. `email_queue` is the new durable queue every email now goes through instead --
-- EmailChannel::send() inserts a 'pending' row and returns immediately; a separate CLI script
-- (cron/send_queued_emails.php, run on a schedule via the OS's own cron/Task Scheduler -- there was
-- NO scheduled-job mechanism anywhere in this project before this, confirmed via sync_batches'
-- own `trigger_type='auto'` column comment: "reserved for when a scheduled-job system exists -- not
-- built yet") is what actually calls PHPMailer against the real SMTP server, later, out of band.
--
-- No FK on comp_id -- deliberately nullable/soft-reference (matching report_export_logs.generated_by's
-- own established "no FK, this is an audit/queue row, not a relational entity" convention elsewhere
-- in this project) since a queued email may legitimately outlive the triggering context (e.g. sent
-- right as some other row gets deleted) and must never be blocked/cascaded by that.
CREATE TABLE IF NOT EXISTS `email_queue` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `comp_id` INT NULL,
  `to_address` VARCHAR(255) NOT NULL,
  `subject` VARCHAR(500) NOT NULL,
  `body` LONGTEXT NOT NULL,
  `attachment_path` VARCHAR(500) NULL,
  `attachment_name` VARCHAR(255) NULL,
  `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `attempts` INT NOT NULL DEFAULT 0,
  `max_attempts` INT NOT NULL DEFAULT 3,
  `error_message` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_email_queue_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
