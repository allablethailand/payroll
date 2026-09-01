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
     */
    public function checkPermission(int $employeeId, string $permissionKey, bool $isAdmin, int $compId): array {
        if ($isAdmin) {
            return ['allowed' => true, 'allow_scope' => 'all', 'detail_level' => 'full'];
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

    /** Plain string marker used everywhere a masked monetary figure is sent instead of the real number. */
    public const MASK_VALUE = 'XXXX';
}
