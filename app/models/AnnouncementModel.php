<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/EntityAssignmentModel.php';
require_once __DIR__ . '/NotificationModel.php';

/**
 * Backlog Phase 10, T057: "full Announcement CMS" -- see database/migrations/2026-09-04_7_
 * announcements.sql's own header comment for the full architecture/design reasoning (Payroll-access
 * users = active employees, publish-time recipient snapshot, entity_assignments reuse, single
 * mandatory dashboard-featured row). This model is the one place all of that is actually implemented.
 *
 * Lifecycle: draft (fully editable, incl. recipient scoping via EntityAssignmentModel) -> published
 * (content + recipients become IMMUTABLE -- publish() is a one-way transition, there is no
 * un-publish/re-publish; the only remaining actions on a published row are delete (soft) and
 * setDashboardFeatured()). This mirrors this app's own established "you can't edit approved numbers"
 * posture (payroll_runs) applied to CMS content instead of payroll math.
 */
class AnnouncementModel {
    private PDO $db;
    private EntityAssignmentModel $assignmentModel;
    private NotificationModel $notificationModel;
    private const ENTITY_TYPE = 'announcement';

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->assignmentModel = new EntityAssignmentModel($this->db);
        $this->notificationModel = new NotificationModel();
    }

    private function mapRow(array $row): array {
        $row['accept_required'] = (bool)$row['accept_required'];
        $row['is_dashboard_featured'] = (bool)$row['is_dashboard_featured'];
        return $row;
    }

    public function list(int $compId): array {
        $stmt = $this->db->prepare("SELECT a.*,
                (SELECT COUNT(*) FROM announcement_recipients ar WHERE ar.announcement_id = a.id) AS recipient_count,
                (SELECT COUNT(*) FROM announcement_acknowledgments aa WHERE aa.announcement_id = a.id) AS acknowledged_count
            FROM `announcements` a
            WHERE a.comp_id = :comp_id AND a.deleted_at IS NULL
            ORDER BY a.status = 'draft' DESC, a.created_at DESC");
        $stmt->execute([':comp_id' => $compId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r = $this->mapRow($r);
            $r['recipient_count'] = (int)$r['recipient_count'];
            $r['acknowledged_count'] = (int)$r['acknowledged_count'];
            $rows[] = $r;
        }
        return $rows;
    }

    public function get(int $compId, int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `announcements` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row = $this->mapRow($row);
        $row['assignments'] = $this->assignmentModel->getAssignments($compId, self::ENTITY_TYPE, $id);
        return $row;
    }

    /** Create (no id) or update (id given) -- update is refused once status='published' (immutable
     *  content, see this class's own docblock). $data['assignments'] (optional list of
     *  {scope_type, scope_id}) is saved via EntityAssignmentModel in the SAME transaction. */
    public function save(int $compId, array $data, int $userId): array {
        $titleTh = trim((string)($data['title_th'] ?? ''));
        $titleEn = trim((string)($data['title_en'] ?? ''));
        $bodyTh = trim((string)($data['body_th'] ?? ''));
        $bodyEn = trim((string)($data['body_en'] ?? ''));
        if ($titleTh === '' || $titleEn === '' || $bodyTh === '' || $bodyEn === '') {
            return ['status' => false, 'message' => 'Title and body (both languages) are required.'];
        }
        $acceptRequired = !empty($data['accept_required']) ? 1 : 0;
        $id = isset($data['id']) ? (int)$data['id'] : 0;

        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            if ($id > 0) {
                $stmtCheck = $this->db->prepare("SELECT status FROM `announcements` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                $status = $stmtCheck->fetchColumn();
                if ($status === false) {
                    if ($own) { $this->db->rollBack(); }
                    return ['status' => false, 'message' => 'Announcement not found.'];
                }
                if ($status === 'published') {
                    if ($own) { $this->db->rollBack(); }
                    return ['status' => false, 'message' => 'A published announcement can no longer be edited.'];
                }
                $this->db->prepare("UPDATE `announcements` SET title_th = :title_th, title_en = :title_en,
                        body_th = :body_th, body_en = :body_en, accept_required = :accept_required,
                        updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id AND comp_id = :comp_id")
                    ->execute([
                        ':title_th' => $titleTh, ':title_en' => $titleEn, ':body_th' => $bodyTh, ':body_en' => $bodyEn,
                        ':accept_required' => $acceptRequired, ':updated_by' => $userId, ':id' => $id, ':comp_id' => $compId,
                    ]);
            } else {
                $this->db->prepare("INSERT INTO `announcements`
                        (comp_id, title_th, title_en, body_th, body_en, accept_required, status, created_by)
                    VALUES (:comp_id, :title_th, :title_en, :body_th, :body_en, :accept_required, 'draft', :created_by)")
                    ->execute([
                        ':comp_id' => $compId, ':title_th' => $titleTh, ':title_en' => $titleEn,
                        ':body_th' => $bodyTh, ':body_en' => $bodyEn, ':accept_required' => $acceptRequired, ':created_by' => $userId,
                    ]);
                $id = (int)$this->db->lastInsertId();
            }

            if (array_key_exists('assignments', $data)) {
                $assignRes = $this->assignmentModel->saveAssignments($compId, self::ENTITY_TYPE, $id, (array)$data['assignments'], $userId);
                if (!$assignRes['status']) {
                    if ($own) { $this->db->rollBack(); }
                    return $assignRes;
                }
            }

            if ($own) { $this->db->commit(); }
            return ['status' => true, 'message' => 'Saved successfully.', 'id' => $id];
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function delete(int $compId, int $id, int $userId): array {
        $stmt = $this->db->prepare("SELECT id FROM `announcements` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        if (!$stmt->fetch()) {
            return ['status' => false, 'message' => 'Announcement not found.'];
        }
        $this->db->prepare("UPDATE `announcements` SET status = status, deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by, is_dashboard_featured = 0
                WHERE id = :id AND comp_id = :comp_id")
            ->execute([':deleted_by' => $userId, ':id' => $id, ':comp_id' => $compId]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    /** Every currently-active employee of the company -- "Payroll-access users" (see this table's own
     *  migration header comment for why this filter is the operational meaning of that phrase). */
    private function activeEmployeeIds(int $compId): array {
        $stmt = $this->db->prepare("SELECT id FROM `employees`
            WHERE comp_id = :comp_id AND deleted_at IS NULL AND employment_status NOT IN ('resigned', 'terminated')");
        $stmt->execute([':comp_id' => $compId]);
        return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id'));
    }

    /** The real "Publish" action -- resolves the target list ONCE against every currently-active
     *  employee (via EntityAssignmentModel::resolveForEmployee(), zero assignment rows = everyone),
     *  freezes it into announcement_recipients, flips status, then fans out a real Notification to
     *  each recipient (type='announcement', see NotificationModel::createForEmployees()). One
     *  transaction -- a partial publish (recipients stamped but status still draft, or vice versa)
     *  must never be observable. */
    public function publish(int $compId, int $id, int $userId): array {
        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            $stmt = $this->db->prepare("SELECT * FROM `announcements` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL FOR UPDATE");
            $stmt->execute([':id' => $id, ':comp_id' => $compId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                if ($own) { $this->db->rollBack(); }
                return ['status' => false, 'message' => 'Announcement not found.'];
            }
            if ($row['status'] !== 'draft') {
                if ($own) { $this->db->rollBack(); }
                return ['status' => false, 'message' => 'Only a draft announcement can be published.'];
            }

            $recipientIds = [];
            foreach ($this->activeEmployeeIds($compId) as $employeeId) {
                if ($this->assignmentModel->resolveForEmployee($compId, self::ENTITY_TYPE, $id, $employeeId)) {
                    $recipientIds[] = $employeeId;
                }
            }
            if (empty($recipientIds)) {
                if ($own) { $this->db->rollBack(); }
                return ['status' => false, 'message' => 'No eligible recipients match this announcement\'s scoping -- nothing to publish to.'];
            }

            $stmtIns = $this->db->prepare("INSERT INTO `announcement_recipients` (announcement_id, employee_id) VALUES (:announcement_id, :employee_id)");
            foreach ($recipientIds as $employeeId) {
                $stmtIns->execute([':announcement_id' => $id, ':employee_id' => $employeeId]);
            }

            $this->db->prepare("UPDATE `announcements` SET status = 'published', published_at = CURRENT_TIMESTAMP, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id AND comp_id = :comp_id")
                ->execute([':updated_by' => $userId, ':id' => $id, ':comp_id' => $compId]);

            $this->notificationModel->createForEmployees(
                $compId, $recipientIds, 'announcement',
                'ประกาศใหม่: ' . (string)$row['title_th'], 'New Announcement: ' . (string)$row['title_en'],
                null, null,
                '/announcements', 'announcement', $id,
                "announcement:{$id}", 'fa-bullhorn'
            );

            if ($own) { $this->db->commit(); }
            return ['status' => true, 'message' => 'Published successfully.', 'recipient_count' => count($recipientIds)];
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /** Exactly one is_dashboard_featured=1 row per company at a time -- same enforcement pattern as
     *  ot_rate_sets.is_default / probation_policy_sets.is_default. Only a PUBLISHED announcement may
     *  be featured (an unpublished draft has no real recipients/content commitment yet). */
    public function setDashboardFeatured(int $compId, int $id, int $userId): array {
        $stmt = $this->db->prepare("SELECT status FROM `announcements` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $status = $stmt->fetchColumn();
        if ($status === false) {
            return ['status' => false, 'message' => 'Announcement not found.'];
        }
        if ($status !== 'published') {
            return ['status' => false, 'message' => 'Only a published announcement can be featured on the Dashboard.'];
        }
        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            $this->db->prepare("UPDATE `announcements` SET is_dashboard_featured = 0 WHERE comp_id = :comp_id")->execute([':comp_id' => $compId]);
            $this->db->prepare("UPDATE `announcements` SET is_dashboard_featured = 1, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND comp_id = :comp_id")
                ->execute([':updated_by' => $userId, ':id' => $id, ':comp_id' => $compId]);
            if ($own) { $this->db->commit(); }
            return ['status' => true, 'message' => 'Featured on Dashboard.'];
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function getDashboardFeatured(int $compId): ?array {
        $stmt = $this->db->prepare("SELECT id, title_th, title_en, body_th, body_en, published_at FROM `announcements`
            WHERE comp_id = :comp_id AND deleted_at IS NULL AND status = 'published' AND is_dashboard_featured = 1 LIMIT 1");
        $stmt->execute([':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Refuses (not-found framing) if $employeeId is not a real recipient of this announcement -- same
     *  "don't confirm existence to someone with no business knowing" posture this app already applies
     *  elsewhere (e.g. ApprovalWorkflowModel's own cross-company custom-item lookups). */
    public function acknowledge(int $compId, int $announcementId, int $employeeId, string $via): array {
        $stmt = $this->db->prepare("SELECT 1 FROM `announcement_recipients` WHERE announcement_id = :announcement_id AND employee_id = :employee_id");
        $stmt->execute([':announcement_id' => $announcementId, ':employee_id' => $employeeId]);
        if (!$stmt->fetch()) {
            return ['status' => false, 'message' => 'Announcement not found.'];
        }
        try {
            $this->db->prepare("INSERT INTO `announcement_acknowledgments` (announcement_id, employee_id, acknowledged_via)
                    VALUES (:announcement_id, :employee_id, :via)
                    ON DUPLICATE KEY UPDATE acknowledged_via = VALUES(acknowledged_via)")
                ->execute([':announcement_id' => $announcementId, ':employee_id' => $employeeId, ':via' => $via]);
            return ['status' => true, 'message' => 'Acknowledged.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /** Every published announcement this employee is a real (snapshotted) recipient of, that they
     *  have NOT yet acknowledged -- the first-login click-through modal's own queue, and its
     *  remaining-count. Oldest-published-first so the queue has a stable, predictable order. */
    public function pendingForEmployee(int $compId, int $employeeId): array {
        $stmt = $this->db->prepare("SELECT a.id, a.title_th, a.title_en, a.body_th, a.body_en, a.accept_required, a.published_at
            FROM `announcements` a
            INNER JOIN `announcement_recipients` ar ON ar.announcement_id = a.id AND ar.employee_id = :employee_id
            LEFT JOIN `announcement_acknowledgments` aa ON aa.announcement_id = a.id AND aa.employee_id = :employee_id2
            WHERE a.comp_id = :comp_id AND a.deleted_at IS NULL AND a.status = 'published' AND aa.id IS NULL
            ORDER BY a.published_at ASC");
        $stmt->execute([':employee_id' => $employeeId, ':employee_id2' => $employeeId, ':comp_id' => $compId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['accept_required'] = (bool)$r['accept_required'];
            $rows[] = $r;
        }
        return $rows;
    }

    /** This employee's own full announcement history (past + pending), for the "View All" list page. */
    public function listForEmployee(int $compId, int $employeeId): array {
        $stmt = $this->db->prepare("SELECT a.id, a.title_th, a.title_en, a.body_th, a.body_en, a.accept_required, a.published_at,
                aa.acknowledged_at
            FROM `announcements` a
            INNER JOIN `announcement_recipients` ar ON ar.announcement_id = a.id AND ar.employee_id = :employee_id
            LEFT JOIN `announcement_acknowledgments` aa ON aa.announcement_id = a.id AND aa.employee_id = :employee_id2
            WHERE a.comp_id = :comp_id AND a.deleted_at IS NULL AND a.status = 'published'
            ORDER BY a.published_at DESC");
        $stmt->execute([':employee_id' => $employeeId, ':employee_id2' => $employeeId, ':comp_id' => $compId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['accept_required'] = (bool)$r['accept_required'];
            $rows[] = $r;
        }
        return $rows;
    }
}
