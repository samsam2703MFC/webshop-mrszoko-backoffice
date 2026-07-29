# mrszoko/ — Mister Szoko

Everything served under `http://185.180.206.46/mrszoko`.

- **`landing/`** — the public Mister Szoko landing page. Static, self-contained:
  `index.html` + the design-system tokens (`tokens.css` → `tokens/*.css`) + the
  brand logo (`assets/logo.png`). Served at **`/mrszoko/landing`** (and `/mrszoko/`
  redirects to it). Fonts load from Google Fonts per `tokens/fonts.css`
  (substitutes — see the design-system fonts caveat).

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
