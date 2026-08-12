# Amendor

Standalone package for the Elementor search-and-replace tool.

## Contents

- `amendor.php`: plugin bootstrap (loads the module files below)
- `includes/` — self-contained PHP runtime, split into focused modules:
  - `plugin-context.php` / `i18n.php` / `activation.php` — bootstrap, localization, and table setup
  - `search-data.php` — data helpers: content sources, backups, retention limits, search history
  - `search-engine.php` — search/replace analysis engine and logging
  - `search-cache.php` — batched search backend (candidate scan, transient cache, pagination payloads)
  - `action-handlers.php` — admin form action handlers (search, preview, replace, restore)
  - `render-results.php` — admin notices and search/preview result markup (shared with AJAX)
  - `admin-chrome.php` — admin pages, settings, assets, and the main UI
  - `log-pages.php` — debug log and history log pages plus export helpers
  - `ajax-handlers.php` — AJAX endpoints (batched search, results, preview, backup check)
- `assets/`: admin CSS and JavaScript
- `languages/`: translation template (`amendor.pot`)
- `uninstall.php`: Amendor-only cleanup
- `composer.json` / `phpcs.xml.dist`: development tooling (WordPress Coding Standards)

## Requirements

- WordPress 6.4+
- PHP 8.1+

## Activation

Amendor is fully separated from the original combined package. It can be installed and activated alongside `Fluentor` on the same WordPress installation.

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
