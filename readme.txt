=== Amendor ===
Contributors: thebrandplaceltd
Tags: elementor, search, replace, find, backup
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.1
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
* Change history log with filtering
* Persistent debug log with level filtering
* Content-source and widget-type filters
* Recent search history (20 entries) with one-click re-run
* Fully translation-ready (`languages/amendor.pot`)

An extended Amendor Pro plugin adds field-key targeting, SEO meta sources (Yoast / Rank Math), saved presets, an in-editor Elementor tool, and CSV/JSON/TXT log exports. It is available separately.

== Installation ==

1. Upload the `amendor` folder to the WordPress plugins directory.
2. Activate the plugin in WordPress.

== Frequently Asked Questions ==

= Where are backups stored? =

Backups are stored in a dedicated database table (`{prefix}amendor_backups`) and can be restored per post from the results list, or all at once with the "Undo Last Replace" button.

= Does it work with Elementor Pro and third-party add-ons? =

Yes. Amendor scans the raw Elementor data, so any widget from Elementor Free, Elementor Pro, or third-party add-ons is supported. Use the widget-type filter to target specific widgets.

= Can I replace text right inside the Elementor editor? =

Yes — this is included in Amendor Pro. The in-editor tool adds a panel (🔍 button or Alt+Shift+F) to search, highlight, and replace text in place, with Elementor-native undo (Cmd/Ctrl+Z). The free version replaces text from the Amendor admin page, with preview and one-click undo.

= Can I delete all plugin data on uninstall? =

Yes. Enable the "Delete plugin data on uninstall" option on the Debug Log settings page, then deactivate and delete the plugin. This removes the history, debug log, and backups tables, plus backup meta and per-user search history.

== Changelog ==

= 1.0.1 =

* Compliance: refactored Freemius gating so the generated Free build strips all Pro features cleanly — no locked functionality ships in the wp.org version
* Compliance: moved inline styles into the enqueued stylesheet and removed inline JS handlers
* Compliance: removed the unnecessary `load_plugin_textdomain()` call (WordPress auto-loads translations for wp.org plugins)
* Compliance: corrected the readme Contributors list and aligned the feature list with the Free build
* Feature: regex matching and bulk replace are now included in the free version

= 1.0.0 =

* Initial public release, split into Free and Pro editions (via Freemius)
* Search & replace in Elementor data and native post fields, with preview (dry run), automatic backups, one-click undo, and per-post restore
* Free: partial/exact matching, content-source & widget-type filters, dashboard stats, search history, history/debug log viewing
* Pro: regex matching, bulk replace, field-key targeting, SEO meta fields (Yoast / Rank Math), saved presets, in-editor live tool (Alt+Shift+F5), and CSV/JSON/TXT log exports
* Performance: keyset-cursor search scan, lightweight search cache, batched AJAX scanning, daily log pruning
* Security: central capability helper, term-length limits, bounded PCRE resource limits, debug-log redaction

== Notes ==

Amendor is fully separated from Fluentor. Both plugins can be installed and activated on the same WordPress installation.

