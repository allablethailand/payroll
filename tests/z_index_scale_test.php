<?php
/**
 * 2026-09-16, rules.md §1 "Layering": z-index lives in ONE ordered scale in tokens.css, and every
 * floating layer reads its level from there.
 *
 * The bug this came out of could not be seen by reading either file alone: `.swal2-container`
 * shipped at 1060, which happens to clear a single modal (1055) -- so every confirm in the app
 * looked fine -- and sits BELOW a modal stacked on another modal (1085), so the first nested modal
 * the app ever grew made its own confirm dialog render underneath itself, unclickable.
 *
 * What this locks:
 *  1. the scale exists, in tokens.css, complete, and in the right ORDER -- an out-of-order value is
 *     the whole class of bug, not a typo;
 *  2. the layers that matter actually consume it (Bootstrap through its own per-component custom
 *     property, SweetAlert2 through a declaration on the rendered class);
 *  3. no one has put a raw z-index back on those components, in CSS or in JS.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. No DB access at all.
 * Run with: php tests/z_index_scale_test.php
 */
declare(strict_types=1);

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

$tokens = file_get_contents(__DIR__ . '/../public/css/tokens.css');
$css = file_get_contents(__DIR__ . '/../public/css/style.css');
$appJs = file_get_contents(__DIR__ . '/../public/js/app.js');

echo "=== 1. the scale is complete and ordered ===\n";
// Read the declared values straight out of tokens.css rather than restating them here -- the point
// is their ORDER, not the specific numbers, which are free to move as long as they stay ordered.
$order = ['--z-page', '--z-modal-backdrop', '--z-modal', '--z-modal-nested-backdrop', '--z-modal-nested', '--z-modal-overlay', '--z-swal', '--z-toast'];
$values = [];
foreach ($order as $name) {
    if (preg_match('/^\s*' . preg_quote($name, '/') . '\s*:\s*(\d+)\s*;/m', $tokens, $m)) {
        $values[$name] = (int)$m[1];
    }
}
foreach ($order as $name) {
    checkTrue("{$name} is declared in tokens.css", isset($values[$name]));
}
$sorted = array_values($values);
$expectedSorted = $sorted;
sort($expectedSorted);
check('every level is strictly above the one below it', $sorted, $expectedSorted);
check('no two layers share a level', count(array_unique($sorted)), count($sorted));
// The 3 relationships the real bug turned on, stated as themselves so a future reorder has to face
// them rather than just keep the list sorted.
checkTrue('a dialog clears a modal stacked on a modal', ($values['--z-swal'] ?? 0) > ($values['--z-modal-nested'] ?? 0));
checkTrue('an overlay opened inside a modal clears a stacked modal', ($values['--z-modal-overlay'] ?? 0) > ($values['--z-modal-nested'] ?? 0));
// The page has to go UNDER the dim -- that is what a backdrop is for.
checkTrue('everything on the page sits below the backdrop', ($values['--z-page'] ?? 0) < ($values['--z-modal-backdrop'] ?? 0));
checkTrue('a toast clears the dialog', ($values['--z-toast'] ?? 0) > ($values['--z-swal'] ?? 0));
checkTrue('a modal clears its own backdrop', ($values['--z-modal'] ?? 0) > ($values['--z-modal-backdrop'] ?? 0));
checkTrue('and a stacked modal clears its own', ($values['--z-modal-nested'] ?? 0) > ($values['--z-modal-nested-backdrop'] ?? 0));
// Dark mode never redeclares these -- a layer order that changed with the theme would be absurd.
$darkStart = strpos($tokens, '[data-bs-theme="dark"]');
checkTrue('the scale is not redeclared per theme',
    $darkStart === false || strpos($tokens, '--z-modal', (int)$darkStart) === false);

echo "\n=== 2. the layers consume it ===\n";
// Bootstrap hardcodes these on the component, not on :root -- same per-component override its
// colours need (rules.md §4).
checkTrue('.modal takes --z-modal', preg_match('/\.modal\s*\{[^}]*--bs-modal-zindex:\s*var\(--z-modal,/s', $css) === 1);
checkTrue('.modal-backdrop takes --z-modal-backdrop', preg_match('/\.modal-backdrop\s*\{[^}]*--bs-backdrop-zindex:\s*var\(--z-modal-backdrop,/s', $css) === 1);
checkTrue('.popover takes --z-modal-overlay', preg_match('/\.popover\s*\{[^}]*--bs-popover-zindex:\s*var\(--z-modal-overlay/s', $css) === 1);
// select2 ships ONE z-index for its dropdown; it needs two levels, or a page select2 floats over the
// dim while a modal is open.
checkTrue('select2 on the page takes --z-page', preg_match('/\.select2-container--bootstrap-5 \.select2-dropdown \{\s*z-index:\s*var\(--z-page/s', $css) === 1);
checkTrue('select2 inside a modal takes --z-modal-overlay', preg_match('/\.modal \.select2-container--bootstrap-5[^{]*\{\s*z-index:\s*var\(--z-modal-overlay/s', $css) === 1);
// A modal and its backdrop must never read the same token, or the dim lands on top of the modal.
checkTrue('.modal and .modal-backdrop take different tokens',
    strpos($css, '--bs-modal-zindex: var(--z-modal,') !== false
    && strpos($css, '--bs-backdrop-zindex: var(--z-modal-backdrop,') !== false);
// An undefined token makes a z-index INVALID (-> auto), not merely different -- which unstacks the
// element completely. Every use carries the literal as a fallback so that failure is survivable.
foreach (['--z-modal, 1055', '--z-modal-backdrop, 1050', '--z-modal-overlay, 1090', '--z-page, 1045', '--z-swal, 1100', '--z-toast, 1110'] as $withFallback) {
    checkTrue("var({$withFallback}) carries its literal fallback", strpos($css, 'var(' . $withFallback . ')') !== false);
}

echo "\n=== 2b. a versioned stylesheet never depends on an unversioned one ===\n";
// The whole regression: style.css is requested as style.css?v=<filemtime>, tokens.css was pulled in
// from inside it by a plain @import with no version -- so a browser kept an old tokens.css next to a
// new style.css, and every token added that day was simply undefined.
// The at-rule itself, not the words: this file's own top comment explains the removal by name.
checkTrue('style.css no longer @imports tokens.css', preg_match('/^\s*@import\s+url\("tokens\.css"\)/m', $css) !== 1);
$header = file_get_contents(__DIR__ . '/../app/views/layout/header.php');
checkTrue('header.php loads tokens.css through asset()', strpos($header, "asset('public/css/tokens.css')") !== false);
checkTrue('...before style.css', strpos($header, "asset('public/css/tokens.css')") < strpos($header, "asset('public/css/style.css')"));
$components = file_get_contents(__DIR__ . '/../docs/design/components.php');
checkTrue('the components page loads it the same way', strpos($components, "tokens.css?v=") !== false);
// SweetAlert2 injects its own stylesheet at load; only a declaration on the rendered class survives
// it (docs/decisions/swal2-css-override.md) -- never :root, never !important.
checkTrue('.swal2-container takes --z-swal', preg_match('/\.swal2-container\s*\{\s*z-index:\s*var\(--z-swal,[^)]*\);\s*\}/s', $css) === 1);
checkTrue('its toast mode takes --z-toast', preg_match('/\.swal2-container\.swal2-toast-shown\s*\{\s*z-index:\s*var\(--z-toast,[^)]*\);\s*\}/s', $css) === 1);
checkTrue('no --swal2-* z-index went back onto :root', strpos($css, '--swal2-container-z-index') === false);
foreach (['z-index: var(--z-swal)', 'z-index: var(--z-toast)'] as $decl) {
    checkTrue("`{$decl}` needs no !important", strpos($css, $decl . ' !important') === false);
}

echo "\n=== 2c. nothing on the page outranks the backdrop ===\n";
// Page chrome (sidebar, help drawer, navbar, sticky columns) has to go UNDER the dim -- that is the
// whole point of a backdrop. Two of these used to sit at a bare 1050, tied with the backdrop itself
// and resolved only by DOM order.
$backdropLevel = $values['--z-modal-backdrop'] ?? 1050;
$offenders = [];
foreach (explode("\n", $css) as $i => $line) {
    if (preg_match('/^\s*z-index:\s*(\d{4});/', $line, $m) && (int)$m[1] >= $backdropLevel) {
        $offenders[] = ($i + 1) . ': ' . trim($line);
    }
}
check('no bare z-index in style.css reaches the backdrop level', $offenders, []);

echo "\n=== 3. nothing writes a raw level any more ===\n";
$stackStart = strpos($appJs, "document.querySelectorAll('.modal.show').length");
$stackBody = substr($appJs, (int)$stackStart - 400, 1200);
checkTrue('the stacked modal is marked before it is shown, not after',
    strpos($stackBody, "\$(document).on('show.bs.modal'") !== false
    && strpos($stackBody, "this.classList.add('modal-nested')") !== false
    && strpos($appJs, "this.classList.remove('modal-nested')") !== false);
checkTrue('and app.js writes no level itself', strpos($stackBody, 'style.zIndex') === false
    && strpos($stackBody, '1055 + stackLevel') === false);
// Both levels are CSS, so they hold from the first painted frame instead of from a callback.
checkTrue('the stacked modal takes --z-modal-nested in CSS',
    preg_match('/\.modal\.modal-nested\s*\{\s*z-index:\s*var\(--z-modal-nested\)/s', $css) === 1);
checkTrue('its backdrop takes --z-modal-nested-backdrop, matched structurally',
    preg_match('/\.modal-backdrop\s*~\s*\.modal-backdrop\s*\{\s*z-index:\s*var\(--z-modal-nested-backdrop\)/s', $css) === 1);
foreach (['1060', '1065', '1075', '1085'] as $raw) {
    checkTrue("no bare {$raw} is assigned as a z-index in app.js",
        preg_match('/zIndex\s*=\s*[\'"]?' . $raw . '/', $appJs) !== 1);
}

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
