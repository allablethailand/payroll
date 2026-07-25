<?php
declare(strict_types=1);
require_once __DIR__ . '/AbstractMasterDataSyncer.php';

class ShiftSyncer extends AbstractMasterDataSyncer {
    public function entityType(): string {
        return 'shift';
    }

    protected function tableName(): string {
        return 'shifts';
    }

    protected function fetch(int $origamiCompanyId, OrigamiSyncClientInterface $client): array {
        return $client->fetchShifts($origamiCompanyId);
    }

    protected function findByNaturalKey(int $compId, array $item): ?int {
        $code = trim((string)($item['code'] ?? ''));
        if ($code === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM shifts WHERE shift_code = :code AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':code' => $code, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function templateColumns(): array {
        return [
            'ref_id' => 'Origami Ref ID (optional)', 'code' => 'Shift Code', 'name_th' => 'Name (Thai)', 'name_en' => 'Name (English)',
            'start_time' => 'Start Time (HH:MM:SS)', 'end_time' => 'End Time (HH:MM:SS)', 'break_minutes' => 'Break Minutes',
            'work_location_code' => 'Work Location Code (optional)',
        ];
    }

    private function resolveWorkLocationId(int $compId, ?string $code): ?int {
        if ($code === null || $code === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM master_work_locations WHERE comp_id = :comp_id AND location_code = :code AND deleted_at IS NULL");
        $stmt->execute([':comp_id' => $compId, ':code' => $code]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    protected function upsertItem(int $compId, array $item, int $batchId, ?int $triggeredBy, ?int $existingId, string $dataSource): void {
        $code = trim((string)($item['code'] ?? ''));
        $nameTh = trim((string)($item['name_th'] ?? ''));
        $nameEn = trim((string)($item['name_en'] ?? ''));
        $startTime = trim((string)($item['start_time'] ?? ''));
        $endTime = trim((string)($item['end_time'] ?? ''));
        if ($code === '' || $nameTh === '' || $nameEn === '' || $startTime === '' || $endTime === '') {
            throw new InvalidArgumentException('Missing code/name_th/name_en/start_time/end_time.');
        }
        $breakMinutes = isset($item['break_minutes']) && is_numeric($item['break_minutes']) ? (int)$item['break_minutes'] : 0;
        $workLocationId = $this->resolveWorkLocationId($compId, $item['work_location_code'] ?? null);
        $refId = isset($item['ref_id']) && is_numeric($item['ref_id']) ? (int)$item['ref_id'] : null;
        if ($existingId !== null) {
            $stmt = $this->db->prepare("UPDATE shifts SET
                    shift_code = :code, shift_name_th = :name_th, shift_name_en = :name_en,
                    start_time = :start_time, end_time = :end_time, break_minutes = :break_minutes, work_location_id = :work_location_id,
                    status = 'active', sync_batch_id = :batch_id, updated_by = :user, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([
                ':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn, ':start_time' => $startTime, ':end_time' => $endTime,
                ':break_minutes' => $breakMinutes, ':work_location_id' => $workLocationId, ':batch_id' => $batchId, ':user' => $triggeredBy, ':id' => $existingId,
            ]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO shifts
                    (comp_id, shift_code, shift_name_th, shift_name_en, start_time, end_time, break_minutes, work_location_id, status, origami_ref_id, data_source, sync_batch_id, created_by)
                VALUES (:comp_id, :code, :name_th, :name_en, :start_time, :end_time, :break_minutes, :work_location_id, 'active', :ref_id, :data_source, :batch_id, :user)");
            $stmt->execute([
                ':comp_id' => $compId, ':code' => $code, ':name_th' => $nameTh, ':name_en' => $nameEn, ':start_time' => $startTime, ':end_time' => $endTime,
                ':break_minutes' => $breakMinutes, ':work_location_id' => $workLocationId, ':ref_id' => $refId, ':data_source' => $dataSource, ':batch_id' => $batchId, ':user' => $triggeredBy,
            ]);
        }
    }
}
