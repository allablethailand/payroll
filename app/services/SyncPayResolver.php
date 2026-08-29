<?php
declare(strict_types=1);

/**
 * Turns one employee's `payroll_sync_items` row (Origami Payroll's synced attendance data for a
 * pulled process -- OT hours, trip allowance, late/absent/unpaid leave, plus the generic
 * `item_values[]` array) into ready-to-use earning/deduction lines for
 * `PayrollRunModel::recalculate()`, additive to the standing PED assignments/attendance
 * bonus/manual lines already assembled there.
 *
 * 2026-08-20, built per explicit request -- `PayrollSyncModel`'s own docblock previously admitted
 * this wiring was "still separate, later work"; this class is that work. Only invoked for runs
 * pulled from a sync process (run.sync_process_id set) -- cycle-based/off-cycle runs never call
 * this at all.
 *
 * DATA SHAPE (confirmed against tests/payroll_sync_test.php, no PAYROLL_SYNC_API.md exists in this
 * repo to verify against directly): `payroll_sync_items` carries BOTH structured attendance columns
 * (ot_req_working_day_hrs/ot_req_weekend_hrs/ot_req_holiday_hrs/late_mins/absent_days/absent_mins/
 * leave_without_pay_days/trip_allowance) AND a generic `item_values` JSON array ({item_code,
 * item_name, item_type=INCOME/DEDUCTION, unit_type=hours/days/minutes/null, value, remark}).
 *
 * MULTI-UNIT DEDUPLICATION (2026-08-20, second real-data report: "Absent ส่งมา 4 unit time day hour
 * minute...รายการอื่นๆ ยกเว้น OT กับค่าเที่ยวก็ส่งเข้ามาเหมือนกัน...อาจไม่ได้ส่งมาครบทั้ง Unit" -- Origami
 * sends the SAME real-world event redundantly across up to 4 representations at once (e.g. Absent:
 * the structured `absent_days` column, the structured `absent_mins` column, AND one-or-more
 * `item_values` rows with unit_type in days/hours/minutes for the same item_code) but is NOT
 * guaranteed to send every representation on every pull. First discovered with OT (structured
 * hours columns + a duplicate `item_values` row in a different unit for the same total -- e.g.
 * 2 hours == 0.25 days), which is why OT was already structured-column-only; this generalizes that
 * fix to every other known item (trip allowance/late/absent/unpaid leave) AND to arbitrary
 * unmatched item_values items, which can apparently be sent with more than one unit_type row for
 * the same item_code too. `resolveCandidates()`/`pickBestCandidate()` below collect every
 * representation actually present (structured column(s) + matching item_values rows) into one
 * pool and pick exactly ONE -- the finest-grained time unit available (minutes > hours > days),
 * i.e. "แปลงให้เป็นหน่วยย่อยแล้วนำมาหัก" -- so only one line is ever produced per real-world event no
 * matter how many redundant representations were sent, and it still works correctly when only a
 * single (any) representation was sent. OT keeps its own dedicated, scope-split handling (a
 * generic item_values 'OT' row has no scope information to merge with the 3 scope-specific
 * columns), but its item_values rows are still excluded from the generic loop, same as before.
 *
 * KNOWN SIMPLIFICATIONS (flagged here rather than silently guessed, same convention as
 * ThPitCalculator's own docblock):
 *  - `value` on an `item_values` entry is a RAW UNIT COUNT (hours/days/minutes) whenever
 *    `unit_type` is set, not already money -- confirmed with the user (2026-08-20) before building
 *    this. Only a null `unit_type` means `value` is already a final money amount.
 *  - No "standard working days/month" or "standard hours/day" divisor exists anywhere else in this
 *    codebase (confirmed via grep before writing this). STANDARD_WORKING_DAYS_PER_MONTH=30 and
 *    STANDARD_HOURS_PER_DAY=8 are a new, explicitly-documented simplification here -- the 8h/day
 *    figure is corroborated by Origami's own dual-unit OT encoding above, but neither constant is
 *    company-configurable in this pass.
 *  - Late/Absent/Unpaid Leave deductions (2026-08-20, explicit request, generalized from Late-only
 *    the same day) are company-configurable via `attendance_deduction_rules`/
 *    `attendance_deduction_rule_brackets` (Payroll Configuration > "Attendance Deduction" tab,
 *    `AttendanceDeductionRuleModel::ruleSave()`), read per-event by
 *    `computeAttendanceDeductionAmount()` below. No row configured for a given (company, event) =
 *    `percent_of_rate` @ multiplier 1.00, i.e. byte-for-byte the old fixed formula for that event --
 *    existing companies see zero behavior change until they explicitly configure something. All 3
 *    events resolve their quantity to MINUTES uniformly (2026-08-21 correctness fix -- see
 *    RULE_DRIVEN_ITEM_DEFS's own docblock); a rule's `rate_unit` (minute/hour/day) only affects how
 *    `flat_amount`/`tiered_bracket` interpret the admin's own configured numbers, never how the raw
 *    attendance data itself is measured.
 *  - A generic (non-OT, non-rule-driven) `item_values` item with a non-null `unit_type` is converted
 *    via the same hourly/daily rate as trip allowance, but with multiplier=1 -- there is no
 *    `ot_rates`-style rate table for an arbitrary custom item, so none is invented.
 *  - Missing `ot_rates` for a scope that actually has hours (`errors[]`, e.g.
 *    'missing_ot_rate_weekday') is surfaced, never silently skipped with a guessed multiplier --
 *    same "visible, not silent" convention PayrollRunModel already uses for
 *    profile_incomplete/missing_base_salary.
 */
class SyncPayResolver {
    private PDO $db;

    private const STANDARD_WORKING_DAYS_PER_MONTH = 30.0;
    private const STANDARD_HOURS_PER_DAY = 8.0;

    /** payroll_sync_items column => master_ot_scope_types.code */
    private const OT_SCOPE_COLUMNS = [
        'ot_req_working_day_hrs' => 'weekday',
        'ot_req_weekend_hrs' => 'weekend',
        'ot_req_holiday_hrs' => 'holiday',
    ];

    /** Finest-grained unit wins when the same event was sent redundantly in more than one unit. */
    private const UNIT_PRIORITY = ['minutes' => 1, 'mins' => 1, 'hours' => 2, 'days' => 3];

    /**
     * 2026-08-20, real bug report: item_code matching against this company's OWN catalog item_code
     * (e.g. 'ABSENT_DEDUCT') is not enough to reliably exclude an item_values row from
     * double-counting against its structured column -- Origami's real payload sends a short generic
     * code (e.g. 'Absent') that does not equal a company's customized catalog code at all, so the
     * exclusion silently missed it and the same absence got deducted twice (once from the
     * structured absent_days/absent_mins columns, once again as an unmatched "custom" item_values
     * line). These aliases are additional, fixed, known spellings checked (after stripping
     * non-alphanumeric characters and uppercasing) alongside the catalog item_code -- exact match
     * only, never substring, to avoid false-positively swallowing an unrelated custom item that
     * merely shares a word.
     */
    private const EVENT_ALIASES = [
        'ot_hours' => ['OT', 'OVERTIME'],
        'trip_allowance' => ['TRIP', 'TRIPALLOWANCE'],
        'late' => ['LATE'],
        'absent' => ['ABSENT', 'ABSENCE'],
        'unpaid_leave' => ['UNPAIDLEAVE', 'LEAVEWITHOUTPAY', 'LEAVENOPAY'],
        // 2026-08-29, explicit request ("ลารออนุมัติ ถึงจะเอามาคำนวณเป็นเงินหัก") -- Origami's own
        // real item_code for this is 'LEAVE_PENDING' (normalizes to 'LEAVEPENDING', already covered
        // by the primary alias below), PENDINGLEAVE kept as a defensive secondary spelling only.
        'leave_pending' => ['LEAVEPENDING', 'PENDINGLEAVE'],
    ];

    /**
     * The only remaining item with a uniform, non-configurable salary-derived formula -- Late/Absent/
     * Unpaid Leave moved out into RULE_DRIVEN_ITEM_DEFS below (2026-08-20, generalized from Late-only
     * to also cover Absent/Unpaid Leave the same day, before any real company had configured
     * anything: "รองรับการ Set เงื่อนของ สาย ขาดงาน ลาไม่รับเงินด้วย"). source_event_code => definition.
     */
    private const KNOWN_ITEM_DEFS = [
        'trip_allowance' => [
            'default_code' => 'TRIP_ALLOW', 'name_th' => 'ค่าเที่ยว', 'name_en' => 'Trip Allowance', 'kind' => 'earning',
            'structured' => [['column' => 'trip_allowance', 'unit' => null]],
        ],
    ];

    /**
     * Events driven by a company-configurable `attendance_deduction_rules` row (Payroll
     * Configuration > "Attendance Deduction" tab, `AttendanceDeductionRuleModel`), read by
     * `computeAttendanceDeductionAmount()` below -- same candidate-pool multi-unit dedup as
     * KNOWN_ITEM_DEFS, but the chosen candidate feeds a configurable rule instead of a fixed
     * salary-derived formula.
     *
     * 2026-08-21, explicit correctness report: "ขาดงาน วันทำงาน ให้ตีเป็นนาทีเลยน่าจะหักถูกกว่านะครับ...
     * บางที่ทำ 7 ชั่วโมงครึ่ง เสาร์ครึ่งวัน บางที่ทำ 9 ชั่วโมง" -- all 3 events now resolve their candidate
     * to MINUTES uniformly (no more per-event `quantity_unit` split), because a day-based quantity
     * combined with a period-average `dailyRate` (baseSalary/working_days) misrepresents any single
     * day's real length whenever shift lengths vary within a period (e.g. a half-day Saturday costs
     * the same "day" as a full weekday under day-based math, but correctly costs half as much under
     * minute-based math, since `absent_mins` for that day is naturally smaller). This is provably a
     * no-op for a uniform-shift company (see `computeAttendanceDeductionAmount()`'s own docblock for
     * the equivalence proof) -- pure accuracy gain for mixed-shift companies, no regression for the
     * common case. Each rule's own `rate_unit` (minute/hour/day, added the same day per "การตั้งค่า
     * เงื่อนไขการหักสาย ให้มี นาทีละ กี่บาท ชั่วโมงละกี่บาท") still lets the ADMIN express `flat_amount`/
     * `tiered_bracket` config in whichever unit is most natural to think in -- only `percent_of_rate`
     * is always minute-based internally, unaffected by `rate_unit`.
     */
    private const RULE_DRIVEN_ITEM_DEFS = [
        'late' => [
            'default_code' => 'LATE_DEDUCT', 'name_th' => 'หักมาสาย', 'name_en' => 'Late Deduction',
            'structured' => [['column' => 'late_mins', 'unit' => 'minutes']],
        ],
        'absent' => [
            'default_code' => 'ABSENT_DEDUCT', 'name_th' => 'หักขาดงาน', 'name_en' => 'Absence Deduction',
            'structured' => [['column' => 'absent_mins', 'unit' => 'minutes'], ['column' => 'absent_days', 'unit' => 'days']],
        ],
        'unpaid_leave' => [
            'default_code' => 'LEAVE_NO_PAY_DEDUCT', 'name_th' => 'หักลาไม่รับเงินเดือน', 'name_en' => 'Unpaid Leave Deduction',
            'structured' => [['column' => 'leave_without_pay_days', 'unit' => 'days']],
        ],
        // 2026-08-29, explicit bug report: "Leave Approved ต้องไม่นำมาบวกเป็นเงินได้ ลาไม่รับเงิน และ
        // ลารออนุมัติ ถึงจะเอามาคำนวณเป็นเงินหัก" -- leave that's still awaiting approval isn't
        // confirmed as paid leave yet, so (like unpaid leave) it's provisionally deducted until it's
        // actually approved -- at which point Origami stops reporting it as pending and it simply
        // stops appearing here. No structured payroll_sync_items column exists for this (unlike
        // late/absent/unpaid_leave) -- it only ever arrives via the generic item_values[] array
        // (item_code 'LEAVE_PENDING'), matched purely through EVENT_ALIASES/the company's own
        // catalog source_event_code below.
        'leave_pending' => [
            'default_code' => 'LEAVE_PENDING_DEDUCT', 'name_th' => 'หักลารออนุมัติ', 'name_en' => 'Pending Leave Deduction',
            'structured' => [],
        ],
    ];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /**
     * @param array $syncItemRow Full `payroll_sync_items` row for this employee/process, with
     *                           `item_values` already json_decode()'d to an array (or empty array).
     * @param float $baseSalary  Employee's base_salary_amount, used to derive daily/hourly rates.
     * @param array $attendanceOverrides 2026-08-21, explicit request ("ต้องการแก้ตัวเลขดิบที่ Sync
     *              มา ไม่ใช่แค่ยอดเงิน") -- a per-run, per-employee correction of the RAW numbers
     *              Origami sent, shaped exactly like a partial `payroll_sync_items` row
     *              (`payroll_run_sync_item_overrides`'s own row, fetched verbatim by
     *              PayrollRunModel::recalculate()/syncDeductionLinesForEmployee() -- column names
     *              are deliberately identical so no remapping is needed here). A present, non-null
     *              key for one of the 7 known columns (the 3 OT hour columns, trip_allowance,
     *              late_mins, absent_days, leave_without_pay_days) makes that event use the
     *              override value DIRECTLY, skipping pickBestCandidate()/pullKnownEventCandidates()
     *              entirely for it -- this is deliberate, not just an optimization: if Origami sent
     *              an event redundantly across multiple unit representations (see the class
     *              docblock's MULTI-UNIT DEDUPLICATION section) and only one representation were
     *              corrected while the others were left untouched, the candidate pool could still
     *              silently pick a stale, uncorrected one. Bypassing the pool entirely for an
     *              overridden event is what guarantees the correction actually wins. Absent from
     *              the array, or present with a null value, for a given column = behave exactly as
     *              before this param existed (use whatever Origami sent). Empty array (the default)
     *              is fully backward compatible with every existing caller.
     * @return array{earning:array,deduction:array,errors:array}
     */
    public function resolve(int $compId, array $syncItemRow, float $baseSalary, array $attendanceOverrides = []): array {
        $earning = [];
        $deduction = [];
        $errors = [];

        $dailyRate = $this->dailyRate($baseSalary, $syncItemRow);
        $hourlyRate = $this->hourlyRate($baseSalary, $syncItemRow);
        // 2026-08-29, real bug found and fixed (explicit report with the exact expected math worked
        // out by hand: baseSalary(13,500) / 30 / 8 = 56.25/hr, x1.5 OT multiplier = 84.375/hr, x1.5
        // hours = 126.5625 -> 126.56) -- OT premium pay must always be computed off the FIXED
        // standard 30-day/8-hour monthly-to-hourly conversion (the standard Thai OT formula for a
        // monthly-rate employee), never off `$hourlyRate`/`$dailyRate` above, which intentionally use
        // the period's ACTUAL working_days/working_mins when Origami sends them (a correctness fix
        // for absence/late/unpaid-leave DEDUCTIONS specifically -- see dailyRate()'s own 2026-08-20
        // docblock: "ถ้าขาดงานเท่ากับวันทำงาน ต้องหักเท่าเงินเดือนหรือเปล่า"). Reusing that same
        // variable-divisor rate for OT was never correct -- OT premium is a legally fixed conversion,
        // not "how many days did this employee actually have scheduled this period" -- so any period
        // where Origami's payload happened to include a non-standard working_days/working_mins (a
        // real month rarely has exactly 30 days worth of standard 8h shifts) silently produced a
        // wrong OT amount. otHourlyRate/otDailyRate below are used ONLY for the OT block immediately
        // following; every other calculation in this method (Late/Absent/Unpaid Leave/Trip
        // Allowance/generic items) is UNCHANGED, still correctly using the variable-divisor rate.
        $otHourlyRate = $baseSalary / self::STANDARD_WORKING_DAYS_PER_MONTH / self::STANDARD_HOURS_PER_DAY;
        $otDailyRate = $baseSalary / self::STANDARD_WORKING_DAYS_PER_MONTH;

        // Group item_values by item_code up front so every code (known or generic) is handled as
        // one candidate pool, not once per row -- see the class docblock's "MULTI-UNIT
        // DEDUPLICATION" section.
        $itemValues = is_array($syncItemRow['item_values'] ?? null) ? $syncItemRow['item_values'] : [];
        $groupedByCode = [];
        foreach ($itemValues as $iv) {
            $code = strtoupper(trim((string)($iv['item_code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $groupedByCode[$code][] = $iv;
        }

        // OT: unchanged, dedicated scope-split handling -- a generic item_values 'OT' row carries
        // no scope information to merge with the 3 scope-specific structured columns, so it stays
        // structured-column-only. Its item_values group is dropped (not double-processed below).
        // 2026-08-29: pedTypeBySourceEvent() can now also return ['disabled'=>true] (the admin
        // explicitly deactivated/deleted the catalog OT type) -- see that method's own docblock.
        // The scope loop below is skipped entirely in that case; the item_values discard call still
        // runs regardless so an OT-tagged generic item_values row doesn't leak through and get
        // double-counted as a "generic/custom item" later in this same method.
        $otMapped = $this->pedTypeBySourceEvent($compId, 'ot_hours');
        $otDisabled = is_array($otMapped) && !empty($otMapped['disabled']);
        $otResolved = $otDisabled ? null : ($otMapped ?? ['code' => 'OT', 'name_th' => 'ค่าล่วงเวลา', 'name_en' => 'Overtime Pay', 'is_custom' => true]);
        $this->pullKnownEventCandidates($groupedByCode, 'ot_hours', $otDisabled ? 'OT' : $otResolved['code']); // discarded -- no scope info to merge, structured columns only.
        foreach (($otDisabled ? [] : self::OT_SCOPE_COLUMNS) as $column => $scopeCode) {
            // 2026-08-21: an active attendance-data override wins outright -- no candidate pool
            // involved for OT hours at all (item_values OT rows are already always excluded, see
            // above), so this is a pure, safe substitution of the input.
            $hours = array_key_exists($column, $attendanceOverrides) && $attendanceOverrides[$column] !== null
                ? (float)$attendanceOverrides[$column]
                : (float)($syncItemRow[$column] ?? 0);
            if ($hours <= 0) {
                continue;
            }
            $rate = $this->otRateForScope($compId, $scopeCode);
            if ($rate === null) {
                $errors[] = "missing_ot_rate_{$scopeCode}";
                continue;
            }
            $isDailyBase = $rate['calculation_base'] === 'daily';
            $isFlat = $rate['calculation_method'] === 'flat_amount';
            $amount = $isFlat
                ? round($rate['flat_amount_rate'] * ($isDailyBase ? $hours / self::STANDARD_HOURS_PER_DAY : $hours), 2)
                : ($isDailyBase
                    ? round($otDailyRate * $rate['multiplier_rate'] * ($hours / self::STANDARD_HOURS_PER_DAY), 2)
                    : round($otHourlyRate * $rate['multiplier_rate'] * $hours, 2));
            if ($amount <= 0) {
                continue;
            }
            // 2026-08-29, explicit request: "OT ก็ให้เห็นสูตรคำนวณเลยว่า คำนวณจากอะไร ฐานเงินเดือนเท่าไหร่ /
            // กี่วัน และคูณกับอะไร ผลลัพธ์ออกมาเท่าไหร่" -- structured step-by-step calculation trace
            // (numbers only, formatted client-side for i18n) attached directly to the line so the
            // Detail page's formula popover can show the REAL numbers used, not a guess reverse-
            // engineered from the terse `note` string. `type` picks which step template the frontend
            // renders (see formulaStepsHtml() in detail.js).
            $formula = $isFlat
                ? [
                    'type' => 'ot_flat', 'scope' => $scopeCode, 'is_daily_base' => $isDailyBase,
                    'flat_rate' => (float)$rate['flat_amount_rate'], 'hours' => $hours,
                    'hours_divisor' => self::STANDARD_HOURS_PER_DAY, 'result' => $amount,
                ]
                : [
                    'type' => 'ot_multiplier', 'scope' => $scopeCode, 'is_daily_base' => $isDailyBase,
                    'base_salary' => $baseSalary, 'days_divisor' => self::STANDARD_WORKING_DAYS_PER_MONTH,
                    'hours_divisor' => self::STANDARD_HOURS_PER_DAY,
                    'unit_rate' => $isDailyBase ? $otDailyRate : $otHourlyRate,
                    'multiplier' => (float)$rate['multiplier_rate'], 'hours' => $hours, 'result' => $amount,
                ];
            $earning[] = [
                'source' => 'sync',
                'code' => $otResolved['code'],
                'name_th' => $otResolved['name_th'],
                'name_en' => $otResolved['name_en'],
                'amount' => $amount,
                'note' => "sync_ot_{$scopeCode}_{$hours}hours",
                'is_custom' => $otResolved['is_custom'],
                'formula' => $formula,
            ];
        }

        // Late / Absent / Unpaid Leave -- same candidate-pool dedup as trip allowance below, but the
        // chosen candidate is always converted to MINUTES (2026-08-21 correctness fix -- see
        // RULE_DRIVEN_ITEM_DEFS's own docblock) and run through the company's configurable
        // attendance_deduction_rules instead of a fixed formula.
        foreach (self::RULE_DRIVEN_ITEM_DEFS as $eventCode => $def) {
            // 2026-08-29: the admin explicitly deactivated/deleted the catalog type for this event
            // -- skip it entirely (see pedTypeBySourceEvent()'s own docblock). Still discard any
            // item_values rows tagged with its code first, so they don't leak through as a generic/
            // custom item further down in this same method.
            $mapped = $this->pedTypeBySourceEvent($compId, $eventCode);
            if (is_array($mapped) && !empty($mapped['disabled'])) {
                $this->pullKnownEventCandidates($groupedByCode, $eventCode, $def['default_code']);
                continue;
            }
            $resolved = $mapped ?? ['code' => $def['default_code'], 'name_th' => $def['name_th'], 'name_en' => $def['name_en'], 'is_custom' => true];

            // 2026-08-21: an active attendance-data override for ANY of this event's structured
            // columns bypasses pickBestCandidate()/pullKnownEventCandidates() ENTIRELY -- see this
            // method's own docblock for why partial correction + leftover pool candidates would
            // otherwise silently pick a stale, uncorrected representation instead.
            $overrideMinutes = null;
            foreach ($def['structured'] as $s) {
                if (array_key_exists($s['column'], $attendanceOverrides) && $attendanceOverrides[$s['column']] !== null) {
                    $overrideMinutes = $this->candidateToMinutes(['unit' => $s['unit'], 'value' => (float)$attendanceOverrides[$s['column']]]);
                    break;
                }
            }
            $isCorrected = $overrideMinutes !== null;

            if ($isCorrected) {
                $minutes = $overrideMinutes;
            } else {
                $candidates = [];
                foreach ($def['structured'] as $s) {
                    $v = (float)($syncItemRow[$s['column']] ?? 0);
                    if ($v > 0) {
                        $candidates[] = ['unit' => $s['unit'], 'value' => $v];
                    }
                }
                foreach ($this->pullKnownEventCandidates($groupedByCode, $eventCode, $resolved['code']) as $c) {
                    $candidates[] = $c;
                }

                $best = $this->pickBestCandidate($candidates);
                if ($best === null) {
                    continue;
                }
                $minutes = $this->candidateToMinutes($best);
            }
            if ($minutes <= 0) {
                continue;
            }
            $result = $this->computeAttendanceDeductionAmount($compId, $eventCode, $minutes, $hourlyRate);
            foreach ($result['errors'] as $e) {
                $errors[] = $e;
            }
            if ($result['amount'] > 0) {
                $deduction[] = [
                    'source' => 'sync',
                    'code' => $resolved['code'],
                    'name_th' => $resolved['name_th'],
                    'name_en' => $resolved['name_en'],
                    'amount' => $result['amount'],
                    'note' => "sync_{$eventCode}_{$minutes}minutes" . ($isCorrected ? '_corrected' : ''),
                    'is_custom' => $resolved['is_custom'],
                    'formula' => $result['formula'] ?? null,
                ];
            }
        }

        // Trip allowance -- candidate pool = structured column(s) + any item_values rows sharing
        // that item's catalog code, pick exactly one representation.
        foreach (self::KNOWN_ITEM_DEFS as $sourceEventCode => $def) {
            // 2026-08-29, real bug found and fixed (explicit report: "ค่าเที่ยวยังแสดงผลอยู่ครับ ทั้งๆที่
            // ไม่ได้กด Sync มาจาก Origami เพราะติ๊กส่วนนั้นออกไป" -- deactivating Trip Allowance in
            // Payroll Configuration had no effect at all, the line still computed and showed). Skip
            // this event entirely when the admin explicitly deactivated/deleted its catalog type --
            // see pedTypeBySourceEvent()'s own docblock. Still discard any item_values rows tagged
            // with its code first, so they don't leak through as a generic/custom item below.
            $mapped = $this->pedTypeBySourceEvent($compId, $sourceEventCode);
            if (is_array($mapped) && !empty($mapped['disabled'])) {
                $this->pullKnownEventCandidates($groupedByCode, $sourceEventCode, $def['default_code']);
                continue;
            }
            $resolved = $mapped ?? ['code' => $def['default_code'], 'name_th' => $def['name_th'], 'name_en' => $def['name_en'], 'is_custom' => true];

            // 2026-08-21: an active attendance-data override on this item's structured column wins
            // outright, bypassing the candidate pool -- same reasoning as the rule-driven loop above.
            $overrideAmount = null;
            foreach ($def['structured'] as $s) {
                if (array_key_exists($s['column'], $attendanceOverrides) && $attendanceOverrides[$s['column']] !== null) {
                    $overrideAmount = round((float)$attendanceOverrides[$s['column']], 2);
                    break;
                }
            }
            $isCorrected = $overrideAmount !== null;
            $formula = null;

            if ($isCorrected) {
                $amount = $overrideAmount;
                $noteSuffix = "{$amount}_corrected";
            } else {
                $candidates = [];
                foreach ($def['structured'] as $s) {
                    $v = (float)($syncItemRow[$s['column']] ?? 0);
                    if ($v > 0) {
                        $candidates[] = ['unit' => $s['unit'], 'value' => $v];
                    }
                }
                foreach ($this->pullKnownEventCandidates($groupedByCode, $sourceEventCode, $resolved['code']) as $iv) {
                    $candidates[] = $iv;
                }

                $best = $this->pickBestCandidate($candidates);
                if ($best === null) {
                    continue;
                }
                $amount = $this->candidateToAmount($best, $hourlyRate, $dailyRate);
                $noteSuffix = "{$best['value']}" . ($best['unit'] ?? 'money');
                // 2026-08-29: trip allowance is a direct passthrough (no rate/multiplier involved) --
                // still surfaced as a (trivial) formula for the same consistent popover format.
                $formula = ['type' => 'passthrough', 'raw_value' => $best['value'], 'unit' => $best['unit'], 'result' => $amount];
            }
            if ($amount <= 0) {
                continue;
            }
            $line = [
                'source' => 'sync',
                'code' => $resolved['code'],
                'name_th' => $resolved['name_th'],
                'name_en' => $resolved['name_en'],
                'amount' => $amount,
                'note' => "sync_{$sourceEventCode}_{$noteSuffix}",
                'is_custom' => $resolved['is_custom'],
                'formula' => $formula,
            ];
            if ($def['kind'] === 'earning') {
                $earning[] = $line;
            } else {
                $deduction[] = $line;
            }
        }

        // Whatever item_code groups are left are genuinely generic/custom items -- still may carry
        // more than one unit_type row for the same item_code, same candidate-pool treatment.
        //
        // 2026-08-29, two real bugs found and fixed here (explicit report: "Leave Approved ต้องไม่
        // นำมาบวกเป็นเงินได้...มีส่ง หักเงินคำประกันการทำงานมา แต่ไม่นำไปคิดเป็นรายการหัก"):
        //
        // 1. A DEDUCTION-typed item can legitimately arrive with a NEGATIVE `value` (Origami already
        //    communicates direction via item_type; the number itself is the raw signed amount --
        //    confirmed with a real example, a guarantee-money installment sent as value=-500.00) but
        //    pickBestCandidate()/the old `$amount <= 0` check below both silently discard anything
        //    non-positive, since every OTHER caller of those two methods (OT/trip allowance/rule-
        //    driven attendance events) only ever deals in positive magnitudes. Normalizing a
        //    DEDUCTION-typed group's candidate values to their absolute magnitude up front, before
        //    they ever reach that shared positive-only filtering, fixes this without touching
        //    pickBestCandidate()/candidateToAmount() themselves (where a negative input should stay
        //    invalid, since it's never legitimate for any of their other callers).
        //
        // 2. A payload item_type that is neither INCOME nor DEDUCTION (e.g. Origami's own 'INFO' --
        //    Leave Approved/Leave Pending are both sent this way) used to fall through to the
        //    `else` branch below and get added as INCOME by default, since the old code only ever
        //    checked "is it literally DEDUCTION" and treated every other value as earning. An
        //    approved leave day is neither an extra payment nor something to withhold -- it's purely
        //    informational (the employee's regular pay already covers it) -- so it must produce NO
        //    line at all. This is a general fix (any future INFO-typed item behaves the same way),
        //    not a hardcoded special case for these 2 item_codes specifically.
        foreach ($groupedByCode as $group) {
            $first = $group[0];
            $itemCode = (string)($first['item_code'] ?? '');
            $payloadType = strtoupper((string)($first['item_type'] ?? ''));
            $payloadIsDeduction = $payloadType === 'DEDUCTION';
            $payloadIsIncome = $payloadType === 'INCOME';

            $candidates = array_map(function ($iv) use ($payloadIsDeduction) {
                $value = (float)($iv['value'] ?? 0);
                return ['unit' => $iv['unit_type'] ?? null, 'value' => $payloadIsDeduction ? abs($value) : $value];
            }, $group);
            $best = $this->pickBestCandidate($candidates);
            if ($best === null) {
                continue;
            }
            $amount = $this->candidateToAmount($best, $hourlyRate, $dailyRate);
            if ($amount <= 0) {
                continue;
            }

            $catalog = $this->pedTypeByItemCode($compId, $itemCode);
            if ($catalog !== null) {
                // A company-configured catalog mapping always wins outright (it's an explicit admin
                // decision), regardless of whatever item_type Origami tagged the payload with.
                $line = [
                    'source' => 'sync',
                    'code' => $catalog['code'],
                    'name_th' => $catalog['name_th'],
                    'name_en' => $catalog['name_en'],
                    'amount' => $amount,
                    'note' => $first['remark'] ?? null,
                    'is_custom' => false,
                ];
                if ($catalog['item_type'] === 'earning') {
                    $earning[] = $line;
                } else {
                    $deduction[] = $line;
                }
                continue;
            }

            if (!$payloadIsDeduction && !$payloadIsIncome) {
                continue; // INFO or any other/unrecognized type -- informational only, no line produced.
            }
            $itemName = (string)($first['item_name'] ?? $itemCode);
            $line = [
                'source' => 'sync',
                'code' => 'CUSTOM:' . $itemName,
                'name_th' => $itemName,
                'name_en' => $itemName,
                'amount' => $amount,
                'note' => $first['remark'] ?? null,
                'is_custom' => true,
            ];
            if ($payloadIsDeduction) {
                $deduction[] = $line;
            } else {
                $earning[] = $line;
            }
        }

        return ['earning' => $earning, 'deduction' => $deduction, 'errors' => $errors];
    }

    /**
     * Picks exactly one candidate from a pool of {unit, value} representations of the same
     * real-world event -- the finest-grained time unit actually present and > 0 (minutes > hours >
     * days), falling back to a null/unrecognized unit (already money, or an unknown unit string) as
     * lowest priority since it can't be cross-checked against the others.
     * @param array<array{unit:?string,value:float}> $candidates
     */
    private function pickBestCandidate(array $candidates): ?array {
        $best = null;
        $bestRank = PHP_INT_MAX;
        foreach ($candidates as $c) {
            $value = (float)($c['value'] ?? 0);
            if ($value <= 0) {
                continue;
            }
            $unit = $c['unit'] ?? null;
            $rank = $unit === null ? 4 : (self::UNIT_PRIORITY[$unit] ?? 5);
            if ($rank < $bestRank) {
                $bestRank = $rank;
                $best = ['unit' => $unit, 'value' => $value];
            }
        }
        return $best;
    }

    private function candidateToAmount(array $candidate, float $hourlyRate, float $dailyRate): float {
        $unit = $candidate['unit'];
        $value = (float)$candidate['value'];
        if ($unit === 'minutes' || $unit === 'mins') {
            return round(($hourlyRate / 60.0) * $value, 2);
        }
        if ($unit === 'hours') {
            return round($hourlyRate * $value, 2);
        }
        if ($unit === 'days') {
            return round($dailyRate * $value, 2);
        }
        return round($value, 2); // null/unrecognized unit -- already a final money amount.
    }

    /**
     * Converts a chosen late candidate to plain minutes (unlike candidateToAmount(), never money --
     * late is inherently a time duration, fed to computeAttendanceDeductionAmount() instead). A
     * null/unrecognized unit is treated as already-minutes, the safest defensive fallback since
     * there is no "money" concept for lateness itself.
     */
    private function candidateToMinutes(array $candidate): float {
        $unit = $candidate['unit'];
        $value = (float)$candidate['value'];
        if ($unit === 'hours') {
            return $value * 60.0;
        }
        if ($unit === 'days') {
            return $value * self::STANDARD_HOURS_PER_DAY * 60.0;
        }
        return $value; // 'minutes'/'mins'/null/unrecognized.
    }

    /**
     * Converts a MINUTE count into whichever unit an admin's `rate_unit` config was expressed in --
     * used ONLY to interpret the admin's own typed `flat_amount`/`tiered_bracket` numbers, never to
     * reinterpret raw attendance data (that's what `candidateToMinutes()` above is for, and it's
     * always used first). This is the one place `STANDARD_HOURS_PER_DAY`'s 8h/day assumption still
     * applies to attendance deductions -- deliberately narrowed to "what did the admin mean when
     * they typed a per-day rate", not "how long was this specific day", which is exactly the
     * distinction the 2026-08-21 correctness fix (see RULE_DRIVEN_ITEM_DEFS's docblock) is about.
     */
    private function minutesToRateUnit(float $minutes, string $rateUnit): float {
        if ($rateUnit === 'hour') {
            return $minutes / 60.0;
        }
        if ($rateUnit === 'day') {
            return $minutes / (self::STANDARD_HOURS_PER_DAY * 60.0);
        }
        return $minutes; // 'minute'.
    }

    /**
     * Company-configurable attendance deduction (2026-08-20, explicit request -- originally
     * Late-only, replacing what used to be a fixed hourlyRate/60*minutes formula; generalized the
     * same day to Absent/Unpaid Leave too, before any real company had configured anything). No
     * `attendance_deduction_rules` row for this (company, event) = 'percent_of_rate' @ multiplier
     * 1.00, i.e. byte-for-byte the old fixed formula for that event -- see
     * AttendanceDeductionRuleModel::ruleGetAll() for the same default, used by the settings modal.
     *
     * `$minutes` is ALWAYS the actual attendance quantity in minutes (2026-08-21 correctness fix --
     * see RULE_DRIVEN_ITEM_DEFS's own docblock), for all 3 events uniformly, never a day count.
     * `percent_of_rate` is proven equivalent to the pre-fix per-event formula for a uniform-shift
     * company: if every day in the period is a standard 8h (`working_mins = working_days * 480`),
     * then `hourlyRate/60 = (baseSalary/working_mins) = dailyRate/480`, and a day-only candidate
     * converts to `days*480` minutes, so `(dailyRate/480) * (days*480) = dailyRate * days` --
     * identical to the old formula. It only diverges (correctly) when shift lengths vary within the
     * period, e.g. a half-day Saturday reports fewer actual `absent_mins` than a full weekday would.
     * @return array{amount:float,errors:array}
     */
    private function computeAttendanceDeductionAmount(int $compId, string $eventCode, float $minutes, float $hourlyRate): array {
        $rule = $this->attendanceDeductionRuleFor($compId, $eventCode);
        $methodCode = $rule['method_code'] ?? 'percent_of_rate';
        $rateUnit = $rule['rate_unit'] ?? 'minute';

        // 2026-08-29, explicit request: "ให้เป็น Format นี้ทุกสูตรการคำนวณที่แสดงผล" -- same structured
        // 'formula' trace convention as the OT block above, one shape per method_code (see
        // formulaStepsHtml() in detail.js for how each type renders).
        if ($methodCode === 'flat_amount') {
            $rate = (float)($rule['rate_per_unit'] ?? 0);
            $quantity = $this->minutesToRateUnit($minutes, $rateUnit);
            $amount = round($rate * $quantity, 2);
            return ['amount' => $amount, 'errors' => [], 'formula' => [
                'type' => 'attendance_flat', 'rate_unit' => $rateUnit, 'rate_per_unit' => $rate,
                'minutes' => $minutes, 'quantity_in_rate_unit' => $quantity, 'result' => $amount,
            ]];
        }

        if ($methodCode === 'tiered_bracket') {
            $brackets = $this->attendanceDeductionBrackets((int)($rule['id'] ?? 0));
            if (empty($brackets)) {
                return ['amount' => 0.0, 'errors' => ["attendance_deduction_no_brackets_configured_{$eventCode}"]];
            }
            $quantityInRateUnit = $this->minutesToRateUnit($minutes, $rateUnit);
            foreach ($brackets as $b) {
                $min = (float)$b['min_units'];
                $max = $b['max_units'] !== null ? (float)$b['max_units'] : null;
                if ($quantityInRateUnit >= $min && ($max === null || $quantityInRateUnit <= $max)) {
                    $amount = round((float)$b['deduction_amount'], 2);
                    return ['amount' => $amount, 'errors' => [], 'formula' => [
                        'type' => 'attendance_bracket', 'rate_unit' => $rateUnit, 'minutes' => $minutes,
                        'quantity_in_rate_unit' => $quantityInRateUnit, 'bracket_min' => $min, 'bracket_max' => $max, 'result' => $amount,
                    ]];
                }
            }
            return ['amount' => 0.0, 'errors' => []]; // fell in a gap between configured brackets -- an intentional grace zone, not an error.
        }

        // 'percent_of_rate' (default/fallback) -- always minute-based, rate_unit doesn't apply here.
        $multiplier = (float)($rule['multiplier_rate'] ?? 1.00);
        if ($multiplier <= 0) {
            $multiplier = 1.00;
        }
        $amount = round(($hourlyRate / 60.0) * $minutes * $multiplier, 2);
        return ['amount' => $amount, 'errors' => [], 'formula' => [
            'type' => 'attendance_percent', 'hourly_rate' => $hourlyRate, 'minutes' => $minutes,
            'multiplier' => $multiplier, 'result' => $amount,
        ]];
    }

    /** @return array{id:?int,method_code:string,rate_per_unit:?float,multiplier_rate:?float} */
    private function attendanceDeductionRuleFor(int $compId, string $eventCode): array {
        $stmt = $this->db->prepare("SELECT id, method_code, rate_unit, rate_per_unit, multiplier_rate
            FROM `attendance_deduction_rules` WHERE comp_id = :comp_id AND event_code = :event_code");
        $stmt->execute([':comp_id' => $compId, ':event_code' => $eventCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['id' => null, 'method_code' => 'percent_of_rate', 'rate_unit' => 'minute', 'rate_per_unit' => null, 'multiplier_rate' => 1.00];
        }
        return [
            'id' => (int)$row['id'],
            'method_code' => (string)$row['method_code'],
            'rate_unit' => (string)($row['rate_unit'] ?? 'minute'),
            'rate_per_unit' => $row['rate_per_unit'] !== null ? (float)$row['rate_per_unit'] : null,
            'multiplier_rate' => $row['multiplier_rate'] !== null ? (float)$row['multiplier_rate'] : null,
        ];
    }

    private function attendanceDeductionBrackets(int $ruleId): array {
        if ($ruleId <= 0) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT min_units, max_units, deduction_amount
            FROM `attendance_deduction_rule_brackets` WHERE rule_id = :rule_id ORDER BY min_units ASC");
        $stmt->execute([':rule_id' => $ruleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 2026-08-20, real report: "ถ้าขาดงานเท่ากับวันทำงาน ต้องหักเท่าเงินเดือนหรือเปล่า" -- with a fixed
     * 30-day divisor, an employee absent for their ENTIRE period (absent_days == actual working
     * days, e.g. 22) was under-deducted (22/30 of salary, not the full amount) because 30 rarely
     * equals a real working-day count. `payroll_sync_items.working_days` is Origami's own actual
     * working-day count for this employee/period -- using it as the divisor instead makes a
     * fully-absent period correctly deduct exactly the full base salary
     * (baseSalary/working_days * working_days = baseSalary). Falls back to the fixed constant only
     * when working_days isn't present in this row (defensive, same "may not send everything"
     * caution as the multi-unit dedup above).
     */
    private function dailyRate(float $baseSalary, array $syncItemRow = []): float {
        $workingDays = (float)($syncItemRow['working_days'] ?? 0);
        if ($workingDays > 0) {
            return $baseSalary / $workingDays;
        }
        return $baseSalary / self::STANDARD_WORKING_DAYS_PER_MONTH;
    }

    /** Same reasoning as dailyRate() above, but keyed off working_mins (more precise than
     *  working_days*8 whenever Origami's actual scheduled hours/day isn't exactly 8). */
    private function hourlyRate(float $baseSalary, array $syncItemRow = []): float {
        $workingMins = (float)($syncItemRow['working_mins'] ?? 0);
        if ($workingMins > 0) {
            return $baseSalary / ($workingMins / 60.0);
        }
        return $this->dailyRate($baseSalary, $syncItemRow) / self::STANDARD_HOURS_PER_DAY;
    }

    private function normalizeCode(string $code): string {
        return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $code) ?? '');
    }

    /**
     * Pulls every item_values row out of $groupedByCode whose item_code matches this known event --
     * either this company's own catalog item_code, or one of EVENT_ALIASES's fixed known spellings
     * (exact match only, after normalizing away case/punctuation) -- and returns them as
     * {unit,value} candidates. Matching groups are removed from $groupedByCode so they are never
     * ALSO processed by the generic/custom item_values loop later (that double-processing was the
     * real "ABSENT_DEDUCT...หัก 2 รอบ" bug this method exists to fix).
     * @return array<array{unit:?string,value:float}>
     */
    private function pullKnownEventCandidates(array &$groupedByCode, string $sourceEventCode, string $catalogCode): array {
        $accepted = array_map([$this, 'normalizeCode'], self::EVENT_ALIASES[$sourceEventCode] ?? []);
        $accepted[] = $this->normalizeCode($catalogCode);
        $accepted = array_unique(array_filter($accepted));

        $candidates = [];
        foreach (array_keys($groupedByCode) as $key) {
            if (!in_array($this->normalizeCode($key), $accepted, true)) {
                continue;
            }
            foreach ($groupedByCode[$key] as $iv) {
                $candidates[] = ['unit' => $iv['unit_type'] ?? null, 'value' => (float)($iv['value'] ?? 0)];
            }
            unset($groupedByCode[$key]);
        }
        return $candidates;
    }

    /** @return array{multiplier_rate:float,calculation_base:string}|null */
    private function otRateForScope(int $compId, string $scopeCode): ?array {
        $stmt = $this->db->prepare("SELECT r.multiplier_rate, r.calculation_base, r.calculation_method, r.flat_amount_rate
            FROM `ot_rates` r
            JOIN `master_ot_scope_types` s ON s.id = r.ot_scope_id
            WHERE r.comp_id = :comp_id AND r.status = 'active' AND r.deleted_at IS NULL
                AND s.code = :scope_code
            ORDER BY r.id ASC LIMIT 1");
        $stmt->execute([':comp_id' => $compId, ':scope_code' => $scopeCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'multiplier_rate' => (float)$row['multiplier_rate'],
            'calculation_base' => (string)$row['calculation_base'],
            'calculation_method' => (string)($row['calculation_method'] ?? 'multiplier'),
            'flat_amount_rate' => $row['flat_amount_rate'] !== null ? (float)$row['flat_amount_rate'] : 0.0,
        ];
    }

    /**
     * 2026-08-29, real bug found and fixed (explicit report: "ค่าเที่ยวยังแสดงผลอยู่ครับ ทั้งๆที่ไม่ได้
     * กด Sync มาจาก Origami เพราะติ๊กส่วนนั้นออกไป" -- Trip Allowance still showed even after the
     * admin turned that Earning Type off in Payroll Configuration). Root cause, confirmed by
     * tracing every one of this method's 3 call sites (OT, the Late/Absent/Unpaid-Leave
     * rule-driven loop, and Trip Allowance): each one does
     * `pedTypeBySourceEvent(...) ?? [hardcoded default definition]` -- this method used to filter
     * `status = 'active' AND deleted_at IS NULL` in its own WHERE clause, so a DEACTIVATED (or
     * deleted) catalog row looked EXACTLY like "no catalog row was ever configured for this source
     * event" to every caller -- both cases returned null, and both fell through to the SAME
     * hardcoded fallback definition (KNOWN_ITEM_DEFS/RULE_DRIVEN_ITEM_DEFS's own 'default_code'/
     * 'name_th'/'name_en'), which still computes and includes the line. Deactivating the type was
     * silently a no-op for this whole sync-derived-item pipeline -- the ONLY thing it actually
     * changed was which item_code/label got used (a hardcoded fallback instead of the real catalog
     * row), not whether the amount got computed and added to the run at all.
     *
     * Fixed by querying the row WITHOUT a status/deleted_at filter, then telling those two cases
     * apart explicitly: no row at all (this company genuinely never mapped a PED type to this
     * source event) still returns null, preserving the existing "fall back to a sensible hardcoded
     * default so sync data isn't silently dropped" behavior for a company that hasn't configured
     * Payroll Configuration yet -- but a row that EXISTS and is inactive/deleted now returns
     * `['disabled' => true]` instead, an explicit signal each call site checks for and `continue`s
     * past entirely (skips computing this source event's contribution at all), correctly honoring
     * the admin's actual decision to turn that item off rather than silently reverting to a
     * default. See this method's own return type and every one of its 3 call sites for how the
     * signal is consumed.
     *
     * @return array{code:string,name_th:string,name_en:string,item_type:string,is_custom:bool}|array{disabled:true}|null
     */
    private function pedTypeBySourceEvent(int $compId, string $sourceEventCode): ?array {
        $stmt = $this->db->prepare("SELECT item_code, item_name_th, item_name_en, item_type, status, deleted_at
            FROM `payroll_earning_deduction_types`
            WHERE comp_id = :comp_id AND source_event_code = :code LIMIT 1");
        $stmt->execute([':comp_id' => $compId, ':code' => $sourceEventCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if ($row['status'] !== 'active' || $row['deleted_at'] !== null) {
            return ['disabled' => true];
        }
        return $this->rowToResolved($row);
    }

    /** @return array{code:string,name_th:string,name_en:string,item_type:string,is_custom:bool}|null */
    private function pedTypeByItemCode(int $compId, string $itemCode): ?array {
        $stmt = $this->db->prepare("SELECT item_code, item_name_th, item_name_en, item_type
            FROM `payroll_earning_deduction_types`
            WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL
                AND UPPER(item_code) = UPPER(:item_code) LIMIT 1");
        $stmt->execute([':comp_id' => $compId, ':item_code' => $itemCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->rowToResolved($row) : null;
    }

    private function rowToResolved(array $row): array {
        return [
            'code' => $row['item_code'],
            'name_th' => $row['item_name_th'],
            'name_en' => $row['item_name_en'],
            'item_type' => $row['item_type'],
            'is_custom' => false,
        ];
    }
}
