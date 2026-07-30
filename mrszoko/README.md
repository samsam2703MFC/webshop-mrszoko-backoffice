# mrszoko/ — Mister Szoko

Everything served under `http://185.180.206.46/mrszoko`.

- **`landing/`** — the public Mister Szoko landing page, **trilingual (pl / uk /
  en, default pl)** and **fully data-driven**: `index.html` contains zero copy;
  `app.js` renders everything from `GET /mrszoko/backoffice/api/landing/content`
  (tables `wsm_landing_i18n` + `wsm_landing_products`, editable through the
  admin API without redeploying), falling back to `content_seed.json` — the
  **same file** that seeds those tables server-side — when the API is
  unreachable (GitHub Pages, dev, outage). Language picking: `?lang=` →
  `localStorage` → browser language → `pl`; a PL/UA/EN switcher sits in the
  header. Served at **`/mrszoko/landing`** (and `/mrszoko/` redirects to it).
  Fonts load from Google Fonts per `tokens/fonts.css` (substitutes — see the
  design-system fonts caveat). End-to-end proof:
  `backoffice/php-api/tests/e2e_landing.php` (22 assertions).

- **`backoffice/`** — the Console marque · Siège (franchisor back-office) and its
  PHP API (`php-api/`, `webshop_mrszoko` DB). Served at **`/mrszoko/backoffice`**,
  API at **`/mrszoko/backoffice/api`**. See `backoffice/README.md`.

- **`design-system/`** — the Mister Szoko design system, imported from the
  Claude Design handoff (claude.ai/design): brand guide (`readme.md`), tokens,
  React component primitives, guidelines specimens, and two clickable prototypes
  (`Mister Szoko Webshop Prototype.html`, `Mister Szoko Back-office Prototype.html`)
  plus the `templates/` they build on. **Reference material** — not deployed.
  The landing copies its tokens from here; future Mister Szoko UIs (webshop,
  re-skinned back-office) should start from this folder.
