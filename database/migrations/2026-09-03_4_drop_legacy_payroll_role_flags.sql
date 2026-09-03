-- Platform Hardening Phase 3, Stage 2 final cleanup: drops structure_roles.can_process_payroll/
-- can_approve_payroll/can_finalize_payroll. Safe to run now -- confirmed via an exhaustive
-- application-code sweep (PayrollRunModel::userCan() and every one of its ~30 direct call sites,
-- CompanyProfileModel's generic structure CRUD, NotificationModel::createForPermissionHolders())
-- that NOTHING reads these 3 columns anymore as of the 2026-09-03_3 migration + its accompanying
-- application code changes -- every one of those call sites now reads the 1:1 replacement
-- payroll_run.process/.approve/.finalize permission keys instead (via PermissionModel::
-- checkPermission()), which that same migration already backfilled from these columns' live values
-- before this migration ever runs. The full test suite (tests/*.php, 850+ assertions in
-- payroll_run_test.php alone, including a dedicated admin-bypass/per-user-override regression suite)
-- was re-run clean against the application code BEFORE this column drop -- if this migration is ever
-- run out of order (before that code is deployed), any code still reading these columns would simply
-- always see NULL/missing-column errors instead of silently misbehaving, since MySQL raises a real
-- error for a SELECT against a dropped column rather than returning a stale value.

ALTER TABLE `structure_roles`
    DROP COLUMN `can_process_payroll`,
    DROP COLUMN `can_approve_payroll`,
    DROP COLUMN `can_finalize_payroll`;
