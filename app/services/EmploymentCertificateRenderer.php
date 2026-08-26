<?php
declare(strict_types=1);

/**
 * Renders an Employment Certificate Template's elements (see EmploymentCertificateTemplateModel)
 * into a PDF. Every element's `left/top/width/height` are plain CSS percentages against a page
 * container sized from the template's own `page_size`/`orientation` -- the exact same coordinate
 * space the browser editor uses, so this is WYSIWYG by construction, no px/pt/mm conversion to
 * keep in sync.
 *
 * `text` elements may embed `{{field_key}}` tokens (e.g. "{{employee_name}}" alone, or inline
 * inside a hand-written sentence) -- resolved here via simple string substitution against a fixed
 * token map built from real company + employee data (buildTokens()). `image` elements are either
 * `field_key = 'company_logo'` (drawn from the template's own uploaded logo file) or bound to a
 * row in `employment_certificate_images` (a reusable, per-company uploaded image library --
 * `image_asset_id`), resolved by the caller and passed in as `$imageAssetPaths`.
 *
 * 2026-08-24: does NOT use the shared `PdfRendererTrait` (Reports/Payslip modules) any more --
 * this class needs its own Dompdf setup for two reasons neither of those need:
 *  1. TH Sarabun New registration (registerThaiFonts()) -- discovered by directly parsing the
 *     `cmap` tables of dompdf's bundled DejaVu* fonts (`storage/fonts/thsarabun/NOTICE.md` has the
 *     full finding) that NONE of them contain a single Thai glyph, despite PdfRendererTrait's own
 *     docblock assuming DejaVu Sans had Thai coverage. Every Thai PDF this app has ever generated
 *     via that shared trait was very likely rendering blank glyphs wherever Thai text appeared --
 *     flagging this here since it's a real, confirmed defect in shared infrastructure, but NOT
 *     fixing PdfRendererTrait itself in this change (out of the scope actually asked for, and
 *     changing the default font sitewide for every existing statutory export needs its own
 *     deliberate look, not a side effect of this feature).
 *  2. A widened dompdf `chroot` (Options::setChroot()) -- by default dompdf only allows local
 *     file:// access (fonts, <img> src) under its OWN vendor package directory. Registering a font
 *     from `storage/fonts/` or embedding a logo/uploaded image from `public/uploads/` both fail
 *     dompdf's own path-containment check otherwise (confirmed empirically while building this --
 *     registerFont() returned false with "Permission denied...chroot" until this was widened).
 *     Widened to the whole project root: safe here because isRemoteEnabled stays false (blocks
 *     remote http/https regardless) and every local path actually reaching buildHtml() is already
 *     independently validated by the caller (EmploymentCertificateTemplateModel::isValidLogoPath(),
 *     the image-library asset lookup) before it gets anywhere near this class -- never raw user
 *     input.
 */
class EmploymentCertificateRenderer {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    private const EMPLOYMENT_STATUS_LABELS = [
        'th' => ['probation' => 'ทดลองงาน', 'permanent' => 'พนักงานประจำ', 'contract' => 'พนักงานสัญญาจ้าง', 'resigned' => 'ลาออกแล้ว', 'terminated' => 'เลิกจ้างแล้ว'],
        'en' => ['probation' => 'Probation', 'permanent' => 'Permanent', 'contract' => 'Contract', 'resigned' => 'Resigned', 'terminated' => 'Terminated'],
    ];
    private const EMPLOYMENT_TYPE_LABELS = [
        'th' => ['full_time' => 'เต็มเวลา', 'part_time' => 'บางเวลา', 'daily' => 'รายวัน', 'internship' => 'ฝึกงาน'],
        'en' => ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'daily' => 'Daily', 'internship' => 'Internship'],
    ];
    /** 2026-08-25, explicit request: "ตรง Add to Canvas สามารถเพิ่ม item อะไรเกี่ยวกับพนักงาน...ได้อีกไหม" */
    private const GENDER_LABELS = [
        'th' => ['male' => 'ชาย', 'female' => 'หญิง', 'other' => 'อื่นๆ'],
        'en' => ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'],
    ];

    /** page_size => [width_mm, height_mm] in PORTRAIT orientation; swapped for landscape.
     *  2026-08-26, explicit request: "ตรง Page Setup ให้เพิ่ม A3 A5 และอื่นๆ เหมือนใน Word" -- standard
     *  ISO 216 (A3/A5/B4/B5) and ANSI (Tabloid/Executive/Statement) dimensions, same list/values as
     *  PayslipTemplateRenderer::PAGE_SIZES_MM. */
    public const PAGE_SIZES_MM = [
        'A3' => [297.0, 420.0],
        'A4' => [210.0, 297.0],
        'A5' => [148.0, 210.0],
        'B4' => [250.0, 353.0],
        'B5' => [176.0, 250.0],
        'Letter' => [215.9, 279.4],
        'Legal' => [215.9, 355.6],
        'Tabloid' => [279.4, 431.8],
        'Executive' => [184.15, 266.7],
        'Statement' => [139.7, 215.9],
    ];

    /** font_family code => CSS font-family name. 'th_sarabun_new' is registered explicitly (see
     *  registerThaiFonts()); the other 6 are all recognized NATIVELY by dompdf without any
     *  registerFont() call -- 'DejaVu Sans'/'DejaVu Sans Mono'/'DejaVu Serif' via its own bundled
     *  TTFs (installed-fonts.dist.json), 'Helvetica'/'Times-Roman'/'Courier' via its built-in
     *  non-embedded base-14 fonts (2026-08-25, explicit request: "เพิ่มตัวเลือก font สัก 10 font ครับ"
     *  -- shipped 7, see the migration's own comment for why not 10: every option here needs a REAL,
     *  legitimately-available font file to keep the canvas preview and the PDF from silently
     *  drifting apart, same bug class already fixed once in this module). None of the 6 non-Sarabun
     *  fonts have Thai glyphs at all -- confirmed for DejaVu previously by parsing cmap tables
     *  directly, and Helvetica/Times/Courier are the same standard Latin-only base-14 set every PDF
     *  viewer ships -- so all 6 are English-tab-only in the UI (see updateFontFamilyOptions() in the
     *  JS), same restriction DejaVu Sans already had. */
    private const FONT_FAMILY_CSS = [
        'th_sarabun_new' => 'TH Sarabun New',
        'dejavu_sans' => 'DejaVu Sans',
        'dejavu_sans_mono' => 'DejaVu Sans Mono',
        'dejavu_serif' => 'DejaVu Serif',
        'helvetica' => 'Helvetica',
        'times_new_roman' => 'Times-Roman',
        'courier' => 'Courier',
    ];

    public static function pageDimensionsMm(string $pageSize, string $orientation): array {
        [$w, $h] = self::PAGE_SIZES_MM[$pageSize] ?? self::PAGE_SIZES_MM['A4'];
        return $orientation === 'landscape' ? [$h, $w] : [$w, $h];
    }

    public function fetchCompany(int $compId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM `companies` WHERE id = :id");
        $stmt->execute([':id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function fetchEmployee(int $compId, int $employeeId): ?array {
        // 2026-08-25, explicit request: "ตรง Add to Canvas สามารถเพิ่ม item อะไรเกี่ยวกับพนักงานได้อีกไหม" --
        // branch/team joined the same way department/position already are; gender/nationality/
        // date_of_birth are plain columns on `employees` itself, no join needed.
        $stmt = $this->db->prepare("SELECT e.id, e.employee_no, e.name_th, e.surname_th, e.name_en, e.surname_en,
                e.employment_date, e.employment_status, e.employment_type, e.base_salary_amount,
                e.gender, e.nationality, e.date_of_birth,
                d.department_name_th, d.department_name_en, p.position_name_th, p.position_name_en,
                b.branch_name_th, b.branch_name_en, t.team_name_th, t.team_name_en
            FROM `employees` e
            LEFT JOIN `structure_departments` d ON e.department_id = d.id
            LEFT JOIN `structure_positions` p ON e.position_id = p.id
            LEFT JOIN `structure_branches` b ON e.branch_id = b.id
            LEFT JOIN `structure_teams` t ON e.team_id = t.id
            WHERE e.id = :id AND e.comp_id = :comp_id AND e.deleted_at IS NULL");
        $stmt->execute([':id' => $employeeId, ':comp_id' => $compId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** Any real employee for this company, for the Preview button when the admin hasn't picked
     *  one -- falls through to mockEmployee() if the company has none at all (a brand-new company
     *  still designing its template before any real employee exists). */
    public function fetchAnyEmployee(int $compId): array {
        $stmt = $this->db->prepare("SELECT id FROM `employees` WHERE comp_id = :comp_id AND deleted_at IS NULL ORDER BY id ASC LIMIT 1");
        $stmt->execute([':comp_id' => $compId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return $this->mockEmployee();
        }
        return $this->fetchEmployee($compId, (int)$id) ?? $this->mockEmployee();
    }

    private function mockEmployee(): array {
        return [
            'id' => 0, 'employee_no' => 'EMP-0001',
            'name_th' => 'สมชาย', 'surname_th' => 'ใจดี', 'name_en' => 'Somchai', 'surname_en' => 'Jaidee',
            'department_name_th' => 'ฝ่ายทรัพยากรบุคคล', 'department_name_en' => 'Human Resources',
            'position_name_th' => 'เจ้าหน้าที่อาวุโส', 'position_name_en' => 'Senior Officer',
            'employment_date' => date('Y-m-d', strtotime('-2 years')), 'employment_status' => 'permanent', 'employment_type' => 'full_time',
            'base_salary_amount' => 30000,
            'gender' => 'male', 'nationality' => 'Thai', 'date_of_birth' => date('Y-m-d', strtotime('-30 years')),
            'branch_name_th' => 'สำนักงานใหญ่', 'branch_name_en' => 'Head Office',
            'team_name_th' => 'ทีมโครงการเอ', 'team_name_en' => 'Project A Team',
        ];
    }

    private function formatDate(?string $ymd): string {
        if (empty($ymd)) {
            return '-';
        }
        $ts = strtotime($ymd);
        return $ts !== false ? date('d/m/Y', $ts) : $ymd;
    }

    /** @return array<string,string> field_key => resolved display value, for substituteTokens(). */
    public function buildTokens(string $language, array $company, array $employee): array {
        $lang = $language === 'en' ? 'en' : 'th';
        $employeeName = $lang === 'en'
            ? trim(($employee['name_en'] ?? '') . ' ' . ($employee['surname_en'] ?? ''))
            : trim(($employee['name_th'] ?? '') . ' ' . ($employee['surname_th'] ?? ''));
        $address = trim(($company['address_line_1'] ?? '') . ' ' . ($company['address_line_2'] ?? ''));
        $statusCode = (string)($employee['employment_status'] ?? '');
        $typeCode = (string)($employee['employment_type'] ?? '');
        $genderCode = (string)($employee['gender'] ?? '');
        return [
            'company_name' => (string)($company['local_name'] ?? $company['company_legal_name'] ?? ''),
            'company_address' => $address,
            'company_tax_id' => (string)($company['global_tax_id'] ?? ''),
            'company_signatory' => (string)($company['authorized_signatory_name'] ?? ''),
            'employee_no' => (string)($employee['employee_no'] ?? ''),
            'employee_name' => $employeeName,
            'position' => (string)($lang === 'en' ? ($employee['position_name_en'] ?? '') : ($employee['position_name_th'] ?? '')) ?: '-',
            'department' => (string)($lang === 'en' ? ($employee['department_name_en'] ?? '') : ($employee['department_name_th'] ?? '')) ?: '-',
            'employment_date' => $this->formatDate($employee['employment_date'] ?? null),
            'employment_status' => self::EMPLOYMENT_STATUS_LABELS[$lang][$statusCode] ?? $statusCode,
            'employment_type' => self::EMPLOYMENT_TYPE_LABELS[$lang][$typeCode] ?? $typeCode,
            'base_salary' => number_format((float)($employee['base_salary_amount'] ?? 0), 2),
            'issue_date' => $this->formatDate(date('Y-m-d')),
            'employee_branch' => (string)($lang === 'en' ? ($employee['branch_name_en'] ?? '') : ($employee['branch_name_th'] ?? '')) ?: '-',
            'employee_team' => (string)($lang === 'en' ? ($employee['team_name_en'] ?? '') : ($employee['team_name_th'] ?? '')) ?: '-',
            'employee_gender' => self::GENDER_LABELS[$lang][$genderCode] ?? $genderCode,
            'employee_nationality' => (string)($employee['nationality'] ?? '') ?: '-',
            'employee_date_of_birth' => $this->formatDate($employee['date_of_birth'] ?? null),
        ];
    }

    private function substituteTokens(string $content, array $tokens): string {
        $escaped = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
        foreach ($tokens as $key => $value) {
            $escaped = str_replace('{{' . $key . '}}', htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'), $escaped);
        }
        return $escaped;
    }

    /**
     * $logoAbsPath / $imageAssetPaths[...] must already be verified, on-disk, traversal-safe
     * absolute paths -- callers resolve/validate them, this method just embeds whatever it's given.
     * @param array{page_size?:string, orientation?:string} $template
     * @param array<int,string> $imageAssetPaths image_asset_id => absolute file path
     */
    /** Renders ONE element's positioning/typography div content (text/image/shape/table) -- split
     *  out of buildHtml() so multi-page grouping there stays readable. Returns '' for an element
     *  that resolves to nothing visible (e.g. an image field with no resolvable path) -- callers
     *  just concatenate, nothing special needed for the empty case. */
    private function renderElementHtml(array $el, array $tokens, ?string $logoAbsPath, array $imageAssetPaths): string {
        $fontFamily = self::FONT_FAMILY_CSS[$el['font_family'] ?? 'th_sarabun_new'] ?? self::FONT_FAMILY_CSS['th_sarabun_new'];
        // font-family value is single-quoted (not double) -- this whole style string gets embedded
        // inside a DOUBLE-quoted HTML style="..." attribute below; double-quoting it here too would
        // silently truncate the attribute at that exact point (real bug hit while building this:
        // font-family/color/etc. after it just never applied, with no error anywhere -- dompdf
        // quietly fell back to a default serif font instead).
        $style = sprintf(
            'position:absolute;left:%s%%;top:%s%%;width:%s%%;height:%s%%;font-size:%dpx;text-align:%s;'
            . "font-weight:%s;font-style:%s;text-decoration:%s;color:%s;font-family:'%s',sans-serif;"
            . 'overflow:hidden;word-wrap:break-word;',
            $el['pos_x_pct'], $el['pos_y_pct'], $el['width_pct'], $el['height_pct'],
            (int)$el['font_size'], htmlspecialchars((string)$el['text_align'], ENT_QUOTES, 'UTF-8'),
            ($el['font_weight'] ?? 'normal') === 'bold' ? 'bold' : 'normal',
            ($el['font_style'] ?? 'normal') === 'italic' ? 'italic' : 'normal',
            ($el['text_decoration'] ?? 'none') === 'underline' ? 'underline' : 'none',
            htmlspecialchars((string)($el['font_color'] ?? '#000000'), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($fontFamily, ENT_QUOTES, 'UTF-8')
        );
        if ($el['element_type'] === 'image') {
            $imgAbsPath = null;
            if (!empty($el['field_key']) && $el['field_key'] === 'company_logo') {
                $imgAbsPath = $logoAbsPath;
            } elseif (!empty($el['image_asset_id']) && isset($imageAssetPaths[(int)$el['image_asset_id']])) {
                $imgAbsPath = $imageAssetPaths[(int)$el['image_asset_id']];
            }
            if ($imgAbsPath === null || !is_file($imgAbsPath)) {
                return '';
            }
            return '<div style="' . $style . '"><img src="' . htmlspecialchars($imgAbsPath, ENT_QUOTES, 'UTF-8') . '" style="max-width:100%;max-height:100%;"></div>';
        }
        if ($el['element_type'] === 'shape') {
            // 2026-08-25, explicit request: "สามารถ insert shape ต่างๆ เหมือน Word" -- deliberately
            // simple compared to Word's own shape gallery: rectangle/ellipse are a solid
            // background+border fill (font_color doubles as both, this element type has no separate
            // fill/border color fields), 'line' is the same box just drawn very thin (no free-angle
            // line segments).
            $shapeType = in_array($el['field_key'] ?? '', ['rectangle', 'ellipse', 'line'], true) ? $el['field_key'] : 'rectangle';
            $color = htmlspecialchars((string)($el['font_color'] ?? '#000000'), ENT_QUOTES, 'UTF-8');
            $shapeStyle = sprintf(
                'position:absolute;left:%s%%;top:%s%%;width:%s%%;height:%s%%;background-color:%s;border:1px solid %s;box-sizing:border-box;',
                $el['pos_x_pct'], $el['pos_y_pct'], $el['width_pct'], $el['height_pct'], $color, $color
            );
            if ($shapeType === 'ellipse') {
                $shapeStyle .= 'border-radius:50%;';
            }
            return '<div style="' . $shapeStyle . '"></div>';
        }
        if ($el['element_type'] === 'table') {
            // 2026-08-25, explicit request: "เพิ่ม option การเพิ่มตาราง ที่สามารถกำหนดเส้นสีเส้นขอบได้เหมือน
            // word" -- content is validated/re-encoded JSON (see
            // EmploymentCertificateTemplateModel::validateElements()), never raw/untrusted shape.
            $tableData = json_decode((string)($el['content'] ?? ''), true);
            $rows = is_array($tableData) ? (int)($tableData['rows'] ?? 0) : 0;
            $cols = is_array($tableData) ? (int)($tableData['cols'] ?? 0) : 0;
            if ($rows < 1 || $cols < 1) {
                return '';
            }
            $borderColor = htmlspecialchars((string)($tableData['border_color'] ?? '#000000'), ENT_QUOTES, 'UTF-8');
            $borderWidth = (int)($tableData['border_width'] ?? 1);
            $cells = is_array($tableData['cells'] ?? null) ? $tableData['cells'] : [];
            $tableHtml = '<table style="width:100%;height:100%;border-collapse:collapse;">';
            for ($r = 0; $r < $rows; $r++) {
                $tableHtml .= '<tr>';
                for ($c = 0; $c < $cols; $c++) {
                    $cellText = nl2br(htmlspecialchars((string)($cells[$r][$c] ?? ''), ENT_QUOTES, 'UTF-8'));
                    $tableHtml .= "<td style=\"border:{$borderWidth}px solid {$borderColor};padding:2px 4px;\">{$cellText}</td>";
                }
                $tableHtml .= '</tr>';
            }
            $tableHtml .= '</table>';
            return '<div style="' . $style . '">' . $tableHtml . '</div>';
        }
        return '<div style="' . $style . '">' . $this->substituteTokens((string)($el['content'] ?? ''), $tokens) . '</div>';
    }

    /** 2026-08-25, explicit request: "รองรับการมีหลายๆหน้า โดยที่มีปุ่มให้เลือกเพิ่มหรือลด" -- `$elements`
     *  spans however many distinct `page_number`s exist (1-based); each becomes its own `.cert-page`
     *  div, `page-break-after:always` on every one but the last so dompdf actually starts a new
     *  physical page. Page SIZE/orientation are shared across every page (no per-page sizing) -- not
     *  asked for, and would need its own UI/data model this request didn't call for. */
    public function buildHtml(array $template, string $language, array $elements, array $company, array $employee, ?string $logoAbsPath, array $imageAssetPaths = [], ?string $watermarkText = null): string {
        $tokens = $this->buildTokens($language, $company, $employee);
        [$pageW, $pageH] = self::pageDimensionsMm((string)($template['page_size'] ?? 'A4'), (string)($template['orientation'] ?? 'portrait'));

        $byPage = [];
        foreach ($elements as $el) {
            $pageNumber = max(1, (int)($el['page_number'] ?? 1));
            $byPage[$pageNumber][] = $el;
        }
        if (empty($byPage)) {
            $byPage[1] = [];
        }
        ksort($byPage);

        $watermarkHtml = '';
        if ($watermarkText !== null && trim($watermarkText) !== '') {
            // 2026-08-25, real bug found: the original single-div version chained
            // `transform:translate(-50%,-50%) rotate(-35deg)` -- dompdf's CSS transform support is
            // known to be inconsistent for chained/composed transform functions (confirmed by
            // dompdf's own changelog/issue history, not just a guess), so this likely rendered
            // incorrectly or not at all in the actual PDF despite `preview()`'s own test only ever
            // checking the STRING contains "rotate(-35deg)", never that dompdf drew it correctly --
            // a real gap in that test's coverage. Rewritten as two nested elements: an outer
            // full-width div centered the ordinary way (`top:50%` + `text-align:center`, the exact
            // same well-supported positioning every other element on this page already uses) and an
            // inner `inline-block` that carries the ONE simple `rotate()` transform, nothing chained.
            // Repeated on EVERY page (a multi-page draft with a watermark on page 1 only would look
            // like an oversight, not a deliberate choice).
            $watermarkHtml = '<div style="position:absolute;top:50%;left:0;width:100%;text-align:center;overflow:visible;">'
                . '<div style="display:inline-block;transform:rotate(-35deg);transform-origin:center;'
                . "font-size:64px;color:rgba(150,150,150,0.35);font-family:'TH Sarabun New',sans-serif;"
                . 'white-space:nowrap;font-weight:bold;">' . htmlspecialchars(trim($watermarkText), ENT_QUOTES, 'UTF-8') . '</div></div>';
        }

        $pageKeys = array_keys($byPage);
        $lastPageKey = end($pageKeys);
        $pagesHtml = '';
        foreach ($byPage as $pageNumber => $pageElements) {
            $body = '';
            foreach ($pageElements as $el) {
                // 2026-08-26, explicit request: "ตรง Layer ให้มี function เปิด/ปิดตาได้ แทนการที่ต้องลบ
                // อย่างเดียว" -- a hidden element is skipped from the actual generated document too
                // (not just the canvas), the real functional alternative to deleting it. Defaults to
                // visible when absent (old rows/tests saved before this column existed).
                if (array_key_exists('is_visible', $el) && !$el['is_visible']) {
                    continue;
                }
                $body .= $this->renderElementHtml($el, $tokens, $logoAbsPath, $imageAssetPaths);
            }
            $body .= $watermarkHtml;
            $breakStyle = $pageNumber === $lastPageKey ? '' : 'page-break-after:always;';
            $pagesHtml .= '<div class="cert-page" style="' . $breakStyle . '">' . $body . '</div>';
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
            . "@page { size: {$pageW}mm {$pageH}mm; margin: 0; }"
            . 'body { margin:0; padding:0; font-family: "TH Sarabun New", sans-serif; }'
            . ".cert-page { position: relative; width: {$pageW}mm; height: {$pageH}mm; overflow: hidden; }"
            . '</style></head><body>' . $pagesHtml . '</body></html>';
    }

    /** Resolves a value already validated as `public/uploads/{$subdir}/{comp_id}/{hash}.{ext}` to
     *  a traversal-safe absolute path, or null if unset/missing on disk -- same defense-in-depth
     *  pattern as PaySlipReport's own company_logo handling. */
    private function resolveUploadAbsPath(?string $relativePath, string $subdir): ?string {
        if (empty($relativePath)) {
            return null;
        }
        $uploadsRoot = realpath(__DIR__ . '/../../public/uploads/' . $subdir);
        $abs = realpath(__DIR__ . '/../../' . ltrim($relativePath, '/'));
        if ($uploadsRoot === false || $abs === false || strpos($abs, $uploadsRoot) !== 0 || !is_file($abs)) {
            return null;
        }
        return $abs;
    }

    private function resolveLogoAbsPath(?string $logoPath): ?string {
        return $this->resolveUploadAbsPath($logoPath, 'employment_cert_logos');
    }

    /** Template's own logo first, falling back to the company profile's logo (Company Profile >
     *  Logo, `companies.logo_path`) when the template has none set -- same fallback pattern as
     *  PaySlipReport::resolveTemplateOrCompanyLogo(). */
    private function resolveTemplateOrCompanyLogo(?string $templateLogoPath, ?string $companyLogoPath): ?string {
        $own = $this->resolveLogoAbsPath($templateLogoPath);
        if ($own !== null) {
            return $own;
        }
        return $this->resolveUploadAbsPath($companyLogoPath, 'company_logos');
    }

    /** @param int[] $imageAssetIds
     *  @return array<int,string> image_asset_id => resolved absolute path, only for ids that
     *  belong to this company and exist on disk (missing/foreign ids are silently omitted --
     *  buildHtml() already skips an image element with no resolvable path). */
    public function resolveImageAssetPaths(int $compId, array $imageAssetIds): array {
        $imageAssetIds = array_values(array_unique(array_map('intval', array_filter($imageAssetIds))));
        if (empty($imageAssetIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($imageAssetIds), '?'));
        $stmt = $this->db->prepare("SELECT id, file_path FROM `employment_certificate_images` WHERE comp_id = ? AND id IN ({$placeholders})");
        $stmt->execute(array_merge([$compId], $imageAssetIds));
        $paths = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $abs = $this->resolveUploadAbsPath($row['file_path'], 'employment_cert_images');
            if ($abs !== null) {
                $paths[(int)$row['id']] = $abs;
            }
        }
        return $paths;
    }

    /**
     * Registers TH Sarabun New (4 styles, bundled under storage/fonts/thsarabun/ -- see that
     * directory's own NOTICE.md for why DejaVu Sans, dompdf's default, can't be used for Thai at
     * all) with a Dompdf instance's font metrics, and widens its chroot so both that registration
     * and any local <img> embed (logo / uploaded image assets) are actually allowed to read from
     * outside dompdf's own vendor directory. Must run BEFORE loadHtml()/render().
     */
    private function registerThaiFonts(\Dompdf\Dompdf $dompdf): void {
        $fontMetrics = $dompdf->getFontMetrics();
        $dir = realpath(__DIR__ . '/../../storage/fonts/thsarabun');
        if ($dir === false) {
            return;
        }
        $toFileUri = fn(string $path): string => 'file://' . str_replace('\\', '/', $path);
        $variants = [
            ['weight' => 'normal', 'style' => 'normal', 'file' => 'THSarabun.ttf'],
            ['weight' => 'bold', 'style' => 'normal', 'file' => 'THSarabun-Bold.ttf'],
            ['weight' => 'normal', 'style' => 'italic', 'file' => 'THSarabun-Italic.ttf'],
            ['weight' => 'bold', 'style' => 'italic', 'file' => 'THSarabun-BoldItalic.ttf'],
        ];
        foreach ($variants as $v) {
            $path = $dir . DIRECTORY_SEPARATOR . $v['file'];
            if (is_file($path)) {
                $fontMetrics->registerFont(['family' => 'TH Sarabun New', 'weight' => $v['weight'], 'style' => $v['style']], $toFileUri($path));
            }
        }
    }

    private function newDompdf(): \Dompdf\Dompdf {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        // See this class's own docblock -- dompdf's default chroot only covers its own vendor
        // directory, which blocks both font registration (storage/fonts/) and local <img> embeds
        // (public/uploads/...) otherwise.
        $options->setChroot([realpath(__DIR__ . '/../../')]);
        $dompdf = new \Dompdf\Dompdf($options);
        $this->registerThaiFonts($dompdf);
        return $dompdf;
    }

    /** @param array{page_size?:string, orientation?:string} $template */
    public function renderPdf(array $template, string $html): string {
        $dompdf = $this->newDompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        [$pageW, $pageH] = self::pageDimensionsMm((string)($template['page_size'] ?? 'A4'), (string)($template['orientation'] ?? 'portrait'));
        // dompdf's setPaper() also accepts an explicit [x1,y1,x2,y2] size in points (1mm = 72/25.4pt)
        // -- used instead of a named size string so Letter/Legal + our own mm-based @page rule agree
        // exactly regardless of orientation swapping.
        $ptPerMm = 72 / 25.4;
        $dompdf->setPaper([0, 0, $pageW * $ptPerMm, $pageH * $ptPerMm]);
        $dompdf->render();
        return $dompdf->output();
    }

    /**
     * Renders the settings page's CURRENT (possibly unsaved) canvas state against real company
     * data + a real or mock employee -- nothing here is persisted.
     * @param array{page_size?:string, orientation?:string} $template
     * @param array $elements same shape EmploymentCertificateTemplateModel::validateElements() produces
     */
    public function renderPreview(int $compId, array $template, string $language, array $elements, ?string $logoPath, ?int $employeeId, ?string $watermarkText = null): string {
        $company = $this->fetchCompany($compId);
        if ($company === null) {
            throw new RuntimeException('Company not found.');
        }
        $employee = $employeeId !== null ? $this->fetchEmployee($compId, $employeeId) : null;
        if ($employee === null) {
            $employee = $this->fetchAnyEmployee($compId);
        }
        $imageAssetIds = array_map(fn($el) => (int)($el['image_asset_id'] ?? 0), $elements);
        $imageAssetPaths = $this->resolveImageAssetPaths($compId, $imageAssetIds);
        $logoAbsPath = $this->resolveTemplateOrCompanyLogo($logoPath, $company['logo_path'] ?? null);
        $html = $this->buildHtml($template, $language, $elements, $company, $employee, $logoAbsPath, $imageAssetPaths, $watermarkText);
        return $this->renderPdf($template, $html);
    }

    /**
     * Renders the REAL, final PDF for an approved Employment Certificate Request
     * (EmploymentCertificateRequestModel::issuePdf(), 2026-08-26) -- unlike renderPreview(), this
     * NEVER falls back to mock/any-other-employee data. A genuine issuance must fail loudly (throw)
     * if the company or employee can't be resolved, not silently produce a document for the wrong
     * person or with placeholder data. No watermark -- an issued document is the real thing.
     */
    public function renderForIssuance(int $compId, array $template, string $language, array $elements, int $employeeId): string {
        $company = $this->fetchCompany($compId);
        if ($company === null) {
            throw new RuntimeException('Company not found.');
        }
        $employee = $this->fetchEmployee($compId, $employeeId);
        if ($employee === null) {
            throw new RuntimeException('Employee not found.');
        }
        $imageAssetIds = array_map(fn($el) => (int)($el['image_asset_id'] ?? 0), $elements);
        $imageAssetPaths = $this->resolveImageAssetPaths($compId, $imageAssetIds);
        $logoAbsPath = $this->resolveTemplateOrCompanyLogo($template['logo_path'] ?? null, $company['logo_path'] ?? null);
        $html = $this->buildHtml($template, $language, $elements, $company, $employee, $logoAbsPath, $imageAssetPaths, null);
        return $this->renderPdf($template, $html);
    }
}
