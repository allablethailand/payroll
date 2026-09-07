-- 2026-09-05, Phase 12 T071 -- the 2026-08-29 seed rows for TH_PND1/TH_SSO110's 'v1_current'
-- version were marked `is_verified = 0` ("ยังไม่ยืนยันกับกรมสรรพากรอย่างเป็นทางการ" / "not yet
-- officially verified") because the field layout at the time was reconstructed from a structural
-- description with no byte sample to check against. T071 replaced both PndOneExporter/
-- Sso110Exporter against a real, self-consistent reference spec the user supplied (PDF/xlsx spec
-- sheets + a PHP reference implementation + 2 byte-exact TIS-620 samples, cross-checked via
-- `iconv` decoding real Thai names back out of the samples) -- adopted wholesale per explicit
-- confirmation (AskUserQuestion, "ยึด spec ใหม่ทั้งหมด (แนะนำ)"). Flips `is_verified` to reflect
-- that; refreshes the Thai/English labels to drop the now-stale "not yet verified" wording.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-05_2_statutory_format_versions_confirmed.sql

UPDATE `master_statutory_format_versions`
    SET `is_verified` = 1,
        `name_th` = 'รูปแบบปัจจุบันในระบบ (ยืนยันตามข้อมูลอ้างอิงที่ได้รับ)',
        `name_en` = 'Current System Implementation (Confirmed Against Supplied Reference Materials)'
    WHERE `form_code` = 'TH_PND1' AND `version_code` = 'v1_current';

UPDATE `master_statutory_format_versions`
    SET `is_verified` = 1,
        `name_th` = 'รูปแบบปัจจุบันในระบบ (ยืนยันตามข้อมูลอ้างอิงที่ได้รับ)',
        `name_en` = 'Current System Implementation (Confirmed Against Supplied Reference Materials)'
    WHERE `form_code` = 'TH_SSO110' AND `version_code` = 'v1_current';
