<?php
class CompanyModel { 
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }
    Public function getCountryMetadata($countryCode) {
        $sql = "SELECT tax_label_i18n, form_schema FROM payroll_country_metadata WHERE country_code = :country_code";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':country_code' => $countryCode]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $result['tax_label_i18n'] = json_decode($result['tax_label_i18n'], true);
            $result['form_schema'] = json_decode($result['form_schema'], true);
            return $result;
        }
        Return null;
    }
    Public function saveCompany($data) {
        $sql = "INSERT INTO companies (
                    Company_name, registered_country, tax_id_global, 
                    Address_line1, address_line2, base_currency, 
                    Timezone, authorized_signatory, local_statutory_data
                ) VALUES (
                    :company_name, :registered_country, :tax_id_global, 
                    :address_line1, :address_line2, :base_currency, 
                    :timezone, :authorized_signatory, :local_statutory_data
                )";
        $stmt = $this->db->prepare($sql);
        Return $stmt->execute([
            ':company_name'       => $data['company_name'],
            ':registered_country' => $data['registered_country'],
            ':tax_id_global'      => $data['tax_id_global'],
            ':address_line1'      => $data['address_line1'],
            ':address_line2'      => $data['address_line2'],
            ':base_currency'      => $data['base_currency'],
            ':timezone'           => $data['timezone'],
            ':authorized_signatory' => $data['authorized_signatory'],
            ':local_statutory_data' => json_encode($data['local_statutory_data'])
        ]);
    }
}