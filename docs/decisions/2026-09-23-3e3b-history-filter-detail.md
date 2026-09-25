# 3e-3b — Action History tab: date-range filter + view-detail modal (2026-09-23)
rules.md §6/§7/§9 · `#auditLogFilterBar`/`#auditLogDetailModal` (`payroll/detail.js`)

**ช่วงวันที่เป็น `.filter-bar` (§6, ตัวเดียวกับ `#runDetailFilterBar`) — ผ่าน 4 รอบก่อนจะถูก**: B1/B2
`.station-filter` (reports/index.php, ไม่มี rules.md pattern เลย), B3 plain flex row (อ่าน §6 ผิดว่าไม่มี
box ระบุไว้), B4 ใช้ `.filter-bar` จริงแต่เสริม visibility เองใน detail.js (`initFilterBar()`'s เอง
`clearAllFields()`/`refresh()` ตอนนั้นเช็คแค่ `<select>`) — **B5 แก้ที่ root cause จริง**: ขยาย
`initFilterBar()` (app.js:1312-1563) ให้รองรับ `input.form-control` ด้วยกติกาเดียวกับ select
(active/count/chip/ล้าง) โค้ดสาขา select เดิม 0 บรรทัดเปลี่ยน (แค่แตกสาขาเพิ่ม) — ฟิลด์ยังเป็น bare
`.form-control.datepicker` เหมือนเดิม (ไม่มี select, ผู้ทำ/การกระทำ/สถานะ กรองผ่าน column-header
checklist ของ §7 อยู่แล้ว) — detail.js ไม่ต้องมี handler เสริมเองอีกต่อไปเลย ลบหมด

**Predicate เทียบ string ล้วน ไม่ผ่าน `Date` parse** — `performed_at` เป็น string ดิบจาก MySQL ไม่มี
timezone (Batch 5 lesson เดิม) → เทียบ `performed_at.slice(0,10)` กับ `toIsoDateRd()` ของช่องกรอกตรงๆ

**จาก > ถึง = callout เตือน + ไม่กรอง** จนกว่าจะแก้ให้ถูก (ไม่ swap/ล้างให้เอง) — validity เช็คจากจุดเดียว
(`auditLogDateRangeValidRd()`) ทั้งใน predicate และก่อนโชว์ callout กันสองจุดพูดคนละอย่าง

**"ล้างตัวกรอง" ใช้ปุ่ม `.filter-bar-clear` ของ shared component ตัวเดียว ไม่มีปุ่ม/handler เสริมของหน้าอีก
เลย (B5)** — ทั้ง `.filter-bar-clear` เอง, chip's × , และ `dtRenderEmptyState()`'s auto "ล้างตัวกรอง"
(empty-state) ล้าง `<input>` ได้ตรงจาก `clearAllTableFilters()`/`clearAllFields()` เดิมทั้งหมด

**Tooltip หมายเหตุถอดออก** — ทางเข้าดูข้อความเต็มทางเดียวคือ modal ใหม่ (`openAuditLogDetailRd`), `data-full-note` เดิมถอดตาม (grep consumer=0)

**ปิด BACKLOG "`#tb_run_audit_log` ไม่แสดง `from_state`"** (3e-3 item 2) — modal แสดง `from → to` เมื่อ
2 ค่าต่างกันแล้ว ตารางเองยังคง `to_state` อย่างเดียวตามเดิม (ตัดสินใจรอบ A)
