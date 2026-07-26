<?php
require_once __DIR__ . '/../models/AddressModel.php';
class AddressController extends Controller {
    private $model;
    public function __construct() { 
        $this->model = new AddressModel(); 
    }
    public function getMetadata() {
        $keyword = isset($_GET['q']) ? trim($_GET['q']) : '';
        $country = isset($_GET['country']) ? trim($_GET['country']) : 'TH';
        $lang    = isset($_GET['lang']) ? strtolower(trim($_GET['lang'])) : 'th';
        if (mb_strlen($keyword, 'UTF-8') < 2) {
            header('Content-Type: application/json');
            echo json_encode([]);
            exit;
        }
        $metadata = $this->model->searchAddresses($keyword, $country, $lang);
        header('Content-Type: application/json');
        echo json_encode($metadata);
        exit;
    }
}