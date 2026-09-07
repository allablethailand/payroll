<?php
/**
 * Lightweight verification script for Platform Hardening Phase 6, batch 5: extends AuditLogModel
 * wiring to the "Document/Master-data models" group named as remaining candidates in batch 4's own
 * status report -- DocumentNumberingModel, StatutoryFormatVersionModel, PayslipTemplateModel,
 * EmploymentCertificateTemplateModel.
 *
 * PayslipTemplateModel/EmploymentCertificateTemplateModel are canvas designers -- only each
 * template's own HEADER row (template_name/page_size/orientation/margin_mm/is_default/status/
 * publish_status/header-footer text) is diffed, same "don't diff child-table replace-semantics"
 * precedent this pilot already established for AttendanceDeductionRuleModel's brackets (batch 1) and
 * ApprovalWorkflowModel's steps (batch 2) -- the elements/assignments arrays (both DELETE+INSERT on
 * every save) are intentionally NOT diffed.
 *
 * StatutoryFormatVersionModel::saveSelection() needed its own tiny fixture: the real dev DB only has
 * exactly ONE active version per real form_code (TH_PND1/TH_SSO110), so there's no way to exercise a
 * genuine version_id CHANGE against real government-form data without inserting a second real
 * version row for one of those forms -- instead this test creates its OWN fake form_code with 2
 * versions, entirely test-owned, never touching TH_PND1/TH_SSO110's real rows (same "don't touch
 * real production reference data, even transactionally" precedent as batch 4's own master
 * statutory_items fixture).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back. Every fixture uses its OWN freshly-created company (same
 * makeCompany() pattern as every prior batch) -- zero dev-DB contamination risk.
 *
 * Run with: php tests/audit_log_batch5_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/AuditLogModel.php';
require_once __DIR__ . '/../app/models/DocumentNumberingModel.php';
require_once __DIR__ . '/../app/models/StatutoryFormatVersionModel.php';
require_once __DIR__ . '/../app/models/PayslipTemplateModel.php';
require_once __DIR__ . '/../app/models/EmploymentCertificateTemplateModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    if ($actual === $expected) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }

function makeCompany(PDO $pdo, string $countryCode): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function auditRowsFor(PDO $pdo, int $compId, string $tableName, int $recordId): array {
    $stmt = $pdo->prepare("SELECT * FROM audit_logs WHERE comp_id = :comp_id AND table_name = :table_name AND record_id = :record_id ORDER BY id ASC");
    $stmt->execute([':comp_id' => $compId, ':table_name' => $tableName, ':record_id' => $recordId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $userId = (int)$pdo->query("SELECT id FROM employees ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($userId <= 0) {
        throw new RuntimeException('No employee row exists in this dev DB to use as a valid created_by/updated_by FK value.');
    }
    $compId = makeCompany($pdo, 'TH');

    // ================= DocumentNumberingModel =================
    echo "=== DocumentNumberingModel::save() ===\n";
    $docModel = new DocumentNumberingModel($pdo);
    // ensureSeeded()'s own auto-INSERT (a create) runs invisibly on first list()/save() call --
    // never logged (no-CREATE-logging convention). The row this test's own save() then touches is
    // that already-seeded row, so THIS save() is a genuine UPDATE from the very first call.
    $docSave1 = $docModel->save($compId, 'PAYSLIP', [
        'prefix_format' => 'PS-{YYYY}{MM}-', 'digit_count' => 4, 'current_number' => 0, 'reset_cycle' => 'monthly',
    ], $userId, '10.4.0.1', 'TestAgent/1.0');
    checkTrue('save PAYSLIP numbering (against the auto-seeded default row)' . (empty($docSave1['status']) ? " ({$docSave1['message']})" : ''), $docSave1['status']);
    $docRow = $pdo->prepare("SELECT id FROM document_numbering_settings WHERE comp_id = :comp_id AND document_type_code = 'PAYSLIP'");
    $docRow->execute([':comp_id' => $compId]);
    $docId = (int)$docRow->fetchColumn();
    checkTrue('fixture: document_numbering_settings row resolved', $docId > 0);

    $docSave2 = $docModel->save($compId, 'PAYSLIP', [
        'prefix_format' => 'PAYSLIP-{YYYY}-', 'digit_count' => 6, 'current_number' => 10, 'reset_cycle' => 'yearly',
    ], $userId, '10.4.0.2', 'TestAgent/1.0');
    checkTrue('save PAYSLIP numbering again (genuine change)' . (empty($docSave2['status']) ? " ({$docSave2['message']})" : ''), $docSave2['status']);
    $docFields = array_column(auditRowsFor($pdo, $compId, 'document_numbering_settings', $docId), 'field_name');
    checkTrue('prefix_format change logged', in_array('prefix_format', $docFields, true));
    checkTrue('digit_count change logged', in_array('digit_count', $docFields, true));
    checkTrue('reset_cycle change logged', in_array('reset_cycle', $docFields, true));
    $prefixRow = current(array_filter(auditRowsFor($pdo, $compId, 'document_numbering_settings', $docId), fn($r) => $r['field_name'] === 'prefix_format'));
    check('prefix_format new_value matches', $prefixRow['new_value'] ?? null, 'PAYSLIP-{YYYY}-');
    check('prefix_format old_value matches the first save', $prefixRow['old_value'] ?? null, 'PS-{YYYY}{MM}-');
    check('ip_address recorded', $prefixRow['ip_address'] ?? null, '10.4.0.2');

    // ================= StatutoryFormatVersionModel =================
    echo "\n=== StatutoryFormatVersionModel::saveSelection() ===\n";
    // Test-owned fake form_code, 2 versions -- never touches TH_PND1/TH_SSO110's real rows (the real
    // dev DB has only 1 active version per real form_code, no way to exercise a genuine change there).
    $fakeFormCode = 'TEST_FORM_' . substr(uniqid(), -8);
    $pdo->prepare("INSERT INTO master_statutory_format_versions (form_code, version_code, name_th, name_en, is_active) VALUES (:code, 'v1', 'ทดสอบ 1', 'Test 1', 1)")
        ->execute([':code' => $fakeFormCode]);
    $versionAId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO master_statutory_format_versions (form_code, version_code, name_th, name_en, is_active) VALUES (:code, 'v2', 'ทดสอบ 2', 'Test 2', 1)")
        ->execute([':code' => $fakeFormCode]);
    $versionBId = (int)$pdo->lastInsertId();

    $sfvModel = new StatutoryFormatVersionModel($pdo);
    $sfvSave1 = $sfvModel->saveSelection($compId, $fakeFormCode, $versionAId, $userId, '10.4.0.3', 'TestAgent/1.0');
    checkTrue('first selection (create branch)' . (empty($sfvSave1['status']) ? " ({$sfvSave1['message']})" : ''), $sfvSave1['status']);
    $sfvRow = $pdo->prepare("SELECT id FROM company_statutory_format_settings WHERE comp_id = :comp_id AND form_code = :form_code");
    $sfvRow->execute([':comp_id' => $compId, ':form_code' => $fakeFormCode]);
    $sfvId = (int)$sfvRow->fetchColumn();
    checkTrue('fixture: company_statutory_format_settings row resolved', $sfvId > 0);
    check('create branch is not audited', count(auditRowsFor($pdo, $compId, 'company_statutory_format_settings', $sfvId)), 0);

    $sfvSave2 = $sfvModel->saveSelection($compId, $fakeFormCode, $versionBId, $userId, '10.4.0.4', 'TestAgent/1.0');
    checkTrue('second selection (update branch, genuine version change)' . (empty($sfvSave2['status']) ? " ({$sfvSave2['message']})" : ''), $sfvSave2['status']);
    $sfvVersionRow = current(array_filter(auditRowsFor($pdo, $compId, 'company_statutory_format_settings', $sfvId), fn($r) => $r['field_name'] === 'version_id'));
    checkTrue('version_id change logged on the update branch', $sfvVersionRow !== false);
    check('old_value is version A', (int)($sfvVersionRow['old_value'] ?? 0), $versionAId);
    check('new_value is version B', (int)($sfvVersionRow['new_value'] ?? 0), $versionBId);

    // Re-selecting the SAME version produces zero new diff rows (AuditLogModel's own unchanged-field
    // skip, not a bug in this wiring).
    $rowCountBefore = count(auditRowsFor($pdo, $compId, 'company_statutory_format_settings', $sfvId));
    $sfvSave3 = $sfvModel->saveSelection($compId, $fakeFormCode, $versionBId, $userId, '10.4.0.5', 'TestAgent/1.0');
    checkTrue('re-selecting the same version still succeeds', $sfvSave3['status']);
    check('no new rows logged for a no-op re-selection', count(auditRowsFor($pdo, $compId, 'company_statutory_format_settings', $sfvId)), $rowCountBefore);

    // ================= PayslipTemplateModel =================
    echo "\n=== PayslipTemplateModel: save()/toggleStatus()/setDefault()/setPublishStatus()/delete() (header row only) ===\n";
    $pstModel = new PayslipTemplateModel($pdo);
    $pstCreate = $pstModel->save($compId, [
        'language' => 'th', 'template_name' => 'Audit Batch5 Test Payslip', 'page_size' => 'A4', 'orientation' => 'portrait',
    ], $userId);
    checkTrue('create payslip template' . (empty($pstCreate['status']) ? " ({$pstCreate['message']})" : ''), $pstCreate['status']);
    $pstId = (int)($pstCreate['template_id'] ?? 0);
    checkTrue('fixture: payslip template id resolved', $pstId > 0);
    check('create branch is not audited', count(auditRowsFor($pdo, $compId, 'payslip_templates', $pstId)), 0);

    $pstUpdate = $pstModel->save($compId, [
        'id' => $pstId, 'language' => 'th', 'template_name' => 'Audit Batch5 Test Payslip RENAMED', 'page_size' => 'Letter', 'orientation' => 'landscape',
    ], $userId, '10.4.0.6', 'TestAgent/1.0');
    checkTrue('update payslip template header' . (empty($pstUpdate['status']) ? " ({$pstUpdate['message']})" : ''), $pstUpdate['status']);
    $pstFields = array_column(auditRowsFor($pdo, $compId, 'payslip_templates', $pstId), 'field_name');
    checkTrue('template_name change logged', in_array('template_name', $pstFields, true));
    checkTrue('page_size change logged', in_array('page_size', $pstFields, true));
    checkTrue('orientation change logged', in_array('orientation', $pstFields, true));
    checkTrue('elements/assignments are NOT diffable fields on this table (only header columns exist here)', !in_array('elements', $pstFields, true));

    $pstToggle = $pstModel->toggleStatus($compId, $pstId, $userId, '10.4.0.7', 'TestAgent/1.0');
    checkTrue('toggle payslip template status', $pstToggle['status']);
    $pstToggleRow = current(array_filter(auditRowsFor($pdo, $compId, 'payslip_templates', $pstId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === $pstToggle['new_status']));
    checkTrue('status toggle logged', $pstToggleRow !== false);

    // toggleStatus() just flipped to inactive -- flip back to active so setDefault()/publish still
    // reflect a normal in-use template for the rest of this section.
    $pstModel->toggleStatus($compId, $pstId, $userId);

    $pstSetDefault = $pstModel->setDefault($compId, $pstId, $userId, '10.4.0.8', 'TestAgent/1.0');
    checkTrue('setDefault()' . (empty($pstSetDefault['status']) ? " ({$pstSetDefault['message']})" : ''), $pstSetDefault['status']);
    $pstDefaultRow = current(array_filter(auditRowsFor($pdo, $compId, 'payslip_templates', $pstId), fn($r) => $r['field_name'] === 'is_default' && $r['new_value'] === '1'));
    checkTrue('is_default 0->1 change logged', $pstDefaultRow !== false);

    $pstPublish = $pstModel->setPublishStatus($compId, $pstId, 'public', $userId, '10.4.0.9', 'TestAgent/1.0');
    checkTrue('setPublishStatus()' . (empty($pstPublish['status']) ? " ({$pstPublish['message']})" : ''), $pstPublish['status']);
    $pstPublishRow = current(array_filter(auditRowsFor($pdo, $compId, 'payslip_templates', $pstId), fn($r) => $r['field_name'] === 'publish_status' && $r['new_value'] === 'public'));
    checkTrue('publish_status draft->public change logged', $pstPublishRow !== false);

    $pstDelete = $pstModel->delete($compId, $pstId, $userId, '10.4.0.10', 'TestAgent/1.0');
    checkTrue('delete payslip template', $pstDelete['status']);
    $pstDeleteRow = current(array_filter(auditRowsFor($pdo, $compId, 'payslip_templates', $pstId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === 'deleted'));
    checkTrue('delete logged as a status->deleted update row', $pstDeleteRow !== false);

    // ================= EmploymentCertificateTemplateModel =================
    echo "\n=== EmploymentCertificateTemplateModel: save()/setDefault()/setPublishStatus()/delete() (header row only) ===\n";
    $ectModel = new EmploymentCertificateTemplateModel($pdo);
    $ectCreate = $ectModel->save($compId, [
        'language' => 'th', 'template_name' => 'Audit Batch5 Test ECT', 'page_size' => 'A4', 'orientation' => 'portrait',
    ], $userId);
    checkTrue('create ECT template' . (empty($ectCreate['status']) ? " ({$ectCreate['message']})" : ''), $ectCreate['status']);
    $ectId = (int)($ectCreate['template_id'] ?? 0);
    checkTrue('fixture: ECT template id resolved', $ectId > 0);
    check('create branch is not audited', count(auditRowsFor($pdo, $compId, 'employment_certificate_templates', $ectId)), 0);

    $ectUpdate = $ectModel->save($compId, [
        'id' => $ectId, 'language' => 'th', 'template_name' => 'Audit Batch5 Test ECT RENAMED', 'page_size' => 'Legal', 'orientation' => 'landscape',
    ], $userId, '10.4.0.11', 'TestAgent/1.0');
    checkTrue('update ECT template header' . (empty($ectUpdate['status']) ? " ({$ectUpdate['message']})" : ''), $ectUpdate['status']);
    $ectFields = array_column(auditRowsFor($pdo, $compId, 'employment_certificate_templates', $ectId), 'field_name');
    checkTrue('template_name change logged', in_array('template_name', $ectFields, true));
    checkTrue('page_size change logged', in_array('page_size', $ectFields, true));

    $ectSetDefault = $ectModel->setDefault($compId, $ectId, $userId, '10.4.0.12', 'TestAgent/1.0');
    checkTrue('setDefault()' . (empty($ectSetDefault['status']) ? " ({$ectSetDefault['message']})" : ''), $ectSetDefault['status']);
    $ectDefaultRow = current(array_filter(auditRowsFor($pdo, $compId, 'employment_certificate_templates', $ectId), fn($r) => $r['field_name'] === 'is_default' && $r['new_value'] === '1'));
    checkTrue('is_default 0->1 change logged', $ectDefaultRow !== false);

    $ectPublish = $ectModel->setPublishStatus($compId, $ectId, 'public', $userId, '10.4.0.13', 'TestAgent/1.0');
    checkTrue('setPublishStatus()' . (empty($ectPublish['status']) ? " ({$ectPublish['message']})" : ''), $ectPublish['status']);
    $ectPublishRow = current(array_filter(auditRowsFor($pdo, $compId, 'employment_certificate_templates', $ectId), fn($r) => $r['field_name'] === 'publish_status' && $r['new_value'] === 'public'));
    checkTrue('publish_status draft->public change logged', $ectPublishRow !== false);

    $ectDelete = $ectModel->delete($compId, $ectId, $userId, '10.4.0.14', 'TestAgent/1.0');
    checkTrue('delete ECT template', $ectDelete['status']);
    $ectDeleteRow = current(array_filter(auditRowsFor($pdo, $compId, 'employment_certificate_templates', $ectId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === 'deleted'));
    checkTrue('delete logged as a status->deleted update row', $ectDeleteRow !== false);

    echo "\n=== SUMMARY: {$passes} passed, {$failures} failed ===\n";
} finally {
    $pdo->rollBack();
}

exit($failures > 0 ? 1 : 0);
