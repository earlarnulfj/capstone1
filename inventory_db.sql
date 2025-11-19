-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 13, 2025 at 12:28 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `inventory_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_activity`
--

CREATE TABLE `admin_activity` (
  `id` int(11) NOT NULL,
  `admin_user_id` int(11) NOT NULL,
  `admin_username` varchar(100) NOT NULL,
  `action` varchar(100) NOT NULL,
  `component` varchar(100) NOT NULL,
  `details` longtext DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_activity`
--

INSERT INTO `admin_activity` (`id`, `admin_user_id`, `admin_username`, `action`, `component`, `details`, `status`, `created_at`) VALUES
(1, 4, 'Admin', 'user_delete', 'users', '{\"target_id\":9}', 'success', '2025-11-03 08:07:50'),
(2, 4, 'Admin', 'user_delete', 'users', '{\"target_id\":9}', 'success', '2025-11-03 08:09:12');

-- --------------------------------------------------------

--
-- Table structure for table `admin_attributes`
--

CREATE TABLE `admin_attributes` (
  `id` int(11) NOT NULL,
  `name` varchar(64) NOT NULL,
  `display_name` varchar(64) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `data_type` varchar(32) DEFAULT 'text',
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` int(11) DEFAULT 0,
  `metadata` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_attributes`
--

INSERT INTO `admin_attributes` (`id`, `name`, `display_name`, `description`, `data_type`, `is_required`, `sort_order`, `metadata`, `is_deleted`, `deleted_at`, `created_at`, `updated_at`) VALUES
(1, 'Name', 'Product Name', NULL, 'text', 0, 1, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20'),
(2, 'Brand', 'Brand', NULL, 'text', 0, 2, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20'),
(3, 'Type', 'Type', NULL, 'text', 0, 3, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20'),
(4, 'Color', 'Color', NULL, 'text', 0, 4, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20'),
(5, 'Size', 'Size', NULL, 'text', 0, 5, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20'),
(6, 'Material', 'Material', NULL, 'text', 0, 6, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20'),
(7, 'Weight', 'Weight', NULL, 'text', 0, 7, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20'),
(8, 'Capacity', 'Capacity', NULL, 'text', 0, 8, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20');

-- --------------------------------------------------------

--
-- Table structure for table `admin_attribute_options`
--

CREATE TABLE `admin_attribute_options` (
  `id` int(11) NOT NULL,
  `attribute_id` int(11) NOT NULL,
  `value` varchar(128) NOT NULL,
  `display_value` varchar(128) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `metadata` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_attribute_options`
--

INSERT INTO `admin_attribute_options` (`id`, `attribute_id`, `value`, `display_value`, `description`, `sort_order`, `metadata`, `is_deleted`, `deleted_at`, `created_at`, `updated_at`) VALUES
(1, 4, 'Red', 'Red', NULL, 1, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20'),
(2, 4, 'Blue', 'Blue', NULL, 2, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20'),
(3, 4, 'Green', 'Green', NULL, 3, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20'),
(4, 4, 'Black', 'Black', NULL, 4, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20'),
(5, 4, 'White', 'White', NULL, 5, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20'),
(6, 5, 'Small', 'Small', NULL, 1, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20'),
(7, 5, 'Medium', 'Medium', NULL, 2, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20'),
(8, 5, 'Large', 'Large', NULL, 3, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20'),
(9, 5, 'Extra Large', 'Extra Large', NULL, 4, NULL, 0, NULL, '2025-11-13 02:43:20', '2025-11-13 02:43:20');

-- --------------------------------------------------------

--
-- Table structure for table `admin_orders`
--

CREATE TABLE `admin_orders` (
  `id` int(11) NOT NULL,
  `inventory_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `is_automated` tinyint(1) DEFAULT 0,
  `order_date` datetime DEFAULT current_timestamp(),
  `confirmation_status` enum('pending','confirmed','cancelled','delivered','completed') DEFAULT 'pending',
  `confirmation_date` datetime DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `unit_type` varchar(50) NOT NULL DEFAULT 'per piece',
  `variation` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_orders`
--

INSERT INTO `admin_orders` (`id`, `inventory_id`, `supplier_id`, `user_id`, `quantity`, `is_automated`, `order_date`, `confirmation_status`, `confirmation_date`, `unit_price`, `unit_type`, `variation`) VALUES
(51, 223, 4, 4, 23, 0, '2025-11-13 19:15:48', 'completed', '2025-11-13 19:16:18', 101.00, 'per bag', 'Brand:Generic|Size:Medium|Type:Standard'),
(52, 224, 4, 4, 23, 0, '2025-11-13 19:15:48', 'pending', NULL, 67.00, 'per piece', 'Quantity:10|Size:Medium');

-- --------------------------------------------------------

--
-- Table structure for table `admin_unit_types`
--

CREATE TABLE `admin_unit_types` (
  `id` int(11) NOT NULL,
  `code` varchar(16) NOT NULL,
  `name` varchar(64) NOT NULL,
  `description` text DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_unit_types`
--

INSERT INTO `admin_unit_types` (`id`, `code`, `name`, `description`, `metadata`, `is_deleted`, `deleted_at`, `created_at`, `updated_at`) VALUES
(1, 'pc', 'per piece', NULL, NULL, 0, NULL, '2025-11-13 02:36:20', '2025-11-13 02:36:20'),
(2, 'set', 'per set', NULL, NULL, 0, NULL, '2025-11-13 02:36:20', '2025-11-13 02:36:20'),
(3, 'box', 'per box', NULL, NULL, 0, NULL, '2025-11-13 02:36:20', '2025-11-13 02:36:20'),
(4, 'pack', 'per pack', NULL, NULL, 0, NULL, '2025-11-13 02:36:20', '2025-11-13 02:50:51'),
(5, 'bag', 'per bag', NULL, NULL, 0, NULL, '2025-11-13 02:36:20', '2025-11-13 02:36:20'),
(6, 'roll', 'per roll', NULL, NULL, 0, NULL, '2025-11-13 02:36:20', '2025-11-13 02:36:20'),
(7, 'bar', 'per bar', NULL, NULL, 0, NULL, '2025-11-13 02:36:20', '2025-11-13 02:36:20'),
(8, 'sheet', 'per sheet', NULL, NULL, 0, NULL, '2025-11-13 02:36:20', '2025-11-13 02:36:20'),
(9, 'm', 'per meter', NULL, NULL, 0, NULL, '2025-11-13 02:36:20', '2025-11-13 02:36:20'),
(10, 'L', 'per liter', NULL, NULL, 0, NULL, '2025-11-13 02:36:20', '2025-11-13 02:36:20'),
(11, 'gal', 'per gallon', NULL, NULL, 0, NULL, '2025-11-13 02:36:20', '2025-11-13 02:36:20'),
(12, 'tube', 'per tube', NULL, NULL, 0, NULL, '2025-11-13 02:36:20', '2025-11-13 02:36:20'),
(13, 'btl', 'per bottle', NULL, NULL, 0, NULL, '2025-11-13 02:36:20', '2025-11-13 02:36:20'),
(14, 'can', 'per can', NULL, NULL, 0, NULL, '2025-11-13 02:36:20', '2025-11-13 02:36:20'),
(15, 'sack', 'per sack', NULL, NULL, 0, NULL, '2025-11-13 02:36:20', '2025-11-13 02:36:20'),
(46, 'adfs', 'asfsfc', NULL, NULL, 0, NULL, '2025-11-13 02:51:34', '2025-11-13 02:51:34');

-- --------------------------------------------------------

--
-- Table structure for table `admin_unit_type_variations`
--

CREATE TABLE `admin_unit_type_variations` (
  `id` int(11) NOT NULL,
  `unit_type_id` int(11) NOT NULL,
  `attribute` varchar(64) NOT NULL,
  `value` varchar(128) NOT NULL,
  `description` text DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `alert_logs`
--

CREATE TABLE `alert_logs` (
  `id` int(11) NOT NULL,
  `inventory_id` int(11) DEFAULT NULL,
  `alert_type` enum('low_stock','out_of_stock','reorder') NOT NULL,
  `alert_date` datetime DEFAULT current_timestamp(),
  `is_resolved` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `auth_logs`
--

CREATE TABLE `auth_logs` (
  `id` int(11) NOT NULL,
  `user_type` varchar(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `action` varchar(50) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `session_id` varchar(255) DEFAULT NULL,
  `additional_info` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`additional_info`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `auth_logs`
--

INSERT INTO `auth_logs` (`id`, `user_type`, `user_id`, `username`, `action`, `ip_address`, `user_agent`, `session_id`, `additional_info`, `created_at`) VALUES
(1, 'admin', NULL, 'admin', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"User not found\"}', '2025-10-29 07:50:50'),
(2, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 07:53:01'),
(3, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 08:14:09'),
(4, 'supplier', 2, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 08:15:42'),
(5, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 08:16:19'),
(6, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 08:27:21'),
(7, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 08:28:20'),
(8, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 08:39:13'),
(9, 'supplier', 2, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 09:08:33'),
(10, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 10:14:57'),
(11, 'supplier', 2, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 10:15:07'),
(12, 'supplier', 2, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 10:30:42'),
(13, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 10:31:10'),
(14, 'supplier', 2, 'Supplier', 'login_success', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.104.3 Chrome/138.0.7204.251 Electron/37.6.1 Safari/537.36', 'snsna3ghh3fkloljvusvgdiqg1', '{\"info\":\"Successful login\"}', '2025-10-29 10:47:40'),
(15, 'supplier', 2, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 11:15:40'),
(16, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 11:15:48'),
(17, 'supplier', 2, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 11:18:54'),
(18, 'supplier', 2, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 11:20:02'),
(19, 'supplier', 2, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 11:22:39'),
(20, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 11:42:28'),
(21, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 12:18:36'),
(22, 'admin', 1, 'Vince', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Invalid password\"}', '2025-10-29 12:55:34'),
(23, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 12:55:42'),
(24, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 13:09:06'),
(25, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-29 13:58:46'),
(26, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 09:37:16'),
(27, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 09:37:39'),
(28, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 09:37:44'),
(29, 'admin', 1, 'Vince', 'login_failed', '192.168.254.102', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36', 'cn8ip4si9fppma3dcprspi6fvo', '{\"info\":\"Invalid password\"}', '2025-10-30 09:56:05'),
(30, 'admin', 1, 'Vince', 'login_success', '192.168.254.102', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36', 'cn8ip4si9fppma3dcprspi6fvo', '{\"info\":\"Successful login\"}', '2025-10-30 09:56:14'),
(31, 'admin', 1, 'Vince', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Invalid password\"}', '2025-10-30 13:37:46'),
(32, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 13:37:51'),
(33, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 13:38:05'),
(34, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 14:05:06'),
(35, 'supplier', 1, 'Supplier', 'login_success', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.104.3 Chrome/138.0.7204.251 Electron/37.6.1 Safari/537.36', 'aufumqab2gur51a9107v3ki47q', '{\"info\":\"Successful login\"}', '2025-10-30 14:46:23'),
(36, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 15:33:28'),
(37, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.104.3 Chrome/138.0.7204.251 Electron/37.6.1 Safari/537.36', 'esuvj1oq0laf104ioknhunar5a', '{\"info\":\"Successful login\"}', '2025-10-30 15:33:33'),
(38, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 15:34:29'),
(39, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.104.3 Chrome/138.0.7204.251 Electron/37.6.1 Safari/537.36', 'esuvj1oq0laf104ioknhunar5a', '{\"info\":\"Successful login\"}', '2025-10-30 15:34:51'),
(40, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 15:44:09'),
(41, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 15:45:50'),
(42, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 15:50:12'),
(43, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 16:18:44'),
(44, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 17:11:03'),
(45, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 17:11:10'),
(46, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 17:37:56'),
(47, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 17:38:13'),
(48, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-30 18:13:21'),
(49, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-31 02:28:45'),
(50, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-31 02:28:51'),
(51, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-31 02:49:45'),
(52, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-31 04:24:39'),
(53, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-31 04:30:04'),
(54, 'supplier', 1, 'Supplier', 'login_success', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.104.3 Chrome/138.0.7204.251 Electron/37.6.1 Safari/537.36', 'tl3hc81u00vhb0jpia50f9qa3o', '{\"info\":\"Successful login\"}', '2025-10-31 04:30:08'),
(55, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-31 07:18:45'),
(56, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-31 07:29:10'),
(57, 'admin', 1, 'Vince', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Invalid password\"}', '2025-10-31 07:29:40'),
(58, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-31 07:29:44'),
(59, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-31 08:26:51'),
(60, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-31 08:26:57'),
(61, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-31 08:27:06'),
(62, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-31 12:26:53'),
(63, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-31 12:26:59'),
(64, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-31 12:27:06'),
(65, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-31 15:07:02'),
(66, 'admin', 1, 'Vince', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Invalid password\"}', '2025-10-31 15:07:11'),
(67, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-10-31 15:07:58'),
(68, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-01 00:57:55'),
(69, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-01 00:58:03'),
(70, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-01 00:58:09'),
(71, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-01 05:31:38'),
(72, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-01 07:38:50'),
(73, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-01 07:38:54'),
(74, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-01 07:39:00'),
(75, 'supplier', 2, 'Supplier', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Invalid password\"}', '2025-11-01 10:03:10'),
(76, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-01 10:03:17'),
(77, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.104.3 Chrome/138.0.7204.251 Electron/37.6.1 Safari/537.36', 'l3lkvunoeoijudnqc62fgp9l4t', '{\"info\":\"Successful login\"}', '2025-11-01 10:16:10'),
(78, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.104.3 Chrome/138.0.7204.251 Electron/37.6.1 Safari/537.36', 'l3lkvunoeoijudnqc62fgp9l4t', '{\"info\":\"Successful login\"}', '2025-11-01 10:16:40'),
(79, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-01 11:09:45'),
(80, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-01 11:09:49'),
(81, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-01 11:09:54'),
(82, 'admin', 1, 'Vince', 'login_success', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.104.3 Chrome/138.0.7204.251 Electron/37.6.1 Safari/537.36', 'ook5hlq8d5kvgvl6dtd0so58hl', '{\"info\":\"Successful login\"}', '2025-11-01 13:43:26'),
(83, 'supplier', 1, 'Supplier', 'login_success', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.104.3 Chrome/138.0.7204.251 Electron/37.6.1 Safari/537.36', 'ook5hlq8d5kvgvl6dtd0so58hl', '{\"info\":\"Successful login\"}', '2025-11-01 13:45:46'),
(84, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 01:24:13'),
(85, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 01:24:18'),
(86, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 01:24:24'),
(87, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.104.3 Chrome/138.0.7204.251 Electron/37.6.1 Safari/537.36', 'gk1gmjatomtnkljn2o0v3pula7', '{\"info\":\"Successful login\"}', '2025-11-02 03:37:46'),
(88, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 09:49:40'),
(89, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 10:06:28'),
(90, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 10:10:08'),
(91, 'supplier', 2, 'Supplier', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Invalid password\"}', '2025-11-02 10:10:56'),
(92, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 10:11:28'),
(93, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 10:11:36'),
(94, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 10:31:03'),
(95, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 10:31:14'),
(96, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 10:31:20'),
(97, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 11:42:42'),
(98, 'supplier', 2, 'Supplier', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Invalid password\"}', '2025-11-02 11:42:47'),
(99, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 11:42:51'),
(100, 'staff', 3, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 11:42:56'),
(101, 'supplier', 1, 'Supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 11:45:40'),
(102, 'admin', 1, 'Vince', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Invalid password\"}', '2025-11-02 14:29:08'),
(103, 'admin', 1, 'Vince', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Invalid password\"}', '2025-11-02 14:29:15'),
(104, 'admin', 1, 'Vince', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Invalid password\"}', '2025-11-02 14:29:48'),
(105, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 14:30:14'),
(106, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Google OAuth login\"}', '2025-11-02 14:34:25'),
(107, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Google OAuth login\"}', '2025-11-02 14:36:23'),
(108, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 14:45:05'),
(109, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 14:48:38'),
(110, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 14:49:20'),
(111, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Google OAuth login\"}', '2025-11-02 14:49:27'),
(112, 'admin', 1, 'Vince', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 14:55:37'),
(113, 'admin', 1, 'Vince', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Invalid password\"}', '2025-11-02 14:58:07'),
(114, 'admin', 1, 'Vince', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Invalid password\"}', '2025-11-02 14:58:12'),
(115, 'supplier', NULL, 'vpvillanueva.chmsu@gmail.com', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Account not found or inactive\"}', '2025-11-02 15:14:58'),
(116, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 15:32:13'),
(117, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 15:32:20'),
(118, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 15:32:24'),
(119, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 15:36:06'),
(120, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 15:36:10'),
(121, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-02 19:46:26'),
(122, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 01:39:33'),
(123, 'supplier', 8, 'abc', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Invalid password\"}', '2025-11-03 01:39:40'),
(124, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 01:39:45'),
(125, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 01:39:52'),
(126, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 01:39:59'),
(127, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 05:52:11'),
(128, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 05:52:18'),
(129, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 05:52:22'),
(130, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 05:52:41'),
(131, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 05:52:50'),
(132, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 05:52:59'),
(133, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 05:53:05'),
(134, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 05:53:09'),
(135, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 05:53:14'),
(136, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 05:53:32'),
(137, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 05:55:03'),
(138, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 05:57:04'),
(139, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 05:57:08'),
(140, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 05:57:13'),
(141, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 05:57:18'),
(142, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 05:57:25'),
(143, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 06:02:24'),
(144, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 06:02:28'),
(145, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 06:02:35'),
(146, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 06:02:39'),
(147, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 06:02:44'),
(148, 'supplier', 5, 'abc_supplier', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 08:08:32'),
(149, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 10:59:26'),
(150, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 10:59:57'),
(151, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:00:09'),
(152, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:00:39'),
(153, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:00:46'),
(154, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:01:43'),
(155, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:01:49'),
(156, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:01:53'),
(157, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:06:21'),
(158, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:06:25'),
(159, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:06:34'),
(160, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:06:52'),
(161, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:09:01'),
(162, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:09:08'),
(163, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:09:16'),
(164, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:12:03'),
(165, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:12:08'),
(166, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:15:24'),
(167, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:15:29'),
(168, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:17:59'),
(169, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:18:05'),
(170, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:21:39'),
(171, 'admin', 4, 'Admin', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Invalid password\"}', '2025-11-03 11:26:24'),
(172, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:26:28'),
(173, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:26:39'),
(174, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-03 11:30:22'),
(175, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Google OAuth login\"}', '2025-11-03 11:32:14'),
(176, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Google OAuth login\"}', '2025-11-03 11:32:29'),
(177, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Google OAuth login\"}', '2025-11-03 11:32:43'),
(178, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Google OAuth login\"}', '2025-11-03 11:34:24'),
(179, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Google OAuth login\"}', '2025-11-03 11:38:15'),
(180, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-13 01:40:14'),
(181, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-13 01:40:24'),
(182, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-13 01:40:29'),
(183, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-13 10:06:33'),
(184, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-13 10:52:57'),
(185, 'admin', 4, 'Admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-13 10:53:06'),
(186, 'supplier', 4, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-13 10:53:19'),
(187, 'staff', 5, 'Staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'nvct5v6lk991m9dmejlfs5hj5d', '{\"info\":\"Successful login\"}', '2025-11-13 10:53:25');

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `sender_type` enum('admin','supplier') NOT NULL,
  `sender_name` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chat_messages`
--

INSERT INTO `chat_messages` (`id`, `supplier_id`, `admin_id`, `sender_type`, `sender_name`, `message`, `is_read`, `created_at`) VALUES
(1, 1, NULL, 'supplier', 'Supplier', 'boossss', 1, '2025-10-31 15:08:21'),
(2, 1, NULL, 'supplier', 'Supplier', 'I have a question about my orders', 1, '2025-11-02 13:13:00');

-- --------------------------------------------------------

--
-- Table structure for table `chat_message_status`
--

CREATE TABLE `chat_message_status` (
  `id` int(11) NOT NULL,
  `message_id` int(11) NOT NULL,
  `status` enum('sent','delivered','read') NOT NULL DEFAULT 'sent',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chat_message_status`
--

INSERT INTO `chat_message_status` (`id`, `message_id`, `status`, `updated_at`) VALUES
(1, 1, 'read', '2025-11-02 16:11:44'),
(5, 2, 'read', '2025-11-02 16:11:44');

-- --------------------------------------------------------

--
-- Table structure for table `chat_typing_indicators`
--

CREATE TABLE `chat_typing_indicators` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `sender_type` enum('admin','supplier') NOT NULL,
  `sender_name` varchar(255) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `deliveries`
--

CREATE TABLE `deliveries` (
  `id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `delivery_date` datetime DEFAULT NULL,
  `latitude` decimal(9,6) DEFAULT NULL,
  `longitude` decimal(9,6) DEFAULT NULL,
  `status` enum('pending','in_transit','delivered','completed','cancelled') DEFAULT 'pending',
  `replenished_quantity` int(11) DEFAULT NULL,
  `driver_name` varchar(255) DEFAULT NULL,
  `vehicle_info` varchar(255) DEFAULT NULL,
  `tracking_number` varchar(100) DEFAULT NULL,
  `delivery_address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `deliveries`
--

INSERT INTO `deliveries` (`id`, `order_id`, `delivery_date`, `latitude`, `longitude`, `status`, `replenished_quantity`, `driver_name`, `vehicle_info`, `tracking_number`, `delivery_address`, `notes`, `updated_at`) VALUES
(34, 37, '2025-11-03 15:32:26', 0.000000, 0.000000, 'completed', 0, '', '', '', '', '', '2025-11-03 14:32:28'),
(35, 38, '2025-11-03 15:34:13', 0.000000, 0.000000, 'completed', 0, '', '', '', '', '', '2025-11-03 14:34:15'),
(36, 39, '2025-11-03 15:52:45', 0.000000, 0.000000, 'completed', 0, '', '', '', '', '', '2025-11-03 14:52:47'),
(38, 40, '2025-11-03 16:43:50', 0.000000, 0.000000, 'completed', 0, '', '', '', '', '', '2025-11-03 15:43:52'),
(54, 59, '2025-11-13 11:57:48', 0.000000, 0.000000, 'completed', 0, '', '', '', '', '', '2025-11-13 10:57:50'),
(55, 60, '2025-11-13 12:16:14', 0.000000, 0.000000, 'completed', 0, '', '', '', '', '', '2025-11-13 11:16:18');

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(11) NOT NULL COMMENT 'Primary key - matches admin_orders.inventory_id',
  `sku` varchar(50) DEFAULT NULL COMMENT 'Product SKU',
  `name` varchar(100) NOT NULL COMMENT 'Product name - displayed as item_name in orders.php',
  `description` text DEFAULT NULL COMMENT 'Product description',
  `category` varchar(50) DEFAULT NULL COMMENT 'Product category',
  `reorder_threshold` int(11) NOT NULL DEFAULT 0 COMMENT 'Reorder threshold for alerts',
  `unit_type` varchar(50) NOT NULL DEFAULT 'per piece' COMMENT 'Unit type - matches admin_orders.unit_type',
  `quantity` int(11) NOT NULL DEFAULT 0 COMMENT 'Total quantity from completed orders - matches admin_orders.quantity aggregation',
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00 COMMENT 'Unit price - matches admin_orders.unit_price',
  `supplier_id` int(11) DEFAULT NULL COMMENT 'Supplier ID - matches admin_orders.supplier_id',
  `location` varchar(100) DEFAULT NULL COMMENT 'Storage location',
  `image_url` varchar(255) DEFAULT NULL COMMENT 'Product image URL',
  `image_path` varchar(255) DEFAULT NULL COMMENT 'Product image file path',
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Soft delete flag: 0 = active, 1 = deleted',
  `last_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Last update timestamp',
  `created_at` datetime DEFAULT current_timestamp() COMMENT 'Creation timestamp'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`id`, `sku`, `name`, `description`, `category`, `reorder_threshold`, `unit_type`, `quantity`, `unit_price`, `supplier_id`, `location`, `image_url`, `image_path`, `is_deleted`, `last_updated`, `created_at`) VALUES
(223, 'SKU-223', 'Cement', NULL, NULL, 0, 'per bag', 7340, 101.00, 4, NULL, 'uploads/products/product_223_1763032600.jpg', 'uploads/products/product_223_1763032600.jpg', 0, '2025-11-13 19:27:33', '2025-11-13 18:32:03'),
(224, 'SKU-224', 'ACSV', NULL, NULL, 0, 'per piece', 80, 67.00, 4, NULL, NULL, NULL, 0, '2025-11-13 19:00:07', '2025-11-13 19:00:07');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_from_completed_orders`
--

CREATE TABLE `inventory_from_completed_orders` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL COMMENT 'ID from admin_orders or orders table',
  `order_table` varchar(20) DEFAULT 'admin_orders' COMMENT 'Which table the order came from: admin_orders or orders',
  `inventory_id` int(11) DEFAULT NULL COMMENT 'Original inventory_id from order (for reference)',
  `sku` varchar(50) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `variation` varchar(255) DEFAULT NULL,
  `unit_type` varchar(50) DEFAULT 'per piece',
  `quantity` int(11) NOT NULL DEFAULT 0 COMMENT 'Quantity from completed order',
  `available_quantity` int(11) NOT NULL DEFAULT 0 COMMENT 'Available stock after sales',
  `sold_quantity` int(11) NOT NULL DEFAULT 0 COMMENT 'Total sold quantity',
  `reorder_threshold` int(11) NOT NULL DEFAULT 0,
  `unit_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `supplier_id` int(11) DEFAULT NULL,
  `supplier_name` varchar(100) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `order_date` datetime DEFAULT NULL COMMENT 'Date when order was placed',
  `completion_date` datetime DEFAULT NULL COMMENT 'Date when order was marked as completed',
  `last_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory_from_completed_orders`
--

INSERT INTO `inventory_from_completed_orders` (`id`, `order_id`, `order_table`, `inventory_id`, `sku`, `name`, `description`, `variation`, `unit_type`, `quantity`, `available_quantity`, `sold_quantity`, `reorder_threshold`, `unit_price`, `supplier_id`, `supplier_name`, `category`, `location`, `image_url`, `image_path`, `order_date`, `completion_date`, `last_updated`, `created_at`) VALUES
(1, 41, 'admin_orders', 221, NULL, '', NULL, 'Brand:Generic', 'per piece', 20, 20, 0, 0, 45.00, 4, 'Earl D. Jaud', NULL, NULL, NULL, NULL, '2025-11-13 15:11:33', '2025-11-13 15:11:45', '2025-11-13 16:29:29', '2025-11-13 16:25:52');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_logs`
--

CREATE TABLE `inventory_logs` (
  `id` int(11) UNSIGNED NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `variation` varchar(255) DEFAULT NULL,
  `unit_type` varchar(50) DEFAULT NULL,
  `action` enum('stock_in','stock_out','adjustment','order_placed','delivery_received','sale_completed') NOT NULL,
  `quantity_before` int(11) NOT NULL DEFAULT 0,
  `quantity_change` int(11) NOT NULL DEFAULT 0,
  `quantity_after` int(11) NOT NULL DEFAULT 0,
  `order_id` int(11) DEFAULT NULL,
  `delivery_id` int(11) DEFAULT NULL,
  `sales_transaction_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_variations`
--

CREATE TABLE `inventory_variations` (
  `id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `variation` varchar(255) DEFAULT NULL COMMENT 'Product variation from admin_orders (e.g., Brand:Adidas|Size:Large|Color:Red)',
  `unit_type` varchar(50) NOT NULL DEFAULT 'per piece',
  `quantity` int(11) NOT NULL DEFAULT 0 COMMENT 'Quantity for this specific variation from completed orders',
  `unit_price` decimal(10,2) DEFAULT NULL COMMENT 'Unit price for this variation',
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory_variations`
--

INSERT INTO `inventory_variations` (`id`, `inventory_id`, `variation`, `unit_type`, `quantity`, `unit_price`, `created_at`, `updated_at`) VALUES
(1, 223, 'Brand:Generic|Size:Large|Type:Standard', 'per bag', 2940, 90.00, '2025-11-13 18:32:03', '2025-11-13 18:54:15'),
(2, 223, 'Brand:Generic|Size:Medium|Type:Standard', 'per bag', 4217, 101.00, '2025-11-13 18:32:03', '2025-11-13 19:27:33'),
(7, 224, 'Quantity:10|Size:Medium', 'per piece', 60, 67.00, '2025-11-13 19:00:07', '2025-11-13 19:00:13');

-- --------------------------------------------------------

--
-- Table structure for table `log_rotation_meta`
--

CREATE TABLE `log_rotation_meta` (
  `name` varchar(64) NOT NULL,
  `last_run` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `log_rotation_meta`
--

INSERT INTO `log_rotation_meta` (`name`, `last_run`) VALUES
('supplier_logs', '2025-11-12 20:21:46');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `type` enum('low_stock','order_confirmation','delivery_update','delivery_arrival','delivery_status_update','delivery_created','order_status_update','supplier_message','inventory_update') NOT NULL,
  `channel` enum('email','sms','push','in_app') NOT NULL DEFAULT 'in_app',
  `recipient_type` enum('admin','supplier','customer','management') NOT NULL,
  `recipient_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `alert_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `status` enum('sent','failed','pending','read','unread') DEFAULT 'pending',
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `type`, `channel`, `recipient_type`, `recipient_id`, `order_id`, `alert_id`, `message`, `sent_at`, `status`, `is_read`, `created_at`, `read_at`) VALUES
(327, 'order_confirmation', '', 'supplier', 1, NULL, NULL, 'Admin Vince placed order #2: 2 x Nail on 2025-11-02 13:33:19', NULL, 'read', 1, '2025-11-02 07:18:40', '2025-11-02 11:01:01'),
(339, 'order_confirmation', '', 'supplier', 1, NULL, NULL, 'Admin Vince placed order #5: 10 x Nail on 2025-11-02 16:16:25', NULL, 'read', 1, '2025-11-02 10:15:58', '2025-11-02 11:01:05'),
(340, 'order_confirmation', '', 'supplier', 1, NULL, NULL, 'Admin Vince placed order #4: 20 x Nail on 2025-11-02 15:45:57', NULL, 'read', 1, '2025-11-02 10:15:58', '2025-11-02 11:01:04'),
(341, 'order_confirmation', '', 'supplier', 1, NULL, NULL, 'Admin Vince placed order #3: 20 x Nail on 2025-11-02 15:45:34', NULL, 'read', 1, '2025-11-02 10:15:58', '2025-11-02 11:01:02'),
(350, 'order_confirmation', '', 'supplier', 1, NULL, NULL, 'Admin Vince placed order #7: 20 x Nail on 2025-11-02 18:52:52', NULL, 'read', 1, '2025-11-02 11:00:58', '2025-11-02 11:12:49'),
(351, 'order_confirmation', '', 'supplier', 1, NULL, NULL, 'Admin Vince placed order #6: 20 x Nail on 2025-11-02 18:51:05', NULL, 'read', 1, '2025-11-02 11:00:58', '2025-11-02 11:12:47'),
(358, 'order_confirmation', '', 'supplier', 1, NULL, NULL, 'Admin Vince placed order #8: 29 x Nail on 2025-11-02 19:25:18', NULL, 'read', 1, '2025-11-02 12:52:04', '2025-11-02 12:52:05'),
(633, '', '', 'management', 1, 60, NULL, 'Product confirmation by Earl D. Jaud: ID 223 – Cement, Qty 23 at 2025-11-13 19:16:11 (Order #60)', NULL, 'pending', 0, '2025-11-13 11:16:11', NULL),
(634, 'delivery_created', '', 'management', 1, 60, NULL, 'Order #60 is ready to ship - delivery created by supplier abc', NULL, 'sent', 0, '2025-11-13 11:16:14', NULL),
(635, '', '', 'management', 1, 60, NULL, 'Order #60 has been completed by supplier abc', NULL, 'sent', 0, '2025-11-13 11:16:18', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `inventory_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `is_automated` tinyint(1) DEFAULT 0,
  `order_date` datetime DEFAULT current_timestamp(),
  `confirmation_status` enum('pending','confirmed','delivered','completed','cancelled') DEFAULT 'pending',
  `confirmation_date` datetime DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `unit_type` varchar(50) NOT NULL DEFAULT 'per piece',
  `variation` varchar(50) DEFAULT NULL,
  `weight_ordered` decimal(10,3) DEFAULT NULL COMMENT 'Weight ordered for weight-based products',
  `is_weight_based` tinyint(1) DEFAULT 0 COMMENT 'Flag for weight-based orders'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `inventory_id`, `supplier_id`, `user_id`, `quantity`, `is_automated`, `order_date`, `confirmation_status`, `confirmation_date`, `unit_price`, `unit_type`, `variation`, `weight_ordered`, `is_weight_based`) VALUES
(59, 224, 4, 4, 20, 0, '2025-11-13 18:56:55', 'completed', '2025-11-13 18:57:50', 67.00, 'per piece', 'Quantity:10|Size:Medium', NULL, 0),
(60, 223, 4, 4, 23, 0, '2025-11-13 19:15:48', 'completed', '2025-11-13 19:16:18', 101.00, 'per bag', 'Brand:Generic|Size:Medium|Type:Standard', NULL, 0),
(61, 224, 4, 4, 23, 0, '2025-11-13 19:15:48', 'pending', NULL, 67.00, 'per piece', 'Quantity:10|Size:Medium', NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `verification_code` varchar(6) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_reset_tokens`
--

INSERT INTO `password_reset_tokens` (`id`, `email`, `token`, `verification_code`, `expires_at`, `used`, `created_at`, `updated_at`) VALUES
(13, 'staff@gmail.com', '9209a9dc80591eabb7e67242aed2400c1baaeb134d71d63a3495cff755e22424', '630326', '2025-10-05 15:53:43', 0, '2025-10-05 21:38:43', '2025-10-05 21:38:43'),
(19, 'patrimoniovillanuevince@gmail.com', '19d3007b4251cff24ed42096e11adc39fa6d83b60a995fabc69e8f687233c21f', '151954', '2025-10-05 16:09:53', 0, '2025-10-05 21:54:53', '2025-10-05 21:54:53'),
(73, 'earlarnulfj@gmail.com', '350a7c6639a930fd467ffbd2db65e270a47303d17dd38aa2123188f7be7b3a35', '418987', '2025-10-29 16:42:49', 0, '2025-10-29 16:27:49', '2025-10-29 16:27:49'),
(76, 'vpvillanueva.chmsu@gmail.com', '2f43d2b04d07bc6bc0ba9b42ce4b0af68e6448afd4a1be66f5a95e58d54cc168', '532136', '2025-11-13 19:07:05', 0, '2025-11-13 18:52:05', '2025-11-13 18:52:05'),
(77, 'vpvillanueva.chmsu@gmail.com', '8a76f432ae8927f9bbd6876448f307a1128f69404a78ca38cd7744b2146fe7df', '728463', '2025-11-13 19:07:09', 1, '2025-11-13 18:52:09', '2025-11-13 18:52:43');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `payment_method` enum('cash','gcash') DEFAULT 'cash',
  `amount` decimal(10,2) NOT NULL,
  `payment_status` enum('pending','completed','failed') DEFAULT 'pending',
  `payment_date` datetime DEFAULT current_timestamp(),
  `transaction_reference` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `order_id`, `payment_method`, `amount`, `payment_status`, `payment_date`, `transaction_reference`) VALUES
(9, 9, 'cash', 1740.00, 'completed', '2025-11-03 00:33:00', ''),
(10, 10, 'cash', 1340.00, 'completed', '2025-11-03 00:57:54', ''),
(11, 11, 'cash', 1340.00, 'completed', '2025-11-03 01:04:28', ''),
(12, 12, 'cash', 2010.00, 'completed', '2025-11-03 01:09:14', ''),
(13, 13, 'cash', 1340.00, 'completed', '2025-11-03 03:16:58', ''),
(14, 14, 'cash', 3042.00, 'completed', '2025-11-03 04:07:07', ''),
(15, 15, 'cash', 0.00, 'completed', '2025-11-03 15:23:53', ''),
(16, 20, 'cash', 2680.00, 'completed', '2025-11-03 15:42:40', ''),
(17, 21, 'cash', 3015.00, 'completed', '2025-11-03 15:43:45', ''),
(18, 22, 'cash', 2278.00, 'completed', '2025-11-03 15:48:26', ''),
(19, 23, 'cash', 0.00, 'completed', '2025-11-03 16:01:25', ''),
(20, 24, 'cash', 2010.00, 'completed', '2025-11-03 20:20:34', ''),
(21, 25, 'cash', 1541.00, 'completed', '2025-11-03 20:24:07', ''),
(22, 26, 'cash', 4992.00, 'completed', '2025-11-03 20:38:20', ''),
(23, 27, 'cash', 0.00, 'completed', '2025-11-03 20:42:06', ''),
(24, 28, 'cash', 4830.00, 'completed', '2025-11-03 21:32:34', ''),
(25, 31, 'gcash', 4005.00, 'pending', '2025-11-03 21:41:41', 'pi_kuEktPAHckHHBfJYx4NVHvVh'),
(26, 32, 'cash', 3015.00, 'completed', '2025-11-03 21:44:39', ''),
(31, 37, 'cash', 989.00, 'completed', '2025-11-03 22:32:17', ''),
(32, 38, 'cash', 430.00, 'completed', '2025-11-03 22:33:56', ''),
(33, 39, 'cash', 1290.00, 'completed', '2025-11-03 22:52:01', ''),
(34, 40, 'gcash', 1510.00, 'pending', '2025-11-03 23:41:47', 'pi_nCEHPAt1ck1Zo5bMgs96RKKD'),
(35, 42, 'gcash', 900.00, 'pending', '2025-11-03 23:44:25', 'pi_gYEEgJ8AZikqqZYEiyZdQW25'),
(52, 59, 'cash', 1340.00, 'completed', '2025-11-13 18:56:55', ''),
(53, 60, 'gcash', 3864.00, 'pending', '2025-11-13 19:15:52', 'pi_cQMp2WBUmdoLLk3iAy3ehF5L');

-- --------------------------------------------------------

--
-- Table structure for table `sales_transactions`
--

CREATE TABLE `sales_transactions` (
  `id` int(11) NOT NULL,
  `inventory_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `unit_type` varchar(50) NOT NULL DEFAULT 'per piece',
  `variation` varchar(255) DEFAULT NULL,
  `transaction_date` datetime DEFAULT current_timestamp(),
  `total_amount` decimal(10,2) NOT NULL,
  `weight_sold` decimal(10,3) DEFAULT NULL COMMENT 'Weight sold for weight-based products',
  `price_per_unit` decimal(10,2) DEFAULT NULL COMMENT 'Price per kg for weight-based products'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `contact_phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `login_attempts` int(11) DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `employee_count` varchar(32) NOT NULL DEFAULT '',
  `payment_methods` varchar(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `name`, `contact_phone`, `email`, `username`, `password_hash`, `login_attempts`, `locked_until`, `last_login`, `address`, `city`, `province`, `postal_code`, `status`, `employee_count`, `payment_methods`) VALUES
(4, 'Earl D. Jaud', '09942715542', 'ajcango.chmsu@gmail.com', 'abc', '$2y$10$XW5lA/hswxvuc9StalJ2neqj5JDP9V6myy0AKx9a.aaXEbAe9ntQq', 0, NULL, NULL, 'GFHGJFH', 'Hardware District', 'Negros Occidental', '6101', 'active', '', ''),
(5, 'Juan D. Cruz', '09123456789', 'patrimoniovillanuevince@gmail.com', 'abc_supplier', '$2y$10$2zuBoPa8r2rQTW7ARXTSs.dKtRXwAQa1VC9UJSKM1RlU5l8/5w7be', 0, NULL, NULL, 'AESFESF', 'ascas', 'ESFESSF', '2342', 'active', '', '');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_activity`
--

CREATE TABLE `supplier_activity` (
  `id` int(11) NOT NULL,
  `supplier_user_id` int(11) NOT NULL,
  `supplier_username` varchar(100) NOT NULL,
  `action` varchar(100) NOT NULL,
  `component` varchar(100) NOT NULL,
  `details` longtext DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL,
  `level` varchar(10) DEFAULT 'INFO',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supplier_activity`
--

INSERT INTO `supplier_activity` (`id`, `supplier_user_id`, `supplier_username`, `action`, `component`, `details`, `status`, `level`, `created_at`) VALUES
(1, 2, 'Supplier', 'profile_update', 'supplier_profile', '{\"changed_fields\":[\"name\",\"email\",\"phone\",\"address\"]}', 'success', 'INFO', '2025-10-29 08:43:16'),
(2, 2, 'Supplier', 'profile_update', 'supplier_profile', '{\"changed_fields\":[\"name\",\"email\",\"phone\",\"address\"]}', 'success', 'INFO', '2025-10-29 08:44:08'),
(3, 2, 'Supplier', 'profile_update', 'supplier_profile', '{\"changed_fields\":[\"name\",\"email\",\"phone\",\"address\"]}', 'success', 'INFO', '2025-10-29 08:46:38'),
(4, 2, 'Supplier', 'profile_update', 'supplier_profile', '{\"changed_fields\":[\"name\",\"email\",\"phone\",\"address\"]}', 'success', 'INFO', '2025-10-29 08:46:41'),
(5, 2, 'Supplier', 'profile_update', 'supplier_profile', '{\"changed_fields\":[\"name\",\"email\",\"phone\",\"address\"]}', 'success', 'INFO', '2025-10-29 08:53:51'),
(6, 2, 'Supplier', 'profile_update', 'supplier_profile', '{\"changed_fields\":[\"name\",\"email\",\"phone\",\"address\"]}', 'success', 'INFO', '2025-10-29 08:57:06'),
(7, 1, 'Supplier', 'delete_unit_type', 'supplier_products', '{\"code\":\"acsasc\",\"name\":\"acsasc\"}', 'success', 'INFO', '2025-10-31 13:34:40'),
(8, 1, 'Supplier', 'delete_unit_type', 'supplier_products', '{\"code\":\"vsdvsdvsdvdsvsdv\",\"name\":\"vSDVSDVSDVDSVSDvSVSvSDV\"}', 'success', 'INFO', '2025-10-31 13:36:11'),
(9, 1, 'Supplier', 'add_unit_type', 'supplier_products', '{\"code\":\"gcx\",\"name\":\"zzsdvDV\"}', 'success', 'INFO', '2025-10-31 13:39:44'),
(10, 1, 'Supplier', 'delete_unit_type', 'supplier_products', '{\"code\":\"gcx\",\"name\":\"zzsdvDV\"}', 'success', 'INFO', '2025-10-31 13:40:17'),
(11, 1, 'Supplier', 'add_unit_type', 'supplier_products', '{\"code\":\"dv\",\"name\":\"sdvvv\"}', 'success', 'INFO', '2025-10-31 13:51:53'),
(12, 1, 'Supplier', 'delete_unit_type', 'supplier_products', '{\"code\":\"dv\",\"name\":\"sdvvv\"}', 'success', 'INFO', '2025-10-31 14:02:17'),
(13, 1, 'Supplier', 'add_unit_type', 'supplier_products', '{\"code\":\"efeas\",\"name\":\"safdv\"}', 'success', 'INFO', '2025-10-31 14:58:54'),
(14, 1, 'Supplier', 'delete_unit_type', 'supplier_products', '{\"code\":\"efeas\",\"name\":\"safdv\"}', 'success', 'INFO', '2025-10-31 14:59:03'),
(15, 1, 'Supplier', 'add_unit_type', 'supplier_products', '{\"code\":\"aewfd\",\"name\":\"awdfa\"}', 'success', 'INFO', '2025-10-31 15:10:48'),
(16, 1, 'Supplier', 'delete_unit_type', 'supplier_products', '{\"code\":\"aewfd\",\"name\":\"awdfa\"}', 'success', 'INFO', '2025-10-31 15:14:51'),
(17, 1, 'Supplier', 'delete_unit_type', 'supplier_products', '{\"code\":\"kilo\",\"name\":\"Per Kilo\"}', 'success', 'INFO', '2025-11-01 01:23:26'),
(18, 1, 'Supplier', 'add_unit_type', 'supplier_products', '{\"code\":\"efe\",\"name\":\"dasfASF\"}', 'success', 'INFO', '2025-11-01 01:23:38'),
(19, 1, 'Supplier', 'add_unit_type', 'supplier_products', '{\"code\":\"efw\",\"name\":\"ewfeff\"}', 'success', 'INFO', '2025-11-01 01:40:00'),
(20, 1, 'Supplier', 'delete_unit_type', 'supplier_products', '{\"code\":\"pack\",\"name\":\"per pack\"}', 'success', 'INFO', '2025-11-01 09:01:02'),
(21, 1, 'Supplier', 'delete_unit_type', 'supplier_products', '{\"code\":\"efw\",\"name\":\"ewfeff\"}', 'success', 'INFO', '2025-11-01 09:01:31'),
(22, 1, 'Supplier', 'delete_unit_type', 'supplier_products', '{\"code\":\"efe\",\"name\":\"dasfASF\"}', 'success', 'INFO', '2025-11-01 09:01:35'),
(23, 1, 'Supplier', 'delete_unit_type', 'supplier_products', '{\"code\":\"sack\",\"name\":\"Sack\"}', 'success', 'INFO', '2025-11-01 09:18:06'),
(24, 1, 'Supplier', 'add_unit_type', 'supplier_products', '{\"code\":\"ewfrt\",\"name\":\"aesfas\"}', 'success', 'INFO', '2025-11-01 09:26:33'),
(25, 1, 'Supplier', 'add_unit_type', 'supplier_products', '{\"code\":\"aefs\",\"name\":\"saefs\"}', 'success', 'INFO', '2025-11-01 09:26:37'),
(26, 1, 'Supplier', 'delete_unit_type', 'supplier_products', '{\"code\":\"aefs\",\"name\":\"saefs\"}', 'success', 'INFO', '2025-11-01 10:40:47'),
(27, 1, 'Supplier', 'add_unit_type', 'supplier_products', '{\"code\":\"Klg\",\"name\":\"Kilo\"}', 'success', 'INFO', '2025-11-01 12:13:09');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_activity_archive`
--

CREATE TABLE `supplier_activity_archive` (
  `id` int(11) NOT NULL,
  `supplier_user_id` int(11) NOT NULL,
  `supplier_username` varchar(100) NOT NULL,
  `action` varchar(100) NOT NULL,
  `component` varchar(100) NOT NULL,
  `details` longtext DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL,
  `level` varchar(10) DEFAULT 'INFO',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_catalog`
--

CREATE TABLE `supplier_catalog` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `sku` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `unit_type` varchar(20) DEFAULT 'per piece',
  `supplier_quantity` int(11) NOT NULL DEFAULT 0,
  `reorder_threshold` int(11) NOT NULL DEFAULT 10,
  `location` varchar(100) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive','deprecated') DEFAULT 'active',
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `source_inventory_id` int(11) DEFAULT NULL,
  `last_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supplier_catalog`
--

INSERT INTO `supplier_catalog` (`id`, `supplier_id`, `sku`, `name`, `description`, `category`, `unit_price`, `unit_type`, `supplier_quantity`, `reorder_threshold`, `location`, `image_path`, `image_url`, `status`, `is_deleted`, `source_inventory_id`, `last_updated`) VALUES
(321, 4, 'CEMEN-321', 'Cement', 'ascasdc', 'Construction', 0.00, 'per piece', 36, 0, 'asc', NULL, NULL, 'active', 0, 223, '2025-11-13 17:08:17'),
(323, 4, 'HW-0004', 'ACSV', 'wfe', 'Tools', 0.00, 'per box', 99, 0, 'efrf', NULL, NULL, 'active', 0, 224, '2025-11-13 17:33:00');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_catalog_unit`
--

CREATE TABLE `supplier_catalog_unit` (
  `supplier_catalog_id` int(11) NOT NULL,
  `unit_type_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_form_drafts`
--

CREATE TABLE `supplier_form_drafts` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `form_id` varchar(128) NOT NULL,
  `data` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supplier_form_drafts`
--

INSERT INTO `supplier_form_drafts` (`id`, `supplier_id`, `form_id`, `data`, `updated_at`) VALUES
(468, 1, 'addProductForm', '{\"name\":\"\",\"sku\":\"\",\"category\":\"\",\"unit_type_code\":\"adsfcsad\",\"location\":\"\",\"description\":\"\",\"variations\":{},\"timestamp\":1762074779116}', '2025-11-02 09:12:59'),
(511, 1, 'editProductForm', '{\"id\":\"276\",\"name\":\"Nail\",\"sku\":\"HW-TIL-001\",\"category\":\"Construction\",\"unit_type_code\":\"Klg\",\"edit_variation_attrs[Kilo][]\":\"4k\",\"edit_variation_attrs[Size][]\":\"4mm\",\"variation_prices[Kilo:1k]\":\"\",\"variation_stocks[Kilo:1k]\":\"\",\"variation_prices[Kilo:2k]\":\"\",\"variation_stocks[Kilo:2k]\":\"\",\"variation_prices[Kilo:3k]\":\"\",\"variation_stocks[Kilo:3k]\":\"\",\"variation_prices[Kilo:4k]\":\"\",\"variation_stocks[Kilo:4k]\":\"\",\"variation_prices[Size:1mm]\":\"\",\"variation_stocks[Size:1mm]\":\"\",\"variation_prices[Size:3mm]\":\"\",\"variation_stocks[Size:3mm]\":\"\",\"variation_prices[Size:2mm]\":\"\",\"variation_stocks[Size:2mm]\":\"\",\"variation_prices[Size:4mm]\":\"\",\"variation_stocks[Size:4mm]\":\"\",\"location\":\"Tile Section\",\"description\":\"HAHAHA\",\"variations\":{},\"timestamp\":1762060711499}', '2025-11-02 05:18:31');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_product_variations`
--

CREATE TABLE `supplier_product_variations` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `variation` varchar(50) NOT NULL,
  `unit_type` varchar(20) DEFAULT 'per piece',
  `unit_price` decimal(10,2) DEFAULT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `last_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supplier_product_variations`
--

INSERT INTO `supplier_product_variations` (`id`, `product_id`, `variation`, `unit_type`, `unit_price`, `stock`, `last_updated`) VALUES
(176, 291, 'Type:Standard', 'per per sack', 21.00, 44, '2025-11-03 22:32:00'),
(177, 291, 'Material:Paper', 'per per sack', 22.00, 54, '2025-11-03 22:32:00'),
(178, 291, 'Material:Plastic', 'per per sack', 11.00, 45, '2025-11-03 22:32:00'),
(179, 292, 'Kilo:1k', 'per piece', 12.00, 0, '2025-11-13 04:32:50'),
(180, 292, 'Kilo:2k', 'per piece', 13.00, 0, '2025-11-13 04:32:50'),
(181, 292, 'Kilo:3k', 'per piece', 14.00, 0, '2025-11-13 04:32:50'),
(182, 292, 'Size:1mm', 'per piece', 15.00, 0, '2025-11-13 04:32:50'),
(183, 292, 'Size:2mm', 'per piece', 16.00, 0, '2025-11-13 04:32:50'),
(184, 292, 'Size:3mm', 'per piece', 17.00, 0, '2025-11-13 04:32:50'),
(286, 321, 'Brand:Generic', 'per piece', 34.00, 3, '2025-11-13 15:07:10'),
(287, 321, 'Type:Standard', 'per piece', 33.00, 33, '2025-11-13 15:07:10'),
(288, 321, 'Size:Large', 'per piece', 23.00, 22, '2025-11-13 15:07:29'),
(291, 321, 'Size:Medium', 'per piece', 34.00, 33, '2025-11-13 17:08:17'),
(292, 323, 'Quantity:10', 'per box', 34.00, 33, '2025-11-13 17:32:34'),
(293, 323, 'Size:Medium', 'per box', 33.00, 33, '2025-11-13 17:32:34'),
(294, 323, 'Size:Small', 'per box', 33.00, 33, '2025-11-13 17:32:34');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_security_logs`
--

CREATE TABLE `supplier_security_logs` (
  `id` int(11) NOT NULL,
  `supplier_user_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `details` longtext DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL,
  `level` varchar(10) DEFAULT 'INFO',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supplier_security_logs`
--

INSERT INTO `supplier_security_logs` (`id`, `supplier_user_id`, `username`, `event_type`, `details`, `ip_address`, `user_agent`, `status`, `level`, `created_at`) VALUES
(1, 2, 'Supplier', 'profile_update', '{\"changed_fields\":[\"name\",\"email\",\"phone\",\"address\"]}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:43:16'),
(2, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:43:16'),
(3, 2, 'Supplier', 'profile_update', '{\"changed_fields\":[\"name\",\"email\",\"phone\",\"address\"]}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:44:08'),
(4, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:44:08'),
(5, 2, 'Supplier', 'profile_update', '{\"changed_fields\":[\"name\",\"email\",\"phone\",\"address\"]}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:46:38'),
(6, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:46:38'),
(7, 2, 'Supplier', 'profile_update', '{\"changed_fields\":[\"name\",\"email\",\"phone\",\"address\"]}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:46:41'),
(8, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:46:41'),
(9, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:49:35'),
(10, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:52:49'),
(13, 2, 'Supplier', 'profile_update', '{\"changed_fields\":[\"name\",\"email\",\"phone\",\"address\"]}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:53:51'),
(14, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:53:51'),
(15, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:56:53'),
(16, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:56:58'),
(18, 2, 'Supplier', 'profile_update', '{\"changed_fields\":[\"name\",\"email\",\"phone\",\"address\"]}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:57:06'),
(19, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:57:06'),
(20, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:57:08'),
(21, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:57:09'),
(26, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:57:10'),
(34, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 08:57:11'),
(35, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 10:24:32'),
(36, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 10:24:34'),
(37, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 10:25:56'),
(38, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 10:25:57'),
(40, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 10:44:11'),
(41, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 11:17:26'),
(42, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 11:18:41'),
(45, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 13:05:08'),
(46, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 14:06:51'),
(47, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 14:06:52'),
(48, 2, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-29 14:49:42'),
(51, 1, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-30 14:46:13'),
(52, 1, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-30 15:34:45'),
(53, 1, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-10-31 15:08:09'),
(54, 1, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-11-01 13:45:39'),
(55, 1, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-11-02 10:21:48'),
(56, 1, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-11-02 13:13:04'),
(57, 1, 'Supplier', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-11-02 14:59:14'),
(58, 4, 'abc', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-11-02 16:17:28'),
(59, 4, 'abc', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-11-02 19:56:46'),
(60, 4, 'abc', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-11-03 06:36:54'),
(61, 4, 'abc', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-11-03 14:59:54'),
(62, 4, 'abc', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-11-03 15:06:55'),
(63, 4, 'abc', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-11-12 20:21:46'),
(64, 4, 'abc', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-11-12 20:23:38'),
(65, 4, 'abc', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-11-12 20:31:12'),
(66, 4, 'abc', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-11-13 02:06:44'),
(67, 4, 'abc', 'profile_view', '{\"component\":\"supplier_profile\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 'success', 'INFO', '2025-11-13 10:53:41');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_security_logs_archive`
--

CREATE TABLE `supplier_security_logs_archive` (
  `id` int(11) NOT NULL,
  `supplier_user_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `event_type` varchar(100) NOT NULL,
  `details` longtext DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `status` varchar(30) DEFAULT NULL,
  `level` varchar(10) DEFAULT 'INFO',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `unit_types`
--

CREATE TABLE `unit_types` (
  `id` int(11) NOT NULL,
  `code` varchar(16) NOT NULL,
  `name` varchar(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `description` text DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `unit_types`
--

INSERT INTO `unit_types` (`id`, `code`, `name`, `created_at`, `description`, `metadata`, `is_deleted`, `deleted_at`, `updated_at`) VALUES
(53, 'pc', 'Piece', '2025-11-01 09:12:28', NULL, '{\"attributes\":[\"Size\",\"Type\",\"Brand\"]}', 0, NULL, '2025-11-01 09:12:28'),
(54, 'set', 'Set', '2025-11-01 09:12:28', NULL, '{\"attributes\":[\"Size\",\"Type\",\"Brand\"]}', 0, NULL, '2025-11-01 09:12:28'),
(55, 'box', 'Box', '2025-11-01 09:12:28', NULL, '{\"attributes\":[\"Quantity\",\"Size\"]}', 0, NULL, '2025-11-01 09:12:28'),
(56, 'pack', 'Pack', '2025-11-01 09:12:28', NULL, '{\"attributes\":[\"Quantity\",\"Size\"]}', 0, NULL, '2025-11-01 09:12:28'),
(57, 'bag', 'Bag', '2025-11-01 09:12:28', NULL, '{\"attributes\":[\"Brand\",\"Type\"]}', 0, NULL, '2025-11-01 09:12:28'),
(58, 'roll', 'Roll', '2025-11-01 09:12:28', NULL, '{\"attributes\":[\"Length\",\"Thickness\"]}', 0, NULL, '2025-11-01 09:12:28'),
(59, 'bar', 'Bar', '2025-11-01 09:12:28', NULL, '{\"attributes\":[\"Size\"]}', 0, NULL, '2025-11-01 09:12:28'),
(60, 'sheet', 'Sheet', '2025-11-01 09:12:28', NULL, '{\"attributes\":[\"Thickness\",\"Size\",\"Type\"]}', 0, NULL, '2025-11-01 09:12:28'),
(61, 'm', 'Meter', '2025-11-01 09:12:28', NULL, '{\"attributes\":[\"Length\",\"Diameter\"]}', 0, NULL, '2025-11-01 09:12:28'),
(62, 'L', 'Liter', '2025-11-01 09:12:28', NULL, '{\"attributes\":[\"Color\",\"Brand\",\"Finish\"]}', 0, NULL, '2025-11-01 09:12:28'),
(63, 'gal', 'Gallon', '2025-11-01 09:12:28', NULL, '{\"attributes\":[\"Color\",\"Brand\",\"Finish\"]}', 0, NULL, '2025-11-01 09:12:28'),
(64, 'tube', 'Tube', '2025-11-01 09:12:28', NULL, '{\"attributes\":[\"Size\",\"Material\"]}', 0, NULL, '2025-11-01 09:12:28'),
(65, 'btl', 'Bottle', '2025-11-01 09:12:28', NULL, '{\"attributes\":[\"Size\",\"Type\"]}', 0, NULL, '2025-11-01 09:12:28'),
(66, 'can', 'Can', '2025-11-01 09:12:28', NULL, '{\"attributes\":[\"Size\",\"Color\",\"Brand\"]}', 0, NULL, '2025-11-01 09:12:28'),
(70, 'sack', 'per sack', '2025-11-01 09:41:14', NULL, NULL, 0, NULL, '2025-11-01 09:41:14'),
(72, 'Klg', 'Kilo', '2025-11-01 12:13:09', NULL, NULL, 0, NULL, '2025-11-01 12:13:09');

-- --------------------------------------------------------

--
-- Table structure for table `unit_type_variations`
--

CREATE TABLE `unit_type_variations` (
  `id` int(11) NOT NULL,
  `unit_type_id` int(11) NOT NULL,
  `attribute` varchar(64) NOT NULL,
  `value` varchar(128) NOT NULL,
  `description` text DEFAULT NULL,
  `metadata` text DEFAULT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  `deleted_at` datetime DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `unit_type_variations`
--

INSERT INTO `unit_type_variations` (`id`, `unit_type_id`, `attribute`, `value`, `description`, `metadata`, `is_deleted`, `deleted_at`, `updated_at`) VALUES
(30, 53, 'Size', 'Small', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(31, 53, 'Size', 'Medium', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(32, 53, 'Size', 'Large', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(33, 53, 'Type', 'Standard', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(34, 53, 'Brand', 'Generic', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(35, 54, 'Size', 'Small', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(36, 54, 'Size', 'Medium', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(37, 54, 'Size', 'Large', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(38, 54, 'Type', 'Standard', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(39, 54, 'Brand', 'Generic', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(40, 55, 'Quantity', '10', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(41, 55, 'Quantity', '20', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(42, 55, 'Size', 'Small', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(43, 55, 'Size', 'Medium', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(44, 56, 'Quantity', '10', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(45, 56, 'Quantity', '20', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(46, 56, 'Size', 'Small', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(47, 56, 'Size', 'Medium', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(48, 57, 'Brand', 'Generic', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(49, 57, 'Type', 'Portland', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(50, 57, 'Type', 'Masonry', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(51, 58, 'Length', '10m', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(52, 58, 'Length', '20m', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(53, 58, 'Thickness', '1mm', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(54, 58, 'Thickness', '2mm', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(55, 59, 'Size', '10mm', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(56, 59, 'Size', '12mm', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(57, 59, 'Size', '16mm', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(58, 60, 'Thickness', '0.5mm', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(59, 60, 'Thickness', '1mm', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(60, 60, 'Size', 'Small', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(61, 60, 'Size', 'Medium', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(62, 60, 'Type', 'Standard', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(63, 61, 'Length', '1m', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(64, 61, 'Length', '2m', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(65, 61, 'Diameter', '10mm', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(66, 61, 'Diameter', '12mm', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(67, 62, 'Color', 'Red', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(68, 62, 'Color', 'Blue', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(69, 62, 'Color', 'White', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(70, 62, 'Brand', 'Generic', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(71, 62, 'Finish', 'Glossy', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(72, 62, 'Finish', 'Matte', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(73, 63, 'Color', 'Red', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(74, 63, 'Color', 'Blue', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(75, 63, 'Color', 'White', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(76, 63, 'Brand', 'Generic', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(77, 63, 'Finish', 'Glossy', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(78, 63, 'Finish', 'Matte', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(79, 64, 'Size', 'Small', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(80, 64, 'Size', 'Medium', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(81, 64, 'Size', 'Large', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(82, 64, 'Material', 'PVC', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(83, 64, 'Material', 'Metal', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(84, 65, 'Size', 'Small', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(85, 65, 'Size', 'Medium', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(86, 65, 'Type', 'Standard', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(87, 66, 'Size', 'Small', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(88, 66, 'Size', 'Medium', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(89, 66, 'Color', 'Red', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(90, 66, 'Color', 'Blue', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(91, 66, 'Color', 'White', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(92, 66, 'Brand', 'Generic', NULL, NULL, 0, NULL, '2025-11-01 09:40:51'),
(93, 70, 'Type', 'Standard', NULL, NULL, 0, NULL, '2025-11-01 09:41:31'),
(94, 70, 'Material', 'Plastic', NULL, NULL, 0, NULL, '2025-11-01 09:41:31'),
(95, 70, 'Material', 'Paper', NULL, NULL, 0, NULL, '2025-11-01 09:41:31'),
(97, 72, 'Kilo', '1k', NULL, NULL, 0, NULL, '2025-11-01 12:14:02'),
(98, 72, 'Kilo', '2k', NULL, NULL, 0, NULL, '2025-11-01 12:14:02'),
(99, 72, 'Kilo', '3k', NULL, NULL, 0, NULL, '2025-11-01 12:14:02'),
(100, 72, 'Kilo', '4k', NULL, NULL, 0, NULL, '2025-11-01 12:14:02'),
(101, 72, 'Kilo', '5k', NULL, NULL, 0, NULL, '2025-11-01 12:14:02'),
(102, 72, 'Size', '1mm', NULL, NULL, 0, NULL, '2025-11-01 12:15:16'),
(103, 72, 'Size', '2mm', NULL, NULL, 0, NULL, '2025-11-01 12:15:16'),
(104, 72, 'Size', '3mm', NULL, NULL, 0, NULL, '2025-11-01 12:15:16'),
(105, 72, 'Size', '4mm', NULL, NULL, 0, NULL, '2025-11-01 12:15:16'),
(108, 72, 'Kilo', 'qs', NULL, NULL, 1, '2025-11-02 20:47:32', '2025-11-02 12:47:32');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('management','staff','supplier') NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `role`, `email`, `first_name`, `middle_name`, `last_name`, `phone`, `address`, `city`, `province`, `postal_code`, `profile_picture`, `created_at`, `is_deleted`) VALUES
(4, 'Admin', '$2y$10$RI02MWxeExLrQTqrsxJsJ./7fa278Ks2IIoGHw/1Fg5/YVALi/s7G', 'management', 'vpvillanueva.chmsu@gmail.com', 'Vince', 'P.', 'Villanueva', '09942715542', 'Taloc', 'Bago City', 'Negros Occidental', '6101', '', '2025-11-02 23:25:25', 0),
(5, 'Staff', '$2y$10$CS4fEyhovp/DfWt50kcARO/WZGylkLtazyDPZBB7r0AP6loIah8CG', 'staff', 'earlarnulfj@gmail.com', 'Alvin', 'J.', 'Cango', '09942715542', 'hahaa', 'Hardware District', 'Negros Occidental', '6101', '', '2025-11-02 23:30:58', 0),
(8, 'abc', '$2y$10$XW5lA/hswxvuc9StalJ2neqj5JDP9V6myy0AKx9a.aaXEbAe9ntQq', 'supplier', 'ajcango.chmsu@gmail.com', 'Earl', 'D.', 'Jaud', '09942715542', 'GFHGJFH', 'Hardware District', 'Negros Occidental', '6101', '', '2025-11-02 23:36:00', 0),
(10, 'abc_supplier', '$2y$10$2zuBoPa8r2rQTW7ARXTSs.dKtRXwAQa1VC9UJSKM1RlU5l8/5w7be', 'supplier', 'patrimoniovillanuevince@gmail.com', 'Juan', 'D.', 'Cruz', '09123456789', 'AESFESF', 'ascas', 'ESFESSF', '2342', '', '2025-11-03 16:08:26', 0);

-- --------------------------------------------------------

--
-- Table structure for table `user_sessions`
--

CREATE TABLE `user_sessions` (
  `id` int(11) NOT NULL,
  `session_token` varchar(128) NOT NULL,
  `user_id` int(11) NOT NULL,
  `user_type` enum('admin','staff','supplier') NOT NULL,
  `username` varchar(50) NOT NULL,
  `session_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`session_data`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `variation_attributes`
--

CREATE TABLE `variation_attributes` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `unit_type_code` varchar(16) NOT NULL,
  `attribute_name` varchar(50) NOT NULL,
  `attribute_values` text NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `weight_based_inventory`
-- (See below for the actual view)
--
CREATE TABLE `weight_based_inventory` (
);

-- --------------------------------------------------------

--
-- Structure for view `weight_based_inventory`
--
DROP TABLE IF EXISTS `weight_based_inventory`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `weight_based_inventory`  AS SELECT `inventory`.`id` AS `id`, `inventory`.`sku` AS `sku`, `inventory`.`name` AS `name`, `inventory`.`description` AS `description`, `inventory`.`current_weight` AS `stock_weight`, `inventory`.`min_weight_threshold` AS `min_weight_threshold`, `inventory`.`weight_unit` AS `weight_unit`, `inventory`.`unit_price` AS `price_per_kg`, `inventory`.`supplier_id` AS `supplier_id`, `inventory`.`category` AS `category`, `inventory`.`location` AS `location`, CASE WHEN `inventory`.`current_weight` <= 0 THEN 'Out of Stock' WHEN `inventory`.`current_weight` <= `inventory`.`min_weight_threshold` THEN 'Low Stock' ELSE 'In Stock' END AS `stock_status` FROM `inventory` WHERE `inventory`.`is_weight_based` = 1 ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_activity`
--
ALTER TABLE `admin_activity`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_admin_user` (`admin_user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `admin_attributes`
--
ALTER TABLE `admin_attributes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `idx_admin_attributes_name` (`name`),
  ADD KEY `idx_admin_attributes_is_deleted` (`is_deleted`),
  ADD KEY `idx_admin_attributes_sort_order` (`sort_order`);

--
-- Indexes for table `admin_attribute_options`
--
ALTER TABLE `admin_attribute_options`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_admin_attr_opt` (`attribute_id`,`value`),
  ADD KEY `idx_admin_attr_opt_attribute_id` (`attribute_id`),
  ADD KEY `idx_admin_attr_opt_value` (`value`),
  ADD KEY `idx_admin_attr_opt_is_deleted` (`is_deleted`),
  ADD KEY `idx_admin_attr_opt_sort_order` (`sort_order`);

--
-- Indexes for table `admin_orders`
--
ALTER TABLE `admin_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `inventory_id` (`inventory_id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `confirmation_status` (`confirmation_status`);

--
-- Indexes for table `admin_unit_types`
--
ALTER TABLE `admin_unit_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_admin_unit_types_name` (`name`),
  ADD KEY `idx_admin_unit_types_code` (`code`),
  ADD KEY `idx_admin_unit_types_is_deleted` (`is_deleted`);

--
-- Indexes for table `admin_unit_type_variations`
--
ALTER TABLE `admin_unit_type_variations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_admin_ut_attr_val` (`unit_type_id`,`attribute`,`value`),
  ADD KEY `idx_admin_utv_unit_type_id` (`unit_type_id`),
  ADD KEY `idx_admin_utv_attribute` (`attribute`),
  ADD KEY `idx_admin_utv_is_deleted` (`is_deleted`);

--
-- Indexes for table `alert_logs`
--
ALTER TABLE `alert_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `inventory_id` (`inventory_id`);

--
-- Indexes for table `auth_logs`
--
ALTER TABLE `auth_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_is_read` (`is_read`);

--
-- Indexes for table `chat_message_status`
--
ALTER TABLE `chat_message_status`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_message_status` (`message_id`),
  ADD KEY `idx_message_id` (`message_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `chat_typing_indicators`
--
ALTER TABLE `chat_typing_indicators`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_typing` (`supplier_id`,`sender_type`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_updated_at` (`updated_at`);

--
-- Indexes for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_supplier_id` (`supplier_id`) COMMENT 'Index for JOIN with suppliers table',
  ADD KEY `idx_sku` (`sku`) COMMENT 'Index for SKU lookups',
  ADD KEY `idx_category` (`category`) COMMENT 'Index for category filtering',
  ADD KEY `idx_is_deleted` (`is_deleted`) COMMENT 'Index for soft delete filtering',
  ADD KEY `idx_name` (`name`) COMMENT 'Index for name searches',
  ADD KEY `idx_unit_type` (`unit_type`) COMMENT 'Index for unit type filtering',
  ADD KEY `idx_unit_price` (`unit_price`) COMMENT 'Index for price queries';

--
-- Indexes for table `inventory_from_completed_orders`
--
ALTER TABLE `inventory_from_completed_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_order_inventory_variation` (`order_id`,`order_table`,`inventory_id`,`variation`(100)),
  ADD KEY `idx_inventory_id` (`inventory_id`),
  ADD KEY `idx_order_id` (`order_id`),
  ADD KEY `idx_order_table` (`order_table`),
  ADD KEY `idx_sku` (`sku`),
  ADD KEY `idx_category` (`category`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_completion_date` (`completion_date`);

--
-- Indexes for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inventory` (`inventory_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created` (`created_at`),
  ADD KEY `idx_order` (`order_id`),
  ADD KEY `idx_variation` (`variation`);

--
-- Indexes for table `inventory_variations`
--
ALTER TABLE `inventory_variations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_inventory_variation` (`inventory_id`,`variation`(100)),
  ADD KEY `idx_inventory_id` (`inventory_id`),
  ADD KEY `idx_variation` (`variation`(100));

--
-- Indexes for table `log_rotation_meta`
--
ALTER TABLE `log_rotation_meta`
  ADD PRIMARY KEY (`name`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_recipient` (`recipient_type`,`recipient_id`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `fk_notifications_orders` (`order_id`),
  ADD KEY `fk_notifications_alert_logs` (`alert_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `inventory_id` (`inventory_id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_email` (`email`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_verification_code` (`verification_code`),
  ADD KEY `idx_expires_at` (`expires_at`),
  ADD KEY `idx_email_token` (`email`,`token`),
  ADD KEY `idx_email_code` (`email`,`verification_code`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `sales_transactions`
--
ALTER TABLE `sales_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `inventory_id` (`inventory_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `supplier_activity`
--
ALTER TABLE `supplier_activity`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sup_act_user` (`supplier_user_id`),
  ADD KEY `idx_sup_act_action` (`action`),
  ADD KEY `idx_sup_act_created` (`created_at`);

--
-- Indexes for table `supplier_activity_archive`
--
ALTER TABLE `supplier_activity_archive`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sup_act_user` (`supplier_user_id`),
  ADD KEY `idx_sup_act_action` (`action`),
  ADD KEY `idx_sup_act_created` (`created_at`);

--
-- Indexes for table `supplier_catalog`
--
ALTER TABLE `supplier_catalog`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_supplier_sku` (`supplier_id`,`sku`),
  ADD KEY `idx_supplier_id` (`supplier_id`);

--
-- Indexes for table `supplier_catalog_unit`
--
ALTER TABLE `supplier_catalog_unit`
  ADD PRIMARY KEY (`supplier_catalog_id`),
  ADD KEY `unit_type_id` (`unit_type_id`);

--
-- Indexes for table `supplier_form_drafts`
--
ALTER TABLE `supplier_form_drafts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_supplier_form` (`supplier_id`,`form_id`);

--
-- Indexes for table `supplier_product_variations`
--
ALTER TABLE `supplier_product_variations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_product_variation` (`product_id`,`variation`),
  ADD KEY `idx_product_id` (`product_id`);

--
-- Indexes for table `supplier_security_logs`
--
ALTER TABLE `supplier_security_logs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_supplier_sec` (`supplier_user_id`,`created_at`,`event_type`),
  ADD KEY `idx_sup_sec_user` (`supplier_user_id`),
  ADD KEY `idx_sup_sec_event` (`event_type`),
  ADD KEY `idx_sup_sec_created` (`created_at`);

--
-- Indexes for table `supplier_security_logs_archive`
--
ALTER TABLE `supplier_security_logs_archive`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_supplier_sec` (`supplier_user_id`,`created_at`,`event_type`),
  ADD KEY `idx_sup_sec_user` (`supplier_user_id`),
  ADD KEY `idx_sup_sec_event` (`event_type`),
  ADD KEY `idx_sup_sec_created` (`created_at`);

--
-- Indexes for table `unit_types`
--
ALTER TABLE `unit_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `idx_unit_types_name` (`name`),
  ADD KEY `idx_unit_types_is_deleted` (`is_deleted`);

--
-- Indexes for table `unit_type_variations`
--
ALTER TABLE `unit_type_variations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_ut_attr_val` (`unit_type_id`,`attribute`,`value`),
  ADD KEY `idx_utv_unit_type_id` (`unit_type_id`),
  ADD KEY `idx_utv_attribute` (`attribute`),
  ADD KEY `idx_utv_is_deleted` (`is_deleted`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `idx_users_is_deleted` (`is_deleted`);

--
-- Indexes for table `user_sessions`
--
ALTER TABLE `user_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_token` (`session_token`),
  ADD KEY `idx_session_token` (`session_token`),
  ADD KEY `idx_user_type_id` (`user_type`,`user_id`),
  ADD KEY `idx_expires_at` (`expires_at`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `variation_attributes`
--
ALTER TABLE `variation_attributes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_supplier_unit_attr` (`supplier_id`,`unit_type_code`,`attribute_name`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_unit_type` (`unit_type_code`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_activity`
--
ALTER TABLE `admin_activity`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `admin_attributes`
--
ALTER TABLE `admin_attributes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `admin_attribute_options`
--
ALTER TABLE `admin_attribute_options`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `admin_orders`
--
ALTER TABLE `admin_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=53;

--
-- AUTO_INCREMENT for table `admin_unit_types`
--
ALTER TABLE `admin_unit_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `admin_unit_type_variations`
--
ALTER TABLE `admin_unit_type_variations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `alert_logs`
--
ALTER TABLE `alert_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `auth_logs`
--
ALTER TABLE `auth_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=188;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `chat_message_status`
--
ALTER TABLE `chat_message_status`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `chat_typing_indicators`
--
ALTER TABLE `chat_typing_indicators`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=56;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Primary key - matches admin_orders.inventory_id', AUTO_INCREMENT=225;

--
-- AUTO_INCREMENT for table `inventory_from_completed_orders`
--
ALTER TABLE `inventory_from_completed_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `inventory_logs`
--
ALTER TABLE `inventory_logs`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_variations`
--
ALTER TABLE `inventory_variations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=636;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT for table `sales_transactions`
--
ALTER TABLE `sales_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `supplier_activity`
--
ALTER TABLE `supplier_activity`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `supplier_activity_archive`
--
ALTER TABLE `supplier_activity_archive`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_catalog`
--
ALTER TABLE `supplier_catalog`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=324;

--
-- AUTO_INCREMENT for table `supplier_form_drafts`
--
ALTER TABLE `supplier_form_drafts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=526;

--
-- AUTO_INCREMENT for table `supplier_product_variations`
--
ALTER TABLE `supplier_product_variations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=295;

--
-- AUTO_INCREMENT for table `supplier_security_logs`
--
ALTER TABLE `supplier_security_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=68;

--
-- AUTO_INCREMENT for table `supplier_security_logs_archive`
--
ALTER TABLE `supplier_security_logs_archive`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `unit_types`
--
ALTER TABLE `unit_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=74;

--
-- AUTO_INCREMENT for table `unit_type_variations`
--
ALTER TABLE `unit_type_variations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=109;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `variation_attributes`
--
ALTER TABLE `variation_attributes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `admin_attribute_options`
--
ALTER TABLE `admin_attribute_options`
  ADD CONSTRAINT `admin_attribute_options_ibfk_1` FOREIGN KEY (`attribute_id`) REFERENCES `admin_attributes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `admin_unit_type_variations`
--
ALTER TABLE `admin_unit_type_variations`
  ADD CONSTRAINT `admin_unit_type_variations_ibfk_1` FOREIGN KEY (`unit_type_id`) REFERENCES `admin_unit_types` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `alert_logs`
--
ALTER TABLE `alert_logs`
  ADD CONSTRAINT `alert_logs_ibfk_1` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`);

--
-- Constraints for table `chat_message_status`
--
ALTER TABLE `chat_message_status`
  ADD CONSTRAINT `chat_message_status_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `chat_messages` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD CONSTRAINT `deliveries_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`);

--
-- Constraints for table `inventory_variations`
--
ALTER TABLE `inventory_variations`
  ADD CONSTRAINT `fk_inventory_variations_inventory` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
