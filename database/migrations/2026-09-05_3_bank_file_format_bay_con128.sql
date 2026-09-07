-- 2026-09-05, Phase 12 T072 -- replaces the 2026-08-29 BAY (Bank of Ayudhya / Krungsri) DEFAULT
-- field template (`bank_file_format_fields` WHERE bank_file_format_id=9 AND comp_id IS NULL) with
-- a real, confirmed layout: "Krungsri Cashlink CON128", a genuine fixed-width 128-byte-per-record
-- corporate bulk-payment format (128 = 128 BYTES, matching the reference file's own name exactly,
-- confirmed by hand-summing every field's own declared width). The old rows were an honest,
-- documented guess ("no public Krungsri CashLink bulk-payment spec exists to research" -- see
-- BankFileFormatModel's own docblock) that summed to 102/80 bytes, not 128/128 -- genuinely a
-- different, unconfirmed layout, not a refinement of it.
--
-- Source: `BAYCON128_spliter_1.2_TDI.xlsm`, a macro-enabled Excel tool the user supplied. The
-- actual byte layout lives in its VBA code (`CreateText()` sub, `Case "128"` branch), NOT in any
-- visible worksheet cell -- extracted via `pip install oletools` + `python -m oletools.olevba`
-- (plain unzip/strings alone only shows the VBA project's own compressed binary garbage). That one
-- workbook actually implements 4 different bank formats in the same macro (MYFORMAT/CBOS/320/128);
-- "128" was identified as the real target because ONLY that branch's header AND detail row both
-- sum to exactly 128 bytes (every other branch is messier, generic multi-bank/multi-product
-- scaffolding with commented-out sections, not specific to this one 128-byte record format).
--
-- Verified before writing this migration was safe to apply: ZERO companies have ever forked their
-- own copy of this default template (`bank_file_format_fields` with comp_id NOT NULL for this
-- format_id) and ZERO have saved a `bank_file_format_configs` row for it either -- replacing the
-- shared default cannot silently change what any company already sees/uses.
--
-- ONE deliberate correction vs. the VBA's own literal behavior, not a blind port: the VBA's
-- beneficiary-account field used `AddBlank(acct, 10, "", "B")` -- an EMPTY fill-character argument,
-- which (per that function's own logic) means "do NOT pad at all, just truncate if too long" --
-- inconsistent with every OTHER account-number-shaped field in the SAME VBA file (which all use
-- `" "` as the fill char) and would make the row's own total byte length VARY with the account
-- number's digit count, defeating the entire point of a "128-byte fixed record" format. Treated as
-- a likely typo in the reference tool, not a real spec requirement -- this field pads with spaces
-- (pad_char=' ', pad_direction='right') like every sibling field, same as this project's own
-- existing BankFileFormatModel convention for every other text field.
--
-- ONE field with NO confirmed real-world value: header field #14 (10 bytes, "bank product code" --
-- VBA's own `myproduct`, a value normally chosen once per company/bank relationship, e.g. which
-- CashLink product the company registered for). Seeded here as an EDITABLE constant with a
-- placeholder value ('PAYROLL') and a field label that says so explicitly -- a company must
-- confirm/edit this with their own Krungsri relationship manager before relying on the file for a
-- real transfer, same "known gap, company must confirm/customize" posture this project already
-- applies elsewhere (e.g. the header's own "712"/"A"/"0010001" literal constants below, which are
-- reproduced EXACTLY as the reference tool hardcodes them, unverified against any independent BAY
-- documentation beyond that one source).
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-05_3_bank_file_format_bay_con128.sql

UPDATE `master_bank_file_formats`
    SET `name_th` = 'กรุงศรีอยุธยา - Cashlink CON128',
        `name_en` = 'Bank of Ayudhya (Krungsri) - Cashlink CON128'
    WHERE `id` = 9;

DELETE FROM `bank_file_format_fields` WHERE `bank_file_format_id` = 9 AND `comp_id` IS NULL;

INSERT INTO `bank_file_format_fields`
    (`bank_file_format_id`, `comp_id`, `row_type`, `sort_order`, `field_label_th`, `field_label_en`, `source_type`, `source_field`, `constant_value`, `data_type`, `width`, `pad_char`, `pad_direction`, `date_format`, `decimal_places`)
VALUES
    -- HEADER row (128 bytes: 3+3+6+30+3+2+5+20+1+7+7+15+16+10 = 128)
    (9, NULL, 'header', 1,  'รหัสประเภทระเบียน (คงที่)', 'Record Type Code (constant)', 'constant', NULL, '001', 'text', 3, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 2,  'รหัสประเภทระเบียนย่อย (คงที่)', 'Record Sub-Type Code (constant)', 'constant', NULL, '001', 'text', 3, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 3,  'วันที่ทำรายการจ่าย (DDMMYY)', 'Payment/Effective Date (DDMMYY)', 'employee_field', 'payment_date', NULL, 'date', 6, ' ', 'right', 'dmy', NULL),
    (9, NULL, 'header', 4,  'เลขที่บัญชีตัดเงินของบริษัท', "Company's Own Debit Account No.", 'employee_field', 'company_account_no', NULL, 'text', 30, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 5,  'รหัสสถาบันการเงิน (คงที่ ตามไฟล์อ้างอิง)', 'Financial Institution Code (constant, per reference file)', 'constant', NULL, '712', 'text', 3, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 6,  'สำรอง/เว้นว่าง', 'Reserved / Blank', 'blank', NULL, NULL, 'text', 2, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 7,  'สำรอง/เว้นว่าง', 'Reserved / Blank', 'blank', NULL, NULL, 'text', 5, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 8,  'สำรอง/เว้นว่าง', 'Reserved / Blank', 'blank', NULL, NULL, 'text', 20, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 9,  'ประเภทการชำระเงิน (A = ปกติ, คงที่)', 'Payment Type (A = Normal, constant)', 'constant', NULL, 'A', 'text', 1, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 10, 'รหัสควบคุมระเบียน (คงที่ ตามไฟล์อ้างอิง)', 'Record Control Code (constant, per reference file)', 'constant', NULL, '0010001', 'text', 7, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 11, 'จำนวนรายการทั้งหมด', 'Total Record Count', 'employee_field', 'total_count', NULL, 'number', 7, '0', 'left', NULL, 0),
    (9, NULL, 'header', 12, 'ยอดรวมทั้งหมด (2 หลักท้าย = ทศนิยม)', 'Total Amount (last 2 digits = decimal)', 'employee_field', 'total_amount', NULL, 'number', 15, '0', 'left', NULL, 2),
    (9, NULL, 'header', 13, 'สำรอง/เว้นว่าง', 'Reserved / Blank', 'blank', NULL, NULL, 'text', 16, ' ', 'right', NULL, NULL),
    (9, NULL, 'header', 14, 'รหัสผลิตภัณฑ์ธนาคาร (แก้ไขให้ตรงกับที่ตกลงกับธนาคาร)', 'Bank Product Code (EDIT to match your actual agreement with Krungsri)', 'constant', NULL, 'PAYROLL', 'text', 10, ' ', 'right', NULL, NULL),
    -- DETAIL row, 1 per beneficiary (128 bytes: 6+10+20+11+5+21+7+48 = 128)
    (9, NULL, 'detail', 1, 'รหัสประเภทระเบียน (คงที่)', 'Record Type Code (constant)', 'constant', NULL, '001001', 'text', 6, ' ', 'right', NULL, NULL),
    (9, NULL, 'detail', 2, 'เลขที่บัญชีธนาคารพนักงาน', 'Employee Bank Account No.', 'employee_field', 'bank_account_no', NULL, 'text', 10, ' ', 'right', NULL, NULL),
    (9, NULL, 'detail', 3, 'ชื่อพนักงาน (ตามภาษาที่เลือกตอน Export)', 'Employee Name (follows the language chosen at Export)', 'employee_field', 'employee_name', NULL, 'text', 20, ' ', 'right', NULL, NULL),
    (9, NULL, 'detail', 4, 'ยอดโอน (2 หลักท้าย = ทศนิยม)', 'Transfer Amount (last 2 digits = decimal)', 'employee_field', 'net_amount', NULL, 'number', 11, '0', 'left', NULL, 2),
    (9, NULL, 'detail', 5, 'สำรอง/เว้นว่าง', 'Reserved / Blank', 'blank', NULL, NULL, 'text', 5, ' ', 'right', NULL, NULL),
    (9, NULL, 'detail', 6, 'สำรอง/เว้นว่าง', 'Reserved / Blank', 'blank', NULL, NULL, 'text', 21, ' ', 'right', NULL, NULL),
    (9, NULL, 'detail', 7, 'รหัสควบคุมระเบียน (คงที่ ตามไฟล์อ้างอิง)', 'Record Control Code (constant, per reference file)', 'constant', NULL, '0010001', 'text', 7, ' ', 'right', NULL, NULL),
    (9, NULL, 'detail', 8, 'สำรอง/เว้นว่าง', 'Reserved / Blank', 'blank', NULL, NULL, 'text', 48, ' ', 'right', NULL, NULL);
