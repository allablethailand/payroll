<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/CompanyStatutorySettingModel.php';

/**
 * Reads company_statutory_settings + statutory_items/rate_history/brackets and
 * computes employee/employer deduction & contribution amounts for a given calculation
 * date. Contains no per-country if/else — all behavior is driven by calc_method/calc_base
 * config. Intended to be called from the Payroll Process module once per employee per cycle.
 */
class StatutoryCalculationEngine {
    private PDO $db;
    private CompanyStatutorySettingModel $companySettingModel;

    /**
     * Maps a statutory item code to the `employees` column that gates per-employee
     * participation (an employee can be excluded from a company-wide statutory item, e.g.
     * opted out of SSO or not yet enrolled in the provident fund). This is a data lookup,
     * not per-country branching logic — items with no entry here are always applicable
     * (matches pre-fix behavior; SG/MY/US employees have no analogous per-employee columns
     * yet, so their items are unaffected until those columns/tables exist).
     */
    private const ITEM_ENROLLMENT_FLAG = [
        'TH_SSO' => 'sso_enrolled',
        'TH_PVD' => 'pvd_enrolled',
    ];
    /** Item codes that a tax-exempt employee should skip entirely. */
    private const TAX_EXEMPT_ITEMS = ['TH_PIT'];

    /** Run states whose statutory_breakdown counts as a settled "fact" for monthly-ceiling
     *  accumulation -- same list ThPitCalculator::YTD_STATES already uses for its own YTD
     *  accumulation, naturally excludes the still-editable DRAFT run currently being calculated. */
    private const MONTHLY_USAGE_STATES = ['approved', 'paid', 'locked'];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->companySettingModel = new CompanyStatutorySettingModel($this->db);
    }

    /**
     * @param int $compId
     * @param array<string,float> $salaryContext keyed by calc_base name, e.g.
     *        ['basic_salary'=>30000, 'gross_salary'=>32000, 'taxable_income'=>250000, 'net_income'=>28000]
     * @param string $calcDate 'YYYY-MM-DD'
     * @param array<string,bool> $employeeFlags per-employee participation flags, e.g.
     *        ['sso_enrolled'=>true, 'pvd_enrolled'=>false, 'tax_exempt'=>false]. A key that is
     *        absent (not explicitly false) is treated as enrolled/not-exempt, so callers that
     *        don't pass this at all keep the pre-fix "always applicable" behavior.
     * @param array<string,array{employee_rate_override?:?float,employer_rate_override?:?float}> $employeeRateOverrides
     *        per-employee rate override, keyed by item code (only meaningful for calc_method=
     *        'flat_rate' items, e.g. 'TH_SSO'). 2026-09-02, real per-employee SSO rate override --
     *        `employees.sso_contribution_rate`/`sso_employer_contribution_rate`, wired in by
     *        PayrollRunModel::recalculate(). Deliberately a SEPARATE param from $employeeFlags
     *        (that one's own documented contract is bool-only) rather than repurposing it. Wins
     *        over company_statutory_settings' own employee_rate_override/employer_rate_override
     *        (which itself already wins over the master rate) -- precedence is
     *        employee override > company override > master rate. A null/absent value for either
     *        key leaves that side's existing (company-or-master) rate untouched.
     * @param ?int $employeeId 2026-09-04, Backlog Phase 10, T060 -- when given together with
     *        $periodStartDate, enables TRUE cumulative-monthly ceiling tracking for a flat_rate
     *        item's max_base_amount/max_employee_contribution/max_employer_contribution (the real
     *        Thai SSO/PVD monthly caps -- 15,000 THB wage base / 750 THB contribution) across
     *        MULTIPLE settled runs within the same calendar month (e.g. 4-5 weekly runs), instead
     *        of each run independently re-applying the FULL monthly ceiling (a real bug: 4-5x
     *        over-withholding the moment a non-monthly payroll_frequency is used with SSO/PVD on
     *        -- see monthlyUsagePriorToThisPeriod()'s own docblock). Both null (the default) skips
     *        this entirely -- byte-identical to pre-T060 behavior -- which is exactly correct for
     *        every existing caller that doesn't have a real employee/period context (the Calc
     *        Preview feature, every test file calling calculateItem() directly) AND for a genuine
     *        monthly payroll_frequency company (there is never a PRIOR settled run this same
     *        calendar month by construction, so the accumulation query naturally returns zero
     *        usage and this is a no-op -- no explicit payroll_frequency check needed anywhere).
     * @param ?string $periodStartDate 'YYYY-MM-DD', the CURRENT run's own period_start_date --
     *        required together with $employeeId to enable the monthly-ceiling tracking above.
     * @return array{calc_date:string, items:array, total_employee_deduction:float, total_employer_contribution:float}
     */
    public function calculate(int $compId, array $salaryContext, string $calcDate, array $employeeFlags = [], array $employeeRateOverrides = [], ?int $employeeId = null, ?string $periodStartDate = null): array {
        $items = $this->companySettingModel->list($compId);
        $lines = [];
        $totalEmployee = 0.0;
        $totalEmployer = 0.0;
        foreach ($items as $item) {
            $line = $this->calculateLine($item, $salaryContext, $calcDate, $employeeFlags, $employeeRateOverrides, $compId, $employeeId, $periodStartDate);
            $lines[] = $line;
            $totalEmployee += $line['employee_amount'];
            $totalEmployer += $line['employer_amount'];
        }
        return [
            'calc_date' => $calcDate,
            'items' => $lines,
            'total_employee_deduction' => round($totalEmployee, 2),
            'total_employer_contribution' => round($totalEmployer, 2),
        ];
    }

    /** Calculate a single item by its master code (e.g. 'TH_SSO'), useful for targeted lookups/tests.
     *  See calculate()'s own docblock for $employeeId/$periodStartDate (T060 monthly-ceiling tracking). */
    public function calculateItem(int $compId, string $itemCode, array $salaryContext, string $calcDate, array $employeeFlags = [], array $employeeRateOverrides = [], ?int $employeeId = null, ?string $periodStartDate = null): ?array {
        foreach ($this->companySettingModel->list($compId) as $item) {
            if ($item['code'] === $itemCode) {
                return $this->calculateLine($item, $salaryContext, $calcDate, $employeeFlags, $employeeRateOverrides, $compId, $employeeId, $periodStartDate);
            }
        }
        return null;
    }

    private function calculateLine(array $item, array $salaryContext, string $calcDate, array $employeeFlags = [], array $employeeRateOverrides = [], ?int $compId = null, ?int $employeeId = null, ?string $periodStartDate = null): array {
        $baseKey = $item['calc_base'];
        $base = array_key_exists($baseKey, $salaryContext) ? (float)$salaryContext[$baseKey] : 0.0;

        $line = [
            'statutory_item_id' => (int)$item['statutory_item_id'],
            'code' => $item['code'],
            // 2026-08-21, real bug fix (explicit report: "รายละเอียดการคำนวณ...แสดงแค่ Code
            // อยากให้มีชื่อด้วย ที่เห็นชัดว่าขาดคือ รายการหัก (ภาครัฐ)") -- name_th/name_en were
            // already selected by CompanyStatutorySettingModel::list() into $item, just never
            // carried through into this returned line, so every consumer (Payroll Run Detail's
            // breakdown modal, statutory_breakdown stored on payroll_run_details, etc.) only ever
            // had the bare code to show.
            'name_th' => $item['name_th'] ?? null,
            'name_en' => $item['name_en'] ?? null,
            'category' => $item['category'],
            'calc_method' => $item['calc_method'],
            'calc_base' => $baseKey,
            'base_amount' => $base,
            'employee_amount' => 0.0,
            'employer_amount' => 0.0,
            'rate_source' => 'master',
            'note' => null,
            'formula' => null,
        ];

        // 2026-09-04, Backlog Phase 9, T047 -- companion safety fix to calc_base becoming a master
        // table (master_statutory_calc_bases): before this, an item whose calc_base didn't match
        // any $salaryContext key (e.g. a NEW calc_base value added to the master table with no
        // matching PayrollRunModel::recalculate() wiring behind it yet -- adding a row there no
        // longer needs a code deploy, but making the engine actually COMPUTE that new context key
        // still does) silently fell through to `$base = 0.0` with NO note at all -- a real deduction
        // that should have applied would just compute 0.0 every run, forever, with nothing in the
        // breakdown to explain why. Set here as the array's own default value (not inside an if),
        // so every explicit `$line['note'] = ...` assignment below (disabled/not_enrolled/
        // tax_exempt/no_rate_configured/etc.) naturally OVERWRITES it with a more specific, more
        // actionable reason when one applies -- this note only ever SURVIVES to the final returned
        // line when the item would otherwise have computed a real (but base-less, wrongly-zero)
        // amount, which is exactly the dangerous case worth flagging.
        if (!array_key_exists($baseKey, $salaryContext)) {
            $line['note'] = 'unrecognized_calc_base';
        }

        if ($item['effective_status'] !== 'active') {
            $line['note'] = 'disabled';
            return $line;
        }

        $flagName = self::ITEM_ENROLLMENT_FLAG[$item['code']] ?? null;
        if ($flagName !== null && array_key_exists($flagName, $employeeFlags) && !$employeeFlags[$flagName]) {
            $line['note'] = 'employee_not_enrolled';
            return $line;
        }
        if (in_array($item['code'], self::TAX_EXEMPT_ITEMS, true) && !empty($employeeFlags['tax_exempt'])) {
            $line['note'] = 'employee_tax_exempt';
            return $line;
        }

        // 2026-09-08, Clone+Version redesign -- $item['item_scope'] ('master'/'custom', already
        // carried by every row CompanyStatutorySettingModel::list() returns) decides which rows
        // resolveEffectiveRate()/itemHasAnyRateHistory() are even allowed to consider: a CUSTOM
        // item's own rate history is always comp_id-IS-NULL-scoped (ownership is transitive via
        // statutory_item_id, unchanged by this redesign), so it never participates in the
        // company-clone lookup at all -- only a MASTER item does.
        $rateCompId = ($item['item_scope'] ?? 'master') === 'custom' ? null : $compId;
        $rateRow = $this->resolveEffectiveRate((int)$item['statutory_item_id'], $calcDate, $rateCompId);
        $noRateNote = $this->itemHasAnyRateHistory((int)$item['statutory_item_id'], $rateCompId) ? 'no_rate_configured' : 'no_rate_ever_configured';

        switch ($item['calc_method']) {
            case 'flat_rate':
                if (!$rateRow) {
                    $line['note'] = $noRateNote;
                    return $line;
                }
                // 2026-09-08: the old company-wide flat override (company_statutory_settings.
                // employee_rate_override etc.) is gone -- $rateRow itself already IS the
                // company's own current version when one exists (resolveEffectiveRate() above
                // already preferred it), so there is no separate "company override" layer left to
                // detect here. Per-employee rate still wins over whatever $rateRow resolved to --
                // see calculate()'s own docblock. Only applied when the caller actually passed a
                // non-null value for that side; a present-but-null key (or an absent item code
                // entirely) leaves the resolved rate untouched.
                $employeeOverride = $employeeRateOverrides[$item['code']] ?? [];
                $isEmployeeOverride = false;
                if (array_key_exists('employee_rate_override', $employeeOverride) && $employeeOverride['employee_rate_override'] !== null) {
                    $item['employee_rate_override'] = $employeeOverride['employee_rate_override'];
                    $isEmployeeOverride = true;
                }
                if (array_key_exists('employer_rate_override', $employeeOverride) && $employeeOverride['employer_rate_override'] !== null) {
                    $item['employer_rate_override'] = $employeeOverride['employer_rate_override'];
                    $isEmployeeOverride = true;
                }
                // 2026-09-04, Backlog Phase 10, T060 -- see calculate()'s own docblock. Only
                // queried when a real employee/period context was actually passed in (both null =
                // legacy callers, e.g. Calc Preview / tests calling calculateItem() directly --
                // no monthly context to accumulate against, so this stays a no-op for them exactly
                // like before this change).
                $priorMonthUsage = [];
                if ($compId !== null && $employeeId !== null && $periodStartDate !== null) {
                    $priorMonthUsage = $this->monthlyUsagePriorToThisPeriod($compId, $employeeId, $item['code'], $periodStartDate);
                }
                [$line['employee_amount'], $line['employer_amount'], $line['base_amount'], $line['formula']] = self::computeFlatRate($item, $rateRow, $base, $priorMonthUsage);
                $line['rate_source'] = $isEmployeeOverride ? 'employee_override' : 'master';
                return $line;

            case 'fixed_amount':
                if (!$rateRow) {
                    $line['note'] = $noRateNote;
                    return $line;
                }
                [$line['employee_amount'], $line['employer_amount'], $line['formula']] = self::computeFixedAmount($item, $rateRow);
                $line['rate_source'] = 'master';
                return $line;

            case 'progressive_bracket':
                if (!$rateRow) {
                    $line['note'] = $noRateNote;
                    return $line;
                }
                $brackets = $this->fetchBrackets((int)$rateRow['id']);
                if (empty($brackets)) {
                    $line['note'] = 'no_brackets_configured';
                    return $line;
                }
                [$tax, $line['formula']] = self::computeProgressiveBracket($brackets, $base, $item);
                $line['employee_amount'] = $item['is_employee_applicable'] ? $tax : 0.0;
                $line['employer_amount'] = 0.0;
                return $line;

            case 'formula':
                [$line['employee_amount'], $line['employer_amount'], $note, $line['formula']] = self::computeFormula($item, $rateRow, $base, $noRateNote);
                if ($note) {
                    $line['note'] = $note;
                }
                return $line;

            default:
                $line['note'] = 'unknown_calc_method';
                return $line;
        }
    }

    /**
     * 2026-08-29, explicit request: "ให้มีการกำหนดเพิ่มได้ว่าปัดเศษ หรือไม่ปัด ถ้าปัดปัดแบบไหน และทศนิยม
     * ได้กี่ตำแหน่ง แล้วตอนคำนวณให้นำไปใช้ด้วย" -- replaces every previously-hardcoded round($x, 2)
     * inside the per-item compute methods below. `$item` is the row from
     * CompanyStatutorySettingModel::list() (or ::get()), which now carries rounding_mode/
     * decimal_places straight from statutory_items -- missing keys (old cached/test fixtures)
     * default to 'round'/2, i.e. byte-identical to the pre-fix hardcoded behavior.
     */
    public static function applyRounding(float $value, array $item): float {
        $decimals = isset($item['decimal_places']) ? (int)$item['decimal_places'] : 2;
        $mode = $item['rounding_mode'] ?? 'round';
        $factor = 10 ** $decimals;
        switch ($mode) {
            case 'up':
                return ceil($value * $factor) / $factor;
            case 'down':
                return floor($value * $factor) / $factor;
            case 'none':
                // Truncate toward zero -- drop excess digits with no rounding adjustment either way.
                return ($value >= 0 ? floor($value * $factor) : ceil($value * $factor)) / $factor;
            case 'round':
            default:
                return round($value, $decimals);
        }
    }

    /**
     * 2026-09-08, Clone+Version redesign -- prefers the COMPANY's own cloned/customized version
     * (comp_id = $compId) when one exists, falling back to Master's own row (comp_id IS NULL)
     * otherwise. The fallback covers 2 real cases: a CUSTOM item (always resolved with
     * $compId=null by the caller, see calculateLine()'s own comment -- ownership is transitive
     * via statutory_item_id already, not this column), and a MASTER item a company hasn't been
     * cloned for yet (a pre-this-feature company that predates the activation-time clone hook, or
     * a brand-new Master item added after that company's own clone already ran).
     */
    private function resolveEffectiveRate(int $itemId, string $calcDate, ?int $compId): ?array {
        if ($compId !== null) {
            $row = $this->resolveEffectiveRateScoped($itemId, $calcDate, $compId);
            if ($row) {
                return $row;
            }
        }
        return $this->resolveEffectiveRateScoped($itemId, $calcDate, null);
    }
    private function resolveEffectiveRateScoped(int $itemId, string $calcDate, ?int $compId): ?array {
        $sql = "SELECT * FROM `statutory_item_rate_history`
            WHERE statutory_item_id = :item_id AND deleted_at IS NULL
            AND effective_date <= :calc_date AND (end_date IS NULL OR end_date >= :calc_date)
            AND " . ($compId === null ? "comp_id IS NULL" : "comp_id = :comp_id") . "
            ORDER BY effective_date DESC LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $params = [':item_id' => $itemId, ':calc_date' => $calcDate];
        if ($compId !== null) {
            $params[':comp_id'] = $compId;
        }
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * True if this statutory item has NEVER had a single rate history row entered, for anyone,
     * at any date -- i.e. "not rolled out on this deployment yet" (e.g. SG/MY/US items seeded in
     * statutory_items with zero real CPF/SOCSO/EPF rates configured), as opposed to a real gap in
     * an otherwise-maintained timeline. Distinguishing these two lets recalculate() treat the
     * former as a soft "not yet configured" note (0 amount, no hard block) and the latter as a
     * real misconfiguration worth blocking the run over. Same company-scoped-then-Master-fallback
     * shape as resolveEffectiveRate() above, for the same 2 reasons.
     */
    private function itemHasAnyRateHistory(int $itemId, ?int $compId): bool {
        if ($compId !== null) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM `statutory_item_rate_history` WHERE statutory_item_id = :item_id AND comp_id = :comp_id AND deleted_at IS NULL");
            $stmt->execute([':item_id' => $itemId, ':comp_id' => $compId]);
            if ((int)$stmt->fetchColumn() > 0) {
                return true;
            }
        }
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `statutory_item_rate_history` WHERE statutory_item_id = :item_id AND comp_id IS NULL AND deleted_at IS NULL");
        $stmt->execute([':item_id' => $itemId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * 2026-09-04, Backlog Phase 10, T060 -- sums a flat_rate item's (e.g. TH_SSO/TH_PVD)
     * base_amount/employee_amount/employer_amount, as they were ACTUALLY recorded (i.e. already
     * capped by whatever ceiling applied on that earlier run), across every SETTLED run
     * (approved/paid/locked -- same MONTHLY_USAGE_STATES list as ThPitCalculator::YTD_STATES,
     * naturally excludes the still-DRAFT run currently being calculated) for this employee whose
     * period_start_date falls in the SAME CALENDAR MONTH as $periodStartDate, strictly BEFORE it
     * (never including the current period itself -- same "prior to this period" boundary
     * ThPitCalculator::ytdGrossPriorToThisPeriod() already uses for its own YEAR-scoped
     * accumulation, this is the identical pattern scoped to a MONTH instead). Reads
     * statutory_breakdown (JSON, decoded PHP-side -- same convention as
     * ThPitCalculator::ytdPitWithheldPriorToThisPeriod(), not a fragile in-SQL JSON function).
     *
     * For a genuine MONTHLY payroll_frequency company this always returns all-zeros (there is
     * never a second settled run for the same employee in the same calendar month by
     * construction), which is exactly why calculate()/calculateItem() need no explicit
     * payroll_frequency branch anywhere -- this query's own natural result already makes the
     * monthly-ceiling logic a no-op for the existing monthly case.
     *
     * @return array{base:float, employee_contribution:float, employer_contribution:float}
     */
    private function monthlyUsagePriorToThisPeriod(int $compId, int $employeeId, string $itemCode, string $periodStartDate): array {
        $year = (int)substr($periodStartDate, 0, 4);
        $month = (int)substr($periodStartDate, 5, 2);
        $placeholders = implode(',', array_fill(0, count(self::MONTHLY_USAGE_STATES), '?'));
        $stmt = $this->db->prepare("SELECT d.statutory_breakdown FROM `payroll_run_details` d
            JOIN `payroll_runs` r ON r.id = d.run_id
            WHERE r.comp_id = ? AND r.deleted_at IS NULL AND r.state IN ({$placeholders})
                AND YEAR(r.period_start_date) = ? AND MONTH(r.period_start_date) = ?
                AND r.period_start_date < ? AND d.employee_id = ?");
        $stmt->execute(array_merge([$compId], self::MONTHLY_USAGE_STATES, [$year, $month, $periodStartDate, $employeeId]));
        $base = 0.0;
        $employeeContribution = 0.0;
        $employerContribution = 0.0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $items = json_decode((string)$json, true);
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $sItem) {
                if (($sItem['code'] ?? null) === $itemCode) {
                    $base += (float)($sItem['base_amount'] ?? 0);
                    $employeeContribution += (float)($sItem['employee_amount'] ?? 0);
                    $employerContribution += (float)($sItem['employer_amount'] ?? 0);
                }
            }
        }
        return ['base' => $base, 'employee_contribution' => $employeeContribution, 'employer_contribution' => $employerContribution];
    }

    private function fetchBrackets(int $rateHistoryId): array {
        $stmt = $this->db->prepare("SELECT min_amount, max_amount, rate FROM `statutory_item_brackets`
            WHERE statutory_item_rate_history_id = :id ORDER BY bracket_order ASC");
        $stmt->execute([':id' => $rateHistoryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array{base?:float, employee_contribution?:float, employer_contribution?:float} $priorMonthUsage
     *        2026-09-04, Backlog Phase 10, T060 -- how much of THIS calendar month's ceiling has
     *        already been consumed by earlier settled runs for this same employee (see
     *        StatutoryCalculationEngine::monthlyUsagePriorToThisPeriod()). Defaults to empty (=
     *        zero prior usage, i.e. the FULL ceiling is still available) -- byte-identical to
     *        pre-T060 behavior, exactly correct for the Calc Preview caller below (no real
     *        employee/period timeline to accumulate against) and for a genuine monthly
     *        payroll_frequency company (there is never any prior usage to report).
     * @return array{0:float,1:float,2:float,3:array} [employee_amount, employer_amount, effective_base, formula]
     * Public+static (like every other computeXxx() below) so the Tax & Statutory settings
     * page's Calculation Preview feature (previewRateVersion() on TaxStatutoryModel) can run
     * the EXACT same formula against a draft/not-yet-saved rate version as real payroll
     * calculation uses -- same rationale as SyncPayResolver's computeOtAmountFromConfig()/
     * computeAttendanceDeductionFromConfig() (2026-08-29/30 calc-preview rollout).
     */
    public static function computeFlatRate(array $item, array $rateRow, float $base, array $priorMonthUsage = []): array {
        $employeeRate = $item['employee_rate_override'] ?? $rateRow['employee_rate'];
        $employerRate = $item['employer_rate_override'] ?? $rateRow['employer_rate'];
        $priorBase = (float)($priorMonthUsage['base'] ?? 0.0);
        $priorEmployeeContribution = (float)($priorMonthUsage['employee_contribution'] ?? 0.0);
        $priorEmployerContribution = (float)($priorMonthUsage['employer_contribution'] ?? 0.0);

        $effBase = $base;
        if ($rateRow['min_base_amount'] !== null) {
            $effBase = max($effBase, (float)$rateRow['min_base_amount']);
        }
        if ($rateRow['max_base_amount'] !== null) {
            // T060: the ceiling itself is unchanged (still the real monthly max_base_amount), but
            // what's LEFT of it this period is reduced by whatever earlier settled runs this same
            // calendar month already consumed -- once a prior run already used up the full
            // monthly base, this period correctly contributes zero more, instead of every period
            // independently re-applying the whole ceiling from scratch.
            $remainingBase = max(0.0, (float)$rateRow['max_base_amount'] - $priorBase);
            $effBase = min($effBase, $remainingBase);
        }

        $employeeAmount = 0.0;
        $employerAmount = 0.0;
        $employeeRawAmount = null;
        $employeeCapped = false;
        if ($item['is_employee_applicable'] && $employeeRate !== null) {
            $employeeRawAmount = self::applyRounding($effBase * (float)$employeeRate / 100, $item);
            $employeeAmount = $employeeRawAmount;
            if ($rateRow['max_employee_contribution'] !== null) {
                // T060: same "remaining allowance this month" treatment as the base cap above,
                // applied to the explicit contribution ceiling (a separate real-world figure, e.g.
                // 750 THB for SSO -- not assumed to always equal max_base_amount * rate% exactly).
                $remainingEmployeeCap = max(0.0, (float)$rateRow['max_employee_contribution'] - $priorEmployeeContribution);
                $employeeAmount = min($employeeAmount, $remainingEmployeeCap);
                $employeeCapped = $employeeAmount < $employeeRawAmount;
            }
        }
        if ($item['is_employer_applicable'] && $employerRate !== null) {
            $employerAmount = self::applyRounding($effBase * (float)$employerRate / 100, $item);
            if ($rateRow['max_employer_contribution'] !== null) {
                $remainingEmployerCap = max(0.0, (float)$rateRow['max_employer_contribution'] - $priorEmployerContribution);
                $employerAmount = min($employerAmount, $remainingEmployerCap);
            }
        }
        // 2026-08-29, explicit request: "ประกันสังคม อยากให้เห็นสูตรคำนวณด้วยครับ...ให้เป็น Format นี้ทุก
        // สูตรการคำนวณที่แสดงผล" -- structured trace covering TH_SSO/TH_PVD's own flat_rate calc_method
        // (and any future item using the same method): raw eligible base -> min/max base clamping ->
        // rate% -> raw amount -> max contribution cap -> final result. Same convention as
        // SyncPayResolver's own 'formula' field on earning/deduction lines.
        $formula = [
            'type' => 'flat_rate',
            'raw_base' => $base, 'min_base' => $rateRow['min_base_amount'] !== null ? (float)$rateRow['min_base_amount'] : null,
            'max_base' => $rateRow['max_base_amount'] !== null ? (float)$rateRow['max_base_amount'] : null,
            // T060: how much of this month's base/contribution ceiling earlier settled runs
            // already consumed -- 0.0 for a monthly-frequency run or a legacy caller with no
            // employee/period context (see this method's own $priorMonthUsage docblock).
            'prior_month_base' => $priorBase, 'prior_month_employee_contribution' => $priorEmployeeContribution,
            'effective_base' => $effBase, 'employee_rate' => $employeeRate !== null ? (float)$employeeRate : null,
            'employee_raw_amount' => $employeeRawAmount, 'max_employee_contribution' => $rateRow['max_employee_contribution'] !== null ? (float)$rateRow['max_employee_contribution'] : null,
            'employee_capped' => $employeeCapped, 'result' => $employeeAmount,
        ];
        return [$employeeAmount, $employerAmount, $effBase, $formula];
    }

    /**
     * @return array{0:float,1:float,2:array} [employee_amount, employer_amount, formula]
     * Static/public per the same calc-preview rationale as computeFlatRate() above.
     */
    public static function computeFixedAmount(array $item, array $rateRow): array {
        $employeeSourceAmount = $item['employee_amount_override'] ?? $rateRow['employee_amount'];
        $employerSourceAmount = $item['employer_amount_override'] ?? $rateRow['employer_amount'];
        $employeeAmount = ($item['is_employee_applicable'] && $employeeSourceAmount !== null) ? self::applyRounding((float)$employeeSourceAmount, $item) : 0.0;
        $employerAmount = ($item['is_employer_applicable'] && $employerSourceAmount !== null) ? self::applyRounding((float)$employerSourceAmount, $item) : 0.0;
        $formula = [
            'type' => 'fixed_amount',
            'is_employee_applicable' => (bool)$item['is_employee_applicable'],
            'is_employer_applicable' => (bool)$item['is_employer_applicable'],
            'employee_source_amount' => $employeeSourceAmount !== null ? (float)$employeeSourceAmount : null,
            'employer_source_amount' => $employerSourceAmount !== null ? (float)$employerSourceAmount : null,
            'employee_amount' => $employeeAmount, 'employer_amount' => $employerAmount,
        ];
        return [$employeeAmount, $employerAmount, $formula];
    }

    /**
     * @return array{0:float,1:array} [tax_amount, formula]
     * Static/public per the same calc-preview rationale as computeFlatRate() above.
     */
    public static function computeProgressiveBracket(array $brackets, float $base, array $item): array {
        $tax = 0.0;
        $steps = [];
        foreach ($brackets as $b) {
            $min = (float)$b['min_amount'];
            $max = $b['max_amount'] !== null ? (float)$b['max_amount'] : null;
            if ($base <= $min) {
                break;
            }
            $upper = $max !== null ? min($base, $max) : $base;
            $taxable = max(0.0, $upper - $min);
            $bracketTax = $taxable * (float)$b['rate'] / 100;
            $tax += $bracketTax;
            $steps[] = ['min' => $min, 'max' => $max, 'rate' => (float)$b['rate'], 'taxable' => $taxable, 'tax' => $bracketTax];
            if ($max !== null && $base <= $max) {
                break;
            }
        }
        $result = self::applyRounding($tax, $item);
        $formula = ['type' => 'progressive_bracket', 'base' => $base, 'steps' => $steps, 'result' => $result];
        return [$result, $formula];
    }

    /**
     * formula_config JSON shape (per side, both optional):
     * {"employee": {"base_rate":1.45,"extra_rate":0.9,"extra_threshold":200000}, "employer": {"base_rate":1.45}}
     * @return array{0:float,1:float,2:?string,3:?array} [employee_amount, employer_amount, note, formula]
     * Static/public per the same calc-preview rationale as computeFlatRate() above.
     */
    public static function computeFormula(array $item, ?array $rateRow, float $base, string $noRateNote = 'no_rate_configured'): array {
        if (!$rateRow || empty($rateRow['formula_config'])) {
            return [0.0, 0.0, $noRateNote, null];
        }
        $config = json_decode($rateRow['formula_config'], true);
        if (!is_array($config)) {
            return [0.0, 0.0, 'invalid_formula_config', null];
        }
        $employeeAmount = $item['is_employee_applicable'] ? self::applyThresholdFormula($config['employee'] ?? null, $base, $item) : 0.0;
        $employerAmount = $item['is_employer_applicable'] ? self::applyThresholdFormula($config['employer'] ?? null, $base, $item) : 0.0;
        $formula = [
            'type' => 'formula', 'base' => $base,
            'employee_config' => $config['employee'] ?? null, 'employer_config' => $config['employer'] ?? null,
            'employee_amount' => $employeeAmount, 'employer_amount' => $employerAmount,
        ];
        return [$employeeAmount, $employerAmount, null, $formula];
    }

    public static function applyThresholdFormula(?array $sideConfig, float $base, array $item): float {
        if ($sideConfig === null || !isset($sideConfig['base_rate'])) {
            return 0.0;
        }
        $amount = $base * (float)$sideConfig['base_rate'] / 100;
        if (isset($sideConfig['extra_rate'], $sideConfig['extra_threshold'])) {
            $extraBase = max(0.0, $base - (float)$sideConfig['extra_threshold']);
            $amount += $extraBase * (float)$sideConfig['extra_rate'] / 100;
        }
        return self::applyRounding($amount, $item);
    }
}
