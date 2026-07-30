# php-api — `webshop_mrszoko` backend

The server the back-office is wired to. It exposes the `/franchisor/*` endpoints
over same-origin `<origin>/mrszoko/backoffice/api`, reading and writing the **`webshop_mrszoko`**
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

**On the server the API runs against the MySQL database `mrszoko`** (the one
visible in phpMyAdmin). The deploy workflow provisions it automatically on
first run: dedicated `mrszoko_app` user + `config.local.php` (engine mysql,
db `mrszoko`, generated password and admin token). `config.local.php` is
gitignored, web-denied and never overwritten by deploys — read the admin token
from it over SSH. The schema is applied INTO the configured database (the
canonical file's `CREATE DATABASE webshop_mrszoko`/`USE` header is skipped),
so the DB name is free.

Manual/env alternative:

```bash
export WSM_DB_ENGINE=mysql
export WSM_DB_HOST=127.0.0.1 WSM_DB_NAME=mrszoko
export WSM_DB_USER=... WSM_DB_PASS=...
export WSM_ADMIN_TOKEN=<shared admin token>
php migrate.php              # applies schema/webshop_mrszoko.mysql.sql + seed
```

The canonical MySQL DDL is `schema/webshop_mrszoko.mysql.sql`; the SQLite
mirror (`schema/webshop_mrszoko.sqlite.sql`, dev/CI fallback) is kept
structurally identical.

## Authentication

Nothing under `/franchisor/*` is public — **reads included**. Two ways in, and
neither has a default secret (fail-closed):

1. **User session** — `POST /auth/login {email, password}` against
   `wsm_users.password_hash` (`password_hash`/`PASSWORD_DEFAULT`). Sets an
   HttpOnly, SameSite=Lax session cookie (Secure as soon as the request is
   HTTPS); the id is regenerated on login. Idle sessions expire after 8 h.
   Five failed attempts lock the account for 15 minutes; wrong password and
   unknown e-mail return the exact same `401`, after a randomised delay.
2. **Service token** — `X-Admin-Token`, for automation and the test suites.
   Compared with `hash_equals`. **If no token is configured the whole token
   path is disabled** — there is no `dev-admin-token` fallback any more.

Roles from `wsm_users` are enforced, not decorative: any active account may
**read**, only role `Centrala` may **write** (`403 forbidden_role` otherwise).
The audit trail records the real actor, not a generic label.

Accounts have no password until one is set, so the demo users cannot log in:

```bash
php migrate.php --set-password <email> <password> [role] [name]
php migrate.php --ensure-admin <email> <password>   # no-op if a login exists
```

CORS emits nothing by default (front and API are same-origin). Set
`WSM_CORS_ORIGIN` to an explicit origin — never `*`, since requests carry a
session cookie.

## tpay.com and InPost — data captured

Neither API is called yet; what exists is the **data they will require**, captured
and validated at entry (`commerce.php`). A wrong NIP gets the invoice rejected and
an 8-digit phone gets the shipment rejected — cheaper to block on the form than to
discover it at checkout.

| Need | Where | Validation |
| --- | --- | --- |
| tpay payer | `wsm_clients.email` (mandatory), `phone` | e-mail syntax; phone normalised to 9 digits (`+48` stripped) |
| tpay invoice | `nip`, `vat_eu`, `bill_street/building/postcode/city/country` | NIP checksum; VIES format; postcode `NN-NNN` validated on the **raw** input |
| tpay VAT | `wsm_products.vat_rate` | Polish rates only: 0 / 5 / 8 / 23 % (`23` accepted as 23 %) |
| InPost receiver | `client_type`, `first_name`, `last_name`, `phone` | an individual must be named — InPost needs a named receiver |
| InPost locker | `wsm_client_points.inpost_point`, `delivery_method` | code format (`KRA010`), stored uppercase |
| InPost courier | `street`, `building`, `postcode`, `city`, `country` | all mandatory for the courier method |
| InPost parcel | `wsm_products.weight_g`, `length/width/height_mm`, `parcel_template` | ≤ 25 kg; A/B/C **deduced** from dimensions, recomputed whenever they change |

Invalid input returns `422` with a per-field map (`{"error":"validation","fields":{…}}`),
which the console shows next to the offending field. Proof: `tests/e2e_commerce.php`
(40 assertions).

## Configuration

All in `config.php`, entirely env-driven (see the header there).

## Endpoints

| Method | Route | Source table(s) |
| --- | --- | --- |
| **Authentication** | | |
| POST | `/auth/login` | `wsm_users` — opens a session |
| POST | `/auth/logout` | destroys the session |
| GET | `/auth/me` | current identity (session or service token) |
| **Landing Mister Szoko (public)** | | |
| GET | `/landing/content?lang=pl\|uk\|en` | `wsm_landing_i18n` · `wsm_landing_products` — everything the landing renders (strings + product cards, texts resolved server-side; unknown lang → default `pl`) |
| POST | `/franchisor/landing-string` | upsert/delete one i18n string (admin) |
| POST | `/franchisor/landing-product` | upsert/delete one landing product card (admin) |
| **Commerce (tpay + InPost)** | | |
| POST | `/franchisor/client` | upsert/delete `wsm_clients` — validated payer + invoice data |
| POST | `/franchisor/client-point` | upsert/delete `wsm_client_points` — locker code or courier address |
| POST | `/franchisor/product` | product governance **and** shipping/VAT fields |
| **Franchisor (console)** | | |
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
