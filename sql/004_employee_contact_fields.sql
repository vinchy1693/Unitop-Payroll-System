-- =====================================================================
-- 004_employee_contact_fields.sql
--
-- Contact details that an employee maintains for themselves in the
-- employee portal (employee_portal/api/me_profile.php). These are kept
-- strictly separate from basic_salary and the government numbers, which
-- remain HR-controlled.
--
-- All five columns are NULLABLE so existing rows are unaffected and a
-- blank form field is stored as NULL by api/employees.php.
--
-- Idempotent: safe to run more than once (ADD COLUMN IF NOT EXISTS).
-- =====================================================================

ALTER TABLE `employees`
    ADD COLUMN IF NOT EXISTS `mobile_number`            VARCHAR(30)  NULL DEFAULT NULL AFTER `pagibig_number`,
    ADD COLUMN IF NOT EXISTS `email`                    VARCHAR(150) NULL DEFAULT NULL AFTER `mobile_number`,
    ADD COLUMN IF NOT EXISTS `address`                  VARCHAR(255) NULL DEFAULT NULL AFTER `email`,
    ADD COLUMN IF NOT EXISTS `emergency_contact_name`   VARCHAR(120) NULL DEFAULT NULL AFTER `address`,
    ADD COLUMN IF NOT EXISTS `emergency_contact_number` VARCHAR(30)  NULL DEFAULT NULL AFTER `emergency_contact_name`;
