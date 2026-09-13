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

- **Page header = partial เดียว** `app/views/partials/page-header.php` รับ `title`, `breadcrumb[]`, `secondary_actions[]` (ไม่เกิน 2, label + id/href + icon optional, render `.btn-outline-secondary`, วางซ้ายของ `primary_action` เสมอ — ตัดสินใจแล้วรอบ 2 item 4), `primary_action` (label + id/href + icon optional), `description` — **ไม่มี card ครอบ ไม่มีไอคอนหน้า ไม่มีพื้นหลังสี** (ของเดิม "การ์ดหัวหน้า + ไอคอน" ทุกหน้าให้แทนด้วย partial นี้)
- **Action วางที่ไหน (ตัดสินใจแล้วรอบ 2 item 4, กฎบังคับทั้งระบบ)**:
  - **Page header** (`primary_action`/`secondary_actions`) = action ระดับ**หน้า** เท่านั้น — ไม่ขึ้นกับว่ามีแถวไหนถูกเลือกอยู่ไหม เช่น "สร้าง/เพิ่ม", "ดึงข้อมูล" (ซิงค์จาก Origami, นำเข้า Excel), "ดูประวัติ"
  - **Toolbar ของตาราง ฝั่งซ้าย หลัง length** = action ที่ทำกับ**แถวที่เลือกไว้** (bulk) เท่านั้น เช่น "ซิงค์ที่เลือก", "ลบที่เลือก" — ไม่ใช่ page header (เพราะพิมพ์ผิดที่ผู้ใช้ทั่วไปจะกดตอนไม่ได้เลือกอะไรเลย ปุ่มควรโผล่/ใช้งานได้เฉพาะตอนมี selection)
  - **Toolbar ของตาราง ฝั่งขวา** = **เฉพาะ**ค้นหา + ส่งออกเท่านั้น (§7) ห้ามใส่ action อื่นแทรก
- Breadcrumb กับ H1 ห้ามพูดซ้ำกัน — H1 คือชื่อหน้า breadcrumb คือทาง
- **Stat card** = partial `stat-card.php` render ด้วย **class ใหม่ `.stat`** (ไม่ใช่ `.stat-card`) — ตัดสินใจแล้วว่า **ทุบสีของ `.stat-card` เดิมทิ้งจริง** (ของเดิมมี 7 tone สี ขอบซ้ายสี ใช้อยู่ 5 ไฟล์ ณ ตอนตัดสินใจนี้ — ตรงข้ามกับกฎนี้โดยสิ้นเชิง ไม่ใช่ต่อยอด) พื้นขาว ขอบ `--c-border` **ไม่มีขอบซ้ายสี ไม่มีพื้นสี** ตัวเลข `.num` ขนาด `--fs-xl` ตัวหนา — ถ้าค่าเป็นสถานะที่ต้องตัดสินใจ (เช่น "รออนุมัติ 3") ใช้ badge จาก `status_map.php` (ข้อ 5) ใน slot ล่างเท่านั้น ไม่ใช่เปลี่ยนสีทั้งการ์ด — **field `badge` ของ `$stat` คือ `{enum, context}` เท่านั้น (ข้อ 5, ไม่ใช่ `{label, tone}` ดิบอีกต่อไป)**: partial เรียก `statusBadge($enum, $context)` เองข้างใน ไม่มีทางส่ง label/สีที่ไม่ผ่าน `status_map.php` เข้ามาได้อีกแล้ว
  - **แก้ไข (รอบ 2 follow-up): อนุญาตไอคอน (optional) 1 ตัว/การ์ด** (เดิมห้ามไอคอนเลย) — ยังคง
    **ห้ามพื้นสี/ขอบสีบนตัวการ์ด**เหมือนเดิม การอนุญาตไอคอนไม่ใช่การเปิดทางกลับไปหา `.stat-card` เดิม —
    **ตัดสินใจแล้ว (แก้กลับรอบที่ 2): ใช้ไอคอนมุมขวาบน** (เคยมี 2 variant ให้เทียบกันใน components.php —
    ตัดสินใจครั้งแรกเลือกไอคอนวงกลมซ้าย 40px, **แก้กลับมาเป็นมุมขวาบนในรอบนี้แทน** — วงกลมซ้ายถูกลบออกจาก
    partial/CSS/components.php ทั้งหมดแล้วเป็นครั้งที่สอง ไม่เหลือ dead code ทั้งสองรอบ): ไอคอนมุมขวาบน
    ขนาด 20px สี `--c-text-faint` ไม่มีวงกลม/พื้นของตัวเอง, label เล็กสีเทาซ้ายบน, ตัวเลข `--fs-xl` ตัวหนา
    ด้านล่าง — **ถ้าไม่ส่งไอคอนมา ไม่เว้นที่ไว้** (ตัดสินใจเดิม ยังคงไว้ไม่เปลี่ยนข้ามทั้ง 2 รอบ:
    "เลือกไม่เว้น"/"ไม่มีไอคอน = ไม่เว้นที่") — label เป็นสมาชิกเดียวในแถว flex (label+ไอคอน,
    `justify-content:space-between`) จึงชิดซ้ายเองโดยธรรมชาติเมื่อไม่มีไอคอน ไม่ต้องเขียนโค้ดพิเศษกันที่ว่าง
  - **ทุกการ์ดในแถวสูงเท่ากันเสมอ** — caller ครอบด้วย Bootstrap `.row` ธรรมดา (ยืด column เท่ากันเป็น default อยู่แล้ว ไม่ต้องเพิ่ม CSS) `.stat` เอง `height:100%` + เป็น flex column — **slot ล่างคงที่สำหรับ sub/badge/link เสมอ** (มี `min-height` แม้ไม่มีเนื้อหาอะไรเลย ก็ยังเว้นพื้นที่เท่ากับการ์ดที่มีครบทั้ง 3 อย่าง) และ `margin-top:auto` ดันลงชิดขอบล่างเสมอ ไม่ว่า header/ไอคอนด้านบนจะสูงแค่ไหน
  - **Migration**: รอบ 2 สร้าง `.stat`/`stat-card.php` ใหม่เท่านั้น **ไม่แตะ 5 ไฟล์ที่ใช้ `.stat-card` เดิม**; รอบ 4 ย้ายทีละหน้า (หน้าไหนมี stat card ก็ย้ายเป็นส่วนหนึ่งของการทำหน้านั้นให้ clean ไม่ใช่ commit แยก) เมื่อย้ายครบ 5 ไฟล์แล้วให้ลบ CSS ของ `.stat-card`/`.stat-card-*` (`style.css`) ทิ้งเป็นขั้นตอนสุดท้าย — ห้ามลบ CSS เดิมก่อนไฟล์ล่าสุดที่ใช้มันย้ายเสร็จ
- Dashboard: ไม่มี welcome card, ไม่มีกราฟที่มีข้อมูลแท่งเดียว — เนื้อหาต้องเป็น "งานที่ต้องทำ" ก่อน (รออนุมัติ/อนุมัติแล้วรอทำต่อ/ค้างนาน) ตามด้วยตัวเลขสรุป
- ปุ่ม `?` ลอย: เอาออก — ความช่วยเหลือให้อยู่ใน helper text หรือลิงก์ "วิธีใช้" ใน page header เท่านั้น
- Max content width ไม่จำกัด (ตารางกว้าง) แต่ **padding ซ้าย/ขวาของ content เท่ากันทุกหน้า = `--sp-5`** และตาราง/การ์ด **เต็มความกว้าง content เสมอ** ไม่มี padding ซ่อนในตาราง (ปัญหา "ตารางไม่เต็มขอบ")

---

## 3. สี — ใครใช้ได้บ้าง

| สี | ใช้ได้กับ | ห้ามใช้กับ |
|---|---|---|
| ส้ม `--c-primary` | ปุ่มหลัก (1/หน้า, 1/modal), tab ที่เลือก (เส้นใต้), step ปัจจุบันใน stepper, focus ring, **checkbox/radio/switch ที่ถูกเลือก/เปิด (§9 — ข้อยกเว้นเดียวกับ tab ที่เลือก, เพิ่มรอบ 2 item (2))**, **รายการที่ยังไม่อ่าน/ต้องสนใจ ใช้ `--c-primary-soft` เป็นพื้น + `--c-primary` ที่ไอคอนได้ (ความหมายเดียวกับ step ปัจจุบัน — "นี่คือสิ่งที่ต้องดู/ตัดสินใจตอนนี้" ไม่ใช่ตกแต่ง — เพิ่ม 2026-09-13, notification item ข้อ 6d)** | ไอคอน (ปกติ — ยกเว้นข้อบน), ตัวเลข, badge, ขอบการ์ด, หัวตาราง, ลิงก์ในเนื้อหา |
| เทา (neutral) | ทุกอย่างที่เหลือ: ปุ่มรอง, ไอคอน, badge ข้อมูล, เส้น, พื้น | — |
| แดง | สถานะ "ผิด/ถูกปฏิเสธ/เกินกำหนด/ต้องแก้", ปุ่มยืนยันลบใน confirm dialog เท่านั้น | ปุ่ม PDF, ปุ่มลบในแถว (ใช้เทา, ไปแดงตอน confirm), ตัวเลขติดลบ (ใช้เครื่องหมายลบ + `--c-text`) |
| เหลือง | สถานะ "รอ/ต้องตรวจ/ยังไม่ครบ" | แจ้งเตือนทั่วไป, helper text |
| เขียว | สถานะ "อนุมัติแล้ว/จ่ายแล้ว/พร้อม" | ปุ่ม Excel, ปุ่มบันทึก, ไอคอน sync, ตัวเลขบวก |
| ฟ้า | **ไม่ใช้เลย** | ปุ่ม sync, badge info, ลิงก์ (ลิงก์ = `--c-text` + underline on hover) |

ทดสอบง่ายๆ: ถ้าเปลี่ยนหน้าให้เป็นขาวดำ ผู้ใช้ยังรู้ไหมว่าต้องกดอะไร ถ้ารู้ = ถูก สีที่เหลือคือของแถม

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
- เสร็จแล้ว: วงกลมเทา (fill `--c-border-strong`) + ✓ (ไอคอน `--c-text-muted`), ตัวหนังสือ `--c-text-muted`;
  ปัจจุบัน: วงกลมตัน `--c-primary` (ไม่มีไอคอน), ตัวหนังสือ `--c-text` หนา; ถัดไป: วงกลมขอบ
  `--c-border-strong` ว่าง (ไม่มี fill/ไอคอน)
- **ไม่มีสีพาสเทล 5 สี ไม่มีกล่องต่อขั้น** — เส้นเชื่อมสีเดียว `--c-border` เสมอ ไม่เปลี่ยนสีตามขั้นที่เสร็จ/ไม่เสร็จ
- Demo จริงใน `docs/design/components.php` ("Stepper (ข้อ 6)"): 5 ขั้นจริงของรอบเงินเดือน (สร้างรายการ →
  ส่งอนุมัติ → อนุมัติ → จ่ายเงิน → ปิดรอบ) ที่ 3 สถานะจริง (`draft`/`approved`/`paid`) — `current` คำนวณตาม
  กฎเดียวกับ `runLifecycleSteps()` จริง (`currentIndex = reachedIdx + 1`)

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
- **ไม่มีไอคอนหน้า label ของ field ใดๆ ทั้งสิ้น** (ของเดิมมี เช่น `.station-filter-body`'s `<label><i class="fa-solid fa-calendar">...` — ยืนยันแล้วมีจริง ~140 จุดใน 18 ไฟล์ทั่วแอป เป็นงาน migrate ของรอบ 4 — ตัดไอคอนออกตอนย้าย ไม่ใช่คัดลอกมาด้วย) และ**ไม่มี fieldset/legend look แบบเดิม** ("ตัวกรอง" เป็น corner label ลอยทับขอบกรอบ) — เปลี่ยนเป็นแถบเรียบแบนแทน (พื้น `--c-bg-subtle` ขอบ `--c-border` `--radius` ไม่มีเงา) ไม่มีคำว่า "ตัวกรอง" ซ้ำอยู่ในกล่อง (ป้ายหัวแผงเองมีคำนี้อยู่แล้ว)
- **จำสถานะกาง/ยุบต่อหน้าได้** ผ่าน `localStorage['filterbar:' + pageKey]` — partial รับ `$pageKey` (optional); ถ้าไม่ส่งมา ไม่จำสถานะเลย เริ่มยุบเสมอ
- collapse ใช้กลไกเดิมของ `.station-filter-body` (CSS class `.collapsed` + `max-height` transition ธรรมดา) **ไม่ใช่** Bootstrap `.collapse` component — เพื่อให้ "เหมือนเดิมเป๊ะ" ตามที่ตัดสินใจ
- ควบคุมทั้งหมดใช้ `.form-select-sm` / select2 ขนาดเดียว ปุ่มขนาด `.btn-sm`
- **`toolbarTarget` ถูกยกเลิกแล้ว (2026-09-13)** — เดิมมี option ให้ `initFilterBar()` ย้ายแถวปุ่ม/chips
  ไปต่อท้ายแถว Status Tabs เดียวกัน (align ขวา) แต่ตัดสินใหม่ว่า**ไม่ต้องยัดเข้าแถวอื่นแล้ว** — ทั้งแผง
  (หัว/ตัว/ท้าย) วางเป็น block ปกติตามตำแหน่งที่ include partial ไว้เสมอ หน้าที่มี Status Tabs (เช่น
  Payroll Process) แค่วาง `filter-bar.php` ต่อท้าย `status-tabs.php` ตามลำดับ markup ธรรมดา ไม่มี JS
  ย้าย DOM node ข้ามที่อีกต่อไป — `.filter-bar--toolbar-relocated`/`.status-tabs > .filter-bar-toolbar`
  ที่เคยมีถูกลบออกจาก `style.css` ทั้งคู่

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
- ≤ 2 action: วางเรียง gap `--sp-1` พร้อม tooltip ทุกปุ่ม
- > 2 action: ปุ่ม ⋮ วงกลม**เดียวกัน** (`.btn-icon`) เปิด dropdown (pattern เดียวกับ Detail › รายละเอียดพนักงาน) — รายการลบอยู่ล่างสุดคั่นด้วยเส้น ตัวหนังสือ `--c-danger`
- ไอคอนต้องสื่อความหมาย + tooltip เสมอ (BACKLOG: ทบทวนไอคอนทั้งระบบ ทำในรอบ 4)
- **`.btn-circle-action` เป็น alias ชั่วคราวของ `.btn-icon`** (ประกาศร่วมกันเป็น selector เดียวใน
  `style.css` — ลบสีทั้ง 7 tone ที่เคยผูกกับ `.text-{color}` ที่บางไฟล์เอาไปวางซ้อนออกแล้ว ด้วย
  `!important` เดียวกับที่ `.btn-icon` เองใช้กัน tone จริงหลุดมาได้) — รอบ 2 ไม่ต้องแตะ 14 ไฟล์เดิมเลย
  (ได้สไตล์ใหม่ทันทีที่ commit เพราะเป็นการแก้ shared CSS ไม่ใช่แก้หน้าจริง); รอบ 4 ค่อย rename
  `.btn-circle-action` → `.btn-icon` ทีละไฟล์เป็นส่วนหนึ่งของการทำหน้านั้น (ไม่ commit แยก) ผ่าน lint
  แบบผ่อน (เฉพาะไฟล์ที่ mark `design:clean` ถึง fail ดู §12) — ลบ alias ทิ้งเมื่อย้ายครบ 14 ไฟล์แล้วเท่านั้น
  เหมือน pattern เดียวกับ `.stat-card` ใน §2
- ~~เดิม (ยกเลิกแล้ว): ปุ่มไอคอน .btn.btn-sm.btn-icon เทา ไม่มีพื้น ไม่มีขอบ~~ — ดูสเปกใหม่ด้านบน

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
- ไม่ใช้สีกับตัวเลข (บวก/ลบ/มากน้อย) — ใช้เครื่องหมายและตำแหน่งคอลัมน์แทน

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
  form ใหม่** `showConfirm({title, message, confirmText, cancelText, danger, onYes, onNo})` เมื่อ
  argument แรกเป็น object — **`cancelText` เป็นการขยายเพิ่มนอกเหนือ draft เดิมของบรรทัดนี้** (ของเดิม
  มีแค่ `confirmText`) จำเป็นเพราะ modal dirty-guard (§9) ต้อง override ปุ่มทั้ง 2 ฝั่งพร้อมกัน
  ("กลับไปแก้ต่อ"/"ปิดโดยไม่บันทึก" ไม่ใช่ "Yes"/"No" default) — ห้ามเรียก `Swal.fire` ตรงๆ ในหน้า
  - **สไตล์ปุ่ม/ไอคอน — เปลี่ยน default ทั้งระบบ ผ่าน `tokens.css`/`style.css`'s `--swal2-*` block เดียว
    ไม่ใช่ per-call**: ปุ่มยืนยัน = `--c-primary` (ส้ม), `danger:true` = `--c-danger` (แดง, per-call
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
| `page-header.php` | `app/views/partials/` | การ์ดหัวหน้าทุกหน้า |
| `stat-card.php` | partials | การ์ดตัวเลขขอบสี |
| `filter-bar.php` (ปรับเป็นแผง 3 ส่วน หัว/ตัว/ท้าย, item 4 revision, ยกเลิก `toolbarTarget` — ดู §6) | partials | filter กางค้าง |
| `renderNotifications()` / `setNotificationCount()` (ใหม่, item 6d — เสร็จแล้ว, UI เท่านั้นยังไม่ต่อ backend; ของจริงมีอยู่แล้ว `notifications.js`/`NotificationModel` — 3 จุดต่างจริง (ไอคอนมีพื้นสี, จุด unread ขวา, badge "99+") ไม่ใช่แค่ token เดิม บันทึกไว้ให้รอบ 4 ตัดสินใจ — ดู §6) | app.js (ใหม่) | `.row-type-icon`/dot-ขวา ของจริง (คงไว้ ไม่แตะ — แค่ flag ความต่างสำหรับ migrate) |
| `status-stepper.php` + `renderStatusStepper()` (ใหม่, item 6 — เสร็จแล้ว; render อย่างเดียว ตำแหน่งเทียบ `current` เท่านั้น ไม่มี action/วันที่/branch icon แบบของจริง — logic ขั้นยังอยู่ที่ `runLifecycleSteps()` เดิม, ยังไม่ย้ายหน้าจริงมาใช้ รอรอบ 4 — ดู §6) | `app/views/partials/` + `app.js` | กล่อง 5 สี |
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
| `showConfirm()` (ขยายรับ object form + `cancelText`, item 7b — เสร็จแล้ว) / `showSuccess` (เปลี่ยนเป็น toast default, item 7b) / `showError` (ไม่เปลี่ยน) | app.js/alert.js (มีแล้ว) | Swal.fire ตรง — **ไม่สร้าง `confirmAction()` ใหม่** (ตัดสินใจแล้วรอบ 0 — ดู §10) |
| `resetModalTabs()` | app.js (มีแล้ว) | strip class เอง |
| `payslip-view.php` | partials | modal คำนวณแบบตาราง |
| `.scroll-thin` (ใหม่, notification "ซอฟต์ลง" follow-up 2026-09-13 — CSS utility class ล้วนๆ ไม่มี JS, scrollbar บาง 6px โปร่ง) | `style.css` | scrollbar เริ่มต้นหนาของ browser บน dropdown/panel ที่ scroll — ใช้กับ `.notif-list` แล้ว, ตัวไหนใน dropdown/panel ที่ scroll ต่อไปในระบบให้เรียกซ้ำ ไม่เขียน scrollbar CSS เองใหม่ |

เพิ่ม component ใหม่ต้องเสนอชื่อ + API + ที่ใช้ ≥ 2 จุด ก่อนเขียน

---

## 12. Lint (รันใน test suite, fail = commit ไม่ได้)

`scripts/check-design.php` สแกน `app/views/**`, `public/js/**` (ยกเว้น vendor/min), `public/css/**` (ยกเว้น `tokens.css`, vendor):
1. hex/rgb/hsl ใน CSS นอก `tokens.css` และใน `style=""` / JS string (ยกเว้น `--bs-*-rgb:` ใน `style.css`'s Bootstrap-override section — mechanical RGB-triplet decomposition ของ token ที่มีอยู่แล้ว ไม่ใช่ค่าสีใหม่ ดู comment ในไฟล์นั้นเอง)
2. `style="` inline ใน view/JS (ยกเว้น `display:none` ชั่วคราวใน JS และ `width` ของคอลัมน์ตาราง — allowlist ระบุใน script)
3. class ต้องห้าม: `btn-success btn-info btn-warning btn-light btn-dark btn-outline-(?!secondary) bg-primary bg-info bg-success text-primary text-info text-success border-primary rounded-circle btn-circle`
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
