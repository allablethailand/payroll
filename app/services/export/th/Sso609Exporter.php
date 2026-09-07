<?php
declare(strict_types=1);
require_once __DIR__ . '/../StatutoryExportInterface.php';

/**
 * 2026-09-05, NEW (Phase 12, T071) — สปส.6-09 (SSO termination notice), pipe-delimited text
 * format. Before this, `Sso609Report` had NO 'txt' format at all ("no field-layout basis exists",
 * per that class's own pre-2026-09-05 docblock). The user's reference materials
 * (`Payroll_Government_Export_Specifications.xlsx`'s "SSO 6-09 Spec" sheet + its own worked
 * sample) are the first real field-layout basis this report has ever had. Adopted per the same
 * explicit confirmation as PndOneExporter/Sso110Exporter's own rewrite (AskUserQuestion, "ยึด spec
 * ใหม่ทั้งหมด (แนะนำ)").
 *
 * สปส.6-09, pipe-delimited text, 4 fields per employee row, TIS-620 encoded, CRLF line endings:
 *   1. ลำดับที่ (running number, 1-based)
 *   2. เลขประจำตัวประชาชน — 13 digits, no dashes (เลขบัตรผู้ประกันตนที่แจ้งออกจากงาน)
 *   3. คำนำหน้า-ชื่อ-สกุล — ONE combined field, 80 chars (matches the spec sheet's own single
 *      "Field 3" and its worked sample, "นาย สมชาย ใจดี" as one value)
 *   4. วันที่ออกจากงาน — DDMMYYYY, Buddhist year (วันที่สิ้นสุดความเป็นผู้ประกันตน)
 *   5. สาเหตุการออกจากงาน — 01 = ลาออก, 02 = เลิกจ้าง, 03 = เกษียณ/เสียชีวิต
 *
 * REASON CODE IS A BEST-EFFORT HEURISTIC, flagged rather than silently guessed away:
 * `employees.employment_end_reason` is a free-text `varchar(255)` with NO seeded/controlled
 * vocabulary anywhere in this app (confirmed by querying the real dev DB directly — zero rows use
 * it at all right now), so there is no reliable value to map from. `mapReasonCode()` pattern-
 * matches a handful of known Thai/English keywords (ลาออก/resign, เลิกจ้าง/terminat.../lay.?off,
 * เกษียณ/retire, เสียชีวิต/decease/death) and falls back to '01' (ลาออก, the most common real-world
 * case) when the field is empty or matches nothing — a real fix needs `employment_end_reason` to
 * become a real closed set (dropdown, matching this project's own "master table for a fixed/closed
 * option list" convention) with values that map 1:1 to these 3 codes, not a heuristic over free text.
 *
 * `isVerified()` returns `true` for the LAYOUT (StatutoryExportInterface's own docblock: this flag
 * is about the field layout, not any one call's data) — the reason-code MAPPING above is the one
 * genuinely unconfirmed piece, called out here rather than hidden behind a blanket `true`.
 */
class Sso609Exporter implements StatutoryExportInterface {
    public function code(): string {
        return 'TH_SSO609';
    }

    public function countryCode(): string {
        return 'TH';
    }

    public function label(): array {
        return ['th' => 'สปส.6-09 (แจ้งสิ้นสุดผู้ประกันตน)', 'en' => 'SSO 6-09 (Termination Notice)'];
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
        }
        return true;
    }

    public function fileName(array $context): string {
        $period = $context['period'] ?? [];
        $ym = sprintf('%04d%02d', $period['year'] ?? date('Y'), $period['month'] ?? date('n'));
        return "SSO609_{$ym}.txt";
    }

    /**
     * @param array $context {
     *   employees: array<{citizen_id: string, full_name: string, leave_date: string 'YYYY-MM-DD', reason?: ?string}>
     * }
     */
    public function generate(array $context): string {
        $employees = $context['employees'] ?? [];
        if (!$this->isVerifiedRecords($employees)) {
            throw new RuntimeException('SSO 6-09 data verification failed (missing employees or a malformed 13-digit ID card no.).');
        }

        $lines = [];
        foreach ($employees as $index => $emp) {
            $lines[] = implode('|', [
                (string)($index + 1),
                $this->digits((string)($emp['citizen_id'] ?? ''), 13),
                trim((string)($emp['full_name'] ?? '')),
                !empty($emp['leave_date']) ? $this->toDdmmyyyyBe((string)$emp['leave_date']) : str_repeat('0', 8),
                $this->mapReasonCode($emp['reason'] ?? null),
            ]);
        }
        return $this->toTis620(implode("\r\n", $lines) . "\r\n");
    }

    /** See this class's own top-of-file docblock for why this is a heuristic, not a confirmed mapping. */
    private function mapReasonCode(?string $reason): string {
        $r = mb_strtolower(trim((string)$reason));
        if ($r === '') {
            return '01';
        }
        if (preg_match('/เสียชีวิต|decease|death/u', $r)) {
            return '03';
        }
        if (preg_match('/เกษียณ|retire/u', $r)) {
            return '03';
        }
        if (preg_match('/เลิกจ้าง|terminat|lay.?off|dismiss/u', $r)) {
            return '02';
        }
        if (preg_match('/ลาออก|resign/u', $r)) {
            return '01';
        }
        return '01';
    }

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

    /** See PndOneExporter's own docblock for why `iconv` (not `mb_convert_encoding`, which this
     *  environment's mbstring build has no TIS-620 table for) with `//TRANSLIT`. */
    private function toTis620(string $utf8): string {
        $converted = @iconv('UTF-8', 'TIS-620//TRANSLIT', $utf8);
        return $converted !== false ? $converted : $utf8;
    }
}
