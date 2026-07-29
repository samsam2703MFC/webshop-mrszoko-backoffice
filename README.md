# webshop-mrszoko-backoffice

**Mister Szoko** — mono-repo of the brand's web presence, deployed standalone
under **`/mrszoko`** on the server (`http://185.180.206.46/mrszoko`).

| URL | Content | Source |
| --- | --- | --- |
| `/mrszoko/` | redirect → landing | generated at deploy |
| `/mrszoko/landing` | Mister Szoko landing page | [`mrszoko/landing/`](mrszoko/landing/) |
| `/mrszoko/backoffice` | Console marque · Siège (franchisor back-office) | [`mrszoko/backoffice/`](mrszoko/backoffice/) |
| `/mrszoko/backoffice/api` | PHP API (`webshop_mrszoko` DB, `wsm_` tables) | [`mrszoko/backoffice/php-api/`](mrszoko/backoffice/php-api/) |

- **Design system** (imported Claude Design handoff: tokens, components,
  prototypes) — [`mrszoko/design-system/`](mrszoko/design-system/)
- **Deploy** (SSH/rsync on push to `main`, with on-server verification) —
  [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml)

Start with [`mrszoko/README.md`](mrszoko/README.md).
