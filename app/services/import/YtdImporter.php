<?php
declare(strict_types=1);
require_once __DIR__ . '/EmployeeMatcher.php';
require_once __DIR__ . '/../ThPitCalculator.php';
require_once __DIR__ . '/../../models/EmployeeYtdOpeningBalanceModel.php';

/**
 * Opening YTD balance file import (entity type `ytd_opening`): one row per employee for a mid-year go-live.
 * Employees are matched by employee code then National ID and must already exist; a row replaces any earlier
 * opening balance for the same employee and tax year. Rejected when the system already holds approved/paid/locked
 * runs for that employee before as_of_date, since those would be counted twice.
 */
class YtdImporter {
    private PDO $db;
    private EmployeeMatcher $matcher;
    private EmployeeYtdOpeningBalanceModel $balances;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->matcher = new EmployeeMatcher($this->db);
        $this->balances = new EmployeeYtdOpeningBalanceModel($this->db);
    }

    public function entityType(): string {
        return 'ytd_opening';
    }

    public function templateColumns(): array {
        return [
            'employee_no' => 'Employee Code', 'id_card_no' => 'National ID',
            'as_of_date' => 'As-of Date (YYYY-MM-DD, first day calculated here)',
            'periods_paid' => 'Periods Paid (this tax year)',
            'ytd_taxable_gross' => 'YTD Taxable Income', 'ytd_pit_withheld' => 'YTD Tax Withheld',
            'ytd_sso_employee' => 'YTD Social Security (optional)', 'ytd_pvd_employee' => 'YTD Provident Fund (optional)',
        ];
    }

    public function importRow(int $compId, array $row, int $batchId, ?int $triggeredBy): array {
        $v = [];
        foreach (array_keys($this->templateColumns()) as $key) {
            $cell = $row[$key] ?? null;
            $v[$key] = $cell === null ? '' : trim((string)$cell);
        }
        if ($v['employee_no'] === '' && $v['id_card_no'] === '') {
            throw new InvalidArgumentException('Employee Code or National ID is required.');
        }
        $asOf = $v['as_of_date'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) || !checkdate((int)substr($asOf, 5, 2), (int)substr($asOf, 8, 2), (int)substr($asOf, 0, 4))) {
            throw new InvalidArgumentException('as_of_date must be a valid date in YYYY-MM-DD format.');
        }
        $taxYear = (int)substr($asOf, 0, 4);
        if ($v['periods_paid'] === '' || !ctype_digit($v['periods_paid']) || (int)$v['periods_paid'] > 366) {
            throw new InvalidArgumentException('periods_paid must be a whole number of periods already paid.');
        }
        $amounts = [
            'ytd_taxable_gross' => $this->amount($v['ytd_taxable_gross'], 'ytd_taxable_gross', true),
            'ytd_pit_withheld' => $this->amount($v['ytd_pit_withheld'], 'ytd_pit_withheld', true),
            'ytd_sso_employee' => $this->amount($v['ytd_sso_employee'], 'ytd_sso_employee', false),
            'ytd_pvd_employee' => $this->amount($v['ytd_pvd_employee'], 'ytd_pvd_employee', false),
        ];
        if ((int)$v['periods_paid'] === 0 && ($amounts['ytd_taxable_gross'] > 0 || $amounts['ytd_pit_withheld'] > 0)) {
            throw new InvalidArgumentException('periods_paid is 0 but income or tax is given.');
        }

        $employee = $this->matcher->find($compId, $v['employee_no'], $v['id_card_no']);
        if ($employee === null) {
            throw new InvalidArgumentException('Employee was not found -- import the employee first.');
        }
        $this->assertNoRunsBefore($compId, $employee['id'], $taxYear, $asOf);

        $action = $this->balances->replace($compId, $employee['id'], $taxYear, [
            'as_of_date' => $asOf, 'periods_paid' => (int)$v['periods_paid'],
            'ytd_taxable_gross' => $amounts['ytd_taxable_gross'], 'ytd_pit_withheld' => $amounts['ytd_pit_withheld'],
            'ytd_sso_employee' => $amounts['ytd_sso_employee'], 'ytd_pvd_employee' => $amounts['ytd_pvd_employee'],
        ], $batchId, $triggeredBy);
        return ['action' => $action];
    }

    /** @return ?float null only for an optional blank cell */
    private function amount(string $raw, string $field, bool $required): ?float {
        if ($raw === '') {
            if ($required) {
                throw new InvalidArgumentException("{$field} is required.");
            }
            return null;
        }
        $clean = str_replace([',', ' '], '', $raw);
        if (!is_numeric($clean) || (float)$clean < 0) {
            throw new InvalidArgumentException("{$field} must be a number of 0 or more.");
        }
        return round((float)$clean, 2);
    }

    private function assertNoRunsBefore(int $compId, int $employeeId, int $taxYear, string $asOf): void {
        $states = ThPitCalculator::YTD_STATES;
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM `payroll_run_details` d JOIN `payroll_runs` r ON r.id = d.run_id
            WHERE r.comp_id = ? AND r.deleted_at IS NULL AND r.state IN (" . implode(',', array_fill(0, count($states), '?')) . ")
                AND YEAR(r.period_start_date) = ? AND r.period_start_date < ? AND d.employee_id = ?");
        $stmt->execute(array_merge([$compId], $states, [$taxYear, $asOf, $employeeId]));
        if ((int)$stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('This employee already has approved or paid payroll runs before the as-of date -- an opening balance would count them twice.');
        }
    }
}
