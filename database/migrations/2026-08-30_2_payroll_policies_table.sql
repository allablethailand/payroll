-- 2026-08-30, explicit request: "ตรงที่ปิดรอบไปแล้ว ต้องการเปิดกลับมาแก้ไข อยากให้มีการตั้งค่าได้ว่า
-- หลังจากปิดรอบต้องกี่วันถึงจะสามารถดึงกลับมาได้ เพิ่มอีก Tab เป็น Tab ตั้งค่าในหน้าตั้งค่าเงินเดือนเลย
-- เดี๋ยวมีอีกหลายหัวข้อครับ" -- new "Payroll Policies" tab in Payroll Configuration settings, starting
-- with a reopen-window policy. One row per company (singleton, same shape as
-- company_statutory_settings' own per-company row pattern but without a per-item key since this
-- table holds heterogeneous, purpose-specific settings added incrementally over time -- more
-- columns land here as later requests arrive, per the user's own "เดี๋ยวมีอีกหลายหัวข้อครับ").
--
-- reopen_window_days: NULL = unlimited (no time restriction on reopening a paid/locked run --
-- PayrollRunModel::reopen()'s own pre-existing behavior before this feature, kept as the default
-- so a company that never visits this tab sees zero behavior change). A positive integer caps how
-- many days after the run's own closing timestamp (locked_at if it was locked, else paid_at) a
-- reopen is still allowed -- see PayrollRunModel::reopen()'s own docblock for the exact check.
CREATE TABLE `company_payroll_policies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `comp_id` INT NOT NULL,
  `reopen_window_days` INT UNSIGNED NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` INT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_company_payroll_policies_comp` (`comp_id`),
  CONSTRAINT `fk_company_payroll_policies_comp` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_company_payroll_policies_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
