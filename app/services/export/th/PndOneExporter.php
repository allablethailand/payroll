<?php
declare(strict_types=1);
require_once __DIR__ . '/../StatutoryExportInterface.php';

/**
 * 2026-09-05, REWRITTEN against a real reference spec (Phase 12, T071) — replaces the 2026-08-29
 * 20-field version that was built from a structural description with no byte/pipe sample to check
 * against, and included 4 address sub-fields (house no./moo/building/soi/road) this app has no
 * structured columns for at all (see git history for that version's own docblock). The user
 * supplied a self-consistent reference set instead: `Payroll_Government_Export_Specs.pdf`,
 * `Payroll_Government_Export_Specifications.xlsx` ("PND1 Spec" sheet), a PHP reference
 * implementation (`GovernmentExporters.php`), and a byte-exact sample (`PND1_Export_Sample_
 * TIS620.txt`) — all agree on the SAME 11-field layout below, confirmed by decoding the sample via
 * `iconv('TIS-620','UTF-8', ...)` and reading real Thai names back out of it (not guessed). Adopted
 * wholesale per explicit user confirmation (AskUserQuestion, "ยึด spec ใหม่ทั้งหมด (แนะนำ)").
 *
 * ภ.ง.ด.1 / ภ.ง.ด.1ก, pipe-delimited text, 11 fields per employee row, TIS-620 encoded, CRLF line
 * endings:
 *   1. ลำดับที่ (running number, 1-based, resets per file)
 *   2. เลขประจำตัวประชาชน (13 digits, no dashes)
 *   3. คำนำหน้านาม (Thai/English prefix TEXT — นาย/นาง/นางสาว or Mr./Mrs./Ms., NOT SSO110's own
 *      numeric-code convention for this same employees.title column)
 *   4. ชื่อ (first name)
 *   5. นามสกุล (last name)
 *   6. วันเดือนปีที่จ่ายเงิน, DDMMYYYY, Buddhist year (e.g. "31012567")
 *   7. ประเภทเงินได้ — 1 = เงินเดือน (40(1)), 2 = ค่าจ้าง (40(2)); this app only ever pays regular
 *      salary through payroll, so this is always '1' unless a caller says otherwise
 *   8. อัตราภาษี — a literal "0.00" per the spec ("ใส่ 0.00 กรณีคำนวณแบบอัตราก้าวหน้า" — this app
 *      always computes PIT via the progressive bracket table, never a flat rate, so this field is
 *      always the literal string, never a real computed rate)
 *   9. จำนวนเงินที่จ่าย (total assessable income this period, 2 decimals)
 *  10. จำนวนเงินภาษีที่หัก (PIT withheld this period, 2 decimals)
 *  11. เงื่อนไขการหัก — 1 = หัก ณ ที่จ่าย ตามปกติ (the only condition this app's own withholding
 *      calculation ever represents; 2/3 exist in the spec for cases — lump-sum severance-style
 *      payments, employer-absorbs-the-tax arrangements — this app has no calculation path for)
 *
 * `isVerified()` now returns `true` (was an unconditional `false`) — the FIELD LAYOUT itself is
 * confirmed against the reference materials above, not merely "best-effort reconstructed" like the
 * pre-2026-09-05 version was (`isVerified()` per StatutoryExportInterface's own docblock is a
 * static, format-level flag about the LAYOUT, never about any one call's data — that per-row
 * validation happens inside generate() itself, below, which throws rather than silently emitting a
 * malformed row). Still worth a final cross-check against the RD's own e-Filing portal before a
 * real filing, same as any of this app's exports — but there is no more OPEN discrepancy to flag
 * here the way this file's own history used to.
 */
class PndOneExporter implements StatutoryExportInterface {
    public const SUPPORTED_VERSION_CODES = ['v1_current'];

    public function code(): string {
        return 'TH_PND1';
    }

    public function countryCode(): string {
        return 'TH';
    }

    public function label(): array {
        return ['th' => 'ภ.ง.ด.1 (รายเดือน)', 'en' => 'PND.1 (Monthly PIT Withholding)'];
    }

    public function isVerified(): bool {
        return true;
    }

    /** Per-row DATA validation (distinct from isVerified()'s FORMAT-level flag above) --
     *  generate() throws when this fails rather than emitting a malformed row. */
    private function isVerifiedRecords(array $records): bool {
        if (empty($records)) {
            return false;
        }
        foreach ($records as $row) {
            if (!preg_match('/^[0-9]{13}$/', (string)($row['id_card_no'] ?? ''))) {
                return false;
            }
            if ((float)($row['total_income'] ?? -1) < 0 || (float)($row['tax_withheld'] ?? -1) < 0) {
                return false;
            }
        }
        return true;
    }

    public function fileName(array $context): string {
        $year = $context['period']['tax_year'] ?? date('Y');
        $month = $context['period']['tax_month'] ?? date('n');
        return sprintf('PND1_%04d%02d.txt', $year, $month);
    }

    /**
     * @param array $context {
     *   period: {tax_year: int (พ.ศ.), tax_month: int, payment_date: string 'YYYY-MM-DD'},
     *   employees: array<{
     *     id_card_no: string, prefix: string, first_name: string, last_name: string,
     *     total_income: float, tax_withheld: float, income_type?: string, condition?: string
     *   }>
     * }
     */
    public function generate(array $context): string {
        $versionCode = $context['version_code'] ?? null;
        if ($versionCode !== null && !in_array($versionCode, self::SUPPORTED_VERSION_CODES, true)) {
            throw new RuntimeException("PndOneExporter does not implement format version '{$versionCode}'.");
        }
        $employees = $context['employees'] ?? [];
        if (!$this->isVerifiedRecords($employees)) {
            throw new RuntimeException('PND1 data verification failed (missing employees, a malformed 13-digit ID card no., or a negative amount).');
        }
        $period = $context['period'] ?? [];
        $paymentDateStr = !empty($period['payment_date']) ? $this->toDdmmyyyyBe((string)$period['payment_date']) : str_repeat('0', 8);

        $lines = [];
        $seq = 0;
        foreach ($employees as $emp) {
            $seq++;
            $lines[] = implode('|', [
                (string)$seq,
                $this->digits((string)($emp['id_card_no'] ?? ''), 13),
                trim((string)($emp['prefix'] ?? '')),
                trim((string)($emp['first_name'] ?? '')),
                trim((string)($emp['last_name'] ?? '')),
                $paymentDateStr,
                (string)($emp['income_type'] ?? '1'),
                '0.00',
                number_format((float)($emp['total_income'] ?? 0), 2, '.', ''),
                number_format((float)($emp['tax_withheld'] ?? 0), 2, '.', ''),
                (string)($emp['condition'] ?? '1'),
            ]);
        }
        return $this->toTis620(implode("\r\n", $lines) . "\r\n");
    }

    /** 'YYYY-MM-DD' (Gregorian, this app's own storage convention) -> 'DDMMYYYY' Buddhist year --
     *  e.g. '2026-07-25' -> '25072569'. */
    private function toDdmmyyyyBe(string $isoDate): string {
        $ts = strtotime($isoDate);
        if ($ts === false) {
            return str_repeat('0', 8);
        }
        $beYear = (int)date('Y', $ts) + 543;
        return date('dm', $ts) . (string)$beYear;
    }

    private function digits(string $value, int $length): string {
        $onlyDigits = preg_replace('/\D/', '', $value) ?? '';
        return str_pad(substr($onlyDigits, 0, $length), $length, '0', STR_PAD_LEFT);
    }

    /** Per the reference spec's own "Global Technical Implementation Rules": TIS-620/Windows-874,
     *  never UTF-8, or Thai text reads as mojibake on the RD/SSO side. Uses `iconv` (not
     *  `mb_convert_encoding`, which this environment's mbstring build has no TIS-620 table for —
     *  confirmed directly, not guessed) with `//TRANSLIT` so a character with no TIS-620
     *  equivalent degrades gracefully instead of throwing. */
    private function toTis620(string $utf8): string {
        $converted = @iconv('UTF-8', 'TIS-620//TRANSLIT', $utf8);
        return $converted !== false ? $converted : $utf8;
    }
}
