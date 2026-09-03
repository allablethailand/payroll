-- 2026-09-02, explicit request: "ในการตั้งค่ารอบการจ่าย ปรับให้รองรับมากกว่า 1 บัญชี และในหน้า Detail ก็
-- สามารถเลือกได้ว่าใครจะโอนผ่านบัญชีไหนในกลุ่มที่รับเงินผ่านบัญชี และในหน้าพนักงาน ก็ต้องมี Tab setup ส่วนนี้
-- เพิ่มเติมว่ารับเงินผ่านบัญชีไหน...ในหน้า Detail ของ Process เพิ่ม Tab ให้จัดการข้อมูลส่วนนี้ได้ และมี Report
-- แยกตามบัญชีที่จ่าย และตอนออกรายงานเพื่อส่ง Cashlink ต้องถูกต้อง".
--
-- `bank_accounts` (company's own settlement accounts, multiple already supported) and
-- `payroll_cycles.bank_account_id` (a CYCLE can already pin one account) already existed before
-- this migration -- see BankAccountModel/PayrollCycleModel. What's genuinely new here is the
-- PER-EMPLOYEE layer: `employees.default_bank_account_id` is the employee's own TEMPLATE default
-- (which company account normally pays them), and `payroll_run_recurring_deduction_overrides`'s own
-- "template default + per-run override, template never touched" convention (see that table's own
-- migration/model) is mirrored here via `payroll_run_employee_bank_accounts` for a one-run-only
-- override. Resolution order (see PayrollRunEmployeeBankAccountModel::resolveForRun()):
-- per-run override > employee's own default_bank_account_id > cycle's pinned bank_account_id >
-- company's is_default=1 account.

START TRANSACTION;

ALTER TABLE `employees`
  ADD COLUMN `default_bank_account_id` int(11) DEFAULT NULL COMMENT 'FK bank_accounts -- which COMPANY settlement account normally pays this employee (distinct from bank_id/bank_account_no, which is the employee''s OWN receiving account)' AFTER `bank_branch`,
  ADD KEY `idx_employees_default_bank_account` (`default_bank_account_id`),
  ADD CONSTRAINT `fk_employees_default_bank_account` FOREIGN KEY (`default_bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE `payroll_run_employee_bank_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `bank_account_id` int(11) NOT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prweba_run_employee` (`run_id`,`employee_id`),
  KEY `idx_prweba_employee` (`employee_id`),
  KEY `idx_prweba_bank_account` (`bank_account_id`),
  CONSTRAINT `fk_prweba_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_prweba_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_prweba_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

COMMIT;

-- Rollback:
-- START TRANSACTION;
-- DROP TABLE IF EXISTS `payroll_run_employee_bank_accounts`;
-- ALTER TABLE `employees` DROP FOREIGN KEY `fk_employees_default_bank_account`, DROP COLUMN `default_bank_account_id`;
-- COMMIT;
