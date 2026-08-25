<?php
declare(strict_types=1);

/**
 * Payslip Template designer backend -- rebuilt as a free-form canvas designer (2026-08-25, explicit
 * request: "ปรับให้การตั้งค่า Slip เงินเดือน Template เป็นเหมือนกับใบรับรอง", confirmed via
 * AskUserQuestion: a FULL canvas designer like Employment Certificate Template's, not just matching
 * page chrome). `payslip_template_fields` (the old ordered field-list) is gone entirely, replaced by
 * `payslip_template_elements` -- the exact same percentage-positioned text/image/shape/table element
 * shape as `employment_certificate_template_elements` (see EmploymentCertificateTemplateModel, which
 * this class mirrors closely). Both tables were confirmed completely EMPTY in the real dev DB before
 * this migration, so it was a clean cutover with no data-migration risk.
 *
 * Deliberately KEPT from the old design, unlike Employment Certificate Template (which has neither
 * concept): `is_default` (genuinely controls which template PaySlipReport::generate() picks),
 * `language_mode` ('th'/'en'/'both' -- ONE shared canvas across languages, NOT forked into separate
 * rows via a pair_key like Employment Certificate, because payslip field values are almost entirely
 * data-driven tokens that already resolve per-language at generation time -- see
 * PayslipTemplateRenderer::buildTokens() -- not hand-authored free text needing two independent
 * layouts), `header_text_th/en`/`footer_text_th/en`, `status` (active/inactive, distinct from the
 * soft-delete `deleted_at` -- a template can be disabled without deleting it), and `country_code`
 * (derived from companies.registered_country at save time, unchanged from before).
 *
 * There is therefore NO pair_key/TH-EN-tabs/listPaired()/generateOtherLanguage()/duplicatePair()
 * machinery here at all -- list()/get() return one row per template, and the standalone editor page
 * is addressed by a plain template `id`, not a pair key.
 */
class PayslipTemplateModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    private const ELEMENT_TYPES = ['text', 'image', 'shape', 'table'];
    private const SHAPE_TYPES = ['rectangle', 'ellipse', 'line'];
    private const TEXT_ALIGNS = ['left', 'center', 'right'];
    private const FONT_WEIGHTS = ['normal', 'bold'];
    private const FONT_STYLES = ['normal', 'italic'];
    private const TEXT_DECORATIONS = ['none', 'underline'];
    private const FONT_FAMILIES = ['th_sarabun_new', 'dejavu_sans', 'dejavu_sans_mono', 'dejavu_serif', 'helvetica', 'times_new_roman', 'courier'];
    private const PAGE_SIZES = ['A4', 'Letter', 'Legal'];
    private const ORIENTATIONS = ['portrait', 'landscape'];
    private const LANGUAGE_MODES = ['th', 'en', 'both'];
    private const MAX_PAGE_NUMBER = 20;
    public const PRESETS = ['blank', 'classic', 'modern', 'minimal'];

    public function fieldTypeOptions(): array {
        $stmt = $this->db->query("SELECT code, name_th, name_en, field_group, element_type
            FROM `master_payslip_field_types` WHERE is_active = 1 ORDER BY sort_order ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function isValidLogoPath(?string $path, int $compId): bool {
        if ($path === null || $path === '') {
            return true;
        }
        $pattern = '#^public/uploads/payslip_logos/' . $compId . '/[a-f0-9]{32}\.(jpg|png|svg)$#';
        return (bool)preg_match($pattern, $path);
    }

    public static function isValidImageAssetPath(?string $path, int $compId): bool {
        if ($path === null || $path === '') {
            return true;
        }
        $pattern = '#^public/uploads/payslip_images/' . $compId . '/[a-f0-9]{32}\.(jpg|png|svg)$#';
        return (bool)preg_match($pattern, $path);
    }

    /* ==================== Templates ==================== */

    private function getElements(int $templateId): array {
        $stmt = $this->db->prepare("SELECT id, element_type, field_key, image_asset_id, content,
                pos_x_pct, pos_y_pct, width_pct, height_pct, font_size, font_family, font_color,
                text_align, font_weight, font_style, text_decoration, sort_order, group_key, page_number
            FROM `payslip_template_elements` WHERE template_id = :id ORDER BY sort_order ASC, id ASC");
        $stmt->execute([':id' => $templateId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function list(int $compId): array {
        $stmt = $this->db->prepare("SELECT t.*,
                (SELECT COUNT(*) FROM `payslip_template_elements` e WHERE e.template_id = t.id) AS element_count
            FROM `payslip_templates` t
            WHERE t.comp_id = :comp_id AND t.deleted_at IS NULL
            ORDER BY t.is_default DESC, t.updated_at DESC, t.id DESC");
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $compId, int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `payslip_templates` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$template) {
            return null;
        }
        $template['elements'] = $this->getElements($id);
        return $template;
    }

    /** Used by PaySlipReport to resolve which template (if any) to render with. */
    public function getDefaultForCompany(int $compId): ?array {
        $stmt = $this->db->prepare("SELECT id FROM `payslip_templates`
            WHERE comp_id = :comp_id AND is_default = 1 AND status = 'active' AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':comp_id' => $compId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? $this->get($compId, (int)$id) : null;
    }

    /** @return array{elements?: array, error?: string} */
    private function validateElements(array $rawElements): array {
        $fieldTypes = [];
        foreach ($this->fieldTypeOptions() as $ft) {
            $fieldTypes[$ft['code']] = $ft;
        }
        $cleaned = [];
        foreach ($rawElements as $i => $raw) {
            $n = $i + 1;
            $elementType = (string)($raw['element_type'] ?? 'text');
            if (!in_array($elementType, self::ELEMENT_TYPES, true)) {
                return ['error' => "Element {$n}: invalid element_type."];
            }
            $fieldKey = !empty($raw['field_key']) ? (string)$raw['field_key'] : null;
            $imageAssetId = !empty($raw['image_asset_id']) && is_numeric($raw['image_asset_id']) ? (int)$raw['image_asset_id'] : null;
            if ($elementType === 'image') {
                if ($fieldKey !== null) {
                    if (!isset($fieldTypes[$fieldKey]) || $fieldTypes[$fieldKey]['element_type'] !== 'image') {
                        return ['error' => "Element {$n}: invalid image field_key."];
                    }
                    $imageAssetId = null;
                } elseif ($imageAssetId === null) {
                    return ['error' => "Element {$n}: an image element needs either field_key or image_asset_id."];
                }
                $content = null;
            } elseif ($elementType === 'shape') {
                if (!in_array($fieldKey, self::SHAPE_TYPES, true)) {
                    return ['error' => "Element {$n}: invalid shape type."];
                }
                $imageAssetId = null;
                $content = null;
            } elseif ($elementType === 'table') {
                $tableData = json_decode((string)($raw['content'] ?? ''), true);
                if (!is_array($tableData)) {
                    return ['error' => "Element {$n}: invalid table data."];
                }
                $rows = (int)($tableData['rows'] ?? 0);
                $cols = (int)($tableData['cols'] ?? 0);
                if ($rows < 1 || $rows > 20 || $cols < 1 || $cols > 10) {
                    return ['error' => "Element {$n}: table rows/cols out of range (1-20 rows, 1-10 cols)."];
                }
                $borderColor = (string)($tableData['border_color'] ?? '#000000');
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $borderColor)) {
                    return ['error' => "Element {$n}: table border_color must be a #rrggbb hex value."];
                }
                $borderWidth = (int)($tableData['border_width'] ?? 1);
                $borderWidth = max(0, min(10, $borderWidth));
                $rawCells = is_array($tableData['cells'] ?? null) ? $tableData['cells'] : [];
                $cells = [];
                for ($r = 0; $r < $rows; $r++) {
                    $rowCells = [];
                    for ($c = 0; $c < $cols; $c++) {
                        $rowCells[] = trim((string)($rawCells[$r][$c] ?? ''));
                    }
                    $cells[] = $rowCells;
                }
                $content = json_encode(['rows' => $rows, 'cols' => $cols, 'border_color' => $borderColor, 'border_width' => $borderWidth, 'cells' => $cells]);
                $fieldKey = null;
                $imageAssetId = null;
            } else {
                $content = trim((string)($raw['content'] ?? ''));
                if ($content === '') {
                    return ['error' => "Element {$n}: text content is required."];
                }
                $fieldKey = null;
                $imageAssetId = null;
            }
            $posX = is_numeric($raw['pos_x_pct'] ?? null) ? (float)$raw['pos_x_pct'] : null;
            $posY = is_numeric($raw['pos_y_pct'] ?? null) ? (float)$raw['pos_y_pct'] : null;
            $width = is_numeric($raw['width_pct'] ?? null) ? (float)$raw['width_pct'] : null;
            $height = is_numeric($raw['height_pct'] ?? null) ? (float)$raw['height_pct'] : null;
            if ($posX === null || $posY === null || $width === null || $height === null
                || $posX < 0 || $posX > 100 || $posY < 0 || $posY > 100 || $width <= 0 || $width > 100 || $height <= 0 || $height > 100) {
                return ['error' => "Element {$n}: position/size must be within the page (0-100%)."];
            }
            $fontSize = is_numeric($raw['font_size'] ?? null) ? (int)$raw['font_size'] : 14;
            if ($fontSize < 6 || $fontSize > 96) {
                return ['error' => "Element {$n}: font_size out of range."];
            }
            $textAlign = (string)($raw['text_align'] ?? 'left');
            if (!in_array($textAlign, self::TEXT_ALIGNS, true)) {
                return ['error' => "Element {$n}: invalid text_align."];
            }
            $fontWeight = (string)($raw['font_weight'] ?? 'normal');
            if (!in_array($fontWeight, self::FONT_WEIGHTS, true)) {
                return ['error' => "Element {$n}: invalid font_weight."];
            }
            $fontStyle = (string)($raw['font_style'] ?? 'normal');
            if (!in_array($fontStyle, self::FONT_STYLES, true)) {
                return ['error' => "Element {$n}: invalid font_style."];
            }
            $textDecoration = (string)($raw['text_decoration'] ?? 'none');
            if (!in_array($textDecoration, self::TEXT_DECORATIONS, true)) {
                return ['error' => "Element {$n}: invalid text_decoration."];
            }
            $fontFamily = (string)($raw['font_family'] ?? 'th_sarabun_new');
            if (!in_array($fontFamily, self::FONT_FAMILIES, true)) {
                return ['error' => "Element {$n}: invalid font_family."];
            }
            $fontColor = (string)($raw['font_color'] ?? '#000000');
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $fontColor)) {
                return ['error' => "Element {$n}: font_color must be a #rrggbb hex value."];
            }
            $groupKey = !empty($raw['group_key']) ? substr((string)$raw['group_key'], 0, 64) : null;
            $pageNumber = is_numeric($raw['page_number'] ?? null) ? (int)$raw['page_number'] : 1;
            $pageNumber = max(1, min(self::MAX_PAGE_NUMBER, $pageNumber));
            $cleaned[] = [
                'element_type' => $elementType, 'field_key' => $fieldKey, 'image_asset_id' => $imageAssetId, 'content' => $content,
                'pos_x_pct' => $posX, 'pos_y_pct' => $posY, 'width_pct' => $width, 'height_pct' => $height,
                'font_size' => $fontSize, 'font_family' => $fontFamily, 'font_color' => $fontColor,
                'text_align' => $textAlign, 'font_weight' => $fontWeight, 'font_style' => $fontStyle, 'text_decoration' => $textDecoration,
                'sort_order' => $n, 'group_key' => $groupKey, 'page_number' => $pageNumber,
            ];
        }
        return ['elements' => $cleaned];
    }

    /**
     * Creates a NEW template (id omitted) or replaces an EXISTING one's whole element set in place
     * (id given). @param array $data {id?:int, template_name:string, language_mode?:string,
     *   header_text_th?/en?/footer_text_th?/en?:string, is_default?:bool, status?:string,
     *   page_size?:string, orientation?:string, margin_mm?:float, logo_path?:?string, elements:array}
     */
    public function save(int $compId, array $data, int $userId): array {
        $templateName = trim((string)($data['template_name'] ?? ''));
        if ($templateName === '') {
            return ['status' => false, 'message' => 'Template name is required.'];
        }
        $languageMode = in_array($data['language_mode'] ?? '', self::LANGUAGE_MODES, true) ? $data['language_mode'] : 'both';
        $pageSize = (string)($data['page_size'] ?? 'A4');
        if (!in_array($pageSize, self::PAGE_SIZES, true)) {
            return ['status' => false, 'message' => 'Invalid page_size.'];
        }
        $orientation = (string)($data['orientation'] ?? 'portrait');
        if (!in_array($orientation, self::ORIENTATIONS, true)) {
            return ['status' => false, 'message' => 'Invalid orientation.'];
        }
        $marginMm = array_key_exists('margin_mm', $data) ? (float)$data['margin_mm'] : 15.0;
        $marginMm = max(0.0, min(50.0, $marginMm));
        $status = in_array($data['status'] ?? '', ['active', 'inactive'], true) ? $data['status'] : 'active';
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        $headerTh = trim((string)($data['header_text_th'] ?? ''));
        $headerEn = trim((string)($data['header_text_en'] ?? ''));
        $footerTh = trim((string)($data['footer_text_th'] ?? ''));
        $footerEn = trim((string)($data['footer_text_en'] ?? ''));
        $logoPath = array_key_exists('logo_path', $data) ? (string)$data['logo_path'] : null;
        if ($logoPath !== null && $logoPath !== '' && !self::isValidLogoPath($logoPath, $compId)) {
            return ['status' => false, 'message' => 'Invalid logo path.'];
        }
        $result = $this->validateElements(is_array($data['elements'] ?? null) ? $data['elements'] : []);
        if (isset($result['error'])) {
            return ['status' => false, 'message' => $result['error']];
        }
        $elements = $result['elements'];
        if (!empty($elements)) {
            $imageAssetIds = array_values(array_filter(array_column($elements, 'image_asset_id')));
            if (!empty($imageAssetIds)) {
                $placeholders = implode(',', array_fill(0, count($imageAssetIds), '?'));
                $stmt = $this->db->prepare("SELECT id FROM `payslip_images` WHERE comp_id = ? AND id IN ({$placeholders})");
                $stmt->execute(array_merge([$compId], $imageAssetIds));
                $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                $missing = array_diff($imageAssetIds, $validIds);
                if (!empty($missing)) {
                    return ['status' => false, 'message' => 'One or more image elements reference an image that no longer exists.'];
                }
            }
        }

        $stmtC = $this->db->prepare("SELECT registered_country FROM `companies` WHERE id = :id");
        $stmtC->execute([':id' => $compId]);
        $countryCode = (string)$stmtC->fetchColumn();
        if ($countryCode === '') {
            return ['status' => false, 'message' => 'Company country is not configured.'];
        }

        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;
        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM `payslip_templates` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    if ($own) { $this->db->rollBack(); }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $logoSql = $logoPath !== null ? ", logo_path = :logo_path" : "";
                $stmt = $this->db->prepare("UPDATE `payslip_templates`
                    SET template_name = :template_name, is_default = :is_default, header_text_th = :header_th, header_text_en = :header_en,
                        footer_text_th = :footer_th, footer_text_en = :footer_en, language_mode = :language_mode, status = :status,
                        page_size = :page_size, orientation = :orientation, margin_mm = :margin_mm{$logoSql},
                        updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $params = [
                    ':template_name' => $templateName, ':is_default' => $isDefault,
                    ':header_th' => $headerTh !== '' ? $headerTh : null, ':header_en' => $headerEn !== '' ? $headerEn : null,
                    ':footer_th' => $footerTh !== '' ? $footerTh : null, ':footer_en' => $footerEn !== '' ? $footerEn : null,
                    ':language_mode' => $languageMode, ':status' => $status,
                    ':page_size' => $pageSize, ':orientation' => $orientation, ':margin_mm' => $marginMm,
                    ':updated_by' => $userId, ':id' => $id,
                ];
                if ($logoPath !== null) {
                    $params[':logo_path'] = $logoPath !== '' ? $logoPath : null;
                }
                $stmt->execute($params);
                $templateId = $id;
            } else {
                $stmt = $this->db->prepare("INSERT INTO `payslip_templates`
                    (comp_id, country_code, template_name, is_default, logo_path,
                     header_text_th, header_text_en, footer_text_th, footer_text_en, language_mode,
                     page_size, orientation, margin_mm, status, created_by)
                    VALUES (:comp_id, :country_code, :template_name, :is_default, :logo_path,
                     :header_th, :header_en, :footer_th, :footer_en, :language_mode,
                     :page_size, :orientation, :margin_mm, :status, :created_by)");
                $stmt->execute([
                    ':comp_id' => $compId, ':country_code' => $countryCode, ':template_name' => $templateName, ':is_default' => $isDefault,
                    ':logo_path' => ($logoPath !== null && $logoPath !== '') ? $logoPath : null,
                    ':header_th' => $headerTh !== '' ? $headerTh : null, ':header_en' => $headerEn !== '' ? $headerEn : null,
                    ':footer_th' => $footerTh !== '' ? $footerTh : null, ':footer_en' => $footerEn !== '' ? $footerEn : null,
                    ':language_mode' => $languageMode, ':page_size' => $pageSize, ':orientation' => $orientation, ':margin_mm' => $marginMm,
                    ':status' => $status, ':created_by' => $userId,
                ]);
                $templateId = (int)$this->db->lastInsertId();
                // The very first template ever saved for this company becomes the default
                // automatically (there would otherwise be no default at all until the admin sets
                // one) -- every later new template stays non-default until chosen.
                $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM `payslip_templates` WHERE comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCount->execute([':comp_id' => $compId]);
                if ((int)$stmtCount->fetchColumn() === 1) {
                    $this->db->prepare("UPDATE `payslip_templates` SET is_default = 1 WHERE id = :id")->execute([':id' => $templateId]);
                    $isDefault = 1;
                }
            }

            if ($isDefault) {
                $this->db->prepare("UPDATE `payslip_templates` SET is_default = 0 WHERE comp_id = :comp_id AND id != :id AND deleted_at IS NULL")
                    ->execute([':comp_id' => $compId, ':id' => $templateId]);
            }

            $this->db->prepare("DELETE FROM `payslip_template_elements` WHERE template_id = :id")->execute([':id' => $templateId]);
            $insEl = $this->db->prepare("INSERT INTO `payslip_template_elements`
                (template_id, element_type, field_key, image_asset_id, content, pos_x_pct, pos_y_pct, width_pct, height_pct,
                 font_size, font_family, font_color, text_align, font_weight, font_style, text_decoration, sort_order, group_key, page_number)
                VALUES (:template_id, :element_type, :field_key, :image_asset_id, :content, :pos_x_pct, :pos_y_pct, :width_pct, :height_pct,
                        :font_size, :font_family, :font_color, :text_align, :font_weight, :font_style, :text_decoration, :sort_order, :group_key, :page_number)");
            foreach ($elements as $el) {
                $insEl->execute([
                    ':template_id' => $templateId,
                    ':element_type' => $el['element_type'], ':field_key' => $el['field_key'], ':image_asset_id' => $el['image_asset_id'], ':content' => $el['content'],
                    ':pos_x_pct' => $el['pos_x_pct'], ':pos_y_pct' => $el['pos_y_pct'], ':width_pct' => $el['width_pct'], ':height_pct' => $el['height_pct'],
                    ':font_size' => $el['font_size'], ':font_family' => $el['font_family'], ':font_color' => $el['font_color'],
                    ':text_align' => $el['text_align'], ':font_weight' => $el['font_weight'], ':font_style' => $el['font_style'], ':text_decoration' => $el['text_decoration'],
                    ':sort_order' => $el['sort_order'], ':group_key' => $el['group_key'], ':page_number' => $el['page_number'] ?? 1,
                ]);
            }
            if ($own) {
                $this->db->commit();
            }
            return ['status' => true, 'message' => 'Saved successfully.', 'template_id' => $templateId];
        } catch (PDOException $e) {
            if ($own) {
                $this->db->rollBack();
            }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    public function duplicate(int $compId, int $id, int $userId): array {
        $source = $this->get($compId, $id);
        if (!$source) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        return $this->save($compId, [
            'template_name' => $source['template_name'] . ' (Copy)',
            'is_default' => false, 'status' => $source['status'],
            'header_text_th' => $source['header_text_th'], 'header_text_en' => $source['header_text_en'],
            'footer_text_th' => $source['footer_text_th'], 'footer_text_en' => $source['footer_text_en'],
            'language_mode' => $source['language_mode'],
            'page_size' => $source['page_size'], 'orientation' => $source['orientation'], 'margin_mm' => $source['margin_mm'],
            'logo_path' => $source['logo_path'],
            'elements' => array_map(fn($e) => [
                'element_type' => $e['element_type'], 'field_key' => $e['field_key'], 'image_asset_id' => $e['image_asset_id'], 'content' => $e['content'],
                'pos_x_pct' => $e['pos_x_pct'], 'pos_y_pct' => $e['pos_y_pct'], 'width_pct' => $e['width_pct'], 'height_pct' => $e['height_pct'],
                'font_size' => $e['font_size'], 'font_family' => $e['font_family'], 'font_color' => $e['font_color'],
                'text_align' => $e['text_align'], 'font_weight' => $e['font_weight'], 'font_style' => $e['font_style'], 'text_decoration' => $e['text_decoration'],
                'group_key' => $e['group_key'] ?? null, 'page_number' => $e['page_number'] ?? 1,
            ], $source['elements']),
        ], $userId);
    }

    public function delete(int $compId, int $id, int $userId): array {
        $stmtCheck = $this->db->prepare("SELECT id FROM `payslip_templates` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
        if (!$stmtCheck->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $stmt = $this->db->prepare("UPDATE `payslip_templates` SET status = 'deleted', is_default = 0, deleted_by = :deleted_by, deleted_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    public function toggleStatus(int $compId, int $id, int $userId): array {
        $stmt = $this->db->prepare("SELECT status FROM `payslip_templates` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $current = $stmt->fetchColumn();
        if ($current === false) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $newStatus = $current === 'active' ? 'inactive' : 'active';
        $sql = "UPDATE `payslip_templates` SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP";
        $params = [':status' => $newStatus, ':updated_by' => $userId, ':id' => $id];
        if ($newStatus === 'inactive') {
            // An inactive template can't remain "the" default (PaySlipReport only looks at active defaults).
            $sql .= ", is_default = 0";
        }
        $sql .= " WHERE id = :id";
        $this->db->prepare($sql)->execute($params);
        return ['status' => true, 'message' => 'Updated successfully.', 'new_status' => $newStatus];
    }

    /** Single-default-per-company enforcement -- explicit list-star action (kept from the old
     *  design; Employment Certificate Template has no equivalent since it has no company-wide
     *  "which template generates by default" concept at all). */
    public function setDefault(int $compId, int $id, int $userId): array {
        $template = $this->get($compId, $id);
        if (!$template) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            $this->db->prepare("UPDATE `payslip_templates` SET is_default = 0 WHERE comp_id = :comp_id AND deleted_at IS NULL")
                ->execute([':comp_id' => $compId]);
            $this->db->prepare("UPDATE `payslip_templates` SET is_default = 1, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':updated_by' => $userId, ':id' => $id]);
            if ($own) { $this->db->commit(); }
            return ['status' => true, 'message' => 'Default template updated.'];
        } catch (PDOException $e) {
            if ($own) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /* ==================== Starter presets -- PHP-defined, not DB/company data (mirrors
       EmploymentCertificateTemplateModel's own presetElements()). "blank" is the implicit 4th
       option. Positions place the 3 block fields (earning/deduction/statutory lines) as generously
       tall boxes since their rendered row-count is unknown at design time. ==================== */

    public function presetOptions(): array {
        return [
            ['code' => 'blank', 'name_th' => 'เริ่มจากหน้าว่าง', 'name_en' => 'Blank canvas'],
            ['code' => 'classic', 'name_th' => 'คลาสสิก (ตารางเต็มหน้า)', 'name_en' => 'Classic (full-width tables)'],
            ['code' => 'modern', 'name_th' => 'โมเดิร์น (โลโก้มุมซ้าย)', 'name_en' => 'Modern (logo top-left)'],
            ['code' => 'minimal', 'name_th' => 'มินิมอล (กะทัดรัด)', 'name_en' => 'Minimal (compact)'],
        ];
    }

    private function presetElements(string $preset): array {
        $base = [
            'font_family' => 'th_sarabun_new', 'font_color' => '#000000',
            'font_weight' => 'normal', 'font_style' => 'normal', 'text_decoration' => 'none',
        ];
        $tok = fn(string $code) => '{{' . $code . '}}';
        switch ($preset) {
            case 'classic':
                return [
                    $base + ['element_type' => 'text', 'content' => $tok('company_name'), 'pos_x_pct' => 8, 'pos_y_pct' => 4, 'width_pct' => 60, 'height_pct' => 6, 'font_size' => 18, 'text_align' => 'left', 'font_weight' => 'bold'],
                    $base + ['element_type' => 'text', 'content' => 'สลิปเงินเดือน / Pay Slip', 'pos_x_pct' => 8, 'pos_y_pct' => 10, 'width_pct' => 60, 'height_pct' => 5, 'font_size' => 13, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('employee_no'), 'pos_x_pct' => 8, 'pos_y_pct' => 18, 'width_pct' => 40, 'height_pct' => 5, 'font_size' => 13, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('employee_name'), 'pos_x_pct' => 50, 'pos_y_pct' => 18, 'width_pct' => 42, 'height_pct' => 5, 'font_size' => 13, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('department'), 'pos_x_pct' => 8, 'pos_y_pct' => 24, 'width_pct' => 40, 'height_pct' => 5, 'font_size' => 13, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('position'), 'pos_x_pct' => 50, 'pos_y_pct' => 24, 'width_pct' => 42, 'height_pct' => 5, 'font_size' => 13, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('pay_period'), 'pos_x_pct' => 8, 'pos_y_pct' => 30, 'width_pct' => 84, 'height_pct' => 5, 'font_size' => 13, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('earning_lines_all'), 'pos_x_pct' => 8, 'pos_y_pct' => 38, 'width_pct' => 40, 'height_pct' => 22, 'font_size' => 12, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('deduction_lines_all'), 'pos_x_pct' => 52, 'pos_y_pct' => 38, 'width_pct' => 40, 'height_pct' => 22, 'font_size' => 12, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('statutory_lines_all'), 'pos_x_pct' => 8, 'pos_y_pct' => 62, 'width_pct' => 84, 'height_pct' => 15, 'font_size' => 12, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('gross_amount'), 'pos_x_pct' => 8, 'pos_y_pct' => 80, 'width_pct' => 40, 'height_pct' => 5, 'font_size' => 14, 'text_align' => 'left', 'font_weight' => 'bold'],
                    $base + ['element_type' => 'text', 'content' => $tok('net_amount'), 'pos_x_pct' => 52, 'pos_y_pct' => 80, 'width_pct' => 40, 'height_pct' => 5, 'font_size' => 14, 'text_align' => 'left', 'font_weight' => 'bold'],
                    $base + ['element_type' => 'text', 'content' => $tok('bank_account_masked'), 'pos_x_pct' => 8, 'pos_y_pct' => 88, 'width_pct' => 84, 'height_pct' => 5, 'font_size' => 11, 'text_align' => 'left'],
                ];
            case 'modern':
                return [
                    $base + ['element_type' => 'image', 'field_key' => 'company_logo', 'content' => null, 'pos_x_pct' => 6, 'pos_y_pct' => 5, 'width_pct' => 16, 'height_pct' => 10, 'font_size' => 14, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('company_name'), 'pos_x_pct' => 26, 'pos_y_pct' => 6, 'width_pct' => 66, 'height_pct' => 6, 'font_size' => 16, 'text_align' => 'left', 'font_weight' => 'bold'],
                    $base + ['element_type' => 'text', 'content' => $tok('company_address'), 'pos_x_pct' => 26, 'pos_y_pct' => 12, 'width_pct' => 66, 'height_pct' => 5, 'font_size' => 11, 'text_align' => 'left'],
                    $base + ['element_type' => 'shape', 'field_key' => 'line', 'font_color' => '#FF9900', 'pos_x_pct' => 6, 'pos_y_pct' => 19, 'width_pct' => 88, 'height_pct' => 0.5],
                    $base + ['element_type' => 'text', 'content' => $tok('employee_name') . '  (' . $tok('employee_no') . ')', 'pos_x_pct' => 6, 'pos_y_pct' => 22, 'width_pct' => 88, 'height_pct' => 5, 'font_size' => 13, 'text_align' => 'left', 'font_weight' => 'bold'],
                    $base + ['element_type' => 'text', 'content' => $tok('department') . ' / ' . $tok('position'), 'pos_x_pct' => 6, 'pos_y_pct' => 27, 'width_pct' => 88, 'height_pct' => 5, 'font_size' => 12, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('pay_period'), 'pos_x_pct' => 6, 'pos_y_pct' => 32, 'width_pct' => 88, 'height_pct' => 5, 'font_size' => 12, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('earning_lines_all'), 'pos_x_pct' => 6, 'pos_y_pct' => 40, 'width_pct' => 88, 'height_pct' => 18, 'font_size' => 12, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('deduction_lines_all'), 'pos_x_pct' => 6, 'pos_y_pct' => 60, 'width_pct' => 88, 'height_pct' => 12, 'font_size' => 12, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('statutory_lines_all'), 'pos_x_pct' => 6, 'pos_y_pct' => 74, 'width_pct' => 88, 'height_pct' => 12, 'font_size' => 12, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('net_amount'), 'pos_x_pct' => 6, 'pos_y_pct' => 89, 'width_pct' => 88, 'height_pct' => 6, 'font_size' => 15, 'text_align' => 'right', 'font_weight' => 'bold', 'font_color' => '#FF9900'],
                ];
            case 'minimal':
                return [
                    $base + ['element_type' => 'text', 'content' => $tok('company_name') . ' — ' . $tok('pay_period'), 'pos_x_pct' => 6, 'pos_y_pct' => 6, 'width_pct' => 88, 'height_pct' => 6, 'font_size' => 14, 'text_align' => 'left', 'font_weight' => 'bold'],
                    $base + ['element_type' => 'text', 'content' => $tok('employee_name') . '  (' . $tok('employee_no') . ')', 'pos_x_pct' => 6, 'pos_y_pct' => 14, 'width_pct' => 88, 'height_pct' => 5, 'font_size' => 12, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('earning_lines_all'), 'pos_x_pct' => 6, 'pos_y_pct' => 24, 'width_pct' => 88, 'height_pct' => 20, 'font_size' => 11, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('deduction_lines_all'), 'pos_x_pct' => 6, 'pos_y_pct' => 46, 'width_pct' => 88, 'height_pct' => 14, 'font_size' => 11, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('statutory_lines_all'), 'pos_x_pct' => 6, 'pos_y_pct' => 62, 'width_pct' => 88, 'height_pct' => 14, 'font_size' => 11, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $tok('net_amount'), 'pos_x_pct' => 6, 'pos_y_pct' => 80, 'width_pct' => 88, 'height_pct' => 6, 'font_size' => 14, 'text_align' => 'right', 'font_weight' => 'bold'],
                ];
            case 'blank':
            default:
                return [];
        }
    }

    /** Creates a new template pre-populated from one of presetOptions()'s layouts (or empty for
     *  'blank'). Just a convenience wrapper around save(). */
    public function createFromPreset(int $compId, string $preset, string $templateName, int $userId): array {
        if (!in_array($preset, self::PRESETS, true)) {
            return ['status' => false, 'message' => 'Invalid preset.'];
        }
        return $this->save($compId, [
            'template_name' => $templateName,
            'elements' => $this->presetElements($preset),
        ], $userId);
    }

    /** Public read-only entry point for the New Template modal's per-preset Preview button. */
    public function presetPreviewElements(string $preset): array {
        if (!in_array($preset, self::PRESETS, true)) {
            throw new InvalidArgumentException('Invalid preset.');
        }
        return $this->presetElements($preset);
    }

    /* ==================== Reusable uploaded-image library -- company-wide, not tied to one
       template, mirrors EmploymentCertificateTemplateModel's own image-library methods exactly. ==================== */

    public function listImages(int $compId): array {
        $stmt = $this->db->prepare("SELECT id, file_path, original_filename, uploaded_at
            FROM `payslip_images` WHERE comp_id = :comp_id ORDER BY uploaded_at DESC");
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addImage(int $compId, string $filePath, ?string $originalFilename, int $userId): array {
        if (!self::isValidImageAssetPath($filePath, $compId)) {
            return ['status' => false, 'message' => 'Invalid file path.'];
        }
        $stmt = $this->db->prepare("INSERT INTO `payslip_images` (comp_id, file_path, original_filename, uploaded_by)
            VALUES (:comp_id, :file_path, :original_filename, :uploaded_by)");
        $stmt->execute([':comp_id' => $compId, ':file_path' => $filePath, ':original_filename' => $originalFilename, ':uploaded_by' => $userId]);
        return ['status' => true, 'message' => 'Uploaded successfully.', 'id' => (int)$this->db->lastInsertId()];
    }

    /** Hard delete (the file itself is removed too, by the controller) -- blocked while any template
     *  element still references it. Same reasoning as EmploymentCertificateTemplateModel::deleteImage(). */
    public function deleteImage(int $compId, int $id): array {
        $stmt = $this->db->prepare("SELECT file_path FROM `payslip_images` WHERE id = :id AND comp_id = :comp_id");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $stmtUsage = $this->db->prepare("SELECT COUNT(*) FROM `payslip_template_elements` WHERE image_asset_id = :id");
        $stmtUsage->execute([':id' => $id]);
        if ((int)$stmtUsage->fetchColumn() > 0) {
            return ['status' => false, 'message' => 'This image is still used by at least one template and cannot be deleted.'];
        }
        $this->db->prepare("DELETE FROM `payslip_images` WHERE id = :id")->execute([':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.', 'file_path' => $row['file_path']];
    }
}
