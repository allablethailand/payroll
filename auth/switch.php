<?php
declare(strict_types=1);

/**
 * "Switch App" endpoint (header Hub icon) -- explicit bug report: "หลังจากที่ Switch App กลับไปใช้งาน
 * Origami Refresh หน้า Payroll แล้ว Session ไม่ตัด" (after using Switch App to go back to Origami,
 * refreshing the Payroll page still shows a logged-in session).
 *
 * Root cause: layout/header.php's hub menu used to link its `<a href>` DIRECTLY to Origami's own
 * `/api/oauth/v2/switch?token=...&app=...` -- a plain cross-origin navigation. Nothing on THIS side
 * ever told Payroll's own PHP session to end, so it just sat there valid; going back to the Payroll
 * tab and refreshing found the same session and let the user straight back in, no re-login needed.
 * This app has NO logout mechanism at all otherwise (confirmed: no logout route/link anywhere in the
 * codebase before this file), so switching away was the only "leaving" action that existed, and
 * users reasonably expect leaving via the hub to behave like a real app switch, not "still logged in
 * behind you."
 *
 * Fix: the hub link now points HERE first (same origin, `?app=...` only -- no token in the URL/page
 * source this app renders, see below), so this script can destroy Payroll's own session BEFORE
 * redirecting on to Origami's real switch URL. One-way, same as the SSO login handshake itself
 * (auth/index.php never round-trips back here either) -- there is no "come back to Payroll already
 * logged in" step in either direction.
 *
 * `origami_switch_token` is read from THIS session server-side (set by auth/index.php at login) --
 * never placed in this app's own rendered HTML/query string, unlike the old direct-link version,
 * which is a small incidental hardening (one less place the live SSO token appeared in page source).
 */

ini_set('session.cookie_httponly', '1');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', '1');
}
ini_set('session.cookie_samesite', 'Lax');
// 2026-08-29 session-timeout extension -- see index.php's own comment on this same ini_set pair
// for the full root-cause explanation ("Session หลุดบ่อยกลับไปที่ Origami"). Kept consistent here
// too even though this script only ever destroys the session, so all 3 standalone session_start()
// call sites agree on the same settings.
ini_set('session.gc_maxlifetime', '28800');
ini_set('session.cookie_lifetime', '28800');
session_start();

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';

$appKey = trim((string)($_GET['app'] ?? ''));
$switchToken = (string)($_SESSION['origami_switch_token'] ?? '');

$origamiBase = rtrim(ORIGAMI_BASE_URL, '/');
$destination = $origamiBase !== '' ? $origamiBase : '/';
if ($origamiBase !== '' && $switchToken !== '' && $appKey !== '') {
    $destination = $origamiBase . '/api/oauth/v2/switch?token=' . urlencode($switchToken) . '&app=' . urlencode($appKey);
}

// 2026-08-29, explicit follow-up request: "การเก็บประวัติเก็บตอน Switch App มาที่ Payroll และ Logout คือ
// ตอน Switch ออกจาก Payroll ไปที่อื่น แต่ถ้าไม่มีก็แสดงว่าไม่ Logout" -- this IS "leaving Payroll" (see
// this file's own top-of-file docblock: switching away via the hub is the only real "leaving"
// action this app has), so it's the logout_at capture point for employee_login_logs (see that
// column's own migration comment). Reads login_log_id from THIS session (never trusted from a
// request param) before the teardown below wipes it -- must run BEFORE $_SESSION = [] a few lines
// down. Best-effort, wrapped in its own try/catch: a DB hiccup here must never block a real logout.
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/EmployeeLoginLogModel.php';
try {
    $loginLogId = (int)($_SESSION['login_log_id'] ?? 0);
    $switchEmployeeId = (int)($_SESSION['user']['employee_id'] ?? 0);
    $switchCompanyId = (int)($_SESSION['user']['company_id'] ?? 0);
    if ($loginLogId > 0 && $switchEmployeeId > 0 && $switchCompanyId > 0) {
        (new EmployeeLoginLogModel())->recordLogout($loginLogId, $switchCompanyId, $switchEmployeeId);
    }
} catch (Throwable $e) {
    // Best-effort -- a logout-timestamp failure must never block the actual session teardown/switch.
}

// Full session teardown -- clear the data, expire the cookie, destroy the session store entry.
// Same three-step pattern PHP's own session_destroy() manual page documents for a real logout
// (session_destroy() alone does not expire the browser's cookie).
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

header('Location: ' . $destination);
exit;
