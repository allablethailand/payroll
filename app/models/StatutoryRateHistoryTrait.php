<?php
declare(strict_types=1);

/**
 * 2026-09-08, Clone+Version redesign -- shared by `TaxStatutoryModel` (Master items' own dated
 * rate history, `comp_id IS NULL`) and `CompanyStatutoryRateVersionModel` (each company's own
 * cloned/customized version of a Master item, `comp_id` set on this SAME `statutory_item_rate_
 * history` table -- see database/migrations/2026-09-08_1_statutory_company_rate_versions.sql's
 * own docblock). Both classes were hand-duplicating "does this new date range overlap an
 * existing version" and "close the currently-open version before inserting the next one" --
 * pulled out here once a 3rd, comp_id-scoped copy of the exact same logic was about to be
 * written a second time. Requires `$this->db` (a PDO instance) on the using class -- both
 * classes already declare that property themselves, so this trait does not redeclare it.
 */
trait StatutoryRateHistoryTrait {
    /**
     * Overlap check scoped by BOTH statutory_item_id AND comp_id (NULL-safe -- `<=>` would also
     * work but this project's own convention elsewhere is an explicit IS NULL/`=` branch, kept
     * for consistency and to avoid a MySQL NULL-safe-equals surprising a future reader unfamiliar
     * with `<=>`). `$compId === null` means "Master's own rows" (TaxStatutoryModel's usage,
     * unchanged behavior from before this trait existed); a real `$compId` scopes the check to
     * just that one company's own cloned/customized versions, so two different companies can
     * have overlapping date ranges for "the same" Master item without conflicting with each
     * other -- confirmed as an explicit requirement of the new architecture (each company's
     * versions are fully independent of every other company's).
     */
    protected function hasOverlapForItem(int $itemId, ?int $compId, string $effectiveDate, ?string $endDate, ?int $excludeId): bool {
        $sql = "SELECT effective_date, end_date FROM `statutory_item_rate_history`
                WHERE statutory_item_id = :item_id AND deleted_at IS NULL AND "
                . ($compId === null ? "comp_id IS NULL" : "comp_id = :comp_id");
        $params = [':item_id' => $itemId];
        if ($compId !== null) {
            $params[':comp_id'] = $compId;
        }
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $newStart = $effectiveDate;
        $newEnd = $endDate ?? '9999-12-31';
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $exStart = $r['effective_date'];
            $exEnd = $r['end_date'] ?? '9999-12-31';
            if ($newStart <= $exEnd && $exStart <= $newEnd) {
                return true;
            }
        }
        return false;
    }

    /**
     * Closes whichever open-ended (end_date IS NULL) version currently precedes $effectiveDate,
     * so a brand-new open-ended version being inserted right after it doesn't leave two open-
     * ended rows active for the same item/scope at once. No-op if there is nothing open to close.
     * Caller is responsible for its own transaction (same convention as TaxStatutoryModel::
     * rateHistorySave(), which this logic was extracted from) -- this method issues one UPDATE
     * and returns, it never begins/commits anything itself.
     */
    protected function closeOpenRateVersion(int $itemId, ?int $compId, string $effectiveDate): void {
        $sql = "SELECT id FROM `statutory_item_rate_history`
                WHERE statutory_item_id = :item_id AND deleted_at IS NULL AND end_date IS NULL AND effective_date < :effective_date AND "
                . ($compId === null ? "comp_id IS NULL" : "comp_id = :comp_id")
                . " ORDER BY effective_date DESC LIMIT 1";
        $params = [':item_id' => $itemId, ':effective_date' => $effectiveDate];
        if ($compId !== null) {
            $params[':comp_id'] = $compId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $openRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($openRow) {
            $prevEnd = date('Y-m-d', strtotime($effectiveDate . ' -1 day'));
            $this->db->prepare("UPDATE `statutory_item_rate_history` SET end_date = :end_date WHERE id = :id")
                ->execute([':end_date' => $prevEnd, ':id' => $openRow['id']]);
        }
    }
}
