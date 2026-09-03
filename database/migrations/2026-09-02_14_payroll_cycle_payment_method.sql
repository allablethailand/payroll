-- 2026-09-02, explicit request: "เพิ่ม field ประเภทการจ่ายของรอบนี้ (payment type per cycle) --
-- เลือกได้ว่ารอบนี้ default จ่ายแบบไหน...โดยพนักงานรายบุคคลสามารถ override ได้". Cycle-level default only
-- -- employees.payment_method_id (see 2026-09-02_12) is what actually governs a given employee's
-- own pay, this is just what a NEW employee assigned to this cycle would default to, applied at
-- the UI layer (not enforced by this column itself), same "reference default, not a hard gate"
-- precedent this project already uses for company_payroll_policies.probation_period_days.

START TRANSACTION;

ALTER TABLE `payroll_cycles`
  ADD COLUMN `default_payment_method_id` int(11) DEFAULT NULL AFTER `bank_account_id`,
  ADD CONSTRAINT `fk_payroll_cycles_payment_method` FOREIGN KEY (`default_payment_method_id`) REFERENCES `master_payment_methods` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

COMMIT;

-- Rollback:
-- ALTER TABLE `payroll_cycles` DROP FOREIGN KEY `fk_payroll_cycles_payment_method`, DROP COLUMN `default_payment_method_id`;
