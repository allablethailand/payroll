<?php
declare(strict_types=1);
require_once __DIR__ . '/../StatutoryExportInterface.php';

/**
 * 2026-09-05, NEW (Phase 12, T071) — กยศ. (Student Loan Fund) deduction remittance, pipe-delimited
 * text format. Before this, `StudentLoanReport` had NO 'txt' format at all ("no field-layout basis
 * exists", per that class's own pre-2026-09-05 docblock) — the user's reference materials
 * (`Payroll_Government_Export_Specifications.xlsx`'s "StudentLoan Spec" sheet + its own worked
 * sample) are the first real field-layout basis this report has ever had. Adopted per the same
 * explicit confirmation as PndOneExporter/Sso110Exporter's own rewrite (AskUserQuestion, "ยึด spec
 * ใหม่ทั้งหมด (แนะนำ)").
 *
 * รายงานการหักเงิน กยศ., pipe-delimited text, 4 fields per employee row, TIS-620 encoded, CRLF line
 * endings:
 *   1. ลำดับที่ (running number, 1-based)
 *   2. เลขประจำตัวประชาชน — 13 digits, no dashes (เลขบัตรผู้กู้ยืม กยศ.)
 *   3. ชื่อ-นามสกุล — ONE combined field (prefix + first + last, unlike PND1/SSO110's separate
 *      first/last columns — matches the spec sheet's own single "Field 3" and its worked sample,
 *      "นาย สมชาย ใจดี" as one value, not split)
 *   4. จำนวนเงินที่หักนำส่ง (amount deducted this period, 2 decimals)
 *
 * `isVerified()` returns `true` — the layout is confirmed against the reference spec above
 * (StatutoryExportInterface's own docblock: this flag is about the LAYOUT, not any one call's
 * data). Per-row data validation happens inside generate(), which throws rather than silently
 * emitting a malformed row.
 */
class StudentLoanExporter implements StatutoryExportInterface {
    public function code(): string {
        return 'TH_SLF';
    }

    public function countryCode(): string {
        return 'TH';
    }

    public function label(): array {
        return ['th' => 'กยศ. (รายงานการหักเงิน)', 'en' => 'Student Loan Fund (Deduction Report)'];
    }

    public function isVerified(): bool {
        return true;
    }

    private function isVerifiedRecords(array $records): bool {
        if (empty($records)) {
            return false;
        }
        foreach ($records as $row) {
            if (!preg_match('/^[0-9]{13}$/', (string)($row['citizen_id'] ?? ''))) {
                return false;
            }
            if ((float)($row['amount'] ?? -1) < 0) {
                return false;
            }
        }
        return true;
    }

    public function fileName(array $context): string {
        $runId = $context['run_id'] ?? 'x';
        return "SLF_Run{$runId}.txt";
    }

    /**
     * @param array $context {
     *   run_id: int (for fileName() only),
     *   employees: array<{citizen_id: string, full_name: string, amount: float}>
     * }
     */
    public function generate(array $context): string {
        $employees = $context['employees'] ?? [];
        if (!$this->isVerifiedRecords($employees)) {
            throw new RuntimeException('Student Loan Fund data verification failed (missing employees, a malformed 13-digit ID card no., or a negative amount).');
        }

        $lines = [];
        foreach ($employees as $index => $emp) {
            $lines[] = implode('|', [
                (string)($index + 1),
                $this->digits((string)($emp['citizen_id'] ?? ''), 13),
                trim((string)($emp['full_name'] ?? '')),
                number_format((float)($emp['amount'] ?? 0), 2, '.', ''),
            ]);
        }
        return $this->toTis620(implode("\r\n", $lines) . "\r\n");
    }

    private function digits(string $value, int $length): string {
        $onlyDigits = preg_replace('/\D/', '', $value) ?? '';
        return str_pad(substr($onlyDigits, 0, $length), $length, '0', STR_PAD_LEFT);
    }

    /** See PndOneExporter's own docblock for why `iconv` (not `mb_convert_encoding`, which this
     *  environment's mbstring build has no TIS-620 table for) with `//TRANSLIT`. */
    private function toTis620(string $utf8): string {
        $converted = @iconv('UTF-8', 'TIS-620//TRANSLIT', $utf8);
        return $converted !== false ? $converted : $utf8;
    }
}
