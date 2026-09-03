-- Employee Sync: document scan URLs (deliberately deferred item from project_employee_sync_field_batch
-- migration -- see EmployeeSyncer::foreignWorkerFieldsFromItem()'s own docblock, which explicitly
-- flagged this as "this app has no document-viewing UI at all yet ... downloading and storing files
-- nobody can currently browse to would be dead weight, not a real feature").
--
-- That blocker no longer applies: `employee_documents` already has a fully-built generic
-- upload/list/view/delete flow (EmployeeController::documentList/Upload/View/Delete +
-- EmployeeModel::listDocuments/saveDocument/getDocument/deleteDocument), just switched OFF at the UI
-- level (app/views/employee/detail.php's Documents tab <li>/pane, both `class="... d-none"`). This
-- migration + the accompanying code change reuses that exact same table/flow for Origami-synced
-- document scans (passport/visa/work_permit) instead of building a parallel mechanism.
--
-- `source` distinguishes a manually-uploaded row (the only kind that existed before this) from one
-- EmployeeSyncer wrote from an Origami document_url -- lets the UI show a "Synced from Origami" badge
-- and lets EmployeeSyncer find/replace its OWN previously-synced row for a given document_type without
-- ever touching a row a human uploaded by hand for that same type.
-- `source_url` is the Origami URL the file was downloaded FROM (source='sync' only, NULL for a manual
-- upload) -- lets EmployeeSyncer cheaply detect "already synced, URL unchanged" on every re-sync
-- without re-downloading the file every time; when Origami's own URL changes (a re-upload on their
-- side), the old synced row is soft-deleted and a fresh one inserted, same soft-delete-and-replace
-- convention this app already uses for `approval_workflow_steps` etc.

ALTER TABLE `employee_documents`
  ADD COLUMN `source` enum('manual','sync') NOT NULL DEFAULT 'manual' AFTER `document_type`,
  ADD COLUMN `source_url` varchar(500) NULL DEFAULT NULL AFTER `file_path`;
