=== Amendor ===
Contributors: TheBrandPlace
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Search and replace text within Elementor data and native post fields, with automatic backups, one-click undo, history, and debug logging.

== Description ==

Amendor is the search-and-replace portion of the original combined plugin, packaged as a standalone WordPress plugin. It lets you find and replace text inside Elementor page-builder data as well as native post titles, content, and excerpts - safely, with automatic backups and a complete audit trail.

Elementor Free and Pro (tested up to 3.15) are supported, and because Amendor scans the raw Elementor data, widgets from most third-party Elementor add-ons work too.

= Key Features =

* Search Elementor content plus native post fields (title, content, excerpt)
* Partial (case-insensitive), exact (case-sensitive), and PCRE regular-expression matching
* Bulk search & replace with multiple sequential search/replace pairs
* Batched AJAX scanning with live progress (scales to large sites)
* Preview (dry run) before committing any change
* Automatic backups before every replacement, with one-click Undo and per-post restore
* Dashboard quick stats and per-source/per-widget occurrence counts
* Change history log with filtering and CSV/JSON/TXT export
* Persistent debug log with level filtering and CSV/JSON/TXT export
* Content-source and widget-type filters
* Recent search history (20 entries) with one-click re-run
* Fully translation-ready (`languages/amendor.pot`)

== Installation ==

1. Upload the `amendor` folder to the WordPress plugins directory.
2. Activate the plugin in WordPress.

== Frequently Asked Questions ==

= Where are backups stored? =

Backups are stored in a dedicated database table (`{prefix}amendor_backups`) and can be restored per post from the results list, or all at once with the "Undo Last Replace" button.

= Does it work with Elementor Pro and third-party add-ons? =

Yes. Amendor scans the raw Elementor data, so any widget from Elementor Free, Elementor Pro, or third-party add-ons is supported. Use the widget-type filter to target specific widgets.

= Can I delete all plugin data on uninstall? =

Yes. Enable the "Delete plugin data on uninstall" option on the Debug Log settings page, then deactivate and delete the plugin. This removes the history, debug log, and backups tables, plus backup meta and per-user search history.

== Changelog ==

= 1.6.0 =

* Performance: keyset-cursor search scan, lightweight search cache, scoped Elementor CSS cache clearing, cached widget-type list, daily log-table pruning, configurable batch size, backups moved to a dedicated table
* Security: central capability helper, search/replace term length limits, bounded PCRE resource limits, debug-log redaction, uninstall cleanup (transients, tables, options)
* UX: one-click Undo for the last replacement, dashboard quick stats, history log export (CSV/JSON/TXT) and filters, occurrence counts by source and widget, search history raised to 20 with one-click re-run, UTF-8 BOM on CSV exports

== Notes ==

Amendor is fully separated from Fluentor. Both plugins can be installed and activated on the same WordPress installation.

