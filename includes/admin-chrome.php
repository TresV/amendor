<?php

/**
 * Admin Pages, Settings, Assets, and Main UI
 *
 * @package Amendor
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the main admin page and the history/debug sub-menu pages.
 */
function amendor_register_admin_pages()
{
    if (function_exists('amendor_is_fluentor_plugin') && amendor_is_fluentor_plugin()) {
        return;
    }

    add_menu_page(
        __('Amendor', 'amendor'),                          // Page Title
        __('Amendor', 'amendor'),                          // Menu Title
        'manage_options',                   // Capability
        amendor_get_admin_parent_slug(),    // Menu Slug (Parent)
        'amendor_render_text_replacer_ui', // Callback function for main page
        'dashicons-edit',                   // Icon
        80                                  // Position
    );

    add_submenu_page(
        amendor_get_admin_parent_slug(),    // Parent Slug
        __('Change History Log', 'amendor'),               // Page Title
        __('History Log', 'amendor'),                      // Menu Title
        'manage_options',                   // Capability
        'amendor-change-history',               // Menu Slug (Submenu 1)
        'amendor_display_change_history_log'        // Callback function for history page
    );

    // Add the new Debug Log submenu page
    add_submenu_page(
        amendor_get_admin_parent_slug(),    // Parent Slug (Attach to main menu)
        __('Debug Log', 'amendor'),                        // Page Title
        __('Debug Log', 'amendor'),                        // Menu Title
        'manage_options',                   // Capability
        'amendor-debug-log',                    // Menu Slug (Submenu 2) - MUST BE UNIQUE
        'amendor_display_debug_log_page'            // Callback function for debug log page
    );
}
add_action('admin_menu', 'amendor_register_admin_pages');


/**
 * Register the setting for enabling/disabling persistent debug logging.
 */
function amendor_sanitize_positive_integer_setting($value)
{
    return max(1, absint($value));
}

/**
 * Get the default debug log retention setting value.
 *
 * @return int
 */
function amendor_get_default_debug_log_retention_setting()
{
    return defined('AMENDOR_DEFAULT_DEBUG_LOG_RETENTION') ? AMENDOR_DEFAULT_DEBUG_LOG_RETENTION : 5000;
}

/**
 * Get the default history log retention setting value.
 *
 * @return int
 */
function amendor_get_default_history_log_retention_setting()
{
    return defined('AMENDOR_DEFAULT_HISTORY_LOG_RETENTION') ? AMENDOR_DEFAULT_HISTORY_LOG_RETENTION : 5000;
}

/**
 * Register the setting for enabling/disabling persistent debug logging.
 */
function amendor_register_debug_settings()
{
    register_setting(
        'amendor_debug_settings', // Option group - must match settings_fields() call
        'amendor_enable_persistent_debug_log', // Option name
        [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean', // Use WP REST API's boolean sanitizer
            'default' => false,
            'show_in_rest' => false, // Typically not needed in REST API
        ]
    );

    register_setting(
        'amendor_debug_settings',
        'amendor_delete_data_on_uninstall',
        [
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
            'show_in_rest' => false,
        ]
    );

    register_setting(
        'amendor_debug_settings',
        'amendor_debug_log_retention_limit',
        [
            'type' => 'integer',
            'sanitize_callback' => 'amendor_sanitize_positive_integer_setting',
            'default' => amendor_get_default_debug_log_retention_setting(),
            'show_in_rest' => false,
        ]
    );

    register_setting(
        'amendor_debug_settings',
        'amendor_history_log_retention_limit',
        [
            'type' => 'integer',
            'sanitize_callback' => 'amendor_sanitize_positive_integer_setting',
            'default' => amendor_get_default_history_log_retention_setting(),
            'show_in_rest' => false,
        ]
    );

    register_setting(
        'amendor_debug_settings',
        'amendor_search_batch_size',
        [
            'type' => 'integer',
            'sanitize_callback' => 'amendor_sanitize_positive_integer_setting',
            'default' => AMENDOR_DEFAULT_SEARCH_BATCH_SIZE,
            'show_in_rest' => false,
        ]
    );
}
add_action('admin_init', 'amendor_register_debug_settings');


/**
 * Enqueue admin scripts and styles, and localize strings for JS.
 */
function amendor_admin_enqueue_scripts($hook_suffix)
{
    // Only load the full admin bundle on the plugin's own admin pages.
    $plugin_pages = [
        'toplevel_page_' . amendor_get_admin_parent_slug(),
        amendor_get_admin_parent_slug() . '_page_amendor-change-history',
        amendor_get_admin_parent_slug() . '_page_amendor-debug-log',
    ];

    $screen = get_current_screen();
    $current_page_hook = $screen ? $screen->id : '';
    if (!in_array($current_page_hook, $plugin_pages, true)) {
        return;
    }

    $admin_css_path = AMENDOR_PLUGIN_DIR . 'assets/css/admin.css';
    $admin_js_path = AMENDOR_PLUGIN_DIR . 'assets/js/admin.js';

    wp_enqueue_style(
        'amendor-admin-style',
        AMENDOR_PLUGIN_URL . 'assets/css/admin.css',
        [],
        file_exists($admin_css_path) ? (string) filemtime($admin_css_path) : AMENDOR_VERSION
    );

    wp_enqueue_script(
        'amendor-admin-script',
        AMENDOR_PLUGIN_URL . 'assets/js/admin.js',
        ['jquery'],
        file_exists($admin_js_path) ? (string) filemtime($admin_js_path) : AMENDOR_VERSION,
        true
    );

    if (function_exists('amendor_add_debug_log')) {
        amendor_add_debug_log('Enqueued full plugin admin bundle.', 'DEBUG', [
            'hook_suffix' => (string) $hook_suffix,
            'current_page_hook' => (string) $current_page_hook,
            'screen_id' => $screen ? (string) $screen->id : '',
            'screen_base' => $screen ? (string) $screen->base : '',
        ]);
    }

    // Localize strings for JavaScript (specifically for confirmation dialogs)
    $js_vars = [
        'confirm_replace_title' => __('Confirm Replacement', 'amendor'),
        /* translators: %d: Number of selected posts. */
        'confirm_replace_text' => __('You are about to replace text in %d selected post(s).', 'amendor'), // %d will be replaced by JS
        'confirm_replace_backup_notice' => __('A backup snapshot of the selected post content will be created for each modified post BEFORE changes are saved.', 'amendor'),
        'confirm_replace_warning' => __('This action cannot be easily undone, except by restoring from a backup.', 'amendor'),
        'confirm_replace_proceed' => __('Are you sure you want to proceed?', 'amendor'),
        /* translators: %d: Number of selected posts. */
        'confirm_replace_large_batch_warning' => __('WARNING: You have selected a large number of posts (%d). This operation might take some time. Ensure your server\'s maximum execution time is set sufficiently high.', 'amendor'), // %d will be replaced
        'alert_select_items' => __('Please select at least one item from the results to replace.', 'amendor'),
        'confirm_restore_title' => __('Confirm Restore', 'amendor'),
        /* translators: %d: Post ID. */
        'confirm_restore_text' => __('Are you sure you want to restore Post ID %d from this backup? This will overwrite the saved content fields for this post.', 'amendor'), // %d will be replaced
        'confirm_undo_text' => __('Are you sure you want to undo the last replacement operation? This will restore the affected posts to their state before the last replace.', 'amendor'),
        'confirm_clear_log_title' => __('Confirm Clear Log', 'amendor'),
        'confirm_clear_log_text' => __('Are you sure you want to clear the ENTIRE debug log? This cannot be undone.', 'amendor'),
        'confirm_delete_preset' => __('Delete this preset?', 'amendor'),
        'backup_selection_warning' => __('Some selected posts do not have an existing plugin backup yet. A fresh backup will be created automatically before replacement.', 'amendor'),
        'backup_selection_safe' => __('All selected posts already have at least one saved plugin backup.', 'amendor'),
        'search_batch_nonce' => wp_create_nonce('amendor_run_search_batch'),
        'search_results_nonce' => wp_create_nonce('amendor_get_search_results'),
        'search_progress_label' => __('Scanning selected content sources...', 'amendor'),
        'search_progress_done' => __('Search scan complete. Loading results...', 'amendor'),
        'search_progress_error' => __('Search scan failed. Falling back to standard form submission.', 'amendor'),
        'search_cancel_label' => __('Cancel Scan', 'amendor'),
        'search_cancelled' => __('Search scan cancelled.', 'amendor'),
        'swap_require_old' => __('Please enter the URL or domain to search for.', 'amendor'),
        'preview_nonce' => wp_create_nonce('amendor_run_preview'),
        'preview_progress_label' => __('Generating preview...', 'amendor'),
        'preview_progress_error' => __('Preview generation failed. Falling back to standard form submission.', 'amendor'),
    ];
    wp_localize_script('amendor-admin-script', 'amendor_admin_vars', $js_vars);
}
add_action('admin_enqueue_scripts', 'amendor_admin_enqueue_scripts');

/**
 * Render admin notices.
 *
 * @param array $messages Messages to display.
 * @return void
 */
function amendor_render_admin_notices(array $messages)
{
    if (empty($messages)) {
        return;
    }
    foreach ($messages as $message) :
?>
        <div class="notice notice-<?php echo esc_attr($message['type']); ?> is-dismissible" style="margin-bottom: 15px;">
            <p><?php echo wp_kses_post($message['text']); ?></p>
        </div>
<?php
    endforeach;
}

/**
 * Get admin notices HTML.
 *
 * @param array $messages Messages to display.
 * @return string
 */
function amendor_get_admin_notices_html(array $messages)
{
    ob_start();
    amendor_render_admin_notices($messages);
    return ob_get_clean();
}
