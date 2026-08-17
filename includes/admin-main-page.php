<?php

/**
 * Main Search & Replace Admin Page
 *
 * @package Amendor
 */

if (!defined('ABSPATH')) {
    exit;
}
/**
 * Renders the main Elementor Text Replacer admin page UI.
 */
function amendor_render_text_replacer_ui()
{
    // Security Check: Ensure the user has the required capability.
    if (!amendor_current_user_can_manage()) {
        wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'amendor'));
        return; // Stop execution if user doesn't have permission
    }

    // --- Initialize Variables ---
    $amendor_messages = []; // Array to hold user feedback messages (success, error, info)

    // Check for redirect notices
    if (isset($_GET['amendor_notice'])) {
        if ($_GET['amendor_notice'] === 'no_backup_data') {
            $amendor_messages[] = ['type' => 'warning', 'text' => __('⚠️ No backup data found to download. Perform some replacements first to create backups.', 'amendor')];
        }
        // Add more notices here if needed in the future
    }

    // Handle onboarding banner dismissal.
    if (isset($_GET['amendor_dismiss_onboarding'], $_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'amendor_dismiss_onboarding')) {
        $onboarding_user_id = get_current_user_id();
        if ($onboarding_user_id) {
            update_user_meta($onboarding_user_id, 'amendor_onboarding_dismissed', 1);
        }
    }
    $show_onboarding = !get_user_meta(get_current_user_id(), 'amendor_onboarding_dismissed', true);

    // Add an initial marker to the debug log for clarity
    amendor_add_debug_log("====== ETP PAGE LOAD / ACTION START ======", 'DEBUG');

    // Determine the current action from POST or GET requests
    $action = '';
    if (isset($_POST['action']) && $_POST['action'] !== '') {
        $action = sanitize_key(wp_unslash($_POST['action']));
    } elseif (isset($_POST['amendor_form_action']) && $_POST['amendor_form_action'] !== '') {
        $action = sanitize_key(wp_unslash($_POST['amendor_form_action']));
    } elseif (isset($_GET['action'])) {
        $action = sanitize_key(wp_unslash($_GET['action']));
    }
    amendor_add_debug_log("Action Triggered", 'DEBUG', ['action' => $action ?: '(None)']);

    // --- Prepare Variables from POST/GET data ---
    $search = isset($_POST['search']) ? sanitize_text_field(wp_unslash($_POST['search'])) : '';
    $replace = isset($_POST['replace']) ? sanitize_text_field(wp_unslash($_POST['replace'])) : '';
    $search_mode = isset($_POST['search_mode']) ? sanitize_key($_POST['search_mode']) : 'partial';
    // Regex search is a Pro-only mode; the Free build strips the regex UI.
    if ('regex' === $search_mode && !ame_fs()->is__premium_only()) {
        $search_mode = 'partial';
    }
    $selected_ids = isset($_POST['selected_posts']) ? array_map('intval', (array) $_POST['selected_posts']) : [];
    $selected_widgets = isset($_POST['widget_types']) ? array_map('sanitize_text_field', (array) $_POST['widget_types']) : [];
    $selected_content_sources = isset($_POST['content_sources']) ? array_map('sanitize_key', (array) $_POST['content_sources']) : [];
    $bulk_search = isset($_POST['bulk_search']) ? array_map(fn($item) => sanitize_text_field(wp_unslash($item)), (array) $_POST['bulk_search']) : [];
    $bulk_replace = isset($_POST['bulk_replace']) ? array_map(fn($item) => sanitize_text_field(wp_unslash($item)), (array) $_POST['bulk_replace']) : [];
    if (!ame_fs()->is__premium_only()) {
        // Bulk replace (multiple pairs) is a Pro feature.
        $bulk_search = [];
        $bulk_replace = [];
    }

    // Initialize result arrays and counters
    $results = [];
    $preview_results = [];
    $scanned_posts = 0;
    $matched_posts = 0;
    $total_candidate_posts = 0;
    $total_pages = 0;
    $paged = isset($_REQUEST['paged']) ? max(1, intval($_REQUEST['paged'])) : 1;
    $results_per_page = amendor_get_search_results_per_page(isset($_REQUEST['results_per_page']) ? wp_unslash($_REQUEST['results_per_page']) : null);

    $supported_post_types = amendor_get_supported_post_types();
    $available_widgets = amendor_get_available_widgets();
    $available_content_sources = amendor_get_available_content_sources();
    $selected_content_sources = amendor_normalize_content_sources($selected_content_sources);
    $allowed_fields = [];
    if (ame_fs()->is__premium_only()) {
        // Field-key targeting is a Pro feature.
        $allowed_fields = isset($_POST['field_keys']) ? amendor_normalize_allowed_fields(wp_unslash($_POST['field_keys'])) : [];
    }

    // Apply a saved preset: load its data into the form and run the search.
    if (ame_fs()->is__premium_only()) {
        if ($action === 'apply_preset') {
            $preset_nonce_ok = isset($_POST['amendor_presets_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['amendor_presets_nonce'])), 'amendor_presets_action');
            $preset_id = isset($_POST['preset_id']) ? intval($_POST['preset_id']) : 0;
            $preset = $preset_nonce_ok ? amendor_get_preset($preset_id) : null;
            if ($preset) {
                $data = $preset['data'];
                $search = (string) ($data['search'] ?? '');
                $replace = (string) ($data['replace'] ?? '');
                $search_mode = in_array($data['search_mode'] ?? '', ['partial', 'exact', 'regex'], true) ? $data['search_mode'] : 'partial';
                $selected_content_sources = amendor_normalize_content_sources((array) ($data['content_sources'] ?? []));
                $selected_widgets = amendor_normalize_selected_widgets((array) ($data['widget_types'] ?? []));
                $allowed_fields = amendor_normalize_allowed_fields((string) ($data['field_keys'] ?? ''));
                $bulk_search = array_values(array_map(static fn($item) => (string) $item, (array) ($data['bulk_search'] ?? [])));
                $bulk_replace = array_values(array_map(static fn($item) => (string) $item, (array) ($data['bulk_replace'] ?? [])));
                /* translators: %s: Preset name. */
                $amendor_messages[] = ['type' => 'success', 'text' => sprintf(__('✅ Preset "%s" applied.', 'amendor'), esc_html($preset['name']))];
                $action = 'search';
            } elseif ($preset_nonce_ok) {
                $amendor_messages[] = ['type' => 'error', 'text' => __('❌ Preset not found.', 'amendor')];
            }
        }

        // Preset management actions (save / delete / export / import).
        amendor_handle_presets_action($action, $amendor_messages);
    }

    $search_attempted = ($action === 'search');
    $preview_attempted = ($action === 'preview_selected');

    amendor_handle_restore_action($action, $amendor_messages);
    amendor_handle_undo_action($action, $amendor_messages);

    $search_payload = amendor_handle_search_action(
        $action,
        $search,
        $search_mode,
        $selected_widgets,
        $selected_content_sources,
        $supported_post_types,
        $paged,
        $results_per_page,
        $amendor_messages,
        $allowed_fields
    );
    $results = $search_payload['results'];
    $scanned_posts = $search_payload['scanned_posts'];
    $matched_posts = $search_payload['matched_posts'];
    $total_candidate_posts = $search_payload['total_candidate_posts'];
    $total_pages = $search_payload['total_pages'];
    $paged = $search_payload['paged'];

    $preview_results = amendor_handle_preview_action(
        $action,
        $selected_ids,
        $search,
        $replace,
        $search_mode,
        $selected_widgets,
        $selected_content_sources,
        $supported_post_types,
        $amendor_messages,
        $allowed_fields
    );

    amendor_handle_replace_action(
        $action,
        $selected_ids,
        $search,
        $replace,
        $search_mode,
        $bulk_search,
        $bulk_replace,
        $selected_widgets,
        $selected_content_sources,
        $supported_post_types,
        $amendor_messages,
        $allowed_fields
    );


    // ======================================================================
    // --- RENDER THE ADMIN PAGE UI ---
    // ======================================================================
?>
    <div class="wrap amendor-wrap">
        <h1><span class="dashicons dashicons-edit" style="font-size: 1.3em; vertical-align: middle;"></span> <?php esc_html_e('Amendor', 'amendor'); ?></h1>

        <?php if ($show_onboarding): ?>
            <div class="notice notice-info is-dismissible amendor-onboarding">
                <p><strong><?php esc_html_e('Welcome to Amendor!', 'amendor'); ?></strong></p>
                <p><?php esc_html_e('Amendor finds and replaces text inside Elementor content and native post fields — safely.', 'amendor'); ?></p>
                <ol style="margin: 6px 0 6px 20px; list-style: decimal;">
                    <li><?php esc_html_e('Enter the text to find (and an optional replacement), or use Bulk Replace for multiple pairs.', 'amendor'); ?></li>
                    <li><?php esc_html_e('Choose the content sources to scan, then click "Search Only".', 'amendor'); ?></li>
                    <li><?php esc_html_e('Review the results, use "Preview Selected" for a dry run, then "Replace Selected".', 'amendor'); ?></li>
                    <li><?php esc_html_e('A backup is created before every replacement — use "Undo Last Replace" or the History Log to revert.', 'amendor'); ?></li>
                </ol>
                <p>
                    <a class="button button-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=' . amendor_get_admin_parent_slug() . '&amendor_dismiss_onboarding=1'), 'amendor_dismiss_onboarding')); ?>">
                        <?php esc_html_e('Dismiss', 'amendor'); ?>
                    </a>
                </p>
            </div>
        <?php endif; ?>

        <?php // --- Display collected notices/messages --- 
        ?>
        <div id="amendor-admin-notices">
            <?php amendor_render_admin_notices($amendor_messages); ?>
        </div>

        <?php settings_errors(); ?>

        <form method="post" id="elementor-search-form" action="<?php echo esc_url(admin_url('admin.php?page=' . amendor_get_admin_parent_slug())); ?>">
            <?php wp_nonce_field('amendor_search_action', 'amendor_search_nonce'); ?>
            <?php wp_nonce_field('amendor_replace_action', 'amendor_replace_nonce'); ?>
            <?php wp_nonce_field('amendor_preview_action', 'amendor_preview_nonce'); ?>
            <?php wp_nonce_field('amendor_undo_action', 'amendor_undo_nonce'); ?>
            <input type="hidden" name="amendor_form_action" id="amendor-form-action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="paged" id="amendor-paged-input" value="<?php echo esc_attr($paged); ?>">
            <input type="hidden" name="results_per_page" id="amendor-results-per-page-input" value="<?php echo esc_attr($results_per_page); ?>">
            <input type="hidden" name="search_cache_key" id="amendor-search-cache-key" value="<?php echo isset($_POST['search_cache_key']) ? esc_attr(sanitize_key(wp_unslash($_POST['search_cache_key']))) : ''; ?>">

            <div class="amendor-layout">
                <?php // --- Main Content Area --- 
                ?>
                <div class="amendor-main-content">

                    <!-- 1. Search & Replace Terms Section -->
                    <div class="amendor-section postbox">
                        <h2 class="hndle"><span><?php esc_html_e('1. Search & Replace Terms', 'amendor'); ?></span></h2>
                        <div class="inside">
                            <p class="description" style="margin-bottom: 15px;"><?php esc_html_e('Define the primary text you want to find and replace. Use the Bulk Replace section below for multiple pairs.', 'amendor'); ?></p>
                            <table class="form-table">
                                <tr>
                                    <th><label for="search"><?php esc_html_e('Search For', 'amendor'); ?></label></th>
                                    <td>
                                        <input name="search" type="text" id="search" value="<?php echo esc_attr($search); ?>" class="regular-text" list="amendor-search-history-list">
                                        <?php $history = amendor_get_search_history(); ?>
                                        <?php if (!empty($history)) : ?>
                                            <datalist id="amendor-search-history-list">
                                                <?php foreach ($history as $past_search) : ?>
                                                    <option value="<?php echo esc_attr($past_search); ?>">
                                                    <?php endforeach; ?>
                                            </datalist>
                                            <p class="description"><?php esc_html_e('Start typing or use arrow keys to see recent searches.', 'amendor'); ?></p>
                                            <div class="amendor-recent-searches">
                                                <span class="description"><?php esc_html_e('Recent:', 'amendor'); ?></span>
                                                <?php foreach (array_slice($history, 0, 5) as $past_search) : ?>
                                                    <button type="button" class="button button-link amendor-recent-search" data-search="<?php echo esc_attr($past_search); ?>"><?php echo esc_html($past_search); ?></button>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <p class="description"><?php esc_html_e('Enter the text you want to find. Case sensitivity depends on the Search Mode.', 'amendor'); ?></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="replace"><?php esc_html_e('Replace With', 'amendor'); ?></label></th>
                                    <td>
                                        <input name="replace" type="text" id="replace" value="<?php echo esc_attr($replace); ?>" class="regular-text">
                                        <p class="description"><?php esc_html_e('Enter the replacement text. Leave empty to delete the found text.', 'amendor'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="search_mode"><?php esc_html_e('Search Mode', 'amendor'); ?></label></th>
                                    <td>
                                        <select name="search_mode" id="search_mode">
                                            <option value="partial" <?php selected($search_mode, 'partial'); ?>><?php esc_html_e('Partial Match (Case-Insensitive, Default)', 'amendor'); ?></option>
                                            <option value="exact" <?php selected($search_mode, 'exact'); ?>><?php esc_html_e('Exact Text (Case-Sensitive)', 'amendor'); ?></option>
                                            <?php if (ame_fs()->is__premium_only()) { ?>
                                                <option value="regex" <?php selected($search_mode, 'regex'); ?>><?php esc_html_e('Regular Expression (PCRE, Case-Insensitive)', 'amendor'); ?></option>
                                            <?php } ?>
                                        </select>
                                        <p class="description"><?php esc_html_e('Choose matching method. Exact Text matches the typed text exactly, including case, within larger content.', 'amendor'); ?></p>
                                        <?php if (ame_fs()->is__premium_only()) { ?>
                                            <div id="regex-help" style="display: <?php echo $search_mode === 'regex' ? 'block' : 'none'; ?>; margin-top: 10px; padding: 10px; background: #f0f0f0; border: 1px solid #ddd; font-size: 0.9em;">
                                                <strong><?php esc_html_e('Regex Tips:', 'amendor'); ?></strong> <?php esc_html_e('Use PCRE syntax (no delimiters needed here). Special characters like', 'amendor'); ?> <code>.^$*+?()[{|</code> <?php esc_html_e('need escaping with', 'amendor'); ?> <code>\</code> (e.g., <code>1\.0</code>). <?php esc_html_e('Search is case-insensitive (<code>i</code> flag) and Unicode-aware (<code>u</code> flag). Use <code>\b</code> for word boundaries. Test carefully!', 'amendor'); ?>
                                            </div>
                                        <?php } ?>
                                    </td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <?php if (ame_fs()->is__premium_only()) { ?>
                        <!-- 2. Bulk Replace Section -->
                        <div class="amendor-section postbox">
                            <h2 class="hndle"><span><?php esc_html_e('2. Bulk Replace (Optional)', 'amendor'); ?></span></h2>
                            <div class="inside">
                                <p class="description" style="margin-bottom: 15px;"><?php esc_html_e('Add multiple pairs here to run sequentially. If used, the single Search/Replace fields above are ignored during replacement.', 'amendor'); ?></p>
                                <div id="bulk-replace-container">
                                    <?php
                                    $bulk_pairs_count = max(1, count($bulk_search));
                                    for ($i = 0; $i < $bulk_pairs_count; $i++):
                                    ?>
                                        <div class="bulk-replace-pair" style="display: flex; gap: 10px; margin-bottom: 10px; align-items: center;">
                                            <input type="text" name="bulk_search[]" placeholder="<?php esc_attr_e('Search for...', 'amendor'); ?>" class="regular-text" style="flex-grow: 1;" value="<?php echo isset($bulk_search[$i]) ? esc_attr($bulk_search[$i]) : ''; ?>">
                                            <span>➡️</span>
                                            <input type="text" name="bulk_replace[]" placeholder="<?php esc_attr_e('Replace with...', 'amendor'); ?>" class="regular-text" style="flex-grow: 1;" value="<?php echo isset($bulk_replace[$i]) ? esc_attr($bulk_replace[$i]) : ''; ?>">
                                            <button type="button" class="button remove-pair" title="<?php esc_attr_e('Remove this pair', 'amendor'); ?>">×</button>
                                        </div>
                                    <?php endfor; ?>
                                </div>
                                <button type="button" id="add-bulk-pair" class="button button-secondary">
                                    <span class="dashicons dashicons-plus-alt"></span> <?php esc_html_e('Add Another Pair', 'amendor'); ?>
                                </button>
                            </div>
                        </div>
                    <?php } ?>


                    <!-- 3. Filters Section -->
                    <div class="amendor-section postbox">
                        <h2 class="hndle"><span><?php esc_html_e('3. Filters', 'amendor'); ?></span></h2>
                        <div class="inside">
                            <p class="description" style="margin-bottom: 15px;"><?php esc_html_e('Choose which post content sources should be searched or replaced. Widget filtering only applies when Elementor content is included.', 'amendor'); ?></p>
                            <table class="form-table">
                                <tr>
                                    <th><?php esc_html_e('Content Sources', 'amendor'); ?></th>
                                    <td>
                                        <fieldset id="amendor-content-sources">
                                            <?php foreach ($available_content_sources as $source_key => $source_label): ?>
                                                <label style="display: block; margin-bottom: 6px;">
                                                    <input type="checkbox" name="content_sources[]" value="<?php echo esc_attr($source_key); ?>" class="amendor-content-source-checkbox" <?php checked(in_array($source_key, $selected_content_sources, true)); ?>>
                                                    <?php echo esc_html($source_label); ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </fieldset>
                                        <p class="description"><?php esc_html_e('By default Amendor searches Elementor data plus native post fields. Uncheck sources to narrow the scan.', 'amendor'); ?></p>
                                    </td>
                                </tr>
                                <tr id="amendor-widget-filter-row" style="<?php echo in_array('elementor', $selected_content_sources, true) ? '' : 'display: none;'; ?>">
                                    <th><label for="widget_types"><?php esc_html_e('Widget Types', 'amendor'); ?></label></th>
                                    <td>
                                        <?php if (!empty($available_widgets)): ?>
                                            <select name="widget_types[]" id="widget_types" multiple style="width: 100%; min-height: 150px;" <?php disabled(!in_array('elementor', $selected_content_sources, true)); ?>>
                                                <?php foreach ($available_widgets as $name => $title): ?>
                                                    <option value="<?php echo esc_attr($name); ?>" <?php selected(in_array($name, $selected_widgets)); ?>>
                                                        <?php echo esc_html($title); ?> (<?php echo esc_html($name); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="description"><?php esc_html_e('Leave empty to search/replace in all widget types. Use Ctrl/Cmd + Click for standard multi-select.', 'amendor'); ?></p>
                                        <?php else: ?>
                                            <p><em><?php esc_html_e('Could not load Elementor widgets list. Filtering by widget type is unavailable. Ensure Elementor plugin is active.', 'amendor'); ?></em></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if (ame_fs()->is__premium_only()) { ?>
                                    <tr>
                                        <th><label for="field_keys"><?php esc_html_e('Field Keys', 'amendor'); ?></label></th>
                                        <td>
                                            <input type="text" name="field_keys" id="field_keys" class="regular-text" value="<?php echo esc_attr(implode(', ', $allowed_fields)); ?>" placeholder="editor, title, url">
                                            <p class="description"><?php esc_html_e('Optional. Comma-separated Elementor settings keys to limit scanning to (e.g. editor, title, url). Leave empty to scan all fields.', 'amendor'); ?></p>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </table>
                        </div>
                    </div>

                    <?php
                    amendor_render_results_section([
                        'preview_attempted' => $preview_attempted,
                        'search_attempted' => $search_attempted,
                        'preview_results' => $preview_results,
                        'results' => $results,
                        'selected_ids' => $selected_ids,
                        'action' => $action,
                        'paged' => $paged,
                        'total_pages' => $total_pages,
                        'matched_posts' => $matched_posts,
                        'total_candidate_posts' => $total_candidate_posts,
                        'results_per_page' => $results_per_page,
                        'content_sources' => $selected_content_sources,
                    ]);
                    ?>
                </div> <?php // End amendor-main-content 
                        ?>

                <?php // --- Sidebar Area --- 
                ?>
                <div class="amendor-sidebar">

                    <!-- Quick Stats Panel -->
                    <div id="amendor-quick-stats" class="postbox">
                        <h2 class="hndle"><span><?php esc_html_e('Quick Stats', 'amendor'); ?></span></h2>
                        <div class="inside">
                            <?php $stats = amendor_get_dashboard_stats(); ?>
                            <ul style="margin: 0; padding: 0; list-style: none;">
                                <li style="padding: 6px 0; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between;">
                                    <span><?php esc_html_e('Total Operations', 'amendor'); ?></span>
                                    <strong><?php echo esc_html(number_format_i18n($stats['total_operations'])); ?></strong>
                                </li>
                                <li style="padding: 6px 0; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between;">
                                    <span><?php esc_html_e('Total Changes', 'amendor'); ?></span>
                                    <strong><?php echo esc_html(number_format_i18n($stats['total_changes'])); ?></strong>
                                </li>
                                <li style="padding: 6px 0; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between;">
                                    <span><?php esc_html_e('Pages Modified', 'amendor'); ?></span>
                                    <strong><?php echo esc_html(number_format_i18n($stats['pages_modified'])); ?></strong>
                                </li>
                                <li style="padding: 6px 0; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between;">
                                    <span><?php esc_html_e('Backups Stored', 'amendor'); ?></span>
                                    <strong><?php echo esc_html(number_format_i18n($stats['total_backups'])); ?></strong>
                                </li>
                                <li style="padding: 6px 0; display: flex; justify-content: space-between;">
                                    <span><?php esc_html_e('Last Activity', 'amendor'); ?></span>
                                    <strong><?php echo $stats['last_activity'] !== '' ? esc_html($stats['last_activity']) : esc_html__('—', 'amendor'); ?></strong>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- URL / Domain Swap Preset -->
                    <div id="amendor-url-swap" class="postbox">
                        <h2 class="hndle"><span><?php esc_html_e('URL / Domain Swap', 'amendor'); ?></span></h2>
                        <div class="inside">
                            <p class="description"><?php esc_html_e('Find and preview a URL/domain replacement across all content sources.', 'amendor'); ?></p>
                            <label for="amendor-swap-old"><?php esc_html_e('Old URL / domain', 'amendor'); ?></label>
                            <input type="text" id="amendor-swap-old" class="regular-text" style="width: 100%; margin-bottom: 8px;" placeholder="https://old.example.com">
                            <label for="amendor-swap-new"><?php esc_html_e('New URL / domain', 'amendor'); ?></label>
                            <input type="text" id="amendor-swap-new" class="regular-text" style="width: 100%; margin-bottom: 8px;" placeholder="https://new.example.com">
                            <button type="button" id="amendor-swap-run" class="button button-secondary button-large" style="width: 100%;">
                                <span class="dashicons dashicons-admin-links"></span> <?php esc_html_e('Fill & Search', 'amendor'); ?>
                            </button>
                        </div>
                    </div>

                    <?php if (ame_fs()->is__premium_only()) { ?>
                        <!-- Save Preset -->
                        <div id="amendor-save-preset" class="postbox">
                            <h2 class="hndle"><span><?php esc_html_e('Save Preset', 'amendor'); ?></span></h2>
                            <div class="inside">
                                <p class="description"><?php esc_html_e('Save the current search/replace configuration to reuse it — also reusable across sites via Export/Import.', 'amendor'); ?></p>
                                <?php wp_nonce_field('amendor_presets_action', 'amendor_presets_nonce'); ?>
                                <input type="text" name="preset_name" id="preset-name" class="regular-text" style="width: 100%; margin-bottom: 8px; box-sizing: border-box;" placeholder="<?php esc_attr_e('Preset name...', 'amendor'); ?>">
                                <button type="submit" name="action" value="save_preset" class="button button-secondary button-large" style="width: 100%;">
                                    <span class="dashicons dashicons-saved"></span> <?php esc_html_e('Save Current as Preset', 'amendor'); ?>
                                </button>
                            </div>
                        </div>
                    <?php } ?>

                    <!-- Actions Panel -->
                    <div id="amendor-actions-panel" class="postbox">
                        <h2 class="hndle"><span><?php esc_html_e('Actions', 'amendor'); ?></span></h2>
                        <div class="inside">
                            <p id="backup-reminder" style="display: none; margin-bottom: 15px; padding: 10px; background: #fff8e1; border-left: 4px solid #ffb900;">
                                <strong><?php esc_html_e('⚠️ Backup Recommended', 'amendor'); ?></strong><br>
                                <span class="amendor-backup-reminder-text"><?php esc_html_e('Some selected posts do not have an existing backup yet. The plugin will create one automatically before replacement.', 'amendor'); ?></span>
                            </p>
                            <div class="amendor-action-buttons">
                                <button type="submit" name="action" value="search" class="button button-secondary button-large amendor-action-button" id="search-button">
                                    <span class="dashicons dashicons-search"></span> <?php esc_html_e('Search Only', 'amendor'); ?>
                                </button>
                                <div id="amendor-search-progress" class="notice inline" style="display: none; margin: 0;">
                                    <p class="amendor-search-progress-text"></p>
                                    <button type="button" id="amendor-search-cancel" class="button button-small" style="display: none; margin-top: 6px;">
                                        <span class="dashicons dashicons-no-alt"></span> <?php esc_html_e('Cancel Scan', 'amendor'); ?>
                                    </button>
                                </div>
                                <button type="submit" name="action" value="preview_selected" class="button button-secondary button-large amendor-action-button" id="preview-button" disabled>
                                    <span class="dashicons dashicons-visibility"></span> <?php esc_html_e('Preview Selected', 'amendor'); ?>
                                </button>
                                <button type="submit" name="action" value="replace_selected" class="button button-primary button-large amendor-action-button" id="replace-button" disabled onclick="return confirmReplaceAction();">
                                    <span class="dashicons dashicons-yes"></span> <?php esc_html_e('Replace Selected', 'amendor'); ?>
                                </button>
                                <button type="submit" name="action" value="undo" class="button button-secondary button-large amendor-action-button" id="undo-button" onclick="return confirm(amendor_admin_vars.confirm_undo_text);">
                                    <span class="dashicons dashicons-undo"></span> <?php esc_html_e('Undo Last Replace', 'amendor'); ?>
                                </button>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=' . amendor_get_admin_parent_slug() . '&action=download_backup'), 'amendor_download_backup_action', 'amendor_download_nonce')); ?>"
                                    class="button button-secondary button-large amendor-action-button" id="backup-button">
                                    <span class="dashicons dashicons-database-export"></span> <?php esc_html_e('Download Backups', 'amendor'); ?>
                                </a>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=amendor-change-history')); ?>"
                                    class="button button-secondary button-large amendor-action-button" id="history-button">
                                    <span class="dashicons dashicons-backup"></span> <?php esc_html_e('View History Log', 'amendor'); ?>
                                </a>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=amendor-debug-log')); ?>"
                                    class="button button-secondary button-large amendor-action-button" id="debug-log-button">
                                    <span class="dashicons dashicons-hammer"></span> <?php esc_html_e('View Debug Log', 'amendor'); ?>
                                </a>
                            </div>
                            <p class="description" style="margin-top: 10px; text-align: center;"><?php esc_html_e('Select items from results to enable Preview/Replace.', 'amendor'); ?></p>
                        </div>
                    </div>

                    <!-- Info Panel -->
                    <div id="amendor-info-panel" class="postbox">
                        <h2 class="hndle"><span><?php esc_html_e('How It Works', 'amendor'); ?></span></h2>
                        <div class="inside">
                            <ol style="margin: 0; padding-left: 20px; list-style: decimal;">
                                <li><?php esc_html_e('Enter search/replace terms (or use Bulk Replace).', 'amendor'); ?></li>
                                <li><?php esc_html_e('Choose the content sources to scan, then optionally filter Elementor widget types.', 'amendor'); ?></li>
                                <li><?php /* translators: %s: Name of the button or action. */ printf(esc_html__('Click %s.', 'amendor'), '<strong>' . esc_html__('Search Only', 'amendor') . '</strong>'); ?></li>
                                <li><?php esc_html_e('Results appear below. Select posts to modify.', 'amendor'); ?></li>
                                <li><?php /* translators: %s: Name of the button or action. */ printf(esc_html__('Click %s for a dry run.', 'amendor'), '<strong>' . esc_html__('Preview Selected', 'amendor') . '</strong>'); ?></li>
                                <li><?php /* translators: %s: Name of the button or action. */ printf(esc_html__('Click %s to apply changes (backups created per post).', 'amendor'), '<strong>' . esc_html__('Replace Selected', 'amendor') . '</strong>'); ?></li>
                                <li><?php /* translators: %s: Name of the button or action. */ printf(esc_html__('Use %s to save all backups.', 'amendor'), '<strong>' . esc_html__('Download Backups', 'amendor') . '</strong>'); ?></li>
                                <li><?php /* translators: %s: Name of the button or action. */ printf(esc_html__('Use %s to track changes.', 'amendor'), '<strong>' . esc_html__('View History Log', 'amendor') . '</strong>'); ?></li>
                                <li><?php /* translators: %s: Name of the button or action. */ printf(esc_html__('Use %s (and enable logging on its page) for detailed troubleshooting.', 'amendor'), '<strong>' . esc_html__('View Debug Log', 'amendor') . '</strong>'); ?></li>
                                <li><?php esc_html_e('Restore individual posts from backups within the results list.', 'amendor'); ?></li>
                            </ol>
                        </div>
                    </div>
                </div> <?php // End amendor-sidebar 
                        ?>
            </div> <?php // End amendor-layout 
                    ?>
        </form> <?php // End main form 
                ?>

        <?php if (ame_fs()->is__premium_only()) {
            amendor_render_presets_box();
        } ?>
    </div> <?php // End wrap 
            ?>

<?php
} // End amendor_render_text_replacer_ui function
