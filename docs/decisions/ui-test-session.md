# Session สำหรับ browser test (`tests/ui/mksession.php`)

แอปนี้ไม่มีหน้า login ให้ browser test ขับเลย — login คือ SSO ขาเข้าทางเดียวจาก Origami (`auth/index.php` แลก token เป็น
JWT แล้วตั้ง `$_SESSION['user']` เอง, `AuthController` มีแค่ `permission()`) Playwright จึงเข้าหน้าที่ต้องล็อกอินไม่ได้ ถ้า
ไม่มีใครสร้าง session ให้ก่อน

**วิธีที่ใช้**: เขียนไฟล์ session ของ install นี้เอง แล้วพิมพ์ `PHPSESSID` ให้ส่งเข้า Playwright เป็น cookie · สร้าง payroll
run ทดสอบ (`UI test run (delete me)`, มี manual line 1 แถว + override 1 แถว ให้มีของจริงให้วัด) ไปด้วย · **ห้ามแตะ run 752**

**เงื่อนไขที่บังคับไว้ในตัวไฟล์ ทั้ง 3 ข้อ ทุกครั้ง**: รันได้จาก CLI เท่านั้น (`PHP_SAPI`), `BASE_URL` ต้องเป็น loopback host (ไม่ใช้ `APP_ENV` —
`.env` ของ repo นี้เป็น `production` บนเครื่อง dev อยู่แล้ว เชื่อไม่ได้), และ `--cleanup` ลบเฉพาะของที่ตัวเองสร้าง: run id อ่านจาก
`tests/ui/.last-session.json` ของตัวเอง + เช็คชื่อ run ซ้ำ (ไม่รับ id จาก argv), ไฟล์ session ตาม id ที่ตัวเองสร้าง (ไม่ไล่ `sess_*` ของคนอื่น)

**จบทุกรอบด้วย `--cleanup`** — output บอกว่าถูกลบจริงไหม (`run_still_present`/`session_still_present` ต้อง false ทั้งคู่ ไม่งั้น
exit code ไม่ใช่ 0) · run ที่ค้างอยู่ทำให้ `payroll_calc_warnings_test.php` fail ได้จริง (เจอมาแล้วรอบนี้)
