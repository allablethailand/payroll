<?php
/**
 * Central test runner: runs every tests/*_test.php AND tests/*_test.js, and tallies them ONE
 * way -- by reading each file's own canonical summary line, `Passed: <n>, Failed: <n>`, off
 * its stdout. The interpreter comes from the extension: php for .php, node for .js.
 *
 * There is deliberately no second counting strategy and no exit-code fallback: a file that does
 * not print that line is reported as NO SUMMARY and fails the run, so the fix lands in the test
 * file (give it the line) rather than in guesswork here.
 *
 * Usage:
 *   php tests/run_all.php                     run everything, print the table, write .last-run.json
 *   php tests/run_all.php --compare <json>    run everything, then print ONLY the files whose
 *                                             pass/fail differs from that baseline json
 *
 * Exit code: 0 when every file reported a summary and no file reported a failure, else 1.
 */
declare(strict_types=1);

const SUMMARY_PATTERN = '/^Passed: (\d+), Failed: (\d+)\s*$/m';
const OUT_FILE = __DIR__ . '/.last-run.json';

$compareWith = null;
$argvRest = array_slice($argv, 1);
for ($i = 0; $i < count($argvRest); $i++) {
    if ($argvRest[$i] === '--compare') {
        $compareWith = $argvRest[$i + 1] ?? null;
        if ($compareWith === null) {
            fwrite(STDERR, "--compare needs a path to a previous .last-run.json\n");
            exit(2);
        }
        $i++;
        continue;
    }
    fwrite(STDERR, "unknown argument: {$argvRest[$i]}\n");
    exit(2);
}
if ($compareWith !== null) {
    if (!is_file($compareWith)) {
        fwrite(STDERR, "--compare baseline not found: {$compareWith}\n");
        exit(2);
    }
    // The run overwrites OUT_FILE, so comparing against it would compare a file with itself.
    if (realpath($compareWith) === realpath(OUT_FILE)) {
        fwrite(STDERR, "--compare cannot point at " . basename(OUT_FILE) . " itself -- copy it first.\n");
        exit(2);
    }
}

$files = array_merge(glob(__DIR__ . '/*_test.php'), glob(__DIR__ . '/*_test.js'));
usort($files, fn($a, $b) => strcmp(basename($a), basename($b)));
if (!$files) {
    fwrite(STDERR, "no tests/*_test.php or tests/*_test.js found\n");
    exit(2);
}

// A .js suite that cannot run is a hard stop, never a silent skip: skipping it would quietly drop
// its assertions out of the total and leave a shrunken baseline looking clean.
$nodeBin = null;
$jsFiles = array_filter($files, fn($p) => str_ends_with($p, '.js'));
if ($jsFiles) {
    $probe = [];
    $probeCode = 1;
    exec('node -v 2>&1', $probe, $probeCode);
    if ($probeCode !== 0) {
        fwrite(STDERR, 'node is required to run ' . count($jsFiles) . " .js test file(s), but `node -v` failed:\n");
        fwrite(STDERR, '  ' . (implode(' ', $probe) ?: '<no output>') . "\n");
        fwrite(STDERR, "Install Node.js or put it on PATH and re-run -- these files are NOT skipped.\n");
        exit(2);
    }
    $nodeBin = 'node';
}

$results = [];
foreach ($files as $path) {
    $name = basename($path);
    $started = microtime(true);
    $output = [];
    $bin = str_ends_with($path, '.js') ? $nodeBin : PHP_BINARY;
    exec(escapeshellarg($bin) . ' ' . escapeshellarg($path) . ' 2>&1', $output);
    $elapsedMs = (int)round((microtime(true) - $started) * 1000);

    $text = implode("\n", $output);
    // Last match wins: a file may print a per-section summary before its final one.
    if (preg_match_all(SUMMARY_PATTERN, $text, $m, PREG_SET_ORDER)) {
        $last = $m[count($m) - 1];
        $results[$name] = ['pass' => (int)$last[1], 'fail' => (int)$last[2], 'status' => 'ok'];
    } else {
        $results[$name] = ['pass' => 0, 'fail' => 0, 'status' => 'no_summary'];
    }
    $results[$name]['_ms'] = $elapsedMs;     // screen only, stripped before the json is written
    $results[$name]['_tail'] = trimTail($text);
}

function trimTail(string $text): string
{
    $lines = array_slice(preg_split('/\r?\n/', rtrim($text)), -3);
    return implode(' | ', array_map('trim', array_filter($lines, fn($l) => trim($l) !== '')));
}

function pad(string $s, int $width): string
{
    $len = mb_strlen($s, 'UTF-8');
    return $s . str_repeat(' ', max(0, $width - $len));
}

$nameWidth = max(array_map(fn($n) => mb_strlen($n, 'UTF-8'), array_keys($results)));
$totalLabel = 'TOTAL (' . count($results) . ' files)';
$nameWidth = max($nameWidth, mb_strlen('ไฟล์', 'UTF-8'), mb_strlen($totalLabel, 'UTF-8'));

echo pad('ไฟล์', $nameWidth) . " | pass | fail |   ms\n";
echo str_repeat('-', $nameWidth) . '-+------+------+------' . "\n";

$totalPass = 0;
$totalFail = 0;
$noSummary = [];
foreach ($results as $name => $r) {
    if ($r['status'] === 'no_summary') {
        $noSummary[] = $name;
        echo pad($name, $nameWidth) . ' |   -  |   -  | ' . str_pad((string)$r['_ms'], 5, ' ', STR_PAD_LEFT)
            . "   NO SUMMARY LINE\n";
        continue;
    }
    $totalPass += $r['pass'];
    $totalFail += $r['fail'];
    echo pad($name, $nameWidth)
        . ' | ' . str_pad((string)$r['pass'], 4, ' ', STR_PAD_LEFT)
        . ' | ' . str_pad((string)$r['fail'], 4, ' ', STR_PAD_LEFT)
        . ' | ' . str_pad((string)$r['_ms'], 5, ' ', STR_PAD_LEFT)
        . ($r['fail'] > 0 ? '   FAIL' : '')
        . "\n";
}

echo str_repeat('-', $nameWidth) . '-+------+------+------' . "\n";
echo pad($totalLabel, $nameWidth)
    . ' | ' . str_pad((string)$totalPass, 4, ' ', STR_PAD_LEFT)
    . ' | ' . str_pad((string)$totalFail, 4, ' ', STR_PAD_LEFT)
    . " |\n";

if ($noSummary) {
    echo "\nFiles with no `Passed: <n>, Failed: <n>` line -- fix the test file, not this runner:\n";
    foreach ($noSummary as $name) {
        echo "  - {$name}   (last output: " . ($results[$name]['_tail'] ?: '<no output>') . ")\n";
    }
}

// The json is deterministic on purpose: two clean runs must produce byte-identical files, so
// timings and any other run-to-run noise stay out of it.
$json = [];
foreach ($results as $name => $r) {
    $json[$name] = ['pass' => $r['pass'], 'fail' => $r['fail'], 'status' => $r['status']];
}
$payload = [
    'totals' => ['files' => count($results), 'pass' => $totalPass, 'fail' => $totalFail, 'no_summary' => count($noSummary)],
    'files'  => $json,
];
file_put_contents(OUT_FILE, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "\nWrote " . OUT_FILE . "\n";

if ($compareWith !== null) {
    $baseline = json_decode((string)file_get_contents($compareWith), true);
    if (!is_array($baseline) || !isset($baseline['files']) || !is_array($baseline['files'])) {
        fwrite(STDERR, "--compare baseline is not a run_all.php json: {$compareWith}\n");
        exit(2);
    }
    $base = $baseline['files'];
    $diffs = [];
    foreach ($json as $name => $now) {
        $was = $base[$name] ?? null;
        if ($was === null) {
            $diffs[] = "  + {$name}   new file: {$now['pass']}/{$now['fail']} ({$now['status']})";
        } elseif ((int)$was['pass'] !== $now['pass'] || (int)$was['fail'] !== $now['fail'] || (string)$was['status'] !== $now['status']) {
            $diffs[] = "  ~ {$name}   {$was['pass']}/{$was['fail']} ({$was['status']})  ->  {$now['pass']}/{$now['fail']} ({$now['status']})";
        }
    }
    foreach ($base as $name => $was) {
        if (!isset($json[$name])) {
            $diffs[] = "  - {$name}   gone (was {$was['pass']}/{$was['fail']})";
        }
    }
    echo "\nCompared against {$compareWith} (pass/fail):\n";
    echo $diffs ? implode("\n", $diffs) . "\n" : "  no differences\n";
}

exit(($totalFail > 0 || $noSummary) ? 1 : 0);
