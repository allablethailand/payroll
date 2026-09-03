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
        // 2026-08-30, real bug found and fixed: Origami's actual real-world item_code for this event
        // is 'ROUND' (confirmed against Origami's own item catalog: "ROUND -- Trip Allowance
        // (Robusta)"), not 'TRIP'/'TRIPALLOWANCE' as originally guessed -- those two guessed spellings
        // never matched anything real, so a redundant item_values row Origami sent alongside the
        // structured `trip_allowance` column was never excluded here and could double-count against
        // it (added AGAIN as a generic/custom item further down in resolve()) whenever the company's
        // own catalog item_code for Trip Allowance wasn't ALSO typed as exactly "ROUND". Kept as
        // defensive secondary spellings, not removed, in case a different environment really does use
        // one of them.
        'trip_allowance' => ['ROUND', 'TRIP', 'TRIPALLOWANCE'],
        'late' => ['LATE'],
        'absent' => ['ABSENT', 'ABSENCE'],
        'unpaid_leave' => ['UNPAIDLEAVE', 'LEAVEWITHOUTPAY', 'LEAVENOPAY'],
        // 2026-08-29, explicit request ("ลารออนุมัติ ถึงจะเอามาคำนวณเป็นเงินหัก") -- Origami's own
        // real item_code for this is 'LEAVE_PENDING' (normalizes to 'LEAVEPENDING', already covered
        // by the primary alias below), PENDINGLEAVE kept as a defensive secondary spelling only.
        'leave_pending' => ['LEAVEPENDING', 'PENDINGLEAVE'],
        // 2026-08-30, real bug found and fixed: Origami's real item_code is 'EARLY_LEAVE' (normalizes
        // to 'EARLYLEAVE') -- see RULE_DRIVEN_ITEM_DEFS['early_leave'] below, which was completely
        // missing until this fix (the source_event_code was already selectable in the "Linked
        // Attendance Event" dropdown -- master_payroll_source_events has always had 'early_leave' --
        // but nothing in this class ever computed it).
        'early_leave' => ['EARLYLEAVE'],
        // 2026-08-30 (Phase 2, T011, explicit request: "เพิ่มเบี้ยขยันเป็นเหตุการณ์ที่ดึงจาก Origami
        // (ไม่ใช่กรอกเองในหน้านี้)") -- before this, "DILIGENCE" was matched purely by an admin-typed
        // catalog item_code coincidentally equalling Origami's real item_code (the generic
        // item_values fallback loop at the bottom of resolve(), via pedTypeByItemCode()) -- fragile,
        // and NOT discoverable via the "Linked Attendance Event" dropdown the way every other synced
        // event is. Promoted to a proper KNOWN_ITEM_DEFS entry below (source_event_code='diligence',
        // now selectable in that dropdown, matched here the same alias-based way as every other
        // event) -- see KNOWN_ITEM_DEFS['diligence']'s own comment for why this changes
        // isKnownEventItemCode()'s answer for this one item_code (it now excludes DILIGENCE from
        // autoCreateMissingPedTypes()'s auto-catalog step, same as OT/trip_allowance/etc already are).
        'diligence' => ['DILIGENCE'],
        // 2026-08-30 (Phase 2, T013, explicit decision confirmed with user) -- OPT-IN only, unlike
        // every other entry in this array: this app has NO confirmed evidence of Origami's real
        // item_code for either of these (unlike DILIGENCE/ASSISTANCE, verified in this session's own
        // item_master payload testing) -- guessed as matching this catalog's OWN existing item_code
        // convention (STUDENT_LOAN/LOAN_REPAY, same as seedDefaults() already seeds), same "don't
        // write what isn't verified" posture as this project's DRAFT statutory exporters elsewhere.
        // A company only starts using this path at all once an admin explicitly selects the new
        // "Linked Attendance Event" dropdown option for their own STUDENT_LOAN/LOAN_REPAY catalog
        // item (seedDefaults() itself was deliberately NOT changed to auto-link -- see that method's
        // own comment) -- until then, the existing employee_earning_deductions manual installment
        // mechanism keeps working completely unchanged for every company, verified or not.
        'student_loan' => ['STUDENT_LOAN', 'STUDENTLOAN', 'SLF'],
        'loan_repay' => ['LOAN_REPAY', 'LOANREPAY', 'LOAN'],
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
        // 2026-08-30 (Phase 2, T011) -- unlike trip_allowance, diligence has no dedicated structured
        // `payroll_sync_items` column of its own (Origami only ever sends it inside the generic
        // `item_values[]` array, same as any other custom item) -- an intentionally EMPTY
        // 'structured' array is enough: the shared loop below just produces zero structured
        // candidates and falls through entirely to pullKnownEventCandidates() (EVENT_ALIASES-based
        // item_values matching), no special-casing needed anywhere else in this class.
        'diligence' => [
            'default_code' => 'DILIGENCE_ALLOW', 'name_th' => 'เบี้ยขยัน', 'name_en' => 'Diligence Allowance', 'kind' => 'earning',
            'structured' => [],
        ],
        // 2026-08-30 (Phase 2, T013) -- same "no structured column, item_values only" shape as
        // diligence, but `opt_in_only=true` (see the resolve() loop's own handling of this flag):
        // UNLIKE every other entry here, this event must have ZERO effect on a company that hasn't
        // explicitly linked a catalog row to it via source_event_code -- these item_codes (unlike
        // DILIGENCE/OT/ROUND) already have a REAL, pre-existing catalog row for most companies
        // (STUDENT_LOAN/LOAN_REPAY are seeded defaults, matched via the OLD generic item_code path,
        // carrying real tax_deduction_impact/statutory_report_code/calc_sso/calc_pf configuration).
        // Falling back to this array's own hardcoded default (is_custom=true, none of that real
        // configuration) the moment an alias matches -- the behavior every OTHER KNOWN_ITEM_DEFS
        // entry deliberately has -- would silently DISCARD that real configuration for every company
        // that hasn't opted in yet, a genuine regression from the pre-T013 behavior. `opt_in_only`
        // makes the loop skip this event ENTIRELY (not even calling pullKnownEventCandidates(), so
        // the item_values group is left untouched in $groupedByCode) whenever no catalog row has
        // actually linked source_event_code -- only once an admin opts in does this event start
        // intercepting matching item_values rows at all.
        'student_loan' => [
            'default_code' => 'STUDENT_LOAN', 'name_th' => 'หักเงินกู้ยืม กยศ.', 'name_en' => 'Student Loan Deduction', 'kind' => 'deduction',
            'structured' => [], 'opt_in_only' => true,
        ],
        'loan_repay' => [
            'default_code' => 'LOAN_REPAY', 'name_th' => 'หักเงินกู้ยืมพนักงาน', 'name_en' => 'Employee Loan Repayment', 'kind' => 'deduction',
            'structured' => [], 'opt_in_only' => true,
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
        // stops appearing here.
        //
        // 2026-08-30, real bug found and fixed: this originally shipped with `'structured' => []`
        // and a comment claiming "no structured payroll_sync_items column exists for this (unlike
        // late/absent/unpaid_leave)" -- that was wrong the day it was written. `leave_wait_days` was
        // added to `payroll_sync_items` one day earlier (2026-08-28, comprehensive schema catchup)
        // and IS Origami's own structured column for this exact event, but it was never wired in here
        // -- meaning a pull where Origami reported pending leave ONLY via the structured column (not
        // also redundantly via item_values, which is not guaranteed on every pull -- see this class's
        // own MULTI-UNIT DEDUPLICATION docblock) silently produced no deduction at all. Fixed by
        // reading it the same way absent_days/leave_without_pay_days already are.
        'leave_pending' => [
            'default_code' => 'LEAVE_PENDING_DEDUCT', 'name_th' => 'หักลารออนุมัติ', 'name_en' => 'Pending Leave Deduction',
            'structured' => [['column' => 'leave_wait_days', 'unit' => 'days']],
        ],
        // 2026-08-30, real bug found and fixed: 'early_leave' has always been a selectable
        // source_event_code (master_payroll_source_events row 3, "Linked Attendance Event" dropdown
        // in Payroll Configuration) and payroll_sync_items has always carried a structured `early_mins`
        // column for it, but this class never had an entry for it at all -- so mapping a PED type's
        // source_event_code to 'early_leave' had zero effect, the deduction was simply never computed.
        // Same treatment as late (minute-based structured column, resolved through the company's
        // configurable attendance_deduction_rules -- no row configured yet = percent_of_rate @
        // multiplier 1.00, same safe default every other event in this array falls back to).
        'early_leave' => [
            'default_code' => 'EARLY_LEAVE_DEDUCT', 'name_th' => 'หักกลับก่อนเวลา', 'name_en' => 'Early Leave Deduction',
            'structured' => [['column' => 'early_mins', 'unit' => 'minutes']],
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
     * @param string[] $exemptEventCodes 2026-08-30, explicit request: attendance-deduction event
     *              codes (subset of RULE_DRIVEN_ITEM_DEFS's own keys) this employee is exempt from,
     *              precomputed by the caller via AttendanceDeductionRuleModel::
     *              exemptEventCodesForEmployee() -- this class does no DB lookups of its own for
     *              WHO belongs to which department/team (it never queries `employees`), same
     *              "engine takes a precomputed flags param" pattern StatutoryCalculationEngine::
     *              calculate()'s own $employeeFlags already established. Empty array (the default)
     *              is fully backward compatible with every existing caller.
     * @param ?int $departmentId/$teamId 2026-08-30, multi-scope Attendance Deduction Rule rollout --
     *              this employee's own department_id/team_id, used ONLY to pick which SAVED rule
     *              variant applies (team > department > company-wide default -- see
     *              attendanceDeductionRuleFor()'s own docblock) when more than one exists for an
     *              event. Both null (the default) behaves exactly as before this param existed:
     *              every event resolves to its company-wide default row, same as when only one row
     *              per event could ever exist.
     * @param bool $otEligible Real gap found and fixed (2026-08-30): employees.ot_eligible has
     *              existed as an Employee Detail checkbox since before this feature, but was NEVER
     *              actually read anywhere in this class -- OT was always computed regardless of it.
     *              Defaults to true (backward compatible with every existing caller that doesn't
     *              pass it) -- PayrollRunModel::recalculate() is the only real caller and always
     *              passes the employee's own actual flag. When false, the entire OT scope loop below
     *              is skipped -- if the sync payload still carries nonzero OT hours anyway (Origami
     *              sent it, the employee is just flagged not to receive it), an advisory
     *              'ot_not_calculated_ineligible' error is pushed instead so
     *              PayrollRunModel::recalculate() can surface it as a non-blocking Remark (same
     *              precedent as daily_salary_no_shift_pattern/no_attendance_data_this_period) --
     *              never silently drops the discrepancy.
     * @param array<string,array{multiplier_rate:float,calculation_base:string,calculation_method:string,flat_amount_rate:float}> $otOverridesByScope
     *              2026-08-30, explicit request ("OT Rate...Assign รายบุคคลได้ด้วย"): this employee's
     *              own per-scope CUSTOM OT rate overrides, keyed by scope code (weekday/weekend/
     *              holiday) -- already resolved to ONLY the scopes this employee actually has a
     *              custom row for. Wins outright over $otRateSetRatesByScope below when present for
     *              a given scope -- see EmployeeOtRateModel's own docblock for why this is prefetched
     *              by the caller rather than queried here.
     * @param array<string,array{multiplier_rate:float,calculation_base:string,calculation_method:string,flat_amount_rate:float}> $otRateSetRatesByScope
     *              2026-08-30 (real gap found and fixed -- previously read the flat `ot_rates` table
     *              directly via a now-removed otRateForScope(), which had no way to disambiguate
     *              multiple rows for the same scope beyond an arbitrary `ORDER BY id ASC LIMIT 1`):
     *              this employee's already-RESOLVED OT Rate Set (OtRateSetModel::
     *              resolveRatesForEmployees() -- explicit assigned_ot_rate_set_id pick, else
     *              employee>team>position>department assignment match, else the company's mandatory
     *              Default set), keyed by scope code. Used only for a scope $otOverridesByScope
     *              doesn't already cover.
     * @return array{earning:array,deduction:array,errors:array}
     */
    public function resolve(int $compId, array $syncItemRow, float $baseSalary, array $attendanceOverrides = [], array $exemptEventCodes = [], ?int $departmentId = null, ?int $teamId = null, bool $otEligible = true, array $otOverridesByScope = [], array $otRateSetRatesByScope = []): array {
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
        // wrong OT amount. This fixed-divisor conversion is used ONLY for the OT block immediately
        // following (2026-08-30: now computed inside computeOtAmountFromConfig() itself, extracted
        // out for the OT Rate settings form's own calculation-preview feature -- see that method's
        // own docblock); every other calculation in this method (Late/Absent/Unpaid Leave/Trip
        // Allowance/generic items) is UNCHANGED, still correctly using the variable-divisor rate.

        // 2026-09-02, originally added in response to an Origami bug-fix notice ("Report Item not
        // selected -> field sent as 0", previously always sent with a real value regardless of
        // selection) that raised a real concern: dailyRate()/hourlyRate() above treat a <=0
        // working_days/working_mins as "not provided, fall back to the fixed 30-day standard
        // divisor" -- correct for a genuinely absent field, but WRONG if a process ever selected
        // Late/Absent/Unpaid-Leave/etc. (real deduction quantities) without ALSO selecting Working
        // Days/Working Minutes, silently reintroducing the exact under-deduction bug dailyRate()'s
        // own 2026-08-20 docblock describes. Origami's own follow-up clarified this specific
        // combination can NEVER actually happen from their real payload: working_days/working_mins
        // share the SAME umbrella selection flag as the whole Late/Absent/OT/leave group on their
        // side, not an independent "Working Days" item -- so this warning will never fire against a
        // genuine sync payload. KEPT ANYWAY (not removed) because it's independently useful for a
        // DIFFERENT, real, reachable path in THIS codebase: `TransactionDataPayAdapter` (Manual
        // Entry/Import for a cycle-based, non-sync run) feeds this exact same resolve() method but
        // DELIBERATELY never sets working_days/working_mins at all (its own docblock: "inventing one
        // here would be a guess") -- meaning this exact fallback is the NORMAL, EXPECTED case for
        // that path, not a bug to alarm over. Downgraded to advisory-only (see
        // PayrollRunModel::recalculate()'s own $blockingErrors filter, 2026-09-02) precisely because
        // of that -- never blocks submit(), just tells an admin which amount used the standard
        // divisor instead of a real period-specific one. Only actually affects money for events using
        // the DEFAULT/'percent_of_rate' deduction method (the only method that reads $hourlyRate at
        // all -- 'flat_amount'/'tiered_bracket' use admin-configured numbers independent of the
        // salary-derived rate) -- flagged per-event inside computeAttendanceDeductionAmount() below
        // via $usedFallbackDivisor, not here, so a tiered_bracket/flat_amount event (or one whose
        // quantity falls in a bracket grace-zone with zero amount either way) never raises a noisy
        // false-positive warning about a divisor its own computed amount never actually used.
        $usedFallbackDivisor = ((float)($syncItemRow['working_days'] ?? 0) <= 0) && ((float)($syncItemRow['working_mins'] ?? 0) <= 0);

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
        // 2026-08-30, real gap found and fixed: $otEligible used to never be checked at all here --
        // OT was computed regardless of employees.ot_eligible. The loop still RUNS regardless (so
        // real, present hours can be detected and flagged) but every scope skips computing an actual
        // amount when ineligible, pushing ONE advisory error (not per-scope) if any hours existed at
        // all -- see this method's own docblock for the full reasoning.
        $otHoursIgnoredDueToIneligibility = false;
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
            if (!$otEligible) {
                $otHoursIgnoredDueToIneligibility = true;
                continue;
            }
            // 2026-08-30, explicit request ("OT Rate...Assign รายบุคคลได้ด้วย"): this employee's own
            // CUSTOM rate for this scope wins outright over the resolved OT Rate Set -- no partial
            // merge, a scope this employee has NOT customized still falls through to whatever Set
            // was already resolved for them (OtRateSetModel::resolveRatesForEmployees(), see this
            // method's own docblock for the full priority chain).
            $rate = $otOverridesByScope[$scopeCode] ?? $otRateSetRatesByScope[$scopeCode] ?? null;
            if ($rate === null) {
                $errors[] = "missing_ot_rate_{$scopeCode}";
                continue;
            }
            $otResult = self::computeOtAmountFromConfig($rate, $baseSalary, $hours);
            $amount = $otResult['amount'];
            if ($amount <= 0) {
                continue;
            }
            $formula = $otResult['formula'];
            $formula['scope'] = $scopeCode;
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
        if ($otHoursIgnoredDueToIneligibility) {
            $errors[] = 'ot_not_calculated_ineligible';
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
            $result = $this->computeAttendanceDeductionAmount($compId, $eventCode, $minutes, $hourlyRate, $departmentId, $teamId, $usedFallbackDivisor);
            foreach ($result['errors'] as $e) {
                $errors[] = $e;
            }
            // 2026-08-30, explicit request: "หักหรือไม่หักกับแผนกไหน ทีมไหน หรือเจาะจงรายคน...และมีหมายเหตุใน
            // กรณีที่ไม่หัก" -- an exempt employee still gets a LINE (amount forced to 0, is_exempted=true,
            // exempted_amount carries what it WOULD have been) rather than being silently skipped, so
            // the Process Detail breakdown modal has something to show ("Late deduction not applied —
            // employee is exempt") instead of the item just quietly not appearing. Deliberately reuses
            // the existing deduction_breakdown array (no new payroll_run_details column) -- this is
            // the same array the breakdown modal already renders per line.
            $isExempt = in_array($eventCode, $exemptEventCodes, true);
            if ($result['amount'] > 0 || $isExempt) {
                $deduction[] = [
                    'source' => 'sync',
                    'code' => $resolved['code'],
                    'name_th' => $resolved['name_th'],
                    'name_en' => $resolved['name_en'],
                    'amount' => $isExempt ? 0.0 : $result['amount'],
                    'note' => "sync_{$eventCode}_{$minutes}minutes" . ($isCorrected ? '_corrected' : '') . ($isExempt ? '_exempted' : ''),
                    'is_custom' => $resolved['is_custom'],
                    'formula' => $result['formula'] ?? null,
                    'is_exempted' => $isExempt,
                    'exempted_amount' => $isExempt ? $result['amount'] : null,
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
            // 2026-08-30 (Phase 2, T013): an opt_in_only event with NO catalog row linked at all
            // (never configured -- $mapped === null, not the "explicitly deactivated" disabled case
            // just below) must not touch $groupedByCode -- leaving its item_values group completely
            // untouched lets the generic fallback loop further down find the real, pre-existing
            // catalog row via its own item_code match instead, preserving that row's real
            // configuration. See KNOWN_ITEM_DEFS['student_loan']'s own comment for the full reasoning.
            if (!empty($def['opt_in_only']) && $mapped === null) {
                continue;
            }
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
                    // 2026-08-31, same-day follow-up (Origami's `scheduled_item_occurrences[]`
                    // proposal) -- the RAW item_code Origami sent, preserved alongside the resolved
                    // 'code' above. Usually identical here (pedTypeByItemCode() matches BY item_code,
                    // so $catalog['code'] already equals $itemCode modulo casing) but kept as its own
                    // explicit field rather than relied on as an invariant, since the CUSTOM: fallback
                    // branch just below genuinely does differ (see that line's own comment) --
                    // PayrollRunModel::syncDeductionLinesForEmployee() needs this to correlate a
                    // resolved breakdown line back to occurrence rows keyed by Origami's own raw code.
                    'sync_item_code' => $itemCode,
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
                // See the catalog-match branch's own comment just above -- HERE is where 'code' and
                // the raw item_code genuinely diverge ('CUSTOM:{name}' vs the real item_code), which
                // is exactly why this field exists as its own thing rather than being inferred.
                'sync_item_code' => $itemCode,
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
     * @param bool $usedFallbackDivisor 2026-09-02 -- true when dailyRate()/hourlyRate() fell back to
     *        the fixed 30-day standard divisor because Origami sent working_days/working_mins as 0
     *        this pull (see resolve()'s own docblock). Only raises a visible error when the
     *        resolved rule's method is 'percent_of_rate' (default included) AND $minutes>0 actually
     *        produced a nonzero amount -- the only combination where the fallback divisor genuinely
     *        affected the computed money; 'flat_amount'/'tiered_bracket' amounts (and a
     *        tiered_bracket grace-zone quantity that produces zero either way) never depend on the
     *        rate at all, so flagging them would be a false alarm.
     * @return array{amount:float,errors:array}
     */
    private function computeAttendanceDeductionAmount(int $compId, string $eventCode, float $minutes, float $hourlyRate, ?int $departmentId = null, ?int $teamId = null, bool $usedFallbackDivisor = false): array {
        $rule = $this->attendanceDeductionRuleFor($compId, $eventCode, $departmentId, $teamId);
        $brackets = ($rule['method_code'] ?? 'percent_of_rate') === 'tiered_bracket'
            ? $this->attendanceDeductionBrackets((int)($rule['id'] ?? 0))
            : [];
        $result = self::computeAttendanceDeductionFromConfig($rule, $brackets, $minutes, $hourlyRate);
        if (($rule['method_code'] ?? 'percent_of_rate') === 'tiered_bracket' && empty($brackets)) {
            $result['errors'][] = "attendance_deduction_no_brackets_configured_{$eventCode}";
        }
        if ($usedFallbackDivisor && ($rule['method_code'] ?? 'percent_of_rate') === 'percent_of_rate' && $result['amount'] > 0) {
            $result['errors'][] = "working_days_fallback_with_attendance_deduction:{$eventCode}";
        }
        return $result;
    }

    /**
     * 2026-08-30, extracted out of the OT block in resolve() above (explicit request, same
     * calculation-preview feature as computeAttendanceDeductionFromConfig() just below this one --
     * "ทำ OT ต่อเลยครับ") so the exact same OT formula can run against a DRAFT, not-yet-saved OT Rate
     * config from the settings form, not just a DB-loaded one -- ONE place this formula lives,
     * shared by the real per-employee calculation above and OtRatePreview (wherever that ends up
     * being called from) so the preview can never drift out of sync with real payroll. Pure/
     * stateless -- no DB access, no side effects. otHourlyRate/otDailyRate are computed HERE from
     * $baseSalary (not passed in) using the same fixed STANDARD_WORKING_DAYS_PER_MONTH/
     * STANDARD_HOURS_PER_DAY divisors resolve() itself uses for OT specifically (see that block's own
     * docblock for why OT deliberately does NOT use the variable, sync-derived working-days divisor
     * every other calculation in this class uses).
     * @param array $rate {calculation_method, calculation_base, flat_amount_rate, multiplier_rate} --
     *   same shape OtRateSetModel::resolveRatesForEmployees()/EmployeeOtRateModel's own item rows
     *   return, or an equivalent draft array from a form.
     * @return array{amount:float,formula:array}
     */
    public static function computeOtAmountFromConfig(array $rate, float $baseSalary, float $hours): array {
        $otHourlyRate = $baseSalary / self::STANDARD_WORKING_DAYS_PER_MONTH / self::STANDARD_HOURS_PER_DAY;
        $otDailyRate = $baseSalary / self::STANDARD_WORKING_DAYS_PER_MONTH;
        $isDailyBase = ($rate['calculation_base'] ?? 'hourly') === 'daily';
        $isFlat = ($rate['calculation_method'] ?? 'multiplier') === 'flat_amount';
        $flatRate = (float)($rate['flat_amount_rate'] ?? 0);
        $multiplier = (float)($rate['multiplier_rate'] ?? 1.0);
        $amount = $isFlat
            ? round($flatRate * ($isDailyBase ? $hours / self::STANDARD_HOURS_PER_DAY : $hours), 2)
            : ($isDailyBase
                ? round($otDailyRate * $multiplier * ($hours / self::STANDARD_HOURS_PER_DAY), 2)
                : round($otHourlyRate * $multiplier * $hours, 2));
        // 2026-08-29, explicit request: "OT ก็ให้เห็นสูตรคำนวณเลยว่า คำนวณจากอะไร ฐานเงินเดือนเท่าไหร่ / กี่วัน
        // และคูณกับอะไร ผลลัพธ์ออกมาเท่าไหร่" -- same structured step-by-step trace convention as every
        // other 'formula' shape in this class (see formulaStepsHtml() in detail.js for how each type
        // renders). 'scope' is intentionally NOT set here -- the real per-employee call site above
        // adds it itself (this function has no scope concept of its own, a preview call site has no
        // scope at all since it's not computing for a real employee/period).
        $formula = $isFlat
            ? [
                'type' => 'ot_flat', 'is_daily_base' => $isDailyBase,
                'flat_rate' => $flatRate, 'hours' => $hours,
                'hours_divisor' => self::STANDARD_HOURS_PER_DAY, 'result' => $amount,
            ]
            : [
                'type' => 'ot_multiplier', 'is_daily_base' => $isDailyBase,
                'base_salary' => $baseSalary, 'days_divisor' => self::STANDARD_WORKING_DAYS_PER_MONTH,
                'hours_divisor' => self::STANDARD_HOURS_PER_DAY,
                'unit_rate' => $isDailyBase ? $otDailyRate : $otHourlyRate,
                'multiplier' => $multiplier, 'hours' => $hours, 'result' => $amount,
            ];
        return ['amount' => $amount, 'formula' => $formula];
    }

    /**
     * 2026-08-30, extracted out of computeAttendanceDeductionAmount() above (explicit request: "อยาก
     * ให้เพิ่มปุ่มแสดงตัวอย่างการคำนวณจากการตั้งค่าที่เลือก" -- a calculation-preview button on the
     * Attendance Deduction Rule settings form) so the exact same formula logic can run against a
     * DRAFT, not-yet-saved rule config (what the admin is currently typing into the form) instead of
     * always reading from the database -- ONE place this formula is implemented, used by both the
     * real per-employee calculation above (DB-loaded config) and AttendanceDeductionRuleModel::
     * previewCalculation() (form-draft config), so the preview can never silently drift out of sync
     * with what payroll actually computes. Pure/stateless -- no DB access, no side effects.
     * @param array $rule {method_code, rate_unit, rate_per_unit, multiplier_rate} -- same shape
     *   attendanceDeductionRuleFor() returns, or an equivalent draft array from a form.
     * @param array $brackets only read when method_code='tiered_bracket' -- same shape
     *   attendanceDeductionBrackets() returns ({min_units, max_units, deduction_amount}).
     * @return array{amount:float,errors:array,formula?:array}
     */
    public static function computeAttendanceDeductionFromConfig(array $rule, array $brackets, float $minutes, float $hourlyRate): array {
        $methodCode = $rule['method_code'] ?? 'percent_of_rate';
        $rateUnit = $rule['rate_unit'] ?? 'minute';

        // 2026-08-30 (T015, "เพิ่มตัวเลือก 'ไม่หัก'") -- always zero, no matter how many minutes the
        // employee was late/absent -- no rate/formula to configure, unlike every other method here.
        // Deliberately checked FIRST, before minutes/hourlyRate ever matter for anything.
        if ($methodCode === 'no_deduction') {
            return ['amount' => 0.0, 'errors' => [], 'formula' => [
                'type' => 'attendance_no_deduction', 'minutes' => $minutes, 'result' => 0.0,
            ]];
        }

        // 2026-08-29, explicit request: "ให้เป็น Format นี้ทุกสูตรการคำนวณที่แสดงผล" -- same structured
        // 'formula' trace convention as the OT block above, one shape per method_code (see
        // formulaStepsHtml() in detail.js for how each type renders).
        if ($methodCode === 'flat_amount') {
            $rate = (float)($rule['rate_per_unit'] ?? 0);
            $quantity = self::minutesToRateUnit($minutes, $rateUnit);
            $amount = round($rate * $quantity, 2);
            return ['amount' => $amount, 'errors' => [], 'formula' => [
                'type' => 'attendance_flat', 'rate_unit' => $rateUnit, 'rate_per_unit' => $rate,
                'minutes' => $minutes, 'quantity_in_rate_unit' => $quantity, 'result' => $amount,
            ]];
        }

        if ($methodCode === 'tiered_bracket') {
            if (empty($brackets)) {
                return ['amount' => 0.0, 'errors' => []];
            }
            $quantityInRateUnit = self::minutesToRateUnit($minutes, $rateUnit);
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

    /**
     * Converts a MINUTE count into whichever unit an admin's `rate_unit` config was expressed in --
     * used ONLY to interpret the admin's own typed `flat_amount`/`tiered_bracket` numbers, never to
     * reinterpret raw attendance data (that's what `candidateToMinutes()` above is for, and it's
     * always used first). This is the one place `STANDARD_HOURS_PER_DAY`'s 8h/day assumption still
     * applies to attendance deductions -- deliberately narrowed to "what did the admin mean when
     * they typed a per-day rate", not "how long was this specific day", which is exactly the
     * distinction the 2026-08-21 correctness fix (see RULE_DRIVEN_ITEM_DEFS's docblock) is about.
     * Static since computeAttendanceDeductionFromConfig() (its only caller) is static too.
     */
    private static function minutesToRateUnit(float $minutes, string $rateUnit): float {
        if ($rateUnit === 'hour') {
            return $minutes / 60.0;
        }
        if ($rateUnit === 'day') {
            return $minutes / (self::STANDARD_HOURS_PER_DAY * 60.0);
        }
        return $minutes; // 'minute'.
    }

    /**
     * 2026-08-30, multi-scope Attendance Deduction Rule rollout -- an event can now have several
     * saved rows (one company-wide default, scope_type/scope_id NULL, plus any number of team/
     * department-scoped overrides). Picks the single most-specific one that applies to THIS
     * employee: team > department > company-wide default -- same priority as
     * AttendanceDeductionRuleModel::resolveVariantRow() (re-implemented independently here rather
     * than shared -- see that class's own docblock for why). Deliberately does NOT filter on
     * is_active: the resolved row's config is still used to compute a "would-have-been" amount even
     * when inactive/exempt, so the Process Detail breakdown modal has a real number to show next to
     * "not applied" -- see resolve()'s own $isExempt handling, which is what actually suppresses it.
     * @return array{id:?int,method_code:string,rate_per_unit:?float,multiplier_rate:?float}
     */
    private function attendanceDeductionRuleFor(int $compId, string $eventCode, ?int $departmentId = null, ?int $teamId = null): array {
        $stmt = $this->db->prepare("SELECT id, method_code, rate_unit, rate_per_unit, multiplier_rate, scope_type, scope_id
            FROM `attendance_deduction_rules` WHERE comp_id = :comp_id AND event_code = :event_code");
        $stmt->execute([':comp_id' => $compId, ':event_code' => $eventCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return ['id' => null, 'method_code' => 'percent_of_rate', 'rate_unit' => 'minute', 'rate_per_unit' => null, 'multiplier_rate' => 1.00];
        }

        $teamRow = null; $deptRow = null; $defaultRow = null;
        foreach ($rows as $r) {
            if ($r['scope_type'] === 'team' && $teamId !== null && (int)$r['scope_id'] === $teamId) {
                $teamRow = $r;
            } elseif ($r['scope_type'] === 'department' && $departmentId !== null && (int)$r['scope_id'] === $departmentId) {
                $deptRow = $r;
            } elseif ($r['scope_type'] === null) {
                $defaultRow = $r;
            }
        }
        $row = $teamRow ?? $deptRow ?? $defaultRow;
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
     * 2026-08-30, exposed for PayrollSyncModel's `item_master` auto-catalog step (PAYROLL_SYNC_API.md's
     * 2026-08-30 revision -- "รายการไหนยังไม่มีให้ insert auto ไปได้เลยไหม") -- true when $itemCode
     * (normalized the same way EVENT_ALIASES matching already works) is one of the built-in events
     * this class computes via a dedicated structured-column/default_code path (OT, trip allowance
     * ("ROUND"), late, absent, early leave, unpaid leave, leave pending). These are already fully
     * functional with zero `payroll_earning_deduction_types` row required -- ONLY `source_event_code`
     * (never `item_code`) ever wires a catalog row into one of them (see pedTypeBySourceEvent()) -- so
     * auto-creating an item_code-matched row for one of these would be an inert decoy that looks
     * configured but has no effect on anything. Built directly from EVENT_ALIASES so the two can never
     * drift out of sync with each other.
     */
    public static function isKnownEventItemCode(string $itemCode): bool {
        static $flat = null;
        if ($flat === null) {
            $flat = [];
            foreach (self::EVENT_ALIASES as $aliases) {
                foreach ($aliases as $alias) {
                    $flat[$alias] = true;
                }
            }
        }
        $normalized = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $itemCode) ?? '');
        return isset($flat[$normalized]);
    }

    /**
     * 2026-08-30, built for PayrollRunModel's `pay_basis='sync_actual_days'` (reads
     * PROBATION_WORKING_DAYS out of a raw payroll_sync_items row) -- a general-purpose reader for
     * any INFO-typed (or otherwise never-resolved-into-a-line) item_values entry a caller needs the
     * raw numeric value of, matched by item_code (normalized the same way every other alias match in
     * this class works -- case/punctuation-insensitive, exact match only). Returns the value of the
     * FIRST matching entry (same "may be sent redundantly across units, this class picks one"
     * caution as resolve() itself, though a plain day-count item like PROBATION_WORKING_DAYS has
     * never been observed with more than one representation) or null when absent -- callers must
     * treat null as "no data this cycle" (Origami didn't send it, wrong policy, employee outside the
     * window, etc.), never as zero.
     */
    public static function extractInfoItemValue(array $syncItemRow, string $itemCode): ?float {
        $itemValues = is_array($syncItemRow['item_values'] ?? null) ? $syncItemRow['item_values'] : [];
        $target = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $itemCode) ?? '');
        foreach ($itemValues as $iv) {
            $code = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', (string)($iv['item_code'] ?? '')) ?? '');
            if ($code === $target && isset($iv['value']) && is_numeric($iv['value'])) {
                return (float)$iv['value'];
            }
        }
        return null;
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
