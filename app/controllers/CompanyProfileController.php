<?php
require_once __DIR__ . '/../models/CompanyProfileModel.php';
class CompanyProfileController extends Controller {
    private $model;
    public function __construct(){ $this->model = new CompanyProfileModel(); }
    public function index() {
        $this->view('setup/company-profile');
    }
    public function getMetadata() {
        header('Content-Type: application/json');
        $countryCode = $_GET['country'] ?? '';
        $model = $this->model;
        $metadata = $model->getCountryMetadata($countryCode);
        if ($metadata) {
            echo json_encode(['success' => true, 'data' => $metadata]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No configuration found']);
        }
        exit;
    }

    // สำหรับรับค่าไปบันทึก (Route: api/payroll/save)
    public function save() {
        header('Content-Type: application/json');
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        $model = $this->model;
        $isSaved = $model->saveCompany($input);
        if ($isSaved) {
            echo json_encode(['success' => true, 'message' => 'Saved successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Database error']);
        }
        exit;
    }
}