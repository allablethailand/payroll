<?php
/**
 * Explicit request: "OT Rate เพิ่มให้สามารถ Assing รายบุคคลได้ด้วย เช่นคนนี้ Rate ไม่เหมือนเพื่อน ได้ทั้งตัวคูณ
 * และเป็นจำนวนเงิน เช่น ชั่วโมงละ 100 หรือ Base จากฐานเงินเงิน...ให้ไป Set แยก ใน Employee ใน Tab ที่มีการติ๊กว่า
 * ได้รับ OT ไหม ถ้ามีสิทธิ์ได้รับ OT ให้เลือกเพิ่มว่า จากการตั้งค่าหลัก หรือจะตั้งค่าแยก ตามประเภท OT...ถ้าติ๊กว่าไม่
 * คำนวณ OT ต่อให้ส่งมาจาก Origami ก็จะไม่คำนวณ แต่ในหน้าทำรอบจะต้องมีหมายเหตุบอก".
 *
 * Covers both EmployeeOtRateModel (save()/getForEmployee() CRUD+validation) AND the real end-to-end
 * calculation pipeline through PayrollRunModel::recalculate() -> SyncPayResolver::resolve() -- 5
 * employees exercising every combination: default-source, custom-multiplier-override,
 * custom-flat-amount-override, custom-source-but-a-scope-with-no-override-row (falls back to the
 * company default for THAT scope only), and ot_eligible=0 (OT hours present but not calculated, with
 * the new advisory calc_errors note).
 *
 * Real risk found and confirmed with the user before this feature shipped (not guessed): all 28 real
 * employees in this company's live DB sat at ot_eligible=0 (the column's own DB default) because the
 * flag was never actually enforced before this change -- wiring it as a hard gate without a backfill
 * first would have silently stopped OT pay for everyone. User confirmed via AskUserQuestion:
 * backfill every EXISTING employee to ot_eligible=1 first (2026-08-30_23_ot_eligible_backfill_before_enforcement.sql,
 * already applied to the dev DB) -- this test's OWN fixture employees set ot_eligible explicitly per
 * row regardless, so that backfill doesn't affect this file's own assertions.
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back.
 * Run with: php tests/employee_ot_rate_override_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/EmployeeOtRateModel.php';
require_once __DIR__ . '/../app/models/OtRateSetModel.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';

$pdo = Database::getInstance()->pdo;
$pdo->beginTransaction();

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
function checkFalse(string $label, bool $actual): void { check($label, $actual, false); }

try {
    $compId = 1;
    $adminUserId = 1;

    // Same shared-dev-DB isolation precautions tests/payroll_run_test.php already documents.
    $pdo->prepare("UPDATE `employees` SET deleted_at = NOW() WHERE comp_id = :comp_id AND deleted_at IS NULL")->execute([':comp_id' => $compId]);
    $pdo->prepare("UPDATE `approval_workflows` w
        JOIN `approval_workflow_document_types` awdt ON awdt.workflow_id = w.id
        SET w.status = 'inactive'
        WHERE w.comp_id = :comp_id AND awdt.document_type_code = 'PAYROLL_RUN_APPROVAL' AND w.status = 'active'")
        ->execute([':comp_id' => $compId]);
    $pdo->prepare("UPDATE `ot_rate_sets` SET deleted_at = NOW(), status = 'deleted' WHERE comp_id = :comp_id AND deleted_at IS NULL")->execute([':comp_id' => $compId]);

    $otRateModel = new EmployeeOtRateModel($pdo);
    $otRateSetModel = new OtRateSetModel($pdo);
    $weekdayScopeId = (int)$pdo->query("SELECT id FROM master_ot_scope_types WHERE code = 'weekday'")->fetchColumn();
    $weekendScopeId = (int)$pdo->query("SELECT id FROM master_ot_scope_types WHERE code = 'weekend'")->fetchColumn();
    checkTrue('fixture: weekday scope resolved', $weekdayScopeId > 0);
    checkTrue('fixture: weekend scope resolved', $weekendScopeId > 0);

    $makeEmployee = function (string $suffix, float $baseSalary, bool $otEligible, string $otRateSource) use ($pdo, $compId) {
        $pdo->prepare("INSERT INTO `employees`
            (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
             personal_email, mobile_no, address_line_1_register, address_line_1_contact,
             emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
             employment_date, employment_status, employment_type, workforce_type, record_time_method,
             payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
             sso_enrolled, pvd_enrolled, tax_exempt, ot_eligible, ot_rate_source)
            VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', :surname_th, 'Test', :surname_en, '1990-01-01', 'Thai',
             :email, '0800000000', 'Test Address', 'Test Address', 'Emergency', 'Contact', 'friend', '0899999999',
             '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
             'bank', 'monthly', :base_salary, '2020-01-01', 'average', 'active', 1, 1, 0, :ot_eligible, :ot_rate_source)")
            ->execute([
                ':comp_id' => $compId, ':employee_no' => 'OTOVR_EMP_' . $suffix . '_' . uniqid(),
                ':surname_th' => $suffix, ':surname_en' => $suffix, ':email' => uniqid() . '@test.local',
                ':base_salary' => $baseSalary, ':ot_eligible' => $otEligible ? 1 : 0, ':ot_rate_source' => $otRateSource,
            ]);
        return (int)$pdo->lastInsertId();
    };

    echo "=== EmployeeOtRateModel::getForEmployee()/save() -- model-level CRUD + validation ===\n";
    $modelEmpId = $makeEmployee('MODELTEST', 30000.0, true, 'default');
    $before = $otRateModel->getForEmployee($modelEmpId, $compId);
    checkTrue('getForEmployee() succeeds for a fresh employee', $before['status']);
    check('default ot_rate_source is "default"', $before['ot_rate_source'], 'default');
    check('3 scopes returned (weekday/weekend/holiday)', count($before['scopes']), 3);
    checkTrue('every scope starts with has_override=false', !in_array(true, array_column($before['scopes'], 'has_override'), true));

    $badScope = $otRateModel->save($modelEmpId, $compId, 'custom', [['ot_scope_id' => 999999, 'calculation_method' => 'multiplier', 'multiplier_rate' => 2.0]], $adminUserId);
    checkFalse('save() rejects an unknown ot_scope_id', $badScope['status']);

    $badFlat = $otRateModel->save($modelEmpId, $compId, 'custom', [['ot_scope_id' => $weekdayScopeId, 'calculation_method' => 'flat_amount', 'flat_amount_rate' => 0]], $adminUserId);
    checkFalse('save() rejects flat_amount_rate <= 0', $badFlat['status']);

    $goodSave = $otRateModel->save($modelEmpId, $compId, 'custom', [
        ['ot_scope_id' => $weekdayScopeId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 2.5],
    ], $adminUserId);
    checkTrue('save() with a valid custom override succeeds' . (empty($goodSave['status']) ? " ({$goodSave['message']})" : ''), $goodSave['status']);
    $afterSave = $otRateModel->getForEmployee($modelEmpId, $compId);
    check('ot_rate_source round-trips as custom', $afterSave['ot_rate_source'], 'custom');
    $weekdayRow = current(array_filter($afterSave['scopes'], fn($s) => $s['ot_scope_id'] === $weekdayScopeId));
    checkTrue('weekday scope now has_override=true', $weekdayRow['has_override']);
    check('weekday multiplier_rate round-trips as 2.5', $weekdayRow['multiplier_rate'], 2.5);
    $weekendRow = current(array_filter($afterSave['scopes'], fn($s) => $s['ot_scope_id'] === $weekendScopeId));
    checkFalse('weekend scope (never overridden) still has_override=false', $weekendRow['has_override']);

    $switchBack = $otRateModel->save($modelEmpId, $compId, 'default', [], $adminUserId);
    checkTrue('switching back to default succeeds', $switchBack['status']);
    $afterSwitchBack = $otRateModel->getForEmployee($modelEmpId, $compId);
    check('ot_rate_source round-trips as default again', $afterSwitchBack['ot_rate_source'], 'default');
    checkTrue('switching to default DELETES the override rows (not just ignores them)',
        !in_array(true, array_column($afterSwitchBack['scopes'], 'has_override'), true));
    check('employee_ot_rate_overrides has 0 rows for this employee after switching back', (int)$pdo->query("SELECT COUNT(*) FROM employee_ot_rate_overrides WHERE employee_id = {$modelEmpId}")->fetchColumn(), 0);

    echo "\n=== EmployeeOtRateModel::save() -- explicit assigned_ot_rate_set_id (2026-08-31, \"ถ้าเลือกจาก\n    OT ของระบบ จะมีให้เลือกเพิ่มว่า OT ไหน\") ===\n";
    $bogusSetSave = $otRateModel->save($modelEmpId, $compId, 'default', [], $adminUserId, 999999);
    checkFalse('save() rejects an assigned_ot_rate_set_id that does not exist', $bogusSetSave['status']);

    $pickerSet1 = $otRateSetModel->save([
        'name_th' => 'ชุดที่ 1 OTOVR', 'name_en' => 'OTOVR Picker Set 1', 'is_default' => true,
        'items' => [['ot_scope_id' => $weekdayScopeId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 1.25, 'calculation_base' => 'hourly']],
    ], $compId, $adminUserId);
    checkTrue('fixture: picker set 1 (default) created' . (empty($pickerSet1['status']) ? " ({$pickerSet1['message']})" : ''), $pickerSet1['status']);
    $pickerSet2 = $otRateSetModel->save([
        'name_th' => 'ชุดที่ 2 OTOVR', 'name_en' => 'OTOVR Picker Set 2', 'is_default' => false,
        'items' => [['ot_scope_id' => $weekdayScopeId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 4.0, 'calculation_base' => 'hourly']],
    ], $compId, $adminUserId);
    checkTrue('fixture: picker set 2 (non-default) created' . (empty($pickerSet2['status']) ? " ({$pickerSet2['message']})" : ''), $pickerSet2['status']);

    $noPick = $otRateModel->getForEmployee($modelEmpId, $compId);
    check('with no explicit pick, resolved_ot_rate_set_id falls back to the mandatory Default (set 1)', $noPick['resolved_ot_rate_set_id'], (int)$pickerSet1['id']);
    check('recommended_ot_rate_set_id agrees (nothing assigned to this employee specifically)', $noPick['recommended_ot_rate_set_id'], (int)$pickerSet1['id']);
    check('assigned_ot_rate_set_id is null before any explicit pick', $noPick['assigned_ot_rate_set_id'], null);

    $explicitPick = $otRateModel->save($modelEmpId, $compId, 'default', [], $adminUserId, (int)$pickerSet2['id']);
    checkTrue('save() with a valid assigned_ot_rate_set_id (set 2, the non-default) succeeds' . (empty($explicitPick['status']) ? " ({$explicitPick['message']})" : ''), $explicitPick['status']);
    $afterPick = $otRateModel->getForEmployee($modelEmpId, $compId);
    check('assigned_ot_rate_set_id round-trips as set 2', $afterPick['assigned_ot_rate_set_id'], (int)$pickerSet2['id']);
    check('resolved_ot_rate_set_id now follows the explicit pick (set 2), NOT the mandatory Default', $afterPick['resolved_ot_rate_set_id'], (int)$pickerSet2['id']);
    check('recommended_ot_rate_set_id still shows what WOULD auto-resolve (set 1) even with an explicit pick active', $afterPick['recommended_ot_rate_set_id'], (int)$pickerSet1['id']);
    checkTrue('assigned_ot_rate_set_name carries the real set 2 name', ($afterPick['assigned_ot_rate_set_name']['name_en'] ?? null) === 'OTOVR Picker Set 2');

    $clearPick = $otRateModel->save($modelEmpId, $compId, 'default', [], $adminUserId, null);
    checkTrue('save() with assigned_ot_rate_set_id=null clears a previous explicit pick', $clearPick['status']);
    $afterClear = $otRateModel->getForEmployee($modelEmpId, $compId);
    check('assigned_ot_rate_set_id is null again after clearing', $afterClear['assigned_ot_rate_set_id'], null);
    check('resolved_ot_rate_set_id falls back to the Default (set 1) again', $afterClear['resolved_ot_rate_set_id'], (int)$pickerSet1['id']);

    echo "\n=== Real end-to-end pipeline: company-wide OT rate config ===\n";
    $defaultSetSave = $otRateSetModel->save([
        'name_th' => 'ชุดมาตรฐาน OTOVR', 'name_en' => 'OTOVR Standard Set', 'is_default' => true,
        'items' => [
            ['ot_scope_id' => $weekdayScopeId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 1.50, 'calculation_base' => 'hourly'],
        ],
    ], $compId, $adminUserId);
    checkTrue('fixture: default OT Rate Set save() succeeds' . (empty($defaultSetSave['status']) ? " ({$defaultSetSave['message']})" : ''), $defaultSetSave['status']);

    $baseSalary = 30000.0;
    $hourlyRate = ($baseSalary / 30.0) / 8.0;
    $otHours = 2.0;

    $empDefault = $makeEmployee('DEFAULT', $baseSalary, true, 'default');
    $empMultiplier = $makeEmployee('MULTIPLIER', $baseSalary, true, 'custom');
    $empFlat = $makeEmployee('FLAT', $baseSalary, true, 'custom');
    $empPartial = $makeEmployee('PARTIAL', $baseSalary, true, 'custom'); // custom source, but no override row for weekday -- must fall back
    $empIneligible = $makeEmployee('INELIGIBLE', $baseSalary, false, 'default');

    // Custom multiplier for empMultiplier: 3.0x instead of the company default 1.5x.
    $saveMultiplier = $otRateModel->save($empMultiplier, $compId, 'custom', [
        ['ot_scope_id' => $weekdayScopeId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 3.0],
    ], $adminUserId);
    checkTrue('fixture: multiplier override saved' . (empty($saveMultiplier['status']) ? " ({$saveMultiplier['message']})" : ''), $saveMultiplier['status']);

    // Custom flat amount for empFlat: 100.00/hour flat, ignoring base salary entirely.
    $saveFlat = $otRateModel->save($empFlat, $compId, 'custom', [
        ['ot_scope_id' => $weekdayScopeId, 'calculation_method' => 'flat_amount', 'flat_amount_rate' => 100.0],
    ], $adminUserId);
    checkTrue('fixture: flat_amount override saved' . (empty($saveFlat['status']) ? " ({$saveFlat['message']})" : ''), $saveFlat['status']);

    // empPartial: custom source, but override only exists for WEEKEND -- weekday hours must still
    // fall back to the company default (1.5x), proving per-scope (not all-or-nothing) fallback.
    $savePartial = $otRateModel->save($empPartial, $compId, 'custom', [
        ['ot_scope_id' => $weekendScopeId, 'calculation_method' => 'multiplier', 'multiplier_rate' => 9.0],
    ], $adminUserId);
    checkTrue('fixture: partial (weekend-only) override saved' . (empty($savePartial['status']) ? " ({$savePartial['message']})" : ''), $savePartial['status']);

    $cycleModel = new PayrollCycleModel();
    $cycleRes = $cycleModel->save($compId, [
        'cycle_name' => 'OTOVR_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5,
        'ot_cutoff_type' => 'same_as_attendance', 'bank_file_format_id' => 1, 'status' => 'active',
    ], $adminUserId);
    checkTrue('fixture: cycle created', $cycleRes['status']);
    $cycleId = $cycleRes['id'];

    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of +106 months')->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of +106 months')->format('Y-m-d');

    // ot_req_working_day_hrs -> the 'weekday' scope column (see SyncPayResolver::OT_SCOPE_COLUMNS).
    // ot_rate_id now targets ot_rate_set_items (2026-08-30 -- TransactionDataPayAdapter only ever
    // reads that row's own ot_scope_id, never its rate value, see OvertimeRecordModel's own docblock).
    foreach ([$empDefault, $empMultiplier, $empFlat, $empPartial, $empIneligible] as $eid) {
        $pdo->prepare("INSERT INTO overtime_records (comp_id, employee_id, ot_date, ot_rate_id, hours, status, data_source)
            SELECT :comp_id, :employee_id, :ot_date, i.id, :hours, 'approved', 'manual'
            FROM ot_rate_set_items i JOIN ot_rate_sets s ON s.id = i.set_id
            WHERE s.comp_id = :comp_id2 AND i.ot_scope_id = :scope_id AND s.deleted_at IS NULL LIMIT 1")
            ->execute([':comp_id' => $compId, ':employee_id' => $eid, ':ot_date' => $periodStart, ':hours' => $otHours, ':comp_id2' => $compId, ':scope_id' => $weekdayScopeId]);
    }

    $runModel = new PayrollRunModel($pdo);
    $runRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'OTOVR_RUN_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $periodEnd,
    ], $adminUserId, true);
    checkTrue('fixture: run created', $runRes['status']);
    $runId = $runRes['id'];
    $runModel->recalculate($runId, $compId, $adminUserId, true);
    $details = $runModel->getDetails($runId, $compId);
    $detailFor = function (int $employeeId) use ($details) {
        foreach ($details as $d) { if ((int)$d['employee_id'] === $employeeId) { return $d; } }
        return null;
    };

    $expectedDefault = round($hourlyRate * 1.50 * $otHours, 2);
    $defaultDetail = $detailFor($empDefault);
    $defaultOt = current(array_filter($defaultDetail['earning_breakdown'] ?? [], fn($l) => $l['code'] === 'OT'));
    check('default-source employee: OT uses the company-wide 1.5x multiplier', (float)($defaultOt['amount'] ?? null), $expectedDefault);

    $expectedMultiplier = round($hourlyRate * 3.0 * $otHours, 2);
    $multiplierDetail = $detailFor($empMultiplier);
    $multiplierOt = current(array_filter($multiplierDetail['earning_breakdown'] ?? [], fn($l) => $l['code'] === 'OT'));
    check('custom-multiplier employee: OT uses THEIR OWN 3.0x, not the company default', (float)($multiplierOt['amount'] ?? null), $expectedMultiplier);
    checkTrue('custom-multiplier amount genuinely differs from the default (proves the override actually took effect)', (float)($multiplierOt['amount'] ?? null) !== $expectedDefault);

    $expectedFlat = round(100.0 * $otHours, 2);
    $flatDetail = $detailFor($empFlat);
    $flatOt = current(array_filter($flatDetail['earning_breakdown'] ?? [], fn($l) => $l['code'] === 'OT'));
    check('custom-flat-amount employee: OT = 100.00/hour * 2 hours = 200.00, ignoring base salary entirely', (float)($flatOt['amount'] ?? null), $expectedFlat);

    $partialDetail = $detailFor($empPartial);
    $partialOt = current(array_filter($partialDetail['earning_breakdown'] ?? [], fn($l) => $l['code'] === 'OT'));
    check('custom-source employee with NO override for the scope actually used (weekday) falls back to the company default (1.5x), not the weekend override', (float)($partialOt['amount'] ?? null), $expectedDefault);

    $ineligibleDetail = $detailFor($empIneligible);
    checkFalse('ot_eligible=0 employee: NO OT earning line at all, even though real OT hours exist', current(array_filter($ineligibleDetail['earning_breakdown'] ?? [], fn($l) => $l['code'] === 'OT')) !== false);
    check('ot_eligible=0 employee: gross_amount excludes OT entirely (base salary only)', (float)$ineligibleDetail['gross_amount'], $baseSalary);
    check('ot_eligible=0 employee: calc_status still "calculated" (advisory only, never blocks)', $ineligibleDetail['calc_status'], 'calculated');
    checkTrue('ot_eligible=0 employee: calc_errors carries the new advisory ot_not_calculated_ineligible code', strpos((string)($ineligibleDetail['calc_errors'] ?? ''), 'ot_not_calculated_ineligible') !== false);

} catch (Throwable $e) {
    $failures++;
    echo "  FAIL  Uncaught exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
} finally {
    $pdo->rollBack();
}

echo "\n--------------------------------------------------\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
if ($failures > 0) {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED (transaction rolled back, no data persisted)\n";
