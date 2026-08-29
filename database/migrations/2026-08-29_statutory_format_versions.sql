-- 2026-08-29, follow-up to the Bank File Format work earlier today: "ส่วน Format เอกสารของการนำส่ง
-- สรรพากร และ ประกันสังคม ก็อยากให้มีการตั้งค่าเหมือนกัน แต่ดูความเหมาะสมว่าจะนำไปไว้ที่ฝั่งไหน" (also want
-- configurability for the RD/SSO submission document formats, use judgement on where it belongs).
--
-- Deliberately NOT the same shape as Bank File Format's field-by-field editor
-- (bank_file_format_fields/bank_file_format_configs) -- ภ.ง.ด./สปส. layouts are government-
-- mandated exact byte-position specs (see PndOneExporter/Sso110Exporter's own docblocks), not
-- something bilaterally agreed with a bank the way a bulk-transfer file is. Letting an admin
-- freely edit field widths there risks producing a rejected/wrong real filing. Instead this is a
-- lighter VERSION SELECTOR: a master table of known format versions per form (so a future format
-- change -- e.g. SSO's own real Jan 2026 format update, see Sso110Exporter's docblock -- is a new
-- master row + a new code branch in that one exporter class, not a schema change) plus a
-- per-company selection of which version to use. Surfaced in Tax & Statutory settings (existing
-- home for country/company-specific statutory config) as a 3rd "Document Format" tab.
--
-- `master_statutory_format_versions` is global (not per-company) -- the SET of known versions for
-- a form is a system fact, not something a company invents themselves.
-- `company_statutory_format_settings` is the per-company selection -- a company that never opens
-- this settings tab has no row here, and StatutoryFormatVersionModel::resolveVersionCode() falls
-- back to whichever version has `is_default = 1` for that form, so this migration changes no
-- existing behavior for anyone until they explicitly pick something.
--
-- Seeded with exactly ONE version per form (TH_PND1, TH_SSO110) -- the one PndOneExporter/
-- Sso110Exporter classes already implement today. Both exist as company-facing forms in
-- ReportRegistry (per this project's Reports module) with real "print" shortcuts already added
-- to the Payroll Process pages earlier today.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-08-29_statutory_format_versions.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

CREATE TABLE `master_statutory_format_versions` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `form_code` VARCHAR(30) NOT NULL COMMENT 'Matches ReportGeneratorInterface::code(), e.g. TH_PND1 / TH_SSO110',
    `version_code` VARCHAR(30) NOT NULL COMMENT 'Passed through to the exporter class as context[version_code] for it to validate/branch on',
    `name_th` VARCHAR(150) NOT NULL,
    `name_en` VARCHAR(150) NOT NULL,
    `effective_date` DATE NULL COMMENT 'When this version starts being the officially correct one to file, if known',
    `is_verified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Mirrors the exporter class''s own isVerified() -- shown as a warning badge in the picker, not independently re-verified here',
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_form_version` (`form_code`, `version_code`),
    KEY `idx_form_active` (`form_code`, `is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `company_statutory_format_settings` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `comp_id` INT NOT NULL,
    `form_code` VARCHAR(30) NOT NULL,
    `version_id` INT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    `updated_by` INT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_comp_form` (`comp_id`, `form_code`),
    CONSTRAINT `fk_csfs_comp` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_csfs_version` FOREIGN KEY (`version_id`) REFERENCES `master_statutory_format_versions` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `master_statutory_format_versions`
    (`form_code`, `version_code`, `name_th`, `name_en`, `effective_date`, `is_verified`, `is_default`, `is_active`, `sort_order`)
VALUES
    ('TH_PND1', 'v1_current', 'รูปแบบปัจจุบันในระบบ (ยังไม่ยืนยันกับกรมสรรพากรอย่างเป็นทางการ)', 'Current System Implementation (Not Yet Officially Verified with the Revenue Department)', NULL, 0, 1, 1, 1),
    ('TH_SSO110', 'v1_current', 'รูปแบบปัจจุบันในระบบ (อ้างอิงจากแหล่งข้อมูลภายนอก ยังไม่ยืนยันว่าเป็นฉบับก่อนหรือหลัง 1 ม.ค. 2569)', 'Current System Implementation (Sourced Externally, Not Confirmed Pre- or Post-Jan-2026 SSO Update)', NULL, 0, 1, 1, 1);
