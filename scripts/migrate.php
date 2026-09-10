<?php
declare(strict_types=1);

/**
 * Batch 3B item 0. Single source of truth for applying `database/migrations/*.sql` files against
 * a database -- replaces the ad-hoc "mysql < file.sql" / PHP-CLI-exec practice this whole project
 * used before now, which broke on production: `mysql < file.sql` runs the ENTIRE file, so a
 * migration written as `-- UP ... -- DOWN ...` in one file (this project's own convention) has
 * BOTH sections executed back to back -- the DOWN section immediately dropped what the UP section
 * had just created. This script is what every migration THIS SESSION actually ran through
 * manually (split UP from DOWN via PDO, run only UP), made repeatable/scriptable instead of a
 * one-off PHP snippet typed out each time.
 *
 * Commands:
 *   php scripts/migrate.php status         -- classify every migration file: applied (run through
 *                                              this tool), applied (detected pre-existing on this
 *                                              DB), pending, or unknown (can't auto-classify)
 *   php scripts/migrate.php up             -- run every PENDING file's UP section, in filename
 *                                              order, recording each into `schema_migrations`.
 *                                              REFUSES to run anything at all (not even the
 *                                              genuinely pending files) while any `unknown` file
 *                                              exists -- see "First deploy" below for why.
 *   php scripts/migrate.php down <file>    -- run one file's DOWN section and remove its record
 *                                              (refuses if the file has no DOWN section, or was
 *                                              never recorded as applied)
 *   php scripts/migrate.php mark <file>    -- record a file as applied WITHOUT running it
 *                                              (`applied_via='manual'`) -- for a file `status`
 *                                              lists under "Unknown" that a human has manually
 *                                              confirmed is already applied on this database
 *   php scripts/migrate.php mark-all-unknown --reason="<text>" --yes
 *                                           -- 2026-09-10, explicit follow-up request: marks EVERY
 *                                              file currently in the "Unknown" bucket applied
 *                                              (`applied_via='manual'`, `reason` recorded on each
 *                                              row) in one shot, for an environment where a human
 *                                              has already independently confirmed the target
 *                                              database's schema is fully current -- reviewing all
 *                                              38+ pre-migrate.php legacy files one by one via
 *                                              `mark <file>` is real, separate work this command
 *                                              deliberately does NOT replace the need for; it only
 *                                              lets someone who has ALREADY done that review (by
 *                                              other means -- e.g. this codebase's own git history
 *                                              and direct schema inspection) record the outcome
 *                                              without 38 individual commands. REFUSES without
 *                                              `--yes` (explicit confirmation this was actually
 *                                              reviewed, not a default/accidental action) or without
 *                                              a non-empty `--reason=` (an unexplained bulk-mark is
 *                                              exactly the kind of thing a future reader of
 *                                              schema_migrations needs the "why" for). Also REFUSES
 *                                              entirely whenever `status` still shows any genuinely
 *                                              PENDING (detectable, not-yet-applied) file -- pending
 *                                              and unknown are different buckets for a reason, and
 *                                              this command must never be used in a state where real
 *                                              unapplied work could be mistaken for already-settled.
 *                                              Never applicable to production without ITS OWN
 *                                              from-scratch schema review (see this project's
 *                                              CLAUDE.md's own "First deploy to a NEW environment"
 *                                              step for why -- dev and prod are not assumed to be in
 *                                              the same state just because dev was just reviewed).
 *
 * Uses the SAME DB connection/config as the app (`Database::getInstance()`, itself reading
 * `.env`/`config.php`) -- no credentials of any kind live in this script.
 *
 * --- First deploy to a NEW environment (a fresh DB, or one this tool has never run against) ---
 *   1. `php scripts/migrate.php status` -- see what's pending vs. unknown
 *   2. Manually inspect every file listed under "Unknown" against the target database's real
 *      schema (each file's own UP section says what it's supposed to create/change)
 *   3. `php scripts/migrate.php mark <file>` for each one CONFIRMED already applied; for one
 *      confirmed NOT applied, apply it deliberately some other way, then `mark` it once done
 *   4. `php scripts/migrate.php up` -- now safe: only genuinely pending files run, since every
 *      unknown from step 1 was resolved in step 3
 * `up` deliberately REFUSES to proceed at all while any unknown file remains (not just skip it
 * with a warning) -- on a database this tool has never seen before, a real schema gap sitting in
 * the "unknown" bucket must never be silently glossed over just because most OTHER files turned
 * out fine.
 *
 * --- File format ---
 * A migration written in this project's `-- UP` / `-- DOWN` convention has its 2 sections split
 * and only UP (or only DOWN, for the `down` command) is ever executed. A migration written BEFORE
 * this convention existed (every file before 2026-09-10) has NO such markers -- its entire content
 * is treated as UP, with no DOWN available (`down` on one of these refuses with a clear message,
 * it was never meant to be rolled back by this tool).
 *
 * --- Detecting pre-existing migrations (backward compatibility) ---
 * The very first time this tool runs against a database that already has most of this project's
 * schema (every dev DB, and production up through whichever commit it's currently on), it must
 * NOT try to blindly re-run ~140 historical files that are already applied. For each not-yet-
 * recorded file, `detectMainObject()` extracts a best-effort single representative marker from its
 * UP content -- the first `CREATE TABLE` it creates, or failing that the first `ALTER TABLE ... ADD
 * COLUMN` it adds -- and checks whether that table/column ALREADY EXISTS on the target database.
 * If it does, the file is recorded as `applied_via='detected'` WITHOUT running its SQL (this is
 * the whole point: never re-run something already there). If the marker genuinely doesn't exist,
 * the file is a real PENDING candidate for `up` to execute. A file this heuristic can't find any
 * marker in at all (a DROP-only file, a pure data-seed INSERT, a file with only complex/multiple
 * ALTERs) is classified `unknown` -- resolved via `mark <file>` (already applied) or by applying
 * it deliberately then `mark`-ing it, per "First deploy" above; `up` itself never touches these.
 *
 * DDL note: MySQL/InnoDB auto-commits every DDL statement (CREATE/ALTER/DROP) -- there is no such
 * thing as a transactional rollback of a partially-applied migration file. If a file with multiple
 * statements fails partway through, the earlier statements in that SAME file already took effect
 * and stay applied; `up` stops immediately (does not proceed to the next FILE) so a human can
 * inspect the actual DB state before deciding how to continue. Write migrations so each statement
 * is safe to leave applied on its own.
 */

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';

const MIGRATE_BOOTSTRAP_FILE = '2026-09-10_0_create_schema_migrations.sql';
const MIGRATE_REASON_COLUMN_FILE = '2026-09-10_4_schema_migrations_add_reason.sql';

function migTableExists(PDO $pdo, string $table): bool {
    return (bool)$pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table))->fetchColumn();
}

function migColumnExists(PDO $pdo, string $table, string $column): bool {
    if (!migTableExists($pdo, $table)) {
        return false;
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
    return (bool)$stmt->fetch();
}

function migListFiles(string $dir): array {
    $files = glob($dir . '/*.sql');
    if ($files === false) {
        return [];
    }
    sort($files, SORT_STRING);
    return array_map('basename', $files);
}

/** @return array{up:string, down:?string} */
function migSplitUpDown(string $sql): array {
    if (preg_match('/--\s*UP\s*(.*?)\s*--\s*DOWN\s*(.*)$/is', $sql, $m)) {
        return ['up' => trim($m[1]), 'down' => trim($m[2])];
    }
    // No -- UP/-- DOWN markers at all -- a pre-convention migration. Whole file is UP, no DOWN.
    return ['up' => trim($sql), 'down' => null];
}

function migStripLineComments(string $sql): string {
    $lines = explode("\n", $sql);
    $kept = array_filter($lines, static fn($line) => strpos(ltrim($line), '--') !== 0);
    return implode("\n", $kept);
}

function migExecMulti(PDO $pdo, string $sql): void {
    $sql = migStripLineComments($sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        $pdo->exec($statement);
    }
}

/**
 * @return ?array{type:string, name?:string, table?:string, column?:string}
 * Real bug found and fixed before shipping (not guessed -- caught by actually running this
 * against every real migration file, see this project's own conversation record): the original
 * `CREATE\s+TABLE\s+` +optional-backtick+\w+ pattern didn't account for `CREATE TABLE IF NOT
 * EXISTS ...` (used by every "catch up a table that's missing on some environments" migration in
 * this project) -- against that syntax it matched the bare word "IF" as the table name, which of
 * course never exists, so every such file was wrongly classified `pending` on a database that
 * already had the REAL table. Same `IF NOT EXISTS` optional-skip added to the ADD COLUMN branch
 * for the same reason (MySQL 8.0.29+ supports `ADD COLUMN IF NOT EXISTS`).
 */
function migDetectMainObject(string $upSql): ?array {
    $upSql = migStripLineComments($upSql);
    if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $upSql, $m)) {
        return ['type' => 'table', 'name' => $m[1]];
    }
    // (?!CONSTRAINT\b) excludes `ADD CONSTRAINT ... FOREIGN KEY` (an FK-only change, e.g. a
    // DROP+re-ADD FOREIGN KEY migration with no column of its own to detect at all) -- without
    // it, "ADD CONSTRAINT `fk_x`..." matched CONSTRAINT itself as if it were a column name,
    // caught the same way as the CREATE TABLE IF NOT EXISTS bug above: by actually running this
    // against every real migration file before shipping, not assumed correct.
    if (preg_match('/ALTER\s+TABLE\s+`?(\w+)`?[^;]*?ADD\s+(?!CONSTRAINT\b)(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/is', $upSql, $m)) {
        return ['type' => 'column', 'table' => $m[1], 'column' => $m[2]];
    }
    return null;
}

function migEnsureBootstrapped(PDO $pdo, string $dir): void {
    if (migTableExists($pdo, 'schema_migrations')) {
        return;
    }
    $path = $dir . '/' . MIGRATE_BOOTSTRAP_FILE;
    if (!is_file($path)) {
        fwrite(STDERR, 'FATAL: bootstrap migration ' . MIGRATE_BOOTSTRAP_FILE . " not found in {$dir}\n");
        exit(1);
    }
    $split = migSplitUpDown((string)file_get_contents($path));
    migExecMulti($pdo, $split['up']);
    $pdo->prepare("INSERT INTO `schema_migrations` (filename, applied_via) VALUES (:f, 'run')")
        ->execute([':f' => MIGRATE_BOOTSTRAP_FILE]);
    echo 'Bootstrapped: created schema_migrations, recorded ' . MIGRATE_BOOTSTRAP_FILE . ".\n";
}

/** Same self-bootstrap technique as migEnsureBootstrapped() above, one level down: the `reason`
 *  column mark-all-unknown depends on (see this file's own docblock) can't wait for a normal `up`
 *  run, since `up` itself is exactly what's blocked while unknown files remain -- self-applying
 *  this ONE specific ALTER directly breaks that chicken-and-egg. Recorded applied_via='run' since
 *  it genuinely was executed, same as migEnsureBootstrapped()'s own record for its file. */
function migEnsureReasonColumn(PDO $pdo, string $dir): void {
    if (migColumnExists($pdo, 'schema_migrations', 'reason')) {
        return;
    }
    $path = $dir . '/' . MIGRATE_REASON_COLUMN_FILE;
    if (!is_file($path)) {
        fwrite(STDERR, 'FATAL: reason-column migration ' . MIGRATE_REASON_COLUMN_FILE . " not found in {$dir}\n");
        exit(1);
    }
    $split = migSplitUpDown((string)file_get_contents($path));
    migExecMulti($pdo, $split['up']);
    $pdo->prepare("INSERT INTO `schema_migrations` (filename, applied_via) VALUES (:f, 'run')")
        ->execute([':f' => MIGRATE_REASON_COLUMN_FILE]);
    echo 'Bootstrapped: added schema_migrations.reason, recorded ' . MIGRATE_REASON_COLUMN_FILE . ".\n";
}

/** @return array{applied_run:string[], applied_detected:string[], applied_manual:string[], pending:string[], unknown:string[]}
 *  Side effect: backfills a 'detected' schema_migrations row for any not-yet-recorded file whose
 *  main object is found to already exist -- both status and up converge the tracking table to
 *  reality this way; neither ever executes a migration's SQL as part of classification. */
function migClassify(PDO $pdo, string $dir): array {
    $files = migListFiles($dir);
    $recorded = [];
    foreach ($pdo->query('SELECT filename, applied_via FROM `schema_migrations`')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recorded[$row['filename']] = $row['applied_via'];
    }
    $viaBucket = ['run' => 'applied_run', 'detected' => 'applied_detected', 'manual' => 'applied_manual'];
    $result = ['applied_run' => [], 'applied_detected' => [], 'applied_manual' => [], 'pending' => [], 'unknown' => []];
    foreach ($files as $file) {
        if ($file === MIGRATE_BOOTSTRAP_FILE) {
            // Always recorded by migEnsureBootstrapped() before classify() ever runs; skip re-processing.
            if (isset($recorded[$file])) {
                $result['applied_run'][] = $file;
            }
            continue;
        }
        if (isset($recorded[$file])) {
            $result[$viaBucket[$recorded[$file]] ?? 'applied_detected'][] = $file;
            continue;
        }
        $split = migSplitUpDown((string)file_get_contents($dir . '/' . $file));
        $obj = migDetectMainObject($split['up']);
        if ($obj === null) {
            $result['unknown'][] = $file;
            continue;
        }
        $exists = $obj['type'] === 'table'
            ? migTableExists($pdo, $obj['name'])
            : migColumnExists($pdo, $obj['table'], $obj['column']);
        if ($exists) {
            $pdo->prepare("INSERT INTO `schema_migrations` (filename, applied_via) VALUES (:f, 'detected')")
                ->execute([':f' => $file]);
            $result['applied_detected'][] = $file;
        } else {
            $result['pending'][] = $file;
        }
    }
    return $result;
}

function cmdStatus(PDO $pdo, string $dir): void {
    $r = migClassify($pdo, $dir);
    echo 'Applied (run via this tool):        ' . count($r['applied_run']) . "\n";
    echo 'Applied (detected, pre-existing):   ' . count($r['applied_detected']) . "\n";
    echo 'Applied (manually marked):          ' . count($r['applied_manual']) . "\n";
    echo 'Pending (will run on `up`):         ' . count($r['pending']) . "\n";
    foreach ($r['pending'] as $f) {
        echo "  - {$f}\n";
    }
    echo 'Unknown (cannot auto-classify -- `mark` or investigate, see `up`): ' . count($r['unknown']) . "\n";
    foreach ($r['unknown'] as $f) {
        echo "  - {$f}\n";
    }
}

function cmdUp(PDO $pdo, string $dir): void {
    $r = migClassify($pdo, $dir);
    // 2026-09-10, explicit instruction: `up` must not proceed AT ALL (not even the genuinely
    // pending files) while any file remains unresolved in the "unknown" bucket -- silently
    // skipping past it risked masking a real schema gap on a database this tool has never run
    // against before (exactly the "first deploy to a new environment" case this tool exists for).
    if (!empty($r['unknown'])) {
        fwrite(STDERR, count($r['unknown']) . " file(s) need manual review before `up` can run -- see `status`, then `mark <file>` each one once confirmed:\n");
        foreach ($r['unknown'] as $f) {
            fwrite(STDERR, "  - {$f}\n");
        }
        exit(1);
    }
    if (empty($r['pending'])) {
        echo "Nothing to do -- no pending migrations.\n";
    }
    foreach ($r['pending'] as $file) {
        echo "Running UP: {$file} ... ";
        $split = migSplitUpDown((string)file_get_contents($dir . '/' . $file));
        try {
            migExecMulti($pdo, $split['up']);
            $pdo->prepare("INSERT INTO `schema_migrations` (filename, applied_via) VALUES (:f, 'run')")
                ->execute([':f' => $file]);
            echo "OK\n";
        } catch (Throwable $e) {
            echo "FAILED\n";
            fwrite(STDERR, '  ' . $e->getMessage() . "\n");
            fwrite(STDERR, "Stopping -- inspect the database state and fix before re-running `up` (DDL in MySQL auto-commits per statement, so earlier statements in THIS file, if any, already took effect).\n");
            exit(1);
        }
    }
}

/** Parses `--yes` and `--reason="..."` from argv (starting at $startIndex) -- this project's CLI
 *  scripts have no argument-parsing library, and these 2 flags are simple enough not to need one. */
function migParseFlags(array $argv, int $startIndex): array {
    $flags = ['yes' => false, 'reason' => null];
    for ($i = $startIndex; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if ($arg === '--yes') {
            $flags['yes'] = true;
        } elseif (strpos($arg, '--reason=') === 0) {
            $flags['reason'] = substr($arg, strlen('--reason='));
        }
    }
    return $flags;
}

function cmdMarkAllUnknown(PDO $pdo, string $dir, ?string $reason, bool $confirmed): void {
    if (!$confirmed) {
        fwrite(STDERR, "Refusing: pass --yes to confirm you have ALREADY reviewed this database's schema and confirmed it's current -- this command does not do that review for you.\n");
        fwrite(STDERR, "Usage: php scripts/migrate.php mark-all-unknown --reason=\"...\" --yes\n");
        exit(1);
    }
    if ($reason === null || trim($reason) === '') {
        fwrite(STDERR, "Refusing: --reason=\"...\" is required -- explain why these files are being marked applied without being reviewed one by one, for whoever reads schema_migrations later.\n");
        exit(1);
    }
    $r = migClassify($pdo, $dir);
    // 2026-09-10, explicit instruction: refuses entirely while any genuinely PENDING (detectable,
    // not-yet-applied) file exists -- pending and unknown are different buckets for a reason; this
    // guards against a real unapplied change being mistaken for already-settled just because this
    // command was reached for.
    if (!empty($r['pending'])) {
        fwrite(STDERR, count($r['pending']) . " pending (detectable, not-yet-applied) migration file(s) exist -- resolve those via `up` first, mark-all-unknown must never run while real pending work exists:\n");
        foreach ($r['pending'] as $f) {
            fwrite(STDERR, "  - {$f}\n");
        }
        exit(1);
    }
    if (empty($r['unknown'])) {
        echo "Nothing to do -- no unknown files.\n";
        return;
    }
    foreach ($r['unknown'] as $file) {
        $pdo->prepare("INSERT INTO `schema_migrations` (filename, applied_via, reason) VALUES (:f, 'manual', :reason)")
            ->execute([':f' => $file, ':reason' => $reason]);
    }
    echo 'Marked ' . count($r['unknown']) . " file(s) as applied (applied_via='manual'), reason: {$reason}\n";
    foreach ($r['unknown'] as $f) {
        echo "  - {$f}\n";
    }
}

function cmdMark(PDO $pdo, string $dir, string $file): void {
    $path = $dir . '/' . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "File not found: {$file}\n");
        exit(1);
    }
    $stmt = $pdo->prepare('SELECT id FROM `schema_migrations` WHERE filename = :f');
    $stmt->execute([':f' => $file]);
    if ($stmt->fetchColumn()) {
        fwrite(STDERR, "{$file} is already recorded in schema_migrations -- nothing to do.\n");
        exit(1);
    }
    $pdo->prepare("INSERT INTO `schema_migrations` (filename, applied_via) VALUES (:f, 'manual')")
        ->execute([':f' => $file]);
    echo "Marked {$file} as applied (applied_via='manual') -- its SQL was NOT run.\n";
}

function cmdDown(PDO $pdo, string $dir, string $file): void {
    $path = $dir . '/' . $file;
    if (!is_file($path)) {
        fwrite(STDERR, "File not found: {$file}\n");
        exit(1);
    }
    $stmt = $pdo->prepare('SELECT id FROM `schema_migrations` WHERE filename = :f');
    $stmt->execute([':f' => $file]);
    if (!$stmt->fetchColumn()) {
        fwrite(STDERR, "No record of {$file} having been applied -- nothing to roll back.\n");
        exit(1);
    }
    $split = migSplitUpDown((string)file_get_contents($path));
    if ($split['down'] === null || $split['down'] === '') {
        fwrite(STDERR, "{$file} has no -- DOWN section -- cannot roll back automatically.\n");
        exit(1);
    }
    echo "Running DOWN: {$file} ... ";
    try {
        migExecMulti($pdo, $split['down']);
        // Special case: rolling back the bootstrap migration ITSELF drops schema_migrations as
        // part of its own DOWN -- there's nothing left to delete a record FROM at that point (the
        // whole tracking table is gone, which already fully "un-records" everything).
        if (migTableExists($pdo, 'schema_migrations')) {
            $pdo->prepare('DELETE FROM `schema_migrations` WHERE filename = :f')->execute([':f' => $file]);
        }
        echo "OK\n";
    } catch (Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, '  ' . $e->getMessage() . "\n");
        exit(1);
    }
}

$pdo = Database::getInstance()->pdo;
$migrationsDir = realpath(__DIR__ . '/../database/migrations');
if ($migrationsDir === false) {
    fwrite(STDERR, "FATAL: database/migrations directory not found.\n");
    exit(1);
}

$command = $argv[1] ?? '';
switch ($command) {
    case 'status':
        migEnsureBootstrapped($pdo, $migrationsDir);
        migEnsureReasonColumn($pdo, $migrationsDir);
        cmdStatus($pdo, $migrationsDir);
        break;
    case 'up':
        migEnsureBootstrapped($pdo, $migrationsDir);
        migEnsureReasonColumn($pdo, $migrationsDir);
        cmdUp($pdo, $migrationsDir);
        break;
    case 'down':
        $targetFile = $argv[2] ?? '';
        if ($targetFile === '') {
            fwrite(STDERR, "Usage: php scripts/migrate.php down <filename>\n");
            exit(1);
        }
        migEnsureBootstrapped($pdo, $migrationsDir);
        migEnsureReasonColumn($pdo, $migrationsDir);
        cmdDown($pdo, $migrationsDir, $targetFile);
        break;
    case 'mark':
        $targetFile = $argv[2] ?? '';
        if ($targetFile === '') {
            fwrite(STDERR, "Usage: php scripts/migrate.php mark <filename>\n");
            exit(1);
        }
        migEnsureBootstrapped($pdo, $migrationsDir);
        migEnsureReasonColumn($pdo, $migrationsDir);
        cmdMark($pdo, $migrationsDir, $targetFile);
        break;
    case 'mark-all-unknown':
        migEnsureBootstrapped($pdo, $migrationsDir);
        migEnsureReasonColumn($pdo, $migrationsDir);
        $flags = migParseFlags($argv, 2);
        cmdMarkAllUnknown($pdo, $migrationsDir, $flags['reason'], $flags['yes']);
        break;
    default:
        fwrite(STDERR, "Usage: php scripts/migrate.php <status|up|down|mark|mark-all-unknown> [filename] [--reason=\"...\"] [--yes]\n");
        exit(1);
}
