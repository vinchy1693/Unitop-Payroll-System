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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance`
--

LOCK TABLES `attendance` WRITE;
/*!40000 ALTER TABLE `attendance` DISABLE KEYS */;
INSERT INTO `attendance` VALUES (1,4,1,'2026-10-01','08:00:00','17:00:00','QR Kiosk',8.00,0,'Present'),(2,4,1,'2026-10-02','08:06:00','17:00:00','QR Kiosk',7.90,6,'Late'),(3,4,1,'2026-10-03','08:00:00','17:00:00','QR Kiosk',8.00,0,'Present'),(4,4,1,'2026-10-05','08:00:00','17:00:00','QR Kiosk',8.00,0,'Present'),(6,4,1,'2026-10-06','08:00:00','17:00:00','QR Kiosk',8.00,0,'Present'),(7,4,1,'2026-10-07','08:00:00','17:00:00','QR Kiosk',8.00,0,'Present'),(8,4,1,'2026-10-08','08:06:00','17:00:00','QR Kiosk',7.90,6,'Late'),(9,4,1,'2026-10-09','08:00:00','17:00:00','QR Kiosk',8.00,0,'Present'),(10,4,1,'2026-10-10','08:00:00','17:00:00','QR Kiosk',8.00,0,'Present'),(12,4,1,'2026-10-13','08:00:00','17:00:00','QR Kiosk',8.00,0,'Present'),(13,4,1,'2026-10-14','08:00:00','17:00:00','QR Kiosk',8.00,0,'Present'),(14,4,1,'2026-10-15','08:05:00','17:00:00','QR Kiosk',7.92,5,'Late');
/*!40000 ALTER TABLE `attendance` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attendance_corrections`
--

DROP TABLE IF EXISTS `attendance_corrections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_corrections` (
  `correction_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) NOT NULL,
  `attendance_id` bigint(20) DEFAULT NULL,
  `work_date` date NOT NULL,
  `correction_type` varchar(20) NOT NULL,
  `requested_time_in` time DEFAULT NULL,
  `requested_time_out` time DEFAULT NULL,
  `reason` varchar(500) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Pending',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` bigint(20) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_remarks` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`correction_id`),
  KEY `idx_corrections_status` (`status`),
  KEY `idx_corrections_employee_date` (`employee_id`,`work_date`),
  KEY `idx_corrections_attendance` (`attendance_id`),
  CONSTRAINT `fk_corrections_attendance` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`attendance_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_corrections_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attendance_corrections`
--

LOCK TABLES `attendance_corrections` WRITE;
/*!40000 ALTER TABLE `attendance_corrections` DISABLE KEYS */;
/*!40000 ALTER TABLE `attendance_corrections` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=127 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_log`
--

LOCK TABLES `audit_log` WRITE;
/*!40000 ALTER TABLE `audit_log` DISABLE KEYS */;
INSERT INTO `audit_log` VALUES (1,90,'payroll','Payroll Officer','qr_codes.php','POST',200,1,'QR code generated.',NULL,'{\"employee_id\":4}','::1','2026-10-18 14:35:00'),(2,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked in at 08:00 AM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"08:00:00\",\"demo_date\":\"2026-10-01\"}','::1','2026-10-18 14:35:14'),(3,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked out at 05:00 PM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"17:00:00\",\"demo_date\":\"2026-10-01\"}','::1','2026-10-18 14:35:20'),(4,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked in at 08:06 AM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"08:06:00\",\"demo_date\":\"2026-10-02\"}','::1','2026-10-18 14:35:38'),(5,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked out at 05:00 PM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"17:00:00\",\"demo_date\":\"2026-10-02\"}','::1','2026-10-18 14:35:59'),(6,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked in at 08:00 AM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"08:00:00\",\"demo_date\":\"2026-10-03\"}','::1','2026-10-18 14:36:29'),(7,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked out at 05:00 PM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"17:00:00\",\"demo_date\":\"2026-10-03\"}','::1','2026-10-18 14:36:40'),(8,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked in at 08:00 AM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"08:00:00\",\"demo_date\":\"2026-10-05\"}','::1','2026-10-18 14:37:07'),(9,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked out at 05:00 PM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"17:00:00\",\"demo_date\":\"2026-10-05\"}','::1','2026-10-18 14:37:23'),(10,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked in at 05:00 PM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"17:00:00\",\"demo_date\":\"2026-10-06\"}','::1','2026-10-18 14:37:33'),(11,90,'payroll','Payroll Officer','attendance.php','DELETE',200,1,'Attendance record deleted.',NULL,NULL,'::1','2026-10-18 14:37:46'),(12,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked in at 08:00 AM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"08:00:00\",\"demo_date\":\"2026-10-06\"}','::1','2026-10-18 14:37:49'),(13,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked out at 05:00 PM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"17:00:00\",\"demo_date\":\"2026-10-06\"}','::1','2026-10-18 14:38:32'),(14,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked in at 08:00 AM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"08:00:00\",\"demo_date\":\"2026-10-07\"}','::1','2026-10-18 14:39:03'),(15,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked out at 05:00 PM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"17:00:00\",\"demo_date\":\"2026-10-07\"}','::1','2026-10-18 14:39:11'),(16,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked in at 08:06 AM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"08:06:00\",\"demo_date\":\"2026-10-08\"}','::1','2026-10-18 14:39:34'),(17,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked out at 05:00 PM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"17:00:00\",\"demo_date\":\"2026-10-08\"}','::1','2026-10-18 14:39:45'),(18,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked in at 08:00 AM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"08:00:00\",\"demo_date\":\"2026-10-09\"}','::1','2026-10-18 14:39:53'),(19,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked out at 05:00 PM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"17:00:00\",\"demo_date\":\"2026-10-09\"}','::1','2026-10-18 14:39:58'),(20,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked in at 08:00 AM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"08:00:00\",\"demo_date\":\"2026-10-10\"}','::1','2026-10-18 14:40:09'),(21,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked out at 05:00 PM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"17:00:00\",\"demo_date\":\"2026-10-10\"}','::1','2026-10-18 14:40:15'),(22,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked in at 08:00 AM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"08:00:00\",\"demo_date\":\"2026-10-12\"}','::1','2026-10-18 14:40:25'),(23,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked out at 05:00 PM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"17:00:00\",\"demo_date\":\"2026-10-12\"}','::1','2026-10-18 14:40:32'),(24,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked in at 08:00 AM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"08:00:00\",\"demo_date\":\"2026-10-13\"}','::1','2026-10-18 14:40:57'),(25,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked out at 05:00 PM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"17:00:00\",\"demo_date\":\"2026-10-13\"}','::1','2026-10-18 14:41:17'),(26,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked in at 08:00 AM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"08:00:00\",\"demo_date\":\"2026-10-14\"}','::1','2026-10-18 14:41:24'),(27,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked out at 05:00 PM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"17:00:00\",\"demo_date\":\"2026-10-14\"}','::1','2026-10-18 14:41:30'),(28,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked in at 08:05 AM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"08:05:00\",\"demo_date\":\"2026-10-15\"}','::1','2026-10-18 14:41:43'),(29,90,'payroll','Payroll Officer','scan_attendance.php','POST',200,1,'Fantonial, Vinchy clocked out at 05:00 PM',NULL,'{\"code_value\":\"UNITOP-21208AD52624A49DE01A00AA83CA113C\",\"demo_mode\":true,\"demo_time\":\"17:00:00\",\"demo_date\":\"2026-10-15\"}','::1','2026-10-18 14:41:56'),(30,90,'payroll','Payroll Officer','payroll_periods.php','POST',200,1,'Payroll period created.',NULL,'{\"period_type\":\"Semi-monthly\",\"pay_date\":\"2026-10-20\",\"start_date\":\"2026-10-01\",\"end_date\":\"2026-10-15\"}','::1','2026-10-18 14:42:29'),(31,90,'payroll','Payroll Officer','payroll_runs.php','POST',200,1,'Payroll run submitted for approval. 3 payslip(s) generated, 0 employee(s) skipped (no basic salary).',NULL,'{\"period_id\":1}','::1','2026-10-18 14:42:43'),(32,90,'payroll','Payroll Officer','payroll_periods.php','POST',200,1,'Payroll period created.',NULL,'{\"period_type\":\"Semi-monthly\",\"pay_date\":\"2026-10-20\",\"start_date\":\"2026-10-01\",\"end_date\":\"2026-10-15\"}','::1','2026-10-18 14:44:45'),(33,90,'payroll','Payroll Officer','payroll_runs.php','POST',200,1,'Payroll run submitted for approval. 3 payslip(s) generated, 0 employee(s) skipped (no basic salary).',NULL,'{\"period_id\":1}','::1','2026-10-18 14:44:46'),(34,90,'payroll','Payroll Officer','leave_types.php','POST',200,1,'Leave type added.',NULL,'{\"name\":\"MATERNAL LEAVE\",\"days_per_year\":\"105\"}','::1','2026-10-18 14:45:46'),(35,90,'payroll','Payroll Officer','leave_requests.php','POST',200,1,'Leave request filed.',NULL,'{\"employee_id\":\"4\",\"leave_type_id\":\"1\",\"start_date\":\"2026-10-16\",\"end_date\":\"2026-10-31\"}','::1','2026-10-18 14:46:03'),(36,90,'payroll','Payroll Officer','leave_requests.php','PATCH',200,1,'Leave request Approved. (11 working day(s))','leave_id=1','{\"leave_id\":1,\"status\":\"Approved\"}','::1','2026-10-18 14:46:05'),(37,90,'payroll','Payroll Officer','payroll_periods.php','POST',409,0,'This period overlaps with an existing Semi-monthly period.',NULL,'{\"period_type\":\"Semi-monthly\",\"pay_date\":\"2026-11-05\",\"start_date\":\"2026-10-15\",\"end_date\":\"2026-10-31\"}','::1','2026-10-18 14:46:28'),(38,90,'payroll','Payroll Officer','bank_file.php','GET',422,0,'Bank file not generated: 3 employee(s) have no complete bank details on file. Add the payment details and export again. Missing: EMP-0002 - Dela Cruz, Juan (missing bank name and bank account number); EMP-0001 - Fantonial, Vinchy (missing bank name and bank account number); EMP-0003 - Sangcopan, Harry Ej (missing bank name and bank account number).','run_id=1','{\"run_id\":1,\"format\":\"bank\",\"status\":\"Submitted\",\"period\":\"2026-10-01 to 2026-10-15\",\"file\":\"bank-file_2026-10-01_2026-10-15.csv\",\"missing_employees\":[\"EMP-0002\",\"EMP-0001\",\"EMP-0003\"]}','::1','2026-10-18 14:46:48'),(39,90,'payroll','Payroll Officer','bank_file.php','GET',200,1,'Payroll register exported: 3 payslip(s).','run_id=1','{\"run_id\":1,\"format\":\"register\",\"status\":\"Submitted\",\"period\":\"2026-10-01 to 2026-10-15\",\"file\":\"payroll-register_2026-10-01_2026-10-15.csv\",\"payslips\":3}','::1','2026-10-18 14:46:51'),(40,90,'payroll','Payroll Officer','bank_file.php','GET',200,1,'Remittance file exported: 3 employee(s).','run_id=1','{\"run_id\":1,\"format\":\"remittance\",\"status\":\"Submitted\",\"period\":\"2026-10-01 to 2026-10-15\",\"file\":\"govt-remittance_2026-10-01_2026-10-15.csv\",\"payslips\":3}','::1','2026-10-18 14:46:57'),(41,90,'payroll','Payroll Officer','payroll_periods.php','POST',409,0,'This period overlaps with an existing Semi-monthly period.',NULL,'{\"period_type\":\"Semi-monthly\",\"pay_date\":\"2026-11-05\",\"start_date\":\"2026-10-15\",\"end_date\":\"2026-10-30\"}','::1','2026-10-18 14:47:35'),(42,90,'payroll','Payroll Officer','payroll_periods.php','POST',409,0,'This period overlaps with an existing Semi-monthly period.',NULL,'{\"period_type\":\"Semi-monthly\",\"pay_date\":\"2026-11-05\",\"start_date\":\"2026-10-15\",\"end_date\":\"2026-10-30\"}','::1','2026-10-18 14:47:41'),(43,90,'payroll','Payroll Officer','payroll_periods.php','POST',409,0,'This period overlaps with an existing Semi-monthly period.',NULL,'{\"period_type\":\"Semi-monthly\",\"pay_date\":\"2026-11-05\",\"start_date\":\"2026-10-15\",\"end_date\":\"2026-10-30\"}','::1','2026-10-18 14:47:45'),(44,90,'payroll','Payroll Officer','payroll_periods.php','POST',200,1,'Payroll period created.',NULL,'{\"period_type\":\"Semi-monthly\",\"pay_date\":\"2026-11-05\",\"start_date\":\"2026-10-16\",\"end_date\":\"2026-10-31\"}','::1','2026-10-18 14:48:33'),(45,90,'payroll','Payroll Officer','payroll_runs.php','POST',200,1,'Payroll run submitted for approval. 3 payslip(s) generated, 0 employee(s) skipped (no basic salary).',NULL,'{\"period_id\":2}','::1','2026-10-18 14:48:34'),(46,90,'payroll','Payroll Officer','leave_requests.php','POST',200,1,'Leave request filed.',NULL,'{\"employee_id\":\"4\",\"leave_type_id\":\"1\",\"start_date\":\"2026-10-16\",\"end_date\":\"2026-10-28\"}','::1','2026-10-18 14:49:58'),(47,90,'payroll','Payroll Officer','leave_requests.php','PATCH',200,1,'Leave request Approved. (9 working day(s))','leave_id=1','{\"leave_id\":1,\"status\":\"Approved\"}','::1','2026-10-18 14:50:00'),(48,90,'payroll','Payroll Officer','payroll_periods.php','POST',200,1,'Payroll period created.',NULL,'{\"period_type\":\"Semi-monthly\",\"pay_date\":\"2026-11-05\",\"start_date\":\"2026-10-16\",\"end_date\":\"2026-10-31\"}','::1','2026-10-18 14:50:55'),(49,90,'payroll','Payroll Officer','payroll_runs.php','POST',200,1,'Payroll run submitted for approval. 3 payslip(s) generated, 0 employee(s) skipped (no basic salary).',NULL,'{\"period_id\":1}','::1','2026-10-18 14:50:57'),(50,400,'dsh_pay_admin','Payroll Officer','auth.php','POST',200,1,'Welcome, dsh_pay_admin.',NULL,'{\"username\":\"dsh_pay_admin\",\"password\":\"***\"}','::1','2026-10-18 14:53:35'),(51,400,'dsh_pay_admin','Payroll Officer','loans.php','POST',200,1,'Loan added.',NULL,'{\"employee_id\":161,\"loan_type\":\"DSH Loan\",\"principal_amount\":400,\"monthly_amortization\":1000}','::1','2026-10-18 14:53:36'),(52,400,'dsh_pay_admin','Payroll Officer','scan_attendance.php','POST',200,1,'Overtime request submitted - waiting for approval.',NULL,'{\"code_value\":\"DSH-PAY-QR-18908\",\"demo_mode\":true,\"demo_date\":\"2027-03-01\",\"demo_time\":\"17:30:00\"}','::1','2026-10-18 14:53:36'),(53,400,'dsh_pay_admin','Payroll Officer','overtime_records.php','POST',409,0,'This employee already has an open overtime request for that date.',NULL,'{\"employee_id\":161,\"ot_date\":\"2027-03-01\",\"ot_type\":\"DSH OT\",\"rate_multiplier\":1.25}','::1','2026-10-18 14:53:36'),(54,400,'dsh_pay_admin','Payroll Officer','overtime_records.php','PATCH',200,1,'Overtime request approved.','ot_id=1','{\"ot_id\":1,\"action\":\"approve\"}','::1','2026-10-18 14:53:36'),(55,400,'dsh_pay_admin','Payroll Officer','scan_attendance.php','POST',200,1,'Overtime started at 18:00.',NULL,'{\"code_value\":\"DSH-PAY-QR-18908\",\"demo_mode\":true,\"demo_date\":\"2027-03-01\",\"demo_time\":\"18:00:00\"}','::1','2026-10-18 14:53:36'),(56,400,'dsh_pay_admin','Payroll Officer','scan_attendance.php','POST',200,1,'Overtime clock-out: 4.00 hour(s).',NULL,'{\"code_value\":\"DSH-PAY-QR-18908\",\"demo_mode\":true,\"demo_date\":\"2027-03-01\",\"demo_time\":\"22:00:00\"}','::1','2026-10-18 14:53:36'),(57,400,'dsh_pay_admin','Payroll Officer','payroll_runs.php','POST',200,1,'Payroll run submitted for approval. 4 payslip(s) generated, 0 employee(s) skipped (no basic salary).',NULL,'{\"period_id\":2}','::1','2026-10-18 14:53:36'),(58,400,'dsh_pay_admin','Payroll Officer','loans.php','DELETE',409,0,'Cannot delete: payments already recorded. Mark as Cancelled instead.',NULL,NULL,'::1','2026-10-18 14:53:36'),(59,400,'dsh_pay_admin','Payroll Officer','payroll_runs.php','POST',200,1,'13th Month run submitted for approval. 4 payslip(s) generated, 0 employee(s) skipped.',NULL,'{\"period_id\":3}','::1','2026-10-18 14:53:36'),(79,406,'dsh_ot_admin','Payroll Officer','auth.php','POST',200,1,'Welcome, dsh_ot_admin.',NULL,'{\"username\":\"dsh_ot_admin\",\"password\":\"***\"}','::1','2026-10-18 14:53:38'),(80,406,'dsh_ot_admin','Payroll Officer','scan_attendance.php','POST',200,1,'Tester, Overtime clocked in at 08:00 AM',NULL,'{\"code_value\":\"DSH-OT-QR-11332-46a50785\",\"demo_mode\":true,\"demo_date\":\"2027-04-01\",\"demo_time\":\"08:00:00\"}','::1','2026-10-18 14:53:38'),(81,406,'dsh_ot_admin','Payroll Officer','scan_attendance.php','POST',200,1,'Tester, Overtime clocked out at 05:00 PM',NULL,'{\"code_value\":\"DSH-OT-QR-11332-46a50785\",\"demo_mode\":true,\"demo_date\":\"2027-04-01\",\"demo_time\":\"17:00:00\"}','::1','2026-10-18 14:53:38'),(82,406,'dsh_ot_admin','Payroll Officer','scan_attendance.php','POST',200,1,'Overtime request submitted - waiting for approval.',NULL,'{\"code_value\":\"DSH-OT-QR-11332-46a50785\",\"demo_mode\":true,\"demo_date\":\"2027-04-01\",\"demo_time\":\"17:30:00\"}','::1','2026-10-18 14:53:38'),(83,406,'dsh_ot_admin','Payroll Officer','scan_attendance.php','POST',409,0,'Your overtime request is still awaiting approval.',NULL,'{\"code_value\":\"DSH-OT-QR-11332-46a50785\",\"demo_mode\":true,\"demo_date\":\"2027-04-01\",\"demo_time\":\"17:31:00\"}','::1','2026-10-18 14:53:38'),(84,406,'dsh_ot_admin','Payroll Officer','payroll_runs.php','POST',200,1,'Payroll run submitted for approval. 4 payslip(s) generated, 0 employee(s) skipped (no basic salary).',NULL,'{\"period_id\":5}','::1','2026-10-18 14:53:38'),(85,406,'dsh_ot_admin','Payroll Officer','overtime_records.php','PATCH',200,1,'Overtime request approved.','ot_id=2','{\"ot_id\":2,\"action\":\"approve\"}','::1','2026-10-18 14:53:38'),(86,407,'dsh_ot_emp','Employee','auth.php','POST',200,1,'Welcome, dsh_ot_emp.',NULL,'{\"username\":\"dsh_ot_emp\",\"password\":\"***\"}','::1','2026-10-18 14:53:38'),(87,407,'dsh_ot_emp','Employee','overtime_records.php','PATCH',403,0,'You do not have permission to perform this action.','ot_id=2','{\"ot_id\":2,\"action\":\"reject\"}','::1','2026-10-18 14:53:38'),(88,406,'dsh_ot_admin','Payroll Officer','scan_attendance.php','POST',200,1,'Overtime started at 18:00.',NULL,'{\"code_value\":\"DSH-OT-QR-11332-46a50785\",\"demo_mode\":true,\"demo_date\":\"2027-04-01\",\"demo_time\":\"18:00:00\"}','::1','2026-10-18 14:53:38'),(89,406,'dsh_ot_admin','Payroll Officer','scan_attendance.php','POST',200,1,'Overtime clock-out: 2.50 hour(s).',NULL,'{\"code_value\":\"DSH-OT-QR-11332-46a50785\",\"demo_mode\":true,\"demo_date\":\"2027-04-01\",\"demo_time\":\"20:30:00\"}','::1','2026-10-18 14:53:38'),(90,406,'dsh_ot_admin','Payroll Officer','payroll_runs.php','POST',200,1,'Payroll run submitted for approval. 4 payslip(s) generated, 0 employee(s) skipped (no basic salary).',NULL,'{\"period_id\":5}','::1','2026-10-18 14:53:38'),(91,406,'dsh_ot_admin','Payroll Officer','scan_attendance.php','POST',200,1,'Overtime request submitted - waiting for approval.',NULL,'{\"code_value\":\"DSH-OT-QR-11332-46a50785\",\"demo_mode\":true,\"demo_date\":\"2027-04-01\",\"demo_time\":\"21:00:00\"}','::1','2026-10-18 14:53:39'),(92,406,'dsh_ot_admin','Payroll Officer','overtime_records.php','PATCH',200,1,'Overtime request rejected.','ot_id=3','{\"ot_id\":3,\"action\":\"reject\"}','::1','2026-10-18 14:53:39'),(93,406,'dsh_ot_admin','Payroll Officer','scan_attendance.php','POST',200,1,'Your overtime request was not approved. A new request has been submitted - waiting for approval.',NULL,'{\"code_value\":\"DSH-OT-QR-11332-46a50785\",\"demo_mode\":true,\"demo_date\":\"2027-04-01\",\"demo_time\":\"21:30:00\"}','::1','2026-10-18 14:53:39'),(94,406,'dsh_ot_admin','Payroll Officer','scan_attendance.php','POST',409,0,'Your overtime request is still awaiting approval.',NULL,'{\"code_value\":\"DSH-OT-QR-11332-46a50785\",\"demo_mode\":true,\"demo_date\":\"2027-04-01\",\"demo_time\":\"21:31:00\"}','::1','2026-10-18 14:53:39'),(95,406,'dsh_ot_admin','Payroll Officer','payroll_runs.php','POST',200,1,'Payroll run submitted for approval. 4 payslip(s) generated, 0 employee(s) skipped (no basic salary).',NULL,'{\"period_id\":5}','::1','2026-10-18 14:53:39'),(115,90,'payroll','Payroll Officer','payroll_periods.php','POST',200,1,'Payroll period created.',NULL,'{\"period_type\":\"Semi-monthly\",\"pay_date\":\"2026-11-05\",\"start_date\":\"2026-10-16\",\"end_date\":\"2026-10-31\"}','::1','2026-10-18 14:56:41'),(116,90,'payroll','Payroll Officer','payroll_runs.php','POST',200,1,'Payroll run submitted for approval. 3 payslip(s) generated, 0 employee(s) skipped (no basic salary).',NULL,'{\"period_id\":1}','::1','2026-10-18 14:56:43'),(117,413,'dsh_pay_admin','Payroll Officer','auth.php','POST',200,1,'Welcome, dsh_pay_admin.',NULL,'{\"username\":\"dsh_pay_admin\",\"password\":\"***\"}','::1','2026-10-18 14:58:32'),(118,413,'dsh_pay_admin','Payroll Officer','loans.php','POST',200,1,'Loan added.',NULL,'{\"employee_id\":167,\"loan_type\":\"DSH Loan\",\"principal_amount\":400,\"monthly_amortization\":1000}','::1','2026-10-18 14:58:32'),(119,413,'dsh_pay_admin','Payroll Officer','scan_attendance.php','POST',200,1,'Overtime request submitted - waiting for approval.',NULL,'{\"code_value\":\"DSH-PAY-QR-29312\",\"demo_mode\":true,\"demo_date\":\"2027-03-01\",\"demo_time\":\"17:30:00\"}','::1','2026-10-18 14:58:32'),(120,413,'dsh_pay_admin','Payroll Officer','overtime_records.php','POST',409,0,'This employee already has an open overtime request for that date.',NULL,'{\"employee_id\":167,\"ot_date\":\"2027-03-01\",\"ot_type\":\"DSH OT\",\"rate_multiplier\":1.25}','::1','2026-10-18 14:58:32'),(121,413,'dsh_pay_admin','Payroll Officer','overtime_records.php','PATCH',200,1,'Overtime request approved.','ot_id=6','{\"ot_id\":6,\"action\":\"approve\"}','::1','2026-10-18 14:58:32'),(122,413,'dsh_pay_admin','Payroll Officer','scan_attendance.php','POST',200,1,'Overtime started at 18:00.',NULL,'{\"code_value\":\"DSH-PAY-QR-29312\",\"demo_mode\":true,\"demo_date\":\"2027-03-01\",\"demo_time\":\"18:00:00\"}','::1','2026-10-18 14:58:32'),(123,413,'dsh_pay_admin','Payroll Officer','scan_attendance.php','POST',200,1,'Overtime clock-out: 4.00 hour(s).',NULL,'{\"code_value\":\"DSH-PAY-QR-29312\",\"demo_mode\":true,\"demo_date\":\"2027-03-01\",\"demo_time\":\"22:00:00\"}','::1','2026-10-18 14:58:32'),(124,413,'dsh_pay_admin','Payroll Officer','payroll_runs.php','POST',200,1,'Payroll run submitted for approval. 4 payslip(s) generated, 0 employee(s) skipped (no basic salary).',NULL,'{\"period_id\":2}','::1','2026-10-18 14:58:33'),(125,413,'dsh_pay_admin','Payroll Officer','loans.php','DELETE',409,0,'Cannot delete: payments already recorded. Mark as Cancelled instead.',NULL,NULL,'::1','2026-10-18 14:58:33'),(126,413,'dsh_pay_admin','Payroll Officer','payroll_runs.php','POST',200,1,'13th Month run submitted for approval. 4 payslip(s) generated, 0 employee(s) skipped.',NULL,'{\"period_id\":3}','::1','2026-10-18 14:58:33');
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  `supervisor_id` bigint(20) DEFAULT NULL,
  `date_hired` date DEFAULT NULL,
  `employment_status` varchar(30) DEFAULT NULL,
  `basic_salary` decimal(12,2) DEFAULT NULL,
  `tin_number` varchar(50) DEFAULT NULL,
  `sss_number` varchar(50) DEFAULT NULL,
  `philhealth_number` varchar(50) DEFAULT NULL,
  `pagibig_number` varchar(50) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_account_name` varchar(120) DEFAULT NULL,
  `bank_account_number` varchar(40) DEFAULT NULL,
  `mobile_number` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `emergency_contact_name` varchar(120) DEFAULT NULL,
  `emergency_contact_number` varchar(30) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`employee_id`),
  UNIQUE KEY `uq_employees_employee_number` (`employee_number`),
  KEY `fk_employees_position` (`position_id`),
  KEY `fk_employees_department` (`department_id`),
  KEY `idx_employees_supervisor` (`supervisor_id`),
  CONSTRAINT `fk_employees_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`),
  CONSTRAINT `fk_employees_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`position_id`),
  CONSTRAINT `fk_employees_supervisor` FOREIGN KEY (`supervisor_id`) REFERENCES `employees` (`employee_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=168 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `employees`
--

LOCK TABLES `employees` WRITE;
/*!40000 ALTER TABLE `employees` DISABLE KEYS */;
INSERT INTO `employees` VALUES (4,'EMP-0001','Vinchy','Fantonial','1995-01-01','Single',4,4,NULL,'2025-01-01','Active',60000.00,'TIN-EMP-0001','SSS-EMP-0001','PH-EMP-0001','PI-EMP-0001',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1),(5,'EMP-0002','Juan','Dela Cruz','1995-01-01','Single',5,5,NULL,'2025-01-01','Active',45000.00,'TIN-EMP-0002','SSS-EMP-0002','PH-EMP-0002','PI-EMP-0002',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1),(8,'EMP-0003','Harry Ej','Sangcopan','2006-06-06','Separated',4,4,NULL,'2026-10-16','Active',10000.00,'123-456-789-004','01-2233445-8','12-345678901-4','1122-3344-5568',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL,1);
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `govt_contributions`
--

LOCK TABLES `govt_contributions` WRITE;
/*!40000 ALTER TABLE `govt_contributions` DISABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `leave_balances`
--

LOCK TABLES `leave_balances` WRITE;
/*!40000 ALTER TABLE `leave_balances` DISABLE KEYS */;
INSERT INTO `leave_balances` VALUES (1,4,1,2026,96.00);
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
INSERT INTO `leave_requests` VALUES (1,4,1,'2026-10-16','2026-10-28','Approved');
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
INSERT INTO `leave_types` VALUES (1,'MATERNAL LEAVE',105.00,1,1);
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  `recommended_by` bigint(20) DEFAULT NULL,
  `recommended_at` datetime DEFAULT NULL,
  `recommendation_remarks` varchar(500) DEFAULT NULL,
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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_periods`
--

LOCK TABLES `payroll_periods` WRITE;
/*!40000 ALTER TABLE `payroll_periods` DISABLE KEYS */;
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
  `status` varchar(20) NOT NULL DEFAULT 'Submitted',
  `submitted_by` bigint(20) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `approved_by` bigint(20) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `returned_by` bigint(20) DEFAULT NULL,
  `returned_at` datetime DEFAULT NULL,
  `release_approved_by` bigint(20) DEFAULT NULL,
  `release_approved_at` datetime DEFAULT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`run_id`),
  KEY `fk_payroll_runs_period` (`period_id`),
  KEY `fk_payroll_runs_user` (`processed_by`),
  KEY `idx_payroll_runs_status` (`status`),
  KEY `idx_payroll_runs_submitted_by` (`submitted_by`),
  KEY `idx_payroll_runs_approved_by` (`approved_by`),
  CONSTRAINT `fk_payroll_runs_period` FOREIGN KEY (`period_id`) REFERENCES `payroll_periods` (`period_id`),
  CONSTRAINT `fk_payroll_runs_user` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payroll_runs`
--

LOCK TABLES `payroll_runs` WRITE;
/*!40000 ALTER TABLE `payroll_runs` DISABLE KEYS */;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `payslips`
--

LOCK TABLES `payslips` WRITE;
/*!40000 ALTER TABLE `payslips` DISABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `qr_codes`
--

LOCK TABLES `qr_codes` WRITE;
/*!40000 ALTER TABLE `qr_codes` DISABLE KEYS */;
INSERT INTO `qr_codes` VALUES (1,4,'UNITOP-21208AD52624A49DE01A00AA83CA113C','2026-10-18','Active');
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=414 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (4,'admin',NULL,'Administrator',1,'$2y$10$Ey5ttBp8vVD3tVHwjnZvN.dG0GU2y1WCIVcSr6BlKwfSuerRaVxSi'),(5,'vinchy',4,'Employee',1,'$2y$10$Vl/K9Mew3DaMvn5m0FK85eOLwOTwwoYQxB0hbWqBVHc4z73bjYkdK'),(34,'juan.delacruz',5,'Employee',1,'$2y$10$MGGVmrO6H8IjbkJtisPmaODdXa/JrCVFYcUoeYwg3192XqNiUxorW'),(90,'payroll',NULL,'Payroll Officer',1,'$2y$10$S9EcD6AGcF3W.6C/Qc2PjuBJopS3qxiitlQm7fIXXqQ81OwndRP.a'),(91,'approver',NULL,'Approver',1,'$2y$10$xhmC3KpUPDC5hDEXuYr8nuJ6hi.KCdR3cCRyhGuFnbE8RrwrzZSue');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

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

-- Dump completed on 2026-10-18 15:00:06
