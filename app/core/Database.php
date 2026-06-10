<?php
    class Database {
        private static $instance = null;
        public $pdo;
        private function __construct() {
            $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8";
            try {
                $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::MYSQL_ATTR_LOCAL_INFILE => true,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
                $this->pdo->exec("SET time_zone = '+00:00'");
            } catch (PDOException $e) {
                die('DB Connection failed: ' . $e->getMessage());
            }
        }
        public static function getInstance() {
            if (self::$instance === null) self::$instance = new Database();
            return self::$instance;
        }
    }