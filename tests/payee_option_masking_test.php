<?php
declare(strict_types=1);
/**
 * The 3 payee pickers (payee employee / company bank account / saved third-party destination) each
 * carry account details in their option data so the Adjustments modal can show a summary of the
 * chosen account. This test guards the one thing that must never regress there: **a real account
 * number must never leave those methods**. Only the masked form (bullets + last 4 digits) may.
 *
 * Runs against real rows it creates itself inside a transaction and rolls back, so it neither needs
 * nor touches whatever the dev DB happens to hold (see CLAUDE.md's own testing convention).
 */
require_once __DIR__ . '/../vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->load();
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/app/core/Database.php';
require_once dirname(__DIR__) . '/app/services/EncryptionService.php';
require_once dirname(__DIR__) . '/app/models/EmployeeModel.php';
require_once dirname(__DIR__) . '/app/models/PayrollCycleModel.php';
require_once dirname(__DIR__) . '/app/models/PaymentDestinationModel.php';

$passed = 0;
$failed = 0;
function check(string $label, $actual, $expected): void {
    global $passed, $failed;
    $ok = $actual === $expected;
    if ($ok) {
        $passed++;
        echo "PASS: {$label}\n";
    } else {
        $failed++;
        echo 'FAIL: ' . $label . ' -- expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
    }
}

$pdo = Database::getInstance()->pdo;
$compId = (int)$pdo->query("SELECT id FROM `companies` ORDER BY id LIMIT 1")->fetchColumn();
$bankRow = $pdo->query("SELECT id, bank_name_en FROM `master_banks` WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$bankId = (int)$bankRow['id'];
$bankNameEn = (string)$bankRow['bank_name_en'];

// ---------------------------------------------------------------- maskAccountNo() itself
check('maskAccountNo keeps only the last 4 digits visible', EncryptionService::maskAccountNo('1234567890'), "\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}\u{2022}7890");
check('maskAccountNo leaves a 4-digit number alone (nothing to hide)', EncryptionService::maskAccountNo('7890'), '7890');
check('maskAccountNo returns null for an empty value', EncryptionService::maskAccountNo(''), null);
check('maskAccountNo returns null for null', EncryptionService::maskAccountNo(null), null);

$PLAIN_EMPLOYEE = '1112223334';
$PLAIN_COMPANY = '4445556667';
$PLAIN_DESTINATION = '7778889990';

$pdo->beginTransaction();
try {
    // ------------------------------------------------------------ fixtures (rolled back below)
    $enc = EncryptionService::encrypt($PLAIN_EMPLOYEE);
    $stmt = $pdo->prepare("INSERT INTO `employees` (comp_id, employee_no, name_th, surname_th, name_en, surname_en, bank_id, bank_account_no, bank_account_name, bank_branch, key_version, employee_status, created_at)
        VALUES (:comp_id, :employee_no, 'ทดสอบ', 'มาสก์', 'Mask', 'Test', :bank_id, :account_no, 'Mask Test', 'Test Branch', :key_version, 'active', NOW())");
    $stmt->execute([
        ':comp_id' => $compId,
        ':employee_no' => 'MASKTEST-' . substr((string)time(), -6),
        ':bank_id' => $bankId,
        ':account_no' => $enc['value'],
        ':key_version' => $enc['key_version'],
    ]);
    $employeeId = (int)$pdo->lastInsertId();

    $encCo = EncryptionService::encrypt($PLAIN_COMPANY);
    $stmt = $pdo->prepare("INSERT INTO `bank_accounts` (comp_id, bank_id, account_no, account_name, branch_name, is_default, key_version, status, created_at)
        VALUES (:comp_id, :bank_id, :account_no, 'Mask Co Account', 'Co Branch', 1, :key_version, 'active', NOW())");
    $stmt->execute([':comp_id' => $compId, ':bank_id' => $bankId, ':account_no' => $encCo['value'], ':key_version' => $encCo['key_version']]);
    $bankAccountId = (int)$pdo->lastInsertId();

    $destResult = (new PaymentDestinationModel($pdo))->create($compId, [
        'account_name' => 'Mask Destination',
        'account_no' => $PLAIN_DESTINATION,
        'bank_id' => $bankId,
        'bank_branch' => 'Dest Branch',
        'is_saved' => true,
    ], 1);
    check('destination fixture created', $destResult['status'], true);

    // ------------------------------------------------------------ 1. payee employee picker
    $opts = (new EmployeeModel($pdo))->reportToOptions($compId, null, 'Mask', 1, 20);
    $row = null;
    foreach ($opts['items'] as $item) {
        if ((int)$item['id'] === $employeeId) {
            $row = $item;
        }
    }
    check('employee option row found', $row !== null, true);
    if ($row) {
        $flat = json_encode($row, JSON_UNESCAPED_UNICODE);
        check('employee option NEVER contains the real account number', strpos($flat, $PLAIN_EMPLOYEE) === false, true);
        check('employee option carries the masked number', $row['account_no_masked'], EncryptionService::maskAccountNo($PLAIN_EMPLOYEE));
        check('employee option exposes no ciphertext/key_version', !array_key_exists('bank_account_no', $row) && !array_key_exists('key_version', $row), true);
        check('employee option reports has_bank_account = true', $row['has_bank_account'], true);
        check('employee option carries the account name', $row['account_name'], 'Mask Test');
        check('employee option carries the branch', $row['bank_branch'], 'Test Branch');
    }

    // an employee with no account at all must say so rather than look like one that has one
    $stmt = $pdo->prepare("INSERT INTO `employees` (comp_id, employee_no, name_th, surname_th, name_en, surname_en, employee_status, created_at)
        VALUES (:comp_id, :employee_no, 'ทดสอบ', 'ไม่มีบัญชี', 'Mask', 'NoAccount', 'active', NOW())");
    $stmt->execute([':comp_id' => $compId, ':employee_no' => 'MASKNONE-' . substr((string)time(), -6)]);
    $noAccountId = (int)$pdo->lastInsertId();
    $opts = (new EmployeeModel($pdo))->reportToOptions($compId, null, 'Mask', 1, 20);
    $none = null;
    foreach ($opts['items'] as $item) {
        if ((int)$item['id'] === $noAccountId) {
            $none = $item;
        }
    }
    check('employee with no account is returned too', $none !== null, true);
    if ($none) {
        check('employee with no account reports has_bank_account = false', $none['has_bank_account'], false);
        check('employee with no account has a null masked number', $none['account_no_masked'], null);
    }

    // ------------------------------------------------------------ 2. company bank account picker
    $items = (new PayrollCycleModel($pdo))->bankAccountOptions($compId, 'Mask Co', 1, 20)['items'];
    $co = null;
    foreach ($items as $item) {
        if ((int)$item['id'] === $bankAccountId) {
            $co = $item;
        }
    }
    check('company account option row found', $co !== null, true);
    if ($co) {
        $flat = json_encode($co, JSON_UNESCAPED_UNICODE);
        check('company account option NEVER contains the real account number', strpos($flat, $PLAIN_COMPANY) === false, true);
        check('company account option carries the masked number', $co['account_no_masked'], EncryptionService::maskAccountNo($PLAIN_COMPANY));
        check('company account label is "bank <dot> masked (name)"', $co['text_en'], $bankNameEn . ' ' . "\u{2022}" . ' ' . EncryptionService::maskAccountNo($PLAIN_COMPANY) . ' (Mask Co Account)');
        check('company account option exposes is_default', $co['is_default'], true);
        check('company account option exposes no ciphertext/key_version', !array_key_exists('account_no', $co) && !array_key_exists('key_version', $co), true);
    }

    // ------------------------------------------------------------ 3. saved destination picker
    $rows = (new PaymentDestinationModel($pdo))->listSaved($compId, 'Mask Destination', 20);
    $dest = $rows[0] ?? null;
    check('saved destination row found', $dest !== null, true);
    if ($dest) {
        $flat = json_encode($dest, JSON_UNESCAPED_UNICODE);
        check('saved destination NEVER contains the real account number', strpos($flat, $PLAIN_DESTINATION) === false, true);
        check('saved destination carries the masked number', $dest['account_no_masked'], EncryptionService::maskAccountNo($PLAIN_DESTINATION));
        check('saved destination exposes no ciphertext/key_version', !array_key_exists('account_no', $dest) && !array_key_exists('key_version', $dest), true);
        check('saved destination carries the branch', $dest['bank_branch'], 'Dest Branch');
    }
} finally {
    $pdo->rollBack();
}

// nothing the test created may survive the rollback
$leftover = (int)$pdo->query("SELECT COUNT(*) FROM `employees` WHERE employee_no LIKE 'MASK%'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM `bank_accounts` WHERE account_name = 'Mask Co Account'")->fetchColumn()
    + (int)$pdo->query("SELECT COUNT(*) FROM `payment_destinations` WHERE account_name = 'Mask Destination'")->fetchColumn();
check('every fixture row was rolled back', $leftover, 0);

echo str_repeat('-', 50) . "\n";
echo "Passed: {$passed}, Failed: {$failed}\n";
if ($failed > 0) {
    echo "TESTS FAILED\n";
    exit(1);
}
echo "ALL TESTS PASSED\n";
