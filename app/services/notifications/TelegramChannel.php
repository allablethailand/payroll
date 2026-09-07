<?php
declare(strict_types=1);
require_once __DIR__ . '/NotificationChannelInterface.php';

/**
 * 2026-09-04, Backlog Phase 11, T062 -- implemented against Telegram Bot API's real, documented
 * `sendDocument` endpoint (https://core.telegram.org/bots/api#senddocument), which genuinely
 * supports a direct file attachment (unlike LINE's Messaging API -- see LineChannel's own
 * docblock for why that one had to take a different shape). `isConfigured()`'s gating on
 * TELEGRAM_BOT_TOKEN is unchanged from the original stub.
 *
 * HONESTY NOTE, same posture this codebase already uses for e.g. PndOneKorExporter/Sso110Exporter's
 * own isVerified()=false: this is built correctly per Telegram's own current, stable, published
 * API spec, but has NOT been exercised against a real bot/chat -- .env's own TELEGRAM_BOT_TOKEN is
 * empty in this dev environment, so there is nothing to test against. Whoever connects a real bot
 * token should do one real end-to-end send before trusting this in production.
 */
class TelegramChannel implements NotificationChannelInterface {
    private const API_TIMEOUT_SECONDS = 20;

    public function code(): string {
        return 'telegram';
    }

    public function isConfigured(): bool {
        return !empty($_ENV['TELEGRAM_BOT_TOKEN']);
    }

    /**
     * $recipient is expected to be employees.telegram_chat_id (a numeric Telegram chat id the
     * employee obtained by starting a conversation with the company's own bot -- out of scope
     * here, same as how a LINE user id is obtained for LineChannel).
     */
    public function send(string $recipient, string $subject, string $message, string $attachmentPath, string $attachmentName): array {
        if (!$this->isConfigured()) {
            throw new RuntimeException('TelegramChannel::send() called while not configured (TELEGRAM_BOT_TOKEN is empty).');
        }
        if (!is_file($attachmentPath)) {
            return ['success' => false, 'message' => "Attachment file not found: {$attachmentPath}"];
        }
        $token = (string)$_ENV['TELEGRAM_BOT_TOKEN'];
        $url = "https://api.telegram.org/bot{$token}/sendDocument";

        // Telegram's own 1024-character caption limit -- truncate rather than let the API reject
        // the whole request over a caption that's too long (a failed send would be worse than a
        // slightly-truncated caption).
        $caption = trim($message !== '' ? $message : $subject);
        if (mb_strlen($caption) > 1024) {
            $caption = mb_substr($caption, 0, 1021) . '...';
        }

        $postFields = [
            'chat_id' => $recipient,
            'caption' => $caption,
            'document' => new CURLFile($attachmentPath, 'application/pdf', $attachmentName),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::API_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            // Deliberately NOT setting Content-Type here -- curl sets the correct
            // multipart/form-data boundary itself when CURLOPT_POSTFIELDS is an array containing
            // a CURLFile, same convention this project already avoids overriding elsewhere.
        ]);
        $body = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrno !== 0) {
            return ['success' => false, 'message' => "Could not reach Telegram: {$curlError}"];
        }
        return $this->parseResponse($httpCode, (string)$body);
    }

    /**
     * Split out from send() specifically so tests/telegram_line_channel_test.php can exercise the
     * real Telegram response-parsing/success-failure logic with a canned HTTP response, without a
     * live network call -- this project's own established pattern for HTTP-calling code it can't
     * reach in this dev environment (see e.g. OrigamiSyncClient's own test seams). `protected` so
     * a test subclass can also override THIS instead if it ever needs to, though the test file
     * calls it directly via reflection/a thin public wrapper rather than subclassing, since this
     * method takes no state from send() beyond its own parameters.
     */
    protected function parseResponse(int $httpCode, string $rawBody): array {
        $decoded = json_decode($rawBody, true);
        $ok = ($httpCode >= 200 && $httpCode < 300) && is_array($decoded) && ($decoded['ok'] ?? false) === true;
        if ($ok) {
            return ['success' => true, 'message' => 'Sent via Telegram.'];
        }
        $apiMessage = is_array($decoded) && is_string($decoded['description'] ?? null)
            ? $decoded['description']
            : "Telegram API returned HTTP {$httpCode}.";
        return ['success' => false, 'message' => $apiMessage];
    }
}
