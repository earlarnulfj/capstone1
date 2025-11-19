-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 21, 2025 at 10:06 AM
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
-- Table structure for table `alert_logs`
--

CREATE TABLE `alert_logs` (
  `id` int(11) NOT NULL,
  `inventory_id` int(11) DEFAULT NULL,
  `alert_type` enum('low_stock','out_of_stock','reorder') NOT NULL,
  `alert_date` datetime DEFAULT current_timestamp(),
  `is_resolved` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `alert_logs`
--

INSERT INTO `alert_logs` (`id`, `inventory_id`, `alert_type`, `alert_date`, `is_resolved`) VALUES
(10, 25, 'low_stock', '2025-09-21 02:41:34', 1),
(11, 31, 'low_stock', '2025-09-21 10:10:04', 0);

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
(1, 'test', NULL, 'test_user', 'test_action', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.100.3 Chrome/132.0.6834.210 Electron/34.5.1 Safari/537.36', 'no_session', '{\"info\":\"Testing auth logs\"}', '2025-09-20 13:33:32'),
(2, 'admin', 12, 'admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 13:34:08'),
(3, 'staff', 13, 'staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 13:34:57'),
(4, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.100.3 Chrome/132.0.6834.210 Electron/34.5.1 Safari/537.36', '5pequkjomqvgckglc12qvv9tig', '{\"info\":\"Successful login\"}', '2025-09-20 13:36:44'),
(5, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 13:37:43'),
(6, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 13:37:52'),
(7, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 13:39:57'),
(8, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 13:44:51'),
(9, 'supplier', 9, 'xyz', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 13:59:24'),
(10, 'admin', 12, 'admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 14:00:10'),
(11, 'admin', 12, 'admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 14:18:25'),
(12, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 14:18:55'),
(13, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.100.3 Chrome/132.0.6834.210 Electron/34.5.1 Safari/537.36', '5pequkjomqvgckglc12qvv9tig', '{\"info\":\"Successful login\"}', '2025-09-20 14:48:21'),
(14, 'supplier', NULL, 'admin', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Account not found or inactive\"}', '2025-09-20 15:19:56'),
(15, 'admin', 12, 'admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 15:20:02'),
(16, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.100.3 Chrome/132.0.6834.210 Electron/34.5.1 Safari/537.36', '5pequkjomqvgckglc12qvv9tig', '{\"info\":\"Successful login\"}', '2025-09-20 15:27:35'),
(17, 'admin', 12, 'admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 15:28:35'),
(18, 'admin', 12, 'admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 15:28:43'),
(19, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 15:28:54'),
(20, 'admin', 12, 'admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 15:32:28'),
(21, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 15:42:21'),
(22, 'admin', 12, 'admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 15:42:53'),
(23, 'admin', 12, 'admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 15:43:08'),
(24, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'k3kt17iti2ehtbenlnjanl1gat', '{\"info\":\"Successful login\"}', '2025-09-20 15:43:15'),
(25, 'admin', 12, 'admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'no8coo6vnekd4vjpmkjf66e104', '{\"info\":\"Successful login\"}', '2025-09-21 00:36:11'),
(26, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'no8coo6vnekd4vjpmkjf66e104', '{\"info\":\"Successful login\"}', '2025-09-21 00:36:23'),
(27, 'admin', 12, 'admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.100.3 Chrome/132.0.6834.210 Electron/34.5.1 Safari/537.36', '7p4jljlg9166oo86k1tqn180kc', '{\"info\":\"Successful login\"}', '2025-09-21 01:28:08'),
(28, 'staff', 13, 'staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'no8coo6vnekd4vjpmkjf66e104', '{\"info\":\"Successful login\"}', '2025-09-21 02:21:47'),
(29, 'admin', 12, 'admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'no8coo6vnekd4vjpmkjf66e104', '{\"info\":\"Successful login\"}', '2025-09-21 03:14:25'),
(30, 'admin', 20, 'abc', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'no8coo6vnekd4vjpmkjf66e104', '{\"info\":\"Role mismatch\"}', '2025-09-21 03:14:33'),
(31, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'no8coo6vnekd4vjpmkjf66e104', '{\"info\":\"Successful login\"}', '2025-09-21 03:14:40'),
(32, 'admin', 12, 'admin', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'no8coo6vnekd4vjpmkjf66e104', '{\"info\":\"Successful login\"}', '2025-09-21 03:22:38'),
(33, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'no8coo6vnekd4vjpmkjf66e104', '{\"info\":\"Successful login\"}', '2025-09-21 03:22:59'),
(34, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.100.3 Chrome/132.0.6834.210 Electron/34.5.1 Safari/537.36', '7p4jljlg9166oo86k1tqn180kc', '{\"info\":\"Successful login\"}', '2025-09-21 04:04:06'),
(35, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.100.3 Chrome/132.0.6834.210 Electron/34.5.1 Safari/537.36', '7p4jljlg9166oo86k1tqn180kc', '{\"info\":\"Successful login\"}', '2025-09-21 04:04:37'),
(36, 'supplier', NULL, 'staff', 'login_failed', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'no8coo6vnekd4vjpmkjf66e104', '{\"info\":\"Account not found or inactive\"}', '2025-09-21 06:23:09'),
(37, 'staff', 13, 'staff', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'no8coo6vnekd4vjpmkjf66e104', '{\"info\":\"Successful login\"}', '2025-09-21 06:23:52'),
(38, 'supplier', 8, 'abc', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', 'no8coo6vnekd4vjpmkjf66e104', '{\"info\":\"Successful login\"}', '2025-09-21 08:04:50');

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
(22, 20, NULL, 'supplier', 'abc', 'Test message from supplier - 2025-09-21 09:21:38', 1, '2025-09-21 07:21:38'),
(23, 20, 1, 'admin', 'Admin', 'Test message from admin - 2025-09-21 09:22:20', 0, '2025-09-21 07:22:20'),
(24, 20, 1, 'admin', 'Admin', 'Test message from admin - 2025-09-21 09:22:49', 0, '2025-09-21 07:22:49'),
(25, 20, 1, 'admin', 'Admin', 'Test message from admin - 2025-09-21 09:23:15', 0, '2025-09-21 07:23:15'),
(41, 8, NULL, 'supplier', 'abc', 'I have a question about my orders', 1, '2025-09-21 08:05:03'),
(42, 8, NULL, 'supplier', 'abc', 'Can you help me with product management?', 1, '2025-09-21 08:05:08'),
(43, 8, NULL, 'supplier', 'abc', 'Can you help me with product management?', 1, '2025-09-21 08:05:14');

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
(46, 22, 'sent', '2025-09-21 07:21:38'),
(47, 23, 'read', '2025-09-21 08:01:52'),
(48, 24, 'read', '2025-09-21 08:01:52'),
(49, 25, 'read', '2025-09-21 08:01:52'),
(113, 41, 'read', '2025-09-21 08:05:25'),
(115, 42, 'read', '2025-09-21 08:05:25'),
(117, 43, 'read', '2025-09-21 08:05:25');

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
  `status` enum('pending','in_transit','delivered','cancelled') DEFAULT 'pending',
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
(1, 28, '2025-09-20 18:05:20', 0.000000, 0.000000, '', 0, NULL, NULL, NULL, NULL, NULL, '2025-09-20 18:28:45'),
(2, 27, '2025-09-20 19:57:14', 0.000000, 0.000000, 'delivered', 0, '', '', '', '', '', '2025-09-20 17:57:37'),
(3, 29, '2025-09-20 20:33:38', 0.000000, 0.000000, 'delivered', 0, '', '', '', '', '', '2025-09-20 18:33:42'),
(4, 30, '2025-09-20 20:42:28', 0.000000, 0.000000, 'delivered', 0, '', '', '', '', '', '2025-09-20 18:42:40'),
(5, 31, '2025-09-20 20:48:55', 0.000000, 0.000000, 'delivered', 0, '', '', '', '', '', '2025-09-20 18:49:09'),
(6, 32, '2025-09-20 20:56:03', 0.000000, 0.000000, 'delivered', 0, '', '', '', '', '', '2025-09-20 18:56:08'),
(7, 33, '2025-09-20 21:01:08', 0.000000, 0.000000, 'delivered', 0, '', '', '', '', '', '2025-09-20 19:01:20'),
(8, 34, '2025-09-20 21:03:45', 0.000000, 0.000000, 'delivered', 0, '', '', '', '', '', '2025-09-20 19:03:47'),
(9, 37, '2025-09-21 03:06:15', NULL, NULL, '', NULL, NULL, NULL, NULL, NULL, NULL, '2025-09-20 19:07:44'),
(10, 38, '2025-09-21 03:08:29', NULL, NULL, 'delivered', NULL, NULL, NULL, NULL, NULL, NULL, '2025-09-20 19:10:46'),
(11, 39, '2025-09-21 03:11:50', NULL, NULL, 'delivered', NULL, NULL, NULL, NULL, NULL, NULL, '2025-09-20 19:12:25'),
(12, 41, '2025-09-20 21:15:48', 0.000000, 0.000000, 'delivered', 0, '', '', '', '', '', '2025-09-20 19:15:54'),
(13, 42, '2025-09-20 21:19:12', 0.000000, 0.000000, 'delivered', 0, '', '', '', '', '', '2025-09-20 19:19:19'),
(14, 44, '2025-09-21 09:04:05', 0.000000, 0.000000, 'in_transit', 0, '', '', '', '', '', '2025-09-21 07:04:05'),
(15, 45, '2025-09-21 09:05:10', 0.000000, 0.000000, 'delivered', 0, '', '', '', '', '', '2025-09-21 07:05:14');

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(11) NOT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `supplier_quantity` int(11) NOT NULL DEFAULT 0,
  `reorder_threshold` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `location` varchar(100) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `last_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `image_url` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`id`, `sku`, `name`, `description`, `quantity`, `supplier_quantity`, `reorder_threshold`, `supplier_id`, `category`, `unit_price`, `location`, `image_path`, `last_updated`, `image_url`) VALUES
(25, 'WR-001', 'Cement', 'gv', 43, 0, 20, 8, 'Materials', 200.00, 'Aisle A, Shelf 3', NULL, '2025-09-21 15:05:14', NULL),
(27, 'PL-001', 'Balay', 'rtgbdf', 34, 100, 10, 8, 'Tools', 500.00, 'Aisle A, Shelf 3', NULL, '2025-09-21 16:05:42', NULL),
(30, 'CB-001', 'vince', 'axzx', 110, 0, 10, 8, 'Hardware', 100.00, 'Ilistaran', NULL, '2025-09-20 23:09:11', NULL),
(31, 'dcsc', 'Lansang', 'dsc', 8, 0, 10, 8, 'Materials', 100.00, 'dsc', 'uploads/products/product_31_1758424136.jpg', '2025-09-21 14:34:04', 'uploads/products/product_31_1758424136.jpg');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `type` enum('low_stock','order_confirmation','delivery_update') NOT NULL,
  `channel` enum('sms','email','system') NOT NULL,
  `recipient_type` enum('management','supplier') NOT NULL,
  `recipient_id` int(11) DEFAULT NULL,
  `order_id` int(11) DEFAULT NULL,
  `alert_id` int(11) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `sent_at` datetime DEFAULT current_timestamp(),
  `status` enum('sent','failed','pending') DEFAULT 'pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `type`, `channel`, `recipient_type`, `recipient_id`, `order_id`, `alert_id`, `message`, `sent_at`, `status`) VALUES
(1, 'low_stock', 'sms', 'management', 5, NULL, NULL, 'Low stock alert: Handsaw is running low. Current quantity: 3, Threshold: 5', '2025-05-13 16:30:06', 'pending'),
(2, 'low_stock', 'sms', 'management', 5, NULL, NULL, 'Low stock alert: Blue Paint is running low. Current quantity: 8, Threshold: 8', '2025-05-14 10:04:23', 'pending'),
(4, 'low_stock', 'sms', 'management', 5, NULL, NULL, 'Low stock alert: PVC Pipe 1/2&quot; is running low. Current quantity: 0, Threshold: 20', '2025-05-17 16:49:11', 'pending'),
(5, 'low_stock', 'sms', 'management', 5, NULL, NULL, 'Low stock alert: Blue Paint is running low. Current quantity: 0, Threshold: 0', '2025-05-17 16:52:23', 'pending'),
(6, 'low_stock', 'sms', 'management', 5, NULL, NULL, 'Low stock alert: Hammer is running low. Current quantity: 0, Threshold: 4', '2025-05-17 16:53:51', 'pending'),
(10, 'low_stock', 'sms', 'management', 5, NULL, NULL, 'Low stock alert: Adjustable Wrench is running low. Current quantity: 0, Threshold: 1', '2025-05-17 18:32:10', 'pending'),
(11, 'low_stock', 'sms', 'management', 5, NULL, NULL, 'Low stock alert: PVC Pipe 1/2 is running low. Current quantity: 0, Threshold: 1', '2025-05-17 18:35:42', 'pending'),
(13, 'low_stock', 'sms', 'management', 5, NULL, NULL, 'Low stock alert: Hammer is running low. Current quantity: 10, Threshold: 10', '2025-05-18 18:35:38', 'pending'),
(15, 'delivery_update', 'system', 'supplier', 8, 28, NULL, 'Delivery status updated to: In transit', '2025-09-21 00:05:20', ''),
(16, 'delivery_update', 'system', 'supplier', 8, 28, NULL, 'Delivery status updated to: Delivered', '2025-09-21 01:08:26', ''),
(17, 'delivery_update', 'system', 'supplier', 8, 27, NULL, 'Delivery status updated to: In transit', '2025-09-21 01:57:14', 'sent'),
(18, 'delivery_update', 'system', 'supplier', 8, 27, NULL, 'Delivery status updated to: Delivered', '2025-09-21 01:57:37', ''),
(19, 'order_confirmation', 'system', '', 1, 29, NULL, 'Order #29 has been confirmed by supplier', '2025-09-21 02:17:04', 'sent'),
(20, 'delivery_update', 'system', 'supplier', 8, 29, NULL, 'Delivery status updated to: In transit', '2025-09-21 02:33:38', 'sent'),
(21, 'delivery_update', 'system', 'supplier', 8, 29, NULL, 'Delivery status updated to: Delivered', '2025-09-21 02:33:42', 'sent'),
(22, 'delivery_update', 'system', 'supplier', 8, 30, NULL, 'Delivery status updated to: In transit', '2025-09-21 02:42:28', 'sent'),
(23, 'delivery_update', 'system', 'supplier', 8, 30, NULL, 'Delivery status updated to: Delivered', '2025-09-21 02:42:40', ''),
(24, 'delivery_update', 'system', 'supplier', 8, 31, NULL, 'Delivery status updated to: In transit', '2025-09-21 02:48:55', 'sent'),
(25, 'delivery_update', 'system', 'supplier', 8, 31, NULL, 'Delivery status updated to: Delivered', '2025-09-21 02:49:09', 'sent'),
(26, 'delivery_update', 'system', 'supplier', 8, 32, NULL, 'Delivery status updated to: In transit', '2025-09-21 02:56:03', 'sent'),
(27, 'delivery_update', 'system', 'supplier', 8, 32, NULL, 'Delivery status updated to: Delivered', '2025-09-21 02:56:08', 'sent'),
(28, 'order_confirmation', 'system', '', 1, 33, NULL, 'Order #33 has been confirmed by supplier', '2025-09-21 03:01:03', 'sent'),
(29, 'delivery_update', 'system', 'supplier', 8, 33, NULL, 'Delivery status updated to: In transit', '2025-09-21 03:01:08', 'sent'),
(30, 'delivery_update', 'system', 'supplier', 8, 33, NULL, 'Delivery status updated to: Delivered', '2025-09-21 03:01:20', 'sent'),
(31, 'delivery_update', 'system', 'supplier', 8, 34, NULL, 'Delivery status updated to: In transit', '2025-09-21 03:03:45', 'sent'),
(32, 'delivery_update', 'system', 'supplier', 8, 34, NULL, 'Delivery status updated to: Delivered', '2025-09-21 03:03:47', 'sent'),
(33, 'delivery_update', 'system', 'supplier', 8, 41, NULL, 'Delivery status updated to: In transit', '2025-09-21 03:15:48', ''),
(34, 'delivery_update', 'system', 'supplier', 8, 41, NULL, 'Delivery status updated to: Delivered', '2025-09-21 03:15:54', 'sent'),
(35, '', 'system', '', 1, 40, NULL, 'Order #40 has been cancelled by supplier', '2025-09-21 03:18:30', 'sent'),
(36, 'order_confirmation', 'system', '', 1, 42, NULL, 'Order #42 has been confirmed by supplier', '2025-09-21 03:19:04', 'sent'),
(37, 'delivery_update', 'system', 'supplier', 8, 42, NULL, 'Delivery status updated to: In transit', '2025-09-21 03:19:12', ''),
(38, 'delivery_update', 'system', 'supplier', 8, 42, NULL, 'Delivery status updated to: Delivered', '2025-09-21 03:19:19', 'sent'),
(39, 'order_confirmation', '', '', 0, NULL, NULL, 'Order #12345 confirmed by [ABC Suppliers Ltd]', '2025-09-21 10:05:59', 'sent'),
(40, '', '', '', 0, NULL, NULL, 'New message from [XYZ Trading Co]: Delivery scheduled for tomorrow', '2025-09-21 10:05:59', 'sent'),
(41, 'order_confirmation', '', '', 0, NULL, NULL, 'Order #12345 confirmed by [ABC Suppliers Ltd]', '2025-09-21 10:06:26', 'sent'),
(42, '', '', '', 0, NULL, NULL, 'New message from [XYZ Trading Co]: Delivery scheduled for tomorrow', '2025-09-21 10:06:26', 'sent'),
(43, 'order_confirmation', '', '', 0, NULL, NULL, 'Order #12345 has been confirmed by [ABC Hardware Supplies]', '2025-09-21 10:06:54', 'sent'),
(44, '', '', '', 0, NULL, NULL, 'New message from [XYZ Building Materials]: Delivery scheduled for tomorrow at 2 PM', '2025-09-21 10:06:54', 'sent'),
(45, '', '', '', 0, NULL, NULL, 'Delivery status updated by [ABC Hardware Supplies]: Package is out for delivery', '2025-09-21 10:06:54', 'sent'),
(46, '', 'system', 'management', 1, 43, NULL, 'Order #43 has been received by supplier abc', '2025-09-21 13:55:08', 'sent'),
(47, 'delivery_update', 'system', 'supplier', 8, 44, NULL, 'Delivery status updated to: In transit', '2025-09-21 15:04:05', 'sent'),
(48, '', 'system', 'management', 1, 44, NULL, 'Order #44 has been completed by supplier abc', '2025-09-21 15:04:32', 'sent'),
(49, 'delivery_update', 'system', 'supplier', 8, 45, NULL, 'Delivery status updated to: In transit', '2025-09-21 15:05:10', 'sent'),
(50, 'delivery_update', 'system', 'supplier', 8, 45, NULL, 'Delivery status updated to: Delivered', '2025-09-21 15:05:14', 'sent');

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
  `confirmation_status` enum('pending','confirmed','cancelled') DEFAULT 'pending',
  `confirmation_date` datetime DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`id`, `inventory_id`, `supplier_id`, `user_id`, `quantity`, `is_automated`, `order_date`, `confirmation_status`, `confirmation_date`, `unit_price`) VALUES
(25, 27, 8, 12, 50, 0, '2025-09-20 22:57:21', 'confirmed', '2025-09-21 01:49:48', 0.00),
(26, 25, 8, 12, 60, 0, '2025-09-20 23:00:28', 'confirmed', NULL, 0.00),
(27, 30, 8, 12, 50, 0, '2025-09-20 23:09:00', 'confirmed', NULL, 0.00),
(28, 27, 8, 12, 50, 0, '2025-09-20 23:13:34', 'confirmed', '2025-09-21 00:04:44', 0.00),
(29, 27, 8, 12, 10, 0, '2025-09-21 02:04:55', 'confirmed', '2025-09-21 02:17:04', 0.00),
(30, 25, 8, 12, 30, 0, '2025-09-21 02:41:53', 'confirmed', '2025-09-21 02:42:09', 0.00),
(31, 25, 8, 12, 10, 0, '2025-09-21 02:48:28', 'confirmed', '2025-09-21 02:48:33', 0.00),
(32, 25, 8, 12, 10, 0, '2025-09-21 02:55:49', 'confirmed', '2025-09-21 02:55:53', 0.00),
(33, 31, 8, 12, 2, 0, '2025-09-21 03:00:56', 'confirmed', '2025-09-21 03:01:03', 0.00),
(34, 31, 8, 12, 5, 0, '2025-09-21 03:03:30', 'confirmed', '2025-09-21 03:03:35', 0.00),
(37, 25, 8, 12, 20, 0, '2025-09-21 03:06:15', 'confirmed', NULL, 15.00),
(38, 25, 8, 12, 15, 0, '2025-09-21 03:08:29', 'confirmed', NULL, 12.00),
(39, 27, 8, 12, 10, 0, '2025-09-21 03:11:50', 'confirmed', NULL, 8.00),
(40, 25, 8, 12, 10, 0, '2025-09-21 03:15:00', 'cancelled', '2025-09-21 03:18:30', 0.00),
(41, 25, 8, 12, 10, 0, '2025-09-21 03:15:24', 'confirmed', '2025-09-21 03:15:40', 0.00),
(42, 31, 8, 12, 20, 0, '2025-09-21 03:18:53', 'confirmed', '2025-09-21 03:19:04', 0.00),
(43, 31, 8, 12, 10, 0, '2025-09-21 13:54:42', '', '2025-09-21 13:55:08', 0.00),
(44, 25, 8, 12, 10, 0, '2025-09-21 15:03:45', '', '2025-09-21 15:04:32', 0.00),
(45, 25, 8, 12, 10, 0, '2025-09-21 15:04:57', 'confirmed', '2025-09-21 15:05:07', 0.00);

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
(3, 25, 'cash', 25000.00, 'completed', '2025-09-20 22:57:21', ''),
(4, 26, 'cash', 12000.00, 'completed', '2025-09-20 23:00:28', ''),
(5, 27, 'cash', 5000.00, 'completed', '2025-09-20 23:09:00', ''),
(6, 28, 'cash', 25000.00, 'completed', '2025-09-20 23:13:34', ''),
(7, 29, 'cash', 5000.00, 'completed', '2025-09-21 02:04:55', ''),
(8, 30, 'cash', 6000.00, 'completed', '2025-09-21 02:41:53', ''),
(9, 31, 'cash', 2000.00, 'completed', '2025-09-21 02:48:28', ''),
(10, 32, 'cash', 2000.00, 'completed', '2025-09-21 02:55:49', ''),
(11, 33, 'cash', 200.00, 'completed', '2025-09-21 03:00:56', ''),
(12, 34, 'cash', 500.00, 'completed', '2025-09-21 03:03:30', ''),
(13, 40, 'cash', 2000.00, 'completed', '2025-09-21 03:15:00', ''),
(14, 41, 'cash', 2000.00, 'completed', '2025-09-21 03:15:24', ''),
(15, 42, 'cash', 2000.00, 'completed', '2025-09-21 03:18:53', ''),
(16, 43, 'cash', 1000.00, 'completed', '2025-09-21 13:54:42', ''),
(17, 44, 'cash', 2000.00, 'completed', '2025-09-21 15:03:45', ''),
(18, 45, 'cash', 2000.00, 'completed', '2025-09-21 15:04:57', '');

-- --------------------------------------------------------

--
-- Table structure for table `sales_transactions`
--

CREATE TABLE `sales_transactions` (
  `id` int(11) NOT NULL,
  `inventory_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `transaction_date` datetime DEFAULT current_timestamp(),
  `total_amount` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales_transactions`
--

INSERT INTO `sales_transactions` (`id`, `inventory_id`, `user_id`, `quantity`, `transaction_date`, `total_amount`) VALUES
(55, 14, 5, 3, '2025-05-18 18:35:32', 19500.00),
(61, 14, 5, 3, '2025-05-18 18:35:38', 19500.00),
(69, 25, 12, 102, '2025-09-21 02:41:34', 26520.00),
(70, 27, 12, 3, '2025-09-21 10:06:20', 1950.00),
(71, 27, 12, 3, '2025-09-21 10:06:22', 1950.00),
(72, 31, 12, 6, '2025-09-21 10:10:00', 780.00),
(73, 31, 12, 6, '2025-09-21 10:10:04', 780.00);

-- --------------------------------------------------------

--
-- Table structure for table `sms_logs`
--

CREATE TABLE `sms_logs` (
  `id` int(11) NOT NULL,
  `message_id` int(11) DEFAULT NULL,
  `recipient_phone` varchar(20) NOT NULL,
  `message_content` text NOT NULL,
  `status` enum('pending','sent','failed') DEFAULT 'pending',
  `sent_at` datetime DEFAULT current_timestamp(),
  `error_message` text DEFAULT NULL
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
  `status` enum('active','inactive') DEFAULT 'active',
  `payment_methods` varchar(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `name`, `contact_phone`, `email`, `username`, `password_hash`, `login_attempts`, `locked_until`, `last_login`, `address`, `status`, `payment_methods`) VALUES
(8, 'abc', '09123456789', 'abc@example.com', 'abc', '$2y$10$GU7kPa9eYB/9u150LVOeHeP/JApQDp5P3z7zFWQHCLZ3hMjvesH66', 0, NULL, '2025-09-21 16:04:50', NULL, 'active', '');

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
  `phone` varchar(20) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `role`, `email`, `phone`, `created_at`) VALUES
(12, 'admin', '$2y$10$mhS/wDf2m7FinqOtebdT4.XFkfnZI5rKo6yQQhvDDX2kZk/COMs3a', 'management', 'admin@gmail.com', '09942715542', '2025-09-20 21:25:16'),
(13, 'staff', '$2y$10$zdHzdAPZwZ8Nd0zU9rbujuHAVIt/0.NY4UQ3bH7mttzakzuJ2yjfu', 'staff', 'staff@gmail.com', '09942715542', '2025-09-20 21:25:37'),
(20, 'abc', '$2y$10$GU7kPa9eYB/9u150LVOeHeP/JApQDp5P3z7zFWQHCLZ3hMjvesH66', 'supplier', 'abc@example.com', '09123456789', '2025-09-20 21:32:24'),
(21, 'xyz', '$2y$10$n9XD1S3Jo9C25QxgRsaLEututehJHe8G.X9riNmon7mjjw1VGHcJ6', 'supplier', 'xyz@example.com', '09987654321', '2025-09-20 21:59:15');

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

--
-- Dumping data for table `user_sessions`
--

INSERT INTO `user_sessions` (`id`, `session_token`, `user_id`, `user_type`, `username`, `session_data`, `ip_address`, `user_agent`, `created_at`, `last_activity`, `expires_at`, `is_active`) VALUES
(1, '3d2f173c0ac499bbe016fc1866d91848593b3125b0e5f32b097c084d9e5deadf42eb9342893e743dd7703d95eb5845e4dc1e0abe0c1688db24d976fe25cbcb15', 999, '', 'Array', '[]', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.100.3 Chrome/132.0.6834.210 Electron/34.5.1 Safari/537.36', '2025-09-21 03:21:34', '2025-09-21 03:21:34', '2025-09-20 22:21:34', 0),
(2, '679863dbd0ce0d45449bd69314774805e5a0d96550e9ed861dc4496b96629a2ebf89cc37474d4affb974bc1c6f56aeefecd563e8a0b224d2b4d64bccb85fbbb3', 999, '', 'Array', '[]', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.100.3 Chrome/132.0.6834.210 Electron/34.5.1 Safari/537.36', '2025-09-21 03:21:34', '2025-09-21 03:21:34', '2025-09-20 22:21:34', 0),
(3, 'b7e08491cbbfbd8dfa97797a8728c5b73081c471eba1caf7c928fc7a2007cd895b8dbe66cef04fa20c05d3a45bcb8a6d9179329bd9fbcff5039af9beeeb02339', 999, '', 'Array', '[]', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Trae/1.100.3 Chrome/132.0.6834.210 Electron/34.5.1 Safari/537.36', '2025-09-21 03:21:34', '2025-09-21 03:21:34', '2025-09-20 22:21:34', 0);

--
-- Indexes for dumped tables
--

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
  ADD UNIQUE KEY `sku` (`sku`),
  ADD KEY `supplier_id` (`supplier_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `alert_id` (`alert_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `inventory_id` (`inventory_id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `user_id` (`user_id`);

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
-- Indexes for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `message_id` (`message_id`),
  ADD KEY `idx_status_sent` (`status`,`sent_at`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

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
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `alert_logs`
--
ALTER TABLE `alert_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `auth_logs`
--
ALTER TABLE `auth_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `chat_message_status`
--
ALTER TABLE `chat_message_status`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=121;

--
-- AUTO_INCREMENT for table `chat_typing_indicators`
--
ALTER TABLE `chat_typing_indicators`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=78;

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `sales_transactions`
--
ALTER TABLE `sales_transactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=74;

--
-- AUTO_INCREMENT for table `sms_logs`
--
ALTER TABLE `sms_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `user_sessions`
--
ALTER TABLE `user_sessions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

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
-- Constraints for table `inventory`
--
ALTER TABLE `inventory`
  ADD CONSTRAINT `inventory_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`alert_id`) REFERENCES `alert_logs` (`id`);

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`),
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `orders_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`);

--
-- Constraints for table `sales_transactions`
--
ALTER TABLE `sales_transactions`
  ADD CONSTRAINT `sales_transactions_ibfk_1` FOREIGN KEY (`inventory_id`) REFERENCES `inventory` (`id`),
  ADD CONSTRAINT `sales_transactions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `sms_logs`
--
ALTER TABLE `sms_logs`
  ADD CONSTRAINT `sms_logs_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
