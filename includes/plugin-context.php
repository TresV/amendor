<?php
/**
 * Shared plugin runtime context helpers.
 *
 * @package ElementorTextReplacer
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('AMENDOR_PLUGIN_MODE')) {
    define('AMENDOR_PLUGIN_MODE', 'amendor');
}

/**
 * Return the current plugin mode.
 *
 * @return string
 */
function amendor_get_plugin_mode()
{
    return sanitize_key((string) AMENDOR_PLUGIN_MODE);
}

/**
 * Return whether the current bootstrap is Amendor.
 *
 * @return bool
 */
function amendor_is_amendor_plugin()
{
    return amendor_get_plugin_mode() === 'amendor';
}

/**
 * Return whether the current bootstrap is Fluentor.
 *
 * @return bool
 */
function amendor_is_fluentor_plugin()
{
    return false;
}

/**
 * Return the current plugin display name.
 *
 * @return string
 */
function amendor_get_plugin_display_name()
{
    return __('Amendor', 'elementor-text-replacer');
}

/**
 * Return the parent admin slug for the active plugin.
 *
 * @return string
 */
function amendor_get_admin_parent_slug()
{
    return 'amendor';
}

/**
 * Return the Amendor backup meta key.
 *
 * @return string
 */
function amendor_get_backup_meta_key()
{
    return '_amendor_backups';
}

/**
 * Return the Amendor history table name.
 *
 * @return string
 */
function amendor_get_history_table_name()
{
    global $wpdb;

    return $wpdb->prefix . 'amendor_history';
}

/**
 * Return the Amendor debug log table name.
 *
 * @return string
 */
function amendor_get_debug_log_table_name()
{
    global $wpdb;

    return $wpdb->prefix . 'amendor_debug_log';
}
