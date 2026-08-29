<?php
declare(strict_types=1);

/**
 * Shared dompdf setup for report generators (PND1/PND1K/SSO110/SSO609/Kor20Kor/StudentLoanReport/
 * PaySlipReport's fallback layout/PaymentVoucherReport — every ReportGeneratorInterface class that
 * `use`s this trait).
 *
 * 2026-08-29, real bug found and fixed (explicit bug report: "ในหน้า Report Print แล้วข้อมูลยังไม่ออก
 * และไม่รองรับภาษาไทยหรือเปล่าเป็นภาษาเอเลี่ยน" -- generated reports don't show data / Thai looks like
 * alien characters). This trait used to default to dompdf's bundled DejaVu Sans and claimed (in this
 * same docblock) that it "has Thai glyph coverage" — that claim was never actually verified and was
 * WRONG: `EmploymentCertificateRenderer`'s own v2 work (2026-08-24) later parsed DejaVu Sans/Serif/
 * Sans Mono's `cmap` tables directly and found ZERO Thai glyphs in any of them. That fix was applied
 * to `EmploymentCertificateRenderer`/`PayslipTemplateRenderer` only at the time (explicitly flagged as
 * "out of scope" for this shared trait in both their docblocks) — every report generator still
 * routing through THIS trait kept silently rendering blank/tofu boxes for every Thai character ever
 * since, which is exactly "ข้อมูลยังไม่ออก" (Thai-heavy report content reads as empty) + "เป็นภาษาเอเลี่ยน"
 * (the few glyphs dompdf does substitute look nothing like Thai).
 *
 * Fixed the same way, reusing the SAME bundled font files (`storage/fonts/thsarabun/`, TH Sarabun
 * New — 4 styles) and the exact same `registerFont()`/chroot pattern already proven working in
 * `EmploymentCertificateRenderer::registerThaiFonts()`/`newDompdf()` — see that class's own docblock
 * for the full root-cause chain (file:// URI requirement, chroot widening) this ports verbatim.
 * `defaultFont` changed from 'DejaVu Sans' to 'TH Sarabun New' so every existing caller (none of
 * which set an explicit font-family in their own HTML) picks it up with zero changes on their side.
 */
trait PdfRendererTrait {
    private function registerThaiFontsForReport(\Dompdf\Dompdf $dompdf): void {
        $fontMetrics = $dompdf->getFontMetrics();
        $dir = realpath(__DIR__ . '/../../../storage/fonts/thsarabun');
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

    protected function renderPdfFromHtml(string $html, string $paperSize = 'A4', string $orientation = 'portrait'): string {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'TH Sarabun New');
        // Default chroot only covers dompdf's own vendor directory, which blocks registerFont()
        // from reading storage/fonts/ (and would block any local <img> embed a report ever adds)
        // -- same widening EmploymentCertificateRenderer/PayslipTemplateRenderer already use.
        $options->setChroot([realpath(__DIR__ . '/../../../')]);
        $dompdf = new \Dompdf\Dompdf($options);
        $this->registerThaiFontsForReport($dompdf);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($paperSize, $orientation);
        $dompdf->render();
        return $dompdf->output();
    }
}
