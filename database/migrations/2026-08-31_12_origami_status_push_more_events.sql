-- 2026-08-31, same-day follow-up: "มีส่วนไหนที่ทำได้เลยไหมครับ ที่ไม่กระทบอะไรส่วนฝั่ง Origami" -- widening
-- the outbound push to cover EVERY payroll_runs state change, not just the original 4
-- (submit/approve/reject/markPaid), per the batch's own explicit request "ทุกครั้งที่ Payroll Run
-- เปลี่ยนสถานะ" (every time the payroll run changes state) -- cancel/requestInfo/revert/
-- reviseAfterReject/reviseAfterNeedInfo were the remaining state-changing actions left out of the
-- first pass. Pure additive enum widening, no other schema change.
ALTER TABLE `origami_status_push_logs`
  MODIFY COLUMN `event_type` ENUM('run_submitted','run_approved','run_rejected','run_paid','run_cancelled','run_need_info','run_reverted','run_revised','sync_process_rejected') COLLATE utf8mb4_unicode_ci NOT NULL;
