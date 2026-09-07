<?php
declare(strict_types=1);

/**
 * 2026-09-05, Backlog Phase 13 -- login-gate Terms & Conditions (forced-scroll modal, versioned,
 * acceptance logged). PLATFORM-WIDE, not per-company (see the migration's own docblock for why --
 * a "terms of use for this system" agreement is the same document for every company, unlike most
 * content tables in this project). Explicit request confirmed via AskUserQuestion: content is a
 * clearly-labeled PLACEHOLDER for now, real legal text is a separate later task -- this model only
 * builds the mechanism (versioning + acceptance tracking), it has no opinion on what the text says.
 *
 * Only ONE row is ever `is_active=1` at a time, enforced here at the application layer (same
 * "single active X" convention as every other such rule elsewhere in this project -- no DB
 * constraint needed for a table this small/admin-only). An employee must accept the CURRENTLY
 * active version specifically -- accepting an older version (from before an admin published a new
 * one) does not count, so publishing a new version re-prompts everyone, by design.
 */
class TermsAndConditionsModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** @return ?array{id:int, version_label:string, content_th:string, content_en:string, effective_date:?string} */
    public function getActive(): ?array {
        $stmt = $this->db->prepare("SELECT id, version_label, content_th, content_en, effective_date FROM `terms_and_conditions` WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Whether $employeeId has accepted the CURRENTLY active version specifically. False (not an
     *  error) when there is no active version at all -- nothing to gate on in that case. */
    public function hasAcceptedActive(int $employeeId): bool {
        $active = $this->getActive();
        if ($active === null) {
            return true;
        }
        $stmt = $this->db->prepare("SELECT 1 FROM `terms_and_conditions_acceptances` WHERE terms_id = :terms_id AND employee_id = :employee_id LIMIT 1");
        $stmt->execute([':terms_id' => $active['id'], ':employee_id' => $employeeId]);
        return (bool)$stmt->fetchColumn();
    }

    /** Records acceptance of the CURRENTLY active version by $employeeId. Idempotent -- accepting
     *  a version already accepted (e.g. a duplicate submit) is a harmless no-op, not an error,
     *  thanks to the table's own UNIQUE (terms_id, employee_id) key. */
    public function acceptActive(int $employeeId, ?string $ipAddress): array {
        $active = $this->getActive();
        if ($active === null) {
            return ['status' => false, 'message' => 'No active Terms & Conditions to accept.'];
        }
        try {
            $this->db->prepare(
                "INSERT INTO `terms_and_conditions_acceptances` (terms_id, employee_id, ip_address) VALUES (:terms_id, :employee_id, :ip)"
            )->execute([':terms_id' => $active['id'], ':employee_id' => $employeeId, ':ip' => $ipAddress]);
        } catch (PDOException $e) {
            // Duplicate (already accepted this exact version) -- not a real failure, see docblock.
            if ((int)$e->getCode() !== 23000) {
                throw $e;
            }
        }
        return ['status' => true, 'message' => 'Accepted.'];
    }

    /** Self-service "view my acceptance history" -- Profile > Terms and Conditions. `terms_id`
     *  included (2026-09-07) so the frontend's own version-history table can link each row to
     *  getVersionForEmployee() below and show that EXACT version's actual text, not just its label. */
    public function acceptanceHistory(int $employeeId): array {
        $stmt = $this->db->prepare(
            "SELECT a.terms_id, t.version_label, t.effective_date, a.accepted_at
             FROM `terms_and_conditions_acceptances` a
             JOIN `terms_and_conditions` t ON t.id = a.terms_id
             WHERE a.employee_id = :employee_id
             ORDER BY a.accepted_at DESC"
        );
        $stmt->execute([':employee_id' => $employeeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 2026-09-07, explicit design question answered: "ถ้ามีหลาย Version จะแสดงยังไง...เป็นตารางก่อน
     * แล้วค่อยกดดูข้อความ" -- Profile > Terms and Conditions' version-history table lets an
     * employee click back into any PAST version's own full text, not just its label/date. Scoped
     * to versions THIS employee has actually accepted (an `acceptances` row must exist for
     * (terms_id, employeeId)) -- never exposes a version they were never prompted with (e.g. one
     * published and superseded before they ever logged in), same "always MY OWN" access
     * convention as every other method on this model/controller.
     *
     * @return ?array{version_label:string, effective_date:?string, content_th:string, content_en:string}
     */
    public function getVersionForEmployee(int $termsId, int $employeeId): ?array {
        $stmt = $this->db->prepare(
            "SELECT t.version_label, t.effective_date, t.content_th, t.content_en
             FROM `terms_and_conditions_acceptances` a
             JOIN `terms_and_conditions` t ON t.id = a.terms_id
             WHERE a.terms_id = :terms_id AND a.employee_id = :employee_id
             LIMIT 1"
        );
        $stmt->execute([':terms_id' => $termsId, ':employee_id' => $employeeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
