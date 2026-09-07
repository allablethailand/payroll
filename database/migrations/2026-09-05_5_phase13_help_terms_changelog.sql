-- 2026-09-05, Backlog Phase 13 (Onboarding/Help/Version system) -- explicitly deferred since the
-- original backlog prompt, un-deferred and started this session per direct user request. Confirmed
-- via AskUserQuestion before building: (1) Terms & Conditions content is PLACEHOLDER text for now,
-- not real legal copy -- the company will supply/edit the real text later, this round only builds
-- the mechanism; (2) the Help Guide checklist is COMPANY-LEVEL setup completeness (distinct from
-- the existing, separate per-EMPLOYEE completeness % feature); (3) the Help Drawer gets its
-- mechanism plus real content for the first ~5 main pages, not every page in the app yet.
--
-- 4 new tables:
--   1. `terms_and_conditions` -- PLATFORM-WIDE (no comp_id), versioned. A login-gate "Terms of
--      Service" style agreement is the same document for every company using this app, unlike
--      most content tables in this project which are per-company. Only one row is ever
--      `is_active=1` at a time (enforced at the application layer, same convention as every other
--      "single active X" rule elsewhere in this project -- no DB constraint needed for a table
--      this small/admin-only).
--   2. `terms_and_conditions_acceptances` -- one row per (employee, terms version) accepted. An
--      employee must accept the CURRENTLY active version to pass the login-gate modal; accepting
--      an OLDER version (from before the active one changed) does not count, so a new version
--      re-prompts everyone. Append-only audit trail, no UPDATE/DELETE method -- same convention as
--      ReportExportLogModel/ApprovalRequestModel's own log tables.
--   3. `app_changelog_entries` -- PLATFORM-WIDE release notes shown on the new Help > Version page.
--      A genuine master/reference table (closed set of entries that only ever grows via INSERT,
--      each entry is pure display data with no per-entry logic needed) -- matches this project's
--      own "master table for a fixed/closed list with no bespoke logic per item" convention.
--   4. `help_drawer_content` -- per-page contextual help shown in the new Help Drawer side panel,
--      keyed by a stable `page_key` (e.g. 'payroll_process', 'employee_list') the frontend already
--      knows from its own routing. Same master-table reasoning as #3 -- pure text content, no
--      logic. The company-level Setup Guide checklist itself (Help > Setup Guide) is NOT a table --
--      each checklist item needs its own bespoke "is this actually done" query (has a bank account,
--      has an active payroll cycle, etc.), so it lives in code (SetupGuideModel), matching this
--      project's own "tied to real logic, not master-table-ified" precedent (e.g. OT Rate's
--      calculation_base).
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-05_5_phase13_help_terms_changelog.sql

CREATE TABLE `terms_and_conditions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `version_label` VARCHAR(30) NOT NULL,
    `content_th` MEDIUMTEXT NOT NULL,
    `content_en` MEDIUMTEXT NOT NULL,
    `effective_date` DATE NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `terms_and_conditions_acceptances` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `terms_id` INT UNSIGNED NOT NULL,
    `employee_id` INT NOT NULL,
    `accepted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_terms_employee` (`terms_id`, `employee_id`),
    KEY `idx_employee` (`employee_id`),
    CONSTRAINT `fk_tca_terms` FOREIGN KEY (`terms_id`) REFERENCES `terms_and_conditions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_tca_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `app_changelog_entries` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `version_label` VARCHAR(30) NOT NULL,
    `release_date` DATE NOT NULL,
    `title_th` VARCHAR(255) NOT NULL,
    `title_en` VARCHAR(255) NOT NULL,
    `body_th` MEDIUMTEXT NOT NULL,
    `body_en` MEDIUMTEXT NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_active_sort` (`is_active`, `release_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `help_drawer_content` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `page_key` VARCHAR(60) NOT NULL,
    `title_th` VARCHAR(255) NOT NULL,
    `title_en` VARCHAR(255) NOT NULL,
    `body_th` MEDIUMTEXT NOT NULL,
    `body_en` MEDIUMTEXT NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_page_key` (`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: one PLACEHOLDER active Terms & Conditions version (explicit request: build the mechanism
-- now, real legal text is a later, separate task -- clearly labeled as a placeholder in the text
-- itself so nobody mistakes it for something legally binding).
INSERT INTO `terms_and_conditions` (`version_label`, `content_th`, `content_en`, `effective_date`, `is_active`)
VALUES ('1.0-draft',
'[ข้อความตัวอย่าง — ยังไม่ใช่ข้อกำหนดและเงื่อนไขฉบับจริง กรุณาแทนที่ด้วยเนื้อหาจริงก่อนใช้งาน]\n\nการใช้งานระบบ Origami Payroll นี้ ท่านตกลงที่จะปฏิบัติตามข้อกำหนดและเงื่อนไขการใช้งานที่บริษัทกำหนด รวมถึงนโยบายความเป็นส่วนตัวเกี่ยวกับข้อมูลส่วนบุคคลของท่าน ระบบจะเก็บบันทึกการยอมรับข้อกำหนดนี้ไว้เป็นหลักฐาน',
'[Placeholder text — this is NOT the real Terms & Conditions. Replace with the actual legal content before relying on this for anything binding.]\n\nBy using this Origami Payroll system, you agree to comply with the terms of use set by the company, including the privacy policy regarding your personal data. The system will keep a record of your acceptance of these terms.',
CURDATE(), 1);

-- Seed: changelog entries summarizing recent real, shipped work (from this project's own history),
-- newest first by sort_order. A starting point, not exhaustive -- future shipped features should
-- add their own row here going forward (see docs/release-process.md).
INSERT INTO `app_changelog_entries` (`version_label`, `release_date`, `title_th`, `title_en`, `body_th`, `body_en`, `sort_order`) VALUES
('2026.09.05', '2026-09-05', 'ระบบยื่นราชการ (ภ.ง.ด./สปส./กยศ.) และไฟล์โอนเงินธนาคาร ยืนยันรูปแบบแล้ว',
 'Government e-filing (PND/SSO/Student Loan) and bank transfer file formats confirmed',
 'ปรับรูปแบบไฟล์ ภ.ง.ด.1, ภ.ง.ด.1ก, สปส.1-10 ให้ตรงกับข้อกำหนดที่ยืนยันแล้ว พร้อมเพิ่มไฟล์ยื่น สปส.6-09 และ กยศ. ที่ไม่มีมาก่อน และยืนยันรูปแบบไฟล์โอนเงินธนาคารกรุงศรีอยุธยา (Cashlink)',
 'PND1/PND1K/SSO 1-10 export formats rewritten against a confirmed reference spec, new SSO 6-09 and Student Loan Fund export formats added (previously unavailable), and the Bank of Ayudhya (Krungsri) Cashlink bank transfer file format confirmed and configured.',
 100),
('2026.09.05', '2026-09-05', 'สลิปเงินเดือนสร้างครั้งเดียวและใช้ไฟล์เดิมซ้ำ ไม่ต้องสร้างใหม่ทุกครั้ง',
 'Pay slips are now generated once and reused, not re-rendered every time',
 'ปรับให้ระบบสร้างไฟล์ PDF สลิปเงินเดือนเพียงครั้งเดียวต่อพนักงานต่อรอบจ่าย แล้วใช้ไฟล์เดิมซ้ำในการดาวน์โหลด/ส่งครั้งต่อไป ทำให้เร็วขึ้นและได้ไฟล์เดียวกันทุกครั้ง',
 'Pay slip PDFs are now generated once per employee per payroll run and reused for every later download or delivery attempt, instead of being re-rendered from scratch every time.',
 90),
('2026.09.04', '2026-09-04', 'โหมดกลางคืน (Dark Mode)', 'Dark Mode',
 'เพิ่มการตั้งค่าธีมสี Light/Dark/ตามระบบ ในหน้า Settings ของผู้ใช้แต่ละคน ค่าที่ตั้งไว้จะถูกจดจำในทุกอุปกรณ์ที่เข้าสู่ระบบ',
 'Added a per-user Light/Dark/System theme preference in the Settings modal, remembered across devices and logins.',
 80),
('2026.08.30', '2026-08-30', 'ระบบ RBAC และการจำกัดสิทธิ์การเข้าถึงรายบุคคล', 'RBAC and per-employee permission overrides',
 'เพิ่มระบบจัดการสิทธิ์แบบละเอียด (Permission Matrix) และสามารถตั้งค่าสิทธิ์เพิ่ม/ลดเป็นรายบุคคลได้ที่หน้ารายละเอียดพนักงาน',
 'Added fine-grained role-based permissions (Permission Matrix) with the ability to grant or deny specific permissions per individual employee from their Employee Detail page.',
 70);
