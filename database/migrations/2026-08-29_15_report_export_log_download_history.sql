-- 2026-08-29, explicit request: "มีอีกปุ่มเพื่อกดดูประวัติการ Download Download วันไหนเวลาไหน โดยใคร
-- Device อะไร IP อะไร เบราเซอร์อะไร และ Download จากที่ไหน" -- widens the EXISTING report_export_logs
-- table (already tracks who/when/report/format/run) with the same shape ReportsController::generate()
-- will now capture on every real (non-preview) download: IP + parsed device/browser (reusing
-- EmployeeLoginLogModel::parseUserAgent(), same convention employee_login_logs already established
-- for this exact kind of data -- not reinvented here), the report's own language (th/en), and
-- `source` (which page/screen the download was triggered from -- Process Detail's own new "Reports"
-- tab is the first caller, but this stays generic for any future caller).
ALTER TABLE `report_export_logs`
    ADD COLUMN `ip_address` VARCHAR(45) NULL AFTER `generated_by`,
    ADD COLUMN `device_type` VARCHAR(20) NULL AFTER `ip_address`,
    ADD COLUMN `browser_name` VARCHAR(50) NULL AFTER `device_type`,
    ADD COLUMN `user_agent` VARCHAR(500) NULL AFTER `browser_name`,
    ADD COLUMN `language` VARCHAR(5) NULL AFTER `user_agent`,
    ADD COLUMN `source` VARCHAR(30) NULL AFTER `language`;
