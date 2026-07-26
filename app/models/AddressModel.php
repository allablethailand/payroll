<?php
class AddressModel { 
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }
    public function searchAddresses($keyword, $country, $lang = 'th', $limit = 10) {
        $isEn = ($lang === 'en');
        $lvl2 = $isEn ? 'level_2_en' : 'level_2_th';
        $lvl3 = $isEn ? 'level_3_en' : 'level_3_th';
        $lvl4 = $isEn ? 'level_4_en' : 'level_4_th';
        $searchPattern = "%" . $keyword . "%";
        $sql = "SELECT 
                    id,
                    CONCAT($lvl4, ' » ', $lvl3, ' » ', $lvl2, ' » ', level_1) AS formatted_text,
                    level_1 AS postcode,
                    $lvl2 AS state,
                    $lvl3 AS city,
                    $lvl4 AS sub_district
                FROM master_addresses
                WHERE country_code = :country AND search_text LIKE :search
                LIMIT :limit";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':country', $country, PDO::PARAM_STR);
        $stmt->bindValue(':search', $searchPattern, PDO::PARAM_STR);
        $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}