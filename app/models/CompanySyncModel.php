<?php
declare(strict_types=1);
require_once __DIR__ . '/../services/sync/OrigamiSyncClientInterface.php';
require_once __DIR__ . '/../services/sync/OrigamiSyncClient.php';
require_once __DIR__ . '/SyncBatchModel.php';

/**
 * "Sync from Origami" for the company profile itself (Company Profile page, 2026-09-02) --
 * confirmed live against Origami's own real `GET /api/hr/company` endpoint (see
 * OrigamiSyncClient::fetchCompany()'s own docblock; verified end-to-end against the real local
 * Origami instance, not just a mock, before shipping). Same "interactive, single-entity apply"
 * shape as EmployeeSyncModel's own picker (requireConnected() gate, origamiCompanyRefId()
 * resolution, one sync_batches audit row per attempt) but simpler: there is exactly ONE company
 * row per Payroll company, always already existing by the time Origami linkage is even possible
 * (companies.ref_id is set AFTER the company itself was created in Payroll) -- so this is
 * UPDATE-ONLY, never an insert-or-update decision the way every other syncer in this app is.
 *
 * ONE-DIRECTIONAL, ALWAYS OVERWRITES (confirmed via AskUserQuestion, 2026-09-02: "Sync ซ้ำได้เสมอ 1
 * ทิศทาง (Origami เป็นเจ้าของข้อมูลเสมอ)" -- Origami is always the data owner) -- unlike
 * EmployeeSyncer's HR-owned-vs-payroll-owned split, every field this class touches is treated as
 * fully Origami-owned: a re-sync always overwrites whatever a payroll admin may have typed into
 * Company Profile by hand in between. The ONE exception is a NOT-NULL-constrained local column
 * that Origami sends back genuinely blank for -- writing an empty string into `local_name`/
 * `company_legal_name`/`global_tax_id`/`address_line_1` would violate the schema and is also just
 * bad data, so those specific fields fall back to leaving the existing local value untouched
 * rather than blanking them (same "don't guess/don't destroy with nothing" posture as every other
 * conditional-field sync in this app), not a partial retreat from the one-directional policy.
 *
 * FIELD MAPPING, confirmed against real response data (not guessed) by calling the real endpoint
 * directly during development: name_en -> company_legal_name, name_th -> local_name, tax_id ->
 * global_tax_id, address_th (falls back to address_en if blank) -> address_line_1, logo_url ->
 * downloaded and stored as logo_path (same download-and-store pattern as
 * EmployeeSyncer::downloadPhoto()/writeSyncedFile(), just company-scoped storage instead of
 * per-employee). `telephone`/`fax`/`branch_name`/`address_en` (when address_th IS present) are
 * received from Origami but have NO matching column anywhere in `companies` -- silently ignored,
 * NOT stored anywhere, same "no schema home, don't invent one speculatively" restraint already
 * documented for visa/foreign_worker_info in EmployeeSyncer's own 2026-09-02 docblock. `address_line_2`,
 * `master_address_id`, and every Payroll-owned config field (fiscal_year_start_month,
 * prorate_divisor_days, registered_country, authorized_signatory_name, signature_path,
 * setup_status) are NEVER touched by this class at all -- Origami has no equivalent for any of them.
 */
class CompanySyncModel {
    private PDO $db;
    private OrigamiSyncClientInterface $client;

    public function __construct(?PDO $pdo = null, ?OrigamiSyncClientInterface $client = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->client = $client ?? new OrigamiSyncClient();
    }

    private function requireConnected(): ?array {
        if (!OrigamiSyncClient::isConfigured()) {
            return ['status' => false, 'not_connected' => true,
                'message' => 'Not connected to Origami yet. The connection has not been configured -- please contact your system administrator.'];
        }
        return null;
    }

    private function origamiCompanyRefId(int $compId): ?int {
        $stmt = $this->db->prepare("SELECT ref_id FROM companies WHERE id = :id");
        $stmt->execute([':id' => $compId]);
        $refId = $stmt->fetchColumn();
        return ($refId === false || $refId === null) ? null : (int)$refId;
    }

    /**
     * Same jpg/png/svg MIME-sniffed allowlist + 2MB cap as EmployeeSyncer::downloadPhoto() -- see
     * that method's own docblock for the full reasoning (never trusts the URL's own extension,
     * returns null rather than throwing on any failure since a broken logo URL must never block
     * the rest of the sync).
     */
    private function downloadLogo(?string $url): ?array {
        $url = trim((string)$url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_RANGE => '0-2097151',
        ]);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $httpCode < 200 || $httpCode >= 300 || strlen($body) === 0 || strlen($body) > 2 * 1024 * 1024) {
            return null;
        }
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/svg+xml' => 'svg'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->buffer($body);
        if (!isset($allowedMimes[$detectedMime])) {
            return null;
        }
        return ['bytes' => $body, 'ext' => $allowedMimes[$detectedMime]];
    }

    /** Same sha256-compare-before-write dedup as EmployeeSyncer::writeSyncedFile() -- avoids piling
     *  up an identical orphaned logo file on every re-sync. */
    private function writeLogoFile(int $compId, ?string $currentRelPath, ?array $decoded): ?string {
        if ($decoded === null) {
            return $currentRelPath;
        }
        $currentAbsPath = $currentRelPath ? (__DIR__ . '/../../' . $currentRelPath) : null;
        if ($currentAbsPath && is_file($currentAbsPath) && hash('sha256', (string)file_get_contents($currentAbsPath)) === hash('sha256', $decoded['bytes'])) {
            return $currentRelPath;
        }
        $dir = __DIR__ . '/../../public/uploads/company_logos/' . $compId . '/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $currentRelPath;
        }
        $fileName = bin2hex(random_bytes(16)) . '.' . $decoded['ext'];
        if (file_put_contents($dir . $fileName, $decoded['bytes']) === false) {
            return $currentRelPath;
        }
        return 'public/uploads/company_logos/' . $compId . '/' . $fileName;
    }

    public function sync(int $compId, int $userId): array {
        if ($err = $this->requireConnected()) {
            return $err;
        }
        $origamiCompanyId = $this->origamiCompanyRefId($compId);
        if ($origamiCompanyId === null) {
            return ['status' => false, 'message' => 'This company is not linked to an Origami HR company yet. Set the Origami reference ID first.'];
        }

        $batchModel = new SyncBatchModel($this->db);
        $batchId = $batchModel->start($compId, 'company', 'sync', 'manual', $userId);
        try {
            $data = $this->client->fetchCompany($origamiCompanyId);
        } catch (Throwable $e) {
            $batchModel->fail($batchId, $e->getMessage());
            return ['status' => false, 'message' => $e->getMessage()];
        }
        if ($data === null) {
            $batchModel->fail($batchId, 'No company found in Origami for this reference id.');
            return ['status' => false, 'message' => 'No company found in Origami for this reference id.'];
        }

        $current = $this->db->prepare("SELECT company_legal_name, local_name, global_tax_id, address_line_1, logo_path FROM companies WHERE id = :id");
        $current->execute([':id' => $compId]);
        $currentRow = $current->fetch(PDO::FETCH_ASSOC) ?: [];

        $nameEn = trim((string)($data['name_en'] ?? ''));
        $nameTh = trim((string)($data['name_th'] ?? ''));
        $taxId = trim((string)($data['tax_id'] ?? ''));
        $addressTh = trim((string)($data['address_th'] ?? ''));
        $addressEn = trim((string)($data['address_en'] ?? ''));
        $address = $addressTh !== '' ? $addressTh : $addressEn;

        $set = [];
        $params = [':id' => $compId];
        // NOT NULL columns -- only overwritten when Origami actually sent something, per this
        // class's own docblock on why a genuinely blank value never blanks these.
        if ($nameEn !== '') { $set[] = 'company_legal_name = :company_legal_name'; $params[':company_legal_name'] = $nameEn; }
        if ($nameTh !== '') { $set[] = 'local_name = :local_name'; $params[':local_name'] = $nameTh; }
        if ($taxId !== '') { $set[] = 'global_tax_id = :global_tax_id'; $params[':global_tax_id'] = $taxId; }
        if ($address !== '') { $set[] = 'address_line_1 = :address_line_1'; $params[':address_line_1'] = $address; }

        $logoDecoded = $this->downloadLogo(is_string($data['logo_url'] ?? null) ? $data['logo_url'] : null);
        $newLogoPath = $this->writeLogoFile($compId, $currentRow['logo_path'] ?? null, $logoDecoded);
        if ($newLogoPath !== ($currentRow['logo_path'] ?? null)) {
            $set[] = 'logo_path = :logo_path';
            $params[':logo_path'] = $newLogoPath;
        }

        if (!empty($set)) {
            $this->db->prepare('UPDATE companies SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
        }

        $batchModel->complete($batchId, 1, 1, 0, []);
        return [
            'status' => true, 'batch_id' => $batchId,
            'name_en' => $nameEn !== '' ? $nameEn : ($currentRow['company_legal_name'] ?? null),
            'name_th' => $nameTh !== '' ? $nameTh : ($currentRow['local_name'] ?? null),
        ];
    }
}
