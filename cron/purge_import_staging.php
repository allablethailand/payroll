<?php
declare(strict_types=1);

/**
 * Discards Data Import batches that were uploaded but never finished (default: older than 24 hours),
 * deleting their staged rows (personal data) and stored file. Run standalone from the OS scheduler,
 * same as send_queued_emails.php:
 *   0 * * * * /usr/bin/php /path/to/payroll/cron/purge_import_staging.php [hours]
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/ImportStagingModel.php';

$hours = isset($argv[1]) ? max(1, (int)$argv[1]) : 24;
$purged = (new ImportStagingModel())->purgeStale($hours);
echo date('c') . " purged {$purged} stale import batch(es) older than {$hours}h\n";
