<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/SyncPayResolver.php';

/**
 * Company-wide OT Rate Sets -- explicit request: "การจัดการ OT ตอนนี้สร้างได้เรื่อยๆ ถ้าตอนที่นำไปคำนวณ ถ้า
 * บันทึกข้อมูลซ้ำ แต่คนละ Rate จะแก้ไขยังไง...ตอนกดบวกรายการ ให้ขึ้นมาเลยเป็นชุดของ OT Type แล้วมี form ในแต่ละ
 * Type ให้ระบุ...เท่ากับว่า 1 ชุดข้อมูลมีทุก Type ให้จัดการ แต่สามารถจัดการแยกกันได้แต่ละ type ในแถวเดียวกัน และให้
 * เพิ่มการ Assign ให้ด้วย ว่ามีผลกับแผนก ทีม ตำแหน่ง หรือพนักงานคนไหน และป้องกันการบันทึกซ้ำ...บังคับไปเลยว่าต้องมี
 * Default".
 *
 * Fully REPLACES the old flat `ot_rates` table (confirmed with the user: zero real rows existed on
 * this live company at the time of replacement). One Set (`ot_rate_sets`) bundles ALL 3 OT types
 * (weekday/weekend/holiday, `master_ot_scope_types`) as its own independently-configurable sub-rows
 * (`ot_rate_set_items`) -- an admin picks multiplier-vs-flat-amount and the actual rate PER TYPE
 * within the same Set, not one flat row per type with no relationship between them (the old design's
 * own ambiguity problem: multiple `ot_rates` rows could exist for the same scope with different
 * rates and nothing picked a winner except an arbitrary `ORDER BY id ASC LIMIT 1`).
 *
 * A Set can be ASSIGNED to specific department/team/position/employee scopes
 * (`ot_rate_set_assignments`, same polymorphic-scope shape as `holiday_assignments` -- "team" added,
 * "shift" dropped, neither concept applies to OT eligibility the same way). "ป้องกันการบันทึกซ้ำ" is
 * enforced by `findConflictingAssignment()` below across EVERY active Set (not just within one) --
 * same "findConflictingAssignment()" precedent Payslip/Employment Certificate Template's own
 * "Assign To" already established. Resolution priority for an employee matching more than one
 * assigned scope, confirmed with the user via AskUserQuestion: **employee > team > position >
 * department** (most-specific-wins, same convention as Holiday's own
 * employee>position>department>shift resolver -- just a different concrete ranking, confirmed
 * explicitly since it genuinely differs).
 *
 * Exactly ONE Set per company must be `is_default` = the mandatory fallback for an OT-eligible
 * employee who matches no assignment at all ("บังคับไปเลยว่าต้องมี Default เพื่อดึงไปใช้กับพนักงานที่มีการ
 * ติ๊กให้รับ OT แต่ยังไม่ได้ Assign OT ให้") -- enforced at the application layer in save() (the very
 * FIRST Set a company ever creates is force-defaulted regardless of what was submitted, since leaving
 * zero Sets as default would violate the "always exactly one" invariant with nothing to fall back to
 * -- every save after that can freely move the flag). The Default Set carries NO assignment rows at
 * all (it IS the catch-all) -- save() force-clears any assignments submitted for it.
 *
 * The actual per-employee resolution used by real payroll calculation is batched here
 * (resolveRatesForEmployees()) rather than resolved one employee at a time -- same "prefetch once
 * outside the per-employee loop" convention PayrollRunModel::recalculate() already follows for every
 * other per-employee lookup on that page (see EmployeeOtRateModel's own docblock for the identical
 * reasoning applied to the per-employee CUSTOM override layer, which sits ABOVE this Set-resolution
 * layer and always wins when present -- see SyncPayResolver's own OT block for exactly how the two
 * combine).
 */
class OtRateSetModel {
    private PDO $db;
    private const SCOPE_TYPES = ['department', 'team', 'position', 'employee'];
    private const SCOPE_TABLES = ['department' => 'structure_departments', 'team' => 'structure_teams', 'position' => 'structure_positions', 'employee' => 'employees'];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function otScopeOptions(): array {
        return $this->db->query("SELECT id, code, name_th AS text_th, name_en AS text_en FROM master_ot_scope_types WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function list(int $compId): array {
        $stmt = $this->db->prepare("SELECT id FROM ot_rate_sets WHERE comp_id = :comp_id AND deleted_at IS NULL ORDER BY is_default DESC, name_th ASC");
        $stmt->execute([':comp_id' => $compId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return array_values(array_filter(array_map(fn($id) => $this->get($id, $compId), $ids)));
    }

    public function get(int $id, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM ot_rate_sets WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $set = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$set) {
            return null;
        }
        $set['is_default'] = (bool)$set['is_default'];
        $set['items'] = $this->itemsForSet($id);
        $set['assignments'] = $this->assignmentsForSet($id, $compId);
        return $set;
    }

    private function itemsForSet(int $setId): array {
        $stmt = $this->db->prepare("SELECT i.*, s.code AS scope_code, s.name_th AS scope_name_th, s.name_en AS scope_name_en
            FROM ot_rate_set_items i JOIN master_ot_scope_types s ON s.id = i.ot_scope_id
            WHERE i.set_id = :set_id ORDER BY s.sort_order ASC");
        $stmt->execute([':set_id' => $setId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assignmentsForSet(int $setId, int $compId): array {
        $stmt = $this->db->prepare("SELECT id, scope_type, scope_id FROM ot_rate_set_assignments WHERE set_id = :set_id");
        $stmt->execute([':set_id' => $setId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['label'] = $this->scopeLabel($compId, (string)$r['scope_type'], (int)$r['scope_id']);
        }
        unset($r);
        return $rows;
    }

    private function scopeLabel(int $compId, string $scopeType, int $scopeId): ?string {
        $table = self::SCOPE_TABLES[$scopeType] ?? null;
        if ($table === null) {
            return null;
        }
        if ($scopeType === 'employee') {
            $stmt = $this->db->prepare("SELECT CONCAT(employee_no, ' - ', name_th, ' ', surname_th) AS label FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        } else {
            $nameCol = $scopeType . '_name_th';
            $stmt = $this->db->prepare("SELECT `{$nameCol}` AS label FROM `{$table}` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        }
        $stmt->execute([':id' => $scopeId, ':comp_id' => $compId]);
        $label = $stmt->fetchColumn();
        return $label !== false ? (string)$label : null;
    }

    private function validScopeRef(int $compId, string $scopeType, int $scopeId): bool {
        $table = self::SCOPE_TABLES[$scopeType] ?? null;
        if ($table === null) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT id FROM `{$table}` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $scopeId, ':comp_id' => $compId]);
        return (bool)$stmt->fetch();
    }

    /** Full option lists for the "Assign" checkbox UI -- same shape/precedent as Payslip/Employment
     *  Certificate Template's own assignableOptions(). */
    public function assignableOptions(int $compId): array {
        $departments = $this->db->prepare("SELECT id, department_name_th AS label FROM structure_departments WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL ORDER BY department_name_th ASC");
        $departments->execute([':comp_id' => $compId]);
        $teams = $this->db->prepare("SELECT id, team_name_th AS label FROM structure_teams WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL ORDER BY team_name_th ASC");
        $teams->execute([':comp_id' => $compId]);
        $positions = $this->db->prepare("SELECT id, position_name_th AS label FROM structure_positions WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL ORDER BY position_name_th ASC");
        $positions->execute([':comp_id' => $compId]);
        $employees = $this->db->prepare("SELECT id, CONCAT(employee_no, ' - ', name_th, ' ', surname_th) AS label FROM employees WHERE comp_id = :comp_id AND deleted_at IS NULL ORDER BY employee_no ASC");
        $employees->execute([':comp_id' => $compId]);
        return [
            'departments' => $departments->fetchAll(PDO::FETCH_ASSOC),
            'teams' => $teams->fetchAll(PDO::FETCH_ASSOC),
            'positions' => $positions->fetchAll(PDO::FETCH_ASSOC),
            'employees' => $employees->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /** Every scope already claimed by an ACTIVE Set other than $excludeSetId -- "ป้องกันการบันทึกซ้ำ". */
    private function findConflictingAssignment(int $compId, array $assignments, ?int $excludeSetId): ?array {
        if (empty($assignments)) {
            return null;
        }
        $sql = "SELECT a.scope_type, a.scope_id, s.name_th AS set_name_th
            FROM ot_rate_set_assignments a
            JOIN ot_rate_sets s ON s.id = a.set_id AND s.comp_id = :comp_id AND s.status = 'active' AND s.deleted_at IS NULL
            WHERE a.scope_type = :scope_type AND a.scope_id = :scope_id";
        if ($excludeSetId !== null) {
            $sql .= " AND a.set_id != :exclude_set_id";
        }
        $stmt = $this->db->prepare($sql);
        foreach ($assignments as $a) {
            $params = [':comp_id' => $compId, ':scope_type' => $a['scope_type'], ':scope_id' => $a['scope_id']];
            if ($excludeSetId !== null) {
                $params[':exclude_set_id'] = $excludeSetId;
            }
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return ['scope_type' => $a['scope_type'], 'scope_id' => $a['scope_id'], 'conflicting_set_name' => $row['set_name_th']];
            }
        }
        return null;
    }

    /**
     * @param array $items list of {ot_scope_id, calculation_method, multiplier_rate?, flat_amount_rate?, calculation_base}
     * @param array $assignments list of {scope_type, scope_id} -- ignored entirely (force-cleared) when is_default=true
     */
    public function save(array $data, int $compId, int $userId): array {
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;
        $nameTh = trim((string)($data['name_th'] ?? ''));
        $nameEn = trim((string)($data['name_en'] ?? ''));
        if ($nameTh === '') {
            return ['status' => false, 'message' => 'Missing required field: name_th.'];
        }
        if ($nameEn === '') {
            $nameEn = $nameTh;
        }

        // "บังคับไปเลยว่าต้องมี Default" -- if this company has no OTHER active set at all, the one
        // being saved MUST be the default regardless of what was submitted (nothing else to fall
        // back to). Otherwise honor the submitted flag.
        $stmtAnyOther = $this->db->prepare("SELECT COUNT(*) FROM ot_rate_sets WHERE comp_id = :comp_id AND deleted_at IS NULL" . ($id !== null ? " AND id != :id" : ""));
        $anyOtherParams = [':comp_id' => $compId];
        if ($id !== null) {
            $anyOtherParams[':id'] = $id;
        }
        $stmtAnyOther->execute($anyOtherParams);
        $hasAnyOtherSet = (int)$stmtAnyOther->fetchColumn() > 0;
        $isDefault = !$hasAnyOtherSet || !empty($data['is_default']);

        // Validate items -- every configured scope type needs a real calculation config; a scope
        // simply omitted from the payload is left unconfigured (no row), same as any other optional
        // sub-row list elsewhere in this app -- the UI is expected to always submit all 3 (see
        // otRateSetModal's own render), but a genuinely incomplete Set just means that scope falls
        // through to missing_ot_rate_{scope} at calculation time, same failure mode as before.
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $validatedItems = [];
        foreach ($items as $item) {
            $scopeId = (int)($item['ot_scope_id'] ?? 0);
            $stmtScope = $this->db->prepare("SELECT id FROM master_ot_scope_types WHERE id = :id AND is_active = 1");
            $stmtScope->execute([':id' => $scopeId]);
            if (!$stmtScope->fetch()) {
                return ['status' => false, 'message' => 'Invalid OT scope in items.'];
            }
            $calcBase = in_array($item['calculation_base'] ?? '', ['hourly', 'daily'], true) ? $item['calculation_base'] : 'hourly';
            $calcMethod = in_array($item['calculation_method'] ?? '', ['multiplier', 'flat_amount'], true) ? $item['calculation_method'] : 'multiplier';
            $multiplier = 1.00;
            $flatAmountRate = null;
            if ($calcMethod === 'flat_amount') {
                $flatAmountRate = (float)($item['flat_amount_rate'] ?? 0);
                if ($flatAmountRate <= 0) {
                    return ['status' => false, 'message' => 'flat_amount_rate must be greater than 0.'];
                }
            } else {
                $multiplier = (float)($item['multiplier_rate'] ?? 0);
                if ($multiplier <= 0) {
                    return ['status' => false, 'message' => 'multiplier_rate must be greater than 0.'];
                }
            }
            $validatedItems[$scopeId] = ['calc_base' => $calcBase, 'calc_method' => $calcMethod, 'multiplier' => $multiplier, 'flat_amount_rate' => $flatAmountRate];
        }

        // Validate + de-dupe assignments (force-cleared entirely when is_default -- the Default set
        // is the catch-all, it never needs/uses explicit assignment rows).
        $assignments = [];
        if (!$isDefault) {
            $rawAssignments = is_array($data['assignments'] ?? null) ? $data['assignments'] : [];
            $seen = [];
            foreach ($rawAssignments as $a) {
                $scopeType = (string)($a['scope_type'] ?? '');
                $scopeId = (int)($a['scope_id'] ?? 0);
                if (!in_array($scopeType, self::SCOPE_TYPES, true) || $scopeId <= 0) {
                    return ['status' => false, 'message' => 'Invalid assignment row.'];
                }
                if (!$this->validScopeRef($compId, $scopeType, $scopeId)) {
                    return ['status' => false, 'message' => 'One of the assigned departments/teams/positions/employees does not belong to this company.'];
                }
                $key = $scopeType . ':' . $scopeId;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $assignments[] = ['scope_type' => $scopeType, 'scope_id' => $scopeId];
            }
            $conflict = $this->findConflictingAssignment($compId, $assignments, $id);
            if ($conflict !== null) {
                $scopeLabel = $this->scopeLabel($compId, $conflict['scope_type'], $conflict['scope_id']) ?? "#{$conflict['scope_id']}";
                return ['status' => false, 'message' => "'{$scopeLabel}' is already assigned to another active OT Rate Set ('{$conflict['conflicting_set_name']}')."];
            }
        }

        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM ot_rate_sets WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    if ($own) { $this->db->rollBack(); }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                if ($isDefault) {
                    $this->db->prepare("UPDATE ot_rate_sets SET is_default = 0 WHERE comp_id = :comp_id AND id != :id")->execute([':comp_id' => $compId, ':id' => $id]);
                }
                $this->db->prepare("UPDATE ot_rate_sets SET name_th = :th, name_en = :en, is_default = :is_default, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                    ->execute([':th' => $nameTh, ':en' => $nameEn, ':is_default' => $isDefault ? 1 : 0, ':updated_by' => $userId, ':id' => $id]);
                $setId = $id;
            } else {
                if ($isDefault) {
                    $this->db->prepare("UPDATE ot_rate_sets SET is_default = 0 WHERE comp_id = :comp_id")->execute([':comp_id' => $compId]);
                }
                $this->db->prepare("INSERT INTO ot_rate_sets (comp_id, name_th, name_en, is_default, created_by) VALUES (:comp_id, :th, :en, :is_default, :created_by)")
                    ->execute([':comp_id' => $compId, ':th' => $nameTh, ':en' => $nameEn, ':is_default' => $isDefault ? 1 : 0, ':created_by' => $userId]);
                $setId = (int)$this->db->lastInsertId();
            }

            $this->db->prepare("DELETE FROM ot_rate_set_items WHERE set_id = :set_id")->execute([':set_id' => $setId]);
            if (!empty($validatedItems)) {
                $stmtIns = $this->db->prepare("INSERT INTO ot_rate_set_items (set_id, ot_scope_id, calculation_method, multiplier_rate, calculation_base, flat_amount_rate)
                    VALUES (:set_id, :ot_scope_id, :calc_method, :multiplier, :calc_base, :flat_amount_rate)");
                foreach ($validatedItems as $scopeId => $v) {
                    $stmtIns->execute([
                        ':set_id' => $setId, ':ot_scope_id' => $scopeId, ':calc_method' => $v['calc_method'],
                        ':multiplier' => $v['multiplier'], ':calc_base' => $v['calc_base'], ':flat_amount_rate' => $v['flat_amount_rate'],
                    ]);
                }
            }

            $this->db->prepare("DELETE FROM ot_rate_set_assignments WHERE set_id = :set_id")->execute([':set_id' => $setId]);
            if (!empty($assignments)) {
                $stmtAssignIns = $this->db->prepare("INSERT INTO ot_rate_set_assignments (set_id, scope_type, scope_id) VALUES (:set_id, :scope_type, :scope_id)");
                foreach ($assignments as $a) {
                    $stmtAssignIns->execute([':set_id' => $setId, ':scope_type' => $a['scope_type'], ':scope_id' => $a['scope_id']]);
                }
            }

            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
        return ['status' => true, 'message' => 'Saved successfully.', 'id' => $setId];
    }

    /** The Default set can never be deleted (same "mandatory invariant" reasoning as save()'s own
     *  force-default-the-first-set rule) -- an admin must set a DIFFERENT set as default first. */
    public function delete(int $id, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT is_default FROM ot_rate_sets WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ((int)$row['is_default'] === 1) {
            return ['status' => false, 'message' => 'The Default OT Rate Set cannot be deleted -- set a different Set as Default first.'];
        }
        $this->db->prepare("UPDATE ot_rate_sets SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id")
            ->execute([':deleted_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    public function toggleStatus(int $id, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT status, is_default FROM ot_rate_sets WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ((int)$row['is_default'] === 1 && $row['status'] === 'active') {
            return ['status' => false, 'message' => 'The Default OT Rate Set cannot be deactivated -- set a different Set as Default first.'];
        }
        $newStatus = $row['status'] === 'active' ? 'inactive' : 'active';
        $this->db->prepare("UPDATE ot_rate_sets SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':status' => $newStatus, ':updated_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Updated successfully.', 'new_status' => $newStatus];
    }

    /** Explicit "make this one the default" action -- clears every other set's flag first. */
    public function setDefault(int $id, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT id, status FROM ot_rate_sets WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($row['status'] !== 'active') {
            return ['status' => false, 'message' => 'Only an active OT Rate Set can be made the default.'];
        }
        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            $this->db->prepare("UPDATE ot_rate_sets SET is_default = 0 WHERE comp_id = :comp_id")->execute([':comp_id' => $compId]);
            $this->db->prepare("UPDATE ot_rate_sets SET is_default = 1, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':updated_by' => $userId, ':id' => $id]);
            // Making one set the default frees it from ever needing assignment rows -- same "Default
            // carries no assignments" invariant save() itself enforces on create/update.
            $this->db->prepare("DELETE FROM ot_rate_set_assignments WHERE set_id = :set_id")->execute([':set_id' => $id]);
            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
        return ['status' => true, 'message' => 'Default set updated.'];
    }

    /**
     * 2026-08-30, explicit request ("ทำ OT ต่อเลยครับ" precedent) -- same calculation-preview feature
     * as AttendanceDeductionRuleModel/the old otRatePreview(), for ONE scope's draft item config.
     */
    public function previewCalculation(array $item, float $sampleBaseSalary = 30000.0, float $sampleHours = 2.0): array {
        $calcBase = in_array($item['calculation_base'] ?? '', ['hourly', 'daily'], true) ? $item['calculation_base'] : 'hourly';
        $calcMethod = in_array($item['calculation_method'] ?? '', ['multiplier', 'flat_amount'], true) ? $item['calculation_method'] : 'multiplier';
        if ($calcMethod === 'flat_amount' && (!isset($item['flat_amount_rate']) || (float)$item['flat_amount_rate'] <= 0)) {
            return ['status' => false, 'message' => 'flat_amount_rate must be greater than 0.'];
        }
        if ($sampleBaseSalary <= 0 || $sampleHours < 0) {
            return ['status' => false, 'message' => 'Sample base salary must be positive and sample hours must not be negative.'];
        }
        $rate = [
            'calculation_base' => $calcBase, 'calculation_method' => $calcMethod,
            'flat_amount_rate' => isset($item['flat_amount_rate']) ? (float)$item['flat_amount_rate'] : 0.0,
            'multiplier_rate' => isset($item['multiplier_rate']) && (float)$item['multiplier_rate'] > 0 ? (float)$item['multiplier_rate'] : 1.0,
        ];
        $result = SyncPayResolver::computeOtAmountFromConfig($rate, $sampleBaseSalary, $sampleHours);
        return ['status' => true, 'amount' => $result['amount'], 'formula' => $result['formula']];
    }

    /**
     * Which Set applies to ONE employee -- single-employee version of resolveRatesForEmployees()
     * below, used by the Employee Detail/Recheck UI to show the "Recommended: Set X" hint when this
     * employee hasn't explicitly picked one. Returns null only if this company has no active Default
     * set at all yet (shouldn't happen once any set exists -- save() forces the first one to be
     * default -- but a company that has never configured ANY OT Rate Set genuinely has nothing to
     * recommend).
     */
    public function resolveSetForEmployee(int $compId, int $employeeId, ?int $departmentId, ?int $teamId, ?int $positionId): ?array {
        $resolved = $this->resolveRatesForEmployees([[
            'id' => $employeeId, 'department_id' => $departmentId, 'team_id' => $teamId, 'position_id' => $positionId, 'assigned_ot_rate_set_id' => null,
        ]], $compId);
        $setId = $resolved[$employeeId]['set_id'] ?? null;
        return $setId !== null ? $this->get((int)$setId, $compId) : null;
    }

    /**
     * Batched resolution for PayrollRunModel::recalculate() -- prefetches every active Set's items +
     * every assignment row ONCE (not per employee), then resolves per employee in PHP. Priority
     * (confirmed with the user): explicit assigned_ot_rate_set_id wins outright; else
     * employee > team > position > department assignment match; else the mandatory Default set.
     * @param array<int,array{id:int,department_id:?int,team_id:?int,position_id:?int,assigned_ot_rate_set_id:?int}> $employeeRows
     * @return array<int,array{set_id:?int,rates:array<string,array>}> employee_id => {set_id, rates: scope_code => rate array}
     */
    public function resolveRatesForEmployees(array $employeeRows, int $compId): array {
        $stmtItems = $this->db->prepare("SELECT i.set_id, s2.code AS scope_code, i.multiplier_rate, i.calculation_base, i.calculation_method, i.flat_amount_rate
            FROM ot_rate_set_items i
            JOIN ot_rate_sets s ON s.id = i.set_id AND s.comp_id = :comp_id AND s.status = 'active' AND s.deleted_at IS NULL
            JOIN master_ot_scope_types s2 ON s2.id = i.ot_scope_id");
        $stmtItems->execute([':comp_id' => $compId]);
        $itemsBySet = [];
        foreach ($stmtItems->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $itemsBySet[(int)$row['set_id']][$row['scope_code']] = [
                'multiplier_rate' => (float)$row['multiplier_rate'], 'calculation_base' => (string)$row['calculation_base'],
                'calculation_method' => (string)$row['calculation_method'],
                'flat_amount_rate' => $row['flat_amount_rate'] !== null ? (float)$row['flat_amount_rate'] : 0.0,
            ];
        }

        $stmtDefault = $this->db->prepare("SELECT id FROM ot_rate_sets WHERE comp_id = :comp_id AND is_default = 1 AND status = 'active' AND deleted_at IS NULL LIMIT 1");
        $stmtDefault->execute([':comp_id' => $compId]);
        $defaultSetId = $stmtDefault->fetchColumn();
        $defaultSetId = $defaultSetId !== false ? (int)$defaultSetId : null;

        $stmtAssign = $this->db->prepare("SELECT a.scope_type, a.scope_id, a.set_id
            FROM ot_rate_set_assignments a
            JOIN ot_rate_sets s ON s.id = a.set_id AND s.comp_id = :comp_id AND s.status = 'active' AND s.deleted_at IS NULL");
        $stmtAssign->execute([':comp_id' => $compId]);
        $assignBy = ['employee' => [], 'team' => [], 'position' => [], 'department' => []];
        foreach ($stmtAssign->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $assignBy[$row['scope_type']][(int)$row['scope_id']] = (int)$row['set_id'];
        }

        $result = [];
        foreach ($employeeRows as $emp) {
            $employeeId = (int)$emp['id'];
            $setId = !empty($emp['assigned_ot_rate_set_id']) ? (int)$emp['assigned_ot_rate_set_id'] : null;
            if ($setId === null) {
                $setId = $assignBy['employee'][$employeeId] ?? null;
                if ($setId === null && !empty($emp['team_id'])) {
                    $setId = $assignBy['team'][(int)$emp['team_id']] ?? null;
                }
                if ($setId === null && !empty($emp['position_id'])) {
                    $setId = $assignBy['position'][(int)$emp['position_id']] ?? null;
                }
                if ($setId === null && !empty($emp['department_id'])) {
                    $setId = $assignBy['department'][(int)$emp['department_id']] ?? null;
                }
                if ($setId === null) {
                    $setId = $defaultSetId;
                }
            }
            $result[$employeeId] = ['set_id' => $setId, 'rates' => ($setId !== null ? ($itemsBySet[$setId] ?? []) : [])];
        }
        return $result;
    }
}
