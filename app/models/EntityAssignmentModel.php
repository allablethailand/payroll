<?php
declare(strict_types=1);

/**
 * 2026-09-04, Backlog Phase 10, T055: generic, reusable "assign to Department/Position/Team/Employee"
 * mechanism -- see the migration's own header comment (database/migrations/2026-09-04_4_entity_
 * assignments.sql) for the full "why one shared table, not a 4th per-feature copy" reasoning. Any
 * future feature that needs "scope this to specific departments/positions/teams/employees, or leave
 * unscoped for everyone" attaches to `entity_assignments` via its own `entity_type` code string --
 * generalizes OtRateSetModel's own department/position/team/employee assignment mechanism (the
 * closest existing precedent, same 4 scope types) into a feature-agnostic table/model.
 *
 * DELIBERATE SCOPE BOUNDARY: this model has NO consuming HTTP controller/route yet. T054/T056 (not
 * built in this same task) are the first real consumers -- each of THEIR OWN controllers will call
 * this model's methods directly, in the SAME request/transaction as that feature's own already-
 * permission-gated save() action, rather than through a shared generic public endpoint. A generic
 * `POST api/entity-assignment.save` accepting an arbitrary entity_type/entity_id with no way to know
 * which permission SHOULD gate it would be a real, avoidable security/data-integrity gap (any caller
 * could attach assignment rows to an entity_type/entity_id it has no legitimate access to) -- this is
 * a safer integration shape, not a corner cut.
 *
 * Semantics: zero assignment rows for an (entity_type, entity_id) pair = UNSCOPED, applies to
 * everyone. Any rows present = applies only to the UNION of those scopes (an employee matching ANY
 * assigned scope is in-scope -- not all of them). No include/exclude mode (unlike Holiday's own
 * richer holiday_assignments) -- T055's own task text never asked for one, keeping this genuinely
 * generic rather than carrying a feature none of its first consumers need.
 */
class EntityAssignmentModel {
    private PDO $db;

    private const SCOPE_TYPES = ['department', 'position', 'team', 'employee'];
    private const SCOPE_TABLES = [
        'department' => 'structure_departments',
        'position' => 'structure_positions',
        'team' => 'structure_teams',
        'employee' => 'employees',
    ];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** Full option lists for the "Assign" checkbox UI -- same shape/precedent as OtRateSetModel::
     *  assignableOptions() (the closest existing 4-type example), Payslip/Employment Certificate
     *  Template's own 3-type versions. Every ACTIVE row, no pagination -- "every department/position/
     *  team/employee" is the whole point of a checkbox-all-of-them UI, not a searchable picker. */
    public function assignableOptions(int $compId): array {
        $stmtD = $this->db->prepare("SELECT id, department_name_th AS label FROM structure_departments WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL ORDER BY department_name_th ASC");
        $stmtD->execute([':comp_id' => $compId]);
        $stmtP = $this->db->prepare("SELECT id, position_name_th AS label FROM structure_positions WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL ORDER BY position_name_th ASC");
        $stmtP->execute([':comp_id' => $compId]);
        $stmtT = $this->db->prepare("SELECT id, team_name_th AS label FROM structure_teams WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL ORDER BY team_name_th ASC");
        $stmtT->execute([':comp_id' => $compId]);
        $stmtE = $this->db->prepare("SELECT id, CONCAT(employee_no, ' - ', name_th, ' ', surname_th) AS label FROM employees WHERE comp_id = :comp_id AND deleted_at IS NULL ORDER BY employee_no ASC");
        $stmtE->execute([':comp_id' => $compId]);
        return [
            'departments' => $stmtD->fetchAll(PDO::FETCH_ASSOC),
            'positions' => $stmtP->fetchAll(PDO::FETCH_ASSOC),
            'teams' => $stmtT->fetchAll(PDO::FETCH_ASSOC),
            'employees' => $stmtE->fetchAll(PDO::FETCH_ASSOC),
        ];
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

    /** Every assignment row for one (entity_type, entity_id), WITH a resolved display label -- the
     *  caller UI needs labels, not just raw scope_type/scope_id pairs. A scope_id whose underlying
     *  row was since deleted resolves to null and is skipped (stale row, shouldn't happen in practice
     *  since nothing else in this app hard-deletes department/position/team/employee rows, but a
     *  defensive skip here is cheap and matches this codebase's own "denormalize or defend, never
     *  crash on a stale reference" precedent). */
    public function getAssignments(int $compId, string $entityType, int $entityId): array {
        $stmt = $this->db->prepare("SELECT scope_type, scope_id FROM entity_assignments WHERE comp_id = :comp_id AND entity_type = :entity_type AND entity_id = :entity_id");
        $stmt->execute([':comp_id' => $compId, ':entity_type' => $entityType, ':entity_id' => $entityId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $label = $this->scopeLabel($compId, (string)$row['scope_type'], (int)$row['scope_id']);
            if ($label === null) {
                continue;
            }
            $out[] = ['scope_type' => $row['scope_type'], 'scope_id' => (int)$row['scope_id'], 'label' => $label];
        }
        return $out;
    }

    /**
     * Whole-set replace -- deletes every existing row for this (entity_type, entity_id) then inserts
     * the new set, same convention as holiday_assignments/approval_workflow_steps in this codebase
     * (not a diff/merge). $assignments is a list of {scope_type, scope_id}; an empty array is valid
     * and means "unscoped, applies to everyone" -- not an error.
     * @param array<int,array{scope_type:string,scope_id:int}> $assignments
     */
    public function saveAssignments(int $compId, string $entityType, int $entityId, array $assignments, int $userId): array {
        $validated = [];
        $seen = [];
        foreach ($assignments as $a) {
            $scopeType = (string)($a['scope_type'] ?? '');
            $scopeId = (int)($a['scope_id'] ?? 0);
            if (!in_array($scopeType, self::SCOPE_TYPES, true) || $scopeId <= 0) {
                return ['status' => false, 'message' => 'Invalid assignment row.'];
            }
            if (!$this->validScopeRef($compId, $scopeType, $scopeId)) {
                return ['status' => false, 'message' => 'One of the assigned departments/positions/teams/employees does not belong to this company.'];
            }
            $key = $scopeType . ':' . $scopeId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $validated[] = ['scope_type' => $scopeType, 'scope_id' => $scopeId];
        }

        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare("DELETE FROM entity_assignments WHERE comp_id = :comp_id AND entity_type = :entity_type AND entity_id = :entity_id")
                ->execute([':comp_id' => $compId, ':entity_type' => $entityType, ':entity_id' => $entityId]);
            if (!empty($validated)) {
                $stmtIns = $this->db->prepare("INSERT INTO entity_assignments (comp_id, entity_type, entity_id, scope_type, scope_id, created_by) VALUES (:comp_id, :entity_type, :entity_id, :scope_type, :scope_id, :created_by)");
                foreach ($validated as $a) {
                    $stmtIns->execute([
                        ':comp_id' => $compId, ':entity_type' => $entityType, ':entity_id' => $entityId,
                        ':scope_type' => $a['scope_type'], ':scope_id' => $a['scope_id'], ':created_by' => $userId,
                    ]);
                }
            }
            if ($own) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Saved successfully.'];
        } catch (PDOException $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /** Cleanup helper for a future consuming feature's own delete() -- e.g. deleting a PED type or a
     *  Probation policy should also clear its assignment rows. Not wired into any real delete() method
     *  yet since no consumer exists in this task (T054/T056 will call this from their own delete()). */
    public function deleteAllForEntity(int $compId, string $entityType, int $entityId): void {
        $this->db->prepare("DELETE FROM entity_assignments WHERE comp_id = :comp_id AND entity_type = :entity_type AND entity_id = :entity_id")
            ->execute([':comp_id' => $compId, ':entity_type' => $entityType, ':entity_id' => $entityId]);
    }

    /**
     * Does entity (entity_type, entity_id) apply to $employeeId? Unscoped (zero assignment rows) =
     * always true. Scoped = true if the employee matches ANY assigned scope -- their own id directly,
     * OR their department_id/position_id/team_id matches an assigned department/position/team (union
     * match across every row, not a strict priority elimination -- "priority" only matters for which
     * scope's label a caller might want to show as "the reason", a display concern, not this boolean).
     * Mirrors SetupRulesModel::resolveHolidaysForEmployee()/OtRateSetModel::resolveRatesForEmployees()'s
     * own per-employee resolution style.
     */
    public function resolveForEmployee(int $compId, string $entityType, int $entityId, int $employeeId): bool {
        $stmt = $this->db->prepare("SELECT scope_type, scope_id FROM entity_assignments WHERE comp_id = :comp_id AND entity_type = :entity_type AND entity_id = :entity_id");
        $stmt->execute([':comp_id' => $compId, ':entity_type' => $entityType, ':entity_id' => $entityId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return true;
        }

        $stmtEmp = $this->db->prepare("SELECT department_id, position_id, team_id FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);
        if (!$emp) {
            return false;
        }
        $departmentId = $emp['department_id'] !== null ? (int)$emp['department_id'] : null;
        $positionId = $emp['position_id'] !== null ? (int)$emp['position_id'] : null;
        $teamId = $emp['team_id'] !== null ? (int)$emp['team_id'] : null;

        foreach ($rows as $row) {
            $scopeType = (string)$row['scope_type'];
            $scopeId = (int)$row['scope_id'];
            if ($scopeType === 'employee' && $scopeId === $employeeId) {
                return true;
            }
            if ($scopeType === 'team' && $teamId !== null && $scopeId === $teamId) {
                return true;
            }
            if ($scopeType === 'position' && $positionId !== null && $scopeId === $positionId) {
                return true;
            }
            if ($scopeType === 'department' && $departmentId !== null && $scopeId === $departmentId) {
                return true;
            }
        }
        return false;
    }
}
