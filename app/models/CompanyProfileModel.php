<?php
class CompanyProfileModel { 
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }
    public function get() {
        $companyId = $_SESSION['user']['company_id'] ?? null;
        if (!$companyId) {
            return null;
        }
        $sql = "SELECT c.*, 
                       mc.countries_name_th,
                       mc.countries_name_en,
                       m.level_1 AS postcode,
                       m.level_2_th AS province_th,
                       m.level_3_th AS district_th,
                       m.level_4_th AS sub_district_th,
                       m.level_2_en AS province_en,
                       m.level_3_en AS district_en,
                       m.level_4_en AS sub_district_en
                FROM companies c
                LEFT JOIN master_countries mc ON c.registered_country = mc.countries_code
                LEFT JOIN master_addresses m ON c.master_address_id = m.id
                WHERE c.id = :company_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':company_id' => $companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($company) {
            $addrTh = [];
            if (!empty($company['sub_district_th'])) $addrTh[] = $company['sub_district_th'];
            if (!empty($company['district_th']))     $addrTh[] = $company['district_th'];
            if (!empty($company['province_th']))     $addrTh[] = $company['province_th'];
            if (!empty($company['postcode']))        $addrTh[] = $company['postcode'];
            $company['address_display_th'] = !empty($addrTh) ? implode(' » ', $addrTh) : '';
            $addrEn = [];
            if (!empty($company['sub_district_en'])) $addrEn[] = $company['sub_district_en'];
            if (!empty($company['district_en']))     $addrEn[] = $company['district_en'];
            if (!empty($company['province_en']))     $addrEn[] = $company['province_en'];
            if (!empty($company['postcode']))        $addrEn[] = $company['postcode'];
            $company['address_display_en'] = !empty($addrEn) ? implode(' » ', $addrEn) : '';
            $company['country_data'] = [
                'id' => $company['registered_country'],
                'text_th' => $company['countries_name_th'] ?? $company['registered_country'],
                'text_en' => $company['countries_name_en'] ?? $company['registered_country']
            ];
            if (!empty($company['statutory_data'])) {
                $company['statutory_data'] = json_decode($company['statutory_data'], true);
            } else {
                $company['statutory_data'] = [];
            }
            return $company;
        }
        return null;
    }
    public function save($data) {
        $companyId = $_SESSION['user']['company_id'] ?? null;
        if (!$companyId) {
            return false;
        }
        $stmtCheck = $this->db->prepare("SELECT id FROM companies WHERE id = :company_id");
        $stmtCheck->execute([':company_id' => $companyId]);
        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
        $statutoryJson = null;
        if (isset($data['statutory_data']) && is_array($data['statutory_data'])) {
            $statutoryJson = json_encode($data['statutory_data'], JSON_UNESCAPED_UNICODE);
        }
        if ($existing) {
            $sql = "UPDATE companies SET 
                        company_legal_name = :company_legal_name,
                        local_name = :local_name,
                        registered_country = :registered_country,
                        global_tax_id = :global_tax_id,
                        address_line_1 = :address_line_1,
                        address_line_2 = :address_line_2,
                        master_address_id = :master_address_id,
                        statutory_data = :statutory_data,
                        authorized_signatory_name = :authorized_signatory_name,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':id' => $companyId,
                ':company_legal_name' => $data['company_legal_name'] ?? null,
                ':local_name' => $data['local_name'] ?? null,
                ':registered_country' => $data['registered_country'] ?? null,
                ':global_tax_id' => $data['global_tax_id'] ?? null,
                ':address_line_1' => $data['address_line_1'] ?? null,
                ':address_line_2' => !empty($data['address_line_2']) ? $data['address_line_2'] : null,
                ':master_address_id' => !empty($data['master_address_id']) ? (int)$data['master_address_id'] : null,
                ':statutory_data' => $statutoryJson,
                ':authorized_signatory_name' => $data['authorized_signatory_name'] ?? null
            ]);
        } 
    }
}