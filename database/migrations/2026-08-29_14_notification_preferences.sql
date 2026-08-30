-- 2026-08-29, explicit follow-up request: "ทำ Notification Settings ก่อนเลยครับ -- ให้ user เลือกเปิด/ปิด
-- รับแจ้งเตือนได้เป็นราย category (5 ประเภทที่มีอยู่) ผูกกับ user preference ในระดับ role ได้ด้วยถ้าไม่ซับซ้อน
-- เกินไป" -- two preference layers, resolved in NotificationModel::shouldNotify() with this
-- priority: personal override > role-level default > enabled (today's behavior, unchanged when
-- neither table has a row for someone).

-- Personal, per-employee mute/unmute per notification `type` (the 5 types NotificationModel
-- actually creates -- see its own top-of-file docblock). No row = not personally overridden.
CREATE TABLE IF NOT EXISTS `employee_notification_preferences` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_by` INT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_emp_notif_pref` (`employee_id`, `type`),
    CONSTRAINT `fk_enp_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Role-level default, admin-managed (checkbox matrix, same replace-all convention as
-- role_permissions/PermissionModel::saveMatrix() -- no direct comp_id column, scoped via
-- role_id -> structure_roles.comp_id, same precedent as role_permissions itself). Only consulted
-- for an employee with NO personal override row above for that type.
CREATE TABLE IF NOT EXISTS `role_notification_preferences` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `role_id` INT NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_by` INT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_role_notif_pref` (`role_id`, `type`),
    CONSTRAINT `fk_rnp_role` FOREIGN KEY (`role_id`) REFERENCES `structure_roles`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
