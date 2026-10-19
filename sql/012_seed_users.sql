-- =====================================================================
-- 012_seed_users.sql
--
-- Standalone user accounts, for a database whose `users` table is empty (or
-- missing). Safe to re-run: existing usernames are updated rather than duplicated.
--
-- PASSWORDS (change these after the first login):
--     admin           Unitop#Admin26
--     payroll         Unitop#Payroll26
--     approver        Unitop#Approver26
--     vinchy          Unitop#Vinchy26        (employee 4)
--     juan.delacruz   Unitop#Juan26          (employee 5)
--
-- IMPORTANT: if the WHOLE database is empty - no employees, no positions, no
-- payroll tables - this script is not enough. Restore the full dump instead:
--
--     Get-Content sql\payroll_db_recovery.sql -Raw | C:\xampp\mysql\bin\mysql.exe -u root
--
-- The hashes below are bcrypt ($2y$10$) and were generated with PHP
-- password_hash(). Replace them by changing the password in the app, not by
-- editing this file.
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
-- Staff accounts. These have no employee link: Administrator is a technical
-- account, and the maker/checker roles are staff positions, not employees.
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
-- Employee accounts. Only inserted when the employee row actually exists,
-- because users.employee_id is a foreign key.
-- ---------------------------------------------------------------------
INSERT INTO `users` (`username`, `employee_id`, `role`, `is_active`, `password_hash`)
SELECT 'vinchy', 4, 'Employee', 1, '$2y$10$lIt7Havfj.Lj7EmmtyCjle4WZCT.e3OwLs6j77D2pA9E1evWDWkTu'
WHERE EXISTS (SELECT 1 FROM `employees` WHERE `employee_id` = 4)
ON DUPLICATE KEY UPDATE
  `password_hash` = VALUES(`password_hash`),
  `employee_id`   = 4,
  `role`          = 'Employee',
  `is_active`     = 1;

INSERT INTO `users` (`username`, `employee_id`, `role`, `is_active`, `password_hash`)
SELECT 'juan.delacruz', 5, 'Employee', 1, '$2y$10$mBgr8X2fn0K49Qt1OdMRuePZ5ZfYCVHjpQuFBDb0yxQ9JoAAJluv2'
WHERE EXISTS (SELECT 1 FROM `employees` WHERE `employee_id` = 5)
ON DUPLICATE KEY UPDATE
  `password_hash` = VALUES(`password_hash`),
  `employee_id`   = 5,
  `role`          = 'Employee',
  `is_active`     = 1;

-- ---------------------------------------------------------------------
-- Show what is now in place.
-- ---------------------------------------------------------------------
SELECT `user_id`, `username`, `role`, `employee_id`, `is_active` FROM `users` ORDER BY `user_id`;
