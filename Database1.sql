-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Server version:               10.4.32-MariaDB - mariadb.org binary distribution
-- Server OS:                    Win64
-- HeidiSQL Version:             12.8.0.6908
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

-- Dumping structure for table psu_blue_cafe.attendance
CREATE TABLE IF NOT EXISTS `attendance` (
  `attendance_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `date_in` date NOT NULL,
  `time_in` time NOT NULL,
  `date_out` date NOT NULL,
  `time_out` time NOT NULL,
  `hour_type` enum('normal','fund') NOT NULL DEFAULT 'normal',
  PRIMARY KEY (`attendance_id`),
  KEY `fk_attendance_user` (`user_id`),
  CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table psu_blue_cafe.categories
CREATE TABLE IF NOT EXISTS `categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`category_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table psu_blue_cafe.menu
CREATE TABLE IF NOT EXISTS `menu` (
  `menu_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`menu_id`),
  KEY `fk_menu_category` (`category_id`),
  CONSTRAINT `fk_menu_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table psu_blue_cafe.menu_toppings
CREATE TABLE IF NOT EXISTS `menu_toppings` (
  `menu_id` int(11) NOT NULL,
  `topping_id` int(11) NOT NULL,
  `extra_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`menu_id`,`topping_id`),
  KEY `topping_id` (`topping_id`),
  CONSTRAINT `menu_toppings_ibfk_1` FOREIGN KEY (`menu_id`) REFERENCES `menu` (`menu_id`),
  CONSTRAINT `menu_toppings_ibfk_2` FOREIGN KEY (`topping_id`) REFERENCES `toppings` (`topping_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for table psu_blue_cafe.orders
CREATE TABLE IF NOT EXISTS `orders` (
  `order_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `order_time` datetime NOT NULL DEFAULT current_timestamp(),
  `order_date` date NOT NULL,
  `order_seq` int(11) NOT NULL DEFAULT 0,
  `status` enum('awaiting_payment','pending','ready','canceled') NOT NULL DEFAULT 'awaiting_payment',
  `payment_method` enum('transfer','cash') NOT NULL DEFAULT 'transfer',
  `total_price` decimal(10,2) NOT NULL,
  `updated_at` datetime(6) NOT NULL,
  `promo_id` int(11) DEFAULT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `final_total` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`order_id`),
  UNIQUE KEY `uk_orders_day_seq` (`order_date`,`order_seq`),
  KEY `fk_orders_user` (`user_id`),
  KEY `idx_orders_updated_at` (`updated_at`),
  KEY `idx_orders_user_updated` (`user_id`,`updated_at`,`status`),
  KEY `idx_orders_feed` (`user_id`,`updated_at`,`order_id`),
  KEY `fk_orders_promo` (`promo_id`),
  KEY `idx_orders_status_updated` (`status`,`updated_at`),
  KEY `idx_orders_user` (`user_id`),
  KEY `idx_orders_order` (`order_id`),
  KEY `idx_orders_updated` (`updated_at`),
  CONSTRAINT `fk_orders_promo` FOREIGN KEY (`promo_id`) REFERENCES `promotions` (`promo_id`),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=128 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table psu_blue_cafe.order_counters
CREATE TABLE IF NOT EXISTS `order_counters` (
  `order_date` date NOT NULL,
  `last_seq` int(11) NOT NULL,
  PRIMARY KEY (`order_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for table psu_blue_cafe.order_details
CREATE TABLE IF NOT EXISTS `order_details` (
  `order_detail_id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `menu_id` int(11) NOT NULL,
  `promo_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `total_price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`order_detail_id`),
  KEY `fk_details_order` (`order_id`),
  KEY `fk_details_menu` (`menu_id`),
  KEY `fk_details_promo` (`promo_id`),
  CONSTRAINT `fk_details_menu` FOREIGN KEY (`menu_id`) REFERENCES `menu` (`menu_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_details_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_details_promo` FOREIGN KEY (`promo_id`) REFERENCES `promotions` (`promo_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=152 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data exporting was unselected.

-- Dumping structure for table psu_blue_cafe.payment_slips
CREATE TABLE IF NOT EXISTS `payment_slips` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `mime` varchar(64) NOT NULL,
  `size_bytes` int(11) NOT NULL,
  `uploaded_at` datetime(6) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `idx_slips_order_uploaded` (`order_id`,`uploaded_at`)
) ENGINE=InnoDB AUTO_INCREMENT=79 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for table psu_blue_cafe.promotions
CREATE TABLE IF NOT EXISTS `promotions` (
  `promo_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `scope` enum('ORDER','ITEM') NOT NULL DEFAULT 'ITEM',
  `discount_type` enum('PERCENT','FIXED') NOT NULL DEFAULT 'PERCENT',
  `discount_value` decimal(10,2) NOT NULL,
  `min_order_total` decimal(10,2) DEFAULT NULL,
  `max_discount` decimal(10,2) DEFAULT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `usage_limit` int(11) DEFAULT NULL,
  `used_count` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`promo_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for table psu_blue_cafe.promotion_items
CREATE TABLE IF NOT EXISTS `promotion_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `promo_id` int(11) NOT NULL,
  `menu_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_promo_menu` (`promo_id`,`menu_id`),
  KEY `idx_menu` (`menu_id`),
  CONSTRAINT `fk_pi_menu` FOREIGN KEY (`menu_id`) REFERENCES `menu` (`menu_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pi_promo` FOREIGN KEY (`promo_id`) REFERENCES `promotions` (`promo_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for table psu_blue_cafe.recipe_headers
CREATE TABLE IF NOT EXISTS `recipe_headers` (
  `recipe_id` int(11) NOT NULL AUTO_INCREMENT,
  `menu_id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`recipe_id`),
  KEY `fk_rh_menu` (`menu_id`),
  CONSTRAINT `fk_rh_menu` FOREIGN KEY (`menu_id`) REFERENCES `menu` (`menu_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for table psu_blue_cafe.recipe_ingredients
CREATE TABLE IF NOT EXISTS `recipe_ingredients` (
  `ingredient_id` int(11) NOT NULL AUTO_INCREMENT,
  `recipe_id` int(11) NOT NULL,
  `step_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `qty` varchar(64) DEFAULT NULL,
  `unit` varchar(64) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ingredient_id`),
  KEY `fk_ri_recipe` (`recipe_id`),
  KEY `fk_ri_step` (`step_id`),
  CONSTRAINT `fk_ri_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `recipe_headers` (`recipe_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ri_step` FOREIGN KEY (`step_id`) REFERENCES `recipe_steps` (`step_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for table psu_blue_cafe.recipe_steps
CREATE TABLE IF NOT EXISTS `recipe_steps` (
  `step_id` int(11) NOT NULL AUTO_INCREMENT,
  `recipe_id` int(11) NOT NULL,
  `step_no` int(11) NOT NULL,
  `step_text` text NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`step_id`),
  UNIQUE KEY `uq_recipe_step` (`recipe_id`,`step_no`),
  CONSTRAINT `fk_rs_recipe` FOREIGN KEY (`recipe_id`) REFERENCES `recipe_headers` (`recipe_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=74 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for table psu_blue_cafe.toppings
CREATE TABLE IF NOT EXISTS `toppings` (
  `topping_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `base_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`topping_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for table psu_blue_cafe.users
CREATE TABLE IF NOT EXISTS `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `student_ID` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `role` enum('admin','employee') NOT NULL DEFAULT 'employee',
  `status` enum('ชั่วโมงทุน','ชั่วโมงปกติ') NOT NULL DEFAULT 'ชั่วโมงปกติ',
  `default_hour_type` enum('normal','fund') NOT NULL DEFAULT 'normal',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data exporting was unselected.

-- Dumping structure for trigger psu_blue_cafe.trg_orders_before_insert
SET @OLDTMP_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION';
DELIMITER //
CREATE TRIGGER trg_orders_before_insert
BEFORE INSERT ON `orders`
FOR EACH ROW
BEGIN
  DECLARE v_date DATE;
  DECLARE v_seq  INT;

  SET v_date = DATE(IFNULL(NEW.order_time, NOW()));
  SET NEW.order_date = v_date;

  IF NEW.updated_at IS NULL OR NEW.updated_at = '0000-00-00 00:00:00' THEN
    SET NEW.updated_at = NOW(6);
  END IF;

  INSERT INTO order_counters (order_date, last_seq)
  VALUES (v_date, LAST_INSERT_ID(1))
  ON DUPLICATE KEY UPDATE last_seq = LAST_INSERT_ID(last_seq + 1);

  SET v_seq = LAST_INSERT_ID();
  SET NEW.order_seq = v_seq;
END//
DELIMITER ;
SET SQL_MODE=@OLDTMP_SQL_MODE;

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
