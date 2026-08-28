<?php
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_USER', $_ENV['DB_USER'] ?? '');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_NAME', $_ENV['DB_NAME'] ?? '');

define('BASE_URL', rtrim($_ENV['BASE_URL'] ?? '', '/'));
define('APP_URL',  rtrim($_ENV['APP_URL'] ?? '', '/'));

// Origami SSO (JWT handshake against /api/oauth/v2/auth on the Origami HR app -- separate
// from ORIGAMI_API_BASE_URL below, which is the still-unimplemented HR data-sync API).
// O_CLIENT_ID/O_CLIENT_SECRET must match a row in Origami's m_application table
// (m_app_username/m_app_password) that was registered for this payroll app. See auth/index.php.
define('ORIGAMI_BASE_URL', rtrim($_ENV['ORIGAMI_BASE_URL'] ?? '', '/'));
define('O_CLIENT_ID', $_ENV['O_CLIENT_ID'] ?? '');
define('O_CLIENT_SECRET', $_ENV['O_CLIENT_SECRET'] ?? '');

// Payroll Sync ingest API (POST /api/payroll-sync.ingest) -- see
// PayrollSyncController/PayrollSyncModel and PAYROLL_SYNC_API.md.
define('PAYROLL_SYNC_INGEST_API_KEY', $_ENV['PAYROLL_SYNC_INGEST_API_KEY'] ?? '');