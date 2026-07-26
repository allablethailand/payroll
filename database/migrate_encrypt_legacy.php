<?php
declare(strict_types=1);

/**
 * One-time migration: encrypt legacy plaintext PDPA fields left over from before
 * field-level AES-256-GCM encryption was introduced (see app/services/EncryptionService.php).
 *
 * A row is considered "legacy" when its key_version column is NULL — the application
 * always sets key_version the moment it writes an encrypted value, so any row still
 * NULL predates encryption and holds plaintext in columns that are now expected to be
 * ciphertext. Already-migrated rows are skipped automatically, so this script is safe
 * to re-run (e.g. after a partial failure).
 *
 * Usage (must be run from the CLI, never over HTTP):
 *   php database/migrate_encrypt_legacy.php                 Dry run — reports what WOULD change, writes nothing.
 *   php database/migrate_encrypt_legacy.php --confirm        Runs the real migration inside one transaction.
 *   php database/migrate_encrypt_legacy.php --confirm --skip-backup   Skips the automatic mysqldump backup (not recommended).
 *
 * Before running with --confirm on a database that actually has legacy rows:
 *   1. Take a backup (this script does one automatically via mysqldump unless --skip-backup is passed).
 *   2. Run this script against a COPY of that database first and verify the report before touching production.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script may only be run from the command line.\n");
}

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';

$args = array_slice($argv, 1);
$confirm = in_array('--confirm', $args, true);
$skipBackup = in_array('--skip-backup', $args, true);

/**
 * table => [
 *   'idCol' => primary key column,
 *   'columns' => [ plaintextCol => hashCol|null ],
 * ]
 */
$TABLES = [
    'employees' => [
        'idCol' => 'id',
        'columns' => [
            'id_card_no' => 'id_card_no_hash',
            'tax_id_no' => 'tax_id_no_hash',
            'passport_no' => null,
            'bank_account_no' => 'bank_account_no_hash',
            'sso_no' => 'sso_no_hash',
            'spouse_id_card_no' => null,
        ],
    ],
    'employee_dependents' => [
        'idCol' => 'id',
        'columns' => [
            'id_card_no' => null,
        ],
    ],
    'employee_parents' => [
        'idCol' => 'id',
        'columns' => [
            'id_card_no' => null,
        ],
    ],
    'bank_accounts' => [
        'idCol' => 'id',
        'columns' => [
            'account_no' => 'account_no_hash',
        ],
    ],
];

function findLegacyRows(PDO $pdo, string $table, string $idCol, array $columns): array {
    $notNullChecks = array_map(fn($col) => "(`{$col}` IS NOT NULL AND `{$col}` != '')", array_keys($columns));
    $sql = "SELECT * FROM `{$table}` WHERE `key_version` IS NULL AND (" . implode(' OR ', $notNullChecks) . ")";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function runBackup(): bool {
    $dbHost = $_ENV['DB_HOST'] ?? 'localhost';
    $dbUser = $_ENV['DB_USER'] ?? 'root';
    $dbPass = $_ENV['DB_PASS'] ?? '';
    $dbName = $_ENV['DB_NAME'] ?? 'payroll';

    $mysqldumpCandidates = [
        'C:/xampp-vonconnect/mysql/bin/mysqldump.exe',
        'mysqldump',
    ];
    $mysqldump = null;
    foreach ($mysqldumpCandidates as $candidate) {
        if (file_exists($candidate)) {
            $mysqldump = $candidate;
            break;
        }
    }
    if ($mysqldump === null) {
        $mysqldump = 'mysqldump';
    }

    $backupDir = __DIR__ . '/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0700, true);
    }
    $backupFile = $backupDir . '/pre_encrypt_migration_' . date('Ymd_His') . '.sql';

    $cmd = sprintf(
        '%s --host=%s --user=%s %s%s > %s 2>%s',
        escapeshellarg($mysqldump),
        escapeshellarg($dbHost),
        escapeshellarg($dbUser),
        $dbPass !== '' ? '--password=' . escapeshellarg($dbPass) . ' ' : '',
        escapeshellarg($dbName),
        escapeshellarg($backupFile),
        escapeshellarg($backupFile . '.err')
    );
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0 || !file_exists($backupFile) || filesize($backupFile) === 0) {
        fwrite(STDERR, "Backup FAILED (exit code {$exitCode}). See {$backupFile}.err for details.\n");
        return false;
    }
    @unlink($backupFile . '.err');
    echo "Backup written to: {$backupFile}\n";
    return true;
}

echo "=== PDPA legacy-data encryption migration ===\n";
echo $confirm ? "Mode: LIVE (will write changes)\n" : "Mode: DRY RUN (no changes will be written; pass --confirm to apply)\n";
echo "\n";

if ($confirm && !$skipBackup) {
    echo "Taking a backup before migrating...\n";
    if (!runBackup()) {
        fwrite(STDERR, "Aborting migration: backup could not be created. Use --skip-backup only if you already have a verified backup.\n");
        exit(1);
    }
    echo "\n";
}

$pdo = Database::getInstance()->pdo;
$report = [];
$totalRows = 0;

try {
    if ($confirm) {
        $pdo->beginTransaction();
    }

    foreach ($TABLES as $table => $cfg) {
        $rows = findLegacyRows($pdo, $table, $cfg['idCol'], $cfg['columns']);
        $report[$table] = count($rows);
        $totalRows += count($rows);

        if (!$confirm || count($rows) === 0) {
            continue;
        }

        foreach ($rows as $row) {
            $setSql = [];
            $params = [':id' => $row[$cfg['idCol']]];
            $encryptedAny = false;

            foreach ($cfg['columns'] as $plainCol => $hashCol) {
                $val = $row[$plainCol] ?? null;
                if ($val === null || $val === '') {
                    continue;
                }
                $enc = EncryptionService::encrypt((string)$val);
                $setSql[] = "`{$plainCol}` = :{$plainCol}";
                $params[":{$plainCol}"] = $enc['value'] ?? null;
                $encryptedAny = true;

                if ($hashCol !== null) {
                    $setSql[] = "`{$hashCol}` = :{$hashCol}";
                    $params[":{$hashCol}"] = EncryptionService::hash((string)$val);
                }
            }

            if (!$encryptedAny) {
                continue;
            }

            $setSql[] = "`key_version` = :key_version";
            $params[':key_version'] = EncryptionService::currentKeyVersion();

            $sql = "UPDATE `{$table}` SET " . implode(', ', $setSql) . " WHERE `{$cfg['idCol']}` = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
    }

    if ($confirm) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if ($confirm && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Migration FAILED, transaction rolled back: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Legacy rows found per table:\n";
foreach ($report as $table => $count) {
    echo "  - {$table}: {$count}\n";
}
echo "Total: {$totalRows}\n\n";

if (!$confirm) {
    echo "This was a dry run. Re-run with --confirm to encrypt these rows.\n";
} else {
    echo "Migration complete. All matched rows were encrypted inside a single transaction.\n";
}
