<?php
    /** 2026-09-11, extracted out of asset() below so header.php can get the SAME cache-busting
     *  version number for a file that isn't loaded via a <script>/<link> tag (public/lang/*.json,
     *  fetched by app.js's own loadLang() -- see that function's own comment) without duplicating
     *  this filemtime/fallback logic a second time. */
    function assetVersion(string $path): int {
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($path, '/');
        return file_exists($fullPath) ? filemtime($fullPath) : time();
    }
    function asset($path) {
        $base = rtrim(BASE_URL, '/');
        return $base . '/' . ltrim($path, '/') . '?v=' . assetVersion($path);
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
        // Machine-to-machine pushes from Origami (see PayrollSyncController::ingest()/
        // attributionUpdate()) -- each authenticated by its own separate Bearer/*_API_KEY check,
        // not a session. Scoped to exactly these 2 actions -- other api/payroll-sync.* routes (e.g.
        // pending-list, for the logged-in Payroll Process page) must stay session-gated. 2026-09-08:
        // widened from a single hardcoded route to an array of exact matches (not a broad prefix)
        // now that a 2nd machine-to-machine route exists, on purpose so a FUTURE api/payroll-sync.*
        // route defaults to session-gated unless explicitly added here.
        $machineToMachineSyncRoutes = ['/api/payroll-sync.ingest', '/api/payroll-sync.attribution-update'];
        $isApiPayrollSyncRoute = false;
        foreach ($machineToMachineSyncRoutes as $mtmRoute) {
            if (strpos($requestUri, $mtmRoute) !== false) {
                $isApiPayrollSyncRoute = true;
                break;
            }
        }
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

    // 2026-09-13, Phase Design Round 2 item 5 (docs/design/rules.md §5) -- the ONE PHP entry point
    // that reads app/config/status_map.php. Also called directly from layout/header.php, which
    // json_encode()s this function's own return value straight into a "window.STATUS_MAP = ...;"
    // assignment (the ONE approved exception to Round 2's own "ห้ามแตะหน้าจริง" rule) to hand this
    // exact same array to JS's own statusBadgeHtml() -- there is no second, hand-kept copy of this
    // data anywhere; JS reads whatever this function returns, injected as-is. (NOTE: this comment
    // block twice broke every function declared below it, app-wide, by literally spelling out the
    // PHP short-echo open tag followed by its own closing delimiter as plain text -- PHP's lexer
    // switches out of PHP mode the instant it sees that 2-character sequence ANYWHERE in the file,
    // comments included, printing everything after it as raw output instead of parsing it. Avoid
    // ever typing that exact sequence again in a comment in this file, including describing it.)
    // loadStatusMap() itself reads the file once per request (a static var, not a global -- this
    // file has no class to hang it off of) since it's a small, static array with no reason to
    // re-parse it on every single badge rendered in a page with dozens of table rows.
    function loadStatusMap(): array {
        static $map = null;
        if ($map === null) {
            $map = require __DIR__ . '/../config/status_map.php';
        }
        return $map;
    }

    /**
     * Raw lookup -- returns the status_map.php entry ({label_key, tone, direction?}) for one
     * enum value in one context, or null if that context/enum combination isn't mapped. Exists as
     * its own function (not just inlined into statusBadge() below) because status-tabs.php's own
     * caller needs the raw tone/direction pair to build its own $tabs array -- it has no use for a
     * fully rendered `<span class="badge">` HTML string, since its own pill markup is a plain colored
     * number, not a label+badge.
     */
    function statusMapEntry(string $enum, string $context): ?array {
        $map = loadStatusMap();
        return $map[$context][$enum] ?? null;
    }

    // PHP has no client-side "currently active language" to render against (this app's own
    // established convention -- see any existing `<span data-i18n="key">English text</span>` --
    // server-rendered text is ALWAYS the English fallback, swapped client-side by app.js's own
    // updateText() once langData loads, regardless of which language ends up showing). Reading
    // en.json directly (once per request, cached) keeps that fallback text byte-identical to the
    // real translation file instead of hand-typing an English string a second time that could drift
    // out of sync with it.
    function statusEnLabelFallback(string $labelKey): string {
        static $enLang = null;
        if ($enLang === null) {
            $path = __DIR__ . '/../../public/lang/en.json';
            $raw = file_exists($path) ? file_get_contents($path) : false;
            $enLang = $raw !== false ? (json_decode($raw, true) ?: []) : [];
        }
        return $enLang[$labelKey] ?? $labelKey;
    }

    /**
     * The ONE PHP way to render a status badge -- docs/design/rules.md §5. Returns a full
     * `<span class="badge badge-{tone}" data-badge="status" data-i18n="{label_key}">{English
     * fallback}</span>`, matching this app's own established data-i18n convention exactly (server
     * renders English, app.js's updateText() swaps it to the active language once langData loads --
     * no different from any other server-rendered i18n span already in this codebase).
     * `data-badge="status"` is the marker §12's own lint rule #8 checks for (`<span class="badge"`
     * with no such marker = didn't come through this function) -- present from day one so lint rule
     * #8 (item 8, not built yet) has something to actually find once it exists, instead of every
     * badge this round produces needing a retrofit later.
     *
     * An enum/context combination NOT found in status_map.php renders as a plain neutral badge with
     * the RAW enum value as its label (no data-i18n -- there's no real key to swap to -- but STILL
     * carries data-badge="status", since it genuinely did come through this function) and logs the
     * gap via error_log() so it surfaces in the server's own error log rather than failing silently
     * or fatally -- a missing map entry should never be the thing that breaks a page render.
     */
    function statusBadge(string $enum, string $context): string {
        $entry = statusMapEntry($enum, $context);
        if ($entry === null) {
            error_log("status_map: missing enum '{$enum}' for context '{$context}'");
            return '<span class="badge badge-neutral" data-badge="status">' . htmlspecialchars($enum) . '</span>';
        }
        $tone = htmlspecialchars($entry['tone'] ?? 'neutral');
        $labelKey = $entry['label_key'];
        $label = statusEnLabelFallback($labelKey);
        return '<span class="badge badge-' . $tone . '" data-badge="status" data-i18n="' . htmlspecialchars($labelKey) . '">' . htmlspecialchars($label) . '</span>';
    }