<?php
declare(strict_types=1);

/**
 * Origami SSO login receiver. Origami redirects here after login as
 * BASE_URL/auth/?token=...&application=... (the `application` param identifies which
 * m_application row the SSO click came from on Origami's side -- Origami's own internal
 * client-auth-api.php variants use it to look up per-app credentials dynamically, but a
 * registered *external* client app like this one authenticates with its own fixed
 * O_CLIENT_ID/O_CLIENT_SECRET instead (same pattern as vonconnect/admin/auth/index.php and
 * Origami's own api/academy|robusta|store/client-auth-api.php), so `application` is not
 * used to select credentials here -- only `token` drives the handshake.
 *
 * Flow: sign a JWT request with O_CLIENT_ID/O_CLIENT_SECRET, POST it to Origami's
 * /api/oauth/v2/auth, decode the JWT response, then map the returned identity onto this
 * app's own `companies`/`employees` rows. Origami only ever hands back a one-way SHA256 hash
 * of its internal numeric IDs (`user_key`/`company.comp_key`), never the raw ID -- so a row
 * already linked via a real Master Data Sync (which does have the raw ID) matches through
 * `SHA2(ref_id/origami_ref_id, 256) = ...` exactly like Origami's own client-auth-api.php
 * reverses it. But since Origami's SSO payload alone never gives us the raw ID, a row that
 * doesn't exist yet gets auto-provisioned here using ONLY identity fields Origami verified
 * (name, role, company name) -- matched from then on via a separate hash column
 * (`origami_sso_comp_key`/`origami_sso_user_key`) since `ref_id`/`origami_ref_id` still can't
 * be populated. Origami's payload carries zero payroll-relevant data (salary, tax, employment
 * type, registered_country, tax ID, ...), so auto-provisioned rows are explicitly marked
 * incomplete and gated elsewhere rather than silently used for real payroll math:
 *   - company: `setup_status = 'draft'` blocks PayrollRunModel::create() until Company
 *     Profile is saved with real registered_country/global_tax_id/etc.
 *   - employee: `is_payroll_ready = 0` excludes them from PayrollRunModel::recalculate()
 *     until a real EmployeeModel::save() (which validates all required fields) completes
 *     their profile.
 * Login itself is never blocked by draft/not-ready state -- someone has to be able to log in
 * to go complete it.
 */

ini_set('session.cookie_httponly', '1');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', '1');
}
ini_set('session.cookie_samesite', 'Lax');
session_start();

require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/core/Database.php';
require_once __DIR__ . '/../app/services/OrigamiSsoJwt.php';
require_once __DIR__ . '/../app/models/PayrollEarningDeductionTypeModel.php';

function origami_sso_fail(string $message): void {
    http_response_code(401);
    $originHref = htmlspecialchars(ORIGAMI_BASE_URL !== '' ? ORIGAMI_BASE_URL : '/', ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
    <!doctype html>
    <html lang="th">
    <head>
    <meta charset="utf-8">
    <title>เข้าสู่ระบบไม่สำเร็จ</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #1a1a2e; color: #fff; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .box { background: rgba(255,255,255,.08); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,.15); border-radius: 16px; padding: 40px; max-width: 420px; text-align: center; }
        h1 { color: #FF9900; font-size: 1.4em; margin: 0 0 12px; }
        p { opacity: .85; line-height: 1.6; margin: 0; }
        a { display: inline-block; margin-top: 20px; background: #FF9900; color: #1a1a2e; text-decoration: none; padding: 10px 24px; border-radius: 8px; font-weight: 600; }
    </style>
    </head>
    <body>
        <div class="box">
            <h1>เข้าสู่ระบบไม่สำเร็จ</h1>
            <p>{$safeMessage}</p>
            <a href="{$originHref}">กลับไปหน้า Origami</a>
        </div>
    </body>
    </html>
    HTML;
    exit;
}

$token = trim((string)($_REQUEST['token'] ?? ''));
if ($token === '') {
    origami_sso_fail('ไม่พบ token สำหรับเข้าสู่ระบบ กรุณาเข้าใช้งานผ่านลิงก์จาก Origami อีกครั้ง');
}

if (O_CLIENT_ID === '' || O_CLIENT_SECRET === '' || ORIGAMI_BASE_URL === '') {
    origami_sso_fail('ระบบยังไม่ได้ตั้งค่าการเชื่อมต่อ Origami SSO (O_CLIENT_ID / O_CLIENT_SECRET / ORIGAMI_BASE_URL ใน .env)');
}

// Password derivation and Basic-Auth-with-JWT-encoded-password scheme match Origami's own
// api/oauth/v2/auth.php exactly -- it is not a generic OAuth flow, it's this specific handshake.
$password = hash('sha256', md5(O_CLIENT_SECRET));

$requestPayload = [
    'emp_user' => '',
    'emp_pass' => '',
    'token'    => $token,
];
$encodedPassword = OrigamiSsoJwt::encode(['password' => $password], $password);
$body = OrigamiSsoJwt::encode(json_encode($requestPayload), $password);

$ch = curl_init(ORIGAMI_BASE_URL . '/api/oauth/v2/auth');
curl_setopt_array($ch, [
    // Matches the reference implementation (origami.local runs on plain http/self-signed
    // certs in this environment) -- do not point ORIGAMI_BASE_URL at an untrusted host.
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_CUSTOMREQUEST  => 'POST',
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
    CURLOPT_USERPWD        => O_CLIENT_ID . ':' . $encodedPassword,
    CURLOPT_HTTPHEADER     => [
        'Cache-Control: no-cache',
        'Content-Type: application/json',
        'Content-Length: ' . strlen($body),
    ],
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
]);
$response = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $response === '') {
    origami_sso_fail('ไม่สามารถเชื่อมต่อ Origami ได้: ' . $curlError);
}

try {
    $authJson = OrigamiSsoJwt::decode($response, $password, false);
    // Origami's own api/oauth/v2/auth.php double-JSON-encodes its response: it calls
    // json_encode($array) first, then passes that *string* into JWT::encode(), which
    // json_encode()s it again internally. So the decoded JWT payload is a JSON string
    // *literal* (escaped quotes and all) containing the real JSON array as text, not the
    // array itself -- json_decode() once yields a PHP string, not the array. Decode twice;
    // if some other Origami deployment doesn't double-encode, the first decode already
    // yields the array/object and the second decode is skipped.
    $result = json_decode($authJson);
    if (is_string($result)) {
        $result = json_decode($result);
    }
} catch (Throwable $e) {
    origami_sso_fail('ไม่สามารถถอดรหัสข้อมูลยืนยันตัวตนจาก Origami ได้');
}
if (!is_array($result) || !isset($result[0]) || ($result[0]->status ?? null) !== '1') {
    $message = $result[0]->message ?? 'Token ไม่ถูกต้องหรือหมดอายุ กรุณาเข้าสู่ระบบใหม่อีกครั้ง';
    origami_sso_fail((string)$message);
}

$info = $result[0]->info ?? null;
$userKey = $info->user_key ?? '';
$companyKey = $info->company->comp_key ?? '';

if ($info === null || $userKey === '' || $companyKey === '') {
    origami_sso_fail('ข้อมูลผู้ใช้จาก Origami ไม่สมบูรณ์');
}

$pdo = Database::getInstance()->pdo;
$companyName = (string)($info->company->company_name ?? 'Unknown Company');
$firstName = (string)($info->firstname ?? 'Origami');
$lastName = (string)($info->lastname ?? 'User');

$companyStmt = $pdo->prepare(
    "SELECT id FROM companies
     WHERE (ref_id IS NOT NULL AND SHA2(ref_id, 256) = :comp_key) OR origami_sso_comp_key = :comp_key
     LIMIT 1"
);
$companyStmt->execute([':comp_key' => $companyKey]);
$company = $companyStmt->fetch();
if (!$company) {
    // Auto-provision: identity-only, real registered_country/global_tax_id/etc are unknown --
    // 'XX' is a deliberately invalid country code so no statutory/country-specific code path
    // can mistake this for a real TH/SG/MY/US company. setup_status='draft' blocks payroll
    // runs (see PayrollRunModel::create()) until Company Profile is saved with real values.
    try {
        $insertCompany = $pdo->prepare(
            "INSERT INTO companies
                (company_legal_name, local_name, registered_country, global_tax_id,
                 address_line_1, authorized_signatory_name, origami_sso_comp_key, setup_status)
             VALUES (:legal_name, :local_name, 'XX', 'PENDING', 'PENDING', 'PENDING', :comp_key, 'draft')"
        );
        $insertCompany->execute([':legal_name' => $companyName, ':local_name' => $companyName, ':comp_key' => $companyKey]);
        $company = ['id' => (int)$pdo->lastInsertId()];
        // Give every brand-new company the system's starter set of common earning/deduction
        // items (per explicit request, 2026-08-19) so Payroll Configuration isn't a totally blank
        // slate on day one -- still just a starting point (is_sync_only stays 0), addable to and
        // deletable like any other row. Best-effort: a seeding failure here must never block
        // account creation itself.
        try {
            (new PayrollEarningDeductionTypeModel())->seedDefaults($company['id'], null);
        } catch (Throwable $e) {
            // Swallowed on purpose -- see comment above.
        }
    } catch (PDOException $e) {
        // Race: another concurrent first-login for the same company already inserted it.
        $companyStmt->execute([':comp_key' => $companyKey]);
        $company = $companyStmt->fetch();
        if (!$company) {
            origami_sso_fail('ไม่สามารถสร้างข้อมูลบริษัทอัตโนมัติได้ กรุณาติดต่อผู้ดูแลระบบ');
        }
    }
}

$employeeStmt = $pdo->prepare(
    "SELECT id FROM employees
     WHERE comp_id = :comp_id AND deleted_at IS NULL
       AND ( (origami_ref_id IS NOT NULL AND SHA2(origami_ref_id, 256) = :user_key)
             OR origami_sso_user_key = :user_key )
     LIMIT 1"
);
$employeeStmt->execute([':comp_id' => $company['id'], ':user_key' => $userKey]);
$employee = $employeeStmt->fetch();
if (!$employee) {
    // Auto-provision: Origami's SSO payload only ever gives us name/role, never salary, tax,
    // employment type, bank details, etc. -- is_payroll_ready=0 keeps this placeholder data out
    // of PayrollRunModel::recalculate() until HR completes the profile via the normal Employee
    // edit form (EmployeeModel::save() flips it back to 1 once required fields are all real).
    $employeeNo = 'SSO-' . strtoupper(substr($userKey, 0, 10));
    $placeholderEmail = 'sso-pending-' . substr($userKey, 0, 16) . '@placeholder.local';
    try {
        $insertEmployee = $pdo->prepare(
            "INSERT INTO employees
                (comp_id, origami_sso_user_key, data_source, is_payroll_ready, employee_no,
                 employee_type, employee_status, title, gender, name_th, surname_th, name_en, surname_en,
                 date_of_birth, nationality, personal_email, mobile_no,
                 address_line_1_register, address_line_1_contact,
                 emergency_name, emergency_surname, emergency_relationship, emergency_mobile,
                 employment_date, employment_status, employment_type, workforce_type, record_time_method,
                 payment_type, salary_type, salary_effective_date, tax_calculation_method)
             VALUES
                (:comp_id, :user_key, 'manual', 0, :employee_no,
                 'domestic', 'active', 'mr', 'male', :name_th, :surname_th, :name_en, :surname_en,
                 '1900-01-01', 'Unknown', :email, '0000000000',
                 'PENDING', 'PENDING',
                 'PENDING', 'PENDING', 'PENDING', '0000000000',
                 CURDATE(), 'probation', 'full_time', 'office', 'none',
                 'cash', 'monthly', CURDATE(), 'average')"
        );
        $insertEmployee->execute([
            ':comp_id' => $company['id'], ':user_key' => $userKey, ':employee_no' => $employeeNo,
            ':name_th' => $firstName, ':surname_th' => $lastName, ':name_en' => $firstName, ':surname_en' => $lastName,
            ':email' => $placeholderEmail,
        ]);
        $employee = ['id' => (int)$pdo->lastInsertId()];
    } catch (PDOException $e) {
        // Race: another concurrent first-login for the same person already inserted it.
        $employeeStmt->execute([':comp_id' => $company['id'], ':user_key' => $userKey]);
        $employee = $employeeStmt->fetch();
        if (!$employee) {
            origami_sso_fail('ไม่สามารถสร้างข้อมูลพนักงานอัตโนมัติได้ กรุณาติดต่อผู้ดูแลระบบ');
        }
    }
}

// Origami's own role name is not the same concept as this app's structure_roles/role_id --
// there is no real RBAC-derived session role anywhere in this codebase yet (see
// PermissionModel's docblock), 'admin' is purely a bypass-everything flag. Default new SSO
// sessions to the non-bypassing 'user' role and only grant the bypass when Origami explicitly
// reports an admin-equivalent role, per least-privilege -- revisit once real role mapping exists.
$origamiRole = strtolower((string)($info->role ?? ''));
$role = in_array($origamiRole, ['admin', 'administrator', 'superadmin'], true) ? 'admin' : 'user';

session_regenerate_id(true);
$_SESSION['user'] = [
    'employee_id' => (int)$employee['id'],
    'company_id'  => (int)$company['id'],
    'role'        => $role,
];

// App switcher (header "Origami Hub" icon): Origami's own header.php/switch_app.php reuses
// $_SESSION['auth_json'] from ITS OWN same-origin session -- payroll is a separate origin with
// its own session, so it can't read that. But this same OAuth response already gives us
// everything needed to reimplement it: a `token` (reusable against /api/oauth/v2/switch exactly
// like a login token) and an `app` array whose `app_key` values are signed with OUR OWN app
// secret (Origami's auth.php signs them with the *calling* app's password hash). `app_active`
// marks the app matching who we authenticated as (Payroll itself), filtered out in the view --
// no point "switching" to the app you're already in.
$_SESSION['origami_switch_token'] = (string)($result[0]->token ?? '');
$_SESSION['origami_apps'] = json_decode(json_encode($result[0]->app ?? []), true);

header('Location: ' . rtrim(BASE_URL, '/') . '/');
exit;
