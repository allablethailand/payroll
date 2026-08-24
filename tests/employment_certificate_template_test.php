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

    echo "=== fieldTypeOptions() / presetOptions() ===\n";
    $fieldOptions = $model->fieldTypeOptions();
    check('15 field types seeded', count($fieldOptions), 15);
    $presets = $model->presetOptions();
    check('4 presets available (blank + 3 standard layouts)', count($presets), 4);
    checkTrue('classic/modern/minimal are all present', in_array('classic', array_column($presets, 'code'), true)
        && in_array('modern', array_column($presets, 'code'), true) && in_array('minimal', array_column($presets, 'code'), true));

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

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
