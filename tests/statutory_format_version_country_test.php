<?php
/**
 * Lightweight verification script for Backlog Phase 9, T049: "redesign the document-handling
 * section (per selected city/country, what must be filed) for max usability". Not PHPUnit -- see
 * tests/statutory_engine_test.php for why. Runs against the real dev DB inside a transaction that
 * is always rolled back -- uses its own fresh companies (never touches comp_id=1's live data, see
 * feedback_dev_db_shared_state_test_fragility).
 * Run with: php tests/statutory_format_version_country_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/StatutoryFormatVersionModel.php';

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

function makeCompany(PDO $pdo, string $countryCode): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

/** @param array<int,array{form_code:string}> $items */
function findItem(array $items, string $formCode): ?array {
    foreach ($items as $it) {
        if ($it['form_code'] === $formCode) return $it;
    }
    return null;
}

try {
    $thCompId = makeCompany($pdo, 'TH');
    $sgCompId = makeCompany($pdo, 'SG');
    $model = new StatutoryFormatVersionModel($pdo);

    echo "=== TH company: country-scoped picker rows + full 'what must be filed' checklist ===\n";
    $thResult = $model->settingsForCompany($thCompId);
    check('TH result: country_code', $thResult['country_code'], 'TH');
    check('TH result: country_name_en', $thResult['country_name_en'], 'Thailand');
    checkTrue('TH result has items', count($thResult['items']) > 0);

    $thPnd1 = findItem($thResult['items'], 'TH_PND1');
    checkTrue('TH_PND1 present (real version picker)', $thPnd1 !== null);
    if ($thPnd1) {
        checkTrue('TH_PND1 has_version_picker = true', $thPnd1['has_version_picker']);
        checkTrue('TH_PND1 has at least 1 seeded version', count($thPnd1['versions']) >= 1);
        check('TH_PND1 category = tax', $thPnd1['category'], 'tax');
    }
    $thSso110 = findItem($thResult['items'], 'TH_SSO110');
    checkTrue('TH_SSO110 present (real version picker)', $thSso110 !== null);
    if ($thSso110) {
        check('TH_SSO110 category = social_insurance', $thSso110['category'], 'social_insurance');
    }

    // T049's actual "what must be filed" deliverable: the 4 reports with NO version picker still
    // show up, informational-only, completing the checklist.
    // 2026-09-05, Phase 12 T071: PND1K_SUMMARY/SSO609/SLF's underlying txt layouts are now
    // confirmed against a real reference spec (see each exporter's own docblock) -- only
    // TH_KOR20KOR remains unconfirmed (the reference materials had no dedicated spec sheet for
    // it), so it's the one holdout still expected false here.
    $expectedVerified = ['TH_PND1K_SUMMARY' => true, 'TH_SSO609' => true, 'TH_KOR20KOR' => false, 'TH_SLF' => true];
    foreach (['TH_PND1K_SUMMARY' => 'tax', 'TH_SSO609' => 'social_insurance', 'TH_KOR20KOR' => 'provident_fund', 'TH_SLF' => 'other'] as $code => $expectedCategory) {
        $item = findItem($thResult['items'], $code);
        checkTrue("{$code} present as an informational row (T049's own point)", $item !== null);
        if ($item) {
            checkFalse("{$code} has_version_picker = false (no real choice exists)", $item['has_version_picker']);
            check("{$code} versions array is empty", $item['versions'], []);
            check("{$code} category = {$expectedCategory}", $item['category'], $expectedCategory);
            checkTrue("{$code} label resolved from ReportGeneratorInterface::label()", isset($item['label']['th']) && $item['label']['th'] !== '');
            check("{$code} is_verified = " . ($expectedVerified[$code] ? 'true' : 'false'), $item['is_verified'], $expectedVerified[$code]);
        }
    }
    check('TH sees exactly 6 statutory documents total (2 pickers + 4 informational)', count($thResult['items']), 6);

    echo "\n=== SG company: sees ZERO of TH's country-specific rows (proves the filter, not just 'no crash') ===\n";
    $sgResult = $model->settingsForCompany($sgCompId);
    check('SG result: country_code', $sgResult['country_code'], 'SG');
    check('SG sees 0 items (no SG statutory forms exist yet, and none of TH\'s leak through)', count($sgResult['items']), 0);
    checkTrue('SG does NOT see TH_PND1', findItem($sgResult['items'], 'TH_PND1') === null);
    checkTrue('SG does NOT see TH_SLF (the informational-only branch is also country-filtered)', findItem($sgResult['items'], 'TH_SLF') === null);

    echo "\n=== A NULL country_code row (an 'applies to every country' form) is visible to BOTH companies ===\n";
    $genericFormCode = 'GENERIC_TEST_' . strtoupper(substr(uniqid(), -6));
    $pdo->prepare("INSERT INTO master_statutory_format_versions (form_code, country_code, version_code, name_th, name_en, is_verified, is_default, is_active, sort_order)
        VALUES (:fc, NULL, 'v1', 'ทดสอบสากล', 'Generic Test Form', 1, 1, 1, 1)")
        ->execute([':fc' => $genericFormCode]);
    $thResult2 = $model->settingsForCompany($thCompId);
    $sgResult2 = $model->settingsForCompany($sgCompId);
    checkTrue('TH company sees the NULL-country generic form', findItem($thResult2['items'], $genericFormCode) !== null);
    checkTrue('SG company ALSO sees the NULL-country generic form', findItem($sgResult2['items'], $genericFormCode) !== null);
    $genericItemForTh = findItem($thResult2['items'], $genericFormCode);
    if ($genericItemForTh) {
        checkTrue('the generic form still behaves as a real version picker (it has a master_statutory_format_versions row)', $genericItemForTh['has_version_picker']);
    }

    echo "\n=== resolveVersionCode() unaffected by this round -- still returns the right default ===\n";
    check('resolveVersionCode() for TH_PND1 falls back to the seeded default version_code', $model->resolveVersionCode($thCompId, 'TH_PND1'), 'v1_current');
    check('resolveVersionCode() for an unseeded form returns null', $model->resolveVersionCode($thCompId, 'NO_SUCH_FORM_XYZ'), null);

    echo "\n=== saveSelection() still works end-to-end for a real version picker (no regression) ===\n";
    $versions = $model->listVersions('TH_PND1');
    checkTrue('fixture: TH_PND1 has a version to select', count($versions) >= 1);
    if (count($versions) >= 1) {
        $saveRes = $model->saveSelection($thCompId, 'TH_PND1', (int)$versions[0]['id'], 1);
        checkTrue('saveSelection() succeeds for a valid version', $saveRes['status']);
        check('resolveVersionCode() now reflects the saved selection', $model->resolveVersionCode($thCompId, 'TH_PND1'), $versions[0]['version_code']);
    }

    echo "\n=== A company with no registered_country at all gets an empty, well-shaped result (not a crash) ===\n";
    // registered_country is NOT NULL on this schema (confirmed by a real PDOException the first
    // time this test tried a literal NULL) -- an empty string is the real-world equivalent
    // (companyCountry()'s own guard already treats '' the same as NULL/false, see the model), so
    // that's what actually exercises this guard clause without violating the real constraint.
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, '', :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'No Country Co ' . uniqid(), ':tax' => 'TAX' . uniqid()]);
    $noCountryCompId = (int)$pdo->lastInsertId();
    $noCountryResult = $model->settingsForCompany($noCountryCompId);
    check('no-country company: country_code is null', $noCountryResult['country_code'], null);
    check('no-country company: items is an empty array', $noCountryResult['items'], []);

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
