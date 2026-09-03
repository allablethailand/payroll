<?php
declare(strict_types=1);
require_once __DIR__ . '/OrigamiSyncClientInterface.php';

/**
 * 2026-09-02, real implementation for the 6 master-data endpoints + company profile -- confirmed
 * live by reading Origami's own actual source directly (`origami/api/hr/master/{type}.php` and
 * `origami/api/hr/company.php`), same "read the real source, don't guess" verification this
 * codebase already used for `OrigamiEmployeeCandidateClient`'s own `candidates.php`/
 * `filter-options.php`. Origami's own team confirmed (2026-09-01) these 7 endpoints are live
 * against production data, not draft/untested.
 *
 * `fetchEmployees()`/`fetchAttendance()`/`fetchLeaveRequests()`/`fetchOvertimeRecords()`/
 * `fetchLeaveTypes()`/`fetchOtRates()` remain STUBS -- no real Origami endpoint exists for bulk
 * employee/attendance/leave/OT pull, or for leave-type/OT-rate master data, at all (confirmed by
 * the same source survey: only `employees/candidates.php` -- the INTERACTIVE, filtered picker
 * already wired via `OrigamiEmployeeCandidateClient`, a deliberately separate class/contract, see
 * its own docblock -- and the 7 endpoints below exist). Do not implement these speculatively.
 *
 * Response shapes below come straight from Origami's own real endpoints, which differ in small,
 * confirmed ways from `OrigamiSyncClientInterface`'s own speculative shapes (written before any
 * real endpoint existed) -- each fetch*() method here does the minimum TRANSLATION needed
 * (mirroring a single `name` into both name_th/name_en when Origami has no bilingual pair, mapping
 * `date`->`holiday_date`, etc.) so every Syncer class downstream (DepartmentSyncer/ShiftSyncer/
 * etc.) needed ZERO changes -- exactly the "nothing else in the sync engine needs to change"
 * promise this class's own prior stub docblock made.
 */
class OrigamiSyncClient implements OrigamiSyncClientInterface {
    private function notConfigured(string $method): void {
        throw new RuntimeException("OrigamiSyncClient::{$method}() is not implemented yet. Set ORIGAMI_API_BASE_URL/ORIGAMI_API_KEY in .env and implement this method against the real Origami HR API before syncing.");
    }

    /** Same auth/error-handling shape as OrigamiEmployeeCandidateClient::callRealApi() -- deliberately
     *  duplicated, not shared, per this codebase's own "two independent sync engines, don't force a
     *  shared dependency" precedent (see EmployeeSyncer's own top docblock). $path is the full
     *  relative path after `/api/hr/` (e.g. `master/departments`, `company`) since, unlike the
     *  employee-candidate endpoints, these aren't all nested under one shared prefix. */
    private function callRealApi(string $path, array $query): array {
        if (!self::isConfigured()) {
            throw new RuntimeException('OrigamiSyncClient is not configured -- ORIGAMI_API_BASE_URL/ORIGAMI_API_KEY are not set.');
        }
        $url = ORIGAMI_API_BASE_URL . '/api/hr/' . $path . '?' . http_build_query($query);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . ORIGAMI_API_KEY,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrno !== 0) {
            throw new RuntimeException("Could not reach Origami ({$path}): {$curlError}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("Origami returned HTTP {$httpCode} for {$path}.");
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Origami returned an invalid (non-JSON) response for {$path}.");
        }
        if (($decoded['status'] ?? null) !== true) {
            $msg = is_string($decoded['message'] ?? null) ? $decoded['message'] : 'Origami reported a failure with no message.';
            throw new RuntimeException("Origami rejected the {$path} request: {$msg}");
        }
        return $decoded;
    }

    public static function isConfigured(): bool {
        return defined('ORIGAMI_API_BASE_URL') && defined('ORIGAMI_API_KEY') && ORIGAMI_API_BASE_URL !== '' && ORIGAMI_API_KEY !== '';
    }

    /** `master/departments.php` -- passthrough, already matches the interface shape (extra
     *  parent_ref_id/sort_no fields are harmless, DepartmentSyncer's own upsertItem() ignores them). */
    public function fetchDepartments(int $origamiCompanyId): array {
        $decoded = $this->callRealApi('master/departments', ['company_ref_id' => $origamiCompanyId]);
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    /** `master/positions.php` -- passthrough, same shape compatibility as departments above. */
    public function fetchPositions(int $origamiCompanyId): array {
        $decoded = $this->callRealApi('master/positions', ['company_ref_id' => $origamiCompanyId]);
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    /** `master/shifts.php` -- Origami sends a single `name` (no bilingual pair) and no
     *  `work_location_code` at all (that's a Payroll-only concept) -- mirror name into both
     *  name_th/name_en, work_location_code always null (ShiftSyncer already treats a null/absent
     *  code as "no location", never guesses one). */
    public function fetchShifts(int $origamiCompanyId): array {
        $decoded = $this->callRealApi('master/shifts', ['company_ref_id' => $origamiCompanyId]);
        $rows = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        return array_map(static function (array $r): array {
            $name = (string)($r['name'] ?? '');
            return [
                'ref_id' => $r['ref_id'] ?? null, 'code' => $r['code'] ?? null,
                'name_th' => $name, 'name_en' => $name,
                'start_time' => $r['start_time'] ?? null, 'end_time' => $r['end_time'] ?? null,
                'break_minutes' => $r['break_minutes'] ?? null, 'work_location_code' => null,
                'is_active' => $r['is_active'] ?? true,
            ];
        }, $rows);
    }

    /** `master/branches.php` -- passthrough; single `name` (mirrored into name_th/name_en by
     *  BranchSyncer itself, not here, since BranchSyncer already has that fallback chain built for
     *  the employee-sync auto-create path too) plus `is_default`. */
    public function fetchBranches(int $origamiCompanyId): array {
        $decoded = $this->callRealApi('master/branches', ['company_ref_id' => $origamiCompanyId]);
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    /** `master/teams.php` -- passthrough; single `name` (mirrored by TeamSyncer itself), no code at all. */
    public function fetchTeams(int $origamiCompanyId): array {
        $decoded = $this->callRealApi('master/teams', ['company_ref_id' => $origamiCompanyId]);
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    /** `master/holidays.php` -- Origami has no `is_recurring` concept (its own `day_off` table is a
     *  flat, re-entered-every-year list, confirmed by reading its query directly) and no bilingual
     *  name pair, and the endpoint itself already filters to active rows server-side (no `is_active`
     *  key at all in its response) -- name mirrored into both th/en, is_recurring always false,
     *  is_active always true. $year is optional on Origami's side (omit for every year on file) --
     *  not exposed here since HolidaySyncer's own fetch() signature takes no date range; a future
     *  year-scoped re-sync would need a signature change, out of scope for this round. */
    public function fetchHolidays(int $origamiCompanyId): array {
        $decoded = $this->callRealApi('master/holidays', ['company_ref_id' => $origamiCompanyId]);
        $rows = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        return array_map(static function (array $r): array {
            $name = (string)($r['name'] ?? '');
            return [
                'ref_id' => $r['ref_id'] ?? null,
                'name_th' => $name, 'name_en' => $name,
                'holiday_date' => $r['date'] ?? null,
                'is_recurring' => false,
                'is_active' => true,
            ];
        }, $rows);
    }

    /** `company.php` -- single-object response (not a list), so this deliberately does NOT match
     *  MasterDataSyncerInterface's list-shaped fetch() contract at all; consumed directly by
     *  CompanyProfileModel::syncFromOrigami() instead of a Syncer subclass -- see that method's own
     *  docblock for why a single-row, always-UPDATE-never-INSERT entity doesn't fit the
     *  insert-or-update-many-rows shape every other syncer here is built around. Returns null when
     *  Origami has no company for this ref_id (404) rather than throwing, since that's a genuinely
     *  different, recoverable case from "the request itself failed."
     */
    public function fetchCompany(int $origamiCompanyId): ?array {
        try {
            $decoded = $this->callRealApi('company', ['company_ref_id' => $origamiCompanyId]);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return null;
            }
            throw $e;
        }
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : null;
    }

    public function fetchLeaveTypes(int $origamiCompanyId): array { $this->notConfigured(__FUNCTION__); }
    public function fetchOtRates(int $origamiCompanyId): array { $this->notConfigured(__FUNCTION__); }
    public function fetchEmployees(int $origamiCompanyId): array { $this->notConfigured(__FUNCTION__); }
    public function fetchAttendance(int $origamiCompanyId, string $dateFrom, string $dateTo): array { $this->notConfigured(__FUNCTION__); }
    public function fetchLeaveRequests(int $origamiCompanyId, string $dateFrom, string $dateTo): array { $this->notConfigured(__FUNCTION__); }
    public function fetchOvertimeRecords(int $origamiCompanyId, string $dateFrom, string $dateTo): array { $this->notConfigured(__FUNCTION__); }
}
