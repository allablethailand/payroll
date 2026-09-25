<?php
/**
 * One command for "what did the whole suite look like BEFORE my changes": check some ref out into
 * a throwaway git worktree, give it the two gitignored files the suite needs to boot, run
 * tests/run_all.php there, keep its .last-run.json under a tag, take the worktree back down.
 *
 * Usage:
 *   php tests/baseline.php <tag> [<ref>]        default ref: HEAD
 *   php tests/run_all.php --compare ../baseline-<tag>.json
 *
 * The json lands OUTSIDE the repo (../baseline-<tag>.json) on purpose: it is a local artifact of
 * one machine's dev DB at one moment, never a repo file, and run_all.php refuses to compare
 * tests/.last-run.json against itself anyway.
 *
 * vendor/ and node_modules/ are COMMITTED in this repo, so the checkout already has them and
 * nothing needs linking or copying. Do not "helpfully" add that back: the first version of this
 * script junction-linked them, fell back to a recursive copy when mklink refused a path git had
 * already created, and then could not delete its own worktree.
 */
declare(strict_types=1);

const REPO_ROOT = __DIR__ . '/..';

/** Gitignored, so a fresh checkout has neither -- and nothing boots without them. */
const COPY_ITEMS = ['.env', 'config.php'];
/** Proof the checkout really is complete before a whole suite runs against it. */
const REQUIRED_AFTER_CHECKOUT = ['vendor/autoload.php', 'tests/run_all.php'];

$tag = $argv[1] ?? null;
$ref = $argv[2] ?? 'HEAD';
if ($tag === null || preg_match('/^[A-Za-z0-9._-]+$/', $tag) !== 1) {
    fwrite(STDERR, "usage: php tests/baseline.php <tag> [<ref>]\n");
    fwrite(STDERR, "  <tag>  a name for this baseline: letters, digits, dot, dash, underscore\n");
    fwrite(STDERR, "  <ref>  anything git can check out (default: HEAD)\n");
    exit(2);
}

$root = realpath(REPO_ROOT);
if ($root === false) {
    fwrite(STDERR, "cannot resolve the repo root from " . REPO_ROOT . "\n");
    exit(2);
}
$parent = dirname($root);
$worktree = $parent . DIRECTORY_SEPARATOR . 'payroll-baseline-' . $tag;
$outFile = $parent . DIRECTORY_SEPARATOR . 'baseline-' . $tag . '.json';

function run(string $cmd, ?array &$output = null): int
{
    $output = [];
    $code = 0;
    exec($cmd . ' 2>&1', $output, $code);
    return $code;
}

function git(string $args, ?array &$output = null): int
{
    global $root;
    return run('git -C ' . escapeshellarg($root) . ' ' . $args, $output);
}

function teardown(string $worktree): void
{
    git('worktree remove ' . escapeshellarg($worktree) . ' --force');
    if (is_dir($worktree)) {
        run(PHP_OS_FAMILY === 'Windows'
            ? 'cmd /c rmdir /S /Q ' . escapeshellarg($worktree)
            : 'rm -rf ' . escapeshellarg($worktree));
    }
    git('worktree prune');
}

echo "Baseline '{$tag}' from {$ref}\n";
echo "  worktree : {$worktree}\n";
echo "  json     : {$outFile}\n\n";

if (is_dir($worktree)) {
    echo "A worktree from an earlier run is still there -- taking it down first.\n";
    teardown($worktree);
    if (is_dir($worktree)) {
        fwrite(STDERR, "could not remove {$worktree} -- clear it by hand and re-run\n");
        exit(2);
    }
}

if (git('worktree add ' . escapeshellarg($worktree) . ' ' . escapeshellarg($ref), $out) !== 0) {
    fwrite(STDERR, "git worktree add failed:\n  " . implode("\n  ", $out) . "\n");
    exit(2);
}
echo "Checked out {$ref}.\n";

$failed = null;
foreach (REQUIRED_AFTER_CHECKOUT as $rel) {
    if (!file_exists($worktree . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel))) {
        $failed = "{$rel} is missing from the checkout of {$ref} -- the suite cannot run against it";
        break;
    }
}
if ($failed === null) {
    foreach (COPY_ITEMS as $name) {
        $from = $root . DIRECTORY_SEPARATOR . $name;
        if (!is_file($from)) {
            $failed = "{$name} is missing from the working tree -- the suite cannot boot without it";
            break;
        }
        copy($from, $worktree . DIRECTORY_SEPARATOR . $name);
        echo "  copied  {$name}\n";
    }
}
if ($failed !== null) {
    fwrite(STDERR, "\n{$failed}\n");
    teardown($worktree);
    exit(2);
}

echo "\nRunning the suite against {$ref}...\n\n";
$suiteCode = 0;
passthru(escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg($worktree . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'run_all.php'), $suiteCode);

$produced = $worktree . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . '.last-run.json';
$kept = is_file($produced) && copy($produced, $outFile);
$payload = $kept ? json_decode((string)file_get_contents($outFile), true) : null;

teardown($worktree);
echo "\n" . (is_dir($worktree) ? "WARNING: {$worktree} is still on disk -- remove it by hand.\n" : "Worktree removed.\n");

if (!$kept) {
    fwrite(STDERR, "the suite produced no tests/.last-run.json -- nothing to keep as a baseline\n");
    exit(2);
}

// A baseline WITH failures is still a valid baseline -- it is the "before" picture, warts and all
// -- so a non-zero suite code is reported here, not treated as this script failing.
$totals = $payload['totals'] ?? [];
echo "Kept {$outFile}\n";
printf("  %d files, %d pass, %d fail, %d with no summary line (suite exit %d)\n",
    $totals['files'] ?? 0, $totals['pass'] ?? 0, $totals['fail'] ?? 0, $totals['no_summary'] ?? 0, $suiteCode);
echo "\nCompare the working tree against it with:\n";
echo "  php tests/run_all.php --compare " . $outFile . "\n";
exit(0);
