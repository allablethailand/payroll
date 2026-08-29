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
     * @return array{calc_date:string, items:array, total_employee_deduction:float, total_employer_contribution:float}
     */
    public function calculate(int $compId, array $salaryContext, string $calcDate, array $employeeFlags = []): array {
        $items = $this->companySettingModel->list($compId);
        $lines = [];
        $totalEmployee = 0.0;
        $totalEmployer = 0.0;
        foreach ($items as $item) {
            $line = $this->calculateLine($item, $salaryContext, $calcDate, $employeeFlags);
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

    /** Calculate a single item by its master code (e.g. 'TH_SSO'), useful for targeted lookups/tests. */
    public function calculateItem(int $compId, string $itemCode, array $salaryContext, string $calcDate, array $employeeFlags = []): ?array {
        foreach ($this->companySettingModel->list($compId) as $item) {
            if ($item['code'] === $itemCode) {
                return $this->calculateLine($item, $salaryContext, $calcDate, $employeeFlags);
            }
        }
        return null;
    }

    private function calculateLine(array $item, array $salaryContext, string $calcDate, array $employeeFlags = []): array {
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

        $rateRow = $this->resolveEffectiveRate((int)$item['statutory_item_id'], $calcDate);
        $noRateNote = $this->itemHasAnyRateHistory((int)$item['statutory_item_id']) ? 'no_rate_configured' : 'no_rate_ever_configured';

        switch ($item['calc_method']) {
            case 'flat_rate':
                if (!$rateRow) {
                    $line['note'] = $noRateNote;
                    return $line;
                }
                $isOverride = $item['employee_rate_override'] !== null || $item['employer_rate_override'] !== null;
                [$line['employee_amount'], $line['employer_amount'], $line['base_amount'], $line['formula']] = $this->computeFlatRate($item, $rateRow, $base);
                $line['rate_source'] = $isOverride ? 'company_override' : 'master';
                return $line;

            case 'fixed_amount':
                if (!$rateRow) {
                    $line['note'] = $noRateNote;
                    return $line;
                }
                $isOverride = $item['employee_amount_override'] !== null || $item['employer_amount_override'] !== null;
                [$line['employee_amount'], $line['employer_amount']] = $this->computeFixedAmount($item, $rateRow);
                $line['rate_source'] = $isOverride ? 'company_override' : 'master';
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
                $tax = $this->computeProgressiveBracket($brackets, $base, $item);
                $line['employee_amount'] = $item['is_employee_applicable'] ? $tax : 0.0;
                $line['employer_amount'] = 0.0;
                return $line;

            case 'formula':
                [$line['employee_amount'], $line['employer_amount'], $note] = $this->computeFormula($item, $rateRow, $base, $noRateNote);
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
    private function applyRounding(float $value, array $item): float {
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

    private function resolveEffectiveRate(int $itemId, string $calcDate): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `statutory_item_rate_history`
            WHERE statutory_item_id = :item_id AND deleted_at IS NULL
            AND effective_date <= :calc_date AND (end_date IS NULL OR end_date >= :calc_date)
            ORDER BY effective_date DESC LIMIT 1");
        $stmt->execute([':item_id' => $itemId, ':calc_date' => $calcDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * True if this statutory item has NEVER had a single rate history row entered, for anyone,
     * at any date -- i.e. "not rolled out on this deployment yet" (e.g. SG/MY/US items seeded in
     * statutory_items with zero real CPF/SOCSO/EPF rates configured), as opposed to a real gap in
     * an otherwise-maintained timeline. Distinguishing these two lets recalculate() treat the
     * former as a soft "not yet configured" note (0 amount, no hard block) and the latter as a
     * real misconfiguration worth blocking the run over.
     */
    private function itemHasAnyRateHistory(int $itemId): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `statutory_item_rate_history` WHERE statutory_item_id = :item_id AND deleted_at IS NULL");
        $stmt->execute([':item_id' => $itemId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function fetchBrackets(int $rateHistoryId): array {
        $stmt = $this->db->prepare("SELECT min_amount, max_amount, rate FROM `statutory_item_brackets`
            WHERE statutory_item_rate_history_id = :id ORDER BY bracket_order ASC");
        $stmt->execute([':id' => $rateHistoryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array{0:float,1:float,2:float,3:array} [employee_amount, employer_amount, effective_base, formula] */
    private function computeFlatRate(array $item, array $rateRow, float $base): array {
        $employeeRate = $item['employee_rate_override'] ?? $rateRow['employee_rate'];
        $employerRate = $item['employer_rate_override'] ?? $rateRow['employer_rate'];

        $effBase = $base;
        if ($rateRow['min_base_amount'] !== null) {
            $effBase = max($effBase, (float)$rateRow['min_base_amount']);
        }
        if ($rateRow['max_base_amount'] !== null) {
            $effBase = min($effBase, (float)$rateRow['max_base_amount']);
        }

        $employeeAmount = 0.0;
        $employerAmount = 0.0;
        $employeeRawAmount = null;
        $employeeCapped = false;
        if ($item['is_employee_applicable'] && $employeeRate !== null) {
            $employeeRawAmount = $this->applyRounding($effBase * (float)$employeeRate / 100, $item);
            $employeeAmount = $employeeRawAmount;
            if ($rateRow['max_employee_contribution'] !== null) {
                $employeeAmount = min($employeeAmount, (float)$rateRow['max_employee_contribution']);
                $employeeCapped = $employeeAmount < $employeeRawAmount;
            }
        }
        if ($item['is_employer_applicable'] && $employerRate !== null) {
            $employerAmount = $this->applyRounding($effBase * (float)$employerRate / 100, $item);
            if ($rateRow['max_employer_contribution'] !== null) {
                $employerAmount = min($employerAmount, (float)$rateRow['max_employer_contribution']);
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
            'effective_base' => $effBase, 'employee_rate' => $employeeRate !== null ? (float)$employeeRate : null,
            'employee_raw_amount' => $employeeRawAmount, 'max_employee_contribution' => $rateRow['max_employee_contribution'] !== null ? (float)$rateRow['max_employee_contribution'] : null,
            'employee_capped' => $employeeCapped, 'result' => $employeeAmount,
        ];
        return [$employeeAmount, $employerAmount, $effBase, $formula];
    }

    /** @return array{0:float,1:float} [employee_amount, employer_amount] */
    private function computeFixedAmount(array $item, array $rateRow): array {
        $employeeAmount = $item['employee_amount_override'] ?? $rateRow['employee_amount'];
        $employerAmount = $item['employer_amount_override'] ?? $rateRow['employer_amount'];
        $employeeAmount = ($item['is_employee_applicable'] && $employeeAmount !== null) ? $this->applyRounding((float)$employeeAmount, $item) : 0.0;
        $employerAmount = ($item['is_employer_applicable'] && $employerAmount !== null) ? $this->applyRounding((float)$employerAmount, $item) : 0.0;
        return [$employeeAmount, $employerAmount];
    }

    private function computeProgressiveBracket(array $brackets, float $base, array $item): float {
        $tax = 0.0;
        foreach ($brackets as $b) {
            $min = (float)$b['min_amount'];
            $max = $b['max_amount'] !== null ? (float)$b['max_amount'] : null;
            if ($base <= $min) {
                break;
            }
            $upper = $max !== null ? min($base, $max) : $base;
            $taxable = max(0.0, $upper - $min);
            $tax += $taxable * (float)$b['rate'] / 100;
            if ($max !== null && $base <= $max) {
                break;
            }
        }
        return $this->applyRounding($tax, $item);
    }

    /**
     * formula_config JSON shape (per side, both optional):
     * {"employee": {"base_rate":1.45,"extra_rate":0.9,"extra_threshold":200000}, "employer": {"base_rate":1.45}}
     * @return array{0:float,1:float,2:?string} [employee_amount, employer_amount, note]
     */
    private function computeFormula(array $item, ?array $rateRow, float $base, string $noRateNote = 'no_rate_configured'): array {
        if (!$rateRow || empty($rateRow['formula_config'])) {
            return [0.0, 0.0, $noRateNote];
        }
        $config = json_decode($rateRow['formula_config'], true);
        if (!is_array($config)) {
            return [0.0, 0.0, 'invalid_formula_config'];
        }
        $employeeAmount = $item['is_employee_applicable'] ? $this->applyThresholdFormula($config['employee'] ?? null, $base, $item) : 0.0;
        $employerAmount = $item['is_employer_applicable'] ? $this->applyThresholdFormula($config['employer'] ?? null, $base, $item) : 0.0;
        return [$employeeAmount, $employerAmount, null];
    }

    private function applyThresholdFormula(?array $sideConfig, float $base, array $item): float {
        if ($sideConfig === null || !isset($sideConfig['base_rate'])) {
            return 0.0;
        }
        $amount = $base * (float)$sideConfig['base_rate'] / 100;
        if (isset($sideConfig['extra_rate'], $sideConfig['extra_threshold'])) {
            $extraBase = max(0.0, $base - (float)$sideConfig['extra_threshold']);
            $amount += $extraBase * (float)$sideConfig['extra_rate'] / 100;
        }
        return $this->applyRounding($amount, $item);
    }
}
