<?php

/**
 * Admin Form Action Handlers (search, preview, replace, restore)
 *
 * @package Amendor
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle restore action requests.
 *
 * @param string $action Current action.
 * @param array  $messages Notices to append to.
 * @return void
 */
function amendor_handle_restore_action($action, array &$messages)
{
    if ($action !== 'restore' || empty($_POST['restore_post_id'])) {
        return;
    }

    if (!amendor_current_user_can_manage()) {
        $messages[] = ['type' => 'error', 'text' => __('❌ You do not have permission to restore backups.', 'amendor')];
        amendor_add_debug_log('Restore Error: Permission denied.', 'ERROR');
        return;
    }

    $post_id_to_restore = intval($_POST['restore_post_id']);
    if (isset($_POST['amendor_restore_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['amendor_restore_nonce'])), 'amendor_restore_action_' . $post_id_to_restore)) {
        $backup_index = isset($_POST['backup_index']) ? intval($_POST['backup_index']) : 0;
        amendor_add_debug_log("Attempting Restore Action", 'INFO', ['post_id' => $post_id_to_restore, 'index' => $backup_index]);

        if (amendor_restore_elementor_backup($post_id_to_restore, $backup_index)) {
            /* translators: %d: Post ID. */
            $messages[] = ['type' => 'success', 'text' => sprintf(__('✅ Post ID %d successfully restored from backup.', 'amendor'), $post_id_to_restore)];
            amendor_add_debug_log("Restore successful.", 'INFO', ['post_id' => $post_id_to_restore]);
        } else {
            /* translators: %d: Post ID. */
            $messages[] = ['type' => 'error', 'text' => sprintf(__('❌ Failed to restore Post ID %d from backup. Check debug logs or backup data validity.', 'amendor'), $post_id_to_restore)];
        }
    } else {
        $messages[] = ['type' => 'error', 'text' => __('❌ Security check failed for restore action. Please try again.', 'amendor')];
        amendor_add_debug_log("Restore Error: Nonce verification failed.", 'ERROR');
    }

    amendor_add_debug_log("====== Restore Action Finished ======", 'DEBUG');
}

/**
 * Handle the one-click undo action (restore the last replace operation).
 *
 * @param string $action Current action.
 * @param array  $messages Notices to append to.
 * @return void
 */
function amendor_handle_undo_action($action, array &$messages)
{
    if ($action !== 'undo') {
        return;
    }

    if (!amendor_current_user_can_manage()) {
        $messages[] = ['type' => 'error', 'text' => __('❌ You do not have permission to undo replacements.', 'amendor')];
        amendor_add_debug_log('Undo Error: Permission denied.', 'ERROR');
        return;
    }

    if (!isset($_POST['amendor_undo_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['amendor_undo_nonce'])), 'amendor_undo_action')) {
        $messages[] = ['type' => 'error', 'text' => __('❌ Security check failed. Please try again.', 'amendor')];
        amendor_add_debug_log('Undo Error: Nonce verification failed.', 'ERROR');
        return;
    }

    amendor_add_debug_log("Attempting Undo Action...", 'INFO');

    global $wpdb;
    $history_table = amendor_get_history_table_name();

    $last_timestamp = $wpdb->get_var("SELECT MAX(timestamp) FROM {$history_table}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    if (!$last_timestamp) {
        $messages[] = ['type' => 'info', 'text' => __('ℹ️ Nothing to undo yet. No replacement operations have been recorded.', 'amendor')];
        amendor_add_debug_log("Undo Action Finished: no history.", 'DEBUG');
        return;
    }

    $post_ids = $wpdb->get_col(
        $wpdb->prepare("SELECT DISTINCT post_id FROM {$history_table} WHERE timestamp = %s", $last_timestamp)
    ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    if (empty($post_ids)) {
        $messages[] = ['type' => 'info', 'text' => __('ℹ️ Nothing to undo.', 'amendor')];
        amendor_add_debug_log("Undo Action Finished: no posts to undo.", 'DEBUG');
        return;
    }

    $restored = 0;
    $failed = 0;
    foreach ($post_ids as $post_id) {
        if (amendor_restore_elementor_backup((int) $post_id, 0)) {
            $restored++;
            amendor_add_debug_log('Undo restored post from backup.', 'INFO', ['post_id' => (int) $post_id]);
        } else {
            $failed++;
            amendor_add_debug_log('Undo failed to restore post.', 'WARN', ['post_id' => (int) $post_id]);
        }
    }

    if ($restored > 0) {
        /* translators: %d: Number of restored posts. */
        $messages[] = ['type' => 'success', 'text' => sprintf(__('↩️ Undo complete: %1$d post(s) restored to the state before the last replacement.', 'amendor'), $restored)];
        if ($failed > 0) {
            /* translators: %d: Number of failed posts. */
            $messages[] = ['type' => 'warning', 'text' => sprintf(__('⚠️ %d post(s) could not be restored (no valid backup).', 'amendor'), $failed)];
        }
    } else {
        $messages[] = ['type' => 'warning', 'text' => __('⚠️ Undo could not restore any posts (no valid backups found for the last operation).', 'amendor')];
    }

    amendor_add_debug_log("====== Undo Action Finished ======", 'DEBUG');
}

/**
 * Handle search action requests.
 *
 * @param string $action Current action.
 * @param string $search Search term.
 * @param string $search_mode Search mode.
 * @param array  $selected_widgets Selected widgets.
 * @param array  $content_sources Selected content sources.
 * @param array  $supported_post_types Supported post types.
 * @param int    $paged Current page.
 * @param int    $results_per_page Results per page.
 * @param array  $messages Notices to append to.
 * @return array
 */
function amendor_handle_search_action($action, $search, $search_mode, array $selected_widgets, array $content_sources, array $supported_post_types, $paged, $results_per_page, array &$messages)
{
    $payload = [
        'results' => [],
        'scanned_posts' => 0,
        'matched_posts' => 0,
        'total_candidate_posts' => 0,
        'total_pages' => 0,
        'paged' => $paged,
        'content_sources' => amendor_normalize_content_sources($content_sources),
    ];

    if ($action !== 'search' || $search === '') {
        return $payload;
    }

    if (!amendor_current_user_can_manage()) {
        $messages[] = ['type' => 'error', 'text' => __('❌ You do not have permission to run searches.', 'amendor')];
        amendor_add_debug_log('Search Error: Permission denied.', 'ERROR');
        return $payload;
    }

    amendor_add_debug_log("Attempting Search Action...", 'INFO');
    if (!isset($_POST['amendor_search_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['amendor_search_nonce'])), 'amendor_search_action')) {
        $messages[] = ['type' => 'error', 'text' => __('❌ Security check failed. Please try searching again.', 'amendor')];
        amendor_add_debug_log("Security check (Nonce) failed for search action.", 'ERROR');
        amendor_add_debug_log("====== Search Action Finished ======", 'DEBUG');
        return $payload;
    }

    amendor_add_debug_log("Nonce verified. Search proceeding.", 'DEBUG', [
        'search' => $search,
        'mode' => $search_mode,
        'widgets' => !empty($selected_widgets) ? $selected_widgets : 'None',
        'sources' => $content_sources,
    ]);

    $selected_widgets = amendor_normalize_selected_widgets($selected_widgets);
    $content_sources = amendor_normalize_content_sources($content_sources);

    if ($search_mode === 'regex' && !amendor_is_valid_regex($search)) {
        /* translators: %s: The invalid regular expression pattern. */
        $messages[] = ['type' => 'error', 'text' => sprintf(__('❌ Invalid regular expression pattern provided for search: %s. Please check syntax.', 'amendor'), '<code>' . esc_html($search) . '</code>')];
        amendor_add_debug_log("Search aborted: Invalid regex pattern.", 'ERROR', ['pattern' => $search, 'sources' => $content_sources]);
        amendor_add_debug_log("====== Search Action Finished ======", 'DEBUG');
        return $payload;
    }

    amendor_store_search_history($search);

    $cache_key = isset($_POST['search_cache_key']) ? sanitize_key(wp_unslash($_POST['search_cache_key'])) : '';
    $signature = amendor_get_search_signature($search, $search_mode, $selected_widgets, $content_sources, $supported_post_types);
    $cache = amendor_get_valid_search_cache($cache_key, $signature);

    if (!$cache) {
        // No AJAX search cache available: run a synchronous full scan via the batched backend.
        $fallback_cache_key = '';
        $is_first_batch = true;
        $batch = [];

        do {
            $batch = amendor_run_search_batch_request(
                $search,
                $search_mode,
                $selected_widgets,
                $content_sources,
                $supported_post_types,
                $fallback_cache_key,
                $is_first_batch
            );
            $is_first_batch = false;
            $fallback_cache_key = $batch['cache_key'];
        } while (empty($batch['done']));

        if ((int) $batch['total_candidate_posts'] === 0) {
            amendor_add_debug_log('No candidate posts found for search.', 'INFO', ['sources' => $content_sources]);
            $messages[] = ['type' => 'info', 'text' => __('ℹ️ No posts matched your search criteria.', 'amendor')];
            amendor_add_debug_log("====== Search Action Finished ======", 'DEBUG');
            return $payload;
        }

        amendor_add_debug_log('Finished fallback full candidate scan for search.', 'DEBUG', [
            'scanned' => $batch['scanned_posts'],
            'matched' => $batch['matched_posts'],
            'sources' => $content_sources,
        ]);

        $payload = amendor_get_cached_search_results_payload(
            $search,
            $search_mode,
            $selected_widgets,
            $content_sources,
            $supported_post_types,
            $fallback_cache_key,
            $paged,
            $results_per_page,
            $messages
        );

        amendor_add_debug_log("====== Search Action Finished ======", 'DEBUG');

        return $payload;
    }

    if ($cache) {
        $payload = amendor_get_cached_search_results_payload(
            $search,
            $search_mode,
            $selected_widgets,
            $content_sources,
            $supported_post_types,
            $cache_key,
            $paged,
            $results_per_page,
            $messages
        );
    }

    amendor_add_debug_log("====== Search Action Finished ======", 'DEBUG');

    return $payload;
}

/**
 * Handle preview action requests.
 *
 * @param string $action Current action.
 * @param array  $selected_ids Selected post IDs.
 * @param string $search Search term.
 * @param string $replace Replacement term.
 * @param string $search_mode Search mode.
 * @param array  $selected_widgets Selected widgets.
 * @param array  $content_sources Selected content sources.
 * @param array  $supported_post_types Supported post types.
 * @param array  $messages Notices to append to.
 * @return array
 */
function amendor_handle_preview_action($action, array $selected_ids, $search, $replace, $search_mode, array $selected_widgets, array $content_sources, array $supported_post_types, array &$messages)
{
    $preview_results = [];

    if ($action !== 'preview_selected' || empty($selected_ids) || $search === '') {
        return $preview_results;
    }

    if (!amendor_current_user_can_manage()) {
        $messages[] = ['type' => 'error', 'text' => __('❌ You do not have permission to preview changes.', 'amendor')];
        amendor_add_debug_log('Preview Error: Permission denied.', 'ERROR');
        return $preview_results;
    }

    amendor_add_debug_log("Attempting Preview Action...", 'INFO');
    if (!isset($_POST['amendor_preview_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['amendor_preview_nonce'])), 'amendor_preview_action')) {
        $messages[] = ['type' => 'error', 'text' => __('❌ Security check failed. Please try the preview again.', 'amendor')];
        amendor_add_debug_log("Security check (Nonce) failed for preview action.", 'ERROR');
        amendor_add_debug_log("====== Preview Action Finished ======", 'DEBUG');
        return $preview_results;
    }

    amendor_add_debug_log("Nonce verified. Preview proceeding.", 'DEBUG', [
        'search' => $search,
        'replace' => $replace,
        'mode' => $search_mode,
        'widgets' => !empty($selected_widgets) ? $selected_widgets : 'None',
        'sources' => $content_sources,
        'post_ids' => $selected_ids
    ]);

    $selected_widgets = amendor_normalize_selected_widgets($selected_widgets);
    $content_sources = amendor_normalize_content_sources($content_sources);

    if ($search_mode === 'regex' && !amendor_is_valid_regex($search)) {
        /* translators: %s: The invalid regular expression pattern. */
        $messages[] = ['type' => 'error', 'text' => sprintf(__('❌ Invalid regular expression pattern for preview: %s. Please check syntax.', 'amendor'), '<code>' . esc_html($search) . '</code>')];
        amendor_add_debug_log("Preview aborted: Invalid regex pattern.", 'ERROR', ['pattern' => $search, 'sources' => $content_sources]);
        amendor_add_debug_log("====== Preview Action Finished ======", 'DEBUG');
        return $preview_results;
    }

    /* translators: %d: Number of selected posts. */
    $messages[] = ['type' => 'info', 'text' => sprintf(__('👁️ Generating preview (Dry Run) for %d selected post(s). No changes will be saved.', 'amendor'), count($selected_ids))];
    $processed_preview_count = 0;

    foreach ($selected_ids as $post_id) {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || !in_array($post->post_type, $supported_post_types, true) || !in_array($post->post_status, ['publish', 'draft', 'private'], true)) {
            amendor_add_debug_log("Preview skipped: Invalid post.", 'WARN', ['post_id' => $post_id, 'reason' => 'Not found, unsupported type, or invalid status.']);
            continue;
        }

        $analysis = amendor_analyze_post_content_state(
            amendor_build_post_content_state($post),
            $search,
            $replace,
            $search_mode,
            false,
            $selected_widgets,
            $content_sources
        );
        $changes_details = $analysis['changes_details'];

        if (!empty($changes_details['errors'])) {
            amendor_add_debug_log('Preview errors occurred in post.', 'WARN', [
                'post_id' => $post_id,
                'errors' => $changes_details['errors'],
                'sources' => $content_sources,
            ]);
        }

        if ($changes_details['matched_count'] > 0 && !empty($changes_details['diffs'])) {
            $preview_results[] = amendor_build_search_result_entry($post, $changes_details);
            $processed_preview_count++;
            amendor_add_debug_log('Preview generated for post.', 'INFO', [
                'post_id' => $post_id,
                'predicted_matches' => $changes_details['matched_count'],
                'sources' => $content_sources,
            ]);
        } elseif (empty($changes_details['errors'])) {
            amendor_add_debug_log('No changes predicted in post for preview (respecting filters).', 'DEBUG', [
                'post_id' => $post_id,
                'sources' => $content_sources,
            ]);
        }
    }

    if ($processed_preview_count === 0) {
        $has_errors = !empty(array_filter($messages, static fn($message) => $message['type'] === 'error'));
        if (!$has_errors) {
            $messages[] = ['type' => 'info', 'text' => __('ℹ️ No changes were predicted for the selected posts based on the provided terms and filters.', 'amendor')];
        }
    }

    amendor_add_debug_log("====== Preview Action Finished ======", 'DEBUG');
    return $preview_results;
}

/**
 * Handle replace action requests.
 *
 * @param string $action Current action.
 * @param array  $selected_ids Selected post IDs.
 * @param string $search Search term.
 * @param string $replace Replacement term.
 * @param string $search_mode Search mode.
 * @param array  $bulk_search Bulk search terms.
 * @param array  $bulk_replace Bulk replace terms.
 * @param array  $selected_widgets Selected widgets.
 * @param array  $content_sources Selected content sources.
 * @param array  $supported_post_types Supported post types.
 * @param array  $messages Notices to append to.
 * @return void
 */
function amendor_handle_replace_action($action, array $selected_ids, $search, $replace, $search_mode, array $bulk_search, array $bulk_replace, array $selected_widgets, array $content_sources, array $supported_post_types, array &$messages)
{
    if ($action !== 'replace_selected' || empty($selected_ids)) {
        return;
    }

    if (!amendor_current_user_can_manage()) {
        $messages[] = ['type' => 'error', 'text' => __('❌ You do not have permission to run replacements.', 'amendor')];
        amendor_add_debug_log('Replace Error: Permission denied.', 'ERROR');
        return;
    }

    amendor_add_debug_log("Attempting Replace Action...", 'INFO');
    if (!isset($_POST['amendor_replace_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['amendor_replace_nonce'])), 'amendor_replace_action')) {
        $messages[] = ['type' => 'error', 'text' => __('❌ Security check failed. Please try the replacement again.', 'amendor')];
        amendor_add_debug_log("Security check (Nonce) failed for replace action.", 'ERROR');
        amendor_add_debug_log("====== Replace Action Finished ======", 'DEBUG');
        return;
    }

    $selected_widgets = amendor_normalize_selected_widgets($selected_widgets);
    $content_sources = amendor_normalize_content_sources($content_sources);

    amendor_add_debug_log("Nonce verified. Replace proceeding.", 'DEBUG', ['post_ids' => $selected_ids, 'sources' => $content_sources]);

    $pairs_to_process = [];
    $is_bulk_operation = false;
    $filtered_bulk_search = array_filter($bulk_search, static fn($term) => $term !== '');

    if (!empty($filtered_bulk_search)) {
        if (count($bulk_search) !== count($bulk_replace)) {
            $messages[] = ['type' => 'error', 'text' => __('❌ Bulk Replace Error: The number of search terms does not match the number of replace terms. Operation cancelled.', 'amendor')];
            amendor_add_debug_log("Bulk Replace Error: Mismatched count of search/replace pairs.", 'ERROR', ['search_count' => count($bulk_search), 'replace_count' => count($bulk_replace)]);
        } else {
            $is_bulk_operation = true;
            for ($i = 0; $i < count($bulk_search); $i++) {
                if ($bulk_search[$i] !== '') {
                    $pairs_to_process[] = ['search' => $bulk_search[$i], 'replace' => $bulk_replace[$i] ?? ''];
                }
            }

            if (empty($pairs_to_process)) {
                $messages[] = ['type' => 'warning', 'text' => __('⚠️ No valid bulk search terms provided (all were empty). Operation cancelled.', 'amendor')];
                amendor_add_debug_log("Bulk Replace: No non-empty search terms found in pairs.", 'WARN');
            } else {
                amendor_add_debug_log("Performing Bulk Replace.", 'INFO', ['pair_count' => count($pairs_to_process), 'sources' => $content_sources]);
            }
        }
    } elseif ($search !== '') {
        $pairs_to_process[] = ['search' => $search, 'replace' => $replace];
        amendor_add_debug_log("Performing Single Replace.", 'INFO', ['search' => $search, 'replace' => $replace, 'sources' => $content_sources]);
    } else {
        $messages[] = ['type' => 'warning', 'text' => __('⚠️ No search term provided (single or bulk). Replacement operation cancelled.', 'amendor')];
        amendor_add_debug_log("Replace Action Error: No search term provided.", 'WARN');
    }

    if (empty($pairs_to_process)) {
        amendor_add_debug_log("====== Replace Action Finished ======", 'DEBUG');
        return;
    }

    if ($search_mode === 'regex') {
        foreach ($pairs_to_process as $index => $pair) {
            if (!amendor_is_valid_regex($pair['search'])) {
                /* translators: 1: Pair number, 2: The invalid regular expression pattern. */
                $messages[] = ['type' => 'error', 'text' => sprintf(__('❌ Invalid regular expression pattern found in pair #%1$d: %2$s. Replacement operation aborted.', 'amendor'), $index + 1, '<code>' . esc_html($pair['search']) . '</code>')];
                amendor_add_debug_log("Replace aborted: Invalid regex pattern in pair.", 'ERROR', ['index' => $index + 1, 'pattern' => $pair['search'], 'sources' => $content_sources]);
                amendor_add_debug_log("====== Replace Action Finished ======", 'DEBUG');
                return;
            }
        }
    }

    $global_replaced_posts_count = 0;
    $global_total_changes_made = 0;
    $current_user_id = get_current_user_id();

    amendor_add_debug_log("Processing replacement for selected posts.", 'INFO', ['count' => count($selected_ids), 'sources' => $content_sources]);

    foreach ($selected_ids as $post_id) {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post || !in_array($post->post_type, $supported_post_types, true) || !in_array($post->post_status, ['publish', 'draft', 'private'], true)) {
            amendor_add_debug_log("Replace skipped: Invalid post.", 'WARN', ['post_id' => $post_id, 'reason' => 'Not found, unsupported type, or invalid status.']);
            continue;
        }

        $snapshot = amendor_build_post_backup_snapshot($post_id);
        if (!is_array($snapshot) || !amendor_create_post_backup($post_id, $snapshot)) {
            amendor_add_debug_log("Replace aborted for post: Failed to create backup.", 'ERROR', ['post_id' => $post_id]);
            /* translators: %d: Post ID. */
            $messages[] = ['type' => 'error', 'text' => sprintf(__('❌ Failed to create backup for Post ID %d. Replacement skipped for this post.', 'amendor'), $post_id)];
            continue;
        }

        amendor_add_debug_log("Backup created for post.", 'INFO', ['post_id' => $post_id]);

        $post_modified_by_any_pair = false;
        $total_changes_in_post_across_pairs = 0;
        $current_state = amendor_build_post_content_state($post);

        foreach ($pairs_to_process as $pair_index => $pair) {
            $pair_analysis = amendor_analyze_post_content_state(
                $current_state,
                $pair['search'],
                $pair['replace'],
                $search_mode,
                true,
                $selected_widgets,
                $content_sources
            );
            $changes_details_for_pair = $pair_analysis['changes_details'];
            $current_state = $pair_analysis['state'];

            if (!empty($changes_details_for_pair['errors'])) {
                amendor_add_debug_log("Replace Errors occurred during pair processing.", 'WARN', [
                    'post_id' => $post_id,
                    'pair_index' => $pair_index + 1,
                    'search' => $pair['search'],
                    'errors' => $changes_details_for_pair['errors'],
                    'sources' => $content_sources,
                ]);
            }

            if ($changes_details_for_pair['replaced_count'] > 0) {
                $post_modified_by_any_pair = true;
                $total_changes_in_post_across_pairs += $changes_details_for_pair['replaced_count'];
                amendor_add_debug_log("Replacements made by pair.", 'DEBUG', [
                    'post_id' => $post_id,
                    'pair_index' => $pair_index + 1,
                    'search' => $pair['search'],
                    'replace' => $pair['replace'],
                    'count' => $changes_details_for_pair['replaced_count'],
                    'sources' => $content_sources,
                ]);
            } elseif (empty($changes_details_for_pair['errors'])) {
                amendor_add_debug_log("No replacements made by pair.", 'DEBUG', [
                    'post_id' => $post_id,
                    'pair_index' => $pair_index + 1,
                    'search' => $pair['search'],
                    'replace' => $pair['replace'],
                    'sources' => $content_sources,
                ]);
            }
        }

        if (!$post_modified_by_any_pair) {
            amendor_add_debug_log("No modifications needed for post after processing all pairs.", 'INFO', ['post_id' => $post_id]);
            continue;
        }

        $native_update = [
            'ID' => $post_id,
            'post_title' => (string) $current_state['post_title'],
            'post_content' => (string) $current_state['post_content'],
            'post_excerpt' => (string) $current_state['post_excerpt'],
        ];
        $update_result = wp_update_post(wp_slash($native_update), true);
        if (is_wp_error($update_result)) {
            amendor_add_debug_log('ERROR updating native post fields after processing pairs.', 'ERROR', ['post_id' => $post_id, 'error' => $update_result->get_error_message()]);
            /* translators: 1: Post ID, 2: WordPress error message. */
            $messages[] = ['type' => 'error', 'text' => sprintf(__('❌ Failed to save post field changes for Post ID %1$d. Backup was created. Error: %2$s', 'amendor'), $post_id, esc_html($update_result->get_error_message()))];
            continue;
        }

        if (array_key_exists('elementor_data', $current_state)) {
            if (is_array($current_state['elementor_data'])) {
                $encoded_current_data_state = amendor_encode_elementor_data($current_state['elementor_data'], ['post_id' => $post_id, 'operation' => 'replace_selected']);
                if ($encoded_current_data_state === false) {
                    /* translators: %d: Post ID. */
                    $messages[] = ['type' => 'error', 'text' => sprintf(__('❌ Failed to encode updated Elementor data for Post ID %d. Backup was created and no changes were saved.', 'amendor'), $post_id)];
                    continue;
                }

                $meta_update = update_post_meta($post_id, '_elementor_data', wp_slash($encoded_current_data_state));
                if ($meta_update === false) {
                    $db_error = $GLOBALS['wpdb']->last_error;
                    amendor_add_debug_log("ERROR updating post meta after processing pairs.", 'ERROR', ['post_id' => $post_id, 'db_error' => $db_error]);
                    /* translators: 1: Post ID, 2: Database error message. */
                    $messages[] = ['type' => 'error', 'text' => sprintf(__('❌ Failed to save Elementor changes for Post ID %1$d. Database error occurred. Backup was created. Error: %2$s', 'amendor'), $post_id, esc_html($db_error))];
                    continue;
                }
                amendor_clear_elementor_cache_for_post($post_id);
            } elseif ($current_state['elementor_data'] === null && amendor_content_sources_include_elementor($content_sources)) {
                delete_post_meta($post_id, '_elementor_data');
            }
        }

        amendor_add_debug_log("Post content updated successfully.", 'INFO', ['post_id' => $post_id, 'total_changes' => $total_changes_in_post_across_pairs, 'sources' => $content_sources]);

        /* translators: %s: Comma-separated list of content sources. */
        $source_suffix = sprintf(__(' [Sources: %s]', 'amendor'), amendor_format_content_sources_summary($content_sources));
        $log_search = $is_bulk_operation ? __('(Bulk Operation)', 'amendor') . $source_suffix : $search . $source_suffix;
        /* translators: %d: Number of search/replace pairs. */
        $log_replace = $is_bulk_operation ? sprintf(__('%d pairs applied', 'amendor'), count($pairs_to_process)) : $replace;
        amendor_log_replacement(
            $current_user_id,
            $post_id,
            get_the_title($post),
            $log_search,
            $log_replace,
            $search_mode,
            $total_changes_in_post_across_pairs,
            $is_bulk_operation
        );

        $global_total_changes_made += $total_changes_in_post_across_pairs;
        $global_replaced_posts_count++;
    }

    if ($global_replaced_posts_count > 0) {
        $summary_message = sprintf(
            /* translators: 1: Number of selected posts, 2: Number of modified posts, 3: Total instances replaced. */
            __('✅ Successfully processed %1$s selected post(s).<br>➡️ %2$s post(s) were modified.<br>📊 Total instances replaced across all modified posts: %3$s.', 'amendor'),
            '<strong>' . number_format_i18n(count($selected_ids)) . '</strong>',
            '<strong>' . number_format_i18n($global_replaced_posts_count) . '</strong>',
            '<strong>' . number_format_i18n($global_total_changes_made) . '</strong>'
        );

        if ($is_bulk_operation) {
            $summary_message .= '<br>' . sprintf(
                /* translators: %d: Number of search/replace pairs. */
                __('📦 Operation type: Bulk Replace with %d pair(s).', 'amendor'),
                count($pairs_to_process)
            );
        }

        $messages[] = ['type' => 'success', 'text' => $summary_message];
        amendor_add_debug_log("Replacement action completed successfully.", 'INFO', [
            'selected_count' => count($selected_ids),
            'modified_count' => $global_replaced_posts_count,
            'total_changes' => $global_total_changes_made,
            'is_bulk' => $is_bulk_operation,
            'sources' => $content_sources,
        ]);
    } else {
        $has_errors = !empty(array_filter($messages, static fn($message) => $message['type'] === 'error'));
        if (!$has_errors) {
            $messages[] = ['type' => 'info', 'text' => __('ℹ️ No posts required replacement based on the provided terms, selected posts, and filters.', 'amendor')];
            amendor_add_debug_log("Replacement action completed, but no posts required modification.", 'INFO');
        }
    }

    amendor_add_debug_log("====== Replace Action Finished ======", 'DEBUG');
}
