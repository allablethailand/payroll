<!-- สรุป initiative "dtlang" (รอบ A/A2/B) — แก้ refreshAllDataTablesLanguage() ยิง request ซ้ำ/ทิ้งเปล่า
     ตอนสลับภาษา บนตาราง serverSide — อ่านไฟล์นี้เมื่อต้องรู้ที่มา/เหตุผล/ประวัติการแก้ ไม่ใช่ตอนแก้โค้ดปกติ -->

## ทำไม

`tests/ui/o_history_dt.js`'s `c9()` (Payroll Run Detail → Action History tab, สลับภาษา th→en ระหว่างมี
column filter ค้าง) วัด `api/payroll-run.audit-log.list` request ได้ **3 ครั้ง** ต่อการสลับภาษา 1 ครั้ง
บนตาราง `#tb_run_audit_log` ตัวเดียว ที่มองเห็นอยู่ตลอด (Round B4, `docs/decisions/2026-09-24-audit-log-
serverside.md`) — ตั้งใจให้เหลือ **2**

## ต้นเหตุ (3 request ของ c9 ตามลำดับเวลาจริง)

`changeLanguage(lang)` (`public/js/app.js:3087-3153`): `currentLang = lang` ทันที → `await
loadLang(lang)` → `reloadAllTablesForLanguageChange()` → ...→ `refreshPayrollDetailLanguage()`
(เรียกตัวสุดท้าย)

1. **ก่อนสุด**: `refreshAllDataTablesLanguage()`'s เอง inner loop
   (`_refreshAllDataTablesLanguageInner()`, `app.js`) เรียก `table.draw(false)` กับ**ทุก** DataTable
   บนหน้าผ่าน `$.fn.dataTable.tables()` **โดยไม่เช็ค visible เลย** — รันอยู่ภายใน `await loadLang(lang)`
   (ผ่าน `loadLang()`→`applyLanguage()`→`refreshAllTables()`) ก่อน `changeLanguage()` จะเดินต่อ — ตาราง
   นี้เป็น `serverSide:true` ทำให้ `.draw()` = fetch จริงเสมอไม่ว่า visible หรือไม่
2. **รองลงมา**: `reloadAllTablesForLanguageChange()` (`app.js:424-465`) — กรอง
   `$.fn.dataTable.tables({visible:true})` แล้ว `.ajax.reload(null,false)` ตารางที่มี `ajax:` (serverSide
   เข้าเงื่อนไขนี้เสมอ) — ตารางนี้ visible อยู่ตลอดตอน c9 วัด จึงโดนเสมอ
3. **หลังสุด**: `refreshAuditLogTableLanguage()` (`public/js/payroll/detail.js:4387-...`, เรียกจาก
   `refreshPayrollDetailLanguage()` ซึ่งเป็นบรรทัดสุดท้ายของ `changeLanguage()`) → `clearColumnFilters
   (tb_run_audit_log)` — ยิง `.ajax.reload()` **เฉพาะเมื่อมี column filter ค้างอยู่จริง** (c9's เอง test
   ตั้ง filter ไว้ก่อน switch เสมอ จึงเข้าเงื่อนไขนี้ทุกครั้งที่วัด)

ทั้ง 3 กลไกเป็น**อิสระต่อกันโดยสถาปัตยกรรม** ไม่มีใครรู้ว่าอีก 2 ตัวเพิ่งยิงไปหรือกำลังจะยิง

## Invariant ที่การแก้พึ่ง (I1/I2, ตรวจแล้วในรอบ B ก่อนแก้)

- **I1**: ทุกครั้งที่ภาษาเปลี่ยนจริง (`currentLang`/`langData` เปลี่ยนค่า) จะมี
  `reloadAllTablesForLanguageChange()` ตามมาเสมอ — grep ทุก caller ของ `applyLanguage(` (ไม่ใช่แค่
  `refreshAllTables()`/`refreshAllDataTablesLanguage()`) พบ caller เพิ่มเติมที่ไม่เคยตรวจมาก่อน
  (`reports/index.js:269,290`, `setup/changelog.js:13,30`, `setup/setup-guide.js:34`,
  `setup/terms-and-conditions.js:59,80`, `setup/help-drawer.js:41`) — ทุกตัวเรียก `applyLanguage()`
  แบบไม่มี argument หลัง inject DOM สดเข้าไปใหม่ (re-sync `data-i18n` เฉยๆ) **ไม่มีตัวไหนเปลี่ยนภาษาจริง**
  จึงไม่ขัด I1 — แต่พบเพิ่มว่า `setup/help-drawer.js` โหลดทุกหน้า (footer.php) และรอ `langReady` ก่อนยิง
  `api/help.drawer-content` แล้วเรียก `applyLanguage()` ตอน response กลับมา — เท่ากับ
  `refreshAllDataTablesLanguage()` เคยรันซ้ำเป็นครั้งที่ 2 บนทุกหน้าโหลด (ไม่ใช่แค่ตอน `loadLang()`'s เอง
  page-load call) โดยที่ตอนนั้นตารางหลักของหน้า (เช่น `#tb_employee`) อาจสร้าง+visible ไปแล้ว — **ไม่ใช่
  I1 exception** (ภาษาไม่เปลี่ยน) แต่หมายความว่าการแก้รอบนี้ลดการยิง request ทิ้งเปล่าเพิ่มอีกจุดหนึ่งที่
  ไม่เคยรู้มาก่อนด้วย (ทุกหน้าโหลด ไม่ใช่แค่ตอนสลับภาษา)
- **I2**: ตาราง serverSide ทุกตัวที่ถูกสร้างก่อน `langReady` resolve ต้องได้ภาษาถูกตอน construction เอง
  ไม่ได้พึ่ง draw ของ `refreshAllDataTablesLanguage()` — ยืนยันครบทั้ง 11 ตัวใน inventory (รอบ A/A2):
  10/11 รอ `window.langReady` ก่อนสร้าง (หรือ lazy สร้างหลัง page load นานแล้ว) — **ข้อยกเว้นจริง 1 ตัว:
  `#tb_notification`** (`app/views/notification/index.php`) ไม่รอ `langReady` — แก้คู่กันในรอบนี้ (ดูข้างล่าง)

ไม่พบ I1/I2 exception ใหม่นอกเหนือจากที่รู้อยู่แล้ว → ดำเนินการแก้ได้

## การแก้

**`public/js/app.js`** — `_refreshAllDataTablesLanguageInner()` ข้าม `table.draw(false)` เฉพาะตารางที่
`settings.oFeatures.bServerSide` จริง **และ** อยู่ใน `$.fn.dataTable.tables({visible:true})` (นิยาม
visible เดียวกับ `reloadAllTablesForLanguageChange()`, สร้าง `Set` ครั้งเดียวก่อน loop) — แยกเป็น pure
function `dtlangShouldSkipVisibleServerSideDraw(settings, isVisible)` เพื่อ unit-test ได้โดยไม่ต้องพึ่ง
jQuery/DataTables (`tests/dtlang_visible_serverside_skip_test.js`, 8 assertions: client×{visible,hidden},
serverSide×{visible,hidden}, settings ไม่มี/ไม่ครบ `oFeatures`) — การ mutate `settings.oLanguage` และ
DOM patch (search/length/info) ยังทำเหมือนเดิมทุกตาราง ไม่มีเงื่อนไข — ตาราง client และตาราง serverSide
ที่ซ่อนอยู่ เดินเส้นทางเดิมทุกไบต์ (ยังไม่แก้เรื่อง H รอบนี้)

## เหตุที่ `#tb_notification` ต้องแก้คู่กัน

Guard ใหม่ทำให้ตาราง serverSide ที่ visible ไม่ถูก draw จาก `refreshAllDataTablesLanguage()` อีกต่อไป
โดยอาศัยว่า `reloadAllTablesForLanguageChange()` จะมา redraw แทนเสมอ — **แต่ invariant นี้ใช้ได้เฉพาะสาย
`changeLanguage()`** ไม่ใช้กับสาย page-load (ซึ่งไม่มี `reloadAllTablesForLanguageChange()` ตามมาเลย)
`#tb_notification` (`app/views/notification/index.php`) เป็นตารางเดียวที่สร้างโดยไม่รอ `window.langReady`
— แม้จะมี `language: getTableLang()` อยู่แล้วที่ `public/js/notifications.js:271` (**แก้ข้อสรุปของรอบ A2
ที่เข้าใจผิดว่าไม่มี — มีอยู่แล้วจริง**) แต่ถ้าเรียกก่อน `langData` โหลดเสร็จ ค่าที่ `getTableLang()` คืนคือ
English fallback ของ `langData.xxx || 'English...'` (ดู `getTableLang()`, `app.js:882-...`) ถูก capture
เป็น snapshot นิ่งตอน construction — เดิมพึ่ง page-load's เอง `refreshAllDataTablesLanguage()` draw() มา
แก้ให้ทีหลัง ตอนนี้ guard ใหม่ข้าม draw() นั้นไปแล้ว (เพราะตารางนี้ visible เสมอ ไม่มีแท็บซ่อน) → ต้องแก้
ให้รอ `langReady` ก่อนสร้าง (`app/views/notification/index.php`, เลียนแบบ
`public/js/employee/login-history.js:103-107`) เพื่อให้ `getTableLang()` อ่าน `langData` ที่โหลดเสร็จแล้ว
ตั้งแต่ construction จริง — ไม่ต้องแก้ `language:` เพิ่มเพราะมีอยู่แล้ว — dropdown แจ้งเตือนบน header
(`.nav-notif-item`/`notifItemHtml()`/`notifOpenDropdown()`) ไม่ถูกแตะเลย (คนละ table/คนละ endpoint
`api/notification.list` vs `api/notification.datatable`)

## ตัวเลข c9: 3 → 2 — วัดจริงแล้ว (รอบ C)

Assertion เปลี่ยนจาก `=== 3` เป็น `=== 2` ใน `tests/ui/o_history_dt.js`'s `c9()` — ยืนยันแล้วว่าเหลือ 2
request จริงจาก mechanism (2) `reloadAllTablesForLanguageChange()` + (3) `clearColumnFilters()`
(เพราะ c9's เอง test ตั้ง column filter ไว้ก่อนสลับภาษาเสมอ) — **ได้ 2 ครบ 7/7 รอบที่มี diagnostic log**
(session run 45453 ×1, run 45454 ×3 [c9b, รอบวินิจฉัย], run 45455 ×3 [full2, ลำดับเต็ม]) — แยกกลไกได้
ชัดเจนจาก payload จริงทุกรอบ: `seq:0` (draw:4) ยังมี `column_filters[audit_action][]` ติดมา (มาจาก
`reloadAllTablesForLanguageChange()`, ยิงก่อน filter ถูกเคลียร์) ส่วน `seq:1` (draw:5) ไม่มี filter เลย
(มาจาก `clearColumnFilters()`, ยิงหลัง filter ถูกเคลียร์แล้ว) — `iDraw` delta และ `dispatchedCount` (log
ใหม่ที่เพิ่มระหว่างสืบ, ดูหัวข้อถัดไป) ตรงกัน 2 ทั้ง 7 รอบ ไม่มี `requestfailed` เลยสักรอบ

**ความผิดปกติที่พบและยังไม่คลี่คลาย**: 3 รอบแรกสุดที่วัด (session run 45451, **ก่อน**เพิ่ม diagnostic
logging) ได้ **1** ทั้ง 3 รอบ ไม่ใช่ 2 — session นั้นถูกใช้ไปแล้ว ไม่สามารถย้อนกลับไปตรวจซ้ำได้อีก และ
diagnostic logging ที่เพิ่มมาทีหลัง**ไม่เคยจับอาการนี้ซ้ำได้เลยแม้แต่ครั้งเดียว**ในการวัดทั้งหมด 7 รอบถัดมา
(รวม `h_history_table.js` รันนำก่อนแบบเดียวกันทุกรอบ) — สรุปว่า **ยืนยัน c9=2 เป็นค่าถูกต้องแล้ว
(ยึดตามหลักฐาน 7/7 ล่าสุด), assertion คง `=== 2` ต่อไป และเก็บ diagnostic logging ไว้ถาวรใน
`tests/ui/o_history_dt.js`** เผื่ออาการ "1" กลับมาอีกในอนาคต — ดู `BACKLOG.md` สำหรับ log ที่ต้องดูถ้าเกิดซ้ำ

**Log วินิจฉัยที่เพิ่ม (เก็บไว้ถาวร ไม่ใช่ trace ชั่วคราว)**: `collectAuditListResponses()` เพิ่ม
`dispatchedCount` (ค่าสุดท้ายของ `seqCounter` — จำนวน request ที่ถูก dispatch จริงทั้งหมด ไม่ว่าจะได้
response หรือไม่) และ `failed` (จับ event `'requestfailed'` ของ URL เดียวกัน) — `c9()` เพิ่ม log
`iDraw`/`hasActiveColumnFilters` ก่อน/หลัง switch (ผ่าน `page.evaluate`) และ log
`column_filters[*]`/`columns[i][search][value]` ที่ไม่ว่างในตัว request ที่รอด (แยกกลไกจาก payload จริง)
— ทั้งหมดนี้ purely additive ไม่กระทบ caller เดิม (N1/N2 ที่อ่านแค่ `.list.length`)

**คำตอบเรื่อง "c9 มีเส้นทางข้ามการตั้ง column filter แบบเงียบๆ ไหม"**: **ไม่มี** —
เงื่อนไขเดียวที่ข้ามคือ `if (logs.length) {...} else { note(...) }`
(`tests/ui/o_history_dt.js:901-909`) ซึ่งมี `note()` แจ้งชัดเจน ไม่เงียบ และไม่เข้าเงื่อนไขนี้ในทั้ง 3 รอบที่
วัด (`PASS c9: column filter set before the switch really narrows (sanity)` ทุกรอบ) — ตรวจเพิ่มความ
เป็นไปได้อีกทาง (semantics "select-all → null" ของ `table-column-filter.js:376`, ที่ทำให้
`getColumnFilterValues()` ตัด key ทิ้งเงียบๆ แม้ `hasActiveColumnFilters()` ยังคืน `true`) แล้วยืนยันด้วย
ข้อมูลจริงของ run 1014 (`tests/ui/audit_log_ref_cli.php 1014`) ว่า **ไม่เข้าเงื่อนไขนี้** — `wantAction =
logs[0].action` ("create") ปรากฏแค่ 1 จาก 18 แถว ไม่ใช่ค่าที่ทุกแถวใช้ร่วมกัน จึงไม่ใช่ "เลือกครบทุกตัวเลือก
โดยไม่ตั้งใจ" — ไม่ต้องแก้/เพิ่ม check ใหม่ (ไม่ต้องรัน `h_history_table`→`o_history_dt`×3 ใหม่)

**ชี้แจงเพิ่ม (2026-09-25, ตรวจซ้ำหลังมีคำถามว่า run 1014 ผูกกับ session หรือไม่)**: `c9()` ใช้
`RUN_1014_ID`/`RUN_1014_TOKEN` (ค่าคงที่ `= 1014`, `tests/ui/o_history_dt.js:119`) **เสมอ ไม่ว่า session
ไหน** (`gotoRun(ctx, RUN_1014_TOKEN, 'th')` ที่ `:890`, `auditLogRef(RUN_1014_ID)` ที่ `:892`) — ไม่ใช่
`fixtureRunToken`/run_id ของ session (`--with-recurring --with-calc-errors` แต่ละรอบได้ run_id ใหม่ เช่น
45451/45453/45454/45455 แต่ไม่เกี่ยวกับ run 1014 เลย) — ดังนั้นการตรวจข้างต้นด้วยข้อมูลจริงของ run 1014
**ใช้ได้กับทุกรอบ/ทุก session ที่วัดมา ไม่ใช่เฉพาะรอบเดียว** run 1014 เป็น "real dev run, locked"
(comment เดิม, `:119`) ที่ใช้ร่วมกันข้ามหลายรอบตั้งแต่ 2026-09-22 — ไม่มี write path ที่เปิดให้เพิ่ม audit
row ใหม่ได้จากภายในสคริปต์นี้เอง (`state=locked` กันการ mutate ผ่าน lifecycle ปกติอยู่แล้ว, cell ทั้งหมด
ของไฟล์นี้เป็น read-only ตาม docblock) และจำนวนแถว (`recordsTotal: 18`) วัดได้ตรงกันทุกรอบที่ผ่านมา —
ไม่มีหลักฐานว่าข้อมูลของ run 1014 เปลี่ยนไปเลย [ยืนยันจากโค้ด + ยืนยันจากการรัน (recordsTotal คงที่)]

## รอบวัด (รอบ C) — ลำดับเต็ม session ปกติ 2 ชุด + `--with-sync` 1 ชุด, ผลจริง

**session ปกติ** (`--with-recurring --with-calc-errors`, 16 script + `o_history_dt`×3) รันลำดับเต็ม
**2 ชุด**: รอบวัดแรก (run 45451 — ชุดที่พบ c9=1 3/3 รอบ) และ `full2` (run 45455 — ยืนยัน c9=2 3/3 รอบ) —
**session `--with-sync`** (`n_missing_pull`→`p_sync_not_participant`→`m3e1`) รัน **1 ชุด** (run 45452)
— **ทุก script ตรงค่าคาดทุกตัวทั้ง 3 ชุดรวมกัน รวม fail ที่รู้จักทั้ง 2 รายการ**
(`m3e2a`'s `p10c` 147/1, `k4a2`'s inset ±1px 300/8) — **ไม่มีตัวเลขไหนเปลี่ยนจากการที่ V-fix ตัด request
ตอนสลับภาษา/ตอนโหลดหน้าเลย** (ตามที่คาดไว้ — การตัด request ที่ไม่จำเป็นออกไม่ควรกระทบตัวเลข assertion
อื่นใดที่ไม่ได้นับ request ตรงๆ) — เจอ 1 เหตุการณ์ไม่เกี่ยวกัน: `o_history_dt`'s `c4` เจอ
`page.goto` timeout (30s, รอ `networkidle`) 1 ครั้งใน `full2` (run 45455) รอบ 2 — network/server-load
timeout ทั่วไป เกิดก่อนถึง c9 เสมอ ไม่เกี่ยวกับ column filter/language switch เลย รันซ้ำผ่านปกติทันที —
บันทึกเป็น "พบครั้งเดียว" ใน `BACKLOG.md` ไม่ใช่ known flake

## สิ่งที่ไม่ทำรอบนี้

- **เรื่อง H** (opt-in ตารางซ่อน) — ออกแบบไว้แล้ว (รอบ A2) ยังไม่แก้จริง — ดู `BACKLOG.md`
- `structureTables` loop ใน `refreshAllTables()` — instance กำพร้าสะสม, ไม่เช็ค visible — ดู `BACKLOG.md`
- N1 (2 requests ตอนคลิกแท็บ History ครั้งแรก, เทียบกับ `#tb_join_employees`) — แยกก้อนของตัวเอง
- `reloadAllTablesForLanguageChange()` / `refreshAuditLogTableLanguage()` — ไม่แตะทั้งคู่ตามขอบเขตรอบนี้
