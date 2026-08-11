# Amendor

Standalone package for the Elementor search-and-replace tool.

## Contents

- `amendor.php`: plugin bootstrap
- `includes/`: self-contained PHP runtime used by this package
- `assets/`: admin CSS and JavaScript
- `languages/`: translation template (`amendor.pot`)
- `uninstall.php`: Amendor-only cleanup
- `composer.json` / `phpcs.xml.dist`: development tooling (WordPress Coding Standards)

## Requirements

- WordPress 6.4+
- PHP 8.1+

## Activation

This package is designed to ship only the Amendor search-and-replace feature surface. It can be installed independently from `Fluentor`.

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
