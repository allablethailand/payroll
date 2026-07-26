<?php
declare(strict_types=1);
require_once __DIR__ . '/MasterDataSyncerInterface.php';

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
 *    tax_calculation_method, bank info) are only ever DEFAULTED on first insert, never touched
 *    again -- protects whatever the payroll admin has already configured locally. A newly-landed
 *    employee gets placeholder defaults (e.g. base_salary_amount stays at the schema default of
 *    0.00) that a payroll admin must complete via Employee Detail afterward.
 *  - address and emergency-contact columns are NOT NULL in this schema but not meaningfully
 *    sourced from either a sync payload or the import template in this design -- placeholder-
 *    filled on insert, never touched again.
 *
 * sync() resolves department/position/shift by origami_ref_id (Origami's numeric id, matching
 * what a live API would return). importRow() resolves the SAME links by CODE instead
 * (department_code/position_code/shift_code) -- a human filling a spreadsheet knows the codes
 * shown in this system's own department/shift/etc. list pages, not Origami's internal ref ids.
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

    /** @param array{department_id: ?int, position_id: ?int, shift_id: ?int} $links */
    private function upsertItem(int $compId, array $item, array $links, int $batchId, ?int $triggeredBy, ?int $existingId, string $dataSource): void {
        $employeeNo = trim((string)($item['employee_no'] ?? ''));
        $nameTh = trim((string)($item['name_th'] ?? ''));
        $surnameTh = trim((string)($item['surname_th'] ?? ''));
        $nameEn = trim((string)($item['name_en'] ?? ''));
        $surnameEn = trim((string)($item['surname_en'] ?? ''));
        $dob = trim((string)($item['date_of_birth'] ?? ''));
        $gender = (string)($item['gender'] ?? '');
        $employmentDate = trim((string)($item['employment_date'] ?? ''));
        $employmentStatus = (string)($item['employment_status'] ?? '');
        if ($employeeNo === '' || $nameTh === '' || $surnameTh === '' || $nameEn === '' || $surnameEn === ''
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || !in_array($gender, ['male', 'female', 'other'], true)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $employmentDate)
            || !in_array($employmentStatus, ['probation', 'permanent', 'contract', 'resigned', 'terminated'], true)) {
            throw new InvalidArgumentException('Missing or invalid employee_no/name/surname/date_of_birth/gender/employment_date/employment_status.');
        }
        $personalEmail = trim((string)($item['personal_email'] ?? ''));
        $mobileNo = substr(trim((string)($item['mobile_no'] ?? '')), 0, 10);
        if ($personalEmail === '' || $mobileNo === '') {
            throw new InvalidArgumentException('Missing personal_email/mobile_no.');
        }

        $refId = isset($item['ref_id']) && is_numeric($item['ref_id']) ? (int)$item['ref_id'] : null;
        if ($existingId !== null) {
            $stmt = $this->db->prepare("UPDATE employees SET
                    employee_no = :employee_no, name_th = :name_th, surname_th = :surname_th, name_en = :name_en, surname_en = :surname_en,
                    date_of_birth = :dob, gender = :gender, personal_email = :email, mobile_no = :mobile,
                    department_id = :department_id, position_id = :position_id, shift_id = :shift_id,
                    employment_date = :employment_date, employment_status = :employment_status,
                    sync_batch_id = :batch_id, updated_by = :user, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([
                ':employee_no' => $employeeNo, ':name_th' => $nameTh, ':surname_th' => $surnameTh, ':name_en' => $nameEn, ':surname_en' => $surnameEn,
                ':dob' => $dob, ':gender' => $gender, ':email' => $personalEmail, ':mobile' => $mobileNo,
                ':department_id' => $links['department_id'], ':position_id' => $links['position_id'], ':shift_id' => $links['shift_id'],
                ':employment_date' => $employmentDate, ':employment_status' => $employmentStatus,
                ':batch_id' => $batchId, ':user' => $triggeredBy, ':id' => $existingId,
            ]);
            return;
        }

        $nationality = trim((string)($item['nationality'] ?? '')) ?: 'Thai';
        $title = in_array($item['title'] ?? '', ['mr', 'mrs', 'ms'], true) ? $item['title'] : 'mr';
        $stmt = $this->db->prepare("INSERT INTO employees
                (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
                 personal_email, mobile_no, address_line_1_register, address_line_1_contact,
                 emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
                 department_id, position_id, shift_id,
                 employment_date, employment_status, employment_type, workforce_type, record_time_method,
                 salary_effective_date, tax_calculation_method, employee_status,
                 origami_ref_id, data_source, sync_batch_id, created_by)
            VALUES
                (:comp_id, :employee_no, :title, :gender, :name_th, :surname_th, :name_en, :surname_en, :dob, :nationality,
                 :email, :mobile, '', '',
                 'N/A', 'N/A', 'N/A', '0000000000',
                 :department_id, :position_id, :shift_id,
                 :employment_date, :employment_status, 'full_time', 'office', 'manual',
                 :employment_date, 'average', 'active',
                 :ref_id, :data_source, :batch_id, :user)");
        $stmt->execute([
            ':comp_id' => $compId, ':employee_no' => $employeeNo, ':title' => $title, ':gender' => $gender,
            ':name_th' => $nameTh, ':surname_th' => $surnameTh, ':name_en' => $nameEn, ':surname_en' => $surnameEn,
            ':dob' => $dob, ':nationality' => $nationality, ':email' => $personalEmail, ':mobile' => $mobileNo,
            ':department_id' => $links['department_id'], ':position_id' => $links['position_id'], ':shift_id' => $links['shift_id'],
            ':employment_date' => $employmentDate, ':employment_status' => $employmentStatus,
            ':ref_id' => $refId, ':data_source' => $dataSource, ':batch_id' => $batchId, ':user' => $triggeredBy,
        ]);
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
