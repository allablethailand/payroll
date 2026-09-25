# เส้นใต้ tab ที่ active หายบางจอ/บาง tab — เปลี่ยนวิธีวาดเส้นของ `.nav-tabs` (2026-09-15)

## อาการที่ถูกรายงาน

ใน `#manageLinesModal` tab "รายการจ่าย" กับ "ปรับตัวเลข" ตอน active ไม่มีเส้นใต้สีส้ม ส่วน tab อื่นมี —
และผู้ใช้สังเกตเพิ่มว่าขึ้นกับขนาดจอ บางขนาดปกติ บางขนาดเส้นหาย

## สาเหตุจริง (วัดแล้ว ไม่ใช่เดา)

ไม่ใช่ `.form-compact` และไม่ใช่เรื่องเฉพาะ modal นี้ — เป็นของ shared ทั้งระบบ:

- Bootstrap ตั้ง `.nav-tabs .nav-link { margin-bottom: calc(-1 * var(--bs-nav-tabs-border-width)) }`
  (= `-1px`) เพื่อให้ border ล่างของ tab ที่ active **ทับ** เส้น `border-bottom` 1px ของ `.nav-tabs` เอง
  → เส้นใต้ 2px มี 1px ที่อยู่ **นอก padding box** ของ `.nav-tabs` โดยตั้งใจ
- รอบ "shared component บนจอแคบ" (2026-09-15) เพิ่ม `overflow-x: auto; overflow-y: hidden` ให้ `.nav-tabs`
  (กฎ "แถว tab ไม่ wrap ให้เลื่อนแนวนอนแทน") — **scroll container clip ทุกอย่างที่อยู่นอก padding box**
  → เส้นใต้เหลือ 1px จาก 2px ทุก tab ทุกหน้า และ 1px ที่เหลือไปวางทับเส้นเทาของ `.nav-tabs` พอดี
- ที่ "บาง tab หาย บาง tab ไม่หาย" เพราะ 1px ที่เหลือคร่อมขอบ half-pixel (`lineBottom` วัดได้ .75 / .5 / .63
  แล้วแต่ความสูงจริงของ modal/หน้าจอ) — ปัดเป็น pixel จริงแล้วบางกรณีจางจนกลืนกับเส้นเทา

วัดก่อนแก้: ทุกความกว้าง (430–1400) ทุก tab ทั้ง modal และ `#runDetailTabs` ได้ค่าเดียวกันหมด —
`outsidePx = 1.00`, `insidePx = 1.00`, `margin-bottom: -1px`, `scrollHeight(32) > clientHeight(31)`
และภาพจริงมีแถบส้มแค่ 1 แถว pixel ไม่ใช่ 2

## วิธีแก้ที่เลือก

ให้เส้น active อยู่ใน content box ของ `.nav-tabs` ทั้งหมด ไม่ต้องพึ่งการล้นอีก:

| | ก่อน | หลัง |
|---|---|---|
| เส้นเทาของแถว | `.nav-tabs { border-bottom: 1px solid var(--c-border) }` | `.nav-tabs { border-bottom: none; box-shadow: inset 0 -1px 0 var(--c-border) }` |
| เส้นใต้ tab | `.nav-link { border-bottom: 2px solid transparent; margin-bottom: -1px }` | เหมือนเดิม แต่ `margin-bottom: 0` (เขียนทับ Bootstrap ตรงๆ) |
| tab ที่ active | `border-color: transparent transparent var(--c-primary)` | `border-bottom-color: var(--c-primary)` |

inset shadow วาดบน padding box แต่อยู่ **ใต้** ลูกของมัน → border ส้ม 2px ของ tab ที่ active ทับเส้นเทาได้
เหมือนเดิมเป๊ะ ต่างกันแค่ไม่มีอะไรล้นออกนอกกล่องให้ scroll container clip อีก (`scrollHeight == clientHeight`)

`#runDetailTabs.nav-tabs { border-bottom-color: #dee2e6 }` (สีเฉพาะหน้า Payroll Detail) ย้ายมาเป็น
`box-shadow: inset 0 -1px 0 #dee2e6` กลไกเดียวกัน เพื่อให้หน้านั้นสีเส้นเท่าเดิมไม่เปลี่ยน

## ทางเลือกที่ไม่เลือก

- **`overflow-y: visible`** — ทำไม่ได้: ถ้าแกนหนึ่งไม่ใช่ `visible` อีกแกนจะถูกบังคับเป็น `auto` เสมอ (CSS spec)
- **คง border ของแถวไว้แล้วแค่ `margin-bottom: 0`** — ได้เส้นคู่ (ส้ม 2px + เทา 1px ใต้ลงไป) หน้าตาเปลี่ยน
- **`::after` ที่ `bottom: -1px`** — ล้นนอกกล่องเหมือนเดิม เจอ clip เหมือนเดิม
- **`!important`** — ไม่แก้ต้นเหตุ และผิดกฎของโปรเจกต์

## ผลข้างเคียงที่รู้แล้ว

`.nav-link:focus-visible` เป็น `box-shadow` ring 4px รอบปุ่ม — ring ด้านบน/ล่างยังโดน `overflow` ของแถว clip อยู่
(เป็นผลจาก `overflow-x: auto` ตัวเดียวกัน ไม่เกี่ยวกับการเปลี่ยนครั้งนี้) — อยู่ใน `BACKLOG.md`
