-- 2026-08-29, explicit request: "เพิ่มให้สามารถเลือกเอาเงินเดือนออกจากการคำนวณได้ หรือค่าอื่นๆที่ไม่นำมา
-- คำนวณ ทั้ง template เลย และกำหนดได้สำหรับพนักงานรายบุคคล ติ๊กเอาหรือไม่เอา เพื่อให้รองรับบางรอบที่ไม่นำ
-- บางค่ามาคำนวณ และต้องกำหนดได้ด้วยว่าคำนวณภาษี ไม่คำนวณภาษี ส่งประกันสังคมไหม กำหนดแบบทั้งหมด และรายบุคคลได้"
--
-- Two independent concerns:
--   1) Item exclusion (base salary + any earning/deduction catalog item) -- a NEW run-level
--      default list (payroll_run_item_exclusions, applies to everyone in the run). The
--      PER-EMPLOYEE override side deliberately reuses the EXISTING payroll_run_line_overrides
--      table/mechanism (its own 'exclude' action) instead of a second, parallel per-employee
--      table -- that mechanism already covers "exclude this one item for this one employee" for
--      every earning/deduction line via the Manage Items modal's "Adjust Amounts" tab; this round
--      only teaches PayrollRunModel::recalculate() to ALSO honor 'exclude' for base salary
--      (previously a no-op there) and to fall back to the new run-level default when an employee
--      has no override of their own for a given item_code. See recalculate()'s own docblock for
--      the exact layering.
--   2) Tax/SSO calculation toggle -- run-level default (new payroll_run_calc_settings) layered
--      under the EXISTING per-employee payroll_run_employee_exemptions table, which is widened
--      here from a force-off-only boolean pair to a bidirectional tri-state pair
--      (inherit/yes/no) so a per-employee override can now also force something back ON, not
--      just off. See PayrollRunModel::recalculate()'s own docblock for the full resolution order
--      (employee override > run-level default > employee's own permanent flag).
--
-- Both apply to EVERY payroll run type (regular sync-driven + off-cycle/manual), not just
-- off-cycle runs -- confirmed via AskUserQuestion.

-- (1) Run-level item/base-salary exclusion default.
CREATE TABLE IF NOT EXISTS `payroll_run_item_exclusions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `run_id` INT NOT NULL,
    -- Either a real `payroll_earning_deduction_types.item_code`, or the reserved
    -- PayrollRunModel::BASE_SALARY_OVERRIDE_CODE pseudo-item ('__base_salary__') already used
    -- elsewhere in this app for the base-salary VALUE override mechanism -- same reserved code,
    -- different (boolean presence, not a value) mechanism here.
    `item_code` VARCHAR(50) NOT NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_run_item_exclusion` (`run_id`, `item_code`),
    CONSTRAINT `fk_prie_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (2a) Run-level tax/SSO calculation default ("Run Settings" panel, Process Detail page). One row
-- per run, created lazily the first time an admin actually changes a default away from
-- 'use_employee_setting' (a run with no row here behaves exactly as before this feature existed).
CREATE TABLE IF NOT EXISTS `payroll_run_calc_settings` (
    `run_id` INT NOT NULL PRIMARY KEY,
    `tax_calculate_default` ENUM('use_employee_setting','yes','no') NOT NULL DEFAULT 'use_employee_setting',
    `sso_calculate_default` ENUM('use_employee_setting','yes','no') NOT NULL DEFAULT 'use_employee_setting',
    `updated_by` INT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_prcs_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (2b) Widen the EXISTING per-employee exemption table from force-off-only booleans to a
-- bidirectional tri-state pair. Additive (old exempt_tax/exempt_sso columns are left in place,
-- untouched, for historical rows/tooling that might still read them) -- new columns are what
-- PayrollRunModel now actually reads going forward. Backfilled from the old columns' own
-- semantics (exempt_tax=1 always meant "force tax calculation OFF for this run", never "force
-- ON" -- that capability never existed before -- so the backfill below is a lossless, exact
-- translation of the old meaning into the new tri-state, not a guess).
ALTER TABLE `payroll_run_employee_exemptions`
    ADD COLUMN `tax_calculate_override` ENUM('inherit','yes','no') NOT NULL DEFAULT 'inherit' AFTER `exempt_sso`,
    ADD COLUMN `sso_calculate_override` ENUM('inherit','yes','no') NOT NULL DEFAULT 'inherit' AFTER `tax_calculate_override`;

UPDATE `payroll_run_employee_exemptions` SET `tax_calculate_override` = 'no' WHERE `exempt_tax` = 1;
UPDATE `payroll_run_employee_exemptions` SET `sso_calculate_override` = 'no' WHERE `exempt_sso` = 1;
