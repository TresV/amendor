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

$plugin_tables = [
    $wpdb->prefix . 'amendor_history',
    $wpdb->prefix . 'amendor_debug_log',
];

foreach ($plugin_tables as $table_name) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table_name)) {
        continue;
    }

    $wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
}

$wpdb->delete($wpdb->postmeta, ['meta_key' => '_amendor_backups'], ['%s']);
$wpdb->delete($wpdb->usermeta, ['meta_key' => 'amendor_search_history'], ['%s']);
