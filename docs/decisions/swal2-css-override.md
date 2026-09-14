<!-- ย้ายมาจาก docs/design/rules.md §10 (2026-09-14) ทั้งก้อน ไม่ตัดทอน -- ตามกฎ "ห้ามเพิ่มประวัติ/
     เหตุผลยาวในเอกสารกฎ ให้ไป docs/decisions/" (CLAUDE.md เดิมพูดถึง CLAUDE.md เอง แต่ใช้หลักการเดียวกัน
     กับ rules.md). กฎปัจจุบันแบบสั้น (ตาราง tone→icon + ข้อจำกัด 1 บรรทัดเรื่อง override) อยู่ใน
     rules.md §10's เดิม -- อ่านไฟล์นี้เมื่อต้องรู้ที่มา/เหตุผล/ประวัติการดีบัก ไม่ใช่ตอนแก้โค้ด CSS ของ
     Swal2 ตามปกติ -->

## SweetAlert2 CSS override ทั้งก้อนตายเงียบ -- injected-stylesheet cascade bug (2026-09-14)

- **2026-09-14, Round 3 Phase A, บั๊กจริงที่เจอระหว่างตรวจ `showConfirm()`'s ใหม่ `tone` → ไอคอน
  อัตโนมัติ ด้วย Playwright ใน dark mode**: popup ของ SweetAlert2 (ทุก dialog — `showConfirm`/
  `showSuccess`/`showError`/`showWarning`) ยังเป็นกล่องขาวสว่างอยู่กลางหน้าจอมืด ทั้งที่ `style.css`
  มี block `:root { --swal2-background: var(--c-bg); ... }` เขียนไว้ตั้งแต่ T069 Step 3
  (2026-09-05)/Round 2 item 7b (2026-09-13) แล้ว
- **สืบแล้วพบ root cause จริง**: `app/views/layout/footer.php` โหลด `sweetalert2.all.min.js`
  (bundle "ALL") ซึ่งฝัง stylesheet เริ่มต้นของตัวเองมาด้วย แล้ว **inject `<style>` เข้า `<head>` เอง
  ตอนโหลดหน้า** (module-load time, ไม่ใช่ lazy ตอน `Swal.fire()` ครั้งแรก — ยืนยันด้วยการตรวจสอบ
  `document.styleSheets` ทันทีหลังโหลดหน้า ก่อนเรียก `showConfirm()` เลยด้วยซ้ำ ก็เจอ stylesheet
  ที่ inject มาแล้ว) — `<style>` นี้ตามหลัง `style.css` ใน DOM เสมอ (script โหลดหลัง `<link>` เสมอ)
  แล้ว `:root` ของมันมี specificity เท่ากับของเราเป๊ะ (ทั้งคู่เป็น `:root {}` เปล่าๆ) — cascade tie
  จึงตัดสินด้วยลำดับในเอกสาร ตัวที่มาทีหลังชนะ กลายเป็นว่า `style.css`'s ของเราแพ้ทุกครั้งไม่ว่าจะ
  เขียนอะไรไว้ก็ตาม
- **ยืนยันด้วย `getComputedStyle(document.documentElement)` ตรงๆ ไม่ใช่แค่เดาจากภาพหน้าจอ**:
  `--swal2-background` อ่านได้ `white` (ค่า default ของ library) ไม่ใช่ `var(--c-bg)`,
  `--swal2-color` อ่านได้ `#545454` ไม่ใช่ `var(--c-text)`, `--swal2-border-radius` อ่านได้
  `0.3125rem` ไม่ใช่ `var(--radius-lg)`, `--swal2-confirm-button-border-radius`/
  `--swal2-cancel-button-border-radius` อ่านได้ `0.25em` ไม่ใช่ `var(--radius)`,
  `--swal2-cancel-button-background-color` อ่านได้ `#6e7881` (เทาอมฟ้าตันของ library) ไม่ใช่
  `transparent` — **ทุกตัว** ใน block นั้น dead ตั้งแต่วันแรกที่เขียน ไม่ใช่แค่บางตัว
- **ทำไมไม่เคยมีใครเห็นมาก่อน**: light mode บังเอิญไม่โชว์อาการ เพราะค่า default ของ library
  (`white`/`#545454`) ใกล้เคียงกับค่า light ของ `--c-bg`/`--c-text` ของแอปเองพอที่จะดูไม่ผิดปกติ —
  ปัญหาเห็นชัดเฉพาะ dark mode เท่านั้น และไม่มีใคร screenshot dialog ของ Swal2 ใน dark mode มา
  ตรวจตั้งแต่ T069 Step 3 ship (2026-09-05) จนถึงตอนนี้
- **สิ่งที่ยังคงทำงานถูกได้แม้จะมีบั๊กนี้อยู่**: `.swal2-cancel { border: 1px solid
  var(--c-border-strong); }` (ของเดิม, ก่อนรอบนี้) และ `.swal2-icon.swal2-info,
  .swal2-icon.swal2-question { border-color: var(--c-info); color: var(--c-info); }` (ของเดิม) —
  ทั้งคู่เป็น **direct class selector** ไม่ใช่ custom-property indirection ผ่าน `:root` จึงไม่โดน
  bug นี้กระทบเลย (ดูหัวข้อ "ทางแก้" ด้านล่างว่าทำไม)
- **ทางแก้**: ย้ายทุก property จาก `:root { --swal2-*: ... }` ไปประกาศตรงบน class ที่ render จริง
  (`.swal2-popup`/`.swal2-confirm`/`.swal2-cancel`/`.swal2-close`/`.swal2-timer-progress-bar`)
  แทน — อ่าน source ของ library ตรงๆ พบว่า selector ของมันเกือบทั้งหมดห่อด้วย `:where(...)`
  (specificity 0 ตาม CSS spec) เหลือแค่ `div`/`button` เปล่าๆนอก `:where()` ให้คะแนน specificity
  (0,0,1) ถึง (0,0,2) เท่านั้น — class selector ตรงๆของเราแค่ตัวเดียว (0,1,0) ชนะได้เสมอไม่ว่า
  `<style>` ที่ inject มาจะมาก่อนหรือหลังในเอกสาร (ไม่ต้องพึ่ง `!important` เลยสักจุด) — เทคนิคเดียวกับ
  ที่ `.swal2-cancel`'s ของเดิม (border) ถูกอยู่แล้วโดยไม่รู้ตัวว่าเป็นเพราะเหตุนี้
- **`--swal2-footer-*`/`--swal2-input-*`/`--swal2-validation-message-*` ไม่ได้ถูก "แก้" ด้วยวิธีนี้ —
  ตัดทิ้งไปเลย**: ยืนยันแล้ว (ทั้งก่อนและหลังรอบนี้) ว่าไม่มี `showSuccess`/`showError`/
  `showConfirm`/`showWarning` call site ไหนในแอปส่ง Swal2's `input:`/`footer:` options เลย —
  ไม่มีอะไรให้ property เหล่านี้ theme จริง ต่อให้แก้ก็ไม่มีผลอะไรที่มองเห็นได้
- **ลองแล้วพัง ครั้งที่ 1 — `.swal2-confirm { background-color: var(--c-primary); }`
  (hardcode ตรงๆ)**: ทุก `showConfirm()` ที่ส่ง `tone` มา (warning/danger/success) กลายเป็นปุ่มสีส้ม
  หมดทุกอัน ไม่ตรง tone อีกต่อไป — สาเหตุ: `confirmButtonColor` (option ที่ `tone`/`danger` branch
  ของ `showConfirm()` ตั้งให้) **ไม่ได้ตั้ง `background-color` เป็น inline style ตรงๆ** อ่าน source
  ของ library (`sweetalert2.all.min.js`) พบว่ามันเรียก
  `element.style.setProperty('--swal2-confirm-button-background-color', confirmButtonColor)`
  แทน — ตั้ง **custom property** ตัวเดิมที่ block นี้เคยอ่านผ่าน `var()` เป็น inline บนปุ่มนั้นโดยตรง
  — พอ rule ของเราไม่อ้างถึง custom property ตัวนี้อีกต่อไป (hardcode ค่าตรงๆ) ก็เลยไม่มีทางรับค่าที่
  ตั้งมาต่อ call ได้เลย
- **ลองแล้วพัง ครั้งที่ 2 — `background-color: var(--swal2-confirm-button-background-color,
  var(--c-primary))` (อ่าน custom property เดิม พร้อม fallback)**: คราวนี้กรณีมี `tone` ถูกต้องแล้ว
  แต่กรณี **ไม่มี tone เลย** (default) กลับกลายเป็นสีม่วง `#7066e0` ของ library แทนที่จะเป็นส้ม
  `--c-primary` — สาเหตุ: fallback ของ `var(x, y)` (พารามิเตอร์ตัวที่ 2) จะทำงานก็ต่อเมื่อ `x`
  **ไม่มีค่าเลยตลอดสาย inheritance** เท่านั้น — แต่ในกรณีนี้ `--swal2-confirm-button-background-color`
  มีค่าอยู่จริง (inherited ลงมาจาก `:root` ที่ stylesheet ที่ inject มาตั้งไว้เป็น `#7066e0`) เพียงแค่
  เป็นค่าที่ inherited มาจาก ancestor ไม่ใช่ "ไม่มีค่า" ตามที่ CSS spec นิยาม — fallback จึงไม่เคยทำงาน
- **ทางแก้สุดท้าย (ใช้จริง)**: ประกาศทั้ง custom property **และ** อ่านมันไว้บน selector เดียวกัน
  (`.swal2-confirm`) พร้อมกัน:
  ```css
  .swal2-confirm {
      --swal2-confirm-button-background-color: var(--c-primary);
      background-color: var(--swal2-confirm-button-background-color);
  }
  ```
  หลักการ: **declaration ที่ตรงบน element (แม้จะมาจาก class selector specificity ต่ำแค่ไหน) ชนะค่า
  inherited จาก ancestor เสมอ** (เป็นคนละชั้นของ cascade กับ specificity fight ระหว่างสอง declaration
  ที่ต่างก็ตรงกับ element เดียวกัน) — rule นี้เลยชนะ `:root` ที่ inject มาได้แน่นอนสำหรับ**กรณี default
  (ไม่มี tone)** แล้ว Swal2's เอง `setProperty()` (ตอนมี `tone`) ก็ยังชนะ rule นี้ต่ออีกที เพราะ
  inline declaration บน element เดียวกันชนะ stylesheet declaration บน element เดียวกันเสมอ (ไม่ว่า
  specificity เท่าไหร่) — ผลคือถูกทั้ง 2 กรณี: default = ส้ม, มี tone = สีตาม tone
- **สรุปกฎที่ต้องจำไว้สำหรับใครแก้ Swal2 CSS ในไฟล์นี้ต่อไป**: ห้ามตั้ง property/custom-property ใหม่
  ที่เกี่ยวกับ Swal2 ไว้ที่ `:root` อีกเด็ดขาด (ตายแน่นอนเพราะเหตุผลข้างบน) — ถ้าจำเป็นต้องอ่านค่า
  ผ่าน custom property ตัวเดิมที่ Swal2's เองมี per-call inline override (เช่น
  `confirmButtonColor`/`cancelButtonColor`/`denyButtonColor`) ให้ **ประกาศ default ของ custom
  property นั้นตรงบน class selector ที่ render จริง** (ไม่ใช่ fallback ผ่าน `var(x,y)`) แล้วค่อยอ่าน
  มันกลับมาใช้ในบรรทัดถัดไปบน selector เดียวกัน — ถ้าไม่มี per-call override ให้กังวล (เช่น
  border-radius, popup background/color, cancel button เอง) แค่ประกาศ property ปกติตรงๆบน class
  selector พอ ไม่ต้องผ่าน custom property เลยด้วยซ้ำ
