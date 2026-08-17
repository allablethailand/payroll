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
 * (added to the doc 2026-08-17) are stored as-received
 * but, per this task's scope, never touched beyond storage -- nothing here writes to
 * bank_accounts/employees or any statutory config; a human still has to review and pull each
 * process into a run before any of this becomes real payroll data. pay_bank_no is the one field
 * here that's genuine PII (an account number), so it's encrypted at rest the same way as
 * BankAccountModel/EmployeeModel (EncryptionService, value + row-level key_version column) even
 * though this table is just a staging area -- same class of sensitive data deserves the same
 * protection regardless of which table it's passing through. getProcessDetail() decrypts it back
 * only to mask it to the last 4 digits for the View modal; the raw decrypted number and
 * key_version never leave this model.
 */
class PayrollSyncModel {
    private PDO $db;

    private const SUPPORTED_SCHEMA_VERSION = 1;
    private const FREQUENCY_TYPES = ['monthly', 'semimonthly', 'weekly', 'biweekly'];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
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

        $ownTransaction = !$this->db->inTransaction();
        try {
            if ($ownTransaction) { $this->db->beginTransaction(); }

            $processRowId = $this->upsertProcess($compId, $payload);
            $unmappedCount = $this->replaceItems($processRowId, $compId, $payload['items']);
            $this->replaceEmployeeStatus($processRowId, $compId, $payload['employee_status'] ?? []);

            $stmt = $this->db->prepare("UPDATE payroll_sync_processes SET item_count = :item_count, unmapped_item_count = :unmapped_count WHERE id = :id");
            $stmt->execute([
                ':item_count' => count($payload['items']),
                ':unmapped_count' => $unmappedCount,
                ':id' => $processRowId,
            ]);

            if ($ownTransaction) { $this->db->commit(); }
            return ['status' => true, 'process_row_id' => $processRowId, 'unmapped_items' => $unmappedCount];
        } catch (Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Ingest failed: ' . $e->getMessage()];
        }
    }

    private function resolveCompanyId(string $compCode): ?int {
        $stmt = $this->db->prepare("SELECT id FROM companies WHERE origami_payroll_comp_code = :code LIMIT 1");
        $stmt->execute([':code' => $compCode]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    private function upsertProcess(int $compId, array $p): int {
        $stmt = $this->db->prepare("SELECT id FROM payroll_sync_processes WHERE origami_process_id = :pid LIMIT 1");
        $stmt->execute([':pid' => (int)$p['process_id']]);
        $existingId = $stmt->fetchColumn();

        $rawPayload = json_encode($p, JSON_UNESCAPED_UNICODE);

        if ($existingId !== false) {
            $id = (int)$existingId;
            $stmt = $this->db->prepare("UPDATE payroll_sync_processes SET
                    comp_id = :comp_id, process_no = :process_no, origami_report_id = :report_id,
                    origami_comp_code = :comp_code, origami_comp_name = :comp_name,
                    origami_period_id = :period_id, period_name = :period_name, frequency_type = :frequency_type,
                    schema_version = :schema_version, raw_payload = :raw_payload, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([
                ':comp_id' => $compId, ':process_no' => (string)$p['process_no'], ':report_id' => $p['report_id'] ?? null,
                ':comp_code' => (string)$p['comp_code'], ':comp_name' => (string)$p['comp_name'],
                ':period_id' => $p['period_id'] ?? null, ':period_name' => $p['period_name'] ?? null,
                ':frequency_type' => (string)$p['frequency_type'], ':schema_version' => (int)$p['schema_version'],
                ':raw_payload' => $rawPayload, ':id' => $id,
            ]);
            $this->db->prepare("DELETE FROM payroll_sync_items WHERE process_id = :id")->execute([':id' => $id]);
            $this->db->prepare("DELETE FROM payroll_sync_employee_status WHERE process_id = :id")->execute([':id' => $id]);
            return $id;
        }

        $stmt = $this->db->prepare("INSERT INTO payroll_sync_processes
                (comp_id, origami_process_id, process_no, origami_report_id, origami_comp_code, origami_comp_name,
                 origami_period_id, period_name, frequency_type, schema_version, raw_payload)
            VALUES (:comp_id, :pid, :process_no, :report_id, :comp_code, :comp_name,
                 :period_id, :period_name, :frequency_type, :schema_version, :raw_payload)");
        $stmt->execute([
            ':comp_id' => $compId, ':pid' => (int)$p['process_id'], ':process_no' => (string)$p['process_no'],
            ':report_id' => $p['report_id'] ?? null, ':comp_code' => (string)$p['comp_code'], ':comp_name' => (string)$p['comp_name'],
            ':period_id' => $p['period_id'] ?? null, ':period_name' => $p['period_name'] ?? null,
            ':frequency_type' => (string)$p['frequency_type'], ':schema_version' => (int)$p['schema_version'],
            ':raw_payload' => $rawPayload,
        ]);
        return (int)$this->db->lastInsertId();
    }

    private const PAY_TYPES = ['cash', 'transfer'];

    /** @return int number of rows whose payroll_code did not match any employee */
    private function replaceItems(int $processRowId, int $compId, array $items): int {
        $stmt = $this->db->prepare("INSERT INTO payroll_sync_items
                (process_id, employee_id, payroll_code, emp_code, mapping_status, origami_report_item_id,
                 dept_description, position_name, origami_branch_id, branch_name,
                 origami_shift_working_id, shift_working_name,
                 pay_type, origami_pay_bank_id, pay_bank_code, pay_bank_name, pay_bank_no, deduct_sso, key_version,
                 working_days, working_mins, absent_days, absent_mins, late_mins, early_mins,
                 ot_mins, ot_req_hrs, ot_req_working_day_hrs, ot_req_weekend_hrs, ot_req_holiday_hrs,
                 leave_approve_days, leave_wait_days, leave_without_pay_days, trip_allowance, item_values)
            VALUES
                (:process_id, :employee_id, :payroll_code, :emp_code, :mapping_status, :report_item_id,
                 :dept_description, :position_name, :branch_id, :branch_name,
                 :shift_working_id, :shift_working_name,
                 :pay_type, :pay_bank_id, :pay_bank_code, :pay_bank_name, :pay_bank_no, :deduct_sso, :key_version,
                 :working_days, :working_mins, :absent_days, :absent_mins, :late_mins, :early_mins,
                 :ot_mins, :ot_req_hrs, :ot_req_working_day_hrs, :ot_req_weekend_hrs, :ot_req_holiday_hrs,
                 :leave_approve_days, :leave_wait_days, :leave_without_pay_days, :trip_allowance, :item_values)");

        $unmappedCount = 0;
        foreach ($items as $item) {
            $payrollCode = (string)($item['payroll_code'] ?? '');
            $employeeId = $payrollCode !== '' ? $this->resolveEmployeeId($compId, $payrollCode) : null;
            $mappingStatus = $employeeId !== null ? 'mapped' : 'unmapped';
            if ($mappingStatus === 'unmapped') {
                $unmappedCount++;
            }
            $payType = in_array($item['pay_type'] ?? null, self::PAY_TYPES, true) ? $item['pay_type'] : null;
            // deduct_sso is tri-state on the wire (true/false/null) -- strict compare so "not sent"
            // and "explicitly false" don't collapse into the same stored value.
            $deductSso = array_key_exists('deduct_sso', $item) && $item['deduct_sso'] !== null
                ? ($item['deduct_sso'] ? 1 : 0) : null;
            $bankNoEnc = isset($item['pay_bank_no']) ? EncryptionService::encrypt((string)$item['pay_bank_no']) : null;
            $stmt->execute([
                ':process_id' => $processRowId, ':employee_id' => $employeeId, ':payroll_code' => $payrollCode,
                ':emp_code' => $item['emp_code'] ?? null, ':mapping_status' => $mappingStatus,
                ':report_item_id' => $item['report_item_id'] ?? null,
                ':dept_description' => $item['dept_description'] ?? null, ':position_name' => $item['position_name'] ?? null,
                ':branch_id' => $item['branch_id'] ?? null, ':branch_name' => $item['branch_name'] ?? null,
                ':shift_working_id' => $item['shift_working_id'] ?? null, ':shift_working_name' => $item['shift_working_name'] ?? null,
                ':pay_type' => $payType, ':pay_bank_id' => $item['pay_bank_id'] ?? null,
                ':pay_bank_code' => $item['pay_bank_code'] ?? null, ':pay_bank_name' => $item['pay_bank_name'] ?? null,
                ':pay_bank_no' => $bankNoEnc['value'] ?? null, ':deduct_sso' => $deductSso,
                ':key_version' => $bankNoEnc['key_version'] ?? null,
                ':working_days' => $item['working_days'] ?? null, ':working_mins' => $item['working_mins'] ?? null,
                ':absent_days' => $item['absent_days'] ?? null, ':absent_mins' => $item['absent_mins'] ?? null,
                ':late_mins' => $item['late_mins'] ?? null, ':early_mins' => $item['early_mins'] ?? null,
                ':ot_mins' => $item['ot_mins'] ?? null, ':ot_req_hrs' => $item['ot_req_hrs'] ?? null,
                ':ot_req_working_day_hrs' => $item['ot_req_working_day_hrs'] ?? null,
                ':ot_req_weekend_hrs' => $item['ot_req_weekend_hrs'] ?? null, ':ot_req_holiday_hrs' => $item['ot_req_holiday_hrs'] ?? null,
                ':leave_approve_days' => $item['leave_approve_days'] ?? null, ':leave_wait_days' => $item['leave_wait_days'] ?? null,
                ':leave_without_pay_days' => $item['leave_without_pay_days'] ?? null, ':trip_allowance' => $item['trip_allowance'] ?? null,
                ':item_values' => isset($item['item_values']) ? json_encode($item['item_values'], JSON_UNESCAPED_UNICODE) : null,
            ]);
        }
        return $unmappedCount;
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

    private function resolveEmployeeId(int $compId, string $payrollCode): ?int {
        $stmt = $this->db->prepare("SELECT id FROM employees WHERE comp_id = :comp_id AND employee_no = :employee_no AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':comp_id' => $compId, ':employee_no' => $payrollCode]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /** Sync processes not yet pulled into a payroll run -- the Payroll Process page's "Pending Pull" station. */
    public function pendingList(int $compId): array {
        $stmt = $this->db->prepare("SELECT p.id, p.origami_process_id, p.process_no, p.origami_comp_name, p.period_name, p.frequency_type,
                p.item_count, p.unmapped_item_count, p.received_at
            FROM payroll_sync_processes p
            LEFT JOIN payroll_runs r ON r.sync_process_id = p.id
            WHERE p.comp_id = :comp_id AND r.id IS NULL
            ORDER BY p.received_at DESC");
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Full detail for the "View" modal on a single pending row -- header + the per-employee items
     * (item_values JSON decoded back into an array for the frontend) + employee_status snapshot.
     * Not scoped to "still pending" -- once pulled it's still fine to look back at what was sent.
     */
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
            $bankNo = EncryptionService::decrypt($item['pay_bank_no'] ?? null, isset($item['key_version']) ? (int)$item['key_version'] : null);
            $item['pay_bank_no_masked'] = $bankNo !== null && strlen($bankNo) > 4 ? str_repeat('x', strlen($bankNo) - 4) . substr($bankNo, -4) : $bankNo;
            unset($item['pay_bank_no'], $item['key_version']);
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
