-- =====================================================================
-- 012_seed_users.sql
--
-- Standalone user accounts for a database whose `users` table is empty OR
-- missing. Safe to re-run: an existing username is updated, never duplicated.
--
-- PASSWORDS (change these after the first login):
--     admin           Unitop#Admin26
--     payroll         Unitop#Payroll26
--     approver        Unitop#Approver26
--
-- IF THE WHOLE DATABASE IS EMPTY this script alone is not enough. The
-- application also needs employees, positions, departments and the payroll
-- tables. Restore the full dump instead - schema AND data in one file:
--
--   C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\master_file_module_api\sql\payroll_db_recovery.sql
--
-- The hashes are bcrypt ($2y$10$), generated with PHP password_hash().
-- Change a password in the application, not by editing this file.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `users` (
  `user_id`       BIGINT NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(100) DEFAULT NULL,
  `employee_id`   BIGINT DEFAULT NULL,
  `role`          VARCHAR(50)  DEFAULT NULL,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `password_hash` VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- Staff accounts. No employee link: Administrator is technical, and the
-- maker/checker roles are staff positions rather than employees.
-- These three are enough to run and demonstrate the whole payroll.
-- ---------------------------------------------------------------------
INSERT INTO `users` (`username`, `employee_id`, `role`, `is_active`, `password_hash`) VALUES
  ('admin',    NULL, 'Administrator',   1, '$2y$10$Tiyjv9nBqe5SynBH/YQcI.DhvXad1kMBuXMuGzn8Z7mpVcRlBz/re'),
  ('payroll',  NULL, 'Payroll Officer', 1, '$2y$10$IQxvzBr7gtnDbzuUYwEaYeqEntXbmKAda37GXRsNannQAV2bhG3bq'),
  ('approver', NULL, 'Approver',        1, '$2y$10$91M0pGdupzOt3Wpb4.hsJuCvLZJKmcBL39Oca/7Ryvaf.FxYCtiNe')
ON DUPLICATE KEY UPDATE
  `password_hash` = VALUES(`password_hash`),
  `role`          = VALUES(`role`),
  `is_active`     = 1;

-- ---------------------------------------------------------------------
-- Employee accounts - RUN THESE ONLY IF employees 4 and 5 exist.
-- users.employee_id is a foreign key, so an account pointing at a missing
-- employee fails. Check first:
--
--     SELECT employee_id, employee_number, first_name, last_name FROM employees;
--
-- then uncomment the two statements below, adjusting the ids and hashes.
-- ---------------------------------------------------------------------
-- INSERT INTO `users` (`username`, `employee_id`, `role`, `is_active`, `password_hash`) VALUES
--   ('vinchy',        4, 'Employee', 1, '$2y$10$lIt7Havfj.Lj7EmmtyCjle4WZCT.e3OwLs6j77D2pA9E1evWDWkTu'),
--   ('juan.delacruz', 5, 'Employee', 1, '$2y$10$mBgr8X2fn0K49Qt1OdMRuePZ5ZfYCVHjpQuFBDb0yxQ9JoAAJluv2')
-- ON DUPLICATE KEY UPDATE
--   `password_hash` = VALUES(`password_hash`),
--   `employee_id`   = VALUES(`employee_id`),
--   `role`          = 'Employee',
--   `is_active`     = 1;

-- ---------------------------------------------------------------------
-- Report what is now in place.
-- ---------------------------------------------------------------------
SELECT `user_id`, `username`, `role`, `employee_id`, `is_active` FROM `users` ORDER BY `user_id`;