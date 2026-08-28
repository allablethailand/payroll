<?php
declare(strict_types=1);

/**
 * 2026-08-28, explicit follow-up: "Sync พนักงานพร้อมสร้าง Master ที่ขาดอัตโนมัติ" (sync an employee
 * AND auto-create any missing master data at the same time) -- candidates now also carry
 * department_code/position_code and a full shift_* block (shift_ref_id/code/name_th/name_en/
 * start_time/end_time/break_minutes), so EmployeeSyncer::applyOne() has enough to actually CREATE
 * a missing structure_departments/structure_positions/shifts row on the fly (see that method's own
 * resolveOrCreateDepartment()/resolveOrCreatePosition()/resolveOrCreateShift()) instead of just
 * resolving an already-existing one. This is a deliberate divergence from the bulk sync() path,
 * which still refuses to guess (see this class's own class-level note further down + EmployeeSyncer's
 * own docblock).
 *
 * MOCK data source for the interactive "Sync Employee from Origami" picker (Employee List page,
 * 2026-08-28). There is no real Origami endpoint for this yet -- see
 * docs/origami-employee-sync-api-guide.md, the spec handed to the Origami dev team to build the
 * real thing. This class fabricates plausible candidates so the whole browse/filter/select/apply/
 * log workflow can be built and demoed NOW; once a real endpoint exists, only this class's
 * internals need to change to a real HTTP call -- every other file in this feature
 * (EmployeeSyncModel, EmployeeSyncController, the picker UI) is written against the exact response
 * shape this class already returns, so nothing else needs to change.
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
 * 2026-08-28, explicit follow-up request: "ข้อมูลที่ Sync จะต้องมีครบตามที่ Sync มาในการทำรอบเงินเดือน"
 * (synced data must be complete enough to actually run a payroll round) -- candidates now also
 * carry salary_type/base_salary_amount/tax_calculation_method/payment_type/bank_code/
 * bank_account_no/bank_account_name/bank_branch, confirmed via AskUserQuestion to include bank
 * info too (not just base salary/tax method). EmployeeSyncer::upsertItem() applies these ONLY on
 * first INSERT, same as every other payroll-owned field -- also confirmed via AskUserQuestion, so
 * a re-sync never silently overwrites whatever a payroll admin has since corrected locally.
 *
 * `type` ('support'/'employee', per explicit request) has NO equivalent column anywhere in this
 * app's own `employees` schema (checked: employee_type is domestic/foreigner, employment_type is
 * full_time/part_time/daily/internship, employment_status is probation/permanent/contract/
 * resigned/terminated -- none of the three is this). Treated here as an Origami-side-only
 * classification, used purely to filter/label candidates in the picker -- NOT written to any local
 * column on apply (EmployeeSyncModel::apply() never touches it). If Origami's real API turns out to
 * mean something this app should actually store, that's a follow-up schema decision once the real
 * contract is confirmed, not something to guess at here.
 *
 * `team_ref_id`/`team_name` similarly do NOT map to this app's own `structure_teams` (Team is a
 * purely local "which client/project this outsourcing firm's employee is deployed to" concept with
 * no Origami-side equivalent or origami_ref_id column at all, unlike department/position/shift).
 * Filtering by Team here only narrows which mock candidates come back; it is never used to set
 * `employees.team_id` on apply -- that stays a manual assignment via Employee Detail, same as today.
 *
 * 2026-08-28, "how do we switch this to real" -- SWITCHING TO A REAL ORIGAMI ENDPOINT NEEDS EXACTLY
 * 3 STEPS:
 *  1. Set `ORIGAMI_API_BASE_URL` and `ORIGAMI_API_KEY` in `.env` (both already reserved there and
 *     exposed as PHP constants in `config.php`, currently blank -- nothing reads them as "real"
 *     yet, see step 3). This is the "domain + key" -- there is nowhere else these get configured;
 *     no other file in this feature has its own separate connection setting.
 *  2. Confirm the actual endpoint paths/auth header/response shape with Origami's dev team (hand
 *     them `docs/origami-employee-sync-api-guide.md` -- it is still a DRAFT with 3 open questions
 *     in its own §5 that need answers first: what `type` means, what `team_ref_id` means, and
 *     pagination/result-size limits).
 *  3. Implement `callRealApi()` below (currently a stub that throws, same "configured but not
 *     implemented" pattern as `OrigamiSyncClient::notConfigured()`) to actually make the HTTP
 *     call, and change `fetchCandidates()`/`fetchFilterOptions()` to call it instead of
 *     `mockPool()`/deriving from it, once step 2 is confirmed. Both methods already branch on
 *     `isConfigured()` so that swap is a small, localized change -- EmployeeSyncModel, the
 *     controller, and the picker UI never need to change at all, since they're written against
 *     this class's response SHAPE, not against "mock vs real".
 */
class OrigamiEmployeeCandidateClient {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** True once BOTH the domain and key are set in .env -- see this class's own "how do we switch
     *  this to real" docblock note above for the full 3-step swap. Public + static (2026-08-28,
     *  explicit request: "ถ้ายังเชื่อมไม่ได้ก็ควรแจ้งว่าเชื่อมไม่ได้ ไม่ใช่ Mock Data" -- if we still
     *  can't connect, say so, don't show Mock Data) so EmployeeSyncModel can check this UP FRONT,
     *  before ever calling fetchCandidates()/fetchFilterOptions(), and refuse the whole feature
     *  with a clear "not connected" result instead of quietly falling back to mock candidates.
     *  Confirmed via AskUserQuestion: block the picker entirely (no mock preview at all) until a
     *  real connection exists, not a softer "admin-only preview" middle ground. */
    public static function isConfigured(): bool {
        return ORIGAMI_API_BASE_URL !== '' && ORIGAMI_API_KEY !== '';
    }

    /** The ONE place a real HTTP call gets implemented once docs/origami-employee-sync-api-guide.md
     *  is confirmed with Origami's dev team -- see this class's own docblock, step 3. $endpoint is
     *  'filter-options' or 'candidates' (the guide's own §2/§3); $query is the request params for
     *  that call (company_ref_id always included, plus whichever filters were passed). Expected to
     *  return the parsed JSON body's own array, same shape fetchFilterOptions()/fetchCandidates()
     *  already document and this class's callers already expect -- no other file needs to change
     *  once this method is filled in. */
    private function callRealApi(string $endpoint, array $query): array {
        throw new RuntimeException(
            "OrigamiEmployeeCandidateClient::callRealApi('{$endpoint}') is not implemented yet. " .
            "ORIGAMI_API_BASE_URL/ORIGAMI_API_KEY are set, but the actual HTTP call against " .
            "docs/origami-employee-sync-api-guide.md's §2/§3 endpoints still needs to be written here."
        );
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
        if (self::isConfigured()) {
            return $this->callRealApi('candidates', array_merge(['company_ref_id' => $origamiCompanyId], $filters));
        }
        $compId = (int)($filters['comp_id'] ?? 0);
        $pool = $this->mockPool($compId);

        $deptFilter = (isset($filters['department_ref_id']) && $filters['department_ref_id'] !== '' && $filters['department_ref_id'] !== null)
            ? (int)$filters['department_ref_id'] : null;
        $posFilter = (isset($filters['position_ref_id']) && $filters['position_ref_id'] !== '' && $filters['position_ref_id'] !== null)
            ? (int)$filters['position_ref_id'] : null;
        $teamFilter = (isset($filters['team_ref_id']) && $filters['team_ref_id'] !== '' && $filters['team_ref_id'] !== null)
            ? (int)$filters['team_ref_id'] : null;
        $typeFilter = !empty($filters['type']) ? (string)$filters['type'] : null;

        return array_values(array_filter($pool, function (array $row) use ($deptFilter, $posFilter, $teamFilter, $typeFilter): bool {
            if ($deptFilter !== null && $row['department_ref_id'] !== $deptFilter) return false;
            if ($posFilter !== null && $row['position_ref_id'] !== $posFilter) return false;
            if ($teamFilter !== null && $row['team_ref_id'] !== $teamFilter) return false;
            if ($typeFilter !== null && $row['type'] !== $typeFilter) return false;
            return true;
        }));
    }

    /**
     * 2026-08-28, explicit follow-up request: "ตัวที่เป็น Filter ต้อง Filter จาก Origami ครับ แล้วส่งไป
     * ดึงข้อมูลพนักงานอีกที" (the filter dropdowns themselves must be sourced FROM Origami, then that
     * selection is sent to fetch employees) -- the picker's Department/Position/Team/Type filters
     * no longer read from Payroll's own local structure_departments/structure_positions/
     * structure_teams tables (a Payroll-side proxy for "what Origami might have"). They now come
     * from THIS method instead -- the distinct department/position/team/type values that actually
     * appear across Origami's own candidate pool -- so an admin can only ever pick a filter value
     * Origami can actually answer. The values returned here (ref_id/ref_id/ref_id/raw string) are
     * passed straight back into fetchCandidates()'s own filters with NO local-id translation step
     * in between (see EmployeeSyncModel::candidates()'s own simplification note) -- exactly the
     * "filter from Origami, then use that to fetch" round-trip requested.
     * @return array{
     *   departments: array<array{ref_id:int, name_th:string, name_en:string}>,
     *   positions: array<array{ref_id:int, name_th:string, name_en:string}>,
     *   teams: array<array{ref_id:int, name:string}>,
     *   types: array<array{value:string, label_en:string, label_th:string}>
     * }
     */
    public function fetchFilterOptions(int $origamiCompanyId, int $compId): array {
        if (self::isConfigured()) {
            return $this->callRealApi('filter-options', ['company_ref_id' => $origamiCompanyId]);
        }
        $pool = $this->mockPool($compId);

        $departments = [];
        $positions = [];
        $teams = [];
        foreach ($pool as $row) {
            if ($row['department_ref_id'] !== null && !isset($departments[$row['department_ref_id']])) {
                $departments[$row['department_ref_id']] = ['ref_id' => $row['department_ref_id'], 'name_th' => $row['department_name_th'], 'name_en' => $row['department_name_en']];
            }
            if ($row['position_ref_id'] !== null && !isset($positions[$row['position_ref_id']])) {
                $positions[$row['position_ref_id']] = ['ref_id' => $row['position_ref_id'], 'name_th' => $row['position_name_th'], 'name_en' => $row['position_name_en']];
            }
            if (!isset($teams[$row['team_ref_id']])) {
                $teams[$row['team_ref_id']] = ['ref_id' => $row['team_ref_id'], 'name' => $row['team_name']];
            }
        }

        return [
            'departments' => array_values($departments),
            'positions' => array_values($positions),
            'teams' => array_values($teams),
            // Fixed 2-value set today (see this class's own docblock on `type`'s uncertain meaning),
            // but still served through this same "ask Origami what the options are" endpoint rather
            // than hardcoded into the picker's own markup -- if Origami's real API ever returns a
            // 3rd value, nothing on the Payroll side needs to change to show it.
            'types' => [
                ['value' => 'support', 'label_en' => 'Support', 'label_th' => 'Support'],
                ['value' => 'employee', 'label_en' => 'Employee', 'label_th' => 'Employee'],
            ],
        ];
    }

    /** Fabricates a small, fixed pool of mock candidates. department_ref_id/position_ref_id/
     *  shift_ref_id mostly cycle through this company's OWN real structure_departments/
     *  structure_positions/shifts rows (their real `origami_ref_id`, never a local id standing in
     *  for one) -- EXCEPT candidate index 0, which deliberately references a department/position/
     *  shift that does NOT exist locally at all (fixed fake refs 800001/800002/800003, see
     *  NEW_DEPARTMENT/NEW_POSITION/NEW_SHIFT below), so that EmployeeSyncer::applyOne()'s
     *  auto-create-missing-master-data path (added 2026-08-28, explicit request: "Sync พนักงาน
     *  พร้อมสร้าง Master ที่ขาดอัตโนมัติ") is actually exercised by this mock rather than only being
     *  reachable once a real Origami connection exists. team_ref_id has no local table to borrow
     *  from at all (Team has no Origami-side equivalent, see this class's own docblock, and is
     *  deliberately NOT auto-created locally even though department/position/shift now are) so it's
     *  just a small fixed set of fake ids/names. */
    private const NEW_DEPARTMENT = ['ref_id' => 800001, 'code' => 'ORG-NEWDEPT', 'name_th' => 'แผนกใหม่จาก Origami', 'name_en' => 'Origami New Department'];
    private const NEW_POSITION = ['ref_id' => 800002, 'code' => 'ORG-NEWPOS', 'name_th' => 'ตำแหน่งใหม่จาก Origami', 'name_en' => 'Origami New Position'];
    private const NEW_SHIFT = ['ref_id' => 800003, 'code' => 'ORG-NEWSHIFT', 'name_th' => 'กะใหม่จาก Origami', 'name_en' => 'Origami New Shift', 'start_time' => '08:00:00', 'end_time' => '17:00:00', 'break_minutes' => 60];

    private function mockPool(int $compId): array {
        $deptRefs = $this->refPool('structure_departments', 'department_code', 'department_name_th', 'department_name_en', $compId);
        $posRefs = $this->refPool('structure_positions', 'position_code', 'position_name_th', 'position_name_en', $compId);
        $shiftRefs = $this->shiftPool($compId);
        $teamRefs = [
            ['ref_id' => 9001, 'name' => 'Client Site A'],
            ['ref_id' => 9002, 'name' => 'Client Site B'],
            ['ref_id' => 9003, 'name' => 'Internal Support'],
        ];
        $names = [
            ['สมชาย', 'ใจดี', 'Somchai', 'Jaidee', 'male'],
            ['สมหญิง', 'รักงาน', 'Somying', 'Rakngan', 'female'],
            ['วิชัย', 'มั่นคง', 'Wichai', 'Mankong', 'male'],
            ['ปิยะดา', 'สุขสันต์', 'Piyada', 'Suksan', 'female'],
            ['ธนากร', 'เพียรทำ', 'Thanakorn', 'Peanthum', 'male'],
            ['นภัสสร', 'แจ่มใส', 'Napassorn', 'Jamsai', 'female'],
            ['กิตติศักดิ์', 'บุญมา', 'Kittisak', 'Boonma', 'male'],
            ['อรุณี', 'ทองดี', 'Arunee', 'Thongdee', 'female'],
        ];
        $types = ['support', 'employee'];
        $bankPool = $this->bankPool();

        $pool = [];
        foreach ($names as $i => $n) {
            // Index 0 deliberately points at a department/position/shift NOT present locally (see
            // this method's own docblock) so the auto-create-missing-master-data path always has
            // something real to exercise, regardless of what's already been synced in this DB.
            $isNewMasterData = $i === 0;
            $dept = $isNewMasterData ? self::NEW_DEPARTMENT : ($deptRefs[$i % max(1, count($deptRefs))] ?? null);
            $pos = $isNewMasterData ? self::NEW_POSITION : ($posRefs[$i % max(1, count($posRefs))] ?? null);
            $shift = $isNewMasterData ? self::NEW_SHIFT : ($shiftRefs[$i % max(1, count($shiftRefs))] ?? null);
            $team = $teamRefs[$i % count($teamRefs)];
            $refId = 500000 + $i;
            // 2026-08-28, explicit request: "ข้อมูลที่ Sync จะต้องมีครบตามที่ Sync มาในการทำรอบเงินเดือน"
            // -- a candidate must carry enough to actually be usable in a payroll run right after
            // sync, not just HR-record fields (see EmployeeSyncer::upsertItem()'s own docblock for
            // how these get applied ONCE on insert only, never overwritten on re-sync). One candidate
            // out of 8 (payment_type='cash') deliberately carries no bank fields at all, to exercise
            // upsertItem()'s "no bank info given" path.
            $isCash = $i === 7;
            $bank = $isCash ? null : $bankPool[$i % max(1, count($bankPool))] ?? null;
            $pool[] = [
                'ref_id' => $refId,
                'employee_no' => sprintf('ORG-%04d', $refId),
                'name_th' => $n[0], 'surname_th' => $n[1], 'name_en' => $n[2], 'surname_en' => $n[3],
                'date_of_birth' => sprintf('19%02d-0%d-1%d', 85 + ($i % 10), 1 + ($i % 9), $i % 8),
                'gender' => $n[4],
                'department_ref_id' => $dept['ref_id'] ?? null, 'department_code' => $dept['code'] ?? null,
                'department_name_th' => $dept['name_th'] ?? null, 'department_name_en' => $dept['name_en'] ?? null,
                'position_ref_id' => $pos['ref_id'] ?? null, 'position_code' => $pos['code'] ?? null,
                'position_name_th' => $pos['name_th'] ?? null, 'position_name_en' => $pos['name_en'] ?? null,
                'shift_ref_id' => $shift['ref_id'] ?? null, 'shift_code' => $shift['code'] ?? null,
                'shift_name_th' => $shift['name_th'] ?? null, 'shift_name_en' => $shift['name_en'] ?? null,
                'shift_start_time' => $shift['start_time'] ?? null, 'shift_end_time' => $shift['end_time'] ?? null,
                'shift_break_minutes' => $shift['break_minutes'] ?? null,
                'team_ref_id' => $team['ref_id'], 'team_name' => $team['name'],
                'type' => $types[$i % 2],
                'employment_date' => sprintf('2026-0%d-0%d', 1 + ($i % 8), 1 + ($i % 25)),
                'employment_status' => 'probation',
                'personal_email' => sprintf('%s.%s@example.com', strtolower($n[2]), strtolower($n[3])),
                'mobile_no' => sprintf('08%08d', 10000000 + $refId),
                'is_active' => true,
                'salary_type' => 'monthly',
                'base_salary_amount' => (string)(18000 + ($i * 2500)),
                'tax_calculation_method' => 'average',
                'payment_type' => $isCash ? 'cash' : 'bank',
                'bank_code' => $bank['bank_code'] ?? null,
                'bank_account_no' => $isCash ? null : sprintf('%03d-%d-%05d-%d', 100 + $i, $i, 10000 + $refId, $i % 10),
                'bank_account_name' => $isCash ? null : trim($n[2] . ' ' . $n[3]),
                'bank_branch' => $isCash ? null : 'Head Office',
            ];
        }
        return $pool;
    }

    /** Active banks from the global master_banks table (not per-company -- see that table's own
     *  schema) to cycle a mock bank_code through, same "borrow real reference data instead of
     *  fabricating a fake id space" approach as department/position above. @return array<array{bank_code:string}> */
    private function bankPool(): array {
        $stmt = $this->db->prepare("SELECT bank_code FROM master_banks WHERE is_active = 1 ORDER BY id ASC LIMIT 5");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Only rows with a real origami_ref_id already set are usable as a mock candidate's
     *  department/position link -- see fetchCandidates()'s own docblock for why a local id must
     *  never stand in for one. Returns fewer than 6 (possibly zero) entries whenever this
     *  company's departments/positions haven't been synced from Origami yet; mockPool() falls back
     *  to `null` for any candidate slot that runs out of real refs to cycle through.
     *  @return array<array{ref_id:int, code:string, name_th:string, name_en:string}> */
    private function refPool(string $table, string $codeCol, string $colTh, string $colEn, int $compId): array {
        $stmt = $this->db->prepare("SELECT origami_ref_id, `{$codeCol}` AS code, `{$colTh}` AS name_th, `{$colEn}` AS name_en
            FROM `{$table}` WHERE comp_id = :comp_id AND deleted_at IS NULL AND origami_ref_id IS NOT NULL ORDER BY id ASC LIMIT 6");
        $stmt->execute([':comp_id' => $compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['ref_id' => (int)$row['origami_ref_id'], 'code' => $row['code'], 'name_th' => $row['name_th'], 'name_en' => $row['name_en']];
        }
        return $out;
    }

    /** Same "only already-synced rows" rule as refPool() above, for shifts.
     *  @return array<array{ref_id:int, code:string, name_th:string, name_en:string, start_time:string, end_time:string, break_minutes:int}> */
    private function shiftPool(int $compId): array {
        $stmt = $this->db->prepare("SELECT origami_ref_id, shift_code AS code, shift_name_th AS name_th, shift_name_en AS name_en,
                start_time, end_time, break_minutes
            FROM shifts WHERE comp_id = :comp_id AND deleted_at IS NULL AND origami_ref_id IS NOT NULL ORDER BY id ASC LIMIT 6");
        $stmt->execute([':comp_id' => $compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'ref_id' => (int)$row['origami_ref_id'], 'code' => $row['code'], 'name_th' => $row['name_th'], 'name_en' => $row['name_en'],
                'start_time' => $row['start_time'], 'end_time' => $row['end_time'], 'break_minutes' => (int)$row['break_minutes'],
            ];
        }
        return $out;
    }
}
