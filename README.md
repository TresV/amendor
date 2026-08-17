# Amendor

Standalone package for the Elementor search-and-replace tool.

## Features

- **Search & replace** across Elementor data plus native post fields (title, content, excerpt)
- **Three search modes**: partial (case-insensitive), exact (case-sensitive), and PCRE regex
- **Bulk replace** with multiple sequential search/replace pairs
- **Batched AJAX scanning** with live progress, a **cancel button**, and results caching (scales to large sites)
- **Preview (dry run)** before committing changes, with a collapsible **visual JSON diff** for Elementor data
- **Automatic backups** before every replacement, with **one-click Undo** and per-post restore (dedicated table)
- **Dashboard quick stats** and **occurrence counts** by content source and widget type
- **Change history log** with filtering and CSV/JSON/TXT export
- **Persistent debug log** with level filtering and CSV/JSON/TXT export
- **Content-source and widget-type filters**, plus **field-key targeting**
- **SEO meta support** — scan and replace Yoast / Rank Math SEO titles and meta descriptions as first-class content sources
- **URL / domain swap preset**
- **Saved search/replace presets** — name, save, apply, and export/import as JSON for reuse across client sites
- **Data-size guardrail** (10 MB per page) to safely skip oversized Elementor documents
- **Recent search history** (20 entries) with one-click re-run
- **Dismissible onboarding banner**
- **Elementor editor tool (experimental)** — search, highlight and replace right inside the Elementor editor (see below)
- Fully **translation-ready** (`languages/amendor.pot`)

### Elementor editor tool (experimental)

Search, see and replace text directly in the Elementor editor — no need to leave the page:

- Floating **FAB + panel**, opened with the 🔍 button or **`Alt+Shift+F`** (works even while focus is inside the preview canvas)
- **Word-level highlighting** in the live preview (yellow marks) plus a subtle outline on each matching element
- **Replace Selected (N)**: per-occurrence checkboxes (widget + field + snippet) so you choose exactly what changes
- **Field filter** with safe defaults (text & content only; URLs, shortcodes, code and internal fields are opt-in)
- **In-place replace** via Elementor's own settings command — the canvas updates instantly, no page reload, scroll position preserved
- **Undo** in the panel, plus native **`Cmd/Ctrl+Z`** support through Elementor's history system (each replace is a recorded history step)
- **"Open in Amendor"** deep link to the full admin tool for batch work, backups and the audit trail
- Gated by the `amendor_enable_elementor_editor_integration` filter (default on)

## Contents

- `amendor.php`: plugin bootstrap (loads the module files below)
- `includes/` — self-contained PHP runtime, split into focused modules:
  - `plugin-context.php` / `activation.php` — bootstrap context helpers and table setup
  - `search-data.php` — data helpers: content sources, retention limits, search history, stats
  - `backups.php` — backup storage and restore (dedicated table)
  - `search-engine.php` — search/replace analysis engine and logging
  - `search-cache.php` — batched search backend (candidate scan, transient cache, pagination payloads)
  - `presets.php` — saved search/replace presets (save, apply, export/import as JSON)
  - `action-handlers.php` — admin form action handlers (search, preview, replace, restore, undo)
  - `render-results.php` — admin notices and search/preview result markup (shared with AJAX)
  - `admin-chrome.php` — admin menu, settings, assets, and notices
  - `admin-main-page.php` — the main search & replace UI page
  - `log-debug-page.php` — debug log page and export
  - `log-history-page.php` — change history log page and export
  - `elementor-editor.php` — Elementor editor integration (experimental search/highlight/replace tool)
  - `ajax-handlers.php` — AJAX endpoints (batched search, results, preview, backup check)
- `assets/`: admin CSS and JavaScript
- `languages/`: translation template (`amendor.pot`)
- Uninstall cleanup runs via the Freemius `after_uninstall` hook (`fs_after_uninstall_amendor`, defined in `amendor.php`)
- `composer.json` / `phpcs.xml.dist`: development tooling (WordPress Coding Standards)

## Requirements

- WordPress 6.4+
- PHP 8.1+

## Activation

Amendor is fully separated from the original combined package. It can be installed and activated alongside `Fluentor` on the same WordPress installation.

## Developer: hooks & filters

The plugin exposes the following filters (all prefixed `amendor_`):

| Filter | Purpose |
|---|---|
| `amendor_supported_post_types` | Restrict which post types are scanned |
| `amendor_search_batch_size` | Posts processed per AJAX scan batch |
| `amendor_backup_retention_limit` | Max backups kept per post |
| `amendor_search_posts_per_page` / `amendor_search_posts_per_page_options` | Default / allowed results-per-page values |
| `amendor_default_debug_log_retention` / `amendor_default_history_log_retention` | Default retention row counts for the log tables |
| `amendor_debug_log_items_per_page` / `amendor_history_items_per_page` | Admin log page pagination |
| `amendor_debug_log_export_max_rows` / `amendor_history_log_export_max_rows` | Max rows included in a log export |

A daily cron event, `amendor_daily_log_prune`, prunes the history and debug log tables to their configured retention limits.

## Development

Validate the code against the WordPress Coding Standards:

```bash
composer install          # installs WPCS + PHPCompatibility
composer run lint         # PHP syntax check on all plugin files
composer run cs           # phpcs --standard=phpcs.xml.dist
composer run cs-fix       # phpcbf --standard=phpcs.xml.dist
```

Regenerate the translation template (on a WordPress install with WP-CLI):

```bash
wp i18n make-pot . languages/amendor.pot
```
