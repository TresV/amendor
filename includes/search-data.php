<?php

/**
 * Search & Replace Data Helpers (sources, backups, limits, history)
 *
 * @package Amendor
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

if (!defined('AMENDOR_DEFAULT_MAX_BACKUPS')) {
    define('AMENDOR_DEFAULT_MAX_BACKUPS', 5);
}

if (!defined('AMENDOR_DEFAULT_SEARCH_BATCH_SIZE')) {
    define('AMENDOR_DEFAULT_SEARCH_BATCH_SIZE', 25);
}

if (!defined('AMENDOR_DEFAULT_DEBUG_LOG_RETENTION')) {
    define('AMENDOR_DEFAULT_DEBUG_LOG_RETENTION', 5000);
}

if (!defined('AMENDOR_DEFAULT_HISTORY_LOG_RETENTION')) {
    define('AMENDOR_DEFAULT_HISTORY_LOG_RETENTION', 5000);
}

if (!defined('AMENDOR_MAX_SEARCH_TERM_LENGTH')) {
    define('AMENDOR_MAX_SEARCH_TERM_LENGTH', 1000);
}

/**
 * Return the available content sources that can be searched or replaced.
 *
 * @return array<string,string>
 */
function amendor_get_available_content_sources()
{
    return [
        'elementor' => __('Elementor Content', 'amendor'),
        'post_title' => __('Post Title', 'amendor'),
        'post_content' => __('Post Content', 'amendor'),
        'post_excerpt' => __('Post Excerpt', 'amendor'),
    ];
}

/**
 * Return the default selected content sources.
 *
 * @return array<int,string>
 */
function amendor_get_default_content_sources()
{
    return array_keys(amendor_get_available_content_sources());
}

/**
 * Normalize selected content sources.
 *
 * @param array $selected_sources Raw selected sources.
 * @param bool  $fallback_to_default Whether to default to all supported sources.
 * @return array<int,string>
 */
function amendor_normalize_content_sources(array $selected_sources, $fallback_to_default = true)
{
    $available = array_keys(amendor_get_available_content_sources());
    $selected_sources = array_values(array_unique(array_filter(array_map('sanitize_key', $selected_sources))));
    $selected_sources = array_values(array_intersect($available, $selected_sources));

    if (empty($selected_sources) && $fallback_to_default) {
        return $available;
    }

    return $selected_sources;
}

/**
 * Return whether Elementor content is included in a source selection.
 *
 * @param array $selected_sources Selected sources.
 * @return bool
 */
function amendor_content_sources_include_elementor(array $selected_sources)
{
    return in_array('elementor', amendor_normalize_content_sources($selected_sources, false), true);
}

/**
 * Return a human-readable label for a content source.
 *
 * @param string $source Source key.
 * @return string
 */
function amendor_get_content_source_label($source)
{
    $available = amendor_get_available_content_sources();
    return isset($available[$source]) ? $available[$source] : ucfirst(str_replace('_', ' ', (string) $source));
}

/**
 * Return a short, human-readable summary of selected sources.
 *
 * @param array $selected_sources Selected sources.
 * @return string
 */
function amendor_format_content_sources_summary(array $selected_sources)
{
    $selected_sources = amendor_normalize_content_sources($selected_sources);
    $labels = array_map('amendor_get_content_source_label', $selected_sources);
    return implode(', ', $labels);
}

/**
 * Clamp a search or replace term to a safe maximum length.
 *
 * @param string $term Search or replace term.
 * @return string
 */
function amendor_limit_search_term($term)
{
    if (!is_string($term) || $term === '') {
        return '';
    }

    return mb_substr($term, 0, AMENDOR_MAX_SEARCH_TERM_LENGTH);
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

    return [
        'post_title' => (string) $post->post_title,
        'post_content' => (string) $post->post_content,
        'post_excerpt' => (string) $post->post_excerpt,
        'elementor_data' => amendor_decode_elementor_data(get_post_meta($post_id, '_elementor_data', true)),
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
 * Decode Elementor JSON into a PHP array.
 *
 * @param mixed $raw_meta Raw meta value from the database.
 * @return array|null
 */
function amendor_decode_elementor_data($raw_meta)
{
    if (!is_string($raw_meta) || $raw_meta === '' || $raw_meta === '[]') {
        return null;
    }

    $data = json_decode($raw_meta, true);
    if (is_string($data)) {
        $data = json_decode($data, true);
    }

    return is_array($data) ? $data : null;
}

/**
 * Encode Elementor data safely for storage or export.
 *
 * @param mixed $data Data to encode.
 * @param array $log_context Optional debug context.
 * @return string|false
 */
function amendor_encode_elementor_data($data, $log_context = [])
{
    $encoded = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE);

    if ($encoded === false) {
        $error_message = sprintf(
            /* translators: %s: JSON error message */
            __('JSON encoding failed: %s', 'amendor'),
            function_exists('json_last_error_msg') ? json_last_error_msg() : __('Unknown JSON error', 'amendor')
        );
        amendor_add_debug_log($error_message, 'ERROR', $log_context);
        error_log('ETP ' . $error_message);
        return false;
    }

    return $encoded;
}

/**
 * Resolve an integer plugin limit from an option with a fallback constant.
 *
 * @param string $option_name Option name.
 * @param int    $default Default value.
 * @param int    $minimum Minimum accepted value.
 * @return int
 */
function amendor_get_integer_limit_option($option_name, $default, $minimum = 1)
{
    $value = get_option($option_name, $default);
    $value = is_numeric($value) ? (int) $value : (int) $default;

    return max((int) $minimum, $value);
}

/**
 * Get the backup retention limit.
 *
 * @return int
 */
function amendor_get_backup_retention_limit()
{
    return max(1, (int) apply_filters('amendor_backup_retention_limit', AMENDOR_DEFAULT_MAX_BACKUPS));
}

/**
 * Get the default search batch size.
 *
 * @return int
 */
function amendor_get_default_search_batch_size()
{
    $saved = get_option('amendor_search_batch_size', 0);
    $value = is_numeric($saved) && (int) $saved > 0 ? (int) $saved : AMENDOR_DEFAULT_SEARCH_BATCH_SIZE;

    return max(1, (int) apply_filters('amendor_search_batch_size', $value));
}

/**
 * Get the maximum number of debug log rows to retain.
 *
 * @return int
 */
function amendor_get_debug_log_retention_limit()
{
    $default = (int) apply_filters('amendor_default_debug_log_retention', AMENDOR_DEFAULT_DEBUG_LOG_RETENTION);

    return amendor_get_integer_limit_option('amendor_debug_log_retention_limit', $default);
}

/**
 * Get the maximum number of history rows to retain.
 *
 * @return int
 */
function amendor_get_history_log_retention_limit()
{
    $default = (int) apply_filters('amendor_default_history_log_retention', AMENDOR_DEFAULT_HISTORY_LOG_RETENTION);

    return amendor_get_integer_limit_option('amendor_history_log_retention_limit', $default);
}

/**
 * Build a safe PCRE pattern from a user-supplied regex body.
 *
 * @param string $pattern_body Regex body without delimiters.
 * @param string $flags Optional PCRE flags.
 * @return string
 */
function amendor_build_regex_pattern($pattern_body, $flags = '')
{
    $pattern_body = (string) $pattern_body;
    $flags = preg_replace('/[^a-zA-Z]/', '', (string) $flags);
    $candidate_delimiters = ['~', '#', '%', '!', ';', '`', '@'];
    $delimiter = '~';

    foreach ($candidate_delimiters as $candidate_delimiter) {
        if (strpos($pattern_body, $candidate_delimiter) === false) {
            $delimiter = $candidate_delimiter;
            break;
        }
    }

    if (strpos($pattern_body, $delimiter) !== false) {
        $pattern_body = str_replace($delimiter, '\\' . $delimiter, $pattern_body);
    }

    return $delimiter . $pattern_body . $delimiter . $flags;
}

/**
 * Create a fresh changes-details payload.
 *
 * @return array
 */
function amendor_create_changes_details()
{
    return [
        'matched_count' => 0,
        'replaced_count' => 0,
        'diffs' => [],
        'errors' => [],
    ];
}

/**
 * Merge one changes-details payload into another.
 *
 * @param array $target Aggregate payload.
 * @param array $source Payload to merge.
 * @return array
 */
function amendor_merge_changes_details(array $target, array $source)
{
    $target['matched_count'] += (int) ($source['matched_count'] ?? 0);
    $target['replaced_count'] += (int) ($source['replaced_count'] ?? 0);
    $target['diffs'] = array_merge($target['diffs'], isset($source['diffs']) && is_array($source['diffs']) ? $source['diffs'] : []);
    $target['errors'] = array_merge($target['errors'], isset($source['errors']) && is_array($source['errors']) ? $source['errors'] : []);

    return $target;
}

/**
 * Add source metadata to diff entries.
 *
 * @param array  $diffs Diff entries.
 * @param string $source_key Source key.
 * @param string $source_label Source label.
 * @return array
 */
function amendor_annotate_diff_entries(array $diffs, $source_key, $source_label)
{
    foreach ($diffs as &$diff) {
        $diff['source'] = (string) $source_key;
        $diff['source_label'] = (string) $source_label;
    }
    unset($diff);

    return $diffs;
}

/**
 * Build the mutable content state for a post.
 *
 * @param WP_Post $post Post object.
 * @return array<string,mixed>
 */
function amendor_build_post_content_state(WP_Post $post)
{
    return [
        'post_id' => (int) $post->ID,
        'post_title' => (string) $post->post_title,
        'post_content' => (string) $post->post_content,
        'post_excerpt' => (string) $post->post_excerpt,
        'elementor_data' => amendor_decode_elementor_data(get_post_meta($post->ID, '_elementor_data', true)),
    ];
}

/**
 * Prune a plugin-owned log table to the latest retained rows.
 *
 * @param string $table_name Table name.
 * @param int    $limit Maximum rows to keep.
 * @return void
 */
function amendor_prune_log_table($table_name, $limit)
{
    global $wpdb;

    $limit = max(1, (int) $limit);
    if (!preg_match('/^[A-Za-z0-9_]+$/', (string) $table_name)) {
        return;
    }

    $total_rows = (int) $wpdb->get_var("SELECT COUNT(id) FROM {$table_name}");
    if ($total_rows <= $limit) {
        return;
    }

    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$table_name} WHERE id NOT IN (
                SELECT id FROM (
                    SELECT id FROM {$table_name} ORDER BY id DESC LIMIT %d
                ) retained_rows
            )",
            $limit
        )
    );
}

/**
 * Run the scheduled log pruning job.
 *
 * @return void
 */
function amendor_run_log_pruning()
{
    amendor_prune_log_table(amendor_get_history_table_name(), amendor_get_history_log_retention_limit());
    amendor_prune_log_table(amendor_get_debug_log_table_name(), amendor_get_debug_log_retention_limit());
}

/**
 * Gets the current user's search history list.
 *
 * @param int|null $user_id Optional user ID.
 * @return array
 */
function amendor_get_search_history($user_id = null)
{
    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id) {
        return [];
    }

    $history = get_user_meta($user_id, 'amendor_search_history', true);
    return is_array($history) ? $history : [];
}

/**
 * Normalize the number of result posts shown per page.
 *
 * @param mixed $requested Requested page size.
 * @return int
 */
function amendor_get_search_results_per_page($requested = null)
{
    $default = (int) apply_filters('amendor_search_posts_per_page', 50);
    $allowed = array_values(array_unique(array_map('intval', apply_filters('amendor_search_posts_per_page_options', [10, 25, 50, 100]))));

    if (!in_array($default, $allowed, true)) {
        $allowed[] = $default;
        sort($allowed);
    }

    $requested = $requested !== null ? (int) $requested : $default;
    return in_array($requested, $allowed, true) ? $requested : $default;
}

/**
 * Store a search term in the current user's search history.
 *
 * @param string   $search  Search term.
 * @param int|null $user_id Optional user ID.
 * @return void
 */
function amendor_store_search_history($search, $user_id = null)
{
    $search = (string) $search;
    if ($search === '') {
        return;
    }

    $user_id = $user_id ?: get_current_user_id();
    if (!$user_id) {
        return;
    }

    $history = amendor_get_search_history($user_id);
    if (empty($history) || $history[0] !== $search) {
        $history = array_values(array_filter($history, static function ($item) use ($search) {
            return $item !== $search;
        }));
        array_unshift($history, $search);
        update_user_meta($user_id, 'amendor_search_history', array_slice($history, 0, 10));
    }
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
