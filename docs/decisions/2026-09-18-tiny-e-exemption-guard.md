# tiny-E — exclude บน TH_PIT/TH_SSO ถูกปิด, tri-state เป็นทางเดียว (2026-09-18)

## ขอบเขต guard
`PayrollRunModel::statutoryLineOverrideSave()` ปฏิเสธ `action='exclude'` เมื่อ item code เป็น **`TH_PIT` หรือ `TH_SSO`**
เท่านั้น (`TRI_STATE_STATUTORY_CODES`, normalize ด้วย `strtoupper(trim())`) — `override_amount` บนสองรหัสนี้ยังรับปกติ,
รหัส statutory อื่น (`TH_PVD` ฯลฯ) ไม่เปลี่ยนพฤติกรรม, `statutoryLineOverrideRemove()` ไม่แตะเลย (เป็นทางล้างแถวค้าง)

## ความหมายที่เปลี่ยน
แถว exclude ทำแค่ `employee_amount = 0` + `note='manually_excluded'` **แต่ฝั่งนายจ้างยังคิดเต็ม** จึงไม่เคยหมายความว่า
"ไม่ส่งประกันสังคม/ไม่คิดภาษีให้คนนี้" จริง ส่วน `payroll_run_employee_exemptions.{tax,sso}_calculate_override = 'no'`
เข้าถึง `$employeeFlags` ของ engine → ได้แถวศูนย์ทั้งสองฝั่ง (`note='employee_not_enrolled'`) ซึ่งเป็นความหมายที่ต้องการ
`saveEmployeeExemption()` จึงเป็น write surface เดียวของเรื่องนี้ (guard draft/verified/enum ของมันมีอยู่แล้ว)

## read path ที่คงไว้
`recalculate()` ยังมีสาขา `manually_excluded` (`PayrollRunModel` ~:4534) — ตายแล้วสำหรับ 2 รหัสนี้ แต่ยังใช้กับ `TH_PVD`
และยังต้องอ่านแถวเก่าถ้ามี **dev DB มี 0 แถวทั้ง `payroll_run_line_overrides` และ `_history` (prefix `__statutory_`)**
→ จด BACKLOG ให้ query prod ยืนยัน 0 แถวก่อน แล้วจึงลบสาขานั้นได้ (ก่อนขึ้น prod)

## ตัวเลข
`tests/exemption_tri_state_test.php` — 34 assertions, 0 fail · ครอบ (a) tri-state write + enum/draft/verified refusal,
(b) exclude ถูกปฏิเสธบน 2 รหัส (ไม่เขียนทั้งแถวและ history) + `override_amount` ยังผ่าน + `TH_PVD` ไม่เปลี่ยน,
(c) recalculate: `no` ศูนย์ทั้งสองฝั่ง / `yes` บังคับเข้าให้คนที่ `sso_enrolled=0` / `inherit` กลับไปตาม flag เดิม
· ไม่แตะ JS/CSS/view/lang (refusal ของ endpoint นี้เป็น literal English `message` ตาม convention เดิม ไม่มี lang key)
