-- =====================================================================
-- 006_employee_bank_fields.sql
--
-- Payment details for the bank file (api/bank_file.php?format=bank):
--
--   bank_name           - the receiving bank, e.g. "BDO Unibank"
--   bank_account_name   - the account holder exactly as the bank knows it
--   bank_account_number - the account number the credit is addressed to
--
-- All three columns are NULLABLE: existing rows are unaffected and a blank
-- form field is stored as NULL by api/employees.php. They are MAKER-writable
-- only, because employees.php requires MAKER_ROLES for POST/PUT, and they are
-- deliberately NOT part of any employee-portal (me_*) payload.
--
-- Idempotent: safe to run more than once (ADD COLUMN IF NOT EXISTS).
-- =====================================================================

ALTER TABLE `employees`
    ADD COLUMN IF NOT EXISTS `bank_name`           VARCHAR(100) NULL DEFAULT NULL AFTER `pagibig_number`,
    ADD COLUMN IF NOT EXISTS `bank_account_name`   VARCHAR(120) NULL DEFAULT NULL AFTER `bank_name`,
    ADD COLUMN IF NOT EXISTS `bank_account_number` VARCHAR(40)  NULL DEFAULT NULL AFTER `bank_account_name`;
