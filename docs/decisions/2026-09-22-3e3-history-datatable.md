# 3e-3 — Action History tab: Timeline card list → DataTable (2026-09-22)
rules.md §6/§7 · `initAuditLogTableRd()` (`payroll/detail.js`) แทน `renderAuditHistoryTimelineRd()`

**เหตุผล**: run 752 ในเดฟมี audit log 1571 แถว (ไม่นับ `view_detail`) — เกินกว่า card-per-row list จะแสดงครบได้โดยไม่มี paging ของตัวเอง ซึ่ง DataTable ทำให้ฟรีอยู่แล้ว · §6 เพิ่มกฎ: feed ที่โตไม่จำกัด = DataTable, Timeline สงวนไว้ feed สั้นที่ตัดยอดได้

**`initSharedDataTable()`'s `searchThreshold` (default 10) ปิด filter engine ทั้งระบบเงียบๆ** — ตารางที่แถว ≤10 ไม่มี search box หรือ `$.fn.dataTable.ext.search` predicate ทำงานเลย แม้ config ถูกต้องทุกจุด · fixture ปกติ (`--with-recurring --with-calc-errors`) มีแค่ 9 แถว ต่ำกว่า threshold เสมอ → เทส filter (c2-c4/c7) ต้องเปิด run 752 จริงแทน · `o_history_dt.js` เพิ่ม `searchingEnabled()` guard (อ่าน `oFeatures.bFilter` สด ไม่ hardcode เลข) กัน false-fail รอบหน้า

**ip filter บน run 752 เจอค่าไม่ซ้ำแค่ค่าเดียว (`::1`, 1566/1571 แถว)** — ติ๊กช่องเดียวที่มี = "เลือกทั้งหมด" ตาม Excel-semantics ที่ `table-column-filter.js` ตั้งใจไว้เอง (ไม่ใช่บั๊ก) → เทสแยกเป็น 2 เช็ค: ติ๊กค่าเดียวที่มี = unfiltered ตามดีไซน์, กรองด้วยค่าที่ไม่มีจริง = 0 แถว พิสูจน์ narrow ได้จริง

**m3e1 cell 13 anchor เลือกผิดตัวรอบแรก** — `.rd-audit-log-wrap` เป็น div ที่ห่อ `<table>` (ancestor) ไม่ใช่ block ข้างๆ แบบ `.callout`/`.filter-bar` ตัวอื่นในลิสต์ · เทียบ `<table>.left` กับ wrapper ของตัวเองเจอ offset 9px จริงจาก DataTables' เอง Bootstrap5 `.dt-layout-table .col-md` gutter — ไม่ใช่บั๊กจัดวาง ลบ selector ออกไม่ใส่ตัวแทน (`anchor:null` = skip เหมือน `#run-reports-pane` เดิมที่ไม่มี sibling block เช่นกัน) · class ที่ไม่มี consumer ถูกถอดออกจาก `detail.php` ด้วย

**คอลัมน์ "หมายเหตุ" ต้อง object-form render** — function-form เดียวใช้ทั้ง display/filter ทำให้ search box ค้นหาเจอ HTML ดิบแทนเนื้อ note จริง

**ยังไม่ได้ทำ**: BACKLOG 5 ข้อ (renderTimeline consumer 0, from→to ไม่แสดง, audit_log 710KB/load, แถวเสีย id 156711, fixture เพิ่ม action ให้เกิน threshold)
