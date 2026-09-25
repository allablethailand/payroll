# Spec — `line-override.save-batch` (เขียนหลายแถว recalculate ครั้งเดียว)

> **สถานะ: spec เท่านั้น ยังไม่ implement** — งาน **Batch 5** (แก้ logic ฝั่ง model/controller ซึ่ง phase
> design แตะไม่ได้ ตาม rules.md §0.7) · UI ที่ต้องใช้มันมีอยู่แล้ว (ตารางเดียวใน tab "ปรับตัวเลข" +
> ปุ่มบันทึกเดียวที่ footer) แค่ตอนนี้ยิงทีละแถวไปก่อน

## ปัญหา

`PayrollRunModel::lineOverrideSave()` / `lineOverrideRemove()` จบด้วย `return $this->recalculate(...)`
ทุกครั้ง = **คำนวณทั้งรอบใหม่ 1 ครั้งต่อ 1 แถวที่บันทึก** และไม่มี endpoint ที่รับหลายแถว

ผลตอนนี้ (หลังรวมเป็นตารางเดียว): แก้ 5 แถวแล้วกดบันทึก = 5 request เรียงกัน + **5 full recalculate**
ของทั้งรอบ · ยิงพร้อมกันไม่ได้ (recalculate 2 ตัวจะเขียนทับกัน — คอมเมนต์เดิมใน `runSequentialAjaxRd()`
ระบุเหตุผลนี้ไว้แล้ว) จึงต้องรอเป็นทอดๆ · รอบที่พนักงานเยอะ เวลารวมโตตามจำนวนแถว × ขนาดรอบ

## สิ่งที่จะทำ

### endpoint

`POST api/payroll-run.line-override.save-batch`

```json
{
  "id": 752,
  "employee_id": 159,
  "items": [
    { "item_code": "OT",        "line_type": "earning_deduction", "action": "exclude" },
    { "item_code": "BONUS",     "line_type": "earning_deduction", "action": "override_amount", "override_amount": 1500 },
    { "item_code": "TH_SSO",    "line_type": "statutory",         "action": "override_amount", "override_amount": 750 },
    { "item_code": "LOAN",      "line_type": "earning_deduction", "action": "remove" }
  ]
}
```

- `line_type: 'statutory'` → server wrap `item_code` ด้วย `statutoryOverrideCode()` **เอง** (เหมือน
  `statutoryLineOverrideSave()` วันนี้) — client ห้าม wrap เด็ดขาด กฎเดิมไม่เปลี่ยน
- `action` 3 ค่า: `exclude` / `override_amount` / `remove` (ตรงกับ 3 ทางที่ UI สร้างได้จริง)

### model

`lineOverrideSaveBatch(int $runId, int $compId, int $employeeId, array $items, int $userId, bool $isAdmin): array`

1. gate เดิมทุกข้อ ก่อนแตะข้อมูล: permission `payroll_run.process`, run ต้องมีจริง, `state='draft'`,
   `isEmployeeVerifiedForRun()` = ปฏิเสธทั้งชุด (พฤติกรรมเดียวกับรายตัววันนี้)
2. **1 transaction ครอบทั้งชุด** — ทุกแถวเขียนครบหรือไม่เขียนเลย (วันนี้ยิงทีละ request แล้วล้มกลางทาง =
   ครึ่งๆ กลางๆ ซึ่ง UI ต้องรายงานว่า "บันทึกไปแล้ว N แถว" อยู่ตอนนี้)
3. เขียนแต่ละแถวด้วย**โค้ดเดิม**: แยก write path ของ `lineOverrideSave()`/`lineOverrideRemove()` ออกมาเป็น
   private method ที่ไม่เรียก `recalculate()` แล้วให้ทั้งตัวเดิมและ batch เรียกตัวเดียวกัน — **ห้าม copy
   SQL/validation ไปเขียนซ้ำใน batch** (CLAUDE.md: ห้าม mirror-by-copy)
4. audit + `recordLineOverrideHistory()` ยัง **1 แถวต่อ 1 item** เหมือนเดิม (ประวัติต่อรายการต้องไม่หายไป
   เพราะบันทึกพร้อมกัน)
5. **`recalculate()` ครั้งเดียว ท้ายสุด** หลัง commit — คืนผลของมันต่อออกไปเหมือน `lineOverrideSave()` ทำอยู่

### UI ที่ต้องแก้ตอนนั้น

`saveLineOverrideTableRd()` (detail.js) เปลี่ยนจากสร้าง N closure ยิงเรียงกัน เป็นยิง 1 request แล้ว
**ตัดออก**: progress "กำลังบันทึก i/N…", `runSequentialAjaxRd()` (ถ้าไม่มี caller อื่นเหลือ), ข้อความ
"บันทึกไม่สำเร็จที่รายการ X — บันทึกสำเร็จไปแล้ว N รายการ" (batch เป็น all-or-nothing จึงเหลือแค่สำเร็จ/ล้ม)
— ล็อกทั้ง modal ระหว่างรอยังคงไว้

## test ที่ต้องมี

1. **ผลเท่ากับการยิงทีละแถว** — ชุดเดียวกัน (mix ครบ 3 action + มีแถว statutory) รันผ่าน batch เทียบกับ
   รันผ่าน `lineOverrideSave()`/`lineOverrideRemove()` ทีละตัวบนข้อมูลตั้งต้นเดียวกัน แล้ว **payroll_run_details
   ของพนักงานคนนั้น + totals ของ run ต้องเท่ากันทุกค่า**
2. **recalculate ถูกเรียกครั้งเดียว** — นับจาก `payroll_run_audit_logs` (action='recalculate') ที่เพิ่มขึ้น
   หลัง batch 1 ครั้ง = 1 แถว (วันนี้ N แถว)
3. **all-or-nothing** — ใส่แถวที่ทำให้ล้มกลางชุด (เช่น `override_amount` ติดลบ) → ไม่มีแถวไหนใน
   `payroll_run_line_overrides` เปลี่ยนเลย และ run ไม่ถูก recalculate
4. **statutory wrap ที่ server** — ส่ง `item_code: 'TH_SSO'` ดิบๆ แล้วแถวที่เขียนจริงต้องเป็น
   `__statutory_TH_SSO__` (และ `recalculate()` อ่านเจอจริง = ยอดเปลี่ยนตาม)
5. gate เดิมครบ: non-draft ปฏิเสธ, ไม่มีสิทธิ์ปฏิเสธ, พนักงานที่ verify แล้วปฏิเสธ
