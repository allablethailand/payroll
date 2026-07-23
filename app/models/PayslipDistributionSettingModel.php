<?php
declare(strict_types=1);

/**
 * Company-wide Payslip Distribution policy: one settings row per company (singleton, not a
 * deletable list), plus an ordered channel/fallback chain child table. `channel_code` values
 * are validated against master_notification_channels.code at the application layer (same
 * convention as payslip_template_fields.field_key -> master_payslip_field_types.code -- not a
 * literal FK).
 *
 * scope_department_ids / scope_employment_statuses only constrain the 'auto' distribution
 * path (which employees get an auto-send on a run reaching 'paid'); Mode B (payslip_requests)
 * is always available to any employee regardless of these scopes.
 */
class PayslipDistributionSettingModel {
    private PDO $db;

    private const VALID_MODES = ['auto', 'request_only', 'both'];
    private const VALID_EMPLOYMENT_STATUSES = ['probation', 'permanent', 'contract', 'resigned', 'terminated'];

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function channelOptions(): array {
        $stmt = $this->db->query("SELECT code AS id, name_th AS text_th, name_en AS text_en
            FROM master_notification_channels WHERE is_active = 1 ORDER BY sort_order ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function activeChannelCodeSet(): array {
        return array_flip(array_column($this->channelOptions(), 'id'));
    }

    /**
     * Always returns a shape the settings form can render, even if the company hasn't saved one
     * yet (id = null). `channels`/`scope_departments` are resolved with name_th/name_en (like
     * ApprovalWorkflowModel's `document_types`) so the frontend can pre-populate Select2 labels
     * without a second round-trip.
     */
    public function get(int $compId): array {
        $stmt = $this->db->prepare("SELECT * FROM payslip_distribution_settings WHERE comp_id = :comp_id");
        $stmt->execute([':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [
                'id' => null, 'comp_id' => $compId, 'distribution_mode' => 'request_only',
                'send_delay_hours' => 0, 'scope_department_ids' => null, 'scope_employment_statuses' => null,
                'is_active' => 1, 'channels' => [], 'scope_departments' => [],
            ];
        }
        $row['channels'] = $this->getChannels((int)$row['id']);
        $row['scope_departments'] = $this->resolveDepartments((string)($row['scope_department_ids'] ?? ''), $compId);
        return $row;
    }

    private function getChannels(int $settingId): array {
        $stmt = $this->db->prepare("SELECT c.channel_code AS code, m.name_th, m.name_en
            FROM payslip_distribution_channels c
            JOIN master_notification_channels m ON m.code = c.channel_code
            WHERE c.setting_id = :id ORDER BY c.sort_order ASC");
        $stmt->execute([':id' => $settingId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function resolveDepartments(string $csv, int $compId): array {
        $ids = array_values(array_filter(array_map('intval', explode(',', $csv))));
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT id, department_name_th AS name_th, department_name_en AS name_en
            FROM structure_departments WHERE id IN ({$placeholders}) AND comp_id = ?");
        $stmt->execute([...$ids, $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function validateDepartmentIds(array $ids, int $compId): ?string {
        if (empty($ids)) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM structure_departments
            WHERE id IN ({$placeholders}) AND comp_id = ? AND status = 'active' AND deleted_at IS NULL");
        $stmt->execute([...$ids, $compId]);
        if ((int)$stmt->fetchColumn() !== count(array_unique($ids))) {
            return 'One or more selected departments are invalid.';
        }
        return null;
    }

    public function save(array $data, int $compId, int $userId): array {
        $mode = (string)($data['distribution_mode'] ?? '');
        if (!in_array($mode, self::VALID_MODES, true)) {
            return ['status' => false, 'message' => 'Invalid distribution_mode.'];
        }

        $delayHours = isset($data['send_delay_hours']) && is_numeric($data['send_delay_hours']) ? (int)$data['send_delay_hours'] : 0;
        if ($delayHours < 0) {
            return ['status' => false, 'message' => 'send_delay_hours must not be negative.'];
        }

        $channelsInput = is_array($data['channels'] ?? null) ? $data['channels'] : [];
        $channelsInput = array_values(array_unique(array_map('strval', $channelsInput)));
        if (in_array($mode, ['auto', 'both'], true) && empty($channelsInput)) {
            return ['status' => false, 'message' => 'Select at least one delivery channel for auto-send.'];
        }
        $validChannels = $this->activeChannelCodeSet();
        foreach ($channelsInput as $code) {
            if (!isset($validChannels[$code])) {
                return ['status' => false, 'message' => "Invalid channel: {$code}"];
            }
        }

        $deptIds = [];
        if (!empty($data['scope_department_ids'])) {
            $deptIds = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$data['scope_department_ids'])))));
            $err = $this->validateDepartmentIds($deptIds, $compId);
            if ($err !== null) {
                return ['status' => false, 'message' => $err];
            }
        }

        $statuses = [];
        if (!empty($data['scope_employment_statuses'])) {
            $statuses = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)$data['scope_employment_statuses'])))));
            foreach ($statuses as $s) {
                if (!in_array($s, self::VALID_EMPLOYMENT_STATUSES, true)) {
                    return ['status' => false, 'message' => "Invalid employment status: {$s}"];
                }
            }
        }

        $isActive = !empty($data['is_active']) ? 1 : 0;
        $deptCsv = !empty($deptIds) ? implode(',', $deptIds) : null;
        $statusCsv = !empty($statuses) ? implode(',', $statuses) : null;

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $stmtExisting = $this->db->prepare("SELECT id FROM payslip_distribution_settings WHERE comp_id = :comp_id");
            $stmtExisting->execute([':comp_id' => $compId]);
            $existingId = $stmtExisting->fetchColumn();

            if ($existingId !== false) {
                $settingId = (int)$existingId;
                $stmt = $this->db->prepare("UPDATE payslip_distribution_settings SET
                        distribution_mode = :mode, send_delay_hours = :delay, scope_department_ids = :dept,
                        scope_employment_statuses = :statuses, is_active = :is_active,
                        updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id");
                $stmt->execute([
                    ':mode' => $mode, ':delay' => $delayHours, ':dept' => $deptCsv, ':statuses' => $statusCsv,
                    ':is_active' => $isActive, ':updated_by' => $userId, ':id' => $settingId,
                ]);
                $this->db->prepare("DELETE FROM payslip_distribution_channels WHERE setting_id = :id")->execute([':id' => $settingId]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO payslip_distribution_settings
                        (comp_id, distribution_mode, send_delay_hours, scope_department_ids, scope_employment_statuses, is_active, created_by)
                    VALUES (:comp_id, :mode, :delay, :dept, :statuses, :is_active, :created_by)");
                $stmt->execute([
                    ':comp_id' => $compId, ':mode' => $mode, ':delay' => $delayHours, ':dept' => $deptCsv,
                    ':statuses' => $statusCsv, ':is_active' => $isActive, ':created_by' => $userId,
                ]);
                $settingId = (int)$this->db->lastInsertId();
            }

            $insC = $this->db->prepare("INSERT INTO payslip_distribution_channels (setting_id, channel_code, sort_order) VALUES (:id, :code, :order)");
            foreach ($channelsInput as $index => $code) {
                $insC->execute([':id' => $settingId, ':code' => $code, ':order' => $index + 1]);
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Saved successfully.', 'id' => $settingId];
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }
}
