<?php
declare(strict_types=1);

/**
 * Company-level Provident Fund employer contribution ladder ("อายุงานตั้งแต่ (ปี) -> % นายจ้าง"),
 * `company_pvd_employer_rate_ladders`. Batch 3A item 7a. `ladder_type` is a real column (not
 * hardcoded to this one use) so a future tier type (e.g. vesting) can reuse this same table/model
 * shape without a new migration -- only `save()`'s validation is specific to 'employer_rate' right
 * now, per the confirmed scope (UI/validation for other ladder types is a future task).
 *
 * Whole-set replace on save (delete+reinsert per (comp_id, ladder_type), same convention as
 * `approval_workflow_steps`/`holiday_assignments`) -- a ladder is a single ordered tier list, not a
 * record list with independent per-row lifecycles.
 */
class PvdEmployerRateLadderModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** Active tiers for this company, ordered by min_service_years ascending (0 first). */
    public function list(int $compId, string $ladderType = 'employer_rate'): array {
        $stmt = $this->db->prepare("SELECT id, min_service_years, max_service_years, rate_percent
            FROM `company_pvd_employer_rate_ladders`
            WHERE comp_id = :comp_id AND ladder_type = :ladder_type AND status = 'active' AND deleted_at IS NULL
            ORDER BY min_service_years ASC");
        $stmt->execute([':comp_id' => $compId, ':ladder_type' => $ladderType]);
        return array_map(function (array $row): array {
            return [
                'id' => (int)$row['id'],
                'min_service_years' => (float)$row['min_service_years'],
                'max_service_years' => $row['max_service_years'] !== null ? (float)$row['max_service_years'] : null,
                'rate_percent' => (float)$row['rate_percent'],
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Validates a proposed tier set: at least 1 row, sorted by min_service_years, first row's min
     * must be exactly 0, every row's max must equal the NEXT row's min exactly (no gap, no overlap),
     * only the LAST row may have max=null (open-ended), rate_percent within 0-100.
     * Returns an error message string, or null when valid. $rows is mutated (sorted) by reference.
     */
    private function validateRows(array &$rows): ?string {
        if (empty($rows)) {
            return 'At least one tier is required.';
        }
        foreach ($rows as $r) {
            if (!is_numeric($r['min_service_years'] ?? null) || (float)$r['min_service_years'] < 0) {
                return 'Each tier\'s starting year must be zero or greater.';
            }
            if (isset($r['max_service_years']) && $r['max_service_years'] !== null && !is_numeric($r['max_service_years'])) {
                return 'Invalid ending year value.';
            }
            if (!is_numeric($r['rate_percent'] ?? null) || (float)$r['rate_percent'] < 0 || (float)$r['rate_percent'] > 100) {
                return 'Rate must be between 0 and 100.';
            }
        }
        usort($rows, fn($a, $b) => (float)$a['min_service_years'] <=> (float)$b['min_service_years']);
        if ((float)$rows[0]['min_service_years'] !== 0.0) {
            return 'The first tier must start at 0 years.';
        }
        $count = count($rows);
        foreach ($rows as $i => $r) {
            $max = isset($r['max_service_years']) ? $r['max_service_years'] : null;
            $isLast = $i === $count - 1;
            if ($isLast) {
                if ($max !== null) {
                    return 'The last tier must be open-ended (no ending year).';
                }
                continue;
            }
            if ($max === null) {
                return 'Only the last tier may be open-ended.';
            }
            $nextMin = (float)$rows[$i + 1]['min_service_years'];
            if ((float)$max !== $nextMin) {
                return 'Tiers must be continuous with no gap or overlap (tier ending year must equal the next tier\'s starting year).';
            }
        }
        return null;
    }

    /**
     * @param array<int,array{min_service_years:float,max_service_years:?float,rate_percent:float}> $rows
     */
    public function save(int $compId, array $rows, int $userId, string $ladderType = 'employer_rate'): array {
        $error = $this->validateRows($rows);
        if ($error !== null) {
            return ['status' => false, 'message' => $error];
        }
        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            $this->db->prepare("UPDATE `company_pvd_employer_rate_ladders`
                    SET status = 'deleted', deleted_by = :deleted_by, deleted_at = CURRENT_TIMESTAMP
                    WHERE comp_id = :comp_id AND ladder_type = :ladder_type AND status = 'active'")
                ->execute([':deleted_by' => $userId, ':comp_id' => $compId, ':ladder_type' => $ladderType]);

            $stmtInsert = $this->db->prepare("INSERT INTO `company_pvd_employer_rate_ladders`
                    (comp_id, ladder_type, min_service_years, max_service_years, rate_percent, created_by)
                VALUES (:comp_id, :ladder_type, :min_years, :max_years, :rate_percent, :created_by)");
            foreach ($rows as $r) {
                $stmtInsert->execute([
                    ':comp_id' => $compId, ':ladder_type' => $ladderType,
                    ':min_years' => (float)$r['min_service_years'],
                    ':max_years' => isset($r['max_service_years']) && $r['max_service_years'] !== null ? (float)$r['max_service_years'] : null,
                    ':rate_percent' => (float)$r['rate_percent'],
                    ':created_by' => $userId,
                ]);
            }
            if ($own) { $this->db->commit(); }
            return ['status' => true, 'message' => 'Saved successfully.'];
        } catch (PDOException $e) {
            if ($own && $this->db->inTransaction()) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /**
     * Deletes every active row of this ladder type (soft), returning the company to "no ladder
     * configured" (opt-in behavior falls back to the flat company/master rate).
     */
    public function clear(int $compId, int $userId, string $ladderType = 'employer_rate'): array {
        try {
            $this->db->prepare("UPDATE `company_pvd_employer_rate_ladders`
                    SET status = 'deleted', deleted_by = :deleted_by, deleted_at = CURRENT_TIMESTAMP
                    WHERE comp_id = :comp_id AND ladder_type = :ladder_type AND status = 'active'")
                ->execute([':deleted_by' => $userId, ':comp_id' => $compId, ':ladder_type' => $ladderType]);
            return ['status' => true, 'message' => 'Cleared successfully.'];
        } catch (PDOException $e) {
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /**
     * Whole years (decimal) of tenure between $startDate and $asOfDate, day-count based
     * (asOfDate - startDate in days) / 365.25 -- consistent and trivially testable, not a
     * calendar-aware (leap-year-exact) age calculation, which this tiering doesn't need.
     */
    public static function serviceYears(string $startDate, string $asOfDate): float {
        $start = strtotime($startDate);
        $asOf = strtotime($asOfDate);
        if ($start === false || $asOf === false || $asOf < $start) {
            return 0.0;
        }
        return ($asOf - $start) / 86400 / 365.25;
    }

    /**
     * Resolves which tier (if any) applies for $serviceYears against this company's active ladder.
     * Returns null when the company has no active rows for $ladderType at all (opt-in: caller must
     * fall through to its own default). $serviceYears below 0 is clamped to 0 (can't happen via
     * serviceYears() above, but defensive for a direct caller).
     */
    public function resolveTier(int $compId, float $serviceYears, string $ladderType = 'employer_rate'): ?array {
        $rows = $this->list($compId, $ladderType);
        if (empty($rows)) {
            return null;
        }
        $serviceYears = max(0.0, $serviceYears);
        foreach ($rows as $row) {
            $inRange = $serviceYears >= $row['min_service_years']
                && ($row['max_service_years'] === null || $serviceYears < $row['max_service_years']);
            if ($inRange) {
                return $row;
            }
        }
        // Defensive fallback -- validateRows() guarantees full 0..open-ended coverage at save time,
        // so this is unreachable for data saved through this model, but never leave a real employee
        // unresolved if the table were ever edited outside this model's own validation.
        return $rows[count($rows) - 1];
    }
}
