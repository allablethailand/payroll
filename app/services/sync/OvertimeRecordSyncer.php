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
            'ot_rate_name' => 'OT Rate Name (English, must match an existing OT Rate)', 'hours' => 'Hours', 'amount' => 'Amount (optional)',
            'status' => 'Status (pending/approved/rejected)',
        ];
    }

    /** Resolves by origami_ref_id (sync payload) or ot_rate_name (import payload, matching ot_rates.ot_name_en) -- whichever key is present. */
    private function resolveOtRateId(int $compId, array $item): ?int {
        if (array_key_exists('ot_rate_ref_id', $item)) {
            return $this->resolveOptionalRef('ot_rates', $compId, $item['ot_rate_ref_id']);
        }
        return $this->resolveOptionalRefByCode('ot_rates', 'ot_name_en', $compId, $item['ot_rate_name'] ?? null);
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
            $stmt = $this->db->prepare("UPDATE overtime_records SET
                    ot_date = :ot_date, ot_rate_id = :ot_rate_id, hours = :hours, amount = :amount, status = :status,
                    sync_batch_id = :batch_id, updated_by = :user, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([
                ':ot_date' => $otDate, ':ot_rate_id' => $otRateId, ':hours' => $hours, ':amount' => $amount, ':status' => $status,
                ':batch_id' => $batchId, ':user' => $triggeredBy, ':id' => $existingId,
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
