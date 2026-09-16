# Spec — คำนวณใหม่เฉพาะพนักงานที่เลือก (partial recalculate)

> **สถานะ: spec เท่านั้น ยังไม่ implement** — ตัดสินแล้วว่าเป็นงาน **Batch 5** (เป็นการแก้ logic
> `PayrollRunModel::recalculate()` ซึ่ง phase design แตะไม่ได้ ตาม rules.md §0.7) รอบ design ปัจจุบัน
> **ไม่เพิ่มปุ่ม/เมนูใดๆ ของฟีเจอร์นี้เลย** เพราะ control ที่ไม่มี backend รองรับห้ามโชว์บนหน้าจอ
> เอกสารนี้คือสิ่งที่ตกลงกันไว้แล้ว ให้หยิบไปทำต่อได้ทันทีโดยไม่ต้องตัดสินใจซ้ำ

## สภาพปัจจุบัน (สำรวจ 2026-09-16)

| | ของจริงวันนี้ |
|---|---|
| route | `POST api/payroll-run.recalculate` ตัวเดียว รับแค่ `{id}` = run id (`index.php`) |
| signature | `PayrollRunModel::recalculate(int $id, int $compId, int $userId, bool $isAdmin)` — ไม่มี param พนักงาน |
| กลไก | `DELETE FROM payroll_run_details WHERE run_id = ?` แล้ว INSERT ใหม่ **ทั้งรอบ** |
| totals | สะสมในลูป (`$totalGross`/`$totalDeduction`/`$totalNet`) + `employee_count = count($employees)` + `has_validation_errors` |
| gate | `state = 'draft'` เท่านั้น, permission `payroll_run.process` |
| audit | 1 แถวต่อ 1 ครั้ง: `logAudit(id,'draft','draft','recalculate',userId, "N employee(s) calculated")` — ไม่มีคอลัมน์ employee_id ในตาราง |
| caller ฝั่ง JS | ปุ่ม `#btnRecalculate` (page header, draft only) และ auto-recalculate ตอนโหลดหน้า (1 ครั้ง/การเปิดหน้า) |

## สิ่งที่จะทำ (ตัดสินแล้ว)

### 1. signature

```php
public function recalculate(int $id, int $compId, int $userId, bool $isAdmin, ?array $onlyEmployeeIds = null): array
```

`null` = พฤติกรรมเดิมทุกประการ (ทั้งรอบ) — call site เดิมทั้งหมดไม่ต้องแก้

### 2. คนที่ **ไม่อยู่** ในลิสต์ → เดิน path `preserved` เดิม

กลไกนี้ **มีอยู่แล้ว** ในเมธอด ไม่ต้องสร้างใหม่: `$verifiedPreservedRows` (ปัจจุบันใช้กับพนักงานที่
verify แล้ว) อ่านแถวเดิมไว้ก่อน DELETE แล้ว re-insert ค่าเดิม **ทั้งแถวแบบคำต่อคำ** พร้อมบวกเข้า
`$totalGross`/`$totalDeduction`/`$totalNet` และเซ็ต `$anyError` ตาม `calc_status` เดิม

ให้ partial recalculate ใช้ path เดียวกันนี้กับ "ทุกคนที่ไม่ได้ถูกเลือก" — ผลคือ **totals /
`employee_count` / `has_validation_errors` ยังถูกต้องเสมอ** เพราะทุกคนถูก re-insert ในรอบเดียวเหมือนเดิม
ไม่ต้องเขียน SUM ใหม่ และไม่ต้องแก้ลำดับ DELETE/INSERT

### 3. คนที่ **ยังไม่มีแถว** ใน `payroll_run_details` → **ข้าม ไม่แตะ roster**

พนักงานที่ eligibility query คืนมาแต่ยังไม่มีแถวเดิม (เพิ่งเข้าเกณฑ์) และไม่ได้ถูกเลือก → ไม่คำนวณ
และ **ไม่ insert แถวให้** — partial recalculate ต้องไม่เปลี่ยนสมาชิกของรอบ การเพิ่ม/ลบคนเป็นหน้าที่ของ
`joinEmployees()`/`removeManualEmployee()` ซึ่งเรียก recalculate เต็มรอบของมันเองอยู่แล้ว

### 4. คนที่ **verify แล้ว** → ข้ามเฉพาะคนนั้น + รายงานกลับ ไม่ปลดล็อก

ถูกเลือกมาก็ไม่คำนวณให้ (ตัวเลขของคน verify แล้วต้องแช่แข็ง — กฎเดิมทั้งระบบ ทุก mutation อื่นบล็อกไว้หมด
ผ่าน `isEmployeeVerifiedForRun()`) **ไม่ปฏิเสธทั้งชุด** คนที่เหลือยังคำนวณตามปกติ และผลลัพธ์ต้องบอกให้รู้:

```php
return [
    'status' => true,
    'message' => 'Calculated successfully.',
    'employee_count' => <จำนวนที่คำนวณจริง>,
    'skipped_verified' => ['EM009', 'EM067'],   // รหัสพนักงาน ไม่ใช่ id
    'has_validation_errors' => $anyError,
];
```

UI แสดงเป็น toast/ข้อความต่อท้ายว่า "ข้ามพนักงานที่ตรวจสอบแล้ว N คน" (ไม่ใช่ error)

### 5. audit note ใส่รหัสพนักงาน — pattern เดียวกับ `add_manual_line`

`logAudit()` ไม่มีคอลัมน์ employee_id (และจะไม่เพิ่ม) — ใส่ใน `note` แทน ตามรูปแบบที่เมธอดอื่นใช้อยู่แล้ว
(`"Employee {$employeeNo}: ..."`):

- ทั้งรอบ (`$onlyEmployeeIds === null`): ข้อความเดิมไม่เปลี่ยน — `"N employee(s) calculated"`
- บางคน: `"Recalculated: EM009, EM067, EM085"`
- **เกิน 20 คน**: ตัดที่ 20 แล้วต่อท้าย `"และอีก N คน"` (`"Recalculated: EM001, ... , EM020 และอีก 13 คน"`)
  — กัน note ยาวจนอ่านไม่ไหว/ชน column limit
- ยังคง `(with errors)` ต่อท้ายเหมือนเดิมเมื่อ `$anyError`

### 6. UI ที่จะมาพร้อมกัน (Batch 5 เท่านั้น ไม่ใช่รอบนี้)

- **ต่อแถว**: รายการ `"คำนวณใหม่"` ในเมนู ⋮ ของแถว (มีไอคอนเหมือนทุกรายการในเมนูเดียวกัน — rules.md §6)
- **ตามที่เลือก**: ปุ่ม bulk `"คำนวณที่เลือก N"` — `.btn-outline-secondary` **ไม่มีไอคอน** วางถัดจาก
  `"ตรวจสอบทั้งหมด"` ใน toolbar ฝั่งซ้าย (§2: bulk action อยู่กลุ่มเดียวกับ length) `disabled` จริงจนกว่า
  จะเลือกอย่างน้อย 1 แถว
- ยืนยันด้วย `showConfirm({tone: 'info'})` ก่อนยิง
- หลังเสร็จ: refresh เฉพาะข้อมูลตาราง + stat card + แถวสรุปท้ายตาราง (ไม่ reload ทั้งหน้า)
- **ปุ่ม "คำนวณ" ใน page header คงเดิมทุกอย่าง** (ทั้งรอบ) — คนละงานกัน
- **ปุ่ม/เมนูรายคนยังคงอยู่แม้ toggle "คำนวณอัตโนมัติ" จะเปิด** — auto ยิงแค่ตอนเปิดหน้า 1 ครั้งและแตะทุกแถว
  ส่วนรายคนคือ "อัปเดตเฉพาะคนนี้ โดยไม่ไปยุ่งตัวเลขคนอื่น" คนละปัญหากัน
- gate: `state = 'draft'` + permission `payroll_run.process` เหมือน recalculate เดิมเป๊ะ

### 7. test ที่ต้องมี (ก่อนถือว่าจบ)

1. **totals ตรงกับ full recalculate** — คำนวณทีละคนจนครบทุกคน แล้วเทียบ `total_gross_amount`/
   `total_deduction_amount`/`total_net_amount`/`employee_count`/`has_validation_errors` กับผลของ
   `recalculate()` เต็มรอบบนข้อมูลชุดเดียวกัน ต้องเท่ากันทุกค่า
2. **คนที่ verify แล้วไม่เปลี่ยน** — ทุกคอลัมน์ของแถวนั้นเท่าเดิมหลังถูกเลือกมาคำนวณ และมีรหัสอยู่ใน
   `skipped_verified`
3. **roster ไม่เปลี่ยน** — จำนวนแถวและชุด `employee_id` ใน `payroll_run_details` ก่อน/หลังเท่ากันเป๊ะ
   (รวมกรณีมีพนักงานที่เข้าเกณฑ์ใหม่แต่ยังไม่มีแถว — ต้องไม่ถูก insert เข้ามา)
4. **audit note** — มีรหัสพนักงานจริง, กรณี > 20 คนตัดถูกและมี "และอีก N คน", กรณีทั้งรอบข้อความเดิมไม่เปลี่ยน
