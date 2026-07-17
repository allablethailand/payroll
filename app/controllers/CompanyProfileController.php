<?php
require_once __DIR__ . '/../models/CompanyProfileModel.php';
class CompanyProfileController extends Controller {
    private $model;
    public function __construct(){ $this->model = new CompanyProfileModel(); }
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
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        if (empty($data['registered_country']) || empty($data['company_legal_name']) || empty($data['global_tax_id'])) {
            $this->json(['status' => false, 'message' => 'Missing required fields.']);
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
}