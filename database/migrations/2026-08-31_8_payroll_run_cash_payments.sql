-- Explicit request: "ถ้าพนักงานรับเงินสด ต้องไม่ดึงไปใน Report Payroll ขึ้นธนาคาร แต่แยก Report ตามแยก ว่า
-- จ่ายเงินสดเท่าไหร่ โอนผ่านธนาคารเท่าไหร่ และสามารถใส่ Status ว่าจ่ายแล้ว" -- the bank-transfer exclusion
-- half was ALREADY done (BankTransferFileReport::generate() already filters out
-- payment_type!='bank', confirmed via research before building this). This table is the genuinely
-- new half: per-employee, per-run "has this cash disbursement actually been handed over yet" status
-- -- `payroll_runs.markPaid()` only ever flips the WHOLE run to paid, it has no per-employee
-- granularity, so a real new table was needed rather than reusing that column.
--
-- One row per (run_id, employee_id) that was ever a cash-paying employee in that run -- rows are
-- lazily created (PayrollRunCashPaymentModel::ensureRowsForRun()) the first time this run's cash
-- payments are viewed, not eagerly at run-creation time, since payment_type can still change on the
-- employee record right up until the run reaches a state where cash tracking is even meaningful
-- (approved/paid/locked -- same ALLOWED_STATES gate every other payment-type report already uses,
-- see PaymentVoucherReport/BankTransferFileReport's own docblocks).
--
-- `amount` is a SNAPSHOT taken at ensure-time (this run's own payroll_run_details.net_amount for
-- that employee), not a live join -- once a run reaches approved+ its own numbers don't change
-- (PayrollRunModel's locked-employee/edit-after-approval rules already guarantee that), so a
-- snapshot is safe and means this table survives independently of payroll_run_details' own
-- lifecycle.
CREATE TABLE `payroll_run_cash_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `status` enum('unpaid','paid') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `paid_at` datetime DEFAULT NULL,
  `paid_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prcp_run_employee` (`run_id`,`employee_id`),
  KEY `idx_prcp_employee` (`employee_id`),
  CONSTRAINT `fk_prcp_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_prcp_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
  -- `paid_by` intentionally has NO FK -- matches this project's own established convention for
  -- every other actor/"who did this" column (payroll_runs.created_by/approved_by, etc., confirmed
  -- via `SHOW CREATE TABLE payroll_runs` -- none of them are FK'd), so a session's acting
  -- employee_id doesn't need to satisfy referential integrity against `employees` here either.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `permissions` (`module_code`, `action_code`, `permission_key`, `name_th`, `name_en`, `is_active`, `sort_order`)
VALUES ('payroll_run_cash_payment', 'manage', 'payroll_run_cash_payment.manage', 'จัดการสถานะการจ่ายเงินสด', 'Manage Cash Payment Status', 1, 201);
