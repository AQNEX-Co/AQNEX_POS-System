-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: aqnex_pos
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
-- Table structure for table `accounting_accounts`
--

DROP TABLE IF EXISTS `accounting_accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounting_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `account_type` enum('asset','liability','equity','revenue','expense') NOT NULL,
  `is_parent` tinyint(1) NOT NULL DEFAULT 0,
  `level` int(11) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_reconcilable` tinyint(1) NOT NULL DEFAULT 0 COMMENT '┘è╪│┘à╪ص ╪ذ╪د┘╪ز╪│┘ê┘è╪ر',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '╪د┘╪ص╪│╪د╪ذ ┘à┘╪╣┘ّ┘',
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `accounting_journal`
--

DROP TABLE IF EXISTS `accounting_journal`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounting_journal` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ref_type` enum('sale','return','purchase','expense','receipt','adjustment') NOT NULL,
  `ref_id` int(11) DEFAULT NULL,
  `account_debit` varchar(100) NOT NULL,
  `account_credit` varchar(100) NOT NULL,
  `amount` double NOT NULL DEFAULT 0,
  `description` varchar(500) DEFAULT NULL,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1,
  `amount_foreign` double DEFAULT 0,
  `user` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `box_id` int(11) DEFAULT NULL,
  `sector_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_journal_ref` (`ref_type`,`ref_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `accounting_journal_audit`
--

DROP TABLE IF EXISTS `accounting_journal_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounting_journal_audit` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entry_id` int(11) NOT NULL,
  `action` enum('create','edit','void','approve') NOT NULL,
  `changed_by` varchar(100) NOT NULL DEFAULT '',
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `old_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`old_data`)),
  `new_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`new_data`)),
  `reason` varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_audit_entry` (`entry_id`),
  CONSTRAINT `fk_audit_entry` FOREIGN KEY (`entry_id`) REFERENCES `accounting_journal_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='╪│╪ش┘ ╪ز╪»┘é┘è┘é ┘é┘è┘ê╪» ╪د┘┘è┘ê┘à┘è╪ر';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `accounting_journal_entries`
--

DROP TABLE IF EXISTS `accounting_journal_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounting_journal_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entry_date` date NOT NULL,
  `reference_no` varchar(50) NOT NULL DEFAULT '',
  `description` varchar(500) NOT NULL DEFAULT '',
  `source_type` varchar(50) NOT NULL DEFAULT 'manual' COMMENT 'manual | sale | purchase | voucher | expense | receipt',
  `source_id` int(11) DEFAULT NULL,
  `status` enum('draft','posted','voided') NOT NULL DEFAULT 'posted',
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `approved_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_entry_date` (`entry_date`),
  KEY `idx_source` (`source_type`,`source_id`),
  KEY `idx_status` (`status`),
  KEY `idx_reference_no` (`reference_no`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='╪▒╪ج┘ê╪│ ┘é┘è┘ê╪» ╪د┘┘è┘ê┘à┘è╪ر ╪د┘┘à╪▓╪»┘ê╪ش╪ر';
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER IF NOT EXISTS `trg_clean_orphaned_journal_items` 
AFTER DELETE ON `accounting_journal_entries`
FOR EACH ROW
BEGIN
    DELETE FROM `accounting_journal_items` WHERE `entry_id` = OLD.id;
END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `accounting_journal_history`
--

DROP TABLE IF EXISTS `accounting_journal_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounting_journal_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ref_type` enum('sale','return','purchase','expense','receipt','adjustment') NOT NULL,
  `ref_id` int(11) DEFAULT NULL,
  `account_debit` varchar(100) NOT NULL,
  `account_credit` varchar(100) NOT NULL,
  `amount` double NOT NULL DEFAULT 0,
  `description` varchar(500) DEFAULT NULL,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1,
  `amount_foreign` double DEFAULT 0,
  `user` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `box_id` int(11) DEFAULT NULL,
  `sector_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_journal_ref` (`ref_type`,`ref_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `accounting_journal_items`
--

DROP TABLE IF EXISTS `accounting_journal_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounting_journal_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entry_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `debit` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `credit` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `currency_id` int(11) DEFAULT NULL,
  `exchange_rate` decimal(15,4) NOT NULL DEFAULT 1.0000,
  `memo` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_entry_id` (`entry_id`),
  KEY `idx_account_id` (`account_id`),
  CONSTRAINT `fk_ji_account` FOREIGN KEY (`account_id`) REFERENCES `accounting_accounts` (`id`),
  CONSTRAINT `fk_ji_entry` FOREIGN KEY (`entry_id`) REFERENCES `accounting_journal_entries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='╪ذ┘┘ê╪» ┘é┘è┘ê╪» ╪د┘┘è┘ê┘à┘è╪ر ╪د┘┘à╪▓╪»┘ê╪ش╪ر';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `accounting_sequences`
--

DROP TABLE IF EXISTS `accounting_sequences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounting_sequences` (
  `seq_key` varchar(50) NOT NULL,
  `last_no` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`seq_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `accounting_voucher_items`
--

DROP TABLE IF EXISTS `accounting_voucher_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounting_voucher_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `voucher_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `memo` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_voucher_id` (`voucher_id`),
  CONSTRAINT `fk_vi_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `accounting_vouchers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `accounting_vouchers`
--

DROP TABLE IF EXISTS `accounting_vouchers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounting_vouchers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `voucher_type` enum('receipt','payment') NOT NULL COMMENT 'receipt=┘é╪ذ╪╢ | payment=╪╡╪▒┘',
  `voucher_no` varchar(50) NOT NULL DEFAULT '',
  `voucher_date` date NOT NULL,
  `party_type` enum('customer','supplier','other') NOT NULL DEFAULT 'other',
  `party_id` int(11) DEFAULT NULL,
  `party_name` varchar(150) NOT NULL DEFAULT '',
  `cash_account_id` int(11) NOT NULL COMMENT '╪ص╪│╪د╪ذ ╪د┘╪╡┘╪»┘ê┘é/╪د┘╪ذ┘┘â',
  `box_id` int(11) DEFAULT 0,
  `contra_account_id` int(11) NOT NULL COMMENT '╪ص╪│╪د╪ذ ╪د┘┘à┘é╪د╪ذ┘',
  `amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `currency_id` int(11) DEFAULT NULL,
  `exchange_rate` decimal(15,4) NOT NULL DEFAULT 1.0000,
  `description` varchar(500) NOT NULL DEFAULT '',
  `status` enum('draft','posted','voided') NOT NULL DEFAULT 'posted',
  `journal_entry_id` int(11) DEFAULT NULL COMMENT 'FK to accounting_journal_entries',
  `created_by` varchar(100) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_voucher_no` (`voucher_no`,`voucher_type`),
  KEY `idx_voucher_date` (`voucher_date`),
  KEY `idx_voucher_type` (`voucher_type`),
  KEY `idx_party` (`party_type`,`party_id`),
  KEY `fk_vou_cash_acc` (`cash_account_id`),
  KEY `fk_vou_contra_acc` (`contra_account_id`),
  KEY `fk_vou_je` (`journal_entry_id`),
  CONSTRAINT `fk_vou_cash_acc` FOREIGN KEY (`cash_account_id`) REFERENCES `accounting_accounts` (`id`),
  CONSTRAINT `fk_vou_contra_acc` FOREIGN KEY (`contra_account_id`) REFERENCES `accounting_accounts` (`id`),
  CONSTRAINT `fk_vou_je` FOREIGN KEY (`journal_entry_id`) REFERENCES `accounting_journal_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='╪│┘╪»╪د╪ز ╪د┘┘é╪ذ╪╢ ┘ê╪د┘╪╡╪▒┘';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `branches`
--

DROP TABLE IF EXISTS `branches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `branches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `contacts` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `business_rules`
--

DROP TABLE IF EXISTS `business_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `business_rules` (
  `id` int(11) NOT NULL,
  `allow_negative_stock` tinyint(1) NOT NULL DEFAULT 0,
  `inventory_valuation_method` enum('FIFO','AVG') NOT NULL DEFAULT 'AVG',
  `max_discount_limit` decimal(15,4) NOT NULL DEFAULT 100.0000,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `catid` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `d_s` int(11) NOT NULL DEFAULT 0,
  `requires_serial` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`catid`),
  KEY `catname` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=44 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `currencies`
--

DROP TABLE IF EXISTS `currencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `currencies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(10) NOT NULL,
  `symbol` varchar(10) NOT NULL,
  `exchange_rate` decimal(15,4) NOT NULL DEFAULT 1.0000,
  `is_base` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `customers` (
  `cust_id` int(11) NOT NULL AUTO_INCREMENT,
  `cust_name` varchar(100) NOT NULL,
  `cust_madeen` double(100,2) NOT NULL DEFAULT 0.00,
  `cust_daain` double NOT NULL DEFAULT 0,
  `sale_date` date NOT NULL,
  `take_date` date DEFAULT NULL,
  `phone` varchar(100) NOT NULL DEFAULT '',
  `d_s` int(11) NOT NULL DEFAULT 0,
  `email` varchar(100) DEFAULT '',
  `address` varchar(255) DEFAULT '',
  `credit_limit` decimal(25,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`cust_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expenses`
--

DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expenses` (
  `m_id` int(11) NOT NULL AUTO_INCREMENT,
  `sname` varchar(100) NOT NULL,
  `m_price` double NOT NULL,
  `remark` varchar(100) NOT NULL DEFAULT '',
  `m_date` date NOT NULL,
  `s` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`m_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expenses_history`
--

DROP TABLE IF EXISTS `expenses_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expenses_history` (
  `m_id` int(11) NOT NULL AUTO_INCREMENT,
  `sname` varchar(100) NOT NULL,
  `m_price` double NOT NULL,
  `remark` varchar(100) NOT NULL DEFAULT '',
  `m_date` date NOT NULL,
  `s` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`m_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fiscal_years`
--

DROP TABLE IF EXISTS `fiscal_years`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `fiscal_years` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `is_closed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `installment_plans`
--

DROP TABLE IF EXISTS `installment_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `installment_plans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `down_payment` decimal(14,2) NOT NULL DEFAULT 0.00,
  `remaining_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `installments_count` int(11) NOT NULL DEFAULT 1,
  `status` enum('active','completed','defaulted') NOT NULL DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `installment_schedule`
--

DROP TABLE IF EXISTS `installment_schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `installment_schedule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `plan_id` int(11) NOT NULL,
  `installment_number` int(11) NOT NULL DEFAULT 1,
  `due_date` date NOT NULL,
  `amount_due` decimal(14,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(14,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','paid','overdue') NOT NULL DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inventory_audit_log`
--

DROP TABLE IF EXISTS `inventory_audit_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `action_type` enum('in','out','transfer_out','transfer_in','adjustment') NOT NULL,
  `quantity` decimal(15,4) NOT NULL,
  `cost_price` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `warehouse_id` int(11) NOT NULL,
  `reference_table` varchar(50) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `user_id` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inventory_log`
--

DROP TABLE IF EXISTS `inventory_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `type` enum('purchase','sale','manual') NOT NULL,
  `qty_change` int(11) NOT NULL,
  `new_qty` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `user` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `journal_entries`
--

DROP TABLE IF EXISTS `journal_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `journal_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ref_type` varchar(50) NOT NULL,
  `ref_id` int(11) DEFAULT NULL,
  `account_debit` varchar(100) NOT NULL,
  `account_credit` varchar(100) NOT NULL,
  `debit_entity_type` enum('customer','supplier','fund','general') DEFAULT 'general',
  `debit_entity_id` int(11) DEFAULT NULL,
  `credit_entity_type` enum('customer','supplier','fund','general') DEFAULT 'general',
  `credit_entity_id` int(11) DEFAULT NULL,
  `amount` double NOT NULL DEFAULT 0,
  `description` varchar(500) DEFAULT NULL,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1,
  `amount_foreign` double DEFAULT 0,
  `user` varchar(100) DEFAULT NULL,
  `box_id` int(11) DEFAULT NULL,
  `sector_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_journal_ref` (`ref_type`,`ref_id`),
  KEY `idx_debit_entity` (`debit_entity_type`,`debit_entity_id`),
  KEY `idx_credit_entity` (`credit_entity_type`,`credit_entity_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `journal_entries_history`
--

DROP TABLE IF EXISTS `journal_entries_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `journal_entries_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ref_type` varchar(50) NOT NULL,
  `ref_id` int(11) DEFAULT NULL,
  `account_debit` varchar(100) NOT NULL,
  `account_credit` varchar(100) NOT NULL,
  `debit_entity_type` enum('customer','supplier','fund','general') DEFAULT 'general',
  `debit_entity_id` int(11) DEFAULT NULL,
  `credit_entity_type` enum('customer','supplier','fund','general') DEFAULT 'general',
  `credit_entity_id` int(11) DEFAULT NULL,
  `amount` double NOT NULL DEFAULT 0,
  `description` varchar(500) DEFAULT NULL,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1,
  `amount_foreign` double DEFAULT 0,
  `user` varchar(100) DEFAULT NULL,
  `box_id` int(11) DEFAULT NULL,
  `sector_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_journal_ref` (`ref_type`,`ref_id`),
  KEY `idx_debit_entity` (`debit_entity_type`,`debit_entity_id`),
  KEY `idx_credit_entity` (`credit_entity_type`,`credit_entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `lastt`
--

DROP TABLE IF EXISTS `lastt`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `lastt` (
  `bush_id` int(11) NOT NULL AUTO_INCREMENT,
  `supp_name` varchar(100) NOT NULL,
  `bush_price` double NOT NULL DEFAULT 0,
  `remark` varchar(100) NOT NULL DEFAULT '',
  `bush_date` date NOT NULL,
  PRIMARY KEY (`bush_id`),
  KEY `bush_id` (`bush_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` varchar(50) NOT NULL DEFAULT '',
  `title` varchar(200) NOT NULL DEFAULT '',
  `message` text DEFAULT NULL,
  `related_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `target_role` varchar(20) NOT NULL DEFAULT 'admin',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_vouchers_dtl`
--

DROP TABLE IF EXISTS `payment_vouchers_dtl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_vouchers_dtl` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `voucher_id` int(10) unsigned NOT NULL,
  `expense_account_id` int(11) DEFAULT NULL COMMENT '┘à╪╣╪▒┘ ╪ص╪│╪د╪ذ ╪د┘┘à╪╡╪▒┘ê┘ ┘à┘ ╪┤╪ش╪▒╪ر ╪د┘╪ص╪│╪د╪ذ╪د╪ز',
  `amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `remark` varchar(255) DEFAULT NULL,
  `d_s` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_voucher_id` (`voucher_id`),
  CONSTRAINT `fk_pay_vou_dtl_mst` FOREIGN KEY (`voucher_id`) REFERENCES `payment_vouchers_mst` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_vouchers_mst`
--

DROP TABLE IF EXISTS `payment_vouchers_mst`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_vouchers_mst` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `voucher_no` varchar(50) NOT NULL,
  `voucher_date` date NOT NULL,
  `party_type` enum('supplier','employee','other') NOT NULL DEFAULT 'supplier',
  `party_id` int(11) DEFAULT NULL,
  `party_name` varchar(150) NOT NULL,
  `total_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `payment_method` varchar(50) DEFAULT 'cash',
  `box_id` int(11) NOT NULL DEFAULT 1,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` decimal(15,4) DEFAULT 1.0000,
  `remark` text DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `sector_id` int(11) DEFAULT NULL,
  `d_s` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_voucher_no` (`voucher_no`),
  KEY `idx_party` (`party_type`,`party_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `permissions`
--

DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `permission_key` varchar(100) NOT NULL,
  `name` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `permission_key` (`permission_key`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `printer_settings`
--

DROP TABLE IF EXISTS `printer_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `printer_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `printer_name` varchar(100) NOT NULL DEFAULT '',
  `printer_type` enum('thermal_80','thermal_58','a4','label_zpl') NOT NULL DEFAULT 'thermal_80',
  `connection_type` enum('usb','network','bluetooth') NOT NULL DEFAULT 'usb',
  `ip_address` varchar(50) NOT NULL DEFAULT '',
  `port` int(11) NOT NULL DEFAULT 9100,
  `usb_vendor_id` varchar(20) NOT NULL DEFAULT '',
  `usb_product_id` varchar(20) NOT NULL DEFAULT '',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `template_json` text DEFAULT NULL,
  `d_s` char(1) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `product_barcodes`
--

DROP TABLE IF EXISTS `product_barcodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_barcodes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `barcode` varchar(100) NOT NULL DEFAULT '',
  `unit_id` int(11) DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `d_s` char(1) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_barcode` (`barcode`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `product_batches`
--

DROP TABLE IF EXISTS `product_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `purchase_item_id` int(11) DEFAULT NULL,
  `batch_number` varchar(50) NOT NULL DEFAULT '',
  `quantity` decimal(12,2) NOT NULL DEFAULT 0.00,
  `expiry_date` date DEFAULT NULL,
  `d_s` char(1) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT current_timestamp(),
  `cost_price` decimal(15,4) DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  KEY `idx_product_expiry` (`product_id`,`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `product_serials`
--

DROP TABLE IF EXISTS `product_serials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_serials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `serial_number` varchar(100) NOT NULL DEFAULT '',
  `imei_1` varchar(20) NOT NULL DEFAULT '',
  `imei_2` varchar(20) NOT NULL DEFAULT '',
  `status` enum('in_stock','sold','returned','defective') NOT NULL DEFAULT 'in_stock',
  `purchase_item_id` int(11) DEFAULT NULL,
  `sale_item_id` int(11) DEFAULT NULL,
  `warranty_start` date DEFAULT NULL,
  `warranty_end` date DEFAULT NULL,
  `d_s` char(1) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT current_timestamp(),
  `cost_price` decimal(15,4) DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_imei1` (`imei_1`),
  KEY `idx_product_status` (`product_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `product_units`
--

DROP TABLE IF EXISTS `product_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_units` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `unit_name` varchar(50) NOT NULL DEFAULT '',
  `conversion_factor` decimal(12,4) NOT NULL DEFAULT 1.0000,
  `sale_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `purchase_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `is_base_unit` tinyint(1) NOT NULL DEFAULT 0,
  `d_s` char(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_product` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `quantity` int(20) DEFAULT 0,
  `buy_price` decimal(25,2) DEFAULT 0.00,
  `sale_price` decimal(25,2) NOT NULL DEFAULT 0.00,
  `catid` int(11) unsigned NOT NULL,
  `date` datetime NOT NULL,
  `delete_status` int(11) NOT NULL DEFAULT 0,
  `total` double NOT NULL DEFAULT 0,
  `min_stock_alert` decimal(10,2) NOT NULL DEFAULT 0.00,
  `has_multiple_units` tinyint(1) NOT NULL DEFAULT 0,
  `track_expiry` tinyint(1) NOT NULL DEFAULT 0,
  `sector_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `idx_products_barcode` (`barcode`),
  KEY `categorie_id` (`catid`),
  KEY `quantity` (`quantity`)
) ENGINE=InnoDB AUTO_INCREMENT=586 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_invoices_dtl`
--

DROP TABLE IF EXISTS `purchase_invoices_dtl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_invoices_dtl` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int(10) unsigned NOT NULL COMMENT '┘à╪╣╪▒┘ ┘╪د╪ز┘ê╪▒╪ر ╪د┘┘à╪د╪│╪ز╪▒',
  `product_id` int(10) unsigned DEFAULT NULL COMMENT '┘à╪╣╪▒┘ ╪د┘┘à┘╪ز╪ش (NULL ╪ح╪░╪د ┘â╪د┘ ╪ش╪»┘è╪»)',
  `product_name` varchar(255) NOT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `unit_name` varchar(50) DEFAULT '╪د┘┘ê╪ص╪»╪ر ╪د┘╪ث╪│╪د╪│┘è╪ر',
  `quantity` decimal(15,4) NOT NULL DEFAULT 1.0000,
  `unit_cost` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT '╪│╪╣╪▒ ╪┤╪▒╪د╪ة ╪د┘┘ê╪ص╪»╪ر',
  `total_cost` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT '╪ح╪ش┘à╪د┘┘è ╪ز┘â┘┘╪ر ╪د┘╪ذ┘╪»',
  `d_s` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_invoice_id` (`invoice_id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_pur_inv_dtl_mst` FOREIGN KEY (`invoice_id`) REFERENCES `purchase_invoices_mst` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='╪ز┘╪د╪╡┘è┘ ╪ث╪╡┘╪د┘ ┘╪د╪ز┘ê╪▒╪ر ╪د┘┘à╪┤╪ز╪▒┘è╪د╪ز';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_invoices_mst`
--

DROP TABLE IF EXISTS `purchase_invoices_mst`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_invoices_mst` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(50) NOT NULL COMMENT '╪▒┘é┘à ╪د┘┘╪د╪ز┘ê╪▒╪ر',
  `supp_id` int(11) DEFAULT NULL COMMENT '┘à╪╣╪▒┘ ╪د┘┘à┘ê╪▒╪»',
  `supp_name` varchar(150) NOT NULL COMMENT '╪د╪│┘à ╪د┘┘à┘ê╪▒╪»',
  `invoice_date` date NOT NULL,
  `total_amount` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT '╪د┘╪ح╪ش┘à╪د┘┘è ┘é╪ذ┘ ╪د┘╪«╪╡┘à',
  `discount_amount` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT '┘à╪ذ┘╪║ ╪د┘╪«╪╡┘à',
  `net_amount` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT '╪╡╪د┘┘è ╪د┘┘╪د╪ز┘ê╪▒╪ر',
  `paid_amount` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT '╪د┘┘à╪ذ┘╪║ ╪د┘┘à╪»┘┘ê╪╣',
  `remaining_amount` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT '╪د┘┘à╪ذ┘╪║ ╪د┘┘à╪ز╪ذ┘é┘è (╪ت╪ش┘)',
  `invoice_type` enum('cash','credit','account') NOT NULL DEFAULT 'cash',
  `payment_method` varchar(50) DEFAULT 'cash',
  `wallet_type` varchar(100) DEFAULT NULL,
  `box_id` int(11) NOT NULL DEFAULT 1 COMMENT '┘à╪╣╪▒┘ ╪د┘╪╡┘╪»┘ê┘é',
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` decimal(15,4) DEFAULT 1.0000,
  `remark` text DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `sector_id` int(11) DEFAULT NULL,
  `d_s` tinyint(1) NOT NULL DEFAULT 0 COMMENT '╪ص╪د┘╪ر ╪د┘╪ص╪░┘',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invoice_no` (`invoice_no`),
  KEY `idx_supp_id` (`supp_id`),
  KEY `idx_invoice_date` (`invoice_date`),
  KEY `idx_box_id` (`box_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='╪▒╪ث╪│ ┘╪د╪ز┘ê╪▒╪ر ╪د┘┘à╪┤╪ز╪▒┘è╪د╪ز';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_items`
--

DROP TABLE IF EXISTS `purchase_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_items` (
  `buyid` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_id` int(11) DEFAULT NULL,
  `supp_name` varchar(50) NOT NULL,
  `supp_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `unit_name` varchar(50) DEFAULT NULL,
  `buy_price` double NOT NULL DEFAULT 0,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `pushtosupp` double NOT NULL DEFAULT 0,
  `buys_date` date NOT NULL,
  `total_d` double NOT NULL DEFAULT 0,
  `s` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`buyid`),
  KEY `buys_date` (`buys_date`),
  KEY `idx_purchase_id` (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_items_history`
--

DROP TABLE IF EXISTS `purchase_items_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_items_history` (
  `buyid` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_id` int(11) DEFAULT NULL,
  `supp_name` varchar(50) NOT NULL,
  `supp_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `buy_price` double NOT NULL DEFAULT 0,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `pushtosupp` double NOT NULL DEFAULT 0,
  `buys_date` date NOT NULL,
  `total_d` double NOT NULL DEFAULT 0,
  `s` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`buyid`),
  KEY `buys_date` (`buys_date`),
  KEY `idx_purchase_id` (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_returns`
--

DROP TABLE IF EXISTS `purchase_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(200) NOT NULL,
  `unit_name` varchar(50) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` double NOT NULL,
  `refund_amount` double NOT NULL,
  `reason` varchar(200) DEFAULT NULL,
  `return_date` date NOT NULL,
  `user` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1,
  `status` enum('active','cancelled') DEFAULT 'active',
  `box_id` int(11) DEFAULT NULL,
  `refund_method` enum('cash','credit') DEFAULT 'cash',
  `refund_source` varchar(50) DEFAULT 'box',
  `sector_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_id` (`purchase_id`),
  KEY `box_id` (`box_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_returns_dtl`
--

DROP TABLE IF EXISTS `purchase_returns_dtl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_returns_dtl` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `return_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity` decimal(15,4) NOT NULL DEFAULT 1.0000,
  `unit_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `total_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `d_s` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_return_id` (`return_id`),
  CONSTRAINT `fk_pur_ret_dtl_mst` FOREIGN KEY (`return_id`) REFERENCES `purchase_returns_mst` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_returns_history`
--

DROP TABLE IF EXISTS `purchase_returns_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_returns_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `purchase_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `product_name` varchar(200) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` double NOT NULL,
  `refund_amount` double NOT NULL,
  `reason` varchar(200) DEFAULT NULL,
  `return_date` date NOT NULL,
  `user` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1,
  `status` enum('active','cancelled') DEFAULT 'active',
  `box_id` int(11) DEFAULT NULL,
  `refund_method` enum('cash','credit') DEFAULT 'cash',
  `refund_source` varchar(50) DEFAULT 'box',
  `sector_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `purchase_id` (`purchase_id`),
  KEY `box_id` (`box_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_returns_mst`
--

DROP TABLE IF EXISTS `purchase_returns_mst`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_returns_mst` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `return_no` varchar(50) NOT NULL,
  `original_purchase_id` int(10) unsigned DEFAULT NULL COMMENT '┘à╪╣╪▒┘ ┘╪د╪ز┘ê╪▒╪ر ╪د┘╪┤╪▒╪د╪ة ╪د┘╪ث╪╡┘┘è╪ر',
  `supp_id` int(11) DEFAULT NULL,
  `supp_name` varchar(150) NOT NULL,
  `return_date` date NOT NULL,
  `total_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `refund_method` enum('cash','credit') NOT NULL DEFAULT 'credit' COMMENT '╪╖╪▒┘è┘é╪ر ╪د┘╪د╪│╪ز╪▒╪»╪د╪» (┘┘é╪» ╪ث┘ê ╪«╪╡┘à ┘à┘ ╪د┘╪░┘à╪ر)',
  `box_id` int(11) NOT NULL DEFAULT 1,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` decimal(15,4) DEFAULT 1.0000,
  `reason` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `sector_id` int(11) DEFAULT NULL,
  `d_s` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_return_no` (`return_no`),
  KEY `idx_original_purchase_id` (`original_purchase_id`),
  KEY `idx_supp_id` (`supp_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchases`
--

DROP TABLE IF EXISTS `purchases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supp_id` int(11) DEFAULT NULL,
  `supp_name` varchar(100) NOT NULL,
  `total` double NOT NULL DEFAULT 0,
  `remark` varchar(100) NOT NULL DEFAULT '',
  `date` date NOT NULL,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1,
  `box_id` int(11) NOT NULL DEFAULT 1,
  `remaining_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `invoice_type` varchar(20) NOT NULL DEFAULT 'cash',
  `payment_method` varchar(20) NOT NULL DEFAULT 'cash',
  `wallet_type` varchar(100) NOT NULL DEFAULT '',
  `sector_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_purchases_box` (`box_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchases_history`
--

DROP TABLE IF EXISTS `purchases_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchases_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supp_name` varchar(100) NOT NULL,
  `total` double NOT NULL DEFAULT 0,
  `remark` varchar(100) NOT NULL DEFAULT '',
  `date` date NOT NULL,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1,
  `box_id` int(11) NOT NULL DEFAULT 1,
  `remaining_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `invoice_type` varchar(20) NOT NULL DEFAULT 'cash',
  `payment_method` varchar(20) NOT NULL DEFAULT 'cash',
  `wallet_type` varchar(100) NOT NULL DEFAULT '',
  `sector_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_purchases_box` (`box_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `receipt_vouchers_dtl`
--

DROP TABLE IF EXISTS `receipt_vouchers_dtl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receipt_vouchers_dtl` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `voucher_id` int(10) unsigned NOT NULL,
  `reference_type` varchar(50) DEFAULT NULL COMMENT '┘┘ê╪╣ ╪د┘┘à╪▒╪ش╪╣ (sale, invoice, etc)',
  `reference_id` int(11) DEFAULT NULL COMMENT '┘à╪╣╪▒┘ ╪د┘┘╪د╪ز┘ê╪▒╪ر ╪ث┘ê ╪د┘╪╣┘à┘┘è╪ر ╪د┘┘à╪▒╪ش╪╣┘è╪ر',
  `amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `remark` varchar(255) DEFAULT NULL,
  `d_s` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_voucher_id` (`voucher_id`),
  CONSTRAINT `fk_rec_vou_dtl_mst` FOREIGN KEY (`voucher_id`) REFERENCES `receipt_vouchers_mst` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `receipt_vouchers_mst`
--

DROP TABLE IF EXISTS `receipt_vouchers_mst`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receipt_vouchers_mst` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `voucher_no` varchar(50) NOT NULL,
  `voucher_date` date NOT NULL,
  `party_type` enum('customer','supplier','other') NOT NULL DEFAULT 'customer',
  `party_id` int(11) DEFAULT NULL,
  `party_name` varchar(150) NOT NULL,
  `total_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `payment_method` varchar(50) DEFAULT 'cash',
  `box_id` int(11) NOT NULL DEFAULT 1,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` decimal(15,4) DEFAULT 1.0000,
  `remark` text DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `sector_id` int(11) DEFAULT NULL,
  `d_s` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_voucher_no` (`voucher_no`),
  KEY `idx_party` (`party_type`,`party_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `receipts`
--

DROP TABLE IF EXISTS `receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receipts` (
  `qid` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `cust_name` varchar(100) NOT NULL,
  `q_price` double NOT NULL DEFAULT 0,
  `remark` varchar(100) NOT NULL DEFAULT '',
  `q_date` date NOT NULL,
  `total` double NOT NULL DEFAULT 0,
  `s` int(11) NOT NULL DEFAULT 0,
  `box_id` int(11) NOT NULL DEFAULT 1,
  `voucher_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`qid`),
  KEY `idx_receipts_box` (`box_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `receipts_history`
--

DROP TABLE IF EXISTS `receipts_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `receipts_history` (
  `qid` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `cust_name` varchar(100) NOT NULL,
  `q_price` double NOT NULL DEFAULT 0,
  `remark` varchar(100) NOT NULL DEFAULT '',
  `q_date` date NOT NULL,
  `total` double NOT NULL DEFAULT 0,
  `s` int(11) NOT NULL DEFAULT 0,
  `box_id` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`qid`),
  KEY `idx_receipts_box` (`box_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `repair_issue_types`
--

DROP TABLE IF EXISTS `repair_issue_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `repair_issue_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_name` varchar(150) NOT NULL DEFAULT '',
  `d_s` char(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `repair_parts_used`
--

DROP TABLE IF EXISTS `repair_parts_used`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `repair_parts_used` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `repair_ticket_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `part_name` varchar(150) NOT NULL DEFAULT '',
  `quantity` decimal(10,2) NOT NULL DEFAULT 1.00,
  `cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `repair_tickets`
--

DROP TABLE IF EXISTS `repair_tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `repair_tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_number` varchar(30) NOT NULL DEFAULT '',
  `customer_id` int(11) DEFAULT NULL,
  `customer_name_text` varchar(255) DEFAULT NULL,
  `device_name` varchar(200) NOT NULL DEFAULT '',
  `device_type` varchar(100) NOT NULL DEFAULT '',
  `device_brand` varchar(100) NOT NULL DEFAULT '',
  `imei` varchar(20) NOT NULL DEFAULT '',
  `issue_type` varchar(150) NOT NULL DEFAULT '',
  `expected_delivery_date` date DEFAULT NULL,
  `problem_description` text DEFAULT NULL,
  `diagnosis` text DEFAULT NULL,
  `status` enum('received','in_progress','waiting_parts','completed','delivered','cancelled') NOT NULL DEFAULT 'received',
  `estimated_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `final_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `technician_id` int(11) DEFAULT NULL,
  `received_date` datetime DEFAULT current_timestamp(),
  `delivered_date` datetime DEFAULT NULL,
  `d_s` char(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_permissions` (
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `permission_id` (`permission_id`),
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_2` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles`
--

DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales`
--

DROP TABLE IF EXISTS `sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales` (
  `id` int(50) NOT NULL AUTO_INCREMENT,
  `cust_name` varchar(100) NOT NULL,
  `build_date` date NOT NULL,
  `total` double(10,2) NOT NULL DEFAULT 0.00,
  `prifet` double NOT NULL DEFAULT 0,
  `remark` text NOT NULL DEFAULT '',
  `delete_status` tinyint(4) NOT NULL DEFAULT 0,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1,
  `remaining_total` double DEFAULT 0,
  `invoice_type` varchar(20) NOT NULL DEFAULT 'cash',
  `payment_method` varchar(20) NOT NULL DEFAULT 'cash',
  `wallet_type` varchar(100) NOT NULL DEFAULT '',
  `profit_total` double DEFAULT 0,
  `box_id` int(11) NOT NULL DEFAULT 1,
  `is_transferred_to_box` tinyint(1) NOT NULL DEFAULT 0,
  `sector_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sales_box` (`box_id`),
  KEY `idx_sales_transfer` (`is_transferred_to_box`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales_cancellations`
--

DROP TABLE IF EXISTS `sales_cancellations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_cancellations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sales_id` int(11) NOT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `user` varchar(100) DEFAULT NULL,
  `cancelled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales_history`
--

DROP TABLE IF EXISTS `sales_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_history` (
  `id` int(50) NOT NULL AUTO_INCREMENT,
  `cust_name` varchar(100) NOT NULL,
  `build_date` date NOT NULL,
  `total` double(10,2) NOT NULL DEFAULT 0.00,
  `prifet` double NOT NULL DEFAULT 0,
  `remark` text NOT NULL DEFAULT '',
  `delete_status` tinyint(4) NOT NULL DEFAULT 0,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1,
  `remaining_total` double DEFAULT 0,
  `invoice_type` varchar(20) NOT NULL DEFAULT 'cash',
  `payment_method` varchar(20) NOT NULL DEFAULT 'cash',
  `wallet_type` varchar(100) NOT NULL DEFAULT '',
  `profit_total` double DEFAULT 0,
  `box_id` int(11) NOT NULL DEFAULT 1,
  `is_transferred_to_box` tinyint(1) NOT NULL DEFAULT 0,
  `sector_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sales_box` (`box_id`),
  KEY `idx_sales_transfer` (`is_transferred_to_box`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales_invoices_dtl`
--

DROP TABLE IF EXISTS `sales_invoices_dtl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_invoices_dtl` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `unit_name` varchar(50) DEFAULT '╪د┘┘ê╪ص╪»╪ر ╪د┘╪ث╪│╪د╪│┘è╪ر',
  `quantity` decimal(15,4) NOT NULL DEFAULT 1.0000,
  `unit_price` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT '╪│╪╣╪▒ ╪د┘╪ذ┘è╪╣',
  `unit_cost` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT '╪│╪╣╪▒ ╪د┘╪ز┘â┘┘╪ر (┘╪ص╪│╪د╪ذ ╪د┘╪▒╪ذ╪ص)',
  `discount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `total_price` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `d_s` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_invoice_id` (`invoice_id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_sal_inv_dtl_mst` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices_mst` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales_invoices_mst`
--

DROP TABLE IF EXISTS `sales_invoices_mst`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_invoices_mst` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_no` varchar(50) NOT NULL,
  `cust_id` int(11) DEFAULT NULL COMMENT '┘à╪╣╪▒┘ ╪د┘╪╣┘à┘è┘',
  `cust_name` varchar(150) NOT NULL DEFAULT '╪╣┘à┘è┘ ┘┘é╪»┘è',
  `invoice_date` date NOT NULL,
  `total_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `discount_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `net_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `paid_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `remaining_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `invoice_type` enum('cash','credit','account') NOT NULL DEFAULT 'cash',
  `payment_method` varchar(50) DEFAULT 'cash',
  `wallet_type` varchar(100) DEFAULT NULL,
  `profit_total` decimal(15,4) NOT NULL DEFAULT 0.0000 COMMENT '╪ح╪ش┘à╪د┘┘è ╪د┘╪▒╪ذ╪ص ╪د┘┘à╪ص┘é┘é',
  `box_id` int(11) NOT NULL DEFAULT 1,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` decimal(15,4) DEFAULT 1.0000,
  `remark` text DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `sector_id` int(11) DEFAULT NULL,
  `d_s` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invoice_no` (`invoice_no`),
  KEY `idx_cust_id` (`cust_id`),
  KEY `idx_invoice_date` (`invoice_date`),
  KEY `idx_box_id` (`box_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales_items`
--

DROP TABLE IF EXISTS `sales_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_items` (
  `p_id` int(50) NOT NULL AUTO_INCREMENT,
  `sales_id` int(50) NOT NULL,
  `id` int(11) NOT NULL,
  `cust_name` varchar(100) NOT NULL,
  `name` varchar(200) DEFAULT NULL,
  `unit_name` varchar(50) DEFAULT NULL,
  `quantity` int(20) NOT NULL,
  `unit_price` double(10,2) NOT NULL DEFAULT 0.00,
  `bush` double NOT NULL DEFAULT 0,
  `dis` double NOT NULL DEFAULT 0,
  `d` double NOT NULL DEFAULT 0,
  `total` double(10,2) NOT NULL DEFAULT 0.00,
  `all_tot` double NOT NULL DEFAULT 0,
  `build_date` date NOT NULL,
  `remaining` double DEFAULT 0,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1,
  PRIMARY KEY (`p_id`),
  KEY `quantity` (`quantity`),
  KEY `product_id` (`id`),
  KEY `sales_id` (`sales_id`),
  KEY `sales_id_2` (`sales_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales_items_history`
--

DROP TABLE IF EXISTS `sales_items_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_items_history` (
  `p_id` int(50) NOT NULL AUTO_INCREMENT,
  `sales_id` int(50) NOT NULL,
  `id` int(11) NOT NULL,
  `cust_name` varchar(100) NOT NULL,
  `name` varchar(200) DEFAULT NULL,
  `quantity` int(20) NOT NULL,
  `unit_price` double(10,2) NOT NULL DEFAULT 0.00,
  `bush` double NOT NULL DEFAULT 0,
  `dis` double NOT NULL DEFAULT 0,
  `d` double NOT NULL DEFAULT 0,
  `total` double(10,2) NOT NULL DEFAULT 0.00,
  `all_tot` double NOT NULL DEFAULT 0,
  `build_date` date NOT NULL,
  `remaining` double DEFAULT 0,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1,
  PRIMARY KEY (`p_id`),
  KEY `quantity` (`quantity`),
  KEY `product_id` (`id`),
  KEY `sales_id` (`sales_id`),
  KEY `sales_id_2` (`sales_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales_returns`
--

DROP TABLE IF EXISTS `sales_returns`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_returns` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sales_id` int(11) NOT NULL DEFAULT 0,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(200) DEFAULT NULL,
  `unit_name` varchar(50) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `unit_price` double DEFAULT 0,
  `refund_amount` double DEFAULT 0,
  `reason` varchar(200) DEFAULT NULL,
  `return_date` date DEFAULT NULL,
  `user` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sales_item_id` int(11) DEFAULT NULL,
  `original_unit_price` double DEFAULT 0,
  `original_buy_price` double DEFAULT 0,
  `profit_impact` double DEFAULT 0,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1,
  `status` enum('active','cancelled') DEFAULT 'active',
  `box_id` int(11) NOT NULL DEFAULT 1,
  `refund_method` enum('cash','credit') NOT NULL DEFAULT 'cash',
  `refund_source` varchar(50) DEFAULT 'box',
  `sector_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_returns_sales` (`sales_id`),
  KEY `idx_returns_box` (`box_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales_returns_dtl`
--

DROP TABLE IF EXISTS `sales_returns_dtl`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_returns_dtl` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `return_id` int(10) unsigned NOT NULL,
  `product_id` int(10) unsigned NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `quantity` decimal(15,4) NOT NULL DEFAULT 1.0000,
  `unit_price` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `unit_cost` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `total_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `d_s` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_return_id` (`return_id`),
  CONSTRAINT `fk_sal_ret_dtl_mst` FOREIGN KEY (`return_id`) REFERENCES `sales_returns_mst` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales_returns_history`
--

DROP TABLE IF EXISTS `sales_returns_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_returns_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sales_id` int(11) NOT NULL DEFAULT 0,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(200) DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `unit_price` double DEFAULT 0,
  `refund_amount` double DEFAULT 0,
  `reason` varchar(200) DEFAULT NULL,
  `return_date` date DEFAULT NULL,
  `user` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sales_item_id` int(11) DEFAULT NULL,
  `original_unit_price` double DEFAULT 0,
  `original_buy_price` double DEFAULT 0,
  `profit_impact` double DEFAULT 0,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1,
  `status` enum('active','cancelled') DEFAULT 'active',
  `box_id` int(11) NOT NULL DEFAULT 1,
  `refund_method` enum('cash','credit') NOT NULL DEFAULT 'cash',
  `refund_source` varchar(50) DEFAULT 'box',
  `sector_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_returns_sales` (`sales_id`),
  KEY `idx_returns_box` (`box_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales_returns_mst`
--

DROP TABLE IF EXISTS `sales_returns_mst`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_returns_mst` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `return_no` varchar(50) NOT NULL,
  `original_sale_id` int(10) unsigned DEFAULT NULL,
  `cust_id` int(11) DEFAULT NULL,
  `cust_name` varchar(150) NOT NULL,
  `return_date` date NOT NULL,
  `total_amount` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `refund_method` enum('cash','credit') NOT NULL DEFAULT 'cash',
  `box_id` int(11) NOT NULL DEFAULT 1,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` decimal(15,4) DEFAULT 1.0000,
  `reason` varchar(255) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `sector_id` int(11) DEFAULT NULL,
  `d_s` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_return_no` (`return_no`),
  KEY `idx_original_sale_id` (`original_sale_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sectors`
--

DROP TABLE IF EXISTS `sectors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sectors` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `store_name` varchar(100) NOT NULL,
  `store_name_en` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `phone_en` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `address_en` text DEFAULT NULL,
  `commercial_register` varchar(100) DEFAULT NULL,
  `tax_number` varchar(100) DEFAULT NULL,
  `currency` varchar(20) DEFAULT '???????? ????????',
  `barcode_scanner` tinyint(1) DEFAULT 1,
  `printer_type` varchar(50) DEFAULT 'receipt_80mm',
  `tax_percent` double DEFAULT 0,
  `low_stock_threshold` int(11) DEFAULT 5,
  `receipt_footer` text DEFAULT NULL,
  `logo` varchar(255) DEFAULT NULL,
  `cashier_permissions` text DEFAULT NULL,
  `inventory_permissions` text DEFAULT NULL,
  `is_configured` tinyint(1) NOT NULL DEFAULT 0,
  `whatsapp_token` varchar(255) DEFAULT NULL,
  `whatsapp_instance` varchar(100) DEFAULT NULL,
  `whatsapp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `gemini_api_key` varchar(255) DEFAULT NULL,
  `report_header_subtitle` varchar(255) DEFAULT '',
  `report_header_notes` text DEFAULT NULL,
  `report_show_logo` tinyint(1) NOT NULL DEFAULT 1,
  `report_show_cr` tinyint(1) NOT NULL DEFAULT 1,
  `report_show_tax` tinyint(1) NOT NULL DEFAULT 1,
  `support_token` varchar(255) DEFAULT 'ReplaceWithStrongSupportToken123!',
  `industry_type` varchar(100) DEFAULT NULL,
  `timezone` varchar(100) DEFAULT 'Asia/Aden',
  `date_format` varchar(50) DEFAULT 'Y-m-d',
  `decimal_precision` int(11) DEFAULT 4,
  `thousand_separator` varchar(5) DEFAULT ',',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stock_adjustment_items`
--

DROP TABLE IF EXISTS `stock_adjustment_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_adjustment_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `adjustment_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(15,4) NOT NULL,
  `type` enum('damaged','discrepancy','other') NOT NULL,
  `cost_price` decimal(15,4) NOT NULL DEFAULT 0.0000,
  `batch_id` int(11) DEFAULT NULL,
  `serial_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stock_adjustments`
--

DROP TABLE IF EXISTS `stock_adjustments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_adjustments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `warehouse_id` int(11) NOT NULL,
  `adjustment_date` date NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stock_transfer_items`
--

DROP TABLE IF EXISTS `stock_transfer_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_transfer_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(15,4) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `serial_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stock_transfers`
--

DROP TABLE IF EXISTS `stock_transfers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_transfers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `from_warehouse_id` int(11) NOT NULL,
  `to_warehouse_id` int(11) NOT NULL,
  `transfer_date` date NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_by` varchar(100) NOT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_payments`
--

DROP TABLE IF EXISTS `supplier_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supplier_payments` (
  `bush_id` int(11) NOT NULL AUTO_INCREMENT,
  `supp_name` varchar(100) NOT NULL,
  `bush_price` double NOT NULL DEFAULT 0,
  `remark` varchar(100) NOT NULL DEFAULT '',
  `bush_date` date NOT NULL,
  PRIMARY KEY (`bush_id`),
  KEY `bush_id` (`bush_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `supp_id` int(11) NOT NULL AUTO_INCREMENT,
  `supp_name` varchar(100) NOT NULL,
  `supp_madeen` double(100,2) NOT NULL DEFAULT 0.00,
  `supp_daain` double NOT NULL DEFAULT 0,
  `buy_date` date NOT NULL,
  `phone` varchar(100) NOT NULL DEFAULT '',
  `d_s` int(11) NOT NULL DEFAULT 0,
  `email` varchar(100) DEFAULT '',
  `address` varchar(255) DEFAULT '',
  `company_name` varchar(150) DEFAULT '',
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`supp_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `system_backups`
--

DROP TABLE IF EXISTS `system_backups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_backups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `backup_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('success','failed') DEFAULT 'success',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `system_licensing`
--

DROP TABLE IF EXISTS `system_licensing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_licensing` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `machine_id` varchar(255) NOT NULL,
  `company_name` varchar(150) NOT NULL,
  `owner_name` varchar(150) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `city` varchar(50) NOT NULL,
  `license_type` enum('trial','daily','weekly','monthly','yearly','lifetime') NOT NULL,
  `start_date` date NOT NULL,
  `expiry_date` date NOT NULL,
  `modules_enabled` text NOT NULL,
  `max_users` int(11) NOT NULL DEFAULT 1,
  `max_branches` int(11) NOT NULL DEFAULT 1,
  `license_key` text NOT NULL,
  `activation_status` tinyint(1) NOT NULL DEFAULT 0,
  `tampering_lock` tinyint(1) NOT NULL DEFAULT 0,
  `activated_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `machine_id` (`machine_id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `system_modules`
--

DROP TABLE IF EXISTS `system_modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_modules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_key` varchar(50) NOT NULL,
  `module_name` varchar(100) NOT NULL DEFAULT '',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `config_json` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `module_key` (`module_key`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `system_time_check`
--

DROP TABLE IF EXISTS `system_time_check`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_time_check` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `last_run_date` date NOT NULL,
  `last_run_time` datetime NOT NULL,
  `client_ip` varchar(45) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `system_updates`
--

DROP TABLE IF EXISTS `system_updates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_updates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(30) NOT NULL,
  `released_date` date DEFAULT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `description` text DEFAULT NULL,
  `status` enum('success','failed') NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `treasury`
--

DROP TABLE IF EXISTS `treasury`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `treasury` (
  `box_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '?????????????? ??????????????',
  `mony` double NOT NULL DEFAULT 0,
  `remark` varchar(100) NOT NULL DEFAULT '',
  `user_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`box_id`),
  KEY `idx_treasury_user` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `treasury_closings`
--

DROP TABLE IF EXISTS `treasury_closings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `treasury_closings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `box_id` int(11) NOT NULL,
  `close_date` date NOT NULL,
  `expected_balance` double NOT NULL DEFAULT 0,
  `actual_balance` double NOT NULL DEFAULT 0,
  `difference` double NOT NULL DEFAULT 0,
  `transferred_amount` double NOT NULL DEFAULT 0,
  `user` varchar(100) NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `treasury_expenses`
--

DROP TABLE IF EXISTS `treasury_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `treasury_expenses` (
  `sid` int(11) NOT NULL AUTO_INCREMENT,
  `bush_id` int(11) NOT NULL,
  `st` varchar(20) NOT NULL,
  `sname` varchar(100) NOT NULL,
  `sdate` date NOT NULL,
  `sprice` double NOT NULL DEFAULT 0,
  `sremark` varchar(100) NOT NULL DEFAULT '',
  `tot` int(11) NOT NULL DEFAULT 0,
  `s` int(11) NOT NULL DEFAULT 0,
  `box_id` int(11) NOT NULL DEFAULT 1,
  `voucher_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`sid`),
  KEY `bush_id` (`bush_id`),
  KEY `bush_id_2` (`bush_id`),
  KEY `idx_expenses_box` (`box_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `treasury_expenses_history`
--

DROP TABLE IF EXISTS `treasury_expenses_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `treasury_expenses_history` (
  `sid` int(11) NOT NULL AUTO_INCREMENT,
  `bush_id` int(11) NOT NULL,
  `st` varchar(20) NOT NULL,
  `sname` varchar(100) NOT NULL,
  `sdate` date NOT NULL,
  `sprice` double NOT NULL DEFAULT 0,
  `sremark` varchar(100) NOT NULL DEFAULT '',
  `tot` int(11) NOT NULL DEFAULT 0,
  `s` int(11) NOT NULL DEFAULT 0,
  `box_id` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`sid`),
  KEY `bush_id` (`bush_id`),
  KEY `bush_id_2` (`bush_id`),
  KEY `idx_expenses_box` (`box_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `treasury_transactions`
--

DROP TABLE IF EXISTS `treasury_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `treasury_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mony` int(100) NOT NULL DEFAULT 0,
  `statue` varchar(20) NOT NULL,
  `remark` varchar(100) NOT NULL DEFAULT '',
  `datte` date NOT NULL,
  `box_id` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_treasury_trans_box` (`box_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `units`
--

DROP TABLE IF EXISTS `units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `units` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `d_s` char(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `userid` int(20) NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `full_name` varchar(150) DEFAULT NULL,
  `password` varchar(100) NOT NULL,
  `phone` varchar(30) NOT NULL DEFAULT '',
  `code` varchar(100) NOT NULL DEFAULT '',
  `position` varchar(20) NOT NULL DEFAULT 'cashier',
  `custom_permissions` text DEFAULT NULL,
  `role_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`userid`),
  UNIQUE KEY `username` (`username`),
  KEY `fk_users_role` (`role_id`),
  KEY `fk_users_branch` (`branch_id`),
  KEY `fk_users_warehouse` (`warehouse_id`),
  CONSTRAINT `fk_users_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1000 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `warehouses`
--

DROP TABLE IF EXISTS `warehouses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `warehouses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `branch_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `branch_id` (`branch_id`),
  CONSTRAINT `warehouses_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `warehouses_stock`
--

DROP TABLE IF EXISTS `warehouses_stock`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `warehouses_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `warehouse_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` decimal(15,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_wh_prod` (`warehouse_id`,`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping routines for database 'aqnex_pos'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

--
-- Table structure for table `tax_groups` (added manually - engine corruption workaround)
--

DROP TABLE IF EXISTS `tax_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tax_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `description` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

-- Dump completed on 2026-07-24 22:51:04
