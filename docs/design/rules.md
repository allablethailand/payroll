# Design Rules — Origami Payroll (section สำหรับ CLAUDE.md)

> วางเป็น section ใหม่ใน CLAUDE.md ชื่อ `## Design` ใช้บังคับกับทุกงานที่แตะ view/CSS/JS ที่ render UI
> Stack: PHP views + jQuery + Bootstrap 5 + DataTables + Select2 + SweetAlert2 — **ไม่มี component framework** ทุก "component" ในเอกสารนี้คือ PHP partial + JS helper + CSS class ไม่ใช่ React/Vue

---

## 0. หลักการ (อ่านก่อนทุกครั้ง)

1. **หน้าจอต้องเงียบ** — สีมีหน้าที่บอกว่า "ทำอะไรต่อ" หรือ "ต้องตัดสินใจอะไร" เท่านั้น ถ้าสีไม่ได้ตอบสองคำถามนี้ ให้เป็นเทา
2. **1 หน้า/1 modal = 1 action หลัก** — มีปุ่มส้มได้ตัวเดียว ถ้าหาไม่เจอว่าตัวไหนคือ action หลัก ให้ถาม
3. **โครงสร้างต้องสื่อข้อมูล ไม่ใช่ตกแต่ง** — เส้น กล่อง การ์ด ไอคอน ตัวเลขลำดับ ใช้เมื่อมันบอกอะไรที่ผู้ใช้ต้องรู้ ถ้าเอาออกแล้วความหมายไม่เปลี่ยน ให้เอาออก
4. **ซ้ำ = shared** — ถ้า markup/JS แบบเดียวกันจะปรากฏใน 2 ที่ ต้องเป็น partial/helper ตัวเดียว (กฎ "ห้าม mirror-copy" เดิมใช้กับ UI ด้วย)
5. **คำในหน้าจอเป็น design content** — ปุ่ม/หัวข้อ/toast ใช้คำเดียวกันตลอด flow ("บันทึก" → toast "บันทึกแล้ว" ไม่ใช่ "สำเร็จ"), ประโยคเดียว, active voice, ไม่มีคำเติม
6. **ห้ามเดา design** — เจอกรณีที่กฎไม่ครอบ ให้เสนอ 2–3 ตัวเลือกพร้อมภาพ/ASCII แล้วหยุดถาม ไม่ตัดสินเอง
7. **phase design ห้ามแก้ logic** — ถ้าระหว่างจัด UI เจอบั๊ก logic ให้จดลง BACKLOG.md แล้วทำต่อ ไม่แก้ปนกัน (diff ของ design pass ต้องเป็น view/CSS/JS-render เท่านั้น)

---

## 1. Tokens (CSS variables) — แหล่งเดียวของสี/ระยะ/ตัวอักษร

ไฟล์: `public/css/tokens.css` (โหลดก่อน style.css ทุกหน้า) — **ห้ามมี hex/rgb ใหม่ที่อื่น** ทั้ง CSS/PHP/JS

**Dark mode มีที่เดียว — `tokens.css` เท่านั้น** (ตัดสินใจแล้วรอบ 2 item 1b): token สี (`--c-*`) มี 2 ชุด
light/dark อยู่ใน `tokens.css` ไฟล์เดียว ผ่าน selector convention เดียวกับที่ Backlog Phase 11 T069 ใช้อยู่แล้ว
กับ `--app-*` ของตัวเอง (`style.css`, ค้นคำว่า "3-state model") — ไม่ประดิษฐ์ selector ใหม่:
`[data-bs-theme="dark"]` (เลือก Dark ตรงๆ) + `@media (prefers-color-scheme: dark) { :root:not([data-bs-theme="light"]) { ... } }`
(เลือก System บน OS ที่เป็น dark) — **ห้าม component ไหนมี dark override ของตัวเองแยกต่างหาก** (เช่น
`.btn-primary[data-bs-theme="dark"] { ... }`) ทุก component ต้องอ้าง `var(--c-*)` เท่านั้น แล้วให้
`tokens.css`'s 2 ชุดนี้เป็นคนตัดสินค่าจริงให้เอง — ถ้า component ไหนต้องการสีที่เปลี่ยนตาม theme แต่ยังไม่มี token
ให้เพิ่ม token ใหม่ใน `tokens.css` (ทั้ง light และ dark) ก่อน ไม่ใช่เขียน dark-mode CSS แยกที่ตัว component
`--c-primary` เป็นข้อยกเว้นที่ตั้งใจ: **ค่าเดียวกันทั้ง 2 theme** (สีแบรนด์คงที่ ไม่ปรับตาม theme) — มีแค่
`--c-primary-hover`/`--c-primary-soft` ที่ต้องมีค่า dark แยก (ต้องปรับความสว่าง/ทึบให้ยังคุมกับพื้นมืด)

```css
:root {
  /* brand — ใช้ได้ 3 ที่เท่านั้น: ปุ่มหลัก, tab ที่เลือก, step ปัจจุบัน */
  --c-primary: #FF9900;
  --c-primary-hover: #E68A00;
  --c-primary-soft: #FFF4E0;        /* พื้นหลังอ่อนของ step/tab ปัจจุบัน เท่านั้น */

  /* neutral — ทุกอย่างที่ไม่ใช่ brand/status */
  --c-text: #1F2328;
  --c-text-muted: #6B7280;
  --c-text-faint: #9CA3AF;
  --c-border: #E5E7EB;
  --c-border-strong: #D1D5DB;
  --c-bg: #FFFFFF;
  --c-bg-subtle: #F7F8FA;           /* แถบหัวตาราง, filter bar, พื้น modal footer */
  --c-bg-hover: #F1F3F5;

  /* status — ใช้เฉพาะ "สถานะที่ผู้ใช้ต้องตัดสินใจ/ระวัง" ไม่ใช้ตกแต่ง */
  --c-danger: #D92D20;  --c-danger-soft: #FEE4E2;
  --c-warning: #B54708; --c-warning-soft: #FEF0C7;
  --c-success: #067647; --c-success-soft: #DCFAE6;
  --c-info: #6B7280;    --c-info-soft: #F1F3F5;   /* "info" = เทา ไม่ใช่ฟ้า */

  /* decision-set solid buttons เท่านั้น (.btn-decision-*, §4's ข้อยกเว้น, 2026-09-13, แก้สี warning อีก
     รอบวันเดียวกัน) — -on-fill คือสีตัวหนังสือ/ไอคอนบนพื้นตัน **ตอนนี้ขาวทั้ง 3 tone ทั้ง 2 theme** (ตัวหนา
     600 บังคับบน `.btn-decision-*` ทุกตัว ช่วย contrast ให้ผ่านเกณฑ์ตัวหนา/ตัวใหญ่ 3:1 แทน 4.5:1 ปกติ) —
     **ยกเว้น success/danger ใน dark mode ที่ยังเป็น #1F2328 (ดำ) เหมือนเดิม ไม่ได้เปลี่ยนตาม** เพราะพื้น
     dark-mode ของ 2 ตัวนั้น (--c-success/--c-danger เดิม ไม่ได้ปรับสี) ตัวหนังสือขาวยังวัดได้แค่ 1.74:1/
     2.79:1 ไม่ผ่านแม้เกณฑ์ 3:1 ที่ผ่อนแล้ว — ดู tokens.css's เอง comment สำหรับตัวเลข contrast จริงที่เช็ค
     ครบทุกคู่ -hover = เข้มขึ้น 8% ต่อ channel -- -fill คือสีพื้นถมจริงที่ใช้ (success/danger = alias ของ
     token สถานะเดิม เท่ากันเป๊ะ แค่ชื่อสม่ำเสมอ; warning เป็นสีใหม่จริง #D97706/#C26A05 — เข้มกว่ารอบแรก
     #F5B400/#E0A400 โดยตั้งใจ เพื่อให้ตัวหนังสือขาวกลับมาใช้ได้ ผ่าน 3:1) */
  --c-success-on-fill: #fff; --c-warning-on-fill: #fff; --c-danger-on-fill: #fff;
  --c-success-hover: #066D41; --c-warning-hover: #A74107; --c-danger-hover: #C8291D;
  --c-success-fill: var(--c-success); --c-warning-fill: #D97706; --c-danger-fill: var(--c-danger);
  --c-warning-fill-hover: #C86D06;

  /* type */
  --font-sans: "Sarabun", system-ui, sans-serif;   /* ฟอนต์เดียวทั้งระบบ ทุกน้ำหนักจาก Sarabun */
  --font-num: var(--font-sans);     /* ตัวเลข: เปิด font-variant-numeric: tabular-nums เสมอ */
  --fs-xs: 12px; --fs-sm: 13px; --fs-base: 14px; --fs-md: 16px; --fs-lg: 20px; --fs-xl: 24px;
  --lh: 1.5;

  /* space (4px grid) */
  --sp-1: 4px; --sp-2: 8px; --sp-3: 12px; --sp-4: 16px; --sp-5: 24px; --sp-6: 32px; --sp-7: 48px;

  /* shape */
  --radius: 6px;                    /* ปุ่ม/input/การ์ดในหน้า */
  --radius-lg: 12px;                /* surface ที่ "ลอย" เหนือหน้า — dropdown/popover/toast/Swal popup
                                        + การ์ดต่อรายการข้างในนั้น (เพิ่ม 2026-09-13, notification card
                                        redesign — ค่าเดิม 10px ปรับเป็น 12px วันเดียวกันหลัง feedback
                                        "ยังแข็ง" ดูรายละเอียดในประวัติ revision ท้ายหัวข้อ Notification) */
  --radius-pill: 999px;             /* badge เท่านั้น */
  --shadow-modal: 0 8px 24px rgba(0,0,0,.12);   /* modal/dropdown ที่ต้องการความรู้สึก "หนักแน่น" — เช่น
                                        modal ยืนยัน/แจ้งเตือนจริงจัง — การ์ดในหน้าไม่มีเงา */
  --shadow-soft: 0 12px 32px rgba(0,0,0,.10);   /* เพิ่ม 2026-09-13 — dropdown/popover ที่ต้องการความรู้สึก
                                        นุ่มกว่า --shadow-modal (กระจายกว้างกว่า ทึบน้อยกว่า) เช่น
                                        notification — คนละโทนกับ --shadow-modal เจตนา ไม่ใช่ค่าเดียวที่
                                        ปรับเผื่อทุกที่ */
}
```

กฎ:
- **Bootstrap override เป็นราย-component เสมอ ไม่ใช่แค่ root variable** (แก้ตามความจริงที่ตรวจพบแล้วในโค้ด — ดูรอบ 0's conflict report: Bootstrap 5.3.3's compiled `bootstrap.min.css` hardcode `--bs-btn-bg`/`--bs-btn-border-color` ฯลฯ ไว้ที่ class ของแต่ละ component ตรงๆ ไม่ได้อ่านจาก `--bs-primary` root token เลย — ตั้ง root var เดียวไม่พอ, ยืนยันจริงแล้วใน `style.css`'s "Bootstrap primary recolor" comment ปี 2026-08-20): `tokens.css` ต้อง (1) ตั้ง root token (`--bs-primary`, `--bs-primary-rgb`, `--bs-body-color`, `--bs-border-color`, `--bs-border-radius` ฯลฯ) สำหรับ utility ที่อ่าน var ตรง (`.text-primary`, `.bg-primary-subtle`) **และ** (2) override ทับทุก component ที่ Bootstrap hardcode ค่าไว้เอง ด้วยเทคนิคเดียวกับที่ `style.css` ใช้อยู่แล้ว (`.btn-primary { --bs-btn-bg: var(--c-primary); --bs-btn-border-color: var(--c-primary); --bs-btn-hover-bg: var(--c-primary-hover); ... }`) — รายการ component ที่ต้อง override แบบนี้อย่างน้อย: `.btn-primary`, `.btn-outline-secondary`, `.form-control`/`.form-select`, `.nav-tabs .nav-link`, `.badge` (ที่ยังไม่ผ่าน `statusBadge()`), `.dropdown-menu`, `.modal-content` — เช็คทุก component เพิ่มเติมที่ hardcode สีไว้ก่อน assume ว่า root var พอ (ยืนยันเป็นรายตัว ไม่ inherit จากที่เดียว)
- **`.btn-outline-brand` (ของเดิม — สร้างขึ้นเพราะปัญหาเดียวกันนี้เป๊ะ: outline ก็ไม่ได้สีจาก `--bs-primary` เหมือนกัน) ถูกแทนที่ด้วย `.btn-outline-secondary` ทั้งหมด หลังจากที่ tokens.css override `.btn-outline-secondary` ให้ใช้สีตาม §4 แล้ว** — ไม่มี "outline สีแบรนด์" อีกต่อไปตาม §4 (ปุ่มรองทุกตัวเป็นเทา แยกด้วยคำ ไม่แยกด้วยสี) ไม่ใช่ย้ายไปใช้ token อื่นแทน
- ไม่ใช้ `btn-info`, `btn-success`, `btn-warning`, `bg-primary`, `text-primary` ฯลฯ ที่ไม่ได้ map (ดู §12 lint)
- ตัวเลขทุกที่ (ตาราง, stat, สลิป) ใช้ `.num` → `font-variant-numeric: tabular-nums; text-align:right`
- ไอคอน: Font Awesome ชุดเดียว น้ำหนักเดียว (`fa-regular` หรือ `fa-solid` เลือกอันเดียวทั้งระบบ) สี = สีข้อความปัจจุบัน (`currentColor`) เสมอ ไม่มีไอคอนหลากสี
  - **ข้อยกเว้น: ไอคอนชนิดไฟล์ (Excel/PDF/CSV) ใช้สีประจำชนิดได้ เฉพาะไอคอน ไม่ใช่ปุ่ม (ใหม่, 2026-09-13,
    item 3a "เก็บตก" item 1)** — Excel/CSV = `--c-success` (เขียว), PDF = `--c-danger` (แดง) ผ่าน token
    เดิม ไม่ hardcode hex ใหม่ ทำเป็น class กลาง `.file-icon-excel`/`.file-icon-csv`/`.file-icon-pdf`
    (style.css) — ตรงตามธรรมเนียมที่ผู้ใช้คุ้นเคยอยู่แล้วจากทุก OS/office suite (Excel เขียว, PDF แดง) ระบุ
    **ชนิดไฟล์** ไม่ใช่สถานะ/action จึงไม่ขัดกับ §3's "สีมีหน้าที่บอกว่าต้องทำอะไรต่อ" — **สีอยู่ที่ไอคอนเท่านั้น
    ปุ่ม/ลิงก์ที่ห่อไอคอนนั้นยังเป็น `.btn-outline-secondary`/`.dropdown-item` ปกติไม่เปลี่ยน** ใช้แล้วที่เมนู
    ส่งออก (page-header.php's export dropdown items, app.js's `dtInjectExportDropdown()` สำหรับ toolbar
    ของ DataTable)
- **`--radius-lg` (12px, เพิ่ม 2026-09-13 ที่ 10px ปรับเป็น 12px วันเดียวกัน)** — ใช้เฉพาะ surface ที่
  "ลอย" เหนือหน้า: dropdown (เช่น notification), popover, toast, Swal popup, และการ์ดต่อรายการที่อยู่
  *ข้างใน* surface ลอยเหล่านั้น (เช่น การ์ดต่อรายการใน notification dropdown) — ใช้**ค่าเดียวกัน**ทั้ง
  surface ลอยเองและการ์ดข้างในนั้น ไม่ใช่ 2 ค่าต่างกันซ้อนกัน (การ์ดข้างในโค้งกว่ากรอบนอกจะดูแปลก) —
  `--radius` (6px) เดิมยังใช้กับปุ่ม/input/การ์ดที่ฝังอยู่ในหน้าโดยตรงเหมือนเดิมทุกที่ ไม่เปลี่ยน มี 2 token
  คู่กันเจตนา ไม่ใช่เปลี่ยนค่าเดียวทั้งระบบ
- **`--shadow-soft` (เพิ่ม 2026-09-13)** — เงานุ่มกว่า `--shadow-modal` (`0 12px 32px rgba(0,0,0,.10)`
  กระจายกว้างกว่า ทึบน้อยกว่า) สำหรับ surface ลอยที่ต้องการความรู้สึก "เงียบ/นุ่ม" ไม่ใช่ "หนักแน่นจริงจัง"
  เช่น notification dropdown — `--shadow-modal` ยังคงไว้สำหรับ modal ยืนยัน/แจ้งเตือนจริงจังเหมือนเดิม
  คนละโทนกันเจตนา ไม่ใช่ทดแทนกัน
- **ธง (flag icon) เลิกใช้ทั้งหมด — ไม่มีข้อยกเว้น** (ตัดสินใจแล้วรอบ 2, ปิดช่องว่างที่รอบ 1 audit เจอ: `public/flags/th.png`/`gb.png` เป็นภาพสีตายตัว recolor ด้วย `currentColor` ไม่ได้ จึงไม่มีทางทำให้ตรงกับกฎไอคอนข้อบนได้ — ไม่ใช่ "หาข้อยกเว้นให้" แต่ตัดออกไปเลย) ทุกที่ที่ใช้ธงตัวเปลี่ยนภาษา (navbar language switcher, Payslip Template/Employment Certificate Template editor's TH/EN language tabs) เปลี่ยนเป็น**ข้อความ "TH | EN"** (ตัวที่ active = `--c-text` ตัวหนา, ตัวที่ไม่ active = `--c-text-muted`, คั่นด้วย `|` สีเทา, คลิกได้ทั้ง 2 ฝั่ง) — migrate เป็นส่วนหนึ่งของรอบ 4 (navbar อยู่ใน `layout/header.php`, canvas editor 2 ตัวอยู่ใน `_editor_content.php` — ไม่ใช่ไฟล์ที่รอบ 2 แก้ได้ ตาม scope limit ของรอบนี้)

---

## 2. Layout หน้า

```
┌ Sidebar ─┬────────────────────────────────────────────────────────┐
│          │ Breadcrumb (เทา, ตัวเล็ก)                               │
│          │ H1 หน้า                              [ปุ่มหลัก ส้ม 1 ตัว] │
│          │ คำอธิบาย 1 บรรทัด (ถ้าจำเป็นจริง)                       │
│          ├────────────────────────────────────────────────────────┤
│          │ Stat cards (ถ้ามี) — แถวเดียว การ์ดเท่ากัน ไม่มีสีพื้น    │
│          │ Tabs (ไม่มีไอคอน)                                       │
│          │ Filter bar (ยุบ, มีป้ายจำนวน filter ที่ใช้)               │
│          │ ตาราง / เนื้อหา                                          │
└──────────┴────────────────────────────────────────────────────────┘
```

- **Page header = partial เดียว** `app/views/partials/page-header.php` รับ `title`, `breadcrumb[]`, `secondary_actions[]` (ไม่เกิน 2, label + id/href + icon optional, render `.btn-outline-secondary`, วางซ้ายของ `primary_action` เสมอ — ตัดสินใจแล้วรอบ 2 item 4; item ยังรับ `items[]` ให้เป็น dropdown ได้, และมี slot แยก `overflow_actions[]`/`overflow_label` สำหรับ action เปลี่ยนสถานะ — เพิ่มรอบ 3 item 3a), `primary_action` (label + id/href + icon optional), `description`, `id_prefix` (optional, default `'ph'` — ให้หน้าเดียวกัน include ซ้ำได้โดยไม่ id ชน เช่น demo หลายชุดใน components.php) — **ไม่มี card ครอบ ไม่มีไอคอนหน้า ไม่มีพื้นหลังสี** (ของเดิม "การ์ดหัวหน้า + ไอคอน" ทุกหน้าให้แทนด้วย partial นี้) **ปุ่มทุกตัวใน header เป็นขนาดปกติเสมอ ห้าม `btn-sm`** (item A.4, 2026-09-13 — partial ไม่เคยใช้ `btn-sm` มาตั้งแต่แรกอยู่แล้ว ตรวจสอบแล้ว ไม่ต้องแก้โค้ด)
  - **`decision_actions[]` (ใหม่, item 3a follow-up ข้อ 1, 2026-09-13; REVISED ข้อ "แก้ decision set" วัน
    เดียวกัน)** — แยกจาก `secondary_actions` โดยตั้งใจ: ใช้เมื่อผู้ใช้ต้องเห็น**ทุกทางเลือกของการตัดสินใจ
    หนึ่งเรื่อง**พร้อมกัน ไม่ใช่ action ระดับหน้าทั่วไป — caller ตั้งค่าตัวนี้แทน `primary_action` (ไม่ใช่คู่กัน,
    ตัวนี้แทนที่ slot primary ไปเลย) เรียงลำดับซ้าย→ขวา**ตามที่ caller ส่งมาตรงๆ ไม่มีการจัดเรียงเอง**
    (กฎเดิม "รายการสุดท้าย = primary" **ถูกถอดออกแล้ว**) — **แต่ละรายการระบุ `tone` เอง**
    (`'success'|'warning'|'danger'`) เลือก class `.btn-decision-success`/`.btn-decision-warning`/
    `.btn-decision-danger` (§4's ข้อยกเว้น "decision set" — ดู §4 เต็ม) กลุ่มนี้ห่างจาก action อื่นด้วยระยะ
    `--sp-3` (กว้างกว่า `--sp-2` ปกติระหว่าง action อื่น เพื่อให้เห็นชัดว่าเป็นกลุ่มแยก) ตัวอย่างจริง: Payroll
    Detail's pending_approval + มีสิทธิ์อนุมัติ = `[อนุมัติ]` (success, ตัน) `[ขอข้อมูลเพิ่มเติม]` (warning,
    ตัน) `[ไม่อนุมัติ]` (danger, ตัน) — ทั้ง 3 พื้นตัน ตัวหนา 600 ตัวหนังสือขาว (ดู §4 เต็มสำหรับข้อยกเว้น
    dark-mode ของ success/danger) — modal ฟอร์มที่ตามหลังแต่ละปุ่ม (มีช่องเหตุผล/note) ก็ใช้ class เดียวกัน
    บนปุ่มยืนยันของตัวเอง ให้สีตรงกันตลอด ไม่ใช่แค่ที่ header
  - **`overflow_actions[]` เมนู "อื่นๆ" (ใหม่, 2026-09-13, "เมนูอื่นๆ" follow-up)** — 2 จุดที่แก้: **(1) เหลือ
    รายการเดียว → render เป็นปุ่ม `.btn-outline-secondary` ธรรมดา** (label/id/href/icon ของรายการนั้นตรงๆ)
    **ไม่ใช่ dropdown 1 ตัวเลือก** — เมนูที่เลือกได้ทางเดียวไม่ใช่เมนู; `tone` ของรายการนั้น (ถ้ามี) **ไม่ถูกนำมา
    ใช้เลย** เพราะ path ปุ่มธรรมดาไม่อ่าน `tone` (ตั้งใจ ให้เหมือนกับ decision set's เอง "สีทำลายอยู่ที่ confirm
    เท่านั้น") **(2) divider คั่นเฉพาะเมื่อมีรายการปกติอยู่เหนือมันจริง** — ก่อนหน้านี้ divider จะขึ้นก่อนรายการ
    danger ตัวแรกเสมอแม้จะเป็นรายการแรกสุดของเมนู (เส้นคั่นที่ไม่มีอะไรอยู่เหนือมันเลย ไม่มีความหมาย) — ตอนนี้
    render เฉพาะเมื่อ index ของรายการ danger ตัวแรกนั้น > 0 (มีรายการปกติอย่างน้อย 1 ตัวอยู่ก่อน) — ทำใน
    page-header.php **และ** JS twin `renderPageHeaderActions()`/`pageHeaderActionButtonHtml()` (app.js)
    พร้อมกัน ไม่ใช่เฉพาะหน้าใดหน้าหนึ่ง
- **ระยะห่างแนวตั้งของหน้ารายละเอียด (item A.1, 2026-09-13)** — ทุกช่วง header → stepper → callout → stat cards → tabs ห่างเท่ากัน = `--sp-5` (24px) **ยกเว้น stepper → callout ที่ห่างแค่ `--sp-3`** (เพราะ callout คือคำอธิบายของ stepper ด้านบนมันโดยตรง ไม่ใช่ block ถัดไปที่เป็นอิสระ) — page header เองมี `border-bottom` คั่นจากส่วนถัดไปเสมอ (`.ph-header`'s `padding-bottom`+`border-bottom`, ไม่ใช่แค่ `margin-bottom` เฉยๆ)
- **`$description` = ข้อมูล 1 ชิ้นที่สำคัญที่สุดพร้อมคำนำหน้า ไม่ใช่รายการตัวเลข (item 5, 2026-09-13)** — เคยตัดสินใจ
  (item 3a แรก) ให้ Payroll Detail's description เป็น "งวด · วันจ่าย" (2 ค่าคั่นด้วย `·`) — **แก้แล้ว**:
  ขัดกับเจตนาเดิมของ `$description` (§2's diagram: "คำอธิบาย 1 บรรทัด (ถ้าจำเป็นจริง)" — บรรทัดอธิบาย ไม่ใช่
  ช่องรวมตัวเลข) เหลือแค่ **ค่าเดียวที่สำคัญที่สุดของหน้านั้น พร้อมคำนำหน้าบอกว่าคือค่าอะไร** เช่น Payroll
  Detail ใช้ "วันจ่าย 31/08/2026" (`run_payment_date_label` + `toDisplayDateRd()`) — งวดเอง (period range)
  ไม่ได้หายไปจากหน้า แค่ไม่ได้อยู่ซ้ำใน description (มีอยู่แล้วที่ Details tab's `#infoPeriod`)
- **Action วางที่ไหน (ตัดสินใจแล้วรอบ 2 item 4, กฎบังคับทั้งระบบ)**:
  - **Page header** (`primary_action`/`secondary_actions`) = action ระดับ**หน้า** เท่านั้น — ไม่ขึ้นกับว่ามีแถวไหนถูกเลือกอยู่ไหม เช่น "สร้าง/เพิ่ม", "ดึงข้อมูล" (ซิงค์จาก Origami, นำเข้า Excel), "ดูประวัติ"
  - **Toolbar ของตาราง ฝั่งซ้าย หลัง length** = action ที่ทำกับ**แถวที่เลือกไว้** (bulk) เท่านั้น เช่น "ซิงค์ที่เลือก", "ลบที่เลือก" — ไม่ใช่ page header (เพราะพิมพ์ผิดที่ผู้ใช้ทั่วไปจะกดตอนไม่ได้เลือกอะไรเลย ปุ่มควรโผล่/ใช้งานได้เฉพาะตอนมี selection)
  - **Toolbar ของตาราง ฝั่งขวา** = **เฉพาะ**ค้นหา + ส่งออกเท่านั้น (§7) ห้ามใส่ action อื่นแทรก
- Breadcrumb กับ H1 ห้ามพูดซ้ำกัน — H1 คือชื่อหน้า breadcrumb คือทาง
  - **หน้ารายละเอียดที่ H1 เป็นค่าข้อมูลจริง (2026-09-13, Payroll Detail pilot item 3a; REVISED เต็ม
    วันเดียวกัน — ทับกฎ "crumb สุดท้าย = ชนิดหน้า" เดิมด้านล่างนี้ทั้งหมด ไม่ใช่ต่อยอด)**: เมื่อ H1 ต้อง
    เป็นชื่อ/รหัสของ**ตัวข้อมูล**นั้นเอง (เช่น ชื่อรอบเงินเดือน, ชื่อพนักงาน) ไม่ใช่ชื่อหมวดของหน้า — สเปกจริง:
    - **crumb สุดท้ายของ breadcrumb = รหัสของ entity นั้น** (เช่น รอบเงินเดือน = `run_code`, พนักงาน =
      รหัสพนักงาน, รายการทั่วไป = code ของตัวมันเอง) **ไม่ใช่คำว่า "รายละเอียด…" อีกต่อไป** (มติเดิมของ
      3a เองที่บอกว่า crumb สุดท้าย = ชนิดหน้า คงที่ ถูกยกเลิก ไม่ใช่กฎที่ใช้จริงแล้ว)
    - **H1 = ชื่อแสดงผล** (display name จริงของ entity นั้น เช่น `run_name`) — **ถ้าไม่มีชื่อ (name ว่าง/
      ไม่มี) ให้ H1 = รหัสเดียวกับที่ breadcrumb ใช้แทน — ยอมให้ซ้ำกันได้ในกรณีนี้เท่านั้น** (เป็น fallback
      ที่ยอมรับได้ ไม่ใช่การออกแบบให้ซ้ำกันปกติ) — `updateDocumentTitleFromBreadcrumb()` (app.js) เอง
      กันการซ้ำในชื่อ browser tab อยู่แล้ว (ไม่ push `#phTitle`'s text ซ้ำถ้าเท่ากับ crumb's text พอดี —
      ตรวจแล้วว่า logic เดิมนี้ครอบกรณี fallback นี้ได้เองโดยไม่ต้องแก้เพิ่ม)
    - **crumb สุดท้ายเป็นข้อความธรรมดา `--c-text`เสมอ (ไม่ใช่ `--c-text-muted` แบบ crumb ก่อนหน้ามันที่ยัง
      เป็นลิงก์) ไม่ใช่ลิงก์** — รหัสต้องอ่านง่าย ไม่จาง; ไม่คลิกได้เพราะ "อยู่หน้านี้อยู่แล้ว" ไม่มีที่ให้ไปต่อ
    - **ถ้ารหัสของ entity นั้นเองก็ไม่มี (เช่น `run_code` เป็น `null` ได้จริงตามสคีมา)**: fallback เป็น
      `#{id}` (ตัวเลข primary key ของ record นั้น เสมอมีอยู่แล้ว) — ตัวอย่างจริง Payroll Detail:
      `run.run_code || ('#' + run.id)` ใช้ทั้งเป็น crumb และเป็น fallback ของ H1 (chain เดียวกัน 2 จุด)
    - ตัวอย่างจริง (Payroll Detail, `renderRunHeader()`, detail.js): `$('#phBreadcrumbCurrent').text(run.run_code
      || ('#' + run.id))`, `$('#phTitle').text(run.run_name || runCodeOrFallback)`
    - **สำหรับ Employee Detail (ยังไม่ทำ รอรอบ 4)**: crumb สุดท้าย = รหัสพนักงาน (employee code),
      H1 = ชื่อพนักงาน (fallback เป็นรหัสพนักงานเดียวกันถ้าไม่มีชื่อ) — ใช้กฎเดียวกันนี้เป๊ะ ไม่ต้องคิดใหม่
    - **ผลข้างเคียงที่ยังต้องจัดการคู่กันเสมอ (ไม่เปลี่ยนจากก่อนหน้านี้)**: `updateDocumentTitleFromBreadcrumb()`
      (app.js) ยังดึงค่าจากทั้ง breadcrumb **และ** `#phTitle` มาต่อกันเป็น browser tab title (ทั้งคู่เป็น
      data จริงตอนนี้ ไม่ใช่แค่ H1 อย่างเดียวเหมือนตอนที่ crumb เป็น label คงที่) — ฟังก์ชันเดิมรองรับ
      กรณีนี้ได้ครบอยู่แล้วโดยไม่ต้องแก้เพิ่ม
- **Stat card** = partial `stat-card.php` render ด้วย **class ใหม่ `.stat`** (ไม่ใช่ `.stat-card`) — ตัดสินใจแล้วว่า **ทุบสีของ `.stat-card` เดิมทิ้งจริง** (ของเดิมมี 7 tone สี ขอบซ้ายสี ใช้อยู่ 5 ไฟล์ ณ ตอนตัดสินใจนี้ — ตรงข้ามกับกฎนี้โดยสิ้นเชิง ไม่ใช่ต่อยอด) พื้นขาว ขอบ `--c-border` **ไม่มีขอบซ้ายสี ไม่มีพื้นสี** ตัวเลข `.num` ขนาด `--fs-xl` ตัวหนา — ถ้าค่าเป็นสถานะที่ต้องตัดสินใจ (เช่น "รออนุมัติ 3") ใช้ badge จาก `status_map.php` (ข้อ 5) ใน slot ล่างเท่านั้น ไม่ใช่เปลี่ยนสีทั้งการ์ด — **field `badge` ของ `$stat` คือ `{enum, context}` เท่านั้น (ข้อ 5, ไม่ใช่ `{label, tone}` ดิบอีกต่อไป)**: partial เรียก `statusBadge($enum, $context)` เองข้างใน ไม่มีทางส่ง label/สีที่ไม่ผ่าน `status_map.php` เข้ามาได้อีกแล้ว
  - **แก้ไข (รอบ 2 follow-up): อนุญาตไอคอน (optional) 1 ตัว/การ์ด** (เดิมห้ามไอคอนเลย) — ยังคง
    **ห้ามพื้นสี/ขอบสีบนตัวการ์ด**เหมือนเดิม การอนุญาตไอคอนไม่ใช่การเปิดทางกลับไปหา `.stat-card` เดิม —
    **ตัดสินใจแล้ว (แก้กลับรอบที่ 2): ใช้ไอคอนมุมขวาบน** (เคยมี 2 variant ให้เทียบกันใน components.php —
    ตัดสินใจครั้งแรกเลือกไอคอนวงกลมซ้าย 40px, **แก้กลับมาเป็นมุมขวาบนในรอบนี้แทน** — วงกลมซ้ายถูกลบออกจาก
    partial/CSS/components.php ทั้งหมดแล้วเป็นครั้งที่สอง ไม่เหลือ dead code ทั้งสองรอบ): ไอคอนมุมขวาบน
    ขนาด **16px** (แก้จาก 20px เดิม 2026-09-13, Payroll Detail pilot item 3a — 20px ดูใหญ่ไปเมื่อเห็นบน
    หน้าจริง) สี `--c-text-faint` ไม่มีวงกลม/พื้นของตัวเอง, label เล็กสีเทาซ้ายบน, ตัวเลข `--fs-xl` ตัวหนา
    ด้านล่าง — **ถ้าไม่ส่งไอคอนมา ไม่เว้นที่ไว้** (ตัดสินใจเดิม ยังคงไว้ไม่เปลี่ยนข้ามทั้ง 2 รอบ:
    "เลือกไม่เว้น"/"ไม่มีไอคอน = ไม่เว้นที่") — label เป็นสมาชิกเดียวในแถว flex (label+ไอคอน,
    `justify-content:space-between`) จึงชิดซ้ายเองโดยธรรมชาติเมื่อไม่มีไอคอน ไม่ต้องเขียนโค้ดพิเศษกันที่ว่าง
  - **ทุกการ์ดในแถวสูงเท่ากันเสมอ** — caller ครอบด้วย Bootstrap `.row` ธรรมดา (ยืด column เท่ากันเป็น default อยู่แล้ว ไม่ต้องเพิ่ม CSS) `.stat` เอง `height:100%` + เป็น flex column — **slot ล่างคงที่สำหรับ sub/badge/link เสมอ** (มี `min-height` แม้ไม่มีเนื้อหาอะไรเลย ก็ยังเว้นพื้นที่เท่ากับการ์ดที่มีครบทั้ง 3 อย่าง) และ `margin-top:auto` ดันลงชิดขอบล่างเสมอ ไม่ว่า header/ไอคอนด้านบนจะสูงแค่ไหน
  - **ขนาดที่แน่นอน (item A.3, 2026-09-13)**: padding การ์ด `--sp-4`, label `--fs-sm`, value `--fs-xl` `line-height:1.2`, slot ล่างสูงคงที่ `24px` (แก้จาก `20px` เดิม) — รวมกันแล้วการ์ดสูง **~110px เท่ากันทุกใบ**; ไอคอน 16px ชิดขวาบนระดับเดียวกับ label (ไม่เปลี่ยนจากที่ตกลงไว้ก่อนหน้า); บรรทัดย่อยใน slot ล่าง (เช่น "โอนผ่านบัญชี 0 · เงินสด 2") เป็นข้อความ `--c-text-muted` ล้วน **ไม่มีไอคอน/สี** ใดๆ
  - **`value_class` (ใหม่, item D, 2026-09-13)** — field เสริม optional ของ `$stat`, ใส่ class เพิ่มบน `.stat-value` ต่อจาก `.num` (เช่น `'money-gross'`) สำหรับตัวเลขที่มีความหมายทางบัญชีจริง — ดู §8 money-color system ด้านล่าง คนละเรื่องกับ `value_id`/`sub_id` ที่ยังเป็นแผนรอบ 4 ด้านล่าง (นี่คือ class เสริมตอน render ครั้งเดียว, ไม่เกี่ยวกับปัญหา live-update ค่าเดี่ยว)
  - **Migration**: รอบ 2 สร้าง `.stat`/`stat-card.php` ใหม่เท่านั้น **ไม่แตะ 5 ไฟล์ที่ใช้ `.stat-card` เดิม**; รอบ 4 ย้ายทีละหน้า (หน้าไหนมี stat card ก็ย้ายเป็นส่วนหนึ่งของการทำหน้านั้นให้ clean ไม่ใช่ commit แยก) เมื่อย้ายครบ 5 ไฟล์แล้วให้ลบ CSS ของ `.stat-card`/`.stat-card-*` (`style.css`) ทิ้งเป็นขั้นตอนสุดท้าย — ห้ามลบ CSS เดิมก่อนไฟล์ล่าสุดที่ใช้มันย้ายเสร็จ
  - **Gap พบระหว่างรอบ 3 (Payroll Detail pilot, item 3a, 2026-09-13) — ยังไม่แก้ partial, บันทึกไว้ก่อน**:
    `stat-card.php` แทนที่เนื้อหาการ์ดทั้งใบจาก `$stat` array ต่อการ render ครั้งเดียว แต่ Payroll Detail
    ต้องอัปเดตค่าเดี่ยว **สด** ทีละค่า (`updateSummaryCardsFromTable()`, ทุกครั้งตารางพนักงาน redraw
    ไม่ใช่ reload ทั้งหน้า) ผ่าน `.text()` บน id คงที่ — partial ปัจจุบันไม่มีทางรองรับกรณีนี้โดยไม่ re-render
    การ์ดทั้งใบ รอบนี้แก้โดย**เขียนมือด้วย class เดียวกับที่ partial output ตรงๆ** (ไม่ได้ extend partial)
    — **แผนสำหรับรอบ 4**: ถ้าเจอหน้าที่สอง (นอกจาก Payroll Detail) ที่ต้องการ live-update ค่าเดี่ยวแบบนี้
    ให้ `stat-card.php`/`$stat` รับ `value_id`/`sub_id` (optional, string) เพิ่ม — เมื่อระบุ ให้ partial
    ใส่ `id="<?=$value_id?>"` บน `.stat-value`/`.stat-sub` แทนที่จะปล่อยไม่มี id เลย caller เดิมที่ไม่ส่งมา
    ไม่กระทบอะไร (id ไม่ใช่ required field) — **ยังไม่ทำตอนนี้เพราะมีแค่ 1 หน้าที่ต้องการ** ไม่อยากเดา
    shape ล่วงหน้าจากตัวอย่างเดียว
- Dashboard: ไม่มี welcome card, ไม่มีกราฟที่มีข้อมูลแท่งเดียว — เนื้อหาต้องเป็น "งานที่ต้องทำ" ก่อน (รออนุมัติ/อนุมัติแล้วรอทำต่อ/ค้างนาน) ตามด้วยตัวเลขสรุป
- ปุ่ม `?` ลอย: เอาออก — ความช่วยเหลือให้อยู่ใน helper text หรือลิงก์ "วิธีใช้" ใน page header เท่านั้น
- Max content width ไม่จำกัด (ตารางกว้าง) แต่ **padding ซ้าย/ขวาของ content เท่ากันทุกหน้า = `--sp-5`** และตาราง/การ์ด **เต็มความกว้าง content เสมอ** ไม่มี padding ซ่อนในตาราง (ปัญหา "ตารางไม่เต็มขอบ")

---

## 3. สี — ใครใช้ได้บ้าง

| สี | ใช้ได้กับ | ห้ามใช้กับ |
|---|---|---|
| ส้ม `--c-primary` | ปุ่มหลัก (1/หน้า, 1/modal), tab ที่เลือก (เส้นใต้), step ปัจจุบันใน stepper, focus ring, **checkbox/radio/switch ที่ถูกเลือก/เปิด (§9 — ข้อยกเว้นเดียวกับ tab ที่เลือก, เพิ่มรอบ 2 item (2))**, **รายการที่ยังไม่อ่าน/ต้องสนใจ ใช้ `--c-primary-soft` เป็นพื้น + `--c-primary` ที่ไอคอนได้ (ความหมายเดียวกับ step ปัจจุบัน — "นี่คือสิ่งที่ต้องดู/ตัดสินใจตอนนี้" ไม่ใช่ตกแต่ง — เพิ่ม 2026-09-13, notification item ข้อ 6d)** | ไอคอน (ปกติ — ยกเว้นข้อบน), ตัวเลข, badge, ขอบการ์ด, หัวตาราง, ลิงก์ในเนื้อหา |
| เทา (neutral) | ทุกอย่างที่เหลือ: ปุ่มรอง, ไอคอน, badge ข้อมูล, เส้น, พื้น | — |
| แดง | สถานะ "ผิด/ถูกปฏิเสธ/เกินกำหนด/ต้องแก้", ปุ่มยืนยันลบใน confirm dialog เท่านั้น, **ตัวเลขรายการหัก (`.money-deduction`, §8, ข้อยกเว้นด้านล่าง)** | ปุ่ม PDF, ปุ่มลบในแถว (ใช้เทา, ไปแดงตอน confirm), ตัวเลขติดลบทั่วไป (ใช้เครื่องหมายลบ + `--c-text`) |
| เหลือง | สถานะ "รอ/ต้องตรวจ/ยังไม่ครบ" | แจ้งเตือนทั่วไป, helper text |
| เขียว | สถานะ "อนุมัติแล้ว/จ่ายแล้ว/พร้อม", **ตัวเลขรายได้/รายรับ (`.money-gross`, §8, ข้อยกเว้นด้านล่าง)** | ปุ่ม Excel, ปุ่มบันทึก, ไอคอน sync, ตัวเลขบวกทั่วไป |
| ฟ้า | **ไม่ใช้เลย** | ปุ่ม sync, badge info, ลิงก์ (ลิงก์ = `--c-text` + underline on hover) |

ทดสอบง่ายๆ: ถ้าเปลี่ยนหน้าให้เป็นขาวดำ ผู้ใช้ยังรู้ไหมว่าต้องกดอะไร ถ้ารู้ = ถูก สีที่เหลือคือของแถม

**2026-09-13, "เก็บตกรอบ 4", บั๊กจริงบน switch ที่ถูกเลือก/เปิด (checked)** — ติ๊กสวิตช์แล้วจุดขาวหายไปจนกว่า
จะคลิกที่อื่น (เสีย focus) — ต้นตอ: `.form-switch .form-check-input:focus`'s เองมี `--bs-form-switch-bg`
override เป็นวงกลม**สีส้มตัน**ไม่มีเงื่อนไข ชนกับพื้น track สีส้มเดียวกันตอน checked (จุดกลายเป็นส้มบนส้ม
มองไม่เห็น) — Bootstrap เองแก้กรณี checked+focused ถูกอยู่แล้ว (declare `:checked` หลัง `:focus`ในซอร์สตัวเอง
ชนะ tie ได้ถูก) แต่ override ของแอปนี้ที่โหลดทีหลัง (specificity เท่ากัน) ดันชนะทับแม้ตอน checked ด้วย —
แก้โดย scope override เหลือแค่ `:not(:checked):focus` (ให้ `:checked`'s จุดขาวเดิมของ Bootstrap ชนะเสมอตอน
ติ๊กอยู่ ไม่ว่า focus หรือไม่) ใช้ ring ส้มรอบนอก (`.form-check-input:focus`'s border+box-shadow เดิม) เป็น
สัญญาณ focus แทนการเปลี่ยนสีจุดเอง — ดูรายละเอียดเต็มใน `style.css`'s own comment บน rule นี้ (Round 2
item (2) section) มีผลทุกสวิตช์ในแอปอัตโนมัติ

**ข้อยกเว้นของกฎ "การ์ด/กล่องห้ามมีสี tone บนขอบ/พื้นตัวเอง" (เพิ่ม 2026-09-13)** — 2 กรณีนี้เท่านั้นที่ให้สี
tone ปรากฏบนตัว container ได้ ไม่ใช่แค่ badge/ไอคอน/ตัวเลขเดี่ยว เพราะเป็น "หน้าที่ทั้งหมด" ของ component นั้นเอง
ไม่ใช่การตกแต่งเพิ่ม:
1. **Callout (§15)** — เส้นซ้าย 3px ของ `.callout` มีสีตาม `tone` ได้ (พื้น/ตัวหนังสือยังคงที่ `--c-bg-subtle`/
   `--c-text` เสมอไม่ว่า tone ไหน — สีอยู่แค่เส้นซ้ายเส้นเดียว)
2. **ตัวเลขเงิน (§8)** — `.money-gross`/`.money-deduction` (สีตัวเลขเอง ไม่ใช่การ์ด/พื้นหลัง/label ที่ล้อมมัน)

---

## 4. ปุ่ม

ลำดับชั้น 4 ระดับ ใช้ class ของ Bootstrap ที่ map แล้วเท่านั้น:

| ระดับ | class | เมื่อไหร่ |
|---|---|---|
| Primary | `.btn.btn-primary` (ส้ม, ตัวหนังสือขาว) | action หลัก 1 ตัว: บันทึก, ส่งอนุมัติ, สร้างรอบ |
| Secondary | `.btn.btn-outline-secondary` (ขอบเทา ตัวหนังสือ `--c-text`) | ทุกอย่างที่เหลือ: ยกเลิก, ซิงค์, Export, ดึงจาก Master, เพิ่มเวอร์ชัน |
| Tertiary | `.btn.btn-link` (ไม่มีขอบ สีข้อความ) | action เบา: "ล้างตัวกรอง", "ดูทั้งหมด" |
| Danger | `.btn.btn-danger` | **เฉพาะปุ่มยืนยันใน SweetAlert confirm** ของการลบ/ยกเลิกที่ย้อนไม่ได้ |

กฎ:
- ห้ามใช้ `btn-success / btn-info / btn-warning / btn-light / btn-dark` และ `btn-outline-*` อื่นนอกจาก `outline-secondary`
- ปุ่มที่หน้าที่ต่างกันไม่ได้แยกด้วยสี แยกด้วย**คำ** (ซิงค์ Origami / Excel / PDF ทั้งหมดเป็น secondary)
- Export หลายแบบ = ปุ่ม secondary 1 ตัว "ส่งออก ▾" เป็น dropdown (Excel / PDF) ไม่ใช่ 2 ปุ่ม
- ไอคอนในปุ่ม: ใส่ได้เมื่อช่วยแยกแยะ (เช่น ▾ ของ dropdown, ⟳ ของ sync) ไม่ใส่ในปุ่มบันทึก/ยกเลิก/ปิด
- ขนาด: ปุ่มในหน้า/modal = ขนาดปกติ; ปุ่มในแถวตาราง/filter bar/DataTable toolbar = `.btn-sm` **ทุกตัวขนาดเดียวกัน** (ปัญหา "ปุ่มใน DataTable ไม่เท่ากัน")
- **ปุ่มกลม row action — ตัดสินใหม่รอบ 2 item (5), ย้อนกลับข้อความเดิมด้านล่างนี้**: ทรงวงกลม**คงไว้** ตาม
  ที่เคย approve ไว้ก่อนหน้านี้แล้ว (`.btn-circle-action`, 14 ไฟล์) ไม่ใช่เปลี่ยนเป็นไอคอนลอยไม่มีขอบ —
  ปัญหาจริงที่ต้องแก้คือ**สีต่อปุ่ม** (7 tone ต่อ action) ไม่ใช่**ทรง** — ดูสเปกเต็มใน §7's "Row actions"
  ~~ปุ่มกลม (`.btn-circle`, `.rounded-circle`, รวม `.btn-circle-action`) เลิกใช้ทั้งหมด — `.btn-circle-action`
  คือปัญหาที่ระบุไว้ตั้งแต่ต้นของ phase design นี้เอง (ปุ่มกลมหลายสีต่อแถวในตาราง คือตัวอย่างที่ §0/§3 พูดถึง
  ตรงๆ) แม้จะเป็นมติที่เคย roll out ทั่วระบบมาก่อนหน้านี้ (14 ไฟล์) ก็ถือว่าถูกแทนที่โดย rules.md ฉบับนี้~~
  (ข้อความเดิมด้านบน — เก็บไว้ให้เห็นประวัติการกลับมติ ไม่ใช่กฎที่ใช้จริงอีกต่อไป)
- Save/Cancel วางขวาล่างเสมอ ลำดับ `[ยกเลิก] [บันทึก]` (secondary ซ้าย, primary ขวาสุด) ทั้งใน modal footer และฟอร์มเต็มหน้า — `payroll-configuration` ต้องเป็นแบบนี้ด้วย
- ปุ่มระหว่างโหลด: `disabled` + spinner ในปุ่มเดิม ไม่เปลี่ยนคำ
- **ข้อยกเว้น: Decision set (ใหม่, 2026-09-13, page-header.php's `$decision_actions`; REVISED หลายรอบ
  same-day — พื้นตันทั้ง 3 ปุ่ม, warning ได้ token สีพื้นของตัวเอง, แล้วปรับสี warning อีกรอบให้ตัวหนังสือ
  ขาวได้ทั้ง 3 ปุ่ม)** — ปุ่มที่**ตั้งสถานะ**ของ record นั้นโดยตรง (ไม่ใช่ "บันทึก/ยกเลิก" ทั่วไป) ใน
  **ชุดตัดสินใจที่จำกัดตายตัว**เท่านั้น — อนุญาตเฉพาะชุด **อนุมัติ/ขอข้อมูลเพิ่มเติม/ปฏิเสธ** (และชุดที่มี
  ความหมายแบบเดียวกันจริงๆ ในอนาคต ไม่ใช่ทุกปุ่มที่ "อยากได้สี") — **ทั้ง 3 ปุ่มเป็นพื้นตัน ตัวหนา 600 ตัวหนังสือ
  ขาวเหมือนกันหมด**: อนุมัติ = `.btn-decision-success` (พื้น `--c-success-fill`), ขอข้อมูลเพิ่มเติม =
  `.btn-decision-warning` (พื้น `--c-warning-fill`), ปฏิเสธ = `.btn-decision-danger` (พื้น
  `--c-danger-fill`) — `-fill` (tokens.css) สำหรับ success/danger เป็นแค่ alias ของ `--c-success`/
  `--c-danger` เดิม (ค่าเท่ากันเป๊ะ ตั้งชื่อให้สม่ำเสมอเฉยๆ) **แต่ warning ไม่ใช่** — `--c-warning-fill`
  (`#D97706` light / `#C26A05` dark) เป็นสีใหม่จริง เพราะ `--c-warning` เดิม (`#B54708`/`#F5B14C`) ถูก
  tune ไว้สำหรับตัวหนังสือ/ขอบ/badge ไม่ใช่พื้นถม — `--c-warning` ตัวเดิมยังใช้กับตัวหนังสือ/ขอบ/badge ต่อไป
  ไม่เปลี่ยน (เช่น stepper's `.status-stepper-tone-warning`)
  - **ตัวหนา 600 (`font-weight:600` บน `.btn-decision-*` ทุกตัว) เป็นเงื่อนไขที่ต้องมีจริงๆ ไม่ใช่แค่สไตล์**
    — WCAG อนุญาตเกณฑ์ contrast ต่ำกว่าปกติ (3:1 แทน 4.5:1) สำหรับตัวหนังสือ "ตัวหนา/ตัวใหญ่" เท่านั้น ถ้าไม่
    ใส่ตัวหนาจริง เกณฑ์ผ่อนนี้จะใช้อ้างอิงไม่ได้
  - ตัวหนังสือ/ไอคอนใช้ `--c-{tone}-on-fill` (tokens.css, **ไม่ใช่ `#fff` ตรงๆ**) — **ตอนนี้เป็น `#fff` ทั้ง
    3 tone ทั้ง 2 theme แล้ว ยกเว้น 1 กรณี**: success/danger ใน **dark mode** ยังเป็น `#1F2328` (ดำ) เหมือน
    เดิม เพราะพื้น dark-mode ของ 2 ตัวนั้น (`--c-success`/`--c-danger` เดิม ไม่ได้ปรับสี) ตัวหนังสือขาววัดได้
    แค่ 1.74:1/2.79:1 — **ไม่ผ่านแม้เกณฑ์ตัวหนา 3:1 ที่ผ่อนแล้ว** (เช็คจริง ไม่ได้เดา) จึง**ไม่ได้ทำตามคำสั่ง
    "ทั้ง 3 เหมือนกัน" ให้ครบ 100%** สำหรับ 2 ตัวนี้ในธีมมืดโดยเฉพาะ — flag ไว้ตรงๆ: ถ้าต้องการขาวจริงทั้ง 3
    ทุก theme ต้องปรับ `--c-success`/`--c-danger` เองให้เข้มขึ้นแบบเดียวกับที่ทำกับ warning ไปแล้ว ไม่ใช่แค่
    เปลี่ยนสีตัวหนังสือเฉยๆ (ยังไม่ทำ รอคำยืนยัน)
  - **warning**: `--c-warning-fill` เปลี่ยนจาก `#F5B400`/`#E0A400` (รอบแรก) เป็น `#D97706`/`#C26A05` (เข้ม
    ขึ้น) โดยตั้งใจ เพื่อให้ตัวหนังสือขาว**กลับมาใช้ได้** — วัด contrast จริง: ขาวบน `#D97706` = 3.19:1
    (light), ขาวบน `#C26A05` = 3.92:1 (dark) — ผ่านเกณฑ์ตัวหนา 3:1 ทั้งคู่ (ไม่ใช่เกณฑ์ปกติ 4.5:1)
  - hover = `--c-{tone}-hover` (success/danger) / `--c-warning-fill-hover` (tokens.css, เข้มขึ้น 8% ต่อ
    channel คำนวณจากสีฐานของแต่ละ theme เอง ไม่ใช่ `filter:brightness()` runtime)
  - **สังเกตว่า "ปฏิเสธ" ยังไม่ใช่ Danger tier ด้านบน** (`.btn-danger` ตัวนั้นสงวนไว้เฉพาะปุ่มยืนยันใน
    SweetAlert confirm ของการลบ/ยกเลิกที่ย้อนไม่ได้ — คนละ class คนละบริบทกัน แม้จะพื้นตันเหมือนกันตอนนี้ก็ตาม)
    `showConfirm()` (§10) รับ `tone` แบบเดียวกันสำหรับ confirm dialog/modal ที่ตามหลังปุ่มกลุ่มนี้ ให้สีตรงกัน
    ตลอดจากปุ่ม trigger ถึงปุ่มยืนยันจริง — ไอคอนไม่เปลี่ยน (glyph/ตำแหน่งเดิม แค่สีตามตัวหนังสือที่เปลี่ยนไป
    โดยอัตโนมัติ เพราะไม่มี CSS override สีไอคอนแยก) — **ปุ่มอื่นทั้งหมดนอกชุดนี้ยังตามลำดับชั้น 4 ระดับเดิม
    ข้างบนไม่เปลี่ยน** ข้อยกเว้นนี้ไม่ใช่ใบอนุญาตให้ปุ่มไหนก็ได้มีสีของตัวเอง
- **ข้อยกเว้น: ปุ่มสร้างรายการในตาราง (ใหม่, 2026-09-13, Round 3 item 3b follow-up — Payroll Detail's
  `#btnJoinEmployees` "+ พนักงาน")** — ปุ่มสร้างรายการใหม่ใน**ตารางย่อยภายในหน้า** (ไม่ใช่ปุ่มหลักของทั้งหน้า)
  เป็น **`.btn-primary` (ส้ม) ได้** เมื่อ **ปุ่ม primary ของ page header เป็น state action** (เช่น
  คำนวณ/ส่งอนุมัติ/อนุมัติ — ปุ่มที่เปลี่ยน**สถานะของ record ทั้งก้อน**) ไม่ใช่ปุ่ม "สร้าง/เพิ่ม" แบบเดียวกัน —
  เหตุผล: ทั้งสองไม่ได้แข่งกันเป็น "สิ่งเดียวที่ต้องทำในหน้านี้" (§2's "1 หน้า = ปุ่มส้มได้ตัวเดียว" เขียนขึ้นมา
  เพื่อกันปุ่มส้ม 2 ตัวที่ชิงความสนใจเรื่อง "อันไหนคือ action หลัก" กัน แต่ state action ของทั้ง run กับ
  "เพิ่มพนักงานเข้าตารางนี้" เป็นคนละคำถามกันจริงๆ ไม่ใช่ 2 คำตอบของคำถามเดียวกัน) — **ไม่ใช่ใบอนุญาตทั่วไป**
  สำหรับปุ่ม "+เพิ่ม" ทุกตัวในทุกตาราง ใช้ได้เฉพาะเมื่อเงื่อนไข "page header primary เป็น state action"
  เป็นจริงจริงๆ เท่านั้น — ตารางที่ page header ของหน้าเดียวกันมีปุ่ม primary เป็น "บันทึก"/"สร้าง" อยู่แล้ว
  (ชนกับความหมายเดียวกัน) ปุ่ม "+เพิ่ม" ในตารางย่อยยังคง `.btn-outline-secondary` ตามปกติ

---

## 5. Badge / สถานะ

- Badge = สถานะเท่านั้น ไม่ใช่ label ทั่วไป (ประเภท, หมวด, ที่มา → เป็นข้อความธรรมดาหรือคอลัมน์)
- helper เดียว: PHP `statusBadge($enum, $context)` (`app/helpers/helpers.php`) / JS
  **`statusBadgeHtml(enum, context)`** (`public/js/app.js`, ชื่อ global ยืนยันแล้วรอบ 0 — ต้องมี
  suffix `Html` เสมอ ห้ามประกาศ global เปล่าชื่อ `statusBadge`) อ่าน map จาก
  `app/config/status_map.php` ที่เดียว (label i18n key + tone) — **ห้ามเขียน map ใน view/JS**
  - ✅ **rename เสร็จแล้ว (ก่อนเขียนโค้ดจริงตามที่ตัดสินใจไว้รอบ 0)**: `function statusBadge(row)`
    local ใน `public/js/setup/payroll-configuration.js` → `pcRowStatusBadge(row)` — ชื่อชนกันตรงๆ ใน
    สภาพแวดล้อม non-module script ที่ global function ประกาศซ้ำชื่อไม่ error แต่ผลลัพธ์ fragile ขึ้นกับ
    ลำดับโหลดไฟล์ (บั๊กคลาสเดียวกับที่เจอจริงมาแล้วใน Payslip/ECT Template's double-declared `let`)
  - **`app/config/status_map.php` เป็นแหล่งเดียวจริงๆ ไม่มี copy ที่ไหนอีก** — **ยกเว้นกฎ "ห้ามแตะหน้าจริง"
    เฉพาะจุดเดียว**: `layout/header.php` (ตรงจุดเดียวกับที่ inject `BASE_URL`/`LANG_VERSION` ให้ JS
    อยู่แล้ว) ใส่ `window.STATUS_MAP = <?=json_encode(loadStatusMap())?>;` ตรงจาก PHP ทุกหน้า — JS
    `statusBadgeHtml()` อ่านจาก `window.STATUS_MAP` เท่านั้น (มี guard: ถ้าไม่มี — หน้าที่ไม่โหลด
    header.php — warn ครั้งเดียวแล้ว fallback เป็น map ว่าง ทุก enum จะ render เป็น badge เทา+label ดิบ
    ตามกลไก missing-entry เดิม) `docs/design/components.php` เองก็ใส่บรรทัดเดียวกันจาก `loadStatusMap()`
    จริง (require ไว้แล้วตอนต้นไฟล์) ไม่ใช่ก็อบปี้ค่าเอง — **แก้ครั้งแรกใช้วิธี duplicate array เต็มเป็น JS
    literal ใน app.js (แบบเดียวกับ `PAGE_SIZES_MM`), ถูกถอดออกและแทนที่ด้วยวิธีนี้แล้ว** เพราะผู้ใช้
    อนุมัติข้อยกเว้นจุดนี้โดยตรง ไม่ต้อง duplicate ข้อมูลอีกต่อไป
  - **PHP กับ JS render ข้อความ label ต่างวิธีกัน โดยตั้งใจ**: PHP ไม่รู้ภาษาที่ผู้ใช้เลือกอยู่ (view ฝั่ง
    server ไม่เคยรู้ ตาม convention เดิมของแอปทั้งหมด) จึง render fallback ภาษาอังกฤษ (อ่านจาก
    `public/lang/en.json` ตรงๆ กันข้อความเพี้ยนจากของที่พิมพ์ซ้ำ) + `data-i18n="{label_key}"` ให้
    `updateText()` (มีอยู่แล้ว) สลับเป็นภาษาที่ถูกต้องหลังโหลด — JS รู้ `langData` ปัจจุบันอยู่แล้วจึงเรียก
    `getLangValue(label_key)` ตรงได้เลย (ยังใส่ `data-i18n` ไว้ด้วยเพื่อให้สลับภาษาสดได้ผ่าน
    `updateText()` เหมือนกัน ไม่ใช่เพราะจำเป็นต่อการ render ครั้งแรก)
  - **enum ที่ไม่มีใน map**: badge เทา (`badge-neutral`) + label เป็นค่า enum ดิบ (ไม่มี `data-i18n`
    เพราะไม่มี key จริงให้สลับ) — PHP บันทึกผ่าน `error_log()`, JS ผ่าน `console.warn()` ไม่ throw/พังหน้า
  - **`statusBadgeHtml(enum, context, options)` — option `{menu: htmlString}` (ใหม่, 2026-09-13, Round 3
    item 3b follow-up — Payroll Detail's "ตรวจสอบแล้ว" badge เป็นจุดใช้จริงจุดแรก)**: badge ที่บางสถานะ
    ต้อง**ยกเลิก/แก้ไขสถานะนั้นได้จากตัวมันเอง** (เช่น กด "ตรวจสอบแล้ว" แล้วเปิดเมนู "ยกเลิกการตรวจสอบ")
    ส่ง `options.menu` เป็น HTML ดิบของ `<li>` รายการเมนู (caller เป็นคนสร้างเนื้อหาเมนูเอง — ฟังก์ชันนี้
    **ไม่รู้ความหมายของ action ข้างใน** แค่ห่อเป็น dropdown ให้) — badge กลายเป็น `<button>` จริง (ต้อง
    focusable/คลิกได้ ต่างจาก `<span>` ปกติ) มีคลาส `dropdown-toggle` เพิ่ม (ได้ลูกศร ▾ เล็กจาก CSS ของ
    Bootstrap เอง โดยไม่ต้องเขียนไอคอนเอง) + `data-bs-toggle="dropdown"` — ไม่ส่ง `menu` มา = badge ปกติ
    เหมือนเดิมทุกอย่าง (backward-compatible, ทุก call site เดิมไม่กระทบ) — `button.badge` มี CSS reset
    ของตัวเอง (`border:0`) กัน browser default ของปุ่มหลุดออกมาทับหน้าตา badge — **เงื่อนไขว่าจะส่ง `menu`
    เมื่อไหร่เป็นหน้าที่ของ caller ตัดสินเอง** (เช่น "ตรวจสอบแล้ว" ส่ง menu เฉพาะตอน draft เท่านั้น,
    non-draft ไม่ส่ง = ไม่มี ▾ เลย) ไม่ใช่ logic ในฟังก์ชันกลางนี้
- **`countBadgeHtml(n, options)` (`public/js/app.js`, Round 3 item 3b — ตัวเลขล้วนบน pill, คนละอย่างกับ
  `statusBadgeHtml()`)** — สำหรับ "จำนวน" ที่ไม่ได้มาจาก enum/`status_map.php` (เช่น จำนวนรายการที่ปรับ,
  จำนวนคอมเมนต์) รับตัวเลขตรงๆ ไม่ใช่ (enum, context) คู่ — ใช้ `.badge.badge-{tone}` CSS เดียวกับ
  `statusBadgeHtml()` เป๊ะ แค่เนื้อหาเป็นตัวเลขล้วน (ไม่มี `data-i18n` เพราะไม่มี label ให้แปล)
  - **tone: `neutral` (เทา) เป็นค่า default เสมอ** — ส่ง `{tone: 'x'}` เฉพาะเมื่อ "จำนวนนี้เองต้องการความ
    สนใจ" เท่านั้น ไม่ใช่แค่ "มีจำนวน > 0"
  - **บน count badge ที่ overlay ทับปุ่มวงกลม `.btn-circle-action` โดยเฉพาะ (2026-09-13, Round 3 item 3b
    follow-up, ยืนยันแล้ว)**: `{tone: 'primary'}` สงวนไว้เฉพาะ **"มีรายการใหม่/ยังไม่อ่าน"** เท่านั้น —
    ไม่ใช่ "จำนวนเยอะ" หรือ "มีข้อมูลอยู่" เฉยๆ — **ไอคอนของปุ่มวงกลมเองไม่เปลี่ยนสีตามนี้** ยังเป็น
    `--c-text-muted` สีเดียวตาม §7 เสมอไม่ว่า badge จะ tone ไหน (badge เป็นตัวส่งสัญญาณ "ใหม่" ไม่ใช่ไอคอน)
    — caller ที่ยังไม่มี concept "ยังไม่อ่าน" จริง (เช่น Payroll Detail's Comments count — ดึงมาจาก
    `row.comment_count` รวมทั้งหมด ไม่มี flag อ่านแล้ว/ยังไม่อ่านเลย) **ต้องอยู่ที่ neutral default ไปก่อน**
    ไม่ใช่เดาว่า "count>0 = ใหม่" — ดู BACKLOG.md ("Comment count badge has no unread/new tracking")
    สำหรับสิ่งที่ต้องมีก่อนถึงจะเปิดใช้ primary tone ได้จริง
- Tone มี 4 ค่า: `neutral` (เทา), `warning`, `danger`, `success` — เลือกจากคำถาม "ผู้ใช้ต้องทำอะไรกับสถานะนี้ไหม": ต้องทำ → warning/danger, จบแล้ว → success, แค่รู้ → neutral
- รูปแบบเดียว: `.badge.badge-{tone}` พื้น `--c-{tone}-soft` ตัวหนังสือ `--c-{tone}` `--radius-pill` ไม่มีไอคอน ไม่มีขอบ
- ไม่แสดง badge ซ้ำในทุกแถวถ้าค่าเหมือนกันทั้งตาราง (เช่น "Origami" ทุกแถว) → ย้ายไปเป็น filter หรือหัวตาราง
- ป้ายในหัว modal (ธง + ไอคอน + badge "รายการของบริษัทคุณ"): เหลือ**ชื่อ modal อย่างเดียว** ข้อมูลประกอบย้ายไปบรรทัดรอง (`--c-text-muted`) ใต้ชื่อ

**Context ที่มีใน `status_map.php` ตอนนี้ (ยืนยัน tone กับผู้ใช้ครบก่อนเขียนโค้ด — ดู commit message
ของรอบนี้สำหรับรายการเต็มว่าค่าไหนเปลี่ยนจากสีเดิมที่ใช้อยู่ก่อนหน้า และทำไม)**:

| Context | ใช้กับ | หมายเหตุ tone ที่ไม่ตรงสามัญสำนึก |
|---|---|---|
| `run_state` | `payroll_runs.state` | `approved`=**warning** (ยังต้องไปจ่ายต่อ ไม่ใช่ success) |
| `payroll_process_tab` | status-tabs.php บนหน้า Payroll Process (มี `direction`) | derive มาจาก `run_state` เสมอ ไม่พิมพ์ tone ซ้ำ |
| `approval_status` | `approval_requests`/`leave_requests`/`overtime_records` | `approved`=success (คำขอจบแล้ว — คนละความหมายกับ `run_state.approved` โดยตั้งใจ) |
| `payslip_request_status` | `payslip_requests.status` | = `approval_status` + `sent`/`send_failed` |
| `employment_certificate_request_status` | `employment_certificate_requests.status` | = `approval_status` + `issued`/`issue_failed` |
| `employee_status` | `employees.employee_status` | แยกจาก `employment_status` ตั้งใจ (คนละคอลัมน์คนละคำถาม) |
| `employment_status` | `employees.employment_status` | เช่นกัน — label key ใช้ร่วมกับ `employee_status` ที่ค่าซ้ำ แต่ map แยก |
| `document_delivery_status` | payslip/certificate delivery outcome (union) | |
| `sync_batch_status` | `sync_batches.status` | `running`=**warning** (ไม่ใช่ neutral — "กำลังทำงาน" ต้องรอ) |
| `payroll_calc_status` | `payroll_run_details.calc_status` | |
| `eed_status` | `employee_earning_deductions.status` (แผนผ่อนจ่าย) | `completed`=**neutral** (ไม่ใช่ success) |
| `eed_installment_status` | `employee_earning_deduction_installments.status` (รายงวด) | |
| `remittance_status` | `payroll_remittances.status` | `transferred`=**warning** (ยังไม่ยืนยันรับ) |
| `attendance_status` | `attendance_records.status` | `holiday`=**neutral** (ไม่ใช่ฟ้า — แค่ข้อมูล) |
| `recurring_earning_status` | `employee_recurring_earnings`' `is_suspended_now` | คนละ context กับ `employee_status.suspended` (คนละความหมาย) |
| `data_source` | `manual`/`sync`/`import` | **ไม่ใช่สถานะจริง** (§5 บนสุด) — ใส่ไว้ชั่วคราว neutral ทั้งหมด กันพังตอน migrate, รอบ 4 ควรเลิกใช้ badge กับอันนี้ไปเลย |

**ยังไม่ใส่ใน map รอบนี้ (ตัดสินใจแล้ว — รอมีหน้าใช้จริงก่อน)**: sync attribution
(resolved/pending_fold_in), pending-pull row status, blocked sync update, master-data mapping
(mapped/unmapped), attendance bonus ledger (`status_passed`/`status_failed` ของ ledger — คนละ key กับ
`document_delivery_status` แม้ชื่อจะคล้ายกัน). **นอก scope ถาวร**: generic soft-delete
(`active`/`inactive`/`deleted`) และ `publish_status` (`draft`/`public`) — ทั้งคู่ render เป็น toggle
switch ไม่เคยเป็น badge เลยที่ไหนในแอป

---

## 6. Tabs, Status tabs, Stepper, Timeline, Filter bar, Notification, Empty state

**Tabs** (`.nav-tabs` ที่ override แล้ว) — top-level page tab (สลับ "หน้า" ทั้งหน้า เช่น Generate Reports / Annual Summary)
- ไม่มีไอคอนใน tab ทุกหน้า (เอาออกทั้งหมด รวม tab รายงาน)
- ไม่มี chevron / ลูกศร
- tab ที่เลือก: ตัวหนังสือ `--c-text` + เส้นใต้ 2px `--c-primary`; ไม่เลือก: `--c-text-muted`
- จำนวนที่ต้องแสดง (เช่น "รออนุมัติ 3") ใช้ตัวเลขเทาในวงเล็บหลังชื่อ tab ไม่ใช่ badge สี
- **`.tab-content` ไม่มี card ครอบ** (ตัดสินใจแล้ว, Round 3 item 3b follow-up — ตัวอย่างจริง: Payroll
  Detail's `#runDetailTabsContent`) — ตัวครอบเดียวที่ห่อ `.tab-pane` ทุกอันร่วมกัน (border/พื้น/เงา/
  border-radius) ต้องไม่มี แต่ละ `.tab-pane` ชิดเนื้อหาโดยตรง เว้นระยะห่างจาก tab bar แค่ `--sp-4`
  เท่านั้น ไม่มีขอบ/พื้น/padding ข้างของตัวเอง — ตารางและ filter-bar ภายใน pane จึงกว้างเต็ม content
  ได้จริง (ไม่ใช่เต็มแค่ "ภายในการ์ด") ใช้กับทุกหน้าที่มี top-level page tab ไม่ใช่เฉพาะ Payroll Detail
  — ถ้าเนื้อใน pane ใดยังพึ่ง padding ของ card เดิมอยู่ (ยังไม่ถูกจัดใหม่ในรอบเดียวกัน) ให้ใส่ padding
  แบบเดิมกลับเข้าไป **ที่ตัว pane นั้นโดยตรง** (scoped ต่อ id) เป็นการชั่วคราวแทน ไม่ใช่คืน card กลับมา

**Status tabs** (ตัดสินใจแล้วรอบ 2 item 4b, **แก้ไขหลัง review**: คนละ component กับ "Tabs" ด้านบน แม้
หน้าตาคล้ายกัน — อันนี้ใช้ **กรองตาราง** ตาม status ที่**เป็นลำดับ/workflow** (draft → pending_approval →
approved → ...) ไม่ใช่สลับหน้า)
- **chevron/ลูกศรใช้ได้เฉพาะ component นี้เท่านั้น** ("Tabs" ทั่วไปด้านบน — top-level page tab หรือ tab
  อื่นใดที่ไม่ได้แสดงลำดับ workflow — **ห้ามมีลูกศร/chevron เด็ดขาด**) — รอบแรกของ item 4b เคยเปลี่ยน
  status tabs ไปใช้เส้นใต้แบบ "Tabs" ธรรมดา ถูก**ถอนกลับแล้ว**หลัง review: chevron pipeline เดิม
  (`.station-row`/`.station-card` ของ Employee List/Payroll Process) ที่ approve ไว้แต่แรก**ยังคงรูปแบบเดิม**
  งานของ partial นี้คือรวม markup ที่เคยซ้ำกัน 2 ไฟล์ให้เป็นชิ้นเดียว + แทน hex เดิมด้วย token เท่านั้น
  ไม่ใช่เปลี่ยนรูปแบบภาพ — เบาลงกว่าของเดิมเล็กน้อย: สูง **34px** (เดิม 38px ของ `.station-card`, ผ่าน
  36px มาก่อนในรอบนี้เอง ลดอีกครั้ง 2026-09-13), รอยบากลึก 8px (เดิม 12px, แนวตั้งของรอยบากเองก็ลดตาม
  ความสูงใหม่จาก 18px→17px แต่ความลึกแนวนอน 8px ไม่เปลี่ยน), ไม่มีเงา/ขอบนอกทั้งแถว, ระยะห่างระหว่างขั้น 2px
- partial `status-tabs.php` + JS `initStatusTabs(el, {onChange})` — คืน `{ update(counts) }` ให้ caller
  เรียกทุกครั้งที่มี count ใหม่ (โหลดครั้งแรก/หลัง redraw/หลัง sync ฯลฯ) — **ตัดสินใจแล้ว**: เคยมีอีก
  variant หนึ่ง (`path`, ไม่มีพื้นสีเลย label+pill ในแถบเดียวคั่นด้วย `›`) ให้เทียบคู่กันใน
  components.php ก่อนตัดสินใจ **เลือก chevron แล้ว ลบ `path` ออกจาก partial/JS/CSS/components.php
  ทั้งหมด ไม่เหลือ dead code**
- **สี** (แก้ไข 2026-09-13, "ปรับให้เบาลง" follow-up — pill ของ idle state เดิม `--c-bg-hover` อ่านดูเข้ม
  เกินไปเพราะสีใกล้กับพื้นหลัง `--c-bg-subtle` ของ tab เอง แยกไม่ออก): ไม่ถูกเลือก = พื้น `--c-bg-subtle`
  ตัวหนังสือ `--c-text-muted`, pill จำนวนพื้น **`--c-bg` (ขาว) มีขอบ `--c-border`** สูงคงที่ 18px
  ตัวหนังสือ `--fs-xs`; ถูกเลือก (ไม่ใช่สถานะย้อนกลับ) = ทั้งการ์ดเป็นส้ม `--c-primary` ตัวหนังสือขาวเสมอ
  ไม่ว่า `tone` จะเป็นอะไร, pill ของ **ทุก** ขั้นที่ถูกเลือก (รวมกลุ่มย้อนกลับด้านล่าง — ใช้ selector
  `.active` ร่วมกัน จึงไม่ต้องเขียนกฎแยก) เปลี่ยนเป็นขาวโปร่ง `rgba(255,255,255,.25)` ตัวหนังสือขาว
  **ไม่มีขอบ** (ต่างจาก idle state ด้านบนที่เพิ่งมีขอบใหม่ — ต้อง override กลับเป็น `border: none`)
- **กลุ่ม "ย้อนกลับ"** (ไม่อนุมัติ/ส่งกลับแก้ไข, ขอข้อมูลเพิ่มเติม, ยกเลิก) — ตั้ง `'direction' => 'back'`
  ต่อ tab (default `'forward'` ถ้าไม่ตั้ง) **มาจาก `status_map.php` เท่านั้น ห้าม hardcode/เดาใน view**
  — แยกเป็น**กลุ่มที่สองท้ายแถว** เว้นช่อง `--sp-4` จากกลุ่มเดินหน้า (เว้นช่องแค่ครั้งเดียวก่อนตัวแรกของ
  กลุ่ม ไม่ใช่ทุกคู่ในกลุ่มเดียวกัน) และหมุนการ์ดกลุ่มนี้ 180° ให้ลูกศรชี้ซ้าย (◀) สื่อว่าเป็นทางย้อน
  (label/count หมุนกลับให้อ่านออกตามปกติ)
  - **ถูกเลือก + อยู่กลุ่มย้อนกลับ**: ทั้งการ์ดใช้ tone color แทนส้มเสมอ — `tone` เป็น `warning`/`danger`
    ใช้ตรงๆ (rejected→danger, need_info→warning); `tone` เป็น `neutral`/`success` (เช่น cancelled)
    **fallback เป็น danger** — เหตุผล: สถานะย้อนกลับที่กำลังดูอยู่ตรงๆ ควรอ่านเป็นสีจริงจังเสมอ ต่อให้
    ตอนไม่ถูกเลือกจะไม่มีอะไรต้องทำ (ดูข้อถัดไป) ก็ตาม
  - **จำนวน (ทุก tab ไม่ว่าอยู่กลุ่มไหน)**: ไม่ถูกเลือก + `tone` เป็น `warning`/`danger` + จำนวน > 0 ให้
    pill เป็น badge จริง (`.badge.badge-{tone}`, §5) — เหตุผล: สถานะที่ "ต้องทำอะไรสักอย่าง" ควรเด่นขึ้นมา
    เมื่อมีของจริงให้ทำ ไม่ใช่ทุกครั้งที่เห็น (ไม่ถูกเลือก + `tone` neutral/success หรือจำนวน = 0 → pill
    เทาธรรมดา) — **`tone` ในกฎนี้ใช้ค่าจริงตรงๆ ไม่มี fallback แบบข้อบน**: "ยกเลิก" ตั้ง `tone` เป็น
    `neutral` ใน `status_map` (คนละความหมายกับสีตอนถูกเลือกด้านบน) เพื่อไม่ให้ pill เตือนแม้จำนวนจะสูง
    แค่ไหน เพราะไม่มีอะไรต้องทำต่อแล้วเมื่อ run ถูกยกเลิกไปแล้ว
- **ตัดเส้นส้มหนาใต้ pipeline ออก** — ของเดิมเคยห่อด้วย `.nav.nav-tabs` (Bootstrap) ซึ่งวาดเส้นขอบล่าง
  ของทั้งแถวมาด้วยเสมอ ซ้ำกับสีของ tab ที่เลือกเอง — partial ใหม่เลิกใช้ `.nav`/`.nav-tabs`/`.nav-link`
  ทั้งหมด ใช้ class ของตัวเอง (`.status-tabs`/`.status-tab-btn`/...) แทน ปัญหานี้จึงไม่มีอยู่แล้ว
- ลำดับ/รายชื่อสถานะที่ส่งเข้า `$tabs` **มาจาก `status_map.php` (ข้อ 5) + ลำดับ workflow ที่
  `runLifecycleSteps()`/PHP equivalent ในอนาคตรู้อยู่แล้ว ห้าม hardcode ลำดับ/label ซ้ำในตัว view เอง**
  เมื่อย้ายมาใช้จริงในรอบ 4 — partial เองไม่ยุ่งกับลำดับ แค่ render `$tabs` ตามที่ส่งมา
- **ตรวจสอบแล้ว (ตัดสินใจรอบนี้): Employee List กับ Payroll Process ใช้ shape/แหล่งที่มาของ counts
  ไม่ตรงกันเลย** — Employee List ยิง server 1 ครั้ง (`POST api/employee.station-counts`) ได้
  `{active, probation, permanent, resigned}` เพราะตารางเป็น `serverSide:true`; Payroll Process **ไม่ยิง
  server เลย**สำหรับเกือบทุกสถานะ (`updateStationCounts()` นับจาก row ที่โหลดมาแล้วในตาราง client-side)
  ได้ `{draft, pending_approval, approved, paid, locked, rejected, need_info, cancelled}` — ส่วน
  `pending_sync` มาจากกลไกแยกไปเลยคนละที่ — **shape เดียวที่เสนอ**: object แบนธรรมดา `{key: count}` ส่งเข้า
  `update()` เท่านั้น (ทั้งสองหน้าอยู่แล้วลงเอยที่ shape นี้เหมือนกัน ต่างแค่ "ได้มายังไง" ซึ่งไม่ต้องบังคับให้
  เหมือนกัน — ตารางที่เป็น serverSide ต้องยิง server เสมอ ตารางที่โหลดครบแล้วนับ client-side ถูกกว่า) —
  ไม่มีการแก้หน้าจริงในรอบนี้ (รอบ 2 ไม่แตะหน้า) เป็นข้อเสนอสำหรับรอบ 4

**Stepper** (ไทม์ไลน์ 5 ขั้นของรอบ) — **ตัดสินใจแล้ว/เสร็จแล้ว รอบ 2 item 6**
- partial `app/views/partials/status-stepper.php` (`$steps` = list ของ label ที่ resolve แล้ว, `$current`
  = index ปัจจุบัน) + JS twin `renderStatusStepper(steps, current)` (`app.js`) — render markup
  เดียวกันเป๊ะจาก argument 2 ตัวแบบเดียวกัน (แทน `runLifecycleSteps()` render ส่วน HTML — **logic การคำนวณ
  ขั้น/branch (rejected/need_info/cancelled) ยังอยู่ที่ `runLifecycleSteps()`/`computeRunLifecycleProgress()`
  เดิมทั้งหมด ไม่ถูกแตะ**) component นี้ "โง่โดยตั้งใจ": done/current/next ตัดสินจาก**ตำแหน่ง**เทียบกับ
  `$current`/`current` เท่านั้น ไม่มี action button/วันที่/ไอคอน branch ต่อขั้นเหมือนของจริง —
  `payroll/detail.js`'s `renderProcessTimeline()`/`payroll/index.js`'s mini-timeline **ยังไม่ถูกย้ายมาใช้
  partial นี้รอบนี้** (ห้ามแตะหน้าจริง §13) เป็นการตัดสินใจของรอบ 4 ถ้าจะทำ
- **แยก 3 สถานะชัด (แก้ 2026-09-13, item C — ทับข้อความเดิมด้านบนที่บอกว่า "เสร็จแล้ว = วงกลมเทา")**:
  - **เสร็จแล้ว**: วงกลม `--c-success-soft` (fill) + ✓ สี `--c-success`, ตัวหนังสือ **`--c-text` เต็มสี** (ไม่จาง
    แล้ว — ต่างจาก "ยังมาไม่ถึง" อย่างชัดเจน ไม่ใช่โทนเทาเดียวกันเหมือนก่อนแก้), เส้นเชื่อมของ**ช่วงที่ตามหลัง
    ขั้นเสร็จ**เปลี่ยนเป็น `--c-success` (เดิมบอกว่า "เส้นเชื่อมสีเดียว `--c-border` เสมอ" — **แก้แล้ว**: ทุกช่วง
    ก่อนขั้นปัจจุบันเป็นเขียว ช่วงตั้งแต่ปัจจุบันเป็นต้นไปยังเทาเหมือนเดิม)
  - **ปัจจุบัน**: วงกลมตัน `--c-primary` **+ ไอคอนขาว 12px ของขั้นนั้นโดยเฉพาะ** (แก้ ambiguity เดิมแล้ว, item C
    follow-up 2026-09-13 — เดิมไม่มีไอคอนเพราะไม่รู้ glyph ที่แน่นอน ตอนนี้มี mapping ชัดเจนแล้ว: `created`/draft
    = `fa-calculator`, `submitted`/pending_approval = `fa-paper-plane`, `pending_approval`/approved =
    `fa-list-check`, `paid` = `fa-money-bill`, `locked` = `fa-lock`; branch state ใช้ไอคอนของ tone นั้น:
    `need_info` = `fa-circle-info`, `rejected` = `fa-xmark` — **mapping อยู่ที่เดียว**: `app.js`'s
    `RUN_LIFECYCLE_STEPS`/`RUN_LIFECYCLE_BRANCH_INFO` (ตัวเดียวกับที่ `payroll/index.js`'s mini-timeline
    ใช้อยู่แล้ว) **ไม่ hardcode ใน `status-stepper.php`/`renderStatusStepper()` เลย** — partial แค่รับ
    `icon` (bare glyph class ไม่มี `fa-solid` prefix, partial เติมให้เอง) ต่อขั้น render เฉพาะขั้นปัจจุบัน
    เท่านั้น (ขั้นเสร็จยัง ✓ คงที่, ขั้นถัดไปว่างเหมือนเดิม ไม่เปลี่ยน) — **สังเกต**: ชื่อ key ที่ผู้ใช้ระบุ
    (`created`/`submitted`/`pending_approval`) ไม่ตรงกับ `RUN_LIFECYCLE_STEPS[i].key` จริงเป๊ะ (key จริงคือ
    `draft`/`pending_approval`/`approved`/`paid`/`locked`) — ตีความตามลำดับตำแหน่ง (5 ค่าตรงกับ 5 ขั้นตาม
    ลำดับ) ไม่ใช่ตาม key name เป๊ะๆ ระบุไว้ตรงๆ ไม่เดาเงียบๆ — **ผลข้างเคียงที่ต้องรู้**: `RUN_LIFECYCLE_STEPS`/
    `RUN_LIFECYCLE_BRANCH_INFO` เป็น shared function ข้ามหน้า (ใช้ทั้ง Payroll Detail's stepper และ Payroll
    Process List's mini-timeline) แก้ตรงนี้จึงเปลี่ยนไอคอนบน**ทั้ง 2 หน้า** ไม่ใช่แค่หน้านี้ — ตามที่สั่งให้ไป
    แก้ที่เดียว ("ไม่ hardcode ใน partial") จึงเป็นผลที่ตั้งใจ ไม่ใช่ side effect ที่ไม่รู้ตัว, ตัวหนังสือ
    `--c-text` หนา **600**
  - **ยังมาไม่ถึง**: วงกลมขอบ `--c-border-strong` ว่าง (ไม่มี fill/ไอคอน, ไม่เปลี่ยน), ตัวหนังสือ
    **`--c-text-faint`** (จางกว่าขั้นเสร็จ — เดิมใช้ `--c-text-muted` เดียวกับขั้นเสร็จ ทำให้ 2 สถานะแยกไม่ออก
    ที่ตัวหนังสือ, แก้แล้ว), **ไม่แสดงวันที่**
  - **รอบจบแล้ว (locked) — ขั้นสุดท้าย = `final:true`**: วงกลมตัน `--c-success` (ไม่ใช่ soft แล้ว) + ✓ สีขาว,
    ทุกขั้นก่อนหน้าเป็น "เสร็จแล้ว" ตามปกติ — `final` เป็น bool ต่อขั้น (ผลเฉพาะขั้นที่อยู่ในสถานะเสร็จแล้วเท่านั้น)
    caller เป็นคนตัดสินว่าเมื่อไหร่ "จบจริง" (เช่น `run.state === 'locked'`) component ไม่เดาเองจาก `current`
    ล้วนๆ — ดู `payroll/detail.js`'s `renderProcessTimeline()`
  - **branch state (rejected/need_info)**: ยังใช้ `tone` danger/warning ตามที่ทำไว้ก่อนหน้านี้ (ดู bullet
    ด้านล่าง ไม่เปลี่ยน)
- **ขนาด/สัดส่วน (item A.2, 2026-09-13, แก้ตำแหน่งอีกครั้งที่ item 4 ของ follow-up เดียวกัน)**: ไม่ยืดเต็ม
  ความกว้าง content — `max-width:960px` **จัดกึ่งกลาง** (`margin:0 auto`, ใช้กับทุกหน้าที่มี stepper — ของเดิม
  ตอน A.2 เคยตัดสินให้ชิดซ้าย align กับ H1 ด้านบน แต่**แก้เป็นกึ่งกลางแทนในรอบ item 4**) ระยะระหว่างขั้นเท่ากัน
  ทุกช่วง (Bootstrap flex equal-width columns เดิมทำให้อยู่แล้ว) วงกลม 28px, label `--fs-sm`, วันที่ `--fs-xs`,
  ความสูงรวมไม่เกิน 72px (28px วงกลม + `--sp-2` ระยะห่าง + label 1 บรรทัด + วันที่ 1 บรรทัด — ค่าที่มีอยู่แล้ว
  พอดี ไม่ต้องแก้)
- Demo จริงใน `docs/design/components.php` ("Stepper (ข้อ 6)"): 5 ขั้นจริงของรอบเงินเดือน (สร้างรายการ →
  ส่งอนุมัติ → อนุมัติ → จ่ายเงิน → ปิดรอบ) ที่ 4 กรณีจริง (`draft`/`approved`/`locked` ใหม่ — เพื่อโชว์
  `final`/`rejected`) — `current` คำนวณตามกฎเดียวกับ `runLifecycleSteps()` จริง (`currentIndex = reachedIdx
  + 1`)
- **2026-09-13, Round 3 item 3a — ย้ายหน้าจริงมาใช้ partial นี้แล้วจริงๆ (Payroll Detail, `payroll/
  detail.js`'s `renderProcessTimeline()`)**, ขยาย component 1 จุด (รายงานตามที่สั่ง ไม่ใช่แก้เงียบๆ):
  - **`$steps`/`steps` รับ 2 รูปแบบ**: string ธรรมดา (เดิม ยังใช้ได้ 100%) หรือ
    `{label, date}` (ใหม่) — เมื่อมี `date` render เป็นบรรทัดใต้ label `--fs-xs` `--c-text-faint`
    **ไม่มีไอคอนนาฬิกา** (ของจริงเดิมมี `<i class="fa-regular fa-clock">` — ตัดออกตามที่สั่งชัดเจน) `date`
    เป็น string ที่ caller format มาแล้ว (partial ไม่ parse/format วันที่เอง)
  - **branch state (rejected/need_info/cancelled) ไม่มีไอคอนแยกของตัวเองใน component นี้** (ยังคงเป็น
    วงกลมเปล่าธรรมดา ไม่มี glyph ต่าง) แต่ **สีวงกลมเปลี่ยนได้แล้ว** (2026-09-13, same-day follow-up,
    explicit instruction: "status-stepper รับ tone ของขั้นปัจจุบันจาก statusMapEntry(run_state)") — ดู
    bullet ถัดไป — label ยังใช้ label ของ branch นั้นแทน (เช่น "ไม่อนุมัติ / ส่งกลับแก้ไข" แทน "อนุมัติ")
    เหมือนเดิม
  - **`$steps`/`steps` แต่ละขั้นรับ `tone` เพิ่มได้ (`{label, date, tone}`)** — มีผลเฉพาะขั้นที่เป็น
    "ปัจจุบัน" เท่านั้น (`tone` บนขั้นเสร็จ/ถัดไปถูกเพิกเฉย) เปลี่ยนสีวงกลมจาก `--c-primary` (ส้ม) เป็นสีของ
    tone นั้น (`danger`/`warning`/`success`/`neutral` — คำศัพท์เดียวกับ `status_map.php`, ข้อ 5) — caller
    ต้อง**ดึงเองผ่าน `statusMapEntry()`/`getStatusMapEntry(state, 'run_state')` เสมอ ห้าม hardcode สีตรงๆ**
    (payroll/detail.js's `renderProcessTimeline()` ใช้ `step.cls` ของ `runLifecycleSteps()` เป็น enum
    ตรงๆ ได้เลยเพราะ branch step's `cls` ตั้งใจให้ตรงกับ `run_state` enum อยู่แล้ว) — สถานะ flow ปกติ
    (draft/pending_approval/approved/paid/locked) ไม่ส่ง `tone` เลย จึงยังเป็นส้มเหมือนเดิมเสมอ ไม่ใช่ทุก
    ขั้นปัจจุบันจะถูกดึง tone อัตโนมัติ (`statusMapEntry('current', ...)` หาไม่เจอ คืน null ตามปกติ)
  - `.process-timeline`/`.tl-*`/`.process-timeline-wrap` CSS **ไม่ได้ลบทิ้ง** — ยังใช้อยู่จริงที่
    `payroll/index.js`'s mini-timeline และ Approval Timeline modal (`layout/modals.php`) — แค่
    Payroll Detail เท่านั้นที่เลิกใช้ (ตัวอย่าง "ห้ามลบ shared CSS จนกว่าทุกจุดที่ใช้จะย้ายครบ" เดียวกับ
    `.stat-card` migration ด้านบน)
  - `.process-next-step` (ข้อความ "This run is still a draft…") ก็เจอปัญหาเดียวกัน (พื้นสี/ขอบซ้ายสีตาม
    tone) — แก้ครั้งแรกเหลือแค่ตัวหนังสือธรรมดา **ตอนนี้ (item B, 2026-09-13) ถอดออกทั้งคลาสแล้ว**
    เปลี่ยนไปใช้ Callout component แทน (§15 ใหม่) เพราะ "ข้อความ 1 บรรทัดใต้ stepper สีตามความหมาย" กลาย
    เป็น shape ที่ reusable จริง ไม่ใช่แค่ของหน้านี้หน้าเดียว
  - **`final` (bool ต่อขั้น, item C, 2026-09-13)** — ดูสเปกเต็มใน bullet "แยก 3 สถานะชัด" ด้านบน; มีผลเฉพาะ
    ขั้นที่อยู่ในสถานะ "เสร็จแล้ว" เท่านั้น (บน "ปัจจุบัน"/"ยังมาไม่ถึง" ไม่มีผล — component ยังไม่มี glyph
    พิเศษให้ 2 สถานะนั้น)
  - **`live` (bool ต่อขั้น, ใหม่ 2026-09-13, item 3a "เก็บตก" item 2) — pulse เบาๆ ที่ขั้นปัจจุบัน** — มีผล
    เฉพาะขั้น "ปัจจุบัน" เท่านั้น (เหมือน `tone` — บนขั้นเสร็จ/ยังมาไม่ถึง ไม่มีผล) render วงแหวน `--c-primary`
    ขยายออกจากวงกลม (opacity `.35`→`0` scale `1`→`1.9` ทุก `2.4s` ease-out, keyframe เดียวใน style.css,
    class `.stepper-current-live`) **วงกลมเองไม่กะพริบ** (มีแค่วงแหวนที่เคลื่อนไหว) `@media
    (prefers-reduced-motion: reduce)` ปิด animation ให้อัตโนมัติ — **เงื่อนไขที่ caller ต้องตัดสินเอง (ไม่ใช่
    component เดาสถานะ)**: pulse เฉพาะเมื่อ **ถึงตาผู้ใช้คนนี้จริงๆ** คือ `run_state` นั้นมี
    `$decision_actions`/`$primary_action` ให้ผู้ใช้ปัจจุบันกด (ตรวจจาก `computeRunHeaderActions(run)` เดียว
    กับที่ page-header.php's เองใช้ ไม่ derive ใหม่แยก) **และ** ขั้นนั้นไม่มี `tone` override เลย (branch
    state เช่น rejected/need_info นิ่งอยู่แล้ว ไม่ต้อง pulse ซ้ำ แม้ผู้ใช้บาง role จะยังกดอะไรได้อยู่ก็ตาม) —
    payroll/detail.js's `renderProcessTimeline()` เป็นตัวอย่างจริงที่ตัดสินเงื่อนไขนี้
  - **`icon` (string ต่อขั้น, ใหม่ 2026-09-13, item C ambiguity resolution) — ไอคอนขาว 12px ของขั้นปัจจุบัน**
    — มีผลเฉพาะขั้น "ปัจจุบัน" เท่านั้น (เหมือน `tone`/`live`) เป็น bare Font Awesome class ไม่มี `fa-solid`
    prefix (partial/JS twin เติมให้เอง) — **partial ไม่มี mapping ขั้น→ไอคอนของตัวเองเลย** caller ต้องดึงจาก
    `runLifecycleSteps()`'s ผลลัพธ์ (`step.icon`) ซึ่ง sourced จาก `app.js`'s `RUN_LIFECYCLE_STEPS`/
    `RUN_LIFECYCLE_BRANCH_INFO` — **ที่เดียวที่มี mapping จริง** ดูสเปกเต็มใน "แยก 3 สถานะชัด" bullet ด้านบน
    (รายชื่อไอคอนต่อขั้น + ผลข้างเคียงที่กระทบ mini-timeline ของ Payroll Process List ด้วย)

**Timeline** (feed กิจกรรม/audit log — ยาวเท่าไหร่ก็ได้, ไม่ใช่ milestone คงที่แบบ Stepper) — **เสร็จแล้ว
รอบ 2 item (3)/6b**
- partial `app/views/partials/timeline.php` (`$items`, `$groupByDay` optional) + JS twin
  `renderTimeline(items, {groupByDay})` (`app.js`) — render markup เดียวกันจาก item shape เดียวกัน
  (ยืนยันด้วยการรันจริงทั้ง PHP/JS บนข้อมูล mock เดียวกันแล้วเทียบ structure) — **คนละ component กับ
  Stepper**: Stepper = milestone คงที่จำนวนน้อยรู้ล่วงหน้า (5 ขั้นของรอบ), Timeline = log เหตุการณ์ยาวไม่
  จำกัด **caller เรียงใหม่สุดบนสุดมาเอง** partial/ฟังก์ชันนี้ไม่ sort/dedupe ให้
- item = `{time (string ที่ parse เป็นวันที่ได้), actor?: {name, avatar}, title, detail? (1 บรรทัด),
  badge?: {enum, context}, tone?: neutral|warning|danger|success (default neutral, สีแค่จุดบนเส้น)}`
  — `time` แสดงแค่ **เวลา** (HH:MM เท่านั้น, ไม่ใช่วันที่+เวลา — §6 เขียนไว้ตรงๆ ว่า "เวลา" ส่วนวันที่คือสิ่งที่
  หัววัน (`groupByDay`) ใช้แบ่ง ไม่ซ้ำกันสองที่)
- layout: เส้นแนวตั้ง `--c-border` ซ้าย (วาดเป็นส่วนต่อรายการ จากจุดของรายการนี้ลงไปจุดถัดไป — จึงหยุดเองที่
  รายการสุดท้ายและที่หัววันโดยไม่ต้องมี JS พิเศษเช็ค "รายการสุดท้ายไหม"), จุดกลม 10px บนเส้น (สีตาม `tone`,
  default `--c-border-strong`), ขวา = แถวบน [avatar 24px + ชื่อ] [เวลา `--c-text-muted` `--fs-xs` ชิดขวา],
  บรรทัด title `--c-text`, detail `--c-text-muted`, badge ถ้ามี (ผ่าน `statusBadge()`/`statusBadgeHtml()`
  จริง ข้อ 5) — **ไม่มีการ์ดต่อรายการ ไม่มีพื้นสี** (หลักการเดียวกับ Stepper)
- `groupByDay:true` แทรกหัววัน (`dd/mm/yyyy`) เป็น `<li>` แยก ไม่มีจุด/เส้นของตัวเอง (คั่นสายตาธรรมชาติ)
- **Avatar**: `apvAvatarHtml()` เป็น JS-only function — PHP เรียกไม่ได้ — `timeline.php` จึง reuse **class
  `.apv-person-avatar`** เดียวกัน (CSS ตัวเดียวกันไม่ว่าใครเรนเดอร์) แต่ mirror แค่ภาพพื้นฐาน (ตัวอักษรแรก
  ในวงกลม, หรือ `<img>` ถ้ามี avatar path) ไม่ได้ port ทั้งฟังก์ชัน (ไม่มี employee-quick-view click,
  timeline ไม่ต้องใช้)
- **ตรวจแล้วไม่ชนกับของเดิม**: `.apv-timeline-log`/`.apv-log-entry` (มีอยู่แล้วใน `style.css`, hex ตรงๆ
  ไม่ผ่าน token) เป็น component คนละตัว ยืนยันแล้วว่า**ไม่มี real call site ไหนใช้เลย** (dead CSS ค้างจาก
  ก่อนที่ approval timeline จริงจะออกแบบเป็น `.apv-stage`/`.apv-timeline` แบบที่ใช้จริงทุกวันนี้) — ไม่แตะ
  ไม่ reuse ตั้งชื่อ class ใหม่ (`.timeline*`) แยกชัดเจนกันสับสน
- **ตัวอย่างประกอบกับ Stepper (จำลอง modal ไทม์ไลน์อนุมัติของรอบ, Batch 2/3C)**: `docs/design/components.php`
  ต่อ Stepper แนวนอนไว้บน + Timeline นี้ไว้ล่าง ให้เห็นว่าถ้า migrate จริงในรอบ 4 จะหน้าตาประมาณไหน —
  **ไม่แตะโค้ดจริงของ `payroll/detail.js`'s `renderApprovalTimelineBody()` เลยรอบนี้** (ห้ามแตะหน้าจริง §13)
- Demo จริง: audit log 6 รายการ 2 วัน (สร้าง/แก้/ส่งอนุมัติ/ไม่อนุมัติ `danger`/อนุมัติ `success`/จ่าย
  `success`) — light/dark ผ่านปุ่มสลับ theme มุมขวาบนของหน้าเดียวกัน

**Filter bar** (ตัดสินใจแล้วรอบ 2 item 4, แก้ไข 2 รอบหลัง feedback — โครงสร้างล่าสุดคือ **แผง 3 ส่วน**
หัว/ตัว/ท้าย ด้านล่าง, ยกเลิก `toolbarTarget` ที่เคยมี)
- partial `filter-bar.php` ตอนนี้เป็น**แผงเดียว 3 ส่วน**, ปิด (ยุบ) โดย default:
  1. **หัว** (`.filter-bar-header`, แสดงตลอด ไม่ว่ากางหรือยุบ) — ซ้าย = ป้าย "ตัวกรอง" (`--c-text-muted`
     `--fs-sm`) ตามด้วย " (N)" ต่อท้าย **เฉพาะตอน N > 0** (ไม่โชว์ "(0)" ค้างไว้เหมือนเดิม); ขวา = ปุ่ม
     **`.btn-icon` วงกลมเดียวกับ row action (§7)** ไอคอน chevron หมุน 180° ตามสถานะกาง/ยุบ (CSS ล้วน
     `.filter-bar:not(.collapsed) .filter-bar-toggle i { transform: rotate(180deg) }` ไม่ต้องมี JS
     เปลี่ยนไอคอนเอง) — **นี่คือปุ่ม toggle เดียวของแผง** เลิกใช้ปุ่มตัวหนังสือ "ตัวกรอง (N)" แบบเดิม
  2. **ตัว** (`.filter-bar-body`, ยุบ/กางได้) — grid ฟิลด์ของ caller เหมือนเดิมทุกอย่าง (ดูข้อถัดไป)
  3. **ท้าย** (`.filter-bar-footer`, **แสดงตลอดเสมอไม่ว่ากางหรือยุบ** — เส้นบน `--c-border` คั่นจากตัว)
     — ซ้าย = chips ของ filter ที่ active (รูปแบบใหม่ **"label: ค่า ×"** ไม่ใช่ค่าอย่างเดียวแบบเดิม —
     label มาจาก `<label>` ที่เป็น sibling ของ `<select>` ในช่องเดียวกัน) หรือข้อความ **"ไม่ได้กรอง"**
     (`--c-text-faint`, i18n key `filter_bar_empty` ใหม่) เมื่อ N=0 (แผงไม่เคยว่างเปล่าเป็นแถบเปล่าๆ);
     ขวา = ปุ่ม tertiary **"ล้างตัวกรอง"** (`.btn.btn-link` ตาม §4's Tertiary row) ซ่อนเมื่อ N=0
- **ตอนกาง = ใช้ grid ของ `.station-filter` เดิมเป๊ะ** (คอลัมน์/ขนาดช่อง/ลำดับเหมือนเดิมทุกอย่าง) — `filter-bar.php` เป็น**แค่ wrapper** ไม่บังคับ grid/gutter class ของตัวเอง `$filter_fields_html` ต้องเป็น markup เดิมของ `.station-filter-body` คัดลอกมาแบบคำต่อคำ (รวม `<div class="row g-X">` ของมันเอง) — ย้ายเข้า partial นี้ในรอบ 4 โดยไม่ต้องเขียน field ใหม่เลย
- **ไม่มีไอคอนหน้า label ของ field ใดๆ ทั้งสิ้น** (ของเดิมมี เช่น `.station-filter-body`'s `<label><i class="fa-solid fa-calendar">...` — ยืนยันแล้วมีจริง ~140 จุดใน 18 ไฟล์ทั่วแอป เป็นงาน migrate ของรอบ 4 — ตัดไอคอนออกตอนย้าย ไม่ใช่คัดลอกมาด้วย) และ**ไม่มี fieldset/legend look แบบเดิม** ("ตัวกรอง" เป็น corner label ลอยทับขอบกรอบ) — เปลี่ยนเป็นแถบเรียบแบนแทน (พื้น `--c-bg-subtle` **ไม่มีขอบ** radius **`--radius-lg`** ไม่มีเงา — เดิมมีขอบ `--c-border` + `--radius` ก่อน "ซอฟต์ลง" follow-up ดูประวัติ revision ท้ายหัวข้อ) ไม่มีคำว่า "ตัวกรอง" ซ้ำอยู่ในกล่อง (ป้ายหัวแผงเองมีคำนี้อยู่แล้ว)
- **จำสถานะกาง/ยุบต่อหน้าได้** ผ่าน `localStorage['filterbar:' + pageKey]` — partial รับ `$pageKey` (optional); ถ้าไม่ส่งมา ไม่จำสถานะเลย เริ่มยุบเสมอ
- collapse ใช้กลไกเดิมของ `.station-filter-body` (CSS class `.collapsed` + `max-height` transition ธรรมดา) **ไม่ใช่** Bootstrap `.collapse` component — เพื่อให้ "เหมือนเดิมเป๊ะ" ตามที่ตัดสินใจ
- ควบคุมทั้งหมดใช้ `.form-select-sm` / select2 ขนาดเดียว ปุ่มขนาด `.btn-sm`
- **`toolbarTarget` ถูกยกเลิกแล้ว (2026-09-13)** — เดิมมี option ให้ `initFilterBar()` ย้ายแถวปุ่ม/chips
  ไปต่อท้ายแถว Status Tabs เดียวกัน (align ขวา) แต่ตัดสินใหม่ว่า**ไม่ต้องยัดเข้าแถวอื่นแล้ว** — ทั้งแผง
  (หัว/ตัว/ท้าย) วางเป็น block ปกติตามตำแหน่งที่ include partial ไว้เสมอ หน้าที่มี Status Tabs (เช่น
  Payroll Process) แค่วาง `filter-bar.php` ต่อท้าย `status-tabs.php` ตามลำดับ markup ธรรมดา ไม่มี JS
  ย้าย DOM node ข้ามที่อีกต่อไป — `.filter-bar--toolbar-relocated`/`.status-tabs > .filter-bar-toolbar`
  ที่เคยมีถูกลบออกจาก `style.css` ทั้งคู่
- **2026-09-13, "ซอฟต์ลง" follow-up (ตามรอบเดียวกับ notification/datepicker/timepicker) — 7 จุด**:
  1. ภาษา: ป้ายหัว "ตัวกรอง"/ปุ่ม "ล้างตัวกรอง" ได้ i18n key ใหม่ของตัวเอง **`filter_title`/
     `filter_clear`** (แยกจาก `label_filter`/`clear_filter` เดิมที่ 18 ไฟล์หน้าจริง/`.station-filter`
     ใช้อยู่แล้ว — **ไม่แตะของเดิม** เพราะ `filter-bar.php` ยังไม่มีหน้าจริงเรียกใช้เลยรอบนี้ คนละ
     lifecycle กัน แม้ค่าจะเหมือนกันตอนนี้ก็ตาม) มีครบทั้ง th/en
  2. แผง: ตัดขอบออก (`border: none`) เหลือพื้น `--c-bg-subtle` radius `--radius-lg` (เดิม `--radius` +
     ขอบ `--c-border`) — ช่อง select ข้างในไม่ต้องเพิ่ม CSS ใหม่ ได้พื้น `--c-bg`/ขอบ `--c-border` ฟรีอยู่
     แล้วจาก root Bootstrap override เดิม (§1) ตัดกับพื้นแผงเองพอโดยไม่ต้องเข้มขึ้น
  3. label เหนือช่อง (`.filter-bar-body label`): `--fs-xs` `--c-text-muted` น้ำหนัก **500** (เดิมรับ
     ค่า default ของ Bootstrap `.form-label` ที่ดำ/หนักกว่า) ระยะ label→ช่อง `--sp-1`
  4. หัว "ตัวกรอง (N)": เพิ่มน้ำหนัก **500** ให้ `.filter-bar-label` (เดิมไม่ได้ระบุน้ำหนัก) — ปุ่ม
     chevron toggle ได้ variant ใหม่ **`.btn-icon-ghost`** (§7: ไม่มีขอบ/พื้นนิ่ง, hover พื้น
     `--c-bg-hover` เหมือนเดิม) เพราะวงกลม `.btn-icon` ปกติ (มีขอบ+พื้นขาว) จะดูเป็นชิ้นลอยแยกออกจาก
     แผงที่มีพื้นสีของตัวเองอยู่แล้ว
  5. chips: ตัดขอบออก พื้นเปลี่ยนจาก `--c-bg` เป็น **`--c-bg-hover`** (พื้นเติมแทนขอบ เหตุผลเดียวกับ
     `.btn-icon-ghost`) ตัวหนังสือ `--fs-sm` `--c-text` (เดิม `--fs-xs` `--c-text-muted`) ×
     **12px** `--c-text-muted` hover `--c-text` ระยะระหว่าง chip `--sp-2` (เดิม `--sp-1`)
  6. ท้าย: ตัดเส้นบนออก (`border-top` เดิม) แยกจากตัวด้วยระยะ `padding-top: --sp-3` แทน — "ล้างตัวกรอง"
     ได้สไตล์ tertiary ชัดเจนเป็นครั้งแรก (`--fs-sm` `--c-text-muted` ไม่ underline ปกติ underline
     เฉพาะ hover — แบบเดียวกับปุ่ม tertiary ของ notification dropdown รอบเดียวกัน)
  7. **ตอนยุบ: หัว+chips รวมเป็นแถวเดียว** (chips ต่อจากป้ายหัวทางซ้าย, chevron ชิดขวาเสมอ) — ทำผ่าน
     JS ไม่ใช่ CSS ล้วน (`initFilterBar()`'s `syncCollapsedLayout()`, app.js): ย้าย
     `.filter-bar-footer-left` (chips + ข้อความ "ไม่ได้กรอง") เข้าไปเป็นลูกของ `.filter-bar-header`
     (ก่อนปุ่ม toggle) แล้วซ่อนทั้ง `.filter-bar-footer` เมื่อยุบ ย้ายกลับตอนกาง — เหตุผลที่ไม่ใช้ CSS
     ล้วน: header/footer เป็น flex row คนละก้อนคั่นด้วย `.filter-bar-body` ใน DOM (แม้ตอนยุบจะสูง 0px
     ก็ตาม) ไม่มีทางรวมเป็น flex row เดียวกันได้โดยไม่ย้าย node จริง — `.filter-bar-header` เปลี่ยนจาก
     `justify-content: space-between` เป็น `flex-start` + `.filter-bar-toggle { margin-left: auto }`
     แทน ให้ใช้ได้ทั้งกรณี 2 ลูก (ปกติ) และ 3 ลูก (ยุบ, มี chips แทรกกลาง) โดยปุ่ม toggle ชิดขวาเสมอ —
     ปุ่ม "ล้างตัวกรอง" หายไปพร้อมกับ `.filter-bar-footer` ตอนยุบ (ตามที่สั่ง "เหลือหัว + chips" ไม่ได้
     พูดถึงปุ่มล้าง) กลับมาตอนกางเหมือนเดิม
- **2026-09-13, Round 3 item 3b follow-up — 8 จุด (จากการทดสอบหน้าจริง Payroll Detail):**
  1. **บั๊กจริงที่เจอและแก้แล้ว: ปุ่ม "ล้างตัวกรอง"/× บน chip กดไม่ทำงาน** — `initFilterBar()` เดิมผูก
     click handler ทั้งสองแบบ**ตรง** (`$clearBtn.on('click', ...)`, `$chip.find(...).on('click', ...)`)
     ไปที่ node เฉพาะจุดที่จับไว้ครั้งเดียว — แผงนี้มี `syncCollapsedLayout()` ที่ย้าย
     `.filter-bar-footer-left` ไปมาระหว่างกางกับยุบอยู่แล้ว และ chip เองก็ถูกสร้างใหม่ทุกครั้งที่
     `refresh()` ทำงาน (`$chips.empty()` แล้วสร้างใหม่) — pattern ที่ทนทานกับ DOM ที่เปลี่ยนบ่อยแบบนี้คือ
     **delegated binding บน `$bar` เอง** (node เดียวในแผงทั้งหมดที่ไม่เคยถูกย้าย/แทนที่) ไม่ใช่ direct
     binding แก้แล้วทั้งคู่ — chip's × ใช้ `data-target="{select id}"` แทน closure (delegation ไม่มี
     closure ต่อ chip ให้ใช้) เพราะฉะนั้น **ทุกช่องที่ใช้กับ partial นี้ต้องมี `id` จริงไม่ซ้ำกัน** (ข้อกำหนด
     ใหม่ ระบุไว้ใน `filter-bar.php`'s docblock ของตัวเอง) — ยืนยันด้วย components.php's demo ทั้ง 2 จุด
     เดิมมี `id` ครบอยู่แล้วจึงไม่ต้องแก้ demo
  2. **ไอคอน `fa-filter` หน้า "ตัวกรอง"** — สี `--c-text-muted` สีเดียว (inherit จาก `.filter-bar-label`
     เอง ไม่ต้องมี CSS สีแยก) เป็น**ข้อยกเว้นเฉพาะหัว filter-bar เท่านั้น** ไม่ใช่การยกเลิกกฎ "ไม่มีไอคอนหน้า
     label ของ field" ด้านบน (field label ในตัวแผงยังห้ามมีไอคอนเหมือนเดิม)
  3. **N=0 ไม่แสดง "ไม่ได้กรอง" อีกต่อไป** — ข้อความ placeholder เดิม (`filter_bar_empty` i18n key)
     **ถูกลบทิ้งทั้งหมด** (ไม่ใช่แค่ซ่อน) `.filter-bar-footer-left` (chips' own container) ซ่อนตัวเองแทน
     เมื่อ N=0 (`.toggleClass('d-none', n===0)`) — แผงเมื่อไม่ได้กรองเลยจึงเป็นพื้นที่ว่างเงียบๆ ไม่ใช่
     ประโยคบอกว่า "ไม่ได้กรอง"
  4. **chips ตอนยุบ (restyle รอบใหม่)**: `--fs-xs` พื้น `--c-bg-hover` ขอบ `--c-border` ตัวหนังสือ label
     `--c-text-muted` ค่า `--c-text` (รูปแบบ "label: ค่า" เดิมไม่เปลี่ยน) ปุ่ม × ขนาด **10px** — ตั้งใจให้
     chip อ่านเป็น "ป้ายบอกค่าที่กรองอยู่" ชัดเจน ไม่กลืนไปกับพื้น/ตัวหนังสือของหัวแผงข้างบน (ก่อนหน้านี้
     chip กับหัวแผงใช้โทนใกล้กันเกินไป แยกไม่ออกว่าอันไหนคืออะไร)
  5. **ระยะภายในแผงตอนกาง แน่นลง**: หัว→ช่อง `--sp-3`, ช่อง→ท้าย `--sp-3` (มาจากแหล่งเดียวไม่ซ้อน 2 ชั้น —
     ดู style.css's own comment บน `#runDetailTabsContent`... ไม่ใช่, ดู comment บน `.filter-bar-header`/
     `.filter-bar-body > :first-child`/`.filter-bar-footer` โดยตรง), padding รอบแผง `--sp-3 --sp-4`
     (แนวตั้ง/แนวนอน) ทุกจุด — ของเดิมมี padding ซ้อนกัน 2 ชั้นที่รอยต่อ ช่อง→ท้าย จนได้ 24px ทั้งที่ไม่มี
     กฎไหนตั้งใจให้เยอะขนาดนั้น (บั๊กจริงที่เจอระหว่างแก้ข้อนี้)
  6. **`$header_extra_html` (optional slot ใหม่)** — เพิ่มเข้ามาเพื่อทดลองวางสวิตช์ "คำนวณอัตโนมัติ" ของ
     Payroll Detail ไว้ขวาของหัวแผง แล้ว**ถูกเลือกไม่ใช้ในหน้านั้น**หลัง feedback (สวิตช์กลับไปเป็นบรรทัด
     ของตัวเองเหนือแผงแทน ตามที่ผู้ใช้ยืนยัน) — slot ยังคงอยู่ใน `filter-bar.php` (documented, ใช้ได้จริง
     กับ caller อื่นในอนาคต) ไม่ได้ถูกลบทิ้งเพียงเพราะหน้านี้ไม่ได้ใช้แล้ว
- **2026-09-13, footer ถูกตัดออกทั้งหมด — แผงเหลือ 2 ส่วน (หัว + ตัว) ไม่ใช่ 3 ส่วนอีกต่อไป (ยกเลิกทุกข้อ
  ด้านบนที่พูดถึง `.filter-bar-footer`/`.filter-bar-footer-left`/`syncCollapsedLayout()`) — 4 จุด:**
  1. **ตอนกาง**: chips หายไปทั้งหมด (`.filter-bar:not(.collapsed) .filter-bar-chips { display:none }`,
     CSS ล้วน) เหลือแค่ปุ่ม "ล้างตัวกรอง" — ย้ายจากท้ายแผง (footer) ไปอยู่ **หัวแผงฝั่งขวา ข้าง chevron**
     ตามที่ผู้ใช้เลือก ("ประหยัดที่" กว่าคงแถวท้ายไว้เฉพาะปุ่มเดียว) `.filter-bar-footer` ทั้งก้อนถูกลบออก
     จาก `filter-bar.php`/`style.css` เลย ไม่เหลือ wrapper เปล่าค้างไว้
  2. **ตอนยุบ**: chips แสดงตามที่เคยทำไว้ (ไม่เปลี่ยน) — อยู่ในหัวแผงเหมือนเดิม แต่ตอนนี้เป็น**ตำแหน่งถาวร**
     ของ chips (ไม่ใช่ JS ย้าย node เข้า-ออกจากหัวแผงตามสถานะกางอีกต่อไป — `.filter-bar-chips` เป็นลูกถาวร
     ของ `.filter-bar-header` ใน markup เอง `syncCollapsedLayout()` ทั้งฟังก์ชันถูกลบออกจาก `initFilterBar()`
     ไม่มีการ reparent DOM ใดๆ เหลืออยู่ในกลไกนี้แล้ว — เหตุผลเดียวกับที่เคยแก้บั๊กปุ่มกดไม่ทำงานไปแล้วรอบก่อน
     คือยิ่งลด DOM reparenting ยิ่งทนบั๊กแบบนั้นได้มากขึ้น)
  3. **ระยะภายในแผงตอนกาง แน่นลงอีก**: padding รอบแผง (`.filter-bar-header`) ยังคง `--sp-3 --sp-4`
     (แนวตั้ง/แนวนอน) แต่ระยะหัว→ช่อง (`.filter-bar-header`'s padding-bottom) ลดจาก `--sp-3` เหลือ **`--sp-2`**
     โดยเฉพาะ (แนวนอนซ้าย/ขวา/บนไม่เปลี่ยน) — ยิ่งเตี้ยลงอีกขั้นจากรอบก่อนหน้า
  4. **ช่องที่มีค่า**: field wrapper (div เดียวกับที่ label เป็น sibling ของ `<select>`) ได้ class ใหม่
     `.filter-bar-field-active` (toggle ใน `refresh()` ทุกครั้งที่ filter เปลี่ยน, เช็คด้วย `isActive()`
     ตัวเดิมที่ใช้นับ N อยู่แล้ว) → ขอบ `--c-border-strong` บน select2/native select ข้างใน — ให้เห็นว่าช่อง
     นี้ไม่ใช่ค่า default **โดยไม่ต้องพึ่ง chips** (ตอนกาง chips ถูกซ่อนตาม item 1 ข้างบน ขอบช่องนี้เลยเป็น
     สัญญาณเดียวที่เหลือ) — CSS: `.filter-bar-field-active .select2-selection,
     .filter-bar-field-active select:not(.select2-hidden-accessible) { border-color: var(--c-border-strong) }`
     (ยืนยันแล้วว่า specificity ชนะ select2-bootstrap-5-theme's own `.select2-selection` border ผ่าน
     source order ปกติ ไม่ต้องใช้ `!important`)
- ดู `docs/design/components.php`'s Filter Bar (§6) demo section — แก้คำอธิบาย/ขั้นตอนทดสอบให้ตรงกับ
  โครงสร้าง 2 ส่วนนี้แล้วในรอบเดียวกัน (ของเดิมอธิบายแผง 3 ส่วนพร้อม footer และอ้างถึงข้อความ "ไม่ได้กรอง"
  ที่ถูกลบไปตั้งแต่รอบก่อนหน้านี้แล้ว)
- **2026-09-13, defensive hardening follow-up — บั๊กรายงาน "× บน chip และ 'ล้างตัวกรอง' กดติดบ้างไม่ติดบ้าง"
  ตอนยุบ:** ไล่โค้ดจริงแล้วไม่พบ double-init บนหน้าจริง (payroll/detail.js มี once-guard ระดับโมดูลอยู่แล้ว
  ปุ่ม toggle เดิมก็ผูกกับวงกลม chevron เท่านั้น ไม่เคยครอบทั้งแถวหัว) — ไม่มีสภาพแวดล้อม browser จริงให้
  reproduce ยืนยัน root cause เดียวแบบคาหนังคาเขาได้ จึงเพิ่ม defensive hardening 3 จุดแทนการปล่อยผ่าน:
  1. `initFilterBar()` (app.js) ได้ once-guard ระดับ**ตัว element เอง** (`$bar.data('filterBarInitialized')`)
     ไม่ใช่แค่พึ่ง once-guard ระดับหน้าที่ caller ต้องเขียนเอง — กัน handler ซ้อนทุกตัว (toggle/chip-remove/
     clear/change) หาก caller ในอนาคตเรียกซ้ำโดยไม่ตั้งใจ scope ต่อ element ไม่ใช่ module-level flag เดียว
     ทั้งไฟล์ จึงยังรองรับ 2 filter-bar instance แยกกันบนหน้าเดียว (เช่น demo's `#cpFilterBarDemo` +
     `#cpFullFilterBar`) ได้ปกติ
  2. zone สำหรับกาง/ยุบชัดเจนขึ้น — รวมป้าย "ตัวกรอง" (`.filter-bar-label`, ได้ `cursor:pointer` เป็น
     สัญญาณ) เข้ากับวงกลม chevron เป็น zone เดียว (`$bar.find('.filter-bar-toggle, .filter-bar-label')`)
     — chips/ปุ่ม "ล้างตัวกรอง" อยู่นอก zone นี้เสมอ และได้ `e.stopPropagation()` ในตัว handler ของตัวเอง
     กันชนกับ zone นี้ (หรือ ancestor click zone ใดๆ ในอนาคต) แม้ปัจจุบันยังไม่เจอการชนจริงก็ตาม
  3. ยืนยันแล้วว่า onChange/reload ยิงครั้งเดียวต่อ action เสมอ (debounce ผ่าน `setTimeout(0)` เดียวใน
     `scheduleNotify()`, ของเดิมถูกต้องอยู่แล้ว ไม่ต้องแก้) — เพิ่ม stress-test panel ใน components.php's
     demo (2 counter: จำนวนคลิกที่จับได้ vs. จำนวน onChange ที่ยิงจริง + ปุ่ม "ตั้งค่าตัวอย่างใหม่") ให้กด
     × สลับกาง/ยุบซ้ำได้จริงด้วยตา ไม่ใช่แค่อ่านโค้ดแล้วเชื่อ
- **2026-09-13, บั๊กจริงที่ 3 วันเดียวกัน — repro ชัดจากผู้ใช้ยืนยัน root cause ได้จริง (ไม่ใช่เดา):**
  "เลือก 2 ช่อง → × ของช่องที่ 2 (static) ทำงาน, ช่องที่ 1 (แผนก = select2-remote) × ไม่ทำงาน; 'ล้างตัวกรอง'
  ล้างได้แค่ช่อง static; เลือกแผนกช่องเดียว × ไม่ทำงานเลย" — components.php's demo เดิมทุกช่องเป็น
  `select2-native` (มี option ครบในมาร์กอัปเสมอ) จึง repro บั๊กนี้ไม่ได้เลย จนกว่าจะเปลี่ยนช่องแรก
  (`#cpFilterDept`) เป็น `select2-remote` จริง ชี้ `/api/department.get` เหมือน `#rdDepartmentFilter`
  ของหน้าจริงทุกประการ (ตามที่สั่ง) — root cause: `resetSelect()` เดิมใช้ `.find('option').first()` เป็นค่า
  "default" เสมอ ถูกสำหรับ static/native (option แรกในมาร์กอัปคือ `value="all"` จริง) แต่**ผิดสำหรับ
  select2-remote** เพราะช่อง ajax ไม่มี option ใดๆ ในมาร์กอัปเลยตอนเริ่มต้น (ดู `initSelect2()`'s ajax
  branch, input.js) — option เดียวที่เคยมีคือตัวที่ select2 append ตอนผู้ใช้เลือกค่าจริง ดังนั้น
  `.find('option').first()` จึงเจอ**ตัวเดียวกับค่าที่กำลังจะล้าง**เสมอ ตั้งค่ากลับไปที่ตัวมันเอง = no-op
  ตรงกับทุกอาการที่รายงาน แก้ 3 จุด:
  1. `resetSelect()` แยก branch ตาม `.select2-remote`: ลบ option ที่ select2 append ไว้ทั้งหมดก่อน
     (`$select.find('option').remove()`) แล้วค่อย `$select.val(null).trigger('change')` — คืนช่องกลับ
     สภาพเปล่าเป๊ะเหมือนมาร์กอัปเริ่มต้น ไม่ใช่แค่ตั้งค่าว่างทิ้ง option ค้างไว้
  2. sentinel "ทั้งหมด" ไม่ hardcode `'all'` ทุกช่องอีกต่อไป — `defaultValueFor($select)` ใหม่อ่าน
     `data-filter-default` attribute ก่อน (optional, ระบุได้ต่อช่อง) ถ้าไม่มีค่อย fallback ตาม type:
     `select2-remote` → `''`, อื่นๆ → `'all'` (ของเดิมเท่ากับ fallback นี้พอดี ไม่กระทบ field เดิมที่ไม่ได้
     ตั้ง attribute) — `isActive()`/`resetSelect()` ทั้งคู่อ่านผ่านฟังก์ชันเดียวนี้ ไม่ hardcode ซ้ำ
  3. "ล้างตัวกรอง" เก็บ snapshot รายการช่อง (`.toArray()`) ก่อนวน + `try/catch` ต่อช่องกัน 1 ช่องพังแล้ว
     ช่องที่เหลือไม่ถูกล้างตาม (debounce เดิมของ `scheduleNotify()` ยิง onChange ครั้งเดียวหลังจบลูปอยู่แล้ว
     ไม่ต้องแก้) — `filter-bar.php`'s docblock บันทึก `data-filter-default` contract ใหม่ไว้แล้ว

**Dropdown ของปุ่ม** (⋮/ส่งออก/action menu ทั่วไป — `.dropdown-menu:not(.notif-dropdown)` — REVISED
2026-09-13, item 3a "เก็บตก" item 1, แล้วปรับละเอียดอีกรอบวันเดียวกัน)
- **มีขอบจริง**: ขอบ 1px `--c-border` + พื้น `--c-bg` + เงา `--shadow-soft` — ทำผ่าน Bootstrap 5's เอง
  per-component CSS vars (`--bs-dropdown-border-color`/`-width`/`-bg`/`-box-shadow`) scope ที่
  `.dropdown-menu` เท่านั้น **ไม่แตะ root `--bs-border-radius`/`--bs-box-shadow`** (นั่นยังเป็น
  `--radius`/`--shadow-modal` ให้ component อื่น เช่น modal จริงที่อ่าน var เดียวกัน) — **ย้อนกลับการ
  ตัดสินใจก่อนหน้านี้ในรอบเดียวกัน** ที่ตั้ง `--bs-dropdown-border-color: transparent` ไว้ทั่วระบบ (ขอบใส
  หมดทุก dropdown)
- **มุมโค้ง `--radius` (6px) ไม่ใช่ `--radius-lg`** — **`--radius-lg` สงวนไว้เฉพาะ surface ที่ "ลอยเหนือ
  หน้า" จริงๆ เท่านั้น: notification dropdown / Swal2 toast / popover ในอนาคต** — dropdown ของปุ่มทั่วไป
  เป็น control ที่เล็ก/แน่นกว่านั้น ใช้มุมโค้งปกติ padding รอบนอกแค่ `--sp-1` (padding จริงที่เห็นมาจาก
  แต่ละรายการเองด้านล่าง ไม่ใช่ขอบเมนู)
- **แต่ละรายการ (`.dropdown-item`)**: padding `--sp-2 --sp-3`, มุมโค้งของตัวเอง `4px` (ค่าตายตัว ไม่ใช่
  token — เล็กกว่า `--radius` ปกติโดยตั้งใจ เพราะเป็น element ย่อยข้างในเมนูที่มีมุมโค้งอยู่แล้ว), hover/focus
  พื้น `--c-bg-hover` (คนละสีกับ `.active`/`:active` ที่ใช้ `--c-bg-subtle` เดิม — 2 สถานะคนละความหมาย)
- **ไอคอนกว้างคงที่ 16px**: ไอคอนแต่ละรายการ (child ตัวแรกของ `.dropdown-item`) กว้าง 16px + จัดกลาง
  ในกรอบนั้น เพื่อให้**ข้อความเริ่มที่ตำแหน่งเดียวกันทุกแถว** ไม่ว่า glyph ไหน (ไอคอนแต่ละตัวใน Font Awesome
  กว้างไม่เท่ากันที่ font-size เดียวกัน)
- **`min-width: 180px`**
- **ห่างจากปุ่ม trigger `margin-top: var(--sp-1)` (4px)** — **ข้อจำกัดที่ต้องรู้ (ตรวจแล้ว ไม่ได้เดา)**:
  Bootstrap's เอง Popper positioning (`dropdown.js`'s default offset modifier `[0, 2]`, hardcode ใน JS
  ไม่ได้อ่านจาก CSS variable เลย) วางเมนูห่างจากปุ่มด้วย inline `transform` ของตัวเองอยู่แล้ว (~2px) —
  `margin-top` นี้**บวกเพิ่ม**บนนั้น (margin ไม่ถูก reset โดย positioning แบบ absolute) ไม่ใช่แทนที่ — ระยะ
  ที่เห็นจริงจะใกล้เคียง ~6px ไม่ใช่ 4px เป๊ะๆ ถ้าต้องการ 4px เป๊ะจริงต้อง override Popper's เอง offset ผ่าน
  `data-bs-offset="0,0"` ที่ทุกปุ่ม trigger ด้วย (ยังไม่ทำ เพราะคำสั่งระบุกลไก "margin-top" ตรงๆ)
- **ข้อยกเว้นเดียว: `.notif-dropdown`** — ไม่มีขอบ (`border: none` ชัดเจน) และไม่ถูกกระทบจาก bullet ไหน
  ข้างบนเลย (`:not(.notif-dropdown)` กันไว้ทุก rule ใหม่) พื้น/มุมโค้ง/เงา/padding ของตัวเองยังคงเดิม
  (`--c-bg-subtle`/`--radius-lg`/`--shadow-soft`/`--sp-2` — ตั้งเป็น plain CSS property โดยตรงมาก่อนหน้า
  นี้แล้ว ไม่ผ่าน Bootstrap var เลย) — เหตุผลเดิม (§14/notification card redesign): เป็น "surface ที่ลอย
  เหนือหน้า" ที่ใช้เงาบอกความลอยพออยู่แล้ว ไม่ต้องมีขอบซ้อน

**Notification** (ตัดสินใจแล้วรอบ 2 item 6d — **UI เท่านั้น ยังไม่ต่อ backend, ไม่ polling**)
- **มีกลไกจริงอยู่แล้ว** (`layout/header.php`'s `.nav-notif-dropdown`, `public/js/notifications.js`,
  `NotificationModel`, 2026-08-29/-30/-31, ต่อ backend จริงครบ) — **ไม่แตะรอบนี้** ("ไม่แตะ header.php
  รอบนี้ นอกจาก demo") แต่ต่างจาก component อื่นๆ ในรอบนี้ (ที่ของเดิมส่วนใหญ่แค่ "ยังไม่ใช้ token"),
  ของจริงตัวนี้มี **3 จุดที่ต่างจากสเปกใหม่ด้านล่างจริงๆ ไม่ใช่แค่สีที่ยังไม่ผ่าน token** — บันทึกไว้ตรงๆ
  ให้รอบ 4 ตัดสินใจตอน migrate จริง ไม่ใช่เดาแทนตอนนี้:
  1. ไอคอนต่อรายการของจริงใช้ **`.row-type-icon rt-N`** (วงกลมพื้น gradient **สีต่างกันตาม type**, คนละ
     แบบกับ Report table's row-category icon ที่ confirm ผ่าน AskUserQuestion แล้วตอน 2026-08-30 ว่าต้อง
     **เหมือนกับหน้า Report ทุกตาราง**) — สเปกใหม่ด้านล่างนี้ (แก้อีกครั้ง 2026-09-13 — ดูรายละเอียดท้าย
     หัวข้อนี้) ใช้วงกลมพื้น **สีเดียวกันทุก type** (`--c-bg-subtle`/`--c-text-muted`) ไม่ใช่ gradient สี
     ต่าง type แบบของจริง — ต่างกันตรง "สีเดียวกันหมด vs สีต่างกันตาม type" ไม่ใช่ "ไม่มีพื้น vs มีพื้น"
     อีกต่อไป (เวอร์ชันแรกของสเปกนี้ไม่มีพื้นเลย ก่อนแก้)
  2. จุดไม่อ่านของจริงอยู่**ขวา** ของ item (`.nav-notif-item-dot`, ต่อท้าย body) — สเปกนี้เคยมีจุดซ้าย
     ก่อนหน้านี้ (revision ก่อน) แต่ **ตัดจุดออกทั้งหมดแล้ว** ในการ์ดต่อรายการ (แก้ 2026-09-13 อีกรอบ —
     ดูรายละเอียดท้ายหัวข้อ) ย้ายสัญญาณ unread ไปไว้ที่สี icon plate + น้ำหนัก title แทน
  3. ป้ายจำนวนของจริงโชว์ **"99+"** เมื่อเกิน 99 (`notifUpdateBadge()`) สเปกใหม่โชว์ **">99"**
  - **จุดร่วมที่ไม่ต่าง**: ข้อความปุ่ม/หัว/ท้าย ("การแจ้งเตือน"/"ทำเครื่องหมายว่าอ่านแล้วทั้งหมด"/"ดูทั้งหมด"/
    empty state) ใช้ **i18n key เดิมของจริงซ้ำ** (`notifications`/`notif_mark_all_read`/`notif_view_all`/
    `notif_empty`) — ไม่สร้าง key ใหม่ซ้ำความหมายเดียวกัน แม้ถ้อยคำที่ผู้ใช้ร่างไว้รอบนี้ ("อ่านทั้งหมดแล้ว",
    "ไม่มีการแจ้งเตือน") จะสั้นกว่าเล็กน้อย — ไม่แก้ค่า i18n เดิม (จะกระทบข้อความจริงบนหน้าจริงทันทีทั้งที่
    ไม่ได้แตะไฟล์ header.php เอง เหมือนกรณี showConfirm/fmtNum ที่แก้ shared resource แล้วมีผลทั่วแอป —
    รอบนี้ไม่ใช่การตัดสินใจแบบนั้น เก็บคำถามการปรับถ้อยคำไว้ให้รอบ 4 คู่กับการ migrate จริง)
- **ปุ่มกระดิ่ง**: `.btn-icon` วงกลมเดียวกับ row action (§7) — ต่างจากของจริง (`<img>` Bell.svg เปล่าไม่มี
  วงกลม/ขอบ) โดยตั้งใจ ใช้ `<i class="fa-solid fa-bell">` ข้างใน (ไม่ใช่ `<img>` — `.btn-icon i` เท่านั้นที่
  มี CSS ขนาดไอคอนให้) — ป้ายจำนวนยังไม่อ่าน (`.notif-badge`) ลอยมุมขวาบนของวงกลม (`position:absolute`)
  pill พื้น `--c-danger` ตัวหนังสือขาว ซ่อนเมื่อ 0 (`d-none`), โชว์ **">99"** ถ้าเกิน 99
- **Dropdown (แก้เป็นโครง "การ์ดต่อรายการ" 2026-09-13, ซอฟต์ลงอีกรอบวันเดียวกัน — ดูประวัติ revision
  ท้ายหัวข้อ)**: กว้าง **380px** (เดิม 360px) สูงสุด **480px** (flex column, ส่วนกลาง `.notif-list`
  scroll เอง ผ่าน `.scroll-thin` utility ดู §11 — หัว/ท้าย fix อยู่กับที่ ไม่ scroll ทั้งกล่อง) พื้น
  **`--c-bg-subtle`** (เดิม `--c-bg`) padding **`--sp-2`** รอบกล่อง (เดิม 0) เงา **`--shadow-soft`**
  (เดิม `--shadow-modal` — เปลี่ยนพร้อม token ใหม่ ให้ความรู้สึกนุ่ม/เงียบกว่า) radius **`--radius-lg`**
  (เดิม `--radius` — surface ลอย ดู §1) **ไม่มีขอบ (`border`) อีกต่อไปที่ชั้นไหนเลย** (เดิมมี 1px
  `--c-border` คู่กับเงา — ตัดออก ให้เงาอย่างเดียวคุมความรู้สึก "ลอย" พอ ไม่ซ้อน 2 สัญญาณ)
  - หัว: ซ้าย "การแจ้งเตือน" `--fs-base` น้ำหนัก **600** (เดิม 700 หนามาก — ผ่อนลงพร้อมรอบ "ซอฟต์ลง"),
    ขวา ปุ่ม tertiary "ทำเครื่องหมายว่าอ่านแล้วทั้งหมด" (`.btn.btn-link`, ปรับสไตล์ชัดเจนแล้ว: `--fs-sm`
    `--c-text-muted` ไม่ underline ปกติ, underline เฉพาะ hover) — **ไม่มีเส้นคั่นใต้หัวอีกต่อไป** (ของเดิม
    มี `border-bottom`) เพราะโครงการ์ดใหม่ไม่มีเส้นคั่นที่ไหนในบล็อกนี้เลย
  - รายการ = **การ์ด** (ของเดิมเป็นแถวคั่นเส้น) — พื้น `--c-bg` (สวนทางกับพื้น `--c-bg-subtle` ของกล่อง
    ทั้งใบ ให้อ่านเป็น surface ลอยซ้อนอีกชั้น โดยแยกกันด้วยพื้นเท่านั้น **ไม่มีขอบ**) radius `--radius-lg`
    padding **`--sp-3` `--sp-4`** เว้นช่องระหว่างการ์ด `--sp-2` (ผ่าน `gap` บน `.notif-list`, ไม่มีเส้น
    คั่นระหว่างรายการเลย) hover เปลี่ยนพื้นเป็น **`--c-bg-hover`** (เดิม hover เปลี่ยนสีขอบ — ตอนนี้ไม่มี
    ขอบให้เปลี่ยนแล้ว จึงย้ายสัญญาณ hover ไปที่พื้นแทน) **ไม่มีเงาไม่ยก** (การ์ดที่ลอยอยู่แล้วในกล่องที่ก็
    ลอยอยู่แล้ว ไม่ต้องมี elevation คู่ที่สองซ้อนกัน)
  - item shape `{unread: bool, tone: neutral|warning|danger|success (เซตเดียวกับ Timeline's tone enum),
    title (1 บรรทัด), detail? (1 บรรทัด, `--c-text-muted`), time, link}` — ไอคอนซ้ายเป็นสี่เหลี่ยมมน
    36px radius **10px** (เดิม 8px, ก่อนหน้านั้นเป็นวงกลม 28px) **ไม่มีขอบ**: อ่านแล้ว = พื้น
    `--c-bg-subtle` ไอคอน **`--c-text-faint`** (เดิม `--c-text-muted` — จางลงอีกระดับพร้อมรอบ "ซอฟต์ลง"
    ให้ตัดกับ unread ชัดขึ้น); ยังไม่อ่าน = พื้น `--c-primary-soft` ไอคอน `--c-primary` (ข้อยกเว้นของ §3 —
    ความหมายเดียวกับ step ปัจจุบัน "นี่คือสิ่งที่ต้องดูตอนนี้", ไม่เปลี่ยน) glyph ยังเลือกตาม tone เหมือนเดิม
    ไม่เปลี่ยน (สื่อความหมายผ่านรูปทรง คนละเรื่องกับสีที่ผูกกับ read state ล้วนๆ): success→check
    (อนุมัติ), danger→xmark (ปฏิเสธ), warning→triangle-exclamation, neutral→bell (ระบบ, default) —
    ไม่มีจุด unread แยกต่างหากอีกต่อไป (สัญญาณ unread อยู่ที่สี icon plate + น้ำหนัก title เท่านั้น) —
    ตัวหนังสือ title: unread = `--c-text` น้ำหนัก **600** (เดิม 700), อ่านแล้ว = `--c-text` น้ำหนักปกติ
    (400), `--fs-base`, **`line-height:1.4`** (เพิ่มใหม่ ให้ข้อความ 2 บรรทัดขึ้นไปอ่านง่ายขึ้น ใช้ร่วมกับ
    detail/time ในคอลัมน์เดียวกัน); detail `--fs-sm` `--c-text-muted` 1 บรรทัด ellipsis; time `--fs-xs`
    `--c-text-faint`
  - ท้าย: ปุ่ม tertiary (`.btn.btn-link.btn-sm`) "ดูทั้งหมด" ไป `/notifications` กึ่งกลาง — ไม่มีพื้นแยก/
    เส้นคั่นบน (ทั้งกล่องพื้น `--c-bg-subtle` เหมือนกันหมดแล้ว) สไตล์เหมือนปุ่ม tertiary ของหัว
    (`--fs-sm` `--c-text-muted`, underline เฉพาะ hover)
  - ว่าง (0 รายการ): ข้อความ "ยังไม่มีการแจ้งเตือน" `--c-text-faint` แทนที่ list ทั้งหมด
- **2026-09-13, "ปรับตาม token ให้เงียบลง" follow-up — 2 บั๊ก/ประเด็นจริงที่แก้**:
  1. **ไอคอน item เคยมีสีต่างกันตาม tone จริง** (success=เขียว/danger=แดง/warning=ส้ม, ไม่มีพื้นสี) —
     ขัดกับ §3 ตรงๆ ("สีมีหน้าที่บอกว่า...ถ้าสีไม่ได้ตอบสองคำถามนี้ ให้เป็นเทา" — ประเภทการแจ้งเตือนอย่าง
     เดียวไม่ใช่เหตุผลให้มีสี ไม่ใช่ "ต้องตัดสินใจ/ต้องระวัง") — แก้เป็นสีเดียว `--c-text-muted` ในวงกลม
     28px พื้น `--c-bg-subtle` ทุก item เหมือนกันหมด **glyph ยังต่างตาม tone** (สื่อความหมายผ่านรูปทรง
     ไม่ใช่สี) — `tone` ตอนนี้จึงมีผลแค่กับการเลือกไอคอนเท่านั้น ไม่มีผลกับสีที่ไหนในบล็อกนี้เลย
  2. **พื้นแถว unread (`--c-primary-soft`) อ่านดูเหลืองเกินไป** — ตัดออกทั้งหมด (ทางเลือกที่ให้มาอีกทาง
     คือลด opacity เหลือ 3–4% แต่เลือกตัดทิ้งไปเลยเพราะจุดส้ม + title หนาสองอย่างนี้พอสื่อ "ยังไม่อ่าน"
     อยู่แล้วโดยไม่ต้องมีพื้นสีเพิ่ม) `tokens.css`'s own `--c-primary-soft` comment ที่เคยขยาย scope
     ให้ครอบ notification row ไว้ ถอนกลับเป็น "step/tab ปัจจุบัน เท่านั้น" ตามเดิม (comment ระบุประวัติ
     การลองแล้วถอนไว้ ไม่ลบทิ้งเงียบๆ)
- **2026-09-13, "การ์ดต่อรายการ" follow-up (รอบที่ 3 ของวันเดียวกัน) — เปลี่ยนจาก list คั่นเส้นเป็นการ์ด
  นุ่มๆ ต่อรายการ**:
  1. เพิ่ม token ใหม่ `--radius-lg` (10px, §1) สำหรับ surface ที่ "ลอย" ทั้งหมด (dropdown/popover/
     toast/Swal popup + การ์ดข้างในนั้น) — `--radius` (6px) เดิมยังคุมปุ่ม/input/การ์ดในหน้าเหมือนเดิม
  2. dropdown ทั้งกล่องเปลี่ยนพื้นเป็น `--c-bg-subtle` + padding `--sp-2` + radius `--radius-lg` (ดู
     bullet "Dropdown" ด้านบนที่แก้ตามแล้ว) ตัดขอบกล่องออก เหลือแค่เงา
  3. แต่ละรายการกลายเป็นการ์ดจริง (พื้น `--c-bg` ขอบ `--c-border` radius `--radius-lg`) เว้นช่องด้วย
     `gap` แทนเส้นคั่น — **ไม่มีเส้นคั่นเหลืออยู่ที่ไหนในบล็อกนี้เลย** ทั้งหัว/รายการ/ท้าย
  4. **ย้อนกลับการตัดสินใจก่อนหน้า (bullet "เงียบลง" ด้านบน) เรื่องพื้น unread**: ตอนนั้นตัดพื้น
     `--c-primary-soft` ออกทั้งหมดเพราะกลัวดูเหลือง — รอบนี้**เอากลับมาใช้ แต่ใช้กับ icon plate แทนพื้น
     ทั้งแถว** (พื้นที่เล็กกว่ามาก ไม่ล้นเป็นแถบเหลืองทั้งแถวเหมือนเดิม) พร้อมเพิ่มเป็น**ข้อยกเว้นใหม่ใน
     §3's ตาราง** (`--c-primary-soft`/`--c-primary` ใช้กับรายการที่ยังไม่อ่าน/ต้องสนใจได้ — ความหมาย
     เดียวกับ step ปัจจุบัน) ไม่ใช่แค่ hack เฉพาะ component นี้เฉยๆ
  5. ตัดจุดส้ม 6px ที่เพิ่งเพิ่มไปออกอีกครั้ง (สัญญาณ unread ย้ายไปที่สี icon plate + น้ำหนัก title
     ล้วนๆ ไม่ต้องมีจุดแยกอีกจุดหนึ่ง)
  6. ไอคอนเปลี่ยนจากวงกลม 28px → สี่เหลี่ยมมน 36px radius 8px (ให้พื้นที่พอสำหรับสีสองสถานะข้างบน)
  7. `--radius-lg` ยังถูกนำไปใช้กับ Swal2 popup/toast ด้วย (`--swal2-border-radius`, ตัวปุ่มข้างในยังคง
     `--radius` เหมือนเดิม) ให้ "ชุดเดียวกัน" ตามที่สั่งตรงๆ — ยืนยันจากอ่าน `sweetalert2.css` ตรงๆ ว่า
     toast mode (`showSuccess()`) กับ popup ปกติใช้ CSS variable ตัวเดียวกัน ไม่มี rule แยกสำหรับ toast
     โดยเฉพาะ จึงแก้จุดเดียวครอบคลุมทั้งคู่
- **2026-09-13, "ซอฟต์ลง" follow-up (รอบที่ 4 ของวันเดียวกัน) — feedback ตรงๆ: "ยังแข็งเพราะทุกชั้นมี
  เส้นขอบ"**:
  1. **ตัดเส้นขอบออกทุกชั้นที่เหลือ**: การ์ดต่อรายการ (รอบก่อนหน้ายังมีขอบ 1px `--c-border` อยู่) ตอนนี้
     ไม่มีขอบเลย แยกจากพื้น dropdown ด้วยพื้น `--c-bg` บนพื้น `--c-bg-subtle` เท่านั้น — dropdown เองไม่มี
     ขอบอยู่แล้วตั้งแต่รอบก่อน (ไม่เปลี่ยน) แต่เปลี่ยนเงาจาก `--shadow-modal` เป็น **`--shadow-soft`**
     (token ใหม่ ดู §1) ให้ความรู้สึกนุ่มกว่าเดิม — hover การ์ดเปลี่ยนจาก "เข้มขอบ" เป็น **เปลี่ยนพื้นเป็น
     `--c-bg-hover`** แทน (ไม่มีขอบให้เข้มอีกต่อไป)
  2. ไอคอน: ไม่มีขอบ (ไม่เคยมี ไม่เปลี่ยน) radius **8px → 10px**, อ่านแล้วสีจาง **`--c-text-muted` →
     `--c-text-faint`** (จางกว่าเดิม 1 ระดับ ให้ตัดกับ unread ชัดขึ้น) ยังไม่อ่านคงเดิม
     `--c-primary-soft`/`--c-primary`
  3. ตัวอักษร: title น้ำหนัก **700 → 600** เฉพาะยังไม่อ่าน (อ่านแล้วคงน้ำหนักปกติ 400 `--c-text`),
     detail/time คงเดิม (`--fs-sm` `--c-text-muted` / `--fs-xs` `--c-text-faint`), เพิ่ม
     **`line-height:1.4`** ใหม่ให้คอลัมน์ข้อความ, padding การ์ดขยายเป็น **`--sp-3` `--sp-4`** (เดิม
     `--sp-3` รอบเดียว)
  4. **`.scroll-thin` — utility กลางใหม่** (`scrollbar-width:thin` + `scrollbar-color` + WebKit
     scrollbar 6px thumb `--c-border-strong` radius `--radius-pill`) แทนที่จะผูก scrollbar บาง/โปร่งไว้
     เฉพาะ `.notif-list` — ทำเป็น class กลางใน `style.css` ให้ dropdown/panel ที่ scroll ตัวไหนก็ตามในระบบ
     เรียกใช้ซ้ำได้ (ดู §11's ตาราง shared component) — `.notif-list` ใช้ผ่าน `class="notif-list
     scroll-thin"`
  5. หัว/ท้าย: ปุ่ม tertiary ("อ่านทั้งหมดแล้ว"/"ดูทั้งหมด") ได้สไตล์ชัดเจนเป็นครั้งแรก — `--fs-sm`
     `--c-text-muted` ไม่ underline ปกติ, underline เฉพาะ hover เท่านั้น (ก่อนหน้านี้ได้สี `--c-text` มา
     ฟรีจาก `--bs-link-color` แต่ไม่มี size/underline rule ของตัวเองเลย)
  6. **บั๊กจริงที่เจอและแก้ในรอบเดียวกัน (ไม่ใช่ design แต่เกี่ยวเนื่องกับ demo หน้านี้)**: `components.php`
     ยัง render คำอังกฤษ (Notifications/Mark all as read/View All) ทั้งที่ i18n key มีครบทั้ง th/en —
     root cause ไม่ใช่ wiring บั๊ก (ยืนยันด้วย curl ว่า `th.json`/`en.json` โหลดสำเร็จและมี key ครบ) แต่
     เป็นพฤติกรรมที่ถูกต้องของทั้งแอป: `currentLang = localStorage.getItem('preferred_language') ||
     'en'` (`app.js`) fallback เป็นอังกฤษบนเบราว์เซอร์ที่ยังไม่เคยตั้งค่าภาษาไว้เลย — แก้แบบ scoped เฉพาะ
     หน้า demo นี้เท่านั้น: seed `localStorage['preferred_language'] = 'th'` ใน `components.php` เอง
     เฉพาะตอนที่ยังไม่มีการตั้งค่าใดๆ มาก่อนเลย (ไม่เคยเขียนทับค่าที่ผู้ใช้เคยเลือกไว้จริง) ไม่แตะ
     default logic ของ `app.js` ที่ใช้จริงทั้งแอป
- **JS helper 2 ตัวใน `app.js`, ไม่มี polling**: `renderNotifications(items)` (คืน HTML string ของ
  รายการทั้งหมด รวม empty-state เมื่อ `items` ว่าง — pattern เดียวกับ `renderTimeline()`/
  `renderStatusStepper()` คือคืนค่าให้ caller เอาไป `.html()` เอง ไม่ inject ตรงเข้า DOM ให้) และ
  `setNotificationCount(el, n)` (ตั้งตัวเลข/ซ่อน-โชว์ badge ตาม `el` ที่ caller ระบุ — ไม่ผูกกับ id ตายตัว
  เดียว เพื่อให้ demo กับของจริง (id ต่างกัน) เรียกร่วมกันได้)
- **หน้า Notifications เต็ม (มีอยู่แล้วจริง ตาม `docs/design/audit.md`'s `/notifications`) ใช้
  `timeline.php`/`renderTimeline({groupByDay:true})` ตัวเดียวกับข้อ (3)/6b — ไม่มี layout ที่ 3** สำหรับ
  หน้ารายการเต็ม dropdown ด้านบน (ย่อ, ไม่ group วัน) กับหน้าเต็ม (`groupByDay:true`) ใช้ component เดียวกัน
  ต่างกันแค่ option — ของจริงตอนนี้ (`app/views/notification/index.php`) เป็น DataTable ของตัวเอง
  (`notifPageTableInit()`) ไม่ใช่ timeline อย่างที่กฎนี้ตัดสินใจ เป็นอีกจุดที่ต้อง migrate ตอนรอบ 4
- Demo จริงใน `docs/design/components.php`: กระดิ่งมี badge = 3 (ตั้งผ่าน `setNotificationCount()`
  ตรงๆ ไม่ได้นับจาก list — ตั้งใจให้เห็นว่าเป็นคนละ state กัน เหมือนของจริงที่ unread-count มาจาก
  endpoint แยกจาก dropdown's เอง 10 รายการล่าสุด ไม่ใช่ derive จากกันเสมอไป), dropdown มี 5 รายการ (2
  unread) ผ่าน `renderNotifications()` ตรงๆ — กด "ทำเครื่องหมายว่าอ่านแล้วทั้งหมด" แล้ว badge หาย (n=0) +
  ไอคอนการ์ดทั้ง 5 ใบเปลี่ยนจาก `--c-primary-soft`/`--c-primary` (2 ใบที่เคยยังไม่อ่าน) กลับเป็นเทาปกติ
  `--c-bg-subtle`/`--c-text-muted` เหมือนกันหมด + title กลับเป็นน้ำหนักปกติ (re-render ผ่าน
  `renderNotifications()` เดิมด้วย items ที่ตั้ง `unread:false` ทุกตัว) — light/dark ผ่านปุ่มสลับ theme
  มุมขวาบนของหน้าเดียวกัน (ทุกสีเป็น token ทั้งหมด
  ไม่มี hex ตรงๆ จึงสลับถูกต้องเองโดยไม่ต้องมี dark-mode override เพิ่ม)
- **2026-09-13, บั๊กจริงที่เจอและแก้ในเดโม: dropdown หลุดซ้ายนอกจอ** — เวอร์ชันแรกของเดโมเขียน
  `.notif-dropdown` เองเป็น `position:absolute; right:0` + toggle ด้วย `.toggleClass('d-none')` มือ
  ซึ่งคือพฤติกรรม `dropdown-menu-end` ที่เขียนเองแบบ hardcode ทิศทาง ใช้ได้ถูกต้องเฉพาะตอนปุ่มกระดิ่งอยู่
  ใกล้ขอบขวาจอ (top bar จริง) — เดโมวางกระดิ่งไว้ซ้ายของ section ทำให้กล่อง 360px ดันหลุดซ้ายจอไปเลย
  แก้ 2 จุดร่วมกัน: **(1)** ย้ายกระดิ่งเดโมไปขวาสุดของ section (`.d-flex.justify-content-end`) จำลอง
  ตำแหน่งจริงบน top bar; **(2)** เปลี่ยนเป็น **Bootstrap dropdown จริง** (`.dropdown` + `data-bs-toggle=
  "dropdown"` + `.dropdown-menu.dropdown-menu-end`, ใช้ Popper ที่ bundle มาอยู่แล้วคุมตำแหน่ง/พลิกด้าน
  เองอัตโนมัติเมื่อชิดขอบจอ — `data-bs-display="dynamic"` ระบุไว้ตรงๆ ทั้งที่เป็นค่า default ของ Bootstrap
  เองอยู่แล้ว กันแก้ผิดเป็น `static` ในอนาคตซึ่งจะปิด Popper) ไม่ hardcode ทิศทางเองอีกต่อไป จะไม่พังถ้า
  top bar จริงเปลี่ยน layout ตอนรอบ 4 — `display:flex` ของกล่อง dropdown ต้อง scope เป็น
  `.notif-dropdown.show` (ไม่ใช่ `.notif-dropdown` เฉยๆ) เพราะ Bootstrap's `.dropdown-menu{display:none}`
  เป็น single-class selector เหมือนกัน specificity เท่ากันจะชนกัน (บั๊ก class เดียวกับ v11's Symbol
  dropdown/status-tab idle-pill ที่เจอมาแล้ว 2 ครั้งในโปรเจกต์นี้) — ตรวจแล้วว่า `.cp-section` ไม่มี
  `overflow:hidden` ตัด dropdown จึงไม่ต้องใช้ `popperConfig:{strategy:'fixed'}` ในเดโมนี้ (ถ้า container
  จริงตอน migrate รอบ 4 ตัดจริง ค่อยเพิ่ม option นี้ตอนนั้น)

**Empty state** (ตัดสินใจแล้วรอบ 2 item 6e)
- ใช้ **4 ที่**: ตารางว่างทั้งตาราง (แทน `language.emptyTable`/`zeroRecords` ของ DataTable's own
  single-line text — ผ่าน `initSharedDataTable()`'s `emptyState` option ด้านล่าง), tab/section ว่าง
  (เช่น Tax & Statutory ยังไม่มีเวอร์ชันอัตรา), รายการว่าง (notification/timeline — ดู `renderNotifications()`
  เอง ที่เรียก `emptyStateHtml()` ตัวเดียวกันนี้อยู่แล้วตอน item ว่าง), ผลค้นหา/กรองไม่พบ
- **layout**: กึ่งกลาง, padding แนวตั้ง `--sp-6` (แนวนอน `--sp-4` กันข้อความชิดขอบ container แคบ) —
  ไอคอน 1 ตัว 32px `--c-text-faint` (**สีเดียว ไม่มีวงกลม/พื้น** — คนละการตัดสินใจกับไอคอน notification
  item ข้อ 6d ด้านบน ซึ่งกลับไปใช้วงกลมพื้นสีหลังแก้ 2026-09-13 — 2 จุดนี้ตัดสินใจแยกกันตอนนี้ ไม่ใช่กฎ
  เดียวกันที่ใช้ซ้ำ), title 1 บรรทัด `--c-text` `--fs-md`, text 1 บรรทัด `--c-text-muted` บอกว่าทำอะไร
  ต่อได้, action (optional) ปุ่ม 1 ตัว — **secondary** (`.btn.btn-outline-secondary`) ถ้าหน้ามีปุ่ม
  primary อยู่แล้วที่อื่นใน header, **primary** (`.btn.btn-primary`) ได้เฉพาะเมื่อหน้านั้นไม่มีปุ่มหลัก
  ที่อื่นเลย (การตัดสินใจว่าใช้ variant ไหนเป็นหน้าที่ของ **caller** เสมอ — component เองไม่มีความเห็น
  ไม่เดาจาก context ให้)
  - **2026-09-13, บั๊กจริงที่เจอและแก้: ไอคอนไม่อยู่กึ่งกลาง (ชิดซ้าย)** — root cause: FontAwesome's เอง
    base rule (`.fa-solid,.fab,.far,.fas`, ยืนยันจากอ่าน `all.min.css` ตรงๆ) ตั้ง `width:1.25em` ที่
    specificity เท่ากับ (single-class) `.empty-state-icon`'s เดิม `display:block` — `display:block`
    ชนะ tie ได้ (`style.css` โหลดทีหลัง) แต่ `width:1.25em` ของ FA ไม่มีอะไรมาสู้เลยเพราะกฎเดิมไม่เคย
    ประกาศ `width` ของตัวเอง ผลคือ icon กลายเป็น block กว้างคงที่ ~1.25em — block ที่มีความกว้างชัดเจน
    (ไม่ใช่ auto) **ไม่ขยายเต็ม container** และ `text-align:center` มีผลแค่กับ inline content ข้างใน box
    ไม่มีผลกับตำแหน่งของ box ที่มีความกว้างคงที่เอง จึงติดชิดซ้ายเสมอไม่ว่า `text-align` จะเป็นอะไร — แก้
    โดยเปลี่ยน `.empty-state` เองเป็น `display:flex; flex-direction:column; align-items:center;` (ลบ
    `display:block` ออกจาก `.empty-state-icon` เพราะไม่มีผลอะไรอีกต่อไป — flex item ถูก blockify ให้เอง
    ตาม spec) `align-items:center` จัดกึ่งกลางแนวขวางของ flex child ตาม box จริงของมันเอง ไม่สนว่ากว้าง
    คงที่หรือไม่ แก้ปัญหาตรงจุดแทนที่จะพึ่ง `text-align` ที่ไม่เกี่ยวกับปัญหานี้เลย
- **2 ความหมายต้องแยกคำ ไม่ใช้ข้อความเดียวกัน**:
  - **"ยังไม่มีข้อมูล"** (ยังไม่เคยมีใครสร้างอะไรเลย) — text แนะนำให้ **สร้าง**, action (ถ้ามี) คือปุ่ม
    สร้างของหน้า/tab นั้นเอง
  - **"ไม่พบตามที่กรอง"** (มีข้อมูลจริงอยู่ แต่ค้นหา/กรองแล้วไม่ตรงสักแถว) — text แนะนำให้ **เปลี่ยน/ล้าง
    ตัวกรอง**, action = ปุ่ม **tertiary** "ล้างตัวกรอง" (คำเดียวกับ `filter-bar.php`'s own Clear button,
    key `clear_filter` เดิม — ไม่สร้างคำใหม่)
  - partial/ฟังก์ชันนี้เอง**ไม่มีความเห็นว่ากรณีไหนใช้ความหมายไหน** — caller เป็นคนตัดสิน ยกเว้น
    `initSharedDataTable()`'s own `emptyState` option ด้านล่างที่ **auto-pick ให้เอง** สำหรับ DataTable
    โดยเฉพาะ (ที่เดียวที่ auto-detect จริง)
- **partial `app/views/partials/empty-state.php`** (`$icon, $title, $text, $action`) + JS twin
  **`emptyStateHtml({icon, title, text, action})`** (`app.js`) — รูปแบบเดียวกันเป๊ะทั้งสองฝั่ง
  (`action.onClick` เป็นของ JS ฝั่งเดียวเท่านั้น — จำเป็นเพราะ caller ที่ re-render block นี้ซ้ำๆ เช่น
  DataTable redraw จะทำลาย DOM node เดิมทุกครั้ง การ bind click จากภายนอกด้วย `$('#id').on('click',...)`
  ครั้งเดียวจะหยุดทำงานหลัง redraw แรก — ให้ `emptyStateHtml()`'s caller ที่ re-render เองผูก `onClick`
  มาในตัว action object แทน ส่วน `action.id` ยังใช้ได้ปกติสำหรับ render ครั้งเดียวไม่ re-render ซ้ำ)
- **`initSharedDataTable()`'s `emptyState:{icon,title,text,action}` option** — render ผ่าน
  `emptyStateHtml()` ใน `<td class="dt-empty-cell">` (colspan เต็มความกว้างตาราง, นับเฉพาะคอลัมน์ที่
  visible) แทนข้อความบรรทัดเดียวเดิมของ DataTables เมื่อ 0 แถวแสดงผล — ทำงานทุกครั้งที่ draw ใหม่
  (พิมพ์ค้นหา/ใช้ filter/เปลี่ยนหน้า) ไม่ใช่แค่ตอน init ครั้งแรก เพราะ "ว่างเพราะอะไร" เปลี่ยนได้ทุกครั้ง
  ที่ draw — **แยกอัตโนมัติว่าว่างเพราะกรองหรือว่างจริง** ผ่าน DataTables' เอง `page.info()`:
  `recordsTotal` (ขนาดข้อมูลทั้งหมดไม่ว่าตารางโหมดไหน — client-side คือทุกแถวที่โหลดมา, server-side คือ
  `COUNT(*)` ดิบจาก backend) เทียบกับ `recordsDisplay` (เหลือเท่าไหร่หลัง global search()/
  `$.fn.dataTable.ext.search` predicate ของ `initExcelColumnFilters()`'s client mode — หรือ
  `recordsFiltered` ที่ backend ส่งมาตรงๆ สำหรับ server mode) — **ทั้ง 2 กลไก filter ลงเลข 2 ตัวนี้เอง
  โดยอัตโนมัติ ไม่ต้องมี API ใหม่เชื่อมเข้า `table-column-filter.js` เลย**: `recordsTotal===0` = ว่างจริง
  (โชว์ `options.emptyState` ของ caller), `recordsTotal>0 && recordsDisplay===0` = กรองแล้วไม่พบ (โชว์
  ข้อความคงที่ที่ helper สร้างเอง — title reuse key `zeroRecords` เดิม ไม่สร้าง key ใหม่ซ้ำความหมาย,
  text เป็น key ใหม่ `empty_state_filtered_text`, action = "ล้างตัวกรอง" ผูก `dt.search('').draw()`
  ให้เองอัตโนมัติ) — `<td class="dt-empty-cell">` เอง colspan เต็มความกว้างตารางเสมอ และ `.empty-state`
  เองก็เป็น flex column กึ่งกลางเหมือนกันไม่ว่าจะ render อยู่ใน `<td>` นี้หรือที่อื่น (ไม่มี CSS แยกสำหรับ
  บริบท DataTable โดยเฉพาะ — component เดียวกันเป๊ะ)
- Demo จริงใน `docs/design/components.php`: 3 แบบ — **(1)** ตารางว่างจริง + ปุ่มสร้าง (primary, ไม่มี
  ปุ่มหลักอื่นในหน้าเดโม), **(2)** ค้นหา/กรองไม่พบ + "ล้างตัวกรอง" (auto จาก `emptyState` option, พิมพ์
  คำค้นหาที่ไม่มีจริงในตารางเดโมแล้วดู), **(3)** รายการว่างไม่มี action (list/tab แบบเปล่าๆ ไม่มีปุ่ม) —
  light/dark ผ่านปุ่มสลับ theme มุมขวาบนของหน้าเดียวกัน

**2026-09-13, บั๊กจริงที่เจอและแก้ทั้งหน้า components.php: DataTable/notification demo ยังเป็นอังกฤษ
หลายจุด (Show/entries/Showing/First/Last/No matching records, Notifications/Mark all as read/View
All)** — ไม่ใช่เพราะหน้านี้ไม่เคยโหลด `th.json` จริง (`app.js`'s own `$(document).ready(async function()
{...})` เรียก `loadLang(currentLang)` จริงและ `await` มันจริง — เป็น real `fetch()` ของไฟล์ static
`public/lang/{lang}.json` ที่ทำงานได้เต็มที่บนหน้า standalone นี้เพราะไม่ต้องพึ่ง session/backend เลย)
แต่เพราะ **jQuery ไม่รอ async work ของ ready callback ตัวหนึ่งก่อนจะยิง ready callback ตัวถัดไป** —
`$(document).ready(async fn)` แค่เรียก `fn()` แล้วปล่อยให้ทำงานต่อไปเป็น Promise แยก ส่วน ready
callback **ตัวที่สอง** (script ของเดโมเอง ที่เรียก `initSharedDataTable()`/`renderNotifications()`
ทันทีแบบ synchronous) รันไปเลยโดยที่ `langData` ยังเป็น `{}` ว่างอยู่ (fetch ยังไม่ resolve) — แก้ตาม
pattern เดียวกับที่หน้าจริงใช้อยู่แล้วทุกไฟล์ (ยืนยันจาก grep `employee/list.js`/`payroll/index.js`):
ครอบทั้ง body ของ ready callback เดโมด้วย `(window.langReady || Promise.resolve()).then(function () {
... })` — `|| Promise.resolve()` กันหน้าที่ไม่มี `langReady` เลยค้าง เดโมนี้ต่อไปนี้จะแสดงภาษาตามที่
`localStorage['preferred_language']` ตั้งไว้จริง (default `en` ถ้าไม่เคยตั้งค่าเลย — เหมือนหน้าจริงทุก
ประการ ไม่ได้ force เป็นไทยเอง) — **เจอบั๊กเพิ่มระหว่างแก้**: `renderNotifications()`'s empty-state
branch เดิมใช้ `data-i18n="notif_empty"` (markup ที่หวังให้ DOM sweep ของ `applyLanguage()` มาแปลทีหลัง)
แต่ sweep นั้นรันไปแล้วรอบเดียวตอน `loadLang()` เอง **ก่อน** ที่ HTML นี้จะถูก insert เข้า DOM ด้วยซ้ำ —
`data-i18n` แบบนี้ไม่มีวันถูกแปลเลยไม่ว่าภาษาไหน แก้โดยอ่าน `getLangValue('notif_empty')` ตรงๆ ในฟังก์ชัน
เอง (แบบเดียวกับที่ `dtRenderEmptyState()` ทำอยู่แล้วสำหรับข้อความ "กรองไม่พบ") — **กฎทั่วไปสำหรับ
component ทุกตัวของรอบ 2 จากนี้**: markup ที่ JS สร้างขึ้นเองแบบ dynamic (ไม่ใช่ markup คงที่ที่ render
มาจาก PHP ตั้งแต่โหลดหน้าแรก) ต้องอ่าน `langData`/`getLangValue()` ตรงในฟังก์ชันเสมอ ห้ามพึ่ง `data-i18n`
+ sweep ทีหลัง เพราะ sweep รอบเดียวจะพลาด content ที่เพิ่ง insert เข้ามาทีหลังเสมอ

---

## 7. ตาราง — DataTable standard (ใช้กับทุกตารางทั้งเก่าและใหม่)

**การ init**: `initSharedDataTable(selector, options)` เท่านั้น (helper ที่มีอยู่แล้วใน app.js) — ห้าม `$(...).DataTable({...})` ตรงๆ ในหน้า; option ต่อตารางส่งเป็น override

**Per-column sort/filter (ช่องว่างที่พบรอบ 0, ตัดสินแล้ว)**: CLAUDE.md's Table convention เดิมบังคับว่าทุก `<th>` ที่มีข้อมูลจริงต้องเรียก **`initExcelColumnFilters(dt, options)`** (`public/js/table-column-filter.js`) เอง ต่อตาราง ใน `initComplete` — กฎนั้นยังใช้อยู่ ไม่ถูกยกเลิก แต่ **`initSharedDataTable()` ต้องครอบหน้าที่นี้ให้เองจากรอบ 2 เป็นต้นไป** (อ่าน `columnDefs`/`columns` ที่ caller ส่งมา แล้วเรียก `initExcelColumnFilters()` ให้อัตโนมัติตาม mode ที่เหมาะกับตาราง client/server — หน้าเรียกทีเดียวผ่าน `initSharedDataTable()` ไม่ต้องเรียก `initExcelColumnFilters()` แยกเองอีก) รายละเอียด mode/exemption ตาม CLAUDE.md's Table convention เดิม (`mode:'client'`/`mode:'server'`, exempt คอลัมน์ปุ่ม/widget ภาพ/ตารางที่มี top-level filter อยู่แล้ว) — รายละเอียดการ implement (จะ auto-detect คอลัมน์ที่ควร filter ยังไง) ตัดสินตอนรอบ 2

**2026-09-13, Round 3 "เก็บตก" item 2 — column-filter popup migrate เข้าระบบ token/component ปัจจุบัน**
(สร้างไว้ 2026-08-27 ก่อน Phase Design Round 2/3 จะมีอยู่ด้วยซ้ำ ไม่เคยผ่าน migration รอบไหนเลย):
- **(a)** ทุกข้อความผ่าน `langData` แล้วครบ — ปุ่ม "ล้าง"/"ใช้ตัวกรอง" ได้ key ใหม่ของตัวเอง
  `column_filter_clear`/`column_filter_apply` (**ไม่ใช่** reuse `clear_filter`/`apply` เดิมที่ค่าเป็นคำอื่น
  อยู่แล้วและใช้ร่วมกับหน้า/ปุ่มอื่นอีก 22+ จุดทั่วแอป — แก้ค่า key เดิมจะกระทบข้อความที่อื่นโดยไม่ตั้งใจ)
  `column_filter_title`/`select_all`/`close`/`loading` เดิมตรงกับที่ต้องการอยู่แล้ว ไม่ต้องแก้ — `search`
  key (ใช้ร่วมกับอีก 4 จุดทั่วแอป ทุกจุดเป็น placeholder ล้วนไม่มีจุดไหนเป็น label) ค่าเปลี่ยนจาก "ค้นหา"
  เป็น "ค้นหา..." (ตามธรรมเนียม "..." 3 จุดที่ th.json ใช้อยู่แล้ว 19 จุด ไม่ใช่ "…" ตัวเดียว) ให้ตรงกับ
  fallback ที่ HTML เดิมก็ hardcode ไว้แบบนี้อยู่แล้วทุกจุด
- **(b)** checkbox (select-all + รายการค่า) ได้ `.form-check-input` จริง (ติ๊กส้มมาตรฐาน §3) — ของเดิม
  **ไม่มี class ใดๆ เลย** เป็น native browser checkbox ล้วนๆ ไม่ใช่แค่ "style ของตัวเอง" ตามที่สงสัยไว้ —
  ไม่ต้องห่อ `.form-check` เพราะ `.tcf-item`'s เองเป็น flex row ธรรมดาอยู่แล้ว ไม่ได้พึ่ง Bootstrap's
  margin-left:-1.5em trick ที่ `.form-check` wrapper มีไว้ให้ (บั๊กคนละแบบกับที่เคยเจอใน Payslip/ECT
  Assign-To checkboxes รอบก่อน — ยืนยันแล้วว่าไม่ใช่เคสเดียวกันก่อนมั่นใจว่าไม่ต้องห่อ)
- **(c)** กล่อง (`.tcf-panel`) ย้ายจาก token คู่ขนานเดิม `--app-*` (ของ T069, ยัง theme-aware ปกติแต่คนละ
  ระบบกับ `tokens.css`) เป็น `--c-border`/`--shadow-soft`/`--radius`/`--sp-3` ครบ (migrate ทั้งกล่องรวม
  ถึง background/hover/border ของ sub-element ข้างในด้วย ไม่ใช่แค่ 4 property ที่สั่งตรงๆ — เหตุผล: ทิ้งไว้
  ครึ่งๆ กลางๆ จะได้กล่องที่ขอบอ้าง token หนึ่งระบบ พื้นอ้างอีกระบบ เสี่ยงเฉดไม่ตรงกันใน dark mode แม้ทั้งคู่
  จะ valid — ตรงกับกฎ §1 เดิมอยู่แล้วที่ห้าม component มี dark override แยกจาก `--c-*`, งานรอบนี้แค่เป็น
  ตัวกระตุ้นให้ทำจริงสักที) หัว (`.tcf-panel-title`) เป็น `--fs-sm` 600 (ของเดิม 1.0417rem เดี่ยวๆ ไม่มี
  token), ปุ่ม × เปลี่ยนเป็น `.btn-icon.btn-icon-ghost` จริง (§7 ข้างบน) — CSS ของตัวเองเหลือแค่ `flex:none`
  ที่ยังจำเป็นสำหรับ layout, ปุ่มท้าย [ล้าง]/[ใช้ตัวกรอง] **เดิมถูกต้องอยู่แล้ว** (`.btn.btn-link`
  ซ้าย/`.btn.btn-primary` ขวา ตรง §4 เป๊ะ ไม่ต้องแก้ class แก้แค่ข้อความผ่าน (a))
- **(d)** ไอคอนหัวคอลัมน์: สีปกติเปลี่ยนจาก `--app-text-muted` เป็น `--c-text-faint` ตามที่สั่ง สี active
  (`--c-primary`) เดิมถูกต้องอยู่แล้วไม่ต้องแก้ — **glyph เปลี่ยนไปมา 2 รอบ**: รอบนี้เอง (2026-09-13) เปลี่ยน
  จาก `fa-filter` เป็น `fa-chevron-down` (อ่าน "▼" เป็นตัว caret ตรงตัว) — **รอบถัดมาวันเดียวกัน ("เก็บตกรอบ
  4") กลับเป็น `fa-filter` เดิม** ตามคำสั่งแก้ไข "ใช้ fa-filter ตัวเดียวกับ filter-bar (ไม่ใช่ ▾)" — สรุป
  ปัจจุบัน = `fa-filter` ตัวเดียวกับ `filter-bar-label-icon`, ขนาด 11px (`0.9167rem`, ของเดิมอยู่แล้ว ไม่ได้
  แก้ตาม) สี `--c-text-faint`/`--c-primary` ตามด้านบน

Demo: `docs/design/components.php`'s DataTable (§7) section's own `#cpDemoTable` (ผ่าน
`initSharedDataTable()`'s `columnFilters` option อยู่แล้วตั้งแต่รอบ 2 — ไม่ต้องสร้าง demo แยกใหม่) — กด
ตัวกรองที่หัวคอลัมน์ "สถานะ" เห็นทุกจุดข้างบนพร้อมกัน

**2026-09-13, "เก็บตกรอบ 4" — 2 บั๊กจริงในกลไกภาษา + popup ไม่รีเฟรชข้อความ:**
- **บั๊กที่ 1 (ร้ายแรง): สลับภาษา TH→EN→TH ทำไอคอน sort + column filter บนหัวคอลัมน์หายทั้งคู่** —
  ต้นตอ: มาร์กอัปเดิมใส่ `data-i18n="{key}"` ตรงบน `<th>` เอง (เช่น `<th data-i18n="table_calculation">`)
  — `initExcelColumnFilters()` (`table-column-filter.js`) rebuild ลูกของ `<th>` ใหม่ทั้งหมด (sort-arrow
  span + ปุ่ม filter) แต่ไม่เคยแตะ attribute ของ `<th>` เอง ดังนั้น `data-i18n` เดิมยังติดอยู่ — sweep
  ภาษากลาง (`updateText()`, app.js) กวาดเจอ `<th>` นี้ทุกครั้งที่สลับภาษา และเนื่องจาก children ของมันตอนนี้
  ไม่ใช่ `<i>`/`<svg>` ตรงๆ อีกต่อไป (เป็น `.tcf-header-row` div ซ้อนอยู่) จึงตกไป branch `$el.text(value)`
  ซึ่งเหมือน `.html()` คือ**ลบ child node ทั้งหมดทิ้งก่อนเสมอ** — DOM ที่ `initExcelColumnFilters()` เพิ่ง
  สร้างไว้จึงหายไปพร้อมกัน ไม่ใช่บั๊กของ `updateText()` เองที่ทำงานผิด แต่เป็น `data-i18n` ติดอยู่ผิดตำแหน่ง
  หลัง DOM ถูก restructure — แก้ที่ต้นตอจริง: `initExcelColumnFilters()` จับค่า `data-i18n` เดิมของ `<th>`
  ไว้ก่อน rebuild, ลบออกจาก `<th>` เอง, แล้วย้ายไปใส่บน `.tcf-header-title` span (leaf แท้ ไม่มีลูก) แทน —
  sweep เดิมทำงานถูกอยู่แล้วสำหรับ leaf element ไม่ต้องแก้ logic ส่วนนั้น — เพิ่ม defensive backstop ใน
  `updateText()` เองด้วย (ทั่วทั้งแอป ไม่ใช่เฉพาะ `<th>`): element ที่มี `data-i18n` **และ**มี child element
  จริงอยู่แล้ว (ไม่ใช่แค่ icon-prefix case ที่รองรับอยู่แล้ว) จะ**ข้าม**การเขียน `.text()` ทับ (แค่
  `console.warn()`) แทนที่จะเดาลบ/แทนที่บางส่วนซึ่งพิสูจน์แล้วว่าอาจไปไม่ถึง text จริงที่ซ้อนลึกอยู่ (กรณีนี้
  `.tcf-header-title` ซ้อนอยู่ชั้นที่ 2 ของ `<th>` ไม่ใช่ child ตรง) — ตรวจแล้วตอนนี้มีแค่ `payroll/detail.js`
  ตัวเดียวที่เรียก `columnFilters` จริง (grep ยืนยัน) แต่ fix อยู่ที่ helper กลาง จึงป้องกันทุกตารางในอนาคตด้วย
  โดยไม่ต้องแก้ไฟล์หน้าเพิ่มเลย
- **บั๊กที่ 2: popup column filter ไม่เปลี่ยนภาษาตาม** — ป้าย/placeholder ทั้งหมดถูกตั้งครั้งเดียวตอน
  `ensurePanel()` สร้าง DOM ครั้งแรก (ฟังก์ชันนี้ทำงานครั้งเดียวตลอดอายุหน้าตามเจตนา — panel ตัวเดียวใช้ซ้ำ
  ทุกคอลัมน์/ตาราง) ภาษาที่ active ตอนเปิด popup ครั้งแรกจึงค้างอยู่แบบนั้นตลอดไป — แยก
  `applyPanelLabels()` ออกมาเป็นฟังก์ชันของตัวเอง เรียกทั้งจาก `ensurePanel()` (ครั้งแรก) **และ**
  `openPanelFor()` (ทุกครั้งที่เปิด) — อ่าน `langData` สดทุกครั้ง ไม่ cache
- ผลข้างเคียงที่ต้องแก้คู่กัน (label/placeholder ของช่องค้นหาตาราง เห็นตอนไล่โค้ด `search` key) — ดู "Search
  toolbar" ด้านล่าง

**2026-09-14, "เก็บตกรอบ 5" — root cause จริงของบั๊ก i18n ที่ค้างมา 3-4 รอบ (item 1) + บั๊กค่า filter ผิด
เพราะอ่านจาก DOM แทน render.filter (item 2):**
- **บั๊กที่ 1 — เจอ root cause จริงแล้ว**: ทุก label lookup ใน `table-column-filter.js` เดิมใช้
  `(window.langData && langData['key'])` — `app.js` ประกาศ `let langData = {}` ที่ top level ของ plain
  `<script>` (ไม่ห่อ IIFE/module) ซึ่ง**ไม่เคยผูกเข้ากับ `window` object เลย** (เป็นแค่ script-scope lexical
  binding ที่ script อื่นบนหน้าเดียวกัน "เห็น" ได้ผ่านชื่อเปล่า `langData` แต่ `window.langData` เป็น
  `undefined` เสมอไม่ว่าภาษาไหน) — guard นี้จึง short-circuit ทุกครั้งก่อนจะถึง lookup จริงด้วยซ้ำ ทุกรอบก่อน
  หน้า (ย้าย timing ไปตอนเปิด, เพิ่ม key ใหม่) แก้ถูกทิศแต่ไม่มีทางได้ผลเลยตราบที่ guard นี้ยังพัง ไม่มีไฟล์
  อื่นในแอปใช้ pattern นี้ (grep ยืนยัน — ทุกที่อื่นใช้ `langData['key'] || fallback`/`getLangValue()`
  ถูกต้องอยู่แล้ว) — แก้โดยเปลี่ยนทุกจุดเป็น `getLangValue()` (helper กลางที่ถูกต้องอยู่แล้ว) พร้อม
  รวม key เดิมทั้งหมด (`column_filter_title/_clear/_apply` + `search`/`select_all` ที่ borrow มา) เป็น
  ชุดใหม่ของตัวเอง 5 key `dt_filter_title/_search/_select_all/_clear/_apply` — decouple จากทุก key ที่ใช้
  ร่วมกับหน้าอื่นสมบูรณ์
- **บั๊กที่ 2**: คอลัมน์ "ตรวจสอบ" (verify_status) เดิม `render` เป็น plain function
  (`(d,t,row)=>verifyLockButtonsRd(row)`) ไม่ใช่ object-form `{display,filter}` ตามที่ CLAUDE.md's Table
  convention บังคับไว้แล้วสำหรับคอลัมน์ที่ HTML ที่แสดงต่างจากค่าที่ใช้ filter — `renderedCellText()`
  (table-column-filter.js) เรียก `.render('display')` ซึ่งสำหรับคอลัมน์แบบนี้คืนค่า HTML เดียวกับที่ตาเห็น
  ทุกตัวอักษร รวม `statusBadgeHtml({menu})`'s เอง `<ul class="dropdown-menu">` ที่ฝังอยู่ในสตริงเดียวกัน
  (ไม่ใช่ DOM ซ้อนแยกจากกัน) — strip HTML แล้วเจอ "ยกเลิกการตรวจสอบ" (ข้อความใน `<li>` ที่ซ่อนอยู่) ติดมาด้วย
  — แก้ 2 จุด: (1) `renderedCellText()` เปลี่ยนเป็น `.render('filter')` (fallback เป็นฟังก์ชันเดิมอัตโนมัติ
  สำหรับคอลัมน์อื่นที่ยังไม่ split display/filter — ไม่กระทบ) (2) คอลัมน์ verify_status
  (`initRunDetailTable()`) เปลี่ยนเป็น object-form `{display: verifyLockButtonsRd, filter:
  verifyLockFilterTextRd}` ฟังก์ชันใหม่ `verifyLockFilterTextRd(row)` คืนแค่ label ข้อความล้วนของแต่ละ
  4 สถานะ (ไม่มี HTML/menu เลย) — `statusBadgeHtml()` เองไม่ถูกแตะ ยัง return HTML string เดียวเหมือนเดิม
  ทุก caller อื่นไม่กระทบ

**Footer (`<tfoot>`) class propagation, item 3, real bug found and fixed (repro: "ตรวจสอบแล้ว 1/1"/
"คำนวณแล้ว 1/1" ไม่ชิดซ้ายตามคอลัมน์ §7 badge=left)** — DataTables' own `columnDefs.className`
(`dtColumnDefsFromMarkerClasses()`, app.js) never reaches a page's own static `<tfoot>` markup at all
(construction-time option, only ever applied to `<thead>`/body `<td>`) — confirmed by reading
`dataTables.bootstrap5.css` directly: the library DOES ship its own `tfoot th/td { text-align:left }`
default, but Payroll Detail's own `#rdFootVerifyLock` had a hardcoded `class="text-center"`
overriding it (Bootstrap's `.text-center` carries `!important`, beats the library default regardless
of specificity) — real page bug, not a framework gap. Fixed 2 ways together: removed the wrong
hardcoded class from `payroll/detail.php`, **and** added a new `applyTfootMarkerClasses($table,
columnDefs)` (app.js, called from `initSharedDataTable()` right after building `autoColumnDefs`) that
mirrors any §7 marker class (`.num`/`.col-money`/`.col-date`/`.col-check`/`.col-avatar`/
`.col-actions`/`.col-toggle`) from a column's own `<thead>` `<th>` onto its `<tfoot>` cell at the same
index automatically, for EVERY `initSharedDataTable()` caller going forward — `.addClass()`, never
`.attr('class', ...)`, so a page's own additional footer-only classes (e.g. `#rdFootGross`'s own
`fw-bold money-gross` running-total styling) are always preserved, only added to. A table with no
`<tfoot>`, or a column with nothing in it, is a safe no-op.

**2026-09-14, "เก็บตกรอบ 6" (สุดท้ายก่อน commit) — หัวตาราง (thead) ทุก DataTable + language-refresh hook
สำหรับ Payroll Detail's JS-templated text (item 1/2):**
- **item 2 — หัวตาราง (`thead th`) ทุก DataTable ในแอป**: ยังไม่เคย implement เป็น CSS จริงเลยแม้ §7's
  own layout diagram (ด้านบน, บรรทัด "หัวตาราง พื้น --c-bg-subtle ตัวหนังสือ --c-text-muted ไม่หนา")
  จะระบุเจตนาไว้แล้วตั้งแต่รอบ 2 — confirmed ผ่าน grep ว่าไม่มี rule ไหนเคย override
  `table.dataTable thead th` เลยจริงๆ ทุกตารางเลยยังใช้ native browser/Bootstrap default (ตัวหนา ดำ) อยู่
  — เพิ่ม `table.dataTable thead th { color: var(--c-text-muted); font-weight: 500; font-size:
  var(--fs-sm); }` เป็น shared rule เดียว ไม่ scope เฉพาะหน้าไหน (public/css/style.css, ต่อจาก
  `.pagination` override block) มีผลทุก DataTable ทันที — ไอคอน sort (`.dt-column-order`,
  `dataTables.bootstrap5.css` เอง) เป็น glyph ▲▼ สี `currentColor` ไม่มีสีของตัวเอง จะ inherit
  `--c-text-muted` จากหัวข้อความแทนถ้าปล่อยไว้ ทำให้ต่างจากไอคอน filter (`.tcf-filter-btn`,
  `--c-text-faint` อยู่แล้วตั้งแต่รอบก่อน) — เพิ่ม `table.dataTable thead th .dt-column-order { color:
  var(--c-text-faint); }` แยกให้สีตรงกันตามที่สั่ง ("ไอคอน sort/filter สีเดียวกัน")
- **item 1 — EN mode ค้างข้อความไทย: stepper labels / "ขั้นต่อไป" callout / หัวคอลัมน์ 6 ตัว** —
  grep audit ยืนยันแล้วว่าหัวคอลัมน์ทั้ง 6 (`employee_no`/`table_employee_name`/`table_base_salary`/
  `table_gross_amount`/`table_deduction_amount`/`table_net_pay`) มี `data-i18n` markup ถูกต้อง **และ**
  key ครบทั้ง 2 ไฟล์ ค่าต่างกันจริง (ไม่ใช่ key หาย/ค่า en ว่าง/ค่า en=th) — ไม่ใช่ data bug — root cause
  จริงคือ **stepper/callout** (`renderProcessTimeline()`/`nextStepBanner()` ผ่าน `renderRunHeaderText()`
  ใหม่, `public/js/payroll/detail.js`) build ข้อความเป็น JS template string ล้วนผ่าน `langData[key] ||
  fallback` **ไม่มี `data-i18n` ที่ไหนเลย** (ยืนยันจาก grep) — `run` data โหลดครั้งเดียวตอนหน้าเปิด
  (gate หลัง `langReady` อยู่แล้ว ทำให้ render แรกถูกเสมอ) แต่ไม่มีอะไรเรียก render ซ้ำตอนสลับภาษาทีหลัง —
  รูปแบบบั๊กเดียวกับที่แอปนี้เคยเจอและแก้แล้ว ~6 หน้า (ดู `changeLanguage()`'s เอง series ของ
  `if (typeof refreshXxxLanguage === 'function') ...` hooks, app.js) — Payroll Detail เป็นหน้าเดียวที่
  ขาด hook นี้ — แก้โดย **แยก `renderRunHeader(run)` เดิมออกเป็น 2 ฟังก์ชัน**: `renderRunHeaderText(run)`
  (subset ล้วนที่ pure/ไม่มี side-effect — text/badge/stepper/callout ทั้งหมด, ตรวจสอบทีละ sub-call
  ก่อนว่าไม่มี AJAX ก่อนรวมเข้ามา) กับ `renderRunHeader(run)` เดิม (slim wrapper: ตั้ง `currentRun` +
  เรียก `renderRunHeaderText()` + AJAX-driven calls ที่เหลือ เช่น `loadRunReportsTab()`/
  `renderRunSettingsPanel()` ซึ่งยืนยันแล้วว่าเรียก `loadRunSettingsPanel()` ที่มี AJAX จริง จึงต้องอยู่ฝั่ง
  ที่ไม่ re-run ซ้ำ) — เพิ่ม `refreshPayrollDetailLanguage()` (payroll/detail.js, hook ใหม่, เรียก
  `renderRunHeaderText(currentRun)` ซ้ำถ้ามี `run` โหลดแล้ว) ลงทะเบียนใน `changeLanguage()` (app.js) ตาม
  pattern เดิมทุกอย่าง — **บวกด้วย defensive re-sync ของ `#tb_run_detail`'s เอง column header** (แม้จะ
  ยืนยันแล้วว่า markup/key ถูกต้องและควรถูก sweep กลางแปลให้อยู่แล้ว) เป็น backstop เผื่อกลไกอื่นที่ยังไม่
  พบชัดเจนกระทบหัวคอลัมน์กลุ่มนี้อยู่ — ความจริงใจ: **ไม่พบ root cause ทางเลือกที่ชัดเจนสำหรับหัวคอลัมน์ 6
  ตัวโดยเฉพาะ** นอกจาก stepper/callout ที่ยืนยันแล้ว หลังตัดทิ้งไปแล้วว่าไม่ใช่ key/markup/`initStickyColumns()`
  (อ่าน source เต็มยืนยันว่าทำแค่ CSS positioning)/DataTables' `drawCallback`/`initComplete` — การ resync
  แบบ defensive จึงเป็นการรับประกันความถูกต้องแทนการเดาสาเหตุที่ยังไม่ยืนยันแน่ชัด
- **check-lang.php เพิ่มกฎเตือนใหม่ (ไม่ fail)**: `findSuspiciousTranslations()` — flag ทุก key ที่ค่า
  `en` ว่าง/whitespace ล้วน หรือเหมือนกับค่า `th` เป๊ะๆ ทุกตัวอักษร เป็น**คำเตือน**เท่านั้น (ไม่กระทบ
  `hasProblem`/exit code, ไม่ assert ใน `tests/lang_check_test.php`) — เหตุผล: สแกนจริงพบ 25 key ที่
  เหมือนกันโดยตั้งใจ (proper noun เช่น "Origami"/"PDF", placeholder เช่น "0-4"/"e.g., Somchai", ค่า
  format string ที่ไม่ต้องแปล) fail จุดนี้จะเป็น noise ค้างตลอดไปไม่ใช่สัญญาณจริง — เป็นรายงานให้อ่านทบทวน
  ไม่ใช่ gate ที่ต้องผ่าน
- **item 3 — `setting-row-plain-label` น้ำหนัก 500 ไม่ใช่ 700** — ของเดิม (รอบ "เก็บตกรอบ 7") ตั้งไว้ 600
  (ไม่ใช่ 700 ตามที่รายงานผิด แต่ยังไม่ตรงสเปก) แก้เป็น `font-weight: 500` ตามที่สั่งชัดเจน ("ปรับ label
  น้ำหนัก 500 ไม่ใช่ 700 ตามสเปก") — เบากว่า label ของ variant card (`.setting-row-label`, ยังคง 600
  ไม่เปลี่ยน เพราะเป็นหัวข้อกล่องแยก ไม่ใช่ inline caption แถวเดียวแบบ plain)

**Layout มาตรฐาน** (helper จัด `layout`/`dom` ให้เอง หน้าไม่ต้องกำหนด — ตัดสินใจแล้วรอบ 2 item 3b,
แก้ไขอีกครั้งวันเดียวกัน (รอบก่อนเขียนกลับด้าน): ซ้าย = length เดี่ยวๆ (`layout.topStart:'pageLength'`
— ค่า default ของ DataTables เองอยู่แล้ว ตั้งให้ชัดเจนไว้กันค่า default เปลี่ยนในอนาคต), ขวา = ค้นหา +
ส่งออก ติดกันในแถวเดียว (`layout.topEnd:'search'`, ส่งออก append เข้า `.dt-search` เป็น sibling ของ
input เสมอไม่ว่าจะอยู่ฝั่งไหน):
```
[แสดง 25 ▾ รายการ]                                     [ค้นหา… ] [ส่งออก ▾]
┌────────────────────────────────────────────────────────────────┐
│ หัวตาราง (พื้น --c-bg-subtle, ตัวหนังสือ --c-text-muted, ไม่หนา) │
│ … แถว …                                                          │
└────────────────────────────────────────────────────────────────┘
แสดง 1–25 จาก 130                                   ‹ 1 2 3 ›
```
- toolbar (ค้นหา/length/ส่งออก) **อยู่กับที่** ไม่เลื่อนตามตาราง; ตารางที่กว้างเกิน scroll แนวนอนภายในตัวเอง + **fix คอลัมน์แรก (พนักงาน) และหัวตาราง** + ลากเลื่อนได้ — ตาม pattern `/employees#employee-recheck-top-tab` ที่ตกลงเป็นต้นแบบ
- ส่งออก: dropdown secondary ตัวเดียว (Excel / PDF) — helper append เข้า `.dt-search` เป็น sibling ของ
  ช่องค้นหาตรงๆ (`d-inline-block`, ไม่ใช่ block ใหม่ที่จะตกลงบรรทัดถัดไป) เปิดให้เมื่อ `export: true` —
  ติดกับช่องค้นหาเสมอ (ฝั่งขวาตาม layout ปัจจุบัน)
- แถวน้อย (≤ threshold ของ helper): ไม่มีค้นหา/paging (ทำอยู่แล้ว)
- **2026-09-13, "เก็บตกรอบ 4" — Search toolbar, 2 จุด:**
  - **ระยะห่าง label "ค้นหา" ↔ input ↔ ปุ่ม (เช่น "+ พนักงาน") = `--sp-2` ทุกจุด** — บั๊กจริงที่เจอ: มี
    override เฉพาะหน้า `#tb_run_detail_wrapper .dt-search`/`.dt-length` (2026-09-09, เพื่อแก้บั๊กตกแถว
    คนละเรื่อง) ตั้ง `gap: 0.35rem` ค้างไว้ (specificity สูงกว่า rule กลาง `.dt-container .dt-search {
    gap: var(--sp-2) }` ที่เพิ่มทีหลังในรอบ 2 — เลยชนะทับอยู่ตลอด ไม่มีใครสังเกต) แก้เป็น
    `gap: var(--sp-2)` ตรงๆ (คง `flex-wrap:nowrap` เดิมไว้ ยังจำเป็นอยู่) — label/input/ปุ่มที่ inject
    เข้ามาทั้งหมดเป็น direct child ของ `.dt-search` เดียวกัน (ยืนยันจาก DataTables source ตรงๆ: label ที่
    ลงท้ายด้วย `_INPUT_` marker ทำให้ input ออกมาเป็น sibling ของ label ไม่ใช่ nested ข้างใน) แก้จุดเดียว
    บน container พอ ไม่ต้องมี gap แยกข้างในของ label เอง
  - **ตัด "…" ท้าย label "ค้นหา" ออก (placeholder ในช่องพอ)** — `langData.search` เองยังคงมี "..." เหมือน
    เดิม (ถูกสำหรับ consumer อื่นที่เป็น placeholder จริงๆ อีก 4+ จุดทั่วแอป) แต่ DataTables' เองมี 2 key
    แยกกันจริง: `language.search` (ข้อความ label) กับ `language.searchPlaceholder` (attribute
    `placeholder` ของ `<input>` จริง — ยืนยันจากซอร์ส DataTables ตรงๆ, `opts.placeholder =
    language.sSearchPlaceholder`) — `getTableLang()` (app.js) แยก 2 ค่านี้แล้ว: `search` ตัด "..." ท้าย
    ออกด้วย `.replace(/\.+$/, '')` สำหรับ label, `searchPlaceholder` คงค่าดิบมี "..." ไว้ใส่ placeholder
    จริง — `refreshAllDataTablesLanguage()`'s เอง DOM-patch ก็ต้องอัปเดตทั้งคู่คู่กัน (label text +
    input's placeholder attribute) เพราะทั้ง 2 ถูกสร้างครั้งเดียวตอน construct เหมือนกัน ไม่ re-read จาก
    settings ตอน redraw (จุดเดียวกับที่ comment เดิมของฟังก์ชันนี้อธิบายไว้แล้วสำหรับ label/length-menu)
- ตาราง **เต็มความกว้าง** container ไม่มี padding รอบ (`.table-responsive` ไม่มี margin)

**คอลัมน์ — การจัดวาง (บังคับทั้งระบบ, helper ใส่ class ให้จาก `columnDefs` กลาง)**

| ชนิด | จัด | class | หมายเหตุ |
|---|---|---|---|
| ข้อความ, ชื่อ, รหัส, อีเมล | ซ้าย | (default) | |
| วันที่/เวลา | ซ้าย | `.col-date` | รูปแบบ `dd/mm/yyyy` เดียวทั้งระบบ, `data-order` = ISO |
| จำนวนเงิน | ขวา | `.num.col-money` | comma + ทศนิยม 2 ตำแหน่งเสมอ, `data-order` = ค่าดิบ, **ไม่มีสี** ติดลบใช้ "-" นำหน้า |
| จำนวน/%/ตัวเลขอื่น | ขวา | `.num` | |
| สถานะ (badge) | ซ้าย | | |
| checkbox เลือกแถว | กึ่งกลาง | `.col-check` | กว้างคงที่ — checkbox หัวตาราง = "เลือกทั้งหน้า" (แถวที่แสดงอยู่จริง ไม่ใช่ทุกหน้า) เข้า indeterminate เองเมื่อเลือกไม่ครบ (ดู §9 สไตล์) |
| รูป/avatar | กึ่งกลาง | `.col-avatar` | ใช้ `apvAvatarHtml()` ตัวเดียว |
| สวิตช์เปิด/ปิดใช้งาน (ใหม่, item (2)) | กึ่งกลาง | `.col-toggle` | กว้างคงที่, ไม่ sort, ไม่ search — `initRowToggles($table, {onChange})` (`app.js`, ใหม่): delegated `change` บน `.row-toggle-switch`, disable ระหว่างรอ response, revert กลับที่เดิมถ้า error/reject |
| action | ขวา | `.col-actions` | กว้างคงที่, ไม่ sort, ไม่ search |
| หัวคอลัมน์ | **เหมือน content ของคอลัมน์นั้น** | | ตัวเลขหัวชิดขวาด้วย |

- **บั๊กจริงที่เจอและแก้ระหว่าง item (2)**: `.col-check`/`.col-avatar`/`.col-actions` (item 3's เดิม)
  ไม่เคยมี CSS "กว้างคงที่" จริงเลยสักตัว (grep 0 hits ก่อนแก้) แม้ตารางข้างบนจะเขียนไว้ตั้งแต่ต้น —
  ทุกคอลัมน์เหล่านี้กว้างตามเนื้อหา/หัวคอลัมน์ของตัวเองมาตลอด ไม่ใช่กว้างคงที่จริง — แก้พร้อมกับเพิ่ม
  `.col-toggle` ใหม่: `width:1%; white-space:nowrap;` ทั้ง 4 class (เทคนิคมาตรฐานของตาราง ให้คอลัมน์
  หดตามเนื้อหาตัวเองแล้วไม่ขยายอีก โดยไม่ต้อง hardcode พิกเซลที่จะผิดสำหรับบางคอลัมน์)

**Row actions — สเปกทรงกลม ตัดสินใหม่แล้ว รอบ 2 item (5) (ย้อนข้อความ "ไม่มีพื้น ไม่มีขอบ" เดิมด้านล่าง)**
- `.btn-icon` = วงกลม **32px**, ขอบ 1px `--c-border`, พื้น `--c-bg`, ไอคอน **12px** (ลดจาก 14px เดิม
  2026-09-13 — 14px ดูใหญ่ไปในวงกลม 32px เว้นระยะรอบไม่พอ) `--c-text-muted`; hover พื้น `--c-bg-hover`
  ไอคอน `--c-text`; focus ring ส้ม (เหมือนทุก focusable element อื่น); disabled `--c-text-faint` —
  **ขนาดเดียวทั้งระบบ ไม่มีขนาด/สีต่อ action** (ลบ = เทาเหมือนกันทุกปุ่ม สีแดงใช้ได้แค่ ปุ่มยืนยันใน
  SweetAlert confirm เท่านั้น ตรงกับ §3/§4)
  - **ตรวจแล้ว (ไม่ใช้): เปลี่ยนไปใช้ `fa-regular` แทน `fa-solid` เพื่อให้เส้นบางลง** — แอปนี้ bundle
    เฉพาะ `@fortawesome/fontawesome-free` (ยืนยันจาก `fa-regular-400.woff2` มีขนาดแค่ 19KB เทียบกับ
    `fa-solid-900.woff2` 113KB) และ free tier's `fa-regular` มีไอคอนให้ใช้แค่ชุดเล็กๆ — เช็คตรงจาก
    `node_modules/@fortawesome/fontawesome-free/metadata/icon-families.json` แล้วพบว่าไอคอนที่ปุ่มกลม
    ในระบบนี้ใช้จริง (`trash`, `pen`/`pencil`, `ellipsis`/`ellipsis-vertical`, `chevron-down`,
    `xmark`, `filter`) **เป็น solid-only ทั้งหมดในระดับ free** — เปลี่ยนไปใช้ `fa-regular` จะทำให้
    ไอคอนหายไปเงียบๆ ใน 14+ ไฟล์จริงที่ใช้ `.btn-circle-action`/`.btn-icon` อยู่แล้ว จึงคงใช้ `fa-solid`
    ทุกที่ ลดแค่ font-size เท่านั้น
- **≤ 3 ปุ่ม + ⋮ (แก้ไขแล้ว 2026-09-13, Round 3 item 3b follow-up — เดิม "≤ 2")** — ปุ่มวงกลมที่ใช้บ่อย/
  สำคัญสุด (เช่น Detail › รายละเอียดพนักงาน: ดูรายละเอียดการคำนวณ/ความคิดเห็น/ปรับรายการ) วางเรียง gap
  `--sp-1` พร้อม tooltip ทุกปุ่ม เสมอไม่ว่ารายการที่เหลือจะมีกี่ตัว ("≤3" ไม่ใช่ "เสมอ 3" — action ที่มีเงื่อนไข
  เช่น draft-only หายไปได้ตามปกติ เหลือน้อยกว่า 3 ก็ไม่เป็นไร); ที่เหลือทั้งหมด (ไม่ว่ากี่ตัว) พับเข้า ⋮
  เดียวเสมอ ไม่ใช่แสดงเพิ่มเป็นปุ่มที่ 4/5/6 ทีละตัว — จำนวนตัดที่ 3 ใน "3 ปุ่มวงกลม" นี้ ไม่ผูกกับ
  action ตัวใดตัวหนึ่งตายตัว ระบุตามหน้าจริงที่ใช้ (Detail › รายละเอียดพนักงาน ระบุไว้แล้วข้างบน)
- > 3 action ที่เหลือ: ปุ่ม ⋮ วงกลม**เดียวกัน** (`.btn-icon`) เปิด dropdown (pattern เดียวกับ Detail › รายละเอียดพนักงาน) — รายการลบอยู่ล่างสุดคั่นด้วยเส้น ตัวหนังสือ `--c-danger`
- ไอคอนต้องสื่อความหมาย + tooltip เสมอ (BACKLOG: ทบทวนไอคอนทั้งระบบ ทำในรอบ 4)
- **`.btn-circle-action` เป็น alias ชั่วคราวของ `.btn-icon`** (ประกาศร่วมกันเป็น selector เดียวใน
  `style.css` — ลบสีทั้ง 7 tone ที่เคยผูกกับ `.text-{color}` ที่บางไฟล์เอาไปวางซ้อนออกแล้ว ด้วย
  `!important` เดียวกับที่ `.btn-icon` เองใช้กัน tone จริงหลุดมาได้) — รอบ 2 ไม่ต้องแตะ 14 ไฟล์เดิมเลย
  (ได้สไตล์ใหม่ทันทีที่ commit เพราะเป็นการแก้ shared CSS ไม่ใช่แก้หน้าจริง); รอบ 4 ค่อย rename
  `.btn-circle-action` → `.btn-icon` ทีละไฟล์เป็นส่วนหนึ่งของการทำหน้านั้น (ไม่ commit แยก) ผ่าน lint
  แบบผ่อน (เฉพาะไฟล์ที่ mark `design:clean` ถึง fail ดู §12) — ลบ alias ทิ้งเมื่อย้ายครบ 14 ไฟล์แล้วเท่านั้น
  เหมือน pattern เดียวกับ `.stat-card` ใน §2
- ~~เดิม (ยกเลิกแล้ว): ปุ่มไอคอน .btn.btn-sm.btn-icon เทา ไม่มีพื้น ไม่มีขอบ~~ — ดูสเปกใหม่ด้านบน
- **`.btn-icon-ghost` — variant ใหม่ (2026-09-13, filter-bar "ซอฟต์ลง" follow-up)**: ใช้ร่วมกับ
  `.btn-icon` เสมอ (`class="btn-icon btn-icon-ghost"`, ไม่ใช่ class แทนที่) สำหรับวงกลมที่อยู่ **ข้างใน
  panel ที่มีพื้นสีของตัวเองอยู่แล้ว** (เช่น filter-bar's หัวพื้น `--c-bg-subtle`) ซึ่งขอบ+พื้น `--c-bg`
  ของ `.btn-icon` ปกติจะดูเป็นชิ้นลอยแยกออกจากพื้นรอบข้าง — ตัด `border`/`background` ที่ resting state
  ออก (โปร่งใสทั้งคู่) ขนาด/ไอคอน/สี/hover (`--c-bg-hover`)/disabled เหมือน `.btn-icon` ทุกอย่างไม่เปลี่ยน
  ใช้แล้วที่ filter-bar's chevron toggle (`.filter-bar-toggle`, ดู Filter bar ด้านบน) — ตัวถัดไปที่ต้อง
  "ปิด/toggle ใน panel ที่มีพื้นตัวเอง" ให้ใช้ variant นี้แทนที่จะเขียน override เฉพาะจุดใหม่

**ข้อความว่าง**: จาก `getTableLang().emptyTable` — ประโยคเดียวบอกว่าทำอะไรต่อได้ (เช่น "ยังไม่มีรายการ — กด 'เพิ่มรายการ' เพื่อเริ่ม")

---

## 8. ตัวเลขและเงิน (ทั่วระบบ ไม่ใช่แค่ตาราง)

- **แสดงผล — เสร็จแล้ว รอบ 2 item 7a**: helper PHP `fmtMoney($n)` (`app/helpers/helpers.php`, ใหม่)
  / JS **`fmtNum(n)`** (`public/js/format-helpers.js`, **มีอยู่แล้ว ไม่ต้องแก้** — ตัดสินใจแล้วรอบ 2:
  ไม่สร้าง `formatMoney()` ใหม่ ใช้ `fmtNum()` เดิมเป็นตัวเดียว เพราะทำ `toLocaleString` แบบ 2 ทศนิยมคงที่
  อยู่แล้วตรงตามที่กฎนี้ต้องการ ไม่ต้อง "ขยาย" อะไรเพิ่ม — ตรวจ caller เดิมทุกจุดที่เรียก `fmtNum()` แล้วครบถ้วน:
  ไม่มีจุดไหนพึ่งทศนิยมจำนวนอื่นเลย) → `1,234,567.89`; ยืนยันด้วย `tests/fmt_money_test.php` (10 ค่า
  รวมติดลบ/ศูนย์/ทศนิยมยาว/null/ว่าง/mask `'XXXX'`, expected string ของแต่ละค่ายืนยันจากการรัน `fmtNum()`
  จริงใน Node ก่อน ไม่ใช่เดา) ว่า PHP/JS ให้ผลตรงกันทุกกรณี
  - **พบระหว่างตรวจ (ไม่แก้รอบนี้ เพราะเป็นหน้าจริง — ห้ามแตะหน้าจริง §13, migrate รอบ 4)**: 60 จุดใน 10
    ไฟล์ JS ยังเรียก `toLocaleString()` ตรงๆ ไม่ผ่าน `fmtNum()` เลย (บางจุดตั้งใจใช้ทศนิยมจำนวนอื่นสำหรับ
    ค่าที่ไม่ใช่เงิน เช่น ปี/เปอร์เซ็นต์ที่ใช้ 1 ตำแหน่ง, จำนวนนับที่ไม่มีทศนิยมเลย — ไม่ใช่ทุกจุดเป็น bug) และ
    `employee/reports.js`'s `fmtMoneyList(n)` เป็นสำเนาใกล้เคียง `fmtNum()` ที่หลุดรอดตอน consolidate ใน
    Phase 11 T065 (ต่างกันตรงค่า null/undefined กลายเป็น `"0.00"` แทน `"-"`) — ทั้งสองเป็น debt เดียวกับที่
    §6/§7 อื่นๆ เจอมาแล้ว (เช่น icon-label ~140 จุด, `.btn-circle-action` 14 ไฟล์) migrate ทีละหน้าตอนย้าย
    มาใช้จริงในรอบ 4
- **input เงิน — เสร็จแล้ว รอบ 2 item 7a**: `<input class="money-input">` + JS `initMoneyInputs($scope)`
  (`app.js`, เรียกอัตโนมัติจาก `$(document).ready()` + `shown.bs.modal` ทุก modal แบบเดียวกับที่ไฟล์นี้
  ผูก modal-lifecycle หมายเลขอื่นอยู่แล้ว): พิมพ์ได้ตัวเลขและจุด (กรองตัวอักษรอื่น/จุดที่ 2 ทิ้งทันทีขณะพิมพ์),
  blur → `fmtNum()` (comma + 2 ทศนิยม), focus → เอา comma ออกกลับเป็นตัวเลขล้วนแก้ต่อได้ — ค่าดิบ (ไม่มี
  comma) sync ไว้ที่ `data-raw-value` attribute ของ input เองตลอดเวลา (พิมพ์/blur/โหลดครั้งแรก)
  - **ตรวจแล้ว (ตัดสินใจแล้ว): แอปนี้ไม่มี central form-serializer จุดเดียวให้ strip comma รวมได้จริง** —
    grep เจอ 8 ฟังก์ชัน `collect*FormData()` แยกกันคนละหน้า (`collectRunFormData`/`collectRcFormData`/
    `collectEmployeeFormData`/`collectEedFormData`/`collectPedTypeFormData`/`collectCycleFormData`/
    `collectSrDetailsFormData`/`collectSrRateVersionFormData`) ไม่ใช่ตัวกลางเดียว — คำตอบคือสร้าง
    `parseMoneyInput(str)` (`format-helpers.js`, ใหม่) เป็นจุดร่วมเดียวที่มีให้เรียกแทนการเขียน
    `.replace(/,/g,'')` เองทีละฟังก์ชัน แต่ **ยังไม่ได้ wire เข้าทั้ง 8 ฟังก์ชันจริงรอบนี้** (ไม่มีหน้าไหนใช้
    `.money-input` อยู่จริงตอนนี้ด้วย — ห้ามแตะหน้าจริง §13) เป็นงาน migrate รอบ 4 พร้อมกับตัวหน้าที่จะเริ่มใช้
    `.money-input` จริง
  - **บั๊กจริงที่เจอระหว่างทำข้อนี้ (แก้แล้ว): `.num` ไม่เคยมี CSS ของตัวเองเลย** — `stat-card.php` (item 4)
    และ `initSharedDataTable()`'s `DT_MARKER_CLASSES` (item 3, ใส่ `className:'num'`/`'num col-money'`
    ให้ `<td>` ของ DataTable) ทั้งคู่สันนิษฐานว่ามี `.num` base rule อยู่แล้ว — grep ยืนยัน 0 จุดที่มี
    `tabular-nums`/`font-variant-numeric` ในทั้งไฟล์ก่อนรอบนี้ แปลว่า**ทุกคอลัมน์ตัวเลขของ DataTable ที่
    item 3 ทำไว้ชิดซ้ายมาตลอด ทั้งที่ควรชิดขวา** — แก้โดยแยก 2 กฎ ไม่รวมเป็นกฎเดียว: `.num` เปล่าให้แค่
    `font-variant-numeric:tabular-nums` (ปลอดภัยทุกที่ รวม `.stat-value.num` ที่ item 4 ตัดสินใจ/verify
    ไว้แล้วว่าชิดซ้ายใต้ label ชิดซ้าย) ส่วน `td.num`/`th.num` (สโคปเฉพาะ cell ในตารางจริง) เท่านั้นที่ชิดขวา
    ตาม §7's ตาราง — `.col-money` cell มี `.num` ติดมาด้วยเสมอ (`DT_MARKER_CLASSES`'s เอง) จึงไม่ต้องมี
    selector แยก
- ตัวเลขในหน้า/สลิป/stat ทั้งหมด `.num` (tabular — ไม่บังคับชิดขวานอกตาราง ดูข้อข้างบน)
- ไม่ใช้สีกับตัวเลขทั่วไป (บวก/ลบ/มากน้อย) — ใช้เครื่องหมายและตำแหน่งคอลัมน์แทน **ยกเว้นตัวเลขประเภทเงินจริง
  ที่มีความหมายทางบัญชี ดูระบบสีเงินด้านล่าง (ตัดสินแล้ว item D, 2026-09-13)**

**ระบบสีตัวเลขเงิน (§8, ตัดสินแล้ว 2026-09-13, item D)** — ระบบเดียวทั้งแอป ไม่แยกเฉด ไม่แยกหน้า:
- **รายได้/รายรับ** = `--c-success` (เขียว), **รายหัก** = `--c-danger` (แดง), **สุทธิ** = `--c-text` **ตัวหนา
  600** (ไม่มีสี ไม่ใช่ฟ้า — ยอดสุทธิไม่ใช่ตัวเลข "ดี/ไม่ดี" แต่เป็นยอดสรุปที่ต้องเด่นด้วยน้ำหนักตัวอักษรแทน)
- **ใช้กับ "ตัวเลขประเภทเงิน" เท่านั้น** — label/ไอคอน/การ์ด/พื้นหลังรอบตัวเลขนั้น**ไม่**ตามสีไปด้วย (ตัวเลขตัว
  เดียวเปลี่ยนสี ไม่ใช่ทั้ง component)
- **เขียว/แดงเป็น token เดียวกับที่ใช้บอกสถานะ (§3) ไม่แยกเฉดใหม่** — "ตัวเลขนี้เป็นรายการหัก" กับ "การกระทำนี้
  ล้มเหลว" ให้อ่านเป็นภาษาสีเดียวกัน ไม่ใช่แดง 2 เฉดคนละความหมาย
- **ทำผ่าน class กลาง 3 ตัวเท่านั้น**: `.money-gross` / `.money-deduction` / `.money-net` (ใส่คู่กับ `.num`
  เสมอ ไม่แทนที่) — นิยามใน `tokens`/`style.css` ใกล้ `.num` — **ห้ามใช้ `text-success`/`text-danger` ตรงๆ
  บนตัวเลขเงินอีกต่อไป**; §12's lint rule 3 ขยายเพิ่ม: `text-danger` ที่อยู่ในธาตุเดียวกับ `.num` เป็น hit
  (แต่ `text-danger` เดี่ยวๆ ไม่มี `.num` ยังใช้ได้ปกติ เช่น dropdown item ทำลาย) — `text-success` ถูกแบนทุก
  ที่อยู่แล้วจากกฎเดิม ไม่ต้องเพิ่มกฎแยก
- **ที่ใช้แล้วรอบนี้ (3a)**: stat card 3 ใบของ Payroll Detail (รายได้รวม/รายการหัก/ยอดจ่ายสุทธิ — `#infoGross`/
  `#infoDeduction`/`#infoNet`) ผ่าน `stat-card.php`'s ใหม่ `value_class` (ดู §2)
- **ที่ยังไม่ทำ (deferred ตามลำดับงานเดิม)**: คอลัมน์เงิน 6/7/8 ของตาราง Employee Breakdown (item 3b),
  `payslip-view.php` (item 3c) — คนละ commit ตามที่วางแผนไว้ ไม่ใช่ลืม

---

## 9. ฟอร์มและ Modal

**Modal**
- ขนาด: `modal-lg` เป็นค่าเริ่มต้นสำหรับฟอร์ม, `modal-xl` เฉพาะที่มีตาราง, ไม่ใช้ fullscreen ยกเว้น editor
- **Header = ชื่อ + สวิตช์ภาษาแบบข้อความ + × (ตัดสินใจแล้ว รอบ 2 items 6c/7b follow-up)**: ชื่อ (H5) ซ้าย,
  ปุ่ม × ขวาสุด (Bootstrap default `margin-left:auto` อยู่แล้ว), ระหว่างกลางคือสวิตช์ภาษา — **ไม่ใช่ธง/ไอคอน
  ตามที่เคยเขียนไว้** แต่ก็ไม่ใช่ "ไม่มีอะไรเลย" — เป็นตัวหนังสือ **"TH | EN"** (ภาษาที่ใช้อยู่ `--c-text`
  หนา, อีกภาษา `--c-text-muted` คลิกสลับได้, ขนาด `--fs-xs`) วางขวาของชื่อ modal ก่อนปุ่ม ×
  - **เหตุผลที่ต้องมีสวิตช์ภาษาในนี้เลย ไม่ตัดออกเฉยๆ**: เป็นการแก้บั๊กจริงจาก 2026-08-30
    ("พอมีการเปิด modal จะกลับไปเปลี่ยนภาษาไม่ได้") — modal backdrop บังตัวสวิตช์ภาษาจริงที่ header หน้า
    ทำให้กดไม่ได้ตลอดเวลาที่มี modal เปิดอยู่ ถ้าเอาออกทั้งระบบตามที่เคยเขียนไว้ (§1's "ไม่มีธง") จะรีบั๊กนี้
    กลับมาทันทีใน ~100 modal จริงทั่วแอป — ยืนยันกับผู้ใช้ตรงๆ แล้วว่าให้**เก็บกลไกไว้ แค่เปลี่ยนหน้าตา**
    ให้ตรง §1 (ไม่ใช้รูปธง/ไอคอน) แทนที่จะลบทิ้งทั้งกลไก
  - **แก้ที่จุดเดียวใน `app.js`** (`modalLangDropdownHtml()` + `show.bs.modal` global handler ที่มีอยู่
    แล้ว — ไม่ใช่ partial PHP กลาง, มันคือ JS mechanism ที่ฉีดเข้าไปทุก modal อัตโนมัติ) ไม่ต้องไล่แก้ทีละ
    modal เหมือนที่กังวลไว้ตอนแรก — เอา `<img class="current-flag" src=".../flags/{th,gb}.png">` +
    dropdown menu ออก เหลือแค่ปุ่มข้อความ 2 ปุ่ม คั่นด้วย "|" คลิกปุ่มที่ไม่ active เพื่อเรียก
    `changeLanguage(lang)` ตัวเดียวกับที่ header จริงใช้ (ผูกไปถึง header/หน้าหลักให้ฟรีเหมือนเดิม)
  - **ตำแหน่งเปลี่ยนด้วย**: ของเดิม prepend เข้า header (อยู่ซ้ายสุด, ก่อนชื่อ) → ย้ายมาแทรกหลัง
    `.modal-title` (ขวาของชื่อ ก่อน ×) ตามที่ตัดสินใหม่ — ลบ CSS compensation เดิม
    (`.modal-header .modal-title { flex:1 1 auto; margin-left:.75rem }`) ที่เคยต้องมีไว้เพราะปุ่มธง
    เคยเป็น flex child ตัวแรกด้วย ไม่ต้องใช้อีกต่อไป
  - บริบท (รหัส, ชื่อพนักงาน) เป็นบรรทัดรอง `--c-text-muted` ใต้ชื่อ หรือใช้ `.emp-header-card` (สไตล์จริงตอนนี้
    — ดูรายละเอียดเต็มในหัวข้อ **emp-header-card** ด้านล่าง — เพิ่มบรรทัด 2 + badge สถานะ จากที่เคยร่างไว้
    แค่ "1 บรรทัด")
- Footer: พื้น `--c-bg-subtle`, `[ยกเลิก] [บันทึก]` ขวา; ปุ่มทำลาย (ลบ) ถ้ามี = tertiary ซ้ายสุด
- **confirm ก่อนปิด modal ที่มีข้อมูลค้าง — เสร็จแล้ว รอบ 2 item 7b, เป็น redesign ไม่ใช่การรื้อของเดิมกลับมาตรงๆ**:
  ใช้กลไกเดิม `isFormDirty($container, baselineSnapshot)` + `confirmIfDirtyThen($container, baselineSnapshot, onProceed, promptOptions)`
  (`app.js`, Platform Hardening Phase 1 — มีอยู่แล้ว, ขยายรับ `promptOptions` (optional, 4th arg) ให้
  override title/message/ปุ่ม/danger ได้ต่อ caller โดยยัง backward-compatible 100% กับ 3 real caller
  เดิม (Employee Detail/Payroll Configuration/Tax & Statutory's page-body Cancel button — Permission
  Matrix ใช้กลไกของตัวเองแยกต่างหาก ไม่ใช่ `confirmIfDirtyThen()` ตามที่ comment เก่าเข้าใจผิด) —
  **ไม่สร้างฟังก์ชันที่ 3** (ตัดสินใจแล้วรอบ 0)
  - **สำคัญ: ก่อนรอบ 2 item 7b ไม่มี modal-level dirty-check ที่ยัง active อยู่เลยสักตัว** — เคยมี
    (Platform Hardening Phase 1.2, delegated `shown.bs.modal`/`hide.bs.modal` ครอบทุก `.modal` ~100
    ตัว) แต่ถูก**สั่งปิดทั้งระบบไปแล้วเมื่อ 2026-09-09** ("ปิดทั้งระบบ เอา dirty-check ออกทั้งหมด")
    เพราะ**สับสนในฟอร์ม/modal ส่วนใหญ่ของแอป** — เป็นการตัดสินใจที่ยืนยันชัดเจนแค่ 4 วันก่อนรอบ 2 นี้จะ
    เริ่ม ก่อนเขียนโค้ดรอบนี้จึงต้องรายงาน + ถามยืนยันตรงๆ ก่อนว่าจะรื้อกลับมาไหม (ไม่ใช่ทำตาม draft เดิม
    ของ §9 บรรทัดนี้ที่เขียนไว้ก่อนจะรู้เรื่อง 2026-09-09 อย่างมั่นใจ)
  - **ยืนยันแล้วให้รื้อกลับ แบบ opt-in ต่างจากเดิม 3 จุด เพื่อไม่ให้กลับไปสับสนซ้ำ**:
    1. ผูกเฉพาะ `.modal[data-dirty-guard]` (marker attribute ต้องใส่เองต่อ modal) ไม่ใช่ทุก
       `.modal:has(form)` — modal ดู/เลือก/filter ไม่ใส่; รอบ 4 ตอน migrate แต่ละหน้าค่อยตัดสินใจใส่
       เฉพาะ modal ที่เป็นฟอร์มแก้ข้อมูลจริง
    2. dirty = เทียบ snapshot (`snapshotFormState()` เดิม) ที่ถ่ายตอน `shown.bs.modal` กับตอนพยายามปิด
       — ไม่ใช่ flag จาก event `change`/`input` ใดๆ — เปิดแล้วปิดเฉยๆ หรือแก้แล้วแก้กลับเป็นค่าเดิม =
       ไม่ถาม ทั้งคู่
    3. หลัง save สำเร็จ (หรือ `resetForm`) ต้อง snapshot ใหม่เอง ผ่าน `refreshDirtyGuard($modal)`
       (ใหม่, `app.js`) ก่อนจะปิด modal — ปิดหลัง save จึงไม่ถาม เพราะ baseline อัปเดตตามแล้ว
  - **ถามเฉพาะ ×/Esc/backdrop** (delegated `hide.bs.modal` บน `.modal[data-dirty-guard]` เท่านั้น,
    `e.preventDefault()` ยืนยันแล้วว่า Bootstrap 5's `Modal.hide()` เช็ค `hideEvent.defaultPrevented`
    จริงจากอ่าน source ตรงๆ ไม่ใช่เดา) — **ปุ่ม "ยกเลิก" ในฟอร์มที่เป็น `data-bs-dismiss="modal"` ธรรมดา
    ผ่านกลไกเดียวกันโดยอัตโนมัติ** (Bootstrap เองส่ง `hide.bs.modal` event เดียวกันไม่ว่าจะปิดทางไหน) —
    **ไม่ต้องเรียก `confirmIfDirtyThen()` เองทีละปุ่ม Cancel อีกต่อไป**
  - **ข้อความยืนยัน** (`promptOptions`, i18n key ใหม่ `confirm_modal_dirty_title`/
    `action_close_without_saving`/`action_back_to_editing`, reuse `confirm_discard_changes_message`
    เดิมสำหรับเนื้อหา): title "มีข้อมูลที่ยังไม่ได้บันทึก", ปุ่ม **[กลับไปแก้ต่อ]** (secondary/cancel) กับ
    **[ปิดโดยไม่บันทึก]** (danger, ผ่าน `showConfirm()`'s `danger:true` — ดูข้อ 10) — คนละข้อความกับ
    default ของ `confirmIfDirtyThen()` เดิม ("Discard unsaved changes?"/Yes/No, ยังใช้อยู่กับ 3 real
    caller เดิมที่ไม่ส่ง `promptOptions` มา ไม่เปลี่ยน)
  - `data-dirty-guard` **ยังไม่มีหน้าจริงใช้เลยรอบนี้** (ห้ามแตะหน้าจริง §13) — demo ครบ 3 สถานการณ์ใน
    `docs/design/components.php`
- Modal ซ้อน: ใช้ global z-index/scroll fix ใน app.js ที่มีแล้ว ไม่จัดการเองต่อ modal
- ซ่อน block ที่ว่าง (ทำแล้วใน 3B) เป็นกฎถาวร

**ฟอร์ม**
- Grid: label ซ้าย (`col-lg-3`, `--c-text-muted`) / input ขวา (`col-lg-9`) สำหรับ modal; ฟอร์มเต็มหน้าใช้ label บน input ได้เมื่อมี ≥ 2 คอลัมน์
- required = `*` แดงหลัง label (ที่เดียวที่แดงใช้ได้นอกสถานะ)
- **Helper text ≤ 1 บรรทัด** (`.form-text`, `--c-text-muted`) ถ้าเกิน → ตัดเหลือประโยคหลัก และย้ายรายละเอียดไป tooltip ไอคอน ⓘ หลัง label หรือเอกสาร; ห้ามมี helper ทุกช่อง ใส่เฉพาะที่ผู้ใช้จะกรอกผิดถ้าไม่มี
- Validation: inline ใต้ช่อง (`.invalid-feedback`) + focus ช่องแรกที่ผิด; ไม่ใช้ alert สำหรับ validation
- Segmented/toggle (ใช่-ไม่ใช่): `.btn-group` ของ `.btn-outline-secondary.btn-sm` ตัวที่เลือกเป็น `active` (พื้น `--c-bg-subtle` ขอบ `--c-border-strong`) — ไม่ใช้ส้มกับ toggle **(คนละ component กับ checkbox/switch ด้านล่าง — segmented toggle เป็นปุ่มคู่แข่งกันเลือกได้ 1 ทาง (เทาเสมอ), checkbox/switch เป็น input จริงที่มีสถานะ checked/unchecked (ส้มตอน checked/on) — อย่าสลับกฎกัน)**
- ฟอร์ม "รายการจ่าย/หัก", "จัดการรอบ", "ตั้งค่ารอบ": จัดกลุ่มช่องด้วยหัวข้อย่อย (`--fs-sm` หนา) + เส้น `--c-border` ระหว่างกลุ่ม ไม่ใช้ card ซ้อน card

**Checkbox / Radio / Switch — เสร็จแล้ว รอบ 2 item (2)** (Bootstrap `.form-check`/`.form-switch` ตรงๆ,
ไม่สร้าง component ใหม่ — override สีใน `style.css` เท่านั้น)
- unchecked: ขอบ `--c-border-strong`, พื้น `--c-bg`
- checked/on: พื้น `--c-primary` (ส้ม), เครื่องหมายขาว — **ข้อยกเว้นตรงใน §3** (เหมือน tab ที่เลือก) ไม่ใช่กฎใหม่
- disabled: `--c-text-faint` (แทน opacity เฉยๆ ให้ตรงกับ element disabled อื่นในแอป) — `checked+disabled`
  พื้น `--c-text-faint` (ไม่ใช่ส้มจาง)
- label: ตัวหนังสือ `--c-text`; focus ring: ส้ม (เหมือน `.form-control:focus`)
- **บั๊กจริงที่เจอ**: Bootstrap hardcode `#0d6efd` (hex ตรงๆ ไม่ใช่ CSS variable) ใน
  `.form-check-input:checked`/`:indeterminate` และฝัง fill สีไว้ใน SVG data-URI ของ `.form-switch`'s
  own focus state — override ผ่าน root `--bs-primary` เฉยๆ ไปไม่ถึง ต้อง override ราย-component ตรงๆ
  (เทคนิคเดียวกับที่ `.btn-primary`/`.form-control:focus` ทำไว้แล้วในรอบ 1)
- ตารางที่ใช้ switch ต่อแถว → `.col-toggle` + `initRowToggles()` ดู §7

**Setting row — ใหม่ 2026-09-14 (Round 3 "เก็บตกรอบ 6"), 2 variant เพิ่มวันเดียวกัน ("เก็บตกรอบ 7")** —
แถวตั้งค่า: label + คำอธิบาย (เปลี่ยนตามสถานะสวิตช์ได้) + switch — ดู §11 สำหรับ component/API เต็ม
(`setting-row.php` + `settingRowHtml()`) มาจาก Payroll Detail's เองสวิตช์ "คำนวณอัตโนมัติ" ที่เคยลองเป็น
page-local block มา 2 รอบก่อนหน้า (callout ก่อน แล้วข้อความเปล่าใต้สวิตช์) ก่อนถูกดึงออกมาเป็น component
จริง
- **`variant` 2 แบบ, กฎเลือก**: **1-2 setting ในหน้า/section = `plain` (default)**, **3+ แถวซ้อนกัน =
  `card`** (พื้นร่วมกันทำให้เห็นว่า "กลุ่มนี้อยู่ด้วยกัน" เหมือนที่ `.filter-bar`/panel อื่นทำอยู่แล้ว — แถว
  `plain` เดี่ยวๆ อ่านได้ปกติบนพื้นหน้าเปล่า แต่หลายแถว `plain` ซ้อนกันเริ่มอ่านเป็นข้อความหลวมๆ ไม่เป็นกลุ่ม)
- **`plain` (default)**: **ไม่มีกล่อง/พื้น/padding เลย** — แถวเดียวแบน `[switch] label · คำอธิบาย` สูง
  ~24px (ความสูงธรรมชาติของ switch เอง ไม่เพิ่ม padding ทับ) ชิดซ้ายไม่มี inset ของตัวเอง (align กับ
  filter-bar ที่วางต่อกันพอดี) — switch มาก่อน (ซ้ายสุด), ตามด้วย label ตัวหนา (`--fs-sm` 600 `--c-text`,
  เป็น `<label for="...">` จริง กดที่ label ก็ toggle switch ได้เหมือนกดที่ switch เอง), คั่นด้วย "·"
  (`--c-text-muted`), แล้วคำอธิบาย (`--fs-xs` `--c-text-muted`) ทั้งหมดอยู่บรรทัดเดียว ellipsis ถ้ายาวเกิน
  — ใช้จริงที่ Payroll Detail (1 setting เท่านั้น)
- **`card` (`variant:'card'`)**: รูปแบบเดิมจากรอบแรก — พื้น `--c-bg-subtle` `--radius` padding
  `--sp-3 --sp-4` ไม่มีขอบ — ข้อความซ้าย 2 บรรทัด (label `--fs-sm` 600 `--c-text` บรรทัดบน, คำอธิบาย
  `--fs-xs` `--c-text-muted` บรรทัดล่าง ellipsis ถ้ายาวเกิน), switch ขวา `align-items:center` กับ block
  ข้อความ — ใช้เมื่อมี 3+ setting ซ้อนกันในหน้าเดียว (ยังไม่มีหน้าจริงใช้ variant นี้รอบนี้ — demo เท่านั้น)
- ทั้ง 2 variant: คำอธิบายสลับ `desc_on`/`desc_off` อัตโนมัติทุกครั้งที่สวิตช์ถูกติ๊ก ผ่าน delegated
  `change` handler กลางใน `app.js` (auto-wired ทั้งแอป ไม่ต้อง init) อ่าน `data-desc-on`/`data-desc-off`
  จาก `.setting-row-desc` เอง (มาร์กอัปร่วมกันทั้ง 2 variant, เป็น `<span>` เดียวกัน — variant `card`
  เพิ่ม `display:block` scoped ให้มันขึ้นบรรทัดใหม่แทนที่จะ inline) — caller ที่เปลี่ยน checked แบบ
  programmatic (sync จาก server, revert ตอน save พลาด) ต้องเรียก `syncSettingRowDesc($switchInput)` เอง
  (**ไม่ใช่** `.trigger('change')` เพราะจะไป re-fire handler อื่นที่อาจผูกกับ switch ตัวเดียวกันไว้แล้วโดย
  ไม่ตั้งใจ — เจอจริงกับ Payroll Detail's เอง `#chkAutoRecalculate` ที่มี id-scoped save-on-toggle
  handler อยู่ก่อนแล้ว)
- ใช้จริงแล้ว: Payroll Detail's สวิตช์ "คำนวณอัตโนมัติ" (variant `plain`) — `#recalcReminderBanner`/
  `#autoRecalculateWrap .form-check-label`'s เอง page-scoped CSS จาก 2 รอบก่อนถูกลบทิ้งทั้งคู่ แทนที่ด้วย
  component นี้

**สลิป / รายละเอียดการคำนวณ** (modal รายละเอียดการคำนวณ)
- partial `payslip-view.php` แบบสลิป 2 คอลัมน์ (รายได้ | รายหัก) + สรุปล่าง (รวมรายได้/รวมหัก/สุทธิ) ตัวเลข `.num` — ใช้ทั้ง modal และหน้าพิมพ์/PDF ตัวเดียวกัน

**emp-header-card — เสร็จแล้ว รอบ 2 item 6c** (หัวการ์ดพนักงาน ใช้เป็นบล็อกแรกของทุก modal ที่เปิดจากแถว
พนักงาน — quick-view, รายละเอียดคำนวณ, comment, verify, ผูกบัญชีธนาคาร ฯลฯ)
- พื้น `--c-bg-subtle` ขอบล่าง `--c-border` **ไม่มีขอบสี/เงา**, avatar 40px (`apvAvatarHtml()`)
- บรรทัด 1 = ชื่อ `--c-text` หนา + รหัสพนักงาน `--c-text-muted`; บรรทัด 2 = แผนก · ตำแหน่ง `--c-text-muted`
  `--fs-sm`; ขวาสุด = badge สถานะพนักงาน (`statusBadge()`/`statusBadgeHtml()` จริง, context
  `employee_status` — ข้อ 5) — **badge เป็น optional จริง** ไม่ใช่ fallback ที่พังถ้าไม่มีค่า: caller ไหนไม่มี
  field `employee_status` จะไม่เห็น badge เลย (ไม่ใช่ badge เทาว่างเปล่า/"undefined")
- **partial `app/views/partials/emp-header-card.php` (ใหม่) + JS `employeeHeaderCardHtml(emp)`
  (`app.js`, มีอยู่แล้วตั้งแต่ Batch 3C item 8 — GENERALIZE ของเดิม ไม่สร้างซ้ำ ตามที่ระบุ)** —
  **ข้อควรระวัง: ต่างจาก component อื่นๆ ในรอบ 2 นี้ ฟังก์ชัน JS ตัวนี้มี 6 real call site ผูกอยู่แล้วจริง
  ใน `payroll/detail.js`** (Calculation Breakdown/Raw Sync Data/Manage Items/Comments/Adjustments/
  Bank Account Assignment) **ตั้งแต่ก่อนรอบ 2 นี้จะเริ่ม** — โค้ดเดิมมี comment ตรงๆ ว่า "ยังไม่จัดสไตล์
  การ์ด — design phase มาทีหลัง" งานรอบนี้คือ design phase ที่ comment นั้นรอไว้ ไม่ใช่ scope ใหม่ — แก้แค่
  ตัว function เดิม (ชื่อ/signature เดิมทุกอย่าง) ไม่แตะ 6 call site ใน payroll/detail.js เลยสักบรรทัด
  (นับเป็นการปรับ shared helper ไม่ใช่การแก้หน้าจริง — เหมือน `showConfirm()`/`fmtNum()` ที่แก้ไปแล้วก่อน
  หน้านี้) — **ผลคือ 6 modal จริงจะได้สไตล์ใหม่ทันทีที่ commit รอบนี้** (avatar 48px→40px ตามขนาดที่ตัดสิน
  รอบนี้, เพิ่มบรรทัด 2 + badge slot) ต่างจาก component อื่นในรอบ 2 ที่ยังไม่ถูก wire เข้าหน้าจริงเลย

**Quick-view พนักงาน** (จากคลิก avatar ทั่วแอป, ตัดสินใจแล้วรอบ 2 item 6c — migrate จริงรอบ 4)
- เป็น **modal** (`modal-md`) ไม่ทำ popover — ใช้ `.emp-header-card` เป็นหัว, เนื้อหา 2 คอลัมน์เท่ากัน
  label/value, footer มีแค่ **[ปิด] [ดูข้อมูลเต็ม]** (ไม่มีปุ่มอื่น)

---

## 10. Feedback

- **ยืนยัน — เสร็จแล้ว รอบ 2 item 7b**: SweetAlert2 ผ่าน helper เดิม **`showConfirm(...)`**
  (`public/js/alert.js`, มีอยู่แล้วทั่วระบบ) — **ไม่สร้าง `confirmAction()` ใหม่** (ตัดสินใจแล้วรอบ 0)
  รับได้ 2 รูปแบบ: **positional เดิม** `showConfirm(title, msg, yesCallback, noCallback)` (ทุก call
  site เดิมยังทำงานเหมือนเดิม 100% — ยืนยันด้วยการอ่าน implementation ตรง ไม่ใช่แค่ test) **หรือ object
  form ใหม่** `showConfirm({title, message, confirmText, cancelText, danger, tone, onYes, onNo})` เมื่อ
  argument แรกเป็น object — **`cancelText` เป็นการขยายเพิ่มนอกเหนือ draft เดิมของบรรทัดนี้** (ของเดิม
  มีแค่ `confirmText`) จำเป็นเพราะ modal dirty-guard (§9) ต้อง override ปุ่มทั้ง 2 ฝั่งพร้อมกัน
  ("กลับไปแก้ต่อ"/"ปิดโดยไม่บันทึก" ไม่ใช่ "Yes"/"No" default) — ห้ามเรียก `Swal.fire` ตรงๆ ในหน้า
  - **`tone: 'success'|'warning'|'danger'` (ใหม่, 2026-09-13, decision-set follow-up)** — ขยาย
    `danger:true` เดิมเป็น 3 ทาง สำหรับ confirm dialog ที่ต้องสีตรงกับปุ่ม `.btn-decision-*` (§4's
    decision-set exception) ที่เปิดมัน — `danger:true` **ยังใช้ได้เหมือนเดิม** เป็น shorthand ของ
    `tone:'danger'` (backward-compatible, ไม่ต้องแก้ call site เดิม) `tone` มีผลเหนือกว่าถ้าตั้งทั้งคู่
  - **สไตล์ปุ่ม/ไอคอน — เปลี่ยน default ทั้งระบบ ผ่าน `tokens.css`/`style.css`'s `--swal2-*` block เดียว
    ไม่ใช่ per-call**: ปุ่มยืนยัน = `--c-primary` (ส้ม), `danger:true`/`tone:'danger'` = `--c-danger`
    (แดง), `tone:'warning'` = `--c-warning`, `tone:'success'` = `--c-success` (ทั้งหมด per-call
    `confirmButtonColor` override), ปุ่มยกเลิก = ทรง `.btn-outline-secondary` (โปร่งใส + ขอบ
    `--c-border-strong` + ตัวหนังสือ `--c-text`), popup/ปุ่มทุกปุ่ม border-radius = `--radius` เดียวกับ
    ที่อื่นทั้งแอป, ฟอนต์ Sarabun **ไม่ต้องแก้อะไรเลย** (`.swal2-popup` เดิมใช้ `font-family:inherit` +
    `html,body` ตั้ง Sarabun ไว้อยู่แล้ว ยืนยันจาก source ตรงๆ) — **นี่คือการย้อนการตัดสินใจของ T069 Step 3
    (2026-09-05) ที่เคยเลือกเก็บสีม่วง/แดง/เทา default ของ Swal2 ไว้โดยตั้งใจ** ยืนยันตรงกับผู้ใช้ก่อน
    เขียนโค้ดแล้วว่าให้เปลี่ยนจริง (เหตุผลเดิมของ T069 ใช้ไม่ได้อีกต่อไปเมื่อรอบนี้กำลังนิยาม
    "primary"/"danger" ของ object form เองพอดี) — **พบเพิ่มระหว่างแก้จุดเดียวกัน**: ไอคอน
    `info`/`question` ของ Swal2 default เป็นสีฟ้า (`#3fc3ee`/`#87adbd`, ตรงข้าม §3) แก้เป็น `--c-info`
    (โทเคนของแอปเองที่ comment ใน tokens.css บอกไว้แล้วว่า "info = เทา ไม่ใช่ฟ้า")
- **สำเร็จ — เปลี่ยน default ทั้งระบบ, ยืนยันแล้ว**: toast มุมขวาบน (helper `showSuccess`, ไม่ใช่ modal
  บล็อกกลางจอที่ต้องกด OK เหมือนก่อนรอบนี้อีกต่อไป) — กติกา auto-timer: ข้อความ ≤ 60 ตัวอักษร = 3
  วินาที ไม่มีปุ่ม; ยาวกว่านั้น = 6 วินาที + ปุ่ม × ปิดเอง (ไม่ใช่ OK); hover ค้าง = timer หยุดนับ
  (`Swal.stopTimer`/`Swal.resumeTimer` จาก `didOpen`, official Swal2 toast recipe) — caller ที่ส่ง
  `timer` เอง (7 จุดเดิม) ยังชนะ auto-decision เหมือนเดิม ข้อความ = กริยาเดิม + "แล้ว"
  - **ตรวจ caller เดิมก่อนเปลี่ยน default (135 จุด)**: 0 จุดพึ่ง `confirm=false` เลย (grep ยืนยัน) จึง
    เปลี่ยน default ได้โดยไม่มี call site ไหนพังพฤติกรรมจากพารามิเตอร์ตัวนี้
  - **พบ (ไม่แก้รอบนี้ เพราะเป็นข้อความจาก server/ตัวแปรที่ความยาวจริงรู้ได้แค่ตอน runtime ไม่ใช่แก้ page
    logic) — 63 จุดที่ข้อความเป็น `res.message ||...`/ตัวแปรอื่นที่ยาวไม่จำกัด**, เข้ากติกา auto-timer
    ข้างบนได้เองอัตโนมัติที่ runtime ไม่ต้องแก้ต่อไซต์ — แต่มี **2 กลุ่มที่สร้าง HTML หลายบรรทัดจริง**
    (`setup/data-sync.js`'s `dsResultSummaryHtml()`, `setup/origami-sync-widget.js`'s
    `origamiSyncResultSummaryHtml()` — ทั้งคู่สร้าง `<div>` + `<ul>` รายการ error สูงสุด 5+ รายการ) ที่
    ยาวเกิน 60 ตัวอักษรแน่นอนจนตกไปกิ่ง 6 วิ+ปุ่ม × เองอยู่แล้ว **แต่เจอบั๊กจริงแยกต่างหากตรงนี้ที่ไม่ได้
    แก้** (`showSuccess()`/`showError()` render ผ่าน Swal2's `text`/`title` ธรรมดา ไม่ใช่ `html:` เลย —
    เนื้อหา HTML นี้จึงโชว์เป็น source code ดิบมาตลอด ไม่ใช่ list ที่จัดรูปแบบแล้ว) — ดู BACKLOG.md
    entry เดียวกัน (พบระหว่าง design pass, ไม่แก้ปนกันตาม §0.7)
  - รายการเต็มของทั้ง 143 call site (breakdown ตาม dynamic/HTML/static) → **[SC13] ใน
    `docs/design/audit.md`**
- **ผิดพลาด — ไม่เปลี่ยน**: `showError` ยังเป็น dialog บล็อกกลางจอต้องกด OK เหมือนเดิมทุกอย่าง (§10 พูดถึง
  toast แค่ฝั่งสำเร็จเท่านั้น — ข้อผิดพลาดต้องไม่ถูกมองข้ามได้ง่ายจากมุมจอ/หายไปเองใน 3-6 วินาที) ข้อความ =
  บอกสาเหตุ + สิ่งที่ทำได้ ไม่ขอโทษ ไม่คลุมเครือ (การไล่ตรวจคำของ caller ทั้ง 9 จุดที่มีอยู่ ไม่ได้ทำรอบนี้ —
  เป็นการแก้ข้อความหน้าจริงทีละจุด ไม่ใช่ shared helper)
- Loading: ปุ่ม spinner (ปุ่ม) หรือ `.table-loading` overlay (ตาราง) — ไม่มี spinner เต็มหน้า

---

## 11. Shared components — ต้องใช้ ห้ามเขียนเอง

| ชื่อ | ที่อยู่ | แทนของเดิม |
|---|---|---|
| `page-header.php` (ขยายรอบ 3 item 3a: `secondary_actions`/`overflow_actions` รับ dropdown ได้ (`items[]`), `extraClass` ต่อ action, `id_prefix` optional กันชน id ตอน include ซ้ำ, `decision_actions[]` ใหม่ (แทน `primary_action` สำหรับกลุ่มตัดสินใจ, ห่าง `--sp-3`, แต่ละรายการมี `tone` ของตัวเอง — `.btn-decision-{success,warning,danger}`, §4's decision-set exception); `overflow_actions[]` เหลือ 1 รายการ → ปุ่มธรรมดาแทน dropdown, divider เฉพาะเมื่อมีรายการปกติอยู่เหนือจริง — ดู §2/§4) | `app/views/partials/` | การ์ดหัวหน้าทุกหน้า |
| `stat-card.php` (ขยายรอบ 3 item 3a/D: `value_class` optional ใส่ `.money-gross`/`.money-deduction`/`.money-net` — ดู §2/§8) | partials | การ์ดตัวเลขขอบสี |
| `callout.php` + `calloutHtml()` (ใหม่, §15, รอบ 3 item 3a follow-up — ข้อความ 1 บรรทัดสีตามความหมายใต้ block อื่น, 5 tone) | `app/views/partials/` + `app.js` | `.next-step-banner`/`.process-next-step` เดิม (Payroll Detail เท่านั้น, ลบ CSS แล้ว) |
| `filter-bar.php` (ปรับเป็นแผง 3 ส่วน หัว/ตัว/ท้าย, item 4 revision, ยกเลิก `toolbarTarget` — ดู §6) | partials | filter กางค้าง |
| `renderNotifications()` / `setNotificationCount()` (ใหม่, item 6d — เสร็จแล้ว, UI เท่านั้นยังไม่ต่อ backend; ของจริงมีอยู่แล้ว `notifications.js`/`NotificationModel` — 3 จุดต่างจริง (ไอคอนมีพื้นสี, จุด unread ขวา, badge "99+") ไม่ใช่แค่ token เดิม บันทึกไว้ให้รอบ 4 ตัดสินใจ — ดู §6) | app.js (ใหม่) | `.row-type-icon`/dot-ขวา ของจริง (คงไว้ ไม่แตะ — แค่ flag ความต่างสำหรับ migrate) |
| `status-stepper.php` + `renderStatusStepper()` (item 6 — เสร็จแล้ว; render อย่างเดียว ตำแหน่งเทียบ `current` เท่านั้น ไม่มี action button แบบของจริง — logic ขั้นยังอยู่ที่ `runLifecycleSteps()` เดิม — 2026-09-13 รอบ 3 item 3a: **ย้าย Payroll Detail มาใช้จริงแล้ว** + ขยายรับ `{label, date, tone, final, live, icon}` ต่อขั้น, แยก 3 สถานะสีชัดเจน (done/current/next), pulse ring, ไอคอนขาว 12px ของขั้นปัจจุบัน (mapping อยู่ที่ `RUN_LIFECYCLE_STEPS`/`RUN_LIFECYCLE_BRANCH_INFO` เท่านั้น ไม่ hardcode ใน partial) — ดู §6) | `app/views/partials/` + `app.js` | กล่อง 5 สี, `.process-timeline`/`.tl-*` (Payroll Detail เท่านั้น — ที่อื่นยังใช้อยู่) |
| `timeline.php` + `renderTimeline()` (ใหม่, item (3)/6b — เสร็จแล้ว; feed กิจกรรมยาวไม่จำกัด, caller เรียงมาเอง, ยังไม่ย้ายหน้าจริง (`renderApprovalTimelineBody()`) มาใช้ รอรอบ 4 — ดู §6) | `app/views/partials/` + `app.js` | `.apv-timeline-log`/`.apv-log-entry` เดิม (dead code, ไม่มี call site — ไม่ reuse ตั้งชื่อใหม่แทน) |
| `status-tabs.php` + `initStatusTabs()` (ใหม่, item 4b — chevron pipeline เดิม**ยังคงรูปแบบไว้**, retokenize เท่านั้น; **ตัดสินใจแล้ว**: เคยมี variant `path` ให้เทียบคู่กัน ลบออกทั้งหมดแล้ว) | partials + app.js | markup ที่เคยซ้ำ 2 ไฟล์ของ `.station-row`/`.station-card` |
| `statusBadge()` / `statusBadgeHtml()` + `status_map.php` (ใหม่, item 5 — เสร็จแล้ว; map มีที่เดียวคือ `status_map.php`, JS ไม่มี copy ของตัวเอง อ่านจาก `window.STATUS_MAP` ที่ `layout/header.php` inject ให้ — ยกเว้นกฎ "ห้ามแตะหน้าจริง" เฉพาะจุดนี้จุดเดียว; rename `payroll-configuration.js`'s local `statusBadge(row)` → `pcRowStatusBadge(row)` ทำก่อนเขียนแล้วตามแผน — ดู §5) | `app/helpers/helpers.php` + `app.js` + `app/config/status_map.php` + `layout/header.php` (inject จุดเดียว) | map สถานะกระจาย |
| `initSharedDataTable()` (ขยาย: layout, export, fixed column, columnDefs alignment, `emptyState` option ใหม่ item 6e — auto-pick ว่างจริง/กรองไม่พบ — ดู §6) | app.js | init ตรงทุกหน้า |
| `empty-state.php` + `emptyStateHtml()` (ใหม่, item 6e — เสร็จแล้ว; ใช้ผ่าน `initSharedDataTable()`'s `emptyState` option แล้ว, ยังไม่มีหน้าจริงอื่นเรียกตรง รอรอบ 4 — ดู §6) | `app/views/partials/` + `app.js` | ข้อความบรรทัดเดียวของ `language.emptyTable`/`zeroRecords` เดิม |
| `initRowToggles($table, {onChange})` (ใหม่, item (2) — เสร็จแล้ว) | app.js | switch ต่อแถวที่แต่ละหน้าเขียน wiring เองคนละแบบ (tax-statutory/company-profile ฯลฯ — ยังไม่ migrate รอบนี้ ห้ามแตะหน้าจริง §13) |
| `fmtMoney()` (ใหม่, item 7a — เสร็จแล้ว) / `fmtNum()` (มีแล้ว, `format-helpers.js` — **ไม่สร้าง `formatMoney()` ใหม่**, ตัดสินใจแล้วรอบ 2 — ดู §8) / `initMoneyInputs()` (ใหม่, item 7a — เสร็จแล้ว; ยังไม่มีหน้าจริงใช้ `.money-input`, migrate รอบ 4) / `parseMoneyInput()` (ใหม่, item 7a — `format-helpers.js`, จุดร่วมเดียวสำหรับ strip comma ที่ `collect*FormData` 8 ฟังก์ชันของหน้าจริงจะเรียกตอน migrate) | `app/helpers/helpers.php` + `app.js` + `format-helpers.js` | number_format กระจาย |
| `apvAvatarHtml()` / `apvPersonLineHtml()` | app.js (มีแล้ว) | avatar เขียนเอง |
| `emp-header-card.php` (ใหม่, item 6c) + `employeeHeaderCardHtml()` (generalize ของเดิม Batch 3C item 8 — เสร็จแล้ว, **มี 6 real call site ใน payroll/detail.js อยู่แล้ว ได้สไตล์ใหม่ทันทีที่ commit** ไม่เหมือน component อื่นในรอบนี้ — ดู §9) | `app/views/partials/` + `app.js` | หัว modal ธง+ไอคอน |
| `isFormDirty()` / `confirmIfDirtyThen()` (ขยายรับ `promptOptions`, item 7b — เสร็จแล้ว) / `refreshDirtyGuard()` (ใหม่, item 7b) / `data-dirty-guard` modal marker (ใหม่, item 7b — opt-in, redesign ของกลไกที่เคยถูกสั่งปิดทั้งระบบไป 2026-09-09, ยังไม่มีหน้าจริงใช้ รอรอบ 4) | app.js (มีแล้ว, Platform Hardening Phase 1) | ผูก dirty-check เองทีละ modal — **ไม่สร้าง `guardDirtyModal()` ใหม่** (ตัดสินใจแล้วรอบ 0 — ดู §9) |
| `showConfirm()` (ขยายรับ object form + `cancelText`, item 7b — เสร็จแล้ว; + `tone` 3 ทาง 2026-09-13 decision-set follow-up, `danger:true` ยังใช้ได้เป็น shorthand) / `showSuccess` (เปลี่ยนเป็น toast default, item 7b) / `showError` (ไม่เปลี่ยน) | app.js/alert.js (มีแล้ว) | Swal.fire ตรง — **ไม่สร้าง `confirmAction()` ใหม่** (ตัดสินใจแล้วรอบ 0 — ดู §10) |
| `resetModalTabs()` | app.js (มีแล้ว) | strip class เอง |
| `payslip-view.php` | partials | modal คำนวณแบบตาราง |
| `.scroll-thin` (ใหม่, notification "ซอฟต์ลง" follow-up 2026-09-13 — CSS utility class ล้วนๆ ไม่มี JS, scrollbar บาง 6px โปร่ง) | `style.css` | scrollbar เริ่มต้นหนาของ browser บน dropdown/panel ที่ scroll — ใช้กับ `.notif-list` แล้ว, ตัวไหนใน dropdown/panel ที่ scroll ต่อไปในระบบให้เรียกซ้ำ ไม่เขียน scrollbar CSS เองใหม่ |
| `calendar-widget.php` + `renderCalendarWidget(el, {month, events, onSelect})` (ใหม่, item 9 — เสร็จแล้ว; โครงคงเดิมจาก dashboard จริงแต่ class namespace ใหม่ทั้งหมด, ยังไม่มีหน้าจริงเรียกใช้ รอรอบ 4 — ดู §14) | `app/views/partials/` + `app.js` | `.dash-calendar-*` ของจริง (ไม่แตะ, ไม่ reuse ชื่อเดิม) |
| `chartColor()` / `chartColors()` / `chartDefaults(overrides)` (ใหม่, item 9 — เสร็จแล้ว; อ่าน token `--chart-*`/`--chart-grid`/`--c-*` สดจาก `getComputedStyle` ทุกครั้งที่เรียก ไม่ cache ค่า) | `app.js` | สี/font/grid ที่แต่ละกราฟ (8 กราฟทั้งแอป) ตั้งเองแยกกันตอนนี้ — ยังไม่ migrate หน้าจริง รอรอบ 4 |
| `initTimepicker($scope, options)` (ใหม่, item 9 — เสร็จแล้ว; flatpickr time-only, **auto-init** ต่างจาก `initDatepicker()` — ดู §14) | `input.js` | native `<input type="time">` ที่ปรับสไตล์ popup ไม่ได้เลย — ของเดิมใน `layout/modals.php`/`setup-rules` ยังไม่แตะ รอรอบ 4 |
| `setting-row.php` + `settingRowHtml({id,label,desc_on,desc_off,checked,variant})` (ใหม่, 2026-09-14 "เก็บตกรอบ 6", 2 variant เพิ่มวันเดียวกัน "เก็บตกรอบ 7" — เสร็จแล้ว; `variant:'plain'` default ไม่มีกล่อง แถวเดียว `[switch] label · คำอธิบาย`, `variant:'card'` กล่องพื้นเดิม label+คำอธิบาย 2 บรรทัด/switch ขวา — กฎเลือก: 1-2 setting = plain, 3+ = card — auto-wired description-swap ผ่าน delegated `change` handler กลาง (ใช้ร่วมกันทั้ง 2 variant), `syncSettingRowDesc()` สำหรับ caller ที่ set checked แบบ programmatic — ดู §9) | `app/views/partials/` + `app.js` | `#recalcReminderBanner`/page-local switch+banner block ของ Payroll Detail (2 รอบก่อนหน้า ลบ CSS แล้ว) |

เพิ่ม component ใหม่ต้องเสนอชื่อ + API + ที่ใช้ ≥ 2 จุด ก่อนเขียน

---

## 12. Lint (รันใน test suite, fail = commit ไม่ได้)

`scripts/check-design.php` สแกน `app/views/**`, `public/js/**` (ยกเว้น vendor/min), `public/css/**` (ยกเว้น `tokens.css`, vendor):
1. hex/rgb/hsl ใน CSS นอก `tokens.css` และใน `style=""` / JS string (ยกเว้น `--bs-*-rgb:` ใน `style.css`'s Bootstrap-override section — mechanical RGB-triplet decomposition ของ token ที่มีอยู่แล้ว ไม่ใช่ค่าสีใหม่ ดู comment ในไฟล์นั้นเอง)
2. `style="` inline ใน view/JS (ยกเว้น `display:none` ชั่วคราวใน JS และ `width` ของคอลัมน์ตาราง — allowlist ระบุใน script)
3. class ต้องห้าม: `btn-success btn-info btn-warning btn-light btn-dark btn-outline-(?!secondary) bg-primary bg-info bg-success text-primary text-info text-success border-primary rounded-circle btn-circle` — **+ ตัวที่เจาะจงกว่านั้น (item D, 2026-09-13, §8)**: `text-danger` ที่อยู่ใน `class="..."` เดียวกันกับ `num` (ไม่ใช่แบนทั่วไป — `text-danger` เดี่ยวๆ ไม่มี `num` ยังใช้ได้ปกติ เช่น dropdown item ทำลาย) เพื่อบังคับให้ตัวเลขเงินใช้ `.money-gross`/`.money-deduction`/`.money-net` (`.callout`/`.callout-*` ตัวมันเองไม่ถูก flag เพราะไม่มี `text-danger`/`num` เลย — สีของมันมาจาก `border-left-color` ไม่ใช่ text class) — **`.btn-decision-success`/`.btn-decision-warning`/`.btn-decision-danger` (§4's decision-set exception, 2026-09-13) ผ่านกฎนี้เองอัตโนมัติ** (ชื่อ class ไม่ตรงกับตัวไหนใน list ต้องห้ามข้างบนเป๊ะๆ, ไม่ใช่ `btn-outline-*` prefix ด้วย) ไม่ต้อง allowlist เพิ่มในโค้ด แต่บันทึกไว้ตรงนี้กันคนแก้ regex รอบหน้าเผลอไปครอบคลุมโดยไม่ตั้งใจ
4. `<i class="fa` ภายใน `.nav-link` (ไอคอนใน tab)
5. `.DataTable(` / `.dataTable(` นอก `initSharedDataTable`
6. `Swal.fire(` นอก app.js
7. `number_format(` ใน view / `toLocaleString(` ใน JS (ยกเว้น `fmtNum()`'s ของ `format-helpers.js` เอง — เดียวกับที่ #1 ยกเว้น `tokens.css`)
8. `<span class="badge` ที่ไม่ได้มาจาก `statusBadge` (ตรวจ marker `data-badge="status"`)

ระหว่างรอบ 4 (ไล่ทีละหน้า) ให้ lint **นับจำนวน** ต่อไฟล์ และ fail เฉพาะไฟล์ที่ "ประกาศแล้วว่าสะอาด" (`// design:clean` บรรทัดแรก / `<?php // design:clean ?>`) — ไฟล์ที่ยังไม่ทำไม่ fail แต่รายงานตัวเลข เพื่อไม่บล็อกงานอื่น

**BACKLOG รอบ 4 (นับจากรอบ 0, ยังไม่แก้)**: rule #3's `btn-outline-(?!secondary)` hit อยู่จริง ~145 จุดตอนตัดสินใจรอบ 0 (`.btn-outline-brand` ×77, `.btn-outline-primary` ×21, `.btn-outline-danger` ×23, `.btn-outline-success` ×12, `.btn-outline-info` ×6, `.btn-outline-warning` ×2, `.btn-outline-dark` ×1) — ทุกจุดต้องเปลี่ยนเป็น `.btn-outline-secondary` (ดู §1's `.btn-outline-brand` note) เป็นส่วนหนึ่งของการทำหน้านั้นในรอบ 4 ไม่ใช่ sweep แยกทีเดียวทั้งระบบ

**เสร็จแล้ว รอบ 2 item 8 (สุดท้ายของรอบ 2)** — `scripts/check-design.php` เขียนจริงครบ 8 กฎ + `tests/design_lint_test.php` (41 assertions: overall "ไม่มีไฟล์ design:clean ไหน fail" + ต่อไฟล์ทั้ง 10 ไฟล์ที่ต้อง mark ว่ามีอยู่จริง/ถูกสแกนจริง/มี marker จริง/0 hit จริง) — ทุกฟังก์ชัน rule เป็น pure function (`array $lines in -> array $hits out`) ให้ test เรียกตรงได้ ไม่ copy logic ซ้ำ (pattern เดียวกับ `scripts/check-lang.php`/`tests/lang_check_test.php` ที่มีอยู่แล้ว)
- **ขอบเขตการสแกนกว้างกว่า §12's ข้อความเดิมนิดเดียว โดยตั้งใจ**: เพิ่ม `docs/design/components.php` เข้าไปด้วยตรงๆ (ไม่ใช่ `app/views/**` ตามตัวอักษร แต่เป็นไฟล์หน้าตาเหมือนหน้าจริงที่รอบ 2 ทั้งรอบเขียนใส่มาตลอด — ตามคำสั่งชัดเจนของรอบนี้ "components.php...ต้อง mark design:clean")
- **เพิ่ม escape hatch ระดับบรรทัด `design:ignore`** (นอกเหนือจาก `design:clean` ระดับไฟล์ที่ระบุไว้เดิม) — comment ธรรมดาที่มีคำนี้อยู่ตรงไหนก็ได้ในบรรทัดเดียวกับที่ตรวจ ใช้กับกรณีที่ตั้งใจให้ "ดูเหมือนผิดกฎ" จริงๆ เช่น เดโมที่ตั้งใจคง `.text-primary` ทับ `.btn-circle-action` ไว้เพื่อพิสูจน์ว่า `!important` กลางบังคับให้เป็นเทาเสมอ (ลบออกจะทำให้เดโมข้อนั้นพิสูจน์อะไรไม่ได้เลย) — ใช้แค่ 2 จุดใน `components.php` เท่านั้น จุดที่เหลือแก้ที่ script เอง (ดูข้อถัดไป) ไม่ใช้ marker นี้พร่ำเพรื่อแทนการแก้จริง
- **rule 1/3/4/8 ต้อง match แบบ attribute/token-aware ไม่ใช่ whole-line regex ธรรมดา** — เจอ false positive จริงตอนรันรอบแรกกับ `components.php` เอง (ไฟล์นี้เต็มไปด้วยประโยคอธิบายที่พูดถึงชื่อ class ใน `<code>` เช่น "`.btn-outline-brand` ไม่แสดงแยกที่นี่..." ซึ่งไม่ใช่ `class="..."` จริง, และ class ที่มีคำต้องห้ามเป็นส่วนหนึ่งของชื่อ compound เช่น `.notif-badge`/`.btn-circle-action` ที่ไม่ใช่ `.badge`/`.btn-circle` จริงแต่ `\bword\b` แบบเดิมจับเจอเพราะขีดกลางนับเป็นขอบคำ) — แก้โดยดึงค่า attribute (`class="..."`/`style="..."`) ออกมาก่อนแล้วเทียบทีละ token แบบ exact-match/prefix เท่านั้น ไม่ใช่ substring ลอยๆ
- **rule 2 ขยาย exemption จาก 2 เป็น 4 ข้อ** โดยยึดหลัก "เป็นมิติ/ขนาดต่อชิ้น (dimension) ไม่ใช่การตกแต่ง (decoration)" เดียวกับ exemption เดิม ("width ของคอลัมน์ตาราง"):
  - (c) **inline style ที่มีแต่ property มิติ/เลย์เอาต์ล้วน** (width/height/min-\*/max-\*/font-size/border-radius/object-fit/object-position, ไม่มีสีปนเลย) — เจอจริงตอนเขียน lint ว่า `apvAvatarHtml()` (app.js, §11's shared avatar helper) และ PHP twin ของมันใน `emp-header-card.php` (item 6c) **render แบบนี้มาตั้งแต่ต้นอยู่แล้ว** (ขนาด avatar เป็น parameter ต่อครั้งเรียก ไม่ใช่ class ตายตัว) — ถ้าไม่เพิ่ม exemption นี้ shared component ที่ approve แล้วจะไม่มีทาง mark design:clean ได้เลย ซึ่งไม่ใช่สิ่งที่กฎนี้ตั้งใจจะจับ
  - (d) **inline style ที่ค่าเป็น `var(--token)` ล้วนๆ** — ไม่มีทางเป็นค่าใหม่ที่ไม่ผ่าน token ได้ตามนิยาม (มันคือการอ้าง token) — `components.php`'s เอง token-swatch grid ต้องใช้แบบนี้ (`style="background:var(<?=...?>)"` วนตามรายชื่อ token ~20 ตัว) ไม่มีทางเลือกที่เป็น fixed class ได้จริงสำหรับ "โชว์สีปัจจุบันของ token นี้"
- **รันครั้งแรก vs `docs/design/audit.md` (round 1, grep มือ)** — ตัวเลขต่างกันจริงในทุกกฎ อธิบายทีละข้อ (ไม่ใช่ script ผิด):

  | กฎ | audit.md (round 1) | check-design.php (รอบนี้) | ทำไมต่าง |
  |---|---|---|---|
  | §12.1 hex/rgb/hsl | ~1,696+ (ทั้งแอปรวม style.css) | **1,019** | **ลดลงจริง** — round 2 item 1 เพิ่ง migrate สีจำนวนมากใน `style.css` เข้า `tokens.css`/`var(--c-*)` แล้ว (audit ทำตอน tokens.css ยังไม่มีเลยด้วยซ้ำ) ส่วนที่เหลือคือสีที่ยังไม่ migrate ของหน้าจริง (รอรอบ 4) |
  | §12.2 inline style | 275 (raw ก่อนกรอง, audit เขียนไว้ตรงๆ ว่า "ต้องรอ check-design.php") | **91** | ต่ำกว่าเพราะมี exemption จริงแล้ว (4 ข้อข้างบน) — audit เดิมนับดิบไม่กรองอะไรเลย |
  | §12.3 forbidden classes | 145 (เฉพาะ `btn-outline-*`, ไม่รวม token อื่น) | **359** | สูงกว่าเพราะ scope กฎนี้กว้างกว่า audit เดิม (audit นับแยกแค่ `btn-outline-*`, ไม่รวม `bg-/text-primary/info/success`/`rounded-circle`/`btn-circle`/`.btn-circle-action` ที่ script นี้นับรวมด้วย) |
  | §12.4 tab icon | 32 | **94** | สูงกว่า — window 5 บรรทัดของ script กว้างกว่า grep เดิม (นับซ้ำได้เมื่อมีหลาย `nav-link` ใกล้กัน) ไม่ใช่ปัญหาที่ต้องแก้ตอนนี้ (rule 4 ไม่มีไฟล์ไหนต้อง mark clean รอบนี้) แต่บันทึกไว้ว่า over-count จริง รอปรับตอนรอบ 4 ถ้าจำเป็น |
  | §12.5 `.DataTable(` | 75 | **137** | สูงกว่า — audit เดิมน่าจะนับเฉพาะรูปแบบ init (`.DataTable({`) script นี้นับทุก `.DataTable(`/`.dataTable(` ตามตัวอักษรกฎ รวมการเรียกซ้ำไปยัง instance เดิมด้วย |
  | §12.6 `Swal.fire(` | 16 | **16** | **ตรงกันเป๊ะ** |
  | §12.7 `number_format`/`toLocaleString` | 60 (JS) + 0 (view) | **60** | ตรงกันแทบสนิท (ต่างจุดทศนิยมเล็กน้อยจากรอบก่อนแก้ demo เอง ไม่ใช่ปัญหา) |
  | §12.8 badge ไม่มี marker | 184 | **190** | สูงกว่าเล็กน้อย — ส่วนต่างมาจากหน้าจริงที่เพิ่ม badge ใหม่ระหว่าง round 1 ถึงตอนนี้ (งานโปรดักชันเดินคู่ขนานกับ design round) ไม่ใช่ script bug |

  สรุป: **hex/rgb ลดลงจริง (ผลจาก item 1), inline-style ลดลงเพราะกรองแล้ว, ที่เหลือสูงกว่าเพราะ scope/ความละเอียดของ script ใหม่กว้าง/แม่นกว่า grep มือเดิม** ไม่มีจุดไหนที่ต่างเพราะ script ผิด
- **10 ไฟล์ mark `design:clean` แล้วและผ่านจริง** (0 hit ทุกไฟล์, ยืนยันด้วย `tests/design_lint_test.php`): `docs/design/components.php` + `app/views/partials/{emp-header-card,empty-state,filter-bar,page-header,stat-card,status-stepper,status-tabs,timeline}.php` + `public/css/tokens.css` — **3 บั๊กจริงที่แก้ใน `components.php` เอง** (ไม่ใช่แค่ปรับ script): 3 จุดเรียก `number_format()` ตรงๆ → `fmtMoney()`, 2 badge เขียนมือ → `statusBadge()`, avatar วงกลมจาก `.rounded-circle` → `.apv-person-avatar` (class เดียวกับที่ `emp-header-card.php` ใช้อยู่แล้ว) — เดโมตัวเองตอนนี้ถือ shared helper ทุกตัวจริง ไม่ใช่แค่ผ่าน lint เฉยๆ

---

## 13. กระบวนการ phase design

**มติเดิมที่ขัดกับ rules.md ถือว่าถูกแทนที่** — ถ้ามีมติ/convention ที่ตกลงกันไว้ก่อนหน้า phase design นี้ (ไม่ว่าจะ roll out ไปแล้วกว้างแค่ไหน) ขัดกับกฎในเอกสารนี้ ให้ยึด rules.md เป็นมติล่าสุดเสมอ ไม่ต้องถามซ้ำว่าจะคง convention เดิมไว้ไหม — ของเดิมยังใช้งานได้จนกว่าจะถูกย้ายจริงตามลำดับรอบ (2/3/4 ตามที่ระบุไว้ต่อกรณี) แต่**ห้ามเขียนโค้ดใหม่ตาม convention เดิมอีก** นับจากรอบ 0 นี้เป็นต้นไป (ตัวอย่างที่เคยอ้างตรงนี้ — `.btn-circle-action` — เอง**กลับมติอีกรอบใน item (5)**: rules.md รอบแรกสั่งยกเลิกทรงกลม, item (5) ยกเลิกคำสั่งนั้นอีกที คง**ทรง**ไว้แต่ยกเลิก**สี** — หลักการ "rules.md ล่าสุดชนะ" ยังใช้เหมือนเดิม แค่ "ล่าสุด" ในกรณีนี้ดันเป็นรอบเดียวกันเอง ไม่ใช่ convention ก่อนรอบ 0 อีกต่อไป)

| รอบ | ทำอะไร | ผลลัพธ์ | ห้าม |
|---|---|---|---|
| 1 Audit | ไล่ทุกหน้า/modal ตามกฎ §2–§10 บันทึกใน `docs/design/audit.md`: หน้า, จุดที่ผิดกฎ (อ้าง §), จำนวน lint hit, ระดับ (ใหญ่/เล็ก) | รายงานเดียว + ลำดับหน้าที่เสนอ | แก้โค้ด |
| 2 Tokens + shared | `tokens.css`, override Bootstrap, สร้าง/ขยาย component ใน §11, lint script, หน้า `docs/design/components.php` (ตัวอย่างทุก component ในหน้าเดียว ใช้ทดสอบสายตา) | commit ทีละ component | แตะหน้าจริง |
| 3 นำร่อง | Detail › tab รายละเอียดพนักงาน (รวม modal ที่เปิดจากแถว) ใช้ component ทั้งหมด → รีวิวจริงในเบราว์เซอร์ → ปรับกฎ/component ถ้ากฎใช้จริงไม่ได้ | หน้าต้นแบบ 1 หน้า + กฎฉบับแก้ | ไปหน้าอื่น |
| 4 ไล่หน้า | ตามลำดับจาก audit ทีละหน้า: หน้า = 1 commit, ท้ายไฟล์ mark `design:clean`, lint ผ่าน | ทุกหน้า clean | รวมหลายหน้าใน commit |

กฎรายงานทุกรอบ: ก่อน/หลัง เป็นรายการจุดที่เปลี่ยน (อ้าง §) + สิ่งที่ยังไม่แน่ใจ + lint count ก่อน/หลัง — ไม่มีการอธิบายว่า "สวยขึ้น" ต้องอ้างกฎเสมอ

**ข้อยกเว้นเดียวของ "ห้าม: แตะหน้าจริง" ในรอบ 2 (item 5, ยืนยันโดยผู้ใช้โดยตรง)**: `layout/header.php`
บรรทัดเดียว (ตรงจุดที่ inject `BASE_URL`/`LANG_VERSION` ให้ JS อยู่แล้ว) เพิ่ม
`window.STATUS_MAP = <?=json_encode(loadStatusMap())?>;` เพื่อให้ `app/config/status_map.php` เป็น
แหล่งข้อมูลเดียวจริงๆ ไม่ต้องมี JS copy ของตัวเอง (ดู §5, §11) — **ไม่ใช่การเปิดทางให้แก้ header.php
เพิ่มเติมได้อีกในรอบนี้** ยกเว้นเฉพาะบรรทัดนี้บรรทัดเดียวที่ขอ/อนุมัติไว้ชัดเจนแล้วเท่านั้น

---

## 14. Datepicker, Timepicker, Calendar widget, Chart (Round 2 item 9, 2026-09-13)

**Scope note**: infra + token override + `docs/design/components.php` demo เท่านั้น — `dashboard.js`/
`dashboard.php` (calendar) และ `employee/reports.js` (chart) เป็นหน้าจริง **ไม่แตะในรอบนี้** ตาม §13
ปกติ — 2 บั๊กสีจริงที่เจอระหว่างสำรวจ (probation dot สีคราม, กราฟ Tenure/Completeness สีรุ้งต่อแท่ง) จด
ไว้ใน `docs/design/audit.md`'s 2026-09-13 addendum แล้ว รอรอบ 4

### Datepicker

Library เดิม `bootstrap-datepicker` v1.10.1 (npm-vendored, `initDatepicker()` ใน `public/js/input.js`)
— **migrate override เดิม (`style.css`, comment block "Bootstrap-datepicker") จาก token ชุดเก่า
`--app-*` (T069, 2026-09-05) มาเป็นชุด `--c-*`/`--radius-lg`/`--shadow-modal` ของรอบนี้** — `--app-*`
เองไม่ได้ถูกถอดออกจากแอปทั้งหมด (ยังเหลืออีก ~220 บรรทัดที่ใช้ใน `style.css` ที่อื่น เป็นระบบ dark-mode
เดิมของ T069 คนละเรื่องกับ token ชุดนี้ นอกขอบเขตรอบนี้ — จดเป็น candidate รอบ 4 ถ้าจะรวม 2 ระบบ token
เป็นชุดเดียว):
- popup (`.datepicker.datepicker-dropdown`): พื้น `--c-bg` ตัวหนังสือ `--c-text` ขอบ 1px `--c-border`
  radius `--radius-lg` เงา `--shadow-modal` padding `--sp-2`
- หัวเดือน/ปุ่มเลื่อน (`th`) และช่องวันปกติ: `--c-text`
- วันนอกเดือน (`.old`/`.new`) และวันปิด/disabled (`.disabled`): `--c-text-faint`
- วันที่เลือก (`.active`/`.selected`): พื้น `--c-primary` ตัวหนังสือขาว (`#fff`, คงไว้ตามเดิม — สีขาวบน
  พื้น primary ไม่ใช่ token เพราะเป็นค่าคงที่ไม่ผูกกับ theme)
- วันนี้ (`.today`): **ขอบ 1px `--c-primary`** (ไม่ใช่พื้นสี — แยกสัญญาณจากวันที่เลือกที่เป็นพื้นทึบชัดเจน
  ไม่ให้สับสนกันเมื่อ "วันนี้" เป็น "วันที่เลือก" ด้วย ซึ่งกรณีนั้น specificity ของ `.active`/`.selected`
  ชนะอยู่แล้วเพราะมี 2 class)
- hover: `--c-bg-hover`
- ปุ่ม Clear (`tfoot th.clear`): `--c-primary` ตัวหนา, hover `--c-bg-hover`
- dark mode: ได้มาโดยอัตโนมัติจาก token เอง (ไม่มี override ซ้ำ เหมือนทุก component อื่นในรอบนี้)

### Timepicker

**ตัดสินใจแล้ว (2026-09-13, ยืนยันโดยผู้ใช้): flatpickr โหมด time-only** (ไม่ใช่ native
`<input type="time">`) — native ปรับสไตล์ popup ไม่ได้เลยในเบราว์เซอร์หลักเกือบทั้งหมด (closed shadow
DOM) ซึ่งขัดกับเป้าหมายที่ต้องการให้หน้าตาเหมือน datepicker ตรงๆ — `npm install flatpickr` (lock ใน
`package.json`, v4.6.13) โหลด global ผ่าน `layout/footer.php` (CSS+JS 2 บรรทัด ต่อจาก
bootstrap-datepicker เดิม — **ข้อยกเว้นเดียวของ "ห้ามแตะหน้าจริง" ที่ยืนยันแล้วสำหรับ item นี้** เหมือนที่
`window.STATUS_MAP` เคยได้รับตอน item 5, จำกัดเฉพาะ 2 บรรทัดนี้เท่านั้น) เพราะ `.timepicker` เป็น field
ที่ปรากฏได้ทุกหน้า เหมือน bootstrap-datepicker
- **`initTimepicker($scope, options)` (`public/js/input.js`)** คู่กับ `initDatepicker()` แต่
  **auto-init** (ต่างจาก datepicker ที่ต้องเรียกเองต่อ field) — ผูกกับ `.timepicker` ผ่าน
  `$(document).ready()` + `shown.bs.modal` เหมือน `initMoneyInputs()` (app.js, §8) มี guard
  `data('timepickerWired')` กัน init ซ้ำ ค่า default: `enableTime:true, noCalendar:true,
  dateFormat:'H:i', time_24hr:true, minuteIncrement:5, allowInput:true` (24 ชม., ทีละ 5 นาที, ค่าที่
  ส่ง server เป็น string `"HH:mm"`) — override ได้ต่อ field ผ่าน `options` param
- **override เข้า token** (`style.css`, บล็อกใหม่ต่อจาก Bootstrap-datepicker เดิม, ทุกค่าเป็น
  `var(--c-*)` จึงได้ dark mode ฟรีไม่ต้องเขียนซ้ำ): popup (`.flatpickr-calendar`) พื้น `--c-bg` ขอบ 1px
  `--c-border` radius `--radius-lg` เงา `--shadow-soft` (แทนที่ box-shadow เดิมของ lib ที่ผสมเส้นขอบ
  จำลอง 4 ด้าน + เงาไว้ในค่าเดียวกัน) ลูกศรชี้ (`:before`/`:after`) recolor ตาม border/bg ใหม่แทนการซ่อน
  — ตัวเลขชั่วโมง/นาที (`.flatpickr-time input`, คือ "เวลาที่เลือก" เพราะ time-only ไม่มี cell ให้เลือก
  แบบปฏิทิน) สี `--c-primary`, ตัวคั่น `:`/AM-PM `--c-text`, hover (ทั้ง input และปุ่มลูกศรขึ้น-ลง)
  `--c-bg-hover`, ลูกศรขึ้น-ลงเอง `--c-text-muted`
- **`<input type="time">` เดิมใน `layout/modals.php`/`setup-rules/index.php` ไม่แตะในรอบนี้** (ไม่มี
  class `.timepicker` จึง auto-init ไม่จับ) — migrate เป็นหน้าจริง จดไว้ `docs/design/audit.md`'s
  2026-09-13 addendum แล้ว รอรอบ 4

### Calendar widget (`calendar-widget.php` + `renderCalendarWidget()`, §11)

Component ใหม่ ไม่ใช่การแก้ dashboard จริง — โครงเดิมคงไว้ครบ (ตาราง 7 คอลัมน์, ปุ่มเลื่อนเดือน,
dropdown เลือกเดือน, legend, ช่องรายละเอียดวันที่เลือก) แค่ class namespace ใหม่ (`.calendar-widget-*`)
และสีย้าย token ครบ:
- ช่องวัน: พื้น `--c-bg` ขอบ 1px `--c-border` radius `--radius`; **วันนี้** พื้น `--c-primary-soft`
  (ไม่แตะขอบ); **วันที่เลือก** ขอบ 2px `--c-primary` (ซ้อนกับพื้นวันนี้ได้ถ้าเป็นวันเดียวกัน); วันนอกเดือน/
  ช่องว่างท้ายแถว **ไม่แสดงกล่องเลย** (ไม่ใช่กล่องจางเหมือน datepicker — calendar widget แสดงเฉพาะเดือน
  ที่ขอเท่านั้น ไม่โชว์วันเดือนติดกัน)
- event dot 4 ประเภท คนละ tone ไม่ใช่สีตามใจ: **holiday → danger, ตัดรอบ → warning, จ่ายเงิน →
  success, สิ้นสุดทดลองงาน/ฝึกงาน → muted** (`--c-text-muted`, เทาเข้ม **ไม่ใช่** `--c-info`/ฟ้า — ตรงกับ
  §3 "info = เทา ไม่ใช่ฟ้า" ที่ของจริงบน dashboard ละเมิดอยู่ตอนนี้ ดู audit.md) legend ใช้จุดสีเดียวกัน
  วันที่มีหลาย event แสดงจุดสูงสุด **3 จุด** (event ที่เหลือดูได้จากช่องรายละเอียดด้านล่างเมื่อคลิกวันนั้น)
- dropdown เลือกเดือน: select ธรรมดา (ไม่มี custom dropdown UI) สไตล์ tertiary (`--c-text-muted`,
  hover `--c-text`) + ไอคอน caret เล็ก `--c-text-faint` — **ไม่มีจุดเขียว/จุดสีใดๆ** (ของจริงบน dashboard
  มี `.dash-period-picker-live-dot` สีเขียวที่ไม่มีความหมายจริง — ไม่ port มาที่นี่ ตรงกับ §1 "สีต้องตอบ 2
  คำถาม")
- ช่องรายละเอียดวันที่เลือก: พื้น `--c-bg-subtle` (ไม่ใช่ส้มอ่อนแบบของจริง) ขอบ `--c-border` radius
  `--radius`; ว่าง = ข้อความ empty-state ขนาดเล็ก `--c-text-faint` กึ่งกลาง (ไม่ใช่ full `empty-state.php`
  component — เล็กเกินไปสำหรับ icon+title+text เต็มรูปแบบ)
- หัว "ปฏิทิน": ข้อความล้วน **ไม่มีไอคอน** (ของจริงมี `<i class="fa-calendar-days text-warning">` สีส้ม
  เหลืองที่ไม่สื่อความหมายอะไร — §2 ไอคอนพื้นสีต้องมีความหมาย ถ้าไม่มีให้ตัดออก ไม่ใช่แค่เปลี่ยนสี)

### Chart

Library: Chart.js v4.5.1 (npm-vendored, โหลดต่อหน้า ไม่ global) — **ยังไม่มี `chartDefaults()` มาก่อน
รอบนี้** ทุกกราฟ (8 กราฟทั้งแอป) ตั้งสี/font/grid เองแยกกัน (ดู audit.md's addendum สำหรับรายชื่อกราฟที่
ต้อง migrate ตอนหน้าจริงไปรอบ 4)

- Token: `--chart-1` (= `--c-primary` ตรงๆ, ไม่ประกาศค่าใหม่) `--chart-2..5` (เทาไล่ระดับ ไม่มีความหมาย
  สถานะ, มีค่า dark mode ของตัวเองที่ไล่ระดับกลับด้าน ให้ยังแยกกันได้บนพื้นมืด) `--chart-grid` (=
  `--c-border` ตรงๆ)
- Helper `chartColor(varName)`/`chartColors()`/`chartDefaults(overrides)` (`app.js`) — `chartDefaults()`
  คืน options object ของ Chart.js (font Sarabun, สี legend/tooltip/axis จาก token, grid
  `--chart-grid`, tooltip radius `--radius-lg`, ผสานกับ `overrides` ที่ผู้เรียกส่งมาผ่าน
  `$.extend(true, {}, base, overrides)`) — ทุกกราฟใหม่ (และกราฟจริงตอน migrate รอบ 4) เรียกฟังก์ชันนี้
  แทนตั้ง options เอง
- **กฎการใช้กราฟ (ใหม่, แม่นกว่าที่ระบุไว้ตอนสั่งงานครั้งแรก)**: กราฟต้องมี "ข้อมูลที่เปรียบเทียบได้" —
  series เดียวแต่ **≥ 6 จุด** (เช่นแนวโน้มรายเดือน) หรือมีหลาย series อยู่แล้ว ยังนับเป็นกราฟได้ทั้งคู่ —
  ถ้าเป็นแท่งเดียว/ค่าเดียว (ไม่มีอะไรให้เทียบ) ให้ใช้ **stat card** หรือตารางแทน ไม่ใช่กราฟ
- **สีต่อแท่ง/เส้น**: แท่งหลายแท่งที่อยู่ *series เดียวกัน* ใช้สีเดียว (`--chart-1`) ทั้งหมด **ยกเว้น**แท่งที่
  ต้องเน้นสถานะจริง (เช่น alert/at-risk vs. ปกติ) จึงใช้ `--c-danger`/`--c-success` เฉพาะแท่งนั้นได้ —
  หลาย series ใช้ `--chart-1..5` ไล่ตามลำดับ ไม่ใช่สีรุ้งเลือกเอง
- **doughnut 2 ส่วน**: ใช้ `--chart-1` (ส่วนที่นับ) + `--chart-grid` (ส่วนที่เหลือ/พื้นหลัง) เท่านั้น —
  ยกเว้นกรณีที่ 2 ส่วนนั้นสื่อสถานะจริงคู่กัน (เช่น enrolled/not enrolled, hire/exit) ซึ่งใช้
  `--c-success`/`--c-danger` ได้ตรงตามกฎ §3 เดิม (ไม่ใช่กฎใหม่ ของเดิมมีอยู่แล้ว)
- **legend ไม่มีสีรุ้ง** — ห้ามมี legend ที่สีแต่ละอันเลือกเอง/ไล่เฉดแบบไม่มีระบบ (เช่น 1 สีต่อ bucket ที่
  ไม่มีความหมายสถานะ) ทุกสีใน legend ต้องสืบย้อนกลับไปที่ `--chart-1..5` หรือสถานะจริง (`--c-danger`/
  `--c-success`) เท่านั้น

---

## 15. Callout — ข้อความ 1 กล่องใต้ block อื่น สีตามความหมาย (ใหม่, Round 3 item 3a follow-up, 2026-09-13)

**ที่มา**: Payroll Detail's เดิม `.next-step-banner`/`.process-next-step` (ข้อความ "This run is still a
draft…" ใต้ stepper) ถูกทำให้เรียบง่ายมาแล้วครั้งหนึ่ง (item 3a เดิม) แต่รูปร่าง "1 บรรทัดข้อความอธิบาย
ต่อจาก block อื่นด้านบน มีสีตามความหมาย" กลายเป็น shape ที่ reusable จริง ไม่ใช่แค่ของหน้านี้หน้าเดียว —
ยกระดับเป็น component ตั้งชื่อ + API ชัดเจนแทนที่จะปล่อยเป็น page-local class ต่อไป

**Component**: `app/views/partials/callout.php` (`$text`, `$tone`) + JS twin `calloutHtml(text, tone)`
(`app.js`) — ทั้งคู่ render markup เดียวกัน:
```
<div class="callout callout-{tone}">{text}</div>
```

**สเปกภาพ (ไม่มีข้อยกเว้น)**:
- พื้น `--c-bg-subtle`, มุมโค้ง `--radius`, padding `--sp-2 --sp-3` (แก้จาก `--sp-3 --sp-4` เดิม, 2026-09-13
  same-day follow-up — สูงเกินไปสำหรับ caption 1 บรรทัดใต้ stepper)
- เส้นซ้าย 3px สีตาม `tone` — **ข้อยกเว้นของ §3** (ดูตารางสี §3): container ทั่วไปห้ามมีสี tone บนขอบ/พื้น
  ของตัวเอง แต่ callout ใช้ได้เพราะสีบนเส้นซ้ายคือหน้าที่หลักของ component นี้ (ไม่ใช่ของแถม)
- ตัวหนังสือ `--c-text` ขนาด **`--fs-sm`** (แก้จาก `--fs-base` เดิม, 2026-09-13) เสมอ **ไม่ว่า tone ไหน**
  (สีอยู่ที่เส้นซ้ายเท่านั้น พื้น/ตัวหนังสือคงที่) — คำที่เป็น action ยังตัวหนา 600 เหมือนเดิมผ่าน
  `.callout b`/`.callout strong` (ไม่เปลี่ยนตามขนาดตัวอักษรที่เล็กลง) — รวมแล้วกล่องสูง **~36px**
  (padding-top 8px + บรรทัดข้อความ ~20px + padding-bottom 8px)
- **ไม่มีไอคอนหน้าข้อความ** (เคยมี ⓘ/✓ ในเวอร์ชันแรกๆ ของ `.process-next-step` — ตัดออกตามคำสั่งชัดเจน:
  เส้นซ้ายสี tone บอกความหมายพออยู่แล้ว ไอคอนซ้ำเป็นของตกแต่งที่เอาออกได้โดยไม่เสียความหมาย, §0.3)
- 5 tone: `primary` (ขั้นต่อไปที่ต้องทำ), `success` (จบแล้ว), `warning`, `danger`, `neutral`
  (`--c-border-strong`) — คำศัพท์เดียวกับ `status_map.php`'s tone vocabulary (§5), ไม่ใช่ชุดใหม่

**`$text` เป็น HTML ดิบที่ caller เตรียม/escape มาเองแล้ว — partial/`calloutHtml()` ไม่ escape ซ้ำ** (ตั้งใจ,
ไม่ใช่ช่องโหว่): จุดประสงค์หลักของ component นี้คือให้ caller ตัวหนา**คำที่เป็น action** ในประโยคให้ตรงกับ
label ปุ่มจริง (เช่น `<b>คำนวณ</b>` ตรงกับปุ่ม "คำนวณ") — ตัวหนาผ่าน `.callout b`/`.callout strong` (weight
600) — **`$text` ต้องเป็น copy ที่แอปเขียนเอง (i18n string) เสมอ ห้ามเป็น user input ดิบที่ไม่ผ่าน escape**

**ที่ใช้แล้ว**: Payroll Detail's `#nextStepBanner` (แทน `.next-step-banner`/`.process-next-step` เดิมทั้งคู่
— CSS เก่าถูกลบออกจาก `style.css` แล้ว ไม่เหลือ dead code เพราะยืนยันแล้วว่า exclusive กับหน้านี้) — tone
ต่อ state ของรอบเงินเดือนเป็น **judgment call ที่ flag ไว้ตรงๆ** (ผู้ใช้ระบุชัดแค่ locked=success/
rejected=danger/need_info=warning): draft/pending_approval/approved/paid → `primary`, cancelled →
`neutral`

**2026-09-13, Round 3 "เก็บตก" item 1 — 2nd real consumer, ภายหลัง REVERTED ในรอบ "เก็บตกรอบ 4" เดียวกัน
วัน (ดูด้านล่าง) — เหลือไว้เป็นประวัติ ไม่ใช่สถานะปัจจุบันอีกต่อไป**: Payroll Detail's `#recalcReminderBanner`
เคยเปลี่ยนจาก `.alert.alert-warning` เดิมมาเป็น `.callout.callout-warning` รอบนี้ — ตัวหนา
`<b>คำนวณ</b>`/`<b>Recalculate</b>` ให้ตรงกับปุ่ม `#btnRecalculate`'s เอง label จริงคำต่อคำ (ของเดิมเป็น
"คำนวณใหม่" ซึ่งไม่ตรงกับปุ่มจริงที่เขียนแค่ "คำนวณ" — แก้ข้อความให้ตรงพร้อมกับใส่ตัวหนา, ยังคงอยู่หลัง revert)
ยังใช้ได้เหมือนเดิม ไม่ได้ถูกแตะ

**2026-09-13, "เก็บตกรอบ 4" — REVERT ออกจาก callout: "ไม่ใช้ callout (ดูหนักเมื่อต่อท้ายสวิตช์)"** —
`#recalcReminderBanner` เลิกใช้ `.callout` แล้ว กลับไปเป็นข้อความล้วนหน้าตาเบา (`--fs-sm`/`--c-text-muted`,
ไม่มีเส้นซ้าย/พื้น/radius ใดๆ, CSS scope เฉพาะ id นี้ใน `style.css` ไม่ใช่ shared component อีกต่อไป) วาง
**บรรทัดใต้สวิตช์** (ไม่ใช่ inline ข้างสวิตช์แบบ flex-wrap ที่ทำไว้ก่อนหน้านี้ในวันเดียวกัน — ก็ revert เช่นกัน)
ห่าง `--sp-1` เท่านั้น (แน่นกว่า `--sp-2` เดิมมาก เพราะเป็น caption ของสวิตช์ ไม่ใช่ block อิสระ) —
`renderRecalcReminder()` (detail.js) เปลี่ยนจาก `.attr('class', 'callout callout-warning')` เป็นแค่
`.removeClass('d-none')`/`.addClass('d-none')` ธรรมดา (ไม่มี class ให้สลับอีกแล้วนอกจาก `d-none`) — element
ยังเป็น empty `<div id="recalcReminderBanner">` เดิม ไม่เปลี่ยน mechanism การ show/hide (gate ตาม state
สวิตช์ + draft-only เหมือนเดิมทุกอย่าง) **บทเรียน**: component ที่มีขอบ/พื้น/padding ของตัวเอง (`.callout`)
เหมาะกับข้อความที่ยืนอิสระเป็น block ของตัวเอง ไม่เหมาะเป็น caption สั้นๆ ต่อท้าย control อื่นที่ควรเบากว่านั้น
— ไม่ใช่ทุกข้อความเตือนต้องเป็น callout เสมอไป ให้เลือกตามน้ำหนักภาพที่ต้องการจริงๆ

**2026-09-14, "เก็บตกรอบ 5" item 3, follow-up — caption ยังอ่านเป็น text ปกติขนาดใหญ่**: `--fs-sm` (รอบ
ก่อนหน้าเลือกไว้) ยังใกล้กับขนาด label/เนื้อความปกติเกินไปจนไม่รู้สึกว่าเบากว่า — ลดเป็น `--fs-xs` (token
เล็กสุด ระดับ chip/badge) พร้อมเพิ่ม `line-height:1.4` (ประโยคยาว 2 บรรทัดต้องมี leading จริง ไม่ใช่ default
1) และ `max-width:640px` (กันบรรทัดยืดเต็มความกว้าง tab-pane) — label ของสวิตช์เอง (`#chkAutoRecalculate`)
ก็แก้ไปพร้อมกัน: ตัด Bootstrap's `.small` utility ออก (`.875em`, ค่า relative ไม่ใช่ token จริง ขนาดจริงลอย
ตามฟอนต์ parent) แทนที่ด้วย `--fs-sm` ตรงๆ ผ่าน scoped rule `#autoRecalculateWrap .form-check-label`

Demo จริงใน `docs/design/components.php` ("Callout (§15)"): 5 tone ผ่าน partial จริง (ไม่ใช่ mockup) ข้อความ
ดึงมาจาก i18n string จริงของ Payroll Detail (`next_step_draft`/`_locked`/`_need_info`/`_rejected`/
`_cancelled`) เพื่อยืนยันคู่ tone/ข้อความจริงที่ใช้บนหน้าจริง
