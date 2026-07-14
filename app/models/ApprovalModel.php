<?php
class ApprovalModel { 
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }
}