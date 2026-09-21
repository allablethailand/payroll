<?php
/**
 * Prints the token of a run that shows the Detail page's run-level banners, or the word `none`.
 *
 * Two round scripts (m3e1_tabs_shared.js's c14, m3e2a_calc_badges.js's p10c) need a run that really
 * shows more than one banner at once. Both of them took it as an argv, with a hard-coded token as
 * the fallback and the run's numeric id written only in a comment -- which is fine until this dev DB
 * changes under them, and then the cell measures one banner while its label says two. This finds the
 * run at run time instead, from the same 4 conditions the page's own render path reads:
 *
 *   has_validation_errors = 1   -> #validationErrorsBanner
 *   sync_process_id IS NOT NULL -> #syncMissingEmployeesBanner (loadSyncMissingEmployeesBanner())
 *   state = 'draft'             -> both of the above are draft-only
 *   auto_recalculate = 0        -> so merely opening the run does not POST recalculate
 *
 * Read-only: one SELECT, no writes of any kind, no state file. Prints ONE line -- the IdCodec token,
 * or `none` when this database has no such run, which is a legitimate answer the caller handles
 * (the two cells fall back to mocking the fields instead of silently skipping).
 *
 * Usage:  php tests/ui/find_banner_run.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("tests/ui/find_banner_run.php is a CLI tool.\n");
}

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($ROOT)->load();
require_once $ROOT . '/config.php';
require_once $ROOT . '/app/core/Database.php';
spl_autoload_register(function ($class) use ($ROOT) {
    foreach (['app/models/', 'app/services/', 'app/core/', 'app/controllers/'] as $p) {
        $f = $ROOT . '/' . $p . $class . '.php';
        if (file_exists($f)) { require_once $f; return; }
    }
});

// Same loopback guard every tool in this folder carries (see mksession.php's own note on why the
// check is the URL and not APP_ENV).
$host = strtolower((string)parse_url(BASE_URL, PHP_URL_HOST));
if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
    fwrite(STDERR, "refusing to run: BASE_URL host is '{$host}', not a loopback address.\n");
    exit(1);
}

$db = Database::getInstance()->pdo;
$stmt = $db->prepare("SELECT id FROM `payroll_runs`
    WHERE has_validation_errors = 1
      AND sync_process_id IS NOT NULL
      AND auto_recalculate = 0
      AND state = 'draft'
    ORDER BY id DESC LIMIT 1");
$stmt->execute();
$id = $stmt->fetchColumn();

echo ($id === false ? 'none' : IdCodec::encode((int)$id)) . "\n";
