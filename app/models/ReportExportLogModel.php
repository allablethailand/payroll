<?php
declare(strict_types=1);
class ReportExportLogModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    public function log(int $compId, string $reportType, string $reportCode, string $fileName, string $format, ?int $periodYear, ?int $periodMonth, ?int $payrollRunId, ?int $userId): int {
        $stmt = $this->db->prepare("INSERT INTO `report_export_logs`
            (comp_id, report_type, report_code, period_year, period_month, payroll_run_id, file_name, format, generated_by)
            VALUES (:comp_id, :report_type, :report_code, :period_year, :period_month, :payroll_run_id, :file_name, :format, :generated_by)");
        $stmt->execute([
            ':comp_id' => $compId,
            ':report_type' => $reportType,
            ':report_code' => $reportCode,
            ':period_year' => $periodYear,
            ':period_month' => $periodMonth,
            ':payroll_run_id' => $payrollRunId,
            ':file_name' => $fileName,
            ':format' => $format,
            ':generated_by' => $userId,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /**
     * @param array $filters optional: report_type, period_year, period_month, payroll_run_id, format
     */
    public function list(int $compId, array $filters = []): array {
        $where = "WHERE l.comp_id = :comp_id";
        $params = [':comp_id' => $compId];
        if (!empty($filters['report_type'])) {
            $where .= " AND l.report_type = :report_type";
            $params[':report_type'] = $filters['report_type'];
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
