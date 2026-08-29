<?php
declare(strict_types=1);

/**
 * 2026-08-29, explicit request: "ไฟล์ในการขึ้นธนาคาร...หา Format ของธนาคารกรุงศรีอยูธยามาเป็นต้นแบบ เก็บไว้ใน
 * Database และให้เพิ่มให้สามารถตั้งค่าเองได้ว่า Format เป็นแบบไหนให้แก้ไขได้ แต่ต้องเก็บ Log โดย Default ธนาคารให้
 * ดึงมาจาก ธนาคารที่บริษัทมีการนำเข้าระบบ...โดยที่ไม่ต้อง Fixed Code แต่ให้มี Master ดึงมาเป็น Default เท่าที่จะหาได้"
 * -- see database/migrations/2026-08-29_bank_file_format_config.sql's own header comment for the
 * full schema rationale (3 new tables) and why BAY is seeded as an editable DRAFT rather than a
 * fabricated verified spec (no public Krungsri CashLink bulk-payment spec exists to research — a
 * real bank's proprietary corporate-banking doc, confirmed via web search, not guessed).
 *
 * Layering:
 *  - `bank_file_format_fields` with comp_id IS NULL = the shipped SYSTEM DEFAULT layout for a given
 *    `master_bank_file_formats` row (today: BAY only). A company never edits these rows directly --
 *    the first time a company saves ANY field for a bank_file_format_id, `forkDefaultIfNeeded()`
 *    clones the whole default set into comp_id-scoped rows first, so the shared template other
 *    companies still see is never mutated by one company's customization.
 *  - `bank_file_format_configs` is the per-(comp_id, bank_file_format_id) header (delimiter style,
 *    encoding, header/trailer toggles, the company-settable `is_verified` flag). No row here yet =
 *    the company has never opened/saved this format's settings -- `getFormatDetail()` synthesizes a
 *    virtual default header (delimited/comma/crlf/utf8/unverified) so the UI always has something
 *    sensible to show without a row existing.
 *  - `bank_file_format_edit_logs` is an append-only audit trail (explicit "ต้องเก็บ Log" requirement)
 *    -- every config save, field save, field delete, and reset-to-default writes one row here. No
 *    UPDATE/DELETE method exists for this table on purpose, same convention as
 *    ReportExportLogModel/ApprovalRequestModel's own log tables elsewhere in this app.
 *
 * BankTransferFileReport (app/services/reports/payment/BankTransferFileReport.php) is the one real
 * consumer of getFormatDetail() for actually rendering a file -- when a run's cycle has no
 * bank_file_format_id at all, or that format has zero fields configured anywhere (no default seed,
 * no company override), it falls back to that report's existing honest generic CSV unchanged.
 */
class BankFileFormatModel {
    private PDO $db;

    /**
     * Closed set of payroll-run fields a field row may pull from (source_type='employee_field') --
     * validated against this at save time so `source_field` can never silently drift out of sync
     * with what BankTransferFileReport::valueForField() actually knows how to resolve. Keys are
     * what's stored; labels are shown in the field editor's dropdown.
     */
    public const SOURCE_FIELDS = [
        'bank_account_no' => ['th' => 'เลขที่บัญชีธนาคาร', 'en' => 'Bank Account No.'],
        'bank_account_name' => ['th' => 'ชื่อบัญชีธนาคาร', 'en' => 'Bank Account Name'],
        'bank_code' => ['th' => 'รหัสธนาคาร', 'en' => 'Bank Code'],
        'bank_name' => ['th' => 'ชื่อธนาคาร', 'en' => 'Bank Name'],
        'employee_no' => ['th' => 'รหัสพนักงาน', 'en' => 'Employee No.'],
        'employee_name' => ['th' => 'ชื่อพนักงาน', 'en' => 'Employee Name'],
        'id_card_no' => ['th' => 'เลขบัตรประชาชน', 'en' => 'ID Card No.'],
        'net_amount' => ['th' => 'จำนวนเงินสุทธิ', 'en' => 'Net Amount'],
        'sequence_no' => ['th' => 'ลำดับที่', 'en' => 'Sequence No.'],
        'total_amount' => ['th' => 'ยอดรวมทั้งหมด', 'en' => 'Total Amount (header/trailer only)'],
        'total_count' => ['th' => 'จำนวนรายการทั้งหมด', 'en' => 'Total Record Count (header/trailer only)'],
        'company_name' => ['th' => 'ชื่อบริษัท', 'en' => 'Company Name'],
        'pay_period' => ['th' => 'งวดการจ่าย', 'en' => 'Pay Period (YYYYMMDD)'],
        // 2026-08-29, explicit request: "มีส่วนไหนที่ยังไม่มีให้ตั้งค่าเรื่องบัญชี หรือการใส่รหัสอะไรไหมครับ" --
        // the company's own settlement/debit account (source account the bank pulls the whole
        // payroll batch from) was NOT previously exposed as a header-row source field at all, even
        // though the underlying data already exists (`bank_accounts` where is_default=1 for this
        // company -- the same "Bank Accounts" record already used to resolve bank_code/bank_name
        // for every DETAIL row). Auto-resolved + decrypted (see BankTransferFileReport's own
        // companyAccountNo()), not something a company has to retype as a constant every time.
        'company_account_no' => ['th' => 'เลขที่บัญชีตัดเงินของบริษัท', 'en' => "Company's Own Debit Account No. (header/trailer only)"],
        // 2026-08-29, explicit follow-up request: "ในแต่ละรอบการจ่ายอาจใช้เลขแยกกันครับ แยกบัญชีในการจ่าย" --
        // replaces the earlier "constant, company must retype the same value on every field" design
        // for this specific code -- now resolved from bank_accounts.company_code on the SAME
        // account row company_account_no above resolves (the run's own cycle's bank_account_id if
        // pinned, else the company's default account), so a company with multiple accounts/codes
        // never has to keep 2+ places in sync by hand.
        'company_service_code' => ['th' => 'รหัสบริษัท/รหัสบริการที่ลงทะเบียนกับธนาคาร', 'en' => "Company/Service Code Registered with the Bank (header/trailer only)"],
        // Distinct from pay_period (period_start_date) -- this is the run's actual disbursement
        // date (payroll_runs.payment_date), which is what a bank's own "transaction date" field
        // means (Krungsri's spec example: "วันที่ทำรายการจ่าย").
        'payment_date' => ['th' => 'วันที่ทำรายการจ่าย', 'en' => 'Payment/Transaction Date'],
    ];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** Picker list for the Bank File Format settings UI. 2026-08-29, explicit request: "ตรงที่ธนาคาร
     *  ให้เหลือแค่ธนาคารที่บริษัทนั้นเพิ่มแล้วเท่านั้น ตอนนี้เห็นทุกธนาคาร" -- was every active named format
     *  regardless of whether the company had anything to do with that bank; narrowed to only the
     *  formats whose bank_id matches one of THIS company's own active bank_accounts (an INNER JOIN
     *  against a DISTINCT bank_id set, same "company's real payer bank(s)" source defaultFormatId()
     *  already uses) -- a company with no bank account on file yet, or whose banks have no named
     *  format row at all, now correctly sees an empty list rather than all 16. */
    public function listFormats(int $compId): array {
        $stmt = $this->db->prepare(
            "SELECT f.id, f.bank_id, f.code, f.name_th, f.name_en, f.file_extension,
                    b.bank_name_th, b.bank_name_en,
                    c.id AS config_id, c.is_verified, c.status AS config_status,
                    (SELECT COUNT(*) FROM bank_file_format_fields ff
                        WHERE ff.bank_file_format_id = f.id AND (ff.comp_id = :comp_id OR ff.comp_id IS NULL)) AS field_count,
                    (SELECT COUNT(*) FROM bank_file_format_fields ff2
                        WHERE ff2.bank_file_format_id = f.id AND ff2.comp_id = :comp_id2) AS has_own_override
             FROM master_bank_file_formats f
             LEFT JOIN master_banks b ON b.id = f.bank_id
             LEFT JOIN bank_file_format_configs c ON c.bank_file_format_id = f.id AND c.comp_id = :comp_id3 AND c.deleted_at IS NULL
             INNER JOIN (
                 SELECT DISTINCT bank_id FROM bank_accounts
                 WHERE comp_id = :comp_id4 AND deleted_at IS NULL AND status = 'active' AND bank_id IS NOT NULL
             ) ba ON ba.bank_id = f.bank_id
             WHERE f.is_active = 1
             ORDER BY f.sort_order ASC, f.id ASC"
        );
        $stmt->execute([':comp_id' => $compId, ':comp_id2' => $compId, ':comp_id3' => $compId, ':comp_id4' => $compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['is_verified'] = (bool)($row['is_verified'] ?? false);
            $row['has_own_override'] = (int)($row['has_own_override'] ?? 0) > 0;
            $row['field_count'] = (int)($row['field_count'] ?? 0);
        }
        unset($row);
        return $rows;
    }

    /** "Default ธนาคารให้ดึงมาจาก ธนาคารที่บริษัทมีการนำเข้าระบบ" -- resolves the format matching whichever
     *  bank the company's own bank_accounts use most (its real payer account(s) for salary transfer),
     *  not an arbitrary first-row default. Null when the company has no active bank account on file
     *  yet, or none of their banks have a named format row at all. */
    public function defaultFormatId(int $compId): ?int {
        $stmt = $this->db->prepare(
            "SELECT f.id
             FROM bank_accounts ba
             INNER JOIN master_bank_file_formats f ON f.bank_id = ba.bank_id AND f.is_active = 1
             WHERE ba.comp_id = :comp_id AND ba.deleted_at IS NULL AND ba.status = 'active'
             GROUP BY f.id
             ORDER BY MAX(ba.is_default) DESC, COUNT(*) DESC, f.sort_order ASC
             LIMIT 1"
        );
        $stmt->execute([':comp_id' => $compId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    private function formatExists(int $bankFileFormatId): bool {
        $stmt = $this->db->prepare("SELECT id FROM master_bank_file_formats WHERE id = :id AND is_active = 1");
        $stmt->execute([':id' => $bankFileFormatId]);
        return (bool)$stmt->fetch();
    }

    /** Company's own field rows if any exist for this format, else the shared system-default
     *  template -- grouped by row_type, ordered by sort_order. Used both by the settings UI and by
     *  BankTransferFileReport itself, so what an admin sees configured is exactly what renders. */
    public function fieldsForRender(int $compId, int $bankFileFormatId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM bank_file_format_fields
             WHERE bank_file_format_id = :fmt AND comp_id = :comp_id
             ORDER BY row_type ASC, sort_order ASC, id ASC"
        );
        $stmt->execute([':fmt' => $bankFileFormatId, ':comp_id' => $compId]);
        $own = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($own)) {
            return $own;
        }
        $stmtDefault = $this->db->prepare(
            "SELECT * FROM bank_file_format_fields
             WHERE bank_file_format_id = :fmt AND comp_id IS NULL
             ORDER BY row_type ASC, sort_order ASC, id ASC"
        );
        $stmtDefault->execute([':fmt' => $bankFileFormatId]);
        return $stmtDefault->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Config header row for this company+format -- a virtual, never-persisted default is returned
     *  when the company hasn't saved one yet, so the UI/renderer always has consistent settings to
     *  read without a NULL-check at every call site. */
    public function getConfig(int $compId, int $bankFileFormatId): array {
        $stmt = $this->db->prepare(
            "SELECT * FROM bank_file_format_configs
             WHERE comp_id = :comp_id AND bank_file_format_id = :fmt AND deleted_at IS NULL"
        );
        $stmt->execute([':comp_id' => $compId, ':fmt' => $bankFileFormatId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['is_verified'] = (bool)$row['is_verified'];
            $row['has_header_row'] = (bool)$row['has_header_row'];
            $row['has_trailer_row'] = (bool)$row['has_trailer_row'];
            return $row;
        }
        // 2026-08-29, real gap found while wiring up Krungsri's real layout: a virtual default of
        // delimiter_type='delimited'/has_header_row=false is a reasonable BLANK-SLATE default, but
        // it silently ignored whatever the format's own seeded/default fields actually look like --
        // a company picking BAY (which now has real header fields + every field carrying a `width`)
        // would see nothing of that until they ALSO separately remembered to flip 2 checkboxes in
        // settings, with no clue anything was configured at all. Inferred here from the fields
        // themselves instead (every field has a width -> this looks like a fixed-width layout;
        // header/trailer fields exist -> default to showing that row) -- a general, non-hardcoded-
        // per-bank heuristic (works the same for ANY format seeded this way, not "if BAY then..."),
        // still just a DEFAULT the company can turn back off, never persisted until they save.
        $fields = $this->fieldsForRender($compId, $bankFileFormatId);
        $hasFields = !empty($fields);
        $allFieldsHaveWidth = $hasFields && !array_filter($fields, fn($f) => $f['width'] === null || $f['width'] === '');
        $hasHeaderFields = (bool)array_filter($fields, fn($f) => ($f['row_type'] ?? '') === 'header');
        $hasTrailerFields = (bool)array_filter($fields, fn($f) => ($f['row_type'] ?? '') === 'trailer');
        // 2026-08-29, real bug found and fixed while testing the Krungsri layout end-to-end: a
        // byte-width fixed_width field is virtually ALWAYS meant for single-byte TIS-620 Thai text
        // in a real bank/government machine format (same "Thai fixed-width specs are historically
        // byte-width... TIS-620/CP874 is 1 byte/char" precedent FixedWidthHelperTrait's own
        // docblock already documents for the statutory exports) -- leaving text_encoding defaulted
        // to 'utf8' here meant a genuinely Thai name (3 bytes/char in UTF-8) would silently get
        // MID-CHARACTER-truncated inside a byte-width field sized for TIS-620, producing a
        // corrupted/invalid byte sequence instead of a readable (if shortened) name -- caught by
        // this exact failure in tests/bank_file_format_test.php before shipping. The company's own
        // config settings still let them switch back to utf8 explicitly if their bank genuinely
        // wants that (rare, but the field exists) -- this only changes the un-saved DEFAULT.
        return [
            'id' => null, 'comp_id' => $compId, 'bank_file_format_id' => $bankFileFormatId,
            'delimiter_type' => $allFieldsHaveWidth ? 'fixed_width' : 'delimited', 'delimiter_char' => ',', 'line_ending' => 'crlf',
            'has_header_row' => $hasHeaderFields, 'has_trailer_row' => $hasTrailerFields,
            'text_encoding' => $allFieldsHaveWidth ? 'tis620' : 'utf8',
            'is_verified' => false, 'status' => 'active',
        ];
    }

    /** Full picker payload for the settings UI: config header + fields grouped by row_type. */
    public function getFormatDetail(int $compId, int $bankFileFormatId): ?array {
        if (!$this->formatExists($bankFileFormatId)) {
            return null;
        }
        $fields = $this->fieldsForRender($compId, $bankFileFormatId);
        $grouped = ['header' => [], 'detail' => [], 'trailer' => []];
        foreach ($fields as $f) {
            $rowType = $f['row_type'] ?? 'detail';
            if (!isset($grouped[$rowType])) {
                continue;
            }
            $f['width'] = $f['width'] !== null ? (int)$f['width'] : null;
            $f['decimal_places'] = $f['decimal_places'] !== null ? (int)$f['decimal_places'] : null;
            $f['is_company_owned'] = $f['comp_id'] !== null;
            $grouped[$rowType][] = $f;
        }
        return [
            'config' => $this->getConfig($compId, $bankFileFormatId),
            'fields' => $grouped,
            'source_fields' => self::SOURCE_FIELDS,
        ];
    }

    private function logEdit(int $compId, int $bankFileFormatId, string $action, ?int $userId, $before, $after): void {
        $stmt = $this->db->prepare(
            "INSERT INTO bank_file_format_edit_logs (comp_id, bank_file_format_id, action, changed_by, before_json, after_json)
             VALUES (:comp_id, :fmt, :action, :changed_by, :before_json, :after_json)"
        );
        $stmt->execute([
            ':comp_id' => $compId, ':fmt' => $bankFileFormatId, ':action' => $action, ':changed_by' => $userId,
            ':before_json' => $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            ':after_json' => $after !== null ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    public function editLogs(int $compId, int $bankFileFormatId): array {
        $stmt = $this->db->prepare(
            "SELECT l.*, e.name_th AS changed_by_name_th, e.name_en AS changed_by_name_en
             FROM bank_file_format_edit_logs l
             LEFT JOIN employees e ON e.id = l.changed_by
             WHERE l.comp_id = :comp_id AND l.bank_file_format_id = :fmt
             ORDER BY l.changed_at DESC, l.id DESC
             LIMIT 200"
        );
        $stmt->execute([':comp_id' => $compId, ':fmt' => $bankFileFormatId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Upsert the per-company header config. `id` present = update that company's own row; absent =
     *  insert. Always logs (config_saved). */
    public function saveConfig(int $compId, int $bankFileFormatId, array $data, ?int $userId): array {
        if (!$this->formatExists($bankFileFormatId)) {
            return ['status' => false, 'message' => 'Invalid bank file format.'];
        }
        $delimiterType = in_array($data['delimiter_type'] ?? '', ['fixed_width', 'delimited'], true) ? $data['delimiter_type'] : 'delimited';
        $lineEnding = in_array($data['line_ending'] ?? '', ['crlf', 'lf'], true) ? $data['line_ending'] : 'crlf';
        $textEncoding = in_array($data['text_encoding'] ?? '', ['utf8', 'tis620'], true) ? $data['text_encoding'] : 'utf8';
        $delimiterChar = $delimiterType === 'delimited' ? substr((string)($data['delimiter_char'] ?? ','), 0, 5) : null;
        $hasHeaderRow = !empty($data['has_header_row']) ? 1 : 0;
        $hasTrailerRow = !empty($data['has_trailer_row']) ? 1 : 0;
        $isVerified = !empty($data['is_verified']) ? 1 : 0;

        $before = $this->getConfig($compId, $bankFileFormatId);
        $stmt = $this->db->prepare(
            "SELECT id FROM bank_file_format_configs WHERE comp_id = :comp_id AND bank_file_format_id = :fmt AND deleted_at IS NULL"
        );
        $stmt->execute([':comp_id' => $compId, ':fmt' => $bankFileFormatId]);
        $existingId = $stmt->fetchColumn();

        $params = [
            ':comp_id' => $compId, ':fmt' => $bankFileFormatId, ':delimiter_type' => $delimiterType,
            ':delimiter_char' => $delimiterChar, ':line_ending' => $lineEnding, ':has_header_row' => $hasHeaderRow,
            ':has_trailer_row' => $hasTrailerRow, ':text_encoding' => $textEncoding, ':is_verified' => $isVerified,
            ':user_id' => $userId,
        ];
        if ($existingId) {
            $params[':id'] = $existingId;
            $this->db->prepare(
                "UPDATE bank_file_format_configs SET delimiter_type = :delimiter_type, delimiter_char = :delimiter_char,
                    line_ending = :line_ending, has_header_row = :has_header_row, has_trailer_row = :has_trailer_row,
                    text_encoding = :text_encoding, is_verified = :is_verified, status = 'active',
                    updated_by = :user_id, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            )->execute($params);
        } else {
            $this->db->prepare(
                "INSERT INTO bank_file_format_configs
                    (comp_id, bank_file_format_id, delimiter_type, delimiter_char, line_ending, has_header_row, has_trailer_row, text_encoding, is_verified, status, created_by)
                 VALUES
                    (:comp_id, :fmt, :delimiter_type, :delimiter_char, :line_ending, :has_header_row, :has_trailer_row, :text_encoding, :is_verified, 'active', :user_id)"
            )->execute($params);
        }
        $after = $this->getConfig($compId, $bankFileFormatId);
        $this->logEdit($compId, $bankFileFormatId, 'config_saved', $userId, $before, $after);
        return ['status' => true, 'message' => 'Format settings saved.', 'config' => $after];
    }

    /** Clones the shared default field set into comp_id-scoped rows the FIRST time this company
     *  touches a given format's fields -- so every subsequent edit operates on the company's own
     *  copy and the system default template (comp_id IS NULL, read by every OTHER company that
     *  hasn't customized yet) is never mutated. No-op if the company already has its own rows, or
     *  if there's no default to fork from (an unseeded format starts from a genuinely empty list). */
    private function forkDefaultIfNeeded(int $compId, int $bankFileFormatId, ?int $userId): void {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM bank_file_format_fields WHERE bank_file_format_id = :fmt AND comp_id = :comp_id");
        $stmt->execute([':fmt' => $bankFileFormatId, ':comp_id' => $compId]);
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }
        $stmtDefault = $this->db->prepare("SELECT * FROM bank_file_format_fields WHERE bank_file_format_id = :fmt AND comp_id IS NULL ORDER BY row_type ASC, sort_order ASC, id ASC");
        $stmtDefault->execute([':fmt' => $bankFileFormatId]);
        $defaults = $stmtDefault->fetchAll(PDO::FETCH_ASSOC);
        if (empty($defaults)) {
            return;
        }
        $insert = $this->db->prepare(
            "INSERT INTO bank_file_format_fields
                (bank_file_format_id, comp_id, row_type, sort_order, field_label_th, field_label_en, source_type, source_field, constant_value, data_type, width, pad_char, pad_direction, date_format, decimal_places, created_by)
             VALUES
                (:fmt, :comp_id, :row_type, :sort_order, :field_label_th, :field_label_en, :source_type, :source_field, :constant_value, :data_type, :width, :pad_char, :pad_direction, :date_format, :decimal_places, :user_id)"
        );
        foreach ($defaults as $d) {
            $insert->execute([
                ':fmt' => $bankFileFormatId, ':comp_id' => $compId, ':row_type' => $d['row_type'], ':sort_order' => $d['sort_order'],
                ':field_label_th' => $d['field_label_th'], ':field_label_en' => $d['field_label_en'], ':source_type' => $d['source_type'],
                ':source_field' => $d['source_field'], ':constant_value' => $d['constant_value'], ':data_type' => $d['data_type'],
                ':width' => $d['width'], ':pad_char' => $d['pad_char'], ':pad_direction' => $d['pad_direction'],
                ':date_format' => $d['date_format'], ':decimal_places' => $d['decimal_places'], ':user_id' => $userId,
            ]);
        }
    }

    private function validateFieldPayload(array $data, string $delimiterType): array {
        $rowType = in_array($data['row_type'] ?? '', ['header', 'detail', 'trailer'], true) ? $data['row_type'] : 'detail';
        $sourceType = in_array($data['source_type'] ?? '', ['employee_field', 'constant', 'blank'], true) ? $data['source_type'] : 'employee_field';
        $dataType = in_array($data['data_type'] ?? '', ['text', 'number', 'date'], true) ? $data['data_type'] : 'text';
        $padDirection = in_array($data['pad_direction'] ?? '', ['left', 'right'], true) ? $data['pad_direction'] : 'right';
        $labelTh = trim((string)($data['field_label_th'] ?? ''));
        $labelEn = trim((string)($data['field_label_en'] ?? ''));
        if ($labelTh === '' || $labelEn === '') {
            return ['error' => 'Field label (Thai and English) is required.'];
        }
        $sourceField = null;
        $constantValue = null;
        if ($sourceType === 'employee_field') {
            $sourceField = (string)($data['source_field'] ?? '');
            if (!isset(self::SOURCE_FIELDS[$sourceField])) {
                return ['error' => 'Invalid source_field.'];
            }
        } elseif ($sourceType === 'constant') {
            $constantValue = (string)($data['constant_value'] ?? '');
            if ($constantValue === '') {
                return ['error' => 'constant_value is required when source_type is constant.'];
            }
        }
        $width = isset($data['width']) && $data['width'] !== '' ? (int)$data['width'] : null;
        if ($delimiterType === 'fixed_width' && (!$width || $width <= 0)) {
            return ['error' => 'width must be a positive integer for a fixed-width format.'];
        }
        if ($width !== null && $width <= 0) {
            $width = null;
        }
        return [
            'row_type' => $rowType, 'sort_order' => (int)($data['sort_order'] ?? 0),
            'field_label_th' => $labelTh, 'field_label_en' => $labelEn,
            'source_type' => $sourceType, 'source_field' => $sourceField, 'constant_value' => $constantValue,
            'data_type' => $dataType, 'width' => $width,
            'pad_char' => substr((string)($data['pad_char'] ?? ' '), 0, 1) ?: ' ',
            'pad_direction' => $padDirection,
            'date_format' => $dataType === 'date' ? (string)($data['date_format'] ?? 'Ymd') : null,
            'decimal_places' => $dataType === 'number' ? max(0, (int)($data['decimal_places'] ?? 2)) : null,
        ];
    }

    /** Insert (no `id`) or update (`id` present, must already be one of THIS company's own field
     *  rows) one field. Forks the default set first if this is this company's first-ever edit for
     *  the format (see forkDefaultIfNeeded()). Always logs (field_saved). */
    public function saveField(int $compId, int $bankFileFormatId, array $data, ?int $userId): array {
        if (!$this->formatExists($bankFileFormatId)) {
            return ['status' => false, 'message' => 'Invalid bank file format.'];
        }
        $config = $this->getConfig($compId, $bankFileFormatId);
        $clean = $this->validateFieldPayload($data, (string)$config['delimiter_type']);
        if (isset($clean['error'])) {
            return ['status' => false, 'message' => $clean['error']];
        }

        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;
        // 2026-08-29, real bug found and fixed (explicit report: editing one of the SEEDED DEFAULT
        // fields for the very first time -- i.e. before this company has ever customized this
        // format -- always failed with "Field not found." even though the field was clearly right
        // there in the UI): the editor's Edit button carries whatever id the field currently has
        // in bffCurrentDetail, which (before this company's first-ever edit) IS the shared
        // comp_id-IS-NULL default template row's own id. forkDefaultIfNeeded() below clones that
        // whole template into BRAND NEW company-owned rows with FRESH auto-increment ids -- so
        // looking up the ORIGINAL id scoped to `comp_id = :comp_id` immediately afterward can
        // never match anything (that id still only exists on the comp_id-IS-NULL row). row_type +
        // sort_order survive the fork verbatim (forkDefaultIfNeeded() copies them unchanged), so
        // that pair is used here as the stable identity to re-resolve $id onto the newly-forked
        // company-owned copy -- but only when $id demonstrably pointed at a same-format DEFAULT
        // row before forking; an id that's already this company's own (the normal case on every
        // edit AFTER the first) is left untouched.
        if ($id !== null) {
            $stmtDefaultRef = $this->db->prepare("SELECT row_type, sort_order FROM bank_file_format_fields WHERE id = :id AND bank_file_format_id = :fmt AND comp_id IS NULL");
            $stmtDefaultRef->execute([':id' => $id, ':fmt' => $bankFileFormatId]);
            $defaultRef = $stmtDefaultRef->fetch(PDO::FETCH_ASSOC);
            if ($defaultRef) {
                $this->forkDefaultIfNeeded($compId, $bankFileFormatId, $userId);
                $stmtRemap = $this->db->prepare("SELECT id FROM bank_file_format_fields WHERE bank_file_format_id = :fmt AND comp_id = :comp_id AND row_type = :row_type AND sort_order = :sort_order LIMIT 1");
                $stmtRemap->execute([':fmt' => $bankFileFormatId, ':comp_id' => $compId, ':row_type' => $defaultRef['row_type'], ':sort_order' => $defaultRef['sort_order']]);
                $remappedId = $stmtRemap->fetchColumn();
                if ($remappedId !== false) {
                    $id = (int)$remappedId;
                }
            } else {
                $this->forkDefaultIfNeeded($compId, $bankFileFormatId, $userId);
            }
        } else {
            $this->forkDefaultIfNeeded($compId, $bankFileFormatId, $userId);
        }

        $before = null;
        if ($id !== null) {
            $stmtCheck = $this->db->prepare("SELECT * FROM bank_file_format_fields WHERE id = :id AND bank_file_format_id = :fmt AND comp_id = :comp_id");
            $stmtCheck->execute([':id' => $id, ':fmt' => $bankFileFormatId, ':comp_id' => $compId]);
            $before = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if (!$before) {
                return ['status' => false, 'message' => 'Field not found.'];
            }
            $params = $clean;
            $params[':id'] = $id;
            $params = [
                ':row_type' => $clean['row_type'], ':sort_order' => $clean['sort_order'], ':field_label_th' => $clean['field_label_th'],
                ':field_label_en' => $clean['field_label_en'], ':source_type' => $clean['source_type'], ':source_field' => $clean['source_field'],
                ':constant_value' => $clean['constant_value'], ':data_type' => $clean['data_type'], ':width' => $clean['width'],
                ':pad_char' => $clean['pad_char'], ':pad_direction' => $clean['pad_direction'], ':date_format' => $clean['date_format'],
                ':decimal_places' => $clean['decimal_places'], ':user_id' => $userId, ':id' => $id,
            ];
            $this->db->prepare(
                "UPDATE bank_file_format_fields SET row_type = :row_type, sort_order = :sort_order, field_label_th = :field_label_th,
                    field_label_en = :field_label_en, source_type = :source_type, source_field = :source_field, constant_value = :constant_value,
                    data_type = :data_type, width = :width, pad_char = :pad_char, pad_direction = :pad_direction, date_format = :date_format,
                    decimal_places = :decimal_places, updated_by = :user_id, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            )->execute($params);
        } else {
            $params = [
                ':fmt' => $bankFileFormatId, ':comp_id' => $compId, ':row_type' => $clean['row_type'], ':sort_order' => $clean['sort_order'],
                ':field_label_th' => $clean['field_label_th'], ':field_label_en' => $clean['field_label_en'], ':source_type' => $clean['source_type'],
                ':source_field' => $clean['source_field'], ':constant_value' => $clean['constant_value'], ':data_type' => $clean['data_type'],
                ':width' => $clean['width'], ':pad_char' => $clean['pad_char'], ':pad_direction' => $clean['pad_direction'],
                ':date_format' => $clean['date_format'], ':decimal_places' => $clean['decimal_places'], ':user_id' => $userId,
            ];
            $this->db->prepare(
                "INSERT INTO bank_file_format_fields
                    (bank_file_format_id, comp_id, row_type, sort_order, field_label_th, field_label_en, source_type, source_field, constant_value, data_type, width, pad_char, pad_direction, date_format, decimal_places, created_by)
                 VALUES
                    (:fmt, :comp_id, :row_type, :sort_order, :field_label_th, :field_label_en, :source_type, :source_field, :constant_value, :data_type, :width, :pad_char, :pad_direction, :date_format, :decimal_places, :user_id)"
            )->execute($params);
            $id = (int)$this->db->lastInsertId();
        }
        $stmtAfter = $this->db->prepare("SELECT * FROM bank_file_format_fields WHERE id = :id");
        $stmtAfter->execute([':id' => $id]);
        $after = $stmtAfter->fetch(PDO::FETCH_ASSOC);
        $this->logEdit($compId, $bankFileFormatId, 'field_saved', $userId, $before, $after);
        return ['status' => true, 'message' => 'Field saved.', 'id' => $id];
    }

    /** Deletes ONE of this company's own field rows -- never the shared default (a field row with
     *  comp_id IS NULL never matches the comp_id check below, so it's simply "not found" from any
     *  company's point of view, exactly like it should be). Always logs (field_deleted). */
    public function deleteField(int $compId, int $bankFileFormatId, int $fieldId, ?int $userId): array {
        $stmt = $this->db->prepare("SELECT * FROM bank_file_format_fields WHERE id = :id AND bank_file_format_id = :fmt AND comp_id = :comp_id");
        $stmt->execute([':id' => $fieldId, ':fmt' => $bankFileFormatId, ':comp_id' => $compId]);
        $before = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$before) {
            return ['status' => false, 'message' => 'Field not found.'];
        }
        $this->db->prepare("DELETE FROM bank_file_format_fields WHERE id = :id")->execute([':id' => $fieldId]);
        $this->logEdit($compId, $bankFileFormatId, 'field_deleted', $userId, $before, null);
        return ['status' => true, 'message' => 'Field deleted.'];
    }

    /** Discards this company's own customization (fields + config) and reverts to the shared
     *  default template again. Own-transaction guard per this project's convention (multi-table
     *  write). Always logs (reset_to_default) after the revert completes. */
    public function resetToDefault(int $compId, int $bankFileFormatId, ?int $userId): array {
        if (!$this->formatExists($bankFileFormatId)) {
            return ['status' => false, 'message' => 'Invalid bank file format.'];
        }
        $before = $this->getFormatDetail($compId, $bankFileFormatId);
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare("DELETE FROM bank_file_format_fields WHERE bank_file_format_id = :fmt AND comp_id = :comp_id")
                ->execute([':fmt' => $bankFileFormatId, ':comp_id' => $compId]);
            $this->db->prepare("UPDATE bank_file_format_configs SET deleted_at = CURRENT_TIMESTAMP, deleted_by = :user_id WHERE comp_id = :comp_id AND bank_file_format_id = :fmt AND deleted_at IS NULL")
                ->execute([':user_id' => $userId, ':comp_id' => $compId, ':fmt' => $bankFileFormatId]);
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        $after = $this->getFormatDetail($compId, $bankFileFormatId);
        $this->logEdit($compId, $bankFileFormatId, 'reset_to_default', $userId, $before, $after);
        return ['status' => true, 'message' => 'Reverted to the default template.'];
    }
}
