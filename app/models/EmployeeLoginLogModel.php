<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/Database.php';

/**
 * 2026-08-29, explicit request: "ต้องการอีก Tab ใน Employee เพื่อดูประวัติการเข้าใช้งานระบบโดยแสดงข้อมูล
 * แบบละเอียดตามที่เก็บ ตอนนี้เก็บ ip location timezone อุปกรณ์ version อุปกรณ์ เบราเซอร์ ครบไหม ถ้ายังไม่ครบ
 * ให้เก็บเพิ่มครับ และสามารถ Filter ได้" -- checked first (no guessing): no login/session audit table
 * existed anywhere in this schema before now (confirmed via SHOW TABLES). `employee_login_logs`
 * (see its own migration's header comment) captures one row per successful login through
 * auth/index.php's SSO handshake -- ip_address, device_type/os_name/os_version/browser_name/
 * browser_version (parsed from User-Agent via parseUserAgent(), no new composer dependency),
 * location_city/location_country (best-effort IP geolocation, see create()'s own docblock), and
 * `user_agent` (the raw string, kept alongside the parsed fields so a parsing miss can be
 * re-diagnosed later without having lost the source data). `timezone` is NOT captured here -- the
 * server has no way to know a browser's IANA timezone from the initial HTTP request alone (no
 * standard header carries it) -- see recordTimezone()'s own docblock for the second-step capture.
 *
 * Append-only, no soft delete: a login record is a historical audit fact, never edited/deleted by
 * anyone through this app, so there's deliberately no `status`/`deleted_at` on this table at all
 * (unlike most other tables in this project) -- CompanyProfileModel::paginateData()'s generic
 * helper assumes both exist, so it's not reused here; list() below is its own small query instead.
 */
class EmployeeLoginLogModel {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }

    /**
     * Best-effort device/OS/browser parse from a raw User-Agent string. Deliberately simple regex
     * matching (no composer dependency) covering the realistic real-world set for this app's own
     * users -- good enough for an audit trail read by an admin, not a marketing analytics tool that
     * needs to distinguish every device model. Order matters: Edge/OPR must be checked BEFORE
     * Chrome/Safari (both embed "Chrome"/"Safari" tokens in their own UA string), same well-known
     * UA-sniffing gotcha every hand-rolled parser has to account for.
     */
    public static function parseUserAgent(string $ua): array {
        $result = ['device_type' => 'unknown', 'os_name' => null, 'os_version' => null, 'browser_name' => null, 'browser_version' => null];
        if ($ua === '') {
            return $result;
        }
        if (preg_match('/bot|crawl|spider|slurp/i', $ua)) {
            $result['device_type'] = 'bot';
        } elseif (preg_match('/tablet|ipad/i', $ua)) {
            $result['device_type'] = 'tablet';
        } elseif (preg_match('/mobile|android|iphone/i', $ua)) {
            $result['device_type'] = 'mobile';
        } else {
            $result['device_type'] = 'desktop';
        }

        if (preg_match('/Windows NT ([\d.]+)/i', $ua, $m)) {
            $winVersions = ['10.0' => '10/11', '6.3' => '8.1', '6.2' => '8', '6.1' => '7'];
            $result['os_name'] = 'Windows';
            $result['os_version'] = $winVersions[$m[1]] ?? $m[1];
        } elseif (preg_match('/Mac OS X ([\d_]+)/i', $ua, $m)) {
            $result['os_name'] = 'macOS';
            $result['os_version'] = str_replace('_', '.', $m[1]);
        } elseif (preg_match('/Android ([\d.]+)/i', $ua, $m)) {
            $result['os_name'] = 'Android';
            $result['os_version'] = $m[1];
        } elseif (preg_match('/OS ([\d_]+) like Mac OS X/i', $ua, $m)) {
            $result['os_name'] = 'iOS';
            $result['os_version'] = str_replace('_', '.', $m[1]);
        } elseif (preg_match('/Linux/i', $ua)) {
            $result['os_name'] = 'Linux';
        }

        if (preg_match('/Edg\/([\d.]+)/i', $ua, $m)) {
            $result['browser_name'] = 'Edge';
            $result['browser_version'] = $m[1];
        } elseif (preg_match('/OPR\/([\d.]+)/i', $ua, $m)) {
            $result['browser_name'] = 'Opera';
            $result['browser_version'] = $m[1];
        } elseif (preg_match('/Firefox\/([\d.]+)/i', $ua, $m)) {
            $result['browser_name'] = 'Firefox';
            $result['browser_version'] = $m[1];
        } elseif (preg_match('/CriOS\/([\d.]+)/i', $ua, $m)) {
            $result['browser_name'] = 'Chrome';
            $result['browser_version'] = $m[1];
        } elseif (preg_match('/Chrome\/([\d.]+)/i', $ua, $m)) {
            $result['browser_name'] = 'Chrome';
            $result['browser_version'] = $m[1];
        } elseif (preg_match('/Version\/([\d.]+).*Safari/i', $ua, $m)) {
            $result['browser_name'] = 'Safari';
            $result['browser_version'] = $m[1];
        }
        return $result;
    }

    /**
     * Best-effort IP -> city/country resolution (2026-08-29, confirmed via AskUserQuestion: automatic
     * resolution, not just storing the raw IP). Uses ip-api.com's free JSON endpoint (no API key,
     * generous enough for this app's own login volume) -- wrapped in its own try/catch with a short
     * timeout and NEVER allowed to fail/slow down create()'s own caller (auth/index.php's login
     * flow): a geolocation outage must never block a real login. A private/loopback IP (dev
     * environment, internal network) can't resolve to a real location at all -- skipped up front
     * rather than wasting a request on it.
     */
    private function resolveLocation(string $ip): array {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['city' => null, 'country' => null];
        }
        try {
            $ctx = stream_context_create(['http' => ['timeout' => 2]]);
            $raw = @file_get_contents('http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,city', false, $ctx);
            if ($raw === false) {
                return ['city' => null, 'country' => null];
            }
            $json = json_decode($raw, true);
            if (!is_array($json) || ($json['status'] ?? '') !== 'success') {
                return ['city' => null, 'country' => null];
            }
            return ['city' => $json['city'] ?? null, 'country' => $json['country'] ?? null];
        } catch (Throwable $e) {
            return ['city' => null, 'country' => null];
        }
    }

    /**
     * Called from auth/index.php right after a successful SSO login establishes $_SESSION['user'].
     * Returns the new row's id so the caller can stash it (session) for recordTimezone() to find
     * later in the SAME browser session, without needing any other lookup key.
     *
     * 2026-08-30, Phase 7 T037 ("1 User 1 Login...ให้ session เก่าหลุดออกทันที"): every OTHER row
     * still `is_active=1` for this SAME employee is deactivated FIRST, in the SAME transaction as
     * the new row's insert -- this is the entire mechanism a duplicate/second login uses to kick
     * every earlier session out. The kicked session(s) don't learn about this instantly (there is
     * no push channel to an open browser tab in this app) -- they discover it on their own next
     * request, via ensure_login()'s own per-request `is_active` check (see that function's
     * docblock) or the client-side heartbeat poll (public/js/session-guard.js), whichever comes
     * first -- the standard, practical meaning of "immediately" for a traditional request/response
     * web app with no websocket/SSE infrastructure.
     */
    public function create(int $compId, int $employeeId, string $ipAddress, string $userAgent): int {
        $parsed = self::parseUserAgent($userAgent);
        $location = $this->resolveLocation($ipAddress);
        $own = !$this->db->inTransaction();
        try {
            if ($own) {
                $this->db->beginTransaction();
            }
            $this->db->prepare("UPDATE `employee_login_logs`
                    SET is_active = 0, ended_reason = 'new_login'
                    WHERE employee_id = :employee_id AND is_active = 1")
                ->execute([':employee_id' => $employeeId]);
            $stmt = $this->db->prepare("INSERT INTO `employee_login_logs`
                (comp_id, employee_id, ip_address, location_city, location_country, device_type, os_name, os_version, browser_name, browser_version, user_agent, login_at, is_active)
                VALUES (:comp_id, :employee_id, :ip_address, :location_city, :location_country, :device_type, :os_name, :os_version, :browser_name, :browser_version, :user_agent, CURRENT_TIMESTAMP, 1)");
            $stmt->execute([
                ':comp_id' => $compId, ':employee_id' => $employeeId,
                ':ip_address' => $ipAddress !== '' ? $ipAddress : null,
                ':location_city' => $location['city'], ':location_country' => $location['country'],
                ':device_type' => $parsed['device_type'], ':os_name' => $parsed['os_name'], ':os_version' => $parsed['os_version'],
                ':browser_name' => $parsed['browser_name'], ':browser_version' => $parsed['browser_version'],
                ':user_agent' => $userAgent !== '' ? substr($userAgent, 0, 500) : null,
            ]);
            $newId = (int)$this->db->lastInsertId();
            if ($own) {
                $this->db->commit();
            }
            return $newId;
        } catch (Throwable $e) {
            if ($own) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /** T037: does this login_log_id still represent the currently-valid session for this employee? False once a NEWER login (or an explicit end -- switch/timeout) has superseded it. */
    public function isActive(int $id, int $employeeId): bool {
        $stmt = $this->db->prepare("SELECT is_active FROM `employee_login_logs` WHERE id = :id AND employee_id = :employee_id");
        $stmt->execute([':id' => $id, ':employee_id' => $employeeId]);
        $value = $stmt->fetchColumn();
        return $value !== false && (bool)$value;
    }

    /**
     * T038 (idle timeout) and the generic "end this session for a real reason" path -- sets
     * logout_at/ended_reason/is_active together, scoped to (id, comp_id, employee_id) so one
     * employee's session can never end another's row -- the ONLY guard; deliberately NOT also
     * requiring `is_active = 1` currently (a row already deactivated by a NEWER login for the same
     * employee -- see create()'s own docblock -- can still legitimately record a real logout_at
     * later, e.g. a kicked device's own browser tab eventually triggers Switch App unaware it was
     * already superseded; that's still a real, worth-recording event, last-write-wins on
     * ended_reason if it happens to differ from whatever ended it first).
     */
    public function endSession(int $id, int $compId, int $employeeId, string $reason): void {
        $stmt = $this->db->prepare("UPDATE `employee_login_logs`
            SET is_active = 0, logout_at = CURRENT_TIMESTAMP, ended_reason = :reason
            WHERE id = :id AND comp_id = :comp_id AND employee_id = :employee_id");
        $stmt->execute([':reason' => $reason, ':id' => $id, ':comp_id' => $compId, ':employee_id' => $employeeId]);
    }

    /** Second-step capture -- see this table's own migration comment for why timezone can't be known at create() time. Scoped to comp_id/employee_id (not just the raw id) so one employee's session can never patch another's row. */
    public function recordTimezone(int $id, int $compId, int $employeeId, string $timezone): bool {
        if ($timezone === '' || strlen($timezone) > 64) {
            return false;
        }
        $stmt = $this->db->prepare("UPDATE `employee_login_logs` SET timezone = :tz
            WHERE id = :id AND comp_id = :comp_id AND employee_id = :employee_id");
        $stmt->execute([':tz' => $timezone, ':id' => $id, ':comp_id' => $compId, ':employee_id' => $employeeId]);
        return true;
    }

    /** Called from auth/switch.php right before it destroys the session -- see that file's own
     *  docblock and the logout_at column's own migration comment for why "Switch App away from
     *  Payroll" is this app's own definition of "logout". 2026-08-30: now also flips
     *  is_active=0/ended_reason='switch_app' via endSession() (Phase 7 T037) -- an explicit
     *  app-switch is just as much "this session is over" as a timeout or a new login is. */
    public function recordLogout(int $id, int $compId, int $employeeId): bool {
        $this->endSession($id, $compId, $employeeId, 'switch_app');
        return true;
    }

    /**
     * 2026-09-04, Backlog Phase 10, T058 ("Dashboard shows currently-online users"). A SEPARATE
     * presence signal from `is_active`/`$_SESSION['last_activity']` -- see this table's own
     * `last_seen_at` migration comment for why. Called from helpers.php's ensure_login() on every
     * authenticated request (throttled there, not here -- this method itself is a cheap plain
     * UPDATE, no transaction wrapping needed for a single statement). Only touches a row that is
     * STILL `is_active = 1` -- a session already superseded/ended must never have its last_seen_at
     * revived, or a stale/kicked session could keep showing up in listOnlineForCompany() below.
     */
    public function touchLastSeen(int $id): void {
        $this->db->prepare("UPDATE `employee_login_logs` SET last_seen_at = CURRENT_TIMESTAMP WHERE id = :id AND is_active = 1")
            ->execute([':id' => $id]);
    }

    /**
     * Currently-online employees for the Dashboard widget: a session must be BOTH `is_active = 1`
     * (not superseded/ended) AND have a `last_seen_at` within the last `$windowMinutes` (real,
     * recent presence -- see the `last_seen_at` migration comment for why `is_active` alone isn't
     * enough). `is_active = 1` also guarantees at most one row per employee (create() deactivates
     * every other row for that employee first), so this can never double-list the same person.
     */
    public function listOnlineForCompany(int $compId, int $windowMinutes = 5): array {
        $stmt = $this->db->prepare(
            "SELECT ell.employee_id, ell.last_seen_at, e.employee_no, e.name_th, e.surname_th, e.name_en, e.surname_en, e.profile_photo_path, e.profile_photo_thumbnail_path
             FROM `employee_login_logs` ell
             JOIN `employees` e ON e.id = ell.employee_id
             WHERE ell.comp_id = :comp_id AND ell.is_active = 1 AND ell.last_seen_at IS NOT NULL
               AND ell.last_seen_at >= (NOW() - INTERVAL :window_minutes MINUTE)
             ORDER BY ell.last_seen_at DESC"
        );
        $stmt->bindValue(':comp_id', $compId, PDO::PARAM_INT);
        $stmt->bindValue(':window_minutes', $windowMinutes, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Server-side DataTable list, scoped to one employee (this is a tab ON that employee's own
     * Detail page, never a company-wide log viewer). Filters: date range (login_at), device_type,
     * browser_name, and a free-text search across ip_address/location_city/location_country --
     * matches the "Filter ได้" (must be filterable) half of the explicit request.
     */
    public function list(int $employeeId, int $compId, int $start, int $length, array $filters, string $search, int $colIndex, string $orderDir): array {
        $sortColumns = [0 => 'login_at', 1 => 'device_type', 2 => 'browser_name', 3 => 'os_name'];
        $sortColumn = $sortColumns[$colIndex] ?? 'login_at';
        $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';

        $where = "comp_id = :comp_id AND employee_id = :employee_id";
        $params = [':comp_id' => $compId, ':employee_id' => $employeeId];

        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM `employee_login_logs` WHERE {$where}");
        $totalStmt->execute($params);
        $recordsTotal = (int)$totalStmt->fetchColumn();

        if (!empty($filters['date_from'])) {
            $where .= " AND login_at >= :date_from";
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND login_at <= :date_to";
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['device_type'])) {
            $where .= " AND device_type = :device_type";
            $params[':device_type'] = $filters['device_type'];
        }
        if (!empty($filters['browser_name'])) {
            $where .= " AND browser_name = :browser_name";
            $params[':browser_name'] = $filters['browser_name'];
        }
        if ($search !== '') {
            $where .= " AND (ip_address LIKE :search OR location_city LIKE :search OR location_country LIKE :search)";
            $params[':search'] = "%{$search}%";
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM `employee_login_logs` WHERE {$where}");
        $countStmt->execute($params);
        $recordsFiltered = (int)$countStmt->fetchColumn();

        $dataStmt = $this->db->prepare("SELECT * FROM `employee_login_logs` WHERE {$where}
            ORDER BY `{$sortColumn}` {$orderDir} LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) {
            $dataStmt->bindValue($k, $v);
        }
        $dataStmt->bindValue(':limit', $length, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $start, PDO::PARAM_INT);
        $dataStmt->execute();

        return ['recordsTotal' => $recordsTotal, 'recordsFiltered' => $recordsFiltered, 'data' => $dataStmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** Distinct device_type/browser_name values actually present for this employee, to populate the filter dropdowns without hardcoding a fixed option list. */
    public function distinctFilterValues(int $employeeId, int $compId): array {
        $stmtDevice = $this->db->prepare("SELECT DISTINCT device_type FROM `employee_login_logs` WHERE comp_id = :comp_id AND employee_id = :employee_id AND device_type IS NOT NULL ORDER BY device_type");
        $stmtDevice->execute([':comp_id' => $compId, ':employee_id' => $employeeId]);
        $stmtBrowser = $this->db->prepare("SELECT DISTINCT browser_name FROM `employee_login_logs` WHERE comp_id = :comp_id AND employee_id = :employee_id AND browser_name IS NOT NULL ORDER BY browser_name");
        $stmtBrowser->execute([':comp_id' => $compId, ':employee_id' => $employeeId]);
        return [
            'device_types' => array_column($stmtDevice->fetchAll(PDO::FETCH_ASSOC), 'device_type'),
            'browser_names' => array_column($stmtBrowser->fetchAll(PDO::FETCH_ASSOC), 'browser_name'),
        ];
    }

    /**
     * 2026-08-29, explicit request: "ในหน้า employee list ก็ให้แยกเป็น 2 tab tab employee กับประวัติการเข้าใช้
     * ดูภาพรวมของทุกคน มี Filter ด้วย" -- company-WIDE version of list() above (that one is scoped to a
     * single employee_id, for the Employee Detail page's own tab); this is the Employee List page's
     * new "Login History" tab, an overview across every employee at this company. Same filter set
     * plus an additional employee_id filter (optional -- narrows to one specific person without
     * needing to leave this overview and go to their own Detail tab).
     */
    public function listForCompany(int $compId, int $start, int $length, array $filters, string $search, int $colIndex, string $orderDir): array {
        $sortColumns = [1 => 'ell.login_at', 4 => 'ell.device_type', 5 => 'ell.browser_name', 6 => 'ell.os_name'];
        $sortColumn = $sortColumns[$colIndex] ?? 'ell.login_at';
        $orderDir = strtoupper($orderDir) === 'ASC' ? 'ASC' : 'DESC';

        $joins = "FROM `employee_login_logs` ell JOIN `employees` e ON e.id = ell.employee_id";
        $where = "ell.comp_id = :comp_id";
        $params = [':comp_id' => $compId];

        $totalStmt = $this->db->prepare("SELECT COUNT(*) {$joins} WHERE {$where}");
        $totalStmt->execute($params);
        $recordsTotal = (int)$totalStmt->fetchColumn();

        if (!empty($filters['employee_id'])) {
            $where .= " AND ell.employee_id = :employee_id";
            $params[':employee_id'] = (int)$filters['employee_id'];
        }
        if (!empty($filters['date_from'])) {
            $where .= " AND ell.login_at >= :date_from";
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND ell.login_at <= :date_to";
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['device_type'])) {
            $where .= " AND ell.device_type = :device_type";
            $params[':device_type'] = $filters['device_type'];
        }
        if (!empty($filters['browser_name'])) {
            $where .= " AND ell.browser_name = :browser_name";
            $params[':browser_name'] = $filters['browser_name'];
        }
        if ($search !== '') {
            $where .= " AND (ell.ip_address LIKE :search OR ell.location_city LIKE :search OR ell.location_country LIKE :search OR e.employee_no LIKE :search OR e.name_th LIKE :search OR e.surname_th LIKE :search OR e.name_en LIKE :search OR e.surname_en LIKE :search)";
            $params[':search'] = "%{$search}%";
        }

        $countStmt = $this->db->prepare("SELECT COUNT(*) {$joins} WHERE {$where}");
        $countStmt->execute($params);
        $recordsFiltered = (int)$countStmt->fetchColumn();

        // $sortColumn is table-qualified (e.g. "ell.login_at") -- unlike list() above, it can't be
        // backtick-wrapped as one single identifier (MySQL rejects a dot inside a single backtick
        // pair); safe unquoted here regardless since every value comes from the fixed $sortColumns
        // whitelist above, never from raw user input.
        $dataStmt = $this->db->prepare("SELECT ell.*, e.employee_no, e.name_th, e.surname_th, e.name_en, e.surname_en
            {$joins} WHERE {$where} ORDER BY {$sortColumn} {$orderDir} LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) {
            $dataStmt->bindValue($k, $v);
        }
        $dataStmt->bindValue(':limit', $length, PDO::PARAM_INT);
        $dataStmt->bindValue(':offset', $start, PDO::PARAM_INT);
        $dataStmt->execute();

        return ['recordsTotal' => $recordsTotal, 'recordsFiltered' => $recordsFiltered, 'data' => $dataStmt->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** Company-wide version of distinctFilterValues() above, for the Employee List page's own overview tab. */
    public function distinctFilterValuesForCompany(int $compId): array {
        $stmtDevice = $this->db->prepare("SELECT DISTINCT device_type FROM `employee_login_logs` WHERE comp_id = :comp_id AND device_type IS NOT NULL ORDER BY device_type");
        $stmtDevice->execute([':comp_id' => $compId]);
        $stmtBrowser = $this->db->prepare("SELECT DISTINCT browser_name FROM `employee_login_logs` WHERE comp_id = :comp_id AND browser_name IS NOT NULL ORDER BY browser_name");
        $stmtBrowser->execute([':comp_id' => $compId]);
        return [
            'device_types' => array_column($stmtDevice->fetchAll(PDO::FETCH_ASSOC), 'device_type'),
            'browser_names' => array_column($stmtBrowser->fetchAll(PDO::FETCH_ASSOC), 'browser_name'),
        ];
    }
}
