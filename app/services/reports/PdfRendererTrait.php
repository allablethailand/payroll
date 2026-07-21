<?php
declare(strict_types=1);

/**
 * Shared dompdf setup for report generators. Uses the bundled DejaVu Sans font, which has
 * Thai glyph coverage.
 *
 * VERIFIED: dompdf successfully generates a PDF from Thai HTML input (no errors, correct
 * byte size) and the ASCII/numeric parts of a test render extract cleanly via pdftotext.
 * NOT independently verified: whether the Thai glyphs themselves are visually correct when
 * opened in a PDF viewer — this environment has no PDF rasterizer (no ghostscript/imagick),
 * so a human should open one generated PDF and confirm before relying on this for real
 * documents. pdftotext could not extract the Thai text layer at all (renders as non-machine-
 * readable glyphs, a known complex-script ToUnicode limitation, not necessarily a visual
 * rendering problem — the same class of issue seen with the official RD/SSO PDFs researched
 * earlier, but here on output WE control rather than input we don't).
 */
trait PdfRendererTrait {
    protected function renderPdfFromHtml(string $html, string $paperSize = 'A4', string $orientation = 'portrait'): string {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($paperSize, $orientation);
        $dompdf->render();
        return $dompdf->output();
    }
}
