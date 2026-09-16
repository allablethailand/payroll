<?php
/**
 * 2026-09-16: `api/payroll-run.update-manual-line` -- editing one already-added ad-hoc earning/
 * deduction line in place, instead of the remove-then-add-again round trip that was the only way
 * to correct a typo'd amount before this.
 *
 * What this locks:
 *  1. an edit really lands: the row changes AND the employee's calculated slip changes with it
 *     (updateManualLine() recalculates, exactly as addManualLine() does);
 *  2. an edit is gated no more loosely than adding that same line would be -- a verified employee
 *     is refused, a non-draft run is refused, a bad amount is refused, and all of those refusals
 *     come from the SAME code addManualLine() runs, not a second copy of it;
 *  3. a line id that belongs to a different employee (or a different run) is refused outright --
 *     the run/verified gates above were evaluated for the employee in the request, so acting on
 *     someone else's row would apply them to the wrong person;
 *  4. every resolved column is written, including the ones the edit cleared (switching a line away
 *     from payee_type='other_person' must drop its destination_id, not keep a stale one);
 *  5. the response goes out through maskManualLines(), the same rule its sibling endpoints use, so
 *     a role without full salary visibility gets 'XXXX' here too.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back; uses its own fresh company/employees (same isolation
 * convention as tests/other_income_deduction_test.php), not the shared comp_id=1 dev data.
 * Run with: php tests/manual_line_update_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/models/PermissionModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

$failures = 0;
$passes = 0;
function check(string $label, $actual, $expected): void {
    global $failures, $passes;
    $ok = (is_float($expected) || is_float($actual)) ? abs((float)$actual - (float)$expected) < 0.005 : $actual === $expected;
    if ($ok) {
        $passes++;
        echo "  PASS  {$label}\n";
    } else {
        $failures++;
        echo "  FAIL  {$label} => got " . var_export($actual, true) . ", expected " . var_export($expected, true) . "\n";
    }
}
function checkTrue(string $label, bool $actual): void { check($label, $actual, true); }
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }

try {
    echo "=== 1. the endpoint is wired, and wired to the same gate as add ===\n";
    $routes = file_get_contents(__DIR__ . '/../index.php');
    checkTrue('route api/payroll-run.update-manual-line -> PayrollController@updateManualLine',
        strpos($routes, "\$router->post('api/payroll-run.update-manual-line', 'PayrollController@updateManualLine');") !== false);

    $controllerSrc = file_get_contents(__DIR__ . '/../app/controllers/PayrollController.php');
    $sliceBetween = function (string $from, string $to) use ($controllerSrc): string {
        $start = strpos($controllerSrc, $from);
        return $start === false ? '' : substr($controllerSrc, $start, strpos($controllerSrc, $to, $start) - $start);
    };
    $addBody = $sliceBetween('public function addManualLine()', 'public function updateManualLine()');
    $updateBody = $sliceBetween('public function updateManualLine()', 'private function manualLinePayload(');
    checkTrue('PayrollController::updateManualLine() exists', $updateBody !== '');
    check('it requires the same permission addManualLine() requires',
        strpos($updateBody, "requirePermission('payroll_run.add')") !== false,
        strpos($addBody, "requirePermission('payroll_run.add')") !== false);
    checkTrue('both endpoints read the wire payload through the one shared manualLinePayload()',
        strpos($addBody, '$this->manualLinePayload(') !== false && strpos($updateBody, '$this->manualLinePayload(') !== false);
    checkTrue('the response masks through maskManualLines()', strpos($updateBody, 'maskManualLines') !== false);
    checkTrue('it asks payroll_process salary visibility, same module as its siblings',
        strpos($updateBody, 'resolveSalaryVisibility') !== false && strpos($updateBody, "'payroll_process'") !== false);
    checkTrue('it gates on full (summary_only must not see line figures either)',
        strpos($updateBody, "\$visibility['full'] ? \$lines :") !== false);

    $modelSrc = file_get_contents(__DIR__ . '/../app/models/PayrollRunModel.php');
    // The whole point of this endpoint's shape: one validator, two writers. A second copy of the
    // checks is how "edit reaches a state add would have refused" comes back.
    check('resolveManualLineInput() is declared exactly once', substr_count($modelSrc, 'private function resolveManualLineInput('), 1);
    check('and it has exactly two callers -- addManualLine() and updateManualLine()',
        substr_count($modelSrc, '$this->resolveManualLineInput('), 2);
    check('assertManualLinesEditable() (draft + not-verified + membership) gates both writers too',
        substr_count($modelSrc, '$this->assertManualLinesEditable('), 2);

    echo "\n=== 2. fixture: a draft run with two employees, each with a manual line ===\n";
    $userId = 1;
    $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', :comp_code)")
        ->execute([':name' => 'Manual Line Update Test Co ' . uniqid(), ':comp_code' => 'MLU_' . uniqid()]);
    $compId = (int)$pdo->lastInsertId();

    $makeEmployee = function (string $tag) use ($pdo, $compId): int {
        $pdo->prepare("INSERT INTO `employees`
            (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
             personal_email, mobile_no, address_line_1_register, address_line_1_contact,
             emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
             employment_date, employment_status, employment_type, workforce_type, record_time_method,
             bank_id, bank_account_no, bank_account_name, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt)
            VALUES (:comp_id, :employee_no, 'mr', 'male', :name_th, :tag, :name_en, :tag, '1990-01-01', 'Thai',
             :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
             '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
             1, 'ENC-ACC-NO', 'Test Account', 'monthly', 30000, '2020-01-01', 'average', 'active', 0, 0, 1)")
            ->execute([':comp_id' => $compId, ':employee_no' => 'MLU_' . $tag . '_' . uniqid(), ':email' => uniqid() . '@test.local',
                ':name_th' => 'ทดสอบ' . $tag, ':name_en' => 'Test', ':tag' => $tag]);
        return (int)$pdo->lastInsertId();
    };
    $employeeAId = $makeEmployee('A');
    $employeeBId = $makeEmployee('B');

    $cycleSave = (new PayrollCycleModel($pdo))->save($compId, [
        'cycle_name' => 'MLU_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);

    $runModel = new PayrollRunModel($pdo);
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleSave['id'], 'run_name' => 'Manual Line Update Test Run',
        'period_start_date' => '2027-07-21', 'period_end_date' => '2027-08-20', 'payment_date' => '2027-07-25',
    ], $userId, true);
    if (empty($createRes['status'])) { throw new RuntimeException('create failed: ' . ($createRes['message'] ?? '')); }
    $runId = $createRes['id'];
    checkTrue('initial recalculate succeeds', !empty($runModel->recalculate($runId, $compId, $userId, true)['status']));

    $addA = $runModel->addManualLine($runId, $compId, $employeeAId, null, 500.00, $userId, true, 'พิมพ์ยอดผิด', 'เงินรางวัลพิเศษ', 'earning');
    checkTrue('addManualLine() for employee A succeeds' . (empty($addA['status']) ? " ({$addA['message']})" : ''), $addA['status']);
    $addB = $runModel->addManualLine($runId, $compId, $employeeBId, null, 700.00, $userId, true, null, 'เงินรางวัลพิเศษ B', 'earning');
    checkTrue('addManualLine() for employee B succeeds' . (empty($addB['status']) ? " ({$addB['message']})" : ''), $addB['status']);

    $lineAId = (int)$runModel->manualLinesForEmployee($compId, $runId, $employeeAId)[0]['id'];
    $lineBId = (int)$runModel->manualLinesForEmployee($compId, $runId, $employeeBId)[0]['id'];

    $netPayOf = function (int $employeeId) use ($runModel, $runId, $compId): float {
        $row = current(array_filter($runModel->getDetails($runId, $compId), fn($d) => (int)$d['employee_id'] === $employeeId));
        return (float)$row['net_amount'];
    };
    $manualLineOnSlip = function (int $employeeId, string $bucket) use ($runModel, $runId, $compId): float {
        $row = current(array_filter($runModel->getDetails($runId, $compId), fn($d) => (int)$d['employee_id'] === $employeeId));
        $lines = array_filter($row[$bucket], fn($l) => strpos((string)$l['code'], 'CUSTOM:เงินรางวัลพิเศษ') === 0);
        return (float)array_sum(array_column($lines, 'amount'));
    };
    check('employee A slip carries the added 500 before the edit', $manualLineOnSlip($employeeAId, 'earning_breakdown'), 500.0);
    $netABefore = $netPayOf($employeeAId);

    echo "\n=== 3. a successful edit changes the row AND the slip ===\n";
    $updateRes = $runModel->updateManualLine($runId, $compId, $lineAId, $employeeAId, null, 1200.00, $userId, true, 'แก้ยอดให้ถูก', 'เงินรางวัลพิเศษ', 'earning');
    checkTrue('updateManualLine() succeeds' . (empty($updateRes['status']) ? " ({$updateRes['message']})" : ''), $updateRes['status']);

    $linesA = $runModel->manualLinesForEmployee($compId, $runId, $employeeAId);
    check('it edits IN PLACE -- still exactly one line, not a second one added', count($linesA), 1);
    check('same line id (the row was UPDATEd, not replaced)', (int)$linesA[0]['id'], $lineAId);
    check('the amount is the new one', (float)$linesA[0]['amount'], 1200.0);
    check('the note is the new one', $linesA[0]['note'], 'แก้ยอดให้ถูก');
    check('the slip now carries the new amount', $manualLineOnSlip($employeeAId, 'earning_breakdown'), 1200.0);
    check('net pay moved by exactly the difference (the edit really recalculated)',
        round($netPayOf($employeeAId) - $netABefore, 2), 700.0);
    check('the OTHER employee line is untouched', (float)$runModel->manualLinesForEmployee($compId, $runId, $employeeBId)[0]['amount'], 700.0);

    $updateLog = array_values(array_filter($runModel->getAuditLog($runId, $compId), fn($a) => $a['action'] === 'update_manual_line'));
    check('the edit is audited as its own action', count($updateLog), 1);
    checkTrue('the audit note names the employee and the new amount',
        strpos($updateLog[0]['note'] ?? '', 'Employee') === 0 && strpos($updateLog[0]['note'] ?? '', '1,200.00') !== false);

    echo "\n=== 4. every resolved column is written, including the ones the edit cleared ===\n";
    $toOtherPerson = $runModel->updateManualLine($runId, $compId, $lineAId, $employeeAId, null, 1200.00, $userId, true, null, 'หักส่งบุคคลภายนอก', 'deduction', null, 'other_person', null,
        ['account_name' => 'Vendor Co.', 'account_no' => '9990001112', 'bank_id' => 1, 'is_saved' => false]);
    checkTrue('an edit may switch the line to payee_type=other_person' . (empty($toOtherPerson['status']) ? " ({$toOtherPerson['message']})" : ''), $toOtherPerson['status']);
    $afterOther = $runModel->manualLinesForEmployee($compId, $runId, $employeeAId)[0];
    check('item_type switched to deduction', $afterOther['item_type'], 'deduction');
    checkTrue('a destination was resolved and stored', $afterOther['destination_id'] !== null);

    $backToPlain = $runModel->updateManualLine($runId, $compId, $lineAId, $employeeAId, null, 1200.00, $userId, true, null, 'หักธรรมดา', 'deduction', null, 'not_disbursed');
    checkTrue('an edit may switch it back away from other_person' . (empty($backToPlain['status']) ? " ({$backToPlain['message']})" : ''), $backToPlain['status']);
    $afterPlain = $runModel->manualLinesForEmployee($compId, $runId, $employeeAId)[0];
    check('the stale destination_id is CLEARED, not left behind', $afterPlain['destination_id'], null);
    check('payee_type is the new one', $afterPlain['payee_type'], 'not_disbursed');
    check('include_in_cash_summary follows not_disbursed, same forced rule as add', (int)$afterPlain['include_in_cash_summary'], 0);

    echo "\n=== 5. refusals -- no looser than adding the same line ===\n";
    $wrongEmployee = $runModel->updateManualLine($runId, $compId, $lineBId, $employeeAId, null, 999.00, $userId, true, null, 'ของคนอื่น', 'earning');
    checkFalse('a line id belonging to ANOTHER employee is refused', (bool)$wrongEmployee['status']);
    check('...and refused as not-found, leaking nothing about the other employee row', $wrongEmployee['message'], 'Record not found.');
    check('...and that other line is genuinely untouched', (float)$runModel->manualLinesForEmployee($compId, $runId, $employeeBId)[0]['amount'], 700.0);

    $wrongRun = $runModel->updateManualLine($runId + 999999, $compId, $lineAId, $employeeAId, null, 999.00, $userId, true, null, 'ผิดรอบ', 'earning');
    checkFalse('a line id from a different run is refused', (bool)$wrongRun['status']);

    $badAmount = $runModel->updateManualLine($runId, $compId, $lineAId, $employeeAId, null, 0.00, $userId, true, null, 'ศูนย์', 'earning');
    checkFalse('amount 0 is refused, the same shared check add runs', (bool)$badAmount['status']);
    check('...with the shared validator own message', $badAmount['message'], 'Amount must be greater than 0.');
    $noItem = $runModel->updateManualLine($runId, $compId, $lineAId, $employeeAId, null, 50.00, $userId, true, null, '', 'earning');
    checkFalse('a blank custom item name is refused, the same shared check add runs', (bool)$noItem['status']);

    $verifyRes = $runModel->setEmployeeVerified($runId, $compId, $employeeAId, true, $userId, true);
    checkTrue('fixture: employee A is verified for this run' . (empty($verifyRes['status']) ? " ({$verifyRes['message']})" : ''), $verifyRes['status']);
    $onVerified = $runModel->updateManualLine($runId, $compId, $lineAId, $employeeAId, null, 4000.00, $userId, true, null, 'หลังยืนยัน', 'deduction');
    checkFalse('a VERIFIED employee line cannot be edited', (bool)$onVerified['status']);
    checkTrue('...and says so, same message add gives', strpos($onVerified['message'], 'verified for this run') !== false);
    check('...and the line really did not move', (float)$runModel->manualLinesForEmployee($compId, $runId, $employeeAId)[0]['amount'], 1200.0);
    $runModel->setEmployeeVerified($runId, $compId, $employeeAId, false, $userId, true);

    $runModel->submit($runId, $compId, $userId, true);
    $onSubmitted = $runModel->updateManualLine($runId, $compId, $lineAId, $employeeAId, null, 4000.00, $userId, true, null, 'หลังส่ง', 'deduction');
    checkFalse('a non-draft run cannot have a line edited', (bool)$onSubmitted['status']);
    checkTrue('...same draft-only message add gives', strpos($onSubmitted['message'], 'draft payroll run') !== false);

    echo "\n=== 6. the response is masked for a reader without full salary visibility ===\n";
    require_once __DIR__ . '/../app/core/Controller.php';
    require_once __DIR__ . '/../app/controllers/PayrollController.php';
    $masker = new ReflectionMethod('PayrollController', 'maskManualLines');
    $masker->setAccessible(true);
    $controller = (new ReflectionClass('PayrollController'))->newInstanceWithoutConstructor();
    $masked = $masker->invoke($controller, $runModel->manualLinesForEmployee($compId, $runId, $employeeAId));
    check('the edited amount comes back as XXXX', $masked[0]['amount'], PermissionModel::MASK_VALUE);
    check('the item label still reads (it is not a figure)', $masked[0]['item_name_th'], 'หักธรรมดา');
    check('the payee still reads (where the money goes is not the figure)', $masked[0]['payee_type'], 'not_disbursed');
    check('the line id still reads (it is what a further edit targets)', (int)$masked[0]['id'], $lineAId);

    echo "\n";
    echo $failures === 0 ? "ALL PASSED ({$passes} assertions)\n" : "{$failures} FAILED, {$passes} passed\n";
} catch (Throwable $e) {
    $failures++;
    echo "  FATAL  " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    $pdo->rollBack();
}
exit($failures === 0 ? 0 : 1);
