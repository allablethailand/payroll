<?php
declare(strict_types=1);
require_once __DIR__ . '/../StatutoryExportInterface.php';
require_once __DIR__ . '/../FixedWidthHelperTrait.php';

/**
 * 2026-08-29, rewritten against a REAL sample the user supplied directly (header + detail rows,
 * with a field-by-field annotation) — replacing the earlier third-party-blog-derived DRAFT (see
 * git history / this class's own prior docblock for that lineage). Every field width below was
 * derived by locating each annotated value's exact byte offset within the user's own literal
 * sample string (TIS-620-encoded, byte-for-byte — same method used for
 * app/services/reports/payment/BankTransferFileReport's own Krungsri layout the same day), not
 * guessed:
 *   - Header row: 135 bytes total (record type 5 + employer account 10 + branch sequence 4 +
 *     period MMYY 4 + SSO agency/branch code 4 + employer name 45 + insured count 2 +
 *     record count 8 + total wage-to-calculate 14 + total wage-actual 14 +
 *     employee contribution total 13 + employer contribution total 12 = 135).
 *   - Detail row: 108 bytes total (record type 2 + ID card no 13 + prefix code 2 +
 *     first name 30 + last name 35 + wage 12 + contribution 14 = 108) — genuinely DIFFERENT
 *     from the header's own 135, correcting the prior draft's assumption both rows were the
 *     same length.
 *
 * DECIMAL CONVENTION, derived from the sample (not assumed uniform): wage amounts (header's own
 * 2 totals, detail's own per-employee wage) decode as PLAIN WHOLE-BAHT INTEGERS with no implied
 * decimal at all (e.g. detail's own "000000020800" = 20,800 baht exactly, matching the sample's
 * own annotation) — CONTRIBUTION amounts (header's employee/employer totals, detail's own
 * per-employee contribution) decode as IMPLIED-2-DECIMAL instead (e.g. detail's own
 * "00000000087500" = 875.00 baht). This asymmetry is real, confirmed against 3 independent data
 * points in the sample, not an inconsistency introduced here.
 *
 * ONE UNRESOLVED DISCREPANCY, flagged rather than silently resolved: the header's own "total
 * wage to be calculated" field, literally "00000000821000" in the sample, does not decode to the
 * sample's own annotated value (82,100.00) under EITHER convention above (whole-integer gives
 * 821,000; implied-2-decimal gives 8,210.00) — every other amount field in the same sample
 * decodes correctly under one of the two conventions. Implemented here as whole-baht-integer
 * (consistent with the header's OWN adjacent "total wage-actual" field, which decodes correctly
 * under that convention) — but this one field's real width/convention should be re-confirmed
 * against the actual source document before relying on it for a real filing, since the sample
 * value itself doesn't self-verify the way every other field in it does.
 *
 * SSO's own prefix-code convention (employees.title -> 2-digit code) is a widely-used Thai
 * government-form convention (01=เด็กชาย, 02=เด็กหญิง, 03=นาย, 04=นาง, 05=นางสาว), matching the
 * sample's own "03 = นาย" exactly for the one code it shows -- mr/mrs/ms mapped to 03/04/05
 * accordingly. Only the mr->03 mapping is directly confirmed by the sample itself; 04/05 follow
 * the same well-known convention but are not independently confirmed by this sample.
 *
 * SSO announced an update to this form ("ปรับปรุงแบบรายการนำส่งเงินสมทบ สปส.1-10") effective
 * 1 ม.ค. 2569 (2026-01-01) — before today's date. This layout is presumed to reflect whatever the
 * user's own real sample represents (implicitly the CURRENT, post-update layout, since it's what
 * they're being asked to submit today) — still worth a final cross-check against sso.go.th before
 * a real filing, same standing caveat every DRAFT/unverified export in this app carries.
 */
class Sso110Exporter implements StatutoryExportInterface {
    use FixedWidthHelperTrait;

    private const HEADER_ROW_LENGTH = 135;
    private const DETAIL_ROW_LENGTH = 108;

    /** 2026-08-29 -- the ONE version_code this class actually implements (matches
     *  master_statutory_format_versions' seed row for TH_SSO110, see that migration's own
     *  comment). StatutoryFormatVersionModel::resolveVersionCode() is what a company's selection
     *  ultimately resolves to; Sso110Report passes it through as context['version_code']. When a
     *  real 2nd version is ever coded, add it here and branch on it inside generate() -- this
     *  const/check is what stops a future unimplemented version from silently generating THIS
     *  layout's output under the wrong label. */
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
        return false;
    }

    public function fileName(array $context): string {
        $period = $context['period'] ?? [];
        $ym = sprintf('%04d%02d', $period['year'] ?? date('Y'), $period['month'] ?? date('n'));
        return "SSO110_{$ym}.txt";
    }

    /**
     * @param array $context {
     *   company: {employer_account:string, branch_seq:string, sso_agency_code:string, name_th:string, name_en:string},
     *   period: {year:int, month:int},
     *   employees: array<{insured_id:string, prefix_code:string, first_name_th:string, last_name_th:string, first_name_en:string, last_name_en:string, wage:float, contribution:float}>,
     *   language: 'th'|'en' (default 'th')
     * }
     */
    public function generate(array $context): string {
        // 2026-08-29 -- only enforced when the caller actually supplies a version_code (backward
        // compatible with any existing call site/test that doesn't pass one at all); a
        // company-selected version this class doesn't implement must fail loudly rather than
        // silently produce output under the wrong version's label.
        $versionCode = $context['version_code'] ?? null;
        if ($versionCode !== null && !in_array($versionCode, self::SUPPORTED_VERSION_CODES, true)) {
            throw new RuntimeException("Sso110Exporter does not implement format version '{$versionCode}'.");
        }
        $company = $context['company'] ?? [];
        $period = $context['period'] ?? [];
        $employees = $context['employees'] ?? [];
        // 2026-08-29, explicit request: "รองรับ 2 ภาษาเหมือนกัน" -- same th/en selection convention
        // BankTransferFileReport's own generate() already established the same day.
        $requestedLanguage = $context['language'] ?? 'th';
        $language = in_array($requestedLanguage, ['th', 'en'], true) ? $requestedLanguage : 'th';

        // "Total wage to be calculated" vs "total wage actual": the sample's own literal values
        // for these two fields don't tie out against each other OR against the contribution
        // total the same sample shows (78,820 * 5% = 3,941, matching the contribution total
        // EXACTLY -- but 82,100 does not relate to that number at all, and a capped/eligible wage
        // total should logically be <= the real wage total, not greater than it as the sample's
        // literal values would imply). Both computed from the SAME sum here (the wage figure
        // Sso110Report passes per employee is already the SSO-eligible, ceiling-capped base) --
        // see this class's own top-of-file docblock for the full discrepancy writeup.
        $totalWageActual = array_sum(array_column($employees, 'wage'));
        $totalWageToCalculate = $totalWageActual;
        $employeeContribution = $context['company']['employee_contribution_total'] ?? array_sum(array_column($employees, 'contribution'));
        $employerContribution = $context['company']['employer_contribution_total'] ?? $employeeContribution;

        $wagePeriod = sprintf('%02d%02d', $period['month'] ?? 1, ((int)($period['year'] ?? date('Y'))) % 100);

        $header = ''
            . $this->padNumber(11001, 5)
            . $this->padText($this->digitsOnly((string)($company['employer_account'] ?? ''), 10), 10)
            . $this->padText($this->digitsOnly((string)($company['branch_seq'] ?? '0001'), 4), 4)
            . $wagePeriod
            . $this->padText($this->digitsOnly((string)($company['sso_agency_code'] ?? ''), 4), 4)
            . $this->padText($this->toFileEncoding($language === 'en' ? (string)($company['name_en'] ?? '') : (string)($company['name_th'] ?? '')), 45)
            . $this->padNumber(count($employees), 2)
            . $this->padNumber(count($employees), 8)
            // "Total wage to be calculated" -- see this class's own docblock for the one
            // unresolved sample discrepancy on this specific field's decimal convention.
            . $this->padNumber($totalWageToCalculate, 14, 0)
            . $this->padNumber($totalWageActual, 14, 0)
            . $this->padNumber((float)$employeeContribution, 13, 2)
            . $this->padNumber((float)$employerContribution, 12, 2);
        $header = $this->assertLength($header, self::HEADER_ROW_LENGTH, 'header');

        $rows = [$header];
        foreach ($employees as $emp) {
            $firstName = $language === 'en' ? (string)($emp['first_name_en'] ?? '') : (string)($emp['first_name_th'] ?? '');
            $lastName = $language === 'en' ? (string)($emp['last_name_en'] ?? '') : (string)($emp['last_name_th'] ?? '');
            $detail = ''
                . $this->padNumber(25, 2)
                . $this->padText($this->digitsOnly((string)($emp['insured_id'] ?? ''), 13), 13)
                . $this->padText($this->digitsOnly((string)($emp['prefix_code'] ?? ''), 2), 2)
                . $this->padText($this->toFileEncoding($firstName), 30)
                . $this->padText($this->toFileEncoding($lastName), 35)
                . $this->padNumber((float)($emp['wage'] ?? 0), 12, 0)
                . $this->padNumber((float)($emp['contribution'] ?? 0), 14, 2);
            $rows[] = $this->assertLength($detail, self::DETAIL_ROW_LENGTH, 'detail');
        }

        return implode("\r\n", $rows) . "\r\n";
    }

    private function digitsOnly(string $value, int $maxLength): string {
        $onlyDigits = preg_replace('/\D/', '', $value) ?? '';
        return substr($onlyDigits, 0, $maxLength);
    }

    private function assertLength(string $row, int $expected, string $label): string {
        $actual = strlen($row);
        if ($actual !== $expected) {
            // A mismatch here means a field value overflowed its column width in a way
            // padText/padNumber's own truncation didn't fully absorb (e.g. company name
            // encoded to more bytes than expected) — surface it loudly rather than silently
            // shipping a misaligned fixed-width row to SSO.
            throw new RuntimeException("SSO 1-10 {$label} row length mismatch: expected {$expected}, got {$actual}");
        }
        return $row;
    }
}
