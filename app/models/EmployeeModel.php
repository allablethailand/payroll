<?php
class EmployeeModel { 
    private $db;
    public function __construct() {
        $this->db = Database::getInstance()->pdo;
    }
    public function list($start = 0, $length = 10, $filters = [], $search = '', $colIndex = 6, $orderDir = 'desc') {
        $mockData = [
            ["id" => 1, "employee_no" => "EMP001", "name" => "สมชาย ใจดี", "email" => "somchai.j@company.com", "phone" => "0812345678", "role" => "Admin", "department" => "IT", "shift" => "Day", "branch" => "Bangkok", "start_work_date" => "2023-01-15", "status" => "Active"],
            ["id" => 2, "employee_no" => "EMP002", "name" => "สมหญิง รักดี", "email" => "somying.r@company.com", "phone" => "0823456789", "role" => "User", "department" => "HR", "shift" => "Day", "branch" => "Bangkok", "start_work_date" => "2023-03-01", "status" => "Active"],
            ["id" => 3, "employee_no" => "EMP003", "name" => "กิตติพงษ์ มั่นคง", "email" => "kittipong.m@company.com", "phone" => "0834567890", "role" => "Manager", "department" => "Sales", "shift" => "Day", "branch" => "Chiang Mai", "start_work_date" => "2022-05-10", "status" => "Active"],
            ["id" => 4, "employee_no" => "EMP004", "name" => "นภา สว่างจิต", "email" => "napa.s@company.com", "phone" => "0845678901", "role" => "User", "department" => "Accounting", "shift" => "Day", "branch" => "Bangkok", "start_work_date" => "2024-02-20", "status" => "Inactive"],
            ["id" => 5, "employee_no" => "EMP005", "name" => "วีระศักดิ์ รุ่งเรือง", "email" => "weerasak.r@company.com", "phone" => "0856789012", "role" => "User", "department" => "IT", "shift" => "Night", "branch" => "Phuket", "start_work_date" => "2023-07-11", "status" => "Active"],
            ["id" => 6, "employee_no" => "EMP006", "name" => "ธนพล ก้าวหน้า", "email" => "thanapol.k@company.com", "phone" => "0867890123", "role" => "Admin", "department" => "Operations", "shift" => "Day", "branch" => "Chonburi", "start_work_date" => "2021-11-01", "status" => "Active"],
            ["id" => 7, "employee_no" => "EMP007", "name" => "อนงค์นาฏ ชื่นบาน", "email" => "anongnat.c@company.com", "phone" => "0878901234", "role" => "User", "department" => "Marketing", "shift" => "Day", "branch" => "Bangkok", "start_work_date" => "2025-01-08", "status" => "Active"],
            ["id" => 8, "employee_no" => "EMP008", "name" => "ประพันธ์ สุขใจ", "email" => "prapan.s@company.com", "phone" => "0889012345", "role" => "User", "department" => "Support", "shift" => "Night", "branch" => "Chiang Mai", "start_work_date" => "2023-09-15", "status" => "Active"],
            ["id" => 9, "employee_no" => "EMP009", "name" => "วิไลวรรณ เจริญดี", "email" => "wilaiwan.j@company.com", "phone" => "0890123456", "role" => "Manager", "department" => "HR", "shift" => "Day", "branch" => "Bangkok", "start_work_date" => "2020-04-18", "status" => "Active"],
            ["id" => 10, "employee_no" => "EMP010", "name" => "ศิริชัย ตั้งมั่น", "email" => "sirichai.t@company.com", "phone" => "0801234567", "role" => "User", "department" => "Logistics", "shift" => "Night", "branch" => "Chonburi", "start_work_date" => "2024-06-01", "status" => "Active"],
            ["id" => 11, "employee_no" => "EMP011", "name" => "ดวงใจ งามดี", "email" => "duangjai.n@company.com", "phone" => "0813579246", "role" => "User", "department" => "Accounting", "shift" => "Day", "branch" => "Phuket", "start_work_date" => "2022-10-25", "status" => "Inactive"],
            ["id" => 12, "employee_no" => "EMP012", "name" => "ปกรณ์ มณีรัตน์", "email" => "pakorn.m@company.com", "phone" => "0824681357", "role" => "Admin", "department" => "IT", "shift" => "Day", "branch" => "Bangkok", "start_work_date" => "2023-12-12", "status" => "Active"],
            ["id" => 13, "employee_no" => "EMP013", "name" => "สร้อยเพชร ทวีทรัพย์", "email" => "sroipetch.t@company.com", "phone" => "0835791468", "role" => "User", "department" => "Sales", "shift" => "Day", "branch" => "Chiang Mai", "start_work_date" => "2024-03-15", "status" => "Active"],
            ["id" => 14, "employee_no" => "EMP014", "name" => "อานนท์ บุญยืน", "email" => "arnon.b@company.com", "phone" => "0846802579", "role" => "User", "department" => "Operations", "shift" => "Night", "branch" => "Bangkok", "start_work_date" => "2024-08-22", "status" => "Active"],
            ["id" => 15, "employee_no" => "EMP015", "name" => "สุดาภรณ์ เรืองศรี", "email" => "sudaporn.r@company.com", "phone" => "0857913680", "role" => "Manager", "department" => "Marketing", "shift" => "Day", "branch" => "Phuket", "start_work_date" => "2021-02-14", "status" => "Active"],
            ["id" => 16, "employee_no" => "EMP016", "name" => "ชัชวาล วงศ์ษา", "email" => "chatchawal.w@company.com", "phone" => "0868024791", "role" => "User", "department" => "Support", "shift" => "Day", "branch" => "Chonburi", "start_work_date" => "2023-05-20", "status" => "Active"],
            ["id" => 17, "employee_no" => "EMP017", "name" => "นงลักษณ์ สุขสวัสดิ์", "email" => "nonglak.s@company.com", "phone" => "0879135802", "role" => "User", "department" => "HR", "shift" => "Day", "branch" => "Bangkok", "start_work_date" => "2025-02-01", "status" => "Active"],
            ["id" => 18, "employee_no" => "EMP018", "name" => "มานะ ชูใจ", "email" => "mana.c@company.com", "phone" => "0880246913", "role" => "User", "department" => "Logistics", "shift" => "Night", "branch" => "Chiang Mai", "start_work_date" => "2022-08-11", "status" => "Inactive"],
            ["id" => 19, "employee_no" => "EMP019", "name" => "ยุพา พรหมดี", "email" => "yupa.p@company.com", "phone" => "0891357024", "role" => "Admin", "department" => "Accounting", "shift" => "Day", "branch" => "Bangkok", "start_work_date" => "2023-04-05", "status" => "Active"],
            ["id" => 20, "employee_no" => "EMP020", "name" => "เกรียงไกร มีสุข", "email" => "kriengkrai.m@company.com", "phone" => "0802468135", "role" => "User", "department" => "IT", "shift" => "Night", "branch" => "Chonburi", "start_work_date" => "2024-11-30", "status" => "Active"]
        ];
        if (!empty($search)) {
            $mockData = array_filter($mockData, function($item) use ($search) {
                return (strpos($item['name'], $search) !== false) || 
                    (strpos($item['employee_no'], $search) !== false) ||
                    (strpos($item['email'], $search) !== false);
            });
        }
        $totalRecords = count($mockData);
        $slicedData = array_slice($mockData, $start, $length);
        return [
            'total' => $totalRecords,
            'data' => $slicedData
        ];
    }
}