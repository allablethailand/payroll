# TH Sarabun New

Bundled here for use by `EmploymentCertificateRenderer` (registered with dompdf at render time via
`Dompdf\FontMetrics::registerFont()`), because the fonts already bundled with dompdf
(`vendor/dompdf/dompdf/lib/fonts/DejaVuSans*.ttf` etc.) have **no Thai glyphs at all** — confirmed
by parsing their `cmap` tables directly (2026-08-24), not an assumption. Every Thai PDF this app has
generated via the shared `PdfRendererTrait` default font was very likely rendering blank/missing
glyphs wherever Thai text appeared; see `CLAUDE.md`'s Employment Certificate section for the full
finding, and revisit `PdfRendererTrait`'s own default font choice separately since it's shared by
the Reports/Payslip modules too.

TH Sarabun New is the Thai government's standard document font (SIPA), released under the SIL Open
Font License 1.1 — free to embed/redistribute. These 4 files were copied from a local Windows
installation's font cache as the source; if redistributing this repository, verify you have your
own legitimate copy of the SIL-OFL-licensed release (e.g. from the official Thai Fonts Consortium /
SIPA distribution) rather than relying on this copy's provenance being re-verified.

**Not bundled, on purpose:** Tahoma and Leelawadee (also confirmed via the same cmap check to have
full Thai coverage) were considered but excluded — both are Microsoft-owned commercial fonts, not
freely redistributable, so they must not be copied into this repository.
