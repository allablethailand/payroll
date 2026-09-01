-- Explicit request: "ช่วยเขียนส่งสำหรับส่ง Status กลับไปที่ Origami ได้ไหมครับ เพื่อให้ฝั่งโน้นสามารถ Track ได้
-- และเพิ่มให้สามารถตีกลับเอกสารที่ยังไม่ดึงมาทำรอบได้ โดยที่ต้องใส่ Comment เข้าไปด้วยครับ...และเน้นย้ำต้องเก็บ Log
-- การดำเนินการ" -- confirmed via AskUserQuestion: push happens as a direct synchronous outbound call
-- (not a queue) on every payroll_runs state change (submit/approve/reject/markPaid), plus a new
-- reject-back action for a payroll_sync_processes row still in "Pending Pull" (never yet pulled
-- into a run). No formal spec document for Origami's side yet (no team ready to consume one) -- see
-- OrigamiPayrollStatusClient's own docblock for the documented-in-code contract instead.

-- Reject-back: status/reason/actor/timestamp directly on the row being rejected (mirrors
-- payroll_runs.rejected_at/rejected_by/reject_reason's own shape for the SAME kind of action on a
-- different table) -- `pendingList()` (the Pending Pull station's own query) is updated alongside
-- this migration to additionally exclude status='rejected' rows.
ALTER TABLE `payroll_sync_processes`
  ADD COLUMN `status` ENUM('pending','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' AFTER `unmapped_item_count`,
  ADD COLUMN `rejected_reason` TEXT COLLATE utf8mb4_unicode_ci NULL AFTER `status`,
  ADD COLUMN `rejected_by` INT(11) NULL AFTER `rejected_reason`,
  ADD COLUMN `rejected_at` DATETIME NULL AFTER `rejected_by`;

-- Outbound push audit log -- ONE shared table for both kinds of push (run state changes AND
-- sync-process reject-backs), since both are "we told Origami something happened" events with the
-- same shape (what happened, what we sent, whether it worked, what Origami said back). Deliberately
-- separate from payroll_sync_processes.rejected_* above -- those columns record the REJECT ACTION
-- ITSELF (who/when/why, true regardless of whether Origami could be reached), this table records
-- whether/how the NOTIFICATION about it succeeded -- same separation of concerns as this project's
-- own `sync_batches` (an operation's own audit trail) vs. the operation's real side effects
-- elsewhere. No FK on comp_id/run_id/sync_process_id (same nullable soft-reference convention
-- `report_export_logs.generated_by`/`email_queue.comp_id` already established) -- a log row must
-- never be blocked or cascaded away by the thing it's logging.
CREATE TABLE `origami_status_push_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) DEFAULT NULL,
  `event_type` ENUM('run_submitted','run_approved','run_rejected','run_paid','sync_process_rejected') COLLATE utf8mb4_unicode_ci NOT NULL,
  `run_id` int(11) DEFAULT NULL,
  `sync_process_id` int(11) DEFAULT NULL,
  `origami_process_id` bigint(20) DEFAULT NULL COMMENT 'denormalized copy of payroll_sync_processes.origami_process_id, for readability without a join',
  `request_payload` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `success` tinyint(1) NOT NULL DEFAULT 0,
  `http_status` int(11) DEFAULT NULL,
  `response_message` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ospl_comp` (`comp_id`),
  KEY `idx_ospl_run` (`run_id`),
  KEY `idx_ospl_sync_process` (`sync_process_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions` (`module_code`, `action_code`, `permission_key`, `name_th`, `name_en`, `is_active`, `sort_order`)
VALUES ('payroll_sync', 'reject', 'payroll_sync.reject', 'ตีกลับเอกสาร Sync ที่ยังไม่ดึงมาทำรอบ', 'Reject Pending Sync Documents', 1, 202);
