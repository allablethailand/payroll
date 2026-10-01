<?php
/**
 * Verification for the opening YTD balance import (YtdImporter / employee_ytd_opening_balances) and its use by
 * ThPitCalculator::calculateActual(). Runs inside a transaction that is always rolled back.
 * Requires migration 2026-10-01_4_employee_ytd_opening_balances to be applied.
 *
 * Run with: php tests/ytd_opening_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/import/ImportService.php';
require_once __DIR__ . '/../app/services/ThPitCalculator.php';
require_once __DIR__ . '/../app/models/SyncBatchModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
function check(string $label, $actual, $expected): void {
    global $failures;
    if ($actual === $expected) {
        echo "PASS: {$label}\n";
    } else {
        $failures++;
        echo "FAIL: {$label} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    }
}
function throws(callable $fn): ?string {
    try { $fn(); } catch (InvalidArgumentException $e) { return $e->getMessage(); }
    return null;
}

try {
    $compId = (int)$pdo->query("SELECT id FROM companies ORDER BY id LIMIT 1")->fetchColumn();
    $batchId = (new SyncBatchModel($pdo))->start($compId, 'ytd_opening', 'import', 'manual', null);
    $pdo->prepare("INSERT INTO employees (comp_id, employee_no, id_card_no, id_card_no_hash, key_version, data_source) VALUES (:c, 'T-YTD-1', :enc, :h, :kv, 'import')")
        ->execute([':c' => $compId, ':enc' => EncryptionService::encrypt('5555555555555')['value'], ':h' => EncryptionService::hash('5555555555555'), ':kv' => EncryptionService::currentKeyVersion()]);
    $empId = (int)$pdo->lastInsertId();
    $imp = new YtdImporter($pdo);
    $balances = new EmployeeYtdOpeningBalanceModel($pdo);
    $row = ['employee_no' => 'T-YTD-1', 'as_of_date' => '2026-08-01', 'periods_paid' => '7', 'ytd_taxable_gross' => '210,000.00', 'ytd_pit_withheld' => '1000', 'ytd_sso_employee' => '', 'ytd_pvd_employee' => '4200'];

    // --- importer ---
    check('first row inserts', $imp->importRow($compId, $row, $batchId, 9)['action'], 'inserted');
    $saved = $balances->find($compId, $empId, 2026);
    check('stored with commas stripped', [$saved['periods_paid'], $saved['ytd_taxable_gross'], $saved['ytd_pit_withheld'], $saved['as_of_date']], [7, 210000.0, 1000.0, '2026-08-01']);
    check('optional blank stays NULL', $pdo->query("SELECT ytd_sso_employee FROM employee_ytd_opening_balances WHERE employee_id = {$empId}")->fetchColumn(), null);
    $again = $imp->importRow($compId, ['id_card_no' => '5555555555555', 'as_of_date' => '2026-09-01', 'periods_paid' => '8', 'ytd_taxable_gross' => '240000', 'ytd_pit_withheld' => '1500'], $batchId, 9);
    check('re-import by National ID replaces the whole row', [$again['action'], (int)$pdo->query("SELECT COUNT(*) FROM employee_ytd_opening_balances WHERE employee_id = {$empId}")->fetchColumn(),
        $balances->find($compId, $empId, 2026)['periods_paid']], ['updated', 1, 8]);
    check('replace clears optional columns not in the new file', $pdo->query("SELECT ytd_pvd_employee FROM employee_ytd_opening_balances WHERE employee_id = {$empId}")->fetchColumn(), null);
    $imp->importRow($compId, $row, $batchId, 9);

    check('unknown employee rejected', (bool)throws(fn() => $imp->importRow($compId, ['employee_no' => 'NOPE-YTD'] + $row, $batchId, null)), true);
    check('needs an identifier', (bool)throws(fn() => $imp->importRow($compId, ['employee_no' => ''] + $row, $batchId, null)), true);
    check('bad as_of_date rejected', (bool)throws(fn() => $imp->importRow($compId, ['as_of_date' => '01/08/2026'] + $row, $batchId, null)), true);
    check('non-integer periods rejected', (bool)throws(fn() => $imp->importRow($compId, ['periods_paid' => '7.5'] + $row, $batchId, null)), true);
    check('negative amount rejected', (bool)throws(fn() => $imp->importRow($compId, ['ytd_pit_withheld' => '-5'] + $row, $batchId, null)), true);
    check('missing required amount rejected', (bool)throws(fn() => $imp->importRow($compId, ['ytd_taxable_gross' => ''] + $row, $batchId, null)), true);
    check('0 periods with money rejected', (bool)throws(fn() => $imp->importRow($compId, ['periods_paid' => '0'] + $row, $batchId, null)), true);

    // an employee that already has an approved/paid/locked run before as_of_date must be refused
    $existing = $pdo->query("SELECT d.employee_id, r.comp_id, r.period_start_date FROM payroll_run_details d JOIN payroll_runs r ON r.id = d.run_id
        WHERE r.deleted_at IS NULL AND r.state IN ('approved','paid','locked') ORDER BY r.period_start_date LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $en = $pdo->query("SELECT employee_no FROM employees WHERE id = " . (int)$existing['employee_id'])->fetchColumn();
        $year = substr($existing['period_start_date'], 0, 4);
        check('existing run before as_of_date blocks the import', (bool)throws(fn() => $imp->importRow((int)$existing['comp_id'], ['employee_no' => (string)$en, 'as_of_date' => "{$year}-12-31"] + $row, $batchId, null)), true);
    } else {
        echo "SKIP: no approved/paid/locked run in this database for the double-count guard\n";
    }

    // --- ThPitCalculator ---
    $calc = new ThPitCalculator($pdo);
    $monthly = fn(string $start) => $calc->calculate($compId, $empId, 30000.0, 0.0, 0.0, 0.0, 12, 'actual', false, $start, '2026-08-31');
    $with = $monthly('2026-08-01');
    check('opening periods are counted (7 paid + this one)', $with['periods_elapsed'], 8);
    $annualEstimate = 210000 + 30000 + 4 * 30000;
    check('annual estimate starts from the opening income', $with['annual_taxable_income'], round($annualEstimate - 100000 - 60000, 2));
    check('tax already withheld is subtracted', $with['employee_amount'], max(0.0, round(($with['annual_tax'] / 12) * 8 - 1000.0, 2)));

    check('period before as_of_date ignores the opening', $monthly('2026-06-01')['periods_elapsed'], 1);
    $balances->replace($compId, $empId, 2026, ['as_of_date' => '2026-08-01', 'periods_paid' => 7, 'ytd_taxable_gross' => 210000, 'ytd_pit_withheld' => 1000, 'ytd_sso_employee' => null, 'ytd_pvd_employee' => null], null, null);
    check('opening for another year is ignored', $calc->calculate($compId, $empId, 30000.0, 0.0, 0.0, 0.0, 12, 'actual', false, '2027-08-01', '2027-08-31')['periods_elapsed'], 1);
    check('average method is unaffected', $calc->calculate($compId, $empId, 30000.0, 0.0, 0.0, 0.0, 12, 'average', false, '2026-08-01', '2026-08-31')['method'], 'average');

    $noOpening = (int)$pdo->query("SELECT id FROM employees WHERE comp_id = {$compId} AND id <> {$empId} AND deleted_at IS NULL LIMIT 1")->fetchColumn();
    check('an employee with no opening row behaves as before', $calc->calculate($compId, $noOpening, 30000.0, 0.0, 0.0, 0.0, 12, 'actual', false, '2026-08-01', '2026-08-31')['periods_elapsed'] >= 1, true);

    // --- through ImportService ---
    $svc = new ImportService($pdo);
    check('template exposes 8 columns', count($svc->templateColumns('ytd_opening')), 8);
    $before = (int)$pdo->query("SELECT COUNT(*) FROM employee_ytd_opening_balances")->fetchColumn();
    $prev = $svc->preview($compId, 'ytd_opening', [['employee_no' => 'T-YTD-1', 'as_of_date' => '2025-08-01', 'periods_paid' => '7', 'ytd_taxable_gross' => '1', 'ytd_pit_withheld' => '0']], null);
    check('preview reports success but writes nothing', [$prev['success'], (int)$pdo->query("SELECT COUNT(*) FROM employee_ytd_opening_balances")->fetchColumn()], [1, $before]);
} finally {
    $pdo->rollBack();
}

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
