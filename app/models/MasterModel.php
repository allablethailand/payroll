<?php
declare(strict_types=1);
require_once __DIR__ . '/OtRateSetModel.php';
class MasterModel {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }
    public function master(int $page = 1, int $limit = 10, string $type = '', string $searchTerm = '', ?int $compId = null, ?int $employeeId = null): array {
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
            // 2026-09-04, Backlog Phase 9, T047 -- same "closed set that may grow, business-addable
            // without a code deploy" master-table convention as 'country' above; `id` returned is the
            // CODE (not a numeric row id), matching payroll_earning_deduction_types.source_event_code's
            // own "store by code string" pattern -- see TaxStatutoryModel::save()'s own validation of
            // these 2 fields against the same 2 tables.
            case 'statutory_category':
                $where = " WHERE is_active = 1 ";
                $params = [];
                if (!empty($searchTerm)) {
                    $where .= " AND (name_th LIKE :search OR name_en LIKE :search) ";
                    $params[':search'] = '%' . $searchTerm . '%';
                }
                $sqlTotal = "SELECT COUNT(*) FROM master_statutory_categories" . $where;
                $stmtTotal = $pdo->prepare($sqlTotal);
                $stmtTotal->execute($params);
                $totalCount = $stmtTotal->fetchColumn();
                $sql = "SELECT code as id, name_th as text_th, name_en as text_en FROM master_statutory_categories" . $where . " ORDER BY sort_order asc, id asc LIMIT :offset, :limit";
                $stmt = $pdo->prepare($sql);
                $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
                $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
                foreach ($params as $key => $val) $stmt->bindValue($key, $val);
                $stmt->execute();
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
            case 'statutory_calc_base':
                $where = " WHERE is_active = 1 ";
                $params = [];
                if (!empty($searchTerm)) {
                    $where .= " AND (name_th LIKE :search OR name_en LIKE :search) ";
                    $params[':search'] = '%' . $searchTerm . '%';
                }
                $sqlTotal = "SELECT COUNT(*) FROM master_statutory_calc_bases" . $where;
                $stmtTotal = $pdo->prepare($sqlTotal);
                $stmtTotal->execute($params);
                $totalCount = $stmtTotal->fetchColumn();
                $sql = "SELECT code as id, name_th as text_th, name_en as text_en FROM master_statutory_calc_bases" . $where . " ORDER BY sort_order asc, id asc LIMIT :offset, :limit";
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
            case 'ot_rate':
                // 2026-08-30: `ot_rates` (a flat per-company table matching every other entry in the
                // generic $tableMap below) was replaced by the OT Rate Set system -- a selectable "OT
                // rate" is now one `ot_rate_set_items` row (one OT scope's rate within a Set), a child
                // of `ot_rate_sets` rather than its own top-level comp_id-scoped table, so it can't
                // reuse the generic dispatcher below and gets its own case instead (same precedent as
                // 'religion'/'bank' above, which are shaped differently from the generic group too).
                // Used by the manual/imported Overtime Record entry picker (OvertimeRecordModel) --
                // label combines the Set name + OT type name since a Set's own items have no name of
                // their own (only the type they belong to does).
                if ($compId === null) {
                    break;
                }
                $where = " WHERE s.comp_id = :comp_id AND s.deleted_at IS NULL AND s.status = 'active' ";
                $params = [':comp_id' => $compId];
                // 2026-09-03, Manual Entry Phase 1A: when called with a real employee_id (Manual
                // Entry's Overtime "OT Rate" picker sets this via data-employee-id, see input.js's own
                // ajax data() builder), narrow results to that employee's OWN resolved OT Rate Set
                // (same precedence OtRateSetModel::resolveSetForEmployee()/resolveRatesForEmployees()
                // already use for PayrollRunModel -- explicit assignment > employee > team > position
                // > department > mandatory Default) instead of listing every active Set's items
                // company-wide. Silently falls through to the unfiltered (existing) behavior if the
                // employee can't be resolved to any Set at all (shouldn't happen once any Set exists --
                // save() always forces one to be Default -- but a company with literally zero OT Rate
                // Sets configured has nothing to narrow to).
                if ($employeeId !== null) {
                    $stmtEmp = $this->db->prepare("SELECT department_id, team_id, position_id, assigned_ot_rate_set_id FROM `employees` WHERE id = :id AND comp_id = :comp_id AND deleted_at IS NULL");
                    $stmtEmp->execute([':id' => $employeeId, ':comp_id' => $compId]);
                    $empRow = $stmtEmp->fetch(PDO::FETCH_ASSOC);
                    if ($empRow) {
                        $resolved = (new OtRateSetModel($this->db))->resolveRatesForEmployees([[
                            'id' => $employeeId, 'department_id' => $empRow['department_id'] !== null ? (int)$empRow['department_id'] : null,
                            'team_id' => $empRow['team_id'] !== null ? (int)$empRow['team_id'] : null,
                            'position_id' => $empRow['position_id'] !== null ? (int)$empRow['position_id'] : null,
                            'assigned_ot_rate_set_id' => $empRow['assigned_ot_rate_set_id'] !== null ? (int)$empRow['assigned_ot_rate_set_id'] : null,
                        ]], $compId);
                        $employeeSetId = $resolved[$employeeId]['set_id'] ?? null;
                        if ($employeeSetId !== null) {
                            $where .= " AND s.id = :employee_set_id ";
                            $params[':employee_set_id'] = $employeeSetId;
                        }
                    }
                }
                if (!empty($searchTerm)) {
                    $where .= " AND (s.name_th LIKE :search OR s.name_en LIKE :search OR st.name_th LIKE :search OR st.name_en LIKE :search) ";
                    $params[':search'] = '%' . $searchTerm . '%';
                }
                $fromSql = " FROM ot_rate_set_items i JOIN ot_rate_sets s ON s.id = i.set_id JOIN master_ot_scope_types st ON st.id = i.ot_scope_id";
                $sqlTotal = "SELECT COUNT(*)" . $fromSql . $where;
                $stmtTotal = $pdo->prepare($sqlTotal);
                $stmtTotal->execute($params);
                $totalCount = $stmtTotal->fetchColumn();
                $sql = "SELECT i.id, CONCAT(s.name_th, ' - ', st.name_th) AS text_th, CONCAT(s.name_en, ' - ', st.name_en) AS text_en"
                    . $fromSql . $where . " ORDER BY s.is_default DESC, s.name_th ASC, st.sort_order ASC LIMIT :offset, :limit";
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
            case 'shift':
            case 'leave_type':
            case 'team':
            case 'rank':
            case 'work_location':
            case 'employment_type':
            case 'hospital':
            case 'pvd_plan':
                if ($compId === null) {
                    break;
                }
                $tableMap = [
                    'department' => ['table' => 'structure_departments', 'code' => 'department_code', 'nameTh' => 'department_name_th', 'nameEn' => 'department_name_en'],
                    'role' => ['table' => 'structure_roles', 'code' => null, 'nameTh' => 'role_name_th', 'nameEn' => 'role_name_en'],
                    'position' => ['table' => 'structure_positions', 'code' => 'position_code', 'nameTh' => 'position_name_th', 'nameEn' => 'position_name_en'],
                    'branch' => ['table' => 'structure_branches', 'code' => 'branch_code', 'nameTh' => 'branch_name_th', 'nameEn' => 'branch_name_en'],
                    'shift' => ['table' => 'shifts', 'code' => 'shift_code', 'nameTh' => 'shift_name_th', 'nameEn' => 'shift_name_en'],
                    'leave_type' => ['table' => 'leave_types', 'code' => 'code', 'nameTh' => 'name_th', 'nameEn' => 'name_en'],
                    'team' => ['table' => 'structure_teams', 'code' => 'team_code', 'nameTh' => 'team_name_th', 'nameEn' => 'team_name_en'],
                    // 2026-08-31, explicit request: Assign Employees modal's own destination-master
                    // Select2 needs a dropdown-options endpoint for every assignable type -- rank and
                    // work_location were the only 2 of the 8 that never had one (every other type
                    // already powers a real Employee Detail dropdown this same way).
                    'rank' => ['table' => 'structure_ranks', 'code' => 'rank_code', 'nameTh' => 'rank_name_th', 'nameEn' => 'rank_name_en'],
                    'work_location' => ['table' => 'master_work_locations', 'code' => 'location_code', 'nameTh' => 'location_name_th', 'nameEn' => 'location_name_en'],
                    // 2026-09-02, Origami candidates.php field batch: employment_type_ref_id/_code/
                    // _name (structure_employment_types, auto-created on sync, see EmployeeSyncer's
                    // own docblock) -- dropdown-options endpoint for Employee Detail's new
                    // employment_type_id field, same generic pattern as every other type here.
                    'employment_type' => ['table' => 'structure_employment_types', 'code' => 'employment_type_code', 'nameTh' => 'employment_type_name_th', 'nameEn' => 'employment_type_name_en'],
                    // 2026-09-10, Batch 3A item 7b: `company_hospitals`/`company_pvd_plans` are
                    // single-free-text-name lookup lists (no _th/_en split, same "one column, not
                    // translated" precedent as structure_teams.client_name) -- pointing nameTh AND
                    // nameEn at the SAME column works with this resolver unchanged (the WHERE/SELECT
                    // just reference `name` twice, harmless). Writing (Select2 "tag" -> row id,
                    // auto-create on first use) is CompanyLookupListModel::resolveOrCreate(), called
                    // from EmployeeModel::save() -- this case only ever reads.
                    'hospital' => ['table' => 'company_hospitals', 'code' => null, 'nameTh' => 'name', 'nameEn' => 'name'],
                    'pvd_plan' => ['table' => 'company_pvd_plans', 'code' => null, 'nameTh' => 'name', 'nameEn' => 'name'],
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
