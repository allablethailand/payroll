<?php
/**
 * Smoke test for app/helpers/helpers.php -- explicit instruction, guarding against the exact class
 * of bug found and fixed twice in the same session while writing this file: a literal PHP short-echo
 * open tag followed by its own closing delimiter, typed as plain text inside a `//` comment (once
 * describing how layout/header.php injects status_map.php's data, once re-describing that same bug
 * while explaining it). PHP's lexer switches out of PHP mode the instant it sees that 2-character
 * sequence ANYWHERE in the file -- comments included -- silently turning every line after it, for
 * the rest of the file, into raw HTML output instead of parsed code. `php -l` does NOT catch this
 * (switching in/out of PHP mode is valid syntax by design) -- only an actual execution/function-
 * existence check does, which is exactly what this file is for. No DB needed.
 *
 * Run with: php tests/helpers_smoke_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/helpers.php';

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

// Every function actually declared in app/helpers/helpers.php today (confirmed by grepping the file
// directly, not guessed/copied from memory) -- if a stray closing tag anywhere above one of these
// broke parsing, function_exists() for it (and everything declared after it) comes back false here.
// NOTE: fmtMoney() is §8's own PLANNED helper (item 7, not built yet) -- deliberately NOT asserted
// here, since asserting a function that doesn't exist yet would make this smoke test permanently red
// instead of a real regression signal; add it to this list when item 7 actually ships it.
$expectedFunctions = [
    'assetVersion',
    'asset',
    'session_kill_response',
    'ensure_login',
    'getCompId',
    'loadStatusMap',
    'statusMapEntry',
    'statusEnLabelFallback',
    'statusBadge',
];
foreach ($expectedFunctions as $fn) {
    check("function_exists('{$fn}')", function_exists($fn), true);
}

// loadStatusMap() vs. a SECOND, independent `require` of the same file (plain `require`, not
// `require_once` -- re-executes every call, no include-cache to worry about) -- cross-checks that
// what the cached helper returns is genuinely the whole file's real content, not a truncated/empty
// array left over from a parse failure partway through app/config/status_map.php itself.
$mapViaHelper = loadStatusMap();
$mapDirect = require dirname(__DIR__) . '/app/config/status_map.php';
check('loadStatusMap() returns a non-empty array', count($mapViaHelper) > 0, true);
check('loadStatusMap() context count matches status_map.php required directly', count($mapViaHelper), count($mapDirect));
check('loadStatusMap() context keys match status_map.php required directly', array_keys($mapViaHelper), array_keys($mapDirect));

// Every context this round's own status_map.php shipped with (docs/design/rules.md §5's own table) --
// fails loudly if a future edit accidentally drops one instead of just changing its contents.
$expectedContexts = [
    'run_state', 'payroll_process_tab', 'approval_status', 'payslip_request_status',
    'employment_certificate_request_status', 'employee_status', 'employment_status',
    'document_delivery_status', 'sync_batch_status', 'payroll_calc_status', 'eed_status',
    'eed_installment_status', 'remittance_status', 'attendance_status',
    'recurring_earning_status', 'data_source',
];
sort($expectedContexts);
$actualContexts = array_keys($mapViaHelper);
sort($actualContexts);
check('loadStatusMap() has exactly the expected context list', $actualContexts, $expectedContexts);

// statusMapEntry()/statusBadge() actually CALLED (not just declared) -- the strongest possible proof
// the file parsed all the way through, since both are the LAST functions in the file, right where
// the real bug this test guards against actually broke it.
$entry = statusMapEntry('pending_approval', 'run_state');
check('statusMapEntry() finds a known enum/context pair', $entry['tone'] ?? null, 'warning');
check('statusMapEntry() returns null for an unknown enum', statusMapEntry('bogus_value', 'run_state'), null);

$badge = statusBadge('pending_approval', 'run_state');
check('statusBadge() output contains the right tone class', str_contains($badge, 'badge-warning'), true);
check('statusBadge() output carries the data-badge="status" marker (§12 lint rule #8)', str_contains($badge, 'data-badge="status"'), true);
check('statusBadge() output carries data-i18n for a known key', str_contains($badge, 'data-i18n="state_pending_approval"'), true);

$unmappedBadge = statusBadge('bogus_value', 'run_state');
check('statusBadge() falls back to neutral for an unmapped enum', str_contains($unmappedBadge, 'badge-neutral'), true);
check('statusBadge() unmapped fallback still carries data-badge="status"', str_contains($unmappedBadge, 'data-badge="status"'), true);

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
