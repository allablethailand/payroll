-- 2026-09-01, explicit request: "ตอนดึงมาทำรอบหรือเพิ่มรอบใหม่ ให้มี radio เลือกว่า เปิดรอบใหม่ หรืออ้างอิงถึง
-- รอบ" -- confirmed via AskUserQuestion: reachable ONLY from the plain "Add" flow (the Pending-Pull/
-- Origami flow stays exactly as-is, still driven solely by Origami's own attribution -- see
-- PayrollRunModel::mergeSupplementalIntoRun(), untouched). merge_target_run_id is set at CREATE
-- time when the admin picks "อ้างอิงถึงรอบ" (Reference an existing round) -- the run is created and
-- built up normally afterward (Join Employees/Manage Items, same as any off-cycle run), and this
-- column is what makes the Detail page show a "Merge into Target" action once ready, reusing the
-- exact same mechanics PayrollRunModel::mergeIntoExistingRun()/performRunMerge() (shared with the
-- Origami-driven mergeSupplementalIntoRun()) already implement.
--
-- Self-referencing FK (a genuine structural reference, not a "who did this" actor column -- see
-- payroll_run_payment_events' own migration for why THOSE stay unconstrained) -- ON DELETE SET NULL
-- since a target run being hard-removed should never cascade-delete the run still waiting to merge
-- into it, just fall back to "no target chosen" and let the admin re-pick or create standalone.
ALTER TABLE `payroll_runs`
  ADD COLUMN `merge_target_run_id` int(11) DEFAULT NULL AFTER `sync_process_id`,
  ADD CONSTRAINT `fk_payroll_runs_merge_target` FOREIGN KEY (`merge_target_run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
