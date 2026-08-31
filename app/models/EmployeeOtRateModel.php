<?php
declare(strict_types=1);

require_once __DIR__ . '/OtRateSetModel.php';

/**
 * Per-employee OT rate override -- explicit request: "OT Rate เพิ่มให้สามารถ Assing รายบุคคลได้ด้วย เช่น
 * คนนี้ Rate ไม่เหมือนเพื่อน ได้ทั้งตัวคูณ และเป็นจำนวนเงิน เช่น ชั่วโมงละ 100 หรือ Base จากฐานเงินเงิน...ให้ไป Set
 * แยก ใน Employee ใน Tab ที่มีการติ๊กว่า ได้รับ OT ไหม ถ้ามีสิทธิ์ได้รับ OT ให้เลือกเพิ่มว่า จากการตั้งค่าหลัก หรือ
 * จะตั้งค่าแยก ตามประเภท OT".
 *
 * `employees.ot_rate_source` ('default'/'custom') is an EXPLICIT choice, not implied by whether
 * override rows exist -- lets an admin flip back to the company default without losing whatever
 * custom rows they already typed in. `employee_ot_rate_overrides` is one row per (employee,
 * ot_scope) -- same field shape a single OT Rate Set item has (multiplier_rate/calculation_base/
 * calculation_method/flat_amount_rate), delete+reinsert the whole set on every save (same pattern as
 * holiday_assignments/approval_workflow_step_approvers -- no in-flight state of its own worth
 * diffing). A scope with NO override row for this employee (even while ot_rate_source='custom')
 * falls back to this employee's own RESOLVED OT Rate Set for that scope (2026-08-30 --
 * OtRateSetModel::resolveRatesForEmployees(), replacing the old flat company-wide `ot_rates` table)
 * -- same "unassigned = general, explicitly assigned = scoped" convention already used for
 * Holiday/Payslip Template assignment.
 *
 * The actual per-employee resolution used by real payroll calculation lives in
 * SyncPayResolver::resolve()'s own OT block (not here), fed by PayrollRunModel::recalculate()'s
 * prefetched $otOverridesByEmployee/$otRateSetRatesByEmployee (see that method's own docblock for
 * why) -- SyncPayResolver::resolve() is called once per employee per run inside a tight loop, so
 * every employee's override rows AND resolved Set rates are prefetched ONCE up front (same "prefetch
 * outside the loop" convention every other per-employee lookup in that method already follows)
 * rather than each resolve() call querying this model/OtRateSetModel directly.
 */
class EmployeeOtRateModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /**
     * Full state for the Employee Detail Salary tab's OT section -- ot_rate_source plus one row per
     * active OT scope, each carrying the RESOLVED rate (this employee's own override if one exists,
     * else the company-wide default) plus `has_override`/`has_company_default` so the UI can show
     * which scopes are actually customized vs. just inheriting a fallback.
     */
    public function getForEmployee(int $employeeId, int $compId): array {
        $stmtSource = $this->db->prepare("SELECT ot_rate_source, department_id, team_id, position_id, assigned_ot_rate_set_id FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtSource->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $empRow = $stmtSource->fetch(PDO::FETCH_ASSOC);
        if ($empRow === false) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $otRateSource = $empRow['ot_rate_source'];

        $scopes = $this->db->query("SELECT id, code, name_th, name_en FROM master_ot_scope_types WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);

        $stmtOverrides = $this->db->prepare("SELECT * FROM employee_ot_rate_overrides WHERE employee_id = :employee_id AND comp_id = :comp_id");
        $stmtOverrides->execute([':employee_id' => $employeeId, ':comp_id' => $compId]);
        $overridesByScope = [];
        foreach ($stmtOverrides->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $overridesByScope[(int)$row['ot_scope_id']] = $row;
        }

        // 2026-08-30 (OT Rate Set replacement) -- "the company default" for a SPECIFIC employee is now
        // that employee's own resolved Set (explicit assigned_ot_rate_set_id, else
        // employee>team>position>department assignment, else the mandatory Default Set), not a single
        // flat company-wide rate per scope -- see OtRateSetModel's own docblock.
        $otRateSetModel = new OtRateSetModel($this->db);
        $resolved = $otRateSetModel->resolveRatesForEmployees([[
            'id' => $employeeId, 'department_id' => $empRow['department_id'], 'team_id' => $empRow['team_id'],
            'position_id' => $empRow['position_id'], 'assigned_ot_rate_set_id' => $empRow['assigned_ot_rate_set_id'],
        ]], $compId);
        $resolvedSetId = $resolved[$employeeId]['set_id'] ?? null;
        $defaultsByScope = $resolved[$employeeId]['rates'] ?? [];

        // Explicit request: "แต่ถ้าพนักงานคนไหนที่มีการเลือกว่าได้รับ OT แต่ยังไม่เลือกว่า OT รายการไหน ให้
        // Default เป็นรายการที่ใกล้เคียง ถ้ามีการ Assign ให้ ทีมเลือกทีม ตำแหน่งเลือกตำแหน่ง แผนก เลือกแผนก
        // ตามลำดับ" -- the Recommend hint needs what auto-resolution WOULD pick even when an explicit
        // pick already exists (so switching back to "no explicit pick" shows what it'll fall back to),
        // so this is resolved a second time with assigned_ot_rate_set_id forced null.
        $recommended = $otRateSetModel->resolveRatesForEmployees([[
            'id' => $employeeId, 'department_id' => $empRow['department_id'], 'team_id' => $empRow['team_id'],
            'position_id' => $empRow['position_id'], 'assigned_ot_rate_set_id' => null,
        ]], $compId);
        $recommendedSetId = $recommended[$employeeId]['set_id'] ?? null;
        $recommendedSetName = null;
        if ($recommendedSetId !== null) {
            $recSet = $otRateSetModel->get((int)$recommendedSetId, $compId);
            $recommendedSetName = $recSet ? ['name_th' => $recSet['name_th'], 'name_en' => $recSet['name_en']] : null;
        }
        // Display text for the explicit pick itself (if any) -- needed so the select2-remote picker
        // can be preloaded with a real <option> before .val() is set (see detail.js's own
        // populateSelect2Field()-style precedent -- a select2-remote with no option preloaded yet
        // silently ignores a plain .val(id)).
        $assignedSetName = null;
        if ($empRow['assigned_ot_rate_set_id'] !== null) {
            $assignedSet = $otRateSetModel->get((int)$empRow['assigned_ot_rate_set_id'], $compId);
            $assignedSetName = $assignedSet ? ['name_th' => $assignedSet['name_th'], 'name_en' => $assignedSet['name_en']] : null;
        }

        $result = [];
        foreach ($scopes as $scope) {
            $scopeId = (int)$scope['id'];
            $override = $overridesByScope[$scopeId] ?? null;
            $default = $defaultsByScope[$scope['code']] ?? null;
            $source = $override ?? $default;
            $result[] = [
                'ot_scope_id' => $scopeId, 'scope_code' => $scope['code'],
                'scope_name_th' => $scope['name_th'], 'scope_name_en' => $scope['name_en'],
                'has_override' => $override !== null,
                'has_company_default' => $default !== null,
                'multiplier_rate' => $source !== null ? (float)$source['multiplier_rate'] : 1.5,
                'calculation_base' => $source !== null ? (string)$source['calculation_base'] : 'hourly',
                'calculation_method' => $source !== null ? (string)$source['calculation_method'] : 'multiplier',
                'flat_amount_rate' => ($source !== null && $source['flat_amount_rate'] !== null) ? (float)$source['flat_amount_rate'] : null,
            ];
        }
        return [
            'status' => true, 'ot_rate_source' => (string)$otRateSource,
            'assigned_ot_rate_set_id' => $empRow['assigned_ot_rate_set_id'] !== null ? (int)$empRow['assigned_ot_rate_set_id'] : null,
            'assigned_ot_rate_set_name' => $assignedSetName,
            'resolved_ot_rate_set_id' => $resolvedSetId,
            'recommended_ot_rate_set_id' => $recommendedSetId, 'recommended_ot_rate_set_name' => $recommendedSetName,
            'scopes' => $result,
        ];
    }

    /**
     * Batch version of getForEmployee() for a LIST of employees at once (Employee List's "Recheck
     * ข้อมูล" tab, explicit request: "เพิ่ม Column OT เพิ่มว่าคิดหรือไม่คิด ถ้าคิดคิด Rate ของ OT แต่ละ
     * ประเภท") -- avoids N+1 queries by prefetching every override row + the company's OT rate
     * defaults ONCE (same "prefetch outside the per-row loop" convention
     * PayrollRunModel::recalculate() itself already uses for this exact table), instead of calling
     * getForEmployee() once per row.
     * @param array<int,array{id:int,ot_eligible:mixed,ot_rate_source:string,department_id:?int,team_id:?int,position_id:?int,assigned_ot_rate_set_id:?int}> $employeeRows
     *        each row must carry its own id/ot_eligible/ot_rate_source/department_id/team_id/
     *        position_id/assigned_ot_rate_set_id -- the caller's own already-fetched SELECT, not
     *        re-queried here.
     * @return array<int,array{eligible:bool,rate_source:string,scopes:array}> keyed by employee_id
     */
    public function summaryForEmployees(array $employeeRows, int $compId): array {
        $scopes = $this->db->query("SELECT id, code, name_th, name_en FROM master_ot_scope_types WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);

        // 2026-08-30 (OT Rate Set replacement) -- "default" is no longer a single flat company-wide
        // rate per scope; it's now THIS employee's own resolved Set (explicit assigned_ot_rate_set_id,
        // else employee>team>position>department assignment match, else the mandatory company Default
        // Set) which can genuinely differ per employee. Batched via OtRateSetModel's own
        // resolveRatesForEmployees() (same "prefetch once, not per row" convention this method already
        // followed for the old flat table).
        $otRateSetModel = new OtRateSetModel($this->db);
        $ratesByEmployee = $otRateSetModel->resolveRatesForEmployees($employeeRows, $compId);

        $stmtOverrides = $this->db->prepare("SELECT * FROM employee_ot_rate_overrides WHERE comp_id = :comp_id");
        $stmtOverrides->execute([':comp_id' => $compId]);
        $overridesByEmployeeScope = [];
        foreach ($stmtOverrides->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $overridesByEmployeeScope[(int)$row['employee_id']][(int)$row['ot_scope_id']] = $row;
        }

        $result = [];
        foreach ($employeeRows as $emp) {
            $employeeId = (int)$emp['id'];
            $rateSource = (string)($emp['ot_rate_source'] ?? 'default');
            $defaultsByScope = $ratesByEmployee[$employeeId]['rates'] ?? [];
            $scopeSummaries = [];
            foreach ($scopes as $scope) {
                $scopeId = (int)$scope['id'];
                $override = ($rateSource === 'custom') ? ($overridesByEmployeeScope[$employeeId][$scopeId] ?? null) : null;
                $default = $defaultsByScope[$scope['code']] ?? null;
                $source = $override ?? $default;
                $scopeSummaries[] = [
                    'scope_code' => $scope['code'], 'scope_name_th' => $scope['name_th'], 'scope_name_en' => $scope['name_en'],
                    'is_override' => $override !== null,
                    'has_rate' => $source !== null,
                    'multiplier_rate' => $source !== null ? (float)$source['multiplier_rate'] : null,
                    'calculation_method' => $source !== null ? (string)$source['calculation_method'] : null,
                    'flat_amount_rate' => ($source !== null && $source['flat_amount_rate'] !== null) ? (float)$source['flat_amount_rate'] : null,
                    'calculation_base' => $source !== null ? (string)$source['calculation_base'] : null,
                ];
            }
            $result[$employeeId] = ['eligible' => !empty($emp['ot_eligible']), 'rate_source' => $rateSource, 'scopes' => $scopeSummaries];
        }
        return $result;
    }

    /**
     * @param array $overrides list of {ot_scope_id, calculation_base, calculation_method, multiplier_rate?, flat_amount_rate?}
     * @param ?int $assignedOtRateSetId explicit "which OT Rate Set" pick (2026-08-30, "ถ้าเลือกจาก OT
     *        ของระบบ จะมีให้เลือกเพิ่มว่า OT ไหน") -- only meaningful while $otRateSource='default'; null
     *        means "no explicit pick, auto-resolve via employee>team>position>department>Default"
     *        (see OtRateSetModel::resolveRatesForEmployees()). Always written exactly as passed
     *        (including null, to genuinely clear a previous explicit pick) -- this is the ONE
     *        dedicated endpoint for these 2 columns, so there's no "field absent from payload" risk
     *        the way a generic EmployeeModel::save() call would have.
     */
    public function save(int $employeeId, int $compId, string $otRateSource, array $overrides, int $userId, ?int $assignedOtRateSetId = null): array {
        if (!in_array($otRateSource, ['default', 'custom'], true)) {
            return ['status' => false, 'message' => 'Invalid ot_rate_source.'];
        }
        $stmtCheck = $this->db->prepare("SELECT id FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtCheck->execute([':id' => $employeeId, ':comp_id' => $compId]);
        if (!$stmtCheck->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($assignedOtRateSetId !== null) {
            $stmtSet = $this->db->prepare("SELECT id FROM ot_rate_sets WHERE id = :id AND comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL");
            $stmtSet->execute([':id' => $assignedOtRateSetId, ':comp_id' => $compId]);
            if (!$stmtSet->fetch()) {
                return ['status' => false, 'message' => 'Selected OT Rate Set not found.'];
            }
        }

        // Validate every row up front -- reject the whole save on any bad row, not a partial write.
        $validated = [];
        foreach ($overrides as $row) {
            $scopeId = (int)($row['ot_scope_id'] ?? 0);
            $stmtScope = $this->db->prepare("SELECT id FROM master_ot_scope_types WHERE id = :id AND is_active = 1");
            $stmtScope->execute([':id' => $scopeId]);
            if (!$stmtScope->fetch()) {
                return ['status' => false, 'message' => 'Invalid OT scope.'];
            }
            $calcBase = in_array($row['calculation_base'] ?? '', ['hourly', 'daily'], true) ? $row['calculation_base'] : 'hourly';
            $calcMethod = in_array($row['calculation_method'] ?? '', ['multiplier', 'flat_amount'], true) ? $row['calculation_method'] : 'multiplier';
            $multiplier = 1.00;
            $flatAmountRate = null;
            if ($calcMethod === 'flat_amount') {
                $flatAmountRate = (float)($row['flat_amount_rate'] ?? 0);
                if ($flatAmountRate <= 0) {
                    return ['status' => false, 'message' => 'flat_amount_rate must be greater than 0.'];
                }
            } else {
                $multiplier = (float)($row['multiplier_rate'] ?? 0);
                if ($multiplier <= 0) {
                    return ['status' => false, 'message' => 'multiplier_rate must be greater than 0.'];
                }
            }
            $validated[$scopeId] = ['calc_base' => $calcBase, 'calc_method' => $calcMethod, 'multiplier' => $multiplier, 'flat_amount_rate' => $flatAmountRate];
        }

        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            $this->db->prepare("UPDATE employees SET ot_rate_source = :source, assigned_ot_rate_set_id = :set_id, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND comp_id = :comp_id")
                ->execute([':source' => $otRateSource, ':set_id' => $assignedOtRateSetId, ':updated_by' => $userId, ':id' => $employeeId, ':comp_id' => $compId]);
            $this->db->prepare("DELETE FROM employee_ot_rate_overrides WHERE employee_id = :employee_id AND comp_id = :comp_id")
                ->execute([':employee_id' => $employeeId, ':comp_id' => $compId]);
            if ($otRateSource === 'custom' && !empty($validated)) {
                $stmtIns = $this->db->prepare("INSERT INTO employee_ot_rate_overrides
                    (comp_id, employee_id, ot_scope_id, multiplier_rate, calculation_base, calculation_method, flat_amount_rate, created_by)
                    VALUES (:comp_id, :employee_id, :ot_scope_id, :multiplier, :calc_base, :calc_method, :flat_amount_rate, :created_by)");
                foreach ($validated as $scopeId => $v) {
                    $stmtIns->execute([
                        ':comp_id' => $compId, ':employee_id' => $employeeId, ':ot_scope_id' => $scopeId,
                        ':multiplier' => $v['multiplier'], ':calc_base' => $v['calc_base'], ':calc_method' => $v['calc_method'],
                        ':flat_amount_rate' => $v['flat_amount_rate'], ':created_by' => $userId,
                    ]);
                }
            }
            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
        return ['status' => true, 'message' => 'Saved successfully.'];
    }
}
