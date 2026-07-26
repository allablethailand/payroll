<?php
declare(strict_types=1);

/**
 * Contract every payroll report must implement (statutory/payment/internal alike). Adding a
 * new report means adding one new class that implements this interface and registering it
 * in ReportRegistry — no changes to the engine, controller, or existing reports.
 */
interface ReportGeneratorInterface {
    /** Unique code, e.g. 'TH_PND1K_SUMMARY', 'PAY_SLIP', 'PAYROLL_REGISTER'. */
    public function code(): string;

    /** 'statutory' | 'payment' | 'internal' — matches report_export_logs.report_type. */
    public function reportType(): string;

    /** Human-readable label, keyed by 'th'/'en'. */
    public function label(): array;

    /**
     * Whether this report's output has been confirmed against an official government/bank
     * spec (for reports that claim to represent one) — false means best-effort/DRAFT, and the
     * UI must surface that before anyone relies on it for a real filing/submission. Reports
     * that are purely this system's own layout (no external spec claimed) return true.
     */
    public function isVerified(): bool;

    /** Formats this report can produce, e.g. ['pdf','excel'] or ['txt']. */
    public function supportedFormats(): array;

    /**
     * Build the report file.
     * @param array $context Report-specific input — see each implementation's doc block
     *        for the exact shape it expects (comp_id, run_id, period, employee_id, etc.).
     * @param string $format One of supportedFormats().
     * @return array{content:string, file_name:string, mime_type:string}
     */
    public function generate(array $context, string $format): array;
}
