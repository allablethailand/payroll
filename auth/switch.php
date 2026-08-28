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
