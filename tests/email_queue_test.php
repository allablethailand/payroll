<?php
/**
 * Lightweight verification script for the Phase 7 (T040) email queue: EmailQueueModel,
 * EmailChannel::send()'s new enqueue-only behavior, and send_queued_email_via_smtp()
 * (app/services/EmailSender.php -- the actual PHPMailer call the cron script drives).
 * Not PHPUnit -- see tests/statutory_engine_test.php for why.
 *
 * This dev environment has no real SMTP credentials (confirmed via tests/payslip_delivery_test.php's
 * own "email is not configured" assertion) -- send_queued_email_via_smtp() is therefore verified
 * only for its "MAIL_HOST missing" early-return path here, same "documented, not hidden" caveat
 * style this project already uses for EmailChannel/LineChannel/TelegramChannel's own DRAFT/
 * unverified status elsewhere. A temporary $_ENV override further down DOES exercise the
 * "configured" branch of EmailChannel::isConfigured()/send() to prove the real enqueue path works,
 * without needing a real mailbox (send() itself never touches SMTP any more, only email_queue).
 *
 * Run with: php tests/email_queue_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/EmailQueueModel.php';
require_once __DIR__ . '/../app/services/EmailSender.php';
require_once __DIR__ . '/../app/services/notifications/EmailChannel.php';

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

try {
    $model = new EmailQueueModel($pdo);

    echo "=== EmailQueueModel::enqueue() / claimBatch() ===\n";
    $id1 = $model->enqueue(1, 'a@test.local', 'Subject A', 'Body A', null, null);
    $id2 = $model->enqueue(null, 'b@test.local', 'Subject B', 'Body B', '/tmp/x.pdf', 'x.pdf');
    checkTrue('enqueue() returns positive ids', $id1 > 0 && $id2 > 0 && $id1 !== $id2);
    $row1 = $pdo->query("SELECT * FROM email_queue WHERE id = {$id1}")->fetch(PDO::FETCH_ASSOC);
    check('status starts pending', $row1['status'], 'pending');
    check('attempts starts at 0', (int)$row1['attempts'], 0);
    check('comp_id persisted when given', (int)$row1['comp_id'], 1);
    $row2 = $pdo->query("SELECT * FROM email_queue WHERE id = {$id2}")->fetch(PDO::FETCH_ASSOC);
    check('comp_id stays NULL when not given (no FK, soft reference by design)', $row2['comp_id'], null);
    check('attachment_path/name persisted', [$row2['attachment_path'], $row2['attachment_name']], ['/tmp/x.pdf', 'x.pdf']);

    $batch = $model->claimBatch(50);
    $batchIds = array_map('intval', array_column($batch, 'id'));
    checkTrue('claimBatch() includes both fresh pending rows', in_array($id1, $batchIds, true) && in_array($id2, $batchIds, true));
    checkTrue('claimBatch() returns oldest first', array_search($id1, $batchIds, true) < array_search($id2, $batchIds, true));

    echo "=== markSent() / markFailed() ===\n";
    $model->markSent($id1);
    $sentRow = $pdo->query("SELECT status, attempts, sent_at FROM email_queue WHERE id = {$id1}")->fetch(PDO::FETCH_ASSOC);
    check('status flips to sent', $sentRow['status'], 'sent');
    check('attempts incremented', (int)$sentRow['attempts'], 1);
    checkTrue('sent_at populated', !empty($sentRow['sent_at']));
    $batchAfterSent = $model->claimBatch(50);
    checkTrue('claimBatch() no longer includes the now-sent row', !in_array($id1, array_map('intval', array_column($batchAfterSent, 'id')), true));

    $model->markFailed($id2, 'SMTP connection refused');
    $failedRow1 = $pdo->query("SELECT status, attempts, error_message FROM email_queue WHERE id = {$id2}")->fetch(PDO::FETCH_ASSOC);
    check('a single failure (attempts=1 < max_attempts=3) stays pending for retry', $failedRow1['status'], 'pending');
    check('attempts incremented on failure too', (int)$failedRow1['attempts'], 1);
    check('error_message recorded', $failedRow1['error_message'], 'SMTP connection refused');
    checkTrue('a merely-pending-again row is still claimable', in_array($id2, array_map('intval', array_column($model->claimBatch(50), 'id')), true));

    $model->markFailed($id2, 'attempt 2');
    $model->markFailed($id2, 'attempt 3 -- final');
    $exhaustedRow = $pdo->query("SELECT status, attempts, error_message FROM email_queue WHERE id = {$id2}")->fetch(PDO::FETCH_ASSOC);
    check('after reaching max_attempts (3), status flips to failed permanently', $exhaustedRow['status'], 'failed');
    check('attempts caps at max_attempts', (int)$exhaustedRow['attempts'], 3);
    check('error_message reflects the LAST attempt', $exhaustedRow['error_message'], 'attempt 3 -- final');
    checkTrue('a permanently-failed row is never claimed again (would retry forever otherwise)', !in_array($id2, array_map('intval', array_column($model->claimBatch(50), 'id')), true));

    echo "=== send_queued_email_via_smtp() (app/services/EmailSender.php) ===\n";
    $origMailHost = $_ENV['MAIL_HOST'] ?? null;
    unset($_ENV['MAIL_HOST']);
    $noHostResult = send_queued_email_via_smtp(['to_address' => 'x@test.local', 'subject' => 'S', 'body' => 'B', 'attachment_path' => null, 'attachment_name' => null]);
    check('with no MAIL_HOST configured, fails cleanly with a clear message (never throws)', $noHostResult['success'], false);
    checkTrue('failure message names the missing config', str_contains($noHostResult['message'], 'MAIL_HOST'));
    if ($origMailHost !== null) { $_ENV['MAIL_HOST'] = $origMailHost; } else { unset($_ENV['MAIL_HOST']); }

    echo "=== EmailChannel::send() -- enqueues, never touches SMTP directly any more ===\n";
    $countBefore = (int)$pdo->query("SELECT COUNT(*) FROM email_queue")->fetchColumn();
    // Temporarily simulate a configured environment (this dev .env genuinely has none -- see this
    // file's own top comment) purely to exercise send()'s ENQUEUE path, which never actually
    // contacts SMTP any more regardless -- safe to do without a real mailbox.
    $origHost = $_ENV['MAIL_HOST'] ?? null;
    $origFrom = $_ENV['MAIL_FROM_ADDRESS'] ?? null;
    $_ENV['MAIL_HOST'] = 'smtp.example.test';
    $_ENV['MAIL_FROM_ADDRESS'] = 'noreply@example.test';
    $emailChannel = new EmailChannel();
    checkTrue('isConfigured() now true with the simulated env', $emailChannel->isConfigured());
    $sendResult = $emailChannel->send('recipient@test.local', 'Test Subject', 'Test Body', '', '');
    check('send() reports success (meaning "queued", see this class\'s own docblock)', $sendResult['success'], true);
    check('send() message says Queued, not Sent -- the distinction matters (documented scope limit)', $sendResult['message'], 'Queued for delivery.');
    $countAfter = (int)$pdo->query("SELECT COUNT(*) FROM email_queue")->fetchColumn();
    check('exactly one new email_queue row was created by send()', $countAfter - $countBefore, 1);
    $queuedRow = $pdo->query("SELECT to_address, subject, body, status FROM email_queue ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    check('the queued row carries the real recipient/subject/body', [$queuedRow['to_address'], $queuedRow['subject'], $queuedRow['body']], ['recipient@test.local', 'Test Subject', 'Test Body']);
    check('queued as pending, not sent -- send() itself never delivers anything', $queuedRow['status'], 'pending');
    if ($origHost !== null) { $_ENV['MAIL_HOST'] = $origHost; } else { unset($_ENV['MAIL_HOST']); }
    if ($origFrom !== null) { $_ENV['MAIL_FROM_ADDRESS'] = $origFrom; } else { unset($_ENV['MAIL_FROM_ADDRESS']); }

    echo "=== EmailChannel::send() still throws when genuinely unconfigured (unchanged contract) ===\n";
    $unconfiguredChannel = new EmailChannel();
    checkTrue('isConfigured() is false again once the simulated env is restored', !$unconfiguredChannel->isConfigured());
    try {
        $unconfiguredChannel->send('x@test.local', 'S', 'B', '', '');
        checkTrue('send() while unconfigured should have thrown', false);
    } catch (RuntimeException $e) {
        checkTrue('send() throws RuntimeException while unconfigured, same as before this change', true);
    }

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
