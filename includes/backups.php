<?php

/**
 * Backup Storage and Restore (dedicated backups table)
 *
 * @package Amendor
 */

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Build the current backup snapshot for a post.
 *
 * @param int $post_id The post ID.
 * @return array|null
 */
function amendor_build_post_backup_snapshot($post_id)
{
    $post = get_post($post_id);
    if (!$post instanceof WP_Post) {
        return null;
    }

    $seo_snapshot = [];
    foreach (amendor_get_seo_meta_field_groups() as $seo_source => $seo_meta_keys) {
        $seo_snapshot[$seo_source] = [];
        foreach ($seo_meta_keys as $seo_meta_key) {
            $seo_snapshot[$seo_source][$seo_meta_key] = (string) get_post_meta($post_id, $seo_meta_key, true);
        }
    }

    return [
        'post_title' => (string) $post->post_title,
        'post_content' => (string) $post->post_content,
        'post_excerpt' => (string) $post->post_excerpt,
        'elementor_data' => amendor_decode_elementor_data(get_post_meta($post_id, '_elementor_data', true)),
        'seo_title' => $seo_snapshot['seo_title'],
        'seo_description' => $seo_snapshot['seo_description'],
    ];
}

/**
 * Create a plugin-owned backup snapshot for a post.
 *
 * @param int   $post_id The post ID.
 * @param array $snapshot Snapshot payload.
 * @return bool True on success, false on failure.
 */
function amendor_create_post_backup($post_id, array $snapshot)
{
    if (!$post_id || empty($snapshot)) {
        amendor_add_debug_log('Backup Error: Invalid post backup snapshot.', 'ERROR', ['post_id' => $post_id]);
        return false;
    }

    global $wpdb;
    $table = amendor_get_backups_table_name();
    $elementor_data = (isset($snapshot['elementor_data']) && is_array($snapshot['elementor_data'])) ? $snapshot['elementor_data'] : null;

    $inserted = $wpdb->insert(
        $table,
        [
            'post_id' => (int) $post_id,
            'timestamp' => current_time('mysql', 1),
            'data' => $elementor_data !== null ? wp_json_encode($elementor_data) : null,
            'snapshot' => wp_json_encode($snapshot),
        ],
        ['%d', '%s', '%s', '%s']
    );

    if ($inserted === false) {
        $error_msg = sprintf(
            /* translators: %s: Post ID */
            esc_html__('Backup Error: Insert failed for Post ID %s.', 'amendor'),
            esc_html($post_id)
        );
        amendor_add_debug_log($error_msg, 'ERROR', ['post_id' => $post_id]);
        error_log('ETP ' . $error_msg);
        return false;
    }

    amendor_prune_backups($post_id, amendor_get_backup_retention_limit());

    return true;
}

/**
 * Prune backups for a post down to the retention limit.
 *
 * @param int $post_id The post ID.
 * @param int $limit Maximum number of backups to keep.
 * @return void
 */
function amendor_prune_backups($post_id, $limit)
{
    global $wpdb;

    $limit = max(1, (int) $limit);
    $table = amendor_get_backups_table_name();

    // Table name is plugin-owned; the subquery keeps the newest $limit rows.
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table} WHERE post_id = %d AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM {$table} WHERE post_id = %d ORDER BY timestamp DESC, id DESC LIMIT %d
                ) retained_backups
            )",
            $post_id,
            $post_id,
            $limit
        )
    ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

/**
 * Creates a backup of the Elementor data for a specific post.
 * Stores backups in the dedicated backups table, keeping the configured retention count.
 *
 * @param int $post_id The ID of the post.
 * @param array $data The Elementor data array to back up.
 * @return bool True on success, false on failure.
 */
function amendor_create_elementor_backup($post_id, $data)
{
    if (!$post_id || !is_array($data)) {
        $error_msg = sprintf(
            /* translators: 1: Post ID, 2: Data type */
            esc_html__('Backup Error: Invalid Post ID (%1$s) or Data type (%2$s) for backup.', 'amendor'),
            esc_html($post_id),
            esc_html(gettype($data))
        );
        amendor_add_debug_log($error_msg, 'ERROR', ['post_id' => $post_id, 'data_type' => gettype($data)]);
        error_log('ETP ' . $error_msg);
        return false;
    }

    $snapshot = amendor_build_post_backup_snapshot($post_id);
    if (!is_array($snapshot)) {
        return false;
    }
    $snapshot['elementor_data'] = $data;

    return amendor_create_post_backup($post_id, $snapshot);
}

/**
 * Retrieves all stored backups for a specific post from the backups table.
 *
 * @param int $post_id The ID of the post.
 * @return array An array of backups or an empty array if none found.
 */
function amendor_get_elementor_backups($post_id)
{
    global $wpdb;
    $table = amendor_get_backups_table_name();

    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM {$table} WHERE post_id = %d ORDER BY timestamp DESC, id DESC", $post_id),
        ARRAY_A
    ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    $backups = [];
    foreach ((array) $rows as $row) {
        $backups[] = [
            'timestamp' => (string) $row['timestamp'],
            'data' => !empty($row['data']) ? json_decode($row['data'], true) : null,
            'snapshot' => !empty($row['snapshot']) ? json_decode($row['snapshot'], true) : [],
        ];
    }

    return $backups;
}

/**
 * Returns the number of stored backups for a post.
 *
 * @param int $post_id The post ID.
 * @return int
 */
function amendor_get_post_backup_count($post_id)
{
    global $wpdb;
    $table = amendor_get_backups_table_name();

    $count = $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(id) FROM {$table} WHERE post_id = %d", $post_id)
    ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    return (int) $count;
}

/**
 * Restores Elementor data from a specific backup version.
 *
 * @param int $post_id The ID of the post.
 * @param int $backup_index The index of the backup to restore (0 is the latest).
 * @return bool True on success, false on failure.
 */
function amendor_restore_elementor_backup($post_id, $backup_index = 0)
{
    $backups = amendor_get_elementor_backups($post_id);

    if (!isset($backups[$backup_index]) || !is_array($backups[$backup_index])) {
        $error_msg = sprintf(
            /* translators: 1: Backup Index, 2: Post ID */
            esc_html__('Restore Error: Backup index %1$s not found or invalid for Post ID %2$s.', 'amendor'),
            esc_html($backup_index),
            esc_html($post_id)
        );
        amendor_add_debug_log($error_msg, 'ERROR', ['post_id' => $post_id, 'index' => $backup_index]);
        error_log('ETP ' . $error_msg);
        return false;
    }

    $backup = $backups[$backup_index];
    $snapshot = isset($backup['snapshot']) && is_array($backup['snapshot']) ? $backup['snapshot'] : [];
    if (empty($snapshot) && array_key_exists('data', $backup)) {
        $snapshot = [
            'elementor_data' => $backup['data'],
        ];
    }

    if (empty($snapshot)) {
        $error_msg = sprintf(
            /* translators: 1: Post ID, 2: Backup Index */
            esc_html__('Restore Error: Invalid backup snapshot for Post ID %1$s, Index %2$s.', 'amendor'),
            esc_html($post_id),
            esc_html($backup_index)
        );
        amendor_add_debug_log($error_msg, 'ERROR', ['post_id' => $post_id, 'index' => $backup_index]);
        error_log('ETP ' . $error_msg);
        return false;
    }

    $post_update = [
        'ID' => (int) $post_id,
    ];
    foreach (['post_title', 'post_content', 'post_excerpt'] as $field_key) {
        if (array_key_exists($field_key, $snapshot)) {
            $post_update[$field_key] = (string) $snapshot[$field_key];
        }
    }

    if (count($post_update) > 1) {
        $update_result = wp_update_post(wp_slash($post_update), true);
        if (is_wp_error($update_result)) {
            $error_msg = sprintf(
                /* translators: 1: Post ID, 2: Error message */
                esc_html__('Restore Error: Failed to update post fields for Post ID %1$s. Error: %2$s', 'amendor'),
                esc_html($post_id),
                esc_html($update_result->get_error_message())
            );
            amendor_add_debug_log($error_msg, 'ERROR', ['post_id' => $post_id, 'error' => $update_result->get_error_message()]);
            error_log('ETP ' . $error_msg);
            return false;
        }
    }

    if (array_key_exists('elementor_data', $snapshot)) {
        $backup_data = $snapshot['elementor_data'];
        if ($backup_data === null) {
            delete_post_meta($post_id, '_elementor_data');
        } elseif (!is_array($backup_data)) {
            $error_msg = sprintf(
                /* translators: 1: Post ID, 2: Backup Index */
                esc_html__('Restore Error: Invalid Elementor backup data format for Post ID %1$s, Index %2$s.', 'amendor'),
                esc_html($post_id),
                esc_html($backup_index)
            );
            amendor_add_debug_log($error_msg, 'ERROR', ['post_id' => $post_id, 'index' => $backup_index]);
            error_log('ETP ' . $error_msg);
            return false;
        } else {
            $encoded_backup_data = amendor_encode_elementor_data($backup_data, ['post_id' => $post_id, 'operation' => 'restore_backup']);
            if ($encoded_backup_data === false) {
                return false;
            }

            $update_result = update_post_meta($post_id, '_elementor_data', wp_slash($encoded_backup_data));
            if ($update_result === false) {
                $db_error = $GLOBALS['wpdb']->last_error;
                $error_msg = sprintf(
                    /* translators: 1: Post ID, 2: Database Error */
                    esc_html__('Restore Error: Failed to update post meta for Post ID %1$s. DB Error: %2$s', 'amendor'),
                    esc_html($post_id),
                    esc_html($db_error)
                );
                amendor_add_debug_log($error_msg, 'ERROR', ['post_id' => $post_id, 'db_error' => $db_error]);
                error_log('ETP ' . $error_msg);
                return false;
            }
        }
        if (amendor_clear_elementor_cache_for_post($post_id)) {
            $log_msg = sprintf(
                /* translators: %s: Post ID */
                esc_html__('Elementor cache cleared after restore for Post ID %s.', 'amendor'),
                esc_html($post_id)
            );
            amendor_add_debug_log($log_msg, 'INFO', ['post_id' => $post_id]);
            error_log('ETP Restore Info: ' . $log_msg);
        }
    }

    // Restore SEO meta (Yoast / Rank Math) captured in the snapshot.
    $seo_groups = amendor_get_seo_meta_field_groups();
    foreach ($seo_groups as $seo_source => $seo_meta_keys) {
        $seo_values = isset($snapshot[$seo_source]) && is_array($snapshot[$seo_source]) ? $snapshot[$seo_source] : [];
        foreach ($seo_meta_keys as $seo_meta_key) {
            if (array_key_exists($seo_meta_key, $seo_values)) {
                update_post_meta($post_id, $seo_meta_key, wp_slash((string) $seo_values[$seo_meta_key]));
            }
        }
    }

    return true;
}


/**
 * Handles the backup download request early in the WP load.
 */
function amendor_handle_backup_download()
{
    // Check if the download action is triggered and nonce is set
    if (!isset($_GET['action']) || $_GET['action'] !== 'download_backup' || !isset($_GET['amendor_download_nonce'])) {
        return; // Not our action
    }

    // Verify nonce
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['amendor_download_nonce'])), 'amendor_download_backup_action')) {
        amendor_add_debug_log("Backup Download Error: Nonce verification failed on admin_init.", 'ERROR');
        // Optionally add a wp_die() here or redirect with an error message, but returning might be less disruptive
        // wp_die( __( 'Security check failed.', 'amendor' ), __( 'Nonce Error', 'amendor' ), 403 );
        return;
    }

    // Check capability
    if (!amendor_current_user_can_manage()) {
        amendor_add_debug_log("Backup Download Error: Permission denied on admin_init.", 'ERROR', ['user_id' => get_current_user_id()]);
        wp_die(esc_html__('You do not have sufficient permissions to access this feature.', 'amendor'), esc_html__('Permission Denied', 'amendor'), 403);
        exit; // Exit is redundant after wp_die but good practice
    }

    amendor_add_debug_log("Initiating Backup Download (via admin_init)...", 'INFO');

    // --- Gather Data from the dedicated backups table ---
    global $wpdb;
    $table = amendor_get_backups_table_name();
    $backup_rows = $wpdb->get_results(
        "SELECT post_id, timestamp, data, snapshot FROM {$table} ORDER BY post_id ASC, timestamp DESC, id DESC",
        ARRAY_A
    ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    $export_data = [];
    $processed_count = 0;
    $posts_map = [];

    foreach ((array) $backup_rows as $row) {
        $post_id = (int) $row['post_id'];

        if (!isset($posts_map[$post_id])) {
            $post = get_post($post_id);
            $posts_map[$post_id] = [
                'post_id' => $post_id,
                'post_title' => $post ? (string) $post->post_title : '',
                'post_type' => $post ? (string) $post->post_type : '',
                'backups' => [],
            ];
            $processed_count++;
        }

        $posts_map[$post_id]['backups'][] = [
            'timestamp' => (string) $row['timestamp'],
            'data' => !empty($row['data']) ? json_decode($row['data'], true) : null,
            'snapshot' => !empty($row['snapshot']) ? json_decode($row['snapshot'], true) : [],
        ];
    }
    $export_data = array_values($posts_map);

    // --- Prepare and Send File ---
    if (empty($export_data)) {
        amendor_add_debug_log("Backup Download: No data to export (checked on admin_init).", 'INFO');
        // Redirect back with an error message instead of trying to output headers
        $redirect_url = add_query_arg(['page' => amendor_get_admin_parent_slug(), 'amendor_notice' => 'no_backup_data'], admin_url('admin.php'));
        wp_safe_redirect(esc_url_raw($redirect_url));
        exit;
    } else {
        $filename = 'amendor-backups-' . gmdate('Ymd-His') . '.json';
        amendor_add_debug_log("Backup Download: Exporting posts (via admin_init).", 'INFO', ['count' => $processed_count, 'filename' => $filename]);

        // Set headers to force download
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $json_export = amendor_encode_elementor_data($export_data, ['operation' => 'backup_export', 'count' => $processed_count]);
        if ($json_export === false) {
            wp_die(esc_html__('Unable to generate the backup export file because JSON encoding failed.', 'amendor'), esc_html__('Export Error', 'amendor'), 500);
        }

        // Output the JSON data (raw JSON download - must not be HTML-escaped).
        echo $json_export; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit; // IMPORTANT: Stop script execution after sending the file
    }
}
