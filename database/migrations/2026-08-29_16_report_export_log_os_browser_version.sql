-- 2026-08-29, same-day follow-up: "ที่โชว์ในตารางประวัติการ Download มีเก็บครบหรือยังถ้ายังไม่ครบเก็บเพิ่มให้
-- ครบครับ" -- the original "Device อะไร...เบราเซอร์อะไร" request is answerable with device_type/
-- browser_name alone, but EmployeeLoginLogModel::parseUserAgent() (already reused for this table,
-- see the previous migration's own header comment) ALSO resolves os_name/browser_version for free
-- from the exact same User-Agent string already being parsed -- captured now so "Device"/"Browser"
-- can show a genuinely complete answer (e.g. "Desktop (Windows)" / "Chrome 119") instead of the
-- bare device_type/browser_name this table started with.
ALTER TABLE `report_export_logs`
    ADD COLUMN `os_name` VARCHAR(30) NULL AFTER `device_type`,
    ADD COLUMN `browser_version` VARCHAR(20) NULL AFTER `browser_name`;
