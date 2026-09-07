<?php
declare(strict_types=1);

/**
 * 2026-09-05, Backlog Phase 13 -- platform-wide release notes shown on Help > Version. Pure
 * reference/display data (see the migration's own docblock for why this is a master table, not
 * code-driven logic) -- no per-company scoping, every company sees the same list. Read-only from
 * the app's own UI for now (no admin CRUD screen built this round -- rows are added directly, same
 * "insert a row, no code deploy needed" convention every other master table in this project uses,
 * see docs/release-process.md for the intended ongoing process).
 */
class ChangelogModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function list(): array {
        $stmt = $this->db->prepare(
            "SELECT id, version_label, release_date, title_th, title_en, body_th, body_en
             FROM `app_changelog_entries`
             WHERE is_active = 1
             ORDER BY release_date DESC, sort_order DESC, id DESC"
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
