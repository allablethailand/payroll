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
    $result['line_overrides_left'] = $overridesLeft;
    $result['line_override_history_left'] = $historyLeft;
    $result['fixture_still_present'] = !empty($result['run_still_present'])
        || (int)($result['run_detail_rows_left'] ?? 0) > 0
        || (int)($result['manual_lines_left'] ?? 0) > 0
        || $overridesLeft > 0
        || $historyLeft > 0
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
] + ($recurringFixture ?? []);
file_put_contents(STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE) . "\n");
echo json_encode($state, JSON_UNESCAPED_UNICODE) . "\n";
