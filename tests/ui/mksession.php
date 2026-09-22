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
 *   php tests/ui/mksession.php --with-sync        ...and make it a SYNC-based run (see below)
 *   php tests/ui/mksession.php --cleanup    delete what the last create made
 *
 * --with-sync gives the run its own throwaway `payroll_sync_processes` row plus one mapped
 * `payroll_sync_items` row per company-1 payroll participant EXCEPT two, deliberately left out so
 * the Detail page's "sync missing employees" banner/picker has a real, non-empty answer to show.
 * It exists for the employee-pulling round ONLY -- it is NOT part of the 11-script sequence, which
 * keeps using a plain (no-flag) session:
 *  - A sync-based run never reaches recalculate()'s `no_attendance_data_this_period` branch
 *    (PayrollRunModel.php:4131 -- it is the `elseif` to the sync branch), so that advisory, which
 *    EVERY row of a no-flag session carries, is absent from every row here. Left to disappear on
 *    its own rather than faked back in: it is what a real sync run genuinely looks like.
 *  - Every row's `data_source` is 'sync' instead of 'manual', for the same reason.
 * That first point is why --with-sync REFUSES to run together with --with-calc-errors: that
 * fixture's R5/R6/R7 are control rows whose whole contract is "whatever recalculate() wrote is
 * still there", and what recalculate() writes for them differs between the two run types.
 *
 * It deliberately does NOT hand the new process to PayrollRunModel::create(): create()'s own
 * sync branch (PayrollRunModel.php:2146) first runs MasterDataSyncOrchestrator::syncAllMasterData(),
 * i.e. a real HTTP pull from Origami across 9 master-data entity types, writing real master rows
 * and a sync_batches row each -- a fixture tool must not do that. The run is created exactly as it
 * always was, the one column create() would have set from the sync side (sync_process_id, see that
 * INSERT's own column list) is written straight after, and recalculate() is then called normally --
 * which is the whole of what create()'s sync branch contributes to the run's end state.
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
// Same idea for --with-sync's own process row: every sweep below is bounded by (COMP_ID, this
// prefix), never by an id read back from anywhere. `_` is escaped in the LIKE pattern so the
// prefix can only ever match itself.
const SYNC_PROCESS_PREFIX = 'UITEST_';
const SYNC_PROCESS_LIKE = 'UITEST\_%';
/** How many participants --with-sync leaves OUT of the payload, i.e. what the banner must report. */
const SYNC_MISSING_COUNT = 2;

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
 * Hard-deletes --with-sync's own process rows (its `payroll_sync_items` go with them, FK CASCADE;
 * a `payroll_runs` row still pointing at one is set back to NULL, FK SET NULL -- both verified
 * against information_schema, not assumed). Called by --cleanup always, and by create before it
 * inserts, so a session that died before its own cleanup cannot leave a process behind that the
 * next one would have to work around. Bounded by (COMP_ID, SYNC_PROCESS_PREFIX), which only this
 * tool ever writes -- a real Origami process can never match it.
 */
function sweepSyncFixture(PDO $pdo): int {
    $del = $pdo->prepare("DELETE FROM `payroll_sync_processes` WHERE comp_id = :comp_id AND process_no LIKE :pattern");
    $del->execute([':comp_id' => COMP_ID, ':pattern' => SYNC_PROCESS_LIKE]);
    return $del->rowCount();
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

    // After the run above, never before: delete() is what clears payroll_runs.sync_process_id
    // (PayrollRunModel.php:2813), so by the time the process row goes there is nothing left
    // pointing at it. The two "left" counters are read back AFTER the sweep, by the recorded id --
    // they answer "is this session's own process really gone", which the removed-count alone does
    // not (it also counts strays from sessions that died).
    $syncProcessId = (int)($state['sync_process_id'] ?? 0);
    $result['stray_sync_processes_removed'] = sweepSyncFixture($pdo);
    $syncProcessLeft = $syncProcessId > 0
        ? (int)$pdo->query("SELECT COUNT(*) FROM `payroll_sync_processes` WHERE id = " . $syncProcessId)->fetchColumn()
        : 0;
    $syncItemsLeft = $syncProcessId > 0
        ? (int)$pdo->query("SELECT COUNT(*) FROM `payroll_sync_items` WHERE process_id = " . $syncProcessId)->fetchColumn()
        : 0;
    $result['sync_process_left'] = $syncProcessLeft;
    $result['sync_items_left'] = $syncItemsLeft;

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
        || (int)($result['recurring_fixture_left'] ?? 0) > 0
        || $syncProcessLeft > 0
        || $syncItemsLeft > 0;

    unlink(STATE_FILE);
    echo json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    exit(empty($result['fixture_still_present']) && empty($result['session_still_present']) ? 0 : 1);
}

$withSync = in_array('--with-sync', array_slice($argv, 1), true);
// Refused here, before anything at all has been created, so a rejected combination cannot leave a
// half-built session behind. See this file's own docblock for why the two are incompatible: R5/R6/
// R7 are control rows asserting what recalculate() itself wrote, and a sync-based run writes
// something different there (no `no_attendance_data_this_period`) than the run that fixture was
// measured against.
if ($withSync && in_array('--with-calc-errors', array_slice($argv, 1), true)) {
    fwrite(STDERR, "--with-sync and --with-calc-errors cannot be combined: a sync-based run never writes "
        . "no_attendance_data_this_period, which R5/R6/R7 (the control rows, left exactly as recalculate() "
        . "wrote them) currently carry -- the fixture's own contract would silently stop holding. Nothing was created.\n");
    exit(1);
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

// --with-sync: turn the run just created into a sync-based one BEFORE the recalculate() below, so
// that single existing call is the one that takes recalculate()'s sync branch -- no second pass,
// and no window where the run exists with a process that nothing has read yet.
$syncFixture = null;
if ($withSync) {
    sweepSyncFixture($pdo);
    // Ordered by employee_no because that is the order k4b_close_batch4.js's own row rule reads
    // (see applyCalcErrorFixture()'s note on it) -- the employee that rule would reserve must not
    // be one of the two left out of the payload, or the rule lands somewhere else.
    $participants = $pdo->query("SELECT id, employee_no FROM `employees`
        WHERE comp_id = " . COMP_ID . " AND deleted_at IS NULL AND is_payroll_participant = 1
        ORDER BY employee_no ASC")->fetchAll(PDO::FETCH_ASSOC);
    $k4bReserved = null;
    foreach ($participants as $p) {
        if (!in_array((int)$p['id'], CALC_ERROR_OFF_LIMITS, true)) {
            $k4bReserved = (int)$p['id'];
            break;
        }
    }
    // 28/159/499 stay IN the payload on purpose: 28 is the row every other fixture here drives and
    // the one real calc error on this run, and 159/499 are k4b's OFF_LIMITS pair -- all three have
    // to be present for the rest of the session to mean what it always meant.
    $missingPool = [];
    foreach ($participants as $p) {
        $eid = (int)$p['id'];
        if (!in_array($eid, CALC_ERROR_OFF_LIMITS, true) && $eid !== $k4bReserved) {
            $missingPool[] = $eid;
        }
    }
    sort($missingPool);
    if (count($missingPool) < SYNC_MISSING_COUNT) {
        fwrite(STDERR, "--with-sync needs " . SYNC_MISSING_COUNT . " participants outside employees "
            . implode('/', CALC_ERROR_OFF_LIMITS) . " and k4b's own (" . var_export($k4bReserved, true)
            . "); this company has " . count($missingPool) . " -- nothing was set\n");
        exit(1);
    }
    $missingIds = array_slice($missingPool, 0, SYNC_MISSING_COUNT);
    $itemEmployeeIds = [];
    foreach ($participants as $p) {
        if (!in_array((int)$p['id'], $missingIds, true)) {
            $itemEmployeeIds[] = (int)$p['id'];
        }
    }

    // Minimal payload on purpose -- the only NOT NULL columns, nothing else (same shape
    // tests/payroll_run_test.php's own sync fixture uses). Every amount on this run still comes
    // from the `employees` master exactly as it did before: SyncPayResolver only ever ADDS
    // attendance-derived lines, and with no attendance columns set it produces none and flags
    // nothing. origami_process_id is UNIQUE and real ones here are 2-digit; this band cannot
    // collide with one, and the sweep above already removed any leftover of ours.
    $insProc = $pdo->prepare("INSERT INTO `payroll_sync_processes`
        (comp_id, origami_process_id, process_no, origami_comp_code, origami_comp_name, frequency_type, schema_version, raw_payload)
        VALUES (:comp_id, :origami_process_id, :process_no, 'UITEST', 'UI test (delete me)', 'monthly', 1, '{}')");
    $insProc->execute([
        ':comp_id' => COMP_ID,
        ':origami_process_id' => random_int(900000000, 999999999),
        ':process_no' => SYNC_PROCESS_PREFIX . strtoupper(bin2hex(random_bytes(6))),
    ]);
    $syncProcessId = (int)$pdo->lastInsertId();
    $insItem = $pdo->prepare("INSERT INTO `payroll_sync_items` (process_id, employee_id, payroll_code, mapping_status)
        VALUES (:process_id, :employee_id, :payroll_code, 'mapped')");
    foreach ($participants as $p) {
        if (in_array((int)$p['id'], $missingIds, true)) {
            continue;
        }
        $insItem->execute([':process_id' => $syncProcessId, ':employee_id' => (int)$p['id'], ':payroll_code' => (string)$p['employee_no']]);
    }
    // The one column create() itself would have written from the sync side -- see this file's own
    // docblock for why create() is not handed the process instead. Guarded by the same run_name
    // condition every other statement in this tool uses, so it can only reach our own run.
    $pdo->prepare("UPDATE `payroll_runs` SET sync_process_id = :pid
        WHERE id = :id AND comp_id = :comp_id AND run_name LIKE 'UI test run (delete me)%'")
        ->execute([':pid' => $syncProcessId, ':id' => $runId, ':comp_id' => COMP_ID]);
    $syncFixture = [
        'sync_process_id' => $syncProcessId,
        'sync_item_employee_ids' => $itemEmployeeIds,
        'sync_missing_employee_ids' => $missingIds,
    ];
}

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
] + ($syncFixture ?? []) + ($recurringFixture ?? []) + ($calcErrorFixture ?? []);
file_put_contents(STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE) . "\n");
echo json_encode($state, JSON_UNESCAPED_UNICODE) . "\n";
