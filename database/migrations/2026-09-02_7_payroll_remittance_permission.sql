-- 2026-09-02, Deduction Destination & Third-Party Remittance -- gates the "manage" actions on the
-- new remittance report tab (Mark as Transferred/Confirm Success/Mark Failed/Retry), per the
-- feature's own spec: "จำกัดสิทธิ์ mark-transferred/confirm ตาม RBAC matrix ที่มีอยู่ (เฉพาะ Finance/
-- Payroll admin role)". Viewing the tab itself reuses the existing `payroll_run.view` gate the rest
-- of the Process Detail page already requires -- only the state-changing actions get a dedicated
-- key, same "one key for the whole page, no view/manage split when the page has no separate view
-- concern" precedent PayrollRunCashPaymentModel's own single `payroll_run_cash_payment.manage` key
-- already established for the sibling Cash Payments tab.
INSERT INTO `permissions` (module_code, action_code, permission_key, name_th, name_en, is_active, sort_order)
VALUES ('payroll_remittance', 'manage', 'payroll_remittance.manage', 'จัดการสถานะการโอนเงินบุคคลที่สาม', 'Manage Third-Party Remittance Status', 1, 202);
