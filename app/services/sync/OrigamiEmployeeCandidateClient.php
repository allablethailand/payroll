<?php
declare(strict_types=1);

/**
 * REAL Origami HTTP client for the interactive "Sync Employee from Origami" picker (Employee List
 * page, 2026-08-28). Was a mock (fabricated plausible candidates) while Origami's own endpoints
 * didn't exist yet -- switched to a real `callRealApi()` implementation the same day, once the
 * Origami dev team confirmed their filter-options endpoint was live and handed over real
 * ORIGAMI_API_BASE_URL/ORIGAMI_API_KEY values ("ฝั่ง Origami ทำเส้น Sync เสร็จแล้วครับ ช่วย Config
 * ต่อ"). The mock generator (mockPool()/refPool()/shiftPool()/bankPool() + the NEW_DEPARTMENT/
 * NEW_POSITION/NEW_SHIFT fixed fake-ref constants) was deleted outright rather than kept as a
 * fallback -- per the explicit "if we can't connect, say so, don't show mock data" principle this
 * feature was already built around (see isConfigured()'s own docblock), a live fallback path that
 * could silently reactivate if .env config is ever blanked again would directly contradict that.
 * No test in tests/ exercised the mock pool directly (confirmed by grep before deleting it), so
 * nothing else needed to change.
 *
 * **Two endpoints, both GET, per docs/origami-employee-sync-api-guide.md §2/§3:**
 *   GET {ORIGAMI_API_BASE_URL}/api/hr/employees/filter-options?company_ref_id=...
 *   GET {ORIGAMI_API_BASE_URL}/api/hr/employees/candidates?company_ref_id=...&department_ref_id=...&...
 * Auth: `Authorization: Bearer {ORIGAMI_API_KEY}` on every call (confirmed against Origami's own
 * api/hr/_common.php, per the message that handed over these real credentials).
 *
 * **2026-08-28, as of this switch: filter-options.php is live, candidates.php is NOT yet built**
 * (blocked pending Origami's own confirmation on the `type`/`system_type=3` and
 * omitted-payroll-fields open questions -- see the guide's own §5). That means `fetchCandidates()`
 * will genuinely 404 for now -- callRealApi() surfaces that as a RuntimeException with the real
 * HTTP status in its message (not a silent empty list, not mock data), which
 * EmployeeSyncModel::candidates()/apply() catch and turn into a clear, honest UI error. Once
 * candidates.php exists on Origami's side, this file needs ZERO changes -- it already calls the
 * confirmed contract; the endpoint just starts returning 200 instead of 404.
 *
 * Deliberately its OWN standalone class, NOT an implementation of OrigamiSyncClientInterface --
 * that interface's fetchEmployees() is shaped for the existing bulk auto-apply engine
 * (EmployeeSyncer::sync(), still not wired to any UI anywhere in this app) and takes no filter
 * parameters at all. Interactive, filtered, browse-then-select candidate fetching is a genuinely
 * different shape (filters in, denormalized display fields out -- see fetchCandidates()'s own
 * return type), so it gets its own contract instead of widening the shared interface and forcing
 * every existing implementer (the real OrigamiSyncClient stub + 2 test fakes) to grow a method
 * none of them need.
 *
 * `type` ('support'/'employee', per explicit request) has NO equivalent column anywhere in this
 * app's own `employees` schema (checked: employee_type is domestic/foreigner, employment_type is
 * full_time/part_time/daily/internship, employment_status is probation/permanent/contract/
 * resigned/terminated -- none of the three is this). Treated here as an Origami-side-only
 * classification, used purely to filter/label candidates in the picker -- NOT written to any local
 * column on apply (EmployeeSyncModel::apply() never touches it).
 *
 * `team_ref_id`/`team_name` similarly do NOT map to this app's own `structure_teams` (Team is a
 * purely local "which client/project this outsourcing firm's employee is deployed to" concept with
 * no Origami-side equivalent or origami_ref_id column at all, unlike department/position/shift).
 * Filtering by Team here only narrows which real candidates come back; it is never used to set
 * `employees.team_id` on apply -- that stays a manual assignment via Employee Detail, same as today.
 */
class OrigamiEmployeeCandidateClient {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** True once BOTH the domain and key are set in .env. Public + static (2026-08-28, explicit
     *  request: "ถ้ายังเชื่อมไม่ได้ก็ควรแจ้งว่าเชื่อมไม่ได้ ไม่ใช่ Mock Data" -- if we still can't
     *  connect, say so, don't show Mock Data) so EmployeeSyncModel can check this UP FRONT, before
     *  ever calling fetchCandidates()/fetchFilterOptions(), and refuse the whole feature with a
     *  clear "not connected" result. Also re-checked defensively inside callRealApi() itself below,
     *  in case this class is ever called directly without going through that model-layer gate. */
    public static function isConfigured(): bool {
        return ORIGAMI_API_BASE_URL !== '' && ORIGAMI_API_KEY !== '';
    }

    /** The one place the real HTTP call happens for both endpoints. $endpoint is 'filter-options'
     *  or 'candidates' (docs/origami-employee-sync-api-guide.md §2/§3); $query is the GET query
     *  params for that call (company_ref_id always included, plus whichever filters were passed
     *  for 'candidates'). Returns the parsed JSON body's own array. Throws RuntimeException with a
     *  clear, specific message (never silently returns empty/fabricated data) on: not configured,
     *  a network/curl failure, a non-2xx HTTP status (the message includes the actual status code,
     *  so a 404 on the still-unbuilt candidates endpoint reads as exactly that, not a generic
     *  failure), malformed JSON, or `status !== true` in an otherwise-valid response body. */
    private function callRealApi(string $endpoint, array $query): array {
        if (!self::isConfigured()) {
            throw new RuntimeException('OrigamiEmployeeCandidateClient is not configured -- ORIGAMI_API_BASE_URL/ORIGAMI_API_KEY are not set.');
        }
        $url = ORIGAMI_API_BASE_URL . '/api/hr/employees/' . $endpoint . '?' . http_build_query($query);
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
            throw new RuntimeException("Could not reach Origami ({$endpoint}): {$curlError}");
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("Origami returned HTTP {$httpCode} for {$endpoint} -- this endpoint may not be built/deployed yet on Origami's side.");
        }
        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Origami returned an invalid (non-JSON) response for {$endpoint}.");
        }
        if (($decoded['status'] ?? null) !== true) {
            $msg = is_string($decoded['message'] ?? null) ? $decoded['message'] : 'Origami reported a failure with no message.';
            throw new RuntimeException("Origami rejected the {$endpoint} request: {$msg}");
        }
        return $decoded;
    }

    /**
     * @param array{department_ref_id: ?int, position_ref_id: ?int, team_ref_id: ?int, type: ?string} $filters
     * @return array<array{
     *   ref_id:int, employee_no:string, name_th:string, surname_th:string, name_en:string, surname_en:string,
     *   date_of_birth:string, gender:string,
     *   department_ref_id:?int, department_code:?string, department_name_th:?string, department_name_en:?string,
     *   position_ref_id:?int, position_code:?string, position_name_th:?string, position_name_en:?string,
     *   shift_ref_id:?int, shift_code:?string, shift_name_th:?string, shift_name_en:?string,
     *   shift_start_time:?string, shift_end_time:?string, shift_break_minutes:?int,
     *   team_ref_id:?int, team_name:?string, type:string,
     *   employment_date:string, employment_status:string, personal_email:string, mobile_no:string, is_active:bool,
     *   salary_type:string, base_salary_amount:string, tax_calculation_method:string, payment_type:string,
     *   bank_code:?string, bank_account_no:?string, bank_account_name:?string, bank_branch:?string
     * }>
     */
    public function fetchCandidates(int $origamiCompanyId, array $filters): array {
        $query = ['company_ref_id' => $origamiCompanyId];
        foreach (['department_ref_id', 'position_ref_id', 'team_ref_id', 'type'] as $key) {
            if (!empty($filters[$key])) {
                $query[$key] = $filters[$key];
            }
        }
        $decoded = $this->callRealApi('candidates', $query);
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    /**
     * 2026-08-28, explicit follow-up request: "ตัวที่เป็น Filter ต้อง Filter จาก Origami ครับ แล้วส่งไป
     * ดึงข้อมูลพนักงานอีกที" (the filter dropdowns themselves must be sourced FROM Origami, then that
     * selection is sent to fetch employees) -- the picker's Department/Position/Team/Type filters
     * come from Origami's own filter-options endpoint, never from Payroll's own local
     * structure_departments/structure_positions/structure_teams tables. The values returned here
     * (ref_id/ref_id/ref_id/raw string) are passed straight back into fetchCandidates()'s own
     * filters with NO local-id translation step in between.
     * @return array{
     *   departments: array<array{ref_id:int, name_th:string, name_en:string}>,
     *   positions: array<array{ref_id:int, name_th:string, name_en:string}>,
     *   teams: array<array{ref_id:int, name:string}>,
     *   types: array<array{value:string, label_en:string, label_th:string}>
     * }
     */
    public function fetchFilterOptions(int $origamiCompanyId, int $compId): array {
        $decoded = $this->callRealApi('filter-options', ['company_ref_id' => $origamiCompanyId]);
        return [
            'departments' => is_array($decoded['departments'] ?? null) ? $decoded['departments'] : [],
            'positions' => is_array($decoded['positions'] ?? null) ? $decoded['positions'] : [],
            'teams' => is_array($decoded['teams'] ?? null) ? $decoded['teams'] : [],
            'types' => is_array($decoded['types'] ?? null) ? $decoded['types'] : [],
        ];
    }
}
