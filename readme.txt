=== Unstoppable Activity Tracker ===
Contributors: paramedic192
Donate link: https://unstoppablesolutions.app/donate
Tags: activity log, audit log, security, monitoring, rest api
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 2.3.1
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Standalone activity log with Insights charts, downtime forensics, CSV/JSON export, email digests, optional WooCommerce tracking, and REST API.

== Description ==

Unstoppable Activity Tracker is a fully standalone activity tracker. It does **not** depend on any other plugin — it hooks WordPress directly and writes to its own database table.

**New in 2.3.0 — Downtime Forensics:** when a site goes offline and comes back up, Activity Tracker makes it dramatically easier to find what caused it. Risky events (plugin updates, core updates, theme switches, critical option changes) are now visually highlighted in the log, each annotated with a snapshot of the plugin/theme/version state at that moment. A one-click "Site-stability events" filter and a new `?before=` REST parameter let you drill into the minutes before a known outage.

**Previously added:** CSV/JSON export (2.2.0), opt-in email digests (2.2.0), optional WooCommerce tracking (2.2.0), visual Insights dashboard (2.1.0).

Use it as an audit log for a single site, or expose the REST endpoint to a dashboard, monitoring tool, or client portal of your choice.

**What it tracks**

* Authentication: successful logins, failed login attempts, logouts.
* Posts and pages (and any other public post type): created, updated, published, unpublished, trashed, restored, permanently deleted.
* Users: registered, deleted, profile updated, role changed.
* Plugins: activated, deactivated, installed, updated, deleted.
* Themes: switched, installed, updated.
* WordPress core: updated.
* Site settings: site title, tagline, admin email, site URL, home URL, registration, default role, permalink structure, language.
* **WooCommerce (optional, if WC is active):** orders (created, status changed, refunded, deleted), products (created, updated, deleted), coupons (created, used).

Every event is stored with: timestamp, numeric event code, severity (info / notice / warning / error / critical), user, role, IP, user agent, message, and event-specific metadata (JSON).

**What it does NOT do**

* It does not depend on any other plugin.
* It does not send any data anywhere. All data stays on your site. Outbound requests only happen when *you* configure something else (a dashboard, portal, monitoring tool) to *read* the REST endpoint with the per-site API key.
* It does not modify any third-party plugin's data.

**REST endpoints**

All endpoints live under `/wp-json/unstoppable/v1/` and require an API key in the `X-Unstoppable-Key` header. The key is **auto-generated on activation and shown on the plugin's settings page** — no signup, no license, no external service. You can regenerate it any time. The requirement is authentication only (so the endpoint doesn't expose activity data to the public web), not a paywall.

* `GET /ping` — health check
* `GET /events` — paginated events feed, with `limit`, `since`, `object`, `event_type`, `severity` filters
* `GET /stats` — aggregate counts (total / last 24h / last 7d / by object)

**Retention**

A daily cron job deletes events older than the retention window (default 90 days). Set the retention to 0 to keep events forever. Deletes run in bounded chunks (up to 50,000 rows per invocation) and are coordinated with an atomic lock, so a large one-time backlog can't stall a single cron run or cause overlapping workers to race.

== Installation ==

1. Upload the plugin via Plugins → Add New → Upload Plugin, or extract the zip into `/wp-content/plugins/`.
2. Activate "Unstoppable Activity Tracker" through the Plugins menu in WordPress.
3. Open the **Activity Tracker** menu in the admin sidebar and copy the endpoint URL and API key into whatever dashboard or tool you want to read events from.

== Frequently Asked Questions ==

= Do I need WP Activity Log or any other plugin? =

No. This plugin is standalone. It hooks WordPress directly and stores events in its own database table.

= Where is the data stored? =

In a custom table named `{your-wp-prefix}uatr_events`. The plugin writes to it; nothing else does.

= Does the plugin contact any external services? =

No. The plugin makes zero outbound HTTP requests. Data stays on your site unless something else pulls it via the authenticated REST endpoint.

= How do I rotate the API key? =

On the settings page, click "Regenerate API Key". The old key stops working immediately — update it in any consumer that uses it.

= Do I need to pay for or activate the API key? =

No. The key is created locally on your site when you activate the plugin and stored in your own database. There is no signup, no license activation, no external validation server. The key exists purely so the REST endpoint isn't wide open to the public web — it's authentication, not a paywall.

= Can I temporarily disable the REST endpoint without rotating the key? =

Yes. Uncheck "Enabled" under Settings on the settings page. Event tracking continues; only the REST endpoint stops responding.

= How does incremental sync work? =

Pass a Unix timestamp as the `since` query parameter to the events endpoint. Only events newer than that timestamp are returned, capped at the `limit` value (default 50, max 200).

= What happens to my data when I deactivate the plugin? =

Nothing — the table and your settings are kept. Deactivating just stops new events from being recorded and clears the daily cleanup cron. Uninstalling drops the table and removes all options.

= Can I keep events forever? =

Yes. Set the retention setting to 0.

== Screenshots ==

1. Insights page — activity trends, category breakdown, top users, and security highlights, with a time range switcher.
2. The settings page with the connection details, retention controls, and email digest settings.
3. The Activity Log viewer with filters and CSV/JSON export buttons.

== Changelog ==

= 2.3.1 =
* **Bounded retention cleanup.** The daily retention cron now deletes in chunks (1,000 rows per DELETE, up to 50,000 rows per invocation) and reschedules a follow-up run 5 minutes later when a large backlog remains. Replaces a single unbounded `DELETE` that could time out on sites switching from long-retention to short-retention windows.
* **Concurrency-safe retention.** Concurrent cron invocations now coordinate through a truly atomic lock: a direct `INSERT IGNORE` against `wp_options` (atomic at the MySQL layer via the UNIQUE index on `option_name`). A losing worker skips the run cleanly rather than racing. The lock stores an owner UUID so a late-completing worker can't clobber a newer worker's claim. Stale locks older than 10 minutes are auto-recovered.
* Significant design input from PeepSo across three rounds of review on the Reddit launch thread: the backlog-drain failure mode, the cap-and-reschedule bounded-per-run approach, and the atomic-claim locking primitive with owner-checked release. Thank you.

= 2.3.0 =
* **New: Downtime Forensics.** When a site goes offline and comes back up, the Activity Log now makes it dramatically easier to find what caused it:
  * **Elevated severities on breakage-prone events.** Plugin activations and plugin/core/theme installs and updates now log at `notice` or `warning` severity so they stand out in the log. Theme switches and WordPress core updates log at `warning`. Changes to the `siteurl` or `home` option log at `critical` (these are the near-universal cause of admin lockout). Other watched-option changes log at `warning`.
  * **"Last known good" state snapshots.** On every plugin/theme/core change and every critical option change, UATR now stores a snapshot of the current state (active plugins list, WP version, active theme, PHP version) in the event's meta. After an outage you can see the exact state immediately before each risky change — enough context to know what to roll back.
  * **One-click "Site-stability events" filter** on the Activity Log page. Shows only plugin, theme, and core changes — the usual suspects when a site goes down.
  * **New REST parameter: `?before=TIMESTAMP`.** Pair with the existing `?since=` to pull a precise event window — e.g. "events in the 15 minutes before my uptime monitor reported downtime at 2:47 PM."
* **Settings page UX cleanup.** The Endpoint URL, API Key, and REST reference tables are now tucked inside a collapsible "External integration (optional)" block, closed by default. Non-technical users opening the Settings page see the simple options (enabled toggle, retention, digest settings) without being confronted with connection details they don't need. A reassuring intro at the top says "This plugin works out of the box — everything below is optional."
* "Regenerate API Key" button renamed to "Generate a new key" with a gentler helper note; its purpose is the same.

= 2.2.0 =
* **New: CSV / JSON export.** Download the filtered Activity Log as a CSV or JSON file (hard cap: 50,000 rows per export).
* **New: Email digest.** Opt-in daily or weekly email summary of events at or above a chosen severity threshold (warning / error / critical). Nothing is sent unless you enable it. If no matching events occurred in the window, no email is sent.
* **New: Optional WooCommerce event tracking.** When WooCommerce is active, the plugin also records orders (created, status changed, refunded, deleted), products (created, updated, deleted), and coupons (created, used). Event codes in the 7000 range. No effect on non-WC sites.
* Activity Log filter extended to cover the new WooCommerce object types.

= 2.1.0 =
* **New: Insights page.** Visual summaries of activity with four cards: events over time (bar chart), events by category (donut), most active users (top 5), and security highlights (failed logins, new users, role changes, plugin/core activity).
* Time range switcher: 24 hours, 7 days, 30 days, or 90 days.
* Insights is now the default landing page when opening Activity Tracker from the sidebar menu.
* Menu restructure: **Insights** (default), Activity Log, Settings. The Settings page URL changed from `?page=unstoppable-activity-tracker` to `?page=unstoppable-activity-tracker-settings`. Old bookmarks now land on Insights.
* Charts render locally with a bundled copy of Chart.js — no external CDN calls.

= 2.0.3 =
* Restricted all admin notices to the plugin's own pages. Removed the activation welcome notice and removed the announcement notice infrastructure. Flash notices (shown briefly after actions like API key regeneration) now include a defensive screen scope check.

= 2.0.2 =
* Collapsed four multi-line database queries to single-line form so `phpcs:ignore` comments correctly cover the interpolated identifier. No behavior change; static-analysis polish only.

= 2.0.1 =
* Static-analysis polish for cleaner Plugin Check output. No user-facing changes.
* Refactored IP and user-agent capture in the logger to sanitize inline (single expression) so static analyzers can trace the sanitization path.
* Expanded phpcs:ignore comments on all custom-table queries with clear explanations of why interpolated identifiers are safe.
* Prefixed local variables in `uninstall.php` (`$table` → `$uatr_table`, `$options` → `$uatr_options`, `$opt` → `$uatr_opt`).

= 2.0.0 =
* **Breaking change:** removed the built-in Base44 update mechanism. The plugin now relies on WordPress.org for updates.
* If you were running the previous build with the Base44 updater: update via WordPress.org from this version forward. No data loss — the events table and all settings are preserved across the upgrade.
* No outbound HTTP requests anywhere in the plugin. The plugin is now fully passive: it records events locally and waits for authenticated reads.
* Replaced the `admin_head`-injected menu branding CSS with the canonical `wp_add_inline_style()` pattern for WordPress.org compliance.
* Hardened all `$_POST` / `$_GET` access to use `wp_unslash()` before sanitizing.
* Tested with WordPress 7.0.

= 1.0.0 =
* Initial release.
* Standalone event tracking — no dependency on any other plugin.
* Custom database table with indexes for fast filtered reads.
* Hooks ~20 WordPress actions covering auth, content, users, plugins, themes, core, and key settings.
* REST endpoints: `/ping`, `/events` (with filtering), `/stats`.
* Per-site auto-generated API key with constant-time comparison.
* Top-level admin menu with brand accent.
* Activity Log viewer with filters and pagination.
* Daily retention cleanup cron (default 90 days, configurable; 0 = forever).
* Welcome notice, flash notices, and announcement system.
* Full uninstall cleanup (drops table, deletes options).

== Upgrade Notice ==

= 2.3.1 =
Retention cleanup is now bounded (chunked DELETEs, capped per cron run, atomic lock across concurrent workers). Prevents long cleanup stalls on sites with large event backlogs. No settings changes; all existing data preserved.

= 2.3.0 =
Adds Downtime Forensics: elevated severities on risky events, site-state snapshots on changes, a one-click stability filter, and a new `?before=` REST parameter. Settings page tidied to hide API/endpoint details behind a collapsible block. All existing data preserved.

= 2.2.0 =
Adds CSV/JSON export, opt-in email digests, and optional WooCommerce event tracking. All existing data preserved; no settings changes required.

= 2.1.0 =
New Insights page with charts and security highlights. Menu restructure — Settings URL changed; old bookmarks now land on Insights. All data preserved.

= 2.0.3 =
Admin notice compliance polish. No user-facing behavior change; safe drop-in upgrade.

= 2.0.2 =
Static-analysis polish. No user-facing changes; safe drop-in upgrade.

= 2.0.1 =
Static-analysis polish. No user-facing changes; safe drop-in upgrade.

= 2.0.0 =
Major release. The Base44 update mechanism has been removed — updates now come through WordPress.org. Your events table and settings are preserved.

= 1.0.0 =
Initial release.
