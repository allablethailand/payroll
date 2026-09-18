# Working rules (always apply)

## Git
- Never run git commit, git add, git stash, git checkout, or git reset.
- ถ้าต้องเทียบ test บน HEAD ให้ใช้ `git worktree add ../payroll-head HEAD` รันในนั้นแล้ว `git worktree remove`
  — ห้าม stash แม้จะสำรองไฟล์ไว้ก็ตาม
- Edit files, then stop and report: files changed + `git status` + `git diff --stat`.
- The developer reviews and commits manually.
- `.git/COMMIT_MSG_NEXT` must describe the ENTIRE uncommitted diff (`git diff HEAD`), not just the
  latest round. If a file already has uncommitted work from an earlier round sitting in it, write
  ONE combined message covering all of it, titled after the main/most recent task — never a message
  that only covers what changed in this round while leaving out what's already there uncommitted.
- COMMIT_MSG_NEXT: บรรทัดแรก = title, เนื้อความเป็นหัวข้อสั้น ไม่เกิน 30 บรรทัด — สิ่งที่เปลี่ยน + ไฟล์กลุ่มหลัก + ผล test;
  ไม่ต้องเล่าเหตุผล/การวัด/สิ่งที่ลองแล้วพัง (ของพวกนั้นอยู่ใน rules.md / docs/decisions แล้ว)

## Database
- Schema changes always go in `database/migrations/<date>_<desc>.sql` with UP and DOWN sections
  (e.g. `2026-08-29_add_xyz_column.sql`; more than one file the same day → append `_2`/`_3`).
  One file = one change/feature, never append multiple unrelated changes into one file.
- Never run ad-hoc DDL (CREATE/ALTER/DROP) against any database without a migration file
  backing it first — the file always comes before the DDL runs, never after or instead of it.
- Apply every migration through `php scripts/migrate.php up` only — never `mysql < file.sql` and
  never an ad-hoc PDO `exec()` of the file's contents. This is not optional style: `mysql < file.sql`
  runs the UP and DOWN sections of the SAME file back to back, which already broke production once
  (dropped the tables the UP section had just created). `scripts/migrate.php` is what correctly
  splits UP from DOWN, tracks what's already applied (`schema_migrations` table), and only ever
  runs a file's UP section, once.
- A migration that adds a FOREIGN KEY onto an *existing* column must NULL out orphaned values for
  that column first, in its own UP, before adding the constraint — never assume a value that's
  always valid in the local dev DB is equally valid in production; the ADD CONSTRAINT step must
  never be the first thing that discovers otherwise.
- You may apply migrations to the local dev DB only. Never to any other database.
- Report in the task summary that a migration was applied and how to roll it back.
- Report which tasks require a migration to be run before the code works.
- A release note's own deploy steps for a database that has run `scripts/migrate.php` before must
  say `php scripts/migrate.php status && php scripts/migrate.php up`, never a raw `mysql < file.sql`
  command. **First deploy to a NEW environment** (a fresh DB, or one this tool has never run
  against) is 4 steps, not 2 — write all 4 in the release note, don't shorten it to just `status`+
  `up`: (1) `php scripts/migrate.php status`, (2) manually check every file listed under "Unknown"
  against that environment's real schema, (3) `php scripts/migrate.php mark <file>` each one
  confirmed already applied (or apply it deliberately then `mark` it), (4) `php scripts/migrate.php up`
  — `up` refuses to run anything at all while any file remains unresolved in "Unknown", by design.
- Code that depends on a new column must fail safely or clearly if the
  migration has not been applied.

## Process
- Ask before guessing any business rule.
- Fix logic only unless explicitly told to change UI/style.
- One task at a time; stop and report after each.
- Code comments explain why in one line max. Longer rationale goes in the commit message.
- ทุกไฟล์ข้อความใน repo ต้องเป็น **LF** เท่านั้น (`.gitattributes`: `* text=auto eol=lf`) — ห้ามเขียนไฟล์กลับ
  เป็น CRLF ด้วยเหตุผลว่า "คง convention เดิมของไฟล์นั้น" — ไฟล์ที่เป็น CRLF ใน working tree คือของตกค้าง ไม่ใช่มาตรฐาน
- Never type a literal `?>` inside a comment or string in a `.php` file — PHP's lexer closes PHP mode
  the instant it sees that sequence anywhere in the file, comment or not, silently turning everything
  after it into raw output; `php -l` does not catch this. Hit twice for real in one session
  (`app/helpers/helpers.php`, once writing the bug's own explanation) before this rule was added.
  Mirror-image case, same rule: never type a literal `<?php`/`<?=` opening tag as prose text either
  (e.g. inside an HTML `<!-- -->` comment explaining what a tag below does) — PHP does not know it's
  "inside an HTML comment," it tries to parse whatever follows as real code. Hit for real in
  `docs/design/components.php` (an example snippet describing a cache-busting query string broke
  `php -l` the same way) — this time caught before commit.
- When asked for a summary or release note, derive it from git log, never from memory.
- Follow the given task order. Ask before reordering.
- Mirror-by-copy is not acceptable: if a new function duplicates an existing one except for a
  parameter, generalize the existing function instead.
- End every task report with a suggested commit message in conventional-commit format
  (type(scope): summary + 1-3 lines of why). The developer copies it when committing.
- Also write that same commit message to `.git/COMMIT_MSG_NEXT` (overwrite each time).
- ห้ามเพิ่มประวัติ/เหตุผลยาวใน CLAUDE.md — เรื่องแบบนั้นไปที่ `docs/decisions/<หัวข้อ>.md` แทน (CLAUDE.md เก็บเฉพาะกฎที่ต้องทำตามตอนนี้แบบสั้น + บรรทัดชี้ไปไฟล์รายละเอียด) — `tests/claude_md_size_test.php` fail ถ้า `CLAUDE.md` เกิน 80,000 ตัวอักษร

## ประหยัด context (กฎถาวร)
- อ่านไฟล์: เปิดเฉพาะช่วงบรรทัดที่ต้องใช้ ห้าม cat ทั้งไฟล์ที่ยาวเกิน 200 บรรทัด — grep ก่อนแล้วค่อยเปิดช่วงนั้น
- ห้าม print ผล test ระหว่างทาง — รันเฉพาะไฟล์ที่แตะ แสดงเฉพาะบรรทัดที่ fail; `run_all` แค่ตอนจบด้วย `--compare`
- Playwright: ก้อน logic/ฟอร์ม = 1400 th light + 430 th dark (+ en 1 จุดที่มีข้อความใหม่); ก้อน layout/CSS = ครบ 8 cell
- ห้าม dump DOM/HTML/screenshot/สตริงเข้ารายงาน — รายงานเป็นตัวเลข แนบสตริงจริงเฉพาะจุดที่ต่างจากที่คาด
- SELECT ตรวจสอบ: ใส่ LIMIT + เลือกคอลัมน์เสมอ ห้าม `SELECT *`
- วัดพลาด/timeout: หยุดหาสาเหตุก่อน ห้ามรันซ้ำแบบเดิมเกิน 1 ครั้ง
- รายงานท้ายก้อน ≤ 60 บรรทัด (ไม่นับตาราง); สรุปรอบอ่าน ≤ 10 บรรทัด
- ห้ามทวนกติกา/คำสั่งกลับมาในรายงาน; ห้าม echo ไฟล์ที่เพิ่งเขียน

---

# Origami Payroll — Project Guide

## Project
Origami Payroll — ระบบเงินเดือน multi-country (TH, SG, MY, US). โปรเจกต์ใหม่ทั้งหมด มีบาง feature สร้างไว้แล้วบางส่วน ที่เหลือให้ทำต่อทีละโมดูลตาม scan/roadmap ที่มีอยู่

## Stack
PHP 8.x, MySQL 8.x, Bootstrap 5, jQuery, SweetAlert2, CSS (custom, ไม่ใช้ CSS framework อื่นนอกจาก Bootstrap)

## Deployment Status & Database Migrations
- **เวอร์ชันนี้ขึ้นบน Production แล้ว (ยืนยันโดยผู้ใช้ 2026-08-28)** — `database/payroll.sql` ถือเป็น
  schema baseline ที่ตรงกับสิ่งที่ deploy จริงแล้ว ณ จุดนี้ (ใช้สำหรับติดตั้งใหม่/fresh install เท่านั้น
  จากนี้ไป)
- **ห้ามแก้ `database/payroll.sql` เพิ่มเติมสำหรับงานหลังจากนี้อีก** (ก่อนหน้านี้ทั้ง session ที่ผ่านมา
  ทุก schema change ถูก append เข้าไฟล์นี้ตรงๆ พร้อม comment ระบุวันที่ — เปลี่ยนวิธีตั้งแต่ตอนนี้)
  ถ้ามีงานที่เกี่ยวกับ Database (ALTER/CREATE TABLE/INSERT ข้อมูล master ใหม่ ฯลฯ) ให้:
  1. สร้างไฟล์ migration ตาม format ที่ระบุไว้ใน **## Database** section บนสุดของไฟล์นี้ (ชื่อไฟล์ +
     UP/DOWN section)
  2. รัน migration นั้นกับ DB จริงตามปกติ (เหมือนที่เคยทำมาตลอด session นี้ — ผ่าน PDO/PHP CLI หรือ
     mysql client โดยตรง)
  3. **ไม่ต้อง sync กลับเข้า `database/payroll.sql`** — ปล่อยให้ไฟล์นั้นหยุดนิ่งเป็น snapshot ของตอน
     deploy จริง ประวัติการเปลี่ยนแปลงหลังจากนี้ทั้งหมดอยู่ใน `database/migrations/` แทน (ไฟล์ละ 1
     การเปลี่ยนแปลง/1 feature ไม่ append หลายเรื่องปนกันในไฟล์เดียว)

## Code Convention
- ทุก query ต้องใช้ PDO prepared statement เท่านั้น ห้าม concatenate ค่าที่มาจาก user input ลงใน SQL ตรงๆ
- Controller/Model ใหม่ทุกไฟล์ต้องมี `declare(strict_types=1);`
- ชื่อฟังก์ชันกลางสำหรับ query: `select_data` / `insert_data` / `update_data` (implementation ใช้ PDO ภายใน) — **ยังไม่ได้ implement จริงในโค้ดปัจจุบัน** โมเดลที่มีอยู่เรียก `$this->db->prepare()/execute()` ตรงๆ ในแต่ละ method หากจะสร้าง helper กลางนี้ ให้ทำเป็นงานแยกและ migrate ของเดิมทีหลัง อย่าผสมสองรูปแบบในไฟล์เดียวกัน
- Soft delete pattern: ตารางที่มีการลบใช้ `status enum('active','inactive','deleted')` + `deleted_at`/`deleted_by` ไม่ hard delete ข้อมูล payroll/master data
- Unique constraint ที่มี `deleted_at` ร่วมด้วยต้องเช็คซ้ำระดับ application เสมอ (MySQL ถือว่า NULL แต่ละแถวไม่ซ้ำกัน composite unique key จึงกันแถว active ซ้ำไม่ได้จริง)
- ตารางที่ scope ด้วยบริษัทต้องมี FK `comp_id → companies.id` (`ON DELETE RESTRICT ON UPDATE CASCADE`)
- Testing: โปรเจกต์ยังไม่มี PHPUnit — ใช้ lightweight script ใน `tests/*.php` แทน (รันตรงด้วย `php tests/xxx.php`, print PASS/FAIL, exit code ไม่ใช่ 0 ถ้ามี fail) สำหรับ service class ที่ต้อง unit-testable (เช่น `StatutoryCalculationEngine`, export ใน `app/services/export/`) — ให้ constructor รับ `?PDO $pdo = null` เพื่อ inject ได้ และถ้า test เขียนลง DB จริงให้ครอบด้วย transaction แล้ว rollback เสมอ ไม่ทิ้งข้อมูลไว้ (ดูตัวอย่าง `tests/statutory_engine_test.php`)
- **Model method ที่ทำ multi-step write ต้องเช็ค `$this->db->inTransaction()` ก่อน `beginTransaction()` เสมอ** (pattern: `$own = !$this->db->inTransaction(); if ($own) beginTransaction(); ... if ($own) commit()/rollBack();`) ไม่งั้นถ้าถูกเรียกจากภายใน transaction อื่น (เช่น test script ที่ครอบทั้งไฟล์ด้วย transaction, หรือในอนาคตถ้ามี endpoint ที่เรียกหลาย model ต่อกันในทรานแซคชันเดียว) จะได้ PDOException "There is already an active transaction" แล้ว catch block จะ rollback transaction ของผู้เรียกโดยไม่ตั้งใจ ทำให้โค้ดหลังจากนั้นรันแบบ auto-commit จริงและหลุดออกนอก transaction ที่ตั้งใจไว้ (เจอบั๊กนี้จริงกับ `PayrollRunModel::recalculate/delete/markPaid` ตอนเขียน test — แก้ตามนี้แล้วทั้ง 3 method, และเจอซ้ำอีกรอบใน `EmployeeEarningDeductionModel::save()` ตอนเขียน test fixture ให้ `StudentLoanReport` — แก้ตามนี้แล้ว — เจอซ้ำอีกรอบใน `TaxStatutoryModel::rateHistorySave()` ตอนตรวจสอบ Payslip Format module (สืบไปเจอ TH_SSO/TH_PVD rate history ถูก soft-delete ในทางที่ไม่มีแถวใหม่มาแทน ทำให้ payroll calculation พังจริงใน dev DB — ไม่ยืนยันได้ 100% ว่าบั๊กนี้เป็นสาเหตุของ incident นั้นเป๊ะๆ เพราะ `rateHistoryDelete()` เองไม่ได้เปิด transaction แต่ `rateHistorySave()` มี bug pattern เดียวกันจริง — restore ข้อมูล 2 แถวนั้นแล้ว และ **แก้ `TaxStatutoryModel::rateHistorySave()` ตามนี้แล้ว** (2026-07-23))
- รายการตัวเลือกแบบ fixed/closed set ที่ "อาจมีเพิ่มทีหลังโดยไม่อยากแก้โค้ด" (เช่น ประเภทเหตุการณ์ที่ผูกกับรายการ payroll, รูปแบบไฟล์ธนาคาร) ให้ทำเป็น master table (`id`, `code`, `name_th`, `name_en`, `is_active`, ...) แล้วดึงผ่าน Select2 โหมด `ajax` แทนการ hardcode enum/array ในโค้ด — เพิ่มตัวเลือกใหม่ทีหลังแค่ insert แถวใหม่ ไม่ต้อง deploy โค้ด ตัวอย่าง: `master_payroll_source_events` (ผูก dropdown "Linked Attendance Event" ในหน้า Payroll Configuration, มีคอลัมน์ `applies_to` กรองว่าใช้ได้กับ earning/deduction/both), `master_bank_file_formats` (ผูก `payroll_cycles.bank_file_format_id`, ย้ายมาจาก hardcoded array ใน `PayrollCycleModel` ตอนทำ Reports module — ถ้าเจอ pattern แบบนี้ที่ยังเป็น PHP const array อยู่ที่อื่น ให้ถือเป็น candidate ย้ายเป็น master table เช่นกัน)
- **`StatutoryCalculationEngine::calculate()`/`calculateItem()` รับ `$employeeFlags` (param ตัวที่ 4, optional)** สำหรับเช็คสิทธิ์เข้าร่วมต่อรายบุคคล (เช่น `employees.sso_enrolled`/`pvd_enrolled`/`tax_exempt`) แยกจาก company-wide toggle ใน `company_statutory_settings` — ถ้าไม่ส่ง param นี้มาเลย (default `[]`) engine จะถือว่า enrolled ทุก item (backward compatible) แต่ **`PayrollRunModel::recalculate()` ส่ง flag จริงจาก DB เสมอ** ห้ามลบทิ้งเวลาแก้โค้ดคำนวณ payroll ไม่งั้นพนักงานที่ opt-out ประกันสังคม/ไม่ได้เข้ากองทุนสำรองเลี้ยงชีพ จะโดนหักเงินผิดอีกครั้ง (เป็น bug จริงที่แก้ไปตอนทำ Reports module — root cause คือ engine เดิมคำนวณ statutory item เดียวกันทุกคนในบริษัทแบบไม่แยกรายคน)
- **`PayrollRunModel::recalculate()` เลือกพนักงานเข้า run ด้วยช่วงวันที่ (`employment_date`/`employment_end_date`) เท่านั้น ไม่เช็ค `employees.employee_status`** เพราะพนักงานที่เพิ่งลาออกมักถูกเปลี่ยน status เป็น `resigned` ทันที แต่ยังต้องปรากฏใน run ของงวดสุดท้ายที่ยังจ่ายเงินให้ (pro-rate ถึงวันที่ `employment_end_date`) — ถ้าเพิ่ม filter ด้วย status จะทำให้พนักงานที่เพิ่งลาออกหายไปจาก run สุดท้ายของตัวเองผิดพลาด
- **`PayrollRunModel::recalculate()` ดึง Recurring Earnings (`EmployeeRecurringEarningModel::activeForPeriod()`) เป็น earning line เพิ่มเติมทุกรอบ** (2026-08-26, explicit request: "รายรับที่ได้ทุกเดือนเช่นพวกค่าตำแหน่ง ค่ารถ ค่าน้ำมัน...ให้เพิ่มส่วนนี้เข้าไปด้วย และระงับการจ่ายได้ รวมถึงการตั้งค่าส่วนนี้เพิ่มเติมให้นำไปคำนวณในรอบการจ่ายด้วย") — คนละตารางกับ `employee_earning_deductions` (เงินกู้/ผ่อนส่งแบบมีจำนวนงวดตายตัว) เพราะเป็นรายรับที่จ่ายซ้ำไม่มีกำหนดสิ้นสุดจนกว่าจะระงับ/ลบ ดูรายละเอียดสถาปัตยกรรมเต็มใน `EmployeeRecurringEarningModel`'s own docblock — **ข้าม branch นี้ทั้งหมดสำหรับ incentive/off-cycle run** (manually-picked items only) เหมือนกับ PED assignments ด้านบน — "ระงับ" เป็นช่วงวันที่ (`suspended_from`/`suspended_to`, ต้องตั้งคู่กันเสมอ) ไม่ใช่ toggle เปิด/ปิดธรรมดา ตัดออกจาก run ใดๆ ที่ pay period ทับซ้อนกับช่วงระงับแม้เพียงบางส่วน แล้วกลับมาจ่ายเองอัตโนมัติเมื่อ run นั้นเลยช่วงระงับไปแล้ว ไม่ต้องมีขั้นตอน re-activate แยก — ตั้งค่าที่หน้า Employee Detail's Salary tab (section ใหม่ "Recurring Allowances") เลือกจาก catalog `payroll_earning_deduction_types` เดิม (จำกัดแค่ `item_type='earning' AND calculation_method='fixed_amount'`) — ทดสอบด้วย `tests/employee_recurring_earning_test.php` (model-level, 34 assertions) + `tests/payroll_run_test.php`'s "2026-08-26" section (integration ผ่าน run จริง ยืนยันทั้ง included/excluded/resumed)
- **ห้ามพิมพ์ `*/` (หรือชื่อ token/wildcard ที่ลงท้ายด้วย `*` แล้วตามด้วย `/` ทันที เช่น `--xxx-*/--yyy-*`) เป็นข้อความปกติภายใน CSS comment (`/* ... */`)** — `*` ตามด้วย `/` ติดกันคือตัวปิดคอมเมนต์ CSS เสมอ ไม่ว่าจะตั้งใจให้เป็นแค่ข้อความหรือไม่ คอมเมนต์จะปิดกลางประโยคทันทีแล้วเนื้อหา CSS จริงหลังจากนั้น (จนถึง `*/` ตัวถัดไป) จะถูก parse เป็นโค้ดจริงแทน — `php -l` ตรวจไม่เจอ (เป็นไฟล์ `.css` ไม่ใช่ `.php`) ต้องดูด้วยตา/รันเทสหรือเปิดเบราว์เซอร์จริงเท่านั้น เจอบั๊กจริงกับ `public/css/tokens.css` (2026-09-14): comment อธิบาย dark-mode เขียน `--sp-*/--fs-*` ทำให้ rule `[data-bs-theme="dark"] {...}` ทั้งก้อนถูกเบราว์เซอร์ทิ้งเงียบๆ มาตั้งแต่สร้างไฟล์ — พนักงานที่เลือก Dark theme เองไม่เคยได้ `--c-*` token สีมืดเลยสักตัว มีแค่ System dark mode ที่ทำงานถูก — คั่นชื่อ token ด้วย comma แทน `/` ถ้าต้องเขียนรายการ token ในคอมเมนต์ — ตรวจอัตโนมัติด้วย `tests/css_parse_test.php`
- **`PayrollRunModel::update()`'s incentive-purpose branch (`compute_statutory`/`include_base_salary`/`include_standing_items`/`include_attendance_pay`/`use_flat_tax_rate`) ต้องเช็ค `array_key_exists($key, $data)` ก่อนเสมอ ห้ามใช้ `!empty($data[$key]) ? 1 : 0` เปล่าๆ โดยไม่เช็ค presence ก่อน** — คีย์ที่ "ไม่ถูกส่งมาเลย" ต้องแปลว่า "คงค่าที่ run มีอยู่เดิมไว้" ไม่ใช่ "reset เป็น 0" เพราะ `!empty()` เพียงอย่างเดียวมองไม่เห็นความต่างระหว่าง 2 กรณีนี้ (เจอบั๊กจริง 2026-09-09: ตอน `use_flat_tax_rate` เพิ่งมีแค่ใน Create form ยังไม่มีใน Edit form เลย ทุกครั้งที่ edit-save รอบ off-cycle/incentive ใดๆ ค่า flat-tax ที่เคยติ๊กไว้จริงจะถูกรีเซ็ตเป็น 0 แบบเงียบๆ ทันที — กระทบพฤติกรรมหักภาษีจริงโดยไม่มี UI แจ้งเตือนใดๆ) ถ้าจะเพิ่ม calc flag ตัวใหม่ (ตัวที่ 6 เป็นต้นไป) เข้า group นี้ ให้ตามรูปแบบเดียวกัน: `array_key_exists('key', $data) ? (!empty($data['key']) ? 1 : 0) : $currentValue` เสมอ

## UI Convention
อ่าน `docs/design/rules.md` ก่อนแตะ UI (source เดียวของสี/ปุ่ม/badge/tabs/modal/feedback) — ประวัติ/บั๊ก: `docs/decisions/ui-convention.md`

- **Dropdown**: ทุก `<select>` init ผ่าน `initSelect2(selector, options)` (`public/js/input.js`) — 3 modes: `ajax` (คืน `{data:{items:[{id,text_th,text_en}],total_count}}`), `static` (`data-option-keys`, เช็ค key มีใน lang json ทั้งคู่ก่อนใช้ — backend ต้องการ value ต่างจาก key ใส่ `data-option-values` เรียงตำแหน่งตรงกัน), `native` (ยังต้อง init ผ่านฟังก์ชันนี้)
- **Datepicker** (`.datepicker`, `initDatepicker()`): set ค่าด้วย `.val(...)` ตรงๆ ต้องตามด้วย `.datepicker('update')` เสมอ ไม่งั้น widget state desync
- **Date display**: ใช้ `formatDisplayDate()`/`formatDisplayDateTime()` (app.js) หรือ local `toDisplayDate*()` ที่มีอยู่แล้ว — ห้าม render ISO ดิบ — statutory export ห้ามแตะ
- **Tab**: top-level = `.nav-tabs.setup-tabs` + `.nav-link.setup-menu`; sub-tab pill = `.structure-tabs-wrap > .nav-pills.structure-tabs` + `.nav-link.structure-menu` — ห้ามสร้าง pill CSS แยกใหม่ reuse `.structure-tabs`
- **Table**: ทุกตารางใช้ DataTables ผ่าน `initSharedDataTable()` (server-side = list ไม่จำกัด, client-side = list เล็ก) — ปุ่ม Add inject ผ่าน `initComplete` เข้า `.dt-search`; Edit ดึงสดจาก API เสมอ (ห้ามอ่าน cache DOM แถว); save/delete reload ด้วย `table.ajax.reload(null,false)` — ทุก `<th>` ที่มีข้อมูลจริงต้องมี sort+filter ผ่าน `columnFilters` option ยกเว้นคอลัมน์ปุ่ม/widget ภาพ/ตารางที่มี top-level filter — คอลัมน์ format ต่างจากค่าจริงต้องใช้ `render:{display,sort,filter}` เสมอ — **`data-i18n` ต้องอยู่บน `<span>` leaf ข้างใน `<th>` เสมอ ห้ามอยู่บน `<th>` ตรงๆ** (DataTables wrap child ของทุก `<th>` เข้า `.dt-column-title` เสมอ)

## Design

> **บังคับ: ก่อนแตะ view/CSS/JS ที่ render UI ต้องอ่าน `docs/design/rules.md` ทั้งไฟล์ทุกครั้ง และรายงานอ้าง § ของกฎ** — เอกสารนี้ (`## Design` ใน CLAUDE.md) เป็นแค่สรุปหลักการ + ตาราง shared component เท่านั้น `docs/design/rules.md` คือฉบับเต็ม (โครงสร้างหน้า/สี/ปุ่ม/badge/tabs/ตาราง/ตัวเลข/modal/feedback/lint/กระบวนการ phase design — §1–§13)

> **`rules.md` เก็บเฉพาะกฎ (สิ่งที่ต้องทำ) ห้ามใส่ตัวเลขที่วัดได้/สภาพก่อนแก้/จำนวนจุดที่ทดสอบ — ประวัติไปที่ `docs/decisions/`**

### หลักการ (§0 ของ `docs/design/rules.md`, อ่านก่อนทุกครั้ง)

1. **หน้าจอต้องเงียบ** — สีมีหน้าที่บอกว่า "ทำอะไรต่อ" หรือ "ต้องตัดสินใจอะไร" เท่านั้น ถ้าสีไม่ได้ตอบสองคำถามนี้ ให้เป็นเทา
2. **1 หน้า/1 modal = 1 action หลัก** — มีปุ่มส้มได้ตัวเดียว ถ้าหาไม่เจอว่าตัวไหนคือ action หลัก ให้ถาม
3. **โครงสร้างต้องสื่อข้อมูล ไม่ใช่ตกแต่ง** — เส้น กล่อง การ์ด ไอคอน ตัวเลขลำดับ ใช้เมื่อมันบอกอะไรที่ผู้ใช้ต้องรู้ ถ้าเอาออกแล้วความหมายไม่เปลี่ยน ให้เอาออก
4. **ซ้ำ = shared** — ถ้า markup/JS แบบเดียวกันจะปรากฏใน 2 ที่ ต้องเป็น partial/helper ตัวเดียว (กฎ "ห้าม mirror-copy" เดิมใช้กับ UI ด้วย)
5. **คำในหน้าจอเป็น design content** — ปุ่ม/หัวข้อ/toast ใช้คำเดียวกันตลอด flow ("บันทึก" → toast "บันทึกแล้ว" ไม่ใช่ "สำเร็จ"), ประโยคเดียว, active voice, ไม่มีคำเติม
6. **ห้ามเดา design** — เจอกรณีที่กฎไม่ครอบ ให้เสนอ 2–3 ตัวเลือกพร้อมภาพ/ASCII แล้วหยุดถาม ไม่ตัดสินเอง
7. **phase design ห้ามแก้ logic** — ถ้าระหว่างจัด UI เจอบั๊ก logic ให้จดลง BACKLOG.md แล้วทำต่อ ไม่แก้ปนกัน (diff ของ design pass ต้องเป็น view/CSS/JS-render เท่านั้น)

### Shared components (§11 ของ `docs/design/rules.md`) — ต้องใช้ ห้ามเขียนเอง

| ชื่อ | ที่อยู่ | แทนของเดิม |
|---|---|---|
| `page-header.php` | `app/views/partials/` | การ์ดหัวหน้าทุกหน้า |
| `stat-card.php` | partials | การ์ดตัวเลขขอบสี |
| `filter-bar.php` | partials | filter กางค้าง |
| `status-stepper.php` + `renderStatusStepper()` | partials + app.js | กล่อง 5 สี |
| `statusBadge()` / `statusBadgeHtml()` + `status_map.php` (rename `payroll-configuration.js`'s local `statusBadge(row)` → `pcRowStatusBadge(row)` ก่อนประกาศ global — ดู rules.md §5) | helpers + app.js + config | map สถานะกระจาย |
| `initSharedDataTable()` (ขยาย: layout, export, fixed column, columnDefs alignment, ครอบ `initExcelColumnFilters()` ให้เอง) | app.js | init ตรงทุกหน้า |
| `fmtMoney()` / `formatMoney()` / `initMoneyInputs()` | helpers + app.js | number_format กระจาย |
| `apvAvatarHtml()` / `apvPersonLineHtml()` | app.js (มีแล้ว) | avatar เขียนเอง |
| `emp-header-card` (style ตาม §9) | modals (มีแล้ว) | หัว modal ธง+ไอคอน |
| `isFormDirty()` / `confirmIfDirtyThen()` | app.js (มีแล้ว) | ผูก dirty-check เองทีละ modal — ไม่สร้าง `guardDirtyModal()` ใหม่ |
| `showConfirm()` (ขยายรับ object form) / `showSuccess` / `showError` | app.js/alert.js (มีแล้ว) | Swal.fire ตรง — ไม่สร้าง `confirmAction()` ใหม่ |
| `resetModalTabs()` | app.js (มีแล้ว) | strip class เอง |
| `payslipViewHtml()` | app.js | modal คำนวณแบบตาราง (PHP twin `payslip-view.php` ถูกลบแล้ว 2026-09-16 — `docs/decisions/remove-payslip-view-php-twin.md`) |
| `payee-destination.php` + `initPayeeDestination()` | partials + app.js | payee picker ที่เขียนเองทีละที่ |

เพิ่ม component ใหม่ต้องเสนอชื่อ + API + ที่ใช้ ≥ 2 จุด ก่อนเขียน — ดูรายละเอียดเต็ม (tokens, สี, ปุ่ม, badge, tabs/stepper/filter bar, ตาราง, ตัวเลข, modal/ฟอร์ม, feedback, lint, กระบวนการ 4 รอบ) ใน `docs/design/rules.md`

## Business Model — 3 ประเภทผู้ใช้
1. **Origami HR user** — sync attendance/leave/holiday/OT/ค่าเที่ยว อัตโนมัติจากโมดูล HR
2. **External HR user** — Import ผ่าน Excel/CSV template + validation + field mapping
3. **No HR user** — manual entry เต็มรูปแบบ

ทุก entity ที่เกี่ยวกับ attendance/leave/OT ต้องมี field `data_source` (`sync` / `import` / `manual`) เพื่อบอกที่มาของข้อมูล

## Authentication (Origami SSO, `auth/index.php` + `auth/switch.php`)
- **Login เป็น one-way SSO handshake เท่านั้น** — Origami redirect มาที่ `BASE_URL/auth/?token=...`, `auth/index.php` เอา token ไป sign เป็น JWT ยิงไปที่ Origami's `/api/oauth/v2/auth`, ได้ identity กลับมาแล้ว map เข้ากับ `companies`/`employees` ของแอปนี้เอง (auto-provision แถวใหม่ถ้ายังไม่เคย sync มาก่อน ดู comment ยาวในไฟล์นั้นเอง) แล้วตั้ง `$_SESSION['user']` — เป็น session ของ Payroll เองล้วนๆ **ไม่มี token/expiry เชื่อมกับ Origami อีกเลยหลังจากขั้นตอนนี้** `ensure_login()` (`app/helpers/helpers.php`) เช็คแค่ว่า `$_SESSION['user']` มี `employee_id`/`company_id` ในเซสชันของตัวเองหรือไม่ ไม่เคยเช็คย้อนกลับไปที่ Origami เลยว่า session ฝั่งนั้นยังใช้ได้อยู่ไหม
- **`auth/index.php`/`auth/switch.php` เป็นไฟล์ standalone อยู่นอก Router** (`.htaccess` เช็ค `!-f`/`!-d` ก่อน rewrite เข้า `index.php?url=...` — เพราะ `auth/` เป็นโฟลเดอร์จริงที่มีไฟล์อยู่ Apache เลยเสิร์ฟตรงไม่ผ่าน Router/Controller) ห้ามย้ายเข้า MVC ปกติโดยไม่ปรับ `.htaccess`
- **2026-08-26, real bug found and fixed (explicit bug report: "หลังจากที่ Switch App กลับไปใช้งาน Origami Refresh หน้า Payroll แล้ว Session ไม่ตัด")** — ก่อนหน้านี้แอปนี้ **ไม่มี logout mechanism เลยแม้แต่จุดเดียว** (`.nav-profile-link` ในเมนูเป็น `href="#"` เปล่าๆ ไม่มี dropdown/logout) และปุ่ม "Switch App" (ไอคอน Hub มุมขวาบน, `layout/header.php`) เดิม `<a href>` ชี้ไปที่ URL ของ Origami เอง (`ORIGAMI_BASE_URL/api/oauth/v2/switch?token=...&app=...`) ตรงๆ — เป็นแค่การ navigate ข้าม origin เฉยๆ ไม่มีจุดไหนสั่งทำลาย session ของ Payroll เองเลย พอกลับมาที่แท็บ Payroll แล้วรีเฟรช เจอ session เดิมที่ยัง valid อยู่ ก็ล็อกอินเข้าได้ทันทีโดยไม่ต้อง login ใหม่ — ยืนยันด้วยการยิง HTTP จริงผ่าน curl พร้อม session cookie ก่อน/หลังแก้ ไม่ใช่แค่เดา
  - แก้โดยเพิ่ม **`auth/switch.php`** (ไฟล์ standalone อยู่ระดับเดียวกับ `auth/index.php`) — อ่าน `$_SESSION['origami_switch_token']` (ที่ `auth/index.php` เก็บไว้ตอน login) จาก session ฝั่ง server เอง (ไม่ผ่าน query string อีกต่อไป ลดโอกาส token หลุดไปอยู่ใน page source/referrer) ทำลาย session ของ Payroll เองให้ครบ (`$_SESSION = []` + expire cookie + `session_destroy()`) **ก่อน** ค่อย redirect (302) ไปที่ URL switch จริงของ Origami — `layout/header.php`'s hub menu link เปลี่ยนจากชี้ตรงไปที่ Origami เป็นชี้มาที่ `BASE_URL/auth/switch?app=...` (ไม่มี token โผล่ใน HTML ที่ Payroll เอง render ออกมาอีกต่อไป) แทน
  - **ยังไม่มีปุ่ม "Logout" แบบทั่วไป** (ไม่ผูกกับการ switch app) เพราะ user report รอบนี้เจาะจงที่ปัญหา switch-app เท่านั้น — ถ้าต้องการ logout button แยกต่างหากใน `.nav-profile-link` (ที่ยังเป็น dead `href="#"` อยู่) ต้องทำเป็นงานแยก แต่ backend (`session` teardown pattern เดียวกับใน `auth/switch.php`) พร้อมใช้ซ้ำได้ทันทีถ้าต้องการ
  - Verified end-to-end ผ่าน HTTP จริง (ไม่ใช่แค่ PHP CLI simulation): สร้าง session จริงบนดิสก์ → ยิง `GET auth/switch?app=...` ผ่าน curl พร้อม cookie ของ session นั้น → ยืนยัน `302` ไป Origami switch URL ที่ถูกต้อง + `Set-Cookie: PHPSESSID=deleted` + ไฟล์ session หายจากดิสก์จริง → ยิง request ถัดไปด้วย cookie เดิมไปที่หน้าที่ต้อง login (`/dashboard`) ยืนยันโดน redirect ไป `/auth` ทันที (ไม่ใช่แค่ session data ว่างเปล่าแต่ cookie ยังผ่าน `ensure_login()` ได้)

## Output Requirement (Compliance)
ประวัติ/เหตุผลแบบเต็ม: `docs/decisions/output-requirement.md`

- Export ภ.ง.ด.1/ภ.ง.ด.1ก + สปส. 1-10 ตามฟอร์แมตราชการ — config ได้ต่อประเทศ (TH/SG/MY/US)
- **Statutory export** (`app/services/export/`): `StatutoryExportInterface` + `StatutoryExportRegistry` (lookup โดย code) + implementation ต่อประเทศใน `{country}/` — เพิ่มฟอร์แมตใหม่ = class ใหม่ + register ใน `init()` บรรทัดเดียว — `FixedWidthHelperTrait` = fixed-width byte-based padding (TIS-620 1 byte/ตัวอักษร)
- **`PndOneKorExporter`/`Sso110Exporter` ยัง DRAFT, `isVerified()`=false — ห้ามใช้ output จริงยื่นจนกว่าจะเทียบเอกสารทางการแล้ว**
- **Reports module** (`app/services/reports/`): `ReportGeneratorInterface` (`code()`,`reportType()`,`label()`,`isVerified()`,`supportedFormats()`,`generate()`) + `ReportRegistry` + แยกโฟลเดอร์ตาม type (`statutory/{country}/`,`payment/`,`internal/`) — 10 รายงาน: statutory 6 (`TH_PND1`,`TH_PND1K_SUMMARY`,`TH_SSO110`,`TH_SSO609`,`TH_KOR20KOR`,`TH_SLF`), payment 3 (`PAY_SLIP`,`BANK_TRANSFER_FILE`,`PAYMENT_VOUCHER`), internal 1 (`PAYROLL_REGISTER`) — เพิ่มใหม่ = class ใหม่ implement ครบ (รวม `isVerified()`) + register บรรทัดเดียว
- **`TH_PND1K_SUMMARY` คือใบแนบ ภ.ง.ด.1ก แล้ว** ไม่มี report แยก — **`TH_SLF` (กยศ.)** ผูกผ่าน `payroll_earning_deduction_types.statutory_report_code` (ตั้งค่าที่ Payroll Configuration > Earning-Deduction Types) ไม่ใช่ statutory calc item — ถ้าไม่ tag ไว้ throw `RuntimeException`
- `PayrollReportDataModel` = query กลางทุกรายงาน — `assertRunState()` บังคับ statutory/payment report อ่านจาก `payroll_runs.state` = approved/paid/locked เท่านั้น (internal preview จาก draft ได้) — ยกเว้น `Sso609Report` ไม่มี state gate
- ใช้ `dompdf/dompdf` + `phpoffice/phpspreadsheet` — PDF ไทย (DejaVu Sans) verify ได้แค่ generate ไม่ error, verify ด้วยตาไม่ได้ในสภาพแวดล้อมนี้ — **`ExcelRendererTrait::renderExcelFromRows()` ต้องใช้ `setCellValueExplicit(...,TYPE_STRING)` ทีละ cell ห้ามใช้ `fromArray()` ตรงๆ** (กิน leading zero ของเลขบัญชี/ประกันสังคม)
- `BankTransferFileReport` = generic CSV เดียว ยังไม่แยกตาม bank format จริง — พนักงานข้อมูลธนาคารไม่ครบถูกข้ามพร้อม comment ในไฟล์ ไม่หายเงียบๆ — `Sso609Report`/`Kor20KorReport` (DRAFT) ไม่มี format `txt`
- ไม่มี `report_schedules` table (on-demand เท่านั้น) — `report_export_logs` = audit log อย่างเดียว — **ทุก report generator ต้อง validate `context` เอง** throw `InvalidArgumentException`/`RuntimeException` ห้าม generate ไฟล์เปล่าเงียบๆ
- ทดสอบ: `tests/statutory_export_test.php`, `tests/reports_test.php`

## Approval Workflow (`Document & Approval` menu → "ลำดับผู้อนุมัติ"/"ติดตามสถานะ" tabs)
ประวัติ/เหตุผล/บั๊กที่เจอแบบเต็ม (rework 3 รอบ, admin-bypass fix 2 รอบ, revert-to-chosen-status): `docs/decisions/approval-workflow.md`

- Schema (8 ตาราง): `approval_document_types` (master, global) → `approval_workflows` (header ต่อบริษัท, soft delete) → `approval_workflow_document_types` (join) → `approval_workflow_steps` (soft delete, diff-based `save()`: no-op ถ้าเนื้อหาเหมือนเดิม, ไม่เหมือนก็ soft-delete ชุดเดิม+insert ใหม่ทั้งชุด — `step_order` derive จากตำแหน่งใน array เสมอ ไม่เชื่อ client) → `approval_workflow_step_approvers` (**1 step มีหลาย approver ได้**, `approver_id` polymorphic: `employees.id` ถ้า `approver_type='user'`, `structure_roles.id` ถ้า `'role'`, validate ผ่าน `validApproverRef()`) → `approval_requests` (instance) → `approval_request_step_approvers` (**eligibility resolve ครั้งเดียวตอนสร้าง request แล้ว persist — อ่านจากตารางนี้เท่านั้นหลังจากนั้น ห้าม live-query role membership ซ้ำ**) → `approval_request_logs` (audit trail)
- `joint_approve_mode` ('any'/'all') ต่อ step: any=ใครกดก่อนตัดสิน, all=ต้องครบทุกคนใน pool
- `requires_previous_step` ต่อ step (ไม่ใช่ workflow เดียวกันทั้งก้อน) คุม sequencing แบบอิสระ — step ที่ 0 กดได้ทันทีเสมอ
- ผลรวมทั้งใบคำนวณจาก `group_type` ต่อ step ('and'/'or'/'finish') ผ่าน `recomputeVerdict()` — and=ทุก step ต้องผ่าน reject 1 step=จบ, or=count 1 ไม่มีผล/2+ ผ่านได้ทันทีที่มี 1 step approve, finish=ตัดสินจบทั้งใบทันทีไม่สนใจ step อื่น
- `current_step_order` เป็น display-only (ไม่ gate อะไร) — timeout/escalation ถูกถอดออกจากสคีมาทั้งหมดแล้ว
- **`ApprovalRequestModel::canActOnRequestNow()` (เข้ม: eligible+pending+unlocked) ต้องใช้ gate ปุ่มตัดสินใจ (Approve/Reject/Request Info)** — `canActOnRequest()` (หลวมกว่า) ใช้กับ revert/undo เท่านั้น — **admin session ไม่ bypass เมื่อ run มี workflow active ครอบอยู่จริง** (bypass ได้เฉพาะตอนไม่มี workflow active — flat `structure_roles.can_approve_payroll` fallback)
- `PayrollRunModel::list(..., approvalQueueOnly: true)` กรองทิ้งแถว pending ที่ user กดไม่ได้จริง ไม่ใช่แค่ซ่อนปุ่ม — ผูก flow จริงกับ `PAYROLL_RUN_APPROVAL`/`SLIP_REQUEST_APPROVAL`/`EMPLOYMENT_CERTIFICATE_APPROVAL`
- `revert(..., ?string $toState)`: pending_approval→draft พฤติกรรมเดิม; approved/rejected/need_info เลือกปลายทางได้ (`pending_approval`/`rejected`/`need_info`) แต่ต้องไม่ใช่ state เดิม
- `document_type_code` ผูกได้แค่ 1 workflow `status='active'` ต่อบริษัทพร้อมกัน (app-layer check) — `duplicate()` บังคับ `status='inactive'` เสมอ — `delete()` soft delete, บล็อกถ้ามี pending request อ้างอิงอยู่
- UI: 2 pill คงที่ต่อ document type, แก้ทีละแถว (`stepSave`/`stepDelete`/`stepsSort`) ไม่มี modal, SortableJS drag
- ทดสอบ: `tests/approval_workflow_test.php`

## Setup & Rules (`Time & Leave` menu → 5 แท็บ: Shift/Holiday/Leave Type/OT Rate/Work Location)
ประวัติ/เหตุผลแบบเต็ม: `docs/decisions/setup-rules.md`

- Backend เต็มรูปแบบใน `SetupRulesModel`/`SetupRulesController` ครบทั้ง 5 แท็บ
- **OT Rate** (`ot_rates` + `master_ot_scope_types`): "Applies To" เป็น master table (seed 3 ค่า) — `calculation_base` เป็น enum('hourly','daily') ธรรมดา ไม่ใช่ master table — ไม่ผูก scope กับ employee/shift — **ยังไม่ enforce ขั้นต่ำตามกฎหมายแรงงาน** เป็นแค่ config tool — ทดสอบ `tests/ot_rate_test.php`
- `shifts`/`master_work_locations` เป็น dependency ของ `employees.shift_id`/`work_location_id` (มี FK จริง `ON DELETE SET NULL`, dropdown ต่อแล้ว)
- **Holiday** (`holidays`+`holiday_assignments`): `assignment_mode` ('include'=whitelist/'exclude'=blacklist) — scope polymorphic validate ผ่าน `validateScopeRef()` — priority ชนกัน: employee > position > department > shift ผ่าน `resolveHolidaysForEmployee()` (**ยังไม่มี consumer จริง**) — date-collision check เป็น scope-blind — `country_code` ดึงจาก `companies.registered_country` อัตโนมัติ ไม่ใช่กรอกเอง
- **Leave Type** (`leave_types`+`master_leave_categories`[seed 10]+`master_statutory_leave_minimums`): `master_statutory_leave_minimums` เป็น global reference (ไม่ผูกบริษัท) เช็คเฉพาะ `unit_type='day'` — **seed แค่ TH annual(6)/maternity(98) เป็น DRAFT ยังไม่ verify** SG/MY/US ไม่ seed
- Shift↔Employee: ปุ่ม "Assign Employees" → `shiftAssignEmployees()` replace ทั้งชุด (ไม่ diff ทีละคน)
- DataTable ทุกตารางในหน้านี้ใช้ default `dom` (native search) + ปุ่ม Add inject เข้า `.dt-search` — ห้ามใช้ custom toolbar แยก
- ทดสอบ: `tests/holiday_test.php`, `tests/setup_rules_phase3_test.php`
- **RBAC** (`permissions`/`role_permissions` ผูก `structure_roles`/`employees.role_id`): `PermissionModel::checkPermission($employeeId,$permissionKey,$isAdmin,$compId)` = coarse gate เดียวที่ใช้ทั้งระบบ — `$isAdmin` bypass ทุกอย่าง — enforced ใน `SetupRulesController` (`holiday.*`/`leave_type.*`) และ `ApprovalWorkflowController` — `allow_scope='own_department'` บังคับใช้จริงแค่ `approval_request.act` เท่านั้น — UI = tab "Permissions" ใต้ Organizational Structure (checkbox matrix, ไม่ใช่ DataTable) — ทดสอบ `tests/permission_matrix_test.php`

## Team (Organizational Structure → 7th sub-tab "Team", `structure_teams`; also on Employee list/detail)
ประวัติ/เหตุผลแบบเต็ม: `docs/decisions/team.md`

- บริษัทที่ใช้แอปนี้เป็น outsourcing/staffing firm — "Team" คือกลุ่มพนักงานที่ส่งไปอยู่กับ client/project เดียวกัน คนละแกนกับ Department/Position ในองค์กรตัวเอง
- Schema: `structure_teams` เหมือน `structure_departments` (code/name_th/name_en, per-company, soft-deletable) + `client_name` (free text, nullable, ไม่แยก _th/_en) — `employees.team_id` nullable FK `ON DELETE SET NULL ON UPDATE CASCADE`
- Backend **100% additive ผ่าน generic dispatcher เดิม ไม่มี CRUD engine ใหม่**: `CompanyProfileModel::structureConfig()` เพิ่ม entry `'team'` (ใช้ `saveStructure()`/`deleteStructure()`/`isStructureValueDuplicate()` เดิม), `MasterModel::master()` เพิ่ม case `'team'` (backs dropdown `data-api="/api/team.get"`) — routes: `api/structure.team`(.save/.delete), `api/team.get`
- Employee Detail: dropdown **ไม่ required** (ไม่อยู่ใน `requiredColumns()`/`completenessColumns()`/`requiredFieldTabs()`) — **`team_id` ต้องอยู่ทั้งใน `remoteFields` array และมี `populateSelect2Field('team_id',...)` เรียกตรง** ไม่งั้นเจอบั๊ก silent-data-loss เดียวกับ `work_location_id`/`shift_id` (select2-remote ไม่มี option preload แล้ว `.val(id)` เฉยๆ จะเงียบหาย)
- Employee List: filter dropdown + Team column (หลัง Department) — เพิ่มคอลัมน์กลาง list ต้อง shift `sortColumns` index ทุกตัวที่ตามมา (ตรวจสอบให้ครบ ไม่ใช่แค่ตัวใหม่)
- Join Employees picker (off-cycle run) มี Team filter ด้วย ผ่าน shared `buildManualEmployeeWhere()` + ปุ่ม "Select All Matching (N)" (`manualEmployeeAllIds()`, ไม่ paginate) แยกจาก header checkbox ที่เลือกแค่หน้าปัจจุบัน
- ไม่มี dedicated test file — ยืนยันผ่าน PHP CLI smoke test (pattern เดียวกับ branch/role/department/position/rank)

## Payslip Format (`Document & Approval` menu → "เทมเพลตสลิปเงินเดือน" tab, canvas designer)
ประวัติ/บั๊กที่เจอทุกรอบ (v1–round 7) แบบเต็ม: `docs/decisions/payslip-format.md`

- **สถาปัตยกรรมเป็น direct port ของ Employment Certificate Template** (`PayslipTemplateModel`/`Renderer`/`Controller` ports ของ ECT ชุดเดียวกัน, `payslip-template.js` port ของ `employment-certificate-template.js`) — canvas mechanism ทั้งหมด (drag/resize/multi-select/Layers/Undo-Redo/pan-zoom/grid/Insert Table-Shape-Symbol/Image Library/multi-page/presets) เหมือนกันเป๊ะ — editor เปิดเป็น**หน้าแยกในแท็บใหม่**ผ่าน `Controller::view()` ปกติ (navbar/sidebar) เหมือน ECT
- **ปัจจุบันมี `pair_key`+`language` (`th`/`en`) เหมือน ECT แล้ว** (`language_mode` ถูกถอดออกทั้งหมด) — `is_default` เป็น per-(comp_id, language) — route `payslip-template/edit/{pair_key}`
- `earning_lines_all`/`deduction_lines_all`/`statutory_lines_all` เป็น text element ธรรมดาที่ content = token เดียว (`{{xxx}}`) — `PayslipTemplateRenderer::renderElementHtml()` special-case 3 token นี้ขยายเป็น `<table>` รายการจริง
- Schema หลัก: `payslip_templates` (page_size/orientation/margin_mm/template_name/language/pair_key/is_default/header_text_*/footer_text_*/status — **`is_default`/header-footer text/status เป็น field จริงที่ ECT ไม่มี**), `payslip_template_elements` (element_type text/image/shape/table, image_asset_id, group_key, page_number, **`is_visible`**), `payslip_images`, `payslip_template_assignments` (department/team/employee scope, **เฉพาะ active template เท่านั้นที่ conflict กัน**, scoped ต่อ language)
- Permission `payslip_template.manage` (ทุก endpoint gate แล้ว)
- **`PaySlipReport::generate()` delegate ไป `PayslipTemplateRenderer::renderForRun()` เมื่อบริษัทมี default template ที่มี element แล้ว ไม่งั้น fallback `buildHtml()` เดิม** (backward compatible) — ต้องการ `context.language` (default `'th'`) ให้ `resolveTemplateForEmployee($compId,$employeeId,$language)` — Company Logo fallback: template's own `logo_path` → `companies.logo_path`
- Font ไทยใช้ TH Sarabun New (dompdf setup ของตัวเอง, ไม่ใช่ DejaVu Sans) — font dropdown ล็อกให้ tab ภาษาไทยเลือกได้แค่ `th_sarabun_new` (`data-en-only` marker)
- **`PAGE_SIZES` มี 10 ขนาด (A3/A4/A5/B4/B5/Letter/Legal/Tabloid/Executive/Statement) — `PAGE_SIZES_MM` ฝั่ง PHP กับ client-side JS mirror ต้องตรงกันเป๊ะเสมอ**
- Fullscreen = Bootstrap `.modal-fullscreen` (ย้าย DOM node จริงเข้า/ออก ไม่ใช่ browser Fullscreen API — เพราะหน้านี้อาจถูก embed ใน iframe)
- **`is_visible` (Layers eye toggle) ต้อง thread ผ่านทุกจุด serialize element** (`validateElements()`, INSERT, `getElements()`, `duplicate()`, `duplicatePair()`, `generateOtherLanguage()`, JS's `elementsPayload()`/`fetchTemplateIntoSlot()`) — พลาดจุดไหนจุดหนึ่งจะทำให้ element ที่ซ่อนไว้กลับมาโชว์เงียบๆ
- Save แยกปุ่มต่อ tab (Design/Assign To) แต่ submit payload เดียวกันทั้งคู่เสมอ (canvas+assignment พร้อมกัน)
- ทดสอบ: `tests/payslip_template_test.php`

## Employment Certificate Template (เมนู "หนังสือรับรองการทำงาน" → Settings, `employment-certificate/settings`)
ประวัติ/บั๊กที่เจอทุกรอบ (v1–v12) แบบเต็ม: `docs/decisions/employment-certificate-template.md`

- Canvas designer แบบ Word/Photoshop: ลากตำแหน่ง+ปรับขนาด+ฟอนต์/จัดวาง/ตัวหนา-เอียง-underline ต่อ element — **ไม่มี** color picker แบบอิสระ (จำกัด font_family 7 ตัว), ตำแหน่ง/ขนาดเก็บเป็น % ของหน้า (ไม่ใช่ px) ให้ canvas กับ dompdf ใช้ coordinate เดียวกันเป๊ะ
- Schema: `master_employment_certificate_field_types` (20 fields), `employment_certificate_templates` (**หลาย template ต่อภาษาได้**, `page_size`/`orientation`/`margin_mm`, `pair_key`+`language`(`th`/`en`) ผูกคู่, `is_default` per-(comp_id,language)), `employment_certificate_template_elements` (element_type **text/image/shape/table**, `font_family`/`color`/`style`/`decoration`, `group_key`, `page_number`, `is_visible`), `employment_certificate_images` (company-wide reusable library, **hard delete แต่บล็อกถ้ามี template อ้างอิงอยู่**), `employment_certificate_template_assignments` (department/team/employee scope, **conflict check ต่อภาษา**)
- Element มีแค่ 2 ฐาน: `text` (content ฝัง `{{field_key}}` ได้อิสระ = "Auto Replace") กับ `image` — เพิ่ม `shape`/`table` ทีหลัง — `{{field_key}}` substitute ด้วย `htmlspecialchars()` ทั้งกล่องก่อนแล้วค่อย str_replace ค่าที่ escape แล้ว (กัน XSS)
- **Font ไทยจริงมีแค่ `th_sarabun_new` เท่านั้น** (verify จาก cmap table) — อีก 6 (`dejavu_sans`/`dejavu_sans_mono`/`dejavu_serif`/`sans-serif`/`times`/`courier`) เป็น English-only ใน UI (`data-en-only`)
- **Editor เป็นหน้าแยกเปิดแท็บใหม่** (`employment-certificate/edit/{pair_key}`) ผ่าน `Controller::view()` ปกติ (navbar/sidebar ปกติ ไม่ bare) — 2 tab ภายใน (Design/Assign To, Design ต้องเป็น default active เพราะ canvas อ่าน `offsetWidth` ตอนโหลด) — Fullscreen = Bootstrap `.modal-fullscreen` (relocate DOM จริง ไม่ใช่ browser Fullscreen API เพราะอาจถูก embed ใน iframe)
- Multi-select Ctrl+Click, Group/Ungroup, Layers panel (layer บนสุด = drawn ล่าสุด), grid overlay toggle, pan+zoom, Undo/Redo (`e.code` ไม่ใช่ `e.key` — คีย์บอร์ดไทยกด Ctrl+Z ไม่ได้ถ้าใช้ `e.key`), Ctrl+D duplicate/Ctrl+C-V copy-paste/arrow-key nudge, page margin guide (visual only ไม่กระทบ PDF จริง), Insert Table/Shape/Symbol, Image Library ผ่าน ribbon (multi-select insert), multi-page (`page_number`, ไม่มี field เก็บจำนวนหน้าแยก infer จาก max) — double-click แก้ text ล็อกเฉพาะ field ที่ content เป็น `{{key}}` เดี่ยวๆ (bound field, เช็คจาก pattern ไม่ใช่ flag)
- 6 preset (`blank`/`classic`/`modern`/`minimal`/`formal`/`elegant`) — list เป็น pair TH/EN ต่อแถว, ขาดภาษาไหนมีปุ่ม Generate Auto (clone verbatim ข้ามภาษา ไม่แปล) หรือสร้างจาก preset เอง
- `assignableOptions($compId)` = dept/team/employee ทั้งหมด (ไม่ paginate) → checkbox UI — `findConflictingAssignment()` บล็อก assign ซ้ำ (ต่อภาษา) — `duplicate()`/`duplicatePair()` เริ่มแบบ unscoped เสมอ (ไม่ copy assignment, `is_default=false`)
- `resolveTemplateForEmployee($compId,$employeeId,$language)` — **มี consumer จริงแล้ว** ผ่าน Document Requests flow (ดู section นั้น)
- ทดสอบ: `tests/employment_certificate_template_test.php`

## Document Requests — Employment Certificate Request/Approval/Issuance + shared timeline UI (`Payslip & Documents` menu → "Requests" page)
ประวัติ/เหตุผลแบบเต็ม: `docs/decisions/document-requests.md`

- `employment_certificate_requests` (comp_id/employee_id/language/requested_by/`approval_request_id`[unique FK]/status[`pending`/`approved`/`rejected`/`cancelled`/`issued`/`issue_failed`]/file_path/issue_error) — โครงเดียวกับ `payslip_requests`
- `EmploymentCertificateRequestModel::create()` เช็คก่อนสร้าง request: employee มีจริง, language valid, ไม่มี pending request ซ้ำ (ต่อ employee+language), **ต้องมี template resolve ได้จริงผ่าน `resolveTemplateForEmployee()` ก่อนสร้าง** ถึงจะสร้าง row + ยิงเข้า generic `ApprovalRequestModel::create()` (`EMPLOYMENT_CERTIFICATE_APPROVAL`)
- **Two-phase status**: engine ตัดสิน approved/rejected/cancelled ก่อน → `syncFromApprovalStatus()` (เรียกจาก `ApprovalWorkflowController::syncDocumentAfterAct()`) เป็นคนสั่ง `issuePdf()` ตอน approved จริง — render ผ่าน `EmploymentCertificateRenderer::renderForIssuance()`, เขียนไฟล์ `public/uploads/employment_certificate_files/{comp_id}/{hex}.pdf` แล้วตั้ง `issued`/`issue_failed`+`issue_error` — **generate ครั้งเดียวตอน approve เท่านั้น ไม่ re-render อีกแม้ข้อมูลพนักงาน/template จะเปลี่ยนทีหลัง**
- Download stream ผ่าน `realpath()` path-containment check, เฉพาะ `status='issued'`
- **Timeline ใช้ร่วมกัน**: `public/js/setup/approval-request-detail.js` (`openApprovalRequestDetail(id, onActed)`) ขับด้วย `approval_request_id` + generic engine endpoints (`api/approval-request.get`/`.logs`/`.act`) เท่านั้น — ใช้ร่วมกันทั้ง Payslip Requests และ Employment Certificate Requests tab
- DataTable ของ Employment Certificate Requests tab init เฉพาะตอน `shown.bs.tab` (ไม่ใช่ default tab)
- ทดสอบ: `tests/employment_certificate_request_test.php`
- **Delivery Log tab รวมทั้ง 2 ประเภทเอกสารแล้ว**: `DocumentDeliveryLogModel` (UNION ALL ที่ read layer ระหว่าง `payslip_delivery_logs` กับ `employment_certificate_requests` WHERE `status IN ('issued','issue_failed')`) — normalize เป็น row เดียวกัน (`document_type`, `status`=success/failed, `channel_code`/`recipient`/`sent_by` เป็น NULL สำหรับ certificate เพราะไม่มี channel/ไม่มีคนส่ง) — endpoint ใหม่ `api/document-delivery-log.list` แทนที่แค่ตัว list เดิม (`api/payslip-delivery-log.list`/`.resend` ยังอยู่ ใช้ปกติสำหรับ Resend ที่ยัง payslip-only) — filter UI = `.station-filter` pattern เดียวกับ Employee List + filter Document Type ใหม่ — Actions แยกตาม `document_type` (Resend เฉพาะ payslip ที่ fail, Download เฉพาะ certificate ที่ issued)
- ทดสอบ: `tests/document_delivery_log_test.php`
