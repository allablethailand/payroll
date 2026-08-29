<?php
declare(strict_types=1);
require_once __DIR__ . '/../StatutoryExportInterface.php';
require_once __DIR__ . '/../FixedWidthHelperTrait.php';

/**
 * DRAFT — สปส.1-10 (monthly social security contribution report), fixed-width text format
 * for electronic submission to the Social Security Office.
 *
 * Field layout below (135 bytes/row, Header type=1 + Detail type=2) comes from a third-party
 * accountant blog (not an official SSO document) — see https://www.panyame.com/blog/docs/others/e-filing/sso110txt/.
 * The field lengths in both the header (12 fields) and detail (8 fields) sections each sum to
 * exactly 135, which is a good internal-consistency signal, but it is NOT proof of correctness.
 *
 * IMPORTANT RISK: SSO announced an update to this form ("ปรับปรุงแบบรายการนำส่งเงินสมทบ สปส.1-10")
 * effective 1 ม.ค. 2569 (2026-01-01) — before today's date. This draft may reflect the
 * SUPERSEDED pre-2026 layout. Before relying on this for a real filing: confirm the current
 * layout directly with SSO (sso.go.th) or a payroll software vendor's current-year FAQ.
 *
 * Also unconfirmed: left/right alignment convention, zero-padding vs space-padding for
 * numeric fields, and file byte encoding. This implementation assumes right-aligned
 * zero-padded numerics, left-aligned space-padded text, and TIS-620 encoding (the historical
 * convention for Thai government fixed-width files) — all best-effort defaults, not verified.
 */
class Sso110Exporter implements StatutoryExportInterface {
    use FixedWidthHelperTrait;

    private const HEADER_ROW_LENGTH = 135;
    private const DETAIL_ROW_LENGTH = 135;

    /** 2026-08-29 -- the ONE version_code this class actually implements (matches
     *  master_statutory_format_versions' seed row for TH_SSO110, see that migration's own
     *  comment). StatutoryFormatVersionModel::resolveVersionCode() is what a company's selection
     *  ultimately resolves to; Sso110Report passes it through as context['version_code']. When a
     *  real 2nd version is ever coded (e.g. once the actual pre/post-Jan-2026 SSO layout is
     *  confirmed), add it here and branch on it inside generate() -- this const/check is what
     *  stops a future unimplemented version from silently generating THIS layout's output under
     *  the wrong label. */
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
     *   company: {employer_account:string, branch_no:string, name:string, contribution_rate:float},
     *   period: {year:int, month:int, payment_date:string 'YYYY-MM-DD'},
     *   employees: array<{insured_id:string, prefix_code:string, first_name:string, last_name:string, wage:float, contribution:float}>
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

        $totalWage = array_sum(array_column($employees, 'wage'));
        $totalContribution = array_sum(array_column($employees, 'contribution'));
        // Standard SSO split is 50/50 employee/employer on the same base; if the caller
        // doesn't supply an explicit split, assume an even split (draft assumption).
        $employeeContribution = $context['company']['employee_contribution_total'] ?? ($totalContribution / 2);
        $employerContribution = $context['company']['employer_contribution_total'] ?? ($totalContribution / 2);

        $paymentDate = !empty($period['payment_date']) ? date('dmy', strtotime($period['payment_date'])) : str_repeat('0', 6);
        $wagePeriod = sprintf('%02d%02d', $period['month'] ?? 1, ((int)($period['year'] ?? date('Y'))) % 100);

        $header = ''
            . $this->padNumber(1, 1)
            . $this->padText($this->digitsOnly((string)($company['employer_account'] ?? ''), 10), 10)
            . $this->padText($this->digitsOnly((string)($company['branch_no'] ?? '0'), 6), 6)
            . $paymentDate
            . $wagePeriod
            . $this->padText($this->toFileEncoding((string)($company['name'] ?? '')), 45)
            . $this->padNumber((float)($company['contribution_rate'] ?? 5.0), 4, 2)
            . $this->padNumber(count($employees), 6)
            . $this->padNumber($totalWage, 15, 2)
            . $this->padNumber($totalContribution, 14, 2)
            . $this->padNumber($employeeContribution, 12, 2)
            . $this->padNumber($employerContribution, 12, 2);
        $header = $this->assertLength($header, self::HEADER_ROW_LENGTH, 'header');

        $rows = [$header];
        foreach ($employees as $emp) {
            $detail = ''
                . $this->padNumber(2, 1)
                . $this->padText($this->digitsOnly((string)($emp['insured_id'] ?? ''), 13), 13)
                . $this->padText((string)($emp['prefix_code'] ?? ''), 3)
                . $this->padText($this->toFileEncoding((string)($emp['first_name'] ?? '')), 30)
                . $this->padText($this->toFileEncoding((string)($emp['last_name'] ?? '')), 35)
                . $this->padNumber((float)($emp['wage'] ?? 0), 14, 2)
                . $this->padNumber((float)($emp['contribution'] ?? 0), 12, 2)
                . str_repeat(' ', 27);
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
