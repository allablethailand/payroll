<?php
declare(strict_types=1);

/**
 * Shared infrastructure for `PayslipTemplateRenderer`/`EmploymentCertificateRenderer` -- the two
 * canvas-designer PDF renderers built as direct architectural mirrors of each other (see each
 * class's own docblock). Backlog Phase 11, T064 (part 1 of 4: unify Slip/document settings pages,
 * consolidate duplicated code where possible).
 *
 * Every method here was verified BODY-FOR-BODY identical between the two classes before extraction
 * (not just name-matched) -- pure mechanical/infrastructure logic with zero Payslip-vs-Certificate
 * business rules: dompdf setup (font registration, chroot widening), page-size math, a font-fallback
 * detection table, and a traversal-safe upload-path resolver. Real payoff: CLAUDE.md documents the
 * Thai-font/chroot setup here had a real bug found and fixed ONCE for
 * EmploymentCertificateRenderer, then had to be manually re-applied to PayslipTemplateRenderer
 * separately when it was built later -- a shared trait means a future fix to this infrastructure
 * only ever needs to happen once.
 *
 * Deliberately does NOT include `formatDate()` -- PayslipTemplateRenderer's own docblock already
 * documents an explicit prior decision not to share it ("not shared via a trait since it's a
 * 3-line, no-state-dependency helper -- not worth extracting for a second private copy of this
 * size"), and `buildTokens()`/`renderElementHtml()`/`substituteTokens()` etc. are NOT here because
 * they carry real, documented, deliberate Payslip-vs-Certificate differences (block-field-to-table
 * expansion, is_default/language_mode-adjacent fields, request/issuance flow) -- see the T064 audit
 * report / this project's own backlog memory for the full list of what was found NOT safe to merge.
 *
 * `resolveImageAssetPaths()` is the one method here that is NOT byte-identical between the two
 * classes -- both query a company-scoped image-library table and resolve each row's file path the
 * same way, but the TABLE name and upload SUBDIRECTORY differ per class
 * (`payslip_images`/`payslip_images` vs `employment_certificate_images`/`employment_cert_images`).
 * Rather than silently forcing those two real per-class facts into one hardcoded value, the trait
 * declares 2 `abstract` method requirements (`imageLibraryTableName()`/`imageLibraryUploadSubdir()`)
 * that each using class MUST implement -- a class that forgets either one gets a hard PHP fatal
 * error at load time, not silently wrong behavior.
 *
 * `__DIR__`-based path resolution inside a PHP trait resolves to the TRAIT FILE's own location, not
 * the using class's -- this file deliberately lives in `app/services/` (the exact same directory as
 * both `PayslipTemplateRenderer.php`/`EmploymentCertificateRenderer.php`) so every relative path
 * computed here (`__DIR__ . '/../../storage/fonts/thsarabun'`, `__DIR__ . '/../../public/uploads/...'`)
 * resolves identically to what each class's own original copy of these methods already computed.
 * Do NOT move this trait file to a different directory without re-deriving every `__DIR__` use below.
 */
trait PdfCanvasRendererTrait {
    /** page_size => [width_mm, height_mm] in PORTRAIT orientation; swapped for landscape by
     *  pageDimensionsMm() below. Both using classes still declare their own PAGE_SIZES_MM constant
     *  (identical 10-entry values) -- PHP trait methods resolve `self::` against whichever class
     *  actually uses the trait, so this stays correct without moving the constant itself here. */
    public static function pageDimensionsMm(string $pageSize, string $orientation): array {
        [$w, $h] = self::PAGE_SIZES_MM[$pageSize] ?? self::PAGE_SIZES_MM['A4'];
        return $orientation === 'landscape' ? [$h, $w] : [$w, $h];
    }

    /** True if $content contains any codepoint TH Sarabun New has no glyph for (confirmed by
     *  directly parsing storage/fonts/thsarabun/THSarabun.ttf's own `cmap` table -- see each using
     *  class's own SYMBOL_CODEPOINTS_MISSING_IN_SARABUN constant, also still declared per-class for
     *  the same `self::`-resolution reason as PAGE_SIZES_MM above) -- used to force a PDF-only font
     *  fallback for exactly those elements. */
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

    /**
     * Registers TH Sarabun New (4 styles, bundled under storage/fonts/thsarabun/ -- see that
     * directory's own NOTICE.md for why DejaVu Sans, dompdf's default, can't be used for Thai at
     * all) with a Dompdf instance's font metrics. Must run BEFORE loadHtml()/render().
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

    /** New Dompdf instance with TH Sarabun New registered + a widened chroot (dompdf's default
     *  chroot only covers its own vendor directory, which blocks both font registration
     *  (storage/fonts/) and local <img> embeds (public/uploads/...) otherwise) -- safe because
     *  isRemoteEnabled stays false (blocks remote http/https regardless) and every local path
     *  actually reaching this renderer is already independently validated by the caller before it
     *  gets anywhere near here, never raw user input. */
    private function newDompdf(): \Dompdf\Dompdf {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->setChroot([realpath(__DIR__ . '/../../')]);
        $dompdf = new \Dompdf\Dompdf($options);
        $this->registerThaiFonts($dompdf);
        return $dompdf;
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

    /** DB table holding this renderer's own reusable, per-company image library rows (e.g.
     *  `payslip_images` / `employment_certificate_images`) -- required by resolveImageAssetPaths()
     *  below, since that's the one real per-class fact this shared method can't infer on its own. */
    abstract protected function imageLibraryTableName(): string;

    /** `public/uploads/{subdir}` this renderer's own image library files are stored under (e.g.
     *  `payslip_images` / `employment_cert_images` -- note ECT's own subdir is NOT the same string
     *  as its table name, a real, easy-to-miss distinction preserved exactly as each class already
     *  had it, not flattened to match the table name). */
    abstract protected function imageLibraryUploadSubdir(): string;

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
        $table = $this->imageLibraryTableName();
        $stmt = $this->db->prepare("SELECT id, file_path FROM `{$table}` WHERE comp_id = ? AND id IN ({$placeholders})");
        $stmt->execute(array_merge([$compId], $imageAssetIds));
        $paths = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $abs = $this->resolveUploadAbsPath($row['file_path'], $this->imageLibraryUploadSubdir());
            if ($abs !== null) {
                $paths[(int)$row['id']] = $abs;
            }
        }
        return $paths;
    }
}
