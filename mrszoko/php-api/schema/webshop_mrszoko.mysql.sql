-- ============================================================================
--  webshop_mrszoko — canonical MySQL schema (Console marque · franchisor)
-- ----------------------------------------------------------------------------
--  Every domain table lives here, all prefixed `wsm_`. This is the single
--  source of truth read by the back-office through php-api/. No business data
--  is hardcoded in the front-end: the UI reads these tables via the API.
--
--  Idempotent & additive: safe to run more than once. Seed rows live in
--  seed.php (engine-agnostic, run by migrate.php).
--
--  MySQL 8+ / utf8mb4.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `webshop_mrszoko`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `webshop_mrszoko`;

-- --- Dashboard KPI snapshot ------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_kpis` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sort_order`  INT NOT NULL DEFAULT 0,
  `label`       VARCHAR(120) NOT NULL,
  `value`       VARCHAR(60)  NOT NULL,
  `val_color`   VARCHAR(40)  NOT NULL DEFAULT 'var(--color-text)',
  `delta`       VARCHAR(60)  NOT NULL DEFAULT '',
  `delta_color` VARCHAR(40)  NOT NULL DEFAULT '#2d7a3e',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Shops (boutiques du réseau) ------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_shops` (
  `id`         VARCHAR(24)  NOT NULL,
  `nom`        VARCHAR(160) NOT NULL,
  `ville`      VARCHAR(120) NOT NULL DEFAULT '',
  `web`        TINYINT(1)   NOT NULL DEFAULT 1,
  `contrat`    VARCHAR(40)  NOT NULL DEFAULT 'Franchise',
  `act`        TINYINT(1)   NOT NULL DEFAULT 1,
  `ca_shop`    INT NOT NULL DEFAULT 0,
  `ca_office`  INT NOT NULL DEFAULT 0,
  `adoption`   INT NOT NULL DEFAULT 0,
  `accent`     VARCHAR(40)  NOT NULL DEFAULT 'var(--color-primary)',
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Product categories -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_categories` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(120) NOT NULL,
  `sort_order`      INT NOT NULL DEFAULT 0,
  `menu_default`    TINYINT(1) NOT NULL DEFAULT 0,
  `brand_whitelist` TINYINT(1) NOT NULL DEFAULT 1,
  `office_delivery` TINYINT(1) NOT NULL DEFAULT 0,
  `brand_mandatory` TINYINT(1) NOT NULL DEFAULT 0,
  `active`          TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Products (catalogue) ---------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_products` (
  `id`              VARCHAR(48)  NOT NULL,
  `category_id`     INT UNSIGNED NOT NULL,
  `nom`             VARCHAR(160) NOT NULL,
  `prix`            DECIMAL(10,2) NOT NULL DEFAULT 0,
  `base_cost`       DECIMAL(10,2) NOT NULL DEFAULT 0,
  `statut`          VARCHAR(40)  NOT NULL DEFAULT 'Publié',
  `saison`          VARCHAR(40)  NOT NULL DEFAULT '',
  `brand_whitelist` TINYINT(1) NOT NULL DEFAULT 0,
  `brand_mandatory` TINYINT(1) NOT NULL DEFAULT 0,
  `adoption`        INT NOT NULL DEFAULT 0,
  `menu_override`   VARCHAR(8)   NULL DEFAULT NULL,
  `sort_order`      INT NOT NULL DEFAULT 0,
  `active`          TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_products_category` (`category_id`),
  CONSTRAINT `fk_wsm_products_category`
    FOREIGN KEY (`category_id`) REFERENCES `wsm_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Brand vouchers ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_vouchers` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`     VARCHAR(60)  NOT NULL,
  `valeur`   VARCHAR(160) NOT NULL DEFAULT '',
  `type`     VARCHAR(40)  NOT NULL DEFAULT 'Panier',
  `validite` VARCHAR(80)  NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_vouchers_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Network pricing rules ---------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_pricing_rules` (
  `id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nom`     VARCHAR(160) NOT NULL,
  `cible`   VARCHAR(160) NOT NULL DEFAULT '',
  `effet`   VARCHAR(80)  NOT NULL DEFAULT '',
  `shop_id` VARCHAR(24)  NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Brand parameters (config) ----------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_params` (
  `cle`  VARCHAR(120) NOT NULL,
  `type` VARCHAR(16)  NOT NULL DEFAULT 'text',
  `val`  VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Email templates --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_email_templates` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cle`    VARCHAR(80)  NOT NULL,
  `langue` VARCHAR(8)   NOT NULL DEFAULT 'FR',
  `sujet`  VARCHAR(200) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_email_templates` (`cle`, `langue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Users (RBAC) -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_users` (
  `id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nom`    VARCHAR(160) NOT NULL,
  `email`  VARCHAR(200) NOT NULL,
  `role`   VARCHAR(40)  NOT NULL DEFAULT 'Franchise',
  `portee` VARCHAR(200) NOT NULL DEFAULT '',
  `act`    TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_user_shops` (
  `user_id` INT UNSIGNED NOT NULL,
  `shop_id` VARCHAR(24)  NOT NULL,
  PRIMARY KEY (`user_id`, `shop_id`),
  CONSTRAINT `fk_wsm_user_shops_user`
    FOREIGN KEY (`user_id`) REFERENCES `wsm_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Audit log --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_audit` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ts`         VARCHAR(40)  NOT NULL DEFAULT '',
  `user`       VARCHAR(160) NOT NULL DEFAULT '',
  `verb`       VARCHAR(40)  NOT NULL DEFAULT '',
  `entity`     VARCHAR(200) NOT NULL DEFAULT '',
  `shop`       VARCHAR(120) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Catchment zones (zones de chalandise) ----------------------------------
CREATE TABLE IF NOT EXISTS `wsm_catchment` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`      VARCHAR(200) NOT NULL,
  `postcodes` TEXT NULL,
  `exclusive` TINYINT(1) NOT NULL DEFAULT 1,
  `active`    TINYINT(1) NOT NULL DEFAULT 1,
  `shop_id`   VARCHAR(24) NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Menu builder (bundles / slots / choices) -------------------------------
CREATE TABLE IF NOT EXISTS `wsm_bundles` (
  `id`             VARCHAR(48)  NOT NULL,
  `product_id`     VARCHAR(48)  NOT NULL,
  `name`           VARCHAR(160) NOT NULL DEFAULT 'Nouvelle formule',
  `description`    VARCHAR(255) NOT NULL DEFAULT '',
  `price_modifier` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `sort_order`     INT NOT NULL DEFAULT 0,
  `active`         TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_bundles_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_bundle_slots` (
  `id`         VARCHAR(48)  NOT NULL,
  `bundle_id`  VARCHAR(48)  NOT NULL,
  `label`      VARCHAR(160) NOT NULL DEFAULT 'Nouvelle étape',
  `required`   TINYINT(1) NOT NULL DEFAULT 1,
  `kind`       VARCHAR(16) NOT NULL DEFAULT 'single',
  `min_select` INT NOT NULL DEFAULT 1,
  `max_select` INT NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `active`     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_slots_bundle` (`bundle_id`),
  CONSTRAINT `fk_wsm_slots_bundle`
    FOREIGN KEY (`bundle_id`) REFERENCES `wsm_bundles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wsm_bundle_slot_choices` (
  `id`         VARCHAR(48)  NOT NULL,
  `slot_id`    VARCHAR(48)  NOT NULL,
  `label`      VARCHAR(160) NOT NULL DEFAULT 'Nouveau choix',
  `img`        VARCHAR(16)  NOT NULL DEFAULT '',
  `delta`      DECIMAL(10,2) NOT NULL DEFAULT 0,
  `cost`       DECIMAL(10,2) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `active`     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_choices_slot` (`slot_id`),
  CONSTRAINT `fk_wsm_choices_slot`
    FOREIGN KEY (`slot_id`) REFERENCES `wsm_bundle_slots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  DELIVERY MODULE  (livraison B2B — bureaux / points de livraison)
-- ============================================================================

-- --- B2B clients ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_clients` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`     VARCHAR(24)  NOT NULL,
  `raison`   VARCHAR(200) NOT NULL,
  `seg`      VARCHAR(40)  NOT NULL DEFAULT 'horeca',
  `statut`   VARCHAR(40)  NOT NULL DEFAULT 'actif',
  `tva`      VARCHAR(40)  NOT NULL DEFAULT '',
  `paiement` VARCHAR(80)  NOT NULL DEFAULT '',
  `plafond`  INT NOT NULL DEFAULT 0,
  `encours`  INT NOT NULL DEFAULT 0,
  `franco`   VARCHAR(24)  NOT NULL DEFAULT '',
  `remise`   VARCHAR(24)  NOT NULL DEFAULT '',
  `fact`     VARCHAR(40)  NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_clients_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Delivery points (adresses de livraison d'un client) --------------------
CREATE TABLE IF NOT EXISTS `wsm_client_points` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_id`  INT UNSIGNED NOT NULL,
  `libelle`    VARCHAR(200) NOT NULL,
  `adresse`    VARCHAR(255) NOT NULL DEFAULT '',
  `fenetre`    VARCHAR(40)  NOT NULL DEFAULT '',
  `jours`      VARCHAR(40)  NOT NULL DEFAULT '',
  `validation` VARCHAR(24)  NOT NULL DEFAULT 'QR',
  `marge`      INT NOT NULL DEFAULT 0,
  `lat`        DECIMAL(9,6) NULL DEFAULT NULL,
  `lng`        DECIMAL(9,6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_points_client` (`client_id`),
  CONSTRAINT `fk_wsm_points_client`
    FOREIGN KEY (`client_id`) REFERENCES `wsm_clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Drivers (chauffeurs) ---------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_drivers` (
  `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nom`      VARCHAR(160) NOT NULL,
  `info`     VARCHAR(200) NOT NULL DEFAULT '',
  `color`    VARCHAR(40)  NOT NULL DEFAULT '#8D1D2C',
  `vehicule` VARCHAR(120) NOT NULL DEFAULT '',
  `zone`     VARCHAR(120) NOT NULL DEFAULT '',
  `active`   TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Rounds (tournées) ------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_rounds` (
  `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`      VARCHAR(160) NOT NULL,
  `driver_id` INT UNSIGNED NULL DEFAULT NULL,
  `round_date` DATE NULL DEFAULT NULL,
  `status`    VARCHAR(24) NOT NULL DEFAULT 'planifiée',
  PRIMARY KEY (`id`),
  KEY `idx_wsm_rounds_driver` (`driver_id`),
  CONSTRAINT `fk_wsm_rounds_driver`
    FOREIGN KEY (`driver_id`) REFERENCES `wsm_drivers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Deliveries (livraisons) ------------------------------------------------
--  status flow: brouillon → planifiée → assignée → en_cours → livrée
--                                                          ↘ échouée
CREATE TABLE IF NOT EXISTS `wsm_deliveries` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref`               VARCHAR(32)  NOT NULL,
  `client_id`         INT UNSIGNED NULL DEFAULT NULL,
  `point_id`          INT UNSIGNED NULL DEFAULT NULL,
  `driver_id`         INT UNSIGNED NULL DEFAULT NULL,
  `round_id`          INT UNSIGNED NULL DEFAULT NULL,
  `status`            VARCHAR(24)  NOT NULL DEFAULT 'brouillon',
  `window_label`      VARCHAR(40)  NOT NULL DEFAULT '',
  `validation_method` VARCHAR(16)  NOT NULL DEFAULT 'QR',
  `confirm_code`      VARCHAR(32)  NOT NULL DEFAULT '',
  `confirmed`         TINYINT(1)   NOT NULL DEFAULT 0,
  `ca`                DECIMAL(10,2) NOT NULL DEFAULT 0,
  `couts`             DECIMAL(10,2) NOT NULL DEFAULT 0,
  `scheduled_date`    DATE NULL DEFAULT NULL,
  `notes`             VARCHAR(255) NOT NULL DEFAULT '',
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `confirmed_at`      DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_deliveries_ref` (`ref`),
  KEY `idx_wsm_deliveries_status` (`status`),
  KEY `idx_wsm_deliveries_driver` (`driver_id`),
  CONSTRAINT `fk_wsm_deliveries_client`
    FOREIGN KEY (`client_id`) REFERENCES `wsm_clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wsm_deliveries_point`
    FOREIGN KEY (`point_id`) REFERENCES `wsm_client_points` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wsm_deliveries_driver`
    FOREIGN KEY (`driver_id`) REFERENCES `wsm_drivers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_wsm_deliveries_round`
    FOREIGN KEY (`round_id`) REFERENCES `wsm_rounds` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Delivery events (piste d'audit d'une livraison) ------------------------
CREATE TABLE IF NOT EXISTS `wsm_delivery_events` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `delivery_id` INT UNSIGNED NOT NULL,
  `event`       VARCHAR(40)  NOT NULL,
  `detail`      VARCHAR(255) NOT NULL DEFAULT '',
  `actor`       VARCHAR(160) NOT NULL DEFAULT '',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_devents_delivery` (`delivery_id`),
  CONSTRAINT `fk_wsm_devents_delivery`
    FOREIGN KEY (`delivery_id`) REFERENCES `wsm_deliveries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Delivery incidents -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_incidents` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ref`         VARCHAR(32)  NOT NULL,
  `delivery_id` INT UNSIGNED NULL DEFAULT NULL,
  `type`        VARCHAR(80)  NOT NULL,
  `point`       VARCHAR(200) NOT NULL DEFAULT '',
  `statut`      VARCHAR(40)  NOT NULL DEFAULT 'À traiter',
  `impact`      VARCHAR(40)  NOT NULL DEFAULT '',
  `description` TEXT NULL,
  `geo`         VARCHAR(60)  NOT NULL DEFAULT '',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_incidents_ref` (`ref`),
  KEY `idx_wsm_incidents_delivery` (`delivery_id`),
  CONSTRAINT `fk_wsm_incidents_delivery`
    FOREIGN KEY (`delivery_id`) REFERENCES `wsm_deliveries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
