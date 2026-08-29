<?php
declare(strict_types=1);
require_once __DIR__ . '/../StatutoryExportInterface.php';

/**
 * 2026-08-29, rewritten against a structural spec the user described directly (field order/
 * meaning, not a literal byte/pipe sample this time -- unlike Krungsri's own bank-transfer file
 * or SSO 1-10, both of which had a real example to derive exact positions from) -- ภ.ง.ด.1 /
 * ภ.ง.ด.1ก (monthly PIT withholding return), pipe-delimited text format for the Revenue
 * Department's New e-Filing portal. Since this is delimited (not fixed-width), there's no byte-
 * position ambiguity to resolve the way the other 2 formats needed -- FIELD ORDER is what
 * matters here, and that's exactly what the user's own description lists.
 *
 * 19 pipe-separated fields per employee row, in this order:
 *   1. Form type code (constant "401N" -- the user's own description: "401N หมายถึง แบบ ภ.ง.ด.1
 *      หรือ ภ.ง.ด.1ก", used as-is regardless of which of the two this run's report represents)
 *   2. Sequence number (1-based, resets per file -- "ลำดับที่ (1-5)" in the user's own example)
 *   3. National ID card no. (13 digits)
 *   4. Prefix (คำนำหน้านาม, Thai text e.g. "นาย"/"นาง"/"นางสาว" -- NOT SSO's own numeric code,
 *      see Sso110Exporter for that different convention)
 *   5. First name
 *   6. Middle name (แทบทุกกรณีจะว่างสำหรับพนักงานไทย -- employees has no middle-name column at
 *      all, Thai names don't conventionally have one; always blank here, see PndOneReport's own
 *      docblock)
 *   7. Last name
 *   8. House no. (บ้านเลขที่)
 *   9. Moo/village no. (หมู่ที่)
 *   10. Building/village name (อาคาร/หมู่บ้าน)
 *   11. Soi/lane (ซอย)
 *   12. Road (ถนน)
 *   13. Subdistrict (ตำบล/แขวง)
 *   14. District (อำเภอ/เขต)
 *   15. Province (จังหวัด)
 *   16. Postal code (รหัสไปรษณีย์)
 *   17. Payment date, DDMMYYYY, Buddhist year (e.g. "25072569" = 25 July 2569 BE)
 *   18. Amount paid this period (บาท, 2 decimals)
 *   19. Tax withheld this period (บาท, 2 decimals)
 *   20. Withholding condition (1 = per the user's own example, "หัก ณ ที่จ่าย ออกให้ครั้งเดียว/
 *       จ่ายตามปกติ")
 *
 * ADDRESS FIELD GAP, flagged rather than silently guessed around: this app only stores an
 * employee's registered address as free text (address_line_1_register/address_line_2_register)
 * plus a subdistrict/district/province/postal-code link (master_addresses, via
 * master_address_id_register) -- there are NO separate house-no./moo/building/soi/road columns
 * anywhere. PndOneReport passes the ENTIRE free-text address line into field 8 (house no.) as a
 * pragmatic catch-all and leaves fields 9-12 blank, rather than guessing how to split one
 * free-text string into 4 structured government-form sub-fields. Fields 13-16 (subdistrict/
 * district/province/postal code) ARE resolved from real structured data. If the RD portal
 * strictly validates fields 8-12 as separate required sub-fields, this needs a real employee-
 * form change (4 new address columns) to close properly -- see PndOneReport's own docblock.
 */
class PndOneExporter implements StatutoryExportInterface {
    /** 2026-08-29 -- see Sso110Exporter::SUPPORTED_VERSION_CODES's own docblock for the full
     *  reasoning; same pattern here (matches master_statutory_format_versions' seed row). */
    public const SUPPORTED_VERSION_CODES = ['v1_current'];
    private const FORM_TYPE_CODE = '401N';

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
        return false;
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
     *     house_no: string, moo?: string, building?: string, soi?: string, road?: string,
     *     subdistrict: string, district: string, province: string, postal_code: string,
     *     total_income: float, tax_withheld: float, condition?: string
     *   }>
     * }
     */
    public function generate(array $context): string {
        $versionCode = $context['version_code'] ?? null;
        if ($versionCode !== null && !in_array($versionCode, self::SUPPORTED_VERSION_CODES, true)) {
            throw new RuntimeException("PndOneExporter does not implement format version '{$versionCode}'.");
        }
        $period = $context['period'] ?? [];
        $paymentDateStr = !empty($period['payment_date']) ? $this->toDdmmyyyyBe((string)$period['payment_date']) : str_repeat('0', 8);

        $lines = [];
        $seq = 0;
        foreach ($context['employees'] ?? [] as $emp) {
            $seq++;
            $lines[] = implode('|', [
                self::FORM_TYPE_CODE,
                (string)$seq,
                $this->digits((string)($emp['id_card_no'] ?? ''), 13),
                trim((string)($emp['prefix'] ?? '')),
                trim((string)($emp['first_name'] ?? '')),
                trim((string)($emp['middle_name'] ?? '')),
                trim((string)($emp['last_name'] ?? '')),
                trim((string)($emp['house_no'] ?? '')),
                trim((string)($emp['moo'] ?? '')),
                trim((string)($emp['building'] ?? '')),
                trim((string)($emp['soi'] ?? '')),
                trim((string)($emp['road'] ?? '')),
                trim((string)($emp['subdistrict'] ?? '')),
                trim((string)($emp['district'] ?? '')),
                trim((string)($emp['province'] ?? '')),
                trim((string)($emp['postal_code'] ?? '')),
                $paymentDateStr,
                number_format((float)($emp['total_income'] ?? 0), 2, '.', ''),
                number_format((float)($emp['tax_withheld'] ?? 0), 2, '.', ''),
                (string)($emp['condition'] ?? '1'),
            ]);
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    /** 'YYYY-MM-DD' (Gregorian, this app's own storage convention) -> 'DDMMYYYY' Buddhist year --
     *  e.g. '2026-07-25' -> '25072569' (matches the user's own worked example exactly). */
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
}
