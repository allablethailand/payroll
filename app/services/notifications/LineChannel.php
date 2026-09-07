<?php
declare(strict_types=1);
require_once __DIR__ . '/NotificationChannelInterface.php';

/**
 * 2026-09-04, Backlog Phase 11, T062 -- implemented against LINE Messaging API's real, documented
 * push-message endpoint (https://developers.line.biz/en/reference/messaging-api/#send-push-message,
 * POST https://api.line.me/v2/bot/message/push). Confirmed (not assumed) LINE's own message
 * object types are text/sticker/image/video/audio/location/imagemap/template/flex -- there is NO
 * generic "document"/arbitrary-file-attachment message type the way Telegram's sendDocument or a
 * plain email attachment has. A raw PDF cannot be pushed directly through this API at all.
 *
 * Design decision: sends a TEXT message containing a link to PayslipController::myDownload() (a
 * new, minimal, self-service "view MY OWN payslip" endpoint added specifically for this — see
 * that method's own docblock for why it's safe: employee_id is read only from the recipient's own
 * logged-in session, never from anything in this message, so the link itself carries no secret/
 * capability of its own -- it's exactly as safe as a plain email deep-link into an already-
 * session-gated app). The employee taps the link, which opens their normal browser, goes through
 * the app's existing Origami-SSO login exactly like any other in-app link if they're not already
 * signed in, and only then sees the PDF -- this is a genuine security IMPROVEMENT over Telegram/
 * email's direct-attachment approach (nothing sensitive ever transits LINE's own servers as a
 * file), not a lesser substitute forced by the API's limitation.
 *
 * HONESTY NOTE, same posture as TelegramChannel's own: built correctly per LINE's current,
 * published API spec, NOT exercised against a real LINE Official Account -- .env's own
 * LINE_CHANNEL_ACCESS_TOKEN is empty in this dev environment. Whoever connects a real channel
 * access token should do one real end-to-end send (and confirm the recipient's own line_id is
 * genuinely a valid LINE "user id", not e.g. a display name) before trusting this in production.
 */
class LineChannel implements NotificationChannelInterface {
    private const API_TIMEOUT_SECONDS = 20;

    public function code(): string {
        return 'line';
    }

    public function isConfigured(): bool {
        return !empty($_ENV['LINE_CHANNEL_ACCESS_TOKEN']);
    }

    /**
     * $recipient is expected to be employees.line_id (the LINE user id LINE itself assigns once
     * an employee has friended/messaged the company's own LINE Official Account -- obtaining that
     * id is out of scope here, same as Telegram's own chat_id).
     *
     * $attachmentPath/$attachmentName are accepted (interface contract) but NOT uploaded anywhere
     * -- the download link points back at THIS app's own already-generated payslip, not a copy
     * pushed through LINE, so there is nothing to attach on LINE's own side.
     */
    public function send(string $recipient, string $subject, string $message, string $attachmentPath, string $attachmentName): array {
        if (!$this->isConfigured()) {
            throw new RuntimeException('LineChannel::send() called while not configured (LINE_CHANNEL_ACCESS_TOKEN is empty).');
        }
        $token = (string)$_ENV['LINE_CHANNEL_ACCESS_TOKEN'];
        $url = 'https://api.line.me/v2/bot/message/push';

        $text = trim($message !== '' ? $message : $subject);
        // LINE's own 5000-character text-message limit -- truncate rather than let the whole
        // push be rejected over an oversized message.
        if (mb_strlen($text) > 5000) {
            $text = mb_substr($text, 0, 4997) . '...';
        }
        $payload = [
            'to' => $recipient,
            'messages' => [
                ['type' => 'text', 'text' => $text],
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::API_TIMEOUT_SECONDS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrno !== 0) {
            return ['success' => false, 'message' => "Could not reach LINE: {$curlError}"];
        }
        return $this->parseResponse($httpCode, (string)$body);
    }

    /**
     * Split out from send() specifically so tests/telegram_line_channel_test.php can exercise the
     * real LINE response-parsing/success-failure logic with a canned HTTP response, without a live
     * network call -- same seam TelegramChannel's own parseResponse() adds, same reasoning.
     * LINE's push-message endpoint returns an EMPTY JSON object {} with HTTP 200 on success --
     * confirmed per the API's own documented response shape, not guessed. An error response
     * carries {"message": "...", "details": [...]}.
     */
    protected function parseResponse(int $httpCode, string $rawBody): array {
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'message' => 'Sent via LINE.'];
        }
        $decoded = json_decode($rawBody, true);
        $apiMessage = is_array($decoded) && is_string($decoded['message'] ?? null)
            ? $decoded['message']
            : "LINE API returned HTTP {$httpCode}.";
        return ['success' => false, 'message' => $apiMessage];
    }
}
