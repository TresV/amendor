<?php

/**
 * Search & Replace Analysis Engine and Logging
 *
 * @package Amendor
 */

if (!defined('ABSPATH')) {
    exit;
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
    // Clamp terms once here; every scan/preview/replace path funnels through this method.
    $search = amendor_limit_search_term($search);
    $replace = amendor_limit_search_term($replace);
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
 * Recursively search or replace values inside Elementor data.
 *
 * @param mixed  $data             Elementor data.
 * @param string $search           Search term.
 * @param string $replace          Replacement term.
 * @param string $search_mode      Search mode.
 * @param bool   $perform_replace  Whether to replace.
 * @param array  $changes_details  Summary of changes.
 * @param array  $selected_widgets Optional widget filters.
 * @param string $widget_context   Current Elementor widget type context.
 * @return mixed
 */
function amendor_process_elementor_data_recursive($data, $search, $replace, $search_mode, $perform_replace, array &$changes_details, array $selected_widgets = [], $widget_context = '')
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
                            // Bound PCRE resource limits to avoid catastrophic backtracking on user input.
                            $prev_backtrack = ini_get('pcre.backtrack_limit');
                            $prev_recursion = ini_get('pcre.recursion_limit');
                            ini_set('pcre.backtrack_limit', '1000000');
                            ini_set('pcre.recursion_limit', '100000');
                            $potential_new_value = @preg_replace($pattern, $replace, $original_value);
                            if (false !== $prev_backtrack) {
                                ini_set('pcre.backtrack_limit', (string) $prev_backtrack);
                            }
                            if (false !== $prev_recursion) {
                                ini_set('pcre.recursion_limit', (string) $prev_recursion);
                            }
                            if ($potential_new_value === null && preg_last_error() !== PREG_NO_ERROR) {
                                /* translators: %s: PCRE error message. */
                                throw new Exception(sprintf(__('Regex replacement error: %s', 'amendor'), preg_last_error_msg()));
                            }
                            $new_value = $potential_new_value;
                        } elseif ($match_result === false) {
                            /* translators: %s: PCRE error message. */
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
                    'widget' => $widget_context,
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
                    'widget' => $widget_context,
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
        $child_context = $widget_context;
        if ($element_type === 'widget' && !empty($widget_type)) {
            $child_context = (string) $widget_type;
        }

        foreach ($data as $key => $value) {
            if ($key === 'settings' && $is_widget_or_element && !$process_this_element_settings) {
                $modified_data[$key] = $value;
                continue;
            }

            $modified_data[$key] = amendor_process_elementor_data_recursive($value, $search, $replace, $search_mode, $perform_replace, $changes_details, $selected_widgets, $child_context);
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

        // Clear only the affected post's CSS cache, never the whole site.
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
    }
}

/**
 * Recursively truncate long values inside a debug-log context.
 *
 * @param mixed $context Context payload.
 * @return mixed
 */
function amendor_redact_log_context($context)
{
    if (is_string($context)) {
        return strlen($context) > 1000 ? substr($context, 0, 1000) . '…[truncated]' : $context;
    }

    if (is_array($context)) {
        $out = [];
        foreach ($context as $key => $value) {
            $out[$key] = amendor_redact_log_context($value);
        }
        return $out;
    }

    return $context;
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

    // Bound the message length and redact long context values before persisting.
    $message = (string) $message;
    if (strlen($message) > 1000) {
        $message = substr($message, 0, 1000) . '…[truncated]';
    }
    if ($context !== null) {
        $context = amendor_redact_log_context($context);
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

    // Retention is enforced by the daily log pruning cron job.
}
