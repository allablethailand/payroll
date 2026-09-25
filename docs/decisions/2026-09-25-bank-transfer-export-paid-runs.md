<!-- รอบ B ของบั๊ก export ไฟล์โอนเงินธนาคาร -- อ่านคู่กับ tmp-bank-roundA.md (การสืบสวนรอบ A ฉบับเต็ม)
     สรุปกฎปัจจุบันแบบสั้นอยู่ใน docs/decisions/output-requirement.md อยู่แล้ว ไฟล์นี้เก็บเฉพาะเหตุผล/หลักฐาน/
     การตัดสินใจของบั๊กนี้โดยเฉพาะ -->

## ต้นเหตุ

`markPaid()` บันทึก `payroll_run_payment_events` เต็มจำนวน (100%) ทุกคนทุกครั้งที่กด "Mark as Paid" —
`lock()` บังคับให้ต้องผ่าน state `paid` มาก่อนเสมอ — `BankTransferFileReport`/`PayrollReportDataModel`
เดิมอ่าน `net_amount_due` (ยอดค้างจ่าย = net_amount - SUM(payroll_run_payment_events)) เป็นยอดที่จะใส่ในไฟล์
เสมอไม่ว่า state ไหน — ผลคือรอบที่ผ่าน `paid`/`locked` มาแล้ว **ทุกรอบ ไม่ว่าประเภท** จะมี `net_amount_due = 0`
ทุกคนเสมอ → `$groups`/`$included` ว่าง → throw `bank_transfer_no_valid_accounts` — ไม่เกี่ยวกับ run_purpose
(incentive/ปกติ) เลย เป็น design flaw ที่ผสม 2 concept เข้าด้วยกัน: state ที่ตั้งใจให้ดาวน์โหลดไฟล์ย้อนหลังได้
(`ALLOWED_STATES` มี paid/locked) กับสูตรยอดที่คิดเป็น "ยอดค้าง" (ไม่ใช่ "ยอดที่จ่ายไปแล้ว")

## หลักฐาน (prod/dev)

- **Prod**: รอบ Incentive "ค่าเที่ยวเดือน สิงหาคม 2569" (locked, 11 คนโอนทั้งหมด, รวม 5,195.00) export ไม่ได้ —
  รอบเงินเดือนปกติที่ยังไม่ปิด (state=approved) export ได้ปกติ — ยืนยันว่าตัวแปรคือ **state** ไม่ใช่ run_purpose
  (ไล่ทุกจุดในเส้นทาง export แล้ว grep คำว่า `run_purpose` ไม่พบเลยสักจุดใน `BankTransferFileReport.php`/
  `PayrollRunEmployeeBankAccountModel.php`)
- **Dev**: จำลองได้ด้วย run 1014 (locked, EM009 ตั้งโอน+ธนาคาร 025+เลขบัญชีทดสอบ) — export error เดียวกัน
- ช่องว่างเทสจริง: ไม่มี fixture ไหนเคยตั้ง `payment_method_id='transfer'` แล้วปล่อย `bank_id`/`bank_account_no`
  เป็น NULL มาก่อนเลย (ปิดช่องว่างนี้ใน `tests/bank_transfer_export_paid_locked_test.php` Scenario 1)

## ข้อกำหนด (ผู้ใช้ตัดสิน, prompt-bank-roundB.md)

- **R1**: รอบ paid/locked ต้อง export ได้ — ยอดต่อคน = ยอดที่บันทึกจ่ายผ่านการโอนของรอบนั้น (จาก payment
  events) ห้ามเกิน `net_amount` ของคนนั้น
- **R2**: รอบ approved ใช้ยอดค้างจ่ายเหมือนเดิมทุกไบต์ (เทสเดิมทั้งหมดผ่านโดยไม่แก้ค่าคาด ยกเว้น 1 จุดที่แก้
  ด้านล่าง — เป็นจุดที่ทดสอบพฤติกรรม paid state ไม่ใช่ approved)
- **R3**: ห้าม error เพราะไม่มีคนเข้าไฟล์ — ต้องได้ไฟล์เสมอ (ว่างได้) พร้อมคำเตือนแยกตามเหตุผล — error อื่น
  (state ไม่อนุญาต, ถอดรหัสไม่ได้, format เสีย, width overflow ฯลฯ) คงพฤติกรรมเดิม

## นิยาม "ยอดที่บันทึกจ่ายผ่านการโอน" (R1)

`markPaid()` บันทึก `payment_method` **หนึ่งค่าต่อทั้ง batch** (ทุกคนใน markPaid() call เดียวกันได้ค่าเดียวกัน
เสมอ ไม่แยกราย employee) — ดังนั้นนิยามที่ใช้ได้จากข้อมูลจริงคือ: `LEAST(net_amount, SUM(net_amount_paid))`
จาก `payroll_run_payment_events` ของ run+employee นั้น **กรองเฉพาะ `payment_method='bank_transfer'`** — เพิ่ม
เป็นคอลัมน์ใหม่ `net_amount_paid_via_transfer` ใน `PayrollReportDataModel::getRunDetails()` (additive,
consumer อื่นที่ไม่อ่านคอลัมน์นี้ไม่กระทบ) ครอบคลุมทั้ง plain-transfer และ mixed เพราะ `BankTransferFileReport`
override `$d['net_amount_due']` ด้วยค่านี้ **ครั้งเดียวตอนต้น `generate()`** เมื่อ state เป็น paid/locked
(approved ไม่แตะเลย) แล้วปล่อยให้ทุกจุดที่อ่าน `net_amount_due` เดิม (inclusion check ของ plain-transfer,
percent/fixed split ของ mixed) ทำงานเหมือนเดิมทุกอย่างโดยไม่ต้องแก้จุดอื่นเพิ่ม — cap ที่ `net_amount` กัน data
anomaly (เช่น payment event ผิดพลาดสะสมเกินยอดจริง) ไม่ให้หลุดเข้าไฟล์ (ทดสอบจริงด้วยการ insert event เกินตรงๆ
ใน Scenario 4)

รอบที่ batch ทั้งก้อนถูก mark paid ด้วย `payment_method='cash'` (แม้พนักงานจะถูกจัดเป็น transfer ในโปรไฟล์)
จะได้ `net_amount_paid_via_transfer = 0` ทุกคน → ไม่มีใครเข้าไฟล์ → ตรงกับ R3 กรณี "ไม่มีคนเข้าเลย" พอดี
(Scenario 5)

## ช่องทางคำเตือน (R3)

`ReportsController::generate()` stream ไฟล์กลับตรงๆ (`echo $result['content']; exit;`) ไม่มี JSON envelope
ห่ออยู่แล้ว — ตัวเลือกที่แก้น้อยที่สุดและไม่กระทบรายงานอื่น: **HTTP response header** `X-Report-Warning`
(JSON `{warning_key, params}`, `rawurlencode()`) ซึ่งตั้งเฉพาะตอน `$result['warning_key']` ถูกตั้งมาเท่านั้น
(รายงานอื่นที่ไม่เคยตั้ง key นี้ไม่มี header นี้เลย ไม่กระทบ) — `public/js/app.js`'s `generateReport()` (shared
ทั้งหน้า Reports และ Payroll Process List/Detail's print shortcuts) อ่าน header **ก่อน** consume body,
แปลผ่าน `translateReportWarning()` (sibling ใหม่ของ `translateApiError()` เดิม, lookup
`langData['warning_' + key]`) แล้วโชว์ผ่าน `showWarning()` (modal ต้องกด, ไม่ใช่ toast — เพราะเป็นข้อมูลที่
ผู้ใช้ต้องอ่านจริง ไม่ใช่แค่ "สำเร็จ") **แทนที่** `showSuccess()` toast เดิม (ไฟล์ยังดาวน์โหลดได้ปกติทั้งสองกรณี)

`error_bank_transfer_no_valid_accounts` (เดิม) ไม่มี throw site เหลือแล้วหลังแก้ (grep ยืนยัน 0 consumer
นอกจาก comment) — ลบออกจาก `public/lang/th.json`/`en.json` (บรรทัด 1428 เดิมทั้งคู่) แทนที่ด้วย
`warning_bank_transfer_employees_excluded` (คีย์ใหม่, `warning_` ไม่ใช่ `error_` prefix เพราะไม่ใช่ error จริง)

## จุดที่แก้ (backend)

- `app/models/PayrollReportDataModel.php::getRunDetails()` — เพิ่มคอลัมน์ `net_amount_paid_via_transfer`
  (additive)
- `app/services/reports/payment/BankTransferFileReport.php`:
  - `generate()`: override `net_amount_due` เมื่อ state paid/locked (ก่อน grouping) · `$groups` ว่าง →
    synthesize empty group แทน throw (reuse per-group render path เดิม) · aggregate
    `skip_no_account`/`skip_no_amount`/reconciliation count → แนบ `warning_key`/`warning_params` บน return
  - `renderGenericFallback()`/`renderConfigured()`: ลบ throw ตอนไม่มีคนเข้า (`$total<=0`/`$included` ว่าง) ·
    เพิ่ม counter แยกเหตุผล คืนกลับผ่าน return array (`skip_no_account`/`skip_no_amount`/`included_count`) —
    ไม่แตะ `$skipped`/comment-row เดิมของ generic CSV (byte-compatible, R2)
- `app/controllers/ReportsController.php::generate()` — ตั้ง `X-Report-Warning` header เมื่อมี warning
- `public/js/app.js` — `translateReportWarning()` ใหม่ + `generateReport()` อ่าน header ก่อน consume body

## ตารางเทส (`tests/bank_transfer_export_paid_locked_test.php`, ใหม่, 66 assertions)

| Scenario | ครอบคลุม |
|---|---|
| 1 | approved ไม่กระทบ (R2) + ปิดช่องว่างรอบ A (transfer ไม่มีเลขบัญชี) + partial-skip warning ตอน approved |
| 2 | paid แล้ว locked — plain transfer เต็มจำนวน (R1) |
| 3 | paid+locked, 4 คน — ครบทั้ง 3 เหตุผล skip (no_account/no_amount ผ่าน S5/reconciliation) + mixed 60% reconcile |
| 4 | reopen+จ่ายซ้ำ — event รวมกันถูกต้อง + cap ที่ net_amount แม้ ledger ผิดปกติ (insert event เกินตรงๆ) |
| 5 | locked ทั้ง batch จ่ายด้วย cash — ไฟล์ว่าง+คำเตือน ไม่ throw (R3, "ไม่มีคนเข้าเลย") |
| 6 | configured (BAY fixed-width) format, missing-account only — ไฟล์ว่าง+คำเตือน ไม่ throw (R3 ครอบทั้ง 2 format) |

เทสเดิมที่แก้ 1 จุด: `tests/payroll_run_payment_events_test.php` — assertion เดิม (บรรทัด ~218 ก่อนแก้) เช็คว่า
regenerate หลัง markPaid() รอบ 2 (state=paid) ต้อง throw "nobody due" — เป็นพฤติกรรมเดิมที่ตรงกับบั๊กนี้เป๊ะ
(ทดสอบ paid state ไม่ใช่ approved จึงไม่ขัด R2) เปลี่ยนเป็นยืนยันว่าไม่ throw และไฟล์มี 2 คน (ยอดรวมทั้งหมดที่
เคยโอนจริงของรอบนั้น) แทน — จาก 27/0 เป็น 30/0 (เพิ่ม assertion ไม่ใช่ลด)

ทุกเทสอื่นในกลุ่มธนาคาร (`bank_file_format_test.php` 88/0, `payroll_run_employee_bank_account_test.php` 59/0,
`payroll_remittance_test.php` 87/0, `reports_test.php` 186/0, `cash_payment_test.php` 55/0) ผ่านโดยไม่แก้ค่า
คาดเลย — ยืนยัน R2

## แก้เพิ่ม (session เดิม, หลัง commit แรกยังไม่เกิดขึ้น)

ผู้ใช้ขอเพิ่ม 2 ข้อหลังตรวจรอบแรก:

**R1-เพิ่ม (คำเตือน "จ่ายแล้ว" เสมอ)**: รอบ paid/locked ต้องแนบคำเตือนเสมอว่า **รอบนี้บันทึกจ่ายแล้ว ให้
ตรวจสอบก่อนอัปโหลดธนาคารเพื่อไม่ให้โอนซ้ำ** — ไม่ว่าจะมีคนถูกข้ามหรือไม่ (แม้ 100% ของคนเข้าไฟล์ครบก็ต้องเตือน
เพราะความเสี่ยงคือ "ไฟล์นี้แทนยอดที่จ่ายไปแล้ว ไม่ใช่ยอดที่กำลังจะจ่าย" ไม่เกี่ยวกับว่ามีคนตกหล่นหรือไม่)

**R3-เพิ่ม (ไม่มีคนตั้งค่าบัญชีเลย ต้องมีข้อความเฉพาะ)**: กรณี `$groups` ว่างตั้งแต่ด่านจัดกลุ่ม (ทุกคนใน
รอบเป็น cash/check หรือ mixed ที่ไม่มี transfer line เลย) — breakdown เดิม (no_account/no_amount/
reconciliation) จะได้ 0 ทุกช่องพร้อมกัน อ่านแล้วดูเหมือนพัง/ไม่มีข้อมูล ไม่ใช่คำอธิบาย — ต้องมีข้อความเฉพาะ
ของตัวเองแยกจาก breakdown เดิม

**คนเงินสด/เช็คถูกนับหรือไม่ในตัวนับเดิม (no_account/no_amount/reconciliation)**: **ไม่นับ** — ทั้ง 3
ตัวนับเดิมมาจากการวนลูปเฉพาะ `$details` ที่ผ่านด่านจัดกลุ่มเป็น `transfer`/`mixed` แล้วเท่านั้น
(`BankTransferFileReport.php`'s classification loop ใน `generate()`) — พนักงาน cash/check ถูกข้ามตั้งแต่ก่อน
เข้า loop นับ ("cash/check are not this report's concern" ตาม comment เดิมในโค้ด) ไม่เคยถูกนับเป็น
no_account/no_amount/reconciliation เลยสักคนเดียว — นี่คือเหตุผลที่ breakdown เดิมได้ 0 ทุกช่องพอดีในกรณีนี้
(ไม่มีอะไรให้นับ) ต้องแยกคำเตือนใหม่ที่นับจาก **ทั้งหมด** (`count($details)`) แทน

### การแก้

`$result['warning_key']`/`$result['warning_params']` (คู่เดียว) **เปลี่ยนเป็น `$result['warnings']`** (list ของ
`{key, params}`) เพราะตอนนี้ 1 ไฟล์อาจมีคำเตือนพร้อมกันได้มากกว่า 1 เรื่อง (เช่น locked + มีคนตกหล่น = 2
entries) — ตรรกะใน `generate()`:
1. **`bank_transfer_already_paid_notice`** — เพิ่มเสมอเมื่อ `$run['state']` เป็น paid/locked (ไม่ขึ้นกับจำนวนคน
   ที่ถูกข้ามเลย) params: `included` (จำนวนคนในไฟล์ ให้บริบท)
2. ถ้า `$includedCount === 0 && $totalSkipped === 0` (ทุกช่อง breakdown เป็น 0 พร้อมกัน = ไม่มีใครผ่านด่าน
   จัดกลุ่มเลย) → **`bank_transfer_no_bank_employees`** (params: `total` = `count($details)`) **แทนที่**
   `bank_transfer_employees_excluded` เดิม (ไม่ใช้ทั้งคู่พร้อมกัน — mutually exclusive)
3. ถ้า `$totalSkipped > 0` (ปกติ มีคนผ่านด่านจัดกลุ่มแต่บางคนถูกข้ามภายหลัง) → `bank_transfer_employees_excluded`
   เดิม เหมือนรอบแรกทุกประการ

`ReportsController::generate()` header เปลี่ยนจาก `{warning_key, params}` เป็น `{warnings: [...]}` —
`public/js/app.js`'s `generateReport()` แปลทุก entry ผ่าน `translateReportWarning()` เดิม แล้ว**รวมเป็นข้อความ
เดียว**ก่อนโชว์ `showWarning()`: 1 entry แสดงตรงๆ เหมือนเดิม, 2+ entries ใส่เลขนำหน้า `1) ... 2) ...` คั่นด้วย
space 3 ตัว (**ไม่ใช้ `\n` ล้วน** — ตรวจ `node_modules/sweetalert2/dist/sweetalert2.min.css` แล้วยืนยันว่า
`.swal2-html-container` ไม่มี `white-space:pre-line` เป็นค่าเริ่มต้น เขียน `\n` เฉยๆ จะยุบเป็นช่องว่างเดียว อ่าน
ไม่ออกว่าคั่นตรงไหน — เลี่ยงการแก้ CSS ของ Swal2 ทั้งแอป เพราะกว้างเกินขอบเขตงานนี้)

เพิ่ม lang key: `warning_bank_transfer_already_paid_notice`, `warning_bank_transfer_no_bank_employees` (th/en
ทั้งคู่) — `warning_bank_transfer_employees_excluded` เดิมคงอยู่ (ยังใช้อยู่จริง)

### เทสที่แก้/เพิ่ม

`tests/bank_transfer_export_paid_locked_test.php`: เพิ่ม helper `findWarning($result, $key)` (ค้นใน list
ใหม่) — อัปเดต Scenario 1-6 ให้เช็คผ่าน `findWarning()` แทน `warning_key`/`warning_params` ตรงๆ, ยืนยันว่า
S2/S3/S4/S5 (ทุกอันเป็น paid/locked) ได้ `bank_transfer_already_paid_notice` เสมอแม้ S2/S4 ไม่มีคนถูกข้ามเลย —
เพิ่ม **Scenario 7** (locked, 2 คน cash ล้วน → `bank_transfer_no_bank_employees` total=2 + already-paid-notice
ทั้งคู่พร้อมกัน) และ **Scenario 8** (approved, 1 คน cash ล้วน → `bank_transfer_no_bank_employees` total=1 เท่านั้น
ไม่มี already-paid-notice เพราะยังไม่ paid) — รวม **94/0** (จาก 66/0 เดิม)

`tests/payroll_run_payment_events_test.php`: assertion ท้ายไฟล์ (ที่เพิ่งแก้ในรอบแรกให้เช็ค "no warning")
ต้องแก้ต่ออีกรอบ เพราะตอนนี้ state=paid ต้องมี `bank_transfer_already_paid_notice` เสมอ — เปลี่ยนเป็นเช็คว่ามี
notice นั้นแต่ไม่มี `employees_excluded` — **31/0** (จาก 30/0 เดิม, จาก 27/0 baseline)

### run_all --compare ล่าสุด

`TOTAL 7964/2` (baseline `7866/2`) — diff อธิบายได้ครบทุกจุด: `bank_transfer_export_paid_locked_test.php`
ใหม่ (+94/0), `payroll_run_payment_events_test.php` 27→31 (+4/0) → รวม pass เพิ่ม `94+4=98`, ตรงกับ
`7964-7866=98` พอดี — fail ยังคงที่ 2 เท่าเดิม (`transaction_data_sync_test.php`/`import_test.php`, ทั้งคู่เป็น
known pre-existing flake ไม่เปลี่ยนแปลง ไม่โผล่ใน diff เพราะค่าเท่าเดิมทุกไบต์)

### smoke test บน dev — เลือก run ไหนสำหรับกรณี "ทุกคนเงินสด"

ตรวจจากโค้ด/SELECT อ่านอย่างเดียว (ไม่มี WITH, มี LIMIT, ไม่ดึง PII): **ไม่มีรอบไหนบน dev ที่ตรงเงื่อนไข "ทุกคน
ในรอบเป็น cash/check ล้วน" เลยสักรอบเดียว** (SELECT หา `run_id` ที่ state IN ('paid','locked') แล้ว
`bankish_count=0` คืนค่าว่างเปล่า) — **run 1015 ใช้ไม่ได้**: มีพนักงาน 1 คน `transfer` + 1 คน `cash` (ไม่ใช่
"ทุกคน" cash) ตรงนี้จะเข้าเงื่อนไข `bank_transfer_employees_excluded` (ถ้า cash คนนั้นมีเหตุผล skip จริง) ไม่ใช่
`bank_transfer_no_bank_employees` — สำหรับกรณีนี้ **แนะนำให้ผู้ใช้ดูผลจาก Scenario 7/8 ของเทสอัตโนมัติแทน**
(ครอบคลุมครบแล้ว, deterministic กว่าการหารอบจริงบน dev) — ถ้าต้องการเห็นบน UI จริง ต้องสร้างรอบทดสอบใหม่เอง
(ตั้งพนักงานทุกคนในรอบเป็น payment method "เงินสด" ก่อน mark paid) ซึ่งเป็นการเขียนข้อมูลที่ต้องให้ผู้ใช้ทำเอง
ตามกติกา "ห้ามรัน SQL ที่เขียนข้อมูล" ของ session นี้

**run 1014 + EM009 ขึ้นกับวิธีจ่ายที่บันทึกตอน markPaid จริงๆ**: SELECT ยืนยันว่า `payroll_run_payment_events`
ของ run 1014 ทั้งหมด (4 แถว, 2 คน คือ EM009+EM067) มี `payment_method='bank_transfer'` ล้วน — เพราะฉะนั้น
**EM009 ควรปรากฏในไฟล์พร้อมยอดที่โอนจริง** (ไม่ใช่กรณี no_amount) ตรงกับที่ระบุไว้ในรายการ smoke test เดิม — ถ้า
ผู้ใช้เจอว่า EM009 ไม่ขึ้น ให้สงสัยจุดอื่น (เลขบัญชี/รหัสธนาคารหาย) ไม่ใช่ปัญหาเรื่อง payment_method ของ event

## รอบวัด (round C, 2026-09-25 — UI regression + smoke test บน dev จริง)

**สถานะ**: ยังไม่แก้โค้ด production เพิ่มในรอบนี้เลย (ตาม "ห้ามแก้โค้ด production ในรอบนี้") — เป็นรอบวัด/ยืนยัน
ล้วนๆ บน 10 ไฟล์เดิมของรอบ B ที่ยังไม่ commit

### smoke test ของผู้ใช้บน dev — 5/5 ผ่าน

1014 และ 1015 export ไฟล์โอนเงินได้จริง (run 1014: 1 แถว EM009 ยอด 29,079.17 ตรงยอดสุทธิ) · modal คำเตือน
"รอบนี้บันทึกจ่ายแล้ว ... ป้องกันการโอนเงินซ้ำ" แสดงถูกทั้ง th/light และ en/dark · ตัวนับดาวน์โหลดเพิ่มขึ้นทุกครั้ง
(ยืนยันว่า `ReportExportLogModel::log()` เดิมไม่ได้รับผลกระทบจาก `warnings[]` ที่เพิ่มเข้ามา) · ภ.ง.ด.1 (รายงานอื่น
ที่ไม่ตั้ง `warnings`) ยังดาวน์โหลดได้ปกติโดยไม่มี modal เลย — ยืนยัน "ไม่กระทบรายงานอื่น" ตามที่ตั้งใจไว้

### ผล production ที่ยืนยันแล้ว

run id 4 (locked, `payroll_run_payment_events` ทั้งหมด `payment_method='bank_transfer'` 11 แถว รวม 5,195.00
— คือรอบ "ค่าเที่ยวเดือนสิงหาคม 2569" ต้นเรื่องของรายงานรอบ A) — หลังแก้ตามรอบ B **คาดว่า export จะได้ครบ 11 คน**
(ยืนยันจาก smoke test ของผู้ใช้เองแล้วว่า export ได้จริงไม่ error อีกต่อไป)

### ตารางค่าจริงเทียบค่าคาด (ทุก script)

| script | ค่าคาด | ค่าจริง | หมายเหตุ |
|---|---|---|---|
| m3e2a | 147/1 | 147/1 | fail `p10c` ข้อมูล dev ไม่พอ ตามที่รู้อยู่แล้ว |
| m3e2b | 315 | 315/0 | |
| slip2a | 68 | 68/0 | |
| k4a1 | 65 | 65/0 | |
| k4a2 | 300/8 | 300/8 | fail ทั้ง 8 ข้อความเดียวกันเป๊ะ ("each totals figure ends on the block's own inset (+-1px)") ครบ 8 cell (1400/430 × th/en × light/dark) — บั๊กของเทสเดิมที่รู้อยู่แล้ว |
| k4a2b | 216 | 216/0 | |
| k4c_employee_detail | 59 | 59/0 | |
| q_eed_row_actions | 59 | 59/0 | |
| r_table_dropdown_fixed | 36 | 36/0 | |
| l6a | 39 | 39/0 | |
| l6b | 117 | 117/0 | |
| tinyc | 17 | 17/0 | |
| h_history_table | 539 | 539/0 | |
| o_history_dt | 181 | 181/0 | flake c9 (4≠3) ไม่เกิดขึ้นรอบนี้ |
| s_run_detail_c3 | 29 | 29/0 | |
| m3e1 (ปกติ) | 241 | **รอบแรก 243/0** → **รอบซ้ำ 241/0** | ดูหัวข้อ "m3e1 รอบแรก 243/0" ด้านล่าง |
| n_missing_pull (sync) | 61 | 61/0 | |
| p_sync_not_participant (sync) | 53 | **รอบแรก 46/0** → **รอบซ้ำ 53/0** | ดูหัวข้อด้านล่าง (ความผิดพลาดของผู้ช่วย ไม่ใช่โค้ด) |
| m3e1 `--with-sync` | 246 | 246/0 | |

### m3e1 รอบแรก 243/0 — สืบแล้ว: ไม่ใช่โค้ด, เป็นข้อมูลทดสอบค้างจาก smoke test

`243/0` (ไม่ใช่ `241/0`) แต่**ไม่มี fail เลย** — สืบด้วย SELECT อ่านอย่างเดียว (`payroll_run_details`
JOIN `employees` JOIN `master_payment_methods`, ไม่ดึง PII) พบว่า `#run-bank-account-pane` บน run 1015 มีแถวจริง
1 แถว ปลดล็อก assertion แบบมีเงื่อนไข 2 ตัวในสคริปต์ (`m3e1_tabs_shared.js`'s cell 4: `list.length===0` → เช็ค
empty-state 1 จุด, `list.length>0` → เช็ค "row count matches"+"no badge" 2 จุด) — ต้นเหตุคือ **EM009 ยังถูกตั้ง
เป็น "โอนเข้าบัญชี" ค้างอยู่จากการ smoke test ของก้อนนี้เอง** ทั้งใน run 1014 และ 1015 (`code:"transfer",
has_bank_id:1, has_account_no:1` ทั้งคู่) — ไม่เกี่ยวกับไฟล์ที่แก้ในรอบ B/C เลย (`BankTransferFileReport.php`/
`ReportsController.php`/`app.js`/`PayrollReportDataModel.php` ไม่แตะ endpoint
`payroll-run-employee-bank-account.list` หรือ pane นี้เลย)

ผู้ใช้แก้ EM009 กลับเป็นเงินสดผ่านหน้า UI จริง (เห็น "บันทึกข้อมูลสำเร็จ" + Ctrl+F5 ยืนยัน) — SELECT ซ้ำยืนยัน
`code:"cash"` ทั้ง 1014/1015 แล้ว (แต่ `has_bank_id`/`has_account_no` ยังเป็น 1 ค้างอยู่ — ดู BACKLOG) — รัน
`m3e1` ซ้ำใน session เดิมได้ `241/0` ตรงค่าคาดพอดี — **ห้ามบันทึก 243 เป็นค่าคาดใหม่** ค่าคาดที่ถูกต้องคือ `241`

### p_sync_not_participant รอบแรก 46/0 — ความผิดพลาดของผู้ช่วย ไม่ใช่บั๊ก

`46/0` (ไม่ใช่ `53/0`) เพราะรันโดยไม่ได้ส่ง argv ตัวที่ 3 (`<withSyncRunToken>`, optional ตาม usage message
ของสคริปต์) — cell `c7` ถูก `SKIP` ไปเงียบๆ (log: "c7: skipped -- no fixture token given") ทำให้ assertion หาย
7 ตัว — รันซ้ำพร้อมส่ง `token` จาก session JSON ครบ ได้ `53/0` ตรงค่าคาด — บันทึกไว้เป็นบทเรียนการใช้เครื่องมือ
ของผู้ช่วยเอง ไม่ใช่ปัญหาของโค้ดหรือเทส

### run_all --compare และ baseline

`run_all --compare baseline-tiny2.json` ยังคงเป็น **7,964/2** เหมือนที่รายงานรอบ B (ก้อนนี้ไม่แตะโค้ด PHP เลย
จึงไม่ต้องรันซ้ำ) — **หลัง commit รอบนี้ต้องเก็บ baseline ใหม่** (`baseline-tiny2.json` ยังชี้ค่าก่อน round B/C
อยู่ — 7,866/2 เดิม) มิฉะนั้นรอบถัดไปจะเห็น diff ปลอมจากไฟล์เทส 2 ไฟล์ที่เพิ่ม/แก้ไปแล้วในรอบ B
