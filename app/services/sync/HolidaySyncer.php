<?php
declare(strict_types=1);
require_once __DIR__ . '/AbstractMasterDataSyncer.php';

/**
 * Synced/imported holidays always apply company-wide (assignment_mode='exclude' with no
 * holiday_assignments rows, same meaning as an admin creating a holiday and leaving the scope
 * picker empty) -- neither the designed sync fetch shape nor the import template has a
 * scope/assignment concept to map. If per-scope holiday sync/import is ever needed, that's a
 * deliberate follow-up, not an oversight.
 */
class HolidaySyncer extends AbstractMasterDataSyncer {
    public function entityType(): string {
        return 'holiday';
    }

    protected function tableName(): string {
        return 'holidays';
    }

    protected function fetch(int $origamiCompanyId, OrigamiSyncClientInterface $client): array {
        return $client->fetchHolidays($origamiCompanyId);
    }

    /** No single natural code column exists for holidays -- (holiday_date, name_en) is used as the composite natural key for import. */
    protected function findByNaturalKey(int $compId, array $item): ?int {
        $holidayDate = trim((string)($item['holiday_date'] ?? ''));
        $nameEn = trim((string)($item['name_en'] ?? ''));
        if ($holidayDate === '' || $nameEn === '') {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM holidays WHERE holiday_date = :date AND name_en = :name_en AND comp_id = :comp AND deleted_at IS NULL");
        $stmt->execute([':date' => $holidayDate, ':name_en' => $nameEn, ':comp' => $compId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int)$id;
    }

    public function templateColumns(): array {
        return [
            'ref_id' => 'Origami Ref ID (optional)', 'name_th' => 'Name (Thai)', 'name_en' => 'Name (English)',
            'holiday_date' => 'Date (YYYY-MM-DD)', 'is_recurring' => 'Recurring Every Year (1/0)',
        ];
    }

    private function companyCountryCode(int $compId): string {
        $stmt = $this->db->prepare("SELECT registered_country FROM companies WHERE id = :id");
        $stmt->execute([':id' => $compId]);
        return (string)$stmt->fetchColumn();
    }

    protected function upsertItem(int $compId, array $item, int $batchId, ?int $triggeredBy, ?int $existingId, string $dataSource): void {
        $nameTh = trim((string)($item['name_th'] ?? ''));
        $nameEn = trim((string)($item['name_en'] ?? ''));
        $holidayDate = trim((string)($item['holiday_date'] ?? ''));
        if ($nameTh === '' || $nameEn === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $holidayDate)) {
            throw new InvalidArgumentException('Missing name_th/name_en or invalid holiday_date.');
        }
        $countryCode = $this->companyCountryCode($compId);
        if ($countryCode === '') {
            throw new InvalidArgumentException('Company country is not configured.');
        }
        $isRecurring = !empty($item['is_recurring']) ? 1 : 0;
        $refId = isset($item['ref_id']) && is_numeric($item['ref_id']) ? (int)$item['ref_id'] : null;
        if ($existingId !== null) {
            $stmt = $this->db->prepare("UPDATE holidays SET
                    country_code = :country, name_th = :name_th, name_en = :name_en, holiday_date = :holiday_date, is_recurring = :is_recurring,
                    status = 'active', sync_batch_id = :batch_id, updated_by = :user, updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([
                ':country' => $countryCode, ':name_th' => $nameTh, ':name_en' => $nameEn, ':holiday_date' => $holidayDate,
                ':is_recurring' => $isRecurring, ':batch_id' => $batchId, ':user' => $triggeredBy, ':id' => $existingId,
            ]);
        } else {
            $stmt = $this->db->prepare("INSERT INTO holidays
                    (comp_id, country_code, name_th, name_en, holiday_date, is_recurring, assignment_mode, status, origami_ref_id, data_source, sync_batch_id, created_by)
                VALUES (:comp_id, :country, :name_th, :name_en, :holiday_date, :is_recurring, 'exclude', 'active', :ref_id, :data_source, :batch_id, :user)");
            $stmt->execute([
                ':comp_id' => $compId, ':country' => $countryCode, ':name_th' => $nameTh, ':name_en' => $nameEn, ':holiday_date' => $holidayDate,
                ':is_recurring' => $isRecurring, ':ref_id' => $refId, ':data_source' => $dataSource, ':batch_id' => $batchId, ':user' => $triggeredBy,
            ]);
        }
    }
}
