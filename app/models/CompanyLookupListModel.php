<?php
declare(strict_types=1);

/**
 * Batch 3A item 7b: generic "company lookup list" write-side helper -- a small, per-company,
 * free-growing list of names (SSO-registered hospitals, PVD investment plans) with no real
 * government/master dataset available to seed in this environment (confirmed with the user before
 * building this). The READ side (select2 ajax options) reuses `MasterModel::master()`'s existing
 * generic comp_id-scoped table-driven resolver (just 2 more `$tableMap` rows -- 'hospital'/
 * 'pvd_plan') since that already does exactly this shape of query; this class exists ONLY for the
 * one piece that resolver doesn't do: resolve a Select2 "tag" (typed free text OR an existing
 * numeric id) into a real row id, auto-creating a new row the first time a name is used.
 *
 * ONE model, parameterized by table name (2026-09-10, explicit instruction: "ทั้งสองตารางใช้
 * helper/pattern เดียวกัน ไม่เขียน model ซ้ำ 2 ชุด") -- add a table to ALLOWED_TABLES to reuse this
 * for a future lookup list of the same shape, never write a near-duplicate class.
 *
 * No Setup management page exists yet for either list (rename/merge-duplicates is BACKLOG'd).
 */
class CompanyLookupListModel {
    private PDO $db;
    private const ALLOWED_TABLES = ['company_hospitals', 'company_pvd_plans'];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    private function assertAllowedTable(string $table): void {
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new InvalidArgumentException("Unknown company lookup list table: {$table}");
        }
    }

    /**
     * $rawValue is whatever the Select2 tags field submitted -- either a real existing row's numeric
     * id (the common case: HR picked an existing entry) or free text (a brand-new Select2 "tag",
     * where Select2's own default createTag behavior sets id===text===what was typed). A purely
     * numeric string is treated as an existing id ONLY if it actually belongs to this company as an
     * active row; anything else (including a numeric-LOOKING id that doesn't resolve, which can't
     * really happen from the real UI but is handled defensively) falls through to the name-based
     * lookup-or-create path.
     *
     * Case-insensitive + trimmed dedup (2026-09-10, explicit instruction: "กันซ้ำแบบ
     * case-insensitive") -- reuses an existing ACTIVE row if one matches after trimming/lowercasing,
     * regardless of how this particular submission was cased/spaced, per this project's own "unique
     * constraint with deleted_at needs an application-layer re-check" convention (CLAUDE.md) -- there
     * is no DB-level unique index doing this job.
     */
    public function resolveOrCreate(string $table, int $compId, $rawValue, int $userId): ?int {
        $this->assertAllowedTable($table);
        $rawValue = is_string($rawValue) ? trim($rawValue) : $rawValue;
        if ($rawValue === null || $rawValue === '') {
            return null;
        }
        if (is_numeric($rawValue)) {
            $stmt = $this->db->prepare("SELECT id FROM `{$table}` WHERE id = :id AND comp_id = :comp_id AND status = 'active'");
            $stmt->execute([':id' => (int)$rawValue, ':comp_id' => $compId]);
            $existingId = $stmt->fetchColumn();
            if ($existingId !== false) {
                return (int)$existingId;
            }
        }
        return $this->findOrCreateByName($table, $compId, (string)$rawValue, $userId);
    }

    private function findOrCreateByName(string $table, int $compId, string $rawName, int $userId): ?int {
        $name = trim($rawName);
        if ($name === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM `{$table}` WHERE comp_id = :comp_id AND status = 'active' AND LOWER(name) = LOWER(:name) LIMIT 1");
        $stmt->execute([':comp_id' => $compId, ':name' => $name]);
        $existingId = $stmt->fetchColumn();
        if ($existingId !== false) {
            return (int)$existingId;
        }
        $stmt = $this->db->prepare("INSERT INTO `{$table}` (comp_id, name, created_by) VALUES (:comp_id, :name, :created_by)");
        $stmt->execute([':comp_id' => $compId, ':name' => $name, ':created_by' => $userId]);
        return (int)$this->db->lastInsertId();
    }

    public function get(string $table, int $compId, int $id): ?array {
        $this->assertAllowedTable($table);
        $stmt = $this->db->prepare("SELECT id, name FROM `{$table}` WHERE id = :id AND comp_id = :comp_id AND status = 'active'");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
