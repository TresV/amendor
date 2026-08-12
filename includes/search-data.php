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

