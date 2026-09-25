# n (2026-09-22) — ดึงพนักงานที่ไม่อยู่ใน Sync เข้ารอบ (UI)

ต่อจาก `2026-09-22-tiny1-sync-missing-criteria.md` (เกณฑ์ + `missing_only` + รูปพนักงาน ฝั่ง backend)

## ที่เปลี่ยน
ปุ่ม "ดูรายชื่อ" บน `#syncMissingEmployeesBanner` เคยเปิด Swal รายการอ่านอย่างเดียวแล้วจบ — เห็นว่าใครตก
หล่นกับทำอะไรกับเรื่องนั้นเป็นคนละหน้าจอ และหน้าที่สอง (Join Employees) เสนอทั้งบริษัทโดยไม่มีทางกรองเหลือ
เฉพาะคนที่ตกหล่น ตอนนี้ปุ่มเดิมเปิด **picker ตัวเดิม** ในโหมด `missing` แทน (`joinEmployeesMode`)

## ทำไมเป็น modal เดิม ไม่ใช่ modal ใหม่
รายการเดียวกัน ต่างกันแค่ WHERE ตัวเดียว (`buildManualEmployeeWhere()` ฝั่ง server) — modal ที่สองจะได้
paging/filter/select-all/ปุ่มยิง endpoint เดียวกันซ้ำทั้งชุด (กฎ "ซ้ำ = shared", `rules.md` §0.4) กติกาที่
ได้จากรอบนี้เขียนไว้ที่ `rules.md` §9 "modal ตัวเดียว 2 โหมด" แล้ว

## จุดที่เป็นกับดักจริง (เจอตอนอ่านรอบ A ไม่ใช่เดา)
- `updateText()` อ่าน `data-i18n` สดทุกครั้ง → สลับ title ด้วย `.attr()` ปลอดภัย แต่ป้ายที่เป็น template
  `{count}` ต้อง **ถอด** marker ออก ไม่งั้นสลับภาษาแล้วได้ `{count}` ดิบบนปุ่ม
- DataTables อ่าน `language` ครั้งเดียวตอน construct → empty state ต่อโหมด/ต่อภาษาต้องผ่าน
  `dtRenderEmptyState()` ใน `drawCallback` (shared, §11) ไม่ใช่ `language.emptyTable`
- `#joinSelectedCount` ถูก `.text()` ทับตั้งแต่เปิดครั้งแรก ทำให้ `<span data-i18n>` ข้างในหายไป — เป็นบั๊ก
  ภาษาค้างที่มีอยู่เดิม ปิดไปด้วยในรอบนี้ผ่าน `refreshPayrollDetailLanguage()`

## ที่ตัดสินแล้ว ไม่ต้องถามซ้ำ
- skipped → `showWarning` **แทน** success (ไม่ซ้อนกัน) แต่ยังปิด modal + `loadRunDetail()` ตามปกติ
- ไม่เรียก `loadSyncMissingEmployeesBanner()` ซ้ำ — `loadRunDetail()` → `renderRunHeader()` เรียกให้แล้ว
- avatar ส่ง `employeeId: null` ทั้ง 2 โหมด (quick-view ซ้อน modal = เรื่องแยก ดู BACKLOG)
- ปุ่มแถวไม่มี confirm เหมือนปุ่ม footer เดิม — เพิ่มคนเข้ารอบ draft ถอนออกได้ในคลิกเดียว
