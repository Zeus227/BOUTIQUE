-- ============================================================
--  Cosmétiques d'Alger — Schéma MySQL (tables + données d'exemple)
--  Les comptes utilisateurs se créent avec seed_users.sql (voir README)
--  À importer via phpMyAdmin (onglet "Importer")
--  Moteur : InnoDB — Encodage : utf8mb4
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
--  Utilisateurs (admin / vendeur)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)  NOT NULL,
  `display_name`  VARCHAR(100) NOT NULL,
  `password_hash` CHAR(64)     NOT NULL COMMENT 'SHA-256 du mot de passe',
  `role`          ENUM('admin','vendeur') NOT NULL DEFAULT 'vendeur',
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Paramètres de la boutique
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `setting_key`   VARCHAR(50)  NOT NULL,
  `setting_value` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Produits / inventaire (prix en XOF)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(150) NOT NULL,
  `category`       VARCHAR(80)  NOT NULL DEFAULT 'Divers',
  `purchase_price` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Prix d''achat unitaire (XOF)',
  `sale_price`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Prix de vente unitaire (XOF)',
  `stock`          INT          NOT NULL DEFAULT 0,
  `unit`           VARCHAR(30)  NOT NULL DEFAULT 'pièce',
  `alert_threshold` INT         NOT NULL DEFAULT 5,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_products_name` (`name`),
  KEY `idx_products_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Ventes (en-tête)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `sales`;
CREATE TABLE `sales` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_date`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id`     INT UNSIGNED NULL,
  `seller_name` VARCHAR(100) NOT NULL DEFAULT '',
  `subtotal`    INT UNSIGNED NOT NULL DEFAULT 0,
  `discount`    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Remise (XOF)',
  `total`       INT UNSIGNED NOT NULL DEFAULT 0,
  `paid`        INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Montant reçu',
  `change_given` INT         NOT NULL DEFAULT 0 COMMENT 'Monnaie rendue',
  `payment_mode` ENUM('Espèces','Mobile Money','Carte bancaire') NOT NULL DEFAULT 'Espèces',
  PRIMARY KEY (`id`),
  KEY `idx_sales_date` (`sale_date`),
  KEY `idx_sales_user` (`user_id`),
  CONSTRAINT `fk_sales_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Lignes de vente
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `sale_items`;
CREATE TABLE `sale_items` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id`      INT UNSIGNED NOT NULL,
  `product_id`   INT UNSIGNED NULL,
  `product_name` VARCHAR(150) NOT NULL,
  `qty`          INT UNSIGNED NOT NULL,
  `unit_price`   INT UNSIGNED NOT NULL COMMENT 'Prix de vente au moment de la vente',
  `cost_price`   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Prix d''achat au moment de la vente (pour la marge)',
  `line_total`   INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_items_sale` (`sale_id`),
  KEY `idx_items_product` (`product_id`),
  CONSTRAINT `fk_items_sale`    FOREIGN KEY (`sale_id`)    REFERENCES `sales` (`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_items_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Achats / approvisionnements
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `purchases`;
CREATE TABLE `purchases` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_date` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `product_id`    INT UNSIGNED NULL,
  `product_name`  VARCHAR(150) NOT NULL,
  `qty`           INT UNSIGNED NOT NULL,
  `unit_cost`     INT UNSIGNED NOT NULL,
  `total`         INT UNSIGNED NOT NULL,
  `supplier`      VARCHAR(120) NOT NULL DEFAULT '—',
  `user_id`       INT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `idx_purchases_date` (`purchase_date`),
  KEY `idx_purchases_product` (`product_id`),
  CONSTRAINT `fk_purchases_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_purchases_user`    FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  DONNÉES INITIALES
-- ============================================================

-- Nom de la boutique
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
  ('shop_name', 'Cosmétiques d''Alger'),
  ('currency',  'XOF');

-- Produits d'exemple (modifiables ou supprimables)
INSERT INTO `products` (`name`, `category`, `purchase_price`, `sale_price`, `stock`, `unit`, `alert_threshold`) VALUES
  ('Crème hydratante Karité', 'Soin visage', 1500, 3000, 25, 'pot',    5),
  ('Rouge à lèvres Mat',      'Maquillage',  1000, 2500, 40, 'pièce',  8),
  ('Huile de coco bio',       'Soin corps',  1200, 2500, 15, 'flacon', 5),
  ('Parfum Oud Intense',      'Parfumerie',  4000, 8500, 10, 'flacon', 3),
  ('Savon noir gommant',      'Hygiène',      500, 1200, 60, 'pièce', 10),
  ('Gel douche Karité-Miel',  'Hygiène',      800, 1800, 30, 'flacon', 8);
