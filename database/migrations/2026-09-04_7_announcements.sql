-- 2026-09-04, Backlog Phase 10, T057: "full Announcement CMS (recipient groups limited to
-- Payroll-access users; Accept-required vs. dismissible; Draft/Publish, publish stamps only
-- currently-active employees; shown in Notifications with 'view more' AND as a first-login-after-
-- publish modal with click-through-all + remaining-count if multiple pending; admin picks ONE
-- announcement to show on Dashboard + a view-all button)."
--
-- 3 new tables:
--   `announcements` -- the CMS content itself, Draft/Published, exactly one `is_dashboard_featured`
--     per company (same "exactly one mandatory X" enforcement pattern as ot_rate_sets.is_default /
--     probation_policy_sets.is_default, enforced at AnnouncementModel::setDashboardFeatured()'s own
--     application layer, same as those two).
--   `announcement_recipients` -- the PUBLISH-TIME SNAPSHOT of who an announcement actually went to.
--     "publish stamps only currently-active employees" means the target list is resolved ONCE, at
--     the moment Publish is clicked (via EntityAssignmentModel::resolveForEmployee(),
--     entity_type='announcement', against every employee with employment_status NOT IN ('resigned',
--     'terminated') -- see this project's own established "active employee" filter convention,
--     e.g. NotificationModel::probationInternExpiringEmployees()), then FROZEN into this table.
--     A later org-structure change, a new hire, or someone leaving never retroactively changes an
--     already-published announcement's real recipient list -- this table has no FK-cascade-driven
--     recompute anywhere, by design.
--   `announcement_acknowledgments` -- one row per employee once they've Accepted/Dismissed. A
--     recipient with no row here yet is "pending" (drives the first-login click-through modal's
--     queue + remaining-count).
--
-- "recipient groups limited to Payroll-access users" -- investigated what distinguishes "has actual
-- Payroll access" from "exists as an HR-synced employee record" in this app: there is no separate
-- flag for this (is_payroll_ready is about DATA COMPLETENESS for running payroll math on someone,
-- not about system access -- a resigned employee can still be is_payroll_ready=1 from before they
-- left). The one real, consistent signal already used everywhere else in this codebase for "this is
-- a genuine current employee, not a former one" is `employment_status NOT IN ('resigned',
-- 'terminated') AND deleted_at IS NULL` -- adopted here as the operational meaning of "Payroll-access
-- users": every currently-active employee of the company is a Payroll user by definition of how this
-- whole app works (auto-provisioned via Origami SSO on first real login), so this filter alone
-- correctly excludes former employees from ever becoming a recipient, without inventing a new
-- "has logged in at least once" gate that would just as easily exclude someone who legitimately
-- hasn't needed to open Payroll yet this month.
--
-- No new master_ table needed for accept_required vs. dismissible -- both are the SAME underlying
-- acknowledgment mechanism (announcement_acknowledgments), only the required COPY/flow differs client
-- side based on the `accept_required` flag, per this task's own literal wording ("Accept-required vs.
-- dismissible" reads as a UX variant, not two different data shapes).
--
-- Recipient scoping itself reuses T055's generic `entity_assignments` (entity_type='announcement') --
-- zero assignment rows = unscoped (every active employee), matching that table's own established
-- "zero rows = applies to everyone" convention, so "send to literally everyone" needs no special case.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-04_7_announcements.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

CREATE TABLE `announcements` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `comp_id` INT NOT NULL,
    `title_th` VARCHAR(255) NOT NULL,
    `title_en` VARCHAR(255) NOT NULL,
    `body_th` TEXT NOT NULL,
    `body_en` TEXT NOT NULL,
    `accept_required` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'true = employee must click Accept (blocking, no backdrop/Esc dismiss in the modal), false = plain Dismiss/OK closes it',
    `status` ENUM('draft','published') NOT NULL DEFAULT 'draft',
    `is_dashboard_featured` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Exactly one 1-row per comp_id at a time, enforced at the application layer (AnnouncementModel::setDashboardFeatured())',
    `published_at` TIMESTAMP NULL DEFAULT NULL,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    `deleted_by` INT NULL DEFAULT NULL,
    `created_by` INT NULL DEFAULT NULL,
    `updated_by` INT NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_announcements_comp` (`comp_id`, `status`, `deleted_at`),
    CONSTRAINT `fk_announcements_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE `announcement_recipients` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `announcement_id` INT NOT NULL,
    `employee_id` INT NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_announcement_recipient` (`announcement_id`, `employee_id`),
    KEY `idx_announcement_recipients_employee` (`employee_id`),
    CONSTRAINT `fk_ar_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ar_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE `announcement_acknowledgments` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `announcement_id` INT NOT NULL,
    `employee_id` INT NOT NULL,
    `acknowledged_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `acknowledged_via` VARCHAR(30) NULL COMMENT 'modal / notification -- where the click happened, informational only',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_announcement_ack` (`announcement_id`, `employee_id`),
    CONSTRAINT `fk_aa_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_aa_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

INSERT INTO `permissions` (`module_code`,`action_code`,`permission_key`,`name_th`,`name_en`,`is_active`,`sort_order`) VALUES
('announcement','manage','announcement.manage','จัดการประกาศ','Manage Announcements',1,170)
ON DUPLICATE KEY UPDATE `name_th` = VALUES(`name_th`), `name_en` = VALUES(`name_en`);
