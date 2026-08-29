<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../../models/BankFileFormatModel.php';
require_once __DIR__ . '/../../export/FixedWidthHelperTrait.php';
require_once __DIR__ . '/../LocalizedException.php';

/**
 * 2026-08-29, rewritten to render according to a company-configurable bank file layout
 * (BankFileFormatModel — see database/migrations/2026-08-29_bank_file_format_config.sql's own
 * header comment for the full rationale, and that model's docblock for the default/company-
 * override layering). A company picks a bank_file_format_id on their payroll cycle (existing
 * field, `payroll_cycles.bank_file_format_id`), configures/edits that format's field-by-field
 * layout via the Bank Accounts settings page's "Bank File Format" sub-tab, and every run built
 * off that cycle renders through whatever the company currently has configured for it — never a
 * hardcoded per-bank layout in this class.
 *
 * Falls back to the ORIGINAL honest generic CSV (unchanged shape/columns) whenever there's
 * nothing to render from: the cycle has no bank_file_format_id at all, or that format has zero
 * fields configured anywhere (no company override AND no seeded system default) -- exactly the
 * same "no real spec, don't fabricate one" reasoning this class's own docblock already carried
 * before this rewrite, just now scoped to "unconfigured" instead of "always".
 *
 * Requires the run to be Approved+ (transfer files should only be built from finalized numbers)
 * and every included employee to be paid by bank transfer with an account number on file --
 * employees missing bank details are skipped. For a CONFIGURED (non-fallback) format, skipped
 * employees are silently excluded from the file rather than reported via an injected comment
 * row -- unlike the generic-CSV fallback, a company-configured layout (especially fixed_width)
 * is meant to be byte-for-byte what the bank's own import parser expects, and an extra line would
 * risk corrupting that contract for a real upload. The generic-CSV fallback keeps its original
 * warning-comment-row behavior unchanged.
 */
class BankTransferFileReport implements ReportGeneratorInterface {
    use EmployeePiiTrait;
    use FixedWidthHelperTrait;

    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];

    public function code(): string {
        return 'BANK_TRANSFER_FILE';
    }

    public function reportType(): string {
        return 'payment';
    }

    public function label(): array {
        return ['th' => 'ไฟล์โอนเงินธนาคาร (Bank Transfer File)', 'en' => 'Bank Transfer File'];
    }

    public function isVerified(): bool {
        return false;
    }

    public function supportedFormats(): array {
        return ['csv'];
    }

    /**
     * @param array $context { comp_id: int, run_id: int }
     */
    public function generate(array $context, string $format): array {
        $compId = (int)($context['comp_id'] ?? 0);
        if ($compId <= 0) {
            throw new LocalizedException('comp_id is required.', 'comp_id_required');
        }
        if (!isset($context['run_id']) || !is_numeric($context['run_id']) || (int)$context['run_id'] <= 0) {
            throw new LocalizedException('run_id is required and must be a positive integer.', 'run_id_required');
        }
        $runId = (int)$context['run_id'];

        $dataModel = new PayrollReportDataModel();
        $run = $dataModel->getRun($runId, $compId);
        if (!$run) {
            throw new LocalizedException('Payroll run not found.', 'run_not_found');
        }
        $dataModel->assertRunStateOrThrow($run, self::ALLOWED_STATES);
        $details = $dataModel->getRunDetails($runId);
        if (empty($details)) {
            throw new LocalizedException('This payroll run has no calculated employees yet. Recalculate it first.', 'run_no_calculated_employees');
        }

        $bankFileFormatId = isset($run['bank_file_format_id']) ? (int)$run['bank_file_format_id'] : 0;
        if ($bankFileFormatId > 0) {
            $formatModel = new BankFileFormatModel();
            $fields = $formatModel->fieldsForRender($compId, $bankFileFormatId);
            if (!empty($fields)) {
                $config = $formatModel->getConfig($compId, $bankFileFormatId);
                $company = $dataModel->getCompany($compId);
                return $this->renderConfigured($details, $fields, $config, $run, $company, $runId);
            }
        }

        return $this->renderGenericFallback($details, $runId);
    }

    /* ---------- Original generic fallback (unchanged shape) ---------- */

    private function renderGenericFallback(array $details, int $runId): array {
        $lines = ['เลขที่บัญชี,ชื่อบัญชี,ธนาคาร,รหัสธนาคาร,จำนวนเงิน,หมายเหตุ'];
        $skipped = [];
        $total = 0.0;
        foreach ($details as $d) {
            if (($d['payment_type'] ?? 'bank') !== 'bank') {
                continue;
            }
            $accountNo = $this->decryptEmployeeField($d, 'bank_account_no');
            if (empty($accountNo) || empty($d['bank_code'])) {
                $skipped[] = $d['employee_no'];
                continue;
            }
            $amount = (float)$d['net_amount'];
            $total += $amount;
            $lines[] = implode(',', [
                $this->csvField($accountNo),
                $this->csvField($d['bank_account_name'] ?? ''),
                $this->csvField($d['bank_name_th'] ?? ''),
                $this->csvField($d['bank_code'] ?? ''),
                number_format($amount, 2, '.', ''),
                $this->csvField($d['employee_no']),
            ]);
        }
        if (!empty($skipped)) {
            array_unshift($lines, '# คำเตือน: พนักงานต่อไปนี้ไม่มีเลขบัญชี/ธนาคารในระบบ ถูกข้ามจากไฟล์นี้: ' . implode(', ', $skipped));
        }
        if ($total <= 0) {
            throw new LocalizedException('No employees with a valid bank account were found to include in the transfer file.', 'bank_transfer_no_valid_accounts');
        }

        // 2026-08-29, explicit bug report: "excel csv...ไม่รองรับภาษาไทย" -- plain UTF-8 CSV with no
        // BOM opens correctly in most tools, but Microsoft Excel (the overwhelmingly common way
        // this file actually gets opened) auto-detects encoding on a bare double-click and, without
        // a BOM, very often guesses the system's legacy Thai codepage instead of UTF-8 -- every Thai
        // character then shows as mojibake even though the underlying bytes were always correct. A
        // leading UTF-8 BOM (EF BB BF) is the standard fix Excel itself recognizes. Safe here
        // unconditionally -- this fallback is always plain UTF-8 delimited text, human/Excel-facing,
        // never a byte-exact machine format a bank parser depends on.
        return [
            'content' => "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n",
            'file_name' => "BankTransfer_Run{$runId}.csv",
            'mime_type' => 'text/csv',
        ];
    }

    private function csvField(string $value): string {
        if (strpbrk($value, ",\"\r\n") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }

    /* ---------- Configured (company-defined) rendering ---------- */

    private function renderConfigured(array $details, array $fieldRows, array $config, array $run, ?array $company, int $runId): array {
        $rowsByType = ['header' => [], 'detail' => [], 'trailer' => []];
        foreach ($fieldRows as $f) {
            $rowType = $f['row_type'] ?? 'detail';
            if (isset($rowsByType[$rowType])) {
                $rowsByType[$rowType][] = $f;
            }
        }

        $included = [];
        $skippedCount = 0;
        $total = 0.0;
        foreach ($details as $d) {
            if (($d['payment_type'] ?? 'bank') !== 'bank') {
                continue;
            }
            $accountNo = $this->decryptEmployeeField($d, 'bank_account_no');
            if (empty($accountNo) || empty($d['bank_code'])) {
                $skippedCount++;
                continue;
            }
            $total += (float)$d['net_amount'];
            $included[] = $d;
        }
        if (empty($included)) {
            throw new LocalizedException('No employees with a valid bank account were found to include in the transfer file.', 'bank_transfer_no_valid_accounts');
        }

        $isFixedWidth = ($config['delimiter_type'] ?? 'delimited') === 'fixed_width';
        $delimiterChar = (string)($config['delimiter_char'] ?? ',');
        $encoding = ($config['text_encoding'] ?? 'utf8') === 'tis620' ? 'TIS-620' : null;
        $lineEnding = ($config['line_ending'] ?? 'crlf') === 'lf' ? "\n" : "\r\n";
        $aggregateContext = [
            'company_name' => $company['company_legal_name'] ?? ($company['local_name'] ?? ''),
            'pay_period' => !empty($run['period_start_date']) ? date('Ymd', strtotime((string)$run['period_start_date'])) : '',
            'total_amount' => $total,
            'total_count' => count($included),
        ];

        $lines = [];
        if (!empty($config['has_header_row']) && !empty($rowsByType['header'])) {
            $lines[] = $this->renderRow($rowsByType['header'], null, $aggregateContext, $isFixedWidth, $delimiterChar, $encoding);
        }
        $seq = 0;
        foreach ($included as $d) {
            $seq++;
            $aggregateContext['sequence_no'] = $seq;
            $lines[] = $this->renderRow($rowsByType['detail'], $d, $aggregateContext, $isFixedWidth, $delimiterChar, $encoding);
        }
        if (!empty($config['has_trailer_row']) && !empty($rowsByType['trailer'])) {
            $lines[] = $this->renderRow($rowsByType['trailer'], null, $aggregateContext, $isFixedWidth, $delimiterChar, $encoding);
        }

        $content = implode($lineEnding, $lines) . $lineEnding;
        // Same Excel-mojibake fix as the generic fallback above -- ONLY for the human/Excel-facing
        // delimited+UTF-8 case. A fixed_width file is a byte-exact machine format going to a bank's
        // own parser (never opened in Excel), and a TIS-620-encoded file already isn't UTF-8 at all
        // -- prepending a UTF-8 BOM to either would corrupt the very first field/byte a real
        // consumer expects, not fix a display problem.
        if (!$isFixedWidth && $encoding === null) {
            $content = "\xEF\xBB\xBF" . $content;
        }
        $extension = $isFixedWidth ? 'txt' : 'csv';
        return [
            'content' => $content,
            'file_name' => "BankTransfer_Run{$runId}.{$extension}",
            'mime_type' => $isFixedWidth ? 'text/plain' : 'text/csv',
        ];
    }

    /** Renders one line (header/detail/trailer) from its field definitions. $employeeRow is null
     *  for header/trailer rows (they only ever pull from $context, constants, or blanks). */
    private function renderRow(array $fields, ?array $employeeRow, array $context, bool $isFixedWidth, string $delimiterChar, ?string $encoding): string {
        $cells = [];
        foreach ($fields as $field) {
            $raw = $this->rawValueForField($field, $employeeRow, $context);
            $formatted = $this->applyDataType($raw, $field);
            if ($encoding !== null) {
                $formatted = $this->toFileEncoding($formatted, $encoding);
            }
            if ($isFixedWidth && !empty($field['width'])) {
                $formatted = $this->padByte($formatted, (int)$field['width'], (string)($field['pad_char'] ?? ' '), (string)($field['pad_direction'] ?? 'right'));
            }
            $cells[] = $formatted;
        }
        if ($isFixedWidth) {
            return implode('', $cells);
        }
        return implode($delimiterChar, array_map([$this, 'delimitedField'], $cells, array_fill(0, count($cells), $delimiterChar)));
    }

    private function delimitedField(string $value, string $delimiterChar): string {
        if (strpbrk($value, $delimiterChar . "\"\r\n") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }

    /** Byte-length pad/truncate honoring the field's own pad_char/pad_direction -- same byte-based
     *  reasoning as FixedWidthHelperTrait::padText()/padNumber(), generalized to a configurable
     *  direction/character instead of those methods' fixed conventions. */
    private function padByte(string $value, int $width, string $padChar, string $direction): string {
        $bytes = strlen($value);
        if ($bytes >= $width) {
            return substr($value, 0, $width);
        }
        $pad = str_repeat($padChar !== '' ? $padChar[0] : ' ', $width - $bytes);
        return $direction === 'left' ? $pad . $value : $value . $pad;
    }

    private function rawValueForField(array $field, ?array $d, array $context): string {
        if (($field['source_type'] ?? 'employee_field') === 'blank') {
            return '';
        }
        if (($field['source_type'] ?? '') === 'constant') {
            return (string)($field['constant_value'] ?? '');
        }
        $sourceField = (string)($field['source_field'] ?? '');
        switch ($sourceField) {
            case 'bank_account_no': return $d !== null ? (string)$this->decryptEmployeeField($d, 'bank_account_no') : '';
            case 'bank_account_name': return $d !== null ? (string)($d['bank_account_name'] ?? '') : '';
            case 'bank_code': return $d !== null ? (string)($d['bank_code'] ?? '') : '';
            case 'bank_name': return $d !== null ? (string)($d['bank_name_th'] ?? $d['bank_name_en'] ?? '') : '';
            case 'employee_no': return $d !== null ? (string)($d['employee_no'] ?? '') : '';
            case 'employee_name': return $d !== null ? $this->employeeDisplayName($d, 'th') : '';
            case 'id_card_no': return $d !== null ? (string)$this->decryptEmployeeField($d, 'id_card_no') : '';
            case 'net_amount': return $d !== null ? (string)((float)($d['net_amount'] ?? 0)) : '';
            case 'sequence_no': return (string)($context['sequence_no'] ?? '');
            case 'total_amount': return (string)($context['total_amount'] ?? 0);
            case 'total_count': return (string)($context['total_count'] ?? 0);
            case 'company_name': return (string)($context['company_name'] ?? '');
            case 'pay_period': return (string)($context['pay_period'] ?? '');
            default: return '';
        }
    }

    private function applyDataType(string $raw, array $field): string {
        switch ($field['data_type'] ?? 'text') {
            case 'number':
                $num = is_numeric($raw) ? (float)$raw : 0.0;
                $decimals = $field['decimal_places'] ?? 2;
                return number_format($num, (int)$decimals, '.', '');
            case 'date':
                if ($raw === '') return '';
                $ts = strtotime($raw);
                return $ts !== false ? date((string)($field['date_format'] ?: 'Ymd'), $ts) : '';
            default:
                return $raw;
        }
    }
}
