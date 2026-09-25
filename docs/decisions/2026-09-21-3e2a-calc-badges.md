# 3e-2a — badge error → popover, ตัวแปล calc error เป็นของกลาง, advisory prorate 0

**2026-09-21** · rules.md §5.1/§5.2 · คู่กับ 3e-2b (หน้า List + Raw Sync) ที่ยังไม่ทำ

**ย้ายตัวแปล** — `calcErrorMessageRd()` อยู่ใน `payroll/detail.js` ที่หน้า List ไม่โหลด `index.js:348` เลย
render code ดิบให้ผู้ใช้เห็น → ย้าย verbatim ไป `format-helpers.js` (โหลดทุกหน้า)

**prorate 0 คิดฝั่ง client** — `prorate_days = 0` เป็นผลลัพธ์ที่ถูกต้องของ 4 สาขาใน `recalculate()` ไม่ใช่
error ของสาขาไหน · push ที่ engine ต้องแตะ `ADVISORY_CALC_ERROR_CODES` + semantics ของ `calc_status` ทั้งชุด
ตัวเลือก (B) ที่แยกสาเหตุครบ 4 สาขาอยู่ใน BACKLOG พร้อมข้อความร่างแล้ว

**4 บั๊กที่ "การวัด" จับได้ (ไม่ใช่การอ่านโค้ด)**
1. Bootstrap sanitize ตัด `data-code` บน `<li>` ทิ้งเงียบ — `allowList` เป็น per-tag **และ** per-attribute และ `li` ไม่มี attribute ที่อนุญาตเลย (markup เดียวกันนอก popover เก็บไว้ครบ)
2. `prorate_days` มาเป็นสตริง `"0"` (`getDetails()` ไม่ cast) เช็ค `typeof === 'number'` จึงไม่เคยติดเลย — กัน `null`/`undefined`/`''` ก่อนแล้วค่อย `Number()` (ทั้งคู่ coerce เป็น 0)
3. เทสเขียนกฎ `=== 0` ซ้ำอีกรอบ สองฝั่งตอบตรงกันและผ่านทั้งที่ฟีเจอร์ไม่ทำงาน — ให้เทสอ่าน**รูปร่างจริงของ response** ไม่ใช่สมมุติฐานของโค้ด
4. cell ชื่อ dark ไม่ได้ dark — บัญชีเทส save `light` แอป stamp `data-bs-theme="light"` ซึ่งปิด media query ของ OS พอดี → `applyAppTheme()` (harness.js) เป็นกลไกเดียวของ repo + assert ก่อนวัดเสมอ

**ระยะ banner ต้องใช้ `:has()`** — `gap` ข้าม child ที่ `display:none` ให้เอง แต่ระยะ wrapper → tab bar ต้อง
เป็น 0 เมื่อไม่มี banner ไม่งั้นรอบส่วนใหญ่โดนขยับ · พิสูจน์ p10b ด้วยการถอด style ของ wrapper ออกสดๆ แล้ว
วัดซ้ำ ได้ 18px เท่ากัน ไม่ hardcode · **`<body>` ใช้ legacy `--app-bg` ไม่ใช่ `--c-bg`** (`#14181f`/`#15181C`)
