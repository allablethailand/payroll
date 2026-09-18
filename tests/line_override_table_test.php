<?php
/**
 * 2026-09-16, Adjustments modal batch 3/4 -- the "ปรับตัวเลข" tab became ONE table whose grouping
 * (Base Salary / Income / Deductions / Statutory / Other) is driven entirely by the `item_type`
 * field `PayrollRunModel::syncDeductionLinesForEmployee()` now returns per row.
 *
 * It is a READ-ONLY, derived field -- nothing stores it: for rows that came out of a breakdown it
 * IS which breakdown column they came from (only that method knows), and for a row that an
 * 'exclude' override dropped out of every breakdown it is looked up in the company's own catalog,
 * falling back to 'other' for a code with no catalog row at all. That derivation is what this test
 * locks: the table would silently collapse into one unlabelled block (or drop rows into "Other") if
 * any of it regressed.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Runs against the real dev DB inside a transaction that is always rolled back.
 * Run with: php tests/line_override_table_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
foreach (glob(__DIR__ . '/../app/services/*.php') as $serviceFile) {
    require_once $serviceFile;
}
foreach (glob(__DIR__ . '/../app/models/*.php') as $modelFile) {
    require_once $modelFile;
}

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    if ($actual === $expected) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }

$pdo = Database::getInstance()->pdo;
$model = new PayrollRunModel();
$pdo->beginTransaction();
try {
    $target = $pdo->query("SELECT d.run_id, d.employee_id, r.comp_id
        FROM `payroll_run_details` d
        JOIN `payroll_runs` r ON r.id = d.run_id
        WHERE r.deleted_at IS NULL
        ORDER BY d.run_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        echo "  SKIP  no payroll_run_details row in this dev DB to test against\n";
    } else {
        $runId = (int)$target['run_id'];
        $employeeId = (int)$target['employee_id'];
        $compId = (int)$target['comp_id'];

        $rowsOf = static function () use ($model, $compId, $runId, $employeeId): array {
            $out = [];
            foreach ($model->syncDeductionLinesForEmployee($compId, $runId, $employeeId) as $row) {
                $out[$row['code']] = $row;
            }
            return $out;
        };

        echo "=== 1. every row is tagged, with a value the table knows how to group ===\n";
        $rows = $rowsOf();
        checkTrue('at least the base salary row is returned', count($rows) > 0);
        $allowed = ['base_salary', 'earning', 'deduction', 'statutory', 'other'];
        $bad = [];
        foreach ($rows as $code => $row) {
            if (!array_key_exists('item_type', $row) || !in_array($row['item_type'], $allowed, true)) {
                $bad[] = $code . '=' . var_export($row['item_type'] ?? null, true);
            }
        }
        check('no row has a missing/unknown item_type', $bad, []);
        check('base salary row is tagged base_salary', $rows[PayrollRunModel::BASE_SALARY_OVERRIDE_CODE]['item_type'] ?? null, 'base_salary');
        // Statutory rows are the one kind whose tag must agree with the field the UI badges off.
        foreach ($rows as $code => $row) {
            if (($row['line_type'] ?? '') === 'statutory') {
                check("statutory row {$code} is tagged statutory", $row['item_type'], 'statutory');
            }
        }

        echo "\n=== 2. rows that came out of a breakdown are tagged by THEIR OWN column ===\n";
        // Written straight into the persisted breakdowns so the assertion does not depend on this
        // dev DB happening to have an earning and a deduction line for this employee already.
        $detail = $pdo->prepare("UPDATE `payroll_run_details`
            SET earning_breakdown = :earning, deduction_breakdown = :deduction
            WHERE run_id = :run_id AND employee_id = :employee_id");
        $detail->execute([
            ':earning' => json_encode([['code' => 'TEST_EARN', 'name_th' => 'ทดสอบรายได้', 'name_en' => 'Test earning', 'amount' => 100]], JSON_UNESCAPED_UNICODE),
            ':deduction' => json_encode([['code' => 'TEST_DEDUCT', 'name_th' => 'ทดสอบรายการหัก', 'name_en' => 'Test deduction', 'amount' => 50]], JSON_UNESCAPED_UNICODE),
            ':run_id' => $runId,
            ':employee_id' => $employeeId,
        ]);
        $rows = $rowsOf();
        check('earning_breakdown row -> earning', $rows['TEST_EARN']['item_type'] ?? null, 'earning');
        check('deduction_breakdown row -> deduction', $rows['TEST_DEDUCT']['item_type'] ?? null, 'deduction');
        check('the earning row keeps its own amount', $rows['TEST_EARN']['current_amount'] ?? null, 100.0);
        // 2026-09-18, tiny-C: `computed_amount` is passed through from the breakdown entry, and is
        // ALWAYS a key -- null (not missing) on a line the engine's own figure is still live on.
        check('a line with no computed_amount reports null, not a missing key', array_key_exists('computed_amount', $rows['TEST_EARN']) ? $rows['TEST_EARN']['computed_amount'] : 'MISSING', null);
        $detail->execute([
            ':earning' => json_encode([['code' => 'TEST_EARN', 'name_th' => 'ทดสอบรายได้', 'name_en' => 'Test earning', 'amount' => 100, 'computed_amount' => 137.5]], JSON_UNESCAPED_UNICODE),
            ':deduction' => json_encode([['code' => 'TEST_DEDUCT', 'name_th' => 'ทดสอบรายการหัก', 'name_en' => 'Test deduction', 'amount' => 50]], JSON_UNESCAPED_UNICODE),
            ':run_id' => $runId,
            ':employee_id' => $employeeId,
        ]);
        $rowsWithComputed = $rowsOf();
        check('a persisted computed_amount reaches the row untouched', $rowsWithComputed['TEST_EARN']['computed_amount'] ?? null, 137.5);
        check('...and the live amount is still the post-override one', $rowsWithComputed['TEST_EARN']['current_amount'] ?? null, 100.0);

        echo "\n=== 3. a row excluded out of every breakdown gets its type from the catalog ===\n";
        // An 'exclude' override drops the line from the breakdown entirely, so this row exists ONLY
        // through the fallback path -- the one place item_type cannot come from a column.
        $catalogRow = $pdo->query("SELECT item_code, item_type FROM `payroll_earning_deduction_types`
            WHERE comp_id = {$compId} AND status = 'active' AND item_type IN ('earning','deduction') LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$catalogRow) {
            echo "  SKIP  this company has no active catalog item to test the fallback with\n";
        } else {
            $ins = $pdo->prepare("INSERT INTO `payroll_run_line_overrides` (run_id, employee_id, item_code, action, created_at)
                VALUES (?, ?, ?, 'exclude', NOW())");
            $ins->execute([$runId, $employeeId, $catalogRow['item_code']]);
            $ins->execute([$runId, $employeeId, '__no_such_catalog_item__']);
            $rows = $rowsOf();
            check(
                "catalog item {$catalogRow['item_code']} -> its own catalog item_type",
                $rows[$catalogRow['item_code']]['item_type'] ?? null,
                $catalogRow['item_type']
            );
            check('a code with no catalog row at all -> other', $rows['__no_such_catalog_item__']['item_type'] ?? null, 'other');
            // The catalog lookup is also what gives these rows a readable name instead of a raw code.
            checkTrue(
                'a catalog-backed fallback row shows a name, not its raw code',
                ($rows[$catalogRow['item_code']]['name_th'] ?? '') !== $catalogRow['item_code']
                    || ($rows[$catalogRow['item_code']]['name_en'] ?? '') !== $catalogRow['item_code']
            );
            check('an excluded row still reports its override_action', $rows[$catalogRow['item_code']]['override_action'] ?? null, 'exclude');
            check('an excluded row has no amount to show', $rows[$catalogRow['item_code']]['current_amount'] ?? null, 0.0);
            // An excluded line is gone from the breakdown, so there is no engine figure to serve
            // for it either -- null, deliberately, rather than a guess (BACKLOG).
            check('an excluded row has no engine figure either', array_key_exists('computed_amount', $rows[$catalogRow['item_code']]) ? $rows[$catalogRow['item_code']]['computed_amount'] : 'MISSING', null);
        }

        echo "\n=== 4. statutory rows carry the engine's own note, verbatim ===\n";
        // The table hides a row ONLY on 'employee_not_enrolled'/'employee_tax_exempt'/'disabled', so
        // the note has to arrive unchanged -- a dropped or rewritten note either hides a real problem
        // (no_rate_configured) or stops hiding what should be hidden.
        $pdo->prepare("UPDATE `payroll_run_details` SET statutory_breakdown = :b WHERE run_id = :run_id AND employee_id = :employee_id")
            ->execute([
                ':b' => json_encode([
                    ['code' => 'TH_SSO', 'name_th' => 'ประกันสังคม', 'name_en' => 'SSO', 'employee_amount' => 750, 'note' => null],
                    ['code' => 'TH_PVD', 'name_th' => 'กองทุนสำรองเลี้ยงชีพ', 'name_en' => 'PVD', 'employee_amount' => 0, 'note' => 'employee_not_enrolled'],
                    ['code' => 'TH_PIT', 'name_th' => 'ภาษี', 'name_en' => 'PIT', 'employee_amount' => 0, 'note' => 'no_rate_configured'],
                ], JSON_UNESCAPED_UNICODE),
                ':run_id' => $runId,
                ':employee_id' => $employeeId,
            ]);
        $rows = $rowsOf();
        // `?? 'missing'` would report a present-but-null value as missing -- the point here is that
        // the KEY is always there and its value is null, so the client never has to guess.
        checkTrue('a computed statutory row has the key', array_key_exists('note', $rows['TH_SSO']));
        check('a computed statutory row has no note', $rows['TH_SSO']['note'], null);
        check('a skipped row reports why (this is what the table hides on)', $rows['TH_PVD']['note'] ?? null, 'employee_not_enrolled');
        check('a not-set-up row reports its own note (never hidden)', $rows['TH_PIT']['note'] ?? null, 'no_rate_configured');
        checkTrue('a non-statutory row has the key too', array_key_exists('note', $rows[PayrollRunModel::BASE_SALARY_OVERRIDE_CODE]));
        check('a non-statutory row reports note = null', $rows[PayrollRunModel::BASE_SALARY_OVERRIDE_CODE]['note'], null);

        echo "\n=== 5. an item turned off in Run Settings is listed even with no breakdown line ===\n";
        // Before this, such a row appeared while the breakdown still had it and vanished after the
        // next recalculate dropped it -- one unchanged setting showing 2 different tables.
        $runOnlyCode = null;
        foreach ($pdo->query("SELECT item_code FROM `payroll_earning_deduction_types` WHERE comp_id = {$compId} AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN) as $candidate) {
            if (!isset($rows[$candidate])) { $runOnlyCode = $candidate; break; }
        }
        if (!$runOnlyCode) {
            echo "  SKIP  every catalog item already has a row for this employee\n";
        } else {
            check("{$runOnlyCode} is not in the list to begin with", isset($rowsOf()[$runOnlyCode]), false);
            $pdo->prepare("INSERT INTO `payroll_run_item_exclusions` (run_id, item_code, created_at) VALUES (?, ?, NOW())")
                ->execute([$runId, $runOnlyCode]);
            $rows = $rowsOf();
            checkTrue("{$runOnlyCode} is listed once the run excludes it", isset($rows[$runOnlyCode]));
            check('it is listed as a plain, un-overridden row (the UI disables it from run_settings)', $rows[$runOnlyCode]['override_action'], null);
            check('it has no amount of its own', $rows[$runOnlyCode]['current_amount'] ?? null, 0.0);
            checkTrue('it is grouped by its catalog item_type, not dumped into other',
                in_array($rows[$runOnlyCode]['item_type'] ?? null, ['earning', 'deduction'], true));
            // ...and it is listed exactly once even when it is BOTH run-excluded and personally excluded.
            $pdo->prepare("INSERT INTO `payroll_run_line_overrides` (run_id, employee_id, item_code, action, created_at) VALUES (?, ?, ?, 'exclude', NOW())")
                ->execute([$runId, $employeeId, $runOnlyCode]);
            $occurrences = 0;
            foreach ($model->syncDeductionLinesForEmployee($compId, $runId, $employeeId) as $row) {
                if ($row['code'] === $runOnlyCode) $occurrences++;
            }
            check('no duplicate row when it is excluded at both levels', $occurrences, 1);
        }

        echo "\n=== 6. the UI's positive checkbox did NOT change what is stored ===\n";
        // The tab shows "นำมาคำนวณ" (ticked = included) but the stored field still means the opposite
        // -- one row in payroll_run_line_overrides with action='exclude'. Nothing about this round
        // may have introduced a second representation.
        $stored = $pdo->query("SELECT DISTINCT action FROM `payroll_run_line_overrides`
            WHERE run_id = {$runId} AND employee_id = {$employeeId} ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
        check('only the 2 documented actions are ever stored', array_values(array_diff($stored, ['exclude', 'override_amount'])), []);

        echo "
=== 6b. a hand-added line is not listed here at all (2026-09-16) ===
";
        // A manual line is edited and removed on its own tab, as the row it really is. It must not
        // also appear here with a switch and a pencil: an override is keyed by item_code, and two
        // manual lines are allowed to share one code, so an override on that code has no single line
        // to mean. Filtered in the model so BOTH mount points of this table agree without either
        // remembering to do it -- which is exactly what this asserts by calling the model directly.
        $pdo->prepare("UPDATE `payroll_run_details`
                SET earning_breakdown = :earning, deduction_breakdown = :deduction
                WHERE run_id = :run_id AND employee_id = :employee_id")
            ->execute([
                ':earning' => json_encode([
                    ['source' => 'manual_line', 'manual_line_id' => 9001, 'code' => 'TEST_BONUS', 'name_th' => 'โบนัสทดสอบ', 'name_en' => 'Test bonus', 'amount' => 2000],
                    ['code' => 'TEST_CALC_EARN', 'name_th' => 'รายได้จากระบบ', 'name_en' => 'Calculated earning', 'amount' => 500],
                ], JSON_UNESCAPED_UNICODE),
                ':deduction' => json_encode([
                    ['source' => 'manual_line', 'manual_line_id' => 9002, 'code' => 'TEST_UNIFORM', 'name_th' => 'หักทดสอบ', 'name_en' => 'Test deduction', 'amount' => 550],
                ], JSON_UNESCAPED_UNICODE),
                ':run_id' => $runId,
                ':employee_id' => $employeeId,
            ]);
        $codes = array_column($model->syncDeductionLinesForEmployee($compId, $runId, $employeeId), 'code');
        check('a hand-added earning is not offered for adjustment', in_array('TEST_BONUS', $codes, true), false);
        check('a hand-added deduction is not either', in_array('TEST_UNIFORM', $codes, true), false);
        // The filter has to be about WHERE the line came from, not about it being an earning: a
        // calculated line in the same column stays.
        check('a calculated line in the same column is still listed', in_array('TEST_CALC_EARN', $codes, true), true);
        check('base salary is still listed', in_array(PayrollRunModel::BASE_SALARY_OVERRIDE_CODE, $codes, true), true);
        // The line is filtered out of ONE endpoint, not out of the payroll. It is still in the
        // breakdown the payslip renders, and still in the money the employee is paid -- a filter
        // that quietly dropped it from either would be a pay bug, not a UI change.
        $detail = $model->getDetails($runId, $compId);
        $row = null;
        foreach ($detail as $d) { if ((int)$d['employee_id'] === $employeeId) { $row = $d; break; } }
        $breakdownCodes = array_merge(array_column($row['earning_breakdown'], 'code'), array_column($row['deduction_breakdown'], 'code'));
        check('the hand-added lines are still in the breakdown the payslip renders',
            [in_array('TEST_BONUS', $breakdownCodes, true), in_array('TEST_UNIFORM', $breakdownCodes, true)], [true, true]);
        // Totals are the persisted ones recalculate() wrote; reading them back proves this endpoint's
        // own filter changed nothing about them.
        $persisted = $pdo->query("SELECT gross_amount, total_deduction_amount, net_amount FROM `payroll_run_details`
            WHERE run_id = {$runId} AND employee_id = {$employeeId}")->fetch(PDO::FETCH_ASSOC);
        check('and the run totals are untouched by the filter',
            [$row['gross_amount'], $row['total_deduction_amount'], $row['net_amount']],
            [$persisted['gross_amount'], $persisted['total_deduction_amount'], $persisted['net_amount']]);
    }
} finally {
    $pdo->rollBack();
}

echo "\n=== 7. one action, one request, sent immediately ===\n";
// 2026-09-16, round 6: this tab stopped staging. The staged version existed for a batch endpoint
// that was never built -- its single Save button fired one request per row anyway -- so all it
// really produced was a screen that disagreed with the server, plus the rules invented to describe
// that gap ("empty means...", "unticking parks...", the data-loss bug of round 5). Every action now
// writes the moment it is confirmed. These assertions are the action table in
// docs/decisions/2026-09-16-line-override-table.md, read off the source that implements it.
$js = file_get_contents(__DIR__ . '/../public/js/payroll/detail.js');

// There is exactly ONE write path, and every entry point goes through it.
checkTrue('one sender for the whole tab', substr_count($js, 'function lineOverrideSendRd(') === 1);
foreach ([
    'the switch' => "lineOverrideSendRd(\$row, included ? { action: 'remove' } : { action: 'exclude' })",
    'history -> a recorded value' => "lineOverrideSendRd(\$row, { action: 'override_amount', amount: parsed })",
] as $label => $call) {
    checkTrue("{$label} sends through it", strpos($js, $call) !== false);
}
// 2026-09-18, 4b: "back to what the system decided" is its own function now -- on TH_PIT/TH_SSO it
// is 2 stored things (the tri-state answer AND any amount override), which one plan cannot express.
checkTrue('the calculated value goes through the row restore', strpos($js, 'function lineOverrideRestoreRowRd($row) {') !== false
    && strpos($js, "if (asComputed) {\n                lineOverrideRestoreRowRd(\$row);") !== false);
// ...and the staged machinery is gone, not merely unused. 2026-09-18, tiny-L6a adds the inline
// cell editor to that list: the pencil opens the line's own form now, which has its own write path
// (submitLineOverrideFormRd) through the same lineOverrideRequestRd() this sender uses.
foreach (['lineOverrideRowPlanRd', 'saveLineOverrideTableRd', 'data-force-remove', 'data-typed-amount',
          'data-from-history', 'lo-new-amount', 'lo-input-dirty',
          'lineOverrideOpenEditorRd', 'lineOverrideCloseEditorRd', 'lineOverrideEditPlanRd',
          'lo-edit-input', 'lo-amount-edit', 'lo-edit-save', 'lo-edit-cancel', 'lo-use-system-btn'] as $dead) {
    checkTrue("no trace of `{$dead}` is left", strpos($js, $dead) === false);
}

echo "\n=== 8. the pencil opens the line's own form ===\n";
// 2026-09-18, tiny-L6a: the cell no longer becomes an editor. It could only ever hold the amount,
// so a line's note and its destination had no way in from the row they belong to -- the pencil now
// opens the SAME modal form a hand-added line is edited in (rules.md 9). What that form does with
// each kind of line is pinned down in tests/line_form_sections_test.js; what THIS file asserts is
// that the row hands it over correctly and keeps nothing of the old editor.
checkTrue('the pencil opens the form, nothing else',
    strpos($js, "\$(document).on('click', '.lo-mount .lo-edit-btn', function () {\n    openLineOverrideFormRd(\$(this).closest('tr.lo-row'));") !== false);
// The row is a KEY, not the data: the line itself is looked up in what the table was rendered from,
// so the form fills in from the payload rather than from text scraped off the screen.
checkTrue('the line is looked up by code AND type, never by code alone',
    strpos($js, 'function lineOverrideLineByRowRd($row) {') !== false
    && strpos($js, "String(l.code) === code && (l.line_type || 'earning_deduction') === lineType") !== false);
checkTrue('an excluded row has no amount to edit, so it does not open',
    strpos($js, "if (!\$row.length || \$row.hasClass('lo-row-off')) return;") !== false);
// One reload path, shared by the row's own controls and by the form -- one override moves every
// other figure with it, so neither may patch a row locally.
checkTrue('both write paths reload through one function',
    strpos($js, 'function lineOverrideAfterWriteRd() {') !== false
    && substr_count($js, 'lineOverrideAfterWriteRd()') >= 3);
// The endpoint pair is picked from the line TYPE now, not from a row -- the form sends for a line it
// holds as data and has no row to read.
checkTrue('the endpoint is chosen by line type', strpos($js, 'function lineOverrideEndpointRd(lineType, action) {') !== false
    && strpos($js, "return (lineType || 'earning_deduction') === 'statutory'") !== false);
checkTrue('one request builder for both senders', strpos($js, 'function lineOverrideRequestRd(lineType, payload, action, done) {') !== false
    && strpos($js, 'function lineOverridePayloadRd(itemCode, plan) {') !== false);
// The note column has always existed on the override table and the endpoint has always accepted it;
// tiny-L6a is the first sender. It rides on the same payload builder, so it cannot reach one path only.
checkTrue('a note rides along with the amount when the caller has one',
    strpos($js, "if (plan.note !== undefined) { payload.note = plan.note; }") !== false);

echo "\n=== 9. the switch asks, both ways ===\n";
// 2026-09-16: bound on `.lo-mount`, the class BOTH hosts of this table carry, not on tab 3's own id
// -- see setLineOverrideHostRd()'s docblock for why the table has 2 mount points and one handler set.
$swStart = (int)strpos($js, "on('change', '.lo-mount .lo-include'");
$sw = substr($js, $swStart, 2600);
checkTrue('turning it OFF asks in the warning tone', strpos($sw, "line_override_confirm_exclude_message") !== false);
checkTrue('turning it ON asks too', strpos($sw, "line_override_confirm_include_message") !== false);
checkTrue('the tone differs by direction', strpos($sw, "tone: included ? 'info' : 'warning'") !== false);
// A cancelled confirm must not leave the control disagreeing with the data behind it.
checkTrue('cancelling puts the switch back', strpos($sw, 'onNo: snapBack') !== false
    && strpos($sw, "\$row.find('.lo-include').prop('checked', !included)") !== false);
checkTrue('nothing is sent before the answer', strpos($sw, 'onYes: function () {') < strpos($sw, 'lineOverrideSendRd('));

echo "\n=== 10. the table is locked while one write is in flight ===\n";
// Each save recalculates the whole run internally; a second action started before the first comes
// back would race it.
$busyStart = (int)strpos($js, 'function setLineOverrideTableBusyRd(');
$busy = substr($js, $busyStart, 900);
foreach (['.lo-include', '.lo-edit-btn', '.lo-history-toggle'] as $control) {
    checkTrue("`{$control}` is disabled while busy", strpos($busy, $control) !== false);
}
checkTrue('the footer action is locked too', strpos($busy, "\$('#btnRestoreAllComputedLineOverrides').prop('disabled', busy);") !== false);
// A row that was ALREADY disabled (Run Settings) must not come back enabled when the lock lifts.
checkTrue('an already-disabled control stays disabled afterwards', strpos($busy, "data-was-disabled") !== false);
// A failed write leaves what the user typed where it is, so they can fix it rather than start over.
// The lock has to survive the reload that follows a successful write, or the user can act on rows
// that are about to be replaced -- so it is released where the fresh rows land, not where the
// request came back. (Found by measuring: the table stayed dimmed forever without this.)
checkTrue('the lock is released when the new rows render', strpos($js, "\$wrap.removeClass('lo-table-busy');") !== false);
checkTrue('a failure unlocks and leaves the table as the user left it',
    strpos($js, 'function lineOverrideSendFailedRd(') !== false);
// One override changes what the statutory lines calculate to, so the whole table (and the run's own
// totals) is reloaded, never patched row-locally.
checkTrue('success reloads the table and the run', strpos($js, "function lineOverrideAfterWriteRd() {\n    loadSyncLineOverridesRd();\n    loadRunDetail();") !== false);

echo "\n=== 11. no Save button, and restore-all sits with the table ===\n";
// 2026-09-17, D3: this table has no Save step at all -- every action writes when it is confirmed --
// and its only host is a modal whose footer is [restore all] ... [Close], with no Save to disable.
checkTrue('the Breakdown modal footer has no Save button', strpos($js, "function renderBreakdownFooterRd(canEdit) {") !== false
    && strpos($js, "? { id: 'btnRestoreAllComputedLineOverrides'") !== false
    && strpos($js, "secondary: { key: 'close', fallback: 'Close', dismiss: true },\n    }));\n    refreshBreakdownFooterStateRd();") !== false);
// Restore-all is the one thing that touches rows the user never opened, so it is also the one thing
// that is disabled until there is really something to restore.
checkTrue('restore-all is enabled only when a row really carries an override',
    strpos($js, "function refreshBreakdownFooterStateRd() {") !== false
    && strpos($js, "\$btn.prop('disabled', overrideRowCount === 0);") !== false);
checkTrue('restore-all confirms with a count before sending', strpos($js, 'line_override_confirm_restore_all_message') !== false);
checkTrue('and sends one .remove per row, in order', strpos($js, "url: lineOverrideEndpointRd(\$row.data('line-type'), 'remove')") !== false
    && strpos($js, 'runSequentialAjaxRd(calls,') !== false);

echo "\n=== 12. the row, and what it says ===\n";
$rowStart = (int)strpos($js, 'function lineOverrideRowHtml(');
$rowHtml = substr($js, $rowStart, (int)strpos($js, 'function renderLineOverrideTableRd(') - $rowStart);
checkTrue('the include control is a switch', strpos($rowHtml, 'class="form-check form-switch mb-0"') !== false
    && strpos($rowHtml, 'role="switch"') !== false);
checkTrue('the amount column carries the figure and its pencil', strpos($rowHtml, 'lo-amount-cell') !== false
    && strpos($rowHtml, 'lo-edit-btn') !== false);
// 2026-09-17, R1: an off row has no FIGURE either, not just no pencil -- where the amount used to be
// struck through it now says what is true about the row ("ไม่นำมาคำนวณ"). The editable-only controls
// (pencil, "use the calculated value") hang off the same one condition.
// 2026-09-18, 4a-2 follow-up: `!skipped` dropped out of that condition -- a skipped line is filtered
// out by renderLineOverrideTableRd() before it can be a row at all, so the row builder has no
// skipped case left to gate.
checkTrue('an off row shows no figure and no controls', strpos($rowHtml, 'const editable = included && !runDisabled;') !== false
    && strpos($rowHtml, "line_override_excluded_amount") !== false
    && strpos($rowHtml, 'const amountCell = included') !== false);
checkTrue('a skipped line never reaches the row builder at all',
    strpos($js, '&& !lineOverrideIsSkippedRd(l, mode)') !== false
    && strpos($rowHtml, 'lo-row-skipped') === false && strpos($rowHtml, 'skipBadge') === false
    && strpos($rowHtml, 'lineOverrideSkipEnumRd(') === false
    && strpos($js, 'payroll_statutory_skip') === false);
// 2026-09-18, tiny-L6b (B3): NOT a column of its own any more -- a sub-line of the amount cell, so
// the table carries one money column and the figure sits under the one it is compared with. The
// markup-level assertions live in tests/line_override_row_render_test.js; what is checked here is
// that nothing of the old column survived in the row builder.
checkTrue('the calculated figure is a sub-line of the amount cell, not a column',
    strpos($rowHtml, 'lo-computed-cell') === false
    && strpos($rowHtml, 'lineOverrideComputedTagHtml(line)') !== false
    && substr_count($rowHtml, 'col-money') === 1);
// It comes from the row when nothing has overridden it, and from this line's own history when
// something has -- the same `original_value` the dropdown's head shows.
checkTrue('the calculated figure has one resolver, with both sources', strpos($js, 'function lineOverrideComputedTextRd(line) {') !== false
    && strpos($js, 'if (!line.override_action) return lineOverrideHistoryValueRd(line.current_amount);') !== false
    && strpos($js, 'const original = history ? history.original_value : null;') !== false);
// 2026-09-18, tiny-L6b (B3): ...and it answers only where the answer is real. `original_value` is a
// stand-in for the engine's figure, not the figure itself, so a row with no recorded history says
// nothing rather than printing an older override as if the system had calculated it (BACKLOG).
// 2026-09-18, tiny-C: the persisted engine figure is asked FIRST -- the history below is only
// the fallback for runs last calculated before it existed, and must not be removed.
checkTrue('the persisted engine figure wins, with the history kept as the fallback',
    strpos($js, 'if (line.computed_amount !== null && line.computed_amount !== undefined) return lineOverrideHistoryValueRd(line.computed_amount);') !== false
    && strpos($js, 'const original = history ? history.original_value : null;') !== false);
checkTrue('...and it refuses to answer without a recorded history',
    strpos($js, 'const history = lineOverrideHistoryRd.historyAvailable ? lineOverrideHistoryFor(line) : null;') !== false);
// 2026-09-18, tiny-L6a: "back to the calculated value" is no longer a second round button in the
// row -- it is the form's own left slot, where both figures are on screen together. So the row
// carries exactly one control, and the action still exists, just not here.
// 2026-09-18, 4a-2: the cell holds the pencil for a calculated row and the hand-added row's own 2
// buttons for a manual one -- one action column, never a second place actions can live.
checkTrue('the row carries one action button, the pencil',
    strpos($rowHtml, '<div class="lo-actions">${isManual ? manualActions : pencil}</div>') !== false
    && strpos($rowHtml, 'lo-use-system-btn') === false);
checkTrue('a hand-added row carries its own 2 instead, addressed by line id',
    strpos($rowHtml, 'manual-line-edit-btn" data-line-id="${escapeAttr(line.manual_line_id)}"') !== false
    && strpos($rowHtml, 'manual-line-remove-btn" data-line-id="${escapeAttr(line.manual_line_id)}"') !== false);
checkTrue('the action moved to the form footer, it was not dropped',
    strpos($js, "{ id: 'btnLineFormUseComputed', key: 'line_override_use_computed'") !== false
    && strpos($js, "\$(document).on('click', '#btnLineFormUseComputed', function () {") !== false);
checkTrue('and it still goes through the one confirm the history menu uses',
    strpos($js, "lineOverrideConfirmApplyHistoryValueRd(ctx.overrideLine.code, '', true, lineFormCloseRd);") !== false);
checkTrue('the row carries its own current figure for the form', strpos($rowHtml, 'data-amount="${escapeAttr(fmtNum(line.current_amount))}"') !== false);

$thLang = json_decode(file_get_contents(__DIR__ . '/../public/lang/th.json'), true);
$enLang = json_decode(file_get_contents(__DIR__ . '/../public/lang/en.json'), true);
foreach (['line_override_col_amount', 'line_override_edit_amount', 'line_override_saved',
          'line_override_confirm_exclude_title', 'line_override_confirm_exclude_message',
          'line_override_confirm_include_title', 'line_override_confirm_include_message'] as $key) {
    checkTrue("{$key} exists in both languages", isset($thLang[$key], $enLang[$key]));
}
foreach (['line_override_confirm_exclude_message', 'line_override_confirm_include_message'] as $key) {
    checkTrue("{$key} names the item", strpos((string)$thLang[$key], '{item}') !== false
        && strpos((string)$enLang[$key], '{item}') !== false);
}
// The staged version's vocabulary is gone from the screen too.
foreach (['line_override_col_new', 'line_override_new_placeholder', 'line_override_cancel_edits'] as $key) {
    checkTrue("{$key} is gone", !array_key_exists($key, $thLang) && !array_key_exists($key, $enLang));
}
// The tab's own hint line went with the tab (D3) -- the table is read inside the Calculation
// Breakdown modal now, which carries no hint of its own.
checkTrue('line_override_hint is gone', !array_key_exists('line_override_hint', $thLang)
    && !array_key_exists('line_override_hint', $enLang));
// "Use this value" now writes on confirm, so its own wording had to change with it.
checkTrue('the use-this-value confirm says it saves immediately',
    strpos((string)$thLang['line_override_confirm_use_value_message'], 'บันทึกทันที') !== false
    && strpos((string)$enLang['line_override_confirm_use_value_message'], 'saved immediately') !== false);

echo "\n=== 13. the read-only slip's 2 tabs (4a-2b) ===\n";
// The reason badge on a skipped row is gone with the row itself (4a-2): its status_map context and
// all 4 of its labels went in this round, so nothing may reference them again.
foreach (['statutory_skip_sso', 'statutory_skip_pvd', 'statutory_skip_tax_exempt', 'statutory_skip_disabled'] as $key) {
    checkTrue("{$key} is gone from both lang files", !array_key_exists($key, $thLang) && !array_key_exists($key, $enLang));
}
checkTrue('the payroll_statutory_skip context is gone with them',
    !array_key_exists('payroll_statutory_skip', require __DIR__ . '/../app/config/status_map.php'));
foreach (['line_override_tab_all', 'line_override_tab_changed'] as $key) {
    checkTrue("{$key} is in both lang files", !empty($thLang[$key]) && !empty($enLang[$key]));
}
// The count is decided by one predicate, and it is the SAME one the filter uses.
checkTrue('the changed-row predicate is one function', strpos($js, 'function lineOverrideIsChangedRd(line) {') !== false
    && strpos($js, "return !!line.override_action || line.line_type === 'manual_line' || statutoryExemptionChangedRd(line);") !== false);
checkTrue('the tab row is only built for the read-only slip', strpos($js, 'const changedCount = isView') !== false);
checkTrue('an empty count renders no tab row at all', strpos($js, "if (!changedCount) return '';") !== false);
// 2026-09-19, tiny-4b-fix1 v2: the totals are appended to the HOST after the scroller closes, not
// into the table body -- but they still come off the run's own row, which is what makes a filtered
// table end on the full pay.
checkTrue('the filter narrows the rows, not the totals',
    strpos($js, '&& (!changedOnly || lineOverrideIsChangedRd(l)));') !== false
    && strpos($js, '</table></div>` + lineOverrideTotalsHtmlRd(breakdownRowRd));') !== false);
checkTrue('the tab state is reset per host, so it never survives into the next employee slip',
    strpos($js, "    lineOverrideViewFilterRd = 'all';\n") !== false
    && strpos($js, "    lineOverrideExemptionRd = null;\n}") !== false);

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
