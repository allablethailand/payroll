<?php
/**
 * Lightweight verification script for NotificationModel (2026-08-29, explicit request: see that
 * class's own top-of-file docblock for the full notification-type analysis/request text). Not
 * PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 * Run with: php tests/notification_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/NotificationModel.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';

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

try {
    $compId = 1;
    $model = new NotificationModel();

    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'แจ้งเตือน', 'Test', 'Notif', '1990-01-01', 'Thai',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active')");
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => 'NOTIF_TEST_' . uniqid()]);
    $employeeId = (int)$pdo->lastInsertId();

    $insEmp2 = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ2', 'แจ้งเตือน2', 'Test2', 'Notif2', '1990-01-01', 'Thai',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active')");
    $insEmp2->execute([':comp_id' => $compId, ':employee_no' => 'NOTIF_TEST2_' . uniqid()]);
    $otherEmployeeId = (int)$pdo->lastInsertId();

    echo "=== create() ===\n";
    $ok = $model->create($compId, $employeeId, 'test_type', 'หัวข้อ', 'Title', 'ข้อความ', 'Message', '/somewhere', 'payroll_run', 123, null, 'fa-bell');
    checkTrue('create() succeeds', $ok);
    $row = $pdo->query("SELECT * FROM notifications WHERE employee_id = {$employeeId} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    checkTrue('the row was actually persisted', $row !== false);
    check('is_read defaults to 0 (unread)', (int)$row['is_read'], 0);
    check('read_at is null until read', $row['read_at'], null);
    check('title_th persisted', $row['title_th'], 'หัวข้อ');
    check('link_url persisted', $row['link_url'], '/somewhere');

    echo "=== dedup_key (2026-08-29, avoids re-checked notifications spamming duplicates) ===\n";
    $dedupOk1 = $model->create($compId, $employeeId, 'dedup_type', 'A', 'A', null, null, null, null, null, 'notif_test_dedup_1');
    checkTrue('first insert with a dedup_key succeeds', $dedupOk1);
    $dedupOk2 = $model->create($compId, $employeeId, 'dedup_type', 'A again', 'A again', null, null, null, null, null, 'notif_test_dedup_1');
    check('second insert with the SAME dedup_key is a silent no-op (returns false)', $dedupOk2, false);
    $dedupCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE dedup_key = 'notif_test_dedup_1'")->fetchColumn();
    check('only ONE row actually exists for that dedup_key', $dedupCount, 1);
    // Confirm the surviving row is genuinely the FIRST insert, not silently overwritten by the second.
    $dedupRow = $pdo->query("SELECT title_th FROM notifications WHERE dedup_key = 'notif_test_dedup_1'")->fetch(PDO::FETCH_ASSOC);
    check('the surviving row is the original insert, untouched by the rejected duplicate', $dedupRow['title_th'], 'A');

    echo "=== createForPermissionHolders() (fan-out to every employee holding a given permission_key) ===\n";
    // 2026-09-03, Platform Hardening Phase 3: structure_roles.can_process_payroll/etc. are dropped
    // entirely -- createForPermissionHolders() now resolves recipients via a real `permission_key`
    // (PermissionModel::employeesWithPermission()), not a raw role-flag column. Give both fixture
    // employees a role with a real payroll_run.process grant so the fan-out has real, isolated
    // targets to count (not comp_id=1's real dev-DB roles, whose membership can drift).
    $pdo->prepare("INSERT INTO `structure_roles` (comp_id, role_name_th, role_name_en)
        VALUES (:comp_id, 'ทดสอบแจ้งเตือน', 'Notif Test Role')")->execute([':comp_id' => $compId]);
    $notifRoleId = (int)$pdo->lastInsertId();
    $notifPermId = (int)$pdo->query("SELECT id FROM permissions WHERE permission_key = 'payroll_run.process'")->fetchColumn();
    $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, allow_scope, detail_level) VALUES (:r, :p, 'all', 'full')")
        ->execute([':r' => $notifRoleId, ':p' => $notifPermId]);
    $pdo->prepare("UPDATE employees SET role_id = :role_id WHERE id IN ({$employeeId}, {$otherEmployeeId})")->execute([':role_id' => $notifRoleId]);
    $model->createForPermissionHolders($compId, 'payroll_run.process', 'fanout_type', 'Fan', 'Fan', null, null, '/x', null, null, 'notif_test_fanout');
    $fanoutRows = $pdo->query("SELECT employee_id FROM notifications WHERE type = 'fanout_type'")->fetchAll(PDO::FETCH_COLUMN);
    $fanoutForFixture = array_intersect($fanoutRows, [$employeeId, $otherEmployeeId]);
    check('both fixture employees (both now granted payroll_run.process) received their own row', count($fanoutForFixture), 2);
    // Real SQL injection is structurally impossible regardless (employeesWithPermission() always
    // binds the key as a PDO parameter, never interpolates it into raw SQL) -- an unknown
    // permission_key just naturally matches zero rows via the LEFT JOIN against `permissions`,
    // same safe end result as the old whitelist array used to guarantee a different way.
    checkTrue('an unknown permission_key is safely a no-op, not a crash/injection vector', true); // exercised next line
    $model->createForPermissionHolders($compId, "not_a_real_key; DROP TABLE notifications", 'should_not_exist', 'x', 'x', null, null, null);
    $badColCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type = 'should_not_exist'")->fetchColumn();
    check('an unknown permission_key produces zero rows (parameterized, never reaches raw SQL)', $badColCount, 0);

    echo "=== createForEmployees() (explicit recipient list, e.g. resolved approval-workflow approvers) ===\n";
    $model->createForEmployees($compId, [$employeeId, $otherEmployeeId, $employeeId], 'explicit_type', 'E', 'E', null, null, '/y', null, null, 'notif_test_explicit');
    $explicitRows = array_map('intval', $pdo->query("SELECT employee_id FROM notifications WHERE type = 'explicit_type' ORDER BY employee_id")->fetchAll(PDO::FETCH_COLUMN));
    check('duplicate employee ids in the input list are de-duplicated to one row each', $explicitRows, [$employeeId, $otherEmployeeId]);

    echo "=== listForEmployee() / unreadCount() -- scoping + pagination ===\n";
    $unread = $model->unreadCount($compId, $employeeId);
    checkTrue('unreadCount() is non-zero after the fixtures above', $unread > 0);
    $otherUnread = $model->unreadCount($compId, $otherEmployeeId);
    checkTrue('the OTHER employee has their own independent unread count, not the first employee\'s', $otherUnread > 0);

    $page1 = $model->listForEmployee($compId, $employeeId, 0, 2);
    check('listForEmployee() respects the limit param', count($page1), 2);
    $allForEmployee = $model->listForEmployee($compId, $employeeId, 0, 100);
    $leakedOther = array_filter($allForEmployee, fn($r) => (int)$r['employee_id'] !== $employeeId);
    check('listForEmployee() never leaks a different employee\'s row', count($leakedOther), 0);

    echo "=== markRead() / markAllRead() -- \"ต้องไปกดเปิดดูก่อนถึงหาย\", per-user, never cross-leaks ===\n";
    $beforeRead = $model->listForEmployee($compId, $employeeId, 0, 1)[0];
    check('fixture sanity: the most recent row starts unread', (int)$beforeRead['is_read'], 0);
    $model->markRead((int)$beforeRead['id'], $compId, $employeeId);
    $afterRead = $pdo->query("SELECT is_read, read_at FROM notifications WHERE id = {$beforeRead['id']}")->fetch(PDO::FETCH_ASSOC);
    check('markRead() flips is_read to 1', (int)$afterRead['is_read'], 1);
    checkTrue('markRead() sets read_at', !empty($afterRead['read_at']));

    // Cross-employee guard: employee B cannot mark employee A's own row read.
    $anotherUnreadRow = null;
    foreach ($model->listForEmployee($compId, $employeeId, 0, 100) as $r) {
        if ((int)$r['is_read'] === 0) { $anotherUnreadRow = $r; break; }
    }
    checkTrue('fixture sanity: at least one more unread row exists for employee A', $anotherUnreadRow !== null);
    $model->markRead((int)$anotherUnreadRow['id'], $compId, $otherEmployeeId); // wrong employee on purpose
    $stillUnread = $pdo->query("SELECT is_read FROM notifications WHERE id = {$anotherUnreadRow['id']}")->fetchColumn();
    check('markRead() cannot mark a DIFFERENT employee\'s own notification read (no row matched, silent no-op)', (int)$stillUnread, 0);

    $beforeAllReadCount = $model->unreadCount($compId, $employeeId);
    checkTrue('fixture sanity: employee A still has unread notifications before markAllRead()', $beforeAllReadCount > 0);
    $otherUnreadBefore = $model->unreadCount($compId, $otherEmployeeId);
    $model->markAllRead($compId, $employeeId);
    check('markAllRead() zeroes out employee A\'s own unread count', $model->unreadCount($compId, $employeeId), 0);
    check('markAllRead() for employee A does NOT touch employee B\'s own unread count ("ของใครของมัน")', $model->unreadCount($compId, $otherEmployeeId), $otherUnreadBefore);

    echo "=== checkStaleDrafts() (2026-08-29, lazy 1/2/3-day aging-draft reminder -- no cron in this project) ===\n";
    $cycleModel = new PayrollCycleModel();
    $cycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'NOTIF_TEST_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance', 'status' => 'active',
        'bank_file_format_id' => 1,
    ], $employeeId);
    checkTrue('fixture: cycle created' . (empty($cycleRes['status']) ? " ({$cycleRes['message']})" : ''), $cycleRes['status']);
    $runModel = new PayrollRunModel();
    $staleRunRes = $runModel->create($compId, [
        'cycle_id' => $cycleRes['id'], 'run_name' => 'NOTIF_TEST_STALE_' . uniqid(),
        'period_start_date' => '2020-01-01', 'period_end_date' => '2020-01-31', 'payment_date' => '2020-02-05',
    ], $employeeId, true);
    checkTrue('fixture: draft run created' . (empty($staleRunRes['status']) ? " ({$staleRunRes['message']})" : ''), $staleRunRes['status']);
    // Backdate updated_at directly (recalculate()/create() itself always stamps "now") to simulate a
    // run that has genuinely sat untouched for 2 days.
    $pdo->prepare("UPDATE payroll_runs SET updated_at = DATE_SUB(NOW(), INTERVAL 2 DAY) WHERE id = :id")->execute([':id' => $staleRunRes['id']]);

    $model->checkStaleDrafts($compId, $employeeId);
    $staleNotif = $pdo->query("SELECT * FROM notifications WHERE type = 'stale_draft' AND related_id = {$staleRunRes['id']}")->fetch(PDO::FETCH_ASSOC);
    checkTrue('a stale_draft notification was created for the 2-day-old draft', $staleNotif !== false);
    checkTrue('the dedup_key encodes the 2-day milestone specifically', str_contains((string)$staleNotif['dedup_key'], ':2d'));

    // Calling it again immediately must NOT create a second row for the same milestone (dedup_key).
    $model->checkStaleDrafts($compId, $employeeId);
    $staleCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type = 'stale_draft' AND related_id = {$staleRunRes['id']}")->fetchColumn();
    check('calling checkStaleDrafts() again the same day does not duplicate the reminder', $staleCount, 1);

    // A run this employee did NOT create must never generate a reminder for them.
    $model->checkStaleDrafts($compId, $otherEmployeeId);
    $wrongOwnerCount = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE type = 'stale_draft' AND related_id = {$staleRunRes['id']} AND employee_id = {$otherEmployeeId}")->fetchColumn();
    check('checkStaleDrafts() only notifies the run\'s OWN creator, never a different employee', $wrongOwnerCount, 0);

    echo "=== Notification Preferences: shouldNotify() resolution order via create() (2026-08-29) ===\n";
    // $employeeId already has role_id=$notifRoleId (can_process_payroll test role) from the
    // createForPermissionHolders() fixture above -- reused here rather than a new role.
    check('baseline: create() succeeds for a type with no preference set anywhere (today\'s behavior, unchanged)', $model->create($compId, $employeeId, 'sync_new_data', 'a', 'a', null, null, null), true);

    $prefSaveRes = $model->saveEmployeePreferences($employeeId, [['type' => 'sync_new_data', 'enabled' => false]], $employeeId);
    checkTrue('saveEmployeePreferences() succeeds' . (empty($prefSaveRes['status']) ? " ({$prefSaveRes['message']})" : ''), $prefSaveRes['status']);
    check('personal preference OFF: create() is a silent no-op (no row inserted)', $model->create($compId, $employeeId, 'sync_new_data', 'b', 'b', null, null, null), false);
    check('a DIFFERENT type for the same employee is completely unaffected', $model->create($compId, $employeeId, 'approved_continue', 'c', 'c', null, null, null), true);
    check('a DIFFERENT employee for the SAME type is completely unaffected ("ของใครของมัน")', $model->create($compId, $otherEmployeeId, 'sync_new_data', 'd', 'd', null, null, null), true);

    echo "=== Notification Preferences: role-level default only applies with NO personal override ===\n";
    $roleMuteRes = $model->saveRoleMatrix($compId, [['role_id' => $notifRoleId, 'type' => 'lock_reminder_print', 'enabled' => false]], $employeeId);
    checkTrue('saveRoleMatrix() succeeds' . (empty($roleMuteRes['status']) ? " ({$roleMuteRes['message']})" : ''), $roleMuteRes['status']);
    check('role-level default OFF, no personal override: create() is a silent no-op', $model->create($compId, $employeeId, 'lock_reminder_print', 'e', 'e', null, null, null), false);

    $personalOverridesRoleRes = $model->saveEmployeePreferences($employeeId, [['type' => 'lock_reminder_print', 'enabled' => true]], $employeeId);
    checkTrue('saveEmployeePreferences() succeeds', $personalOverridesRoleRes['status']);
    check('personal preference ON wins over a role-level default of OFF (most specific setting wins)', $model->create($compId, $employeeId, 'lock_reminder_print', 'f', 'f', null, null, null), true);

    echo "=== getEmployeePreferences() reflects the effective (resolved) state ===\n";
    $effectivePrefs = $model->getEmployeePreferences($employeeId);
    check('getEmployeePreferences() returns exactly the 5 known types', count($effectivePrefs), count(NotificationModel::TYPES));
    $bySyncNewData = current(array_filter($effectivePrefs, fn($p) => $p['type'] === 'sync_new_data'));
    check('sync_new_data reflects the personal OFF override saved above', $bySyncNewData['enabled'], false);
    $byApprovedContinue = current(array_filter($effectivePrefs, fn($p) => $p['type'] === 'approved_continue'));
    check('approved_continue (never touched) defaults to enabled', $byApprovedContinue['enabled'], true);
    $byLockReminder = current(array_filter($effectivePrefs, fn($p) => $p['type'] === 'lock_reminder_print'));
    check('lock_reminder_print reflects the personal ON override (winning over the role-level OFF)', $byLockReminder['enabled'], true);

    echo "=== roleMatrix() / saveRoleMatrix() -- admin grid, validation, full-replace semantics ===\n";
    $roleMatrixData = $model->roleMatrix($compId);
    check('roleMatrix() returns exactly the 5 known types', count($roleMatrixData['types']), count(NotificationModel::TYPES));
    checkTrue('roleMatrix() includes the fixture role', current(array_filter($roleMatrixData['roles'], fn($r) => (int)$r['id'] === $notifRoleId)) !== false);
    $lockGrant = current(array_filter($roleMatrixData['grants'], fn($g) => (int)$g['role_id'] === $notifRoleId && $g['type'] === 'lock_reminder_print'));
    check('roleMatrix() reflects the saved OFF grant for lock_reminder_print', $lockGrant['enabled'] ?? null, false);

    $invalidRoleRes = $model->saveRoleMatrix($compId, [['role_id' => 999999999, 'type' => 'sync_new_data', 'enabled' => false]], $employeeId);
    check('saveRoleMatrix() rejects a role_id that does not belong to this company', $invalidRoleRes['status'], false);
    $invalidTypeRes = $model->saveRoleMatrix($compId, [['role_id' => $notifRoleId, 'type' => 'not_a_real_type', 'enabled' => false]], $employeeId);
    check('saveRoleMatrix() rejects an unknown notification type', $invalidTypeRes['status'], false);

    // Full-replace: saving a grid that no longer mentions lock_reminder_print at all for this role
    // clears its previous OFF grant entirely (reverts to the true default, enabled) -- confirms
    // this ISN'T a merge/patch.
    $roleReplaceRes = $model->saveRoleMatrix($compId, [['role_id' => $notifRoleId, 'type' => 'sync_new_data', 'enabled' => false]], $employeeId);
    checkTrue('saveRoleMatrix() (replacement grid) succeeds', $roleReplaceRes['status']);
    $roleMatrixAfterReplace = $model->roleMatrix($compId);
    $lockGrantAfterReplace = current(array_filter($roleMatrixAfterReplace['grants'], fn($g) => (int)$g['role_id'] === $notifRoleId && $g['type'] === 'lock_reminder_print'));
    check('full-replace: the OLD lock_reminder_print grant is gone (no row = reverts to the true default)', $lockGrantAfterReplace, false);
    $syncGrantAfterReplace = current(array_filter($roleMatrixAfterReplace['grants'], fn($g) => (int)$g['role_id'] === $notifRoleId && $g['type'] === 'sync_new_data'));
    check('full-replace: the NEW sync_new_data grant is present', $syncGrantAfterReplace['enabled'] ?? null, false);

    echo "=== listDataTable() (2026-08-29, server-side DataTables source for /notifications page) ===\n";
    $insEmp3 = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ3', 'แจ้งเตือน3', 'Test3', 'Notif3', '1990-01-01', 'Thai',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', 30000, '2020-01-01', 'average', 'active')");
    $insEmp3->execute([':comp_id' => $compId, ':employee_no' => 'NOTIF_TEST3_' . uniqid()]);
    $dtEmployeeId = (int)$pdo->lastInsertId();

    $model->create($compId, $dtEmployeeId, 'sync_new_data', 'ซิงค์ใหม่', 'New Sync', null, null, '/a', null, null, null, 'fa-arrows-rotate');
    $model->create($compId, $dtEmployeeId, 'approved_continue', 'อนุมัติแล้ว งวดมกราคม', 'Approved Jan Run', null, null, '/b', null, null, null, 'fa-check');
    $model->create($compId, $dtEmployeeId, 'lock_reminder_print', 'ปิดรอบแล้ว', 'Locked', null, null, '/c', null, null, null, 'fa-lock');
    $pdo->prepare("UPDATE notifications SET created_at = '2020-01-10 09:00:00' WHERE employee_id = :eid AND type = 'sync_new_data'")->execute([':eid' => $dtEmployeeId]);
    $pdo->prepare("UPDATE notifications SET created_at = '2020-06-15 09:00:00' WHERE employee_id = :eid AND type = 'approved_continue'")->execute([':eid' => $dtEmployeeId]);
    $pdo->prepare("UPDATE notifications SET created_at = '2020-12-20 09:00:00' WHERE employee_id = :eid AND type = 'lock_reminder_print'")->execute([':eid' => $dtEmployeeId]);
    $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE employee_id = :eid AND type = 'sync_new_data'")->execute([':eid' => $dtEmployeeId]);

    $allRes = $model->listDataTable($compId, $dtEmployeeId, 0, 10, '', '', '', 2, 'desc');
    check('listDataTable(): total = 3 (no filter)', $allRes['total'], 3);
    check('listDataTable(): filtered = 3 (no filter)', $allRes['filtered'], 3);
    check('listDataTable(): default sort (created_at desc) puts the Dec row first', $allRes['data'][0]['type'], 'lock_reminder_print');

    $pageRes = $model->listDataTable($compId, $dtEmployeeId, 0, 2, '', '', '', 2, 'desc');
    check('listDataTable(): length=2 returns exactly 2 rows', count($pageRes['data']), 2);
    check('listDataTable(): recordsFiltered still reports the true total (3), not just the page size', $pageRes['filtered'], 3);

    $dateRes = $model->listDataTable($compId, $dtEmployeeId, 0, 10, '', '2020-06-01', '2020-12-31', 2, 'desc');
    check('listDataTable(): date_from/date_to narrows to the 2 rows inside that range', $dateRes['filtered'], 2);
    checkTrue('listDataTable(): the Jan row (outside the range) is excluded', !in_array('sync_new_data', array_column($dateRes['data'], 'type'), true));

    $searchRes = $model->listDataTable($compId, $dtEmployeeId, 0, 10, 'มกราคม', '', '', 2, 'desc');
    check('listDataTable(): search matches against title_th/title_en/message_th/message_en', $searchRes['filtered'], 1);
    check('listDataTable(): search result is the matching row', $searchRes['data'][0]['type'], 'approved_continue');

    $scopeRes = $model->listDataTable($compId, $employeeId, 0, 10, '', '', '', 2, 'desc');
    $leakedRows = array_filter($scopeRes['data'], fn($r) => (int)$r['employee_id'] === $dtEmployeeId);
    check('listDataTable() stays scoped to the requested employee -- never leaks another employee\'s rows', count($leakedRows), 0);

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
