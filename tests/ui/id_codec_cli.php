<?php
/**
 * 2026-09-22, 3e-3 round B1: IdCodec::decode() (app/services/IdCodec.php) is PHP-only (AES-256-GCM
 * via EncryptionService, keyed off this install's own .env) -- a Playwright round script cannot call
 * it directly, so o_history_dt.js shells out to this instead of hardcoding a run's token as a
 * literal (the same reasoning find_banner_run.php's own docblock gives for not hardcoding a run id).
 *
 * Usage:
 *   php tests/ui/id_codec_cli.php decode <token>   -> prints the numeric id, or `none`
 *   php tests/ui/id_codec_cli.php encode <id>       -> prints the token
 *
 * Read-only, no DB query (IdCodec itself never touches one) -- pure encode/decode, same loopback
 * guard every CLI tool in this folder carries.
 */
declare(strict_types=1);

// 2026-09-22, 3e-3 round B2: the 2 sibling CLI tools' own guard (`exit("string")`) never actually
// sets a non-zero exit code -- a string argument to exit() prints it and exits 0, same as success.
// Fixed here (not retroactively in those 2 files, out of this round's scope) with the same
// fwrite(STDERR)+exit(1) pair this file's own loopback/usage guards below already use, so a caller
// that checks the exit code (rather than reading output) can actually tell this failed.
if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "tests/ui/id_codec_cli.php is a CLI tool.\n");
    exit(1);
}

$ROOT = dirname(__DIR__, 2);
require_once $ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($ROOT)->load();
require_once $ROOT . '/config.php';
require_once $ROOT . '/app/services/EncryptionService.php';
require_once $ROOT . '/app/services/IdCodec.php';

$host = strtolower((string)parse_url(BASE_URL, PHP_URL_HOST));
if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
    fwrite(STDERR, "refusing to run: BASE_URL host is '{$host}', not a loopback address.\n");
    exit(1);
}

$mode = $argv[1] ?? '';
$arg = $argv[2] ?? '';

if ($mode === 'decode') {
    $id = IdCodec::decode((string)$arg);
    echo ($id === null ? 'none' : (string)$id) . "\n";
    exit(0);
}
if ($mode === 'encode' && ctype_digit($arg)) {
    echo IdCodec::encode((int)$arg) . "\n";
    exit(0);
}
fwrite(STDERR, "usage: php tests/ui/id_codec_cli.php decode <token> | encode <id>\n");
exit(1);
