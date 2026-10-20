-- POS PWA Database Schema & Initial Seed Data
-- Timezone: Asia/Kolkata (+05:30)

CREATE DATABASE IF NOT EXISTS `pos_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `pos_db`;

-- 1. Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'cashier') NOT NULL DEFAULT 'cashier',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Products Table
CREATE TABLE IF NOT EXISTS `products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `category` VARCHAR(50) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `is_deleted` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_products_category` (`category`),
  INDEX `idx_products_status` (`is_active`, `is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Bills Table
CREATE TABLE IF NOT EXISTS `bills` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `bill_no` VARCHAR(50) NOT NULL UNIQUE,
  `client_uuid` VARCHAR(36) NULL UNIQUE,
  `customer_name` VARCHAR(100) NULL,
  `customer_phone` VARCHAR(20) NULL,
  `subtotal` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount_type` ENUM('none', 'flat', 'percent') NOT NULL DEFAULT 'none',
  `discount_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `advance_paid` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `balance_due` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('pending', 'completed') NOT NULL DEFAULT 'completed',
  `payment_mode` ENUM('upi', 'cash', 'mixed') NOT NULL DEFAULT 'cash',
  `upi_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `cash_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_by` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  INDEX `idx_bills_status` (`status`),
  INDEX `idx_bills_payment_mode` (`payment_mode`),
  INDEX `idx_bills_created_at` (`created_at`),
  INDEX `idx_bills_created_by` (`created_by`),
  CONSTRAINT `fk_bills_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Bill Items Table
CREATE TABLE IF NOT EXISTS `bill_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `bill_id` INT NOT NULL,
  `product_id` INT NULL,
  `item_name` VARCHAR(150) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `quantity` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `line_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `is_custom` TINYINT(1) NOT NULL DEFAULT 0,
  INDEX `idx_bill_items_bill` (`bill_id`),
  INDEX `idx_bill_items_product` (`product_id`),
  CONSTRAINT `fk_bill_items_bill` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bill_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Payments Table
CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `bill_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `upi_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `cash_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `type` ENUM('advance', 'balance', 'full') NOT NULL DEFAULT 'full',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_payments_bill` (`bill_id`),
  INDEX `idx_payments_type` (`type`),
  INDEX `idx_payments_created_at` (`created_at`),
  CONSTRAINT `fk_payments_bill` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Settings Table
CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` VARCHAR(50) PRIMARY KEY,
  `setting_value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Seed Data
-- ============================================================================

-- Seed Users:
-- admin: admin123 ($2y$12$jlYUXLSdO9.yMUFMgE4HbOAyQ8wtQQxL0RrVEbcCf0spDELyoCyuq)
-- cashier: cashier123 ($2y$12$fc9HI3LgQ7gYHiaE0xz8VeC49j4MBqqJiTKNO0yXvaIKj1tJ0LeOC)
INSERT INTO `users` (`id`, `name`, `username`, `password_hash`, `role`) VALUES
(1, 'System Administrator', 'admin', '$2y$12$jlYUXLSdO9.yMUFMgE4HbOAyQ8wtQQxL0RrVEbcCf0spDELyoCyuq', 'admin'),
(2, 'Counter Cashier', 'cashier', '$2y$12$fc9HI3LgQ7gYHiaE0xz8VeC49j4MBqqJiTKNO0yXvaIKj1tJ0LeOC', 'cashier')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `password_hash` = VALUES(`password_hash`), `role` = VALUES(`role`);

-- Seed Products: 12 sample items across 3 categories (Beverages, Snacks, Meals)
INSERT INTO `products` (`id`, `name`, `price`, `category`, `is_active`, `is_deleted`) VALUES
-- Beverages
(1, 'Masala Chai', 20.00, 'Beverages', 1, 0),
(2, 'Filter Coffee', 30.00, 'Beverages', 1, 0),
(3, 'Cold Coffee', 70.00, 'Beverages', 1, 0),
(4, 'Fresh Lime Soda', 45.00, 'Beverages', 1, 0),
-- Snacks
(5, 'Samosa (2 pcs)', 30.00, 'Snacks', 1, 0),
(6, 'Veg Puff', 25.00, 'Snacks', 1, 0),
(7, 'Paneer Kathi Roll', 90.00, 'Snacks', 1, 0),
(8, 'Crispy French Fries', 80.00, 'Snacks', 1, 0),
-- Meals
(9, 'Veg Executive Thali', 150.00, 'Meals', 1, 0),
(10, 'Paneer Butter Masala Combo', 180.00, 'Meals', 1, 0),
(11, 'Dal Khichdi Tadka', 120.00, 'Meals', 1, 0),
(12, 'Veg Dum Biryani', 160.00, 'Meals', 1, 0)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `price` = VALUES(`price`), `category` = VALUES(`category`);

-- Seed Default Settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('business_name', 'QuickPOS Mart'),
('business_address', '123 MG Road, Indiranagar, Bengaluru, Karnataka 560038'),
('address', '123 MG Road, Indiranagar, Bengaluru, Karnataka 560038'),
('business_phone', '+91 98765 43210'),
('phone', '+91 98765 43210'),
('business_gstin', '29ABCDE1234F1Z5'),
('gstin', '29ABCDE1234F1Z5'),
('receipt_footer', 'Thank you for shopping with us! Visit again.'),
('footer_message', 'Thank you for shopping with us! Visit again.'),
('paper_width', '58'),
('printer_ip', '192.168.1.200'),
('printer_port', '9100'),
('bill_prefix', 'INV')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
