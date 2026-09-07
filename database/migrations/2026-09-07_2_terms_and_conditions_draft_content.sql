-- 2026-09-07, explicit request: "เงื่อนไขการเข้าใช้งานระบบ Draft แรกขอแบบนี้ครับ" -- replaces the
-- 2026-09-05 placeholder text (seeded in 2026-09-05_5_phase13_help_terms_changelog.sql,
-- "ข้อความตัวอย่าง — ยังไม่ใช่ข้อกำหนดและเงื่อนไขฉบับจริง...") with the user's own first-draft legal
-- text, supplied directly as a local file (payroll_terms_and_conditions.md). Still `version_label`
-- '1.0-draft' -- an UPDATE in place, not a new version row, since the user's own wording ("Draft
-- แรก") frames this as filling in the SAME still-in-draft version, not publishing a real numbered
-- release yet -- a real "1.0" (dropping "-draft") should be its own later migration once the
-- placeholder company name below is replaced with a real one and this is genuinely finalized.
--
-- Stored as HTML (not the plain-text-with-\n the placeholder used) -- see
-- public/js/setup/terms-and-conditions.js's own 2026-09-07 comment on why: this is real legal text
-- with real structure (6 numbered sections, bold sub-item labels, bullet lists) that plain-text-
-- with-<br> can't represent at all. Safe to render as raw HTML client-side since this table's
-- content is DB-seeded/admin-authored only (see TermsAndConditionsModel's own "no permission gate
-- beyond logged in... this is always MY OWN acceptance" docblock -- there is no user-input path
-- that ever writes to `content_th`/`content_en`).
--
-- The bracketed "[ชื่อบริษัท/องค์กรของคุณ]" / "[Your Company/Organization Name]" placeholder is
-- copied VERBATIM from the user's own source file -- this table is platform-wide (not
-- per-company, see the schema migration's own docblock), so it can't be templated per company
-- automatically; left as an explicit fill-in-the-blank for whoever finalizes the real (non-draft)
-- version later.
--
-- Run with: mysql --default-character-set=utf8mb4 -u <user> -p <database> < 2026-09-07_2_terms_and_conditions_draft_content.sql
-- (see CLAUDE.md -- mysql CLI without --default-character-set=utf8mb4 silently corrupts Thai text)

UPDATE `terms_and_conditions`
SET
    `content_th` = '<h5>ข้อกำหนดและเงื่อนไขการเข้าใช้งานระบบบริหารจัดการเงินเดือน</h5>
<p class="fw-semibold">สำหรับเจ้าหน้าที่และผู้มีสิทธิ์บริหารจัดการเงินเดือน (Payroll Administrators)</p>
<p>เอกสารฉบับนี้เป็นข้อตกลงทางกฎหมายระหว่าง <strong>[ชื่อบริษัท/องค์กรของคุณ]</strong> ("บริษัท") และเจ้าหน้าที่ผู้ได้รับอนุมัติให้เข้าถึงระบบบริหารจัดการเงินเดือน ("ผู้ใช้งาน") การเข้าถึงหรือใช้งานระบบถือเป็นการยอมรับและตกลงที่จะปฏิบัติตามข้อกำหนดดังต่อไปนี้:</p>
<h6>1. สิทธิ์และการอนุญาตเข้าถึงระบบ (System Access &amp; Authorization)</h6>
<ul>
<li><strong>1.1 สิทธิ์เฉพาะบุคคล:</strong> สิทธิ์การเข้าถึงระบบเงินเดือนเป็นสิทธิ์เฉพาะบุคคลตามบทบาทหน้าที่ (Role-Based Access Control) ห้ามมิให้ผู้ใช้งานแบ่งปัน โอน หรือยินยอมให้บุคคลอื่นใช้บัญชีผู้ใช้งาน (User Account) หรือรหัสผ่านร่วมกันโดยเด็ดขาด</li>
<li><strong>1.2 ความรับผิดชอบในข้อมูลระบุตัวตน:</strong> ผู้ใช้งานมีหน้าที่เก็บรักษารหัสผ่าน ข้อมูลการยืนยันตัวตนสองปัจจัย (2FA/MFA) ให้เป็นความลับสูงสุด หากพบการเข้าถึงโดยไม่ได้รับอนุญาต ต้องแจ้งฝ่ายเทคโนโลยีสารสนเทศ (IT) หรือผู้ดูแลระบบทันที</li>
</ul>
<h6>2. การรักษาความลับและข้อมูลส่วนบุคคล (Confidentiality &amp; Data Protection)</h6>
<ul>
<li><strong>2.1 ข้อมูลลับระดับสูงสุด:</strong> ข้อมูลทั้งหมดในระบบเงินเดือน รวมถึงแต่ไม่จำกัดเพียง เงินเดือน ค่าตอบแทน โบนัส ข้อมูลภาษี ประวัติส่วนตัว และข้อมูลบัญชีธนาคารของพนักงาน ถือเป็น <strong>"ข้อมูลลับที่สุดของบริษัท"</strong></li>
<li><strong>2.2 การปฏิบัติตามกฎหมาย PDPA:</strong> ผู้ใช้งานต้องเก็บรวบรวม ใช้ หรือเปิดเผยข้อมูลส่วนบุคคลของพนักงานเท่าที่จำเป็นต่อการปฏิบัติงานตามหน้าที่เท่านั้น โดยต้องปฏิบัติตาม พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล พ.ศ. 2562 (PDPA) อย่างเคร่งครัด</li>
<li><strong>2.3 ข้อห้ามการเปิดเผย:</strong> ห้ามคัดลอก ส่งต่อ เผยแพร่ ดาวน์โหลด หรือจัดเก็บข้อมูลเงินเดือนไว้ในอุปกรณ์ส่วนตัว หรือไดรฟ์ส่วนตัวที่ไม่ได้รับการอนุมัติจากบริษัท</li>
</ul>
<h6>3. ความถูกต้องและความสมบูรณ์ของข้อมูล (Data Integrity &amp; Accuracy)</h6>
<ul>
<li><strong>3.1 หน้าที่ความระมัดระวัง:</strong> ผู้ใช้งานต้องปฏิบัติหน้าที่ด้วยความระมัดระวังสูงสุด เพื่อให้มั่นใจว่าการบันทึก การคำนวณ และการประมวลผลข้อมูลเงินเดือนมีความถูกต้อง แม่นยำ และทันเวลา</li>
<li><strong>3.2 การรายงานข้อผิดพลาด:</strong> หากพบข้อผิดพลาด รูรั่วของข้อมูล (Data Breach) หรือความผิดปกติในระบบ ผู้ใช้งานต้องรายงานต่อผู้บังคับบัญชาและผู้ดูแลระบบทันที</li>
</ul>
<h6>4. ความปลอดภัยและการใช้งานที่เหมาะสม (Security &amp; Acceptable Use)</h6>
<ul>
<li><strong>4.1 ความปลอดภัยของอุปกรณ์:</strong> การเข้าใช้งานต้องทำผ่านอุปกรณ์และเครือข่ายความปลอดภัยที่บริษัทกำหนดหรืออนุมัติเท่านั้น (เช่น การต่อผ่าน VPN ของบริษัท)</li>
<li><strong>4.2 การออกจากระบบ:</strong> ผู้ใช้งานต้องทำการล็อกหน้าจอ/ออกจากระบบ (Log out) ทุกครั้งเมื่อไม่ได้อยู่ในบริเวณหน้าจอ</li>
<li><strong>4.3 ข้อห้ามดัดแปลงระบบ:</strong> ห้ามแก้ไข ดัดแปลง ย้อนรอยกระบวนการ (Reverse Engineer) หรือพยายามเจาะระบบความปลอดภัยของระบบเงินเดือนโดยเด็ดขาด</li>
</ul>
<h6>5. การตรวจสอบและการเก็บบันทึก (Auditing &amp; Monitoring)</h6>
<ul>
<li><strong>5.1 บันทึกการใช้งาน (Audit Logs):</strong> บริษัทขอสงวนสิทธิ์ในการติดตาม ตรวจสอบ และบันทึกประวัติการใช้งาน (System Activity Logs) ของผู้ใช้งาน รวมถึงการเข้าถึง การแก้ไข และการส่งออกข้อมูลทั้งหมด เพื่อวัตถุประสงค์ด้านความปลอดภัยและการตรวจสอบภายใน</li>
</ul>
<h6>6. บทลงโทษเมื่อมีการละเมิด (Penalties for Breach)</h6>
<p>การฝ่าฝืนข้อกำหนดนี้ ถือเป็นความผิดวินัยร้ายแรง ซึ่งอาจนำไปสู่การลงโทษทางวินัยตามระเบียบบังคับของบริษัท (รวมถึงการเลิกจ้างโดยไม่จ่ายชดเชย) และอาจถูกดำเนินคดีทั้งทางแพ่งและทางอาญาตามกฎหมายที่เกี่ยวข้อง (รวมถึง พ.ร.บ. คอมพิวเตอร์ฯ และ พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคลฯ)</p>',
    `content_en` = '<h5>Payroll System Terms and Conditions of Use</h5>
<p class="fw-semibold">For Payroll Administrators and Authorized Personnel</p>
<p>This document constitutes a legally binding agreement between <strong>[Your Company/Organization Name]</strong> ("Company") and authorized personnel granted access to the Payroll Management System ("User"). By accessing or using the System, the User agrees to comply with the following terms and conditions:</p>
<h6>1. System Access &amp; Authorization</h6>
<ul>
<li><strong>1.1 Individual Access:</strong> Access to the Payroll System is granted strictly on a need-to-know and role-based access control (RBAC) basis. Users are explicitly prohibited from sharing, transferring, or allowing third parties to use their credentials or account.</li>
<li><strong>1.2 Credential Security:</strong> Users are solely responsible for maintaining the strict confidentiality of their usernames, passwords, and multi-factor authentication (MFA) tokens. Any unauthorized access or suspected compromise must be reported to the IT Security Team immediately.</li>
</ul>
<h6>2. Confidentiality &amp; Data Protection</h6>
<ul>
<li><strong>2.1 High-Level Confidentiality:</strong> All data contained within the Payroll System—including but not limited to salaries, compensation structures, bonuses, tax identification, personal background, and bank account details—is classified as <strong>"Strictly Confidential."</strong></li>
<li><strong>2.2 Privacy Compliance:</strong> Users shall collect, process, use, or disclose employee personal data strictly within the scope of their assigned duties, in full compliance with applicable data protection laws (including the Personal Data Protection Act - PDPA / GDPR).</li>
<li><strong>2.3 Data Export Restrictions:</strong> Exfiltrating, downloading, storing, or transmitting payroll data to personal devices, unauthorized cloud storage, or external email accounts is strictly prohibited.</li>
</ul>
<h6>3. Data Integrity &amp; Accuracy</h6>
<ul>
<li><strong>3.1 Duty of Care:</strong> Users must exercise the highest degree of professional care to ensure that payroll data entry, calculations, and processing are accurate, complete, and executed in a timely manner.</li>
<li><strong>3.2 Incident Reporting:</strong> Any identified discrepancies, system errors, or data breach security incidents must be reported immediately to line management and the System Administrator.</li>
</ul>
<h6>4. Security &amp; Acceptable Use</h6>
<ul>
<li><strong>4.1 Approved Channels:</strong> Access to the Payroll System must occur solely through Company-approved devices and secure network connections (e.g., corporate VPN).</li>
<li><strong>4.2 Workstation Hygiene:</strong> Users must log out or lock their computer screens whenever leaving their workstations unattended.</li>
<li><strong>4.3 System Tampering:</strong> Users shall not attempt to bypass security controls, reverse-engineer, alter code, or introduce malicious software into the Payroll System.</li>
</ul>
<h6>5. Auditing &amp; Monitoring</h6>
<ul>
<li><strong>5.1 Activity Logging:</strong> The Company reserves the right to monitor, track, and log all user activities within the System—including data views, modifications, exports, and deletions—for security compliance and auditing purposes.</li>
</ul>
<h6>6. Breach &amp; Penalties</h6>
<p>Any non-compliance or breach of these Terms and Conditions constitutes severe misconduct and will result in disciplinary action up to and including immediate termination of employment without severance, as well as potential civil and criminal prosecution under applicable laws.</p>'
WHERE `is_active` = 1;
