-- 2026-09-04, Backlog Phase 11, T061: "/payroll/setup/document-approval redesigned as cards with
-- example settings shown; simplify the form; wire into real document generation; support comp_code."
--
-- DocumentNumberingModel::generateNext() has existed since 2026-08-23 but was only ever wired to
-- ONE consumer (PAYROLL_RUN, via PayrollRunModel::create() -- run_code stamped once, at creation,
-- persisted on payroll_runs.run_code). T061 wires 2 more of its 4 fixed document_type_codes:
-- PAYSLIP and BANK_TRANSFER (WHT_CERT confirmed, via AskUserQuestion, to have no real generator
-- anywhere in this codebase -- left exactly as-is, config-only/unwired, same "built ahead of its
-- consumer" precedent as several other features in this app).
--
-- Both PAYSLIP and BANK_TRANSFER are generated ON DEMAND (a report `generate()` call, not a one-time
-- "create" event the way a payroll run has) -- calling generateNext() unconditionally on every
-- generate() call would silently assign a NEW number every time someone re-downloads the SAME real
-- document, which is wrong (a document number must be stable/idempotent for the same real-world
-- document). Both new columns below are the persisted "already-assigned" marker: generate() checks
-- the column first, only calls generateNext() (and persists the result back) the FIRST time a given
-- document is actually produced, and reuses the stored value on every subsequent re-download.
--
-- `payroll_run_details.payslip_number` -- one payslip per (run, employee) pair, assigned once.
-- `payroll_runs.bank_transfer_file_code` -- one bank transfer file per run (this report is
--   generated once per run, not per employee -- see BankTransferFileReport::generate()'s own
--   {comp_id, run_id} context shape), assigned once.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-04_10_document_numbering_wiring.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

ALTER TABLE `payroll_run_details`
    ADD COLUMN `payslip_number` VARCHAR(50) NULL DEFAULT NULL AFTER `net_amount`;

ALTER TABLE `payroll_runs`
    ADD COLUMN `bank_transfer_file_code` VARCHAR(50) NULL DEFAULT NULL AFTER `run_code`;

-- New selectable canvas field for the Payslip Template designer's "Add to Canvas" palette (document
-- group, same shape as the existing `static_text` row) -- so an admin can place `{{payslip_number}}`
-- on their own template layout, same "Auto Replace" token mechanism every other bound field already
-- uses. The FALLBACK (no-template) layout in PaySlipReport::buildHtml() embeds it directly, no
-- master-table row needed for that path.
INSERT INTO `master_payslip_field_types` (`code`, `name_th`, `name_en`, `field_group`, `element_type`, `is_active`, `sort_order`)
VALUES ('payslip_number', 'เลขที่เอกสาร', 'Document Number', 'document', 'text', 1, 6)
ON DUPLICATE KEY UPDATE `name_th` = VALUES(`name_th`), `name_en` = VALUES(`name_en`);
