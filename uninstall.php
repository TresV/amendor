<?php

/**
 * Amendor uninstall.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    return;
}

if (!get_option('amendor_delete_data_on_uninstall', false)) {
    delete_option('amendor_delete_data_on_uninstall');
    return;
}

global $wpdb;

delete_option('amendor_enable_persistent_debug_log');
delete_option('amendor_delete_data_on_uninstall');
delete_option('amendor_storage_schema_version');
delete_option('amendor_search_batch_size');
delete_option('amendor_presets');

$amendor_tables = [
    $wpdb->prefix . 'amendor_history',
    $wpdb->prefix . 'amendor_debug_log',
    $wpdb->prefix . 'amendor_backups',
];

foreach ($amendor_tables as $amendor_table_name) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $amendor_table_name)) {
        continue;
    }

    // Table names are plugin-owned and validated above.
    $wpdb->query("DROP TABLE IF EXISTS `{$amendor_table_name}`"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
}

// Purge Amendor transients (search caches, widget-type cache).
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_amendor_%' OR option_name LIKE '_transient_timeout_amendor_%'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
);

$wpdb->delete($wpdb->postmeta, ['meta_key' => '_amendor_backups'], ['%s']);
$wpdb->delete($wpdb->usermeta, ['meta_key' => 'amendor_search_history'], ['%s']);
