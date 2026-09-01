-- 2026-08-31, explicit request: real double-payment risk found and confirmed by the user --
-- PayrollRunModel::reopen() has always allowed re-opening an already-PAID/LOCKED run (e.g. to merge
-- a supplemental process into it via mergeSupplementalIntoRun()'s own allowReopenPaidTarget), but
-- markPaid() and the payment reports (BankTransferFileReport/PaymentVoucherReport) never tracked
-- "how much was already actually disbursed for this run before" -- a second markPaid() cycle after
-- reopening would compute the transfer/voucher amount from the run's CURRENT (now-larger) total,
-- which risks re-transferring the SAME base salary a second time on top of the genuinely-new amount.
-- Confirmed via AskUserQuestion: fix markPaid()/reports to record what was actually paid each time
-- and automatically compute only the delta on any later payment cycle, rather than requiring a
-- human to manually recognize and subtract the overlap before actually transferring money.
--
-- One row per (run_id, employee_id) per markPaid() call -- an immutable, append-only ledger (never
-- updated/deleted) so a run that goes through paid -> reopen -> merge -> paid -> reopen -> merge ->
-- paid (etc, no cap on how many cycles) has a full audit trail of exactly what was disbursed on each
-- cycle, not just a single "total paid so far" counter that would lose that granularity. Per-employee
-- (not per-run) since the Bank Transfer File needs a per-employee delta amount -- an employee
-- untouched by a given merge must show delta=0 for that cycle, not the whole run's own new total.
CREATE TABLE `payroll_run_payment_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `run_id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  -- The DELTA actually disbursed THIS payment cycle (current payroll_run_details amount MINUS the
  -- sum of every prior event row for this same run_id+employee_id) -- NOT a copy of the run's own
  -- current total. Gross/deduction included alongside net for report completeness (Payment Voucher
  -- itemizes all three), computed the same delta way.
  `gross_amount_paid` decimal(15,2) NOT NULL DEFAULT 0.00,
  `deduction_amount_paid` decimal(15,2) NOT NULL DEFAULT 0.00,
  `net_amount_paid` decimal(15,2) NOT NULL DEFAULT 0.00,
  `payment_method` enum('bank_transfer','cash','cheque') COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paid_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- No FK on paid_by, matching this project's own established convention for every other "who did
  -- this" actor column (payroll_runs.created_by/submitted_by/approved_by/paid_by, etc.) -- none of
  -- them are FK-enforced against employees, same pattern kept here for consistency.
  `paid_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_prpe_run_employee` (`run_id`, `employee_id`),
  CONSTRAINT `fk_prpe_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_prpe_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
