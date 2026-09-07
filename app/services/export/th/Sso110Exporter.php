<?php
declare(strict_types=1);
require_once __DIR__ . '/../StatutoryExportInterface.php';

/**
 * 2026-09-05, REWRITTEN against a real reference spec (Phase 12, T071) — replaces the 2026-08-29
 * fixed-width version (135-byte header + 108-byte detail row), which was reconstructed from a
 * user-supplied sample that had one field ("total wage to be calculated") whose literal value
 * never actually decoded correctly under either decimal convention tried (flagged, never resolved,
 * in that version's own docblock). The new reference materials — `Payroll_Government_Export_
 * Specs.pdf`, `Payroll_Government_Export_Specifications.xlsx` ("SSO 1-10 Spec" sheet), a PHP
 * reference implementation (`GovernmentExporters.php`), and a byte-exact sample
 * (`SSO110_Export_Sample_TIS620.txt`) — all agree on a MUCH simpler pipe-delimited layout with NO
 * header/batch-total row at all, every field self-consistent (confirmed by decoding the sample via
 * `iconv('TIS-620','UTF-8', ...)` and reading real Thai names back out of it, not guessed). Adopted
 * wholesale per explicit user confirmation (AskUserQuestion, "ยึด spec ใหม่ทั้งหมด (แนะนำ)").
 *
 * สปส.1-10, pipe-delimited text, 7 fields per employee row (no header row), TIS-620 encoded, CRLF
 * line endings:
 *   1. ลำดับที่ — 5 digits, zero-padded left (e.g. "00001")
 *   2. เลขประจำตัวประชาชน — 13 digits, no dashes (เลขบัตรผู้ประกันตน)
 *   3. คำนำหน้านาม — Thai/English prefix TEXT (นาย/นาง/นางสาว), NOT a numeric code — this is a
 *      DELIBERATE correction of the pre-2026-09-05 version's own assumption (2-digit prefix code,
 *      01=เด็กชาย/02=เด็กหญิง/03=นาย/etc.) which the new sample directly contradicts (its own
 *      literal bytes decode to the Thai WORD "นาย", not a 2-digit code)
 *   4. ชื่อ (first name)
 *   5. นามสกุล (last name)
 *   6. ค่าจ้างที่ใช้คำนวณ — the SSO-eligible wage base, clamped 1,650–15,000 baht, 2 decimals
 *   7. เงินสมทบผู้ประกันตน — the employee's own 5% contribution on that base, capped at 750.00,
 *      2 decimals
 *
 * WAGE BASE / CONTRIBUTION ARE PASSED IN, NOT RECOMPUTED HERE — deliberately different from the
 * reference PHP class (`Sso110Exporter::addRecord()` in `GovernmentExporters.php`), which hardcodes
 * the 5%-of-clamped-wage formula itself. This app's own `StatutoryCalculationEngine` already
 * computes the real per-employee SSO contribution from `company_statutory_settings`'s configured
 * rate (which is 5%/1,650–15,000 for every company today, but is a company-level OVERRIDE-able
 * setting, not a hardcoded constant — see CLAUDE.md's own Tax & Statutory section) — recomputing it
 * again here would silently ignore a real rate override for the one company that ever sets one.
 * isVerified()/isVerifiedRecords() still range-checks the incoming values against the spec's own
 * stated bounds (1,650–15,000 wage, ≤750 contribution) as a sanity guard, just doesn't derive them.
 *
 * `isVerified()` now returns `true` (was an unconditional `false`) — the FIELD LAYOUT itself is
 * confirmed against the reference materials above (StatutoryExportInterface's own docblock: this
 * flag is about the LAYOUT, not any one call's data — that per-row validation happens inside
 * generate(), which throws rather than silently emitting a malformed row).
 */
class Sso110Exporter implements StatutoryExportInterface {
    public const SUPPORTED_VERSION_CODES = ['v1_current'];

    public function code(): string {
        return 'TH_SSO110';
    }

    public function countryCode(): string {
        return 'TH';
    }

    public function label(): array {
        return ['th' => 'สปส.1-10 (นำส่งเงินสมทบ)', 'en' => 'SSO 1-10 (Monthly Contribution Report)'];
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
            if (!preg_match('/^[0-9]{13}$/', (string)($row['insured_id'] ?? ''))) {
                return false;
            }
            $wage = (float)($row['wage'] ?? -1);
            $contribution = (float)($row['contribution'] ?? -1);
            if ($wage < 1650 || $wage > 15000 + 0.005 || $contribution < 0 || $contribution > 750 + 0.005) {
                return false;
            }
        }
        return true;
    }

    public function fileName(array $context): string {
        $period = $context['period'] ?? [];
        $ym = sprintf('%04d%02d', $period['year'] ?? date('Y'), $period['month'] ?? date('n'));
        return "SSO110_{$ym}.txt";
    }

    /**
     * @param array $context {
     *   period: {year:int, month:int},
     *   employees: array<{insured_id:string, prefix:string, first_name:string, last_name:string, wage:float, contribution:float}>
     * }
     */
    public function generate(array $context): string {
        $versionCode = $context['version_code'] ?? null;
        if ($versionCode !== null && !in_array($versionCode, self::SUPPORTED_VERSION_CODES, true)) {
            throw new RuntimeException("Sso110Exporter does not implement format version '{$versionCode}'.");
        }
        $employees = $context['employees'] ?? [];
        if (!$this->isVerifiedRecords($employees)) {
            throw new RuntimeException('SSO 1-10 data verification failed (missing employees, a malformed 13-digit ID card no., or a wage/contribution amount outside the SSO-mandated 1,650–15,000 / 0–750 range).');
        }

        $lines = [];
        foreach ($employees as $index => $emp) {
            $lines[] = implode('|', [
                str_pad((string)($index + 1), 5, '0', STR_PAD_LEFT),
                $this->digits((string)($emp['insured_id'] ?? ''), 13),
                trim((string)($emp['prefix'] ?? '')),
                trim((string)($emp['first_name'] ?? '')),
                trim((string)($emp['last_name'] ?? '')),
                number_format((float)($emp['wage'] ?? 0), 2, '.', ''),
                number_format((float)($emp['contribution'] ?? 0), 2, '.', ''),
            ]);
        }
        return $this->toTis620(implode("\r\n", $lines) . "\r\n");
    }

    private function digits(string $value, int $maxLength): string {
        $onlyDigits = preg_replace('/\D/', '', $value) ?? '';
        return substr($onlyDigits, 0, $maxLength);
    }

    /** See PndOneExporter's own docblock for why `iconv` (not `mb_convert_encoding`, which this
     *  environment's mbstring build has no TIS-620 table for) with `//TRANSLIT`. */
    private function toTis620(string $utf8): string {
        $converted = @iconv('UTF-8', 'TIS-620//TRANSLIT', $utf8);
        return $converted !== false ? $converted : $utf8;
    }
}
