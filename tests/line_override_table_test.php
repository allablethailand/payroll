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
    }
} finally {
    $pdo->rollBack();
}

echo "\n=== 7. the New-value field only ever means \"change it to this\" ===\n";
// 2026-09-16, real data-loss bug the user reproduced: the field used to PREFILL an existing override,
// and an empty field meant "drop the override". Unticking a row clears the field, so untick +
// re-tick + Save deleted a real 40,000 with nothing on screen saying it would. The field now starts
// empty on every row and empty means DO NOT TOUCH -- these assertions are the 6-row truth table in
// docs/decisions/2026-09-16-line-override-table.md, read off the source that implements it.
$js = file_get_contents(__DIR__ . '/../public/js/payroll/detail.js');
$planStart = strpos($js, 'function lineOverrideRowPlanRd(');
$plan = substr($js, (int)$planStart, (int)strpos($js, 'function lineOverrideSaveUrlRd(') - (int)$planStart);

// (6) the case that was wrong: ticked + empty sends nothing, on EVERY row
checkTrue('ticked + empty returns no plan at all', strpos($plan, "if (newAmount === '') return null;") !== false);
// ...and the old rule is gone, not merely shadowed by something earlier
checkTrue('empty no longer maps to .remove on an overridden row',
    strpos($plan, "if (newAmount === '') {") === false);
// (1) the mark is read before the field
checkTrue('a marked row is a .remove, decided before the field is read',
    strpos($plan, 'data-force-remove') < strpos($plan, '.lo-new-amount'));
// (2)(3)(4)(5)
checkTrue('a Run-Settings row sends nothing', strpos($plan, "if (\$check.is(':disabled')) return null;") !== false);
checkTrue('unticked sends exclude, unless it already is one', strpos($plan, "return origAction === 'exclude' ? null : { action: 'exclude' };") !== false);
checkTrue('re-ticking an excluded row with nothing typed undoes the exclusion',
    strpos($plan, "if (origAction === 'exclude' && newAmount === '') return { action: 'remove' };") !== false);
checkTrue('a typed value is always an override_amount', strpos($plan, "return { action: 'override_amount', amount: parsed };") !== false);
// ...and it no longer skips sending when the typed value happens to equal the stored one, because
// there is nothing prefilled for it to equal any more.
checkTrue('no stored-amount comparison is left in the plan', strpos($plan, 'newAmount === origAmount') === false);

echo "\n=== 8. the field starts empty, and unticking parks what was typed ===\n";
$rowStart = (int)strpos($js, 'function lineOverrideRowHtml(');
$rowHtml = substr($js, $rowStart, (int)strpos($js, 'function renderLineOverrideTableRd(') - $rowStart);
checkTrue('the field renders empty on every row', strpos($rowHtml, "const amountValue = '';") !== false);
// The stored figure is not carried anywhere on the row either: the "ค่าปัจจุบัน" column already shows
// what is in effect, and a second copy of it is exactly what the field's prefill was.
checkTrue('the stored override figure reaches neither the field nor the row', strpos($rowHtml, 'data-orig-amount') === false
    && strpos($rowHtml, 'fmtNum(line.override_amount)') === false);
checkTrue('unticking parks the typed value', strpos($js, "data-typed-amount', String(\$input.val()") !== false);
checkTrue('re-ticking restores it', strpos($js, "const parked = \$row.attr('data-typed-amount');") !== false);
// A second `change` for the same state must not overwrite the parked value with the already-cleared
// field -- the naive version did exactly that and lost the number it existed to protect.
checkTrue('parking is keyed off the row state, so a repeated change is a no-op',
    strpos($js, "const wasOff = \$row.hasClass('lo-row-off');") !== false
    && strpos($js, 'if (!included && !wasOff)') !== false
    && strpos($js, '} else if (included && wasOff) {') !== false);

$thLang = json_decode(file_get_contents(__DIR__ . '/../public/lang/th.json'), true);
$enLang = json_decode(file_get_contents(__DIR__ . '/../public/lang/en.json'), true);
// The hint has to say what empty means now, or the screen still teaches the rule that lost data.
checkTrue('the hint no longer says blank = use the system value',
    strpos((string)$thLang['line_override_hint'], 'เว้นว่าง = ใช้ค่าระบบ') === false
    && strpos((string)$enLang['line_override_hint'], 'blank = use system value') === false);
checkTrue('the hint says blank = no change, in both languages',
    strpos((string)$thLang['line_override_hint'], 'เว้นว่าง = ไม่เปลี่ยน') !== false
    && strpos((string)$enLang['line_override_hint'], 'blank = no change') !== false);
// ...and names the two ways back to the calculated figure, since empty is no longer one of them.
checkTrue('and names how to get back to the system value',
    strpos((string)$thLang['line_override_hint'], 'คืนค่าระบบทั้งหมด') !== false
    && strpos((string)$enLang['line_override_hint'], 'Restore-all') !== false);

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
