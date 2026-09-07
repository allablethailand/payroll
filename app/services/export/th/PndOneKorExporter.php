<?php
declare(strict_types=1);
require_once __DIR__ . '/../StatutoryExportInterface.php';

/**
 * 2026-09-05, REWRITTEN against a real reference spec (Phase 12, T071) — replaces the pre-existing
 * "best-effort reconstruction from general knowledge" version (no byte sample, no PHP reference,
 * just RD Prep manual references + third-party accountant guides — see git history for that
 * version's own docblock). The reference materials' own PDF states explicitly: "Class
 * PndOneExporter ... Exporter for ภ.ง.ด.1 และ ภ.ง.ด.1ก" — ONE field layout serves BOTH the monthly
 * (ภ.ง.ด.1, see PndOneExporter) and annual-summary (ภ.ง.ด.1ก, this class) forms; the only real
 * difference is what period each row's amounts/pay-date represent. Adopted per the same explicit
 * user confirmation (AskUserQuestion, "ยึด spec ใหม่ทั้งหมด (แนะนำ)").
 *
 * Field layout is IDENTICAL to PndOneExporter's own 11-field pipe-delimited row (see that class's
 * own docblock for the full field-by-field breakdown) — kept as a SEPARATE class rather than one
 * PndOneReport/PndOneKorSummaryReport sharing a single exporter instance, purely so
 * StatutoryExportRegistry/StatutoryFormatVersionModel keep a DISTINCT `code()` per form
 * ('TH_PND1' vs 'TH_PND1K') for the company-facing "Document Format" version picker, matching
 * every other pair of related-but-separately-tracked statutory formats in this codebase (e.g.
 * TH_PND1K_SUMMARY vs TH_PND1). The one field with no obvious annual-summary equivalent — field 6,
 * "วันเดือนปีที่จ่ายเงิน" (payment date) — uses 31 December of the tax year (Buddhist Era) as the
 * representative date, since an annual summary has no single "the" payment date; flagged here
 * rather than silently invented, since neither the spec sheet nor its sample distinguishes this
 * case (both only ever show the MONTHLY form's own real per-payment date).
 *
 * `isVerified()` returns `true` — the FIELD LAYOUT is confirmed against the reference materials
 * above (StatutoryExportInterface's own docblock: this flag is about the layout, not any one
 * call's data — that per-row validation happens inside generate(), which throws rather than
 * silently emitting a malformed row).
 */
class PndOneKorExporter implements StatutoryExportInterface {
    public function code(): string {
        return 'TH_PND1K';
    }

    public function countryCode(): string {
        return 'TH';
    }

    public function label(): array {
        return ['th' => 'ภ.ง.ด.1ก (สรุปประจำปี)', 'en' => 'PND.1K (Annual PIT Withholding Summary)'];
    }

    public function isVerified(): bool {
        return true;
    }

    private function isVerifiedRecords(array $records): bool {
        if (empty($records)) {
            return false;
        }
        foreach ($records as $row) {
            if (!preg_match('/^[0-9]{13}$/', (string)($row['id_card_no'] ?? ($row['tax_id'] ?? '')))) {
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
        return "PND1K_{$year}.txt";
    }

    /**
     * @param array $context {
     *   period: {tax_year: int (พ.ศ.)},
     *   employees: array<{
     *     id_card_no: string, prefix: string, first_name: string, last_name: string,
     *     total_income: float, tax_withheld: float, income_type?: string, condition?: string
     *   }>
     * }
     */
    public function generate(array $context): string {
        $employees = $context['employees'] ?? [];
        if (!$this->isVerifiedRecords($employees)) {
            throw new RuntimeException('PND1K data verification failed (missing employees, a malformed 13-digit ID card no., or a negative amount).');
        }
        $taxYearBe = (int)($context['period']['tax_year'] ?? date('Y'));
        // See this class's own top-of-file docblock for why 31 Dec of the tax year (BE) stands
        // in for "the" payment date on an annual-summary row -- e.g. tax_year=2569 -> '31122569'.
        $representativeDate = sprintf('3112%04d', $taxYearBe);

        $lines = [];
        $seq = 0;
        foreach ($employees as $emp) {
            $seq++;
            $lines[] = implode('|', [
                (string)$seq,
                $this->digits((string)($emp['id_card_no'] ?? ($emp['tax_id'] ?? '')), 13),
                trim((string)($emp['prefix'] ?? '')),
                trim((string)($emp['first_name'] ?? '')),
                trim((string)($emp['last_name'] ?? '')),
                $representativeDate,
                (string)($emp['income_type'] ?? '1'),
                '0.00',
                number_format((float)($emp['total_income'] ?? 0), 2, '.', ''),
                number_format((float)($emp['tax_withheld'] ?? 0), 2, '.', ''),
                (string)($emp['condition'] ?? '1'),
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
