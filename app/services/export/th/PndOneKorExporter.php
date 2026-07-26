<?php
declare(strict_types=1);
require_once __DIR__ . '/../StatutoryExportInterface.php';

/**
 * DRAFT — ภ.ง.ด.1ก (annual summary of PIT withheld from employee salary), pipe-delimited
 * text format for import into the Revenue Department's "RD Prep" tool.
 *
 * Confirmed from research (RD Prep manual references + third-party accountant guides):
 *   - Field delimiter is "|" (pipe).
 *   - Amounts are plain decimal with 2 digits, no thousands separator.
 *   - The current official spec is "รูปแบบข้อมูล (ฟอร์แมตกลาง) Version 2.0",
 *     published at https://www.rd.go.th/63724.html, updated 2025-06-16.
 *
 * NOT verified against that official PDF (Thai text in it could not be machine-extracted
 * in this environment — no OCR tool available). The per-employee field list below is a
 * best-effort reconstruction from general knowledge of what ภ.ง.ด.1ก reports (taxpayer ID,
 * name, total annual income under section 40(1)(2), tax withheld). Before relying on this
 * for a real filing: open the official PDF at the URL above and confirm field order/count,
 * especially whether a company/header record is required and whether there is a
 * "เงื่อนไขการคำนวณภาษี" or similar flag column this draft is missing.
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
        return false;
    }

    public function fileName(array $context): string {
        $year = $context['period']['tax_year'] ?? date('Y');
        return "PND1K_{$year}.txt";
    }

    /**
     * @param array $context {
     *   period: {tax_year: int (พ.ศ.)},
     *   employees: array<{tax_id:string, prefix:string, first_name:string, last_name:string,
     *                      total_income:float, tax_withheld:float, condition?:string}>
     * }
     */
    public function generate(array $context): string {
        $lines = [];
        foreach ($context['employees'] ?? [] as $emp) {
            $lines[] = implode('|', [
                $this->digits($emp['tax_id'] ?? '', 13),
                trim((string)($emp['prefix'] ?? '')),
                trim((string)($emp['first_name'] ?? '')),
                trim((string)($emp['last_name'] ?? '')),
                number_format((float)($emp['total_income'] ?? 0), 2, '.', ''),
                number_format((float)($emp['tax_withheld'] ?? 0), 2, '.', ''),
                (string)($emp['condition'] ?? '1'),
            ]);
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    private function digits(string $value, int $length): string {
        $onlyDigits = preg_replace('/\D/', '', $value) ?? '';
        return str_pad(substr($onlyDigits, 0, $length), $length, '0', STR_PAD_LEFT);
    }
}
