<?php
declare(strict_types=1);
class PayrollConfigurationModel {
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }
}