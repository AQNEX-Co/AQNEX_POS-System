-- MariaDB dump 10.19-11.2.2-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: aqnex_pos
-- ------------------------------------------------------
-- Server version	11.2.2-MariaDB

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
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  `exchange_rate` double NOT NULL DEFAULT 1,
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
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_imei1` (`imei_1`),
  KEY `idx_product_status` (`product_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `idx_products_barcode` (`barcode`),
  KEY `categorie_id` (`catid`),
  KEY `quantity` (`quantity`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  PRIMARY KEY (`id`),
  KEY `purchase_id` (`purchase_id`),
  KEY `box_id` (`box_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchases`
--

DROP TABLE IF EXISTS `purchases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supp_name` varchar(100) NOT NULL,
  `total` double NOT NULL DEFAULT 0,
  `remark` varchar(100) NOT NULL DEFAULT '',
  `date` date NOT NULL,
  `currency_code` varchar(10) DEFAULT 'YER',
  `exchange_rate` double DEFAULT 1,
  `box_id` int(11) NOT NULL DEFAULT 1,
  `remaining_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_purchases_box` (`box_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  PRIMARY KEY (`qid`),
  KEY `idx_receipts_box` (`box_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  `device_type` varchar(100) NOT NULL DEFAULT '',
  `device_brand` varchar(100) NOT NULL DEFAULT '',
  `imei` varchar(20) NOT NULL DEFAULT '',
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
  `profit_total` double DEFAULT 0,
  `box_id` int(11) NOT NULL DEFAULT 1,
  `is_transferred_to_box` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_sales_box` (`box_id`),
  KEY `idx_sales_transfer` (`is_transferred_to_box`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  PRIMARY KEY (`id`),
  KEY `idx_returns_sales` (`sales_id`),
  KEY `idx_returns_box` (`box_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `commercial_register` varchar(100) DEFAULT NULL,
  `tax_number` varchar(100) DEFAULT NULL,
  `currency` varchar(20) DEFAULT '╪▒┘è╪د┘ ┘è┘à┘┘è',
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  `name` varchar(100) NOT NULL DEFAULT '╪د┘╪╡┘╪»┘ê┘é ╪د┘╪▒╪خ┘è╪│┘è',
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
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
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
  PRIMARY KEY (`userid`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `accounting_accounts`
--

DROP TABLE IF EXISTS `accounting_accounts`;
CREATE TABLE `accounting_accounts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(150) NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `account_type` enum('asset','liability','equity','revenue','expense') NOT NULL,
  `is_parent` tinyint(1) NOT NULL DEFAULT 0,
  `level` int(11) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `accounting_accounts`
--

LOCK TABLES `accounting_accounts` WRITE;
INSERT INTO `accounting_accounts` VALUES 
(1, '1', 'الأصول', NULL, 'asset', 1, 1, 'الحساب الرئيسي للأصول', CURRENT_TIMESTAMP),
(2, '2', 'الخصوم والالتزامات', NULL, 'liability', 1, 1, 'الحساب الرئيسي للخصوم والالتزامات', CURRENT_TIMESTAMP),
(3, '3', 'حقوق الملكية', NULL, 'equity', 1, 1, 'الحساب الرئيسي لحقوق الملكية', CURRENT_TIMESTAMP),
(4, '4', 'الإيرادات', NULL, 'revenue', 1, 1, 'الحساب الرئيسي للإيرادات', CURRENT_TIMESTAMP),
(5, '5', 'المصروفات', NULL, 'expense', 1, 1, 'الحساب الرئيسي للمصروفات', CURRENT_TIMESTAMP),
(6, '11', 'الأصول المتداولة', 1, 'asset', 1, 2, 'الأصول المتداولة والسيولة', CURRENT_TIMESTAMP),
(7, '12', 'الأصول الثابتة', 1, 'asset', 1, 2, 'الأصول الثابتة للمنشأة', CURRENT_TIMESTAMP),
(8, '21', 'الالتزامات المتداولة', 2, 'liability', 1, 2, 'الالتزامات المتداولة والديون قصيرة الأجل', CURRENT_TIMESTAMP),
(9, '31', 'رأس المال والاحتياطيات', 3, 'equity', 1, 2, 'رأس المال والاحتياطيات وحقوق الشركاء', CURRENT_TIMESTAMP),
(10, '1101', 'الصندوق الرئيسي', 6, 'asset', 0, 3, 'الصندوق المالي الرئيسي للمحل', CURRENT_TIMESTAMP),
(11, '1102', 'الذمم المدينة - العملاء', 6, 'asset', 0, 3, 'حساب مديونيات العملاء الآجلة', CURRENT_TIMESTAMP),
(12, '1103', 'المخزون / البضاعة', 6, 'asset', 0, 3, 'حساب تقييم بضاعة المخازن', CURRENT_TIMESTAMP),
(13, '2101', 'الذمم الدائنة - الموردين', 8, 'liability', 0, 3, 'حساب مستحقات الموردين الآجلة', CURRENT_TIMESTAMP),
(14, '3101', 'رأس المال المفتوح', 9, 'equity', 0, 3, 'رأس مال المشروع التأسيسي', CURRENT_TIMESTAMP),
(15, '4101', 'المبيعات', 4, 'revenue', 0, 2, 'حساب إيرادات المبيعات العامة', CURRENT_TIMESTAMP),
(16, '4102', 'المردودات (مردودات المبيعات)', 4, 'revenue', 0, 2, 'حساب مرتجعات مبيعات العملاء', CURRENT_TIMESTAMP),
(17, '5101', 'تكلفة البضاعة المباعة (مصروف)', 5, 'expense', 0, 2, 'حساب تكلفة البضاعة المباعة للعملاء', CURRENT_TIMESTAMP),
(18, '5102', 'المصروفات العامة والتشغيلية', 5, 'expense', 0, 2, 'حساب المصروفات والتشغيل العام', CURRENT_TIMESTAMP);
UNLOCK TABLES;

--
-- Dumping data for table `currencies`
--

LOCK TABLES `currencies` WRITE;
INSERT INTO `currencies` VALUES 
(1, 'ريال يمني', 'YER', 'ر.ي', 1.0, 1),
(2, 'دولار أمريكي', 'USD', '$', 530.0, 0),
(3, 'ريال سعودي', 'SAR', 'ر.س', 140.0, 0);
UNLOCK TABLES;

--
-- Table structure for table `sectors`
--

DROP TABLE IF EXISTS `sectors`;
CREATE TABLE `sectors` (
  `sector_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`sector_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `sectors`
--

LOCK TABLES `sectors` WRITE;
INSERT INTO `sectors` VALUES (1, 'القطاع الرئيسي', 1);
UNLOCK TABLES;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-06-27  0:12:48

