<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/Database.php';
require_once __DIR__ . '/PayrollPolicyModel.php';
require_once __DIR__ . '/PermissionModel.php';

/**
 * 2026-08-29, explicit request: "ช่วยสร้างระบบแจ้งเตือนและวิเคราะห์ว่าควรมีการแจ้งเตือนอะไรบ้าง เช่นมีข้อมูล
 * Sync มาใหม่จาก Origami หรือมีการทำงานค้างอยู่นานแล้ว 1 2 3 วัน ทำต่อไหม หรืออนุมัติแล้วนะ ทำงานต่อเลยไหม
 * หรือปิดรอบไปแล้วอย่าลืมปริ้นเอกสาร หรือมีคนขอ Slip เงินเดือนหรือขอเอกสารมานะรออนุมัติอยู่...โดยเป็นของใคร
 * ของมัน คนนึงเห็นแล้วอีกคนไม่เห็นตัวเลขก็จะไม่หาย ต้องไปกดเปิดดูก่อนถึงหาย"
 *
 * Analysis of what's worth notifying on, and who receives it (documented here since the request
 * asked to "วิเคราะห์ว่าควรมีการแจ้งเตือนอะไรบ้าง" -- analyze what notifications should exist, not just
 * build a mechanism):
 *  - sync_new_data: a new batch of attendance/payroll data actually arrived from Origami (real
 *    event -- PayrollSyncModel::ingest() succeeding). Recipients: can_process_payroll holders (the
 *    people who'd actually pull it into a run).
 *  - stale_draft (1d/2d/3d): a draft run nobody has touched in a while. NOT an event -- there is no
 *    "nothing happened" trigger to hook. Computed lazily instead, see checkStaleDrafts()'s own
 *    docblock (this project has no cron/scheduled-job infrastructure, already documented elsewhere
 *    e.g. PayslipDeliveryService's own docblock). Recipients: the run's own creator (created_by) --
 *    this is a personal "you left this" nudge, not a company-wide broadcast.
 *  - approved_continue: a run just reached final approval. Recipients: can_finalize_payroll
 *    holders (whoever can actually act on it next -- Mark as Paid).
 *  - lock_reminder_print: a run just got locked (fully closed out). Recipients: can_process_payroll
 *    holders -- a locked run is done, but the statutory/bank/payslip reports for it still need to
 *    be pulled, and this project's own established pattern (Print Reports button) already lives on
 *    the pages those same people work from.
 *  - document_request_pending: an employee submitted a Payslip/Employment Certificate request that
 *    needs approval. Recipients: whoever the resolved Approval Workflow step actually lists as
 *    eligible for THIS specific request (ApprovalRequestModel's own eligible_employee_ids) -- not a
 *    blanket permission-holder broadcast, since approval eligibility here is workflow-configured,
 *    not a fixed role flag.
 *
 * Every row belongs to exactly one recipient (employee_id) -- see this table's own migration
 * header comment for why that alone already satisfies "ของใครของมัน" with no separate read-state
 * pivot table needed.
 */
class NotificationModel {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }

    /**
     * 2026-08-29, explicit follow-up request: "ทำ Notification Settings ก่อนเลยครับ -- ให้ user เลือก
     * เปิด/ปิดรับแจ้งเตือนได้เป็นราย category (5 ประเภทที่มีอยู่)" -- the single source of truth for
     * "which 5 categories exist" (was previously only implicit -- each caller just picked its own
     * `type` string). Every place that needs to enumerate/label the 5 types (this class's own
     * preference methods, the Settings modal, the role matrix) reads from here instead of
     * duplicating the list, so a future 6th type only needs adding in ONE place.
     */
    public const TYPES = [
        'sync_new_data' => ['label_th' => 'มีข้อมูล Sync ใหม่จาก Origami', 'label_en' => 'New data synced from Origami'],
        'stale_draft' => ['label_th' => 'งานค้างอยู่ (ยังเป็นร่าง)', 'label_en' => 'Stale draft reminder'],
        'approved_continue' => ['label_th' => 'งวดเงินเดือนได้รับการอนุมัติแล้ว', 'label_en' => 'Payroll run approved'],
        'lock_reminder_print' => ['label_th' => 'ปิดรอบแล้ว อย่าลืมปริ้นเอกสาร', 'label_en' => 'Run locked -- print reminder'],
        'document_request_pending' => ['label_th' => 'มีคำขอเอกสารรออนุมัติ', 'label_en' => 'Document request pending approval'],
        // 2026-09-02, explicit request (item 5 of a 5-item follow-up list): "ปิดไปได้เลยครับ ให้เข้าไป
        // ปรับเอง แต่ให้มี notification ขึ้นเตือนเฉยๆครับ ทั้งในหน้า Dashboard...และใน notification" -- no
        // auto-transition of employment_status/employment_type (stays a manual admin action), just a
        // reminder. See probationInternExpiringEmployees()'s own docblock for the full mechanism.
        'probation_intern_expiring' => ['label_th' => 'ทดลองงาน/ฝึกงานใกล้ครบกำหนด', 'label_en' => 'Probation/internship period ending'],
    ];

    /** Days-before-expiry window that counts as "expiring soon" (see probationInternExpiringEmployees()). */
    private const PROBATION_INTERN_EXPIRY_SOON_DAYS = 7;

    /**
     * Resolves whether $employeeId should receive a NEW notification of $type, checked once per
     * create() call. Priority: personal override (employee_notification_preferences) > role-level
     * default (role_notification_preferences) > enabled (today's behavior, unchanged for anyone
     * who never touched either setting). A muted notification is simply never inserted -- not
     * created-then-hidden -- so existing history before a mute is untouched, only future ones stop.
     */
    private function shouldNotify(int $employeeId, string $type): bool {
        $stmtPersonal = $this->db->prepare("SELECT enabled FROM `employee_notification_preferences` WHERE employee_id = :employee_id AND type = :type");
        $stmtPersonal->execute([':employee_id' => $employeeId, ':type' => $type]);
        $personal = $stmtPersonal->fetchColumn();
        if ($personal !== false) {
            return (bool)$personal;
        }
        $stmtRole = $this->db->prepare("SELECT rnp.enabled FROM `role_notification_preferences` rnp
            JOIN `employees` e ON e.role_id = rnp.role_id
            WHERE e.id = :employee_id AND rnp.type = :type");
        $stmtRole->execute([':employee_id' => $employeeId, ':type' => $type]);
        $roleDefault = $stmtRole->fetchColumn();
        if ($roleDefault !== false) {
            return (bool)$roleDefault;
        }
        return true;
    }

    /**
     * Core insert, one row per call (one recipient). $dedupKey (optional) makes this a safe no-op
     * if the exact same notification instance was already created for this employee -- callers that
     * re-check periodically (checkStaleDrafts()) always pass one; a genuine one-shot event
     * (approved/locked/document request) normally doesn't need to, since it only ever fires once
     * per real action anyway.
     */
    public function create(int $compId, int $employeeId, string $type, string $titleTh, string $titleEn, ?string $messageTh, ?string $messageEn, ?string $linkUrl, ?string $relatedType = null, ?int $relatedId = null, ?string $dedupKey = null, ?string $icon = null): bool {
        if (!$this->shouldNotify($employeeId, $type)) {
            return false;
        }
        if ($dedupKey !== null) {
            $stmtCheck = $this->db->prepare("SELECT 1 FROM `notifications` WHERE dedup_key = :dedup_key");
            $stmtCheck->execute([':dedup_key' => $dedupKey]);
            if ($stmtCheck->fetch()) {
                return false; // already exists, nothing new to insert
            }
        }
        $stmt = $this->db->prepare("INSERT INTO `notifications`
            (comp_id, employee_id, type, icon, title_th, title_en, message_th, message_en, link_url, related_type, related_id, dedup_key)
            VALUES (:comp_id, :employee_id, :type, :icon, :title_th, :title_en, :message_th, :message_en, :link_url, :related_type, :related_id, :dedup_key)");
        try {
            $stmt->execute([
                ':comp_id' => $compId, ':employee_id' => $employeeId, ':type' => $type, ':icon' => $icon,
                ':title_th' => $titleTh, ':title_en' => $titleEn, ':message_th' => $messageTh, ':message_en' => $messageEn,
                ':link_url' => $linkUrl, ':related_type' => $relatedType, ':related_id' => $relatedId, ':dedup_key' => $dedupKey,
            ]);
        } catch (PDOException $e) {
            // A concurrent request winning the same dedup_key race is a harmless no-op, not a
            // real failure -- the pre-check above already covers the common case, this catches
            // the rare exact-same-millisecond race the check-then-insert gap can't.
            if ((int)$e->getCode() === 23000) {
                return false;
            }
            throw $e;
        }
        return true;
    }

    /** Same event, fanned out to every employee whose role has $permissionColumn=1 (can_process_payroll/can_approve_payroll/can_finalize_payroll -- same 3 columns PayrollRunModel::userCan() itself reads off structure_roles). */
    /**
     * 2026-09-03, Platform Hardening Phase 3: `$permissionKey` used to be a literal
     * structure_roles.<column> name (can_process_payroll/can_approve_payroll/can_finalize_payroll),
     * read via a raw SQL column scan -- those columns are retired. Now a real `permission_key`
     * string (e.g. 'payroll_run.process'), resolved via PermissionModel::employeesWithPermission()
     * -- role-granted employees, correctly accounting for per-user grant/deny overrides too (the
     * raw column scan this replaces had no way to represent an override at all).
     */
    public function createForPermissionHolders(int $compId, string $permissionKey, string $type, string $titleTh, string $titleEn, ?string $messageTh, ?string $messageEn, ?string $linkUrl, ?string $relatedType = null, ?int $relatedId = null, ?string $dedupKeyPrefix = null, ?string $icon = null): void {
        $permissionModel = new PermissionModel($this->db);
        foreach ($permissionModel->employeesWithPermission($compId, $permissionKey) as $employeeId) {
            $dedupKey = $dedupKeyPrefix !== null ? "{$dedupKeyPrefix}:{$employeeId}" : null;
            $this->create($compId, $employeeId, $type, $titleTh, $titleEn, $messageTh, $messageEn, $linkUrl, $relatedType, $relatedId, $dedupKey, $icon);
        }
    }

    /** Same event, fanned out to a specific, already-resolved list of employee ids (e.g. an Approval Workflow step's eligible_employee_ids). */
    public function createForEmployees(int $compId, array $employeeIds, string $type, string $titleTh, string $titleEn, ?string $messageTh, ?string $messageEn, ?string $linkUrl, ?string $relatedType = null, ?int $relatedId = null, ?string $dedupKeyPrefix = null, ?string $icon = null): void {
        foreach (array_unique(array_map('intval', $employeeIds)) as $employeeId) {
            if ($employeeId <= 0) {
                continue;
            }
            $dedupKey = $dedupKeyPrefix !== null ? "{$dedupKeyPrefix}:{$employeeId}" : null;
            $this->create($compId, $employeeId, $type, $titleTh, $titleEn, $messageTh, $messageEn, $linkUrl, $relatedType, $relatedId, $dedupKey, $icon);
        }
    }

    /**
     * Lazy aging-draft check (see this class's own top-of-file docblock for why lazy, not a cron).
     * Called once per notification-list/unread-count request for the CURRENT employee only (not a
     * company-wide sweep every time anyone opens the bell) -- cheap (one small query scoped to runs
     * THIS employee created), and dedup_key means calling it redundantly often is always safe.
     * Milestones are 1/2/3+ days since payroll_runs.updated_at, each its own dedup_key so a run
     * stuck for a week gets exactly 3 reminders total (1d/2d/3d), not one per day forever.
     */
    public function checkStaleDrafts(int $compId, int $employeeId): void {
        $stmt = $this->db->prepare("SELECT id, run_name, updated_at, TIMESTAMPDIFF(DAY, updated_at, NOW()) AS days_stale
            FROM `payroll_runs`
            WHERE comp_id = :comp_id AND created_by = :employee_id AND state = 'draft'
            AND TIMESTAMPDIFF(DAY, updated_at, NOW()) >= 1");
        $stmt->execute([':comp_id' => $compId, ':employee_id' => $employeeId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $run) {
            $daysStale = (int)$run['days_stale'];
            $milestone = $daysStale >= 3 ? 3 : ($daysStale >= 2 ? 2 : 1);
            $runName = (string)$run['run_name'];
            $this->create(
                $compId, $employeeId, 'stale_draft',
                "งวด \"{$runName}\" ค้างอยู่ {$milestone} วันแล้ว", "\"{$runName}\" has been sitting untouched for {$milestone} day(s)",
                "ยังเป็นสถานะร่างอยู่ ต้องการทำต่อไหมครับ", "Still in Draft -- want to continue working on it?",
                "/payroll-process/{$run['id']}", 'payroll_run', (int)$run['id'],
                "stale_draft:{$run['id']}:{$milestone}d", 'fa-hourglass-half'
            );
        }
    }

    /**
     * 2026-09-02, explicit request: probation/internship period-expiry reminder -- NO
     * auto-transition of `employment_status`/`employment_type` (confirmed staying a manual admin
     * action, "ให้เข้าไปปรับเอง"), this is purely informational. Deliberately the SINGLE source of
     * truth both `checkProbationInternExpiring()`'s lazy notification trigger below AND the
     * Dashboard's own card (`DashboardController::summary()` calls this directly) share, so the two
     * never disagree about who currently qualifies -- a notification can be marked read/dismissed
     * while the underlying condition still holds, so the Dashboard card can't be driven off the
     * notifications table itself.
     *
     * Effective period_days precedence mirrors `PayrollRunModel::recalculate()`'s own probation/
     * intern override resolution exactly: employee's own `*_period_days_override` (NULL = inherit)
     * wins over `company_payroll_policies.*_period_days`; when an employee is somehow BOTH
     * `employment_type='internship'` AND `employment_status='probation'` simultaneously, internship
     * wins (same "intern wins when both true, never stacked" precedent documented elsewhere in this
     * model). Same "reference/display only, no auto-transition" contract `*_period_days` has
     * everywhere else in this app -- an employee/company with the field left unset (NULL) is simply
     * never checked, not treated as "already expired."
     *
     * @return array<int,array{employee_id:int,employee_no:string,name_th:string,name_en:string,
     *     kind:'probation'|'internship',expiry_date:string,days_remaining:int,
     *     milestone:'expiring_soon'|'expired'}> sorted soonest/most-overdue first
     */
    public function probationInternExpiringEmployees(int $compId): array {
        $policy = (new PayrollPolicyModel($this->db))->get($compId);
        $stmt = $this->db->prepare("SELECT id, employee_no, name_th, name_en, employment_date, employment_status, employment_type,
                probation_period_days_override, intern_period_days_override
            FROM `employees`
            WHERE comp_id = :comp_id AND deleted_at IS NULL
                AND employment_status NOT IN ('resigned', 'terminated')
                AND (employment_status = 'probation' OR employment_type = 'internship')
                AND employment_date IS NOT NULL");
        $stmt->execute([':comp_id' => $compId]);

        $today = new DateTime('today');
        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
            $isIntern = ($e['employment_type'] ?? null) === 'internship';
            $isProbation = ($e['employment_status'] ?? null) === 'probation';
            if ($isIntern) {
                $kind = 'internship';
                $periodDays = $e['intern_period_days_override'] !== null ? (int)$e['intern_period_days_override'] : $policy['intern_period_days'];
            } elseif ($isProbation) {
                $kind = 'probation';
                $periodDays = $e['probation_period_days_override'] !== null ? (int)$e['probation_period_days_override'] : $policy['probation_period_days'];
            } else {
                continue;
            }
            if ($periodDays === null) {
                continue; // nothing configured for this company/employee -- nothing to check
            }
            $expiryDate = (new DateTime((string)$e['employment_date']))->modify("+{$periodDays} days");
            $daysRemaining = (int)$today->diff($expiryDate)->format('%r%a');
            if ($daysRemaining < 0) {
                $milestone = 'expired';
            } elseif ($daysRemaining <= self::PROBATION_INTERN_EXPIRY_SOON_DAYS) {
                $milestone = 'expiring_soon';
            } else {
                continue;
            }
            $results[] = [
                'employee_id' => (int)$e['id'], 'employee_no' => (string)$e['employee_no'],
                'name_th' => (string)$e['name_th'], 'name_en' => (string)$e['name_en'],
                'kind' => $kind, 'expiry_date' => $expiryDate->format('Y-m-d'),
                'days_remaining' => $daysRemaining, 'milestone' => $milestone,
            ];
        }
        usort($results, fn($a, $b) => $a['days_remaining'] <=> $b['days_remaining']);
        return $results;
    }

    /**
     * Lazy check (see this class's own top-of-file docblock for why lazy, not a cron) -- fans out
     * to every `can_process_payroll` holder (the people who'd actually go adjust the employee's
     * status/type manually), one notification per qualifying employee per milestone
     * (`expiring_soon`/`expired`, each its own dedup_key) so a given employee generates AT MOST 2
     * notifications ever per recipient, not a repeat every time the bell is opened.
     */
    public function checkProbationInternExpiring(int $compId): void {
        foreach ($this->probationInternExpiringEmployees($compId) as $e) {
            $kindLabelTh = $e['kind'] === 'internship' ? 'ฝึกงาน' : 'ทดลองงาน';
            $kindLabelEn = $e['kind'] === 'internship' ? 'Internship' : 'Probation';
            if ($e['milestone'] === 'expired') {
                $titleTh = "ครบกำหนด{$kindLabelTh}แล้ว: {$e['name_th']}";
                $titleEn = "{$kindLabelEn} period ended: {$e['name_en']}";
                $msgTh = "สิ้นสุดเมื่อ {$e['expiry_date']} -- โปรดปรับสถานะพนักงานด้วยตนเอง";
                $msgEn = "Ended on {$e['expiry_date']} -- please update the employee's status manually.";
            } else {
                $titleTh = "ใกล้ครบกำหนด{$kindLabelTh}: {$e['name_th']} (อีก {$e['days_remaining']} วัน)";
                $titleEn = "{$kindLabelEn} ending soon: {$e['name_en']} ({$e['days_remaining']} day(s) left)";
                $msgTh = "ครบกำหนด {$e['expiry_date']}";
                $msgEn = "Ends on {$e['expiry_date']}";
            }
            $this->createForPermissionHolders(
                $compId, 'payroll_run.process', 'probation_intern_expiring',
                $titleTh, $titleEn, $msgTh, $msgEn,
                "/employees/{$e['employee_no']}", 'employee', $e['employee_id'],
                "probation_intern_expiring:{$e['employee_id']}:{$e['milestone']}", 'fa-hourglass-end'
            );
        }
    }

    /**
     * Paginated list for one recipient -- backs both the header bell dropdown's lazy/infinite-
     * scroll load and the dedicated "view all" notifications page (same endpoint, dropdown just
     * requests a smaller page size). Runs checkStaleDrafts() once per call (only meaningful on the
     * FIRST page load of a session in practice, thanks to dedup_key) so a fresh reminder can appear
     * without the employee needing to do anything else first.
     */
    public function listForEmployee(int $compId, int $employeeId, int $offset, int $limit, bool $unreadOnly = false): array {
        $this->checkStaleDrafts($compId, $employeeId);
        $this->checkProbationInternExpiring($compId);
        $where = "comp_id = :comp_id AND employee_id = :employee_id";
        $params = [':comp_id' => $compId, ':employee_id' => $employeeId];
        if ($unreadOnly) {
            $where .= " AND is_read = 0";
        }
        $stmt = $this->db->prepare("SELECT * FROM `notifications` WHERE {$where}
            ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 2026-08-29, same-day follow-up: "หน้า /payroll/notifications ให้แสดงเป็นตาราง Datatable และมี Filter
     * วันที่ด้วย" -- server-side DataTables source (recordsTotal/recordsFiltered/data shape, same
     * convention as e.g. EmployeeModel::list()) for the dedicated "view all" page. A per-employee
     * notification history has no natural size cap (accumulates for as long as the account exists),
     * same "server-side for an unbounded list" reasoning this project already applies to Employee
     * List -- unlike listForEmployee() above, which stays a small offset/limit call for the header
     * bell dropdown and Dashboard widget (those never need more than a couple of pages, so
     * serverSide would be needless overhead there).
     */
    public function listDataTable(int $compId, int $employeeId, int $start, int $length, string $search, string $dateFrom, string $dateTo, int $orderCol, string $orderDir, string $isRead = ''): array {
        $this->checkStaleDrafts($compId, $employeeId);
        $this->checkProbationInternExpiring($compId);

        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `notifications` WHERE comp_id = :comp_id AND employee_id = :employee_id");
        $totalStmt->execute([':comp_id' => $compId, ':employee_id' => $employeeId]);
        $total = (int)$totalStmt->fetchColumn();

        $where = "comp_id = :comp_id AND employee_id = :employee_id";
        $params = [':comp_id' => $compId, ':employee_id' => $employeeId];
        if ($dateFrom !== '') {
            $where .= " AND created_at >= :date_from";
            $params[':date_from'] = "{$dateFrom} 00:00:00";
        }
        if ($dateTo !== '') {
            $where .= " AND created_at <= :date_to";
            $params[':date_to'] = "{$dateTo} 23:59:59";
        }
        if ($search !== '') {
            $where .= " AND (title_th LIKE :search OR title_en LIKE :search OR message_th LIKE :search OR message_en LIKE :search)";
            $params[':search'] = "%{$search}%";
        }
        // 2026-08-29, same-day follow-up: system-wide table audit punch-list item -- the Status
        // column (is_read) had no filter dimension at all on this serverSide table.
        if ($isRead === '0' || $isRead === '1') {
            $where .= " AND is_read = :is_read";
            $params[':is_read'] = (int)$isRead;
        }

        $filteredStmt = $this->db->prepare("SELECT COUNT(*) FROM `notifications` WHERE {$where}");
        foreach ($params as $k => $v) {
            $filteredStmt->bindValue($k, $v);
        }
        $filteredStmt->execute();
        $filtered = (int)$filteredStmt->fetchColumn();

        $sortColumns = ['type', 'title_th', 'created_at', 'is_read'];
        $sortColumn = $sortColumns[$orderCol] ?? 'created_at';
        $sortDir = strtolower($orderDir) === 'asc' ? 'ASC' : 'DESC';
        $limit = $length > 0 ? $length : 20;

        $stmt = $this->db->prepare("SELECT * FROM `notifications` WHERE {$where} ORDER BY {$sortColumn} {$sortDir}, id DESC LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $start, PDO::PARAM_INT);
        $stmt->execute();

        return ['total' => $total, 'filtered' => $filtered, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function unreadCount(int $compId, int $employeeId): int {
        $this->checkStaleDrafts($compId, $employeeId);
        $this->checkProbationInternExpiring($compId);
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `notifications` WHERE comp_id = :comp_id AND employee_id = :employee_id AND is_read = 0");
        $stmt->execute([':comp_id' => $compId, ':employee_id' => $employeeId]);
        return (int)$stmt->fetchColumn();
    }

    /** Marks read -- called the moment an employee actually clicks/opens ONE item (see this class's
     *  own top-of-file docblock: "ต้องไปกดเปิดดูก่อนถึงหาย", opening the dropdown alone must NOT mark
     *  anything read). Scoped to employee_id so nobody can mark another person's notification read. */
    public function markRead(int $id, int $compId, int $employeeId): bool {
        $stmt = $this->db->prepare("UPDATE `notifications` SET is_read = 1, read_at = CURRENT_TIMESTAMP
            WHERE id = :id AND comp_id = :comp_id AND employee_id = :employee_id AND is_read = 0");
        $stmt->execute([':id' => $id, ':comp_id' => $compId, ':employee_id' => $employeeId]);
        return true;
    }

    public function markAllRead(int $compId, int $employeeId): bool {
        $stmt = $this->db->prepare("UPDATE `notifications` SET is_read = 1, read_at = CURRENT_TIMESTAMP
            WHERE comp_id = :comp_id AND employee_id = :employee_id AND is_read = 0");
        $stmt->execute([':comp_id' => $compId, ':employee_id' => $employeeId]);
        return true;
    }

    /* ==================== Notification Preferences (2026-08-29) ====================
       Personal (per-employee, Settings modal) + role-level default (admin matrix, Organizational
       Structure > Permissions tab) -- see shouldNotify()'s own docblock for resolution order and
       this file's own migration header comment for why two separate tables. */

    /**
     * Effective (resolved) state of all 5 types for the Settings modal -- also tells the UI whether
     * each one is a PERSONAL override or just inheriting the role/global default, so the modal can
     * show "not explicitly set" distinctly if it ever wants to (current UI just shows the effective
     * checked state either way).
     */
    public function getEmployeePreferences(int $employeeId): array {
        $stmtPersonal = $this->db->prepare("SELECT type, enabled FROM `employee_notification_preferences` WHERE employee_id = :employee_id");
        $stmtPersonal->execute([':employee_id' => $employeeId]);
        $personalByType = [];
        foreach ($stmtPersonal->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $personalByType[$row['type']] = (bool)$row['enabled'];
        }
        $result = [];
        foreach (self::TYPES as $type => $meta) {
            $result[] = [
                'type' => $type,
                'label_th' => $meta['label_th'],
                'label_en' => $meta['label_en'],
                'enabled' => $personalByType[$type] ?? $this->shouldNotify($employeeId, $type),
            ];
        }
        return $result;
    }

    /**
     * Full-replace semantics (same convention as approval_workflow_steps/holiday_assignments) --
     * the Settings modal always submits its complete current checkbox state, not a diff. Every one
     * of the 5 types always ends up with an EXPLICIT personal row after this (even one left
     * checked/"on", same as the global default would already give) -- simpler than trying to track
     * "did the user actually touch this one" per checkbox; harmless since the value matches what
     * would apply anyway.
     */
    public function saveEmployeePreferences(int $employeeId, array $preferences, int $userId): array {
        $clean = [];
        foreach ($preferences as $p) {
            $type = (string)($p['type'] ?? '');
            if (!isset(self::TYPES[$type])) {
                continue;
            }
            $clean[$type] = !empty($p['enabled']);
        }
        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            $stmt = $this->db->prepare("INSERT INTO `employee_notification_preferences` (employee_id, type, enabled, updated_by)
                VALUES (:employee_id, :type, :enabled, :updated_by)
                ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP");
            foreach ($clean as $type => $enabled) {
                $stmt->execute([':employee_id' => $employeeId, ':type' => $type, ':enabled' => $enabled ? 1 : 0, ':updated_by' => $userId]);
            }
            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
        return ['status' => true];
    }

    /**
     * Admin role-default matrix (5 types x N roles), same shape/pattern as
     * PermissionModel::matrix() -- roles as columns, a checked cell means that role receives this
     * type by DEFAULT (an individual's own personal preference, if they ever set one, still wins).
     */
    public function roleMatrix(int $compId): array {
        $stmtRoles = $this->db->prepare("SELECT id, role_name_th, role_name_en FROM `structure_roles`
            WHERE comp_id = :comp_id AND deleted_at IS NULL AND status = 'active' ORDER BY role_name_th ASC");
        $stmtRoles->execute([':comp_id' => $compId]);
        $roles = $stmtRoles->fetchAll(PDO::FETCH_ASSOC);

        $types = [];
        foreach (self::TYPES as $type => $meta) {
            $types[] = ['type' => $type, 'label_th' => $meta['label_th'], 'label_en' => $meta['label_en']];
        }

        $stmtGrants = $this->db->prepare("SELECT rnp.role_id, rnp.type, rnp.enabled FROM `role_notification_preferences` rnp
            JOIN `structure_roles` r ON r.id = rnp.role_id
            WHERE r.comp_id = :comp_id AND r.deleted_at IS NULL");
        $stmtGrants->execute([':comp_id' => $compId]);
        $grants = [];
        foreach ($stmtGrants->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $grants[] = ['role_id' => (int)$g['role_id'], 'type' => $g['type'], 'enabled' => (bool)$g['enabled']];
        }

        return ['roles' => $roles, 'types' => $types, 'grants' => $grants];
    }

    /**
     * Full-replace semantics, same "delete-then-reinsert" shape as PermissionModel::saveMatrix()
     * -- deletes every role_notification_preferences row for this company's roles, then re-inserts
     * exactly the submitted grid. UNLIKE Permission Matrix (where an unchecked cell means "submit
     * nothing, no row at all, deny by default"), the notification default is the OPPOSITE polarity
     * (no row = enabled) -- so here the caller must submit an explicit row for EVERY cell it wants
     * to actually persist, both checked (enabled=1) AND unchecked (enabled=0); a cell genuinely
     * left out of the payload ends up with no row at all, i.e. reverts to the true default
     * (enabled), not "off". See notification-preferences-matrix.js's own submit handler, which
     * sends one entry per rendered cell for exactly this reason.
     */
    public function saveRoleMatrix(int $compId, array $grants, int $userId): array {
        $stmtRoles = $this->db->prepare("SELECT id FROM `structure_roles` WHERE comp_id = :comp_id AND deleted_at IS NULL");
        $stmtRoles->execute([':comp_id' => $compId]);
        $validRoleIds = array_map('intval', array_column($stmtRoles->fetchAll(PDO::FETCH_ASSOC), 'id'));
        $validRoleIdSet = array_flip($validRoleIds);

        $clean = [];
        $seen = [];
        foreach ($grants as $g) {
            $roleId = (int)($g['role_id'] ?? 0);
            $type = (string)($g['type'] ?? '');
            if (!isset($validRoleIdSet[$roleId])) {
                return ['status' => false, 'message' => 'Invalid role selection.'];
            }
            if (!isset(self::TYPES[$type])) {
                return ['status' => false, 'message' => 'Invalid notification type.'];
            }
            $key = $roleId . ':' . $type;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $clean[] = [$roleId, $type, !empty($g['enabled'])];
        }

        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            if (!empty($validRoleIds)) {
                $placeholders = implode(',', array_fill(0, count($validRoleIds), '?'));
                $this->db->prepare("DELETE FROM `role_notification_preferences` WHERE role_id IN ({$placeholders})")->execute($validRoleIds);
            }
            if (!empty($clean)) {
                $ins = $this->db->prepare("INSERT INTO `role_notification_preferences` (role_id, type, enabled, updated_by) VALUES (?, ?, ?, ?)");
                foreach ($clean as [$roleId, $type, $enabled]) {
                    $ins->execute([$roleId, $type, $enabled ? 1 : 0, $userId]);
                }
            }
            if ($own) { $this->db->commit(); }
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
        return ['status' => true];
    }
}
