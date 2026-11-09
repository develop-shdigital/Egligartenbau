# Egli Gartenbau

Custom code for [egligartenbau.com](https://egligartenbau.com/) (WordPress, Elementor Pro, Hello Elementor theme).

## Contents

- `wp-content/plugins/shd-instagram-feed/` – **SHD Instagram Feed**: an Elementor widget that shows the latest Instagram posts in a fast, accessible slider and updates itself automatically. Sites connect with one "Login to Instagram" button. Setup and details: [plugin README](wp-content/plugins/shd-instagram-feed/README.md).
- `connect-service/` – the small PHP service behind the "Login to Instagram" button (holds SH Digital's Meta app secret). Set up once for all client sites: [connect service README](connect-service/README.md).
- `scripts/build-plugin.sh` – builds installable zips of the plugin and the connect service into `dist/`.
- `.claude/skills/` and `skills-lock.json` – agent skills used for development (installed with `npx skills`, update with `npx skills update`).

## Build the plugin zip

```bash
scripts/build-plugin.sh
```

Needs Node.js (for minifying the slider script) and `zip`. With WP-CLI on the path it also recompiles the translations.
