-- Platform Hardening Phase 3, Stage 3 -- final cleanup, per Stage 1's own migration comment
-- ("Old `.manage` rows are intentionally LEFT AS-IS ... until every controller currently checking
-- them has been re-gated onto the new specific keys (Stage 3), at which point a follow-up
-- migration flips them to is_active=0").
--
-- Every controller call site that used to check one of these 14 modules' coarse `.manage` key has
-- now been swapped onto the specific view/add/edit/delete key that matches what the method
-- actually does (exhaustive grep sweep across app/, public/js/, index.php confirmed zero remaining
-- functional references -- only historical docblock prose mentions the old key name in a few
-- files, left as-is, purely descriptive). role_permissions rows granting the old `.manage` key are
-- NOT deleted (soft-hide only, matches this app's own soft-delete-everywhere convention, and keeps
-- the grant recoverable if `is_active` is ever flipped back) -- they simply stop being consulted
-- by checkPermission()'s own `p.is_active = 1` join condition the moment this runs.
--
-- Real near-miss caught before writing this migration: `app/views/layout/header.php`'s own
-- Permissions-menu visibility check and `NotificationController::requireRbacManage()` (both used
-- `rbac.manage` directly, missed by the original controller-by-controller sweep since neither is a
-- "controller `.manage` call site" in the usual sense -- one's a view file, the other a private
-- helper with its own name) -- both fixed to `rbac.view`/`.edit` in this same Stage 3 pass BEFORE
-- this migration was written, or deactivating `rbac.manage` here would have hidden the Permissions
-- menu from every non-admin user site-wide. `HolidaySyncController` (candidates/apply/log) had the
-- same miss for `holiday.manage`, fixed the same way. Same lesson as Stage 2's own `approvalFlow()`
-- raw-SQL near-miss: a module_code's real dependents can hide outside the obvious controller files.
--
-- `payroll_run.manage` (retired back in Stage 2) is NOT touched here -- already handled by that
-- stage's own cleanup migration.

UPDATE `permissions` SET `is_active` = 0
WHERE `module_code` IN (
    'holiday','leave_type','approval_workflow','rbac','company_structure','bank_account',
    'payslip_template','payroll_configuration','employment_certificate_template','tax_statutory',
    'company_profile','payroll_run_cash_payment','payroll_remittance','employee'
) AND `action_code` = 'manage';
