<?php
declare(strict_types=1);

/**
 * Contract for fetching data from the Origami HR module. There is no real API contract to
 * implement against yet (no URL, auth, or response spec exists anywhere -- confirmed by survey
 * before this feature was built), so the shapes below are this codebase's own design, not a
 * documented Origami spec. Revisit field names once a real spec exists.
 *
 * Every fetch method takes $origamiCompanyId (== companies.ref_id, "the Origami-side company id
 * this payroll company is linked to") and returns a plain array of associative arrays. Each item
 * MUST include a `ref_id` key (the Origami-side id for that record) and an `is_active` bool key
 * (whether the record is still active on the Origami side -- used to soft-delete/deactivate on
 * this side when false). Records previously synced (non-null origami_ref_id) that are simply
 * ABSENT from a fetch response are also treated as deactivated -- see MasterDataSyncerInterface
 * implementations.
 */
interface OrigamiSyncClientInterface {
    /** @return array<array{ref_id:int, code:string, name_th:string, name_en:string, is_active:bool}> */
    public function fetchDepartments(int $origamiCompanyId): array;

    /** @return array<array{ref_id:int, code:string, name_th:string, name_en:string, is_active:bool}> */
    public function fetchPositions(int $origamiCompanyId): array;

    /** @return array<array{ref_id:int, code:string, name_th:string, name_en:string, start_time:string, end_time:string, break_minutes:int, work_location_code:?string, is_active:bool}> */
    public function fetchShifts(int $origamiCompanyId): array;

    /** @return array<array{ref_id:int, code:?string, name_th:string, name_en:string, is_default:bool, is_active:bool}>
     *  2026-09-02, real endpoint confirmed live (`master/branches.php`). */
    public function fetchBranches(int $origamiCompanyId): array;

    /** @return array<array{ref_id:int, name_th:string, name_en:string, is_active:bool}>
     *  2026-09-02, real endpoint confirmed live (`master/teams.php`) -- Team was previously excluded
     *  from this contract entirely (see OrigamiEmployeeCandidateClient's own docblock) because no
     *  endpoint existed; that's no longer true. */
    public function fetchTeams(int $origamiCompanyId): array;

    /** @return array<array{ref_id:int, name_th:string, name_en:string, holiday_date:string, is_recurring:bool, is_active:bool}> */
    public function fetchHolidays(int $origamiCompanyId): array;

    /** @return array<array{ref_id:int, category_code:string, code:string, name_th:string, name_en:string, quota_type:string, quota_amount:float, unit_type:string, is_paid:bool, is_active:bool}> */
    public function fetchLeaveTypes(int $origamiCompanyId): array;

    /** @return array<array{ref_id:int, name_th:string, name_en:string, scope_code:string, multiplier_rate:float, calculation_base:string, is_active:bool}> */
    public function fetchOtRates(int $origamiCompanyId): array;

    /** @return array<array{ref_id:int, employee_no:string, name_th:string, surname_th:string, name_en:string, surname_en:string, date_of_birth:string, gender:string, department_ref_id:?int, position_ref_id:?int, shift_ref_id:?int, employment_date:string, employment_status:string, personal_email:string, mobile_no:string, is_active:bool}> */
    public function fetchEmployees(int $origamiCompanyId): array;

    /** @return array<array{ref_id:int, employee_ref_id:int, work_date:string, shift_ref_id:?int, clock_in:?string, clock_out:?string, status:string}> */
    public function fetchAttendance(int $origamiCompanyId, string $dateFrom, string $dateTo): array;

    /** @return array<array{ref_id:int, employee_ref_id:int, leave_type_ref_id:int, start_date:string, end_date:string, total_days:float, reason:?string, status:string}> */
    public function fetchLeaveRequests(int $origamiCompanyId, string $dateFrom, string $dateTo): array;

    /** @return array<array{ref_id:int, employee_ref_id:int, ot_date:string, ot_rate_ref_id:int, hours:float, amount:?float, status:string}> */
    public function fetchOvertimeRecords(int $origamiCompanyId, string $dateFrom, string $dateTo): array;

    /**
     * 2026-09-02, real endpoint confirmed live (`GET /api/hr/company`). Single-record fetch, NOT a
     * list -- deliberately does not fit the array<...> shape every other method here returns.
     * Returns null (not an exception) when Origami has no company for this ref_id (404).
     * @return array{ref_id:int, code:?string, name_th:?string, name_en:?string, tax_id:?string,
     *   branch_name:?string, address_th:?string, address_en:?string, telephone:?string, fax:?string,
     *   logo_url:?string, is_active:?bool, updated_at:?string}|null
     */
    public function fetchCompany(int $origamiCompanyId): ?array;
}
