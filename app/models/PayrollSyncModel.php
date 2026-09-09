<?php
declare(strict_types=1);

/**
 * Ingest for the Payroll Sync API (PAYROLL_SYNC_API.md) -- Origami Payroll pushes one approved
 * attendance-derived cycle (OT, late/absent, leave, trip allowance, custom items) per request,
 * keyed by a globally-unique origami_process_id. ingest() only receives and stores it (idempotent
 * upsert). pendingList() surfaces unconsumed rows for the Payroll Process page's "Pending Pull"
 * station (payroll_runs.sync_process_id, set by PayrollRunModel::create(), is what marks a row
 * consumed) -- actually turning item_values/attendance figures into earning/deduction lines on a
 * run is still separate, later work; this model never touches employee_earning_deductions.
 *
 * remapUnmappedItems() is called by PayrollRunModel::create() at the moment of pulling a pending
 * process into a run -- it runs a full Origami HR master-data sync first (department/position/
 * shift/employee, via MasterDataSyncOrchestrator) then re-resolves any row still unmapped, so the
 * user doesn't have to click "Sync Now" as a separate step before pulling (per explicit request).
 * It never re-writes employee_id for a row already mapped -- only rows still sitting at
 * mapping_status='unmapped' from ingest time are touched.
 *
 * createPlaceholderEmployeesForUnmapped() (called right after remapUnmappedItems(), same Pending
 * Pull moment) is a DIFFERENT, deliberately simpler mechanism from the Origami-HR-API sync above --
 * per explicit clarification (2026-08-19), "Sync employee ข้อมูล" here means pulling an employee
 * IN using only what THIS payload already told us (payroll_code + payroll_sync_employee_status),
 * not calling out to the real Origami HR API. It auto-creates a minimal `employees` row
 * (is_payroll_ready=0, data_source='sync') for any payroll_code that still doesn't match an
 * employee after remapUnmappedItems() ran, so the run isn't blocked on an admin manually creating
 * the employee first -- HR fills in the rest afterward via the normal Employee edit form. Calling
 * the real Origami HR employee sync directly (rather than this payload-derived placeholder) remains
 * a separate, future feature -- this method never touches MasterDataSyncOrchestrator/EmployeeSyncer.
 *
 * applyEmployeeMasterFields() (called right after createPlaceholderEmployeesForUnmapped(), same
 * Pending Pull moment) overwrites payment_method_id/bank_id/bank_account_no/sso_enrolled/id_card_no/id_card_issue_date/
 * id_card_expire_date on `employees` for every row resolved to an employee this pull -- per
 * explicit request (2026-08-19), this data is treated as HR-owned/source-of-truth on every pull,
 * NOT "payroll-owned, default-once" like the rest of employees' payment/tax config -- the
 * reasoning is that this data originates from Origami's own already-approved payroll process, not
 * from a payroll admin's local config in this app, so there's nothing local to protect from being
 * overwritten. `pay_bank_name` (the bank's own name, e.g. "Kasikornbank") is never written to
 * `employees.bank_account_name` (the account HOLDER's name) -- the payload has no equivalent for
 * that field, so it (and bank_branch) are deliberately left untouched, same reasoning as
 * `EmployeeSyncer` never touching payroll-owned fields it has no real source for.
 * `employees.id_card_issue_date` (new column, added alongside this feature) is a sync-pull-only
 * field -- deliberately left OUT of `EmployeeModel::allColumns()` so the generic Employee Detail
 * edit form (which has no input for it) can never silently null it out on an unrelated save; if a
 * manual-edit UI is ever added for it, that omission needs to be revisited at the same time.
 * `employees.key_version` is a single column shared by every encrypted field on the row (id_card_no,
 * tax_id_no, passport_no, bank_account_no, sso_no, spouse_id_card_no) -- overwriting just the two
 * fields this method cares about without re-encrypting the others under the same key version would
 * desync that shared column and make sibling fields undecryptable, so this method reads, decrypts,
 * and re-encrypts the WHOLE encrypted set together (same invariant EmployeeModel::save() keeps by
 * always round-tripping every encrypted field through the form together).
 *
 * Company mapping uses `companies.origami_payroll_comp_code` -- a separate ID-space from the SSO
 * integration's `ref_id`/`origami_sso_comp_key` (the doc is explicit that this payload's own
 * `comp_id` is "not meaningful outside" the sending app). Employee mapping needs no new column:
 * `items[].payroll_code` is guaranteed non-empty and matches `employees.employee_no` directly.
 *
 * An unmapped comp_code fails the whole request (caller should get a non-2xx so Origami's cron
 * retries -- retrying works once an admin adds the mapping). An unmapped payroll_code within a
 * mapped company does NOT fail the request -- retrying can't fix a bad code, only a human editing
 * data can, so that row is stored with employee_id=NULL/mapping_status='unmapped' for later
 * reconciliation while the rest of the payload still gets stored (same shape as
 * payroll_run_details.calc_status/calc_errors: per-row failure, operation still succeeds).
 *
 * items[].pay_type / .pay_bank_id / .pay_bank_code / .pay_bank_name / .pay_bank_no / .deduct_sso
 * (added to the doc 2026-08-17) are stored as-received at ingest time, encrypted at rest the same
 * way as BankAccountModel/EmployeeModel where PII (pay_bank_no is an account number) is involved
 * (EncryptionService, value + row-level key_version column) even though this table is just a
 * staging area -- same class of sensitive data deserves the same protection regardless of which
 * table it's passing through. getProcessDetail() decrypts pay_bank_no back only to mask it to the
 * last 4 digits for the View modal; the raw decrypted number and key_version never leave this
 * model there. Applied to `employees` on pull by applyEmployeeMasterFields() -- see that method's
 * own docblock above; ingest() itself never writes to `employees`, only applyEmployeeMasterFields()
 * (a later, separate step in the Pending Pull flow) does.
 *
 * items[].idcard / .idcard_issued / .idcard_expire (added to the doc 2026-08-18) are stored the
 * same as pay_bank_no above -- idcard (the national ID number) is genuine PII so it's encrypted
 * at rest via EncryptionService, sharing this row's single key_version column with pay_bank_no
 * (both are encrypted with whatever the current key version is at ingest time, so one column can
 * serve both, same convention as employees.key_version covering several encrypted columns at
 * once). idcard_issued/idcard_expire are plain dates, not PII on their own. getProcessDetail()
 * decrypts and masks id_card_no to the last 4 digits the same way as pay_bank_no_masked. Also
 * applied to `employees` by applyEmployeeMasterFields(), same as pay_bank_no above.
 *
 * items[].dept_id / .posi_id / .title / .gender / .date_birth / .nickname / .nationality /
 * .religion / .marital_status / .military_service / .emp_pic / .email / .emp_tel / .spouse /
 * .children (added to the doc 2026-08-18 rev 2) are all stored as-received at ingest time (see
 * `payroll_sync_items` column comments). What applyEmployeeMasterFields() actually does with each
 * on pull, per explicit request (2026-08-19, "map correctly into the Employee form -- Department/
 * Position/Bank: create if missing, else fetch the existing ID"):
 *   - dept_id/.posi_id (+ the existing dept_description/position_name) resolve-or-create against
 *     structure_departments/structure_positions -- see resolveOrCreateDepartmentId()/
 *     resolveOrCreatePositionId() docblock. pay_bank_code/.pay_bank_name got the equivalent
 *     upgrade (resolveBankId() -> resolveOrCreateBankId()) at the same time, same "create if
 *     missing" treatment applied consistently across all three.
 *   - title/.gender/.marital_status are mapped only when they normalize to a recognized value
 *     (see normalizeTitle()/normalizeGender()/normalizeMaritalStatus()) -- these are documented as
 *     raw legacy values on the wire, sometimes a numeric code with no glossary given, so an
 *     unrecognized value is left unmapped rather than guessed.
 *   - date_birth maps directly to date_of_birth (unambiguous date field).
 *   - .nationality is resolved against master_nationalities (matching nationality_name_en/_th,
 *     case-insensitively) and the matched nationality_code is written -- NOT the raw incoming
 *     value -- see resolveNationalityCode()'s own docblock for why a raw passthrough here would be
 *     wrong. .religion is resolved the same way (2026-09-02) via resolveReligionCode() -- direct
 *     name match first, an explicit alias map for the names that don't line up cleanly, see that
 *     method's own docblock.
 *   - nickname maps to BOTH nickname_th and nickname_en (no separate TH/EN source on the wire).
 *   - email maps to company_email (NOT personal_email, which is this app's own NOT NULL field --
 *     overwriting it from an ambiguous upstream source risked clobbering real data with a possibly-
 *     different work email). emp_tel maps to office_tel (NOT mobile_no, which is NOT NULL/10-char
 *     mobile-specific and not guaranteed to match emp_tel's format).
 *   - military_service is intentionally NEVER mapped to employees.military_status -- the two
 *     aren't even documented as the same concept (free text vs. a structured enum) and no example
 *     values were given to build a mapping from.
 *   - pass_pro/.pass_pro_date: pass_pro is really the string "Y"/"N" on the wire (2026-08-19,
 *     explicit correction), not the JSON boolean the API doc describes -- see parseYesNoFlag()'s
 *     docblock, a plain truthy cast used to silently store a "not yet passed" employee as passed.
 *     Combined with pass_pro_date it actually encodes three states (still on probation /
 *     evaluated-and-failed / passed), not two -- see deriveProbationStatus()'s docblock.
 *     getProcessDetail() attaches the derived `probation_status` to every item for the Pending Pull
 *     view modal to render. applyOneEmployeeMasterFields() now ALSO auto-transitions
 *     employees.employment_status from 'probation' to 'permanent' when this pull's derived status
 *     is 'passed' (2026-08-19, explicit request -- reverses the original "human decision, never
 *     auto-applied" stance for this one specific direction only: probation -> permanent, guarded on
 *     the employee's CURRENT employment_status still being exactly 'probation' so an employee
 *     already on contract/resigned/terminated is never touched by an old pass_pro=Y on file).
 *     'probation -> contract' is NOT handled -- no signal in this payload distinguishes which
 *     target the employee should move to, so only the permanent case is auto-applied; a contract
 *     employee's status change is still a human decision made via the Employee Detail form.
 *   - emp_pic is intentionally NEVER copied into employees.profile_photo_path -- it's a path on
 *     Origami's own local filesystem ("not resolved to an absolute/public URL" per the doc), so
 *     copying it verbatim would just produce a broken image reference on this app's side.
 *   - spouse/.children are stored encrypted (spouse_data/children_data, whole-JSON-blob encryption
 *     since there's no per-field column to encrypt individually -- both contain real PII like
 *     spouse_idcard/child_idcard). getProcessDetail() decrypts+masks the idcard-like nested fields
 *     before they'd ever reach the frontend, same policy as pay_bank_no/id_card_no above.
 *   - **2026-08-28 update**: now also applied to the real employee record in
 *     applyOneEmployeeMasterFields(), closing a real gap found in this exact scenario (confirmed
 *     via explicit user report: "ข้อมูลที่เชื่อมมายังไม่ครบ...พวกลูก สามี ภรรยา" -- the data was being
 *     received and stored, just never materialized onto the employee's actual profile). Confirmed
 *     via AskUserQuestion: same "Origami is the source of truth, overwrite every pull" policy as
 *     every other field in this method (NOT insert-once like EmployeeSyncer's payroll fields) --
 *     spouse writes employees.has_spouse/.spouse_name/.spouse_id_card_no directly; the employee's
 *     OWN father/mother (bundled inside the same `spouse` object per the doc's own note) and
 *     children both do a whole-set delete+reinsert into employee_parents/employee_dependents on
 *     every pull, same "replace, don't diff" convention already used elsewhere in this project
 *     (approval_workflow_steps, holiday_assignments). The accepted tradeoff of this policy: a
 *     dependent/parent a payroll admin added manually, that Origami has no record of, gets removed
 *     on the next pull for that employee -- not an oversight, the explicitly chosen behavior.
 *     `child_type` does NOT mean legitimate/adopted -- confirmed by the Origami team 2026-09-02
 *     (a follow-up reply after they were asked for a glossary): it's an AGE-based classification for
 *     tax-deduction eligibility (1=Preschool, 2=Furthers studying, 3=Work), unrelated to parentage.
 *     Origami's own payload has no field for legitimate/adopted status at all -- that would need a
 *     new feature request on their side, not something to derive from this. Every synced child still
 *     defaults to relationship='child_legitimate' (the column is NOT NULL) -- `child_type`'s value
 *     is received but currently unused/not stored anywhere on this side.
 *
 * items[].nationality switched on the sending side (PAYROLL_SYNC_API.md, 2026-08-19 revision) from
 * a raw internal Origami ID to a resolved display name (e.g. "Thai"). This mattered here because
 * `employees.nationality` was NEVER a free-text field despite the class docblock above previously
 * saying so -- EmployeeModel::get() LEFT JOINs master_nationalities ON e.nationality =
 * mn.nationality_code (a short ISO-ish code like "TH", what the manual-entry Nationality dropdown
 * actually submits), so a raw passthrough of Origami's OLD numeric ID was already silently breaking
 * that join for every sync-created employee (nationality showed blank in the edit form even though
 * a value existed in the column) -- the ID→name change didn't introduce this bug, it was already
 * there, just newly worth fixing properly instead of continuing to pass through a differently-wrong
 * raw value. resolveNationalityCode() now matches the incoming name against
 * master_nationalities.nationality_name_en/_th (case-insensitive) and writes the resolved
 * nationality_code; on no match, nationality is left untouched entirely (same "don't write what
 * isn't verified" stance as normalizeTitle()/normalizeGender()/normalizeMaritalStatus() above) --
 * not a create-if-missing like resolveOrCreateBankId(), since this is a fixed global country list,
 * not something a sync pull should be minting new rows into.
 *
 * religion had the exact same shape of bug (also a raw passthrough into a column
 * EmployeeModel::get() joins against master_religions.religion_code the same way) -- **fixed
 * 2026-09-02** via resolveReligionCode(). Origami's documented religion names ("Buddha", "Judah",
 * "Irreligious", "Paganism") don't line up cleanly with this app's master_religions rows
 * ("Buddhist", "Judaism", "None", "Other"; there's also no "Sikh" equivalent on Origami's side), so
 * this couldn't reuse the same plain direct-match-only approach nationality uses -- confirmed
 * Origami's COMPLETE, fixed 0-6 religion code table by reading PAYROLL_SYNC_API.md directly (not
 * guessed) and built an explicit alias map (RELIGION_NAME_ALIASES) for the 4 names that don't
 * already match this app's own religion_name_en verbatim -- "Paganism" maps to the generic "Other"
 * bucket as the best-effort catch-all, since no cleaner equivalent exists.
 *
 * items[].support_team_id / .support_team_text / .signature_drawing (added to the doc's 2026-08-27
 * revision) are handled per explicit request ("ตอนบันทึกข้อมูลพนักงาน ให้ไปบันทึกในตารางทีม และ Assign
 * ให้พนักงาน Auto เพิ่ม ลายเซ็นถูกส่งมาแบบ base64"):
 *   - support_team_id/.support_team_text get the exact same resolve-or-create treatment as
 *     dept_id/.posi_id above -- see resolveOrCreateTeamId(). Matches structure_teams.origami_ref_id
 *     first, falls back to an exact team_name_th/_en match, creates a new row (both name columns set
 *     to the same incoming text, same "no separate TH/EN source on the wire" precedent as nickname)
 *     only when neither resolves, and writes the resolved id to employees.team_id on every pull --
 *     this auto-assigns the employee to their support team without any manual step.
 *   - signature_drawing is stored encrypted in payroll_sync_items (see that table's own column
 *     comment) since, unlike emp_pic (a path), this is the actual signature IMAGE content -- decoded
 *     (stripping an optional `data:image/...;base64,` prefix per the doc's own field note) and
 *     MIME-sniffed via decodeSignatureDrawing() using the exact same allowlist (jpg/png/svg) as
 *     EmployeeController::uploadSignature(), then written to a real file under
 *     public/uploads/employee_signatures/{comp_id}/ and employees.signature_path pointed at it --
 *     same path convention/validation (EmployeeModel::isValidSignaturePath()) as a manual signature
 *     upload through the Employee Detail UI, so nothing downstream needs to know this one came from
 *     a sync pull rather than a live drawing. A malformed/unrecognized value is silently skipped
 *     (existing signature_path left untouched) rather than failing the whole pull -- same "don't
 *     guess, don't block on one bad field" stance as the personal-profile normalizers above. Only
 *     writes a NEW file when the decoded bytes actually differ from what's already on disk at the
 *     employee's current signature_path (a sha256 comparison) -- guards against silently piling up
 *     an identical orphaned file on every single pull for an employee whose signature never changes,
 *     while still following this app's existing "re-upload just points at a new file, old one is
 *     left orphaned" precedent (CompanyProfileController/EmployeeController's own uploadSignature())
 *     on the pulls where the signature genuinely did change.
 */
require_once __DIR__ . '/NotificationModel.php';
require_once __DIR__ . '/../services/SyncPayResolver.php';
// 2026-09-02: pendingList()'s own matched-cycle heuristic needs this -- explicit require (not just
// relying on index.php's spl_autoload_register) since PayrollSyncModel.php is also loaded directly
// by CLI test scripts that never go through that autoloader.
require_once __DIR__ . '/PayrollCycleModel.php';
class PayrollSyncModel {
    private PDO $db;

    private const SUPPORTED_SCHEMA_VERSION = 1;
    private const FREQUENCY_TYPES = ['monthly', 'semimonthly', 'weekly', 'biweekly'];

    private ?array $paymentMethodIdsByCode = null;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** 2026-09-02, follow-up cleanup: the legacy employees.payment_type enum this class used to
     *  write directly is gone -- resolves a master_payment_methods code ('transfer'/'cash') into its
     *  real id instead, cached the same way EmployeeModel's own paymentMethodCode() caches the
     *  reverse lookup. Returns null if the code somehow isn't seeded (never expected in practice --
     *  master_payment_methods is seeded by migration, not admin-editable). */
    private function paymentMethodIdByCode(string $code): ?int {
        if ($this->paymentMethodIdsByCode === null) {
            $this->paymentMethodIdsByCode = [];
            $stmt = $this->db->query("SELECT id, code FROM `master_payment_methods`");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $this->paymentMethodIdsByCode[(string)$row['code']] = (int)$row['id'];
            }
        }
        return $this->paymentMethodIdsByCode[$code] ?? null;
    }

    public function ingest(array $payload): array {
        $version = $payload['schema_version'] ?? null;
        if ($version !== self::SUPPORTED_SCHEMA_VERSION) {
            return ['status' => false, 'message' => "Unsupported schema_version: " . var_export($version, true) . '. This receiver only understands version ' . self::SUPPORTED_SCHEMA_VERSION . '.'];
        }

        foreach (['process_id', 'process_no', 'comp_code', 'comp_name', 'frequency_type', 'items'] as $field) {
            if (!isset($payload[$field]) || $payload[$field] === '' || $payload[$field] === []) {
                return ['status' => false, 'message' => "Missing required field: {$field}"];
            }
        }
        if (!is_array($payload['items'])) {
            return ['status' => false, 'message' => 'items must be an array.'];
        }
        if (!in_array($payload['frequency_type'], self::FREQUENCY_TYPES, true)) {
            return ['status' => false, 'message' => 'Invalid frequency_type: ' . var_export($payload['frequency_type'], true)];
        }

        $compId = $this->resolveCompanyId((string)$payload['comp_code']);
        if ($compId === null) {
            return ['status' => false, 'message' => "No company mapped to comp_code '{$payload['comp_code']}'. Set companies.origami_payroll_comp_code for this company first."];
        }

        // 2026-08-31, same-day follow-up (explicit report from the Origami dev team about their new
        // "pull back and re-edit" admin action) -- BEFORE this ingest ever reaches upsertProcess(),
        // check whether this origami_process_id is already linked to a real payroll_runs row
        // (regardless of that run's state -- same `r.id`/no-deleted_at-filter convention every other
        // "already pulled" check in this app already uses, see PayrollRunModel::create()'s own
        // validation query). If so, this is a re-push for something already in progress on our
        // side -- overwriting payroll_sync_items/payroll_sync_employee_status silently here would be
        // genuinely dangerous: PayrollRunModel::recalculate() reads payroll_sync_items LIVE every
        // time it runs, so the linked run's own numbers could change on its NEXT recalculate with
        // zero warning, and we never emit any status push back to Origami until the run reaches
        // pending_approval (submit()) -- meaning Origami's own "we haven't heard anything back yet"
        // safety assumption does not actually cover a process sitting in an active draft run. Block
        // the overwrite, preserve the attempted payload for an admin to review
        // (payroll_sync_blocked_updates), and notify -- never silently apply, never silently drop.
        $stmtLinked = $this->db->prepare("SELECT p.id AS process_row_id, r.id AS run_id
            FROM `payroll_sync_processes` p
            JOIN `payroll_runs` r ON r.sync_process_id = p.id
            WHERE p.origami_process_id = :pid
            LIMIT 1");
        $stmtLinked->execute([':pid' => (int)$payload['process_id']]);
        $linked = $stmtLinked->fetch(PDO::FETCH_ASSOC);
        if ($linked) {
            return $this->recordBlockedUpdate($compId, (int)$linked['process_row_id'], (int)$linked['run_id'], $payload);
        }

        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) { $this->db->beginTransaction(); }

            $processRowId = $this->upsertProcess($compId, $payload);
            $unmappedCount = $this->replaceItems($processRowId, $compId, $payload['items']);
            $this->replaceEmployeeStatus($processRowId, $compId, $payload['employee_status'] ?? []);
            $this->replaceScheduledItemOccurrences($processRowId, $compId, $payload['items'], $payload['scheduled_item_occurrences'] ?? []);

            $stmt = $this->db->prepare("UPDATE payroll_sync_processes SET item_count = :item_count, unmapped_item_count = :unmapped_count WHERE id = :id");
            $stmt->execute([
                ':item_count' => count($payload['items']),
                ':unmapped_count' => $unmappedCount,
                ':id' => $processRowId,
            ]);

            if ($ownTransaction) { $this->db->commit(); }
            // 2026-08-29, explicit request: "มีข้อมูล Sync มาใหม่จาก Origami" -- best-effort, own
            // try/catch: a notification hiccup must never turn a genuinely successful ingest into a
            // failed one (Origami's own cron retries on a non-2xx response, see
            // PayrollSyncController::ingest()'s own docblock -- a spurious failure here would cause
            // needless re-delivery of data that already landed fine).
            try {
                $processNo = (string)($payload['process_no'] ?? '');
                (new NotificationModel())->createForPermissionHolders(
                    $compId, 'payroll_run.process', 'sync_new_data',
                    "มีข้อมูล Sync ใหม่จาก Origami", "New data synced from Origami",
                    "รอบข้อมูล {$processNo} พร้อมให้ดึงเข้าคำนวณเงินเดือนแล้วครับ", "Sync batch {$processNo} is ready to be pulled into a payroll run",
                    "/payroll-process", 'payroll_sync_process', $processRowId, "sync_new_data:{$processRowId}", 'fa-arrows-rotate'
                );
            } catch (Throwable $e) {
                // Best-effort -- see comment above.
            }
            return ['status' => true, 'process_row_id' => $processRowId, 'unmapped_items' => $unmappedCount];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Ingest failed: ' . $e->getMessage()];
        }
    }

    /**
     * 2026-08-31, same-day follow-up -- see ingest()'s own guard comment above for the full "why".
     * Records the BLOCKED push (never applied, never discarded) so an admin can review it via
     * blockedUpdatesList() and explicitly decide to applyBlockedUpdate() or dismissBlockedUpdate()
     * it. Deliberately returns `status: true` (not an error) -- this is a well-understood, expected
     * outcome from Origami's own side (their push genuinely arrived and was received), not a
     * transient failure; per ingest()'s own docblock Origami's cron only retries on a non-2xx
     * response, and this must never trigger an automatic retry loop over something that requires a
     * human decision on our side.
     *
     * Known, deliberate simplification: if the SAME already-linked process is pushed again while an
     * earlier blocked update for it is still `pending`, a SECOND row is inserted rather than
     * superseding/collapsing the first -- multiple genuine edits queuing up before an admin reviews
     * any of them is expected to be rare in practice (a real human editing on Origami's side, not an
     * automated retry loop), so the admin-facing list simply shows every pending one for that
     * process; not worth the complexity of a "supersede the older pending row" mechanism for that.
     */
    private function recordBlockedUpdate(int $compId, int $processRowId, int $runId, array $payload): array {
        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) { $this->db->beginTransaction(); }
            $stmt = $this->db->prepare("INSERT INTO `payroll_sync_blocked_updates`
                    (comp_id, process_row_id, linked_run_id, attempted_payload)
                VALUES (:comp_id, :process_row_id, :linked_run_id, :attempted_payload)");
            $stmt->execute([
                ':comp_id' => $compId, ':process_row_id' => $processRowId, ':linked_run_id' => $runId,
                ':attempted_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
            $blockedUpdateId = (int)$this->db->lastInsertId();
            if ($ownTransaction) { $this->db->commit(); }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Failed to record blocked update: ' . $e->getMessage()];
        }

        // Best-effort, own try/catch -- same reasoning as ingest()'s own "sync_new_data" notification.
        try {
            $processNo = (string)($payload['process_no'] ?? '');
            (new NotificationModel())->createForPermissionHolders(
                $compId, 'payroll_run.process', 'sync_update_blocked',
                "Origami พยายามอัปเดตข้อมูลที่ถูกดึงเข้ารอบเงินเดือนไปแล้ว", "Origami tried to update data already pulled into a payroll run",
                "รอบข้อมูล {$processNo} มีการแก้ไขจาก Origami หลังถูกดึงเข้ารอบเงินเดือนแล้ว ระบบไม่ได้นำไปใช้อัตโนมัติ กรุณาตรวจสอบก่อนนำไปใช้",
                "Sync batch {$processNo} was edited by Origami after being pulled into a payroll run. It was NOT applied automatically -- please review before applying it.",
                "/payroll-process", 'payroll_sync_blocked_update', $blockedUpdateId, "sync_update_blocked:{$blockedUpdateId}", 'fa-triangle-exclamation'
            );
        } catch (Throwable $e) {
            // Best-effort -- see comment above.
        }

        return [
            'status' => true, 'blocked' => true,
            'process_row_id' => $processRowId, 'linked_run_id' => $runId, 'blocked_update_id' => $blockedUpdateId,
            'message' => 'This process is already linked to an existing payroll run. The update was recorded for admin review instead of being applied automatically.',
        ];
    }

    /** Admin-facing list for the Payroll Process page's "Pending Pull" station -- every BLOCKED
     *  update still awaiting a decision (applied/dismissed ones drop out). */
    public function blockedUpdatesList(int $compId): array {
        $stmt = $this->db->prepare("SELECT bu.id, bu.process_row_id, bu.linked_run_id, bu.received_at,
                p.process_no, p.process_subject,
                r.run_name, r.state AS run_state
            FROM `payroll_sync_blocked_updates` bu
            JOIN `payroll_sync_processes` p ON p.id = bu.process_row_id
            JOIN `payroll_runs` r ON r.id = bu.linked_run_id
            WHERE bu.comp_id = :comp_id AND bu.status = 'pending'
            ORDER BY bu.received_at DESC");
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Explicit admin action: apply a previously-blocked update now. Re-runs the EXACT SAME
     * upsertProcess()/replaceItems()/replaceEmployeeStatus() steps ingest() itself uses (against the
     * payload preserved at block-time), then marks the blocked-update row resolved. Does NOT
     * recalculate the linked run itself -- that stays a separate, explicit admin action on the
     * Detail page (this method only refreshes the SOURCE sync data; recalculating is a distinct,
     * already-audited action with its own confirmation, not something to bundle in silently here).
     */
    public function applyBlockedUpdate(int $blockedUpdateId, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT * FROM `payroll_sync_blocked_updates` WHERE id = :id AND comp_id = :comp_id AND status = 'pending'");
        $stmt->execute([':id' => $blockedUpdateId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => false, 'message' => 'Blocked update not found or already resolved.'];
        }
        $payload = json_decode((string)$row['attempted_payload'], true);
        if (!is_array($payload)) {
            return ['status' => false, 'message' => 'The stored payload could not be read.'];
        }

        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) { $this->db->beginTransaction(); }
            $processRowId = $this->upsertProcess($compId, $payload);
            $unmappedCount = $this->replaceItems($processRowId, $compId, $payload['items'] ?? []);
            $this->replaceEmployeeStatus($processRowId, $compId, $payload['employee_status'] ?? []);
            $this->replaceScheduledItemOccurrences($processRowId, $compId, $payload['items'] ?? [], $payload['scheduled_item_occurrences'] ?? []);
            $this->db->prepare("UPDATE payroll_sync_processes SET item_count = :item_count, unmapped_item_count = :unmapped_count WHERE id = :id")
                ->execute([':item_count' => count($payload['items'] ?? []), ':unmapped_count' => $unmappedCount, ':id' => $processRowId]);
            $this->db->prepare("UPDATE `payroll_sync_blocked_updates` SET status = 'applied', resolved_by = :resolved_by, resolved_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':resolved_by' => $userId, ':id' => $blockedUpdateId]);
            if ($ownTransaction) { $this->db->commit(); }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Failed to apply update: ' . $e->getMessage()];
        }

        // Best-effort -- the linked run's own numbers are now stale against the refreshed sync data
        // until someone recalculates it; nudge the same audience that got the original block notice.
        try {
            (new NotificationModel())->createForPermissionHolders(
                $compId, 'payroll_run.process', 'sync_update_applied',
                "อัปเดตข้อมูล Sync แล้ว กรุณาคำนวณรอบเงินเดือนใหม่", "Sync data updated -- please recalculate the linked payroll run",
                null, null, "/payroll-process", 'payroll_run', (int)$row['linked_run_id'], "sync_update_applied:{$blockedUpdateId}", 'fa-rotate'
            );
        } catch (Throwable $e) {
            // Best-effort -- see comment above.
        }

        return ['status' => true, 'process_row_id' => $processRowId, 'linked_run_id' => (int)$row['linked_run_id'], 'unmapped_items' => $unmappedCount];
    }

    /** Explicit admin action: discard a blocked update without applying it -- the row is KEPT
     *  (status='dismissed'), not hard-deleted, same "no hard delete" convention as everywhere else
     *  in this app; it just drops out of blockedUpdatesList()'s own WHERE. */
    public function dismissBlockedUpdate(int $blockedUpdateId, int $compId, int $userId): array {
        $stmt = $this->db->prepare("UPDATE `payroll_sync_blocked_updates` SET status = 'dismissed', resolved_by = :resolved_by, resolved_at = CURRENT_TIMESTAMP
            WHERE id = :id AND comp_id = :comp_id AND status = 'pending'");
        $stmt->execute([':resolved_by' => $userId, ':id' => $blockedUpdateId, ':comp_id' => $compId]);
        if ($stmt->rowCount() === 0) {
            return ['status' => false, 'message' => 'Blocked update not found or already resolved.'];
        }
        return ['status' => true];
    }

    /**
     * 2026-09-08, Origami email exchange (2 rounds) -- handles the new `attribution_update` event
     * (own endpoint, own API key -- see PayrollSyncController::attributionUpdate()). Patches ONLY
     * the attribution columns of an EXISTING `payroll_sync_processes` row, found by Origami's own
     * `process_id` (the same key `ingest()` upserts on) -- never touches items/employee-status/
     * numeric data at all, so this deliberately does NOT go through ingest()'s own
     * "already linked to a run" blocked-update safety net (that guard exists specifically because a
     * FULL re-push could silently change numbers under an in-progress/consumed run -- an
     * attribution-only patch has no such risk category, and the whole point of this event is to
     * still work after the row has already been pulled/merged for audit/reporting visibility, even
     * though resolving a target this way never auto-merges anything -- see below).
     *
     * Deliberately does NOT auto-merge, even if the newly-resolved target already exists as a real
     * run here and this supplemental was ALREADY pulled into its own standalone run via the
     * existing "Pull to Run" escape hatch before the target became known -- same "never auto-merge
     * silently, only ever surface a confirm prompt to a live admin" principle
     * PayrollRunModel::create()'s own pending_merges_ready detection already established, which
     * cannot apply here since there is no live admin session during an inbound webhook call. If
     * this supplemental hasn't been pulled anywhere yet, it simply reappears in Pending Pull with
     * `attribution_target_status` now correctly 'ready' (attributionTargetStatus() is computed
     * live per page view, no extra plumbing needed) -- an admin merges it from there as normal. If
     * it WAS already pulled standalone, the resolved target is still recorded here for visibility,
     * and an admin can manually set `merge_target_run_id` on that run's own Edit form (already
     * built, see PayrollRunModel::update()) if they want to merge it after the fact.
     */
    public function applyAttributionUpdate(array $payload): array {
        $version = $payload['schema_version'] ?? null;
        if ($version !== self::SUPPORTED_SCHEMA_VERSION) {
            return ['status' => false, 'message' => "Unsupported schema_version: " . var_export($version, true) . '. This receiver only understands version ' . self::SUPPORTED_SCHEMA_VERSION . '.'];
        }
        if (($payload['event'] ?? null) !== 'attribution_update') {
            return ['status' => false, 'message' => "Invalid event: expected 'attribution_update'."];
        }
        foreach (['process_id', 'attribution'] as $field) {
            if (!isset($payload[$field]) || $payload[$field] === '') {
                return ['status' => false, 'message' => "Missing required field: {$field}"];
            }
        }
        if (!is_array($payload['attribution'])) {
            return ['status' => false, 'message' => 'attribution must be an object.'];
        }

        $originProcessId = (int)$payload['process_id'];
        $stmt = $this->db->prepare("SELECT id, comp_id, run_kind FROM `payroll_sync_processes` WHERE origami_process_id = :pid LIMIT 1");
        $stmt->execute([':pid' => $originProcessId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            // Genuinely unknown to us -- unlike ingest(), there is no sensible "create it" fallback
            // here (an attribution_update has no items/comp_code/frequency_type etc. to create a
            // full process row from), so this is refused outright rather than silently no-op'd.
            return ['status' => false, 'message' => "Unknown process_id: {$originProcessId}. This process has never been received via the payroll_export event."];
        }

        $attribution = $this->normalizeAttribution((string)$row['run_kind'], $payload['attribution']);
        $updateStmt = $this->db->prepare("UPDATE `payroll_sync_processes` SET
                attribution_target_origami_process_id = :attr_target_id,
                attribution_target_process_no = :attr_target_no,
                attribution_tax_treatment = :attr_tax_treatment,
                attribution_status = :attr_status,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id");
        $updateStmt->execute([
            ':attr_target_id' => $attribution['target_origami_process_id'],
            ':attr_target_no' => $attribution['target_process_no'],
            ':attr_tax_treatment' => $attribution['tax_treatment'],
            ':attr_status' => $attribution['attribution_status'],
            ':id' => $row['id'],
        ]);

        // Best-effort notification, same "must never turn a genuinely successful write into a
        // failed response" posture as ingest()'s own sync_new_data notification -- only fires when
        // this update genuinely just RESOLVED a merge-intent attribution (the case the "please
        // don't finalize until you hear from us" instruction was actually about), not for a
        // separate-treatment or still-pending update.
        if ($attribution['tax_treatment'] === 'merge' && $attribution['attribution_status'] === 'resolved') {
            try {
                (new NotificationModel())->createForPermissionHolders(
                    (int)$row['comp_id'], 'payroll_run.process', 'sync_attribution_resolved',
                    "รอบเป้าหมายของรายการ Sync ถูกกำหนดแล้ว", "Sync item's merge target is now resolved",
                    "รายการที่รอ fold-in พร้อม merge เข้ารอบเป้าหมายแล้วครับ", "An item waiting to fold in is now ready to merge into its target round",
                    "/payroll-process", 'payroll_sync_process', (int)$row['id'], "sync_attribution_resolved:{$row['id']}", 'fa-link'
                );
            } catch (Throwable $e) {
                // Best-effort -- see comment above.
            }
        }

        return ['status' => true, 'process_row_id' => (int)$row['id'], 'attribution_status' => $attribution['attribution_status']];
    }

    private function resolveCompanyId(string $compCode): ?int {
        $stmt = $this->db->prepare("SELECT id FROM companies WHERE origami_payroll_comp_code = :code LIMIT 1");
        $stmt->execute([':code' => $compCode]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /** Loose YYYY-MM-DD check -- same defensive posture as the rest of this class's own field
     *  parsing (never trust an external payload's shape blindly), null passthrough for an absent/
     *  blank/malformed date rather than throwing (these 3 fields are new, additive, and per
     *  PAYROLL_SYNC_API.md's own 2026-08-28 revision note "null if not set yet" is a valid, expected
     *  state -- not an error). */
    private function nullableDate(mixed $value): ?string {
        if (empty($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) {
            return null;
        }
        return (string)$value;
    }

    /** 2026-08-29, per PAYROLL_SYNC_API.md's own 2026-08-28 revision note: "This field name and its
     *  intended use on the receiving side are explicitly NOT confirmed with the Payroll team yet."
     *  Defensively defaulted to 'regular' (today's existing, unchanged behavior) for anything
     *  missing/unrecognized rather than rejecting the whole ingest over one still-draft field --
     *  same "don't trust external input blindly" posture as the rest of this class. */
    private function normalizeRunKind(mixed $value): string {
        return $value === 'supplemental' ? 'supplemental' : 'regular';
    }

    /**
     * 2026-08-31, PAYROLL_SYNC_API.md revision, confirmed field: `attribution` -- only meaningful
     * when `run_kind='supplemental'` (Origami's own rule: "null for every regular process -- a
     * regular cycle is always attributed to itself"). Same defensive posture as
     * normalizeRunKind() -- never trust the external payload's shape blindly; a malformed/partial
     * `attribution` object degrades to "no attribution" (null tax_treatment) rather than throwing
     * or half-populating the columns inconsistently.
     *
     * 2026-09-08, Origami email exchange (2 rounds) -- gained `attribution.status`
     * (`'resolved'`/`'pending_fold_in'`). Origami used to BLOCK sending a supplemental batch at all
     * until an admin had already linked it to a real target -- they now send it immediately with
     * `status='pending_fold_in'` and `target_process_id=null`, following up later with a separate
     * `attribution_update` event once a real target is chosen. Confirmed with Origami (2nd round,
     * not guessed) that `status` (link state) and `tax_treatment` (calc intent -- does finalizing
     * this batch's tax need to WAIT for the merge, or calculate standalone regardless) are
     * deliberately independent: `tax_treatment='separate'` + `status='pending_fold_in'` is a real,
     * intentional combination (a standalone-taxed batch whose REPORTING period attribution just
     * isn't chosen yet), not a contradiction -- see attributionTargetStatus()'s own docblock for
     * where that distinction actually matters.
     *
     * `status` is DERIVED here, not trusted verbatim from the wire, except for the one case that
     * needs it (target genuinely absent) -- a `target_process_id` that IS present always means
     * 'resolved' regardless of what `status` claims, since that's the literal definition Origami
     * themselves gave it (status answers "do we know the target yet").
     * @return array{target_origami_process_id: ?int, target_process_no: ?string, tax_treatment: ?string, attribution_status: ?string}
     */
    private function normalizeAttribution(?string $runKind, mixed $value): array {
        $empty = ['target_origami_process_id' => null, 'target_process_no' => null, 'tax_treatment' => null, 'attribution_status' => null];
        if ($runKind !== 'supplemental' || !is_array($value)) {
            return $empty;
        }
        $treatment = ($value['tax_treatment'] ?? null) === 'merge' ? 'merge' : 'separate';
        $targetId = isset($value['target_process_id']) && is_numeric($value['target_process_id']) ? (int)$value['target_process_id'] : null;
        if ($targetId === null) {
            if (($value['status'] ?? null) !== 'pending_fold_in') {
                // No real target AND not explicitly pending fold-in -- per Origami's own doc, this
                // is the "explicitly chose NOT to attribute this batch to anything" case,
                // functionally identical to `attribution: null` even if a (malformed or intent-only)
                // object was technically present.
                return $empty;
            }
            // Target genuinely not chosen yet -- attribution INTENT is real (tax_treatment still
            // matters, see docblock above) even though the target itself isn't resolved.
            return [
                'target_origami_process_id' => null,
                'target_process_no' => null,
                'tax_treatment' => $treatment,
                'attribution_status' => 'pending_fold_in',
            ];
        }
        return [
            'target_origami_process_id' => $targetId,
            'target_process_no' => !empty($value['target_process_no']) ? trim((string)$value['target_process_no']) : null,
            'tax_treatment' => $treatment,
            'attribution_status' => 'resolved',
        ];
    }

    private function upsertProcess(int $compId, array $p): int {
        $stmt = $this->db->prepare("SELECT id FROM payroll_sync_processes WHERE origami_process_id = :pid LIMIT 1");
        $stmt->execute([':pid' => (int)$p['process_id']]);
        $existingId = $stmt->fetchColumn();

        $rawPayload = json_encode($p, JSON_UNESCAPED_UNICODE);
        // process_subject/process_description (2026-08-28 revision): this specific cycle's own
        // label/note, distinct from period_name (the recurring schedule template) -- "" normalized
        // to null, same rule PAYROLL_SYNC_API.md documents for its other free-text fields.
        $processSubject = !empty($p['process_subject']) ? trim((string)$p['process_subject']) : null;
        $processDescription = !empty($p['process_description']) ? trim((string)$p['process_description']) : null;
        $processStart = $this->nullableDate($p['process_start'] ?? null);
        $processEnd = $this->nullableDate($p['process_end'] ?? null);
        $processPaid = $this->nullableDate($p['process_paid'] ?? null);
        $runKind = $this->normalizeRunKind($p['run_kind'] ?? null);
        $attribution = $this->normalizeAttribution($runKind, $p['attribution'] ?? null);
        // 2026-09-01, PAYROLL_SYNC_API.md revision (per Origami's own reply confirming they picked
        // the external_cycle_code approach) -- an admin-set free-text code on Origami's own Setup >
        // Period screen, meant to be re-entered identically against a payroll_cycles row on this
        // side (PayrollCycleModel::save()'s own `external_cycle_code` field) so
        // PayrollCycleModel::matchForSyncProcess() can join exactly instead of guessing from
        // frequency/cutoff/payment-day. `null`/"" both normalize to null, same rule this method
        // already applies to every other free-text field on this page.
        $externalCycleCode = !empty($p['external_cycle_code']) ? trim((string)$p['external_cycle_code']) : null;

        if ($existingId !== false) {
            $id = (int)$existingId;
            // 2026-09-06, real gap confirmed by Origami (not a hypothetical): their own
            // ProcessModel::pullBackFromPayrollRejection() lets an Origami admin pull a process we
            // rejected back to draft and resend it with the SAME origami_process_id -- "sync_rejected"
            // (our own Pending-Pull-level rejectProcess(), status='rejected' on THIS table, never
            // pulled into a run at all) is one of the 3 statuses that unlocks that pull-back on their
            // side, confirmed as a real, intentionally-designed flow, not an edge case. Before this
            // fix, status/rejected_reason/rejected_by/rejected_at were never reset on a re-ingest, so
            // a resent process stayed stuck at status='rejected' forever -- permanently excluded from
            // pendingList()'s own `AND p.status = 'pending'` filter, meaning fresh, corrected data
            // could never be pulled into a run at all. Safe to reset unconditionally on every
            // re-ingest (not just when currently 'rejected'): a no-op for an already-'pending' row,
            // and harmless even for a process already consumed (pulled into a run, or merged into
            // one via mergeSupplementalIntoRun()) -- pendingList()'s OTHER conditions
            // (`r.id IS NULL`, `merged_into_run_id IS NULL`) are untouched by this column and still
            // correctly keep a consumed process out of Pending Pull regardless of this reset.
            $stmt = $this->db->prepare("UPDATE payroll_sync_processes SET
                    comp_id = :comp_id, process_no = :process_no, process_subject = :process_subject,
                    process_description = :process_description, process_start = :process_start,
                    process_end = :process_end, process_paid = :process_paid, run_kind = :run_kind,
                    attribution_target_origami_process_id = :attr_target_id, attribution_target_process_no = :attr_target_no,
                    attribution_tax_treatment = :attr_tax_treatment, attribution_status = :attr_status,
                    status = 'pending', rejected_reason = NULL, rejected_by = NULL, rejected_at = NULL,
                    origami_report_id = :report_id,
                    origami_comp_code = :comp_code, origami_comp_name = :comp_name,
                    origami_period_id = :period_id, period_name = :period_name, frequency_type = :frequency_type,
                    external_cycle_code = :external_cycle_code,
                    schema_version = :schema_version, raw_payload = :raw_payload, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([
                ':comp_id' => $compId, ':process_no' => (string)$p['process_no'],
                ':process_subject' => $processSubject, ':process_description' => $processDescription,
                ':process_start' => $processStart, ':process_end' => $processEnd, ':process_paid' => $processPaid,
                ':run_kind' => $runKind,
                ':attr_target_id' => $attribution['target_origami_process_id'],
                ':attr_target_no' => $attribution['target_process_no'],
                ':attr_tax_treatment' => $attribution['tax_treatment'],
                ':attr_status' => $attribution['attribution_status'],
                ':report_id' => $p['report_id'] ?? null,
                ':comp_code' => (string)$p['comp_code'], ':comp_name' => (string)$p['comp_name'],
                ':period_id' => $p['period_id'] ?? null, ':period_name' => $p['period_name'] ?? null,
                ':frequency_type' => (string)$p['frequency_type'], ':external_cycle_code' => $externalCycleCode,
                ':schema_version' => (int)$p['schema_version'],
                ':raw_payload' => $rawPayload, ':id' => $id,
            ]);
            $this->db->prepare("DELETE FROM payroll_sync_items WHERE process_id = :id")->execute([':id' => $id]);
            $this->db->prepare("DELETE FROM payroll_sync_employee_status WHERE process_id = :id")->execute([':id' => $id]);
            return $id;
        }

        $stmt = $this->db->prepare("INSERT INTO payroll_sync_processes
                (comp_id, origami_process_id, process_no, process_subject, process_description,
                 process_start, process_end, process_paid, run_kind,
                 attribution_target_origami_process_id, attribution_target_process_no, attribution_tax_treatment, attribution_status,
                 origami_report_id, origami_comp_code, origami_comp_name,
                 origami_period_id, period_name, frequency_type, external_cycle_code, schema_version, raw_payload)
            VALUES (:comp_id, :pid, :process_no, :process_subject, :process_description,
                 :process_start, :process_end, :process_paid, :run_kind,
                 :attr_target_id, :attr_target_no, :attr_tax_treatment, :attr_status,
                 :report_id, :comp_code, :comp_name,
                 :period_id, :period_name, :frequency_type, :external_cycle_code, :schema_version, :raw_payload)");
        $stmt->execute([
            ':comp_id' => $compId, ':pid' => (int)$p['process_id'], ':process_no' => (string)$p['process_no'],
            ':process_subject' => $processSubject, ':process_description' => $processDescription,
            ':process_start' => $processStart, ':process_end' => $processEnd, ':process_paid' => $processPaid,
            ':run_kind' => $runKind,
            ':attr_target_id' => $attribution['target_origami_process_id'],
            ':attr_target_no' => $attribution['target_process_no'],
            ':attr_tax_treatment' => $attribution['tax_treatment'],
            ':attr_status' => $attribution['attribution_status'],
            ':report_id' => $p['report_id'] ?? null, ':comp_code' => (string)$p['comp_code'], ':comp_name' => (string)$p['comp_name'],
            ':period_id' => $p['period_id'] ?? null, ':period_name' => $p['period_name'] ?? null,
            ':external_cycle_code' => $externalCycleCode,
            ':frequency_type' => (string)$p['frequency_type'], ':schema_version' => (int)$p['schema_version'],
            ':raw_payload' => $rawPayload,
        ]);
        return (int)$this->db->lastInsertId();
    }

    private const PAY_TYPES = ['cash', 'transfer'];

    /** @return int number of rows whose payroll_code did not match any employee */
    private function replaceItems(int $processRowId, int $compId, array $items): int {
        $stmt = $this->db->prepare("INSERT INTO payroll_sync_items
                (process_id, employee_id, payroll_code, emp_code, emp_name, mapping_status, origami_report_item_id,
                 dept_description, position_name, origami_branch_id, branch_name,
                 origami_shift_working_id, shift_working_name,
                 pay_type, origami_pay_bank_id, pay_bank_code, pay_bank_name, pay_bank_no, deduct_sso,
                 id_card_no, id_card_issue_date, id_card_expire_date, key_version,
                 working_days, working_mins, absent_days, absent_mins, late_mins, early_mins,
                 ot_mins, ot_req_hrs, ot_req_working_day_hrs, ot_req_weekend_hrs, ot_req_holiday_hrs,
                 leave_approve_days, leave_wait_days, leave_without_pay_days, trip_allowance, item_values,
                 dept_id, posi_id, pass_pro, pass_pro_date, title, gender, date_birth, nickname,
                 nationality, religion, marital_status, military_service, emp_pic, email, emp_tel,
                 support_team_id, support_team_text,
                 spouse_data, children_data, signature_drawing)
            VALUES
                (:process_id, :employee_id, :payroll_code, :emp_code, :emp_name, :mapping_status, :report_item_id,
                 :dept_description, :position_name, :branch_id, :branch_name,
                 :shift_working_id, :shift_working_name,
                 :pay_type, :pay_bank_id, :pay_bank_code, :pay_bank_name, :pay_bank_no, :deduct_sso,
                 :id_card_no, :id_card_issue_date, :id_card_expire_date, :key_version,
                 :working_days, :working_mins, :absent_days, :absent_mins, :late_mins, :early_mins,
                 :ot_mins, :ot_req_hrs, :ot_req_working_day_hrs, :ot_req_weekend_hrs, :ot_req_holiday_hrs,
                 :leave_approve_days, :leave_wait_days, :leave_without_pay_days, :trip_allowance, :item_values,
                 :dept_id, :posi_id, :pass_pro, :pass_pro_date, :title, :gender, :date_birth, :nickname,
                 :nationality, :religion, :marital_status, :military_service, :emp_pic, :email, :emp_tel,
                 :support_team_id, :support_team_text,
                 :spouse_data, :children_data, :signature_drawing)");

        $unmappedCount = 0;
        foreach ($items as $item) {
            $payrollCode = (string)($item['payroll_code'] ?? '');
            $employeeId = $payrollCode !== '' ? $this->resolveEmployeeId($compId, $payrollCode) : null;
            $mappingStatus = $employeeId !== null ? 'mapped' : 'unmapped';
            if ($mappingStatus === 'unmapped') {
                $unmappedCount++;
            }
            $payType = in_array($item['pay_type'] ?? null, self::PAY_TYPES, true) ? $item['pay_type'] : null;
            // deduct_sso is a genuine JSON boolean on the wire (true/false/null) -- strict compare so
            // "not sent" and "explicitly false" don't collapse into the same stored value.
            $deductSso = array_key_exists('deduct_sso', $item) && $item['deduct_sso'] !== null
                ? ($item['deduct_sso'] ? 1 : 0) : null;
            // pass_pro is NOT a real boolean on the wire despite what it looks like (and despite the
            // API doc describing it as one) -- see parseYesNoFlag()'s own docblock for why a plain
            // truthy cast here was silently wrong (every non-empty string, including "N", is truthy
            // in PHP).
            $passPro = array_key_exists('pass_pro', $item) ? $this->parseYesNoFlag($item['pass_pro']) : null;
            $bankNoEnc = isset($item['pay_bank_no']) ? EncryptionService::encrypt((string)$item['pay_bank_no']) : null;
            $idCardEnc = isset($item['idcard']) ? EncryptionService::encrypt((string)$item['idcard']) : null;
            // spouse/children (2026-08-18 rev 2) carry nested PII (spouse_idcard, child_idcard,
            // spouse_tax, etc.) -- same protection as pay_bank_no/idcard above, just applied to the
            // whole JSON blob at once rather than field-by-field, since there's no per-field column
            // to encrypt individually. Sharing this row's single key_version column, same as the
            // other two encrypted fields.
            $spouseEnc = !empty($item['spouse']) ? EncryptionService::encrypt(json_encode($item['spouse'], JSON_UNESCAPED_UNICODE)) : null;
            $childrenEnc = !empty($item['children']) ? EncryptionService::encrypt(json_encode($item['children'], JSON_UNESCAPED_UNICODE)) : null;
            // signature_drawing (2026-08-27) is the actual signature IMAGE content, not a path like
            // emp_pic -- encrypted at rest same as pay_bank_no/idcard/spouse/children above, sharing
            // this row's single key_version column. Stored exactly as received (data: URI or bare
            // base64) -- decodeSignatureDrawing() does the prefix-stripping/MIME-sniffing later, at
            // apply time, not here.
            $signatureEnc = !empty($item['signature_drawing']) ? EncryptionService::encrypt((string)$item['signature_drawing']) : null;
            $stmt->execute([
                ':process_id' => $processRowId, ':employee_id' => $employeeId, ':payroll_code' => $payrollCode,
                ':emp_code' => $item['emp_code'] ?? null,
                // 2026-08-30 rev 2: items[].emp_name (PAYROLL_SYNC_API.md) -- display/verification
                // only, matching how payroll_sync_employee_status.emp_name is already handled;
                // trimmed to blank-string-becomes-null since an empty string and "not sent" should
                // read identically to every consumer (createPlaceholderEmployeesForUnmapped()'s own
                // fallback chain in particular).
                ':emp_name' => (isset($item['emp_name']) && trim((string)$item['emp_name']) !== '') ? trim((string)$item['emp_name']) : null,
                ':mapping_status' => $mappingStatus,
                ':report_item_id' => $item['report_item_id'] ?? null,
                ':dept_description' => $item['dept_description'] ?? null, ':position_name' => $item['position_name'] ?? null,
                ':branch_id' => $item['branch_id'] ?? null, ':branch_name' => $item['branch_name'] ?? null,
                ':shift_working_id' => $item['shift_working_id'] ?? null, ':shift_working_name' => $item['shift_working_name'] ?? null,
                ':pay_type' => $payType, ':pay_bank_id' => $item['pay_bank_id'] ?? null,
                ':pay_bank_code' => $item['pay_bank_code'] ?? null, ':pay_bank_name' => $item['pay_bank_name'] ?? null,
                ':pay_bank_no' => $bankNoEnc['value'] ?? null, ':deduct_sso' => $deductSso,
                ':id_card_no' => $idCardEnc['value'] ?? null,
                ':id_card_issue_date' => $item['idcard_issued'] ?? null,
                ':id_card_expire_date' => $item['idcard_expire'] ?? null,
                ':key_version' => $bankNoEnc['key_version'] ?? $idCardEnc['key_version'] ?? $spouseEnc['key_version'] ?? $childrenEnc['key_version'] ?? $signatureEnc['key_version'] ?? null,
                ':working_days' => $item['working_days'] ?? null, ':working_mins' => $item['working_mins'] ?? null,
                ':absent_days' => $item['absent_days'] ?? null, ':absent_mins' => $item['absent_mins'] ?? null,
                ':late_mins' => $item['late_mins'] ?? null, ':early_mins' => $item['early_mins'] ?? null,
                ':ot_mins' => $item['ot_mins'] ?? null, ':ot_req_hrs' => $item['ot_req_hrs'] ?? null,
                ':ot_req_working_day_hrs' => $item['ot_req_working_day_hrs'] ?? null,
                ':ot_req_weekend_hrs' => $item['ot_req_weekend_hrs'] ?? null, ':ot_req_holiday_hrs' => $item['ot_req_holiday_hrs'] ?? null,
                ':leave_approve_days' => $item['leave_approve_days'] ?? null, ':leave_wait_days' => $item['leave_wait_days'] ?? null,
                ':leave_without_pay_days' => $item['leave_without_pay_days'] ?? null, ':trip_allowance' => $item['trip_allowance'] ?? null,
                ':item_values' => isset($item['item_values']) ? json_encode($item['item_values'], JSON_UNESCAPED_UNICODE) : null,
                ':dept_id' => $item['dept_id'] ?? null, ':posi_id' => $item['posi_id'] ?? null,
                ':pass_pro' => $passPro, ':pass_pro_date' => $item['pass_pro_date'] ?? null,
                ':title' => $item['title'] ?? null, ':gender' => $item['gender'] ?? null,
                ':date_birth' => $item['date_birth'] ?? null, ':nickname' => $item['nickname'] ?? null,
                ':nationality' => $item['nationality'] ?? null, ':religion' => $item['religion'] ?? null,
                ':marital_status' => $item['marital_status'] ?? null, ':military_service' => $item['military_service'] ?? null,
                ':emp_pic' => $item['emp_pic'] ?? null, ':email' => $item['email'] ?? null, ':emp_tel' => $item['emp_tel'] ?? null,
                ':support_team_id' => $item['support_team_id'] ?? null, ':support_team_text' => $item['support_team_text'] ?? null,
                ':spouse_data' => $spouseEnc['value'] ?? null, ':children_data' => $childrenEnc['value'] ?? null,
                ':signature_drawing' => $signatureEnc['value'] ?? null,
            ]);
        }
        return $unmappedCount;
    }

    /**
     * 2026-08-31, same-day follow-up -- Origami's `scheduled_item_occurrences[]` proposal (a
     * per-installment breakdown of an Employee Item's summed items[].item_values[] amount, e.g.
     * "LOAN installment 2 of 12"). Confirmed additive, no PAYLOAD_SCHEMA_VERSION bump.
     *
     * `employee_id` resolution is a TWO-STEP lookup, not a direct one: Origami's own `emp_id` (the
     * field each occurrence row is keyed by) is never persisted anywhere on this app's side as its
     * own column -- replaceItems() above only ever resolves/stores `employee_id` via
     * `payroll_code` -- so this method builds its own `emp_id -> employee_id` map by scanning
     * `$items` (the SAME array replaceItems() just processed, still in scope here) for each item's
     * own `emp_id`/`payroll_code` pair, then resolves through the identical resolveEmployeeId()
     * every other mapping in this class uses. An occurrence whose `emp_id` doesn't match ANY
     * `items[].emp_id` in this same payload (should not happen per Origami's own description --
     * every occurrence belongs to an employee who also has an items[] entry in the same batch --
     * but defensively handled, not assumed) is stored with `employee_id = NULL`, same "can't
     * resolve, don't drop the row" convention replaceItems() itself uses for an unmapped
     * `payroll_code`.
     *
     * Same idempotent delete+reinsert pattern as replaceItems()/replaceEmployeeStatus() -- a
     * resubmit of the same process_id replaces this table's rows for it wholesale, not a
     * diff-and-patch.
     *
     * `applied_at` accepts EITHER a plain ISO-ish `YYYY-MM-DD HH:MM:SS` (matching every other
     * timestamp convention this API otherwise uses, e.g. run_kind/attribution's own dates) OR
     * Origami's first-proposed `YYYY/MM/DD HH:MM:SS` (slash-separated) -- flagged back to Origami
     * as an inconsistency worth fixing on their side, but parsed defensively here regardless so a
     * genuine, valid timestamp in either format is never silently dropped over pure formatting.
     * Same "never trust an external payload's shape blindly, degrade rather than throw" posture as
     * nullableDate()/normalizeRunKind() elsewhere in this class -- an unparseable value stores NULL
     * rather than rejecting the whole occurrence row (the amount/item_code/occurrence_code are the
     * load-bearing fields; a missing timestamp is a lesser, tolerable data-quality gap).
     */
    private function parseOccurrenceAppliedAt(mixed $value): ?string {
        if (empty($value) || !is_string($value)) {
            return null;
        }
        foreach (['Y-m-d H:i:s', 'Y/m/d H:i:s', 'Y-m-d', 'Y/m/d'] as $format) {
            $dt = DateTime::createFromFormat($format, $value);
            if ($dt !== false) {
                return $dt->format('Y-m-d H:i:s');
            }
        }
        return null;
    }

    private function replaceScheduledItemOccurrences(int $processRowId, int $compId, array $items, array $occurrences): void {
        $this->db->prepare("DELETE FROM `payroll_sync_item_occurrences` WHERE process_id = :process_id")
            ->execute([':process_id' => $processRowId]);
        if (empty($occurrences)) {
            return;
        }

        $employeeIdByOrigamiEmpId = [];
        foreach ($items as $item) {
            $origamiEmpId = $item['emp_id'] ?? null;
            $payrollCode = (string)($item['payroll_code'] ?? '');
            if ($origamiEmpId === null || $payrollCode === '') {
                continue;
            }
            $employeeIdByOrigamiEmpId[(string)$origamiEmpId] = $this->resolveEmployeeId($compId, $payrollCode);
        }

        $stmt = $this->db->prepare("INSERT INTO `payroll_sync_item_occurrences`
                (process_id, employee_id, origami_emp_id, item_code, item_ref_code, occurrence_code, installment_no, amount, applied_at)
            VALUES (:process_id, :employee_id, :origami_emp_id, :item_code, :item_ref_code, :occurrence_code, :installment_no, :amount, :applied_at)");
        foreach ($occurrences as $occ) {
            $itemCode = (string)($occ['item_code'] ?? '');
            if ($itemCode === '' || !isset($occ['amount']) || !is_numeric($occ['amount'])) {
                continue; // no meaningful row without at least an item_code + amount
            }
            $origamiEmpId = $occ['emp_id'] ?? null;
            $employeeId = $origamiEmpId !== null ? ($employeeIdByOrigamiEmpId[(string)$origamiEmpId] ?? null) : null;
            $stmt->execute([
                ':process_id' => $processRowId,
                ':employee_id' => $employeeId,
                ':origami_emp_id' => $origamiEmpId !== null && is_numeric($origamiEmpId) ? (int)$origamiEmpId : null,
                ':item_code' => $itemCode,
                ':item_ref_code' => !empty($occ['item_ref_code']) ? trim((string)$occ['item_ref_code']) : null,
                ':occurrence_code' => !empty($occ['occurrence_code']) ? trim((string)$occ['occurrence_code']) : null,
                ':installment_no' => isset($occ['installment_no']) && is_numeric($occ['installment_no']) ? (int)$occ['installment_no'] : null,
                ':amount' => round((float)$occ['amount'], 2),
                ':applied_at' => $this->parseOccurrenceAppliedAt($occ['applied_at'] ?? null),
            ]);
        }
    }

    /**
     * Read-only, for consumers wanting the occurrence breakdown of a specific item for a specific
     * employee within one Origami process (e.g. PayrollRunModel::syncDeductionLinesForEmployee()'s
     * own Adjust Amounts listing, or a payslip renderer) -- deliberately a plain per-(process,
     * employee, item_code) lookup, not tied to any payroll_runs row, since occurrences belong to
     * the Origami PROCESS, not to whichever run it eventually got pulled into.
     */
    public function occurrencesForItem(int $processRowId, int $employeeId, string $itemCode): array {
        $stmt = $this->db->prepare("SELECT item_ref_code, occurrence_code, installment_no, amount, applied_at
            FROM `payroll_sync_item_occurrences`
            WHERE process_id = :process_id AND employee_id = :employee_id AND item_code = :item_code
            ORDER BY installment_no ASC, id ASC");
        $stmt->execute([':process_id' => $processRowId, ':employee_id' => $employeeId, ':item_code' => $itemCode]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function replaceEmployeeStatus(int $processRowId, int $compId, array $statusRows): void {
        if (empty($statusRows)) {
            return;
        }
        $stmt = $this->db->prepare("INSERT INTO payroll_sync_employee_status
                (process_id, employee_id, payroll_code, emp_code, emp_name, dept_description, position_name,
                 emp_start_date, emp_resign_date, is_new_hire, is_resigned_this_period, status_text)
            VALUES
                (:process_id, :employee_id, :payroll_code, :emp_code, :emp_name, :dept_description, :position_name,
                 :emp_start_date, :emp_resign_date, :is_new_hire, :is_resigned_this_period, :status_text)");

        foreach ($statusRows as $row) {
            $payrollCode = (string)($row['payroll_code'] ?? '');
            $employeeId = $payrollCode !== '' ? $this->resolveEmployeeId($compId, $payrollCode) : null;
            $stmt->execute([
                ':process_id' => $processRowId, ':employee_id' => $employeeId, ':payroll_code' => $payrollCode,
                ':emp_code' => $row['emp_code'] ?? null, ':emp_name' => $row['emp_name'] ?? null,
                ':dept_description' => $row['dept_description'] ?? null, ':position_name' => $row['position_name'] ?? null,
                ':emp_start_date' => $row['emp_start_date'] ?? null, ':emp_resign_date' => $row['emp_resign_date'] ?? null,
                ':is_new_hire' => !empty($row['is_new_hire']) ? 1 : 0,
                ':is_resigned_this_period' => !empty($row['is_resigned_this_period']) ? 1 : 0,
                ':status_text' => $row['status_text'] ?? null,
            ]);
        }
    }

    /**
     * Re-resolves employee_id for whatever's still unmapped on a process -- called from the
     * "Pending Pull" flow (PayrollRunModel::create()) right after a fresh master-data sync, so an
     * employee who only just landed in `employees` (or whose employee_no was only just corrected)
     * becomes mapped without needing Origami to re-push the whole payload. payroll_code/emp_code/
     * attendance figures never change here -- only which employee row a row resolves to.
     * @return int number of rows newly resolved (was unmapped, now mapped)
     */
    public function remapUnmappedItems(int $processRowId, int $compId): int {
        $stmt = $this->db->prepare("SELECT id, payroll_code FROM payroll_sync_items WHERE process_id = :process_id AND mapping_status = 'unmapped'");
        $stmt->execute([':process_id' => $processRowId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return 0;
        }
        $update = $this->db->prepare("UPDATE payroll_sync_items SET employee_id = :employee_id, mapping_status = 'mapped' WHERE id = :id");
        $resolvedCount = 0;
        foreach ($rows as $row) {
            $employeeId = $this->resolveEmployeeId($compId, (string)$row['payroll_code']);
            if ($employeeId !== null) {
                $update->execute([':employee_id' => $employeeId, ':id' => $row['id']]);
                $resolvedCount++;
            }
        }
        if ($resolvedCount > 0) {
            $this->db->prepare("UPDATE payroll_sync_processes SET unmapped_item_count = unmapped_item_count - :n WHERE id = :id")
                ->execute([':n' => $resolvedCount, ':id' => $processRowId]);
        }
        return $resolvedCount;
    }

    /**
     * Auto-creates a minimal placeholder `employees` row for every payroll_sync_items row still
     * unmapped after remapUnmappedItems() -- per explicit request (2026-08-19): "Sync ข้อมูล
     * Employee" here means pulling the employee IN from whatever this already-received sync
     * payload knows about them (payroll_code + a name + emp_start_date), NOT calling out to the real
     * Origami HR API (that's MasterDataSyncOrchestrator/EmployeeSyncer, a separate, still-future
     * feature -- this method never touches it). Mirrors the exact placeholder-provisioning pattern
     * already established in auth/index.php for SSO first-login (is_payroll_ready=0, dummy-but-NOT-
     * NULL contact/emergency fields) with one difference: employee_no is set to payroll_code
     * verbatim rather than a generated code, because resolveEmployeeId() (and the rest of this class)
     * matches payroll_code against employees.employee_no directly -- a generated code would make the
     * row unmatchable forever. data_source='sync' (not 'manual' like the SSO placeholder) so it's
     * visibly distinguishable as sync-originated once HR fills in the rest via the normal Employee
     * edit form (which flips is_payroll_ready back to 1, same as any other placeholder profile in
     * this app).
     * Name source, 2026-08-30 rev 2: `payroll_sync_items.emp_name` (PAYROLL_SYNC_API.md's
     * items[].emp_name, sent on EVERY employee row every cycle) is tried FIRST, falling back to
     * `payroll_sync_employee_status.emp_name` (only ever covers new-hire/resigned-this-period rows --
     * the ORIGINAL, and until now only, source) when the items row itself doesn't have one. Before
     * this, an ordinary unmapped employee who wasn't flagged new-hire/resigned this period got no
     * name at all -- the payroll_code repeated as both first/last name, a genuinely bad placeholder.
     * @return int number of employees newly created (rows merely re-resolved to an
     *   already-existing employee via the race-safety fallback below do NOT count here)
     */
    public function createPlaceholderEmployeesForUnmapped(int $processRowId, int $compId, ?int $triggeredBy): int {
        $stmt = $this->db->prepare("SELECT id, payroll_code, emp_name FROM payroll_sync_items WHERE process_id = :process_id AND mapping_status = 'unmapped' AND employee_id IS NULL");
        $stmt->execute([':process_id' => $processRowId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return 0;
        }

        $statusStmt = $this->db->prepare("SELECT emp_name, emp_start_date FROM payroll_sync_employee_status WHERE process_id = :process_id AND payroll_code = :payroll_code LIMIT 1");
        // 2026-09-02, follow-up: payment_type (legacy enum) dropped -- payment_method_id resolved via
        // paymentMethodIdByCode('cash') below, same default this placeholder INSERT always used.
        $insertEmployee = $this->db->prepare(
            "INSERT INTO employees
                (comp_id, employee_no, data_source, is_payroll_ready, employee_type, employee_status,
                 title, gender, name_th, surname_th, name_en, surname_en,
                 date_of_birth, nationality, personal_email, mobile_no,
                 address_line_1_register, address_line_1_contact,
                 emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
                 employment_date, employment_status, employment_type, workforce_type, record_time_method,
                 payment_method_id, salary_type, salary_effective_date, tax_calculation_method)
             VALUES
                (:comp_id, :employee_no, 'sync', 0, 'domestic', 'active',
                 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en,
                 '1900-01-01', 'Unknown', :email, '0000000000',
                 'PENDING', 'PENDING',
                 'PENDING', 'PENDING', 'PENDING', '0000000000',
                 :employment_date, 'probation', 'full_time', 'office', 'none',
                 :payment_method_id, 'monthly', :employment_date, 'average')"
        );
        $placeholderCashMethodId = $this->paymentMethodIdByCode('cash');
        $mapItem = $this->db->prepare("UPDATE payroll_sync_items SET employee_id = :employee_id, mapping_status = 'mapped' WHERE id = :id");

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) { $this->db->beginTransaction(); }
        $createdCount = 0;
        $resolvedCount = 0;
        try {
            foreach ($rows as $row) {
                $payrollCode = trim((string)$row['payroll_code']);
                if ($payrollCode === '') {
                    continue;
                }

                // Race/idempotency safety: another row in this same batch (or a concurrent pull)
                // may have already created this employee_no since remapUnmappedItems() last ran.
                $existingId = $this->resolveEmployeeId($compId, $payrollCode);
                if ($existingId !== null) {
                    $mapItem->execute([':employee_id' => $existingId, ':id' => $row['id']]);
                    $resolvedCount++;
                    continue;
                }

                // employee_status[] is still the only source for emp_start_date (items[] carries no
                // equivalent) -- always looked up regardless of where the name itself comes from.
                $statusStmt->execute([':process_id' => $processRowId, ':payroll_code' => $payrollCode]);
                $status = $statusStmt->fetch(PDO::FETCH_ASSOC);
                // Name: prefer items[].emp_name (2026-08-30 rev 2, sent on every row every cycle)
                // over payroll_sync_employee_status.emp_name (only ever covers new-hire/resigned-
                // this-period rows -- the original, and until now only, source).
                $empName = trim((string)($row['emp_name'] ?? ''));
                if ($empName === '') {
                    $empName = trim((string)($status['emp_name'] ?? ''));
                }
                if ($empName !== '') {
                    $parts = preg_split('/\s+/', $empName, 2);
                    $firstName = $parts[0];
                    $lastName = $parts[1] ?? $parts[0];
                } else {
                    // No name from either source for this payroll_code -- still create the row so
                    // the pull isn't blocked, using the code itself as a visible "needs a real name"
                    // placeholder rather than leaving NOT NULL columns empty.
                    $firstName = $payrollCode;
                    $lastName = $payrollCode;
                }
                $empStartDate = $status['emp_start_date'] ?? null;
                $employmentDate = (is_string($empStartDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $empStartDate)) ? $empStartDate : date('Y-m-d');
                $placeholderEmail = 'sync-pending-' . strtolower(preg_replace('/[^A-Za-z0-9]/', '', $payrollCode)) . '-' . $processRowId . '@placeholder.local';

                try {
                    $insertEmployee->execute([
                        ':comp_id' => $compId, ':employee_no' => $payrollCode,
                        ':name_th' => $firstName, ':surname_th' => $lastName,
                        ':name_en' => $firstName, ':surname_en' => $lastName,
                        ':email' => $placeholderEmail, ':employment_date' => $employmentDate,
                        ':payment_method_id' => $placeholderCashMethodId,
                    ]);
                    $newEmployeeId = (int)$this->db->lastInsertId();
                    $mapItem->execute([':employee_id' => $newEmployeeId, ':id' => $row['id']]);
                    $createdCount++;
                    $resolvedCount++;
                } catch (PDOException $e) {
                    // Race: another concurrent pull already created this employee_no since the
                    // check above -- re-resolve and map to it rather than failing the whole batch
                    // (same per-row-failure-tolerant shape as the rest of this class).
                    $raceId = $this->resolveEmployeeId($compId, $payrollCode);
                    if ($raceId !== null) {
                        $mapItem->execute([':employee_id' => $raceId, ':id' => $row['id']]);
                        $resolvedCount++;
                    }
                }
            }
            if ($resolvedCount > 0) {
                $this->db->prepare("UPDATE payroll_sync_processes SET unmapped_item_count = unmapped_item_count - :n WHERE id = :id")
                    ->execute([':n' => $resolvedCount, ':id' => $processRowId]);
            }
            if ($ownTransaction) { $this->db->commit(); }
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
        return $createdCount;
    }

    /**
     * Overwrites payment/SSO/ID-card master data on `employees` from every currently-mapped row
     * of a process -- see the class docblock for why this is treated as source-of-truth (overwrite
     * every pull) rather than the usual "payroll-owned, default-once" rule.
     * @return int number of employees written to
     */
    public function applyEmployeeMasterFields(int $processRowId, int $compId, ?int $triggeredBy): int {
        $stmt = $this->db->prepare("SELECT employee_id, pay_type, pay_bank_code, pay_bank_name, pay_bank_no, deduct_sso,
                id_card_no, id_card_issue_date, id_card_expire_date, key_version,
                dept_id, dept_description, posi_id, position_name,
                title, gender, date_birth, nickname, nationality, religion, marital_status, email, emp_tel,
                pass_pro, pass_pro_date, support_team_id, support_team_text, signature_drawing,
                spouse_data, children_data
            FROM payroll_sync_items WHERE process_id = :process_id AND mapping_status = 'mapped' AND employee_id IS NOT NULL");
        $stmt->execute([':process_id' => $processRowId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $updated = 0;
        foreach ($rows as $row) {
            if ($this->applyOneEmployeeMasterFields($compId, (int)$row['employee_id'], $row, $triggeredBy)) {
                $updated++;
            }
        }
        return $updated;
    }

    /**
     * `item_master` (top-level array, PAYROLL_SYNC_API.md's 2026-08-30 revision) -- the distinct
     * list of income/deduction/info items this specific process has turned on, independent of
     * whether anyone actually carries a value for it this cycle. Per explicit request ("พวกรายได้
     * รายหักเรื่อง item เงินได้เงินหักถ้ารายการไหนยังไม่มีให้ insert auto ไปได้เลยไหม"), auto-creates a
     * `payroll_earning_deduction_types` row for any item_code this company doesn't already have one
     * for -- so a brand-new custom item (ASSISTANCE/PHONE_ALLOWANCE/LOAN/STUDENT_LOAN/...)
     * shows up in Payroll Configuration > Earning-Deduction Types ready to review/adjust (tax
     * treatment, SSO/PF, statutory report tag), instead of silently landing every pull as an
     * anonymous "CUSTOM:<name>" line (SyncPayResolver::resolve()'s own generic item_values fallback)
     * with no tax categorization and nothing an admin can configure.
     *
     * Deliberately SKIPS any item_code SyncPayResolver::isKnownEventItemCode() recognizes (OT, trip
     * allowance/"ROUND", late, absent, early leave, unpaid leave, leave pending, and -- as of
     * 2026-08-30's Phase 2 T011 -- diligence/"DILIGENCE" too, promoted out of the generic-item
     * fallback into its own KNOWN_ITEM_DEFS entry the same day) -- see that
     * method's own docblock for why an item_code-matched row for one of those would be an inert
     * decoy. Also skips item_type='INFO' (LEAVE_APPROVED/PROBATION_WORKING_DAYS) --
     * `payroll_earning_deduction_types.item_type` is a hard enum('earning','deduction') and an INFO
     * item is designed to never produce a payroll line at all (see SyncPayResolver::resolve()'s own
     * INFO-type handling), so there's nothing for a catalog row to configure.
     *
     * Read from `payroll_sync_processes.raw_payload` (the verbatim original JSON, already stored at
     * ingest time) rather than a new dedicated column -- item_master has no other use anywhere in
     * this table, so a full column would be unused storage for what's only ever a one-time read at
     * pull time. Called from the SAME "Pending Pull" moment as remapUnmappedItems()/
     * applyEmployeeMasterFields() (see PayrollRunModel::create()), so a brand-new item's catalog row
     * already exists by the time recalculate() runs for the very first time on this run.
     *
     * `is_sync_only = 1` on every auto-created row -- the per-employee amount for these is always
     * driven externally by Origami's own item_values[] each cycle, never something a payroll admin
     * assigns locally (there's no per-employee manual-assignment path for a generic synced item),
     * same "system-managed, not manually editable" precedent trip allowance's own seeded row already
     * established (see PayrollEarningDeductionTypeModel::save()/delete()'s own is_sync_only guard).
     * tax_treatment/tax_deduction_impact default to the exact same values these items already got
     * BEFORE this row existed (taxable earning / before-tax deduction -- PayrollRunModel::
     * recalculate()'s own tax-flag lookup treats "no matching catalog row" the same way), so creating
     * the row is purely additive visibility/manageability, not a silent calculation change for any
     * pull that already happened before this feature existed.
     * @return int number of new catalog rows created
     */
    public function autoCreateMissingPedTypes(int $processRowId, int $compId, ?int $triggeredBy): int {
        $stmt = $this->db->prepare("SELECT raw_payload FROM payroll_sync_processes WHERE id = :id AND comp_id = :comp_id");
        $stmt->execute([':id' => $processRowId, ':comp_id' => $compId]);
        $rawPayload = $stmt->fetchColumn();
        if ($rawPayload === false) {
            return 0;
        }
        $payload = json_decode((string)$rawPayload, true);
        $itemMaster = is_array($payload['item_master'] ?? null) ? $payload['item_master'] : [];
        if (empty($itemMaster)) {
            return 0;
        }

        $stmtExisting = $this->db->prepare("SELECT item_code FROM `payroll_earning_deduction_types` WHERE comp_id = :comp_id");
        $stmtExisting->execute([':comp_id' => $compId]);
        $existingCodes = array_map('strtoupper', array_column($stmtExisting->fetchAll(PDO::FETCH_ASSOC), 'item_code'));

        $insertStmt = $this->db->prepare("INSERT INTO `payroll_earning_deduction_types`
                (comp_id, item_code, item_name_th, item_name_en, item_type, calculation_method,
                 tax_treatment, tax_deduction_impact, calc_sso, calc_pf, is_sync_only, status, created_by)
            VALUES (:comp_id, :item_code, :item_name_th, :item_name_en, :item_type, 'manual_entry',
                 :tax_treatment, :tax_deduction_impact, 0, 0, 1, 'active', :created_by)");

        $created = 0;
        foreach ($itemMaster as $item) {
            $itemCode = trim((string)($item['item_code'] ?? ''));
            $itemName = trim((string)($item['item_name'] ?? ''));
            $itemTypeRaw = strtoupper((string)($item['item_type'] ?? ''));
            if ($itemCode === '' || $itemName === '' || !in_array($itemTypeRaw, ['INCOME', 'DEDUCTION'], true)) {
                continue; // INFO-typed or malformed entries never get a catalog row -- see docblock.
            }
            if (SyncPayResolver::isKnownEventItemCode($itemCode)) {
                continue; // already fully functional without one -- see docblock.
            }
            if (in_array(strtoupper($itemCode), $existingCodes, true)) {
                continue; // this company already has a row for it (active, inactive, or soft-deleted).
            }
            $itemType = $itemTypeRaw === 'INCOME' ? 'earning' : 'deduction';
            try {
                $insertStmt->execute([
                    ':comp_id' => $compId, ':item_code' => $itemCode, ':item_name_th' => $itemName, ':item_name_en' => $itemName,
                    ':item_type' => $itemType, ':tax_treatment' => $itemType === 'earning' ? 'taxable' : null,
                    ':tax_deduction_impact' => $itemType === 'deduction' ? 'before_tax' : null,
                    ':created_by' => $triggeredBy,
                ]);
                $existingCodes[] = strtoupper($itemCode); // guards a duplicate item_code appearing twice in the same item_master array.
                $created++;
            } catch (PDOException $e) {
                // Race with a concurrent pull, or a genuine constraint clash -- skip, don't fail the whole pull over one item.
            }
        }
        return $created;
    }

    /**
     * Resolve-or-create for department/position/bank (per explicit request, 2026-08-19: "if it
     * doesn't exist yet, create it; if it already exists, just fetch its ID and use it" --
     * department, position, AND bank all get the same treatment). Department/position reuse the
     * exact origami_ref_id + data_source='sync' columns already built for the real Origami HR API
     * sync (MasterDataSyncOrchestrator/DepartmentSyncer/PositionSyncer, see AbstractMasterDataSyncer)
     * -- items[].dept_id/.posi_id (added to the doc 2026-08-18 rev 2) line up with that column
     * directly. This is deliberately NOT a call into DepartmentSyncer/PositionSyncer::sync() itself
     * -- that does a full fetch-and-reconcile (including deactivating anything missing from the
     * fetch), wrong for touching one single department/position mentioned on one sync row. Matches
     * by origami_ref_id first (when the payload sent an id), falls back to an exact name match
     * (structure_departments/positions have no natural code equivalent to key a sync row on), and
     * only creates a new row when neither resolves -- a generated code (since department_code/
     * position_code are NOT NULL with nothing equivalent on the wire) using the origami id when
     * available, else a hash of the name, so a re-pull of the same unmatched name/id is idempotent
     * (matches its own previously-created row via origami_ref_id or name, not a fresh row every time).
     */
    private function resolveOrCreateDepartmentId(int $compId, ?int $origamiDeptId, ?string $deptName, ?int $triggeredBy): ?int {
        $deptName = $deptName !== null ? trim($deptName) : null;
        if ($origamiDeptId === null && ($deptName === null || $deptName === '')) {
            return null;
        }
        if ($origamiDeptId !== null) {
            $stmt = $this->db->prepare("SELECT id FROM structure_departments WHERE origami_ref_id = :ref AND comp_id = :comp AND deleted_at IS NULL");
            $stmt->execute([':ref' => $origamiDeptId, ':comp' => $compId]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int)$id;
            }
        }
        if ($deptName !== null && $deptName !== '') {
            $stmt = $this->db->prepare("SELECT id FROM structure_departments WHERE comp_id = :comp AND deleted_at IS NULL AND status != 'deleted' AND (department_name_th = :name OR department_name_en = :name) LIMIT 1");
            $stmt->execute([':comp' => $compId, ':name' => $deptName]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int)$id;
            }
        }
        if ($deptName === null || $deptName === '') {
            // Nothing to label a brand-new department with -- an id alone (no dept_description
            // sent) isn't enough to create a meaningful row.
            return null;
        }
        $code = 'SYNC-' . ($origamiDeptId !== null ? (string)$origamiDeptId : strtoupper(substr(md5($deptName), 0, 8)));
        try {
            $ins = $this->db->prepare("INSERT INTO structure_departments (comp_id, department_code, department_name_th, department_name_en, status, origami_ref_id, data_source, created_by)
                VALUES (:comp_id, :code, :name, :name, 'active', :ref_id, 'sync', :created_by)");
            $ins->execute([':comp_id' => $compId, ':code' => $code, ':name' => $deptName, ':ref_id' => $origamiDeptId, ':created_by' => $triggeredBy]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            // Race: another concurrent pull already created this department -- re-resolve.
            if ($origamiDeptId !== null) {
                $stmt = $this->db->prepare("SELECT id FROM structure_departments WHERE origami_ref_id = :ref AND comp_id = :comp AND deleted_at IS NULL");
                $stmt->execute([':ref' => $origamiDeptId, ':comp' => $compId]);
                $id = $stmt->fetchColumn();
                if ($id !== false) { return (int)$id; }
            }
            $stmt = $this->db->prepare("SELECT id FROM structure_departments WHERE comp_id = :comp AND department_code = :code AND deleted_at IS NULL");
            $stmt->execute([':comp' => $compId, ':code' => $code]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int)$id : null;
        }
    }

    private function resolveOrCreatePositionId(int $compId, ?int $origamiPosiId, ?string $positionName, ?int $triggeredBy): ?int {
        $positionName = $positionName !== null ? trim($positionName) : null;
        if ($origamiPosiId === null && ($positionName === null || $positionName === '')) {
            return null;
        }
        if ($origamiPosiId !== null) {
            $stmt = $this->db->prepare("SELECT id FROM structure_positions WHERE origami_ref_id = :ref AND comp_id = :comp AND deleted_at IS NULL");
            $stmt->execute([':ref' => $origamiPosiId, ':comp' => $compId]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int)$id;
            }
        }
        if ($positionName !== null && $positionName !== '') {
            $stmt = $this->db->prepare("SELECT id FROM structure_positions WHERE comp_id = :comp AND deleted_at IS NULL AND status != 'deleted' AND (position_name_th = :name OR position_name_en = :name) LIMIT 1");
            $stmt->execute([':comp' => $compId, ':name' => $positionName]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int)$id;
            }
        }
        if ($positionName === null || $positionName === '') {
            return null;
        }
        $code = 'SYNC-' . ($origamiPosiId !== null ? (string)$origamiPosiId : strtoupper(substr(md5($positionName), 0, 8)));
        try {
            $ins = $this->db->prepare("INSERT INTO structure_positions (comp_id, position_code, position_name_th, position_name_en, status, origami_ref_id, data_source, created_by)
                VALUES (:comp_id, :code, :name, :name, 'active', :ref_id, 'sync', :created_by)");
            $ins->execute([':comp_id' => $compId, ':code' => $code, ':name' => $positionName, ':ref_id' => $origamiPosiId, ':created_by' => $triggeredBy]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            if ($origamiPosiId !== null) {
                $stmt = $this->db->prepare("SELECT id FROM structure_positions WHERE origami_ref_id = :ref AND comp_id = :comp AND deleted_at IS NULL");
                $stmt->execute([':ref' => $origamiPosiId, ':comp' => $compId]);
                $id = $stmt->fetchColumn();
                if ($id !== false) { return (int)$id; }
            }
            $stmt = $this->db->prepare("SELECT id FROM structure_positions WHERE comp_id = :comp AND position_code = :code AND deleted_at IS NULL");
            $stmt->execute([':comp' => $compId, ':code' => $code]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int)$id : null;
        }
    }

    /**
     * Same resolve-or-create treatment as department/position above (2026-08-27, explicit request:
     * "ตอนบันทึกข้อมูลพนักงาน ให้ไปบันทึกในตารางทีม และ Assign ให้พนักงาน Auto"), against `structure_teams`
     * -- items[].support_team_id/.support_team_text line up with that table's own origami_ref_id/
     * team_name_th/team_name_en columns. Both name columns are set to the same incoming text on
     * create (no separate TH/EN source on the wire, same fallback already used for nickname_th/en
     * above) -- `client_name` is deliberately left NULL, since Origami's payload has no equivalent
     * field to populate it from and it's a free-text, manually-curated concept (which client/project
     * this team is deployed to) that a sync pull has no basis to guess at.
     */
    private function resolveOrCreateTeamId(int $compId, ?int $origamiTeamId, ?string $teamName, ?int $triggeredBy): ?int {
        $teamName = $teamName !== null ? trim($teamName) : null;
        if ($origamiTeamId === null && ($teamName === null || $teamName === '')) {
            return null;
        }
        if ($origamiTeamId !== null) {
            $stmt = $this->db->prepare("SELECT id FROM structure_teams WHERE origami_ref_id = :ref AND comp_id = :comp AND deleted_at IS NULL");
            $stmt->execute([':ref' => $origamiTeamId, ':comp' => $compId]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int)$id;
            }
        }
        if ($teamName !== null && $teamName !== '') {
            $stmt = $this->db->prepare("SELECT id FROM structure_teams WHERE comp_id = :comp AND deleted_at IS NULL AND status != 'deleted' AND (team_name_th = :name OR team_name_en = :name) LIMIT 1");
            $stmt->execute([':comp' => $compId, ':name' => $teamName]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int)$id;
            }
        }
        if ($teamName === null || $teamName === '') {
            // Nothing to label a brand-new team with -- an id alone (no support_team_text sent)
            // isn't enough to create a meaningful row.
            return null;
        }
        $code = 'SYNC-' . ($origamiTeamId !== null ? (string)$origamiTeamId : strtoupper(substr(md5($teamName), 0, 8)));
        try {
            $ins = $this->db->prepare("INSERT INTO structure_teams (comp_id, team_code, team_name_th, team_name_en, status, origami_ref_id, data_source, created_by)
                VALUES (:comp_id, :code, :name, :name, 'active', :ref_id, 'sync', :created_by)");
            $ins->execute([':comp_id' => $compId, ':code' => $code, ':name' => $teamName, ':ref_id' => $origamiTeamId, ':created_by' => $triggeredBy]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            // Race: another concurrent pull already created this team -- re-resolve.
            if ($origamiTeamId !== null) {
                $stmt = $this->db->prepare("SELECT id FROM structure_teams WHERE origami_ref_id = :ref AND comp_id = :comp AND deleted_at IS NULL");
                $stmt->execute([':ref' => $origamiTeamId, ':comp' => $compId]);
                $id = $stmt->fetchColumn();
                if ($id !== false) { return (int)$id; }
            }
            $stmt = $this->db->prepare("SELECT id FROM structure_teams WHERE comp_id = :comp AND team_code = :code AND deleted_at IS NULL");
            $stmt->execute([':comp' => $compId, ':code' => $code]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int)$id : null;
        }
    }

    /**
     * Decodes items[].signature_drawing (already decrypted by the caller) into real image bytes --
     * strips an optional `data:image/...;base64,` prefix per the doc's own field note ("expect either
     * shape"), then MIME-sniffs the decoded bytes against the exact same allowlist
     * EmployeeController::uploadSignature() uses for a live-drawn/uploaded signature (jpg/png/svg),
     * so a sync-provided signature ends up validated exactly as strictly as a manual one. Returns
     * null on anything malformed/unrecognized -- signature handling never fails the whole pull, it
     * just leaves the employee's existing signature_path untouched (same "don't guess, don't block
     * on one bad field" stance as the rest of this class).
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
     * Same resolve-or-create treatment as department/position above, but against `master_banks` --
     * a GLOBAL registry (no comp_id column), not per-company, so a bank created here becomes visible
     * to every company, which is correct for a real bank (its existence doesn't depend on which
     * company reported it first). country_code is taken from the pulling company's own
     * registered_country (same source Holiday/Payslip Template already use for their own
     * country_code) since the sync payload carries no country of its own for the bank. `bank_code`
     * is NOT NULL + UNIQUE with country_code -- when the payload sent one, it's used as-is (a real
     * bank code should match the official registry); when absent, a deterministic code is generated
     * from the bank name so a repeat pull with the same name resolves back to the same generated
     * code (and, either way, the name-match lookup above would already have found it on a repeat
     * pull regardless of the code).
     */
    private function resolveOrCreateBankId(int $compId, ?string $bankCode, ?string $bankName, ?int $triggeredBy): ?int {
        if ($bankCode !== null && $bankCode !== '') {
            $stmt = $this->db->prepare("SELECT id FROM master_banks WHERE LOWER(bank_code) = LOWER(:code) LIMIT 1");
            $stmt->execute([':code' => $bankCode]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int)$id;
            }
        }
        if ($bankName !== null && $bankName !== '') {
            $stmt = $this->db->prepare("SELECT id FROM master_banks WHERE bank_name_en = :name OR bank_name_th = :name LIMIT 1");
            $stmt->execute([':name' => $bankName]);
            $id = $stmt->fetchColumn();
            if ($id !== false) {
                return (int)$id;
            }
        }
        if (($bankCode === null || $bankCode === '') && ($bankName === null || $bankName === '')) {
            // Nothing to key or label a new bank with at all.
            return null;
        }
        $countryStmt = $this->db->prepare("SELECT registered_country FROM companies WHERE id = :id");
        $countryStmt->execute([':id' => $compId]);
        $countryCode = (string)($countryStmt->fetchColumn() ?: 'TH');
        $finalCode = ($bankCode !== null && $bankCode !== '') ? $bankCode : ('SYNC-' . strtoupper(substr(md5((string)$bankName), 0, 8)));
        $label = ($bankName !== null && $bankName !== '') ? $bankName : $finalCode;
        try {
            $ins = $this->db->prepare("INSERT INTO master_banks (bank_code, bank_name_th, bank_name_en, country_code, is_active)
                VALUES (:code, :name, :name, :country, 1)");
            $ins->execute([':code' => $finalCode, ':name' => $label, ':country' => $countryCode]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            // Race: another concurrent pull (possibly for a different company) already created
            // this exact bank_code/country_code combination -- re-resolve rather than fail the pull.
            $stmt = $this->db->prepare("SELECT id FROM master_banks WHERE LOWER(bank_code) = LOWER(:code) AND country_code = :country LIMIT 1");
            $stmt->execute([':code' => $finalCode, ':country' => $countryCode]);
            $id = $stmt->fetchColumn();
            return $id !== false ? (int)$id : null;
        }
    }

    /**
     * items[].title/.gender/.marital_status are raw legacy values on the wire -- "sometimes
     * human-readable text, sometimes an internal numeric code depending on when/how the row was
     * entered" per the doc's own field notes, with no glossary given for what the numeric codes
     * mean. Only recognized TEXT variants are mapped to this app's own enum values below;
     * numeric-only or unrecognized values are deliberately left unmapped (existing value on
     * `employees` stays untouched) rather than guessed at -- same "don't write what isn't verified"
     * stance as the DRAFT statutory exporters elsewhere in this app. military_service has no
     * equivalent normalization at all (see class docblock) -- intentionally never mapped.
     */
    /**
     * Resolves an incoming display name (e.g. "Thai") to this app's own master_nationalities
     * nationality_code (e.g. "TH") -- see the class docblock's 2026-08-19-revision note for why a
     * raw passthrough is wrong here. Matches nationality_name_en first (what Origami's payload
     * actually sends per its own field notes), nationality_name_th as a fallback in case that ever
     * changes, both case-insensitive. Returns null on no match -- resolve-only, never creates a new
     * row (a fixed global country list, unlike resolveOrCreateBankId()'s per-company banks).
     */
    private function resolveNationalityCode(int|string|null $raw): ?string {
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
     * 2026-09-02: Origami's own fixed religion code table (PAYROLL_SYNC_API.md, confirmed by
     * reading that doc directly, not guessed) -- `m_employee_info.religion` (0-6) is mapped to one
     * of these exact display names before being sent: 0->"Irreligious", 1->"Islam",
     * 2->"Christian", 3->"Hindu", 4->"Buddha", 5->"Judah", 6->"Paganism". 3 of the 7
     * ("Islam"/"Christian"/"Hindu") already match this app's own master_religions.religion_name_en
     * exactly, so resolveReligionCode() below finds those via the same direct-match query
     * resolveNationalityCode() uses; only the remaining 4 need an explicit alias here. "Paganism"
     * has no clean equivalent in this app's fixed religion list -- mapped to the generic "Other"
     * bucket as the best-effort catch-all. No Origami code maps to this app's own "Sikh" row --
     * stays reachable via manual entry only, same as before this fix.
     */
    private const RELIGION_NAME_ALIASES = [
        'irreligious' => 'NON',
        'buddha' => 'BUD',
        'judah' => 'JEW',
        'paganism' => 'OTH',
    ];

    /**
     * Resolves an incoming display name (e.g. "Buddha", Origami's own wire value) to this app's own
     * master_religions.religion_code (e.g. "BUD") -- same "don't write what isn't verified" stance
     * as resolveNationalityCode() right above, which this mirrors. Tries a direct case-insensitive
     * match against religion_name_en/_th first (catches the 3 names that already line up), then
     * falls back to RELIGION_NAME_ALIASES above for the 4 that don't. Returns null on no match --
     * resolve-only, never creates a new row (a fixed list, not something a sync pull should mint
     * new rows into).
     */
    private function resolveReligionCode(?string $raw): ?string {
        $name = trim((string)$raw);
        if ($name === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT religion_code FROM master_religions
            WHERE LOWER(religion_name_en) = LOWER(:name) OR LOWER(religion_name_th) = LOWER(:name) LIMIT 1");
        $stmt->execute([':name' => $name]);
        $code = $stmt->fetchColumn();
        if ($code !== false) {
            return (string)$code;
        }
        return self::RELIGION_NAME_ALIASES[strtolower($name)] ?? null;
    }

    /**
     * items[].pass_pro is documented (PAYROLL_SYNC_API.md) as a genuine JSON boolean, but the real
     * wire value is the string "Y"/"N" (2026-08-19, explicit correction from the business side) --
     * a plain `$raw ? 1 : 0` truthy cast was silently WRONG for this: every non-empty PHP string,
     * including "N", is truthy, so the old code stored a "not yet passed" employee as pass_pro=1
     * (passed). Also accepts a genuine boolean or "1"/"0" so this keeps working unchanged if the
     * sending side is ever corrected to send real JSON booleans as the doc claims. Anything else
     * unrecognized -> null (don't guess), same "don't map what isn't verified" stance already used
     * for normalizeTitle()/normalizeGender()/normalizeMaritalStatus() below.
     */
    private function parseYesNoFlag($raw): ?int {
        if ($raw === null) {
            return null;
        }
        if (is_bool($raw)) {
            return $raw ? 1 : 0;
        }
        $val = strtoupper(trim((string)$raw));
        if (in_array($val, ['Y', 'YES', 'TRUE', '1'], true)) {
            return 1;
        }
        if (in_array($val, ['N', 'NO', 'FALSE', '0'], true)) {
            return 0;
        }
        return null;
    }

    /**
     * Derives the 3-state probation status a human actually cares about from the stored pass_pro +
     * pass_pro_date pair, per explicit business rule (2026-08-19): pass_pro=N with pass_pro_date
     * still blank means probation is still in progress (not evaluated yet); pass_pro=N WITH a
     * pass_pro_date means it WAS evaluated and the employee did not pass -- that date is an
     * evaluation/failure date here, not a "passed" date despite the column's name; pass_pro=Y means
     * passed (pass_pro_date is then the pass date). Returns null when pass_pro itself is null (never
     * sent/not on file for this employee) -- nothing to derive. Used by getProcessDetail() for
     * display AND by applyOneEmployeeMasterFields() to drive the probation -> permanent
     * auto-transition (2026-08-19, explicit request) -- see this class's own docblock for the full
     * guard conditions (only probation -> permanent, never any other status).
     */
    private function deriveProbationStatus(?int $passPro, ?string $passProDate): ?string {
        if ($passPro === null) {
            return null;
        }
        if ($passPro === 1) {
            return 'passed';
        }
        return !empty($passProDate) ? 'failed' : 'on_probation';
    }

    private function normalizeTitle(int|string|null $raw): ?string {
        $map = ['mr' => 'mr', 'mr.' => 'mr', 'mister' => 'mr', 'mrs' => 'mrs', 'mrs.' => 'mrs', 'ms' => 'ms', 'ms.' => 'ms', 'miss' => 'ms'];
        $key = strtolower(trim((string)$raw));
        return $map[$key] ?? null;
    }

    private function normalizeGender(int|string|null $raw): ?string {
        $map = ['m' => 'male', 'male' => 'male', 'f' => 'female', 'female' => 'female'];
        $key = strtolower(trim((string)$raw));
        return $map[$key] ?? null;
    }

    private function normalizeMaritalStatus(int|string|null $raw): ?string {
        $map = ['single' => 'single', 'married' => 'married', 'divorced' => 'divorced', 'widowed' => 'widowed', 'widow' => 'widowed'];
        $key = strtolower(trim((string)$raw));
        return $map[$key] ?? null;
    }

    private function applyOneEmployeeMasterFields(int $compId, int $employeeId, array $row, ?int $triggeredBy): bool {
        $stmt = $this->db->prepare("SELECT id_card_no, tax_id_no, passport_no, bank_account_no, sso_no, spouse_id_card_no, key_version, employment_status, signature_path,
                employment_date, sso_enrolled, sso_start_date
            FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            return false;
        }
        $existingKeyVersion = isset($current['key_version']) ? (int)$current['key_version'] : null;
        $plain = [
            'id_card_no' => EncryptionService::decrypt($current['id_card_no'], $existingKeyVersion),
            'tax_id_no' => EncryptionService::decrypt($current['tax_id_no'], $existingKeyVersion),
            'passport_no' => EncryptionService::decrypt($current['passport_no'], $existingKeyVersion),
            'bank_account_no' => EncryptionService::decrypt($current['bank_account_no'], $existingKeyVersion),
            'sso_no' => EncryptionService::decrypt($current['sso_no'], $existingKeyVersion),
            'spouse_id_card_no' => EncryptionService::decrypt($current['spouse_id_card_no'], $existingKeyVersion),
        ];

        $set = [];
        $params = [':id' => $employeeId, ':comp_id' => $compId];
        $touchedEncrypted = false;

        $sourceKeyVersion = isset($row['key_version']) ? (int)$row['key_version'] : null;
        $payType = $row['pay_type'] ?? null;
        if ($payType !== null) {
            // 2026-09-02, follow-up: payment_type (legacy enum) dropped -- writes payment_method_id
            // instead now, resolved via the same 'transfer'/'cash' code mapping this sync payload's
            // own pay_type value always used.
            $resolvedSyncMethodId = $this->paymentMethodIdByCode($payType === 'transfer' ? 'transfer' : 'cash');
            if ($resolvedSyncMethodId !== null) {
                $set[] = "payment_method_id = :payment_method_id";
                $params[':payment_method_id'] = $resolvedSyncMethodId;
            }
            if ($payType === 'transfer' && !empty($row['pay_bank_no'])) {
                $plain['bank_account_no'] = EncryptionService::decrypt($row['pay_bank_no'], $sourceKeyVersion);
                $set[] = "bank_id = :bank_id";
                $params[':bank_id'] = $this->resolveOrCreateBankId($compId, $row['pay_bank_code'] ?? null, $row['pay_bank_name'] ?? null, $triggeredBy);
            } else {
                $plain['bank_account_no'] = null;
                $set[] = "bank_id = NULL";
            }
            $touchedEncrypted = true;
        }

        if (array_key_exists('deduct_sso', $row) && $row['deduct_sso'] !== null) {
            $set[] = "sso_enrolled = :sso_enrolled";
            $params[':sso_enrolled'] = (int)$row['deduct_sso'];
        }

        // SSO Start Date default (2026-08-30, explicit request: "วันที่เริ่มประกันสังคมถ้าเป็นค่าว่างให้
        // Default เป็นวันที่เริ่มงานลงไปเลย") -- Origami's payload has no SSO-start-date field of its own
        // (out of scope per PAYROLL_SYNC_API.md's own "Out of scope" section -- only the deduct/
        // don't-deduct flag itself is sent), so `employees.sso_start_date` stays entirely
        // receiver-owned. This only fills it in when it's genuinely still blank, using the
        // employee's own on-file employment_date as the sensible default -- never overwrites a
        // value HR already entered by hand, and never fires for an employee who isn't (or won't be,
        // after this pull) SSO-enrolled at all.
        $ssoEnrolledAfterThisPull = array_key_exists('deduct_sso', $row) && $row['deduct_sso'] !== null
            ? (bool)$row['deduct_sso']
            : !empty($current['sso_enrolled']);
        if ($ssoEnrolledAfterThisPull && empty($current['sso_start_date']) && !empty($current['employment_date'])) {
            $set[] = "sso_start_date = :sso_start_date";
            $params[':sso_start_date'] = $current['employment_date'];
        }

        if (!empty($row['id_card_no'])) {
            $plain['id_card_no'] = EncryptionService::decrypt($row['id_card_no'], $sourceKeyVersion);
            // SSO number (2026-08-29 explicit request): Origami's sync payload carries no separate
            // SSO-number field at all -- Thai law has equated the national ID card number with the
            // Social Security number since ~2011, so default sso_no to the same value whenever this
            // pull refreshes id_card_no. Same "this pull is the source of truth, overwrite every
            // time" policy already applied to bank_account_no/spouse_id_card_no elsewhere in this
            // method (see class docblock) -- not a fill-only-if-currently-empty default.
            $plain['sso_no'] = $plain['id_card_no'];
            $touchedEncrypted = true;
        }
        if (!empty($row['id_card_issue_date'])) {
            $set[] = "id_card_issue_date = :id_card_issue_date";
            $params[':id_card_issue_date'] = $row['id_card_issue_date'];
        }
        if (!empty($row['id_card_expire_date'])) {
            $set[] = "id_card_expire_date = :id_card_expire_date";
            $params[':id_card_expire_date'] = $row['id_card_expire_date'];
        }

        // Department/Position (2026-08-19 request): resolve against this row's dept_id/posi_id +
        // dept_description/position_name, creating a new structure_departments/positions row when
        // neither the id nor the name matches anything already there. Only touches department_id/
        // position_id when the sync row actually carries something to resolve -- an employee with
        // neither stays untouched rather than being nulled out.
        $deptId = $this->resolveOrCreateDepartmentId($compId, isset($row['dept_id']) ? (int)$row['dept_id'] : null, $row['dept_description'] ?? null, $triggeredBy);
        if ($deptId !== null) {
            $set[] = "department_id = :department_id";
            $params[':department_id'] = $deptId;
        }
        $posiId = $this->resolveOrCreatePositionId($compId, isset($row['posi_id']) ? (int)$row['posi_id'] : null, $row['position_name'] ?? null, $triggeredBy);
        if ($posiId !== null) {
            $set[] = "position_id = :position_id";
            $params[':position_id'] = $posiId;
        }

        // Support Team (2026-08-27 request): same resolve-or-create + auto-assign treatment as
        // Department/Position above -- see resolveOrCreateTeamId()'s own docblock.
        $teamId = $this->resolveOrCreateTeamId($compId, isset($row['support_team_id']) ? (int)$row['support_team_id'] : null, $row['support_team_text'] ?? null, $triggeredBy);
        if ($teamId !== null) {
            $set[] = "team_id = :team_id";
            $params[':team_id'] = $teamId;
        }

        // Personal profile fields (2026-08-18 rev 2) -- title/gender/marital_status only written
        // when they normalize to a recognized value (see normalizeTitle()/normalizeGender()/
        // normalizeMaritalStatus() docblock for why raw/numeric-coded values are skipped rather
        // than guessed). date_birth/nationality/religion/email/emp_tel have no such ambiguity --
        // written through whenever present. nickname has no separate TH/EN source on the wire, so
        // (same fallback already used for placeholder employee names elsewhere in this class) the
        // same raw value is written to both nickname_th and nickname_en.
        $title = $this->normalizeTitle($row['title'] ?? null);
        if ($title !== null) {
            $set[] = "title = :title";
            $params[':title'] = $title;
        }
        $gender = $this->normalizeGender($row['gender'] ?? null);
        if ($gender !== null) {
            $set[] = "gender = :gender";
            $params[':gender'] = $gender;
        }
        $maritalStatus = $this->normalizeMaritalStatus($row['marital_status'] ?? null);
        if ($maritalStatus !== null) {
            $set[] = "marital_status = :marital_status";
            $params[':marital_status'] = $maritalStatus;
        }
        if (!empty($row['date_birth']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$row['date_birth'])) {
            $set[] = "date_of_birth = :date_of_birth";
            $params[':date_of_birth'] = $row['date_birth'];
        }
        if (!empty($row['nickname'])) {
            $set[] = "nickname_th = :nickname_th";
            $params[':nickname_th'] = $row['nickname'];
            $set[] = "nickname_en = :nickname_en";
            $params[':nickname_en'] = $row['nickname'];
        }
        $nationalityCode = $this->resolveNationalityCode($row['nationality'] ?? null);
        if ($nationalityCode !== null) {
            $set[] = "nationality = :nationality";
            $params[':nationality'] = $nationalityCode;
        }
        $religionCode = $this->resolveReligionCode($row['religion'] ?? null);
        if ($religionCode !== null) {
            $set[] = "religion = :religion";
            $params[':religion'] = $religionCode;
        }
        // email/emp_tel go into company_email/office_tel, not personal_email (NOT NULL, this app's
        // own field, risky to overwrite from an ambiguous source)/mobile_no (NOT NULL, 10-char
        // mobile-specific format that emp_tel isn't guaranteed to match) -- see class docblock.
        if (!empty($row['email'])) {
            $set[] = "company_email = :company_email";
            $params[':company_email'] = $row['email'];
        }
        if (!empty($row['emp_tel'])) {
            $set[] = "office_tel = :office_tel";
            $params[':office_tel'] = $row['emp_tel'];
        }

        // Signature (2026-08-27 request): decrypt this row's stored ciphertext, decode+MIME-sniff
        // it (see decodeSignatureDrawing()'s docblock), and only actually write a new file when the
        // decoded bytes differ from what's already on disk at the employee's current signature_path
        // -- see class docblock for why (avoid piling up an identical orphaned file on every pull).
        if (!empty($row['signature_drawing'])) {
            $signaturePlain = EncryptionService::decrypt($row['signature_drawing'], $sourceKeyVersion);
            $decodedSignature = $signaturePlain !== null ? $this->decodeSignatureDrawing($signaturePlain) : null;
            if ($decodedSignature !== null) {
                $currentSigPath = $current['signature_path'] ?? null;
                $currentSigAbsPath = $currentSigPath ? (__DIR__ . '/../../' . $currentSigPath) : null;
                $unchanged = $currentSigAbsPath && is_file($currentSigAbsPath)
                    && hash('sha256', (string)file_get_contents($currentSigAbsPath)) === hash('sha256', $decodedSignature['bytes']);
                if (!$unchanged) {
                    $signatureDir = __DIR__ . '/../../public/uploads/employee_signatures/' . $compId . '/';
                    if (is_dir($signatureDir) || mkdir($signatureDir, 0755, true)) {
                        $signatureFileName = bin2hex(random_bytes(16)) . '.' . $decodedSignature['ext'];
                        if (file_put_contents($signatureDir . $signatureFileName, $decodedSignature['bytes']) !== false) {
                            $set[] = "signature_path = :signature_path";
                            $params[':signature_path'] = 'public/uploads/employee_signatures/' . $compId . '/' . $signatureFileName;
                        }
                    }
                }
            }
        }

        // Spouse/children (2026-08-28, explicit follow-up -- was received and stored encrypted in
        // payroll_sync_items.spouse_data/children_data since the 2026-08-18 rev 2 doc addition, but
        // never actually applied to the employee's real profile; see this class's own docblock note
        // above explaining why it was deferred). Confirmed via AskUserQuestion: same "Origami is the
        // source of truth, overwrite on every pull" policy as the rest of this method (pay_bank/
        // deduct_sso/etc.) -- NOT insert-once like EmployeeSyncer's payroll fields. This means a
        // manually-added dependent/parent that Origami doesn't know about WILL be removed on the
        // next pull -- an accepted tradeoff of the chosen policy, not an oversight.
        $spouseJson = !empty($row['spouse_data']) ? EncryptionService::decrypt($row['spouse_data'], $sourceKeyVersion) : null;
        $spouse = $spouseJson !== null ? json_decode($spouseJson, true) : null;
        if (is_array($spouse) && !empty(trim((string)($spouse['spouse_name'] ?? '')))) {
            $set[] = "has_spouse = 1";
            $spouseFullName = trim((string)($spouse['spouse_name'] ?? '') . ' ' . (string)($spouse['spouse_lastname'] ?? ''));
            $set[] = "spouse_name = :spouse_name";
            $params[':spouse_name'] = $spouseFullName;
            $plain['spouse_id_card_no'] = !empty($spouse['spouse_idcard']) ? (string)$spouse['spouse_idcard'] : null;
            $touchedEncrypted = true;
        } elseif (is_array($spouse)) {
            // spouse_data present but genuinely empty (every field blank) -- ingest() already
            // collapses this exact case to a NULL column (see class docblock), so in practice this
            // branch is unreachable today; kept for safety if that normalization ever changes.
            $set[] = "has_spouse = 0";
            $set[] = "spouse_name = NULL";
            $plain['spouse_id_card_no'] = null;
            $touchedEncrypted = true;
        }

        $childrenJson = !empty($row['children_data']) ? EncryptionService::decrypt($row['children_data'], $sourceKeyVersion) : null;
        $children = $childrenJson !== null ? json_decode($childrenJson, true) : null;

        // employee_dependents (children) and employee_parents (the employee's OWN father/mother,
        // bundled inside the same `spouse` object on the wire per PAYROLL_SYNC_API.md's own note)
        // are separate tables from `employees` -- own-transaction guard per this project's own
        // convention (multi-step write across 3 tables must not partially apply if interrupted).
        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            // Whole-set replace (delete+reinsert), same pattern already used elsewhere in this
            // project (approval_workflow_steps, holiday_assignments) -- matches the "overwrite every
            // pull" policy just confirmed, not a partial diff-and-patch.
            if (is_array($spouse)) {
                $this->db->prepare("DELETE FROM employee_parents WHERE employee_id = :employee_id AND relationship IN ('father', 'mother')")
                    ->execute([':employee_id' => $employeeId]);
                $parentSlots = [
                    'father' => ['name' => trim((string)($spouse['father_name'] ?? '') . ' ' . (string)($spouse['father_lastname'] ?? '')), 'id_card' => $spouse['father_idcard'] ?? null],
                    'mother' => ['name' => trim((string)($spouse['mother_name'] ?? '') . ' ' . (string)($spouse['mother_lastname'] ?? '')), 'id_card' => $spouse['mother_idcard'] ?? null],
                ];
                foreach ($parentSlots as $relationship => $parent) {
                    if ($parent['name'] === '') {
                        continue;
                    }
                    $idCardEnc = !empty($parent['id_card']) ? EncryptionService::encrypt((string)$parent['id_card']) : null;
                    $stmtParent = $this->db->prepare("INSERT INTO employee_parents
                            (employee_id, name, id_card_no, relationship, status, key_version, created_by)
                        VALUES (:employee_id, :name, :id_card_no, :relationship, 'active', :key_version, :created_by)");
                    $stmtParent->execute([
                        ':employee_id' => $employeeId, ':name' => $parent['name'],
                        ':id_card_no' => $idCardEnc['value'] ?? null, ':relationship' => $relationship,
                        ':key_version' => $idCardEnc !== null ? EncryptionService::currentKeyVersion() : null,
                        ':created_by' => $triggeredBy,
                    ]);
                }
            }
            if (is_array($children)) {
                $this->db->prepare("DELETE FROM employee_dependents WHERE employee_id = :employee_id")
                    ->execute([':employee_id' => $employeeId]);
                foreach ($children as $child) {
                    $childName = trim((string)($child['child_name'] ?? '') . ' ' . (string)($child['child_lastname'] ?? ''));
                    if ($childName === '') {
                        continue;
                    }
                    $idCardEnc = !empty($child['child_idcard']) ? EncryptionService::encrypt((string)$child['child_idcard']) : null;
                    $dob = !empty($child['child_birthday']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$child['child_birthday']) ? $child['child_birthday'] : null;
                    // child_type is NOT a legitimate/adopted signal (confirmed by Origami 2026-09-02
                    // -- see class docblock) -- it's an age-based tax-deduction category, unrelated
                    // to parentage, and Origami's payload has no legitimate/adopted field at all.
                    // 'child_legitimate' stays the fixed default since relationship is NOT NULL and
                    // there's genuinely nothing on the wire to distinguish it from adopted.
                    $stmtChild = $this->db->prepare("INSERT INTO employee_dependents
                            (employee_id, name, id_card_no, date_of_birth, relationship, studying, status, key_version, created_by)
                        VALUES (:employee_id, :name, :id_card_no, :date_of_birth, 'child_legitimate', 0, 'active', :key_version, :created_by)");
                    $stmtChild->execute([
                        ':employee_id' => $employeeId, ':name' => $childName,
                        ':id_card_no' => $idCardEnc['value'] ?? null, ':date_of_birth' => $dob,
                        ':key_version' => $idCardEnc !== null ? EncryptionService::currentKeyVersion() : null,
                        ':created_by' => $triggeredBy,
                    ]);
                }
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

        // Probation -> Permanent auto-transition (2026-08-19, explicit request -- reverses the
        // earlier "never auto-applied, human decision" stance for this one specific direction only).
        // Only fires when this pull's derived probation_status is 'passed' (see
        // deriveProbationStatus()'s docblock for the pass_pro/pass_pro_date rule) AND the employee's
        // CURRENT employment_status is still exactly 'probation' -- so this can only ever move
        // probation -> permanent, never touch someone already contract/resigned/terminated (a
        // resigned employee who happens to have an old pass_pro=Y on file must never be silently
        // reactivated), and is naturally idempotent (a re-pull after the transition already
        // happened finds employment_status no longer 'probation' and does nothing further).
        $passProValue = array_key_exists('pass_pro', $row) && $row['pass_pro'] !== null ? (int)$row['pass_pro'] : null;
        $probationStatus = $this->deriveProbationStatus($passProValue, $row['pass_pro_date'] ?? null);
        if ($probationStatus === 'passed' && ($current['employment_status'] ?? null) === 'probation') {
            $set[] = "employment_status = 'permanent'";
        }

        if ($touchedEncrypted) {
            // Re-encrypt the WHOLE encrypted set together, not just the field(s) this row touched
            // -- see class docblock. Every encrypted column always gets rewritten under the same
            // (current) key version in this branch, keeping employees.key_version valid for all of
            // them, exactly like EmployeeModel::save() does for a full-form save.
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

        if (empty($set)) {
            return false;
        }
        $set[] = "updated_by = :updated_by";
        $params[':updated_by'] = $triggeredBy;
        $set[] = "updated_at = CURRENT_TIMESTAMP";
        $sql = "UPDATE employees SET " . implode(', ', $set) . " WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL";
        $this->db->prepare($sql)->execute($params);
        return true;
    }

    private function resolveEmployeeId(int $compId, string $payrollCode): ?int {
        $stmt = $this->db->prepare("SELECT id FROM employees WHERE comp_id = :comp_id AND employee_no = :employee_no AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':comp_id' => $compId, ':employee_no' => $payrollCode]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /**
     * 2026-09-06: classifies a supplemental row's merge readiness for Pending Pull's own badge/
     * button, using the extra target-lookup columns pendingList()'s own query joins in. Returns
     * `null` for anything that isn't a "merge" attribution at all (regular rows, `separate`
     * attribution, no attribution) -- those never show a merge badge to begin with.
     * - `'ready'`: the target regular cycle has already been pulled into a `payroll_runs` row here
     *   -- Merge into Target works right now.
     * - `'waiting_known'`: Origami has already sent us the target regular process's own sync
     *   payload (it exists in `payroll_sync_processes`, still `status='pending'`), but nobody has
     *   pulled it into a run yet.
     * - `'waiting_unknown'`: we have never received that target process from Origami at all --
     *   confirmed with Origami there is no guaranteed send order, so this is a normal, expected
     *   transient state, not an error.
     * - `'target_rejected'`: the target process WAS received but has since been rejected
     *   (rejectProcess(), a real terminal state -- there is no un-reject action anywhere in this
     *   class, and re-sending the same origami_process_id later does not reset `status` either,
     *   see upsertProcess()'s own UPDATE branch) -- unlike `waiting_known`/`waiting_unknown`, this
     *   attribution will NEVER resolve on its own; genuinely distinct from "still waiting" so the
     *   UI doesn't imply it'll become ready eventually.
     * - `'pending_fold_in'` (2026-09-08): Origami itself hasn't chosen a target AT ALL yet
     *   (`attribution_status='pending_fold_in'`, `attribution_target_origami_process_id IS NULL`)
     *   -- genuinely earlier in the lifecycle than `waiting_known`/`waiting_unknown` (those both
     *   assume Origami already picked a target, just checking whether WE'VE received/pulled it).
     *   Resolves only via a future `attribution_update` event (PayrollSyncModel::
     *   applyAttributionUpdate()), never on its own from anything on our side. Checked FIRST,
     *   before the target-lookup checks below, since there is no target to look up yet.
     *   Deliberately only applies when `tax_treatment='merge'` -- confirmed with Origami that
     *   `tax_treatment='separate'` rows never gate on merge-readiness regardless of
     *   `attribution_status` (their pending_fold_in there is purely a future report/period
     *   reconciliation label, not something this app's own tax finalization needs to wait for).
     */
    private function attributionTargetStatus(array $row): ?string {
        if (($row['run_kind'] ?? '') !== 'supplemental' || ($row['attribution_tax_treatment'] ?? '') !== 'merge') {
            return null;
        }
        if (($row['attribution_status'] ?? null) === 'pending_fold_in') {
            return 'pending_fold_in';
        }
        if (!empty($row['attribution_target_run_id'])) {
            return 'ready';
        }
        if (!empty($row['attribution_target_sync_process_id'])) {
            return ($row['attribution_target_process_status'] ?? '') === 'rejected' ? 'target_rejected' : 'waiting_known';
        }
        return 'waiting_unknown';
    }

    /**
     * Sync processes not yet pulled into a payroll run -- the Payroll Process page's "Pending
     * Pull" station. date_from/date_to filter on received_at (the only real date this table has --
     * payroll_sync_processes has no period_start/end of its own, just the free-text period_name
     * label) -- shares the List page's own Date From/To filter box by explicit request, so picking
     * a range that matches nothing here correctly shows empty instead of silently ignoring the
     * filter and always returning the full unfiltered list.
     */
    public function pendingList(int $compId, array $filters = []): array {
        // 2026-08-31: `status='pending'` added alongside the existing `r.id IS NULL` check -- a
        // process that's been explicitly rejected (rejectProcess() below) must also drop out of
        // this station, same as one that's been pulled into a run, even though neither r.id nor
        // this row's own comp_id/date filters changed at all.
        // `merged_into_run_id IS NULL` (2026-08-31, PAYROLL_SYNC_API.md attribution revision) --
        // same reasoning: a supplemental process whose items were MERGED into an existing regular
        // run (PayrollRunModel::mergeSupplementalIntoRun()) is consumed too, even though it was
        // never itself the primary sync_process_id of any run (r.id stays NULL for it).
        $where = "WHERE p.comp_id = :comp_id AND r.id IS NULL AND p.status = 'pending' AND p.merged_into_run_id IS NULL";
        $params = [':comp_id' => $compId];
        if (!empty($filters['date_from'])) {
            $where .= " AND p.received_at >= :date_from";
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND p.received_at <= :date_to";
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        // process_subject/process_start/process_end/process_paid/run_kind (2026-08-29, see
        // PAYROLL_SYNC_API.md's own 2026-08-28 revision) -- surfaced here so the Payroll Process
        // page's "Pull to Run" action can pre-fill the run's own name/period/pay date directly from
        // what Origami sent, per explicit request, instead of the admin re-entering it by hand.
        // attribution_* (2026-08-31, PAYROLL_SYNC_API.md revision) -- surfaced so the Pending Pull
        // station can show a supplemental row's routing intent (merge into a named regular cycle,
        // vs. separate) before an admin pulls it, see PayrollSyncModel::normalizeAttribution()'s
        // own docblock.
        //
        // 2026-09-06, real gap found and fixed: confirmed with Origami that there is NO send-order
        // guarantee between a regular process's own sync payload and a supplemental process
        // attributed to merge into it (e.g. a mid-month trip-allowance batch attributed to "next
        // month's regular cycle" can arrive here before OR after that regular cycle's own payload
        // does). `attribution_target_sync_process_id`/`attribution_target_run_id` (two extra LEFT
        // JOINs, both keyed off the SAME origami_process_id uniqueness `mergeSupplementalIntoRun()`
        // itself already relies on) let attributionTargetStatus() below tell apart 3 real states
        // instead of the old binary "ready or reject": target run already pulled here (ready),
        // target process known to us but not pulled into a run yet (waiting_known), or we haven't
        // even received that target process from Origami at all yet (waiting_unknown) -- see that
        // method's own docblock. Previously the UI offered the Merge button regardless and only
        // found out it couldn't work when the admin actually clicked it and
        // PayrollRunModel::mergeSupplementalIntoRun() refused.
        $stmt = $this->db->prepare("SELECT p.id, p.origami_process_id, p.process_no, p.process_subject,
                p.process_start, p.process_end, p.process_paid, p.run_kind,
                p.attribution_target_origami_process_id, p.attribution_target_process_no, p.attribution_tax_treatment, p.attribution_status,
                p.origami_comp_name, p.period_name, p.frequency_type, p.external_cycle_code,
                p.item_count, p.unmapped_item_count, p.received_at,
                tp.id AS attribution_target_sync_process_id, tp.status AS attribution_target_process_status,
                tr.id AS attribution_target_run_id
            FROM payroll_sync_processes p
            LEFT JOIN payroll_runs r ON r.sync_process_id = p.id
            LEFT JOIN payroll_sync_processes tp ON tp.origami_process_id = p.attribution_target_origami_process_id AND tp.comp_id = p.comp_id
            LEFT JOIN payroll_runs tr ON tr.sync_process_id = tp.id AND tr.deleted_at IS NULL
            {$where}
            ORDER BY p.received_at DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['attribution_target_status'] = $this->attributionTargetStatus($row);
        }
        unset($row);

        // 2026-09-01, explicit request: "อยากให้กดแล้ว Default ค่าที่ส่งมา Origami เลยโดยที่ไม่ต้องเลือกใหม่" --
        // matched_cycle_id/matched_cycle_name (null when nothing confidently matches) let "Pull to
        // Run" pre-select the Payroll Schedule dropdown too, not just the period dates. See
        // PayrollCycleModel::matchForSyncProcess()'s own docblock for the full heuristic and its
        // limits -- this is a best-effort guess, not a real Origami-side mapping.
        if (!empty($rows)) {
            $cycleModel = new PayrollCycleModel($this->db);
            $activeCycles = $cycleModel->list($compId);
            foreach ($rows as &$row) {
                $matched = $cycleModel->matchForSyncProcess($activeCycles, $row);
                $row['matched_cycle_id'] = $matched['id'] ?? null;
                $row['matched_cycle_name'] = $matched['cycle_name'] ?? null;
            }
            unset($row);
        }
        return $rows;
    }

    /**
     * 2026-08-31, explicit request: "เพิ่มให้สามารถตีกลับเอกสารที่ยังไม่ดึงมาทำรอบได้ โดยที่ต้องใส่ Comment
     * เข้าไปด้วยครับ...และต้องมีเอกสารส่งไปที่ Origami เพื่อให้ฝั่งนั้นเขียนรับค่า Status และการตีกลับครับ...และ
     * เน้นย้ำต้องเก็บ Log การดำเนินการ" -- reject-back for a payroll_sync_processes row still in the
     * Pending Pull station (never yet consumed by a run). A comment is mandatory (this IS the
     * "ต้องใส่ Comment" requirement) and becomes `rejected_reason`. Refuses if the process has
     * ALREADY been pulled into a run (same `r.id IS NULL` check pendingList() itself uses -- a
     * process that already became a real run cannot retroactively be un-pulled by this action) or
     * is already rejected (idempotent-refuse, not a silent no-op success). Pushes the rejection to
     * Origami best-effort (OrigamiPayrollStatusClient, never throws back into this method) --
     * origami_status_push_logs is the audit trail for THAT half; this row's own rejected_reason/
     * rejected_by/rejected_at is the audit trail for the reject action itself.
     */
    public function rejectProcess(int $processId, int $compId, string $comment, int $userId): array {
        $comment = trim($comment);
        if ($comment === '') {
            return ['status' => false, 'message' => 'A comment is required to reject this document.'];
        }
        $stmt = $this->db->prepare("SELECT p.*, r.id AS linked_run_id FROM payroll_sync_processes p
            LEFT JOIN payroll_runs r ON r.sync_process_id = p.id
            WHERE p.id = :id AND p.comp_id = :comp_id");
        $stmt->execute([':id' => $processId, ':comp_id' => $compId]);
        $process = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$process) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($process['linked_run_id'] !== null) {
            return ['status' => false, 'message' => 'This document has already been pulled into a payroll run and can no longer be rejected.'];
        }
        if ($process['status'] === 'rejected') {
            return ['status' => false, 'message' => 'This document has already been rejected.'];
        }
        $upd = $this->db->prepare("UPDATE payroll_sync_processes
            SET status = 'rejected', rejected_reason = :reason, rejected_by = :user_id, rejected_at = CURRENT_TIMESTAMP
            WHERE id = :id");
        $upd->execute([':reason' => $comment, ':user_id' => $userId, ':id' => $processId]);

        try {
            require_once __DIR__ . '/../services/OrigamiPayrollStatusClient.php';
            (new OrigamiPayrollStatusClient($this->db))->pushSyncProcessRejected($process, $comment);
        } catch (Throwable $e) {
            // Best-effort -- the local reject already committed; a push failure is logged by the
            // client itself and must never surface as a failure of THIS action.
        }

        return ['status' => true, 'message' => 'Rejected.'];
    }

    /**
     * Full detail for the "View" modal on a single pending row -- header + the per-employee items
     * (item_values JSON decoded back into an array for the frontend) + employee_status snapshot.
     * Not scoped to "still pending" -- once pulled it's still fine to look back at what was sent.
     */
    /**
     * Masks every idcard/tax-number-like key in a decrypted spouse/children array to its last 4
     * characters, in place -- same masking rule as pay_bank_no_masked/id_card_no_masked above,
     * just applied to the handful of known PII keys nested inside these two JSON blobs rather than
     * a single top-level column.
     */
    private function maskNestedIdLikeFields(?array $data): ?array {
        if ($data === null) {
            return null;
        }
        foreach (['spouse_idcard', 'spouse_tax', 'father_idcard', 'mother_idcard', 'child_idcard'] as $key) {
            if (!empty($data[$key]) && is_string($data[$key])) {
                $val = $data[$key];
                $data[$key] = strlen($val) > 4 ? str_repeat('x', strlen($val) - 4) . substr($val, -4) : $val;
            }
        }
        return $data;
    }

    public function getProcessDetail(int $id, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM payroll_sync_processes WHERE id = :id AND comp_id = :comp_id");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$header) {
            return null;
        }

        $itemStmt = $this->db->prepare("SELECT i.*, e.employee_no AS matched_employee_no, e.name_en AS matched_name_en, e.surname_en AS matched_surname_en,
                e.name_th AS matched_name_th, e.surname_th AS matched_surname_th
            FROM payroll_sync_items i
            LEFT JOIN employees e ON e.id = i.employee_id
            WHERE i.process_id = :process_id ORDER BY i.id");
        $itemStmt->execute([':process_id' => $id]);
        $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as &$item) {
            $item['item_values'] = $item['item_values'] !== null ? json_decode($item['item_values'], true) : [];
            // pay_bank_no is stored encrypted (see replaceItems()) -- decrypt then mask to the last
            // 4 digits for display, same convention as PaySlipReport's bank_account_masked field.
            // Never send the raw decrypted number or key_version to the frontend.
            $keyVersion = isset($item['key_version']) ? (int)$item['key_version'] : null;
            $bankNo = EncryptionService::decrypt($item['pay_bank_no'] ?? null, $keyVersion);
            $item['pay_bank_no_masked'] = $bankNo !== null && strlen($bankNo) > 4 ? str_repeat('x', strlen($bankNo) - 4) . substr($bankNo, -4) : $bankNo;
            $idCardNo = EncryptionService::decrypt($item['id_card_no'] ?? null, $keyVersion);
            $item['id_card_no_masked'] = $idCardNo !== null && strlen($idCardNo) > 4 ? str_repeat('x', strlen($idCardNo) - 4) . substr($idCardNo, -4) : $idCardNo;

            // spouse/children (2026-08-18 rev 2): decrypt the whole blob back to an array/object,
            // then mask every idcard-like nested field before this ever reaches the frontend --
            // same policy as pay_bank_no/id_card_no above, just applied recursively since the PII
            // here is nested inside a JSON structure rather than its own column.
            $spouseJson = EncryptionService::decrypt($item['spouse_data'] ?? null, $keyVersion);
            $item['spouse'] = $spouseJson !== null ? $this->maskNestedIdLikeFields(json_decode($spouseJson, true)) : null;
            $childrenJson = EncryptionService::decrypt($item['children_data'] ?? null, $keyVersion);
            $children = $childrenJson !== null ? json_decode($childrenJson, true) : [];
            $item['children'] = is_array($children) ? array_map([$this, 'maskNestedIdLikeFields'], $children) : [];

            // signature_drawing (2026-08-27): the decrypted value is the actual image content --
            // easily tens of KB and meaningless to display raw in the View modal, so this only ever
            // surfaces a presence flag, same "never expose the sensitive raw value" policy as
            // pay_bank_no/id_card_no above.
            $item['has_signature_drawing'] = EncryptionService::decrypt($item['signature_drawing'] ?? null, $keyVersion) !== null;

            // Derived 3-state probation status (2026-08-19) -- see deriveProbationStatus()'s own
            // docblock. $item['pass_pro'] here is already the corrected 1/0/null stored at ingest
            // time (parseYesNoFlag()), not the raw "Y"/"N" wire value.
            $item['probation_status'] = $this->deriveProbationStatus(
                $item['pass_pro'] !== null ? (int)$item['pass_pro'] : null,
                $item['pass_pro_date'] ?? null
            );

            unset($item['pay_bank_no'], $item['id_card_no'], $item['key_version'], $item['spouse_data'], $item['children_data'], $item['signature_drawing']);
        }
        unset($item);

        $statusStmt = $this->db->prepare("SELECT * FROM payroll_sync_employee_status WHERE process_id = :process_id ORDER BY id");
        $statusStmt->execute([':process_id' => $id]);
        $employeeStatus = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

        $header['items'] = $items;
        $header['employee_status'] = $employeeStatus;
        return $header;
    }
}
