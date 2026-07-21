# Origami Payroll — Project Guide

## Project
Origami Payroll — ระบบเงินเดือน multi-country (TH, SG, MY, US). โปรเจกต์ใหม่ทั้งหมด มีบาง feature สร้างไว้แล้วบางส่วน ที่เหลือให้ทำต่อทีละโมดูลตาม scan/roadmap ที่มีอยู่

## Stack
PHP 8.x, MySQL 8.x, Bootstrap 5, jQuery, SweetAlert2, CSS (custom, ไม่ใช้ CSS framework อื่นนอกจาก Bootstrap)

## Code Convention
- ทุก query ต้องใช้ PDO prepared statement เท่านั้น ห้าม concatenate ค่าที่มาจาก user input ลงใน SQL ตรงๆ
- Controller/Model ใหม่ทุกไฟล์ต้องมี `declare(strict_types=1);`
- ชื่อฟังก์ชันกลางสำหรับ query: `select_data` / `insert_data` / `update_data` (implementation ใช้ PDO ภายใน) — **ยังไม่ได้ implement จริงในโค้ดปัจจุบัน** โมเดลที่มีอยู่เรียก `$this->db->prepare()/execute()` ตรงๆ ในแต่ละ method หากจะสร้าง helper กลางนี้ ให้ทำเป็นงานแยกและ migrate ของเดิมทีหลัง อย่าผสมสองรูปแบบในไฟล์เดียวกัน
- Soft delete pattern: ตารางที่มีการลบใช้ `status enum('active','inactive','deleted')` + `deleted_at`/`deleted_by` ไม่ hard delete ข้อมูล payroll/master data
- Unique constraint ที่มี `deleted_at` ร่วมด้วยต้องเช็คซ้ำระดับ application เสมอ (MySQL ถือว่า NULL แต่ละแถวไม่ซ้ำกัน composite unique key จึงกันแถว active ซ้ำไม่ได้จริง)
- ตารางที่ scope ด้วยบริษัทต้องมี FK `comp_id → companies.id` (`ON DELETE RESTRICT ON UPDATE CASCADE`)
- รายการตัวเลือกแบบ fixed/closed set ที่ "อาจมีเพิ่มทีหลังโดยไม่อยากแก้โค้ด" (เช่น ประเภทเหตุการณ์ที่ผูกกับรายการ payroll, รูปแบบไฟล์ธนาคาร) ให้ทำเป็น master table (`id`, `code`, `name_th`, `name_en`, `is_active`, ...) แล้วดึงผ่าน Select2 โหมด `ajax` แทนการ hardcode enum/array ในโค้ด — เพิ่มตัวเลือกใหม่ทีหลังแค่ insert แถวใหม่ ไม่ต้อง deploy โค้ด ตัวอย่าง: `master_payroll_source_events` (ผูก dropdown "Linked Attendance Event" ในหน้า Payroll Configuration, มีคอลัมน์ `applies_to` กรองว่าใช้ได้กับ earning/deduction/both)

## UI Convention
- Design: glassmorphism, สีหลัก orange `#FF9900` + gold accent, รองรับ 2 ภาษา TH/EN แบบ dynamic (เปลี่ยนได้โดยไม่ reload หน้า ผ่าน language switcher มุมขวาบน)
- Alert/Confirm ทั้งหมดใช้ **SweetAlert2** เท่านั้น (`showSuccess`/`showWarning`/`showError`/`showConfirm` ใน `public/js/alert.js`) ห้ามใช้ Bootstrap modal หรือ native `alert()`/`confirm()` สำหรับแจ้งเตือน
- ปุ่มหลัก (Add/Save) ต้องเป็นสี brand orange `#FF9900` ไม่ใช่สี Bootstrap default

### Dropdown / Select
- ทุก dropdown ในระบบต้องใช้ **Select2** ห้ามใช้ `<select>` เปล่าไม่ init — ปล่อยเป็น plain select ครั้งเดียวก็แปลภาษาไม่ได้ (option โชว์เป็น i18n key ดิบๆ ทันทีที่ key ไม่ตรง เจอเคสจริงมาแล้วในหน้า Payroll Cycle)
- Initialize ผ่านฟังก์ชันกลาง `initSelect2(selector, options)` ใน `public/js/input.js` รองรับ 3 โหมด อย่าเขียน select2 init ซ้ำใหม่ในแต่ละหน้า:
  - `{ mode: 'ajax' }` (หรือใช้ alias `initSelect2Remote(selector)`) — โหลดจาก DB ผ่าน endpoint ที่คืนค่า `{status, data: {items:[{id, text_th, text_en}], total_count}}`
  - `{ mode: 'static' }` — ตัวเลือก hardcode ไม่ผ่าน AJAX อ่านจาก `data-option-keys="key1,key2,..."` บน `<select>` แล้ว map แต่ละ key ผ่าน `getLangValue(key)`; **ต้องเช็คว่าทุก key ที่ใส่มีอยู่จริงใน `public/lang/en.json`/`th.json` ทั้งคู่ก่อนใช้งาน** ไม่งั้น fallback เป็นการโชว์ key ดิบแทนข้อความแปล
    - **ค่าที่ submit ไปหลังบ้านคือ key ตรงๆ ไม่ใช่แค่ตัวช่วยหา text** — ถ้า backend ต้องการ enum ที่ไม่ตรงกับ i18n key ที่ใช้แสดงผล (เช่น key `calc_fixed_amount` ไว้กันชนกับ key อื่น แต่ backend ต้องการแค่ `fixed_amount`) **ต้องใส่ `data-option-values="value1,value2,..."` เรียงตำแหน่งให้ตรงกับ `data-option-keys` เสมอ** ไม่งั้น select2 จะ submit ค่า key ดิบไปแทน validation หลังบ้านจะ reject ทันที (เจอบั๊กจริงมาแล้ว — บันทึกไม่ลงทั้งหน้า Payroll Configuration เพราะพลาดจุดนี้)
  - `{ mode: 'native' }` — คง native look ไม่มี dropdown UI ของ Select2 แต่ยัง init ผ่านฟังก์ชันนี้เพื่อให้ผ่านกฎ "ห้ามปล่อย select ไม่ init"
- เปลี่ยนภาษาแบบ dynamic: option label ต้องเปลี่ยนตาม language switcher ทันทีโดยไม่ reload หน้า — `applyLanguage()` ใน `public/js/app.js` จัดการ re-init ให้อัตโนมัติทั้ง `.select2-remote.select2-hidden-accessible` และ `.select2-static.select2-hidden-accessible` อยู่แล้ว ไม่ต้องเขียนเพิ่มเอง แค่ใส่ class ให้ถูก
- Placeholder, "no results found", "searching..." ต้อง localize ผ่าน `langData` เสมอ (key: `select_option`, `no_results`, `searching`, `input_too_short`) ห้าม hardcode ข้อความอังกฤษ
- ต้องรองรับ dropdown ที่อยู่ใน Bootstrap modal และ nested tab — ตั้ง `dropdownParent` ให้ชี้ modal ที่ครอบอยู่เสมอ (`initSelect2` เช็ค `$this.closest('.modal')` ให้อัตโนมัติแล้ว) ป้องกันปัญหา z-index/scroll ที่ Select2 เจอบ่อยเวลาอยู่ใน modal
- ตัวอย่างการใช้งานจริงในโปรเจกต์: dropdown ประเทศใน Company Profile/Payroll Configuration (`ajax`), dropdown ธนาคารใน Bank Accounts (`data-api="/api/bank.get" data-type="bank"`, `ajax`), dropdown Calculation Method/Tax Treatment ใน Payroll Configuration (`static`)

### Table / รายการข้อมูล
- ทุกตารางที่แสดงรายการข้อมูล (list) ต้องใช้ **DataTables** ห้ามปล่อยเป็น `<table>` เปล่าไม่ init หรือ render แถวเองแบบ manual แล้วจบ (ย้อนกลับไปเป็นแบบนั้นเคยเกิดขึ้นจริงตอนสร้างหน้า Payroll Cycle จาก mockup เดิม)
- เลือกโหมดตามขนาดข้อมูลที่คาดว่าจะมี:
  - **Server-side** (`serverSide: true`) สำหรับรายการที่อาจมีจำนวนมากไม่จำกัด (พนักงาน, master catalog ต่อบริษัท) — endpoint ฝั่ง PHP ต้องคืนค่าตรงตาม DataTables spec: `{draw, recordsTotal, recordsFiltered, data}` (ดูตัวอย่าง `EmployeeController::list()`, `PayrollConfigurationController::pedTypeList()`)
  - **Client-side** (`ajax` + `dataSrc`) สำหรับรายการที่รู้แน่ชัดว่าน้อยต่อบริษัท (เช่น payroll cycle) — endpoint คืนค่า `{status, data:[...]}` ธรรมดาแล้วตั้ง `dataSrc: 'data'` ดึงออกมาพอ ไม่ต้องทำ server-side pagination
- ปุ่ม "Add" ต้อง inject เข้าไปในแถบค้นหาของ DataTable เองผ่าน `initComplete` (หา container แล้ว append ปุ่มสีส้มเข้าไปใน `.dt-search`) ห้ามวางปุ่มลอยแยกเหนือ/ข้างตาราง
- ปุ่ม Edit ต้องดึงข้อมูลสดจาก API เสมอ (`GET .../xxx.get?id=`) ห้ามอ่านจากข้อมูลที่ cache ไว้บน DOM ของแถว เพราะ DataTables อาจ re-render แถวเมื่อเปลี่ยนหน้า/sort/ค้นหา ทำให้ cache หลุด
- Save/Delete สำเร็จให้ reload ด้วย `table.ajax.reload(null, false)` (พารามิเตอร์ตัวที่สอง `false` = ไม่รีเซ็ต pagination ที่ผู้ใช้อยู่) ห้าม reload ทั้งหน้า
- ตัวอย่างการใช้งานจริงในโปรเจกต์: ตารางพนักงาน/Earning-Deduction Types (server-side), ตาราง Payroll Cycle (client-side)

## Business Model — 3 ประเภทผู้ใช้
1. **Origami HR user** — sync attendance/leave/holiday/OT/ค่าเที่ยว อัตโนมัติจากโมดูล HR
2. **External HR user** — Import ผ่าน Excel/CSV template + validation + field mapping
3. **No HR user** — manual entry เต็มรูปแบบ

ทุก entity ที่เกี่ยวกับ attendance/leave/OT ต้องมี field `data_source` (`sync` / `import` / `manual`) เพื่อบอกที่มาของข้อมูล

## Output Requirement (Compliance)
- Export ภ.ง.ด.1 / ภ.ง.ด.1ก (กรมสรรพากร) ตามฟอร์แมตราชการ
- Export สปส. 1-10 (สำนักงานประกันสังคม) ตามฟอร์แมตราชการ
- Config ได้ต่อประเทศ เพราะ statutory ต่างกันตามประเทศ (TH/SG/MY/US)
