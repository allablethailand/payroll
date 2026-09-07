<?php
declare(strict_types=1);

/**
 * 2026-09-05, Backlog Phase 13 -- COMPANY-LEVEL setup completeness checklist for Help > Setup
 * Guide. Explicitly distinct from the existing, separate per-EMPLOYEE completeness % feature
 * (Employee List/Detail's own "profile completeness" -- confirmed via AskUserQuestion this is a
 * different thing, not a duplicate). Each item here needs its own bespoke "is this actually done"
 * query (has an active bank account, has a payroll cycle configured, etc.) -- deliberately NOT a
 * master table the way ChangelogModel/HelpDrawerContentModel are, because there is real per-item
 * logic behind each check, matching this project's own "tied to real logic, not master-table-
 * ified" precedent (e.g. OT Rate's calculation_base) -- see the migration's own docblock.
 *
 * Scope for this round (7 items) -- covers what's genuinely needed to run a real payroll round
 * end to end, not an exhaustive audit of every settings page in the app. Adding a new checklist
 * item later means adding one more case here, same as adding a new report/exporter elsewhere in
 * this codebase.
 */
class SetupGuideModel {
    private PDO $db;

    public function __construct(?PDO $pdo = null) {
        $this->db = $pdo ?? Database::getInstance()->pdo;
    }

    /** @return array{items: array<int, array{key:string, label_th:string, label_en:string, done:bool, link:string, detail_th:string, detail_en:string}>, done_count:int, total_count:int, percent:int} */
    public function checklist(int $compId): array {
        $items = [
            $this->checkCompanyProfile($compId),
            $this->checkBankAccount($compId),
            $this->checkPayrollCycle($compId),
            $this->checkEmployees($compId),
            $this->checkTaxStatutory($compId),
            $this->checkEarningDeductionTypes($compId),
            $this->checkApprovalWorkflow($compId),
        ];
        $doneCount = count(array_filter($items, fn($i) => $i['done']));
        $totalCount = count($items);
        return [
            'items' => $items,
            'done_count' => $doneCount,
            'total_count' => $totalCount,
            'percent' => $totalCount > 0 ? (int)round($doneCount / $totalCount * 100) : 0,
        ];
    }

    private function checkCompanyProfile(int $compId): array {
        $stmt = $this->db->prepare(
            "SELECT (COALESCE(global_tax_id,'') != '' AND COALESCE(address_line_1,'') != '' AND COALESCE(authorized_signatory_name,'') != '') AS is_done
             FROM `companies` WHERE id = :comp_id"
        );
        $stmt->execute([':comp_id' => $compId]);
        $done = (bool)$stmt->fetchColumn();
        return [
            'key' => 'company_profile',
            'label_th' => 'ตั้งค่าข้อมูลบริษัท', 'label_en' => 'Set up Company Profile',
            'detail_th' => 'กรอกเลขประจำตัวผู้เสียภาษี ที่อยู่ และชื่อผู้มีอำนาจลงนามให้ครบ',
            'detail_en' => 'Fill in the tax ID, address, and authorized signatory name.',
            'done' => $done, 'link' => '/setup/company-profile',
        ];
    }

    private function checkBankAccount(int $compId): array {
        $stmt = $this->db->prepare("SELECT 1 FROM `bank_accounts` WHERE comp_id = :comp_id AND deleted_at IS NULL AND status = 'active' LIMIT 1");
        $stmt->execute([':comp_id' => $compId]);
        return [
            'key' => 'bank_account',
            'label_th' => 'เพิ่มบัญชีธนาคารของบริษัท', 'label_en' => "Add the company's bank account",
            'detail_th' => 'ใช้สำหรับตัดเงินโอนเข้าบัญชีพนักงานและออกไฟล์โอนเงินธนาคาร',
            'detail_en' => "Used as the debit account for employee bank transfers and the bank transfer file.",
            'done' => (bool)$stmt->fetchColumn(), 'link' => '/setup/company-profile',
        ];
    }

    private function checkPayrollCycle(int $compId): array {
        $stmt = $this->db->prepare("SELECT 1 FROM `payroll_cycles` WHERE comp_id = :comp_id AND deleted_at IS NULL AND status = 'active' LIMIT 1");
        $stmt->execute([':comp_id' => $compId]);
        return [
            'key' => 'payroll_cycle',
            'label_th' => 'ตั้งค่ารอบการจ่ายเงินเดือน', 'label_en' => 'Set up a Payroll Cycle',
            'detail_th' => 'กำหนดวันตัดรอบและวันจ่ายเงินเดือนอย่างน้อย 1 รอบ',
            'detail_en' => 'Configure at least one payroll cycle with its cutoff and payment dates.',
            'done' => (bool)$stmt->fetchColumn(), 'link' => '/setup/payroll-configuration',
        ];
    }

    private function checkEmployees(int $compId): array {
        $stmt = $this->db->prepare("SELECT 1 FROM `employees` WHERE comp_id = :comp_id AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':comp_id' => $compId]);
        return [
            'key' => 'employees',
            'label_th' => 'เพิ่มพนักงาน', 'label_en' => 'Add employees',
            'detail_th' => 'เพิ่มพนักงานอย่างน้อย 1 คนเข้าระบบ ผ่านการกรอกเอง นำเข้าไฟล์ หรือซิงค์จาก Origami',
            'detail_en' => 'Add at least one employee, via manual entry, file import, or Origami sync.',
            'done' => (bool)$stmt->fetchColumn(), 'link' => '/employee/list',
        ];
    }

    private function checkTaxStatutory(int $compId): array {
        $stmt = $this->db->prepare("SELECT 1 FROM `company_statutory_settings` WHERE comp_id = :comp_id AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':comp_id' => $compId]);
        return [
            'key' => 'tax_statutory',
            'label_th' => 'ตรวจสอบการตั้งค่าภาษี/ประกันสังคม', 'label_en' => 'Review tax/statutory settings',
            'detail_th' => 'ตรวจสอบว่าอัตราภาษี ประกันสังคม และกองทุนต่างๆ ตรงกับที่บริษัทต้องการ',
            'detail_en' => 'Confirm the PIT/SSO/provident fund rates match what the company actually needs.',
            'done' => (bool)$stmt->fetchColumn(), 'link' => '/setup/tax-statutory',
        ];
    }

    private function checkEarningDeductionTypes(int $compId): array {
        $stmt = $this->db->prepare("SELECT 1 FROM `payroll_earning_deduction_types` WHERE comp_id = :comp_id AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([':comp_id' => $compId]);
        return [
            'key' => 'earning_deduction_types',
            'label_th' => 'ตั้งค่ารายการเงินได้/เงินหัก', 'label_en' => 'Set up earning/deduction types',
            'detail_th' => 'ตรวจสอบรายการเงินได้และเงินหักที่บริษัทใช้งานจริง เช่น ค่าตำแหน่ง ค่าล่วงเวลา เงินกู้',
            'detail_en' => 'Review the earning/deduction types the company actually uses (allowances, OT, loans, etc.).',
            'done' => (bool)$stmt->fetchColumn(), 'link' => '/setup/payroll-configuration',
        ];
    }

    private function checkApprovalWorkflow(int $compId): array {
        $stmt = $this->db->prepare("SELECT 1 FROM `approval_workflows` WHERE comp_id = :comp_id AND deleted_at IS NULL AND status = 'active' LIMIT 1");
        $stmt->execute([':comp_id' => $compId]);
        return [
            'key' => 'approval_workflow',
            'label_th' => 'ตั้งค่าลำดับผู้อนุมัติ (ไม่บังคับ)', 'label_en' => 'Set up an approval workflow (optional)',
            'detail_th' => 'กำหนดผู้อนุมัติงวดเงินเดือน หากบริษัทต้องการขั้นตอนอนุมัติก่อนจ่ายจริง',
            'detail_en' => 'Configure who approves a payroll run, if the company wants an approval step before paying.',
            'done' => (bool)$stmt->fetchColumn(), 'link' => '/setup/document-approval',
        ];
    }
}
