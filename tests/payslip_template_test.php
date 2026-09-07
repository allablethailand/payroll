<?php
/**
 * Lightweight verification script for PayslipTemplateModel (canvas CRUD, presets, reusable image
 * library, element validation, single-active-default enforcement) and PayslipTemplateRenderer
 * (token substitution incl. the 3 "block" fields that expand into a real itemized table, shape/table
 * elements, multi-page, all 7 font_family codes). Not PHPUnit -- see tests/statutory_engine_test.php
 * for why. Runs against the real dev DB inside a transaction that is always rolled back.
 *
 * 2026-08-25, rebuilt for the canvas designer (explicit request: "ปรับให้การตั้งค่า Slip เงินเดือน
 * Template เป็นเหมือนกับใบรับรอง") -- the OLD version of this file tested the retired ordered
 * field-list API (`save(array $data, int $compId, ...)`, `fields: [{field_key}]`); every assertion
 * below targets the new canvas API instead (`save(int $compId, array $data, ...)`,
 * `elements: [{element_type, pos_x_pct, ...}]`) -- mirrors tests/employment_certificate_template_test.php's
 * own structure closely since PayslipTemplateModel/Renderer are direct ports of that module's own
 * classes (see both classes' own docblocks for the architectural differences that were kept).
 *
 * 2026-08-25, same-day follow-up rewrite ("การทำ 2 ภาษาอยากให้เป็นเหมือนหน้าของเอกสาร และรูปแบบการทำ
 * เหมือนกัน") -- `language_mode`('th'/'en'/'both', one shared canvas) is GONE, replaced by
 * `language`(strictly 'th'/'en') + `pair_key`, same as Employment Certificate Template's own. Every
 * save()/createFromPreset()/list()/getDefault()/resolveTemplateForEmployee()/presetPreviewElements()
 * call below now passes an explicit language; a new "TH/EN pair" section mirrors Employment
 * Certificate Template's own listPaired()/getPairByKey()/generateOtherLanguage()/duplicatePair()
 * assertions.
 *
 * Run with: php tests/payslip_template_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PayslipTemplateModel.php';
require_once __DIR__ . '/../app/services/PayslipTemplateRenderer.php';

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
    $renderer = new PayslipTemplateRenderer($pdo);

    // Isolation step, same reasoning/pattern as tests/employment_certificate_template_test.php's own
    // top-of-file comment (see feedback_dev_db_shared_state_test_fragility memory) -- comp_id=1 is
    // the real dev DB and may have genuine admin-created templates/images at any time (confirmed:
    // a real row "Slip เงินเดือน" showed up here while this test was being written). Must set
    // `deleted_at` too, not just `status` -- unlike Employment Certificate Template's own model,
    // PayslipTemplateModel::list()/get() filter on `deleted_at IS NULL` (a real audit column this
    // table already had before the canvas rebuild), not `status`, so an isolation step that only
    // flips `status` silently fails to hide the row (caught exactly this way: `list()` still
    // returned the real row even after this UPDATE, until `deleted_at` was added here too).
    $pdo->prepare("UPDATE `payslip_templates` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP WHERE comp_id = :comp_id AND deleted_at IS NULL")
        ->execute([':comp_id' => $compId]);
    $pdo->prepare("DELETE FROM `payslip_images` WHERE comp_id = :comp_id")->execute([':comp_id' => $compId]);

    echo "=== fieldTypeOptions() / presetOptions() ===\n";
    $fieldOptions = $model->fieldTypeOptions();
    // 20 original + static_text (new, added for the canvas designer's free-text/paragraph element,
    // mirrors Employment Certificate Template's own field of the same name/purpose) + 2026-08-26's
    // new company_signature ("เพิ่มให้แนบลายเซ็นต์...และเพิ่มใน Item ในการจัดการ Template") +
    // 2026-09-04's new payslip_number (Backlog Phase 11, T061 -- DocumentNumberingModel wired to
    // PaySlipReport, selectable on the canvas as {{payslip_number}}).
    check('23 field types seeded (20 original + static_text + company_signature + payslip_number)', count($fieldOptions), 23);
    checkTrue('static_text field type is present', in_array('static_text', array_column($fieldOptions, 'code'), true));
    checkTrue('company_logo/company_signature are element_type=image, everything else is text', (function () use ($fieldOptions) {
        $imageCodes = ['company_logo', 'company_signature'];
        foreach ($fieldOptions as $ft) {
            $expected = in_array($ft['code'], $imageCodes, true) ? 'image' : 'text';
            if ($ft['element_type'] !== $expected) return false;
        }
        return true;
    })());
    $presets = $model->presetOptions();
    check('4 presets available (blank + classic/modern/minimal)', count($presets), 4);
    foreach (['classic', 'modern', 'minimal'] as $code) {
        checkTrue("preset '{$code}' has real elements (th)", count($model->presetPreviewElements($code, 'th')) > 0);
        checkTrue("preset '{$code}' has real elements (en)", count($model->presetPreviewElements($code, 'en')) > 0);
    }
    check('blank preset has no elements', $model->presetPreviewElements('blank', 'th'), []);
    $invalidPresetThrew = false;
    try {
        $model->presetPreviewElements('not_a_real_preset', 'th');
    } catch (InvalidArgumentException $e) {
        $invalidPresetThrew = true;
    }
    checkTrue('an invalid preset code throws InvalidArgumentException', $invalidPresetThrew);
    $invalidLanguageThrew = false;
    try {
        $model->presetPreviewElements('classic', 'fr');
    } catch (InvalidArgumentException $e) {
        $invalidLanguageThrew = true;
    }
    checkTrue('an invalid language code throws InvalidArgumentException', $invalidLanguageThrew);

    echo "=== list()/get() before anything is saved ===\n";
    check('list() is empty', $model->list($compId, 'th'), []);
    check('getDefault() is null', $model->getDefault($compId, 'th'), null);

    echo "=== createFromPreset() + is_default auto-assignment on the very first template PER LANGUAGE ===\n";
    $t1 = $model->createFromPreset($compId, 'th', 'classic', 'Template One', $userId);
    checkTrue('createFromPreset (classic, th) succeeds', $t1['status']);
    $t1Row = $model->get($compId, (int)$t1['template_id']);
    check('the first TH template ever saved auto-becomes default', (int)$t1Row['is_default'], 1);
    check('country_code auto-derived from company', $t1Row['country_code'], 'TH');
    checkTrue('classic preset elements carried through save()', count($t1Row['elements']) > 0);
    checkTrue('a pair_key was auto-assigned', !empty($t1Row['pair_key']));

    $t2 = $model->createFromPreset($compId, 'th', 'blank', 'Template Two', $userId);
    checkTrue('createFromPreset (blank, th) succeeds', $t2['status']);
    $t2Row = $model->get($compId, (int)$t2['template_id']);
    check('a later new TH template does NOT auto-become default', (int)$t2Row['is_default'], 0);
    check('blank preset really has zero elements', count($t2Row['elements']), 0);
    checkTrue('t1 and t2 have DIFFERENT pair_keys (each an unpaired, brand-new template)', $t1Row['pair_key'] !== $t2Row['pair_key']);

    $tEn1 = $model->createFromPreset($compId, 'en', 'classic', 'EN Template One', $userId);
    checkTrue('createFromPreset (classic, en) succeeds', $tEn1['status']);
    check('the first EN template ever saved ALSO auto-becomes default (per-language, independent of TH)', (int)$model->get($compId, (int)$tEn1['template_id'])['is_default'], 1);

    echo "=== is_default single-active-PER-LANGUAGE enforcement ===\n";
    $model->save($compId, [
        'id' => $t2['template_id'], 'language' => 'th', 'template_name' => 'Template Two', 'is_default' => true, 'status' => 'active',
        'elements' => [],
    ], $userId);
    $list = $model->list($compId, 'th');
    check('2 TH templates listed', count($list), 2);
    $defaults = array_filter($list, fn($t) => (int)$t['is_default'] === 1);
    check('exactly one TH template is default after setting the 2nd one', count($defaults), 1);
    check('template 2 is now the TH default', (int)array_values($defaults)[0]['id'], (int)$t2['template_id']);
    check('the EN default is UNAFFECTED by the TH default change', (int)$model->get($compId, (int)$tEn1['template_id'])['is_default'], 1);

    echo "=== setDefault() explicit list-star action ===\n";
    $setDef = $model->setDefault($compId, (int)$t1['template_id'], $userId);
    checkTrue('setDefault() succeeds', $setDef['status']);
    check('template 1 is default again', (int)$model->get($compId, (int)$t1['template_id'])['is_default'], 1);
    check('template 2 no longer default', (int)$model->get($compId, (int)$t2['template_id'])['is_default'], 0);

    echo "=== save() with shape/table/multi-page/all field types ===\n";
    $baseEl = ['pos_x_pct' => 10, 'pos_y_pct' => 10, 'width_pct' => 20, 'height_pct' => 10,
        'font_size' => 14, 'text_align' => 'left', 'font_weight' => 'normal', 'font_style' => 'normal',
        'text_decoration' => 'none', 'font_color' => '#000000', 'font_family' => 'th_sarabun_new'];
    $richElements = [
        array_merge($baseEl, ['element_type' => 'text', 'content' => '{{employee_name}}', 'page_number' => 1]),
        array_merge($baseEl, ['element_type' => 'image', 'field_key' => 'company_logo', 'content' => null, 'page_number' => 1]),
        array_merge($baseEl, ['element_type' => 'shape', 'field_key' => 'ellipse', 'font_color' => '#FF9900', 'page_number' => 1]),
        array_merge($baseEl, ['element_type' => 'text', 'content' => '{{earning_lines_all}}', 'page_number' => 2]),
        array_merge($baseEl, ['element_type' => 'table', 'page_number' => 2, 'content' => json_encode([
            'rows' => 2, 'cols' => 2, 'border_color' => '#333333', 'border_width' => 2,
            'cells' => [['A1', 'B1'], ['A2', 'B2']],
        ])]),
    ];
    $richSave = $model->save($compId, [
        'id' => $t2['template_id'], 'language' => 'th', 'template_name' => 'Template Two',
        'header_text_th' => 'ทดสอบหัวกระดาษ', 'footer_text_en' => 'Test footer', 'status' => 'active',
        'page_size' => 'A4', 'orientation' => 'portrait', 'elements' => $richElements,
    ], $userId);
    checkTrue('save() with shape/table/multi-page/image elements succeeds', $richSave['status']);
    $richGot = $model->get($compId, (int)$t2['template_id']);
    check('5 elements persisted', count($richGot['elements']), 5);
    check('header_text_th persisted', $richGot['header_text_th'], 'ทดสอบหัวกระดาษ');
    check('footer_text_en persisted', $richGot['footer_text_en'], 'Test footer');
    $shapeEl = array_values(array_filter($richGot['elements'], fn($e) => $e['element_type'] === 'shape'))[0] ?? null;
    checkTrue('shape element round-trips', $shapeEl !== null && $shapeEl['field_key'] === 'ellipse');
    $tableEl = array_values(array_filter($richGot['elements'], fn($e) => $e['element_type'] === 'table'))[0] ?? null;
    checkTrue('table element round-trips', $tableEl !== null);
    $tableData = json_decode($tableEl['content'], true);
    check('table cell text preserved', $tableData['cells'][1][1], 'B2');
    check('page_number persisted for the page-2 elements', (int)$tableEl['page_number'], 2);

    echo "=== Validation ===\n";
    checkFalse('missing language rejected', $model->save($compId, ['template_name' => 'X', 'elements' => []], $userId)['status']);
    checkFalse('invalid language rejected', $model->save($compId, ['language' => 'fr', 'template_name' => 'X', 'elements' => []], $userId)['status']);
    checkFalse('missing template_name rejected', $model->save($compId, ['language' => 'th', 'template_name' => '', 'elements' => []], $userId)['status']);
    checkFalse('invalid element_type rejected', $model->save($compId, [
        'language' => 'th', 'template_name' => 'X', 'elements' => [array_merge($baseEl, ['element_type' => 'bogus'])],
    ], $userId)['status']);
    checkFalse('invalid shape type rejected', $model->save($compId, [
        'language' => 'th', 'template_name' => 'X', 'elements' => [array_merge($baseEl, ['element_type' => 'shape', 'field_key' => 'triangle'])],
    ], $userId)['status']);
    checkFalse('invalid font_family rejected', $model->save($compId, [
        'language' => 'th', 'template_name' => 'X', 'elements' => [array_merge($baseEl, ['element_type' => 'text', 'content' => 'x', 'font_family' => 'comic_sans'])],
    ], $userId)['status']);
    checkFalse('out-of-range position rejected', $model->save($compId, [
        'language' => 'th', 'template_name' => 'X', 'elements' => [array_merge($baseEl, ['element_type' => 'text', 'content' => 'x', 'pos_x_pct' => 150])],
    ], $userId)['status']);
    checkFalse('out-of-range table dimensions rejected', $model->save($compId, [
        'language' => 'th', 'template_name' => 'X', 'elements' => [array_merge($baseEl, ['element_type' => 'table', 'content' => json_encode(['rows' => 999, 'cols' => 2])])],
    ], $userId)['status']);
    checkFalse('image element with neither field_key nor image_asset_id rejected', $model->save($compId, [
        'language' => 'th', 'template_name' => 'X', 'elements' => [array_merge($baseEl, ['element_type' => 'image', 'content' => null])],
    ], $userId)['status']);

    echo "=== duplicate() ===\n";
    $dup = $model->duplicate($compId, (int)$t2['template_id'], $userId);
    checkTrue('duplicate() succeeds', $dup['status']);
    $dupRow = $model->get($compId, (int)$dup['template_id']);
    check('duplicate name has (Copy) suffix', $dupRow['template_name'], 'Template Two (Copy)');
    check('duplicate is never itself default', (int)$dupRow['is_default'], 0);
    check('duplicate carries the same element count as the source', count($dupRow['elements']), count($richGot['elements']));

    // 2026-08-26: publish_status defaults to 'draft' on every INSERT (see save()'s own comment) --
    // getDefault()/resolveTemplateForEmployee() now also require publish_status='public', so fixtures
    // that these two functions are expected to actually resolve need an explicit publish first.
    checkTrue('t1 published', $model->setPublishStatus($compId, (int)$t1['template_id'], 'public', $userId)['status']);
    checkTrue('t2 published', $model->setPublishStatus($compId, (int)$t2['template_id'], 'public', $userId)['status']);

    echo "=== toggleStatus() clears is_default ===\n";
    $toggle = $model->toggleStatus($compId, (int)$t1['template_id'], $userId);
    checkTrue('toggle status on the default template succeeds', $toggle['status']);
    check('toggled to inactive', $toggle['new_status'], 'inactive');
    check('deactivating the default template clears is_default', (int)$model->get($compId, (int)$t1['template_id'])['is_default'], 0);
    // getDefault() falls back to the most-recently-updated ACTIVE template when none is explicitly
    // flagged is_default (same fallback as EmploymentCertificateTemplateModel::getDefault()) -- it
    // only ever returns null when there is truly NO active template left for that language at all.
    checkTrue('getDefault() falls back to another active TH template once the explicit default is deactivated', $model->getDefault($compId, 'th') !== null);
    check('the fallback is NOT the now-inactive template 1', (int)$model->getDefault($compId, 'th')['id'] === (int)$t1['template_id'], false);

    echo "=== delete() ===\n";
    $del = $model->delete($compId, (int)$dup['template_id'], $userId);
    checkTrue('delete succeeds', $del['status']);
    check('deleted template no longer retrievable', $model->get($compId, (int)$dup['template_id']), null);

    echo "=== Reusable image library (payslip_images) ===\n";
    check('image library starts empty', $model->listImages($compId), []);
    $imgAdd = $model->addImage($compId, 'public/uploads/payslip_images/' . $compId . '/' . bin2hex(random_bytes(16)) . '.png', 'test.png', $userId);
    checkTrue('addImage() succeeds with a valid path', $imgAdd['status']);
    check('image library now has 1 entry', count($model->listImages($compId)), 1);
    checkFalse('addImage() rejects a path outside this company\'s own folder', $model->addImage($compId, 'public/uploads/payslip_images/999/x.png', null, $userId)['status']);
    $imgUseSave = $model->save($compId, [
        'language' => 'th', 'template_name' => 'Image User', 'elements' => [array_merge($baseEl, ['element_type' => 'image', 'content' => null, 'image_asset_id' => $imgAdd['id']])],
    ], $userId);
    checkTrue('template referencing the image asset saves', $imgUseSave['status']);
    checkFalse('deleteImage() refuses while still referenced', $model->deleteImage($compId, (int)$imgAdd['id'])['status']);
    // Soft-deleting the template (delete(), same as Employment Certificate Template's own delete())
    // does NOT cascade-clear its `payslip_template_elements` rows -- they stay behind, orphaned under
    // a deleted template, until that template is edited again (which never happens once deleted).
    // deleteImage()'s usage check queries that table directly with no join on template status, so it
    // correctly keeps refusing here too -- same real characteristic already present in Employment
    // Certificate Template's own deleteImage(), not something introduced by this port. Removing the
    // element itself (not the template) is what actually clears the reference.
    $model->delete($compId, (int)$imgUseSave['template_id'], $userId);
    checkFalse('deleteImage() still refuses after the referencing TEMPLATE is soft-deleted (its elements are not cascade-cleared)', $model->deleteImage($compId, (int)$imgAdd['id'])['status']);
    $pdo->prepare("DELETE FROM `payslip_template_elements` WHERE image_asset_id = :id")->execute([':id' => $imgAdd['id']]);
    $imgDel = $model->deleteImage($compId, (int)$imgAdd['id']);
    checkTrue('deleteImage() succeeds once the referencing element row is actually gone', $imgDel['status']);
    check('image library empty again', count($model->listImages($compId)), 0);

    echo "=== PayslipTemplateRenderer: multi-page -> multiple .payslip-page divs with page-break-after ===\n";
    $fakeCompany = ['local_name' => 'Test Co', 'address_line_1' => '123 Test Rd', 'global_tax_id' => '1234567890123',
        'authorized_signatory_name' => 'Jane Doe', 'registered_country' => 'TH', 'logo_path' => null];
    $fakeRun = ['id' => 0, 'period_start_date' => '2026-08-01', 'period_end_date' => '2026-08-31', 'payment_date' => '2026-09-01'];
    $fakeDetail = [
        'comp_id' => $compId, 'employee_id' => 0, 'employee_no' => 'EMP-0001',
        'name_th' => 'สมชาย', 'surname_th' => 'ใจดี', 'name_en' => 'Somchai', 'surname_en' => 'Jaidee',
        'department_name_th' => 'ฝ่ายบุคคล', 'department_name_en' => 'HR', 'position_name_th' => 'จนท.', 'position_name_en' => 'Officer',
        'base_salary_amount' => 30000, 'gross_amount' => 32000, 'total_deduction_amount' => 2000, 'net_amount' => 30000,
        'earning_breakdown' => [['code' => 'OT', 'name_th' => 'OT', 'amount' => 2000]],
        'deduction_breakdown' => [['code' => 'LOAN', 'name_th' => 'Loan', 'amount' => 500]],
        'statutory_breakdown' => [['code' => 'TH_SSO', 'employee_amount' => 1500]],
        'bank_account_no' => null, 'key_version' => null,
    ];
    $richHtml = $renderer->buildHtml(
        ['page_size' => 'A4', 'orientation' => 'portrait', 'language' => 'th'],
        $richGot['elements'], $fakeCompany, $fakeRun, $fakeDetail, [], null, [], null, null
    );
    check('exactly 2 .payslip-page divs (one per distinct page_number)', substr_count($richHtml, 'class="payslip-page"'), 2);
    checkTrue('has page-break-after:always', strpos($richHtml, 'page-break-after:always') !== false);
    checkTrue('block field earning_lines_all expanded into a real <table> with the OT line', strpos($richHtml, '<table') !== false && strpos($richHtml, 'OT') !== false);
    checkTrue('the manually-authored table element also renders its own <table>', substr_count($richHtml, '<table') >= 2);

    $richPdf = $renderer->renderPdf(['page_size' => 'A4', 'orientation' => 'portrait'], $richHtml);
    checkTrue('multi-page/shape/table PDF renders without error', strpos($richPdf, '%PDF') === 0);

    echo "=== PayslipTemplateRenderer: all 7 font_family options + block-field table expansion ===\n";
    foreach (['th_sarabun_new', 'dejavu_sans', 'dejavu_sans_mono', 'dejavu_serif', 'helvetica', 'times_new_roman', 'courier'] as $fontCode) {
        $fontEl = [array_merge($baseEl, ['element_type' => 'text', 'content' => 'Font test ' . $fontCode, 'font_family' => $fontCode])];
        $fontSave = $model->save($compId, ['language' => 'th', 'template_name' => 'Font Test', 'elements' => $fontEl], $userId);
        checkTrue("save() accepts font_family '{$fontCode}'", $fontSave['status']);
        $fontHtml = $renderer->buildHtml(['page_size' => 'A4', 'orientation' => 'portrait', 'language' => 'th'],
            $model->get($compId, (int)$fontSave['template_id'])['elements'], $fakeCompany, $fakeRun, $fakeDetail, [], null, [], null, null);
        $fontPdf = $renderer->renderPdf(['page_size' => 'A4', 'orientation' => 'portrait'], $fontHtml);
        checkTrue("font_family '{$fontCode}' renders a valid PDF", strpos($fontPdf, '%PDF') === 0);
        $model->delete($compId, (int)$fontSave['template_id'], $userId);
    }

    echo "=== PayslipTemplateRenderer::buildTokens() -- th/en language picking (2026-08-25 follow-up: 'both' mode is GONE, every template is strictly one language now) ===\n";
    $tokensTh = $renderer->buildTokens('th', $fakeCompany, $fakeRun, $fakeDetail, null);
    check('th language uses only the Thai name', $tokensTh['employee_name'], 'สมชาย ใจดี');
    $tokensEn = $renderer->buildTokens('en', $fakeCompany, $fakeRun, $fakeDetail, null);
    check('en language uses only the English name', $tokensEn['employee_name'], 'Somchai Jaidee');
    check('ytd_summary is "-" when no YTD data is passed', $tokensTh['ytd_summary'], '-');
    $tokensYtd = $renderer->buildTokens('th', $fakeCompany, $fakeRun, $fakeDetail, ['ytd_gross' => 90000.0, 'ytd_deduction' => 6000.0, 'ytd_net' => 84000.0]);
    checkTrue('ytd_summary is a combined formatted string when YTD data IS passed', strpos($tokensYtd['ytd_summary'], '90,000.00') !== false && strpos($tokensYtd['ytd_summary'], '84,000.00') !== false);

    echo "=== Assign To (department/team/employee scoping, explicit request: \"สามารถ Assign ตั้งค่าให้พนักงาน เป็นรายแผนก รายทีม หรือรายคน หรือใช้งานร่วมกันทั้งหมดก็ได้\") ===\n";
    // Fresh fixtures: a department, a team, and 3 employees -- one in the department only, one in
    // the team only (same department too, since team doesn't replace department), and one with a
    // direct employee-level assignment (also in both, to actually prove employee wins over both).
    $pdo->prepare("UPDATE `payslip_templates` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP WHERE comp_id = :comp_id AND deleted_at IS NULL")
        ->execute([':comp_id' => $compId]);
    $pdo->prepare("INSERT INTO `structure_departments` (comp_id, department_code, department_name_th, department_name_en, status) VALUES (:c, :code, 'แผนกทดสอบ', 'Test Dept', 'active')")
        ->execute([':c' => $compId, ':code' => 'DEPT_ASSIGN_' . uniqid()]);
    $deptId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO `structure_teams` (comp_id, team_code, team_name_th, team_name_en, status) VALUES (:c, :code, 'ทีมทดสอบ', 'Test Team', 'active')")
        ->execute([':c' => $compId, ':code' => 'TEAM_ASSIGN_' . uniqid()]);
    $teamId = (int)$pdo->lastInsertId();
    function makeAssignTestEmployee(PDO $pdo, int $compId, ?int $deptId, ?int $teamId, string $tag): int {
        $enc = EncryptionService::encrypt('0000000000000');
        $stmt = $pdo->prepare("INSERT INTO `employees`
            (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
             tax_id_no, key_version, personal_email, mobile_no, address_line_1_register, address_line_1_contact,
             emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
             employment_date, employment_status, employment_type, workforce_type, record_time_method,
             salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt, department_id, team_id)
            VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, 'ทดสอบ', :name_en, 'Test', '1990-01-01', 'Thai',
             :tax_id_no, :key_version, :email, '0800000000', 'Test Address', 'Test Address',
             'Emergency', 'Contact', 'friend', '0899999999',
             '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
             'monthly', 30000, '2020-01-01', 'average', 'active',
             1, 1, 0, :department_id, :team_id)");
        $stmt->execute([
            ':comp_id' => $compId, ':employee_no' => 'ASSIGN_' . $tag . '_' . uniqid(),
            ':name_th' => $tag, ':name_en' => $tag,
            ':tax_id_no' => $enc['value'], ':key_version' => $enc['key_version'],
            ':email' => uniqid() . '@test.local', ':department_id' => $deptId, ':team_id' => $teamId,
        ]);
        return (int)$pdo->lastInsertId();
    }
    $deptOnlyEmpId = makeAssignTestEmployee($pdo, $compId, $deptId, null, 'DeptOnly');
    $teamEmpId = makeAssignTestEmployee($pdo, $compId, $deptId, $teamId, 'TeamMember');
    $directEmpId = makeAssignTestEmployee($pdo, $compId, $deptId, $teamId, 'DirectAssign');
    $unmatchedEmpId = makeAssignTestEmployee($pdo, $compId, null, null, 'Unmatched'); // no department/team at all -> matches nothing

    $defaultTpl = $model->createFromPreset($compId, 'th', 'blank', 'Company Default', $userId);
    checkTrue('company-default (unscoped) TH template created', $defaultTpl['status']);
    $deptTpl = $model->save($compId, [
        'language' => 'th', 'template_name' => 'Dept Scoped', 'is_default' => false, 'elements' => [],
        'assignments' => [['scope_type' => 'department', 'scope_id' => $deptId]],
    ], $userId);
    checkTrue('department-scoped template saves', $deptTpl['status']);
    $teamTpl = $model->save($compId, [
        'language' => 'th', 'template_name' => 'Team Scoped', 'is_default' => false, 'elements' => [],
        'assignments' => [['scope_type' => 'team', 'scope_id' => $teamId]],
    ], $userId);
    checkTrue('team-scoped template saves', $teamTpl['status']);
    $empTpl = $model->save($compId, [
        'language' => 'th', 'template_name' => 'Employee Scoped', 'is_default' => false, 'elements' => [],
        'assignments' => [['scope_type' => 'employee', 'scope_id' => $directEmpId]],
    ], $userId);
    checkTrue('employee-scoped template saves', $empTpl['status']);
    // 2026-08-26: publish_status defaults to 'draft' -- resolveTemplateForEmployee() only matches
    // publish_status='public' templates, same reasoning as the toggleStatus() section above.
    foreach ([$defaultTpl, $deptTpl, $teamTpl, $empTpl] as $tpl) {
        $model->setPublishStatus($compId, (int)$tpl['template_id'], 'public', $userId);
    }

    check('unmatched employee (no dept/team/employee match) resolves to the company default (th)', (int)($model->resolveTemplateForEmployee($compId, $unmatchedEmpId, 'th')['id'] ?? 0), (int)$defaultTpl['template_id']);
    check('dept-only employee resolves to the department-scoped template (th)', (int)($model->resolveTemplateForEmployee($compId, $deptOnlyEmpId, 'th')['id'] ?? 0), (int)$deptTpl['template_id']);
    check('team member (also in the department) resolves to the TEAM template, not department (team is more specific)', (int)($model->resolveTemplateForEmployee($compId, $teamEmpId, 'th')['id'] ?? 0), (int)$teamTpl['template_id']);
    check('directly-assigned employee resolves to the EMPLOYEE template, overriding both team and department', (int)($model->resolveTemplateForEmployee($compId, $directEmpId, 'th')['id'] ?? 0), (int)$empTpl['template_id']);
    check('resolveTemplateForEmployee() returns null for an invalid language', $model->resolveTemplateForEmployee($compId, $directEmpId, 'fr'), null);
    // No EN template exists for any scope yet, and no unscoped EN default either -- resolving in 'en'
    // must fall through to null, not accidentally leak the TH-scoped result.
    check('resolving in "en" (no EN template exists at all) returns null', $model->resolveTemplateForEmployee($compId, $directEmpId, 'en'), null);

    $gotDeptTpl = $model->get($compId, (int)$deptTpl['template_id']);
    check('1 assignment row on the department-scoped template', count($gotDeptTpl['assignments']), 1);
    check('assignment label resolves the real department name', $gotDeptTpl['assignments'][0]['label'], 'แผนกทดสอบ');

    checkFalse('save() rejects an unknown scope_type', $model->save($compId, [
        'language' => 'th', 'template_name' => 'X', 'elements' => [], 'assignments' => [['scope_type' => 'position', 'scope_id' => $deptId]],
    ], $userId)['status']);
    checkFalse('save() rejects a scope_id that does not exist', $model->save($compId, [
        'language' => 'th', 'template_name' => 'X', 'elements' => [], 'assignments' => [['scope_type' => 'department', 'scope_id' => 999999]],
    ], $userId)['status']);
    checkFalse('save() rejects a scope_id belonging to another company', $model->save(999, [
        'language' => 'th', 'template_name' => 'X', 'elements' => [], 'assignments' => [['scope_type' => 'department', 'scope_id' => $deptId]],
    ], $userId)['status']);

    $dupDept = $model->duplicate($compId, (int)$deptTpl['template_id'], $userId);
    checkTrue('duplicate() of a scoped template succeeds', $dupDept['status']);
    check('duplicate() does NOT carry assignments forward (starts unscoped)', count($model->get($compId, (int)$dupDept['template_id'])['assignments']), 0);

    echo "=== Assign To: duplicate-assignment rejection (explicit request: \"ถ้ามีการตั้งค่าซ้ำต้องแจ้ง Error ว่ามีการ Assign ซ้ำใคร\") ===\n";
    // deptTpl already claims $deptId (active) from earlier in this section -- a second ACTIVE
    // template trying to claim the SAME department must be rejected, naming the department.
    $conflictSave = $model->save($compId, [
        'language' => 'th', 'template_name' => 'Conflicting Dept Template', 'is_default' => false, 'status' => 'active', 'elements' => [],
        'assignments' => [['scope_type' => 'department', 'scope_id' => $deptId]],
    ], $userId);
    checkFalse('a second ACTIVE template claiming the same department is rejected', $conflictSave['status']);
    checkTrue('the error message names the conflicting department', strpos($conflictSave['message'], 'แผนกทดสอบ') !== false);
    checkTrue('the error message names the template that already claims it', strpos($conflictSave['message'], 'Dept Scoped') !== false);

    // Same conflicting scope, but saved as INACTIVE -- an inactive template can never actually
    // apply to anyone, so it must NOT be blocked by the same conflict check.
    $inactiveConflict = $model->save($compId, [
        'language' => 'th', 'template_name' => 'Inactive Dept Template', 'is_default' => false, 'status' => 'inactive', 'elements' => [],
        'assignments' => [['scope_type' => 'department', 'scope_id' => $deptId]],
    ], $userId);
    checkTrue('an INACTIVE template claiming the same department is allowed (it applies to no one)', $inactiveConflict['status']);

    // Same conflicting scope, but on the EN language -- conflict-checking is scoped PER LANGUAGE
    // (2026-08-25 follow-up, "รูปแบบการทำเหมือนกัน"), so the SAME department on an EN template must
    // NOT conflict with the TH-scoped deptTpl.
    $enSameDept = $model->save($compId, [
        'language' => 'en', 'template_name' => 'EN Dept Template', 'is_default' => false, 'status' => 'active', 'elements' => [],
        'assignments' => [['scope_type' => 'department', 'scope_id' => $deptId]],
    ], $userId);
    checkTrue('the SAME department assigned to an EN template does not conflict with the TH one', $enSameDept['status']);

    // Re-saving deptTpl ITSELF with the same assignment it already owns must NOT be treated as a
    // conflict with itself.
    $reSaveSelf = $model->save($compId, [
        'id' => $deptTpl['template_id'], 'language' => 'th', 'template_name' => 'Dept Scoped', 'is_default' => false, 'status' => 'active', 'elements' => [],
        'assignments' => [['scope_type' => 'department', 'scope_id' => $deptId]],
    ], $userId);
    checkTrue('re-saving a template with the assignment it already owns does not conflict with itself', $reSaveSelf['status']);

    // Once deptTpl is deactivated, its department becomes free again for another active template.
    $model->toggleStatus($compId, (int)$deptTpl['template_id'], $userId);
    $freedNowSave = $model->save($compId, [
        'language' => 'th', 'template_name' => 'Now Free Dept Template', 'is_default' => false, 'status' => 'active', 'elements' => [],
        'assignments' => [['scope_type' => 'department', 'scope_id' => $deptId]],
    ], $userId);
    checkTrue('the department becomes assignable again once the original template is deactivated', $freedNowSave['status']);

    echo "=== TH/EN pair: listPaired() / getPairByKey() / generateOtherLanguage() / duplicatePair() ===\n";
    $pdo->prepare("UPDATE `payslip_templates` SET status = 'deleted', deleted_at = CURRENT_TIMESTAMP WHERE comp_id = :comp_id AND deleted_at IS NULL")
        ->execute([':comp_id' => $compId]);
    $pairTh = $model->save($compId, [
        'language' => 'th', 'template_name' => 'ทดสอบคู่ภาษา', 'elements' => [
            array_merge($baseEl, ['element_type' => 'text', 'content' => 'สลิปเงินเดือน']),
        ],
    ], $userId);
    checkTrue('TH half of a pair saves', $pairTh['status']);
    $pairKey = $pairTh['pair_key'];
    checkTrue('save() returns a non-empty pair_key', !empty($pairKey));

    $paired = $model->listPaired($compId);
    check('listPaired() has exactly 1 pair (only the TH half exists)', count($paired), 1);
    check('the pair row has a th entry', $paired[0]['th'] !== null, true);
    check('the pair row has NO en entry yet', $paired[0]['en'], null);

    $gotPair = $model->getPairByKey($compId, $pairKey);
    checkTrue('getPairByKey() finds the pair', $gotPair !== null);
    check('getPairByKey() unknown key returns null', $model->getPairByKey($compId, 'not_a_real_key'), null);

    $genResult = $model->generateOtherLanguage($compId, (int)$pairTh['template_id'], $userId);
    checkTrue('generateOtherLanguage() (th -> en) succeeds', $genResult['status']);
    $genRow = $model->get($compId, (int)$genResult['template_id']);
    check('the generated row is language=en', $genRow['language'], 'en');
    check('the generated row shares the SAME pair_key', $genRow['pair_key'], $pairKey);
    check('element content is cloned VERBATIM (not translated)', $genRow['elements'][0]['content'], 'สลิปเงินเดือน');
    checkFalse('generateOtherLanguage() refuses once the target language already exists', $model->generateOtherLanguage($compId, (int)$pairTh['template_id'], $userId)['status']);

    $pairedAfterGen = $model->listPaired($compId);
    check('listPaired() now shows 1 pair with BOTH languages ready', count($pairedAfterGen), 1);
    check('th ready', $pairedAfterGen[0]['th'] !== null, true);
    check('en ready', $pairedAfterGen[0]['en'] !== null, true);

    $dupPair = $model->duplicatePair($compId, $pairKey, $userId);
    checkTrue('duplicatePair() succeeds', $dupPair['status']);
    check('duplicatePair() clones BOTH languages', count($dupPair['template_ids']), 2);
    checkTrue('duplicatePair() uses a NEW, different pair_key', $dupPair['pair_key'] !== $pairKey);
    checkFalse('duplicatePair() on an unknown pair_key fails cleanly', $model->duplicatePair($compId, 'not_a_real_key', $userId)['status']);

    echo "=== 2026-08-26: Layer visibility toggle (is_visible) -- \"เปิด/ปิดตาได้ แทนการที่ต้องลบอย่างเดียว\" ===\n";
    $visSave = $model->save($compId, [
        'language' => 'th', 'template_name' => 'Visibility Test',
        'elements' => [
            ['element_type' => 'text', 'content' => 'VISIBLE_ONE', 'pos_x_pct' => 1, 'pos_y_pct' => 1, 'width_pct' => 30, 'height_pct' => 5, 'is_visible' => true],
            ['element_type' => 'text', 'content' => 'HIDDEN_ONE', 'pos_x_pct' => 1, 'pos_y_pct' => 10, 'width_pct' => 30, 'height_pct' => 5, 'is_visible' => false],
            ['element_type' => 'text', 'content' => 'DEFAULT_ONE', 'pos_x_pct' => 1, 'pos_y_pct' => 20, 'width_pct' => 30, 'height_pct' => 5],
        ],
    ], $userId);
    checkTrue('save() with mixed is_visible succeeds', $visSave['status']);
    $visRow = $model->get($compId, $visSave['template_id']);
    $visByContent = [];
    foreach ($visRow['elements'] as $e) { $visByContent[$e['content']] = $e; }
    check('explicit is_visible=true round-trips as 1', (int)$visByContent['VISIBLE_ONE']['is_visible'], 1);
    check('explicit is_visible=false round-trips as 0', (int)$visByContent['HIDDEN_ONE']['is_visible'], 0);
    check('omitted is_visible defaults to 1 (visible) -- backward compat with pre-existing saved templates/tests', (int)$visByContent['DEFAULT_ONE']['is_visible'], 1);

    $visHtml = $renderer->buildHtml(
        ['page_size' => 'A4', 'orientation' => 'portrait', 'language' => 'th'],
        $visRow['elements'], $fakeCompany, $fakeRun, $fakeDetail, [], null, [], null, null
    );
    checkTrue('a VISIBLE element is present in the rendered output', strpos($visHtml, 'VISIBLE_ONE') !== false);
    checkFalse('a HIDDEN element is skipped from the rendered output entirely (real functional alternative to deleting it)', strpos($visHtml, 'HIDDEN_ONE') !== false);
    checkTrue('an element with is_visible omitted (defaults visible) still renders', strpos($visHtml, 'DEFAULT_ONE') !== false);

    $visDuplicate = $model->duplicate($compId, $visSave['template_id'], $userId);
    checkTrue('duplicate() succeeds', $visDuplicate['status']);
    $visDupRow = $model->get($compId, $visDuplicate['template_id']);
    $visDupByContent = [];
    foreach ($visDupRow['elements'] as $e) { $visDupByContent[$e['content']] = $e; }
    check('duplicate() carries is_visible=false through (not silently reset to visible)', (int)$visDupByContent['HIDDEN_ONE']['is_visible'], 0);

} finally {
    $pdo->rollBack();
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures > 0 ? "SOME TESTS FAILED\n" : "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
exit($failures > 0 ? 1 : 0);
