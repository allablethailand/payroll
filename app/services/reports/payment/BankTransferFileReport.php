<?php
declare(strict_types=1);
require_once __DIR__ . '/../ReportGeneratorInterface.php';
require_once __DIR__ . '/../EmployeePiiTrait.php';
require_once __DIR__ . '/../../../models/PayrollReportDataModel.php';
require_once __DIR__ . '/../../../models/BankFileFormatModel.php';
require_once __DIR__ . '/../../../models/PayrollRunEmployeeBankAccountModel.php';
require_once __DIR__ . '/../../../models/EmployeePaymentMethodModel.php';
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
 *
 * 2026-09-02, multi-bank-account payroll (explicit request: "ในการตั้งค่ารอบการจ่าย ปรับให้รองรับมากกว่า
 * 1 บัญชี...ตอนออกรายงานเพื่อส่ง Cashlink ต้องถูกต้อง", confirmed via AskUserQuestion: one SEPARATE FILE
 * per company account, not one combined file with account-grouped sections -- real bank transfer
 * file formats assume ONE settlement account per file header, so mixing employees who pay from
 * different source accounts into a single file/header would misrepresent which account the whole
 * batch actually debits from). `generate()` now resolves each bank-paying employee's own company
 * account via `PayrollRunEmployeeBankAccountModel::resolveForRun()` (per-run override > employee's
 * own default_bank_account_id > cycle's pinned bank_account_id > company's is_default=1 account --
 * see that model's own docblock), groups employees by the resolved account, and renders ONE file
 * per group through the SAME renderConfigured()/renderGenericFallback() paths as before (now
 * parameterized with an explicit `$companyBankAccount`/`$fileSuffix` instead of resolving a single
 * run-wide account internally). The common case (every employee in a run resolves to the SAME one
 * account -- true for every company that hasn't touched this feature) still returns a single file
 * with the EXACT SAME file_name as before this round (`BankTransfer_Run{id}.{ext}`), byte-for-byte
 * backward compatible. Only when 2+ distinct accounts are actually in play does this return a ZIP
 * bundling one correctly-named file per account -- see `bundleFiles()`.
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
        $fields = [];
        $config = [];
        if ($bankFileFormatId > 0) {
            $formatModel = new BankFileFormatModel();
            $fields = $formatModel->fieldsForRender($compId, $bankFileFormatId);
            if (!empty($fields)) {
                $config = $formatModel->getConfig($compId, $bankFileFormatId);
            }
        }
        $isConfigured = !empty($fields);
        $company = $isConfigured ? $dataModel->getCompany($compId) : null;

        // 2026-09-02, multi-bank-account payroll -- group bank-paying employees by which company
        // account actually pays them (see this class's own docblock), one rendered file per group.
        $bankAccountModel = new PayrollRunEmployeeBankAccountModel();
        $resolved = $bankAccountModel->resolveForRun($runId, $compId);
        // 2026-09-02, mixed payment method -- resolved entirely HERE, in generate()'s own grouping
        // loop, so renderConfigured()/renderGenericFallback() below need ZERO changes: each mixed
        // employee's own TRANSFER lines become synthetic detail-shaped rows (same fields a normal
        // detail row has, net_amount_due overridden to just that ONE line's own resolved amount)
        // and are grouped into the SAME per-account buckets as any plain transfer employee. Cash/
        // check lines are not this report's concern (PayrollRunCashPaymentModel's own mirror of this
        // same logic handles the cash side). A mixed employee's FULL line set only gets included if
        // it genuinely reconciles: percent lines are always resolvable now (against net_amount_due,
        // known at this point), but a set containing a FIXED line can only be checked here, at
        // report-generation time -- if the total doesn't equal net_amount_due, the employee's
        // transfer lines are skipped entirely (never guess/partially disburse) and their employee_no
        // is surfaced the same "warning comment row" way missing-bank-details already is below.
        $paymentMethodModel = new EmployeePaymentMethodModel();
        $mixedReconciliationWarnings = [];
        $groups = [];
        foreach ($details as $d) {
            $resolvedCode = $d['payment_method_code'] ?? 'transfer';
            if ($resolvedCode === 'transfer') {
                $employeeId = (int)$d['employee_id'];
                $accId = $resolved[$employeeId]['bank_account_id'] ?? null;
                $key = $accId !== null ? (string)$accId : '__none__';
                if (!isset($groups[$key])) {
                    $groups[$key] = ['bank_account_id' => $accId, 'details' => []];
                }
                $groups[$key]['details'][] = $d;
            } elseif ($resolvedCode === 'mixed') {
                $employeeId = (int)$d['employee_id'];
                $lines = $paymentMethodModel->getLines($employeeId);
                $transferLines = array_values(array_filter($lines, fn($l) => ($l['payment_method_code'] ?? '') === 'transfer'));
                if (empty($transferLines)) {
                    continue;
                }
                $netAmountDue = (float)($d['net_amount_due'] ?? $d['net_amount'] ?? 0);
                $hasFixed = false;
                $totalResolved = 0.0;
                $amountsByLineId = [];
                foreach ($lines as $l) {
                    $amt = $l['amount_type'] === 'percent'
                        ? round($netAmountDue * (float)$l['amount_value'] / 100, 2)
                        : (float)$l['amount_value'];
                    if ($l['amount_type'] === 'fixed') {
                        $hasFixed = true;
                    }
                    $amountsByLineId[$l['id']] = $amt;
                    $totalResolved += $amt;
                }
                if ($hasFixed && abs($totalResolved - $netAmountDue) > 0.01) {
                    $mixedReconciliationWarnings[] = $d['employee_no'];
                    continue;
                }
                foreach ($transferLines as $l) {
                    $accId = (int)$l['bank_account_id'];
                    $key = (string)$accId;
                    if (!isset($groups[$key])) {
                        $groups[$key] = ['bank_account_id' => $accId, 'details' => []];
                    }
                    $syntheticDetail = $d;
                    $syntheticDetail['net_amount_due'] = $amountsByLineId[$l['id']];
                    $syntheticDetail['net_amount'] = $amountsByLineId[$l['id']];
                    $groups[$key]['details'][] = $syntheticDetail;
                }
            }
            // cash/check are not this report's concern, same as before this feature existed. An
            // employee with payment_method_id still NULL (never assigned one) falls back to
            // 'transfer' above, same "unset = bank/transfer" default the old payment_type enum had.
        }
        if (empty($groups)) {
            throw new LocalizedException('No employees with a valid bank account were found to include in the transfer file.', 'bank_transfer_no_valid_accounts');
        }

        // Suffix/split-filename only makes sense once there's genuinely more than one group -- the
        // common single-account case (every employee resolves to the SAME account) must keep the
        // EXACT SAME filename this report always returned before this round.
        $isMultiAccount = count($groups) > 1;
        $files = [];
        foreach ($groups as $group) {
            $companyBankAccount = $group['bank_account_id'] !== null
                ? $this->accountInfoById($compId, $group['bank_account_id'])
                : ['account_no' => '', 'company_code' => '', 'label' => ''];
            if (!$isMultiAccount) {
                $companyBankAccount['label'] = '';
            }
            try {
                if ($isConfigured) {
                    $files[] = $this->renderConfigured($group['details'], $fields, $config, $run, $company, $runId, $compId, $language, $companyBankAccount);
                } else {
                    $files[] = $this->renderGenericFallback($group['details'], $runId, $companyBankAccount['label'], $mixedReconciliationWarnings);
                }
            } catch (LocalizedException $e) {
                // 2026-09-02: a group where every employee happens to be missing bank details
                // (rare, but possible) is skipped rather than failing the WHOLE multi-account
                // export -- each per-employee skip is already surfaced (warning-comment-row for
                // the generic fallback, silent exclusion for a configured format, same as before
                // this round), only the top-level "nothing at all was included" case below is a
                // hard failure.
                if ($e->getErrorKey() !== 'bank_transfer_no_valid_accounts') {
                    throw $e;
                }
            }
        }
        if (empty($files)) {
            throw new LocalizedException('No employees with a valid bank account were found to include in the transfer file.', 'bank_transfer_no_valid_accounts');
        }
        if (count($files) === 1) {
            return $files[0];
        }
        return $this->bundleFiles($files, $runId);
    }

    /** Zips 2+ per-account files together -- only reached when a run genuinely has employees
     *  paying from 2+ DIFFERENT company accounts (the common single-account case never gets here,
     *  see generate()'s own docblock). Uses a real temp file (ZipArchive has no in-memory-only
     *  mode) cleaned up immediately after reading its bytes back. */
    private function bundleFiles(array $files, int $runId): array {
        $tmpPath = tempnam(sys_get_temp_dir(), 'banktransfer_') . '.zip';
        $zip = new \ZipArchive();
        $zip->open($tmpPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($files as $file) {
            $zip->addFromString($file['file_name'], $file['content']);
        }
        $zip->close();
        $content = (string)file_get_contents($tmpPath);
        unlink($tmpPath);
        return [
            'content' => $content,
            'file_name' => "BankTransfer_Run{$runId}.zip",
            'mime_type' => 'application/zip',
        ];
    }

    /**
     * Company's own settlement/debit account info for ONE specific `bank_accounts` row -- needed
     * for a header row like Krungsri's own "เลขที่บัญชีตัดเงินของบริษัท" and "รหัสบริษัท/รหัสบริการ".
     * 2026-09-02, multi-bank-account payroll -- replaced the OLD `resolveCompanyBankAccount()`
     * (which resolved ONE account for the whole run via cycle-pin > company-default) now that
     * resolution happens PER EMPLOYEE, one level up in generate()'s own grouping loop
     * (PayrollRunEmployeeBankAccountModel::resolveForRun()) -- this method just looks up the
     * DISPLAY info for whichever specific account id that resolution already decided on. account_no
     * decrypted the same way BankAccountModel itself decrypts it (EncryptionService::decrypt()
     * keyed by that row's own key_version) -- NOT EmployeePiiTrait, which is scoped to per-employee
     * PII, not the company's own account. `label` is a filesystem-safe filename fragment
     * ("KBank_Payroll", spaces/punctuation stripped) used only when 2+ accounts are in play and
     * this run's file has to split into one-file-per-account (see bundleFiles()).
     * @return array{account_no: string, company_code: string, label: string}
     */
    private function accountInfoById(int $compId, int $bankAccountId): array {
        $pdo = Database::getInstance()->pdo;
        $stmt = $pdo->prepare(
            "SELECT ba.account_no, ba.key_version, ba.company_code, ba.account_name, mb.bank_name_en
             FROM `bank_accounts` ba LEFT JOIN `master_banks` mb ON mb.id = ba.bank_id
             WHERE ba.id = :id AND ba.comp_id = :comp_id AND ba.deleted_at IS NULL"
        );
        $stmt->execute([':id' => $bankAccountId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return ['account_no' => '', 'company_code' => '', 'label' => ''];
        }
        $accountNo = !empty($row['account_no'])
            ? (string)(EncryptionService::decrypt($row['account_no'], $row['key_version'] !== null ? (int)$row['key_version'] : null) ?? '')
            : '';
        $labelSource = trim(($row['bank_name_en'] ?? '') . '_' . ($row['account_name'] ?? ''), '_');
        $label = preg_replace('/[^A-Za-z0-9_]+/', '', str_replace(' ', '_', $labelSource)) ?: "Account{$bankAccountId}";
        return ['account_no' => $this->digitsOnlyAccountNo($accountNo), 'company_code' => (string)($row['company_code'] ?? ''), 'label' => $label];
    }

    /**
     * 2026-08-29, real bug found and fixed (explicit report: "เลขบัญชีต้องมีขีดหรือไม่ใส่ขีดช่วยปรับให้
     * ด้วยครับ"): both BankAccountModel::save() (the company's own settlement account) and
     * employees.bank_account_no (an employee's own account, entered free-text with no format
     * validation at all) allow dashes as a human-readable display convention (e.g.
     * "168-1-52209-0") -- a fixed-width machine field must never contain them: at best they
     * silently eat digit positions the account number itself needs (a 10-digit account formatted
     * with 3 dashes is 13 characters, so padByte()'s own truncate-to-width would cut off the
     * LAST 3 real digits to make room for punctuation that was never meant to be there), at worst
     * they just confuse the receiving bank's own parser, which expects digits only. Strips
     * everything but digits before the field is ever padded/truncated.
     */
    private function digitsOnlyAccountNo(string $value): string {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    /* ---------- Original generic fallback (unchanged shape) ---------- */

    /** $fileSuffix: non-empty only when this run's employees split across 2+ company accounts (see
     *  generate()'s own grouping loop) -- appended to the filename so each per-account file in the
     *  resulting zip is distinguishable; empty string for the (common) single-account case keeps
     *  the exact same filename this method always returned before this round.
     *  $extraWarnings: employee_no values generate()'s own mixed-payment reconciliation check
     *  already decided to skip (see that method's own docblock) -- merged into this method's own
     *  missing-bank-details warning line.
     *  2026-09-02: the old `payment_type !== 'bank'` filter here was REMOVED -- generate()'s own
     *  grouping loop already decides exactly which detail rows belong in $details (plain transfer
     *  employees AND mixed employees' own synthetic transfer-line rows, whose copied
     *  `payment_method_code` is the employee's real top-level value, e.g. 'mixed', not 'transfer' --
     *  re-filtering on it here would have wrongly excluded every mixed employee's transfer lines).
     *  This method now trusts its caller completely for inclusion; it only decides
     *  SKIP-for-missing-bank-details among rows
     *  it was already given. */
    private function renderGenericFallback(array $details, int $runId, string $fileSuffix = '', array $extraWarnings = []): array {
        $lines = ['เลขที่บัญชี,ชื่อบัญชี,ธนาคาร,รหัสธนาคาร,จำนวนเงิน,หมายเหตุ'];
        $skipped = $extraWarnings;
        $total = 0.0;
        foreach ($details as $d) {
            $accountNo = $this->decryptEmployeeField($d, 'bank_account_no');
            if (empty($accountNo) || empty($d['bank_code'])) {
                $skipped[] = $d['employee_no'];
                continue;
            }
            // 2026-08-31: net_amount_due (not the plain net_amount column) -- the DELTA still owed
            // this payment cycle after subtracting whatever payroll_run_payment_events already
            // recorded as disbursed on a prior cycle (0 for the overwhelmingly common case of a
            // run's first-ever payment, where this is byte-identical to net_amount). An employee
            // with nothing new to disburse this cycle (e.g. untouched by whatever merge triggered
            // this reopen+repay) is skipped entirely -- see PayrollReportDataModel::getRunDetails()'s
            // own docblock on these columns.
            $amount = (float)($d['net_amount_due'] ?? $d['net_amount']);
            if ($amount <= 0) {
                continue;
            }
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
        $suffixPart = $fileSuffix !== '' ? "_{$fileSuffix}" : '';
        return [
            'content' => "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n",
            'file_name' => "BankTransfer_Run{$runId}{$suffixPart}.csv",
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

    /** $companyBankAccount: {account_no, company_code, label} for the ONE account this group's
     *  employees actually pay from (see accountInfoById()) -- resolved by generate()'s own grouping
     *  loop, no longer looked up internally here. */
    private function renderConfigured(array $details, array $fieldRows, array $config, array $run, ?array $company, int $runId, int $compId, string $language, array $companyBankAccount): array {
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
        // 2026-09-02: same removal as renderGenericFallback()'s own copy of this filter -- see that
        // method's own comment. generate() already decided inclusion for every row in $details.
        foreach ($details as $d) {
            $accountNo = $this->decryptEmployeeField($d, 'bank_account_no');
            if (empty($accountNo) || empty($d['bank_code'])) {
                $skippedCount++;
                continue;
            }
            // 2026-08-31: net_amount_due, same delta reasoning as renderGenericFallback() above --
            // skip an employee with nothing new to disburse this payment cycle.
            $dueAmount = (float)($d['net_amount_due'] ?? $d['net_amount']);
            if ($dueAmount <= 0) {
                $skippedCount++;
                continue;
            }
            $total += $dueAmount;
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
        // 2026-08-29, explicit follow-up: "ในแต่ละรอบการจ่ายอาจใช้เลขแยกกันครับ แยกบัญชีในการจ่าย" -- 2026-09-02:
        // now resolved PER EMPLOYEE one level up (generate()'s own grouping loop via
        // PayrollRunEmployeeBankAccountModel::resolveForRun()), passed in as $companyBankAccount --
        // every employee in THIS call's own $details already resolved to the SAME account, so one
        // header value is correct for the whole group.
        $aggregateContext = [
            'company_name' => $language === 'en' ? ($company['company_legal_name'] ?? ($company['local_name'] ?? '')) : ($company['local_name'] ?? ($company['company_legal_name'] ?? '')),
            'pay_period' => !empty($run['period_start_date']) ? date('Ymd', strtotime((string)$run['period_start_date'])) : '',
            'payment_date' => !empty($run['payment_date']) ? date('Ymd', strtotime((string)$run['payment_date'])) : '',
            'company_account_no' => $companyBankAccount['account_no'],
            'company_service_code' => $companyBankAccount['company_code'],
            'total_amount' => $total,
            'total_count' => count($included),
        ];

        // 2026-08-29, explicit report: "มันมีตรงชื่อที่ติดกันกับตัวเลขในลำดับต่อไปครับ" -- a name (or any
        // other variable-content field) that happens to be exactly as wide, or WIDER, than its
        // fixed-width column has no padding space left before the next field starts, so it visually
        // runs into whatever follows. When the source content is only exactly as wide as the column
        // (this report's own trigger case: "จักรกฤษณ์ สุวรรณภูมิ" is genuinely 20 bytes in TIS-620, its
        // real complete name, nothing lost), that's harmless -- Cashlink and any other fixed-width
        // parser reads every field by its fixed byte POSITION, never by scanning for a whitespace
        // gap, so the file itself is still 100% correct. But if the source content is WIDER than the
        // column, padByte() genuinely truncates it -- silent data loss (a chopped name) that a bank
        // transfer file can't tolerate (wrong/incomplete legal name reaching the bank). $widthOverflows
        // collects only the genuine-overflow case (checked BEFORE padByte() ever truncates anything,
        // comparing the real formatted-but-unpadded byte length against the column width) so this
        // report can hard-block the download and name exactly which employee/field is affected,
        // rather than silently shipping a file with a chopped legal name to the bank -- same "ห้าม
        // generate ไฟล์เปล่าเงียบๆ" standing convention as the "no valid accounts" check above.
        $widthOverflows = [];
        $lines = [];
        if (!empty($config['has_header_row']) && !empty($rowsByType['header'])) {
            $lines[] = $this->renderRow($rowsByType['header'], null, $aggregateContext, $isFixedWidth, $delimiterChar, $encoding, $language, $widthOverflows, null);
        }
        $seq = 0;
        foreach ($included as $d) {
            $seq++;
            $aggregateContext['sequence_no'] = $seq;
            $lines[] = $this->renderRow($rowsByType['detail'], $d, $aggregateContext, $isFixedWidth, $delimiterChar, $encoding, $language, $widthOverflows, $d['employee_no'] ?? ('#' . $seq));
        }
        if (!empty($config['has_trailer_row']) && !empty($rowsByType['trailer'])) {
            $lines[] = $this->renderRow($rowsByType['trailer'], null, $aggregateContext, $isFixedWidth, $delimiterChar, $encoding, $language, $widthOverflows, null);
        }
        if (!empty($widthOverflows)) {
            // 2026-08-30: `details` added as a real param (was baked directly into the English
            // message only, with no way for a translated template to include it) -- see this
            // class's own docblock note in ReportsController's fix for why that mattered.
            $details = implode('; ', $widthOverflows);
            throw new LocalizedException(
                "The following values are too long for their fixed-width column and would be cut off in the file: {$details}. Widen the field in Bank File Format settings, or shorten the source data, before exporting.",
                'bank_transfer_width_overflow',
                ['details' => $details]
            );
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
        // 2026-09-02, multi-bank-account payroll -- see renderGenericFallback()'s own identical
        // comment on $fileSuffix; empty (the common single-account case) keeps this filename
        // byte-identical to before this round.
        $suffixPart = !empty($companyBankAccount['label']) ? "_{$companyBankAccount['label']}" : '';
        return [
            'content' => $content,
            'file_name' => "BankTransfer_Run{$runId}{$suffixPart}.{$extension}",
            'mime_type' => $isFixedWidth ? 'text/plain' : 'text/csv',
        ];
    }

    /** Renders one line (header/detail/trailer) from its field definitions. $employeeRow is null
     *  for header/trailer rows (they only ever pull from $context, constants, or blanks).
     *  $widthOverflows (by reference) collects a human-readable note for any field whose real,
     *  formatted-but-not-yet-padded content is wider than its column -- see the "มันมีตรงชื่อที่ติดกัน"
     *  docblock on the caller (renderConfigured()) for why this check has to happen HERE, before
     *  padByte() truncates it, rather than after. $rowLabel identifies which row this is in a
     *  message (an employee_no for a detail row, null for header/trailer -- those have no genuine
     *  variable-length content in this format today, but the check stays generic rather than
     *  detail-row-only in case a future company config adds one). */
    private function renderRow(array $fields, ?array $employeeRow, array $context, bool $isFixedWidth, string $delimiterChar, ?string $encoding, string $language, array &$widthOverflows, ?string $rowLabel): string {
        $cells = [];
        foreach ($fields as $field) {
            $raw = $this->rawValueForField($field, $employeeRow, $context, $language);
            $formatted = $this->applyDataType($raw, $field, $isFixedWidth);
            if ($encoding !== null) {
                $formatted = $this->toFileEncoding($formatted, $encoding);
            }
            if ($isFixedWidth && !empty($field['width'])) {
                $width = (int)$field['width'];
                if (strlen($formatted) > $width) {
                    $label = $rowLabel !== null
                        ? (($field['field_label_th'] ?? $field['source_field'] ?? 'field') . " (พนักงาน {$rowLabel})")
                        : (string)($field['field_label_th'] ?? $field['source_field'] ?? 'field');
                    $widthOverflows[] = $label;
                }
                $formatted = $this->padByte($formatted, $width, (string)($field['pad_char'] ?? ' '), (string)($field['pad_direction'] ?? 'right'), $encoding === null);
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

    /**
     * Byte-length pad/truncate honoring the field's own pad_char/pad_direction -- same byte-based
     * reasoning as FixedWidthHelperTrait::padText()/padNumber(), generalized to a configurable
     * direction/character instead of those methods' fixed conventions.
     *
     * 2026-08-29, real bug found and fixed (explicit report: "เลือกเป็น UTF-8 แล้วแต่่ยังอ่านไม่ออก" --
     * selected UTF-8 encoding, Thai text still unreadable): when $isUtf8Content is true (the
     * company picked text_encoding=utf8, so toFileEncoding()/TIS-620 conversion never ran and the
     * string is still genuine multi-byte UTF-8), a plain substr($value, 0, $width) truncation --
     * correct and safe for single-byte TIS-620 content, which is what this method was written and
     * tested against first -- can slice a multi-byte UTF-8 character IN HALF whenever a real name
     * is longer than its column width in bytes (routine for Thai text: 3 bytes/char in UTF-8 vs 1
     * byte/char in TIS-620, so a name that fit a 30-byte TIS-620 column can easily be 60-90+ bytes
     * in UTF-8). The truncated tail byte(s) of that split character are what actually produced the
     * "ไอ¸—ไอ¸´"-style garbage the report described -- not a viewer/encoding-detection problem, a
     * genuinely corrupted, invalid UTF-8 byte sequence baked into the file itself. Fixed by using
     * mb_strcut() (a byte-LIMIT truncation that still respects character boundaries) instead of
     * substr() specifically for the UTF-8 case; TIS-620 content keeps the original safe substr()
     * path unchanged, since 1-byte-per-char makes byte-position truncation inherently safe there.
     */
    private function padByte(string $value, int $width, string $padChar, string $direction, bool $isUtf8Content = false): string {
        $bytes = strlen($value);
        if ($bytes >= $width) {
            $value = $isUtf8Content ? mb_strcut($value, 0, $width, 'UTF-8') : substr($value, 0, $width);
            // mb_strcut() only cuts on whole-character boundaries, so it can legitimately return
            // FEWER than $width bytes (whenever the byte limit falls in the middle of a
            // multi-byte character -- that whole character is dropped rather than split). Pad
            // the shortfall back out with spaces so this field still occupies exactly $width
            // bytes -- otherwise every field after it in the row would silently shift left,
            // corrupting the fixed-width alignment of the rest of the line, not just this field.
            $shortfall = $width - strlen($value);
            if ($shortfall > 0) {
                $pad = str_repeat(' ', $shortfall);
                $value = $direction === 'left' ? $pad . $value : $value . $pad;
            }
            return $value;
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
            case 'bank_account_no': return $d !== null ? $this->digitsOnlyAccountNo((string)$this->decryptEmployeeField($d, 'bank_account_no')) : '';
            case 'bank_account_name': return $d !== null ? (string)($d['bank_account_name'] ?? '') : '';
            case 'bank_code': return $d !== null ? (string)($d['bank_code'] ?? '') : '';
            case 'bank_name': return $d !== null ? (string)($language === 'en' ? ($d['bank_name_en'] ?? $d['bank_name_th'] ?? '') : ($d['bank_name_th'] ?? $d['bank_name_en'] ?? '')) : '';
            case 'employee_no': return $d !== null ? (string)($d['employee_no'] ?? '') : '';
            case 'employee_name': return $d !== null ? $this->employeeDisplayName($d, $language) : '';
            case 'id_card_no': return $d !== null ? (string)$this->decryptEmployeeField($d, 'id_card_no') : '';
            // 2026-08-31: net_amount_due (see renderGenericFallback()'s own comment on this same
            // column) -- $d here is already one of renderConfigured()'s own $included rows, which
            // are pre-filtered to due>0, so this always reflects a real amount being transferred.
            case 'net_amount': return $d !== null ? (string)((float)($d['net_amount_due'] ?? $d['net_amount'] ?? 0)) : '';
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
