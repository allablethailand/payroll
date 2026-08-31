<?php
declare(strict_types=1);

/**
 * 2026-08-30, Phase 7 (T040) -- the durable queue every outbound email now goes through. See
 * `database/migrations/2026-08-30_18_email_queue.sql`'s own header comment for the full "why a
 * queue, why no FK on comp_id" reasoning. `enqueue()` is called synchronously from
 * EmailChannel::send() (fast, a single INSERT, well within a normal HTTP request); everything else
 * here is called from cron/send_queued_emails.php, the separate out-of-band CLI process that does
 * the actual, slow, network-dependent SMTP work.
 */
class EmailQueueModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function enqueue(?int $compId, string $toAddress, string $subject, string $body, ?string $attachmentPath, ?string $attachmentName): int {
        $stmt = $this->db->prepare("INSERT INTO `email_queue`
            (comp_id, to_address, subject, body, attachment_path, attachment_name, status)
            VALUES (:comp_id, :to_address, :subject, :body, :attachment_path, :attachment_name, 'pending')");
        $stmt->execute([
            ':comp_id' => $compId, ':to_address' => $toAddress, ':subject' => $subject, ':body' => $body,
            ':attachment_path' => $attachmentPath, ':attachment_name' => $attachmentName,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /** @return array<int,array> up to $limit rows still eligible to try (pending, under max_attempts), oldest first -- fair, no row starves behind an endless stream of newer ones. */
    public function claimBatch(int $limit): array {
        $stmt = $this->db->prepare("SELECT * FROM `email_queue`
            WHERE status = 'pending' AND attempts < max_attempts
            ORDER BY created_at ASC LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markSent(int $id): void {
        $stmt = $this->db->prepare("UPDATE `email_queue` SET status = 'sent', attempts = attempts + 1, sent_at = CURRENT_TIMESTAMP, error_message = NULL WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    /** A failed attempt still increments `attempts` -- once it reaches max_attempts, status flips to 'failed' (permanently given up on) instead of staying 'pending' forever and being retried indefinitely. */
    public function markFailed(int $id, string $errorMessage): void {
        $stmt = $this->db->prepare("UPDATE `email_queue`
            SET attempts = attempts + 1, error_message = :error,
                status = CASE WHEN attempts + 1 >= max_attempts THEN 'failed' ELSE 'pending' END
            WHERE id = :id");
        $stmt->execute([':error' => substr($errorMessage, 0, 60000), ':id' => $id]);
    }
}
