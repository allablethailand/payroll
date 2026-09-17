# `#manageLinesModal` ความสูงกระโดดเมื่อสลับ tab → `.modal-tabbed` (2026-09-15)

> **ยกเลิก 2026-09-17: ทุก modal ที่มี tab เป็นฟอร์มสั้น ดู `remove-manage-lines-tabs`**

## อาการ

สลับ tab ใน `#manageLinesModal` แล้ว modal ทั้งใบขยับความสูงตามเนื้อในของ tab ที่เลือก
(modal centered ด้วย จึงเด้งทั้งบนและล่างพร้อมกัน)

## ตัวเลขก่อนแก้ (viewport 950px, theme light)

`.modal-body` เดิมมีแค่ `max-height: calc(100vh - 100px - 100px)` = **750px** ที่ viewport นี้
→ tab ที่เนื้อสั้นกว่านั้นกำหนดความสูงเองอิสระ

| tab | `.modal-content` @1400 | @768 | @430 |
|---|---|---|---|
| รายการจ่าย | 826.5 | 851.5 | 851.5 |
| ข้อมูลเข้างาน | 584 | 584 | 584 |
| ปรับตัวเลข | 851.5 | 851.5 | 851.5 |
| ปลายทางรายการหักประจำ | 344 | 344 | 359.75 |
| ภาษี & ประกันสังคม | 423.75 | 439.5 | 439.5 |
| **ช่วงที่กระโดด** | **507.5px** | **507.5px** | **491.75px** |

ส่วนประกอบคงที่ (ทุก tab ทุกความกว้าง): `.modal-header` = 47.5px, `.modal-footer` = 54px,
margin ของ `.modal-dialog` = 21px บน/ล่าง (≥ sm) และ 6px บน/ล่าง (< sm, Bootstrap `.modal-dialog`)
→ 100px + 100px ในสูตรเดิมคือค่าประมาณของ header+footer+margin รวมกัน ไม่ใช่ค่าที่วัดจากของจริง

แถว `.nav-tabs` เดิมเลื่อนหายไปกับเนื้อหา: เมื่อ scroll ถึงล่างสุด (เติมเนื้อ 20 แถว)
`navTop − bodyTop` = **−897 / −766 / −634** (430/768/1400) — คือออกนอกจอไปแล้ว

## วิธีแก้

class ใหม่ `.modal-tabbed` วางที่ตัว `.modal` (ไม่แตะ `.modal-body` กลาง ไม่มี `!important`):

- `.modal-tabbed .modal-body { height: calc(100vh - 200px); height: calc(100dvh - 200px) }`
  — **height ไม่ใช่ max-height** และค่าเท่ากับ max-height เดิมเป๊ะ จึงได้ความสูงเดียวกับที่ tab สูงสุด
  เคยทำให้เป็นอยู่แล้ว (ไม่ได้วัดจาก tab ใด ๆ) — `dvh` เขียนทับบรรทัดถัดมาเพื่อให้เบราว์เซอร์ที่รองรับ
  ใช้ค่าที่ไม่ขยับตามแถบ URL บนมือถือ ส่วนที่ไม่รองรับก็ยังได้ `vh`
- `.modal-tabbed .modal-body > .nav-tabs { position: sticky; top: 0; background: var(--c-bg) }`
  พร้อม `box-shadow` 2 ชั้น: ชั้นแรกคือเส้นล่างของแถว (เขียนซ้ำเพราะ declaration นี้ทับของ shared
  `.nav-tabs`) ชั้นที่สองลากพื้นหลังเดียวกันขึ้นไปคลุม padding ด้านบนของ `.modal-body`
  (`calc(-1 * var(--bs-modal-padding, 1rem))`) ซึ่ง `top: 0` ไม่คลุมให้ — ถ้าไม่มีจะเห็นเนื้อหาที่เลื่อน
  โผล่เป็นแถบบาง ๆ เหนือแถว tab (เห็นจริงในภาพก่อนใส่)
- `< sm`: `.modal-dialog` ได้ `modal-fullscreen-sm-down` แล้ว body เป็น `height: auto; max-height: none;
  flex: 1 1 auto; min-height: 0` → flex column ของ `.modal-content` (สูง 100% ของจอ) หักส่วน header/footer
  ให้เอง — **`max-height: none` จำเป็น** ไม่งั้น max-height 750px ของกฎกลางจะ cap ไว้ (เจอจริง: body ค้างที่
  750 ในขณะที่ content เป็น 950 เหลือช่องว่าง 98.5px)

## ตัวเลขหลังแก้ (viewport 950px, ทั้ง light และ dark)

| ความกว้าง | `.modal-content` ทั้ง 5 tab | `.modal-body` | ที่มา |
|---|---|---|---|
| 1400 | **851.5 ทุก tab** | 750 | 750 + 47.5 + 54 |
| 768 | **851.5 ทุก tab** | 750 | เท่ากัน |
| 430 | **950 ทุก tab** | 848.5 | 100dvh − 47.5 − 54 (fullscreen-sm-down) |

แถว tab หลัง scroll สุด: `navTop − bodyTop` = **+12** (= padding ของ body) ทุกความกว้าง/ทุกธีม คือยังติดอยู่
บนสุดจริง, `border-bottom` ของ tab ที่ active ยังเป็น `2px rgb(255,153,0)` ครบ

## modal อื่นที่มี tab — ตรวจแล้ว ไม่ใส่ `.modal-tabbed`

- `#statutoryRateModal` (`layout/modals.php`) — มี tab จริงและกระโดดเหมือนกัน แต่แค่ **473.5 → 499.1
  (25.6px)** และไม่มี footer เลย เนื้อในเป็นฟอร์มสั้น ถ้าบังคับสูง 750px จะกลายเป็น modal โล่งเกือบครึ่งใบ
  ซึ่งแย่กว่าการกระโดด 25px → บันทึกไว้ใน `BACKLOG.md` ให้ตัดสินตอนไล่หน้า Tax & Statutory
- `#ectFullscreenModal` / `#pstFullscreenModal` (canvas designer ทั้งสองตัว) — เป็น `.modal-fullscreen`
  อยู่แล้ว ความสูงคงที่เต็มจออยู่เดิม ไม่มีอาการนี้ และอยู่นอกขอบเขต design system (Tier B, light-only)
