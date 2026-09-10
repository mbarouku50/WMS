-- phpMyAdmin SQL Dump
-- version 5.2.1deb3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Sep 10, 2026 at 07:59 PM
-- Server version: 8.0.46-0ubuntu0.24.04.3
-- PHP Version: 8.3.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `wms_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `access_points`
--

CREATE TABLE `access_points` (
  `id` int UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `router_id` int UNSIGNED DEFAULT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `location` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mac_address` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ssid` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('online','offline','unknown','disabled','retired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `monitoring_source` enum('router','snmp','controller','vendor_api','manual') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `health` tinyint UNSIGNED NOT NULL DEFAULT '100',
  `connected_users` int UNSIGNED NOT NULL DEFAULT '0',
  `last_seen_at` datetime DEFAULT NULL,
  `last_status_change_at` datetime DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `alerts`
--

CREATE TABLE `alerts` (
  `id` bigint UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `type` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `severity` enum('info','warning','danger','critical') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `title` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `source_type` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint UNSIGNED DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `is_resolved` tinyint(1) NOT NULL DEFAULT '0',
  `resolved_by` int UNSIGNED DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `alerts`
--

INSERT INTO `alerts` (`id`, `provider_id`, `type`, `severity`, `title`, `message`, `source_type`, `source_id`, `is_read`, `is_resolved`, `resolved_by`, `resolved_at`, `created_at`) VALUES
(8, 2, 'payment_failed', 'warning', 'Payment could not be started', 'We could not reach the payment service. Please check your connection and try again. (reference WMS260909EJFLB)', 'payment', 3, 1, 0, NULL, NULL, '2026-09-09 22:52:37'),
(9, 2, 'payment_failed', 'warning', 'Payment could not be started', 'The payment request was rejected. Please check the amount and phone number. (Parameter buyer_email is invalid) (reference WMS260909JTT1X)', 'payment', 4, 1, 0, NULL, NULL, '2026-09-09 22:52:56'),
(20, 2, 'payment_failed', 'warning', 'Payment cancelled', 'Payment WMS260910LEGN9 cancelled. Cancelled by the customer on the portal before the prompt was approved.', 'payment', 7, 1, 0, NULL, NULL, '2026-09-10 06:39:07');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `actor_type` enum('user','customer','system','api') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `actor_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `action` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entity_id` bigint UNSIGNED DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `provider_id`, `user_id`, `actor_type`, `actor_name`, `action`, `entity_type`, `entity_id`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES
(63, 2, 2, 'user', 'Provider Administrator', 'login', 'user', 2, 'Signed in to the control centre', '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-09 22:48:37'),
(64, 2, 2, 'user', 'Provider Administrator', 'logout', 'user', 2, 'Signed out', '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-09 22:49:45'),
(65, NULL, 1, 'user', 'System Administrator', 'login', 'user', 1, 'Signed in to the control centre', '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-09 22:51:12'),
(66, NULL, 1, 'user', 'System Administrator', 'provider_mobile_money', 'provider', 2, 'Allowed FastNet Arusha to sell by mobile money', '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-09 22:52:11'),
(67, NULL, 1, 'user', 'System Administrator', 'billing_terms', 'provider', 2, 'Set the platform fee to TSh 10,000 every month, starting 09 Oct 2026', '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-09 22:52:11'),
(68, NULL, 1, 'user', 'System Administrator', 'provider_update', 'provider', 2, 'Updated provider \"FastNet Arusha\"', '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-09 22:52:11'),
(69, NULL, 1, 'user', 'System Administrator', 'logout', 'user', 1, 'Signed out', '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-09 22:52:17'),
(70, NULL, 1, 'user', 'System Administrator', 'login', 'user', 1, 'Signed in to the control centre', '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-09 23:18:28'),
(71, NULL, 1, 'user', 'System Administrator', 'logout', 'user', 1, 'Signed out', '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-09 23:21:38'),
(72, 2, NULL, 'customer', 'System', 'payment_start', 'payment', 5, 'Started payment WMS260909P0UY7 for daily package', '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-09 23:22:05'),
(73, NULL, 1, 'user', 'System Administrator', 'login', 'user', 1, 'Signed in to the control centre', '::1', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-09-09 23:23:23');

-- --------------------------------------------------------

--
-- Table structure for table `bandwidth_profiles`
--

CREATE TABLE `bandwidth_profiles` (
  `id` int UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `download_kbps` int UNSIGNED NOT NULL,
  `upload_kbps` int UNSIGNED NOT NULL,
  `burst_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `burst_download_kbps` int UNSIGNED DEFAULT NULL,
  `burst_upload_kbps` int UNSIGNED DEFAULT NULL,
  `burst_time` smallint UNSIGNED NOT NULL DEFAULT '8',
  `priority` tinyint UNSIGNED NOT NULL DEFAULT '8',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mikrotik_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `synced_at` datetime DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `bandwidth_profiles`
--

INSERT INTO `bandwidth_profiles` (`id`, `provider_id`, `name`, `download_kbps`, `upload_kbps`, `burst_enabled`, `burst_download_kbps`, `burst_upload_kbps`, `burst_time`, `priority`, `description`, `mikrotik_name`, `synced_at`, `status`, `created_at`, `updated_at`) VALUES
(1, 2, 'Standard 3M/1M', 3072, 1024, 0, NULL, NULL, 8, 6, 'Starter profile created with the provider account.', NULL, NULL, 'active', '2026-09-09 06:23:47', '2026-09-09 06:23:47');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `customer_code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(140) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `username` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','suspended','pending','blocked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `customer_type` enum('individual','business','hotspot','staff') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'individual',
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `provider_id`, `customer_code`, `full_name`, `phone`, `email`, `username`, `password_hash`, `status`, `customer_type`, `address`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES
(3, 2, 'CUS-00001', 'Hotspot customer 1250', '255716501250', NULL, NULL, NULL, 'active', 'hotspot', NULL, NULL, NULL, '2026-09-09 22:52:37', '2026-09-09 22:52:37'),
(4, 2, 'CUS-00002', 'Test', '255754000111', NULL, NULL, NULL, 'active', 'hotspot', NULL, NULL, NULL, '2026-09-10 06:38:51', '2026-09-10 06:38:51'),
(5, 2, 'CUS-00003', 'Test', '255754000222', NULL, NULL, NULL, 'active', 'hotspot', NULL, NULL, NULL, '2026-09-10 06:50:14', '2026-09-10 06:50:14'),
(6, 2, 'CUS-00004', 'Hotspot customer 0333', '255754000333', NULL, NULL, NULL, 'active', 'hotspot', NULL, NULL, NULL, '2026-09-10 06:51:30', '2026-09-10 06:51:30');

-- --------------------------------------------------------

--
-- Table structure for table `devices`
--

CREATE TABLE `devices` (
  `id` bigint UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `customer_id` int UNSIGNED DEFAULT NULL,
  `voucher_id` bigint UNSIGNED DEFAULT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_type` enum('phone','laptop','tablet','desktop','smart_tv','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `mac_address` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `router_id` int UNSIGNED DEFAULT NULL,
  `access_point_id` int UNSIGNED DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('active','idle','blocked') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `source` enum('live','demo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'demo',
  `first_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` bigint UNSIGNED NOT NULL,
  `identifier` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` enum('admin','customer') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'admin',
  `success` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`id`, `identifier`, `ip_address`, `scope`, `success`, `created_at`) VALUES
(29, 'provider@gmail.com', '::1', 'admin', 1, '2026-09-09 22:48:37'),
(30, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-09 22:51:12'),
(31, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-09 23:18:28'),
(32, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-09 23:23:23'),
(33, 'admin', '::1', 'admin', 1, '2026-09-10 00:12:02'),
(34, 'provider', '::1', 'admin', 1, '2026-09-10 00:12:02'),
(35, 'admin', '::1', 'admin', 1, '2026-09-10 05:51:14'),
(36, 'provider', '::1', 'admin', 1, '2026-09-10 05:51:14'),
(41, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-10 06:03:20'),
(42, 'provider@gmail.com', '::1', 'admin', 1, '2026-09-10 06:03:20'),
(43, 'admin', '::1', 'admin', 1, '2026-09-10 06:03:20'),
(44, 'provider', '::1', 'admin', 1, '2026-09-10 06:03:20'),
(45, 'admin@gmail.com', '::1', 'admin', 0, '2026-09-10 06:03:21'),
(46, 'provider@gmail.com', '::1', 'admin', 1, '2026-09-10 06:04:04'),
(47, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-10 06:07:27'),
(48, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-10 06:12:24'),
(49, 'provider@gmail.com', '::1', 'admin', 1, '2026-09-10 06:12:24'),
(50, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-10 11:07:54'),
(51, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-10 11:14:36'),
(52, 'admin@gmail.com', '::1', 'admin', 0, '2026-09-10 12:41:56'),
(53, 'admin@gmail.com', '::1', 'admin', 0, '2026-09-10 12:42:09'),
(54, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-10 12:42:16'),
(55, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-10 13:20:31'),
(56, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-10 20:47:57'),
(57, 'provider@gmail.com', '::1', 'admin', 1, '2026-09-10 20:50:14'),
(58, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-10 20:51:43'),
(59, 'provider@gmail.com', '::1', 'admin', 1, '2026-09-10 20:54:12'),
(60, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-10 20:56:09'),
(61, 'provider@gmail.com', '::1', 'admin', 0, '2026-09-10 20:56:54'),
(62, 'provider@gmail.com', '::1', 'admin', 1, '2026-09-10 20:57:04'),
(67, 'provider@gmail.com', '::1', 'admin', 1, '2026-09-10 21:23:29'),
(68, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-10 21:25:28'),
(69, 'provider@gmail.com', '::1', 'admin', 1, '2026-09-10 21:26:29'),
(75, 'provider@gmail.com', '::1', 'admin', 1, '2026-09-10 21:45:11'),
(76, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-10 21:45:31'),
(77, 'provider@gmail.com', '::1', 'admin', 1, '2026-09-10 21:46:20'),
(78, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-10 21:46:55'),
(79, 'provider@gmail.com', '::1', 'admin', 1, '2026-09-10 21:48:54'),
(80, 'provider@gmail.com', '::1', 'admin', 1, '2026-09-10 21:49:35'),
(81, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-10 21:52:51'),
(87, 'provider@gmail.com', '::1', 'admin', 1, '2026-09-10 22:07:29'),
(88, 'admin@gmail.com', '::1', 'admin', 0, '2026-09-10 22:08:06'),
(89, 'admin@gmail.com', '::1', 'admin', 1, '2026-09-10 22:08:12'),
(90, 'provider@gmail.com', '::1', 'admin', 1, '2026-09-10 22:08:53');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `title` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `link` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `packages`
--

CREATE TABLE `packages` (
  `id` int UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `duration_value` int UNSIGNED NOT NULL DEFAULT '1',
  `duration_unit` enum('minutes','hours','days','months') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hours',
  `data_limit_mb` bigint UNSIGNED DEFAULT NULL COMMENT 'NULL = unlimited data',
  `download_kbps` int UNSIGNED NOT NULL DEFAULT '2048',
  `upload_kbps` int UNSIGNED NOT NULL DEFAULT '1024',
  `device_limit` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `bandwidth_profile_id` int UNSIGNED DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` smallint NOT NULL DEFAULT '0',
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `packages`
--

INSERT INTO `packages` (`id`, `provider_id`, `name`, `code`, `price`, `duration_value`, `duration_unit`, `data_limit_mb`, `download_kbps`, `upload_kbps`, `device_limit`, `bandwidth_profile_id`, `description`, `is_featured`, `sort_order`, `status`, `created_at`, `updated_at`) VALUES
(1, 2, 'Quick Hour', 'FA-QHR', 500.00, 1, 'hours', NULL, 3072, 1024, 1, 1, 'Starter package - edit or remove it once you have set your own prices.', 0, 1, 'active', '2026-09-09 06:23:47', '2026-09-10 22:26:26'),
(2, 2, 'Daily 5GB', 'FA-D5GB', 1000.00, 24, 'hours', 5120, 3072, 1024, 1, 1, 'Starter package - edit or remove it once you have set your own prices.', 1, 2, 'active', '2026-09-09 06:23:47', '2026-09-09 06:23:47'),
(3, 2, 'Daily Unlimited', 'FA-DUNL', 2000.00, 24, 'hours', NULL, 3072, 1024, 2, 1, 'Starter package - edit or remove it once you have set your own prices.', 0, 3, 'active', '2026-09-09 06:23:47', '2026-09-09 06:23:47'),
(4, 2, 'daily package', 'UNLMT', 1000.00, 24, 'hours', NULL, 5120, 2048, 1, NULL, NULL, 0, 0, 'active', '2026-09-09 06:35:37', '2026-09-09 06:35:37');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` bigint UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `transaction_ref` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` int UNSIGNED DEFAULT NULL,
  `package_id` int UNSIGNED DEFAULT NULL,
  `voucher_id` bigint UNSIGNED DEFAULT NULL,
  `subscription_id` bigint UNSIGNED DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TZS',
  `method` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'mobile_money',
  `provider` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'demo',
  `provider_ref` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Provider order id',
  `provider_txn_id` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `channel` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payer_name` varchar(140) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payer_phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payer_email` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','successful','failed','cancelled','refunded') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `failure_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `provider_id`, `transaction_ref`, `customer_id`, `package_id`, `voucher_id`, `subscription_id`, `amount`, `currency`, `method`, `provider`, `provider_ref`, `provider_txn_id`, `channel`, `payer_name`, `payer_phone`, `payer_email`, `status`, `failure_reason`, `created_at`, `completed_at`, `updated_at`) VALUES
(3, 2, 'WMS260909EJFLB', 3, 4, NULL, NULL, 1000.00, 'TZS', 'mobile_money', 'sonicpesa', NULL, NULL, NULL, NULL, '255716501250', NULL, 'failed', 'We could not reach the payment service. Please check your connection and try again.', '2026-09-09 22:52:37', NULL, '2026-09-09 22:52:37'),
(4, 2, 'WMS260909JTT1X', 3, 4, NULL, NULL, 1000.00, 'TZS', 'mobile_money', 'sonicpesa', NULL, NULL, NULL, NULL, '255716501250', NULL, 'failed', 'The payment request was rejected. Please check the amount and phone number. (Parameter buyer_email is invalid)', '2026-09-09 22:52:53', NULL, '2026-09-09 22:52:56'),
(5, 2, 'WMS260909P0UY7', 3, 4, NULL, NULL, 1000.00, 'TZS', 'mobile_money', 'sonicpesa', 'sp_6aa1bfe8a750b', NULL, NULL, NULL, '255716501250', NULL, 'pending', NULL, '2026-09-09 23:21:59', NULL, '2026-09-09 23:22:05'),
(6, 2, 'WMS260910W7YQB', 3, 4, NULL, NULL, 1000.00, 'TZS', 'mobile_money', 'sonicpesa', 'sp_6aa21f90a725e', NULL, NULL, NULL, '255716501250', NULL, 'pending', NULL, '2026-09-10 06:10:07', NULL, '2026-09-10 06:10:16'),
(7, 2, 'WMS260910LEGN9', 4, 4, NULL, NULL, 1000.00, 'TZS', 'mobile_money', 'sonicpesa', 'sp_6aa2264d49732', NULL, NULL, 'Test', '255754000111', NULL, 'cancelled', 'Cancelled by the customer on the portal before the prompt was approved.', '2026-09-10 06:38:51', NULL, '2026-09-10 06:39:07'),
(8, 2, 'WMS260910Q7TZB', 5, 4, NULL, NULL, 1000.00, 'TZS', 'mobile_money', 'sonicpesa', 'sp_6aa228f748360', NULL, NULL, 'Test', '255754000222', NULL, 'pending', NULL, '2026-09-10 06:50:14', NULL, '2026-09-10 06:50:20'),
(9, 2, 'WMS260910MKNJ0', 6, 4, NULL, NULL, 1000.00, 'TZS', 'mobile_money', 'sonicpesa', 'sp_6aa229436ede9', NULL, NULL, NULL, '255754000333', NULL, 'pending', NULL, '2026-09-10 06:51:30', NULL, '2026-09-10 06:51:36');

-- --------------------------------------------------------

--
-- Table structure for table `permissions`
--

CREATE TABLE `permissions` (
  `id` int UNSIGNED NOT NULL,
  `slug` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` enum('platform','provider') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'provider',
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'General'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `permissions`
--

INSERT INTO `permissions` (`id`, `slug`, `scope`, `name`, `group_name`) VALUES
(1, 'view_dashboard', 'provider', 'View dashboard', 'Dashboard'),
(2, 'manage_customers', 'provider', 'Manage customers', 'Customers'),
(3, 'manage_packages', 'provider', 'Manage packages', 'Sales'),
(4, 'manage_vouchers', 'provider', 'Manage vouchers', 'Sales'),
(5, 'manage_payments', 'provider', 'Manage payments', 'Sales'),
(6, 'manage_routers', 'provider', 'Manage routers & access points', 'Network'),
(7, 'manage_devices', 'provider', 'Manage devices', 'Network'),
(8, 'manage_sessions', 'provider', 'Manage sessions', 'Network'),
(9, 'manage_reports', 'provider', 'View & export reports', 'Reports'),
(10, 'manage_alerts', 'provider', 'Manage alerts', 'Reports'),
(11, 'view_audit_logs', 'provider', 'View audit logs', 'Administration'),
(12, 'manage_staff', 'platform', 'Manage staff & roles', 'Administration'),
(13, 'manage_settings', 'platform', 'Manage settings', 'Administration'),
(14, 'manage_providers', 'platform', 'Manage providers', 'Platform'),
(15, 'view_global_reports', 'platform', 'View platform-wide reports', 'Platform'),
(16, 'manage_all_routers', 'platform', 'Manage every router', 'Platform'),
(17, 'manage_all_users', 'platform', 'Manage every user account', 'Platform'),
(18, 'impersonate_provider', 'platform', 'View the system as a provider', 'Platform'),
(19, 'manage_platform_settings', 'platform', 'Manage platform settings', 'Platform'),
(20, 'manage_provider_users', 'provider', 'Manage provider staff', 'Administration'),
(21, 'manage_provider_settings', 'provider', 'Manage provider settings', 'Administration'),
(22, 'view_wallet', 'provider', 'View the provider wallet', 'Money'),
(23, 'request_withdrawal', 'provider', 'Request a withdrawal', 'Money'),
(24, 'manage_billing', 'platform', 'Manage provider fees and payouts', 'Platform');

-- --------------------------------------------------------

--
-- Table structure for table `platform_invoices`
--

CREATE TABLE `platform_invoices` (
  `id` bigint UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED NOT NULL,
  `invoice_number` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TZS',
  `due_on` date NOT NULL,
  `status` enum('unpaid','paid','waived','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `paid_at` datetime DEFAULT NULL,
  `paid_from` enum('wallet','manual','waived','mobile_money') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pay_reference` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Our reference for the mobile money charge',
  `pay_provider` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Gateway that handled it',
  `pay_provider_ref` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Gateway order id',
  `pay_phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Number the USSD prompt went to',
  `pay_status` enum('none','pending','successful','failed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `pay_started_at` datetime DEFAULT NULL,
  `pay_message` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Last word from the gateway',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `platform_withdrawals`
--

CREATE TABLE `platform_withdrawals` (
  `id` bigint UNSIGNED NOT NULL,
  `reference` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `fee` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'What SonicPesa charged to send it',
  `net_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TZS',
  `method` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_number` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_name` varchar(140) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','processing','completed','failed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `provider_ref` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'SonicPesa withdrawal_id',
  `failure_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requested_by` int UNSIGNED DEFAULT NULL,
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_response` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `providers`
--

CREATE TABLE `providers` (
  `id` int UNSIGNED NOT NULL,
  `provider_code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `business_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `business_type` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hotspot',
  `owner_name` varchar(140) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Tanzania',
  `logo` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('active','suspended','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `mobile_money_enabled` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 = vouchers only, 1 = customers may pay by mobile money',
  `mobile_money_changed_at` datetime DEFAULT NULL,
  `timezone` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Africa/Dar_es_Salaam',
  `currency` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TSh',
  `currency_code` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TZS',
  `wallet_balance` decimal(14,2) NOT NULL DEFAULT '0.00' COMMENT 'Available to withdraw',
  `wallet_held` decimal(14,2) NOT NULL DEFAULT '0.00' COMMENT 'Reserved against pending withdrawals',
  `lifetime_earned` decimal(14,2) NOT NULL DEFAULT '0.00',
  `lifetime_withdrawn` decimal(14,2) NOT NULL DEFAULT '0.00',
  `platform_fee_amount` decimal(12,2) NOT NULL DEFAULT '10000.00' COMMENT 'Charged each cycle',
  `platform_fee_cycle_months` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT '1 = monthly',
  `billing_starts_on` date DEFAULT NULL COMMENT 'First day the fee applies - the agreed grace period ends here',
  `billing_next_due_on` date DEFAULT NULL COMMENT 'When the next invoice will be raised',
  `billing_status` enum('grace','current','due','overdue','exempt') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'grace',
  `billing_locked_at` datetime DEFAULT NULL COMMENT 'Account locked to the pay screen over an unpaid platform fee',
  `service_suspended_at` datetime DEFAULT NULL COMMENT 'Customer-facing service stopped over an unpaid platform fee',
  `billing_grace_until` date DEFAULT NULL COMMENT 'Platform-granted extension; no lock before this date',
  `payout_method` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'M-Pesa, Tigo Pesa, CRDB Bank, ...',
  `payout_account_number` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payout_account_name` varchar(140) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plan` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subscription_status` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subscription_started_at` datetime DEFAULT NULL,
  `subscription_expires_at` datetime DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `providers`
--

INSERT INTO `providers` (`id`, `provider_code`, `business_name`, `business_type`, `owner_name`, `phone`, `email`, `address`, `city`, `region`, `country`, `logo`, `description`, `status`, `mobile_money_enabled`, `mobile_money_changed_at`, `timezone`, `currency`, `currency_code`, `wallet_balance`, `wallet_held`, `lifetime_earned`, `lifetime_withdrawn`, `platform_fee_amount`, `platform_fee_cycle_months`, `billing_starts_on`, `billing_next_due_on`, `billing_status`, `billing_locked_at`, `service_suspended_at`, `billing_grace_until`, `payout_method`, `payout_account_number`, `payout_account_name`, `plan`, `subscription_status`, `subscription_started_at`, `subscription_expires_at`, `notes`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'PRV-0001', 'Mbaruku WiFi', 'hotspot', 'Mbaruku', '0754000000', 'owner@mbarukuwifi.co.tz', NULL, 'Dar es Salaam', 'Dar es Salaam', 'Tanzania', NULL, 'Hotspot operator', 'active', 0, NULL, 'Africa/Dar_es_Salaam', 'TSh', 'TZS', 0.00, 0.00, 0.00, 0.00, 10000.00, 1, '2026-10-09', '2026-10-09', 'grace', NULL, NULL, NULL, 'M-Pesa', '0754000000', 'Mbaruku', NULL, NULL, NULL, NULL, NULL, NULL, '2026-09-09 06:22:24', '2026-09-09 22:41:30'),
(2, 'PRV-0002', 'FastNet Arusha', 'isp', 'Neema Shirima', '0713200200', 'hello@fastnet.co.tz', NULL, 'Arusha', NULL, 'Tanzania', NULL, NULL, 'active', 0, '2026-09-10 21:55:38', 'Africa/Dar_es_Salaam', 'TSh', 'TZS', 0.00, 0.00, 0.00, 0.00, 10000.00, 1, '2026-10-01', '2026-10-01', 'grace', NULL, NULL, NULL, 'M-Pesa', '0712345678', 'ZZ', NULL, NULL, NULL, NULL, NULL, 1, '2026-09-09 06:23:47', '2026-09-10 22:30:03');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int UNSIGNED NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `slug` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` enum('platform','provider') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'provider',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `slug`, `scope`, `description`, `is_system`, `created_at`) VALUES
(1, 'Super Admin', 'super_admin', 'platform', 'Platform owner. Full, unrestricted access to every provider.', 1, '2026-09-09 06:22:21'),
(2, 'Network Administrator', 'network_admin', 'provider', 'Routers, access points, sessions, devices and bandwidth.', 1, '2026-09-09 06:22:21'),
(3, 'Sales Administrator', 'sales_admin', 'provider', 'Packages, vouchers, customers, payments and revenue reports.', 1, '2026-09-09 06:22:21'),
(4, 'Support', 'support', 'provider', 'Read-mostly access for helping customers get online.', 1, '2026-09-09 06:22:21'),
(5, 'Provider Administrator', 'provider_admin', 'provider', 'Runs one Wi-Fi provider: customers, sales, network and their own staff.', 1, '2026-09-09 06:22:22');

-- --------------------------------------------------------

--
-- Table structure for table `role_permissions`
--

CREATE TABLE `role_permissions` (
  `role_id` int UNSIGNED NOT NULL,
  `permission_id` int UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `role_permissions`
--

INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES
(1, 1),
(2, 1),
(3, 1),
(4, 1),
(5, 1),
(1, 2),
(2, 2),
(3, 2),
(4, 2),
(5, 2),
(1, 3),
(3, 3),
(5, 3),
(1, 4),
(3, 4),
(4, 4),
(5, 4),
(1, 5),
(3, 5),
(5, 5),
(1, 6),
(2, 6),
(5, 6),
(1, 7),
(2, 7),
(5, 7),
(1, 8),
(2, 8),
(4, 8),
(5, 8),
(1, 9),
(2, 9),
(3, 9),
(5, 9),
(1, 10),
(2, 10),
(4, 10),
(5, 10),
(1, 11),
(5, 11),
(1, 12),
(1, 13),
(1, 14),
(1, 15),
(1, 16),
(1, 17),
(1, 18),
(1, 19),
(1, 20),
(5, 20),
(1, 21),
(5, 21),
(1, 22),
(3, 22),
(5, 22),
(1, 23),
(5, 23),
(1, 24);

-- --------------------------------------------------------

--
-- Table structure for table `routers`
--

CREATE TABLE `routers` (
  `id` int UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_port` smallint UNSIGNED NOT NULL DEFAULT '8728',
  `use_tls` tinyint(1) NOT NULL DEFAULT '0',
  `api_username` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_password` varbinary(512) DEFAULT NULL,
  `hotspot_server` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hotspot_profile` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `default_user_profile` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `routeros_version` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `identity` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `board` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mode` enum('live','demo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'demo',
  `status` enum('online','offline','unknown','degraded','disabled','retired') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unknown',
  `cpu_load` tinyint UNSIGNED DEFAULT NULL,
  `memory_used_pct` tinyint UNSIGNED DEFAULT NULL,
  `uptime` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active_users` int UNSIGNED NOT NULL DEFAULT '0',
  `last_seen_at` datetime DEFAULT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `failed_checks` smallint UNSIGNED NOT NULL DEFAULT '0',
  `last_error` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

CREATE TABLE `sessions` (
  `id` bigint UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `customer_id` int UNSIGNED DEFAULT NULL,
  `voucher_id` bigint UNSIGNED DEFAULT NULL,
  `subscription_id` bigint UNSIGNED DEFAULT NULL,
  `device_id` bigint UNSIGNED DEFAULT NULL,
  `username` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mac_address` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `router_id` int UNSIGNED DEFAULT NULL,
  `access_point_id` int UNSIGNED DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at` datetime DEFAULT NULL,
  `duration_seconds` int UNSIGNED NOT NULL DEFAULT '0',
  `download_bytes` bigint UNSIGNED NOT NULL DEFAULT '0',
  `upload_bytes` bigint UNSIGNED NOT NULL DEFAULT '0',
  `status` enum('active','closed','blocked','disconnected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `source` enum('live','demo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'demo',
  `terminate_cause` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED NOT NULL DEFAULT '0',
  `setting_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text COLLATE utf8mb4_unicode_ci,
  `setting_group` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `value_type` enum('string','int','bool','json','secret') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'string',
  `label` varchar(140) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `provider_id`, `setting_key`, `setting_value`, `setting_group`, `value_type`, `label`, `updated_at`) VALUES
(1, 0, 'app_name', 'M-FastNet', 'general', 'string', NULL, '2026-09-10 13:21:09'),
(2, 0, 'platform_name', 'WMS', 'general', 'string', NULL, '2026-09-09 06:23:02'),
(3, 0, 'company_name', 'MFS Networks', 'general', 'string', NULL, '2026-09-09 07:05:40'),
(4, 0, 'company_tagline', 'Connectivity you can account for', 'general', 'string', NULL, '2026-09-09 06:23:02'),
(5, 0, 'support_email', 'admin@localhost', 'general', 'string', NULL, '2026-09-09 06:23:02'),
(6, 0, 'timezone', 'Africa/Dar_es_Salaam', 'general', 'string', NULL, '2026-09-09 06:23:02'),
(7, 0, 'currency', 'TSh', 'general', 'string', NULL, '2026-09-09 06:23:02'),
(8, 0, 'currency_code', 'TZS', 'general', 'string', NULL, '2026-09-09 06:23:02'),
(9, 0, 'demo_mode', '0', 'general', 'bool', NULL, '2026-09-10 06:12:10'),
(10, 0, 'records_per_page', '20', 'general', 'int', NULL, '2026-09-09 06:23:02'),
(11, 0, 'voucher_prefix', 'WMS', 'voucher', 'string', NULL, '2026-09-09 06:23:02'),
(12, 0, 'voucher_code_length', '8', 'voucher', 'int', NULL, '2026-09-09 06:23:02'),
(13, 0, 'voucher_charset', 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', 'voucher', 'string', NULL, '2026-09-09 06:23:02'),
(14, 0, 'voucher_validity_days', '90', 'voucher', 'int', NULL, '2026-09-09 06:23:02'),
(15, 0, 'voucher_print_note', 'Connect to the Wi-Fi network, open any web page and enter this code.', 'voucher', 'string', NULL, '2026-09-09 06:23:02'),
(16, 0, 'portal_ssid', 'WMS-Hotspot', 'network', 'string', NULL, '2026-09-09 06:23:02'),
(17, 0, 'portal_welcome', 'Fast, fair and reliable Wi-Fi.', 'network', 'string', NULL, '2026-09-09 06:23:02'),
(18, 0, 'router_poll_seconds', '60', 'network', 'int', NULL, '2026-09-09 06:23:02'),
(19, 0, 'router_timeout_seconds', '4', 'network', 'int', NULL, '2026-09-10 05:50:30'),
(20, 0, 'session_idle_timeout', '900', 'network', 'int', NULL, '2026-09-09 06:23:02'),
(21, 0, 'payment_provider', 'sonicpesa', 'payment', 'string', NULL, '2026-09-09 22:41:30'),
(22, 0, 'sonicpesa_base_url', 'https://api.sonicpesa.com/api/v1', 'payment', 'string', NULL, '2026-09-10 21:41:24'),
(23, 0, 'sonicpesa_api_key', 'CHANGE_ME_SONICPESA_API_KEY', 'payment', 'secret', NULL, '2026-09-09 22:37:39'),
(24, 0, 'sonicpesa_api_secret', 'CHANGE_ME_SONICPESA_API_SECRET', 'payment', 'secret', NULL, '2026-09-09 22:37:39'),
(25, 0, 'sonicpesa_use_simple', '0', 'payment', 'bool', NULL, '2026-09-09 06:23:02'),
(26, 0, 'payment_currency', 'TZS', 'payment', 'string', NULL, '2026-09-09 06:23:02'),
(27, 0, 'alert_router_offline', '1', 'notification', 'bool', NULL, '2026-09-09 06:23:02'),
(28, 0, 'alert_payment_failed', '1', 'notification', 'bool', NULL, '2026-09-09 06:23:02'),
(29, 0, 'alert_high_usage_gb', '8', 'notification', 'int', NULL, '2026-09-09 06:23:02'),
(30, 1, 'app_name', 'Mbaruku WiFi', 'general', 'string', NULL, '2026-09-09 06:23:47'),
(31, 1, 'company_name', 'Mbaruku WiFi', 'general', 'string', NULL, '2026-09-09 06:23:47'),
(32, 1, 'currency', 'TSh', 'general', 'string', NULL, '2026-09-09 06:23:47'),
(33, 1, 'currency_code', 'TZS', 'general', 'string', NULL, '2026-09-09 06:23:47'),
(34, 2, 'app_name', 'FastNet Arusha', 'general', 'string', NULL, '2026-09-09 06:23:47'),
(35, 2, 'company_name', 'FastNet Arusha', 'general', 'string', NULL, '2026-09-09 06:23:47'),
(36, 2, 'currency', 'TSh', 'general', 'string', NULL, '2026-09-09 06:23:47'),
(37, 2, 'currency_code', 'TZS', 'general', 'string', NULL, '2026-09-09 06:23:47'),
(38, 2, 'timezone', 'Africa/Dar_es_Salaam', 'general', 'string', NULL, '2026-09-09 06:23:47'),
(39, 2, 'support_phone', '0713200200', 'general', 'string', NULL, '2026-09-09 06:23:47'),
(40, 2, 'support_email', 'hello@fastnet.co.tz', 'general', 'string', NULL, '2026-09-09 06:23:47'),
(41, 0, 'support_phone', '0716501250', 'general', 'string', NULL, '2026-09-09 07:05:40'),
(42, 0, 'platform_fee_default', '10000', 'billing', 'string', 'Default platform fee per cycle', '2026-09-09 07:20:12'),
(43, 0, 'platform_fee_cycle_months', '1', 'billing', 'int', 'Default billing cycle in months', '2026-09-09 07:20:12'),
(44, 0, 'platform_fee_grace_months', '1', 'billing', 'int', 'Default months before a new provider starts paying', '2026-09-09 07:20:12'),
(45, 0, 'platform_fee_due_days', '7', 'billing', 'int', 'Days a provider has to settle an invoice', '2026-09-09 07:20:12'),
(46, 0, 'withdrawal_minimum', '5000', 'billing', 'string', 'Smallest withdrawal a provider may request', '2026-09-09 07:20:12'),
(47, 0, 'withdrawal_requires_approval', '1', 'billing', 'bool', 'Platform must approve withdrawals before payout', '2026-09-09 07:20:12'),
(48, 0, 'auto_charge_fee_from_wallet', '1', 'billing', 'bool', 'Take the platform fee from the wallet automatically', '2026-09-09 07:20:12'),
(76, 0, 'cron_token', 'CHANGE_ME_CRON_TOKEN', 'general', 'secret', NULL, '2026-09-10 00:17:02'),
(77, 0, 'platform_fee_enforce', '1', 'billing', 'bool', 'Lock a provider out of their account until the platform fee is paid', '2026-09-10 21:12:37'),
(78, 0, 'platform_fee_lock_after_days', '0', 'billing', 'int', 'Days after the invoice due date before the account is locked (0 = on the due date)', '2026-09-10 21:12:37'),
(79, 0, 'platform_fee_stop_service', '1', 'billing', 'bool', 'Stop the provider\'s customer service while the fee is unpaid', '2026-09-10 21:12:37'),
(80, 0, 'platform_fee_stop_service_after_days', '0', 'billing', 'int', 'Extra days after the lock before customer service stops', '2026-09-10 21:12:37'),
(81, 0, 'platform_fee_warn_days', '3', 'billing', 'int', 'Days before the deadline to remind the provider and send them to the pay screen', '2026-09-10 21:33:32'),
(82, 0, 'platform_fee_pay_by_mobile', '1', 'billing', 'bool', 'Let a provider settle the fee straight from their phone', '2026-09-10 21:12:37'),
(83, 0, 'platform_withdrawal_minimum', '5000', 'billing', 'string', 'Smallest amount the platform owner may withdraw', '2026-09-10 21:33:14');

-- --------------------------------------------------------

--
-- Table structure for table `subscriptions`
--

CREATE TABLE `subscriptions` (
  `id` bigint UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `customer_id` int UNSIGNED DEFAULT NULL,
  `package_id` int UNSIGNED NOT NULL,
  `voucher_id` bigint UNSIGNED DEFAULT NULL,
  `payment_id` bigint UNSIGNED DEFAULT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `data_limit_mb` bigint UNSIGNED DEFAULT NULL,
  `data_used_mb` bigint UNSIGNED NOT NULL DEFAULT '0',
  `device_limit` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `status` enum('active','expired','exhausted','suspended','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` bigint UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `payment_id` bigint UNSIGNED DEFAULT NULL,
  `type` enum('charge','callback','status_check','refund','payout') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'charge',
  `provider` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'demo',
  `provider_ref` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `message` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `raw_response` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `provider_id`, `payment_id`, `type`, `provider`, `provider_ref`, `amount`, `status`, `message`, `raw_response`, `created_at`) VALUES
(5, NULL, 3, 'charge', 'sonicpesa', NULL, 1000.00, 'failed', 'We could not reach the payment service. Please check your connection and try again.', 'Could not resolve host: api.sonicpesa.com', '2026-09-09 22:52:37'),
(6, NULL, 4, 'charge', 'sonicpesa', NULL, 1000.00, 'failed', 'The payment request was rejected. Please check the amount and phone number. (Parameter buyer_email is invalid)', '{\"status\":\"error\",\"message\":\"Parameter buyer_email is invalid\",\"resultcode\":\"400\",\"data\":{\"reference\":\"S20716393077\",\"resultcode\":\"400\",\"result\":\"FAIL\",\"message\":\"Parameter buyer_email is invalid\",\"data\":[]}}', '2026-09-09 22:52:56'),
(7, NULL, 5, 'charge', 'sonicpesa', 'sp_6aa1bfe8a750b', 1000.00, 'pending', 'Check your phone and enter your mobile money PIN to approve the payment.', '{\"status\":\"success\",\"message\":\"Payment order created successfully! Push USSD sent to your phone.\",\"data\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"reference\":\"S20716422576\",\"amount\":1000,\"currency\":\"TZS\",\"payment_status\":\"PENDING\",\"status\":\"PENDING\",\"creation_date\":\"2026-09-09 23:22:52\",\"transid\":null,\"channel\":null,\"msisdn\":\"255716501250\",\"order_status_data\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"creation_date\":\"2026-09-09 23:22:52\",\"amount\":\"1000\",\"payment_status\":\"PENDING\",\"transid\":null,\"channel\":null,\"reference\":null,\"msisdn\":null}}}', '2026-09-09 23:22:05'),
(8, NULL, 5, 'status_check', 'sonicpesa', 'sp_6aa1bfe8a750b', 1000.00, 'pending', 'Order status retrieved successfully', '{\"status\":\"success\",\"message\":\"Order status retrieved successfully\",\"data\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"payment_status\":\"PENDING\",\"amount\":1000,\"currency\":\"TZS\",\"phone\":\"255716501250\",\"transid\":null,\"reference\":\"S20716422576\",\"channel\":null,\"msisdn\":\"255716501250\",\"created_at\":\"2026-09-09T20:22:05.000000Z\",\"cached\":true},\"transaction\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"status\":\"PENDING\",\"amount\":\"1000.00\",\"buyer_email\":\"hello@fastnet.co.tz\",\"buyer_name\":\"WMS customer\"}}', '2026-09-09 23:22:16'),
(9, NULL, 5, 'status_check', 'sonicpesa', 'sp_6aa1bfe8a750b', 1000.00, 'pending', 'Order status retrieved successfully', '{\"status\":\"success\",\"message\":\"Order status retrieved successfully\",\"data\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"payment_status\":\"PENDING\",\"amount\":1000,\"currency\":\"TZS\",\"phone\":\"255716501250\",\"transid\":null,\"reference\":\"S20716422576\",\"channel\":null,\"msisdn\":\"255716501250\",\"created_at\":\"2026-09-09T20:22:05.000000Z\",\"cached\":true},\"transaction\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"status\":\"PENDING\",\"amount\":\"1000.00\",\"buyer_email\":\"hello@fastnet.co.tz\",\"buyer_name\":\"WMS customer\"}}', '2026-09-09 23:22:17'),
(10, NULL, 5, 'status_check', 'sonicpesa', 'sp_6aa1bfe8a750b', 1000.00, 'pending', 'Order status retrieved successfully', '{\"status\":\"success\",\"message\":\"Order status retrieved successfully\",\"data\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"payment_status\":\"PENDING\",\"amount\":1000,\"currency\":\"TZS\",\"phone\":\"255716501250\",\"transid\":null,\"reference\":\"S20716422576\",\"channel\":null,\"msisdn\":\"255716501250\",\"created_at\":\"2026-09-09T20:22:05.000000Z\",\"cached\":true},\"transaction\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"status\":\"PENDING\",\"amount\":\"1000.00\",\"buyer_email\":\"hello@fastnet.co.tz\",\"buyer_name\":\"WMS customer\"}}', '2026-09-09 23:22:19'),
(11, NULL, 5, 'status_check', 'sonicpesa', 'sp_6aa1bfe8a750b', 1000.00, 'pending', 'Order status retrieved successfully', '{\"status\":\"success\",\"message\":\"Order status retrieved successfully\",\"data\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"payment_status\":\"PENDING\",\"amount\":1000,\"currency\":\"TZS\",\"phone\":\"255716501250\",\"transid\":null,\"reference\":\"S20716422576\",\"channel\":null,\"msisdn\":\"255716501250\",\"created_at\":\"2026-09-09T20:22:05.000000Z\",\"cached\":true},\"transaction\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"status\":\"PENDING\",\"amount\":\"1000.00\",\"buyer_email\":\"hello@fastnet.co.tz\",\"buyer_name\":\"WMS customer\"}}', '2026-09-10 00:15:27'),
(12, NULL, 5, 'status_check', 'sonicpesa', 'sp_6aa1bfe8a750b', 1000.00, 'pending', 'Order status retrieved successfully', '{\"status\":\"success\",\"message\":\"Order status retrieved successfully\",\"data\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"payment_status\":\"PENDING\",\"amount\":1000,\"currency\":\"TZS\",\"phone\":\"255716501250\",\"transid\":null,\"reference\":\"S20716422576\",\"channel\":null,\"msisdn\":\"255716501250\",\"created_at\":\"2026-09-09T20:22:05.000000Z\",\"cached\":true},\"transaction\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"status\":\"PENDING\",\"amount\":\"1000.00\",\"buyer_email\":\"hello@fastnet.co.tz\",\"buyer_name\":\"WMS customer\"}}', '2026-09-10 00:15:40'),
(13, NULL, 5, 'status_check', 'sonicpesa', 'sp_6aa1bfe8a750b', 1000.00, 'pending', 'Order status retrieved successfully', '{\"status\":\"success\",\"message\":\"Order status retrieved successfully\",\"data\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"payment_status\":\"PENDING\",\"amount\":1000,\"currency\":\"TZS\",\"phone\":\"255716501250\",\"transid\":null,\"reference\":\"S20716422576\",\"channel\":null,\"msisdn\":\"255716501250\",\"created_at\":\"2026-09-09T20:22:05.000000Z\",\"cached\":true},\"transaction\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"status\":\"PENDING\",\"amount\":\"1000.00\",\"buyer_email\":\"hello@fastnet.co.tz\",\"buyer_name\":\"WMS customer\"}}', '2026-09-10 00:16:46'),
(14, NULL, 5, 'status_check', 'sonicpesa', 'sp_6aa1bfe8a750b', 1000.00, 'pending', 'Order status retrieved successfully', '{\"status\":\"success\",\"message\":\"Order status retrieved successfully\",\"data\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"payment_status\":\"PENDING\",\"amount\":1000,\"currency\":\"TZS\",\"phone\":\"255716501250\",\"transid\":null,\"reference\":\"S20716422576\",\"channel\":null,\"msisdn\":\"255716501250\",\"created_at\":\"2026-09-09T20:22:05.000000Z\",\"cached\":true},\"transaction\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"status\":\"PENDING\",\"amount\":\"1000.00\",\"buyer_email\":\"hello@fastnet.co.tz\",\"buyer_name\":\"WMS customer\"}}', '2026-09-10 05:53:02'),
(15, NULL, 6, 'charge', 'sonicpesa', 'sp_6aa21f90a725e', 1000.00, 'pending', 'Check your phone and enter your mobile money PIN to approve the payment.', '{\"status\":\"success\",\"message\":\"Payment order created successfully! Push USSD sent to your phone.\",\"data\":{\"order_id\":\"sp_6aa21f90a725e\",\"reference\":\"S20716830696\",\"amount\":1000,\"currency\":\"TZS\",\"payment_status\":\"PENDING\",\"status\":\"PENDING\",\"creation_date\":\"2026-09-10 06:11:00\",\"transid\":null,\"channel\":null,\"msisdn\":\"255716501250\",\"order_status_data\":{\"order_id\":\"sp_6aa21f90a725e\",\"creation_date\":\"2026-09-10 06:11:00\",\"amount\":\"1000\",\"payment_status\":\"PENDING\",\"transid\":null,\"channel\":null,\"reference\":null,\"msisdn\":null}}}', '2026-09-10 06:10:16'),
(16, NULL, 6, 'status_check', 'sonicpesa', 'sp_6aa21f90a725e', 1000.00, 'pending', 'Order status retrieved successfully', '{\"status\":\"success\",\"message\":\"Order status retrieved successfully\",\"data\":{\"order_id\":\"sp_6aa21f90a725e\",\"payment_status\":\"PENDING\",\"amount\":1000,\"currency\":\"TZS\",\"phone\":\"255716501250\",\"transid\":null,\"reference\":\"S20716830696\",\"channel\":null,\"msisdn\":\"255716501250\",\"created_at\":\"2026-09-10T03:10:13.000000Z\",\"cached\":true},\"transaction\":{\"order_id\":\"sp_6aa21f90a725e\",\"status\":\"PENDING\",\"amount\":\"1000.00\",\"buyer_email\":\"hello@fastnet.co.tz\",\"buyer_name\":\"WMS customer\"}}', '2026-09-10 06:10:22'),
(17, NULL, 6, 'status_check', 'sonicpesa', 'sp_6aa21f90a725e', 1000.00, 'pending', 'Order status retrieved successfully', '{\"status\":\"success\",\"message\":\"Order status retrieved successfully\",\"data\":{\"order_id\":\"sp_6aa21f90a725e\",\"payment_status\":\"PENDING\",\"amount\":1000,\"currency\":\"TZS\",\"phone\":\"255716501250\",\"transid\":null,\"reference\":\"S20716830696\",\"channel\":null,\"msisdn\":\"255716501250\",\"created_at\":\"2026-09-10T03:10:13.000000Z\",\"cached\":true},\"transaction\":{\"order_id\":\"sp_6aa21f90a725e\",\"status\":\"PENDING\",\"amount\":\"1000.00\",\"buyer_email\":\"hello@fastnet.co.tz\",\"buyer_name\":\"WMS customer\"}}', '2026-09-10 06:10:24'),
(18, NULL, 6, 'status_check', 'sonicpesa', 'sp_6aa21f90a725e', 1000.00, 'pending', 'Order status retrieved successfully', '{\"status\":\"success\",\"message\":\"Order status retrieved successfully\",\"data\":{\"order_id\":\"sp_6aa21f90a725e\",\"payment_status\":\"PENDING\",\"amount\":1000,\"currency\":\"TZS\",\"phone\":\"255716501250\",\"transid\":null,\"reference\":\"S20716830696\",\"channel\":null,\"msisdn\":\"255716501250\",\"created_at\":\"2026-09-10T03:10:13.000000Z\",\"cached\":true},\"transaction\":{\"order_id\":\"sp_6aa21f90a725e\",\"status\":\"PENDING\",\"amount\":\"1000.00\",\"buyer_email\":\"hello@fastnet.co.tz\",\"buyer_name\":\"WMS customer\"}}', '2026-09-10 06:10:25'),
(19, NULL, 6, 'status_check', 'sonicpesa', 'sp_6aa21f90a725e', 1000.00, 'pending', 'Order status retrieved successfully', '{\"status\":\"success\",\"message\":\"Order status retrieved successfully\",\"data\":{\"order_id\":\"sp_6aa21f90a725e\",\"payment_status\":\"PENDING\",\"amount\":1000,\"currency\":\"TZS\",\"phone\":\"255716501250\",\"transid\":null,\"reference\":\"S20716830696\",\"channel\":null,\"msisdn\":\"255716501250\",\"created_at\":\"2026-09-10T03:10:13.000000Z\",\"cached\":true},\"transaction\":{\"order_id\":\"sp_6aa21f90a725e\",\"status\":\"PENDING\",\"amount\":\"1000.00\",\"buyer_email\":\"hello@fastnet.co.tz\",\"buyer_name\":\"WMS customer\"}}', '2026-09-10 06:10:27'),
(20, NULL, 7, 'charge', 'sonicpesa', 'sp_6aa2264d49732', 1000.00, 'pending', 'Check your phone and enter your mobile money PIN to approve the payment.', '{\"status\":\"success\",\"message\":\"Payment order created successfully! Push USSD sent to your phone.\",\"data\":{\"order_id\":\"sp_6aa2264d49732\",\"reference\":\"S20716859913\",\"amount\":1000,\"currency\":\"TZS\",\"payment_status\":\"PENDING\",\"status\":\"PENDING\",\"creation_date\":\"2026-09-10 06:39:44\",\"transid\":null,\"channel\":null,\"msisdn\":\"255754000111\",\"order_status_data\":{\"order_id\":\"sp_6aa2264d49732\",\"creation_date\":\"2026-09-10 06:39:44\",\"amount\":\"1000\",\"payment_status\":\"PENDING\",\"transid\":null,\"channel\":null,\"reference\":null,\"msisdn\":null}}}', '2026-09-10 06:38:59'),
(21, NULL, 8, 'charge', 'sonicpesa', 'sp_6aa228f748360', 1000.00, 'pending', 'Check your phone and enter your mobile money PIN to approve the payment.', '{\"status\":\"success\",\"message\":\"Payment order created successfully! Push USSD sent to your phone.\",\"data\":{\"order_id\":\"sp_6aa228f748360\",\"reference\":\"S20716870136\",\"amount\":1000,\"currency\":\"TZS\",\"payment_status\":\"PENDING\",\"status\":\"PENDING\",\"creation_date\":\"2026-09-10 06:51:06\",\"transid\":null,\"channel\":null,\"msisdn\":\"255754000222\",\"order_status_data\":{\"order_id\":\"sp_6aa228f748360\",\"creation_date\":\"2026-09-10 06:51:06\",\"amount\":\"1000\",\"payment_status\":\"PENDING\",\"transid\":null,\"channel\":null,\"reference\":null,\"msisdn\":null}}}', '2026-09-10 06:50:20'),
(22, NULL, 9, 'charge', 'sonicpesa', 'sp_6aa229436ede9', 1000.00, 'pending', 'Check your phone and enter your mobile money PIN to approve the payment.', '{\"status\":\"success\",\"message\":\"Payment order created successfully! Push USSD sent to your phone.\",\"data\":{\"order_id\":\"sp_6aa229436ede9\",\"reference\":\"S20716872365\",\"amount\":1000,\"currency\":\"TZS\",\"payment_status\":\"PENDING\",\"status\":\"PENDING\",\"creation_date\":\"2026-09-10 06:52:22\",\"transid\":null,\"channel\":null,\"msisdn\":\"255754000333\",\"order_status_data\":{\"order_id\":\"sp_6aa229436ede9\",\"creation_date\":\"2026-09-10 06:52:22\",\"amount\":\"1000\",\"payment_status\":\"PENDING\",\"transid\":null,\"channel\":null,\"reference\":null,\"msisdn\":null}}}', '2026-09-10 06:51:36'),
(23, NULL, 5, 'status_check', 'sonicpesa', 'sp_6aa1bfe8a750b', 1000.00, 'pending', 'Order status retrieved successfully', '{\"status\":\"success\",\"message\":\"Order status retrieved successfully\",\"data\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"payment_status\":\"PENDING\",\"amount\":1000,\"currency\":\"TZS\",\"phone\":\"255716501250\",\"transid\":null,\"reference\":\"S20716422576\",\"channel\":null,\"msisdn\":\"255716501250\",\"created_at\":\"2026-09-09T20:22:05.000000Z\",\"cached\":true},\"transaction\":{\"order_id\":\"sp_6aa1bfe8a750b\",\"status\":\"PENDING\",\"amount\":\"1000.00\",\"buyer_email\":\"hello@fastnet.co.tz\",\"buyer_name\":\"WMS customer\"}}', '2026-09-10 22:26:28'),
(24, NULL, 6, 'status_check', 'sonicpesa', 'sp_6aa21f90a725e', 1000.00, 'pending', 'Order status retrieved successfully', '{\"status\":\"success\",\"message\":\"Order status retrieved successfully\",\"data\":{\"order_id\":\"sp_6aa21f90a725e\",\"payment_status\":\"PENDING\",\"amount\":1000,\"currency\":\"TZS\",\"phone\":\"255716501250\",\"transid\":null,\"reference\":\"S20716830696\",\"channel\":null,\"msisdn\":\"255716501250\",\"created_at\":\"2026-09-10T03:10:13.000000Z\",\"cached\":true},\"transaction\":{\"order_id\":\"sp_6aa21f90a725e\",\"status\":\"PENDING\",\"amount\":\"1000.00\",\"buyer_email\":\"hello@fastnet.co.tz\",\"buyer_name\":\"WMS customer\"}}', '2026-09-10 22:26:29'),
(25, NULL, 8, 'status_check', 'sonicpesa', 'sp_6aa228f748360', 1000.00, 'pending', 'Order status retrieved successfully', '{\"status\":\"success\",\"message\":\"Order status retrieved successfully\",\"data\":{\"order_id\":\"sp_6aa228f748360\",\"payment_status\":\"PENDING\",\"amount\":1000,\"currency\":\"TZS\",\"phone\":\"255754000222\",\"transid\":null,\"reference\":\"S20716870136\",\"channel\":null,\"msisdn\":\"255754000222\",\"created_at\":\"2026-09-10T03:50:20.000000Z\",\"cached\":true},\"transaction\":{\"order_id\":\"sp_6aa228f748360\",\"status\":\"PENDING\",\"amount\":\"1000.00\",\"buyer_email\":\"hello@fastnet.co.tz\",\"buyer_name\":\"Test\"}}', '2026-09-10 22:26:31'),
(26, NULL, 9, 'status_check', 'sonicpesa', 'sp_6aa229436ede9', 1000.00, 'pending', 'Order status retrieved successfully', '{\"status\":\"success\",\"message\":\"Order status retrieved successfully\",\"data\":{\"order_id\":\"sp_6aa229436ede9\",\"payment_status\":\"PENDING\",\"amount\":1000,\"currency\":\"TZS\",\"phone\":\"255754000333\",\"transid\":null,\"reference\":\"S20716872365\",\"channel\":null,\"msisdn\":\"255754000333\",\"created_at\":\"2026-09-10T03:51:36.000000Z\",\"cached\":true},\"transaction\":{\"order_id\":\"sp_6aa229436ede9\",\"status\":\"PENDING\",\"amount\":\"1000.00\",\"buyer_email\":\"hello@fastnet.co.tz\",\"buyer_name\":\"WMS customer\"}}', '2026-09-10 22:26:33');

-- --------------------------------------------------------

--
-- Table structure for table `usage_records`
--

CREATE TABLE `usage_records` (
  `id` bigint UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `customer_id` int UNSIGNED DEFAULT NULL,
  `voucher_id` bigint UNSIGNED DEFAULT NULL,
  `subscription_id` bigint UNSIGNED DEFAULT NULL,
  `session_id` bigint UNSIGNED DEFAULT NULL,
  `router_id` int UNSIGNED DEFAULT NULL,
  `record_date` date NOT NULL,
  `download_bytes` bigint UNSIGNED NOT NULL DEFAULT '0',
  `upload_bytes` bigint UNSIGNED NOT NULL DEFAULT '0',
  `total_bytes` bigint UNSIGNED NOT NULL DEFAULT '0',
  `duration_seconds` int UNSIGNED NOT NULL DEFAULT '0',
  `source` enum('live','demo') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'demo',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `role_id` int UNSIGNED NOT NULL,
  `full_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `username` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('active','suspended','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `avatar` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remember_token` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remember_expires_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `provider_id`, `role_id`, `full_name`, `username`, `email`, `phone`, `password_hash`, `status`, `avatar`, `remember_token`, `remember_expires_at`, `last_login_at`, `last_login_ip`, `created_at`, `updated_at`) VALUES
(1, NULL, 1, 'System Administrator', 'admin', 'admin@gmail.com', NULL, '$2y$10$FWNt31GJitZDESlh5/IEqO.UaHLmI3PcYgTJ1sTAOJc1LpRV1C5M2', 'active', NULL, NULL, NULL, '2026-09-10 22:08:12', '::1', '2026-09-09 06:23:02', '2026-09-10 22:08:12'),
(2, 2, 5, 'Provider Administrator', 'provider', 'provider@gmail.com', NULL, '$2y$10$wc.OnH39gbIl5HnGOVlsju.P0c1kkXbitTPVni91yUSfZYJ2VTSbO', 'active', NULL, NULL, NULL, '2026-09-10 22:08:53', '::1', '2026-09-09 06:23:47', '2026-09-10 22:08:53');

-- --------------------------------------------------------

--
-- Table structure for table `vouchers`
--

CREATE TABLE `vouchers` (
  `id` bigint UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch_id` int UNSIGNED DEFAULT NULL,
  `package_id` int UNSIGNED NOT NULL,
  `router_id` int UNSIGNED DEFAULT NULL,
  `customer_id` int UNSIGNED DEFAULT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `duration_value` int UNSIGNED NOT NULL DEFAULT '1',
  `duration_unit` enum('minutes','hours','days','months') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hours',
  `data_limit_mb` bigint UNSIGNED DEFAULT NULL,
  `download_kbps` int UNSIGNED NOT NULL DEFAULT '2048',
  `upload_kbps` int UNSIGNED NOT NULL DEFAULT '1024',
  `device_limit` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `data_used_mb` bigint UNSIGNED NOT NULL DEFAULT '0',
  `status` enum('available','activated','active','expired','exhausted','suspended','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'available',
  `activated_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `valid_until` datetime DEFAULT NULL COMMENT 'Must be activated before this date',
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `voucher_batches`
--

CREATE TABLE `voucher_batches` (
  `id` int UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED DEFAULT NULL,
  `batch_code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(140) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `package_id` int UNSIGNED NOT NULL,
  `router_id` int UNSIGNED DEFAULT NULL,
  `quantity` int UNSIGNED NOT NULL DEFAULT '0',
  `prefix` varchar(12) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `code_length` tinyint UNSIGNED NOT NULL DEFAULT '8',
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `valid_until` datetime DEFAULT NULL,
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wallet_transactions`
--

CREATE TABLE `wallet_transactions` (
  `id` bigint UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED NOT NULL,
  `type` enum('sale','platform_fee','withdrawal','withdrawal_reversal','refund','adjustment') COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` enum('credit','debit') COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `balance_after` decimal(14,2) NOT NULL,
  `currency` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TZS',
  `reference` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Payment ref, withdrawal id, invoice number',
  `payment_id` bigint UNSIGNED DEFAULT NULL,
  `withdrawal_id` bigint UNSIGNED DEFAULT NULL,
  `invoice_id` bigint UNSIGNED DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int UNSIGNED DEFAULT NULL COMMENT 'Staff member for manual adjustments',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `withdrawals`
--

CREATE TABLE `withdrawals` (
  `id` bigint UNSIGNED NOT NULL,
  `provider_id` int UNSIGNED NOT NULL,
  `reference` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `fee` decimal(12,2) NOT NULL DEFAULT '0.00',
  `net_amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TZS',
  `method` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_number` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_name` varchar(140) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('pending','processing','completed','failed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `provider_ref` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'SonicPesa withdrawal_id',
  `failure_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requested_by` int UNSIGNED DEFAULT NULL,
  `approved_by` int UNSIGNED DEFAULT NULL,
  `raw_response` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `access_points`
--
ALTER TABLE `access_points`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ap_provider_mac` (`provider_id`,`mac_address`),
  ADD KEY `idx_ap_router` (`router_id`),
  ADD KEY `idx_ap_status` (`status`),
  ADD KEY `idx_ap_mac` (`mac_address`),
  ADD KEY `idx_ap_provider` (`provider_id`),
  ADD KEY `idx_ap_provider_status` (`provider_id`,`status`),
  ADD KEY `idx_ap_router_status` (`router_id`,`status`),
  ADD KEY `idx_ap_last_seen` (`last_seen_at`),
  ADD KEY `idx_ap_deleted` (`deleted_at`),
  ADD KEY `idx_ap_management_ip` (`ip_address`);

--
-- Indexes for table `alerts`
--
ALTER TABLE `alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_alerts_severity` (`severity`),
  ADD KEY `idx_alerts_read` (`is_read`),
  ADD KEY `idx_alerts_resolved` (`is_resolved`),
  ADD KEY `idx_alerts_created` (`created_at`),
  ADD KEY `idx_alerts_type` (`type`),
  ADD KEY `fk_alerts_user` (`resolved_by`),
  ADD KEY `idx_alert_provider` (`provider_id`),
  ADD KEY `idx_alert_provider_state` (`provider_id`,`is_resolved`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_user` (`user_id`),
  ADD KEY `idx_audit_action` (`action`),
  ADD KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  ADD KEY `idx_audit_created` (`created_at`),
  ADD KEY `idx_audit_provider` (`provider_id`),
  ADD KEY `idx_audit_provider_created` (`provider_id`,`created_at`);

--
-- Indexes for table `bandwidth_profiles`
--
ALTER TABLE `bandwidth_profiles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_bp_name` (`name`),
  ADD KEY `idx_bp_status` (`status`),
  ADD KEY `idx_bp_provider` (`provider_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_customers_code` (`customer_code`),
  ADD UNIQUE KEY `uq_customers_username` (`username`),
  ADD KEY `idx_customers_phone` (`phone`),
  ADD KEY `idx_customers_email` (`email`),
  ADD KEY `idx_customers_status` (`status`),
  ADD KEY `idx_customers_type` (`customer_type`),
  ADD KEY `idx_customers_created` (`created_at`),
  ADD KEY `fk_customers_user` (`created_by`),
  ADD KEY `idx_cust_provider` (`provider_id`),
  ADD KEY `idx_cust_provider_status` (`provider_id`,`status`),
  ADD KEY `idx_cust_provider_created` (`provider_id`,`created_at`);

--
-- Indexes for table `devices`
--
ALTER TABLE `devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_devices_mac_customer` (`mac_address`,`customer_id`),
  ADD KEY `idx_devices_mac` (`mac_address`),
  ADD KEY `idx_devices_ip` (`ip_address`),
  ADD KEY `idx_devices_customer` (`customer_id`),
  ADD KEY `idx_devices_status` (`status`),
  ADD KEY `idx_devices_voucher` (`voucher_id`),
  ADD KEY `fk_devices_router` (`router_id`),
  ADD KEY `fk_devices_ap` (`access_point_id`),
  ADD KEY `idx_dev_provider` (`provider_id`),
  ADD KEY `idx_dev_provider_status` (`provider_id`,`status`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_la_identifier` (`identifier`,`created_at`),
  ADD KEY `idx_la_ip` (`ip_address`,`created_at`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_user` (`user_id`,`is_read`),
  ADD KEY `idx_notif_provider` (`provider_id`);

--
-- Indexes for table `packages`
--
ALTER TABLE `packages`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_packages_code` (`code`),
  ADD KEY `idx_packages_status` (`status`),
  ADD KEY `idx_packages_price` (`price`),
  ADD KEY `fk_packages_bp` (`bandwidth_profile_id`),
  ADD KEY `idx_pkg_provider` (`provider_id`),
  ADD KEY `idx_pkg_provider_status` (`provider_id`,`status`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payments_ref` (`transaction_ref`),
  ADD KEY `idx_payments_provider_ref` (`provider_ref`),
  ADD KEY `idx_payments_status` (`status`),
  ADD KEY `idx_payments_customer` (`customer_id`),
  ADD KEY `idx_payments_created` (`created_at`),
  ADD KEY `idx_payments_phone` (`payer_phone`),
  ADD KEY `fk_payments_package` (`package_id`),
  ADD KEY `idx_pay_provider` (`provider_id`),
  ADD KEY `idx_pay_provider_status` (`provider_id`,`status`),
  ADD KEY `idx_pay_provider_created` (`provider_id`,`created_at`);

--
-- Indexes for table `permissions`
--
ALTER TABLE `permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_permissions_slug` (`slug`),
  ADD KEY `idx_permissions_group` (`group_name`),
  ADD KEY `idx_permissions_scope` (`scope`);

--
-- Indexes for table `platform_invoices`
--
ALTER TABLE `platform_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_invoice_number` (`invoice_number`),
  ADD UNIQUE KEY `uq_invoice_period` (`provider_id`,`period_start`),
  ADD KEY `idx_inv_provider` (`provider_id`,`status`),
  ADD KEY `idx_inv_status` (`status`,`due_on`),
  ADD KEY `idx_inv_pay_ref` (`pay_provider_ref`);

--
-- Indexes for table `platform_withdrawals`
--
ALTER TABLE `platform_withdrawals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_pw_reference` (`reference`),
  ADD KEY `idx_pw_status` (`status`),
  ADD KEY `idx_pw_created` (`created_at`),
  ADD KEY `idx_pw_provider_ref` (`provider_ref`),
  ADD KEY `fk_pw_requester` (`requested_by`);

--
-- Indexes for table `providers`
--
ALTER TABLE `providers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_providers_code` (`provider_code`),
  ADD KEY `idx_providers_status` (`status`),
  ADD KEY `idx_providers_name` (`business_name`),
  ADD KEY `idx_providers_created` (`created_at`),
  ADD KEY `fk_providers_creator` (`created_by`),
  ADD KEY `idx_providers_billing` (`billing_status`,`billing_next_due_on`),
  ADD KEY `idx_providers_momo` (`mobile_money_enabled`),
  ADD KEY `idx_providers_fee_lock` (`billing_locked_at`,`service_suspended_at`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_roles_slug` (`slug`),
  ADD KEY `idx_roles_scope` (`scope`);

--
-- Indexes for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`role_id`,`permission_id`),
  ADD KEY `idx_rp_permission` (`permission_id`);

--
-- Indexes for table `routers`
--
ALTER TABLE `routers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_routers_provider_name` (`provider_id`,`name`),
  ADD KEY `idx_routers_status` (`status`),
  ADD KEY `idx_routers_ip` (`ip_address`),
  ADD KEY `idx_router_provider` (`provider_id`),
  ADD KEY `idx_router_provider_status` (`provider_id`,`status`),
  ADD KEY `idx_routers_provider_mode` (`provider_id`,`mode`),
  ADD KEY `idx_routers_last_seen` (`last_seen_at`),
  ADD KEY `idx_routers_deleted` (`deleted_at`);

--
-- Indexes for table `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sessions_status` (`status`),
  ADD KEY `idx_sessions_customer` (`customer_id`),
  ADD KEY `idx_sessions_voucher` (`voucher_id`),
  ADD KEY `idx_sessions_mac` (`mac_address`),
  ADD KEY `idx_sessions_ip` (`ip_address`),
  ADD KEY `idx_sessions_started` (`started_at`),
  ADD KEY `idx_sessions_router` (`router_id`),
  ADD KEY `fk_sessions_device` (`device_id`),
  ADD KEY `fk_sessions_ap` (`access_point_id`),
  ADD KEY `idx_sess_provider` (`provider_id`),
  ADD KEY `idx_sess_provider_status` (`provider_id`,`status`),
  ADD KEY `idx_sess_provider_started` (`provider_id`,`started_at`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_settings_provider_key` (`provider_id`,`setting_key`),
  ADD KEY `idx_settings_group` (`setting_group`),
  ADD KEY `idx_settings_provider` (`provider_id`);

--
-- Indexes for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_subs_customer` (`customer_id`),
  ADD KEY `idx_subs_status` (`status`),
  ADD KEY `idx_subs_end` (`end_at`),
  ADD KEY `idx_subs_voucher` (`voucher_id`),
  ADD KEY `fk_subs_package` (`package_id`),
  ADD KEY `fk_subs_payment` (`payment_id`),
  ADD KEY `idx_subs_provider` (`provider_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_txn_payment` (`payment_id`),
  ADD KEY `idx_txn_ref` (`provider_ref`),
  ADD KEY `idx_txn_created` (`created_at`),
  ADD KEY `idx_txn_provider` (`provider_id`);

--
-- Indexes for table `usage_records`
--
ALTER TABLE `usage_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_usage_date` (`record_date`),
  ADD KEY `idx_usage_customer` (`customer_id`,`record_date`),
  ADD KEY `idx_usage_voucher` (`voucher_id`),
  ADD KEY `idx_usage_session` (`session_id`),
  ADD KEY `idx_usage_provider` (`provider_id`),
  ADD KEY `idx_usage_provider_date` (`provider_id`,`record_date`),
  ADD KEY `idx_usage_provider_cust` (`provider_id`,`customer_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD KEY `idx_users_status` (`status`),
  ADD KEY `idx_users_role` (`role_id`),
  ADD KEY `idx_users_provider` (`provider_id`);

--
-- Indexes for table `vouchers`
--
ALTER TABLE `vouchers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_vouchers_code` (`code`),
  ADD KEY `idx_vouchers_status` (`status`),
  ADD KEY `idx_vouchers_batch` (`batch_id`),
  ADD KEY `idx_vouchers_package` (`package_id`),
  ADD KEY `idx_vouchers_customer` (`customer_id`),
  ADD KEY `idx_vouchers_created` (`created_at`),
  ADD KEY `idx_vouchers_expires` (`expires_at`),
  ADD KEY `fk_vouchers_router` (`router_id`),
  ADD KEY `idx_vouch_provider` (`provider_id`),
  ADD KEY `idx_vouch_provider_status` (`provider_id`,`status`),
  ADD KEY `idx_vouch_provider_created` (`provider_id`,`created_at`);

--
-- Indexes for table `voucher_batches`
--
ALTER TABLE `voucher_batches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_batches_code` (`batch_code`),
  ADD KEY `idx_batches_package` (`package_id`),
  ADD KEY `idx_batches_created` (`created_at`),
  ADD KEY `fk_batches_router` (`router_id`),
  ADD KEY `fk_batches_user` (`created_by`),
  ADD KEY `idx_batch_provider` (`provider_id`);

--
-- Indexes for table `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_wt_provider` (`provider_id`,`created_at`),
  ADD KEY `idx_wt_type` (`provider_id`,`type`),
  ADD KEY `idx_wt_payment` (`payment_id`),
  ADD KEY `idx_wt_created` (`created_at`),
  ADD KEY `fk_wt_user` (`created_by`);

--
-- Indexes for table `withdrawals`
--
ALTER TABLE `withdrawals`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_withdrawals_ref` (`reference`),
  ADD KEY `idx_wd_provider` (`provider_id`,`status`),
  ADD KEY `idx_wd_status` (`status`),
  ADD KEY `idx_wd_created` (`created_at`),
  ADD KEY `idx_wd_provider_ref` (`provider_ref`),
  ADD KEY `fk_wd_requester` (`requested_by`),
  ADD KEY `fk_wd_approver` (`approved_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `access_points`
--
ALTER TABLE `access_points`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `alerts`
--
ALTER TABLE `alerts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=63;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=387;

--
-- AUTO_INCREMENT for table `bandwidth_profiles`
--
ALTER TABLE `bandwidth_profiles`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `devices`
--
ALTER TABLE `devices`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=93;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `packages`
--
ALTER TABLE `packages`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `permissions`
--
ALTER TABLE `permissions`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `platform_invoices`
--
ALTER TABLE `platform_invoices`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `platform_withdrawals`
--
ALTER TABLE `platform_withdrawals`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `providers`
--
ALTER TABLE `providers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `routers`
--
ALTER TABLE `routers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `sessions`
--
ALTER TABLE `sessions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=86;

--
-- AUTO_INCREMENT for table `subscriptions`
--
ALTER TABLE `subscriptions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `usage_records`
--
ALTER TABLE `usage_records`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `vouchers`
--
ALTER TABLE `vouchers`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=82;

--
-- AUTO_INCREMENT for table `voucher_batches`
--
ALTER TABLE `voucher_batches`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `withdrawals`
--
ALTER TABLE `withdrawals`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `access_points`
--
ALTER TABLE `access_points`
  ADD CONSTRAINT `fk_ap_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ap_router` FOREIGN KEY (`router_id`) REFERENCES `routers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `alerts`
--
ALTER TABLE `alerts`
  ADD CONSTRAINT `fk_alert_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_alerts_user` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `bandwidth_profiles`
--
ALTER TABLE `bandwidth_profiles`
  ADD CONSTRAINT `fk_bp_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `customers`
--
ALTER TABLE `customers`
  ADD CONSTRAINT `fk_cust_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_customers_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `devices`
--
ALTER TABLE `devices`
  ADD CONSTRAINT `fk_dev_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_devices_ap` FOREIGN KEY (`access_point_id`) REFERENCES `access_points` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_devices_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_devices_router` FOREIGN KEY (`router_id`) REFERENCES `routers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_devices_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `packages`
--
ALTER TABLE `packages`
  ADD CONSTRAINT `fk_packages_bp` FOREIGN KEY (`bandwidth_profile_id`) REFERENCES `bandwidth_profiles` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_pkg_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_pay_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_payments_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_payments_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `platform_invoices`
--
ALTER TABLE `platform_invoices`
  ADD CONSTRAINT `fk_inv_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `platform_withdrawals`
--
ALTER TABLE `platform_withdrawals`
  ADD CONSTRAINT `fk_pw_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `providers`
--
ALTER TABLE `providers`
  ADD CONSTRAINT `fk_providers_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `routers`
--
ALTER TABLE `routers`
  ADD CONSTRAINT `fk_router_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sessions`
--
ALTER TABLE `sessions`
  ADD CONSTRAINT `fk_sess_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sessions_ap` FOREIGN KEY (`access_point_id`) REFERENCES `access_points` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sessions_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sessions_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sessions_router` FOREIGN KEY (`router_id`) REFERENCES `routers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_sessions_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `subscriptions`
--
ALTER TABLE `subscriptions`
  ADD CONSTRAINT `fk_subs_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_subs_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_subs_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_subs_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_subs_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `fk_txn_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `usage_records`
--
ALTER TABLE `usage_records`
  ADD CONSTRAINT `fk_usage_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_usage_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_usage_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT;

--
-- Constraints for table `vouchers`
--
ALTER TABLE `vouchers`
  ADD CONSTRAINT `fk_vouch_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_vouchers_batch` FOREIGN KEY (`batch_id`) REFERENCES `voucher_batches` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_vouchers_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_vouchers_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_vouchers_router` FOREIGN KEY (`router_id`) REFERENCES `routers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `voucher_batches`
--
ALTER TABLE `voucher_batches`
  ADD CONSTRAINT `fk_batch_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_batches_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE RESTRICT,
  ADD CONSTRAINT `fk_batches_router` FOREIGN KEY (`router_id`) REFERENCES `routers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_batches_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `wallet_transactions`
--
ALTER TABLE `wallet_transactions`
  ADD CONSTRAINT `fk_wt_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_wt_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_wt_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `withdrawals`
--
ALTER TABLE `withdrawals`
  ADD CONSTRAINT `fk_wd_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_wd_provider` FOREIGN KEY (`provider_id`) REFERENCES `providers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_wd_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
