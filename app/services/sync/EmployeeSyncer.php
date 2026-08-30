<?php
declare(strict_types=1);
require_once __DIR__ . '/MasterDataSyncerInterface.php';
require_once __DIR__ . '/../EncryptionService.php';

/**
 * Does NOT extend AbstractMasterDataSyncer -- `employees` has no generic `status` enum (uses
 * `employee_status` instead, a different value set) and needs FK resolution against
 * department/position/shift, which must already be synced/imported (dependency order enforced by
 * MasterDataSyncRegistry / the import controller, not by this class).
 *
 * Field ownership, deliberately split in two (applies to BOTH sync() and importRow()):
 *  - "HR-owned" fields (name, DOB, gender, email, mobile, employment_date/status, department/
 *    position/shift links) are overwritten every time, insert or update -- the source (Origami or
 *    the uploaded file) is treated as the source of truth for these.
 *  - Payroll-owned fields (payment_type, salary_type, base_salary_amount, salary_effective_date,
 *    tax_calculation_method, bank_id/bank_account_no/bank_account_name/bank_branch) are only ever
 *    SET on first insert, never touched again on a re-sync/re-import -- protects whatever the
 *    payroll admin has since corrected locally (confirmed via AskUserQuestion, 2026-08-28: "ตั้งค่า
 *    ครั้งแรกตอน Insert เท่านั้น"). Before 2026-08-28 these were only ever left at their bare schema
 *    DEFAULT (e.g. base_salary_amount stuck at 0.00, no bank info at all) regardless of what a sync
 *    payload carried -- a newly-synced employee was unusable in an actual payroll run until a
 *    payroll admin completed their Salary tab by hand. Per explicit follow-up request ("ข้อมูลที่
 *    Sync จะต้องมีครบตามที่ Sync มาในการทำรอบเงินเดือน" -- synced data must be complete enough to
 *    run payroll with), upsertItem() now takes these straight from the item payload WHEN PRESENT,
 *    falling back to the same schema defaults only when a field is genuinely absent (e.g. an
 *    import row with no salary column, or a sync source that doesn't provide bank details) --
 *    still insert-only, still never touching an existing employee's values. base_salary_amount and
 *    bank_account_no go through the exact same AES-256-GCM EncryptionService mechanism
 *    EmployeeModel::save() itself uses (never stored plaintext), bank_code is resolved against the
 *    global master_banks table (see resolveBankId()).
 *  - address and emergency-contact columns are NOT NULL in this schema but not meaningfully
 *    sourced from either a sync payload or the import template in this design -- placeholder-
 *    filled on insert, never touched again.
 *
 * sync() resolves department/position/shift by origami_ref_id (Origami's numeric id, matching
 * what a live API would return). importRow() resolves the SAME links by CODE instead
 * (department_code/position_code/shift_code) -- a human filling a spreadsheet knows the codes
 * shown in this system's own department/shift/etc. list pages, not Origami's internal ref ids.
 * Both use resolveRef()/resolveRefByCode(), which REFUSE to guess: a ref/code that doesn't exist
 * yet is a per-row error ("sync/create it first"), not something either method will create.
 *
 * applyOne() (the interactive picker's own entry point, see its own docblock) is the ONE
 * exception to that "never guess" rule -- 2026-08-28, explicit follow-up request: "Sync พนักงาน
 * พร้อมสร้าง Master ที่ขาดอัตโนมัติ" (sync an employee AND auto-create whatever master data it
 * references that doesn't exist yet). resolveOrCreateDepartment()/resolveOrCreatePosition()/
 * resolveOrCreateShift()/resolveOrCreateBranch() are used ONLY there, not by sync()/importRow() --
 * this interactive path has no dependency-order concept to protect (each Apply is one hand-picked
 * employee, not a whole-company batch where getting the order wrong has wider blast radius), so
 * auto-creating is safe here in a way it deliberately isn't for the bulk paths. Team is excluded
 * from this -- OrigamiEmployeeCandidateClient's own docblock explains why Team has no Origami-side
 * equivalent to create against at all.
 *
 * 2026-08-30, candidates.php gained a large new field batch (branch_ref_id/branch_name,
 * payroll_code, emp_tel, title, nickname, nationality, religion, marital_status,
 * military_service, idcard/idcard_issued/idcard_expire, pass_pro/pass_pro_date, deduct_sso,
 * photo_url, signature_drawing, spouse, children) -- confirmed additive-only by the Origami side
 * (every existing field unchanged). All HR-owned per this class's own field-ownership rule above,
 * so every one of them is written on BOTH insert and update (upsertItem() rewritten from a fixed
 * UPDATE statement into a dynamic $set builder specifically to support this -- see that method's
 * own docblock for why "only write when this pull actually resolved/recognized a value, else leave
 * the column alone" matters for several of these, same real-data lesson
 * `PayrollSyncModel::applyOneEmployeeMasterFields()` already learned for its own near-identical
 * field set on the OTHER Origami integration (PAYROLL_SYNC_API.md) -- title/nickname/nationality/
 * religion/marital_status are frequently blank on real Origami records, and an unconditional
 * overwrite-with-blank on every re-sync would erase data a payroll admin later filled in by hand).
 * `normalizeTitle()`/`normalizeMaritalStatus()`/`resolveNationalityCode()`/`decodeSignatureDrawing()`
 * below are deliberate near-verbatim ports of that class's own same-named private methods (own
 * docblocks kept on each explaining the "why", not re-explained here) -- duplicated on purpose, same
 * "two engines independently implement the same resolution logic" precedent already established
 * elsewhere in this app (e.g. `AttendanceDeductionRuleModel::resolveVariantRow()` vs.
 * `SyncPayResolver::attendanceDeductionRuleFor()`), rather than a forced shared dependency between
 * two otherwise-unrelated sync engines. `military_service` stays intentionally UNmapped, same
 * reasoning as that class (no reliable value crosswalk, `employees.military_status` is a genuinely
 * different, structured enum). Confirmed via AskUserQuestion: `photo_url` (now a real absolute URL,
 * not an Origami-local path) IS downloaded and stored as a real file this time -- see
 * `downloadPhoto()`'s own docblock; `payroll_code` (distinct from `employee_no`/`emp_code`, which
 * stay the actual matching key) is stored reference-only in the new `employees.origami_payroll_code`
 * column, never used for matching.
 */
class EmployeeSyncer implements MasterDataSyncerInterface {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function entityType(): string {
        return 'employee';
    }

    public function templateColumns(): array {
        return [
            'origami_ref_id' => 'Origami Ref ID (optional)', 'employee_no' => 'Employee No.',
            'name_th' => 'Name (Thai)', 'surname_th' => 'Surname (Thai)', 'name_en' => 'Name (English)', 'surname_en' => 'Surname (English)',
            'date_of_birth' => 'Date of Birth (YYYY-MM-DD)', 'gender' => 'Gender (male/female/other)',
            'department_code' => 'Department Code (optional)', 'position_code' => 'Position Code (optional)', 'shift_code' => 'Shift Code (optional)',
            'employment_date' => 'Employment Date (YYYY-MM-DD)', 'employment_status' => 'Employment Status (probation/permanent/contract/resigned/terminated)',
            'personal_email' => 'Personal Email', 'mobile_no' => 'Mobile No.',
        ];
    }

    private function findByRefId(int $compId, int $refId): ?int {
        $stmt = $this->db->prepare("SELECT id FROM employees WHERE origami_ref_id = :ref AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':ref' => $refId, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    private function findByEmployeeNo(int $compId, string $employeeNo): ?int {
        $stmt = $this->db->prepare("SELECT id FROM employees WHERE employee_no = :no AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':no' => $employeeNo, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** @throws InvalidArgumentException if a non-null ref_id was given but hasn't been synced yet (dependency ordering violation). */
    private function resolveRef(string $table, int $compId, $refId): ?int {
        if ($refId === null || $refId === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM `{$table}` WHERE origami_ref_id = :ref AND comp_id = :comp");
        $stmt->execute([':ref' => (int)$refId, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException("Referenced {$table} (ref_id={$refId}) has not been synced yet -- sync it first.");
        }
        return (int)$id;
    }

    private function resolveRefByCode(string $table, string $codeColumn, int $compId, $code): ?int {
        if ($code === null || trim((string)$code) === '') {
            return null;
        }
        $code = trim((string)$code);
        $stmt = $this->db->prepare("SELECT id FROM `{$table}` WHERE `{$codeColumn}` = :code AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':code' => $code, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException("Referenced {$table} (code={$code}) was not found -- create or import it first.");
        }
        return (int)$id;
    }

    /** master_banks is global (not per-company, see that table's own schema) -- looked up by
     *  bank_code (e.g. "004"=KBANK, "014"=SCB), same code space Employee Detail's own bank dropdown
     *  already uses. Returns null (not an exception) when absent/blank/unknown -- a candidate with
     *  no recognizable bank code just lands with bank_id=null, same as an employee created manually
     *  with no bank selected yet; this must never block the rest of the insert. */
    private function resolveBankId(?string $bankCode): ?int {
        $bankCode = trim((string)$bankCode);
        if ($bankCode === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM master_banks WHERE bank_code = :code AND is_active = 1");
        $stmt->execute([':code' => $bankCode]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /**
     * 2026-08-30, ported near-verbatim from `PayrollSyncModel::normalizeTitle()`/
     * `normalizeMaritalStatus()`/`resolveNationalityCode()` (see this class's own top docblock for
     * why duplicated rather than shared) -- title/marital_status are raw legacy values on Origami's
     * wire, "sometimes human-readable text, sometimes an internal numeric code depending on when/how
     * the row was entered", no glossary given for the numeric codes -- only recognized TEXT variants
     * map to this app's own enum values; anything else is left unmapped (null) rather than guessed.
     */
    private function normalizeTitle(?string $raw): ?string {
        $map = ['mr' => 'mr', 'mr.' => 'mr', 'mister' => 'mr', 'mrs' => 'mrs', 'mrs.' => 'mrs', 'ms' => 'ms', 'ms.' => 'ms', 'miss' => 'ms'];
        $key = strtolower(trim((string)$raw));
        return $map[$key] ?? null;
    }

    private function normalizeMaritalStatus(?string $raw): ?string {
        $map = ['single' => 'single', 'married' => 'married', 'divorced' => 'divorced', 'widowed' => 'widowed', 'widow' => 'widowed'];
        $key = strtolower(trim((string)$raw));
        return $map[$key] ?? null;
    }

    /**
     * Resolves an incoming display name (e.g. "Thai") to this app's own master_nationalities
     * nationality_code (e.g. "TH") -- `employees.nationality` LEFT JOINs master_nationalities ON
     * e.nationality = mn.nationality_code (a short code, NOT the raw display name), same bug class
     * `PayrollSyncModel`'s own 2026-08-19 revision fixed on the OTHER Origami integration. Matches
     * nationality_name_en first (what Origami's payload actually sends), nationality_name_th as a
     * fallback, both case-insensitive. Returns null on no match -- resolve-only, never creates a new
     * row (a fixed global country list).
     */
    private function resolveNationalityCode(?string $raw): ?string {
        $name = trim((string)$raw);
        if ($name === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT nationality_code FROM master_nationalities
            WHERE LOWER(nationality_name_en) = LOWER(:name) OR LOWER(nationality_name_th) = LOWER(:name) LIMIT 1");
        $stmt->execute([':name' => $name]);
        $code = $stmt->fetchColumn();
        return $code !== false ? (string)$code : null;
    }

    /**
     * 2026-08-30, ported verbatim from `PayrollSyncModel::decodeSignatureDrawing()` -- strips an
     * optional `data:image/...;base64,` prefix, base64-decodes, then MIME-sniffs against the SAME
     * jpg/png/svg allowlist `EmployeeController::uploadSignature()` already enforces for a manual
     * upload. Returns null (never throws) on anything malformed -- one bad signature must never
     * block the rest of this employee's sync.
     */
    private function decodeSignatureDrawing(?string $raw): ?array {
        if ($raw === null) {
            return null;
        }
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (str_starts_with($raw, 'data:')) {
            $comma = strpos($raw, ',');
            if ($comma === false) {
                return null;
            }
            $raw = substr($raw, $comma + 1);
        }
        $bytes = base64_decode($raw, true);
        if ($bytes === false || $bytes === '') {
            return null;
        }
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/svg+xml' => 'svg'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->buffer($bytes);
        if (!isset($allowedMimes[$detectedMime])) {
            return null;
        }
        return ['bytes' => $bytes, 'ext' => $allowedMimes[$detectedMime]];
    }

    /**
     * 2026-08-30, NEW (candidates.php's `photo_url` is now a real absolute URL, unlike
     * PAYROLL_SYNC_API.md's own `emp_pic`, which `PayrollSyncModel` still deliberately declines to
     * download -- see that class's own docblock; confirmed via AskUserQuestion this integration
     * SHOULD download it). Same validation posture as a manual `EmployeeController::uploadPhoto()`
     * upload (jpg/png/svg allowlist via finfo MIME-sniffing on the actual downloaded bytes, never
     * trusting the URL's own extension; 2MB cap) -- just fetched over HTTP instead of $_FILES.
     * Returns null (never throws) on ANY failure -- a broken/slow/oversized photo URL must never
     * block the rest of this employee's sync, same "best-effort, don't fail the whole apply over one
     * optional field" posture as resolveBankId()/decodeSignatureDrawing() above.
     */
    private function downloadPhoto(?string $url): ?array {
        $url = trim((string)$url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_RANGE => '0-2097151', // 2MB cap, same limit as a manual upload -- not every server honors Range, so the byte-count check below is the real enforcement.
        ]);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $httpCode < 200 || $httpCode >= 300 || strlen($body) === 0 || strlen($body) > 2 * 1024 * 1024) {
            return null;
        }
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/svg+xml' => 'svg'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->buffer($body);
        if (!isset($allowedMimes[$detectedMime])) {
            return null;
        }
        return ['bytes' => $body, 'ext' => $allowedMimes[$detectedMime]];
    }

    /** Resolves a department by origami_ref_id, auto-CREATING a new structure_departments row when
     *  none exists yet -- used ONLY by applyOne() (the interactive picker's insert-or-update path,
     *  2026-08-28 explicit request: "Sync พนักงานพร้อมสร้าง Master ที่ขาดอัตโนมัติ"). Deliberately
     *  NOT used by resolveRef() (sync()/importRow()'s own resolution, still unchanged below) --
     *  that method's "fail per-row, don't guess" behavior is a deliberate design choice tied to the
     *  dependency-order enforcement MasterDataSyncOrchestrator documents (department/position/shift
     *  synced before employee via THAT engine), which this interactive path has no equivalent
     *  ordering step for. A derived code (ORG-{ref_id}) is used whenever the candidate doesn't
     *  supply its own department_code, so this never fails purely for lacking one. */
    private function resolveOrCreateDepartment(int $compId, $refId, array $item, int $batchId, ?int $triggeredBy): ?int {
        if ($refId === null || $refId === '') {
            return null;
        }
        $refId = (int)$refId;
        $stmt = $this->db->prepare("SELECT id FROM structure_departments WHERE origami_ref_id = :ref AND comp_id = :comp");
        $stmt->execute([':ref' => $refId, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
        $code = trim((string)($item['department_code'] ?? '')) ?: "ORG-{$refId}";
        $nameTh = trim((string)($item['department_name_th'] ?? '')) ?: $code;
        $nameEn = trim((string)($item['department_name_en'] ?? '')) ?: $code;
        $stmt = $this->db->prepare("INSERT INTO structure_departments
                (comp_id, department_code, department_name_th, department_name_en, origami_ref_id, data_source, sync_batch_id, created_by)
            VALUES (:comp_id, :code, :name_th, :name_en, :ref_id, 'sync', :batch_id, :user)");
        $stmt->execute([':comp_id' => $compId, ':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn, ':ref_id' => $refId, ':batch_id' => $batchId, ':user' => $triggeredBy]);
        return (int)$this->db->lastInsertId();
    }

    /** Same as resolveOrCreateDepartment() above, for positions. */
    private function resolveOrCreatePosition(int $compId, $refId, array $item, int $batchId, ?int $triggeredBy): ?int {
        if ($refId === null || $refId === '') {
            return null;
        }
        $refId = (int)$refId;
        $stmt = $this->db->prepare("SELECT id FROM structure_positions WHERE origami_ref_id = :ref AND comp_id = :comp");
        $stmt->execute([':ref' => $refId, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
        $code = trim((string)($item['position_code'] ?? '')) ?: "ORG-{$refId}";
        $nameTh = trim((string)($item['position_name_th'] ?? '')) ?: $code;
        $nameEn = trim((string)($item['position_name_en'] ?? '')) ?: $code;
        $stmt = $this->db->prepare("INSERT INTO structure_positions
                (comp_id, position_code, position_name_th, position_name_en, origami_ref_id, data_source, sync_batch_id, created_by)
            VALUES (:comp_id, :code, :name_th, :name_en, :ref_id, 'sync', :batch_id, :user)");
        $stmt->execute([':comp_id' => $compId, ':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn, ':ref_id' => $refId, ':batch_id' => $batchId, ':user' => $triggeredBy]);
        return (int)$this->db->lastInsertId();
    }

    /** Same as resolveOrCreateDepartment() above, for shifts -- start_time/end_time are NOT NULL
     *  columns with no schema default, so a candidate lacking them falls back to a generic 08:00-
     *  17:00/60-minute-break shift rather than failing the whole employee for a missing shift
     *  detail (shift is the least critical of the 3 links to an actual payroll calculation). */
    private function resolveOrCreateShift(int $compId, $refId, array $item, int $batchId, ?int $triggeredBy): ?int {
        if ($refId === null || $refId === '') {
            return null;
        }
        $refId = (int)$refId;
        $stmt = $this->db->prepare("SELECT id FROM shifts WHERE origami_ref_id = :ref AND comp_id = :comp");
        $stmt->execute([':ref' => $refId, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
        $code = trim((string)($item['shift_code'] ?? '')) ?: "ORG-{$refId}";
        $nameTh = trim((string)($item['shift_name_th'] ?? '')) ?: $code;
        $nameEn = trim((string)($item['shift_name_en'] ?? '')) ?: $code;
        $startTime = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string)($item['shift_start_time'] ?? '')) ? $item['shift_start_time'] : '08:00:00';
        $endTime = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string)($item['shift_end_time'] ?? '')) ? $item['shift_end_time'] : '17:00:00';
        $breakMinutes = is_numeric($item['shift_break_minutes'] ?? null) ? (int)$item['shift_break_minutes'] : 60;
        $stmt = $this->db->prepare("INSERT INTO shifts
                (comp_id, shift_code, shift_name_th, shift_name_en, start_time, end_time, break_minutes, origami_ref_id, data_source, sync_batch_id, created_by)
            VALUES (:comp_id, :code, :name_th, :name_en, :start_time, :end_time, :break_minutes, :ref_id, 'sync', :batch_id, :user)");
        $stmt->execute([
            ':comp_id' => $compId, ':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn,
            ':start_time' => $startTime, ':end_time' => $endTime, ':break_minutes' => $breakMinutes,
            ':ref_id' => $refId, ':batch_id' => $batchId, ':user' => $triggeredBy,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Same resolve-or-create treatment as resolveOrCreateDepartment()/Position()/Shift() above, for
     * branches -- 2026-08-30, `structure_branches` NEVER had `origami_ref_id`/`data_source`/
     * `sync_batch_id` before now (unlike department/position/team, which already carried these from
     * earlier rounds -- see `database/migrations/2026-08-30_9_branch_sync_and_origami_payroll_code.sql`).
     * candidates.php sends only `branch_ref_id`/`branch_name` (no separate _th/_en pair, unlike
     * department/position) -- same "no separate TH/EN source on the wire" fallback already used for
     * nickname/team names elsewhere in this app: the one name is written to both branch_name_th and
     * branch_name_en. `branch_code` (NOT NULL, no wire equivalent at all) falls back to the same
     * `ORG-{ref_id}` generated-code convention as every other auto-created entity in this file.
     */
    private function resolveOrCreateBranch(int $compId, $refId, array $item, int $batchId, ?int $triggeredBy): ?int {
        if ($refId === null || $refId === '') {
            return null;
        }
        $refId = (int)$refId;
        $stmt = $this->db->prepare("SELECT id FROM structure_branches WHERE origami_ref_id = :ref AND comp_id = :comp");
        $stmt->execute([':ref' => $refId, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
        $name = trim((string)($item['branch_name'] ?? ''));
        $code = "ORG-{$refId}";
        $label = $name !== '' ? $name : $code;
        $stmt = $this->db->prepare("INSERT INTO structure_branches
                (comp_id, branch_code, branch_name_th, branch_name_en, origami_ref_id, data_source, sync_batch_id, created_by)
            VALUES (:comp_id, :code, :name, :name, :ref_id, 'sync', :batch_id, :user)");
        $stmt->execute([':comp_id' => $compId, ':code' => $code, ':name' => $label, ':ref_id' => $refId, ':batch_id' => $batchId, ':user' => $triggeredBy]);
        return (int)$this->db->lastInsertId();
    }

    private function deactivate(int $compId, int $refId): void {
        $this->db->prepare("UPDATE employees SET employee_status = 'resigned' WHERE origami_ref_id = :ref AND comp_id = :comp AND deleted_at IS NULL")
            ->execute([':ref' => $refId, ':comp' => $compId]);
    }

    private function deactivateMissing(int $compId, array $seenRefIds): void {
        if (empty($seenRefIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($seenRefIds), '?'));
        $sql = "UPDATE employees SET employee_status = 'resigned'
            WHERE comp_id = ? AND origami_ref_id IS NOT NULL AND deleted_at IS NULL AND origami_ref_id NOT IN ({$placeholders})";
        $this->db->prepare($sql)->execute(array_merge([$compId], $seenRefIds));
    }

    /**
     * @return array{has_spouse:int,name:?string,id_card:?string} pure computation, no DB access --
     *  same "spouse's own idcard, bundled together with the employee's own father/mother in one
     *  object" shape PayrollSyncModel's own docblock already documents for the OTHER Origami
     *  integration. candidates.php always sends `spouse` as `object|null` (never an absent key), so
     *  null is unambiguously "no spouse on file", not "field not sent this time".
     */
    private function spouseSummaryFromItem(array $item): array {
        $spouse = is_array($item['spouse'] ?? null) ? $item['spouse'] : null;
        if ($spouse === null) {
            return ['has_spouse' => 0, 'name' => null, 'id_card' => null];
        }
        $name = trim((string)($spouse['spouse_name'] ?? '') . ' ' . (string)($spouse['spouse_lastname'] ?? ''));
        if ($name === '') {
            return ['has_spouse' => 0, 'name' => null, 'id_card' => null];
        }
        return ['has_spouse' => 1, 'name' => $name, 'id_card' => !empty($spouse['spouse_idcard']) ? (string)$spouse['spouse_idcard'] : null];
    }

    /**
     * Whole-set replace (delete+reinsert) into `employee_parents`/`employee_dependents` -- same
     * "overwrite every pull, not a diff-and-patch" policy `PayrollSyncModel`'s own equivalent
     * uses, same accepted tradeoff (a manually-added dependent/parent Origami has no record of gets
     * removed on the next apply -- not an oversight). `child_type`'s real legitimate/adopted mapping
     * is unconfirmed (raw integer code, no glossary) -- every synced child defaults to
     * `relationship='child_legitimate'` since the column is NOT NULL and there's no reliable signal
     * to pick `child_adopted` instead, same as the other integration. Own-transaction guard per this
     * project's own multi-step-write convention.
     */
    private function applySpouseAndChildren(int $employeeId, array $item, ?int $triggeredBy): void {
        $spouse = is_array($item['spouse'] ?? null) ? $item['spouse'] : null;
        $children = is_array($item['children'] ?? null) ? $item['children'] : [];

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare("DELETE FROM employee_parents WHERE employee_id = :employee_id AND relationship IN ('father', 'mother')")
                ->execute([':employee_id' => $employeeId]);
            if ($spouse !== null) {
                $parentSlots = [
                    'father' => ['name' => trim((string)($spouse['father_name'] ?? '') . ' ' . (string)($spouse['father_lastname'] ?? '')), 'id_card' => $spouse['father_idcard'] ?? null],
                    'mother' => ['name' => trim((string)($spouse['mother_name'] ?? '') . ' ' . (string)($spouse['mother_lastname'] ?? '')), 'id_card' => $spouse['mother_idcard'] ?? null],
                ];
                foreach ($parentSlots as $relationship => $parent) {
                    if ($parent['name'] === '') {
                        continue;
                    }
                    $idCardEnc = !empty($parent['id_card']) ? EncryptionService::encrypt((string)$parent['id_card']) : null;
                    $this->db->prepare("INSERT INTO employee_parents
                            (employee_id, name, id_card_no, relationship, status, key_version, created_by)
                        VALUES (:employee_id, :name, :id_card_no, :relationship, 'active', :key_version, :created_by)")
                        ->execute([
                            ':employee_id' => $employeeId, ':name' => $parent['name'],
                            ':id_card_no' => $idCardEnc['value'] ?? null, ':relationship' => $relationship,
                            ':key_version' => $idCardEnc !== null ? EncryptionService::currentKeyVersion() : null,
                            ':created_by' => $triggeredBy,
                        ]);
                }
            }

            $this->db->prepare("DELETE FROM employee_dependents WHERE employee_id = :employee_id")->execute([':employee_id' => $employeeId]);
            foreach ($children as $child) {
                if (!is_array($child)) {
                    continue;
                }
                $childName = trim((string)($child['child_name'] ?? '') . ' ' . (string)($child['child_lastname'] ?? ''));
                if ($childName === '') {
                    continue;
                }
                $idCardEnc = !empty($child['child_idcard']) ? EncryptionService::encrypt((string)$child['child_idcard']) : null;
                $dob = !empty($child['child_birthday']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$child['child_birthday']) ? $child['child_birthday'] : null;
                $this->db->prepare("INSERT INTO employee_dependents
                        (employee_id, name, id_card_no, date_of_birth, relationship, studying, status, key_version, created_by)
                    VALUES (:employee_id, :name, :id_card_no, :date_of_birth, 'child_legitimate', 0, 'active', :key_version, :created_by)")
                    ->execute([
                        ':employee_id' => $employeeId, ':name' => $childName,
                        ':id_card_no' => $idCardEnc['value'] ?? null, ':date_of_birth' => $dob,
                        ':key_version' => $idCardEnc !== null ? EncryptionService::currentKeyVersion() : null,
                        ':created_by' => $triggeredBy,
                    ]);
            }
            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Writes $decoded's bytes to a new file under public/uploads/{$subDir}/{$compId}/ UNLESS they
     * exactly match what's already at $currentRelPath (sha256 compare) -- same "don't pile up an
     * identical orphaned file on every sync" precedent `PayrollSyncModel`'s own signature handling
     * already established for the OTHER Origami integration. Returns the relative path to persist:
     * a new file's path, the unchanged existing path (content matched, or the write itself failed),
     * or the existing path untouched when $decoded is null (nothing to write this pull).
     */
    private function writeSyncedFile(int $compId, string $subDir, ?string $currentRelPath, ?array $decoded): ?string {
        if ($decoded === null) {
            return $currentRelPath;
        }
        $currentAbsPath = $currentRelPath ? (__DIR__ . '/../../../' . $currentRelPath) : null;
        if ($currentAbsPath && is_file($currentAbsPath) && hash('sha256', (string)file_get_contents($currentAbsPath)) === hash('sha256', $decoded['bytes'])) {
            return $currentRelPath;
        }
        $dir = __DIR__ . '/../../../public/uploads/' . $subDir . '/' . $compId . '/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $currentRelPath;
        }
        $fileName = bin2hex(random_bytes(16)) . '.' . $decoded['ext'];
        if (file_put_contents($dir . $fileName, $decoded['bytes']) === false) {
            return $currentRelPath;
        }
        return 'public/uploads/' . $subDir . '/' . $compId . '/' . $fileName;
    }

    /**
     * 2026-08-28, real bug found and fixed against Origami's ACTUAL live candidates.php response
     * (not a theoretical concern) -- of 50 real candidates fetched for a real company, gender was
     * missing for 10, employment_date for 8, personal_email for 17, date_of_birth for 1, and
     * mobile_no for ALL 50 -- meaning the original strict "every field required" validation below
     * rejected literally every real-world candidate, making the whole picker feature unusable the
     * first time it was ever pointed at real data (confirmed live: a 30-candidate apply came back
     * 0 success / 30 error, all "Missing or invalid ...", then a 1-candidate retry failed the same
     * way). Relaxed to reuse the EXACT SAME sentinel-placeholder convention
     * `PayrollSyncModel::createPlaceholderEmployeesForUnmapped()` already established for this
     * identical "sync brought over incomplete data, don't block on it" situation --
     * `EmployeeModel::isCompletenessValueFilled()` already recognizes these specific sentinels
     * ('1900-01-01', '0000000000', 'sync-pending-...@placeholder.local') and scores a record
     * carrying them as incomplete, so a payroll admin sees it correctly flagged on Employee List's
     * own completeness bar instead of the record either being silently rejected from sync entirely
     * or landing with a fake value that reads as "complete." `employment_date`'s own fallback is
     * `date('Y-m-d')` (today), not a sentinel -- same reasoning as that same PayrollSyncModel
     * precedent: `PayrollRunModel::recalculate()` selects employees into a run by date range
     * against this column, so an arbitrary sentinel date could silently push a real employee out
     * of every run's window, where "today" keeps them correctly eligible going forward.
     * `employee_no`/name(+surname)/`employment_status` stay hard-required (a missing employee_no
     * falls back to a generated `ORG-{ref_id}` code instead, same "generated code" fallback the
     * API guide already documents for department/position/shift; a name needs at least ONE full
     * language pair, mirrored into the other exactly like every other th||en display fallback
     * already in this app; employment_status directly drives active/probation/resigned and is too
     * operationally significant to guess at, and none of the 50 real candidates were missing it
     * anyway).
     *
     * 2026-08-30, rewritten -- the UPDATE branch below used to be a fixed SQL string unconditionally
     * overwriting every HR-owned column on every apply. Switched to a dynamic $set/$params builder
     * SPECIFICALLY so the new personal-profile fields (title/nickname/nationality/religion/
     * marital_status/office_tel/origami_payroll_code, all frequently blank on real Origami records
     * per the same real-data lesson noted in this class's own top docblock) can be written only when
     * THIS pull actually resolved/recognized a value, leaving the column alone otherwise -- an
     * unconditional overwrite-with-blank on every re-sync would silently erase data a payroll admin
     * later filled in by hand. The pre-existing unconditional fields (name/dob/gender/email/mobile/
     * department/position/shift/employment_date/status/origami_ref_id/etc.) keep their exact same
     * always-overwritten behavior, just expressed as array entries now instead of a literal string.
     * @param array{department_id: ?int, position_id: ?int, shift_id: ?int, branch_id: ?int} $links
     */
    private function upsertItem(int $compId, array $item, array $links, int $batchId, ?int $triggeredBy, ?int $existingId, string $dataSource): void {
        $refId = isset($item['ref_id']) && is_numeric($item['ref_id']) ? (int)$item['ref_id'] : null;

        $employeeNo = trim((string)($item['employee_no'] ?? ''));
        if ($employeeNo === '') {
            if ($refId === null) {
                throw new InvalidArgumentException('Missing employee_no and ref_id -- cannot identify this candidate at all.');
            }
            $employeeNo = 'ORG-' . $refId;
        }

        $nameTh = trim((string)($item['name_th'] ?? ''));
        $surnameTh = trim((string)($item['surname_th'] ?? ''));
        $nameEn = trim((string)($item['name_en'] ?? ''));
        $surnameEn = trim((string)($item['surname_en'] ?? ''));
        $hasTh = $nameTh !== '' && $surnameTh !== '';
        $hasEn = $nameEn !== '' && $surnameEn !== '';
        if (!$hasTh && !$hasEn) {
            throw new InvalidArgumentException('Missing name -- need at least one full name+surname pair (Thai or English).');
        }
        if (!$hasTh) { $nameTh = $nameEn; $surnameTh = $surnameEn; }
        if (!$hasEn) { $nameEn = $nameTh; $surnameEn = $surnameTh; }

        $dob = trim((string)($item['date_of_birth'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            $dob = '1900-01-01';
        }
        $gender = (string)($item['gender'] ?? '');
        if (!in_array($gender, ['male', 'female', 'other'], true)) {
            $gender = 'male';
        }
        $employmentDate = trim((string)($item['employment_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $employmentDate)) {
            $employmentDate = date('Y-m-d');
        }
        $employmentStatus = (string)($item['employment_status'] ?? '');
        if (!in_array($employmentStatus, ['probation', 'permanent', 'contract', 'resigned', 'terminated'], true)) {
            throw new InvalidArgumentException('Missing or invalid employment_status.');
        }

        $personalEmail = trim((string)($item['personal_email'] ?? ''));
        if ($personalEmail === '') {
            $personalEmail = 'sync-pending-' . ($refId ?? preg_replace('/[^A-Za-z0-9]/', '', $employeeNo)) . '@placeholder.local';
        }
        $mobileNo = substr(trim((string)($item['mobile_no'] ?? '')), 0, 10);
        if ($mobileNo === '') {
            $mobileNo = '0000000000';
        }
        // 2026-08-30, real gap found and fixed while auditing candidates.php against this class:
        // `employees.mobile_country_code` (added 2026-08-19, "เบอร์โทรศัพท์ให้ใส่ Prefix ได้") already
        // exists for exactly this -- a phone country calling-code prefix, display-only alongside
        // mobile_no -- and Origami's candidates.php now sends `tel_code` (its own default '+66' when
        // blank) for precisely this purpose, but this class never read it at all. Same HR-owned,
        // overwrite-every-sync treatment as mobile_no itself (same field family).
        $mobileCountryCode = trim((string)($item['tel_code'] ?? '')) ?: '+66';

        // 2026-08-30, new field batch -- normalized/resolved once, shared by both branches below.
        $title = $this->normalizeTitle($item['title'] ?? null);
        $nickname = trim((string)($item['nickname'] ?? ''));
        $nationalityCode = $this->resolveNationalityCode($item['nationality'] ?? null);
        $religion = trim((string)($item['religion'] ?? ''));
        $maritalStatus = $this->normalizeMaritalStatus($item['marital_status'] ?? null);
        $officeTel = trim((string)($item['emp_tel'] ?? ''));
        $origamiPayrollCode = trim((string)($item['payroll_code'] ?? ''));
        $idCard = trim((string)($item['idcard'] ?? ''));
        $idCardIssued = trim((string)($item['idcard_issued'] ?? ''));
        $idCardExpire = trim((string)($item['idcard_expire'] ?? ''));
        $spouseSummary = $this->spouseSummaryFromItem($item);
        $signatureDecoded = $this->decodeSignatureDrawing(isset($item['signature_drawing']) ? (string)$item['signature_drawing'] : null);
        $photoDecoded = $this->downloadPhoto($item['photo_url'] ?? null);

        if ($existingId !== null) {
            // Current encrypted-set + file paths, same "decrypt/re-encrypt the WHOLE set together"
            // invariant `PayrollSyncModel::applyOneEmployeeMasterFields()` already established for
            // the OTHER Origami integration -- `employees.key_version` is shared across id_card_no/
            // tax_id_no/passport_no/bank_account_no/sso_no/spouse_id_card_no, so touching only SOME
            // of them without re-encrypting the rest under the same version would desync it.
            $curStmt = $this->db->prepare("SELECT id_card_no, tax_id_no, passport_no, bank_account_no, sso_no, spouse_id_card_no, key_version,
                    signature_path, profile_photo_path, sso_enrolled, sso_start_date
                FROM employees WHERE id = :id");
            $curStmt->execute([':id' => $existingId]);
            $current = $curStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $existingKeyVersion = isset($current['key_version']) ? (int)$current['key_version'] : null;
            $plain = [
                'id_card_no' => EncryptionService::decrypt($current['id_card_no'] ?? null, $existingKeyVersion),
                'tax_id_no' => EncryptionService::decrypt($current['tax_id_no'] ?? null, $existingKeyVersion),
                'passport_no' => EncryptionService::decrypt($current['passport_no'] ?? null, $existingKeyVersion),
                'bank_account_no' => EncryptionService::decrypt($current['bank_account_no'] ?? null, $existingKeyVersion),
                'sso_no' => EncryptionService::decrypt($current['sso_no'] ?? null, $existingKeyVersion),
                'spouse_id_card_no' => EncryptionService::decrypt($current['spouse_id_card_no'] ?? null, $existingKeyVersion),
            ];
            $touchedEncrypted = false;

            $set = [
                "employee_no = :employee_no", "name_th = :name_th", "surname_th = :surname_th", "name_en = :name_en", "surname_en = :surname_en",
                "date_of_birth = :dob", "gender = :gender", "personal_email = :email", "mobile_no = :mobile", "mobile_country_code = :mobile_country_code",
                "department_id = :department_id", "position_id = :position_id", "shift_id = :shift_id", "branch_id = :branch_id",
                "employment_date = :employment_date", "employment_status = :employment_status",
                "has_spouse = :has_spouse", "spouse_name = :spouse_name",
                "origami_ref_id = :ref_id", "sync_batch_id = :batch_id", "updated_by = :user", "updated_at = CURRENT_TIMESTAMP",
            ];
            $params = [
                ':employee_no' => $employeeNo, ':name_th' => $nameTh, ':surname_th' => $surnameTh, ':name_en' => $nameEn, ':surname_en' => $surnameEn,
                ':dob' => $dob, ':gender' => $gender, ':email' => $personalEmail, ':mobile' => $mobileNo, ':mobile_country_code' => $mobileCountryCode,
                ':department_id' => $links['department_id'], ':position_id' => $links['position_id'], ':shift_id' => $links['shift_id'], ':branch_id' => $links['branch_id'] ?? null,
                ':employment_date' => $employmentDate, ':employment_status' => $employmentStatus,
                ':has_spouse' => $spouseSummary['has_spouse'], ':spouse_name' => $spouseSummary['name'],
                ':ref_id' => $refId, ':batch_id' => $batchId, ':user' => $triggeredBy, ':id' => $existingId,
            ];
            if ($spouseSummary['id_card'] !== $plain['spouse_id_card_no']) {
                $plain['spouse_id_card_no'] = $spouseSummary['id_card'];
                $touchedEncrypted = true;
            }

            // Conditional personal-profile fields -- only written when THIS pull actually resolved/
            // recognized a value, see this method's own 2026-08-30 docblock for why.
            if ($title !== null) { $set[] = "title = :title"; $params[':title'] = $title; }
            if ($nickname !== '') { $set[] = "nickname_th = :nickname_th"; $set[] = "nickname_en = :nickname_en"; $params[':nickname_th'] = $nickname; $params[':nickname_en'] = $nickname; }
            if ($nationalityCode !== null) { $set[] = "nationality = :nationality"; $params[':nationality'] = $nationalityCode; }
            if ($religion !== '') { $set[] = "religion = :religion"; $params[':religion'] = $religion; }
            if ($maritalStatus !== null) { $set[] = "marital_status = :marital_status"; $params[':marital_status'] = $maritalStatus; }
            if ($officeTel !== '') { $set[] = "office_tel = :office_tel"; $params[':office_tel'] = $officeTel; }
            if ($origamiPayrollCode !== '') { $set[] = "origami_payroll_code = :origami_payroll_code"; $params[':origami_payroll_code'] = $origamiPayrollCode; }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $idCardIssued)) { $set[] = "id_card_issue_date = :id_card_issue_date"; $params[':id_card_issue_date'] = $idCardIssued; }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $idCardExpire)) { $set[] = "id_card_expire_date = :id_card_expire_date"; $params[':id_card_expire_date'] = $idCardExpire; }
            if ($idCard !== '' && $idCard !== $plain['id_card_no']) {
                // SSO number defaults to ID card number (same precedent as the OTHER Origami
                // integration, Thai law has equated the two since ~2011) -- Origami's candidates.php
                // carries no separate SSO-number field of its own either.
                $plain['id_card_no'] = $idCard;
                $plain['sso_no'] = $idCard;
                $touchedEncrypted = true;
            }
            if (array_key_exists('deduct_sso', $item) && $item['deduct_sso'] !== null) {
                $set[] = "sso_enrolled = :sso_enrolled";
                $params[':sso_enrolled'] = (int)(bool)$item['deduct_sso'];
            }
            // sso_start_date default (2026-08-30, same rule as this class's own 2026-08-30 SSO fix
            // just above) -- only fills a genuinely blank value on an employee who is (already,
            // independently, or as of THIS pull) sso_enrolled.
            $ssoEnrolledAfter = (array_key_exists('deduct_sso', $item) && $item['deduct_sso'] !== null) ? (bool)$item['deduct_sso'] : !empty($current['sso_enrolled']);
            if ($ssoEnrolledAfter && empty($current['sso_start_date'])) {
                $set[] = "sso_start_date = :sso_start_date";
                $params[':sso_start_date'] = $employmentDate;
            }

            $newSignaturePath = $this->writeSyncedFile($compId, 'employee_signatures', $current['signature_path'] ?? null, $signatureDecoded);
            if ($newSignaturePath !== ($current['signature_path'] ?? null)) {
                $set[] = "signature_path = :signature_path";
                $params[':signature_path'] = $newSignaturePath;
            }
            $newPhotoPath = $this->writeSyncedFile($compId, 'employee_photos', $current['profile_photo_path'] ?? null, $photoDecoded);
            if ($newPhotoPath !== ($current['profile_photo_path'] ?? null)) {
                $set[] = "profile_photo_path = :profile_photo_path";
                $params[':profile_photo_path'] = $newPhotoPath;
            }

            if ($touchedEncrypted) {
                foreach (['id_card_no' => 'id_card_no_hash', 'tax_id_no' => 'tax_id_no_hash', 'passport_no' => null,
                          'bank_account_no' => 'bank_account_no_hash', 'sso_no' => 'sso_no_hash', 'spouse_id_card_no' => null] as $col => $hashCol) {
                    $enc = EncryptionService::encrypt($plain[$col]);
                    $set[] = "{$col} = :{$col}";
                    $params[":{$col}"] = $enc['value'] ?? null;
                    if ($hashCol !== null) {
                        $set[] = "{$hashCol} = :{$hashCol}";
                        $params[":{$hashCol}"] = EncryptionService::hash($plain[$col]);
                    }
                }
                $set[] = "key_version = :key_version";
                $params[':key_version'] = EncryptionService::currentKeyVersion();
            }

            $sql = "UPDATE employees SET " . implode(', ', $set) . " WHERE id = :id";
            $this->db->prepare($sql)->execute($params);
            $this->applySpouseAndChildren($existingId, $item, $triggeredBy);
            return;
        }

        // INSERT branch -- `nationality`/`title` are NOT NULL with no schema default, so an
        // unresolved value still needs SOME fallback (unlike the UPDATE branch above, which can
        // simply leave the existing column alone). `nationalityCode` resolved via
        // resolveNationalityCode() above when possible (2026-08-30, real bug found and fixed: the
        // OLD fallback stored the raw word 'Thai' directly into this NOT NULL CODE column --
        // `employees.nationality` LEFT JOINs master_nationalities ON e.nationality = mn.
        // nationality_code, a short code like "TH", not a display name -- so a brand-new employee
        // synced with no resolvable nationality was already silently breaking that join before this
        // fix, exact same bug class PayrollSyncModel's own 2026-08-19 revision found on the OTHER
        // Origami integration; confirmed 'TH' is this app's own real code for "Thai" via direct query
        // against master_nationalities before writing this).
        $nationalityCode = $nationalityCode ?? 'TH';
        $titleFinal = $title ?? 'mr';

        // Payroll-owned fields, insert-only -- see this class's own "Field ownership" docblock
        // (2026-08-28 update) for why these are taken from the payload when present instead of
        // being left at bare schema defaults, and why this whole block is skipped entirely on the
        // UPDATE branch above.
        $salaryType = in_array($item['salary_type'] ?? '', ['monthly', 'daily', 'hourly'], true) ? $item['salary_type'] : 'monthly';
        $baseSalaryAmount = is_numeric($item['base_salary_amount'] ?? null) ? (string)$item['base_salary_amount'] : '0.00';
        $taxCalculationMethod = in_array($item['tax_calculation_method'] ?? '', ['average', 'actual'], true) ? $item['tax_calculation_method'] : 'average';
        $paymentType = in_array($item['payment_type'] ?? '', ['bank', 'cash'], true) ? $item['payment_type'] : 'bank';
        $bankId = $this->resolveBankId($item['bank_code'] ?? null);
        $bankAccountNo = trim((string)($item['bank_account_no'] ?? ''));
        $bankAccountName = trim((string)($item['bank_account_name'] ?? '')) ?: null;
        $bankBranch = trim((string)($item['bank_branch'] ?? '')) ?: null;
        $ssoEnrolled = (array_key_exists('deduct_sso', $item) && $item['deduct_sso'] !== null) ? (int)(bool)$item['deduct_sso'] : 0;
        $ssoStartDate = $ssoEnrolled ? $employmentDate : null;
        $signaturePath = $this->writeSyncedFile($compId, 'employee_signatures', null, $signatureDecoded);
        $photoPath = $this->writeSyncedFile($compId, 'employee_photos', null, $photoDecoded);

        // Same AES-256-GCM mechanism EmployeeModel::save() itself uses for these columns -- never
        // stored plaintext. key_version is only set when at least one of them actually produced
        // ciphertext (EncryptionService::encrypt() returns null for an empty value).
        $baseSalaryEnc = EncryptionService::encrypt($baseSalaryAmount !== '' ? $baseSalaryAmount : null);
        $bankAccountEnc = EncryptionService::encrypt($bankAccountNo !== '' ? $bankAccountNo : null);
        $bankAccountNoHash = EncryptionService::hash($bankAccountNo !== '' ? $bankAccountNo : null);
        $idCardEnc = EncryptionService::encrypt($idCard !== '' ? $idCard : null);
        $ssoNoEnc = EncryptionService::encrypt($idCard !== '' ? $idCard : null); // same "defaults to id_card_no" rule as the UPDATE branch above.
        $spouseIdCardEnc = EncryptionService::encrypt($spouseSummary['id_card']);
        $keyVersion = ($baseSalaryEnc !== null || $bankAccountEnc !== null || $idCardEnc !== null || $spouseIdCardEnc !== null) ? EncryptionService::currentKeyVersion() : null;

        $stmt = $this->db->prepare("INSERT INTO employees
                (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
                 nickname_th, nickname_en, religion, marital_status, office_tel, origami_payroll_code,
                 personal_email, mobile_no, mobile_country_code, address_line_1_register, address_line_1_contact,
                 emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
                 department_id, position_id, shift_id, branch_id,
                 employment_date, employment_status, employment_type, workforce_type, record_time_method,
                 payment_type, bank_id, bank_account_no, bank_account_no_hash, bank_account_name, bank_branch,
                 salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
                 id_card_no, id_card_no_hash, id_card_issue_date, id_card_expire_date, sso_no, sso_no_hash, sso_enrolled, sso_start_date,
                 has_spouse, spouse_name, spouse_id_card_no, signature_path, profile_photo_path,
                 origami_ref_id, data_source, sync_batch_id, key_version, created_by)
            VALUES
                (:comp_id, :employee_no, :title, :gender, :name_th, :surname_th, :name_en, :surname_en, :dob, :nationality,
                 :nickname_th, :nickname_en, :religion, :marital_status, :office_tel, :origami_payroll_code,
                 :email, :mobile, :mobile_country_code, '', '',
                 'N/A', 'N/A', 'N/A', '0000000000',
                 :department_id, :position_id, :shift_id, :branch_id,
                 :employment_date, :employment_status, 'full_time', 'office', 'manual',
                 :payment_type, :bank_id, :bank_account_no, :bank_account_no_hash, :bank_account_name, :bank_branch,
                 :salary_type, :base_salary_amount, :employment_date, :tax_calculation_method, 'active',
                 :id_card_no, :id_card_no_hash, :id_card_issue_date, :id_card_expire_date, :sso_no, :sso_no_hash, :sso_enrolled, :sso_start_date,
                 :has_spouse, :spouse_name, :spouse_id_card_no, :signature_path, :profile_photo_path,
                 :ref_id, :data_source, :batch_id, :key_version, :user)");
        $stmt->execute([
            ':comp_id' => $compId, ':employee_no' => $employeeNo, ':title' => $titleFinal, ':gender' => $gender,
            ':name_th' => $nameTh, ':surname_th' => $surnameTh, ':name_en' => $nameEn, ':surname_en' => $surnameEn,
            ':dob' => $dob, ':nationality' => $nationalityCode,
            ':nickname_th' => $nickname !== '' ? $nickname : null, ':nickname_en' => $nickname !== '' ? $nickname : null,
            ':religion' => $religion !== '' ? $religion : null, ':marital_status' => $maritalStatus,
            ':office_tel' => $officeTel !== '' ? $officeTel : null, ':origami_payroll_code' => $origamiPayrollCode !== '' ? $origamiPayrollCode : null,
            ':email' => $personalEmail, ':mobile' => $mobileNo, ':mobile_country_code' => $mobileCountryCode,
            ':department_id' => $links['department_id'], ':position_id' => $links['position_id'], ':shift_id' => $links['shift_id'], ':branch_id' => $links['branch_id'] ?? null,
            ':employment_date' => $employmentDate, ':employment_status' => $employmentStatus,
            ':payment_type' => $paymentType, ':bank_id' => $bankId,
            ':bank_account_no' => $bankAccountEnc['value'] ?? null, ':bank_account_no_hash' => $bankAccountNoHash,
            ':bank_account_name' => $bankAccountName, ':bank_branch' => $bankBranch,
            ':salary_type' => $salaryType, ':base_salary_amount' => $baseSalaryEnc['value'] ?? null,
            ':tax_calculation_method' => $taxCalculationMethod,
            ':id_card_no' => $idCardEnc['value'] ?? null, ':id_card_no_hash' => EncryptionService::hash($idCard !== '' ? $idCard : null),
            ':id_card_issue_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $idCardIssued) ? $idCardIssued : null,
            ':id_card_expire_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $idCardExpire) ? $idCardExpire : null,
            ':sso_no' => $ssoNoEnc['value'] ?? null, ':sso_no_hash' => EncryptionService::hash($idCard !== '' ? $idCard : null),
            ':sso_enrolled' => $ssoEnrolled, ':sso_start_date' => $ssoStartDate,
            ':has_spouse' => $spouseSummary['has_spouse'], ':spouse_name' => $spouseSummary['name'], ':spouse_id_card_no' => $spouseIdCardEnc['value'] ?? null,
            ':signature_path' => $signaturePath, ':profile_photo_path' => $photoPath,
            ':ref_id' => $refId, ':data_source' => $dataSource, ':batch_id' => $batchId, ':key_version' => $keyVersion, ':user' => $triggeredBy,
        ]);
        $this->applySpouseAndChildren((int)$this->db->lastInsertId(), $item, $triggeredBy);
    }

    /** Applies ONE already-fetched Origami employee item (same shape as fetchEmployees()'s own
     *  items) -- upsert only, no deactivate-missing sweep (that's sync()'s own bulk-wide
     *  responsibility, meaningless for a single hand-picked record). Used by
     *  EmployeeSyncModel::apply() (the interactive "Sync Employee from Origami" picker, 2026-08-28)
     *  so the exact same field-validation/HR-vs-payroll-ownership rules upsertItem() already
     *  enforces stay the single source of truth for both the future bulk auto-sync path and this
     *  interactive one -- nothing about how an employee gets written from Origami data is
     *  duplicated here. */
    public function applyOne(int $compId, array $item, int $batchId, ?int $triggeredBy): array {
        $refId = isset($item['ref_id']) && is_numeric($item['ref_id']) ? (int)$item['ref_id'] : null;
        if ($refId === null) {
            throw new InvalidArgumentException('Missing or invalid ref_id.');
        }
        if (array_key_exists('is_active', $item) && !$item['is_active']) {
            $this->deactivate($compId, $refId);
            return ['action' => 'deactivated'];
        }
        // 2026-08-28, explicit follow-up: "Sync พนักงานพร้อมสร้าง Master ที่ขาดอัตโนมัติ" -- unlike
        // sync()'s own resolveRef() (which refuses to guess, see that method's own docblock), this
        // interactive path auto-creates a missing department/position/shift on the fly from
        // whatever code/name/(shift time) the candidate carries. Team is deliberately excluded --
        // see OrigamiEmployeeCandidateClient's own docblock on why Team has no Origami-side
        // equivalent to auto-create against.
        $links = [
            'department_id' => $this->resolveOrCreateDepartment($compId, $item['department_ref_id'] ?? null, $item, $batchId, $triggeredBy),
            'position_id' => $this->resolveOrCreatePosition($compId, $item['position_ref_id'] ?? null, $item, $batchId, $triggeredBy),
            'shift_id' => $this->resolveOrCreateShift($compId, $item['shift_ref_id'] ?? null, $item, $batchId, $triggeredBy),
            'branch_id' => $this->resolveOrCreateBranch($compId, $item['branch_ref_id'] ?? null, $item, $batchId, $triggeredBy),
        ];
        $existingId = $this->findByRefId($compId, $refId);
        if ($existingId === null) {
            $employeeNo = trim((string)($item['employee_no'] ?? ''));
            $existingId = $employeeNo !== '' ? $this->findByEmployeeNo($compId, $employeeNo) : null;
        }
        $this->upsertItem($compId, $item, $links, $batchId, $triggeredBy, $existingId, 'sync');
        return ['action' => $existingId !== null ? 'updated' : 'inserted'];
    }

    public function sync(int $compId, int $origamiCompanyId, OrigamiSyncClientInterface $client, int $batchId, ?int $triggeredBy): array {
        $items = $client->fetchEmployees($origamiCompanyId);
        $success = 0;
        $errors = [];
        $seenRefIds = [];
        foreach ($items as $item) {
            $refId = $item['ref_id'] ?? null;
            try {
                if ($refId === null || !is_numeric($refId)) {
                    throw new InvalidArgumentException('Missing or invalid ref_id.');
                }
                $refIdInt = (int)$refId;
                $seenRefIds[] = $refIdInt;
                if (array_key_exists('is_active', $item) && !$item['is_active']) {
                    $this->deactivate($compId, $refIdInt);
                } else {
                    $links = [
                        'department_id' => $this->resolveRef('structure_departments', $compId, $item['department_ref_id'] ?? null),
                        'position_id' => $this->resolveRef('structure_positions', $compId, $item['position_ref_id'] ?? null),
                        'shift_id' => $this->resolveRef('shifts', $compId, $item['shift_ref_id'] ?? null),
                    ];
                    $existingId = $this->findByRefId($compId, $refIdInt);
                    $this->upsertItem($compId, $item, $links, $batchId, $triggeredBy, $existingId, 'sync');
                }
                $success++;
            } catch (Throwable $e) {
                $errors[] = ['ref_id' => $refId, 'message' => $e->getMessage()];
            }
        }
        $this->deactivateMissing($compId, $seenRefIds);
        return ['total' => count($items), 'success' => $success, 'error' => count($errors), 'errors' => $errors];
    }

    public function importRow(int $compId, array $item, int $batchId, ?int $triggeredBy): array {
        $links = [
            'department_id' => $this->resolveRefByCode('structure_departments', 'department_code', $compId, $item['department_code'] ?? null),
            'position_id' => $this->resolveRefByCode('structure_positions', 'position_code', $compId, $item['position_code'] ?? null),
            'shift_id' => $this->resolveRefByCode('shifts', 'shift_code', $compId, $item['shift_code'] ?? null),
        ];
        $refId = $item['origami_ref_id'] ?? ($item['ref_id'] ?? null);
        if ($refId !== null && $refId !== '' && is_numeric($refId)) {
            $existingId = $this->findByRefId($compId, (int)$refId);
        } else {
            $employeeNo = trim((string)($item['employee_no'] ?? ''));
            $existingId = $employeeNo !== '' ? $this->findByEmployeeNo($compId, $employeeNo) : null;
        }
        $itemWithRef = $item;
        $itemWithRef['ref_id'] = $refId;
        $this->upsertItem($compId, $itemWithRef, $links, $batchId, $triggeredBy, $existingId, 'import');
        return ['action' => $existingId !== null ? 'updated' : 'inserted'];
    }
}
