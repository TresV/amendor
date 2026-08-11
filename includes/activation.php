<?php
/**
 * Activation Functions
 *
 * @package Amendor
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * Create the Amendor-owned tables.
 *
 * @return void
 */
function amendor_create_amendor_tables()
{
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset_collate = $wpdb->get_charset_collate();

    // --- History Table ---
    $history_table_name = amendor_get_history_table_name();
    $sql_history = "CREATE TABLE {$history_table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        post_id BIGINT UNSIGNED NOT NULL,
        post_title text NOT NULL,
        search_term text NOT NULL,
        replace_term text NOT NULL,
        search_mode varchar(10) NOT NULL,
        bulk_operation tinyint(1) NOT NULL DEFAULT 0,
        changes_made int(11) NOT NULL DEFAULT 0,
        PRIMARY KEY  (id),
        INDEX idx_timestamp (timestamp),
        INDEX idx_user_id (user_id),
        INDEX idx_post_id (post_id)
    ) {$charset_collate};";
    dbDelta($sql_history); // dbDelta handles creation and updates safely

    // --- Debug Log Table ---
    $debug_table_name = amendor_get_debug_log_table_name();
    $sql_debug = "CREATE TABLE {$debug_table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        log_level varchar(10) NOT NULL DEFAULT 'DEBUG', -- e.g., DEBUG, INFO, WARN, ERROR
        message longtext NOT NULL,
        context text DEFAULT NULL, -- Optional: Store related data like post_id, action
        PRIMARY KEY  (id),
        INDEX idx_timestamp (timestamp),
        INDEX idx_log_level (log_level)
    ) {$charset_collate};";
    dbDelta($sql_debug); // dbDelta handles creation and updates safely

}

/**
 * Create all historical plugin tables for legacy installs.
 *
 * @return void
 */
function amendor_create_tables()
{
    amendor_create_amendor_tables();
}

/**
 * Activate Amendor.
 *
 * @return void
 */
function amendor_activate_amendor()
{
    amendor_maybe_migrate_legacy_storage();
    amendor_create_amendor_tables();
    flush_rewrite_rules();
}

/**
 * Return whether a table exists.
 *
 * @param string $table_name Table name.
 * @return bool
 */
function amendor_table_exists($table_name)
{
    global $wpdb;

    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;
}

/**
 * Rename a legacy table when the Amendor-owned target table does not exist yet.
 *
 * @param string $legacy_table Legacy table name.
 * @param string $target_table Target table name.
 * @return void
 */
function amendor_maybe_rename_legacy_table($legacy_table, $target_table)
{
    global $wpdb;

    if (
        !preg_match('/^[A-Za-z0-9_]+$/', $legacy_table)
        || !preg_match('/^[A-Za-z0-9_]+$/', $target_table)
        || amendor_table_exists($target_table)
        || !amendor_table_exists($legacy_table)
    ) {
        return;
    }

    $wpdb->query("RENAME TABLE `{$legacy_table}` TO `{$target_table}`");
}

/**
 * Move legacy shared storage into Amendor-owned storage keys.
 *
 * @return void
 */
function amendor_maybe_migrate_legacy_storage()
{
    global $wpdb;

    if (get_option('amendor_storage_schema_version', '') === '1') {
        return;
    }

    amendor_maybe_rename_legacy_table($wpdb->prefix . 'elementor_text_replacer_history', amendor_get_history_table_name());
    amendor_maybe_rename_legacy_table($wpdb->prefix . 'elementor_text_replacer_debug_log', amendor_get_debug_log_table_name());

    $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_key = %s",
            amendor_get_backup_meta_key(),
            '_elementor_text_replacer_backups'
        )
    );

    update_option('amendor_storage_schema_version', '1', false);
}

/**
 * Run one-time storage migrations on plugin load.
 *
 * @return void
 */
function amendor_run_db_migrations()
{
    amendor_maybe_migrate_legacy_storage();
}
add_action('plugins_loaded', 'amendor_run_db_migrations', 5);
