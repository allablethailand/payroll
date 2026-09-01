<?php
/**
 * Lightweight verification script for Origami's `scheduled_item_occurrences[]` proposal --
 * PayrollSyncModel::replaceScheduledItemOccurrences()/occurrencesForItem(). Not PHPUnit -- see
 * tests/statutory_engine_test.php for why. Runs against the real dev DB inside a transaction that
 * is always rolled back. Uses a fresh throwaway company, not comp_id=1.
 * Run with: php tests/payroll_sync_item_occurrences_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/PayrollSyncModel.php';
require_once __DIR__ . '/../app/models/PayrollCycleModel.php';
require_once __DIR__ . '/../app/models/PayrollRunModel.php';
require_once __DIR__ . '/../app/models/PayrollReportDataModel.php';
require_once __DIR__ . '/../app/services/PayslipTemplateRenderer.php';
require_once __DIR__ . '/../app/services/reports/ReportRegistry.php';
require_once __DIR__ . '/../app/services/reports/LocalizedException.php';

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

try {
    $compCode = 'PSIO_' . uniqid();
    $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name, setup_status, origami_payroll_comp_code)
        VALUES (:name, :name, 'TH', '1234567890123', 'Test Address', 'Tester', 'active', :comp_code)")
        ->execute([':name' => 'Occurrence Test Co ' . uniqid(), ':comp_code' => $compCode]);
    $compId = (int)$pdo->lastInsertId();

    $empNoMapped = 'PSIO_EMP_' . uniqid();
    $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         personal_email, mobile_no, address_line_1_register, address_line_1_contact,
         emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'PSIO', 'Test', 'PSIO', '1990-01-01', 'Thai',
         :email, '0812345678', 'A', 'A', 'E', 'E', 'friend', '0898888888',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', 30000, '2020-01-01', 'average', 'active')")
        ->execute([':comp_id' => $compId, ':employee_no' => $empNoMapped, ':email' => uniqid() . '@test.local']);
    $employeeId = (int)$pdo->lastInsertId();

    $model = new PayrollSyncModel($pdo);
    $processId = random_int(100000, 999999);

    function occPayload(string $compCode, string $empCode, int $processId, array $occurrences): array {
        return [
            'schema_version' => 1,
            'process_id' => $processId,
            'process_no' => 'ORIGAMI-PSIO-' . $processId,
            'comp_code' => $compCode,
            'comp_name' => 'PSIO Test Co. (Origami name)',
            'frequency_type' => 'monthly',
            'items' => [
                [
                    'report_item_id' => 1, 'emp_id' => 5001, 'emp_code' => 'E-5001', 'emp_name' => 'Mapped Employee',
                    'payroll_code' => $empCode, 'pay_type' => 'transfer', 'deduct_sso' => true,
                    'working_days' => 22, 'working_mins' => 10560,
                    'item_values' => [
                        ['item_id' => 9, 'item_code' => 'LOAN', 'item_name' => 'Loan', 'item_type' => 'DEDUCTION', 'unit_type' => null, 'value' => 3000, 'remark' => 'installment 2+3 combined'],
                    ],
                ],
                [
                    // No payroll_code at all -- deliberately unmapped, to prove an occurrence tied
                    // to THIS emp_id resolves to employee_id=NULL rather than being dropped.
                    'report_item_id' => 2, 'emp_id' => 5002, 'emp_code' => 'E-5002',
                    'payroll_code' => '', 'pay_type' => 'cash', 'deduct_sso' => false,
                    'working_days' => 22, 'working_mins' => 10560, 'item_values' => [],
                ],
            ],
            'employee_status' => [],
            // item_master (2026-08-29 revision, autoCreateMissingPedTypes()'s own source): a
            // real-world "Employee Item" like LOAN would virtually always be listed here too, so it
            // resolves through the actual `payroll_earning_deduction_types` catalog on pull (bare
            // "LOAN" code preserved in earning_breakdown/deduction_breakdown) rather than falling
            // through SyncPayResolver's "CUSTOM:{name}" unmapped fallback -- required for this
            // test's own full-integration section below, where occurrence matching is by code.
            'item_master' => [
                ['item_code' => 'LOAN', 'item_name' => 'Loan', 'item_type' => 'DEDUCTION'],
            ],
            'scheduled_item_occurrences' => $occurrences,
        ];
    }

    // ---------- Fresh ingest with a real breakdown ----------
    echo "=== Fresh ingest with scheduled_item_occurrences ===\n";
    $res1 = $model->ingest(occPayload($compCode, $empNoMapped, $processId, [
        ['emp_id' => 5001, 'item_code' => 'LOAN', 'item_ref_code' => 'TDI-EI-2026-00001', 'occurrence_code' => 'TDI-EI-2026-00001-002', 'installment_no' => 2, 'amount' => 1500.00, 'applied_at' => '2026-08-31 10:15:00'],
        ['emp_id' => 5001, 'item_code' => 'LOAN', 'item_ref_code' => 'TDI-EI-2026-00001', 'occurrence_code' => 'TDI-EI-2026-00001-003', 'installment_no' => 3, 'amount' => 1500.00, 'applied_at' => '2026/08/31 10:16:00'],
        // Belongs to the UNMAPPED employee (emp_id 5002, no payroll_code) -- must still be
        // preserved (employee_id NULL), not silently dropped.
        ['emp_id' => 5002, 'item_code' => 'SOME_ITEM', 'item_ref_code' => 'REF-X', 'occurrence_code' => 'REF-X-001', 'installment_no' => 1, 'amount' => 250.00, 'applied_at' => null],
    ]));
    checkTrue('fresh ingest succeeds' . (empty($res1['status']) ? " ({$res1['message']})" : ''), $res1['status']);
    $processRowId = $res1['process_row_id'];

    $breakdown = $model->occurrencesForItem($processRowId, $employeeId, 'LOAN');
    check('2 occurrences returned for LOAN, ordered by installment_no', count($breakdown), 2);
    check('installment 2 comes first', (int)$breakdown[0]['installment_no'], 2);
    check('installment 3 comes second', (int)$breakdown[1]['installment_no'], 3);
    check('installment 2 amount is 1500.00', round((float)$breakdown[0]['amount'], 2), 1500.00);
    check('installment 2 item_ref_code preserved', $breakdown[0]['item_ref_code'], 'TDI-EI-2026-00001');
    check('installment 2 occurrence_code preserved', $breakdown[0]['occurrence_code'], 'TDI-EI-2026-00001-002');
    checkTrue('installment 2 applied_at parsed from ISO dash format', $breakdown[0]['applied_at'] === '2026-08-31 10:15:00');
    checkTrue('installment 3 applied_at parsed from Origami slash format (Y/m/d H:i:s)', $breakdown[1]['applied_at'] === '2026-08-31 10:16:00');
    check('the sum of both installments equals the item_values[].value total (3000.00) -- breakdown of the same figure, not a new one', round((float)$breakdown[0]['amount'] + (float)$breakdown[1]['amount'], 2), 3000.00);

    // ---------- Unresolvable emp_id: preserved with employee_id=NULL, not dropped ----------
    echo "=== Occurrence for an unmapped employee -- preserved with employee_id=NULL ===\n";
    $unmappedRow = $pdo->query("SELECT employee_id, origami_emp_id, item_code, amount FROM payroll_sync_item_occurrences
        WHERE process_id = {$processRowId} AND item_code = 'SOME_ITEM'")->fetch(PDO::FETCH_ASSOC);
    checkTrue('the unmapped-employee occurrence row still exists', $unmappedRow !== false);
    check('its employee_id is NULL (payroll_code was never resolvable)', $unmappedRow['employee_id'], null);
    check('its origami_emp_id is preserved for traceability', (int)$unmappedRow['origami_emp_id'], 5002);

    // ---------- A malformed occurrence (no item_code/amount) is skipped, not fatal ----------
    echo "=== Malformed occurrence rows are skipped defensively, not fatal ===\n";
    $res2 = $model->ingest(occPayload($compCode, $empNoMapped, $processId, [
        ['emp_id' => 5001, 'item_code' => '', 'amount' => 100.00], // empty item_code
        ['emp_id' => 5001, 'item_code' => 'LOAN'], // no amount at all
        ['emp_id' => 5001, 'item_code' => 'LOAN', 'amount' => 999.00, 'installment_no' => 9], // the one valid row
    ]));
    checkTrue('resubmit with 2 malformed + 1 valid row still succeeds overall' . (empty($res2['status']) ? " ({$res2['message']})" : ''), $res2['status']);
    $afterMalformed = $model->occurrencesForItem($processRowId, $employeeId, 'LOAN');
    check('only the 1 genuinely valid row was stored (idempotent replace also cleared the previous 2 rows)', count($afterMalformed), 1);
    check('the valid row has the right amount', round((float)$afterMalformed[0]['amount'], 2), 999.00);

    // ---------- Idempotent resubmit fully replaces the occurrence set (delete+reinsert) ----------
    echo "=== Idempotent resubmit replaces occurrences wholesale ===\n";
    $res3 = $model->ingest(occPayload($compCode, $empNoMapped, $processId, [
        ['emp_id' => 5001, 'item_code' => 'LOAN', 'installment_no' => 4, 'amount' => 1500.00],
    ]));
    checkTrue('3rd resubmit succeeds', $res3['status']);
    $afterResubmit = $model->occurrencesForItem($processRowId, $employeeId, 'LOAN');
    check('exactly 1 row after resubmit (old rows replaced, not accumulated)', count($afterResubmit), 1);
    check('the new installment_no is 4', (int)$afterResubmit[0]['installment_no'], 4);

    // ---------- Backward compatibility: a payload with NO scheduled_item_occurrences at all ----------
    echo "=== Backward compatibility: payload with no scheduled_item_occurrences field at all ===\n";
    $processId2 = random_int(100000, 999999);
    $payloadNoOcc = occPayload($compCode, $empNoMapped, $processId2, []);
    unset($payloadNoOcc['scheduled_item_occurrences']);
    $res4 = $model->ingest($payloadNoOcc);
    checkTrue('ingest without the field at all still succeeds (fully backward compatible)' . (empty($res4['status']) ? " ({$res4['message']})" : ''), $res4['status']);
    $noOccResult = $model->occurrencesForItem($res4['process_row_id'], $employeeId, 'LOAN');
    check('no occurrence rows for a payload that never sent the field', count($noOccResult), 0);

    // ---------- Full integration: pulled into a real run, syncDeductionLinesForEmployee() shows the breakdown ----------
    echo "=== Full integration: PayrollRunModel::syncDeductionLinesForEmployee() surfaces the breakdown (Process Detail > Adjust Amounts) ===\n";
    // A real catalog row for LOAN, matching this company's own convention for a configured Employee
    // Item -- resolves through SyncPayResolver's direct pedTypeByItemCode() catalog match, so the
    // persisted line's own 'code' stays exactly 'LOAN' (deterministic for this assertion). Without
    // this, 'LOAN' happens to collide with this app's own built-in loan_repay alias (opt_in_only,
    // requires an admin to explicitly link source_event_code first) and would otherwise fall through
    // to the CUSTOM: fallback path instead -- both paths are correctly covered via
    // SyncPayResolver's own `sync_item_code` field either way, this fixture just picks the
    // deterministic one so this assertion doesn't have to guess which fallback name it became.
    $pdo->prepare("INSERT INTO payroll_earning_deduction_types (comp_id, item_code, item_name_th, item_name_en, item_type, status)
        VALUES (:comp_id, 'LOAN', 'เงินกู้', 'Loan', 'deduction', 'active')")->execute([':comp_id' => $compId]);
    $cycleSave = (new PayrollCycleModel($pdo))->save($compId, [
        'cycle_name' => 'PSIO_CYCLE_' . uniqid(), 'payroll_frequency' => 'monthly',
        'cutoff_day_of_month' => 25, 'payment_day_of_month' => 5, 'ot_cutoff_type' => 'same_as_attendance',
        'bank_file_format_id' => 1, 'status' => 'active',
    ], 1);
    $cycleId = $cycleSave['id'];
    $runModel = new PayrollRunModel($pdo);
    $createRes = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'PSIO Integration Run',
        'period_start_date' => '2027-07-21', 'period_end_date' => '2027-08-20', 'payment_date' => '2027-07-25',
        'sync_process_id' => $processRowId,
    ], 1, true);
    checkTrue('pulling the process into a real run succeeds' . (empty($createRes['status']) ? " ({$createRes['message']})" : ''), $createRes['status']);
    $runId = $createRes['id'];
    $calcRes = $runModel->recalculate($runId, $compId, 1, true);
    checkTrue('recalculate succeeds' . (empty($calcRes['status']) ? " ({$calcRes['message']})" : ''), $calcRes['status']);

    $adjustLines = $runModel->syncDeductionLinesForEmployee($compId, $runId, $employeeId);
    $loanLine = current(array_filter($adjustLines, fn($l) => $l['code'] === 'LOAN'));
    checkTrue('the Adjust Amounts listing includes the LOAN line', $loanLine !== false);
    checkTrue('the LOAN line carries an occurrences breakdown', !empty($loanLine['occurrences']));
    check('the breakdown has exactly 1 entry (the last resubmit left installment 4)', count($loanLine['occurrences']), 1);
    check('the breakdown entry is installment 4', (int)$loanLine['occurrences'][0]['installment_no'], 4);

    $baseSalaryLine = current(array_filter($adjustLines, fn($l) => $l['code'] === PayrollRunModel::BASE_SALARY_OVERRIDE_CODE));
    checkTrue('base salary line is present but carries NO occurrences key at all (no schedule concept for it)', $baseSalaryLine !== false && !array_key_exists('occurrences', $baseSalaryLine));
    checkTrue('no row leaks the internal sync_item_code matching key into its public shape', !array_key_exists('sync_item_code', $loanLine) && !array_key_exists('sync_item_code', $baseSalaryLine));

    // ---------- The OTHER branch: an item with NO catalog row at all (CUSTOM: fallback) ----------
    // Real bug this specifically catches: matching occurrences by the resolved 'code' alone would
    // look for a "CUSTOM:Staff Advance" occurrence row, which Origami never sends (it always sends
    // the real raw item_code) -- silently finding nothing. sync_item_code fixes this by preserving
    // the raw code SyncPayResolver actually saw, independent of which branch resolved the line.
    echo "=== The CUSTOM: fallback branch also correlates correctly via sync_item_code ===\n";
    $processId3 = random_int(100000, 999999);
    $customPayload = occPayload($compCode, $empNoMapped, $processId3, [
        ['emp_id' => 5001, 'item_code' => 'STAFF_ADVANCE_XYZ', 'installment_no' => 1, 'amount' => 800.00],
    ]);
    // Swap the LOAN item for one this company genuinely has no catalog row for and that isn't any
    // known built-in alias either -- forces the CUSTOM: fallback branch.
    $customPayload['items'][0]['item_values'][0] = ['item_id' => 99, 'item_code' => 'STAFF_ADVANCE_XYZ', 'item_name' => 'Staff Advance', 'item_type' => 'DEDUCTION', 'unit_type' => null, 'value' => 800, 'remark' => null];
    unset($customPayload['item_master']); // deliberately NOT in the catalog at all
    $res5 = $model->ingest($customPayload);
    checkTrue('ingest for the CUSTOM: fallback fixture succeeds' . (empty($res5['status']) ? " ({$res5['message']})" : ''), $res5['status']);
    $createRes2 = $runModel->create($compId, [
        'cycle_id' => $cycleId, 'run_name' => 'PSIO Custom Fallback Run',
        'period_start_date' => '2027-08-21', 'period_end_date' => '2027-09-20', 'payment_date' => '2027-08-25',
        'sync_process_id' => $res5['process_row_id'],
    ], 1, true);
    checkTrue('pulling the custom-fallback process into a run succeeds' . (empty($createRes2['status']) ? " ({$createRes2['message']})" : ''), $createRes2['status']);
    $runModel->recalculate($createRes2['id'], $compId, 1, true);
    $customLines = $runModel->syncDeductionLinesForEmployee($compId, $createRes2['id'], $employeeId);
    $customLine = current(array_filter($customLines, fn($l) => str_starts_with($l['code'], 'CUSTOM:')));
    checkTrue('the line resolved through the CUSTOM: fallback (no catalog row existed)', $customLine !== false);
    checkTrue('it STILL carries the occurrence breakdown, matched via the preserved raw item_code', !empty($customLine['occurrences']));
    check('the breakdown amount is correct', round((float)$customLine['occurrences'][0]['amount'], 2), 800.00);

    // ---------- Payslip rendering: PayslipTemplateRenderer::attachOccurrenceBreakdown() ----------
    echo "=== PayslipTemplateRenderer surfaces the same breakdown in the rendered PDF's HTML ===\n";
    $renderer = new PayslipTemplateRenderer($pdo);
    $fakeCompany = ['local_name' => 'Test Co', 'address_line_1' => '123 Test Rd', 'global_tax_id' => '1234567890123',
        'authorized_signatory_name' => 'Jane Doe', 'registered_country' => 'TH', 'logo_path' => null];
    $singleElement = [[
        'element_type' => 'text', 'content' => '{{deduction_lines_all}}', 'field_key' => null,
        'pos_x_pct' => 10, 'pos_y_pct' => 10, 'width_pct' => 80, 'height_pct' => 30,
        'font_size' => 14, 'text_align' => 'left', 'font_weight' => 'normal', 'font_style' => 'normal',
        'text_decoration' => 'none', 'font_color' => '#000000', 'font_family' => 'th_sarabun_new',
        'page_number' => 1, 'group_key' => null, 'image_asset_id' => null,
    ]];

    // Unit-level: buildHtml() directly with a manually-attached occurrence (no DB lookup involved) --
    // proves renderOccurrenceSubRows() itself renders correctly in isolation.
    $unitDetail = [
        'comp_id' => $compId, 'employee_id' => 0, 'employee_no' => 'EMP-0001',
        'name_th' => 'ทดสอบ', 'surname_th' => 'PSIO', 'name_en' => 'Test', 'surname_en' => 'PSIO',
        'department_name_th' => '-', 'department_name_en' => '-', 'position_name_th' => '-', 'position_name_en' => '-',
        'base_salary_amount' => 30000, 'gross_amount' => 30000, 'total_deduction_amount' => 3000, 'net_amount' => 27000,
        'earning_breakdown' => [],
        'deduction_breakdown' => [['code' => 'LOAN', 'name_th' => 'เงินกู้', 'amount' => 3000, 'occurrences' => [
            ['installment_no' => 2, 'occurrence_code' => 'TDI-EI-2026-00001-002', 'amount' => 1500.00, 'applied_at' => '2026-08-31 10:15:00'],
            ['installment_no' => 3, 'occurrence_code' => 'TDI-EI-2026-00001-003', 'amount' => 1500.00, 'applied_at' => '2026-08-31 10:16:00'],
        ]]],
        'statutory_breakdown' => [], 'bank_account_no' => null, 'key_version' => null,
    ];
    $unitHtml = $renderer->buildHtml(['page_size' => 'A4', 'orientation' => 'portrait', 'language' => 'th'],
        $singleElement, $fakeCompany, ['id' => 0, 'period_start_date' => '2027-07-21', 'period_end_date' => '2027-08-20', 'payment_date' => '2027-07-25'],
        $unitDetail, [], null, [], null, null);
    checkTrue('the summed LOAN line renders', strpos($unitHtml, 'เงินกู้') !== false && strpos($unitHtml, '3,000.00') !== false);
    checkTrue('installment 2 sub-row renders with its own amount', strpos($unitHtml, 'งวดที่ 2') !== false && strpos($unitHtml, '1,500.00') !== false);
    checkTrue('installment 3 sub-row also renders', strpos($unitHtml, 'งวดที่ 3') !== false);

    // Integration-level: real run + real DB occurrence data, through renderForRun()'s own
    // attachOccurrenceBreakdown() DB lookup -- proves the FULL pipeline, not just the HTML rendering.
    $realRun = $runModel->get($createRes['id'], $compId);
    $realDetail = current(array_filter((new PayrollReportDataModel($pdo))->getRunDetails($createRes['id']), fn($d) => (int)$d['employee_id'] === $employeeId));
    checkTrue('fixture: found the real run detail row for the integration check', $realDetail !== false);
    $integrationPdf = $renderer->renderForRun($compId, ['page_size' => 'A4', 'orientation' => 'portrait', 'language' => 'th', 'country_code' => 'TH'],
        [['element_type' => 'text', 'content' => '{{deduction_lines_all}}', 'field_key' => null,
          'pos_x_pct' => 10, 'pos_y_pct' => 10, 'width_pct' => 80, 'height_pct' => 30,
          'font_size' => 14, 'text_align' => 'left', 'font_weight' => 'normal', 'font_style' => 'normal',
          'text_decoration' => 'none', 'font_color' => '#000000', 'font_family' => 'th_sarabun_new',
          'page_number' => 1, 'group_key' => null, 'image_asset_id' => null]],
        $fakeCompany, $realRun, $realDetail, null);
    checkTrue('renderForRun() (real run, real DB occurrence lookup) produces a valid PDF', strpos($integrationPdf, '%PDF') === 0);

    // ---------- Reconciliation report (ScheduledItemOccurrenceReconciliationReport) ----------
    echo "=== ScheduledItemOccurrenceReconciliationReport ===\n";
    $reportDataModel = new PayrollReportDataModel($pdo);
    // Company-wide, spans every process ingested so far in this file (LOAN via catalog-match, and
    // STAFF_ADVANCE_XYZ via CUSTOM: fallback) -- proves the query isn't scoped to one run/process.
    $allOccurrences = $reportDataModel->scheduledItemOccurrences($compId, null, null);
    checkTrue('scheduledItemOccurrences() (no date filter) returns rows spanning multiple processes', count($allOccurrences) >= 2);
    $staffAdvanceRow = current(array_filter($allOccurrences, fn($r) => $r['item_code'] === 'STAFF_ADVANCE_XYZ'));
    checkTrue('includes the CUSTOM:-fallback item too, not just the catalog-matched one', $staffAdvanceRow !== false);
    check('its linked run_name resolves correctly through the LEFT JOIN', $staffAdvanceRow['run_name'], 'PSIO Custom Fallback Run');

    $unmatchedFiltered = $reportDataModel->scheduledItemOccurrences($compId, '2099-01-01', '2099-12-31');
    check('a date range matching nothing returns an empty array, not an error', count($unmatchedFiltered), 0);

    // Note: the ORIGINAL unmapped-employee (emp_id 5002) occurrence from the very first fresh-ingest
    // above no longer exists by this point -- every later resubmit to that same $processId
    // (idempotent delete+reinsert, see replaceScheduledItemOccurrences()'s own docblock) replaced
    // the whole occurrence set for it, and none of those later payloads re-included that row. That
    // specific "unmapped employee_id preserved, not dropped" behavior is already directly proven
    // earlier in this file (see "Occurrence for an unmapped employee" above) -- this section only
    // needs to confirm the REPORT-level query surfaces an unresolved row correctly when one DOES
    // currently exist, using a fresh fixture instead of relying on now-stale state.
    $unresolvedProcessId = random_int(100000, 999999);
    $unresolvedPayload = occPayload($compCode, $empNoMapped, $unresolvedProcessId, [
        ['emp_id' => 9999, 'item_code' => 'UNRESOLVED_ITEM', 'installment_no' => 1, 'amount' => 111.00, 'applied_at' => '2026-08-31 09:00:00'],
    ]);
    unset($unresolvedPayload['item_master']);
    $unresolvedIngestRes = $model->ingest($unresolvedPayload);
    checkTrue('fixture: unresolved-employee occurrence ingested', $unresolvedIngestRes['status']);

    $dateFilteredOnce = $reportDataModel->scheduledItemOccurrences($compId, '2026-08-31', '2026-08-31');
    $unresolvedRow = current(array_filter($dateFilteredOnce, fn($r) => (int)($r['origami_emp_id'] ?? 0) === 9999));
    checkTrue('an occurrence for an unresolved employee_id still appears in the report query (not silently excluded)', $unresolvedRow !== false);
    checkTrue('employee columns are NULL for it, not a fatal/missing row', $unresolvedRow['employee_no'] === null);

    $reconReport = ReportRegistry::get('SCHEDULED_ITEM_OCCURRENCE_RECONCILIATION');
    checkTrue('the report is registered in ReportRegistry', $reconReport !== null);
    check('reportType is internal', $reconReport->reportType(), 'internal');
    checkTrue('isVerified is true (own layout, no external spec claimed)', $reconReport->isVerified());
    check('supports excel only', $reconReport->supportedFormats(), ['excel']);

    $reconResult = $reconReport->generate(['comp_id' => $compId], 'excel');
    checkTrue('generate() produces a real XLSX (ZIP-signature bytes)', strpos($reconResult['content'], 'PK') === 0);
    check('mime_type is the real xlsx content-type', $reconResult['mime_type'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $reconThrew = false;
    try {
        $reconReport->generate(['comp_id' => $compId, 'date_from' => '2099-01-01', 'date_to' => '2099-12-31'], 'excel');
    } catch (LocalizedException $e) {
        $reconThrew = true;
        check('LocalizedException error_key for an empty result set', $e->getErrorKey(), 'no_occurrence_data');
    }
    checkTrue('generate() throws (not a silent empty file) when no occurrence data matches the filter', $reconThrew);

    $reconNoCompThrew = false;
    try {
        $reconReport->generate([], 'excel');
    } catch (LocalizedException $e) {
        $reconNoCompThrew = true;
        check('LocalizedException error_key for a missing comp_id', $e->getErrorKey(), 'comp_id_required');
    }
    checkTrue('generate() throws when comp_id is missing entirely', $reconNoCompThrew);

} finally {
    $pdo->rollBack();
    echo "rolled back.\n";
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Passed: {$passes}, Failed: {$failures}\n";
echo $failures === 0 ? "ALL TESTS PASSED (transaction rolled back, no data persisted)\n" : "SOME TESTS FAILED\n";
