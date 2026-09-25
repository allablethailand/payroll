# 3e-1 — Payroll Detail: แท็บนอกสลิปใช้ของกลาง (2026-09-20)

5 แท็บนอกสลิปย้ายมาใช้ shared component + เคลียร์ lint §12 เฉพาะรายการที่ระบุ ไม่แตะพฤติกรรมตาม state

- **"ยังไม่พร้อม" 2 แบบ** — Cash/Bank/Remittance ซ่อนเนื้อหาทั้งก้อน → empty-state; Reports ยังโชว์
  ตารางครบแถว (ปุ่ม `disabled`) → **callout `neutral`** เพราะ empty-state จะโกหกว่าไม่มีข้อมูล (กฎใหม่ §6)
- **badge**: สถานะจริง → `statusBadgeHtml()` (context ใหม่ `cash_payment_status` paid=success/unpaid=
  **neutral** · `remittance_status` มีอยู่แล้ว ผล `transferred` ฟ้า→warning §3) · ที่ไม่ใช่สถานะ
  (Source, chip รายการยกเว้น) → ข้อความธรรมดา §5
- **shared partial ได้ field optional 6 ตัว** (`stat-card.php` `value_id`/`sub_id` ตามแผนรอบ 4 ที่ §2 เขียน
  ไว้เป๊ะ + `label_i18n` · `empty-state.php` `title_i18n`/`text_i18n`/`text_id`) — consumer เดิม 9 shape
  render byte-identical (วัด `git show HEAD:` เทียบ working copy)
- **`.tab-pane` ไม่มี padding ของตัวเองแล้วทั้ง 7 pane** (ของเดิมเป็น stopgap ที่ 3b เขียนว่า "จะจัดจริงใน
  3e") — 2 ชั้น: padding ของ pane + gutter ของ `.row` ที่ datatables.net-bs5 สร้างเอง
- **tile รายงานเหลือเทาเดียว** — 3 สีต่อ type (purple นอก palette + hex ดิบ 2) ถูกถอด ไอคอนคงไว้ (§0.1)
- **banner ระดับรอบ 4 กล่องเป็น callout** (validation / sync-missing / merge-target / merge-waiting) —
  `.alert` เหนือแถบ tab เหลือ 0 · `$text` ของ callout เป็น HTML ดิบอยู่แล้ว แถวปุ่มจึงอยู่ข้างในได้
- **DB ไม่มีข้อมูลให้พิสูจน์ 3 ตาราง** (remittance 0 แถวทั้งฐาน, bank assignment 0, cash เกิน
  `searchThreshold` มีแต่ในรอบที่ถูกลบ) → cell 9–11,13 ใช้ `route.fulfill` mock ลอก shape จาก model จริง
  (file:line ในหัวไฟล์เทส) MEASURED ขึ้นต้น MOCK ไม่เขียน DB · c14 ใช้ run จริง
