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
  -- Vitrine de la boutique (les textes traduits vivent dans wsm_shop_i18n
  -- sous product.<id>.name / .subtitle / .desc — rien en dur dans les pages)
  `slug`            VARCHAR(80)  NOT NULL DEFAULT '',
  `shop_visible`    TINYINT(1)   NOT NULL DEFAULT 0,
  `stock`           INT NOT NULL DEFAULT 0,
  `image_url`       VARCHAR(255) NOT NULL DEFAULT '',
  `swatch_from`     VARCHAR(32)  NOT NULL DEFAULT '--choco-500',
  `swatch_to`       VARCHAR(32)  NOT NULL DEFAULT '--choco-800',
  `origin`          VARCHAR(80)  NOT NULL DEFAULT '',
  `cocoa`           VARCHAR(16)  NOT NULL DEFAULT '',
  `unit_label`      VARCHAR(40)  NOT NULL DEFAULT '',
  `badge`           VARCHAR(40)  NOT NULL DEFAULT '',
  -- Expédition InPost + fiscalité tpay
  `sku`             VARCHAR(60)  NOT NULL DEFAULT '',
  `ean`             VARCHAR(14)  NOT NULL DEFAULT '',
  `vat_rate`        DECIMAL(4,2) NOT NULL DEFAULT 0.23,
  `weight_g`        INT NOT NULL DEFAULT 0,
  `length_mm`       INT NOT NULL DEFAULT 0,
  `width_mm`        INT NOT NULL DEFAULT 0,
  `height_mm`       INT NOT NULL DEFAULT 0,
  `parcel_template` CHAR(1)      NOT NULL DEFAULT '',
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
  `role`   VARCHAR(40)  NOT NULL DEFAULT 'Franczyza',
  `portee` VARCHAR(200) NOT NULL DEFAULT '',
  `act`    TINYINT(1)   NOT NULL DEFAULT 1,
  -- Authentification (voir auth.php). Un compte sans hachage ne peut pas se
  -- connecter : les comptes de démonstration sont donc inertes tant qu'un
  -- mot de passe n'a pas été posé (migrate.php --set-password).
  `password_hash`   VARCHAR(255) NULL DEFAULT NULL,
  `last_login`      DATETIME NULL DEFAULT NULL,
  `failed_attempts` INT NOT NULL DEFAULT 0,
  `locked_until`    DATETIME NULL DEFAULT NULL,
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
  -- tpay (payeur + facture) & InPost (destinataire) — voir commerce.php
  `client_type`   VARCHAR(10)  NOT NULL DEFAULT 'firma',
  `email`         VARCHAR(200) NOT NULL DEFAULT '',
  `phone`         VARCHAR(20)  NOT NULL DEFAULT '',
  `first_name`    VARCHAR(120) NOT NULL DEFAULT '',
  `last_name`     VARCHAR(120) NOT NULL DEFAULT '',
  `nip`           VARCHAR(15)  NOT NULL DEFAULT '',
  `vat_eu`        VARCHAR(20)  NOT NULL DEFAULT '',
  `bill_street`   VARCHAR(200) NOT NULL DEFAULT '',
  `bill_building` VARCHAR(30)  NOT NULL DEFAULT '',
  `bill_postcode` VARCHAR(10)  NOT NULL DEFAULT '',
  `bill_city`     VARCHAR(120) NOT NULL DEFAULT '',
  `bill_country`  CHAR(2)      NOT NULL DEFAULT 'PL',
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
  -- InPost : Paczkomat (code) ou coursier (adresse structurée)
  `delivery_method` VARCHAR(20)  NOT NULL DEFAULT 'inpost_locker',
  `inpost_point`    VARCHAR(20)  NOT NULL DEFAULT '',
  `street`          VARCHAR(200) NOT NULL DEFAULT '',
  `building`        VARCHAR(30)  NOT NULL DEFAULT '',
  `postcode`        VARCHAR(10)  NOT NULL DEFAULT '',
  `city`            VARCHAR(120) NOT NULL DEFAULT '',
  `country`         CHAR(2)      NOT NULL DEFAULT 'PL',
  `contact_phone`   VARCHAR(20)  NOT NULL DEFAULT '',
  `contact_email`   VARCHAR(200) NOT NULL DEFAULT '',
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
  `statut`      VARCHAR(40)  NOT NULL DEFAULT 'Do obsłużenia',
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

-- --- Landing Mister Szoko (contenu du site public, 3 langues) ---------------
-- Tout le texte de la landing vit ici (aucun libellé en dur dans la page) ;
-- seedé depuis landing/content_seed.json, éditable via l'API admin.
CREATE TABLE IF NOT EXISTS `wsm_landing_i18n` (
  `lang` VARCHAR(5)  NOT NULL,
  `k`    VARCHAR(64) NOT NULL,
  `v`    TEXT        NOT NULL,
  PRIMARY KEY (`lang`, `k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Données structurées des cartes produit (les textes sont dans wsm_landing_i18n
-- sous product.<id>.name/.meta/.specs).
CREATE TABLE IF NOT EXISTS `wsm_landing_products` (
  `id`              VARCHAR(32) NOT NULL,
  `sort_order`      INT NOT NULL DEFAULT 0,
  `swatch_from`     VARCHAR(32) NOT NULL DEFAULT '--choco-900',
  `swatch_to`       VARCHAR(32) NOT NULL DEFAULT '--choco-700',
  `fluidity`        TINYINT NOT NULL DEFAULT 3,
  `active`          TINYINT(1) NOT NULL DEFAULT 1,
  `price_from_pln`  DECIMAL(10,2) NULL,
  `price_perkg_pln` DECIMAL(10,2) NULL,
  `price_from_eur`  DECIMAL(10,2) NULL,
  `price_perkg_eur` DECIMAL(10,2) NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
--  BOUTIQUE EN LIGNE — commandes, paiements (tpay), expéditions (InPost)
-- ----------------------------------------------------------------------------
--  Tous les montants sont en GROSZE (entiers). Jamais de flottant sur de
--  l'argent : 0.1 + 0.2 != 0.3 en binaire, et une facture doit tomber juste.
--  Le client n'envoie JAMAIS un prix : le serveur les relit dans wsm_products.
-- ============================================================================

-- --- Modes de livraison (tarifs pilotés par la base, pas par le code) -------
CREATE TABLE IF NOT EXISTS `wsm_shipping_methods` (
  `id`           VARCHAR(24)  NOT NULL,          -- inpost_locker | inpost_courier
  `carrier`      VARCHAR(24)  NOT NULL DEFAULT 'inpost',
  `sort_order`   INT NOT NULL DEFAULT 0,
  `active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `price_net`    INT NOT NULL DEFAULT 0,         -- grosze, hors TVA
  `vat_rate`     DECIMAL(4,2) NOT NULL DEFAULT 0.23,
  `free_from`    INT NOT NULL DEFAULT 0,         -- franco en grosze TTC (0 = jamais)
  `max_weight_g` INT NOT NULL DEFAULT 25000,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Commandes --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_orders` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`            VARCHAR(24)  NOT NULL,       -- MS-260730-0001
  `access_token`    VARCHAR(64)  NOT NULL,       -- relire sa commande sans compte
  `lang`            VARCHAR(5)   NOT NULL DEFAULT 'pl',
  `currency`        CHAR(3)      NOT NULL DEFAULT 'PLN',
  `status`          VARCHAR(24)  NOT NULL DEFAULT 'nowe',
  `payment_status`  VARCHAR(24)  NOT NULL DEFAULT 'oczekuje',
  -- Acheteur : figé au moment de la commande (tpay payeur + InPost destinataire)
  `client_id`       INT UNSIGNED NULL DEFAULT NULL,
  `client_type`     VARCHAR(10)  NOT NULL DEFAULT 'osoba',
  `email`           VARCHAR(200) NOT NULL DEFAULT '',
  `phone`           VARCHAR(20)  NOT NULL DEFAULT '',
  `first_name`      VARCHAR(120) NOT NULL DEFAULT '',
  `last_name`       VARCHAR(120) NOT NULL DEFAULT '',
  `company`         VARCHAR(200) NOT NULL DEFAULT '',
  `nip`             VARCHAR(15)  NOT NULL DEFAULT '',
  `vat_eu`          VARCHAR(20)  NOT NULL DEFAULT '',
  `invoice`         TINYINT(1)   NOT NULL DEFAULT 0,
  `bill_street`     VARCHAR(200) NOT NULL DEFAULT '',
  `bill_building`   VARCHAR(30)  NOT NULL DEFAULT '',
  `bill_postcode`   VARCHAR(10)  NOT NULL DEFAULT '',
  `bill_city`       VARCHAR(120) NOT NULL DEFAULT '',
  `bill_country`    CHAR(2)      NOT NULL DEFAULT 'PL',
  -- Livraison
  `delivery_method` VARCHAR(24)  NOT NULL DEFAULT 'inpost_locker',
  `inpost_point`    VARCHAR(20)  NOT NULL DEFAULT '',
  `ship_street`     VARCHAR(200) NOT NULL DEFAULT '',
  `ship_building`   VARCHAR(30)  NOT NULL DEFAULT '',
  `ship_postcode`   VARCHAR(10)  NOT NULL DEFAULT '',
  `ship_city`       VARCHAR(120) NOT NULL DEFAULT '',
  `ship_country`    CHAR(2)      NOT NULL DEFAULT 'PL',
  -- Montants en grosze
  `items_net`       INT NOT NULL DEFAULT 0,
  `items_vat`       INT NOT NULL DEFAULT 0,
  `items_gross`     INT NOT NULL DEFAULT 0,
  `shipping_net`    INT NOT NULL DEFAULT 0,
  `shipping_vat`    INT NOT NULL DEFAULT 0,
  `shipping_gross`  INT NOT NULL DEFAULT 0,
  `total_net`       INT NOT NULL DEFAULT 0,
  `total_vat`       INT NOT NULL DEFAULT 0,
  `total_gross`     INT NOT NULL DEFAULT 0,
  `weight_g`        INT NOT NULL DEFAULT 0,
  `parcel_template` CHAR(1)      NOT NULL DEFAULT '',
  `note`            TEXT,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `paid_at`         DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_orders_code` (`code`),
  KEY `idx_wsm_orders_status` (`status`),
  KEY `idx_wsm_orders_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Lignes de commande (prix figés : une facture ne bouge plus) -------------
CREATE TABLE IF NOT EXISTS `wsm_order_items` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED NOT NULL,
  `product_id` VARCHAR(48)  NOT NULL,
  `name`       VARCHAR(200) NOT NULL,
  `sku`        VARCHAR(60)  NOT NULL DEFAULT '',
  `ean`        VARCHAR(14)  NOT NULL DEFAULT '',
  `qty`        INT NOT NULL DEFAULT 1,
  `unit_net`   INT NOT NULL DEFAULT 0,
  `unit_gross` INT NOT NULL DEFAULT 0,
  `vat_rate`   DECIMAL(4,2) NOT NULL DEFAULT 0.23,
  `line_net`   INT NOT NULL DEFAULT 0,
  `line_vat`   INT NOT NULL DEFAULT 0,
  `line_gross` INT NOT NULL DEFAULT 0,
  `weight_g`   INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_order_items_order` (`order_id`),
  CONSTRAINT `fk_wsm_order_items_order`
    FOREIGN KEY (`order_id`) REFERENCES `wsm_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Paiements tpay ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_payments` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     INT UNSIGNED NOT NULL,
  `provider`     VARCHAR(24)  NOT NULL DEFAULT 'tpay',
  `tr_id`        VARCHAR(64)  NOT NULL DEFAULT '',
  `tr_title`     VARCHAR(64)  NOT NULL DEFAULT '',
  `amount`       INT NOT NULL DEFAULT 0,
  `currency`     CHAR(3)      NOT NULL DEFAULT 'PLN',
  `status`       VARCHAR(24)  NOT NULL DEFAULT 'oczekuje',
  `redirect_url` VARCHAR(500) NOT NULL DEFAULT '',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_payments_order` (`order_id`),
  CONSTRAINT `fk_wsm_payments_order`
    FOREIGN KEY (`order_id`) REFERENCES `wsm_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Notifications de paiement : event_key UNIQUE = idempotence -------------
-- tpay réémet sa notification jusqu'à recevoir « TRUE ». Sans cette clé unique
-- une commande serait encaissée deux fois.
CREATE TABLE IF NOT EXISTS `wsm_payment_events` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     INT UNSIGNED NULL DEFAULT NULL,
  `provider`     VARCHAR(24)  NOT NULL DEFAULT 'tpay',
  `event_key`    VARCHAR(160) NOT NULL,
  `status`       VARCHAR(24)  NOT NULL DEFAULT '',
  `amount`       INT NOT NULL DEFAULT 0,
  `signature_ok` TINYINT(1)   NOT NULL DEFAULT 0,
  `raw`          TEXT,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_wsm_payment_events_key` (`event_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Expéditions InPost ShipX ----------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_shipments` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`        INT UNSIGNED NOT NULL,
  `carrier`         VARCHAR(24)  NOT NULL DEFAULT 'inpost',
  `service`         VARCHAR(24)  NOT NULL DEFAULT 'inpost_locker',
  `target_point`    VARCHAR(20)  NOT NULL DEFAULT '',
  `parcel_template` CHAR(1)      NOT NULL DEFAULT '',
  `weight_g`        INT NOT NULL DEFAULT 0,
  `shipment_id`     VARCHAR(64)  NOT NULL DEFAULT '',
  `tracking_number` VARCHAR(64)  NOT NULL DEFAULT '',
  `label_url`       VARCHAR(500) NOT NULL DEFAULT '',
  `status`          VARCHAR(32)  NOT NULL DEFAULT 'do_utworzenia',
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_shipments_order` (`order_id`),
  CONSTRAINT `fk_wsm_shipments_order`
    FOREIGN KEY (`order_id`) REFERENCES `wsm_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Journal d'une commande -------------------------------------------------
CREATE TABLE IF NOT EXISTS `wsm_order_events` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED NOT NULL,
  `event`      VARCHAR(48)  NOT NULL,
  `detail`     VARCHAR(255) NOT NULL DEFAULT '',
  `actor`      VARCHAR(120) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_order_events_order` (`order_id`),
  CONSTRAINT `fk_wsm_order_events_order`
    FOREIGN KEY (`order_id`) REFERENCES `wsm_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Textes de la boutique (pl / uk / en) — aucun libellé en dur -----------
CREATE TABLE IF NOT EXISTS `wsm_shop_i18n` (
  `lang` VARCHAR(5)  NOT NULL,
  `k`    VARCHAR(80) NOT NULL,
  `v`    TEXT        NOT NULL,
  PRIMARY KEY (`lang`, `k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Contrôles VIES (TVA intracommunautaire) --------------------------------
-- Un contrôle par ligne, JAMAIS écrasé : c'est l'historique qui prouve, en cas
-- de contrôle fiscal, qu'on a vérifié tel numéro à telle date. Le numéro de
-- consultation délivré par VIES est la pièce opposable.
CREATE TABLE IF NOT EXISTS `wsm_vies_checks` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `vat_eu`       VARCHAR(20)  NOT NULL,
  `country`      CHAR(2)      NOT NULL DEFAULT '',
  `number`       VARCHAR(20)  NOT NULL DEFAULT '',
  `status`       VARCHAR(16)  NOT NULL DEFAULT '',   -- valid | invalid | unavailable | skipped
  `reason`       VARCHAR(160) NOT NULL DEFAULT '',
  `name`         VARCHAR(250) NOT NULL DEFAULT '',
  `address`      VARCHAR(500) NOT NULL DEFAULT '',
  `consultation` VARCHAR(64)  NOT NULL DEFAULT '',
  `raw`          TEXT,
  `checked_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_vies_vat` (`vat_eu`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Pays servis ------------------------------------------------------------
-- Qui peut commander, et à quel régime de TVA. `is_eu` n'est pas décoratif :
-- c'est lui qui autorise l'autoliquidation (0 %) pour un acheteur professionnel
-- d'un autre État membre. La Pologne est le marché intérieur : jamais 0 %.
CREATE TABLE IF NOT EXISTS `wsm_countries` (
  `code`       CHAR(2)      NOT NULL,
  `name_pl`    VARCHAR(80)  NOT NULL,
  `name_uk`    VARCHAR(80)  NOT NULL DEFAULT '',
  `name_en`    VARCHAR(80)  NOT NULL DEFAULT '',
  `is_eu`      TINYINT(1)   NOT NULL DEFAULT 1,
  `active`     TINYINT(1)   NOT NULL DEFAULT 0,   -- ouvert à la commande
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Rabaty ilościowe : remise en % selon le POIDS total du panier ----------
-- Le kilogramme baisse avec le volume. Les paliers sont de la donnée, pas du
-- code : le back-office les règle sans redéploiement.
CREATE TABLE IF NOT EXISTS `wsm_discount_tiers` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `min_weight_g` INT NOT NULL DEFAULT 0,          -- à partir de ce poids
  `percent`      DECIMAL(5,2) NOT NULL DEFAULT 0, -- remise appliquée aux produits
  `label`        VARCHAR(80)  NOT NULL DEFAULT '',
  `active`       TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_wsm_discount_weight` (`min_weight_g`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
