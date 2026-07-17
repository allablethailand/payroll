<?php
class MasterModel { 
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }
    public function master($page = 1, $limit = 10, $type = '', $searchTerm = '') {
        $offset = ($page - 1) * $limit;
        $items = [];
        $totalCount = 0;
        $pdo = $this->db;
        switch($type) {
            case 'country':
                $where = " WHERE is_active = 'active' ";
                $params = [];
                if (!empty($searchTerm)) {
                    $where .= " AND (countries_name_th LIKE :search OR countries_name_en LIKE :search) ";
                    $params[':search'] = '%' . $searchTerm . '%';
                }
                $sqlTotal = "SELECT COUNT(*) FROM master_countries" . $where;
                $stmtTotal = $pdo->prepare($sqlTotal);
                $stmtTotal->execute($params);
                $totalCount = $stmtTotal->fetchColumn();
                $sql = "SELECT countries_code as id, countries_name_th as text_th, countries_name_en as text_en FROM master_countries" . $where . " ORDER BY countries_id asc LIMIT :offset, :limit";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
                $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
                foreach ($params as $key => $val) $stmt->bindValue($key, $val);
                $stmt->execute();
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
        }
        return [
            'items' => $items,
            'total_count' => $totalCount
        ];
    }
}