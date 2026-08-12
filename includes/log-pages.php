<?php

/**
 * Debug Log and History Log Admin Pages (with export helpers)
 *
 * @package Amendor
 */

if (!defined('ABSPATH')) {
    exit;
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

        $lines = [];
        foreach ($rows as $row) {
            $export_row = amendor_prepare_debug_log_export_row($row);
            $lines[] = '[' . $export_row['timestamp'] . '] ' . $export_row['level'] . ': ' . $export_row['message'];
            if ($export_row['context_text'] !== '') {
                $lines[] = 'Context: ' . $export_row['context_text'];
            }
            $lines[] = '';
        }

        // Raw text export: content must not be HTML-escaped.
        echo implode("\n", $lines); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($output);
    }

    exit;
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
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'amendor'));
    }

    // --- Handle Clear Log Action ---
    if (isset($_POST['amendor_action']) && $_POST['amendor_action'] === 'clear_debug_log' && isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'amendor_clear_debug_log_nonce')) {
        // Table name is plugin-owned and derived from $wpdb->prefix; gated by nonce + capability.
        $wpdb->query("TRUNCATE TABLE {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
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

        // Table name is plugin-owned; the optional level filter is prepared below.
        $export_query = "SELECT timestamp, log_level, message, context FROM {$table_name}" . $export_where_clause . " ORDER BY timestamp DESC"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        if (!empty($export_params)) {
            $export_query = $wpdb->prepare($export_query, ...$export_params);
        }

        $export_rows = $wpdb->get_results($export_query, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $filename_suffix = !empty($selected_level) ? strtolower($selected_level) : 'all-levels';
        amendor_send_debug_log_export($export_rows, $export_format, $filename_suffix);
    }

    // --- Build Query ---
    $where_clause = '';
    if (!empty($selected_level)) {
        $where_clause = $wpdb->prepare(" WHERE log_level = %s", $selected_level);
    }

    // Get total items for pagination (considering filter)
    $total_items = $wpdb->get_var("SELECT COUNT(id) FROM {$table_name}" . $where_clause); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $total_pages = ceil($total_items / $per_page);

    // Get log items for the current page (considering filter)
    $log_items = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_name}" . $where_clause . " ORDER BY timestamp DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
        $per_page,
        $offset
    )); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter

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
                        <?php
                        /* translators: %s: Number of log items. */
                        printf(esc_html(_n('%s item', '%s items', $total_items, 'amendor')), esc_html(number_format_i18n($total_items)));
                        ?>
                    </span>
                    <?php
                    // Add log_level to pagination links if filter is active
                    $paginate_base = add_query_arg('debug_paged', '%#%');
                    if (!empty($selected_level)) {
                        $paginate_base = add_query_arg('log_level', $selected_level, $paginate_base);
                    }
                    echo wp_kses_post(paginate_links([
                        'base'      => $paginate_base,
                        'format'    => '',
                        'prev_text' => __('&laquo;', 'amendor'), // Use HTML entities for arrows
                        'next_text' => __('&raquo;', 'amendor'),
                        'total'     => $total_pages,
                        'current'   => $current_page,
                        'add_args'  => false,
                    ]));
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
                                /* translators: %s: Debug log level (e.g. ERROR). */
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
                        <?php
                        /* translators: %s: Number of log items. */
                        printf(esc_html(_n('%s item', '%s items', $total_items, 'amendor')), esc_html(number_format_i18n($total_items)));
                        ?>
                    </span>
                    <?php
                    echo wp_kses_post(paginate_links([
                        'base'      => $paginate_base, // Use the same base as top pagination
                        'format'    => '',
                        'prev_text' => __('&laquo;', 'amendor'),
                        'next_text' => __('&raquo;', 'amendor'),
                        'total'     => $total_pages,
                        'current'   => $current_page,
                        'add_args'  => false,
                    ]));
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
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'amendor'));
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
                        <?php
                        /* translators: %s: Number of log items. */
                        printf(esc_html(_n('%s item', '%s items', $total_items, 'amendor')), esc_html(number_format_i18n($total_items)));
                        ?>
                    </span>
                    <?php
                    echo wp_kses_post(paginate_links([
                        'base'      => add_query_arg('history_paged', '%#%'), // '%#%' will be replaced by page number
                        'format'    => '', // Already have query var in base
                        'prev_text' => __('&laquo;', 'amendor'),
                        'next_text' => __('&raquo;', 'amendor'),
                        'total'     => $total_pages,
                        'current'   => $current_page,
                        'add_args'  => false, // Base includes everything needed (?page=amendor-change-history)
                    ]));
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
                            <td><?php
                                /* translators: %d: User ID. */
                                echo esc_html($user_info ? $user_info->user_login : sprintf(__('Unknown User (ID: %d)', 'amendor'), $item->user_id));
                                ?></td>
                            <td>
                                <?php $post_link = get_edit_post_link($item->post_id); ?>
                                <?php if ($post_link): ?>
                                    <a href="<?php echo esc_url($post_link); ?>" target="_blank" title="<?php
                                                                                                        /* translators: %s: Post title. */
                                                                                                        printf(esc_attr__('Edit Post: %s', 'amendor'), esc_attr($item->post_title));
                                                                                                        ?>">
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
                        <?php
                        /* translators: %s: Number of log items. */
                        printf(esc_html(_n('%s item', '%s items', $total_items, 'amendor')), esc_html(number_format_i18n($total_items)));
                        ?>
                    </span>
                    <?php
                    echo wp_kses_post(paginate_links([
                        'base'      => add_query_arg('history_paged', '%#%'),
                        'format'    => '',
                        'prev_text' => __('&laquo;', 'amendor'),
                        'next_text' => __('&raquo;', 'amendor'),
                        'total'     => $total_pages,
                        'current'   => $current_page,
                        'add_args'  => false,
                    ]));
                    ?>
                </div>
            </div>
        <?php endif; ?>

    </div><!-- /.wrap -->
<?php
} // End amendor_display_change_history_log


