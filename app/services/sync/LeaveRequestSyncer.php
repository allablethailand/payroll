<?php
declare(strict_types=1);
require_once __DIR__ . '/AbstractTransactionDataSyncer.php';

class LeaveRequestSyncer extends AbstractTransactionDataSyncer {
    public function entityType(): string {
        return 'leave';
    }

    protected function tableName(): string {
        return 'leave_requests';
    }

    protected function dateColumn(): string {
        return 'start_date';
    }

    protected function fetch(int $origamiCompanyId, OrigamiSyncClientInterface $client, string $dateFrom, string $dateTo): array {
        return $client->fetchLeaveRequests($origamiCompanyId, $dateFrom, $dateTo);
    }

    protected function findByNaturalKey(int $compId, int $employeeId, array $item): ?int {
        $startDate = trim((string)($item['start_date'] ?? ''));
        $endDate = trim((string)($item['end_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            return null;
        }
        $leaveTypeId = $this->resolveLeaveTypeId($compId, $item);
        if ($leaveTypeId === null) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM leave_requests
            WHERE employee_id = :emp AND leave_type_id = :lt AND start_date = :start AND end_date = :end AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':emp' => $employeeId, ':lt' => $leaveTypeId, ':start' => $startDate, ':end' => $endDate, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function templateColumns(): array {
        return [
            'origami_ref_id' => 'Origami Ref ID (optional)', 'employee_no' => 'Employee No.', 'leave_type_code' => 'Leave Type Code',
            'start_date' => 'Start Date (YYYY-MM-DD)', 'end_date' => 'End Date (YYYY-MM-DD)', 'total_days' => 'Total Days',
            'reason' => 'Reason (optional)', 'status' => 'Status (pending/approved/rejected/cancelled)',
        ];
    }

    /** Resolves by origami_ref_id (sync payload) or leave_type_code (import payload) -- whichever key is present. */
    private function resolveLeaveTypeId(int $compId, array $item): ?int {
        if (array_key_exists('leave_type_ref_id', $item)) {
            return $this->resolveOptionalRef('leave_types', $compId, $item['leave_type_ref_id']);
        }
        return $this->resolveOptionalRefByCode('leave_types', 'code', $compId, $item['leave_type_code'] ?? null);
    }

    protected function upsertItem(int $compId, array $item, int $employeeId, int $batchId, ?int $triggeredBy, ?int $existingId, string $dataSource): void {
        $startDate = trim((string)($item['start_date'] ?? ''));
        $endDate = trim((string)($item['end_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            throw new InvalidArgumentException('Missing or invalid start_date/end_date.');
        }
        $leaveTypeId = $this->resolveLeaveTypeId($compId, $item);
        if ($leaveTypeId === null) {
            throw new InvalidArgumentException('Missing or unresolvable leave type.');
        }
        $totalDays = isset($item['total_days']) && is_numeric($item['total_days']) ? (float)$item['total_days'] : 0.0;
        $reason = !empty($item['reason']) ? trim((string)$item['reason']) : null;
        $status = in_array($item['status'] ?? '', ['pending', 'approved', 'rejected', 'cancelled'], true) ? $item['status'] : 'approved';
        $refId = isset($item['ref_id']) && is_numeric($item['ref_id']) ? (int)$item['ref_id'] : null;

        if ($existingId !== null) {
            $stmt = $this->db->prepare("UPDATE leave_requests SET
                    leave_type_id = :leave_type_id, start_date = :start_date, end_date = :end_date,
                    total_days = :total_days, reason = :reason, status = :status,
                    sync_batch_id = :batch_id, updated_by = :user, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([
                ':leave_type_id' => $leaveTypeId, ':start_date' => $startDate, ':end_date' => $endDate,
                ':total_days' => $totalDays, ':reason' => $reason, ':status' => $status,
                ':batch_id' => $batchId, ':user' => $triggeredBy, ':id' => $existingId,
            ]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO leave_requests
                    (comp_id, employee_id, origami_ref_id, leave_type_id, start_date, end_date, total_days, reason, status, data_source, sync_batch_id, created_by)
                VALUES (:comp_id, :employee_id, :ref_id, :leave_type_id, :start_date, :end_date, :total_days, :reason, :status, :data_source, :batch_id, :user)");
            $stmt->execute([
                ':comp_id' => $compId, ':employee_id' => $employeeId, ':ref_id' => $refId, ':leave_type_id' => $leaveTypeId,
                ':start_date' => $startDate, ':end_date' => $endDate, ':total_days' => $totalDays, ':reason' => $reason,
                ':status' => $status, ':data_source' => $dataSource, ':batch_id' => $batchId, ':user' => $triggeredBy,
            ]);
        }
    }
}
