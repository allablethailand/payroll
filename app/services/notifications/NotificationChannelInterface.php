<?php
declare(strict_types=1);

/**
 * Contract for a single payslip delivery channel (email/LINE/telegram/...). Mirrors the
 * StatutoryExportInterface + StatutoryExportRegistry pattern already used in this codebase
 * (app/services/export/) -- add a new channel = write a new class implementing this + register
 * it in NotificationChannelRegistry::init(), no changes to existing channels or the delivery
 * engine (PayslipDeliveryService) needed.
 */
interface NotificationChannelInterface {
    /** Matches master_notification_channels.code (e.g. 'email', 'line', 'telegram'). */
    public function code(): string;

    /**
     * Whether this channel has the credentials/config it needs to attempt a real send (e.g. SMTP
     * host set). PayslipDeliveryService checks this BEFORE calling send() so an unconfigured
     * channel in a fallback chain is skipped with a clear log entry instead of throwing.
     */
    public function isConfigured(): bool;

    /**
     * @param string $recipient destination address for this channel (email address / LINE user
     *        id / Telegram chat id) -- resolved by PayslipDeliveryService, not this class.
     * @param string $attachmentPath absolute filesystem path to the payslip PDF to attach/send.
     * @return array{success: bool, message: string}
     * @throws RuntimeException if called while isConfigured() is false -- callers must check
     *         isConfigured() first; this is a programmer error, not an expected failure mode.
     */
    public function send(string $recipient, string $subject, string $message, string $attachmentPath, string $attachmentName): array;
}
