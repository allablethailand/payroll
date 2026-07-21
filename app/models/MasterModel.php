<?php
declare(strict_types=1);
class MasterModel {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }
    public function master(int $page = 1, int $limit = 10, string $type = '', string $searchTerm = '', ?int $compId = null): array {
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
            case 'nationality':
                $where = " WHERE is_active = 1 ";
                $params = [];
                if (!empty($searchTerm)) {
                    $where .= " AND (nationality_name_th LIKE :search OR nationality_name_en LIKE :search) ";
                    $params[':search'] = '%' . $searchTerm . '%';
                }
                $sqlTotal = "SELECT COUNT(*) FROM master_nationalities" . $where;
                $stmtTotal = $pdo->prepare($sqlTotal);
                $stmtTotal->execute($params);
                $totalCount = $stmtTotal->fetchColumn();
                $sql = "SELECT nationality_code as id, nationality_name_th as text_th, nationality_name_en as text_en FROM master_nationalities" . $where . " ORDER BY nationality_name_en asc LIMIT :offset, :limit";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
                $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
                foreach ($params as $key => $val) $stmt->bindValue($key, $val);
                $stmt->execute();
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
            case 'religion':
                $where = " WHERE is_active = 1 ";
                $params = [];
                if (!empty($searchTerm)) {
                    $where .= " AND (religion_name_th LIKE :search OR religion_name_en LIKE :search) ";
                    $params[':search'] = '%' . $searchTerm . '%';
                }
                $sqlTotal = "SELECT COUNT(*) FROM master_religions" . $where;
                $stmtTotal = $pdo->prepare($sqlTotal);
                $stmtTotal->execute($params);
                $totalCount = $stmtTotal->fetchColumn();
                $sql = "SELECT religion_code as id, religion_name_th as text_th, religion_name_en as text_en FROM master_religions" . $where . " ORDER BY id asc LIMIT :offset, :limit";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
                $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
                foreach ($params as $key => $val) $stmt->bindValue($key, $val);
                $stmt->execute();
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
            case 'bank':
                $where = " WHERE is_active = 1 ";
                $params = [];
                if (!empty($searchTerm)) {
                    $where .= " AND (bank_name_th LIKE :search OR bank_name_en LIKE :search OR bank_code LIKE :search) ";
                    $params[':search'] = '%' . $searchTerm . '%';
                }
                $sqlTotal = "SELECT COUNT(*) FROM master_banks" . $where;
                $stmtTotal = $pdo->prepare($sqlTotal);
                $stmtTotal->execute($params);
                $totalCount = $stmtTotal->fetchColumn();
                $sql = "SELECT id, CONCAT(bank_code, ' - ', bank_name_th) as text_th, CONCAT(bank_code, ' - ', bank_name_en) as text_en FROM master_banks" . $where . " ORDER BY bank_code asc LIMIT :offset, :limit";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
                $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
                foreach ($params as $key => $val) $stmt->bindValue($key, $val);
                $stmt->execute();
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
            case 'department':
            case 'role':
            case 'position':
            case 'branch':
                if ($compId === null) {
                    break;
                }
                $tableMap = [
                    'department' => ['table' => 'structure_departments', 'code' => 'department_code', 'nameTh' => 'department_name_th', 'nameEn' => 'department_name_en'],
                    'role' => ['table' => 'structure_roles', 'code' => null, 'nameTh' => 'role_name_th', 'nameEn' => 'role_name_en'],
                    'position' => ['table' => 'structure_positions', 'code' => 'position_code', 'nameTh' => 'position_name_th', 'nameEn' => 'position_name_en'],
                    'branch' => ['table' => 'structure_branches', 'code' => 'branch_code', 'nameTh' => 'branch_name_th', 'nameEn' => 'branch_name_en'],
                ];
                $cfg = $tableMap[$type];
                $where = " WHERE comp_id = :comp_id AND deleted_at IS NULL AND status = 'active' ";
                $params = [':comp_id' => $compId];
                if (!empty($searchTerm)) {
                    $where .= " AND (`{$cfg['nameTh']}` LIKE :search OR `{$cfg['nameEn']}` LIKE :search) ";
                    $params[':search'] = '%' . $searchTerm . '%';
                }
                $sqlTotal = "SELECT COUNT(*) FROM `{$cfg['table']}`" . $where;
                $stmtTotal = $pdo->prepare($sqlTotal);
                $stmtTotal->execute($params);
                $totalCount = $stmtTotal->fetchColumn();
                $textTh = $cfg['code'] ? "CONCAT(`{$cfg['code']}`, ' - ', `{$cfg['nameTh']}`)" : "`{$cfg['nameTh']}`";
                $textEn = $cfg['code'] ? "CONCAT(`{$cfg['code']}`, ' - ', `{$cfg['nameEn']}`)" : "`{$cfg['nameEn']}`";
                $sql = "SELECT id, {$textTh} as text_th, {$textEn} as text_en FROM `{$cfg['table']}`" . $where . " ORDER BY id asc LIMIT :offset, :limit";
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
