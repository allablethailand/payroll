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

## `tests/ui/harness.js` — ทางเดียวที่ browser test เปิดแอป (2026-09-18, tiny-L4)

แต่ละรอบเขียนสคริปต์ของตัวเองทิ้ง ๆ อยู่แล้ว ปัญหาคือ **กฎที่ต้องใช้กับทุกสคริปต์ไม่มีที่อยู่** — harness.js คือที่อยู่นั้น
(`openContext()` คืน `page` + `report()`; resolve Playwright จาก npx cache เพราะไม่ได้อยู่ใน `package.json`)

**บล็อก 2 request เสมอ (abort) นับจำนวนไว้ และรอบต้องรายงานตัวเลขนั้น**

1. `api/user-preference.save` — รอบหนึ่งวัด th/en + light/dark ทุกครั้ง แปลว่าจบรอบแล้วจะทิ้ง `ui_language`/`ui_theme`
   ของแอดมินตัวจริงไว้ตรงที่ assertion สุดท้ายบังเอิญหยุด (เขียนทับแถวของคนจริงใน dev DB ที่ใช้ร่วมกัน) — หน้าเว็บยัง
   สลับภาษา/ธีมได้ปกติ (ทำฝั่ง client อยู่แล้ว) ตัดแค่ write-back
2. `api/payroll-run.recalculate` — **เจอจริงในรอบนี้**: run ที่เป็น draft + `auto_recalculate = 1` จะถูก
   `loadRunDetail()` (`payroll/detail.js`) ยิง POST recalculate ให้เองตอนโหลดหน้าครั้งแรก ⇒ แค่ "เปิดดู" run ก็
   DELETE+INSERT `payroll_run_details` ทุกแถวของ run นั้นแล้ว (run 752 โดนไป 2 ครั้งก่อนจะเพิ่มบล็อกนี้ ตัวเลขไม่เพี้ยน
   แต่แถวถูกสร้างใหม่จริง) ไม่มีทางกันได้จากตัวสคริปต์เองเพราะสคริปต์ไม่ได้สั่ง — ปิดโดย default,
   รอบที่ตั้งใจจะ recalculate จริงส่ง `allowRecalculate: true`
   (หน้าเว็บไม่พัง: branch นั้นเรียก `loadRunDetail()` ต่อใน `.always` และ flag ครั้งเดียวต่อ session ถูกตั้งไปแล้ว)

Chromium log request ที่ถูก abort เป็น console error ของมันเอง — `report()` แยกออกมาเป็น `suppressedAbortNoise`
โดยหักได้ไม่เกินจำนวนที่บล็อกจริง เพื่อไม่ให้ error จริงหลบอยู่ข้างหลังได้

**`--cleanup` ต้องได้ `fixture_still_present: false`** (roll-up ของ run/detail/manual line/override/history) — เดิมดู
ทีละตัวเลข ทำให้แถว `payroll_run_line_overrides` + `payroll_run_line_override_history` ที่ tool นี้สร้างเองหลุดสะสมมา
ตลอด (กวาดทิ้งครั้งแรก 21 + 23 แถว) ทั้งคู่ผูกด้วย guard 3 ข้อเดิม จึงเอื้อมไปแตะ run ของคนอื่นไม่ได้
