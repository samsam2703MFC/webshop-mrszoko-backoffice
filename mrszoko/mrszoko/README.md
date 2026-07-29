# mrszoko/ — Mister Szoko design system (imported)

Imported from the **claude.ai/design** project *"Mister Szoko Design System"*
via the design MCP. This is the warm, artisanal-chocolate brand system whose
database this back-office is wired to (`webshop_mrszoko`).

## Contents

- `styles.css` — global entry point; `@import`s all token files.
- `tokens/` — `colors.css` (chocolate + cream + caramel/gold/berry/pistachio),
  `typography.css`, `spacing.css`, `radius.css` (incl. the organic
  `--radius-blob`), `shadow.css` (warm brown-tinted), `motion.css`, `fonts.css`.
- `assets/logo.svg` — the brand mark (see note below).
- `thumbnail.html` — the brand tile: the logo badge on deep chocolate with the
  four-accent side strip. Implemented from the design project's `thumbnail.html`.

Open `thumbnail.html` over HTTP (the tokens load via `@import`).

## Logo note

The design project's binary `assets/logo.png` (197×185) could not be transferred
losslessly through this channel, so `assets/logo.svg` is a faithful recreation of
the described mark — a dark-chocolate pebble/blob with a cream negative-space
profile (quiffed hair + round glasses) and the bold "MISTER SZOKO" wordmark, in
the exact brand tokens. To use the real asset, drop `logo.png` from the design
system into `assets/` and point `thumbnail.html`'s `<img src>` at it.

## Fonts note

The real brand fonts are unknown; `tokens/fonts.css` loads the closest Google
Fonts substitutes. Replace with licensed brand fonts when available.
