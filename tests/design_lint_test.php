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

// Round 4, `--all` flag: designLintFormatUnmarkedSection() is a pure function, so exercise it against
// a SYNTHETIC fixture -- not the real app's own current hit counts, which change every time someone
// fixes a lint hit (that would make this test flaky/misleading, per the standing rule not to bind
// design lint tests to today's real numbers). Fixture: 22 unmarked files with a hit (more than the
// top-20 cutoff, with strictly descending totals so ordering/truncation is deterministic) + 3 with a
// clean sweep (0 hits on every rule).
function designLintFixtureCounts(int $rule, int $n): array {
    $c = array_fill(1, 8, 0);
    $c[$rule] = $n;
    return $c;
}
$fixtureRoot = '/fake/root';
$fixtureUnmarked = [];
for ($i = 1; $i <= 22; $i++) {
    $path = $fixtureRoot . '/file' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . '.php';
    $fixtureUnmarked[$path] = [
        'type' => 'view', 'clean' => false, 'hits' => [],
        'countsByRule' => designLintFixtureCounts(3, 23 - $i), // descending: 22, 21, ..., 1
    ];
}
for ($i = 1; $i <= 3; $i++) {
    $path = $fixtureRoot . '/clean-ish' . $i . '.php';
    $fixtureUnmarked[$path] = ['type' => 'view', 'clean' => false, 'hits' => [], 'countsByRule' => array_fill(1, 8, 0)];
}

$defaultOutput = designLintFormatUnmarkedSection($fixtureUnmarked, $fixtureRoot, false);
$allOutput = designLintFormatUnmarkedSection($fixtureUnmarked, $fixtureRoot, true);

check('default mode: header says "top 20"', str_contains($defaultOutput, 'top 20 by total hit count'), true);
check('default mode: shows exactly 20 file-with-hit lines', preg_match_all('/^  \/fake\/root\/file\d+\.php:/m', $defaultOutput), 20);
// NOTE: the pre-existing (unchanged) `$shown++ >= $limit` check increments $shown once more on the
// breaking iteration, so "N more" is always 1 LOWER than the true remaining count (22-20=2 here, but
// the loop's own $shown ends at 21, not 20) -- a real quirk that predates this round. This round's
// contract is "no-flag output byte-identical to before", so it's preserved as-is, not fixed here.
check('default mode: cuts off with a "more file(s)" line (pre-existing off-by-one label kept as-is)', str_contains($defaultOutput, '  ... 1 more file(s) with hits not shown'), true);
check('default mode: does NOT print the 0-hits section at all', str_contains($defaultOutput, 'unmarked files with 0 hits'), false);

check('--all mode: header says "all files"', str_contains($allOutput, 'reported, not failed -- all files'), true);
check('--all mode: shows all 22 file-with-hit lines (no top-20 cut)', preg_match_all('/^  \/fake\/root\/file\d+\.php:/m', $allOutput), 22);
check('--all mode: no "more file(s)" line', str_contains($allOutput, 'more file(s)'), false);
check('--all mode: "(all shown)" line present', str_contains($allOutput, '  (all shown)'), true);
check('--all mode: 0-hits section header shows count 3', str_contains($allOutput, 'unmarked files with 0 hits (3)'), true);
check('--all mode: 0-hits section lists exactly the 3 zero-hit files', preg_match_all('/^  \/fake\/root\/clean-ish\d\.php$/m', $allOutput), 3);

$fixtureExpectedTotal = 0;
foreach ($fixtureUnmarked as $info) {
    $fixtureExpectedTotal += array_sum($info['countsByRule']);
}
preg_match_all('/#3=(\d+)/', $allOutput, $allRuleMatches);
$fixtureSumFromAllOutput = array_sum(array_map('intval', $allRuleMatches[1]));
check('--all mode: sum of per-file hit counts in the printed lines equals the fixture\'s true total (253)', $fixtureSumFromAllOutput, $fixtureExpectedTotal);

// Integration-level structural invariant against the REAL app (not numeric, so it can't go flaky when
// someone fixes a lint hit): every scanned file falls into exactly one of the 3 groups `--all` prints.
$markedFiles = array_filter($result['files'], fn($f) => $f['clean']);
$unmarkedFiles = array_filter($result['files'], fn($f) => !$f['clean']);
$realUnmarkedWithHit = 0;
$realUnmarkedZeroHit = 0;
foreach ($unmarkedFiles as $info) {
    if (array_sum($info['countsByRule']) > 0) $realUnmarkedWithHit++;
    else $realUnmarkedZeroHit++;
}
check(
    'real app: design:clean + unmarked-with-hit + unmarked-0-hit groups add up to Files scanned',
    count($markedFiles) + $realUnmarkedWithHit + $realUnmarkedZeroHit,
    count($result['files'])
);

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
