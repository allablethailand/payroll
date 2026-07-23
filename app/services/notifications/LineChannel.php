<?php
declare(strict_types=1);
require_once __DIR__ . '/NotificationChannelInterface.php';

/**
 * STUB -- no LINE Messaging API integration exists anywhere in this codebase (confirmed by
 * survey before building Payslip Distribution; the user's mention of a "golf referee
 * notification module" using LINE does not apply to this project). isConfigured() is
 * intentionally always false until LINE_CHANNEL_ACCESS_TOKEN is set AND send() below is actually
 * implemented and tried against a real LINE Official Account -- do not implement the HTTP call
 * speculatively; there is no channel access token or account to test against in this
 * environment, and an untested integration with an external messaging API is worse than an
 * honest stub. Recipient is expected to be employees.line_id (a LINE user id) once implemented.
 */
class LineChannel implements NotificationChannelInterface {
    public function code(): string {
        return 'line';
    }

    public function isConfigured(): bool {
        return !empty($_ENV['LINE_CHANNEL_ACCESS_TOKEN']);
    }

    public function send(string $recipient, string $subject, string $message, string $attachmentPath, string $attachmentName): array {
        throw new RuntimeException('LineChannel is not implemented yet. Set LINE_CHANNEL_ACCESS_TOKEN and implement send() against the LINE Messaging API before using this channel.');
    }
}
