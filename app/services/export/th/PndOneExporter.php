<?php
declare(strict_types=1);
require_once __DIR__ . '/../StatutoryExportInterface.php';

/**
 * DRAFT — ภ.ง.ด.1 (monthly PIT withholding return), pipe-delimited text format for import
 * into the Revenue Department's "RD Prep" tool.
 *
 * Uses the SAME per-employee field shape as PndOneKorExporter (ภ.ง.ด.1ก) — tax_id, prefix,
 * first/last name, income, tax withheld, condition — because this environment could not
 * verify either form's real field layout against the official RD PDF (see
 * PndOneKorExporter's docblock for the full explanation of that limitation). Sharing the
 * shape is a deliberate, clearly-labeled simplification, not a claim that RD's actual ภ.ง.ด.1
 * and ภ.ง.ด.1ก text layouts are identical — they may well differ once verified, at which
 * point this class should be edited independently of PndOneKorExporter.
 */
class PndOneExporter implements StatutoryExportInterface {
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
     *   period: {tax_year: int (พ.ศ.), tax_month: int},
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
