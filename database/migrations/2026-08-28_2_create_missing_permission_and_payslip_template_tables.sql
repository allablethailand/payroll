-- Fixes 2 more confirmed production 500s (from the real Apache/PHP error log):
--   PDOException: Table 'payroll.role_permissions' doesn't exist  (PermissionModel::matrix(),
--     /setup/company-profile)
--   PDOException: Table 'payroll.payslip_templates' doesn't exist  (PayslipTemplateModel::listPaired(),
--     /payslip-documents/settings)
--
-- Every CREATE TABLE below is pulled via SHOW CREATE TABLE directly off the current dev database
-- (not hand-reconstructed from scattered ALTER statements across payroll.sql's history) -- this
-- reflects the true final shape after every round of changes (Payslip Template alone went through
-- 7+ rounds of schema tweaks), so nothing is missed. Seed data (`permissions`, 18 rows;
-- `master_payslip_field_types`, 22 rows) is likewise a live dump, not retyped by hand.
--
-- Dependency order: permissions -> role_permissions (needs permissions+structure_roles) ->
-- master_payslip_field_types (standalone) -> payslip_images (needs companies) ->
-- payslip_templates (needs companies) -> payslip_template_elements (needs payslip_templates+
-- payslip_images) -> payslip_template_assignments (needs payslip_templates).
--
-- NOTE: this almost certainly is not the complete list of what's missing on production --
-- `role_permissions` missing means the whole RBAC feature never made it, and `payslip_templates`
-- missing means the whole canvas-designer feature never made it either, which strongly suggests
-- production's schema was frozen well before a large amount of this project's later work. A full
-- `SHOW TABLES` diff against database/payroll.sql's ~88 tables is still the right follow-up to
-- find everything at once rather than continuing to chase one 500 at a time.

CREATE TABLE IF NOT EXISTS `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `permission_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'module_code.action_code e.g. holiday.manage',
  `name_th` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permission_key` (`permission_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

INSERT INTO `permissions` (`module_code`,`action_code`,`permission_key`,`name_th`,`name_en`,`is_active`,`sort_order`) VALUES
('holiday','view','holiday.view','ดูวันหยุด','View Holidays','1','10'),
('holiday','manage','holiday.manage','จัดการวันหยุด','Manage Holidays','1','20'),
('leave_type','view','leave_type.view','ดูประเภทการลา','View Leave Types','1','30'),
('leave_type','manage','leave_type.manage','จัดการประเภทการลา','Manage Leave Types','1','40'),
('approval_workflow','view','approval_workflow.view','ดูลำดับผู้อนุมัติ','View Approval Workflows','1','50'),
('approval_workflow','manage','approval_workflow.manage','จัดการลำดับผู้อนุมัติ','Manage Approval Workflows','1','60'),
('approval_request','act','approval_request.act','อนุมัติ/ปฏิเสธคำขอ','Act on Approval Requests','1','70'),
('rbac','manage','rbac.manage','จัดการสิทธิ์การใช้งาน','Manage Roles & Permissions','1','80'),
('employee','view','employee.view','ดูข้อมูลพนักงาน','View Employees','1','90'),
('employee','manage','employee.manage','จัดการข้อมูลพนักงาน','Manage Employees','1','100'),
('company_structure','view','company_structure.view','ดูโครงสร้างองค์กร','View Company Structure','1','110'),
('company_structure','manage','company_structure.manage','จัดการโครงสร้างองค์กร','Manage Company Structure','1','120'),
('bank_account','manage','bank_account.manage','จัดการบัญชีธนาคารบริษัท','Manage Company Bank Accounts','1','130'),
('payslip_template','manage','payslip_template.manage','จัดการเทมเพลตสลิปเงินเดือน','Manage Payslip Templates','1','140'),
('payroll_configuration','manage','payroll_configuration.manage','จัดการการตั้งค่าระบบเงินเดือน','Manage Payroll Configuration','1','150'),
('tax_statutory','manage','tax_statutory.manage','จัดการภาษีและกองทุนตามกฎหมาย','Manage Tax & Statutory Settings','1','160'),
('company_profile','manage','company_profile.manage','จัดการข้อมูลบริษัท','Manage Company Profile','1','170'),
('employment_certificate_template','manage','employment_certificate_template.manage','จัดการเทมเพลตหนังสือรับรองการทำงาน','Manage Employment Certificate Templates','1','150');

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `allow_scope` enum('all','own_department') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'all',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_role_permission` (`role_id`,`permission_id`),
  KEY `idx_rp_permission` (`permission_id`),
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `structure_roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `master_payslip_field_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_th` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name_en` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `field_group` enum('employee_info','company_info','earning','deduction','statutory','summary','document') COLLATE utf8mb4_unicode_ci NOT NULL,
  `element_type` enum('text','image') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payslip_field_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

INSERT INTO `master_payslip_field_types` (`code`,`name_th`,`name_en`,`field_group`,`element_type`,`is_active`,`sort_order`) VALUES
('employee_no','รหัสพนักงาน','Employee No.','employee_info','text','1','10'),
('employee_name','ชื่อ-นามสกุล','Employee Name','employee_info','text','1','20'),
('department','แผนก','Department','employee_info','text','1','30'),
('position','ตำแหน่ง','Position','employee_info','text','1','40'),
('pay_period','งวดการจ่าย','Pay Period','employee_info','text','1','50'),
('payment_date','วันที่จ่าย','Payment Date','employee_info','text','1','60'),
('bank_account_masked','เลขบัญชีธนาคาร (4 ตัวท้าย)','Bank Account (last 4 digits)','employee_info','text','1','70'),
('company_name','ชื่อบริษัท','Company Name','company_info','text','1','80'),
('company_address','ที่อยู่บริษัท','Company Address','company_info','text','1','90'),
('company_tax_id','เลขประจำตัวผู้เสียภาษีบริษัท','Company Tax ID','company_info','text','1','100'),
('company_signatory','ผู้มีอำนาจลงนาม','Authorized Signatory','company_info','text','1','110'),
('company_logo','โลโก้บริษัท','Company Logo','company_info','image','1','120'),
('basic_salary','เงินเดือนพื้นฐาน','Basic Salary','earning','text','1','130'),
('earning_lines_all','รายการเงินได้ทั้งหมด (OT, ค่าเที่ยว ฯลฯ)','All Earning Line Items (OT, Trip Allowance, etc.)','earning','text','1','140'),
('deduction_lines_all','รายการเงินหักทั้งหมด','All Deduction Line Items','deduction','text','1','150'),
('statutory_lines_all','รายการหักตามกฎหมาย (SSO/ภาษี/กองทุน)','All Statutory Deductions (SSO/Tax/Fund)','statutory','text','1','160'),
('gross_amount','รายได้รวม','Gross Amount','summary','text','1','170'),
('total_deduction_amount','หักรวม','Total Deduction','summary','text','1','180'),
('net_amount','ยอดจ่ายสุทธิ','Net Amount','summary','text','1','190'),
('ytd_summary','ยอดสะสมทั้งปี (YTD)','Year-to-Date Summary','summary','text','1','200'),
('static_text','ข้อความ/ย่อหน้าอิสระ','Free Text / Paragraph','document','text','1','5'),
('company_signature','ลายเซ็นผู้มีอำนาจลงนาม','Authorized Signature','company_info','image','1','125');

CREATE TABLE IF NOT EXISTS `payslip_images` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL,
  `file_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_filename` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pimg_comp` (`comp_id`),
  CONSTRAINT `fk_pimg_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `payslip_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `comp_id` int(11) NOT NULL COMMENT 'ID บริษัทที่ล็อกอิน',
  `country_code` varchar(2) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ต้องตรงกับ companies.registered_country ของบริษัทนี้ เช็คที่ application layer',
  `template_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `language` enum('th','en') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'th',
  `pair_key` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'ใช้ template นี้อัตโนมัติตอน generate PAY_SLIP ถ้าไม่ระบุ ต้องมีได้แค่ 1 active default ต่อบริษัท เช็คที่ application layer',
  `logo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'เก็บที่ template ไม่ใช่ companies เพราะ companies ไม่มีคอลัมน์ logo',
  `header_text_th` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `header_text_en` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `footer_text_th` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `footer_text_en` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `page_size` enum('A4','Letter','Legal') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'A4',
  `orientation` enum('portrait','landscape') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'portrait',
  `margin_mm` decimal(5,2) NOT NULL DEFAULT 15.00,
  `status` enum('active','inactive','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `publish_status` enum('draft','public') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `auto_save` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `deleted_by` int(11) DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_payslip_templates_tenant` (`comp_id`,`deleted_at`,`status`),
  KEY `idx_pst_pair_key` (`comp_id`,`pair_key`),
  CONSTRAINT `fk_payslip_templates_company` FOREIGN KEY (`comp_id`) REFERENCES `companies` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `payslip_template_elements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `element_type` enum('text','image','shape','table') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text',
  `field_key` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'references master_payslip_field_types.code for image elements (company_logo) or the shape type for shape elements -- NULL for text/table (content carries the string/tokens/JSON instead)',
  `image_asset_id` int(11) DEFAULT NULL COMMENT 'references payslip_images.id -- a custom uploaded image element, distinct from field_key=company_logo which uses the template''s own logo_path instead',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT 'text elements: literal text, may embed {{field_key}} tokens. table elements: JSON grid.',
  `pos_x_pct` decimal(6,3) NOT NULL DEFAULT 0.000,
  `pos_y_pct` decimal(6,3) NOT NULL DEFAULT 0.000,
  `width_pct` decimal(6,3) NOT NULL DEFAULT 20.000,
  `height_pct` decimal(6,3) NOT NULL DEFAULT 5.000,
  `font_size` int(11) NOT NULL DEFAULT 14,
  `font_family` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'th_sarabun_new',
  `font_color` varchar(7) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#000000',
  `text_align` enum('left','center','right') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'left',
  `font_weight` enum('normal','bold') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `font_style` enum('normal','italic') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `text_decoration` enum('none','underline') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `group_key` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `page_number` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_pte_template` (`template_id`),
  KEY `fk_pte_image_asset` (`image_asset_id`),
  CONSTRAINT `fk_pte_image_asset` FOREIGN KEY (`image_asset_id`) REFERENCES `payslip_images` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pte_template` FOREIGN KEY (`template_id`) REFERENCES `payslip_templates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `payslip_template_assignments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL,
  `scope_type` enum('department','team','employee') COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pta_template` (`template_id`),
  KEY `idx_pta_scope` (`scope_type`,`scope_id`),
  CONSTRAINT `fk_pta_template` FOREIGN KEY (`template_id`) REFERENCES `payslip_templates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
