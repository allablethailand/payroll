<?php
declare(strict_types=1);

/**
 * Staging area behind the Import Framework: upload -> map -> validate (repeatable, per-row edits) -> commit.
 * Every method is scoped by comp_id, and commit rebuilds its rows from the DB so the client never supplies them.
 */
class ImportStagingModel {
    public const MAX_ROWS = 5000;

    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** @param array<int, array<string,mixed>> $parsedRows raw rows keyed by file header; row_no = index + 2 */
    public function create(int $compId, string $entityType, string $fileName, ?string $filePath, ?int $fileSize, array $parsedRows, ?int $userId): int {
        if (count($parsedRows) > self::MAX_ROWS) {
            throw new InvalidArgumentException('Too many rows. Maximum is ' . self::MAX_ROWS . ' per file.');
        }
        $own = !$this->db->inTransaction();
        if ($own) { $this->db->beginTransaction(); }
        try {
            $this->db->prepare("INSERT INTO `import_staging_batches` (comp_id, entity_type, file_name, file_path, file_size, total_count, created_by)
                VALUES (:c, :e, :f, :p, :s, :t, :u)")
                ->execute([':c' => $compId, ':e' => $entityType, ':f' => substr($fileName, 0, 255), ':p' => $filePath, ':s' => $fileSize, ':t' => count($parsedRows), ':u' => $userId]);
            $batchId = (int)$this->db->lastInsertId();
            $ins = $this->db->prepare("INSERT INTO `import_staging_rows` (batch_id, row_no, raw_json) VALUES (:b, :n, :r)");
            foreach ($parsedRows as $i => $row) {
                $ins->execute([':b' => $batchId, ':n' => $i + 2, ':r' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)]);
            }
            if ($own) { $this->db->commit(); }
            return $batchId;
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    public function find(int $compId, int $batchId): ?array {
        $stmt = $this->db->prepare("SELECT id, comp_id, entity_type, file_name, file_path, file_size, mapping_json, total_count, valid_count, error_count, warning_count,
                status, created_by, created_at, validated_at, committed_at, committed_batch_id
            FROM `import_staging_batches` WHERE id = :id AND comp_id = :c");
        $stmt->execute([':id' => $batchId, ':c' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return string[] distinct file headers in first-seen order, from the raw rows */
    public function fileHeaders(int $batchId): array {
        $headers = [];
        $stmt = $this->db->prepare("SELECT raw_json FROM `import_staging_rows` WHERE batch_id = :b ORDER BY row_no LIMIT " . self::MAX_ROWS);
        $stmt->execute([':b' => $batchId]);
        while (($json = $stmt->fetchColumn()) !== false) {
            foreach (array_keys((array)json_decode((string)$json, true)) as $h) {
                $headers[(string)$h] = true;
            }
        }
        return array_keys($headers);
    }

    /** Applies the mapping to every raw row; resets validation because the mapped data changed. */
    public function applyMapping(int $batchId, array $mapping, callable $mapRows): void {
        $own = !$this->db->inTransaction();
        if ($own) { $this->db->beginTransaction(); }
        try {
            $sel = $this->db->prepare("SELECT row_no, raw_json FROM `import_staging_rows` WHERE batch_id = :b ORDER BY row_no");
            $sel->execute([':b' => $batchId]);
            $all = $sel->fetchAll(PDO::FETCH_ASSOC);
            $raw = array_map(fn($r) => (array)json_decode((string)$r['raw_json'], true), $all);
            $mapped = $mapRows($raw, $mapping)['rows'];
            $upd = $this->db->prepare("UPDATE `import_staging_rows` SET data_json = :d, status = 'pending', messages_json = NULL, edited = 0 WHERE batch_id = :b AND row_no = :n");
            foreach ($all as $i => $r) {
                $upd->execute([':d' => json_encode($mapped[$i] ?? [], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), ':b' => $batchId, ':n' => $r['row_no']]);
            }
            $this->db->prepare("UPDATE `import_staging_batches` SET mapping_json = :m, status = 'mapped', valid_count = 0, error_count = 0, warning_count = 0, validated_at = NULL WHERE id = :id")
                ->execute([':m' => json_encode($mapping, JSON_UNESCAPED_UNICODE), ':id' => $batchId]);
            if ($own) { $this->db->commit(); }
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    /**
     * @param ?int[] $rowNos null = every row
     * @return array{row_no:int, data:array}[] mapped rows in row_no order
     */
    public function mappedRows(int $batchId, ?array $rowNos = null): array {
        $sql = "SELECT row_no, data_json FROM `import_staging_rows` WHERE batch_id = :b";
        $params = [':b' => $batchId];
        if ($rowNos !== null) {
            if (!$rowNos) { return []; }
            $keys = [];
            foreach (array_values($rowNos) as $i => $n) { $keys[] = ":n{$i}"; $params[":n{$i}"] = (int)$n; }
            $sql .= " AND row_no IN (" . implode(',', $keys) . ")";
        }
        $stmt = $this->db->prepare($sql . " ORDER BY row_no");
        $stmt->execute($params);
        return array_map(fn($r) => ['row_no' => (int)$r['row_no'], 'data' => (array)json_decode((string)$r['data_json'], true)], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<int, array{status:string, messages:string[]}> $results keyed by row_no */
    public function saveValidation(int $batchId, array $results): void {
        $own = !$this->db->inTransaction();
        if ($own) { $this->db->beginTransaction(); }
        try {
            $upd = $this->db->prepare("UPDATE `import_staging_rows` SET status = :s, messages_json = :m WHERE batch_id = :b AND row_no = :n");
            foreach ($results as $rowNo => $r) {
                $upd->execute([':s' => $r['status'], ':m' => $r['messages'] ? json_encode($r['messages'], JSON_UNESCAPED_UNICODE) : null, ':b' => $batchId, ':n' => $rowNo]);
            }
            $this->recount($batchId);
            $this->db->prepare("UPDATE `import_staging_batches` SET status = 'validated', validated_at = CURRENT_TIMESTAMP WHERE id = :id")->execute([':id' => $batchId]);
            if ($own) { $this->db->commit(); }
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    /** Edited rows go back to 'pending' so commit refuses until they are re-validated. Only keys in $allowedKeys are kept. */
    public function editRows(int $batchId, array $edits, array $allowedKeys): int {
        $own = !$this->db->inTransaction();
        if ($own) { $this->db->beginTransaction(); }
        try {
            $sel = $this->db->prepare("SELECT data_json FROM `import_staging_rows` WHERE batch_id = :b AND row_no = :n");
            $upd = $this->db->prepare("UPDATE `import_staging_rows` SET data_json = :d, status = 'pending', messages_json = NULL, edited = 1 WHERE batch_id = :b AND row_no = :n");
            $count = 0;
            foreach ($edits as $edit) {
                $rowNo = (int)($edit['row_no'] ?? 0);
                $sel->execute([':b' => $batchId, ':n' => $rowNo]);
                $json = $sel->fetchColumn();
                if ($json === false || !is_array($edit['data'] ?? null)) { continue; }
                $data = (array)json_decode((string)$json, true);
                foreach ($edit['data'] as $k => $v) {
                    if (in_array($k, $allowedKeys, true) && (is_scalar($v) || $v === null)) {
                        $data[$k] = is_string($v) ? trim($v) : $v;
                    }
                }
                $upd->execute([':d' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), ':b' => $batchId, ':n' => $rowNo]);
                $count++;
            }
            if ($count > 0) {
                $this->recount($batchId);
                $this->db->prepare("UPDATE `import_staging_batches` SET status = 'mapped' WHERE id = :id AND status = 'validated'")->execute([':id' => $batchId]);
            }
            if ($own) { $this->db->commit(); }
            return $count;
        } catch (Throwable $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    /** @return array{rows: array[], total: int} paginated rows for the verification grid; $status filters one row status */
    public function rows(int $batchId, ?string $status, int $offset, int $limit): array {
        $where = "WHERE batch_id = :b";
        $params = [':b' => $batchId];
        if ($status !== null && in_array($status, ['pending', 'valid', 'warning', 'error'], true)) {
            $where .= " AND status = :s";
            $params[':s'] = $status;
        }
        $cnt = $this->db->prepare("SELECT COUNT(*) FROM `import_staging_rows` {$where}");
        $cnt->execute($params);
        $stmt = $this->db->prepare("SELECT row_no, data_json, status, messages_json, edited FROM `import_staging_rows` {$where}
            ORDER BY FIELD(status,'error','warning','pending','valid'), row_no LIMIT " . max(1, min(500, $limit)) . " OFFSET " . max(0, $offset));
        $stmt->execute($params);
        $rows = array_map(fn($r) => [
            'row_no' => (int)$r['row_no'], 'status' => $r['status'], 'edited' => (bool)$r['edited'],
            'data' => (array)json_decode((string)$r['data_json'], true),
            'messages' => $r['messages_json'] ? (array)json_decode((string)$r['messages_json'], true) : [],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['rows' => $rows, 'total' => (int)$cnt->fetchColumn()];
    }

    /** Atomically moves a validated, error-free, fully-revalidated batch to 'committing'; false if another request got there first or it is not ready. */
    public function claimForCommit(int $batchId): bool {
        $stmt = $this->db->prepare("UPDATE `import_staging_batches` SET status = 'committing'
            WHERE id = :id AND status = 'validated' AND error_count = 0
              AND NOT EXISTS (SELECT 1 FROM `import_staging_rows` r WHERE r.batch_id = :id2 AND r.status IN ('pending','error'))");
        $stmt->execute([':id' => $batchId, ':id2' => $batchId]);
        return $stmt->rowCount() === 1;
    }

    public function releaseClaim(int $batchId): void {
        $this->db->prepare("UPDATE `import_staging_batches` SET status = 'validated' WHERE id = :id AND status = 'committing'")->execute([':id' => $batchId]);
    }

    public function markCommitted(int $batchId, ?int $syncBatchId): void {
        $this->db->prepare("UPDATE `import_staging_batches` SET status = 'committed', committed_at = CURRENT_TIMESTAMP, committed_batch_id = :s WHERE id = :id")
            ->execute([':s' => $syncBatchId, ':id' => $batchId]);
        $this->purgeRows($batchId);
    }

    /** @return bool false when the batch is already committed/committing/discarded */
    public function discard(int $batchId): bool {
        $stmt = $this->db->prepare("UPDATE `import_staging_batches` SET status = 'discarded' WHERE id = :id AND status IN ('uploaded','mapped','validated')");
        $stmt->execute([':id' => $batchId]);
        if ($stmt->rowCount() !== 1) { return false; }
        $this->purgeRows($batchId);
        return true;
    }

    /** Discards batches nobody finished within $hours (rows hold personal data) and removes their stored file. @return int batches purged */
    public function purgeStale(int $hours = 24): int {
        $stmt = $this->db->prepare("SELECT id, file_path FROM `import_staging_batches`
            WHERE status IN ('uploaded','mapped','validated') AND created_at < (NOW() - INTERVAL :h HOUR) LIMIT 500");
        $stmt->bindValue(':h', max(1, $hours), PDO::PARAM_INT);
        $stmt->execute();
        $purged = 0;
        $root = realpath(__DIR__ . '/../../storage/uploads/import_originals');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
            if (!$this->discard((int)$b['id'])) { continue; }
            $purged++;
            $file = $b['file_path'] ? realpath(__DIR__ . '/../../' . $b['file_path']) : false;
            if ($file !== false && $root !== false && strpos($file, $root) === 0 && is_file($file)) {
                @unlink($file);
            }
        }
        return $purged;
    }

    /** Staged rows hold personal data -- gone once the batch is committed or discarded. */
    private function purgeRows(int $batchId): void {
        $this->db->prepare("DELETE FROM `import_staging_rows` WHERE batch_id = :b")->execute([':b' => $batchId]);
    }

    private function recount(int $batchId): void {
        $this->db->prepare("UPDATE `import_staging_batches` b SET
                valid_count = (SELECT COUNT(*) FROM `import_staging_rows` WHERE batch_id = b.id AND status = 'valid'),
                warning_count = (SELECT COUNT(*) FROM `import_staging_rows` WHERE batch_id = b.id AND status = 'warning'),
                error_count = (SELECT COUNT(*) FROM `import_staging_rows` WHERE batch_id = b.id AND status = 'error')
            WHERE b.id = :id")->execute([':id' => $batchId]);
    }
}
