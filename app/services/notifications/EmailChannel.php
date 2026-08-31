<?php
declare(strict_types=1);
require_once __DIR__ . '/NotificationChannelInterface.php';
require_once __DIR__ . '/../../models/EmailQueueModel.php';

/**
 * 2026-08-30, Phase 7 (T040, explicit request: "ทุกครั้งที่ส่งเมล ให้บันทึกคิวไว้ใน Database ก่อน แล้วมี
 * cronjob แยกมา run ส่งจริง (ไม่ส่งแบบ sync ทันที)") -- send() no longer calls PHPMailer/SMTP directly
 * inline in the HTTP request at all. It now just INSERTS a row into `email_queue` (a single fast
 * write) and returns immediately -- the real, slow, network-dependent SMTP delivery happens later,
 * out of band, in cron/send_queued_emails.php (that file is the only remaining PHPMailer call site
 * in this codebase now -- see its own docblock for the actual SMTP-sending logic that used to live
 * here).
 *
 * DELIBERATE SCOPE LIMIT (documented, not hidden): `success: true` here means "successfully
 * QUEUED for delivery", not "successfully delivered to the recipient's inbox" -- the real outcome
 * is only known later, when the cron actually tries it. PayslipDeliveryService's own fallback-chain
 * logic (email failed -> try LINE next, etc.) and `payslip_delivery_logs.status` both still read
 * this as an immediate success/failure exactly as before this change -- a genuinely FAILED email
 * delivery (bad SMTP credentials, recipient bounces, etc.) is now something the queue's own
 * `email_queue.status='failed'`/`error_message` records separately, not something
 * PayslipDeliveryService retries into a different channel automatically. Revisit if/when that
 * distinction needs to drive UI/retry behavior -- out of scope for this round, which is specifically
 * about "queue first, cron sends for real", not a rearchitecture of the whole delivery/fallback
 * system built around channels always resolving synchronously.
 */
class EmailChannel implements NotificationChannelInterface {
    public function code(): string {
        return 'email';
    }

    public function isConfigured(): bool {
        return !empty($_ENV['MAIL_HOST']) && !empty($_ENV['MAIL_FROM_ADDRESS']);
    }

    public function send(string $recipient, string $subject, string $message, string $attachmentPath, string $attachmentName): array {
        if (!$this->isConfigured()) {
            throw new RuntimeException('EmailChannel is not configured (MAIL_HOST/MAIL_FROM_ADDRESS missing in .env).');
        }
        $queueModel = new EmailQueueModel();
        $queueModel->enqueue(
            null, // no comp_id in this interface's own contract -- see this class's own docblock.
            $recipient,
            $subject,
            $message,
            $attachmentPath !== '' ? $attachmentPath : null,
            $attachmentName !== '' ? $attachmentName : null
        );
        return ['success' => true, 'message' => 'Queued for delivery.'];
    }
}
