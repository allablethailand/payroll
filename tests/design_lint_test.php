<?php
/**
 * docs/design/rules.md §12, Round 2 item 8: wires scripts/check-design.php's own designLintRun()
 * into the test suite -- fail if any file marked `design:clean` still has a lint hit. Requires that
 * file directly rather than re-implementing the scan here (this project's own "generalize, don't
 * mirror-copy" rule, same pattern tests/lang_check_test.php already uses for scripts/check-lang.php)
 * -- the CLI-only report/exit block at the bottom of that file is guarded to skip when required like
 * this.
 *
 * Also asserts, by name, that the specific files THIS round was told to mark clean actually carry
 * the marker AND actually pass -- not just "whatever happens to be marked passes" (a file silently
 * losing its marker, e.g. from a careless copy/paste of its own top comment, would otherwise make
 * this test suite quietly stop checking it at all, which is a worse failure mode than a normal lint
 * hit: it fails silent, not loud).
 *
 * No DB needed. Run with: php tests/design_lint_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../scripts/check-design.php';

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

$root = dirname(__DIR__);
$result = designLintRun($root);

// designLintCollectFiles() stores paths with the OS's own native separator (backslashes on Windows,
// via SplFileInfo::getPathname()) -- normalize to forward slashes for lookup below so this test
// doesn't depend on which OS it runs on to find its own files.
$filesByNormalizedPath = [];
foreach ($result['files'] as $path => $info) {
    $filesByNormalizedPath[str_replace('\\', '/', $path)] = $info;
}

check('no design:clean file has any lint hit (overall)', $result['failed'], []);

// The exact file list Round 2 item 8 was told to mark clean and make genuinely pass.
$mustBeCleanAndPassing = [
    'docs/design/components.php',
    'app/views/partials/callout.php',
    'app/views/partials/emp-header-card.php',
    'app/views/partials/empty-state.php',
    'app/views/partials/filter-bar.php',
    'app/views/partials/page-header.php',
    'app/views/partials/stat-card.php',
    'app/views/partials/status-stepper.php',
    'app/views/partials/status-tabs.php',
    'app/views/partials/timeline.php',
    'public/css/tokens.css',
];

foreach ($mustBeCleanAndPassing as $rel) {
    $path = $root . '/' . $rel;
    check("$rel exists", is_file($path), true);
    if (!is_file($path)) continue;
    $info = $filesByNormalizedPath[str_replace('\\', '/', $path)] ?? null;
    check("$rel was scanned (collected by designLintCollectFiles())", $info !== null, true);
    if ($info === null) continue;
    check("$rel carries the design:clean marker", $info['clean'], true);
    check("$rel has 0 lint hits", array_sum($info['countsByRule']), 0);
}

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
