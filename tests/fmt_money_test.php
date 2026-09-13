<?php
/**
 * docs/design/rules.md §8: "รอบ 2 ต้องยืนยันด้วย test ว่า PHP fmtMoney($n) กับ JS fmtNum(n) ให้ผลตรงกัน
 * ทุกกรณี" -- 10 values (negative, zero, long-decimal, null, empty, the 'XXXX' salary-mask
 * passthrough) run through PHP's fmtMoney() (app/helpers/helpers.php) and asserted against the
 * EXACT string public/js/format-helpers.js's own fmtNum() produces for the same input -- each
 * expected string below was confirmed by actually executing fmtNum() in Node first (see the git
 * history of this file's own commit message for the exact script/output), not hand-derived from
 * reading the JS source and assuming.
 *
 * Run with: php tests/fmt_money_test.php
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

// [input, expected -- expected confirmed via a real `fmtNum()` run in Node, see this file's own docblock]
$cases = [
    [0, '0.00'],
    [1234567.89, '1,234,567.89'],
    [-1234.5, '-1,234.50'],
    [1000000, '1,000,000.00'],
    [0.1, '0.10'],
    [-0.01, '-0.01'],
    [1234.567, '1,234.57'],
    [null, '-'],
    ['', '-'],
    ['XXXX', 'XXXX'],
];
foreach ($cases as [$input, $expected]) {
    check('fmtMoney(' . var_export($input, true) . ')', fmtMoney($input), $expected);
}

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
