<?php
declare(strict_types=1);

/**
 * Fixed 4-column payslip layout (Income | Deductions | Work Summary | Yearly Summary) used by the
 * Monthly Report download (PaySlipReport context layout='monthly'), modelled on the reference sample
 * "Payslip ALB 202608.pdf".
 *
 * Data mapping: every slot below is always printed, even at 0.00, so the page structure never
 * changes. A line lands in the FIRST slot whose pattern matches its code/name; anything unmatched
 * falls into the catch-all "อื่นๆ" slot so the column still adds up to the run totals.
 * Slots with no data source yet (marked MOCK) print 0.00 / a placeholder.
 */
trait MonthlyPayslipLayoutTrait {
    // [label, code/name regex, or null = base salary / no source]
    private static array $MONTHLY_INCOME_SLOTS = [
        ['เงินเดือน', null],
        ['ค่าตำแหน่งงาน', '/POSITION|ตำแหน่ง/iu'],
        ['ค่าคอมมิชชั่น', '/COMMISSION|คอมมิช|คอมมิส/iu'],
        ['ค่าน้ำมัน', '/FUEL|OIL|PETROL|น้ำมัน/iu'],
        ['ค่าโทรศัพท์', '/PHONE|MOBILE|โทรศัพท์/iu'],
        ['เงินช่วยเหลือ', '/ALLOWANCE|SUPPORT|ช่วยเหลือ/iu'],
        ['โบนัส', '/BONUS|โบนัส/iu'],
        ['รับอื่นๆ', '/MISC_INCOME|OTHER_INCOME|รับอื่น/iu'],
        ['เบี้ยขยัน', '/DILIGEN|เบี้ยขยัน/iu'],
        ['คืนเงินประกันทำงาน', '/DEPOSIT.*(RETURN|REFUND)|คืนเงินประกัน/iu'],
        ['ค่าวิชาชีพ', '/PROFESSION|วิชาชีพ/iu'],
        ['คืนเงินทดรอง', '/ADVANCE.*(RETURN|REFUND)|คืนเงินทดรอง/iu'],
        ['คืนเงินอื่นๆ', '/OTHER_REFUND|คืนเงินอื่น/iu'],
        ['อื่นๆ', 'catch_all'],
    ];

    // Statutory codes (TH_PIT/TH_SSO/TH_PVD) are matched on the statutory breakdown, the rest on deduction lines.
    private static array $MONTHLY_DEDUCTION_SLOTS = [
        ['ภาษีเงินได้งวดนี้', '/^TH_PIT$/'],
        ['ประกันสังคม', '/^TH_SSO$/'],
        ['สมทบกองทุนสำรองเลี้ยงชีพ', '/^TH_PVD$/'],
        ['กองทุนเข้างานสาย', '/LATE|สาย/iu'],
        ['หักเลิกงานเร็ว', '/EARLY|เลิกงานเร็ว/iu'],
        ['หักขาดงาน/ลาเกิน', '/ABSENT|ABSENCE|ขาดงาน|ลาเกิน/iu'],
        ['ค่าโทรศัพท์ที่เกิน', '/PHONE|โทรศัพท์/iu'],
        ['หักเงินประกันทำงาน', '/DEPOSIT|เงินประกัน/iu'],
        ['คืนเงินกู้ยืม01', '/LOAN|เงินกู้/iu'],
        ['คืนเงินกู้ยืม02', null], // MOCK: second loan slot has no source yet, all loans land in 01
        ['หักเบิกล่วงหน้า', '/ADVANCE|เบิกล่วงหน้า/iu'],
        ['หักอื่นๆ', '/OTHER_DEDUCT/iu'],
        ['หักค่าบัตรพนักงาน', '/CARD|บัตร/iu'],
        ['อื่นๆ', 'catch_all'],
    ];

    private static array $THAI_MONTHS = ['มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];

    /** @return array{0: array<int,array{0:string,1:float}>, 1: array<int,array{0:string,1:float}>} income rows, deduction rows */
    private function mapMonthlySlots(array $detail): array {
        $income = array_map(fn($s) => [$s[0], 0.0], self::$MONTHLY_INCOME_SLOTS);
        $deduct = array_map(fn($s) => [$s[0], 0.0], self::$MONTHLY_DEDUCTION_SLOTS);
        $income[0][1] = (float)$detail['base_salary_amount'];

        $otherNames = ['income' => [], 'deduct' => []];
        $place = function (array &$rows, array $slots, string $haystackCode, string $haystackName, float $amount, string $side) use (&$otherNames): void {
            $catchAll = count($slots) - 1;
            foreach ($slots as $i => [$label, $pattern]) {
                if ($pattern === null || $pattern === 'catch_all') continue;
                if (preg_match($pattern, $haystackCode) || preg_match($pattern, $haystackName)) {
                    $rows[$i][1] += $amount;
                    return;
                }
            }
            $rows[$catchAll][1] += $amount;
            if ($haystackName !== '' && !in_array($haystackName, $otherNames[$side], true)) {
                $otherNames[$side][] = $haystackName;
            }
        };
        foreach ($detail['earning_breakdown'] as $line) {
            $place($income, self::$MONTHLY_INCOME_SLOTS, (string)($line['code'] ?? ''), (string)($line['name_th'] ?? ''), (float)($line['amount'] ?? 0), 'income');
        }
        foreach ($detail['deduction_breakdown'] as $line) {
            $place($deduct, self::$MONTHLY_DEDUCTION_SLOTS, (string)($line['code'] ?? ''), (string)($line['name_th'] ?? ''), (float)($line['amount'] ?? 0), 'deduct');
        }
        foreach ($detail['statutory_breakdown'] as $item) {
            $amount = (float)($item['employee_amount'] ?? 0);
            if ($amount > 0) {
                $place($deduct, self::$MONTHLY_DEDUCTION_SLOTS, (string)($item['code'] ?? ''), (string)($item['name_th'] ?? ''), $amount, 'deduct');
            }
        }
        // "อื่นๆ (name, name)" -- falls back to plain "อื่นๆ" when the names would not fit the column.
        foreach ([['income', &$income], ['deduct', &$deduct]] as [$side, &$rows]) {
            $label = 'อื่นๆ (' . implode(', ', $otherNames[$side]) . ')';
            if ($otherNames[$side] && mb_strlen($label) <= 30) {
                $rows[count($rows) - 1][0] = $label;
            }
        }
        return [$income, $deduct];
    }

    private function thaiLongDate(?string $ymd): string {
        $ts = $ymd ? strtotime($ymd) : false;
        return $ts === false ? '-' : (int)date('j', $ts) . ' ' . self::$THAI_MONTHS[(int)date('n', $ts) - 1] . ' ' . ((int)date('Y', $ts) + 543);
    }

    private function moneyCell(float $v): string {
        return number_format($v, 2);
    }

    /** @param array $ytd keys base, gross, tax, sso, pvd (see PayrollReportDataModel::getYtdSlipTotals) */
    private function buildMonthlySlipHtml(string $companyName, array $run, array $detail, string $employeeName, array $ytd): string {
        [$income, $deduct] = $this->mapMonthlySlots($detail);
        $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        $m = fn($v) => $this->moneyCell((float)$v);

        $rowsFor = function (array $rows) use ($e, $m): string {
            $h = '';
            foreach ($rows as [$label, $amount]) {
                $h .= '<tr><td>' . $e($label) . '</td><td class="a">' . $m($amount) . '</td></tr>';
            }
            return $h;
        };
        $kv = function (array $rows) use ($e): string {
            $h = '';
            foreach ($rows as [$label, $value]) {
                $h .= '<tr><td>' . $e($label) . '</td><td class="a">' . $e($value) . '</td></tr>';
            }
            return $h;
        };

        // Work Summary: MOCK except base salary and the day counts the run row already carries.
        $work = $kv([
            ['ฐานเงินเดือน', $m($detail['base_salary_amount']) . ' บาท'],
            ['ค่าแรงวันละ', '0.00 บาท'],
            ['ค่าแรง ชม.ละ', '0.00 บาท'],
            ['วันทำงานทั้งสิ้นในงวด', (int)($detail['prorate_total_days'] ?? 0) . ' วัน'],
            ['วันมาทำงานที่ได้รับค่าแรง', (int)($detail['prorate_days'] ?? 0) . ' วัน'],
            ['ชั่วโมงทำงาน', '000:00 ชั่วโมง:นาที'],
            ['กองทุนเข้างานสาย', '00:00 ชั่วโมง'],
            ['หักเลิกงานเร็ว', '00:00 ชั่วโมง'],
            ['หักขาดงาน/ลาเกิน', '0.0 ครั้ง'],
        ]);
        // Yearly Summary: real cumulative figures for the first block, MOCK 0.00 for the "continuing balance" block.
        $yearly = $kv([
            ['เงินเดือน/ค่าแรงสะสม', $m($ytd['base'])],
            ['เงินได้สุทธิสะสม', $m($ytd['gross'])],
            ['ภาษีเงินได้สะสม', $m($ytd['tax'])],
            ['ประกันสังคมสะสม', $m($ytd['sso'])],
            ['กองทุนสำรองเลี้ยงชีพสะสม', $m($ytd['pvd'])],
        ]);
        $continuing = $kv([
            ['กองทุนสำรองเลี้ยงชีพสะสม', '0.00'],
            ['หักเงินประกันทำงาน', '0.00'],
            ['คืนเงินกู้ยืม01', '0.00'],
            ['คืนเงินกู้ยืม02', '0.00'],
        ]);

        $paid = (string)$run['payment_date'];
        $periodNo = (int)date('n', strtotime($paid)) . ' / ' . ((int)date('Y', strtotime($paid)) + 543);
        $position = $detail['position_name_th'] ?? '-';
        $department = $detail['department_name_th'] ?? '-';
        $bank = trim((string)($detail['bank_code'] ?? ''));
        $accountNo = (string)($this->decryptEmployeeField($detail, 'bank_account_no') ?? '');
        $docNo = !empty($detail['payslip_number']) ? '<div class="small">เลขที่ ' . $e($detail['payslip_number']) . '</div>' : '';

        return <<<HTML
<html>
<head><style>
@page { margin: 28px 34px; }
body { font-family: 'TH Sarabun New', 'DejaVu Sans', sans-serif; font-size: 13px; color: #222; }
table { border-collapse: collapse; width: 100%; }
td { padding: 1px 4px; vertical-align: top; }
.a { text-align: right; }
.small { font-size: 11px; color: #555; }
.title { border: 1px solid #222; padding: 6px 10px; font-size: 18px; text-align: center; }
.cols { border-top: 3px double #222; border-bottom: 3px double #222; margin-top: 8px; }
.cols > tbody > tr > td { width: 25%; padding: 4px 8px; }
.head td { border-bottom: 1px solid #222; padding-bottom: 3px; }
.tot td { padding-top: 12px; font-size: 14px; }
</style></head>
<body>
<table><tr><td style="font-size:15px;">{$e($companyName)}{$docNo}</td><td style="width:36%;"><div class="title">ใบรับจ่ายเงินเดือนและค่าจ้าง (Pay Slip)</div></td></tr></table>
<table style="margin-top:10px;">
<tr><td>รหัส (Employee Code): {$e($detail['employee_no'])}</td><td>ชื่อ (Employee Name): {$e($employeeName)}</td><td>ตำแหน่ง (Position): {$e($position)}</td></tr>
<tr><td>ฝ่าย: {$e($department)}</td><td>แผนก: -</td><td>สถานะ (Status): พนักงานปกติ</td></tr>
<tr><td>ประจำงวด (Period): {$e($periodNo)}</td><td>สิ้นสุดงวดวันที่ (Ending Period): {$e($this->thaiLongDate($run['period_end_date']))}</td><td>จ่ายเงินวันที่ (Paid Date): {$e($this->thaiLongDate($paid))}</td></tr>
<tr><td colspan="3">ฝากเข้าบัญชีธนาคาร {$e($bank)} &nbsp; บัญชีเลขที่ {$e($accountNo)}</td></tr>
</table>
<table class="cols"><tbody>
<tr class="head">
<td><b>เงินได้</b><br><span class="small">INCOME</span></td>
<td><b>รายหัก</b><br><span class="small">DEDUCTIONS</span></td>
<td><b>สรุปชั่วโมงการทำงานในงวดนี้</b><br><span class="small">WORK SUMMARY DURING PERIOD</span></td>
<td><b>ยอดสะสมตั้งแต่ต้นจนถึงปัจจุบัน</b><br><span class="small">YEARLY SUMMARY</span></td>
</tr>
<tr>
<td><table>{$rowsFor($income)}</table></td>
<td><table>{$rowsFor($deduct)}</table></td>
<td><table>{$work}</table></td>
<td><table>{$yearly}</table><div style="height:14px;"></div><b>ยอดเงินสะสมต่อเนื่อง</b><table>{$continuing}</table></td>
</tr>
<tr class="tot">
<td><table><tr><td>เงินได้รวม<br><span class="small">Total Income</span></td><td class="a">{$m($detail['gross_amount'])}</td></tr></table></td>
<td><table><tr><td>รายจ่ายรวม<br><span class="small">Total Deduction</span></td><td class="a">{$m($detail['total_deduction_amount'])}</td></tr></table></td>
<td></td>
<td><table><tr><td>เงินได้สุทธิ<br><span class="small">Net Income</span></td><td class="a">{$m($detail['net_amount'])}</td></tr></table></td>
</tr>
</tbody></table>
<div style="margin-top:34px; text-align:right;">ลงชื่อผู้รับเงิน (Signature) ______________________________</div>
</body>
</html>
HTML;
    }
}
