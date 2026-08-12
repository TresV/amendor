<?php

/**
 * Batched Search Backend (candidate scan, transient cache, pagination payloads)
 *
 * @package Amendor
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get supported post types for the plugin.
 *
 * @return array
 */
function amendor_get_supported_post_types()
{
    $supported_post_types = get_post_types(['public' => true], 'names');
    return apply_filters('amendor_supported_post_types', $supported_post_types);
}

/**
 * Get available Elementor widgets for filtering.
 *
 * @return array
 */
function amendor_get_available_widgets()
{
    $available_widgets = [];

    if (class_exists('\Elementor\Plugin') && isset(\Elementor\Plugin::$instance->widgets_manager) && method_exists(\Elementor\Plugin::$instance->widgets_manager, 'get_widget_types')) {
        $widgets_manager = \Elementor\Plugin::$instance->widgets_manager;
        $widget_types = $widgets_manager->get_widget_types();
        foreach ($widget_types as $widget_type) {
            $available_widgets[$widget_type->get_name()] = $widget_type->get_title();
        }
        asort($available_widgets);
    } else {
        amendor_add_debug_log("Elementor Widgets Manager not found or method unavailable. Widget filtering disabled.", 'WARN');
    }

    return $available_widgets;
}

/**
 * Normalize selected widgets for comparisons and cache keys.
 *
 * @param array $selected_widgets Selected widgets.
 * @return array
 */
function amendor_normalize_selected_widgets(array $selected_widgets)
{
    $selected_widgets = array_values(array_filter(array_map('strval', $selected_widgets)));
    sort($selected_widgets);
    return $selected_widgets;
}

/**
 * Build a stable signature for a search request.
 *
 * @param string $search Search term.
 * @param string $search_mode Search mode.
 * @param array  $selected_widgets Selected widget filters.
 * @param array  $content_sources Selected content sources.
 * @param array  $supported_post_types Supported post types.
 * @return string
 */
function amendor_get_search_signature($search, $search_mode, array $selected_widgets, array $content_sources, array $supported_post_types)
{
    return wp_hash(wp_json_encode([
        'search' => (string) $search,
        'search_mode' => (string) $search_mode,
        'selected_widgets' => amendor_normalize_selected_widgets($selected_widgets),
        'content_sources' => amendor_normalize_content_sources($content_sources),
        'supported_post_types' => array_values($supported_post_types),
    ]));
}

/**
 * Build the transient key for a search cache entry.
 *
 * @param string $cache_key Search cache key.
 * @return string
 */
function amendor_get_search_cache_transient_key($cache_key)
{
    return 'amendor_search_cache_' . sanitize_key($cache_key);
}

/**
 * Fetch candidate post IDs for searching.
 *
 * @param array $supported_post_types Supported post types.
 * @param array $content_sources Selected content sources.
 * @return array
 */
function amendor_get_search_candidate_post_ids(array $supported_post_types, array $content_sources)
{
    $content_sources = amendor_normalize_content_sources($content_sources);
    $query_args = [
        'post_type' => $supported_post_types,
        'post_status' => ['publish', 'draft', 'private'],
        'posts_per_page' => -1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'fields' => 'ids',
        'no_found_rows' => true,
    ];

    if ($content_sources === ['elementor']) {
        $query_args['meta_query'] = [
            'relation' => 'AND',
            ['key' => '_elementor_data', 'compare' => 'EXISTS'],
            ['key' => '_elementor_data', 'value' => '[]', 'compare' => '!='],
            ['key' => '_elementor_data', 'value' => '', 'compare' => '!='],
        ];
    }

    return get_posts($query_args);
}

/**
 * Load a cached search payload if it is valid for the current user and signature.
 *
 * @param string $cache_key Cache key.
 * @param string $signature Search signature.
 * @return array|null
 */
function amendor_get_valid_search_cache($cache_key, $signature)
{
    if (!is_string($cache_key) || $cache_key === '') {
        return null;
    }

    $cache = get_transient(amendor_get_search_cache_transient_key($cache_key));
    if (!is_array($cache)) {
        return null;
    }

    if (($cache['user_id'] ?? 0) !== get_current_user_id()) {
        return null;
    }

    if (($cache['signature'] ?? '') !== $signature) {
        return null;
    }

    return $cache;
}

/**
 * Store a search cache payload.
 *
 * @param string $cache_key Cache key.
 * @param array  $cache Cache payload.
 * @return bool
 */
function amendor_set_search_cache($cache_key, array $cache)
{
    return set_transient(amendor_get_search_cache_transient_key($cache_key), $cache, HOUR_IN_SECONDS);
}

/**
 * Build a result entry for a matched post.
 *
 * @param WP_Post $post Post object.
 * @param array  $changes_details Search changes details.
 * @return array
 */
function amendor_build_search_result_entry(WP_Post $post, array $changes_details)
{
    return [
        'ID' => (int) $post->ID,
        'title' => (string) $post->post_title,
        'type' => (string) $post->post_type,
        'matches' => array_slice($changes_details['diffs'], 0, 10),
        'backup_count' => amendor_get_post_backup_count($post->ID),
    ];
}

/**
 * Process one batch of candidate posts for search.
 *
 * @param array  $candidate_post_ids Candidate post IDs.
 * @param int    $offset Batch offset.
 * @param int    $limit Batch size.
 * @param string $search Search term.
 * @param string $search_mode Search mode.
 * @param array  $selected_widgets Selected widgets.
 * @param array  $content_sources Selected content sources.
 * @return array
 */
function amendor_process_search_batch(array $candidate_post_ids, $offset, $limit, $search, $search_mode, array $selected_widgets, array $content_sources)
{
    $result = [
        'matched_results' => [],
        'scanned_count' => 0,
        'matched_count' => 0,
    ];

    $batch_ids = array_slice($candidate_post_ids, max(0, (int) $offset), max(1, (int) $limit));
    foreach ($batch_ids as $current_post_id) {
        $result['scanned_count']++;
        $post = get_post($current_post_id);
        if (!$post instanceof WP_Post) {
            amendor_add_debug_log('Search batch skipped missing post.', 'WARN', ['post_id' => $current_post_id]);
            continue;
        }

        $analysis = amendor_analyze_post_content_state(
            amendor_build_post_content_state($post),
            $search,
            '',
            $search_mode,
            false,
            $selected_widgets,
            $content_sources
        );
        $changes_details = $analysis['changes_details'];

        if (!empty($changes_details['errors'])) {
            amendor_add_debug_log('Search errors occurred in post.', 'WARN', [
                'post_id' => $current_post_id,
                'errors' => $changes_details['errors'],
                'sources' => $content_sources,
            ]);
        }

        if ($changes_details['matched_count'] > 0) {
            $result['matched_results'][] = amendor_build_search_result_entry($post, $changes_details);
            $result['matched_count']++;
            amendor_add_debug_log('Match found in post.', 'INFO', [
                'post_id' => $current_post_id,
                'title' => $post->post_title,
                'instances' => $changes_details['matched_count'],
                'sources' => $content_sources,
            ]);
        } elseif (empty($changes_details['errors'])) {
            amendor_add_debug_log('No match in post (respecting filters).', 'DEBUG', [
                'post_id' => $current_post_id,
                'title' => $post->post_title,
                'sources' => $content_sources,
            ]);
        }
    }

    return $result;
}

/**
 * Run a batched search request and persist its cache.
 *
 * @param string      $search Search term.
 * @param string      $search_mode Search mode.
 * @param array       $selected_widgets Selected widgets.
 * @param array       $content_sources Selected content sources.
 * @param array       $supported_post_types Supported post types.
 * @param string|null $cache_key Existing cache key.
 * @param bool        $reset Whether to start a new cache.
 * @return array
 */
function amendor_run_search_batch_request($search, $search_mode, array $selected_widgets, array $content_sources, array $supported_post_types, $cache_key = null, $reset = false)
{
    $selected_widgets = amendor_normalize_selected_widgets($selected_widgets);
    $content_sources = amendor_normalize_content_sources($content_sources);
    $signature = amendor_get_search_signature($search, $search_mode, $selected_widgets, $content_sources, $supported_post_types);
    $batch_size = amendor_get_default_search_batch_size();
    $user_id = get_current_user_id();

    $cache = null;
    if (!$reset && is_string($cache_key) && $cache_key !== '') {
        $cache = amendor_get_valid_search_cache($cache_key, $signature);
    }

    if (!$cache) {
        $cache_key = wp_generate_password(20, false, false);
        $candidate_post_ids = amendor_get_search_candidate_post_ids($supported_post_types, $content_sources);
        $cache = [
            'user_id' => $user_id,
            'signature' => $signature,
            'search' => $search,
            'search_mode' => $search_mode,
            'selected_widgets' => $selected_widgets,
            'content_sources' => $content_sources,
            'supported_post_types' => array_values($supported_post_types),
            'candidate_post_ids' => $candidate_post_ids,
            'matched_results' => [],
            'scanned_posts' => 0,
            'matched_posts' => 0,
            'total_candidate_posts' => count($candidate_post_ids),
            'offset' => 0,
            'completed' => false,
        ];
        amendor_add_debug_log('Candidate posts loaded for batched search.', 'INFO', [
            'candidate_count' => $cache['total_candidate_posts'],
            'batch_size' => $batch_size,
            'sources' => $content_sources,
        ]);
    }

    if (!$cache['completed']) {
        $batch_result = amendor_process_search_batch(
            $cache['candidate_post_ids'],
            $cache['offset'],
            $batch_size,
            $search,
            $search_mode,
            $selected_widgets,
            $content_sources
        );

        $cache['matched_results'] = array_merge($cache['matched_results'], $batch_result['matched_results']);
        $cache['scanned_posts'] += $batch_result['scanned_count'];
        $cache['matched_posts'] += $batch_result['matched_count'];
        $cache['offset'] += $batch_result['scanned_count'];
        $cache['completed'] = $cache['offset'] >= $cache['total_candidate_posts'];

        if ($cache['completed']) {
            unset($cache['candidate_post_ids']);
        }

        amendor_set_search_cache($cache_key, $cache);
    }

    return [
        'cache_key' => $cache_key,
        'done' => !empty($cache['completed']),
        'scanned_posts' => (int) $cache['scanned_posts'],
        'matched_posts' => (int) $cache['matched_posts'],
        'total_candidate_posts' => (int) $cache['total_candidate_posts'],
        'progress_percent' => $cache['total_candidate_posts'] > 0 ? (int) floor(($cache['scanned_posts'] / $cache['total_candidate_posts']) * 100) : 100,
        'content_sources' => $content_sources,
    ];
}

/**
 * Build paginated results output from a completed cached search.
 *
 * @param string $search Search term.
 * @param string $search_mode Search mode.
 * @param array  $selected_widgets Selected widget filters.
 * @param array  $content_sources Selected content sources.
 * @param array  $supported_post_types Supported post types.
 * @param string $cache_key Search cache key.
 * @param int    $paged Current page.
 * @param int    $results_per_page Results per page.
 * @param array  $messages Notices to append to.
 * @return array
 */
function amendor_get_cached_search_results_payload($search, $search_mode, array $selected_widgets, array $content_sources, array $supported_post_types, $cache_key, $paged, $results_per_page, array &$messages)
{
    $payload = [
        'results' => [],
        'scanned_posts' => 0,
        'matched_posts' => 0,
        'total_candidate_posts' => 0,
        'total_pages' => 0,
        'paged' => max(1, (int) $paged),
        'content_sources' => amendor_normalize_content_sources($content_sources),
    ];

    $selected_widgets = amendor_normalize_selected_widgets($selected_widgets);
    $content_sources = amendor_normalize_content_sources($content_sources);
    $signature = amendor_get_search_signature($search, $search_mode, $selected_widgets, $content_sources, $supported_post_types);
    $cache = amendor_get_valid_search_cache($cache_key, $signature);

    if (!$cache || empty($cache['completed'])) {
        $messages[] = ['type' => 'warning', 'text' => __('⚠️ Search results are no longer available. Please run the search again.', 'amendor')];
        amendor_add_debug_log('Cached search results unavailable for rendering.', 'WARN', ['cache_key' => $cache_key]);
        return $payload;
    }

    $payload['scanned_posts'] = (int) ($cache['scanned_posts'] ?? 0);
    $payload['matched_posts'] = (int) ($cache['matched_posts'] ?? 0);
    $payload['total_candidate_posts'] = (int) ($cache['total_candidate_posts'] ?? 0);
    $matched_results = isset($cache['matched_results']) && is_array($cache['matched_results']) ? $cache['matched_results'] : [];

    $payload['total_pages'] = max(1, (int) ceil($payload['matched_posts'] / $results_per_page));
    if ($payload['paged'] > $payload['total_pages']) {
        $payload['paged'] = $payload['total_pages'];
    }

    $payload['results'] = array_slice($matched_results, ($payload['paged'] - 1) * $results_per_page, $results_per_page);
    amendor_add_debug_log('Loaded cached search results for rendering.', 'INFO', [
        'cache_key' => $cache_key,
        'paged' => $payload['paged'],
        'matched' => $payload['matched_posts'],
        'sources' => $content_sources,
    ]);

    return $payload;
}

