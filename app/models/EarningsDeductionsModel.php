<?php
class EarningsDeductionsModel { 
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }
}