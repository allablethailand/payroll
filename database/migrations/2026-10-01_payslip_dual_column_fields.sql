-- Payslip designer: tokens for the Dual-Column layout (header/bank, work summary, year-to-date) plus the
-- income_deduction_table block that renders earnings and deductions side by side with padded blank rows.
-- Adds 2 field groups (work_summary, ytd) to master_payslip_field_types.field_group, then 20 rows.
-- Rolling back deletes the 20 rows and narrows the enum; templates already holding one of these tokens
-- keep the literal {{token}} text because the renderer ignores unknown tokens.

-- UP
ALTER TABLE `master_payslip_field_types`
    MODIFY COLUMN `field_group` enum('employee_info','company_info','earning','deduction','statutory','summary','document','work_summary','ytd') COLLATE utf8mb4_unicode_ci NOT NULL;

INSERT INTO `master_payslip_field_types` (`code`, `name_th`, `name_en`, `field_group`, `element_type`, `is_active`, `sort_order`) VALUES
('status', 'สถานะพนักงาน', 'Employee Status', 'employee_info', 'text', 1, 75),
('paid_date', 'วันที่จ่ายเงิน', 'Paid Date', 'employee_info', 'text', 1, 76),
('bank_name', 'ชื่อธนาคาร', 'Bank Name', 'employee_info', 'text', 1, 77),
('bank_account_no', 'เลขบัญชีธนาคาร (เต็ม)', 'Bank Account No. (full)', 'employee_info', 'text', 1, 78),
('income_deduction_table', 'ตารางเงินได้ / เงินหัก 2 คอลัมน์', 'Income / Deduction Table (2 columns)', 'earning', 'text', 1, 165),
('base_salary_rate', 'ฐานเงินเดือน', 'Base Salary Rate', 'work_summary', 'text', 1, 210),
('daily_rate', 'ค่าแรงต่อวัน', 'Daily Rate', 'work_summary', 'text', 1, 211),
('hourly_rate', 'ค่าแรงต่อชั่วโมง', 'Hourly Rate', 'work_summary', 'text', 1, 212),
('working_days', 'วันทำงานที่ได้รับค่าแรง', 'Paid Working Days', 'work_summary', 'text', 1, 213),
('absent_days', 'วันขาดงาน', 'Absent Days', 'work_summary', 'text', 1, 214),
('total_ot_hours', 'ชั่วโมง OT รวม', 'Total OT Hours', 'work_summary', 'text', 1, 215),
('late_hours', 'ชั่วโมงมาสาย', 'Late Hours', 'work_summary', 'text', 1, 216),
('ytd_earnings', 'เงินเดือน/ค่าแรงสะสม', 'YTD Salary / Wages', 'ytd', 'text', 1, 220),
('ytd_gross_income', 'เงินได้รวมสะสม', 'YTD Gross Income', 'ytd', 'text', 1, 221),
('ytd_tax', 'ภาษีสะสม', 'YTD Tax', 'ytd', 'text', 1, 222),
('ytd_social_security', 'ประกันสังคมสะสม', 'YTD Social Security', 'ytd', 'text', 1, 223),
('ytd_provident_fund', 'กองทุนสำรองเลี้ยงชีพสะสม', 'YTD Provident Fund', 'ytd', 'text', 1, 224),
('ytd_guarantee_fund', 'เงินประกันทำงานสะสม', 'YTD Guarantee Fund', 'ytd', 'text', 1, 225),
('ytd_loan_repayment_1', 'คืนเงินกู้ยืม 1 สะสม', 'YTD Loan Repayment 1', 'ytd', 'text', 1, 226),
('ytd_loan_repayment_2', 'คืนเงินกู้ยืม 2 สะสม', 'YTD Loan Repayment 2', 'ytd', 'text', 1, 227)
ON DUPLICATE KEY UPDATE `name_th` = VALUES(`name_th`), `name_en` = VALUES(`name_en`);

-- DOWN
DELETE FROM `master_payslip_field_types` WHERE `code` IN (
    'status', 'paid_date', 'bank_name', 'bank_account_no', 'income_deduction_table',
    'base_salary_rate', 'daily_rate', 'hourly_rate', 'working_days', 'absent_days', 'total_ot_hours', 'late_hours',
    'ytd_earnings', 'ytd_gross_income', 'ytd_tax', 'ytd_social_security', 'ytd_provident_fund', 'ytd_guarantee_fund',
    'ytd_loan_repayment_1', 'ytd_loan_repayment_2'
);

ALTER TABLE `master_payslip_field_types`
    MODIFY COLUMN `field_group` enum('employee_info','company_info','earning','deduction','statutory','summary','document') COLLATE utf8mb4_unicode_ci NOT NULL;
