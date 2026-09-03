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
    // 2026-08-25, explicit request: "เพิ่ม option การเพิ่มตาราง...และสามารถ insert shape ต่างๆ เหมือน Word"
    // -- 'shape' reuses `field_key` to say WHICH shape (see SHAPE_TYPES), 'table' stores its grid as
    // JSON in `content` (the same column every text element already uses for its own string data).
    private const ELEMENT_TYPES = ['text', 'image', 'shape', 'table'];
    private const SHAPE_TYPES = ['rectangle', 'ellipse', 'line'];
    private const TEXT_ALIGNS = ['left', 'center', 'right'];
    private const FONT_WEIGHTS = ['normal', 'bold'];
    private const FONT_STYLES = ['normal', 'italic'];
    private const TEXT_DECORATIONS = ['none', 'underline'];
    // 2026-08-25, explicit request: "เพิ่มตัวเลือก font สัก 10 font ครับ" -- shipped with 7, not 10: see
    // EmploymentCertificateRenderer's own comment for why (every option here needs a REAL font file
    // dompdf can embed, to keep the canvas preview and the actual PDF from silently drifting apart --
    // there aren't 10 more legitimately-available ones in this environment without either breaking
    // that guarantee or bundling something not freely redistributable).
    private const FONT_FAMILIES = ['th_sarabun_new', 'dejavu_sans', 'dejavu_sans_mono', 'dejavu_serif', 'helvetica', 'times_new_roman', 'courier'];
    // 2026-08-26, explicit request: "ตรง Page Setup ให้เพิ่ม A3 A5 และอื่นๆ เหมือนใน Word" -- same
    // practical Word-style paper-size set (minus envelopes, irrelevant for a certificate/payslip)
    // added identically to Payslip Template's own PAGE_SIZES, see this class's own renderer
    // counterpart (EmploymentCertificateRenderer::PAGE_SIZES_MM) for the actual mm dimensions.
    private const PAGE_SIZES = ['A3', 'A4', 'A5', 'B4', 'B5', 'Letter', 'Legal', 'Tabloid', 'Executive', 'Statement'];
    private const ORIENTATIONS = ['portrait', 'landscape'];
    private const MAX_PAGE_NUMBER = 20;
    public const PRESETS = ['blank', 'classic', 'modern', 'minimal', 'formal', 'elegant'];
    // 2026-08-25, explicit request: "สามารถ Assign ตั้งค่าให้พนักงาน เป็นรายแผนก รายทีม หรือรายคน หรือ
    // ใช้งานร่วมกันทั้งหมดก็ได้" -- same polymorphic scope pattern as PayslipTemplateModel's own (see
    // that class's own docblock for the full reasoning, incl. why there's no include/exclude mode).
    // Assignment is per LANGUAGE ROW here (not per pair) -- same granularity as logo_path/elements
    // already are, so assigning the Thai design and English design to an audience is two separate
    // steps, one per language tab, consistent with how everything else in this per-row architecture
    // already works. This is config-only for now, same as Holiday's own resolver was before Holiday
    // had a real consumer -- Employment Certificate Template still has NO request/issuance flow that
    // would actually call resolveTemplateForEmployee() below, see this file's own top-of-class notes.
    private const SCOPE_TYPES = ['department', 'team', 'employee'];
    private const SCOPE_PRIORITY = ['employee' => 3, 'team' => 2, 'department' => 1];

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
                text_align, font_weight, font_style, text_decoration, sort_order, group_key, page_number, is_visible
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

    /**
     * 2026-08-25, explicit request: "ตรงตาราง Template ในขั้นตอนการจัดการ ให้มี th กับ eng ในการจัดการเลย
     * ไม่ต้องแยกเป็น Tab เหมือนเดิม...แล้วในตารางแสดงผลก็ว่า template นี้ th eng พร้อมใช้งานทั้ง 2 ไหม" --
     * one row per pair_key, not per template row. Fetches every active template for the company
     * (both languages) in one query, groups in PHP (there are only ever a handful of templates per
     * company, same "small enough for client-side DataTables" tier as Payslip Template's own list --
     * no need for a GROUP_CONCAT/window-function query for this). Each group's display name/page
     * size/orientation come from whichever language exists (TH preferred if both do and happen to
     * differ, which can only happen if an admin manually diverged them post-generateOtherLanguage()
     * -- not specially reconciled, each language's own Edit modal is still the source of truth for
     * ITS OWN data regardless of what this summary row shows).
     * @return array<int,array{pair_key:string, template_name:string, page_size:string, orientation:string,
     *   th:?array, en:?array}>
     */
    public function listPaired(int $compId): array {
        $stmt = $this->db->prepare("SELECT id, language, pair_key, template_name, page_size, orientation, is_default, publish_status, auto_save, updated_at
            FROM `employment_certificate_templates`
            WHERE comp_id = :comp_id AND status = 'active'
            ORDER BY updated_at DESC, id DESC");
        $stmt->execute([':comp_id' => $compId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pairs = [];
        foreach ($rows as $row) {
            $key = (string)$row['pair_key'];
            if (!isset($pairs[$key])) {
                $pairs[$key] = [
                    'pair_key' => $key, 'template_name' => $row['template_name'],
                    'page_size' => $row['page_size'], 'orientation' => $row['orientation'],
                    'th' => null, 'en' => null, 'latest_updated_at' => $row['updated_at'],
                ];
            }
            $langInfo = ['id' => (int)$row['id'], 'is_default' => (bool)$row['is_default'],
                'publish_status' => $row['publish_status'], 'auto_save' => (bool)$row['auto_save'], 'updated_at' => $row['updated_at']];
            if ($row['language'] === 'th') {
                $pairs[$key]['th'] = $langInfo;
                $pairs[$key]['template_name'] = $row['template_name']; // TH preferred as the display name when both exist
                $pairs[$key]['page_size'] = $row['page_size'];
                $pairs[$key]['orientation'] = $row['orientation'];
            } else {
                $pairs[$key]['en'] = $langInfo;
            }
            if ($row['updated_at'] > $pairs[$key]['latest_updated_at']) {
                $pairs[$key]['latest_updated_at'] = $row['updated_at'];
            }
        }
        $result = array_values($pairs);
        usort($result, fn($a, $b) => strcmp((string)$b['latest_updated_at'], (string)$a['latest_updated_at']));
        return $result;
    }

    /** Same per-pair shape as one row of listPaired() above, but looked up by a single pair_key --
     *  backs the standalone editor page's route (`employment-certificate/edit/{key}`, 2026-08-25
     *  explicit request: "หน้าแก้ไขให้เปลี่ยนเป็นการเปิด Tab ใหม่...โดยส่ง key ไปต่อ /key"). Returns null
     *  if the company has no active template (either language) under this pair_key. */
    public function getPairByKey(int $compId, string $pairKey): ?array {
        $stmt = $this->db->prepare("SELECT id, language, pair_key, template_name, page_size, orientation, is_default, publish_status, auto_save, updated_at
            FROM `employment_certificate_templates`
            WHERE comp_id = :comp_id AND pair_key = :pair_key AND status = 'active'");
        $stmt->execute([':comp_id' => $compId, ':pair_key' => $pairKey]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($rows)) {
            return null;
        }
        $pair = ['pair_key' => $pairKey, 'template_name' => $rows[0]['template_name'], 'page_size' => $rows[0]['page_size'], 'orientation' => $rows[0]['orientation'], 'th' => null, 'en' => null];
        foreach ($rows as $row) {
            $langInfo = ['id' => (int)$row['id'], 'is_default' => (bool)$row['is_default'],
                'publish_status' => $row['publish_status'], 'auto_save' => (bool)$row['auto_save']];
            if ($row['language'] === 'th') {
                $pair['th'] = $langInfo;
                $pair['template_name'] = $row['template_name'];
                $pair['page_size'] = $row['page_size'];
                $pair['orientation'] = $row['orientation'];
            } else {
                $pair['en'] = $langInfo;
            }
        }
        return $pair;
    }

    /**
     * "Generate other language, Auto" (2026-08-25, explicit request -- confirmed via AskUserQuestion:
     * since this app has no real translation service, "Auto" means cloning the SOURCE template's
     * whole element structure -- positions/sizes/fonts/images/groups -- into a new row for the
     * OTHER language, verbatim, including any free-text content, which stays in the source's
     * language until the admin edits it themselves. This is explicitly NOT a translation -- it just
     * saves the layout work, same idea as starting from a preset). The new row shares the source's
     * `pair_key` so the unified list shows them as one line. Refuses if a row for that pair_key +
     * target language already exists (use the normal Edit flow for an existing one, this is only
     * for filling in a genuinely missing language).
     */
    public function generateOtherLanguage(int $compId, int $sourceTemplateId, int $userId): array {
        $source = $this->get($compId, $sourceTemplateId);
        if (!$source) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $targetLanguage = $source['language'] === 'th' ? 'en' : 'th';
        $stmtExisting = $this->db->prepare("SELECT id FROM `employment_certificate_templates`
            WHERE comp_id = :comp_id AND pair_key = :pair_key AND language = :language AND status = 'active'");
        $stmtExisting->execute([':comp_id' => $compId, ':pair_key' => $source['pair_key'], ':language' => $targetLanguage]);
        if ($stmtExisting->fetch()) {
            return ['status' => false, 'message' => 'The other language already exists for this template.'];
        }
        $clonedElements = array_map(function (array $el): array {
            return [
                'element_type' => $el['element_type'], 'field_key' => $el['field_key'],
                'image_asset_id' => $el['image_asset_id'], 'content' => $el['content'],
                'pos_x_pct' => $el['pos_x_pct'], 'pos_y_pct' => $el['pos_y_pct'],
                'width_pct' => $el['width_pct'], 'height_pct' => $el['height_pct'],
                'font_size' => $el['font_size'], 'font_family' => $el['font_family'], 'font_color' => $el['font_color'],
                'text_align' => $el['text_align'], 'font_weight' => $el['font_weight'], 'font_style' => $el['font_style'],
                'text_decoration' => $el['text_decoration'], 'group_key' => $el['group_key'],
                'page_number' => $el['page_number'] ?? 1, 'is_visible' => $el['is_visible'] ?? 1,
            ];
        }, $source['elements']);
        return $this->save($compId, [
            'language' => $targetLanguage, 'pair_key' => $source['pair_key'],
            'template_name' => $source['template_name'], 'page_size' => $source['page_size'], 'orientation' => $source['orientation'],
            'margin_mm' => $source['margin_mm'], 'logo_path' => $source['logo_path'] ?? null, 'elements' => $clonedElements,
        ], $userId);
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
        $template['assignments'] = $this->getAssignments($id);
        return $template;
    }

    /* ==================== Assignment (department/team/employee scoping) -- see this class's own
       const block comment for the full reasoning; mirrors PayslipTemplateModel's own methods. ==================== */

    private function validateScopeRef(string $scopeType, int $scopeId, int $compId): bool {
        $table = match ($scopeType) {
            'department' => 'structure_departments',
            'team' => 'structure_teams',
            'employee' => 'employees',
            default => null,
        };
        if ($table === null) {
            return false;
        }
        $stmt = $this->db->prepare("SELECT id FROM `{$table}` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $scopeId, ':comp_id' => $compId]);
        return (bool)$stmt->fetch();
    }

    public function getAssignments(int $templateId): array {
        $stmt = $this->db->prepare("SELECT id, scope_type, scope_id FROM `employment_certificate_template_assignments` WHERE template_id = :id ORDER BY scope_type ASC, id ASC");
        $stmt->execute([':id' => $templateId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['label'] = $this->scopeLabel($row['scope_type'], (int)$row['scope_id']);
        }
        unset($row);
        return $rows;
    }

    /** All active departments/teams/employees for this company, for the "Assign To" tab's checkbox
     *  lists -- mirrors PayslipTemplateModel::assignableOptions() exactly, see that method's own
     *  comment for why a full (not paginated-search) list is fetched. */
    public function assignableOptions(int $compId): array {
        $stmtD = $this->db->prepare("SELECT id, department_name_th AS text_th, department_name_en AS text_en
            FROM `structure_departments` WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL ORDER BY department_name_th ASC");
        $stmtD->execute([':comp_id' => $compId]);
        $stmtT = $this->db->prepare("SELECT id, team_name_th AS text_th, team_name_en AS text_en
            FROM `structure_teams` WHERE comp_id = :comp_id AND status = 'active' AND deleted_at IS NULL ORDER BY team_name_th ASC");
        $stmtT->execute([':comp_id' => $compId]);
        $stmtE = $this->db->prepare("SELECT id, CONCAT(employee_no, ' - ', name_th, ' ', surname_th) AS text_th,
                CONCAT(employee_no, ' - ', name_en, ' ', surname_en) AS text_en
            FROM `employees` WHERE comp_id = :comp_id AND deleted_at IS NULL ORDER BY name_th ASC");
        $stmtE->execute([':comp_id' => $compId]);
        return [
            'departments' => $stmtD->fetchAll(PDO::FETCH_ASSOC),
            'teams' => $stmtT->fetchAll(PDO::FETCH_ASSOC),
            'employees' => $stmtE->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    private function scopeLabel(string $scopeType, int $scopeId): string {
        if ($scopeType === 'department') {
            $stmt = $this->db->prepare("SELECT department_name_th AS th, department_name_en AS en FROM `structure_departments` WHERE id = :id");
        } elseif ($scopeType === 'team') {
            $stmt = $this->db->prepare("SELECT team_name_th AS th, team_name_en AS en FROM `structure_teams` WHERE id = :id");
        } else {
            $stmt = $this->db->prepare("SELECT CONCAT(employee_no, ' - ', name_th, ' ', surname_th) AS th, CONCAT(employee_no, ' - ', name_en, ' ', surname_en) AS en FROM `employees` WHERE id = :id");
        }
        $stmt->execute([':id' => $scopeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return "(#{$scopeId})";
        }
        return trim((string)$row['th']) !== '' ? $row['th'] : (string)$row['en'];
    }

    /** Same conflict-detection as PayslipTemplateModel::findConflictingAssignment() but scoped to
     *  the SAME LANGUAGE (not company-wide) -- a department assigned to the Thai design and the
     *  SAME department assigned to the English design of a DIFFERENT template are not a conflict at
     *  all, since resolveTemplateForEmployee() itself resolves th/en completely independently. Only
     *  ACTIVE templates count (status='active', unrelated to the elements/is_default fields this
     *  module has no equivalent of -- see this class's own docblock). */
    private function findConflictingAssignment(string $scopeType, int $scopeId, int $compId, string $language, ?int $excludeTemplateId): ?array {
        $sql = "SELECT t.id, t.template_name FROM `employment_certificate_template_assignments` a
            JOIN `employment_certificate_templates` t ON t.id = a.template_id
            WHERE t.comp_id = :comp_id AND t.language = :language AND t.status = 'active'
              AND a.scope_type = :scope_type AND a.scope_id = :scope_id";
        $params = [':comp_id' => $compId, ':language' => $language, ':scope_type' => $scopeType, ':scope_id' => $scopeId];
        if ($excludeTemplateId !== null) {
            $sql .= " AND t.id != :exclude_id";
            $params[':exclude_id'] = $excludeTemplateId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array{error?:string, assignments?:array}
     * 2026-08-25, explicit request: "ถ้ามีการตั้งค่าซ้ำต้องแจ้ง Error ว่ามีการ Assign ซ้ำใคร" -- same
     * hard-reject-on-conflict behavior as PayslipTemplateModel's own (see that class's comment),
     * scoped per language here (see findConflictingAssignment()'s own comment for why).
     */
    private function validateAssignments(array $raw, int $compId, string $language, ?int $excludeTemplateId): array {
        $seen = [];
        $cleaned = [];
        foreach ($raw as $i => $a) {
            $n = $i + 1;
            $scopeType = (string)($a['scope_type'] ?? '');
            $scopeId = is_numeric($a['scope_id'] ?? null) ? (int)$a['scope_id'] : 0;
            if (!in_array($scopeType, self::SCOPE_TYPES, true) || $scopeId <= 0) {
                return ['error' => "Assignment {$n}: invalid scope."];
            }
            if (!$this->validateScopeRef($scopeType, $scopeId, $compId)) {
                return ['error' => "Assignment {$n}: {$scopeType} #{$scopeId} not found."];
            }
            $conflict = $this->findConflictingAssignment($scopeType, $scopeId, $compId, $language, $excludeTemplateId);
            if ($conflict !== null) {
                $label = $this->scopeLabel($scopeType, $scopeId);
                return ['error' => "\"{$label}\" is already assigned to another active template (\"{$conflict['template_name']}\") for this language. Remove it there first, or deactivate that template."];
            }
            $key = $scopeType . ':' . $scopeId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $cleaned[] = ['scope_type' => $scopeType, 'scope_id' => $scopeId];
        }
        return ['assignments' => $cleaned];
    }

    /** Resolves which template (of the given language) applies to a specific employee -- same
     *  employee > team > department > company default priority as PayslipTemplateModel's own
     *  resolveTemplateForEmployee(). Config-only for now -- see this class's own const block
     *  comment for why there's still no consumer that calls this. */
    public function resolveTemplateForEmployee(int $compId, int $employeeId, string $language): ?array {
        if (!in_array($language, self::LANGUAGES, true)) {
            return null;
        }
        $stmtEmp = $this->db->prepare("SELECT department_id, team_id FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);

        $candidateScopes = [['scope_type' => 'employee', 'scope_id' => $employeeId]];
        if ($emp && !empty($emp['team_id'])) {
            $candidateScopes[] = ['scope_type' => 'team', 'scope_id' => (int)$emp['team_id']];
        }
        if ($emp && !empty($emp['department_id'])) {
            $candidateScopes[] = ['scope_type' => 'department', 'scope_id' => (int)$emp['department_id']];
        }

        // 2026-08-26, explicit request: "ให้มี Draft Mode และ Public Mode...ตั้งต้นเป็น Draft mode ก่อน
        // แล้วค่อย Public" -- a draft must never be resolved for real issuance, same gate added to
        // PayslipTemplateModel's own equivalent methods.
        $stmt = $this->db->prepare("SELECT t.id, t.updated_at, a.scope_type
            FROM `employment_certificate_template_assignments` a
            JOIN `employment_certificate_templates` t ON t.id = a.template_id
            WHERE t.comp_id = :comp_id AND t.language = :language AND t.status = 'active' AND t.publish_status = 'public'
              AND a.scope_type = :scope_type AND a.scope_id = :scope_id");
        $best = null;
        $bestPriority = -1;
        foreach ($candidateScopes as $scope) {
            $stmt->execute([':comp_id' => $compId, ':language' => $language, ':scope_type' => $scope['scope_type'], ':scope_id' => $scope['scope_id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $priority = self::SCOPE_PRIORITY[$row['scope_type']] ?? 0;
                if ($priority > $bestPriority || ($priority === $bestPriority && $best !== null && $row['updated_at'] > $best['updated_at'])) {
                    $bestPriority = $priority;
                    $best = $row;
                }
            }
        }
        if ($best !== null) {
            return $this->get($compId, (int)$best['id']);
        }
        return $this->getDefault($compId, $language);
    }

    /** The template to open by default when a language tab loads -- the flagged default if one
     *  exists, else the most recently updated template, else null (truly nothing saved yet). */
    public function getDefault(int $compId, string $language): ?array {
        if (!in_array($language, self::LANGUAGES, true)) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM `employment_certificate_templates`
            WHERE comp_id = :comp_id AND language = :language AND status = 'active' AND publish_status = 'public'
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
            } elseif ($elementType === 'shape') {
                // field_key doubles as "which shape" here -- rectangle/ellipse/line -- font_color
                // (already validated below, same as every other element) is its fill/stroke color.
                if (!in_array($fieldKey, self::SHAPE_TYPES, true)) {
                    return ['error' => "Element {$n}: invalid shape type."];
                }
                $imageAssetId = null;
                $content = null;
            } elseif ($elementType === 'table') {
                // 2026-08-25, explicit request: "เพิ่ม option การเพิ่มตาราง ที่สามารถกำหนดเส้นสีเส้นขอบได้
                // เหมือน word" -- the grid (row/col count, border color/width, per-cell text) is
                // stored as JSON in `content`, re-encoded here (not stored verbatim) so a malformed
                // payload can never reach the database, and so every row always has exactly `cols`
                // cells regardless of what the client actually sent.
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
            // 2026-08-24, explicit request: Group/Ungroup -- group_key is an opaque client-generated
            // tag (see the migration's own comment), not validated against any format beyond a
            // length cap; empty/absent means ungrouped.
            $groupKey = !empty($raw['group_key']) ? substr((string)$raw['group_key'], 0, 64) : null;
            // 2026-08-25, explicit request: "รองรับการมีหลายๆหน้า โดยที่มีปุ่มให้เลือกเพิ่มหรือลด" -- 1-based;
            // which physical page (in the PDF) this element belongs to. The coordinate system itself
            // doesn't change at all -- still percentage-of-ONE-page, just repeated per distinct
            // page_number found (see EmploymentCertificateRenderer::buildHtml()).
            $pageNumber = is_numeric($raw['page_number'] ?? null) ? (int)$raw['page_number'] : 1;
            $pageNumber = max(1, min(self::MAX_PAGE_NUMBER, $pageNumber));
            // 2026-08-26, explicit request: "ตรง Layer ให้มี function เปิด/ปิดตาได้ แทนการที่ต้องลบอย่างเดียว"
            // -- defaults to visible=true when absent so old saved payloads/tests that never sent this
            // field keep working unchanged.
            $isVisible = !array_key_exists('is_visible', $raw) || !empty($raw['is_visible']) ? 1 : 0;
            $cleaned[] = [
                'element_type' => $elementType, 'field_key' => $fieldKey, 'image_asset_id' => $imageAssetId, 'content' => $content,
                'pos_x_pct' => $posX, 'pos_y_pct' => $posY, 'width_pct' => $width, 'height_pct' => $height,
                'font_size' => $fontSize, 'font_family' => $fontFamily, 'font_color' => $fontColor,
                'text_align' => $textAlign, 'font_weight' => $fontWeight, 'font_style' => $fontStyle, 'text_decoration' => $textDecoration,
                'sort_order' => $n, 'group_key' => $groupKey, 'page_number' => $pageNumber, 'is_visible' => $isVisible,
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
        // 2026-08-25, explicit request: "เพิ่มให้ตั้งค่าขอบกระดาษได้ด้วยครับ" -- a VISUAL GUIDE ONLY (see
        // the migration's own comment), clamped to a sane range so a typo/garbage value can't push
        // the guide rectangle off the page entirely.
        $marginMm = array_key_exists('margin_mm', $data) ? (float)$data['margin_mm'] : 15.0;
        $marginMm = max(0.0, min(50.0, $marginMm));
        // 2026-08-26, explicit request: "เพิ่มให้ติ๊กได้ว่าต้องการให้ Auto Save...ตั้งต้นเป็น Draft mode
        // ก่อน แล้วค่อย Public" -- same as PayslipTemplateModel::save()'s own comment: publish_status
        // is deliberately NOT accepted here, only ever changed via setPublishStatus().
        $autoSave = !empty($data['auto_save']) ? 1 : 0;
        // 2026-08-26, explicit request: "เพิ่ม Set as default template ใน เอกสารด้วย" -- is_default
        // already existed in the schema/getDefault()'s own fallback, just had no editor-page toggle
        // before now; wired the same bidirectional way PayslipTemplateModel::save() already does
        // (part of the regular Save payload, both true AND false explicitly persisted -- see the
        // "clear other defaults" block below).
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        $logoPath = array_key_exists('logo_path', $data) ? (string)$data['logo_path'] : null;
        if ($logoPath !== null && $logoPath !== '' && !self::isValidLogoPath($logoPath, $compId)) {
            return ['status' => false, 'message' => 'Invalid logo path.'];
        }
        $result = $this->validateElements(is_array($data['elements'] ?? null) ? $data['elements'] : []);
        if (isset($result['error'])) {
            return ['status' => false, 'message' => $result['error']];
        }
        $elements = $result['elements'];
        $idForAssignCheck = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;
        $assignResult = $this->validateAssignments(is_array($data['assignments'] ?? null) ? $data['assignments'] : [], $compId, $language, $idForAssignCheck);
        if (isset($assignResult['error'])) {
            return ['status' => false, 'message' => $assignResult['error']];
        }
        $assignments = $assignResult['assignments'];
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
                    SET template_name = :template_name, page_size = :page_size, orientation = :orientation, margin_mm = :margin_mm, auto_save = :auto_save, is_default = :is_default{$logoSql},
                        updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $params = [
                    ':template_name' => $templateName, ':page_size' => $pageSize, ':orientation' => $orientation, ':margin_mm' => $marginMm,
                    ':auto_save' => $autoSave, ':is_default' => $isDefault, ':updated_by' => $userId, ':id' => $id,
                ];
                if ($logoPath !== null) {
                    $params[':logo_path'] = $logoPath !== '' ? $logoPath : null;
                }
                $stmt->execute($params);
                $templateId = $id;
            } else {
                // 2026-08-25, explicit request: unified TH/EN list ("ให้มี th กับ eng ในการจัดการเลย
                // ไม่ต้องแยกเป็น Tab") -- every template gets a pair_key from creation onward, either
                // the caller's own (generateOtherLanguage() below passes the SOURCE template's
                // pair_key, so the new row links to it) or a fresh one for a genuinely brand-new,
                // not-yet-paired template. See the migration's own comment for why this is never null.
                $pairKey = !empty($data['pair_key']) ? substr((string)$data['pair_key'], 0, 64) : bin2hex(random_bytes(16));
                $stmt = $this->db->prepare("INSERT INTO `employment_certificate_templates`
                    (comp_id, language, pair_key, template_name, page_size, orientation, margin_mm, logo_path, status, publish_status, auto_save, created_by)
                    VALUES (:comp_id, :language, :pair_key, :template_name, :page_size, :orientation, :margin_mm, :logo_path, 'active', 'draft', :auto_save, :created_by)");
                $stmt->execute([
                    ':comp_id' => $compId, ':language' => $language, ':pair_key' => $pairKey, ':template_name' => $templateName,
                    ':page_size' => $pageSize, ':orientation' => $orientation, ':margin_mm' => $marginMm,
                    ':logo_path' => ($logoPath !== null && $logoPath !== '') ? $logoPath : null,
                    ':auto_save' => $autoSave, ':created_by' => $userId,
                ]);
                $templateId = (int)$this->db->lastInsertId();
                // The very first template ever saved for this company+language becomes the default
                // automatically (there would otherwise be no default at all until the admin
                // explicitly sets one) -- every later new template stays non-default until chosen.
                $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM `employment_certificate_templates` WHERE comp_id = :comp_id AND language = :language AND status = 'active'");
                $stmtCount->execute([':comp_id' => $compId, ':language' => $language]);
                if ((int)$stmtCount->fetchColumn() === 1) {
                    $this->db->prepare("UPDATE `employment_certificate_templates` SET is_default = 1 WHERE id = :id")->execute([':id' => $templateId]);
                    $isDefault = 1;
                }
            }

            if ($isDefault) {
                $this->db->prepare("UPDATE `employment_certificate_templates` SET is_default = 0 WHERE comp_id = :comp_id AND language = :language AND id != :id AND status = 'active'")
                    ->execute([':comp_id' => $compId, ':language' => $language, ':id' => $templateId]);
            }

            $this->db->prepare("DELETE FROM `employment_certificate_template_elements` WHERE template_id = :id")->execute([':id' => $templateId]);
            $insEl = $this->db->prepare("INSERT INTO `employment_certificate_template_elements`
                (template_id, element_type, field_key, image_asset_id, content, pos_x_pct, pos_y_pct, width_pct, height_pct,
                 font_size, font_family, font_color, text_align, font_weight, font_style, text_decoration, sort_order, group_key, page_number, is_visible)
                VALUES (:template_id, :element_type, :field_key, :image_asset_id, :content, :pos_x_pct, :pos_y_pct, :width_pct, :height_pct,
                        :font_size, :font_family, :font_color, :text_align, :font_weight, :font_style, :text_decoration, :sort_order, :group_key, :page_number, :is_visible)");
            foreach ($elements as $el) {
                $insEl->execute([
                    ':template_id' => $templateId,
                    ':element_type' => $el['element_type'], ':field_key' => $el['field_key'], ':image_asset_id' => $el['image_asset_id'], ':content' => $el['content'],
                    ':pos_x_pct' => $el['pos_x_pct'], ':pos_y_pct' => $el['pos_y_pct'], ':width_pct' => $el['width_pct'], ':height_pct' => $el['height_pct'],
                    ':font_size' => $el['font_size'], ':font_family' => $el['font_family'], ':font_color' => $el['font_color'],
                    ':text_align' => $el['text_align'], ':font_weight' => $el['font_weight'], ':font_style' => $el['font_style'], ':text_decoration' => $el['text_decoration'],
                    ':sort_order' => $el['sort_order'], ':group_key' => $el['group_key'], ':page_number' => $el['page_number'] ?? 1,
                    ':is_visible' => $el['is_visible'] ?? 1,
                ]);
            }

            $this->db->prepare("DELETE FROM `employment_certificate_template_assignments` WHERE template_id = :id")->execute([':id' => $templateId]);
            $insAssign = $this->db->prepare("INSERT INTO `employment_certificate_template_assignments` (template_id, scope_type, scope_id) VALUES (:template_id, :scope_type, :scope_id)");
            foreach ($assignments as $a) {
                $insAssign->execute([':template_id' => $templateId, ':scope_type' => $a['scope_type'], ':scope_id' => $a['scope_id']]);
            }

            if ($own) {
                $this->db->commit();
            }
            // 2026-08-25, explicit request: the edit screen is now a standalone page addressed by
            // pair_key in the URL ("ส่ง key ไปต่อ /key") -- the client needs the pair_key of whatever
            // it just created/updated to build that URL/open the new tab, without a second round
            // trip. Cheap enough to look up unconditionally (covers both the INSERT branch, where
            // $pairKey is already known, and the UPDATE branch, where it isn't computed above at all).
            $stmtPairKey = $this->db->prepare("SELECT pair_key FROM `employment_certificate_templates` WHERE id = :id");
            $stmtPairKey->execute([':id' => $templateId]);
            $pairKeyOut = $stmtPairKey->fetchColumn();
            return ['status' => true, 'message' => 'Saved successfully.', 'template_id' => $templateId, 'pair_key' => $pairKeyOut !== false ? $pairKeyOut : null];
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
            'margin_mm' => $source['margin_mm'],
            'logo_path' => $source['logo_path'],
            // 2026-08-25, real bug found & fixed while adding duplicatePair() below: group_key was
            // never carried over here, so duplicating a template silently ungrouped every element
            // that had been grouped in the source.
            'elements' => array_map(fn($e) => [
                'element_type' => $e['element_type'], 'field_key' => $e['field_key'], 'image_asset_id' => $e['image_asset_id'], 'content' => $e['content'],
                'pos_x_pct' => $e['pos_x_pct'], 'pos_y_pct' => $e['pos_y_pct'], 'width_pct' => $e['width_pct'], 'height_pct' => $e['height_pct'],
                'font_size' => $e['font_size'], 'font_family' => $e['font_family'], 'font_color' => $e['font_color'],
                'text_align' => $e['text_align'], 'font_weight' => $e['font_weight'], 'font_style' => $e['font_style'], 'text_decoration' => $e['text_decoration'],
                'group_key' => $e['group_key'] ?? null, 'page_number' => $e['page_number'] ?? 1, 'is_visible' => $e['is_visible'] ?? 1,
            ], $source['elements']),
        ], $userId);
    }

    /** Duplicates BOTH languages of a pair together as one new, independent pair (2026-08-25,
     *  explicit follow-up: the unified list's Duplicate button is now a single, per-pair action --
     *  see EmploymentCertificateTemplateModel::listPaired()'s own comment for the redesign this
     *  belongs to). Every language currently active for the source pair is cloned into the SAME new
     *  pair_key, so the copy stays linked as one pair too instead of becoming two separate,
     *  unlinked templates. */
    public function duplicatePair(int $compId, string $pairKey, int $userId): array {
        $stmt = $this->db->prepare("SELECT id FROM `employment_certificate_templates`
            WHERE comp_id = :comp_id AND pair_key = :pair_key AND status = 'active'");
        $stmt->execute([':comp_id' => $compId, ':pair_key' => $pairKey]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (empty($ids)) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $newPairKey = bin2hex(random_bytes(16));
        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            $newIds = [];
            foreach ($ids as $id) {
                $source = $this->get($compId, $id);
                if (!$source) { continue; }
                $result = $this->save($compId, [
                    'language' => $source['language'], 'pair_key' => $newPairKey,
                    'template_name' => $source['template_name'] . ' (Copy)',
                    'page_size' => $source['page_size'], 'orientation' => $source['orientation'],
                    'margin_mm' => $source['margin_mm'], 'logo_path' => $source['logo_path'],
                    'elements' => array_map(fn($e) => [
                        'element_type' => $e['element_type'], 'field_key' => $e['field_key'], 'image_asset_id' => $e['image_asset_id'], 'content' => $e['content'],
                        'pos_x_pct' => $e['pos_x_pct'], 'pos_y_pct' => $e['pos_y_pct'], 'width_pct' => $e['width_pct'], 'height_pct' => $e['height_pct'],
                        'font_size' => $e['font_size'], 'font_family' => $e['font_family'], 'font_color' => $e['font_color'],
                        'text_align' => $e['text_align'], 'font_weight' => $e['font_weight'], 'font_style' => $e['font_style'], 'text_decoration' => $e['text_decoration'],
                        'group_key' => $e['group_key'] ?? null, 'page_number' => $e['page_number'] ?? 1, 'is_visible' => $e['is_visible'] ?? 1,
                    ], $source['elements']),
                ], $userId);
                if (!$result['status']) {
                    if ($own) { $this->db->rollBack(); }
                    return $result;
                }
                $newIds[] = $result['template_id'];
            }
            if ($own) { $this->db->commit(); }
            return ['status' => true, 'message' => 'Duplicated successfully.', 'pair_key' => $newPairKey, 'template_ids' => $newIds];
        } catch (PDOException $e) {
            if ($own) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
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

    /** 2026-08-26, explicit request: "ให้มี Draft Mode และ Public Mode...ในหน้า List สามารถเปิด Draft
     *  หรือ Public ได้จากหน้านั้นเลย" -- direct port of PayslipTemplateModel::setPublishStatus() (see
     *  that method's own docblock: never touched by save()/autosave, only this dedicated action). */
    public function setPublishStatus(int $compId, int $id, string $status, int $userId): array {
        if (!in_array($status, ['draft', 'public'], true)) {
            return ['status' => false, 'message' => 'Invalid publish status.'];
        }
        $stmt = $this->db->prepare("SELECT id FROM `employment_certificate_templates` WHERE id = :id AND comp_id = :comp_id AND status = 'active'");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        if (!$stmt->fetch()) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $this->db->prepare("UPDATE `employment_certificate_templates` SET publish_status = :publish_status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':publish_status' => $status, ':updated_by' => $userId, ':id' => $id]);
        return ['status' => true, 'message' => 'Updated successfully.', 'publish_status' => $status];
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
            // 2026-08-25, explicit follow-up: "ขอเพิ่ม Template สัก 5 template ให้แต่ละ template มีความ
            // แตกต่างกัน" -- 2 more, genuinely distinct from the first 3 and each other (not just
            // shuffled positions): 'formal' uses a maroon accent + dash-line dividers to read as an
            // official/certificate-style document, 'elegant' uses the brand orange accent + a smaller
            // top-left logo + an italic closing disclaimer line + a right-aligned-only signature
            // block (vs. modern's split date-left/signature-right row).
            ['code' => 'formal', 'name_th' => 'ทางการ (เส้นคั่นตกแต่ง)', 'name_en' => 'Formal (decorative dividers)'],
            ['code' => 'elegant', 'name_th' => 'หรูหรา (สีส้มแบรนด์)', 'name_en' => 'Elegant (brand accent)'],
        ];
    }

    private function presetElements(string $preset, string $language): array {
        $title = $language === 'en' ? 'EMPLOYMENT CERTIFICATE' : 'หนังสือรับรองการทำงาน';
        $body = $language === 'en'
            ? 'This is to certify that {{employee_name}} (Employee No. {{employee_no}}), holding the position of {{position}} in the {{department}} department, has been employed with the company since {{employment_date}} to the present. Current employment status: {{employment_status}}.'
            : 'ขอรับรองว่า {{employee_name}} รหัสพนักงาน {{employee_no}} ดำรงตำแหน่ง {{position}} สังกัดแผนก {{department}} ได้เข้าทำงานกับบริษัทตั้งแต่วันที่ {{employment_date}} จนถึงปัจจุบัน สถานะการจ้างงานปัจจุบัน {{employment_status}}';
        $issuedOn = $language === 'en' ? 'Issued on {{issue_date}}' : 'ออกให้ ณ วันที่ {{issue_date}}';
        $taxIdLine = $language === 'en' ? 'Tax ID: {{company_tax_id}}' : 'เลขประจำตัวผู้เสียภาษี {{company_tax_id}}';
        $disclaimerLine = $language === 'en'
            ? 'This document is issued for the purpose requested by the employee.'
            : 'เอกสารนี้ออกให้เพื่อใช้ตามความประสงค์ของผู้ร้องขอ';
        $divider = str_repeat('─', 30);
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
            // 'formal' -- maroon accent + dash-line dividers standing in for a real border (elements
            // can only be text/image, no shape/box primitive exists, so a row of dash characters is
            // the lightweight way to suggest a rule line) -- centered throughout, tax ID line under
            // the address (a real formality on official Thai employment certificates), signature
            // block at the very bottom flanked by its own divider.
            case 'formal':
                return [
                    $base + ['element_type' => 'text', 'content' => '{{company_name}}', 'pos_x_pct' => 10, 'pos_y_pct' => 6, 'width_pct' => 80, 'height_pct' => 7, 'font_size' => 18, 'text_align' => 'center', 'font_weight' => 'bold', 'font_color' => '#7a1f1f'],
                    $base + ['element_type' => 'text', 'content' => '{{company_address}}', 'pos_x_pct' => 10, 'pos_y_pct' => 13, 'width_pct' => 80, 'height_pct' => 5, 'font_size' => 11, 'text_align' => 'center'],
                    $base + ['element_type' => 'text', 'content' => $taxIdLine, 'pos_x_pct' => 10, 'pos_y_pct' => 18, 'width_pct' => 80, 'height_pct' => 5, 'font_size' => 10, 'text_align' => 'center'],
                    $base + ['element_type' => 'text', 'content' => $divider, 'pos_x_pct' => 10, 'pos_y_pct' => 24, 'width_pct' => 80, 'height_pct' => 4, 'font_size' => 14, 'text_align' => 'center', 'font_color' => '#7a1f1f'],
                    $base + ['element_type' => 'text', 'content' => $title, 'pos_x_pct' => 10, 'pos_y_pct' => 29, 'width_pct' => 80, 'height_pct' => 10, 'font_size' => 22, 'text_align' => 'center', 'font_weight' => 'bold', 'font_color' => '#7a1f1f'],
                    $base + ['element_type' => 'text', 'content' => $body, 'pos_x_pct' => 14, 'pos_y_pct' => 42, 'width_pct' => 72, 'height_pct' => 30, 'font_size' => 15, 'text_align' => 'center'],
                    $base + ['element_type' => 'text', 'content' => $divider, 'pos_x_pct' => 10, 'pos_y_pct' => 76, 'width_pct' => 80, 'height_pct' => 4, 'font_size' => 14, 'text_align' => 'center', 'font_color' => '#7a1f1f'],
                    $base + ['element_type' => 'text', 'content' => $issuedOn, 'pos_x_pct' => 10, 'pos_y_pct' => 81, 'width_pct' => 80, 'height_pct' => 5, 'font_size' => 12, 'text_align' => 'center'],
                    $base + ['element_type' => 'text', 'content' => '{{company_signatory}}', 'pos_x_pct' => 10, 'pos_y_pct' => 88, 'width_pct' => 80, 'height_pct' => 6, 'font_size' => 13, 'text_align' => 'center', 'font_weight' => 'bold'],
                ];
            // 'elegant' -- smaller top-left logo than 'modern' (14% vs 18% wide), brand-orange (#FF9900)
            // accent on company name/title instead of black, an italicized disclaimer line (real
            // wording used on Thai employment certificates, not filler), and a signature block that's
            // RIGHT-aligned only at the bottom -- distinct from 'modern''s split date-left/signature-
            // right row on the same line.
            case 'elegant':
                return [
                    $base + ['element_type' => 'image', 'field_key' => 'company_logo', 'content' => null, 'pos_x_pct' => 6, 'pos_y_pct' => 5, 'width_pct' => 14, 'height_pct' => 10, 'font_size' => 14, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => '{{company_name}}', 'pos_x_pct' => 24, 'pos_y_pct' => 6, 'width_pct' => 70, 'height_pct' => 6, 'font_size' => 16, 'text_align' => 'left', 'font_weight' => 'bold', 'font_color' => '#FF9900'],
                    $base + ['element_type' => 'text', 'content' => $title, 'pos_x_pct' => 24, 'pos_y_pct' => 13, 'width_pct' => 70, 'height_pct' => 7, 'font_size' => 19, 'text_align' => 'left', 'font_weight' => 'bold', 'font_color' => '#FF9900'],
                    $base + ['element_type' => 'text', 'content' => $body, 'pos_x_pct' => 8, 'pos_y_pct' => 26, 'width_pct' => 84, 'height_pct' => 34, 'font_size' => 14, 'text_align' => 'left'],
                    $base + ['element_type' => 'text', 'content' => $disclaimerLine, 'pos_x_pct' => 8, 'pos_y_pct' => 62, 'width_pct' => 84, 'height_pct' => 6, 'font_size' => 12, 'text_align' => 'left', 'font_style' => 'italic'],
                    $base + ['element_type' => 'text', 'content' => $issuedOn, 'pos_x_pct' => 54, 'pos_y_pct' => 82, 'width_pct' => 38, 'height_pct' => 5, 'font_size' => 12, 'text_align' => 'right'],
                    $base + ['element_type' => 'text', 'content' => '{{company_signatory}}', 'pos_x_pct' => 54, 'pos_y_pct' => 89, 'width_pct' => 38, 'height_pct' => 6, 'font_size' => 13, 'text_align' => 'right', 'font_weight' => 'bold'],
                ];
            case 'blank':
            default:
                return [];
        }
    }

    /** Creates a new template pre-populated from one of presetOptions()'s layouts (or empty for
     *  'blank'). Just a convenience wrapper around save() -- the admin can freely drag/edit/delete
     *  every element afterward, this only seeds the starting point. */
    public function createFromPreset(int $compId, string $language, string $preset, string $templateName, int $userId, ?string $pairKey = null): array {
        if (!in_array($preset, self::PRESETS, true)) {
            return ['status' => false, 'message' => 'Invalid preset.'];
        }
        $data = [
            'language' => $language, 'template_name' => $templateName,
            'elements' => $this->presetElements($preset, $language),
        ];
        // 2026-08-25, explicit request: creating the SECOND language of a pair manually (from the
        // gallery, "ทำเอง" instead of "Generate Auto") still needs to link to the same pair_key as
        // its counterpart, not start a brand-new one -- see the unified-list migration's comment.
        if ($pairKey !== null) {
            $data['pair_key'] = $pairKey;
        }
        return $this->save($compId, $data, $userId);
    }

    /** Public read-only entry point onto presetElements() for the "New Template" modal's own Preview
     *  button (2026-08-24, explicit follow-up: List+Modal restructure -- "ปุ่มที่ดึง template ที่ระบบมีให้มา
     *  ใช้...สามารถกด Preview ดูก่อนที่จะดึงมาได้", resolved via AskUserQuestion to be the SAME preset
     *  picker already in this modal, just with a Preview action added per card -- no separate import
     *  mechanism). Throws instead of returning a status array since this only ever gets called with a
     *  code already constrained to presetOptions()'s own <select>/card values -- an invalid one here
     *  means a tampered request, not a normal validation failure a user should see a friendly message for. */
    public function presetPreviewElements(string $preset, string $language): array {
        if (!in_array($preset, self::PRESETS, true)) {
            throw new InvalidArgumentException('Invalid preset.');
        }
        return $this->presetElements($preset, $language);
    }

    /* ==================== Reusable uploaded-image library (2026-08-24, explicit request:
       "เพิ่มให้ Upload รูปภาพมาใช้งานเองได้ โดย Upload แล้วดึงกลับมาใช้ซ้ำได้ใน Template ต่อไป") --
       company-wide, not tied to one template/language -- upload once, place on any template via
       an 'image' element's `image_asset_id`. ==================== */

    public function listImages(int $compId): array {
        $stmt = $this->db->prepare("SELECT id, file_path, file_size, thumbnail_path, original_filename, uploaded_at
            FROM `employment_certificate_images` WHERE comp_id = :comp_id ORDER BY uploaded_at DESC");
        $stmt->execute([':comp_id' => $compId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addImage(int $compId, string $filePath, ?string $originalFilename, int $userId, ?int $fileSize = null, ?string $thumbnailPath = null): array {
        if (!self::isValidImageAssetPath($filePath, $compId)) {
            return ['status' => false, 'message' => 'Invalid file path.'];
        }
        $stmt = $this->db->prepare("INSERT INTO `employment_certificate_images` (comp_id, file_path, file_size, thumbnail_path, original_filename, uploaded_by)
            VALUES (:comp_id, :file_path, :file_size, :thumbnail_path, :original_filename, :uploaded_by)");
        $stmt->execute([':comp_id' => $compId, ':file_path' => $filePath, ':file_size' => $fileSize, ':thumbnail_path' => $thumbnailPath, ':original_filename' => $originalFilename, ':uploaded_by' => $userId]);
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
