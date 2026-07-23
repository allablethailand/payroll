<?php
declare(strict_types=1);
require_once __DIR__ . '/NotificationChannelInterface.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * SMTP email delivery via PHPMailer (added as a composer dependency specifically for this --
 * this codebase had no mail library before). Configured entirely from $_ENV (MAIL_HOST/PORT/
 * ENCRYPTION/USERNAME/PASSWORD/FROM_ADDRESS/FROM_NAME), same convention as EncryptionService
 * reading $_ENV directly rather than config.php constants.
 *
 * NOT verified against a real mailbox in this environment (no SMTP credentials available here) --
 * verified only that it builds a message and calls PHPMailer::send() without a fatal error when
 * MAIL_HOST is empty (isConfigured() correctly returns false in that case, see
 * tests/payslip_delivery_test.php). Treat as unverified until tried against a real SMTP account,
 * same caveat style as PndOneKorExporter/Sso110Exporter's DRAFT status elsewhere in this project.
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
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $_ENV['MAIL_HOST'];
            $mail->Port = (int)($_ENV['MAIL_PORT'] ?? 587);
            $encryption = (string)($_ENV['MAIL_ENCRYPTION'] ?? 'tls');
            if ($encryption !== '') {
                $mail->SMTPSecure = $encryption;
            }
            if (!empty($_ENV['MAIL_USERNAME'])) {
                $mail->SMTPAuth = true;
                $mail->Username = $_ENV['MAIL_USERNAME'];
                $mail->Password = (string)($_ENV['MAIL_PASSWORD'] ?? '');
            }
            $mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], (string)($_ENV['MAIL_FROM_NAME'] ?? 'Origami Payroll'));
            $mail->addAddress($recipient);
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->addAttachment($attachmentPath, $attachmentName);
            $mail->send();
            return ['success' => true, 'message' => 'Sent.'];
        } catch (PHPMailerException $e) {
            return ['success' => false, 'message' => $mail->ErrorInfo ?: $e->getMessage()];
        }
    }
}
