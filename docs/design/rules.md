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
  --radius: 6px;                    /* ค่าเดียวทั้งระบบ */
  --radius-pill: 999px;             /* badge เท่านั้น */
  --shadow-modal: 0 8px 24px rgba(0,0,0,.12);   /* modal/dropdown เท่านั้น — การ์ดในหน้าไม่มีเงา */
}
```

กฎ:
- **Bootstrap override เป็นราย-component เสมอ ไม่ใช่แค่ root variable** (แก้ตามความจริงที่ตรวจพบแล้วในโค้ด — ดูรอบ 0's conflict report: Bootstrap 5.3.3's compiled `bootstrap.min.css` hardcode `--bs-btn-bg`/`--bs-btn-border-color` ฯลฯ ไว้ที่ class ของแต่ละ component ตรงๆ ไม่ได้อ่านจาก `--bs-primary` root token เลย — ตั้ง root var เดียวไม่พอ, ยืนยันจริงแล้วใน `style.css`'s "Bootstrap primary recolor" comment ปี 2026-08-20): `tokens.css` ต้อง (1) ตั้ง root token (`--bs-primary`, `--bs-primary-rgb`, `--bs-body-color`, `--bs-border-color`, `--bs-border-radius` ฯลฯ) สำหรับ utility ที่อ่าน var ตรง (`.text-primary`, `.bg-primary-subtle`) **และ** (2) override ทับทุก component ที่ Bootstrap hardcode ค่าไว้เอง ด้วยเทคนิคเดียวกับที่ `style.css` ใช้อยู่แล้ว (`.btn-primary { --bs-btn-bg: var(--c-primary); --bs-btn-border-color: var(--c-primary); --bs-btn-hover-bg: var(--c-primary-hover); ... }`) — รายการ component ที่ต้อง override แบบนี้อย่างน้อย: `.btn-primary`, `.btn-outline-secondary`, `.form-control`/`.form-select`, `.nav-tabs .nav-link`, `.badge` (ที่ยังไม่ผ่าน `statusBadge()`), `.dropdown-menu`, `.modal-content` — เช็คทุก component เพิ่มเติมที่ hardcode สีไว้ก่อน assume ว่า root var พอ (ยืนยันเป็นรายตัว ไม่ inherit จากที่เดียว)
- **`.btn-outline-brand` (ของเดิม — สร้างขึ้นเพราะปัญหาเดียวกันนี้เป๊ะ: outline ก็ไม่ได้สีจาก `--bs-primary` เหมือนกัน) ถูกแทนที่ด้วย `.btn-outline-secondary` ทั้งหมด หลังจากที่ tokens.css override `.btn-outline-secondary` ให้ใช้สีตาม §4 แล้ว** — ไม่มี "outline สีแบรนด์" อีกต่อไปตาม §4 (ปุ่มรองทุกตัวเป็นเทา แยกด้วยคำ ไม่แยกด้วยสี) ไม่ใช่ย้ายไปใช้ token อื่นแทน
- ไม่ใช้ `btn-info`, `btn-success`, `btn-warning`, `bg-primary`, `text-primary` ฯลฯ ที่ไม่ได้ map (ดู §12 lint)
- ตัวเลขทุกที่ (ตาราง, stat, สลิป) ใช้ `.num` → `font-variant-numeric: tabular-nums; text-align:right`
- ไอคอน: Font Awesome ชุดเดียว น้ำหนักเดียว (`fa-regular` หรือ `fa-solid` เลือกอันเดียวทั้งระบบ) สี = สีข้อความปัจจุบัน (`currentColor`) เสมอ ไม่มีไอคอนหลากสี
- **ธง (flag icon) เลิกใช้ทั้งหมด — ไม่มีข้อยกเว้น** (ตัดสินใจแล้วรอบ 2, ปิดช่องว่างที่รอบ 1 audit เจอ: `public/flags/th.png`/`gb.png` เป็นภาพสีตายตัว recolor ด้วย `currentColor` ไม่ได้ จึงไม่มีทางทำให้ตรงกับกฎไอคอนข้อบนได้ — ไม่ใช่ "หาข้อยกเว้นให้" แต่ตัดออกไปเลย) ทุกที่ที่ใช้ธงตัวเปลี่ยนภาษา (navbar language switcher, Payslip Template/Employment Certificate Template editor's TH/EN language tabs) เปลี่ยนเป็น**ข้อความ "TH | EN"** (ตัวที่ active = `--c-text` ตัวหนา, ตัวที่ไม่ active = `--c-text-muted`, คั่นด้วย `|` สีเทา, คลิกได้ทั้ง 2 ฝั่ง) — migrate เป็นส่วนหนึ่งของรอบ 4 (navbar อยู่ใน `layout/header.php`, canvas editor 2 ตัวอยู่ใน `_editor_content.php` — ไม่ใช่ไฟล์ที่รอบ 2 แก้ได้ ตาม scope limit ของรอบนี้)

---

## 2. Layout หน้า

```
┌ Sidebar ─┬────────────────────────────────────────────────────────┐
│          │ Breadcrumb (เทา, ตัวเล็ก)                               │
│          │ H1 หน้า                              [ปุ่มหลัก ส้ม 1 ตัว] │
│          │ คำอธิบาย 1 บรรทัด (ถ้าจำเป็นจริง)                       │
│          ├────────────────────────────────────────────────────────┤
│          │ Stat cards (ถ้ามี) — แถวเดียว การ์ดเท่ากัน ไม่มีสี/ไอคอน  │
│          │ Tabs (ไม่มีไอคอน)                                       │
│          │ Filter bar (ยุบ, มีป้ายจำนวน filter ที่ใช้)               │
│          │ ตาราง / เนื้อหา                                          │
└──────────┴────────────────────────────────────────────────────────┘
```

- **Page header = partial เดียว** `app/views/partials/page-header.php` รับ `title`, `breadcrumb[]`, `primary_action` (label + id/href + icon optional), `description` — **ไม่มี card ครอบ ไม่มีไอคอนหน้า ไม่มีพื้นหลังสี** (ของเดิม "การ์ดหัวหน้า + ไอคอน" ทุกหน้าให้แทนด้วย partial นี้)
- Breadcrumb กับ H1 ห้ามพูดซ้ำกัน — H1 คือชื่อหน้า breadcrumb คือทาง
- **Stat card** = partial `stat-card.php` (label, value, sub, optional link) render ด้วย **class ใหม่ `.stat`** (ไม่ใช่ `.stat-card`) — ตัดสินใจแล้วว่า **ทุบสี/ไอคอนของ `.stat-card` เดิมทิ้งจริง** (ของเดิมมี 7 tone สี + ไอคอนเสมอ ใช้อยู่ 5 ไฟล์ ณ ตอนตัดสินใจนี้ — ตรงข้ามกับกฎนี้โดยสิ้นเชิง ไม่ใช่ต่อยอด) พื้นขาว ขอบ `--c-border` **ไม่มีขอบซ้ายสี ไม่มีไอคอน** ตัวเลข `.num` ขนาด `--fs-xl` — ถ้าค่าเป็นสถานะที่ต้องตัดสินใจ (เช่น "รออนุมัติ 3") ใช้ badge ใน sub ไม่ใช่เปลี่ยนสีทั้งการ์ด
  - **Migration**: รอบ 2 สร้าง `.stat`/`stat-card.php` ใหม่เท่านั้น **ไม่แตะ 5 ไฟล์ที่ใช้ `.stat-card` เดิม**; รอบ 4 ย้ายทีละหน้า (หน้าไหนมี stat card ก็ย้ายเป็นส่วนหนึ่งของการทำหน้านั้นให้ clean ไม่ใช่ commit แยก) เมื่อย้ายครบ 5 ไฟล์แล้วให้ลบ CSS ของ `.stat-card`/`.stat-card-*` (`style.css`) ทิ้งเป็นขั้นตอนสุดท้าย — ห้ามลบ CSS เดิมก่อนไฟล์ล่าสุดที่ใช้มันย้ายเสร็จ
- Dashboard: ไม่มี welcome card, ไม่มีกราฟที่มีข้อมูลแท่งเดียว — เนื้อหาต้องเป็น "งานที่ต้องทำ" ก่อน (รออนุมัติ/อนุมัติแล้วรอทำต่อ/ค้างนาน) ตามด้วยตัวเลขสรุป
- ปุ่ม `?` ลอย: เอาออก — ความช่วยเหลือให้อยู่ใน helper text หรือลิงก์ "วิธีใช้" ใน page header เท่านั้น
- Max content width ไม่จำกัด (ตารางกว้าง) แต่ **padding ซ้าย/ขวาของ content เท่ากันทุกหน้า = `--sp-5`** และตาราง/การ์ด **เต็มความกว้าง content เสมอ** ไม่มี padding ซ่อนในตาราง (ปัญหา "ตารางไม่เต็มขอบ")

---

## 3. สี — ใครใช้ได้บ้าง

| สี | ใช้ได้กับ | ห้ามใช้กับ |
|---|---|---|
| ส้ม `--c-primary` | ปุ่มหลัก (1/หน้า, 1/modal), tab ที่เลือก (เส้นใต้), step ปัจจุบันใน stepper, focus ring | ไอคอน, ตัวเลข, badge, ขอบการ์ด, หัวตาราง, ลิงก์ในเนื้อหา |
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
- ปุ่มกลม (`.btn-circle`, `.rounded-circle`, **รวม `.btn-circle-action`**) เลิกใช้ทั้งหมด — **ยืนยันแล้ว ไม่ใช่การเดา**: `.btn-circle-action` คือปัญหาที่ระบุไว้ตั้งแต่ต้นของ phase design นี้เอง (ปุ่มกลมหลายสีต่อแถวในตาราง คือตัวอย่างที่ §0/§3 พูดถึงตรงๆ) แม้จะเป็นมติที่เคย roll out ทั่วระบบมาก่อนหน้านี้ (14 ไฟล์) ก็ถือว่า**ถูกแทนที่**โดย rules.md ฉบับนี้ — row action ใช้ §7 แทน (ไอคอนเทาไม่มีพื้น/ปุ่ม ⋮)
- Save/Cancel วางขวาล่างเสมอ ลำดับ `[ยกเลิก] [บันทึก]` (secondary ซ้าย, primary ขวาสุด) ทั้งใน modal footer และฟอร์มเต็มหน้า — `payroll-configuration` ต้องเป็นแบบนี้ด้วย
- ปุ่มระหว่างโหลด: `disabled` + spinner ในปุ่มเดิม ไม่เปลี่ยนคำ

---

## 5. Badge / สถานะ

- Badge = สถานะเท่านั้น ไม่ใช่ label ทั่วไป (ประเภท, หมวด, ที่มา → เป็นข้อความธรรมดาหรือคอลัมน์)
- helper เดียว: PHP `statusBadge($enum, $context)` / JS **`statusBadgeHtml(enum, context)`** (ชื่อ global JS ยืนยันแล้วรอบ 0 — ต้องมี suffix `Html` เสมอ ห้ามประกาศ global เปล่าชื่อ `statusBadge`) อ่าน map จาก `app/config/status_map.php` ที่เดียว (label i18n + tone) — **ห้ามเขียน map ใน view/JS**
  - **ก่อนประกาศ global `statusBadgeHtml()` (รอบ 2): ต้อง rename `function statusBadge(row)` local ที่มีอยู่แล้วใน `public/js/setup/payroll-configuration.js` เป็น `pcRowStatusBadge(row)` ก่อน** (ตัดสินใจแล้วรอบ 0) — ชื่อชนกันตรงๆ ในสภาพแวดล้อม non-module script ที่ global function ประกาศซ้ำชื่อไม่ error แต่ผลลัพธ์ fragile ขึ้นกับลำดับโหลดไฟล์ (บั๊กคลาสเดียวกับที่เจอจริงมาแล้วใน Payslip/ECT Template's double-declared `let` — ดู `project_payslip_template_canvas_rebuild` memory)
- Tone มี 4 ค่า: `neutral` (เทา), `warning`, `danger`, `success` — เลือกจากคำถาม "ผู้ใช้ต้องทำอะไรกับสถานะนี้ไหม": ต้องทำ → warning/danger, จบแล้ว → success, แค่รู้ → neutral
- รูปแบบเดียว: `.badge.badge-{tone}` พื้น `--c-{tone}-soft` ตัวหนังสือ `--c-{tone}` `--radius-pill` ไม่มีไอคอน ไม่มีขอบ
- ไม่แสดง badge ซ้ำในทุกแถวถ้าค่าเหมือนกันทั้งตาราง (เช่น "Origami" ทุกแถว) → ย้ายไปเป็น filter หรือหัวตาราง
- ป้ายในหัว modal (ธง + ไอคอน + badge "รายการของบริษัทคุณ"): เหลือ**ชื่อ modal อย่างเดียว** ข้อมูลประกอบย้ายไปบรรทัดรอง (`--c-text-muted`) ใต้ชื่อ

---

## 6. Tabs, Stepper, Filter bar

**Tabs** (`.nav-tabs` ที่ override แล้ว)
- ไม่มีไอคอนใน tab ทุกหน้า (เอาออกทั้งหมด รวม tab รายงาน)
- ไม่มี chevron / ลูกศร
- tab ที่เลือก: ตัวหนังสือ `--c-text` + เส้นใต้ 2px `--c-primary`; ไม่เลือก: `--c-text-muted`
- จำนวนที่ต้องแสดง (เช่น "รออนุมัติ 3") ใช้ตัวเลขเทาในวงเล็บหลังชื่อ tab ไม่ใช่ badge สี

**Stepper** (ไทม์ไลน์ 5 ขั้นของรอบ)
- partial เดียว `status-stepper.php` + JS `renderStatusStepper(steps, current)` (แทน `runLifecycleSteps()` render ส่วน HTML — logic ขั้นยังอยู่ที่เดิม)
- เสร็จแล้ว: วงกลมเทา + ✓, ตัวหนังสือ `--c-text-muted`; ปัจจุบัน: วงกลม `--c-primary` ตัวหนังสือ `--c-text` หนา; ถัดไป: วงกลมขอบ `--c-border-strong` ว่าง
- **ไม่มีสีพาสเทล 5 สี ไม่มีกล่องต่อขั้น** — เส้นเชื่อมสีเดียว `--c-border`

**Filter bar**
- partial `filter-bar.php`: ปิด (ยุบ) โดย default; ปุ่ม secondary "ตัวกรอง (N)" แสดงจำนวนที่ active; ปุ่ม tertiary "ล้าง" โผล่เมื่อ N > 0
- filter ที่ active แสดงเป็น chips เทาใต้ปุ่ม (ปิดได้ทีละตัว) — ผู้ใช้เห็นว่ากรองอะไรอยู่โดยไม่ต้องกาง
- ควบคุมทั้งหมดใช้ `.form-select-sm` / select2 ขนาดเดียว ปุ่มขนาด `.btn-sm`

---

## 7. ตาราง — DataTable standard (ใช้กับทุกตารางทั้งเก่าและใหม่)

**การ init**: `initSharedDataTable(selector, options)` เท่านั้น (helper ที่มีอยู่แล้วใน app.js) — ห้าม `$(...).DataTable({...})` ตรงๆ ในหน้า; option ต่อตารางส่งเป็น override

**Per-column sort/filter (ช่องว่างที่พบรอบ 0, ตัดสินแล้ว)**: CLAUDE.md's Table convention เดิมบังคับว่าทุก `<th>` ที่มีข้อมูลจริงต้องเรียก **`initExcelColumnFilters(dt, options)`** (`public/js/table-column-filter.js`) เอง ต่อตาราง ใน `initComplete` — กฎนั้นยังใช้อยู่ ไม่ถูกยกเลิก แต่ **`initSharedDataTable()` ต้องครอบหน้าที่นี้ให้เองจากรอบ 2 เป็นต้นไป** (อ่าน `columnDefs`/`columns` ที่ caller ส่งมา แล้วเรียก `initExcelColumnFilters()` ให้อัตโนมัติตาม mode ที่เหมาะกับตาราง client/server — หน้าเรียกทีเดียวผ่าน `initSharedDataTable()` ไม่ต้องเรียก `initExcelColumnFilters()` แยกเองอีก) รายละเอียด mode/exemption ตาม CLAUDE.md's Table convention เดิม (`mode:'client'`/`mode:'server'`, exempt คอลัมน์ปุ่ม/widget ภาพ/ตารางที่มี top-level filter อยู่แล้ว) — รายละเอียดการ implement (จะ auto-detect คอลัมน์ที่ควร filter ยังไง) ตัดสินตอนรอบ 2

**Layout มาตรฐาน** (helper จัด `layout`/`dom` ให้เอง หน้าไม่ต้องกำหนด):
```
[ค้นหา…            ]                     [แสดง 25 ▾] [ส่งออก ▾]
┌────────────────────────────────────────────────────────────────┐
│ หัวตาราง (พื้น --c-bg-subtle, ตัวหนังสือ --c-text-muted, ไม่หนา) │
│ … แถว …                                                          │
└────────────────────────────────────────────────────────────────┘
แสดง 1–25 จาก 130                                   ‹ 1 2 3 ›
```
- toolbar (ค้นหา/length/ส่งออก) **อยู่กับที่** ไม่เลื่อนตามตาราง; ตารางที่กว้างเกิน scroll แนวนอนภายในตัวเอง + **fix คอลัมน์แรก (พนักงาน) และหัวตาราง** + ลากเลื่อนได้ — ตาม pattern `/employees#employee-recheck-top-tab` ที่ตกลงเป็นต้นแบบ
- ส่งออก: dropdown secondary ตัวเดียว (Excel / PDF) ต่อจากช่องค้นหา — helper เปิดให้เมื่อ `export: true`
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
| checkbox เลือกแถว | กึ่งกลาง | `.col-check` | กว้างคงที่ |
| รูป/avatar | กึ่งกลาง | `.col-avatar` | ใช้ `apvAvatarHtml()` ตัวเดียว |
| action | ขวา | `.col-actions` | กว้างคงที่, ไม่ sort, ไม่ search |
| หัวคอลัมน์ | **เหมือน content ของคอลัมน์นั้น** | | ตัวเลขหัวชิดขวาด้วย |

**Row actions**
- ≤ 2 action: ปุ่มไอคอน `.btn.btn-sm.btn-icon` เทา (ไม่มีพื้น ไม่มีขอบ, hover พื้น `--c-bg-hover`) พร้อม tooltip
- > 2 action: ปุ่ม ⋮ ตัวเดียว เปิด dropdown (pattern เดียวกับ Detail › รายละเอียดพนักงาน) — รายการลบอยู่ล่างสุดคั่นด้วยเส้น ตัวหนังสือ `--c-danger`
- ไอคอนต้องสื่อความหมาย + tooltip เสมอ (BACKLOG: ทบทวนไอคอนทั้งระบบ ทำในรอบ 4)
- **Migration จาก `.btn-circle-action`**: รอบ 2 ไม่ต้องแตะ 14 ไฟล์เดิม; รอบ 4 ย้ายทีละหน้าเป็นส่วนหนึ่งของการทำหน้านั้น (ไม่ commit แยก) ผ่าน lint แบบผ่อน (เฉพาะไฟล์ที่ mark `design:clean` ถึง fail ดู §12) — ลบ CSS `.btn-circle-action` ทิ้งเมื่อย้ายครบ 14 ไฟล์แล้วเท่านั้น เหมือน pattern เดียวกับ `.stat-card` ใน §2

**ข้อความว่าง**: จาก `getTableLang().emptyTable` — ประโยคเดียวบอกว่าทำอะไรต่อได้ (เช่น "ยังไม่มีรายการ — กด 'เพิ่มรายการ' เพื่อเริ่ม")

---

## 8. ตัวเลขและเงิน (ทั่วระบบ ไม่ใช่แค่ตาราง)

- **แสดงผล**: helper เดียว PHP `fmtMoney($n)` (ยังไม่มี — สร้างรอบ 2) / JS **`fmtNum(n)`** (`public/js/format-helpers.js`, **มีอยู่แล้ว** — ตัดสินใจแล้วรอบ 2: ไม่สร้าง `formatMoney()` ใหม่ ใช้ `fmtNum()` เดิมเป็นตัวเดียว เพราะทำ `toLocaleString` แบบ 2 ทศนิยมคงที่อยู่แล้วตรงตามที่กฎนี้ต้องการ ไม่ต้อง "ขยาย" อะไรเพิ่ม — เจอระหว่าง audit รอบ 1 ว่ามีอยู่แล้วเหมือนกันเป๊ะ) → `1,234,567.89`; ห้าม `number_format` / `toLocaleString` ตรงๆ ในหน้า (ยกเว้นภายใน `fmtNum()`/`fmtMoney()` เอง) — รอบ 2 ต้องยืนยันด้วย test ว่า PHP `fmtMoney($n)` กับ JS `fmtNum(n)` ให้ผลตรงกันทุกกรณี (ทศนิยม, ค่าลบ, ค่า null/ว่าง, ตัวเลขใหญ่ที่มี comma)
- **input เงิน**: `<input class="money-input">` + JS `initMoneyInputs($scope)` (เรียกอัตโนมัติจาก app.js ready + หลัง modal shown/render): พิมพ์ได้ตัวเลขและจุด, blur → ใส่ comma + 2 ทศนิยม, focus → เอา comma ออก, **ค่าที่ส่ง server เป็นตัวเลขล้วน** (helper ใส่ hidden input หรือ strip ใน `collect*FormData` — เลือกทางเดียว ใช้ทุกฟอร์ม)
- ตัวเลขในหน้า/สลิป/stat ทั้งหมด `.num` (tabular)
- ไม่ใช้สีกับตัวเลข (บวก/ลบ/มากน้อย) — ใช้เครื่องหมายและตำแหน่งคอลัมน์แทน

---

## 9. ฟอร์มและ Modal

**Modal**
- ขนาด: `modal-lg` เป็นค่าเริ่มต้นสำหรับฟอร์ม, `modal-xl` เฉพาะที่มีตาราง, ไม่ใช้ fullscreen ยกเว้น editor
- Header: ชื่ออย่างเดียว (H5) + ปุ่ม × — ไม่มีธง/ไอคอน/badge; บริบท (รหัส, ชื่อพนักงาน) เป็นบรรทัดรอง `--c-text-muted` ใต้ชื่อ หรือใช้ `.emp-header-card` (ตอนนี้จัด style ให้: พื้น `--c-bg-subtle`, avatar 40px, ชื่อ + รหัส/แผนก 1 บรรทัด, ไม่มีขอบสี)
- Footer: พื้น `--c-bg-subtle`, `[ยกเลิก] [บันทึก]` ขวา; ปุ่มทำลาย (ลบ) ถ้ามี = tertiary ซ้ายสุด
- **confirm ก่อนปิด modal ที่มีข้อมูลค้าง**: ใช้กลไกเดิม `isFormDirty($container, baselineSnapshot)` + `confirmIfDirtyThen($container, baselineSnapshot, onProceed)` (`app.js`, Platform Hardening Phase 1 — มีอยู่แล้ว) **ไม่สร้าง `guardDirtyModal()` ใหม่** (ตัดสินใจแล้วรอบ 0) — track `input`/`change` ในฟอร์ม (ของเดิมทำอยู่แล้ว); ปิดด้วย ×/Esc/backdrop ขณะ dirty → SweetAlert "ยังไม่ได้บันทึก ปิดหรือไม่" ; reset flag หลัง save สำเร็จ/`resetForm`. **รอบ 2 ต้องผูกกลไกนี้กับทุก modal ที่มีฟอร์มจากจุดเดียวใน app.js** (delegated บน `hide.bs.modal`/ปุ่ม ×/Esc ทั่วระบบ, เช็ค `.modal:has(form)` หรือ marker class) — ห้ามผูกทีละ modal เอง (มิฉะนั้น modal ใหม่ทุกตัวต้อง remember to wire เอง ซึ่งเป็นความเสี่ยงเดียวกับที่ §0 ข้อ 4 ห้าม)
- Modal ซ้อน: ใช้ global z-index/scroll fix ใน app.js ที่มีแล้ว ไม่จัดการเองต่อ modal
- ซ่อน block ที่ว่าง (ทำแล้วใน 3B) เป็นกฎถาวร

**ฟอร์ม**
- Grid: label ซ้าย (`col-lg-3`, `--c-text-muted`) / input ขวา (`col-lg-9`) สำหรับ modal; ฟอร์มเต็มหน้าใช้ label บน input ได้เมื่อมี ≥ 2 คอลัมน์
- required = `*` แดงหลัง label (ที่เดียวที่แดงใช้ได้นอกสถานะ)
- **Helper text ≤ 1 บรรทัด** (`.form-text`, `--c-text-muted`) ถ้าเกิน → ตัดเหลือประโยคหลัก และย้ายรายละเอียดไป tooltip ไอคอน ⓘ หลัง label หรือเอกสาร; ห้ามมี helper ทุกช่อง ใส่เฉพาะที่ผู้ใช้จะกรอกผิดถ้าไม่มี
- Validation: inline ใต้ช่อง (`.invalid-feedback`) + focus ช่องแรกที่ผิด; ไม่ใช้ alert สำหรับ validation
- Segmented/toggle (ใช่-ไม่ใช่): `.btn-group` ของ `.btn-outline-secondary.btn-sm` ตัวที่เลือกเป็น `active` (พื้น `--c-bg-subtle` ขอบ `--c-border-strong`) — ไม่ใช้ส้มกับ toggle
- ฟอร์ม "รายการจ่าย/หัก", "จัดการรอบ", "ตั้งค่ารอบ": จัดกลุ่มช่องด้วยหัวข้อย่อย (`--fs-sm` หนา) + เส้น `--c-border` ระหว่างกลุ่ม ไม่ใช้ card ซ้อน card

**สลิป / รายละเอียดการคำนวณ** (modal รายละเอียดการคำนวณ)
- partial `payslip-view.php` แบบสลิป 2 คอลัมน์ (รายได้ | รายหัก) + สรุปล่าง (รวมรายได้/รวมหัก/สุทธิ) ตัวเลข `.num` — ใช้ทั้ง modal และหน้าพิมพ์/PDF ตัวเดียวกัน

---

## 10. Feedback

- ยืนยัน (ลบ, ส่งอนุมัติ, ปิดรอบ, ย้อนสถานะ): SweetAlert2 ผ่าน helper เดิม **`showConfirm(...)`** (`public/js/alert.js`, มีอยู่แล้วทั่วระบบ) — **ไม่สร้าง `confirmAction()` ใหม่** (ตัดสินใจแล้วรอบ 0: ของเดิมทำหน้าที่เดียวกันอยู่แล้ว สร้างคู่กันจะขัดกับ §0 ข้อ 4 เอง) แก้ `showConfirm()` ให้รับได้ 2 รูปแบบ: **positional เดิม** `showConfirm(title, msg, yesCallback, noCallback)` (ทุก call site เดิมยังทำงานเหมือนเดิม 100%) **หรือ object form ใหม่** `showConfirm({title, message, confirmText, danger, onYes, onNo})` เมื่อ argument แรกเป็น object (เช็คด้วย `typeof arg1 === 'object'`) — object form: ปุ่มยืนยัน primary (หรือ danger ถ้า `danger:true`), ปุ่มยกเลิก secondary, `confirmText` override ข้อความปุ่มยืนยัน (default "ยืนยัน"); ห้ามเรียก `Swal.fire` ตรงๆ ในหน้า
- สำเร็จ: toast มุมขวาบน 3 วินาที (helper `showSuccess`) ข้อความ = กริยาเดิม + "แล้ว"
- ผิดพลาดจาก server: `showError` ข้อความบอกสาเหตุ + สิ่งที่ทำได้ ไม่ขอโทษ ไม่คลุมเครือ
- Loading: ปุ่ม spinner (ปุ่ม) หรือ `.table-loading` overlay (ตาราง) — ไม่มี spinner เต็มหน้า

---

## 11. Shared components — ต้องใช้ ห้ามเขียนเอง

| ชื่อ | ที่อยู่ | แทนของเดิม |
|---|---|---|
| `page-header.php` | `app/views/partials/` | การ์ดหัวหน้าทุกหน้า |
| `stat-card.php` | partials | การ์ดตัวเลขขอบสี |
| `filter-bar.php` | partials | filter กางค้าง |
| `status-stepper.php` + `renderStatusStepper()` | partials + app.js | กล่อง 5 สี |
| `statusBadge()` / `statusBadgeHtml()` + `status_map.php` (rename `payroll-configuration.js`'s local `statusBadge(row)` → `pcRowStatusBadge(row)` ก่อนประกาศ global — ดู §5) | helpers + app.js + config | map สถานะกระจาย |
| `initSharedDataTable()` (ขยาย: layout, export, fixed column, columnDefs alignment) | app.js | init ตรงทุกหน้า |
| `fmtMoney()` (ใหม่) / `fmtNum()` (มีแล้ว, `format-helpers.js` — **ไม่สร้าง `formatMoney()` ใหม่**, ตัดสินใจแล้วรอบ 2 — ดู §8) / `initMoneyInputs()` (ใหม่) | helpers + app.js | number_format กระจาย |
| `apvAvatarHtml()` / `apvPersonLineHtml()` | app.js (มีแล้ว) | avatar เขียนเอง |
| `emp-header-card` (style ตาม §9) | modals (มีแล้ว) | หัว modal ธง+ไอคอน |
| `isFormDirty()` / `confirmIfDirtyThen()` | app.js (มีแล้ว, Platform Hardening Phase 1) | ผูก dirty-check เองทีละ modal — **ไม่สร้าง `guardDirtyModal()` ใหม่** (ตัดสินใจแล้วรอบ 0 — ดู §9) |
| `showConfirm()` (ขยายรับ object form) / `showSuccess` / `showError` | app.js/alert.js (มีแล้ว) | Swal.fire ตรง — **ไม่สร้าง `confirmAction()` ใหม่** (ตัดสินใจแล้วรอบ 0 — ดู §10) |
| `resetModalTabs()` | app.js (มีแล้ว) | strip class เอง |
| `payslip-view.php` | partials | modal คำนวณแบบตาราง |

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

---

## 13. กระบวนการ phase design

**มติเดิมที่ขัดกับ rules.md ถือว่าถูกแทนที่** — ถ้ามีมติ/convention ที่ตกลงกันไว้ก่อนหน้า phase design นี้ (ไม่ว่าจะ roll out ไปแล้วกว้างแค่ไหน เช่น `.btn-circle-action` ที่เคย "full rollout done app-wide" มาก่อน — ดู §4) ขัดกับกฎในเอกสารนี้ ให้ยึด rules.md เป็นมติล่าสุดเสมอ ไม่ต้องถามซ้ำว่าจะคง convention เดิมไว้ไหม — ของเดิมยังใช้งานได้จนกว่าจะถูกย้ายจริงตามลำดับรอบ (2/3/4 ตามที่ระบุไว้ต่อกรณี) แต่**ห้ามเขียนโค้ดใหม่ตาม convention เดิมอีก** นับจากรอบ 0 นี้เป็นต้นไป

| รอบ | ทำอะไร | ผลลัพธ์ | ห้าม |
|---|---|---|---|
| 1 Audit | ไล่ทุกหน้า/modal ตามกฎ §2–§10 บันทึกใน `docs/design/audit.md`: หน้า, จุดที่ผิดกฎ (อ้าง §), จำนวน lint hit, ระดับ (ใหญ่/เล็ก) | รายงานเดียว + ลำดับหน้าที่เสนอ | แก้โค้ด |
| 2 Tokens + shared | `tokens.css`, override Bootstrap, สร้าง/ขยาย component ใน §11, lint script, หน้า `docs/design/components.php` (ตัวอย่างทุก component ในหน้าเดียว ใช้ทดสอบสายตา) | commit ทีละ component | แตะหน้าจริง |
| 3 นำร่อง | Detail › tab รายละเอียดพนักงาน (รวม modal ที่เปิดจากแถว) ใช้ component ทั้งหมด → รีวิวจริงในเบราว์เซอร์ → ปรับกฎ/component ถ้ากฎใช้จริงไม่ได้ | หน้าต้นแบบ 1 หน้า + กฎฉบับแก้ | ไปหน้าอื่น |
| 4 ไล่หน้า | ตามลำดับจาก audit ทีละหน้า: หน้า = 1 commit, ท้ายไฟล์ mark `design:clean`, lint ผ่าน | ทุกหน้า clean | รวมหลายหน้าใน commit |

กฎรายงานทุกรอบ: ก่อน/หลัง เป็นรายการจุดที่เปลี่ยน (อ้าง §) + สิ่งที่ยังไม่แน่ใจ + lint count ก่อน/หลัง — ไม่มีการอธิบายว่า "สวยขึ้น" ต้องอ้างกฎเสมอ
