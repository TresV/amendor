<?php

/**
 * Change History Log Admin Page and Export
 *
 * @package Amendor
 */

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Send the history log export response.
 *
 * @param array  $rows Export rows (history table ARRAY_A).
 * @param string $format Export format (csv, json, txt).
 * @param string $filename_suffix Filename suffix.
 * @return void
 */
function amendor_send_history_log_export(array $rows, $format, $filename_suffix)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $timestamp = gmdate('Y-m-d-His');
    nocache_headers();

    $export_rows = [];
    foreach ($rows as $row) {
        $user_info = get_userdata((int) ($row['user_id'] ?? 0));
        $export_rows[] = [
            'timestamp' => wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i:s'), strtotime((string) ($row['timestamp'] ?? ''))),
            'user' => $user_info ? $user_info->user_login : '',
            'post_id' => (int) ($row['post_id'] ?? 0),
            'post_title' => (string) ($row['post_title'] ?? ''),
            'search_term' => (string) ($row['search_term'] ?? ''),
            'replace_term' => (string) ($row['replace_term'] ?? ''),
            'search_mode' => (string) ($row['search_mode'] ?? ''),
            'bulk_operation' => !empty($row['bulk_operation']),
            'changes_made' => (int) ($row['changes_made'] ?? 0),
        ];
    }

    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="amendor-history-log-' . $filename_suffix . '-' . $timestamp . '.json"');
        echo wp_json_encode($export_rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($format === 'txt') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="amendor-history-log-' . $filename_suffix . '-' . $timestamp . '.txt"');
        $lines = [];
        foreach ($export_rows as $row) {
            $lines[] = sprintf(
                '[%s] %s | Post %d (%s) | %s -> %s | Mode: %s%s | Changes: %d',
                $row['timestamp'],
                $row['user'],
                $row['post_id'],
                $row['post_title'],
                $row['search_term'],
                $row['replace_term'],
                $row['search_mode'],
                $row['bulk_operation'] ? ' (Bulk)' : '',
                $row['changes_made']
            );
        }
        echo implode("\n", $lines); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="amendor-history-log-' . $filename_suffix . '-' . $timestamp . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel compatibility.
    $output = fopen('php://output', 'w');
    if ($output !== false) {
        fputcsv($output, [
            __('Timestamp (Site Time)', 'amendor'),
            __('User', 'amendor'),
            __('Post ID', 'amendor'),
            __('Post Title', 'amendor'),
            __('Search Term', 'amendor'),
            __('Replace Term', 'amendor'),
            __('Mode', 'amendor'),
            __('Bulk', 'amendor'),
            __('Changes', 'amendor'),
        ]);
        foreach ($export_rows as $row) {
            fputcsv($output, [
                $row['timestamp'],
                $row['user'],
                $row['post_id'],
                $row['post_title'],
                $row['search_term'],
                $row['replace_term'],
                $row['search_mode'],
                $row['bulk_operation'] ? '1' : '0',
                $row['changes_made'],
            ]);
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($output);
    }
    exit;
}

/**
 * Displays the change history log page content.
 */
function amendor_display_change_history_log()
{
    global $wpdb;
    $table_name = amendor_get_history_table_name();

    // Security check: Only users who can manage options should see this
    if (!amendor_current_user_can_manage()) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'amendor'));
    }

    // --- Filters ---
    $allowed_modes = ['partial', 'exact', 'regex'];
    $selected_mode = isset($_GET['history_mode']) && in_array(sanitize_key($_GET['history_mode']), $allowed_modes, true) ? sanitize_key($_GET['history_mode']) : '';
    $selected_bulk = isset($_GET['history_bulk']) ? sanitize_key($_GET['history_bulk']) : '';
    if (!in_array($selected_bulk, ['bulk', 'single'], true)) {
        $selected_bulk = '';
    }

    $where_sql = '';
    $where_params = [];
    if ($selected_mode !== '') {
        $where_sql .= ' WHERE search_mode = %s';
        $where_params[] = $selected_mode;
    }
    if ($selected_bulk === 'bulk') {
        $where_sql .= ($where_sql === '' ? ' WHERE' : ' AND') . ' bulk_operation = 1';
    } elseif ($selected_bulk === 'single') {
        $where_sql .= ($where_sql === '' ? ' WHERE' : ' AND') . ' bulk_operation = 0';
    }

    // --- Export Action (Pro) ---
    if (ame_fs()->is__premium_only()) {
        if (
            isset($_GET['amendor_action'], $_GET['_wpnonce']) &&
            $_GET['amendor_action'] === 'export_history_log' &&
            wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'amendor_export_history_log')
        ) {
            $history_format = isset($_GET['history_format']) && in_array(sanitize_key($_GET['history_format']), ['csv', 'json', 'txt'], true) ? sanitize_key($_GET['history_format']) : 'csv';
            $export_sql = "SELECT * FROM {$table_name}{$where_sql} ORDER BY timestamp DESC";
            $export_rows = $wpdb->get_results($wpdb->prepare($export_sql, ...$where_params), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $export_max_rows = max(1, (int) apply_filters('amendor_history_log_export_max_rows', 50000));
            $export_rows = array_slice((array) $export_rows, 0, $export_max_rows);
            $filename_suffix = $selected_mode !== '' ? $selected_mode : 'all';
            if ($selected_bulk !== '') {
                $filename_suffix .= '-' . $selected_bulk;
            }
            amendor_send_history_log_export($export_rows, $history_format, $filename_suffix);
        }
    }

    // --- Pagination Logic ---
    $current_page = isset($_GET['history_paged']) ? absint($_GET['history_paged']) : 1;
    $per_page = apply_filters('amendor_history_items_per_page', 25);
    $offset = ($current_page - 1) * $per_page;

    // Get total number of history items for pagination calculation (respecting filters)
    $count_sql = "SELECT COUNT(id) FROM {$table_name}{$where_sql}";
    $total_items = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$where_params)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $total_pages = ceil($total_items / $per_page);

    // Get the history items for the current page (respecting filters)
    $list_sql = "SELECT * FROM {$table_name}{$where_sql} ORDER BY timestamp DESC LIMIT %d OFFSET %d";
    $list_args = array_merge($where_params, [$per_page, $offset]);
    $history_items = $wpdb->get_results($wpdb->prepare($list_sql, ...$list_args)); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-backup" style="vertical-align: middle;"></span> <?php esc_html_e('Amendor - Change History Log', 'amendor'); ?></h1>
        <p><?php esc_html_e('This log records all successful replacement operations performed by the plugin.', 'amendor'); ?></p>

        <?php // --- Filter & Export Toolbar --- 
        ?>
        <div class="debug_log__options" style="margin-bottom: 20px;">
            <form method="get" style="display: inline-block; margin-right: 10px;">
                <input type="hidden" name="page" value="amendor-change-history">
                <label for="history_mode_filter"><?php esc_html_e('Mode:', 'amendor'); ?></label>
                <select name="history_mode" id="history_mode_filter">
                    <option value="" <?php selected($selected_mode, ''); ?>><?php esc_html_e('All Modes', 'amendor'); ?></option>
                    <?php foreach ($allowed_modes as $mode): ?>
                        <option value="<?php echo esc_attr($mode); ?>" <?php selected($selected_mode, $mode); ?>><?php echo esc_html(ucfirst($mode)); ?></option>
                    <?php endforeach; ?>
                </select>
                <label for="history_bulk_filter"><?php esc_html_e('Type:', 'amendor'); ?></label>
                <select name="history_bulk" id="history_bulk_filter">
                    <option value="" <?php selected($selected_bulk, ''); ?>><?php esc_html_e('All', 'amendor'); ?></option>
                    <option value="bulk" <?php selected($selected_bulk, 'bulk'); ?>><?php esc_html_e('Bulk', 'amendor'); ?></option>
                    <option value="single" <?php selected($selected_bulk, 'single'); ?>><?php esc_html_e('Single', 'amendor'); ?></option>
                </select>
                <button type="submit" class="button button-secondary"><?php esc_html_e('Filter', 'amendor'); ?></button>
                <?php if ($selected_mode !== '' || $selected_bulk !== ''): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=amendor-change-history')); ?>" class="button button-secondary"><?php esc_html_e('Clear Filter', 'amendor'); ?></a>
                <?php endif; ?>
            </form>

            <?php if (ame_fs()->is__premium_only()) { ?>
                <?php
                $export_base = [
                    'page' => 'amendor-change-history',
                    'amendor_action' => 'export_history_log',
                    'history_mode' => $selected_mode,
                    'history_bulk' => $selected_bulk,
                ];
                foreach (['csv' => __('Export CSV', 'amendor'), 'json' => __('Export JSON', 'amendor'), 'txt' => __('Export TXT', 'amendor')] as $fmt => $label) : ?>
                    <a href="<?php echo esc_url(wp_nonce_url(add_query_arg(array_merge($export_base, ['history_format' => $fmt]), admin_url('admin.php')), 'amendor_export_history_log')); ?>" class="button button-secondary"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            <?php } ?>
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
