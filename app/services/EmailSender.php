<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * 2026-08-30, Phase 7 (T040) -- the actual PHPMailer/SMTP call, extracted out of
 * cron/send_queued_emails.php's own top level so it's a plain, requirable, testable function (that
 * script's top-level code processes a real batch as a side effect of being included at all, which
 * would make an in-process test accidentally touch the real email_queue table). The ONLY 2 callers
 * are cron/send_queued_emails.php (the real, out-of-band sender) and tests/email_queue_test.php.
 * Same $_ENV config keys EmailChannel used to read directly before this became a queue (see that
 * class's own docblock) -- MAIL_HOST/PORT/ENCRYPTION/USERNAME/PASSWORD/FROM_ADDRESS/FROM_NAME.
 *
 * @param array $row an email_queue row shape: to_address, subject, body, attachment_path (nullable), attachment_name (nullable).
 * @return array{success: bool, message: string}
 */
function send_queued_email_via_smtp(array $row): array {
    if (empty($_ENV['MAIL_HOST']) || empty($_ENV['MAIL_FROM_ADDRESS'])) {
        return ['success' => false, 'message' => 'MAIL_HOST/MAIL_FROM_ADDRESS missing in .env.'];
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
        $mail->addAddress($row['to_address']);
        $mail->Subject = $row['subject'];
        $mail->Body = $row['body'];
        if (!empty($row['attachment_path']) && is_file($row['attachment_path'])) {
            $mail->addAttachment($row['attachment_path'], (string)($row['attachment_name'] ?? ''));
        }
        $mail->send();
        return ['success' => true, 'message' => 'Sent.'];
    } catch (PHPMailerException $e) {
        return ['success' => false, 'message' => $mail->ErrorInfo ?: $e->getMessage()];
    }
}
