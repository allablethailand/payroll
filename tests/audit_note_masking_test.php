<?php
/**
 * 2026-09-17: `api/payroll-run.get` masked every key on its response except one -- `audit_log`.
 * Several PayrollRunModel actions write the amount straight into that free-text `note`
 * (addManualLine/updateManualLine/removeManualLine/lineOverrideSave/lineOverrideRemove), so a
 * reader with no salary_amount.view_payroll_process grant could read, off the Action History tab,
 * the very figures the Detail table beside it had just replaced with 'XXXX'.
 *
 * What this locks:
 *  1. the endpoint runs audit_log through maskAuditNote(), beside the maskers its siblings use;
 *  2. maskAuditNote() is ONE function with ONE call site -- the whole point of doing it this way
 *     instead of a pattern repeated per action, and the thing that has to stay true;
 *  3. the rule, both ways: 'full' visibility gets the notes back byte-identical; anything less gets
 *     every number_format()-shaped figure in a listed action's note replaced with the mask, and
 *     NOTHING else touched (employee no, item code/name, the user's own words, who/when, IP/UA);
 *  4. a real dev-DB note of each listed action survives the mask with no figure left in it.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why.
 * Run with: php tests/audit_note_masking_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/core/Controller.php';
foreach (glob(__DIR__ . '/../app/services/*.php') as $serviceFile) {
    require_once $serviceFile;
}
foreach (glob(__DIR__ . '/../app/models/*.php') as $modelFile) {
    require_once $modelFile;
}
require_once __DIR__ . '/../app/controllers/PayrollController.php';

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    if ($actual === $expected) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }

/** Stands in for the real permission lookup so both branches are testable without a role/session. */
class FakeVisibilityPermissionModel extends PermissionModel {
    private array $result;
    public function __construct(?PDO $pdo = null, array $result = []) { $this->result = $result; }
    public function resolveSalaryVisibility(int $employeeId, string $module, bool $isAdmin, int $compId, ?int $subjectEmployeeId = null): array {
        return $this->result;
    }
}
function makeController(array $visibility): PayrollController {
    $controller = (new ReflectionClass('PayrollController'))->newInstanceWithoutConstructor();
    $prop = new ReflectionProperty('PayrollController', 'permissionModel');
    $prop->setAccessible(true);
    $prop->setValue($controller, new FakeVisibilityPermissionModel(null, $visibility));
    return $controller;
}
function maskWith(PayrollController $controller, array $log): array {
    $method = new ReflectionMethod('PayrollController', 'maskAuditNote');
    $method->setAccessible(true);
    return $method->invoke($controller, $log, 1);
}
$_SESSION['user'] = ['employee_id' => 99, 'company_id' => 1, 'role' => 'user'];
const VIS_FULL = ['full' => true, 'masked' => false, 'in_scope' => true, 'summary_only' => false];
const VIS_NONE = ['full' => false, 'masked' => true, 'in_scope' => false, 'summary_only' => false];
const VIS_SUMMARY = ['full' => false, 'masked' => false, 'in_scope' => true, 'summary_only' => true];

echo "=== 1. the endpoint is wired, once ===\n";
$controllerSrc = file_get_contents(__DIR__ . '/../app/controllers/PayrollController.php');
checkTrue('payroll-run.get runs audit_log through maskAuditNote()',
    strpos($controllerSrc, "\$row['audit_log'] = \$this->maskAuditNote(\$row['audit_log'], (int)\$compId);") !== false);
checkTrue('it sits with the other maskers, after the model has filled the response',
    strpos($controllerSrc, "maskRunDetailRows(\$row['details']") < strpos($controllerSrc, "maskAuditNote(\$row['audit_log']"));
// One function, one call site: 1 definition + 1 call. A third occurrence means the rule started
// spreading, which is exactly what doing it in one place was meant to prevent.
check('maskAuditNote appears exactly twice in the file (definition + its single call)',
    substr_count($controllerSrc, 'maskAuditNote'), 2);
checkTrue('the pattern lives on the class, not inline at a call site',
    strpos($controllerSrc, 'AUDIT_NOTE_MONEY_PATTERN') !== false);
checkTrue('and it says in the file that it is temporary until the figures become real columns',
    stripos($controllerSrc, 'TEMPORARY by design') !== false);

echo "\n=== 2. the full-visibility case: nothing is touched ===\n";
$log = [
    ['id' => 1, 'action' => 'add_manual_line', 'note' => 'Employee E001: added "BONUS" amount 12,500.00', 'performed_by' => 7, 'ip_address' => '10.0.0.5'],
    ['id' => 2, 'action' => 'line_override_save', 'note' => 'Employee E001: TH_SSO changed from 750.00 -> override amount 500.00'],
    ['id' => 3, 'action' => 'approve', 'note' => 'ok'],
];
$maskedFull = maskWith(makeController(VIS_FULL), $log);
check('a full reader gets the audit log back byte-identical', $maskedFull, $log);

echo "\n=== 3. the masked case: figures go, everything else stays ===\n";
$masked = maskWith(makeController(VIS_NONE), $log);
check('the added amount is masked', $masked[0]['note'], 'Employee E001: added "BONUS" amount XXXX');
check('both sides of a before -> after note are masked',
    $masked[1]['note'], 'Employee E001: TH_SSO changed from XXXX -> override amount XXXX');
check('an action that carries no figure is left alone', $masked[2]['note'], 'ok');
check('the employee number is not a salary figure and survives', strpos($masked[0]['note'], 'E001') !== false, true);
check('the item code survives', strpos($masked[1]['note'], 'TH_SSO') !== false, true);
check('who did it survives', $masked[0]['performed_by'], 7);
check('the IP survives', $masked[0]['ip_address'], '10.0.0.5');
check('the row count is unchanged -- masking hides figures, it never drops entries', count($masked), 3);
check('summary_only is masked too (it only ever means totals, never a line figure)',
    maskWith(makeController(VIS_SUMMARY), $log)[0]['note'], 'Employee E001: added "BONUS" amount XXXX');

echo "\n=== 4. the edges of the shape it matches ===\n";
$edges = maskWith(makeController(VIS_NONE), [
    ['action' => 'remove_manual_line', 'note' => null],
    ['action' => 'update_manual_line', 'note' => 'Employee E002: updated "ค่าปรับ" amount 0.00 (note: งวด 1/2 หัก 1,500.00)'],
    ['action' => 'update_manual_line', 'note' => 'Employee E003: updated "OT" amount -200.50'],
    ['action' => 'add_manual_line', 'note' => 'Employee E004: added "REF 2026.09 batch 12" amount 1,234,567.89'],
    ['action' => 'attendance_override_save', 'note' => 'Employee E005: late_mins=30, absent_days=1'],
]);
check('a null note stays null', $edges[0]['note'], null);
check('a zero figure is masked (0.00 is a figure, not "no value") and so is one the user typed',
    $edges[1]['note'], 'Employee E002: updated "ค่าปรับ" amount XXXX (note: งวด 1/2 หัก XXXX)');
check('a negative figure is masked with its sign', $edges[2]['note'], 'Employee E003: updated "OT" amount XXXX');
check('millions are masked, and a version-looking token beside them is not',
    $edges[3]['note'], 'Employee E004: added "REF 2026.09 batch 12" amount XXXX');
check('an action outside the list is untouched even when it holds numbers',
    $edges[4]['note'], 'Employee E005: late_mins=30, absent_days=1');

echo "\n=== 5. against the notes this dev DB really holds ===\n";
$pdo = Database::getInstance()->pdo;
$actions = (new ReflectionClassConstant('PayrollController', 'AUDIT_ACTIONS_WITH_MONEY_IN_NOTE'))->getValue();
$pattern = (new ReflectionClassConstant('PayrollController', 'AUDIT_NOTE_MONEY_PATTERN'))->getValue();
$in = implode(',', array_fill(0, count($actions), '?'));
$stmt = $pdo->prepare("SELECT id, action, note FROM `payroll_run_audit_logs`
    WHERE action IN ({$in}) AND note IS NOT NULL ORDER BY id DESC LIMIT 200");
$stmt->execute($actions);
$realRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$realRows) {
    echo "  SKIP  this dev DB has no audit note for any of the listed actions\n";
} else {
    checkTrue('the dev DB really does hold such notes', count($realRows) > 0);
    $withFigure = array_values(array_filter($realRows, fn(array $r): bool => preg_match($pattern, (string)$r['note']) === 1));
    checkTrue('at least one of them really does carry a figure (this is the leak being closed)', count($withFigure) > 0);
    $realMasked = maskWith(makeController(VIS_NONE), $realRows);
    $leaked = array_values(array_filter($realMasked, fn(array $r): bool => preg_match($pattern, (string)$r['note']) === 1));
    check('no figure survives the mask on any real note', $leaked, []);
    check('and a full reader still gets every one of them unchanged',
        maskWith(makeController(VIS_FULL), $realRows), $realRows);
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
