-- ============================================================================
-- Course: BMIT2013 Web-Based Integrated Systems
-- Assignment Project: sumthin (Tech Gadget Online Store)
-- Database Name: sumthin_db
-- Generated: 2026-11-14
-- Note: MySQL / MariaDB compatible (phpMyAdmin)
-- ============================================================================

DROP DATABASE IF EXISTS `sumthin_db`;
CREATE DATABASE `sumthin_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `sumthin_db`;

-- ----------------------------------------------------------------------------
-- Table: user (Admins and Members)
-- ----------------------------------------------------------------------------
CREATE TABLE `user` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `role` ENUM('Admin', 'Member') NOT NULL DEFAULT 'Member',
  `photo` VARCHAR(255) DEFAULT 'default_user.png',
  `status` ENUM('Active', 'Blocked') NOT NULL DEFAULT 'Active',
  `failed_attempts` INT NOT NULL DEFAULT 0,
  `locked_until` DATETIME DEFAULT NULL,
  `remember_token` VARCHAR(64) DEFAULT NULL,
  `email_verified_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: category
-- ----------------------------------------------------------------------------
CREATE TABLE `category` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `slug` VARCHAR(100) NOT NULL UNIQUE,
  `description` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: brand
-- ----------------------------------------------------------------------------
CREATE TABLE `brand` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: product
-- ----------------------------------------------------------------------------
CREATE TABLE `product` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT NOT NULL,
  `brand_id` INT DEFAULT NULL,
  `name` VARCHAR(150) NOT NULL,
  `slug` VARCHAR(150) NOT NULL UNIQUE,
  `description` TEXT NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `stock` INT NOT NULL DEFAULT 0,
  `low_stock_threshold` INT NOT NULL DEFAULT 5,
  `warranty_months` TINYINT UNSIGNED NOT NULL DEFAULT 12,
  `specs` TEXT DEFAULT NULL,
  `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_product_category` (`category_id`),
  INDEX `idx_product_brand` (`brand_id`),
  CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`) REFERENCES `category` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_product_brand` FOREIGN KEY (`brand_id`) REFERENCES `brand` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: product_photo
-- ----------------------------------------------------------------------------
CREATE TABLE `product_photo` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `photo_url` VARCHAR(255) NOT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  CONSTRAINT `fk_photo_product` FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: address (Shipping addresses for members)
-- ----------------------------------------------------------------------------
CREATE TABLE `address` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `recipient_name` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `address_line1` VARCHAR(255) NOT NULL,
  `address_line2` VARCHAR(255) DEFAULT NULL,
  `city` VARCHAR(100) NOT NULL,
  `state` VARCHAR(100) NOT NULL,
  `postal_code` VARCHAR(10) NOT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_address_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: order (Reserved word escaped with backticks)
-- ----------------------------------------------------------------------------
CREATE TABLE `order` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_no` VARCHAR(30) NOT NULL UNIQUE,
  `user_id` INT NOT NULL,
  `address_id` INT DEFAULT NULL,
  `total_amount` DECIMAL(10,2) NOT NULL,
  `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `final_amount` DECIMAL(10,2) NOT NULL,
  `status` ENUM('Pending', 'Processing', 'Shipped', 'Delivered', 'Cancelled') NOT NULL DEFAULT 'Pending',
  `payment_status` ENUM('Unpaid', 'Paid', 'Refunded') NOT NULL DEFAULT 'Unpaid',
  `payment_method` ENUM('stripe', 'pay_later') NOT NULL DEFAULT 'pay_later',
  `tracking_no` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_order_user_status` (`user_id`, `status`),
  CONSTRAINT `fk_order_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_address` FOREIGN KEY (`address_id`) REFERENCES `address` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: order_item
-- ----------------------------------------------------------------------------
CREATE TABLE `order_item` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `quantity` INT NOT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL,
  INDEX `idx_order_item_product` (`product_id`),
  CONSTRAINT `fk_item_order` FOREIGN KEY (`order_id`) REFERENCES `order` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_product` FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: payment (Stripe and online payment transactions)
-- ----------------------------------------------------------------------------
CREATE TABLE `payment` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `stripe_session_id` VARCHAR(255) DEFAULT NULL UNIQUE,
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'myr',
  `status` ENUM('Pending', 'Paid', 'Failed', 'Refunded') NOT NULL DEFAULT 'Pending',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at` DATETIME DEFAULT NULL,
  CONSTRAINT `fk_payment_order` FOREIGN KEY (`order_id`) REFERENCES `order` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: cart_item (Permanent shopping cart in DB)
-- ----------------------------------------------------------------------------
CREATE TABLE `cart_item` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_cart_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cart_product` FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE CASCADE,
  UNIQUE KEY `uq_cart_user_product` (`user_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: wishlist (Member favorites)
-- ----------------------------------------------------------------------------
CREATE TABLE `wishlist` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `product_id` INT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_wishlist_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wishlist_product` FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE CASCADE,
  UNIQUE KEY `uq_wishlist_user_product` (`user_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: voucher (Discount vouchers)
-- ----------------------------------------------------------------------------
CREATE TABLE `voucher` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `code` VARCHAR(50) NOT NULL UNIQUE,
  `discount_type` ENUM('fixed', 'percentage') NOT NULL,
  `discount_value` DECIMAL(10,2) NOT NULL,
  `min_spend` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `max_discount` DECIMAL(10,2) DEFAULT NULL,
  `usage_limit` INT NOT NULL DEFAULT 100,
  `used_count` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: review (Product rating and customer review)
-- ----------------------------------------------------------------------------
CREATE TABLE `review` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `order_id` INT DEFAULT NULL,
  `rating` TINYINT NOT NULL CHECK (`rating` BETWEEN 1 AND 5),
  `comment` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_review_product` FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_review_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_review_order` FOREIGN KEY (`order_id`) REFERENCES `order` (`id`) ON DELETE SET NULL,
  UNIQUE KEY `uq_review_user_product_order` (`user_id`, `product_id`, `order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: password_reset (Password reset tokens)
-- ----------------------------------------------------------------------------
CREATE TABLE `password_reset` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(100) NOT NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: email_verification (Account activation tokens)
-- ----------------------------------------------------------------------------
CREATE TABLE `email_verification` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token_hash` CHAR(64) NOT NULL UNIQUE,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_verify_user` FOREIGN KEY (`user_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: login_attempt (Security tracking for brute-force protection)
-- ----------------------------------------------------------------------------
CREATE TABLE `login_attempt` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(100) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: email_log (Auditing emails sent via PHPMailer / SMTP)
-- ----------------------------------------------------------------------------
CREATE TABLE `email_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sent_by` INT DEFAULT NULL,
  `type` VARCHAR(30) NOT NULL DEFAULT 'system',
  `recipient` VARCHAR(100) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `status` ENUM('Sent', 'Failed') NOT NULL DEFAULT 'Sent',
  `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_email_log_user` FOREIGN KEY (`sent_by`) REFERENCES `user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: email_template (Pre-defined marketing and notification templates)
-- ----------------------------------------------------------------------------
CREATE TABLE `email_template` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL UNIQUE,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: chat_message (Live customer-admin chat)
-- ----------------------------------------------------------------------------
CREATE TABLE `chat_message` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `member_id` INT NOT NULL,
  `sender_role` ENUM('Member', 'Admin') NOT NULL,
  `message` VARCHAR(500) NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_chat_member_id` (`member_id`, `id`),
  CONSTRAINT `fk_chat_member` FOREIGN KEY (`member_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: setting (Store configuration, announcements, shipping fees, lockout)
-- ----------------------------------------------------------------------------
CREATE TABLE `setting` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `key` VARCHAR(50) NOT NULL UNIQUE,
  `value` TEXT NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SAMPLE DATA
-- ============================================================================

-- 1. Insert Categories (6 categories)
INSERT INTO `category` (`id`, `name`, `slug`, `description`) VALUES
(1, 'Power Banks', 'power-banks', 'High-capacity sumthin portable power banks with 140W fast charging and PD 3.1.'),
(2, 'Keyboards', 'keyboards', 'Premium sumthin mechanical, low-profile, and custom programmable keyboards.'),
(3, 'Mice', 'mice', 'Precision sumthin wireless, ergonomic, and ultralight competitive mice.'),
(4, 'Audio & Headphones', 'audio', 'Active noise-cancelling headphones and true wireless earbuds by sumthin Acoustics.'),
(5, 'Chargers & Cables', 'chargers-cables', 'sumthin GaN fast wall adapters, heavy-duty braided cables, and power hubs.'),
(6, 'Webcams & Streaming', 'webcams', '4K and 1080p webcams, streaming camera gear, and studio boom arms.');

-- 2. Insert sumthin Brand Series
INSERT INTO `brand` (`id`, `name`) VALUES
(1, 'sumthin Core'),
(2, 'sumthin Pro'),
(3, 'sumthin Studio'),
(4, 'sumthin Ultra');

-- 3. Insert Users (2 Admins: Admin123! | 17 Members: Member123!)
-- Member ID 17 is Blocked, Member ID 18 is Locked (failed_attempts=3, locked_until future)
-- Member ID 19 is Unverified (email_verified_at = NULL for verification demo)
INSERT INTO `user` (`id`, `email`, `password_hash`, `name`, `phone`, `role`, `photo`, `status`, `failed_attempts`, `locked_until`, `remember_token`, `email_verified_at`, `created_at`) VALUES
(1, 'admin@sumthin.com', '$2y$10$q3t4RuriSZuA7QAL0lCje.anBH9mtYSYww20kv8wAP0SFlHp9H6Pm', 'System Administrator', '012-3456789', 'Admin', 'default_user.png', 'Active', 0, NULL, NULL, '2026-01-01 08:00:00', '2026-01-01 08:00:00'),
(2, 'staff@sumthin.com', '$2y$10$q3t4RuriSZuA7QAL0lCje.anBH9mtYSYww20kv8wAP0SFlHp9H6Pm', 'Staff Moderator', '012-9876543', 'Admin', 'default_user.png', 'Active', 0, NULL, NULL, '2026-01-01 08:30:00', '2026-01-01 08:30:00'),
(3, 'alice@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Alice Tan', '016-1122334', 'Member', 'default_user.png', 'Active', 0, NULL, NULL, '2026-01-10 09:00:00', '2026-01-10 09:00:00'),
(4, 'bob@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Bob Lee', '017-2233445', 'Member', 'default_user.png', 'Active', 0, NULL, NULL, '2026-01-11 10:15:00', '2026-01-11 10:15:00'),
(5, 'charlie@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Charlie Wong', '018-3344556', 'Member', 'default_user.png', 'Active', 0, NULL, NULL, '2026-01-12 11:20:00', '2026-01-12 11:20:00'),
(6, 'david@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'David Lim', '019-4455667', 'Member', 'default_user.png', 'Active', 0, NULL, NULL, '2026-01-14 14:00:00', '2026-01-14 14:00:00'),
(7, 'emily@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Emily Chen', '012-5566778', 'Member', 'default_user.png', 'Active', 0, NULL, NULL, '2026-01-15 16:30:00', '2026-01-15 16:30:00'),
(8, 'fiona@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Fiona Teo', '013-6677889', 'Member', 'default_user.png', 'Active', 0, NULL, NULL, '2026-01-18 13:45:00', '2026-01-18 13:45:00'),
(9, 'george@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'George Koh', '014-7788990', 'Member', 'default_user.png', 'Active', 0, NULL, NULL, '2026-01-20 15:10:00', '2026-01-20 15:10:00'),
(10, 'hannah@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Hannah Ng', '011-8899001', 'Member', 'default_user.png', 'Active', 0, NULL, NULL, '2026-01-22 17:00:00', '2026-01-22 17:00:00'),
(11, 'ian@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Ian Low', '016-9900112', 'Member', 'default_user.png', 'Active', 0, NULL, NULL, '2026-01-25 10:30:00', '2026-01-25 10:30:00'),
(12, 'jessica@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Jessica Tay', '017-0011223', 'Member', 'default_user.png', 'Active', 0, NULL, NULL, '2026-01-28 12:20:00', '2026-01-28 12:20:00'),
(13, 'kevin@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Kevin Yap', '018-1234987', 'Member', 'default_user.png', 'Active', 0, NULL, NULL, '2026-02-01 14:40:00', '2026-02-01 14:40:00'),
(14, 'lily@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Lily Chua', '019-2345876', 'Member', 'default_user.png', 'Active', 0, NULL, NULL, '2026-02-05 09:50:00', '2026-02-05 09:50:00'),
(15, 'marcus@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Marcus Heng', '012-3456987', 'Member', 'default_user.png', 'Active', 0, NULL, NULL, '2026-02-10 16:15:00', '2026-02-10 16:15:00'),
(16, 'nicole@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Nicole Fong', '013-4567890', 'Member', 'default_user.png', 'Active', 0, NULL, NULL, '2026-02-15 11:05:00', '2026-02-15 11:05:00'),
(17, 'oliver@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Oliver See', '014-5678901', 'Member', 'default_user.png', 'Blocked', 0, NULL, NULL, '2026-02-20 13:25:00', '2026-02-20 13:25:00'),
(18, 'patricia@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Patricia Sim', '011-6789012', 'Member', 'default_user.png', 'Active', 3, '2027-12-31 23:59:59', NULL, '2026-02-25 15:40:00', '2026-02-25 15:40:00'),
(19, 'quinn@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Quinn Tan', '016-7890123', 'Member', 'default_user.png', 'Active', 0, NULL, NULL, NULL, '2026-03-01 10:00:00');

-- 4. Insert 30 sumthin-Branded Products
-- Products 3, 9, 18 have low stock (< 5); Product 12 has stock = 0
INSERT INTO `product` (`id`, `category_id`, `brand_id`, `name`, `slug`, `description`, `price`, `stock`, `low_stock_threshold`, `warranty_months`, `specs`, `is_featured`) VALUES
(1, 1, '4', 'sumthin Volt 24K (140W Two-Way GaN)', 'sumthin-volt-24k-power-bank', 'Flagship 24,000mAh portable power bank with 140W ultra-fast two-way USB-C PD 3.1 charging. Features an intelligent real-time digital OLED status display.', 599.00, 25, 5, 24, 'Capacity: 24,000mAh (86.4Wh)
Max Output: 140W USB-C PD 3.1
Ports: 2x USB-C, 1x USB-A
Display: Smart Digital OLED
Recharge Time: 52 minutes (140W)
Weight: 540g', 1),
(2, 1, '2', 'sumthin Blade Ultra-Slim 100W Laptop Power Bank', 'sumthin-blade-100w-power-bank', 'Razor-thin 18mm aluminum alloy chassis packing 20,000mAh with 100W power delivery. Designed specifically to slip into laptop sleeves with MacBooks and Dell XPS.', 299.00, 40, 5, 18, 'Capacity: 20,000mAh (74Wh)
Max Output: 100W Power Delivery
Ports: 2x USB-C, 2x USB-A
Thickness: 18mm Ultra-Slim Profile
Material: Anodized Aluminum Alloy
Weight: 490g', 1),
(3, 1, '2', 'sumthin NexCore 145W Multi-Port 25,000mAh', 'sumthin-nexcore-145w-power-bank', 'Heavy-duty 3-port high capacity battery pack. Powers 2 laptops simultaneously with high-grade airline-certified battery cells.', 379.00, 2, 5, 24, 'Capacity: 25,000mAh (90Wh)
Max Output: 145W Total (100W + 45W Dual Laptop)
Ports: 2x USB-C, 1x USB-A
Battery Type: 21700 EV-Grade Lithium-Ion
Display: Digital LED Percentage
Weight: 505g', 0),
(4, 1, '1', 'sumthin MagSnap 10,000mAh Magnetic Qi2 Battery', 'sumthin-magsnap-qi2-10000mah', 'Official Qi2 certified 15W high-speed magnetic wireless power bank with integrated zinc-alloy kickstand and smart battery level LED.', 329.00, 30, 5, 12, 'Capacity: 10,000mAh (38.5Wh)
Wireless Output: 15W Qi2 Certified
Wired Output: 20W USB-C PD
Stand: Foldable Zinc Alloy Kickstand
Compatibility: MagSafe & Qi2 Devices
Weight: 210g', 0),
(5, 1, '1', 'sumthin PocketMini 6,000mAh Snap-On Wireless Pack', 'sumthin-pocketmini-6000mah', 'Featherweight everyday carry battery that snaps firmly onto modern smartphones with zero camera blockage and 20W wired fast charge backup.', 129.00, 50, 5, 12, 'Capacity: 6,000mAh (22.2Wh)
Wireless Charging: 10W Magnetic Snap
Wired Output: 18W USB-C Fast Charge
Design: Ultra-Compact Pocket Size
Camera Clearance: Zero Lens Obstruction
Weight: 135g', 0),
(6, 2, '2', 'sumthin KeyPro Q1 Aluminum Custom Mechanical Keyboard', 'sumthin-keypro-q1-mechanical-keyboard', 'Precision CNC milled 6063 aluminum body with double-gasket acoustic dampening, hot-swappable tactile switches, and full QMK/VIA programmable firmware.', 899.00, 12, 5, 24, 'Layout: 75% Exploded (82 Keys)
Case: CNC Milled 6063 Aluminum
Mounting: Double-Gasket Acoustic Dampening
Switches: Gateron Jupiter Banana Hot-Swap
Connectivity: Type-C Wired / QMK & VIA
Keycaps: Double-Shot PBT Cherry Profile', 1),
(7, 2, '1', 'sumthin Tactile K2 Wireless 75% Mechanical Keyboard', 'sumthin-tactile-k2-wireless-keyboard', 'Compact 75% wireless mechanical keyboard with multi-device Bluetooth 5.1 pairing, Mac & Windows physical switch, and 4000mAh battery life.', 389.00, 35, 5, 12, 'Layout: 75% Compact
Switches: sumthin Low-Noise Tactile
Connectivity: Bluetooth 5.1 & Type-C Wired
Multi-Device: Up to 3 Devices Seamless Switch
Battery: 4,000mAh Rechargeable Li-Ion
OS Support: Mac & Windows Dedicated Switch', 0),
(8, 2, '3', 'sumthin LowProfile S Smart Wireless Quiet Keyboard', 'sumthin-lowprofile-s-keyboard', 'Ultra-thin ergonomic productivity keyboard with spherically-dished quiet scissor keys, proximity hand sensors, and dynamic smart backlighting.', 469.00, 28, 5, 24, 'Key Mechanism: Spherically-Dished Scissor Keys
Profile: Ergonomic Ultra-Thin 14mm
Backlighting: Smart Hand Proximity Sensor
Connectivity: 2.4GHz Wireless & Bluetooth LE
Battery: USB-C Fast Charge (Up to 5 months)
Weight: 810g', 1),
(9, 2, '4', 'sumthin Strike Apex Pro RGB Gaming Mechanical Keyboard', 'sumthin-strike-apex-pro-keyboard', 'Competition-grade optical gaming keyboard featuring an interactive rotary command dial, magnetic memory foam wrist rest, and per-key RGB backlighting.', 999.00, 3, 5, 24, 'Switches: OmniPoint 2.0 Adjustable Optical
Actuation Point: 0.2mm - 3.8mm Rapid Trigger
Dial: Multi-Function Programmable Rotary Dial
Wrist Rest: Magnetic Soft-Touch Memory Foam
Chassis: Aircraft-Grade Aluminum Top Plate
Lighting: Per-Key 16.8M RGB Backlight', 0),
(10, 2, '1', 'sumthin Creator V1 Frosted Custom Mechanical Keyboard', 'sumthin-creator-v1-keyboard', 'Translucent frosted black aesthetic with sound-absorbing silicone pad, south-facing RGB, and screw-in PCB stabilizers for enthusiasts.', 299.00, 42, 5, 12, 'Layout: 75% Translucent Frosted Acrylic
Stabilizers: PCB-Mounted Screw-in Stabilizers
Sound Dampening: Custom Silicone Base Pad
Lighting: South-Facing Vibrant RGB
Connectivity: USB Type-C Detachable Cable
Hot-Swap: 3-Pin / 5-Pin Switch Sockets', 0),
(11, 3, '3', 'sumthin Glide Master 3S Silent Ergonomic Mouse', 'sumthin-glide-master-3s-mouse', 'Industry-benchmark productivity mouse with whisper-quiet acoustic clicks, 8,000 DPI track-on-glass sensor, and electromagnetic MagSpeed scroll wheel.', 439.00, 45, 5, 24, 'Sensor: 8,000 DPI Darkfield High Precision
Clicks: 90% Reduced Click Noise Acoustic Guard
Scroll Wheel: MagSpeed Electromagnetic SmartShift
Thumb Wheel: Horizontal Side Navigation Scroll
Connectivity: 2.4GHz Wireless & Bluetooth LE
Battery: 70 Days per Full Charge', 1),
(12, 3, '4', 'sumthin Swift V3 Pro 63g Wireless Ultralight Gaming Mouse', 'sumthin-swift-v3-pro-mouse', 'Featherlight 63-gram ergonomic esports mouse engineered with a 30,000 DPI optical sensor, Gen-3 switches, and flawless 90-hour battery life.', 649.00, 0, 5, 24, 'Sensor: Focus Pro 30K Optical (30,000 DPI)
Weight: 63g Featherlight Symmetric Design
Switches: Gen-3 Optical Mouse Switches (90M clicks)
Polling Rate: Up to 4,000Hz Wireless Compatible
Battery Life: Up to 90 Hours Continuous Gaming
Feet: 100% Virgin PTFE Glides', 0),
(13, 3, '4', 'sumthin ZeroAir Superlight Competitive Wireless Mouse', 'sumthin-zeroair-superlight-mouse', 'Sub-60g symmetrical competitive mouse with hybrid optical-mechanical switches and zero-smoothing frictionless PTFE gliding skates.', 699.00, 20, 5, 24, 'Sensor: HERO 25K Sensor (Zero Smoothing)
Weight: Sub-60g Lightweight Structural Shell
Connectivity: LIGHTSPEED Pro-Grade Wireless
Switches: LIGHTFORCE Hybrid Optical-Mechanical
Battery Life: 95 Hours Constant Motion
Charging: USB-C & Wireless Charging Compatible', 1),
(14, 3, '1', 'sumthin Basilisk Ergonomic 11-Zone Chroma Mouse', 'sumthin-basilisk-mouse', 'Customizable ergonomic mouse featuring an adjustable resistance tilt-wheel, multi-function trigger paddle, and 11 addressable RGB lighting zones.', 289.00, 38, 5, 12, 'Form Factor: Right-Handed Ergonomic Contour
RGB: 11-Zone Full Underglow Lighting
Scroll Wheel: Configurable Resistance Dial
Buttons: 11 Programmable Inputs + Multi-Function Paddle
Sensor: 26,000 DPI Optical Sensor
Cable: Speedflex Drag-Free Braided Cable', 0),
(15, 3, '3', 'sumthin Studio M3 Dual-Mode 26,000 DPI Optical Mouse', 'sumthin-studio-m3-mouse', 'Clean minimalist tri-mode wireless mouse (2.4G + BT 5.1 + Wired) featuring flagship PixArt PAW3395 optical sensor weighing only 79 grams.', 219.00, 25, 5, 12, 'Sensor: PixArt PAW3395 Flagship Optical
Connectivity: Tri-Mode (2.4G / BT 5.2 / Wired)
Weight: 79g Balanced Studio Productivity Shell
Coating: Fingerprint-Resistant Matte UV Coating
Battery: 500mAh Lithium-Polymer (80 hours)
DPI Steps: 6 Onboard Adjustable Presets', 0),
(16, 4, '4', 'sumthin PureTone ANC Flagship Wireless Headphones', 'sumthin-puretone-anc-headphones', 'World-class active noise cancellation driven by dual dedicated processors and 8 microphones. Supports LDAC 24-bit Hi-Res audio with 30-hour battery life.', 1399.00, 15, 5, 24, 'ANC: Dual-Core Active Noise Cancelling Processor
Drivers: 40mm Carbon Fiber Composite Diaphragms
Audio Codec: Sony LDAC 24-bit Hi-Res Wireless
Microphones: 8 Mics with Beamforming Voice Pickup
Battery Life: 30 Hours (ANC On) / 40 Hours (ANC Off)
Comfort: Ultra-Soft Memory Foam Protein Leather', 1),
(17, 4, '2', 'sumthin Buds Pro True Wireless Noise-Cancelling Earbuds', 'sumthin-buds-pro-earbuds', 'Compact studio earbuds featuring bespoke carbon dynamic drivers, bone-conduction voice clarity sensors, wireless charging case, and IPX4 water rating.', 1099.00, 22, 5, 24, 'ANC: Adaptive Intelligent Noise Cancellation
Drivers: 11mm Dual Dynamic Drivers
Voice Clarity: Bone-Conduction Sensor + 3 Mics
Water Resistance: IPX4 Sweat & Water Proof
Playback: 8 Hours (Earbuds) + 24 Hours (Charging Case)
Charging: Qi Wireless Charging & USB-C Fast Charge', 1),
(18, 4, '3', 'sumthin AeroVoice Pro Wireless Studio Headset', 'sumthin-aerovoice-pro-headset', 'Ultra-wideband broadcast microphone, titanium-coated 50mm acoustic drivers, and memory-foam breathable earcups for creators and competitive gaming.', 899.00, 4, 5, 24, 'Microphone: 9.9mm Broadcast-Grade Detachable Condenser
Drivers: 50mm Titanium Acoustic Chambers
Sound Profile: Studio-Calibrated Neutral Response
Wireless: Low-Latency 2.4GHz Lossless Audio
Ear Cushions: Breathable Magnetic Memory Foam
Battery: 50+ Hours Continuous Studio Use', 0),
(19, 4, '1', 'sumthin SpaceOne Adaptive ANC Bluetooth Headphones', 'sumthin-spaceone-headphones', 'Adaptive 2.0 active noise cancelling targeting human voice frequencies with 40-hour ANC playtime and foldable space-saving travel design.', 399.00, 32, 5, 12, 'ANC: 2X Stronger Human Voice Reduction
Drivers: 40mm Custom Dynamic Drivers
Hi-Res Audio: LDAC Certified Wireless
Battery Life: 40 Hours ANC / 55 Hours Standard
Portability: Foldable Rotating Axis Structure
App Control: Custom EQ Curves & HearID Profile', 0),
(20, 4, '1', 'sumthin Liberty ANC Ultra-Compact True Wireless Buds', 'sumthin-liberty-anc-buds', 'Up to 98.5% background noise reduction, custom 11mm composite diaphragms, and 50 hours of total playback with rapid USB-C fast charging.', 349.00, 40, 5, 12, 'Noise Cancelling: Real-Time Adaptive Noise Reduction
Drivers: 10mm Coaxial Dual-Driver Architecture
Battery: 10 Hours per charge / 50 Hours total case
Fast Charge: 10 mins charge = 4 hours playtime
Bluetooth: 5.3 with Multipoint Dual Device Pairing
Water Rating: IPX5 Splash Resistance', 0),
(21, 5, '2', 'sumthin GaNPrime 67W 3-Port Desktop Wall Charger', 'sumthin-ganprime-67w-charger', 'Next-generation GaN technology packed into an ultra-compact body with 2 USB-C and 1 USB-A ports, real-time dynamic thermal monitoring.', 229.00, 50, 5, 18, 'Technology: Gallium Nitride (GaN III) Generation
Total Power: 67W High-Speed Output
Ports: 2x USB Type-C, 1x USB Type-A
Smart Allocation: PowerIQ 4.0 Dynamic Distribution
Safety: ActiveShield 2.0 Temperature Monitoring
Prongs: Compact Foldable Malaysia / UK 3-Pin Plug', 1),
(22, 5, '2', 'sumthin GaNStation 100W 4-Port Fast Power Adapter', 'sumthin-ganstation-100w-charger', 'All-in-one desktop charging hub delivering a full 100W PD single port output to charge MacBooks, tablets, and phones simultaneously.', 269.00, 35, 5, 24, 'Max Single Port: 100W Max USB-PD 3.0
Ports: 3x USB-C, 1x USB-A
Charging: Full 100W output for MacBook Pro 14" & 16"
Efficiency: 95% Thermal Power Conversion
Certifications: CE, FCC, RoHS, SIRIM Certified
Protection: Short Circuit, Over-Current, Surge Guard', 0),
(23, 5, '1', 'sumthin MiniGaN 65W Foldable Travel Charger', 'sumthin-minigan-65w-charger', 'Pocket-friendly fast charger with folding prongs, dual Type-C outputs, and intelligent power distribution for travelers.', 119.00, 60, 5, 12, 'Output Power: 65W Max Single Port Output
Dimensions: 38mm x 32mm x 48mm (Thumb Size)
Ports: 2x USB-C PD 3.0 Ports
Fast Protocols: PD 3.0, PPS, QC 4+, AFC, FCP
Weight: 92g Ultra-Lightweight Travel Companion
Housing: Fireproof Polycarbonate V0 Grade', 0),
(24, 5, '4', 'sumthin UltraBraid 140W USB-C Heavy-Duty Cable (1.8m)', 'sumthin-ultrabraid-140w-cable', 'Armor-braided bio-based nylon USB-C to USB-C cable certified for 140W PD charging with E-Marker smart chipset and 35,000-bend durability.', 89.00, 80, 5, 36, 'Power Rating: 140W (28V / 5A) USB PD 3.1 EPR
Chipset: Integrated E-Marker Smart Chip
Material: High-Tenacity Braided Kevlar Core
Durability: 35,000+ Bend Tested Strain Relief
Data Transfer: 480Mbps USB 2.0 Sync
Length: 1.8 Meters (6 Feet) with Silicone Tie', 0),
(25, 5, '1', 'sumthin FlexSilicone 240W PD 3.1 Soft-Touch Cable (2m)', 'sumthin-flexsilicone-240w-cable', 'Skin-friendly food-grade liquid silicone cable supporting ultra-high 240W power delivery, tangle-free flexibility, and 480Mbps data sync.', 59.00, 90, 5, 24, 'Power Rating: 240W (48V / 5A) Ultra-High Spec
Material: Food-Grade Liquid Graphene Silicone
Texture: Ultra-Soft Tangle-Free Silk Touch
Durability: Remains Flexible in -20°C to 100°C
Connector: Zinc-Alloy Mirror Finish Shells
Length: 2.0 Meters (6.6 Feet)', 0),
(26, 6, '4', 'sumthin Vision 4K Ultra HD HDR Streaming Webcam', 'sumthin-vision-4k-webcam', 'Stunning 4K Ultra HD video clarity at 30fps or 1080p at 60fps with HDR light optimization, AI facial tracking, and dual noise-cancelling mics.', 829.00, 16, 5, 24, 'Resolution: 4K UHD @ 30fps / 1080p @ 60fps
Sensor: 8.5MP Sony STARVIS CMOS Sensor
Optics: F/2.0 Large Aperture 5-Element Glass Lens
Field of View: Adjustable 65° / 78° / 90° FOV
AI Features: Auto-Framing & Facial Tracking
Microphone: Dual Omnidirectional with AI Noise Cancelling', 1),
(27, 6, '2', 'sumthin StreamCam 1080p 60FPS Pro Glass Lens Webcam', 'sumthin-streamcam-1080p-webcam', 'Sharp Full HD 1080p at 60 frames per second with premium 5-element optical glass lens, auto-focus, and dual stereo sound recording.', 329.00, 40, 5, 12, 'Resolution: Full HD 1080p at Smooth 60 FPS
Lens: Premium Multi-Coated F/2.0 Glass Lens
Focus: Fast Smart Autofocus & Facial Exposure
Mounting: Monitor Clamp & 1/4" Tripod Mount
Connection: USB-C 3.1 Direct High-Speed Cable
Orientation: Horizontal Landscape & 9:16 Vertical Video', 0),
(28, 6, '3', 'sumthin StudioLight Pro Adaptive Sensor Webcam', 'sumthin-studiolight-pro-webcam', 'Ultra-sensitive 1/2.8-inch CMOS star-light imaging sensor that captures crisp color video even in dark room ambient lighting conditions.', 699.00, 14, 5, 24, 'Sensor: 1/2.8-inch Ultra-Sensitive Low-Light Sensor
Dynamic Range: Real-Time HDR Live Compensation
Aperture: Ultra-Fast F/1.8 Low-Light Optics
Microphones: Stereo Array with Acoustic Echo Cancellation
Privacy: Magnetic Privacy Cover Included
Software: sumthin Studio Companion App Support', 0),
(29, 6, '1', 'sumthin Clarity 2K USB Webcam with Privacy Shutter', 'sumthin-clarity-2k-webcam', 'Sharp 2K QHD video resolution, integrated physical sliding privacy shutter, wide 95-degree field-of-view, and universal tripod mounting.', 259.00, 30, 5, 12, 'Resolution: 2K QHD (2560 x 1440) @ 30 FPS
Privacy: Integrated Hardware Sliding Privacy Shutter
FOV: 95-Degree Wide Angle Lens
Microphone: Noise-Cancelling Digital Mic
Plug & Play: Driverless UVC Standard USB-A
Rotation: 360-Degree Swivel Base', 0),
(30, 6, '3', 'sumthin BoomPro 360-Degree Aluminum Studio Arm', 'sumthin-boompro-studio-arm', 'Heavy-duty aircraft grade aluminum microphone and webcam suspension boom arm with internal cable channels and 360-degree smooth fluid rotation.', 139.00, 45, 5, 24, 'Material: Aircraft-Grade Extruded Aluminum Alloy
Rotation: Full 360-Degree Fluid Swivel with Tension Knobs
Cable Management: Hidden Integrated Cable Channels
Payload Capacity: Supports Microphones & Cameras up to 1.5kg
Desk Clamp: Heavy-Duty C-Clamp (Max 60mm Desk Thickness)
Threading: Standard 3/8" & 5/8" and 1/4" Camera Adapters', 0);

-- 5. Insert Product Photos (30 primary photos + 10 extra slider photos for 5 showcase products)
INSERT INTO `product_photo` (`id`, `product_id`, `photo_url`, `is_primary`, `sort_order`) VALUES
(1, 1, 'sumthin_volt24k.png', 1, 0),
(2, 2, 'sumthin_blade100w.png', 1, 0),
(3, 3, 'sumthin_nexcore145w.png', 1, 0),
(4, 4, 'sumthin_magsnap10k.png', 1, 0),
(5, 5, 'sumthin_pocketmini.png', 1, 0),
(6, 6, 'sumthin_keypro_q1.png', 1, 0),
(7, 7, 'sumthin_tactile_k2.png', 1, 0),
(8, 8, 'sumthin_lowprofile_s.png', 1, 0),
(9, 9, 'sumthin_strike_apex.png', 1, 0),
(10, 10, 'sumthin_creator_v1.png', 1, 0),
(11, 11, 'sumthin_glide_master.png', 1, 0),
(12, 12, 'sumthin_swift_v3.png', 1, 0),
(13, 13, 'sumthin_zeroair.png', 1, 0),
(14, 14, 'sumthin_basilisk.png', 1, 0),
(15, 15, 'sumthin_studio_m3.png', 1, 0),
(16, 16, 'sumthin_puretone_anc.png', 1, 0),
(17, 17, 'sumthin_buds_pro.png', 1, 0),
(18, 18, 'sumthin_aerovoice.png', 1, 0),
(19, 19, 'sumthin_spaceone.png', 1, 0),
(20, 20, 'sumthin_liberty_anc.png', 1, 0),
(21, 21, 'sumthin_ganprime_67w.png', 1, 0),
(22, 22, 'sumthin_ganstation_100w.png', 1, 0),
(23, 23, 'sumthin_minigan_65w.png', 1, 0),
(24, 24, 'sumthin_ultrabraid_140w.png', 1, 0),
(25, 25, 'sumthin_flexsilicone_240w.png', 1, 0),
(26, 26, 'sumthin_vision_4k.png', 1, 0),
(27, 27, 'sumthin_streamcam_1080p.png', 1, 0),
(28, 28, 'sumthin_studiolight_pro.png', 1, 0),
(29, 29, 'sumthin_clarity_2k.png', 1, 0),
(30, 30, 'sumthin_boompro_arm.png', 1, 0),
(31, 1, 'sumthin_blade100w.png', 0, 1),
(32, 1, 'sumthin_nexcore145w.png', 0, 2),
(33, 6, 'sumthin_tactile_k2.png', 0, 1),
(34, 6, 'sumthin_lowprofile_s.png', 0, 2),
(35, 11, 'sumthin_swift_v3.png', 0, 1),
(36, 11, 'sumthin_zeroair.png', 0, 2),
(37, 16, 'sumthin_buds_pro.png', 0, 1),
(38, 16, 'sumthin_spaceone.png', 0, 2),
(39, 21, 'sumthin_ganstation_100w.png', 0, 1),
(40, 21, 'sumthin_minigan_65w.png', 0, 2);

-- 6. Insert Addresses (21 addresses for members 3-18; members 3-7 have 2 addresses)
INSERT INTO `address` (`id`, `user_id`, `recipient_name`, `phone`, `address_line1`, `address_line2`, `city`, `state`, `postal_code`, `is_default`) VALUES
(1, 3, 'Alice Tan', '016-1122334', 'No. 12, Jalan Genting Kelang', 'Setapak', 'Kuala Lumpur', 'Wilayah Persekutuan', '53300', 1),
(2, 4, 'Bob Lee', '017-2233445', 'Unit 8-2, Residensi PV15', 'Danau Kota', 'Kuala Lumpur', 'Wilayah Persekutuan', '53300', 1),
(3, 5, 'Charlie Wong', '018-3344556', '77, Lorong Lembah Permai 3', 'Tanjung Bungah', 'George Town', 'Penang', '11200', 1),
(4, 6, 'David Lim', '019-4455667', '25, Jalan SS 21/37', 'Damansara Utama', 'Petaling Jaya', 'Selangor', '47400', 1),
(5, 7, 'Emily Chen', '012-5566778', '88, Jalan Sutera Tanjung 8/3', 'Taman Sutera Utama', 'Skudai', 'Johor', '81300', 1),
(6, 8, 'Fiona Teo', '013-6677889', '14, Jalan Melaka Raya 12', 'Taman Melaka Raya', 'Melaka', 'Melaka', '75000', 1),
(7, 9, 'George Koh', '014-7788990', '52, Jalan Canning Estate', 'Taman Ipoh', 'Ipoh', 'Perak', '31400', 1),
(8, 10, 'Hannah Ng', '011-8899001', '102, Lebuh Chulia', 'Georgetown', 'George Town', 'Penang', '10200', 1),
(9, 11, 'Ian Low', '016-9900112', '31, Jalan USJ 10/1G', 'Taipan Business Centre', 'Subang Jaya', 'Selangor', '47620', 1),
(10, 12, 'Jessica Tay', '017-0011223', 'A-15-3, Suria KLCC Condominium', 'Jalan Ampang', 'Kuala Lumpur', 'Wilayah Persekutuan', '50088', 1),
(11, 13, 'Kevin Yap', '018-1234987', '45, Jalan Molek 1/29', 'Taman Molek', 'Johor Bahru', 'Johor', '81100', 1),
(12, 14, 'Lily Chua', '019-2345876', '18, Tabuan Heights', 'Jalan Song', 'Kuching', 'Sarawak', '93350', 1),
(13, 15, 'Marcus Heng', '012-3456987', '6, Luyang Phase 6', 'Jalan Damai', 'Kota Kinabalu', 'Sabah', '88300', 1),
(14, 16, 'Nicole Fong', '013-4567890', '29, Jalan Teluk Sisek', 'Taman Kuantan', 'Kuantan', 'Pahang', '25000', 1),
(15, 17, 'Oliver See', '014-5678901', '7, Jalan Tropicana Selatan', 'Tropicana Gold & Country Resort', 'Petaling Jaya', 'Selangor', '47410', 1),
(16, 18, 'Patricia Sim', '011-6789012', 'B-12-08, The Fennel Sentul East', 'Jalan Sinar', 'Kuala Lumpur', 'Wilayah Persekutuan', '51100', 1),
(17, 3, 'Alice Tan (Office)', '016-1122334', 'Level 18, Menara LGB', '1, Jalan Wan Kadir, TTDI', 'Kuala Lumpur', 'Wilayah Persekutuan', '60000', 0),
(18, 4, 'Bob Lee (Studio)', '017-2233445', 'Unit 3A-01, Oval Damansara', '685, Jalan Damansara', 'Kuala Lumpur', 'Wilayah Persekutuan', '60000', 0),
(19, 5, 'Charlie Wong (Family)', '018-3344556', '12, Jalan Sultan Badlishah', 'Bandar Alor Setar', 'Alor Setar', 'Kedah', '05000', 0),
(20, 6, 'David Lim (Branch Office)', '019-4455667', 'Unit C-3-1, Sunway Geo Avenue', 'Jalan Lagoon Selatan', 'Bandar Sunway', 'Selangor', '47500', 0),
(21, 7, 'Emily Chen (Kulai Warehouse)', '012-5566778', 'Lot 402, Jalan SME 1', 'Kawasan Perindustrian Indahpura', 'Kulai', 'Johor', '81000', 0);

-- 7. Insert Vouchers (6 vouchers: percentage with max discount, fixed, expired, fully used, inactive, high min_spend)
INSERT INTO `voucher` (`id`, `code`, `discount_type`, `discount_value`, `min_spend`, `max_discount`, `usage_limit`, `used_count`, `is_active`, `expires_at`) VALUES
(1, 'SUMTHIN10', 'percentage', 10.00, 100.00, '50.00', 200, 14, 1, '2027-12-31 23:59:59'),
(2, 'TECHSAVE30', 'fixed', 30.00, 150.00, '30.00', 100, 8, 1, '2027-12-31 23:59:59'),
(3, 'EXPSAVE20', 'fixed', 20.00, 100.00, '20.00', 50, 22, 1, '2025-08-31 23:59:59'),
(4, 'EARLYBIRD', 'percentage', 15.00, 80.00, '40.00', 50, 50, 1, '2027-12-31 23:59:59'),
(5, 'VIPSECRET', 'fixed', 100.00, 500.00, '100.00', 30, 0, 0, '2027-12-31 23:59:59'),
(6, 'TITAN80', 'fixed', 80.00, 800.00, '80.00', 50, 4, 1, '2027-12-31 23:59:59');

-- 8. Insert 25 Sample Orders (Delivered, Shipped, Processing, Pending, Cancelled across 60 days)
INSERT INTO `order` (`id`, `order_no`, `user_id`, `address_id`, `total_amount`, `discount_amount`, `final_amount`, `status`, `payment_status`, `payment_method`, `tracking_no`, `created_at`) VALUES
(1, 'ORD-20260918-001', 3, '1', 1127.00, 50.00, 1077.00, 'Delivered', 'Paid', 'stripe', 'DHL-MY-10293841', '2026-09-18 10:20:00'),
(2, 'ORD-20260922-002', 4, '2', 1417.00, 30.00, 1387.00, 'Delivered', 'Paid', 'stripe', 'JNT-MY-88291023', '2026-09-22 14:15:00'),
(3, 'ORD-20260927-003', 5, '3', 1816.00, 0.00, 1816.00, 'Delivered', 'Paid', 'stripe', 'POS-MY-77223344', '2026-09-27 16:30:00'),
(4, 'ORD-20261002-004', 6, '4', 1417.00, 50.00, 1367.00, 'Delivered', 'Paid', 'stripe', 'DHL-MY-44556677', '2026-10-02 09:45:00'),
(5, 'ORD-20261008-005', 7, '5', 1547.00, 0.00, 1547.00, 'Delivered', 'Paid', 'stripe', 'JNT-MY-33994411', '2026-10-08 11:10:00'),
(6, 'ORD-20261014-006', 8, '6', 688.00, 30.00, 658.00, 'Delivered', 'Paid', 'stripe', 'DHL-MY-99881122', '2026-10-14 15:20:00'),
(7, 'ORD-20261019-007', 9, '7', 968.00, 0.00, 968.00, 'Delivered', 'Paid', 'stripe', 'POS-MY-55443322', '2026-10-19 13:05:00'),
(8, 'ORD-20261024-008', 10, '8', 568.00, 30.00, 538.00, 'Delivered', 'Paid', 'stripe', 'JNT-MY-11224455', '2026-10-24 17:40:00'),
(9, 'ORD-20261028-009', 11, '9', 528.00, 0.00, 528.00, 'Delivered', 'Paid', 'stripe', 'DHL-MY-88776655', '2026-10-28 10:15:00'),
(10, 'ORD-20261101-010', 12, '10', 608.00, 50.00, 558.00, 'Delivered', 'Paid', 'stripe', 'POS-MY-99001122', '2026-11-01 12:00:00'),
(11, 'ORD-20261104-011', 13, '11', 688.00, 30.00, 658.00, 'Shipped', 'Paid', 'stripe', 'JNT-MY-66778899', '2026-11-04 14:30:00'),
(12, 'ORD-20261106-012', 14, '12', 899.00, 50.00, 849.00, 'Shipped', 'Paid', 'stripe', 'DHL-MY-22334455', '2026-11-06 16:20:00'),
(13, 'ORD-20261108-013', 15, '13', 668.00, 0.00, 668.00, 'Shipped', 'Paid', 'stripe', 'POS-MY-33445566', '2026-11-08 09:10:00'),
(14, 'ORD-20261109-014', 16, '14', 1399.00, 50.00, 1349.00, 'Shipped', 'Paid', 'stripe', 'JNT-MY-44556677', '2026-11-09 11:45:00'),
(15, 'ORD-20261110-015', 3, '17', 448.00, 0.00, 448.00, 'Shipped', 'Paid', 'stripe', 'DHL-MY-55667788', '2026-11-10 15:00:00'),
(16, 'ORD-20261111-016', 4, '18', 568.00, 30.00, 538.00, 'Processing', 'Paid', 'stripe', NULL, '2026-11-11 13:15:00'),
(17, 'ORD-20261112-017', 5, '19', 469.00, 0.00, 469.00, 'Processing', 'Paid', 'stripe', NULL, '2026-11-12 10:30:00'),
(18, 'ORD-20261112-018', 6, '20', 1099.00, 50.00, 1049.00, 'Processing', 'Paid', 'stripe', NULL, '2026-11-12 16:45:00'),
(19, 'ORD-20261113-019', 7, '21', 968.00, 0.00, 968.00, 'Processing', 'Paid', 'stripe', NULL, '2026-11-13 09:20:00'),
(20, 'ORD-20261113-020', 8, '6', 448.00, 0.00, 448.00, 'Pending', 'Unpaid', 'pay_later', NULL, '2026-11-13 14:10:00'),
(21, 'ORD-20261114-021', 9, '7', 59.00, 0.00, 74.00, 'Pending', 'Unpaid', 'pay_later', NULL, '2026-11-14 08:30:00'),
(22, 'ORD-20261114-022', 10, '8', 299.00, 0.00, 299.00, 'Pending', 'Unpaid', 'pay_later', NULL, '2026-11-14 11:00:00'),
(23, 'ORD-20261110-023', 11, '9', 349.00, 0.00, 349.00, 'Cancelled', 'Refunded', 'stripe', NULL, '2026-11-10 10:00:00'),
(24, 'ORD-20261112-024', 12, '10', 289.00, 0.00, 289.00, 'Cancelled', 'Refunded', 'stripe', NULL, '2026-11-12 11:30:00'),
(25, 'ORD-20261114-025', 13, '11', 89.00, 0.00, 104.00, 'Cancelled', 'Refunded', 'stripe', NULL, '2026-11-14 15:45:00');

-- 9. Insert Order Items (1-4 items per order, exact mathematical matching)
INSERT INTO `order_item` (`id`, `order_id`, `product_id`, `price`, `quantity`, `subtotal`) VALUES
(1, 1, 1, 599.00, 1, 599.00),
(2, 1, 11, 439.00, 1, 439.00),
(3, 1, 24, 89.00, 1, 89.00),
(4, 2, 6, 899.00, 1, 899.00),
(5, 2, 14, 289.00, 1, 289.00),
(6, 2, 21, 229.00, 1, 229.00),
(7, 3, 16, 1399.00, 1, 1399.00),
(8, 3, 2, 299.00, 1, 299.00),
(9, 3, 25, 59.00, 2, 118.00),
(10, 4, 7, 389.00, 1, 389.00),
(11, 4, 13, 699.00, 1, 699.00),
(12, 4, 27, 329.00, 1, 329.00),
(13, 5, 17, 1099.00, 1, 1099.00),
(14, 5, 4, 329.00, 1, 329.00),
(15, 5, 23, 119.00, 1, 119.00),
(16, 6, 8, 469.00, 1, 469.00),
(17, 6, 15, 219.00, 1, 219.00),
(18, 7, 26, 829.00, 1, 829.00),
(19, 7, 30, 139.00, 1, 139.00),
(20, 8, 10, 299.00, 1, 299.00),
(21, 8, 22, 269.00, 1, 269.00),
(22, 9, 19, 399.00, 1, 399.00),
(23, 9, 5, 129.00, 1, 129.00),
(24, 10, 20, 349.00, 1, 349.00),
(25, 10, 29, 259.00, 1, 259.00),
(26, 11, 1, 599.00, 1, 599.00),
(27, 11, 24, 89.00, 1, 89.00),
(28, 12, 6, 899.00, 1, 899.00),
(29, 13, 11, 439.00, 1, 439.00),
(30, 13, 21, 229.00, 1, 229.00),
(31, 14, 16, 1399.00, 1, 1399.00),
(32, 15, 7, 389.00, 1, 389.00),
(33, 15, 25, 59.00, 1, 59.00),
(34, 16, 2, 299.00, 1, 299.00),
(35, 16, 22, 269.00, 1, 269.00),
(36, 17, 8, 469.00, 1, 469.00),
(37, 18, 17, 1099.00, 1, 1099.00),
(38, 19, 26, 829.00, 1, 829.00),
(39, 19, 30, 139.00, 1, 139.00),
(40, 20, 4, 329.00, 1, 329.00),
(41, 20, 23, 119.00, 1, 119.00),
(42, 21, 25, 59.00, 1, 59.00),
(43, 22, 10, 299.00, 1, 299.00),
(44, 23, 20, 349.00, 1, 349.00),
(45, 24, 14, 289.00, 1, 289.00),
(46, 25, 24, 89.00, 1, 89.00);

-- 10. Insert Payments (One record for each Paid order)
INSERT INTO `payment` (`id`, `order_id`, `stripe_session_id`, `amount`, `currency`, `status`, `created_at`, `paid_at`) VALUES
(1, 1, 'cs_test_seed_001', 1077.00, 'myr', 'Paid', '2026-09-18 10:20:00', '2026-09-18 10:20:00'),
(2, 2, 'cs_test_seed_002', 1387.00, 'myr', 'Paid', '2026-09-22 14:15:00', '2026-09-22 14:15:00'),
(3, 3, 'cs_test_seed_003', 1816.00, 'myr', 'Paid', '2026-09-27 16:30:00', '2026-09-27 16:30:00'),
(4, 4, 'cs_test_seed_004', 1367.00, 'myr', 'Paid', '2026-10-02 09:45:00', '2026-10-02 09:45:00'),
(5, 5, 'cs_test_seed_005', 1547.00, 'myr', 'Paid', '2026-10-08 11:10:00', '2026-10-08 11:10:00'),
(6, 6, 'cs_test_seed_006', 658.00, 'myr', 'Paid', '2026-10-14 15:20:00', '2026-10-14 15:20:00'),
(7, 7, 'cs_test_seed_007', 968.00, 'myr', 'Paid', '2026-10-19 13:05:00', '2026-10-19 13:05:00'),
(8, 8, 'cs_test_seed_008', 538.00, 'myr', 'Paid', '2026-10-24 17:40:00', '2026-10-24 17:40:00'),
(9, 9, 'cs_test_seed_009', 528.00, 'myr', 'Paid', '2026-10-28 10:15:00', '2026-10-28 10:15:00'),
(10, 10, 'cs_test_seed_010', 558.00, 'myr', 'Paid', '2026-11-01 12:00:00', '2026-11-01 12:00:00'),
(11, 11, 'cs_test_seed_011', 658.00, 'myr', 'Paid', '2026-11-04 14:30:00', '2026-11-04 14:30:00'),
(12, 12, 'cs_test_seed_012', 849.00, 'myr', 'Paid', '2026-11-06 16:20:00', '2026-11-06 16:20:00'),
(13, 13, 'cs_test_seed_013', 668.00, 'myr', 'Paid', '2026-11-08 09:10:00', '2026-11-08 09:10:00'),
(14, 14, 'cs_test_seed_014', 1349.00, 'myr', 'Paid', '2026-11-09 11:45:00', '2026-11-09 11:45:00'),
(15, 15, 'cs_test_seed_015', 448.00, 'myr', 'Paid', '2026-11-10 15:00:00', '2026-11-10 15:00:00'),
(16, 16, 'cs_test_seed_016', 538.00, 'myr', 'Paid', '2026-11-11 13:15:00', '2026-11-11 13:15:00'),
(17, 17, 'cs_test_seed_017', 469.00, 'myr', 'Paid', '2026-11-12 10:30:00', '2026-11-12 10:30:00'),
(18, 18, 'cs_test_seed_018', 1049.00, 'myr', 'Paid', '2026-11-12 16:45:00', '2026-11-12 16:45:00'),
(19, 19, 'cs_test_seed_019', 968.00, 'myr', 'Paid', '2026-11-13 09:20:00', '2026-11-13 09:20:00'),
(20, 23, 'cs_test_seed_023', 349.00, 'myr', 'Refunded', '2026-11-10 10:00:00', '2026-11-10 10:05:00'),
(21, 24, 'cs_test_seed_024', 289.00, 'myr', 'Refunded', '2026-11-12 11:30:00', '2026-11-12 11:35:00'),
(22, 25, 'cs_test_seed_025', 104.00, 'myr', 'Refunded', '2026-11-14 15:45:00', '2026-11-14 15:50:00');

-- 11. Insert 25 Customer Reviews (ONLY from members with Delivered orders containing the product)
INSERT INTO `review` (`id`, `product_id`, `user_id`, `order_id`, `rating`, `comment`, `created_at`) VALUES
(1, 1, 3, 1, 5, 'The sumthin Volt 24K is phenomenal! Charges my MacBook Pro 16" at full 140W speed without heating up. The digital display is super convenient.', '2026-09-20 18:30:00'),
(2, 11, 3, 1, 5, 'Best ergonomic productivity mouse ever made. Whisper-quiet clicks and the MagSpeed wheel is pure magic.', '2026-09-20 18:35:00'),
(3, 24, 3, 1, 4, 'Very sturdy braided cable. Handles 140W power delivery easily and the silicone tie keeps it neat in my bag.', '2026-09-20 18:40:00'),
(4, 6, 4, 2, 5, 'Exceptional craftsmanship! CNC aluminum body feels heavy and solid. The Jupiter Banana switches sound deep and thocky right out of the box.', '2026-09-24 10:15:00'),
(5, 14, 4, 2, 4, 'Great ergonomic shape for palm grip. The adjustable scroll tension is fantastic for gaming and coding alike.', '2026-09-24 10:20:00'),
(6, 21, 4, 2, 4, 'Compact GaN charger that easily handles laptop and phone together. Stays relatively cool under heavy load.', '2026-09-24 10:25:00'),
(7, 16, 5, 3, 5, 'Class-leading ANC! Blocks out flight cabin drone and coffee shop noise completely. LDAC high-res audio is punchy and detailed.', '2026-09-29 14:00:00'),
(8, 2, 5, 3, 4, 'Super slim for a 20,000mAh battery. Slips right into my laptop briefcase pocket alongside my ultrabook.', '2026-09-29 14:10:00'),
(9, 25, 5, 3, 3, 'Extremely soft and flexible silicone feel. Does not tangle, though it attracts a bit of dust over time.', '2026-09-29 14:15:00'),
(10, 7, 6, 4, 5, 'The Bluetooth switching between my Mac Mini and work laptop is instant. Tactile switches are quiet enough for video meetings.', '2026-10-04 16:20:00'),
(11, 13, 6, 4, 5, 'Incredible ultralight mouse under 60g! Zero sensor lag, clicks are crisp, and the battery lasts weeks.', '2026-10-04 16:25:00'),
(12, 27, 6, 4, 2, 'Video quality is decent at 60fps, but low-light performance is grainy without an external ring light.', '2026-10-04 16:30:00'),
(13, 17, 7, 5, 5, 'The ANC on these earbuds rivals full over-ear headphones. Call quality in windy outdoor conditions is remarkably clear.', '2026-10-10 11:45:00'),
(14, 4, 7, 5, 4, 'Strong magnetic hold on Qi2 phones. The kickstand is metallic and very sturdy for watching videos during lunch.', '2026-10-10 11:50:00'),
(15, 23, 7, 5, 3, 'Very small and travel-friendly. However, it gets warm when charging two phones simultaneously at max wattage.', '2026-10-10 11:55:00'),
(16, 8, 8, 6, 5, 'Typing on this low-profile scissor keyboard is effortless. The proximity smart backlighting feels very futuristic.', '2026-10-16 19:10:00'),
(17, 15, 8, 6, 4, 'Clean minimalist look on my desk. PAW3395 tracking is flawless across both mousepads and bare wooden desks.', '2026-10-16 19:15:00'),
(18, 26, 9, 7, 5, 'Crisp 4K HDR picture quality! AI face tracking keeps me centered during client presentations. Studio-grade webcam.', '2026-10-21 15:30:00'),
(19, 30, 9, 7, 4, 'Very solid aluminum arm. The hidden cable routing channels keep my setup completely clean with zero loose wires.', '2026-10-21 15:35:00'),
(20, 10, 10, 8, 4, 'The frosted acrylic aesthetic with south-facing RGB looks stunning at night. Pre-lubed stabilizers prevent rattle.', '2026-10-26 12:40:00'),
(21, 22, 10, 8, 4, '100W fast charger powers my entire desktop workstation. Having 4 ports eliminated all my messy power strips.', '2026-10-26 12:45:00'),
(22, 19, 11, 9, 4, 'Comfortable memory foam ear cushions for long listening sessions. ANC works well on trains and public buses.', '2026-10-30 17:15:00'),
(23, 5, 11, 9, 4, 'Tiny pocket power bank that snaps onto the back of my phone without covering the camera lenses. Perfect emergency backup.', '2026-10-30 17:20:00'),
(24, 20, 12, 10, 4, 'Great bass and clean mids for compact earbuds. Fast charging gives 4 hours of music in just 10 minutes.', '2026-11-03 14:00:00'),
(25, 29, 12, 10, 1, 'Physical privacy shutter is nice, but the microphone picks up too much background room echo during conference calls.', '2026-11-03 14:05:00');

-- 12. Insert 20 Wishlist Items
INSERT INTO `wishlist` (`id`, `user_id`, `product_id`) VALUES
(1, 3, 8),
(2, 3, 16),
(3, 4, 1),
(4, 4, 13),
(5, 5, 6),
(6, 5, 11),
(7, 6, 9),
(8, 6, 26),
(9, 7, 2),
(10, 7, 18),
(11, 8, 3),
(12, 8, 21),
(13, 9, 7),
(14, 9, 28),
(15, 10, 12),
(16, 10, 24),
(17, 11, 14),
(18, 12, 17),
(19, 13, 19),
(20, 14, 22);

-- 13. Insert 10 Email Audit Logs
INSERT INTO `email_log` (`id`, `sent_by`, `type`, `recipient`, `subject`, `body`, `status`, `sent_at`) VALUES
(1, NULL, 'auth', 'alice@gmail.com', 'Welcome to sumthin - Verify Your Account', '[redacted - contains secure link]', 'Sent', '2026-09-18 09:00:00'),
(2, '1', 'order', 'alice@gmail.com', 'Order Confirmed - ORD-20260918-001', 'Hi Alice,

Thank you for your order ORD-20260918-001. We have received your payment of RM 1,077.00.

Best regards,
sumthin Logistics', 'Sent', '2026-09-18 10:21:00'),
(3, '1', 'order', 'bob@gmail.com', 'Your Order ORD-20260922-002 Has Been Dispatched', 'Hi Bob,

Your order ORD-20260922-002 has shipped via J&T Express tracking number JNT-MY-88291023.

Best regards,
sumthin Fulfillment', 'Sent', '2026-09-23 09:15:00'),
(4, NULL, 'auth', 'charlie@gmail.com', 'Password Reset Request', '[redacted - contains secure link]', 'Sent', '2026-10-01 14:10:00'),
(5, '1', 'marketing', 'david@gmail.com', 'Exclusive Gadget Deal for You, David Lim!', 'Hi David Lim,

We noticed you have an eye for great tech gadgets! Enjoy 10% off with promo code SUMTHIN10 on your next purchase.

Warm regards,
The sumthin Team', 'Sent', '2026-10-05 11:00:00'),
(6, '2', 'support', 'emily@gmail.com', 'Inquiry Response: sumthin Warranty Coverage', 'Hi Emily,

All sumthin hardware products include 12 to 24 months 1-to-1 replacement warranty support across Malaysia.

Support Desk', 'Sent', '2026-10-10 16:30:00'),
(7, '1', 'order', 'fiona@gmail.com', 'Order Confirmed - ORD-20261014-006', 'Hi Fiona,

Your order ORD-20261014-006 has been placed successfully. Payment of RM 658.00 verified.

Fulfillment Team', 'Sent', '2026-10-14 15:21:00'),
(8, NULL, 'system', 'george@gmail.com', 'Security Notice: New Login Detected', 'Hi George,

A new login session was established on your account from IP 115.164.88.12 on 2026-10-20.

sumthin Security', 'Sent', '2026-10-20 18:05:00'),
(9, '1', 'order', 'ian@gmail.com', 'Order Cancelled - ORD-20261110-023', 'Hi Ian,

Your order ORD-20261110-023 has been cancelled and RM 349.00 has been refunded to your original payment method.

Billing Team', 'Sent', '2026-11-10 10:05:00'),
(10, '2', 'marketing', 'jessica@gmail.com', 'Thank You for Shopping at sumthin, Jessica Tay!', 'Hi Jessica Tay,

Thank you for choosing sumthin for your tech gadget essentials! Leave a review to share your experience.

The sumthin Team', 'Sent', '2026-11-12 14:00:00');

-- 14. Insert 3 Email Templates (Promotion, Order Delay Apology, Thank You with {name} placeholder)
INSERT INTO `email_template` (`id`, `name`, `subject`, `body`, `created_at`) VALUES
(1, 'Promotion', 'Exclusive Tech Gadget Deal Just for You, {name}!', 'Hi {name},

We noticed you have an eye for great tech gadgets! Upgrade your daily workstation setup today and enjoy exclusive savings with voucher code TECHSAVE30 at checkout.

Shop the collection now at sumthin.

Warm regards,
The sumthin Team', '2026-09-01 10:00:00'),
(2, 'Order Delay Apology', 'Notice Regarding Your Order Shipment - Apology for Delay', 'Dear {name},

We sincerely apologize for the delay in fulfilling your recent order. Due to high shipment volume, dispatch has been delayed slightly. Our logistics team is prioritizing your parcel to ensure delivery within the next 48 hours.

Thank you for your patience and understanding.

sumthin Customer Support', '2026-09-01 10:00:00'),
(3, 'Thank You', 'Thank You for Shopping with sumthin, {name}!', 'Hello {name},

Thank you for choosing sumthin for your tech gadget essentials! Your trust in our quality products means the world to us.

If you have a moment, please consider leaving a review on our store to help other tech enthusiasts.

Best regards,
The sumthin Team', '2026-09-01 10:00:00');

-- 15. Insert 6 Live Support Chat Messages (One conversation for member Alice Tan)
INSERT INTO `chat_message` (`id`, `member_id`, `sender_role`, `message`, `is_read`, `created_at`) VALUES
(1, 3, 'Member', 'Hi sumthin support! Does the Volt 24K power bank come with a fast-charging cable in the box?', 1, '2026-11-14 09:30:00'),
(2, 3, 'Admin', 'Hello Alice! Yes, the Volt 24K includes a certified 140W USB-C PD 3.1 braided cable (1m) inside the packaging.', 1, '2026-11-14 09:32:00'),
(3, 3, 'Member', 'Awesome! Can it fast charge both my MacBook Pro 16-inch and iPhone 16 simultaneously?', 1, '2026-11-14 09:34:00'),
(4, 3, 'Admin', 'Absolutely! It intelligently splits 100W to your MacBook and 30W to your iPhone without overheating.', 1, '2026-11-14 09:36:00'),
(5, 3, 'Member', 'That is exactly what I need for traveling. Placing my order now, thank you for the quick help!', 1, '2026-11-14 09:38:00'),
(6, 3, 'Admin', 'You are most welcome, Alice! We will have your order packed and dispatched today. Have a wonderful day!', 1, '2026-11-14 09:39:00');

-- 16. Insert Login Attempts (Security audit logs for locked member Patricia Sim)
INSERT INTO `login_attempt` (`id`, `email`, `ip_address`, `attempted_at`) VALUES
(1, 'patricia@gmail.com', '115.164.45.10', '2026-11-14 20:10:00'),
(2, 'patricia@gmail.com', '115.164.45.10', '2026-11-14 20:11:00'),
(3, 'patricia@gmail.com', '115.164.45.10', '2026-11-14 20:12:00');

-- 17. Insert System Settings
INSERT INTO `setting` (`id`, `key`, `value`, `label`, `description`) VALUES
(1, 'store_name', 'sumthin', 'Store Brand Name', 'Public name of the gadget store displayed in headers and logos.'),
(2, 'store_tagline', 'Curated Tech Gadgets & Daily Essentials', 'Store Tagline', 'Subtitle displayed across hero sections and invoices.'),
(3, 'announcement_bar', '⚡ Complimentary Express Shipping on all Malaysian orders over RM150', 'Top Announcement Bar Text', 'Marketing notification bar displayed at the top of every page.'),
(4, 'announcement_enabled', '1', 'Show Announcement Bar', 'Toggle 1 to display the top banner, 0 to hide it.'),
(5, 'free_shipping_threshold', '150.00', 'Free Shipping Threshold (RM)', 'Minimum spend required to qualify for free express shipping.'),
(6, 'standard_shipping_fee', '15.00', 'Standard Shipping Fee (RM)', 'Flat courier fee applied to orders below free shipping threshold.'),
(7, 'support_email', 'support@sumthin.com', 'Customer Support Email', 'Contact email displayed to customers on invoices and help links.'),
(8, 'support_phone', '03-41450123', 'Customer Support Hotline', 'Hotline phone number displayed in footer and order receipts.'),
(9, 'max_login_fails', '3', 'Max Failed Login Attempts', 'Number of consecutive invalid logins before account is locked.'),
(10, 'lockout_minutes', '15', 'Lockout Duration (Minutes)', 'Duration an account remains temporarily locked after threshold.');
