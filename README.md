# webshop-mrszoko-backoffice

Console marque · Siège (franchisor back-office) for **Mister Szoko**.

The application now lives entirely under [`mrszoko/`](mrszoko/) and is deployed
**standalone** (no longer under `/webshop`) to **`/mrszoko`** on the server —
`http://185.180.206.46/mrszoko` — with its PHP API served alongside at
`/mrszoko/api`.

- **App + docs** — [`mrszoko/`](mrszoko/) · start with [`mrszoko/README.md`](mrszoko/README.md)
- **Backend** (`webshop_mrszoko` DB, `wsm_` tables) — [`mrszoko/php-api/`](mrszoko/php-api/)
- **Deploy** (SSH/rsync on push to `main`) — [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml)
