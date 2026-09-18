-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 16, 2026 at 01:45 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `payroll_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `allowances`
--

CREATE TABLE `allowances` (
  `allowance_id` bigint(20) NOT NULL,
  `payslip_id` bigint(20) NOT NULL,
  `allowance_type` varchar(100) DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `attendance_id` bigint(20) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `qr_code_id` bigint(20) DEFAULT NULL,
  `work_date` date DEFAULT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `scan_device_id` varchar(100) DEFAULT NULL,
  `hours_worked` decimal(6,2) DEFAULT NULL,
  `minutes_late` int(11) DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cash_disbursements`
--

CREATE TABLE `cash_disbursements` (
  `disbursement_id` bigint(20) NOT NULL,
  `payslip_id` bigint(20) NOT NULL,
  `released_by` bigint(20) DEFAULT NULL,
  `amount_released` decimal(12,2) DEFAULT NULL,
  `date_released` date DEFAULT NULL,
  `acknowledged_by_signature` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `deductions`
--

CREATE TABLE `deductions` (
  `deduction_id` bigint(20) NOT NULL,
  `payslip_id` bigint(20) NOT NULL,
  `attendance_id` bigint(20) DEFAULT NULL,
  `deduction_type` varchar(100) DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` bigint(20) NOT NULL,
  `department_name` varchar(150) DEFAULT NULL,
  `manager_employee_id` bigint(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `department_name`, `manager_employee_id`, `is_active`) VALUES
(1, 'Human Resources', 1, 0),
(2, 'Finance', 2, 1),
(3, 'Information Technology', 3, 1),
(4, 'Sales and Marketing', NULL, 1),
(5, 'Operations', NULL, 1),
(6, 'Accounting', 5, 1);

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `employee_id` bigint(20) NOT NULL,
  `employee_number` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `civil_status` varchar(30) DEFAULT NULL,
  `position_id` bigint(20) DEFAULT NULL,
  `department_id` bigint(20) DEFAULT NULL,
  `date_hired` date DEFAULT NULL,
  `employment_status` varchar(30) DEFAULT NULL,
  `tin_number` varchar(50) DEFAULT NULL,
  `sss_number` varchar(50) DEFAULT NULL,
  `philhealth_number` varchar(50) DEFAULT NULL,
  `pagibig_number` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`employee_id`, `employee_number`, `first_name`, `last_name`, `birth_date`, `civil_status`, `position_id`, `department_id`, `date_hired`, `employment_status`, `tin_number`, `sss_number`, `philhealth_number`, `pagibig_number`, `is_active`) VALUES
(1, 'EMP-0001', 'Maria', 'Santos', '1990-03-14', 'Single', 1, 1, '2019-06-01', 'Active', '111-222-333-000', '01-2233445-6', '12-345678901-2', '1122-3344-5566', 0),
(2, 'EMP-0002', 'Juan', 'Dela Cruz', '1988-11-02', 'Married', 3, 2, '2018-01-15', 'Active', '222-333-444-000', '02-3344556-7', '13-456789012-3', '2233-4455-6677', 1),
(3, 'EMP-0003', 'Ana', 'Reyes', '1995-07-22', 'Single', 6, 3, '2021-09-10', 'On Leave', '333-444-555-000', '03-4455667-8', '14-567890123-4', '3344-5566-7788', 1),
(4, 'EMP-0004', 'Mark', 'Villanueva', '1992-05-30', 'Married', 8, 4, '2020-02-20', 'Active', '444-555-666-000', '04-5566778-9', '15-678901234-5', '4455-6677-8899', 0),
(5, 'EMP-0005', 'Liza', 'Fernandez', '1993-09-18', 'Single', 9, 5, '2022-03-05', 'Active', '555-666-777-000', '05-6677889-0', '16-789012345-6', '5566-7788-9900', 1),
(6, 'EMP-0006', 'Carlo', 'Ramos', '1997-12-01', 'Single', 10, 5, '2023-07-11', 'Resigned', '666-777-888-000', '06-7788990-1', '17-890123456-7', '6677-8899-0011', 0),
(7, 'EMP-0007', 'Vinchy', 'Fantonial', '2026-09-09', 'Single', 4, 6, '2026-09-07', 'Active', '123123123', '123123', '123123', '123123', 1);

-- --------------------------------------------------------

--
-- Table structure for table `govt_contributions`
--

CREATE TABLE `govt_contributions` (
  `contribution_id` bigint(20) NOT NULL,
  `payslip_id` bigint(20) NOT NULL,
  `sss_ee` decimal(12,2) DEFAULT NULL,
  `sss_er` decimal(12,2) DEFAULT NULL,
  `philhealth_ee` decimal(12,2) DEFAULT NULL,
  `philhealth_er` decimal(12,2) DEFAULT NULL,
  `pagibig_ee` decimal(12,2) DEFAULT NULL,
  `pagibig_er` decimal(12,2) DEFAULT NULL,
  `withholding_tax` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_balances`
--

CREATE TABLE `leave_balances` (
  `balance_id` bigint(20) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `leave_type_id` bigint(20) NOT NULL,
  `year` int(11) DEFAULT NULL,
  `remaining_days` decimal(5,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_requests`
--

CREATE TABLE `leave_requests` (
  `leave_id` bigint(20) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `leave_type_id` bigint(20) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_types`
--

CREATE TABLE `leave_types` (
  `leave_type_id` bigint(20) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `days_per_year` decimal(5,2) DEFAULT NULL,
  `is_paid` tinyint(1) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leave_types`
--

INSERT INTO `leave_types` (`leave_type_id`, `name`, `days_per_year`, `is_paid`, `is_active`) VALUES
(1, 'Vacation Leave', 15.00, 1, 1),
(2, 'Sick Leave', 15.00, 1, 1),
(3, 'Maternity Leave', 105.00, 1, 1),
(4, 'Paternity Leave', 7.00, 1, 1),
(5, 'Unpaid Leave', 0.00, 0, 1),
(6, 'Birthday Leave', 1.00, 1, 1);

-- --------------------------------------------------------

--
-- Table structure for table `loans`
--

CREATE TABLE `loans` (
  `loan_id` bigint(20) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `loan_type` varchar(50) DEFAULT NULL,
  `principal_amount` decimal(12,2) DEFAULT NULL,
  `monthly_amortization` decimal(12,2) DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_payments`
--

CREATE TABLE `loan_payments` (
  `payment_id` bigint(20) NOT NULL,
  `loan_id` bigint(20) NOT NULL,
  `payslip_id` bigint(20) NOT NULL,
  `amount_paid` decimal(12,2) DEFAULT NULL,
  `payment_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `overtime_records`
--

CREATE TABLE `overtime_records` (
  `ot_id` bigint(20) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `ot_date` date DEFAULT NULL,
  `hours` decimal(6,2) DEFAULT NULL,
  `ot_type` varchar(50) DEFAULT NULL,
  `rate_multiplier` decimal(6,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_periods`
--

CREATE TABLE `payroll_periods` (
  `period_id` bigint(20) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `period_type` varchar(30) DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll_runs`
--

CREATE TABLE `payroll_runs` (
  `run_id` bigint(20) NOT NULL,
  `period_id` bigint(20) NOT NULL,
  `processed_by` bigint(20) DEFAULT NULL,
  `run_date` date DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payslips`
--

CREATE TABLE `payslips` (
  `payslip_id` bigint(20) NOT NULL,
  `run_id` bigint(20) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `basic_pay` decimal(12,2) DEFAULT NULL,
  `overtime_pay` decimal(12,2) DEFAULT NULL,
  `gross_pay` decimal(12,2) DEFAULT NULL,
  `total_deductions` decimal(12,2) DEFAULT NULL,
  `net_pay` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `positions`
--

CREATE TABLE `positions` (
  `position_id` bigint(20) NOT NULL,
  `department_id` bigint(20) DEFAULT NULL,
  `title` varchar(150) DEFAULT NULL,
  `salary_grade` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `positions`
--

INSERT INTO `positions` (`position_id`, `department_id`, `title`, `salary_grade`, `is_active`) VALUES
(1, 1, 'HR Manager', 'SG-15', 1),
(2, 1, 'HR Staff', 'SG-8', 1),
(3, 2, 'Finance Manager', 'SG-15', 1),
(4, 2, 'Accountant', 'SG-10', 1),
(5, 3, 'IT Manager', 'SG-15', 1),
(6, 3, 'Software Developer', 'SG-11', 1),
(7, 4, 'Sales Manager', 'SG-14', 1),
(8, 4, 'Sales Associate', 'SG-7', 1),
(9, 5, 'Operations Supervisor', 'SG-12', 1),
(10, 5, 'Warehouse Staff', 'SG-5', 1);

-- --------------------------------------------------------

--
-- Table structure for table `qr_codes`
--

CREATE TABLE `qr_codes` (
  `qr_code_id` bigint(20) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `code_value` varchar(255) DEFAULT NULL,
  `issued_date` date DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `salary_rates`
--

CREATE TABLE `salary_rates` (
  `rate_id` bigint(20) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `basic_salary` decimal(12,2) DEFAULT NULL,
  `rate_type` varchar(30) DEFAULT NULL,
  `effective_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` bigint(20) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `password_hash` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `role`, `is_active`, `password_hash`) VALUES
(1, 'admin', 'Administrator', 1, '$2y$10$X7Y6Z5W4V3U2T1S0R9Q8PeH1J2K3L4M5N6O7P8Q9R0S1T2U3V4W5'),
(3, 'finance.juan', 'Payroll Officer', 1, '$2y$10$X7Y6Z5W4V3U2T1S0R9Q8PeH1J2K3L4M5N6O7P8Q9R0S1T2U3V4W5');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `allowances`
--
ALTER TABLE `allowances`
  ADD PRIMARY KEY (`allowance_id`),
  ADD KEY `fk_allowances_payslip` (`payslip_id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`attendance_id`),
  ADD KEY `fk_attendance_employee` (`employee_id`),
  ADD KEY `fk_attendance_qr_code` (`qr_code_id`);

--
-- Indexes for table `cash_disbursements`
--
ALTER TABLE `cash_disbursements`
  ADD PRIMARY KEY (`disbursement_id`),
  ADD KEY `fk_cash_disbursements_payslip` (`payslip_id`),
  ADD KEY `fk_cash_disbursements_user` (`released_by`);

--
-- Indexes for table `deductions`
--
ALTER TABLE `deductions`
  ADD PRIMARY KEY (`deduction_id`),
  ADD KEY `fk_deductions_payslip` (`payslip_id`),
  ADD KEY `fk_deductions_attendance` (`attendance_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD KEY `fk_departments_manager` (`manager_employee_id`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`employee_id`),
  ADD UNIQUE KEY `uq_employees_employee_number` (`employee_number`),
  ADD KEY `fk_employees_position` (`position_id`),
  ADD KEY `fk_employees_department` (`department_id`);

--
-- Indexes for table `govt_contributions`
--
ALTER TABLE `govt_contributions`
  ADD PRIMARY KEY (`contribution_id`),
  ADD KEY `fk_govt_contributions_payslip` (`payslip_id`);

--
-- Indexes for table `leave_balances`
--
ALTER TABLE `leave_balances`
  ADD PRIMARY KEY (`balance_id`),
  ADD UNIQUE KEY `uq_leave_balances` (`employee_id`,`leave_type_id`,`year`),
  ADD KEY `fk_leave_balances_type` (`leave_type_id`);

--
-- Indexes for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD PRIMARY KEY (`leave_id`),
  ADD KEY `fk_leave_requests_employee` (`employee_id`),
  ADD KEY `fk_leave_requests_type` (`leave_type_id`);

--
-- Indexes for table `leave_types`
--
ALTER TABLE `leave_types`
  ADD PRIMARY KEY (`leave_type_id`);

--
-- Indexes for table `loans`
--
ALTER TABLE `loans`
  ADD PRIMARY KEY (`loan_id`),
  ADD KEY `fk_loans_employee` (`employee_id`);

--
-- Indexes for table `loan_payments`
--
ALTER TABLE `loan_payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `fk_loan_payments_loan` (`loan_id`),
  ADD KEY `fk_loan_payments_payslip` (`payslip_id`);

--
-- Indexes for table `overtime_records`
--
ALTER TABLE `overtime_records`
  ADD PRIMARY KEY (`ot_id`),
  ADD KEY `fk_overtime_employee` (`employee_id`);

--
-- Indexes for table `payroll_periods`
--
ALTER TABLE `payroll_periods`
  ADD PRIMARY KEY (`period_id`);

--
-- Indexes for table `payroll_runs`
--
ALTER TABLE `payroll_runs`
  ADD PRIMARY KEY (`run_id`),
  ADD KEY `fk_payroll_runs_period` (`period_id`),
  ADD KEY `fk_payroll_runs_user` (`processed_by`);

--
-- Indexes for table `payslips`
--
ALTER TABLE `payslips`
  ADD PRIMARY KEY (`payslip_id`),
  ADD KEY `fk_payslips_run` (`run_id`),
  ADD KEY `fk_payslips_employee` (`employee_id`);

--
-- Indexes for table `positions`
--
ALTER TABLE `positions`
  ADD PRIMARY KEY (`position_id`),
  ADD KEY `fk_positions_department` (`department_id`);

--
-- Indexes for table `qr_codes`
--
ALTER TABLE `qr_codes`
  ADD PRIMARY KEY (`qr_code_id`),
  ADD UNIQUE KEY `code_value` (`code_value`),
  ADD KEY `fk_qr_codes_employee` (`employee_id`);

--
-- Indexes for table `salary_rates`
--
ALTER TABLE `salary_rates`
  ADD PRIMARY KEY (`rate_id`),
  ADD KEY `fk_salary_rates_employee` (`employee_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `employee_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `leave_types`
--
ALTER TABLE `leave_types`
  MODIFY `leave_type_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `positions`
--
ALTER TABLE `positions`
  MODIFY `position_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `allowances`
--
ALTER TABLE `allowances`
  ADD CONSTRAINT `fk_allowances_payslip` FOREIGN KEY (`payslip_id`) REFERENCES `payslips` (`payslip_id`);

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_attendance_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  ADD CONSTRAINT `fk_attendance_qr_code` FOREIGN KEY (`qr_code_id`) REFERENCES `qr_codes` (`qr_code_id`);

--
-- Constraints for table `cash_disbursements`
--
ALTER TABLE `cash_disbursements`
  ADD CONSTRAINT `fk_cash_disbursements_payslip` FOREIGN KEY (`payslip_id`) REFERENCES `payslips` (`payslip_id`),
  ADD CONSTRAINT `fk_cash_disbursements_user` FOREIGN KEY (`released_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `deductions`
--
ALTER TABLE `deductions`
  ADD CONSTRAINT `fk_deductions_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`attendance_id`),
  ADD CONSTRAINT `fk_deductions_payslip` FOREIGN KEY (`payslip_id`) REFERENCES `payslips` (`payslip_id`);

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `fk_departments_manager` FOREIGN KEY (`manager_employee_id`) REFERENCES `employees` (`employee_id`);

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `fk_employees_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`),
  ADD CONSTRAINT `fk_employees_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`position_id`);

--
-- Constraints for table `govt_contributions`
--
ALTER TABLE `govt_contributions`
  ADD CONSTRAINT `fk_govt_contributions_payslip` FOREIGN KEY (`payslip_id`) REFERENCES `payslips` (`payslip_id`);

--
-- Constraints for table `leave_balances`
--
ALTER TABLE `leave_balances`
  ADD CONSTRAINT `fk_leave_balances_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  ADD CONSTRAINT `fk_leave_balances_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`leave_type_id`);

--
-- Constraints for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD CONSTRAINT `fk_leave_requests_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  ADD CONSTRAINT `fk_leave_requests_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`leave_type_id`);

--
-- Constraints for table `loans`
--
ALTER TABLE `loans`
  ADD CONSTRAINT `fk_loans_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`);

--
-- Constraints for table `loan_payments`
--
ALTER TABLE `loan_payments`
  ADD CONSTRAINT `fk_loan_payments_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`loan_id`),
  ADD CONSTRAINT `fk_loan_payments_payslip` FOREIGN KEY (`payslip_id`) REFERENCES `payslips` (`payslip_id`);

--
-- Constraints for table `overtime_records`
--
ALTER TABLE `overtime_records`
  ADD CONSTRAINT `fk_overtime_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`);

--
-- Constraints for table `payroll_runs`
--
ALTER TABLE `payroll_runs`
  ADD CONSTRAINT `fk_payroll_runs_period` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`period_id`),
  ADD CONSTRAINT `fk_payroll_runs_user` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `payslips`
--
ALTER TABLE `payslips`
  ADD CONSTRAINT `fk_payslips_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  ADD CONSTRAINT `fk_payslips_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`run_id`);

--
-- Constraints for table `positions`
--
ALTER TABLE `positions`
  ADD CONSTRAINT `fk_positions_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`);

--
-- Constraints for table `qr_codes`
--
ALTER TABLE `qr_codes`
  ADD CONSTRAINT `fk_qr_codes_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`);

--
-- Constraints for table `salary_rates`
--
ALTER TABLE `salary_rates`
  ADD CONSTRAINT `fk_salary_rates_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
