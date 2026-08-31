-- 2026-08-30_13_attendance_deduction_no_deduction_method.sql
-- T015 (Phase 2, payroll-configuration cleanup): "หักตามข้อมูลการเข้างาน: เพิ่มตัวเลือก 'ไม่หัก'" --
-- a 4th master_attendance_deduction_methods option so an admin can explicitly configure a
-- (company/team/department, event) combination to compute ZERO deduction, distinct from simply not
-- configuring a rule at all (which falls back to percent_of_rate @ 1.00x, the system default) and
-- distinct from an is_active=0/exemption row (which still computes a "would-have-been" amount for
-- the Process Detail breakdown modal to show as "not applied" -- see AttendanceDeductionRuleModel's
-- own docblock). "ไม่หัก" is a genuine 4th CALCULATION METHOD: always zero, no matter how many
-- minutes/days the employee was late/absent, with no rate/formula to configure at all.
-- sort_order=40 (after the existing 3, which run 10/20/30) -- keeps percent_of_rate's existing
-- visual position as the first/default-looking option in the dropdown unchanged.
INSERT INTO `master_attendance_deduction_methods` (`code`, `name_th`, `name_en`, `is_active`, `sort_order`)
VALUES ('no_deduction', 'ไม่หัก', 'No Deduction', 1, 40);
