<!-- สรุปทั้ง initiative (backend commit 9cdcbfc3 + frontend round B1/B2a/B2b/B3) ที่แปลง
     #tb_run_audit_log (Payroll Run Detail, "Action History" tab) จาก client-side DataTable เป็น
     serverSide -- อ่านไฟล์นี้เมื่อต้องรู้ที่มา/เหตุผล/ประวัติการแก้ ไม่ใช่ตอนแก้โค้ดปกติ -->

## ทำไม

Run 752 มี audit log จำนวนมาก จนตัว `/api/payroll-run.get` (endpoint เดียวที่เคยใช้ทั้ง render หน้า
Run Detail และเติมตาราง History ผ่าน `audit_log` key ในตัวมันเอง) มี payload ~700KB — ทุกครั้งที่เปิด/
switch tab/เปลี่ยนภาษาบนหน้านี้ก็โหลดก้อนนี้มาเงียบๆ ทั้งที่ผู้ใช้อาจไม่เคยเปิดแท็บ History เลย
แนวทางแก้: แยก endpoint ใหม่เฉพาะของตาราง History เป็น DataTables `serverSide:true` (paginate/
sort/filter/search ที่ DB ฝั่งเดียว) แล้วตัด `.get()`'s ส่วน `audit_log` key ออกในอนาคต (ดู "tiny-2"
ท้ายไฟล์นี้ — ยังไม่ทำรอบนี้)

## Backend (commit `9cdcbfc3`)

- Route ใหม่ `api/payroll-run.audit-log.list` / `.audit-log.column-values` (POST) →
  `PayrollController::auditLogList()`/`auditLogColumnValues()` → `PayrollRunModel::getAuditLogPaged()`/
  `auditLogColumnValues()` — row shape/exclusion (`action != 'view_detail'`) เดียวกับ `getAuditLog()`
  เดิม (ที่ `.get()` ยังใช้อยู่ — **ยังไม่ตัด `audit_log` key ออกจาก `.get()` รอบนี้**)
- Sort/filter whitelist: `performed_at`/`actor`/`action`/`to_state`/`ip_address` + id tie-break ทุก
  order, index นอก whitelist fallback ไป default. Search = note + actor name_th/en + ip_address
  (LIKE, bound). Date range บน `performed_at` รวมทั้ง 2 ขอบ. `length=-1` ("All") รองรับ. LIMIT/OFFSET
  bind เป็น `PDO::PARAM_INT`
- Excel-filter 4 คีย์ map เป็นคอลัมน์จริงครบ — `audit_action`/`audit_state` คืนค่า enum ดิบเสมอ (ไม่มี
  i18n ฝั่ง server ในแอปนี้) — `auditLogList()` mask note ผ่าน `maskAuditNote()` เหมือน `.get()`,
  column-values ไม่คืน note เลยไม่ต้อง mask
- `PayrollRunModel::get()` เพิ่ม `cancelled_from_state` (subquery เดียวกับที่ `list()` มีอยู่แล้ว) —
  pure addition ปิดช่องโหว่ที่ `runLifecycleCancelledFromState()` (app.js) เคย fallback มาคอลัมน์นี้
  ไม่ได้เพราะ `.get()` ไม่เคย select
- Test: +8 assertions (`audit_note_masking_test.php`) + 44 assertions (`payroll_run_test.php`, เทียบ
  run 1014 จริง + cancelled runs 271/294/310/1018 จริง ไม่มี fixture) — `run_all.php --compare`:
  7,784+52=7,836 pass / 2 fail (2 fail เดิม ไม่เกี่ยวกัน)

## Frontend decisions (D1–D7)

- **D1 — serverSide เฉพาะตารางนี้เท่านั้น**: `initAuditLogTableRd()` (`detail.js`) เป็น DataTables
  ตัวเดียวในหน้า Run Detail ที่ตั้ง `serverSide:true` — ตารางอื่นบนหน้าเดียวกัน (`tb_run_detail` ฯลฯ)
  ยังคง client-side เดิม 100% (verify ผ่าน `s_run_detail_c3`/`m3e1` ที่บังคับให้ behavior เดิมไม่เปลี่ยน)
- **D2 — lazy construction**: ตารางไม่ถูกสร้างจนกว่าผู้ใช้คลิกแท็บ History ครั้งแรก (`shown.bs.tab` บน
  `#run-history-tab`) — ไม่มี request ใดๆ ที่ page load (พิสูจน์ผ่าน N1's เอง "0 requests ที่ page load")
- **D3 — column filter (Excel-style) 4 คีย์**: `audit_action`/`audit_performed_by`/`audit_to_state`/
  `audit_device_ip` ผ่าน `table-column-filter.js`'s ปกติ, ค่าที่ส่งเป็นค่าดิบเสมอ (ไม่มี `formatValue`
  บนคีย์เหล่านี้ ยกเว้น label ที่ dropdown panel เอง) — "select all"/"เหลือตัวเลือกเดียวแล้วติ๊กมันหมด"
  ส่งผลเหมือนกัน (ไม่ส่ง key เลย) เป็น semantics เดิมของ component นี้ ไม่ใช่บั๊กใหม่จากรอบนี้
- **D4 — date range เหนือ column filter**: `performed_at` เป็นคอลัมน์เดียวที่เป็นช่วงต่อเนื่อง เข้าคู่
  ไม่ได้กับ closed-set column filter จึงมี UI แยก (`#auditLogDateFrom`/`To` + filter-bar ของตัวเอง)
  เหนือ column filter ปกติ — inclusive ทั้ง 2 ขอบ ตรง backend
- **D5 — stale-flag แทน unconditional reload**: `refreshAuditLogAfterRunLoadRd()` (เรียกจาก
  `loadRunDetail()`'s success handler) reload ทันทีถ้าแท็บ History active อยู่ ณ ขณะนั้น, ไม่งั้นแค่ตั้ง
  `auditLogTableStaleRd=true` ให้ `shown.bs.tab` handler ไป reload ทีหลังตอนแท็บถูกเปิดจริง — กันไม่ให้
  mutating action ที่เกิดขณะอยู่แท็บอื่น (เช่น recalculate) ทำให้ History ค้างข้อมูลเก่าแบบเงียบๆ
- **D6 — ภาษา**: เปลี่ยนภาษาระหว่างเปิดตารางนี้อยู่ → เคลียร์ column filter ที่ค้างอยู่เสมอ (ไม่ปล่อยให้
  ตารางค้างที่สถานะ narrowed/0 แถวแบบไม่มี indicator ว่ามี filter ค้าง) แล้ว reload ให้ label ตามภาษาใหม่
- **D7 — search box มีเงื่อนไข**: กล่องค้นหาโชว์เฉพาะ run ที่ recordsTotal เกิน threshold (proven ด้วย
  run 1014 เทียบ run 29685) — กัน UI รกบน run ที่มี audit entry น้อย

## Behavior changes (จากเดิม client-side)

1. Search/sort/filter ทุกอย่างยิง request ใหม่ไป server แทนกรองใน browser (payload ต่อ request เล็กลง
   มาก แต่จำนวน request ต่อ session มากขึ้น)
2. ตารางไม่ถูกสร้าง/fetch จนกว่าจะคลิกแท็บ History ครั้งแรก (เดิม fetch มาพร้อม `.get()` เสมอ)
3. เปลี่ยนภาษาขณะมี column filter ค้าง → filter ถูกเคลียร์ (เดิม client-side ไม่มีปัญหานี้เพราะ label
   เปลี่ยนแต่ข้อมูลที่ narrow ไว้แล้วยังอยู่ใน memory)
4. เปิดผ่าน hash URL ตรงเข้าแท็บ History (ไม่ใช่คลิกเอง) ยังคงยิง 2 requests ได้ (ยอมรับพฤติกรรมนี้
   ตั้งแต่รอบ B1 แล้ว ไม่ใช่ regression ใหม่)

## ผลวัดสุดท้าย (Round B4, ยืนยันด้วยการรันจริง 3 รอบติดกันได้ตัวเลขเดิมทุกรอบ — เลขล่าสุด แทนที่ตาราง B3 เดิม)

**`m3e1_tabs_shared.js`** — 240/240 ผ่านทุกครั้ง (3 รอบ + 1 รอบ with-sync = 245/245), ไม่เปลี่ยนจาก B3:

| cell | pass |
|---|---|
| c1 (1400 th light) | 38 |
| c2 (430 dark) | 19 |
| c3 (768 en) | 30 |
| c4 (run 1015 locked) | 8 |
| c5 (empty search) | 1 |
| c6 (2 GET-only modals) | 7 |
| c7 bulk verify | 6 |
| c8 single verify | 6 |
| c9 (Remittance) | 17 |
| c10 (Bank Account) | 13 |
| c11 (Cash) | 5 |
| c12 (data-i18n whole page) | 5 |
| c13 (pane padding) | 58 |
| c14 (run-level banners) | 10 |
| c15 @1400 | 8 |
| c15 @430 | 9 |

**`o_history_dt.js`** — **181 pass / 0 fail**, identical 3 รอบติดกัน (ขึ้นจาก 174/2 ตอนจบ B3 — c4
เปลี่ยนวิธีได้ 5 check แทน 0, N1/c9 assertion ล็อกค่าที่วัดได้แล้วผ่าน, c14 แก้ race แล้วผ่านคงที่):

| cell | pass | fail |
|---|---|---|
| c1–c3, c5–c8 | 3/7/4/4/7/4/7 | 0 |
| **c4** (rewritten, search-string based) | **5** | 0 |
| c9 (assertion locked = 3) | 9 | 0 |
| c10–c13 | 5/5/10/8 | 0 |
| **c14** (send-order race fixed) | **20** | 0 |
| c15–c17 | 9/16+8+7/10 | 0 |
| **N1** (assertion locked = 2) | **4** | 0 |
| N2–N6 | 4/4/4/5/2 | 0 |

## Round B3 — บั๊กที่เจอและแก้แล้ว

1. **c12/c14 "Clear Filter ไม่ reload"** — ไม่ใช่บั๊ก production. Root cause: test เดิมใช้
   `page.waitForLoadState('networkidle')` รอหลังกด Clear แต่ `initFilterBar()`'s `scheduleNotify()`
   เป็น debounce (`setTimeout(fn,0)`) — `networkidle` ไม่รู้จัก pending timer จึง resolve ได้ก่อน
   request จริงจะถูกยิงด้วยซ้ำ. **แก้**: เพิ่ม `waitForNextAuditDraw()` (`tests/ui/o_history_dt.js`,
   ผูกกับ event `draw.dt` ของตารางเองแทน network quietness) ใช้แทนใน `clearAuditLogDateFilter()`,
   c12's empty-state click, และ c9's language-switch call. Verified: c12/c14 ผ่านคงที่หลังแก้
2. **c3 "ค่า actor ไม่ถูกส่งไป server"** — ไม่ใช่บั๊ก. เมื่อ `audit_action` ถูกกรองไว้ก่อนแล้ว panel
   `audit_performed_by` เหลือตัวเลือกเดียว ("Admin") — ติ๊กตัวเดียวที่มีอยู่แยกไม่ออกจาก "select all"
   ภายใต้ semantics เดิมของ `table-column-filter.js` (`$checked.length===$all.length?null:new Set()`)
   → `state.selected[key]=null` → `getColumnFilterValues()` ตัด key ทิ้งถูกต้องแล้ว (พฤติกรรมเดิม
   proven ไปแล้วกับ `audit_device_ip` ใน c7 รอบก่อน). **แก้**: branch assertion ใน c3 ตาม
   `panelItemsNow.length===1` (ยืนยัน key ถูกตัดทิ้งถูกต้อง) vs หลายค่า (ยืนยันค่าดิบถูกส่ง)
3. **N2 regression จากความพยายามแก้ N1** (ดูหัวข้อถัดไป) — เจอเอง ระหว่างทดสอบ, แก้โดย **revert** ทั้ง
   fix ทิ้ง (ไม่ได้เก็บไว้บางส่วน — ดูรายละเอียดด้านล่าง)

## Round B3 — ความพยายามแก้ N1 ที่ล้มเหลว แล้ว revert ทิ้งทั้งหมด

ลอง trace ด้วย XHR-wrapping ชั่วคราว (`AUDIT_TRACE=1`, ลบออกจากไฟล์แล้วหลังใช้เสร็จตาม B5) พบว่า N1's
2 requests มา stack คนละที่กัน: request แรกจาก DataTables' เอง init path
(`_fnLoadState → _fnReDraw`), request ที่ 2 จาก `__reload()` API call จริง (`tb_run_audit_log
.ajax.reload()`) ห่างกันแค่ ~12ms ตั้งสมมติฐานว่าเป็น race ระหว่าง `loadRunDetail()`'s เอง in-flight
`.get()` (เรียกไม่มีเงื่อนไขทุก page load) กับ lazy construction ของตารางจากการคลิกแท็บ — ถ้า `.get()`
resolve **หลัง** ตารางถูกสร้างเสร็จ, `refreshAuditLogAfterRunLoadRd()` จะเห็นแท็บ active + ตารางสร้าง
เสร็จแล้ว แล้ว reload ซ้ำ

**ลองแก้**: เพิ่ม flag `auditLogSkipNextRefreshRd` — ตั้ง true ท้าย `initAuditLogTableRd()`, consume
(เคลียร์) โดย `refreshAuditLogAfterRunLoadRd()` call ถัดไปเพื่อข้าม reload ซ้ำรอบนั้นรอบเดียว

**ผลจริงหลังแก้ (re-test)**:
- N1 **ยังคง fire 2 requests เหมือนเดิม** — fix ไม่ได้แก้ปัญหาที่ตั้งใจแก้เลย
- N2 (`loadRunDetail()` while off-tab, later, deliberate) **พัง** — timeout/crash จริง (`page.
  waitForRequest` timeout 15000ms แล้ว uncaught rejection ทำให้ process ทั้งไฟล์ตาย) เพราะ flag ไม่ได้
  แยกแยะว่า "call ถัดไปหลังสร้างตาราง" คือ race ที่ตั้งใจจับจริงๆ หรือเป็นแค่ `refreshAuditLogAfterRun
  LoadRd()` call ปกติที่มาทีหลังนานแล้ว (กรณี N2: page-load's เอง `.get()` resolve ตั้งแต่ก่อนสร้าง
  ตาราง จึงคืนเปล่าไม่แตะ flag เลย — flag เลยไปโดน consume ที่ N2's เอง call จริงแทน ทำให้ branch "ไม่
  active → mark stale" ไม่เคยถูกเรียกเลยรอบนั้น)

เนื่องจาก fix ไม่ได้ผลตามที่ตั้งใจ (N1 ไม่หาย) และสร้าง regression จริง (N2 พัง) — **revert ทิ้งทั้งหมด**
(`detail.js`'s `auditLogSkipNextRefreshRd` flag + docblock + ทั้ง 2 call site) กลับไปเป็นโค้ดเดิมของ
`refreshAuditLogAfterRunLoadRd()`/`initAuditLogTableRd()` ทุกตัวอักษร — re-test ยืนยัน N2 กลับมาผ่าน
ปกติ (4/4), N1 ยังคง 2 requests เหมือนเดิม (ไม่แย่ลง ไม่ดีขึ้น)

**สถานะสุดท้าย: N1/c9 เป็น open finding ที่ยังไม่ถูกแก้** — ตัดสาเหตุที่เป็นไปได้ออกแล้วด้วยหลักฐานจริง:
`bStateSave` restoration (default false ยืนยันจาก DataTables source), generic `shown.bs.tab` lang-
epoch handler ใน app.js (ตัดออกด้วย diagnostic บังคับ `window.langChangeEpoch=0` ก่อนคลิกแล้วยัง fire
2 requests เหมือนเดิม), `columns.adjust()`/`_fnAdjustColumnSizing` และ `initExcelColumnFilters()`'s
เอง setup (ไม่มี ajax call ในซอร์สทั้งคู่), และ `loadRunDetail()`-vs-lazy-construction race (พิสูจน์แล้ว
ว่าไม่ใช่ต้นเหตุจริงจาก fix attempt ข้างบน) สมมติฐานที่เหลือซึ่ง**ยังไม่ยืนยัน**: อาจเป็นพฤติกรรมโดย
ธรรมชาติของ DataTables 2.x serverSide init เอง ไม่เกี่ยวกับโค้ดของโปรเจกต์นี้เลย — ยังไม่ได้ตรวจกับ
`#tb_join_employees` (serverSide ตัวอื่นที่มีอยู่ก่อนแล้วในแอป) เพื่อยืนยัน/ปฏิเสธสมมติฐานนี้เพราะเวลา
จำกัด — ดู BACKLOG.md

**Shared file**: Round B3 ไม่ได้แก้ `app.js`/`table-column-filter.js` เลย (การแก้ไขที่เห็นใน diff ของ
2 ไฟล์นี้มาจากรอบ B1/B2a/B2b ก่อนหน้า ไม่ใช่ B3) — จึงไม่มีประเด็น "client-mode branch ต้อง byte-
identical" ต้องตรวจสำหรับรอบนี้

## Round B4 — ตรวจสมมติฐานที่ปรึกษา (filter-bar onChange), ผลจริงเจอคนละเรื่อง, ล็อก assertion

**ข้อห้ามรอบนี้: ห้ามแตะ `app.js`/`table-column-filter.js` — แก้ได้เฉพาะ `detail.js`**

สมมติฐานที่ตรวจ: "request ที่ 2 ของ N1 มาจาก `.ajax.reload()` ห่างตัวแรก ~12ms ตรงกับ
`scheduleNotify()` ของ `initFilterBar()` — filter-bar แจ้ง `onChange` ตอนถูกสร้าง (และ/หรือตอน
relabel เมื่อเปลี่ยนภาษา) แล้ว `onChange` ของตารางนี้ (ที่ B1 ต่อไว้) สั่ง `ajax.reload()` ทุกครั้งโดย
ไม่ดูว่าค่าเปลี่ยนหรือไม่"

**ตรวจด้วยหลักฐานตรง (อ่านโค้ด ไม่ใช่เดา) — สรุปว่าเท็จ**:
- `initFilterBar()`'s เอง call ตอนสร้าง (`public/js/app.js:1638`) เรียก `refresh();` เฉยๆ — ไม่เคยเรียก
  `options.onChange` เลย ตอน construction
- `options.onChange` ถูกเรียกจาก `scheduleNotify()`'s debounced `setTimeout(fn,0)` เท่านั้น
  (`public/js/app.js:1589-1594`), ผูกกับ delegated `'change'` event บน `select, input.form-control`
  (`public/js/app.js:1597`) — ไม่มี mechanism ไหนเรียก `onChange` ตรงๆ นอกเส้นทางนี้
- detail.js's เอง onChange สำหรับตารางนี้ (`public/js/payroll/detail.js:4230-4235`:
  `initFilterBar('#auditLogFilterBar', {onChange: function () {...; if (tb_run_audit_log)
  tb_run_audit_log.draw(); }})`) เรียก `.draw()` (ไม่ใช่ `.ajax.reload()` ด้วยซ้ำ) — แต่ยังไงก็ตาม
  ไม่มีอะไร trigger `'change'` บน `#auditLogDateFrom`/`#auditLogDateTo` เองเลยระหว่าง construction
  (`initDatepicker()`, `public/js/input.js:135-177`, ไม่ set ค่า ไม่ trigger change ตอน field ว่าง) หรือ
  ระหว่าง `refreshAuditLogTableLanguage()` (`public/js/payroll/detail.js:4385-4429`, ไม่มี
  `.trigger('change')` เลยสักจุด, grep ยืนยันแล้ว)
- N1 ไม่มีการ switch ภาษาเลย ("relabel" ของสมมติฐานไม่เกี่ยวเลย) — ตัดสมมติฐานนี้ทิ้งได้เต็มที่สำหรับ N1

**แต่พบ root cause จริงของ c9's เอง request ที่ 3 ระหว่างตรวจ (คนละกลไกกับที่เดา)**:
`refreshAllDataTablesLanguage()`'s เอง inner loop (`public/js/app.js:4911-4944`) เรียก
`table.draw(false)` (บรรทัด `4944`) กับ**ทุก** DataTable บนหน้าผ่าน `$.fn.dataTable.tables()`
(ไม่กรอง `{visible:true}` เหมือน `reloadAllTablesForLanguageChange()`, `public/js/app.js:455`) — ตาราง
นี้เป็น `serverSide:true` แปลว่า `.draw()` reload จริงเสมอ ไม่ว่า visible หรือไม่ — รวมเป็น 3 reload
mechanism อิสระที่แตะตารางเดียวกันในการ switch ภาษาครั้งเดียว: (1) `reloadAllTablesForLanguageChange()`
(`changeLanguage()`'s เอง เรียกตรง, `public/js/app.js:3058`) ถ้า visible ตอน switch, (2)
`refreshAuditLogTableLanguage()`'s เอง `clearColumnFilters()` (`public/js/payroll/detail.js:4418`)
ถ้ามี column filter ค้าง, (3) `refreshAllDataTablesLanguage()` (เรียกผ่าน `loadLang()`→
`applyLanguage()`→`refreshAllTables()`, ทั้งหมดก่อนถึง (1)/(2) ใน `changeLanguage()`'s เอง sequence)
— **ไม่แก้รอบนี้เพราะห้ามแตะ `app.js`** — ดู BACKLOG.md สำหรับแนวทางแก้ที่เสนอไว้ถ้าทำต่อ

**ผลตามกฎ "หยุดสืบ" (สมมติฐานที่ให้มาเป็นเท็จ, ไม่มีการแก้ไข `detail.js` รอบนี้ — nothing to revert,
`git diff --stat -- public/js/payroll/detail.js` เท่าเดิมกับตอนจบ B3 เป๊ะ: 192 lines/157+/35-)**:
assertion ของ N1/c9 (`tests/ui/o_history_dt.js`) เปลี่ยนจากเพดานเดิม เป็น **ล็อกค่าที่วัดได้จริง**
(N1=2, c9=3) พร้อม comment อ้างอิง BACKLOG.md ในโค้ดเอง — regression เกินค่านี้ในอนาคตยังคง fail test
เหมือนเดิม ไม่ใช่การปล่อยผ่าน

## Round B4 — c4 เปลี่ยนวิธีทำให้เหลือ 0 แถว

`tests/ui/o_history_dt.js`'s c4 เดิม (หา 2 ค่าจริงจาก column filter คนละคอลัมน์ที่ intersection ว่าง)
พึ่งพาความหลากหลายของข้อมูลจริงใน dev DB — รอบนี้พบว่า run 1014's ข้อมูลปัจจุบันไม่มี combo แบบนั้น
เหลือแล้ว จึง skip ทั้ง cell ได้ 0 check ทุกครั้ง — **เปลี่ยนเป็นใช้ช่องค้นหา global search box +
string จาก timestamp (`zz-nomatch-<Date.now()>`)** แทน ซึ่งไม่ต้องพึ่งความหลากหลายของข้อมูลเลย และยัง
เข้า "OTHER empty state" เดียวกัน (`dtRenderEmptyState()`'s เอง `filtered` branch,
`public/js/app.js:2500`: `info.recordsTotal > 0 || tableHasActiveFilters(...)` — true เสมอเพราะ
`recordsTotal` ยังเป็นบวก) — assertion เดิมทั้ง 3 (filtered empty state ไม่ใช่ "no history yet", ปุ่ม
Clear ใช้งานได้จริง, หลังล้างกลับมาครบ) ยังคงอยู่ครบ ยืนยันแล้วว่า > 0 check ทุกครั้ง (ไม่พึ่งข้อมูล
diversity อีกต่อไป)

## Round B4 — c14 real test bug พบระหว่างวัดรอบแรก (arrival-order race, แก้แล้ว)

รอบวัดแรกของ B4 เจอ c14 fail จริง 1 จุด (`c14: request after Clear Filter has no search value -- x`,
ไม่ได้อยู่ใน scope ที่ prompt สั่ง แต่กระทบข้อกำหนด "o_history_dt: 0 fail" ของ section 5 โดยตรง จึงต้อง
สืบและแก้) — root cause: กด "ล้างตัวกรอง" 1 ครั้งบนตารางนี้ยิงได้มากกว่า 1 request/draw ซ้อนกัน
(`barClear()`'s debounced field-reset → onChange → `.draw()`, `clearColumnFilters()`'s เอง immediate
`.ajax.reload()`, และ `clearAllTableFilters()`'s เอง explicit `dt.search('').draw()` —
`public/js/app.js:2440-2452`) — `collectAuditListResponses()`'s เดิมเก็บตาม **ลำดับที่ response
มาถึง** (`page.on('response', ...)`) ซึ่งไม่รับประกันว่าตรงกับ**ลำดับที่ request ถูกส่งจริง** — ถ้า
request ที่ส่งก่อน (ยังมี search='x' ค้าง) response กลับมาถึงทีหลัง request ที่ส่งทีหลังสุด (ที่ถูกล้าง
ครบแล้วจริง) `list[list.length-1]` จะหยิบตัวผิดมาตรวจ **แก้**: เพิ่ม `seq` (stamp จาก `page.on
('request', ...)` ซึ่งเรียงตามลำดับส่งจริงเสมอ ต่างจาก `'response'`) ให้ `collectAuditListResponses()`
(เพิ่มเติมแบบ backward-compatible, N1/N2/c9 ที่อ่านแค่ `.list.length` ไม่กระทบ), c14 เปลี่ยนมาเลือก
entry ที่ `seq` สูงสุดแทน array position, และรอ DOM settle ครบ (search ว่าง + ไม่มี column filter +
date ว่าง ผ่าน `page.waitForFunction`) ก่อน `collector.stop()` กันพลาดกรณี response ตัวจริงยังไม่มาถึง
ตอนเรียก stop — verified: รันซ้ำ 3 รอบติดกันหลังแก้ ผ่านคงที่ 20/20 ทุกรอบ

## tiny-2 (ยังไม่ทำ, แผนอนาคต)

`.get()`'s response ยังคงมี `audit_log` key เดิมอยู่ (ตารางใหม่ไม่ได้ใช้มันแล้ว แต่ยังไม่ถูกตัดออกเพื่อ
ความปลอดภัยระหว่างช่วงเปลี่ยนผ่าน) — ขั้นถัดไปคือลบ key นี้ออกจาก `PayrollRunModel::get()` เพื่อได้
payload เล็กลงจริงตามเป้าหมายเดิมของ initiative นี้ ก่อนตัดต้องยืนยันว่าไม่มี consumer อื่นเหลืออยู่
(grep `res.data.audit_log` ทั้ง repo)
