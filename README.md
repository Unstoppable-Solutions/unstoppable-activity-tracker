# Unstoppable Activity Tracker

[![WordPress Plugin Version](https://img.shields.io/wordpress/plugin/v/unstoppable-activity-tracker.svg)](https://wordpress.org/plugins/unstoppable-activity-tracker/)
[![WordPress Plugin Downloads](https://img.shields.io/wordpress/plugin/dt/unstoppable-activity-tracker.svg)](https://wordpress.org/plugins/unstoppable-activity-tracker/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2+-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

Standalone activity tracker for WordPress. Visual Insights dashboard, filterable Activity Log, CSV / JSON export, optional email digests, optional WooCommerce event tracking, and a secure REST API for external sync.

> **End users:** install from the WordPress Plugin Directory — [wordpress.org/plugins/unstoppable-activity-tracker](https://wordpress.org/plugins/unstoppable-activity-tracker/)
>
> **This repository is for development.** Browse the code, follow releases, file issues, submit pull requests.

## Features

- **Visual Insights dashboard** — at-a-glance charts of what's happening across your site
- **Filterable Activity Log** — drill into every event by date, user, type
- **CSV + JSON export** — pull the full log into your tools
- **Email digests** — optional daily / weekly summaries
- **WooCommerce event tracking** — order activity, cart events, customer actions (optional)
- **REST API** — secure endpoints for external sync and reporting pipelines
- **Zero outbound HTTP** — all data stays on your own site

## Who it's for

Agencies that need to audit client-site activity, store owners who want a readable record of what their team and customers are doing, and anyone running compliance-adjacent WordPress deployments.

## Development

### Repository layout

```
.
├── assets/           Frontend + admin CSS and JS (including chart.min.js for Insights)
├── includes/         Core PHP classes (UATR_* prefix)
├── languages/        Translation .pot location
├── readme.txt        WordPress.org readme (standard WP format)
└── unstoppable-activity-tracker.php   Main plugin bootstrap
```

### Release workflow

Releases are deployed to WordPress.org automatically by GitHub Actions:

1. Finish the feature work on `main`
2. Bump the version in **all four** required locations:
   - `unstoppable-activity-tracker.php` — `Version:` header
   - `unstoppable-activity-tracker.php` — `UATR_VERSION` constant
   - `readme.txt` — `Stable tag:`
   - `readme.txt` — add a `== Changelog ==` entry
3. Commit and push `main`
4. On GitHub, create a new **Release** tagged `vX.Y.Z` (e.g. `v2.3.0`)
5. The `deploy-to-wporg.yml` workflow fires and pushes the release to WordPress.org SVN automatically

### CI

Every push and pull request runs the official WordPress Plugin Check against the codebase.

## License

GPL-2.0-or-later. See the `License` header in `unstoppable-activity-tracker.php` for the full text.

## Author

Developed and maintained by [Unstoppable Solutions](https://unstoppablesolutions.app) — Dave Stevens / `paramedic192`.
