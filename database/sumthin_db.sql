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
  `is_featured` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
  `tracking_no` VARCHAR(100) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
  CONSTRAINT `fk_item_order` FOREIGN KEY (`order_id`) REFERENCES `order` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_item_product` FOREIGN KEY (`product_id`) REFERENCES `product` (`id`) ON DELETE CASCADE
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
  CONSTRAINT `fk_review_order` FOREIGN KEY (`order_id`) REFERENCES `order` (`id`) ON DELETE SET NULL
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
  `token` VARCHAR(64) NOT NULL UNIQUE,
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
  `recipient` VARCHAR(100) NOT NULL,
  `subject` VARCHAR(255) NOT NULL,
  `body` TEXT NOT NULL,
  `status` ENUM('Sent', 'Failed') NOT NULL DEFAULT 'Sent',
  `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Table: chat_message (Live customer-admin chat)
-- ----------------------------------------------------------------------------
CREATE TABLE `chat_message` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `sender_id` INT NOT NULL,
  `receiver_id` INT NOT NULL,
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_chat_sender` FOREIGN KEY (`sender_id`) REFERENCES `user` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_chat_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `user` (`id`) ON DELETE CASCADE
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

-- 3. Insert Users (Admin: Admin123! | Members: Member123!)
INSERT INTO `user` (`id`, `email`, `password_hash`, `name`, `phone`, `role`, `photo`, `status`) VALUES
(1, 'admin@sumthin.com', '$2y$10$q3t4RuriSZuA7QAL0lCje.anBH9mtYSYww20kv8wAP0SFlHp9H6Pm', 'System Administrator', '012-3456789', 'Admin', 'default_user.png', 'Active'),
(2, 'staff@sumthin.com', '$2y$10$q3t4RuriSZuA7QAL0lCje.anBH9mtYSYww20kv8wAP0SFlHp9H6Pm', 'Staff Moderator', '012-9876543', 'Admin', 'default_user.png', 'Active'),
(3, 'alice@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Alice Tan', '016-1122334', 'Member', 'default_user.png', 'Active'),
(4, 'bob@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Bob Lee', '017-2233445', 'Member', 'default_user.png', 'Active'),
(5, 'charlie@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Charlie Wong', '018-3344556', 'Member', 'default_user.png', 'Active'),
(6, 'david@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'David Lim', '019-4455667', 'Member', 'default_user.png', 'Active'),
(7, 'emily@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Emily Chen', '012-5566778', 'Member', 'default_user.png', 'Active'),
(8, 'fiona@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Fiona Teo', '013-6677889', 'Member', 'default_user.png', 'Active'),
(9, 'george@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'George Koh', '014-7788990', 'Member', 'default_user.png', 'Active'),
(10, 'hannah@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Hannah Ng', '011-8899001', 'Member', 'default_user.png', 'Active'),
(11, 'ian@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Ian Low', '016-9900112', 'Member', 'default_user.png', 'Active'),
(12, 'jessica@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Jessica Tay', '017-0011223', 'Member', 'default_user.png', 'Active'),
(13, 'kevin@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Kevin Yap', '018-1234987', 'Member', 'default_user.png', 'Active'),
(14, 'lily@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Lily Chua', '019-2345876', 'Member', 'default_user.png', 'Active'),
(15, 'marcus@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Marcus Heng', '012-3456987', 'Member', 'default_user.png', 'Active'),
(16, 'nicole@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Nicole Fong', '013-4567890', 'Member', 'default_user.png', 'Active'),
(17, 'oliver@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Oliver See', '014-5678901', 'Member', 'default_user.png', 'Active'),
(18, 'patricia@gmail.com', '$2y$10$6x5cWt/kx4hGyn69a0p5/uJjRUbuSveEbCkKtMvFasum9WrE4ey9.', 'Patricia Sim', '011-6789012', 'Member', 'default_user.png', 'Active');

-- 4. Insert 30 sumthin-Branded Products
INSERT INTO `product` (`id`, `category_id`, `brand_id`, `name`, `slug`, `description`, `price`, `stock`, `is_featured`) VALUES
(1, 1, 4, 'sumthin Volt 24K (140W Two-Way GaN)', 'sumthin-volt-24k-power-bank', 'Flagship 24,000mAh portable power bank with 140W ultra-fast two-way USB-C PD 3.1 charging. Features an intelligent real-time digital OLED status display.', 599.00, 25, 1),
(2, 1, 2, 'sumthin Blade Ultra-Slim 100W Laptop Power Bank', 'sumthin-blade-100w-power-bank', 'Razor-thin 18mm aluminum alloy chassis packing 20,000mAh with 100W power delivery. Designed specifically to slip into laptop sleeves with MacBooks and Dell XPS.', 299.00, 40, 1),
(3, 1, 2, 'sumthin NexCore 145W Multi-Port 25,000mAh', 'sumthin-nexcore-145w-power-bank', 'Heavy-duty 3-port high capacity battery pack. Powers 2 laptops simultaneously with high-grade airline-certified battery cells.', 379.00, 18, 0),
(4, 1, 1, 'sumthin MagSnap 10,000mAh Magnetic Qi2 Battery', 'sumthin-magsnap-qi2-10000mah', 'Official Qi2 certified 15W high-speed magnetic wireless power bank with integrated zinc-alloy kickstand and smart battery level LED.', 329.00, 30, 0),
(5, 1, 1, 'sumthin PocketMini 6,000mAh Snap-On Wireless Pack', 'sumthin-pocketmini-6000mah', 'Featherweight everyday carry battery that snaps firmly onto modern smartphones with zero camera blockage and 20W wired fast charge backup.', 129.00, 50, 0),

(6, 2, 2, 'sumthin KeyPro Q1 Aluminum Custom Mechanical Keyboard', 'sumthin-keypro-q1-mechanical-keyboard', 'Precision CNC milled 6063 aluminum body with double-gasket acoustic dampening, hot-swappable tactile switches, and full QMK/VIA programmable firmware.', 899.00, 12, 1),
(7, 2, 1, 'sumthin Tactile K2 Wireless 75% Mechanical Keyboard', 'sumthin-tactile-k2-wireless-keyboard', 'Compact 75% wireless mechanical keyboard with multi-device Bluetooth 5.1 pairing, Mac & Windows physical switch, and 4000mAh battery life.', 389.00, 35, 0),
(8, 2, 3, 'sumthin LowProfile S Smart Wireless Quiet Keyboard', 'sumthin-lowprofile-s-keyboard', 'Ultra-thin ergonomic productivity keyboard with spherically-dished quiet scissor keys, proximity hand sensors, and dynamic smart backlighting.', 469.00, 28, 1),
(9, 2, 4, 'sumthin Strike Apex Pro RGB Gaming Mechanical Keyboard', 'sumthin-strike-apex-pro-keyboard', 'Competition-grade optical gaming keyboard featuring an interactive rotary command dial, magnetic memory foam wrist rest, and per-key RGB backlighting.', 999.00, 10, 0),
(10, 2, 1, 'sumthin Creator V1 Frosted Custom Mechanical Keyboard', 'sumthin-creator-v1-keyboard', 'Translucent frosted black aesthetic with sound-absorbing silicone pad, south-facing RGB, and screw-in PCB stabilizers for enthusiasts.', 299.00, 42, 0),

(11, 3, 3, 'sumthin Glide Master 3S Silent Ergonomic Mouse', 'sumthin-glide-master-3s-mouse', 'Industry-benchmark productivity mouse with whisper-quiet acoustic clicks, 8,000 DPI track-on-glass sensor, and electromagnetic MagSpeed scroll wheel.', 439.00, 45, 1),
(12, 3, 4, 'sumthin Swift V3 Pro 63g Wireless Ultralight Gaming Mouse', 'sumthin-swift-v3-pro-mouse', 'Featherlight 63-gram ergonomic esports mouse engineered with a 30,000 DPI optical sensor, Gen-3 switches, and flawless 90-hour battery life.', 649.00, 15, 0),
(13, 3, 4, 'sumthin ZeroAir Superlight Competitive Wireless Mouse', 'sumthin-zeroair-superlight-mouse', 'Sub-60g symmetrical competitive mouse with hybrid optical-mechanical switches and zero-smoothing frictionless PTFE gliding skates.', 699.00, 20, 1),
(14, 3, 1, 'sumthin Basilisk Ergonomic 11-Zone Chroma Mouse', 'sumthin-basilisk-mouse', 'Customizable ergonomic mouse featuring an adjustable resistance tilt-wheel, multi-function trigger paddle, and 11 addressable RGB lighting zones.', 289.00, 38, 0),
(15, 3, 3, 'sumthin Studio M3 Dual-Mode 26,000 DPI Optical Mouse', 'sumthin-studio-m3-mouse', 'Clean minimalist tri-mode wireless mouse (2.4G + BT 5.1 + Wired) featuring flagship PixArt PAW3395 optical sensor weighing only 79 grams.', 219.00, 25, 0),

(16, 4, 4, 'sumthin PureTone ANC Flagship Wireless Headphones', 'sumthin-puretone-anc-headphones', 'World-class active noise cancellation driven by dual dedicated processors and 8 microphones. Supports LDAC 24-bit Hi-Res audio with 30-hour battery life.', 1399.00, 15, 1),
(17, 4, 2, 'sumthin Buds Pro True Wireless Noise-Cancelling Earbuds', 'sumthin-buds-pro-earbuds', 'Compact studio earbuds featuring bespoke carbon dynamic drivers, bone-conduction voice clarity sensors, wireless charging case, and IPX4 water rating.', 1099.00, 22, 1),
(18, 4, 3, 'sumthin AeroVoice Pro Wireless Studio Headset', 'sumthin-aerovoice-pro-headset', 'Ultra-wideband broadcast microphone, titanium-coated 50mm acoustic drivers, and memory-foam breathable earcups for creators and competitive gaming.', 899.00, 14, 0),
(19, 4, 1, 'sumthin SpaceOne Adaptive ANC Bluetooth Headphones', 'sumthin-spaceone-headphones', 'Adaptive 2.0 active noise cancelling targeting human voice frequencies with 40-hour ANC playtime and foldable space-saving travel design.', 399.00, 32, 0),
(20, 4, 1, 'sumthin Liberty ANC Ultra-Compact True Wireless Buds', 'sumthin-liberty-anc-buds', 'Up to 98.5% background noise reduction, custom 11mm composite diaphragms, and 50 hours of total playback with rapid USB-C fast charging.', 349.00, 40, 0),

(21, 5, 2, 'sumthin GaNPrime 67W 3-Port Desktop Wall Charger', 'sumthin-ganprime-67w-charger', 'Next-generation GaN technology packed into an ultra-compact body with 2 USB-C and 1 USB-A ports, real-time dynamic thermal monitoring.', 229.00, 50, 1),
(22, 5, 2, 'sumthin GaNStation 100W 4-Port Fast Power Adapter', 'sumthin-ganstation-100w-charger', 'All-in-one desktop charging hub delivering a full 100W PD single port output to charge MacBooks, tablets, and phones simultaneously.', 269.00, 35, 0),
(23, 5, 1, 'sumthin MiniGaN 65W Foldable Travel Charger', 'sumthin-minigan-65w-charger', 'Pocket-friendly fast charger with folding prongs, dual Type-C outputs, and intelligent power distribution for travelers.', 119.00, 60, 0),
(24, 5, 4, 'sumthin UltraBraid 140W USB-C Heavy-Duty Cable (1.8m)', 'sumthin-ultrabraid-140w-cable', 'Armor-braided bio-based nylon USB-C to USB-C cable certified for 140W PD charging with E-Marker smart chipset and 35,000-bend durability.', 89.00, 80, 0),
(25, 5, 1, 'sumthin FlexSilicone 240W PD 3.1 Soft-Touch Cable (2m)', 'sumthin-flexsilicone-240w-cable', 'Skin-friendly food-grade liquid silicone cable supporting ultra-high 240W power delivery, tangle-free flexibility, and 480Mbps data sync.', 59.00, 90, 0),

(26, 6, 4, 'sumthin Vision 4K Ultra HD HDR Streaming Webcam', 'sumthin-vision-4k-webcam', 'Stunning 4K Ultra HD video clarity at 30fps or 1080p at 60fps with HDR light optimization, AI facial tracking, and dual noise-cancelling mics.', 829.00, 16, 1),
(27, 6, 2, 'sumthin StreamCam 1080p 60FPS Pro Glass Lens Webcam', 'sumthin-streamcam-1080p-webcam', 'Sharp Full HD 1080p at 60 frames per second with premium 5-element optical glass lens, auto-focus, and dual stereo sound recording.', 329.00, 40, 0),
(28, 6, 3, 'sumthin StudioLight Pro Adaptive Sensor Webcam', 'sumthin-studiolight-pro-webcam', 'Ultra-sensitive 1/2.8-inch CMOS star-light imaging sensor that captures crisp color video even in dark room ambient lighting conditions.', 699.00, 14, 0),
(29, 6, 1, 'sumthin Clarity 2K USB Webcam with Privacy Shutter', 'sumthin-clarity-2k-webcam', 'Sharp 2K QHD video resolution, integrated physical sliding privacy shutter, wide 95-degree field-of-view, and universal tripod mounting.', 259.00, 30, 0),
(30, 6, 3, 'sumthin BoomPro 360-Degree Aluminum Studio Arm', 'sumthin-boompro-studio-arm', 'Heavy-duty aircraft grade aluminum microphone and webcam suspension boom arm with internal cable channels and 360-degree smooth fluid rotation.', 139.00, 45, 0);

-- 5. Insert Primary Product Photos
INSERT INTO `product_photo` (`product_id`, `photo_url`, `is_primary`, `sort_order`) VALUES
(1, 'sumthin_volt24k.png', 1, 1),
(2, 'sumthin_blade100w.png', 1, 1),
(3, 'sumthin_nexcore145w.png', 1, 1),
(4, 'sumthin_magsnap10k.png', 1, 1),
(5, 'sumthin_pocketmini.png', 1, 1),
(6, 'sumthin_keypro_q1.png', 1, 1),
(7, 'sumthin_tactile_k2.png', 1, 1),
(8, 'sumthin_lowprofile_s.png', 1, 1),
(9, 'sumthin_strike_apex.png', 1, 1),
(10, 'sumthin_creator_v1.png', 1, 1),
(11, 'sumthin_glide_master.png', 1, 1),
(12, 'sumthin_swift_v3.png', 1, 1),
(13, 'sumthin_zeroair.png', 1, 1),
(14, 'sumthin_basilisk.png', 1, 1),
(15, 'sumthin_studio_m3.png', 1, 1),
(16, 'sumthin_puretone_anc.png', 1, 1),
(17, 'sumthin_buds_pro.png', 1, 1),
(18, 'sumthin_aerovoice.png', 1, 1),
(19, 'sumthin_spaceone.png', 1, 1),
(20, 'sumthin_liberty_anc.png', 1, 1),
(21, 'sumthin_ganprime_67w.png', 1, 1),
(22, 'sumthin_ganstation_100w.png', 1, 1),
(23, 'sumthin_minigan_65w.png', 1, 1),
(24, 'sumthin_ultrabraid_140w.png', 1, 1),
(25, 'sumthin_flexsilicone_240w.png', 1, 1),
(26, 'sumthin_vision_4k.png', 1, 1),
(27, 'sumthin_streamcam_1080p.png', 1, 1),
(28, 'sumthin_studiolight_pro.png', 1, 1),
(29, 'sumthin_clarity_2k.png', 1, 1),
(30, 'sumthin_boompro_arm.png', 1, 1);

-- 6. Insert Addresses
INSERT INTO `address` (`id`, `user_id`, `recipient_name`, `phone`, `address_line1`, `address_line2`, `city`, `state`, `postal_code`, `is_default`) VALUES
(1, 3, 'Alice Tan', '016-1122334', 'No. 12, Jalan Genting Kelang', 'Setapak', 'Kuala Lumpur', 'Wilayah Persekutuan', '53300', 1),
(2, 4, 'Bob Lee', '017-2233445', 'Unit 8-2, Residensi PV15', 'Danau Kota', 'Kuala Lumpur', 'Wilayah Persekutuan', '53300', 1),
(3, 5, 'Charlie Wong', '018-3344556', '77, Lorong Lembah Permai 3', 'Tanjung Bungah', 'George Town', 'Penang', '11200', 1);

-- 7. Insert Vouchers
INSERT INTO `voucher` (`code`, `discount_type`, `discount_value`, `min_spend`, `max_discount`, `usage_limit`, `used_count`, `expires_at`) VALUES
('SUMTHIN10', 'percentage', 10.00, 100.00, 50.00, 200, 14, '2027-12-31 23:59:59'),
('TECHSAVE30', 'fixed', 30.00, 200.00, 30.00, 100, 8, '2027-12-31 23:59:59'),
('WELCOME50', 'fixed', 50.00, 400.00, 50.00, 50, 3, '2027-12-31 23:59:59');

-- 8. Insert Sample Orders & Order Items
INSERT INTO `order` (`id`, `order_no`, `user_id`, `address_id`, `total_amount`, `discount_amount`, `final_amount`, `status`, `payment_status`, `tracking_no`, `created_at`) VALUES
(1, 'ORD-20261101-001', 3, 1, 1038.00, 50.00, 988.00, 'Delivered', 'Paid', 'JNT-MY-99881122', '2026-11-01 14:20:00'),
(2, 'ORD-20261105-002', 4, 2, 439.00, 30.00, 409.00, 'Shipped', 'Paid', 'DHL-MY-44556677', '2026-11-05 10:15:00'),
(3, 'ORD-20261112-003', 5, 3, 599.00, 0.00, 599.00, 'Processing', 'Paid', NULL, '2026-11-12 16:45:00'),
(4, 'ORD-20261113-004', 6, 1, 899.00, 50.00, 849.00, 'Delivered', 'Paid', 'POS-MY-77223344', '2026-11-13 11:30:00'),
(5, 'ORD-20261114-005', 7, 2, 349.00, 0.00, 349.00, 'Cancelled', 'Refunded', NULL, '2026-11-14 09:20:00');

INSERT INTO `order_item` (`order_id`, `product_id`, `price`, `quantity`, `subtotal`) VALUES
(1, 1, 599.00, 1, 599.00),
(1, 11, 439.00, 1, 439.00),
(2, 11, 439.00, 1, 439.00),
(3, 1, 599.00, 1, 599.00),
(4, 6, 899.00, 1, 899.00),
(5, 20, 349.00, 1, 349.00);

-- 9. Insert Sample Reviews
INSERT INTO `review` (`product_id`, `user_id`, `order_id`, `rating`, `comment`, `created_at`) VALUES
(1, 3, 1, 5, 'The sumthin Volt 24K is phenomenal! Charges my laptop at full 140W speed without heating up. The digital display is super convenient.', '2026-11-03 18:30:00'),
(11, 3, 1, 5, 'Best ergonomic productivity mouse ever made. Quiet clicks are great for library and office use.', '2026-11-04 09:12:00'),
(11, 4, 2, 4, 'Very smooth scrolling and comfortable grip. Fits my hand naturally.', '2026-11-07 20:45:00'),
(6, 6, 4, 5, 'Exceptional craftsmanship. CNC aluminum case feels heavy and premium, switches are smooth and sound deep and thocky.', '2026-11-13 19:40:00'),
(16, 5, NULL, 5, 'ANC blocks out almost all engine rumble and conversation in cafes. LDAC audio transmission is crisp and punchy.', '2026-11-14 14:10:00'),
(21, 7, NULL, 5, 'Tiny size but charges my MacBook Air and iPhone at the same time without breaking a sweat.', '2026-11-14 15:25:00');

-- 10. Insert Sample Wishlist Items
INSERT INTO `wishlist` (`user_id`, `product_id`) VALUES
(3, 8),
(3, 16),
(4, 6),
(5, 13),
(6, 1),
(7, 26);

-- 11. Insert System Settings
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
