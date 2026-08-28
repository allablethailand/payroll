<?php
declare(strict_types=1);

/**
 * Unified, read-only "document delivery/issuance history" view -- 2026-08-26, explicit follow-up
 * on the new Employment Certificate Request flow: "ช่องประวัติการส่งปรับ Filter ให้เหมือนหน้าพนักงาน และมี
 * เพิ่มประเภทเอกสารที่ส่งด้วยครับ" (make the Delivery Log's filter look like the Employee page's, and add
 * a document-type filter). Confirmed via AskUserQuestion: this means genuinely folding Employment
 * Certificate issuance INTO the log (not just adding a filter that would only ever have one real
 * value), since `payslip_delivery_logs` only ever logged payslip sends before this.
 *
 * Deliberately a NEW model/table union rather than widening `payslip_delivery_logs` itself --
 * `payslip_delivery_logs.run_id` is `NOT NULL` and the table's whole shape (channel_code/recipient,
 * a real "send" attempt via `PayslipDeliveryService`) doesn't fit an Employment Certificate issuance
 * (no channel, no recipient address, no retry-the-fallback-chain concept, generated once at approval
 * time -- see `EmploymentCertificateRequestModel`'s own docblock). A `UNION ALL` over the two source
 * tables here keeps BOTH of those models/tables completely untouched and gives one consistent read
 * shape for the UI, the same "combine at the read layer, don't force a shared write schema" approach
 * `PayrollReportDataModel` already uses for the Reports module's own varied sources.
 *
 * Row shape returned by list(): document_type ('payslip'/'employment_certificate'), id (the row's
 * own id in ITS source table -- a payslip_delivery_logs.id or an employment_certificate_requests.id,
 * never a shared/synthetic id -- callers must branch on document_type to know which action endpoint
 * an id belongs to), employee_no/employee_name_th/employee_name_en, channel_code/recipient (payslip
 * only, NULL for certificates), status ('success'/'failed', already normalized from
 * employment_certificate_requests' own 'issued'/'issue_failed'), sent_at, sent_by_name_th/en
 * (payslip only -- a certificate is auto-issued by the system on approval, there is no "who sent it"
 * person), source ('auto'/'request' for payslip, always 'request' for a certificate -- there is no
 * auto-issuance path), reference_label/period_start_date/period_end_date (payslip's pay period,
 * NULL for a certificate), language ('th'/'en', certificate only, NULL for payslip).
 */
class DocumentDeliveryLogModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** @param array $filters optional: document_type, status, channel_code, source */
    public function list(int $compId, array $filters = []): array {
        $where = "WHERE 1=1";
        $params = [':comp_id1' => $compId, ':comp_id2' => $compId];
        if (!empty($filters['document_type'])) {
            $where .= " AND x.document_type = :document_type";
            $params[':document_type'] = $filters['document_type'];
        }
        if (!empty($filters['status'])) {
            $where .= " AND x.status = :status";
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['channel_code'])) {
            $where .= " AND x.channel_code = :channel_code";
            $params[':channel_code'] = $filters['channel_code'];
        }
        if (!empty($filters['source'])) {
            $where .= " AND x.source = :source";
            $params[':source'] = $filters['source'];
        }
        $sql = "SELECT x.*,
                    e.employee_no, CONCAT(e.name_th, ' ', e.surname_th) AS employee_name_th, CONCAT(e.name_en, ' ', e.surname_en) AS employee_name_en,
                    CONCAT(u.name_th, ' ', u.surname_th) AS sent_by_name_th, CONCAT(u.name_en, ' ', u.surname_en) AS sent_by_name_en
                FROM (
                    SELECT
                        'payslip' AS document_type, l.id AS id, l.employee_id,
                        l.channel_code, l.recipient, l.status, l.sent_at, l.sent_by, l.source,
                        r.run_name AS reference_label, r.period_start_date, r.period_end_date,
                        NULL AS language
                    FROM `payslip_delivery_logs` l
                    JOIN `payroll_runs` r ON r.id = l.run_id
                    WHERE l.comp_id = :comp_id1

                    UNION ALL

                    SELECT
                        'employment_certificate' AS document_type, ecr.id AS id, ecr.employee_id,
                        NULL AS channel_code, NULL AS recipient,
                        CASE ecr.status WHEN 'issued' THEN 'success' ELSE 'failed' END AS status,
                        ecr.updated_at AS sent_at, NULL AS sent_by, 'request' AS source,
                        NULL AS reference_label, NULL AS period_start_date, NULL AS period_end_date,
                        ecr.language AS language
                    FROM `employment_certificate_requests` ecr
                    WHERE ecr.comp_id = :comp_id2 AND ecr.status IN ('issued', 'issue_failed')
                ) x
                JOIN `employees` e ON e.id = x.employee_id
                LEFT JOIN `employees` u ON u.id = x.sent_by
                {$where}
                ORDER BY x.sent_at DESC LIMIT 200";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
