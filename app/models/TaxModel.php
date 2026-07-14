<?php
class TaxModel { 
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }
}