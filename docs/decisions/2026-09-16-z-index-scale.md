# z-index เป็น scale เดียวใน tokens.css (2026-09-16)

## บั๊กที่เป็นจุดเริ่ม

modal ซ้อน (`#lineOverrideHistoryModal`, z 1085) ปุ่ม "ใช้ค่านี้" เรียก `showConfirm()` แล้ว **dialog
ไปอยู่ใต้ modal** — อ่านไม่ได้และกดไม่ได้จริง ไม่ใช่แค่ดูแปลก

วัดด้วย Playwright ก่อนแก้ (hit test ด้วย `document.elementFromPoint()` ที่จุดกึ่งกลางปุ่มยืนยัน ไม่ใช่
ดูจากภาพ):

| layer | z-index ก่อนแก้ | มาจาก |
|---|---|---|
| `.modal-backdrop` (หลัก) | 1050 | Bootstrap (`--bs-backdrop-zindex`) |
| `.modal` (หลัก) | 1055 | Bootstrap (`--bs-modal-zindex`) |
| backdrop ของ modal ซ้อน | 1075 | inline, `app.js`'s `shown.bs.modal` handler |
| modal ซ้อน | 1085 | inline, handler เดียวกัน (`1055 + level*20 + 10`) |
| **`.swal2-container`** | **1060** | stylesheet ที่ SweetAlert2 inject เอง |
| `.dropdown-menu` ใน modal | 1000 (`auto` ใน stacking context ของ modal) | Bootstrap — **ไม่เคยพัง** เพราะอยู่ใน stacking context ของ modal อยู่แล้ว (hit test ยืนยัน: จุดบนสุดที่เมนู = ตัวเมนูเอง) |
| `.select2-container` ใน modal | 1 (`position: relative`); dropdown ของมันประกาศ 1056 | select2-bootstrap-5-theme |
| toast / tooltip / popover / offcanvas | ไม่มี instance บนหน้าจอตอนวัด; ค่า default ของ Bootstrap | — |

ผลวัดตอนเปิด confirm จาก modal ซ้อน: `confirmButtonReachable: false`, element บนสุดที่ตำแหน่งปุ่มยืนยัน =
`.timeline-head` (เนื้อใน modal ซ้อน) → dialog ถูกทับจริง

**1060 เคยพอดีพอที่จะไม่มีใครเห็นปัญหา**: มันชนะ modal เดี่ยว (1055) อยู่ 5 หน่วย ทุก confirm จากหน้า/modal
ปกติจึงถูกต้องมาตลอด ปัญหาโผล่เฉพาะตอนมี modal ซ้อนเท่านั้น ซึ่งเพิ่งมีตัวแรกในแอปรอบนี้เอง

## ทางแก้: scale เดียวใน `tokens.css` ไม่ใช่แก้เลขที่จุดที่พัง

```
1050  --z-modal-backdrop
1055  --z-modal
1075  --z-modal-nested-backdrop
1085  --z-modal-nested
1090  --z-popover          (popover ใน modal ซ้อนเป็นรูปแบบที่มีจริง จึงต้องสูงกว่า 1085)
1100  --z-swal             (dialog = วิธีที่ modal ถามคำถาม ต้องชนะ modal ทุกชั้น)
1110  --z-toast            (บันทึกสำเร็จระหว่าง flow ที่มี dialog ก็ยังต้องเห็น)
```

เริ่มจากเลขของ Bootstrap เอง (1050/1055) เพราะ `bootstrap.css` ที่ bundle มา ship ค่านี้อยู่แล้ว —
ทุกอย่างเหนือจากนั้นเป็นของแอป · เว้นช่วงไว้ (ไม่ใช่เลขติดกัน) เพราะ modal ซ้อนต้องมีที่ว่างระหว่างตัวมันเอง
กับ backdrop ของตัวเอง

**ผู้ใช้ token แต่ละตัว**

- Bootstrap components รับผ่าน **custom property ของ component ตัวเอง** (`.modal { --bs-modal-zindex:
  var(--z-modal) }`, `.modal-backdrop`, `.popover`) — เทคนิคเดียวกับที่ §4 ใช้กับสี เพราะ Bootstrap
  hardcode ค่าพวกนี้ไว้ที่ class ของ component ไม่ได้อ่านจาก `:root`
- **SweetAlert2 รับเป็น declaration ธรรมดาบน class ที่ render จริง** (`.swal2-container { z-index:
  var(--z-swal) }`) — ทางเดียวที่รอดจาก stylesheet ที่ library inject เข้ามาเอง: selector ของมันคือ
  `div:where(.swal2-container)` = specificity (0,0,1), ของเราคือ (0,1,0) ชนะเสมอโดยไม่ต้องใช้
  `!important` และ **ห้ามตั้งที่ `:root`** (จะตายเงียบทั้งก้อน) — เหตุผลเต็ม:
  `docs/decisions/swal2-css-override.md`
- **`app.js`'s stacked-modal handler อ่าน token กลับมาใช้** แทนเลขคำนวณเอง
  (`getComputedStyle(document.documentElement).getPropertyValue('--z-modal-nested')`) — modal ซ้อนชั้นที่ 3
  (รูปแบบที่แอปนี้ไม่มี) **ตั้งใจให้อยู่ระดับเดียวกับชั้นที่ 2** ไม่ไต่ขึ้นไปทับ popover/dialog/toast —
  การอยู่หลังใน DOM ก็ทำให้มันทับชั้นที่ 2 ได้อยู่แล้ว

## ที่ยังไม่ได้แตะ (ตั้งใจ)

- ~~`.select2-container--bootstrap-5 .select2-dropdown` = 1056 จาก theme ของ select2 เอง~~ — **แก้แล้วในรอบที่ 2
  ด้านล่าง**: 1056 สูงกว่า backdrop จึงลอยอยู่เหนือดิมเมื่อเปิดจากในหน้า — แยกเป็น 2 ระดับ (`--z-page` /
  `--z-modal-overlay`) แล้ว
- `.dropdown-menu` ใน modal — ไม่มีอะไรต้องแก้: มันอยู่ใน stacking context ของ modal เอง ค่า 1000 จึงเทียบ
  กับพี่น้องใน modal เท่านั้น ไม่เคยแข่งกับ backdrop (ยืนยันด้วย hit test แล้ว ไม่ได้เดา)
- Bootstrap toast (`.toast-container`) — แอปนี้ไม่มี instance จริงสักตัว (toast ของแอป = Swal2 toast
  mode ซึ่ง `.swal2-container.swal2-toast-shown` ครอบให้แล้ว) จึงไม่ประกาศ rule ลอยไว้


---

## รอบที่ 2 (วันเดียวกัน): regression จาก scale เอง — modal หลักไปอยู่ใต้ backdrop

**อาการที่ user เจอ**: `#manageLinesModal` ถูกทำมืดทั้งกล่อง ส่วน navbar / select2 ใน filter bar /
sticky column / pagination / ปุ่มช่วยเหลือ กลับลอยอยู่เหนือ backdrop

**สาเหตุจริง — ไม่ใช่ลำดับของ scale แต่เป็นเรื่อง cache**: `tokens.css` ถูกดึงเข้ามาด้วย
`@import url("tokens.css")` ที่บรรทัดแรกของ `style.css` — **URL นั้นไม่มี `?v=`** ขณะที่ `style.css` เองถูกขอ
เป็น `style.css?v=<filemtime>` เสมอ (`asset()`) → เบราว์เซอร์โหลด style.css ใหม่ทุกครั้งที่ไฟล์เปลี่ยน
แต่ใช้ `tokens.css` จาก cache ตัวเก่าต่อไป **token ทุกตัวที่เพิ่งเพิ่มใหม่จึง undefined สำหรับคนกลุ่มนี้**

และ `var()` ที่อ้าง custom property ที่ไม่มีอยู่**ไม่ได้แปลว่า "ไม่มีค่า"** — มันทำให้ declaration ทั้งบรรทัด
invalid at computed-value time → `z-index: var(--bs-modal-zindex)` กลายเป็น **`auto`** ทั้ง `.modal` และ
`.modal-backdrop` → ทั้งคู่หลุดออกจากการเรียงชั้น แล้ว DOM order ตัดสินแทน (backdrop ถูก append ทีหลัง จึงทับ modal)
ส่วนองค์ประกอบของหน้าที่มี z-index จริง (navbar, sticky, ปุ่มช่วยเหลือ 1045) ก็ลอยเหนือ backdrop ที่เป็น `auto`

ทำซ้ำได้แน่นอนด้วย Playwright (intercept request ของ `tokens.css` แล้วตอบไฟล์จาก worktree ที่ HEAD
คู่กับ `style.css` ของ working tree) — ตารางเทียบที่วัดจริง:

| | HEAD | หลังแก้ (cache ใหม่) | **tokens.css เก่าใน cache** |
|---|---|---|---|
| `.modal-backdrop` | 1050 | 1050 | **auto** |
| `.modal` | 1055 | 1055 | **auto** |
| `.swal2-container` | 1060 | 1100 | **auto** |
| modal ถูก backdrop ทับ | ไม่ | ไม่ | **ใช่** |
| confirm จาก modal ซ้อนกดได้ | **ไม่** | ใช่ | ไม่ |

### ทางแก้ 3 ชั้น

1. **ต้นเหตุ**: `tokens.css` โหลดเป็น `<link>` ของตัวเองผ่าน `asset()` (มี `?v=`) ก่อน `style.css`
   ทั้งใน `layout/header.php` และ `docs/design/components.php` — ลบ `@import` ทิ้ง
   · **กฎทั่วไป: ไฟล์ที่มี version ห้ามขึ้นกับไฟล์ที่ไม่มี** (rules.md §1)
2. **กันชั้นที่ 2**: ทุก `var(--z-*)` มีตัวเลขเป็น fallback (`var(--z-modal, 1055)`) — token หายแล้วได้
   เลขเก่ากลับมา ไม่ใช่ `auto` · วัดแล้ว: โหมด stale ให้ผลเท่ากับโหมดปกติทุกตัวเลข
3. **เติม scale ให้ครบ 2 ชั้นที่ขาดไป** และย้ายทุกตัวที่ล้ำเข้ามา:
   - `--z-page` 1045 = เพดานของหน้า — `.origami-sidebar` (1050 → token), `.help-drawer-panel` (1050 → token),
     `.help-drawer-toggle-btn` (1045 → ต่ำกว่า panel 1 ขั้นตามลำดับเดิม), `.tcf-panel` (**2000** → token),
     select2 dropdown ในหน้า (**1056** → token)
   - `--z-modal-overlay` 1090 = dropdown/select2/popover ที่เปิดจากใน modal (`--z-popover` เดิมถูกรวมมาตรงนี้)
     — select2 ship z-index เดียว (1056) ซึ่งสูงกว่า backdrop: ต้องแยกเป็น 2 ระดับ ไม่ใช่ค่าเดียว

### วัดหลังแก้ (payroll-process list, 1400 light)

| layer | ก่อน | หลัง |
|---|---|---|
| select2 dropdown ในหน้า | 1056 | **1045** |
| `.tcf-panel` (column filter) | 2000 | **1045** |
| `.origami-sidebar` | 1050 | **1045** |
| `.help-drawer-panel` | 1050 | **1045** |
| `.help-drawer-toggle-btn` | 1045 | **1044** |
| `.dt-paging` / navbar | auto/static | ไม่เปลี่ยน |
| sticky column (FixedColumns) | 1 / 3 (จาก library) | ไม่เปลี่ยน |
| `.modal-backdrop` / `.modal` | 1050 / 1055 | ไม่เปลี่ยน |

**หมายเหตุเรื่องวิธีวัด**: ขณะ modal เปิด `.modal` เองคลุมเต็ม viewport (`position: fixed; inset: 0`) ที่ 1055
— `elementFromPoint()` จึงคืน `.modal` เกือบทุกจุดนอกกล่อง dialog ไม่ใช่ `.modal-backdrop` การตอบว่า
"หน้าอยู่ใต้ดิมหรือไม่" จึงต้องตัดสินจาก **ลำดับของ computed z-index** ไม่ใช่ hit test (hit test ใช้ได้กับ
"confirm อยู่เหนือ modal ไหม" ซึ่งเป็นการเทียบ 2 ตัวที่ทับกันจริง)
