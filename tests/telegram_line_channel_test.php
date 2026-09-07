<?php
/**
 * Backlog Phase 11, T062 -- real Telegram Bot API / LINE Messaging API implementation for
 * TelegramChannel/LineChannel (previously honest stubs that always threw). This dev environment
 * has no real TELEGRAM_BOT_TOKEN/LINE_CHANNEL_ACCESS_TOKEN (both empty in .env, same as
 * tests/payslip_delivery_test.php's own documented limitation), so a live network call can't be
 * exercised here -- this file instead proves the parts that ARE testable without one:
 *   - isConfigured() still correctly gates on the env var (unchanged from the original stub).
 *   - send() still throws when called while not configured (unchanged interface contract).
 *   - the real response-parsing/success-failure logic (parseResponse(), extracted from send()
 *     specifically to be testable this way) correctly classifies a canned HTTP response the
 *     exact shape each API's own documentation says it returns.
 *
 * Not PHPUnit. No DB access needed (pure logic), but wraps a transaction anyway for consistency
 * with every other test file in this project.
 * Run with: php tests/telegram_line_channel_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/notifications/TelegramChannel.php';
require_once __DIR__ . '/../app/services/notifications/LineChannel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

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
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }

// Thin subclasses exposing the protected parseResponse() for direct testing -- the classes
// themselves keep it protected (not part of the public NotificationChannelInterface contract),
// these subclasses exist only in this test file.
class TestableTelegramChannel extends TelegramChannel {
    public function testParseResponse(int $httpCode, string $rawBody): array {
        return $this->parseResponse($httpCode, $rawBody);
    }
}
class TestableLineChannel extends LineChannel {
    public function testParseResponse(int $httpCode, string $rawBody): array {
        return $this->parseResponse($httpCode, $rawBody);
    }
}

try {
    $originalTelegramToken = $_ENV['TELEGRAM_BOT_TOKEN'] ?? null;
    $originalLineToken = $_ENV['LINE_CHANNEL_ACCESS_TOKEN'] ?? null;

    echo "=== isConfigured() gating (unchanged from the original stub) ===\n";
    $_ENV['TELEGRAM_BOT_TOKEN'] = '';
    $telegram = new TelegramChannel();
    checkFalse('TelegramChannel::isConfigured() is false with an empty token', $telegram->isConfigured());
    $_ENV['TELEGRAM_BOT_TOKEN'] = '12345:fake-token-for-this-test-only';
    checkTrue('TelegramChannel::isConfigured() is true once a token is set', $telegram->isConfigured());

    $_ENV['LINE_CHANNEL_ACCESS_TOKEN'] = '';
    $line = new LineChannel();
    checkFalse('LineChannel::isConfigured() is false with an empty token', $line->isConfigured());
    $_ENV['LINE_CHANNEL_ACCESS_TOKEN'] = 'fake-channel-access-token-for-this-test-only';
    checkTrue('LineChannel::isConfigured() is true once a token is set', $line->isConfigured());

    echo "\n=== send() still throws when NOT configured (unchanged interface contract) ===\n";
    $_ENV['TELEGRAM_BOT_TOKEN'] = '';
    $threwTelegram = false;
    try {
        (new TelegramChannel())->send('123', 'Payslip', 'msg', __FILE__, 'test.pdf');
    } catch (RuntimeException $e) {
        $threwTelegram = true;
    }
    checkTrue('TelegramChannel::send() throws RuntimeException while not configured', $threwTelegram);

    $_ENV['LINE_CHANNEL_ACCESS_TOKEN'] = '';
    $threwLine = false;
    try {
        (new LineChannel())->send('U1234567890', 'Payslip', 'msg', __FILE__, 'test.pdf');
    } catch (RuntimeException $e) {
        $threwLine = true;
    }
    checkTrue('LineChannel::send() throws RuntimeException while not configured', $threwLine);

    echo "\n=== TelegramChannel::parseResponse() -- real sendDocument response shapes ===\n";
    $tg = new TestableTelegramChannel();
    $ok = $tg->testParseResponse(200, json_encode(['ok' => true, 'result' => ['message_id' => 42]]));
    checkTrue('HTTP 200 + ok:true is classified as success', $ok['success']);
    check('success message mentions Telegram', $ok['message'], 'Sent via Telegram.');

    $badChat = $tg->testParseResponse(400, json_encode(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found']));
    checkFalse('HTTP 400 + ok:false is classified as failure', $badChat['success']);
    check('failure message surfaces Telegram\'s own description', $badChat['message'], 'Bad Request: chat not found');

    $garbled = $tg->testParseResponse(500, 'not json at all');
    checkFalse('a non-JSON 500 response is classified as failure, not a crash', $garbled['success']);
    checkTrue('failure message falls back to the HTTP code when the body cannot be parsed', str_contains($garbled['message'], '500'));

    echo "\n=== LineChannel::parseResponse() -- real push-message response shapes ===\n";
    $ln = new TestableLineChannel();
    $lineOk = $ln->testParseResponse(200, '{}');
    checkTrue('HTTP 200 with LINE\'s own empty-object body is classified as success', $lineOk['success']);
    check('success message mentions LINE', $lineOk['message'], 'Sent via LINE.');

    $lineBad = $ln->testParseResponse(400, json_encode(['message' => 'The property, to, in the request body is invalid']));
    checkFalse('HTTP 400 is classified as failure', $lineBad['success']);
    check('failure message surfaces LINE\'s own message field', $lineBad['message'], 'The property, to, in the request body is invalid');

    $lineGarbled = $ln->testParseResponse(401, 'not json');
    checkFalse('a non-JSON 401 response is classified as failure, not a crash', $lineGarbled['success']);
    checkTrue('failure message falls back to the HTTP code when the body cannot be parsed', str_contains($lineGarbled['message'], '401'));

    echo "\n=== TelegramChannel::send() rejects a missing attachment file BEFORE attempting any network call ===\n";
    $_ENV['TELEGRAM_BOT_TOKEN'] = '12345:fake-token-for-this-test-only';
    $missingFileResult = (new TelegramChannel())->send('123', 'Payslip', 'msg', '/nonexistent/path/to/nothing.pdf', 'test.pdf');
    checkFalse('send() with a missing attachment path returns success:false (not a thrown error)', $missingFileResult['success']);
    checkTrue('the failure message names the missing path', str_contains($missingFileResult['message'], '/nonexistent/path/to/nothing.pdf'));

    // Restore the real .env values so nothing this test touched leaks into any later test file
    // run in the same PHP process (not an issue for `php tests/xxx.php` run individually, but
    // matches this project's own "leave global state as you found it" discipline).
    $_ENV['TELEGRAM_BOT_TOKEN'] = $originalTelegramToken;
    $_ENV['LINE_CHANNEL_ACCESS_TOKEN'] = $originalLineToken;

    echo "\n" . ($failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)" : "SOME TESTS FAILED") . "\n";
    echo "{$passes} passed, {$failures} failed.\n";
} finally {
    $pdo->rollBack();
}
exit($failures === 0 ? 0 : 1);
