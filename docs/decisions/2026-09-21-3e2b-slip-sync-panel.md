# 3e-2b — ข้อมูลดิบจาก Origami เป็นแผงในสลิป, หน้า List เลิกโชว์ code ดิบ, ของค้าง 3e-1

**2026-09-21** · rules.md §2/§5/§5.2/§9/§15 · ต่อจาก [3e-2a](2026-09-21-3e2a-calc-badges.md)

**modal ที่สองหายไป** — `#rawSyncDataModal` (view + handler + `rawSyncDataEmployeeId` + key `raw_sync_data_title`) ไม่มีปุ่มเปิดตั้งแต่ 4b ถอดเมนู ⋮ · ตัวเลขที่กำลังตรวจกับข้อมูลที่ Origami ส่งมาต้องอยู่จอเดียวกัน → disclosure ใต้หัวการ์ด `.modal.show` จึงเท่ากับ 1 เสมอ (วัดทุก cell) · renderer 8 ตัวเดิม reuse ผ่าน `renderRawSyncDataModal(data, target)` ไม่ได้ copy สักบรรทัด

**เงื่อนไขต้องครบ 2 ข้อ** — `currentRun.sync_process_id` **และ** `row.data_source === 'sync'` · `rawSyncDataForEmployee()` คืน null ทั้งคู่ ไม่เข้าเงื่อนไขจึงไม่ render ปุ่มเลย ไม่ใช่ render แล้วค่อยขอโทษ

**reset ก่อน render เสมอ** — `resetRawSyncPanelRd()` เป็นบรรทัดแรกของ `renderBreakdownModal()` เพราะปุ่มถูกสร้างใหม่พร้อมการ์ด state จึงต้องล้างเทียบกับแถวที่กำลังจะมา ไม่ใช่แถวที่เพิ่งจากไป — s2 วัดจาก `payroll_code` จริงของ 2 คน

**ระยะคุมจาก margin ของแผงตัวเดียว** — แผงที่ `d-none` ไม่กินทั้งความสูงและ margin ระยะหัวการ์ด → callout จึงเป็น 16 เท่ากันทั้งเปิดและปิด (margin collapse) ใต้ callout ยัง `--sp-3` เดิม — s4 ถอด style ของแผงออกสดๆ แล้ววัดซ้ำ ไม่ hardcode

**slot ที่ `min-height` เตี้ยกว่าเนื้อหา 1 บรรทัดของตัวเอง = layout shift ตอนถูกเติมทีหลัง** — `.stat-footer` 24px < 8 + 19.5 → การ์ดโต 3.5px ตอน `updateSummaryCardsFromTable()` เติมบรรทัดล่าง ดัน tab bar และทุก pane ลง (คือสาเหตุจริงของ c13 `[16,19.5]` ที่เคยสรุปผิดว่าเทสเปราะ) — floor ต้อง derive จาก token ที่มันต้องครอบ ห้ามพิมพ์เลขคงที่

**กล่องใน modal ต้องพับตามความกว้างของ "แผง" ไม่ใช่ viewport** — `col-md-6` ที่ viewport 1400 ยังได้แผงแค่ 776px จึงวาง 2 ใบ/แถวทิ้งใบที่สามไว้ลำพัง · grid `auto-fit` + `minmax` ไม่ต้องมี breakpoint เลย — floor 220px เป็นค่า**วัด** ไม่ใช่เลขกลม: ลอง 200 เพื่อให้มีขั้น 2-up ที่ 768 แล้วแผง**สูงขึ้น** (859.3 vs 600.1) เพราะ section แคบลงจนบีบ field grid เหลือคอลัมน์เดียว

**`find_banner_run.php`** — c14/p10c เคย fallback เป็น token ที่พิมพ์ไว้ในไฟล์ · token ที่เลิกตรงกับของจริงวัดรอบผิดโดยยังเรียกตัวเองด้วยชื่อที่ถูก → ถาม DB ด้วยเงื่อนไข 4 ข้อเดียวกับที่ render path อ่าน, `none` = mock

**ที่ไม่ได้แก้** — ไอคอน `fa-code-merge` บน `#btnMergeIntoTarget` (§4, BACKLOG เดิม) · คอลัมน์ pill ถูก DataTables Responsive พับที่ 430 (s8 เปิด modal ผ่าน handler แทนการคลิก)
