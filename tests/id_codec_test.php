<?php
/**
 * Lightweight verification script for IdCodec (app/services/IdCodec.php). Not PHPUnit -- see
 * tests/statutory_engine_test.php for why. No DB needed, just EncryptionService (.env keys).
 * Run with: php tests/id_codec_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/services/IdCodec.php';

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
function checkTrue(string $label, bool $actual): void {
    check($label, $actual, true);
}

echo "=== Round-trip ===\n";
foreach ([1, 137, 999999, 0] as $id) {
    $token = IdCodec::encode($id);
    check("encode({$id}) decodes back to {$id}", IdCodec::decode($token), $id);
}

echo "=== Token shape ===\n";
$token137 = IdCodec::encode(137);
checkTrue('token does not literally contain the raw id as a substring', strpos($token137, '137') === false);
checkTrue('token is URL-safe (no +, /, or = padding characters)', !preg_match('/[+\/=]/', $token137));

echo "=== Non-determinism (random IV per encrypt) ===\n";
$tokenA = IdCodec::encode(137);
$tokenB = IdCodec::encode(137);
checkTrue('encoding the same id twice produces different tokens (random IV)', $tokenA !== $tokenB);
check('...but both still decode back to the same id', IdCodec::decode($tokenA), IdCodec::decode($tokenB));

echo "=== Rejects bad input ===\n";
check('a plain old raw numeric string (pre-encoding URL format) fails to decode, not silently misinterpreted', IdCodec::decode('137'), null);
check('empty string fails to decode', IdCodec::decode(''), null);
check('garbage string fails to decode', IdCodec::decode('not-a-valid-token!!!'), null);
check('truncated real token fails to decode', IdCodec::decode(substr($token137, 0, 10)), null);
check('tampered real token (flipped last char) fails to decode', IdCodec::decode(substr($token137, 0, -1) . (substr($token137, -1) === 'A' ? 'B' : 'A')), null);

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
