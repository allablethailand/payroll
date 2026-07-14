<?php
class SubmissionModel { 
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }
}