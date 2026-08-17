# Amendor — Architecture & Function Index

> **Read this before adding or changing code.** Amendor is intentionally
> procedural (no namespaces, no autoloader) and the only "language boundary" is
> the `amendor_*` naming convention. This document is the structural map that
> lets a fresh session find existing functions instead of duplicating them.
>
> Guardrails: run `composer run check-structure` after any change. It verifies
> unique function names, that every `amendor_*` call resolves, that every module
> is required exactly once by the bootstrap, and that `current_user_can()` is
> only used inside `amendor_current_user_can_manage()`.

## Layout

```
amendor.php                 Bootstrap: requires every module, Freemius SDK init, after_uninstall cleanup hook
includes/
  plugin-context.php        Context helpers: mode, capability gate, table names
  activation.php            Table creation (dbDelta), cron schedule, migrations
  search-data.php           Data helpers: sources, limits, history, stats, JSON diff
  backups.php               Backup snapshots, restore, download (dedicated table)
  search-engine.php         Search/replace analysis engine + debug logging
  search-cache.php          Batched search backend: cursor scan, cache, payloads
  presets.php               Saved search/replace presets (save, apply, export/import as JSON)
  action-handlers.php       Admin form handlers: search/preview/replace/restore/undo
  render-results.php        Shared result/notice markup (admin + AJAX)
  admin-chrome.php          Menu, settings, assets, notices
  admin-main-page.php       Main search & replace UI page
  log-debug-page.php        Debug log page + export
  log-history-page.php      Change history log page + export
  ajax-handlers.php         AJAX endpoints (batched search, results, preview, backup)
  elementor-editor.php      Elementor editor tool (search/highlight/replace)
assets/                     admin.js, editor.js, admin.css
languages/                  amendor.pot
bin/check-structure.php     Structural guardrail script (composer run check-structure)
```

## Conventions

- **Naming**: every function is prefixed `amendor_` and lives in the module that
  owns its concern (see the index below). **If a function for a task already
  exists, reuse it — check this index first.**
- **Capability checks**: never call `current_user_can()` directly. Always use
  `amendor_current_user_can_manage()` (enforced by `check-structure`).
- **i18n**: every user-facing string goes through `__()`, `_e()`,
  `esc_html__()`, etc., with the `amendor` text domain. Regenerate
  `languages/amendor.pot` when strings change (see README).
- **Directories**: new PHP modules go in `includes/` and **must** be added to the
  `amendor.php` require list exactly once.
- **Escaping**: escape all output (`esc_html`, `esc_attr`, `esc_url`, `wp_kses`
  for rich content).
- **Hooks**: all public filters/actions are prefixed `amendor_` (see README
  "Developer: hooks & filters").

## Function index

Functions are grouped by module with the defining line number. Line numbers may
drift; the name is the stable key.

### `includes/plugin-context.php`
- `amendor_get_plugin_mode` (22) — current context: amendor / fluentor / both
- `amendor_is_amendor_plugin` (32) — running as Amendor
- `amendor_is_fluentor_plugin` (42) — running as Fluentor
- `amendor_current_user_can_manage` (54) — **central capability gate** (`manage_options`)
- `amendor_get_plugin_display_name` (64) — display name for the UI
- `amendor_get_admin_parent_slug` (74) — admin page parent slug
- `amendor_get_backup_meta_key` (84) — post-meta key for backups
- `amendor_get_history_table_name` (94) / `amendor_get_debug_log_table_name` (106) / `amendor_get_backups_table_name` (118) — table names

### `includes/activation.php`
- `amendor_create_amendor_tables` (18) / `amendor_create_tables` (79) — dbDelta table creation
- `amendor_activate_amendor` (89) — activation hook (tables + cron)
- `amendor_schedule_log_pruning` (102) / `amendor_clear_log_pruning_schedule` (114) — daily cron
- `amendor_table_exists` (129) — table existence check
- `amendor_maybe_rename_legacy_table` (143) / `amendor_maybe_migrate_legacy_storage` (164) / `amendor_maybe_migrate_backups_to_table` (191) — legacy migrations
- `amendor_run_db_migrations` (254) — schema-version migrations

### `includes/search-data.php`
- `amendor_get_available_content_sources` (42) / `amendor_get_default_content_sources` (57) / `amendor_normalize_content_sources` (69) — content-source lists
- `amendor_get_seo_meta_field_groups` (69) — SEO meta key groups (Yoast / Rank Math) mapped to `seo_title` / `seo_description` sources
- `amendor_content_sources_include_elementor` (88) — has Elementor source selected
- `amendor_get_content_source_label` (99) / `amendor_format_content_sources_summary` (111) — labels
- `amendor_limit_search_term` (124) — clamp term length (1,000 chars)
- `amendor_normalize_allowed_fields` (139) — field-key allowlist
- `amendor_elementor_data_size_exceeded` (162) — 10 MB per-page guardrail
- `amendor_decode_elementor_data` (179) / `amendor_encode_elementor_data` (200) — JSON with flag fallback
- `amendor_get_integer_limit_option` (226) — settings helper
- `amendor_get_backup_retention_limit` (239) / `amendor_get_default_search_batch_size` (249) / `amendor_get_debug_log_retention_limit` (262) / `amendor_get_history_log_retention_limit` (274) — retention/batch getters
- `amendor_build_regex_pattern` (288) — PCRE pattern builder
- `amendor_create_changes_details` (314) / `amendor_merge_changes_details` (331) / `amendor_annotate_diff_entries` (349) — diff analysis
- `amendor_build_post_content_state` (366) — state snapshot for a post
- `amendor_prune_log_table` (384) / `amendor_run_log_pruning` (415) — cron pruning
- `amendor_get_search_history` (427) / `amendor_store_search_history` (465) — recent searches
- `amendor_get_search_results_per_page` (444) — pagination default
- `amendor_get_dashboard_stats` (492) — quick stats
- `amendor_build_json_diff` (530) — LCS line diff for the preview

### `includes/backups.php`
- `amendor_build_post_backup_snapshot` (18) — snapshot data structure
- `amendor_create_post_backup` (40) / `amendor_prune_backups` (85) — create/prune
- `amendor_create_elementor_backup` (115) / `amendor_get_elementor_backups` (144) — Elementor backups
- `amendor_get_post_backup_count` (172) — backup count per post
- `amendor_restore_elementor_backup` (191) — restore from backup
- `amendor_handle_backup_download` (303) — export a backup

### `includes/search-engine.php`
- `amendor_analyze_native_post_fields` (24) / `amendor_analyze_post_content_state` (75) / `amendor_analyze_elementor_data` (139) — analysis entry points
- `amendor_process_elementor_data_recursive` (163) — Elementor data walker (field-key aware)
- `amendor_render_match_block` (302) — match context markup
- `amendor_clear_elementor_cache_for_post` (324) — **scoped** cache clear (no global flush)
- `amendor_is_valid_regex` (359) — PCRE validation with resource limits
- `amendor_log_replacement` (381) / `amendor_redact_log_context` (456) / `amendor_add_debug_log` (480) — logging + redaction

### `includes/search-cache.php`
- `amendor_get_supported_post_types` (18) / `amendor_get_available_widgets` (29) / `amendor_normalize_selected_widgets` (68) — filter options
- `amendor_get_search_signature` (85) / `amendor_get_search_cache_transient_key` (103) / `amendor_get_whitelisted_post_types` (114) — cache keys
- `amendor_get_search_candidate_count` (127) / `amendor_get_search_candidate_ids_after` (170) — keyset-cursor scan
- `amendor_get_valid_search_cache` (218) / `amendor_set_search_cache` (247) — transient cache
- `amendor_build_search_result_entry` (259) — one result row
- `amendor_process_search_batch_next` (295) / `amendor_run_search_batch_request` (378) / `amendor_get_cached_search_results_payload` (458) — batched AJAX backend

### `includes/action-handlers.php`
- `amendor_handle_restore_action` (20) / `amendor_handle_undo_action` (60) / `amendor_handle_search_action` (140) / `amendor_handle_preview_action` (280) / `amendor_handle_replace_action` (409) — admin form handlers

### `includes/render-results.php`
- `amendor_render_results_item` (21) / `amendor_render_results_section` (155) / `amendor_get_results_section_html` (288) — shared result markup

### `includes/admin-chrome.php`
- `amendor_register_admin_pages` (16) — menu registration
- `amendor_sanitize_positive_integer_setting` (57) — settings sanitizer
- `amendor_get_default_debug_log_retention_setting` (67) / `amendor_get_default_history_log_retention_setting` (77) — defaults
- `amendor_register_debug_settings` (85) — settings page
- `amendor_admin_enqueue_scripts` (148) — assets
- `amendor_render_admin_notices` (231) / `amendor_get_admin_notices_html` (251) — notices/onboarding

### `includes/admin-main-page.php`
- `amendor_render_text_replacer_ui` (15) — the main search & replace UI

### `includes/log-debug-page.php`
- `amendor_get_debug_log_export_format` (17) / `amendor_prepare_debug_log_export_row` (31) / `amendor_send_debug_log_export` (63) — debug export
- `amendor_display_debug_log_page` (143) — debug log page

### `includes/log-history-page.php`
- `amendor_send_history_log_export` (20) — history export
- `amendor_display_change_history_log` (112) — change history page

### `includes/ajax-handlers.php`
- `amendor_check_backup_callback` (16) — backup status AJAX
- `amendor_run_search_batch_callback` (60) — batched search AJAX
- `amendor_get_search_results_callback` (108) — results AJAX
- `amendor_run_preview_callback` (177) — preview AJAX

### `includes/presets.php`
- `amendor_get_presets` (22) / `amendor_get_preset` (46) — saved-preset storage (`amendor_presets` option)
- `amendor_save_preset` (63) / `amendor_delete_preset` (90) — add/remove presets
- `amendor_build_preset_data` (106) / `amendor_validate_preset_data` (139) — capture + validate preset data
- `amendor_build_preset_export_payload` (171) / `amendor_send_preset_export` (188) — JSON export download
- `amendor_handle_import_preset` (219) — JSON import
- `amendor_handle_presets_action` (250) — save/delete/export/import dispatch
- `amendor_render_presets_box` (307) — presets UI (list + import)

### `includes/elementor-editor.php`
- `amendor_elementor_editor_assets` (22) — enqueues the editor tool (gated by `amendor_enable_elementor_editor_integration`)

## Notes

- **Load order** is low-risk today: `amendor.php` requires all modules before any
  hook fires, and function definitions are hoisted once their file loads. Keep
  every module in the require list exactly once (`check-structure` enforces it).
- **The Elementor editor tool** (`assets/js/editor.js`) is the only non-PHP
  runtime surface; it follows its own small conventions (feature-detected,
  version-fragile by design).
- **Tests**: pure logic (matching, diff, clamping, redaction) is kept inside
  isolated functions so it can be unit-tested without a WordPress install
  (Phase 1 of the hardening plan).
