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
        'backup_selection_warning' => __('Some selected posts do not have an existing plugin backup yet. A fresh backup will be created automatically before replacement.', 'amendor'),
        'backup_selection_safe' => __('All selected posts already have at least one saved plugin backup.', 'amendor'),
        'search_batch_nonce' => wp_create_nonce('amendor_run_search_batch'),
        'search_results_nonce' => wp_create_nonce('amendor_get_search_results'),
        'search_progress_label' => __('Scanning selected content sources...', 'amendor'),
        'search_progress_done' => __('Search scan complete. Loading results...', 'amendor'),
        'search_progress_error' => __('Search scan failed. Falling back to standard form submission.', 'amendor'),
        'search_cancel_label' => __('Cancel Scan', 'amendor'),
        'search_cancelled' => __('Search scan cancelled.', 'amendor'),
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

/**
 * Renders the main Elementor Text Replacer admin page UI.
 */
function amendor_render_text_replacer_ui()
{
    // Security Check: Ensure the user has the required capability.
    if (!amendor_current_user_can_manage()) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'amendor'));
        return; // Stop execution if user doesn't have permission
    }

    // --- Initialize Variables ---
    $amendor_messages = []; // Array to hold user feedback messages (success, error, info)

    // Check for redirect notices
    if (isset($_GET['amendor_notice'])) {
        if ($_GET['amendor_notice'] === 'no_backup_data') {
            $amendor_messages[] = ['type' => 'warning', 'text' => __('⚠️ No backup data found to download. Perform some replacements first to create backups.', 'amendor')];
        }
        // Add more notices here if needed in the future
    }

    // Add an initial marker to the debug log for clarity
    amendor_add_debug_log("====== ETP PAGE LOAD / ACTION START ======", 'DEBUG');

    // Determine the current action from POST or GET requests
    $action = '';
    if (isset($_POST['action']) && $_POST['action'] !== '') {
        $action = sanitize_key(wp_unslash($_POST['action']));
    } elseif (isset($_POST['amendor_form_action']) && $_POST['amendor_form_action'] !== '') {
        $action = sanitize_key(wp_unslash($_POST['amendor_form_action']));
    } elseif (isset($_GET['action'])) {
        $action = sanitize_key(wp_unslash($_GET['action']));
    }
    amendor_add_debug_log("Action Triggered", 'DEBUG', ['action' => $action ?: '(None)']);

    // --- Prepare Variables from POST/GET data ---
    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $replace = isset($_POST['replace']) ? sanitize_text_field(wp_unslash($_POST['replace'])) : '';
    $search_mode = isset($_POST['search_mode']) ? sanitize_key($_POST['search_mode']) : 'partial';
    $selected_ids = isset($_POST['selected_posts']) ? array_map('intval', (array) $_POST['selected_posts']) : [];
    $selected_widgets = isset($_POST['widget_types']) ? array_map('sanitize_text_field', (array) $_POST['widget_types']) : [];
    $selected_content_sources = isset($_POST['content_sources']) ? array_map('sanitize_key', (array) $_POST['content_sources']) : [];
    $bulk_search = isset($_POST['bulk_search']) ? array_map(fn($item) => sanitize_text_field(wp_unslash($item)), (array) $_POST['bulk_search']) : [];
    $bulk_replace = isset($_POST['bulk_replace']) ? array_map(fn($item) => sanitize_text_field(wp_unslash($item)), (array) $_POST['bulk_replace']) : [];

    // Initialize result arrays and counters
    $results = [];
    $preview_results = [];
    $scanned_posts = 0;
    $matched_posts = 0;
    $total_candidate_posts = 0;
    $total_pages = 0;
    $paged = isset($_REQUEST['paged']) ? max(1, intval($_REQUEST['paged'])) : 1;
    $results_per_page = amendor_get_search_results_per_page(isset($_REQUEST['results_per_page']) ? wp_unslash($_REQUEST['results_per_page']) : null);

    $search_attempted = ($action === 'search');
    $preview_attempted = ($action === 'preview_selected');

    $supported_post_types = amendor_get_supported_post_types();
    $available_widgets = amendor_get_available_widgets();
    $available_content_sources = amendor_get_available_content_sources();
    $selected_content_sources = amendor_normalize_content_sources($selected_content_sources);

    amendor_handle_restore_action($action, $amendor_messages);
    amendor_handle_undo_action($action, $amendor_messages);

    $search_payload = amendor_handle_search_action(
        $action,
        $search,
        $search_mode,
        $selected_widgets,
        $selected_content_sources,
        $supported_post_types,
        $paged,
        $results_per_page,
        $amendor_messages
    );
    $results = $search_payload['results'];
    $scanned_posts = $search_payload['scanned_posts'];
    $matched_posts = $search_payload['matched_posts'];
    $total_candidate_posts = $search_payload['total_candidate_posts'];
    $total_pages = $search_payload['total_pages'];
    $paged = $search_payload['paged'];

    $preview_results = amendor_handle_preview_action(
        $action,
        $selected_ids,
        $search,
        $replace,
        $search_mode,
        $selected_widgets,
        $selected_content_sources,
        $supported_post_types,
        $amendor_messages
    );

    amendor_handle_replace_action(
        $action,
        $selected_ids,
        $search,
        $replace,
        $search_mode,
        $bulk_search,
        $bulk_replace,
        $selected_widgets,
        $selected_content_sources,
        $supported_post_types,
        $amendor_messages
    );


    // ======================================================================
    // --- RENDER THE ADMIN PAGE UI ---
    // ======================================================================
    ?>
    <div class="wrap amendor-wrap">
        <h1><span class="dashicons dashicons-edit" style="font-size: 1.3em; vertical-align: middle;"></span> <?php esc_html_e('Amendor', 'amendor'); ?></h1>

        <?php // --- Display collected notices/messages --- 
        ?>
        <div id="amendor-admin-notices">
            <?php amendor_render_admin_notices($amendor_messages); ?>
        </div>

        <?php settings_errors(); ?>

        <form method="post" id="elementor-search-form" action="<?php echo esc_url(admin_url('admin.php?page=' . amendor_get_admin_parent_slug())); ?>">
            <?php wp_nonce_field('amendor_search_action', 'amendor_search_nonce'); ?>
            <?php wp_nonce_field('amendor_replace_action', 'amendor_replace_nonce'); ?>
            <?php wp_nonce_field('amendor_preview_action', 'amendor_preview_nonce'); ?>
            <?php wp_nonce_field('amendor_undo_action', 'amendor_undo_nonce'); ?>
            <input type="hidden" name="amendor_form_action" id="amendor-form-action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="paged" id="amendor-paged-input" value="<?php echo esc_attr($paged); ?>">
            <input type="hidden" name="results_per_page" id="amendor-results-per-page-input" value="<?php echo esc_attr($results_per_page); ?>">
            <input type="hidden" name="search_cache_key" id="amendor-search-cache-key" value="<?php echo isset($_POST['search_cache_key']) ? esc_attr(sanitize_key(wp_unslash($_POST['search_cache_key']))) : ''; ?>">

            <div class="amendor-layout">
                <?php // --- Main Content Area --- 
                ?>
                <div class="amendor-main-content">

                    <!-- 1. Search & Replace Terms Section -->
                    <div class="amendor-section postbox">
                        <h2 class="hndle"><span><?php esc_html_e('1. Search & Replace Terms', 'amendor'); ?></span></h2>
                        <div class="inside">
                            <p class="description" style="margin-bottom: 15px;"><?php esc_html_e('Define the primary text you want to find and replace. Use the Bulk Replace section below for multiple pairs.', 'amendor'); ?></p>
                            <table class="form-table">
                                <tr>
                                    <th><label for="search"><?php esc_html_e('Search For', 'amendor'); ?></label></th>
                                    <td>
                                        <input name="search" type="text" id="search" value="<?php echo esc_attr($search); ?>" class="regular-text" list="amendor-search-history-list">
                                        <?php $history = amendor_get_search_history(); ?>
                                        <?php if (!empty($history)) : ?>
                                            <datalist id="amendor-search-history-list">
                                                <?php foreach ($history as $past_search) : ?>
                                                    <option value="<?php echo esc_attr($past_search); ?>">
                                                    <?php endforeach; ?>
                                            </datalist>
                                            <p class="description"><?php esc_html_e('Start typing or use arrow keys to see recent searches.', 'amendor'); ?></p>
                                            <div class="amendor-recent-searches">
                                                <span class="description"><?php esc_html_e('Recent:', 'amendor'); ?></span>
                                                <?php foreach (array_slice($history, 0, 5) as $past_search) : ?>
                                                    <button type="button" class="button button-link amendor-recent-search" data-search="<?php echo esc_attr($past_search); ?>"><?php echo esc_html($past_search); ?></button>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="description"><?php esc_html_e('Enter the text you want to find. Case sensitivity depends on the Search Mode.', 'amendor'); ?></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="replace"><?php esc_html_e('Replace With', 'amendor'); ?></label></th>
                                    <td>
                                        <input name="replace" type="text" id="replace" value="<?php echo esc_attr($replace); ?>" class="regular-text">
                                        <p class="description"><?php esc_html_e('Enter the replacement text. Leave empty to delete the found text.', 'amendor'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="search_mode"><?php esc_html_e('Search Mode', 'amendor'); ?></label></th>
                                    <td>
                                        <select name="search_mode" id="search_mode">
                                            <option value="partial" <?php selected($search_mode, 'partial'); ?>><?php esc_html_e('Partial Match (Case-Insensitive, Default)', 'amendor'); ?></option>
                                            <option value="exact" <?php selected($search_mode, 'exact'); ?>><?php esc_html_e('Exact Text (Case-Sensitive)', 'amendor'); ?></option>
                                            <option value="regex" <?php selected($search_mode, 'regex'); ?>><?php esc_html_e('Regular Expression (PCRE, Case-Insensitive)', 'amendor'); ?></option>
                                        </select>
                                        <p class="description"><?php esc_html_e('Choose matching method. Exact Text matches the typed text exactly, including case, within larger content. Regex uses PHP PCRE syntax (e.g., `\bword\b`).', 'amendor'); ?></p>
                                        <div id="regex-help" style="display: <?php echo $search_mode === 'regex' ? 'block' : 'none'; ?>; margin-top: 10px; padding: 10px; background: #f0f0f0; border: 1px solid #ddd; font-size: 0.9em;">
                                            <strong><?php esc_html_e('Regex Tips:', 'amendor'); ?></strong> <?php esc_html_e('Use PCRE syntax (no delimiters needed here). Special characters like', 'amendor'); ?> <code>.^$*+?()[{|</code> <?php esc_html_e('need escaping with', 'amendor'); ?> <code>\</code> (e.g., <code>1\.0</code>). <?php esc_html_e('Search is case-insensitive (<code>i</code> flag) and Unicode-aware (<code>u</code> flag). Use <code>\b</code> for word boundaries. Test carefully!', 'amendor'); ?>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <!-- 2. Bulk Replace Section -->
                    <div class="amendor-section postbox">
                        <h2 class="hndle"><span><?php esc_html_e('2. Bulk Replace (Optional)', 'amendor'); ?></span></h2>
                        <div class="inside">
                            <p class="description" style="margin-bottom: 15px;"><?php esc_html_e('Add multiple pairs here to run sequentially. If used, the single Search/Replace fields above are ignored during replacement.', 'amendor'); ?></p>
                            <div id="bulk-replace-container">
                                <?php
                                $bulk_pairs_count = max(1, count($bulk_search));
                                for ($i = 0; $i < $bulk_pairs_count; $i++):
                                ?>
                                    <div class="bulk-replace-pair" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
                                        <input type="text" name="bulk_search[]" placeholder="<?php esc_attr_e('Search for...', 'amendor'); ?>" class="regular-text" style="flex-grow: 1;" value="<?php echo isset($bulk_search[$i]) ? esc_attr($bulk_search[$i]) : ''; ?>">
                                        <span>➡️</span>
                                        <input type="text" name="bulk_replace[]" placeholder="<?php esc_attr_e('Replace with...', 'amendor'); ?>" class="regular-text" style="flex-grow: 1;" value="<?php echo isset($bulk_replace[$i]) ? esc_attr($bulk_replace[$i]) : ''; ?>">
                                        <button type="button" class="button remove-pair" title="<?php esc_attr_e('Remove this pair', 'amendor'); ?>">×</button>
                                    </div>
                                <?php endfor; ?>
                            </div>
                            <button type="button" id="add-bulk-pair" class="button button-secondary">
                                <span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e('Add Another Pair', 'amendor'); ?>
                            </button>
                        </div>
                    </div>


                    <!-- 3. Filters Section -->
                    <div class="amendor-section postbox">
                        <h2 class="hndle"><span><?php esc_html_e('3. Filters', 'amendor'); ?></span></h2>
                        <div class="inside">
                            <p class="description" style="margin-bottom: 15px;"><?php esc_html_e('Choose which post content sources should be searched or replaced. Widget filtering only applies when Elementor content is included.', 'amendor'); ?></p>
                            <table class="form-table">
                                <tr>
                                    <th><?php esc_html_e('Content Sources', 'amendor'); ?></th>
                                    <td>
                                        <fieldset id="amendor-content-sources">
                                            <?php foreach ($available_content_sources as $source_key => $source_label): ?>
                                                <label style="display: block; margin-bottom: 6px;">
                                                    <input type="checkbox" name="content_sources[]" value="<?php echo esc_attr($source_key); ?>" class="amendor-content-source-checkbox" <?php checked(in_array($source_key, $selected_content_sources, true)); ?>>
                                                    <?php echo esc_html($source_label); ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </fieldset>
                                        <p class="description"><?php esc_html_e('By default Amendor searches Elementor data plus native post fields. Uncheck sources to narrow the scan.', 'amendor'); ?></p>
                                    </td>
                                </tr>
                                <tr id="amendor-widget-filter-row" style="<?php echo in_array('elementor', $selected_content_sources, true) ? '' : 'display: none;'; ?>">
                                    <th><label for="widget_types"><?php esc_html_e('Widget Types', 'amendor'); ?></label></th>
                                    <td>
                                        <?php if (!empty($available_widgets)): ?>
                                            <select name="widget_types[]" id="widget_types" multiple style="width: 100%; min-height: 150px;" <?php disabled(!in_array('elementor', $selected_content_sources, true)); ?>>
                                                <?php foreach ($available_widgets as $name => $title): ?>
                                                    <option value="<?php echo esc_attr($name); ?>" <?php selected(in_array($name, $selected_widgets)); ?>>
                                                        <?php echo esc_html($title); ?> (<?php echo esc_html($name); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="description"><?php esc_html_e('Leave empty to search/replace in all widget types. Use Ctrl/Cmd + Click for standard multi-select.', 'amendor'); ?></p>
                                        <?php else: ?>
                                            <p><em><?php esc_html_e('Could not load Elementor widgets list. Filtering by widget type is unavailable. Ensure Elementor plugin is active.', 'amendor'); ?></em></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <?php
                    amendor_render_results_section([
                        'preview_attempted' => $preview_attempted,
                        'search_attempted' => $search_attempted,
                        'preview_results' => $preview_results,
                        'results' => $results,
                        'selected_ids' => $selected_ids,
                        'action' => $action,
                        'paged' => $paged,
                        'total_pages' => $total_pages,
                        'matched_posts' => $matched_posts,
                        'total_candidate_posts' => $total_candidate_posts,
                        'results_per_page' => $results_per_page,
                        'content_sources' => $selected_content_sources,
                    ]);
                    ?>
                </div> <?php // End amendor-main-content 
                        ?>

                <?php // --- Sidebar Area --- 
                ?>
                <div class="amendor-sidebar">

                    <!-- Quick Stats Panel -->
                    <div id="amendor-quick-stats" class="postbox">
                        <h2 class="hndle"><span><?php esc_html_e('Quick Stats', 'amendor'); ?></span></h2>
                        <div class="inside">
                            <?php $stats = amendor_get_dashboard_stats(); ?>
                            <ul style="margin: 0; padding: 0; list-style: none;">
                                <li style="padding: 6px 0; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between;">
                                    <span><?php esc_html_e('Total Operations', 'amendor'); ?></span>
                                    <strong><?php echo esc_html(number_format_i18n($stats['total_operations'])); ?></strong>
                                </li>
                                <li style="padding: 6px 0; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between;">
                                    <span><?php esc_html_e('Total Changes', 'amendor'); ?></span>
                                    <strong><?php echo esc_html(number_format_i18n($stats['total_changes'])); ?></strong>
                                </li>
                                <li style="padding: 6px 0; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between;">
                                    <span><?php esc_html_e('Pages Modified', 'amendor'); ?></span>
                                    <strong><?php echo esc_html(number_format_i18n($stats['pages_modified'])); ?></strong>
                                </li>
                                <li style="padding: 6px 0; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between;">
                                    <span><?php esc_html_e('Backups Stored', 'amendor'); ?></span>
                                    <strong><?php echo esc_html(number_format_i18n($stats['total_backups'])); ?></strong>
                                </li>
                                <li style="padding: 6px 0; display: flex; justify-content: space-between;">
                                    <span><?php esc_html_e('Last Activity', 'amendor'); ?></span>
                                    <strong><?php echo $stats['last_activity'] !== '' ? esc_html($stats['last_activity']) : esc_html__('—', 'amendor'); ?></strong>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Actions Panel -->
                    <div id="amendor-actions-panel" class="postbox">
                        <h2 class="hndle"><span><?php esc_html_e('Actions', 'amendor'); ?></span></h2>
                        <div class="inside">
                            <p id="backup-reminder" style="display: none; margin-bottom: 15px; padding: 10px; background: #fff8e1; border-left: 4px solid #ffb900;">
                                <strong><?php esc_html_e('⚠️ Backup Recommended', 'amendor'); ?></strong><br>
                                <span class="amendor-backup-reminder-text"><?php esc_html_e('Some selected posts do not have an existing backup yet. The plugin will create one automatically before replacement.', 'amendor'); ?></span>
                            </p>
                            <div class="amendor-action-buttons">
                                <button type="submit" name="action" value="search" class="button button-secondary button-large amendor-action-button" id="search-button">
                                    <span class="dashicons dashicons-search"></span> <?php esc_html_e('Search Only', 'amendor'); ?>
                                </button>
                                <div id="amendor-search-progress" class="notice inline" style="display: none; margin: 0;">
                                    <p class="amendor-search-progress-text"></p>
                                    <button type="button" id="amendor-search-cancel" class="button button-small" style="display: none; margin-top: 6px;">
                                        <span class="dashicons dashicons-no-alt"></span> <?php esc_html_e('Cancel Scan', 'amendor'); ?>
                                    </button>
                                </div>
                                <button type="submit" name="action" value="preview_selected" class="button button-secondary button-large amendor-action-button" id="preview-button" disabled>
                                    <span class="dashicons dashicons-visibility"></span> <?php esc_html_e('Preview Selected', 'amendor'); ?>
                                </button>
                                <button type="submit" name="action" value="replace_selected" class="button button-primary button-large amendor-action-button" id="replace-button" disabled onclick="return confirmReplaceAction();">
                                    <span class="dashicons dashicons-yes"></span> <?php esc_html_e('Replace Selected', 'amendor'); ?>
                                </button>
                                <button type="submit" name="action" value="undo" class="button button-secondary button-large amendor-action-button" id="undo-button" onclick="return confirm(amendor_admin_vars.confirm_undo_text);">
                                    <span class="dashicons dashicons-undo"></span> <?php esc_html_e('Undo Last Replace', 'amendor'); ?>
                                </button>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=' . amendor_get_admin_parent_slug() . '&action=download_backup'), 'amendor_download_backup_action', 'amendor_download_nonce')); ?>"
                                    class="button button-secondary button-large amendor-action-button" id="backup-button">
                                    <span class="dashicons dashicons-database-export"></span> <?php esc_html_e('Download Backups', 'amendor'); ?>
                                </a>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=amendor-change-history')); ?>"
                                    class="button button-secondary button-large amendor-action-button" id="history-button">
                                    <span class="dashicons dashicons-backup"></span> <?php esc_html_e('View History Log', 'amendor'); ?>
                                </a>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=amendor-debug-log')); ?>"
                                    class="button button-secondary button-large amendor-action-button" id="debug-log-button">
                                    <span class="dashicons dashicons-hammer"></span> <?php esc_html_e('View Debug Log', 'amendor'); ?>
                                </a>
                            </div>
                            <p class="description" style="margin-top: 10px; text-align: center;"><?php esc_html_e('Select items from results to enable Preview/Replace.', 'amendor'); ?></p>
                        </div>
                    </div>

                    <!-- Info Panel -->
                    <div id="amendor-info-panel" class="postbox">
                        <h2 class="hndle"><span><?php esc_html_e('How It Works', 'amendor'); ?></span></h2>
                        <div class="inside">
                            <ol style="margin: 0; padding-left: 20px; list-style: decimal;">
                                <li><?php esc_html_e('Enter search/replace terms (or use Bulk Replace).', 'amendor'); ?></li>
                                <li><?php esc_html_e('Choose the content sources to scan, then optionally filter Elementor widget types.', 'amendor'); ?></li>
                                <li><?php /* translators: %s: Name of the button or action. */ printf(esc_html__('Click %s.', 'amendor'), '<strong>' . esc_html__('Search Only', 'amendor') . '</strong>'); ?></li>
                                <li><?php esc_html_e('Results appear below. Select posts to modify.', 'amendor'); ?></li>
                                <li><?php /* translators: %s: Name of the button or action. */ printf(esc_html__('Click %s for a dry run.', 'amendor'), '<strong>' . esc_html__('Preview Selected', 'amendor') . '</strong>'); ?></li>
                                <li><?php /* translators: %s: Name of the button or action. */ printf(esc_html__('Click %s to apply changes (backups created per post).', 'amendor'), '<strong>' . esc_html__('Replace Selected', 'amendor') . '</strong>'); ?></li>
                                <li><?php /* translators: %s: Name of the button or action. */ printf(esc_html__('Use %s to save all backups.', 'amendor'), '<strong>' . esc_html__('Download Backups', 'amendor') . '</strong>'); ?></li>
                                <li><?php /* translators: %s: Name of the button or action. */ printf(esc_html__('Use %s to track changes.', 'amendor'), '<strong>' . esc_html__('View History Log', 'amendor') . '</strong>'); ?></li>
                                <li><?php /* translators: %s: Name of the button or action. */ printf(esc_html__('Use %s (and enable logging on its page) for detailed troubleshooting.', 'amendor'), '<strong>' . esc_html__('View Debug Log', 'amendor') . '</strong>'); ?></li>
                                <li><?php esc_html_e('Restore individual posts from backups within the results list.', 'amendor'); ?></li>
                            </ol>
                        </div>
                    </div>
                </div> <?php // End amendor-sidebar 
                        ?>
            </div> <?php // End amendor-layout 
                    ?>
        </form> <?php // End main form 
                ?>
    </div> <?php // End wrap 
            ?>

<?php
} // End amendor_render_text_replacer_ui function
