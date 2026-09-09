<?php
/**
 * Backlog Phase 9, T048 -- end-to-end verification that EVERY tax-statutory setting introduced by
 * T044-T047 actually flows into a REAL payroll run via `PayrollRunModel::recalculate()`, not just
 * `StatutoryCalculationEngine::calculateItem()` directly the way tests/statutory_master_clone_test.php
 * and tests/statutory_master_data_test.php already verify at the model/engine layer. This file adds
 * NO new backend logic -- see PayrollRunModel::recalculate()'s own statutory block (`$this->engine->
 * calculate($compId, $salaryContext, $paymentDate, $employeeFlags)`), which reads exclusively through
 * `CompanyStatutorySettingModel::list()`. Because that is the ONLY read path (confirmed via grep: this
 * model has zero direct SQL against statutory_items/company_statutory_settings), everything T045-T047
 * built into list()/save() automatically reaches a real run with zero additional "wiring" code -- this
 * test exists to PROVE that claim end-to-end rather than take it on faith.
 *
 * Covers, all through real PayrollRunModel::create()+recalculate()+getDetails() calls:
 *   1. A company's own CUSTOM item (T045), including a category CREATED AT RUNTIME (T047, no code
 *      deploy) as its classification, computes correctly in a real run's statutory_breakdown.
 *   2. A custom item using a calc_base CREATED AT RUNTIME that does NOT match any real
 *      $salaryContext key still runs (doesn't error/break the run) and correctly surfaces the
 *      T047 `unrecognized_calc_base` safety-net note all the way through to the PERSISTED
 *      payroll_run_details row, not just the in-memory engine return.
 *   3. TaxStatutoryModel::promoteToMaster() (T045): after promoting the item from #1, a SECOND,
 *      entirely independent company (never configured this item itself) sees and correctly
 *      calculates it in ITS OWN real run purely because the item is now comp_id IS NULL.
 *   4. CompanyStatutorySettingModel::promoteOverrideToMaster() (T045): a company's own rate
 *      OVERRIDE on the pre-existing master item TH_PVD is (a) correctly applied in that company's
 *      own real run BEFORE promotion, then (b) after promotion, a THIRD, independent fresh company
 *      defaults to the new promoted rate in its own real run with zero configuration of its own,
 *      and (c) the original company's own override is confirmed cleared (a later run for that same
 *      company also lands on the new master default, not a stale override).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back. Uses 3 fresh throwaway companies, never comp_id=1.
 * Run with: php tests/statutory_master_clone_payroll_run_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/TaxStatutoryModel.php';
require_once __DIR__ . '/../app/models/CompanyStatutorySettingModel.php';
require_once __DIR__ . '/../app/models/CompanyStatutoryRateVersionModel.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';

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

function makeCompany(PDO $pdo, string $countryCode = 'TH'): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory', 'active', :comp_code)");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid(), ':comp_code' => 'WIRE_' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function makeCycle(PayrollCycleModel $cycleModel, int $compId, int $userId): int {
    $res = $cycleModel->save($compId, [
        'cycle_name' => 'WIRE_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], $userId);
    if (empty($res['status'])) {
        throw new RuntimeException('Cannot continue without a cycle: ' . $res['message']);
    }
    return (int)$res['id'];
}

function makeEmployee(PDO $pdo, int $compId, int $cycleId, float $baseSalary, bool $ssoEnrolled, bool $pvdEnrolled, bool $taxExempt): int {
    $stmt = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, employee_type, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status,
         sso_enrolled, pvd_enrolled, tax_exempt, cycle_id)
        VALUES (:comp_id, :employee_no, 'domestic', 'mr', 'male', 'ทดสอบ', 'สาย', 'Test', 'Line', '1990-01-01', 'Thai',
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2018-01-01', 'permanent', 'full_time', 'office', 'manual',
         'monthly', :base_salary, '2018-01-01', 'average', 'active', :sso, :pvd, :tax_exempt, :cycle_id)");
    $stmt->execute([
        ':comp_id' => $compId, ':employee_no' => 'WIRE_' . uniqid(), ':email' => uniqid() . '@test.local',
        ':base_salary' => $baseSalary, ':sso' => $ssoEnrolled ? 1 : 0, ':pvd' => $pvdEnrolled ? 1 : 0,
        ':tax_exempt' => $taxExempt ? 1 : 0, ':cycle_id' => $cycleId,
    ]);
    return (int)$pdo->lastInsertId();
}

function runAndGetDetails(PayrollRunModel $runModel, int $compId, int $cycleId, int $userId, int $monthOffset): array {
    $today = new DateTime();
    $periodStart = (clone $today)->modify('first day of this month')->modify("+{$monthOffset} months")->format('Y-m-d');
    $periodEnd = (clone $today)->modify('last day of this month')->modify("+{$monthOffset} months")->format('Y-m-d');
    $res = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'WIRE_RUN_' . uniqid(),
        'period_start_date' => $periodStart, 'period_end_date' => $periodEnd, 'payment_date' => $periodEnd,
    ], $userId, true);
    if (empty($res['status'])) {
        throw new RuntimeException('Cannot continue without a created run: ' . $res['message']);
    }
    $recalc = $runModel->recalculate((int)$res['id'], $compId, $userId, true);
    if (empty($recalc['status'])) {
        throw new RuntimeException('recalculate() failed: ' . ($recalc['message'] ?? ''));
    }
    return $runModel->getDetails((int)$res['id'], $compId);
}

function breakdownLine(array $details, int $employeeId, string $itemCode): ?array {
    $row = current(array_filter($details, fn($d) => (int)$d['employee_id'] === $employeeId));
    if ($row === false) return null;
    $breakdown = is_string($row['statutory_breakdown']) ? json_decode($row['statutory_breakdown'], true) : $row['statutory_breakdown'];
    foreach ((array)$breakdown as $item) {
        if (($item['code'] ?? null) === $itemCode) return $item;
    }
    return null;
}

try {
    $userId = 1;
    $taxModel = new TaxStatutoryModel();
    $csModel = new CompanyStatutorySettingModel($pdo);
    $rateVersionModel = new CompanyStatutoryRateVersionModel($pdo);
    $cycleModel = new PayrollCycleModel($pdo);
    $runModel = new PayrollRunModel($pdo);

    $compA = makeCompany($pdo, 'TH');
    $cycleA = makeCycle($cycleModel, $compA, $userId);

    echo "=== Part 1+2: custom items (T045), incl. a category/calc_base created AT RUNTIME (T047), through a REAL run ===\n";

    // A brand-new category row, inserted at runtime -- proves T047's "no code deploy needed" claim
    // holds through the FULL payroll-run path, not just TaxStatutoryModel::save() validation.
    $newCatCode = 'wire_cat_' . substr(uniqid(), -6);
    $pdo->prepare("INSERT INTO master_statutory_categories (code, name_th, name_en, sort_order) VALUES (:c, 'หมวดทดสอบ', 'Wiring Test Category', 999)")
        ->execute([':c' => $newCatCode]);

    $codeA = 'WIRE_CUSTOM_A_' . strtoupper(substr(uniqid(), -6));
    $itemA = $taxModel->save([
        'country_code' => 'TH', 'code' => $codeA, 'name_th' => 'ทดสอบ A', 'name_en' => 'Wiring Custom A',
        'category' => $newCatCode, 'calc_method' => 'flat_rate', 'calc_base' => 'basic_salary',
        'is_employee_applicable' => true, 'is_employer_applicable' => true,
        'is_company_rate_editable' => false, 'default_is_active' => true,
    ], $userId, $compA);
    checkTrue('fixture: custom item A (runtime category, recognized calc_base) saves for compA' . (empty($itemA['message']) ? '' : " ({$itemA['message']})"), $itemA['status']);
    $itemAId = (int)($itemA['id'] ?? 0);
    $rateA = $taxModel->rateHistorySave(['statutory_item_id' => $itemAId, 'effective_date' => '2018-01-01', 'employee_rate' => '2.00', 'employer_rate' => '3.00'], $userId);
    checkTrue('fixture: rate history saved for custom item A', $rateA['status']);

    // A brand-new calc_base row, inserted at runtime, that does NOT match any real salaryContext
    // key -- proves the T047 safety-net note survives all the way through a REAL run into the
    // PERSISTED payroll_run_details.statutory_breakdown, not just the in-memory engine return.
    $newBaseCode = 'wire_base_' . substr(uniqid(), -6);
    $pdo->prepare("INSERT INTO master_statutory_calc_bases (code, name_th, name_en, sort_order) VALUES (:c, 'ฐานทดสอบ', 'Wiring Test Base', 999)")
        ->execute([':c' => $newBaseCode]);
    $codeB = 'WIRE_CUSTOM_B_' . strtoupper(substr(uniqid(), -6));
    $itemB = $taxModel->save([
        'country_code' => 'TH', 'code' => $codeB, 'name_th' => 'ทดสอบ B', 'name_en' => 'Wiring Custom B',
        'category' => 'tax', 'calc_method' => 'flat_rate', 'calc_base' => $newBaseCode,
        'is_employee_applicable' => true, 'is_employer_applicable' => true,
        'is_company_rate_editable' => false, 'default_is_active' => true,
    ], $userId, $compA);
    checkTrue('fixture: custom item B (runtime calc_base, unrecognized by the engine) saves for compA', $itemB['status']);
    $itemBId = (int)($itemB['id'] ?? 0);
    $rateB = $taxModel->rateHistorySave(['statutory_item_id' => $itemBId, 'effective_date' => '2018-01-01', 'employee_rate' => '5.00', 'employer_rate' => '5.00'], $userId);
    checkTrue('fixture: rate history saved for custom item B', $rateB['status']);

    $empA = makeEmployee($pdo, $compA, $cycleA, 50000.0, false, false, true);
    $detailsA0 = runAndGetDetails($runModel, $compA, $cycleA, $userId, 0);

    $lineA = breakdownLine($detailsA0, $empA, $codeA);
    checkTrue("real run: custom item A ({$codeA}) appears in statutory_breakdown", $lineA !== null);
    if ($lineA !== null) {
        check('custom item A employee_amount = 50000 * 2% = 1000.00 in a REAL run', (float)$lineA['employee_amount'], 1000.0);
        check('custom item A employer_amount = 50000 * 3% = 1500.00 in a REAL run', (float)$lineA['employer_amount'], 1500.0);
    }

    $lineB = breakdownLine($detailsA0, $empA, $codeB);
    checkTrue("real run: custom item B ({$codeB}, unrecognized calc_base) still appears in statutory_breakdown (never dropped/errored)", $lineB !== null);
    if ($lineB !== null) {
        check('custom item B base_amount = 0.0 (unrecognized calc_base), persisted correctly through a real run', (float)$lineB['base_amount'], 0.0);
        check('custom item B note = unrecognized_calc_base, SURVIVES all the way into persisted payroll_run_details', $lineB['note'] ?? null, 'unrecognized_calc_base');
    }

    echo "\n=== Part 3: promoteToMaster() (T045) -- a SECOND, independent company sees & calculates it in ITS OWN real run ===\n";
    $promote = $taxModel->promoteToMaster($itemAId, $compA, $userId);
    checkTrue('promoteToMaster() succeeds for custom item A' . (empty($promote['message']) ? '' : " ({$promote['message']})"), $promote['status']);

    $compB = makeCompany($pdo, 'TH');
    $cycleB = makeCycle($cycleModel, $compB, $userId);
    $empB = makeEmployee($pdo, $compB, $cycleB, 80000.0, false, false, true);
    $detailsB0 = runAndGetDetails($runModel, $compB, $cycleB, $userId, 0);
    $lineB_onCompB = breakdownLine($detailsB0, $empB, $codeA);
    checkTrue("real run: company B (never configured {$codeA} itself) sees it purely because it is now a MASTER item", $lineB_onCompB !== null);
    if ($lineB_onCompB !== null) {
        check('promoted item, calculated for a DIFFERENT company: employee_amount = 80000 * 2% = 1600.00', (float)$lineB_onCompB['employee_amount'], 1600.0);
        check('promoted item, calculated for a DIFFERENT company: employer_amount = 80000 * 3% = 2400.00', (float)$lineB_onCompB['employer_amount'], 2400.0);
    }
    $lineB_customBOnCompB = breakdownLine($detailsB0, $empB, $codeB);
    checkTrue("company B does NOT see company A's still-custom item B ({$codeB}) -- custom scoping intact", $lineB_customBOnCompB === null);

    // Sanity: company A itself, in a LATER period (still using the same item, now a master row),
    // still calculates correctly -- promotion must not disturb the original owner's own numbers.
    $detailsA1 = runAndGetDetails($runModel, $compA, $cycleA, $userId, 1);
    $lineA1 = breakdownLine($detailsA1, $empA, $codeA);
    checkTrue('company A, later period, post-promotion: item A still appears (now visible via the MASTER branch)', $lineA1 !== null);
    if ($lineA1 !== null) {
        check('company A, post-promotion: employee_amount unchanged at 1000.00', (float)$lineA1['employee_amount'], 1000.0);
    }

    echo "\n=== Part 4: CompanyStatutoryRateVersionModel::promoteToMaster() (Clone+Version redesign) -- a company's own version applies in a real run, then a THIRD company defaults to it ===\n";
    // 2026-09-08, Clone+Version redesign -- replaces the old flat-override
    // CompanyStatutorySettingModel::save()/promoteOverrideToMaster() flow. compA here is a FRESH
    // company created via a raw INSERT (makeCompany(), never through CompanyProfileModel::save()),
    // so it has no comp_id-scoped clone row yet for TH_PVD at all -- the version added below is its
    // first.
    $pvdRow = $csModel->get($compA, 3); // TH_PVD is seeded id=3 -- confirmed via direct query before writing this test.
    checkTrue('fixture: TH_PVD (id=3) resolves for compA via CompanyStatutorySettingModel::get()', $pvdRow !== null);
    $stmtPvdCode = $pdo->prepare("SELECT id, code FROM statutory_items WHERE code = 'TH_PVD' AND comp_id IS NULL AND deleted_at IS NULL");
    $stmtPvdCode->execute();
    $pvdItem = $stmtPvdCode->fetch(PDO::FETCH_ASSOC);
    checkTrue('fixture: TH_PVD master item found by code', $pvdItem !== false);
    $pvdItemId = (int)$pvdItem['id'];

    $overrideEmployeeRate = 4.44;
    $overrideEmployerRate = 4.44;
    // Backdated well before every payment date this test computes, open-ended -- same "a version's
    // effective_date must genuinely be in effect at the calc dates being tested" reasoning
    // tests/statutory_engine_test.php's own Scenario 3 comment explains.
    $versionSave = $rateVersionModel->save($compA, [
        'statutory_item_id' => $pvdItemId, 'effective_date' => '2020-01-01',
        'employee_rate' => $overrideEmployeeRate, 'employer_rate' => $overrideEmployerRate,
    ], $userId);
    checkTrue('fixture: compA adds its own TH_PVD rate version' . (empty($versionSave['message']) ? '' : " ({$versionSave['message']})"), $versionSave['status']);
    $pvdVersionId = $versionSave['id'];

    $empA_pvd = makeEmployee($pdo, $compA, $cycleA, 60000.0, false, true, true);
    $detailsA_pvdOverride = runAndGetDetails($runModel, $compA, $cycleA, $userId, 2);
    $linePvdOverride = breakdownLine($detailsA_pvdOverride, $empA_pvd, 'TH_PVD');
    checkTrue('real run: TH_PVD line appears for the pvd_enrolled employee', $linePvdOverride !== null);
    if ($linePvdOverride !== null) {
        check('real run applies compA\'s OWN override rate: employee_amount = 60000 * 4.44% = 2664.00', (float)$linePvdOverride['employee_amount'], 2664.0);
        check('real run applies compA\'s OWN override rate: employer_amount = 60000 * 4.44% = 2664.00', (float)$linePvdOverride['employer_amount'], 2664.0);
    }

    $promoteEffectiveDate = (new DateTime())->modify('first day of this month')->modify('+4 months')->format('Y-m-d');
    $promoteVersion = $rateVersionModel->promoteToMaster($compA, $pvdVersionId, $promoteEffectiveDate, $userId);
    checkTrue('promoteToMaster() succeeds' . (empty($promoteVersion['message']) ? '' : " ({$promoteVersion['message']})"), $promoteVersion['status']);

    $compC = makeCompany($pdo, 'TH');
    $cycleC = makeCycle($cycleModel, $compC, $userId);
    $empC_pvd = makeEmployee($pdo, $compC, $cycleC, 90000.0, false, true, true);
    $detailsC = runAndGetDetails($runModel, $compC, $cycleC, $userId, 4);
    $linePvdC = breakdownLine($detailsC, $empC_pvd, 'TH_PVD');
    checkTrue('real run: a THIRD, brand-new company (zero config of its own) gets a TH_PVD line', $linePvdC !== null);
    if ($linePvdC !== null) {
        check('brand-new company defaults to the PROMOTED rate with zero config: employee_amount = 90000 * 4.44% = 3996.00', (float)$linePvdC['employee_amount'], 3996.0);
        check('brand-new company defaults to the PROMOTED rate with zero config: employer_amount = 90000 * 4.44% = 3996.00', (float)$linePvdC['employer_amount'], 3996.0);
    }

    // 2026-09-08: unlike the OLD flat-override flow, promoteToMaster() deliberately does NOT clear
    // the company's own version afterward (see that method's own docblock) -- confirm it SURVIVES
    // instead, and that a later run for compA itself still resolves through its OWN kept version
    // (not merely "happens to match the new master by coincidence" -- it's the SAME row).
    $reloadedVersion = $rateVersionModel->get($compA, $pvdVersionId);
    checkTrue('compA\'s own version SURVIVES after promotion (not cleared, unlike the old flat-override flow)', $reloadedVersion !== null);
    check('surviving version still carries its own 4.44 rate', round((float)($reloadedVersion['employee_rate'] ?? 0), 2), 4.44);
    $detailsA_postPromotion = runAndGetDetails($runModel, $compA, $cycleA, $userId, 4);
    $linePvdA_post = breakdownLine($detailsA_postPromotion, $empA_pvd, 'TH_PVD');
    checkTrue('real run: compA itself, post-promotion, still gets a TH_PVD line', $linePvdA_post !== null);
    if ($linePvdA_post !== null) {
        check('compA, post-promotion: still resolves through its OWN version, same 4.44% -- employee_amount = 60000 * 4.44% = 2664.00', (float)$linePvdA_post['employee_amount'], 2664.0);
    }

    echo "\n" . ($failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)" : "SOME TESTS FAILED") . "\n";
    echo "{$passes} passed, {$failures} failed.\n";
} finally {
    $pdo->rollBack();
}
exit($failures === 0 ? 0 : 1);
