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

        $stmtGrants = $this->db->prepare("SELECT rp.role_id, rp.permission_id, rp.allow_scope FROM role_permissions rp
            JOIN structure_roles r ON r.id = rp.role_id
            WHERE r.comp_id = :comp_id AND r.deleted_at IS NULL");
        $stmtGrants->execute([':comp_id' => $compId]);
        $grants = [];
        foreach ($stmtGrants->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $grants[] = ['role_id' => (int)$g['role_id'], 'permission_id' => (int)$g['permission_id'], 'allow_scope' => $g['allow_scope']];
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
            $scope = in_array($g['allow_scope'] ?? '', ['all', 'own_department'], true) ? $g['allow_scope'] : 'all';
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
            $clean[] = [$roleId, $permId, $scope];
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
                $ins = $this->db->prepare("INSERT INTO role_permissions (role_id, permission_id, allow_scope, created_by) VALUES (?, ?, ?, ?)");
                foreach ($clean as [$roleId, $permId, $scope]) {
                    $ins->execute([$roleId, $permId, $scope, $userId]);
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
     * 'allow_scope' in the result is 'all'|'own_department'|null (null only when denied).
     */
    public function checkPermission(int $employeeId, string $permissionKey, bool $isAdmin, int $compId): array {
        if ($isAdmin) {
            return ['allowed' => true, 'allow_scope' => 'all'];
        }
        $stmt = $this->db->prepare("SELECT rp.allow_scope FROM employees e
            JOIN structure_roles r ON r.id = e.role_id AND r.deleted_at IS NULL
            JOIN role_permissions rp ON rp.role_id = r.id
            JOIN permissions p ON p.id = rp.permission_id AND p.permission_key = :perm_key AND p.is_active = 1
            WHERE e.id = :employee_id AND e.comp_id = :comp_id AND e.deleted_at IS NULL");
        $stmt->execute([':perm_key' => $permissionKey, ':employee_id' => $employeeId, ':comp_id' => $compId]);
        $scope = $stmt->fetchColumn();
        if ($scope === false) {
            return ['allowed' => false, 'allow_scope' => null];
        }
        return ['allowed' => true, 'allow_scope' => $scope];
    }
}
