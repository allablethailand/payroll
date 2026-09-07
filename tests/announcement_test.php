<?php
/**
 * Lightweight verification script for Backlog Phase 10, T057: AnnouncementModel (full Announcement
 * CMS -- Draft/Publish, publish-time recipient snapshot via EntityAssignmentModel, single mandatory
 * dashboard-featured row, acknowledge/pending queue). See AnnouncementModel.php's own docblock and
 * database/migrations/2026-09-04_7_announcements.sql's own header comment for the full design.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back -- uses its own fresh company (never touches comp_id=1's
 * live data, see feedback_dev_db_shared_state_test_fragility).
 * Run with: php tests/announcement_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/AnnouncementModel.php';
require_once __DIR__ . '/../app/models/EntityAssignmentModel.php';
require_once __DIR__ . '/../app/models/NotificationModel.php';

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

function makeCompany(PDO $pdo, string $countryCode = 'TH'): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}
function makeDepartment(PDO $pdo, int $compId, string $nameTh): int {
    $stmt = $pdo->prepare("INSERT INTO structure_departments (comp_id, department_code, department_name_th, department_name_en, status) VALUES (:c, :code, :name, :name, 'active')");
    $stmt->execute([':c' => $compId, ':code' => 'DEPT_' . uniqid(), ':name' => $nameTh]);
    return (int)$pdo->lastInsertId();
}
function makeEmployee(PDO $pdo, int $compId, ?int $deptId = null, string $employmentStatus = 'permanent'): int {
    $stmt = $pdo->prepare("INSERT INTO employees (comp_id, employee_no, name_th, surname_th, department_id, employment_status)
        VALUES (:c, :no, 'ทดสอบ', 'พนักงาน', :dept, :status)");
    $stmt->execute([':c' => $compId, ':no' => 'EMP_' . uniqid(), ':dept' => $deptId, ':status' => $employmentStatus]);
    return (int)$pdo->lastInsertId();
}

try {
    $compA = makeCompany($pdo);
    $userId = 1;
    $model = new AnnouncementModel($pdo);
    $assignmentModel = new EntityAssignmentModel($pdo);
    $notificationModel = new NotificationModel();

    $deptA = makeDepartment($pdo, $compA, 'แผนก A');
    $deptB = makeDepartment($pdo, $compA, 'แผนก B');
    $empInDeptA = makeEmployee($pdo, $compA, $deptA, 'permanent');
    $empInDeptB = makeEmployee($pdo, $compA, $deptB, 'permanent');
    $empResigned = makeEmployee($pdo, $compA, $deptA, 'resigned');

    echo "=== save(): draft create + edit ===\n";
    $createRes = $model->save($compA, [
        'title_th' => 'ประกาศทดสอบ', 'title_en' => 'Test Announcement',
        'body_th' => 'เนื้อหาทดสอบ', 'body_en' => 'Test body',
        'accept_required' => true,
    ], $userId);
    checkTrue('draft create succeeds', $createRes['status']);
    $annId = $createRes['id'];

    $editRes = $model->save($compA, [
        'id' => $annId, 'title_th' => 'ประกาศทดสอบ (แก้ไข)', 'title_en' => 'Test Announcement (edited)',
        'body_th' => 'เนื้อหาทดสอบ', 'body_en' => 'Test body', 'accept_required' => true,
        'assignments' => [['scope_type' => 'department', 'scope_id' => $deptA]],
    ], $userId);
    checkTrue('draft edit (incl. recipient scoping) succeeds', $editRes['status']);
    $row = $model->get($compA, $annId);
    check('edited title reflects the change', $row['title_en'], 'Test Announcement (edited)');
    check('assignment saved (1 row)', count($row['assignments']), 1);

    echo "\n=== publish(): snapshot recipients + fan out notifications ===\n";
    $publishRes = $model->publish($compA, $annId, $userId);
    checkTrue('publish() succeeds', $publishRes['status']);
    check('recipient_count = 1 (only empInDeptA matches -- deptB employee and resigned employee excluded)', $publishRes['recipient_count'], 1);

    $rowAfterPublish = $model->get($compA, $annId);
    check('status is now published', $rowAfterPublish['status'], 'published');

    $stmtRecip = $pdo->prepare("SELECT employee_id FROM announcement_recipients WHERE announcement_id = :id");
    $stmtRecip->execute([':id' => $annId]);
    $recipients = array_map('intval', array_column($stmtRecip->fetchAll(PDO::FETCH_ASSOC), 'employee_id'));
    checkTrue('empInDeptA (matches scope) IS a recipient', in_array($empInDeptA, $recipients, true));
    checkFalse('empInDeptB (does NOT match scope) is NOT a recipient', in_array($empInDeptB, $recipients, true));
    checkFalse('empResigned (inactive, "Payroll-access users" filter) is NOT a recipient even though in deptA', in_array($empResigned, $recipients, true));

    $stmtNotif = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE related_type = 'announcement' AND related_id = :id AND employee_id = :emp");
    $stmtNotif->execute([':id' => $annId, ':emp' => $empInDeptA]);
    check('a real Notification row exists for the recipient after publish', (int)$stmtNotif->fetchColumn(), 1);

    echo "\n=== immutability after publish ===\n";
    $editAfterPublish = $model->save($compA, ['id' => $annId, 'title_th' => 'x', 'title_en' => 'x', 'body_th' => 'x', 'body_en' => 'x'], $userId);
    checkFalse('editing content after publish is refused', $editAfterPublish['status']);

    echo "\n=== publish-time snapshot is immutable -- a NEW employee joining deptA afterward is NOT retroactively added ===\n";
    $empJoinedLater = makeEmployee($pdo, $compA, $deptA, 'permanent');
    $stmtRecip2 = $pdo->prepare("SELECT employee_id FROM announcement_recipients WHERE announcement_id = :id");
    $stmtRecip2->execute([':id' => $annId]);
    $recipientsAfter = array_map('intval', array_column($stmtRecip2->fetchAll(PDO::FETCH_ASSOC), 'employee_id'));
    checkFalse('employee added to deptA AFTER publish is NOT in the frozen recipient list', in_array($empJoinedLater, $recipientsAfter, true));
    check('recipient count is still exactly 1', count($recipientsAfter), 1);

    echo "\n=== acknowledge(): real-recipient-only guard ===\n";
    $ackNonRecipient = $model->acknowledge($compA, $annId, $empInDeptB, 'modal');
    checkFalse('acknowledge() by a NON-recipient is refused', $ackNonRecipient['status']);
    $ackReal = $model->acknowledge($compA, $annId, $empInDeptA, 'modal');
    checkTrue('acknowledge() by the real recipient succeeds', $ackReal['status']);
    $ackAgain = $model->acknowledge($compA, $annId, $empInDeptA, 'notification');
    checkTrue('re-acknowledging (upsert) does not error', $ackAgain['status']);

    echo "\n=== pendingForEmployee(): correct filtering ===\n";
    $ann2Res = $model->save($compA, ['title_th' => 'ยังไม่ยอมรับ', 'title_en' => 'Not yet ack', 'body_th' => 'x', 'body_en' => 'x'], $userId);
    $ann2Id = $ann2Res['id'];
    $model->publish($compA, $ann2Id, $userId); // unscoped -> everyone active

    $pendingForA = $model->pendingForEmployee($compA, $empInDeptA);
    $pendingIdsA = array_map('intval', array_column($pendingForA, 'id'));
    checkFalse('the ACKNOWLEDGED announcement is NOT in the pending queue', in_array($annId, $pendingIdsA, true));
    checkTrue('the UN-acknowledged (unscoped) announcement IS in the pending queue', in_array($ann2Id, $pendingIdsA, true));

    $pendingForB = $model->pendingForEmployee($compA, $empInDeptB);
    $pendingIdsB = array_map('intval', array_column($pendingForB, 'id'));
    checkTrue('empInDeptB (never a recipient of ann1, IS a recipient of unscoped ann2) sees ann2 pending', in_array($ann2Id, $pendingIdsB, true));

    $pendingForResigned = $model->pendingForEmployee($compA, $empResigned);
    check('resigned employee has ZERO pending (never became a recipient of anything, even the unscoped one)', count($pendingForResigned), 0);

    echo "\n=== setDashboardFeatured(): exactly one per company, published-only ===\n";
    $draftRes = $model->save($compA, ['title_th' => 'ร่างที่ยังไม่เผยแพร่', 'title_en' => 'Unpublished draft', 'body_th' => 'x', 'body_en' => 'x'], $userId);
    $featureDraft = $model->setDashboardFeatured($compA, $draftRes['id'], $userId);
    checkFalse('featuring a DRAFT (not yet published) is refused', $featureDraft['status']);

    $feature1 = $model->setDashboardFeatured($compA, $annId, $userId);
    checkTrue('featuring the published announcement succeeds', $feature1['status']);
    $featured = $model->getDashboardFeatured($compA);
    check('getDashboardFeatured() returns the right one', (int)$featured['id'], $annId);

    $feature2 = $model->setDashboardFeatured($compA, $ann2Id, $userId);
    checkTrue('featuring a SECOND announcement succeeds', $feature2['status']);
    $featuredAfter = $model->getDashboardFeatured($compA);
    check('getDashboardFeatured() now returns the NEW one (old one un-featured)', (int)$featuredAfter['id'], $ann2Id);

    $stmtFeaturedCount = $pdo->prepare("SELECT COUNT(*) FROM announcements WHERE comp_id = :c AND is_dashboard_featured = 1");
    $stmtFeaturedCount->execute([':c' => $compA]);
    check('exactly ONE featured row exists for this company at any time', (int)$stmtFeaturedCount->fetchColumn(), 1);

    echo "\n=== delete() (soft) ===\n";
    $delRes = $model->delete($compA, $draftRes['id'], $userId);
    checkTrue('delete() succeeds', $delRes['status']);
    check('deleted announcement is no longer get()-able', $model->get($compA, $draftRes['id']), null);

    echo "\n=== 2026-09-07 CMS follow-up: rich-content sanitization ===\n";
    $xssRes = $model->save($compA, [
        'title_th' => 'ทดสอบ XSS', 'title_en' => 'XSS test',
        'body_th' => '<p>สวัสดี <strong onclick="alert(1)">โลก</strong></p><script>alert(1)</script><img src="x" onerror="alert(1)">',
        'body_en' => '<p>Hello <a href="javascript:alert(1)">click</a> <a href="https://example.com" target="_blank">safe link</a></p><iframe src="evil"></iframe>',
    ], $userId);
    checkTrue('save() with unsafe HTML still succeeds (sanitized, not rejected)', $xssRes['status']);
    $xssRow = $model->get($compA, $xssRes['id']);
    checkFalse('body_th: <script> tag stripped', str_contains($xssRow['body_th'], '<script'));
    checkFalse('body_th: onclick attribute stripped', str_contains($xssRow['body_th'], 'onclick'));
    checkFalse('body_th: onerror attribute stripped', str_contains($xssRow['body_th'], 'onerror'));
    checkTrue('body_th: safe <strong> text content survives', str_contains($xssRow['body_th'], 'โลก'));
    checkFalse('body_en: javascript: href stripped', str_contains($xssRow['body_en'], 'javascript:'));
    checkFalse('body_en: <iframe> tag stripped', str_contains($xssRow['body_en'], '<iframe'));
    checkTrue('body_en: safe https:// href survives', str_contains($xssRow['body_en'], 'https://example.com'));
    checkFalse('body_th: <script> CONTENT (not just the tag) does not leak as visible text', str_contains($xssRow['body_th'], 'alert(1)'));

    $imgRes = $model->save($compA, [
        'title_th' => 'ทดสอบรูปภาพ', 'title_en' => 'Image test',
        'body_th' => '<p><img src="data:image/svg+xml;base64,AAAA"> <img src="https://example.com/x.png"></p>', 'body_en' => 'x',
    ], $userId);
    $imgRow = $model->get($compA, $imgRes['id']);
    checkFalse('body_th: data: image src is stripped (forces real upload instead)', str_contains($imgRow['body_th'], 'data:image'));
    checkTrue('body_th: https:// image src survives', str_contains($imgRow['body_th'], 'https://example.com/x.png'));

    $htmlOnlyRes = $model->save($compA, [
        'title_th' => 'ว่างเปล่า', 'title_en' => 'Empty body',
        'body_th' => '<p><br></p>', 'body_en' => '<p></p>',
    ], $userId);
    checkFalse('save() refuses a body that is HTML markup with no real text content', $htmlOnlyRes['status']);

    echo "\n=== 2026-09-07 CMS follow-up: cover_image_path ===\n";
    checkTrue('isValidCoverPath(): null is valid (no cover)', AnnouncementModel::isValidCoverPath(null, $compA));
    checkTrue('isValidCoverPath(): a correctly-shaped path for this company is valid',
        AnnouncementModel::isValidCoverPath('public/uploads/announcement_covers/' . $compA . '/' . str_repeat('a', 32) . '.jpg', $compA));
    checkFalse('isValidCoverPath(): a path for a DIFFERENT company is rejected',
        AnnouncementModel::isValidCoverPath('public/uploads/announcement_covers/' . ($compA + 1) . '/' . str_repeat('a', 32) . '.jpg', $compA));
    checkFalse('isValidCoverPath(): a path-traversal attempt is rejected',
        AnnouncementModel::isValidCoverPath('public/uploads/announcement_covers/' . $compA . '/../../../etc/passwd', $compA));

    $coverPath = 'public/uploads/announcement_covers/' . $compA . '/' . str_repeat('b', 32) . '.png';
    $coverRes = $model->save($compA, [
        'title_th' => 'มีปก', 'title_en' => 'Has a cover', 'body_th' => 'x', 'body_en' => 'x',
        'cover_image_path' => $coverPath,
    ], $userId);
    checkTrue('save() with a valid cover_image_path succeeds', $coverRes['status']);
    $coverRow = $model->get($compA, $coverRes['id']);
    check('cover_image_path persisted correctly', $coverRow['cover_image_path'], $coverPath);

    $badCoverRes = $model->save($compA, [
        'title_th' => 'ปกไม่ถูกต้อง', 'title_en' => 'Bad cover', 'body_th' => 'x', 'body_en' => 'x',
        'cover_image_path' => 'public/uploads/announcement_covers/' . ($compA + 1) . '/' . str_repeat('c', 32) . '.jpg',
    ], $userId);
    checkFalse('save() with a cross-company cover_image_path is refused', $badCoverRes['status']);

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
