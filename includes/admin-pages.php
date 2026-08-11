<?php

/**
 * Admin Page Rendering Functions
 *
 * @package Amendor
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
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
 * Get the requested debug log export format.
 *
 * @return string
 */
function amendor_get_debug_log_export_format()
{
    $allowed_formats = ['csv', 'json', 'txt'];
    $format = isset($_GET['export_format']) ? sanitize_key(wp_unslash($_GET['export_format'])) : 'csv';

    return in_array($format, $allowed_formats, true) ? $format : 'csv';
}

/**
 * Normalize a debug log row for export.
 *
 * @param array $row Database row.
 * @return array
 */
function amendor_prepare_debug_log_export_row(array $row)
{
    $context_data = null;
    $context_text = '';

    if (!empty($row['context'])) {
        $decoded_context = json_decode($row['context'], true);
        if (is_array($decoded_context)) {
            $context_data = $decoded_context;
            $context_text = wp_json_encode($decoded_context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $context_text = (string) $row['context'];
        }
    }

    return [
        'timestamp' => wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i:s'), strtotime($row['timestamp'])),
        'level' => (string) $row['log_level'],
        'message' => (string) $row['message'],
        'context_text' => $context_text,
        'context_data' => $context_data,
    ];
}

/**
 * Send the debug log export response.
 *
 * @param array  $rows Export rows.
 * @param string $format Export format.
 * @param string $filename_suffix Filename suffix.
 * @return void
 */
function amendor_send_debug_log_export(array $rows, $format, $filename_suffix)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $timestamp = gmdate('Y-m-d-His');
    nocache_headers();

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="amendor-debug-log-' . $filename_suffix . '-' . $timestamp . '.json"');

        $payload = [];
        foreach ($rows as $row) {
            $export_row = amendor_prepare_debug_log_export_row($row);
            $payload[] = [
                'timestamp' => $export_row['timestamp'],
                'level' => $export_row['level'],
                'message' => $export_row['message'],
                'context' => $export_row['context_data'] !== null ? $export_row['context_data'] : $export_row['context_text'],
            ];
        }

        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($format === 'txt') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="amendor-debug-log-' . $filename_suffix . '-' . $timestamp . '.txt"');

        foreach ($rows as $row) {
            $export_row = amendor_prepare_debug_log_export_row($row);
            echo '[' . $export_row['timestamp'] . '] ' . $export_row['level'] . ': ' . $export_row['message'] . "\n";
            if ($export_row['context_text'] !== '') {
                echo 'Context: ' . $export_row['context_text'] . "\n";
            }
            echo "\n";
        }

        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="amendor-debug-log-' . $filename_suffix . '-' . $timestamp . '.csv"');

    $output = fopen('php://output', 'w');
    if ($output !== false) {
        fputcsv($output, [
            __('Timestamp (Site Time)', 'amendor'),
            __('Level', 'amendor'),
            __('Message', 'amendor'),
            __('Context', 'amendor'),
        ]);

        foreach ($rows as $row) {
            $export_row = amendor_prepare_debug_log_export_row($row);
            fputcsv($output, [
                $export_row['timestamp'],
                $export_row['level'],
                $export_row['message'],
                $export_row['context_text'],
            ]);
        }

        fclose($output);
    }

    exit;
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
        'confirm_replace_text' => __('You are about to replace text in %d selected post(s).', 'amendor'), // %d will be replaced by JS
        'confirm_replace_backup_notice' => __('A backup snapshot of the selected post content will be created for each modified post BEFORE changes are saved.', 'amendor'),
        'confirm_replace_warning' => __('This action cannot be easily undone, except by restoring from a backup.', 'amendor'),
        'confirm_replace_proceed' => __('Are you sure you want to proceed?', 'amendor'),
        'confirm_replace_large_batch_warning' => __('WARNING: You have selected a large number of posts (%d). This operation might take some time. Ensure your server\'s maximum execution time is set sufficiently high.', 'amendor'), // %d will be replaced
        'alert_select_items' => __('Please select at least one item from the results to replace.', 'amendor'),
        'confirm_restore_title' => __('Confirm Restore', 'amendor'),
        'confirm_restore_text' => __('Are you sure you want to restore Post ID %d from this backup? This will overwrite the saved content fields for this post.', 'amendor'), // %d will be replaced
        'confirm_clear_log_title' => __('Confirm Clear Log', 'amendor'),
        'confirm_clear_log_text' => __('Are you sure you want to clear the ENTIRE debug log? This cannot be undone.', 'amendor'),
        'backup_selection_warning' => __('Some selected posts do not have an existing plugin backup yet. A fresh backup will be created automatically before replacement.', 'amendor'),
        'backup_selection_safe' => __('All selected posts already have at least one saved plugin backup.', 'amendor'),
        'search_batch_nonce' => wp_create_nonce('amendor_run_search_batch'),
        'search_results_nonce' => wp_create_nonce('amendor_get_search_results'),
        'search_progress_label' => __('Scanning selected content sources...', 'amendor'),
        'search_progress_done' => __('Search scan complete. Loading results...', 'amendor'),
        'search_progress_error' => __('Search scan failed. Falling back to standard form submission.', 'amendor'),
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
 * Render a single result item.
 *
 * @param array $item Result item data.
 * @param bool  $is_preview Whether this is preview output.
 * @param array $selected_ids Selected post IDs.
 * @return void
 */
function amendor_render_results_item(array $item, $is_preview, array $selected_ids)
{
    $post_status = get_post_status($item['ID']);
    $post_status_object = $post_status ? get_post_status_object($post_status) : null;
    $post_status_label = $post_status_object && !empty($post_status_object->label)
        ? $post_status_object->label
        : ucfirst((string) $post_status);
    ?>
    <div class="amendor-preview-item" data-type="<?php echo esc_attr($item['type']); ?>" data-backup-count="<?php echo esc_attr(isset($item['backup_count']) ? intval($item['backup_count']) : amendor_get_post_backup_count($item['ID'])); ?>">
        <div class="amendor-item-header hndle">
            <div class="result-left">
                <input type="checkbox" name="selected_posts[]" value="<?php echo esc_attr($item['ID']); ?>" class="amendor-result-checkbox" <?php checked(in_array($item['ID'], $selected_ids, true)); ?>>
                <strong class="amendor-item-title"><?php echo esc_html($item['title']); ?></strong>
                <span class="amendor-post-type">(<?php echo esc_html(ucfirst(str_replace(['-', '_'], ' ', $item['type']))); ?>)</span>
                <?php if ($post_status_label !== ''): ?>
                    <span class="amendor-post-status amendor-post-status-<?php echo esc_attr(sanitize_html_class($post_status)); ?>">
                        <?php echo esc_html($post_status_label); ?>
                    </span>
                <?php endif; ?>
            </div>
            <span class="amendor-item-actions">
                <?php
                $edit_link = get_edit_post_link($item['ID']);
                $view_link = get_permalink($item['ID']);
                $elementor_edit_link = $edit_link ? add_query_arg(['action' => 'elementor'], $edit_link) : false;
                ?>
                <?php if ($view_link): ?><a href="<?php echo esc_url($view_link); ?>" target="_blank" class="button button-small view-link" title="<?php esc_attr_e('View Post (Opens in new tab)', 'amendor'); ?>"><?php esc_html_e('View', 'amendor'); ?></a><?php endif; ?>
                <?php if ($edit_link): ?><a href="<?php echo esc_url($edit_link); ?>" target="_blank" class="button button-small edit-wp-link" title="<?php esc_attr_e('Edit in WordPress Editor (Opens in new tab)', 'amendor'); ?>"><?php esc_html_e('Edit WP', 'amendor'); ?></a><?php endif; ?>
                <?php if ($elementor_edit_link && class_exists('Elementor\Plugin')): ?><a href="<?php echo esc_url($elementor_edit_link); ?>" target="_blank" class="button button-small edit-elementor-link" title="<?php esc_attr_e('Edit with Elementor (Opens in new tab)', 'amendor'); ?>"><?php esc_html_e('Edit Elementor', 'amendor'); ?></a><?php endif; ?>
            </span>
        </div>
        <div class="amendor-item-content">
            <div class="amendor-diffs">
                <?php if (empty($item['matches'])): ?>
                    <p><em><?php echo $is_preview ? esc_html__('No changes predicted in this item for the current criteria.', 'amendor') : esc_html__('No changes found in this item for the current criteria.', 'amendor'); ?></em></p>
                <?php else: ?>
                    <?php $match_count = count($item['matches']); ?>
                    <p class="match-count-info"><em><?php
                                                    printf(
                                                        /* translators: %s: Number of matches shown */
                                                        esc_html__('Showing up to %s example matches for this post.', 'amendor'),
                                                        esc_html(number_format_i18n($match_count))
                                                    );
                                                    ?></em></p>
                    <?php foreach ($item['matches'] as $index => $match): ?>
                        <div class="match-block" data-match-index="<?php echo esc_attr($index); ?>" style="<?php echo $index >= 10 ? 'display: none;' : ''; ?>">
                            <?php echo wp_kses_post(amendor_render_match_block($match)); ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if (!$is_preview): ?>
                <div class="amendor-restore-section">
                    <?php $backups = amendor_get_elementor_backups($item['ID']); ?>
                    <?php if (!empty($backups)): ?>
                        <form method="post" class="amendor-restore-form" style="margin-top: 15px; padding-top: 10px; border-top: 1px dashed #ddd;">
                            <?php wp_nonce_field('amendor_restore_action_' . $item['ID'], 'amendor_restore_nonce'); ?>
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="restore_post_id" value="<?php echo esc_attr($item['ID']); ?>">
                            <label for="backup_index_<?php echo esc_attr($item['ID']); ?>" style="margin-right: 5px;"><?php esc_html_e('Restore from:', 'amendor'); ?></label>
                            <select name="backup_index" id="backup_index_<?php echo esc_attr($item['ID']); ?>">
                                <?php foreach ($backups as $index => $backup): ?>
                                    <option value="<?php echo esc_attr($index); ?>">
                                        <?php
                                        printf(
                                            /* translators: 1: Date and Time, 2: Human readable time difference */
                                            esc_html__('Backup from %1$s (%2$s ago)', 'amendor'),
                                            esc_html(wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i:s'), strtotime($backup['timestamp']))),
                                            esc_html(human_time_diff(strtotime($backup['timestamp']), current_time('timestamp', 1)))
                                        );
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button-secondary button-small restore-button"
                                data-post-id="<?php echo esc_attr($item['ID']); ?>"
                                onclick="return confirm(amendor_admin_vars.confirm_restore_text.replace('%d', this.getAttribute('data-post-id')));">
                                <span class="dashicons dashicons-undo"></span> <?php esc_html_e('Restore', 'amendor'); ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <p style="margin-top: 15px; padding-top: 10px; border-top: 1px dashed #ddd; font-style: italic; color: #666;"><?php esc_html_e('No backups available for this post.', 'amendor'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php
}

/**
 * Render the results section.
 *
 * @param array $args Results section state.
 * @return void
 */
function amendor_render_results_section(array $args)
{
    $preview_attempted = !empty($args['preview_attempted']);
    $search_attempted = !empty($args['search_attempted']);
    $preview_results = isset($args['preview_results']) && is_array($args['preview_results']) ? $args['preview_results'] : [];
    $results = isset($args['results']) && is_array($args['results']) ? $args['results'] : [];
    $selected_ids = isset($args['selected_ids']) && is_array($args['selected_ids']) ? $args['selected_ids'] : [];
    $action = isset($args['action']) ? (string) $args['action'] : '';
    $paged = isset($args['paged']) ? (int) $args['paged'] : 1;
    $total_pages = isset($args['total_pages']) ? (int) $args['total_pages'] : 0;
    $matched_posts = isset($args['matched_posts']) ? (int) $args['matched_posts'] : 0;
    $total_candidate_posts = isset($args['total_candidate_posts']) ? (int) $args['total_candidate_posts'] : 0;
    $results_per_page = isset($args['results_per_page']) ? amendor_get_search_results_per_page($args['results_per_page']) : amendor_get_search_results_per_page();
    $content_sources = isset($args['content_sources']) && is_array($args['content_sources']) ? amendor_normalize_content_sources($args['content_sources']) : amendor_get_default_content_sources();
    $content_source_summary = amendor_format_content_sources_summary($content_sources);

    $items_to_display = !empty($preview_results) ? $preview_results : $results;
    $is_preview = !empty($preview_results);
    $has_results_or_preview = !empty($items_to_display);
?>
    <div class="amendor-section postbox" id="amendor-results-panel">
        <h2 class="hndle"><span><?php esc_html_e('4. Results', 'amendor'); ?> <?php
                                                                                if ($preview_attempted) {
                                                                                    echo '(' . esc_html__('Preview', 'amendor') . ')';
                                                                                } elseif ($search_attempted) {
                                                                                    echo '(' . esc_html__('Search Results', 'amendor') . ')';
                                                                                }
                                                                                ?></span></h2>
        <div class="inside">
            <?php if (!$search_attempted && !$preview_attempted): ?>
                <p><?php esc_html_e('Perform a search or preview to see results here.', 'amendor'); ?></p>
            <?php elseif (!$has_results_or_preview): ?>
                <p><?php printf(esc_html__('No matches found for your %s criteria.', 'amendor'), '<strong>' . esc_html($action) . '</strong>'); ?></p>
            <?php else: ?>
                <?php $result_types = array_unique(wp_list_pluck($items_to_display, 'type')); ?>
                <?php if ($is_preview): ?>
                    <div class="notice notice-info inline" style="margin-bottom: 15px;">
                        <p><strong><?php esc_html_e('Preview Mode:', 'amendor'); ?></strong> <?php esc_html_e('Showing potential changes for selected items. No changes have been saved yet.', 'amendor'); ?> <?php printf(esc_html__('Active content sources: %s.', 'amendor'), '<strong>' . esc_html($content_source_summary) . '</strong>'); ?></p>
                    </div>
                <?php else: ?>
                    <p><?php
                        printf(
                            /* translators: 1: Count of matched posts on the current page, 2: Current page number, 3: Total pages, 4: Total matched posts, 5: Total scanned candidate posts, 6: Source summary */
                            esc_html__('Found %1$s matched post(s) on this page (Page %2$s of %3$s). Total matched posts across the full scan: %4$s. Candidate posts scanned: %5$s. Active content sources: %6$s.', 'amendor'),
                            '<strong>' . number_format_i18n(count($items_to_display)) . '</strong>',
                            '<strong>' . number_format_i18n($paged) . '</strong>',
                            '<strong>' . number_format_i18n($total_pages) . '</strong>',
                            '<strong>' . number_format_i18n($matched_posts) . '</strong>',
                            '<strong>' . number_format_i18n($total_candidate_posts) . '</strong>',
                            '<strong>' . esc_html($content_source_summary) . '</strong>'
                        );
                        ?></p>
                    <p><?php esc_html_e('Select posts below to Preview or Replace.', 'amendor'); ?></p>
                <?php endif; ?>

                <div class="amendor-results-toolbar">
                    <div class="result-toolbar-item">
                        <label><input type="checkbox" id="select-all-results"> <?php esc_html_e('Select All / None', 'amendor'); ?></label>
                    </div>
                    <div class="result-toolbar-item">
                        <label for="filter-type"><?php esc_html_e('Filter by Type:', 'amendor'); ?></label>
                        <select id="filter-type">
                            <option value=""><?php esc_html_e('All Types', 'amendor'); ?></option>
                            <?php foreach ($result_types as $type): ?>
                                <option value="<?php echo esc_attr($type); ?>">
                                    <?php echo esc_html(ucfirst(str_replace(['-', '_'], ' ', $type))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (!$is_preview): ?>
                        <div class="result-toolbar-item">
                            <label for="results-per-page"><?php esc_html_e('Posts Per Page:', 'amendor'); ?></label>
                            <select id="results-per-page">
                                <?php foreach (apply_filters('amendor_search_posts_per_page_options', [10, 25, 50, 100]) as $option): ?>
                                    <?php $option = (int) $option; ?>
                                    <option value="<?php echo esc_attr($option); ?>" <?php selected($results_per_page, $option); ?>>
                                        <?php echo esc_html(number_format_i18n($option)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="amendor-accordion">
                    <?php foreach ($items_to_display as $item): ?>
                        <?php amendor_render_results_item($item, $is_preview, $selected_ids); ?>
                    <?php endforeach; ?>
                </div>

                <?php if (!$is_preview && $total_pages > 1): ?>
                    <div class="tablenav bottom amendor-results-pagination">
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php printf(esc_html(_n('%s matched item', '%s matched items', $matched_posts, 'amendor')), number_format_i18n($matched_posts)); ?>
                            </span>
                            <?php
                            $pagination_base_url = add_query_arg('paged', '%#%', admin_url('admin.php?page=' . amendor_get_admin_parent_slug()));
                            echo paginate_links([
                                'base' => $pagination_base_url,
                                'format' => '',
                                'prev_text' => __('&laquo; Prev', 'amendor'),
                                'next_text' => __('Next &raquo;', 'amendor'),
                                'total' => $total_pages,
                                'current' => $paged,
                                'add_args' => ['results_per_page' => $results_per_page],
                            ]);
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
<?php
}

/**
 * Get the results section HTML.
 *
 * @param array $args Results section state.
 * @return string
 */
function amendor_get_results_section_html(array $args)
{
    ob_start();
    amendor_render_results_section($args);
    return ob_get_clean();
}


/**
 * Displays the persistent debug log page content.
 */
function amendor_display_debug_log_page()
{
    global $wpdb;
    $table_name = amendor_get_debug_log_table_name();

    // Security check
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'amendor'));
    }

    // --- Handle Clear Log Action ---
    if (isset($_POST['amendor_action']) && $_POST['amendor_action'] === 'clear_debug_log' && isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'amendor_clear_debug_log_nonce')) {
        $wpdb->query("TRUNCATE TABLE {$table_name}");
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Debug log cleared successfully.', 'amendor') . '</p></div>';
    }

    // --- Pagination Logic ---
    $current_page = isset($_GET['debug_paged']) ? absint($_GET['debug_paged']) : 1;
    $per_page = apply_filters('amendor_debug_log_items_per_page', 100); // Allow filtering items per page
    $offset = ($current_page - 1) * $per_page;

    // --- Filtering Logic ---
    $allowed_levels = ['DEBUG', 'INFO', 'WARN', 'ERROR', 'CRITICAL'];
    $selected_level = isset($_GET['log_level']) && in_array(strtoupper(sanitize_key($_GET['log_level'])), $allowed_levels) ? strtoupper(sanitize_key($_GET['log_level'])) : '';

    if (
        isset($_GET['amendor_action'], $_GET['_wpnonce']) &&
        $_GET['amendor_action'] === 'export_debug_log' &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'amendor_export_debug_log')
    ) {
        $export_format = amendor_get_debug_log_export_format();
        $export_where_clause = '';
        $export_params = [];

        if (!empty($selected_level)) {
            $export_where_clause = ' WHERE log_level = %s';
            $export_params[] = $selected_level;
        }

        $export_query = "SELECT timestamp, log_level, message, context FROM {$table_name}" . $export_where_clause . " ORDER BY timestamp DESC";
        if (!empty($export_params)) {
            $export_query = $wpdb->prepare($export_query, ...$export_params);
        }

        $export_rows = $wpdb->get_results($export_query, ARRAY_A);
        $filename_suffix = !empty($selected_level) ? strtolower($selected_level) : 'all-levels';
        amendor_send_debug_log_export($export_rows, $export_format, $filename_suffix);
    }

    // --- Build Query ---
    $where_clause = '';
    if (!empty($selected_level)) {
        $where_clause = $wpdb->prepare(" WHERE log_level = %s", $selected_level);
    }

    // Get total items for pagination (considering filter)
    $total_items = $wpdb->get_var("SELECT COUNT(id) FROM {$table_name}" . $where_clause);
    $total_pages = ceil($total_items / $per_page);

    // Get log items for the current page (considering filter)
    $log_items = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_name}" . $where_clause . " ORDER BY timestamp DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ));

?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-hammer" style="vertical-align: middle;"></span> <?php esc_html_e('Amendor - Debug Log', 'amendor'); ?></h1>
        <p><?php esc_html_e('This log records detailed plugin operations if persistent logging is enabled.', 'amendor'); ?></p>

        <?php // --- Log Control Form (Enable/Disable, Clear) --- 
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>" style="margin-bottom: 20px;padding: 16px;background: #f9f9f9;border: 1px solid #ccd0d4;display: flex;flex-direction: row;flex-wrap: nowrap;justify-content: space-between;align-items: center;">
            <?php settings_fields('amendor_debug_settings'); // Use a settings group 
            ?>
            <div style="display: flex; flex-direction: column; gap: 8px;">
                <label>
                    <input type="checkbox" name="amendor_enable_persistent_debug_log" value="1" <?php checked(get_option('amendor_enable_persistent_debug_log', false), 1); ?>>
                    <?php esc_html_e('Enable Persistent Debug Logging (Logs detailed actions to the database table below)', 'amendor'); ?>
                </label>
                <label>
                    <input type="checkbox" name="amendor_delete_data_on_uninstall" value="1" <?php checked(get_option('amendor_delete_data_on_uninstall', false), 1); ?>>
                    <?php esc_html_e('Delete plugin data on uninstall (history, debug log, backups, and per-user search history)', 'amendor'); ?>
                </label>
                <label for="amendor_debug_log_retention_limit">
                    <?php esc_html_e('Max debug log rows to retain', 'amendor'); ?>
                </label>
                <input
                    type="number"
                    min="1"
                    step="1"
                    id="amendor_debug_log_retention_limit"
                    name="amendor_debug_log_retention_limit"
                    value="<?php echo esc_attr((int) get_option('amendor_debug_log_retention_limit', amendor_get_default_debug_log_retention_setting())); ?>"
                    style="max-width: 160px;">
                <label for="amendor_history_log_retention_limit">
                    <?php esc_html_e('Max history log rows to retain', 'amendor'); ?>
                </label>
                <input
                    type="number"
                    min="1"
                    step="1"
                    id="amendor_history_log_retention_limit"
                    name="amendor_history_log_retention_limit"
                    value="<?php echo esc_attr((int) get_option('amendor_history_log_retention_limit', amendor_get_default_history_log_retention_setting())); ?>"
                    style="max-width: 160px;">
            </div>
            <?php submit_button(__('Save Setting', 'amendor'), 'primary', 'submit_settings', false); // Add save button for the setting 
            ?>
        </form>

        <div class="debug_log__options">
            <form method="post" style="display: inline-block; margin-right: 10px;">
                <input type="hidden" name="amendor_action" value="clear_debug_log">
                <?php wp_nonce_field('amendor_clear_debug_log_nonce'); ?>
                <?php
                $clear_disabled = ($total_items == 0); // Disable if log is empty
                ?>
                <button type="submit" class="button button-secondary" <?php disabled($clear_disabled); ?> onclick="return confirm(amendor_admin_vars.confirm_clear_log_text);" style="display: flex; align-items: center; gap: 8px;">
                    <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Clear Entire Log', 'amendor'); ?>
                </button>
            </form>

            <a
                href="<?php echo esc_url(wp_nonce_url(add_query_arg([
                            'page' => 'amendor-debug-log',
                            'amendor_action' => 'export_debug_log',
                            'export_format' => 'csv',
                            'log_level' => $selected_level,
                        ], admin_url('admin.php')), 'amendor_export_debug_log')); ?>"
                class="button button-secondary"
                style="display: inline-flex; align-items: center; gap: 8px; margin-right: 10px;">
                <span class="dashicons dashicons-download"></span> <?php esc_html_e('Export Log CSV', 'amendor'); ?>
            </a>

            <a
                href="<?php echo esc_url(wp_nonce_url(add_query_arg([
                            'page' => 'amendor-debug-log',
                            'amendor_action' => 'export_debug_log',
                            'export_format' => 'json',
                            'log_level' => $selected_level,
                        ], admin_url('admin.php')), 'amendor_export_debug_log')); ?>"
                class="button button-secondary"
                style="display: inline-flex; align-items: center; gap: 8px; margin-right: 10px;">
                <span class="dashicons dashicons-media-code"></span> <?php esc_html_e('Export Log JSON', 'amendor'); ?>
            </a>

            <a
                href="<?php echo esc_url(wp_nonce_url(add_query_arg([
                            'page' => 'amendor-debug-log',
                            'amendor_action' => 'export_debug_log',
                            'export_format' => 'txt',
                            'log_level' => $selected_level,
                        ], admin_url('admin.php')), 'amendor_export_debug_log')); ?>"
                class="button button-secondary"
                style="display: inline-flex; align-items: center; gap: 8px; margin-right: 10px;">
                <span class="dashicons dashicons-media-text"></span> <?php esc_html_e('Export Log TXT', 'amendor'); ?>
            </a>

            <?php // --- Log Level Filter --- 
            ?>
            <form method="get" style="display: inline-block;">
                <input type="hidden" name="page" value="amendor-debug-log"> <?php // Keep page context 
                                                                            ?>
                <label for="log_level_filter"><?php esc_html_e('Filter by Level:', 'amendor'); ?></label>
                <select name="log_level" id="log_level_filter">
                    <option value="" <?php selected($selected_level, ''); ?>><?php esc_html_e('All Levels', 'amendor'); ?></option>
                    <?php foreach ($allowed_levels as $level): ?>
                        <option value="<?php echo esc_attr($level); ?>" <?php selected($selected_level, $level); ?>><?php echo esc_html($level); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button button-secondary"><?php esc_html_e('Filter', 'amendor'); ?></button>
                <?php if (!empty($selected_level)): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=amendor-debug-log')); ?>" class="button button-secondary"><?php esc_html_e('Clear Filter', 'amendor'); ?></a>
                <?php endif; ?>
            </form>
        </div>

        <?php // --- Top Pagination --- 
        ?>
        <?php if ($total_pages > 1): ?>
            <div class="tablenav top">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php printf(esc_html(_n('%s item', '%s items', $total_items, 'amendor')), number_format_i18n($total_items)); ?>
                    </span>
                    <?php
                    // Add log_level to pagination links if filter is active
                    $paginate_base = add_query_arg('debug_paged', '%#%');
                    if (!empty($selected_level)) {
                        $paginate_base = add_query_arg('log_level', $selected_level, $paginate_base);
                    }
                    echo paginate_links([
                        'base'      => $paginate_base,
                        'format'    => '',
                        'prev_text' => __('&laquo;', 'amendor'), // Use HTML entities for arrows
                        'next_text' => __('&raquo;', 'amendor'),
                        'total'     => $total_pages,
                        'current'   => $current_page,
                        'add_args'  => false,
                    ]);
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <?php // --- Debug Log Table --- 
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" style="width: 160px;"><?php esc_html_e('Timestamp (Site Time)', 'amendor'); ?></th>
                    <th scope="col" style="width: 100px;"><?php esc_html_e('Level', 'amendor'); ?></th>
                    <th scope="col"><?php esc_html_e('Message', 'amendor'); ?></th>
                    <th scope="col" style="width: 25%;"><?php esc_html_e('Context', 'amendor'); ?></th>
                </tr>
            </thead>
            <tbody id="the-list">
                <?php if (empty($log_items)): ?>
                    <tr class="no-items">
                        <td class="colspanchange" colspan="4">
                            <?php
                            if (!empty($selected_level)) {
                                printf(esc_html__('No debug log records found for level %s.', 'amendor'), '<strong>' . esc_html($selected_level) . '</strong>');
                            } else {
                                esc_html_e('No debug log records found.', 'amendor');
                            }
                            if (!get_option('amendor_enable_persistent_debug_log', false)) {
                                echo ' ' . esc_html__('Logging is currently disabled.', 'amendor');
                            }
                            ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($log_items as $item): ?>
                        <tr>
                            <td><?php echo esc_html(wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i:s'), strtotime($item->timestamp))); ?></td>
                            <td>
                                <span class="log-level-badge log-level-<?php echo esc_attr(strtolower($item->log_level)); ?>">
                                    <?php echo esc_html($item->log_level); ?>
                                </span>
                            </td>
                            <td><?php echo nl2br(esc_html($item->message)); ?></td>
                            <td>
                                <?php if (!empty($item->context)): ?>
                                    <pre style="white-space: pre-wrap; word-wrap: break-word; font-size: 11px; max-height: 100px; overflow-y: auto; background: #f0f0f0; padding: 5px; border: 1px solid #ddd;"><?php
                                                                                                                                                                                                                // Attempt to decode and pretty print JSON context
                                                                                                                                                                                                                $decoded_context = json_decode($item->context);
                                                                                                                                                                                                                if (json_last_error() === JSON_ERROR_NONE) {
                                                                                                                                                                                                                    echo esc_html(wp_json_encode($decoded_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                                                                                                                                                                                                                } else {
                                                                                                                                                                                                                    // If not valid JSON, just display the raw string
                                                                                                                                                                                                                    echo esc_html($item->context);
                                                                                                                                                                                                                }
                                                                                                                                                                                                                ?></pre>
                                <?php else: ?>
                                    <em><?php esc_html_e('N/A', 'amendor'); ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th scope="col"><?php esc_html_e('Timestamp (Site Time)', 'amendor'); ?></th>
                    <th scope="col"><?php esc_html_e('Level', 'amendor'); ?></th>
                    <th scope="col"><?php esc_html_e('Message', 'amendor'); ?></th>
                    <th scope="col"><?php esc_html_e('Context', 'amendor'); ?></th>
                </tr>
            </tfoot>
        </table>

        <?php // --- Bottom Pagination --- 
        ?>
        <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php printf(esc_html(_n('%s item', '%s items', $total_items, 'amendor')), number_format_i18n($total_items)); ?>
                    </span>
                    <?php
                    echo paginate_links([
                        'base'      => $paginate_base, // Use the same base as top pagination
                        'format'    => '',
                        'prev_text' => __('&laquo;', 'amendor'),
                        'next_text' => __('&raquo;', 'amendor'),
                        'total'     => $total_pages,
                        'current'   => $current_page,
                        'add_args'  => false,
                    ]);
                    ?>
                </div>
            </div>
        <?php endif; ?>

    </div><!-- /.wrap -->
    <style>
        /* Add some basic styling for log level badges */
        .log-level-badge {
            display: inline-block;
            padding: 2px 6px;
            font-size: 10px;
            font-weight: bold;
            border-radius: 3px;
            color: #fff;
            text-transform: uppercase;
        }

        .log-level-debug {
            background-color: #6c757d;
        }

        .log-level-info {
            background-color: #17a2b8;
        }

        .log-level-warn {
            background-color: #ffc107;
            color: #333;
        }

        .log-level-error {
            background-color: #dc3545;
        }

        .log-level-critical {
            background-color: #bd2130;
        }

        tr.log-level-warn {
            background-color: #fff3cd !important;
        }

        /* Use important to override WP styles */
        tr.log-level-error,
        tr.log-level-critical {
            background-color: #f8d7da !important;
        }

        .debug_log__options {
            display: flex;
            flex-direction: row;
            flex-wrap: nowrap;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }
    </style>
<?php
} // End amendor_display_debug_log_page


/**
 * Displays the change history log page content.
 */
function amendor_display_change_history_log()
{
    global $wpdb;
    $table_name = amendor_get_history_table_name();

    // Security check: Only users who can manage options should see this
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'amendor'));
    }

    // --- Pagination Logic ---
    $current_page = isset($_GET['history_paged']) ? absint($_GET['history_paged']) : 1;
    $per_page = apply_filters('amendor_history_items_per_page', 25);
    $offset = ($current_page - 1) * $per_page;

    // Get total number of history items for pagination calculation
    $total_items = $wpdb->get_var("SELECT COUNT(id) FROM {$table_name}");
    $total_pages = ceil($total_items / $per_page);

    // Get the history items for the current page
    $history_items = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_name} ORDER BY timestamp DESC LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ));

?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-backup" style="vertical-align: middle;"></span> <?php esc_html_e('Amendor - Change History Log', 'amendor'); ?></h1>
        <p><?php esc_html_e('This log records all successful replacement operations performed by the plugin.', 'amendor'); ?></p>

        <?php // --- Top Pagination --- 
        ?>
        <?php if ($total_pages > 1): ?>
            <div class="tablenav top">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php printf(esc_html(_n('%s item', '%s items', $total_items, 'amendor')), number_format_i18n($total_items)); ?>
                    </span>
                    <?php
                    echo paginate_links([
                        'base'      => add_query_arg('history_paged', '%#%'), // '%#%' will be replaced by page number
                        'format'    => '', // Already have query var in base
                        'prev_text' => __('&laquo;', 'amendor'),
                        'next_text' => __('&raquo;', 'amendor'),
                        'total'     => $total_pages,
                        'current'   => $current_page,
                        'add_args'  => false, // Base includes everything needed (?page=amendor-change-history)
                    ]);
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <?php // --- History Table --- 
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" style="width: 160px;"><?php esc_html_e('Timestamp (Site Time)', 'amendor'); ?></th>
                    <th scope="col" style="width: 120px;"><?php esc_html_e('User', 'amendor'); ?></th>
                    <th scope="col"><?php esc_html_e('Post', 'amendor'); ?></th>
                    <th scope="col"><?php esc_html_e('Search Term', 'amendor'); ?></th>
                    <th scope="col"><?php esc_html_e('Replace Term', 'amendor'); ?></th>
                    <th scope="col" style="width: 100px;"><?php esc_html_e('Mode', 'amendor'); ?></th>
                    <th scope="col" style="width: 80px;"><?php esc_html_e('Changes', 'amendor'); ?></th>
                </tr>
            </thead>
            <tbody id="the-list">
                <?php if (empty($history_items)): ?>
                    <tr class="no-items">
                        <td class="colspanchange" colspan="7"><?php esc_html_e('No history records found.', 'amendor'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($history_items as $item): ?>
                        <?php $user_info = get_userdata($item->user_id); ?>
                        <tr>
                            <td><?php echo esc_html(wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i:s'), strtotime($item->timestamp))); // Display in site's timezone 
                                ?></td>
                            <td><?php echo esc_html($user_info ? $user_info->user_login : sprintf(__('Unknown User (ID: %d)', 'amendor'), $item->user_id)); ?></td>
                            <td>
                                <?php $post_link = get_edit_post_link($item->post_id); ?>
                                <?php if ($post_link): ?>
                                    <a href="<?php echo esc_url($post_link); ?>" target="_blank" title="<?php printf(esc_attr__('Edit Post: %s', 'amendor'), $item->post_title); ?>">
                                        <?php echo esc_html(wp_trim_words($item->post_title, 10, '...')); ?> (ID: <?php echo esc_html($item->post_id); ?>) <span class="dashicons dashicons-external"></span>
                                    </a>
                                <?php else: ?>
                                    <?php echo esc_html(wp_trim_words($item->post_title, 10, '...')); ?> (ID: <?php echo esc_html($item->post_id); ?>) <span title="<?php esc_attr_e('Post may have been deleted', 'amendor'); ?>">(<?php esc_html_e('Deleted?', 'amendor'); ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td><code title="<?php echo esc_attr($item->search_term); ?>"><?php echo esc_html(wp_trim_words($item->search_term, 15, '...')); ?></code></td>
                            <td><code title="<?php echo esc_attr($item->replace_term); ?>"><?php echo esc_html(wp_trim_words($item->replace_term, 15, '...')); ?></code></td>
                            <td>
                                <?php echo esc_html(ucfirst($item->search_mode)); ?>
                                <?php if ($item->bulk_operation) echo ' <span title="' . esc_attr__('Bulk Operation', 'amendor') . '">(' . esc_html__('Bulk', 'amendor') . ')</span>'; ?>
                            </td>
                            <td style="text-align: center;"><?php echo esc_html($item->changes_made); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th scope="col"><?php esc_html_e('Timestamp (Site Time)', 'amendor'); ?></th>
                    <th scope="col"><?php esc_html_e('User', 'amendor'); ?></th>
                    <th scope="col"><?php esc_html_e('Post', 'amendor'); ?></th>
                    <th scope="col"><?php esc_html_e('Search Term', 'amendor'); ?></th>
                    <th scope="col"><?php esc_html_e('Replace Term', 'amendor'); ?></th>
                    <th scope="col"><?php esc_html_e('Mode', 'amendor'); ?></th>
                    <th scope="col"><?php esc_html_e('Changes', 'amendor'); ?></th>
                </tr>
            </tfoot>
        </table>

        <?php // --- Bottom Pagination --- 
        ?>
        <?php if ($total_pages > 1): ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php printf(esc_html(_n('%s item', '%s items', $total_items, 'amendor')), number_format_i18n($total_items)); ?>
                    </span>
                    <?php
                    echo paginate_links([
                        'base'      => add_query_arg('history_paged', '%#%'),
                        'format'    => '',
                        'prev_text' => __('&laquo;', 'amendor'),
                        'next_text' => __('&raquo;', 'amendor'),
                        'total'     => $total_pages,
                        'current'   => $current_page,
                        'add_args'  => false,
                    ]);
                    ?>
                </div>
            </div>
        <?php endif; ?>

    </div><!-- /.wrap -->
<?php
} // End amendor_display_change_history_log


/**
 * Renders the main Elementor Text Replacer admin page UI.
 */
function amendor_render_text_replacer_ui()
{
    // Security Check: Ensure the user has the required capability.
    if (!current_user_can('manage_options')) {
        wp_die(__('Sorry, you are not allowed to access this page.', 'amendor'));
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
                                </div>
                                <button type="submit" name="action" value="preview_selected" class="button button-secondary button-large amendor-action-button" id="preview-button" disabled>
                                    <span class="dashicons dashicons-visibility"></span> <?php esc_html_e('Preview Selected', 'amendor'); ?>
                                </button>
                                <button type="submit" name="action" value="replace_selected" class="button button-primary button-large amendor-action-button" id="replace-button" disabled onclick="return confirmReplaceAction();">
                                    <span class="dashicons dashicons-yes"></span> <?php esc_html_e('Replace Selected', 'amendor'); ?>
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
                                <li><?php printf(esc_html__('Click %s.', 'amendor'), '<strong>' . esc_html__('Search Only', 'amendor') . '</strong>'); ?></li>
                                <li><?php esc_html_e('Results appear below. Select posts to modify.', 'amendor'); ?></li>
                                <li><?php printf(esc_html__('Click %s for a dry run.', 'amendor'), '<strong>' . esc_html__('Preview Selected', 'amendor') . '</strong>'); ?></li>
                                <li><?php printf(esc_html__('Click %s to apply changes (backups created per post).', 'amendor'), '<strong>' . esc_html__('Replace Selected', 'amendor') . '</strong>'); ?></li>
                                <li><?php printf(esc_html__('Use %s to save all backups.', 'amendor'), '<strong>' . esc_html__('Download Backups', 'amendor') . '</strong>'); ?></li>
                                <li><?php printf(esc_html__('Use %s to track changes.', 'amendor'), '<strong>' . esc_html__('View History Log', 'amendor') . '</strong>'); ?></li>
                                <li><?php printf(esc_html__('Use %s (and enable logging on its page) for detailed troubleshooting.', 'amendor'), '<strong>' . esc_html__('View Debug Log', 'amendor') . '</strong>'); ?></li>
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
