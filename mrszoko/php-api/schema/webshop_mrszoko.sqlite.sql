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
  role   TEXT NOT NULL DEFAULT 'Franchise',
  portee TEXT NOT NULL DEFAULT '',
  act    INTEGER NOT NULL DEFAULT 1
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
  statut      TEXT NOT NULL DEFAULT 'À traiter',
  impact      TEXT NOT NULL DEFAULT '',
  description TEXT,
  geo         TEXT NOT NULL DEFAULT '',
  created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
