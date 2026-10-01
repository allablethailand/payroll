<?php
declare(strict_types=1);

/** Opening YTD balances (employee_ytd_opening_balances) read by ThPitCalculator and written by YtdImporter. */
class EmployeeYtdOpeningBalanceModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** @return ?array{as_of_date:string, periods_paid:int, ytd_taxable_gross:float, ytd_pit_withheld:float}
     *  null when there is no row, or the migration has not been applied (the calculator then behaves exactly as before). */
    public function find(int $compId, int $employeeId, int $taxYear): ?array {
        try {
            $stmt = $this->db->prepare("SELECT as_of_date, periods_paid, ytd_taxable_gross, ytd_pit_withheld
                FROM `employee_ytd_opening_balances` WHERE comp_id = :c AND employee_id = :e AND tax_year = :y");
            $stmt->execute([':c' => $compId, ':e' => $employeeId, ':y' => $taxYear]);
        } catch (PDOException $e) {
            if ($e->getCode() === '42S02') {
                error_log('employee_ytd_opening_balances is missing -- apply migration 2026-10-01_4_employee_ytd_opening_balances');
                return null;
            }
            throw $e;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ['as_of_date' => (string)$row['as_of_date'], 'periods_paid' => (int)$row['periods_paid'],
            'ytd_taxable_gross' => (float)$row['ytd_taxable_gross'], 'ytd_pit_withheld' => (float)$row['ytd_pit_withheld']] : null;
    }

    /** Replaces the whole row for this employee and tax year. @param array $v as_of_date, periods_paid, ytd_taxable_gross, ytd_pit_withheld, ytd_sso_employee, ytd_pvd_employee */
    public function replace(int $compId, int $employeeId, int $taxYear, array $v, ?int $batchId, ?int $userId): string {
        $exists = $this->find($compId, $employeeId, $taxYear) !== null;
        $stmt = $this->db->prepare("INSERT INTO `employee_ytd_opening_balances`
                (comp_id, employee_id, tax_year, as_of_date, periods_paid, ytd_taxable_gross, ytd_pit_withheld, ytd_sso_employee, ytd_pvd_employee, data_source, sync_batch_id, created_by)
            VALUES (:c, :e, :y, :asof, :pp, :gross, :pit, :sso, :pvd, 'import', :b, :u)
            ON DUPLICATE KEY UPDATE as_of_date = VALUES(as_of_date), periods_paid = VALUES(periods_paid), ytd_taxable_gross = VALUES(ytd_taxable_gross),
                ytd_pit_withheld = VALUES(ytd_pit_withheld), ytd_sso_employee = VALUES(ytd_sso_employee), ytd_pvd_employee = VALUES(ytd_pvd_employee),
                data_source = 'import', sync_batch_id = VALUES(sync_batch_id), updated_by = VALUES(created_by)");
        $stmt->execute([':c' => $compId, ':e' => $employeeId, ':y' => $taxYear, ':asof' => $v['as_of_date'], ':pp' => $v['periods_paid'],
            ':gross' => $v['ytd_taxable_gross'], ':pit' => $v['ytd_pit_withheld'], ':sso' => $v['ytd_sso_employee'], ':pvd' => $v['ytd_pvd_employee'],
            ':b' => $batchId, ':u' => $userId]);
        return $exists ? 'updated' : 'inserted';
    }
}
