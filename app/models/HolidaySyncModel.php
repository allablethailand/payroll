<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/sync/HolidayGoogleCalendarClient.php';
require_once __DIR__ . '/../services/sync/HolidaySyncer.php';
require_once __DIR__ . '/SyncBatchModel.php';

/**
 * Interactive "Sync Holidays from Google Calendar" picker (Time & Leave > Setup & Rules > Holiday
 * tab, 2026-08-28, explicit request: "เพิ่มให้ Sync ข้อมูลวันหยุดตามประกาศจาก API ที่มี...แต่ต้อง Map
 * กับข้อมูลที่มีแล้ว แล้วค่อยมานำตั้งค่าให้พนักงานต่อ หรือถ้าเข้าเงื่อนไขก็ Assign ไปอัตโนมัติ").
 * Same architecture as this session's EmployeeSyncModel: fetch, match against local data, split
 * into New vs. Already Exists, admin ticks which to pull in, every apply logged.
 *
 * Deliberately a SEPARATE entry point from HolidaySyncer::sync() (the existing bulk auto-apply
 * engine, part of the original Origami HR sync scaffolding, still unwired to any UI) rather than a
 * mode flag bolted onto it -- same reasoning as EmployeeSyncModel vs. EmployeeSyncer::sync(): that
 * engine fetches+applies everything in one shot with no review step, a fundamentally different
 * shape from "browse candidates, tick some, apply just those." Both ultimately write through
 * HolidaySyncer's own upsertItem() (this one via the new applyResolved(), the bulk engine via
 * sync()) and both log to the SAME sync_batches table (entity_type='holiday'), so there is exactly
 * one place that knows how to write a holiday from external data and exactly one audit trail for
 * it, regardless of entry point.
 *
 * "Assign ไปอัตโนมัติ" (auto-assign if conditions are met): every holiday HolidaySyncer::upsertItem()
 * inserts already gets `assignment_mode='exclude'` with NO holiday_assignments rows -- this app's
 * own existing convention for "applies to the whole company, no scope restriction" (see that
 * class's own docblock). That already IS the auto-assign this request asks for: a synced-and-
 * applied holiday takes effect for every employee immediately, no extra manual assignment step
 * required. "แล้วค่อยมานำตั้งค่าให้พนักงานต่อ" (then go configure for employees afterward) is
 * satisfied by the EXISTING Holiday edit modal -- an admin can narrow the scope (shift/department/
 * position/employee) for any specific synced holiday afterward, same as for a manually-created one.
 * No new schema or scope-matching logic was added for this -- see this model's own religion/
 * ethnicity note below for why.
 *
 * Religion/ethnicity ("ถ้ามีตามศาสนา เชื้อชาติ...ก็ให้ดึงมา"): NOT implemented. Confirmed by research
 * before building this: (1) Google's Thailand public holiday calendar has no per-event
 * religion/ethnicity field at all -- it's a flat list of dates+names; (2) `holiday_assignments.
 * scope_type` has no 'religion'/'nationality' option today (only shift/department/position/
 * employee); (3) `employees.religion`/`employees.nationality` (plain varchar, not FK'd) are not
 * used anywhere for eligibility logic today. More fundamentally: Thailand's OWN official public
 * holidays are declared nationally for every employer regardless of employee religion (including
 * the Buddhist observances in this calendar, e.g. Makha Bucha -- these are statutory holidays for
 * ALL Thai employees, not just Buddhist ones), so there is no real religion-based differentiation
 * need for the Thailand feed specifically. Adding a religion/ethnicity scope dimension would be a
 * real, separate schema change (widen the enum, build new matching logic) with no concrete Thai use
 * case to build it against right now -- deliberately left as a documented gap, not a silent
 * omission, for whenever a country whose calendar genuinely needs it is added.
 */
class HolidaySyncModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** Unlike EmployeeSyncModel's own gate (missing API credentials), GOOGLE_CALENDAR_API_KEY is a
     *  real, working key already -- this still exists as a defensive check (never silently proceed
     *  with an empty key) and because the SAME "refuse clearly, never fabricate data" principle
     *  applies here as it did for Employee Sync, should the key ever get unset/rotated out. */
    private function requireConnected(): ?array {
        if (GOOGLE_CALENDAR_API_KEY === '') {
            return ['status' => false, 'not_connected' => true,
                'message' => 'Google Calendar sync is not configured yet. Please contact your system administrator.'];
        }
        return null;
    }

    private function companyCountry(int $compId): ?string {
        $stmt = $this->db->prepare("SELECT registered_country FROM companies WHERE id = :id");
        $stmt->execute([':id' => $compId]);
        $country = $stmt->fetchColumn();
        return ($country === false || $country === null || $country === '') ? null : (string)$country;
    }

    /** Matches by DATE ALONE (not date+name like HolidaySyncer::findByNaturalKey()) -- see
     *  applyResolved()'s own docblock in HolidaySyncer.php for why the review step needs this
     *  looser match: an admin needs to see "you already have something on this date" even if
     *  Google's own wording differs slightly from what's saved locally. Consistent with this app's
     *  own "one holiday per date" convention (SetupRulesModel::holidaySave()'s scope-blind
     *  date-collision check). */
    private function findLocalMatch(int $compId, string $holidayDate): ?int {
        $stmt = $this->db->prepare("SELECT id FROM holidays WHERE comp_id = :comp AND holiday_date = :date AND deleted_at IS NULL");
        $stmt->execute([':comp' => $compId, ':date' => $holidayDate]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    /** @return array{status:bool, not_connected?:bool, message?:string, new?:array, existing?:array} */
    public function candidates(int $compId, int $year): array {
        if ($err = $this->requireConnected()) {
            return $err;
        }
        $country = $this->companyCountry($compId);
        if ($country !== 'TH') {
            // TH-only for now, per explicit request -- HolidayGoogleCalendarClient itself only
            // knows the one hardcoded Thailand calendar id right now (see that class's own
            // docblock on extending to other countries later).
            return ['status' => false, 'message' => 'Holiday sync from Google Calendar currently only supports companies registered in Thailand.'];
        }

        $client = new HolidayGoogleCalendarClient();
        try {
            $items = $client->fetchThaiHolidays($year);
        } catch (Throwable $e) {
            return ['status' => false, 'message' => 'Failed to fetch holidays from Google Calendar: ' . $e->getMessage()];
        }

        $new = [];
        $existing = [];
        foreach ($items as $item) {
            $localId = $this->findLocalMatch($compId, $item['holiday_date']);
            if ($localId === null) {
                $item['match_status'] = 'new';
                $new[] = $item;
                continue;
            }
            $stmt = $this->db->prepare("SELECT name_th, name_en, is_recurring FROM holidays WHERE id = :id");
            $stmt->execute([':id' => $localId]);
            $local = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $item['match_status'] = 'existing';
            $item['existing_holiday_id'] = $localId;
            $item['existing_name_th'] = $local['name_th'] ?? null;
            $item['has_update'] = ($local['name_th'] ?? null) !== $item['name_th'];
            $existing[] = $item;
        }

        return ['status' => true, 'new' => $new, 'existing' => $existing];
    }

    /** Refetches fresh from Google using the SAME year the picker showed -- never trusts
     *  client-echoed candidate rows for what actually gets written, same convention as
     *  EmployeeSyncModel::apply(). Anything in $selectedDates no longer present in a fresh fetch is
     *  recorded as a per-item error rather than silently skipped. */
    public function apply(int $compId, int $year, array $selectedDates, int $userId): array {
        if ($err = $this->requireConnected()) {
            return $err;
        }
        $selectedDates = array_values(array_unique(array_map('strval', $selectedDates)));
        if (empty($selectedDates)) {
            return ['status' => false, 'message' => 'No holidays selected.'];
        }

        $client = new HolidayGoogleCalendarClient();
        try {
            $items = $client->fetchThaiHolidays($year);
        } catch (Throwable $e) {
            return ['status' => false, 'message' => 'Failed to fetch holidays from Google Calendar: ' . $e->getMessage()];
        }
        $byDate = [];
        foreach ($items as $item) {
            $byDate[$item['holiday_date']] = $item;
        }

        $batchModel = new SyncBatchModel($this->db);
        $batchId = $batchModel->start($compId, 'holiday', 'sync', 'manual', $userId);
        $syncer = new HolidaySyncer($this->db);
        $success = 0;
        $errors = [];
        foreach ($selectedDates as $date) {
            if (!isset($byDate[$date])) {
                $errors[] = ['date' => $date, 'message' => 'This holiday is no longer available -- please refresh and try again.'];
                continue;
            }
            try {
                $existingId = $this->findLocalMatch($compId, $date);
                $syncer->applyResolved($compId, $byDate[$date], $batchId, $userId, $existingId);
                $success++;
            } catch (Throwable $e) {
                $errors[] = ['date' => $date, 'message' => $e->getMessage()];
            }
        }
        $batchModel->complete($batchId, count($selectedDates), $success, count($errors), $errors);

        return ['status' => true, 'batch_id' => $batchId, 'total' => count($selectedDates), 'success' => $success, 'error' => count($errors), 'errors' => $errors];
    }

    public function log(int $compId, int $limit = 50): array {
        $limit = max(1, min(200, $limit));
        $stmt = $this->db->prepare("SELECT b.*, e.name_th AS triggered_by_name_th, e.surname_th AS triggered_by_surname_th,
                e.name_en AS triggered_by_name_en, e.surname_en AS triggered_by_surname_en
            FROM sync_batches b
            LEFT JOIN employees e ON e.id = b.triggered_by
            WHERE b.comp_id = :comp_id AND b.entity_type = 'holiday' AND b.source = 'sync'
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
