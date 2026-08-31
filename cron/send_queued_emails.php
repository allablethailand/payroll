<?php
declare(strict_types=1);

/**
 * 2026-08-30, Phase 7 (T040, explicit request: "ทุกครั้งที่ส่งเมล ให้บันทึกคิวไว้ใน Database ก่อน แล้วมี
 * cronjob แยกมา run ส่งจริง"). This is that separate cronjob -- drives `email_queue` in batches, the
 * actual PHPMailer/SMTP call itself lives in app/services/EmailSender.php's
 * send_queued_email_via_smtp() (pulled out into its own requirable function so
 * tests/email_queue_test.php can exercise it directly without this script's own top-level
 * batch-processing side effects running). EmailChannel::send() just enqueues now, see that class's
 * own docblock. Run standalone via the OS's own scheduler, NOT through the web server/Router -- there was no
 * scheduled-job mechanism anywhere in this project before this (confirmed via sync_batches'
 * `trigger_type='auto'` column comment: "reserved for when a scheduled-job system exists -- not
 * built yet").
 *
 * HOW TO SCHEDULE THIS (pick whichever matches the real deployment target):
 *   - Linux/production crontab, every minute:
 *       * * * * * /usr/bin/php /path/to/payroll/cron/send_queued_emails.php >> /path/to/payroll/storage/logs/email_queue.log 2>&1
 *   - Windows Task Scheduler (this dev environment, XAMPP): create a Basic Task that runs
 *       C:\xampp-vonconnect\php\php.exe C:\xampp-vonconnect\htdocs\payroll\cron\send_queued_emails.php
 *     on a repeating trigger (e.g. every 1-5 minutes) -- `schtasks /create` can do this from an
 *     elevated shell, or use the Task Scheduler GUI; either way this file itself is the same
 *     regardless of which OS/scheduler ends up invoking it, no code here is Windows- or
 *     Linux-specific.
 * A cadence of 1-5 minutes is a reasonable default for a payroll app's own email volume (payslip
 * delivery, employment certificate issuance notifications, etc.) -- not tuned further since there's
 * no real production traffic pattern to tune against yet.
 *
 * Processes up to BATCH_SIZE pending rows per run (oldest first), actually sends each via PHPMailer/
 * SMTP (same $_ENV MAIL_HOST/PORT/ENCRYPTION/USERNAME/PASSWORD/FROM_ADDRESS/FROM_NAME config
 * EmailChannel used to read directly), and marks each sent/failed. A row that fails
 * max_attempts times (default 3, email_queue.max_attempts) stops being retried and is left
 * status='failed' with its own error_message for an admin to investigate -- never retried forever,
 * never silently dropped either.
 *
 * Exits 0 always (even on individual send failures -- those are recorded per-row, not a script
 * failure) so a cron scheduler never flags a normal "some emails bounced" run as a job failure;
 * exits 1 only if the script itself couldn't run at all (e.g. DB connection failed).
 */

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/EmailQueueModel.php';
require_once __DIR__ . '/../app/services/EmailSender.php';

const BATCH_SIZE = 20;

try {
    $pdo = Database::getInstance()->pdo;
} catch (Throwable $e) {
    fwrite(STDERR, '[send_queued_emails] Could not connect to the database: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$queueModel = new EmailQueueModel($pdo);
$batch = $queueModel->claimBatch(BATCH_SIZE);
$sentCount = 0;
$failedCount = 0;
foreach ($batch as $row) {
    $result = send_queued_email_via_smtp($row);
    if ($result['success']) {
        $queueModel->markSent((int)$row['id']);
        $sentCount++;
    } else {
        $queueModel->markFailed((int)$row['id'], $result['message']);
        $failedCount++;
        echo '[send_queued_emails] id=' . $row['id'] . ' to=' . $row['to_address'] . ' FAILED: ' . $result['message'] . PHP_EOL;
    }
}
echo '[send_queued_emails] ' . date('Y-m-d H:i:s') . " -- claimed=" . count($batch) . " sent={$sentCount} failed={$failedCount}" . PHP_EOL;
exit(0);
