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
    // 2026-08-30, Phase 7 (T038): server-side-authoritative idle timeout. Originally 30 minutes,
    // widened to 1 hour on 2026-08-31 per explicit request -- deliberately NOT the same knob as
    // session.gc_maxlifetime/cookie_lifetime (28800s/8h, extended 2026-08-29 to fix real premature
    // session loss -- see index.php's own comment on that ini_set pair) -- that pair controls how
    // long the underlying PHP session FILE is allowed to live at all (a hard ceiling, generous on
    // purpose so the file/cookie never vanishes for reasons unrelated to genuine inactivity), while
    // this is a separate, explicit "how long since the last real request" check enforced every
    // request via $_SESSION['last_activity'] below -- the two settings solve different problems and
    // neither should be conflated with the other. The client-side mirror (header.php's own
    // `const SESSION_IDLE_TIMEOUT_SECONDS`, read by public/js/session-guard.js's idle timer) is
    // derived directly from this constant via a short-echo PHP tag inline in that file -- changing
    // the number here alone is enough, no separate JS-side edit needed.
    define('SESSION_IDLE_TIMEOUT_SECONDS', 3600);

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

            // 2026-09-04, Backlog Phase 10, T058 ("Dashboard shows currently-online users") --
            // a SEPARATE presence signal from last_activity just above. Deliberately does NOT
            // reuse/share $_SESSION['last_activity'] (T038's own idle-timeout clock, which
            // intentionally EXCLUDES the heartbeat route so a forgotten-but-open tab can't defeat
            // the idle timeout) -- for THIS feature, a heartbeat DOES count as real presence (a
            // user with the tab genuinely open and polling is meaningfully "online"), so this
            // touch runs on every authenticated request including $isHeartbeatRoute, with its own
            // separate throttle key ($_SESSION['last_seen_touch']) so it never has to fight over
            // meaning with last_activity. Throttled to once per 60s per session to avoid a DB write
            // on every single request -- see EmployeeLoginLogModel::touchLastSeen()'s own docblock
            // for why it only ever touches a still-active row.
            if ($loginLogId > 0) {
                $lastSeenTouch = (int)($_SESSION['last_seen_touch'] ?? 0);
                if (($now - $lastSeenTouch) > 60) {
                    require_once __DIR__ . '/../models/EmployeeLoginLogModel.php';
                    (new EmployeeLoginLogModel())->touchLastSeen($loginLogId);
                    $_SESSION['last_seen_touch'] = $now;
                }
            }

            // 2026-09-04, Backlog Phase 10, T059 ("RBAC -- suspend a user's system access") -- a
            // live, per-request check (same "as immediate as stateless HTTP allows" posture as
            // T037's own superseded-session check above, not a lazier login-time-only gate).
            // Deliberately does NOT destroy the session the way session_kill_response() does for
            // T037/T038 -- app/views/permission.php (the existing "No Permission" page, reused
            // as-is here rather than building a parallel one) is designed to render inside the
            // NORMAL header/footer layout (sidebar, greeting, language switcher all read
            // $_SESSION['user']), and a suspended employee seeing their own name while being told
            // access is denied is the correct, intentional UX here -- not a forced logout. The
            // suspension marker itself (PermissionModel::isSuspended()) is a single indexed lookup,
            // cheap enough to check on every request the same way T037/T038 already do.
            // Skipped entirely for a live admin session ($_SESSION['user']['role'] === 'admin',
            // same check DashboardController::isAdmin() already uses) -- confirmed with the user:
            // workshop decision #2 ("admin can never be suspended") is a hard guarantee against an
            // admin ever being locked out, not just a preventive gate at suspend-time (which can't
            // be enforced anyway -- see the migration's own header comment). Mirrors the same
            // ordering fix just made in PermissionModel::checkPermission().
            $isAdminSession = (($_SESSION['user']['role'] ?? '') === 'admin');
            require_once __DIR__ . '/../models/PermissionModel.php';
            if (!$isAdminSession && (new PermissionModel())->isSuspended($employeeId, $compId)) {
                $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
                $isApiRoute = (strpos($requestUri, '/api/') !== false);
                if ($isAjax || $isApiRoute) {
                    http_response_code(403);
                    header('Content-Type: application/json');
                    echo json_encode(['status' => false, 'reason' => 'suspended', 'message' => 'Your account access has been suspended. Please contact your administrator.']);
                    exit;
                }
                // Same 3-include sequence Controller::view() uses (app/core/Controller.php) --
                // ensure_login() is a bare function, not a Controller instance, so it can't call
                // $this->view(), but the method itself is trivial enough to replicate inline rather
                // than restructuring this whole function around a Controller dependency for one
                // call site.
                include __DIR__ . '/../views/layout/header.php';
                include __DIR__ . '/../views/permission.php';
                include __DIR__ . '/../views/layout/footer.php';
                exit;
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