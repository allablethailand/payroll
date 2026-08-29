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
 * resolveOrCreateShift() are used ONLY there, not by sync()/importRow() -- this interactive path
 * has no dependency-order concept to protect (each Apply is one hand-picked employee, not a
 * whole-company batch where getting the order wrong has wider blast radius), so auto-creating is
 * safe here in a way it deliberately isn't for the bulk paths. Team is excluded from this --
 * OrigamiEmployeeCandidateClient's own docblock explains why Team has no Origami-side equivalent
 * to create against at all.
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
     * @param array{department_id: ?int, position_id: ?int, shift_id: ?int} $links
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

        if ($existingId !== null) {
            // 2026-08-28, real gap found and fixed (explicit follow-up: "มีข้อมูลส่งมา Map ทีหลัง...จะ
            // ได้มีปุ่มทุกคน" -- a manually-created employee matched here via findByEmployeeNo()
            // fallback, not findByRefId(), never had origami_ref_id written on this UPDATE branch at
            // all (only the INSERT branch below did) -- so they'd never actually become "linked",
            // permanently stuck re-resolving by employee_no on every future sync instead of the
            // faster/more precise ref_id path, and Employee Detail's Re-Sync button (which checks
            // origami_ref_id to decide whether to even show) would never light up for them. Safe to
            // write unconditionally here: applyOne()'s own findByRefId()-then-findByEmployeeNo()
            // precedence already guarantees $refId doesn't already belong to a DIFFERENT row by the
            // time execution reaches this employee_no fallback branch (if it did, findByRefId()
            // would have resolved $existingId to that row instead), so this can never collide with
            // the uq_employees_origami_ref unique key.
            $stmt = $this->db->prepare("UPDATE employees SET
                    employee_no = :employee_no, name_th = :name_th, surname_th = :surname_th, name_en = :name_en, surname_en = :surname_en,
                    date_of_birth = :dob, gender = :gender, personal_email = :email, mobile_no = :mobile,
                    department_id = :department_id, position_id = :position_id, shift_id = :shift_id,
                    employment_date = :employment_date, employment_status = :employment_status,
                    origami_ref_id = :ref_id, sync_batch_id = :batch_id, updated_by = :user, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([
                ':employee_no' => $employeeNo, ':name_th' => $nameTh, ':surname_th' => $surnameTh, ':name_en' => $nameEn, ':surname_en' => $surnameEn,
                ':dob' => $dob, ':gender' => $gender, ':email' => $personalEmail, ':mobile' => $mobileNo,
                ':department_id' => $links['department_id'], ':position_id' => $links['position_id'], ':shift_id' => $links['shift_id'],
                ':employment_date' => $employmentDate, ':employment_status' => $employmentStatus,
                ':ref_id' => $refId, ':batch_id' => $batchId, ':user' => $triggeredBy, ':id' => $existingId,
            ]);
            return;
        }

        $nationality = trim((string)($item['nationality'] ?? '')) ?: 'Thai';
        $title = in_array($item['title'] ?? '', ['mr', 'mrs', 'ms'], true) ? $item['title'] : 'mr';

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

        // Same AES-256-GCM mechanism EmployeeModel::save() itself uses for these two columns --
        // never stored plaintext. key_version is only set when at least one of them actually
        // produced ciphertext (EncryptionService::encrypt() returns null for an empty value).
        $baseSalaryEnc = EncryptionService::encrypt($baseSalaryAmount !== '' ? $baseSalaryAmount : null);
        $bankAccountEnc = EncryptionService::encrypt($bankAccountNo !== '' ? $bankAccountNo : null);
        $bankAccountNoHash = EncryptionService::hash($bankAccountNo !== '' ? $bankAccountNo : null);
        $keyVersion = ($baseSalaryEnc !== null || $bankAccountEnc !== null) ? EncryptionService::currentKeyVersion() : null;

        $stmt = $this->db->prepare("INSERT INTO employees
                (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
                 personal_email, mobile_no, address_line_1_register, address_line_1_contact,
                 emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
                 department_id, position_id, shift_id,
                 employment_date, employment_status, employment_type, workforce_type, record_time_method,
                 payment_type, bank_id, bank_account_no, bank_account_no_hash, bank_account_name, bank_branch,
                 salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
                 origami_ref_id, data_source, sync_batch_id, key_version, created_by)
            VALUES
                (:comp_id, :employee_no, :title, :gender, :name_th, :surname_th, :name_en, :surname_en, :dob, :nationality,
                 :email, :mobile, '', '',
                 'N/A', 'N/A', 'N/A', '0000000000',
                 :department_id, :position_id, :shift_id,
                 :employment_date, :employment_status, 'full_time', 'office', 'manual',
                 :payment_type, :bank_id, :bank_account_no, :bank_account_no_hash, :bank_account_name, :bank_branch,
                 :salary_type, :base_salary_amount, :employment_date, :tax_calculation_method, 'active',
                 :ref_id, :data_source, :batch_id, :key_version, :user)");
        $stmt->execute([
            ':comp_id' => $compId, ':employee_no' => $employeeNo, ':title' => $title, ':gender' => $gender,
            ':name_th' => $nameTh, ':surname_th' => $surnameTh, ':name_en' => $nameEn, ':surname_en' => $surnameEn,
            ':dob' => $dob, ':nationality' => $nationality, ':email' => $personalEmail, ':mobile' => $mobileNo,
            ':department_id' => $links['department_id'], ':position_id' => $links['position_id'], ':shift_id' => $links['shift_id'],
            ':employment_date' => $employmentDate, ':employment_status' => $employmentStatus,
            ':payment_type' => $paymentType, ':bank_id' => $bankId,
            ':bank_account_no' => $bankAccountEnc['value'] ?? null, ':bank_account_no_hash' => $bankAccountNoHash,
            ':bank_account_name' => $bankAccountName, ':bank_branch' => $bankBranch,
            ':salary_type' => $salaryType, ':base_salary_amount' => $baseSalaryEnc['value'] ?? null,
            ':tax_calculation_method' => $taxCalculationMethod,
            ':ref_id' => $refId, ':data_source' => $dataSource, ':batch_id' => $batchId, ':key_version' => $keyVersion, ':user' => $triggeredBy,
        ]);
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
