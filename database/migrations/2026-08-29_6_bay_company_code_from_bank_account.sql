-- 2026-08-29, explicit follow-up request: "ในแต่ละรอบการจ่ายอาจใช้เลขแยกกันครับ แยกบัญชีในการจ่าย" --
-- companion to migrations/2026-08-29_5_payroll_cycle_bank_account_and_company_code.sql. The BAY
-- seed's "รหัสบริษัท/รหัสบริการ" header field (sort_order=6) was a `constant` placeholder value
-- "XXX" the company had to manually keep in sync by hand -- now resolved automatically from
-- bank_accounts.company_code on whichever account this run's cycle actually settles from (see
-- BankTransferFileReport::resolveCompanyBankAccount()'s own docblock), same pattern
-- company_account_no already used since the previous round.
UPDATE `bank_file_format_fields`
SET `source_type` = 'employee_field', `source_field` = 'company_service_code', `constant_value` = NULL,
    `field_label_th` = 'รหัสบริษัท/รหัสบริการ (ตั้งค่าที่บัญชีธนาคาร)',
    `field_label_en` = 'Company/Service Code (set on the Bank Account)'
WHERE `bank_file_format_id` = 9 AND `comp_id` IS NULL AND `row_type` = 'header' AND `sort_order` = 6;
