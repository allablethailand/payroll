-- 2026-08-29, explicit request: "ไฟล์ในการขึ้นธนาคาร...หา Format ของธนาคารกรุงศรีอยูธยามาเป็นต้นแบบ เก็บไว้
-- ใน Database และให้เพิ่มให้สามารถตั้งค่าเองได้ว่า Format เป็นแบบไหนให้แก้ไขได้ แต่ต้องเก็บ Log โดย Default
-- ธนาคารให้ดึงมาจาก ธนาคารที่บริษัทมีการนำเข้าระบบ...โดยที่ไม่ต้อง Fixed Code แต่ให้มี Master ดึงมาเป็น Default
-- เท่าที่จะหาได้" -- BankTransferFileReport currently always produces one hardcoded generic CSV shape
-- (see that class's own docblock) regardless of `payroll_cycles.bank_file_format_id`, because no
-- bank's real bulk-transfer file spec was ever researched/verified. A public web search for Bank of
-- Ayudhya's (Krungsri CashLink) real spec turned up nothing -- it's proprietary corporate-banking
-- documentation only handed to onboarded business clients via their Relationship Manager/CashLink
-- portal, not published anywhere fetchable. Confirmed via AskUserQuestion: seed BAY as an editable
-- DRAFT starter template (is_verified=0) rather than inventing exact field positions -- same
-- "honest draft, not a fabricated spec" precedent already established in this project for
-- PndOneKorExporter/Sso110Exporter.
--
-- `master_bank_file_formats` (existing table, unchanged) stays the pick-list of NAMED formats.
-- These 3 new tables are the actual field-level layout data, entirely DB-driven (no bank format is
-- ever hardcoded in PHP) and editable per company without touching code:
--   - bank_file_format_fields: the field-by-field layout. comp_id IS NULL rows are the shipped
--     SYSTEM DEFAULT template for a given bank_file_format_id (seeded below for BAY only, since
--     that's the one format this request asked to seed) -- comp_id-scoped rows are a company's own
--     customized COPY, forked from the default the first time that company edits anything for that
--     format (see BankFileFormatModel::saveField()'s own docblock). This is the "โดยที่ไม่ต้อง Fixed
--     Code แต่ให้มี Master ดึงมาเป็น Default" part -- editing never mutates the shared default, and a
--     format with zero configured fields anywhere still falls back to BankTransferFileReport's
--     existing honest generic CSV.
--   - bank_file_format_configs: per-company header settings for one bank_file_format_id (delimiter
--     style, encoding, header/trailer row toggles, an is_verified flag the company flips once THEY
--     confirm the layout against their own bank -- same isVerified() convention already used
--     elsewhere in this app's export code, just company-settable instead of code-fixed).
--   - bank_file_format_edit_logs: append-only audit trail for every config/field change (explicit
--     "ต้องเก็บ Log" requirement) -- same shape/spirit as report_export_logs/approval_request_logs
--     elsewhere in this app (no UPDATE/DELETE on this table, ever).
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-08-29_bank_file_format_config.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

CREATE TABLE `bank_file_format_fields` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `bank_file_format_id` INT NOT NULL,
    `comp_id` INT NULL COMMENT 'NULL = shipped system-default template; otherwise a company''s own customized copy',
    `row_type` ENUM('header','detail','trailer') NOT NULL DEFAULT 'detail',
    `sort_order` INT NOT NULL DEFAULT 0,
    `field_label_th` VARCHAR(150) NOT NULL,
    `field_label_en` VARCHAR(150) NOT NULL,
    `source_type` ENUM('employee_field','constant','blank') NOT NULL DEFAULT 'employee_field',
    `source_field` VARCHAR(50) NULL COMMENT 'One of BankFileFormatModel::SOURCE_FIELDS, required when source_type=employee_field',
    `constant_value` VARCHAR(255) NULL COMMENT 'Required when source_type=constant',
    `data_type` ENUM('text','number','date') NOT NULL DEFAULT 'text',
    `width` INT NULL COMMENT 'Column width in characters -- required when the config''s delimiter_type is fixed_width',
    `pad_char` CHAR(1) NOT NULL DEFAULT ' ',
    `pad_direction` ENUM('left','right') NOT NULL DEFAULT 'right',
    `date_format` VARCHAR(20) NULL DEFAULT 'Ymd',
    `decimal_places` TINYINT NULL DEFAULT 2,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_bff_format_comp` (`bank_file_format_id`, `comp_id`, `row_type`, `sort_order`),
    CONSTRAINT `fk_bff_format` FOREIGN KEY (`bank_file_format_id`) REFERENCES `master_bank_file_formats` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_bff_comp` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bank_file_format_configs` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `comp_id` INT NOT NULL,
    `bank_file_format_id` INT NOT NULL,
    `delimiter_type` ENUM('fixed_width','delimited') NOT NULL DEFAULT 'delimited',
    `delimiter_char` VARCHAR(5) NULL DEFAULT ',',
    `line_ending` ENUM('crlf','lf') NOT NULL DEFAULT 'crlf',
    `has_header_row` TINYINT(1) NOT NULL DEFAULT 0,
    `has_trailer_row` TINYINT(1) NOT NULL DEFAULT 0,
    `text_encoding` ENUM('utf8','tis620') NOT NULL DEFAULT 'utf8',
    `is_verified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Company confirms this layout against their own bank before relying on it for a real upload',
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    `deleted_by` INT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_bffc_comp_format_deleted` (`comp_id`, `bank_file_format_id`, `deleted_at`),
    CONSTRAINT `fk_bffc_comp` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_bffc_format` FOREIGN KEY (`bank_file_format_id`) REFERENCES `master_bank_file_formats` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `bank_file_format_edit_logs` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `comp_id` INT NOT NULL,
    `bank_file_format_id` INT NOT NULL,
    `action` VARCHAR(30) NOT NULL COMMENT 'config_saved | field_saved | field_deleted | reset_to_default',
    `changed_by` INT NULL,
    `changed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `before_json` MEDIUMTEXT NULL,
    `after_json` MEDIUMTEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_bffl_comp_format` (`comp_id`, `bank_file_format_id`, `changed_at`),
    CONSTRAINT `fk_bffl_comp` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_bffl_format` FOREIGN KEY (`bank_file_format_id`) REFERENCES `master_bank_file_formats` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed a DRAFT starter layout for BAY (master_bank_file_formats.id = 9, bank_id = 8 = ธนาคารกรุงศรีอยุธยา)
-- ONLY -- the format this request specifically asked to prototype. Round, sensible fixed-width
-- widths for a single detail-row-per-employee layout (no header/trailer row) -- NOT claimed as a
-- verified Krungsri spec anywhere in the UI (bank_file_format_configs.is_verified defaults to 0);
-- a company opening "Manage Format" for BAY sees this pre-filled instead of a blank list and edits
-- it to match what their own bank/RM actually gives them.
INSERT INTO `bank_file_format_fields`
    (`bank_file_format_id`, `comp_id`, `row_type`, `sort_order`, `field_label_th`, `field_label_en`, `source_type`, `source_field`, `constant_value`, `data_type`, `width`, `pad_char`, `pad_direction`, `date_format`, `decimal_places`)
VALUES
    (9, NULL, 'detail', 1, 'เลขที่บัญชี', 'Account No.', 'employee_field', 'bank_account_no', NULL, 'text', 15, ' ', 'right', NULL, NULL),
    (9, NULL, 'detail', 2, 'ชื่อบัญชี', 'Account Name', 'employee_field', 'bank_account_name', NULL, 'text', 40, ' ', 'right', NULL, NULL),
    (9, NULL, 'detail', 3, 'จำนวนเงิน', 'Amount', 'employee_field', 'net_amount', NULL, 'number', 13, '0', 'left', NULL, 2),
    (9, NULL, 'detail', 4, 'รหัสอ้างอิง (รหัสพนักงาน)', 'Reference (Employee No.)', 'employee_field', 'employee_no', NULL, 'text', 15, ' ', 'right', NULL, NULL);
