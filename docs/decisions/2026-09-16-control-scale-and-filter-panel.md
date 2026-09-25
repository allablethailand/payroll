# Control scale เดียวทั้งแอป + filter bar เป็น card 2 ส่วน (2026-09-16)

## ปัญหาที่วัดได้ก่อนแก้ (1400 light, Payroll Detail → tab พนักงาน)

| control | height | font-size | มาจาก |
|---|---|---|---|
| ปุ่ม "คำนวณ" (page header) | **29px** | **12px** | ปุ่มปกติ (ไม่มี `-sm`) |
| length select | 23.8px | 10.5px | `.form-select-sm` — **datatables.net-bs5 ใส่เอง** |
| search input | 23.8px | 10.5px | `.form-control-sm` — datatables.net-bs5 ใส่เอง |
| ปุ่ม bulk / "ตรวจสอบทั้งหมด" / "+ พนักงาน" | 23.8–24.6px | 10.5px | `.btn-sm` ใน `options.toolbar` ของหน้า |
| select ใน filter bar | 28px | 10.5px | `.form-select-sm` ใน markup ของหน้า |
| ปุ่ม "ล้างตัวกรอง" | — | — | `.btn-sm` ใน `filter-bar.php` เอง |
| ไอคอน filter บนหัวคอลัมน์ | 11px | 11px (`0.9167rem`) | `.tcf-filter-btn` ตั้งขนาดของตัวเอง |
| ไอคอนในปุ่มกลม row action | 12px | 12px | — (เป็นขนาดอ้างอิง) |

แถวเดียวกันมี 3 ขนาด และไม่มีอันไหนตรงกับปุ่มใน page header — ต้นเหตุมาจาก **3 แหล่งอิสระ** ไม่ใช่ที่เดียว

## ที่แก้ (control scale = ขนาดปกติทุกที่)

1. **datatables.net-bs5**: ไฟล์ integration ของมันฝัง `form-control form-control-sm` / `form-select form-select-sm`
   ไว้ใน `DataTable.ext.classes` — override **ครั้งเดียวใน `app.js`** (ใน `$(function(){...})` ของไฟล์เอง
   ดูหมายเหตุการโหลดสคริปต์ด้านล่าง) ไม่แตะไฟล์ vendor และครอบทุกตารางในแอป รวมหน้าที่ยังสร้าง DataTable
   เองด้วย `$().DataTable()` (14 ไฟล์ ดู BACKLOG "รอบ 4")
2. **toolbar config ของหน้า**: `payroll/detail.js` ตัด `btn-sm` ออกจากปุ่มทั้ง 3 ที่ส่งเข้า `options.toolbar`
3. **markup ของหน้า/partial**: `payroll/detail.php` ตัด `form-select-sm` ออกจาก select ของ filter 3 ช่อง,
   `filter-bar.php` ตัด `btn-sm` ออกจากปุ่ม "ล้างตัวกรอง"
4. **safety net ใน style.css**: กฎเดียวที่ยกเลิกผลของ `-sm` เฉพาะในโซน toolbar/filter (เขียนผ่าน CSS variable
   ของ Bootstrap เอง ไม่ใช้ `!important`) สำหรับหน้าที่ยังไม่ migrate — ไม่ได้มาแทนข้อ 1-3 แต่กัน 14 หน้าที่เหลือ
   ไม่ให้หลุดจากสเกลระหว่างรอ
5. **ไอคอนหัวคอลัมน์**: `.tcf-filter-btn` เลิกตั้ง `0.9167rem` ของตัวเอง ใช้ `1rem` = ขนาดไอคอน control ปกติ

### รอบตรวจซ้ำก่อน commit: ค่าที่ถูกต้องคือของ "ฟอร์ม" ไม่ใช่ของ page header

วัด 4 จุดอ้างอิงเทียบกัน (1400 light) แล้วพบว่ายังเป็น **2 สเกล** อยู่ดี:

| จุด | ก่อน |
|---|---|
| ปุ่ม "ปิด" ใน modal footer (`modalFooterButtonsHtml`) | 29px / 12px |
| ปุ่ม "เพิ่มรายการ" ในฟอร์ม (`.form-compact`) | **30.5px / 13px** |
| input ในฟอร์ม | **30.5px / 13px** |
| ปุ่ม "คำนวณ" ใน page header | 29px / 12px |

ฟอร์มอยู่ที่ `--fs-sm` (13px) มาตั้งแต่ §9 ส่วน page header / toolbar / filter bar / modal footer
ยังเป็น 1rem ของ root (12px) — **เลือกให้ฟอร์มเป็นค่าอ้างอิง** แล้วยก 4 โซนที่เหลือขึ้นมาเท่ากัน
(ไม่ใช่ดึงฟอร์มลง เพราะ §9 ระบุ `--fs-sm` ไว้ชัดและเป็นสเกลที่ผู้ใช้อ่านฟอร์มจริงอยู่ทุกวัน)

**หลังแก้ทั้งหมด**: modal footer / ปุ่มในฟอร์ม / input ในฟอร์ม / select2 ในฟอร์ม / page header /
length / search / ปุ่ม toolbar / select2 + ปุ่มล้างใน filter bar = **30.5px, 13px ทุกตัว** ขอบล่าง
ตรงกันทั้งแถว · ปุ่มกลม row action คง 32px (มีสเปกของตัวเองใน §7) · ไอคอนหัวคอลัมน์ = 12px เท่าไอคอน
ในปุ่มกลม

ปุ่มเขียนผ่าน `--bs-btn-*` ของ Bootstrap เอง (ไม่ใช่ `font-size` ตรงๆ) เพื่อไม่ให้ชนกับ variant
`-sm`/`-lg` และ selector ของ input/select ระบุทั้งตัวธรรมดาและตัว `-sm` คู่กัน เพราะ `.form-select-sm`
ชนะ `.dt-length select` ด้วย specificity (เจอจริงระหว่างรอบนี้: length box ค้างที่ 12px อยู่ตัวเดียว)

**หมายเหตุการโหลดสคริปต์**: `app.js` ถูกโหลดจาก `layout/header.php` คือ **ก่อน** footer.php ดึง
DataTables เข้ามา — การ override `$.fn.dataTable.ext.classes` ตอน parse time จึงไม่มีผลเลย (ยืนยันจาก
หน้าจริง: class ยังเป็น `-sm` อยู่) ต้องทำใน `$(function(){...})` ของ app.js เอง ซึ่งลงทะเบียนก่อน ready
handler ของทุก page script จึงทันก่อนตารางแรกถูกสร้าง

**ไม่แตะ**: ขนาดปุ่มกลม row action (32px, §7 มีสเปกของตัวเอง), เนื้อในตาราง (`--fs-sm`), badge (`--fs-xs`)

## filter bar: จาก "บล็อกเทา" เป็น card 2 ส่วน

ของเดิม: พื้น `--c-bg-subtle` ทั้งแผง ไม่มีขอบ radius `--radius-lg` — อ่านเป็นบล็อกสีลอยๆ ที่ไม่เหมือน
panel ไหนในหน้าเดียวกัน

ของใหม่: **card** (`--c-bg` + ขอบ `--c-border` + `--radius` ชุดเดียวกับ `.stat` ใน §2) ที่มี
- **หัว** แถบพื้น `--c-bg-subtle` (เทาเดียวกับหัวตาราง) — ไอคอนกรวยเทา + "ตัวกรอง (N)" ซ้าย, chips/ล้างตัวกรอง/
  caret ขวา — **กดได้ทั้งแถบ**เพื่อยุบ/กาง (เดิมกดได้แค่วงกลม+ป้าย) โดย chips / ปุ่มล้าง / slot
  `$header_extra_html` อยู่ในโซนที่ handler ข้ามให้ (`.filter-bar-chips`, `.filter-bar-header-right`)
  จึงไม่กลืนคลิกของตัวเอง
- **เส้นคั่นใต้หัวเฉพาะตอนกาง** — ตอนยุบ card เหลือหัวเดี่ยว ไม่มีเส้นลอยค้าง
- `overflow:hidden` บน card คือสิ่งที่ทำให้แถบหัวโค้งตามมุม card แทนที่จะตัดเป็นมุมฉาก

ทุกค่ามาจาก token → dark mode ได้เองไม่ต้องมี override, ไม่มีสีส้ม, ไม่มีขาว hardcode — กฎ < 992px
(ยุบเป็น 2 แถว) และการจำสถานะต่อ `pageKey` ใน localStorage ไม่เปลี่ยน

## empty state 2 แบบ + ปุ่ม "ล้างตัวกรอง" ที่กดแล้วไม่ทำงาน

ปุ่มเดิมใน empty state เรียก `dt.search('').draw()` — ล้างเฉพาะช่องค้นหาของ DataTables เท่านั้น ตารางที่
ว่างเพราะ filter-bar หรือ column filter (2 กรณีที่เกิดจริงบ่อยที่สุด) กดแล้ว "ไม่มีอะไรเกิดขึ้น" ตามที่รายงาน
— ไม่ใช่ปุ่มพัง แต่ล้างผิดแหล่ง แก้โดยทำฟังก์ชันกลาง `clearAllTableFilters()` ที่ล้างครบทั้ง 3 แหล่ง
(filter-bar ผ่าน `$bar.data('filterBarClear')` ที่ `initFilterBar()` ฝากไว้, column filter ผ่าน
`clearColumnFilters()` ที่ `table-column-filter.js` export ออกมาใหม่, แล้วค่อยช่องค้นหา) — ปุ่มใน
empty state กับปุ่มใน filter-bar จึงเป็นทางเดียวกันจริง

การเลือกว่าจะแสดงแบบไหนใช้ `tableHasActiveFilters()` (แหล่งเดียวกันทั้ง 3) คู่กับ `recordsTotal > 0`
เพราะ server-mode รายงาน `recordsTotal` เป็น 0 ได้ทั้งที่ตัวกรองเป็นคนทำให้ว่าง

### ตำแหน่งข้อความบนจอแคบ: `--dt-visible-width`

แถว empty state เป็น `<td colspan>` เต็มความกว้าง**ตาราง** ไม่ใช่ความกว้างจอ — ที่ 430px ตารางกว้าง
1,180px ข้อความที่จัดกลางจึงไปอยู่นอกจอ (ตารางอ่านว่าว่างเปล่าโดยไม่มีข้อความอะไรเลย) เซลล์ถูกตรึงซ้ายด้วย
`position: sticky` อยู่แล้ว เหลือแค่บอกว่า "ส่วนที่มองเห็นกว้างเท่าไร" — ประกาศเป็น custom property บนตัว
scroller เอง (`--dt-visible-width`) ไม่ใช่ inline style บนแถว เพราะทุก draw สร้างแถวนั้นใหม่แต่ scroller
อยู่ตลอด

**ครั้งแรกทำผิดจุด**: publish ตอน `initComplete` + `resize` ของ window — แล้วไม่ติดเลยในหน้าจริง (วัดได้
`--dt-visible-width` ว่าง) เพราะตารางนี้อยู่ใน tab ที่ไม่ใช่ tab แรก ตอน init scroller ยังซ่อนอยู่ (กว้าง 0
= ไม่มีอะไรให้ publish) และ **ตารางว่างไม่มี draw รอบต่อไปอีกเลย** ตัว window resize ก็ไม่เคยเกิด — แก้ด้วย
`ResizeObserver` บน scroller (`dtWatchVisibleWidth()`) ซึ่งยิงเองตอน element เปลี่ยนขนาดจริง รวมถึงตอนที่
tab ถูกเปิดครั้งแรก (fallback เป็น window resize ถ้าเบราว์เซอร์ไม่มี ResizeObserver)
