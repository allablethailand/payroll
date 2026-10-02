<?php
/**
 * Verification for ImportStagingModel (import_staging_batches/rows). Runs inside a transaction that is always rolled back.
 * Requires migration 2026-10-01_2_import_staging to be applied.
 *
 * Run with: php tests/import_staging_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/ImportStagingModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
function check(string $label, $actual, $expected): void {
    global $failures;
    if ($actual === $expected) {
        echo "PASS: {$label}\n";
    } else {
        $failures++;
        echo "FAIL: {$label} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    }
}

try {
    $compId = (int)$pdo->query("SELECT id FROM companies ORDER BY id LIMIT 1")->fetchColumn();
    $m = new ImportStagingModel($pdo);
    $raw = [['Emp' => 'E1', 'Hours' => '8'], ['Emp' => 'E2', 'Hours' => '9'], ['Emp' => 'E3', 'Hours' => 'x']];

    $id = $m->create($compId, 'attendance', 'a.csv', null, 10, $raw, null);
    $b = $m->find($compId, $id);
    check('created as uploaded with total', [$b['status'], (int)$b['total_count']], ['uploaded', 3]);
    check('other company cannot see batch', $m->find($compId + 9999, $id), null);
    check('file headers', $m->fileHeaders($id), ['Emp', 'Hours']);

    $m->applyMapping($id, ['Emp' => 'employee_no', 'Hours' => 'hours'], function (array $rows, array $mapping) {
        return ['rows' => array_map(fn($r) => ['employee_no' => $r['Emp'], 'hours' => $r['Hours']], $rows)];
    });
    check('mapped status', $m->find($compId, $id)['status'], 'mapped');
    check('row_no starts at 2', array_column($m->mappedRows($id), 'row_no'), [2, 3, 4]);
    check('subset by row_no', array_column($m->mappedRows($id, [3]), 'row_no'), [3]);
    check('empty subset', $m->mappedRows($id, []), []);

    check('commit refused before validation', $m->claimForCommit($id), false);

    $m->saveValidation($id, [2 => ['status' => 'valid', 'messages' => []], 3 => ['status' => 'warning', 'messages' => ['w']], 4 => ['status' => 'error', 'messages' => ['bad hours']]]);
    $b = $m->find($compId, $id);
    check('counters', [$b['status'], (int)$b['valid_count'], (int)$b['warning_count'], (int)$b['error_count']], ['validated', 1, 1, 1]);
    check('commit refused with errors', $m->claimForCommit($id), false);
    check('errors listed first', $m->rows($id, null, 0, 10)['rows'][0]['row_no'], 4);

    $n = $m->editRows($id, [['row_no' => 4, 'data' => ['hours' => '7', 'evil' => 'x', 'employee_no' => ['arr']]], ['row_no' => 99, 'data' => ['hours' => '1']]], ['employee_no', 'hours']);
    check('only existing rows edited', $n, 1);
    $row = $m->rows($id, 'pending', 0, 10)['rows'][0] ?? [];
    check('edit applied, unknown/array keys ignored', [$row['data']['hours'] ?? null, $row['data']['employee_no'] ?? null, isset($row['data']['evil']), $row['edited'] ?? null], ['7', 'E3', false, true]);
    check('edit drops batch back to mapped', $m->find($compId, $id)['status'], 'mapped');
    check('error count refreshed after edit', (int)$m->find($compId, $id)['error_count'], 0);

    $m->saveValidation($id, [4 => ['status' => 'valid', 'messages' => []]]);
    check('commit allowed once all rows valid/warning', $m->claimForCommit($id), true);
    check('second claim refused', $m->claimForCommit($id), false);
    $m->releaseClaim($id);
    check('release returns to validated', $m->find($compId, $id)['status'], 'validated');

    check('claim again', $m->claimForCommit($id), true);
    $m->markCommitted($id, null);
    check('committed + rows purged', [$m->find($compId, $id)['status'], $m->rows($id, null, 0, 10)['total']], ['committed', 0]);
    check('committed batch cannot be discarded', $m->discard($id), false);

    $id2 = $m->create($compId, 'leave', 'b.csv', null, 5, [['A' => '1']], null);
    check('discard works', $m->discard($id2), true);
    check('discarded rows purged', $m->rows($id2, null, 0, 10)['total'], 0);

    $id3 = $m->create($compId, 'leave', 'old.csv', null, 5, [['A' => '1']], null);
    $id4 = $m->create($compId, 'leave', 'new.csv', null, 5, [['A' => '1']], null);
    $pdo->prepare("UPDATE import_staging_batches SET created_at = (NOW() - INTERVAL 30 HOUR) WHERE id = :id")->execute([':id' => $id3]);
    $m->purgeStale(24);
    check('stale batch purged, fresh one kept', [$m->find($compId, $id3)['status'], $m->find($compId, $id4)['status']], ['discarded', 'uploaded']);
    check('stale batch rows gone', $m->rows($id3, null, 0, 10)['total'], 0);

    $threw = false;
    try { $m->create($compId, 'leave', 'big.csv', null, 1, array_fill(0, ImportStagingModel::MAX_ROWS + 1, ['A' => '1']), null); } catch (InvalidArgumentException $e) { $threw = true; }
    check('row cap enforced', $threw, true);
} finally {
    $pdo->rollBack();
}

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
