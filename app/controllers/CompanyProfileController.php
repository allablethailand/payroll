<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/CompanyProfileModel.php';
require_once __DIR__ . '/../models/PermissionModel.php';
class CompanyProfileController extends Controller {
    private $model;
    private PermissionModel $permissionModel;
    public function __construct() {
        $this->model = new CompanyProfileModel();
        $this->permissionModel = new PermissionModel();
    }

    private function userId(): int {
        return (int)($_SESSION['user']['employee_id'] ?? 0);
    }

    private function isAdmin(): bool {
        return ($_SESSION['user']['role'] ?? '') === 'admin';
    }

    private function requirePermission(string $permissionKey): bool {
        $compId = (int)getCompId();
        $check = $this->permissionModel->checkPermission($this->userId(), $permissionKey, $this->isAdmin(), $compId);
        if (!$check['allowed']) {
            $this->json(['status' => false, 'message' => 'You do not have permission to perform this action.']);
            return false;
        }
        return true;
    }

    public function index() {
        $this->view('setup/company-profile');
    }
    public function get() {
        $lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : (isset($_COOKIE['lang']) ? $_COOKIE['lang'] : 'th');
        $data = $this->model->get($lang);
        if ($data) {
            $this->json(['status' => true, 'data' => $data]);
        } else {
            $this->json(['status' => false, 'message' => 'No data found', 'data' => null]);
        }
    }
    public function save() {
        if (!$this->requirePermission('company_profile.manage')) return;
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (empty($data['registered_country']) || empty($data['company_legal_name']) || empty($data['global_tax_id'])) {
            $this->json(['status' => false, 'message' => 'Missing required fields.']);
            return;
        }
        $compId = (int)($_SESSION['user']['company_id'] ?? 0);
        if (!empty($data['logo_path']) && !CompanyProfileModel::isValidLogoPath((string)$data['logo_path'], $compId)) {
            $this->json(['status' => false, 'message' => 'Invalid logo path.']);
            return;
        }
        if (!empty($data['signature_path']) && !CompanyProfileModel::isValidSignaturePath((string)$data['signature_path'], $compId)) {
            $this->json(['status' => false, 'message' => 'Invalid signature path.']);
            return;
        }
        try {
            $result = $this->model->save($data);
            if ($result) {
                $this->json(['status' => true, 'message' => 'Company profile updated successfully!']);
            } else {
                $this->json(['status' => false, 'message' => 'Database operation failed.']);
            }
        } catch (Exception $e) {
            $this->json(['status' => false, 'message' => 'System error: ' . $e->getMessage()]);
        }
    }

    /** 2026-08-24, explicit request: "ในหน้า Profile บริษัท ให้สามารถใส่ Logo ได้ และดึงไปใช้กับหน้า
     *  ตั้งค่า Slip เงินเดือน และใบรับรอง" -- same upload pattern as PayslipTemplateController::
     *  uploadLogo()/EmploymentCertificateTemplateController::uploadLogo(), separate storage root.
     *  Uploads immediately and returns the path for the client to include in save() -- same
     *  decoupled-upload convention as those two. */
    public function uploadLogo() {
        if (!$this->requirePermission('company_profile.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['status' => false, 'message' => 'File upload failed.']);
            return;
        }
        $file = $_FILES['file'];
        $maxSize = 2 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            $this->json(['status' => false, 'message' => 'File size exceeds 2MB limit.']);
            return;
        }
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/svg+xml' => 'svg'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        if (!isset($allowedMimes[$detectedMime])) {
            $this->json(['status' => false, 'message' => 'Unsupported file type. Use JPG, PNG, or SVG.']);
            return;
        }
        $ext = $allowedMimes[$detectedMime];

        $uploadDir = __DIR__ . '/../../public/uploads/company_logos/' . (int)$compId . '/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            $this->json(['status' => false, 'message' => 'Failed to prepare storage directory.']);
            return;
        }
        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $uploadDir . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $this->json(['status' => false, 'message' => 'Failed to save file.']);
            return;
        }
        $relativePath = 'public/uploads/company_logos/' . (int)$compId . '/' . $safeName;
        $this->json(['status' => true, 'message' => 'Uploaded successfully.', 'logo_path' => $relativePath]);
    }

    /** 2026-08-26, explicit request: "เพิ่มให้แนบลายเซ็นต์ Authorized Signatory Name หรือสามารถเซ็นต์สด
     *  ผ่านหน้าจอได้" -- identical pattern/validation to uploadLogo() above (finfo MIME check, 2MB
     *  limit, jpg/png/svg only, random 32-hex filename), separate storage root. A live-drawn
     *  signature reaches here the exact same way an uploaded file does -- the browser's signature-pad
     *  canvas is exported to a PNG Blob client-side (canvas.toBlob()) and posted as a normal
     *  multipart file under the same `file` field name, so this one endpoint serves both input
     *  methods without needing to know which one produced the image. */
    public function uploadSignature() {
        if (!$this->requirePermission('company_profile.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['status' => false, 'message' => 'File upload failed.']);
            return;
        }
        $file = $_FILES['file'];
        $maxSize = 2 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            $this->json(['status' => false, 'message' => 'File size exceeds 2MB limit.']);
            return;
        }
        $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/svg+xml' => 'svg'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        if (!isset($allowedMimes[$detectedMime])) {
            $this->json(['status' => false, 'message' => 'Unsupported file type. Use JPG, PNG, or SVG.']);
            return;
        }
        $ext = $allowedMimes[$detectedMime];

        $uploadDir = __DIR__ . '/../../public/uploads/company_signatures/' . (int)$compId . '/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            $this->json(['status' => false, 'message' => 'Failed to prepare storage directory.']);
            return;
        }
        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $uploadDir . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $this->json(['status' => false, 'message' => 'Failed to save file.']);
            return;
        }
        $relativePath = 'public/uploads/company_signatures/' . (int)$compId . '/' . $safeName;
        $this->json(['status' => true, 'message' => 'Uploaded successfully.', 'signature_path' => $relativePath]);
    }

    /**
     * 2026-08-27, explicit request: "นำไปปรับใช้กับทุกตาราง" -- Excel-style column filter rollout.
     * Frontend column KEY -> real SQL column, one map per structure type, `$lang`-dependent for the
     * TH/EN name columns (same convention as EmployeeModel::listColumnExprMap()). Deliberately
     * excludes boolean-icon columns (is_default/lock_stamp/salary_access/ot_eligible -- the raw 0/1
     * value doesn't read as a meaningful checkbox label) and computed/composite columns (rank's own
     * salary_min-salary_max RANGE display has no single real column) -- same exclusion policy the
     * client-side tables in this rollout already apply to their own non-filterable columns.
     */
    private function structureFilterMap(string $type, string $lang): array {
        $nameCol = $lang === 'en' ? '_name_en' : '_name_th';
        switch ($type) {
            case 'branch':
                return ['branch_code' => 'branch_code', 'name' => 'branch_name' . $nameCol, 'tax_branch_id' => 'tax_branch_id', 'sso_branch_code' => 'sso_branch_code', 'location' => 'location', 'status' => 'status'];
            case 'role':
                return ['name' => 'role_name' . $nameCol, 'status' => 'status'];
            case 'department':
                return ['department_code' => 'department_code', 'name' => 'department_name' . $nameCol, 'cost_center' => 'cost_center', 'status' => 'status'];
            case 'position':
                return ['position_code' => 'position_code', 'name' => 'position_name' . $nameCol, 'position_allowance' => 'position_allowance', 'status' => 'status'];
            case 'rank':
                return ['rank_code' => 'rank_code', 'name' => 'rank_name' . $nameCol, 'status' => 'status'];
            case 'team':
                return ['team_code' => 'team_code', 'name' => 'team_name' . $nameCol, 'client_name' => 'client_name', 'status' => 'status'];
            default:
                return [];
        }
    }

    /** Translates the frontend's `column_filters[key][]=value` request shape into
     *  `[realColumn => values]` via structureFilterMap(), dropping any key that map doesn't
     *  recognize -- defense in depth on top of CompanyProfileModel::applyColumnFilters()'s own
     *  `$allowedColumns` check, never trusting client-supplied keys as real column names directly. */
    private function translateStructureColumnFilters(array $raw, array $filterMap): array {
        $out = [];
        foreach ($raw as $key => $values) {
            if (isset($filterMap[$key])) {
                $out[$filterMap[$key]] = $values;
            }
        }
        return $out;
    }

    /** One shared distinct-values endpoint for all 6 structure types (branch/role/department/
     *  position/rank/team) -- `type` + `column` (the frontend KEY, e.g. 'name'/'branch_code') come
     *  from the request, same shape as EmployeeController::listColumnValues(). */
    public function structureColumnValues() {
        if (!$this->requirePermission('company_structure.view')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'values' => []]);
            return;
        }
        $type = (string)($_POST['type'] ?? '');
        $key = (string)($_POST['column'] ?? '');
        $config = $this->model->getStructureConfig($type);
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $filterMap = $this->structureFilterMap($type, (string)$lang);
        if (!$config || !isset($filterMap[$key])) {
            $this->json(['status' => true, 'values' => []]);
            return;
        }
        $rawColumnFilters = is_array($_POST['column_filters'] ?? null) ? $_POST['column_filters'] : [];
        $columnFilters = $this->translateStructureColumnFilters($rawColumnFilters, $filterMap);
        $values = $this->model->columnDistinctValues($config['table'], (int)$compId, $filterMap[$key], array_values($filterMap), $columnFilters, $filterMap[$key]);
        $this->json(['status' => true, 'values' => $values]);
    }

    public function branch() {
        if (!$this->requirePermission('company_structure.view')) return;
        $compId = getCompId();
        if (!$compId) {
            return $this->json(['draw' => intval($_REQUEST['draw'] ?? 1), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }
        $request = $_REQUEST;
        $start = isset($request['start']) ? (int)$request['start'] : 0;
        $length = isset($request['length']) ? (int)$request['length'] : 10;
        $search = isset($request['search']['value']) ? $request['search']['value'] : '';
        $colIndex = isset($request['order'][0]['column']) ? (int)$request['order'][0]['column'] : 0;
        $orderDir = isset($request['order'][0]['dir']) ? $request['order'][0]['dir'] : 'asc';
        $searchColumns = ['branch_code', 'branch_name_th', 'branch_name_en', 'tax_branch_id', 'sso_branch_code'];
        $sortColumns = [
            0 => 'id',
            1 => 'branch_code',
            2 => 'branch_name_th',
            3 => 'branch_name_en',
            4 => 'status'
        ];
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $filterMap = $this->structureFilterMap('branch', (string)$lang);
        $columnFilters = $this->translateStructureColumnFilters(is_array($request['column_filters'] ?? null) ? $request['column_filters'] : [], $filterMap);
        $result = $this->model->paginateData(
            'structure_branches',
            $compId,
            $searchColumns,
            $sortColumns,
            $start,
            $length,
            $search,
            $colIndex,
            $orderDir,
            $columnFilters,
            array_values($filterMap)
        );
        foreach ($result['data'] as &$row) {
            $row['is_default'] = isset($row['is_default']) ? (bool)$row['is_default'] : false;
            $row['lock_stamp'] = isset($row['lock_stamp']) ? (bool)$row['lock_stamp'] : false;
        }
        $result['draw'] = intval($request['draw'] ?? 1);
        return $this->json($result);
    }
    public function role() {
        if (!$this->requirePermission('company_structure.view')) return;
        $compId = getCompId();
        if (!$compId) {
            return $this->json(['draw' => intval($_REQUEST['draw'] ?? 1), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }
        $request = $_REQUEST;
        $start = isset($request['start']) ? (int)$request['start'] : 0;
        $length = isset($request['length']) ? (int)$request['length'] : 10;
        $search = isset($request['search']['value']) ? $request['search']['value'] : '';
        $colIndex = isset($request['order'][0]['column']) ? (int)$request['order'][0]['column'] : 0;
        $orderDir = isset($request['order'][0]['dir']) ? $request['order'][0]['dir'] : 'asc';
        $searchColumns = ['role_name_th', 'role_name_en'];
        $sortColumns = [
            0 => 'id',
            1 => 'role_name_th',
            2 => 'role_name_en',
            3 => 'salary_access',
            4 => 'status'
        ];
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $filterMap = $this->structureFilterMap('role', (string)$lang);
        $columnFilters = $this->translateStructureColumnFilters(is_array($request['column_filters'] ?? null) ? $request['column_filters'] : [], $filterMap);
        $result = $this->model->paginateData(
            'structure_roles',
            $compId,
            $searchColumns,
            $sortColumns,
            $start,
            $length,
            $search,
            $colIndex,
            $orderDir,
            $columnFilters,
            array_values($filterMap)
        );
        foreach ($result['data'] as &$row) {
            $row['salary_access'] = isset($row['salary_access']) ? (bool)$row['salary_access'] : false;
        }
        $result['draw'] = intval($request['draw'] ?? 1);
        return $this->json($result);
    }
    public function department() {
        if (!$this->requirePermission('company_structure.view')) return;
        $compId = getCompId();
        if (!$compId) {
            return $this->json(['draw' => intval($_REQUEST['draw'] ?? 1), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }
        $request = $_REQUEST;
        $start = isset($request['start']) ? (int)$request['start'] : 0;
        $length = isset($request['length']) ? (int)$request['length'] : 10;
        $search = isset($request['search']['value']) ? $request['search']['value'] : '';
        $colIndex = isset($request['order'][0]['column']) ? (int)$request['order'][0]['column'] : 0;
        $orderDir = isset($request['order'][0]['dir']) ? $request['order'][0]['dir'] : 'asc';
        $searchColumns = ['department_code', 'department_name_th', 'department_name_en', 'cost_center'];
        $sortColumns = [
            0 => 'id',
            1 => 'department_code',
            2 => 'department_name_th',
            3 => 'department_name_en',
            4 => 'cost_center',
            5 => 'status'
        ];
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $filterMap = $this->structureFilterMap('department', (string)$lang);
        $columnFilters = $this->translateStructureColumnFilters(is_array($request['column_filters'] ?? null) ? $request['column_filters'] : [], $filterMap);
        $result = $this->model->paginateData(
            'structure_departments',
            $compId,
            $searchColumns,
            $sortColumns,
            $start,
            $length,
            $search,
            $colIndex,
            $orderDir,
            $columnFilters,
            array_values($filterMap)
        );
        $result['draw'] = intval($request['draw'] ?? 1);
        return $this->json($result);
    }
    public function position() {
        if (!$this->requirePermission('company_structure.view')) return;
        $compId = getCompId();
        if (!$compId) {
            return $this->json(['draw' => intval($_REQUEST['draw'] ?? 1), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }
        $request = $_REQUEST;
        $start = isset($request['start']) ? (int)$request['start'] : 0;
        $length = isset($request['length']) ? (int)$request['length'] : 10;
        $search = isset($request['search']['value']) ? $request['search']['value'] : '';
        $colIndex = isset($request['order'][0]['column']) ? (int)$request['order'][0]['column'] : 0;
        $orderDir = isset($request['order'][0]['dir']) ? $request['order'][0]['dir'] : 'asc';
        $searchColumns = ['position_code', 'position_name_th', 'position_name_en'];
        $sortColumns = [
            0 => 'id',
            1 => 'position_code',
            2 => 'position_name_th',
            3 => 'position_name_en',
            4 => 'position_allowance',
            5 => 'status'
        ];
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $filterMap = $this->structureFilterMap('position', (string)$lang);
        $columnFilters = $this->translateStructureColumnFilters(is_array($request['column_filters'] ?? null) ? $request['column_filters'] : [], $filterMap);
        $result = $this->model->paginateData(
            'structure_positions',
            $compId,
            $searchColumns,
            $sortColumns,
            $start,
            $length,
            $search,
            $colIndex,
            $orderDir,
            $columnFilters,
            array_values($filterMap)
        );
        foreach ($result['data'] as &$row) {
            $row['position_allowance'] = isset($row['position_allowance']) ? (float)$row['position_allowance'] : 0.00;
        }
        $result['draw'] = intval($request['draw'] ?? 1);
        return $this->json($result);
    }
    public function rank() {
        if (!$this->requirePermission('company_structure.view')) return;
        $compId = getCompId();
        if (!$compId) {
            return $this->json(['draw' => intval($_REQUEST['draw'] ?? 1), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }
        $request = $_REQUEST;
        $start = isset($request['start']) ? (int)$request['start'] : 0;
        $length = isset($request['length']) ? (int)$request['length'] : 10;
        $search = isset($request['search']['value']) ? $request['search']['value'] : '';
        $colIndex = isset($request['order'][0]['column']) ? (int)$request['order'][0]['column'] : 0;
        $orderDir = isset($request['order'][0]['dir']) ? $request['order'][0]['dir'] : 'asc';
        $searchColumns = ['rank_code', 'rank_name_th', 'rank_name_en'];
        $sortColumns = [
            0 => 'id',
            1 => 'rank_code',
            2 => 'rank_name_th',
            3 => 'rank_name_en',
            4 => 'salary_min',
            5 => 'salary_max',
            6 => 'status'
        ];
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $filterMap = $this->structureFilterMap('rank', (string)$lang);
        $columnFilters = $this->translateStructureColumnFilters(is_array($request['column_filters'] ?? null) ? $request['column_filters'] : [], $filterMap);
        $result = $this->model->paginateData(
            'structure_ranks',
            $compId,
            $searchColumns,
            $sortColumns,
            $start,
            $length,
            $search,
            $colIndex,
            $orderDir,
            $columnFilters,
            array_values($filterMap)
        );
        foreach ($result['data'] as &$row) {
            $row['salary_min'] = isset($row['salary_min']) ? (float)$row['salary_min'] : 0.00;
            $row['salary_max'] = isset($row['salary_max']) ? (float)$row['salary_max'] : 0.00;
            $row['ot_eligible'] = isset($row['ot_eligible']) ? (bool)$row['ot_eligible'] : false;
        }
        $result['draw'] = intval($request['draw'] ?? 1);
        return $this->json($result);
    }
    public function team() {
        if (!$this->requirePermission('company_structure.view')) return;
        $compId = getCompId();
        if (!$compId) {
            return $this->json(['draw' => intval($_REQUEST['draw'] ?? 1), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
        }
        $request = $_REQUEST;
        $start = isset($request['start']) ? (int)$request['start'] : 0;
        $length = isset($request['length']) ? (int)$request['length'] : 10;
        $search = isset($request['search']['value']) ? $request['search']['value'] : '';
        $colIndex = isset($request['order'][0]['column']) ? (int)$request['order'][0]['column'] : 0;
        $orderDir = isset($request['order'][0]['dir']) ? $request['order'][0]['dir'] : 'asc';
        $searchColumns = ['team_code', 'team_name_th', 'team_name_en', 'client_name'];
        $sortColumns = [
            0 => 'id',
            1 => 'team_code',
            2 => 'team_name_th',
            3 => 'team_name_en',
            4 => 'client_name',
            5 => 'status'
        ];
        $lang = $_SESSION['lang'] ?? ($_COOKIE['lang'] ?? 'th');
        $filterMap = $this->structureFilterMap('team', (string)$lang);
        $columnFilters = $this->translateStructureColumnFilters(is_array($request['column_filters'] ?? null) ? $request['column_filters'] : [], $filterMap);
        $result = $this->model->paginateData(
            'structure_teams',
            $compId,
            $searchColumns,
            $sortColumns,
            $start,
            $length,
            $search,
            $colIndex,
            $orderDir,
            $columnFilters,
            array_values($filterMap)
        );
        $result['draw'] = intval($request['draw'] ?? 1);
        return $this->json($result);
    }
    public function branchSave() { $this->handleStructureSave('branch'); }
    public function branchDelete() { $this->handleStructureDelete('branch'); }
    public function roleSave() { $this->handleStructureSave('role'); }
    public function roleDelete() { $this->handleStructureDelete('role'); }
    public function departmentSave() { $this->handleStructureSave('department'); }
    public function departmentDelete() { $this->handleStructureDelete('department'); }
    public function positionSave() { $this->handleStructureSave('position'); }
    public function positionDelete() { $this->handleStructureDelete('position'); }
    public function rankSave() { $this->handleStructureSave('rank'); }
    public function rankDelete() { $this->handleStructureDelete('rank'); }
    public function teamSave() { $this->handleStructureSave('team'); }
    public function teamDelete() { $this->handleStructureDelete('team'); }
    private function handleStructureSave(string $type): void {
        if (!$this->requirePermission('company_structure.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        if (!is_array($data)) {
            $this->json(['status' => false, 'message' => 'Invalid request payload.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->model->saveStructure($type, (int)$compId, $data, $userId);
        $this->json($result);
    }
    private function handleStructureDelete(string $type): void {
        if (!$this->requirePermission('company_structure.manage')) return;
        $compId = getCompId();
        if (!$compId) {
            $this->json(['status' => false, 'message' => 'Missing company context.']);
            return;
        }
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);
        $id = (is_array($data) && isset($data['id'])) ? (int)$data['id'] : 0;
        if ($id <= 0) {
            $this->json(['status' => false, 'message' => 'Invalid ID.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $result = $this->model->deleteStructure($type, (int)$compId, $id, $userId);
        $this->json($result);
    }

    /* ==================== Assign Employees (2026-08-31, explicit request) ====================
     * Generic across every structure type (see CompanyProfileModel's own EMPLOYEE_FK_COLUMN
     * docblock) -- `type` travels as a plain request param rather than 6 separate dispatcher
     * methods, since the frontend's own modal is ALSO one shared component driven by the same
     * `type` value (no per-type markup/JS to keep in sync with a per-type route).
     */
    public function structureEmployeesInRow() {
        if (!$this->requirePermission('company_structure.view')) return;
        $compId = getCompId();
        $type = (string)($_GET['type'] ?? '');
        $rowId = (int)($_GET['id'] ?? 0);
        $search = trim((string)($_GET['search'] ?? ''));
        if (!$compId || $rowId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->structureEmployeesInRow($type, $rowId, (int)$compId, $search));
    }

    public function structureEmployeesOutsideRow() {
        if (!$this->requirePermission('company_structure.view')) return;
        $compId = getCompId();
        $type = (string)($_GET['type'] ?? '');
        $rowId = (int)($_GET['id'] ?? 0);
        $search = trim((string)($_GET['search'] ?? ''));
        if (!$compId || $rowId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $this->json($this->model->structureEmployeesOutsideRow($type, $rowId, (int)$compId, $search));
    }

    public function structureAssignEmployees() {
        if (!$this->requirePermission('company_structure.manage')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $type = (string)($data['type'] ?? '');
        $rowId = (int)($data['id'] ?? 0);
        $employeeIds = is_array($data['employee_ids'] ?? null) ? $data['employee_ids'] : [];
        if (!$compId || $rowId <= 0) {
            $this->json(['status' => false, 'message' => 'Missing id.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $this->json($this->model->structureAssignEmployees($type, $rowId, $employeeIds, (int)$compId, $userId));
    }

    public function structureMoveEmployeesOut() {
        if (!$this->requirePermission('company_structure.manage')) return;
        $compId = getCompId();
        $data = json_decode(file_get_contents('php://input'), true);
        $type = (string)($data['type'] ?? '');
        $employeeIds = is_array($data['employee_ids'] ?? null) ? $data['employee_ids'] : [];
        $destinationRowId = !empty($data['destination_id']) ? (int)$data['destination_id'] : null;
        if (!$compId || empty($employeeIds)) {
            $this->json(['status' => false, 'message' => 'No employees selected.']);
            return;
        }
        $userId = (int)($_SESSION['user']['employee_id'] ?? 0);
        $this->json($this->model->structureMoveEmployeesOut($type, $employeeIds, (int)$compId, $destinationRowId, $userId));
    }
}