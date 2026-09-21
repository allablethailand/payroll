<?php
/**
 * Browser-test harness for the UI rounds: makes a throwaway payroll run to drive, and a local PHP
 * session so Playwright can open the app as a logged-in admin.
 *
 * It exists because this app has NO login form to drive: login is the one-way Origami SSO handshake
 * in auth/index.php, so a browser test cannot log itself in. See
 * docs/decisions/ui-test-session.md for what that means and what this is allowed to do.
 *
 * Usage:
 *   php tests/ui/mksession.php              create -- prints one line of JSON
 *   php tests/ui/mksession.php --with-recurring   ...and give that run a recurring deduction to drive
 *   php tests/ui/mksession.php --with-calc-errors ...and 7 rows with calc-error/warning/prorate cases
 *   php tests/ui/mksession.php --cleanup    delete what the last create made
 *
 * Guards (all 3, every run): CLI only, BASE_URL must be a loopback host, and the run it deletes
 * must be one it created itself (the id is read back from its own state file, never from argv).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("tests/ui/mksession.php is a CLI tool.\n");
}

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($ROOT)->load();
require_once $ROOT . '/config.php';
require_once $ROOT . '/app/core/Database.php';
spl_autoload_register(function ($class) use ($ROOT) {
    foreach (['app/models/', 'app/services/', 'app/core/', 'app/controllers/'] as $p) {
        $f = $ROOT . '/' . $p . $class . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});

// The dev check is the URL this install answers on, not APP_ENV: this repo's own .env ships
// APP_ENV=production on a developer machine, so trusting that flag would be trusting nothing.
$host = strtolower((string)parse_url(BASE_URL, PHP_URL_HOST));
if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
    fwrite(STDERR, "refusing to run: BASE_URL host is '{$host}', not a loopback address.\n");
    exit(1);
}

const STATE_FILE = __DIR__ . '/.last-session.json';
const COMP_ID = 1;
const ADMIN_EMPLOYEE_ID = 28;
// The catalog item --with-recurring creates. Every sweep below is bounded by (COMP_ID, this code)
// rather than by an id read back from anywhere, so it can only ever reach rows this tool wrote.
const RECURRING_ITEM_CODE = 'TINYL6TMP';

/**
 * Hard-deletes the --with-recurring fixture: the recurring deduction rows first, then the catalog
 * item they point at (that order is the FK's, not a preference). HARD, unlike the run itself: a
 * soft-deleted catalog row keeps its item_code reserved -- this app's deleted_at-composite-unique
 * caveat -- so a soft delete here would make the NEXT --with-recurring fail its duplicate check.
 * Called by --cleanup always, and by create before it inserts, so a session that died before its
 * cleanup cannot block the next one.
 */
function sweepRecurringFixture(PDO $pdo): array {
    $stmtIds = $pdo->prepare("SELECT id FROM `payroll_earning_deduction_types` WHERE comp_id = :comp_id AND item_code = :code");
    $stmtIds->execute([':comp_id' => COMP_ID, ':code' => RECURRING_ITEM_CODE]);
    $typeIds = array_map('intval', $stmtIds->fetchAll(PDO::FETCH_COLUMN));
    $out = ['recurring_deductions_removed' => 0, 'deduction_types_removed' => 0, 'recurring_fixture_left' => 0];
    if (!$typeIds) {
        return $out;
    }
    $in = implode(',', $typeIds);
    // The fixture's own audit trail goes while its ids still exist -- otherwise every
    // --with-recurring session leaves one more orphan audit_logs row in the shared dev DB, exactly
    // the way the line-override history rows above used to.
    $pdo->prepare("DELETE al FROM `audit_logs` al
        JOIN `employee_recurring_deductions` erd ON erd.id = al.record_id
        WHERE al.table_name = 'employee_recurring_deductions' AND erd.ped_type_id IN ({$in})")->execute();
    $delRec = $pdo->prepare("DELETE FROM `employee_recurring_deductions` WHERE ped_type_id IN ({$in})");
    $delRec->execute();
    $delType = $pdo->prepare("DELETE FROM `payroll_earning_deduction_types` WHERE id IN ({$in})");
    $delType->execute();
    $out['recurring_deductions_removed'] = $delRec->rowCount();
    $out['deduction_types_removed'] = $delType->rowCount();
    $stmtIds->execute([':comp_id' => COMP_ID, ':code' => RECURRING_ITEM_CODE]);
    $out['recurring_fixture_left'] = count($stmtIds->fetchAll(PDO::FETCH_COLUMN));
    return $out;
}

/**
 * Employees --with-calc-errors must never write to: 28 is this fixture's own driven row (every
 * manual line/override/exemption below is on it), and 159/499 are k4b_close_batch4.js's own
 * OFF_LIMITS pair, off limits on whatever run they appear.
 */
const CALC_ERROR_OFF_LIMITS = [ADMIN_EMPLOYEE_ID, 159, 499];
/**
 * The 7 cases --with-calc-errors sets, in the order they are handed rows. A key that is ABSENT
 * leaves that column exactly as recalculate() wrote it -- which is the whole point of R5/R6/R7:
 * a control row is only worth having if nothing touched it. `calc_errors => []` means a real SQL
 * NULL (R4: prorate 0 with nothing to explain it), not an empty string.
 *
 * Every code here is a real one, and each is written in the exact shape its own push site produces:
 * `missing_ot_rate_weekday` (SyncPayResolver.php:438 x its own OT_SCOPE_COLUMNS, :77-81),
 * `working_days_fallback_with_attendance_deduction:late` (SyncPayResolver.php:821 x
 * RULE_DRIVEN_ITEM_DEFS, :216-220). `zz_fixture_unknown` is the one deliberate fake: nothing emits
 * it, which is what makes it the test of detail.js's calcErrorMessageRd() fallback (`return code`).
 * Advisory-vs-blocking is never restated here -- PayrollRunModel::isAdvisoryCalcError() decides it,
 * and calc_status below follows that same invariant (blocking >= 1 => 'error').
 */
const CALC_ERROR_FIXTURE_SPEC = [
    'R1' => ['calc_status' => 'error', 'calc_errors' => ['no_manual_lines', 'profile_incomplete']],
    'R2' => ['calc_status' => 'error', 'calc_errors' => [
        'no_rate_configured:TH_SSO', 'missing_base_salary', 'missing_ot_rate_weekday',
        'transfer_payee_not_in_run:LOAN_REPAY', 'ot_not_calculated_ineligible', 'zz_fixture_unknown',
    ]],
    'R3' => ['calc_status' => 'calculated', 'calc_errors' => [
        'mixed_payment_lines_mismatch', 'working_days_fallback_with_attendance_deduction:late',
    ]],
    'R4' => ['calc_status' => 'calculated', 'calc_errors' => [], 'prorate_days' => 0],
    'R5' => [],
    'R6' => ['prorate_days' => 0],
    'R7' => ['prorate_days' => 15],
];

/**
 * --with-calc-errors: written with UPDATE straight onto the rows recalculate() just produced,
 * because the engine cannot produce this set on this machine at all -- every code above needs input
 * data company 1 does not have (no sync process on this run, no OT hours, no mixed-payment lines),
 * and prorate only ever fills in for a mid-period joiner/leaver. Nothing overwrites these values
 * afterwards: the run is created with auto_recalculate=0, and harness.js blocks
 * api/payroll-run.recalculate for every round that did not explicitly ask for it.
 *
 * Rows are chosen at RUN TIME, never hard-coded: employee ids are a property of whatever the dev DB
 * holds today, and the round that reads this fixture is handed the ids through .last-session.json.
 */
function applyCalcErrorFixture(PDO $pdo, PayrollRunModel $model, int $runId): array {
    // The same list, in the same order, the Employee Breakdown table itself renders (employee_no
    // ASC) -- so "the row k4b picks" below is decided by exactly what k4b will see.
    $rows = $model->getDetails($runId, COMP_ID);
    // k4b_close_batch4.js:481-489 picks its own second row at runtime: first row that is not one of
    // OFF_LIMITS and has no adjustments yet. Re-derived here rather than hard-coded as an id,
    // because the id that rule lands on is a property of the DB, not of either script.
    $reserved = null;
    foreach ($rows as $r) {
        if (!in_array((int)$r['employee_id'], CALC_ERROR_OFF_LIMITS, true) && (int)$r['adjustment_count'] === 0) {
            $reserved = (int)$r['employee_id'];
            break;
        }
    }
    $pool = array_values(array_filter($rows, static fn($r) => !in_array((int)$r['employee_id'], CALC_ERROR_OFF_LIMITS, true)
        && (int)$r['employee_id'] !== $reserved));
    usort($pool, static fn($a, $b) => (int)$a['employee_id'] <=> (int)$b['employee_id']);
    $need = count(CALC_ERROR_FIXTURE_SPEC);
    // All 7 or none: half a set is worse than no set, because a round would still find its key in
    // .last-session.json and report the missing cases as failures of the page.
    if (count($pool) < $need) {
        fwrite(STDERR, "--with-calc-errors needs {$need} rows outside employees "
            . implode('/', CALC_ERROR_OFF_LIMITS) . " and k4b's own (" . var_export($reserved, true)
            . "); this run has " . count($pool) . " -- nothing was set\n");
        exit(1);
    }
    $baselineErrorCount = count(array_filter($rows, static fn($r) => $r['calc_status'] === 'error'));
    // The company's own configured divisor, read fresh -- recalculate() itself divides by this
    // (PayrollRunModel.php:2847-2849), so a prorate fixture that hard-coded 30 would stop matching
    // the real page the day someone changes it on Company Profile.
    $divisor = (int)($pdo->query("SELECT prorate_divisor_days FROM `companies` WHERE id = " . COMP_ID)->fetchColumn() ?: 30);

    $fixture = [];
    $ids = [];
    $i = 0;
    foreach (CALC_ERROR_FIXTURE_SPEC as $label => $want) {
        $row = $pool[$i++];
        $ids[$label] = (int)$row['id'];
        $fixture[$label] = ['employee_id' => (int)$row['employee_id'], 'employee_no' => (string)$row['employee_no']];
        $set = [];
        $params = [':id' => (int)$row['id']];
        if (array_key_exists('calc_status', $want)) {
            $set[] = 'calc_status = :calc_status';
            $params[':calc_status'] = $want['calc_status'];
        }
        if (array_key_exists('calc_errors', $want)) {
            $set[] = 'calc_errors = :calc_errors';
            // The exact shape recalculate() stores, so splitCalcErrors() has nothing special to do.
            $params[':calc_errors'] = $want['calc_errors'] ? implode(', ', $want['calc_errors']) : null;
        }
        if (array_key_exists('prorate_days', $want)) {
            $set[] = 'prorate_days = :prorate_days';
            $set[] = 'prorate_total_days = :prorate_total_days';
            $params[':prorate_days'] = $want['prorate_days'];
            $params[':prorate_total_days'] = $divisor;
        }
        if ($set) {
            $pdo->prepare("UPDATE `payroll_run_details` SET " . implode(', ', $set) . " WHERE id = :id")->execute($params);
        }
    }
    // Reported back from the DB, not from the spec: R5/R6/R7 keep columns nobody here wrote, and a
    // round comparing against what this file MEANT to set would never notice if a write was lost.
    $read = $pdo->prepare("SELECT calc_status, calc_errors, prorate_days, prorate_total_days FROM `payroll_run_details` WHERE id = :id");
    foreach ($ids as $label => $detailId) {
        $read->execute([':id' => $detailId]);
        $now = $read->fetch(PDO::FETCH_ASSOC) ?: [];
        $fixture[$label] += [
            'calc_status'        => (string)($now['calc_status'] ?? ''),
            'calc_errors'        => $now['calc_errors'] !== null ? (string)$now['calc_errors'] : null,
            'prorate_days'       => $now['prorate_days'] !== null ? (int)$now['prorate_days'] : null,
            'prorate_total_days' => $now['prorate_total_days'] !== null ? (int)$now['prorate_total_days'] : null,
        ];
    }
    // The run-level flag behind Run Detail's own banner (payroll/detail.js:1416). Idempotent: this
    // fixture's employee 28 already errors, so it is normally 1 before this line ever runs -- but
    // the banner must not depend on that staying true. The List page's red pill needs nothing here:
    // error_employee_count is counted from the rows above on read (PayrollRunModel.php:152).
    $pdo->prepare("UPDATE `payroll_runs` SET has_validation_errors = 1 WHERE id = :id")->execute([':id' => $runId]);
    return $fixture + ['k4b_reserved' => $reserved, 'baseline_error_count' => $baselineErrorCount];
}

$pdo = Database::getInstance()->pdo;
$model = new PayrollRunModel();

if (in_array('--cleanup', array_slice($argv, 1), true)) {
    if (!is_file(STATE_FILE)) {
        fwrite(STDERR, "nothing to clean up: " . STATE_FILE . " does not exist.\n");
        exit(1);
    }
    $state = json_decode((string)file_get_contents(STATE_FILE), true) ?: [];
    $runId = (int)($state['run_id'] ?? 0);
    $sid = (string)($state['session_id'] ?? '');
    $result = ['run_id' => $runId, 'session_id' => $sid];

    // Only ever a run this tool created: the id comes from its own state file, and the row must
    // still carry the name it was created with.
    $name = $runId > 0
        ? (string)$pdo->query("SELECT run_name FROM `payroll_runs` WHERE id = " . $runId)->fetchColumn()
        : '';
    if ($runId > 0 && strpos($name, 'UI test run (delete me)') !== false) {
        $del = $model->delete($runId, COMP_ID, ADMIN_EMPLOYEE_ID, true);
        $result['run_deleted'] = !empty($del['status']);
        // payroll_runs is soft-deleted (status='deleted' + deleted_at, this app's own convention for
        // payroll data) -- "gone" is that status, not a missing row. Its detail rows DO go for real.
        $after = $pdo->query("SELECT status FROM `payroll_runs` WHERE id = " . $runId)->fetchColumn();
        $result['run_status_after'] = $after === false ? '(row gone)' : (string)$after;
        $result['run_still_present'] = !in_array($result['run_status_after'], ['deleted', '(row gone)'], true);
        $result['run_detail_rows_left'] = (int)$pdo->query("SELECT COUNT(*) FROM `payroll_run_details` WHERE run_id = " . $runId)->fetchColumn();
        // 2026-09-17: the run is SOFT-deleted, and `payroll_run_manual_lines` is not one of the
        // tables delete() clears -- so the manual line this tool creates used to outlive every
        // cleanup, one row per session, forever. They are this tool's own rows in this tool's own
        // run, so they go here. Same guard as the run itself: only rows of runs that are really
        // this tool's and really deleted.
        $pdo->prepare("DELETE ml FROM `payroll_run_manual_lines` ml
            JOIN `payroll_runs` r ON r.id = ml.run_id
            WHERE ml.run_id = :run_id AND r.comp_id = :comp_id
              AND r.run_name LIKE 'UI test run (delete me)%' AND r.status = 'deleted'")
            ->execute([':run_id' => $runId, ':comp_id' => COMP_ID]);
        $result['manual_lines_left'] = (int)$pdo->query("SELECT COUNT(*) FROM `payroll_run_manual_lines` WHERE run_id = " . $runId)->fetchColumn();
        // Rows this same tool left behind in ITS OWN earlier runs, before the delete above existed.
        // Bounded by the same 3 conditions, so it can never reach a run this tool did not create.
        $strays = $pdo->prepare("DELETE ml FROM `payroll_run_manual_lines` ml
            JOIN `payroll_runs` r ON r.id = ml.run_id
            WHERE r.comp_id = :comp_id AND r.run_name LIKE 'UI test run (delete me)%' AND r.status = 'deleted'");
        $strays->execute([':comp_id' => COMP_ID]);
        $result['stray_manual_lines_removed'] = $strays->rowCount();

        // 2026-09-18, tiny-L4: the override row this tool creates next to the manual line, and the
        // history row that override wrote, outlive delete() for exactly the same reason the manual
        // line did -- neither table is one it clears, and the run is only SOFT-deleted. Same 3 guards
        // as above, so neither statement can ever reach a run this tool did not create, and the
        // current run plus this tool's own earlier ones are swept in one pass each.
        foreach ([
            'line_overrides' => 'payroll_run_line_overrides',
            'line_override_history' => 'payroll_run_line_override_history',
            // 2026-09-19, H-ui: the tri-state answer this tool now writes, for the same reason.
            'employee_exemptions' => 'payroll_run_employee_exemptions',
        ] as $label => $table) {
            $sweep = $pdo->prepare("DELETE t FROM `{$table}` t
                JOIN `payroll_runs` r ON r.id = t.run_id
                WHERE r.comp_id = :comp_id AND r.run_name LIKE 'UI test run (delete me)%' AND r.status = 'deleted'");
            $sweep->execute([':comp_id' => COMP_ID]);
            $result[$label . '_removed'] = $sweep->rowCount();
        }
    } else {
        $result['run_deleted'] = false;
        $result['run_skipped_reason'] = $runId > 0 ? "run {$runId} is not one of ours (name: '{$name}')" : 'no run id recorded';
    }

    // Independent of the run above: the fixture is found by its own item_code, so it is swept even
    // when the recorded run turned out not to be ours, and even when the session that made it never
    // reached its own cleanup.
    $result += sweepRecurringFixture($pdo);

    // The session file, by the id this tool generated -- it never reads or lists anyone else's.
    $sessionFile = rtrim((string)session_save_path(), '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    $result['session_deleted'] = ($sid !== '' && is_file($sessionFile)) ? unlink($sessionFile) : false;
    $result['session_still_present'] = ($sid !== '') && is_file($sessionFile);

    // 2026-09-18, tiny-L4: ONE roll-up of "is any row this tool created still in the DB". The
    // per-table counters above each answer only for their own table, and having to read 4 numbers to
    // decide one thing is exactly how the override row below went unnoticed for as long as it did.
    // This is the number a round reports, and the one the exit code is taken from.
    $overridesLeft = $runId > 0
        ? (int)$pdo->query("SELECT COUNT(*) FROM `payroll_run_line_overrides` WHERE run_id = " . $runId)->fetchColumn()
        : 0;
    $historyLeft = $runId > 0
        ? (int)$pdo->query("SELECT COUNT(*) FROM `payroll_run_line_override_history` WHERE run_id = " . $runId)->fetchColumn()
        : 0;
    $exemptionsLeft = $runId > 0
        ? (int)$pdo->query("SELECT COUNT(*) FROM `payroll_run_employee_exemptions` WHERE run_id = " . $runId)->fetchColumn()
        : 0;
    $result['line_overrides_left'] = $overridesLeft;
    $result['line_override_history_left'] = $historyLeft;
    $result['employee_exemptions_left'] = $exemptionsLeft;
    $result['fixture_still_present'] = !empty($result['run_still_present'])
        || (int)($result['run_detail_rows_left'] ?? 0) > 0
        || (int)($result['manual_lines_left'] ?? 0) > 0
        || $overridesLeft > 0
        || $historyLeft > 0
        || $exemptionsLeft > 0
        || (int)($result['recurring_fixture_left'] ?? 0) > 0;

    unlink(STATE_FILE);
    echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    exit(empty($result['fixture_still_present']) && empty($result['session_still_present']) ? 0 : 1);
}

// A run with NO cycle_id is an off-cycle run, and recalculate() then only pulls in employees
// somebody joined by hand -- i.e. an empty run, nothing to drive. The company's own active cycle is
// what makes it a normal run that picks up its employees by employment-date range.
$cycleId = (int)$pdo->query("SELECT id FROM `payroll_cycles` WHERE comp_id = " . COMP_ID . " AND status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
if (!$cycleId) {
    fwrite(STDERR, "no active payroll cycle for company " . COMP_ID . " -- nothing to drive\n");
    exit(1);
}
$res = $model->create(COMP_ID, [
    'run_name'          => 'UI test run (delete me)',
    'cycle_id'          => $cycleId,
    'payment_date'      => date('Y-m-d'),
    'period_start_date' => date('Y-m-01'),
    'period_end_date'   => date('Y-m-t'),
    'run_purpose'       => 'payroll',
], ADMIN_EMPLOYEE_ID, true);
if (empty($res['status'])) {
    fwrite(STDERR, 'create failed: ' . ($res['message'] ?? '?') . "\n");
    exit(1);
}
$runId = (int)$res['id'];
// Recorded the moment the run exists, before anything else can fail: otherwise a failure below
// leaves a run behind that --cleanup has no way to find.
file_put_contents(STATE_FILE, json_encode(['run_id' => $runId], JSON_UNESCAPED_UNICODE) . "\n");
$model->recalculate($runId, COMP_ID, ADMIN_EMPLOYEE_ID, true);

$employeeId = (int)$pdo->query("SELECT employee_id FROM `payroll_run_details` WHERE run_id = {$runId} ORDER BY employee_id LIMIT 1")->fetchColumn();
if (!$employeeId) {
    fwrite(STDERR, "the new run has no employees -- nothing to drive\n");
    exit(1);
}
// --with-recurring: a recurring deduction on this run's own employee, so a round can drive the
// recurring line's form (amount, then destination) against a real line instead of a run that has
// none. Its own catalog item, not one of the company's -- see sweepRecurringFixture().
if (in_array('--with-recurring', array_slice($argv, 1), true)) {
    $bankAccountId = (int)$pdo->query("SELECT id FROM `bank_accounts` WHERE comp_id = " . COMP_ID . " AND status = 'active' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    if (!$bankAccountId) {
        fwrite(STDERR, "--with-recurring needs one active bank account for company " . COMP_ID . " (payee_type = company)\n");
        exit(1);
    }
    sweepRecurringFixture($pdo);
    $typeRes = (new PayrollEarningDeductionTypeModel())->save(COMP_ID, [
        'item_code'            => RECURRING_ITEM_CODE,
        'item_name_th'         => 'รายการหักทดสอบ UI (ลบได้)',
        'item_name_en'         => 'UI test deduction (delete me)',
        'item_type'            => 'deduction',
        'calculation_method'   => 'fixed_amount',
        'fixed_amount'         => 500,
        'tax_deduction_impact' => 'after_tax',
    ], ADMIN_EMPLOYEE_ID);
    if (empty($typeRes['status'])) {
        fwrite(STDERR, 'deduction type create failed: ' . ($typeRes['message'] ?? '?') . "\n");
        exit(1);
    }
    $recRes = (new EmployeeRecurringDeductionModel())->save($employeeId, COMP_ID, [
        'ped_type_id'     => (int)$typeRes['id'],
        'amount'          => 500,
        'effective_date'  => date('Y-m-01'),
        'payee_type'      => 'company',
        'bank_account_id' => $bankAccountId,
    ], ADMIN_EMPLOYEE_ID);
    if (empty($recRes['status'])) {
        fwrite(STDERR, 'recurring deduction create failed: ' . ($recRes['message'] ?? '?') . "\n");
        exit(1);
    }
    $recurringFixture = ['ped_type_id' => (int)$typeRes['id'], 'recurring_deduction_id' => (int)$recRes['id']];
    // recalculate() is what turns an employee_recurring_deductions row into a line of this run
    // (PayrollRunModel reads activeForPeriod() there), and it already ran above, before this row
    // existed. Without this second pass the fixture is in the DB and absent from the screen.
    $model->recalculate($runId, COMP_ID, ADMIN_EMPLOYEE_ID, true);
}

// One hand-added line and one overridden line, so the row's count badge is non-zero and
// "คืนค่าระบบทั้งหมด" has a row to act on.
$model->addManualLine($runId, COMP_ID, $employeeId, null, 1234.50, ADMIN_EMPLOYEE_ID, true, null, 'UI test bonus', 'earning');
$model->lineOverrideSave($runId, COMP_ID, $employeeId, '__base_salary__', 'override_amount', 28500.00, null, ADMIN_EMPLOYEE_ID, true);
// 2026-09-19, H-ui: and one tri-state answer, which is the THIRD kind of edit the history table can
// show and the only one this fixture never produced -- so TH_PIT's own history had nothing in it to
// measure against. SSO is left on 'inherit' on purpose: the pair is written together, and a row that
// was NOT answered is as much a case as one that was.
$model->saveEmployeeExemption($runId, COMP_ID, $employeeId, 'no', 'inherit', 'UI test exemption', ADMIN_EMPLOYEE_ID, true);

// Last, on purpose: the rows it reserves are decided from adjustment_count, which the 3 writes
// above are what set.
if (in_array('--with-calc-errors', array_slice($argv, 1), true)) {
    $calcErrorFixture = ['calc_error_fixture' => applyCalcErrorFixture($pdo, $model, $runId)];
}

// A real session file, written where this install's own PHP will read it back.
session_id('uitest' . bin2hex(random_bytes(8)));
session_start();
$_SESSION['user'] = ['employee_id' => ADMIN_EMPLOYEE_ID, 'company_id' => COMP_ID, 'role' => 'admin', 'ui_theme' => null];
$sid = session_id();
session_write_close();

$state = [
    'run_id'      => $runId,
    'token'       => IdCodec::encode($runId),
    'employee_id' => $employeeId,
    'session_id'  => $sid,
] + ($recurringFixture ?? []) + ($calcErrorFixture ?? []);
file_put_contents(STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE) . "\n");
echo json_encode($state, JSON_UNESCAPED_UNICODE) . "\n";
