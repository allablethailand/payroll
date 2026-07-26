<?php
declare(strict_types=1);
require_once __DIR__ . '/NotificationChannelInterface.php';

/**
 * STUB -- no Telegram Bot API integration exists anywhere in this codebase (confirmed by survey
 * before building Payslip Distribution). isConfigured() is intentionally always false until
 * TELEGRAM_BOT_TOKEN is set AND send() below is actually implemented and tried against a real
 * bot -- see LineChannel's docblock for why this isn't implemented speculatively. Recipient is
 * expected to be employees.telegram_chat_id once implemented.
 */
class TelegramChannel implements NotificationChannelInterface {
    public function code(): string {
        return 'telegram';
    }

    public function isConfigured(): bool {
        return !empty($_ENV['TELEGRAM_BOT_TOKEN']);
    }

    public function send(string $recipient, string $subject, string $message, string $attachmentPath, string $attachmentName): array {
        throw new RuntimeException('TelegramChannel is not implemented yet. Set TELEGRAM_BOT_TOKEN and implement send() against the Telegram Bot API before using this channel.');
    }
}
