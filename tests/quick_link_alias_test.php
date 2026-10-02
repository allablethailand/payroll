<?php
// Retired Quick Link keys (reports.run_audit, audit_log) must resolve to 'audit' for users who saved them before the menu merge.
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/PermissionModel.php';
require_once __DIR__ . '/../app/models/UserPreferenceModel.php';

$fails = 0;
$passes = 0;
$check = function (string $name, bool $ok) use (&$fails, &$passes) { if (!$ok) { $fails++; echo "FAIL $name\n"; } else { $passes++; echo "PASS $name\n"; } };

$pdo = Database::getInstance()->pdo;
$row = $pdo->query("SELECT id, comp_id FROM employees WHERE deleted_at IS NULL ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$pdo->beginTransaction();
try {
    $set = $pdo->prepare("UPDATE employees SET ui_quick_links = :l WHERE id = :id");
    $m = new UserPreferenceModel();
    $set->execute([':l' => json_encode(['employees.list', 'reports.run_audit', 'audit_log', 'dashboard']), ':id' => $row['id']]);
    $got = $m->getQuickLinks((int)$row['id'], (int)$row['comp_id']);
    $check('aliases collapse to one audit key, order kept', $got === ['employees.list', 'audit', 'dashboard']);
    $set->execute([':l' => json_encode(['audit', 'dashboard']), ':id' => $row['id']]);
    $check('new key untouched', $m->getQuickLinks((int)$row['id'], (int)$row['comp_id']) === ['audit', 'dashboard']);
    $cat = UserPreferenceModel::quickLinkCatalog((int)$row['comp_id'], (int)$row['id'], true);
    $byKey = array_column($cat, 'url', 'key');
    $check('annual summary quick link points at /reports?tab=annual', ($byKey['reports.annual_summary'] ?? '') === '/reports?tab=annual');
    $check('retired audit keys are not in the catalog', !isset($byKey['reports.run_audit']) && !isset($byKey['audit_log']) && isset($byKey['audit']));
} finally {
    $pdo->rollBack();
}
echo "Passed: {$passes}, Failed: {$fails}
";
exit($fails ? 1 : 0);
