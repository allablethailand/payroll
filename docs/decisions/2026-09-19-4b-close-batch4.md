# 4b — ปิดสายก้อน 4 (2026-09-19)

**tri-state แทน exclude (TH_PIT/TH_SSO)** — แถว `exclude` ทำแค่ `employee_amount = 0` **ฝั่งนายจ้างยังคิดเต็ม** จึงไม่เคยแปลว่า "ไม่คิดภาษี/ไม่ส่งประกันสังคมให้คนนี้" (tiny-E ปิดทางเขียนแล้ว) · switch บน 2 แถวนี้เขียน `payroll_run_employee_exemptions` แทน (เปิด=`yes` / ปิด=`no` / "คืนค่าระบบ" ของแถว=`inherit`) ส่ง payload ครบ 2 field เสมอเพราะ endpoint เขียนเป็นคู่ · **ห้ามอ่าน state จาก "มี/ไม่มีแถว"**: engine คงแถว SSO/PIT ไว้ยอด 0 ทั้งสองฝั่ง `note='employee_not_enrolled'`

**field read-only ที่เพิ่ม** — `getEmployeeExemption()` คืน `tax_inherit_effective`/`sso_inherit_effective` (`yes`/`no`) = *inherit แปลว่าอะไรจริง* สำหรับคนนี้ในรอบนี้ (run default → employee flag, ลำดับเดียวกับ `recalculate()` แต่ไม่รวม override เอง) · จำเป็นเพราะ tag "ระบบ: คำนวณ/ไม่คำนวณ" ต้องบอกค่านั้น **ขณะที่ override ทับอยู่** ซึ่ง `note` ของ line บอกไม่ได้ (override แทนที่ note ไปแล้ว)

**2 แถว statutory render เสมอในโหมดแก้** — switch เป็นทางเดียวที่จะตั้ง `yes` ได้ ถ้าซ่อนตอนยอด 0 / not_enrolled ก็ไม่มีทางเปิดกลับ (`lineOverrideIsSkippedRd(line, mode)` รับ mode) · โหมดดู render เมื่อยอด ≠ 0 หรือ override ≠ inherit

**ลบเพิ่มจากลิสต์รอบ A** (grep consumer = 0 ทุกตัวก่อนลบ) — `rawSyncDataButtonRd()` (ไม่มี ⋮ ให้ใส่แล้ว, `#rawSyncDataModal` + handler คงไว้ให้ 3e) · handler `#btnSaveEmpCalcOverride`, `.sync-line-exclude-check`, `recurringDestFormErrorRd()` · lang orphan 10 key (รวมลบ 40 key/ไฟล์, grep ใน `app/` `public/js/` `public/css/` = 0 ทุกตัว, th/en เท่ากัน 3,023 key)

**run 1016 ใช้เป็นตัวอย่าง n=0 ไม่ได้** — `payroll_runs.status='deleted'` → `payroll-run.get` ตอบ "Record not found" หน้าไม่โหลดเลย (ยิง HTTP จริงยืนยัน) · cell 6 ใช้ employee ที่ `adjustment_count=0` บน fixture run แทน

**ปุ่ม "ลบออก" เป็น neutral** — §5 ให้สีแดงกับ *สถานะ* ไม่ใช่ action และ §7 บังคับไอคอน row-action เป็น `--c-text-muted` อยู่แล้ว (`!important`) · ใส่ `.text-danger` จะทำให้ markup พูดคนละอย่างกับที่ render จริง จึงไม่ใส่ tone class เลย ไม่แก้ CSS ไม่เพิ่มข้อยกเว้น

**ตัวเลข** — code +307/−1,235 · CSS 0/−45 · test แก้ +84/−1,244 + ไฟล์ใหม่ 624 บรรทัด · `run_all` 140 ไฟล์ 7,360 pass / 2 fail (fail เดิมทั้งคู่) · Playwright 8 cell 66/66
