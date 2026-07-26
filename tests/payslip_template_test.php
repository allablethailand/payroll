<?php
/**
 * Lightweight verification script for PayslipTemplateModel: CRUD, field-selection validation,
 * single-active-default-per-company enforcement, and the toggle-clears-default rule. Not
 * PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 * Run with: php tests/payslip_template_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayslipTemplateModel.php';
require_once __DIR__ . '/../app/services/reports/payment/PaySlipReport.php';

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
    $compId = 1;
    $userId = 1;
    $model = new PayslipTemplateModel($pdo);

    $fieldOptions = $model->fieldTypeOptions();
    check('20 field types seeded', count($fieldOptions), 20);
    checkTrue('field options carry an id key (not just code)', isset($fieldOptions[0]['id']));

    // ---------- Create ----------
    $t1 = $model->save([
        'name_th' => 'ทดสอบมาตรฐาน', 'name_en' => 'Test Standard', 'language_mode' => 'both', 'is_default' => 1,
        'header_text_th' => 'หัวกระดาษทดสอบ', 'status' => 'active',
        'fields' => [['field_key' => 'employee_no'], ['field_key' => 'basic_salary'], ['field_key' => 'net_amount']],
    ], $compId, $userId);
    checkTrue('template 1 (default) saves', $t1['status']);

    $t2 = $model->save([
        'name_th' => 'ทดสอบละเอียด', 'name_en' => 'Test Detailed', 'language_mode' => 'th', 'is_default' => 1,
        'status' => 'active', 'fields' => [['field_key' => 'ytd_summary']],
    ], $compId, $userId);
    checkTrue('template 2 (also default) saves', $t2['status']);

    // ---------- Single-default enforcement ----------
    $list = $model->list($compId);
    check('2 templates listed', count($list), 2);
    $defaults = array_filter($list, fn($t) => (int)$t['is_default'] === 1);
    check('exactly one template is default after 2nd save', count($defaults), 1);
    $defaultRow = array_values($defaults)[0];
    check('template 2 is the one that stayed default (most recent save wins)', (int)$defaultRow['id'], (int)$t2['id']);

    // ---------- get() field resolution ----------
    $fetched1 = $model->get((int)$t1['id'], $compId);
    check('template 1 has 3 fields in saved order', array_column($fetched1['fields'], 'field_key'), ['employee_no', 'basic_salary', 'net_amount']);
    check('field label resolved from master table', $fetched1['fields'][0]['default_label_en'], 'Employee No.');
    check('country_code auto-derived from company', $fetched1['country_code'], 'TH');

    // ---------- Validation ----------
    $dup = $model->save(['name_th' => 'ทดสอบมาตรฐาน', 'name_en' => 'X', 'fields' => [['field_key' => 'employee_no']]], $compId, $userId);
    checkFalse('duplicate name_th rejected', $dup['status']);

    $badField = $model->save(['name_th' => 'X', 'name_en' => 'X', 'fields' => [['field_key' => 'not_a_real_field']]], $compId, $userId);
    checkFalse('invalid field_key rejected', $badField['status']);

    $noFields = $model->save(['name_th' => 'Y', 'name_en' => 'Y', 'fields' => []], $compId, $userId);
    checkFalse('empty fields array rejected', $noFields['status']);

    $missingName = $model->save(['name_th' => '', 'name_en' => '', 'fields' => [['field_key' => 'employee_no']]], $compId, $userId);
    checkFalse('missing name rejected', $missingName['status']);

    // ---------- Duplicate field_key in payload gets deduped, not rejected ----------
    $dedupe = $model->save([
        'name_th' => 'ทดสอบซ้ำฟิลด์', 'name_en' => 'Test Dedupe', 'status' => 'active',
        'fields' => [['field_key' => 'employee_no'], ['field_key' => 'employee_no'], ['field_key' => 'net_amount']],
    ], $compId, $userId);
    checkTrue('duplicate field_key in payload does not fail the save', $dedupe['status']);
    $fetchedDedupe = $model->get((int)$dedupe['id'], $compId);
    check('duplicate field_key silently deduped to 2 rows', count($fetchedDedupe['fields']), 2);

    // ---------- Toggle status clears is_default ----------
    $toggle = $model->toggleStatus((int)$t2['id'], $compId, $userId);
    checkTrue('toggle status on the default template succeeds', $toggle['status']);
    check('toggled to inactive', $toggle['new_status'], 'inactive');
    $afterToggle = $model->get((int)$t2['id'], $compId);
    check('deactivating the default template clears is_default', (int)$afterToggle['is_default'], 0);

    // ---------- getDefaultForCompany() ----------
    checkTrue('no active default exists after toggling the only default off', $model->getDefaultForCompany($compId) === null);
    $model->save(['id' => $t1['id'], 'name_th' => 'ทดสอบมาตรฐาน', 'name_en' => 'Test Standard', 'is_default' => 1, 'status' => 'active',
        'fields' => [['field_key' => 'employee_no']]], $compId, $userId);
    $newDefault = $model->getDefaultForCompany($compId);
    checkTrue('getDefaultForCompany finds the newly-set default', $newDefault !== null);
    check('getDefaultForCompany returns template 1', (int)$newDefault['id'], (int)$t1['id']);

    // ---------- Preview (mock data, unsaved draft state, no persistence) ----------
    $paySlipReport = new PaySlipReport();
    $previewPdf = $paySlipReport->generatePreview([
        'language_mode' => 'both', 'header_text_th' => 'ตัวอย่าง',
        'fields' => [['field_key' => 'company_name'], ['field_key' => 'employee_no'], ['field_key' => 'basic_salary'],
            ['field_key' => 'earning_lines_all'], ['field_key' => 'statutory_lines_all'], ['field_key' => 'net_amount'],
            ['field_key' => 'bank_account_masked']],
    ], $compId);
    checkTrue('preview PDF starts with %PDF header', str_starts_with($previewPdf, '%PDF'));
    checkTrue('preview PDF has non-trivial content length', strlen($previewPdf) > 1000);

    $countBefore = count($model->list($compId));
    $paySlipReport->generatePreview(['fields' => [['field_key' => 'employee_no']]], $compId);
    check('preview does not persist any template row', count($model->list($compId)), $countBefore);

    $previewInvalidField = false;
    try {
        $paySlipReport->generatePreview(['fields' => [['field_key' => 'not_a_real_field']]], $compId);
    } catch (LocalizedException $e) {
        $previewInvalidField = ($e->getErrorKey() === 'invalid_field_selection');
    }
    checkTrue('preview rejects an invalid field_key', $previewInvalidField);

    $previewEmptyFields = false;
    try {
        $paySlipReport->generatePreview(['fields' => []], $compId);
    } catch (LocalizedException $e) {
        $previewEmptyFields = ($e->getErrorKey() === 'select_at_least_one_field');
    }
    checkTrue('preview rejects an empty field list', $previewEmptyFields);

    // ---------- Delete ----------
    $del = $model->delete((int)$dedupe['id'], $compId, $userId);
    checkTrue('delete succeeds', $del['status']);
    check('deleted template no longer retrievable', $model->get((int)$dedupe['id'], $compId), null);

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
