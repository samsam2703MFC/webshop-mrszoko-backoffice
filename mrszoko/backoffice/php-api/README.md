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

## The shop — who decides the price

The storefront lives in `../../shop` and is rendered **server-side by PHP from
these tables**. The browser only ever sends product ids and quantities; every
price, VAT amount, shipping fee and total is recomputed here (`shop.php`). A
tampered basket changes nothing about what gets charged — `tests/e2e_shop.php`
asserts exactly that.

Money is carried in **grosze (integers)**. Floats have no business holding money:
`0.1 + 0.2` is not `0.3` in binary, and a VAT line has to add up. Consumer prices
are stored gross (`wsm_products.prix`, Polish B2C convention); net is derived and
VAT is the *remainder*, so `net + VAT == gross` always holds, line by line.

| Table | Holds |
| --- | --- |
| `wsm_orders` | one order: buyer snapshot, delivery target, all totals |
| `wsm_order_items` | frozen lines — an invoice must not move when a price does |
| `wsm_payments` | tpay attempts (transaction id, amount, status) |
| `wsm_payment_events` | every notification received; `event_key` **UNIQUE** = no double capture |
| `wsm_shipments` | InPost parcel: service, locker code, template, tracking |
| `wsm_order_events` | audit trail per order |
| `wsm_shipping_methods` | delivery options and prices — data, not code |
| `wsm_shop_i18n` | every shop string in pl / uk / en |

Stock is decremented **inside the order transaction** (`UPDATE … WHERE stock >= ?`),
so two simultaneous orders for the last bag cannot both succeed.

The parcel template (A/B/C) is computed from the **whole basket's volume**, not
from the largest single item — two 6 cm bags each fit an 8 cm locker, together
they do not. It stays an estimate (a real packing solver would be needed); the
console labels it as such and the packer can override.

## tpay — what makes the money real

`POST /shop/tpay/notify` is the only thing that marks an order paid. The browser
return URL never does: it is under the buyer's control. Three guards, all tested:

1. **Signature first.** `md5(id + tr_id + tr_amount + tr_crc + security_code)`,
   compared with `hash_equals`. A rejected notification is archived under a
   throwaway key — if it took the real idempotency key, knowing a `tr_id` would
   be enough to stop a legitimate payment from ever being booked.
2. **Amount recheck.** An authentic notification for the wrong amount is refused.
3. **Idempotency in the database.** `wsm_payment_events.event_key UNIQUE`. tpay
   resends until it reads `TRUE`; the unique index — not application logic — is
   what stops a second capture, because two concurrent retries would race.

Without `security_code` configured, **every** notification is refused (`503`).
Without `client_id`/`client_secret`, no transaction is created: the order still
exists and waits. Nothing has a default value.

InPost is the same shape: `inpost.php` builds the exact ShipX payload and shows
it in the console *before* the integration is switched on, so missing data is
visible immediately. Without a ShipX token nothing is sent — the parcel is
prepared by hand and the shipment row stays `oczekuje_na_konfiguracje`.

## Product photos

Uploaded from the console (`/mrszoko/backoffice/produkty.php`, role `Centrala`),
or through `POST /franchisor/product-photo`.

A file is not an image because it ends in `.jpg`. It is one because we managed
to decode it — so every upload is decoded and **re-encoded** by GD (`media.php`).
What lands in `shop/media/` is an image *we* produced: metadata, comments and
anything else that might have travelled inside are left at the door. It is also
capped at 1400 px and written as WebP.

The filename is random — a user-chosen name is a user-chosen path. The extension
comes from the re-encoded format, not from what was claimed. And
`shop/media/.htaccess` disables script execution, so a perfectly valid image
carrying PHP is downloaded, never interpreted. `image_url` accepts only our own
`media/<32 hex>.webp|jpg` or an `https://` URL — `http://` would be blocked by
the browser as mixed content.

Replacing a photo deletes the old file. `media/` is not versioned and `rsync`
runs without `--delete`, so deploys never carry the photos away.

## VIES — is that VAT number real?

Format was never proof. `PL5252248481` *looks* like a VAT number; only VIES (the
Commission's VAT Information Exchange System) knows whether it exists and whose
it is. `vies.php` asks.

**The rule that matters.** VIES queries each national tax administration live,
and those administrations go down — routinely. So two things that nothing forces
us to conflate are kept apart:

| VIES says | We record | Consequence |
| --- | --- | --- |
| `INVALID`, `INVALID_INPUT` | `invalid` | the entry is **refused** (`422` on `vat_eu`) |
| `MS_UNAVAILABLE`, `TIMEOUT`, `*_BLOCKED`, `*_MAX_CONCURRENT_REQ`, network error | `unavailable` | **nothing is blocked** — saved, flagged for re-check |
| `isValid` | `valid` | company name, address and consultation number stored |

Refusing a sale because a Commission service is down would be a bigger loss than
the one being prevented. `tests/e2e_vies.php` asserts precisely this asymmetry.

**Proof.** With our own VAT number set (`WSM_VIES_REQUESTER`), VIES returns a
consultation number — that is what stands up to a tax audit, not a screenshot.
Every consultation is appended to `wsm_vies_checks`, including the ones that
answered nothing; the client and the order carry `vat_status`, `vat_checked_at`,
`vat_name`, `vat_consultation`.

**Cost control.** `valid` and `invalid` verdicts are cached for 30 days —
VIES is slow and must not be hammered. `unavailable` is **never** cached: caching
an outage would freeze it. Country prefixes are checked against the real list of
27 member states (Greece is `EL`, not `GR`) plus `XI`, so a non-EU prefix is
refused without a pointless round-trip.

**Reverse charge is applied**, from `wsm_countries` and the VIES verdict:

| Delivery country | EU VAT number | Rate |
| --- | --- | --- |
| Poland — home market | irrelevant | Polish VAT |
| Another member state | VIES says **valid** | **0 %, reverse charge** |
| Another member state | missing, invalid, or VIES silent | Polish VAT |

An outage never exempts: we do not zero-rate on an answer we did not get. The
buyer then pays the **net** price, not the gross one, and the VAT breakdown is
empty.

Countries and carrier coverage are managed in the console
(`/mrszoko/backoffice/kraje.php`). Only Poland is open out of the box, and a
country open for sale but served by no carrier refuses the order rather than
promising a delivery nobody makes.

**OSS threshold.** A private buyer in another member state pays Polish VAT.
That is correct only below the €10,000 EU distance-selling threshold; above it,
the destination country's rate is due under OSS. The console says so on the
same screen — the day that threshold nears, this is where to come back.
Zero-rating also requires proof the goods left Poland: the VAT number alone is
not enough.

Checked at both entry points: `POST /franchisor/client` (console) and the shop
checkout. `POST /franchisor/vies` runs a check on demand without saving anything.

## Configuration

All in `config.php`, entirely env-driven (see the header there).

Payment and shipping credentials have **no defaults** and never belong in this
repository (it is public). Set them in `config.local.php` on the server, or as
`WSM_TPAY_CLIENT_ID`, `WSM_TPAY_CLIENT_SECRET`, `WSM_TPAY_SECURITY_CODE`,
`WSM_INPOST_TOKEN`, `WSM_INPOST_ORG_ID`, `WSM_INPOST_GEOWIDGET_TOKEN`.

VIES needs no credentials (it is a public service), but set
`WSM_VIES_REQUESTER` to our own VAT number so consultations come back with a
provable reference. `WSM_VIES_ENABLED=0` turns the network call off entirely —
numbers are then checked for shape only.
Both integrations default to **sandbox**; set `WSM_TPAY_SANDBOX=0` /
`WSM_INPOST_SANDBOX=0` to go live.

Editorial content is additive on deploy: `php migrate.php --sync-content` adds
strings shipped since the last release **without** overwriting anything edited
from the console.

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
| **Sklep — boutique (public)** | | |
| GET | `/shop/catalog?lang=pl\|uk\|en` | `wsm_products` · `wsm_shop_i18n` · `wsm_shipping_methods` |
| GET | `/shop/product/{slug}` | one product, texts resolved server-side |
| POST | `/shop/quote` | prices the basket — **ids and quantities only**, prices ignored |
| POST | `/shop/order` | creates the order, decrements stock, opens the tpay transaction |
| GET | `/shop/order/{code}?t=…` | order status; the token is compared with `hash_equals` |
| POST | `/shop/tpay/notify` | signed payment notification, idempotent (replies `TRUE`) |
| **Boutique côté console (protégé)** | | |
| GET | `/franchisor/orders` | order list |
| GET | `/franchisor/orders/{id}` | one order + events + the ShipX payload it would send |
| GET | `/franchisor/shop-kpis` | orders, paid, pending, revenue, average basket |
| GET | `/franchisor/shop-config` | integration state only — never a secret |
| POST | `/franchisor/orders/{id}/status` | status transition (admin) |
| POST | `/franchisor/orders/{id}/ship` | create the InPost shipment (admin, paid orders only) |
| POST | `/franchisor/product-photo` | multipart upload — decoded and **re-encoded** server-side |
| POST | `/franchisor/vies` | check an EU VAT number against VIES (no write) |
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
