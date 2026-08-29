-- 2026-08-29, explicit request: "ในหน้าตั้งค่าพวกภาษี ให้มีการกำหนดเพิ่มได้ว่าปัดเศษ หรือไม่ปัด
-- ถ้าปัดปัดแบบไหน และทศนิยมได้กี่ตำแหน่ง แล้วตอนคำนวณให้นำไปใช้ด้วย"
-- Per-statutory-item rounding configuration, applied by StatutoryCalculationEngine at calc time
-- instead of the previous hardcoded round($x, 2) throughout.
--   rounding_mode: 'round' = standard round-half-up (PHP round() default, unchanged behavior for
--                  every existing row via the DEFAULT below); 'up' = always ceiling; 'down' = always
--                  floor/truncate-toward-more-negative; 'none' = truncate toward zero to
--                  decimal_places with NO rounding adjustment at all ("ไม่ปัด").
--   decimal_places: 0-4, default 2 (matches the previous hardcoded behavior exactly).
ALTER TABLE `statutory_items`
  ADD COLUMN `rounding_mode` ENUM('round','up','down','none') NOT NULL DEFAULT 'round' AFTER `calc_base`,
  ADD COLUMN `decimal_places` TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER `rounding_mode`;
