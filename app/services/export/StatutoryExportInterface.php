<?php
declare(strict_types=1);

/**
 * Contract every government statutory export format must implement (e.g. TH ภ.ง.ด.1ก,
 * TH สปส.1-10, and any future SG/MY/US format). Adding a new format means adding one new
 * class that implements this interface and registering it in StatutoryExportRegistry — no
 * changes to the engine or existing exporters.
 */
interface StatutoryExportInterface {
    /** Unique code, e.g. 'TH_PND1K', 'TH_SSO110'. */
    public function code(): string;

    /** ISO country code this format applies to, e.g. 'TH'. */
    public function countryCode(): string;

    /** Human-readable label, keyed by 'th'/'en'. */
    public function label(): array;

    /**
     * Whether the exact field layout has been confirmed against an official government
     * document. False means the implementation is a best-effort draft — callers (and the
     * UI, once built) must surface this before anyone relies on the output for a real filing.
     */
    public function isVerified(): bool;

    /** Suggested file name for the generated export, e.g. 'PND1K_202607.txt'. */
    public function fileName(array $context): string;

    /**
     * Build the raw file content (already in the target byte encoding) for this format.
     * @param array $context Structured, format-specific input — see each implementation's
     *        doc block for the exact shape it expects (company info, period, employee rows).
     */
    public function generate(array $context): string;
}
