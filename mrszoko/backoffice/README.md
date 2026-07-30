# back_office_ws_franchisor

**Console marque · Siège** (Konsola marki · Centrala) — the franchisor
(head-office) back-office for the
**L'Atelier By** webshop. This is the reworked Claude Design export
(`back_office_ws_franchisor.dc.html`) run **natively**, with its own
**L'Atelier design system** (`_ds/…/global.css`, Gotham + Vank fonts) — no
WebShop admin CSS is mixed in.

## Running

The app fetches its data and dynamically imports modules at startup, so serve
the folder over HTTP (not `file://`):

```bash
python3 -m http.server 8080
# then visit http://localhost:8080/back_office_ws_franchisor.dc.html
```

No build step.

## Access

The console is behind a login (e-mail + password). `auth-gate.js` checks
`api/auth/me` before the app boots and shows the Polish login screen when no
identity is established; a "Wyloguj" button signs out. Accounts, roles and
password rules live in `php-api/` — see its README. The first administrator is
created by the deploy (credentials left in `/root/mrszoko-admin.txt` on the
server, readable by root only).

**Language: Polish.** All screen labels live in the page and are in Polish; all
demo/business data comes from the `wsm_` tables (or the JS seed fallback) and is
seeded in Polish. Stored workflow codes (delivery statuses `planifiée/assignée/
en_cours/livrée/échouée`, event names) are internal enums — kept stable and
mapped to Polish labels at display time.

## Architecture — data-driven, self-contained

- **`back_office_ws_franchisor.dc.html`** — the page: template (Claude Design
  `<x-dc>` markup with `sc-for` / `sc-if` / `{{ }}`) + the component logic
  (`class Component extends DCLogic`). Zero domain data.
- **`support.js`** — the Claude Design "DC" runtime. It loads React, evaluates
  the component, and renders. A tiny inline `window.__resources` map in the
  page points its React URLs at the locally-vendored copies.
- **`bo_server.js`** — server-simulation data layer. **Every domain table**
  (KPIs, boutiques, catalogue, vouchers, pricing rules, params, email
  templates, users, audit…) lives here as the seed, is persisted to
  `localStorage`, and is read by the page via `window.BOServer.table(name)`.
  No data is hardcoded in the UI.
- **`menu_api.js` + `menu_seed.js`** — the Menu Builder's server simulation:
  the bundle → slot → choice tree, category `menu_default` / product
  `menu_override` resolution, and server-authoritative price/cost/margin. The
  page imports it dynamically and calls it like an API.
- **`_ds/l-atelier-by-…/`** — the L'Atelier design system: `global.css`
  (tokens + components), the (empty) `_ds_bundle.js`, and the brand fonts
  under `fonts/` (Gotham UI + Vank display).
- **`vendor/react.js`, `vendor/react-dom.js`** — React 18.3.1 (UMD), vendored
  so the app runs without any CDN.

## Screens (franchisor scope)

| Group | Screen | Backing tables (labelled in-UI) |
| --- | --- | --- |
| **Pilotage** | Tableau de bord réseau (KPIs + boutiques) | kpis · shops |
| | Boutiques (CRUD) | ws_shops ← franchise_shops |
| | Catalogue (arbre catégories › produits) | ws_products · product_categories · ws_season |
| | Menus & formules (menu builder) | ws_bundles · ws_bundle_slots · ws_bundle_slot_choices |
| | Promotions réseau | ws_vouchers · ws_pricing_rules |
| **Paramétrage** | Communications | ws_email_templates |
| | Utilisateurs & rôles | bo_users · bo_user_shops (RBAC) |
| | Journal d'audit | bo_audit |

| **Livraisons** | Livraisons bureau (module livraison) | wsm_deliveries · wsm_clients · wsm_client_points · wsm_drivers · wsm_rounds · wsm_delivery_events · wsm_incidents |

Interactions: sidebar nav, live toggles, whitelist/gouvernance switches,
create/edit modal forms, a full menu builder (bundles, steps, choices,
server-resolved pricing & margin), and a **delivery module** — create a test
delivery, assign a driver/round, confirm by QR/PIN, with a full status +
event trail.

## Database — `webshop_mrszoko` (tables `wsm_`)

Every table is now backed by a real database, **`webshop_mrszoko`**, whose tables
are all prefixed **`wsm_`**, served by **`php-api/`**. Nothing is hardcoded in the
UI: each screen reads its `wsm_` table(s) through the `/franchisor/*` API and
falls back to an in-memory seed only when no API/token is present (dev/GitHub
Pages). See **`php-api/README.md`** to run it (SQLite locally, MySQL in prod) and
**`MIGRATION_NOTES.md`** for the endpoint/table map. The end-to-end delivery flow
is verified by `php-api/tests/e2e_delivery.php` (23 assertions, all green).

## Brand — Mister Szoko design system

The Mister Szoko design system (full Claude Design handoff: tokens, components,
prototypes) lives at `../design-system/`. See `../README.md`.

## Deployment

GitHub Actions (`.github/workflows/deploy.yml`) deploys over SSH/rsync on every
push to `main` — same mechanism and secrets as the WebShop — to the path served
at `/mrszoko/backoffice`. The default server dir is
`/var/www/html/mrszoko` (this app under `backoffice/`); the PHP API ships alongside as `./api` (so `/mrszoko/backoffice/api`).
The workflow verifies the served page and that the runtime, data modules, vendored
React, design-system CSS and fonts all return `200`.

## Files

- `back_office_ws_franchisor.dc.html` — page (template + component logic)
- `index.html` — copy of the above so the directory URL serves the app
- `support.js` — Claude Design DC runtime
- `bo_server.js` — all domain data (seed → localStorage)
- `menu_api.js`, `menu_seed.js` — Menu Builder data + server simulation
- `_ds/l-atelier-by-…/` — L'Atelier design system (global.css, fonts, bundle)
- `vendor/` — React 18.3.1 (UMD), vendored
- `img/logo.png` — L'Atelier By wordmark
- `php-api/` — `webshop_mrszoko` backend: PHP router, `wsm_` schema (MySQL +
  SQLite mirror), seed, migrate CLI, delivery module, end-to-end test
