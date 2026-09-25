# 4c — หน้าพนักงานเลิกมีคำศัพท์/คอนโทรลของตัวเอง (2026-09-19)

**ปัญหา**: หน้า Employee Detail กับสลิปพูดถึงของชิ้นเดียวกันคนละภาษา — ตาราง EED พิมพ์ `item_code` ใต้ชื่อ, บรรทัดปลายทางเขียนเอง 4 กิ่ง + ไอคอนที่บอกซ้ำกับข้อความข้างหลัง, ฟอร์มมี segmented 3 โหมดที่สองอันหลังฟิลด์เหมือนกันเป๊ะต่างแค่ flag `is_other`, picker ปลายทางถาม 2 ชั้นทั้งที่ชั้นแรกอ่านได้จากชั้นสอง

| เดิม | ใหม่ |
|---|---|
| segmented 3 โหมดเหนือ picker | ตัวเลือกปักท้ายใน picker เอง (`manual_line_item_custom_option` → "ระบุชื่อเอง", มีผลกับสลิปด้วย) + checkbox `is_other` ใต้ช่องชื่อ |
| `{p}PayeeRecordWrap` "ไม่บันทึก/บันทึก" (4 caller) | select บัญชีบริษัท allowClear: ว่าง = `payee_type` NULL, เลือก = `'company'`; คำว่า "ว่าง" แปลว่าอะไรอยู่ที่ placeholder (`data-placeholder-key`) |
| toggle ไม่มี/คิดค่าธรรมเนียม ของ erd | ช่อง % ว่าง = ไม่มีค่าธรรมเนียม (ตารางนี้ไม่มี `interest_type`, `fee_base` ค่าเดียว — #eedModal เป็น enum จริง **ไม่แตะ**) |
| descriptor อยู่ใน `payroll/detail.js` | ย้าย verbatim → `public/js/payee-descriptor.js` โหลดก่อน `app.js` (รวม `pinRowOptionRd`/`payeeRowPinnedOptionsRd`/`renderPayee*DetailRd` รอบ 2) |
| prefill eed/erd ประกอบ label เอง (`"CEO"` / `"ชื่อ สกุล (CEO)"`) ไม่มีการ์ดบัญชี | pin option ด้วย label จาก endpoint เดียวกับ picker + การ์ดบัญชีใต้ select ทั้ง 3 picker เหมือนสลิปเป๊ะ · `get()` ของ 2 model คืน `payee` ด้วย (read-only, additive) |
| cell ชื่อพิมพ์ code + ไอคอนต่อชนิดผู้รับ | ชื่อ + tag descriptor กลาง; code **ค้นได้**ผ่าน `render.filter` แต่ไม่แสดง |
| ตารางบอกแค่ "แผนคืออะไร" | กล่องสรุปเทียบ **วันนี้** (ยังไม่เริ่ม/ระงับ/จบแล้ว) — ห้ามใช้คำ "รอบนี้" หน้านี้ไม่มีงวดจ่าย |
| ลิงก์จากสลิป `/employees/{id}` | `/employees/{employee_no}#earningDeduction-tab` (route match ด้วย employee_no — เดิมชี้ผิดคน) |

**บั๊กจริงที่เจอระหว่างทาง**: (1) `<th data-i18n>` ตรงๆ 51 จุด ผิดกฎ DataTables ของ CLAUDE.md → หัวตารางค้างอังกฤษ
(2) `_refreshAllDataTablesLanguageInner()` ไม่เคย copy `sEmptyTable` + `langData` โหลด async → empty state ค้างอังกฤษตลอดกาล
(3) **RangeError** (รอบแก้ 1): บัญชีบริษัทได้ binding `change`→`syncPayeeDestination()` แต่ onChange ทุก caller ยังเคลียร์ select ตัวเดียวกันด้วย `.trigger('change')` → เคลียร์ = เรียกคนที่เพิ่งสั่งเคลียร์ (วัดได้ 21+ ชั้น) เห็นเฉพาะตอนเปลี่ยน segment ปลายทาง/เปิดแถวเก่า ไม่เห็นตอนโหลดเปล่า → guard reentrancy (`PAYEE_DEST_SYNCING`) + เลิกยิง event เคลียร์ช่องที่ว่างอยู่แล้ว
(4) `.btn-primary` contrast 2.14:1 ทั้งแอป — token ระดับ brand ลง BACKLOG

**ไม่แตะ**: ปุ่มกลม 5 ตัว/แถว (§7, BACKLOG), `#manualLineDestModeToggle`, ดอกเบี้ย/ค่าธรรมเนียมของ #eedModal, backend/DDL
**ทดสอบ**: `tests/ui/k4c_employee_detail.js` 4 cell (read-only บน EM009, block 5 write endpoint) + `payee_picker_options_test.php`
