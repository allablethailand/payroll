-- 2026-09-04, Backlog Phase 10, T055: "build a generic, reusable 'Assign' card/form template --
-- Department/Position/Team/individual employee, one consistent pattern reusable everywhere." User
-- confirmed: "เริ่ม T055 เลยครับ" (go ahead and build it).
--
-- WHY one shared table, not a 4th per-feature copy: this exact "assign to department/team/employee"
-- shape has already been independently reinvented 3 times this session, each with its own dedicated
-- table -- holiday_assignments (shift/department/position/employee, include/exclude modes),
-- payslip_template_assignments/employment_certificate_template_assignments (department/team/employee,
-- simpler "zero rows = unscoped" semantics), and ot_rate_set_assignments (department/team/position/
-- employee, the closest precedent to this one -- see OtRateSetModel's own SCOPE_TABLES/scopeLabel()/
-- validScopeRef()/resolveRatesForEmployees(), which this table's own model generalizes directly).
-- T055 exists specifically to stop this pattern from being reinvented a 5th/6th/7th time -- ONE
-- generic, feature-agnostic table any future feature can attach to via its own `entity_type` code
-- string, instead of a new migration + new assignment table every time a feature needs "assign to
-- department/position/team/employee".
--
-- Semantics (deliberately the SIMPLER of the two precedents above, matching Payslip/ECT Template's
-- own convention, NOT Holiday's richer include/exclude -- T055's own task text never asked for an
-- exclude mode, so this stays genuinely generic rather than carrying a feature none of its first
-- consumers need): ZERO assignment rows for a given (entity_type, entity_id) pair means UNSCOPED --
-- applies to everyone. Any rows present means it applies ONLY to the union of those scopes. Priority
-- when resolving "does this apply to employee X" and more than one scope type matches: employee > team
-- > position > department (same "most specific wins" convention already established by
-- OtRateSetModel::resolveRatesForEmployees()/Holiday's own employee > position > department > shift/
-- Payslip+ECT Template's own employee > team > department) -- see EntityAssignmentModel::
-- resolveForEmployee()'s own docblock for the full union-match implementation.
--
-- `entity_type` is an opaque varchar code the CONSUMING feature defines for itself (e.g.
-- 'payroll_earning_deduction_type' for T054, 'probation_policy' for T056) -- intentionally NOT an FK
-- to any single table, since this one table serves every future feature generically. `entity_id` is
-- that feature's own owning row's id in whatever table its entity_type names. `scope_id` is
-- polymorphic (structure_departments/structure_positions/structure_teams/employees depending on
-- scope_type) -- validated at the APPLICATION layer only (EntityAssignmentModel::validScopeRef()),
-- same convention as every other polymorphic scope_id in this codebase (no DB-level FK is possible
-- across 4 different target tables).
--
-- comp_id FK ON DELETE RESTRICT ON UPDATE CASCADE per this project's own hard convention (CLAUDE.md)
-- for every company-scoped table.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-04_4_entity_assignments.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

CREATE TABLE `entity_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `entity_type` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Opaque feature code, e.g. payroll_earning_deduction_type / probation_policy -- not an FK, generic across every future consumer',
  `entity_id` int(11) NOT NULL COMMENT 'The owning row''s id in whatever table entity_type names',
  `scope_type` enum('department','position','team','employee') COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_id` int(11) NOT NULL COMMENT 'Polymorphic -- structure_departments/structure_positions/structure_teams/employees depending on scope_type, app-layer validated only',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_entity_assignment` (`comp_id`,`entity_type`,`entity_id`,`scope_type`,`scope_id`),
  KEY `idx_entity_assignments_owner` (`comp_id`,`entity_type`,`entity_id`),
  CONSTRAINT `fk_entity_assignments_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
