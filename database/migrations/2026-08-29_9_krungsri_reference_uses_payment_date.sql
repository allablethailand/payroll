-- 2026-08-29, real bug found and fixed (explicit report: "0726 ไม่ใช่ครับต้องเป็น 0826 ตามเดือนที่จ่าย"
-- -- the header's own DDMMYY "Payment Date" field correctly showed August, but the reference
-- code's own MMYY portion showed July instead).
--
-- Root cause: the "รหัสอ้างอิงงวดจ่าย - เดือน/ปี (MMYY)" fields (header sort_order=10, detail
-- sort_order=8) were seeded with source_field='pay_period', which
-- BankTransferFileReport::renderConfigured() computes from the run's period_start_date -- NOT
-- payment_date. A run's pay PERIOD (e.g. July 1-31) and its actual DISBURSEMENT date (e.g. paid
-- Aug 5th) are commonly different months, exactly the case this bug report caught. The reference
-- code is meant to identify WHEN THE MONEY WAS ACTUALLY PAID, same as the header's own Payment
-- Date field right next to it -- both should derive from the SAME payment_date, not two
-- different dates.
--
-- Fix: repoint source_field to 'payment_date' (date_format stays 'my', unaffected -- this just
-- changes WHICH date value is formatted).
UPDATE `bank_file_format_fields`
SET `source_field` = 'payment_date'
WHERE `bank_file_format_id` = 9 AND `source_field` = 'pay_period';
