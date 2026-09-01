-- Explicit request: add an "Assign Employees" modal to every master/structure page an employee can
-- be linked to (Department/Position/Team/Branch/Role + Shift/Work Location + Rank). Every OTHER
-- assignable type already has a real FK column on `employees` (department_id/position_id/role_id/
-- branch_id/team_id/shift_id/work_location_id) -- `structure_ranks` has existed as a master table
-- since Organizational Structure's own early phase, but was NEVER actually linked to `employees` at
-- all (confirmed via `DESCRIBE employees` -- no rank_id column anywhere in the schema). This is the
-- one real schema gap this batch needs to close before Rank can be assigned to anyone.
ALTER TABLE `employees`
  ADD COLUMN `rank_id` INT(11) NULL DEFAULT NULL AFTER `position_id`,
  ADD CONSTRAINT `fk_employees_rank` FOREIGN KEY (`rank_id`) REFERENCES `structure_ranks` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
