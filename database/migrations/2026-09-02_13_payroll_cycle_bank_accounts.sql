-- 2026-09-02, explicit request: "หน้านี้รองรับการเพิ่มมากกว่า 1 บัญชีธนาคารต่อ 1 รอบจ่ายเงินเดือนอยู่แล้ว
-- หรือไม่...ถ้ายังไม่มีให้เพิ่ม". Confirmed a real gap: `payroll_cycles.bank_account_id` (added
-- 2026-08-29) lets a cycle pin down exactly ONE account, nullable, falling back to the company's
-- own is_default=1 account. A cycle offering 2+ accounts (with exactly one flagged default) did
-- not exist. This junction table adds that; `payroll_cycles.bank_account_id` is KEPT as a
-- denormalized shortcut cache of this table's own default row (cheapest migration path -- every
-- existing reader of that column, e.g. PayrollRunEmployeeBankAccountModel::resolveForRun(), keeps
-- working unmodified) -- PayrollCycleModel::saveBankAccounts() is the ONLY write path responsible
-- for keeping the two in sync.

START TRANSACTION;

CREATE TABLE `payroll_cycle_bank_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cycle_id` int(11) NOT NULL,
  `bank_account_id` int(11) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pcba_cycle_account` (`cycle_id`,`bank_account_id`),
  KEY `idx_pcba_bank_account` (`bank_account_id`),
  CONSTRAINT `fk_pcba_cycle` FOREIGN KEY (`cycle_id`) REFERENCES `payroll_cycles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pcba_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

-- Backfill: every cycle that already pinned a single account becomes that account's is_default=1
-- row here, so behavior is byte-identical for every company that hasn't touched this feature yet.
INSERT INTO `payroll_cycle_bank_accounts` (`cycle_id`, `bank_account_id`, `is_default`, `sort_order`)
  SELECT `id`, `bank_account_id`, 1, 0 FROM `payroll_cycles` WHERE `bank_account_id` IS NOT NULL;

COMMIT;

-- Rollback:
-- DROP TABLE IF EXISTS `payroll_cycle_bank_accounts`;
