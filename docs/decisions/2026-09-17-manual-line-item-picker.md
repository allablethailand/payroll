# สลิปที่แก้ได้ — R1b: ฟอร์มเพิ่ม/แก้รายการ เหลือ select เดียว (2026-09-17)

segmented 3 โหมด (เลือกจากรายการ / ระบุรายการเอง / อื่นๆ) ยุบเป็น **select เดียว label "รายการ"**: catalog ของคอลัมน์ที่กด `+`
ต่อท้ายด้วยตัวเลือกตายตัว "อื่นๆ (ระบุชื่อ)" คั่นด้วยเส้น — คำถามเดียวเคยถูกถาม 2 รอบ (โหมด แล้วช่องที่โหมดเผยขึ้นมา) และ 2 ใน 3 โหมด
เปิดกล่องข้อความใบเดียวกัน · เลือกตัวสุดท้าย = ช่องชื่อโผล่**ใต้** select (select ยังอยู่ กลับไปเลือก catalog ได้) + focus ให้เอง;
prefill ไม่ focus · mode ไม่เก็บเป็นตัวแปรแล้ว อ่านจากค่าใน select (`manualLineIsCustomRd()`)

**โหมด `other` ตายจาก UI แต่ไม่ตายจาก DB**: `is_other` ไม่ถูกส่งจากฟอร์มนี้อีก (คอลัมน์/enum คงเดิม แบบเดียวกับ `not_disbursed`) —
**แถวเก่าที่เป็น `other` prefill เป็น "อื่นๆ" + ชื่อเดิม แต่บันทึกกลับเป็น custom** คือเสียการจัดกลุ่ม "รายรับ/รายหักอื่นๆ" ในรายงานของ
แถวนั้นไป ตั้งใจและยอมรับแล้ว (ทางเลือกอื่นคือเอาโหมดที่ถอดออกไปแล้วกลับมา)

**badge โหมดในแถวเหลือแบบเดียว: `other` (legacy)** — badge "ระบุเอง" ของ custom ถูกตัด เพราะเมื่อฟอร์มเหลือทางเดียวที่จะพิมพ์ชื่อเอง badge นั้นก็ไม่ได้แยกอะไรที่ผู้อ่านทำอะไรต่อได้ · `other` เก็บไว้เพราะต่างจริง: เป็นโหมดที่ถอดไปแล้ว UI สร้างใหม่ไม่ได้อีก และรายงานยังรวมยอดมันคนละถังจนกว่าแถวนั้นจะถูกแก้ · แถว custom ไม่ได้ `title` ด้วย (`code` ของมันคือ `CUSTOM:{ชื่อ}` = ชื่อที่โชว์อยู่แล้ว) · entry `'custom'` ใน `status_map.php` ถูกลบ (dead) แต่ lang key `manual_line_custom_badge` ยังอยู่ — `#eedModal` พิมพ์จาก `langData` ตรงๆ ไม่ผ่าน map · ที่ตัดทิ้งอีกอย่างคือบรรทัดช่วยใต้ segmented (`#manualLineModeDesc` + `.manual-line-mode-desc`)

**option แสดงชื่ออย่างเดียว ไม่มี code** (`stripCodePrefix` ตัดที่ `processResults` + `templateResult`/`templateSelection`, code ไปอยู่ที่ `title`) — endpoint ยังคืน `[CODE] ชื่อ` เหมือนเดิมเพราะ picker อื่นบน endpoint เดียวกันยังใช้อยู่ และมันไม่ส่ง field ชื่อ/code แยกมาให้ · **ไม่มี matcher ฝั่ง client**: โหมด ajax ค้นที่ server และ `activeOptions()` match `item_code` + ชื่อ th/en อยู่แล้ว — พิมพ์ code ยังเจอแถวเดิม

**บั๊กจริงที่เจอตอนวัด (ไม่ใช่ของใหม่จากรอบนี้)**: option ของ select2 ต่อ field **ไม่รอดจากการ re-init** — `applyLanguage()` (app.js) วน `initSelect2Remote($field)` ใส่ทุก `.select2-remote` บนหน้าโดย**ไม่ส่ง option ใดๆ** ทุกครั้งที่โหลดหน้าและทุกครั้งที่สลับภาษา ของที่ field ขอไว้ตอน init ของตัวเองจึงถูกทิ้งเงียบๆ (รอบนี้: `pinnedOption`/`stripCodePrefix` ไม่ทำงานเลยทั้งที่โค้ดถูก · ของเดิม: `allowClear` ของ picker อีก 3 ตัวบนหน้านี้หายมาตลอด) — แก้ที่ `initSelect2()` จุดเดียว: จำ option ไว้ที่**ตัว element** แล้ว merge ไว้ใต้ค่าที่ส่งมาใหม่ (`selectedValue` ไม่จำ เป็นคำสั่งครั้งเดียว) · เจอเพราะวัดจริงในเบราว์เซอร์ ไม่ใช่อ่านโค้ด

**ของใหม่ที่เป็น shared**: `initSelect2()` รับ `pinnedOption` / `stripCodePrefix` (input.js), `splitOptionCodePrefix()` (app.js), `.select2-pinned-option` (style.css) — บันทึกที่ `components.php` § Form control
