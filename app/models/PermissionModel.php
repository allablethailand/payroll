<?php
declare(strict_types=1);

/**
 * RBAC engine on top of the existing structure_roles / employees.role_id (no new role or
 * user_roles table -- see CLAUDE.md). checkPermission() follows the same $isAdmin-bypass
 * convention as PayrollRunModel::userCan() for consistency with the rest of the app's (still
 * hardcoded-session) auth story.
 *
 * allow_scope='own_department' is stored generically for every permission, but only actually
 * enforced by callers for approval_request.act -- Holiday/Leave Type are company-wide config
 * tables with no per-record department ownership, so department-scoping them has no sensible
 * meaning. Callers for those two treat any allow_scope as 'all'.
 *
 * 2026-08-31, explicit request ("สิทธิ์ในการมองเห็นเงินเดือน...จะเห็นเป็น XXXX แต่ยังสามารถคำนวณเงินเดือน...
 * ได้ตามสิทธิ์"): allow_scope widened with a 3rd value, 'own_only' (this employee's own figures
 * only, everyone else's masked), and a new `detail_level` column ('summary'|'full', only
 * meaningful for the 3 salary_amount.* keys) -- both only enforced by
 * resolveSalaryVisibility()/checkPermission() callers for salary_amount.view_employee/
 * view_payroll_process/view_reports, same "generic column, selectively enforced per key"
 * precedent allow_scope itself already established. See database/migrations/
 * 2026-08-31_16_salary_amount_visibility_permission.sql for the full design reasoning.
 *
 * CRITICAL invariant: masking only ever happens at the READ/response-shaping layer (controller
 * output). PayrollRunModel::recalculate() and every other write/calculation path NEVER calls
 * through this permission check -- a restricted role can still trigger Calculate/Submit/Approve/
 * Pay normally without ever seeing the real numbers, and the numbers themselves are never
 * altered by who is looking at them. Never gate a WRITE action on a *_amount.view* permission.
 */
class PermissionModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function listPermissions(): array {
        $stmt = $this->db->query("SELECT * FROM permissions WHERE is_active = 1 ORDER BY sort_order ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function matrix(int $compId): array {
        $stmtRoles = $this->db->prepare("SELECT id, role_name_th, role_name_en FROM structure_roles
            WHERE comp_id = :comp_id AND deleted_at IS NULL AND status = 'active' ORDER BY role_name_th ASC");
        $stmtRoles->execute([':comp_id' => $compId]);
        $roles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

        $permissions = $this->listPermissions();

        $stmtGrants = $this->db->prepare("SELECT rp.role_id, rp.permission_id, rp.allow_scope, rp.detail_level FROM role_permissions rp
            JOIN structure_roles r ON r.id = rp.role_id
            WHERE r.comp_id = :comp_id AND r.deleted_at IS NULL");
        $stmtGrants->execute([':comp_id' => $compId]);
        $grants = [];
        foreach ($stmtGrants->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $grants[] = ['role_id' => (int)$g['role_id'], 'permission_id' => (int)$g['permission_id'], 'allow_scope' => $g['allow_scope'], 'detail_level' => $g['detail_level']];
        }

        return ['roles' => $roles, 'permissions' => $permissions, 'grants' => $grants];
    }

    public function saveMatrix(int $compId, array $grants, int $userId): array {
        $stmtRoles = $this->db->prepare("SELECT id FROM structure_roles WHERE comp_id = :comp_id AND deleted_at IS NULL");
        $stmtRoles->execute([':comp_id' => $compId]);
        $validRoleIds = array_map('intval', array_column($stmtRoles->fetchAll(PDO::FETCH_ASSOC), 'id'));
        $validRoleIdSet = array_flip($validRoleIds);

        $validPermIdSet = array_flip(array_map(fn($p) => (int)$p['id'], $this->listPermissions()));

        $clean = [];
        $seen = [];
        foreach ($grants as $g) {
            $roleId = (int)($g['role_id'] ?? 0);
            $permId = (int)($g['permission_id'] ?? 0);
            // 2026-08-31: 'own_only' added (salary_amount.* keys) -- see this model's own docblock.
            $scope = in_array($g['allow_scope'] ?? '', ['all', 'own_department', 'own_only'], true) ? $g['allow_scope'] : 'all';
            $detailLevel = in_array($g['detail_level'] ?? '', ['summary', 'full'], true) ? $g['detail_level'] : 'full';
            if (!isset($validRoleIdSet[$roleId])) {
                return ['status' => false, 'message' => 'Invalid role selection.'];
            }
            if (!isset($validPermIdSet[$permId])) {
                return ['status' => false, 'message' => 'Invalid permission selection.'];
            }
            $key = $roleId . ':' . $permId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $clean[] = [$roleId, $permId, $scope, $detailLevel];
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            if (!empty($validRoleIds)) {
                $placeholders = implode(',', array_fill(0, count($validRoleIds), '?'));
                $this->db->prepare("DELETE FROM role_permissions WHERE role_id IN ({$placeholders})")->execute($validRoleIds);
            }
            if (!empty($clean)) {
                $ins = $this->db->prepare("INSERT INTO role_permissions (role_id, permission_id, allow_scope, detail_level, created_by) VALUES (?, ?, ?, ?, ?)");
                foreach ($clean as [$roleId, $permId, $scope, $detailLevel]) {
                    $ins->execute([$roleId, $permId, $scope, $detailLevel, $userId]);
                }
            }
            if ($ownTransaction) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Saved successfully.'];
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /**
     * Coarse RBAC gate. $isAdmin bypasses everything (matches PayrollRunModel::userCan()).
     * 'allow_scope' in the result is 'all'|'own_department'|'own_only'|null (null only when denied).
     * 'detail_level' is 'summary'|'full'|null (null only when denied) -- only meaningful to callers
     * checking a salary_amount.* key, see resolveSalaryVisibility() below for the one that matters.
     *
     * 2026-09-03, Platform Hardening Phase 3 -- per-user override checked FIRST (before the
     * role_permissions lookup), fully additive: an employee with no override row for this
     * permission behaves exactly as before this change. A 'deny' override refuses immediately even
     * if the employee's role would otherwise grant it; a 'grant' override allows immediately (with
     * its own allow_scope/detail_level) even if the role would otherwise deny it -- either way,
     * short-circuits before ever touching role_permissions. Admin bypass stays the very first check,
     * unaffected by any override -- an admin is never bindable to a 'deny' override.
     */
    public function checkPermission(int $employeeId, string $permissionKey, bool $isAdmin, int $compId): array {
        if ($isAdmin) {
            return ['allowed' => true, 'allow_scope' => 'all', 'detail_level' => 'full'];
        }
        $override = $this->getOverride($employeeId, $permissionKey, $compId);
        if ($override !== null) {
            if ($override['effect'] === 'deny') {
                return ['allowed' => false, 'allow_scope' => null, 'detail_level' => null];
            }
            return [
                'allowed' => true,
                'allow_scope' => $override['allow_scope'] ?? 'all',
                'detail_level' => $override['detail_level'] ?? 'full',
            ];
        }
        $stmt = $this->db->prepare("SELECT rp.allow_scope, rp.detail_level FROM employees e
            JOIN structure_roles r ON r.id = e.role_id AND r.deleted_at IS NULL
            JOIN role_permissions rp ON rp.role_id = r.id
            JOIN permissions p ON p.id = rp.permission_id AND p.permission_key = :perm_key AND p.is_active = 1
            WHERE e.id = :employee_id AND e.comp_id = :comp_id AND e.deleted_at IS NULL");
        $stmt->execute([':perm_key' => $permissionKey, ':employee_id' => $employeeId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['allowed' => false, 'allow_scope' => null, 'detail_level' => null];
        }
        return ['allowed' => true, 'allow_scope' => $row['allow_scope'], 'detail_level' => $row['detail_level']];
    }

    /** Raw override row lookup (or null), scoped to the acting employee's own company. Private --
     *  checkPermission() is the only sanctioned entry point for callers outside this class. */
    private function getOverride(int $employeeId, string $permissionKey, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT epo.effect, epo.allow_scope, epo.detail_level
            FROM employee_permission_overrides epo
            JOIN permissions p ON p.id = epo.permission_id AND p.permission_key = :perm_key AND p.is_active = 1
            WHERE epo.employee_id = :employee_id AND epo.comp_id = :comp_id");
        $stmt->execute([':perm_key' => $permissionKey, ':employee_id' => $employeeId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * 2026-09-03, Platform Hardening Phase 3 -- "everyone who currently holds this permission,"
     * for fan-out consumers (notification recipients, etc.) that used to scan a raw
     * `structure_roles.<column> = 1` column directly (see NotificationModel::
     * createForPermissionHolders(), the first real caller of this). Does NOT bypass for admin --
     * an admin session isn't a real employee_id in most fan-out contexts (there may be several
     * admin sessions, or none tied to a specific employee row), so this deliberately returns only
     * the REAL rule-derived set: role-granted employees, minus anyone with a 'deny' override on
     * this permission, plus anyone with a 'grant' override who wouldn't otherwise qualify. Company-
     * scoped, active employees only.
     */
    public function employeesWithPermission(int $compId, string $permissionKey): array {
        $sql = "SELECT DISTINCT e.id
            FROM employees e
            LEFT JOIN structure_roles r ON r.id = e.role_id AND r.deleted_at IS NULL
            LEFT JOIN role_permissions rp ON rp.role_id = r.id
            LEFT JOIN permissions p_role ON p_role.id = rp.permission_id AND p_role.permission_key = :perm_key1 AND p_role.is_active = 1
            LEFT JOIN employee_permission_overrides epo ON epo.employee_id = e.id AND epo.comp_id = e.comp_id
            LEFT JOIN permissions p_override ON p_override.id = epo.permission_id AND p_override.permission_key = :perm_key2 AND p_override.is_active = 1
            WHERE e.comp_id = :comp_id AND e.deleted_at IS NULL
              AND (
                  (p_role.id IS NOT NULL AND (p_override.id IS NULL OR epo.effect != 'deny'))
                  OR (p_override.id IS NOT NULL AND epo.effect = 'grant')
              )";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':perm_key1' => $permissionKey, ':perm_key2' => $permissionKey, ':comp_id' => $compId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * 2026-08-31, explicit request: the one real entry point every controller that returns a
     * salary/monetary figure should call before deciding what to send back. Wraps
     * checkPermission() for one of the 3 salary_amount.* keys and resolves it down to a plain
     * decision a controller can act on directly:
     *   - full: true  => every real number for anyone in scope is sent as-is.
     *   - full: false, masked: true => figures for anyone OUTSIDE scope must be replaced with
     *     the literal string "XXXX" (never omitted -- omitting a key is indistinguishable from
     *     "this employee has no salary data" on the frontend, which is worse than an explicit
     *     mask marker).
     *   - own_only: when true, `$subjectEmployeeId` (whichever employee's row/figures this
     *     response is ABOUT) must equal the ACTING employee's own id to count as "in scope";
     *     every other subject is masked regardless of detail_level.
     *   - summary_only: when true (detail_level='summary'), itemized breakdown figures
     *     (earning_breakdown/deduction_breakdown/statutory_breakdown lines) must be masked even
     *     for an in-scope subject -- only net/gross/total-style single figures are shown.
     * `$subjectEmployeeId` is null for a response that isn't about one specific employee (e.g. a
     * Reports download) -- own_only scope then degrades to "must be admin/have 'all' scope",
     * since there is no single subject to compare against.
     *
     * NEVER called from PayrollRunModel::recalculate() or any other write/calculation path -- see
     * this class's own top-of-file docblock. Calculation must stay correct and unaffected by who
     * is looking at the result.
     */
    public function resolveSalaryVisibility(int $employeeId, string $module, bool $isAdmin, int $compId, ?int $subjectEmployeeId = null): array {
        $permissionKey = 'salary_amount.view_' . $module;
        $check = $this->checkPermission($employeeId, $permissionKey, $isAdmin, $compId);
        if (!$check['allowed']) {
            return ['full' => false, 'masked' => true, 'in_scope' => false, 'summary_only' => false];
        }
        $ownOnly = $check['allow_scope'] === 'own_only';
        $inScope = !$ownOnly || ($subjectEmployeeId !== null && $subjectEmployeeId === $employeeId);
        $summaryOnly = $inScope && $check['detail_level'] === 'summary';
        return [
            'full' => $inScope && $check['detail_level'] === 'full',
            'masked' => !$inScope,
            'in_scope' => $inScope,
            'summary_only' => $summaryOnly,
        ];
    }

    /**
     * 2026-09-03, Platform Hardening Phase 3 Stage 5 -- the Employee Detail "Permission Overrides"
     * tab's own data source. One row per active permission, carrying whether this employee's ROLE
     * grants it by default ("Inherited") alongside any per-employee override row that already
     * exists for it. The frontend renders a 3-state control per row (Inherit/Grant/Deny) -- `null`
     * `override_effect` means "Inherit" (no row yet, behaves exactly like every other employee on
     * this role). `allow_scope`/`detail_level` reflect the override's own values when one exists,
     * else fall back to the role's own values (so a freshly-opened "Grant" selection starts from a
     * sensible default rather than blank).
     */
    public function employeeOverrides(int $compId, int $employeeId): array {
        $permissions = $this->listPermissions();

        $stmtRole = $this->db->prepare("SELECT rp.permission_id, rp.allow_scope, rp.detail_level
            FROM employees e
            JOIN structure_roles r ON r.id = e.role_id AND r.deleted_at IS NULL
            JOIN role_permissions rp ON rp.role_id = r.id
            WHERE e.id = :employee_id AND e.comp_id = :comp_id AND e.deleted_at IS NULL");
        $stmtRole->execute([':employee_id' => $employeeId, ':comp_id' => $compId]);
        $roleGrants = [];
        foreach ($stmtRole->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $roleGrants[(int)$g['permission_id']] = ['allow_scope' => $g['allow_scope'], 'detail_level' => $g['detail_level']];
        }

        $stmtOverride = $this->db->prepare("SELECT permission_id, effect, allow_scope, detail_level
            FROM employee_permission_overrides WHERE employee_id = :employee_id AND comp_id = :comp_id");
        $stmtOverride->execute([':employee_id' => $employeeId, ':comp_id' => $compId]);
        $overrides = [];
        foreach ($stmtOverride->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $overrides[(int)$o['permission_id']] = ['effect' => $o['effect'], 'allow_scope' => $o['allow_scope'], 'detail_level' => $o['detail_level']];
        }

        $rows = [];
        foreach ($permissions as $p) {
            $pid = (int)$p['id'];
            $override = $overrides[$pid] ?? null;
            $roleGrant = $roleGrants[$pid] ?? null;
            $rows[] = [
                'permission_id' => $pid,
                'permission_key' => $p['permission_key'],
                'module_code' => $p['module_code'],
                'action_code' => $p['action_code'],
                'name_th' => $p['name_th'],
                'name_en' => $p['name_en'],
                'role_granted' => $roleGrant !== null,
                'override_effect' => $override['effect'] ?? null,
                'allow_scope' => $override['allow_scope'] ?? ($roleGrant['allow_scope'] ?? 'all'),
                'detail_level' => $override['detail_level'] ?? ($roleGrant['detail_level'] ?? 'full'),
            ];
        }
        return $rows;
    }

    /**
     * Replaces the WHOLE override set for one employee in one call -- same declarative,
     * delete-then-reinsert semantics as saveMatrix() above (the frontend always submits the
     * complete current state of every permission row, including the ones left at "Inherit", so a
     * row simply absent from `$overrides` or carrying a null/empty `effect` is correctly treated as
     * "no override here" rather than "leave whatever was there before untouched"). `$overrides` is
     * an array of `{permission_id, effect: 'grant'|'deny'|null, allow_scope?, detail_level?}`.
     */
    public function saveEmployeeOverrides(int $compId, int $employeeId, array $overrides, int $userId): array {
        $stmtEmp = $this->db->prepare("SELECT id FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
        if (!$stmtEmp->fetch()) {
            return ['status' => false, 'message' => 'Employee not found.'];
        }

        $validPermIdSet = array_flip(array_map(fn($p) => (int)$p['id'], $this->listPermissions()));

        $clean = [];
        $seen = [];
        foreach ($overrides as $o) {
            $effect = $o['effect'] ?? null;
            if ($effect === null || $effect === '') {
                continue; // Inherit -- nothing to persist for this permission.
            }
            if (!in_array($effect, ['grant', 'deny'], true)) {
                return ['status' => false, 'message' => 'Invalid effect.'];
            }
            $permId = (int)($o['permission_id'] ?? 0);
            if (!isset($validPermIdSet[$permId])) {
                return ['status' => false, 'message' => 'Invalid permission selection.'];
            }
            if (isset($seen[$permId])) {
                continue;
            }
            $seen[$permId] = true;
            // A 'deny' override needs no scope -- it's an outright refusal (see this table's own
            // migration comment).
            $scope = ($effect === 'grant' && in_array($o['allow_scope'] ?? '', ['all', 'own_department', 'own_only'], true)) ? $o['allow_scope'] : null;
            $detailLevel = ($effect === 'grant' && in_array($o['detail_level'] ?? '', ['summary', 'full'], true)) ? $o['detail_level'] : null;
            $clean[] = [$permId, $effect, $scope, $detailLevel];
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $this->db->prepare("DELETE FROM employee_permission_overrides WHERE employee_id = :employee_id AND comp_id = :comp_id")
                ->execute([':employee_id' => $employeeId, ':comp_id' => $compId]);
            if (!empty($clean)) {
                $ins = $this->db->prepare("INSERT INTO employee_permission_overrides (comp_id, employee_id, permission_id, effect, allow_scope, detail_level, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                foreach ($clean as [$permId, $effect, $scope, $detailLevel]) {
                    $ins->execute([$compId, $employeeId, $permId, $effect, $scope, $detailLevel, $userId]);
                }
            }
            if ($ownTransaction) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Saved successfully.'];
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /** Plain string marker used everywhere a masked monetary figure is sent instead of the real number. */
    public const MASK_VALUE = 'XXXX';
}
