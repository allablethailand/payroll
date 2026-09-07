<?php
/**
 * Lightweight verification script for Platform Hardening Phase 6, batch 4: extends AuditLogModel
 * wiring to TaxStatutoryModel -- save()/toggleStatus()/delete() on `statutory_items`, plus
 * rateHistorySave()/rateHistoryDelete() on `statutory_item_rate_history`.
 *
 * Genuinely different shape from every prior batch: `statutory_items` is BOTH a system-wide MASTER
 * catalog (comp_id IS NULL, affects every company on the platform -- e.g. the national SSO rate) AND
 * a company's own CUSTOM item (comp_id = that company), governed by the SAME model methods via an
 * `?int $compId` scope parameter (see TaxStatutoryModel::save()'s own docblock). Only the CUSTOM-item
 * branch is logged into the per-company audit_logs table -- editing the MASTER catalog is a
 * different ownership story entirely (same reasoning already applied to
 * CompanyStatutorySettingModel::promoteOverrideToMaster() in batch 2, which stayed out of scope for
 * the identical reason). `statutory_item_rate_history` has no comp_id column of its own at all --
 * whether a rate-history edit is loggable is resolved from its OWNING item's own comp_id at call
 * time (NULL = master rate, skip; non-null = that company's own custom item's rate, log under it).
 *
 * Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB inside a
 * transaction that is always rolled back. Every fixture uses its OWN freshly-created company (same
 * makeCompany() pattern as tests/audit_log_test.php and tests/statutory_master_clone_test.php) --
 * zero dev-DB contamination risk, including for the MASTER-scope assertions (a fresh master item is
 * created via save(..., null) rather than touching any REAL production master item like TH_SSO).
 *
 * Run with: php tests/audit_log_batch4_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/AuditLogModel.php';
require_once __DIR__ . '/../app/models/TaxStatutoryModel.php';

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

function makeCompany(PDO $pdo, string $countryCode): int {
    $stmt = $pdo->prepare("INSERT INTO companies (company_legal_name, local_name, registered_country, global_tax_id, address_line_1, authorized_signatory_name)
        VALUES (:name, :name, :cc, :tax, 'Test Address', 'Test Signatory')");
    $stmt->execute([':name' => 'Test Co ' . uniqid(), ':cc' => $countryCode, ':tax' => 'TAX' . uniqid()]);
    return (int)$pdo->lastInsertId();
}

/** No comp_id filter -- statutory_items/statutory_item_rate_history rows may be MASTER-scoped
 *  (comp_id IS NULL), so a per-company WHERE would silently miss exactly the rows this test needs
 *  to confirm are absent. */
function auditRowsForAnyComp(PDO $pdo, string $tableName, int $recordId): array {
    $stmt = $pdo->prepare("SELECT * FROM audit_logs WHERE table_name = :table_name AND record_id = :record_id ORDER BY id ASC");
    $stmt->execute([':table_name' => $tableName, ':record_id' => $recordId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    $userId = (int)$pdo->query("SELECT id FROM employees ORDER BY id ASC LIMIT 1")->fetchColumn();
    if ($userId <= 0) {
        throw new RuntimeException('No employee row exists in this dev DB to use as a valid created_by/updated_by FK value.');
    }
    $compId = makeCompany($pdo, 'TH');
    $taxModel = new TaxStatutoryModel();

    // ================= MASTER scope (compId = null) -- must NEVER be logged =================
    echo "=== TaxStatutoryModel: MASTER item scope (compId = null) -- never audited ===\n";
    $masterCode = 'AUDB4M' . substr(uniqid(), -6);
    $masterCreate = $taxModel->save([
        'country_code' => 'TH', 'code' => $masterCode, 'name_th' => 'ทดสอบมาสเตอร์', 'name_en' => 'Test Master',
        'category' => 'other', 'calc_method' => 'flat_rate', 'calc_base' => 'basic_salary',
        'is_employee_applicable' => true, 'is_employer_applicable' => true,
    ], $userId, null);
    checkTrue('create master item' . (empty($masterCreate['status']) ? " ({$masterCreate['message']})" : ''), $masterCreate['status']);
    $masterId = (int)($masterCreate['id'] ?? 0);
    if ($masterId > 0) {
        $masterUpdate = $taxModel->save([
            'id' => $masterId, 'country_code' => 'TH', 'code' => $masterCode, 'name_th' => 'ทดสอบมาสเตอร์ (แก้ไข)', 'name_en' => 'Test Master Renamed',
            'category' => 'other', 'calc_method' => 'flat_rate', 'calc_base' => 'basic_salary',
            'is_employee_applicable' => true, 'is_employer_applicable' => true,
        ], $userId, null, '10.3.0.1');
        checkTrue('update master item' . (empty($masterUpdate['status']) ? " ({$masterUpdate['message']})" : ''), $masterUpdate['status']);
        check('master item update is NEVER logged into audit_logs (out of scope, different ownership story)', count(auditRowsForAnyComp($pdo, 'statutory_items', $masterId)), 0);

        $masterToggle = $taxModel->toggleStatus($masterId, $userId, null, '10.3.0.2');
        checkTrue('toggle master item status' . (empty($masterToggle['status']) ? " ({$masterToggle['message']})" : ''), $masterToggle['status']);
        check('master item toggleStatus is NEVER logged', count(auditRowsForAnyComp($pdo, 'statutory_items', $masterId)), 0);

        // A master item's own rate history is ALSO never logged (system-wide impact, same reasoning).
        $masterRateCreate = $taxModel->rateHistorySave([
            'statutory_item_id' => $masterId, 'effective_date' => '2026-01-01', 'employee_rate' => 5, 'employer_rate' => 5,
        ], $userId);
        checkTrue('create master item rate history' . (empty($masterRateCreate['status']) ? " ({$masterRateCreate['message']})" : ''), $masterRateCreate['status']);
        $masterRateId = (int)($masterRateCreate['id'] ?? 0);
        if ($masterRateId > 0) {
            $masterRateUpdate = $taxModel->rateHistorySave([
                'id' => $masterRateId, 'statutory_item_id' => $masterId, 'effective_date' => '2026-01-01', 'employee_rate' => 6, 'employer_rate' => 6,
            ], $userId, '10.3.0.3');
            checkTrue('update master item rate history' . (empty($masterRateUpdate['status']) ? " ({$masterRateUpdate['message']})" : ''), $masterRateUpdate['status']);
            check('master item rate history update is NEVER logged', count(auditRowsForAnyComp($pdo, 'statutory_item_rate_history', $masterRateId)), 0);

            $masterRateDelete = $taxModel->rateHistoryDelete($masterRateId, $userId, '10.3.0.4');
            checkTrue('delete master item rate history', $masterRateDelete['status']);
            check('master item rate history delete is NEVER logged', count(auditRowsForAnyComp($pdo, 'statutory_item_rate_history', $masterRateId)), 0);
        }

        $masterDelete = $taxModel->delete($masterId, $userId, null, '10.3.0.5');
        checkTrue('delete master item', $masterDelete['status']);
        check('master item delete is NEVER logged', count(auditRowsForAnyComp($pdo, 'statutory_items', $masterId)), 0);
    }

    // ================= CUSTOM item scope (compId set) -- update/delete/toggle ARE logged =================
    echo "\n=== TaxStatutoryModel: CUSTOM item scope (compId set) -- update/delete/toggle audited ===\n";
    $customCode = 'AUDB4C' . substr(uniqid(), -6);
    $customCreate = $taxModel->save([
        'country_code' => 'TH', 'code' => $customCode, 'name_th' => 'ทดสอบกำหนดเอง', 'name_en' => 'Test Custom',
        'category' => 'other', 'calc_method' => 'flat_rate', 'calc_base' => 'basic_salary',
        'is_employee_applicable' => true, 'is_employer_applicable' => true,
    ], $userId, $compId);
    checkTrue('create custom item' . (empty($customCreate['status']) ? " ({$customCreate['message']})" : ''), $customCreate['status']);
    $customId = (int)($customCreate['id'] ?? 0);
    if ($customId > 0) {
        check('custom item create branch is not audited (only update/delete/toggle are wired)', count(auditRowsForAnyComp($pdo, 'statutory_items', $customId)), 0);

        $customUpdate = $taxModel->save([
            'id' => $customId, 'country_code' => 'TH', 'code' => $customCode, 'name_th' => 'ทดสอบกำหนดเอง (แก้ไข)', 'name_en' => 'Test Custom Renamed',
            'category' => 'other', 'calc_method' => 'flat_rate', 'calc_base' => 'basic_salary',
            'is_employee_applicable' => true, 'is_employer_applicable' => true,
        ], $userId, $compId, '10.3.0.6');
        checkTrue('update custom item' . (empty($customUpdate['status']) ? " ({$customUpdate['message']})" : ''), $customUpdate['status']);
        $customFields = array_column(auditRowsForAnyComp($pdo, 'statutory_items', $customId), 'field_name');
        checkTrue('custom item update: name_en change logged', in_array('name_en', $customFields, true));

        $customToggle = $taxModel->toggleStatus($customId, $userId, $compId, '10.3.0.7');
        checkTrue('toggle custom item status', $customToggle['status']);
        $customToggleRow = current(array_filter(auditRowsForAnyComp($pdo, 'statutory_items', $customId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === $customToggle['new_status']));
        checkTrue('custom item toggleStatus: status change logged', $customToggleRow !== false);

        // Custom item's own rate history IS logged, scoped to its owning company.
        $customRateCreate = $taxModel->rateHistorySave([
            'statutory_item_id' => $customId, 'effective_date' => '2026-01-01', 'employee_rate' => 2, 'employer_rate' => 2,
        ], $userId);
        checkTrue('create custom item rate history' . (empty($customRateCreate['status']) ? " ({$customRateCreate['message']})" : ''), $customRateCreate['status']);
        $customRateId = (int)($customRateCreate['id'] ?? 0);
        if ($customRateId > 0) {
            check('custom item rate history create branch is not audited', count(auditRowsForAnyComp($pdo, 'statutory_item_rate_history', $customRateId)), 0);

            $customRateUpdate = $taxModel->rateHistorySave([
                'id' => $customRateId, 'statutory_item_id' => $customId, 'effective_date' => '2026-01-01', 'employee_rate' => 4, 'employer_rate' => 4,
            ], $userId, '10.3.0.8');
            checkTrue('update custom item rate history' . (empty($customRateUpdate['status']) ? " ({$customRateUpdate['message']})" : ''), $customRateUpdate['status']);
            $customRateFields = array_column(auditRowsForAnyComp($pdo, 'statutory_item_rate_history', $customRateId), 'field_name');
            checkTrue('custom item rate history update: employee_rate change logged', in_array('employee_rate', $customRateFields, true));

            $customRateDelete = $taxModel->rateHistoryDelete($customRateId, $userId, '10.3.0.9');
            checkTrue('delete custom item rate history', $customRateDelete['status']);
            $customRateDeleteRows = array_filter(auditRowsForAnyComp($pdo, 'statutory_item_rate_history', $customRateId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === 'deleted');
            checkTrue('custom item rate history delete: logged as a status->deleted update row', count($customRateDeleteRows) > 0);
        }

        $customDelete = $taxModel->delete($customId, $userId, $compId, '10.3.0.10');
        checkTrue('delete custom item', $customDelete['status']);
        $customDeleteRows = array_filter(auditRowsForAnyComp($pdo, 'statutory_items', $customId), fn($r) => $r['field_name'] === 'status' && $r['new_value'] === 'deleted');
        checkTrue('custom item delete: logged as a status->deleted update row', count($customDeleteRows) > 0);
    }

    echo "\n=== SUMMARY: {$passes} passed, {$failures} failed ===\n";
} finally {
    $pdo->rollBack();
}

exit($failures > 0 ? 1 : 0);
