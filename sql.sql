-- 1. Vytvoření databáze s plnou podporou UTF-8 (emoji atd.)
CREATE DATABASE IF NOT EXISTS `btcpay_lite` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. Přepnutí do nově vytvořené databáze
USE `btcpay_lite`;

-- 3. Tabulka pro Obchody (Multitenancy)
CREATE TABLE `stores` (
  `id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `api_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `wallet_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Tabulka pro Faktury
CREATE TABLE `invoices` (
  `id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `btc_address` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(16,8) NOT NULL,
  `status` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'New',
  `metadata` json DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `expires_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `store_id` (`store_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Tabulka pro Webhooky
CREATE TABLE `webhooks` (
  `id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `store_id` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` text COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `store_id` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Vložení testovacího obchodu (Tohle budeš používat ty pro svůj první web)
INSERT INTO `stores` (`id`, `name`, `api_key`, `wallet_path`) 
VALUES ('store_12345', 'Můj První E-shop', 'super_tajny_klic_999', '/home/ag/Documents/Projekty/htdocs/electrum/wallets/default_wallet');