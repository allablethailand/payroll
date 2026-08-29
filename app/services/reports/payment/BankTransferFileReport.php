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
     * @param array $context { comp_id: int, run_id: int, language?: 'th'|'en' }
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
        // 2026-08-29, explicit request: "ตอน Export ให้เลือกเพิ่มเติมได้ว่าเอาภาษาไทยหรือภาษาอังกฤษ ข้อมูลที่
        // ออกมาจะตามนั้นครับ" -- defaults to 'th' (every call site before this change effectively
        // got Thai, hardcoded), so every existing caller keeps working unchanged. The defaulted
        // value is captured in its own variable BEFORE the validity check re-reads it -- re-reading
        // $context['language'] directly inside the ternary's true-branch was a real bug caught once
        // already in this codebase (PaySlipReport::generate(), see that class's own docblock) when
        // the key is genuinely absent: 'Undefined array key' + a TypeError downstream. Not repeating
        // it here.
        $requestedLanguage = $context['language'] ?? 'th';
        $language = in_array($requestedLanguage, ['th', 'en'], true) ? $requestedLanguage : 'th';

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
                return $this->renderConfigured($details, $fields, $config, $run, $company, $runId, $compId, $language);
            }
        }

        return $this->renderGenericFallback($details, $runId);
    }

    /**
     * Company's own settlement/debit account (the source account the bank debits for the whole
     * payroll batch) -- needed for a header row like Krungsri's own "เลขที่บัญชีตัดเงินของบริษัท" and
     * "รหัสบริษัท/รหัสบริการ". 2026-08-29, explicit follow-up request: "ในแต่ละรอบการจ่ายอาจใช้เลขแยกกันครับ
     * แยกบัญชีในการจ่าย" -- a company may run multiple payroll cycles that each settle from a
     * DIFFERENT bank_accounts row (each with its own bank-registered Company/Service Code, see
     * migrations/2026-08-29_5_payroll_cycle_bank_account_and_company_code.sql's own header
     * comment). Resolution order: 1) the run's own cycle's `bank_account_id` if the cycle has one
     * pinned, 2) else the company's single `is_default=1` account (unchanged fallback -- every
     * cycle that predates this feature, or was simply never given a specific account, keeps
     * working exactly as before). account_no decrypted the same way BankAccountModel itself
     * decrypts it (EncryptionService::decrypt() keyed by that row's own key_version) -- NOT
     * EmployeePiiTrait, which is scoped to per-employee PII, not the company's own account.
     * Returns ['account_no'=>'', 'company_code'=>''] (never throws) when nothing resolves -- a
     * missing constant/blank field in the rendered output is a config problem for the company to
     * notice and fix, not a reason to hard-fail the whole file.
     * @return array{account_no: string, company_code: string}
     */
    private function resolveCompanyBankAccount(int $compId, ?int $cycleBankAccountId): array {
        $pdo = Database::getInstance()->pdo;
        $row = null;
        if ($cycleBankAccountId !== null && $cycleBankAccountId > 0) {
            $stmt = $pdo->prepare(
                "SELECT account_no, key_version, company_code FROM `bank_accounts`
                 WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL AND status = 'active'"
            );
            $stmt->execute([':id' => $cycleBankAccountId, ':comp_id' => $compId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($row === null) {
            $stmt = $pdo->prepare(
                "SELECT account_no, key_version, company_code FROM `bank_accounts`
                 WHERE comp_id = :comp_id AND deleted_at IS NULL AND status = 'active' AND is_default = 1
                 ORDER BY id ASC LIMIT 1"
            );
            $stmt->execute([':comp_id' => $compId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$row) {
            return ['account_no' => '', 'company_code' => ''];
        }
        $accountNo = !empty($row['account_no'])
            ? (string)(EncryptionService::decrypt($row['account_no'], $row['key_version'] !== null ? (int)$row['key_version'] : null) ?? '')
            : '';
        return ['account_no' => $accountNo, 'company_code' => (string)($row['company_code'] ?? '')];
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

    private function renderConfigured(array $details, array $fieldRows, array $config, array $run, ?array $company, int $runId, int $compId, string $language): array {
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
        // 2026-08-29, explicit request: "ตอน Export ให้เลือกเพิ่มเติมได้ว่าเอาภาษาไทยหรือภาษาอังกฤษ" --
        // company_name now follows the requested $language (was always company_legal_name, i.e.
        // always the English/legal name regardless of what was asked for) same as employee_name in
        // rawValueForField() below. "มีส่วนไหนที่ยังไม่มีให้ตั้งค่าเรื่องบัญชี" -- company_account_no/
        // payment_date are genuinely new: the header row previously had NO way at all to pull the
        // company's own settlement account or the run's real disbursement date (see
        // BankFileFormatModel::SOURCE_FIELDS' own comment on both).
        // 2026-08-29, explicit follow-up: "ในแต่ละรอบการจ่ายอาจใช้เลขแยกกันครับ แยกบัญชีในการจ่าย" -- resolved
        // from the RUN's own cycle (run['bank_account_id'], from PayrollReportDataModel::getRun()'s
        // own new join) when that cycle has a specific account pinned, else the company's single
        // default account -- see resolveCompanyBankAccount()'s own docblock.
        $companyBankAccount = $this->resolveCompanyBankAccount($compId, isset($run['bank_account_id']) ? (int)$run['bank_account_id'] : null);
        $aggregateContext = [
            'company_name' => $language === 'en' ? ($company['company_legal_name'] ?? ($company['local_name'] ?? '')) : ($company['local_name'] ?? ($company['company_legal_name'] ?? '')),
            'pay_period' => !empty($run['period_start_date']) ? date('Ymd', strtotime((string)$run['period_start_date'])) : '',
            'payment_date' => !empty($run['payment_date']) ? date('Ymd', strtotime((string)$run['payment_date'])) : '',
            'company_account_no' => $companyBankAccount['account_no'],
            'company_service_code' => $companyBankAccount['company_code'],
            'total_amount' => $total,
            'total_count' => count($included),
        ];

        $lines = [];
        if (!empty($config['has_header_row']) && !empty($rowsByType['header'])) {
            $lines[] = $this->renderRow($rowsByType['header'], null, $aggregateContext, $isFixedWidth, $delimiterChar, $encoding, $language);
        }
        $seq = 0;
        foreach ($included as $d) {
            $seq++;
            $aggregateContext['sequence_no'] = $seq;
            $lines[] = $this->renderRow($rowsByType['detail'], $d, $aggregateContext, $isFixedWidth, $delimiterChar, $encoding, $language);
        }
        if (!empty($config['has_trailer_row']) && !empty($rowsByType['trailer'])) {
            $lines[] = $this->renderRow($rowsByType['trailer'], null, $aggregateContext, $isFixedWidth, $delimiterChar, $encoding, $language);
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
    private function renderRow(array $fields, ?array $employeeRow, array $context, bool $isFixedWidth, string $delimiterChar, ?string $encoding, string $language): string {
        $cells = [];
        foreach ($fields as $field) {
            $raw = $this->rawValueForField($field, $employeeRow, $context, $language);
            $formatted = $this->applyDataType($raw, $field, $isFixedWidth);
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

    /** $language: 'th'|'en' -- 2026-08-29, explicit request: "ตอน Export ให้เลือกเพิ่มเติมได้ว่าเอาภาษาไทยหรือ
     *  ภาษาอังกฤษ ข้อมูลที่ออกมาจะตามนั้นครับ". Applied everywhere a th/en pair actually exists in the
     *  underlying data (employee_name, bank_name, company_name in renderConfigured()'s own
     *  aggregateContext) -- bank_account_name is deliberately NOT included, since that's a single
     *  free-text field as registered with the bank (not a th/en pair the system tracks), and
     *  id_card_no/employee_no/amounts have no language concept at all. */
    private function rawValueForField(array $field, ?array $d, array $context, string $language = 'th'): string {
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
            case 'bank_name': return $d !== null ? (string)($language === 'en' ? ($d['bank_name_en'] ?? $d['bank_name_th'] ?? '') : ($d['bank_name_th'] ?? $d['bank_name_en'] ?? '')) : '';
            case 'employee_no': return $d !== null ? (string)($d['employee_no'] ?? '') : '';
            case 'employee_name': return $d !== null ? $this->employeeDisplayName($d, $language) : '';
            case 'id_card_no': return $d !== null ? (string)$this->decryptEmployeeField($d, 'id_card_no') : '';
            case 'net_amount': return $d !== null ? (string)((float)($d['net_amount'] ?? 0)) : '';
            case 'sequence_no': return (string)($context['sequence_no'] ?? '');
            case 'total_amount': return (string)($context['total_amount'] ?? 0);
            case 'total_count': return (string)($context['total_count'] ?? 0);
            case 'company_name': return (string)($context['company_name'] ?? '');
            case 'pay_period': return (string)($context['pay_period'] ?? '');
            case 'payment_date': return (string)($context['payment_date'] ?? '');
            case 'company_account_no': return (string)($context['company_account_no'] ?? '');
            case 'company_service_code': return (string)($context['company_service_code'] ?? '');
            default: return '';
        }
    }

    /**
     * 2026-08-29, real gap found and fixed while implementing Krungsri's real spec (explicit
     * request: "ปรับ Format นี้ให้เป็น Format มาตรฐานของกรุงศรี"): a fixed-width numeric field in a
     * bank/government machine format is virtually always "implied decimal, zero-padded, no literal
     * separator character" -- e.g. Krungsri's own total-amount example `00000008873025` (14 digits)
     * IS 88,730.25, with the last 2 digits being the decimal places, not a `.` character consuming
     * one of the 14 column positions. The previous number_format($num, $decimals, '.', '') always
     * inserted a literal '.', which would have silently shortened every fixed-width numeric field
     * by one character and shifted everything after it out of position -- exactly the kind of
     * corruption a byte-exact bank file can't tolerate. Delimited/CSV output (human/Excel-facing)
     * keeps the literal '.' unchanged, since that's what a person reading a CSV expects.
     */
    private function applyDataType(string $raw, array $field, bool $isFixedWidth = false): string {
        switch ($field['data_type'] ?? 'text') {
            case 'number':
                $num = is_numeric($raw) ? (float)$raw : 0.0;
                $decimals = (int)($field['decimal_places'] ?? 2);
                if ($isFixedWidth && !empty($field['width'])) {
                    return $this->padNumber($num, (int)$field['width'], $decimals);
                }
                return number_format($num, $decimals, '.', '');
            case 'date':
                if ($raw === '') return '';
                $ts = strtotime($raw);
                return $ts !== false ? date((string)($field['date_format'] ?: 'Ymd'), $ts) : '';
            default:
                return $raw;
        }
    }
}
