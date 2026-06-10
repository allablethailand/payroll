<?php
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_USER', $_ENV['DB_USER'] ?? '');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_NAME', $_ENV['DB_NAME'] ?? '');

define('BASE_URL', rtrim($_ENV['BASE_URL'] ?? '', '/'));
define('APP_URL',  rtrim($_ENV['APP_URL'] ?? '', '/'));