<?php
/**
 * Lightweight verification script for EmploymentCertificateTemplateModel (multi-template CRUD,
 * default enforcement, presets, reusable image library, element validation) and
 * EmploymentCertificateRenderer (token substitution, Thai font registration, page size/
 * orientation, watermark). Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Runs against the real dev DB inside a transaction that is always rolled back.
 * Run with: php tests/employment_certificate_template_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/EmploymentCertificateTemplateModel.php';
require_once __DIR__ . '/../app/services/EmploymentCertificateRenderer.php';

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
    $model = new EmploymentCertificateTemplateModel($pdo);
    $renderer = new EmploymentCertificateRenderer($pdo);

    // Isolation step (same pattern as tests/payroll_run_test.php's own Approval Workflow isolation --
    // see CLAUDE.md's "2026-08-24 fix, round 2" section) -- comp_id=1 is the real dev DB and may have
    // genuine admin-created templates/images at any time (confirmed concurrent live usage while this
    // suite was being extended, not stale fixture leftovers -- see
    // feedback_dev_db_shared_state_test_fragility memory). Clear both here, inside this test's own
    // transaction that always rolls back at the end, so the real rows are restored untouched the
    // moment this script exits either way (employment_certificate_images has no soft-delete column --
    // a real DELETE is still safe here for the same reason: it never survives the rollback).
    $pdo->prepare("UPDATE `employment_certificate_templates` SET status = 'deleted' WHERE comp_id = :comp_id AND status = 'active'")
        ->execute([':comp_id' => $compId]);
    $pdo->prepare("DELETE FROM `employment_certificate_images` WHERE comp_id = :comp_id")
        ->execute([':comp_id' => $compId]);

    echo "=== fieldTypeOptions() / presetOptions() ===\n";
    $fieldOptions = $model->fieldTypeOptions();
    // 2026-08-25, explicit follow-up: "ตรง Add to Canvas สามารถเพิ่ม item อะไรเกี่ยวกับพนักงาน...ได้อีกไหม"
    // -- 15 original + 5 new employee fields (branch/team/gender/nationality/date_of_birth) = 20.
    check('20 field types seeded (15 original + 5 new employee fields)', count($fieldOptions), 20);
    foreach (['employee_branch', 'employee_team', 'employee_gender', 'employee_nationality', 'employee_date_of_birth'] as $code) {
        checkTrue("field type '{$code}' is present in fieldTypeOptions()", in_array($code, array_column($fieldOptions, 'code'), true));
    }
    $presets = $model->presetOptions();
    // 2026-08-25, explicit follow-up: "ขอเพิ่ม Template สัก 5 template ให้แต่ละ template มีความแตกต่างกัน"
    // -- blank + 5 standard layouts now (classic/modern/minimal + new formal/elegant).
    check('6 presets available (blank + 5 standard layouts)', count($presets), 6);
    foreach (['classic', 'modern', 'minimal', 'formal', 'elegant'] as $code) {
        checkTrue("preset '{$code}' is present in presetOptions()", in_array($code, array_column($presets, 'code'), true));
    }

    echo "=== presetPreviewElements() (List+Modal restructure's per-preset Preview button) ===\n";
    foreach (['classic', 'modern', 'minimal', 'formal', 'elegant'] as $code) {
        $thCount = count($model->presetPreviewElements($code, 'th'));
        $enCount = count($model->presetPreviewElements($code, 'en'));
        checkTrue("preset '{$code}' has real elements (th)", $thCount > 0);
        check("preset '{$code}' has the same element count in th and en", $enCount, $thCount);
    }
    check('blank preset has no elements', $model->presetPreviewElements('blank', 'th'), []);
    $invalidPresetThrew = false;
    try {
        $model->presetPreviewElements('not_a_real_preset', 'th');
    } catch (InvalidArgumentException $e) {
        $invalidPresetThrew = true;
    }
    checkTrue('an invalid preset code throws InvalidArgumentException', $invalidPresetThrew);

    echo "=== list()/getDefault() before anything is saved ===\n";
    check('list() is empty for a language with nothing saved', $model->list($compId, 'th'), []);
    check('getDefault() returns null', $model->getDefault($compId, 'th'), null);

    echo "=== save() validation ===\n";
    $noName = $model->save($compId, ['language' => 'th', 'template_name' => '', 'elements' => [['element_type' => 'text', 'content' => 'x', 'pos_x_pct' => 1, 'pos_y_pct' => 1, 'width_pct' => 10, 'height_pct' => 5]]], $userId);
    check('rejects a blank template_name', $noName['status'], false);
    $badPageSize = $model->save($compId, ['language' => 'th', 'template_name' => 'X', 'page_size' => 'Tabloid', 'elements' => []], $userId);
    check('rejects an invalid page_size', $badPageSize['status'], false);
    $badColor = $model->save($compId, ['language' => 'th', 'template_name' => 'X', 'elements' => [
        ['element_type' => 'text', 'content' => 'x', 'pos_x_pct' => 1, 'pos_y_pct' => 1, 'width_pct' => 10, 'height_pct' => 5, 'font_color' => 'red'],
    ]], $userId);
    check('rejects a non-hex font_color', $badColor['status'], false);
    $imageNoSource = $model->save($compId, ['language' => 'th', 'template_name' => 'X', 'elements' => [
        ['element_type' => 'image', 'pos_x_pct' => 1, 'pos_y_pct' => 1, 'width_pct' => 10, 'height_pct' => 5],
    ]], $userId);
    check('rejects an image element with neither field_key nor image_asset_id', $imageNoSource['status'], false);

    echo "=== createFromPreset() -- 'classic' seeds real elements, 'blank' seeds none ===\n";
    $classicRes = $model->createFromPreset($compId, 'th', 'classic', 'ทดสอบคลาสสิก', $userId);
    checkTrue('classic preset creates a template' . (empty($classicRes['status']) ? " ({$classicRes['message']})" : ''), $classicRes['status']);
    $classicTemplate = $model->get($compId, (int)$classicRes['template_id']);
    checkTrue('classic template has several elements', count($classicTemplate['elements']) >= 5);
    check('the FIRST template saved for a language becomes the default automatically', (bool)$classicTemplate['is_default'], true);

    $blankRes = $model->createFromPreset($compId, 'th', 'blank', 'ทดสอบว่างเปล่า', $userId);
    checkTrue('blank preset creates a template too', $blankRes['status']);
    $blankTemplate = $model->get($compId, (int)$blankRes['template_id']);
    check('blank template starts with zero elements', count($blankTemplate['elements']), 0);
    check('the SECOND template does NOT become default automatically', (bool)$blankTemplate['is_default'], false);

    echo "=== group_key (2026-08-24, explicit request: Group/Ungroup + Layers panel) round-trips through save()/get() ===\n";
    $groupRes = $model->save($compId, [
        'language' => 'th', 'template_name' => 'ทดสอบ Group',
        'elements' => [
            ['element_type' => 'text', 'content' => 'A', 'pos_x_pct' => 1, 'pos_y_pct' => 1, 'width_pct' => 10, 'height_pct' => 5, 'group_key' => 'grp_test_1'],
            ['element_type' => 'text', 'content' => 'B', 'pos_x_pct' => 20, 'pos_y_pct' => 1, 'width_pct' => 10, 'height_pct' => 5, 'group_key' => 'grp_test_1'],
            ['element_type' => 'text', 'content' => 'C', 'pos_x_pct' => 40, 'pos_y_pct' => 1, 'width_pct' => 10, 'height_pct' => 5],
        ],
    ], $userId);
    checkTrue('save() with group_key succeeds', $groupRes['status']);
    $groupTemplate = $model->get($compId, (int)$groupRes['template_id']);
    $byContent = [];
    foreach ($groupTemplate['elements'] as $el) { $byContent[$el['content']] = $el['group_key']; }
    check('element A kept its group_key', $byContent['A'], 'grp_test_1');
    check('element B kept its group_key', $byContent['B'], 'grp_test_1');
    check('element C (never grouped) has a null group_key', $byContent['C'], null);
    $ungroupRes = $model->save($compId, [
        'id' => $groupRes['template_id'], 'language' => 'th', 'template_name' => 'ทดสอบ Group',
        'elements' => [
            ['element_type' => 'text', 'content' => 'A', 'pos_x_pct' => 1, 'pos_y_pct' => 1, 'width_pct' => 10, 'height_pct' => 5],
            ['element_type' => 'text', 'content' => 'B', 'pos_x_pct' => 20, 'pos_y_pct' => 1, 'width_pct' => 10, 'height_pct' => 5],
        ],
    ], $userId);
    checkTrue('re-save without group_key (ungroup) succeeds', $ungroupRes['status']);
    $ungroupedTemplate = $model->get($compId, (int)$groupRes['template_id']);
    checkTrue('after ungroup, no element has a group_key any more', array_filter(array_column($ungroupedTemplate['elements'], 'group_key')) === []);
    // Cleanup -- this section's own template must not affect the exact-count assertions in
    // "multiple templates coexist per language" right below (which assume exactly classic+blank).
    $model->delete($compId, (int)$groupRes['template_id'], $userId);

    echo "=== multiple templates coexist per language ===\n";
    $thList = $model->list($compId, 'th');
    check('2 TH templates listed', count($thList), 2);
    check('getDefault() still returns the classic one (flagged default)', (int)$model->getDefault($compId, 'th')['id'], (int)$classicTemplate['id']);

    echo "=== setDefault() enforces single-default-per-language ===\n";
    $setDefaultRes = $model->setDefault($compId, (int)$blankTemplate['id'], $userId);
    checkTrue('setDefault() succeeds', $setDefaultRes['status']);
    check('blank is now the default', (bool)$model->get($compId, (int)$blankTemplate['id'])['is_default'], true);
    check('classic is no longer the default', (bool)$model->get($compId, (int)$classicTemplate['id'])['is_default'], false);
    check('getDefault() now returns blank', (int)$model->getDefault($compId, 'th')['id'], (int)$blankTemplate['id']);

    echo "=== save() with an id updates that template in place (whole element set replaced) ===\n";
    $updateRes = $model->save($compId, [
        'id' => $blankTemplate['id'], 'language' => 'th', 'template_name' => 'ทดสอบว่างเปล่า (แก้ไข)',
        'page_size' => 'Letter', 'orientation' => 'landscape',
        'elements' => [['element_type' => 'text', 'content' => '{{employee_no}}', 'pos_x_pct' => 5, 'pos_y_pct' => 5, 'width_pct' => 20, 'height_pct' => 5]],
    ], $userId);
    checkTrue('update-by-id succeeds' . (empty($updateRes['status']) ? " ({$updateRes['message']})" : ''), $updateRes['status']);
    check('template id unchanged (same row, not a new one)', (int)$updateRes['template_id'], (int)$blankTemplate['id']);
    $updated = $model->get($compId, (int)$blankTemplate['id']);
    check('template_name updated', $updated['template_name'], 'ทดสอบว่างเปล่า (แก้ไข)');
    check('page_size updated', $updated['page_size'], 'Letter');
    check('orientation updated', $updated['orientation'], 'landscape');
    check('elements replaced (now 1, not accumulated)', count($updated['elements']), 1);
    check('still flagged default (updating does not clear it)', (bool)$updated['is_default'], true);

    echo "=== duplicate() clones a template as a new, independent row ===\n";
    $dupRes = $model->duplicate($compId, (int)$classicTemplate['id'], $userId);
    checkTrue('duplicate() succeeds', $dupRes['status']);
    checkTrue('duplicate gets a NEW id', (int)$dupRes['template_id'] !== (int)$classicTemplate['id']);
    $dupTemplate = $model->get($compId, (int)$dupRes['template_id']);
    check('duplicate name has "(Copy)" suffix', $dupTemplate['template_name'], $classicTemplate['template_name'] . ' (Copy)');
    check('duplicate has the same element count as the source', count($dupTemplate['elements']), count($classicTemplate['elements']));
    check('duplicate is NOT the default (a 3rd template, default stays with blank)', (bool)$dupTemplate['is_default'], false);
    check('now 3 TH templates total', count($model->list($compId, 'th')), 3);

    echo "=== delete() is a soft delete and drops out of list()/getDefault() ===\n";
    $deleteRes = $model->delete($compId, (int)$dupTemplate['id'], $userId);
    checkTrue('delete() succeeds', $deleteRes['status']);
    check('deleted template no longer retrievable via get()', $model->get($compId, (int)$dupTemplate['id']), null);
    check('back to 2 TH templates', count($model->list($compId, 'th')), 2);

    echo "=== TH and EN templates are independent ===\n";
    $enRes = $model->createFromPreset($compId, 'en', 'minimal', 'Minimal EN', $userId);
    checkTrue('EN preset creates a template', $enRes['status']);
    check('EN list has exactly 1 (unaffected by TH activity above)', count($model->list($compId, 'en')), 1);
    check('TH list still has 2', count($model->list($compId, 'th')), 2);

    echo "=== isValidLogoPath() / isValidImageAssetPath() traversal-proofing ===\n";
    checkTrue('a well-formed logo path is valid', EmploymentCertificateTemplateModel::isValidLogoPath('public/uploads/employment_cert_logos/' . $compId . '/' . str_repeat('a', 32) . '.png', $compId));
    checkFalse('a logo path for a different company is rejected', EmploymentCertificateTemplateModel::isValidLogoPath('public/uploads/employment_cert_logos/999/' . str_repeat('a', 32) . '.png', $compId));
    checkTrue('a well-formed image-asset path is valid', EmploymentCertificateTemplateModel::isValidImageAssetPath('public/uploads/employment_cert_images/' . $compId . '/' . str_repeat('a', 32) . '.jpg', $compId));
    checkFalse('an image-asset traversal attempt is rejected', EmploymentCertificateTemplateModel::isValidImageAssetPath('public/uploads/employment_cert_images/' . $compId . '/../../../etc/passwd', $compId));

    echo "=== reusable image library ===\n";
    check('image library starts empty', $model->listImages($compId), []);
    $imgPath = 'public/uploads/employment_cert_images/' . $compId . '/' . str_repeat('c', 32) . '.png';
    $addImgRes = $model->addImage($compId, $imgPath, 'logo-variant.png', $userId);
    checkTrue('addImage() succeeds', $addImgRes['status']);
    $imageAssetId = (int)$addImgRes['id'];
    $images = $model->listImages($compId);
    check('image library now has 1 entry', count($images), 1);
    check('original_filename preserved', $images[0]['original_filename'], 'logo-variant.png');

    echo "--- a template can reference the uploaded image by id ---\n";
    $withImageRes = $model->save($compId, [
        'language' => 'th', 'template_name' => 'ใช้รูปจากคลัง',
        'elements' => [['element_type' => 'image', 'image_asset_id' => $imageAssetId, 'pos_x_pct' => 5, 'pos_y_pct' => 5, 'width_pct' => 20, 'height_pct' => 10]],
    ], $userId);
    checkTrue('save() with a valid image_asset_id succeeds', $withImageRes['status']);
    $withImageTemplate = $model->get($compId, (int)$withImageRes['template_id']);
    check('the element carries the image_asset_id', (int)$withImageTemplate['elements'][0]['image_asset_id'], $imageAssetId);

    $badImageAssetRes = $model->save($compId, [
        'language' => 'th', 'template_name' => 'ใช้รูปที่ไม่มีจริง',
        'elements' => [['element_type' => 'image', 'image_asset_id' => 999999, 'pos_x_pct' => 5, 'pos_y_pct' => 5, 'width_pct' => 20, 'height_pct' => 10]],
    ], $userId);
    check('save() rejects a nonexistent image_asset_id', $badImageAssetRes['status'], false);

    echo "--- deleteImage() is blocked while still referenced, then succeeds once free ---\n";
    $blockedDelete = $model->deleteImage($compId, $imageAssetId);
    check('deleteImage() refused while a template still uses it', $blockedDelete['status'], false);
    // Free it up by re-saving that template with the image element removed.
    $model->save($compId, ['id' => $withImageTemplate['id'], 'language' => 'th', 'template_name' => 'ใช้รูปจากคลัง',
        'elements' => [['element_type' => 'text', 'content' => 'x', 'pos_x_pct' => 1, 'pos_y_pct' => 1, 'width_pct' => 10, 'height_pct' => 5]]], $userId);
    $freeDelete = $model->deleteImage($compId, $imageAssetId);
    checkTrue('deleteImage() succeeds once no template references it', $freeDelete['status']);
    check('image library empty again', $model->listImages($compId), []);

    echo "=== EmploymentCertificateRenderer: token substitution ===\n";
    $tokens = $renderer->buildTokens('th', [
        'local_name' => 'บริษัท ทดสอบ จำกัด', 'company_legal_name' => 'Test Co., Ltd.',
        'address_line_1' => '123 ถนนทดสอบ', 'address_line_2' => 'แขวงทดสอบ',
        'global_tax_id' => '1234567890123', 'authorized_signatory_name' => 'นายทดสอบ ใจดี',
    ], [
        'employee_no' => 'EMP-999', 'name_th' => 'สมหญิง', 'surname_th' => 'รักงาน',
        'position_name_th' => 'ผู้จัดการ', 'department_name_th' => 'ฝ่ายขาย',
        'employment_date' => '2022-03-15', 'employment_status' => 'permanent', 'employment_type' => 'full_time',
        'base_salary_amount' => 45000,
    ]);
    check('employee_name token resolves (TH)', $tokens['employee_name'], 'สมหญิง รักงาน');
    check('base_salary is formatted with 2 decimals', $tokens['base_salary'], '45,000.00');

    echo "=== 2026-08-25: 5 new employee tokens (branch/team/gender/nationality/date_of_birth) ===\n";
    $tokensNew = $renderer->buildTokens('th', ['local_name' => 'x'], [
        'name_th' => 'x', 'gender' => 'female', 'nationality' => 'Thai', 'date_of_birth' => '1995-06-20',
        'branch_name_th' => 'สาขาสีลม', 'branch_name_en' => 'Silom Branch',
        'team_name_th' => 'ทีมบี', 'team_name_en' => 'Team B',
    ]);
    check('employee_gender resolves to the TH label, not the raw enum value', $tokensNew['employee_gender'], 'หญิง');
    check('employee_nationality passes through as-is (free text, not translated)', $tokensNew['employee_nationality'], 'Thai');
    check('employee_date_of_birth is formatted d/m/Y', $tokensNew['employee_date_of_birth'], '20/06/1995');
    check('employee_branch resolves the TH branch name', $tokensNew['employee_branch'], 'สาขาสีลม');
    check('employee_team resolves the TH team name', $tokensNew['employee_team'], 'ทีมบี');
    $tokensNewEn = $renderer->buildTokens('en', ['local_name' => 'x'], [
        'name_en' => 'x', 'gender' => 'female',
        'branch_name_th' => 'สาขาสีลม', 'branch_name_en' => 'Silom Branch',
        'team_name_th' => 'ทีมบี', 'team_name_en' => 'Team B',
    ]);
    check('employee_gender resolves to the EN label', $tokensNewEn['employee_gender'], 'Female');
    check('employee_branch resolves the EN branch name', $tokensNewEn['employee_branch'], 'Silom Branch');
    check('employee_team resolves the EN team name', $tokensNewEn['employee_team'], 'Team B');
    $tokensMissing = $renderer->buildTokens('th', ['local_name' => 'x'], ['name_th' => 'x']);
    check('employee_branch falls back to "-" when the employee has no branch', $tokensMissing['employee_branch'], '-');
    check('employee_nationality falls back to "-" when blank', $tokensMissing['employee_nationality'], '-');

    echo "=== EmploymentCertificateRenderer: page size / orientation ===\n";
    check('A4 portrait dimensions', EmploymentCertificateRenderer::pageDimensionsMm('A4', 'portrait'), [210.0, 297.0]);
    check('A4 landscape swaps width/height', EmploymentCertificateRenderer::pageDimensionsMm('A4', 'landscape'), [297.0, 210.0]);
    check('Letter portrait dimensions', EmploymentCertificateRenderer::pageDimensionsMm('Letter', 'portrait'), [215.9, 279.4]);
    $htmlLandscape = $renderer->buildHtml(['page_size' => 'Letter', 'orientation' => 'landscape'], 'th', [], ['local_name' => 'x'], ['name_th' => 'x'], null);
    checkTrue('buildHtml() emits the swapped landscape page size in @page', strpos($htmlLandscape, '279.4mm 215.9mm') !== false);

    echo "=== EmploymentCertificateRenderer: full text formatting reaches the output ===\n";
    $html = $renderer->buildHtml(
        ['page_size' => 'A4', 'orientation' => 'portrait'], 'th',
        [[
            'element_type' => 'text', 'content' => 'ขอรับรองว่า {{employee_name}} ตำแหน่ง {{position}}',
            'pos_x_pct' => 10, 'pos_y_pct' => 10, 'width_pct' => 80, 'height_pct' => 10,
            'font_size' => 18, 'text_align' => 'center', 'font_weight' => 'bold', 'font_style' => 'italic',
            'text_decoration' => 'underline', 'font_color' => '#2266CC', 'font_family' => 'dejavu_sans',
        ]],
        ['local_name' => 'บริษัท ทดสอบ จำกัด', 'address_line_1' => '', 'address_line_2' => '', 'global_tax_id' => '', 'authorized_signatory_name' => ''],
        ['name_th' => 'สมหญิง', 'surname_th' => 'รักงาน', 'position_name_th' => 'ผู้จัดการ', 'employment_status' => 'permanent', 'employment_type' => 'full_time'],
        null
    );
    checkTrue('tokens substituted', strpos($html, 'ขอรับรองว่า สมหญิง รักงาน ตำแหน่ง ผู้จัดการ') !== false);
    checkFalse('no raw {{token}} markers survive', strpos($html, '{{') !== false);
    checkTrue('font-weight:bold present', strpos($html, 'font-weight:bold') !== false);
    checkTrue('font-style:italic present', strpos($html, 'font-style:italic') !== false);
    checkTrue('text-decoration:underline present', strpos($html, 'text-decoration:underline') !== false);
    checkTrue('font-color hex present', strpos($html, 'color:#2266CC') !== false);
    checkTrue('font-family is SINGLE-quoted inside the double-quoted style attribute (real bug hit while building this -- double-quoting it truncated the whole attribute silently)', strpos($html, "font-family:'DejaVu Sans'") !== false);
    checkFalse('no malformed nested double-quote in any style attribute', (bool)preg_match('/style="[^"]*"[^>]*"/', $html));

    echo "=== EmploymentCertificateRenderer: watermark ===\n";
    $htmlNoWatermark = $renderer->buildHtml(['page_size' => 'A4', 'orientation' => 'portrait'], 'th', [], ['local_name' => 'x'], ['name_th' => 'x'], null, [], null);
    checkFalse('no watermark text when not requested', strpos($htmlNoWatermark, 'SAMPLE') !== false);
    $htmlWithWatermark = $renderer->buildHtml(['page_size' => 'A4', 'orientation' => 'portrait'], 'th', [], ['local_name' => 'x'], ['name_th' => 'x'], null, [], 'SAMPLE ตัวอย่าง');
    checkTrue('custom watermark text appears when requested', strpos($htmlWithWatermark, 'SAMPLE ตัวอย่าง') !== false);
    $htmlEmptyWatermark = $renderer->buildHtml(['page_size' => 'A4', 'orientation' => 'portrait'], 'th', [], ['local_name' => 'x'], ['name_th' => 'x'], null, [], '   ');
    checkFalse('a blank/whitespace-only watermark string renders nothing', strpos($htmlEmptyWatermark, 'rotate(-35deg)') !== false);

    echo "=== EmploymentCertificateRenderer::renderPreview() produces a real, Thai-capable PDF ===\n";
    $pdf = $renderer->renderPreview($compId, ['page_size' => 'A4', 'orientation' => 'portrait'], 'th', [
        ['element_type' => 'text', 'content' => '{{employee_name}} - {{position}}', 'pos_x_pct' => 10, 'pos_y_pct' => 10, 'width_pct' => 80, 'height_pct' => 10,
         'font_size' => 16, 'text_align' => 'center', 'font_weight' => 'bold', 'font_style' => 'normal', 'text_decoration' => 'none', 'font_color' => '#000000', 'font_family' => 'th_sarabun_new'],
    ], null, null, null);
    checkTrue('preview PDF starts with %PDF header', strpos($pdf, '%PDF') === 0);
    checkTrue('preview PDF has non-trivial content length', strlen($pdf) > 1500);
    preg_match_all('/\/BaseFont\s*\/([^\s\/\]]+)/', $pdf, $fontMatches);
    checkTrue('PDF actually embeds a TH Sarabun subset font (not a fallback base-14 font like Times/Helvetica)', !empty(array_filter($fontMatches[1], fn($f) => stripos($f, 'THSarabun') !== false)));

    echo "=== EmploymentCertificateRenderer: falls back to the Company Profile logo when the template has none ===\n";
    $tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    $certLogoDir = __DIR__ . '/../public/uploads/employment_cert_logos/' . $compId;
    $companyLogoDir = __DIR__ . '/../public/uploads/company_logos/' . $compId;
    @mkdir($certLogoDir, 0777, true);
    @mkdir($companyLogoDir, 0777, true);
    $certLogoRel = 'public/uploads/employment_cert_logos/' . $compId . '/' . bin2hex(random_bytes(16)) . '.png';
    $companyLogoRel = 'public/uploads/company_logos/' . $compId . '/' . bin2hex(random_bytes(16)) . '.png';
    file_put_contents(__DIR__ . '/../' . $certLogoRel, $tinyPng);
    file_put_contents(__DIR__ . '/../' . $companyLogoRel, $tinyPng);
    try {
        $logoElements = [
            ['element_type' => 'image', 'field_key' => 'company_logo', 'pos_x_pct' => 1, 'pos_y_pct' => 1, 'width_pct' => 20, 'height_pct' => 10,
             'font_size' => 16, 'text_align' => 'left', 'font_weight' => 'normal', 'font_style' => 'normal', 'text_decoration' => 'none', 'font_color' => '#000000', 'font_family' => 'th_sarabun_new'],
        ];
        $pdo->prepare('UPDATE `companies` SET logo_path = :p WHERE id = :id')->execute([':p' => $companyLogoRel, ':id' => $compId]);

        $pdfNoTemplateLogo = $renderer->renderPreview($compId, ['page_size' => 'A4', 'orientation' => 'portrait'], 'th', $logoElements, null, null, null);
        checkTrue('no template logo -> falls back to company logo (image embedded, PDF has real content)', strlen($pdfNoTemplateLogo) > 1000);

        $pdo->prepare('UPDATE `companies` SET logo_path = NULL WHERE id = :id')->execute([':id' => $compId]);
        $pdfNoLogoAtAll = $renderer->renderPreview($compId, ['page_size' => 'A4', 'orientation' => 'portrait'], 'th', $logoElements, null, null, null);
        checkTrue('neither template nor company logo -> still renders a valid PDF (image element just omitted)', strpos($pdfNoLogoAtAll, '%PDF') === 0);
        checkTrue('with no logo at all, the no-template-logo/company-fallback PDF is bigger (actually embedded an image)', strlen($pdfNoTemplateLogo) > strlen($pdfNoLogoAtAll));

        $pdo->prepare('UPDATE `companies` SET logo_path = :p WHERE id = :id')->execute([':p' => $companyLogoRel, ':id' => $compId]);
        $pdfTemplateLogoWins = $renderer->renderPreview($compId, ['page_size' => 'A4', 'orientation' => 'portrait'], 'th', $logoElements, $certLogoRel, null, null);
        checkTrue('template has its own logo -> still renders (template logo takes priority over company fallback)', strlen($pdfTemplateLogoWins) > 1000);
    } finally {
        @unlink(__DIR__ . '/../' . $certLogoRel);
        @unlink(__DIR__ . '/../' . $companyLogoRel);
    }

    echo "=== 2026-08-25: listPaired() / generateOtherLanguage() (TH/EN unified list) ===\n";
    // Re-isolate: every earlier section in this file created its own templates for comp_id=1 and
    // this whole file runs inside ONE shared transaction, so several of theirs are still 'active'
    // at this point -- listPaired()'s pair COUNT assertions below need a clean slate, same reasoning
    // as the top-of-file isolation step (see its own comment).
    $pdo->prepare("UPDATE `employment_certificate_templates` SET status = 'deleted' WHERE comp_id = :comp_id AND status = 'active'")
        ->execute([':comp_id' => $compId]);
    // 'classic' preset carries real Thai free text ($title/$body), not just {{field_key}} tokens --
    // needed to actually prove generateOtherLanguage() clones content VERBATIM (see the confirmed
    // decision: "Generate Auto" = clone structure, free text stays in the original language until
    // the admin edits it, it is NOT translated).
    $pairA = $model->createFromPreset($compId, 'th', 'classic', 'Pair A', $userId);
    checkTrue('pairA THai create succeeded', $pairA['status']);
    $pairAThId = $pairA['template_id'];
    $pairAThRow = $model->get($compId, $pairAThId);
    $pairAKey = $pairAThRow['pair_key'];

    $listedBefore = $model->listPaired($compId);
    check('exactly 1 pair exists so far', count($listedBefore), 1);
    check('pair has TH populated', $listedBefore[0]['th']['id'], $pairAThId);
    check('pair has EN still missing', $listedBefore[0]['en'], null);
    check('pair_key matches the TH row\'s own pair_key', $listedBefore[0]['pair_key'], $pairAKey);

    $genResult = $model->generateOtherLanguage($compId, $pairAThId, $userId);
    checkTrue('generateOtherLanguage() succeeded', $genResult['status']);
    $pairAEnId = $genResult['template_id'];
    $pairAEnRow = $model->get($compId, $pairAEnId);
    check('generated row is language=en', $pairAEnRow['language'], 'en');
    check('generated row shares the SAME pair_key as its TH source', $pairAEnRow['pair_key'], $pairAKey);
    check('generated row keeps the same template_name', $pairAEnRow['template_name'], $pairAThRow['template_name']);
    check('generated row has the same element count as the source', count($pairAEnRow['elements']), count($pairAThRow['elements']));
    $thBodyEl = array_values(array_filter($pairAThRow['elements'], fn($e) => $e['pos_y_pct'] == 40))[0] ?? null;
    $enBodyEl = array_values(array_filter($pairAEnRow['elements'], fn($e) => $e['pos_y_pct'] == 40))[0] ?? null;
    checkTrue('body element exists on both sides', $thBodyEl !== null && $enBodyEl !== null);
    check('free text content is cloned VERBATIM (still Thai, NOT translated -- confirmed design decision)', $enBodyEl['content'] ?? null, $thBodyEl['content'] ?? null);
    check('positions/sizes/fonts also cloned verbatim (pos_x_pct)', $enBodyEl['pos_x_pct'] ?? null, $thBodyEl['pos_x_pct'] ?? null);
    check('positions/sizes/fonts also cloned verbatim (font_size)', $enBodyEl['font_size'] ?? null, $thBodyEl['font_size'] ?? null);

    $listedAfter = $model->listPaired($compId);
    check('still exactly 1 pair (EN joined the existing pair, did not start a new one)', count($listedAfter), 1);
    check('pair now has EN populated', $listedAfter[0]['en']['id'], $pairAEnId);

    $dupGen = $model->generateOtherLanguage($compId, $pairAThId, $userId);
    checkFalse('generating again refuses -- the other language already exists', $dupGen['status']);

    // Manual creation of the missing language (the "or create manually" path, NOT Generate Auto) --
    // resolved via AskUserQuestion: passing the counterpart's pair_key links the new row to the same
    // pair instead of starting a brand-new, unlinked one.
    $pairB = $model->createFromPreset($compId, 'th', 'blank', 'Pair B', $userId);
    $pairBThId = $pairB['template_id'];
    $pairBKey = $model->get($compId, $pairBThId)['pair_key'];
    $pairBEn = $model->createFromPreset($compId, 'en', 'blank', 'Pair B', $userId, $pairBKey);
    checkTrue('manual create of the missing language succeeded', $pairBEn['status']);
    check('manually-created row carries the SAME pair_key passed in', $model->get($compId, $pairBEn['template_id'])['pair_key'], $pairBKey);

    $listedFinal = $model->listPaired($compId);
    check('now exactly 2 pairs total (Pair A and Pair B stayed separate)', count($listedFinal), 2);
    checkTrue('generateOtherLanguage() on an unknown template id fails cleanly', $model->generateOtherLanguage($compId, 999999999, $userId)['status'] === false);

    echo "=== 2026-08-25: margin_mm (page-margin guide, explicit request \"เพิ่มให้ตั้งค่าขอบกระดาษได้\") ===\n";
    $pdo->prepare("UPDATE `employment_certificate_templates` SET status = 'deleted' WHERE comp_id = :comp_id AND status = 'active'")
        ->execute([':comp_id' => $compId]);
    $marginCreate = $model->createFromPreset($compId, 'th', 'blank', 'Margin Test', $userId);
    check('new template defaults margin_mm to 15.00', $model->get($compId, $marginCreate['template_id'])['margin_mm'], '15.00');
    $marginSave = $model->save($compId, [
        'id' => $marginCreate['template_id'], 'language' => 'th', 'template_name' => 'Margin Test',
        'page_size' => 'A4', 'orientation' => 'portrait', 'margin_mm' => 22, 'elements' => [],
    ], $userId);
    checkTrue('save() with a custom margin_mm succeeds', $marginSave['status']);
    check('margin_mm persisted as saved', $model->get($compId, $marginCreate['template_id'])['margin_mm'], '22.00');
    $marginClamped = $model->save($compId, [
        'id' => $marginCreate['template_id'], 'language' => 'th', 'template_name' => 'Margin Test',
        'page_size' => 'A4', 'orientation' => 'portrait', 'margin_mm' => 9999, 'elements' => [],
    ], $userId);
    checkTrue('save() with an out-of-range margin_mm still succeeds (clamped, not rejected)', $marginClamped['status']);
    check('margin_mm is clamped to the max (50)', $model->get($compId, $marginCreate['template_id'])['margin_mm'], '50.00');
    $marginDup = $model->duplicate($compId, $marginCreate['template_id'], $userId);
    check('duplicate() carries the source margin_mm forward (not reset to the 15.00 default)', $model->get($compId, $marginDup['template_id'])['margin_mm'], '50.00');

    echo "=== 2026-08-25: duplicatePair() (unified list's single per-pair Duplicate action) ===\n";
    $pdo->prepare("UPDATE `employment_certificate_templates` SET status = 'deleted' WHERE comp_id = :comp_id AND status = 'active'")
        ->execute([':comp_id' => $compId]);
    $dupPairTh = $model->createFromPreset($compId, 'th', 'classic', 'Dup Pair', $userId);
    $dupPairKey = $model->get($compId, $dupPairTh['template_id'])['pair_key'];
    $dupPairEn = $model->createFromPreset($compId, 'en', 'blank', 'Dup Pair', $userId, $dupPairKey);
    checkTrue('duplicatePair() succeeds for a pair with both languages', $model->duplicatePair($compId, $dupPairKey, $userId)['status']);
    $afterDup = $model->listPaired($compId);
    check('duplicatePair() results in exactly 2 pairs (original + copy)', count($afterDup), 2);
    $copiedPair = null;
    foreach ($afterDup as $p) { if ($p['pair_key'] !== $dupPairKey) { $copiedPair = $p; } }
    checkTrue('the copy is a genuinely different pair_key', $copiedPair !== null);
    checkTrue('the copy has BOTH languages (th)', $copiedPair['th'] !== null);
    checkTrue('the copy has BOTH languages (en)', $copiedPair['en'] !== null);
    $copiedThElements = $model->get($compId, $copiedPair['th']['id'])['elements'];
    $sourceThElements = $model->get($compId, $dupPairTh['template_id'])['elements'];
    check('the copy\'s TH element count matches the source (classic preset)', count($copiedThElements), count($sourceThElements));
    checkFalse('duplicatePair() on an unknown pair_key fails cleanly', $model->duplicatePair($compId, 'no_such_pair_key', $userId)['status']);

    echo "=== 2026-08-25: shape/table element types + multi-page (save()/validateElements()) ===\n";
    $pdo->prepare("UPDATE `employment_certificate_templates` SET status = 'deleted' WHERE comp_id = :comp_id AND status = 'active'")
        ->execute([':comp_id' => $compId]);
    $richCreate = $model->createFromPreset($compId, 'th', 'blank', 'Rich Elements Test', $userId);
    $richId = $richCreate['template_id'];
    $baseEl = ['pos_x_pct' => 10, 'pos_y_pct' => 10, 'width_pct' => 20, 'height_pct' => 10,
        'font_size' => 14, 'text_align' => 'left', 'font_weight' => 'normal', 'font_style' => 'normal',
        'text_decoration' => 'none', 'font_color' => '#000000', 'font_family' => 'th_sarabun_new'];
    $richElements = [
        array_merge($baseEl, ['element_type' => 'text', 'content' => 'Page 1 text', 'page_number' => 1]),
        array_merge($baseEl, ['element_type' => 'shape', 'field_key' => 'ellipse', 'font_color' => '#FF9900', 'page_number' => 1]),
        array_merge($baseEl, ['element_type' => 'text', 'content' => 'Page 2 text', 'page_number' => 2]),
        array_merge($baseEl, ['element_type' => 'table', 'page_number' => 2, 'content' => json_encode([
            'rows' => 2, 'cols' => 2, 'border_color' => '#333333', 'border_width' => 2,
            'cells' => [['A1', 'B1'], ['A2', 'B2']],
        ])]),
    ];
    $richSave = $model->save($compId, [
        'id' => $richId, 'language' => 'th', 'template_name' => 'Rich Elements Test',
        'page_size' => 'A4', 'orientation' => 'portrait', 'elements' => $richElements,
    ], $userId);
    checkTrue('save() with shape/table/multi-page elements succeeds', $richSave['status']);

    $richGot = $model->get($compId, $richId);
    check('4 elements persisted', count($richGot['elements']), 4);
    $shapeEl = array_values(array_filter($richGot['elements'], fn($e) => $e['element_type'] === 'shape'))[0] ?? null;
    checkTrue('shape element round-trips', $shapeEl !== null);
    check('shape field_key persisted as ellipse', $shapeEl['field_key'] ?? null, 'ellipse');
    $tableEl = array_values(array_filter($richGot['elements'], fn($e) => $e['element_type'] === 'table'))[0] ?? null;
    checkTrue('table element round-trips', $tableEl !== null);
    $tableData = json_decode($tableEl['content'], true);
    check('table content re-encoded with rows=2', $tableData['rows'], 2);
    check('table content re-encoded with cols=2', $tableData['cols'], 2);
    check('table cell text preserved', $tableData['cells'][1][1], 'B2');
    check('table border_color preserved', $tableData['border_color'], '#333333');
    check('page_number persisted for page-2 elements', (int)$tableEl['page_number'], 2);

    // Invalid shape type / malformed table content must both be rejected, not silently coerced.
    $badShapeSave = $model->save($compId, [
        'id' => $richId, 'language' => 'th', 'template_name' => 'Rich Elements Test',
        'page_size' => 'A4', 'orientation' => 'portrait',
        'elements' => [array_merge($baseEl, ['element_type' => 'shape', 'field_key' => 'triangle'])],
    ], $userId);
    checkFalse('save() rejects an unknown shape type', $badShapeSave['status']);
    $badTableSave = $model->save($compId, [
        'id' => $richId, 'language' => 'th', 'template_name' => 'Rich Elements Test',
        'page_size' => 'A4', 'orientation' => 'portrait',
        'elements' => [array_merge($baseEl, ['element_type' => 'table', 'content' => json_encode(['rows' => 999, 'cols' => 2])])],
    ], $userId);
    checkFalse('save() rejects out-of-range table rows', $badTableSave['status']);

    echo "=== 2026-08-25: multi-page rendering -> multiple .cert-page divs with page-break-after ===\n";
    $richHtml = $renderer->buildHtml(
        ['page_size' => 'A4', 'orientation' => 'portrait'],
        'th', $richGot['elements'],
        $renderer->fetchCompany($compId) ?? [], $renderer->fetchAnyEmployee($compId) ?? [], null
    );
    check('exactly 2 .cert-page divs (one per distinct page_number)', substr_count($richHtml, 'class="cert-page"'), 2);
    checkTrue('first page has page-break-after:always', strpos($richHtml, 'page-break-after:always') !== false);
    checkTrue('rendered HTML contains the shape as a plain div (no text content)', strpos($richHtml, '#ff9900') !== false || strpos($richHtml, '#FF9900') !== false);
    checkTrue('rendered HTML contains the table <table> markup', strpos($richHtml, '<table') !== false);
    checkTrue('rendered HTML contains the table cell text', strpos($richHtml, 'B2') !== false);

    $richPdf = $renderer->renderPreview($compId, ['page_size' => 'A4', 'orientation' => 'portrait'], 'th', $richGot['elements'], null, null, null);
    checkTrue('multi-page/shape/table PDF renders without error', strpos($richPdf, '%PDF') === 0);

    echo "=== 2026-08-25: 7 font_family options all produce a valid PDF (canvas/PDF parity, not just default) ===\n";
    foreach (['th_sarabun_new', 'dejavu_sans', 'dejavu_sans_mono', 'dejavu_serif', 'helvetica', 'times_new_roman', 'courier'] as $fontCode) {
        $fontEl = [array_merge($baseEl, ['element_type' => 'text', 'content' => 'Font test ' . $fontCode, 'font_family' => $fontCode])];
        $fontSave = $model->save($compId, [
            'id' => $richId, 'language' => 'th', 'template_name' => 'Rich Elements Test',
            'page_size' => 'A4', 'orientation' => 'portrait', 'elements' => $fontEl,
        ], $userId);
        checkTrue("save() accepts font_family '{$fontCode}'", $fontSave['status']);
        $fontPdf = $renderer->renderPreview($compId, ['page_size' => 'A4', 'orientation' => 'portrait'], 'th', $model->get($compId, $richId)['elements'], null, null, null);
        checkTrue("font_family '{$fontCode}' renders a valid PDF", strpos($fontPdf, '%PDF') === 0);
    }
    $badFontSave = $model->save($compId, [
        'id' => $richId, 'language' => 'th', 'template_name' => 'Rich Elements Test',
        'page_size' => 'A4', 'orientation' => 'portrait',
        'elements' => [array_merge($baseEl, ['element_type' => 'text', 'content' => 'x', 'font_family' => 'comic_sans'])],
    ], $userId);
    checkFalse('save() rejects an unrecognized font_family', $badFontSave['status']);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
