-- 2026-09-05, Backlog Phase 13 -- real Help Drawer content for the first 5 main pages (confirmed
-- scope via AskUserQuestion: mechanism + real content for a handful of main pages this round, not
-- full app-wide coverage yet). `page_key` values match exactly what help-drawer.js derives from
-- each page's own URL path (see that file's own docblock) -- Dashboard, Employee List, Payroll
-- Process, Payroll Configuration, Tax & Statutory.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-05_6_help_drawer_content_seed.sql

INSERT INTO `help_drawer_content` (`page_key`, `title_th`, `title_en`, `body_th`, `body_en`, `sort_order`) VALUES
('dashboard', 'หน้าแดชบอร์ด', 'Dashboard',
 'หน้านี้แสดงภาพรวมของบริษัท เช่น จำนวนพนักงาน สถานะรอบเงินเดือนล่าสุด และรายการที่ต้องดำเนินการ ใช้เป็นจุดเริ่มต้นในการดูว่ามีอะไรต้องทำต่อบ้าง',
 'This page shows an overview of the company: employee counts, the latest payroll run status, and items awaiting action. Use it as a starting point to see what needs attention.',
 100),
('employees', 'รายชื่อพนักงาน', 'Employee List',
 'จัดการข้อมูลพนักงานทั้งหมดของบริษัทที่นี่ สามารถเพิ่มพนักงานได้ 3 วิธี: กรอกเอง นำเข้าไฟล์ Excel/CSV หรือซิงค์จาก Origami HR หากบริษัทเชื่อมต่อ Origami HR อยู่แล้ว แนะนำให้ใช้การซิงค์เพื่อลดการกรอกข้อมูลซ้ำ',
 "Manage every employee record here. You can add employees 3 ways: manual entry, Excel/CSV import, or syncing from Origami HR. If the company is already connected to Origami HR, syncing is recommended to avoid re-typing data that's already there.",
 90),
('payroll_process', 'ประมวลผลเงินเดือน', 'Payroll Process',
 'หน้านี้ใช้สร้างและติดตามรอบการจ่ายเงินเดือน แต่ละรอบจะผ่านสถานะ ร่าง (Draft) → รออนุมัติ → อนุมัติแล้ว → จ่ายแล้ว ตรวจสอบตัวเลขให้ถูกต้องก่อนกดอนุมัติ เพราะรอบที่อนุมัติแล้วจะไม่สามารถคำนวณซ้ำได้จนกว่าจะดึงกลับมาเป็นร่างก่อน',
 'Use this page to create and track payroll runs. Each run moves through Draft → Pending Approval → Approved → Paid. Double-check the numbers before approving — an approved run cannot be recalculated again until it is reverted back to draft first.',
 80),
('setup_payroll_configuration', 'ตั้งค่ารอบและรายการเงินได้/เงินหัก', 'Payroll Configuration',
 'ตั้งค่ารอบการจ่ายเงินเดือน (วันตัดรอบ วันจ่าย) และรายการเงินได้/เงินหักที่บริษัทใช้งานจริง เช่น ค่าตำแหน่ง ค่าล่วงเวลา เงินกู้พนักงาน แต่ละรายการสามารถกำหนดวิธีคำนวณและผลต่อภาษี/ประกันสังคมได้แยกกัน',
 'Configure payroll cycles (cutoff and payment dates) and the earning/deduction types the company actually uses (allowances, overtime, employee loans). Each type can have its own calculation method and its own effect on tax/social security.',
 70),
('setup_tax_statutory', 'ภาษีและประกันสังคม', 'Tax & Statutory',
 'ตั้งค่าอัตราภาษี เงินสมทบประกันสังคม และกองทุนสำรองเลี้ยงชีพที่บริษัทใช้จริง ค่าเริ่มต้นดึงมาจากอัตรากลางของระบบ (Master) แต่บริษัทสามารถปรับอัตราของตนเองแยกต่างหากได้ โดยไม่กระทบอัตรากลางที่บริษัทอื่นใช้อยู่',
 "Configure the tax rates, social security contributions, and provident fund the company actually uses. Defaults come from the system's shared master rates, but the company can override its own rate independently without affecting the shared master other companies use.",
 60);
