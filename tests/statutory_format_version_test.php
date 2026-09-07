<?php
/**
 * Lightweight verification script for StatutoryFormatVersionModel (2026-08-29) -- the document
 * format VERSION SELECTOR for ภ.ง.ด./สปส. submissions (see that model's own docblock for why this
 * is a version picker, not a field editor like BankFileFormatModel). Also verifies the actual
 * wiring: PndOneExporter/Sso110Exporter validate an unsupported version_code and throw. Not
 * PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back. Uses a fresh throwaway company, not comp_id=1.
 * Run with: php tests/statutory_format_version_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/StatutoryFormatVersionModel.php';
require_once __DIR__ . '/../app/services/export/th/PndOneExporter.php';
require_once __DIR__ . '/../app/services/export/th/Sso110Exporter.php';

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
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }

try {
    $userId = 1;
    $insComp = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active')");
    $insComp->execute([':name' => 'SFV Test Co ' . uniqid()]);
    $compId = (int)$pdo->lastInsertId();

    $model = new StatutoryFormatVersionModel($pdo);

    echo "=== listForms() / listVersions() ===\n";
    $forms = $model->listForms();
    checkTrue('TH_PND1 is a known form', in_array('TH_PND1', $forms, true));
    checkTrue('TH_SSO110 is a known form', in_array('TH_SSO110', $forms, true));

    $pnd1Versions = $model->listVersions('TH_PND1');
    check('TH_PND1 has exactly 1 seeded version', count($pnd1Versions), 1);
    checkTrue('the seeded TH_PND1 version is flagged default', $pnd1Versions[0]['is_default']);
    // 2026-09-05, Phase 12 T071: PndOneExporter's field layout is now confirmed against a real
    // reference spec (see that class's own docblock) -- the seed row's own is_verified flag was
    // flipped to match (database/migrations/2026-09-05_2_statutory_format_versions_confirmed.sql).
    checkTrue('the seeded TH_PND1 version is now flagged verified (matches PndOneExporter::isVerified())', $pnd1Versions[0]['is_verified']);
    $pnd1VersionId = (int)$pnd1Versions[0]['id'];

    echo "=== resolveVersionCode() falls back to the default before any company selection exists ===\n";
    check('resolveVersionCode() falls back to default for a company that never chose one', $model->resolveVersionCode($compId, 'TH_PND1'), 'v1_current');

    echo "=== settingsForCompany() ===\n";
    // 2026-09-04, T049: settingsForCompany() now returns an envelope
    // {country_code, country_name_th, country_name_en, items} (was a bare array) -- and 'items' now
    // includes every statutory document this country must file, not just the 2 that happen to have
    // a real version picker (see tests/statutory_format_version_country_test.php for the dedicated,
    // country-scoping-focused coverage of this whole feature; this file keeps only what's still
    // this model's OWN original scope -- the picker/save/exporter-wiring behavior).
    $settings = $model->settingsForCompany($compId);
    check('settingsForCompany() country_code', $settings['country_code'], 'TH');
    check('settingsForCompany() returns 6 statutory documents (2 real pickers + 4 informational-only, T049)', count($settings['items']), 6);
    $pnd1Setting = null;
    foreach ($settings['items'] as $s) { if ($s['form_code'] === 'TH_PND1') $pnd1Setting = $s; }
    checkTrue('TH_PND1 entry found in settingsForCompany()', $pnd1Setting !== null);
    check('TH_PND1 selected_version_id falls back to the default version id', $pnd1Setting['selected_version_id'], $pnd1VersionId);

    echo "=== saveSelection() ===\n";
    $badSave = $model->saveSelection($compId, 'TH_PND1', 999999, $userId);
    checkFalse('saveSelection rejects an unknown version id', $badSave['status']);

    $crossFormSave = $model->saveSelection($compId, 'TH_SSO110', $pnd1VersionId, $userId);
    checkFalse('saveSelection rejects a version id that belongs to a DIFFERENT form_code', $crossFormSave['status']);

    $goodSave = $model->saveSelection($compId, 'TH_PND1', $pnd1VersionId, $userId);
    checkTrue('saveSelection succeeds for a valid (form_code, version_id) pair' . (empty($goodSave['status']) ? " ({$goodSave['message']})" : ''), $goodSave['status']);

    // Re-save (update path, not insert) -- same version id, must not create a 2nd row.
    $resaveResult = $model->saveSelection($compId, 'TH_PND1', $pnd1VersionId, $userId);
    checkTrue('re-saving the same selection succeeds (update path)', $resaveResult['status']);
    $rowCount = (int)$pdo->query("SELECT COUNT(*) FROM company_statutory_format_settings WHERE comp_id = {$compId} AND form_code = 'TH_PND1'")->fetchColumn();
    check('exactly 1 row exists after saving twice (upsert, not duplicate insert)', $rowCount, 1);

    check('resolveVersionCode() now reflects the saved selection', $model->resolveVersionCode($compId, 'TH_PND1'), 'v1_current');

    echo "=== Exporter version validation (real wiring, not just the model) ===\n";
    // 2026-09-05, Phase 12 T071: field key is id_card_no now (11-field reference-spec layout),
    // not the old 20-field version's tax_id -- see PndOneExporter's own docblock.
    $pndExporter = new PndOneExporter();
    $pndOutput = $pndExporter->generate(['period' => ['tax_year' => 2569, 'tax_month' => 1], 'employees' => [['id_card_no' => '1234567890123', 'prefix' => 'นาย', 'first_name' => 'Test', 'last_name' => 'Employee', 'total_income' => 30000, 'tax_withheld' => 500]], 'version_code' => 'v1_current']);
    checkTrue('PndOneExporter accepts its own supported version_code and produces output', strlen($pndOutput) > 0);

    $threwForBadVersion = false;
    try {
        $pndExporter->generate(['period' => ['tax_year' => 2569, 'tax_month' => 1], 'employees' => [], 'version_code' => 'not_a_real_version']);
    } catch (RuntimeException $e) {
        $threwForBadVersion = true;
    }
    checkTrue('PndOneExporter throws for an unsupported version_code', $threwForBadVersion);

    $noVersionOutput = $pndExporter->generate(['period' => ['tax_year' => 2569, 'tax_month' => 1], 'employees' => [['id_card_no' => '1234567890123', 'prefix' => 'นาย', 'first_name' => 'Test', 'last_name' => 'Employee', 'total_income' => 30000, 'tax_withheld' => 500]]]);
    checkTrue('PndOneExporter still works with NO version_code at all (backward compatible)', strlen($noVersionOutput) > 0);

    $ssoExporter = new Sso110Exporter();
    $threwForBadSsoVersion = false;
    try {
        $ssoExporter->generate(['company' => [], 'period' => ['year' => 2026, 'month' => 1], 'employees' => [], 'version_code' => 'nonexistent']);
    } catch (RuntimeException $e) {
        $threwForBadSsoVersion = true;
    }
    checkTrue('Sso110Exporter throws for an unsupported version_code', $threwForBadSsoVersion);

    echo "\n--------------------------------------------------\n";
    echo "Passed: {$passes}, Failed: {$failures}\n";
    if ($failures > 0) {
        echo "SOME TESTS FAILED\n";
    } else {
        echo "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
    }
} catch (Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $failures++;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
exit($failures > 0 ? 1 : 0);
