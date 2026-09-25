# #syncNotParticipantBanner + modal (B1, 2026-09-23)

- แยกกล่องจาก `#syncMissingEmployeesBanner` (คนละ field: `in_sync_not_participant` vs `data`) ใน
  `.rd-run-banners` เดียวกัน, gate เดิม (`sync_process_id && state==='draft'`) แต่ **ไม่ gate ด้วย
  `run_purpose`** — ใช้ response เดียวกับ `loadSyncMissingEmployeesBanner()` ไม่ยิง request เพิ่ม
- **Tone: `neutral`, ไม่ใช่ `info`** — ที่ปรึกษาเขียน "info" ผิด, `docs/design/rules.md` §15 มีแค่
  `primary|success|warning|danger|neutral` จริงๆ (`callout.php`/`style.css` ยืนยันตรงกัน ไม่มี
  `.callout-info` เลย) ยืนยันกับผู้ใช้แล้วเลือก `neutral`: เป็นข้อมูลแจ้งให้รู้ ไม่ใช่ปัญหาเร่งด่วน และไม่ให้
  ซ้อนความหมาย `warning` เดียวกับกล่องพี่น้องด้านบน
- Modal เป็น record-only ตาม §9 (`#auditLogDetailModal` เป็นต้นแบบ) — footer มีแค่ `[ปิด]`,
  1 แถวต่อคน (รหัส + ชื่อตามภาษา, `employeeDisplayNameRd()`) ลิงก์ไปหน้าพนักงานเปิดแท็บใหม่
  (`target=_blank rel=noopener`)
- สลับภาษาระหว่างเปิด modal ค้างอยู่ต้องเปลี่ยนตาม — ไม่มีกลไกสำเร็จรูปให้ใช้ซ้ำ (`#syncMissingEmployeesBanner`
  เองไม่เคย re-render ตอนสลับภาษาเลย เป็นช่องว่างเดิมที่ไม่แก้เพราะต้อง diff 0) จึงเพิ่ม
  `syncNotParticipantList` (cache) + `refreshSyncNotParticipantLanguage()` ใหม่ ตามแบบ
  `auditLogDetailEntryRd`/`renderAuditLogDetailModalBody()`
