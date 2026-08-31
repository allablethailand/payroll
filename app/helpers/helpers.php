<?php
    function asset($path) {
        $base = rtrim(BASE_URL, '/');
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($path, '/');
        if (file_exists($fullPath)) {
            $version = filemtime($fullPath);
        } else {
            $version = time();
        }
        return $base . '/' . ltrim($path, '/') . '?v=' . $version;
    }
    // 2026-08-30, Phase 7 (T038): server-side-authoritative idle timeout. 30 minutes chosen per
    // explicit request -- deliberately NOT the same knob as session.gc_maxlifetime/cookie_lifetime
    // (28800s/8h, extended 2026-08-29 to fix real premature session loss -- see index.php's own
    // comment on that ini_set pair) -- that pair controls how long the underlying PHP session FILE
    // is allowed to live at all (a hard ceiling, generous on purpose so the file/cookie never
    // vanishes for reasons unrelated to genuine inactivity), while this is a separate, explicit
    // "how long since the last real request" check enforced every request via $_SESSION['last_activity']
    // below -- the two settings solve different problems and neither should be conflated with the other.
    define('SESSION_IDLE_TIMEOUT_SECONDS', 1800);

    /**
     * Full session teardown (same 3-step pattern auth/switch.php already established: clear
     * $_SESSION, expire the cookie, session_destroy()) followed by the SAME "AJAX/API gets JSON,
     * a plain page load gets redirected to /auth" branch ensure_login() already used for "never
     * logged in at all" -- widened with a `reason` field so the frontend's shared AJAX handler
     * (public/js/session-guard.js) can tell a real timeout/duplicate-login apart from a plain "you
     * were never logged in" 401 and show the RIGHT popup copy for each.
     */
    function session_kill_response(string $reason, string $message) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();

        $requestUri = strtolower(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        $isApiRoute = (strpos($requestUri, '/api/') !== false);
        if ($isAjax || $isApiRoute) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['status' => false, 'reason' => $reason, 'message' => $message]);
            exit;
        }
        $redirectUrl = rtrim(BASE_URL, '/') . '/auth';
        header('Location: ' . $redirectUrl);
        exit;
    }

    function ensure_login() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $isLoggedIn = !empty($_SESSION['user']) &&
                    is_array($_SESSION['user']) &&
                    !empty($_SESSION['user']['employee_id']) &&
                    !empty($_SESSION['user']['company_id']);
        $requestUri = strtolower(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        $isAuthRoute = (strpos($requestUri, '/auth') !== false);
        $isApiAuthRoute = (strpos($requestUri, '/api/auth') !== false);
        $isApiLoginRoute = (strpos($requestUri, '/api/login') !== false);
        // Machine-to-machine ingest from Origami's cron job (see PayrollSyncController::ingest())
        // -- authenticated by its own Bearer/PAYROLL_SYNC_INGEST_API_KEY check, not a session.
        // Scoped to this one action specifically -- other api/payroll-sync.* routes (e.g.
        // pending-list, for the logged-in Payroll Process page) must stay session-gated.
        $isApiPayrollSyncRoute = (strpos($requestUri, '/api/payroll-sync.ingest') !== false);
        $isExcluded = $isAuthRoute || $isApiAuthRoute || $isApiLoginRoute || $isApiPayrollSyncRoute;

        if ($isLoggedIn && !$isExcluded) {
            // 2026-08-30, Phase 7 (T037/T038) -- both checks run on EVERY authenticated request,
            // this is the one and only enforcement point for both (session-guard.js's client-side
            // idle timer/heartbeat are UX on top of this, never a substitute for it -- a tampered
            // or paused browser tab must never be the only thing standing between an idle/kicked
            // session and continued access).
            $employeeId = (int)$_SESSION['user']['employee_id'];
            $compId = (int)$_SESSION['user']['company_id'];
            $loginLogId = (int)($_SESSION['login_log_id'] ?? 0);

            // T037: has a newer login (this same employee, any device) superseded this session?
            if ($loginLogId > 0) {
                require_once __DIR__ . '/../models/EmployeeLoginLogModel.php';
                $loginLogModel = new EmployeeLoginLogModel();
                if (!$loginLogModel->isActive($loginLogId, $employeeId)) {
                    session_kill_response('superseded', 'This account was signed in from another device or browser. You have been signed out here.');
                }
            }

            // T038: has this session been idle (no real request) longer than the timeout?
            // A heartbeat poll (public/js/session-guard.js's own periodic "am I still valid?"
            // check) deliberately does NOT count as real activity -- see api/session.heartbeat's
            // own route/controller comment for why bumping last_activity there would defeat the
            // whole point of an IDLE timeout (a background poll would keep it alive forever even
            // while the user is genuinely away).
            $isHeartbeatRoute = (strpos($requestUri, '/api/session.heartbeat') !== false);
            $now = time();
            $lastActivity = (int)($_SESSION['last_activity'] ?? $now);
            if (($now - $lastActivity) > SESSION_IDLE_TIMEOUT_SECONDS) {
                if ($loginLogId > 0) {
                    require_once __DIR__ . '/../models/EmployeeLoginLogModel.php';
                    (new EmployeeLoginLogModel())->endSession($loginLogId, $compId, $employeeId, 'timeout');
                }
                session_kill_response('timeout', 'Your session has timed out due to inactivity.');
            }
            if (!$isHeartbeatRoute) {
                $_SESSION['last_activity'] = $now;
            }
        }

        if (!$isLoggedIn && !$isExcluded) {
            $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            $isApiRoute = (strpos($requestUri, '/api/') !== false);
            if ($isAjax || $isApiRoute) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode([
                    'status' => false,
                    'reason' => 'not_logged_in',
                    'message' => 'Session expired or unauthorized. Please log in again.'
                ]);
                exit;
            } else {
                $redirectUrl = rtrim(BASE_URL, '/') . '/auth';
                header('Location: ' . $redirectUrl);
                exit;
            }
        }
    }
    function getCompId() {
        return $_SESSION['user']['company_id'] ?? null;
    }