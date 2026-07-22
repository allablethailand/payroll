<?php
declare(strict_types=1);

/**
 * Payslip Format template editor backend. Templates are per-company; country_code is derived
 * from companies.registered_country at save time, same as SetupRulesModel's holidays.
 *
 * Fields are structural blocks, not individual earning/deduction item codes -- see
 * master_payslip_field_types' table comment for why (payroll_earning_deduction_types is an
 * open, per-company-growable set, not enumerable at template-design time).
 *
 * is_default is properly enforced as single-active-per-company here (unlike bank_accounts.is_default
 * elsewhere in this codebase, which isn't actually enforced -- not copying that gap).
 */
class PayslipTemplateModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function fieldTypeOptions(): array {
        $stmt = $this->db->query("SELECT code AS id, name_th AS text_th, name_en AS text_en, field_group
            FROM master_payslip_field_types WHERE is_active = 1 ORDER BY sort_order ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Same field validation as save(), but returns the resolved field rows (with labels/group
     * from the master table) instead of writing anything -- used by the Preview endpoint to
     * render the modal's current unsaved draft state.
     */
    public function resolveFieldsForPreview(array $fieldsInput): array {
        $allTypes = [];
        $stmt = $this->db->query("SELECT code, name_th, name_en, field_group FROM master_payslip_field_types WHERE is_active = 1");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $allTypes[$row['code']] = $row;
        }
        $seen = [];
        $result = [];
        foreach ($fieldsInput as $f) {
            $fieldKey = (string)($f['field_key'] ?? '');
            if (!isset($allTypes[$fieldKey])) {
                throw new InvalidArgumentException("Invalid field selection: {$fieldKey}");
            }
            if (isset($seen[$fieldKey])) {
                continue;
            }
            $seen[$fieldKey] = true;
            $result[] = [
                'field_key' => $fieldKey,
                'custom_label_th' => !empty($f['custom_label_th']) ? trim((string)$f['custom_label_th']) : null,
                'custom_label_en' => !empty($f['custom_label_en']) ? trim((string)$f['custom_label_en']) : null,
                'default_label_th' => $allTypes[$fieldKey]['name_th'],
                'default_label_en' => $allTypes[$fieldKey]['name_en'],
                'field_group' => $allTypes[$fieldKey]['field_group'],
            ];
        }
        return $result;
    }

    public function list(int $compId): array {
        $stmt = $this->db->prepare("SELECT t.*,
                (SELECT COUNT(*) FROM payslip_template_fields f WHERE f.template_id = t.id) AS field_count
            FROM payslip_templates t
            WHERE t.comp_id = :comp_id AND t.deleted_at IS NULL
            ORDER BY t.is_default DESC, t.name_th ASC");
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $id, int $compId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM payslip_templates WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$template) {
            return null;
        }
        $template['fields'] = $this->getFields($id);
        return $template;
    }

    private function getFields(int $templateId): array {
        $stmt = $this->db->prepare("SELECT ptf.field_key, ptf.custom_label_th, ptf.custom_label_en,
                m.name_th AS default_label_th, m.name_en AS default_label_en, m.field_group
            FROM payslip_template_fields ptf
            JOIN master_payslip_field_types m ON m.code = ptf.field_key
            WHERE ptf.template_id = :id ORDER BY ptf.sort_order ASC");
        $stmt->execute([':id' => $templateId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Used by PaySlipReport to resolve which template (if any) to render with. */
    public function getDefaultForCompany(int $compId): ?array {
        $stmt = $this->db->prepare("SELECT id FROM payslip_templates
            WHERE comp_id = :comp_id AND is_default = 1 AND status = 'active' AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':comp_id' => $compId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return null;
        }
        return $this->get((int)$id, $compId);
    }

    private function isNameDuplicate(int $compId, string $nameTh, ?int $excludeId): bool {
        $sql = "SELECT COUNT(*) FROM payslip_templates WHERE comp_id = :comp_id AND name_th = :name_th AND deleted_at IS NULL";
        $params = [':comp_id' => $compId, ':name_th' => $nameTh];
        if ($excludeId !== null) {
            $sql .= " AND id != :exclude_id";
            $params[':exclude_id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    }

    public function save(array $data, int $compId, int $userId): array {
        $nameTh = trim((string)($data['name_th'] ?? ''));
        $nameEn = trim((string)($data['name_en'] ?? ''));
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        $logoPath = isset($data['logo_path']) && $data['logo_path'] !== '' ? (string)$data['logo_path'] : null;
        $headerTh = trim((string)($data['header_text_th'] ?? ''));
        $headerEn = trim((string)($data['header_text_en'] ?? ''));
        $footerTh = trim((string)($data['footer_text_th'] ?? ''));
        $footerEn = trim((string)($data['footer_text_en'] ?? ''));
        $languageMode = in_array($data['language_mode'] ?? '', ['th', 'en', 'both'], true) ? $data['language_mode'] : 'both';
        $status = in_array($data['status'] ?? '', ['active', 'inactive'], true) ? $data['status'] : 'active';
        $fieldsInput = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;

        if ($nameTh === '' || $nameEn === '') {
            return ['status' => false, 'message' => 'Missing required field: name'];
        }
        if ($this->isNameDuplicate($compId, $nameTh, $id)) {
            return ['status' => false, 'message' => 'A template with this name already exists.'];
        }

        $validFieldCodes = array_column($this->fieldTypeOptions(), 'id');
        $validFieldCodeSet = array_flip($validFieldCodes);
        $seen = [];
        $cleanFields = [];
        foreach ($fieldsInput as $f) {
            $fieldKey = (string)($f['field_key'] ?? '');
            if (!isset($validFieldCodeSet[$fieldKey])) {
                return ['status' => false, 'message' => "Invalid field selection: {$fieldKey}"];
            }
            if (isset($seen[$fieldKey])) {
                continue;
            }
            $seen[$fieldKey] = true;
            $cleanFields[] = [
                'field_key' => $fieldKey,
                'custom_label_th' => !empty($f['custom_label_th']) ? trim((string)$f['custom_label_th']) : null,
                'custom_label_en' => !empty($f['custom_label_en']) ? trim((string)$f['custom_label_en']) : null,
            ];
        }
        if (empty($cleanFields)) {
            return ['status' => false, 'message' => 'Select at least one field to include on the payslip.'];
        }

        $stmtC = $this->db->prepare("SELECT registered_country FROM companies WHERE id = :id");
        $stmtC->execute([':id' => $compId]);
        $countryCode = (string)$stmtC->fetchColumn();
        if ($countryCode === '') {
            return ['status' => false, 'message' => 'Company country is not configured.'];
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }
        try {
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM payslip_templates WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    if ($ownTransaction) {
                        $this->db->rollBack();
                    }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $stmt = $this->db->prepare("UPDATE payslip_templates SET country_code = :country_code, name_th = :name_th, name_en = :name_en,
                    is_default = :is_default, logo_path = :logo_path, header_text_th = :header_th, header_text_en = :header_en,
                    footer_text_th = :footer_th, footer_text_en = :footer_en, language_mode = :language_mode, status = :status,
                    updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $stmt->execute([
                    ':country_code' => $countryCode, ':name_th' => $nameTh, ':name_en' => $nameEn, ':is_default' => $isDefault,
                    ':logo_path' => $logoPath, ':header_th' => $headerTh !== '' ? $headerTh : null, ':header_en' => $headerEn !== '' ? $headerEn : null,
                    ':footer_th' => $footerTh !== '' ? $footerTh : null, ':footer_en' => $footerEn !== '' ? $footerEn : null,
                    ':language_mode' => $languageMode, ':status' => $status, ':updated_by' => $userId, ':id' => $id,
                ]);
                $this->db->prepare("DELETE FROM payslip_template_fields WHERE template_id = :id")->execute([':id' => $id]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO payslip_templates (comp_id, country_code, name_th, name_en, is_default,
                    logo_path, header_text_th, header_text_en, footer_text_th, footer_text_en, language_mode, status, created_by)
                    VALUES (:comp_id, :country_code, :name_th, :name_en, :is_default, :logo_path, :header_th, :header_en,
                    :footer_th, :footer_en, :language_mode, :status, :created_by)");
                $stmt->execute([
                    ':comp_id' => $compId, ':country_code' => $countryCode, ':name_th' => $nameTh, ':name_en' => $nameEn,
                    ':is_default' => $isDefault, ':logo_path' => $logoPath, ':header_th' => $headerTh !== '' ? $headerTh : null,
                    ':header_en' => $headerEn !== '' ? $headerEn : null, ':footer_th' => $footerTh !== '' ? $footerTh : null,
                    ':footer_en' => $footerEn !== '' ? $footerEn : null, ':language_mode' => $languageMode, ':status' => $status,
                    ':created_by' => $userId,
                ]);
                $id = (int)$this->db->lastInsertId();
            }

            if ($isDefault) {
                $this->db->prepare("UPDATE payslip_templates SET is_default = 0 WHERE comp_id = :comp_id AND id != :id")
                    ->execute([':comp_id' => $compId, ':id' => $id]);
            }

            $insF = $this->db->prepare("INSERT INTO payslip_template_fields (template_id, field_key, sort_order, custom_label_th, custom_label_en)
                VALUES (:template_id, :field_key, :sort_order, :custom_label_th, :custom_label_en)");
            foreach ($cleanFields as $index => $f) {
                $insF->execute([
                    ':template_id' => $id, ':field_key' => $f['field_key'], ':sort_order' => $index,
                    ':custom_label_th' => $f['custom_label_th'], ':custom_label_en' => $f['custom_label_en'],
                ]);
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Saved successfully.', 'id' => $id];
        } catch (PDOException $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function delete(int $id, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT id FROM payslip_templates WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        if (!$stmt->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $this->db->prepare("UPDATE payslip_templates SET status = 'deleted', is_default = 0, deleted_at = CURRENT_TIMESTAMP, deleted_by = :deleted_by WHERE id = :id")
            ->execute([':deleted_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    public function toggleStatus(int $id, int $compId, int $userId): array {
        $stmt = $this->db->prepare("SELECT status FROM payslip_templates WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $newStatus = $current === 'active' ? 'inactive' : 'active';
        $params = [':status' => $newStatus, ':updated_by' => $userId, ':id' => $id];
        $sql = "UPDATE payslip_templates SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP";
        if ($newStatus === 'inactive') {
            // An inactive template can't remain "the" default (PaySlipReport only looks at active defaults).
            $sql .= ", is_default = 0";
        }
        $sql .= " WHERE id = :id";
        $this->db->prepare($sql)->execute($params);
        return ['status' => true, 'message' => 'Updated successfully.', 'new_status' => $newStatus];
    }
}
