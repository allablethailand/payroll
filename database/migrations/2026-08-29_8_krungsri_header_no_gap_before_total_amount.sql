-- 2026-08-29, real bug found and fixed (explicit report: "ตรง head มันมีช่องว่างก่อนข้อมูลชุดสุดท้าย แต่
-- ไฟล์ต้นฉบับจะต่อกันเลย" -- the header has a stray space before the last data field, but the
-- original file connects them directly, no gap).
--
-- Root cause: the header layout seeded in 2026-08-29_4_krungsri_bank_transfer_real_layout.sql
-- included a 1-byte "Reserved / Blank" field (sort_order=12) between total record count and
-- total amount, derived from an exact byte-offset gap found in the user's own original sample
-- string at the time. The user has since compared the generated file directly against their real
-- original file and confirmed that gap should NOT be there -- almost certainly a single stray
-- space introduced when that original sample was hand-typed into chat, not a real feature of the
-- format (this project has already caught 2 other likely transcription slips in hand-pasted
-- fixed-width samples the same week -- see BankTransferFileReport/Sso110Exporter's own docblocks
-- -- this is the same category of issue, now directly confirmed by the user rather than inferred).
--
-- Fix: delete that blank field, and widen the total-amount field by the same 1 byte (14 -> 15)
-- so the header's own total length stays exactly 102 bytes -- the extra byte is pure headroom
-- (one more digit of whole-baht capacity), not a functional change to any other field.
DELETE FROM `bank_file_format_fields`
WHERE `bank_file_format_id` = 9 AND `row_type` = 'header' AND `sort_order` = 12 AND `source_type` = 'blank' AND `width` = 1;

UPDATE `bank_file_format_fields`
SET `width` = 15
WHERE `bank_file_format_id` = 9 AND `row_type` = 'header' AND `sort_order` = 13 AND `source_field` = 'total_amount';
