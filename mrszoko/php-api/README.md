# php-api — `webshop_mrszoko` backend

The server the back-office is wired to. It exposes the `/franchisor/*` endpoints
over same-origin `<origin>/mrszoko/api`, reading and writing the **`webshop_mrszoko`**
database whose tables are all prefixed **`wsm_`**. No business data is hardcoded
in the front-end: every screen reads these tables through this API.

## Run it (local / CI — zero server needed)

PHP ships `pdo_sqlite`, so out of the box the API runs on a local SQLite mirror
of the schema — the same tables, seeded identically:

```bash
php migrate.php --fresh      # build webshop_mrszoko + wsm_* tables and seed
./serve.sh                   # → http://localhost:8090/franchisor/kpis
php tests/e2e_delivery.php    # end-to-end delivery proof (create→assign→confirm)
```

## Run it against MySQL (production)

```bash
export WSM_DB_ENGINE=mysql
export WSM_DB_HOST=127.0.0.1 WSM_DB_NAME=webshop_mrszoko
export WSM_DB_USER=... WSM_DB_PASS=...
export WSM_ADMIN_TOKEN=<shared admin token>
php migrate.php              # applies schema/webshop_mrszoko.mysql.sql + seed
```

The canonical MySQL DDL is `schema/webshop_mrszoko.mysql.sql`
(`CREATE DATABASE webshop_mrszoko` + all `wsm_` tables). The SQLite mirror
(`schema/webshop_mrszoko.sqlite.sql`) is kept structurally identical.

## Configuration

All in `config.php`, entirely env-driven (see the header there). Writes require
the admin token via the `X-Admin-Token` header — the front-end already sends
`localStorage['adminToken']`.

## Endpoints

| Method | Route | Source table(s) |
| --- | --- | --- |
| GET | `/franchisor/kpis` | `wsm_kpis` |
| GET | `/franchisor/shops` | `wsm_shops` |
| GET | `/franchisor/catalog` | `wsm_categories` · `wsm_products` |
| GET | `/franchisor/vouchers` | `wsm_vouchers` |
| GET | `/franchisor/pricing-rules` | `wsm_pricing_rules` |
| GET | `/franchisor/params` | `wsm_params` |
| GET | `/franchisor/email-templates` | `wsm_email_templates` |
| GET | `/franchisor/users` | `wsm_users` (+ `wsm_user_shops`) |
| GET | `/franchisor/audit` | `wsm_audit` |
| GET | `/franchisor/catchment` | `wsm_catchment` |
| GET | `/franchisor/menus` | `wsm_products` · `wsm_bundles` → `slots` → `choices` |
| POST | `/franchisor/param` | upsert `wsm_params` (admin) |
| POST | `/franchisor/category` | update `wsm_categories` flags (admin) |
| POST | `/franchisor/catchment` | upsert / delete `wsm_catchment` (admin) |
| **Delivery module** | | |
| GET | `/franchisor/deliveries` | `wsm_deliveries` (+ client/point/driver) |
| GET | `/franchisor/delivery-kpis` | aggregate of `wsm_deliveries` |
| GET | `/franchisor/drivers` | `wsm_drivers` |
| GET | `/franchisor/rounds` | `wsm_rounds` |
| GET | `/franchisor/delivery-clients` | `wsm_clients` (+ `wsm_client_points`) |
| GET | `/franchisor/incidents` | `wsm_incidents` |
| GET | `/franchisor/deliveries/{id}` · `/events` | one delivery · its `wsm_delivery_events` |
| POST | `/franchisor/deliveries` | create a delivery (admin) |
| POST | `/franchisor/deliveries/{id}/assign` | assign driver / round (admin) |
| POST | `/franchisor/deliveries/{id}/status` | status transition (admin) |
| POST | `/franchisor/deliveries/{id}/confirm` | confirm QR/PIN → livrée (admin) |

## Delivery lifecycle

`planifiée → assignée → en_cours → livrée` (or `échouée`). Every transition
writes a `wsm_delivery_events` row; create + confirm also write `wsm_audit`.
Confirmation checks the QR/PIN code issued at creation. The whole flow is
exercised by `tests/e2e_delivery.php` (23 assertions, all green).
