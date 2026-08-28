<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/sync/OrigamiEmployeeCandidateClient.php';
require_once __DIR__ . '/../services/sync/EmployeeSyncer.php';
require_once __DIR__ . '/SyncBatchModel.php';

/**
 * Interactive "Sync Employee from Origami" picker (Employee List page, 2026-08-28, explicit
 * request: filter by department/type/position/team, browse the results, split into New vs.
 * Already Exists, and selectively pull records in -- matching by Origami's own ref id first, this
 * app's own employee_no ("payroll code") second, logging every apply as a sync_batches row).
 *
 * Deliberately a SEPARATE entry point from MasterDataSyncOrchestrator::syncEntity('employee', ...)
 * (the existing bulk auto-apply engine, still unwired to any UI) rather than a mode flag bolted
 * onto it -- that engine fetches+applies the WHOLE company roster in one shot with no review step,
 * a fundamentally different shape from "browse filtered candidates, tick some, apply just those".
 * Both ultimately write through EmployeeSyncer (this one via its new applyOne(), the bulk engine
 * via sync()) and both log to the SAME sync_batches table (entity_type='employee', source='sync'),
 * so there is exactly one place that knows how to write an Origami employee record and exactly one
 * audit trail for it, regardless of which entry point was used.
 *
 * The candidate FETCH itself is backed by OrigamiEmployeeCandidateClient, a REAL HTTP client as of
 * 2026-08-28 (see that class's own docblock + docs/origami-employee-sync-api-guide.md) -- switched
 * from an earlier mock once Origami's dev team confirmed real config. Every call into it here is
 * wrapped in try/catch: filter-options.php is live, but candidates.php is still blocked on
 * Origami's side, so fetchCandidates() genuinely throws (404) until that piece exists -- caught
 * and returned as a clear `{status:false, message:...}` rather than an uncaught 500.
 *
 * 2026-08-28, explicit follow-up: every public method here calls requireConnected() FIRST and
 * refuses outright ("not connected", not mock data) unless ORIGAMI_API_BASE_URL/ORIGAMI_API_KEY
 * are both actually set -- the mock client above is exercised by this app's own tests, never by a
 * real user through the picker UI. See requireConnected()'s own docblock.
 *
 * 2026-08-28, explicit follow-up: the Department/Position/Team/Type filter OPTIONS themselves are
 * now also sourced from Origami (filterOptions(), backed by the same mock client's
 * fetchFilterOptions()) rather than from Payroll's own local structure_departments/
 * structure_positions/structure_teams tables -- an admin can only pick a filter value Origami
 * itself can answer for. candidates()/apply() pass whatever was picked straight through with no
 * local-id translation step (there used to be one; see git history / this file's own prior
 * revision if that's ever needed again).
 */
class EmployeeSyncModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    private function origamiCompanyRefId(int $compId): ?int {
        $stmt = $this->db->prepare("SELECT ref_id FROM companies WHERE id = :id");
        $stmt->execute([':id' => $compId]);
        $refId = $stmt->fetchColumn();
        return ($refId === false || $refId === null) ? null : (int)$refId;
    }

    /** 2026-08-28, explicit request: "ถ้ายังเชื่อมไม่ได้ก็ควรแจ้งว่าเชื่อมไม่ได้ ไม่ใช่ Mock Data" (if we
     *  still can't connect, say so, don't show Mock Data) -- checked FIRST in every public method
     *  below, before OrigamiEmployeeCandidateClient ever gets called at all. Confirmed via
     *  AskUserQuestion: block the picker entirely until ORIGAMI_API_BASE_URL/ORIGAMI_API_KEY are
     *  both set (see OrigamiEmployeeCandidateClient::isConfigured()) -- no admin-only mock preview
     *  path. `not_connected: true` is a distinct flag from a generic `status: false` error so the
     *  picker UI can render a real "not connected" state (hide the filter/fetch UI entirely)
     *  instead of just toasting a warning and leaving a confusing empty picker behind. */
    private function requireConnected(): ?array {
        if (!OrigamiEmployeeCandidateClient::isConfigured()) {
            return ['status' => false, 'not_connected' => true,
                'message' => 'Not connected to Origami yet. The connection has not been configured -- please contact your system administrator.'];
        }
        return null;
    }

    /** @param array{department_ref_id:?int, position_ref_id:?int, team_ref_id:?int, type:?string} $rawFilters */
    private function normalizeFilters(int $compId, array $rawFilters): array {
        return [
            'comp_id' => $compId,
            'department_ref_id' => !empty($rawFilters['department_ref_id']) ? (int)$rawFilters['department_ref_id'] : null,
            'position_ref_id' => !empty($rawFilters['position_ref_id']) ? (int)$rawFilters['position_ref_id'] : null,
            'team_ref_id' => !empty($rawFilters['team_ref_id']) ? (int)$rawFilters['team_ref_id'] : null,
            'type' => !empty($rawFilters['type']) ? (string)$rawFilters['type'] : null,
        ];
    }

    /** Local employees.id matching a candidate, by origami_ref_id first, employee_no ("payroll
     *  code") second -- same 2-step precedence EmployeeSyncer::sync()/importRow() already use.
     *  Null when neither matches (a genuinely new candidate). */
    private function findLocalMatch(int $compId, array $candidate): ?int {
        $refId = (int)$candidate['ref_id'];
        $stmt = $this->db->prepare("SELECT id FROM employees WHERE origami_ref_id = :ref AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':ref' => $refId, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int)$id;
        }
        $employeeNo = trim((string)($candidate['employee_no'] ?? ''));
        if ($employeeNo === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM employees WHERE employee_no = :no AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':no' => $employeeNo, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** Compares only the HR-owned core fields EmployeeSyncer itself would overwrite on a re-sync
     *  (see that class's own "field ownership" docblock) -- payroll-owned fields (salary, bank,
     *  tax method) are never touched by a sync and so are irrelevant to "is there an update".
     *  Department/position are deliberately NOT diffed here -- doing so correctly would need
     *  reverse-resolving the candidate's origami_ref_id back to a local id, which only matters once
     *  this company's department/position master data has actually been synced from Origami (see
     *  OrigamiEmployeeCandidateClient's own docblock); until then every candidate's department/
     *  position link is empty anyway, so a diff there would just be noise.
     *  @return array{has_update:bool, changed_fields:string[]} */
    private function diffAgainstLocal(array $candidate, array $local): array {
        $fields = [
            'name_th' => 'name_th', 'surname_th' => 'surname_th', 'name_en' => 'name_en', 'surname_en' => 'surname_en',
            'date_of_birth' => 'date_of_birth', 'gender' => 'gender',
            'personal_email' => 'personal_email', 'mobile_no' => 'mobile_no',
            'employment_date' => 'employment_date', 'employment_status' => 'employment_status',
        ];
        $changed = [];
        foreach ($fields as $candidateKey => $localKey) {
            $candidateVal = trim((string)($candidate[$candidateKey] ?? ''));
            $localVal = trim((string)($local[$localKey] ?? ''));
            if ($candidateVal !== '' && $candidateVal !== $localVal) {
                $changed[] = $candidateKey;
            }
        }
        return ['has_update' => !empty($changed), 'changed_fields' => $changed];
    }

    /** Filter option lists (Department/Position/Team/Type) sourced FROM Origami itself -- see
     *  OrigamiEmployeeCandidateClient::fetchFilterOptions()'s own docblock. The picker's filter
     *  dropdowns are populated from this, not from Payroll's own local structure_departments/
     *  structure_positions/structure_teams tables. */
    public function filterOptions(int $compId): array {
        if ($err = $this->requireConnected()) {
            return $err;
        }
        $origamiCompanyId = $this->origamiCompanyRefId($compId);
        if ($origamiCompanyId === null) {
            return ['status' => false, 'message' => 'This company is not linked to an Origami HR company yet. Set the Origami reference ID in Company Profile first.'];
        }
        try {
            $options = (new OrigamiEmployeeCandidateClient($this->db))->fetchFilterOptions($origamiCompanyId, $compId);
        } catch (Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
        return array_merge(['status' => true], $options);
    }

    /** $rawFilters values are Origami-native ref ids/type strings straight from the filter
     *  dropdowns (themselves populated via filterOptions() above) -- passed straight through to
     *  fetchCandidates() with NO local-id translation step, unlike an earlier version of this
     *  method that translated a locally-picked department/position id into its origami_ref_id.
     *  That translation step is gone entirely now that the filters themselves are Origami-native. */
    public function candidates(int $compId, array $rawFilters): array {
        if ($err = $this->requireConnected()) {
            return $err;
        }
        $origamiCompanyId = $this->origamiCompanyRefId($compId);
        if ($origamiCompanyId === null) {
            return ['status' => false, 'message' => 'This company is not linked to an Origami HR company yet. Set the Origami reference ID in Company Profile first.'];
        }

        $filters = $this->normalizeFilters($compId, $rawFilters);
        $client = new OrigamiEmployeeCandidateClient($this->db);
        try {
            $rows = $client->fetchCandidates($origamiCompanyId, $filters);
        } catch (Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }

        $new = [];
        $existing = [];
        foreach ($rows as $row) {
            $localId = $this->findLocalMatch($compId, $row);
            if ($localId === null) {
                $row['match_status'] = 'new';
                $new[] = $row;
                continue;
            }
            $stmt = $this->db->prepare("SELECT * FROM employees WHERE id = :id");
            $stmt->execute([':id' => $localId]);
            $local = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $diff = $this->diffAgainstLocal($row, $local);
            $row['match_status'] = 'existing';
            $row['existing_employee_id'] = $localId;
            $row['existing_employee_no'] = $local['employee_no'] ?? null;
            $row['has_update'] = $diff['has_update'];
            $row['changed_fields'] = $diff['changed_fields'];
            $existing[] = $row;
        }

        return ['status' => true, 'new' => $new, 'existing' => $existing];
    }

    /** Refetches fresh from the (mock/future-real) client using the SAME filters the picker showed
     *  -- never trusts client-echoed candidate rows for what actually gets written, same "always
     *  read live, never trust cached row data" convention this app already follows for DataTable
     *  Edit actions. Anything in $selectedRefIds no longer present in a fresh fetch is recorded as
     *  a per-item error rather than silently skipped. */
    public function apply(int $compId, array $rawFilters, array $selectedRefIds, int $userId): array {
        if ($err = $this->requireConnected()) {
            return $err;
        }
        $origamiCompanyId = $this->origamiCompanyRefId($compId);
        if ($origamiCompanyId === null) {
            return ['status' => false, 'message' => 'This company is not linked to an Origami HR company yet. Set the Origami reference ID in Company Profile first.'];
        }
        $selectedRefIds = array_values(array_unique(array_map('intval', $selectedRefIds)));
        if (empty($selectedRefIds)) {
            return ['status' => false, 'message' => 'No records selected.'];
        }

        $filters = $this->normalizeFilters($compId, $rawFilters);
        $client = new OrigamiEmployeeCandidateClient($this->db);
        try {
            $rows = $client->fetchCandidates($origamiCompanyId, $filters);
        } catch (Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
        $byRefId = [];
        foreach ($rows as $row) {
            $byRefId[(int)$row['ref_id']] = $row;
        }

        $batchModel = new SyncBatchModel($this->db);
        $batchId = $batchModel->start($compId, 'employee', 'sync', 'manual', $userId);
        $syncer = new EmployeeSyncer($this->db);
        $success = 0;
        $errors = [];
        foreach ($selectedRefIds as $refId) {
            if (!isset($byRefId[$refId])) {
                $errors[] = ['ref_id' => $refId, 'message' => 'This candidate is no longer available -- please refresh and try again.'];
                continue;
            }
            try {
                $syncer->applyOne($compId, $byRefId[$refId], $batchId, $userId);
                $success++;
            } catch (Throwable $e) {
                $errors[] = ['ref_id' => $refId, 'message' => $e->getMessage()];
            }
        }
        $batchModel->complete($batchId, count($selectedRefIds), $success, count($errors), $errors);

        return ['status' => true, 'batch_id' => $batchId, 'total' => count($selectedRefIds), 'success' => $success, 'error' => count($errors), 'errors' => $errors];
    }

    /**
     * 2026-08-28, explicit request: "เพิ่มปุ่ม Re Sync รายบุคคลของพนักงาน" -- a single-employee
     * re-sync from Employee Detail, without going through the List picker's browse/filter/select
     * flow. Confirmed via AskUserQuestion: this is Employee Sync (Origami HR), not Payroll Sync.
     *
     * Only usable for an employee that already has origami_ref_id set (i.e. was previously synced
     * or otherwise linked) -- there is no "search Origami by this employee's Payroll-side name"
     * concept, ref_id is the one reliable link. `fetchCandidates()` has no ref_id filter of its own
     * (only department/position/team/type, per the API guide), so this fetches the FULL unfiltered
     * candidate list and finds the one matching row -- same "always re-fetch fresh, never trust a
     * cached row" rule apply() already follows, just without a filter to narrow it first.
     */
    public function resyncOne(int $compId, int $employeeId, int $userId): array {
        if ($err = $this->requireConnected()) {
            return $err;
        }
        $origamiCompanyId = $this->origamiCompanyRefId($compId);
        if ($origamiCompanyId === null) {
            return ['status' => false, 'message' => 'This company is not linked to an Origami HR company yet. Set the Origami reference ID in Company Profile first.'];
        }
        $stmt = $this->db->prepare("SELECT origami_ref_id FROM employees WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $refId = $stmt->fetchColumn();
        if ($refId === false) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        if ($refId === null) {
            return ['status' => false, 'message' => 'This employee has no Origami reference id -- they were never linked to an Origami HR record, so there is nothing to re-sync from.'];
        }
        $refId = (int)$refId;

        try {
            $rows = (new OrigamiEmployeeCandidateClient($this->db))->fetchCandidates($origamiCompanyId, []);
        } catch (Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage()];
        }
        $match = null;
        foreach ($rows as $row) {
            if ((int)$row['ref_id'] === $refId) {
                $match = $row;
                break;
            }
        }
        if ($match === null) {
            return ['status' => false, 'message' => 'This employee (Origami ref_id ' . $refId . ') was not found in Origami\'s current candidate list -- they may have been removed or reassigned there.'];
        }

        $batchModel = new SyncBatchModel($this->db);
        $batchId = $batchModel->start($compId, 'employee', 'sync', 'manual', $userId);
        $syncer = new EmployeeSyncer($this->db);
        try {
            $syncer->applyOne($compId, $match, $batchId, $userId);
            $batchModel->complete($batchId, 1, 1, 0, []);
            return ['status' => true, 'message' => 'Re-synced successfully.', 'batch_id' => $batchId];
        } catch (Throwable $e) {
            $batchModel->complete($batchId, 1, 0, 1, [['ref_id' => $refId, 'message' => $e->getMessage()]]);
            return ['status' => false, 'message' => $e->getMessage()];
        }
    }

    /** 2026-08-28, same request as resyncOne() -- "last synced" summary card on Employee Detail.
     *  Confirmed via AskUserQuestion: latest-only, no new history table -- employees.sync_batch_id
     *  already tracks "last batch to touch this row" (updates on every touch per its own
     *  established convention, independent of data_source which tracks origin only), so joining it
     *  to sync_batches gives an accurate "last synced at / outcome" without any new schema. */
    public function lastSyncSummary(int $compId, int $employeeId): ?array {
        $stmt = $this->db->prepare("SELECT e.data_source, e.origami_ref_id, b.id AS batch_id, b.status, b.started_at, b.success_count, b.error_count
            FROM employees e
            LEFT JOIN sync_batches b ON b.id = e.sync_batch_id
            WHERE e.id = :id AND e.comp_id = :comp_id AND e.deleted_at IS NULL");
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function log(int $compId, int $limit = 50): array {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->prepare("SELECT b.*, e.name_th AS triggered_by_name_th, e.surname_th AS triggered_by_surname_th,
                e.name_en AS triggered_by_name_en, e.surname_en AS triggered_by_surname_en
            FROM sync_batches b
            LEFT JOIN employees e ON e.id = b.triggered_by
            WHERE b.comp_id = :comp_id AND b.entity_type = 'employee' AND b.source = 'sync'
            ORDER BY b.started_at DESC LIMIT {$limit}");
        $stmt->execute([':comp_id' => $compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['error_detail'] = $row['error_detail'] ? json_decode((string)$row['error_detail'], true) : [];
        }
        unset($row);
        return $rows;
    }
}
