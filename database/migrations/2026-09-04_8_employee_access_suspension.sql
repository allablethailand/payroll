-- 2026-09-04, Backlog Phase 10, T059: "RBAC -- suspend a user's system access from settings."
--
-- Design workshop confirmed with the user (4 AskUserQuestion prompts) before this was built:
--   1. Mechanism: reuse the EXISTING `employee_permission_overrides` table (Platform Hardening
--      Phase 3) via a "bulk deny" rather than inventing a brand-new access-tier concept.
--   2. Admin accounts can NEVER be suspended.
--   3. Self-suspension is blocked.
--   4. UI lives on Employee Detail's existing "Permission Overrides" tab.
--
-- IMPORTANT ARCHITECTURAL FINDING, discovered during implementation (not assumed): "admin" in this
-- app is a PURELY SESSION-LEVEL claim from Origami SSO (auth/index.php's own $origamiRole mapping,
-- computed fresh on every login) with ZERO persistent representation anywhere in this schema --
-- `employees` has no is_admin/role='admin' column, `structure_roles` has no such flag either, and
-- `employee_login_logs` never captured the role claim historically. This means workshop decision #2
-- ("admin cannot be suspended") CANNOT be mechanically enforced by inspecting a stored attribute on
-- an arbitrary (not-currently-logged-in) target employee -- there is nothing to inspect. The
-- practical mitigation actually shipped: the suspend/unsuspend action is gated behind `rbac.edit`
-- (the SAME permission that already gates the Permission Matrix + Permission Overrides tab, per
-- PermissionController's own existing docblock reasoning: only someone already trusted to manage
-- every permission in the system can touch this). Self-suspension IS 100% enforceable (acting
-- employee id vs target employee id, both real, always-known values) and is enforced in
-- PermissionModel::suspendEmployee(). See that method's own docblock for the full reasoning -- this
-- is a genuine, documented architectural limitation, not an oversight.
--
-- Two things this migration adds:
--   1. `employees.access_suspended_at`/`_by`/`_reason` -- the FAST, authoritative "is this employee
--      suspended" marker. Checked first in ensure_login() (live enforcement, redirects to the
--      existing No Permission page, app/views/permission.php, WITHOUT destroying the session --
--      session-kill would break the normal header/sidebar layout that page relies on, per direct
--      testing while building this) and first in PermissionModel::checkPermission() (belt-and-
--      suspenders -- correctly denies a permission added to the catalog AFTER this employee was
--      suspended, even though no bulk-deny row would exist for it yet).
--   2. `employee_suspension_permission_snapshots` -- one row per (permission_id) that
--      suspendEmployee() touched for this employee, recording exactly what existed BEFORE the
--      suspend wrote a deny row over it (had_prior_override + the prior effect/scope/detail_level,
--      or NULL/0 meaning "there was nothing here before, this row is brand new"). unsuspendEmployee()
--      reads this table to restore EXACTLY the prior state per permission -- deleting a
--      suspend-created row outright, or restoring a pre-existing INDEPENDENT override's original
--      values -- rather than naively wiping every override row for the employee (which would
--      destroy any override that existed independently of the suspension). Rows are deleted once
--      consumed by unsuspend (this table only ever holds the CURRENTLY active suspension's own
--      snapshot for an employee -- suspendEmployee() itself refuses to double-suspend an already-
--      suspended employee, so there is never more than one live snapshot set per employee at once).
--
-- New permission: none -- suspend/unsuspend reuses the existing `rbac.edit` permission key
-- (PermissionController's own employeeOverridesSave() already documents this exact "only someone
-- who can already manage the Permission Matrix" trust boundary; adding a narrower new key here would
-- not add any real safety since rbac.edit already implies full override control over any employee).
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-04_8_employee_access_suspension.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

ALTER TABLE `employees`
    ADD COLUMN `access_suspended_at` TIMESTAMP NULL DEFAULT NULL AFTER `deleted_at`,
    ADD COLUMN `access_suspended_by` INT NULL DEFAULT NULL AFTER `access_suspended_at`,
    ADD COLUMN `access_suspended_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `access_suspended_by`;

-- `employee_login_logs.ended_reason` (Phase 7, T037/T038) is a strict ENUM('switch_app','new_login',
-- 'timeout') -- widened with 'suspended' so suspendEmployee() can correctly end the target's active
-- session with an accurate reason (Login History then shows WHY the session ended, instead of either
-- crashing on an invalid enum value or misleadingly reusing 'timeout').
ALTER TABLE `employee_login_logs`
    MODIFY COLUMN `ended_reason` ENUM('switch_app','new_login','timeout','suspended') NULL;

CREATE TABLE `employee_suspension_permission_snapshots` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `comp_id` INT NOT NULL,
    `employee_id` INT NOT NULL,
    `permission_id` INT NOT NULL,
    `had_prior_override` TINYINT(1) NOT NULL DEFAULT 0,
    `prior_effect` VARCHAR(10) NULL DEFAULT NULL COMMENT 'grant|deny|NULL -- the override effect that existed before suspend overwrote it, only meaningful when had_prior_override=1',
    `prior_allow_scope` VARCHAR(20) NULL DEFAULT NULL,
    `prior_detail_level` VARCHAR(10) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_esps_employee` (`comp_id`, `employee_id`),
    CONSTRAINT `fk_esps_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
