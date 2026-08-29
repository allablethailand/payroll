-- 2026-08-29, explicit request: user supplied the REAL Krungsri (Bank of Ayudhya) fixed-width
-- payroll transfer .txt layout directly (Header Record + Detail Record, with a worked example of
-- each), replacing the earlier migration's (2026-08-29_bank_file_format_config.sql) honestly-
-- guessed 4-field DETAIL-ONLY placeholder -- that earlier seed had NO header row at all, so a
-- company could never even produce the header line (payment date/company account/company code/
-- totals) through it.
--
-- Every field width below was derived by counting exact character positions in the user's own
-- sample rows (byte-verified via iconv('UTF-8','TIS-620') for the Thai name field), not guessed --
-- see BankTransferFileReport::applyDataType()'s own docblock for the companion fix this required
-- (fixed-width numeric fields need an implied-decimal, no-literal-point convention -- e.g.
-- 00000008873025 = 88,730.25 -- which the engine didn't support before this).
--
-- Two fields are seeded as PLACEHOLDER constants the company MUST edit via "Manage Format" before
-- relying on this for a real bank upload (matches this framework's own existing
-- constant_value-is-company-editable mechanism, see BankFileFormatModel::saveField()):
--   - "รหัสบริษัท/รหัสบริการ" (Company/Service Code, e.g. the sample's "712") -- registered by
--     Krungsri per company, genuinely has no other source anywhere in this app.
--   - "รหัสอ้างอิง (ส่วนบริษัท)" (Reference Code company-prefix, e.g. the sample's "001") -- kept as
--     its own editable constant rather than assumed identical to the Company/Service Code above,
--     since the sample shows two DIFFERENT values ("712" vs "001") and nothing confirms they're
--     always the same thing.
-- Company's own settlement account (เลขที่บัญชีตัดเงินของบริษัท) is NOT a placeholder -- it's
-- auto-resolved from bank_accounts (is_default=1 for the company) via a new company_account_no
-- source_field, decrypted the same way BankAccountModel itself does. Employee name
-- (source_field=employee_name) now follows the export-time language choice
-- (BankTransferFileReport::generate()'s new context.language) instead of being hardcoded Thai.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-08-29_4_krungsri_bank_transfer_real_layout.sql

DELETE FROM `bank_file_format_fields` WHERE `bank_file_format_id` = 9 AND `comp_id` IS NULL;

INSERT INTO `bank_file_format_fields`
    (`bank_file_format_id`, `comp_id`, `row_type`, `sort_order`, `field_label_th`, `field_label_en`, `source_type`, `source_field`, `constant_value`, `data_type`, `width`, `pad_char`, `pad_direction`, `date_format`, `decimal_places`)
VALUES
    -- Header Record (102 bytes total)
    (9, NULL, 'header', 1, 'ประเภทรายการ (Record Type)', 'Record Type', 'constant', NULL, '0000', 'text', 4, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 2, 'เว้นว่าง', 'Reserved / Blank', 'blank', NULL, NULL, 'text', 2, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 3, 'วันที่ทำรายการจ่าย (DDMMYY)', 'Payment Date (DDMMYY)', 'employee_field', 'payment_date', NULL, 'date', 6, ' ', 'right', 'dmy', NULL),
    (9, NULL, 'header', 4, 'เลขที่บัญชีตัดเงินของบริษัท', "Company's Debit Account No.", 'employee_field', 'company_account_no', NULL, 'text', 10, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 5, 'เว้นว่าง', 'Reserved / Blank', 'blank', NULL, NULL, 'text', 20, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 6, 'รหัสบริษัท/รหัสบริการ (ต้องแก้ไขเป็นรหัสจริงของบริษัท)', 'Company/Service Code (EDIT to your real bank-assigned code)', 'constant', NULL, 'XXX', 'text', 3, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 7, 'เว้นว่าง', 'Reserved / Blank', 'blank', NULL, NULL, 'text', 27, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 8, 'ประเภทการจ่าย (A = จ่ายเงินเดือนปกติ)', 'Payment Type (A = Normal Payroll)', 'constant', NULL, 'A', 'text', 1, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 9, 'รหัสอ้างอิงงวดจ่าย - ส่วนบริษัท (ต้องแก้ไขเป็นรหัสจริง)', 'Pay Period Reference - Company Prefix (EDIT to your real code)', 'constant', NULL, 'XXX', 'text', 3, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 10, 'รหัสอ้างอิงงวดจ่าย - เดือน/ปี (MMYY)', 'Pay Period Reference - Month/Year (MMYY)', 'employee_field', 'pay_period', NULL, 'date', 4, ' ', 'right', 'my', NULL),
    (9, NULL, 'header', 11, 'จำนวนรายการทั้งหมด', 'Total Record Count', 'employee_field', 'total_count', NULL, 'number', 7, '0', 'left', NULL, 0),
    (9, NULL, 'header', 12, 'เว้นว่าง', 'Reserved / Blank', 'blank', NULL, NULL, 'text', 1, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 13, 'ยอดรวมเงินทั้งหมด (2 หลักท้ายคือทศนิยม)', 'Total Amount (last 2 digits = decimal)', 'employee_field', 'total_amount', NULL, 'number', 14, '0', 'left', NULL, 2),
    -- Detail Record, one per employee (80 bytes total)
    (9, NULL, 'detail', 1, 'ประเภทรายการ (Record Type)', 'Record Type', 'constant', NULL, '0000', 'text', 4, ' ', 'right', NULL, NULL),
    (9, NULL, 'detail', 2, 'เว้นว่าง', 'Reserved / Blank', 'blank', NULL, NULL, 'text', 2, ' ', 'right', NULL, NULL),
    (9, NULL, 'detail', 3, 'เลขที่บัญชีธนาคารพนักงาน', 'Employee Bank Account No.', 'employee_field', 'bank_account_no', NULL, 'text', 10, ' ', 'right', NULL, NULL),
    (9, NULL, 'detail', 4, 'ชื่อ-นามสกุลพนักงาน (ตามภาษาที่เลือกตอน Export)', 'Employee Name (follows the language chosen at Export)', 'employee_field', 'employee_name', NULL, 'text', 20, ' ', 'right', NULL, NULL),
    (9, NULL, 'detail', 5, 'จำนวนเงินที่โอน (2 หลักท้ายคือทศนิยม)', 'Transfer Amount (last 2 digits = decimal)', 'employee_field', 'net_amount', NULL, 'number', 11, '0', 'left', NULL, 2),
    (9, NULL, 'detail', 6, 'เว้นว่าง', 'Reserved / Blank', 'blank', NULL, NULL, 'text', 26, ' ', 'right', NULL, NULL),
    (9, NULL, 'detail', 7, 'รหัสอ้างอิงงวดจ่าย - ส่วนบริษัท (ต้องตรงกับ Header)', 'Pay Period Reference - Company Prefix (must match Header)', 'constant', NULL, 'XXX', 'text', 3, ' ', 'right', NULL, NULL),
    (9, NULL, 'detail', 8, 'รหัสอ้างอิงงวดจ่าย - เดือน/ปี (MMYY)', 'Pay Period Reference - Month/Year (MMYY)', 'employee_field', 'pay_period', NULL, 'date', 4, ' ', 'right', 'my', NULL);
