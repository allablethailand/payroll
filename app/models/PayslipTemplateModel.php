<?php
declare(strict_types=1);

require_once __DIR__ . '/TemplateDesignerModelTrait.php';
require_once __DIR__ . '/AuditLogModel.php';

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
 * 2026-08-25, same-day follow-up: "ในหน้าตั้งค่า Slip การทำ 2 ภาษาอยากให้เป็นเหมือนหน้าของเอกสาร และ
 * รูปแบบการทำเหมือนกัน" -- the original decision documented below (kept for context) to NOT fork into
 * a `language`/`pair_key` pair like Employment Certificate Template was explicitly REVERSED by this
 * follow-up. Confirmed via AskUserQuestion: `language_mode`'s 'both' option is dropped entirely (not
 * kept as a 3rd option alongside a real th/en pair) -- every template row is now exactly ONE
 * language, exactly like `employment_certificate_templates`. The one real production template at
 * migration time (language_mode='both') keeps all of its content and became the TH row of a new
 * pair (see the migration's own comment in database/payroll.sql) -- an EN version can be generated
 * afterward via "Generate Auto" or created manually, same as any other pair missing a language.
 * `is_default` is now enforced per (comp_id, language) instead of company-wide, matching
 * EmploymentCertificateTemplateModel::setDefault()'s own per-language enforcement exactly.
 *
 * [ORIGINAL 2026-08-25 rebuild note, now superseded by the above -- kept so the "why" of the OTHER
 * still-true differences from Employment Certificate Template is not lost] Deliberately KEPT from
 * the old design, unlike Employment Certificate Template (which has neither concept): `is_default`
 * (genuinely controls which template PaySlipReport::generate() picks), `header_text_th/en`/
 * `footer_text_th/en`, `status` (active/inactive/deleted -- an explicit enable/disable toggle
 * layered ON TOP OF this project's usual status+deleted_at soft-delete convention, since Employment
 * Certificate Template's `status` enum has no 'inactive' at all, only active/deleted), and
 * `country_code` (derived from companies.registered_country at save time, unchanged from before).
 *
 * listPaired()/getPairByKey()/generateOtherLanguage()/duplicatePair() below are direct ports of
 * EmploymentCertificateTemplateModel's own methods of the same name -- see that class for the
 * original docblocks/reasoning, not repeated here except where Payslip's own kept fields
 * (is_default/status/header-footer) require a genuine difference.
 */
class PayslipTemplateModel {
    use TemplateDesignerModelTrait;

    private PDO $db;
    private AuditLogModel $auditLog;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
        $this->auditLog = new AuditLogModel($this->db);
    }

    /* ==================== TemplateDesignerModelTrait's own abstract table-name hooks ==================== */
    protected function elementsTableName(): string { return 'payslip_template_elements'; }
    protected function assignmentsTableName(): string { return 'payslip_template_assignments'; }
    protected function imagesTableName(): string { return 'payslip_images'; }

    private const ELEMENT_TYPES = ['text', 'image', 'shape', 'table'];
    private const SHAPE_TYPES = ['rectangle', 'ellipse', 'line'];
    private const TEXT_ALIGNS = ['left', 'center', 'right'];
    private const FONT_WEIGHTS = ['normal', 'bold'];
    private const FONT_STYLES = ['normal', 'italic'];
    private const TEXT_DECORATIONS = ['none', 'underline'];
    private const FONT_FAMILIES = ['th_sarabun_new', 'dejavu_sans', 'dejavu_sans_mono', 'dejavu_serif', 'helvetica', 'times_new_roman', 'courier'];
    // 2026-08-26, explicit request: "ตรง Page Setup ให้เพิ่ม A3 A5 และอื่นๆ เหมือนใน Word" -- same
    // practical Word-style paper-size set (minus envelopes, irrelevant for a payslip/certificate)
    // added identically to Employment Certificate Template's own PAGE_SIZES, see this class's own
    // renderer counterpart (PayslipTemplateRenderer::PAGE_SIZES_MM) for the actual mm dimensions.
    private const PAGE_SIZES = ['A3', 'A4', 'A5', 'B4', 'B5', 'Letter', 'Legal', 'Tabloid', 'Executive', 'Statement'];
    private const ORIENTATIONS = ['portrait', 'landscape'];
    private const LANGUAGES = ['th', 'en'];
    private const MAX_PAGE_NUMBER = 20;
    public const PRESETS = ['blank', 'classic', 'modern', 'minimal'];
    // 2026-08-25, explicit request: "สามารถ Assign ตั้งค่าให้พนักงาน เป็นรายแผนก รายทีม หรือรายคน หรือ
    // ใช้งานร่วมกันทั้งหมดก็ได้" -- mirrors holidays/holiday_assignments' own polymorphic scope
    // pattern (see SetupRulesModel), deliberately WITHOUT an include/exclude mode -- see the
    // migration's own comment for why. Priority when more than one scope type matches the same
    // employee (highest number wins): employee is the most specific, department the least.
    private const SCOPE_TYPES = ['department', 'team', 'employee'];
    private const SCOPE_PRIORITY = ['employee' => 3, 'team' => 2, 'department' => 1];

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
    // getElements() moved to TemplateDesignerModelTrait (T064) -- table name supplied by
    // elementsTableName() above.

    /** @return array<int,array> every non-deleted template for this company+language, most recent first. */
    public function list(int $compId, string $language): array {
        if (!in_array($language, self::LANGUAGES, true)) {
            return [];
        }
        $stmt = $this->db->prepare("SELECT t.*,
                (SELECT COUNT(*) FROM `payslip_template_elements` e WHERE e.template_id = t.id) AS element_count
            FROM `payslip_templates` t
            WHERE t.comp_id = :comp_id AND t.language = :language AND t.deleted_at IS NULL
            ORDER BY t.is_default DESC, t.updated_at DESC, t.id DESC");
        $stmt->execute([':comp_id' => $compId, ':language' => $language]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * 2026-08-25, explicit request: "การทำ 2 ภาษาอยากให้เป็นเหมือนหน้าของเอกสาร และรูปแบบการทำเหมือนกัน"
     * -- direct port of EmploymentCertificateTemplateModel::listPaired() (see that method's own
     * docblock for the full reasoning). One row per pair_key, TH preferred as the display name/page
     * setup when both languages exist.
     * @return array<int,array{pair_key:string, template_name:string, page_size:string, orientation:string,
     *   th:?array, en:?array}>
     */
    public function listPaired(int $compId): array {
        $stmt = $this->db->prepare("SELECT id, language, pair_key, template_name, page_size, orientation, is_default, status, publish_status, auto_save, updated_at
            FROM `payslip_templates`
            WHERE comp_id = :comp_id AND deleted_at IS NULL
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
            $langInfo = ['id' => (int)$row['id'], 'is_default' => (bool)$row['is_default'], 'status' => $row['status'],
                'publish_status' => $row['publish_status'], 'auto_save' => (bool)$row['auto_save'], 'updated_at' => $row['updated_at']];
            if ($row['language'] === 'th') {
                $pairs[$key]['th'] = $langInfo;
                $pairs[$key]['template_name'] = $row['template_name'];
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

    /** Same per-pair shape as one row of listPaired() above, looked up by a single pair_key -- backs
     *  the standalone editor page's route (`payslip-template/edit/{key}`). Returns null if the
     *  company has no non-deleted template (either language) under this pair_key. */
    public function getPairByKey(int $compId, string $pairKey): ?array {
        $stmt = $this->db->prepare("SELECT id, language, pair_key, template_name, page_size, orientation, is_default, status, publish_status, auto_save, updated_at
            FROM `payslip_templates`
            WHERE comp_id = :comp_id AND pair_key = :pair_key AND deleted_at IS NULL");
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

    /** "Generate other language, Auto" -- direct port of
     *  EmploymentCertificateTemplateModel::generateOtherLanguage() (see that method's own docblock:
     *  clones the source's whole element structure verbatim into a new row for the other language,
     *  including free-text content, which stays in the source's language until the admin edits it).
     *  Also carries header/footer text + is_default's OWN language-scoped meaning does NOT carry
     *  over (the new row starts non-default in its language, same "duplicate starts non-default"
     *  reasoning as duplicate()/duplicatePair() below). */
    public function generateOtherLanguage(int $compId, int $sourceTemplateId, int $userId): array {
        $source = $this->get($compId, $sourceTemplateId);
        if (!$source) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $targetLanguage = $source['language'] === 'th' ? 'en' : 'th';
        $stmtExisting = $this->db->prepare("SELECT id FROM `payslip_templates`
            WHERE comp_id = :comp_id AND pair_key = :pair_key AND language = :language AND deleted_at IS NULL");
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
            'margin_mm' => $source['margin_mm'], 'logo_path' => $source['logo_path'] ?? null,
            'header_text_th' => $source['header_text_th'], 'header_text_en' => $source['header_text_en'],
            'footer_text_th' => $source['footer_text_th'], 'footer_text_en' => $source['footer_text_en'],
            'status' => $source['status'], 'auto_save' => $source['auto_save'] ?? false, 'elements' => $clonedElements,
        ], $userId);
    }

    public function get(int $compId, int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `payslip_templates` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$template) {
            return null;
        }
        $template['elements'] = $this->getElements($id);
        $template['assignments'] = $this->getAssignments($id);
        return $template;
    }

    /* ==================== Assignment (department/team/employee scoping) ====================
       2026-08-25, explicit request: "สามารถ Assign ตั้งค่าให้พนักงาน เป็นรายแผนก รายทีม หรือรายคน
       หรือใช้งานร่วมกันทั้งหมดก็ได้". A template with ZERO assignment rows is unscoped (applies as
       the general company default, same as is_default's existing meaning, unchanged). A template
       with ANY assignment rows only applies to the union of those department/team/employee scopes
       -- see resolveTemplateForEmployee() below for the actual priority resolution. */

    // validateScopeRef()/getAssignments()/assignableOptions()/scopeLabel() moved to
    // TemplateDesignerModelTrait (T064) -- zero template-specific table reference, genuinely
    // identical to Employment Certificate Template's own versions from the start.

    /** Finds another ACTIVE template of the SAME LANGUAGE (excluding $excludeTemplateId, e.g. the one
     *  currently being saved) that already claims this exact scope -- used to reject genuinely
     *  ambiguous configuration at save time instead of silently picking a winner by recency. Scoped
     *  per language (2026-08-25 follow-up, "รูปแบบการทำเหมือนกัน" -- mirrors
     *  EmploymentCertificateTemplateModel::findConflictingAssignment() exactly): a department
     *  assigned to the Thai design and the SAME department assigned to the English design of a
     *  DIFFERENT template are not a conflict, since resolveTemplateForEmployee() resolves th/en
     *  completely independently. Only ACTIVE templates count as a real conflict (an inactive one's
     *  assignments don't apply to anyone right now, see resolveTemplateForEmployee()'s own
     *  status='active' filter). */
    private function findConflictingAssignment(string $scopeType, int $scopeId, int $compId, string $language, ?int $excludeTemplateId): ?array {
        $sql = "SELECT t.id, t.template_name FROM `payslip_template_assignments` a
            JOIN `payslip_templates` t ON t.id = a.template_id
            WHERE t.comp_id = :comp_id AND t.language = :language AND t.status = 'active' AND t.deleted_at IS NULL
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
     * 2026-08-25, explicit request: "ถ้ามีการตั้งค่าซ้ำต้องแจ้ง Error ว่ามีการ Assign ซ้ำใคร" -- a
     * department/team/employee can only be actively assigned to ONE template of a given language at a
     * time; assigning the same scope to a second ACTIVE template of the same language is a hard
     * validation error naming exactly who/what conflicts and which template already claims them.
     */
    private function validateAssignments(array $raw, int $compId, string $language, ?int $excludeTemplateId, bool $isActive): array {
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
            $key = $scopeType . ':' . $scopeId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            // A template being saved as inactive can't conflict with anything -- it won't apply to
            // anyone either way (matches resolveTemplateForEmployee()'s own active-only filter).
            if ($isActive) {
                $conflict = $this->findConflictingAssignment($scopeType, $scopeId, $compId, $language, $excludeTemplateId);
                if ($conflict !== null) {
                    $label = $this->scopeLabel($scopeType, $scopeId);
                    return ['error' => "\"{$label}\" is already assigned to another active template (\"{$conflict['template_name']}\") for this language. Remove it there first, or deactivate that template."];
                }
            }
            $cleaned[] = ['scope_type' => $scopeType, 'scope_id' => $scopeId];
        }
        return ['assignments' => $cleaned];
    }

    /**
     * Resolves which template applies to a specific employee for a specific language -- employee-
     * level assignment wins over team, which wins over department, which wins over the company's
     * is_default (unscoped) template for that language (same "most specific wins" convention as
     * SetupRulesModel::resolveHolidaysForEmployee()). Used by PaySlipReport::generate() in place of
     * the old flat getDefaultForCompany()-only lookup. Same-scope-type conflicts (two ACTIVE
     * templates of the same language both claiming the exact same department/team/employee) can no
     * longer actually happen -- save() now rejects that outright, see validateAssignments()'s own
     * comment -- so there is nothing left to disambiguate here.
     */
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
        // แล้วค่อย Public" -- a draft template is a work-in-progress and must never be resolved for
        // REAL generation, so both the assignment lookup here and getDefault() below require
        // publish_status='public' on top of the existing status='active' gate.
        $stmt = $this->db->prepare("SELECT t.id, t.updated_at, a.scope_type
            FROM `payslip_template_assignments` a
            JOIN `payslip_templates` t ON t.id = a.template_id
            WHERE t.comp_id = :comp_id AND t.language = :language AND t.status = 'active' AND t.publish_status = 'public' AND t.deleted_at IS NULL
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

    /** Used by PaySlipReport to resolve which template (if any) to render with for a given language.
     *  Single-default-per-(comp_id,language) -- same pattern as
     *  EmploymentCertificateTemplateModel::getDefault(), including the fallback to the most-recently-
     *  updated active template when none is explicitly flagged is_default (the "very first template
     *  auto-becomes default" invariant in save() normally means one always is, but this fallback is
     *  the same extra insurance ECT's own version has). */
    public function getDefault(int $compId, string $language): ?array {
        if (!in_array($language, self::LANGUAGES, true)) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT id FROM `payslip_templates`
            WHERE comp_id = :comp_id AND language = :language AND status = 'active' AND publish_status = 'public' AND deleted_at IS NULL
            ORDER BY is_default DESC, updated_at DESC, id DESC LIMIT 1");
        $stmt->execute([':comp_id' => $compId, ':language' => $language]);
        $id = $stmt->fetchColumn();
        return $id !== false ? $this->get($compId, (int)$id) : null;
    }

    // validateElements() moved to TemplateDesignerModelTrait (T064) -- byte-identical logic,
    // correctly delegates to $this->fieldTypeOptions() (kept per-class below, queries this class's
    // own master_payslip_field_types table).

    /**
     * Creates a NEW template (id omitted) or replaces an EXISTING one's whole element set in place
     * (id given). @param array $data {id?:int, language:string, pair_key?:string, template_name:string,
     *   header_text_th?/en?/footer_text_th?/en?:string, is_default?:bool, status?:string,
     *   page_size?:string, orientation?:string, margin_mm?:float, logo_path?:?string, elements:array}
     */
    public function save(int $compId, array $data, int $userId, ?string $ip = null, ?string $userAgent = null): array {
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
        $marginMm = array_key_exists('margin_mm', $data) ? (float)$data['margin_mm'] : 15.0;
        $marginMm = max(0.0, min(50.0, $marginMm));
        $status = in_array($data['status'] ?? '', ['active', 'inactive'], true) ? $data['status'] : 'active';
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        // 2026-08-26, explicit request: "เพิ่มให้ติ๊กได้ว่าต้องการให้ Auto Save" -- a plain per-template
        // preference the editor's own JS reads to decide whether to silently save on change instead of
        // waiting for the Save button. `publish_status` (draft/public) is deliberately NOT accepted
        // here -- it's set to 'draft' on INSERT below and otherwise only ever changed via the
        // dedicated setPublishStatus() action (list-page toggle or the editor's own Publish switch),
        // never as a side effect of an ordinary/auto- save, so autosave can never silently publish an
        // unfinished design.
        $autoSave = !empty($data['auto_save']) ? 1 : 0;
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
        $idForAssignCheck = (!empty($data['id']) && is_numeric($data['id'])) ? (int)$data['id'] : null;
        $assignResult = $this->validateAssignments(is_array($data['assignments'] ?? null) ? $data['assignments'] : [], $compId, $language, $idForAssignCheck, $status === 'active');
        if (isset($assignResult['error'])) {
            return ['status' => false, 'message' => $assignResult['error']];
        }
        $assignments = $assignResult['assignments'];
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
            $rowLanguage = $language;
            if ($id !== null) {
                // Platform Hardening Phase 6 (batch 5): SELECT * (was `id, language` -- just enough
                // for this method's own pre-existing logic) so the full row is available to
                // AuditLogModel::record() as the "old" side of the header-row diff below. Only the
                // HEADER row's own scalar columns are diffed here, same "don't diff child-table
                // replace-semantics" precedent this pilot already established for
                // AttendanceDeductionRuleModel's brackets/ApprovalWorkflowModel's steps -- the
                // elements/assignments arrays (payslip_template_elements/_assignments, both
                // DELETE+INSERT every save a few lines down) are NOT diffed.
                $stmtCheck = $this->db->prepare("SELECT * FROM `payslip_templates` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
                $existingRow = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                if (!$existingRow) {
                    if ($own) { $this->db->rollBack(); }
                    return ['status' => false, 'message' => 'Record not found.'];
                }
                // language/pair_key are immutable after creation (same as
                // EmploymentCertificateTemplateModel::save()'s own UPDATE branch, which never touches
                // them either) -- always scope the "clear other defaults" query below to the row's
                // OWN actual language, not whatever the caller happened to pass.
                $rowLanguage = (string)$existingRow['language'];
                $logoSql = $logoPath !== null ? ", logo_path = :logo_path" : "";
                $stmt = $this->db->prepare("UPDATE `payslip_templates`
                    SET template_name = :template_name, is_default = :is_default, header_text_th = :header_th, header_text_en = :header_en,
                        footer_text_th = :footer_th, footer_text_en = :footer_en, status = :status, auto_save = :auto_save,
                        page_size = :page_size, orientation = :orientation, margin_mm = :margin_mm{$logoSql},
                        updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
                $params = [
                    ':template_name' => $templateName, ':is_default' => $isDefault,
                    ':header_th' => $headerTh !== '' ? $headerTh : null, ':header_en' => $headerEn !== '' ? $headerEn : null,
                    ':footer_th' => $footerTh !== '' ? $footerTh : null, ':footer_en' => $footerEn !== '' ? $footerEn : null,
                    ':status' => $status, ':auto_save' => $autoSave,
                    ':page_size' => $pageSize, ':orientation' => $orientation, ':margin_mm' => $marginMm,
                    ':updated_by' => $userId, ':id' => $id,
                ];
                if ($logoPath !== null) {
                    $params[':logo_path'] = $logoPath !== '' ? $logoPath : null;
                }
                $stmt->execute($params);
                $templateId = $id;
                $stmtNewHeaderRow = $this->db->prepare("SELECT * FROM `payslip_templates` WHERE id = :id");
                $stmtNewHeaderRow->execute([':id' => $templateId]);
                $newHeaderRow = $stmtNewHeaderRow->fetch(PDO::FETCH_ASSOC) ?: [];
                $this->auditLog->record($compId, 'payslip_templates', $templateId, 'update', $existingRow, $newHeaderRow, $userId, 'web', $ip, $userAgent);
            } else {
                // 2026-08-25 follow-up, "รูปแบบการทำเหมือนกัน" -- every template gets a pair_key from
                // creation onward, either the caller's own (generateOtherLanguage()/duplicatePair()
                // pass the SOURCE's pair_key, so the new row links to it) or a fresh one for a
                // genuinely brand-new, not-yet-paired template. Mirrors
                // EmploymentCertificateTemplateModel::save()'s own INSERT branch exactly.
                $pairKey = !empty($data['pair_key']) ? substr((string)$data['pair_key'], 0, 64) : bin2hex(random_bytes(16));
                $stmt = $this->db->prepare("INSERT INTO `payslip_templates`
                    (comp_id, country_code, template_name, language, pair_key, is_default, logo_path,
                     header_text_th, header_text_en, footer_text_th, footer_text_en,
                     page_size, orientation, margin_mm, status, publish_status, auto_save, created_by)
                    VALUES (:comp_id, :country_code, :template_name, :language, :pair_key, :is_default, :logo_path,
                     :header_th, :header_en, :footer_th, :footer_en,
                     :page_size, :orientation, :margin_mm, :status, 'draft', :auto_save, :created_by)");
                $stmt->execute([
                    ':comp_id' => $compId, ':country_code' => $countryCode, ':template_name' => $templateName,
                    ':language' => $language, ':pair_key' => $pairKey, ':is_default' => $isDefault,
                    ':logo_path' => ($logoPath !== null && $logoPath !== '') ? $logoPath : null,
                    ':header_th' => $headerTh !== '' ? $headerTh : null, ':header_en' => $headerEn !== '' ? $headerEn : null,
                    ':footer_th' => $footerTh !== '' ? $footerTh : null, ':footer_en' => $footerEn !== '' ? $footerEn : null,
                    ':page_size' => $pageSize, ':orientation' => $orientation, ':margin_mm' => $marginMm,
                    ':status' => $status, ':auto_save' => $autoSave, ':created_by' => $userId,
                ]);
                $templateId = (int)$this->db->lastInsertId();
                // The very first template ever saved for this company+language becomes the default
                // automatically (there would otherwise be no default at all until the admin sets
                // one) -- every later new template stays non-default until chosen.
                $stmtCount = $this->db->prepare("SELECT COUNT(*) FROM `payslip_templates` WHERE comp_id = :comp_id AND language = :language AND deleted_at IS NULL");
                $stmtCount->execute([':comp_id' => $compId, ':language' => $language]);
                if ((int)$stmtCount->fetchColumn() === 1) {
                    $this->db->prepare("UPDATE `payslip_templates` SET is_default = 1 WHERE id = :id")->execute([':id' => $templateId]);
                    $isDefault = 1;
                }
            }

            if ($isDefault) {
                $this->db->prepare("UPDATE `payslip_templates` SET is_default = 0 WHERE comp_id = :comp_id AND language = :language AND id != :id AND deleted_at IS NULL")
                    ->execute([':comp_id' => $compId, ':language' => $rowLanguage, ':id' => $templateId]);
            }

            $this->db->prepare("DELETE FROM `payslip_template_elements` WHERE template_id = :id")->execute([':id' => $templateId]);
            $insEl = $this->db->prepare("INSERT INTO `payslip_template_elements`
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

            $this->db->prepare("DELETE FROM `payslip_template_assignments` WHERE template_id = :id")->execute([':id' => $templateId]);
            $insAssign = $this->db->prepare("INSERT INTO `payslip_template_assignments` (template_id, scope_type, scope_id) VALUES (:template_id, :scope_type, :scope_id)");
            foreach ($assignments as $a) {
                $insAssign->execute([':template_id' => $templateId, ':scope_type' => $a['scope_type'], ':scope_id' => $a['scope_id']]);
            }

            if ($own) {
                $this->db->commit();
            }
            // 2026-08-25 follow-up -- the standalone editor page is addressed by pair_key in the URL
            // (see EmploymentCertificateTemplateController::editPage()'s own precedent), so the
            // client needs it back without a second round trip. Cheap to look up unconditionally --
            // covers both the INSERT branch (already known) and UPDATE (not computed above at all).
            $stmtPairKey = $this->db->prepare("SELECT pair_key FROM `payslip_templates` WHERE id = :id");
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
        // Assignments are deliberately NOT carried over -- a duplicate starts unscoped (unassigned),
        // same reasoning as 'is_default' => false below: having two active templates simultaneously
        // claim the same department/team/employee would be a confusing default, the admin should
        // assign the copy explicitly if that's really what they want. Also NOT carried over: pair_key
        // (starts a brand-new, unlinked pair -- same as EmploymentCertificateTemplateModel::duplicate(),
        // see duplicatePair() below for the whole-pair equivalent).
        return $this->save($compId, [
            'language' => $source['language'], 'template_name' => $source['template_name'] . ' (Copy)',
            'is_default' => false, 'status' => $source['status'],
            'header_text_th' => $source['header_text_th'], 'header_text_en' => $source['header_text_en'],
            'footer_text_th' => $source['footer_text_th'], 'footer_text_en' => $source['footer_text_en'],
            'page_size' => $source['page_size'], 'orientation' => $source['orientation'], 'margin_mm' => $source['margin_mm'],
            'logo_path' => $source['logo_path'],
            'elements' => array_map(fn($e) => [
                'element_type' => $e['element_type'], 'field_key' => $e['field_key'], 'image_asset_id' => $e['image_asset_id'], 'content' => $e['content'],
                'pos_x_pct' => $e['pos_x_pct'], 'pos_y_pct' => $e['pos_y_pct'], 'width_pct' => $e['width_pct'], 'height_pct' => $e['height_pct'],
                'font_size' => $e['font_size'], 'font_family' => $e['font_family'], 'font_color' => $e['font_color'],
                'text_align' => $e['text_align'], 'font_weight' => $e['font_weight'], 'font_style' => $e['font_style'], 'text_decoration' => $e['text_decoration'],
                'group_key' => $e['group_key'] ?? null, 'page_number' => $e['page_number'] ?? 1, 'is_visible' => $e['is_visible'] ?? 1,
            ], $source['elements']),
        ], $userId);
    }

    /** Duplicates BOTH languages of a pair together as one new, independent pair -- direct port of
     *  EmploymentCertificateTemplateModel::duplicatePair() (see that method's own docblock). Every
     *  language currently non-deleted for the source pair is cloned into the SAME new pair_key, so
     *  the copy stays linked as one pair too instead of becoming two separate, unlinked templates. */
    public function duplicatePair(int $compId, string $pairKey, int $userId): array {
        $stmt = $this->db->prepare("SELECT id FROM `payslip_templates`
            WHERE comp_id = :comp_id AND pair_key = :pair_key AND deleted_at IS NULL");
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
                    'template_name' => $source['template_name'] . ' (Copy)', 'is_default' => false, 'status' => $source['status'],
                    'header_text_th' => $source['header_text_th'], 'header_text_en' => $source['header_text_en'],
                    'footer_text_th' => $source['footer_text_th'], 'footer_text_en' => $source['footer_text_en'],
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

    public function delete(int $compId, int $id, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        $stmtCheck = $this->db->prepare("SELECT * FROM `payslip_templates` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmtCheck->execute([':id' => $id, ':comp_id' => $compId]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $stmt = $this->db->prepare("UPDATE `payslip_templates` SET status = 'deleted', is_default = 0, deleted_by = :deleted_by, deleted_at = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':deleted_by' => $userId, ':id' => $id]);
        // Same soft-delete-is-a-status-change convention as every other model in this audit log.
        $this->auditLog->record($compId, 'payslip_templates', $id, 'update', $existing, array_merge($existing, ['status' => 'deleted', 'is_default' => 0]), $userId, 'web', $ip, $userAgent);
        return ['status' => true, 'message' => 'Deleted successfully.'];
    }

    public function toggleStatus(int $compId, int $id, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        $stmt = $this->db->prepare("SELECT * FROM `payslip_templates` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $current = $existing['status'];
        $newStatus = $current === 'active' ? 'inactive' : 'active';
        $sql = "UPDATE `payslip_templates` SET status = :status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP";
        $params = [':status' => $newStatus, ':updated_by' => $userId, ':id' => $id];
        if ($newStatus === 'inactive') {
            // An inactive template can't remain "the" default (PaySlipReport only looks at active defaults).
            $sql .= ", is_default = 0";
        }
        $sql .= " WHERE id = :id";
        $this->db->prepare($sql)->execute($params);
        $newRow = array_merge($existing, ['status' => $newStatus]);
        if ($newStatus === 'inactive') {
            $newRow['is_default'] = 0;
        }
        $this->auditLog->record($compId, 'payslip_templates', $id, 'update', $existing, $newRow, $userId, 'web', $ip, $userAgent);
        return ['status' => true, 'message' => 'Updated successfully.', 'new_status' => $newStatus];
    }

    /** Single-default-per-(comp_id,language) enforcement (2026-08-25 follow-up -- was company-wide
     *  before "รูปแบบการทำเหมือนกัน", now scoped per language same as
     *  EmploymentCertificateTemplateModel::setDefault()). Still genuinely used, unlike Employment
     *  Certificate Template's own version (kept only as a config-only precedent) -- is_default really
     *  does control PaySlipReport::generate()'s fallback via getDefault(). */
    public function setDefault(int $compId, int $id, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        $template = $this->get($compId, $id);
        if (!$template) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $own = !$this->db->inTransaction();
        try {
            if ($own) { $this->db->beginTransaction(); }
            $this->db->prepare("UPDATE `payslip_templates` SET is_default = 0 WHERE comp_id = :comp_id AND language = :language AND deleted_at IS NULL")
                ->execute([':comp_id' => $compId, ':language' => $template['language']]);
            $this->db->prepare("UPDATE `payslip_templates` SET is_default = 1, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                ->execute([':updated_by' => $userId, ':id' => $id]);
            // Only THIS row's own is_default flip is logged (0->1) -- the other templates that just
            // lost default status via the sibling-clearing UPDATE above are not separately logged,
            // same "log the record the admin acted on, not every side effect" precedent every other
            // "single-default-per-scope" toggle in this app already follows implicitly.
            $this->auditLog->record($compId, 'payslip_templates', $id, 'update', ['is_default' => (int)$template['is_default']], ['is_default' => 1], $userId, 'web', $ip, $userAgent);
            if ($own) { $this->db->commit(); }
            return ['status' => true, 'message' => 'Default template updated.'];
        } catch (PDOException $e) {
            if ($own) { $this->db->rollBack(); }
            return ['status' => false, 'message' => 'Database operation failed.'];
        }
    }

    /**
     * 2026-08-26, explicit request: "ให้มี Draft Mode และ Public Mode...ตั้งต้นเป็น Draft mode ก่อน แล้ว
     * ค่อย Public และในหน้า List สามารถเปิด Draft หรือ Public ได้จากหน้านั้นเลย" -- the ONE place
     * `publish_status` is ever changed (save()/autosave never touch it, see that method's own
     * comment), callable both from the List page's own toggle and from a switch inside the editor.
     */
    public function setPublishStatus(int $compId, int $id, string $status, int $userId, ?string $ip = null, ?string $userAgent = null): array {
        if (!in_array($status, ['draft', 'public'], true)) {
            return ['status' => false, 'message' => 'Invalid publish status.'];
        }
        $stmt = $this->db->prepare("SELECT id, publish_status FROM `payslip_templates` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
        $stmt->execute([':id' => $id, ':comp_id' => $compId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            return ['status' => false, 'message' => 'Record not found.'];
        }
        $this->db->prepare("UPDATE `payslip_templates` SET publish_status = :publish_status, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
            ->execute([':publish_status' => $status, ':updated_by' => $userId, ':id' => $id]);
        $this->auditLog->record($compId, 'payslip_templates', $id, 'update', ['publish_status' => $existing['publish_status']], ['publish_status' => $status], $userId, 'web', $ip, $userAgent);
        return ['status' => true, 'message' => 'Updated successfully.', 'publish_status' => $status];
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

    /** @param string $language Only affects a couple of hand-authored literal label strings (the
     *  "สลิปเงินเดือน" title line) -- everything else is a {{token}} that already resolves per-
     *  language at generation time via PayslipTemplateRenderer::buildTokens()/pick(), unaffected by
     *  which canvas it was designed on. Mirrors EmploymentCertificateTemplateModel::presetElements()'s
     *  own $language param, kept proportionally lighter since ECT's presets are mostly hand-authored
     *  prose while Payslip's are almost entirely token-driven. */
    private function presetElements(string $preset, string $language): array {
        $base = [
            'font_family' => $language === 'en' ? 'dejavu_sans' : 'th_sarabun_new', 'font_color' => '#000000',
            'font_weight' => 'normal', 'font_style' => 'normal', 'text_decoration' => 'none',
        ];
        $tok = fn(string $code) => '{{' . $code . '}}';
        $title = $language === 'en' ? 'Pay Slip' : 'สลิปเงินเดือน';
        switch ($preset) {
            case 'classic':
                return [
                    $base + ['element_type' => 'text', 'content' => $tok('company_name'), 'pos_x_pct' => 8, 'pos_y_pct' => 4, 'width_pct' => 60, 'height_pct' => 6, 'font_size' => 18, 'text_align' => 'left', 'font_weight' => 'bold'],
                    $base + ['element_type' => 'text', 'content' => $title, 'pos_x_pct' => 8, 'pos_y_pct' => 10, 'width_pct' => 60, 'height_pct' => 5, 'font_size' => 13, 'text_align' => 'left'],
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
    public function createFromPreset(int $compId, string $language, string $preset, string $templateName, int $userId, ?string $pairKey = null): array {
        if (!in_array($preset, self::PRESETS, true)) {
            return ['status' => false, 'message' => 'Invalid preset.'];
        }
        $data = [
            'language' => $language, 'template_name' => $templateName,
            'elements' => $this->presetElements($preset, $language),
        ];
        // Creating the SECOND language of a pair manually (from the gallery, "ทำเอง" instead of
        // "Generate Auto") still needs to link to the same pair_key as its counterpart, not start a
        // brand-new one -- mirrors EmploymentCertificateTemplateModel::createFromPreset() exactly.
        if ($pairKey !== null) {
            $data['pair_key'] = $pairKey;
        }
        return $this->save($compId, $data, $userId);
    }

    /** Public read-only entry point for the New Template modal's per-preset Preview button. */
    public function presetPreviewElements(string $preset, string $language): array {
        if (!in_array($preset, self::PRESETS, true)) {
            throw new InvalidArgumentException('Invalid preset.');
        }
        if (!in_array($language, self::LANGUAGES, true)) {
            throw new InvalidArgumentException('Invalid language.');
        }
        return $this->presetElements($preset, $language);
    }

    // listImages()/addImage()/deleteImage() moved to TemplateDesignerModelTrait (T064) -- table
    // names supplied by imagesTableName()/elementsTableName() above.
}
