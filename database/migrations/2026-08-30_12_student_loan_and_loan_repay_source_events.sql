-- 2026-08-30_12_student_loan_and_loan_repay_source_events.sql
-- T013 (Phase 2, explicit decision confirmed with user): กยศ. (Student Loan) and general employee
-- loan repayment become OPT-IN Linked Attendance Events (deduction), same pattern as T011's
-- Diligence Allowance -- an admin who confirms Origami's own sync payload actually sends per-cycle
-- deduction amounts for these can select the event from the dropdown; a company that doesn't (or
-- isn't sure) keeps the EXISTING manual installment mechanism (employee_earning_deductions)
-- completely unchanged, since this app has no confirmed evidence yet of Origami's real item_code
-- for either of these (unlike DILIGENCE/ASSISTANCE, verified in this session's own item_master
-- payload testing) -- see SyncPayResolver.php's own EVENT_ALIASES comment on this same point.
-- Deliberately does NOT touch PayrollEarningDeductionTypeModel::seedDefaults()'s existing
-- STUDENT_LOAN/LOAN_REPAY rows or their source_event_code -- opt-in only, no existing company's
-- configuration changes as a side effect of this migration.
INSERT INTO `master_payroll_source_events` (`code`, `name_th`, `name_en`, `applies_to`, `is_active`, `sort_order`)
VALUES
    ('student_loan', 'หักเงินกู้ยืม กยศ.', 'Student Loan Deduction (SLF)', 'deduction', 1, 90),
    ('loan_repay', 'หักเงินกู้ยืมพนักงาน', 'Employee Loan Repayment', 'deduction', 1, 100);
