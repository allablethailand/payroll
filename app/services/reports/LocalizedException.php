<?php
declare(strict_types=1);

/**
 * Carries an i18n-friendly error_key + params alongside the usual English getMessage() fallback,
 * so callers/tests that only know about getMessage() keep working unchanged (additive, not a
 * breaking change to the existing exception-message convention).
 *
 * Frontend looks up langData['error_' + errorKey], substitutes {param} placeholders, and falls
 * back to the raw English message if the key doesn't exist yet -- see translateApiError() in
 * public/js/app.js. A param value that's an array is treated as a list of payroll_runs.state
 * codes and translated per-element via the existing state_{code} lang keys before joining.
 *
 * Scoped to app/services/reports/** for this pass -- SetupRulesModel/PayslipTemplateModel/
 * ApprovalWorkflowModel have the same hardcoded-English-message problem but weren't in scope
 * (see project memory follow-up note). Nothing stops them from reusing this class later.
 */
class LocalizedException extends RuntimeException {
    private string $errorKey;
    private array $params;

    public function __construct(string $englishMessage, string $errorKey, array $params = []) {
        parent::__construct($englishMessage);
        $this->errorKey = $errorKey;
        $this->params = $params;
    }

    public function getErrorKey(): string {
        return $this->errorKey;
    }

    public function getParams(): array {
        return $this->params;
    }
}
