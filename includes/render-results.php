<?php

/**
 * Results Rendering (admin notices and search/preview result markup)
 *
 * @package Amendor
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render a single result item.
 *
 * @param array $item Result item data.
 * @param bool  $is_preview Whether this is preview output.
 * @param array $selected_ids Selected post IDs.
 * @return void
 */
function amendor_render_results_item(array $item, $is_preview, array $selected_ids)
{
    $post_status = get_post_status($item['ID']);
    $post_status_object = $post_status ? get_post_status_object($post_status) : null;
    $post_status_label = $post_status_object && !empty($post_status_object->label)
        ? $post_status_object->label
        : ucfirst((string) $post_status);
?>
    <div class="amendor-preview-item" data-type="<?php echo esc_attr($item['type']); ?>" data-backup-count="<?php echo esc_attr(isset($item['backup_count']) ? intval($item['backup_count']) : amendor_get_post_backup_count($item['ID'])); ?>">
        <div class="amendor-item-header hndle">
            <div class="result-left">
                <input type="checkbox" name="selected_posts[]" value="<?php echo esc_attr($item['ID']); ?>" class="amendor-result-checkbox" <?php checked(in_array($item['ID'], $selected_ids, true)); ?>>
                <strong class="amendor-item-title"><?php echo esc_html($item['title']); ?></strong>
                <span class="amendor-post-type">(<?php echo esc_html(ucfirst(str_replace(['-', '_'], ' ', $item['type']))); ?>)</span>
                <?php if ($post_status_label !== ''): ?>
                    <span class="amendor-post-status amendor-post-status-<?php echo esc_attr(sanitize_html_class($post_status)); ?>">
                        <?php echo esc_html($post_status_label); ?>
                    </span>
                <?php endif; ?>
            </div>
            <span class="amendor-item-actions">
                <?php
                $edit_link = get_edit_post_link($item['ID']);
                $view_link = get_permalink($item['ID']);
                $elementor_edit_link = $edit_link ? add_query_arg(['action' => 'elementor'], $edit_link) : false;
                ?>
                <?php if ($view_link): ?><a href="<?php echo esc_url($view_link); ?>" target="_blank" class="button button-small view-link" title="<?php esc_attr_e('View Post (Opens in new tab)', 'amendor'); ?>"><?php esc_html_e('View', 'amendor'); ?></a><?php endif; ?>
                <?php if ($edit_link): ?><a href="<?php echo esc_url($edit_link); ?>" target="_blank" class="button button-small edit-wp-link" title="<?php esc_attr_e('Edit in WordPress Editor (Opens in new tab)', 'amendor'); ?>"><?php esc_html_e('Edit WP', 'amendor'); ?></a><?php endif; ?>
                <?php if ($elementor_edit_link && class_exists('Elementor\Plugin')): ?><a href="<?php echo esc_url($elementor_edit_link); ?>" target="_blank" class="button button-small edit-elementor-link" title="<?php esc_attr_e('Edit with Elementor (Opens in new tab)', 'amendor'); ?>"><?php esc_html_e('Edit Elementor', 'amendor'); ?></a><?php endif; ?>
            </span>
        </div>
        <div class="amendor-item-content">
            <?php if (!empty($item['source_counts'])): ?>
                <div class="amendor-match-summary">
                    <?php foreach ($item['source_counts'] as $label => $count): ?>
                        <span class="amendor-match-badge"><?php echo esc_html($label); ?> ×<?php echo esc_html(number_format_i18n($count)); ?></span>
                    <?php endforeach; ?>
                    <?php foreach ($item['widget_counts'] as $widget => $count): ?>
                        <span class="amendor-match-badge amendor-match-badge-widget"><?php echo esc_html($widget); ?> ×<?php echo esc_html(number_format_i18n($count)); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="amendor-diffs">
                <?php if (empty($item['matches'])): ?>
                    <p><em><?php echo $is_preview ? esc_html__('No changes predicted in this item for the current criteria.', 'amendor') : esc_html__('No changes found in this item for the current criteria.', 'amendor'); ?></em></p>
                <?php else: ?>
                    <?php $match_count = count($item['matches']); ?>
                    <p class="match-count-info"><em><?php
                                                    printf(
                                                        /* translators: %s: Number of matches shown */
                                                        esc_html__('Showing up to %s example matches for this post.', 'amendor'),
                                                        esc_html(number_format_i18n($match_count))
                                                    );
                                                    ?></em></p>
                    <?php foreach ($item['matches'] as $index => $match): ?>
                        <div class="match-block" data-match-index="<?php echo esc_attr($index); ?>" style="<?php echo $index >= 10 ? 'display: none;' : ''; ?>">
                            <?php echo wp_kses_post(amendor_render_match_block($match)); ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if (!empty($item['json_diff'])): ?>
                <div class="amendor-json-diff">
                    <button type="button" class="button button-small amendor-json-diff-toggle">
                        <span class="dashicons dashicons-visibility"></span> <?php esc_html_e('View JSON Diff', 'amendor'); ?>
                    </button>
                    <div class="amendor-json-diff-body" style="display: none; margin-top: 10px;">
                        <?php if (is_array($item['json_diff']['diff'])): ?>
                            <?php
                            $json_diff_html = '';
                            foreach ($item['json_diff']['diff'] as $diff_line) {
                                $marker = $diff_line['type'] === 'add' ? '+' : ($diff_line['type'] === 'del' ? '-' : ' ');
                                $json_diff_html .= '<span class="amendor-diff-' . esc_attr($diff_line['type']) . '">' . esc_html($marker . ' ' . $diff_line['line']) . "</span>\n";
                            }
                            ?>
                            <p class="description">
                                <span class="amendor-diff-add">+</span> <?php esc_html_e('added', 'amendor'); ?>
                                &nbsp;|&nbsp;
                                <span class="amendor-diff-del">-</span> <?php esc_html_e('removed', 'amendor'); ?>
                            </p>
                            <pre class="amendor-json-diff-pre"><?php echo $json_diff_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
                                                                ?></pre>
                        <?php else: ?>
                            <p class="description"><?php esc_html_e('This page is too large for a line-level diff. Showing the resulting Elementor data.', 'amendor'); ?></p>
                            <pre class="amendor-json-diff-pre"><?php echo esc_html($item['json_diff']['after']); ?></pre>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!$is_preview): ?>
                <div class="amendor-restore-section">
                    <?php $backups = amendor_get_elementor_backups($item['ID']); ?>
                    <?php if (!empty($backups)): ?>
                        <form method="post" class="amendor-restore-form" style="margin-top: 15px; padding-top: 10px; border-top: 1px dashed #ddd;">
                            <?php wp_nonce_field('amendor_restore_action_' . $item['ID'], 'amendor_restore_nonce'); ?>
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="restore_post_id" value="<?php echo esc_attr($item['ID']); ?>">
                            <label for="backup_index_<?php echo esc_attr($item['ID']); ?>" style="margin-right: 5px;"><?php esc_html_e('Restore from:', 'amendor'); ?></label>
                            <select name="backup_index" id="backup_index_<?php echo esc_attr($item['ID']); ?>">
                                <?php foreach ($backups as $index => $backup): ?>
                                    <option value="<?php echo esc_attr($index); ?>">
                                        <?php
                                        printf(
                                            /* translators: 1: Date and Time, 2: Human readable time difference */
                                            esc_html__('Backup from %1$s (%2$s ago)', 'amendor'),
                                            esc_html(wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i:s'), strtotime($backup['timestamp']))),
                                            esc_html(human_time_diff(strtotime($backup['timestamp']), current_time('timestamp', 1)))
                                        );
                                        ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="button button-secondary button-small restore-button"
                                data-post-id="<?php echo esc_attr($item['ID']); ?>"
                                onclick="return confirm(amendor_admin_vars.confirm_restore_text.replace('%d', this.getAttribute('data-post-id')));">
                                <span class="dashicons dashicons-undo"></span> <?php esc_html_e('Restore', 'amendor'); ?>
                            </button>
                        </form>
                    <?php else: ?>
                        <p style="margin-top: 15px; padding-top: 10px; border-top: 1px dashed #ddd; font-style: italic; color: #666;"><?php esc_html_e('No backups available for this post.', 'amendor'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php
}

/**
 * Render the results section.
 *
 * @param array $args Results section state.
 * @return void
 */
function amendor_render_results_section(array $args)
{
    $preview_attempted = !empty($args['preview_attempted']);
    $search_attempted = !empty($args['search_attempted']);
    $preview_results = isset($args['preview_results']) && is_array($args['preview_results']) ? $args['preview_results'] : [];
    $results = isset($args['results']) && is_array($args['results']) ? $args['results'] : [];
    $selected_ids = isset($args['selected_ids']) && is_array($args['selected_ids']) ? $args['selected_ids'] : [];
    $action = isset($args['action']) ? (string) $args['action'] : '';
    $paged = isset($args['paged']) ? (int) $args['paged'] : 1;
    $total_pages = isset($args['total_pages']) ? (int) $args['total_pages'] : 0;
    $matched_posts = isset($args['matched_posts']) ? (int) $args['matched_posts'] : 0;
    $total_candidate_posts = isset($args['total_candidate_posts']) ? (int) $args['total_candidate_posts'] : 0;
    $results_per_page = isset($args['results_per_page']) ? amendor_get_search_results_per_page($args['results_per_page']) : amendor_get_search_results_per_page();
    $content_sources = isset($args['content_sources']) && is_array($args['content_sources']) ? amendor_normalize_content_sources($args['content_sources']) : amendor_get_default_content_sources();
    $content_source_summary = amendor_format_content_sources_summary($content_sources);

    $items_to_display = !empty($preview_results) ? $preview_results : $results;
    $is_preview = !empty($preview_results);
    $has_results_or_preview = !empty($items_to_display);
?>
    <div class="amendor-section postbox" id="amendor-results-panel">
        <h2 class="hndle"><span><?php esc_html_e('4. Results', 'amendor'); ?> <?php
                                                                                if ($preview_attempted) {
                                                                                    echo '(' . esc_html__('Preview', 'amendor') . ')';
                                                                                } elseif ($search_attempted) {
                                                                                    echo '(' . esc_html__('Search Results', 'amendor') . ')';
                                                                                }
                                                                                ?></span></h2>
        <div class="inside">
            <?php if (!$search_attempted && !$preview_attempted): ?>
                <p><?php esc_html_e('Perform a search or preview to see results here.', 'amendor'); ?></p>
            <?php elseif (!$has_results_or_preview): ?>
                <p><?php
                    /* translators: %s: Action name (search or preview). */
                    printf(esc_html__('No matches found for your %s criteria.', 'amendor'), '<strong>' . esc_html($action) . '</strong>');
                    ?></p>
            <?php else: ?>
                <?php $result_types = array_unique(wp_list_pluck($items_to_display, 'type')); ?>
                <?php if ($is_preview): ?>
                    <div class="notice notice-info inline" style="margin-bottom: 15px;">
                        <p><strong><?php esc_html_e('Preview Mode:', 'amendor'); ?></strong> <?php esc_html_e('Showing potential changes for selected items. No changes have been saved yet.', 'amendor'); ?> <?php
                                                                                                                                                                                                                /* translators: %s: Comma-separated list of active content sources. */
                                                                                                                                                                                                                printf(esc_html__('Active content sources: %s.', 'amendor'), '<strong>' . esc_html($content_source_summary) . '</strong>');
                                                                                                                                                                                                                ?></p>
                    </div>
                <?php else: ?>
                    <p><?php
                        printf(
                            /* translators: 1: Count of matched posts on the current page, 2: Current page number, 3: Total pages, 4: Total matched posts, 5: Total scanned candidate posts, 6: Source summary */
                            esc_html__('Found %1$s matched post(s) on this page (Page %2$s of %3$s). Total matched posts across the full scan: %4$s. Candidate posts scanned: %5$s. Active content sources: %6$s.', 'amendor'),
                            '<strong>' . esc_html(number_format_i18n(count($items_to_display))) . '</strong>',
                            '<strong>' . esc_html(number_format_i18n($paged)) . '</strong>',
                            '<strong>' . esc_html(number_format_i18n($total_pages)) . '</strong>',
                            '<strong>' . esc_html(number_format_i18n($matched_posts)) . '</strong>',
                            '<strong>' . esc_html(number_format_i18n($total_candidate_posts)) . '</strong>',
                            '<strong>' . esc_html($content_source_summary) . '</strong>'
                        );
                        ?></p>
                    <p><?php esc_html_e('Select posts below to Preview or Replace.', 'amendor'); ?></p>
                <?php endif; ?>

                <div class="amendor-results-toolbar">
                    <div class="result-toolbar-item">
                        <label><input type="checkbox" id="select-all-results"> <?php esc_html_e('Select All / None', 'amendor'); ?></label>
                    </div>
                    <div class="result-toolbar-item">
                        <label for="filter-type"><?php esc_html_e('Filter by Type:', 'amendor'); ?></label>
                        <select id="filter-type">
                            <option value=""><?php esc_html_e('All Types', 'amendor'); ?></option>
                            <?php foreach ($result_types as $type): ?>
                                <option value="<?php echo esc_attr($type); ?>">
                                    <?php echo esc_html(ucfirst(str_replace(['-', '_'], ' ', $type))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (!$is_preview): ?>
                        <div class="result-toolbar-item">
                            <label for="results-per-page"><?php esc_html_e('Posts Per Page:', 'amendor'); ?></label>
                            <select id="results-per-page">
                                <?php foreach (apply_filters('amendor_search_posts_per_page_options', [10, 25, 50, 100]) as $option): ?>
                                    <?php $option = (int) $option; ?>
                                    <option value="<?php echo esc_attr($option); ?>" <?php selected($results_per_page, $option); ?>>
                                        <?php echo esc_html(number_format_i18n($option)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="amendor-accordion">
                    <?php foreach ($items_to_display as $item): ?>
                        <?php amendor_render_results_item($item, $is_preview, $selected_ids); ?>
                    <?php endforeach; ?>
                </div>

                <?php if (!$is_preview && $total_pages > 1): ?>
                    <div class="tablenav bottom amendor-results-pagination">
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php
                                /* translators: %s: Number of matched items. */
                                printf(esc_html(_n('%s matched item', '%s matched items', $matched_posts, 'amendor')), esc_html(number_format_i18n($matched_posts)));
                                ?>
                            </span>
                            <?php
                            $pagination_base_url = add_query_arg('paged', '%#%', admin_url('admin.php?page=' . amendor_get_admin_parent_slug()));
                            echo wp_kses_post(paginate_links([
                                'base' => $pagination_base_url,
                                'format' => '',
                                'prev_text' => __('&laquo; Prev', 'amendor'),
                                'next_text' => __('Next &raquo;', 'amendor'),
                                'total' => $total_pages,
                                'current' => $paged,
                                'add_args' => ['results_per_page' => $results_per_page],
                            ]));
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
<?php
}

/**
 * Get the results section HTML.
 *
 * @param array $args Results section state.
 * @return string
 */
function amendor_get_results_section_html(array $args)
{
    ob_start();
    amendor_render_results_section($args);
    return ob_get_clean();
}
