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

    // --- Backups Table ---
    $backups_table_name = amendor_get_backups_table_name();
    $sql_backups = "CREATE TABLE {$backups_table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id BIGINT UNSIGNED NOT NULL,
        timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        data longtext DEFAULT NULL,
        snapshot longtext DEFAULT NULL,
        PRIMARY KEY  (id),
        INDEX idx_post_id (post_id),
        INDEX idx_timestamp (timestamp)
    ) {$charset_collate};";
    dbDelta($sql_backups); // dbDelta handles creation and updates safely

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
    amendor_schedule_log_pruning();
    flush_rewrite_rules();
}

/**
 * Schedule the daily log pruning event.
 *
 * @return void
 */
function amendor_schedule_log_pruning()
{
    if (!wp_next_scheduled('amendor_daily_log_prune')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'amendor_daily_log_prune');
    }
}

/**
 * Clear the daily log pruning event.
 *
 * @return void
 */
function amendor_clear_log_pruning_schedule()
{
    $timestamp = wp_next_scheduled('amendor_daily_log_prune');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'amendor_daily_log_prune');
    }
}
add_action('amendor_daily_log_prune', 'amendor_run_log_pruning');

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
 * Move post-meta backups into the dedicated backups table.
 *
 * @return void
 */
function amendor_maybe_migrate_backups_to_table()
{
    global $wpdb;

    if (get_option('amendor_storage_schema_version', '') === '2') {
        return;
    }

    $table = amendor_get_backups_table_name();
    if (!amendor_table_exists($table)) {
        return;
    }

    $migrated = 0;
    $meta_keys = [
        amendor_get_backup_meta_key(),
        '_elementor_text_replacer_backups',
    ];

    foreach ($meta_keys as $meta_key) {
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key),
            ARRAY_A
        ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        foreach ((array) $rows as $row) {
            $backups = maybe_unserialize($row['meta_value']);
            if (!is_array($backups)) {
                continue;
            }

            foreach ($backups as $backup) {
                if (!is_array($backup)) {
                    continue;
                }

                $wpdb->insert(
                    $table,
                    [
                        'post_id' => (int) $row['post_id'],
                        'timestamp' => isset($backup['timestamp']) ? (string) $backup['timestamp'] : current_time('mysql', 1),
                        'data' => (isset($backup['data']) && is_array($backup['data'])) ? wp_json_encode($backup['data']) : null,
                        'snapshot' => isset($backup['snapshot']) ? wp_json_encode($backup['snapshot']) : null,
                    ],
                    ['%d', '%s', '%s', '%s']
                );
                $migrated++;
            }
        }

        $wpdb->delete($wpdb->postmeta, ['meta_key' => $meta_key], ['%s']);
    }

    update_option('amendor_storage_schema_version', '2', false);

    amendor_add_debug_log('Migrated post-meta backups into dedicated backups table.', 'INFO', ['migrated' => $migrated]);
}

/**
 * Run one-time storage migrations on plugin load.
 *
 * @return void
 */
function amendor_run_db_migrations()
{
    amendor_maybe_migrate_legacy_storage();
    amendor_maybe_migrate_backups_to_table();
}
add_action('plugins_loaded', 'amendor_run_db_migrations', 5);
