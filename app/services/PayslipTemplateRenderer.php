<?php
declare(strict_types=1);
require_once __DIR__ . '/reports/EmployeePiiTrait.php';

/**
 * Renders a Payslip Template's canvas elements (see PayslipTemplateModel) into a PDF -- the exact
 * same architecture as EmploymentCertificateRenderer (2026-08-25, explicit request: "ปรับให้การตั้งค่า
 * Slip เงินเดือน Template เป็นเหมือนกับใบรับรอง", confirmed via AskUserQuestion: a FULL canvas
 * designer, not just matching page chrome). Every element's left/top/width/height are plain CSS
 * percentages against a page container sized from the template's own page_size/orientation -- the
 * exact same coordinate space the browser editor uses, true WYSIWYG by construction.
 *
 * The one real structural difference from Employment Certificate: `earning_lines_all`/
 * `deduction_lines_all`/`statutory_lines_all` are BLOCK fields -- a payslip's line-item breakdown is
 * an open, per-company-growable set (`payroll_earning_deduction_types`), not enumerable at
 * template-design time (see master_payslip_field_types' own migration comment). These need NO new
 * element type or schema of their own -- they're ordinary `text` elements whose content is exactly
 * the single bound token `{{earning_lines_all}}` etc. (the SAME `{{field_key}}` convention every
 * other bound field already uses, and the SAME content-pattern the JS's isBoundFieldElement() heuristic
 * already locks against double-click editing) -- renderElementHtml() below special-cases those 3
 * specific token strings to expand into a real itemized `<table>` instead of doing plain string
 * substitution. Every other field (employee_name, company_name, gross_amount, ...) is a plain
 * one-value token exactly like Employment Certificate's own fields.
 *
 * Reuses the Thai-font/chroot dompdf setup Employment Certificate Template's own renderer already
 * worked out (see that class's docblock for the full "dompdf's bundled DejaVu has ZERO Thai glyphs"
 * finding) -- Payslip PDFs generated through the OLD fixed layout (PaySlipReport::buildHtml(), still
 * used as a fallback when a company has no canvas template saved yet) still go through the shared
 * PdfRendererTrait/DejaVu Sans and are NOT fixed by this class -- same "known gap, not touched here"
 * scoping Employment Certificate Template's own v2 migration comment already established.
 */
class PayslipTemplateRenderer {
    use EmployeePiiTrait;

    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** font_family code => CSS font-family name -- identical set to EmploymentCertificateRenderer's
     *  own FONT_FAMILY_CSS (see that class's comment for why exactly these 7 and not more). */
    private const FONT_FAMILY_CSS = [
        'th_sarabun_new' => 'TH Sarabun New',
        'dejavu_sans' => 'DejaVu Sans',
        'dejavu_sans_mono' => 'DejaVu Sans Mono',
        'dejavu_serif' => 'DejaVu Serif',
        'helvetica' => 'Helvetica',
        'times_new_roman' => 'Times-Roman',
        'courier' => 'Courier',
    ];

    /** 2026-08-26, real bug found and fixed, not a guess: "ใน PDF ไม่แสดง Symbol" -- confirmed by
     *  parsing storage/fonts/thsarabun/THSarabun.ttf's own `cmap` table directly (same verification
     *  method already established in this project for font-coverage claims, see
     *  EmploymentCertificateRenderer's own v2 docblock) that TH Sarabun New has NO glyph for any of
     *  these 22 codepoints from the ribbon's own Symbol picker (stars/checkmarks/arrows/card-suits/
     *  phone-mail-flag dingbats/weather/music notes) -- DejaVu Sans (this project's other bundled
     *  font) covers all 40 of the picker's symbols, confirmed the same way. A Thai-language template
     *  is locked to TH Sarabun New (the only bundled font with Thai glyphs, see
     *  updateFontFamilyOptions()), so dropping one of these symbols onto it looked fine in the browser
     *  canvas (which silently falls back to whatever system font actually has the glyph) but rendered
     *  as nothing at all in the real PDF, since dompdf embeds only the ONE font specified with no
     *  automatic per-glyph fallback the way browsers do. Fixed by detecting this exact situation at
     *  render time and swapping just that one element's PDF font to DejaVu Sans -- the stored
     *  font_family/the canvas UI are both untouched, only the generated PDF's font choice changes. */
    private const SYMBOL_CODEPOINTS_MISSING_IN_SARABUN = [
        0x2605, 0x2606, 0x2713, 0x2714, 0x2717, 0x27A4, 0x2192, 0x2190, 0x2191, 0x2193,
        0x2665, 0x2666, 0x2663, 0x2660, 0x260E, 0x2709, 0x2691, 0x2600, 0x2601, 0x2602,
        0x266A, 0x266B,
    ];

    /** True if $content contains any codepoint TH Sarabun New has no glyph for (see the constant's
     *  own docblock) -- used to force a PDF-only font fallback for exactly those elements. */
    private function needsSymbolFontFallback(string $content): bool {
        if (function_exists('mb_str_split')) {
            foreach (mb_str_split($content, 1, 'UTF-8') as $char) {
                $cp = mb_ord($char, 'UTF-8');
                if ($cp !== false && in_array($cp, self::SYMBOL_CODEPOINTS_MISSING_IN_SARABUN, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** The 3 "block" field_keys that expand into a real itemized table instead of a single
     *  substituted value -- see this class's own docblock for why these need no special schema. */
    private const BLOCK_FIELD_KEYS = ['earning_lines_all', 'deduction_lines_all', 'statutory_lines_all'];

    // 2026-08-26, explicit request: "ตรง Page Setup ให้เพิ่ม A3 A5 และอื่นๆ เหมือนใน Word" -- standard
    // ISO 216 (A3/A5/B4/B5) and ANSI (Tabloid/Executive/Statement) dimensions, portrait orientation
    // (pageDimensionsMm() below swaps width/height for landscape) -- same list, same values as
    // EmploymentCertificateRenderer::PAGE_SIZES_MM.
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

    public static function pageDimensionsMm(string $pageSize, string $orientation): array {
        [$w, $h] = self::PAGE_SIZES_MM[$pageSize] ?? self::PAGE_SIZES_MM['A4'];
        return $orientation === 'landscape' ? [$h, $w] : [$w, $h];
    }

    /** th/en pick -- 2026-08-25 follow-up ("รูปแบบการทำเหมือนกัน"): Payslip Template's `language_mode`
     *  ('both' concatenated "{th} / {en}") is gone, replaced by `language` (strictly 'th' or 'en',
     *  same as Employment Certificate's own `language` column) -- every template is now exactly one
     *  language, so this is a plain either/or pick, no more concatenation branch needed. */
    private function pick(string $th, string $en, string $language): string {
        return $language === 'en' ? $en : $th;
    }

    // 2026-08-26, explicit request: "Format วันที่การแสดงผลทั้งหมดของระบบให้เป็น dd/mm/yyyy" -- the
    // `pay_period`/`payment_date` tokens were embedding raw ISO ('YYYY-MM-DD') dates straight into
    // the generated payslip PDF. Same helper/behavior as EmploymentCertificateRenderer::formatDate()
    // (not shared via a trait since it's a 3-line, no-state-dependency helper -- not worth extracting
    // for a second private copy of this size).
    private function formatDate(?string $ymd): string {
        if (empty($ymd)) {
            return '-';
        }
        $ts = strtotime($ymd);
        return $ts !== false ? date('d/m/Y', $ts) : $ymd;
    }

    private function statutoryLabelMap(string $countryCode): array {
        $stmt = $this->db->prepare("SELECT code, name_th, name_en FROM `statutory_items` WHERE country_code = :cc");
        $stmt->execute([':cc' => $countryCode]);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[$row['code']] = ['th' => $row['name_th'], 'en' => $row['name_en']];
        }
        return $map;
    }

    /** @return array<string,string> field_key => resolved display value, for substituteTokens().
     *  $ytd is null when the template has no ytd_summary field at all (PayrollReportDataModel::
     *  getYtdTotals() is only ever queried when needed, same as the old buildTemplatedHtml() did). */
    public function buildTokens(string $language, array $company, array $run, array $detail, ?array $ytd): array {
        $employeeNameTh = $this->employeeDisplayName($detail, 'th');
        $employeeNameEn = $this->employeeDisplayName($detail, 'en');
        $rawBank = $this->decryptEmployeeField($detail, 'bank_account_no');
        $bankAccountMasked = '-';
        if ($rawBank) {
            $digits = preg_replace('/\D/', '', $rawBank) ?? $rawBank;
            $bankAccountMasked = strlen($digits) > 4 ? str_repeat('•', strlen($digits) - 4) . substr($digits, -4) : $digits;
        }
        $address = trim(($company['address_line_1'] ?? '') . ' ' . ($company['address_line_2'] ?? ''));
        $tokens = [
            'employee_no' => (string)($detail['employee_no'] ?? ''),
            'employee_name' => $this->pick($employeeNameTh, $employeeNameEn, $language),
            'department' => $this->pick((string)($detail['department_name_th'] ?? ''), (string)($detail['department_name_en'] ?? ''), $language) ?: '-',
            'position' => $this->pick((string)($detail['position_name_th'] ?? ''), (string)($detail['position_name_en'] ?? ''), $language) ?: '-',
            'pay_period' => $this->formatDate($run['period_start_date'] ?? null) . ' - ' . $this->formatDate($run['period_end_date'] ?? null),
            'payment_date' => $this->formatDate($run['payment_date'] ?? null),
            'bank_account_masked' => $bankAccountMasked,
            'company_name' => (string)($company['local_name'] ?? $company['company_legal_name'] ?? ''),
            'company_address' => $address,
            'company_tax_id' => (string)($company['global_tax_id'] ?? '-'),
            'company_signatory' => (string)($company['authorized_signatory_name'] ?? '-'),
            'basic_salary' => number_format((float)($detail['base_salary_amount'] ?? 0), 2),
            'gross_amount' => number_format((float)($detail['gross_amount'] ?? 0), 2),
            'total_deduction_amount' => number_format((float)($detail['total_deduction_amount'] ?? 0), 2),
            'net_amount' => number_format((float)($detail['net_amount'] ?? 0), 2),
        ];
        if ($ytd !== null) {
            $gLabel = $this->pick('รายได้สะสม', 'YTD Gross', $language);
            $dLabel = $this->pick('หักสะสม', 'YTD Deduction', $language);
            $nLabel = $this->pick('สุทธิสะสม', 'YTD Net', $language);
            $tokens['ytd_summary'] = sprintf(
                '%s: %s   %s: %s   %s: %s',
                $gLabel, number_format((float)$ytd['ytd_gross'], 2),
                $dLabel, number_format((float)$ytd['ytd_deduction'], 2),
                $nLabel, number_format((float)$ytd['ytd_net'], 2)
            );
        } else {
            $tokens['ytd_summary'] = '-';
        }
        return $tokens;
    }

    private function substituteTokens(string $content, array $tokens): string {
        $escaped = nl2br(htmlspecialchars($content, ENT_QUOTES, 'UTF-8'));
        foreach ($tokens as $key => $value) {
            $escaped = str_replace('{{' . $key . '}}', htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'), $escaped);
        }
        return $escaped;
    }

    /** Renders one of the 3 BLOCK_FIELD_KEYS as a real itemized `<table>` at the bound element's own
     *  position/size/font -- this is the one piece of rendering logic Employment Certificate Template
     *  has no equivalent of at all (see this class's own docblock). */
    private function renderBlockTable(string $fieldKey, array $detail, array $statutoryLabels, string $language, string $style): string {
        $rows = '';
        if ($fieldKey === 'earning_lines_all') {
            foreach (($detail['earning_breakdown'] ?? []) as $line) {
                $label = htmlspecialchars((string)($line['name_th'] ?? $line['code'] ?? ''), ENT_QUOTES, 'UTF-8');
                $rows .= '<tr><td>' . $label . '</td><td class="amount">' . number_format((float)($line['amount'] ?? 0), 2) . '</td></tr>';
            }
        } elseif ($fieldKey === 'deduction_lines_all') {
            foreach (($detail['deduction_breakdown'] ?? []) as $line) {
                $label = htmlspecialchars((string)($line['name_th'] ?? $line['code'] ?? ''), ENT_QUOTES, 'UTF-8');
                $rows .= '<tr><td>' . $label . '</td><td class="amount">' . number_format((float)($line['amount'] ?? 0), 2) . '</td></tr>';
            }
        } elseif ($fieldKey === 'statutory_lines_all') {
            foreach (($detail['statutory_breakdown'] ?? []) as $item) {
                if ((float)($item['employee_amount'] ?? 0) <= 0) continue;
                $names = $statutoryLabels[$item['code']] ?? ['th' => $item['code'], 'en' => $item['code']];
                $label = htmlspecialchars($this->pick($names['th'], $names['en'], $language), ENT_QUOTES, 'UTF-8');
                $rows .= '<tr><td>' . $label . '</td><td class="amount">' . number_format((float)$item['employee_amount'], 2) . '</td></tr>';
            }
        }
        if ($rows === '') {
            return '';
        }
        return '<div style="' . $style . 'overflow:visible;"><table style="width:100%;border-collapse:collapse;font-size:inherit;">' . $rows . '</table></div>';
    }

    /** @param array<int,string> $imageAssetPaths image_asset_id => absolute file path */
    private function renderElementHtml(array $el, array $tokens, ?string $logoAbsPath, array $imageAssetPaths, array $detail, array $statutoryLabels, string $language, ?string $signatureAbsPath = null): string {
        $effectiveFontFamily = $el['font_family'] ?? 'th_sarabun_new';
        if ($effectiveFontFamily === 'th_sarabun_new' && $el['element_type'] === 'text'
            && $this->needsSymbolFontFallback((string)($el['content'] ?? ''))) {
            $effectiveFontFamily = 'dejavu_sans';
        }
        $fontFamily = self::FONT_FAMILY_CSS[$effectiveFontFamily] ?? self::FONT_FAMILY_CSS['th_sarabun_new'];
        // font-family single-quoted -- see EmploymentCertificateRenderer's own comment for the exact
        // double-quote-nesting bug this avoids (a real, confirmed defect found once already).
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
            } elseif (!empty($el['field_key']) && $el['field_key'] === 'company_signature') {
                // 2026-08-26, explicit request: "เพิ่มให้แนบลายเซ็นต์...และเพิ่มใน Item ในการจัดการ
                // Template" -- company-wide only (no per-template override the way logo_path has),
                // resolved straight from companies.signature_path.
                $imgAbsPath = $signatureAbsPath;
            } elseif (!empty($el['image_asset_id']) && isset($imageAssetPaths[(int)$el['image_asset_id']])) {
                $imgAbsPath = $imageAssetPaths[(int)$el['image_asset_id']];
            }
            if ($imgAbsPath === null || !is_file($imgAbsPath)) {
                return '';
            }
            return '<div style="' . $style . '"><img src="' . htmlspecialchars($imgAbsPath, ENT_QUOTES, 'UTF-8') . '" style="max-width:100%;max-height:100%;"></div>';
        }
        if ($el['element_type'] === 'shape') {
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
        // text -- the 3 BLOCK_FIELD_KEYS are ordinary bound {{token}}-only text elements (see this
        // class's own docblock) that expand into a real table instead of plain substitution.
        $trimmed = trim((string)($el['content'] ?? ''));
        foreach (self::BLOCK_FIELD_KEYS as $blockKey) {
            if ($trimmed === '{{' . $blockKey . '}}') {
                return $this->renderBlockTable($blockKey, $detail, $statutoryLabels, $language, $style);
            }
        }
        return '<div style="' . $style . '">' . $this->substituteTokens((string)($el['content'] ?? ''), $tokens) . '</div>';
    }

    /** @param array{page_size?:string, orientation?:string} $template */
    public function buildHtml(array $template, array $elements, array $company, array $run, array $detail, array $statutoryLabels, ?string $logoAbsPath, array $imageAssetPaths, ?array $ytd, ?string $watermarkText = null): string {
        $language = (string)($template['language'] ?? 'th');
        $tokens = $this->buildTokens($language, $company, $run, $detail, $ytd);
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
            $watermarkHtml = '<div style="position:absolute;top:50%;left:0;width:100%;text-align:center;overflow:visible;">'
                . '<div style="display:inline-block;transform:rotate(-35deg);transform-origin:center;'
                . "font-size:64px;color:rgba(150,150,150,0.35);font-family:'TH Sarabun New',sans-serif;"
                . 'white-space:nowrap;font-weight:bold;">' . htmlspecialchars(trim($watermarkText), ENT_QUOTES, 'UTF-8') . '</div></div>';
        }

        $signatureAbsPath = $this->resolveUploadAbsPath($company['signature_path'] ?? null, 'company_signatures');

        $pageKeys = array_keys($byPage);
        $lastPageKey = end($pageKeys);
        $pagesHtml = '';
        foreach ($byPage as $pageNumber => $pageElements) {
            $body = '';
            foreach ($pageElements as $el) {
                // 2026-08-26, explicit request: "ตรง Layer ให้มี function เปิด/ปิดตาได้ แทนการที่ต้องลบ
                // อย่างเดียว" -- same as EmploymentCertificateRenderer's own fix: a hidden element is
                // skipped from the actual generated payslip too, not just the canvas. Defaults to
                // visible when absent (old rows/tests saved before this column existed).
                if (array_key_exists('is_visible', $el) && !$el['is_visible']) {
                    continue;
                }
                $body .= $this->renderElementHtml($el, $tokens, $logoAbsPath, $imageAssetPaths, $detail, $statutoryLabels, $language, $signatureAbsPath);
            }
            $body .= $watermarkHtml;
            $breakStyle = $pageNumber === $lastPageKey ? '' : 'page-break-after:always;';
            $pagesHtml .= '<div class="payslip-page" style="' . $breakStyle . '">' . $body . '</div>';
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'
            . "@page { size: {$pageW}mm {$pageH}mm; margin: 0; }"
            . 'body { margin:0; padding:0; font-family: "TH Sarabun New", sans-serif; }'
            . ".payslip-page { position: relative; width: {$pageW}mm; height: {$pageH}mm; overflow: hidden; }"
            . '.amount { text-align: right; }'
            . '</style></head><body>' . $pagesHtml . '</body></html>';
    }

    /** Same TH Sarabun New registration + widened chroot as EmploymentCertificateRenderer -- see that
     *  class's own docblock for the full "dompdf's bundled DejaVu has ZERO Thai glyphs" finding this
     *  fixes, and why the chroot needs widening (font registration + local <img> embeds both fail
     *  dompdf's default vendor-only chroot otherwise). */
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
        $ptPerMm = 72 / 25.4;
        $dompdf->setPaper([0, 0, $pageW * $ptPerMm, $pageH * $ptPerMm]);
        $dompdf->render();
        return $dompdf->output();
    }

    /** Resolves a value already validated as `public/uploads/{$subdir}/{comp_id}/{hash}.{ext}` to a
     *  traversal-safe absolute path, or null if unset/missing on disk. */
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

    /** Template's own logo first, falling back to the Company Profile logo -- same fallback pattern
     *  as EmploymentCertificateRenderer/the old PaySlipReport::resolveTemplateOrCompanyLogo(). */
    public function resolveTemplateOrCompanyLogo(?string $templateLogoPath, ?string $companyLogoPath): ?string {
        $own = $this->resolveUploadAbsPath($templateLogoPath, 'payslip_logos');
        if ($own !== null) {
            return $own;
        }
        return $this->resolveUploadAbsPath($companyLogoPath, 'company_logos');
    }

    /** @param int[] $imageAssetIds
     *  @return array<int,string> image_asset_id => resolved absolute path, only for ids belonging to
     *  this company and present on disk. */
    public function resolveImageAssetPaths(int $compId, array $imageAssetIds): array {
        $imageAssetIds = array_values(array_unique(array_map('intval', array_filter($imageAssetIds))));
        if (empty($imageAssetIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($imageAssetIds), '?'));
        $stmt = $this->db->prepare("SELECT id, file_path FROM `payslip_images` WHERE comp_id = ? AND id IN ({$placeholders})");
        $stmt->execute(array_merge([$compId], $imageAssetIds));
        $paths = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $abs = $this->resolveUploadAbsPath($row['file_path'], 'payslip_images');
            if ($abs !== null) {
                $paths[(int)$row['id']] = $abs;
            }
        }
        return $paths;
    }

    /** Full render for a real payroll run + employee -- called by PaySlipReport::generate() once it
     *  has resolved the company's default template + real run/detail data. */
    public function renderForRun(int $compId, array $template, array $elements, array $company, array $run, array $detail, ?array $ytd): string {
        $statutoryLabels = $this->statutoryLabelMap((string)($template['country_code'] ?? $company['registered_country'] ?? ''));
        $imageAssetIds = array_map(fn($el) => (int)($el['image_asset_id'] ?? 0), $elements);
        $imageAssetPaths = $this->resolveImageAssetPaths($compId, $imageAssetIds);
        $logoAbsPath = $this->resolveTemplateOrCompanyLogo($template['logo_path'] ?? null, $company['logo_path'] ?? null);
        $html = $this->buildHtml($template, $elements, $company, $run, $detail, $statutoryLabels, $logoAbsPath, $imageAssetPaths, $ytd, null);
        return $this->renderPdf($template, $html);
    }

    /** Renders the editor's CURRENT (possibly unsaved) canvas state against real company data + a
     *  mock run/employee -- nothing here is persisted. Mirrors EmploymentCertificateRenderer::
     *  renderPreview()'s own mock-data approach. */
    public function renderPreview(int $compId, array $template, array $elements, ?string $logoPath, ?string $watermarkText = null): string {
        $stmt = $this->db->prepare("SELECT * FROM `companies` WHERE id = :id");
        $stmt->execute([':id' => $compId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) {
            throw new RuntimeException('Company not found.');
        }
        $countryCode = (string)($company['registered_country'] ?? 'TH');
        $stmtS = $this->db->prepare("SELECT code FROM `statutory_items` WHERE country_code = :cc ORDER BY sort_order ASC LIMIT 2");
        $stmtS->execute([':cc' => $countryCode]);
        $statutoryCodes = array_column($stmtS->fetchAll(PDO::FETCH_ASSOC), 'code');
        if (empty($statutoryCodes)) {
            $statutoryCodes = ['SAMPLE_STATUTORY'];
        }
        $run = ['id' => 0, 'comp_id' => $compId, 'period_start_date' => date('Y-m-01'), 'period_end_date' => date('Y-m-t'), 'payment_date' => date('Y-m-d')];
        $basicSalary = 30000.00;
        $earningBreakdown = [
            ['code' => 'OT', 'name_th' => 'ค่าล่วงเวลา (ตัวอย่าง)', 'amount' => 1500.00],
            ['code' => 'TRIP', 'name_th' => 'ค่าเที่ยว (ตัวอย่าง)', 'amount' => 800.00],
        ];
        $deductionBreakdown = [['code' => 'LOAN', 'name_th' => 'เงินกู้พนักงาน (ตัวอย่าง)', 'amount' => 500.00]];
        $statutoryBreakdown = [];
        $statutoryTotal = 0.0;
        foreach ($statutoryCodes as $i => $code) {
            $amount = $i === 0 ? 750.00 : 200.00;
            $statutoryBreakdown[] = ['code' => $code, 'employee_amount' => $amount];
            $statutoryTotal += $amount;
        }
        $grossAmount = $basicSalary + array_sum(array_column($earningBreakdown, 'amount'));
        $totalDeduction = array_sum(array_column($deductionBreakdown, 'amount')) + $statutoryTotal;
        $netAmount = $grossAmount - $totalDeduction;
        $encryptedBank = EncryptionService::encrypt('1234567890');
        $detail = [
            'comp_id' => $compId, 'employee_id' => 0, 'employee_no' => 'EMP-0001',
            'name_th' => 'สมชาย', 'surname_th' => 'ใจดี', 'name_en' => 'Somchai', 'surname_en' => 'Jaidee',
            'department_name_th' => 'ฝ่ายทรัพยากรบุคคล', 'department_name_en' => 'Human Resources',
            'position_name_th' => 'เจ้าหน้าที่อาวุโส', 'position_name_en' => 'Senior Officer',
            'base_salary_amount' => $basicSalary,
            'earning_breakdown' => $earningBreakdown, 'deduction_breakdown' => $deductionBreakdown, 'statutory_breakdown' => $statutoryBreakdown,
            'gross_amount' => $grossAmount, 'total_deduction_amount' => $totalDeduction, 'net_amount' => $netAmount,
            'bank_account_no' => $encryptedBank['value'] ?? null, 'key_version' => $encryptedBank['key_version'] ?? null,
        ];
        $ytd = null;
        foreach ($elements as $el) {
            if (($el['element_type'] ?? '') === 'text' && trim((string)($el['content'] ?? '')) === '{{ytd_summary}}') {
                $ytd = ['ytd_gross' => $grossAmount * 3, 'ytd_deduction' => $totalDeduction * 3, 'ytd_net' => $netAmount * 3];
                break;
            }
        }
        $mergedTemplate = array_merge($template, ['country_code' => $countryCode]);
        $statutoryLabels = $this->statutoryLabelMap($countryCode);
        $imageAssetIds = array_map(fn($el) => (int)($el['image_asset_id'] ?? 0), $elements);
        $imageAssetPaths = $this->resolveImageAssetPaths($compId, $imageAssetIds);
        $logoAbsPath = $this->resolveTemplateOrCompanyLogo($logoPath, $company['logo_path'] ?? null);
        $html = $this->buildHtml($mergedTemplate, $elements, $company, $run, $detail, $statutoryLabels, $logoAbsPath, $imageAssetPaths, $ytd, $watermarkText);
        return $this->renderPdf($template, $html);
    }
}
