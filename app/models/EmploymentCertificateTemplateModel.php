<?php
declare(strict_types=1);

/**
 * Employment Certificate Template designer backend.
 *
 * 2026-08-24, v2 (explicit follow-up request: "ให้รองรับหลาย Template และมี Template มาตรฐานให้เลือก
 * ใช้งาน สัก 2-3 แบบ และให้ตั้งค่าหน้ากระดาษได้...และเพิ่มให้ Upload รูปภาพมาใช้งานเองได้...ดึงกลับมาใช้ซ้ำ
 * ได้ใน Template ต่อไป") -- phase 1 shipped exactly ONE template per (comp_id, language); this
 * removes that restriction. Multiple templates per language now coexist, one flagged `is_default`
 * per (comp_id, language) -- same single-active-default enforcement pattern as
 * PayslipTemplateModel, not the "auto-create the one row, always reuse it" pattern phase 1 used.
 * `page_size`/`orientation` are now per-template (see EmploymentCertificateRenderer::
 * pageDimensionsMm() for how these map to actual mm). `presetElements()` seeds a NEW template with
 * one of 3 starter layouts (or blank) -- these are PHP-defined starting points, not DB rows /
 * company-owned data, so there's no "system template" table to keep in sync.
 *
 * Elements are still freely positioned/resized boxes stored as PERCENTAGES of the page (see phase
 * 1's own docblock in git history for why) -- now also carry `font_family`/`font_color`/
 * `font_style`/`text_decoration` (the "เหมือน Word" formatting controls) and `image_asset_id` (a
 * reusable, per-company uploaded image -- see the image-library methods at the bottom of this
 * class -- distinct from `field_key='company_logo'`, which still uses the template's own
 * `logo_path` instead).
 */
class EmploymentCertificateTemplateModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    private const LANGUAGES = ['th', 'en'];
    private const ELEMENT_TYPES = ['text', 'image'];
    private const TEXT_ALIGNS = ['left', 'center', 'right'];
    private const FONT_WEIGHTS = ['normal', 'bold'];
    private const FONT_STYLES = ['normal', 'italic'];
    private const TEXT_DECORATIONS = ['none', 'underline'];
    private const FONT_FAMILIES = ['th_sarabun_new', 'dejavu_sans'];
    private const PAGE_SIZES = ['A4', 'Letter', 'Legal'];
    private const ORIENTATIONS = ['portrait', 'landscape'];
    public const PRESETS = ['blank', 'classic', 'modern', 'minimal'];

    public function fieldTypeOptions(): array {
        $stmt = $this->db->query("SELECT code, name_th, name_en, field_group, element_type
            FROM `master_employment_certificate_field_types` WHERE is_active = 1 ORDER BY sort_order ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function isValidLogoPath(?string $path, int $compId): bool {
        if ($path === null || $path === '') {
            return true;
        }
        $pattern = '#^public/uploads/employment_cert_logos/' . $compId . '/[a-f0-9]{32}\.(jpg|png|svg)$#';
        return (bool)preg_match($pattern, $path);
    }

    public static function isValidImageAssetPath(?string $path, int $compId): bool {
        if ($path === null || $path === '') {
            return true;
        }
        $pattern = '#^public/uploads/employment_cert_images/' . $compId . '/[a-f0-9]{32}\.(jpg|png|svg)$#';
        return (bool)preg_match($pattern, $path);
    }

    /* ==================== Templates ==================== */

    private function getElements(int $templateId): array {
        $stmt = $this->db->prepare("SELECT id, element_type, field_key, image_asset_id, content,
                pos_x_pct, pos_y_pct, width_pct, height_pct, font_size, font_family, font_color,
                text_align, font_weight, font_style, text_decoration, sort_order
            FROM `employment_certificate_template_elements` WHERE template_id = :id ORDER BY sort_order ASC, id ASC");
        $stmt->execute([':id' => $templateId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array> every non-deleted template for this company+language, most recent first. */
    public function list(int $compId, string $language): array {
        if (!in_array($language, self::LANGUAGES, true)) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT t.*,
                (SELECT COUNT(*) FROM `employment_certificate_template_elements` e WHERE e.template_id = t.id) AS element_count
            FROM `employment_certificate_templates` t
            WHERE t.comp_id = :comp_id AND t.language = :language AND t.status = 'active'
            ORDER BY t.is_default DESC, t.updated_at DESC, t.id DESC");
        $stmt->execute([':comp_id' => $compId, ':language' => $language]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get(int $compId, int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `employment_certificate_templates`
            WHERE id = :id AND comp_id = :comp_id AND status = 'active'");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$template) {
            return null;
        }
        $template['elements'] = $this->getElements($id);
        return $template;
    }

    /** The template to open by default when a language tab loads -- the flagged default if one
     *  exists, else the most recently updated template, else null (truly nothing saved yet). */
    public function getDefault(int $compId, string $language): ?array {
        if (!in_array($language, self::LANGUAGES, true)) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM `employment_certificate_templates`
            WHERE comp_id = :comp_id AND language = :language AND status = 'active'
            ORDER BY is_default DESC, updated_at DESC, id DESC LIMIT 1");
        $stmt->execute([':comp_id' => $compId, ':language' => $language]);
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
            $cleaned[] = [
                'element_type' => $elementType, 'field_key' => $fieldKey, 'image_asset_id' => $imageAssetId, 'content' => $content,
                'pos_x_pct' => $posX, 'pos_y_pct' => $posY, 'width_pct' => $width, 'height_pct' => $height,
                'font_size' => $fontSize, 'font_family' => $fontFamily, 'font_color' => $fontColor,
                'text_align' => $textAlign, 'font_weight' => $fontWeight, 'font_style' => $fontStyle, 'text_decoration' => $textDecoration,
                'sort_order' => $n,
            ];
        }
        return ['elements' => $cleaned];
    }

    /**
     * Creates a NEW template (id omitted) or replaces an EXISTING one's whole element set in place
     * (id given) -- unlike phase 1's save(), this never "finds or auto-creates the one row for this
     * language"; the caller always says explicitly which template (or "a new one").
     * @param array $data {id?:int, language:string, template_name:string, page_size?:string,
     *   orientation?:string, logo_path?:?string, elements:array}
     */
    public function save(int $compId, array $data, int $userId): array {
        $language = (string)($data['language'] ?? '');
        if (!in_array($language, self::LANGUAGES, true)) {
            return ['status' => false, 'message' => 'Invalid language.'];
        }
        $templateName = trim((string)($data['template_name'] ?? ''));
        if ($templateName === '') {
            return ['status' => false, 'message' => 'Template name is required.'];
        }
        $pageSize = (string)($data['page_size'] ?? 'A4');
        if (!in_array($pageSize, self::PAGE_SIZES, true)) {
            return ['status' => false, 'message' => 'Invalid page_size.'];
        }
        $orientation = (string)($data['orientation'] ?? 'portrait');
        if (!in_array($orientation, self::ORIENTATIONS, true)) {
            return ['status' => false, 'message' => 'Invalid orientation.'];
        }
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
                $stmt = $this->db->prepare("SELECT id FROM `employment_certificate_images` WHERE comp_id = ? AND id IN ({$placeholders})");
                $stmt->execute(array_merge([$compId], $imageAssetIds));
                $validIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
                $missing = array_diff($imageAssetIds, $validIds);
                if (!empty($missing)) {
                    return ['status' => false, 'message' => 'One or more image elements reference an image that no longer exists.'];
                }
            }
        }

        $id = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;
        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            if ($id !== null) {
                $stmtCheck = $this->db->prepare("SELECT id FROM `employment_certificate_templates` WHERE id = :id AND comp_id = :comp_id AND status = 'active'");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                if (!$stmtCheck->fetch()) {
                    if ($own) { $this->db->rollBack(); }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                $logoSql = $logoPath !== null ? ", logo_path = :logo_path" : "";
                $stmt = $this->db->prepare("UPDATE `employment_certificate_templates`
                    SET template_name = :template_name, page_size = :page_size, orientation = :orientation{$logoSql},
                        updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $params = [
                    ':template_name' => $templateName, ':page_size' => $pageSize, ':orientation' => $orientation,
                    ':updated_by' => $userId, ':id' => $id,
                ];
                if ($logoPath !== null) {
                    $params[':logo_path'] = $logoPath !== '' ? $logoPath : null;
                }
                $stmt->execute($params);
                $templateId = $id;
            } else {
                $stmt = $this->db->prepare("INSERT INTO `employment_certificate_templates`
                    (comp_id, language, template_name, page_size, orientation, logo_path, status, created_by)
                    VALUES (:comp_id, :language, :template_name, :page_size, :orientation, :logo_path, 'active', :created_by)");
                $stmt->execute([
                    ':comp_id' => $compId, ':language' => $language, ':template_name' => $templateName,
                    ':page_size' => $pageSize, ':orientation' => $orientation,
                    ':logo_path' => ($logoPath !== null && $logoPath !== '') ? $logoPath : null,
                    ':created_by' => $userId,
                ]);
                $templateId = (int)$this->db->lastInsertId();
                // The very first template ever saved for this company+language becomes the default
                // automatically (there would otherwise be no default at all until the admin
                // explicitly sets one) -- every later new template stays non-default until chosen.
                $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM `employment_certificate_templates` WHERE comp_id = :comp_id AND language = :language AND status = 'active'");
                $stmtCount->execute([':comp_id' => $compId, ':language' => $language]);
                if ((int)$stmtCount->fetchColumn() === 1) {
                    $this->db->prepare("UPDATE `employment_certificate_templates` SET is_default = 1 WHERE id = :id")->execute([':id' => $templateId]);
                }
            }

            $this->db->prepare("DELETE FROM `employment_certificate_template_elements` WHERE template_id = :id")->execute([':id' => $templateId]);
            $insEl = $this->db->prepare("INSERT INTO `employment_certificate_template_elements`
                (template_id, element_type, field_key, image_asset_id, content, pos_x_pct, pos_y_pct, width_pct, height_pct,
                 font_size, font_family, font_color, text_align, font_weight, font_style, text_decoration, sort_order)
                VALUES (:template_id, :element_type, :field_key, :image_asset_id, :content, :pos_x_pct, :pos_y_pct, :width_pct, :height_pct,
                        :font_size, :font_family, :font_color, :text_align, :font_weight, :font_style, :text_decoration, :sort_order)");
            foreach ($elements as $el) {
                $insEl->execute([
                    ':template_id' => $templateId,
                    ':element_type' => $el['element_type'], ':field_key' => $el['field_key'], ':image_asset_id' => $el['image_asset_id'], ':content' => $el['content'],
                    ':pos_x_pct' => $el['pos_x_pct'], ':pos_y_pct' => $el['pos_y_pct'], ':width_pct' => $el['width_pct'], ':height_pct' => $el['height_pct'],
                    ':font_size' => $el['font_size'], ':font_family' => $el['font_family'], ':font_color' => $el['font_color'],
                    ':text_align' => $el['text_align'], ':font_weight' => $el['font_weight'], ':font_style' => $el['font_style'], ':text_decoration' => $el['text_decoration'],
                    ':sort_order' => $el['sort_order'],
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
            'language' => $source['language'],
            'template_name' => $source['template_name'] . ' (Copy)',
            'page_size' => $source['page_size'],
            'orientation' => $source['orientation'],
            'logo_path' => $source['logo_path'],
            'elements' => array_map(fn($e) => [
                'element_type' => $e['element_type'], 'field_key' => $e['field_key'], 'image_asset_id' => $e['image_asset_id'], 'content' => $e['content'],
                'pos_x_pct' => $e['pos_x_pct'], 'pos_y_pct' => $e['pos_y_pct'], 'width_pct' => $e['width_pct'], 'height_pct' => $e['height_pct'],
                'font_size' => $e['font_size'], 'font_family' => $e['font_family'], 'font_color' => $e['font_color'],
                'text_align' => $e['text_align'], 'font_weight' => $e['font_weight'], 'font_style' => $e['font_style'], 'text_decoration' => $e['text_decoration'],
            ], $source['elements']),
        ], $userId);
    }

    public function delete(int $compId, int $id, int $userId): array {
        $stmtCheck = $this->db->prepare("SELECT id FROM `employment_certificate_templates` WHERE id = :id AND comp_id = :comp_id AND status = 'active'");
        $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
        if (!$stmtCheck->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $stmt = $this->db->prepare("UPDATE `employment_certificate_templates` SET status = 'deleted', deleted_by = :deleted_by, deleted_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    /** Single-default-per-(comp_id,language) enforcement -- same pattern as PayslipTemplateModel. */
    public function setDefault(int $compId, int $id, int $userId): array {
        $template = $this->get($compId, $id);
        if (!$template) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            $this->db->prepare("UPDATE `employment_certificate_templates` SET is_default = 0
                WHERE comp_id = :comp_id AND language = :language AND status = 'active'")
                ->execute([':comp_id' => $compId, ':language' => $template['language']]);
            $this->db->prepare("UPDATE `employment_certificate_templates` SET is_default = 1, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':updated_by' => $userId, ':id' => $id]);
            if ($own) { $this->db->commit(); }
            return ['status' => true, 'message' => 'Default template updated.'];
        } catch (PDOException $e) {
            if ($own) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /* ==================== Starter presets (2026-08-24, explicit request: "มี Template มาตรฐาน
       ให้เลือกใช้งาน สัก 2-3 แบบ") -- PHP-defined starting element sets, not DB/company data.
       "blank" is the 4th, implicit option (an empty canvas) -- not counted toward the "2-3". ==================== */

    public function presetOptions(): array {
        return [
            ['code' => 'blank', 'name_th' => 'เริ่มจากหน้าว่าง', 'name_en' => 'Blank canvas'],
            ['code' => 'classic', 'name_th' => 'คลาสสิก (จัดกลาง)', 'name_en' => 'Classic (centered)'],
            ['code' => 'modern', 'name_th' => 'โมเดิร์น (โลโก้มุมซ้าย)', 'name_en' => 'Modern (logo top-left)'],
            ['code' => 'minimal', 'name_th' => 'มินิมอล (เรียบง่าย)', 'name_en' => 'Minimal (text only)'],
        ];
    }

    private function presetElements(string $preset, string $language): array {
        $title = $language === 'en' ? 'EMPLOYMENT CERTIFICATE' : 'หนังสือรับรองการทำงาน';
        $body = $language === 'en'
            ? 'This is to certify that {{employee_name}} (Employee No. {{employee_no}}), holding the position of {{position}} in the {{department}} department, has been employed with the company since {{employment_date}} to the present. Current employment status: {{employment_status}}.'
            : 'ขอรับรองว่า {{employee_name}} รหัสพนักงาน {{employee_no}} ดำรงตำแหน่ง {{position}} สังกัดแผนก {{department}} ได้เข้าทำงานกับบริษัทตั้งแต่วันที่ {{employment_date}} จนถึงปัจจุบัน สถานะการจ้างงานปัจจุบัน {{employment_status}}';
        $issuedOn = $language === 'en' ? 'Issued on {{issue_date}}' : 'ออกให้ ณ วันที่ {{issue_date}}';
        $base = [
            'font_family' => 'th_sarabun_new', 'font_color' => '#000000',
            'font_weight' => 'normal', 'font_style' => 'normal', 'text_decoration' => 'none',
        ];
        switch ($preset) {
            case 'classic':
                return [
                    $base + ['element_type' => 'text', 'content' => '{{company_name}}', 'pos_x_pct' => 10, 'pos_y_pct' => 5, 'width_pct' => 80, 'height_pct' => 8, 'font_size' => 20, 'text_align' => 'center', 'font_weight' => 'bold'],
                    $base + ['element_type' => 'text', 'content' => '{{company_address}}', 'pos_x_pct' => 10, 'pos_y_pct' => 13, 'width_pct' => 80, 'height_pct' => 6, 'font_size' => 13, 'text_align' => 'center'],
                    $base + ['element_type' => 'text', 'content' => $title, 'pos_x_pct' => 10, 'pos_y_pct' => 24, 'width_pct' => 80, 'height_pct' => 10, 'font_size' => 24, 'text_align' => 'center', 'font_weight' => 'bold', 'text_decoration' => 'underline'],
                    $base + ['element_type' => 'text', 'content' => $body, 'pos_x_pct' => 12, 'pos_y_pct' => 40, 'width_pct' => 76, 'height_pct' => 30, 'font_size' => 16, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $issuedOn, 'pos_x_pct' => 50, 'pos_y_pct' => 78, 'width_pct' => 40, 'height_pct' => 6, 'font_size' => 14, 'text_align' => 'center'],
                    $base + ['element_type' => 'text', 'content' => '{{company_signatory}}', 'pos_x_pct' => 50, 'pos_y_pct' => 88, 'width_pct' => 40, 'height_pct' => 6, 'font_size' => 14, 'text_align' => 'center'],
                ];
            case 'modern':
                return [
                    $base + ['element_type' => 'image', 'field_key' => 'company_logo', 'content' => null, 'pos_x_pct' => 6, 'pos_y_pct' => 6, 'width_pct' => 18, 'height_pct' => 12, 'font_size' => 14, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => '{{company_name}}', 'pos_x_pct' => 28, 'pos_y_pct' => 6, 'width_pct' => 66, 'height_pct' => 7, 'font_size' => 18, 'text_align' => 'left', 'font_weight' => 'bold'],
                    $base + ['element_type' => 'text', 'content' => '{{company_address}}', 'pos_x_pct' => 28, 'pos_y_pct' => 13, 'width_pct' => 66, 'height_pct' => 6, 'font_size' => 12, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $title, 'pos_x_pct' => 6, 'pos_y_pct' => 26, 'width_pct' => 88, 'height_pct' => 8, 'font_size' => 20, 'text_align' => 'left', 'font_weight' => 'bold'],
                    $base + ['element_type' => 'text', 'content' => $body, 'pos_x_pct' => 6, 'pos_y_pct' => 38, 'width_pct' => 88, 'height_pct' => 32, 'font_size' => 15, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $issuedOn, 'pos_x_pct' => 6, 'pos_y_pct' => 82, 'width_pct' => 40, 'height_pct' => 6, 'font_size' => 13, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => '{{company_signatory}}', 'pos_x_pct' => 58, 'pos_y_pct' => 82, 'width_pct' => 36, 'height_pct' => 6, 'font_size' => 13, 'text_align' => 'center'],
                ];
            case 'minimal':
                return [
                    $base + ['element_type' => 'text', 'content' => $title, 'pos_x_pct' => 10, 'pos_y_pct' => 10, 'width_pct' => 80, 'height_pct' => 8, 'font_size' => 18, 'text_align' => 'left', 'font_weight' => 'bold'],
                    $base + ['element_type' => 'text', 'content' => $body, 'pos_x_pct' => 10, 'pos_y_pct' => 22, 'width_pct' => 80, 'height_pct' => 40, 'font_size' => 15, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $issuedOn . ' — {{company_signatory}}', 'pos_x_pct' => 10, 'pos_y_pct' => 68, 'width_pct' => 80, 'height_pct' => 8, 'font_size' => 13, 'text_align' => 'left'],
                ];
            case 'blank':
            default:
                return [];
        }
    }

    /** Creates a new template pre-populated from one of presetOptions()'s layouts (or empty for
     *  'blank'). Just a convenience wrapper around save() -- the admin can freely drag/edit/delete
     *  every element afterward, this only seeds the starting point. */
    public function createFromPreset(int $compId, string $language, string $preset, string $templateName, int $userId): array {
        if (!in_array($preset, self::PRESETS, true)) {
            return ['status' => false, 'message' => 'Invalid preset.'];
        }
        return $this->save($compId, [
            'language' => $language, 'template_name' => $templateName,
            'elements' => $this->presetElements($preset, $language),
        ], $userId);
    }

    /* ==================== Reusable uploaded-image library (2026-08-24, explicit request:
       "เพิ่มให้ Upload รูปภาพมาใช้งานเองได้ โดย Upload แล้วดึงกลับมาใช้ซ้ำได้ใน Template ต่อไป") --
       company-wide, not tied to one template/language -- upload once, place on any template via
       an 'image' element's `image_asset_id`. ==================== */

    public function listImages(int $compId): array {
        $stmt = $this->db->prepare("SELECT id, file_path, original_filename, uploaded_at
            FROM `employment_certificate_images` WHERE comp_id = :comp_id ORDER BY uploaded_at DESC");
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addImage(int $compId, string $filePath, ?string $originalFilename, int $userId): array {
        if (!self::isValidImageAssetPath($filePath, $compId)) {
            return ['status' => false, 'message' => 'Invalid file path.'];
        }
        $stmt = $this->db->prepare("INSERT INTO `employment_certificate_images` (comp_id, file_path, original_filename, uploaded_by)
            VALUES (:comp_id, :file_path, :original_filename, :uploaded_by)");
        $stmt->execute([':comp_id' => $compId, ':file_path' => $filePath, ':original_filename' => $originalFilename, ':uploaded_by' => $userId]);
        return ['status' => true, 'message' => 'Uploaded successfully.', 'id' => (int)$this->db->lastInsertId()];
    }

    /** Hard delete (the file itself is removed too, by the controller, after this returns true) --
     *  an image library asset carries no history/compliance meaning of its own, unlike payroll
     *  data elsewhere in this app, so soft-delete's audit-trail rationale doesn't apply here.
     *  Blocked while any template element still references it -- the FK is ON DELETE SET NULL, but
     *  silently orphaning a template's image without telling the admin would be a worse experience
     *  than a clear "still in use" refusal. */
    public function deleteImage(int $compId, int $id): array {
        $stmt = $this->db->prepare("SELECT file_path FROM `employment_certificate_images` WHERE id = :id AND comp_id = :comp_id");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $stmtUsage = $this->db->prepare("SELECT COUNT(*) FROM `employment_certificate_template_elements` WHERE image_asset_id = :id");
        $stmtUsage->execute([':id' => $id]);
        if ((int)$stmtUsage->fetchColumn() > 0) {
            return ['status' => false, 'message' => 'This image is still used by at least one template and cannot be deleted.'];
        }
        $this->db->prepare("DELETE FROM `employment_certificate_images` WHERE id = :id")->execute([':id' => $id]);
        return ['status' => true, 'message' => 'Deleted successfully.', 'file_path' => $row['file_path']];
    }
}
