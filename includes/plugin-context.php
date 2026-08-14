<?php

/**
 * Shared plugin runtime context helpers.
 *
 * @package Amendor
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
 * Return whether the current user can manage plugin settings and actions.
 *
 * Central capability check used by every admin handler and AJAX endpoint.
 *
 * @return bool
 */
function amendor_current_user_can_manage()
{
    return current_user_can('manage_options');
}

/**
 * Return whether the current user can use premium (Pro) features.
 *
 * Wraps the Freemius license check so call sites don't need to know about the
 * SDK. In the free build this is always false; in the premium build it
 * reflects an active (paid) license.
 *
 * @return bool
 */
function amendor_can_use_premium_features()
{
    return function_exists('ame_fs') && ame_fs()->can_use_premium_code();
}

/**
 * Restrict premium-only search modes (regex) to Pro users.
 *
 * Downgrades any regex request to a partial match for non-Pro users. Applied at
 * every entry point (form + AJAX) so crafted requests can't enable Pro modes
 * in the free version.
 *
 * @param string $search_mode Raw search mode.
 * @return string Allowed search mode (partial or exact).
 */
function amendor_restrict_search_mode($search_mode)
{
    if ('regex' === $search_mode && !amendor_can_use_premium_features()) {
        return 'partial';
    }
    return $search_mode;
}

/**
 * Return the current plugin display name.
 *
 * @return string
 */
function amendor_get_plugin_display_name()
{
    return __('Amendor', 'amendor');
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

/**
 * Return the Amendor backups table name.
 *
 * @return string
 */
function amendor_get_backups_table_name()
{
    global $wpdb;

    return $wpdb->prefix . 'amendor_backups';
}
