<?php
declare(strict_types=1);
require_once __DIR__ . '/EmployeeLoginLogModel.php';
class ReportExportLogModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /**
     * 2026-08-29, explicit request: "มีอีกปุ่มเพื่อกดดูประวัติการ Download...โดยใคร Device อะไร IP อะไร
     * เบราเซอร์อะไร และ Download จากที่ไหน" -- $ipAddress/$userAgent (parsed into device_type/
     * browser_name via EmployeeLoginLogModel::parseUserAgent(), same convention that model already
     * established for employee_login_logs -- not reinvented here) are ALWAYS a real download's own
     * request context, never user-suppliable free text. $language is the report's own th/en choice.
     * $source is a short caller-supplied code (e.g. 'process_detail') naming which screen the
     * download was triggered from -- purely descriptive, not used for any access decision.
     */
    public function log(int $compId, string $reportType, string $reportCode, string $fileName, string $format, ?int $periodYear, ?int $periodMonth, ?int $payrollRunId, ?int $userId, ?string $ipAddress = null, ?string $userAgent = null, ?string $language = null, ?string $source = null, ?int $employeeId = null): int {
        $parsed = $userAgent ? EmployeeLoginLogModel::parseUserAgent($userAgent) : ['device_type' => null, 'os_name' => null, 'browser_name' => null, 'browser_version' => null];
        $stmt = $this->db->prepare("INSERT INTO `report_export_logs`
            (comp_id, report_type, report_code, period_year, period_month, payroll_run_id, employee_id, file_name, format, generated_by,
             ip_address, device_type, os_name, browser_name, browser_version, user_agent, language, source)
            VALUES (:comp_id, :report_type, :report_code, :period_year, :period_month, :payroll_run_id, :employee_id, :file_name, :format, :generated_by,
             :ip_address, :device_type, :os_name, :browser_name, :browser_version, :user_agent, :language, :source)");
        $stmt->execute([
            ':comp_id' => $compId,
            ':report_type' => $reportType,
            ':report_code' => $reportCode,
            ':period_year' => $periodYear,
            ':period_month' => $periodMonth,
            ':payroll_run_id' => $payrollRunId,
            ':employee_id' => $employeeId,
            ':file_name' => $fileName,
            ':format' => $format,
            ':generated_by' => $userId,
            ':ip_address' => $ipAddress,
            ':device_type' => $parsed['device_type'],
            ':os_name' => $parsed['os_name'],
            ':browser_name' => $parsed['browser_name'],
            ':browser_version' => $parsed['browser_version'],
            ':user_agent' => $userAgent !== null && $userAgent !== '' ? substr($userAgent, 0, 500) : null,
            ':language' => $language,
            ':source' => $source,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * Per-report_code download summary for one run -- count + most recent generated_at, backing the
     * new "Reports" tab's own table columns (2026-08-29 explicit request: "บอกด้วยว่า Download แล้ว
     * ทั้งหมดกี่ครั้ง ครั้งล่าสุด Download ไปเมื่อไหร่"). Reports never generated for this run at all
     * simply don't appear in the returned map -- the caller (ReportsController::runReportsSummary())
     * fills in a zero/never-downloaded row for those, since $reportCodes there is the authoritative
     * "what's applicable to this run" list, not this table.
     */
    public function summaryForRun(int $compId, int $runId): array {
        $stmt = $this->db->prepare("SELECT report_code, COUNT(*) AS download_count, MAX(generated_at) AS last_downloaded_at
            FROM `report_export_logs` WHERE comp_id = :comp_id AND payroll_run_id = :run_id GROUP BY report_code");
        $stmt->execute([':comp_id' => $compId, ':run_id' => $runId]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['report_code']] = ['download_count' => (int)$row['download_count'], 'last_downloaded_at' => $row['last_downloaded_at']];
        }
        return $result;
    }

    /**
     * 2026-08-29, explicit request: "ตรงที่ปริ้น Slip ของพนักงาน...แสดงด้วยว่า Download ไปแล้วกี่ครั้ง" -- per-
     * EMPLOYEE download summary for one run+report_code, backing the Pay Slip roster picker's own
     * per-row download count (a report scoped to one employee at a time, e.g. PAY_SLIP, has no
     * single "how many times has this run's report been downloaded" figure that means anything --
     * it's meaningful only per employee). Same map shape as summaryForRun() (keyed by employee_id
     * instead of report_code) for the same reason: an employee never downloaded simply doesn't
     * appear, the caller (ReportsController::payslipRoster()) fills in the zero/never-downloaded
     * default for those.
     */
    public function perEmployeeSummaryForRun(int $compId, int $runId, string $reportCode): array {
        $stmt = $this->db->prepare("SELECT employee_id, COUNT(*) AS download_count, MAX(generated_at) AS last_downloaded_at
            FROM `report_export_logs`
            WHERE comp_id = :comp_id AND payroll_run_id = :run_id AND report_code = :report_code AND employee_id IS NOT NULL
            GROUP BY employee_id");
        $stmt->execute([':comp_id' => $compId, ':run_id' => $runId, ':report_code' => $reportCode]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int)$row['employee_id']] = ['download_count' => (int)$row['download_count'], 'last_downloaded_at' => $row['last_downloaded_at']];
        }
        return $result;
    }

    /**
     * 2026-08-30, explicit request: "รายงานประจำปี อยากให้เป็นตารางครับ" -- per-report download summary
     * for one YEAR (Annual Reports' own equivalent of summaryForRun() above, which is per-RUN) --
     * backs the new Annual Reports table's own Downloads/Last Downloaded columns, scoped to
     * whichever year the page's own year filter currently has selected (same "counts follow the
     * picker" convention Per-Cycle Reports already established for its own run picker).
     */
    public function summaryForYear(int $compId, int $periodYear): array {
        $stmt = $this->db->prepare("SELECT report_code, COUNT(*) AS download_count, MAX(generated_at) AS last_downloaded_at
            FROM `report_export_logs` WHERE comp_id = :comp_id AND period_year = :period_year GROUP BY report_code");
        $stmt->execute([':comp_id' => $compId, ':period_year' => $periodYear]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['report_code']] = ['download_count' => (int)$row['download_count'], 'last_downloaded_at' => $row['last_downloaded_at']];
        }
        return $result;
    }

    /**
     * @param array $filters optional: report_type, report_code, period_year, period_month, payroll_run_id, format
     */
    public function list(int $compId, array $filters = []): array {
        $where = "WHERE l.comp_id = :comp_id";
        $params = [':comp_id' => $compId];
        if (!empty($filters['report_type'])) {
            $where .= " AND l.report_type = :report_type";
            $params[':report_type'] = $filters['report_type'];
        }
        // 2026-08-29: backs the new per-run "Reports" tab's own "View Download History" modal, one
        // report_code at a time (payroll_run_id filter below narrows it to just this run too).
        if (!empty($filters['report_code'])) {
            $where .= " AND l.report_code = :report_code";
            $params[':report_code'] = $filters['report_code'];
        }
        if (!empty($filters['period_year'])) {
            $where .= " AND l.period_year = :period_year";
            $params[':period_year'] = (int)$filters['period_year'];
        }
        if (!empty($filters['period_month'])) {
            $where .= " AND l.period_month = :period_month";
            $params[':period_month'] = (int)$filters['period_month'];
        }
        if (!empty($filters['payroll_run_id'])) {
            $where .= " AND l.payroll_run_id = :payroll_run_id";
            $params[':payroll_run_id'] = (int)$filters['payroll_run_id'];
        }
        if (!empty($filters['format'])) {
            $where .= " AND l.format = :format";
            $params[':format'] = $filters['format'];
        }
        // 2026-08-29, same-day follow-up: "ประวัติการ Download ให้เป็น Datatable และ Filter ช่วงวันที่ได้".
        if (!empty($filters['date_from'])) {
            $where .= " AND l.generated_at >= :date_from";
            $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where .= " AND l.generated_at <= :date_to";
            $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
        }
        $sql = "SELECT l.*, e.name_th AS generated_by_name_th, e.name_en AS generated_by_name_en
                FROM `report_export_logs` l
                LEFT JOIN `employees` e ON e.id = l.generated_by
                {$where}
                ORDER BY l.generated_at DESC LIMIT 200";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
