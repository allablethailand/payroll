-- 2026-09-02, explicit request: "ประเภทการจ่ายเงินเดือน...transfer/cash/check/mixed" -- a closed set
-- that may grow later (user's own prompt floated a possible future `bank_deposit_to_others` type,
-- deferred for now, no real use case yet) without needing a code deploy, per this project's own
-- master-table convention (see `master_bank_file_formats`/`master_payroll_source_events`, same
-- shape: id/code/name_th/name_en/is_active/sort_order). Global, not comp_id-scoped -- every company
-- shares the same set of payment METHODS (same as bank file formats/source events), only whether a
-- given method is actually usable per employee/cycle is company-specific, handled at the FK level
-- on `employees`/`payroll_cycles`, not here.

START TRANSACTION;

CREATE TABLE `master_payment_methods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_th` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_master_payment_methods_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

INSERT INTO `master_payment_methods` (`code`, `name_th`, `name_en`, `is_active`, `sort_order`) VALUES
('transfer', 'โอนเข้าบัญชีธนาคาร', 'Bank Transfer', 1, 10),
('cash', 'เงินสด', 'Cash', 1, 20),
('check', 'เช็ค', 'Check', 1, 30),
('mixed', 'จ่ายแบบผสม', 'Mixed', 1, 40);

COMMIT;

-- Rollback:
-- DROP TABLE IF EXISTS `master_payment_methods`;
