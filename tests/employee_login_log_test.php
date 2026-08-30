<?php
/**
 * Lightweight verification script for EmployeeLoginLogModel (2026-08-29, explicit request: "ต้องการ
 * อีก Tab ใน Employee เพื่อดูประวัติการเข้าใช้งานระบบโดยแสดงข้อมูลแบบละเอียดตามที่เก็บ...และสามารถ Filter
 * ได้"). Not PHPUnit -- see tests/statutory_engine_test.php for why. Runs against the real dev DB
 * inside a transaction that is always rolled back.
 *
 * Uses REAL fixture employees (a new one created here) rather than mocking parseUserAgent()'s
 * input -- the UA strings below are genuine real-world User-Agent strings, not simplified stand-
 * ins, so the regex parsing is exercised the same way it would be against real traffic.
 *
 * resolveLocation()'s outbound HTTP call to ip-api.com is intentionally exercised for real here
 * (via 8.8.8.8, a stable public IP with a well-known geolocation) rather than mocked -- this is a
 * "does the whole pipeline actually work" test, matching this project's own established preference
 * for verifying via real execution over guessing. If this specific assertion becomes flaky due to
 * the free API's own rate limit/downtime, that's a real signal worth knowing about, not something
 * to paper over with a mock.
 *
 * Run with: php tests/employee_login_log_test.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/models/EmployeeLoginLogModel.php';

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
    $compId = 1;
    $model = new EmployeeLoginLogModel();

    $insEmp = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ', 'ล็อกอิน', 'Test', 'Login', '1990-01-01', 'Thai',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', 30000, '2020-01-01', 'average', 'active')");
    $employeeNo = 'ELL_TEST_' . uniqid();
    $insEmp->execute([':comp_id' => $compId, ':employee_no' => $employeeNo]);
    $employeeId = (int)$pdo->lastInsertId();

    echo "=== parseUserAgent() ===\n";
    $chromeWin = EmployeeLoginLogModel::parseUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.6613.120 Safari/537.36');
    check('plain Chrome/Windows UA: device_type', $chromeWin['device_type'], 'desktop');
    check('plain Chrome/Windows UA: os_name', $chromeWin['os_name'], 'Windows');
    check('plain Chrome/Windows UA: browser_name', $chromeWin['browser_name'], 'Chrome');
    check('plain Chrome/Windows UA: browser_version', $chromeWin['browser_version'], '128.0.6613.120');

    $edgeWin = EmployeeLoginLogModel::parseUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36 Edg/128.0.2739.79');
    checkTrue('Edge UA (also carries a Chrome/ token) is detected as Edge, not Chrome -- order-of-check matters', $edgeWin['browser_name'] === 'Edge');
    check('Edge UA browser_version reads the Edg/ segment, not the Chrome/ one', $edgeWin['browser_version'], '128.0.2739.79');

    $iphoneSafari = EmployeeLoginLogModel::parseUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1');
    check('iPhone Safari UA: device_type', $iphoneSafari['device_type'], 'mobile');
    check('iPhone Safari UA: os_name', $iphoneSafari['os_name'], 'iOS');
    check('iPhone Safari UA: os_version (underscores converted to dots)', $iphoneSafari['os_version'], '17.5.1');
    check('iPhone Safari UA: browser_name', $iphoneSafari['browser_name'], 'Safari');

    $androidChrome = EmployeeLoginLogModel::parseUserAgent('Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Mobile Safari/537.36');
    check('Android Chrome UA: device_type', $androidChrome['device_type'], 'mobile');
    check('Android Chrome UA: os_name', $androidChrome['os_name'], 'Android');
    check('Android Chrome UA: os_version', $androidChrome['os_version'], '14');

    $bot = EmployeeLoginLogModel::parseUserAgent('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)');
    check('a crawler UA is classified as device_type=bot', $bot['device_type'], 'bot');

    $empty = EmployeeLoginLogModel::parseUserAgent('');
    check('an empty UA string parses to device_type=unknown without erroring', $empty['device_type'], 'unknown');

    echo "=== create() + resolveLocation() (real outbound geolocation call, private IP short-circuit) ===\n";
    $privateId = $model->create($compId, $employeeId, '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36');
    checkTrue('create() returns a positive new id', $privateId > 0);
    $privateRow = $pdo->query("SELECT * FROM employee_login_logs WHERE id = {$privateId}")->fetch(PDO::FETCH_ASSOC);
    check('a private/loopback IP never reaches the geolocation API -- location_city stays null', $privateRow['location_city'], null);
    check('a private/loopback IP -- location_country stays null too', $privateRow['location_country'], null);
    check('ip_address stored as given', $privateRow['ip_address'], '127.0.0.1');
    check('device_type parsed and persisted', $privateRow['device_type'], 'desktop');
    check('browser_name parsed and persisted', $privateRow['browser_name'], 'Chrome');
    check('timezone is null until recordTimezone() patches it in (2-step capture)', $privateRow['timezone'], null);

    $publicId = $model->create($compId, $employeeId, '8.8.8.8', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36');
    $publicRow = $pdo->query("SELECT * FROM employee_login_logs WHERE id = {$publicId}")->fetch(PDO::FETCH_ASSOC);
    checkTrue('a real public IP resolves a non-empty location_country via the real geolocation call', !empty($publicRow['location_country']));

    echo "=== recordTimezone() ===\n";
    $tzOk = $model->recordTimezone($privateId, $compId, $employeeId, 'Asia/Bangkok');
    checkTrue('recordTimezone() succeeds for the row\'s own owner', $tzOk);
    $afterTz = $pdo->query("SELECT timezone FROM employee_login_logs WHERE id = {$privateId}")->fetchColumn();
    check('timezone persisted', $afterTz, 'Asia/Bangkok');

    // Cross-employee/cross-company guard -- a login-log row belongs to exactly one employee, and
    // the controller trusts employee_id/comp_id from $_SESSION, never from the request body, for
    // exactly this reason (see EmployeeLoginLogController::recordTimezone()'s own docblock).
    $insEmp2 = $pdo->prepare("INSERT INTO `employees`
        (comp_id, employee_no, title, gender, name_th, surname_th, name_en, surname_en, date_of_birth, nationality,
         employment_date, employment_status, employment_type, workforce_type, record_time_method,
         payment_type, salary_type, base_salary_amount, salary_effective_date, tax_calculation_method, employee_status)
        VALUES (:comp_id, :employee_no, 'mr', 'male', 'ทดสอบ2', 'ล็อกอิน2', 'Test2', 'Login2', '1990-01-01', 'Thai',
         '2020-01-01', 'permanent', 'full_time', 'office', 'manual',
         'bank', 'monthly', 30000, '2020-01-01', 'average', 'active')");
    $insEmp2->execute([':comp_id' => $compId, ':employee_no' => 'ELL_TEST2_' . uniqid()]);
    $otherEmployeeId = (int)$pdo->lastInsertId();
    $model->recordTimezone($privateId, $compId, $otherEmployeeId, 'America/New_York');
    $unchangedTz = $pdo->query("SELECT timezone FROM employee_login_logs WHERE id = {$privateId}")->fetchColumn();
    check('recordTimezone() cannot patch a row belonging to a DIFFERENT employee (no row matched, silently no-op)', $unchangedTz, 'Asia/Bangkok');

    $tzTooLong = $model->recordTimezone($privateId, $compId, $employeeId, str_repeat('X', 65));
    check('recordTimezone() rejects a value wider than the column (65 chars)', $tzTooLong, false);

    echo "=== list() -- filters + pagination ===\n";
    $allList = $model->list($employeeId, $compId, 0, 10, [], '', 0, 'desc');
    check('list() returns both fixture rows for this employee', $allList['recordsTotal'], 2);
    check('newest-first ordering (login_at desc): first row is the public-IP one created second', (int)$allList['data'][0]['id'], $publicId);

    $filteredByDevice = $model->list($employeeId, $compId, 0, 10, ['device_type' => 'desktop'], '', 0, 'desc');
    check('device_type filter matches both fixture rows (both are desktop)', $filteredByDevice['recordsFiltered'], 2);
    $filteredByBrowserMiss = $model->list($employeeId, $compId, 0, 10, ['device_type' => 'mobile'], '', 0, 'desc');
    check('device_type filter correctly excludes when nothing matches', $filteredByBrowserMiss['recordsFiltered'], 0);

    $searchByIp = $model->list($employeeId, $compId, 0, 10, [], '8.8.8.8', 0, 'desc');
    check('free-text search matches on ip_address', $searchByIp['recordsFiltered'], 1);
    check('free-text search result is the right row', (int)$searchByIp['data'][0]['id'], $publicId);

    $otherEmployeeList = $model->list($otherEmployeeId, $compId, 0, 10, [], '', 0, 'desc');
    check('list() is scoped per-employee -- a different employee sees zero rows from this fixture', $otherEmployeeList['recordsTotal'], 0);

    echo "=== distinctFilterValues() ===\n";
    $filterOptions = $model->distinctFilterValues($employeeId, $compId);
    check('distinct device_types for this employee', $filterOptions['device_types'], ['desktop']);
    check('distinct browser_names for this employee', $filterOptions['browser_names'], ['Chrome']);
    $otherFilterOptions = $model->distinctFilterValues($otherEmployeeId, $compId);
    check('a different employee with no login rows gets empty filter option lists, not this employee\'s own', $otherFilterOptions, ['device_types' => [], 'browser_names' => []]);

    echo "=== recordLogout() (2026-08-29, \"Logout คือตอน Switch ออกจาก Payroll ไปที่อื่น\") ===\n";
    $beforeLogout = $pdo->query("SELECT logout_at FROM employee_login_logs WHERE id = {$privateId}")->fetchColumn();
    check('logout_at is null until an explicit Switch-App-away logout is recorded', $beforeLogout, null);
    $model->recordLogout($privateId, $compId, $employeeId);
    $afterLogout = $pdo->query("SELECT logout_at FROM employee_login_logs WHERE id = {$privateId}")->fetchColumn();
    checkTrue('logout_at is set after recordLogout()', !empty($afterLogout));
    $model->recordLogout($publicId, $compId, $otherEmployeeId);
    $unchangedLogout = $pdo->query("SELECT logout_at FROM employee_login_logs WHERE id = {$publicId}")->fetchColumn();
    check('recordLogout() cannot patch a row belonging to a DIFFERENT employee (no row matched, silently no-op)', $unchangedLogout, null);

    echo "=== listForCompany() / distinctFilterValuesForCompany() (2026-08-29, Employee List's own company-wide overview tab) ===\n";
    $companyList = $model->listForCompany($compId, 0, 10, [], '', 0, 'desc');
    checkTrue('listForCompany() returns at least both fixture rows (company-wide, real dev DB may have more)', $companyList['recordsTotal'] >= 2);
    $companyListOwnRows = array_values(array_filter($companyList['data'], fn($r) => in_array((int)$r['id'], [$privateId, $publicId], true)));
    check('both fixture rows are present in the company-wide list', count($companyListOwnRows), 2);
    checkTrue('company-wide rows carry the joined employee_no', !empty($companyListOwnRows[0]['employee_no']));

    $companyListByEmployee = $model->listForCompany($compId, 0, 10, ['employee_id' => $employeeId], '', 0, 'desc');
    checkTrue('listForCompany() employee_id filter narrows to just that employee\'s own rows', $companyListByEmployee['recordsFiltered'] >= 1);
    $othersLeakIn = array_filter($companyListByEmployee['data'], fn($r) => (int)$r['employee_id'] !== $employeeId);
    check('employee_id filter never leaks a different employee\'s row', count($othersLeakIn), 0);

    $companySearchByEmployeeNo = $model->listForCompany($compId, 0, 10, [], $employeeNo, 0, 'desc');
    checkTrue('listForCompany() free-text search also matches on the joined employee_no', $companySearchByEmployeeNo['recordsFiltered'] >= 1);

    $companyFilterOptions = $model->distinctFilterValuesForCompany($compId);
    checkTrue('distinctFilterValuesForCompany() includes desktop (this fixture\'s own device_type)', in_array('desktop', $companyFilterOptions['device_types'], true));
    checkTrue('distinctFilterValuesForCompany() includes Chrome (this fixture\'s own browser_name)', in_array('Chrome', $companyFilterOptions['browser_names'], true));

} finally {
    $pdo->rollBack();
}

echo "\n{$passes} passed, {$failures} failed.\n";
exit($failures > 0 ? 1 : 0);
