-- Platform Hardening Phase 5A/5B: file_size tracking on every real upload endpoint in the app
-- (previously zero file-size persistence anywhere -- every upload site already reads $file['size']
-- for its own MB-limit check, but never stored it), plus thumbnail_path on the 4 upload types that
-- get a real GD-generated thumbnail per the confirmed policy: the two reusable Image Library grids
-- (Employment Certificate / Payslip Template), the Employee profile photo, and image-mime (jpg/png
-- only) rows of employee_documents. companies.logo_path/signature_path and non-image
-- employee_documents rows get file_size tracking only, no thumbnail (see ThumbnailGenerator's own
-- docblock in app/services/ThumbnailGenerator.php).

ALTER TABLE `employee_documents`
  ADD COLUMN `file_size` INT UNSIGNED NULL AFTER `file_path`,
  ADD COLUMN `thumbnail_path` VARCHAR(500) NULL COMMENT 'set only for image-mime (jpg/png) rows; NULL for pdf/doc/docx and any row uploaded before this column existed' AFTER `file_size`;

ALTER TABLE `employment_certificate_images`
  ADD COLUMN `file_size` INT UNSIGNED NULL AFTER `file_path`,
  ADD COLUMN `thumbnail_path` VARCHAR(255) NULL AFTER `file_size`;

ALTER TABLE `payslip_images`
  ADD COLUMN `file_size` INT UNSIGNED NULL AFTER `file_path`,
  ADD COLUMN `thumbnail_path` VARCHAR(255) NULL AFTER `file_size`;

ALTER TABLE `companies`
  ADD COLUMN `logo_file_size` INT UNSIGNED NULL AFTER `logo_path`,
  ADD COLUMN `signature_file_size` INT UNSIGNED NULL AFTER `signature_path`;

ALTER TABLE `employees`
  ADD COLUMN `profile_photo_file_size` INT UNSIGNED NULL AFTER `profile_photo_path`,
  ADD COLUMN `profile_photo_thumbnail_path` VARCHAR(500) NULL AFTER `profile_photo_file_size`,
  ADD COLUMN `signature_file_size` INT UNSIGNED NULL AFTER `signature_path`;

ALTER TABLE `payroll_remittances`
  ADD COLUMN `evidence_file_size` INT UNSIGNED NULL AFTER `evidence_file_path`;

-- Phase 5C: Manual Entry import original-file retention -- ManualEntryController::importPreview()
-- (the only point in the request lifecycle that still has $_FILES['file'] in scope) now copies the
-- uploaded file to storage/uploads/import_originals/{comp_id}/{hex}.{ext} and hands back a token the
-- client echoes into importCommit(), which threads it through ImportService::commit() into
-- SyncBatchModel::start() so it lands on the same sync_batches row (source='import') that already
-- exists for this exact upload. Meaningful only when source='import'; NULL for every sync batch and
-- for any import batch committed before this column existed.
ALTER TABLE `sync_batches`
  ADD COLUMN `original_file_path` VARCHAR(500) NULL AFTER `user_agent`,
  ADD COLUMN `original_file_name` VARCHAR(255) NULL AFTER `original_file_path`,
  ADD COLUMN `original_file_size` INT UNSIGNED NULL AFTER `original_file_name`;
