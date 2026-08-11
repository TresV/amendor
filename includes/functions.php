<?php

/**
 * General Helper Functions
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

    $backups = get_post_meta($post_id, amendor_get_backup_meta_key(), true);
    if (!is_array($backups)) {
        $backups = [];
    }

    $backup = [
        'timestamp' => current_time('mysql', 1),
        'data' => isset($snapshot['elementor_data']) && is_array($snapshot['elementor_data']) ? $snapshot['elementor_data'] : null,
        'snapshot' => $snapshot,
    ];
    array_unshift($backups, $backup);
    $backups = array_slice($backups, 0, amendor_get_backup_retention_limit());
    $result = update_post_meta($post_id, amendor_get_backup_meta_key(), $backups);

    if ($result === false) {
        $error_msg = sprintf(
            /* translators: %s: Post ID */
            esc_html__('Backup Error: update_post_meta failed for Post ID %s.', 'amendor'),
            esc_html($post_id)
        );
        amendor_add_debug_log($error_msg, 'ERROR', ['post_id' => $post_id]);
        error_log('ETP ' . $error_msg);
    }

    return $result !== false;
}

/**
 * Creates a backup of the Elementor data for a specific post.
 * Stores backups in post meta, keeping the configured retention count.
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
 * Retrieves all stored backups for a specific post from post meta.
 *
 * @param int $post_id The ID of the post.
 * @return array An array of backups or an empty array if none found.
 */
function amendor_get_elementor_backups($post_id)
{
    $backups = get_post_meta($post_id, amendor_get_backup_meta_key(), true);
    return is_array($backups) ? $backups : [];
}

/**
 * Returns the number of stored backups for a post.
 *
 * @param int $post_id The post ID.
 * @return int
 */
function amendor_get_post_backup_count($post_id)
{
    return count(amendor_get_elementor_backups($post_id));
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
    return max(1, (int) apply_filters('amendor_search_batch_size', AMENDOR_DEFAULT_SEARCH_BATCH_SIZE));
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
 * Analyze native post fields for search, preview, or replacement.
 *
 * @param array  $state Mutable content state.
 * @param string $search Search term.
 * @param string $replace Replacement term.
 * @param string $search_mode Search mode.
 * @param bool   $perform_replace Whether to replace.
 * @param array  $content_sources Selected content sources.
 * @return array{state:array,changes_details:array}
 */
function amendor_analyze_native_post_fields(array $state, $search, $replace, $search_mode, $perform_replace, array $content_sources)
{
    $changes_details = amendor_create_changes_details();
    $content_sources = amendor_normalize_content_sources($content_sources, false);

    foreach (['post_title', 'post_content', 'post_excerpt'] as $field_key) {
        if (!in_array($field_key, $content_sources, true)) {
            continue;
        }

        $field_changes = amendor_create_changes_details();
        $processed_value = amendor_process_elementor_data_recursive(
            isset($state[$field_key]) ? (string) $state[$field_key] : '',
            $search,
            $replace,
            $search_mode,
            $perform_replace,
            $field_changes,
            []
        );

        $field_changes['diffs'] = amendor_annotate_diff_entries(
            isset($field_changes['diffs']) && is_array($field_changes['diffs']) ? $field_changes['diffs'] : [],
            $field_key,
            amendor_get_content_source_label($field_key)
        );
        $changes_details = amendor_merge_changes_details($changes_details, $field_changes);

        if ($perform_replace && $field_changes['replaced_count'] > 0) {
            $state[$field_key] = $processed_value;
        }
    }

    return [
        'state' => $state,
        'changes_details' => $changes_details,
    ];
}

/**
 * Analyze all selected content sources for a post state.
 *
 * @param array  $state Mutable post content state.
 * @param string $search Search term.
 * @param string $replace Replacement term.
 * @param string $search_mode Search mode.
 * @param bool   $perform_replace Whether to replace.
 * @param array  $selected_widgets Optional widget filters.
 * @param array  $content_sources Selected content sources.
 * @return array{state:array,changes_details:array,matched:bool,changed:bool}
 */
function amendor_analyze_post_content_state(array $state, $search, $replace, $search_mode, $perform_replace, array $selected_widgets, array $content_sources)
{
    $content_sources = amendor_normalize_content_sources($content_sources);
    $changes_details = amendor_create_changes_details();

    if (amendor_content_sources_include_elementor($content_sources) && is_array($state['elementor_data'] ?? null)) {
        $elementor_analysis = amendor_analyze_elementor_data($state['elementor_data'], $search, $replace, $search_mode, $perform_replace, $selected_widgets);
        $elementor_changes = $elementor_analysis['changes_details'];
        $elementor_changes['diffs'] = amendor_annotate_diff_entries(
            isset($elementor_changes['diffs']) && is_array($elementor_changes['diffs']) ? $elementor_changes['diffs'] : [],
            'elementor',
            amendor_get_content_source_label('elementor')
        );
        $changes_details = amendor_merge_changes_details($changes_details, $elementor_changes);

        if ($perform_replace && $elementor_changes['replaced_count'] > 0) {
            $state['elementor_data'] = $elementor_analysis['data'];
        }
    }

    $native_analysis = amendor_analyze_native_post_fields($state, $search, $replace, $search_mode, $perform_replace, $content_sources);
    $state = $native_analysis['state'];
    $changes_details = amendor_merge_changes_details($changes_details, $native_analysis['changes_details']);

    return [
        'state' => $state,
        'changes_details' => $changes_details,
        'matched' => (int) $changes_details['matched_count'] > 0,
        'changed' => (int) $changes_details['replaced_count'] > 0,
    ];
}

/**
 * Analyze Elementor data for search, preview, or replacement.
 *
 * @param mixed  $data Elementor data.
 * @param string $search Search term.
 * @param string $replace Replacement term.
 * @param string $search_mode Search mode.
 * @param bool   $perform_replace Whether to replace.
 * @param array  $selected_widgets Optional widget filters.
 * @return array{data:mixed,changes_details:array}
 */
function amendor_analyze_elementor_data($data, $search, $replace, $search_mode, $perform_replace, array $selected_widgets = [])
{
    $changes_details = amendor_create_changes_details();
    $processed_data = amendor_process_elementor_data_recursive($data, $search, $replace, $search_mode, $perform_replace, $changes_details, $selected_widgets);

    return [
        'data' => $processed_data,
        'changes_details' => $changes_details,
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
 * Recursively search or replace values inside Elementor data.
 *
 * @param mixed  $data             Elementor data.
 * @param string $search           Search term.
 * @param string $replace          Replacement term.
 * @param string $search_mode      Search mode.
 * @param bool   $perform_replace  Whether to replace.
 * @param array  $changes_details  Summary of changes.
 * @param array  $selected_widgets Optional widget filters.
 * @return mixed
 */
function amendor_process_elementor_data_recursive($data, $search, $replace, $search_mode, $perform_replace, array &$changes_details, array $selected_widgets = [])
{
    $changes_details['matched_count'] = $changes_details['matched_count'] ?? 0;
    $changes_details['replaced_count'] = $changes_details['replaced_count'] ?? 0;
    $changes_details['diffs'] = $changes_details['diffs'] ?? [];
    $changes_details['errors'] = $changes_details['errors'] ?? [];

    if (is_string($data)) {
        $original_value = $data;
        if ($original_value === '' && $search !== '') {
            return $original_value;
        }

        $matched = false;
        $new_value = $original_value;

        try {
            if ($search !== '') {
                switch ($search_mode) {
                    case 'exact':
                        $matched = (strpos($original_value, $search) !== false);
                        if ($matched && $perform_replace) {
                            $new_value = str_replace($search, $replace, $original_value);
                        }
                        break;

                    case 'regex':
                        $pattern = amendor_build_regex_pattern($search, 'iu');
                        $match_result = @preg_match($pattern, $original_value);
                        if ($match_result === 1) {
                            $matched = true;
                            $potential_new_value = @preg_replace($pattern, $replace, $original_value);
                            if ($potential_new_value === null && preg_last_error() !== PREG_NO_ERROR) {
                                throw new Exception(sprintf(__('Regex replacement error: %s', 'amendor'), preg_last_error_msg()));
                            }
                            $new_value = $potential_new_value;
                        } elseif ($match_result === false) {
                            throw new Exception(sprintf(__('Regex matching error: %s', 'amendor'), preg_last_error_msg()));
                        }
                        break;

                    default:
                        if (mb_stripos($original_value, $search, 0, 'UTF-8') !== false) {
                            $matched = true;
                            $new_value = str_ireplace($search, $replace, $original_value);
                        }
                        break;
                }
            }
        } catch (Exception $e) {
            $error_message = sprintf(
                /* translators: 1: String snippet, 2: Error message */
                __('Error processing string [%1$s...]: %2$s', 'amendor'),
                esc_html(mb_substr($original_value, 0, 50)),
                esc_html($e->getMessage())
            );
            $changes_details['errors'][] = $error_message;
            amendor_add_debug_log($error_message, 'ERROR', ['original_value_snippet' => mb_substr($original_value, 0, 50)]);
            return $original_value;
        }

        if ($matched) {
            $changes_details['matched_count']++;
            if ($new_value !== $original_value) {
                $changes_details['diffs'][] = [
                    'before' => $original_value,
                    'after' => $new_value,
                    'changed' => true,
                ];
                if ($perform_replace) {
                    $changes_details['replaced_count']++;
                    return $new_value;
                }
            } elseif (!$perform_replace) {
                $changes_details['diffs'][] = [
                    'before' => $original_value,
                    'after' => $original_value,
                    'changed' => false,
                    'note' => __('Match - No Change Previewed', 'amendor'),
                ];
            }
        }

        return $original_value;
    }

    if (is_array($data)) {
        $widget_type = $data['widgetType'] ?? null;
        $element_type = $data['elType'] ?? null;
        $is_widget_or_element = in_array($element_type, ['widget', 'section', 'column', 'common'], true);
        $process_this_element_settings = empty($selected_widgets) || !$is_widget_or_element || ($widget_type && in_array($widget_type, $selected_widgets, true));

        $modified_data = [];
        foreach ($data as $key => $value) {
            if ($key === 'settings' && $is_widget_or_element && !$process_this_element_settings) {
                $modified_data[$key] = $value;
                continue;
            }

            $modified_data[$key] = amendor_process_elementor_data_recursive($value, $search, $replace, $search_mode, $perform_replace, $changes_details, $selected_widgets);
        }

        return $modified_data;
    }

    return $data;
}

/**
 * Render a structured match entry into admin HTML.
 *
 * @param array $match Match entry data.
 * @return string
 */
function amendor_render_match_block(array $match)
{
    $before = isset($match['before']) ? (string) $match['before'] : '';
    $after = isset($match['after']) ? (string) $match['after'] : '';
    $changed = !empty($match['changed']);
    $note = isset($match['note']) ? (string) $match['note'] : '';
    $source_label = isset($match['source_label']) ? (string) $match['source_label'] : '';
    $source_badge = $source_label !== '' ? '<div class="match-source"><strong>' . esc_html($source_label) . '</strong></div>' : '';

    if ($changed) {
        return $source_badge . '<mark class="search-highlight">' . esc_html($before) . '</mark><br><span class="match-arrow" aria-hidden="true">⬇️</span><br><mark class="replace-highlight">' . esc_html($after) . '</mark>';
    }

    return $source_badge . '<mark class="search-highlight">' . esc_html($before) . '</mark>' . ($note !== '' ? ' <span class="match-note">(' . esc_html($note) . ')</span>' : '');
}

/**
 * Clear Elementor caches for a specific post.
 *
 * @param int $post_id The post ID.
 * @return bool True if the cache clear routine ran without exception, false otherwise.
 */
function amendor_clear_elementor_cache_for_post($post_id)
{
    if (!class_exists('Elementor\Plugin')) {
        return false;
    }

    try {
        $elementor = Elementor\Plugin::$instance;

        if (isset($elementor->files_manager) && method_exists($elementor->files_manager, 'clear_cache')) {
            $elementor->files_manager->clear_cache();
        }

        if (isset($elementor->posts_css_manager)) {
            if (method_exists($elementor->posts_css_manager, 'clear_cache_for_post')) {
                $elementor->posts_css_manager->clear_cache_for_post($post_id);
            } elseif (class_exists('\Elementor\Core\Files\CSS\Post') && method_exists('\Elementor\Core\Files\CSS\Post', 'create')) {
                $css_file = \Elementor\Core\Files\CSS\Post::create($post_id);
                if (method_exists($css_file, 'delete')) {
                    $css_file->delete();
                }
            }
        }

        return true;
    } catch (Exception $e) {
        amendor_add_debug_log('Error clearing Elementor cache.', 'WARN', ['post_id' => $post_id, 'error' => $e->getMessage()]);
        error_log('ETP Cache Clear Warning: ' . $e->getMessage());
        return false;
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
 * Checks if a given string is a valid PHP PCRE regex pattern.
 *
 * @param string $pattern The pattern to check (should NOT include delimiters like /.../).
 * @return bool True if valid, false otherwise.
 */
function amendor_is_valid_regex($pattern)
{
    // An empty pattern is not considered a valid pattern for searching
    if ($pattern === null || $pattern === '') {
        return false;
    }

    return @preg_match(amendor_build_regex_pattern($pattern), '') !== false;
}

/**
 * Logs a replacement action to the custom history table.
 *
 * @param int    $user_id       ID of the user performing the action.
 * @param int    $post_id       ID of the post being modified.
 * @param string $post_title    Title of the post.
 * @param string $search        The search term used.
 * @param string $replace       The replacement term used.
 * @param string $search_mode   The search mode used ('partial', 'exact', 'regex').
 * @param int    $changes_made  Number of instances replaced in this operation for this post.
 * @param bool   $is_bulk       Whether this was part of a bulk operation.
 */
function amendor_log_replacement($user_id, $post_id, $post_title, $search, $replace, $search_mode, $changes_made, $is_bulk = false)
{
    global $wpdb;
    $table_name = amendor_get_history_table_name();

    // Basic validation: Ensure essential data is present and changes were made
    if (empty($user_id) || empty($post_id) || $changes_made <= 0) {
        $log_msg = sprintf(
            /* translators: 1: User ID, 2: Post ID, 3: Changes Made */
            esc_html__('History Log Skipped: Missing data or zero changes. User: %1$s, Post: %2$s, Changes: %3$d', 'amendor'),
            esc_html($user_id),
            esc_html($post_id),
            intval($changes_made)
        );
        amendor_add_debug_log($log_msg, 'WARN', [
            'user_id' => $user_id,
            'post_id' => $post_id,
            'changes_made' => $changes_made
        ]);
        error_log('ETP Log Error: ' . $log_msg);
        return; // Don't log if no changes were made or data is missing
    }

    $result = $wpdb->insert(
        $table_name,
        [
            'timestamp' => current_time('mysql', 1), // Use GMT time for consistency
            'user_id' => $user_id,
            'post_id' => $post_id,
            'post_title' => $post_title,
            'search_term' => $search,       // Store potentially long terms
            'replace_term' => $replace,     // Store potentially long terms
            'search_mode' => $search_mode,
            'bulk_operation' => $is_bulk ? 1 : 0,
            'changes_made' => $changes_made
        ],
        [ // Specify data formats for security/correctness
            '%s', // timestamp (string)
            '%d', // user_id (integer)
            '%d', // post_id (integer)
            '%s', // post_title (string)
            '%s', // search_term (string)
            '%s', // replace_term (string)
            '%s', // search_mode (string)
            '%d', // bulk_operation (integer)
            '%d'  // changes_made (integer)
        ]
    );

    if ($result === false) {
        $db_error = $wpdb->last_error;
        $error_msg = sprintf(
            /* translators: %s: Database error message */
            esc_html__('History Log Error: Failed to insert record into DB. Error: %s', 'amendor'),
            esc_html($db_error)
        );
        amendor_add_debug_log($error_msg, 'ERROR', ['db_error' => $db_error]);
        error_log('ETP Log Error: ' . $error_msg);
    } else {
        $log_msg = sprintf(
            /* translators: 1: Post ID, 2: Changes Made */
            esc_html__('History Log Success: Record added for Post ID %1$s. Changes: %2$d', 'amendor'),
            esc_html($post_id),
            intval($changes_made)
        );
        amendor_add_debug_log($log_msg, 'INFO', ['post_id' => $post_id, 'changes' => $changes_made]);
        amendor_prune_log_table($table_name, amendor_get_history_log_retention_limit());
    }
}

/**
 * Adds a message to the persistent debug log table if logging is enabled.
 *
 * @param string $message The log message.
 * @param string $level   The log level (e.g., 'DEBUG', 'INFO', 'WARN', 'ERROR').
 * @param mixed  $context Optional context data (e.g., post ID, action, relevant variables). Will be JSON encoded.
 */
function amendor_add_debug_log($message, $level = 'INFO', $context = null)
{
    $logging_enabled = get_option('amendor_enable_persistent_debug_log', false);
    $write_php_debug_log = defined('WP_DEBUG_LOG') && WP_DEBUG_LOG;

    if (!$logging_enabled && !$write_php_debug_log) {
        return;
    }

    $allowed_levels = ['DEBUG', 'INFO', 'WARN', 'ERROR', 'CRITICAL'];
    $log_level = in_array(strtoupper($level), $allowed_levels) ? strtoupper($level) : 'INFO';
    $context_json = ($context !== null) ? wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE) : null;
    $fingerprint = md5($log_level . '|' . (string) $message . '|' . (string) $context_json);
    if (!isset($GLOBALS['amendor_request_log_fingerprints']) || !is_array($GLOBALS['amendor_request_log_fingerprints'])) {
        $GLOBALS['amendor_request_log_fingerprints'] = [];
    }
    if (isset($GLOBALS['amendor_request_log_fingerprints'][$fingerprint])) {
        return;
    }
    $GLOBALS['amendor_request_log_fingerprints'][$fingerprint] = true;

    $php_log_line = '[ETP][' . $log_level . '] ' . $message . ($context_json ? ' ' . $context_json : '');

    if ($write_php_debug_log) {
        error_log($php_log_line);
    }

    if (!$logging_enabled) {
        return;
    }

    global $wpdb;
    $table_name = amendor_get_debug_log_table_name();
    $inserted = $wpdb->insert(
        $table_name,
        [
            'timestamp' => current_time('mysql', 1),
            'log_level' => $log_level,
            'message' => $message,
            'context' => $context_json,
        ],
        [
            '%s',
            '%s',
            '%s',
            '%s',
        ]
    );

    if ($inserted !== false) {
        amendor_prune_log_table($table_name, amendor_get_debug_log_retention_limit());
    }
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
    if (!current_user_can('manage_options')) {
        amendor_add_debug_log("Backup Download Error: Permission denied on admin_init.", 'ERROR', ['user_id' => get_current_user_id()]);
        wp_die(__('You do not have sufficient permissions to access this feature.', 'amendor'), __('Permission Denied', 'amendor'), 403);
        exit; // Exit is redundant after wp_die but good practice
    }

    amendor_add_debug_log("Initiating Backup Download (via admin_init)...", 'INFO');

    // --- Gather Data (Same logic as before) ---
    $posts_with_backup = get_posts([
        'post_type' => 'any',
        'post_status' => 'any',
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => amendor_get_backup_meta_key(),
                'compare' => 'EXISTS'
            ]
        ],
        'fields' => 'ids'
    ]);

    $export_data = [];
    $processed_count = 0;

    foreach ($posts_with_backup as $post_id) {
        $post = get_post($post_id);
        if (!$post) {
            amendor_add_debug_log("Backup Download: Skipped Post - Post not found.", 'WARN', ['post_id' => $post_id]);
            continue;
        }

        $backups = amendor_get_elementor_backups($post_id);
        if (!empty($backups) && is_array($backups)) {
            $export_data[] = [
                'post_id' => $post_id,
                'post_title' => $post->post_title,
                'post_type' => $post->post_type,
                'backups' => $backups
            ];
            $processed_count++;
        } else {
            amendor_add_debug_log("Backup Download: No valid backup meta found for Post.", 'DEBUG', ['post_id' => $post_id]);
        }
    }

    // --- Prepare and Send File ---
    if (empty($export_data)) {
        amendor_add_debug_log("Backup Download: No data to export (checked on admin_init).", 'INFO');
        // Redirect back with an error message instead of trying to output headers
        $redirect_url = add_query_arg(['page' => amendor_get_admin_parent_slug(), 'amendor_notice' => 'no_backup_data'], admin_url('admin.php'));
        wp_redirect(esc_url_raw($redirect_url));
        exit;
    } else {
        $filename = 'amendor-backups-' . date('Ymd-His') . '.json';
        amendor_add_debug_log("Backup Download: Exporting posts (via admin_init).", 'INFO', ['count' => $processed_count, 'filename' => $filename]);

        // Set headers to force download
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $json_export = amendor_encode_elementor_data($export_data, ['operation' => 'backup_export', 'count' => $processed_count]);
        if ($json_export === false) {
            wp_die(__('Unable to generate the backup export file because JSON encoding failed.', 'amendor'), __('Export Error', 'amendor'), 500);
        }

        // Output the JSON data
        echo $json_export;
        exit; // IMPORTANT: Stop script execution after sending the file
    }
}
