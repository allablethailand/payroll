<?php
declare(strict_types=1);
require_once __DIR__ . '/AbstractTransactionDataSyncer.php';

class AttendanceSyncer extends AbstractTransactionDataSyncer {
    public function entityType(): string {
        return 'attendance';
    }

    protected function tableName(): string {
        return 'attendance_records';
    }

    protected function dateColumn(): string {
        return 'work_date';
    }

    protected function fetch(int $origamiCompanyId, OrigamiSyncClientInterface $client, string $dateFrom, string $dateTo): array {
        return $client->fetchAttendance($origamiCompanyId, $dateFrom, $dateTo);
    }

    protected function findByNaturalKey(int $compId, int $employeeId, array $item): ?int {
        $workDate = trim((string)($item['work_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM attendance_records WHERE employee_id = :emp AND work_date = :date AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':emp' => $employeeId, ':date' => $workDate, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function templateColumns(): array {
        return [
            'origami_ref_id' => 'Origami Ref ID (optional)', 'employee_no' => 'Employee No.', 'work_date' => 'Work Date (YYYY-MM-DD)',
            'shift_code' => 'Shift Code (optional)', 'clock_in' => 'Clock In (YYYY-MM-DD HH:MM:SS, optional)', 'clock_out' => 'Clock Out (YYYY-MM-DD HH:MM:SS, optional)',
            'status' => 'Status (present/absent/leave/holiday)',
        ];
    }

    /** Resolves shift by origami_ref_id (sync payload) or shift_code (import payload) -- whichever key is present. */
    private function resolveShiftId(int $compId, array $item): ?int {
        if (array_key_exists('shift_ref_id', $item)) {
            return $this->resolveOptionalRef('shifts', $compId, $item['shift_ref_id']);
        }
        return $this->resolveOptionalRefByCode('shifts', 'shift_code', $compId, $item['shift_code'] ?? null);
    }

    protected function upsertItem(int $compId, array $item, int $employeeId, int $batchId, ?int $triggeredBy, ?int $existingId, string $dataSource): void {
        $workDate = trim((string)($item['work_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            throw new InvalidArgumentException('Missing or invalid work_date.');
        }
        $shiftId = $this->resolveShiftId($compId, $item);
        $clockIn = !empty($item['clock_in']) ? (string)$item['clock_in'] : null;
        $clockOut = !empty($item['clock_out']) ? (string)$item['clock_out'] : null;
        $actualMinutes = null;
        if ($clockIn && $clockOut) {
            $diff = (strtotime($clockOut) - strtotime($clockIn)) / 60;
            $actualMinutes = $diff > 0 ? (int)$diff : null;
        }
        $status = in_array($item['status'] ?? '', ['present', 'absent', 'leave', 'holiday'], true) ? $item['status'] : 'present';
        $lateMinutes = isset($item['late_minutes']) && is_numeric($item['late_minutes']) ? (int)$item['late_minutes'] : 0;
        $earlyMinutes = isset($item['early_leave_minutes']) && is_numeric($item['early_leave_minutes']) ? (int)$item['early_leave_minutes'] : 0;
        $refId = isset($item['ref_id']) && is_numeric($item['ref_id']) ? (int)$item['ref_id'] : null;

        if ($existingId !== null) {
            // 2026-08-30, conflict-prevention fix: data_source now updates on every write, not just
            // INSERT -- see AbstractTransactionDataSyncer's own docblock for why a stale source
            // badge was a real bug (e.g. a sync-derived row hand-corrected via Manual Entry kept
            // showing "sync" forever).
            $stmt = $this->db->prepare("UPDATE attendance_records SET
                    work_date = :work_date, shift_id = :shift_id, clock_in = :clock_in, clock_out = :clock_out,
                    actual_work_minutes = :actual_minutes, late_minutes = :late, early_leave_minutes = :early, status = :status,
                    data_source = :data_source, sync_batch_id = :batch_id, updated_by = :user, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([
                ':work_date' => $workDate, ':shift_id' => $shiftId, ':clock_in' => $clockIn, ':clock_out' => $clockOut,
                ':actual_minutes' => $actualMinutes, ':late' => $lateMinutes, ':early' => $earlyMinutes, ':status' => $status,
                ':data_source' => $dataSource, ':batch_id' => $batchId, ':user' => $triggeredBy, ':id' => $existingId,
            ]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO attendance_records
                    (comp_id, employee_id, origami_ref_id, work_date, shift_id, clock_in, clock_out, actual_work_minutes,
                     late_minutes, early_leave_minutes, status, data_source, sync_batch_id, created_by)
                VALUES (:comp_id, :employee_id, :ref_id, :work_date, :shift_id, :clock_in, :clock_out, :actual_minutes,
                     :late, :early, :status, :data_source, :batch_id, :user)");
            $stmt->execute([
                ':comp_id' => $compId, ':employee_id' => $employeeId, ':ref_id' => $refId, ':work_date' => $workDate,
                ':shift_id' => $shiftId, ':clock_in' => $clockIn, ':clock_out' => $clockOut, ':actual_minutes' => $actualMinutes,
                ':late' => $lateMinutes, ':early' => $earlyMinutes, ':status' => $status, ':data_source' => $dataSource,
                ':batch_id' => $batchId, ':user' => $triggeredBy,
            ]);
        }
    }
}
