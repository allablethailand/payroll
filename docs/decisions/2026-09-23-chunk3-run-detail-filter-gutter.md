# Payroll Detail — filter-bar มติ ข + gutter-zero generic selector (ก้อน 3, 2026-09-23, 3e-3b round B1)

**ก้อน 3** (payroll/detail — สำรวจแล้วแก้: มติ ข ถอด filter field ซ้ำ + gutter-zero generic selector).
ต่อจากก้อน 3 รอบ A (สำรวจ). เต็ม: อ่านรอบ A's own report (`tmp-chunk3-roundA.md`, ลบแล้ว) และรอบ B1
ครั้งแรก (หยุดที่ข้อ 1) ก่อนแก้ต่อในบริบทนี้ถ้าต้องการรายละเอียดกระบวนการตัดสินใจแบบเต็ม — ไฟล์นี้สรุปเฉพาะ
ผลลัพธ์ที่ทำจริง.

## มติ ข — ถอด #rdDepartmentFilter + #rdPaymentMethodFilter

ลบออกจริงจาก `#runDetailFilterBar` (payroll/detail.php) ทั้ง field markup และ DataTables search
predicate ที่ผูกอยู่ (`registerDepartmentSearchFilter()`/`registerPaymentMethodSearchFilter()`,
public/js/payroll/detail.js) — เหตุผล: rules.md §7 (เพิ่มรอบ 2026-09-23 3e-3b เดียวกัน, quote เต็ม
"แถบ filter เหนือตาราง ... มีไว้เฉพาะสิ่งที่คอลัมน์-header checklist ทำไม่ได้เท่านั้น ... ห้ามทำ select
ซ้ำไว้ข้างบนอีก") — `#tb_run_detail`'s เอง column-header Excel filter มีอยู่แล้วทั้ง department (column
index 3) และ payment_method_code (index 4) ผ่าน `initRunDetailTable()`'s `columnFilters` option.
`#rdSourceFilter` (Source: All/Sync/Manual) เหลืออยู่ตัวเดียว เพราะ data_source ไม่มีคอลัมน์ของตัวเองให้
column-filter จับ (คอลัมน์ถูกตัดออกไปตั้งแต่ 2026-09-11 Batch 3C item 7) — ตรงกับข้อยกเว้นที่ §7 อนุญาต
("สิ่งที่คอลัมน์-header checklist ทำไม่ได้")

`isBankishPaymentMethod()`/`isCashishPaymentMethod()`/`updatePaymentMethodSummary()` **ไม่ถูกแตะ** —
ยังใช้แสดง "Bank X · Cash Y" subtext ใต้การ์ดสรุปพนักงาน อิสระจาก filter field ที่ถูกลบ

grep ยืนยันหลังลบ: `rdDepartmentFilter`/`rdPaymentMethodFilter`/`registerDepartmentSearchFilter`/
`registerPaymentMethodSearchFilter` = 0 hits ทั้ง repo (นอกไฟล์นี้และไฟล์ decisions อื่น) — จุดที่เคยอ้างชื่อ
เดิมเป็นตัวอย่าง (`docs/design/components.php:924,940`, `docs/design/rules.md:990-991`,
`public/js/app.js:1408`) เปลี่ยนไปอ้าง `#employee_filter_department` (app/views/employee/list.php:134,
select2-remote จริง ชี้ `/api/department.get` แบบเดียวกัน) แทน ความหมายของกฎ/คำอธิบายเดิมไม่เปลี่ยน

lang key ทั้งหมดที่ 2 field เคยใช้ (`department`, `table_payment_method`, `filter_all`,
`table_payment_bank`, `table_payment_cash`) มี consumer อื่นอยู่แล้วทุกตัว (ยืนยันรอบ A §2.3) — ไม่มีการลบ
key ไหนออกจาก th.json/en.json

## ข้อค้นพบใหม่ระหว่างแก้: กรณี #runDetailFilterBar ว่างเปล่าทั้งแถบ

หลังลบ 2 field เหลือแค่ `#rdSourceFilter` ตัวเดียวในบาร์ — field นี้เองมี visibility gate ของตัวเองอยู่แล้ว
(`showDataSourceFilter`, detail.js) ที่ซ่อนมันเมื่อ run เป็น `run_purpose='incentive'` และ
`include_base_salary=0` — ยืนยันจริงด้วย SELECT ผ่านสคริปต์ชั่วคราวว่า run 29685 (จริงใน dev DB) เข้าเงื่อนไข
นี้ ถ้าไม่แก้อะไร ผู้ใช้ที่เปิด run แบบนี้จะเห็น filter-bar header (ป้าย "ตัวกรอง" + chevron) กางแล้วว่างเปล่า
ไม่มี field ให้เห็นเลย — แก้โดยสลับ target ของ `.toggleClass('d-none', ...)` เดิมจาก
`#rdDataSourceFilterWrap` เป็น `#runDetailFilterBar` ทั้งก้อน (diff บรรทัดเดียว, เงื่อนไขเดิมไม่เปลี่ยน)
พร้อมเพิ่ม `$('#rdSourceFilter').val('all').trigger('change')` ตอนซ่อน กัน stale value ค้างกรองข้อมูลแบบ
มองไม่เห็น (`detail.js`, ดู comment ที่จุดนั้นสำหรับรายละเอียดเต็ม)

`tableFilterBarFor()`/`clearAllTableFilters()` (app.js:2391/2397 เดิม) ยืนยันแล้วว่าทำงานถูกต้องแม้บาร์ถูก
ซ่อนด้วย `d-none` — ทั้งคู่ resolve ผ่าน jQuery selector + `.data()` ซึ่งไม่สนใจ CSS visibility, และ
`$bar.data('filterBarClear', ...)` (app.js:1591) ถูกตั้งไม่มีเงื่อนไขใน `initFilterBar()` เอง [ยืนยัน]

## เทส (o_history_dt.js c17)

สลับ selector จาก `#rdPaymentMethodFilter` (ลบแล้ว) เป็น `#rdSourceFilter` (field เดียวที่เหลือ) — ค่าตัวอย่าง
เปลี่ยนจาก 'bank'/'cash' เป็น 'sync'/'manual' (option values จริงของ field นี้) assertion อื่นทั้งหมด (chip
label/value/Clear cycle) อ่านจาก DOM สดเหมือนเดิม ไม่แก้ logic การเทส — ยืนยันว่า fixture run ที่ c17 ใช้
(`fixtureRunToken`, mksession.php:410 `run_purpose=>'payroll'`) ไม่มีทางเข้าเงื่อนไข
`showDataSourceFilter=false` (เพราะ `run_purpose!=='incentive'` เป็นจริงเสมอสำหรับ fixture run) —
`#rdSourceFilter` จึงมองเห็นได้เสมอในเซสชันปกติของเทสนี้ ไม่ต้องเปลี่ยน run

## gutter-zero → generic selector

`style.css` เดิม (ก่อนแก้): list 6 `#tb_X_wrapper .row` id ตรงๆ (`tb_run_detail`, `tb_run_reports`,
`tb_run_cash`, `tb_run_bank_account`, `tb_run_remittance`, `tb_run_audit_log`) — เปลี่ยนเป็น
`#runDetailTabsContent > .tab-pane .dt-container .row { --bs-gutter-x: 0; }` ตัวเดียว

เงื่อนไขที่ตรวจก่อนแก้ (รอบ B1 ข้อ 1.4/B):
1. ทุก `.tab-pane` (7 ตัว) เป็นลูกตรงของ `#runDetailTabsContent` — ยืนยันจาก indentation + จับคู่
   opening/closing tag จริง (payroll/detail.php: เปิด 318, ปิด 1331 — 7 tab-pane ทั้งหมดอยู่ระดับ indent
   เดียวกัน [4]→[8])
2. DataTable ที่อยู่ใต้ `.tab-pane` ใดๆ = 6 id เดิมพอดี — `#tb_report_history` (ตารางตัวที่ 7 ในหน้านี้,
   ใช้ `$().DataTable()` ตรงๆ ไม่ผ่าน `initSharedDataTable()`) อยู่ใน `#reportHistoryModal` ซึ่งเป็น
   **sibling** ของ tab-pane ทั้ง 7 ตัว (indent level เดียวกัน, ไม่ใช่ลูกของ tab-pane ไหนเลย) — child
   combinator `> .tab-pane` จึงกันตารางนี้ออกถูกต้อง เหมือนที่ 6-id list เดิมไม่เคยครอบคลุมมันอยู่แล้ว
   `#tb_join_employees` (ตารางตัวที่ 8) ยืนยันแยกว่าอยู่นอก `#runDetailTabsContent` ไปเลย (คนละ modal
   หลัง closing tag บรรทัด 1331)

ทั้ง 2 เงื่อนไขผ่าน → ดำเนินการแก้ตามที่เสนอ scope เฉพาะหน้านี้เท่านั้น (`#runDetailTabsContent` เป็น id
เฉพาะไฟล์เดียว, grep ยืนยัน)

## ท่อนที่ถูกตัด: .detail-section (ไม่แก้)

เงื่อนไข "diff 0 ใน light" (token ที่เลือกต้อง resolve เป็น `#fff`/`#eef0f2` ตรงตัวทั้งคู่) **ไม่ผ่าน**:

| token | light value | ตรงกับ `#eef0f2` (border) ไหม |
|---|---|---|
| `--c-border` (tokens.css:23) | `#E5E7EB` | ไม่ |
| `--c-border-strong` (tokens.css:24) | `#D1D5DB` | ไม่ |
| `--app-border` (style.css:5820, การ์ดหลัก `.card-surface` ใช้จริง, style.css:6901-6907) | `#f0f0f0` | ไม่ (ใกล้ที่สุดแต่ไม่ตรง) |

`--c-bg`(`#FFFFFF`)/`--app-surface-bg`(`#ffffff`) ตรงกับ `#fff` (background) ทั้งคู่ แต่ไม่มี token ไหนตรงกับ
`#eef0f2` (border) เลยสักตัว — `#eef0f2` เป็นค่า hardcode ที่ใช้ซ้ำ **30+ จุด** ทั่ว style.css (ไม่ใช่แค่
`.detail-section`) ไม่มี token กลางรองรับค่านี้อยู่จริง — **ไม่แก้ `.detail-section` ในรอบนี้** (ท่อน 2 ถูก
ตัดออก ไม่มี `t_detail_section_theme.js`) — ปัญหา dark-mode ของการ์ดนี้ (และ consumer อื่นๆ ที่ grep เจอ:
payroll/detail.php:332,381 · payroll/index.js:1261,1292 · employee/detail.php:2054,2289,2336,2376) ยังคง
เป็น BACKLOG ให้ตัดสินใจ token ใหม่ (อาจต้องเพิ่ม token กลางใหม่ หรือยอมรับ diff เล็กน้อยจาก `#eef0f2`
เดิม — ไม่ใช่การตัดสินใจของรอบนี้)

## ข้อค้นพบ: sticky thead แนวตั้งไม่มีจริงในแอป (จากรอบ A, ยังไม่แก้)

rules.md §7's เอง layout diagram เขียนไว้ว่า "**fix คอลัมน์แรก (พนักงาน) และหัวตาราง**"
(`docs/design/rules.md:1513`) — แต่ยืนยันจากโค้ดจริงแล้วว่า **ไม่เคย implement ส่วน "fix หัวตาราง"
(vertical `position:sticky; top:0`) ที่ไหนในแอปเลย** ทั้ง `#tb_run_detail` และหน้าต้นแบบ
(`/employees#employee-recheck-top-tab`) — `initStickyColumns()` (`public/js/sticky-table-columns.js`)
รองรับแค่ `left`/`right` (horizontal) เท่านั้น ไม่มี `top` option เลย สิ่งที่มีจริงคือกฎสี/font ของ thead
(`table.dataTable thead th { color; font-weight; font-size }`, style.css) ซึ่งไม่ใช่ position — ยังไม่ได้
แก้ในรอบนี้ (นอกขอบเขตก้อน 3 ตามที่ระบุไว้ตั้งแต่ต้น), ลง BACKLOG.md ต่อ

## (ก) 4 ตาราง — เลื่อนไม่ได้ทำในก้อนนี้

ขอบเขตก้อนนี้ (มติ ข + gutter-zero + .detail-section เท่านั้น) ไม่รวม (ก) — สเปก fixture ที่ต้องการ
(payroll_run_employee_bank_accounts/payroll_remittances ว่างทั้งฐาน dev DB, ต้องสร้าง fixture ≥6 แถวต่อ
ตารางถ้าจะทดสอบ >5 แถว) อยู่ในรอบ A §1.3 เต็ม — อ้างอิงที่นั่น ไม่ทำซ้ำในไฟล์นี้

## บั๊กจริงที่พบระหว่างวัด (round B2b/B2c) — truthy-string ใน `showDataSourceFilter`

รอบวัดแรก (B2b) ของ `s_run_detail_c3.js` fail จริง 4/27 จุด — สืบแล้วพบ 2 root cause แยกกัน ทั้งคู่**อยู่นอก
diff ของก้อนนี้**:

1. **`!!currentRun.include_base_salary` เป็น truthy-string bug ที่มีอยู่ก่อนรอบนี้แล้ว** — `api/payroll-run.get`
   ส่ง `include_base_salary` เป็น JSON string `"0"` (PDO คืนค่าทุกคอลัมน์เป็น string, `json_encode()` ไม่เคย
   cast กลับเป็น number) — ยืนยันตรงจาก `curl` จริงกับ `api/payroll-run.get?id=29685` → `include_base_salary`
   ชนิด PHP string `'0'` — ใน JS `!!"0"` = `true` เสมอ (string ไม่ว่างเป็น truthy) สวนทางกับความหมายที่ตั้งใจ
   (`include_base_salary=0` ควรทำให้ทั้งนิพจน์เป็น false เมื่อ incentive) — **แก้แล้ว** (`public/js/payroll/detail.js:2957`):
   `!!currentRun.include_base_salary` → `Number(currentRun.include_base_salary) === 1` (pattern เดียวกับ
   จุดอื่นที่ปลอดภัยอยู่แล้วในไฟล์เดียวกัน: `:1247`, `:7487`, `:7531`, และ `auto_recalculate` ที่ `:4403`) —
   truth table หลังแก้ (payroll/incentive+"1"/incentive+"0"/currentRun null) = true/true/false/true
   ตรงตามที่ต้องการทั้ง 4 กรณี [ยืนยัน: รันจริงผ่าน node]

   **grep เต็ม `include_base_salary` ทั้ง `public/js/`** (ย้ายมาจาก BACKLOG.md ข้อ 6 เดิม, ปิดรายการแล้ว —
   ลบออกจาก BACKLOG.md, เก็บบันทึกไว้ที่นี่ที่เดียว): 9 จุดที่อ้างถึง field นี้ — `app.js:5015/5080/5515`
   (เขียน default/dirty-tracking/toggle จาก `isIncentive` — ไม่ได้อ่านค่านี้เลย ไม่โดนบั๊ก), `app.js:5606`
   (WRITE payload ตอน submit ฟอร์ม ไม่ใช่อ่านค่าจาก server ไม่โดนบั๊ก), `payroll/index.js:94`
   (`Number(row.include_base_salary)===1`, ปลอดภัย), `payroll/detail.js:1247/7487/7531`
   (`Number(...)===1` ทั้ง 3 จุด, ปลอดภัย) — **มีจุดเดียวที่โดนบั๊กจริง: `payroll/detail.js:2957`**
   (`!!currentRun.include_base_salary`, แก้แล้วเป็น `Number(currentRun.include_base_salary)===1`) — ไม่มี
   จุดอื่นเหลือให้แก้ต่อ ปิดรายการนี้สมบูรณ์
2. **run 752's `state` เปลี่ยนจาก `locked` (snapshot รอบ A/B1) เป็น `draft` ระหว่าง dev DB ที่ใช้ร่วมกัน** —
   ทำให้ cash/bank_account/remittance tab เนื้อหาไม่โหลด (`RD_REPORT_ALLOWED_STATES`, detail.js:429 ไม่รวม
   `draft`) — ไม่ใช่บั๊ก เป็น design เดิม — **แก้สคริปต์** ให้ c2 ใช้ run 1014 แทน (state='locked' ยืนยันสดตอนนี้)

**บทเรียน**: cell ที่ทดสอบเนื้อหาซึ่ง gate ตาม `state` ของ run (เช่น cash/bank_account/remittance tab) **ต้อง
เลือก run โดยยืนยัน state สดก่อนทุกครั้ง ไม่ผูกกับ run id คงที่ที่เคย valid ในรอบก่อนหน้า** — dev DB เป็นข้อมูล
จริงที่เปลี่ยนได้ตลอดเวลา (เหมือน memory เดิม "Dev DB has real user data") — run ที่ผ่านเงื่อนไขวันนี้ อาจไม่ผ่าน
พรุ่งนี้

## ตัวเลขวัดจริง (round B2b redo, session สอง — วัดครบ 3 รอบเขียวติดกันทั้ง 3 สคริปต์)

**`s_run_detail_c3.js`**: **29 passed, 0 failed** × 3 รอบติด (ก่อนหน้านี้ในรอบ B2b ครั้งแรก fail 4/27 —
สาเหตุ 2 ข้อที่แก้แล้วในรอบ B2c ทำให้เขียวสนิทตอนนี้)
- c1: run 752 (showDataSourceFilter=true) ผ่านครบ 8 ข้อ · run 29685 (false) ผ่านครบ 8 ข้อ (bar
  hidden จริง, `#rdSourceFilter` reset เป็น `'all'` จริง, recordsDisplay===recordsTotal จริง — ทั้งหมด
  นี้พิสูจน์ว่า fix `Number(...)===1` ทำงานถูกจริงกับ run จริง) · **กรณี incentive+include_base_salary=1
  ยัง NOTE เท่านั้น (ไม่มี run จริงในฐานให้ทดสอบ) — ยืนยันฝั่ง logic ผ่าน node truth table ล้วนๆ
  (`payroll/incentive+"1"/incentive+"0"/currentRun null` = `true/true/false/true`) ไม่เคยผ่านเบราว์เซอร์
  จริงเลยสักครั้งสำหรับ branch นี้โดยเฉพาะ**
- c2: run 1014 (state='locked' จริง) — ทุก wrapper ที่วัดได้ (`tb_run_detail`/`tb_run_reports`/
  `tb_run_cash`/`tb_run_bank_account`/`tb_run_audit_log`, 5 ตัว) ผ่าน `--bs-gutter-x:0` ครบ 100% —
  **`tb_run_remittance_wrapper` ไม่เคยถูก assert เลยสักรอบ**: `run-remittance-tab`'s `<li>` เป็น
  `d-none` จริงทุกครั้งที่วัด (`remittance_count===0`, `updateRunDetailTabVisibility()`,
  `detail.js:1274-1283`) เพราะ `payroll_remittances` มี **0 แถวทั้งฐานข้อมูล ไม่ใช่แค่ run นี้** (ยืนยัน
  รอบ A) — แท็บนี้ซ่อนแน่นอนสำหรับทุก run ที่มีอยู่จริงตอนนี้ ไม่มีทางวัด gutter ของตารางนี้ได้เลยจนกว่าจะมี
  fixture ที่มี remittance แถวจริง (สเปกอยู่ในรอบ A §1.3)
- c3: payroll list page gutter = `1.5rem` (≠0, ไม่โดนกระทบ) · `#tb_report_history` วัดได้จริงบน run 1014
  = `1.5rem` (≠0, ไม่โดนกระทบเหมือนกัน) — มีรายงาน download history บน 1014 พอให้กดปุ่มได้จริง (ตรงข้ามกับ
  ที่คาดตอนเขียนสคริปต์ว่าจะต้อง NOTE)

**`o_history_dt.js`**: **138 passed, 0 failed** × 3 รอบติด — ตรงกับค่าอ้างอิงเป๊ะ ไม่มี cell ไหนเปลี่ยนเลย
รวม `c17` (assertion ทั้ง 10 ข้อผ่านหมด บน `#rdSourceFilter`/'Sync'/'แหล่งที่มา' — เทียบก่อนแก้ที่ใช้
`#rdPaymentMethodFilter`/'bank'/'Payment Method': ตัวเลขรวมไม่เปลี่ยนเพราะ `c17`
(`tests/ui/o_history_dt.js:1194-1247` หลังแก้) มีจำนวน assertion เท่าเดิมทุกจุด แค่สลับ selector/ค่าตัวอย่าง
ไม่ได้เพิ่ม/ลด assertion ใดๆ)

**`m3e1_tabs_shared.js`**: **240 passed, 0 failed** × 3 รอบติด — ตรงค่าอ้างอิงเป๊ะทุก cell:

| cell | ค่าอ้างอิง | วัดได้จริง |
|---|---|---|
| c1 | 38 | 38 |
| c2 | 19 | 19 |
| c3 | 30 | 30 |
| c4 | 8 | 8 |
| c5 | 1 | 1 |
| c6 | 7 | 7 |
| c7 | 6 | 6 |
| c8 | 6 | 6 |
| c9 | 17 | 17 |
| c10 | 13 | 13 |
| c11 | 5 | 5 |
| c12 | 5 | 5 |
| c13 | 58 | 58 |
| c14 | 10 | 10 |
| c15 | 17 | 17 (8 @1400 light + 9 @430 dark) |

ไม่มี cell ไหนเปลี่ยนตัวเลขจากค่าอ้างอิงเลย — สอดคล้องกับที่ diff ก้อนนี้ (มติ ข + gutter-zero + truthy-string
fix) ไม่แตะพื้นที่ที่ m3e1 ครอบคลุม (Employee/Reports/Cash/Bank Account/Remittance tab layout, banner,
stat card, modal) เลยสักจุด

## Cleanup

`php tests/ui/mksession.php --cleanup` → `fixture_still_present:false`, `run_still_present:false`,
`session_still_present:false`, ทุกตารางที่เกี่ยวข้อง (`line_overrides_left`/`line_override_history_left`/
`employee_exemptions_left`/`recurring_fixture_left`/`sync_process_left`/`sync_items_left`) = 0 —
`tests/ui/.last-session.json` ถูกลบแล้ว
