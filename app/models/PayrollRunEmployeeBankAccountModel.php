<?php
declare(strict_types=1);
require_once __DIR__ . '/PayrollReportDataModel.php';
require_once __DIR__ . '/../services/reports/LocalizedException.php';

/**
 * 2026-09-02, explicit request: "ในการตั้งค่ารอบการจ่าย ปรับให้รองรับมากกว่า 1 บัญชี และในหน้า Detail ก็
 * สามารถเลือกได้ว่าใครจะโอนผ่านบัญชีไหนในกลุ่มที่รับเงินผ่านบัญชี...ในหน้า Detail ของ Process เพิ่ม Tab ให้จัดการ
 * ข้อมูลส่วนนี้ได้ และมี Report แยกตามบัญชีที่จ่าย และตอนออกรายงานเพื่อส่ง Cashlink ต้องถูกต้อง".
 *
 * `bank_accounts` (company's own settlement accounts, multiple already supported) and
 * `payroll_cycles.bank_account_id` (a cycle can already pin ONE account) already existed. This
 * model adds the missing PER-EMPLOYEE layer: `employees.default_bank_account_id` is the employee's
 * own template default; `payroll_run_employee_bank_accounts` is a per-run-only override, mirroring
 * `payroll_run_recurring_deduction_overrides`'s own "template default + per-run override, template
 * never touched" convention (see that table's own migration/model for the precedent this copies).
 *
 * Deliberately a LIVE resolution, not a snapshot-materialized table (unlike
 * PayrollRunCashPaymentModel's own ensureRowsForRun(), which snapshots an amount at a point in
 * time) -- there's nothing here that needs to survive a later recalculate() the way a cash-payment
 * status does, and re-resolving on every read keeps the override table itself the only source of
 * per-run truth (no risk of a stale snapshot disagreeing with what an admin actually configured).
 *
 * Same ALLOWED_STATES gate as PayrollRunCashPaymentModel/BankTransferFileReport (bank-account
 * assignment is a DISBURSEMENT concern, not a calculation one -- see
 * project_deduction_destination_remittance_2026_09_02's own Calc-vs-Disbursement doc for that same
 * distinction applied elsewhere) -- only meaningful once a run's numbers are final.
 */
class PayrollRunEmployeeBankAccountModel {
    private const ALLOWED_STATES = ['approved', 'paid', 'locked'];
    private PDO $db;
    private PayrollReportDataModel $dataModel;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->dataModel = new PayrollReportDataModel($this->db);
    }

    private function companyDefaultAccountId(int $compId): ?int {
        $stmt = $this->db->prepare("SELECT id FROM `bank_accounts`
            WHERE comp_id = :comp_id AND deleted_at IS NULL AND status = 'active' AND is_default = 1
            ORDER BY id ASC LIMIT 1");
        $stmt->execute([':comp_id' => $compId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    }

    /**
     * Resolves which `bank_accounts` row pays each bank-paying (payment_method_code='transfer')
     * employee in this run. Resolution order: this run's own override > the employee's own
     * default_bank_account_id > the run's cycle's pinned bank_account_id > the company's
     * is_default=1 account. `bank_account_id` is null only when NONE of the 4 resolve (no override,
     * no employee default, no cycle pin, and no company default configured at all) -- a real,
     * reportable gap, not silently hidden.
     * 2026-09-02, mixed payment method: deliberately NOT included here -- a mixed employee's own
     * transfer LINE already carries its own bank_account_id (chosen per-line at Employee Detail time,
     * see EmployeePaymentMethodModel), so there's nothing for this per-RUN override mechanism to
     * apply to. BankTransferFileReport::generate() reads a mixed employee's transfer routing straight
     * off that line, never through this method.
     * @return array<int, array{bank_account_id: ?int, source: string}> keyed by employee_id
     */
    public function resolveForRun(int $runId, int $compId): array {
        $run = $this->dataModel->getRun($runId, $compId);
        if (!$run) {
            throw new LocalizedException('Payroll run not found.', 'run_not_found');
        }
        $details = $this->dataModel->getRunDetails($runId);
        $bankEmployeeIds = array_values(array_map(
            fn($d) => (int)$d['employee_id'],
            array_filter($details, fn($d) => ($d['payment_method_code'] ?? 'transfer') === 'transfer')
        ));
        if (empty($bankEmployeeIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($bankEmployeeIds), '?'));
        $overridesByEmployee = [];
        $stmtOv = $this->db->prepare("SELECT employee_id, bank_account_id FROM `payroll_run_employee_bank_accounts` WHERE run_id = ? AND employee_id IN ({$placeholders})");
        $stmtOv->execute(array_merge([$runId], $bankEmployeeIds));
        foreach ($stmtOv->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $overridesByEmployee[(int)$row['employee_id']] = (int)$row['bank_account_id'];
        }

        $employeeDefaults = [];
        $stmtEmp = $this->db->prepare("SELECT id, default_bank_account_id FROM `employees` WHERE id IN ({$placeholders})");
        $stmtEmp->execute($bankEmployeeIds);
        foreach ($stmtEmp->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $employeeDefaults[(int)$row['id']] = $row['default_bank_account_id'] !== null ? (int)$row['default_bank_account_id'] : null;
        }

        $cycleAccountId = !empty($run['bank_account_id']) ? (int)$run['bank_account_id'] : null;
        $companyDefaultId = $this->companyDefaultAccountId($compId);

        $result = [];
        foreach ($bankEmployeeIds as $employeeId) {
            if (isset($overridesByEmployee[$employeeId])) {
                $result[$employeeId] = ['bank_account_id' => $overridesByEmployee[$employeeId], 'source' => 'override'];
            } elseif (!empty($employeeDefaults[$employeeId])) {
                $result[$employeeId] = ['bank_account_id' => $employeeDefaults[$employeeId], 'source' => 'employee_default'];
            } elseif ($cycleAccountId !== null) {
                $result[$employeeId] = ['bank_account_id' => $cycleAccountId, 'source' => 'cycle'];
            } else {
                $result[$employeeId] = ['bank_account_id' => $companyDefaultId, 'source' => 'company_default'];
            }
        }
        return $result;
    }

    /** For Process Detail's "Bank Account Assignment" tab -- one row per bank-paying employee, the
     *  resolved account + label, and whether it's overridden for this run specifically. */
    public function listForRun(int $runId, int $compId): array {
        $run = $this->dataModel->getRun($runId, $compId);
        if (!$run) {
            throw new LocalizedException('Payroll run not found.', 'run_not_found');
        }
        $this->dataModel->assertRunStateOrThrow($run, self::ALLOWED_STATES);

        $resolved = $this->resolveForRun($runId, $compId);
        if (empty($resolved)) {
            return [];
        }

        $details = $this->dataModel->getRunDetails($runId);
        $detailsByEmployee = [];
        foreach ($details as $d) {
            $detailsByEmployee[(int)$d['employee_id']] = $d;
        }

        $accountIds = array_values(array_unique(array_filter(array_column($resolved, 'bank_account_id'))));
        $accountLabels = [];
        if (!empty($accountIds)) {
            $ph = implode(',', array_fill(0, count($accountIds), '?'));
            $stmt = $this->db->prepare("SELECT ba.id, ba.account_name, mb.bank_name_th, mb.bank_name_en
                FROM `bank_accounts` ba LEFT JOIN `master_banks` mb ON mb.id = ba.bank_id WHERE ba.id IN ({$ph})");
            $stmt->execute($accountIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $accountLabels[(int)$row['id']] = $row;
            }
        }

        $rows = [];
        foreach ($resolved as $employeeId => $r) {
            $d = $detailsByEmployee[$employeeId] ?? [];
            $accId = $r['bank_account_id'];
            $label = $accId !== null ? ($accountLabels[$accId] ?? null) : null;
            $rows[] = [
                'employee_id' => $employeeId,
                'employee_no' => $d['employee_no'] ?? '',
                'name_th' => $d['name_th'] ?? '', 'surname_th' => $d['surname_th'] ?? '',
                'name_en' => $d['name_en'] ?? '', 'surname_en' => $d['surname_en'] ?? '',
                // 2026-09-11, Batch 3C item 8: employeeHeaderCardHtml() (app.js) needs these for the
                // Bank Account Assignment modal's own header card -- already available on $d via
                // PayrollReportDataModel::getRunDetails()'s own JOINs, just never copied through
                // this method's own hand-picked row shape before now.
                'profile_photo_path' => $d['profile_photo_path'] ?? null,
                'department_name_th' => $d['department_name_th'] ?? null, 'department_name_en' => $d['department_name_en'] ?? null,
                'position_name_th' => $d['position_name_th'] ?? null, 'position_name_en' => $d['position_name_en'] ?? null,
                'bank_account_id' => $accId,
                'bank_account_name' => $label['account_name'] ?? null,
                'bank_name_th' => $label['bank_name_th'] ?? null,
                'bank_name_en' => $label['bank_name_en'] ?? null,
                'source' => $r['source'],
                'is_overridden' => $r['source'] === 'override',
            ];
        }
        usort($rows, fn($a, $b) => strcmp((string)$a['employee_no'], (string)$b['employee_no']));
        return $rows;
    }

    private function validateAccountBelongsToComp(int $compId, int $bankAccountId): bool {
        $stmt = $this->db->prepare("SELECT id FROM `bank_accounts` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL AND status = 'active'");
        $stmt->execute([':id' => $bankAccountId, ':comp_id' => $compId]);
        return (bool)$stmt->fetch();
    }

    /** Saves (creates or updates) this run's own override of one employee's paying account --
     *  never writes to employees.default_bank_account_id (that's a separate, explicit action via
     *  Employee Detail's own save, untouched by this). */
    public function overrideSave(int $runId, int $compId, int $employeeId, int $bankAccountId, ?string $note, int $userId): array {
        $run = $this->dataModel->getRun($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if (!in_array($run['state'], self::ALLOWED_STATES, true)) {
            return ['status' => false, 'message' => 'Bank account assignment is only available once this run is approved.'];
        }
        $stmtEmp = $this->db->prepare("SELECT id FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
        if (!$stmtEmp->fetch()) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }
        if (!$this->validateAccountBelongsToComp($compId, $bankAccountId)) {
            return ['status' => false, 'message' => 'Invalid bank account.'];
        }
        $note = $note !== null ? trim($note) : null;
        $note = $note !== '' ? $note : null;

        $stmtExisting = $this->db->prepare("SELECT id FROM `payroll_run_employee_bank_accounts` WHERE run_id = :run_id AND employee_id = :employee_id");
        $stmtExisting->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        $existingId = $stmtExisting->fetchColumn();
        if ($existingId) {
            $this->db->prepare("UPDATE `payroll_run_employee_bank_accounts` SET bank_account_id = :bank_account_id, note = :note, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':bank_account_id' => $bankAccountId, ':note' => $note, ':updated_by' => $userId, ':id' => $existingId]);
        } else {
            $this->db->prepare("INSERT INTO `payroll_run_employee_bank_accounts` (run_id, employee_id, bank_account_id, note, created_by) VALUES (:run_id, :employee_id, :bank_account_id, :note, :created_by)")
                ->execute([':run_id' => $runId, ':employee_id' => $employeeId, ':bank_account_id' => $bankAccountId, ':note' => $note, ':created_by' => $userId]);
        }
        return ['status' => true];
    }

    /** Removes this run's own override, reverting to the template resolution order (employee
     *  default > cycle pin > company default) for this run only -- nothing else is touched. */
    public function overrideRemove(int $runId, int $compId, int $employeeId): array {
        $run = $this->dataModel->getRun($runId, $compId);
        if (!$run) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $this->db->prepare("DELETE FROM `payroll_run_employee_bank_accounts` WHERE run_id = :run_id AND employee_id = :employee_id")
            ->execute([':run_id' => $runId, ':employee_id' => $employeeId]);
        return ['status' => true];
    }
}
