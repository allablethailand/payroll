# Design Inventory — `docs/design/rules.md` §12 lint

## 1. หัว

- วันที่วัด: **2026-09-26**
- commit ที่วัด: **`e0966295`** (โค้ดที่ถูกสแกนไม่เปลี่ยนในรอบที่วัดนี้ — เฉพาะ `scripts/check-design.php`/`tests/design_lint_test.php` ที่แก้ในรอบ B ไม่กระทบไฟล์ที่ถูกสแกน)
- `design:clean` file: **14 ไฟล์** (ทุกไฟล์ PASS จริง — ดูตาราง §4)
- Files scanned: **111** (14 design:clean + 72 มี hit + 25 ไม่มี hit เลย)

## 2. วิธีวัดซ้ำ

```
php scripts/check-design.php --all
```

`--all` (เพิ่มรอบนี้) แสดงไฟล์ที่มี hit **ครบทุกไฟล์** (ไม่ตัด top-20) บวกส่วน "unmarked files with 0 hits" — โหมดปกติ (ไม่ใส่ flag) output เหมือนเดิมทุกไบต์กับก่อนรอบนี้

กฎแต่ละข้อ (implementation อยู่ใน `scripts/check-design.php`, อ้างชื่อฟังก์ชัน ไม่ใส่เลขบรรทัดเพราะเลขจะขยับทุกครั้งที่แก้ script):

| # | ชื่อกฎ | ตรวจอะไร | ฟังก์ชัน |
|---|---|---|---|
| 1 | hex/rgb/hsl นอก tokens.css | CSS ทั้งบรรทัด (ยกเว้น `--bs-*-rgb:`); view จำกัดค่าใน `style="..."`; JS จำกัดใน string literal | `designLintRule1` |
| 2 | inline `style="` | ค่า attribute `style=` เทียบ exemption (display:none, dimension-only, var()-only) | `designLintRule2` / `designLintStyleValueExempt` |
| 3 | forbidden Bootstrap class | token-match ใน `class="..."` เทียบ exact-list + `btn-outline-*` (ยกเว้น secondary/primary) + คู่ `num`+`text-danger` | `designLintRule3` |
| 4 | ไอคอน `fa` ใน `.nav-link` | token `nav-link` ใน `class` → window 5 บรรทัดถัดไปหา `<i class="fa...">` | `designLintRule4` |
| 5 | `.DataTable(`/`.dataTable(` นอก `initSharedDataTable()` | regex ตรง, ยกเว้น `app.js` | `designLintRule5` |
| 6 | `Swal.fire(` นอก app.js/alert.js | regex ตรง, ยกเว้น 2 ไฟล์นั้น | `designLintRule6` |
| 7 | `number_format(`/`.toLocaleString(` | view=`number_format`, js=`.toLocaleString`, ยกเว้น `format-helpers.js` | `designLintRule7` |
| 8 | `<span class="badge">` ไม่มี `data-badge="status"` | ตรวจต่อ tag เดียว, token-match `badge`/`badge-*` | `designLintRule8` |

ไฟล์ที่สแกน (`designLintCollectFiles`): `app/views/**/*.php` + `docs/design/components.php` + `public/js/**/*.js` (ไม่มี vendor/`.min.js` จริงในโปรเจกต์) + `public/css/style.css`, `public/css/tokens.css`

โหมด "ผ่อน": ไฟล์ที่ไม่มี marker `design:clean` ถูกนับแต่ไม่ทำให้ script fail (`designLintHasCleanMarker`)

### hit ที่ไม่ใช่โค้ดจริง

กฎ #5 (`\.(DataTable|dataTable)\s*\(`) นับบรรทัดคอมเมนต์/docblock ด้วย เพราะเป็น whole-line regex ไม่ใช่ token-aware แบบกฎ 1/3/4/8 — ไล่อ่านทุกบรรทัดที่กฎ #5 ตี (137 บรรทัด) ทีละบรรทัดจริง พบ **9 บรรทัด** ที่เป็นคอมเมนต์/docblock ล้วนๆ ไม่ใช่โค้ดที่ทำงานจริง:

| ไฟล์ | จำนวน |
|---|---|
| `public/js/table-column-filter.js` | 4 |
| `public/js/sticky-table-columns.js` | 2 |
| `public/js/payroll/detail.js` | 2 |
| `public/js/employee/list.js` | 1 |
| **รวม** | **9** |

กฎ #5 ยังนับ getter ของ instance ที่มีอยู่แล้ว (`.DataTable()` ไม่มี argument) เป็น hit ด้วย เช่น `table-column-filter.js` 1 จุด ในตัวจัดการคลิกปุ่มกรอง — ไม่ใช่การสร้างตารางแบบ raw

**ผลที่ตามมา**: `table-column-filter.js`, `sticky-table-columns.js`, `public/js/payroll/detail.js`, `public/js/employee/list.js` **จะไม่ถึง 0 บนกฎ #5 แม้ย้ายทุกตารางในคิว §6.2 เข้า `initSharedDataTable()` ครบแล้วก็ตาม** จนกว่าจะแก้คอมเมนต์ (เขียนใหม่ไม่ให้มี literal `.DataTable(` ในคอมเมนต์) — และสำหรับ `table-column-filter.js` ต้องแก้ตัว lint ให้แยก getter (`.DataTable()` ไม่มี argument) ออกจากการสร้างตารางจริงด้วย ไม่งั้นจุดนั้นจะติดค้างอยู่เสมอไม่ว่าจะย้ายตาราง/แก้คอมเมนต์ครบแค่ไหน

## 3. ยอดรวมต่อกฎ

| กฎ | 23-09 | 26-09 | ต่าง |
|---|---|---|---|
| #1 hex/rgb/hsl | 978 | 978 | 0 |
| #2 inline style | 85 | 85 | 0 |
| #3 forbidden class | 272 | **270** | **-2** |
| #4 tab icon | 83 | 83 | 0 |
| #5 raw DataTable | 138 | **137** | **-1** |
| #6 Swal.fire | 12 | 12 | 0 |
| #7 number_format | 60 | 60 | 0 |
| #8 badge | 164 | 164 | 0 |

## 4. ตารางไฟล์ × กฎ (ครบทุกไฟล์ที่มี hit, 72 ไฟล์ — เรียงรวมมาก→น้อย)

ที่มา: `php scripts/check-design.php --all` (วัดตรง ไม่มีตัวเลขประมาณ)

| ไฟล์ | #1 | #2 | #3 | #4 | #5 | #6 | #7 | #8 | รวม |
|---|---|---|---|---|---|---|---|---|---|
| public/css/style.css | 842 | | | | | | | | 842 |
| app/views/layout/modals.php | | 18 | 78 | 2 | | | | 10 | 108 |
| public/js/setup/employment-certificate-template.js | 54 | 6 | 2 | | 9 | | | | 71 |
| public/js/employee/reports.js | 17 | 3 | 2 | | 11 | | 30 | 5 | 68 |
| public/js/employee/detail.js | 7 | 3 | 14 | | 15 | 1 | 10 | 16 | 66 |
| app/views/employee/detail.php | | 2 | 36 | 13 | | | | 1 | 52 |
| public/js/setup/payslip-template.js | 32 | 6 | 2 | | 9 | | | | 49 |
| public/js/setup/payroll-configuration.js | 1 | | 13 | | 8 | 1 | 4 | 18 | 45 |
| public/js/payroll/index.js | 1 | | 14 | | 6 | 3 | 1 | 17 | 42 |
| public/js/employee/list.js | 5 | 3 | 11 | | 7 | | | 14 | 40 |
| app/views/setup-rules/index.php | | 9 | 15 | 5 | | | | 2 | 31 |
| public/js/setup/company-profile.js | 2 | | 8 | | 5 | | 3 | 10 | 28 |
| public/js/setup/tax-statutory.js | 1 | | 7 | | 2 | 2 | | 12 | 24 |
| public/js/setup/setup-rules.js | | | 2 | | 10 | 1 | 3 | 6 | 22 |
| app/views/setup/company-profile.php | | 1 | 3 | 15 | | | | 1 | 20 |
| public/js/app.js | 8 | 5 | | | | | | 2 | 15 |
| public/js/manual-entry/index.js | | | 3 | | 5 | | 1 | 5 | 14 |
| public/js/setup/holiday-sync.js | 2 | 3 | 2 | | 4 | | | 3 | 14 |
| public/js/employee/employee-sync.js | 1 | 1 | 2 | | 4 | | | 4 | 12 |
| public/js/payroll/detail.js | | | 4 | | 6 | 1 | | 1 | 12 |
| public/js/setup/org-structure-sync.js | 1 | 1 | 3 | | 4 | | | 3 | 12 |
| app/views/setup/payroll-configuration.php | | 4 | 1 | 5 | | | | | 10 |
| public/js/dashboard.js | 2 | | | | | | 6 | 2 | 10 |
| app/views/employee/reports.php | | | | 9 | | | | | 9 |
| app/views/employment-certificate/_editor_content.php | | 3 | 4 | 2 | | | | | 9 |
| app/views/payslip-template/_editor_content.php | | 3 | 4 | 2 | | | | | 9 |
| public/js/reports/index.js | | | 2 | | 6 | | | | 8 |
| public/js/setup/data-sync.js | 1 | 1 | 1 | | 2 | 1 | | 2 | 8 |
| app/views/setup/data-sync.php | 1 | 3 | 1 | 2 | | | | | 7 |
| app/views/setup/document-approval.php | | | | 6 | | | | 1 | 7 |
| public/js/payroll/approval.js | | | 4 | | 2 | | | 1 | 7 |
| public/js/setup/announcements.js | | | 4 | | 1 | | | 2 | 7 |
| app/views/setup/tax-statutory.php | | 2 | 1 | 3 | | | | | 6 |
| public/js/setup/email-queue-log.js | | | 1 | | 2 | | | 3 | 6 |
| public/js/setup/payslip-delivery-log.js | | | 2 | | 2 | | | 2 | 6 |
| app/views/manual-entry/index.php | | 1 | | 4 | | | | | 5 |
| public/js/notifications.js | | 1 | | | 2 | | | 2 | 5 |
| public/js/table-column-filter.js | | | | | 5 | | | | 5 |
| app/views/employment-certificate/_modals_partial.php | | | 4 | | | | | | 4 |
| app/views/payslip-template/_modals_partial.php | | | 4 | | | | | | 4 |
| app/views/reports/annual-summary.php | | | | 4 | | | | | 4 |
| app/views/reports/index.php | | 1 | | 3 | | | | | 4 |
| public/js/employee/login-history.js | | | 1 | | 1 | | | 2 | 4 |
| public/js/setup/employment-certificate-request.js | | | 1 | | 2 | | | 1 | 4 |
| app/views/announcements/my-list.php | | | 1 | | | | | 2 | 3 |
| app/views/dashboard.php | | | 1 | | | | | 2 | 3 |
| app/views/payroll/index.php | | 1 | 2 | | | | | | 3 |
| app/views/payslip/requests.php | | | | 3 | | | | | 3 |
| app/views/payslip/settings.php | | | | 3 | | | | | 3 |
| app/views/setup/announcements.php | | 1 | 2 | | | | | | 3 |
| public/js/reports/annual-summary.js | | 1 | 1 | | | | 1 | | 3 |
| public/js/setup/payslip-request.js | | | | | 2 | | | 1 | 3 |
| public/js/setup/structure-assign.js | | | | | | 1 | | 2 | 3 |
| app/views/employee/list.php | | | | 2 | | | | | 2 |
| public/js/manual-entry/bulk-entry.js | | | | | | | | 2 | 2 |
| public/js/reports/run-audit.js | | | | | 1 | | 1 | | 2 |
| public/js/setup/assign-widget.js | | | | | | | | 2 | 2 |
| public/js/setup/audit-log.js | | | | | 1 | | | 1 | 2 |
| public/js/setup/document-numbering.js | | | 1 | | | | | 1 | 2 |
| public/js/setup/setup-guide.js | | | 1 | | | | | 1 | 2 |
| public/js/sticky-table-columns.js | | | | | 2 | | | | 2 |
| app/views/help/setup-guide.php | | | 1 | | | | | | 1 |
| app/views/layout/header.php | | 1 | | | | | | | 1 |
| app/views/payroll/approval.php | | | 1 | | | | | | 1 |
| app/views/payroll/detail.php | | 1 | | | | | | | 1 |
| app/views/setup/permissions.php | | | 1 | | | | | | 1 |
| public/js/session-guard.js | | | | | | 1 | | | 1 |
| public/js/setup/approval-request-detail.js | | | | | | | | 1 | 1 |
| public/js/setup/approval-workflow.js | | | 1 | | | | | | 1 |
| public/js/setup/changelog.js | | | | | | | | 1 | 1 |
| public/js/setup/origami-sync-widget.js | | | 1 | | | | | | 1 |
| public/js/setup/system-access-history.js | | | | | 1 | | | | 1 |
| **รวม** | **978** | **85** | **270** | **83** | **137** | **12** | **60** | **164** | **1789** |

### ไฟล์ 0 hit (25 ไฟล์, ถูกสแกนแล้วแต่ไม่มี hit เลยแม้แต่กฎเดียว)

`app/views/employee/login-history.php`, `app/views/employment-certificate/_list_partial.php`, `app/views/employment-certificate/edit.php`, `app/views/employment-certificate/settings.php`, `app/views/error404.php`, `app/views/help/version.php`, `app/views/layout/footer.php`, `app/views/notification/index.php`, `app/views/partials/payee-destination.php`, `app/views/payslip-template/_list_partial.php`, `app/views/payslip-template/edit.php`, `app/views/permission.php`, `app/views/reports/run-audit.php`, `app/views/setup/audit-log.php`, `public/js/alert.js`, `public/js/format-helpers.js`, `public/js/input.js`, `public/js/payee-descriptor.js`, `public/js/quick-links.js`, `public/js/setup/canvas-designer-core.js`, `public/js/setup/help-drawer.js`, `public/js/setup/notification-preferences-matrix.js`, `public/js/setup/payslip-distribution.js`, `public/js/setup/permission-matrix.js`, `public/js/setup/terms-and-conditions.js`

### ไฟล์ `design:clean` (14 ไฟล์, PASS ทุกตัว)

`app/views/layout/page-loader.php`, `app/views/partials/calendar-widget.php`, `app/views/partials/callout.php`, `app/views/partials/emp-header-card.php`, `app/views/partials/empty-state.php`, `app/views/partials/filter-bar.php`, `app/views/partials/page-header.php`, `app/views/partials/setting-row.php`, `app/views/partials/stat-card.php`, `app/views/partials/status-stepper.php`, `app/views/partials/status-tabs.php`, `app/views/partials/timeline.php`, `docs/design/components.php`, `public/css/tokens.css`

## 5. จัดกลุ่มตามโมดูล

**เกณฑ์**: ไฟล์ที่ `<script src>`/include อยู่ใน `app/views/layout/header.php` หรือ `footer.php` เอง (โหลดทุกหน้าไม่มีเงื่อนไข) = **Shared** — ตรวจจริงด้วย `grep '<script src=' app/views/layout/header.php app/views/layout/footer.php` (ไม่ใช่แค่เดาจากชื่อไฟล์) พบว่ากว้างกว่าที่คาดไว้ตอนแรก: นอกจาก `app.js`/`table-column-filter.js`/`sticky-table-columns.js`/`session-guard.js`/`notifications.js` แล้ว ยังมี `alert.js`, `format-helpers.js`, `input.js`, `payee-descriptor.js`, `quick-links.js`, และ **4 ไฟล์ใต้ `public/js/setup/`** (`assign-widget.js`, `terms-and-conditions.js`, `help-drawer.js`, `system-access-history.js`) ที่ include แบบ global เหมือนกันแม้ path จะอยู่ใน `setup/` — ไฟล์อื่นที่เหลือจัดตาม path/เมนูที่ใช้งานจริง (ตรวจ route/comment ในไฟล์ ไม่ใช่แค่เดาจาก path)

| กลุ่ม | จำนวนไฟล์ | #1 | #2 | #3 | #4 | #5 | #6 | #7 | #8 | รวม |
|---|---|---|---|---|---|---|---|---|---|---|
| Shared | 33 | 850 | 25 | 78 | 2 | 10 | 1 | 0 | 16 | 982 |
| Employee | 9 | 30 | 12 | 66 | 24 | 38 | 1 | 40 | 42 | 253 |
| Setup — Core Config (payroll-config/tax/company/setup-rules) | 8 | 4 | 16 | 50 | 28 | 25 | 4 | 10 | 49 | 186 |
| Canvas designer (ECT + Payslip Template) | 12 | 86 | 18 | 20 | 4 | 18 | 0 | 0 | 0 | 146 |
| Payroll | 6 | 1 | 2 | 25 | 0 | 14 | 4 | 1 | 19 | 66 |
| Document & Approval / Requests | 16 | 0 | 1 | 13 | 12 | 9 | 1 | 0 | 16 | 52 |
| Sync & Integration (Origami) | 5 | 5 | 8 | 8 | 2 | 10 | 1 | 0 | 8 | 42 |
| Manual Entry | 3 | 0 | 1 | 3 | 4 | 5 | 0 | 1 | 7 | 21 |
| Reports (รวม Audit Log — ย้ายเข้าเมนู Reports แล้ว) | 8 | 0 | 2 | 3 | 7 | 8 | 0 | 2 | 1 | 23 |
| Dashboard | 2 | 2 | 0 | 1 | 0 | 0 | 0 | 6 | 4 | 13 |
| Admin/Settings misc (Permissions, notif-pref-matrix, changelog, setup-guide.js) | 5 | 0 | 0 | 2 | 0 | 0 | 0 | 0 | 2 | 4 |
| System shell pages (error404, permission-denied, help pages) | 4 | 0 | 0 | 1 | 0 | 0 | 0 | 0 | 0 | 1 |
| **รวมทุกกลุ่ม** | **111** | **978** | **85** | **270** | **83** | **137** | **12** | **60** | **164** | **1789** |

ตรวจแล้ว: ผลรวมทุกกลุ่ม = ยอดรวมต่อกฎทั้ง 8 กฎเป๊ะ + จำนวนไฟล์รวม = 111 [ยืนยันจากการรัน]

**ไฟล์ที่ตัดสินยาก (ตัดสินใจแล้ว แต่ควรรู้เหตุผล)**:
- `docs/design/components.php` — ไม่ใช่ "หน้า" จริงของแอป (เป็นเอกสารอ้างอิง/demo component) จัดเข้า Shared เพราะสาธิต shared component ทุกตัว
- `public/js/employee/employee-sync.js` — ฟีเจอร์ sync จาก Origami แต่ UI อยู่ใน Employee module (Employee Sync picker) จัดเข้า Employee ไม่ใช่ Sync & Integration
- `public/js/setup/audit-log.js` + `app/views/setup/audit-log.php` — ชื่อไฟล์ยังอยู่ใต้ `setup/` แต่เมนูจริงอยู่ใต้ **Reports** — ยืนยันจาก `app/views/layout/header.php:618` (`<li class="menu-item has-submenu">` เปิดกลุ่มเมนู) `:623` (`data-i18n="reports"` label "Reports") ครอบ submenu-link ของ `:665-669` (`href="...../audit-log"`, ข้อความ "Audit Log") อยู่ในบล็อกเดียวกับ `:629` (`/reports`, "Generate Reports") และ `:655` (`/reports/run-audit`, "Payroll Run Audit") ก่อนจะเจอ `<li class="menu-item has-submenu">` ตัวถัดไปที่ `:691` (เมนูอื่น) — จัดเข้ากลุ่ม Reports ไม่ใช่ Setup ตามโครงสร้างเมนูจริงในโค้ด
- `public/js/setup/email-queue-log.js` — ไม่มี view แยกของตัวเอง เป็น tab ("Email Log") ที่ฝังอยู่ใน `app/views/setup/document-approval.php` (ยืนยันจาก `document-approval.php:24` มี `id="emailQueueLogTabBtn"`) จัดเข้ากลุ่มเดียวกับ document-approval
- `app/views/permission.php` (403 "Access Denied" page) ≠ `app/views/setup/permissions.php` (RBAC matrix) — คนละไฟล์คนละหน้าที่ ชื่อคล้ายกันมาก

## 6. งานในคิว (ยืนยันแล้ว — เรียงตามลำดับนี้ ห้ามเสนอลำดับใหม่)

### 6.1 ย้าย `.station-filter` → `partials/filter-bar.php` (reports 3 ไฟล์)

| ไฟล์ | จำนวน panel | id ของแต่ละ panel |
|---|---|---|
| `app/views/reports/index.php` | 3 | `cycleReportPeriodBar`, `annualReportPeriodBar`, `exportHistoryStationFilter` |
| `app/views/reports/annual-summary.php` | 4 | `aisStationFilter`, `aisPitStationFilter`, `aisSsoStationFilter`, `aisMonthlyStationFilter` |
| `app/views/reports/run-audit.php` | 1 | `runAuditStationFilter` |

### 6.2 6 ตาราง audit → `initSharedDataTable`

| # | selector | ไฟล์ | serverSide? |
|---|---|---|---|
| 1 | `#tb_audit_log` | `public/js/setup/audit-log.js` | ใช่ |
| 2 | `#tb_login_history_overview` | `public/js/employee/login-history.js` | ใช่ |
| 3 | `#tableLoginHistory` | `public/js/employee/detail.js` | ใช่ |
| 4 | `#tb_run_audit_list` | `public/js/reports/run-audit.js` | ไม่ (client-side) |
| 5 | `#tb_system_access_history` | `public/js/setup/system-access-history.js` | ไม่ (client-side) |
| 6 | `#tb_email_queue_log` | `public/js/setup/email-queue-log.js` | ไม่ (client-side) |

**หมายเหตุ**: `#tb_run_audit_log` (`public/js/payroll/detail.js`) **ปิดแล้ว** (ผ่าน `initSharedDataTable()` + serverSide จริง) ตาม `docs/decisions/2026-09-24-audit-log-serverside.md` — ชื่อคล้ายกับ `#tb_run_audit_list` มาก (`_list` vs `_log`) ต้องแยกให้ชัดตอนหยิบงาน ไม่ใช่ตัวเดียวกัน

**ผู้สมัครเพิ่ม (ยังไม่ตัดสิน)** — ตารางที่ชื่อ/หน้าที่เป็น log/history/audit แต่ยังไม่อยู่ในคิว 6 ตัวข้างบน (ดูภาคผนวก การไล่ทุก call site ของกฎ #5):

| selector | ไฟล์ | ความหมาย | serverSide? |
|---|---|---|---|
| `#tableSyncTransactionLog` | `public/js/employee/detail.js` | Sync Transaction Log (ใน modal Employee Detail) | ไม่ |
| `#tb_import_history` | `public/js/manual-entry/index.js` | ประวัติ import ไฟล์ | ไม่ |
| `#tb_report_history` | `public/js/payroll/detail.js` | ประวัติรายงานที่ generate จาก Payroll Run นี้ | ไม่ (คอมเมนต์ในไฟล์ยืนยันตรงๆ ว่า client-side) |
| `#tb_cycle_report_history` | `public/js/reports/index.js` | ประวัติรายงานต่อรอบ (Reports > Per-Cycle) | ไม่ |
| `#tb_export_history` | `public/js/reports/index.js` | ประวัติ export รายงานประจำปี | ไม่ (คอมเมนต์ยืนยันตรงๆ) |
| `#tb_data_sync_history` | `public/js/setup/data-sync.js` | ประวัติ sync ข้อมูลจาก Origami (ภาพรวม) | ไม่ |
| `#tb_ds_card_history` | `public/js/setup/data-sync.js` | ประวัติ sync ต่อการ์ด/ประเภทข้อมูล | ไม่ |
| `#tb_payslip_delivery_log` | `public/js/setup/payslip-delivery-log.js` | Payslip Delivery Log | ไม่ (คอมเมนต์ยืนยันตรงๆ) |

ตัดสินในรอบ A ของก้อน 6.2 — การย้ายเข้า `initSharedDataTable` ไม่ได้แปลว่าต้องเป็น serverSide

### 6.3 `app/views/layout/modals.php` — `<th data-i18n>` 47 จุด

ยังไม่ตรวจ key ครบ th/en (ต้องรัน `php scripts/check-lang.php` แยก ไม่ได้ทำในรอบนี้)

### 6.4 `.station-filter` ที่เหลือ

นับด้วย pattern `class="station-filter(\s|")` — บาง panel มี class เพิ่ม (เช่น `mb-2`) ถ้าค้นแบบ exact string จะนับขาด

| ไฟล์ | จำนวน panel | id |
|---|---|---|
| `app/views/employee/reports.php` | 9 | employeeSummary/Headcount/Expiry/Probation/Enrollment/Structure/Tenure/Birthday/Completeness StationFilter |
| `app/views/reports/annual-summary.php` | 4 | aisStationFilter, aisPitStationFilter, aisSsoStationFilter, aisMonthlyStationFilter |
| `app/views/manual-entry/index.php` | 4 | attendance/leave/overtime/importHistory StationFilter |
| `app/views/reports/index.php` | 3 | cycleReportPeriodBar, annualReportPeriodBar, exportHistoryStationFilter |
| `app/views/employee/list.php` | 2 | employeeStationFilter, employeeRecheckStationFilter |
| `app/views/employee/login-history.php` | 1 | employeeLoginHistoryStationFilter |
| `app/views/layout/modals.php` | 1 | cycleReportHistoryStationFilter |
| `app/views/employee/detail.php` | 1 | loginHistoryStationFilter |
| `app/views/notification/index.php` | 1 | notifStationFilter |
| `app/views/payroll/detail.php` | 1 | reportHistoryStationFilter |
| `app/views/payroll/index.php` | 1 | stationFilter |
| `app/views/setup/data-sync.php` | 1 | dsHistoryStationFilter |
| `app/views/setup/announcements.php` | 1 | announcementStationFilter |
| `app/views/setup/audit-log.php` | 1 | auditLogStationFilter |
| `app/views/payroll/approval.php` | 1 | approvalStationFilter |
| `app/views/setup/document-approval.php` | 1 | emailQueueStationFilter |
| `app/views/reports/run-audit.php` | 1 | runAuditStationFilter |
| `app/views/payslip/requests.php` | 1 | dlogStationFilter |
| **รวม** | **18 ไฟล์ / 35 panel** | |

หลังย้ายทุก panel ไป `filter-bar.php` แล้ว ให้ลบ CSS ของ `.station-filter` ใน `public/css/style.css`: selector `.station-filter`, `.station-filter-body`, `.station-filter.collapsed .station-filter-body`, `.station-filter-label`, `.station-filter-toggle`, `.station-filter.collapsed .station-filter-toggle`, `.station-filter-clear-row` (บล็อกต่อเนื่องกัน — ตรวจตำแหน่งจริงใหม่ตอนลงมือ เพราะเลขบรรทัดจะขยับถ้ามีคนแก้ไฟล์ก่อนหน้านั้น)

## 7. ยังไม่วางแผน

- **Tokenize สีใน `style.css` (#1 = 842 จุด)** — ต้องคุยขอบเขตแยก ไม่ใช่งานของรอบ 4 นี้ (ใหญ่กว่าทุกกลุ่มอื่นในตาราง §5 รวมกัน)
- งานลด lint รายโมดูลอื่น ๆ (Employee/Setup Core Config/Canvas designer/Payroll/ฯลฯ ในตาราง §5) จะจัดลำดับ **หลัง** คิวข้อ 6 ด้านบนเสร็จ

## 8. ประวัติการปิดก้อน

| วันที่ | ก้อน | commit | ตัวเลขก่อน | ตัวเลขหลัง |
|---|---|---|---|---|
| | | | | |

(ว่าง — เติมทีละแถวทุกครั้งที่ปิดก้อนจากคิว §6 หรือกลุ่มโมดูลใน §5)
