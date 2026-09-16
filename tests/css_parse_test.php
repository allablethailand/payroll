<?php
/**
 * 2026-09-14, explicit request, follow-up to a REAL bug found while verifying the page-loader's
 * dark-mode token (see public/css/tokens.css's own dated comment on the fix): a token-name
 * wildcard list in a CSS comment happened to contain an asterisk immediately followed by a slash,
 * with nothing between them -- that exact 2-character combination is how a CSS comment ENDS, so it
 * closed the comment early mid-sentence, silently corrupting the parse of the very next rule
 * (`[data-bs-theme="dark"] { ... }`, discarded entirely by every browser's own error recovery).
 * No existing test caught this -- `php -l` only validates PHP, not the CSS payload inside a .css
 * file, and scripts/check-design.php's own lint never tokenizes comments/strings at all (it scans
 * whole lines for hex/class-name patterns, see designLintRule1()'s own docblock).
 *
 * This is a from-scratch, dependency-free CSS tokenizer (no `css`/`postcss`/etc. package exists in
 * node_modules -- checked before writing this) that tracks comment state (open/close markers),
 * string state (quoted values), and brace depth character-by-character.
 *
 * IMPORTANT, found while writing this test (verified by deliberately reintroducing the exact bug
 * and re-running): counting top-level `{...}` BLOCKS alone is NOT enough to catch this bug class.
 * When a comment closes early, the corrupted stretch of "now not a comment" text can still contain
 * a `{`/`}` pair of its own further down (the comment's real closing marker plus the next real
 * rule's own braces) -- the resulting COUNT can come out numerically correct by coincidence even though the
 * content is completely wrong, exactly what happened here: with the bug reintroduced, this
 * tokenizer's own brace-count still said 3, matching the "expected" number for entirely the wrong
 * reason. So this tokenizer also records each top-level rule's own SELECTOR text (everything
 * accumulated at depth 0 since the previous `}`, trimmed) -- that's what actually changes when a
 * comment corrupts a selector (it stops being `[data-bs-theme="dark"]` and becomes a huge blob of
 * garbled prose instead), and it's what this test actually asserts against for tokens.css.
 *
 * tokens.css gets an EXACT selector-list assertion (`:root`, `[data-bs-theme="dark"]`,
 * `@media (prefers-color-scheme: dark)`, in that order) -- a small, deliberately fixed-shape file
 * where "the selectors changed" is itself worth failing loudly on. style.css does NOT get a
 * selector-list assertion (it has ~1,483 rules today and gains more every round of design work) --
 * it only asserts the file parses cleanly at all (no unterminated comment/string, no brace
 * mismatch), which is the generic half of this regression class.
 *
 * Run with: php tests/css_parse_test.php
 */
declare(strict_types=1);

/**
 * @return array{count:int, selectors:string[], error:?string} count = number of top-level rule
 *   blocks. selectors = each top-level rule's own selector text (trimmed, whitespace collapsed to
 *   single spaces so a selector wrapped across source lines still compares cleanly), in source
 *   order. error = null on a clean parse, otherwise a message naming the first problem found
 *   (unterminated comment/string, or a `}` with no matching `{`).
 */
function cssParseCheck(string $css): array {
    $len = strlen($css);
    $i = 0;
    $depth = 0;
    $topLevelCount = 0;
    $selectors = [];
    $selectorBuf = '';
    $inComment = false;
    $inString = null; // null, or the quote character currently open
    $line = 1;

    while ($i < $len) {
        $ch = $css[$i];
        if ($ch === "\n") {
            $line++;
        }

        if ($inComment) {
            if ($ch === '*' && $i + 1 < $len && $css[$i + 1] === '/') {
                $inComment = false;
                $i += 2;
                continue;
            }
            $i++;
            continue;
        }

        if ($inString !== null) {
            if ($depth === 0) {
                $selectorBuf .= $ch;
            }
            if ($ch === '\\') {
                if ($depth === 0 && $i + 1 < $len) {
                    $selectorBuf .= $css[$i + 1];
                }
                $i += 2; // skip escaped char, whatever it is
                continue;
            }
            if ($ch === $inString) {
                $inString = null;
            }
            $i++;
            continue;
        }

        // Not in a comment or string -- normal CSS territory.
        if ($ch === '/' && $i + 1 < $len && $css[$i + 1] === '*') {
            $inComment = true;
            $i += 2;
            continue;
        }
        if ($ch === '"' || $ch === "'") {
            $inString = $ch;
            if ($depth === 0) {
                $selectorBuf .= $ch;
            }
            $i++;
            continue;
        }
        if ($ch === '{') {
            if ($depth === 0) {
                $topLevelCount++;
                $selectors[] = trim(preg_replace('/\s+/', ' ', $selectorBuf));
                $selectorBuf = '';
            }
            $depth++;
            $i++;
            continue;
        }
        if ($ch === '}') {
            if ($depth === 0) {
                return ['count' => $topLevelCount, 'selectors' => $selectors, 'error' => "unmatched '}' at line {$line} (no corresponding '{' -- likely a comment that closed too early somewhere before this point)"];
            }
            $depth--;
            if ($depth === 0) {
                $selectorBuf = ''; // start fresh for whatever top-level rule comes next
            }
            $i++;
            continue;
        }
        if ($depth === 0) {
            $selectorBuf .= $ch;
        }
        $i++;
    }

    if ($inComment) {
        return ['count' => $topLevelCount, 'selectors' => $selectors, 'error' => "unterminated /* comment (never found a closing */ before end of file) -- or a stray '*/' earlier closed a DIFFERENT comment early, leaving this open one unclosed"];
    }
    if ($inString !== null) {
        return ['count' => $topLevelCount, 'selectors' => $selectors, 'error' => "unterminated {$inString}-quoted string (never found its closing quote before end of file)"];
    }
    if ($depth !== 0) {
        return ['count' => $topLevelCount, 'selectors' => $selectors, 'error' => "brace depth ended at {$depth}, not 0 -- {$depth} unclosed '{' block(s)"];
    }
    return ['count' => $topLevelCount, 'selectors' => $selectors, 'error' => null];
}

/**
 * 2026-09-16, SECOND real instance of the exact bug class this file was written for, found in
 * style.css's own shared-popover block: an asterisk immediately followed by a slash, in the middle
 * of a token-name list in prose, closed that comment mid-sentence -- so the whole
 * `.popover { --bs-popover-... }` rule after it was never applied, and every popover in the app had
 * been rendering at Bootstrap's own defaults (0.875rem text, default colors) instead of this app's
 * tokens ever since that comment was written.
 *
 * The existing assertions could not catch it: an early close leaves the file BALANCED (the comment's
 * real closing marker closes nothing, and the prose in between eats no braces), and style.css is far
 * too big for a fixed selector-list assertion like tokens.css gets.
 *
 * What is always true, though: a comment-CLOSING marker that appears while NOT inside a comment is
 * meaningless CSS. A real selector/declaration never contains one, so every occurrence is, by
 * construction, the leftover marker of a comment something already closed early. That is what this
 * finds -- deterministically, no heuristics, no length guessing.
 *
 * @return int[] 1-based line numbers of every stray closing marker
 */
function cssStrayCommentCloses(string $css): array {
    $len = strlen($css);
    $i = 0;
    $line = 1;
    $inComment = false;
    $inString = null;
    $stray = [];
    while ($i < $len) {
        $ch = $css[$i];
        if ($ch === "\n") { $line++; $i++; continue; }
        if ($inComment) {
            if ($ch === '*' && $i + 1 < $len && $css[$i + 1] === '/') { $inComment = false; $i += 2; continue; }
            $i++;
            continue;
        }
        if ($inString !== null) {
            if ($ch === '\\') { $i += 2; continue; }
            if ($ch === $inString) { $inString = null; }
            $i++;
            continue;
        }
        if ($ch === '/' && $i + 1 < $len && $css[$i + 1] === '*') { $inComment = true; $i += 2; continue; }
        if ($ch === '"' || $ch === "'") { $inString = $ch; $i++; continue; }
        if ($ch === '*' && $i + 1 < $len && $css[$i + 1] === '/') { $stray[] = $line; $i += 2; continue; }
        $i++;
    }
    return $stray;
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
        $actualStr = is_string($actual) ? $actual : var_export($actual, true);
        $expectedStr = is_string($expected) ? $expected : var_export($expected, true);
        echo "  FAIL  {$label} (expected {$expectedStr}, got {$actualStr})\n";
    }
}
// A selector string over ~120 chars can only be a garbled multi-line prose blob that accidentally
// swallowed a real rule boundary -- no real selector in this codebase is anywhere near that long.
function checkSelectorNotGarbled(string $label, string $selector): void {
    global $failures, $passes;
    if (strlen($selector) <= 120) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} (selector is " . strlen($selector) . " chars, looks like corrupted/garbled prose, not a real selector -- starts: \"" . substr($selector, 0, 80) . "...\")\n";
    }
}

define('CLEAN_FIXTURE', "/" . "* plain --sp-x, --fs-y comment *" . "/
:root { --a: 1; }
");
define('STRING_FIXTURE', '.x::after { content: "*' . '/"; }' . "
");

$tokensPath = __DIR__ . '/../public/css/tokens.css';
$stylePath = __DIR__ . '/../public/css/style.css';

$tokensCss = file_get_contents($tokensPath);
$tokensResult = cssParseCheck($tokensCss);
check('tokens.css parses cleanly (no unterminated comment/string, balanced braces)', $tokensResult['error'], null);
check(
    'tokens.css has exactly these 3 top-level selectors, in order (a corrupted comment would garble one of these into a huge prose blob instead)',
    $tokensResult['selectors'],
    [':root', '[data-bs-theme="dark"]', '@media (prefers-color-scheme: dark)']
);
foreach ($tokensResult['selectors'] as $idx => $sel) {
    checkSelectorNotGarbled("tokens.css rule #{$idx} selector is not a garbled prose blob", $sel);
}

check('tokens.css has no stray comment-close (nothing closed a comment early before it)', cssStrayCommentCloses($tokensCss), []);

$styleCss = file_get_contents($stylePath);
$styleResult = cssParseCheck($styleCss);
check('style.css parses cleanly (no unterminated comment/string, balanced braces)', $styleResult['error'], null);
check('style.css has no stray comment-close (nothing closed a comment early before it)', cssStrayCommentCloses($styleCss), []);

// Sanity checks on the tokenizer itself -- a test that could never fail isn't testing anything.
// These use small inline fixtures, not the real files, so they don't depend on app state.
// This fixture reproduces the EXACT bug shape found in tokens.css: a long comment whose prose
// contains an accidental early-close, followed by more prose (with its own genuine closing */)
// before the real rule -- deliberately similar in shape/length to what tripped up the plain
// brace-count check during development of this very test (see this file's own docblock above).
$brokenCommentFixture = "/* explains several things -- --sp-*/--fs-* are spacing/font tokens, "
    . "unrelated to color, not covered below. Also explains selector conventions in more prose "
    . "that goes on for a while about various states and cases before finally wrapping up here. */\n"
    . "[data-bs-theme=\"dark\"] { --x: 1; }\n";
$brokenResult = cssParseCheck($brokenCommentFixture);
check('fixture: a stray */ inside a comment does not throw a fatal error (tokenizer stays in sync)', $brokenResult['error'], null);
check('fixture: a stray */ inside a comment corrupts the NEXT selector into a long garbled blob, not a clean one', strlen($brokenResult['selectors'][0] ?? '') > 120, true);

// The fixture's prose is all on one line, so its own real closing marker -- the one left stranded
// outside a comment by the early close -- is on line 1 too.
check('fixture: the stray-close detector flags the early-close bug shape, by line', cssStrayCommentCloses($brokenCommentFixture), [1]);
check('fixture: a clean comment is not flagged', cssStrayCommentCloses(CLEAN_FIXTURE), []);
check('fixture: a closing marker inside a quoted string is not flagged', cssStrayCommentCloses(STRING_FIXTURE), []);

$unterminatedFixture = "/* this comment never closes\n:root { --x: 1; }\n";
$unterminatedResult = cssParseCheck($unterminatedFixture);
check('fixture: a genuinely unterminated comment is detected as an error', $unterminatedResult['error'] !== null, true);

$balancedFixture = ":root { --a: 1; }\n[data-bs-theme=\"dark\"] { --a: 2; }\n@media (prefers-color-scheme: dark) { :root { --a: 3; } }\n";
$balancedResult = cssParseCheck($balancedFixture);
check('fixture: 3 clean top-level rules parse with the exact expected selectors', $balancedResult, [
    'count' => 3,
    'selectors' => [':root', '[data-bs-theme="dark"]', '@media (prefers-color-scheme: dark)'],
    'error' => null,
]);

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
