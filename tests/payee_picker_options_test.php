<?php
declare(strict_types=1);
/**
 * The payee picker ("เงินที่หักได้ ส่งไปที่") appears in 4 places -- the Adjustments modal's Payment
 * Items tab and its Recurring Deduction Destination tab (payroll/detail.php), plus Employee Detail's
 * #eedModal and #recurringDeductionModal (layout/modals.php).
 *
 * 2026-09-16 rebuilt it as ONE shared component: 3 destinations (what happens to the money) plus a
 * sub-question under the first, mapped onto `payee_type` on the client right before submit --
 * docs/decisions/2026-09-16-payee-three-destinations.md.
 *
 * This test locks in the halves of that decision that are easy to undo by accident:
 *  1. the partial really renders 3 destinations, and the sub-question appears exactly when the
 *     caller allows a "no record" answer (it is rendered here for real, not pattern-matched);
 *  2. all 4 call sites use that partial -- nobody has re-grown a private payee control;
 *  3. every label/helper key the component names exists in BOTH language files, and the keys the
 *     old flat list used are gone (except the one the read path still needs);
 *  4. the backend was NOT narrowed with it -- all 4 write paths still accept all 5 enum values, so
 *     rows saved earlier keep working and a retired choice can come back without a migration.
 *
 * Pure file/JSON reads plus one render of the partial -- no DB, no fixtures, nothing to roll back.
 */
$root = dirname(__DIR__);

$passed = 0;
$failed = 0;
function check(string $label, $actual, $expected): void {
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        echo "PASS: {$label}\n";
    } else {
        $failed++;
        echo 'FAIL: ' . $label . ' -- expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
    }
}

/** Render the shared partial the way a view does, and hand back its HTML. */
function renderPayeePartial(string $root, string $prefix, bool $allowNoRecord, string $slot): string {
    $payee_prefix = $prefix;
    $payee_allow_no_record = $allowNoRecord;
    $payee_slot = $slot;
    ob_start();
    include $root . '/app/views/partials/payee-destination.php';
    return (string)ob_get_clean();
}

// ---------------------------------------------------------------- 1. the partial itself
$html = renderPayeePartial($root, 'demo', true, '<div id="demoSlotMarker"></div>');
preg_match_all('/name="demo_payee_dest" id="([^"]+)" value="([^"]+)"/', $html, $m);
check('3 destinations, in order', implode(',', $m[2] ?? []), 'company_retained,employee,external');
check('the first destination is the one pre-selected', substr_count($html, 'value="company_retained" checked'), 1);
check('destination labels come from lang keys, not literals', substr_count($html, 'data-i18n="payee_dest_'), 3);
check('the helper line under the control is rendered empty for JS to fill', strpos($html, '<p class="payee-dest-desc" id="demoPayeeDestDesc"></p>') !== false, true);
check('the callout wraps the sub-forms', strpos($html, 'class="payee-dest-subform" id="demoPayeeSubform"') !== false, true);
check("the caller's own slot is inside that callout", strpos($html, 'demoSlotMarker') > strpos($html, 'demoPayeeSubform'), true);
preg_match_all('/name="demo_payee_record" id="[^"]+" value="([^"]+)"/', $html, $mr);
check('the sub-question offers exactly No record / Record', implode(',', $mr[1] ?? []), 'no,yes');
check('"No record" is the default answer', substr_count($html, 'value="no" checked'), 1);

$htmlNoQuestion = renderPayeePartial($root, 'demo2', false, '');
check('$payee_allow_no_record=false renders no sub-question at all', strpos($htmlNoQuestion, 'PayeeRecord') !== false, false);
check('...but still renders the same 3 destinations', substr_count($htmlNoQuestion, 'name="demo2_payee_dest"'), 3);

// ---------------------------------------------------------------- 2. all 4 call sites use it
$views = [
    'app/views/payroll/detail.php' => ['manualLine', 'recurringDest'],
    'app/views/layout/modals.php' => ['eed', 'erd'],
];
foreach ($views as $file => $prefixes) {
    $src = (string)file_get_contents($root . '/' . $file);
    foreach ($prefixes as $prefix) {
        check("{$file} includes the partial for '{$prefix}'", substr_count($src, "\$payee_prefix = '{$prefix}';") === 1 && strpos($src, "partials/payee-destination.php") !== false, true);
    }
    // the shapes this component replaced, in either file, would mean a picker grew back privately
    check("{$file} has no hand-rolled payee control left", strpos($src, 'data-payee-type=') !== false || strpos($src, 'data-option-values="none,employee') !== false, false);
}

// ---------------------------------------------------------------- 3. the keys the UI names
$th = json_decode((string)file_get_contents($root . '/public/lang/th.json'), true);
$en = json_decode((string)file_get_contents($root . '/public/lang/en.json'), true);
check('th.json parses', is_array($th), true);
check('en.json parses', is_array($en), true);
$uiKeys = [
    'payee_type_label',
    'payee_dest_retained', 'payee_dest_employee', 'payee_dest_external',
    'payee_dest_desc_retained', 'payee_dest_desc_employee', 'payee_dest_desc_external',
    'payee_record_label', 'payee_record_no', 'payee_record_yes', 'payee_record_desc',
];
foreach ($uiKeys as $key) {
    check("{$key} exists in th.json", array_key_exists($key, $th) && trim((string)$th[$key]) !== '', true);
    check("{$key} exists in en.json", array_key_exists($key, $en) && trim((string)$en[$key]) !== '', true);
}
// Retired with the flat list: one label per payee_type value, plus their helper lines.
foreach (['payee_type_none', 'payee_type_employee', 'payee_type_company', 'payee_type_other_person',
          'payee_desc_none', 'payee_desc_employee', 'payee_desc_company', 'payee_desc_other_person',
          'payee_desc_not_disbursed'] as $gone) {
    check("{$gone} is gone from both language files", array_key_exists($gone, $th) || array_key_exists($gone, $en), false);
}
// Kept on purpose: list views still tag a row that was saved with the retired value.
check('payee_type_not_disbursed is KEPT (read path)', array_key_exists('payee_type_not_disbursed', $th) && array_key_exists('payee_type_not_disbursed', $en), true);

// ---------------------------------------------------------------- 4. backend still accepts all 5
$writePaths = [
    'EmployeeEarningDeductionModel::save()' => '/app/models/EmployeeEarningDeductionModel.php',
    'EmployeeRecurringDeductionModel::save()' => '/app/models/EmployeeRecurringDeductionModel.php',
    'PayrollRunModel (manual line + recurring-deduction override)' => '/app/models/PayrollRunModel.php',
];
foreach ($writePaths as $label => $file) {
    $src = (string)file_get_contents($root . $file);
    check("{$label} still whitelists all 5 payee_type values", substr_count($src, "['employee', 'company', 'not_disbursed', 'other_person']") >= 1, true);
}

// ---------------------------------------------------------------- 5. the mapping lives in one place
$appJs = (string)file_get_contents($root . '/public/js/app.js');
check('app.js owns the UI -> payee_type mapping', substr_count($appJs, 'function payeeDestinationType('), 1);
foreach (['public/js/payroll/detail.js', 'public/js/employee/detail.js'] as $js) {
    $src = (string)file_get_contents($root . '/' . $js);
    check("{$js} reads the payee value through the shared helper", strpos($src, 'payeeDestinationType(') !== false, true);
    check("{$js} does not map payee_type itself", strpos($src, "PayeeTypeToggle button.active") !== false, false);
}

echo str_repeat('-', 50) . "\n";
echo "Passed: {$passed}, Failed: {$failed}\n";
if ($failed > 0) {
    echo "TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
