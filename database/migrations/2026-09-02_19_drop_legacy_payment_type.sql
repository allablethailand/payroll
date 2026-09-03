-- 2026-09-02_19_drop_legacy_payment_type.sql
--
-- Drops the legacy `employees.payment_type` enum('bank','cash','check','mixed') column.
-- `payment_method_id` (FK -> master_payment_methods, added in
-- 2026-09-02_12_employee_payment_method.sql) has been the real source of truth since that
-- migration -- payment_type was only ever kept around as a write-through mirror for backward
-- compatibility with pre-existing read sites, all of which have now been cut over to
-- payment_method_id / a resolved payment_method_code (see EmployeeModel::paymentMethodCode(),
-- PayrollReportDataModel::getRunDetails()'s own master_payment_methods JOIN, and
-- PayrollRunModel::getDetails()'s own).
--
-- Verified before writing this migration: every employees row in the real dev DB already has a
-- non-null payment_method_id (backfilled by 2026-09-02_12), so nothing is lost by dropping the
-- mirror column outright.
--
-- No index/FK exists on this column (SHOW INDEX confirmed clean before writing this migration).

ALTER TABLE `employees`
    DROP COLUMN `payment_type`;
