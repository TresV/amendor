<?php

/**
 * AJAX Handler Functions
 *
 * @package Amendor
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * AJAX handler to check if any backups exist in post meta.
 */
function amendor_check_backup_callback()
{
    // Verify AJAX nonce for security
    check_ajax_referer('amendor_check_backup', 'nonce');

    // Check if user has permission
    if (!amendor_current_user_can_manage()) {
        amendor_add_debug_log('AJAX Backup Check: Permission denied.', 'WARN', ['user_id' => get_current_user_id()]);
        wp_send_json_error(['message' => __('Permission denied.', 'amendor')], 403);
        wp_die();
    }

    amendor_add_debug_log('AJAX Backup Check: Received request.', 'DEBUG');

    $selected_post_ids = isset($_POST['post_ids']) ? array_map('intval', (array) wp_unslash($_POST['post_ids'])) : [];
    $selected_post_ids = array_values(array_filter($selected_post_ids));

    $selected_without_backups = [];
    foreach ($selected_post_ids as $post_id) {
        if (amendor_get_post_backup_count($post_id) < 1) {
            $selected_without_backups[] = $post_id;
        }
    }

    amendor_add_debug_log('AJAX Backup Check: Result.', 'DEBUG', [
        'selected_count' => count($selected_post_ids),
        'without_backup_count' => count($selected_without_backups),
    ]);

    wp_send_json([
        'selected_count' => count($selected_post_ids),
        'without_backup_count' => count($selected_without_backups),
        'selected_without_backups' => $selected_without_backups,
    ]);
    wp_die(); // Required for AJAX handlers in WordPress
}

// Register both Amendor and legacy action names while the package is being renamed.
add_action('wp_ajax_amendor_check_backup', 'amendor_check_backup_callback');
add_action('wp_ajax_etp_check_backup', 'amendor_check_backup_callback');

/**
 * AJAX handler to run a batched search scan.
 */
function amendor_run_search_batch_callback()
{
    check_ajax_referer('amendor_run_search_batch', 'nonce');

    if (!amendor_current_user_can_manage()) {
        amendor_add_debug_log('AJAX Search Batch: Permission denied.', 'WARN', ['user_id' => get_current_user_id()]);
        wp_send_json_error(['message' => __('Permission denied.', 'amendor')], 403);
        wp_die();
    }

    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $search_mode = isset($_POST['search_mode']) ? sanitize_key(wp_unslash($_POST['search_mode'])) : 'partial';
    // Regex search is a Pro-only mode; the Free build strips the regex UI.
    if ('regex' === $search_mode && !ame_fs()->is__premium_only()) {
        $search_mode = 'partial';
    }
    $selected_widgets = isset($_POST['widget_types']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['widget_types'])) : [];
    $content_sources = isset($_POST['content_sources']) ? array_map('sanitize_key', (array) wp_unslash($_POST['content_sources'])) : [];
    $cache_key = isset($_POST['search_cache_key']) ? sanitize_key(wp_unslash($_POST['search_cache_key'])) : '';
    $reset = !empty($_POST['reset']);
    $allowed_fields = [];
    if (ame_fs()->is__premium_only()) {
        $allowed_fields = isset($_POST['field_keys']) ? amendor_normalize_allowed_fields(wp_unslash($_POST['field_keys'])) : [];
    }

    if ($search === '') {
        wp_send_json_error(['message' => __('Search term is required.', 'amendor')], 400);
        wp_die();
    }

    if ($search_mode === 'regex' && !amendor_is_valid_regex($search)) {
        wp_send_json_error(['message' => __('Invalid regular expression pattern.', 'amendor')], 400);
        wp_die();
    }

    $response = amendor_run_search_batch_request(
        $search,
        $search_mode,
        $selected_widgets,
        $content_sources,
        amendor_get_supported_post_types(),
        $cache_key,
        $reset,
        $allowed_fields
    );

    wp_send_json_success($response);
    wp_die();
}
add_action('wp_ajax_amendor_run_search_batch', 'amendor_run_search_batch_callback');
add_action('wp_ajax_etp_run_search_batch', 'amendor_run_search_batch_callback');

/**
 * AJAX handler to render cached search results without a full page reload.
 */
function amendor_get_search_results_callback()
{
    check_ajax_referer('amendor_get_search_results', 'nonce');

    if (!amendor_current_user_can_manage()) {
        amendor_add_debug_log('AJAX Search Results: Permission denied.', 'WARN', ['user_id' => get_current_user_id()]);
        wp_send_json_error(['message' => __('Permission denied.', 'amendor')], 403);
        wp_die();
    }

    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $search_mode = isset($_POST['search_mode']) ? sanitize_key(wp_unslash($_POST['search_mode'])) : 'partial';
    // Regex search is a Pro-only mode; the Free build strips the regex UI.
    if ('regex' === $search_mode && !ame_fs()->is__premium_only()) {
        $search_mode = 'partial';
    }
    $selected_widgets = isset($_POST['widget_types']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['widget_types'])) : [];
    $content_sources = isset($_POST['content_sources']) ? array_map('sanitize_key', (array) wp_unslash($_POST['content_sources'])) : [];
    $cache_key = isset($_POST['search_cache_key']) ? sanitize_key(wp_unslash($_POST['search_cache_key'])) : '';
    $paged = isset($_POST['paged']) ? max(1, intval(wp_unslash($_POST['paged']))) : 1;
    $results_per_page = amendor_get_search_results_per_page(isset($_POST['results_per_page']) ? wp_unslash($_POST['results_per_page']) : null);
    $messages = [];

    if ($search === '') {
        wp_send_json_error(['message' => __('Search term is required.', 'amendor')], 400);
        wp_die();
    }

    if ($search_mode === 'regex' && !amendor_is_valid_regex($search)) {
        wp_send_json_error(['message' => __('Invalid regular expression pattern.', 'amendor')], 400);
        wp_die();
    }

    $payload = amendor_get_cached_search_results_payload(
        $search,
        $search_mode,
        $selected_widgets,
        $content_sources,
        amendor_get_supported_post_types(),
        $cache_key,
        $paged,
        $results_per_page,
        $messages
    );

    $results_html = amendor_get_results_section_html([
        'preview_attempted' => false,
        'search_attempted' => true,
        'preview_results' => [],
        'results' => $payload['results'],
        'selected_ids' => [],
        'action' => 'search',
        'paged' => $payload['paged'],
        'total_pages' => $payload['total_pages'],
        'matched_posts' => $payload['matched_posts'],
        'total_candidate_posts' => $payload['total_candidate_posts'],
        'results_per_page' => $results_per_page,
        'content_sources' => $payload['content_sources'],
    ]);

    wp_send_json_success([
        'results_html' => $results_html,
        'notices_html' => amendor_get_admin_notices_html($messages),
        'paged' => $payload['paged'],
    ]);
    wp_die();
}
add_action('wp_ajax_amendor_get_search_results', 'amendor_get_search_results_callback');
add_action('wp_ajax_etp_get_search_results', 'amendor_get_search_results_callback');

/**
 * AJAX handler to run a preview request without reloading the page.
 */
function amendor_run_preview_callback()
{
    check_ajax_referer('amendor_run_preview', 'nonce');

    if (!amendor_current_user_can_manage()) {
        amendor_add_debug_log('AJAX Preview: Permission denied.', 'WARN', ['user_id' => get_current_user_id()]);
        wp_send_json_error(['message' => __('Permission denied.', 'amendor')], 403);
        wp_die();
    }

    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $replace = isset($_POST['replace']) ? sanitize_text_field(wp_unslash($_POST['replace'])) : '';
    $search_mode = isset($_POST['search_mode']) ? sanitize_key(wp_unslash($_POST['search_mode'])) : 'partial';
    $selected_widgets = isset($_POST['widget_types']) ? array_map('sanitize_text_field', (array) wp_unslash($_POST['widget_types'])) : [];
    $content_sources = isset($_POST['content_sources']) ? array_map('sanitize_key', (array) wp_unslash($_POST['content_sources'])) : [];
    $selected_ids = isset($_POST['selected_posts']) ? array_map('intval', (array) wp_unslash($_POST['selected_posts'])) : [];
    $selected_ids = array_values(array_filter($selected_ids));
    $allowed_fields = isset($_POST['field_keys']) ? amendor_normalize_allowed_fields(wp_unslash($_POST['field_keys'])) : [];

    $messages = [];
    $preview_results = amendor_handle_preview_action(
        'preview_selected',
        $selected_ids,
        $search,
        $replace,
        $search_mode,
        $selected_widgets,
        $content_sources,
        amendor_get_supported_post_types(),
        $messages,
        $allowed_fields
    );

    $results_html = amendor_get_results_section_html([
        'preview_attempted' => true,
        'search_attempted' => false,
        'preview_results' => $preview_results,
        'results' => [],
        'selected_ids' => $selected_ids,
        'action' => 'preview_selected',
        'paged' => 1,
        'total_pages' => 0,
        'matched_posts' => count($preview_results),
        'total_candidate_posts' => 0,
        'content_sources' => amendor_normalize_content_sources($content_sources),
    ]);

    wp_send_json_success([
        'results_html' => $results_html,
        'notices_html' => amendor_get_admin_notices_html($messages),
        'preview_count' => count($preview_results),
    ]);
    wp_die();
}
add_action('wp_ajax_amendor_run_preview', 'amendor_run_preview_callback');
add_action('wp_ajax_etp_run_preview', 'amendor_run_preview_callback');
