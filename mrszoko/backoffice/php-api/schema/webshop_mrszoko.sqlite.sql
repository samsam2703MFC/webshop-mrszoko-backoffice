-- ============================================================================
--  webshop_mrszoko — SQLite mirror of the canonical MySQL schema.
--  Structurally identical (same tables, columns, wsm_ prefix). Used for local
--  dev / CI / the end-to-end delivery test, where no MySQL server is present.
--  Production uses webshop_mrszoko.mysql.sql. Keep the two in sync.
-- ============================================================================
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS wsm_kpis (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  sort_order  INTEGER NOT NULL DEFAULT 0,
  label       TEXT NOT NULL,
  value       TEXT NOT NULL,
  val_color   TEXT NOT NULL DEFAULT 'var(--color-text)',
  delta       TEXT NOT NULL DEFAULT '',
  delta_color TEXT NOT NULL DEFAULT '#2d7a3e'
);

CREATE TABLE IF NOT EXISTS wsm_shops (
  id         TEXT PRIMARY KEY,
  nom        TEXT NOT NULL,
  ville      TEXT NOT NULL DEFAULT '',
  web        INTEGER NOT NULL DEFAULT 1,
  contrat    TEXT NOT NULL DEFAULT 'Franchise',
  act        INTEGER NOT NULL DEFAULT 1,
  ca_shop    INTEGER NOT NULL DEFAULT 0,
  ca_office  INTEGER NOT NULL DEFAULT 0,
  adoption   INTEGER NOT NULL DEFAULT 0,
  accent     TEXT NOT NULL DEFAULT 'var(--color-primary)',
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS wsm_categories (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  name            TEXT NOT NULL UNIQUE,
  sort_order      INTEGER NOT NULL DEFAULT 0,
  menu_default    INTEGER NOT NULL DEFAULT 0,
  brand_whitelist INTEGER NOT NULL DEFAULT 1,
  office_delivery INTEGER NOT NULL DEFAULT 0,
  brand_mandatory INTEGER NOT NULL DEFAULT 0,
  active          INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS wsm_products (
  id              TEXT PRIMARY KEY,
  -- Vitrine de la boutique (textes traduits dans wsm_shop_i18n)
  slug            TEXT NOT NULL DEFAULT '',
  shop_visible    INTEGER NOT NULL DEFAULT 0,
  stock           INTEGER NOT NULL DEFAULT 0,
  image_url       TEXT NOT NULL DEFAULT '',
  swatch_from     TEXT NOT NULL DEFAULT '--choco-500',
  swatch_to       TEXT NOT NULL DEFAULT '--choco-800',
  origin          TEXT NOT NULL DEFAULT '',
  cocoa           TEXT NOT NULL DEFAULT '',
  unit_label      TEXT NOT NULL DEFAULT '',
  badge           TEXT NOT NULL DEFAULT '',
  -- Expédition InPost + fiscalité tpay
  sku             TEXT NOT NULL DEFAULT '',
  ean             TEXT NOT NULL DEFAULT '',
  vat_rate        REAL NOT NULL DEFAULT 0.23,    -- PL : 0 / 0.05 / 0.08 / 0.23
  weight_g        INTEGER NOT NULL DEFAULT 0,
  length_mm       INTEGER NOT NULL DEFAULT 0,
  width_mm        INTEGER NOT NULL DEFAULT 0,
  height_mm       INTEGER NOT NULL DEFAULT 0,
  parcel_template TEXT NOT NULL DEFAULT '',      -- A | B | C (déduit si vide)
  category_id     INTEGER NOT NULL REFERENCES wsm_categories(id) ON DELETE CASCADE,
  nom             TEXT NOT NULL,
  prix            REAL NOT NULL DEFAULT 0,
  base_cost       REAL NOT NULL DEFAULT 0,
  statut          TEXT NOT NULL DEFAULT 'Publié',
  saison          TEXT NOT NULL DEFAULT '',
  brand_whitelist INTEGER NOT NULL DEFAULT 0,
  brand_mandatory INTEGER NOT NULL DEFAULT 0,
  adoption        INTEGER NOT NULL DEFAULT 0,
  menu_override   TEXT DEFAULT NULL,
  sort_order      INTEGER NOT NULL DEFAULT 0,
  active          INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS wsm_vouchers (
  id       INTEGER PRIMARY KEY AUTOINCREMENT,
  code     TEXT NOT NULL UNIQUE,
  valeur   TEXT NOT NULL DEFAULT '',
  type     TEXT NOT NULL DEFAULT 'Panier',
  validite TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS wsm_pricing_rules (
  id      INTEGER PRIMARY KEY AUTOINCREMENT,
  nom     TEXT NOT NULL,
  cible   TEXT NOT NULL DEFAULT '',
  effet   TEXT NOT NULL DEFAULT '',
  shop_id TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wsm_params (
  cle  TEXT PRIMARY KEY,
  type TEXT NOT NULL DEFAULT 'text',
  val  TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS wsm_email_templates (
  id     INTEGER PRIMARY KEY AUTOINCREMENT,
  cle    TEXT NOT NULL,
  langue TEXT NOT NULL DEFAULT 'FR',
  sujet  TEXT NOT NULL DEFAULT '',
  UNIQUE (cle, langue)
);

CREATE TABLE IF NOT EXISTS wsm_users (
  id     INTEGER PRIMARY KEY AUTOINCREMENT,
  nom    TEXT NOT NULL,
  email  TEXT NOT NULL UNIQUE,
  role   TEXT NOT NULL DEFAULT 'Franczyza',
  portee TEXT NOT NULL DEFAULT '',
  act    INTEGER NOT NULL DEFAULT 1,
  -- Authentification (voir auth.php) : sans hachage, le compte ne peut pas
  -- se connecter.
  password_hash   TEXT DEFAULT NULL,
  last_login      TEXT DEFAULT NULL,
  failed_attempts INTEGER NOT NULL DEFAULT 0,
  locked_until    TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wsm_user_shops (
  user_id INTEGER NOT NULL REFERENCES wsm_users(id) ON DELETE CASCADE,
  shop_id TEXT NOT NULL,
  PRIMARY KEY (user_id, shop_id)
);

CREATE TABLE IF NOT EXISTS wsm_audit (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  ts         TEXT NOT NULL DEFAULT '',
  user       TEXT NOT NULL DEFAULT '',
  verb       TEXT NOT NULL DEFAULT '',
  entity     TEXT NOT NULL DEFAULT '',
  shop       TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wsm_catchment (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  name      TEXT NOT NULL,
  postcodes TEXT,
  exclusive INTEGER NOT NULL DEFAULT 1,
  active    INTEGER NOT NULL DEFAULT 1,
  shop_id   TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wsm_bundles (
  id             TEXT PRIMARY KEY,
  product_id     TEXT NOT NULL,
  name           TEXT NOT NULL DEFAULT 'Nouvelle formule',
  description    TEXT NOT NULL DEFAULT '',
  price_modifier REAL NOT NULL DEFAULT 0,
  sort_order     INTEGER NOT NULL DEFAULT 0,
  active         INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS wsm_bundle_slots (
  id         TEXT PRIMARY KEY,
  bundle_id  TEXT NOT NULL REFERENCES wsm_bundles(id) ON DELETE CASCADE,
  label      TEXT NOT NULL DEFAULT 'Nouvelle étape',
  required   INTEGER NOT NULL DEFAULT 1,
  kind       TEXT NOT NULL DEFAULT 'single',
  min_select INTEGER NOT NULL DEFAULT 1,
  max_select INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  active     INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS wsm_bundle_slot_choices (
  id         TEXT PRIMARY KEY,
  slot_id    TEXT NOT NULL REFERENCES wsm_bundle_slots(id) ON DELETE CASCADE,
  label      TEXT NOT NULL DEFAULT 'Nouveau choix',
  img        TEXT NOT NULL DEFAULT '',
  delta      REAL NOT NULL DEFAULT 0,
  cost       REAL NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  active     INTEGER NOT NULL DEFAULT 1
);

-- ===== DELIVERY MODULE ======================================================
CREATE TABLE IF NOT EXISTS wsm_clients (
  id       INTEGER PRIMARY KEY AUTOINCREMENT,
  -- tpay (payeur + facture) & InPost (destinataire) — voir commerce.php
  client_type   TEXT NOT NULL DEFAULT 'firma',   -- firma | osoba
  email         TEXT NOT NULL DEFAULT '',        -- tpay : obligatoire
  phone         TEXT NOT NULL DEFAULT '',        -- InPost : 9 chiffres
  first_name    TEXT NOT NULL DEFAULT '',
  last_name     TEXT NOT NULL DEFAULT '',
  nip           TEXT NOT NULL DEFAULT '',        -- NIP polonais (facture)
  vat_eu        TEXT NOT NULL DEFAULT '',        -- TVA intracom. (VIES)
  bill_street   TEXT NOT NULL DEFAULT '',
  bill_building TEXT NOT NULL DEFAULT '',
  bill_postcode TEXT NOT NULL DEFAULT '',        -- NN-NNN
  bill_city     TEXT NOT NULL DEFAULT '',
  bill_country  TEXT NOT NULL DEFAULT 'PL',
  code     TEXT NOT NULL UNIQUE,
  raison   TEXT NOT NULL,
  seg      TEXT NOT NULL DEFAULT 'horeca',
  statut   TEXT NOT NULL DEFAULT 'actif',
  tva      TEXT NOT NULL DEFAULT '',
  paiement TEXT NOT NULL DEFAULT '',
  plafond  INTEGER NOT NULL DEFAULT 0,
  encours  INTEGER NOT NULL DEFAULT 0,
  franco   TEXT NOT NULL DEFAULT '',
  remise   TEXT NOT NULL DEFAULT '',
  fact     TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS wsm_client_points (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  -- InPost : Paczkomat (code) ou coursier (adresse structurée)
  delivery_method TEXT NOT NULL DEFAULT 'inpost_locker',
  inpost_point    TEXT NOT NULL DEFAULT '',      -- ex. KRA010
  street          TEXT NOT NULL DEFAULT '',
  building        TEXT NOT NULL DEFAULT '',
  postcode        TEXT NOT NULL DEFAULT '',
  city            TEXT NOT NULL DEFAULT '',
  country         TEXT NOT NULL DEFAULT 'PL',
  contact_phone   TEXT NOT NULL DEFAULT '',
  contact_email   TEXT NOT NULL DEFAULT '',
  client_id  INTEGER NOT NULL REFERENCES wsm_clients(id) ON DELETE CASCADE,
  libelle    TEXT NOT NULL,
  adresse    TEXT NOT NULL DEFAULT '',
  fenetre    TEXT NOT NULL DEFAULT '',
  jours      TEXT NOT NULL DEFAULT '',
  validation TEXT NOT NULL DEFAULT 'QR',
  marge      INTEGER NOT NULL DEFAULT 0,
  lat        REAL DEFAULT NULL,
  lng        REAL DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wsm_drivers (
  id       INTEGER PRIMARY KEY AUTOINCREMENT,
  nom      TEXT NOT NULL,
  info     TEXT NOT NULL DEFAULT '',
  color    TEXT NOT NULL DEFAULT '#8D1D2C',
  vehicule TEXT NOT NULL DEFAULT '',
  zone     TEXT NOT NULL DEFAULT '',
  active   INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS wsm_rounds (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL,
  driver_id  INTEGER REFERENCES wsm_drivers(id) ON DELETE SET NULL,
  round_date TEXT DEFAULT NULL,
  status     TEXT NOT NULL DEFAULT 'planifiée'
);

CREATE TABLE IF NOT EXISTS wsm_deliveries (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  ref               TEXT NOT NULL UNIQUE,
  client_id         INTEGER REFERENCES wsm_clients(id) ON DELETE SET NULL,
  point_id          INTEGER REFERENCES wsm_client_points(id) ON DELETE SET NULL,
  driver_id         INTEGER REFERENCES wsm_drivers(id) ON DELETE SET NULL,
  round_id          INTEGER REFERENCES wsm_rounds(id) ON DELETE SET NULL,
  status            TEXT NOT NULL DEFAULT 'brouillon',
  window_label      TEXT NOT NULL DEFAULT '',
  validation_method TEXT NOT NULL DEFAULT 'QR',
  confirm_code      TEXT NOT NULL DEFAULT '',
  confirmed         INTEGER NOT NULL DEFAULT 0,
  ca                REAL NOT NULL DEFAULT 0,
  couts             REAL NOT NULL DEFAULT 0,
  scheduled_date    TEXT DEFAULT NULL,
  notes             TEXT NOT NULL DEFAULT '',
  created_at        TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at      TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wsm_delivery_events (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  delivery_id INTEGER NOT NULL REFERENCES wsm_deliveries(id) ON DELETE CASCADE,
  event       TEXT NOT NULL,
  detail      TEXT NOT NULL DEFAULT '',
  actor       TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wsm_incidents (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  ref         TEXT NOT NULL UNIQUE,
  delivery_id INTEGER REFERENCES wsm_deliveries(id) ON DELETE SET NULL,
  type        TEXT NOT NULL,
  point       TEXT NOT NULL DEFAULT '',
  statut      TEXT NOT NULL DEFAULT 'Do obsłużenia',
  impact      TEXT NOT NULL DEFAULT '',
  description TEXT,
  geo         TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- --- Landing Mister Szoko (contenu du site public, 3 langues) ---------------
-- Tout le texte de la landing vit ici (aucun libellé en dur dans la page) ;
-- seedé depuis landing/content_seed.json, éditable via l'API admin.
CREATE TABLE IF NOT EXISTS wsm_landing_i18n (
  lang TEXT NOT NULL,
  k    TEXT NOT NULL,
  v    TEXT NOT NULL,
  PRIMARY KEY (lang, k)
);

-- Données structurées des cartes produit (les textes sont dans wsm_landing_i18n
-- sous product.<id>.name/.meta/.specs).
CREATE TABLE IF NOT EXISTS wsm_landing_products (
  id              TEXT PRIMARY KEY,
  sort_order      INTEGER NOT NULL DEFAULT 0,
  swatch_from     TEXT NOT NULL DEFAULT '--choco-900',
  swatch_to      TEXT NOT NULL DEFAULT '--choco-700',
  fluidity        INTEGER NOT NULL DEFAULT 3,
  active          INTEGER NOT NULL DEFAULT 1,
  price_from_pln  REAL,
  price_perkg_pln REAL,
  price_from_eur  REAL,
  price_perkg_eur REAL
);

-- ============================================================================
--  BOUTIQUE EN LIGNE — miroir SQLite (dev / CI), structurellement identique.
--  Montants en GROSZE (entiers) : jamais de flottant sur de l'argent.
-- ============================================================================

CREATE TABLE IF NOT EXISTS wsm_shipping_methods (
  id           TEXT PRIMARY KEY,
  carrier      TEXT NOT NULL DEFAULT 'inpost',
  sort_order   INTEGER NOT NULL DEFAULT 0,
  active       INTEGER NOT NULL DEFAULT 1,
  price_net    INTEGER NOT NULL DEFAULT 0,
  vat_rate     REAL NOT NULL DEFAULT 0.23,
  free_from    INTEGER NOT NULL DEFAULT 0,
  max_weight_g INTEGER NOT NULL DEFAULT 25000
);

CREATE TABLE IF NOT EXISTS wsm_orders (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  code            TEXT NOT NULL UNIQUE,
  access_token    TEXT NOT NULL,
  lang            TEXT NOT NULL DEFAULT 'pl',
  currency        TEXT NOT NULL DEFAULT 'PLN',
  status          TEXT NOT NULL DEFAULT 'nowe',
  payment_status  TEXT NOT NULL DEFAULT 'oczekuje',
  client_id       INTEGER REFERENCES wsm_clients(id) ON DELETE SET NULL,
  client_type     TEXT NOT NULL DEFAULT 'osoba',
  email           TEXT NOT NULL DEFAULT '',
  phone           TEXT NOT NULL DEFAULT '',
  first_name      TEXT NOT NULL DEFAULT '',
  last_name       TEXT NOT NULL DEFAULT '',
  company         TEXT NOT NULL DEFAULT '',
  nip             TEXT NOT NULL DEFAULT '',
  vat_eu          TEXT NOT NULL DEFAULT '',
  invoice         INTEGER NOT NULL DEFAULT 0,
  bill_street     TEXT NOT NULL DEFAULT '',
  bill_building   TEXT NOT NULL DEFAULT '',
  bill_postcode   TEXT NOT NULL DEFAULT '',
  bill_city       TEXT NOT NULL DEFAULT '',
  bill_country    TEXT NOT NULL DEFAULT 'PL',
  delivery_method TEXT NOT NULL DEFAULT 'inpost_locker',
  inpost_point    TEXT NOT NULL DEFAULT '',
  ship_street     TEXT NOT NULL DEFAULT '',
  ship_building   TEXT NOT NULL DEFAULT '',
  ship_postcode   TEXT NOT NULL DEFAULT '',
  ship_city       TEXT NOT NULL DEFAULT '',
  ship_country    TEXT NOT NULL DEFAULT 'PL',
  items_net       INTEGER NOT NULL DEFAULT 0,
  items_vat       INTEGER NOT NULL DEFAULT 0,
  items_gross     INTEGER NOT NULL DEFAULT 0,
  shipping_net    INTEGER NOT NULL DEFAULT 0,
  shipping_vat    INTEGER NOT NULL DEFAULT 0,
  shipping_gross  INTEGER NOT NULL DEFAULT 0,
  total_net       INTEGER NOT NULL DEFAULT 0,
  total_vat       INTEGER NOT NULL DEFAULT 0,
  total_gross     INTEGER NOT NULL DEFAULT 0,
  weight_g        INTEGER NOT NULL DEFAULT 0,
  parcel_template TEXT NOT NULL DEFAULT '',
  note            TEXT,
  created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at         TEXT DEFAULT NULL
);

CREATE TABLE IF NOT EXISTS wsm_order_items (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id   INTEGER NOT NULL REFERENCES wsm_orders(id) ON DELETE CASCADE,
  product_id TEXT NOT NULL,
  name       TEXT NOT NULL,
  sku        TEXT NOT NULL DEFAULT '',
  ean        TEXT NOT NULL DEFAULT '',
  qty        INTEGER NOT NULL DEFAULT 1,
  unit_net   INTEGER NOT NULL DEFAULT 0,
  unit_gross INTEGER NOT NULL DEFAULT 0,
  vat_rate   REAL NOT NULL DEFAULT 0.23,
  line_net   INTEGER NOT NULL DEFAULT 0,
  line_vat   INTEGER NOT NULL DEFAULT 0,
  line_gross INTEGER NOT NULL DEFAULT 0,
  weight_g   INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS wsm_payments (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id     INTEGER NOT NULL REFERENCES wsm_orders(id) ON DELETE CASCADE,
  provider     TEXT NOT NULL DEFAULT 'tpay',
  tr_id        TEXT NOT NULL DEFAULT '',
  tr_title     TEXT NOT NULL DEFAULT '',
  amount       INTEGER NOT NULL DEFAULT 0,
  currency     TEXT NOT NULL DEFAULT 'PLN',
  status       TEXT NOT NULL DEFAULT 'oczekuje',
  redirect_url TEXT NOT NULL DEFAULT '',
  created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wsm_payment_events (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id     INTEGER REFERENCES wsm_orders(id) ON DELETE SET NULL,
  provider     TEXT NOT NULL DEFAULT 'tpay',
  event_key    TEXT NOT NULL UNIQUE,
  status       TEXT NOT NULL DEFAULT '',
  amount       INTEGER NOT NULL DEFAULT 0,
  signature_ok INTEGER NOT NULL DEFAULT 0,
  raw          TEXT,
  created_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wsm_shipments (
  id              INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id        INTEGER NOT NULL REFERENCES wsm_orders(id) ON DELETE CASCADE,
  carrier         TEXT NOT NULL DEFAULT 'inpost',
  service         TEXT NOT NULL DEFAULT 'inpost_locker',
  target_point    TEXT NOT NULL DEFAULT '',
  parcel_template TEXT NOT NULL DEFAULT '',
  weight_g        INTEGER NOT NULL DEFAULT 0,
  shipment_id     TEXT NOT NULL DEFAULT '',
  tracking_number TEXT NOT NULL DEFAULT '',
  label_url       TEXT NOT NULL DEFAULT '',
  status          TEXT NOT NULL DEFAULT 'do_utworzenia',
  created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wsm_order_events (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id   INTEGER NOT NULL REFERENCES wsm_orders(id) ON DELETE CASCADE,
  event      TEXT NOT NULL,
  detail     TEXT NOT NULL DEFAULT '',
  actor      TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wsm_shop_i18n (
  lang TEXT NOT NULL,
  k    TEXT NOT NULL,
  v    TEXT NOT NULL,
  PRIMARY KEY (lang, k)
);

-- --- Contrôles VIES (miroir SQLite) -----------------------------------------
CREATE TABLE IF NOT EXISTS wsm_vies_checks (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  vat_eu       TEXT NOT NULL,
  country      TEXT NOT NULL DEFAULT '',
  number       TEXT NOT NULL DEFAULT '',
  status       TEXT NOT NULL DEFAULT '',
  reason       TEXT NOT NULL DEFAULT '',
  name         TEXT NOT NULL DEFAULT '',
  address      TEXT NOT NULL DEFAULT '',
  consultation TEXT NOT NULL DEFAULT '',
  raw          TEXT,
  checked_at   TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_wsm_vies_vat ON wsm_vies_checks (vat_eu, id);

-- --- Pays servis (miroir SQLite) --------------------------------------------
CREATE TABLE IF NOT EXISTS wsm_countries (
  code       TEXT PRIMARY KEY,
  name_pl    TEXT NOT NULL,
  name_uk    TEXT NOT NULL DEFAULT '',
  name_en    TEXT NOT NULL DEFAULT '',
  is_eu      INTEGER NOT NULL DEFAULT 1,
  active     INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0
);

-- --- Rabaty ilościowe (miroir SQLite) ---------------------------------------
CREATE TABLE IF NOT EXISTS wsm_discount_tiers (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  min_weight_g INTEGER NOT NULL DEFAULT 0,
  percent      REAL NOT NULL DEFAULT 0,
  label        TEXT NOT NULL DEFAULT '',
  active       INTEGER NOT NULL DEFAULT 1
);
