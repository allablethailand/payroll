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
        if (!$isLoggedIn && !$isExcluded) {
            $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            $isApiRoute = (strpos($requestUri, '/api/') !== false);
            if ($isAjax || $isApiRoute) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode([
                    'status' => false,
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