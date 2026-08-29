-- 2026-08-29, explicit request referencing C:\xampp\htdocs\origami\payroll\docs\PAYROLL_SYNC_API.md
-- (2026-08-28/29 revisions): "ที่มีการ Update การส่งข้อมูลมาในกรณีที่ ทำจ่าย OT หรือทำจ่ายค่าเที่ยวหรือทำจ่าย
-- ค่าอื่นๆ ช่วยปรับการรับให้ตรง" -- the sending side (Origami HR) now includes this cycle's own
-- identity (`process_subject`/`process_description`/`process_start`/`process_end`/`process_paid`,
-- added 2026-08-28) and a `run_kind` flag (`"regular"`/`"supplemental"`, added 2026-08-28 as a
-- DRAFT/not-yet-locked field per that doc's own caveat -- kept anyway since the receiving side is
-- being built now on explicit instruction, defensively defaulted to 'regular' if ever missing or
-- an unrecognized value) distinguishing a normal period-matched run from a standalone/ad-hoc one
-- (e.g. OT-only or Trip-only, hand-built roster, not tied to a period auto-match).
--
-- None of these 6 fields existed as real columns before this -- previously only sitting inside
-- the opaque raw_payload JSON blob, unqueryable and unused anywhere in the Pending Pull UI.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-08-29_payroll_sync_process_cycle_fields.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

ALTER TABLE `payroll_sync_processes`
    ADD COLUMN `process_subject` VARCHAR(255) NULL COMMENT 'This cycle''s own label from Origami (process_subject), distinct from period_name (the recurring schedule)' AFTER `process_no`,
    ADD COLUMN `process_description` TEXT NULL AFTER `process_subject`,
    ADD COLUMN `process_start` DATE NULL COMMENT 'This cycle''s actual date range start, as sent by Origami' AFTER `process_description`,
    ADD COLUMN `process_end` DATE NULL COMMENT 'This cycle''s actual date range end, as sent by Origami' AFTER `process_start`,
    ADD COLUMN `process_paid` DATE NULL COMMENT 'This cycle''s pay date, as sent by Origami' AFTER `process_end`,
    ADD COLUMN `run_kind` ENUM('regular','supplemental') NOT NULL DEFAULT 'regular' COMMENT 'regular = normal period-matched run; supplemental = standalone/ad-hoc (e.g. OT-only, Trip-only)' AFTER `process_paid`;
