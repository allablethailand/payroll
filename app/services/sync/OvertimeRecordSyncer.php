<?php
declare(strict_types=1);
require_once __DIR__ . '/AbstractTransactionDataSyncer.php';

class OvertimeRecordSyncer extends AbstractTransactionDataSyncer {
    public function entityType(): string {
        return 'overtime';
    }

    protected function tableName(): string {
        return 'overtime_records';
    }

    protected function dateColumn(): string {
        return 'ot_date';
    }

    protected function fetch(int $origamiCompanyId, OrigamiSyncClientInterface $client, string $dateFrom, string $dateTo): array {
        return $client->fetchOvertimeRecords($origamiCompanyId, $dateFrom, $dateTo);
    }

    protected function findByNaturalKey(int $compId, int $employeeId, array $item): ?int {
        $otDate = trim((string)($item['ot_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $otDate)) {
            return null;
        }
        $otRateId = $this->resolveOtRateId($compId, $item);
        if ($otRateId === null) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM overtime_records
            WHERE employee_id = :emp AND ot_date = :date AND ot_rate_id = :rate AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':emp' => $employeeId, ':date' => $otDate, ':rate' => $otRateId, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function templateColumns(): array {
        return [
            'origami_ref_id' => 'Origami Ref ID (optional)', 'employee_no' => 'Employee No.', 'ot_date' => 'OT Date (YYYY-MM-DD)',
            'ot_rate_name' => 'OT Rate Name (English -- weekday/weekend/holiday, matched against the company default OT Rate Set)', 'hours' => 'Hours', 'amount' => 'Amount (optional)',
            'status' => 'Status (pending/approved/rejected)',
        ];
    }

    /**
     * 2026-08-30 (OT Rate Set replacement): resolves against `ot_rate_set_items` (child rows of
     * `ot_rate_sets`) instead of the retired flat `ot_rates` table -- see OtRateSetModel's and
     * OtRateSyncer's own docblocks. Neither of the generic resolveOptionalRef()/
     * resolveOptionalRefByCode() helpers fit this table (both assume comp_id/deleted_at live
     * directly on the target table, which is only true one level up on `ot_rate_sets`), so this is
     * a bespoke join instead.
     *
     * By origami_ref_id (sync payload, matches whichever item OtRateSyncer stamped that ref onto,
     * usually within its own "Synced from Origami" Set) if present, else by ot_rate_name (import
     * payload) -- matched against the OT scope's own name (weekday/weekend/holiday) within the
     * company's mandatory DEFAULT Set, since an item no longer carries a free-text name of its own
     * (only the Set does) -- the Default Set is also the one most useful to resolve an imported
     * overtime record against, since it's the one that actually governs real payroll calculation.
     */
    private function resolveOtRateId(int $compId, array $item): ?int {
        if (array_key_exists('ot_rate_ref_id', $item)) {
            $refId = $item['ot_rate_ref_id'];
            if ($refId === null || $refId === '') {
                return null;
            }
            $stmt = $this->db->prepare("SELECT i.id FROM ot_rate_set_items i
                JOIN ot_rate_sets s ON s.id = i.set_id
                WHERE i.origami_ref_id = :ref AND s.comp_id = :comp AND s.deleted_at IS NULL");
            $stmt->execute([':ref' => (int)$refId, ':comp' => $compId]);
            $id = $stmt->fetchColumn();
            if ($id === false) {
                throw new InvalidArgumentException("Referenced OT rate (ref_id={$refId}) has not been synced yet -- sync it first.");
            }
            return (int)$id;
        }
        $name = trim((string)($item['ot_rate_name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT i.id FROM ot_rate_set_items i
            JOIN ot_rate_sets s ON s.id = i.set_id AND s.comp_id = :comp AND s.is_default = 1 AND s.deleted_at IS NULL
            JOIN master_ot_scope_types t ON t.id = i.ot_scope_id
            WHERE t.name_en = :name OR t.name_th = :name OR t.code = :code");
        $stmt->execute([':comp' => $compId, ':name' => $name, ':code' => strtolower($name)]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new InvalidArgumentException("Referenced OT rate (name={$name}) was not found on the company's default OT Rate Set -- create or import it first.");
        }
        return (int)$id;
    }

    protected function upsertItem(int $compId, array $item, int $employeeId, int $batchId, ?int $triggeredBy, ?int $existingId, string $dataSource): void {
        $otDate = trim((string)($item['ot_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $otDate)) {
            throw new InvalidArgumentException('Missing or invalid ot_date.');
        }
        $otRateId = $this->resolveOtRateId($compId, $item);
        if ($otRateId === null) {
            throw new InvalidArgumentException('Missing or unresolvable OT rate.');
        }
        $hours = isset($item['hours']) && is_numeric($item['hours']) ? (float)$item['hours'] : 0.0;
        $amount = isset($item['amount']) && is_numeric($item['amount']) ? (float)$item['amount'] : null;
        $status = in_array($item['status'] ?? '', ['pending', 'approved', 'rejected'], true) ? $item['status'] : 'approved';
        $refId = isset($item['ref_id']) && is_numeric($item['ref_id']) ? (int)$item['ref_id'] : null;

        if ($existingId !== null) {
            // 2026-08-30, conflict-prevention fix: see AttendanceSyncer's own equivalent comment --
            // data_source now updates on every write, not just INSERT.
            $stmt = $this->db->prepare("UPDATE overtime_records SET
                    ot_date = :ot_date, ot_rate_id = :ot_rate_id, hours = :hours, amount = :amount, status = :status,
                    data_source = :data_source, sync_batch_id = :batch_id, updated_by = :user, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([
                ':ot_date' => $otDate, ':ot_rate_id' => $otRateId, ':hours' => $hours, ':amount' => $amount, ':status' => $status,
                ':data_source' => $dataSource, ':batch_id' => $batchId, ':user' => $triggeredBy, ':id' => $existingId,
            ]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO overtime_records
                    (comp_id, employee_id, origami_ref_id, ot_date, ot_rate_id, hours, amount, status, data_source, sync_batch_id, created_by)
                VALUES (:comp_id, :employee_id, :ref_id, :ot_date, :ot_rate_id, :hours, :amount, :status, :data_source, :batch_id, :user)");
            $stmt->execute([
                ':comp_id' => $compId, ':employee_id' => $employeeId, ':ref_id' => $refId, ':ot_date' => $otDate,
                ':ot_rate_id' => $otRateId, ':hours' => $hours, ':amount' => $amount, ':status' => $status,
                ':data_source' => $dataSource, ':batch_id' => $batchId, ':user' => $triggeredBy,
            ]);
        }
    }
}
