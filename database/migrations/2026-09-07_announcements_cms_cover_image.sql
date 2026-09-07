-- 2026-09-07, explicit follow-up on the Announcement CMS (T057, see 2026-09-04_7_announcements.sql):
-- "แก้ไข เพิ่มเป็น CMS แบบ 100% จัดรูปแบบเนื้อหาได้ สามารถแนบปกได้ ใส่ Subject ได้ รองรับการจัดการเนื้อหา
-- แบบ 2 ภาษา" -- Create/Edit/Delete, 2-language content management, and a "Subject" field already
-- existed (title_th/title_en -- "หัวข้อ" IS "Subject" in this app's own i18n wording, see public/lang/
-- th.json's own `title_th`/`title_en` strings, reused as-is here, no schema change needed for that
-- part). The 2 genuinely new asks are: (1) rich content formatting for body_th/body_en (now real HTML
-- authored via a Quill editor, sanitized server-side in AnnouncementModel::sanitizeRichHtml() before
-- persisting -- body_th/body_en's own column type (TEXT) did not need to change, HTML markup for an
-- announcement-length body comfortably fits under TEXT's 65,535-byte cap), and (2) a cover image,
-- this migration's own one real schema change.
--
-- `cover_image_path` -- same nullable-path-column convention as `companies.logo_path` /
-- `payslip_templates.logo_path` / `employment_certificate_templates.logo_path` (validated at the
-- application layer via a new AnnouncementModel::isValidCoverPath() static, identical
-- traversal-proofing pattern to CompanyProfileModel::isValidLogoPath() -- must exactly match what the
-- new AnnouncementController::uploadCover() endpoint itself produces for THIS company). Uploaded via a
-- separate decoupled endpoint (same convention as every other image-upload feature in this app), so no
-- file-size/mime columns are needed here either -- nothing downstream reads them for a cover image.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-07_announcements_cms_cover_image.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

ALTER TABLE `announcements`
    ADD COLUMN `cover_image_path` VARCHAR(255) NULL DEFAULT NULL AFTER `body_en`;
