-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: payroll_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Current Database: `payroll_db`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `payroll_db` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;

USE `payroll_db`;

--
-- Table structure for table `allowances`
--

DROP TABLE IF EXISTS `allowances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `allowances` (
  `allowance_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `payslip_id` bigint(20) NOT NULL,
  `allowance_type` varchar(100) DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`allowance_id`),
  KEY `fk_allowances_payslip` (`payslip_id`),
  CONSTRAINT `fk_allowances_payslip` FOREIGN KEY (`payslip_id`) REFERENCES `payslips` (`payslip_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `allowances`
--

LOCK TABLES `allowances` WRITE;
/*!40000 ALTER TABLE `allowances` DISABLE KEYS */;
/*!40000 ALTER TABLE `allowances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance`
--

DROP TABLE IF EXISTS `attendance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance` (
  `attendance_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) NOT NULL,
  `qr_code_id` bigint(20) DEFAULT NULL,
  `work_date` date DEFAULT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `scan_device_id` varchar(100) DEFAULT NULL,
  `hours_worked` decimal(6,2) DEFAULT NULL,
  `minutes_late` int(11) DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`attendance_id`),
  KEY `fk_attendance_employee` (`employee_id`),
  KEY `fk_attendance_qr_code` (`qr_code_id`),
  CONSTRAINT `fk_attendance_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  CONSTRAINT `fk_attendance_qr_code` FOREIGN KEY (`qr_code_id`) REFERENCES `qr_codes` (`qr_code_id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance`
--

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_log`
--

DROP TABLE IF EXISTS `audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_log` (
  `audit_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `method` varchar(10) NOT NULL,
  `http_status` smallint(6) NOT NULL,
  `success` tinyint(1) NOT NULL,
  `message` varchar(500) DEFAULT NULL,
  `target` varchar(200) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`audit_id`),
  KEY `idx_audit_created_at` (`created_at`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `cash_disbursements`
--

DROP TABLE IF EXISTS `cash_disbursements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cash_disbursements` (
  `disbursement_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `payslip_id` bigint(20) NOT NULL,
  `released_by` bigint(20) DEFAULT NULL,
  `amount_released` decimal(12,2) DEFAULT NULL,
  `date_released` date DEFAULT NULL,
  `acknowledged_by_signature` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`disbursement_id`),
  KEY `fk_cash_disbursements_payslip` (`payslip_id`),
  KEY `fk_cash_disbursements_user` (`released_by`),
  CONSTRAINT `fk_cash_disbursements_payslip` FOREIGN KEY (`payslip_id`) REFERENCES `payslips` (`payslip_id`),
  CONSTRAINT `fk_cash_disbursements_user` FOREIGN KEY (`released_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `cash_disbursements`
--

LOCK TABLES `cash_disbursements` WRITE;
/*!40000 ALTER TABLE `cash_disbursements` DISABLE KEYS */;
/*!40000 ALTER TABLE `cash_disbursements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `deductions`
--

DROP TABLE IF EXISTS `deductions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `deductions` (
  `deduction_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `payslip_id` bigint(20) NOT NULL,
  `attendance_id` bigint(20) DEFAULT NULL,
  `deduction_type` varchar(100) DEFAULT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`deduction_id`),
  KEY `fk_deductions_payslip` (`payslip_id`),
  KEY `fk_deductions_attendance` (`attendance_id`),
  CONSTRAINT `fk_deductions_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`attendance_id`),
  CONSTRAINT `fk_deductions_payslip` FOREIGN KEY (`payslip_id`) REFERENCES `payslips` (`payslip_id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `deductions`
--

LOCK TABLES `deductions` WRITE;
/*!40000 ALTER TABLE `deductions` DISABLE KEYS */;
/*!40000 ALTER TABLE `deductions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `departments`
--

DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `department_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `department_name` varchar(150) DEFAULT NULL,
  `manager_employee_id` bigint(20) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`department_id`),
  KEY `fk_departments_manager` (`manager_employee_id`),
  CONSTRAINT `fk_departments_manager` FOREIGN KEY (`manager_employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `departments`
--

LOCK TABLES `departments` WRITE;
/*!40000 ALTER TABLE `departments` DISABLE KEYS */;
INSERT INTO `departments` VALUES (4,'Human Resources',4,1),(5,'IT Department',4,1);
/*!40000 ALTER TABLE `departments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `employees`
--

DROP TABLE IF EXISTS `employees`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `employees` (
  `employee_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_number` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `birth_date` date DEFAULT NULL,
  `civil_status` varchar(30) DEFAULT NULL,
  `position_id` bigint(20) DEFAULT NULL,
  `department_id` bigint(20) DEFAULT NULL,
  `date_hired` date DEFAULT NULL,
  `employment_status` varchar(30) DEFAULT NULL,
  `basic_salary` decimal(12,2) DEFAULT NULL,
  `tin_number` varchar(50) DEFAULT NULL,
  `sss_number` varchar(50) DEFAULT NULL,
  `philhealth_number` varchar(50) DEFAULT NULL,
  `pagibig_number` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`employee_id`),
  UNIQUE KEY `uq_employees_employee_number` (`employee_number`),
  KEY `fk_employees_position` (`position_id`),
  KEY `fk_employees_department` (`department_id`),
  CONSTRAINT `fk_employees_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`),
  CONSTRAINT `fk_employees_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`position_id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
INSERT INTO `employees` VALUES (4,'EMP-0001','Vinchy','Fantonial','1995-01-01','Single',4,4,'2025-01-01','Active',60000.00,'TIN-EMP-0001','SSS-EMP-0001','PH-EMP-0001','PI-EMP-0001',1),(5,'EMP-0002','Juan','Dela Cruz','1995-01-01','Single',5,5,'2025-01-01','Active',45000.00,'TIN-EMP-0002','SSS-EMP-0002','PH-EMP-0002','PI-EMP-0002',1),(8,'EMP-0003','Harry Ej','Sangcopan','2006-06-06','Separated',4,4,'2026-10-16','Active',10000.00,'123-456-789-004','01-2233445-8','12-345678901-4','1122-3344-5568',1);
/*!40000 ALTER TABLE `employees` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `govt_contributions`
--

DROP TABLE IF EXISTS `govt_contributions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `govt_contributions` (
  `contribution_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `payslip_id` bigint(20) NOT NULL,
  `sss_ee` decimal(12,2) DEFAULT NULL,
  `sss_er` decimal(12,2) DEFAULT NULL,
  `philhealth_ee` decimal(12,2) DEFAULT NULL,
  `philhealth_er` decimal(12,2) DEFAULT NULL,
  `pagibig_ee` decimal(12,2) DEFAULT NULL,
  `pagibig_er` decimal(12,2) DEFAULT NULL,
  `withholding_tax` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`contribution_id`),
  KEY `fk_govt_contributions_payslip` (`payslip_id`),
  CONSTRAINT `fk_govt_contributions_payslip` FOREIGN KEY (`payslip_id`) REFERENCES `payslips` (`payslip_id`)
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `govt_contributions`
--

LOCK TABLES `govt_contributions` WRITE;
/*!40000 ALTER TABLE `govt_contributions` DISABLE KEYS */;
INSERT INTO `govt_contributions` VALUES (1,1,875.00,1750.00,750.00,750.00,100.00,100.00,3259.17),(2,2,875.00,1750.00,562.50,562.50,100.00,100.00,1796.67),(3,3,250.00,500.00,125.00,125.00,100.00,100.00,0.00);
/*!40000 ALTER TABLE `govt_contributions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_balances`
--

DROP TABLE IF EXISTS `leave_balances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_balances` (
  `balance_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) NOT NULL,
  `leave_type_id` bigint(20) NOT NULL,
  `year` int(11) DEFAULT NULL,
  `remaining_days` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`balance_id`),
  UNIQUE KEY `uq_leave_balances` (`employee_id`,`leave_type_id`,`year`),
  KEY `fk_leave_balances_type` (`leave_type_id`),
  CONSTRAINT `fk_leave_balances_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  CONSTRAINT `fk_leave_balances_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`leave_type_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_balances`
--

LOCK TABLES `leave_balances` WRITE;
/*!40000 ALTER TABLE `leave_balances` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_balances` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_requests`
--

DROP TABLE IF EXISTS `leave_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_requests` (
  `leave_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) NOT NULL,
  `leave_type_id` bigint(20) NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`leave_id`),
  KEY `fk_leave_requests_employee` (`employee_id`),
  KEY `fk_leave_requests_type` (`leave_type_id`),
  CONSTRAINT `fk_leave_requests_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  CONSTRAINT `fk_leave_requests_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`leave_type_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_requests`
--

LOCK TABLES `leave_requests` WRITE;
/*!40000 ALTER TABLE `leave_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_requests` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `leave_types`
--

DROP TABLE IF EXISTS `leave_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `leave_types` (
  `leave_type_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `days_per_year` decimal(5,2) DEFAULT NULL,
  `is_paid` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`leave_type_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_types`
--

LOCK TABLES `leave_types` WRITE;
/*!40000 ALTER TABLE `leave_types` DISABLE KEYS */;
/*!40000 ALTER TABLE `leave_types` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loan_payments`
--

DROP TABLE IF EXISTS `loan_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loan_payments` (
  `payment_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `loan_id` bigint(20) NOT NULL,
  `payslip_id` bigint(20) NOT NULL,
  `amount_paid` decimal(12,2) DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  PRIMARY KEY (`payment_id`),
  KEY `fk_loan_payments_loan` (`loan_id`),
  KEY `fk_loan_payments_payslip` (`payslip_id`),
  CONSTRAINT `fk_loan_payments_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`loan_id`),
  CONSTRAINT `fk_loan_payments_payslip` FOREIGN KEY (`payslip_id`) REFERENCES `payslips` (`payslip_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loan_payments`
--

LOCK TABLES `loan_payments` WRITE;
/*!40000 ALTER TABLE `loan_payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `loan_payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `loans`
--

DROP TABLE IF EXISTS `loans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loans` (
  `loan_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) NOT NULL,
  `loan_type` varchar(50) DEFAULT NULL,
  `principal_amount` decimal(12,2) DEFAULT NULL,
  `monthly_amortization` decimal(12,2) DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`loan_id`),
  KEY `fk_loans_employee` (`employee_id`),
  CONSTRAINT `fk_loans_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `loans`
--

LOCK TABLES `loans` WRITE;
/*!40000 ALTER TABLE `loans` DISABLE KEYS */;
/*!40000 ALTER TABLE `loans` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `overtime_records`
--

DROP TABLE IF EXISTS `overtime_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `overtime_records` (
  `ot_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) NOT NULL,
  `ot_date` date DEFAULT NULL,
  `hours` decimal(6,2) DEFAULT NULL,
  `ot_type` varchar(50) DEFAULT NULL,
  `rate_multiplier` decimal(6,2) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Pending',
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `requested_at` datetime DEFAULT NULL,
  `approved_by` bigint(20) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`ot_id`),
  KEY `fk_overtime_employee` (`employee_id`),
  KEY `idx_overtime_status_date` (`status`,`ot_date`),
  KEY `idx_overtime_approved_by` (`approved_by`),
  CONSTRAINT `fk_overtime_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `overtime_records`
--

LOCK TABLES `overtime_records` WRITE;
/*!40000 ALTER TABLE `overtime_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `overtime_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_periods`
--

DROP TABLE IF EXISTS `payroll_periods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_periods` (
  `period_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `pay_date` date DEFAULT NULL,
  `period_type` varchar(30) DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`period_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_periods`
--

LOCK TABLES `payroll_periods` WRITE;
/*!40000 ALTER TABLE `payroll_periods` DISABLE KEYS */;
INSERT INTO `payroll_periods` VALUES (1,'2026-10-16','2026-10-30','2026-10-05','Semi-monthly','Processed');
/*!40000 ALTER TABLE `payroll_periods` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payroll_runs`
--

DROP TABLE IF EXISTS `payroll_runs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payroll_runs` (
  `run_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `period_id` bigint(20) NOT NULL,
  `processed_by` bigint(20) DEFAULT NULL,
  `run_date` date DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`run_id`),
  KEY `fk_payroll_runs_period` (`period_id`),
  KEY `fk_payroll_runs_user` (`processed_by`),
  CONSTRAINT `fk_payroll_runs_period` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`period_id`),
  CONSTRAINT `fk_payroll_runs_user` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_runs`
--

LOCK TABLES `payroll_runs` WRITE;
/*!40000 ALTER TABLE `payroll_runs` DISABLE KEYS */;
INSERT INTO `payroll_runs` VALUES (1,1,NULL,'2026-10-17','Completed');
/*!40000 ALTER TABLE `payroll_runs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `payslips`
--

DROP TABLE IF EXISTS `payslips`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payslips` (
  `payslip_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `run_id` bigint(20) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `basic_pay` decimal(12,2) DEFAULT NULL,
  `overtime_pay` decimal(12,2) DEFAULT NULL,
  `gross_pay` decimal(12,2) DEFAULT NULL,
  `total_deductions` decimal(12,2) DEFAULT NULL,
  `net_pay` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`payslip_id`),
  KEY `fk_payslips_run` (`run_id`),
  KEY `fk_payslips_employee` (`employee_id`),
  CONSTRAINT `fk_payslips_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  CONSTRAINT `fk_payslips_run` FOREIGN KEY (`run_id`) REFERENCES `payroll_runs` (`run_id`)
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payslips`
--

LOCK TABLES `payslips` WRITE;
/*!40000 ALTER TABLE `payslips` DISABLE KEYS */;
INSERT INTO `payslips` VALUES (1,1,4,30000.00,0.00,30000.00,4984.17,25015.83),(2,1,5,22500.00,0.00,22500.00,3334.17,19165.83),(3,1,8,5000.00,0.00,5000.00,475.00,4525.00);
/*!40000 ALTER TABLE `payslips` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `positions`
--

DROP TABLE IF EXISTS `positions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `positions` (
  `position_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `department_id` bigint(20) DEFAULT NULL,
  `title` varchar(150) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`position_id`),
  KEY `fk_positions_department` (`department_id`),
  CONSTRAINT `fk_positions_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `positions`
--

LOCK TABLES `positions` WRITE;
/*!40000 ALTER TABLE `positions` DISABLE KEYS */;
INSERT INTO `positions` VALUES (4,4,'Manager',1),(5,5,'Maintenance',1);
/*!40000 ALTER TABLE `positions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `qr_codes`
--

DROP TABLE IF EXISTS `qr_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `qr_codes` (
  `qr_code_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) NOT NULL,
  `code_value` varchar(255) DEFAULT NULL,
  `issued_date` date DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`qr_code_id`),
  UNIQUE KEY `code_value` (`code_value`),
  KEY `fk_qr_codes_employee` (`employee_id`),
  CONSTRAINT `fk_qr_codes_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `qr_codes`
--

LOCK TABLES `qr_codes` WRITE;
/*!40000 ALTER TABLE `qr_codes` DISABLE KEYS */;
/*!40000 ALTER TABLE `qr_codes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `salary_rates`
--

DROP TABLE IF EXISTS `salary_rates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `salary_rates` (
  `rate_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) NOT NULL,
  `basic_salary` decimal(12,2) DEFAULT NULL,
  `rate_type` varchar(30) DEFAULT NULL,
  `effective_date` date DEFAULT NULL,
  PRIMARY KEY (`rate_id`),
  KEY `fk_salary_rates_employee` (`employee_id`),
  CONSTRAINT `fk_salary_rates_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `salary_rates`
--

LOCK TABLES `salary_rates` WRITE;
/*!40000 ALTER TABLE `salary_rates` DISABLE KEYS */;
/*!40000 ALTER TABLE `salary_rates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) DEFAULT NULL,
  `employee_id` bigint(20) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `password_hash` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (4,'admin',NULL,'Administrator',1,'$2y$10$Ey5ttBp8vVD3tVHwjnZvN.dG0GU2y1WCIVcSr6BlKwfSuerRaVxSi'),(5,'vinchy',4,'Employee',1,'$2y$10$Vl/K9Mew3DaMvn5m0FK85eOLwOTwwoYQxB0hbWqBVHc4z73bjYkdK'),(34,'juan.delacruz',5,'Employee',1,'$2y$10$MGGVmrO6H8IjbkJtisPmaODdXa/JrCVFYcUoeYwg3192XqNiUxorW');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'payroll_db'
--

--
-- Dumping routines for database 'payroll_db'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-18 12:20:29
