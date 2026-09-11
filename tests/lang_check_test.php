<?php
/**
 * Lightweight verification script wiring scripts/check-lang.php's own checkLangFiles() into the
 * test suite -- Batch 4 item 1, step 2, explicit instruction ("รวม check-lang เข้า test suite: fail
 * ถ้ามี key ซ้ำ หรือ key ไม่ตรงกันระหว่างสองไฟล์"). Requires that file directly rather than
 * re-implementing the parser here (this project's own "generalize, don't mirror-copy" rule) -- the
 * CLI-only report/exit block at the bottom of that file is guarded to skip when required like this.
 * No DB needed. Run with: php tests/lang_check_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../scripts/check-lang.php';

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

$projectRoot = dirname(__DIR__);
$files = [
    'th' => $projectRoot . '/public/lang/th.json',
    'en' => $projectRoot . '/public/lang/en.json',
];

$result = checkLangFiles($files, $projectRoot);

foreach ($result['parsed'] as $lang => $info) {
    $dupSummary = array_map(
        fn($d) => ($d['path'] === '' ? $d['key'] : $d['path'] . '.' . $d['key']),
        $info['duplicates']
    );
    check("$lang.json has no duplicate keys", $dupSummary, []);
}

foreach ($result['onlyIn'] as $lang => $missing) {
    check("$lang.json has no keys missing from the other file(s)", $missing, []);
}

check('overall: no lang-file problems found', $result['hasProblem'], false);

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
