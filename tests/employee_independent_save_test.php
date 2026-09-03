<?php
/**
 * Verifies EmployeeModel::save() no longer blocks a save just because fields on OTHER tabs are
 * still empty (2026-08-19, explicit request: "แต่ละ Tab อยากให้บันทึกได้แบบอิสระต่อกัน"), and that
 * is_payroll_ready / verifyStatus() ("Verify Status") are computed dynamically from whatever the
 * row currently holds instead of being hardcoded true on every successful save.
 * Run with: php tests/employee_independent_save_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/EncryptionService.php';
require_once __DIR__ . '/../app/models/EmployeeModel.php';

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

// 2026-09-02, follow-up: payment_type (legacy enum) dropped -- EmployeeModel::save() payloads now
// use payment_method_id, resolved here via master_payment_methods.code.
function resolvePaymentMethodId(PDO $pdo, string $code): int {
    $stmt = $pdo->prepare("SELECT id FROM `master_payment_methods` WHERE code = :code");
    $stmt->execute([':code' => $code]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        throw new RuntimeException("master_payment_methods code '{$code}' not found -- seed missing?");
    }
    return (int)$id;
}

function makeCompany(PDO $pdo, string $countryCode): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

function makeStructure(PDO $pdo, int $compId): array {
    $ids = [];
    $stmt = $pdo->prepare("INSERT INTO structure_departments (comp_id, department_code, department_name_th, department_name_en) VALUES (:c, :code, 'แผนกทดสอบ', 'Test Dept')");
    $stmt->execute([':c' => $compId, ':code' => 'DEPT' . uniqid()]);
    $ids['department_id'] = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO structure_roles (comp_id, role_name_th, role_name_en) VALUES (:c, 'ตำแหน่งทดสอบ', 'Test Role')");
    $stmt->execute([':c' => $compId]);
    $ids['role_id'] = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO structure_positions (comp_id, position_code, position_name_th, position_name_en) VALUES (:c, :code, 'ตำแหน่งทดสอบ', 'Test Position')");
    $stmt->execute([':c' => $compId, ':code' => 'POS' . uniqid()]);
    $ids['position_id'] = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO structure_branches (comp_id, branch_code, branch_name_th, branch_name_en) VALUES (:c, :code, 'สาขาทดสอบ', 'Test Branch')");
    $stmt->execute([':c' => $compId, ':code' => 'BR' . uniqid()]);
    $ids['branch_id'] = (int)$pdo->lastInsertId();

    return $ids;
}

try {
    $userId = 1;
    $model = new EmployeeModel();
    $compId = makeCompany($pdo, 'TH');
    $structure = makeStructure($pdo, $compId);

    // ---------- Missing employee_no is still the one hard-blocking case ----------
    $noEmpNo = ['title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ'];
    $r0 = $model->save($compId, $noEmpNo, $userId);
    checkFalse('Save without employee_no is still rejected', $r0['status']);
    check('Rejection message names employee_no', $r0['message'], 'Missing required field: employee_no');

    // ---------- Info-tab-only create: no Employment/Salary/Contact fields at all ----------
    $empNo = 'IND-EMP-' . uniqid();
    $infoOnly = [
        'employee_no' => $empNo, 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ทดสอบ', 'surname_th' => 'นามสกุล',
        'name_en' => 'Test', 'surname_en' => 'Surname', 'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        'id_card_no' => '1234567890121', // valid mod-11 checksum -- Info tab's own identification field filled in
    ];
    $r1 = $model->save($compId, $infoOnly, $userId);
    checkTrue('Info-tab-only payload creates a new employee' . (empty($r1['status']) ? " ({$r1['message']})" : ''), $r1['status']);

    $row1 = $r1['status'] ? $model->get($compId, $empNo) : null;
    if ($row1) {
        check('is_payroll_ready is 0 right after an Info-only create', (int)$row1['is_payroll_ready'], 0);
        checkFalse('verify_status.ready is false', $row1['verify_status']['ready']);
        checkTrue('verify_status.missing_tabs includes contact', in_array('contact', $row1['verify_status']['missing_tabs'], true));
        checkTrue('verify_status.missing_tabs includes employment', in_array('employment', $row1['verify_status']['missing_tabs'], true));
        checkTrue('verify_status.missing_tabs includes salary', in_array('salary', $row1['verify_status']['missing_tabs'], true));
        checkFalse('Info tab itself is NOT in missing_tabs (its own fields are all filled)', in_array('info', $row1['verify_status']['missing_tabs'], true));
    }

    // ---------- Employment-tab-only save on the SAME record (simulating the next tab's Save click,
    // which per detail.js always resubmits the whole form -- Info's already-saved fields come along
    // unchanged, only Employment's fields are newly filled in here) ----------
    if ($r1['status']) {
        $employmentPayload = array_merge($infoOnly, $structure, [
            'id' => $r1['id'],
            'employment_date' => '2024-01-01', 'employment_status' => 'permanent', 'employment_type' => 'full_time',
            'workforce_type' => 'office', 'record_time_method' => 'manual', 'payment_method_id' => resolvePaymentMethodId($pdo, 'cash'),
        ]);
        $r2 = $model->save($compId, $employmentPayload, $userId);
        checkTrue('Employment-tab save on existing record succeeds without Contact/Salary filled in' . (empty($r2['status']) ? " ({$r2['message']})" : ''), $r2['status']);

        $row2 = $r2['status'] ? $model->get($compId, $empNo) : null;
        if ($row2) {
            check('is_payroll_ready still 0 (Contact/Salary still missing)', (int)$row2['is_payroll_ready'], 0);
            checkFalse('employment no longer in missing_tabs', in_array('employment', $row2['verify_status']['missing_tabs'], true));
            checkTrue('salary still in missing_tabs', in_array('salary', $row2['verify_status']['missing_tabs'], true));
        }

        // ---------- Fill in Contact + Salary too -> now fully ready ----------
        if ($row2) {
            $fullPayload = array_merge($employmentPayload, [
                'personal_email' => 'indep' . uniqid() . '@example.com', 'mobile_no' => '812345678',
                'id_card_no' => '1234567890121', // valid mod-11 checksum
                'salary_type' => 'monthly', 'base_salary_amount' => 25000, 'salary_effective_date' => '2024-01-01',
                'tax_calculation_method' => 'average',
            ]);
            $r3 = $model->save($compId, $fullPayload, $userId);
            checkTrue('Final save with every tab filled succeeds' . (empty($r3['status']) ? " ({$r3['message']})" : ''), $r3['status']);
            $row3 = $r3['status'] ? $model->get($compId, $empNo) : null;
            if ($row3) {
                check('is_payroll_ready flips to 1 once every required field is present', (int)$row3['is_payroll_ready'], 1);
                checkTrue('verify_status.ready is now true', $row3['verify_status']['ready']);
                check('verify_status.missing_tabs is now empty', $row3['verify_status']['missing_tabs'], []);
            }
        }
    }

    // ---------- 2026-08-30 (T023, explicit request: "นามสกุลไม่เป็น required field") -- a fully-ready
    // employee with NO surname at all (both surname_th/surname_en blank) must still reach
    // is_payroll_ready=1/verify_status.ready=true; first name alone is enough. Reuses $employmentPayload
    // (Employment tab already filled from the section above) plus the same Contact/Salary fields
    // $fullPayload added, minus surname_th/surname_en entirely. ----------
    if ($r1['status'] ?? false) {
        $noSurnamePayload = array_merge($employmentPayload, [
            'employee_no' => 'IND-EMP-NOSURNAME-' . uniqid(), 'id' => null,
            'surname_th' => '', 'surname_en' => '',
            'personal_email' => 'nosurname' . uniqid() . '@example.com', 'mobile_no' => '812345679',
            'id_card_no' => '1234567890121',
            'salary_type' => 'monthly', 'base_salary_amount' => 25000, 'salary_effective_date' => '2024-01-01',
            'tax_calculation_method' => 'average',
        ]);
        $rNoSurname = $model->save($compId, $noSurnamePayload, $userId);
        checkTrue('Save with blank surname_th/surname_en succeeds' . (empty($rNoSurname['status']) ? " ({$rNoSurname['message']})" : ''), $rNoSurname['status']);
        if ($rNoSurname['status']) {
            $noSurnameRow = $model->get($compId, $noSurnamePayload['employee_no']);
            check('is_payroll_ready is 1 with no surname at all (surname is no longer required)', (int)$noSurnameRow['is_payroll_ready'], 1);
            checkTrue('verify_status.ready is true with no surname', $noSurnameRow['verify_status']['ready']);
            check('verify_status.missing_tabs is empty (surname never appears in a missing-field tab)', $noSurnameRow['verify_status']['missing_tabs'], []);
            checkFalse('surname_th is genuinely blank in the saved row (not silently defaulted)', !empty($noSurnameRow['surname_th']));
        }
    }

    // ---------- 2026-08-30 (Phase 3, T020, explicit request: field "จ่าย/ไม่จ่ายเงินเดือน", default =
    // จ่าย) -- brand-new employee with the key entirely OMITTED defaults to paid (1, matching the DB
    // column's own DEFAULT), an explicit unpaid save works and skips every payroll-required field,
    // and an existing UNPAID employee re-saved WITHOUT the key stays unpaid (doesn't silently flip
    // back to paid just because some other tab was edited -- same defense as T014's status-preserving
    // fix in PayrollEarningDeductionTypeModel::save()). ----------
    $noKeyPayload = [
        'employee_no' => 'IND-EMP-PAYDEFAULT-' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ก', 'name_en' => 'A',
        'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        // is_payroll_participant deliberately omitted entirely
    ];
    $rNoKey = $model->save($compId, $noKeyPayload, $userId);
    checkTrue('Save with is_payroll_participant key entirely omitted succeeds' . (empty($rNoKey['status']) ? " ({$rNoKey['message']})" : ''), $rNoKey['status']);
    if ($rNoKey['status']) {
        $noKeyRow = $model->get($compId, $noKeyPayload['employee_no']);
        check('defaults to 1 (paid) when the key was never sent', (int)$noKeyRow['is_payroll_participant'], 1);
    }

    $unpaidPayload = [
        'employee_no' => 'IND-EMP-UNPAID-' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ข', 'name_en' => 'B',
        'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        'is_payroll_participant' => '0',
        // Every payroll-required field left blank on purpose -- must not block the save or count
        // against is_payroll_ready for a staff-only record.
    ];
    $rUnpaid = $model->save($compId, $unpaidPayload, $userId);
    checkTrue('Save with is_payroll_participant=0 and no payroll fields at all succeeds' . (empty($rUnpaid['status']) ? " ({$rUnpaid['message']})" : ''), $rUnpaid['status']);
    if ($rUnpaid['status']) {
        $unpaidRow = $model->get($compId, $unpaidPayload['employee_no']);
        check('is_payroll_participant persisted as 0', (int)$unpaidRow['is_payroll_participant'], 0);
        check('is_payroll_ready is 1 (moot/ready -- staff-only, nothing payroll-related required)', (int)$unpaidRow['is_payroll_ready'], 1);
        checkTrue('verify_status.ready is true for a staff-only employee', $unpaidRow['verify_status']['ready']);
        check('verify_status.missing_tabs is empty (no payroll tab can ever be "missing" for a non-participant)', $unpaidRow['verify_status']['missing_tabs'], []);

        // Re-save the SAME employee (an unrelated field edit, e.g. from a different tab) WITHOUT
        // resending is_payroll_participant at all -- must stay unpaid, not silently flip back to paid.
        $reSavePayload = array_merge($unpaidPayload, ['id' => $rUnpaid['id'], 'name_en' => 'B-Updated']);
        unset($reSavePayload['is_payroll_participant']);
        $rReSave = $model->save($compId, $reSavePayload, $userId);
        checkTrue('Re-save without the key succeeds' . (empty($rReSave['status']) ? " ({$rReSave['message']})" : ''), $rReSave['status']);
        if ($rReSave['status']) {
            $reSavedRow = $model->get($compId, $unpaidPayload['employee_no']);
            check('is_payroll_participant STAYS 0 after a re-save that omits the key (falls back to the existing row value, not the DB default)', (int)$reSavedRow['is_payroll_participant'], 0);
            check('the unrelated field (name_en) did update, confirming this was a real save not a no-op', $reSavedRow['name_en'], 'B-Updated');
        }
    }

    // ---------- 2026-08-30 (Phase 3, T022, explicit request: "Tab/Filter จ่าย vs ไม่จ่ายเงินเดือน...
    // verify ว่ายัง filter หาเจ้าหน้าที่ที่ต้องจ่ายเงินเดือนได้ปกติ") -- EmployeeModel::list()'s new
    // is_payroll_participant filter correctly separates the two groups, doesn't mix them. ----------
    $paidForListPayload = [
        'employee_no' => 'IND-EMP-LISTPAID-' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ค', 'name_en' => 'C',
        'date_of_birth' => '1990-01-01', 'nationality' => 'Thai', 'is_payroll_participant' => '1',
    ];
    $rPaidForList = $model->save($compId, $paidForListPayload, $userId);
    checkTrue('fixture: paid employee for list() filter test created' . (empty($rPaidForList['status']) ? " ({$rPaidForList['message']})" : ''), $rPaidForList['status']);
    $unpaidForListPayload = [
        'employee_no' => 'IND-EMP-LISTUNPAID-' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ง', 'name_en' => 'D',
        'date_of_birth' => '1990-01-01', 'nationality' => 'Thai', 'is_payroll_participant' => '0',
    ];
    $rUnpaidForList = $model->save($compId, $unpaidForListPayload, $userId);
    checkTrue('fixture: unpaid employee for list() filter test created' . (empty($rUnpaidForList['status']) ? " ({$rUnpaidForList['message']})" : ''), $rUnpaidForList['status']);

    $listNoFilter = $model->list($compId, 0, 50, [], '', 0, 'asc');
    $listNoFilterNos = array_column($listNoFilter['data'], 'employee_no');
    checkTrue('list() with no filter includes BOTH the paid and unpaid fixture (no default exclusion)', in_array($paidForListPayload['employee_no'], $listNoFilterNos, true) && in_array($unpaidForListPayload['employee_no'], $listNoFilterNos, true));

    $listPaidOnly = $model->list($compId, 0, 50, ['is_payroll_participant' => '1'], '', 0, 'asc');
    $listPaidOnlyNos = array_column($listPaidOnly['data'], 'employee_no');
    checkTrue('is_payroll_participant=1 filter includes the paid fixture', in_array($paidForListPayload['employee_no'], $listPaidOnlyNos, true));
    check('is_payroll_participant=1 filter does NOT include the unpaid fixture (groups not mixed)', in_array($unpaidForListPayload['employee_no'], $listPaidOnlyNos, true), false);

    $listUnpaidOnly = $model->list($compId, 0, 50, ['is_payroll_participant' => '0'], '', 0, 'asc');
    $listUnpaidOnlyNos = array_column($listUnpaidOnly['data'], 'employee_no');
    checkTrue('is_payroll_participant=0 filter includes the unpaid fixture', in_array($unpaidForListPayload['employee_no'], $listUnpaidOnlyNos, true));
    check('is_payroll_participant=0 filter does NOT include the paid fixture (groups not mixed)', in_array($paidForListPayload['employee_no'], $listUnpaidOnlyNos, true), false);

    // ---------- 2026-08-30 (Phase 3, T018, explicit request: "Recheck ข้อมูล...column-by-column ว่า
    // ข้อมูลจำเป็นสำหรับทำเงินเดือนครบหรือไม่") -- EmployeeModel::recheckList()/fieldReadiness(). ----------
    // Re-saves $empNo back down to Info-tab-only (overwrites the fuller state $r3 further above left
    // it in -- save() always overwrites every column, see this file's own top-of-file docblock) so
    // this section has a known, deliberately-incomplete fixture to assert against; nothing later in
    // this file references $empNo again, so resetting it here is safe.
    $recheckPartial = $model->save($compId, array_merge($infoOnly, ['id' => $r1['id']]), $userId);
    checkTrue('fixture: re-save back to Info-only for the recheckList() test', $recheckPartial['status']);
    $recheckList1 = $model->recheckList($compId, 0, 50, [], '', 'en');
    $recheckRow1 = null;
    foreach ($recheckList1['data'] as $r) { if ($r['employee_no'] === $empNo) $recheckRow1 = $r; }
    checkTrue('recheckList() includes the Info-only-filled employee', $recheckRow1 !== null);
    if ($recheckRow1) {
        check('is_ready is false (Employment/Salary/Contact still genuinely missing)', $recheckRow1['is_ready'], false);
        check('field_readiness.name_th is true (Info tab genuinely filled)', $recheckRow1['field_readiness']['name_th'] ?? null, true);
        check('field_readiness.base_salary_amount is false (Salary tab never filled for this employee)', $recheckRow1['field_readiness']['base_salary_amount'] ?? null, false);
        check('field_readiness.department_id is false (Employment tab never filled)', $recheckRow1['field_readiness']['department_id'] ?? null, false);
    }
    $recheckList2 = $model->recheckList($compId, 0, 50, [], '', 'en');
    $recheckRow3 = null;
    foreach ($recheckList2['data'] as $r) { if ($r['employee_no'] === $noSurnamePayload['employee_no']) $recheckRow3 = $r; }
    checkTrue('recheckList() includes the fully-ready (no-surname) employee too', $recheckRow3 !== null);
    if ($recheckRow3) {
        check('is_ready is true for a genuinely fully-ready employee', $recheckRow3['is_ready'], true);
        // 2026-08-30, explicit request: "และในข้อมูลบัญชีธนาคาร ให้บอกประเภทการจ่ายเงิน เป็นเงินสุด หรือบัญชี"
        // -- the resolved payment method CODE must reach the frontend (not just its readiness
        // boolean) so the Bank Details column can render "Cash" vs "Bank" -- this fixture used
        // payment_method_id='cash'. 2026-09-02, follow-up: payment_type (legacy enum) dropped --
        // recheckList() now exposes payment_method_code instead (see that method's own docblock).
        check('payment_method_code reaches the row (needed for the Bank Details column\'s cash-vs-bank display)', $recheckRow3['payment_method_code'] ?? null, 'cash');
        // 2026-08-30, explicit request: "เพิ่ม Column OT เพิ่มว่าคิดหรือไม่คิด ถ้าคิดคิด Rate ของ OT แต่ละประเภท"
        // -- ot_summary is present on every row; this fixture never set ot_eligible, so it stays at
        // the column's own default (false) and ot_rate_source stays 'default'.
        checkTrue('ot_summary is present on the row', isset($recheckRow3['ot_summary']));
        check('ot_summary.eligible is false (never explicitly enabled for this fixture)', $recheckRow3['ot_summary']['eligible'] ?? null, false);
        check('ot_summary.rate_source defaults to "default"', $recheckRow3['ot_summary']['rate_source'] ?? null, 'default');
        check('ot_summary.scopes has 3 entries (weekday/weekend/holiday)', count($recheckRow3['ot_summary']['scopes'] ?? []), 3);
        // 2026-08-31, explicit request: "ตรงหน้าตรวจสอบเหมือนยังขาด ประกันสังคม ทั้งตารางและหน้า Form" --
        // sso_status is computed server-side from sso_enrolled/sso_no (never exposed raw -- sso_no is
        // encrypted ciphertext, only ever checked for presence, never decrypted here).
        checkFalse('raw sso_enrolled is NOT exposed on the row (stripped, computed sso_status used instead)', array_key_exists('sso_enrolled', $recheckRow3));
        checkFalse('raw sso_no ciphertext is NOT exposed on the row', array_key_exists('sso_no', $recheckRow3));
        check('sso_status is not_enrolled (this fixture never set sso_enrolled)', $recheckRow3['sso_status'] ?? null, 'not_enrolled');
    }
    $ssoEnrolledNoNumber = $model->save($compId, array_merge($noSurnamePayload, ['id' => $rNoSurname['id'], 'sso_enrolled' => true, 'sso_no' => '']), $userId);
    checkTrue('fixture: sso enrolled-but-no-number save succeeds' . (empty($ssoEnrolledNoNumber['status']) ? " ({$ssoEnrolledNoNumber['message']})" : ''), $ssoEnrolledNoNumber['status']);
    $recheckList3 = $model->recheckList($compId, 0, 50, [], '', 'en');
    $recheckRowSso = null;
    foreach ($recheckList3['data'] as $r) { if ($r['employee_no'] === $noSurnamePayload['employee_no']) $recheckRowSso = $r; }
    check('sso_status is enrolled_missing_no when enrolled but sso_no is empty', $recheckRowSso['sso_status'] ?? null, 'enrolled_missing_no');

    $ssoEnrolledComplete = $model->save($compId, array_merge($noSurnamePayload, ['id' => $rNoSurname['id'], 'sso_enrolled' => true, 'sso_no' => '1234567890123']), $userId);
    checkTrue('fixture: sso enrolled-complete save succeeds' . (empty($ssoEnrolledComplete['status']) ? " ({$ssoEnrolledComplete['message']})" : ''), $ssoEnrolledComplete['status']);
    $recheckList4 = $model->recheckList($compId, 0, 50, [], '', 'en');
    $recheckRowSso2 = null;
    foreach ($recheckList4['data'] as $r) { if ($r['employee_no'] === $noSurnamePayload['employee_no']) $recheckRowSso2 = $r; }
    check('sso_status is enrolled_complete when enrolled and sso_no is present', $recheckRowSso2['sso_status'] ?? null, 'enrolled_complete');

    // ---------- 2026-08-30, real bug found and fixed BEFORE shipping: ot_rate_source is a plain
    // enum column (not boolean/int), so an absent key would otherwise fall into save()'s generic
    // `$val = $data[$col] ?? null;` branch and write a literal NULL into a NOT NULL column --
    // exactly what happens on EVERY save from the real Employee Detail page, since its own
    // #ot_rate_source field deliberately carries no `name` attribute (saved through its own
    // dedicated endpoint instead). Proves the fix: explicitly set to 'custom', then a save that
    // OMITS ot_rate_source entirely (simulating the real Detail page's whole-form submit) must
    // PRESERVE 'custom', not reset to 'default' or fail outright. ----------
    $otSourceEmpNo = 'IND-EMP-OTSOURCE-' . uniqid();
    $otSourcePayload = array_merge($infoOnly, ['employee_no' => $otSourceEmpNo, 'id' => null, 'ot_rate_source' => 'custom']);
    $rOtSource1 = $model->save($compId, $otSourcePayload, $userId);
    checkTrue('fixture: employee created with ot_rate_source=custom explicitly' . (empty($rOtSource1['status']) ? " ({$rOtSource1['message']})" : ''), $rOtSource1['status']);
    $rowOtSource1 = $rOtSource1['status'] ? $model->get($compId, $otSourceEmpNo) : null;
    check('ot_rate_source persisted as custom on create', $rowOtSource1['ot_rate_source'] ?? null, 'custom');

    if ($rowOtSource1) {
        // Simulates a save from a tab that never mentions ot_rate_source at all (every real save from
        // the Employee Detail page, and this file's own $infoOnly/$employmentPayload fixtures above).
        $otSourceOmittedPayload = array_merge($infoOnly, ['employee_no' => $otSourceEmpNo, 'id' => $rowOtSource1['id'], 'mobile_no' => '899999999']);
        checkFalse('sanity: this omitted-save payload genuinely does not mention ot_rate_source', array_key_exists('ot_rate_source', $otSourceOmittedPayload));
        $rOtSource2 = $model->save($compId, $otSourceOmittedPayload, $userId);
        checkTrue('save() with ot_rate_source omitted still succeeds (no NOT NULL constraint error)' . (empty($rOtSource2['status']) ? " ({$rOtSource2['message']})" : ''), $rOtSource2['status']);
        $rowOtSource2 = $rOtSource2['status'] ? $model->get($compId, $otSourceEmpNo) : null;
        check('ot_rate_source is STILL custom after a save that omitted it -- preserved, not reset to default or NULL', $rowOtSource2['ot_rate_source'] ?? null, 'custom');
        check('the OTHER field in that same save (mobile_no) genuinely did update, confirming this was a real save, not a no-op', $rowOtSource2['mobile_no'] ?? null, '899999999');
    }

    // A brand-new employee that never mentions ot_rate_source at all (e.g. an older/external
    // integration that doesn't know about this field) must default to 'default', matching the DB
    // column's own DEFAULT, not error or leave it NULL.
    $otSourceFreshEmpNo = 'IND-EMP-OTSOURCE-FRESH-' . uniqid();
    $rOtSourceFresh = $model->save($compId, array_merge($infoOnly, ['employee_no' => $otSourceFreshEmpNo, 'id' => null]), $userId);
    checkTrue('fixture: brand-new employee created without ever mentioning ot_rate_source', $rOtSourceFresh['status']);
    $rowOtSourceFresh = $rOtSourceFresh['status'] ? $model->get($compId, $otSourceFreshEmpNo) : null;
    check('a brand-new employee that never set ot_rate_source defaults to "default"', $rowOtSourceFresh['ot_rate_source'] ?? null, 'default');

    // 2026-08-30, real bug found and fixed while building the above: list()'s own long-standing
    // Salary-tab completeness % never decrypted base_salary_amount before checking it (ciphertext
    // cast to (float) always reads as 0 -> "not filled"), silently broken on the real Employee List
    // page's own completeness bar ever since base_salary_amount was encrypted (2026-08-26) -- fixed
    // in list() itself (key_version now selected + decrypted before calculateCompleteness()), not
    // just in the new recheckList(). Asserted directly against list() here, not just recheckList(),
    // since that's the pre-existing code path the fix actually landed in.
    // get() already decrypts base_salary_amount for the Detail page's own use -- calculateCompleteness()
    // called directly on that GET-shaped (real, decrypted) row is the ground truth to compare list()'s
    // own completeness % against; before this fix, list()'s figure would have been LOWER (base salary
    // wrongly counted as unfilled) even though every OTHER field matches exactly.
    $groundTruthRow = $model->get($compId, $noSurnamePayload['employee_no']);
    $groundTruthPercent = $model->calculateCompleteness($groundTruthRow)['percent'];
    $listFullyReady = $model->list($compId, 0, 50, [], $noSurnamePayload['employee_no'], 0, 'asc');
    checkTrue('list() search finds the fully-ready (no-surname) employee', count($listFullyReady['data']) === 1);
    if (count($listFullyReady['data']) === 1) {
        check('list()\'s own completeness % matches calculateCompleteness() on the real decrypted row (was wrongly lower before this fix, base_salary_amount ciphertext read as unfilled)', (int)$listFullyReady['data'][0]['completeness'], $groundTruthPercent);
    }
    $recheckRowUnpaid = null;
    foreach ($recheckList2['data'] as $r) { if ($r['employee_no'] === $unpaidPayload['employee_no']) $recheckRowUnpaid = $r; }
    checkTrue('T018+T021: the staff-only (is_payroll_participant=0) employee is EXCLUDED from recheckList() entirely', $recheckRowUnpaid === null);

    // ---------- 2026-08-31, explicit request: "เพิ่มปุ่มให้นำออกจากการจ่ายเงินเดือน และมีปุ่มเพิ่ม Employee
    // ที่ไม่ทำจ่ายเงินเดือนกลับเข้ามาทำเงินเดือน" -- recheckList()'s new $participantMode='excluded' view
    // (the "Not in Payroll" list) + setPayrollParticipant() (the Remove/Add-back toggle). Reuses
    // $rUnpaid (already is_payroll_participant=0 from its own fixture above). ----------
    $recheckExcluded = $model->recheckList($compId, 0, 50, [], '', 'en', 'excluded');
    $recheckExcludedRow = null;
    foreach ($recheckExcluded['data'] as $r) { if ($r['employee_no'] === $unpaidPayload['employee_no']) $recheckExcludedRow = $r; }
    checkTrue('recheckList(participantMode=excluded) INCLUDES the staff-only employee', $recheckExcludedRow !== null);
    $recheckExcludedDefault = $model->recheckList($compId, 0, 50, [], '', 'en');
    $recheckExcludedInDefault = null;
    foreach ($recheckExcludedDefault['data'] as $r) { if ($r['employee_no'] === $unpaidPayload['employee_no']) $recheckExcludedInDefault = $r; }
    checkTrue('default recheckList() (participantMode=participant) still excludes it', $recheckExcludedInDefault === null);

    $addBackOk = $model->setPayrollParticipant((int)$rUnpaid['id'], $compId, true);
    checkTrue('setPayrollParticipant(true) succeeds', $addBackOk);
    $rowAfterAddBack = $model->get($compId, $unpaidPayload['employee_no']);
    check('is_payroll_participant is now 1 after Add Back', (int)($rowAfterAddBack['is_payroll_participant'] ?? 0), 1);
    $recheckAfterAddBack = $model->recheckList($compId, 0, 50, [], '', 'en');
    $foundInParticipantViewNow = null;
    foreach ($recheckAfterAddBack['data'] as $r) { if ($r['employee_no'] === $unpaidPayload['employee_no']) $foundInParticipantViewNow = $r; }
    checkTrue('after Add Back, employee now appears in the default (In Payroll) view', $foundInParticipantViewNow !== null);

    $removeOk = $model->setPayrollParticipant((int)$rUnpaid['id'], $compId, false);
    checkTrue('setPayrollParticipant(false) succeeds', $removeOk);
    $rowAfterRemove = $model->get($compId, $unpaidPayload['employee_no']);
    check('is_payroll_participant is back to 0 after Remove', (int)($rowAfterRemove['is_payroll_participant'] ?? 1), 0);

    // ---------- 2026-08-30 (Phase 3, T024, explicit request: "แสดงจำนวนพนักงานต่อ station ด้วย") --
    // EmployeeModel::stationCounts(), the aggregate query backing the station-card pipeline's own
    // .station-count spans. Reuses $paidForListPayload (employee_status='active' by default) and
    // $unpaidForListPayload from the T022 section above -- both are is_payroll_participant-tagged,
    // so this section also proves station counts aren't accidentally affected by that filter unless
    // explicitly asked (is_payroll_participant is NOT one of the 4 station buckets themselves). ----------
    $probationForStationPayload = [
        'employee_no' => 'IND-EMP-STATIONPROB-' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'probation',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'จ', 'name_en' => 'E',
        'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
    ];
    $rProbationForStation = $model->save($compId, $probationForStationPayload, $userId);
    checkTrue('fixture: probation employee for stationCounts() test created' . (empty($rProbationForStation['status']) ? " ({$rProbationForStation['message']})" : ''), $rProbationForStation['status']);
    $permanentForStationPayload = [
        'employee_no' => 'IND-EMP-STATIONPERM-' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'active',
        'employment_status' => 'permanent',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ฉ', 'name_en' => 'F',
        'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
    ];
    $rPermanentForStation = $model->save($compId, $permanentForStationPayload, $userId);
    checkTrue('fixture: permanent employee for stationCounts() test created' . (empty($rPermanentForStation['status']) ? " ({$rPermanentForStation['message']})" : ''), $rPermanentForStation['status']);
    $resignedForStationPayload = [
        'employee_no' => 'IND-EMP-STATIONRESIGN-' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'resigned',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ช', 'name_en' => 'G',
        'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
    ];
    $rResignedForStation = $model->save($compId, $resignedForStationPayload, $userId);
    checkTrue('fixture: resigned employee for stationCounts() test created' . (empty($rResignedForStation['status']) ? " ({$rResignedForStation['message']})" : ''), $rResignedForStation['status']);

    // $compId is a fresh company created just for this test file (makeCompany()) -- every employee
    // created anywhere ABOVE this point in the file is included in these totals too, so counts are
    // asserted as "at least" the fixtures just added here, not exact totals (this file's own earlier
    // sections -- Info-only/employment/resignation fixtures etc. -- already created several
    // employee_status='active'/'resigned' rows of their own before this section runs).
    $counts = $model->stationCounts($compId, [], '', 'en');
    checkTrue('active count includes at least the paid/unpaid list-filter fixtures (both default to employee_status=active)', $counts['active'] >= 2);
    checkTrue('probation count includes the dedicated probation fixture', $counts['probation'] >= 1);
    checkTrue('permanent count includes the dedicated permanent fixture', $counts['permanent'] >= 1);
    checkTrue('resigned count includes the dedicated resigned fixture', $counts['resigned'] >= 1);

    // A department filter that matches NOTHING at all proves stationCounts() actually applies the
    // filters passed in, not just returning company-wide totals regardless.
    $countsFilteredOut = $model->stationCounts($compId, ['department_id' => 999999], '', 'en');
    check('a non-matching department_id filter zeroes out every station', $countsFilteredOut, ['active' => 0, 'probation' => 0, 'permanent' => 0, 'resigned' => 0]);

    // status/employment_status themselves are ignored even if somehow passed in -- they're the
    // station selector, not a valid filter to scope counts BY (a count scoped to itself would be
    // meaningless), confirmed by passing one and seeing every bucket still reflects the real totals.
    $countsIgnoresStatusFilter = $model->stationCounts($compId, ['status' => 'resigned'], '', 'en');
    check('a status filter passed in is ignored -- active count is unaffected, not zeroed to match "resigned"', $countsIgnoresStatusFilter['active'], $counts['active']);

    // ---------- FK fields left empty must not be rejected as "Invalid reference" ----------
    $noFk = [
        'employee_no' => 'IND-EMP-NOFK-' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ก', 'surname_th' => 'ข', 'name_en' => 'A', 'surname_en' => 'B',
        'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        // department_id/role_id/position_id/branch_id deliberately omitted
    ];
    $rFk = $model->save($compId, $noFk, $userId);
    checkTrue('Save with department_id/role_id/position_id/branch_id all omitted still succeeds' . (empty($rFk['status']) ? " ({$rFk['message']})" : ''), $rFk['status']);

    // ---------- bank_id/bank_account_no missing while payment_method_id=transfer must not block the
    // save itself, only is_payroll_ready ----------
    $bankIncomplete = [
        'employee_no' => 'IND-EMP-BANK-' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'active',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ก', 'surname_th' => 'ข', 'name_en' => 'A', 'surname_en' => 'B',
        'date_of_birth' => '1990-01-01', 'nationality' => 'Thai', 'payment_method_id' => resolvePaymentMethodId($pdo, 'transfer'),
    ];
    $rBank = $model->save($compId, $bankIncomplete, $userId);
    checkTrue('Save with payment_method_id=transfer but no bank_id/bank_account_no still succeeds' . (empty($rBank['status']) ? " ({$rBank['message']})" : ''), $rBank['status']);
    if ($rBank['status']) {
        $bankRow = $model->get($compId, $bankIncomplete['employee_no']);
        checkTrue('...but employment stays in missing_tabs because of the incomplete bank details', in_array('employment', $bankRow['verify_status']['missing_tabs'], true));
    }

    // ---------- Resignation/termination fields round-trip (2026-08-21, explicit request) ----------
    $resignedPayload = [
        'employee_no' => 'IND-EMP-RESIGN-' . uniqid(), 'employee_type' => 'domestic', 'employee_status' => 'resigned',
        'title' => 'mr', 'gender' => 'male', 'name_th' => 'ก', 'surname_th' => 'ข', 'name_en' => 'A', 'surname_en' => 'B',
        'date_of_birth' => '1990-01-01', 'nationality' => 'Thai',
        'employment_status' => 'resigned',
        'employment_status_effective_date' => '2026-08-15',
        'employment_end_date' => '2026-08-31',
        'employment_end_reason' => 'ลาออกเพื่อไปศึกษาต่อ',
    ];
    $rResigned = $model->save($compId, $resignedPayload, $userId);
    checkTrue('Save with resignation fields succeeds' . (empty($rResigned['status']) ? " ({$rResigned['message']})" : ''), $rResigned['status']);
    if ($rResigned['status']) {
        $resignedRow = $model->get($compId, $resignedPayload['employee_no']);
        check('employment_status_effective_date round-trips', $resignedRow['employment_status_effective_date'], '2026-08-15');
        check('employment_end_date round-trips (already existed, now actually settable)', $resignedRow['employment_end_date'], '2026-08-31');
        check('employment_end_reason round-trips', $resignedRow['employment_end_reason'], 'ลาออกเพื่อไปศึกษาต่อ');
    }

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
